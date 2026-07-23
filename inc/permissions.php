<?php
/**
 * Permission bridges — connects EC team membership to DM agent access.
 *
 * @package ExtraChillRoadie
 * @since 0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DataMachine\Core\Database\Agents\Agents;

/**
 * Canonical Roadie agent slug.
 */
const EXTRACHILL_ROADIE_AGENT_SLUG = 'roadie';

/**
 * Canonical Roadie agent name.
 */
const EXTRACHILL_ROADIE_AGENT_NAME = 'Roadie';

/**
 * Role tier: public visitor (logged out, or no team capability).
 */
const EXTRACHILL_ROADIE_TIER_PUBLIC = 'public';

/**
 * Role tier: Extra Chill team member (`access_roadie` / `extra_chill_team`).
 */
const EXTRACHILL_ROADIE_TIER_TEAM = 'team';

/**
 * Role tier: administrator (`manage_options`).
 */
const EXTRACHILL_ROADIE_TIER_ADMIN = 'admin';

/**
 * Resolve a user's Roadie role tier.
 *
 * This is the ONE auditable capability→tier mapping for the whole Roadie
 * surface. The role-aware guidance (inc/agent-mode/register.php) and the
 * per-tier tool-visibility filter (inc/tools/register.php) both consume it,
 * so the tier boundaries live in exactly one place.
 *
 * Tiers (highest wins):
 *   - `admin`  — `manage_options`. Everything, plus acting on behalf of other
 *                users via an explicit `user_id`.
 *   - `team`   — `access_roadie` (granted by the `extra_chill_team` role on
 *                every site; extrachill-users#45). Full platform management
 *                tool surface + code-contribution actions.
 *   - `public` — everyone else (logged out, or a logged-in user without team
 *                capability). Individual tools and target routes still apply
 *                their own resource authorization.
 *
 * A user_id of 0 (no human caller — system task, scheduled job, background
 * pipeline) resolves to `public`: there is no authenticated actor to grant
 * the team/admin surface to, and user-scoped writes are inappropriate without
 * a caller. System tasks reach abilities directly with their own gates.
 *
 * @since 0.10.0
 *
 * @param int $user_id User ID to resolve. 0 means "no human caller".
 * @return string One of `public`, `team`, `admin`.
 */
function extrachill_roadie_user_tier( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return EXTRACHILL_ROADIE_TIER_PUBLIC;
	}

	if ( user_can( $user_id, 'manage_options' ) ) {
		return EXTRACHILL_ROADIE_TIER_ADMIN;
	}

	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap granted by the extra_chill_team role (extrachill-users#45).
	if ( user_can( $user_id, 'access_roadie' ) ) {
		return EXTRACHILL_ROADIE_TIER_TEAM;
	}

	return EXTRACHILL_ROADIE_TIER_PUBLIC;
}

/**
 * Resolve redirect URIs allowed for browser-based bridge auth.
 *
 * Keep this explicit and auditable. Same-site callbacks are already allowed by
 * Data Machine core; this filter is for external bridge callback URLs.
 *
 * Override in environment-specific code with:
 * add_filter( 'extrachill_roadie_allowed_redirect_uris', fn() => array( ... ) );
 *
 * @since 0.3.1
 * @return string[]
 */
function extrachill_roadie_allowed_redirect_uris(): array {
	$defaults = array();

	/**
	 * Filter the external redirect URIs allowed for Roadie authorization.
	 *
	 * @since 0.3.1
	 *
	 * @param string[] $defaults Default patterns.
	 */
	$uris = apply_filters( 'extrachill_roadie_allowed_redirect_uris', $defaults );

	if ( ! is_array( $uris ) ) {
		return $defaults;
	}

	return array_values( array_filter( array_map( 'strval', $uris ) ) );
}

/**
 * Grant team members viewer access to the roadie agent.
 *
 * Hooks Data Machine's user-centric access filter and elevates EC team members
 * to Roadie viewer access. The canonical Agents API equivalent is registered
 * separately below so ability and Data Machine callers share one policy. This
 * is the platform-specific policy layer: Data Machine knows nothing
 * about Extra Chill team membership; extrachill-roadie is the
 * one-and-only place where EC-specific knowledge meets DM's generic
 * permission surface.
 *
 * Team membership is sourced from the `access_studio` capability —
 * granted by the `extra_chill_team` role (extrachill-users#45) on
 * every site in the network. We use `user_can()` directly rather
 * than `ec_is_team_member()` so this function stays a thin policy
 * shim that can be reasoned about by reading just this file.
 *
 * @since 0.3.0
 *
 * @param bool   $can_access   Whether the user can access the agent.
 * @param int    $agent_id     Agent ID.
 * @param int    $user_id      User ID.
 * @param string $minimum_role Minimum role required. Team entitlement satisfies viewer only.
 * @return bool
 */
