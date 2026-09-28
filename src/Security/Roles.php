<?php
/**
 * The member roles that MAC Members creates.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Roles
{
	public const PENDING  = 'mac_members_pending';
	public const APPROVED = 'mac_members_approved';
	public const INACTIVE = 'mac_members_inactive';
	public const DENIED   = 'mac_members_denied';

	/**
	 * @return array<string,string> Display names keyed by role slug.
	 */
	public static function defaults(): array
	{
		return array(
			self::PENDING  => __( 'Member (Pending)', 'mac-members' ),
			self::APPROVED => __( 'Member', 'mac-members' ),
			self::INACTIVE => __( 'Member (Inactive)', 'mac-members' ),
			self::DENIED   => __( 'Member (Denied)', 'mac-members' ),
		);
	}

	/**
	 * Adds the member roles that do not exist yet, with only the `read` capability. A role that already
	 * exists keeps its name and capabilities.
	 */
	public static function create_missing(): void
	{
		foreach ( self::defaults() as $slug => $name ) {
			if ( ! \wp_roles()->is_role( $slug ) ) {
				\add_role( $slug, $name, array( 'read' => true ) );
			}
		}
	}

	/**
	 * Removes the member roles that no user holds. A role that a user still holds stays, so uninstalling the
	 * plugin never leaves a user without the role they had.
	 */
	/**
	 * How many users hold each member role that exists, in the order pending, approved, inactive, denied.
	 *
	 * @return array<string,array{name:string,users:int}> Role name and user count, keyed by slug.
	 */
	public static function usage(): array
	{
		$usage = array();

		foreach ( self::defaults() as $slug => $default_name ) {
			if ( ! \wp_roles()->is_role( $slug ) ) {
				continue;
			}

			$query = new \WP_User_Query(
				array(
					'role'        => $slug,
					'number'      => 1,
					'fields'      => 'ID',
					'count_total' => true,
				)
			);

			$usage[ $slug ] = array(
				'name'  => \translate_user_role( (string) ( \wp_roles()->roles[ $slug ]['name'] ?? $default_name ) ),
				'users' => (int) $query->get_total(),
			);
		}

		return $usage;
	}

	public static function remove_unused(): void
	{
		foreach ( array_keys( self::defaults() ) as $slug ) {
			if ( \wp_roles()->is_role( $slug ) && ! self::is_held( $slug ) ) {
				\remove_role( $slug );
			}
		}
	}

	private static function is_held( string $role ): bool
	{
		$query = new \WP_User_Query(
			array(
				'role'        => $role,
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => false,
			)
		);

		return array() !== (array) $query->get_results();
	}
}
