<?php
/**
 * The WordPress version that MAC Members updates say they were tested with.
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
 * Makes a release's `tested` value cover its whole WordPress branch, the way wordpress.org reads "Tested up to:
 * 7.1". The SureCart SDK passes `tested` from release.json as it is, and WordPress compares it with the full
 * running version, so `7.1` on 7.1.2, or `7.1.2` on 7.1.3, would show "Not tested" on the Updates screen and in
 * "View details" after every WordPress patch release. A value from an older branch stays as it is. MAC Core has
 * the same class.
 */
final class UpdateCompatibility implements Service
{
	private const SLUG = 'mac-members';

	public function register(): void
	{
		// After the SureCart SDK, which fills in the update and the plugin details at the default priority.
		\add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins' ), 20 );
		\add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 20, 3 );
	}

	/**
	 * @param mixed $transient The update_plugins site transient.
	 *
	 * @return mixed
	 */
	public function filter_update_plugins( mixed $transient ): mixed
	{
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$basename = \plugin_basename( MAC_MEMBERS_PLUGIN_FILE );

		foreach ( array( 'response', 'no_update' ) as $list ) {
			if ( isset( $transient->{$list}[ $basename ] ) && is_object( $transient->{$list}[ $basename ] ) ) {
				$this->cover_branch( $transient->{$list}[ $basename ] );
			}
		}

		return $transient;
	}

	/**
	 * @param mixed  $result The plugin information, or false when nothing answered yet.
	 * @param string $action The plugins_api action.
	 * @param mixed  $args   The request arguments.
	 *
	 * @return mixed
	 */
	public function filter_plugin_information( mixed $result, string $action = '', mixed $args = null ): mixed
	{
		if ( 'plugin_information' === $action && is_object( $result ) && is_object( $args ) && self::SLUG === ( $args->slug ?? '' ) ) {
			$this->cover_branch( $result );
		}

		return $result;
	}

	/**
	 * Raises `tested` to the running WordPress version when both are on the same branch and `tested` is lower.
	 */
	private function cover_branch( object $update ): void
	{
		if ( ! isset( $update->tested ) || ! is_string( $update->tested ) || '' === $update->tested ) {
			return;
		}

		$running = self::running_version();

		if ( '' !== $running && self::branch( $update->tested ) === self::branch( $running ) && version_compare( $update->tested, $running, '<' ) ) {
			$update->tested = $running;
		}
	}

	/**
	 * The running WordPress version without a suffix such as "-RC1", which the Updates screen drops too.
	 * wp_get_wp_version() arrived in WordPress 6.7 and can't be changed by a plugin; MAC Members supports 6.0.
	 */
	private static function running_version(): string
	{
		$version = \function_exists( 'wp_get_wp_version' ) ? \wp_get_wp_version() : \get_bloginfo( 'version' );

		return (string) preg_replace( '/-.*$/', '', (string) $version );
	}

	private static function branch( string $version ): string
	{
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}
}
