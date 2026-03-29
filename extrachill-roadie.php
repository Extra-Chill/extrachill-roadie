<?php
/**
 * Plugin Name: Extra Chill Roadie
 * Plugin URI: https://extrachill.com
 * Description: Extra Chill platform chat tools for Data Machine agents. Provides artist profiles, link pages, user profiles, and community forum tools via the datamachine_tools filter.
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-roadie
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 *
 * @package ExtraChillRoadie
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ROADIE_VERSION', '0.1.0' );
define( 'EXTRACHILL_ROADIE_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_ROADIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/tools/register.php';
