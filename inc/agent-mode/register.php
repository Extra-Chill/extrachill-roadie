<?php
/**
 * Roadie Agent Mode Registration
 *
 * Registers the `roadie` execution mode with Data Machine's AgentModeRegistry
 * and provides the mode-specific guidance string via the
 * `datamachine_agent_mode_roadie` filter.
 *
 * The mode is the operating context for the Extra Chill platform tool surface
 * — the artist profile, link page, user profile, and community forum tools.
 * Registration is intentionally agent-agnostic: any agent (extra-chill-bot,
 * roadie, or a custom one) can run in this mode and get the same platform
 * guidance composed into the prompt.
 *
 * Reference implementation: data-machine-editor registers `editor` mode the
 * same way at priority 40. We use priority 45 so editor mode wins in the
 * (rare) case both modes are active in the same invocation.
 *
 * @package ExtraChillRoadie\AgentMode
 * @since 0.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the `roadie` execution mode with Data Machine.
 *
 * Fires inside the `datamachine_agent_modes` action — the registry consumes
 * registrations the first time it resolves the mode list.
 *
 * @since 0.8.0
 * @return void
 */
add_action(
	'datamachine_agent_modes',
	function (): void {
		if ( ! class_exists( '\\DataMachine\\Engine\\AI\\AgentModeRegistry' ) ) {
			return;
		}

		\DataMachine\Engine\AI\AgentModeRegistry::register(
			'roadie',
			45,
			array(
				'label'       => __( 'Extra Chill Platform', 'extrachill-roadie' ),
				'description' => __(
					'Artist profiles, link pages, user profiles, community forum, and personal user-scoped operations on the EC multisite network.',
					'extrachill-roadie'
				),
			)
		);
	}
);

/**
 * Provide the `roadie` mode guidance via the directive filter.
 *
 * The filter callback receives the current directive content and the full
 * invocation payload. We append a focused mode guidance block that explains
 * the EC platform topology, the tool domains available *to this caller*, the
 * calling-user contract, editorial voice, and operating posture.
 *
 * The guidance is **role-aware**: the network topology, cross-site
 * conventions, and editorial voice are shared across all tiers, but the
 * available-tools section, the operating posture, and the identity block vary
 * by the caller's role tier (public / team / admin) as resolved by
 * extrachill_roadie_user_tier(). This keeps a public visitor from being told
 * about management tools they cannot use, while giving team/admin callers the
 * full manual. One function, DRY shared sections, branch on tier.
 *
 * @since 0.8.0
 * @since 0.10.0 Role-aware: guidance body varies by caller tier.
 *
 * @param string $content Current directive content for the roadie mode.
 * @param array  $payload Full AI invocation payload.
 * @return string Mode guidance composed with prior content.
 */
add_filter( 'datamachine_agent_mode_roadie', 'extrachill_roadie_mode_guidance', 10, 2 );

function extrachill_roadie_mode_guidance( string $content, array $payload ): string {
	$calling_user_id = 0;
	if ( function_exists( 'datamachine_get_calling_user_id' ) ) {
		$calling_user_id = datamachine_get_calling_user_id( $payload );
	} elseif ( isset( $payload['calling_user_id'] ) && is_numeric( $payload['calling_user_id'] ) ) {
		$calling_user_id = max( 0, (int) $payload['calling_user_id'] );
	}

	$tier = function_exists( 'extrachill_roadie_user_tier' )
		? extrachill_roadie_user_tier( $calling_user_id )
		: EXTRACHILL_ROADIE_TIER_PUBLIC;

	$guidance = extrachill_roadie_compose_guidance( $tier, $calling_user_id );

	if ( '' === trim( $content ) ) {
		return $guidance;
	}

	return $content . "\n\n" . $guidance;
}

/**
 * Compose the full mode guidance body for a given role tier.
 *
 * Assembles shared sections (topology, cross-site conventions, editorial
 * voice) with the tier-specific sections (available tools, identity contract,
 * operating posture). The shared sections are authored once and reused; only
 * the variable sections branch on tier.
 *
 * @since 0.10.0
 *
 * @param string $tier            Role tier: `public`, `team`, or `admin`.
 * @param int    $calling_user_id Resolved calling user ID (0 = no human caller).
 * @return string Composed guidance markdown.
 */
