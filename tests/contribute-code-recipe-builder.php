<?php
/**
 * Smoke tests for extrachill_roadie_build_recipe().
 *
 * Run with: php tests/contribute-code-recipe-builder.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Setup --------------------------------------------------------------
$context = array(
	'blog_id'  => 2,
	'site_url' => 'https://community.extrachill.test',
	'theme'    => array(
		'slug'        => 'extrachill',
		'parent_slug' => '',
		'path'        => '/host/themes/extrachill',
		'name'        => 'Extra Chill',
	),
	'plugins'  => array(
		array(
			'slug' => 'extrachill-community',
			'file' => 'extrachill-community/extrachill-community.php',
			'path' => '/host/plugins/extrachill-community',
			'name' => 'Extra Chill Community',
		),
		array(
			'slug' => 'some-unmapped-plugin',
			'file' => 'some-unmapped-plugin/main.php',
			'path' => '/host/plugins/some-unmapped-plugin',
			'name' => 'Some unmapped plugin',
		),
	),
);

$repo_map = extrachill_roadie_default_repo_map();
$recipe   = extrachill_roadie_build_recipe(
	$context,
	$repo_map,
	array(
		'agent_stack_plugin_paths' => array(
			'data-machine'              => '/host/plugins/data-machine',
			'data-machine-code'         => '/host/plugins/data-machine-code',
			'agents-api'                => '/host/plugins/agents-api',
			'ai-provider-for-openai'    => '/host/plugins/ai-provider-for-openai',
			'ai-provider-for-anthropic' => '/host/plugins/ai-provider-for-anthropic',
		),
	)
);

// --- Test 1: shape ------------------------------------------------------
roadie_test_assert( is_array( $recipe['mounts'] ), 'mounts must be an array' );
roadie_test_assert( count( $recipe['mounts'] ) >= 2, 'recipe must contain at least theme + community mounts; got ' . count( $recipe['mounts'] ) );

// --- Test 2: theme mount is readwrite with correct target ----------------
$theme_mount = null;
foreach ( $recipe['mounts'] as $m ) {
	if ( ( $m['metadata']['kind'] ?? '' ) === 'theme' ) {
		$theme_mount = $m;
		break;
	}
}
roadie_test_assert( null !== $theme_mount, 'theme mount must exist' );
roadie_test_assert( '/host/themes/extrachill' === $theme_mount['source'], 'theme source path' );
roadie_test_assert( '/wordpress/wp-content/themes/extrachill' === $theme_mount['target'], 'theme target path' );
roadie_test_assert( 'readwrite' === $theme_mount['mode'], 'theme mount must be readwrite' );
roadie_test_assert( 'Extra-Chill/extrachill' === $theme_mount['metadata']['repo'], 'theme repo metadata' );
roadie_test_assert( 'main' === $theme_mount['metadata']['default_branch'], 'theme default_branch' );

// --- Test 3: community plugin mount is readwrite -------------------------
$community_mount = null;
foreach ( $recipe['mounts'] as $m ) {
	if ( ( $m['metadata']['slug'] ?? '' ) === 'extrachill-community' ) {
		$community_mount = $m;
		break;
	}
}
roadie_test_assert( null !== $community_mount, 'community mount must exist' );
roadie_test_assert( 'readwrite' === $community_mount['mode'], 'community mount must be readwrite' );
roadie_test_assert( 'Extra-Chill/extrachill-community' === $community_mount['metadata']['repo'], 'community repo' );

// --- Test 4: unmapped plugin tracked, NOT mounted ------------------------
roadie_test_assert(
	in_array( 'some-unmapped-plugin', $recipe['unmapped_active_plugins'], true ),
	'unmapped plugin slug must appear in unmapped_active_plugins'
);
foreach ( $recipe['mounts'] as $m ) {
	roadie_test_assert(
		( $m['metadata']['slug'] ?? '' ) !== 'some-unmapped-plugin',
		'unmapped plugin must NOT have a mount entry'
	);
}

// --- Test 5: agent stack is readonly -------------------------------------
$agent_mounts = array_filter(
	$recipe['mounts'],
	fn( $m ) => ( $m['metadata']['kind'] ?? '' ) === 'agent-stack-plugin'
);
roadie_test_assert( count( $agent_mounts ) >= 3, 'agent stack must include at least 3 references; got ' . count( $agent_mounts ) );
foreach ( $agent_mounts as $m ) {
	roadie_test_assert( 'readonly' === $m['mode'], 'agent-stack mount must be readonly: ' . ( $m['metadata']['slug'] ?? '' ) );
}

// --- Test 6: editable_targets index ---------------------------------------
roadie_test_assert(
	isset( $recipe['editable_targets']['extrachill'] ),
	'editable_targets must include theme slug'
);
roadie_test_assert(
	isset( $recipe['editable_targets']['extrachill-community'] ),
	'editable_targets must include community slug'
);

// --- Test 7: include_agent_stack=false drops the read-only branch --------
$recipe2 = extrachill_roadie_build_recipe(
	$context,
	$repo_map,
	array( 'include_agent_stack' => false )
);
foreach ( $recipe2['mounts'] as $m ) {
	roadie_test_assert(
		( $m['metadata']['kind'] ?? '' ) !== 'agent-stack-plugin',
		'include_agent_stack=false should drop agent-stack mounts'
	);
}
roadie_test_assert( empty( $recipe2['agent_stack_targets'] ), 'agent_stack_targets must be empty when disabled' );

// --- Test 8: filter override on repo map adds a new mappable plugin -----
roadie_test_reset_filters();
add_filter(
	'extrachill_roadie_repo_map',
	function ( array $map ) {
		$map['some-unmapped-plugin'] = array(
			'repo'                        => 'Extra-Chill/some-unmapped-plugin',
			'default_branch'              => 'main',
			'repo_root_relative_to_mount' => '',
			'kind'                        => 'plugin',
		);
		return $map;
	}
);
$updated_map = extrachill_roadie_default_repo_map();
$recipe3     = extrachill_roadie_build_recipe( $context, $updated_map );
$found       = false;
foreach ( $recipe3['mounts'] as $m ) {
	if ( ( $m['metadata']['slug'] ?? '' ) === 'some-unmapped-plugin' ) {
		$found = true;
		roadie_test_assert( 'readwrite' === $m['mode'], 'newly-mapped plugin should be readwrite' );
		roadie_test_assert( 'Extra-Chill/some-unmapped-plugin' === $m['metadata']['repo'], 'newly-mapped repo' );
	}
}
roadie_test_assert( $found, 'filter-added repo entry must produce a mount' );

echo "contribute-code recipe-builder smoke passed.\n";
