<?php
/** Capability discovery for Roadie's artist-owned tools. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the owner abilities required by an artist-facing tool.
 *
 * @param string $tool_name Roadie tool slug.
 * @return array{local:string[],artist:string[]}
 */
function extrachill_roadie_owner_tool_abilities( string $tool_name ): array {
	$requirements = array(
		'manage_artist_profile' => array(
			'local'  => array( 'extrachill/get-user-artists' ),
			'artist' => array( 'extrachill/get-artist-data', 'extrachill/create-artist', 'extrachill/update-artist' ),
		),
		'manage_link_page'      => array(
			'local'  => array( 'extrachill/get-user-artists' ),
			'artist' => array( 'extrachill/get-link-page-data', 'extrachill/save-link-page-links', 'extrachill/save-social-links', 'extrachill/save-link-page-styles', 'extrachill/save-link-page-settings' ),
		),
	);

	return $requirements[ $tool_name ] ?? array(
		'local'  => array(),
		'artist' => array(),
	);
}

/**
 * Extract valid Artist ability names from the core collection response.
 *
 * @param mixed $response Abilities REST collection response.
 * @return string[]
 */
function extrachill_roadie_index_artist_abilities( $response ): array {
	if ( ! is_array( $response ) ) {
		return array();
	}

	$names = array();
	foreach ( $response as $ability ) {
		if ( ! is_array( $ability ) || 'extrachill-artist-platform' !== ( $ability['category'] ?? null ) || ! is_string( $ability['name'] ?? null ) ) {
			continue;
		}
		$names[] = $ability['name'];
	}

	return array_values( array_unique( $names ) );
}

/**
 * Discover the Artist site's REST-exposed ability names once per request.
 *
 * @return string[]
 */
function extrachill_roadie_discover_artist_abilities(): array {
	static $abilities = null;

	if ( null !== $abilities ) {
		return $abilities;
	}

	$abilities = array();
	if ( ! function_exists( 'ec_cross_site_rest_request' ) || get_current_user_id() <= 0 ) {
		return $abilities;
	}

	$response = ec_cross_site_rest_request(
		'artist',
		'GET',
		'/wp-abilities/v1/abilities',
		array(
			'query'   => array(
				'category' => 'extrachill-artist-platform',
				'per_page' => 100,
			),
			'user_id' => get_current_user_id(),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $abilities;
	}

	$abilities = extrachill_roadie_index_artist_abilities( $response );
	return $abilities;
}

/**
 * Confirm all owner abilities required by a Roadie tool are discoverable.
 */
function extrachill_roadie_owner_capabilities_available( string $tool_name ): bool {
	$required = extrachill_roadie_owner_tool_abilities( $tool_name );
	if ( empty( $required['local'] ) || empty( $required['artist'] ) || ! function_exists( 'wp_get_ability' ) ) {
		return false;
	}

	foreach ( $required['local'] as $ability_name ) {
		$ability = wp_get_ability( $ability_name );
		if ( ! $ability ) {
			return false;
		}
	}

	return array() === array_diff( $required['artist'], extrachill_roadie_discover_artist_abilities() );
}

/** Remove owner-backed tools from the resolved registry when dependencies are unavailable. */
function extrachill_roadie_filter_owner_tools( array $tools ): array {
	foreach ( array( 'manage_artist_profile', 'manage_link_page' ) as $tool_name ) {
		if ( isset( $tools[ $tool_name ] ) && ! extrachill_roadie_owner_capabilities_available( $tool_name ) ) {
			unset( $tools[ $tool_name ] );
		}
	}

	return $tools;
}
add_filter( 'datamachine_tools', 'extrachill_roadie_filter_owner_tools', 20 );

/** Force target-site bootstrap for Artist-owned Abilities REST routes. */
function extrachill_roadie_artist_abilities_use_http_loopback( bool $use_http, string $site_key, string $method, string $path, array $args ): bool {
	unset( $method, $args );

	if ( $use_http || 'artist' !== $site_key ) {
		return $use_http;
	}

	return 0 === strpos( $path, '/wp-abilities/v1/abilities' );
}
add_filter( 'ec_cross_site_use_http_loopback', 'extrachill_roadie_artist_abilities_use_http_loopback', 10, 5 );
