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
	 * Whether the current singular object is excluded by ID.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function is_excluded_id( array $settings ): bool {
		if ( empty( $settings['display_exclude_ids'] ) || ! is_singular() ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		return $id > 0 && in_array( $id, array_map( 'intval', (array) $settings['display_exclude_ids'] ), true );
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

		/**
		 * Filters the final display decision for the current request.
		 *
		 * @param bool  $display  Whether the bar should be displayed.
		 * @param array $settings Settings.
		 */
		return (bool) apply_filters( 'hprnb_should_display', $display, $settings );
	}
}
