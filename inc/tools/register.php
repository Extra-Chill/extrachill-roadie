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
	}
);

/**
 * The roadie platform management tools, by slug.
 *
 * These are the team-gated tools registered above. They are the set hidden
 * from public-tier callers — a visitor with no team access cannot use any of
 * them, so offering them only produces dead options and permission-error
 * round-trips.
 *
 * Most are write-capable; `inspect_code` and `inspect_page` are read-only but
 * still team-gated (they read platform source / the rendered DOM to ground UI
 * feedback), so they belong here for the same visibility reason — a public
 * visitor has no source to inspect and no team access to do it with.
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

/**
 * Hide roadie management tools from public-tier callers.
 *
 * Per-call execution gating already exists in ECRoadie_PlatformTool (a public
 * user gets a clean permission error), but the tools are still *offered* to the
 * model — cluttering the prompt with options a visitor can never use. This
 * filter removes them from the exposed tool set for public-tier callers so the
 * surface matches the role-aware guidance.
 *
 * Uses Data Machine's `datamachine_resolved_tools` filter — the canonical
 * per-request, post-policy tool-visibility seam. It fires once per chat turn
 * with the resolved tool set plus the resolution `$args`, which carry the
 * `calling_user_id` threaded through from the chat orchestrator. No new infra:
 * we resolve the caller's tier via extrachill_roadie_user_tier() (the same
 * mapping the guidance uses) and unset the management tools for `public`.
 *
 * Team and admin callers are unaffected — they keep the full surface and rely
 * on the existing per-call gates for fine-grained checks (e.g. acting on
 * behalf of another user is still admin-only at execution time).
 *
 * Only acts when the `roadie` mode is active, so non-roadie chat surfaces that
 * happen to share the resolver are untouched.
 *
 * @since 0.10.0
 *
 * @param array        $tools Resolved tools keyed by tool name.
 * @param string|array $mode  Active mode slug, or array of mode slugs.
 * @param array        $args  Resolution args (carries `calling_user_id`).
 * @return array Filtered tools.
 */
function extrachill_roadie_filter_tools_by_tier( $tools, $mode, array $args ): array {
	if ( ! is_array( $tools ) ) {
		return is_array( $tools ) ? $tools : array();
	}

	// Only act when the roadie mode is part of this turn.
	$modes = is_array( $mode ) ? $mode : array( $mode );
	if ( ! in_array( EXTRACHILL_ROADIE_AGENT_SLUG, array_map( 'strval', $modes ), true ) ) {
		return $tools;
	}

	if ( ! function_exists( 'extrachill_roadie_user_tier' ) ) {
		return $tools;
	}

	$calling_user_id = 0;
	if ( isset( $args['calling_user_id'] ) && is_numeric( $args['calling_user_id'] ) ) {
		$calling_user_id = max( 0, (int) $args['calling_user_id'] );
	}

	// Team and admin keep the full surface.
	if ( EXTRACHILL_ROADIE_TIER_PUBLIC !== extrachill_roadie_user_tier( $calling_user_id ) ) {
		return $tools;
	}

	foreach ( extrachill_roadie_managed_tool_slugs() as $slug ) {
		unset( $tools[ $slug ] );
	}

	return $tools;
}
add_filter( 'datamachine_resolved_tools', 'extrachill_roadie_filter_tools_by_tier', 10, 3 );
