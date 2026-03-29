<?php
/**
 * Plugin Name: Extra Chill Agent Kit
 * Plugin URI: https://extrachill.com
 * Description: EC platform chat tools for Data Machine agents. Registers Extra Chill-specific abilities as chat tools via the datamachine_tools filter.
 * Version: 0.1.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-agent-kit
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: true
 *
 * @package ExtraChillAgentKit
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EC_AGENT_KIT_VERSION', '0.1.0' );
define( 'EC_AGENT_KIT_PLUGIN_FILE', __FILE__ );
define( 'EC_AGENT_KIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once EC_AGENT_KIT_PLUGIN_DIR . 'inc/tools/register.php';
