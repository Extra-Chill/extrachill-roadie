<?php
/**
 * Smoke tests for the contribute-code capability + token resolution.
 *
 * Run with: php tests/contribute-code-capabilities.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- user_has_cap grant ---------------------------------------------------
$admin_user            = new stdClass();
$admin_user->roles     = array( 'administrator' );
$editor_user           = new stdClass();
$editor_user->roles    = array( 'editor' );
$contributor_user      = new stdClass();
$contributor_user->roles = array( 'contributor' );

$allcaps_admin = extrachill_roadie_grant_propose_code_cap( array(), array(), array(), $admin_user );
roadie_test_assert(
	! empty( $allcaps_admin[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] ),
	'administrator must receive extrachill_propose_code by default'
);

$allcaps_editor = extrachill_roadie_grant_propose_code_cap( array(), array(), array(), $editor_user );
roadie_test_assert(
	! empty( $allcaps_editor[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] ),
	'editor must receive extrachill_propose_code by default'
);

$allcaps_contributor = extrachill_roadie_grant_propose_code_cap( array(), array(), array(), $contributor_user );
roadie_test_assert(
	empty( $allcaps_contributor[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] ),
	'contributor must NOT receive extrachill_propose_code by default'
);

// --- filter override expands grant ---------------------------------------
roadie_test_reset_filters();
add_filter(
	'extrachill_roadie_propose_code_roles',
	function ( array $roles ) {
		$roles[] = 'author';
		return $roles;
	}
);

$author_user        = new stdClass();
$author_user->roles = array( 'author' );
$allcaps_author     = extrachill_roadie_grant_propose_code_cap( array(), array(), array(), $author_user );
roadie_test_assert(
	! empty( $allcaps_author[ EXTRACHILL_ROADIE_PROPOSE_CODE_CAP ] ),
	'author should receive the cap after filter override'
);

// --- env var name resolution --------------------------------------------
roadie_test_reset_filters();
roadie_test_assert(
	'GITHUB_TOKEN' === extrachill_roadie_github_token_env_name(),
	'default env var name should be GITHUB_TOKEN'
);

$GLOBALS['extrachill_roadie_test_state']['site_options']['extrachill_roadie_github_token_env'] = 'EC_BOT_TOKEN';
roadie_test_assert(
	'EC_BOT_TOKEN' === extrachill_roadie_github_token_env_name(),
	'network option override should resolve via get_site_option'
);

// --- presence check -----------------------------------------------------
putenv( 'EC_BOT_TOKEN=' ); // empty
roadie_test_assert(
	! extrachill_roadie_github_token_is_present(),
	'empty env var must not count as present'
);

putenv( 'EC_BOT_TOKEN=ghp_realtoken_value' );
roadie_test_assert(
	extrachill_roadie_github_token_is_present(),
	'non-empty env var must count as present'
);

// --- filter override of env var name ------------------------------------
add_filter( 'extrachill_roadie_github_token_env_name', fn() => 'CUSTOM_GH_TOKEN' );
putenv( 'CUSTOM_GH_TOKEN=hello' );
roadie_test_assert(
	'CUSTOM_GH_TOKEN' === extrachill_roadie_github_token_env_name(),
	'filter override on env var name'
);
roadie_test_assert(
	extrachill_roadie_github_token_is_present(),
	'filter-overridden env var should be detected'
);

echo "contribute-code capabilities smoke passed.\n";
