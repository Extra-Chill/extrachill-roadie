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
 *   - `plugin`             — wp-content/plugins/<slug>/
 *   - `agent-stack-plugin` — read-only references the sandboxed agent needs
 *
 * @since 0.7.0
 * @return array<string, array<string, string>>
 */
function extrachill_roadie_default_repo_map(): array {
	$defaults = array(
		// Themes.
		'extrachill' => array(
			'repo'                          => 'Extra-Chill/extrachill',
			'default_branch'                => 'main',
			'repo_root_relative_to_mount'   => '',
			'kind'                          => 'theme',
		),

		// Extra Chill plugins commonly active on individual subsites.
		'extrachill-artist-platform'  => array(
			'repo'                        => 'Extra-Chill/extrachill-artist-platform',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-community'        => array(
			'repo'                        => 'Extra-Chill/extrachill-community',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-events'           => array(
			'repo'                        => 'Extra-Chill/extrachill-events',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-shop'             => array(
			'repo'                        => 'Extra-Chill/extrachill-shop',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-blog'             => array(
			'repo'                        => 'Extra-Chill/extrachill-blog',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-contact'          => array(
			'repo'                        => 'Extra-Chill/extrachill-contact',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-content-blocks'   => array(
			'repo'                        => 'Extra-Chill/extrachill-content-blocks',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-docs'             => array(
			'repo'                        => 'Extra-Chill/extrachill-docs',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),
		'extrachill-ai-adventure'     => array(
			'repo'                        => 'Extra-Chill/extrachill-ai-adventure',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		),

		// Read-only agent stack (mounted as references so the sandboxed agent
		// can grep them, but never as editable surfaces).
		'agents-api' => array(
			'repo'                        => 'Automattic/agents-api',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'data-machine' => array(
			'repo'                        => 'Extra-Chill/data-machine',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'data-machine-code' => array(
			'repo'                        => 'Extra-Chill/data-machine-code',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'ai-provider-for-openai' => array(
			'repo'                        => 'WordPress/ai-provider-for-openai',
			'default_branch'              => 'trunk',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'agent-stack-plugin',
		),
		'ai-provider-for-anthropic' => array(
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
