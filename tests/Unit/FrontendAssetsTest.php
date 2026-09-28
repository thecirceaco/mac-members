<?php
/**
 * Tests for the members table frontend assets.
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

	public function test_enqueue_members_table_registers_the_files_and_enqueues_them(): void {
		( new FrontendAssets() )->enqueue_members_table();

		self::assertSame(
			MAC_MEMBERS_ASSETS_URL . 'members-table.css',
			$GLOBALS['mac_members_test_registered_styles'][ FrontendAssets::STYLE_HANDLE ]['src']
		);
		self::assertSame(
			MAC_MEMBERS_ASSETS_URL . 'members-table.js',
			$GLOBALS['mac_members_test_registered_scripts'][ FrontendAssets::SCRIPT_HANDLE ]['src']
		);
		self::assertContains( FrontendAssets::STYLE_HANDLE, $GLOBALS['mac_members_test_enqueued_styles'] );
		self::assertContains( FrontendAssets::SCRIPT_HANDLE, $GLOBALS['mac_members_test_enqueued_scripts'] );
	}

	public function test_assets_are_versioned_with_the_plugin_version_and_the_file_time(): void {
		( new FrontendAssets() )->enqueue_members_table();

		self::assertSame(
			MAC_MEMBERS_VERSION . '.' . filemtime( MAC_MEMBERS_ASSETS_PATH . 'members-table.css' ),
			$GLOBALS['mac_members_test_registered_styles'][ FrontendAssets::STYLE_HANDLE ]['ver']
		);
		self::assertSame(
			MAC_MEMBERS_VERSION . '.' . filemtime( MAC_MEMBERS_ASSETS_PATH . 'members-table.js' ),
			$GLOBALS['mac_members_test_registered_scripts'][ FrontendAssets::SCRIPT_HANDLE ]['ver']
		);
	}

	public function test_asset_version_is_the_plugin_version_when_the_file_is_missing(): void {
		self::assertSame( MAC_MEMBERS_VERSION, ( new FrontendAssets() )->get_asset_version( 'missing.css' ) );
	}

	public function test_window_config_lists_every_status_change_and_what_each_status_allows(): void {
		( new FrontendAssets() )->enqueue_members_table();

		$inline = $GLOBALS['mac_members_test_inline_scripts'][ FrontendAssets::SCRIPT_HANDLE ][0];

		self::assertSame( 'before', $inline['position'] );
		self::assertStringStartsWith( 'window.macMembers = ', $inline['data'] );

		$config = json_decode( substr( $inline['data'], strlen( 'window.macMembers = ' ), -1 ), true );

		self::assertSame( 'https://example.test/wp-admin/admin-ajax.php', $config['ajaxUrl'] );
		self::assertSame( 'nonce-' . FrontendAssets::NONCE_ACTION, $config['nonce'] );
		self::assertSame( '%1$s-%2$s of %3$s', $config['rangeText'] );
		self::assertSame(
			array(
				'approve'    => array(
					'action'  => 'mac_members_approve_user',
					'label'   => 'Approve',
					'confirm' => 'Are you sure you want to approve this member?',
					'classes' => 'btn--success btn--s',
				),
				'deny'       => array(
					'action'  => 'mac_members_deny_user',
					'label'   => 'Deny',
					'confirm' => 'Are you sure you want to deny this member?',
					'classes' => 'btn--danger btn--s',
				),
				'deactivate' => array(
					'action'  => 'mac_members_deactivate_user',
					'label'   => 'Deactivate',
					'confirm' => 'Are you sure you want to deactivate this member?',
					'classes' => 'btn--warning btn--s',
				),
				'reactivate' => array(
					'action'  => 'mac_members_reactivate_user',
					'label'   => 'Reactivate',
					'confirm' => 'Are you sure you want to reactivate this member?',
					'classes' => 'btn--info btn--s',
				),
			),
			$config['transitions']
		);
		self::assertSame(
			array(
				'pending'  => array(
					'label'       => 'Pending',
					'transitions' => array( 'approve', 'deny' ),
				),
				'approved' => array(
					'label'       => 'Approved',
					'transitions' => array( 'deactivate' ),
				),
				'inactive' => array(
					'label'       => 'Inactive',
					'transitions' => array( 'reactivate' ),
				),
				'denied'   => array(
					'label'       => 'Denied',
					'transitions' => array( 'approve' ),
				),
			),
			$config['statuses']
		);
	}

	public function test_window_config_escapes_markup_for_the_inline_script(): void {
		( new FrontendAssets() )->enqueue_members_table();

		$inline = $GLOBALS['mac_members_test_inline_scripts'][ FrontendAssets::SCRIPT_HANDLE ][0]['data'];

		self::assertStringNotContainsString( '</', $inline );
		self::assertStringNotContainsString( "'", substr( $inline, strlen( 'window.macMembers = ' ) ) );
	}
}
