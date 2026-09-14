<?php
/**
 * Plugin Name:       Horizon Press News Bar
 * Plugin URI:        https://horizonpress.example/news-bar
 * Description:       Barre d'actualités récentes, fixe en bas de page, filtrée par fenêtre temporelle et catégories.
 * Version:           1.2.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Horizon Press
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       horizon-press-news-bar
 * Domain Path:       /languages
 *
 * @package HorizonPress\NewsBar
 */

defined( 'ABSPATH' ) || exit;

define( 'HPRNB_VERSION', '1.2.0' );
define( 'HPRNB_FILE', __FILE__ );
define( 'HPRNB_PATH', plugin_dir_path( __FILE__ ) );
define( 'HPRNB_URL', plugin_dir_url( __FILE__ ) );
define( 'HPRNB_BASENAME', plugin_basename( __FILE__ ) );
define( 'HPRNB_MIN_WP', '6.6' );
define( 'HPRNB_MIN_PHP', '8.0' );
define( 'HPRNB_SCHEMA_VERSION', 1 );

/**
 * Whether the current PHP and WordPress versions satisfy the plugin requirements.
 *
 * Kept free of PHP 8 syntax so that the check itself can run on older runtimes.
 *
 * @return bool
 */
function hprnb_is_compatible() {
	global $wp_version;

	return version_compare( PHP_VERSION, HPRNB_MIN_PHP, '>=' )
		&& version_compare( (string) $wp_version, HPRNB_MIN_WP, '>=' );
}

/**
 * Builds the incompatibility message (translations are only loaded after `init`).
 *
 * @return string
 */
function hprnb_incompatibility_message() {
	return sprintf(
		/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
		__( 'Horizon Press News Bar requires PHP %1$s or newer and WordPress %2$s or newer. The plugin has been left inactive.', 'horizon-press-news-bar' ),
		HPRNB_MIN_PHP,
		HPRNB_MIN_WP
	);
}

/**
 * Prints the admin notice shown when the runtime is incompatible.
 *
 * @return void
 */
function hprnb_incompatibility_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( hprnb_incompatibility_message() ) );
}

/**
 * Activation handler used when the runtime is incompatible: deactivate and explain.
 *
 * @return void
 */
function hprnb_activation_incompatible() {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( HPRNB_BASENAME );
	wp_die(
		esc_html( hprnb_incompatibility_message() ),
		esc_html__( 'Plugin activation error', 'horizon-press-news-bar' ),
		array( 'back_link' => true )
	);
}

if ( ! hprnb_is_compatible() ) {
	add_action( 'admin_notices', 'hprnb_incompatibility_notice' );
	register_activation_hook( __FILE__, 'hprnb_activation_incompatible' );
	return;
}

require_once HPRNB_PATH . 'includes/class-autoloader.php';
HorizonPress\NewsBar\Autoloader::register();

register_activation_hook( __FILE__, array( HorizonPress\NewsBar\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( HorizonPress\NewsBar\Activator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( HorizonPress\NewsBar\Plugin::class, 'boot' ) );
