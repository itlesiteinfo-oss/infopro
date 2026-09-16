<?php
/**
 * `[hprnb_news_bar]` shortcode.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Explicit insertion of the bar. Uses the common renderer and the single-root guard.
 */
final class Shortcode {

	/**
	 * Shortcode tag.
	 */
	const TAG = 'hprnb_news_bar';

	/**
	 * Registers the shortcode on `init` when enabled.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'add' ) );
	}

	/**
	 * `init` callback.
	 *
	 * @return void
	 */
	public static function add(): void {
		$settings = Settings::get();
		if ( ! empty( $settings['shortcode_enabled'] ) ) {
			add_shortcode( self::TAG, array( self::class, 'render' ) );
		}
	}

	/**
	 * Shortcode handler. No attributes.
	 *
	 * @return string
	 */
	public static function render(): string {
		$settings = Settings::get();

		if ( empty( $settings['enabled'] ) || ! Visibility::device_enabled( $settings ) ) {
			return '';
		}
		if ( Visibility::is_absolute_exclusion() ) {
			return '';
		}
		if ( is_singular() && Visibility::is_hidden_by_post( (int) get_queried_object_id() ) ) {
			return '';
		}
		if ( Frontend::root_claimed() ) {
			return '';
		}

		$payload = Payload::get( $settings );
		$count   = (int) $payload['count'];

		if ( 'php' === $settings['render_mode'] && $count < 1 ) {
			return '';
		}
		if ( ! Frontend::claim_root() ) {
			return '';
		}

		if ( 'hybrid' === $settings['render_mode'] ) {
			Assets::enqueue_bootstrap();
		}
		if ( $count > 0 ) {
			Frontend::enqueue_bar_assets( $settings );
		}

		return Renderer::root( $payload, $settings );
	}
}
