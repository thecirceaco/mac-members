<?php
/**
 * Tests for reading MAC Core's last login.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Integrations\MacCoreLastLogin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MacCoreLastLogin::class )]
final class MacCoreLastLoginTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_is_off_without_mac_core_even_with_its_setting_on(): void {
		$GLOBALS['mac_members_test_options']['mac_core_settings'] = array( 'core' => array( 'add_last_login_column' => true ) );

		self::assertFalse( ( new MacCoreLastLogin() )->is_enabled() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_is_on_only_while_mac_core_records_last_logins(): void {
		define( 'MAC_CORE_VERSION', '1.2.0' );

		$last_login = new MacCoreLastLogin();

		$GLOBALS['mac_members_test_options']['mac_core_settings'] = array( 'core' => array( 'add_last_login_column' => true ) );
		self::assertTrue( $last_login->is_enabled() );

		$GLOBALS['mac_members_test_options']['mac_core_settings'] = array( 'core' => array( 'add_last_login_column' => false ) );
		self::assertFalse( $last_login->is_enabled() );

		// Settings in another shape turn the column off rather than fail.
		$GLOBALS['mac_members_test_options']['mac_core_settings'] = 'unexpected';
		self::assertFalse( $last_login->is_enabled() );

		unset( $GLOBALS['mac_members_test_options']['mac_core_settings'] );
		self::assertFalse( $last_login->is_enabled() );
	}

	public function test_get_reads_the_login_time_or_null(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'mac_core_last_login' => '1790000000' ) ),
			new \WP_User( array( 'ID' => 2, 'mac_core_last_login' => 'yesterday' ) ),
			new \WP_User( array( 'ID' => 3 ) ),
		);

		$last_login = new MacCoreLastLogin();

		self::assertSame( 1790000000, $last_login->get( 1 ) );
		self::assertNull( $last_login->get( 2 ) );
		self::assertNull( $last_login->get( 3 ) );
	}
}
