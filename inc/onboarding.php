<?php
/**
 * Bridge onboarding configuration — EC-specific Roadie onboarding for Beeper clients.
 *
 * Hooks `datamachine_bridge_onboarding_config` to provide the welcome message,
 * description, avatar, room metadata, and login instructions that bridge clients
 * (like the Beeper/mautrix connector) use for first-run UX.
 *
 * All product-specific defaults live here, not in the Data Machine-side repos.
 *
 * @package ExtraChillRoadie
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide EC-specific onboarding config for the Roadie agent.
 *
 * Only applies when the requested agent_slug matches the Roadie slug.
 * Other agents pass through untouched.
 *
 * @since 0.4.0
 *
 * @param array      $config     Base onboarding config from the bridge plugin.
 * @param string     $agent_slug Requested agent slug.
 * @param array|null $agent      Agent row from the database.
 * @return array Modified config with EC-specific Roadie values.
 */
function extrachill_roadie_bridge_onboarding_config( array $config, string $agent_slug, ?array $agent ): array {
	// Only apply to the Roadie agent.
	if ( EXTRACHILL_ROADIE_AGENT_SLUG !== $agent_slug ) {
		return $config;
	}

	// If the agent wasn't found in the DB, bail (shouldn't happen if Roadie is active).
	if ( ! $agent ) {
		return $config;
	}

	$config['display_name'] = EXTRACHILL_ROADIE_AGENT_NAME;

	$config['description'] = 'Roadie is your personal assistant on Extra Chill. '
		. 'Manage your artist profile, link page, community posts, and more — all through chat.';

	$config['welcome_message'] = "Hey! I'm Roadie, your assistant on Extra Chill. "
		. "I can help you manage your artist profile, update your link page, post in the community forums, and more.\n\n"
		. "What would you like to do?";

	$config['login_label'] = 'Sign in to Extra Chill';

	$config['login_instructions'] = 'Open the link below (or scan the QR code) to sign in to Extra Chill. '
		. "After you authorize Roadie, you'll be connected automatically.";

	// Room metadata for the bridge to set when creating the chat room.
	$config['room_name']  = 'Roadie';
	$config['room_topic'] = 'Extra Chill assistant — manage your profile, link page, and community.';

	// Capabilities the user can use through chat.
	$config['capabilities'] = array(
		'artist_profile'  => 'Create and manage your artist profile',
		'link_page'       => 'Update your link-in-bio page',
		'user_profile'    => 'Edit your community profile',
		'community'       => 'Browse forums, post topics, and reply',
	);

	// Avatar: use the Roadie agent's avatar if available via DM, otherwise fall back to site icon.
	$avatar_url = '';
	if ( function_exists( 'get_site_icon_url' ) ) {
		$avatar_url = get_site_icon_url( 256 );
	}

	/**
	 * Filter the Roadie avatar URL for bridge onboarding.
	 *
	 * @since 0.4.0
	 *
	 * @param string $avatar_url Default avatar URL.
	 */
	$config['avatar_url'] = apply_filters( 'extrachill_roadie_bridge_avatar_url', $avatar_url );

	return $config;
}
add_filter( 'datamachine_bridge_onboarding_config', 'extrachill_roadie_bridge_onboarding_config', 10, 3 );
