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
function extrachill_roadie_frontend_chat_input( $chat_input, $request, string $agent_slug, array $config ): array {
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
