<?php
/**
 * Member queries for the members table.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Settings\SettingsRepositoryInterface;
use MacMembers\Settings\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MembersQuery
{
	/**
	 * Members per page: the default, and the choices the table offers.
	 */
	public const PER_PAGE         = 24;
	public const PER_PAGE_OPTIONS = array( 24, 48, 96, 192 );

	/**
	 * Longest search the table accepts.
	 */
	public const SEARCH_MAX_LENGTH = 100;

	/**
	 * Most words of a search that are matched.
	 */
	private const SEARCH_MAX_WORDS = 5;

	/**
	 * User fields a search word is matched against, next to the first and last name. A word made of digits
	 * is also compared with the user ID.
	 */
	private const SEARCH_COLUMNS = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );

	/**
	 * IDs of the members that match a role and search, so each search runs once per request.
	 *
	 * @var array<string,array<int,int>>
	 */
	private array $search_matches = array();

	/**
	 * The site's roles, read once per request.
	 *
	 * @var array<string,array<string,mixed>>|null
	 */
	private ?array $site_roles = null;

	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	/**
	 * Every view lists the newest registrations first.
	 *
	 * @param MemberStatus|null $status   Status to list, or null for every member.
	 * @param string            $role     A role the members must also hold, or '' for any role.
	 * @param array<int,int>    $include  Only these users, or no limit when empty.
	 * @param int               $per_page Members per page.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_args( ?MemberStatus $status, int $page = 1, string $role = '', array $include = array(), int $per_page = self::PER_PAGE ): array
	{
		$args = array(
			'role__in'    => null === $status ? array_values( $this->get_status_roles() ) : array( $this->get_role( $status ) ),
			'number'      => max( 1, $per_page ),
			'paged'       => max( 1, $page ),
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'fields'      => 'all',
			'count_total' => true,
		);

		if ( '' !== $role ) {
			// WordPress lists only the users who hold every role in `role`, and one of the roles in `role__in`.
			$args['role'] = array( $role );
		}

		if ( array() !== $include ) {
			$args['include'] = $include;
		}

		return $args;
	}

	/**
	 * @param MemberStatus|null $status   Status to list, or null for every member.
	 * @param string            $role     A role the members must also hold, or '' for any role.
	 * @param string            $search   Words every listed member matches, or '' for no search.
	 * @param int               $per_page Members per page.
	 *
	 * @return array{users:array<int,object>,total:int} One page of members and the number of members in the view.
	 */
	public function get_members( ?MemberStatus $status, int $page = 1, string $role = '', string $search = '', int $per_page = self::PER_PAGE ): array
	{
		$include = $this->find_search_matches( $role, $search );

		if ( array() === $include ) {
			return array(
				'users' => array(),
				'total' => 0,
			);
		}

		$query = new \WP_User_Query( $this->get_query_args( $status, $page, $role, $include ?? array(), $per_page ) );
		$users = $query->get_results();

		return array(
			'users' => \is_array( $users ) ? $users : array(),
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * @param string $role   A role the counted members must also hold, or '' for any role.
	 * @param string $search Words every counted member matches, or '' for no search.
	 *
	 * @return array<string,int> Number of members with each status, keyed by status value.
	 */
	public function count_by_status( string $role = '', string $search = '' ): array
	{
		$include = $this->find_search_matches( $role, $search );
		$counts  = array();

		foreach ( MemberStatus::cases() as $status ) {
			if ( array() === $include ) {
				$counts[ $status->value ] = 0;
				continue;
			}

			$args = array(
				'role'        => '' === $role ? $this->get_role( $status ) : array( $this->get_role( $status ), $role ),
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			);

			if ( null !== $include ) {
				$args['include'] = $include;
			}

			$counts[ $status->value ] = (int) ( new \WP_User_Query( $args ) )->get_total();
		}

		return $counts;
	}

	/**
	 * Roles the role filter offers: every role at least one member holds, except the status roles and the
	 * roles that the role filter exclusions setting names by slug or name, or that have a capability it names.
	 *
	 * @return array<string,string> Role names keyed by slug, sorted by name.
	 */
	public function get_filter_roles(): array
	{
		$status_roles = array_values( array_filter( $this->get_status_roles() ) );
		$exclusions   = array_map( 'strtolower', SettingsSchema::parse_list( (string) $this->settings->get( 'role_filter_exclusions', '' ) ) );
		$roles        = array();

		if ( array() === $status_roles ) {
			return array();
		}

		foreach ( $this->get_site_roles() as $slug => $role ) {
			$name = isset( $role['name'] ) ? (string) $role['name'] : $slug;

			if (
				in_array( $slug, $status_roles, true )
				|| $this->is_excluded( $slug, $name, $role['capabilities'] ?? array(), $exclusions )
				|| ! $this->is_held_by_a_member( $slug, $status_roles )
			) {
				continue;
			}

			$roles[ $slug ] = \translate_user_role( $name );
		}

		asort( $roles, SORT_NATURAL | SORT_FLAG_CASE );

		return $roles;
	}

	/**
	 * The member's roles other than the status roles, for the Roles column.
	 *
	 * @return array<string,string> Role names keyed by slug, in the order the user holds them.
	 */
	public function get_other_roles( object $user ): array
	{
		$status_roles = array_values( $this->get_status_roles() );
		$site_roles   = $this->get_site_roles();
		$roles        = array();

		foreach ( isset( $user->roles ) ? array_map( 'strval', (array) $user->roles ) : array() as $slug ) {
			if ( in_array( $slug, $status_roles, true ) ) {
				continue;
			}

			$name           = isset( $site_roles[ $slug ]['name'] ) ? (string) $site_roles[ $slug ]['name'] : $slug;
			$roles[ $slug ] = \translate_user_role( $name );
		}

		return $roles;
	}

	/**
	 * The member's status: the first status, in the order pending, approved, inactive, denied, whose role the
	 * user holds. Null for a user without any status role.
	 */
	public function get_status( object $user ): ?MemberStatus
	{
		$roles = isset( $user->roles ) ? array_map( 'strval', (array) $user->roles ) : array();

		foreach ( $this->get_status_roles() as $value => $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return MemberStatus::from( $value );
			}
		}

		return null;
	}

	/**
	 * IDs of the members, with any status and with the role when one is set, that match every word of the
	 * search in their user ID, username, email, nicename, display name, first name or last name. WordPress
	 * searches user fields and user meta separately, so each word runs one query for each and joins them.
	 *
	 * @return array<int,int>|null The matching IDs, or null when there is no search.
	 */
	private function find_search_matches( string $role, string $search ): ?array
	{
		$words = $this->get_search_words( $search );

		if ( array() === $words ) {
			return null;
		}

		$key = $role . '|' . implode( ' ', $words );

		if ( isset( $this->search_matches[ $key ] ) ) {
			return $this->search_matches[ $key ];
		}

		$base = array(
			'role__in'    => array_values( $this->get_status_roles() ),
			'number'      => -1,
			'fields'      => 'ID',
			'count_total' => false,
		);

		if ( '' !== $role ) {
			$base['role'] = array( $role );
		}

		$matches = null;

		foreach ( $words as $word ) {
			$in_fields = ( new \WP_User_Query(
				$base + array(
					'search'         => '*' . $word . '*',
					'search_columns' => ctype_digit( $word ) ? array_merge( array( 'ID' ), self::SEARCH_COLUMNS ) : self::SEARCH_COLUMNS,
				)
			) )->get_results();

			$in_names = ( new \WP_User_Query(
				$base + array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Names are user meta; this runs only for reviewers who search.
					'meta_query' => array(
						'relation' => 'OR',
						array(
							'key'     => 'first_name',
							'value'   => $word,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'last_name',
							'value'   => $word,
							'compare' => 'LIKE',
						),
					),
				)
			) )->get_results();

			$word_matches = array_unique( array_map( 'intval', array_merge( (array) $in_fields, (array) $in_names ) ) );
			$matches      = null === $matches ? $word_matches : array_intersect( $matches, $word_matches );

			if ( array() === $matches ) {
				break;
			}
		}

		$this->search_matches[ $key ] = array_values( $matches ?? array() );

		return $this->search_matches[ $key ];
	}

	/**
	 * @return array<int,string> The search's distinct words, without the * that WordPress reads as a wildcard.
	 */
	private function get_search_words( string $search ): array
	{
		$words = preg_split( '/\s+/u', trim( $search ), -1, PREG_SPLIT_NO_EMPTY );
		$words = array_map( static fn ( string $word ): string => trim( $word, '*' ), \is_array( $words ) ? $words : array() );
		$words = array_values( array_unique( array_filter( $words, static fn ( string $word ): bool => '' !== $word ) ) );

		return array_slice( $words, 0, self::SEARCH_MAX_WORDS );
	}

	/**
	 * @param mixed             $capabilities The role's capabilities.
	 * @param array<int,string> $exclusions   Lowercase entries of the role filter exclusions setting.
	 */
	private function is_excluded( string $slug, string $name, mixed $capabilities, array $exclusions ): bool
	{
		$names = array( strtolower( $slug ), strtolower( $name ), strtolower( \translate_user_role( $name ) ) );

		if ( array() !== array_intersect( $names, $exclusions ) ) {
			return true;
		}

		foreach ( \is_array( $capabilities ) ? $capabilities : array() as $capability => $granted ) {
			if ( $granted && in_array( strtolower( (string) $capability ), $exclusions, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,string> $status_roles The configured status roles.
	 */
	private function is_held_by_a_member( string $role, array $status_roles ): bool
	{
		$query = new \WP_User_Query(
			array(
				'role'        => array( $role ),
				'role__in'    => $status_roles,
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		return array() !== (array) $query->get_results();
	}

	/**
	 * @return array<string,array<string,mixed>> The site's roles, keyed by slug.
	 */
	private function get_site_roles(): array
	{
		if ( null !== $this->site_roles ) {
			return $this->site_roles;
		}

		$wp_roles = \wp_roles();
		$roles    = \is_object( $wp_roles ) && isset( $wp_roles->roles ) && \is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
		$site     = array();

		foreach ( $roles as $slug => $role ) {
			if ( \is_array( $role ) && '' !== (string) $slug ) {
				$site[ (string) $slug ] = $role;
			}
		}

		$this->site_roles = $site;

		return $site;
	}

	/**
	 * @return array<string,string> The configured role of each status, keyed by status value.
	 */
	private function get_status_roles(): array
	{
		$roles = array();

		foreach ( MemberStatus::cases() as $status ) {
			$roles[ $status->value ] = $this->get_role( $status );
		}

		return $roles;
	}

	private function get_role( MemberStatus $status ): string
	{
		return (string) $this->settings->get( $status->role_setting(), '' );
	}
}
