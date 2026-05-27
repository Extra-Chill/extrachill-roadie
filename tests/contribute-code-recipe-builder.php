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
// Build a fake workspace root with stubs for the components used below.
$fake_root = sys_get_temp_dir() . '/roadie-test-workspace-' . uniqid();
mkdir( $fake_root, 0755, true );
foreach ( array( 'extrachill', 'extrachill-community', 'extrachill-artist-platform' ) as $slug ) {
	mkdir( $fake_root . '/' . $slug, 0755, true );
}

add_filter( 'extrachill_roadie_workspace_root', static fn() => $fake_root );

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
$recipe   = extrachill_roadie_build_recipe( $context, $repo_map );

// --- Test 1: shape ------------------------------------------------------
roadie_test_assert( is_array( $recipe['mounts'] ), 'mounts must be an array' );
roadie_test_assert(
	count( $recipe['mounts'] ) === 2,
	'recipe must contain exactly theme + community mounts (no agent stack); got ' . count( $recipe['mounts'] )
);

// --- Test 2: theme mount sources from workspace root, sets baselineSource ---
$theme_mount = null;
foreach ( $recipe['mounts'] as $m ) {
	if ( ( $m['metadata']['kind'] ?? '' ) === 'theme' ) {
		$theme_mount = $m;
		break;
	}
}
roadie_test_assert( null !== $theme_mount, 'theme mount must exist' );
roadie_test_assert(
	$fake_root . '/extrachill' === $theme_mount['source'],
	'theme source must be workspace clone path; got ' . $theme_mount['source']
);
roadie_test_assert(
	'/wordpress/wp-content/themes/extrachill' === $theme_mount['target'],
	'theme target path'
);
roadie_test_assert( 'readwrite' === $theme_mount['mode'], 'theme mount must be readwrite' );
roadie_test_assert(
	$theme_mount['source'] === $theme_mount['metadata']['baselineSource'],
	'theme mount must set baselineSource === source for captureMountDiffs'
);
roadie_test_assert( 'Extra-Chill/extrachill' === $theme_mount['metadata']['repo'], 'theme repo metadata' );
roadie_test_assert( true === ( $theme_mount['metadata']['editable'] ?? false ), 'theme mount must be marked editable' );

// --- Test 3: community plugin mount also from workspace + baselineSource ---
$community_mount = null;
foreach ( $recipe['mounts'] as $m ) {
	if ( ( $m['metadata']['slug'] ?? '' ) === 'extrachill-community' ) {
		$community_mount = $m;
		break;
	}
}
roadie_test_assert( null !== $community_mount, 'community mount must exist' );
roadie_test_assert(
	$fake_root . '/extrachill-community' === $community_mount['source'],
	'community source must be workspace clone path'
);
roadie_test_assert( 'readwrite' === $community_mount['mode'], 'community mount must be readwrite' );
roadie_test_assert(
	$community_mount['source'] === $community_mount['metadata']['baselineSource'],
	'community mount must set baselineSource'
);

// --- Test 4: NO agent-stack mounts in our recipe ------------------------
foreach ( $recipe['mounts'] as $m ) {
	roadie_test_assert(
		( $m['metadata']['kind'] ?? '' ) !== 'agent-stack-plugin',
		'agent-stack-plugin must NOT appear in mounts; wp-codebox handles via component paths'
	);
}

// --- Test 5: unmapped plugin tracked, NOT mounted ------------------------
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

// --- Test 6: missing workspace clone tracked, NOT mounted ---------------
roadie_test_reset_filters();
add_filter( 'extrachill_roadie_workspace_root', static fn() => $fake_root );
// Add a context entry whose workspace clone doesn't exist.
$context_missing = $context;
$context_missing['plugins'][] = array(
	'slug' => 'extrachill-shop',
	'file' => 'extrachill-shop/extrachill-shop.php',
	'path' => '/host/plugins/extrachill-shop',
	'name' => 'Extra Chill Shop',
);
$recipe_missing = extrachill_roadie_build_recipe( $context_missing, $repo_map );
roadie_test_assert(
	in_array( 'extrachill-shop', $recipe_missing['missing_clones'], true ),
	'missing workspace clone must be tracked in missing_clones'
);
foreach ( $recipe_missing['mounts'] as $m ) {
	roadie_test_assert(
		( $m['metadata']['slug'] ?? '' ) !== 'extrachill-shop',
		'mount for missing clone must not be emitted'
	);
}

// --- Test 7: require_clone=false bypasses the check ---------------------
$recipe_nocheck = extrachill_roadie_build_recipe(
	$context_missing,
	$repo_map,
	array( 'require_clone' => false )
);
$found_shop = false;
foreach ( $recipe_nocheck['mounts'] as $m ) {
	if ( ( $m['metadata']['slug'] ?? '' ) === 'extrachill-shop' ) {
		$found_shop = true;
		break;
	}
}
roadie_test_assert( $found_shop, 'require_clone=false should emit mounts even when clone is missing' );

// --- Test 8: filter override on repo map adds new mappable plugin -------
roadie_test_reset_filters();
add_filter( 'extrachill_roadie_workspace_root', static fn() => $fake_root );
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
// Create the corresponding workspace dir.
mkdir( $fake_root . '/some-unmapped-plugin', 0755, true );
$updated_map = extrachill_roadie_default_repo_map();
$recipe2     = extrachill_roadie_build_recipe( $context, $updated_map );
$found       = false;
foreach ( $recipe2['mounts'] as $m ) {
	if ( ( $m['metadata']['slug'] ?? '' ) === 'some-unmapped-plugin' ) {
		$found = true;
		roadie_test_assert( 'readwrite' === $m['mode'], 'newly-mapped plugin should be readwrite' );
		roadie_test_assert( 'Extra-Chill/some-unmapped-plugin' === $m['metadata']['repo'], 'newly-mapped repo' );
		roadie_test_assert(
			$m['source'] === $m['metadata']['baselineSource'],
			'newly-mapped mount must also set baselineSource'
		);
	}
}
roadie_test_assert( $found, 'filter-added repo entry must produce a mount' );

// Cleanup
shell_exec( 'rm -rf ' . escapeshellarg( $fake_root ) );

echo "contribute-code recipe-builder smoke passed.\n";
