<?php
/**
 * WP Codebox inheritance resolver.
 *
 * Hooks `wp_codebox_resolve_inheritance` (shipped in chubes4/wp-codebox#89,
 * the foundation for chubes4/wp-codebox#88) to declaratively wire parent-site
 * connector state into sandbox dispatches.
 *
 * Today's contract (per wp-codebox v0.x):
 *
 * - Roadie's `propose_code_change` tool passes `inherit.connectors = ['openai']`.
 * - WP Codebox calls this filter with the request + ability input.
 * - We return a resolution structure with provider/model/secretEnv NAMES per
 *   connector. WP Codebox merges those secretEnv names into the sandbox's
 *   secret_env passthrough and uses provider/model as defaults.
 * - The actual credential VALUE must already be in the host PHP process env
 *   at dispatch time. WP Codebox does not accept secret values through this
 *   filter (chubes4/wp-codebox#89 description: "secret values and setting
 *   values are not serialized into recipe JSON, artifact metadata, logs, or
 *   patches by this transport slice").
 *
 * For OpenAI: the production key lives in the `connectors_ai_openai_api_key`
 * option (WordPress connectors API). We bridge it into the PHP process env
 * as `OPENAI_API_KEY` right here so wp-codebox's secret_env mechanism can
 * carry the NAME into the sandbox, where php-ai-client's ProviderRegistry
 * reads it via getenv().
 *
 * When the upstream contract grows (filter-returned values, agent identity
 * inheritance, pre-plugins_loaded hydration), this bridge becomes obsolete
 * and the filter return shrinks to pure metadata.
 *
 * @package ExtraChillRoadie\ContributeCode
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default connector → metadata map.
 *
 * Each entry describes:
 *   - provider:     wp-ai-client provider id
 *   - model:        default model the sandbox agent should use
 *   - secret_env:   array of env var names the sandbox needs
 *   - option_key:   parent option holding the credential value
 *   - env_var:      env var name to putenv() (must appear in secret_env)
 *
 * Override via the `extrachill_roadie_inherit_connectors` filter.
 *
 * @since 0.7.0
 * @return array<string, array<string,mixed>>
 */
function extrachill_roadie_default_connector_map(): array {
	$defaults = array(
		'openai' => array(
			'provider'   => 'openai',
			'model'      => 'gpt-5',
			'secret_env' => array( 'OPENAI_API_KEY' ),
			'option_key' => 'connectors_ai_openai_api_key',
			'env_var'    => 'OPENAI_API_KEY',
		),
	);

	/**
	 * Filter the connector→metadata map used to resolve inheritance requests.
	 *
	 * @since 0.7.0
	 *
	 * @param array $defaults Default map.
	 */
	$filtered = apply_filters( 'extrachill_roadie_inherit_connectors', $defaults );

	if ( ! is_array( $filtered ) ) {
		return $defaults;
	}

	return $filtered;
}

/**
 * Read a connector credential value from where it actually lives on the
 * parent site.
 *
 * Today: site option store. As stores change (e.g. encrypted secrets,
 * per-user OAuth) this is the only function that needs updating.
 *
 * @since 0.7.0
 *
 * @param string $option_key Option name holding the credential.
 * @return string Credential value, or empty string if absent.
 */
function extrachill_roadie_read_connector_value( string $option_key ): string {
	if ( '' === $option_key || ! function_exists( 'get_option' ) ) {
		return '';
	}
	$value = get_option( $option_key, '' );
	return is_string( $value ) ? trim( $value ) : '';
}

/**
 * Filter callback for `wp_codebox_resolve_inheritance`.
 *
 * Receives the pre-populated $resolution (one entry per requested name with
 * status='unresolved') and walks the connector entries, filling in metadata
 * for any name we know about. Side-effect: for each resolved connector with
 * an `option_key`, also calls putenv() to expose the credential value to
 * the host PHP process so wp-codebox's secret_env passthrough finds it.
 *
 * @since 0.7.0
 *
 * @param array $resolution Pre-populated resolution structure.
 * @param array $request    Requested inheritance (connectors[], settings[]).
 * @param array $input      Full ability input (unused here).
 * @return array Mutated resolution structure.
 */
function extrachill_roadie_resolve_inheritance( $resolution, $request, $input ): array {
	unset( $input );

	if ( ! is_array( $resolution ) ) {
		$resolution = array( 'connectors' => array(), 'settings' => array() );
	}

	$connector_map = extrachill_roadie_default_connector_map();
	$connectors    = is_array( $resolution['connectors'] ?? null ) ? $resolution['connectors'] : array();

	foreach ( $connectors as $idx => $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$name = (string) ( $entry['name'] ?? '' );
		if ( '' === $name || ! isset( $connector_map[ $name ] ) ) {
			continue;
		}

		$meta  = $connector_map[ $name ];
		$value = '';
		if ( ! empty( $meta['option_key'] ) ) {
			$value = extrachill_roadie_read_connector_value( (string) $meta['option_key'] );
		}

		if ( '' === $value ) {
			$connectors[ $idx ] = array(
				'name'   => $name,
				'status' => 'missing-credential',
			);
			continue;
		}

		// Bridge: export the value into the host PHP process env under the
		// configured env var name. WP Codebox's secret_env will inherit it.
		if ( ! empty( $meta['env_var'] ) ) {
			putenv( $meta['env_var'] . '=' . $value );
		}

		$connectors[ $idx ] = array(
			'name'      => $name,
			'status'    => 'resolved',
			'provider'  => (string) ( $meta['provider'] ?? '' ),
			'model'     => (string) ( $meta['model'] ?? '' ),
			'secretEnv' => array_values( (array) ( $meta['secret_env'] ?? array() ) ),
		);
	}

	$resolution['connectors'] = $connectors;
	return $resolution;
}
add_filter( 'wp_codebox_resolve_inheritance', 'extrachill_roadie_resolve_inheritance', 10, 3 );
