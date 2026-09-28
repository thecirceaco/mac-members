<?php
/**
 * Tests for the pending members shortcode.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Assets\FrontendAssets;
use MacMembers\PendingMembers\PendingMembersQuery;
use MacMembers\PendingMembers\PendingMembersShortcode;
use MacMembers\PendingMembers\PendingMembersTableRenderer;
use MacMembers\PendingMembers\RenderToken;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;

#[CoversClass( PendingMembersShortcode::class )]
#[CoversClass( PendingMembersTableRenderer::class )]
final class PendingMembersShortcodeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_pending_table_shortcode_and_page_protection(): void {
		$shortcode = $this->create_shortcode();

		$shortcode->register();

		self::assertSame(
			array( $shortcode, 'render' ),
			$GLOBALS['mac_members_test_shortcodes'][ PendingMembersShortcode::SHORTCODE ]
		);
		self::assertSame(
			array( $shortcode, 'protect_table_page' ),
			$GLOBALS['mac_members_test_actions']['template_redirect'][0]['callback']
		);
	}

	public function test_render_returns_empty_for_users_without_the_review_capability(): void {
		$GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false;

		self::assertSame( '', $this->create_shortcode()->render() );
		self::assertSame( array(), $GLOBALS['mac_members_test_enqueued_scripts'] );
		self::assertSame( 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_adds_a_render_token_that_allows_only_the_rendered_users(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 123 ) ),
			new \WP_User( array( 'ID' => 124 ) ),
		);

		$token  = $this->get_render_token( $this->create_shortcode()->render() );
		$tokens = new RenderToken();

		self::assertTrue( $tokens->allows( $token, 123 ) );
		self::assertTrue( $tokens->allows( $token, 124 ) );
		self::assertFalse( $tokens->allows( $token, 125 ) );
	}

	public function test_each_render_gets_its_own_token(): void {
		$shortcode = $this->create_shortcode();

		self::assertNotSame(
			$this->get_render_token( $shortcode->render() ),
			$this->get_render_token( $shortcode->render() )
		);
	}

	#[DataProvider( 'provide_table_page_detection' )]
	public function test_protect_table_page_detects_pages_whose_content_has_the_shortcode( bool $is_singular, ?string $content, bool $expected ): void {
		$GLOBALS['mac_members_test_is_singular']    = $is_singular;
		$GLOBALS['mac_members_test_queried_object'] = null === $content ? null : new \WP_Post(
			array(
				'ID'           => 5,
				'post_content' => $content,
			)
		);

		$seen = null;
		add_filter(
			'mac_members_is_pending_table_page',
			static function ( bool $has_table ) use ( &$seen ): bool {
				$seen = $has_table;

				return $has_table;
			}
		);

		$this->create_shortcode()->protect_table_page();

		self::assertSame( $expected, $seen );
		self::assertSame( $expected ? 1 : 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	/**
	 * @return array<string,array{0:bool,1:?string,2:bool}>
	 */
	public static function provide_table_page_detection(): array {
		return array(
			'page with the shortcode'        => array( true, "Intro\n[mac_members_pending_table]\nOutro", true ),
			'shortcode with attributes'      => array( true, '[mac_members_pending_table foo="bar"]', true ),
			'page without the shortcode'     => array( true, 'Just text', false ),
			'other shortcode with that name' => array( true, '[mac_members_pending_table_other]', false ),
			'not a singular request'         => array( false, '[mac_members_pending_table]', false ),
			'no queried post'                => array( true, null, false ),
		);
	}

	public function test_filter_marks_layouts_that_place_the_shortcode_elsewhere(): void {
		add_filter( 'mac_members_is_pending_table_page', static fn (): bool => true );

		$this->create_shortcode()->protect_table_page();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_protects_the_response_once_when_template_redirect_already_did(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_pending_table]' ) );

		$shortcode = $this->create_shortcode();
		$shortcode->protect_table_page();
		$shortcode->render();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_protects_the_response_for_layouts_that_were_not_detected(): void {
		$this->create_shortcode()->render();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_table_page_defines_donotcachepage(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_pending_table]' ) );

		self::assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->create_shortcode()->protect_table_page();

		self::assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_other_pages_do_not_define_donotcachepage(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => 'No table here.' ) );

		$this->create_shortcode()->protect_table_page();

		self::assertFalse( defined( 'DONOTCACHEPAGE' ) );
		self::assertSame( 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_returns_empty_for_logged_out_visitors(): void {
		$GLOBALS['mac_members_test_logged_in'] = false;

		self::assertSame( '', $this->create_shortcode()->render() );
		self::assertSame( array(), $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	public function test_render_returns_empty_for_users_without_promote_users(): void {
		$GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false;

		self::assertSame( '', $this->create_shortcode()->render() );
		self::assertSame( array(), $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	public function test_render_outputs_escaped_pending_member_table_and_enqueues_assets(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User(
				array(
					'ID'              => 123,
					'user_email'      => 'pending@example.test',
					'user_login'      => 'pending<script>',
					'user_registered' => '2026-05-01 12:00:00',
					'first_name'      => 'Mia <Admin>',
					'last_name'       => 'O\'Connor',
				)
			),
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'class="mac-members-pending"', $output );
		self::assertStringContainsString( 'class="mac-members-pending-table"', $output );
		self::assertStringContainsString( 'data-mac-members-user-id="123"', $output );
		self::assertStringContainsString( 'pending@example.test', $output );
		self::assertStringContainsString( 'Mia &lt;Admin&gt;', $output );
		self::assertStringNotContainsString( 'Mia <Admin>', $output );
		self::assertStringContainsString( 'pending&lt;script&gt;', $output );
		self::assertStringContainsString( 'user-edit.php?user_id=123', $output );
		self::assertStringContainsString( 'target="_blank"', $output );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $output );
		self::assertStringContainsString( 'data-mac-members-action="approve"', $output );
		self::assertStringContainsString( 'data-mac-members-action="deny"', $output );
		self::assertContains( FrontendAssets::SCRIPT_HANDLE, $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	public function test_render_outputs_empty_state_when_no_pending_members_exist(): void {
		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'There are no pending members.', $output );
		self::assertStringNotContainsString( '<table', $output );
		self::assertStringNotContainsString( 'do not exist', $output );
	}

	public function test_render_warns_about_missing_roles_in_the_table(): void {
		unset( $GLOBALS['mac_members_test_roles']['member-pending'], $GLOBALS['mac_members_test_roles']['member-invalid'] );

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'mac-members-notice--warning', $output );
		self::assertStringContainsString(
			'MAC Members: One or more configured roles do not exist (member-pending, member-invalid). Approve and deny are blocked for any action that needs a missing role. Please review Settings &gt; MAC Members.',
			$output
		);
	}

	public function test_renderer_escapes_missing_role_names(): void {
		$output = ( new PendingMembersTableRenderer() )->render( array(), array( '<b>role</b>' ) );

		self::assertStringContainsString( '&lt;b&gt;role&lt;/b&gt;', $output );
		self::assertStringNotContainsString( '<b>role</b>', $output );
	}

	private function get_render_token( string $output ): string {
		self::assertSame( 1, preg_match( '/data-mac-members-render-token="([^"]+)"/', $output, $matches ) );

		return $matches[1];
	}

	private function create_shortcode(): PendingMembersShortcode {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new PendingMembersShortcode(
			new PendingMembersQuery( $repository ),
			new PendingMembersTableRenderer(),
			new FrontendAssets(),
			$repository
		);
	}
}
