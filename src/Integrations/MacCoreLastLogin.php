<?php
/**
 * MAC Core's last login time, for the members table.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * MAC Core records the time of each login through a login form in the mac_core_last_login user meta while its
 * "Show last login column" setting is on. MAC Members only reads it: the members table shows a Last Login column
 * while MAC Core is active and records last logins. If MAC Core's settings change shape, the column just stops
 * showing.
 */
class MacCoreLastLogin
{
	public const META_KEY = 'mac_core_last_login';

	private const SETTINGS_OPTION = 'mac_core_settings';

	/**
	 * Whether MAC Core is active and records last logins.
	 */
	public function is_enabled(): bool
	{
		if ( ! \defined( 'MAC_CORE_VERSION' ) ) {
			return false;
		}

		$settings = \get_option( self::SETTINGS_OPTION, array() );

		return \is_array( $settings )
			&& \is_array( $settings['core'] ?? null )
			&& ! empty( $settings['core']['add_last_login_column'] );
	}

	/**
	 * @return int|null The user's last recorded login as a Unix time, or null when none was recorded.
	 */
	public function get( int $user_id ): ?int
	{
		$value = \get_user_meta( $user_id, self::META_KEY, true );

		return \is_numeric( $value ) && 0 < (int) $value ? (int) $value : null;
	}
}
