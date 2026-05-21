<?php
/**
 * Recipe builder for the sandbox-backed contribute-code flow.
 *
 * Translates a detected subsite context (active theme + subsite-specific
 * plugins) plus the slug→repo map into the `mounts` array accepted by
 * `wp-codebox/run-agent-task`.
 *
 * Per chubes4/wp-codebox#82 the recipe builder lives in the consumer (us),
 * not in wp-codebox. Each mount declares source/target/mode and carries
 * `metadata` for downstream tools that need to push back to the correct
 * GitHub repo.
 *
 * @package ExtraChillRoadie\ContributeCode
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default in-sandbox WordPress content root.
 *
 * WP Codebox mounts host directories under `/wordpress/wp-content/...` in
 * the Playground filesystem.
 */
const EXTRACHILL_ROADIE_SANDBOX_WP_CONTENT = '/wordpress/wp-content';

/**
 * Build a recipe (the `mounts` array) for `wp-codebox/run-agent-task`.
 *
 * Input:
 *   - $context  — output of extrachill_roadie_detect_subsite_context()
 *   - $repo_map — output of extrachill_roadie_default_repo_map() (or override)
 *   - $args     — optional overrides:
 *       * 'wp_content_target' (string) sandbox WP content root
 *       * 'include_agent_stack' (bool)  default true
 *       * 'agent_stack_plugin_paths' (array<slug,path>) host paths for the
 *         agent stack — pulled from /var/www/extrachill.com/wp-content/plugins/
 *         when omitted
 *
 * Output:
 *   array(
 *     'mounts'                  => array<int, array{source,target,mode,metadata}>,
 *     'editable_targets'        => array<string,string>, // slug => sandbox target
 *     'agent_stack_targets'     => array<string,string>, // slug => sandbox target
 *     'unmapped_active_plugins' => string[],             // slugs without repo entries
 *   )
 *
 * @since 0.7.0
 *
 * @param array $context  Subsite context.
 * @param array $repo_map Slug→repo map.
 * @param array $args     Optional overrides.
 * @return array<string,mixed>
 */
function extrachill_roadie_build_recipe( array $context, array $repo_map, array $args = array() ): array {
	$wp_content_target   = (string) ( $args['wp_content_target'] ?? EXTRACHILL_ROADIE_SANDBOX_WP_CONTENT );
	$include_agent_stack = (bool) ( $args['include_agent_stack'] ?? true );
	$agent_stack_paths   = (array) ( $args['agent_stack_plugin_paths'] ?? array() );

	$mounts              = array();
	$editable_targets    = array();
	$agent_stack_targets = array();
	$unmapped            = array();

	// 1. Theme mount (readwrite if it has a repo entry, otherwise skip).
	$theme_slug = (string) ( $context['theme']['slug'] ?? '' );
	$theme_path = (string) ( $context['theme']['path'] ?? '' );
	if ( '' !== $theme_slug && '' !== $theme_path ) {
		$entry = $repo_map[ $theme_slug ] ?? null;
		if ( $entry && ( $entry['kind'] ?? '' ) === 'theme' ) {
			$target          = $wp_content_target . '/themes/' . $theme_slug;
			$mounts[]        = array(
				'source'   => $theme_path,
				'target'   => $target,
				'mode'     => 'readwrite',
				'metadata' => array(
					'kind'                          => 'theme',
					'slug'                          => $theme_slug,
					'repo'                          => (string) ( $entry['repo'] ?? '' ),
					'default_branch'                => (string) ( $entry['default_branch'] ?? 'main' ),
					'repo_root_relative_to_mount'   => (string) ( $entry['repo_root_relative_to_mount'] ?? '' ),
				),
			);
			$editable_targets[ $theme_slug ] = $target;
		} else {
			$unmapped[] = $theme_slug;
		}
	}

	// 2. Subsite-specific plugin mounts (readwrite when mapped).
	foreach ( (array) ( $context['plugins'] ?? array() ) as $plugin ) {
		$slug = (string) ( $plugin['slug'] ?? '' );
		$path = (string) ( $plugin['path'] ?? '' );
		if ( '' === $slug || '' === $path ) {
			continue;
		}

		$entry = $repo_map[ $slug ] ?? null;
		if ( ! $entry ) {
			$unmapped[] = $slug;
			continue;
		}

		$kind = (string) ( $entry['kind'] ?? 'plugin' );
		if ( 'agent-stack-plugin' === $kind ) {
			// Active on the subsite AND classified as agent stack — treat as
			// the agent stack branch below to keep it read-only.
			continue;
		}

		$target = $wp_content_target . '/plugins/' . $slug;
		$mounts[] = array(
			'source'   => $path,
			'target'   => $target,
			'mode'     => 'readwrite',
			'metadata' => array(
				'kind'                          => $kind,
				'slug'                          => $slug,
				'repo'                          => (string) ( $entry['repo'] ?? '' ),
				'default_branch'                => (string) ( $entry['default_branch'] ?? 'main' ),
				'repo_root_relative_to_mount'   => (string) ( $entry['repo_root_relative_to_mount'] ?? '' ),
			),
		);
		$editable_targets[ $slug ] = $target;
	}

	// 3. Agent stack — read-only references. Sandboxed agent needs these to
	// grep DM core, DMC, AI providers, agents-api when implementing changes.
	if ( $include_agent_stack ) {
		$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';
		foreach ( extrachill_roadie_agent_stack_slugs() as $slug ) {
			$entry = $repo_map[ $slug ] ?? null;
			if ( ! $entry ) {
				continue;
			}

			$host_path = $agent_stack_paths[ $slug ] ?? ( $plugin_dir . '/' . $slug );
			$target    = $wp_content_target . '/plugins/' . $slug;

			$mounts[] = array(
				'source'   => $host_path,
				'target'   => $target,
				'mode'     => 'readonly',
				'metadata' => array(
					'kind'                          => 'agent-stack-plugin',
					'slug'                          => $slug,
					'repo'                          => (string) ( $entry['repo'] ?? '' ),
					'default_branch'                => (string) ( $entry['default_branch'] ?? 'main' ),
					'repo_root_relative_to_mount'   => (string) ( $entry['repo_root_relative_to_mount'] ?? '' ),
				),
			);
			$agent_stack_targets[ $slug ] = $target;
		}
	}

	$recipe = array(
		'mounts'                  => $mounts,
		'editable_targets'        => $editable_targets,
		'agent_stack_targets'     => $agent_stack_targets,
		'unmapped_active_plugins' => array_values( array_unique( $unmapped ) ),
	);

	/**
	 * Filter the built recipe.
	 *
	 * @since 0.7.0
	 *
	 * @param array $recipe   Recipe array.
	 * @param array $context  Source subsite context.
	 * @param array $repo_map Repo map used to build it.
	 * @param array $args     Builder args.
	 */
	return (array) apply_filters( 'extrachill_roadie_recipe', $recipe, $context, $repo_map, $args );
}
