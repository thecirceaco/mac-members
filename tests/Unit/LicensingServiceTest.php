<?php
/**
 * Tests for the SureCart licensing service.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Admin\MenuPlacement;
use MacMembers\Licensing\LicensingService;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use MacMembers\Vendor\SureCart\Licensing\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function add_filter;
use function mac_members_tests_reset_wp_state;

#[CoversClass( LicensingService::class )]
#[CoversClass( MenuPlacement::class )]
final class LicensingServiceTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
		require_once dirname( __DIR__ ) . '/stubs/FakeSureCartClient.php';

		Client::reset();
	}

	public function test_register_starts_the_sdk_on_init(): void {
		$service = $this->create_service();

		$service->register();

		$action = $GLOBALS['mac_members_test_actions']['init'][0];

		self::assertSame( array( $service, 'initialize' ), $action['callback'] );
		self::assertSame( 20, $action['priority'] );
	}

	public function test_without_a_public_token_administrators_see_a_notice_and_the_sdk_does_not_start(): void {
		add_filter( 'mac_members_surecart_public_token', static fn (): string => '' );

		$this->create_service()->initialize();

		self::assertSame( array(), Client::$instances );

		$GLOBALS['mac_members_test_current_user_caps']['manage_options'] = true;

		self::assertStringContainsString( 'MAC Members licensing is not configured.', $this->render_notices() );

		$GLOBALS['mac_members_test_current_user_caps']['manage_options'] = false;

		self::assertSame( '', $this->render_notices() );
	}

	public function test_a_public_token_starts_the_sdk_with_the_license_form_in_the_license_tab(): void {
		add_filter( 'mac_members_surecart_public_token', static fn (): string => ' pt_test_token ' );

		$this->create_service()->initialize();

		self::assertSame(
			array(
				array(
					'name'         => 'MAC Members',
					'public_token' => 'pt_test_token',
					'file'         => MAC_MEMBERS_PLUGIN_FILE,
				),
			),
			Client::$instances
		);
		self::assertSame( array( 'mac-members' ), Client::$textdomains );
		self::assertCount( 1, Client::$pages );

		$page = Client::$pages[0];

		self::assertSame( 'MAC Members License', $page['page_title'] );
		self::assertSame( 'manage_options', $page['capability'] );
		self::assertSame( 'mac-members', $page['menu_slug'] );
		// The form sits in the License tab, so the SDK must not add a menu page.
		self::assertFalse( $page['register_menu'] );
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=mac-members&tab=license', $page['activated_redirect'] );
		self::assertSame( 'https://example.test/wp-admin/options-general.php?page=mac-members&tab=license', $page['deactivated_redirect'] );
		self::assertArrayNotHasKey( 'admin_notices', $GLOBALS['mac_members_test_actions'] );
	}

	public function test_license_redirects_go_to_the_top_level_page_when_that_setting_is_on(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'top_level_menu' => true );
		add_filter( 'mac_members_surecart_public_token', static fn (): string => 'pt_test_token' );

		$this->create_service()->initialize();

		self::assertSame( 'https://example.test/wp-admin/admin.php?page=mac-members&tab=license', Client::$pages[0]['activated_redirect'] );
	}

	public function test_the_license_view_runs_the_sdk_form_for_administrators(): void {
		add_filter( 'mac_members_surecart_public_token', static fn (): string => 'pt_test_token' );
		$GLOBALS['mac_members_test_current_user_caps']['manage_options'] = true;

		$service = $this->create_service();
		$service->initialize();

		self::assertStringContainsString( 'SureCart License', $this->render_view( $service ) );
		self::assertSame( 1, Client::$settings_output_calls );
	}

	public function test_the_license_view_never_runs_the_sdk_without_manage_options(): void {
		add_filter( 'mac_members_surecart_public_token', static fn (): string => 'pt_test_token' );
		$GLOBALS['mac_members_test_current_user_caps']['manage_options'] = false;

		$service = $this->create_service();
		$service->initialize();

		self::assertStringContainsString( 'You do not have permission to manage the MAC Members license.', $this->render_view( $service ) );
		self::assertSame( 0, Client::$settings_output_calls );
	}

	public function test_the_license_view_says_when_licensing_is_not_available(): void {
		add_filter( 'mac_members_surecart_public_token', static fn (): string => '' );
		$GLOBALS['mac_members_test_current_user_caps']['manage_options'] = true;

		$service = $this->create_service();
		$service->initialize();

		self::assertStringContainsString( 'MAC Members licensing is not available.', $this->render_view( $service ) );
		self::assertSame( 0, Client::$settings_output_calls );
	}

	private function create_service(): LicensingService {
		return new LicensingService( new MenuPlacement( new WordPressSettingsRepository( new SettingsSchema() ) ) );
	}

	private function render_view( LicensingService $service ): string {
		ob_start();
		$service->render_view();

		return (string) ob_get_clean();
	}

	private function render_notices(): string {
		ob_start();

		foreach ( $GLOBALS['mac_members_test_actions']['admin_notices'] ?? array() as $action ) {
			( $action['callback'] )();
		}

		return (string) ob_get_clean();
	}
}
