<?php
/**
 * Member queries for the members table.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Members;

use MacMembers\Settings\SettingsRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class MembersQuery
{
	public const PER_PAGE = 50;

	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	/**
	 * Pending requests are listed oldest first, like a queue; the other views newest first.
	 *
	 * @param MemberStatus|null $status Status to list, or null for every member.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_args( ?MemberStatus $status, int $page = 1 ): array
	{
		return array(
			'role__in'    => null === $status ? array_values( $this->get_status_roles() ) : array( $this->get_role( $status ) ),
			'number'      => self::PER_PAGE,
			'paged'       => max( 1, $page ),
			'orderby'     => 'registered',
			'order'       => MemberStatus::Pending === $status ? 'ASC' : 'DESC',
			'fields'      => 'all',
			'count_total' => true,
		);
	}

	/**
	 * @param MemberStatus|null $status Status to list, or null for every member.
	 *
	 * @return array{users:array<int,object>,total:int} One page of members and the number of members in the view.
	 */
	public function get_members( ?MemberStatus $status, int $page = 1 ): array
	{
		$query = new \WP_User_Query( $this->get_query_args( $status, $page ) );
		$users = $query->get_results();

		return array(
			'users' => \is_array( $users ) ? $users : array(),
			'total' => (int) $query->get_total(),
		);
	}

	/**
	 * @return array<string,int> Number of members with each status, keyed by status value.
	 */
	public function count_by_status(): array
	{
		$counts = array();

		foreach ( MemberStatus::cases() as $status ) {
			$query = new \WP_User_Query(
				array(
					'role'        => $this->get_role( $status ),
					'number'      => 1,
					'fields'      => 'ID',
					'count_total' => true,
				)
			);

			$counts[ $status->value ] = (int) $query->get_total();
		}

		return $counts;
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
