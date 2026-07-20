<?php
/**
 * Smoke tests for the read-only inspect_code chat tool.
 *
 * Covers:
 *   - Tool registers via the datamachine_tools filter (roadie mode, authenticated).
 *   - Tool definition surfaces the three read-only actions and NO write action.
 *   - Capability gate: callers below team tier (no access_roadie) are blocked.
 *   - SECURITY — the load-bearing jail:
 *       * a "../../wp-config.php" traversal attempt is REJECTED,
 *       * an absolute path outside the component is REJECTED,
 *       * a symlink that escapes the jail is REJECTED,
 *       * a read of a real file INSIDE the component SUCCEEDS.
 *   - list_tree returns entries inside the component and skips .git.
 *   - read_file honors start_line/end_line bounding.
 *   - grep finds a term and returns file + line + matched text.
 *
 * The jail is built over a real temp directory fixture so realpath()/is_dir()
 * behave for real — the whole point of the tool is the realpath containment
 * check, which a pure-stub filesystem could not exercise honestly.
 *
 * Run: php tests/inspect-code-tool.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Roadie permission constants/resolver the tool gates on ----------------
require_once dirname( __DIR__ ) . '/inc/permissions.php';

// --- BaseTool stub (same shape as feature-request-tool.php) ----------------
if ( ! class_exists( 'DataMachine\\Engine\\AI\\Tools\\BaseTool' ) ) {
	eval(
		'namespace DataMachine\\Engine\\AI\\Tools;
		abstract class BaseTool {
			protected function registerTool( string $toolName, $toolDefinition, array $modes = array(), array $meta = array() ): void {
				$GLOBALS["extrachill_roadie_test_state"]["registered_tools"][ $toolName ] = array(
					"definition" => $toolDefinition,
					"modes"      => $modes,
					"meta"       => $meta,
				);
			}
			protected function buildErrorResponse( string $error, string $tool_name ): array {
				return array( "success" => false, "error" => $error, "error_type" => "validation", "tool_name" => $tool_name );
			}
			protected function buildDiagnosticErrorResponse( string $error, string $type, string $tool_name, array $a = array(), array $b = array() ): array {
				return array( "success" => false, "error" => $error, "error_type" => $type, "tool_name" => $tool_name );
			}
		}'
	);
}

// permissions.php calls add_filter at file scope for the access bridges; the
// bootstrap's add_filter stub absorbs those harmlessly.

// user_can() is needed by extrachill_roadie_user_tier(). Drive it off test state.
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $cap ): bool {
		$caps = $GLOBALS['extrachill_roadie_test_state']['caps_by_user'][ (int) $user_id ] ?? array();
		return ! empty( $caps[ $cap ] );
	}
}

$GLOBALS['extrachill_roadie_test_state']['registered_tools'] = array();
$GLOBALS['extrachill_roadie_test_state']['caps_by_user']     = array();
$GLOBALS['extrachill_roadie_test_state']['current_user_id']  = 0;

require_once dirname( __DIR__ ) . '/inc/tools/class-inspect-code.php';

$tool = new ECRoadie_InspectCode();

// --- Build a real temp fixture component + an out-of-jail secret -----------

$base    = sys_get_temp_dir() . '/ec-roadie-inspect-' . getmypid() . '-' . random_int( 1000, 9999 );
$comp    = $base . '/extrachill-events';        // the jailed component
$outside = $base . '/secrets';                  // sibling, OUTSIDE the jail
$prefix  = $base . '/extrachill-events-evil';   // prefix-sibling trap

mkdir( $comp . '/templates', 0777, true );
mkdir( $comp . '/.git', 0777, true );
mkdir( $outside, 0777, true );
mkdir( $prefix, 0777, true );

file_put_contents(
	$comp . '/templates/calendar.php',
	"<?php\n// Events calendar template.\n\$show_map = true; // the map block\necho 'tonight button';\n// line five\n// line six\n"
);
file_put_contents( $comp . '/readme.txt', "Extra Chill Events\nThe calendar map lives in templates/.\n" );
file_put_contents( $comp . '/.git/HEAD', "ref: refs/heads/main\n" );
file_put_contents( $outside . '/wp-config.php', "<?php define('DB_PASSWORD','hunter2');\n" );
file_put_contents( $prefix . '/leak.php', "<?php // should never be reachable from the jail\n" );

// A symlink inside the component that escapes to the secret dir.
$escape_link = $comp . '/escape';
@symlink( $outside, $escape_link );
$symlink_made = is_link( $escape_link );

// Point the jail at the fixture component via the dedicated filter.
add_filter(
	'extrachill_roadie_inspect_code_jail_roots',
	function ( $roots ) use ( $comp ) {
		return array( 'extrachill-events' => realpath( $comp ) );
	}
);

// Helper: become a given user with a given cap set.
$set_user = function ( int $user_id, array $caps ) {
	$GLOBALS['extrachill_roadie_test_state']['current_user_id']            = $user_id;
	$GLOBALS['extrachill_roadie_test_state']['caps_by_user'][ $user_id ]   = $caps;
};

// =====================================================================
// Registration + definition
// =====================================================================

roadie_test_assert(
	isset( $GLOBALS['extrachill_roadie_test_state']['registered_tools']['inspect_code'] ),
	'inspect_code must register via registerTool()'
);

$reg = $GLOBALS['extrachill_roadie_test_state']['registered_tools']['inspect_code'];
roadie_test_assert( array( 'roadie' ) === $reg['modes'], 'tool must register only for roadie mode' );
roadie_test_assert(
	'authenticated' === ( $reg['meta']['access_level'] ?? '' ),
	'tool must require authenticated access_level'
);

$definition = $tool->getToolDefinition();
roadie_test_assert( 'handle_tool_call' === $definition['method'], 'tool dispatch method' );
$action_enum = $definition['parameters']['properties']['action']['enum'] ?? array();
sort( $action_enum );
roadie_test_assert(
	$action_enum === array( 'grep', 'list_tree', 'read_file' ),
	'action enum is exactly the three READ-ONLY actions (no write action). Got: ' . implode( ',', $action_enum )
);

// =====================================================================
// Capability gate — below team tier is blocked
// =====================================================================

// A logged-in user WITHOUT access_roadie (e.g. has propose-code only — proves
// the tool is NOT gated on propose-code) must still be blocked.
$set_user( 5, array( 'extrachill_propose_code' => true ) );
$blocked = $tool->handle_tool_call( array( 'action' => 'list_tree' ) );
roadie_test_assert( false === $blocked['success'], 'non-team caller must be blocked even with propose-code cap' );
roadie_test_assert(
	false !== stripos( $blocked['error'] ?? '', 'team access' ) || false !== stripos( $blocked['error'] ?? '', 'access_roadie' ),
	'capability error must mention team access'
);

// A team member WITH access_roadie but WITHOUT propose-code MUST be allowed —
// this is the core acceptance criterion from issue #54.
$set_user( 7, array( 'access_roadie' => true ) );
$allowed_probe = $tool->handle_tool_call( array( 'action' => 'list_tree' ) );
roadie_test_assert(
	true === $allowed_probe['success'],
	'team member with access_roadie (and NOT propose-code) can use inspect_code'
);

// =====================================================================
// SECURITY — the realpath jail (load-bearing)
// =====================================================================

// (a) classic traversal: ../../wp-config.php must be rejected.
$trav = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => '../../secrets/wp-config.php' ) );
roadie_test_assert( false === $trav['success'], 'traversal read of ../../secrets/wp-config.php must be REJECTED' );
roadie_test_assert(
	false !== stripos( $trav['error'] ?? '', 'traversal' ) || false !== stripos( $trav['error'] ?? '', 'not found' ),
	'traversal rejection surfaces a clear error'
);

// (a2) bare wp-config name dressed up with traversal segments.
$trav2 = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => 'templates/../../secrets/wp-config.php' ) );
roadie_test_assert( false === $trav2['success'], 'nested traversal to wp-config must be REJECTED' );

// (b) absolute path outside the component.
$abs = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => $outside . '/wp-config.php' ) );
roadie_test_assert( false === $abs['success'], 'absolute path outside the jail must be REJECTED' );

// (b2) prefix-sibling escape (extrachill-events-evil shares a name prefix).
$prefix_attack = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => '../extrachill-events-evil/leak.php' ) );
roadie_test_assert( false === $prefix_attack['success'], 'prefix-sibling escape must be REJECTED' );

// (c) symlink escape — a symlink inside the jail pointing at the secret dir
// must NOT yield the secret file (realpath resolves it out of the jail).
if ( $symlink_made ) {
	$sym = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => 'escape/wp-config.php' ) );
	roadie_test_assert( false === $sym['success'], 'symlink escape to wp-config must be REJECTED' );
} else {
	echo "  (note: symlink could not be created in this environment; symlink-escape assertion skipped)\n";
}

// (d) a real read INSIDE the component SUCCEEDS.
$read = $tool->handle_tool_call( array( 'action' => 'read_file', 'path' => 'templates/calendar.php' ) );
roadie_test_assert( true === $read['success'], 'reading a real file inside the component must SUCCEED' );
roadie_test_assert(
	false !== strpos( $read['data']['content'] ?? '', 'the map block' ),
	'read_file returns the real file content'
);
roadie_test_assert( 'extrachill-events' === ( $read['data']['component'] ?? '' ), 'read_file reports the owning component' );

// =====================================================================
// read_file line bounding
// =====================================================================

$bounded = $tool->handle_tool_call( array(
	'action'     => 'read_file',
	'path'       => 'templates/calendar.php',
	'start_line' => 3,
	'end_line'   => 4,
) );
roadie_test_assert( true === $bounded['success'], 'bounded read succeeds' );
roadie_test_assert( 3 === ( $bounded['data']['start_line'] ?? 0 ), 'bounded read reports start_line' );
roadie_test_assert( 4 === ( $bounded['data']['end_line'] ?? 0 ), 'bounded read reports end_line' );
$body = $bounded['data']['content'] ?? '';
roadie_test_assert( false !== strpos( $body, 'the map block' ), 'bounded read includes line 3' );
roadie_test_assert( false !== strpos( $body, 'tonight button' ), 'bounded read includes line 4' );
roadie_test_assert( false === strpos( $body, 'line five' ), 'bounded read excludes lines outside the range' );

// =====================================================================
// list_tree — entries inside the component, .git skipped
// =====================================================================

$tree = $tool->handle_tool_call( array( 'action' => 'list_tree' ) );
roadie_test_assert( true === $tree['success'], 'list_tree succeeds' );
$entries = $tree['data']['entries'] ?? array();
roadie_test_assert( in_array( 'templates/', $entries, true ), 'list_tree lists the templates/ dir' );
roadie_test_assert( in_array( 'templates/calendar.php', $entries, true ), 'list_tree lists templates/calendar.php' );
$has_git = false;
foreach ( $entries as $e ) {
	if ( 0 === strpos( $e, '.git' ) ) {
		$has_git = true;
		break;
	}
}
roadie_test_assert( false === $has_git, 'list_tree must skip the .git directory' );

// scoped subpath — entries stay relative to the COMPONENT ROOT (not the
// subpath), so they are directly usable as read_file paths.
$tree_scoped = $tool->handle_tool_call( array( 'action' => 'list_tree', 'subpath' => 'templates' ) );
roadie_test_assert( true === $tree_scoped['success'], 'list_tree with subpath succeeds' );
$scoped_entries = $tree_scoped['data']['entries'] ?? array();
roadie_test_assert(
	in_array( 'templates/calendar.php', $scoped_entries, true ),
	'subpath-scoped tree lists templates/calendar.php (paths stay relative to component root). Got: ' . implode( ',', $scoped_entries )
);
roadie_test_assert(
	! in_array( 'readme.txt', $scoped_entries, true ),
	'subpath-scoped tree is limited to the subpath (no root-level readme.txt)'
);

// subpath traversal is rejected
$tree_escape = $tool->handle_tool_call( array( 'action' => 'list_tree', 'subpath' => '../secrets' ) );
roadie_test_assert( false === $tree_escape['success'], 'list_tree subpath traversal must be REJECTED' );

// =====================================================================
// grep — find a term, return file + line + text
// =====================================================================

$grep = $tool->handle_tool_call( array( 'action' => 'grep', 'query' => 'map' ) );
roadie_test_assert( true === $grep['success'], 'grep succeeds' );
$matches = $grep['data']['matches'] ?? array();
roadie_test_assert( count( $matches ) >= 1, 'grep finds at least one match for "map"' );

$found_calendar = false;
foreach ( $matches as $m ) {
	if ( 'templates/calendar.php' === ( $m['path'] ?? '' ) && false !== stripos( $m['text'] ?? '', 'map' ) ) {
		$found_calendar = true;
		roadie_test_assert( ( $m['line'] ?? 0 ) > 0, 'grep match carries a positive line number' );
	}
}
roadie_test_assert( $found_calendar, 'grep surfaces the calendar template match with file + line + text' );

// grep must never reach outside the jail.
$grep_secret = $tool->handle_tool_call( array( 'action' => 'grep', 'query' => 'hunter2' ) );
roadie_test_assert( true === $grep_secret['success'], 'grep for an out-of-jail secret runs' );
roadie_test_assert(
	0 === ( $grep_secret['data']['count'] ?? -1 ),
	'grep must NOT find the out-of-jail secret (hunter2) — the jail holds'
);

// =====================================================================
// Cleanup
// =====================================================================

$rrmdir = function ( string $dir ) use ( &$rrmdir ) {
	if ( is_link( $dir ) ) {
		@unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		@unlink( $dir );
		return;
	}
	foreach ( scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$rrmdir( $dir . '/' . $item );
	}
	@rmdir( $dir );
};
$rrmdir( $base );

echo "inspect-code tool smoke passed.\n";
