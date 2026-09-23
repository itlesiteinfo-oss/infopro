<?php
/**
 * Visibility rules: absolute exclusions, contexts, devices, excluded IDs.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether the bar may be displayed for the current request. Device targeting is CSS only.
 */
final class Visibility {

	/**
	 * Contexts where no bar must ever appear.
	 *
	 * @return bool
	 */
	public static function is_absolute_exclusion(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return true;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			return true;
		}
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( function_exists( 'is_login' ) && is_login() ) {
			return true;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return true;
		}

		// Conditional tags are only meaningful once the main query has run.
		if ( ! did_action( 'wp' ) || ! ( $GLOBALS['wp_query'] ?? null ) instanceof WP_Query ) {
			return true;
		}

		if ( is_feed() || is_robots() || is_favicon() || is_trackback() || is_embed() || is_preview() ) {
			return true;
		}
		if ( '' !== (string) get_query_var( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether at least one device is enabled.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function device_enabled( array $settings ): bool {
		return ! empty( $settings['show_on_desktop'] ) || ! empty( $settings['show_on_mobile'] );
	}

	/**
	 * Key of the current front-end context, or '' when unknown.
	 *
	 * @return string
	 */
	public static function context_key(): string {
		if ( is_front_page() ) {
			return 'front_page';
		}
		if ( is_home() ) {
			return 'blog_home';
		}
		if ( is_singular( 'post' ) ) {
			return 'single_post';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_category() ) {
			return 'category';
		}
		if ( is_tag() ) {
			return 'tag';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_404() ) {
			return 'not_found';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		return '';
	}

	/**
	 * Whether the configured scope allows the current context.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function context_allowed( array $settings ): bool {
		if ( 'custom' !== $settings['display_scope'] ) {
			return true;
		}
		$key = self::context_key();
		if ( '' === $key ) {
			return false;
		}
		return ! empty( $settings['contexts'][ $key ] );
	}

	/**
	 * Which presentation profiles may appear in the current context: the device switches, narrowed
	 * by the per-profile page types. Both false means nothing is rendered at all.
	 *
	 * @param array $settings Settings.
	 * @return array{desktop:bool,mobile:bool}
	 */
	public static function devices_for_context( array $settings ): array {
		$key = self::context_key();

		$allows = static function ( string $profile ) use ( $settings, $key ): bool {
			$map = $settings[ $profile . '_contexts' ] ?? null;
			if ( ! is_array( $map ) || '' === $key || ! array_key_exists( $key, $map ) ) {
				return true; // Unknown context or no map: the global scope already had its say.
			}
			return ! empty( $map[ $key ] );
		};

		return array(
			'desktop' => ! empty( $settings['show_on_desktop'] ) && $allows( 'desktop' ),
			'mobile'  => ! empty( $settings['show_on_mobile'] ) && $allows( 'mobile' ),
		);
	}

	/**
	 * A copy of the settings whose device switches carry the per-profile page types, so the renderer
	 * emits the right `hprnb-hide-*` class without ever looking at conditional tags itself.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function with_context_devices( array $settings ): array {
		$devices                     = self::devices_for_context( $settings );
		$settings['show_on_desktop'] = $devices['desktop'];
		$settings['show_on_mobile']  = $devices['mobile'];

		return $settings;
	}

	/**
	 * Whether the current singular object is excluded by ID.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function is_excluded_id( array $settings ): bool {
		if ( ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 ) {
			return false;
		}
		if ( self::is_hidden_by_post( $id ) ) {
			return true;
		}
		if ( empty( $settings['display_exclude_ids'] ) ) {
			return false;
		}
		return in_array( $id, array_map( 'intval', (array) $settings['display_exclude_ids'] ), true );
	}

	/**
	 * Whether the author ticked "Never show the bar on this page" on the edit screen.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_hidden_by_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_post_meta( $post_id, Settings::META_HIDE, true );
	}

	/**
	 * Whether the URGENT bar (2.14) may show on the current page (2.15): its own page types, the front
	 * page included by default, whatever the news bar's page types, per-device lists and device
	 * switches say. The plugin switch, the absolute exclusions and a page switched off in its News
	 * Bar box (or listed in the exceptions) still mean no bar at all.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function urgent_allowed( array $settings ): bool {
		$allowed = ! empty( $settings['enabled'] )
			&& Urgent::enabled( $settings )
			&& ! self::is_absolute_exclusion()
			&& ! self::is_excluded_id( $settings )
			&& self::urgent_context_allowed( $settings );

		/**
		 * Filters whether the URGENT bar may show on the current page.
		 *
		 * @param bool  $allowed  Whether the URGENT bar may show.
		 * @param array $settings Settings.
		 */
		return (bool) apply_filters( 'hprnb_urgent_should_display', $allowed, $settings );
	}

	/**
	 * Whether the URGENT bar's page types allow the current context. A page of an unknown kind (a
	 * custom post type, say) is allowed only while every page type is ticked.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function urgent_context_allowed( array $settings ): bool {
		$map = isset( $settings['urgent_contexts'] ) && is_array( $settings['urgent_contexts'] ) ? $settings['urgent_contexts'] : array();
		if ( empty( $map ) ) {
			return true;
		}
		$key = self::context_key();
		if ( '' === $key || ! array_key_exists( $key, $map ) ) {
			return ! in_array( false, array_map( 'boolval', $map ), true );
		}
		return ! empty( $map[ $key ] );
	}

	/**
	 * Full decision tree for automatic display.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function should_display( array $settings ): bool {
		$display = ! empty( $settings['enabled'] )
			&& ! self::is_absolute_exclusion()
			&& self::device_enabled( $settings )
			&& self::context_allowed( $settings )
			&& ! self::is_excluded_id( $settings );

		if ( $display ) {
			$devices = self::devices_for_context( $settings );
			$display = $devices['desktop'] || $devices['mobile'];
		}

		/**
		 * Filters the final display decision for the current request.
		 *
		 * @param bool  $display  Whether the bar should be displayed.
		 * @param array $settings Settings.
		 */
		return (bool) apply_filters( 'hprnb_should_display', $display, $settings );
	}
}
