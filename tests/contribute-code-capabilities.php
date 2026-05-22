<?php
/**
 * Smoke tests for the contribute-code capability + apply-tool github token helpers.
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

// --- apply-tool GitHub token helpers (host-only, never crosses to sandbox) -
roadie_test_reset_filters();
roadie_test_assert(
	'GITHUB_TOKEN' === extrachill_roadie_apply_github_token_env_name(),
	'default apply-back env var should be GITHUB_TOKEN'
);

putenv( 'GITHUB_TOKEN=' );
roadie_test_assert(
	! extrachill_roadie_apply_github_token_present(),
	'empty GITHUB_TOKEN must not count as present'
);

putenv( 'GITHUB_TOKEN=ghp_real_token_value' );
roadie_test_assert(
	extrachill_roadie_apply_github_token_present(),
	'non-empty GITHUB_TOKEN must count as present'
);

// --- filter override of env var name ------------------------------------
add_filter( 'extrachill_roadie_apply_github_token_env', fn() => 'EC_BOT_GH_TOKEN' );
putenv( 'EC_BOT_GH_TOKEN=hello' );
roadie_test_assert(
	'EC_BOT_GH_TOKEN' === extrachill_roadie_apply_github_token_env_name(),
	'filter override on apply-back env var name'
);
roadie_test_assert(
	extrachill_roadie_apply_github_token_present(),
	'filter-overridden env var should be detected'
);

echo "contribute-code capabilities smoke passed.\n";
