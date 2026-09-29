<?php
/**
 * Tests for MAC Members settings storage and validation.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Security\Capabilities;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

		self::assertSame( 'mac_members_pending', $settings['pending_role'] );
		self::assertSame( 'mac_members_approved', $settings['approved_role'] );
		self::assertSame( 'mac_members_inactive', $settings['inactive_role'] );
		self::assertSame( 'mac_members_denied', $settings['denied_role'] );
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
		self::assertSame( 'date', $settings['date_display'] );
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

		self::assertSame( 'relative', $repository->save( array( 'date_display' => 'Relative' ) )['date_display'] );
		self::assertSame( 'date', $repository->save( array( 'date_display' => 'ago' ) )['date_display'] );
	}

	public function test_role_filter_exclusions_default_to_administrator(): void {
		self::assertSame( 'administrator', $this->create_repository()->all()['role_filter_exclusions'] );
	}

	public function test_role_filter_exclusions_are_saved_as_a_clean_comma_separated_list(): void {
		$repository = $this->create_repository();

		self::assertSame(
			'administrator, manage_options, Shop Steward',
			$repository->save( array( 'role_filter_exclusions' => ' administrator,, manage_options , <b>Shop Steward</b>, ADMINISTRATOR ' ) )['role_filter_exclusions']
		);
		self::assertSame( '', $repository->save( array( 'role_filter_exclusions' => '' ) )['role_filter_exclusions'] );
		self::assertSame( 'administrator', $repository->save( array( 'role_filter_exclusions' => array( 'editor' ) ) )['role_filter_exclusions'] );
	}

	public function test_save_accepts_existing_role_slugs_only(): void {
		$repository = $this->create_repository();

		$settings = $repository->save(
			array(
				'pending_role'  => 'subscriber',
				'approved_role' => 'not-a-role',
				'denied_role'   => 'administrator<script>',
			)
		);

		self::assertSame( 'subscriber', $settings['pending_role'] );
		self::assertSame( 'mac_members_approved', $settings['approved_role'] );
		self::assertSame( 'mac_members_denied', $settings['denied_role'] );
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

	public function test_missing_roles_detects_configured_role_slugs_not_registered(): void {
		$GLOBALS['mac_members_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'mac_members_approved'        => array( 'name' => 'Member' ),
		);

		$repository = $this->create_repository();

		self::assertSame(
			array( 'mac_members_pending', 'mac_members_inactive', 'mac_members_denied' ),
			$repository->get_missing_role_slugs()
		);
		self::assertTrue( $repository->has_missing_roles() );
	}

	public function test_validate_roles_accepts_four_distinct_roles_without_sensitive_capabilities(): void {
		self::assertSame( array(), $this->create_repository()->validate_roles( array() ) );
		self::assertSame(
			array(),
			$this->create_repository()->validate_roles(
				array(
					'pending_role'  => 'subscriber',
					'approved_role' => 'mac_members_approved',
					'denied_role'   => 'mac_members_denied',
				)
			)
		);
	}

	/**
	 * @param array<string,string> $roles Submitted roles.
	 */
	#[DataProvider( 'provide_roles_that_are_not_distinct' )]
	public function test_validate_roles_requires_four_distinct_roles( array $roles ): void {
		self::assertSame(
			array( 'roles_not_distinct' => 'The pending, approved, inactive and denied roles must be four different roles. The settings were not saved.' ),
			$this->create_repository()->validate_roles( $roles )
		);
	}

	/**
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function provide_roles_that_are_not_distinct(): array {
		return array(
			'pending equals approved'  => array( array( 'approved_role' => 'mac_members_pending' ) ),
			'pending equals denied'    => array( array( 'denied_role' => 'mac_members_pending' ) ),
			'approved equals denied'   => array( array( 'denied_role' => 'mac_members_approved' ) ),
			'inactive equals approved' => array( array( 'inactive_role' => 'mac_members_approved' ) ),
			'inactive equals denied'   => array( array( 'inactive_role' => 'mac_members_denied' ) ),
			'inactive equals pending'  => array( array( 'inactive_role' => 'mac_members_pending' ) ),
		);
	}

	public function test_validate_roles_rejects_an_inactive_role_with_sensitive_capabilities(): void {
		$errors = $this->create_repository()->validate_roles( array( 'inactive_role' => 'administrator' ) );

		self::assertSame(
			array( 'inactive_role_sensitive' => 'The inactive role "Administrator" grants administrative capabilities (manage_options, edit_users, promote_users, unfiltered_html). Choose a role without them. The settings were not saved.' ),
			$errors
		);
	}

	public function test_validate_roles_rejects_an_approved_role_with_sensitive_capabilities(): void {
		$errors = $this->create_repository()->validate_roles( array( 'approved_role' => 'administrator' ) );

		self::assertSame(
			array( 'approved_role_sensitive' => 'The approved role "Administrator" grants administrative capabilities (manage_options, edit_users, promote_users, unfiltered_html). Choose a role without them. The settings were not saved.' ),
			$errors
		);
	}

	public function test_validate_roles_rejects_a_denied_role_with_any_listed_sensitive_capability(): void {
		foreach ( Capabilities::SENSITIVE as $capability ) {
			mac_members_tests_reset_wp_state();
			$GLOBALS['mac_members_test_roles']['mac_members_denied']['capabilities'][ $capability ] = true;

			$errors = $this->create_repository()->validate_roles( array() );

			self::assertArrayHasKey( 'denied_role_sensitive', $errors, $capability );
			self::assertStringContainsString( '(' . $capability . ')', $errors['denied_role_sensitive'] );
		}
	}

	public function test_validate_roles_ignores_capabilities_a_role_does_not_grant(): void {
		$GLOBALS['mac_members_test_roles']['mac_members_approved']['capabilities']['manage_options'] = false;

		self::assertSame( array(), $this->create_repository()->validate_roles( array() ) );
	}

	public function test_save_keeps_the_saved_roles_when_the_submitted_roles_are_not_allowed(): void {
		$repository = $this->create_repository();

		$settings = $repository->save(
			array(
				'approved_role' => 'administrator',
				'denied_role'   => 'subscriber',
				'from_email'    => 'from@example.test',
			)
		);

		self::assertSame( 'mac_members_approved', $settings['approved_role'] );
		self::assertSame( 'mac_members_denied', $settings['denied_role'] );
		self::assertSame( 'from@example.test', $settings['from_email'] );
		self::assertSame( $settings, $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] );
	}

	public function test_role_exists_checks_registered_roles(): void {
		$repository = $this->create_repository();

		self::assertTrue( $repository->role_exists( 'mac_members_approved' ) );
		self::assertFalse( $repository->role_exists( 'not-a-role' ) );
		self::assertFalse( $repository->role_exists( '' ) );
	}

	public function test_ensure_defaults_persists_settings_when_option_missing(): void {
		delete_option( MAC_MEMBERS_SETTINGS_OPTION );

		$repository = $this->create_repository();
		$settings   = $repository->ensure_defaults();

		self::assertSame( $settings, $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] );
		self::assertSame( 'mac_members_pending', $settings['pending_role'] );
	}

	private function create_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
