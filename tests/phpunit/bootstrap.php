<?php
/**
 * PHPUnit bootstrap for the Horizon Press News Bar plugin (development only).
 *
 * Environment variables:
 *  - WP_TESTS_DIR: path to the WordPress test framework (tests/phpunit of wordpress-develop).
 *  - WP_TESTS_CONFIG_FILE_PATH: wp-tests-config.php to use.
 *  - COMPOSER_VENDOR_DIR: vendor dir containing phpunit + yoast polyfills.
 */

$hprnb_vendor = getenv( 'COMPOSER_VENDOR_DIR' ) ?: dirname( __DIR__, 2 ) . '/vendor';
if ( file_exists( $hprnb_vendor . '/autoload.php' ) ) {
	require_once $hprnb_vendor . '/autoload.php';
}

$hprnb_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/opt/wp/develop/tests/phpunit';
if ( getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
} elseif ( file_exists( '/opt/wp/wp-tests-config.php' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', '/opt/wp/wp-tests-config.php' );
}

if ( ! file_exists( $hprnb_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test framework at {$hprnb_tests_dir}. Set WP_TESTS_DIR.\n";
	exit( 1 );
}

require_once $hprnb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/horizon-press-news-bar/horizon-press-news-bar.php';
	}
);

require $hprnb_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-hprnb-test-case.php';
