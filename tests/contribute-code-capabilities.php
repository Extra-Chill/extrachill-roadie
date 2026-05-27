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

// --- Apply-back GitHub token helpers removed (issue #22) ------------------
//
// The previous `extrachill_roadie_apply_github_token_env_name()` and
// `_present()` helpers (plus the `extrachill_roadie_apply_github_token_env`
// filter) were deleted when apply-back switched to
// DataMachineCode\Support\GitHubCredentialResolver. Both tools now resolve a
// per-repo token via the credential profile system; nothing in the
// extrachill-roadie surface should reach for those names anymore. Assert
// they really are gone so future drift fails loudly here.
roadie_test_assert(
	! function_exists( 'extrachill_roadie_apply_github_token_env_name' ),
	'extrachill_roadie_apply_github_token_env_name() must be removed (issue #22)'
);
roadie_test_assert(
	! function_exists( 'extrachill_roadie_apply_github_token_present' ),
	'extrachill_roadie_apply_github_token_present() must be removed (issue #22)'
);

echo "contribute-code capabilities smoke passed.\n";
