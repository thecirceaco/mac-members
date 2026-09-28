<?php
/**
 * Tests for MAC Members settings storage and validation.
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

		self::assertSame( 'member-pending', $settings['pending_role'] );
		self::assertSame( 'member', $settings['approved_role'] );
		self::assertSame( 'member-invalid', $settings['denied_role'] );
		self::assertSame( 'admin@example.test', $settings['admin_notification_email'] );
		self::assertSame( 'admin@example.test', $settings['from_email'] );
		self::assertTrue( $settings['send_member_approval_email'] );
		self::assertTrue( $settings['send_member_denial_email'] );
		self::assertTrue( $settings['send_admin_approval_email'] );
		self::assertTrue( $settings['send_admin_denial_email'] );
		self::assertSame( 'Example Site', $repository->get_from_name() );
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
		self::assertSame( 'member', $settings['approved_role'] );
		self::assertSame( 'member-invalid', $settings['denied_role'] );
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
			'member'        => array( 'name' => 'Member' ),
		);

		$repository = $this->create_repository();

		self::assertSame(
			array( 'member-pending', 'member-invalid' ),
			$repository->get_missing_role_slugs()
		);
		self::assertTrue( $repository->has_missing_roles() );
	}

	public function test_role_exists_checks_registered_roles(): void {
		$repository = $this->create_repository();

		self::assertTrue( $repository->role_exists( 'member' ) );
		self::assertFalse( $repository->role_exists( 'not-a-role' ) );
		self::assertFalse( $repository->role_exists( '' ) );
	}

	public function test_ensure_defaults_persists_settings_when_option_missing(): void {
		delete_option( MAC_MEMBERS_SETTINGS_OPTION );

		$repository = $this->create_repository();
		$settings   = $repository->ensure_defaults();

		self::assertSame( $settings, $GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] );
		self::assertSame( 'member-pending', $settings['pending_role'] );
	}

	private function create_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
