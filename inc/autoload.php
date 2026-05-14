<?php
/**
 * mac-members autoloader.
 *
 * Registers a PSR-4 style autoloader for the MacMembers namespace.
 *
 * @package mac-members
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

\spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'MacMembers\\';
		$base_dir = rtrim( MAC_MEMBERS_SRC_PATH, '/\\' ) . '/';

		if ( \strncmp( $prefix, $class, \strlen( $prefix ) ) !== 0 ) {
			return;
		}

		$relative_class = \substr( $class, \strlen( $prefix ) );
		$file           = $base_dir . \str_replace( '\\', '/', $relative_class ) . '.php';

		if ( \is_readable( $file ) ) {
			require $file;
		}
	}
);
