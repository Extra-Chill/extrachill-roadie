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
		return ! empty( $GLOBALS['extrachill_roadie_test_state']['user_caps'][ $cap ] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['extrachill_roadie_test_state']['current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ): string {
		return (string) json_encode( $data, $flags );
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
