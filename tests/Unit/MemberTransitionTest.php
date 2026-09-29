<?php
/**
 * Tests for member statuses and the changes between them.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Members\MemberStatus;
use MacMembers\Members\MemberTransition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MemberStatus::class )]
#[CoversClass( MemberTransition::class )]
final class MemberTransitionTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_each_status_allows_only_its_changes(): void {
		$available = array();

		foreach ( MemberStatus::cases() as $status ) {
			$available[ $status->value ] = array_map(
				static fn ( MemberTransition $transition ): string => $transition->value,
				MemberTransition::available_for( $status )
			);
		}

		self::assertSame(
			array(
				'pending'  => array( 'approve', 'deny' ),
				'approved' => array( 'deactivate' ),
				'inactive' => array( 'reactivate' ),
				'denied'   => array( 'approve' ),
			),
			$available
		);
	}

	public function test_each_change_leads_to_its_status(): void {
		self::assertSame( MemberStatus::Approved, MemberTransition::Approve->to_status() );
		self::assertSame( MemberStatus::Denied, MemberTransition::Deny->to_status() );
		self::assertSame( MemberStatus::Inactive, MemberTransition::Deactivate->to_status() );
		self::assertSame( MemberStatus::Approved, MemberTransition::Reactivate->to_status() );
	}

	public function test_each_status_is_marked_by_its_member_role(): void {
		self::assertSame(
			array( 'mac_members_pending', 'mac_members_approved', 'mac_members_inactive', 'mac_members_denied' ),
			array_map( static fn ( MemberStatus $status ): string => $status->role(), MemberStatus::cases() )
		);
	}
}
