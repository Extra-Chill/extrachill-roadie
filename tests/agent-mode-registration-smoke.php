<?php
/**
 * Smoke test: roadie agent mode registration.
 *
 * Verifies that loading inc/agent-mode/register.php registers the `roadie`
 * mode with the AgentModeRegistry (priority 45, label "Extra Chill Platform")
 * and that the datamachine_agent_mode_roadie filter is wired and emits a
 * non-empty guidance string covering the key contract points.
 *
 * Run with: php tests/agent-mode-registration-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Stub the registry class FIRST — namespace declarations cannot follow
// procedural code in the same file.
require_once __DIR__ . '/_stub-agent-mode-registry.php';

$GLOBALS['ec_roadie_test_actions'] = array();
$GLOBALS['ec_roadie_test_filters'] = array();

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

function ec_roadie_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

require_once dirname( __DIR__ ) . '/inc/agent-mode/register.php';

// Trigger the action so registration runs.
do_action( 'datamachine_agent_modes' );

$mode = \DataMachine\Engine\AI\AgentModeRegistry::get( 'roadie' );

ec_roadie_smoke_assert( is_array( $mode ), 'roadie mode should be registered with AgentModeRegistry.' );
ec_roadie_smoke_assert( 'roadie' === ( $mode['id'] ?? '' ), 'roadie mode id should be "roadie".' );
ec_roadie_smoke_assert( 45 === ( $mode['priority'] ?? 0 ), 'roadie mode priority should be 45 (after editor mode at 40).' );
ec_roadie_smoke_assert( 'Extra Chill Platform' === ( $mode['label'] ?? '' ), 'roadie mode label should be "Extra Chill Platform".' );
ec_roadie_smoke_assert( '' !== trim( $mode['description'] ?? '' ), 'roadie mode description should be non-empty.' );

// Invoke the directive filter with a payload carrying a calling user — guidance
// should contain the user-scoped identity line.
$guidance_with_caller = apply_filters( 'datamachine_agent_mode_roadie', '', array( 'calling_user_id' => 38 ) );
ec_roadie_smoke_assert( is_string( $guidance_with_caller ) && '' !== $guidance_with_caller, 'datamachine_agent_mode_roadie filter should return non-empty guidance.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'user #38' ), 'Guidance should template the calling user ID when present.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'Network Topology' ), 'Guidance should cover network topology.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'manage_artist_profile' ), 'Guidance should reference manage_artist_profile tool.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'manage_link_page' ), 'Guidance should reference manage_link_page tool.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'manage_user_profile' ), 'Guidance should reference manage_user_profile tool.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'manage_community' ), 'Guidance should reference manage_community tool.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'Calling-User Identity Contract' ), 'Guidance should document the calling-user identity contract.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'Editorial Voice' ), 'Guidance should document editorial voice.' );
ec_roadie_smoke_assert( str_contains( $guidance_with_caller, 'Operating Mode' ), 'Guidance should document operating mode (propose-then-act for writes).' );

// Without a caller, guidance should explain that user-scoped writes have no default.
$guidance_no_caller = apply_filters( 'datamachine_agent_mode_roadie', '', array() );
ec_roadie_smoke_assert( str_contains( $guidance_no_caller, 'no human caller' ), 'Guidance should explain the no-caller case for system tasks.' );

// Filter should compose with prior content rather than replace it.
$composed = apply_filters( 'datamachine_agent_mode_roadie', "# Existing\nPrior directive content.", array() );
ec_roadie_smoke_assert( str_starts_with( $composed, "# Existing\nPrior directive content." ), 'Filter should append to existing directive content, not overwrite it.' );

echo "Roadie agent mode registration smoke passed (14 assertions).\n";