function extrachill_roadie_compose_guidance( string $tier, int $calling_user_id ): string {
	$topology = extrachill_roadie_guidance_topology();
	$voice    = extrachill_roadie_guidance_editorial_voice();

	if ( EXTRACHILL_ROADIE_TIER_PUBLIC === $tier ) {
		$intro    = 'This context is active when you operate against the Extra Chill multisite network — a publication and community platform for independent music. You are talking to a **visitor** (not signed in, or signed in without an Extra Chill team account). Help them explore and point them toward signing in when they want to manage their own stuff.';
		$tools    = extrachill_roadie_guidance_tools_public();
		$identity = extrachill_roadie_guidance_identity_public();
		$posture  = extrachill_roadie_guidance_posture_public();

		return extrachill_roadie_assemble_guidance( $intro, $topology, $tools, $identity, $voice, $posture );
	}

	// Team and admin share the full platform manual; the identity contract and a
	// short admin addendum are the only differences.
	$intro = 'This context is active when you operate against the Extra Chill multisite network — a publication and community platform for independent music. You have a tool surface for managing artist profiles, link pages, user profiles, and the community forum.';
	$tools = extrachill_roadie_guidance_tools_team( EXTRACHILL_ROADIE_TIER_ADMIN === $tier );

	$identity = EXTRACHILL_ROADIE_TIER_ADMIN === $tier
		? extrachill_roadie_guidance_identity_admin( $calling_user_id )
		: extrachill_roadie_guidance_identity_team( $calling_user_id );

	$posture = extrachill_roadie_guidance_posture_team();

	return extrachill_roadie_assemble_guidance( $intro, $topology, $tools, $identity, $voice, $posture );
}

/**
 * Assemble the guidance sections in canonical order.
 *
 * @since 0.10.0
 *
 * @param string $intro    Tier-specific intro line.
 * @param string $topology Shared network-topology section.
 * @param string $tools    Tier-specific available-tools section.
 * @param string $identity Tier-specific identity-contract section.
 * @param string $voice    Shared editorial-voice section.
 * @param string $posture  Tier-specific operating-posture section.
 * @return string
 */
function extrachill_roadie_assemble_guidance( string $intro, string $topology, string $tools, string $identity, string $voice, string $posture ): string {
	return "# Extra Chill Platform Context\n\n{$intro}\n\n{$topology}\n\n{$tools}\n\n{$identity}\n\n{$voice}\n\n{$posture}";
}

/**
 * Shared: network topology + cross-site conventions.
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_topology(): string {
	return <<<MD
## Network Topology

Extra Chill runs as a WordPress multisite across nine subdomains. Identity (users, roles, capabilities) is network-wide; content is per-site. The tools handle cross-site routing internally via the `ec_cross_site_rest_request()` helper — you do not need to think about which site a request lands on.

- **extrachill.com** — main publication (music news, features, reviews)
- **community.extrachill.com** — forums, topics, replies, notifications
- **artist.extrachill.com** — artist profiles and link pages
- **shop.extrachill.com** — merch and music store
- **events.extrachill.com** — events calendar
- **newsletter.extrachill.com** — newsletter management
- **docs.extrachill.com** — documentation
- **wire.extrachill.com** — news wire
- **studio.extrachill.com** — studio

## Cross-Site Conventions

- Identity is network-wide — a user has the same ID and capabilities on every site.
- Content lives on its origin site. The tools switch blogs internally; you do not.
- Permissions are checked against the route's target site, not the site you happen to be calling from.
- Cross-site reads via `switch_to_blog()` are safe; writes go through REST so capability checks run in the right context.
MD;
}

/**
 * Shared: editorial voice.
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_editorial_voice(): string {
	return <<<MD
## Editorial Voice

Extra Chill is an independent, grassroots music platform — not a corporate publication. When generating content (bios, topic posts, replies, profile copy):

- Music-forward, knowledgeable, approachable.
- "Extra chill" — relaxed but focused.
- Authentic, not promotional. Avoid marketing-speak, hype, and corporate filler.
- Respect the user's voice. Suggest, don't overwrite.
MD;
}

/**
 * Public tier: available tools (explore only — no management tools offered).
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_tools_public(): string {
	return <<<MD
## What You Can Help With

You are talking to a visitor, so you do **not** have the platform management tools right now — those unlock when an Extra Chill team member signs in. Keep it to exploring and explaining:

- Help them understand what Extra Chill is: an independent music publication + community for independent artists and fans.
- Point them to the right corner of the network for what they want (read the community forum, browse artist profiles, check out the news wire, the events calendar, the shop).
- If they want to manage their **own** artist profile, link page, user profile, or post in the community, tell them to sign in with an Extra Chill account — those actions need a logged-in team member, and the tools appear once they do.

Do not promise to perform management actions (creating/updating profiles, posting to the forum, filing feature requests, proposing code) — you cannot do those for a visitor. Be upfront and friendly about that boundary instead of attempting a tool you don't have.
MD;
}

/**
 * Team/admin tier: available tools (full platform management surface).
 *
 * @since 0.10.0
 *
 * @param bool $is_admin Whether to append the admin-only code-contribution note.
 * @return string
 */
