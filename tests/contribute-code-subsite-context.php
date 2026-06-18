<?php
/**
 * Smoke tests for extrachill_roadie_detect_subsite_context().
 *
 * Run with: php tests/contribute-code-subsite-context.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Test 1: main subsite, only community plugin active --------------------
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 1;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array(
	'extrachill-community/extrachill-community.php',
	'breeze/breeze.php',                  // boilerplate, excluded
	'extrachill-roadie/extrachill-roadie.php', // self, excluded
);

$context = extrachill_roadie_detect_subsite_context();

roadie_test_assert( 1 === $context['blog_id'], 'blog_id should be 1.' );
roadie_test_assert( 'extrachill' === $context['theme']['slug'], 'Theme slug should be extrachill.' );
roadie_test_assert( '' === $context['theme']['parent_slug'], 'Parent slug should be empty for non-child theme.' );
roadie_test_assert( 1 === count( $context['plugins'] ), 'Only extrachill-community should remain after exclusion; got ' . count( $context['plugins'] ) );
roadie_test_assert( 'extrachill-community' === $context['plugins'][0]['slug'], 'Surviving plugin should be extrachill-community.' );
roadie_test_assert(
	str_ends_with( $context['plugins'][0]['path'], '/extrachill-community' ),
	'Plugin path should resolve under WP_PLUGIN_DIR.'
);

// --- Test 2: community subsite, multiple uniquely-meaningful plugins ------
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 2;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array(
	'extrachill-community/extrachill-community.php',
	'extrachill-artist-platform/extrachill-artist-platform.php',
	'data-machine/data-machine.php', // agent stack, excluded
);

$context = extrachill_roadie_detect_subsite_context();
roadie_test_assert( 2 === $context['blog_id'], 'blog_id should be 2.' );
$slugs = array_map( fn( $p ) => $p['slug'], $context['plugins'] );
sort( $slugs );
roadie_test_assert(
	$slugs === array( 'extrachill-artist-platform', 'extrachill-community' ),
	'community subsite should expose artist-platform + community as editable. Got: ' . implode( ',', $slugs )
);

// --- Test 3: explicit exclusion override via filter -----------------------
roadie_test_reset_filters();
add_filter(
	'extrachill_roadie_excluded_plugin_slugs',
	function ( array $slugs ) {
		// Drop extrachill-community from the exclusion list (it's not there
		// by default but this exercises the filter wiring).
		$slugs[] = 'extrachill-community';
		return $slugs;
	}
);

$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 2;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array(
	'extrachill-community/extrachill-community.php',
	'extrachill-artist-platform/extrachill-artist-platform.php',
);

$context = extrachill_roadie_detect_subsite_context();
$slugs   = array_map( fn( $p ) => $p['slug'], $context['plugins'] );
roadie_test_assert(
	$slugs === array( 'extrachill-artist-platform' ),
	'Filtered exclusion should drop extrachill-community. Got: ' . implode( ',', $slugs )
);

// --- Test 3b: events subsite exposes data-machine-events, hides socials ---
// (#57) The events site runs both extrachill-events AND data-machine-events.
// data-machine-events renders the Calendar + EventsMap UI, so it is a
// subsite-editable surface and must NOT be excluded. data-machine-socials
// renders no front-end and stays excluded.
roadie_test_reset_filters();
$GLOBALS['extrachill_roadie_test_state']['current_blog']   = 7;
$GLOBALS['extrachill_roadie_test_state']['active_plugins'] = array(
	'extrachill-events/extrachill-events.php',
	'data-machine-events/data-machine-events.php',
	'data-machine-socials/data-machine-socials.php', // backend, excluded
	'data-machine/data-machine.php',                 // agent infra, excluded
);

$context = extrachill_roadie_detect_subsite_context();
$slugs   = array_map( fn( $p ) => $p['slug'], $context['plugins'] );
sort( $slugs );
roadie_test_assert(
	$slugs === array( 'data-machine-events', 'extrachill-events' ),
	'events subsite must expose extrachill-events + data-machine-events (and exclude socials + agent infra). Got: ' . implode( ',', $slugs )
);
roadie_test_assert(
	! in_array( 'data-machine-socials', $slugs, true ),
	'data-machine-socials must stay excluded from subsite-context (backend, no front-end surface) (#57)'
);
roadie_test_reset_filters();

// --- Test 4: single-file plugin slug extraction ---------------------------
roadie_test_reset_filters();
roadie_test_assert(
	'single' === extrachill_roadie_plugin_file_to_slug( 'single.php' ),
	'single-file slug should strip .php'
);
roadie_test_assert(
	'multi' === extrachill_roadie_plugin_file_to_slug( 'multi/main.php' ),
	'multi-file slug should take leading directory'
);

echo "contribute-code subsite-context smoke passed.\n";
