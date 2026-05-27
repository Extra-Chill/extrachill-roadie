<?php
/**
 * Subsite context detection for the sandbox-backed contribute-code flow.
 *
 * Pure function that, given the current blog, returns a normalized snapshot
 * of which theme + plugins are uniquely meaningful to this subsite. The
 * recipe builder consumes this to decide which directories to mount into a
 * WP Codebox sandbox.
 *
 * Network-active boilerplate plugins (the platform-wide stack) are filtered
 * out by default — those mount in via the agent stack section of the recipe
 * as read-only references, not as the subsite's editable surface.
 *
 * @package ExtraChillRoadie\ContributeCode
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default list of plugin slugs to treat as platform-wide boilerplate and
 * exclude from the subsite-specific active-plugins list.
 *
 * These plugins are network-activated and not what a contributor would
 * typically be editing when chatting with roadie about "this site." They
 * mount into the sandbox separately as the agent stack.
 *
 * @since 0.7.0
 * @return string[]
 */
function extrachill_roadie_default_excluded_plugin_slugs(): array {
	$defaults = array(
		// Platform-wide network plugins.
		'breeze',
		'imagify',
		'extrachill-multisite',
		'extrachill-search',
		'extrachill-users',
		'extrachill-admin-tools',
		'extrachill-api',
		'extrachill-newsletter',
		'extrachill-seo',
		'extrachill-analytics',
		'extrachill-cli',
		'chubes-gallery-lightbox',
		'redis-cache',
		'easy-wp-smtp',
		'two-factor',
		'plugin-check',
		'html-to-blocks-converter',
		'gutenberg',
		// The roadie plugin itself.
		'extrachill-roadie',
		// Data Machine + AI substrate.
		'data-machine',
		'data-machine-business',
		'data-machine-chat-bridge',
		'data-machine-code',
		'data-machine-editor',
		'data-machine-events',
		'data-machine-skills',
		'data-machine-socials',
		'ai-provider-for-openai',
		'ai-provider-for-anthropic',
		'agents-api',
		'wp-native',
		'frontend-agent-chat',
	);

	/**
	 * Filter the list of plugin slugs excluded from subsite-context detection.
	 *
	 * @since 0.7.0
	 *
	 * @param string[] $defaults Default exclusion list.
	 */
	$filtered = apply_filters( 'extrachill_roadie_excluded_plugin_slugs', $defaults );

	if ( ! is_array( $filtered ) ) {
		return $defaults;
	}

	return array_values( array_unique( array_map( 'strval', $filtered ) ) );
}

/**
 * Extract the plugin slug (top-level directory) from a plugin file path.
 *
 * WordPress identifies plugins by `slug/main-file.php` strings. For
 * single-file plugins the slug is the filename without `.php`.
 *
 * @since 0.7.0
 *
 * @param string $plugin_file Plugin file as returned by `get_option('active_plugins')`.
 * @return string Slug, never empty unless input was empty.
 */
function extrachill_roadie_plugin_file_to_slug( string $plugin_file ): string {
	$plugin_file = trim( $plugin_file );

	if ( '' === $plugin_file ) {
		return '';
	}

	if ( false !== strpos( $plugin_file, '/' ) ) {
		$parts = explode( '/', $plugin_file );
		return (string) $parts[0];
	}

	// Single-file plugin: strip .php extension.
	return preg_replace( '/\.php$/', '', $plugin_file );
}

/**
 * Detect the current subsite's contribution context.
 *
 * Pure-ish: reads from WordPress globals/options but performs no writes
 * and no network calls. Safe to call from a chat tool handler.
 *
 * Returned shape:
 *   array(
 *     'blog_id'        => int,
 *     'site_url'       => string,
 *     'theme'          => array(
 *         'slug'       => string,  // stylesheet slug, e.g. 'extrachill'
 *         'parent_slug'=> string,  // empty when not a child theme
 *         'path'       => string,  // absolute path on host
 *         'name'       => string,  // human-readable theme name
 *     ),
 *     'plugins'        => array(   // subsite-specific, excluded list applied
 *         array(
 *             'slug'   => string,
 *             'file'   => string,  // 'slug/main.php'
 *             'path'   => string,  // absolute path to plugin directory on host
 *             'name'   => string,
 *         ),
 *         ...
 *     ),
 *     'excluded_slugs' => string[],
 *   )
 *
 * @since 0.7.0
 *
 * @param int|null $blog_id Optional blog id; defaults to current.
 * @return array<string,mixed>
 */
function extrachill_roadie_detect_subsite_context( ?int $blog_id = null ): array {
	$blog_id = $blog_id ?: (int) get_current_blog_id();

	$switched = false;
	if ( function_exists( 'switch_to_blog' ) && $blog_id !== (int) get_current_blog_id() ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$theme         = wp_get_theme();
	$stylesheet    = $theme->get_stylesheet();
	$theme_path    = (string) $theme->get_stylesheet_directory();
	$parent_slug   = '';
	$parent        = $theme->parent();
	if ( $parent ) {
		$parent_slug = (string) $parent->get_stylesheet();
	}

	$excluded     = extrachill_roadie_default_excluded_plugin_slugs();
	$excluded_map = array_flip( $excluded );

	$active_plugins = array();

	$site_actives = (array) get_option( 'active_plugins', array() );

	// Subsite-specific actives only — network-active plugins are intentionally
	// excluded because they are platform-wide boilerplate that ships in the
	// agent stack, not the subsite's editable surface.
	foreach ( $site_actives as $plugin_file ) {
		$slug = extrachill_roadie_plugin_file_to_slug( (string) $plugin_file );
		if ( '' === $slug ) {
			continue;
		}
		if ( isset( $excluded_map[ $slug ] ) ) {
			continue;
		}

		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';
		$path       = $plugin_dir . '/' . $slug;
		$data       = array(
			'slug' => $slug,
			'file' => (string) $plugin_file,
			'path' => $path,
			'name' => $slug,
		);

		if ( function_exists( 'get_plugin_data' ) ) {
			$full = $plugin_dir . '/' . $plugin_file;
			if ( file_exists( $full ) ) {
				$meta = get_plugin_data( $full, false, false );
				if ( ! empty( $meta['Name'] ) ) {
					$data['name'] = (string) $meta['Name'];
				}
			}
		}

		$active_plugins[] = $data;
	}

	$site_url = function_exists( 'home_url' ) ? (string) home_url() : '';

	if ( $switched && function_exists( 'restore_current_blog' ) ) {
		restore_current_blog();
	}

	$context = array(
		'blog_id'        => $blog_id,
		'site_url'       => $site_url,
		'theme'          => array(
			'slug'        => (string) $stylesheet,
			'parent_slug' => $parent_slug,
			'path'        => $theme_path,
			'name'        => (string) $theme->get( 'Name' ),
		),
		'plugins'        => $active_plugins,
		'excluded_slugs' => $excluded,
	);

	/**
	 * Filter the detected subsite context before it is returned.
	 *
	 * Useful for tests or for surgical adjustments on environments where the
	 * default exclusion list doesn't quite line up.
	 *
	 * @since 0.7.0
	 *
	 * @param array $context  Detected context.
	 * @param int   $blog_id  Blog id the context was detected for.
	 */
	return (array) apply_filters( 'extrachill_roadie_subsite_context', $context, $blog_id );
}
