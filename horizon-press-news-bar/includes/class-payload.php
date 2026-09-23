<?php
/**
 * Payload orchestration: request memo → transient → query + render.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point used by SSR, REST and the shortcode.
 */
final class Payload {

	/**
	 * Per-request memo keyed by transient name.
	 *
	 * @var array<string, array>
	 */
	private static array $memo = array();

	/**
	 * Returns the payload, using the request memo and the transient cache.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function get( array $settings ): array {
		$key = Cache::key( $settings );

		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}

		$payload = Cache::get( $settings );
		if ( null === $payload ) {
			$payload = self::build( $settings );
			Cache::set( $settings, $payload );
		}

		self::$memo[ $key ] = $payload;

		return $payload;
	}

	/**
	 * Builds a fresh payload without touching the cache.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function build( array $settings ): array {
		// Each bar has its own switches (2.16): the news bar's query only runs while it is on somewhere.
		$items = Visibility::news_enabled( $settings ) ? Query::items( $settings ) : array();
		return Renderer::payload( $items, $settings, null, Urgent::items( $settings ) );
	}

	/**
	 * Clears the request memo.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}
}
