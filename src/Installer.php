<?php
/**
 * Install and uninstall steps.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers;

use MacMembers\Actions\MemberLock;
use MacMembers\Contracts\Service;
use MacMembers\Security\Capabilities;
use MacMembers\Security\Roles;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Installer implements Service
{
	/**
	 * Stores which install steps ran, so a site that updates without reactivating runs the new ones once.
	 */
	public const VERSION_OPTION = 'mac_members_install_version';

	/**
	 * Raise this when an install step is added, and run the step in maybe_install() for older versions.
	 *
	 * 1: administrators get the review capability.
	 * 2: the member roles are created.
	 * 3: the Member Reviewer role is created.
	 */
	private const VERSION = 3;

	/**
	 * Role that receives the review capability.
	 */
	private const REVIEWER_ROLE = 'administrator';

	public function register(): void
	{
		\register_activation_hook( MAC_MEMBERS_PLUGIN_FILE, array( $this, 'install' ) );
		\add_action( 'init', array( $this, 'maybe_install' ) );
	}

	/**
	 * Runs every install step. Activation calls it, so reactivating the plugin brings back a member role
	 * that was deleted.
	 */
	public function install(): void
	{
		$this->grant_review_capability();
		Roles::create_missing();
		Roles::create_reviewer();
		$this->finish();
	}

	/**
	 * Runs the install steps that are newer than the ones this site ran, on a site that updated the plugin
	 * without reactivating it. Each step runs once, so removing the review capability from administrators
	 * later is kept.
	 */
	public function maybe_install(): void
	{
		$installed = (int) \get_option( self::VERSION_OPTION, 0 );

		if ( self::VERSION <= $installed ) {
			return;
		}

		if ( 1 > $installed ) {
			$this->grant_review_capability();
		}

		if ( 2 > $installed ) {
			Roles::create_missing();
		}

		if ( 3 > $installed ) {
			Roles::create_reviewer();
		}

		$this->finish();
	}

	/**
	 * Uninstall callback. WordPress stores it in an option, so it has to be static.
	 *
	 * The per-user lock rows always go, because they only exist while a status change runs. Everything else
	 * stays, like in MAC Core, unless "Delete plugin data on uninstall" is on. Then the settings, the install
	 * version, the review capability, the member and Member Reviewer roles that no user holds, and the SureCart
	 * license and update data go too. Roles that users still hold stay, so nobody is left without a role;
	 * activating the plugin again gives the Member Reviewer role its capability back.
	 */
	public static function uninstall(): void
	{
		MemberLock::delete_all();

		$settings = \get_option( MAC_MEMBERS_SETTINGS_OPTION, array() );

		if ( ! \is_array( $settings ) || true !== ( $settings['delete_data_on_uninstall'] ?? false ) ) {
			return;
		}

		foreach ( \wp_roles()->role_objects as $role ) {
			$role->remove_cap( Capabilities::REVIEW );
		}

		Roles::remove_unused();
		\delete_option( self::VERSION_OPTION );
		\delete_option( MAC_MEMBERS_SETTINGS_OPTION );
		// The SureCart SDK names these after the product name and the plugin slug.
		\delete_option( 'macmembers_license_options' );
		\delete_transient( 'surecart_' . md5( 'mac-members' ) . '_version_info' );
	}

	private function grant_review_capability(): void
	{
		$role = \get_role( self::REVIEWER_ROLE );

		if ( $role instanceof \WP_Role ) {
			$role->add_cap( Capabilities::REVIEW );
		}
	}

	private function finish(): void
	{
		\update_option( self::VERSION_OPTION, self::VERSION );
		\register_uninstall_hook( MAC_MEMBERS_PLUGIN_FILE, array( self::class, 'uninstall' ) );
	}
}
