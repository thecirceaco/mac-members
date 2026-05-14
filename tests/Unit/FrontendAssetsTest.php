<?php
/**
 * Tests for pending members frontend assets.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Assets\FrontendAssets;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( FrontendAssets::class )]
final class FrontendAssetsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_hooks_frontend_asset_registration(): void {
		$assets = new FrontendAssets();

		$assets->register();

		self::assertArrayHasKey( 'wp_enqueue_scripts', $GLOBALS['mac_members_test_actions'] );
	}

	public function test_enqueue_pending_members_assets_provides_window_config(): void {
		$assets = new FrontendAssets();

		$assets->enqueue_pending_members();

		self::assertSame(
			MAC_MEMBERS_ASSETS_URL . 'pending-members.css',
			$GLOBALS['mac_members_test_registered_styles'][ FrontendAssets::STYLE_HANDLE ]['src']
		);
		self::assertSame(
			MAC_MEMBERS_ASSETS_URL . 'pending-members.js',
			$GLOBALS['mac_members_test_registered_scripts'][ FrontendAssets::SCRIPT_HANDLE ]['src']
		);
		self::assertContains( FrontendAssets::STYLE_HANDLE, $GLOBALS['mac_members_test_enqueued_styles'] );
		self::assertContains( FrontendAssets::SCRIPT_HANDLE, $GLOBALS['mac_members_test_enqueued_scripts'] );

		$inline = $GLOBALS['mac_members_test_inline_scripts'][ FrontendAssets::SCRIPT_HANDLE ][0];

		self::assertSame( 'before', $inline['position'] );
		self::assertStringContainsString( 'window.macMembers', $inline['data'] );
		self::assertStringContainsString( 'https:\/\/example.test\/wp-admin\/admin-ajax.php', $inline['data'] );
		self::assertStringContainsString( 'mac_members_approve_user', $inline['data'] );
		self::assertStringContainsString( 'mac_members_deny_user', $inline['data'] );
		self::assertStringContainsString( 'Are you sure you want to approve this member?', $inline['data'] );
		self::assertStringContainsString( 'Are you sure you want to deny this member?', $inline['data'] );
	}
}
