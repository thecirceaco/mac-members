<?php
/**
 * Per-member lock for approve and deny.
 *
 * @package mac-members
 */

declare(strict_types=1);

namespace MacMembers\Actions;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Lets only one request at a time change a member's roles.
 *
 * The lock is a row in the options table, created with INSERT IGNORE: the same atomic insert that core's
 * WP_Upgrader::create_lock() uses, so exactly one request can create it. add_option() and set_transient() are
 * not atomic, because they check for the option first and then insert with ON DUPLICATE KEY UPDATE, and
 * wp_cache_add() is only atomic with a persistent object cache.
 */
final class MemberLock
{
	private const OPTION_PREFIX = 'mac_members_lock_';

	/**
	 * Seconds after which a lock left behind by a request that died can be taken over.
	 */
	private const TIMEOUT = 30;

	/**
	 * Values of the locks this request holds, by user ID.
	 *
	 * @var array<int,string>
	 */
	private array $held = array();

	public function acquire( int $user_id ): bool
	{
		$name  = self::OPTION_PREFIX . $user_id;
		$value = ( time() + self::TIMEOUT ) . ':' . bin2hex( random_bytes( 8 ) );

		if ( ! $this->insert( $name, $value ) && ! $this->take_over_expired( $name, $value ) ) {
			return false;
		}

		$this->held[ $user_id ] = $value;

		return true;
	}

	public function release( int $user_id ): void
	{
		if ( ! isset( $this->held[ $user_id ] ) ) {
			return;
		}

		// Delete by value, so a lock that expired and was taken over by another request is kept.
		$this->delete( self::OPTION_PREFIX . $user_id, $this->held[ $user_id ] );
		unset( $this->held[ $user_id ] );
	}

	/**
	 * Removes every lock row. Used on uninstall.
	 */
	public static function delete_all(): void
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock rows are written with direct queries, see the class comment.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OPTION_PREFIX ) . '%' ) );
	}

	private function take_over_expired( string $name, string $value ): bool
	{
		$current = $this->read( $name );

		if ( null === $current ) {
			// The lock was released in the meantime.
			return $this->insert( $name, $value );
		}

		if ( (int) strstr( $current, ':', true ) >= time() ) {
			return false;
		}

		// Deleting the expired value, not the name, means only one request can replace it.
		return $this->delete( $name, $current ) && $this->insert( $name, $value );
	}

	private function insert( string $name, string $value ): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert, see the class comment.
		return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
	}

	private function read( string $name ): ?string
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock row must be read from the database, not the options cache.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		return \is_string( $value ) ? $value : null;
	}

	private function delete( string $name, string $value ): bool
	{
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes the lock only if it still has this value.
		return 1 === $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $value ) );
	}
}
