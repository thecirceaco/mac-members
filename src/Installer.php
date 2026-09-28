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

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

final class Installer implements Service
{
	/**
	 * Stores which install steps ran, so a site that updates without reactivating runs them once.
	 */
	public const VERSION_OPTION = 'mac_members_install_version';

	/**
	 * Raise this when an install step is added.
	 */
	private const VERSION = 1;

	/**
	 * Role that receives the review capability.
	 */
	private const REVIEWER_ROLE = 'administrator';

	public function register(): void
	{
		\register_activation_hook( MAC_MEMBERS_PLUGIN_FILE, array( $this, 'install' ) );
		\add_action( 'init', array( $this, 'maybe_install' ) );
	}

	public function install(): void
	{
		$role = \get_role( self::REVIEWER_ROLE );

		if ( $role instanceof \WP_Role ) {
			$role->add_cap( Capabilities::REVIEW );
		}

		\update_option( self::VERSION_OPTION, self::VERSION );
		\register_uninstall_hook( MAC_MEMBERS_PLUGIN_FILE, array( self::class, 'uninstall' ) );
	}

	/**
	 * Runs the install steps on a site that updated the plugin without reactivating it. It runs once, so
	 * removing the review capability from administrators later is kept.
	 */
	public function maybe_install(): void
	{
		if ( self::VERSION <= (int) \get_option( self::VERSION_OPTION, 0 ) ) {
			return;
		}

		$this->install();
	}

	/**
	 * Uninstall callback. WordPress stores it in an option, so it has to be static.
	 */
	public static function uninstall(): void
	{
		foreach ( \wp_roles()->role_objects as $role ) {
			$role->remove_cap( Capabilities::REVIEW );
		}

		\delete_option( self::VERSION_OPTION );
		MemberLock::delete_all();
	}
}
