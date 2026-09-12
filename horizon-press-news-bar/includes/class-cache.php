<?php
/**
 * Transient-based payload cache with epoch-based invalidation.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the rendered payload in a transient. Builds no query.
 */
final class Cache {

	/**
	 * Transient name prefix.
	 */
	const KEY_PREFIX = 'hprnb_bar_';

	/**
	 * Hard bounds of the TTL, in seconds.
	 */
	const TTL_MIN = 30;
	const TTL_MAX = 600;

	/**
	 * Settings that change the selection, the order, the markup or the label.
	 * Colours, sizes, z-index and the separator settings (show_separator, separator_char,
	 * separator_after_last) are CSS variables / classes on the root and never change the payload.
	 */
	const PAYLOAD_KEYS = array(
		'label_text',
		'label_position',
		'window_value',
		'window_unit',
		'categories_include',
		'categories_exclude',
		'tags_include',
		'content_exclude_post_ids',
		'max_items',
		'orderby',
		'layout_mode',
		'show_relative_time',
		'relative_time_max_hours',
		'show_thumbnail',
		'thumbnail_size',
		'ticker_enabled',
		'ticker_mode',
		'ticker_speed',
		'rotate_interval',
		'pause_on_hover',
		'close_button',
		'remember_dismiss',
		'dismiss_duration_hours',
	);

	/**
	 * Current cache epoch. Created on demand.
	 *
	 * @return string
	 */
	public static function epoch(): string {
		$epoch = get_option( Settings::EPOCH_OPTION );
		if ( ! is_string( $epoch ) || '' === $epoch || strlen( $epoch ) > 64 ) {
			$epoch = wp_generate_uuid4();
			if ( ! add_option( Settings::EPOCH_OPTION, $epoch, '', true ) ) {
				update_option( Settings::EPOCH_OPTION, $epoch, true );
			}
		}
		return $epoch;
	}

	/**
	 * Hash of everything influencing the payload.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function hash( array $settings ): string {
		$subset = array();
		foreach ( self::PAYLOAD_KEYS as $key ) {
			$subset[ $key ] = $settings[ $key ] ?? null;
		}
		return md5( (string) wp_json_encode( array( $subset, determine_locale(), HPRNB_VERSION ) ) );
	}

	/**
	 * Transient name for the current epoch and settings.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function key( array $settings ): string {
		return self::KEY_PREFIX . self::epoch() . '_' . self::hash( $settings );
	}

	/**
	 * Effective TTL (filtered, then clamped to 30–600 s).
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function ttl( array $settings ): int {
		/**
		 * Filters the cache TTL in seconds.
		 *
		 * @param int   $ttl      TTL from settings.
		 * @param array $settings Settings.
		 */
		$ttl = apply_filters( 'hprnb_cache_ttl', (int) $settings['cache_ttl'], $settings );
		return max( self::TTL_MIN, min( self::TTL_MAX, (int) $ttl ) );
	}

	/**
	 * Cached payload or null.
	 *
	 * @param array $settings Settings.
	 * @return array|null
	 */
	public static function get( array $settings ): ?array {
		$payload = get_transient( self::key( $settings ) );
		return self::is_valid( $payload ) ? $payload : null;
	}

	/**
	 * Stores a payload (empty results included).
	 *
	 * @param array $settings Settings.
	 * @param array $payload  Payload.
	 * @return void
	 */
	public static function set( array $settings, array $payload ): void {
		set_transient( self::key( $settings ), $payload, self::ttl( $settings ) );
	}

	/**
	 * Whether a value is a payload produced by this plugin version.
	 *
	 * @param mixed $payload Value to check.
	 * @return bool
	 */
	public static function is_valid( $payload ): bool {
		return is_array( $payload )
			&& isset( $payload['version'], $payload['generated_at'], $payload['count'], $payload['items'], $payload['html'] )
			&& HPRNB_VERSION === $payload['version']
			&& is_int( $payload['generated_at'] )
			&& is_int( $payload['count'] )
			&& is_array( $payload['items'] )
			&& is_string( $payload['html'] );
	}
}
