<?php
/**
 * Admin bootstrap: menu, assets, notices, action links.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Rest_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the settings page into wp-admin. Assets are loaded on the plugin page only.
 */
final class Admin {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'horizon-press-news-bar';

	/**
	 * Hook suffix of the settings page.
	 */
	const HOOK_SUFFIX = 'settings_page_horizon-press-news-bar';

	/**
	 * Query argument carrying a notice code after admin-post redirects.
	 */
	const NOTICE_ARG = 'hprnb_notice';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( Settings_Page::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'plugin_action_links_' . HPRNB_BASENAME, array( self::class, 'action_links' ) );
	}

	/**
	 * Settings → News Bar.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_options_page(
			__( 'News Bar', 'horizon-press-news-bar' ),
			__( 'News Bar', 'horizon-press-news-bar' ),
			'manage_options',
			self::PAGE_SLUG,
			array( Settings_Page::class, 'render' )
		);
	}

	/**
	 * URL of the settings page.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Enqueues the admin assets on the plugin page only.
	 *
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		// The front stylesheet styles the live preview; the admin stylesheet neutralises its fixed position.
		wp_register_style( 'hprnb-bar', HPRNB_URL . 'assets/css/hprnb-bar' . $suffix . '.css', array(), HPRNB_VERSION );
		wp_style_add_data( 'hprnb-bar', 'rtl', 'replace' );
		if ( '' !== $suffix ) {
			wp_style_add_data( 'hprnb-bar', 'suffix', $suffix );
		}
		wp_enqueue_style( 'hprnb-bar' );

		// The admin stylesheet only uses logical properties: the same file serves LTR and RTL.
		wp_enqueue_style( 'hprnb-admin', HPRNB_URL . 'assets/css/hprnb-admin' . $suffix . '.css', array( 'hprnb-bar' ), HPRNB_VERSION );

		// The front interactive script animates the preview (rotation, progress, marquee, buttons).
		wp_enqueue_script(
			'hprnb-bar',
			HPRNB_URL . 'assets/js/hprnb-bar' . $suffix . '.js',
			array(),
			HPRNB_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_enqueue_script(
			'hprnb-admin',
			HPRNB_URL . 'assets/js/hprnb-admin' . $suffix . '.js',
			array( 'hprnb-bar' ),
			HPRNB_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'hprnb-admin',
			'hprnbAdmin',
			array(
				'restUrl'      => rest_url( Rest_Controller::NAMESPACE . '/preview' ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'emptyMessage' => Settings_Page::empty_message(),
				'i18n'         => array(
					'confirmReset'   => __( 'Restore every setting to its default value? This cannot be undone.', 'horizon-press-news-bar' ),
					'previewLoading' => __( 'Refreshing the preview…', 'horizon-press-news-bar' ),
					'previewError'   => __( 'The preview could not be refreshed.', 'horizon-press-news-bar' ),
					'previewUpdated' => __( 'Preview updated.', 'horizon-press-news-bar' ),
					/* translators: %s: contrast ratio, e.g. "3.1:1". */
					'contrastLow'    => __( 'Low contrast (%s). WCAG AA recommends at least 4.5:1 for text.', 'horizon-press-news-bar' ),
				),
			)
		);
	}

	/**
	 * Prints notices after import / export / reset redirects.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::HOOK_SUFFIX !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only, whitelisted notice code.
		$code = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		$messages = self::messages();
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ]['type'] ),
			esc_html( $messages[ $code ]['text'] )
		);
	}

	/**
	 * Whitelisted notice codes.
	 *
	 * @return array<string, array{type: string, text: string}>
	 */
	public static function messages(): array {
		return array(
			'imported'            => array(
				'type' => 'success',
				'text' => __( 'Settings imported.', 'horizon-press-news-bar' ),
			),
			'imported_ids'        => array(
				'type' => 'warning',
				'text' => __( 'Settings imported. The file contains category, tag or post IDs: check that they exist on this site, IDs differ between sites.', 'horizon-press-news-bar' ),
			),
			'import_error_upload' => array(
				'type' => 'error',
				'text' => __( 'Import failed: no file was received or the upload failed.', 'horizon-press-news-bar' ),
			),
			'import_error_size'   => array(
				'type' => 'error',
				'text' => __( 'Import failed: the file exceeds 256 KB.', 'horizon-press-news-bar' ),
			),
			'import_error_ext'    => array(
				'type' => 'error',
				'text' => __( 'Import failed: only .json files are accepted.', 'horizon-press-news-bar' ),
			),
			'import_error_json'   => array(
				'type' => 'error',
				'text' => __( 'Import failed: the file is not valid JSON.', 'horizon-press-news-bar' ),
			),
			'import_error_schema' => array(
				'type' => 'error',
				'text' => __( 'Import failed: the file is not a Horizon Press News Bar export or its schema version is not supported.', 'horizon-press-news-bar' ),
			),
			'reset_done'          => array(
				'type' => 'success',
				'text' => __( 'Settings restored to their defaults.', 'horizon-press-news-bar' ),
			),
			'reset_unconfirmed'   => array(
				'type' => 'error',
				'text' => __( 'Reset cancelled: tick the confirmation box first.', 'horizon-press-news-bar' ),
			),
			'forbidden'           => array(
				'type' => 'error',
				'text' => __( 'You are not allowed to do this.', 'horizon-press-news-bar' ),
			),
		);
	}

	/**
	 * Adds a "Settings" link on the plugins list.
	 *
	 * @param mixed $links Existing links.
	 * @return mixed
	 */
	public static function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'horizon-press-news-bar' ) . '</a>' );
		return $links;
	}
}
