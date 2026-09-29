<?php
/**
 * Tests for the WordPress compatibility shown for MAC Members updates.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Licensing\UpdateCompatibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( UpdateCompatibility::class )]
final class UpdateCompatibilityTest extends TestCase {
	private const BASENAME = 'mac-members/mac-members.php';

	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_adds_filters_after_the_sdk(): void {
		$service = new UpdateCompatibility();

		$service->register();

		$transient   = $GLOBALS['mac_members_test_filters']['site_transient_update_plugins'][0];
		$information = $GLOBALS['mac_members_test_filters']['plugins_api'][0];

		self::assertSame( array( $service, 'filter_update_transient' ), $transient['callback'] );
		self::assertSame( array( $service, 'filter_plugin_information' ), $information['callback'] );
		self::assertSame( 20, $information['priority'] );
		self::assertSame( 3, $information['accepted_args'] );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public static function version_pairs(): array {
		return array(
			'branch only, same branch' => array( '7.1', '7.1.2', '7.1.2' ),
			'older patch, same branch' => array( '7.1.2', '7.1.3', '7.1.3' ),
			'same version'             => array( '7.1.2', '7.1.2', '7.1.2' ),
			'newer than running'       => array( '7.2', '7.1.2', '7.2' ),
			'older branch'             => array( '7.0', '7.1.2', '7.0' ),
			'older major'              => array( '6.9', '7.1.2', '6.9' ),
			'patch release candidate'  => array( '7.1.2', '7.1.3-RC1', '7.1.3' ),
			'next branch candidate'    => array( '7.1.2', '7.2-RC1', '7.1.2' ),
		);
	}

	#[DataProvider( 'version_pairs' )]
	public function test_pending_update_is_tested_on_the_whole_running_branch( string $tested, string $running, string $expected ): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = $running;
		$update    = (object) array( 'new_version' => '0.5.1', 'tested' => $tested );
		$transient = (object) array( 'response' => array( self::BASENAME => $update ) );

		( new UpdateCompatibility() )->filter_update_transient( $transient );

		self::assertSame( $expected, $transient->response[ self::BASENAME ]->tested );
		// The SDK's own object keeps what SureCart sent.
		self::assertSame( $tested, $update->tested );
	}

	public function test_other_plugins_and_other_values_are_left_alone(): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = '7.1.2';
		$service   = new UpdateCompatibility();
		$transient = (object) array(
			'response'  => array( 'mac-core/mac-core.php' => (object) array( 'tested' => '7.1' ) ),
			'no_update' => array( self::BASENAME => (object) array( 'tested' => '7.1' ) ),
		);

		$service->filter_update_transient( $transient );

		self::assertSame( '7.1', $transient->response['mac-core/mac-core.php']->tested );
		self::assertSame( '7.1', $transient->no_update[ self::BASENAME ]->tested );
		self::assertFalse( $service->filter_update_transient( false ) );

		$without_tested = (object) array( 'response' => array( self::BASENAME => (object) array( 'new_version' => '0.5.1' ) ) );

		self::assertObjectNotHasProperty( 'tested', $service->filter_update_transient( $without_tested )->response[ self::BASENAME ] );
	}

	public function test_view_details_is_tested_on_the_whole_running_branch(): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = '7.1.2';
		$service = new UpdateCompatibility();
		$info    = (object) array( 'slug' => 'mac-members', 'tested' => '7.1' );

		self::assertSame( '7.1.2', $service->filter_plugin_information( $info, 'plugin_information', (object) array( 'slug' => 'mac-members' ) )->tested );
		self::assertSame( '7.1', $info->tested );
		self::assertSame( $info, $service->filter_plugin_information( $info, 'plugin_information', (object) array( 'slug' => 'mac-core' ) ) );
		self::assertSame( $info, $service->filter_plugin_information( $info, 'query_plugins', (object) array( 'slug' => 'mac-members' ) ) );
		self::assertFalse( $service->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'mac-members' ) ) );
	}
}
