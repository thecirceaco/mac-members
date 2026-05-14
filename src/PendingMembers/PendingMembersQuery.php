<?php
/**
 * Pending member query helper.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\PendingMembers;

use MacMembers\Settings\SettingsRepositoryInterface;

final class PendingMembersQuery
{
	public function __construct(
		private readonly SettingsRepositoryInterface $settings
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function get_query_args(): array
	{
		return array(
			'role'        => (string) $this->settings->get( 'pending_role', 'member-pending' ),
			'number'      => 50,
			'orderby'     => 'registered',
			'order'       => 'ASC',
			'fields'      => 'all',
			'count_total' => false,
		);
	}

	/**
	 * @return array<int,object>
	 */
	public function get_pending_users(): array
	{
		$query = new \WP_User_Query( $this->get_query_args() );
		$users = $query->get_results();

		return \is_array( $users ) ? $users : array();
	}
}
