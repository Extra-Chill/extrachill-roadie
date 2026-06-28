<?php
/**
 * Roadie SOUL sync.
 *
 * Roadie's identity (SOUL.md) ships as code in this plugin — `agent/SOUL.md`
 * at the plugin root is the canonical source of truth, committed and deployed
 * like any other code. This mirrors the bundled-agent precedent set by
 * extrachill-ai-adventure, where the game-master SOUL.md lives in the plugin
 * dir and is read directly rather than hand-maintained in the runtime store.
 *
 * Roadie differs from that precedent in one mechanical detail: it is a Data
 * Machine agent (a persisted `agents` row, slug `roadie`), not an agents-api
 * bundled agent. Data Machine injects an agent's SOUL.md from its per-agent
 * file store (the `agent` memory layer) when an execution mode activates the
 * `agent_identity` injection context. There is no plugin-path read seam for a
 * DM agent's SOUL the way agents-api offers `memory_seeds` for a code-bundled
 * agent. So we make the repo file canonical the only way the DM seam allows:
 * an idempotent sync that writes `agent/SOUL.md` into the agent-file store
 * whenever the committed content differs from what's on disk. The on-disk copy
 * becomes a deployment artifact of the committed file — edit the repo, deploy,
 * and the next request reconciles the store.
 *
 * This keeps the layering honest:
 *   - SOUL (who Roadie IS) ships in the repo and deploys like code.
 *   - MODE (operating context) lives in inc/agent-mode/register.php.
 *   - MEMORY (accumulated runtime state) stays in the store, never overwritten.
 *
 * The sync only ever touches SOUL.md. It never touches MEMORY.md, daily memory,
 * or any other agent file — those are runtime state owned by the agent.
 *
 * @package ExtraChillRoadie\AgentMode
 * @since 0.15.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\FilesRepository\AgentMemory;

/**
 * Absolute path to the committed Roadie SOUL.md.
 *
 * @since 0.15.0
 * @return string
 */
function extrachill_roadie_soul_path(): string {
	return EXTRACHILL_ROADIE_PLUGIN_DIR . 'agent/SOUL.md';
}

/**
 * Read the committed Roadie SOUL.md content.
 *
 * @since 0.15.0
 * @return string Empty string when the bundled file is missing/unreadable.
 */
function extrachill_roadie_get_soul(): string {
	$path = extrachill_roadie_soul_path();

	if ( ! is_readable( $path ) ) {
		return '';
	}

	$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a plugin-bundled file by absolute path, no remote/URL access.

	return false === $contents ? '' : (string) $contents;
}

/**
 * Sync the committed SOUL into Roadie's agent-file store.
 *
 * Idempotent: writes only when the store copy is absent or differs from the
 * committed bytes, so steady-state requests do zero IO beyond a read. Targets
 * the resolved `roadie` agent row by ID, in the agent memory layer, with
 * user_id 0 (the agent's own identity file, not a per-user file).
 *
 * Degrades gracefully:
 *   - no Data Machine (AgentMemory class) → no-op
 *   - roadie agent row not yet provisioned → no-op
 *   - empty/unreadable bundled SOUL → no-op (never blanks a live SOUL)
 *
 * @since 0.15.0
 * @return void
 */
function extrachill_roadie_sync_soul(): void {
	if ( ! class_exists( AgentMemory::class ) ) {
		return;
	}

	$agent_id = extrachill_roadie_get_agent_id();
	if ( $agent_id <= 0 ) {
		return;
	}

	$soul = extrachill_roadie_get_soul();
	if ( '' === trim( $soul ) ) {
		// Never overwrite a live SOUL with an empty/missing bundle.
		return;
	}

	$memory  = new AgentMemory( 0, $agent_id, 'SOUL.md' );
	$current = $memory->read();

	// Idempotent: skip the write when the store already matches the bundle.
	if ( $current->exists && (string) $current->content === $soul ) {
		return;
	}

	$memory->replace_all( $soul );
}
