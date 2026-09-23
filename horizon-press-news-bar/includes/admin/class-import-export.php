<?php
/**
 * Import / export / reset handlers (admin-post.php).
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * JSON export, whitelisted JSON import (never executed nor kept) and reset.
 */
final class Import_Export {

	/**
	 * Maximum accepted upload size in bytes (256 KB).
	 */
	const MAX_BYTES = 262144;

	/**
	 * Maximum JSON nesting depth.
	 */
	const JSON_DEPTH = 8;

	/**
	 * Registers admin-post handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_hprnb_export', array( self::class, 'export' ) );
		add_action( 'admin_post_hprnb_import', array( self::class, 'import' ) );
		add_action( 'admin_post_hprnb_reset', array( self::class, 'reset' ) );
	}

	/**
	 * Export payload.
	 *
	 * @return array
	 */
	public static function export_data(): array {
		return array(
			'_meta'    => array(
				'plugin'         => 'horizon-press-news-bar',
				'schema_version' => HPRNB_SCHEMA_VERSION,
				'plugin_version' => HPRNB_VERSION,
				'exported_at'    => gmdate( 'c' ),
			),
			'settings' => Settings::raw(),
		);
	}

	/**
	 * Streams the settings as a JSON download.
	 *
	 * @return void
	 */
	public static function export(): void {
		self::authorize( 'hprnb_export' );

		$json = wp_json_encode( self::export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="horizon-press-news-bar-settings-' . gmdate( 'Ymd' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document, not HTML.
		exit;
	}

	/**
	 * Validates an uploaded JSON file and imports the whitelisted settings.
	 *
	 * @return void
	 */
	public static function import(): void {
		self::authorize( 'hprnb_import' );

		$result = self::import_uploaded_file( isset( $_FILES['hprnb_import_file'] ) && is_array( $_FILES['hprnb_import_file'] ) ? $_FILES['hprnb_import_file'] : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- Nonce verified in authorize(); every field is validated in import_uploaded_file().
		self::redirect( $result );
	}

	/**
	 * Performs every import check and returns a notice code.
	 *
	 * @param array $file One $_FILES entry.
	 * @return string Notice code.
	 */
	public static function import_uploaded_file( array $file ): string {
		if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return 'import_error_upload';
		}

		$tmp = (string) $file['tmp_name'];
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return 'import_error_upload';
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$real = (int) filesize( $tmp );
		if ( $size > self::MAX_BYTES || $real > self::MAX_BYTES || $real < 2 ) {
			return 'import_error_size';
		}

		$name = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
		if ( 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return 'import_error_ext';
		}

		$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary upload, never kept.
		if ( false === $raw ) {
			return 'import_error_upload';
		}

		return self::import_json( $raw );
	}

	/**
	 * Validates a JSON document and imports it. Unknown keys are ignored, MIME is never checked.
	 *
	 * @param string $raw JSON document.
	 * @return string Notice code.
	 */
	public static function import_json( string $raw ): string {
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return 'import_error_size';
		}

		$data = json_decode( $raw, true, self::JSON_DEPTH );
		if ( ! is_array( $data ) ) {
			return 'import_error_json';
		}

		if ( ! isset( $data['_meta'], $data['settings'] ) || ! is_array( $data['_meta'] ) || ! is_array( $data['settings'] ) ) {
			return 'import_error_schema';
		}
		if ( ! isset( $data['_meta']['plugin'] ) || 'horizon-press-news-bar' !== $data['_meta']['plugin'] ) {
			return 'import_error_schema';
		}
		$schema_version = isset( $data['_meta']['schema_version'] ) && is_numeric( $data['_meta']['schema_version'] ) ? (int) $data['_meta']['schema_version'] : 0;
		if ( $schema_version < 1 || $schema_version > HPRNB_SCHEMA_VERSION ) {
			return 'import_error_schema';
		}

		$settings = is_array( $data['settings'] ) ? $data['settings'] : array();
		if ( $schema_version < HPRNB_SCHEMA_VERSION ) {
			$settings = Settings::migrate( $settings, $schema_version );
		}
		$clean = Settings::sanitize( $settings );
		Settings::update( $clean );
		Invalidation::invalidate();

		$has_ids = ! empty( $clean['categories_include'] ) || ! empty( $clean['categories_exclude'] ) || ! empty( $clean['tags_include'] ) || ! empty( $clean['content_exclude_post_ids'] ) || ! empty( $clean['display_exclude_ids'] );

		return $has_ids ? 'imported_ids' : 'imported';
	}

	/**
	 * Restores the defaults after an explicit confirmation.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::authorize( 'hprnb_reset' );

		$confirmed = isset( $_POST['hprnb_reset_confirm'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['hprnb_reset_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in authorize().
		if ( ! $confirmed ) {
			self::redirect( 'reset_unconfirmed' );
		}

		Settings::reset();
		Invalidation::invalidate();
		self::redirect( 'reset_done' );
	}

	/**
	 * Capability + nonce check. Dies on failure.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function authorize( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'horizon-press-news-bar' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back to the settings page with a notice code.
	 *
	 * @param string $code Notice code.
	 * @return void
	 */
	private static function redirect( string $code ): void {
		wp_safe_redirect( add_query_arg( Admin::NOTICE_ARG, $code, Admin::page_url() ) );
		exit;
	}
}
