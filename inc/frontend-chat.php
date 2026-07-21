<?php
/**
 * Frontend Agent Chat branding for Roadie.
 *
 * @package ExtraChillRoadie
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand the generic frontend chat launcher for Roadie.
 *
 * @param array $config Frontend chat configuration.
 * @return array
 */
function extrachill_roadie_frontend_chat_config( array $config ): array {
	$agent_slug = sanitize_title( (string) ( $config['agent_slug'] ?? '' ) );
	if ( '' !== $agent_slug && EXTRACHILL_ROADIE_AGENT_SLUG !== $agent_slug ) {
		return $config;
	}

	$config['fab_label'] = EXTRACHILL_ROADIE_AGENT_NAME;

	// The widget is team-only (extrachill_roadie_gate_widget_visibility()), so
	// every caller who actually sees this greeting is a signed-in team member
	// or admin — one working-assistant framing covers the whole audience.
	$config['fab_greeting'] = __( 'Hey — what can I help you with on Extra Chill?', 'extrachill-roadie' );

	// Suppress the generic "AI" leading-icon label; Roadie ships with no FAB icon yet.
	// When a Roadie brand mark is ready, set this to an SVG path string instead.
	$config['fab_icon'] = '';

	return $config;
}
add_filter( 'frontend_agent_chat_config', 'extrachill_roadie_frontend_chat_config' );

/**
 * Activate Roadie execution mode + real page context for the frontend widget.
 *
 * The generic Frontend Agent Chat widget sends no execution mode and only a
 * transport-level `client_context` (source/client_name/connector_id), so Data
 * Machine defaults the turn to bare `chat`. That bare mode is location-agnostic
 * but carries none of the Extra Chill platform context, and Roadie's registered
 * `roadie` mode never activates because the widget never requests it.
 *
 * This hook — the EC-domain complement to the generic FAC seam — composes the
 * `roadie` mode on top of `chat` (additive: roadie's EC platform directive +
 * roadie tools) and populates `client_context` with the real page/site the
 * user is viewing, which Data Machine's ClientContextDirective renders into the
 * prompt so the agent has accurate location awareness.
 *
 * Guarded on the Roadie agent slug, mirroring the branding hook above: a
 * non-roadie agent or a non-frontend caller is unaffected.
 *
 * @param array            $chat_input Canonical agents/chat input.
 * @param \WP_REST_Request $request    REST request.
 * @param string           $agent_slug Selected agent slug.
 * @param array            $config     Frontend chat configuration.
 * @return array Modified chat input.
 */
function extrachill_roadie_frontend_chat_input( $chat_input, $request, string $agent_slug, array $config ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $config is required by the 4-arg frontend_agent_chat_chat_input/queue_input filter signature.
	if ( ! is_array( $chat_input ) ) {
		return is_array( $chat_input ) ? $chat_input : array();
	}

	if ( EXTRACHILL_ROADIE_AGENT_SLUG !== sanitize_title( $agent_slug ) ) {
		return $chat_input;
	}

	$chat_input['modes']          = extrachill_roadie_compose_modes( $chat_input['modes'] ?? array() );
	$chat_input['client_context'] = extrachill_roadie_build_client_context(
		is_array( $chat_input['client_context'] ?? null ) ? $chat_input['client_context'] : array(),
		$request
	);

	return $chat_input;
}
add_filter( 'frontend_agent_chat_chat_input', 'extrachill_roadie_frontend_chat_input', 10, 4 );
add_filter( 'frontend_agent_chat_queue_input', 'extrachill_roadie_frontend_chat_input', 10, 4 );

/**
 * Apply Roadie's network workspace to the complete conversation lifecycle.
 *
 * @param array            $input      Canonical ability input.
 * @param string           $ability    Canonical ability name.
 * @param \WP_REST_Request $request    REST request.
 * @param string           $agent_slug Selected agent slug.
 * @param array            $config     Frontend chat configuration.
 * @return array Modified ability input.
 */
