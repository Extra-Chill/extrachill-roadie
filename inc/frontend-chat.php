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
