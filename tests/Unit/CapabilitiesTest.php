<?php
/**
 * Tests for the capabilities MAC Members grants and checks.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Security\Capabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( Capabilities::class )]
final class CapabilitiesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();
	}

	public function test_sensitive_capabilities_include_the_required_ones(): void {
		$required = array(
			'manage_options',
			'edit_users',
			'promote_users',
			'delete_users',
			'unfiltered_html',
			'edit_plugins',
			'edit_themes',
			'install_plugins',
			'activate_plugins',
		);

		foreach ( $required as $capability ) {
			self::assertContains( $capability, Capabilities::SENSITIVE );
		}
	}

	public function test_administrative_capabilities_are_the_sensitive_ones_without_unfiltered_html_and_upload(): void {
		self::assertSame( array( 'unfiltered_html', 'unfiltered_upload' ), array_values( array_diff( Capabilities::SENSITIVE, Capabilities::ADMINISTRATIVE ) ) );
		self::assertSame( array(), array_diff( Capabilities::ADMINISTRATIVE, Capabilities::SENSITIVE ) );
	}

	public function test_sensitive_capabilities_of_role_lists_only_granted_sensitive_capabilities(): void {
		$GLOBALS['mac_members_test_roles']['mac_members_approved']['capabilities']['delete_users'] = false;
		$GLOBALS['mac_members_test_roles']['mac_members_approved']['capabilities']['edit_plugins'] = true;

		self::assertSame(
			array( 'manage_options', 'edit_users', 'promote_users', 'unfiltered_html' ),
			Capabilities::sensitive_capabilities_of_role( 'administrator' )
		);
		self::assertSame( array( 'edit_plugins' ), Capabilities::sensitive_capabilities_of_role( 'mac_members_approved' ) );
		self::assertSame( array(), Capabilities::sensitive_capabilities_of_role( 'subscriber' ) );
		self::assertSame( array(), Capabilities::sensitive_capabilities_of_role( 'not-a-role' ) );
		self::assertSame( array( 'manage_options', 'edit_users', 'promote_users' ), Capabilities::administrative_capabilities_of_role( 'administrator' ) );
	}

	public function test_user_has_administrative_capability(): void {
		$member = new \WP_User(
			array(
				'ID'   => 12,
				'caps' => array( 'read' => true ),
			)
		);
		$editor = new \WP_User(
			array(
				'ID'   => 13,
				'caps' => array( 'unfiltered_html' => true ),
			)
		);
		$manager = new \WP_User(
			array(
				'ID'   => 14,
				'caps' => array( 'edit_users' => true ),
			)
		);

		self::assertFalse( Capabilities::user_has_administrative_capability( $member ) );
		self::assertFalse( Capabilities::user_has_administrative_capability( $editor ) );
		self::assertTrue( Capabilities::user_has_administrative_capability( $manager ) );
	}

	public function test_current_user_can_review_needs_login_and_the_review_capability_only(): void {
		self::assertTrue( Capabilities::current_user_can_review() );

		// The review capability is a narrow promote_users, so a reviewer does not need promote_users itself.
		$GLOBALS['mac_members_test_current_user_caps']['promote_users'] = false;
		self::assertTrue( Capabilities::current_user_can_review() );

		$GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = false;
		self::assertFalse( Capabilities::current_user_can_review() );

		$GLOBALS['mac_members_test_current_user_caps']['mac_members_review'] = true;
		$GLOBALS['mac_members_test_logged_in']                               = false;
		self::assertFalse( Capabilities::current_user_can_review() );
	}
}
