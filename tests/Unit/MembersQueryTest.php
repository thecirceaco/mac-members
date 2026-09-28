<?php
/**
 * Tests for the members table queries.
 *
 * @package MacMembers\Tests\Unit
 */

declare(strict_types=1);

namespace MacMembers\Tests\Unit;

use MacMembers\Members\MembersQuery;
use MacMembers\Members\MemberStatus;
use MacMembers\Settings\SettingsSchema;
use MacMembers\Settings\WordPressSettingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MembersQuery::class )]
final class MembersQueryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_pending_view_lists_the_configured_pending_role_oldest_first(): void {
		$repository = $this->create_settings_repository();
		$repository->save( array( 'pending_role' => 'subscriber' ) );

		self::assertSame(
			array(
				'role__in'    => array( 'subscriber' ),
				'number'      => 50,
				'paged'       => 1,
				'orderby'     => 'registered',
				'order'       => 'ASC',
				'fields'      => 'all',
				'count_total' => true,
			),
			( new MembersQuery( $repository ) )->get_query_args( MemberStatus::Pending )
		);
	}

	public function test_other_views_list_newest_first_and_the_all_view_covers_every_status_role(): void {
		$query = new MembersQuery( $this->create_settings_repository() );

		$approved = $query->get_query_args( MemberStatus::Approved, 3 );
		$all      = $query->get_query_args( null );

		self::assertSame( array( 'mac_members_approved' ), $approved['role__in'] );
		self::assertSame( 'DESC', $approved['order'] );
		self::assertSame( 3, $approved['paged'] );
		self::assertSame(
			array( 'mac_members_pending', 'mac_members_approved', 'mac_members_inactive', 'mac_members_denied' ),
			$all['role__in']
		);
		self::assertSame( 'DESC', $all['order'] );
		self::assertSame( 1, $query->get_query_args( MemberStatus::Denied, 0 )['paged'] );
	}

	public function test_get_members_returns_one_page_and_the_total_of_the_view(): void {
		$GLOBALS['mac_members_test_users'] = array();

		for ( $id = 1; $id <= 51; $id++ ) {
			$GLOBALS['mac_members_test_users'][] = new \WP_User( array( 'ID' => $id, 'roles' => array( 'mac_members_approved' ) ) );
		}

		$GLOBALS['mac_members_test_users'][] = new \WP_User( array( 'ID' => 99, 'roles' => array( 'mac_members_pending' ) ) );

		$query  = new MembersQuery( $this->create_settings_repository() );
		$first  = $query->get_members( MemberStatus::Approved );
		$second = $query->get_members( MemberStatus::Approved, 2 );

		self::assertCount( 50, $first['users'] );
		self::assertSame( 51, $first['total'] );
		self::assertSame( array( 51 ), array_map( static fn ( \WP_User $user ): int => $user->ID, $second['users'] ) );
		self::assertSame( 52, $query->get_members( null )['total'] );
	}

	public function test_count_by_status_counts_each_status_role(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'roles' => array( 'mac_members_pending' ) ) ),
			new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_pending', 'subscriber' ) ) ),
			new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_approved' ) ) ),
			new \WP_User( array( 'ID' => 4, 'roles' => array( 'mac_members_denied' ) ) ),
			new \WP_User( array( 'ID' => 5, 'roles' => array( 'subscriber' ) ) ),
		);

		self::assertSame(
			array(
				'pending'  => 2,
				'approved' => 1,
				'inactive' => 0,
				'denied'   => 1,
			),
			( new MembersQuery( $this->create_settings_repository() ) )->count_by_status()
		);
	}

	public function test_get_status_reads_the_status_from_the_configured_roles(): void {
		$query = new MembersQuery( $this->create_settings_repository() );

		self::assertSame( MemberStatus::Inactive, $query->get_status( new \WP_User( array( 'ID' => 1, 'roles' => array( 'subscriber', 'mac_members_inactive' ) ) ) ) );
		self::assertSame( MemberStatus::Denied, $query->get_status( new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_denied' ) ) ) ) );
		// A user who holds two status roles shows the first status, in the order pending, approved, inactive, denied.
		self::assertSame( MemberStatus::Pending, $query->get_status( new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_approved', 'mac_members_pending' ) ) ) ) );
		self::assertNull( $query->get_status( new \WP_User( array( 'ID' => 4, 'roles' => array( 'subscriber' ) ) ) ) );
	}

	private function create_settings_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
