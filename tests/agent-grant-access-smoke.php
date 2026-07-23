<?php
/**
 * Regression coverage for canonical agent grants and additive team access.
 *
 * Run with: php tests/agent-grant-access-smoke.php
 *
 * @package ExtraChillRoadie\Tests
 */

declare(strict_types=1);

namespace AgentsAPI\AI {
	class WP_Agent_Execution_Principal {
		public function __construct( public int $acting_user_id ) {}
	}
}

namespace DataMachine\Core\Database\Agents {
	class Agents {
		public function get_by_slug( string $slug ): ?array {
			if ( 'roadie' !== $slug ) {
				return null;
			}

			return array(
				'agent_id'     => 77,
				'agent_slug'   => 'roadie',
				'agent_name'   => 'Roadie',
				'agent_config' => array( 'description' => 'Extra Chill assistant.' ),
			);
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}

	$GLOBALS['roadie_access_filters']      = array();
	$GLOBALS['roadie_access_current_user'] = 0;
	$GLOBALS['roadie_access_caps']         = array();

	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $priority, $accepted_args );
		$GLOBALS['roadie_access_filters'][ $hook ][] = $callback;
	}

	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['roadie_access_filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}

	function sanitize_title( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( $value ) ) ?? '' );
	}

	function get_current_user_id(): int {
		return (int) $GLOBALS['roadie_access_current_user'];
	}

	function is_user_logged_in(): bool {
		return get_current_user_id() > 0;
	}

	function user_can( int $user_id, string $capability ): bool {
		return ! empty( $GLOBALS['roadie_access_caps'][ $user_id ][ $capability ] );
	}

	function frontend_agent_chat_get_config(): array {
		return array( 'agent_slug' => 'roadie' );
	}

	function roadie_access_login( int $user_id, array $caps = array() ): void {
		$GLOBALS['roadie_access_current_user'] = $user_id;
		$GLOBALS['roadie_access_caps'][ $user_id ] = array_fill_keys( $caps, true );
	}

	function roadie_access_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	require_once dirname( __DIR__ ) . '/inc/permissions.php';

	$roadie = array( 'agent_slug' => 'roadie' );
	$other  = array( 'agent_slug' => 'other-agent' );

	foreach ( array( 'viewer', 'operator', 'admin' ) as $role ) {
		roadie_access_login( 100 );
		roadie_access_assert(
			extrachill_roadie_gate_widget_visibility( true, $roadie ),
			"Canonical {$role} grant should remain visible for a non-team user."
		);
		roadie_access_assert(
			extrachill_roadie_team_access_bridge( true, 77, 100, $role ),
			"Canonical {$role} grant should survive the team access bridge."
		);
	}

	roadie_access_login( 101 );
	roadie_access_assert( ! extrachill_roadie_gate_widget_visibility( false, $roadie ), 'Unrelated user should not gain Roadie visibility.' );
	roadie_access_assert( ! extrachill_roadie_team_access_bridge( false, 77, 101, 'viewer' ), 'Unrelated user should not gain agent access.' );
	roadie_access_assert( ! extrachill_roadie_canonical_team_access_bridge( false, new \AgentsAPI\AI\WP_Agent_Execution_Principal( 101 ), 'roadie', 'viewer' ), 'Unrelated user should not gain canonical agent access.' );
	roadie_access_assert( ! extrachill_roadie_pending_action_permission( false, array() ), 'Unrelated user should not resolve pending actions.' );

	// A revoked grant reaches these filters as false/absent and must stay revoked.
	roadie_access_assert( ! extrachill_roadie_gate_widget_visibility( false, $roadie ), 'Revoked grant should remain hidden.' );
	roadie_access_assert( array() === extrachill_roadie_gate_widget_agent_list( array() ), 'Revoked grant should not be restored to the accessible-agent list.' );

	roadie_access_login( 102, array( 'access_roadie' ) );
	roadie_access_assert( extrachill_roadie_gate_widget_visibility( false, $roadie ), 'Team capability should add Roadie widget visibility.' );
	roadie_access_assert( extrachill_roadie_team_access_bridge( false, 77, 102, 'viewer' ), 'Team capability should add agent access.' );
	roadie_access_assert( ! extrachill_roadie_team_access_bridge( false, 77, 102, 'operator' ), 'Team capability must not satisfy the canonical operator role.' );
	roadie_access_assert( ! extrachill_roadie_team_access_bridge( false, 77, 102, 'admin' ), 'Team capability must not satisfy the canonical admin role.' );
	$team_principal = new \AgentsAPI\AI\WP_Agent_Execution_Principal( 102 );
	roadie_access_assert( extrachill_roadie_canonical_team_access_bridge( false, $team_principal, 'roadie', 'viewer' ), 'Team capability should add canonical Roadie viewer access.' );
	roadie_access_assert( extrachill_roadie_canonical_team_access_bridge( false, $team_principal, '77', 'viewer' ), 'Team capability should support canonical numeric Roadie identity.' );
	roadie_access_assert( ! extrachill_roadie_canonical_team_access_bridge( false, $team_principal, 'roadie', 'operator' ), 'Team capability must not satisfy canonical operator access.' );
	roadie_access_assert( ! extrachill_roadie_canonical_team_access_bridge( false, $team_principal, 'other-agent', 'viewer' ), 'Team capability must not widen another canonical agent.' );
	roadie_access_assert( extrachill_roadie_canonical_team_access_bridge( true, new \AgentsAPI\AI\WP_Agent_Execution_Principal( 100 ), 'roadie', 'admin' ), 'Canonical grants must survive the Roadie bridge.' );
	roadie_access_assert( extrachill_roadie_pending_action_permission( false, array() ), 'Team capability should reach owner-scoped pending action resolution.' );
	roadie_access_assert( extrachill_roadie_pending_action_permission( true, array() ), 'Existing pending action permission must survive the Roadie bridge.' );
	$team_agents = extrachill_roadie_gate_widget_agent_list( array() );
	roadie_access_assert( 'roadie' === ( $team_agents[0]['agent_slug'] ?? '' ), 'Team capability should append Roadie when the canonical list omits it.' );

	roadie_access_login( 103 );
	$granted_agents = array(
		array(
			'agent_slug'        => 'roadie',
			'agent_name'        => 'Roadie',
			'agent_description' => 'Canonical grant.',
			'meta'              => array( 'role' => 'viewer' ),
		),
	);
	roadie_access_assert( $granted_agents === extrachill_roadie_gate_widget_agent_list( $granted_agents ), 'Canonical non-team accessible-agent entry should be preserved unchanged.' );
	roadie_access_assert( extrachill_roadie_gate_widget_visibility( true, $other ), 'Other-agent visibility decisions should remain untouched.' );
	roadie_access_assert( ! extrachill_roadie_gate_widget_visibility( false, $other ), 'Other-agent denials should remain untouched.' );

	echo "Roadie agent grant access smoke passed (24 assertions).\n";
}
