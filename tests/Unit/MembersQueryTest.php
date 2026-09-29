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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function mac_members_tests_reset_wp_state;

#[CoversClass( MembersQuery::class )]
final class MembersQueryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		mac_members_tests_reset_wp_state();

		require_once dirname( __DIR__, 2 ) . '/inc/constants.php';
	}

	public function test_pending_view_lists_the_configured_pending_role_newest_first(): void {
		$repository = $this->create_settings_repository();
		$repository->save( array( 'pending_role' => 'subscriber' ) );

		self::assertSame(
			array(
				'role__in'     => array( 'subscriber' ),
				'number'       => 24,
				'paged'        => 1,
				'orderby'      => 'registered',
				'order'        => 'DESC',
				'fields'       => 'all',
				'count_total'  => true,
				'role__not_in' => array( 'administrator' ),
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
		$third  = $query->get_members( MemberStatus::Approved, 3 );
		$larger = $query->get_members( MemberStatus::Approved, 1, '', '', 48 );

		self::assertCount( 24, $first['users'] );
		self::assertSame( 51, $first['total'] );
		self::assertSame( array( 49, 50, 51 ), array_map( static fn ( \WP_User $user ): int => $user->ID, $third['users'] ) );
		self::assertCount( 48, $larger['users'] );
		self::assertSame( 52, $query->get_members( null )['total'] );
		self::assertSame( 96, $query->get_query_args( MemberStatus::Approved, 2, '', array(), 96 )['number'] );
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

	public function test_a_role_narrows_the_query_and_include_limits_it(): void {
		$args = ( new MembersQuery( $this->create_settings_repository() ) )->get_query_args( MemberStatus::Approved, 1, 'officer', array( 4, 9 ) );

		self::assertSame( array( 'mac_members_approved' ), $args['role__in'] );
		self::assertSame( array( 'officer' ), $args['role'] );
		self::assertSame( array( 4, 9 ), $args['include'] );
	}

	public function test_count_by_status_counts_only_the_members_with_the_role(): void {
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'roles' => array( 'mac_members_pending', 'officer' ) ) ),
			new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_approved', 'officer' ) ) ),
			new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_approved' ) ) ),
			new \WP_User( array( 'ID' => 4, 'roles' => array( 'subscriber', 'officer' ) ) ),
		);

		self::assertSame(
			array(
				'pending'  => 1,
				'approved' => 1,
				'inactive' => 0,
				'denied'   => 0,
			),
			( new MembersQuery( $this->create_settings_repository() ) )->count_by_status( 'officer' )
		);
	}

	public function test_hidden_roles_match_by_slug_name_or_capability_and_never_hide_a_status_role(): void {
		$this->add_test_roles();
		$repository = $this->create_settings_repository();
		$repository->save( array( 'hidden_roles' => 'administrator, EDITOR, manage_options, Member' ) );

		// Administrator by slug, Editor by name, Site Manager by capability. "Member" names the approved status
		// role, which cannot be hidden, and Author does not grant manage_options.
		self::assertSame(
			array( 'administrator', 'editor', 'site_manager' ),
			( new MembersQuery( $repository ) )->get_hidden_roles()
		);
	}

	public function test_users_with_a_hidden_role_never_show_in_the_list_the_counts_or_the_search(): void {
		$this->add_test_roles();
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'user_login' => 'ana-officer', 'roles' => array( 'mac_members_approved', 'officer' ) ) ),
			new \WP_User( array( 'ID' => 2, 'user_login' => 'ana-admin', 'roles' => array( 'mac_members_approved', 'administrator' ) ) ),
			new \WP_User( array( 'ID' => 3, 'user_login' => 'ana-manager', 'roles' => array( 'mac_members_pending', 'site_manager' ) ) ),
		);
		$repository = $this->create_settings_repository();
		$repository->save( array( 'hidden_roles' => 'administrator, manage_options' ) );
		$query = new MembersQuery( $repository );

		self::assertSame( array( 1 ), array_map( static fn ( \WP_User $user ): int => $user->ID, $query->get_members( null )['users'] ) );
		self::assertSame( array( 'pending' => 0, 'approved' => 1, 'inactive' => 0, 'denied' => 0 ), $query->count_by_status() );
		self::assertSame( array( 1 ), array_map( static fn ( \WP_User $user ): int => $user->ID, $query->get_members( null, 1, '', 'ana' )['users'] ) );
	}

	public function test_filter_roles_are_the_roles_shown_members_hold(): void {
		$this->add_test_roles();
		$GLOBALS['mac_members_test_users'] = array(
			new \WP_User( array( 'ID' => 1, 'roles' => array( 'mac_members_pending', 'trustee' ) ) ),
			new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_approved', 'shop_steward' ) ) ),
			new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_denied', 'officer', 'author' ) ) ),
			new \WP_User( array( 'ID' => 4, 'roles' => array( 'mac_members_approved', 'administrator', 'editor' ) ) ),
			new \WP_User( array( 'ID' => 5, 'roles' => array( 'mac_members_inactive', 'site_manager' ) ) ),
			new \WP_User( array( 'ID' => 6, 'roles' => array( 'subscriber' ) ) ),
		);
		$repository = $this->create_settings_repository();
		$repository->save( array( 'hidden_roles' => 'administrator, manage_options' ) );

		// Not offered: the hidden Administrator and Site Manager, Editor, which only a hidden member holds, and
		// Subscriber, which only a non-member holds.
		self::assertSame(
			array(
				'author'       => 'Author',
				'officer'      => 'Officer',
				'shop_steward' => 'Shop Steward',
				'trustee'      => 'Trustee',
			),
			( new MembersQuery( $repository ) )->get_filter_roles()
		);
	}

	public function test_administrators_are_hidden_by_default_and_role_names_are_translated(): void {
		$GLOBALS['mac_members_test_roles']['officer']  = array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) );
		$GLOBALS['mac_members_test_role_translations'] = array( 'Officer' => 'Responsabil' );
		$GLOBALS['mac_members_test_users']             = array(
			new \WP_User( array( 'ID' => 1, 'roles' => array( 'mac_members_approved', 'officer' ) ) ),
			new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_approved', 'administrator' ) ) ),
		);
		$query = new MembersQuery( $this->create_settings_repository() );

		self::assertSame( array( 'administrator' ), $query->get_hidden_roles() );
		self::assertSame( array( 'officer' => 'Responsabil' ), $query->get_filter_roles() );
		self::assertSame( 1, $query->get_members( null )['total'] );
	}

	/**
	 * @param array<int,int> $expected IDs of the members the search finds.
	 */
	#[DataProvider( 'provide_searches' )]
	public function test_search_matches_every_word_in_the_names_email_username_or_id( string $search, string $role, array $expected ): void {
		$this->store_named_members();

		$members = ( new MembersQuery( $this->create_settings_repository() ) )->get_members( null, 1, $role, $search );

		self::assertSame( $expected, array_map( static fn ( \WP_User $user ): int => $user->ID, $members['users'] ) );
		self::assertSame( count( $expected ), $members['total'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:array<int,int>}>
	 */
	public static function provide_searches(): array {
		return array(
			'first name, any case'     => array( 'TEST', '', array( 1, 2 ) ),
			'first and last name'      => array( 'test doi', '', array( 2 ) ),
			'last name'                => array( 'pop', '', array( 3 ) ),
			'email'                    => array( 'membru2@', '', array( 2 ) ),
			'username'                 => array( 'membru-test-1', '', array( 1 ) ),
			'user ID'                  => array( '3', '', array( 3 ) ),
			'wildcards are plain text' => array( '*ana*', '', array( 3 ) ),
			'with a role'              => array( 'test', 'officer', array( 1 ) ),
			'no match'                 => array( 'nobody', '', array() ),
			'not a member'             => array( 'outsider', '', array() ),
		);
	}

	public function test_each_search_runs_once_for_the_counts_and_the_page(): void {
		$this->store_named_members();
		$query = new MembersQuery( $this->create_settings_repository() );

		$query->count_by_status( '', 'test' );
		$after_counts = count( $GLOBALS['mac_members_test_user_queries'] );
		$query->get_members( null, 1, '', 'test' );

		// Only the page query is new: the page reuses the matches the counts found.
		self::assertSame( $after_counts + 1, count( $GLOBALS['mac_members_test_user_queries'] ) );
	}

	public function test_other_roles_are_the_role_names_besides_the_status_roles(): void {
		$GLOBALS['mac_members_test_roles']['officer']  = array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) );
		$GLOBALS['mac_members_test_role_translations'] = array( 'Subscriber' => 'Abonat' );
		$query                                         = new MembersQuery( $this->create_settings_repository() );

		self::assertSame(
			array(
				'officer'    => 'Officer',
				'subscriber' => 'Abonat',
				'gone_role'  => 'gone_role',
			),
			$query->get_other_roles( new \WP_User( array( 'ID' => 1, 'roles' => array( 'officer', 'mac_members_approved', 'subscriber', 'gone_role' ) ) ) )
		);
		self::assertSame( array(), $query->get_other_roles( new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_pending' ) ) ) ) );
	}

	public function test_get_status_reads_the_status_from_the_configured_roles(): void {
		$query = new MembersQuery( $this->create_settings_repository() );

		self::assertSame( MemberStatus::Inactive, $query->get_status( new \WP_User( array( 'ID' => 1, 'roles' => array( 'subscriber', 'mac_members_inactive' ) ) ) ) );
		self::assertSame( MemberStatus::Denied, $query->get_status( new \WP_User( array( 'ID' => 2, 'roles' => array( 'mac_members_denied' ) ) ) ) );
		// A user who holds two status roles shows the first status, in the order pending, approved, inactive, denied.
		self::assertSame( MemberStatus::Pending, $query->get_status( new \WP_User( array( 'ID' => 3, 'roles' => array( 'mac_members_approved', 'mac_members_pending' ) ) ) ) );
		self::assertNull( $query->get_status( new \WP_User( array( 'ID' => 4, 'roles' => array( 'subscriber' ) ) ) ) );
	}

	private function add_test_roles(): void {
		$GLOBALS['mac_members_test_roles'] += array(
			'officer'      => array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) ),
			'trustee'      => array( 'name' => 'Trustee', 'capabilities' => array( 'read' => true ) ),
			'shop_steward' => array( 'name' => 'Shop Steward', 'capabilities' => array( 'read' => true ) ),
			'editor'       => array( 'name' => 'Editor', 'capabilities' => array( 'read' => true ) ),
			'site_manager' => array( 'name' => 'Site Manager', 'capabilities' => array( 'read' => true, 'manage_options' => true ) ),
			'author'       => array( 'name' => 'Author', 'capabilities' => array( 'read' => true, 'manage_options' => false ) ),
		);
	}

	private function store_named_members(): void {
		$GLOBALS['mac_members_test_roles']['officer'] = array( 'name' => 'Officer', 'capabilities' => array( 'read' => true ) );
		$GLOBALS['mac_members_test_users']            = array(
			new \WP_User( array( 'ID' => 1, 'user_login' => 'membru-test-1', 'user_email' => 'mihai+membru1@example.org', 'first_name' => 'Test', 'last_name' => 'Unu', 'roles' => array( 'mac_members_pending', 'officer' ) ) ),
			new \WP_User( array( 'ID' => 2, 'user_login' => 'membru-test-2', 'user_email' => 'mihai+membru2@example.org', 'first_name' => 'Test', 'last_name' => 'Doi', 'roles' => array( 'mac_members_approved' ) ) ),
			new \WP_User( array( 'ID' => 3, 'user_login' => 'ana', 'user_email' => 'ana@example.org', 'first_name' => 'Ana', 'last_name' => 'Pop', 'roles' => array( 'mac_members_denied' ) ) ),
			new \WP_User( array( 'ID' => 4, 'user_login' => 'outsider', 'user_email' => 'outsider@example.org', 'first_name' => 'Outsider', 'roles' => array( 'subscriber' ) ) ),
		);
	}

	private function create_settings_repository(): WordPressSettingsRepository {
		return new WordPressSettingsRepository( new SettingsSchema() );
	}
}
