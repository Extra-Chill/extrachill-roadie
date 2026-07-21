<?php

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugins = array(
	'00-roadie-multisite-fixture/roadie-multisite-fixture.php',
	'agents-api/agents-api.php',
	'data-machine/data-machine.php',
	'frontend-agent-chat/frontend-agent-chat.php',
	'extrachill-users/extrachill-users.php',
	'extrachill-artist-platform/extrachill-artist-platform.php',
	'data-machine-events/data-machine-events.php',
	'extrachill-events/extrachill-events.php',
	'extrachill-roadie/extrachill-roadie.php',
);

foreach ( $plugins as $plugin ) {
	if ( is_plugin_active_for_network( $plugin ) ) {
		continue;
	}

	$result = activate_plugin( $plugin, '', true );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $plugin . ': ' . $result->get_error_message() );
	}
}
