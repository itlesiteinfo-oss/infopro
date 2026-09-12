<?php
/**
 * PSR-4-like autoloader mapping the plugin namespace to WordPress-style file names.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader for the HorizonPress\NewsBar namespace.
 *
 * `HorizonPress\NewsBar\Foo_Bar`        → includes/class-foo-bar.php
 * `HorizonPress\NewsBar\Admin\Foo_Bar`  → includes/admin/class-foo-bar.php
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	const PREFIX = 'HorizonPress\\NewsBar\\';

	/**
	 * Registers the autoloader once.
	 *
	 * @return void
	 */
	public static function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads the file for a class of the plugin namespace.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$parts    = explode( '\\', $relative );
		$class    = array_pop( $parts );
		$file     = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
		$dir      = __DIR__ . '/';

		foreach ( $parts as $part ) {
			$dir .= strtolower( $part ) . '/';
		}

		$path = $dir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
