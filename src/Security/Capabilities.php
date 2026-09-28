<?php
/**
 * Capabilities that MAC Members grants and checks.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Security;

final class Capabilities
{
	/**
	 * Lets a user review pending members. Administrators get it when the plugin is installed.
	 */
	public const REVIEW = 'mac_members_review';

	/**
	 * Capabilities that the approved and denied roles must not grant, and that a user under review must not
	 * hold. Each of them can change the site, its code or other users.
	 *
	 * @var array<int,string>
	 */
	public const SENSITIVE = array(
		'manage_options',
		'edit_users',
		'promote_users',
		'delete_users',
		'unfiltered_html',
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
		'unfiltered_upload',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
	);

	/**
	 * Whether the current user may review pending members.
	 */
	public static function current_user_can_review(): bool
	{
		return \is_user_logged_in()
			&& \current_user_can( self::REVIEW )
			&& \current_user_can( 'promote_users' );
	}

	/**
	 * @return array<int,string> The sensitive capabilities that the role grants.
	 */
	public static function sensitive_capabilities_of_role( string $role ): array
	{
		$role_object = \wp_roles()->get_role( $role );

		if ( ! $role_object instanceof \WP_Role ) {
			return array();
		}

		$granted = array_keys( array_filter( (array) $role_object->capabilities ) );

		return array_values( array_intersect( self::SENSITIVE, $granted ) );
	}

	/**
	 * Whether the user holds a sensitive capability, through a role or directly.
	 */
	public static function user_has_sensitive_capability( \WP_User $user ): bool
	{
		foreach ( self::SENSITIVE as $capability ) {
			if ( \user_can( $user, $capability ) ) {
				return true;
			}
		}

		return false;
	}
}
