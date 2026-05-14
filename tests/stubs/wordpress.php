<?php
/**
 * Minimal WordPress stubs for fast unit tests.
 *
 * @package mac-members
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

function mac_members_tests_reset_wp_state(): void
{
	$GLOBALS['mac_members_test_actions'] = [];
	$GLOBALS['mac_members_test_filters'] = [];
}

mac_members_tests_reset_wp_state();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): true
	{
		$GLOBALS['mac_members_test_actions'][ $hook_name ][] = [
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): true
	{
		$GLOBALS['mac_members_test_filters'][ $hook_name ][] = [
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		];

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed
	{
		$filtered = $value;

		foreach ( $GLOBALS['mac_members_test_filters'][ $hook_name ] ?? [] as $registration ) {
			$callback = $registration['callback'] ?? null;

			if ( ! is_callable( $callback ) ) {
				continue;
			}

			$filtered = $callback( $filtered, ...$args );
		}

		return $filtered;
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string
	{
		return 'https://example.test/wp-content/plugins/mac-members/';
	}
}
