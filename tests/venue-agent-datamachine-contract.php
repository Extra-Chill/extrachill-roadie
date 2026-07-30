<?php
/** Integration coverage for Data Machine's definition-only reconciliation contract. */

declare(strict_types=1);

namespace AgentsAPI\Core\Identity {
	class WP_Agent_Identity_Scope {
		public string $agent_slug;
		public int $owner_user_id;
		public string $instance_key;

		public function __construct( string $agent_slug, int $owner_user_id, string $instance_key = 'default' ) {
			$this->agent_slug    = $agent_slug;
			$this->owner_user_id = $owner_user_id;
			$this->instance_key  = $instance_key;
		}
	}

	// Mirrors the real AgentsAPI class. An empty placeholder here shadows the
	// genuine declaration during static analysis, which reports every valid
	// $id / $scope access in plugin source as an undefined property.
	class WP_Agent_Materialized_Identity {
		public function __construct(
			public readonly int $id = 1,
			public readonly ?WP_Agent_Identity_Scope $scope = null
		) {}
	}
}

namespace DataMachine\Core\Identity {
	class AgentIdentityStoreAdapter {
		public static int $resolve_calls = 0;
		public static int $materialize_calls = 0;

		public function resolve() {
			++self::$resolve_calls;
			return null;
		}

		public function materialize() {
			++self::$materialize_calls;
			return null;
		}
	}
}

namespace DataMachine\Core\FilesRepository {
	class DirectoryManager {
		public static int $default_owner_calls = 0;

		public static function get_default_agent_user_id(): int {
			++self::$default_owner_calls;
			return 99;
		}
	}
}

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	define( 'EXTRACHILL_ROADIE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

	$GLOBALS['_dm_contract_actions'] = array();
	$GLOBALS['_dm_contract_agent']   = null;

	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['_dm_contract_actions'][ $hook ][] = $callback;
	}

	function __( $text ): string {
		return (string) $text;
	}

	function wp_register_agent( string $slug, array $args ): object {
		$GLOBALS['_dm_contract_agent'] = compact( 'slug', 'args' );
		return (object) array();
	}

	function wp_get_agent() {
		throw new RuntimeException( 'Definition-only reconciliation must not resolve a default agent.' );
	}

	function contract_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	$data_machine_root = $argv[1] ?? getenv( 'DATA_MACHINE_PR_ROOT' ) ?: '';
	$materializer      = $data_machine_root . '/inc/Engine/Agents/AgentMaterializer.php';
	if ( ! is_file( $materializer ) ) {
		fwrite( STDERR, "Usage: php tests/venue-agent-datamachine-contract.php /path/to/data-machine-pr-head\n" );
		exit( 1 );
	}

	require $materializer;
	require dirname( __DIR__ ) . '/inc/venue-agent-instances.php';

	extrachill_roadie_register_venue_agent();
	$registered = $GLOBALS['_dm_contract_agent'];
	$summary    = DataMachine\Engine\Agents\AgentMaterializer::reconcile(
		array( $registered['slug'] => $registered['args'] )
	);

	contract_assert(
		array(
			'created'         => array(),
			'existing'        => array(),
			'definition_only' => array( 'roadie-venue' ),
			'skipped'         => array(),
		) === $summary,
		'Approved Data Machine reconciliation must report Roadie as definition-only.'
	);
	contract_assert( 0 === DataMachine\Core\Identity\AgentIdentityStoreAdapter::$resolve_calls, 'Definition-only reconciliation must not query or create a default row.' );
	contract_assert( 0 === DataMachine\Core\Identity\AgentIdentityStoreAdapter::$materialize_calls, 'Definition-only reconciliation must not provision access, directory, scaffold, or hooks.' );
	contract_assert( 0 === DataMachine\Core\FilesRepository\DirectoryManager::$default_owner_calls, 'Definition-only reconciliation must not resolve the global default owner.' );

	echo "Roadie Data Machine definition-only contract passed.\n";
}
