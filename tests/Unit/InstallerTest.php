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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function get_role;
use function mac_members_tests_reset_wp_state;

#[CoversClass( Installer::class )]
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

	public function test_install_grants_the_review_capability_to_administrators_only(): void {
		( new Installer() )->install();

		self::assertTrue( $GLOBALS['mac_members_test_roles']['administrator']['capabilities'][ Capabilities::REVIEW ] );

		foreach ( array( 'member-pending', 'member', 'member-invalid', 'subscriber' ) as $role ) {
			self::assertArrayNotHasKey( Capabilities::REVIEW, $GLOBALS['mac_members_test_roles'][ $role ]['capabilities'], $role );
		}

		self::assertSame( 1, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );
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

		self::assertSame( 1, $GLOBALS['mac_members_test_options'][ Installer::VERSION_OPTION ] );
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

	public function test_uninstall_removes_the_review_capability_from_every_role(): void {
		( new Installer() )->install();
		get_role( 'member' )->add_cap( Capabilities::REVIEW );

		Installer::uninstall();

		foreach ( $GLOBALS['mac_members_test_roles'] as $slug => $role ) {
			self::assertArrayNotHasKey( Capabilities::REVIEW, $role['capabilities'], $slug );
		}

		self::assertArrayNotHasKey( Installer::VERSION_OPTION, $GLOBALS['mac_members_test_options'] );
	}
}
