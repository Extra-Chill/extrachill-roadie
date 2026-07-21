<?php
/**
 * Plugin Name: Roadie Multisite E2E Fixture
 * Description: Test-only site resolution, pending-action handler, and browser evidence page.
 * Version: 1.0.0
 * Network: true
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $key ) {
		$sites = get_site_option( 'roadie_e2e_sites', array() );
		return isset( $sites[ $key ] ) ? (int) $sites[ $key ] : null;
	}
}

if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url( $key ) {
		$blog_id = ec_get_blog_id( $key );
		return $blog_id ? untrailingslashit( get_home_url( $blog_id, '/' ) ) : null;
	}
}

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ): array {
		$handlers['roadie_e2e_origin'] = array(
			'can_resolve' => '__return_true',
			'apply'       => static function ( array $input ): array {
				update_option( 'roadie_e2e_resolved_action', $input, false );
				return array(
					'success' => true,
					'blog_id' => get_current_blog_id(),
				);
			},
		);
		return $handlers;
	}
);

add_action(
	'template_redirect',
	static function (): void {
		if ( '/roadie-e2e/' !== wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) {
			return;
		}

		$sites    = get_site_option( 'roadie_e2e_sites', array() );
		$site_key = array_search( get_current_blog_id(), $sites, true );
		$site_key = is_string( $site_key ) ? $site_key : 'unknown';
		$status   = is_user_logged_in() ? 'authenticated' : 'anonymous';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>Roadie E2E</title></head><body>';
		echo '<main id="roadie-e2e-' . esc_attr( $site_key ) . '">';
		echo '<h1>Roadie ' . esc_html( ucfirst( $site_key ) ) . ' Fixture</h1>';
		echo '<p id="roadie-e2e-' . esc_attr( $status ) . '">' . esc_html( $status ) . '</p>';
		echo '</main></body></html>';
		exit;
	}
);
