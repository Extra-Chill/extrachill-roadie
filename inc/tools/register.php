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

require_once __DIR__ . '/caller.php';

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

		require_once __DIR__ . '/class-ec-platform-tool.php';
		require_once __DIR__ . '/class-manage-artist-profile.php';
		require_once __DIR__ . '/class-manage-link-page.php';
		require_once __DIR__ . '/class-manage-user-profile.php';
		require_once __DIR__ . '/class-manage-community.php';
		require_once __DIR__ . '/class-writing-assistant.php';
		require_once __DIR__ . '/class-propose-code-change.php';
		require_once __DIR__ . '/class-apply-code-change.php';
		require_once __DIR__ . '/class-file-feature-request.php';
		require_once __DIR__ . '/class-inspect-code.php';
		require_once __DIR__ . '/class-inspect-page.php';
		require_once __DIR__ . '/class-present-question.php';
		require_once __DIR__ . '/class-search-content.php';

		new ECRoadie_ManageArtistProfile();
		new ECRoadie_ManageLinkPage();
		new ECRoadie_ManageUserProfile();
		new ECRoadie_ManageCommunity();
		new ECRoadie_WritingAssistant();
		new ECRoadie_ProposeCodeChange();
		new ECRoadie_ApplyCodeChange();
		new ECRoadie_FileFeatureRequest();
		new ECRoadie_InspectCode();
		new ECRoadie_InspectPage();
		new ECRoadie_PresentQuestion();
		new ECRoadie_SearchContent();
	}
);

/**
 * Roadie platform tools tracked as team-experience management actions.
 *
 * This list is used for analytics only. Tool availability comes from Roadie
 * mode registration; each tool or target ability/route owns its capability and
 * resource authorization. In particular, agent access is not a substitute for
 * artist ownership checks.
 *
 * @since 0.10.0
 * @since 0.12.0 Added the read-only, team-gated `inspect_code` tool.
 * @since 0.14.0 Added the read-only, team-gated `inspect_page` (DOM) tool.
 * @return string[]
 */
function extrachill_roadie_managed_tool_slugs(): array {
	return array(
		'manage_artist_profile',
		'manage_link_page',
		'manage_user_profile',
		'manage_community',
		'writing_assistant',
		'propose_code_change',
		'apply_code_change',
		'file_feature_request',
		'inspect_code',
		'inspect_page',
	);
}
