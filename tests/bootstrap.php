<?php
/**
 * PHPUnit bootstrap for MAC Members.
 *
 * @package mac-members
 */

declare(strict_types=1);

require_once __DIR__ . '/stubs/wordpress.php';

$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( is_readable( $composer_autoload ) ) {
	require_once $composer_autoload;
}
