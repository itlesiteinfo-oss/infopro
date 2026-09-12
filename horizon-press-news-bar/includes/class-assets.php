<?php
/**
 * Front-end asset registration and URLs.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the front styles and scripts (versioned with HPRNB_VERSION, deferred).
 */
final class Assets {

	/**
	 * Hooks the front registration early so orchestration can enqueue later.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_front' ), 1 );
	}

	/**
	 * File suffix: '' with SCRIPT_DEBUG, '.min' otherwise.
	 *
	 * @return string
	 */
	public static function suffix(): string {
		return ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
	}

	/**
	 * Registers (does not enqueue) the front assets.
	 *
	 * @return void
	 */
	public static function register_front(): void {
		$suffix = self::suffix();

		if ( ! wp_style_is( 'hprnb-bar', 'registered' ) ) {
			wp_register_style( 'hprnb-bar', HPRNB_URL . 'assets/css/hprnb-bar' . $suffix . '.css', array(), HPRNB_VERSION );
			wp_style_add_data( 'hprnb-bar', 'rtl', 'replace' );
			if ( '' !== $suffix ) {
				wp_style_add_data( 'hprnb-bar', 'suffix', $suffix );
			}
		}

		$script_args = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);
		if ( ! wp_script_is( 'hprnb-bootstrap', 'registered' ) ) {
			wp_register_script( 'hprnb-bootstrap', HPRNB_URL . 'assets/js/hprnb-bootstrap' . $suffix . '.js', array(), HPRNB_VERSION, $script_args );
		}
		if ( ! wp_script_is( 'hprnb-bar', 'registered' ) ) {
			wp_register_script( 'hprnb-bar', HPRNB_URL . 'assets/js/hprnb-bar' . $suffix . '.js', array(), HPRNB_VERSION, $script_args );
		}
	}

	/**
	 * URL of the bar stylesheet actually used (RTL-aware), versioned.
	 *
	 * @return string
	 */
	public static function style_url(): string {
		$file = is_rtl() ? 'hprnb-bar-rtl' : 'hprnb-bar';
		return add_query_arg( 'ver', HPRNB_VERSION, HPRNB_URL . 'assets/css/' . $file . self::suffix() . '.css' );
	}

	/**
	 * URL of a front script, versioned.
	 *
	 * @param string $which bootstrap|bar.
	 * @return string
	 */
	public static function script_url( string $which ): string {
		$file = 'bootstrap' === $which ? 'hprnb-bootstrap' : 'hprnb-bar';
		return add_query_arg( 'ver', HPRNB_VERSION, HPRNB_URL . 'assets/js/' . $file . self::suffix() . '.js' );
	}

	/**
	 * Enqueues the bar stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		self::register_front();
		wp_enqueue_style( 'hprnb-bar' );
	}

	/**
	 * Enqueues the hybrid bootstrap.
	 *
	 * @return void
	 */
	public static function enqueue_bootstrap(): void {
		self::register_front();
		wp_enqueue_script( 'hprnb-bootstrap' );
	}

	/**
	 * Enqueues the interactive script.
	 *
	 * @return void
	 */
	public static function enqueue_bar_script(): void {
		self::register_front();
		wp_enqueue_script( 'hprnb-bar' );
	}

	/**
	 * Prints the anti-flash micro-script (only meaningful when dismissal is remembered).
	 *
	 * @return void
	 */
	public static function print_dismiss_script(): void {
		wp_print_inline_script_tag(
			"try{var v=+localStorage.getItem('hprnb_dismissed_until');if(v&&v>Date.now()){document.documentElement.classList.add('hprnb-dismissed')}}catch(e){}",
			array( 'id' => 'hprnb-dismiss' )
		);
	}
}
