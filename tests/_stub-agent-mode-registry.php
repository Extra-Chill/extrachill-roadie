<?php
/**
 * Test stub for \DataMachine\Engine\AI\AgentModeRegistry.
 *
 * Mirrors the production class shape just enough for smoke tests to register
 * and read back agent modes without booting Data Machine.
 *
 * @package ExtraChillRoadie\Tests
 */

namespace DataMachine\Engine\AI;

class AgentModeRegistry {
	public static array $registered = array();

	public static function register( string $id, int $priority = 50, array $args = array() ): void {
		self::$registered[ $id ] = array(
			'id'          => $id,
			'priority'    => $priority,
			'label'       => $args['label'] ?? $id,
			'description' => $args['description'] ?? '',
		);
	}

	public static function get( string $id ): ?array {
		return self::$registered[ $id ] ?? null;
	}

	public static function reset(): void {
		self::$registered = array();
	}
}
