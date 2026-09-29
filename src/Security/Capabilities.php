<?php
/**
 * Capabilities that MAC Members grants and checks.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Capabilities
{
	/**
	 * Lets a user review pending members. Administrators get it when the plugin is installed.
	 */
	public const REVIEW = 'mac_members_review';

	/**
	 * Capabilities of administrators: each of them can change the site, its code or other users. The members
	 * table hides the roles that grant one, and status changes never touch a user who holds one.
	 *
	 * @var array<int,string>
	 */
	public const ADMINISTRATIVE = array(
		'manage_options',
		'edit_users',
		'promote_users',
		'delete_users',
		'edit_plugins',
		'edit_themes',
		'install_plugins',
		'activate_plugins',
		'create_users',
		'remove_users',
		'edit_files',
		'update_core',
		'update_plugins',
		'delete_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'switch_themes',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
	);

	/**
	 * Capabilities that a member role must never grant, since approving a member would hand them out: the
	 * administrative ones, and unfiltered_html and unfiltered_upload, which let a user add scripts to the site.
	 * Editors hold unfiltered_html, but that alone does not hide them from the members table.
	 *
	 * @var array<int,string>
	 */
	public const SENSITIVE = array( ...self::ADMINISTRATIVE, 'unfiltered_html', 'unfiltered_upload' );

	/**
	 * Whether the current user may use the members table: see it and change member statuses. The review
	 * capability is a narrow promote_users: the plugin only moves members between the four member roles, never
	 * adds one that grants a sensitive capability, and never touches the reviewer, a user with an administrative
	 * capability or a user with a hidden role. So a reviewer needs no promote_users, which in wp-admin would let
	 * them give anyone any role.
	 */
	public static function current_user_can_review(): bool
	{
		return \is_user_logged_in() && \current_user_can( self::REVIEW );
	}

	/**
	 * @return array<int,string> The sensitive capabilities that the role grants.
	 */
	public static function sensitive_capabilities_of_role( string $role ): array
	{
		return self::granted_by_role( $role, self::SENSITIVE );
	}

	/**
	 * @return array<int,string> The administrative capabilities that the role grants.
	 */
	public static function administrative_capabilities_of_role( string $role ): array
	{
		return self::granted_by_role( $role, self::ADMINISTRATIVE );
	}

	/**
	 * Whether the user holds an administrative capability, through a role or directly.
	 */
	public static function user_has_administrative_capability( \WP_User $user ): bool
	{
		foreach ( self::ADMINISTRATIVE as $capability ) {
			if ( \user_can( $user, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,string> $capabilities The capabilities to look for.
	 *
	 * @return array<int,string> Those of them that the role grants, in the order given.
	 */
	private static function granted_by_role( string $role, array $capabilities ): array
	{
		$role_object = \wp_roles()->get_role( $role );

		if ( ! $role_object instanceof \WP_Role ) {
			return array();
		}

		$granted = array_keys( array_filter( (array) $role_object->capabilities ) );

		return array_values( array_intersect( $capabilities, $granted ) );
	}
}
