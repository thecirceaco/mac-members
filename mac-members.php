<?php
/**
 * MAC Members
 *
 * @package           mac-members
 * @author            Circea
 * @copyright         2026 Circea
 *
 * @wordpress-plugin
 * Plugin Name:       MAC Members
 * Plugin URI:        https://circea.co
 * Description:       Company standard membership plugin for WordPress projects.
 * Version:           0.5.1
 * Author:            Circea
 * Author URI:        https://circea.co
 * Update URI:        https://updates.circea.co/mac-members/
 * Requires PHP:      8.3
 * Requires at least: 6.9
 * License:           GPL v3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/inc/constants.php';
require_once __DIR__ . '/inc/autoload.php';

\MacMembers\Kernel::boot();
