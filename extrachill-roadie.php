<?php
/**
 * Plugin Name: Extra Chill Roadie
 * Plugin URI: https://extrachill.com
 * Description: Extra Chill platform integration for Frontend Agent Chat. Registers a role-aware tool surface via the datamachine_tools filter — artist profiles, link pages, user profiles, and community forums, plus team-gated GitHub issue filing and sandbox-backed code contributions — and bridges EC theme tokens into the chat widget.
 * Version: 0.19.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-roadie
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: data-machine
 *
 * @package ExtraChillRoadie
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_ROADIE_VERSION', '0.19.0' );
define( 'EXTRACHILL_ROADIE_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_ROADIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/agent-mode/register.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/tools/register.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/permissions.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/team-experience/events.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/frontend-chat.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/onboarding.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/assets.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/contribute-code/subsite-context.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/contribute-code/repo-map.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/contribute-code/recipe-builder.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/contribute-code/capabilities.php';
require_once EXTRACHILL_ROADIE_PLUGIN_DIR . 'inc/contribute-code/inherit-resolver.php';

/**
 * Bootstrap Roadie policy hooks.
 *
 * @since 0.3.1
 * @return void
 */
function extrachill_roadie_bootstrap(): void {
	extrachill_roadie_sync_agent_policy();
}
add_action( 'plugins_loaded', 'extrachill_roadie_bootstrap', 20 );