function extrachill_roadie_guidance_tools_team( bool $is_admin ): string {
	$base = <<<MD
## Available Tool Domains

- `manage_artist_profile` — list, get, create, and update artist profiles. Auto-resolves `artist_id` when the calling user manages exactly one artist.
- `manage_link_page` — get, edit links, edit socials, edit styles, edit settings on an artist's public link page. Convenience actions (`add_link`, `remove_link`) handle the fetch-modify-save dance for you.
- `manage_user_profile` — read or update the user profile (bio, custom title, city, profile links). Defaults to the calling user; admins may target another user by passing `user_id`.
- `manage_community` — browse forums, list and read topics, post topics and replies, manage notifications on community.extrachill.com.
- `file_feature_request` — file a feature request as a GitHub issue when the user wants something the platform doesn't do yet.
- `propose_code_change` / `apply_code_change` — draft and apply a small code change to the platform when the user is making a concrete code contribution.

Reach for the tool whose domain matches the request. If the user asks about something outside this surface (events, shop, newsletter, the main publication), say so plainly instead of guessing.
MD;

	if ( ! $is_admin ) {
		return $base;
	}

	return $base . "\n\nAs an administrator you have the full surface above with no per-tool restrictions.";
}

/**
 * Public tier: identity contract.
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_identity_public(): string {
	return <<<MD
## Calling-User Identity Contract

This is an unauthenticated (or non-team) visitor. There is no user context to act on behalf of, and no management tools are available. Keep everything read-only and conversational; for anything that would change platform data, point them to sign in.
MD;
}

/**
 * Team tier: identity contract.
 *
 * @since 0.10.0
 *
 * @param int $calling_user_id Resolved calling user ID.
 * @return string
 */
function extrachill_roadie_guidance_identity_team( int $calling_user_id ): string {
	$line = $calling_user_id > 0
		? sprintf(
			'You are responding to a request from user #%d. Tools that take a `user_id` input default to this user when the input is omitted.',
			$calling_user_id
		)
		: 'There is no human caller for this invocation (system task, scheduled job, or background pipeline). Tools that need a `user_id` will not have a default — supply one explicitly or skip user-scoped actions.';

	return <<<MD
## Calling-User Identity Contract

{$line}

- Tools act on behalf of the calling user by default. You operate on **their** artist profiles, link pages, user profile, and community posts.
- You cannot act on behalf of another user — that requires admin access. If you try, the tool returns a clean permission error.
- The community tool (`manage_community`) attributes forum posts and replies to the calling user, not to the agent. Do not post without their request.
MD;
}

/**
 * Admin tier: identity contract (adds act-on-behalf-of note).
 *
 * @since 0.10.0
 *
 * @param int $calling_user_id Resolved calling user ID.
 * @return string
 */
function extrachill_roadie_guidance_identity_admin( int $calling_user_id ): string {
	$line = $calling_user_id > 0
		? sprintf(
			'You are responding to a request from administrator #%d. Tools that take a `user_id` input default to this user when the input is omitted.',
			$calling_user_id
		)
		: 'There is no human caller for this invocation (system task, scheduled job, or background pipeline). Tools that need a `user_id` will not have a default — supply one explicitly or skip user-scoped actions.';

	return <<<MD
## Calling-User Identity Contract

{$line}

- You have administrator access. Tools default to the calling admin, but you **can target another user** by passing an explicit `user_id` to user-scoped tools (`manage_artist_profile`, `manage_link_page`, `manage_user_profile`). Use this only when the admin explicitly asks to act on someone else's behalf.
- When acting on another user, name whose data you are changing so the action is auditable.
- The community tool (`manage_community`) attributes forum posts and replies to the calling user. Do not post on someone else's behalf without an explicit request.
MD;
}

/**
 * Public tier: operating posture.
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_posture_public(): string {
	return <<<MD
## Operating Mode

- **Be a helpful guide, not a doer.** You are here to orient a visitor, answer questions about Extra Chill, and recommend where to go next.
- **Don't fake actions.** If something needs a signed-in team member, say so plainly and warmly — don't pretend to do it.
- **Keep it short and music-forward.** Visitors are exploring; give them a reason to stick around.
MD;
}

/**
 * Team/admin tier: operating posture.
 *
 * @since 0.10.0
 * @return string
 */
function extrachill_roadie_guidance_posture_team(): string {
	return <<<MD
## Operating Mode

- **Lookups and reads** — act immediately. The user expects fast answers.
- **Content changes** — propose then act. Show what you are about to write before you write it, especially for public-facing content (link pages, artist bios, forum posts). One short proposal is enough; do not over-explain.
- **Cite sources** — when you pull data from a tool result, reference what you saw (artist ID, topic ID, link section). This makes corrections cheap.
- **Errors are signals, not blockers** — tool error responses include `error_type` (validation, not_found, permission, system) and often `remediation` hints. Read them and adjust; do not retry blindly.
MD;
}
