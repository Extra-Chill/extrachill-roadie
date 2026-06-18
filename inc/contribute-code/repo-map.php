<?php
/**
 * Slug → GitHub repo map for the sandbox contribute-code flow.
 *
 * The recipe builder uses this map to attach `metadata.repo` and
 * `metadata.default_branch` to each mount so the sandboxed agent can push
 * to the correct repository when it opens its PR.
 *
 * This is consumer-owned (Extra Chill) per the deployment-neutral scope of
 * chubes4/wp-codebox#82. Override entries with the
 * `extrachill_roadie_repo_map` filter.
 *
 * @package ExtraChillRoadie\ContributeCode
 * @since 0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default slug-to-repo mapping for the Extra Chill stack.
 *
 * Keys are component slugs as they appear on disk under wp-content/. Values
 * describe the GitHub repo + repo-root-relative offset (always empty string
 * for our repos because the plugin/theme repo IS the plugin/theme root).
 *
 * Slug categories are tracked via `kind`:
 *   - `theme`              — wp-content/themes/<slug>/
 *   - `plugin`             — wp-content/plugins/<slug>/, subsite-specific
 *   - `platform-plugin`    — wp-content/plugins/<slug>/, network-wide platform
 *                             boilerplate. Excluded from subsite-context detection
 *                             (so it doesn't mount as an editable surface when a
 *                             contributor is on a subsite), but available in the
 *                             registry for issue-tracking, cross-repo references,
 *                             and explicit-target code changes.
 *   - `agent-stack-plugin` — read-only references the sandboxed agent needs
 *
 * @since 0.7.0
 * @return array<string, array<string, string>>
 */
