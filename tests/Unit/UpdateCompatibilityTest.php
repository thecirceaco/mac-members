<?php
/**
 * Tests for the tested WordPress version of MAC Members updates.
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
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_register_runs_after_the_surecart_sdk(): void {
		$tested = new UpdateCompatibility();

		$tested->register();

		$update      = $GLOBALS['mac_members_test_filters']['site_transient_update_plugins'][0];
		$information = $GLOBALS['mac_members_test_filters']['plugins_api'][0];

		self::assertSame( array( $tested, 'filter_update_plugins' ), $update['callback'] );
		self::assertSame( 20, $update['priority'] );
		self::assertSame( array( $tested, 'filter_plugin_information' ), $information['callback'] );
		self::assertSame( 20, $information['priority'] );
		self::assertSame( 3, $information['accepted_args'] );
	}

	/**
	 * @return array<string,array{string,string,string}>
	 */
	public static function versions(): array {
		return array(
			'branch on a patch release'        => array( '7.1', '7.1.2', '7.1.2' ),
			'older patch on a newer one'       => array( '7.1.2', '7.1.3', '7.1.3' ),
			'branch on a release candidate'    => array( '7.1', '7.1.3-RC1', '7.1.3' ),
			'the same version'                 => array( '7.1.2', '7.1.2', '7.1.2' ),
			'a newer patch than WordPress'     => array( '7.1.3', '7.1.2', '7.1.3' ),
			'an older branch stays untested'   => array( '7.0', '7.1.2', '7.0' ),
			'an older branch with a patch'     => array( '7.0.4', '7.1.2', '7.0.4' ),
			'a newer branch stays as it is'    => array( '7.2', '7.1.2', '7.2' ),
			'the branch on its first release'  => array( '7.1', '7.1', '7.1' ),
		);
	}

	#[DataProvider( 'versions' )]
	public function test_an_update_covers_its_whole_branch( string $tested, string $running, string $expected ): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = $running;
		$transient = (object) array(
			'response'  => array( 'mac-members/mac-members.php' => (object) array( 'new_version' => '0.5.1', 'tested' => $tested ) ),
			'no_update' => array(),
		);

		$filtered = ( new UpdateCompatibility() )->filter_update_plugins( $transient );

		self::assertSame( $expected, $filtered->response['mac-members/mac-members.php']->tested );
	}

	public function test_the_no_update_entry_is_covered_too_and_other_plugins_are_left_alone(): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = '7.1.2';
		$transient = (object) array(
			'response'  => array( 'mac-core/mac-core.php' => (object) array( 'tested' => '7.1' ) ),
			'no_update' => array( 'mac-members/mac-members.php' => (object) array( 'tested' => '7.1' ) ),
		);

		$filtered = ( new UpdateCompatibility() )->filter_update_plugins( $transient );

		self::assertSame( '7.1.2', $filtered->no_update['mac-members/mac-members.php']->tested );
		self::assertSame( '7.1', $filtered->response['mac-core/mac-core.php']->tested );
	}

	public function test_a_transient_without_mac_members_or_tested_passes_through(): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = '7.1.2';
		$tested = new UpdateCompatibility();

		self::assertFalse( $tested->filter_update_plugins( false ) );

		$transient = (object) array( 'response' => array( 'mac-members/mac-members.php' => (object) array( 'new_version' => '0.5.1' ) ) );

		self::assertObjectNotHasProperty( 'tested', $tested->filter_update_plugins( $transient )->response['mac-members/mac-members.php'] );
	}

	public function test_view_details_covers_the_branch_for_mac_members_only(): void {
		$GLOBALS['mac_members_test_bloginfo']['version'] = '7.1.2';
		$tested = new UpdateCompatibility();

		$details = $tested->filter_plugin_information( (object) array( 'tested' => '7.1' ), 'plugin_information', (object) array( 'slug' => 'mac-members' ) );
		self::assertSame( '7.1.2', $details->tested );

		$other = $tested->filter_plugin_information( (object) array( 'tested' => '7.1' ), 'plugin_information', (object) array( 'slug' => 'mac-core' ) );
		self::assertSame( '7.1', $other->tested );

		$search = $tested->filter_plugin_information( (object) array( 'tested' => '7.1' ), 'query_plugins', (object) array( 'slug' => 'mac-members' ) );
		self::assertSame( '7.1', $search->tested );

		self::assertFalse( $tested->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'mac-members' ) ) );
	}
}
