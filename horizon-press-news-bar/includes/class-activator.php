<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation and deactivation. No rewrite flush, no cron, no table, no redirect.
 */
final class Activator {

	/**
	 * Maximum number of sites initialised during a network-wide activation.
	 */
	const MAX_SITES = 500;

	/**
	 * Activation hook callback.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! self::requirements_met() ) {
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

		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => self::MAX_SITES,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
			return;
		}

		self::install_site();
	}

	/**
	 * Creates the plugin options for the current site when they do not exist.
	 *
	 * @return void
	 */
	public static function install_site(): void {
		add_option( Settings::OPTION, Settings::defaults(), '', true );
		add_option( Settings::EPOCH_OPTION, wp_generate_uuid4(), '', true );
		add_option( Settings::SCHEMA_OPTION, (string) HPRNB_SCHEMA_VERSION, '', true );
	}

	/**
	 * Deactivation hook callback: keeps settings, rotates the cache epoch.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Invalidation::invalidate();
	}

	/**
	 * Whether PHP and WordPress meet the minimum versions.
	 *
	 * @return bool
	 */
	public static function requirements_met(): bool {
		return hprnb_is_compatible();
	}
}
