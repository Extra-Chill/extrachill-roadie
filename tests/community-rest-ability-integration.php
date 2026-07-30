<?php
/** Exercise approved Community create abilities through core REST and cross-site transport. */

declare(strict_types=1);

namespace AgentsAPI\AI {
	// Mirrors the members of the real AgentsAPI class that plugin source uses.
	// A partial stub here shadows the genuine declaration during static
	// analysis, so anything omitted is reported as undefined at every call site.
	class WP_Agent_Execution_Principal {
		public const REQUEST_CONTEXT_CHAT = 'chat';
		public const REQUEST_CONTEXT_REST = 'rest';

		public $capability_ceiling = null;
		public int $acting_user_id;
		public string $effective_agent_id;

		public function __construct( int $acting_user_id, string $effective_agent_id = '__wordpress_user__' ) {
			$this->acting_user_id    = $acting_user_id;
			$this->effective_agent_id = $effective_agent_id;
		}

		public static function user_session( int $acting_user_id, string $effective_agent_id, string $request_context = self::REQUEST_CONTEXT_REST ): self {
			return new self( $acting_user_id, $effective_agent_id );
		}
	}
}

namespace {
	$wordpress_root = $argv[1] ?? getenv( 'WP_ROOT' ) ?: '';
	$community_root = $argv[2] ?? getenv( 'COMMUNITY_PR_ROOT' ) ?: '';
	if ( ! is_file( $wordpress_root . '/wp-load.php' ) || ! is_file( $community_root . '/inc/content/topic-reply-abilities.php' ) ) {
		fwrite( STDERR, "Usage: php tests/community-rest-ability-integration.php /path/to/wordpress /path/to/community-pr-head\n" );
		exit( 1 );
	}

