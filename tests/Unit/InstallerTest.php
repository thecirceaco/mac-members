<?php
/**
 * Tests for the install and uninstall steps.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Installer;
use MacMembers\Security\Capabilities;
use MacMembers\Security\Roles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function get_role;
use function mac_members_tests_reset_wp_state;

#[CoversClass( Installer::class )]
#[CoversClass( Roles::class )]
final class InstallerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_hooks_install_on_activation_and_init(): void {
		$installer = new Installer();

		$installer->register();

		self::assertContains( array( $installer, 'install' ), $GLOBALS['mac_members_test_activation_hooks'][ MAC_MEMBERS_PLUGIN_FILE ] );
		self::assertSame( array( $installer, 'maybe_install' ), $GLOBALS['mac_members_test_actions']['init'][0]['callback'] );
	}

	public function test_install_grants_the_review_capability_to_administrators_and_member_reviewers_only(): void {
		( new Installer() )->install();

		self::assertTrue( $GLOBALS['mac_members_test_roles']['administrator']['capabilities'][ Capabilities::REVIEW ] );
		self::assertTrue( $GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ]['capabilities'][ Capabilities::REVIEW ] );

		foreach ( array( 'mac_members_pending', 'mac_members_approved', 'mac_members_inactive', 'mac_members_denied', 'subscriber' ) as $role ) {
			self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles'][ $role ]['capabilities'], $role );
		}

		self::assertSame( 3, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );
	}

	public function test_install_creates_the_member_reviewer_role_with_only_the_review_capability(): void {
		( new Installer() )->install();

		self::assertSame(
			array( 'name' => 'Member Reviewer', 'capabilities' => array( Capabilities::REVIEW => true ) ),
			$GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ]
		);
	}

	public function test_install_gives_an_existing_member_reviewer_role_the_capability_back_and_keeps_its_name(): void {
		$GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ] = array( 'name' => 'Union Reviewer', 'capabilities' => array() );

		( new Installer() )->install();

		self::assertSame(
			array( 'name' => 'Union Reviewer', 'capabilities' => array( Capabilities::REVIEW => true ) ),
			$GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ]
		);
	}

	public function test_maybe_install_on_a_version_two_site_creates_the_member_reviewer_role_once(): void {
		$GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] = 2;
		$installer = new Installer();

		$installer->maybe_install();

		self::assertArrayHasKey( Roles::REVIEWER, $GLOBALS['mac_members_test_roles'] );
		self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles']['administrator']['capabilities'] );
		self::assertSame( 3, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );

		// A site owner deletes the role on purpose; it must not come back until the plugin is activated again.
		unset( $GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ] );
		$installer->maybe_install();

		self::assertArrayNotHasKey( Roles::REVIEWER, $GLOBALS['mac_members_test_roles'] );
	}

	public function test_install_registers_a_static_uninstall_callback(): void {
		( new Installer() )->install();

		self::assertSame(
			array( Installer::class, 'uninstall' ),
			$GLOBALS['mac_members_test_uninstall_hooks'][ MAC_MEMBERS_PLUGIN_FILE ]
		);
		self::assertSame( array(), $GLOBALS['mac_members_test_doing_it_wrong'] );
	}

	public function test_install_without_an_administrator_role_still_records_the_version(): void {
		unset( $GLOBALS['mac_members_test_roles']['administrator'] );

		( new Installer() )->install();

		self::assertSame( 3, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );
	}

	public function test_maybe_install_runs_once_on_a_site_that_updated_without_reactivating(): void {
		$installer = new Installer();

		$installer->maybe_install();

		self::assertTrue( $GLOBALS['mac_members_test_roles']['administrator']['capabilities'][ Capabilities::REVIEW ] );

		// A site owner removes the capability from administrators on purpose; it must not come back.
		get_role( 'administrator' )->remove_cap( Capabilities::REVIEW );
		$installer->maybe_install();

		self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles']['administrator']['capabilities'] );
	}

	public function test_uninstall_keeps_the_data_unless_that_setting_is_on(): void {
		( new Installer() )->install();
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'delete_data_on_uninstall' => false );
		$GLOBALS['wpdb']->rows['mac_members_lock_12']                      = '1:dead-request';

		Installer::uninstall();

		self::assertTrue( $GLOBALS['mac_members_test_roles']['administrator']['capabilities'][ Capabilities::REVIEW ] );
		self::assertArrayHasKey( 'mac_members_pending', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayHasKey( Installer::VERSION_OPTION, $GLOBALS['mac_members_test_options'] );
		self::assertArrayHasKey( MAC_MEMBERS_SETTINGS_OPTION, $GLOBALS['mac_members_test_options'] );
		// Lock rows only exist while a status change runs, so they always go.
		self::assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_uninstall_with_that_setting_removes_the_settings_and_the_review_capability(): void {
		( new Installer() )->install();
		get_role( 'mac_members_approved' )->add_cap( Capabilities::REVIEW );
		$this->turn_on_data_deletion();

		Installer::uninstall();

		foreach ( $GLOBALS['mac_members_test_roles'] as $slug => $role ) {
			self::assertArrayNotHasKey( Capabilities::REVIEW, $role['capabilities'], $slug );
		}

		self::assertArrayNotHasKey( Installer::VERSION_OPTION, $GLOBALS['mac_members_test_options'] );
		self::assertArrayNotHasKey( MAC_MEMBERS_SETTINGS_OPTION, $GLOBALS['mac_members_test_options'] );
	}

	public function test_uninstall_removes_the_surecart_license_data_only_with_that_setting(): void {
		$version_info = 'surecart_' . md5( 'mac-members' ) . '_version_info';

		foreach ( array( false, true ) as $delete_data ) {
			$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'delete_data_on_uninstall' => $delete_data );
			$GLOBALS['mac_members_test_options']['macmembers_license_options'] = array( 'sc_license_key' => 'key_mac_members' );
			$GLOBALS['mac_members_test_transients'][ $version_info ]           = array( 'version' => '0.4.0' );

			Installer::uninstall();

			self::assertSame( ! $delete_data, array_key_exists( 'macmembers_license_options', $GLOBALS['mac_members_test_options'] ) );
			self::assertSame( ! $delete_data, array_key_exists( $version_info, $GLOBALS['mac_members_test_transients'] ) );
		}
	}

	public function test_install_creates_the_missing_member_roles_with_read_only(): void {
		$this->remove_member_roles();

		( new Installer() )->install();

		self::assertSame(
			array(
				'mac_members_pending'  => array( 'name' => 'Member (Pending)', 'capabilities' => array( 'read' => true ) ),
				'mac_members_approved' => array( 'name' => 'Member', 'capabilities' => array( 'read' => true ) ),
				'mac_members_inactive' => array( 'name' => 'Member (Inactive)', 'capabilities' => array( 'read' => true ) ),
				'mac_members_denied'   => array( 'name' => 'Member (Denied)', 'capabilities' => array( 'read' => true ) ),
			),
			array_intersect_key( $GLOBALS['mac_members_test_roles'], array_flip( array_keys( Roles::defaults() ) ) )
		);
	}

	public function test_install_keeps_an_existing_member_role_as_it_is(): void {
		$GLOBALS['mac_members_test_roles']['mac_members_approved'] = array(
			'name'         => 'Union Member',
			'capabilities' => array( 'read' => true, 'upload_files' => true ),
		);

		( new Installer() )->install();

		self::assertSame( 'Union Member', $GLOBALS['mac_members_test_roles']['mac_members_approved']['name'] );
		self::assertTrue( $GLOBALS['mac_members_test_roles']['mac_members_approved']['capabilities']['upload_files'] );
	}

	public function test_maybe_install_on_a_version_one_site_creates_the_roles_without_granting_the_capability_again(): void {
		$this->remove_member_roles();
		$GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] = 1;

		( new Installer() )->maybe_install();

		self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles']['administrator']['capabilities'] );

		foreach ( array( ...array_keys( Roles::defaults() ), Roles::REVIEWER ) as $slug ) {
			self::assertArrayHasKey( $slug, $GLOBALS['mac_members_test_roles'], $slug );
		}

		self::assertSame( 3, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );
	}

	public function test_maybe_install_on_a_current_site_does_not_bring_back_a_deleted_role(): void {
		$GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] = 3;
		unset( $GLOBALS['mac_members_test_roles']['mac_members_inactive'] );

		( new Installer() )->maybe_install();

		self::assertArrayNotHasKey( 'mac_members_inactive', $GLOBALS['mac_members_test_roles'] );
	}

	public function test_uninstall_with_that_setting_removes_only_the_member_roles_that_no_user_holds(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 12, 'roles' => array( 'mac_members_approved', 'subscriber' ) ) ),
		);
		$this->turn_on_data_deletion();

		Installer::uninstall();

		self::assertArrayHasKey( 'mac_members_approved', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayNotHasKey( 'mac_members_pending', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayNotHasKey( 'mac_members_inactive', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayNotHasKey( 'mac_members_denied', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayHasKey( 'subscriber', $GLOBALS['mac_members_test_roles'] );
		self::assertArrayHasKey( 'administrator', $GLOBALS['mac_members_test_roles'] );
	}

	public function test_uninstall_with_that_setting_removes_the_member_reviewer_role_unless_a_user_holds_it(): void {
		foreach ( array( false, true ) as $held ) {
			mac_members_tests_reset_wp_state();
			( new Installer() )->install();
			$GLOBALS['mac_members_test_users'] = $held
				? array( new \WP_User( array( 'ID' => 12, 'roles' => array( 'editor', Roles::REVIEWER ) ) ) )
				: array();
			$this->turn_on_data_deletion();

			Installer::uninstall();

			self::assertSame( $held, array_key_exists( Roles::REVIEWER, $GLOBALS['mac_members_test_roles'] ) );

			if ( $held ) {
				// The role stays for its user, without the capability; activating the plugin again gives it back.
				self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ]['capabilities'] );
				( new Installer() )->install();
				self::assertTrue( $GLOBALS['mac_members_test_roles'][ Roles::REVIEWER ]['capabilities'][ Capabilities::REVIEW ] );
			}
		}
	}

	public function test_uninstall_removes_lock_rows_left_by_requests_that_died(): void {
		$GLOBALS['wpdb']->rows['mac_members_lock_12'] = '1:dead-request';

		Installer::uninstall();

		self::assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	private function turn_on_data_deletion(): void {
		$GLOBALS['mac_members_test_options'][ MAC_MEMBERS_SETTINGS_OPTION ] = array( 'delete_data_on_uninstall' => true );
	}

	private function remove_member_roles(): void {
		foreach ( array_keys( Roles::defaults() ) as $slug ) {
			unset( $GLOBALS['mac_members_test_roles'][ $slug ] );
		}
	}
}
