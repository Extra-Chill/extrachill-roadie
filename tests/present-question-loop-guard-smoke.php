<?php
/**
 * Smoke test: present_question loop guard.
 *
 * Regression guard for the production failure where Roadie called
 * `present_question` eleven times for a single "open an issue" intent and never
 * called `file_feature_request` (chat session 8b6eb0f8-3388-4fcd-9e99-179a5d5ced32,
 * 2026-06-28). The fix lives in two places, both asserted here:
 *
 *   1. The roadie mode directive (inc/agent-mode/register.php) must contain a
 *      HARD, binding cap on clarifying questions for filing intent, must tell
 *      the model to call file_feature_request rather than loop present_question,
 *      and must forbid restating the question/choices as plain text after a
 *      present_question card (the observed double-emit).
 *
 *   2. The present_question tool description (inc/tools/class-present-question.php)
 *      must carry the same anti-loop + no-restate + "file instead" guards so the
 *      model sees them on the tool itself, not only in the mode prose.
 *
 * Pure assertion test — no WordPress bootstrap. Stubs mirror the registration
 * smoke so the guidance composes exactly as it does in production.
 *
 * Run with: php tests/present-question-loop-guard-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/_stub-agent-mode-registry.php';
require_once __DIR__ . '/_stub-base-tool.php';

$GLOBALS['ec_roadie_test_actions']          = array();
$GLOBALS['ec_roadie_test_filters']          = array();
$GLOBALS['ec_roadie_test_registered_tools'] = array();

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ec_roadie_test_actions'][ $hook ][] = $callback;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['ec_roadie_test_filters'][ $hook ][] = $callback;
}

function do_action( string $hook, ...$args ): void {
	foreach ( $GLOBALS['ec_roadie_test_actions'][ $hook ] ?? array() as $callback ) {
		$callback( ...$args );
	}
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( $GLOBALS['ec_roadie_test_filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}

	return $value;
}

function __( $text, $domain = null ): string {
	unset( $domain );
	return (string) $text;
}

// Grant the team capability so the team-tier guidance (which carries the binding
// "File, Don't Interrogate" section + the present_question posture bullet) is the
// branch under test.
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability, ...$args ): bool {
		unset( $user, $args );
		return 'access_roadie' === $capability;
	}
}

function ec_roadie_loop_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/permissions.php';
require_once dirname( __DIR__ ) . '/inc/agent-mode/register.php';

do_action( 'datamachine_agent_modes' );

// --- 1. Mode directive (team tier) anti-loop guards ----------------------

$guidance = apply_filters( 'datamachine_agent_mode_roadie', '', array( 'calling_user_id' => 38 ) );
$lower    = strtolower( $guidance );

ec_roadie_loop_assert(
	str_contains( $guidance, "File, Don't Interrogate" ),
	'Guidance must retain the "File, Don\'t Interrogate" filing section.'
);

// A hard cap on clarifying questions before filing must be present (not just a
// soft "prefer filing"). Look for the explicit one-or-two-then-MUST-file shape.
ec_roadie_loop_assert(
	( str_contains( $lower, 'one or two clarifying questions' ) || str_contains( $lower, 'one or two' ) )
		&& str_contains( $lower, 'file_feature_request' )
		&& str_contains( $lower, 'must' ),
	'Guidance must impose a HARD cap (~1-2 clarifying questions) that then REQUIRES calling file_feature_request.'
);

// The third-round prohibition is the crux of the fix.
ec_roadie_loop_assert(
	str_contains( $lower, 'third round' ) || str_contains( $lower, 'never allowed to ask a third' ),
	'Guidance must explicitly forbid a third round of clarifying questions for one filing intent.'
);

// present_question must be explicitly disqualified as a filing/interrogation tool.
ec_roadie_loop_assert(
	str_contains( $guidance, '`present_question` is NOT a filing tool' )
		|| ( str_contains( $lower, 'present_question' ) && str_contains( $lower, 'not a filing tool' ) ),
	'Guidance must state that present_question is NOT a filing tool.'
);

// Double-emit prohibition: the card renders the choices; do not also restate them.
ec_roadie_loop_assert(
	str_contains( $lower, 'never also restate' ) || str_contains( $lower, 'whole turn' ),
	'Guidance must forbid restating the present_question question/choices as plain text (the double-emit).'
);

// --- 2. present_question tool description guards --------------------------

require_once dirname( __DIR__ ) . '/inc/tools/class-present-question.php';

$tool       = new ECRoadie_PresentQuestion();
$registered = $GLOBALS['ec_roadie_test_registered_tools']['present_question'] ?? null;
ec_roadie_loop_assert( is_array( $registered ), 'present_question tool should register itself.' );

$definition = ( $registered['definition'] )();
$desc       = (string) ( $definition['description'] ?? '' );
$desc_lower = strtolower( $desc );

ec_roadie_loop_assert(
	str_contains( $desc_lower, 'do not call this repeatedly' )
		|| str_contains( $desc_lower, 'not call this repeatedly' ),
	'present_question description must warn against calling it repeatedly for one intent.'
);

ec_roadie_loop_assert(
	str_contains( $desc_lower, 'file_feature_request' ),
	'present_question description must redirect filing intent to file_feature_request.'
);

ec_roadie_loop_assert(
	str_contains( $desc_lower, 'do not' ) && str_contains( $desc_lower, 'restate' ),
	'present_question description must forbid restating the question/choices as plain text.'
);

// The tool still functions — emitting it returns the question + choices intact.
$result = $tool->handle_tool_call(
	array(
		'question' => 'Pick one',
		'choices'  => array(
			array( 'label' => 'A', 'message' => 'Do A.' ),
			array( 'label' => 'B', 'message' => 'Do B.' ),
		),
	)
);
ec_roadie_loop_assert(
	isset( $result['result']['question'] ) && 'Pick one' === $result['result']['question'],
	'present_question must still echo the question under result.question.'
);
ec_roadie_loop_assert(
	isset( $result['result']['choices'] ) && 2 === count( $result['result']['choices'] ),
	'present_question must still echo both choices under result.choices.'
);

echo "present_question loop guard smoke passed (12 assertions).\n";