function extrachill_roadie_team_access_bridge( bool $can_access, int $agent_id, int $user_id, string $minimum_role ): bool {
	// If already granted, no need to check further.
	if ( $can_access ) {
		return true;
	}

	// Team membership is the additive viewer entitlement used by the widget.
	// Operator and admin semantics require canonical Agents API grants.
	if ( 'viewer' !== $minimum_role ) {
		return false;
	}

	// Only apply to the roadie agent.
	$roadie_agent_id = extrachill_roadie_get_agent_id();
	if ( $roadie_agent_id <= 0 || $agent_id !== $roadie_agent_id ) {
		return $can_access;
	}

	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap granted by the extra_chill_team role (extrachill-users#45).
	if ( user_can( $user_id, 'access_roadie' ) ) {
		return true;
	}

	return $can_access;
}
add_filter( 'datamachine_can_access_agent', 'extrachill_roadie_team_access_bridge', 10, 4 );

/**
 * Grant the same viewer-only team entitlement through canonical Agents API.
 *
 * Agents API abilities, including `agents/chat`, authorize through
 * `wp_agent_can_access_agent`. Roadie owns the Extra Chill team policy, so it
 * must publish that policy at the canonical substrate seam rather than depend
 * on a consumer-specific bridge being loaded.
 *
 * @since 0.20.0
 *
 * @param bool                                           $can_access   Store-derived access decision.
 * @param \AgentsAPI\AI\WP_Agent_Execution_Principal      $principal    Canonical execution principal.
 * @param string                                         $agent_id     Agent slug or numeric ID.
 * @param string                                         $minimum_role Minimum role required.
 * @param array                                          $context      Host authorization context.
 * @return bool
 */
function extrachill_roadie_canonical_team_access_bridge( $can_access, $principal, $agent_id, $minimum_role, $context = array() ): bool {
	unset( $context );

	if ( $can_access ) {
		return true;
	}

	if ( 'viewer' !== $minimum_role || ! $principal instanceof \AgentsAPI\AI\WP_Agent_Execution_Principal || $principal->acting_user_id <= 0 ) {
		return false;
	}

	$is_roadie = EXTRACHILL_ROADIE_AGENT_SLUG === sanitize_title( (string) $agent_id );
	if ( is_numeric( $agent_id ) ) {
		$is_roadie = (int) $agent_id === extrachill_roadie_get_agent_id();
	}

	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom cap granted by the extra_chill_team role.
	return $is_roadie && user_can( $principal->acting_user_id, 'access_roadie' );
}
add_filter( 'wp_agent_can_access_agent', 'extrachill_roadie_canonical_team_access_bridge', 10, 5 );

/**
 * Check whether the current user may see the Roadie chat surface at all.
 *
 * This is the additive Extra Chill entitlement path. Canonical Agents API
 * grants remain authoritative for non-team users and are preserved by the
 * widget filters below.
 *
 * @since 0.19.0
 *
 * @return bool Whether the current user may see Roadie chat surfaces.
 */
function extrachill_roadie_current_user_can_see_widget(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$tier = extrachill_roadie_user_tier( get_current_user_id() );

	return EXTRACHILL_ROADIE_TIER_TEAM === $tier || EXTRACHILL_ROADIE_TIER_ADMIN === $tier;
}

/**
 * Decide frontend chat widget visibility for the Roadie agent.
 *
 * Hooks Frontend Agent Chat's visibility decision (`frontend_agent_chat_user_can_see`)
 * and preserves the canonical grant-derived decision. Team/admin capability
 * is an additive entitlement for Roadie, never a replacement for Agents API
 * viewer/operator/admin grants.
 *
 * @since 0.19.0
 *
 * @param bool       $allowed Access decision from Agents API.
 * @param array|null $agent   Resolved agent descriptor.
 * @return bool
 */
function extrachill_roadie_gate_widget_visibility( bool $allowed, ?array $agent ): bool {
	$agent_slug = sanitize_title( (string) ( $agent['agent_slug'] ?? $agent['slug'] ?? '' ) );
	if ( EXTRACHILL_ROADIE_AGENT_SLUG !== $agent_slug ) {
		return $allowed;
	}

	return $allowed || extrachill_roadie_current_user_can_see_widget();
}
add_filter( 'frontend_agent_chat_user_can_see', 'extrachill_roadie_gate_widget_visibility', 10, 2 );

/**
 * Reconcile Roadie's presence in the frontend chat agent selector.
 *
 * The widget enqueue path renders whenever the accessible-agents list is
 * non-empty, so the selector list is the load-bearing gate for whether the
 * FAB appears at all. Preserve every canonical accessible-agent result and
 * append Roadie only for the additive team/admin capability path when the
 * grant-derived list omitted it.
 *
 * @since 0.19.0
 *
 * @param array $agents Accessible agents for the frontend selector.
 * @return array
 */
function extrachill_roadie_gate_widget_agent_list( $agents ): array {
	if ( ! is_array( $agents ) ) {
		$agents = array();
	}

	$has_roadie = false;
	foreach ( $agents as $agent ) {
		$agent_slug = sanitize_title( (string) ( $agent['agent_slug'] ?? $agent['slug'] ?? '' ) );
		if ( EXTRACHILL_ROADIE_AGENT_SLUG === $agent_slug ) {
			$has_roadie = true;
			break;
		}
	}

	if ( $has_roadie || ! extrachill_roadie_current_user_can_see_widget() ) {
		return array_values( $agents );
	}

	$roadie = extrachill_roadie_get_agent();
	if ( ! $roadie ) {
		return array_values( $agents );
	}

	$config = is_array( $roadie['agent_config'] ?? null ) ? $roadie['agent_config'] : array();

	$agents[] = array(
		'agent_slug'        => EXTRACHILL_ROADIE_AGENT_SLUG,
		'agent_name'        => (string) ( $roadie['agent_name'] ?? EXTRACHILL_ROADIE_AGENT_NAME ),
		'agent_description' => (string) ( $config['description'] ?? '' ),
		'meta'              => array(),
	);

	return array_values( $agents );
}
add_filter( 'frontend_agent_chat_accessible_agents', 'extrachill_roadie_gate_widget_agent_list', 10, 1 );

/**
 * Map Data Machine Events' write capability to the EC team cap.
 *
 * data-machine-events defaults its write gate to `edit_others_posts`
 * (administrators + editors only). On Extra Chill, the team role
 * (`extra_chill_team`) is the natural trust pool for events admin
 * because team contributors are journalists who need to fix venue
 * data, merge duplicates, and run geocoding sweeps without being
 * site administrators. The role grants `access_events_admin`, which
 * we wire up as DME's write cap on every EC subsite.
 *
 * Pure policy bridge — DME knows nothing about EC; EC's integration
 * layer (this plugin) overrides DME's default via its filter API.
 *
 * @since 0.7.0
 * @return string
 */
function extrachill_roadie_events_write_capability(): string {
	return 'access_events_admin';
}
add_filter( 'datamachine_events_write_capability', 'extrachill_roadie_events_write_capability' );

/**
 * Same shape for DME's diagnostic read gate.
 *
 * Currently the only read ability hooked through DME's filter is
 * unused by default, but providing the bridge here keeps the
 * mapping consistent should a read-gated ability be added later.
 *
 * @since 0.7.0
 * @return string
 */
function extrachill_roadie_events_read_capability(): string {
	return 'access_events_admin';
}
add_filter( 'datamachine_events_read_capability', 'extrachill_roadie_events_read_capability' );

/**
 * Get the roadie agent ID from the frontend chat config.
 *
 * Resolves the agent slug from the current site's config and caches it.
 *
 * @since 0.3.0
 *
 * @return int Agent ID, or 0 if not configured.
 */
function extrachill_roadie_get_agent_id(): int {
	static $agent_id = null;

	if ( null !== $agent_id ) {
		return $agent_id;
	}

	$agent_id = 0;

	if ( ! function_exists( 'frontend_agent_chat_get_config' ) ) {
		return $agent_id;
	}

	$agent = extrachill_roadie_get_agent();
	if ( $agent && ! empty( $agent['agent_id'] ) ) {
		$agent_id = (int) $agent['agent_id'];
	}

	return $agent_id;
}

/**
 * Get the canonical Roadie agent row.
 *
 * @since 0.3.1
 * @return array|null
 */
function extrachill_roadie_get_agent(): ?array {
	static $agent = null;

	if ( null !== $agent ) {
		return $agent;
	}

	if ( ! class_exists( Agents::class ) ) {
		return null;
	}

	$repo  = new Agents();
	$agent = $repo->get_by_slug( EXTRACHILL_ROADIE_AGENT_SLUG );

	return $agent;
}

/**
 * Ensure Roadie exists and its auth policy stays explicit.
 *
 * - canonical slug/name
 * - active status
 * - explicit redirect allowlist for external bridge callbacks
 *
 * This is intentionally narrow and auditable.
 *
 * @since 0.3.1
 * @return void
 */
function extrachill_roadie_sync_agent_policy(): void {
	if ( ! class_exists( Agents::class ) ) {
		return;
	}

	$repo  = new Agents();
	$agent = $repo->get_by_slug( EXTRACHILL_ROADIE_AGENT_SLUG );

	if ( ! $agent ) {
		return;
	}

	$config               = is_array( $agent['agent_config'] ?? null ) ? $agent['agent_config'] : array();
	$existing_redirects   = array_values( array_filter( array_map( 'strval', $config['allowed_redirect_uris'] ?? array() ) ) );
	$configured_redirects = extrachill_roadie_allowed_redirect_uris();
	$merged_redirects     = array_values( array_unique( array_merge( $existing_redirects, $configured_redirects ) ) );

	$needs_update = false;

	if ( ( $agent['agent_name'] ?? '' ) !== EXTRACHILL_ROADIE_AGENT_NAME ) {
		$needs_update = true;
	}

	if ( ( $agent['status'] ?? '' ) !== 'active' ) {
		$needs_update = true;
	}

	if ( $merged_redirects !== $existing_redirects ) {
		$needs_update = true;
	}

	if ( ! $needs_update ) {
		return;
	}

	$config['allowed_redirect_uris'] = $merged_redirects;

	$updates = array(
		'agent_name'   => EXTRACHILL_ROADIE_AGENT_NAME,
		'status'       => 'active',
		'agent_config' => $config,
	);

	$repo->update_agent( (int) $agent['agent_id'], $updates );
}