function extrachill_roadie_frontend_chat_ability_input( $input, string $ability, $request, string $agent_slug, array $config ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $request is required by the generic FAC filter signature.
	if ( ! is_array( $input ) ) {
		return array();
	}

	if ( ! extrachill_roadie_frontend_chat_targets_roadie( $agent_slug, $config ) ) {
		return $input;
	}

	$workspace_abilities = array(
		'agents/chat',
		'agents/queue-chat-message',
		'agents/list-conversation-sessions',
		'agents/get-conversation-session',
		'agents/mark-conversation-session-read',
		'agents/delete-conversation-session',
		'agents/update-conversation-session-title',
		'agents/get-chat-run',
		'agents/list-chat-run-events',
		'agents/cancel-chat-run',
	);
	if ( ! in_array( $ability, $workspace_abilities, true ) ) {
		return $input;
	}

	$input['workspace'] = extrachill_roadie_conversation_workspace();
	if ( 'agents/list-conversation-sessions' === $ability ) {
		$input['context'] = implode( ',', extrachill_roadie_compose_modes( array() ) );
	}

	return $input;
}
add_filter( 'frontend_agent_chat_ability_input', 'extrachill_roadie_frontend_chat_ability_input', 10, 5 );

/**
 * Project a pending action's opaque stored origin into canonical resolver input.
 *
 * FAC has already excluded arbitrary browser client context. This callback
 * accepts only the pending-action workspace and Data Machine's server-stamped
 * WordPress origin. Data Machine remains authoritative and verifies the routed
 * store's action repeats the claimed origin before resolving it.
 *
 * @param array            $input   Canonical pending-action resolver input.
 * @param \WP_REST_Request $request REST request.
 * @param array            $origin  Untrusted opaque origin returned by the client.
 * @param array            $config  Frontend chat configuration.
 * @return array Modified resolver input.
 */
function extrachill_roadie_frontend_chat_pending_action_resolve_input( $input, $request, array $origin, array $config ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $request must never be used as an origin source.
	if ( ! is_array( $input ) || ! extrachill_roadie_frontend_chat_targets_roadie( '', $config ) ) {
		return is_array( $input ) ? $input : array();
	}

	$workspace = is_array( $origin['workspace'] ?? null ) ? $origin['workspace'] : array();
	$metadata  = is_array( $origin['metadata'] ?? null ) ? $origin['metadata'] : array();
	$dm_meta   = is_array( $metadata['datamachine'] ?? null ) ? $metadata['datamachine'] : array();
	$context   = is_array( $dm_meta['context'] ?? null ) ? $dm_meta['context'] : array();
	$wordpress = is_array( $context['wordpress'] ?? null ) ? $context['wordpress'] : array();
	$blog_id   = absint( $wordpress['blog_id'] ?? 0 );

	$workspace_type = sanitize_key( (string) ( $workspace['workspace_type'] ?? '' ) );
	$workspace_id   = trim( (string) ( $workspace['workspace_id'] ?? '' ) );
	if ( $blog_id <= 0 || ! extrachill_roadie_pending_action_origin_is_valid( $workspace_type, $workspace_id, $blog_id ) ) {
		// Data Machine fails closed when the claimed origin does not resolve to a site.
		$input['context'] = array( 'wordpress' => array( 'blog_id' => PHP_INT_MAX ) );
		return $input;
	}

	$input['workspace'] = array(
		'workspace_type' => $workspace_type,
		'workspace_id'   => $workspace_id,
	);
	$input['context']   = array( 'wordpress' => array( 'blog_id' => $blog_id ) );

	return $input;
}
add_filter( 'frontend_agent_chat_pending_action_resolve_input', 'extrachill_roadie_frontend_chat_pending_action_resolve_input', 10, 4 );

/**
 * Check that an origin workspace describes a blog on Roadie's current network.
 */
