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
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( PendingMembersShortcode::class )]
#[CoversClass( PendingMembersTableRenderer::class )]
final class PendingMembersShortcodeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_pending_table_shortcode(): void {
		$shortcode = $this->create_shortcode();

		$shortcode->register();

		self::assertSame(
			array( $shortcode, 'render' ),
			$GLOBALS['mac_members_test_shortcodes'][ PendingMembersShortcode::SHORTCODE ]
		);
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
	}

	private function create_shortcode(): PendingMembersShortcode {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new PendingMembersShortcode(
			new PendingMembersQuery( $repository ),
			new PendingMembersTableRenderer(),
			new FrontendAssets()
		);
	}
}
