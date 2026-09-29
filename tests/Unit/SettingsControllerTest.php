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

		self::assertSame( 20, $GLOBALS['mac_members_test_actions']['admin_menu'][0]['priority'] );
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
				'admin_notification_email'    => 'notifications@example.test',
				'from_email'                  => 'from@example.test',
				'send_member_approval_email' => '1',
				'send_admin_denial_email'    => '1',
			),
		);

		$this->run_save( $controller );

		$settings = $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ];

		self::assertSame( 'notifications@example.test', $settings['admin_notification_email'] );
		self::assertSame( 'from@example.test', $settings['from_email'] );
		self::assertTrue( $settings['send_member_approval_email'] );
		self::assertFalse( $settings['send_member_denial_email'] );
		self::assertFalse( $settings['send_admin_approval_email'] );
		self::assertTrue( $settings['send_admin_denial_email'] );
		self::assertSame( 'settings_saved', $GLOBALS['mac_members_test_settings_errors'][0]['code'] );
	}

	public function test_handle_save_rejects_invalid_nonce(): void {
		$controller = $this->create_controller();

		$_GET['page']              = MAC_MEMBERS_ADMIN_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                    = array(
			'mac_members_action'         => 'save_settings',
			'mac_members_settings_nonce' => 'invalid',
			'mac_members_settings'       => array(
				'from_email' => 'from@example.test',
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
			'MAC Members: One or more member roles do not exist. Deactivate and activate MAC Members to create them again.',
			$output
		);
		self::assertStringContainsString( 'notice notice-warning', $output );
	}

	public function test_settings_page_is_under_settings_by_default(): void {
		$this->create_controller()->register_settings_page();

		self::assertArrayHasKey( MAC_MEMBERS_ADMIN_SLUG, $GLOBALS['mac_members_test_options_pages'] );
		self::assertSame( array(), $GLOBALS['mac_members_test_menu_pages'] );
	}

	public function test_settings_page_is_a_top_level_menu_item_when_that_setting_is_on(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'top_level_menu' => true );

		$controller = $this->create_controller();
		$controller->register_settings_page();

		$page = $GLOBALS['mac_members_test_menu_pages'][ MAC_MEMBERS_ADMIN_SLUG ];

		self::assertSame( array(), $GLOBALS['mac_members_test_options_pages'] );
		self::assertSame( 'MAC Members', $page['menu_title'] );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( array( $controller, 'render_settings_page' ), $page['callback'] );
		self::assertStringStartsWith( 'data:image/svg+xml;base64,', $page['icon_url'] );
		self::assertStringContainsString( 'viewBox="0 0 128 128"', (string) base64_decode( substr( $page['icon_url'], strlen( 'data:image/svg+xml;base64,' ) ) ) );
	}

	/**
	 * Under Settings, WordPress prints settings notices itself, so the page must not print them again.
	 */
	public function test_page_prints_settings_notices_only_as_a_top_level_menu_item(): void {
		\add_settings_error( MAC_MEMBERS_SETTINGS_OPTION, 'settings_saved', 'MAC Members settings saved.', 'success' );

		$under_settings = $this->render_page( false );
		$top_level      = $this->render_page( true );

		self::assertSame( 0, substr_count( $under_settings, 'MAC Members settings saved.' ) );
		self::assertSame( 1, substr_count( $top_level, 'MAC Members settings saved.' ) );
		self::assertStringContainsString( '>Save Settings</button>', $top_level );
	}

	public function test_settings_page_has_its_sections_and_settings_in_order(): void {
		$output    = $this->render_page( false );
		$positions = array_map(
			static fn ( string $needle ): int|false => strpos( $output, $needle ),
			array(
				'<h2 class="title">Interface</h2>',
				'id="mac-members-hidden_roles"',
				'id="mac-members-detail_fields"',
				'id="mac-members-date_display"',
				'id="mac-members-table_size"',
				'<h2 class="title">Emails</h2><table',
				'id="mac-members-from_email"',
				'id="mac-members-send_member_approval_email"',
				'id="mac-members-admin_notification_email"',
				'id="mac-members-send_admin_approval_email"',
				'<h2 class="title">Plugin</h2>',
				'id="mac-members-top_level_menu"',
				'id="mac-members-delete_data_on_uninstall"',
			)
		);

		self::assertNotContains( false, $positions );

		$sorted = $positions;
		sort( $sorted );

		self::assertSame( $sorted, $positions );
	}

	public function test_settings_page_has_settings_license_and_support_tabs(): void {
		$output = $this->render_page( false );

		self::assertStringContainsString(
			'<nav class="nav-tab-wrapper wp-clearfix" aria-label="MAC Members sections">'
			. '<a href="https://example.test/wp-admin/options-general.php?page=mac-members" class="nav-tab nav-tab-active" aria-current="page">Settings</a>'
			. '<a href="https://example.test/wp-admin/options-general.php?page=mac-members&amp;tab=license" class="nav-tab">License</a>'
			. '<a href="https://example.test/wp-admin/options-general.php?page=mac-members&amp;tab=support" class="nav-tab">Support</a></nav>',
			$output
		);
		self::assertStringContainsString( 'name="mac_members_action" value="save_settings"', $output );
	}

	public function test_support_tab_shows_the_support_email_and_the_documentation_instead_of_the_settings_form(): void {
		$_GET['tab'] = 'support';

		$output = $this->render_page( false );

		self::assertStringContainsString( '<a href="https://example.test/wp-admin/options-general.php?page=mac-members&amp;tab=support" class="nav-tab nav-tab-active" aria-current="page">Support</a>', $output );
		self::assertStringContainsString(
			'<p>You can get support by sending an email to <a href="mailto:mihai@circea.co">mihai@circea.co</a>. Before you do, make sure to check out our '
			. '<a href="https://docs.circea.co/" target="_blank" rel="noopener noreferrer">documentation</a>.</p>',
			$output
		);
		self::assertStringNotContainsString( 'save_settings', $output );
	}

	public function test_license_tab_shows_the_license_view_instead_of_the_settings_form(): void {
		$_GET['tab'] = 'license';

		$output = $this->render_page( true );

		self::assertStringContainsString( '<a href="https://example.test/wp-admin/admin.php?page=mac-members&amp;tab=license" class="nav-tab nav-tab-active" aria-current="page">License</a>', $output );
		// No public token in the tests, so the view says licensing isn't available.
		self::assertStringContainsString( 'MAC Members licensing is not available.', $output );
		self::assertStringNotContainsString( 'save_settings', $output );
	}

	public function test_email_toggles_share_one_row_for_the_member_and_one_for_the_admin(): void {
		$output = $this->render_page( false, array( 'send_member_denial_email' => false ) );

		self::assertStringContainsString(
			'<tr><th scope="row">Member emails</th><td><fieldset><legend class="screen-reader-text">Member emails</legend>'
			. '<label><input type="checkbox" id="mac-members-send_member_approval_email" name="mac_members_settings[send_member_approval_email]" value="1" checked="checked"> Approval</label><br>'
			. '<label><input type="checkbox" id="mac-members-send_member_denial_email" name="mac_members_settings[send_member_denial_email]" value="1"> Denial</label><br>'
			. '<label><input type="checkbox" id="mac-members-send_member_deactivation_email" name="mac_members_settings[send_member_deactivation_email]" value="1" checked="checked"> Deactivation</label><br>'
			. '<label><input type="checkbox" id="mac-members-send_member_reactivation_email" name="mac_members_settings[send_member_reactivation_email]" value="1" checked="checked"> Reactivation</label><br>'
			. '</fieldset></td></tr>',
			$output
		);
		self::assertSame( 1, substr_count( $output, '<th scope="row">Member emails</th>' ) );
		self::assertSame( 1, substr_count( $output, '<th scope="row">Admin emails</th>' ) );
	}

	public function test_settings_page_has_a_checkbox_for_each_role_that_can_be_hidden(): void {
		$output = $this->render_page( false, array( 'hidden_roles' => array( 'subscriber' ) ) );

		// No status roles, which are fixed; Administrator is always hidden, so its checkbox is checked and disabled.
		self::assertStringNotContainsString( '_role]', $output );
		self::assertStringContainsString(
			'<th scope="row">Hidden roles</th><td><fieldset id="mac-members-hidden_roles" aria-describedby="mac-members-hidden_roles-description"><legend class="screen-reader-text">Hidden roles</legend>'
			. '<label><input type="checkbox" name="mac_members_settings[hidden_roles][]" value="administrator" checked="checked" disabled> Administrator</label> <span class="description">(always hidden)</span><br>'
			. '<label><input type="checkbox" name="mac_members_settings[hidden_roles][]" value="subscriber" checked="checked"> Subscriber</label><br></fieldset>'
			. '<p class="description" id="mac-members-hidden_roles-description">Members with a checked role don&#039;t show in the members table',
			$output
		);
	}

	public function test_settings_page_has_the_uninstall_checkbox_off_with_its_description(): void {
		$output = $this->render_page( false );

		// A short row title, and the full sentence next to the checkbox.
		self::assertStringContainsString( '<th scope="row"><label for="mac-members-delete_data_on_uninstall">Delete plugin data</label></th>', $output );
		self::assertStringContainsString( '<input type="checkbox" id="mac-members-delete_data_on_uninstall" name="mac_members_settings[delete_data_on_uninstall]" value="1" aria-describedby="mac-members-delete_data_on_uninstall-description"> Delete plugin data on uninstall</label>', $output );
		self::assertStringContainsString( '<p class="description" id="mac-members-delete_data_on_uninstall-description">When the plugin is deleted, also remove its settings, the review capability and the member roles that no user holds.', $output );
		self::assertStringContainsString( '<th scope="row"><label for="mac-members-top_level_menu">Top-level admin menu</label></th>', $output );
		self::assertStringContainsString( 'name="mac_members_settings[top_level_menu]" value="1"> Show MAC Members as a top-level admin menu item</label>', $output );
	}

	public function test_settings_page_has_the_detail_fields_textarea(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'detail_fields' => "phone : Phone\nlocal_number" );

		$controller = $this->create_controller();
		$controller->register_settings_page();
		ob_start();
		$controller->render_settings_page();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<textarea class="large-text code" id="mac-members-detail_fields" name="mac_members_settings[detail_fields]" rows="12" aria-describedby="mac-members-detail_fields-description">phone : Phone' . "\n" . 'local_number</textarea>', $output );
		self::assertStringContainsString( '<th scope="row"><label for="mac-members-detail_fields">Extra modal fields</label></th>', $output );
		self::assertStringContainsString( '<p class="description" id="mac-members-detail_fields-description">Fields the member details modal shows after the table&#039;s fields, in this order; the table itself doesn&#039;t show them.', $output );
	}

	public function test_settings_page_has_the_date_display_choice(): void {
		$output = $this->render_page( false );

		self::assertStringContainsString( '<th scope="row"><label for="mac-members-date_display">Date format</label></th>', $output );
		self::assertStringContainsString( '<select id="mac-members-date_display" name="mac_members_settings[date_display]" aria-describedby="mac-members-date_display-description"><option value="date" selected="selected">Date</option><option value="datetime">Datetime</option><option value="relative">Relative</option></select>', $output );
		self::assertStringContainsString( '<p class="description" id="mac-members-date_display-description">For Registered and Last Login, in the table and the modal.', $output );
	}

	public function test_settings_page_has_the_table_size_choice(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'table_size' => 'small' );

		$controller = $this->create_controller();
		$controller->register_settings_page();
		ob_start();
		$controller->render_settings_page();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<select id="mac-members-table_size" name="mac_members_settings[table_size]" aria-describedby="mac-members-table_size-description"><option value="medium">Medium</option><option value="small" selected="selected">Small</option></select>', $output );
		self::assertStringContainsString( '<th scope="row"><label for="mac-members-table_size">Interface scale</label></th>', $output );
		self::assertStringContainsString( '<p class="description" id="mac-members-table_size-description">The text and button size of the members table, its controls and its modal.', $output );
	}

	public function test_uninstall_setting_shows_how_many_users_hold_each_member_role(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'roles' => array( 'mac_members_approved' ) ) ),
			new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_approved', 'subscriber' ) ) ),
			new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_pending' ) ) ),
		);

		self::assertStringContainsString(
			'<p class="description mac-members-role-usage">Users per member role: Member (Pending): 1, Member: 2, Member (Inactive): 0, Member (Denied): 0. The roles that users hold stay when the plugin is deleted.</p>',
			$this->render_page( false )
		);
	}

	public function test_uninstall_setting_says_when_no_user_holds_a_member_role(): void {
		unset( $GLOBALS['mac_members_test_roles']['mac_members_denied'] );

		self::assertStringContainsString(
			'Users per member role: Member (Pending): 0, Member: 0, Member (Inactive): 0. No user holds a member role, so deleting the plugin with this setting on removes them all.',
			$this->render_page( false )
		);
	}

	public function test_save_redirects_back_to_the_page_with_the_notices_in_a_transient(): void {
		$this->prepare_save( array( 'from_email' => 'from@example.test' ) );

		$this->run_save( $this->create_controller() );

		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=mac-members&settings-updated=true', $GLOBALS['mac_members_test_redirect']['location'] );
		self::assertSame( 'settings_saved', $GLOBALS['mac_members_test_transients']['settings_errors'][0]['code'] );
	}

	public function test_turning_on_the_top_level_menu_redirects_to_its_new_address(): void {
		$this->prepare_save( array( 'top_level_menu' => '1' ) );

		$this->run_save( $this->create_controller() );

		self::assertTrue( $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ]['top_level_menu'] );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=mac-members&settings-updated=true', $GLOBALS['mac_members_test_redirect']['location'] );
	}

	/**
	 * @param array<string,string> $settings Submitted settings.
	 */
	private function prepare_save( array $settings ): void {
		$_GET['page']              = MAC_MEMBERS_ADMIN_SLUG;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'mac_members_action'         => 'save_settings',
			'mac_members_settings_nonce' => wp_create_nonce( SettingsController::NONCE_ACTION ),
			'mac_members_settings'       => $settings,
		);
	}

	/**
	 * @param array<string,mixed> $settings Other saved settings.
	 */
	private function render_page( bool $top_level, array $settings = array() ): string {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'top_level_menu' => $top_level ) + $settings;

		$controller = $this->create_controller();
		$controller->register_settings_page();

		ob_start();
		$controller->render_settings_page();

		return (string) ob_get_clean();
	}

	/**
	 * Runs handle_save() and checks that it ended the request after its redirect.
	 */
	private function run_save( SettingsController $controller ): void {
		try {
			$controller->handle_save();
			self::fail( 'The save did not end the request after redirecting.' );
		} catch ( \MacMembers_Test_Request_Ended ) {
			// The controller redirected and ended the request.
		}
	}

	private function create_controller(): SettingsController {
		$schema     = new SettingsSchema();
		$repository = new WordPressSettingsRepository( $schema );

		return new SettingsController(
			$repository,
			$schema,
			static function (): never {
				throw new \MacMembers_Test_Request_Ended();
			}
		);
	}
}