function extrachill_roadie_pending_action_origin_is_valid( string $workspace_type, string $workspace_id, int $blog_id ): bool {
	if ( ! function_exists( 'get_site' ) || ! function_exists( 'get_current_network_id' ) ) {
		return false;
	}

	$site       = get_site( $blog_id );
	$network_id = (int) get_current_network_id();
	if ( ! is_object( $site ) || $network_id <= 0 || (int) ( $site->site_id ?? 0 ) !== $network_id ) {
		return false;
	}

	if ( 'network' === $workspace_type ) {
		return extrachill_roadie_conversation_workspace()['workspace_id'] === $workspace_id;
	}

	if ( 'site' !== $workspace_type || ! function_exists( 'get_home_url' ) ) {
		return false;
	}

	$site_url = untrailingslashit( (string) get_home_url( $blog_id, '/' ) );
	return '' !== $site_url && $site_url === untrailingslashit( $workspace_id );
}

/**
 * Determine whether a Frontend Agent Chat request targets Roadie.
 */
function extrachill_roadie_frontend_chat_targets_roadie( string $agent_slug, array $config ): bool {
	$agent_slug = sanitize_title( $agent_slug );
	if ( '' === $agent_slug ) {
		$agent_slug = sanitize_title( (string) ( $config['agent_slug'] ?? $config['default_agent_slug'] ?? '' ) );
	}

	return '' === $agent_slug || EXTRACHILL_ROADIE_AGENT_SLUG === $agent_slug;
}

/**
 * Resolve Roadie's network-consistent conversation workspace.
 *
 * Data Machine already uses the canonical Agents API workspace value shape.
 * A network workspace lets the same principal discover one Roadie history from
 * every subsite without introducing a Roadie-owned transcript store.
 *
 * @return array{workspace_type:string,workspace_id:string}
 */
function extrachill_roadie_conversation_workspace(): array {
	$network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 1;

	return array(
		'workspace_type' => 'network',
		'workspace_id'   => (string) max( 1, $network_id ),
	);
}

/**
 * Compose the `roadie` mode on top of any modes already present.
 *
 * Preserves explicitly-set modes (so a future caller can request more) while
 * guaranteeing both `chat` (the generic conversational base) and `roadie`
 * (the EC platform context + tools) are active and de-duplicated.
 *
 * @param mixed $existing Existing modes from the chat input (array or scalar).
 * @return array<int,string> Composed mode slugs.
 */
function extrachill_roadie_compose_modes( $existing ): array {
	$modes = array();

	if ( is_array( $existing ) ) {
		foreach ( $existing as $mode ) {
			$slug = sanitize_key( (string) $mode );
			if ( '' !== $slug ) {
				$modes[] = $slug;
			}
		}
	} elseif ( is_string( $existing ) && '' !== $existing ) {
		$slug = sanitize_key( $existing );
		if ( '' !== $slug ) {
			$modes[] = $slug;
		}
	}

	$modes[] = 'chat';
	$modes[] = EXTRACHILL_ROADIE_AGENT_SLUG;

	return array_values( array_unique( $modes ) );
}

/**
 * Populate client context with the real page/site the user is viewing.
 *
 * The frontend widget forwards the current page URL (and title) in the REST
 * body; we also fall back to the request Referer header. The current EC subsite
 * is derived from the WordPress runtime. Data Machine's ClientContextDirective
 * renders each key into the prompt as `- key: value`, giving the agent accurate
 * location awareness instead of guessing.
 *
 * @param array            $client_context Existing transport-level client context.
 * @param \WP_REST_Request $request        REST request.
 * @return array Enriched client context.
 */