	define( 'SHORTINIT', true );
	define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );
	require $wordpress_root . '/wp-load.php';
	require ABSPATH . WPINC . '/class-wp-http-response.php';
	require ABSPATH . WPINC . '/rest-api.php';
	require ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
	require ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
	require ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
	require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-controller.php';
	require ABSPATH . WPINC . '/abilities-api/class-wp-ability-category.php';
	require ABSPATH . WPINC . '/abilities-api/class-wp-ability-categories-registry.php';
	require ABSPATH . WPINC . '/abilities-api/class-wp-ability.php';
	require ABSPATH . WPINC . '/abilities-api/class-wp-abilities-registry.php';
	require ABSPATH . WPINC . '/abilities-api.php';
	require ABSPATH . WPINC . '/abilities.php';
	require ABSPATH . WPINC . '/rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php';

	define( 'EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META', '_ec_public_voice' );
	define( 'EXTRACHILL_COMMUNITY_AUTOMATED_META', '_ec_automated_agent' );
	$GLOBALS['_rest_user_id']   = 0;
	$GLOBALS['_rest_principal'] = null;
	$GLOBALS['_rest_posts']     = array(
		10 => (object) array( 'ID' => 10, 'post_type' => 'forum', 'post_parent' => 0 ),
		20 => (object) array( 'ID' => 20, 'post_type' => 'topic', 'post_parent' => 10 ),
	);
	$GLOBALS['_rest_meta']      = array();
	$GLOBALS['_rest_next_id']   = 100;
	$GLOBALS['_rest_events_down'] = false;

	function __( $text ): string { return (string) $text; }
	function get_current_user_id(): int { return (int) $GLOBALS['_rest_user_id']; }
	function is_user_logged_in(): bool { return get_current_user_id() > 0; }
	function wp_set_current_user( $user_id ): object {
		$GLOBALS['_rest_user_id'] = (int) $user_id;
		return (object) array( 'ID' => (int) $user_id );
	}
	function ec_get_blog_id( $site_key ): int { unset( $site_key ); return 1; }
	function user_can( $user_id, $capability, ...$args ): bool {
		unset( $args );
		return in_array( (int) $user_id, array( 7, 8 ), true ) && in_array( $capability, array( 'publish_topics', 'publish_replies', 'access_roadie', 'manage_options' ), true );
	}
	function get_userdata( $user_id ) { return in_array( (int) $user_id, array( 7, 8 ), true ) ? (object) array( 'ID' => (int) $user_id ) : false; }
	function get_post( $post_id ) { return $GLOBALS['_rest_posts'][ (int) $post_id ] ?? null; }
	function get_post_field( $field, $post_id ) { return $GLOBALS['_rest_posts'][ (int) $post_id ]->$field ?? null; }
	function get_post_meta( $post_id, $key ) { return $GLOBALS['_rest_meta'][ (int) $post_id ][ $key ] ?? ''; }
	function update_post_meta( $post_id, $key, $value ): void { $GLOBALS['_rest_meta'][ (int) $post_id ][ $key ] = $value; }
	function delete_post_meta( $post_id, $key ): void { unset( $GLOBALS['_rest_meta'][ (int) $post_id ][ $key ] ); }
	function wp_kses_post( $value ): string { return (string) $value; }
	function get_permalink( $post_id ): string { return 'https://community.example/post/' . (int) $post_id; }
	function bbp_get_forum_post_type(): string { return 'forum'; }
	function bbp_get_topic_post_type(): string { return 'topic'; }
	function bbp_get_reply_post_type(): string { return 'reply'; }
	function bbp_get_public_status_id(): string { return 'publish'; }
	function bbp_get_topic_forum_id(): int { return 10; }
	function bbp_get_topic_permalink( $post_id ): string { return get_permalink( $post_id ); }
	function bbp_get_reply_url( $post_id ): string { return get_permalink( $post_id ); }
	function bbp_insert_topic( $data, $meta ) {
		unset( $meta );
		$id = $GLOBALS['_rest_next_id']++;
		$GLOBALS['_rest_posts'][ $id ] = (object) array_merge( array( 'ID' => $id ), $data );
		return $id;
	}
	function bbp_insert_reply( $data, $meta ) {
		unset( $meta );
		$id = $GLOBALS['_rest_next_id']++;
		$GLOBALS['_rest_posts'][ $id ] = (object) array_merge( array( 'ID' => $id ), $data );
		return $id;
	}
	function extrachill_community_maybe_convert_markdown( $content ): string { return (string) $content; }
	function extrachill_community_ability_list_topics(): array { return array(); }
	function extrachill_community_ability_get_topic(): array { return array(); }
	function extrachill_community_ability_get_topic_for_editor(): array { return array(); }
	function extrachill_community_ability_get_topic_for_editor_permission(): bool { return false; }
	function extrachill_community_ability_get_reply_for_editor(): array { return array(); }
	function extrachill_community_ability_get_reply_for_editor_permission(): bool { return false; }
	function extrachill_community_ability_update_topic_permission(): bool { return false; }
	function extrachill_community_ability_update_reply_permission(): bool { return false; }
	function extrachill_community_ability_list_replies(): array { return array(); }

	class WP_Agent_Access {
		public static function get_current_principal() { return $GLOBALS['_rest_principal']; }
	}

	function extrachill_community_prepare_public_voice_change( $input, $author_id, $actor_id ) {
		unset( $actor_id );
		if ( ! isset( $input['public_voice'] ) ) {
			return null;
		}
		if ( $GLOBALS['_rest_events_down'] ) {
			return new WP_Error( 'events_unavailable', 'Events unavailable.', array( 'status' => 503 ) );
		}
		return 'venue:55' === $input['public_voice'] && in_array( (int) $author_id, array( 7, 8 ), true )
			? 'venue:55'
			: new WP_Error( 'public_voice_not_managed', 'Voice not managed.', array( 'status' => 403 ) );
	}
	function extrachill_community_persist_public_voice( $post_id, $reference ): void {
		if ( null !== $reference ) {
			update_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META, $reference );
		}
		$principal = $GLOBALS['_rest_principal'];
		if ( $principal instanceof AgentsAPI\AI\WP_Agent_Execution_Principal && '__wordpress_user__' !== $principal->effective_agent_id ) {
			update_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META, $principal->effective_agent_id );
		}
	}
	function extrachill_community_format_post_public_voice( $post_id ) {
		$reference = get_post_meta( $post_id, EXTRACHILL_COMMUNITY_PUBLIC_VOICE_META );
		if ( '' === $reference ) {
			return null;
		}
		return array(
			'reference' => $reference, 'type' => 'venue', 'id' => 55, 'name' => 'Venue 55',
			'url' => 'https://events.example/venue-55', 'accountable_user_id' => (int) get_post_field( 'post_author', $post_id ),
			'automated' => '' !== get_post_meta( $post_id, EXTRACHILL_COMMUNITY_AUTOMATED_META ),
		);
	}

	$cross_site_transport = $argv[3] ?? getenv( 'NETWORK_CROSS_SITE_FILE' ) ?: $wordpress_root . '/wp-content/plugins/extrachill-network/inc/core/cross-site-rest.php';
	if ( ! is_file( $cross_site_transport ) ) {
		fwrite( STDERR, "Cross-site transport not found: {$cross_site_transport}\n" );
		exit( 1 );
	}
	require $cross_site_transport;
	require $community_root . '/inc/core/ability-helpers.php';
	require $community_root . '/inc/content/public-voice-contract.php';
	require $community_root . '/inc/content/topic-reply-write.php';
	require $community_root . '/inc/content/topic-reply-abilities.php';

	function rest_contract_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}

	add_action( 'wp_abilities_api_categories_init', static function (): void {
		wp_register_ability_category( 'extrachill-community', array( 'label' => 'Community', 'description' => 'Community abilities.' ) );
	} );
	$GLOBALS['wp_actions']['init'] = 1;
	WP_Ability_Categories_Registry::get_instance();
	WP_Abilities_Registry::get_instance();
	$controller = new WP_REST_Abilities_V1_Run_Controller();
	$GLOBALS['wp_actions']['rest_api_init'] = 1;
	$GLOBALS['wp_rest_server'] = new WP_REST_Server();
	$controller->register_routes();

	$GLOBALS['_rest_user_id']   = 7;
	$GLOBALS['_rest_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7 );
	$human = ec_cross_site_rest_request( 'community', 'POST', '/wp-abilities/v1/abilities/extrachill/community-create-topic/run', array(
		'user_id' => 7,
		'body'    => array( 'input' => array( 'forum_id' => 10, 'title' => 'Human', 'content' => 'Post.' ) ),
	) );
	rest_contract_assert( ! is_wp_error( $human ) && 7 === $human['author_id'] && null === $human['public_voice'], 'Authenticated human topic REST execution must preserve author and nullable voice.' );

	$GLOBALS['_rest_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, '101' );
	$venue = ec_cross_site_rest_request( 'community', 'POST', '/wp-abilities/v1/abilities/extrachill/community-create-reply/run', array(
		'user_id' => 7,
		'body'    => array( 'input' => array( 'topic_id' => 20, 'content' => 'Venue reply.', 'public_voice' => 'venue:55' ) ),
	) );
	rest_contract_assert( ! is_wp_error( $venue ) && 7 === $venue['author_id'] && true === $venue['public_voice']['automated'], 'Venue-agent reply REST execution must align acting human, author, and automated public voice.' );

	$GLOBALS['_rest_user_id']   = 0;
	$GLOBALS['_rest_principal'] = null;
	$anonymous = ec_cross_site_rest_request( 'community', 'POST', '/wp-abilities/v1/abilities/extrachill/community-create-topic/run', array(
		'body' => array( 'input' => array( 'forum_id' => 10, 'title' => 'Anonymous', 'content' => 'Denied.' ) ),
	) );
	rest_contract_assert( is_wp_error( $anonymous ), 'Anonymous standard REST ability execution must be denied.' );

	$GLOBALS['_rest_user_id']   = 7;
	$GLOBALS['_rest_principal'] = new AgentsAPI\AI\WP_Agent_Execution_Principal( 7, '101' );
	$spoof = ec_cross_site_rest_request( 'community', 'POST', '/wp-abilities/v1/abilities/extrachill/community-create-topic/run', array(
		'user_id' => 7,
		'body'    => array( 'input' => array( 'forum_id' => 10, 'title' => 'Spoof', 'content' => 'Denied.', 'user_id' => 8, 'public_voice' => 'venue:55' ) ),
	) );
	rest_contract_assert( is_wp_error( $spoof ), 'Cross-user author spoofing must be denied by the approved Community route.' );

	$GLOBALS['_rest_events_down'] = true;
	$transient = ec_cross_site_rest_request( 'community', 'POST', '/wp-abilities/v1/abilities/extrachill/community-create-reply/run', array(
		'user_id' => 7,
		'body'    => array( 'input' => array( 'topic_id' => 20, 'content' => 'Unavailable.', 'public_voice' => 'venue:55' ) ),
	) );
	rest_contract_assert( is_wp_error( $transient ) && 'events_unavailable' === $transient->get_error_code(), 'Transient public-voice authority failures must propagate through cross-site REST.' );

	echo "Roadie Community REST ability integration passed.\n";
}
