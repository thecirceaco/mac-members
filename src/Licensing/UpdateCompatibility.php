<?php
/**
 * WordPress compatibility shown for MAC Members updates.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Licensing;

use MacMembers\Contracts\Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Reads a `tested` version on the running WordPress branch as the running version.
 *
 * The SureCart SDK passes `tested` from `release.json` to WordPress as it is, and WordPress compares it with the
 * full running version: `7.1` is lower than `7.1.2`, so the Updates screen and "View details" said "Not tested".
 * wordpress.org treats a plugin tested up to `7.1` as tested on every `7.1.x` release, and so does this. MAC Core
 * has the same class (mac-core `c46803a`); only the fallback for WordPress before 6.7 is MAC Members' own.
 */
final class UpdateCompatibility implements Service
{
	public function register(): void
	{
		\add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_transient' ) );
		// After the SDK's plugins_api filter (priority 10), which returns MAC Members' release data.
		\add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
	}

	/**
	 * Adjusts MAC Members' pending update when the update_plugins transient is read.
	 */
	public function filter_update_transient( mixed $transient ): mixed
	{
		$basename = $this->basename();

		if ( ! is_object( $transient ) || ! isset( $transient->response[ $basename ] ) || ! is_object( $transient->response[ $basename ] ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = $this->with_branch_tested( $transient->response[ $basename ] );

		return $transient;
	}

	/**
	 * Adjusts MAC Members' "View details" data.
	 */
	public function filter_plugin_information( mixed $result, mixed $action = '', mixed $args = null ): mixed
	{
		if ( 'plugin_information' !== $action || ! is_object( $result ) || ! is_object( $args ) ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== dirname( $this->basename() ) ) {
			return $result;
		}

		return $this->with_branch_tested( $result );
	}

	/**
	 * Returns the update data with the running WordPress version as `tested` when `tested` is lower but on the
	 * same branch, such as 7.1 on 7.1.2. The data is copied, so the SDK's cached release data stays as SureCart
	 * sent it.
	 *
	 * The running version loses a suffix such as -RC1 first, as on the Updates screen, which compares `tested`
	 * with the version without it.
	 */
	private function with_branch_tested( object $data ): object
	{
		$tested  = isset( $data->tested ) && is_scalar( $data->tested ) ? (string) $data->tested : '';
		$running = (string) preg_replace( '/-.*$/', '', $this->running_version() );

		if ( '' === $tested || version_compare( $tested, $running, '>=' ) || $this->branch( $tested ) !== $this->branch( $running ) ) {
			return $data;
		}

		$data         = clone $data;
		$data->tested = $running;

		return $data;
	}

	/**
	 * The running WordPress version. wp_get_wp_version() arrived in WordPress 6.7 and can't be changed by another
	 * plugin; MAC Members supports 6.0, so older sites fall back to get_bloginfo( 'version' ).
	 */
	private function running_version(): string
	{
		return (string) ( \function_exists( 'wp_get_wp_version' ) ? \wp_get_wp_version() : \get_bloginfo( 'version' ) );
	}

	/**
	 * Returns the major.minor branch of a version, such as 7.1 for 7.1.2.
	 */
	private function branch( string $version ): string
	{
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}

	/**
	 * Returns MAC Members' plugin basename, such as mac-members/mac-members.php.
	 */
	private function basename(): string
	{
		return \plugin_basename( MAC_MEMBERS_PLUGIN_FILE );
	}
}