function extrachill_roadie_build_client_context( array $client_context, $request ): array {
	$page_url   = '';
	$page_title = '';

	if ( $request instanceof \WP_REST_Request ) {
		$page_url   = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		$page_title = sanitize_text_field( (string) $request->get_param( 'page_title' ) );

		if ( '' === $page_url ) {
			$referer = (string) $request->get_header( 'referer' );
			if ( '' !== $referer ) {
				$page_url = esc_url_raw( $referer );
			}
		}
	}

	if ( '' !== $page_url ) {
		$client_context['page_url'] = $page_url;
	}

	if ( '' !== $page_title ) {
		$client_context['page_title'] = $page_title;
	}

	$site_name = get_bloginfo( 'name' );
	if ( '' !== (string) $site_name ) {
		$client_context['site'] = sanitize_text_field( (string) $site_name );
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( is_string( $site_host ) && '' !== $site_host ) {
		$client_context['site_host'] = $site_host;
	}

	return $client_context;
}

/**
 * Append page-awareness guidance to the client-context directive output.
 *
 * The plumbing above populates `client_context['page_url']` (and `page_title`)
 * with the page the user is actually viewing, and Data Machine's
 * ClientContextDirective renders those as bare `- page url: <value>` lines under
 * a generic "Current Client Context" heading. But nothing tells the agent that
 * those lines ARE the current page — so when a user says "this page" / "what am
 * I looking at" / "file an issue about this," Roadie disclaims ("I can't see
 * what page you're looking at") instead of using the URL sitting in its prompt.
 *
 * This is the EC-scoped, symmetric analog to Data Machine's built-in editor
 * guidance (build_editor_guidance()): a short, high-signal directive block
 * appended to the directive outputs when — and only when — `page_url` is present
 * in client_context. Layer purity: the EC-specific phrasing lives here in
 * extrachill-roadie, not in the generic ClientContextDirective. We hook the
 * `datamachine_client_context_directive_outputs` filter (the cleanest join to
 * the presence of page_url) rather than baking it into the roadie mode block,
 * because the mode block runs even when no page context is present.
 *
 * @since 0.15.0
 *
 * @param array  $outputs        Directive outputs (system_text entries).
 * @param array  $client_context Full client context payload.
 * @return array Outputs, possibly with page-awareness guidance appended.
 */
function extrachill_roadie_page_awareness_guidance( $outputs, $client_context ): array {
	if ( ! is_array( $outputs ) ) {
		return is_array( $outputs ) ? $outputs : array();
	}

	// Guardrail: only assert page awareness when the page URL is actually
	// present. Never tell the agent it can "see the page" on an empty context.
	if ( ! is_array( $client_context ) || empty( $client_context['page_url'] ) ) {
		return $outputs;
	}

	$page_url   = (string) $client_context['page_url'];
	$page_title = isset( $client_context['page_title'] ) ? (string) $client_context['page_title'] : '';

	$lines   = array();
	$lines[] = '## Current Page The User Is Viewing';
	$lines[] = '';
	$lines[] = 'Treat the `page url` (and `page title`) in the client context above as the authoritative page the user is currently viewing in their browser — this is what they mean by "this page", "the page I\'m on", or "what I\'m looking at".';
	$lines[] = 'When the user refers to the current page ("what page am I on?", "file an issue about this page", "the calendar on this page looks broken"), use that `page url` directly — do NOT ask them to paste a URL you already have.';
	$lines[] = 'If you need the page\'s actual contents or layout, call the `inspect_page` tool: it reads the rendered DOM of that `page url` by default, so you can ground page-specific answers and feedback in what is really on screen instead of guessing.';
	$lines[] = 'Only ask the user for a URL if `page url` is missing or they explicitly point you at a different page.';
	$lines[] = '';
	$lines[] = sprintf( '- current page url: %s', $page_url );

	if ( '' !== $page_title ) {
		$lines[] = sprintf( '- current page title: %s', $page_title );
	}

	$guidance = implode( "\n", $lines );

	$outputs[] = array(
		'type'    => 'system_text',
		'content' => $guidance,
	);

	return $outputs;
}
add_filter( 'datamachine_client_context_directive_outputs', 'extrachill_roadie_page_awareness_guidance', 10, 2 );
