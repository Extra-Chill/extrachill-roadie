<?php
/**
 * Recipe builder for the sandbox-backed contribute-code flow.
 *
 * Translates a detected subsite context into the `mounts` array accepted by
 * `wp-codebox/run-agent-task`.
 *
 * Key design choices:
 *
 * - Mount sources point at `/var/lib/datamachine/workspace/<repo>` (the DMC
 *   primary clone), NOT at `/var/www/.../wp-content/plugins/<slug>`. The
 *   production install is read-only reference; all sandbox-edited code is
 *   sourced from workspace clones so the apply-back path has a real git
 *   tree to commit against.
 *
 * - `metadata.baselineSource` is set on every readwrite mount so
 *   wp-codebox's `captureMountDiffs()` emits a real `patch.diff` artifact.
 *
 * - The agent stack (agents-api, data-machine, data-machine-code, AI
 *   providers) is NOT included here. WP Codebox already auto-mounts it via
 *   the `wp_codebox_component_paths` network option / `extraPlugins` recipe
 *   branch. Including them here would double-mount.
 *
 * Per chubes4/wp-codebox#82 the recipe builder lives in the consumer (us).
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
 * Default DMC workspace root on disk.
 *
 * Override via the `extrachill_roadie_workspace_root` filter.
 *
 * @since 0.7.0
 * @return string
 */
function extrachill_roadie_workspace_root(): string {
	$default = '/var/lib/datamachine/workspace';

	/**
	 * Filter the DMC workspace root used for mount sources.
	 *
	 * @since 0.7.0
	 *
	 * @param string $default Default root path.
	 */
	return (string) apply_filters( 'extrachill_roadie_workspace_root', $default );
}

/**
 * Resolve the on-disk workspace clone path for a repo slug.
 *
 * Convention: DMC clones each repo to `<workspace_root>/<repo>`. We use the
 * primary clone (no `@<branch>` suffix) as the mount source. Apply-back
 * tooling creates per-task worktrees from the artifact patch separately.
 *
 * @since 0.7.0
 *
 * @param string $slug Component slug.
 * @return string Absolute path; not guaranteed to exist.
 */
function extrachill_roadie_workspace_clone_path( string $slug ): string {
	$slug = trim( $slug );
	if ( '' === $slug ) {
		return '';
	}
	return extrachill_roadie_workspace_root() . '/' . $slug;
}

/**
 * Build a recipe (the `mounts` array) for `wp-codebox/run-agent-task`.
 *
 * Input:
 *   - $context  — output of extrachill_roadie_detect_subsite_context()
 *   - $repo_map — output of extrachill_roadie_default_repo_map() (or override)
 *   - $args     — optional overrides:
 *       * 'wp_content_target' (string) sandbox WP content root
 *       * 'workspace_root'    (string) override workspace root for this call
 *       * 'require_clone'     (bool)   if true (default), skip mounts whose
 *                                      workspace clone doesn't exist on disk
 *                                      and track them in `missing_clones`
 *
 * Output:
 *   array(
 *     'mounts'                  => array<int, array{source,target,mode,metadata}>,
 *     'editable_targets'        => array<string,string>,  // slug => sandbox target
 *     'unmapped_active_plugins' => string[],              // slugs without repo entries
 *     'missing_clones'          => string[],              // slugs whose clone is absent
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
	$wp_content_target = (string) ( $args['wp_content_target'] ?? EXTRACHILL_ROADIE_SANDBOX_WP_CONTENT );
	$workspace_root    = (string) ( $args['workspace_root'] ?? extrachill_roadie_workspace_root() );
	$require_clone     = (bool) ( $args['require_clone'] ?? true );

	$mounts           = array();
	$editable_targets = array();
	$unmapped         = array();
	$missing_clones   = array();

	$build_mount = static function ( string $kind, string $slug, array $entry, string $sandbox_target ) use ( $workspace_root, $require_clone, &$missing_clones ): ?array {
		$source = $workspace_root . '/' . $slug;
		if ( $require_clone && ! is_dir( $source ) ) {
			$missing_clones[] = $slug;
			return null;
		}

		return array(
			'source'   => $source,
			'target'   => $sandbox_target,
			'mode'     => 'readwrite',
			'metadata' => array(
				'kind'                        => $kind,
				'slug'                        => $slug,
				'repo'                        => (string) ( $entry['repo'] ?? '' ),
				'default_branch'              => (string) ( $entry['default_branch'] ?? 'main' ),
				'repo_root_relative_to_mount' => (string) ( $entry['repo_root_relative_to_mount'] ?? '' ),
				// captureMountDiffs() in wp-codebox only emits a patch when
				// metadata.baselineSource is set on a readwrite mount.
				'baselineSource'              => $source,
				'editable'                    => true,
			),
		);
	};

	// 1. Theme mount (readwrite if mapped + clone exists).
	$theme_slug = (string) ( $context['theme']['slug'] ?? '' );
	if ( '' !== $theme_slug ) {
		$entry = $repo_map[ $theme_slug ] ?? null;
		if ( $entry && ( $entry['kind'] ?? '' ) === 'theme' ) {
			$target = $wp_content_target . '/themes/' . $theme_slug;
			$mount  = $build_mount( 'theme', $theme_slug, $entry, $target );
			if ( $mount ) {
				$mounts[]                       = $mount;
				$editable_targets[ $theme_slug ] = $target;
			}
		} else {
			$unmapped[] = $theme_slug;
		}
	}

	// 2. Subsite-specific plugin mounts (readwrite when mapped + clone exists).
	foreach ( (array) ( $context['plugins'] ?? array() ) as $plugin ) {
		$slug = (string) ( $plugin['slug'] ?? '' );
		if ( '' === $slug ) {
			continue;
		}

		$entry = $repo_map[ $slug ] ?? null;
		if ( ! $entry ) {
			$unmapped[] = $slug;
			continue;
		}

		$kind = (string) ( $entry['kind'] ?? 'plugin' );
		// Agent-stack plugins are auto-mounted by wp-codebox via component
		// paths — never include them in our recipe.
		if ( 'agent-stack-plugin' === $kind ) {
			continue;
		}
		// Platform-wide plugins are issue-trackable + code-trackable, but
		// not editable from a subsite-context recipe. The subsite-context
		// detector already excludes them from $context['plugins'], so this
		// guard is belt-and-suspenders against future exclusion-list drift.
		if ( 'platform-plugin' === $kind ) {
			continue;
		}

		$target = $wp_content_target . '/plugins/' . $slug;
		$mount  = $build_mount( $kind, $slug, $entry, $target );
		if ( $mount ) {
			$mounts[]                  = $mount;
			$editable_targets[ $slug ] = $target;
		}
	}

	$recipe = array(
		'mounts'                  => $mounts,
		'editable_targets'        => $editable_targets,
		'unmapped_active_plugins' => array_values( array_unique( $unmapped ) ),
		'missing_clones'          => array_values( array_unique( $missing_clones ) ),
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
