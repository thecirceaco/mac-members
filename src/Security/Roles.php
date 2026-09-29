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
	 * The Member Reviewer role: only the review capability, so it goes on top of a user's own role, which gives
	 * them `read` and the rest. It isn't a status role.
	 */
	public const REVIEWER = 'mac_members_reviewer';

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
	 * Adds the Member Reviewer role with only the review capability. When the role exists, it keeps its name
	 * and gets the capability back, for example after deleting the plugin with its data removed it.
	 */
	public static function create_reviewer(): void
	{
		$role = \get_role( self::REVIEWER );

		if ( $role instanceof \WP_Role ) {
			$role->add_cap( Capabilities::REVIEW );
			return;
		}

		\add_role( self::REVIEWER, __( 'Member Reviewer', 'mac-members' ), array( Capabilities::REVIEW => true ) );
	}

	/**
	 * The member roles that do not exist, for example after a role editor deleted one. Activating the plugin
	 * creates them again.
	 *
	 * @return array<int,string> Role slugs.
	 */
	public static function missing(): array
	{
		return array_values( array_filter( array_keys( self::defaults() ), static fn ( string $slug ): bool => ! \wp_roles()->is_role( $slug ) ) );
	}
}
