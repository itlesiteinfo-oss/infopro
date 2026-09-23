<?php
/**
 * Plugin bootstrap: wires every component to WordPress hooks.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton. Contains no business logic.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Returns the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * `plugins_loaded` callback.
	 *
	 * @return void
	 */
	public static function boot(): void {
		self::instance()->register();
	}

	/**
	 * Registers every component. Safe to call several times.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'init', array( Settings::class, 'maybe_upgrade' ), 5 );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Invalidation::register();
		Urgent::register();
		Assets::register();
		Frontend::register();
		Placement::register();
		Rest_Controller::register();
		Shortcode::register();
		Admin\Preview::register();

		if ( is_admin() ) {
			Admin\Admin::register();
			Admin\Import_Export::register();
			Admin\Post_Controls::register();
		}
	}

	/**
	 * Loads the bundled translations. Runs on `init`, never earlier.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'horizon-press-news-bar', false, dirname( HPRNB_BASENAME ) . '/languages' );
	}
}