function extrachill_roadie_default_repo_map(): array {
	$defaults = array(
		// Themes.
		'extrachill'                 => array(
			'repo'                        => 'Extra-Chill/extrachill',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'theme',
		),

		// Extra Chill plugins commonly active on individual subsites.
		'extrachill-artist-platform' => array(
			'repo'                        => 'Extra-Chill/extrachill-artist-platform',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-community'       => array(
			'repo'                        => 'Extra-Chill/extrachill-community',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-events'          => array(
			'repo'                        => 'Extra-Chill/extrachill-events',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		// Data Machine EXTENSION that renders front-end UI a subsite actively
		// runs (the events Calendar + EventsMap blocks live entirely here). It
		// is a subsite-editable surface exactly like extrachill-events — NOT
		// read-only agent infrastructure. Classifying it `agent-stack-plugin`
		// (the trap the other Data Machine plugins fall into) would make the
		// calendar/map code Roadie can't reach. See extrachill-roadie#57.
		'data-machine-events'        => array(
			'repo'                        => 'Extra-Chill/data-machine-events',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-shop'            => array(
			'repo'                        => 'Extra-Chill/extrachill-shop',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-blog'            => array(
			'repo'                        => 'Extra-Chill/extrachill-blog',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-contact'         => array(
			'repo'                        => 'Extra-Chill/extrachill-contact',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-content-blocks'  => array(
			'repo'                        => 'Extra-Chill/extrachill-content-blocks',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-docs'            => array(
			'repo'                        => 'Extra-Chill/extrachill-docs',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-ai-adventure'    => array(
			'repo'                        => 'Extra-Chill/extrachill-ai-adventure',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),

		// Network-wide platform plugins. Not auto-mounted as editable surfaces
		// when a contributor is chatting from a subsite (the subsite-context
		// detector deliberately excludes them — they're platform boilerplate
		// from the subsite's POV), but they ARE legitimately issue-trackable
		// and code-change-trackable. Listing them here keeps the registry as
		// the single allowlist consulted by both `file_feature_request` and
		// future explicit-repo override flows.
		'extrachill-roadie'          => array(
			'repo'                        => 'Extra-Chill/extrachill-roadie',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-users'           => array(
			'repo'                        => 'Extra-Chill/extrachill-users',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-multisite'       => array(
			'repo'                        => 'Extra-Chill/extrachill-multisite',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-api'             => array(
			'repo'                        => 'Extra-Chill/extrachill-api',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-admin-tools'     => array(
			'repo'                        => 'Extra-Chill/extrachill-admin-tools',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-newsletter'      => array(
			'repo'                        => 'Extra-Chill/extrachill-newsletter',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-search'          => array(
			'repo'                        => 'Extra-Chill/extrachill-search',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-seo'             => array(
			'repo'                        => 'Extra-Chill/extrachill-seo',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-analytics'       => array(
			'repo'                        => 'Extra-Chill/extrachill-analytics',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-cli'             => array(
			'repo'                        => 'Extra-Chill/extrachill-cli',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-tokens'          => array(
			'repo'                        => 'Extra-Chill/extrachill-tokens',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		'extrachill-components'      => array(
			'repo'                        => 'Extra-Chill/extrachill-components',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),
		// Data Machine social-publishing EXTENSION. Unlike data-machine-events
		// it renders no front-end surface (it has no Blocks/ directory — only
		// server-side image-generation templates), so it is a backend
		// platform-plugin: in the registry so it's issue-trackable and
		// file-able, but excluded from subsite-context detection so it never
		// mounts as an editable surface. See extrachill-roadie#57.
		'data-machine-socials'       => array(
			'repo'                        => 'Extra-Chill/data-machine-socials',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'platform-plugin',
		),

		// Read-only agent stack (mounted as references so the sandboxed agent
		// can grep them, but never as editable surfaces).
		'agents-api'                 => array(
			'repo'                        => 'Automattic/agents-api',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'data-machine'               => array(
			'repo'                        => 'Extra-Chill/data-machine',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'data-machine-code'          => array(
			'repo'                        => 'Extra-Chill/data-machine-code',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'ai-provider-for-openai'     => array(
			'repo'                        => 'WordPress/ai-provider-for-openai',
			'default_branch'              => 'trunk',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'ai-provider-for-anthropic'  => array(
			'repo'                        => 'WordPress/ai-provider-for-anthropic',
			'default_branch'              => 'trunk',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
	);

	/**
	 * Filter the slug-to-repo map.
	 *
	 * Use this to add new plugins/themes, override default branches, or
	 * shift kind classifications without modifying plugin code.
	 *
	 * @since 0.7.0
	 *
	 * @param array $defaults Default slug-to-repo map.
	 */
	$filtered = apply_filters( 'extrachill_roadie_repo_map', $defaults );

	if ( ! is_array( $filtered ) ) {
		return $defaults;
	}

	return $filtered;
}

/**
 * Resolve a component slug to its `owner/name` GitHub repo.
 *
 * Thin lookup over the slug-to-repo registry so callers (e.g. the
 * file_feature_request repo-inference path) don't duplicate the registry or
 * re-implement the slug → repo mapping. Returns an empty string when the slug
 * is not registered.
 *
 * @since 0.11.0
 *
 * @param string $slug Component slug as it appears on disk under wp-content/.
 * @return string `owner/name` repo, or empty string when the slug is unknown.
 */
function extrachill_roadie_repo_for_slug( string $slug ): string {
	$slug = trim( $slug );
	if ( '' === $slug ) {
		return '';
	}

	$map = extrachill_roadie_default_repo_map();
	if ( ! isset( $map[ $slug ] ) ) {
		return '';
	}

	return (string) ( $map[ $slug ]['repo'] ?? '' );
}

/**
 * Get the canonical list of agent-stack plugin slugs (read-only mounts).
 *
 * Pulled from the repo map by filtering on `kind === 'agent-stack-plugin'`.
 *
 * @since 0.7.0
 * @return string[]
 */
function extrachill_roadie_agent_stack_slugs(): array {
	$slugs = array();
	foreach ( extrachill_roadie_default_repo_map() as $slug => $entry ) {
		if ( ( $entry['kind'] ?? '' ) === 'agent-stack-plugin' ) {
			$slugs[] = (string) $slug;
		}
	}
	return $slugs;
}
