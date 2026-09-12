<?php
/**
 * Uninstall handler: deletes the plugin options only when the administrator asked for it.
 *
 * Never deletes posts, media or terms. Short-lived transients expire on their own.
 *
 * @package HorizonPress\NewsBar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

require_once __DIR__ . '/includes/class-autoloader.php';
HorizonPress\NewsBar\Autoloader::register();

/**
 * Deletes the options of the current site when `uninstall_delete_data` is enabled.
 *
 * @return void
 */
function hprnb_uninstall_site() {
	if ( ! HorizonPress\NewsBar\Settings::uninstall_delete_requested() ) {
		return;
	}
	delete_option( HorizonPress\NewsBar\Settings::OPTION );
	delete_option( HorizonPress\NewsBar\Settings::EPOCH_OPTION );
	delete_option( HorizonPress\NewsBar\Settings::SCHEMA_OPTION );
}

if ( is_multisite() ) {
	$hprnb_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 500,
		)
	);
	foreach ( $hprnb_site_ids as $hprnb_site_id ) {
		switch_to_blog( (int) $hprnb_site_id );
		hprnb_uninstall_site();
		restore_current_blog();
	}
} else {
	hprnb_uninstall_site();
}
