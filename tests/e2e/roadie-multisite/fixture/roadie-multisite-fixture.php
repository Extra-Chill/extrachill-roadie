<?php
/**
 * Plugin Name: Roadie Multisite E2E Fixture
 * Description: Test-only site resolution and deterministic external boundaries.
 * Version: 1.0.0
 * Network: true
 */

defined( 'ABSPATH' ) || exit;

use DataMachine\Engine\AI\Actions\PendingActionAuthorizationReceipt;

add_filter(
	'datamachine_ai_request_result',
	static function ( $result, array $provider_request, string $provider ) {
		if ( 'roadie-e2e' !== $provider ) {
			return $result;
		}

		$request = wp_json_encode( $provider_request );
		$content = str_contains( (string) $request, 'Second deterministic Roadie message.' )
			? 'Deterministic Roadie continuation reply.'
			: 'Deterministic Roadie initial reply.';

		return array(
			'content'    => $content,
			'tool_calls' => array(),
			'usage'      => array(
				'prompt_tokens'     => 1,
				'completion_tokens' => 1,
				'total_tokens'      => 2,
			),
		);
	},
	10,
	3
);

add_filter(
	'datamachine_pending_action_handlers',
	static function ( array $handlers ): array {
		$handlers['roadie_e2e_artist_action'] = array(
			'can_resolve' => 'roadie_e2e_can_resolve_artist_action',
			'apply'       => 'roadie_e2e_apply_artist_action',
		);
		return $handlers;
	}
);

function roadie_e2e_can_resolve_artist_action( array $payload, string $decision, int $user_id ) {
	unset( $decision );
	$input          = is_array( $payload['apply_input'] ?? null ) ? $payload['apply_input'] : array();
	$artist_id      = absint( $input['artist_id'] ?? 0 );
	$artist_blog_id = absint( $input['artist_blog_id'] ?? 0 );

	if ( $artist_id <= 0 || $artist_blog_id <= 0 || ! get_site( $artist_blog_id ) ) {
		return new WP_Error( 'roadie_e2e_invalid_artist_target', 'Fixture action has no valid artist target.' );
	}

	switch_to_blog( $artist_blog_id );
	try {
		return user_can( $user_id, 'edit_post', $artist_id )
			? true
			: new WP_Error( 'roadie_e2e_artist_forbidden', 'Resolver cannot edit the fixture artist.' );
	} finally {
		restore_current_blog();
	}
}

function roadie_e2e_apply_artist_action( array $apply_input, array $payload, array $receipt ) {
	$authorization = PendingActionAuthorizationReceipt::authorization( $payload );
	$subject       = (string) ( $payload['agent'] ?? $payload['creator'] ?? '' );
	$valid         = PendingActionAuthorizationReceipt::validate(
		$receipt,
		(string) $payload['kind'],
		$authorization['operation'],
		$authorization['target'],
		$apply_input,
		$subject,
		(array) $payload['workspace']
	);
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	update_site_option( 'roadie_e2e_pending_apply_count', (int) get_site_option( 'roadie_e2e_pending_apply_count', 0 ) + 1 );
	update_option( 'roadie_e2e_resolved_action', $apply_input, false );

	return array(
		'success'   => true,
		'blog_id'   => get_current_blog_id(),
		'artist_id' => absint( $apply_input['artist_id'] ?? 0 ),
		'marker'    => sanitize_text_field( (string) ( $apply_input['marker'] ?? '' ) ),
	);
}
