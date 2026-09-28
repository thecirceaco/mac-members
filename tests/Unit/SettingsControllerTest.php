<?php
/**
 * Tests for the MAC Members settings admin controller.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Settings\SettingsController;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;
use function wp_create_nonce;

#[CoversClass( SettingsController::class )]
final class SettingsControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_hooks_settings_page_and_activation_defaults(): void {
		$controller = $this->create_controller();

		$controller->register();

		self::assertArrayHasKey( 'admin_menu', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayHasKey( 'admin_init', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayHasKey( 'admin_notices', $GLOBALS['mac_members_test_actions'] );
		self::assertArrayHasKey( MAC_MEMBERS_PLUGIN_FILE, $GLOBALS['mac_members_test_activation_hooks'] );

		$controller->register_settings_page();

		self::assertSame(
			array(
				'page_title' => 'MAC Members',
				'menu_title' => 'MAC Members',
				'capability' => 'manage_options',
				'callback'   => array( $controller, 'render_settings_page' ),
				'parent'     => 'options-general.php',
			),
			$GLOBALS['mac_members_test_options_pages'][ MAC_MEMBERS_ADMIN_SLUG ]
		);

		$controller->activate();

		self::assertArrayHasKey( MAC_MEMBERS_SETTINGS_OPTION, $GLOBALS['mac_members_test_options'] );
	}

	public function test_handle_save_persists_valid_settings_submission(): void {
		$controller = $this->create_controller();
		$nonce      = wp_create_nonce( SettingsController::NONCE_ACTION );

		$_GET['page']              = MAC_MEMBERS_ADMIN_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'mac_members_action'         => 'save_settings',
			'mac_members_settings_nonce' => $nonce,
			'mac_members_settings'       => array(
				'pending_role'                => 'subscriber',
				'approved_role'               => 'mac_members_approved',
				'denied_role'                 => 'mac_members_denied',
				'admin_notification_email'    => 'notifications@example.test',
				'from_email'                  => 'from@example.test',
				'send_member_approval_email' => '1',
				'send_admin_denial_email'    => '1',
			),
		);

		$controller->handle_save();

		$settings = $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ];

		self::assertSame( 'subscriber', $settings['pending_role'] );
		self::assertSame( 'notifications@example.test', $settings['admin_notification_email'] );
		self::assertSame( 'from@example.test', $settings['from_email'] );
		self::assertTrue( $settings['send_member_approval_email'] );
		self::assertFalse( $settings['send_member_denial_email'] );
		self::assertFalse( $settings['send_admin_approval_email'] );
		self::assertTrue( $settings['send_admin_denial_email'] );
		self::assertSame( 'settings_saved', $GLOBALS['mac_members_test_settings_errors'][0]['code'] );
	}

	/**
	 * @param array<string,string> $roles Submitted roles.
	 */
	#[DataProvider( 'provide_role_submissions_that_are_not_allowed' )]
	public function test_handle_save_shows_a_settings_error_and_saves_nothing_for_roles_that_are_not_allowed( array $roles, string $code, string $message ): void {
		$_GET['page']              = MAC_MEMBERS_ADMIN_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'mac_members_action'         => 'save_settings',
			'mac_members_settings_nonce' => wp_create_nonce( SettingsController::NONCE_ACTION ),
			'mac_members_settings'       => array_merge(
				array(
					'pending_role'  => 'mac_members_pending',
					'approved_role' => 'mac_members_approved',
					'denied_role'   => 'mac_members_denied',
					'from_email'    => 'from@example.test',
				),
				$roles
			),
		);

		$this->create_controller()->handle_save();

		self::assertArrayNotHasKey( MAC_MEMBERS_SETTINGS_OPTION, $GLOBALS['mac_members_test_options'] );
		self::assertSame(
			array(
				array(
					'setting' => MAC_MEMBERS_SETTINGS_OPTION,
					'code'    => $code,
					'message' => $message,
					'type'    => 'error',
				),
			),
			$GLOBALS['mac_members_test_settings_errors']
		);
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string,2:string}>
	 */
	public static function provide_role_submissions_that_are_not_allowed(): array {
		return array(
			'same role twice'         => array(
				array( 'denied_role' => 'mac_members_approved' ),
				'roles_not_distinct',
				'The pending, approved, inactive and denied roles must be four different roles. The settings were not saved.',
			),
			'sensitive approved role' => array(
				array( 'approved_role' => 'administrator' ),
				'approved_role_sensitive',
				'The approved role "Administrator" grants administrative capabilities (manage_options, edit_users, promote_users, unfiltered_html). Choose a role without them. The settings were not saved.',
			),
			'sensitive denied role'   => array(
				array( 'denied_role' => 'administrator' ),
				'denied_role_sensitive',
				'The denied role "Administrator" grants administrative capabilities (manage_options, edit_users, promote_users, unfiltered_html). Choose a role without them. The settings were not saved.',
			),
		);
	}

	public function test_handle_save_rejects_invalid_nonce(): void {
		$controller = $this->create_controller();

		$_GET['page']              = MAC_MEMBERS_ADMIN_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'mac_members_action'         => 'save_settings',
			'mac_members_settings_nonce' => 'invalid',
			'mac_members_settings'       => array(
				'pending_role' => 'subscriber',
			),
		);

		$controller->handle_save();

		self::assertArrayNotHasKey( MAC_MEMBERS_SETTINGS_OPTION, $GLOBALS['mac_members_test_options'] );
		self::assertSame( 'settings_nonce_failed', $GLOBALS['mac_members_test_settings_errors'][0]['code'] );
	}

	public function test_render_missing_roles_warning_outputs_expected_notice_on_settings_page(): void {
		$GLOBALS['mac_members_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'mac_members_approved'        => array( 'name' => 'Member' ),
		);

		$_GET['page'] = MAC_MEMBERS_ADMIN_SLUG;

		$controller = $this->create_controller();

		ob_start();
		$controller->render_missing_roles_warning();
		$output = (string) ob_get_clean();

		self::assertStringContainsString(
			'MAC Members: One or more configured roles do not exist. Please review Settings &gt; MAC Members.',
			$output
		);
		self::assertStringContainsString( 'notice notice-warning', $output );
	}

	private function create_controller(): SettingsController {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new SettingsController( $repository, $schema );
	}
}
