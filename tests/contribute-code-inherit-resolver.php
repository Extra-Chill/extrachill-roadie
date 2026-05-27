<?php
/**
 * Smoke tests for the wp_codebox_resolve_inheritance bridge.
 *
 * Run with: php tests/contribute-code-inherit-resolver.php
 *
 * @package ExtraChillRoadie\Tests
 */

require_once __DIR__ . '/contribute-code-bootstrap.php';

// --- Test 1: openai connector with credential present resolves to metadata --
$GLOBALS['extrachill_roadie_test_state']['options']['connectors_ai_openai_api_key'] = 'sk-proj-FAKE_TEST_KEY_value_123';
putenv( 'OPENAI_API_KEY=' ); // clear

$request = array(
	'connectors' => array( 'openai' ),
	'settings'   => array(),
);
$resolution = array(
	'connectors' => array(
		array( 'name' => 'openai', 'status' => 'unresolved' ),
	),
	'settings'   => array(),
);

$result = extrachill_roadie_resolve_inheritance( $resolution, $request, array() );

roadie_test_assert( is_array( $result ), 'resolver must return an array' );
roadie_test_assert( ! empty( $result['connectors'][0] ), 'connector entry must be present' );
$openai = $result['connectors'][0];
roadie_test_assert( 'openai' === $openai['name'], 'connector name preserved' );
roadie_test_assert( 'resolved' === $openai['status'], 'status must be resolved when option has a value' );
roadie_test_assert( 'openai' === $openai['provider'], 'provider populated from default map' );
roadie_test_assert( 'gpt-5' === $openai['model'], 'model populated from default map' );
roadie_test_assert(
	in_array( 'OPENAI_API_KEY', $openai['secretEnv'], true ),
	'secretEnv must include OPENAI_API_KEY'
);
roadie_test_assert(
	'sk-proj-FAKE_TEST_KEY_value_123' === getenv( 'OPENAI_API_KEY' ),
	'resolver must putenv() the credential value so wp-codebox secret_env can carry the name'
);

// --- Test 2: missing credential reports status=missing-credential -------
$GLOBALS['extrachill_roadie_test_state']['options']['connectors_ai_openai_api_key'] = '';
putenv( 'OPENAI_API_KEY=' );

$resolution2 = array(
	'connectors' => array(
		array( 'name' => 'openai', 'status' => 'unresolved' ),
	),
	'settings'   => array(),
);
$result2 = extrachill_roadie_resolve_inheritance( $resolution2, $request, array() );
roadie_test_assert(
	'missing-credential' === ( $result2['connectors'][0]['status'] ?? '' ),
	'empty credential must report missing-credential status'
);

// --- Test 3: unknown connector left as-is -------------------------------
roadie_test_reset_filters();
$resolution3 = array(
	'connectors' => array(
		array( 'name' => 'totally-fake-connector', 'status' => 'unresolved' ),
	),
	'settings'   => array(),
);
$result3 = extrachill_roadie_resolve_inheritance(
	$resolution3,
	array( 'connectors' => array( 'totally-fake-connector' ), 'settings' => array() ),
	array()
);
roadie_test_assert(
	'unresolved' === ( $result3['connectors'][0]['status'] ?? '' ),
	'unknown connector must remain unresolved (other plugins can resolve it via the same filter)'
);

// --- Test 4: filter override of connector map ---------------------------
roadie_test_reset_filters();
add_filter(
	'extrachill_roadie_inherit_connectors',
	function ( array $map ) {
		$map['custom'] = array(
			'provider'   => 'custom-provider',
			'model'      => 'custom-model-v2',
			'secret_env' => array( 'CUSTOM_API_KEY' ),
			'option_key' => 'my_custom_api_option',
			'env_var'    => 'CUSTOM_API_KEY',
		);
		return $map;
	}
);
$GLOBALS['extrachill_roadie_test_state']['options']['my_custom_api_option'] = 'custom-val';
putenv( 'CUSTOM_API_KEY=' );

$resolution4 = array(
	'connectors' => array(
		array( 'name' => 'custom', 'status' => 'unresolved' ),
	),
	'settings'   => array(),
);
$result4 = extrachill_roadie_resolve_inheritance(
	$resolution4,
	array( 'connectors' => array( 'custom' ), 'settings' => array() ),
	array()
);
roadie_test_assert(
	'resolved' === $result4['connectors'][0]['status'],
	'filter-added connector resolves'
);
roadie_test_assert(
	'custom-provider' === $result4['connectors'][0]['provider'],
	'filter-added provider'
);
roadie_test_assert(
	'custom-val' === getenv( 'CUSTOM_API_KEY' ),
	'filter-added env var got exported'
);

// Cleanup envs we set.
putenv( 'OPENAI_API_KEY=' );
putenv( 'CUSTOM_API_KEY=' );

echo "contribute-code inherit-resolver smoke passed.\n";
