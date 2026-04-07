<?php
/**
 * Asset enqueuing — token bridge stylesheet.
 *
 * Loads the EC → DM token bridge CSS on frontend pages so the
 * Data Machine chat widget inherits Extra Chill theme styling.
 *
 * @package ExtraChillRoadie
 * @since 0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the token bridge stylesheet on the frontend.
 *
 * @since 0.5.0
 * @return void
 */
function extrachill_roadie_enqueue_assets(): void {
	if ( is_admin() ) {
		return;
	}

	$css_file = EXTRACHILL_ROADIE_PLUGIN_DIR . 'assets/css/token-bridge.css';

	if ( ! file_exists( $css_file ) ) {
		return;
	}

	wp_enqueue_style(
		'extrachill-roadie-token-bridge',
		plugins_url( 'assets/css/token-bridge.css', EXTRACHILL_ROADIE_PLUGIN_FILE ),
		array(),
		filemtime( $css_file )
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_roadie_enqueue_assets' );
