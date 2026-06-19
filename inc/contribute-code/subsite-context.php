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
		'extrachill-tokens',
		'extrachill-components',
		'chubes-gallery-lightbox',
		'redis-cache',
		'easy-wp-smtp',
		'two-factor',
		'plugin-check',
		'html-to-blocks-converter',
		'gutenberg',
		// The roadie plugin itself.
		'extrachill-roadie',
		// Data Machine + AI substrate (read-only agent infrastructure).
		//
		// NOTE: data-machine-events is deliberately NOT excluded. It is a
		// front-end-rendering Data Machine EXTENSION (the events Calendar +
		// EventsMap blocks) that the events subsite actively runs — a
		// subsite-editable surface like extrachill-events, not agent infra.
		// Excluding it is exactly the bug extrachill-roadie#57 fixed: it hid
		// the calendar/map source from both repo inference and inspect_code.
		// data-machine-socials stays excluded — it renders no front-end
		// surface, so it's backend platform infra (mapped as platform-plugin
		// in repo-map.php for issue-filing, but not a subsite editable surface).
		'data-machine',
		'data-machine-business',
		'data-machine-chat-bridge',
		'data-machine-code',
		'data-machine-editor',
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
	$blog_id = ( $blog_id > 0 ) ? $blog_id : (int) get_current_blog_id();

	$switched = false;
	if ( function_exists( 'switch_to_blog' ) && (int) get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$theme       = wp_get_theme();
	$stylesheet  = $theme->get_stylesheet();
	$theme_path  = (string) $theme->get_stylesheet_directory();
	$parent_slug = '';
	$parent      = $theme->parent();
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

/**
 * Resolve the blog id that owns a given front-end page URL.
 *
 * This is the page-aware complement to plain `get_current_blog_id()` repo
 * inference. On the MAIN site (blog 1) the surviving subsite-specific surface
 * spans several repos (theme, blog, seo, admin-tools), so "which blog is the
 * REST request running on" is the wrong question — the request runs on
 * whichever subsite the chat widget POSTs to, not necessarily the subsite the
 * user actually had open. The page the user was viewing (page_url) is the
 * disambiguating signal: a URL like `https://events.extrachill.com/calendar`
 * unambiguously names the events subsite even when the chat turn executes on
 * blog 1.
 *
 * Resolution is entirely WordPress-multisite-native and registry-free: the
 * host (and path, for subdirectory networks) are matched against the network's
 * registered sites via core `get_blog_id_from_url()`. There is NO plugin-name
 * or brand-name special-casing here — page → blog is pure network topology,
 * blog → repos stays driven by the slug-to-repo registry downstream. Adding a
 * new subsite to the network is sufficient; no inference-code change is needed.
 *
 * @since 0.15.0
 *
 * @param string $page_url Front-end URL the user was viewing (from client context).
 * @return int Resolved blog id, or 0 when the URL is empty, unparseable, or
 *             does not belong to a registered network site.
 */
function extrachill_roadie_blog_id_from_page_url( string $page_url ): int {
	$page_url = trim( $page_url );
	if ( '' === $page_url ) {
		return 0;
	}

	if ( ! function_exists( 'wp_parse_url' ) || ! function_exists( 'get_blog_id_from_url' ) ) {
		return 0;
	}

	$parts = wp_parse_url( $page_url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return 0;
	}

	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return 0;
	}

	$host = strtolower( (string) $parts['host'] );

	// Path matters only on subdirectory multisite networks. For subdomain
	// networks (the EC topology) the path is always '/'. Normalize to a
	// trailing-slashed path so get_blog_id_from_url() matches the site row,
	// which stores paths with a trailing slash.
	$path = (string) ( $parts['path'] ?? '/' );
	$path = '' === $path ? '/' : $path;
	if ( '/' !== substr( $path, -1 ) ) {
		$path .= '/';
	}

	$blog_id = (int) get_blog_id_from_url( $host, $path );

	// Subdirectory networks: a deep path (e.g. /events/calendar/) won't match
	// the site row (path '/events/') on the first try. Walk the path down to
	// its first segment so a deep URL still resolves to its owning subsite.
	if ( 0 === $blog_id && '/' !== $path ) {
		$segments = array_values( array_filter( explode( '/', $path ) ) );
		while ( ! empty( $segments ) && 0 === $blog_id ) {
			$candidate = '/' . implode( '/', $segments ) . '/';
			$blog_id   = (int) get_blog_id_from_url( $host, $candidate );
			array_pop( $segments );
		}
		if ( 0 === $blog_id ) {
			$blog_id = (int) get_blog_id_from_url( $host, '/' );
		}
	}

	return $blog_id;
}
