<?php
/**
 * Tests for MAC Members settings storage and cleaning.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function delete_option;
use function mac_members_tests_reset_wp_state;

#[CoversClass( SettingsSchema::class )]
#[CoversClass( WordPressSettingsRepository::class )]
final class SettingsRepositoryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_all_returns_default_settings(): void {
		delete_option( MAC_MEMBERS_SETTINGS_OPTION );

		$repository = $this->create_repository();
		$settings   = $repository->all();

		self::assertSame( 'admin@example.test', $settings['admin_notification_email'] );
		self::assertSame( 'admin@example.test', $settings['from_email'] );
		self::assertTrue( $settings['send_member_approval_email'] );
		self::assertTrue( $settings['send_member_denial_email'] );
		self::assertTrue( $settings['send_admin_approval_email'] );
		self::assertTrue( $settings['send_admin_denial_email'] );
		self::assertTrue( $settings['send_member_deactivation_email'] );
		self::assertTrue( $settings['send_admin_deactivation_email'] );
		self::assertFalse( $settings['delete_data_on_uninstall'] );
		self::assertSame( 'medium', $settings['table_size'] );
		self::assertSame( 'relative', $settings['date_display'] );
		self::assertSame( 'Example Site', $repository->get_from_name() );
	}

	public function test_table_size_accepts_only_its_choices(): void {
		$repository = $this->create_repository();

		self::assertSame( 'small', $repository->save( array( 'table_size' => 'Small' ) )['table_size'] );
		self::assertSame( 'medium', $repository->save( array( 'table_size' => 'mixed' ) )['table_size'] );
		self::assertSame( 'medium', $repository->save( array( 'table_size' => array( 'small' ) ) )['table_size'] );
	}

	public function test_date_display_accepts_only_its_choices(): void {
		$repository = $this->create_repository();

		self::assertSame( 'date', $repository->save( array( 'date_display' => 'Date' ) )['date_display'] );
		self::assertSame( 'relative', $repository->save( array( 'date_display' => 'ago' ) )['date_display'] );
	}

	public function test_detail_fields_are_saved_one_per_line_with_clean_keys_and_labels(): void {
		$repository = $this->create_repository();

		self::assertSame( '', $repository->all()['detail_fields'] );
		self::assertSame(
			"phone : Phone\nlocal_number\nshop-name : Shop name",
			$repository->save( array( 'detail_fields' => " phone: Phone ,local_number!\r\n\nshop-name : <b>Shop</b> name\nphone : Again" ) )['detail_fields']
		);
	}

	public function test_no_roles_are_chosen_as_hidden_by_default(): void {
		self::assertSame( array(), $this->create_repository()->all()['hidden_roles'] );
	}

	public function test_hidden_roles_carry_over_from_the_development_setting_name_and_text(): void {
		$GLOBALS['mac_members_test_roles']['officer']                      = array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) );
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'role_filter_exclusions' => 'Officer, administrator, manage_options' );

		// Slugs and names count; the capability is dropped.
		self::assertSame( array( 'administrator', 'officer' ), $this->create_repository()->all()['hidden_roles'] );
	}

	public function test_hidden_roles_are_saved_as_existing_role_slugs_once_each(): void {
		$GLOBALS['mac_members_test_roles']['officer'] = array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) );
		$repository                                   = $this->create_repository();

		self::assertSame(
			array( 'officer', 'subscriber' ),
			$repository->save( array( 'hidden_roles' => array( 'subscriber', 'not-a-role', '<b>officer</b>', 'officer', 'subscriber', array( 'officer' ) ) ) )['hidden_roles']
		);
		// The form leaves out unchecked checkboxes: no hidden roles were sent, so none are hidden.
		self::assertSame( array(), $repository->save( array() )['hidden_roles'] );
	}

	public function test_the_status_role_settings_of_earlier_versions_are_dropped(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'pending_role' => 'subscriber' );
		$repository = $this->create_repository();

		self::assertArrayNotHasKey( 'pending_role', $repository->all() );

		$settings = $repository->save( array( 'approved_role' => 'subscriber', 'from_email' => 'from@example.test' ) );

		self::assertArrayNotHasKey( 'pending_role', $settings );
		self::assertArrayNotHasKey( 'approved_role', $settings );
		self::assertSame( 'from@example.test', $settings['from_email'] );
		self::assertSame( $settings, $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] );
	}

	public function test_email_fields_fallback_to_site_admin_email_when_blank_or_invalid(): void {
		$repository = $this->create_repository();

		$settings = $repository->save(
			array(
				'admin_notification_email' => "valid@example.test\r\nBcc: bad@example.test",
				'from_email'               => 'not-an-email',
			)
		);

		self::assertSame( 'admin@example.test', $settings['admin_notification_email'] );
		self::assertSame( 'admin@example.test', $settings['from_email'] );
	}

	public function test_notification_toggles_are_normalized_to_explicit_booleans(): void {
		$repository = $this->create_repository();

		$settings = $repository->save(
			array(
				'send_member_approval_email' => '1',
				'send_member_denial_email'   => '0',
				'send_admin_approval_email'  => 'yes',
			)
		);

		self::assertTrue( $settings['send_member_approval_email'] );
		self::assertFalse( $settings['send_member_denial_email'] );
		self::assertTrue( $settings['send_admin_approval_email'] );
		self::assertFalse( $settings['send_admin_denial_email'] );
	}

	public function test_ensure_defaults_persists_settings_when_option_missing(): void {
		delete_option( MAC_MEMBERS_SETTINGS_OPTION );

		$repository = $this->create_repository();
		$settings   = $repository->ensure_defaults();

		self::assertSame( $settings, $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] );
		self::assertSame( 'medium', $settings['table_size'] );
	}

	private function create_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
