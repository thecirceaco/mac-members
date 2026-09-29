<?php
/**
 * mac-members constants.
 *
 * Centralized plugin constants.
 * This file must not contain logic.
 *
 * @package mac-members
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! defined( 'MAC_MEMBERS_VERSION' ) ) {
	define( 'MAC_MEMBERS_VERSION', '0.5.1' );
}

// Public token of the Circea SureCart store, the same as MAC Core's. The license key decides the product. It
// isn't a secret: every copy of the plugin carries it.
if ( ! defined( 'MAC_MEMBERS_SURECART_PUBLIC_TOKEN' ) ) {
	define( 'MAC_MEMBERS_SURECART_PUBLIC_TOKEN', 'pt_T7wtCcQ2DNPZvadkHjy1uXbw' );
}

// The MAC Members page on the Circea docs site, linked from the plugins screen and the Support tab.
if ( ! defined( 'MAC_MEMBERS_DOCS_URL' ) ) {
	define( 'MAC_MEMBERS_DOCS_URL', 'https://docs.circea.co/doc/mac-members/' );
}

if ( ! defined( 'MAC_MEMBERS_ADMIN_SLUG' ) ) {
	define( 'MAC_MEMBERS_ADMIN_SLUG', 'mac-members' );
}

if ( ! defined( 'MAC_MEMBERS_SETTINGS_OPTION' ) ) {
	define( 'MAC_MEMBERS_SETTINGS_OPTION', 'mac_members_settings' );
}

if ( ! defined( 'MAC_MEMBERS_PATH' ) ) {
	define( 'MAC_MEMBERS_PATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'MAC_MEMBERS_PLUGIN_FILE' ) ) {
	define( 'MAC_MEMBERS_PLUGIN_FILE', MAC_MEMBERS_PATH . 'mac-members.php' );
}

if ( ! defined( 'MAC_MEMBERS_SRC_PATH' ) ) {
	define( 'MAC_MEMBERS_SRC_PATH', MAC_MEMBERS_PATH . 'src/' );
}

if ( ! defined( 'MAC_MEMBERS_ASSETS_PATH' ) ) {
	define( 'MAC_MEMBERS_ASSETS_PATH', MAC_MEMBERS_PATH . 'assets/' );
}

if ( ! defined( 'MAC_MEMBERS_URL' ) ) {
	define( 'MAC_MEMBERS_URL', plugin_dir_url( MAC_MEMBERS_PLUGIN_FILE ) );
}

if ( ! defined( 'MAC_MEMBERS_ASSETS_URL' ) ) {
	define( 'MAC_MEMBERS_ASSETS_URL', MAC_MEMBERS_URL . 'assets/' );
}
