<?php
/**
 * Shared bootstrap for contribute-code smoke tests.
 *
 * Provides stand-alone WordPress-shaped globals/functions so the
 * contribute-code modules can be require_once'd without booting WordPress.
 *
 * @package ExtraChillRoadie\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/var/www/extrachill.com/wp-content/plugins' );
}

$GLOBALS['extrachill_roadie_test_state'] = array(
	'filters'        => array(),
	'current_blog'   => 1,
	'active_plugins' => array(),
	'site_url'       => 'https://extrachill.test',
	'current_user'   => null,
	'user_caps'      => array(),
	'site_options'   => array(),
	'options'        => array(),
	'theme'          => null,
);

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		unset( $priority, $accepted_args );
		$GLOBALS['extrachill_roadie_test_state']['filters'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['extrachill_roadie_test_state']['filters'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) $GLOBALS['extrachill_roadie_test_state']['current_blog'];
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $blog_id ): bool {
		$GLOBALS['extrachill_roadie_test_state']['current_blog_stack'][] = $GLOBALS['extrachill_roadie_test_state']['current_blog'];
		$GLOBALS['extrachill_roadie_test_state']['current_blog']         = $blog_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		if ( ! empty( $GLOBALS['extrachill_roadie_test_state']['current_blog_stack'] ) ) {
			$GLOBALS['extrachill_roadie_test_state']['current_blog'] = array_pop(
				$GLOBALS['extrachill_roadie_test_state']['current_blog_stack']
			);
		}
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		if ( 'active_plugins' === $name ) {
			// Per-blog active-plugin map (set by tests that need to prove
			// blog-resolution drives which subsite's plugins are read).
			// Falls back to the flat list used by the rest of the suite.
			$by_blog = $GLOBALS['extrachill_roadie_test_state']['active_plugins_by_blog'] ?? null;
			if ( is_array( $by_blog ) ) {
				$blog_id = (int) $GLOBALS['extrachill_roadie_test_state']['current_blog'];
				if ( array_key_exists( $blog_id, $by_blog ) ) {
					return $by_blog[ $blog_id ];
				}
			}
			return $GLOBALS['extrachill_roadie_test_state']['active_plugins'];
		}
		return $GLOBALS['extrachill_roadie_test_state']['options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $name, $default = false ) {
		return $GLOBALS['extrachill_roadie_test_state']['site_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url(): string {
		return (string) $GLOBALS['extrachill_roadie_test_state']['site_url'];
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme() {
		if ( null === $GLOBALS['extrachill_roadie_test_state']['theme'] ) {
			$GLOBALS['extrachill_roadie_test_state']['theme'] = new ECRoadie_TestTheme();
		}
		return $GLOBALS['extrachill_roadie_test_state']['theme'];
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return user_can( get_current_user_id(), $cap );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['extrachill_roadie_test_state']['current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user_id, string $cap ): bool {
		$user_id      = (int) $user_id;
		$caps_by_user = $GLOBALS['extrachill_roadie_test_state']['caps_by_user'][ $user_id ] ?? null;
		if ( is_array( $caps_by_user ) ) {
			return ! empty( $caps_by_user[ $cap ] );
		}

		return $user_id === get_current_user_id()
			&& ! empty( $GLOBALS['extrachill_roadie_test_state']['user_caps'][ $cap ] );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ): string {
		return (string) json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test stub.
	}
}

if ( ! function_exists( 'get_blog_id_from_url' ) ) {
	/**
	 * Test stub mirroring core multisite host+path → blog id resolution.
	 *
	 * Looks up the host/path pair in a test-controlled network site table at
	 * $GLOBALS['extrachill_roadie_test_state']['network_sites'], shaped as
	 * `[ 'host|/path/' => blog_id ]`. Returns 0 when no row matches, exactly
	 * like core does for an off-network URL.
	 */
	function get_blog_id_from_url( string $host, string $path = '/' ): int {
		$sites = $GLOBALS['extrachill_roadie_test_state']['network_sites'] ?? array();
		$key   = strtolower( $host ) . '|' . $path;
		return (int) ( $sites[ $key ] ?? 0 );
	}
}

if ( ! class_exists( 'ECRoadie_TestTheme' ) ) {
	final class ECRoadie_TestTheme {
		public string $stylesheet = 'extrachill';
		public string $directory  = '/var/www/extrachill.com/wp-content/themes/extrachill';
		public string $name       = 'Extra Chill';
		public ?ECRoadie_TestTheme $parent_obj = null;

		public function get_stylesheet(): string {
			return $this->stylesheet;
		}

		public function get_stylesheet_directory(): string {
			return $this->directory;
		}

		public function get( string $key ): string {
			if ( 'Name' === $key ) {
				return $this->name;
			}
			return '';
		}

		public function parent() {
			return $this->parent_obj;
		}
	}
}

if ( ! function_exists( 'roadie_test_assert' ) ) {
	function roadie_test_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

if ( ! function_exists( 'roadie_test_reset_filters' ) ) {
	function roadie_test_reset_filters(): void {
		$GLOBALS['extrachill_roadie_test_state']['filters'] = array();
	}
}

require_once dirname( __DIR__ ) . '/inc/contribute-code/subsite-context.php';
require_once dirname( __DIR__ ) . '/inc/contribute-code/repo-map.php';
require_once dirname( __DIR__ ) . '/inc/contribute-code/recipe-builder.php';
require_once dirname( __DIR__ ) . '/inc/contribute-code/capabilities.php';
require_once dirname( __DIR__ ) . '/inc/contribute-code/inherit-resolver.php';
