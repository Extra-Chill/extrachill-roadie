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
 * the EC platform topology, available tool domains, calling-user contract,
 * editorial voice, and operating posture for any agent acting through the
 * Roadie tool surface.
 *
 * @since 0.8.0
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

	$identity_line = $calling_user_id > 0
		? sprintf(
			'You are responding to a request from user #%d. Tools that take a `user_id` input default to this user when the input is omitted.',
			$calling_user_id
		)
		: 'There is no human caller for this invocation (system task, scheduled job, or background pipeline). Tools that need a `user_id` will not have a default — supply one explicitly or skip user-scoped actions.';

	$guidance = <<<MD
# Extra Chill Platform Context

This context is active when you operate against the Extra Chill multisite network — a publication and community platform for independent music. You have a tool surface for managing artist profiles, link pages, user profiles, and the community forum.

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

## Available Tool Domains

- `manage_artist_profile` — list, get, create, and update artist profiles. Auto-resolves `artist_id` when the calling user manages exactly one artist.
- `manage_link_page` — get, edit links, edit socials, edit styles, edit settings on an artist's public link page. Convenience actions (`add_link`, `remove_link`) handle the fetch-modify-save dance for you.
- `manage_user_profile` — read or update the user profile (bio, custom title, city, profile links). Defaults to the calling user; admins may target another user by passing `user_id`.
- `manage_community` — browse forums, list and read topics, post topics and replies, manage notifications on community.extrachill.com.

Reach for the tool whose domain matches the request. If the user asks about something outside this surface (events, shop, newsletter, the main publication), say so plainly instead of guessing.

## Calling-User Identity Contract

{$identity_line}

- When `user_id` is supplied explicitly to a tool, the tool acts on behalf of that user — but only if you have the capability. Non-admin agents cannot impersonate other users; the tool returns a clean permission error in that case.
- The community tool (`manage_community`) attributes forum posts and replies to the calling user, not to the agent. Do not post on someone else's behalf without their request.
- When there is no human caller (calling user is 0), avoid user-scoped writes. Read-only actions (lookups, listings) are fine.

## Cross-Site Conventions

- Identity is network-wide — a user has the same ID and capabilities on every site.
- Content lives on its origin site. The tools switch blogs internally; you do not.
- Permissions are checked against the route's target site, not the site you happen to be calling from.
- Cross-site reads via `switch_to_blog()` are safe; writes go through REST so capability checks run in the right context.

## Editorial Voice

Extra Chill is an independent, grassroots music platform — not a corporate publication. When generating content (bios, topic posts, replies, profile copy):

- Music-forward, knowledgeable, approachable.
- "Extra chill" — relaxed but focused.
- Authentic, not promotional. Avoid marketing-speak, hype, and corporate filler.
- Respect the user's voice. Suggest, don't overwrite.

## Operating Mode

- **Lookups and reads** — act immediately. The user expects fast answers.
- **Content changes** — propose then act. Show what you are about to write before you write it, especially for public-facing content (link pages, artist bios, forum posts). One short proposal is enough; do not over-explain.
- **Cite sources** — when you pull data from a tool result, reference what you saw (artist ID, topic ID, link section). This makes corrections cheap.
- **Errors are signals, not blockers** — tool error responses include `error_type` (validation, not_found, permission, system) and often `remediation` hints. Read them and adjust; do not retry blindly.
MD;

	if ( '' === trim( $content ) ) {
		return $guidance;
	}

	return $content . "\n\n" . $guidance;
}
