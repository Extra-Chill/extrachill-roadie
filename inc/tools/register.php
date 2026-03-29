<?php
/**
 * EC Platform Chat Tools — Registration
 *
 * Registers Extra Chill platform chat tools with Data Machine's tool system
 * via the `datamachine_tools` filter. Each tool wraps abilities from EC domain
 * plugins (extrachill-artist-platform, extrachill-users, extrachill-community)
 * and handles cross-site execution transparently.
 *
 * Tools are only registered when Data Machine is active (BaseTool class exists).
 *
 * @package ExtraChillRoadie\Tools
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register EC platform chat tools after all plugins have loaded.
 *
 * Uses `plugins_loaded` to ensure Data Machine's BaseTool class is available.
 * Each tool self-registers via the `datamachine_tools` filter in its constructor.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( '\DataMachine\Engine\AI\Tools\BaseTool' ) ) {
			return;
		}

		require_once __DIR__ . '/class-manage-artist-profile.php';
		require_once __DIR__ . '/class-manage-link-page.php';
		require_once __DIR__ . '/class-manage-user-profile.php';
		require_once __DIR__ . '/class-manage-community.php';

		new ECRoadie_ManageArtistProfile();
		new ECRoadie_ManageLinkPage();
		new ECRoadie_ManageUserProfile();
		new ECRoadie_ManageCommunity();
	}
);
