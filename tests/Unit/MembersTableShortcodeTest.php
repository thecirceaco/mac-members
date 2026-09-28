<?php
/**
 * Tests for the members table shortcode.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Assets\FrontendAssets;
use MacMembers\Members\MembersQuery;
use MacMembers\Members\MembersTableRenderer;
use MacMembers\Members\MembersTableShortcode;
use MacMembers\Members\RenderToken;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;

#[CoversClass( MembersTableShortcode::class )]
#[CoversClass( MembersTableRenderer::class )]
final class MembersTableShortcodeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_the_table_shortcode_and_page_protection(): void {
		$shortcode = $this->create_shortcode();

		$shortcode->register();

		self::assertSame( 'mac_members_table', MembersTableShortcode::SHORTCODE );
		self::assertSame(
			array( $shortcode, 'render' ),
			$GLOBALS['mac_members_test_shortcodes'][ MembersTableShortcode::SHORTCODE ]
		);
		self::assertSame(
			array( $shortcode, 'protect_table_page' ),
			$GLOBALS['mac_members_test_actions']['template_redirect'][0]['callback']
		);
	}

	#[DataProvider( 'provide_users_who_cannot_review' )]
	public function test_render_returns_empty_for_users_who_cannot_review( string $case ): void {
		match ( $case ) {
			'logged out'       => $GLOBALS['mac_members_test_logged_in'] = false,
			'no review cap'    => $GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false,
			'no promote_users' => $GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false,
		};

		self::assertSame( '', $this->create_shortcode()->render() );
		self::assertSame( array(), $GLOBALS['mac_members_test_enqueued_scripts'] );
		self::assertSame( 0, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_users_who_cannot_review(): array {
		return array(
			'logged out'       => array( 'logged out' ),
			'no review cap'    => array( 'no review cap' ),
			'no promote_users' => array( 'no promote_users' ),
		);
	}

	public function test_render_adds_a_render_token_that_allows_only_the_rendered_users(): void {
		$this->store_members(
			array(
				123 => 'mac_members_pending',
				124 => 'mac_members_pending',
				125 => 'mac_members_approved',
			)
		);

		$token  = $this->get_render_token( $this->create_shortcode()->render() );
		$tokens = new RenderToken();

		self::assertTrue( $tokens->allows( $token, 123 ) );
		self::assertTrue( $tokens->allows( $token, 124 ) );
		// Approved members are not in the pending view, so this table cannot act on them.
		self::assertFalse( $tokens->allows( $token, 125 ) );
	}

	public function test_each_render_gets_its_own_token(): void {
		$shortcode = $this->create_shortcode();

		self::assertNotSame(
			$this->get_render_token( $shortcode->render() ),
			$this->get_render_token( $shortcode->render() )
		);
	}

	public function test_default_view_is_pending_with_status_filters_and_counts(): void {
		$_SERVER['REQUEST_URI'] = '/members/?tab=x&mac_members_page=3';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				2 => 'mac_members_pending',
				3 => 'mac_members_approved',
				4 => 'mac_members_denied',
			)
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-view="pending"', $output );
		self::assertStringContainsString( 'aria-label="Member status"', $output );
		self::assertStringContainsString( '<a class="mac-members-filter btn--primary is-current btn--s" href="/members/?tab=x&amp;mac_members_status=pending" aria-current="page">Pending <span class="mac-members-count" data-mac-members-count-for="pending">2</span></a>', $output );
		self::assertStringContainsString( 'href="/members/?tab=x&amp;mac_members_status=approved">Approved <span class="mac-members-count" data-mac-members-count-for="approved">1</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="inactive">0</span>', $output );
		self::assertStringContainsString( 'data-mac-members-count-for="denied">1</span>', $output );
		self::assertStringContainsString( 'href="/members/?tab=x&amp;mac_members_status=all">All <span class="mac-members-count" data-mac-members-count-for="all">4</span>', $output );
		self::assertSame( 2, substr_count( $output, 'data-mac-members-status="pending"' ) );
		self::assertStringNotContainsString( 'data-mac-members-user-id="3"', $output );
	}

	public function test_status_query_argument_selects_the_view_and_its_actions(): void {
		$_GET['mac_members_status'] = 'approved';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				3 => 'mac_members_approved',
			)
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'data-mac-members-view="approved"', $output );
		self::assertStringContainsString( 'aria-current="page">Approved', $output );
		self::assertStringContainsString( '<tr class="mac-members-list__row" data-mac-members-user-id="3" data-mac-members-status="approved">', $output );
		self::assertStringContainsString( 'mac-members-status__label--approved">Approved</span>', $output );
		self::assertStringContainsString( 'data-mac-members-action="deactivate" data-mac-members-user-id="3">Deactivate</button>', $output );
		self::assertStringNotContainsString( 'data-mac-members-action="approve"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="1"', $output );
	}

	public function test_all_view_shows_every_member_with_its_status_and_actions(): void {
		$_GET['mac_members_status'] = 'all';
		$this->store_members(
			array(
				1 => 'mac_members_pending',
				2 => 'mac_members_approved',
				3 => 'mac_members_inactive',
				4 => 'mac_members_denied',
				5 => 'subscriber',
			)
		);

		$output = $this->create_shortcode()->render();
		$token  = $this->get_render_token( $output );

		self::assertStringContainsString( 'data-mac-members-view="all"', $output );
		self::assertMatchesRegularExpression( '/data-mac-members-user-id="1" data-mac-members-status="pending">.*data-mac-members-action="approve".*data-mac-members-action="deny"/s', $output );
		self::assertStringContainsString( 'data-mac-members-action="deactivate" data-mac-members-user-id="2"', $output );
		self::assertStringContainsString( 'data-mac-members-action="reactivate" data-mac-members-user-id="3"', $output );
		self::assertStringContainsString( 'data-mac-members-action="approve" data-mac-members-user-id="4"', $output );
		self::assertStringNotContainsString( 'data-mac-members-action="deny" data-mac-members-user-id="4"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="5"', $output );

		foreach ( array( 1, 2, 3, 4 ) as $user_id ) {
			self::assertTrue( ( new RenderToken() )->allows( $token, $user_id ), (string) $user_id );
		}
	}

	public function test_columns_start_with_the_user_id_and_end_with_status_and_actions(): void {
		$this->store_members( array( 7 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertSame( 9, preg_match_all( '/<th scope="col">([^<]+)<\/th>/', $output, $headers ) );
		self::assertSame(
			array( 'User ID', 'Email', 'First Name', 'Last Name', 'Username', 'Registered', 'Profile', 'Status', 'Actions' ),
			$headers[1]
		);
		self::assertStringContainsString( '<tr class="mac-members-list__row" data-mac-members-user-id="7" data-mac-members-status="pending"><td>7</td><td>member7@example.test</td>', $output );
	}

	public function test_buttons_use_automatic_css_classes(): void {
		$this->store_members( array( 7 => 'mac_members_pending' ) );

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'class="mac-members-button mac-members-button--approve btn--primary btn--s"', $output );
		self::assertStringContainsString( 'class="mac-members-button mac-members-button--deny btn--primary btn--outline btn--s"', $output );
		self::assertStringContainsString( 'class="mac-members-filter btn--primary btn--outline btn--s" href="/?mac_members_status=approved"', $output );
	}

	public function test_unknown_status_query_argument_falls_back_to_pending(): void {
		$_GET['mac_members_status'] = 'administrator';

		self::assertStringContainsString( 'data-mac-members-view="pending"', $this->create_shortcode()->render() );
	}

	public function test_status_attribute_fixes_the_view_without_filters(): void {
		$_GET['mac_members_status'] = 'approved';
		$this->store_members(
			array(
				1 => 'mac_members_inactive',
				2 => 'mac_members_approved',
			)
		);

		$output = $this->create_shortcode()->render( array( 'status' => 'inactive' ) );

		self::assertStringContainsString( 'data-mac-members-view="inactive"', $output );
		self::assertStringNotContainsString( 'mac-members-filters', $output );
		self::assertStringContainsString( 'data-mac-members-action="reactivate" data-mac-members-user-id="1"', $output );
		self::assertStringNotContainsString( 'data-mac-members-user-id="2"', $output );
	}

	public function test_invalid_status_attribute_keeps_the_filters(): void {
		$output = $this->create_shortcode()->render( array( 'status' => 'nope' ) );

		self::assertStringContainsString( 'mac-members-filters', $output );
		self::assertStringContainsString( 'data-mac-members-view="pending"', $output );
	}

	public function test_pagination_links_keep_the_view(): void {
		$members = array();

		for ( $id = 1; $id <= 51; $id++ ) {
			$members[ $id ] = 'mac_members_approved';
		}

		$this->store_members( $members );
		$_SERVER['REQUEST_URI']     = '/members/?mac_members_status=approved';
		$_GET['mac_members_status'] = 'approved';

		$first = $this->create_shortcode()->render();

		self::assertStringContainsString( '<span class="mac-members-page-count">Page 1 of 2</span>', $first );
		self::assertStringContainsString( '<a class="mac-members-page-link btn--primary btn--outline btn--s" href="/members/?mac_members_status=approved&amp;mac_members_page=2">Next</a>', $first );
		self::assertStringNotContainsString( '>Previous</a>', $first );

		$_GET['mac_members_page'] = '2';
		$second                   = $this->create_shortcode()->render();

		self::assertStringContainsString( 'Page 2 of 2', $second );
		self::assertStringContainsString( 'href="/members/?mac_members_status=approved&amp;mac_members_page=1">Previous</a>', $second );
		self::assertStringNotContainsString( '>Next</a>', $second );
		self::assertSame( 1, substr_count( $second, '<tr class="mac-members-list__row"' ) );
	}

	public function test_single_page_has_no_pagination(): void {
		$this->store_members( array( 1 => 'mac_members_pending' ) );

		self::assertStringNotContainsString( 'mac-members-pagination', $this->create_shortcode()->render() );
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
			'mac_members_is_table_page',
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
			'page with the shortcode'        => array( true, "Intro\n[mac_members_table]\nOutro", true ),
			'shortcode with attributes'      => array( true, '[mac_members_table status="approved"]', true ),
			'page without the shortcode'     => array( true, 'Just text', false ),
			'other shortcode with that name' => array( true, '[mac_members_table_other]', false ),
			'not a singular request'         => array( false, '[mac_members_table]', false ),
			'no queried post'                => array( true, null, false ),
		);
	}

	public function test_filter_marks_layouts_that_place_the_shortcode_elsewhere(): void {
		add_filter( 'mac_members_is_table_page', static fn (): bool => true );

		$this->create_shortcode()->protect_table_page();

		self::assertSame( 1, $GLOBALS['mac_members_test_nocache_headers_calls'] );
	}

	public function test_render_protects_the_response_once_when_template_redirect_already_did(): void {
		$GLOBALS['mac_members_test_is_singular']    = true;
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_table]' ) );

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
		$GLOBALS['mac_members_test_queried_object'] = new \WP_Post( array( 'post_content' => '[mac_members_table]' ) );

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

	public function test_render_outputs_escaped_member_rows_and_enqueues_assets(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User(
				array(
					'ID'              => 123,
					'user_email'      => 'pending@example.test',
					'user_login'      => 'pending<script>',
					'user_registered' => '2026-05-01 12:00:00',
					'first_name'      => 'Mia <Admin>',
					'last_name'       => 'O\'Connor',
					'roles'           => array( 'mac_members_pending' ),
				)
			),
		);

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'class="mac-members-table"', $output );
		self::assertStringContainsString( 'class="mac-members-list"', $output );
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
		self::assertStringContainsString( '<p class="mac-members-empty" hidden>There are no pending members.</p>', $output );
		self::assertContains( FrontendAssets::SCRIPT_HANDLE, $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	/**
	 * @param array<string,string> $query Query arguments.
	 */
	#[DataProvider( 'provide_empty_views' )]
	public function test_empty_view_says_which_members_are_missing( array $query, string $text ): void {
		$_GET = $query;

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( '<p class="mac-members-empty">' . $text . '</p>', $output );
		self::assertStringNotContainsString( '<table', $output );
		self::assertStringNotContainsString( 'do not exist', $output );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function provide_empty_views(): array {
		return array(
			'pending'  => array( array(), 'There are no pending members.' ),
			'approved' => array( array( 'mac_members_status' => 'approved' ), 'There are no approved members.' ),
			'inactive' => array( array( 'mac_members_status' => 'inactive' ), 'There are no inactive members.' ),
			'denied'   => array( array( 'mac_members_status' => 'denied' ), 'There are no denied members.' ),
			'all'      => array( array( 'mac_members_status' => 'all' ), 'There are no members yet.' ),
		);
	}

	public function test_render_warns_about_missing_roles_in_the_table(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_pending'], $GLOBALS['mac_members_test_roles']['mac_members_denied'] );

		$output = $this->create_shortcode()->render();

		self::assertStringContainsString( 'mac-members-notice--warning', $output );
		self::assertStringContainsString(
			'MAC Members: One or more configured roles do not exist (mac_members_pending, mac_members_denied). Status changes that need a missing role are blocked. Please review the MAC Members settings.',
			$output
		);
	}

	public function test_renderer_escapes_missing_role_names(): void {
		$output = ( new MembersTableRenderer() )->render( array(), 'pending', array(), array(), array( '<b>role</b>' ) );

		self::assertStringContainsString( '&lt;b&gt;role&lt;/b&gt;', $output );
		self::assertStringNotContainsString( '<b>role</b>', $output );
	}

	/**
	 * @param array<int,string> $members Status role of each member, keyed by user ID.
	 */
	private function store_members( array $members ): void {
		$GLOBALS['mac_members_test_users'] = array();

		foreach ( $members as $user_id => $role ) {
			$GLOBALS['mac_members_test_users'][] = new \WP_User(
				array(
					'ID'         => $user_id,
					'user_email' => 'member' . $user_id . '@example.test',
					'user_login' => 'member' . $user_id,
					'roles'      => array( $role ),
				)
			);
		}
	}

	private function get_render_token( string $output ): string {
		self::assertSame( 1, preg_match( '/data-mac-members-render-token="([^"]+)"/', $output, $matches ) );

		return $matches[1];
	}

	private function create_shortcode(): MembersTableShortcode {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new MembersTableShortcode(
			new MembersQuery( $repository ),
			new MembersTableRenderer(),
			new FrontendAssets(),
			$repository
		);
	}
}
