<?php
/**
 * Renderer: the single source of the bar markup for SSR, REST, shortcode and preview.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Renders HTML from normalised items. Never queries the database.
 */
final class Renderer {

	/**
	 * Template folder name inside a theme for overrides.
	 */
	const THEME_DIR = 'horizon-press-news-bar';

	/**
	 * Builds a payload from items.
	 *
	 * @param array    $items        Normalised items.
	 * @param array    $settings     Settings.
	 * @param int|null $generated_at Generation timestamp (defaults to now).
	 * @return array
	 */
	public static function payload( array $items, array $settings, ?int $generated_at = null ): array {
		$items = array_values( $items );

		return array(
			'version'      => HPRNB_VERSION,
			'generated_at' => null === $generated_at ? time() : $generated_at,
			'count'        => count( $items ),
			'items'        => $items,
			'html'         => empty( $items ) ? '' : self::bar( $items, $settings ),
		);
	}

	/**
	 * The complete `<aside>` markup, or '' without items.
	 *
	 * @param array $items    Normalised items.
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function bar( array $items, array $settings ): string {
		$items = array_values( $items );
		if ( empty( $items ) ) {
			return '';
		}

		$html = trim(
			self::render_template(
				'bar',
				array(
					'items'    => $items,
					'settings' => $settings,
				)
			)
		);

		/**
		 * Filters the complete bar markup before it is cached.
		 *
		 * @param string $html     Bar markup (the `<aside>` element).
		 * @param array  $items    Normalised items.
		 * @param array  $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_bar_html', $html, $items, $settings );

		return is_string( $filtered ) ? $filtered : $html;
	}

	/**
	 * The `#hprnb-root` wrapper around the payload markup.
	 *
	 * @param array $payload  Payload.
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function root( array $payload, array $settings ): string {
		$html  = isset( $payload['html'] ) && is_string( $payload['html'] ) ? $payload['html'] : '';
		$count = isset( $payload['count'] ) ? (int) $payload['count'] : 0;
		$empty = ( $count < 1 || '' === $html );

		$classes = array(
			'hprnb-root',
			self::device_class( $settings ),
			'hprnb-root--' . ( 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve' ),
		);
		foreach ( self::separator_classes( $settings ) as $class ) {
			$classes[] = $class;
		}

		$attributes = array(
			'id'                   => 'hprnb-root',
			'class'                => implode( ' ', $classes ),
			'data-hprnb-generated' => (string) (int) ( $payload['generated_at'] ?? 0 ),
			'data-hprnb-stale'     => (string) self::stale_threshold( $settings ),
			'data-hprnb-layout'    => 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve',
			'data-hprnb-empty'     => $empty ? '1' : '0',
		);

		$urls = array();
		if ( 'hybrid' === $settings['render_mode'] ) {
			$urls['data-hprnb-endpoint'] = rest_url( Rest_Controller::NAMESPACE . '/items' );
			$urls['data-hprnb-css']      = Assets::style_url();
			if ( self::needs_interactive_js( $settings ) ) {
				$urls['data-hprnb-js'] = Assets::script_url( 'bar' );
			}
		}

		$out = '<div';
		foreach ( $attributes as $name => $value ) {
			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		foreach ( $urls as $name => $value ) {
			$out .= ' ' . $name . '="' . esc_url( $value ) . '"';
		}
		$out .= ' style="' . esc_attr( self::root_style( $settings ) ) . '"';
		if ( $empty ) {
			$out .= ' hidden';
		}
		$out .= '>' . ( $empty ? '' : $html ) . '</div>';

		return $out;
	}

	/**
	 * Inline CSS variables carried by the root (and by the admin preview root).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function root_style( array $settings ): string {
		return sprintf(
			'--hprnb-bg:%1$s;--hprnb-fg:%2$s;--hprnb-label-bg:%3$s;--hprnb-label-fg:%4$s;--hprnb-hover:%5$s;--hprnb-font-size:%6$dpx;--hprnb-height:%7$dpx;--hprnb-z:%8$d;--hprnb-sep:%9$s',
			self::color( $settings['bg_color'], '#B00000' ),
			self::color( $settings['text_color'], '#FFFFFF' ),
			self::color( $settings['label_bg_color'], '#8F0000' ),
			self::color( $settings['label_text_color'], '#FFFFFF' ),
			self::color( $settings['link_hover_color'], '#FFFFFF' ),
			(int) $settings['font_size'],
			(int) $settings['bar_height'],
			(int) $settings['z_index'],
			self::css_string( isset( $settings['separator_char'] ) ? (string) $settings['separator_char'] : '•' )
		);
	}

	/**
	 * Root classes driving the CSS separator pseudo-element (never part of the cached markup).
	 *
	 * @param array $settings Settings.
	 * @return string[] hprnb-bar--sep when enabled, plus hprnb-bar--sep-loop when the separator follows the last item.
	 */
	public static function separator_classes( array $settings ): array {
		if ( empty( $settings['show_separator'] ) ) {
			return array();
		}
		$classes = array( 'hprnb-bar--sep' );
		if ( ! empty( $settings['separator_after_last'] ) ) {
			$classes[] = 'hprnb-bar--sep-loop';
		}
		return $classes;
	}

	/**
	 * Single-quoted CSS string literal (used for the --hprnb-sep custom property).
	 *
	 * @param string $text Text (already sanitized).
	 * @return string
	 */
	public static function css_string( string $text ): string {
		$text = str_replace( array( "\r", "\n" ), '', $text );
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $text ) . "'";
	}

	/**
	 * Root class controlling device visibility (CSS only, fixed 768px breakpoint).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function device_class( array $settings ): string {
		$desktop = ! empty( $settings['show_on_desktop'] );
		$mobile  = ! empty( $settings['show_on_mobile'] );

		if ( $desktop && ! $mobile ) {
			return 'hprnb-hide-mobile';
		}
		if ( $mobile && ! $desktop ) {
			return 'hprnb-hide-desktop';
		}
		return 'hprnb-device-all';
	}

	/**
	 * Effective stale threshold in seconds (filtered, minimum 30).
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function stale_threshold( array $settings ): int {
		/**
		 * Filters the client-side staleness threshold in seconds.
		 *
		 * @param int   $seconds  Threshold from settings.
		 * @param array $settings Settings.
		 */
		$seconds = apply_filters( 'hprnb_stale_threshold', (int) $settings['stale_threshold'], $settings );
		return max( 30, (int) $seconds );
	}

	/**
	 * Whether the interactive script is needed for these settings.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function needs_interactive_js( array $settings ): bool {
		return ! empty( $settings['ticker_enabled'] ) || ! empty( $settings['close_button'] ) || ! empty( $settings['show_relative_time'] );
	}

	/**
	 * Locates a template, allowing theme overrides in `{theme}/horizon-press-news-bar/{name}.php`.
	 *
	 * @param string $name Template name without extension (bar|list|item).
	 * @return string Absolute path.
	 */
	public static function locate_template( string $name ): string {
		$name = sanitize_key( $name );
		$file = self::THEME_DIR . '/' . $name . '.php';

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . $file,
			trailingslashit( get_template_directory() ) . $file,
		);
		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return HPRNB_PATH . 'templates/' . $name . '.php';
	}

	/**
	 * Renders a template with an explicit `$context` array; variables are never extracted into the scope.
	 *
	 * @param string $name    Template name (bar|list|item).
	 * @param array  $context Context read by the template.
	 * @return string
	 */
	public static function render_template( string $name, array $context ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is read by the included template.
		$file = self::locate_template( $name );
		if ( ! is_readable( $file ) ) {
			return '';
		}

		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Server-side relative time label ("2 hours ago") or absolute date beyond the threshold.
	 *
	 * @param int      $timestamp Publication timestamp.
	 * @param array    $settings  Settings.
	 * @param int|null $now       Reference time (defaults to time()).
	 * @return string
	 */
	public static function relative_time_label( int $timestamp, array $settings, ?int $now = null ): string {
		$now     = null === $now ? time() : $now;
		$max_age = max( 1, (int) $settings['relative_time_max_hours'] ) * HOUR_IN_SECONDS;

		if ( $timestamp <= $now && ( $now - $timestamp ) < $max_age ) {
			/* translators: %s: human-readable time difference, e.g. "2 hours". */
			return sprintf( __( '%s ago', 'horizon-press-news-bar' ), human_time_diff( $timestamp, $now ) );
		}

		$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
		return (string) wp_date( '' === $format ? 'Y-m-d H:i' : $format, $timestamp );
	}

	/**
	 * Inline SVG icon markup (static, safe to print).
	 *
	 * @param string $name pause|play|prev|next|close.
	 * @return string
	 */
	public static function icon( string $name ): string {
		$paths = array(
			'pause' => '<rect x="6" y="5" width="4" height="14"/><rect x="14" y="5" width="4" height="14"/>',
			'play'  => '<polygon points="7 4 20 12 7 20 7 4"/>',
			'prev'  => '<polyline points="15 18 9 12 15 6"/>',
			'next'  => '<polyline points="9 18 15 12 9 6"/>',
			'close' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="hprnb-bar__icon hprnb-bar__icon--' . $name . '" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Returns a valid hex colour or the fallback.
	 *
	 * @param mixed  $value    Candidate colour.
	 * @param string $fallback Fallback colour.
	 * @return string
	 */
	private static function color( $value, string $fallback ): string {
		$color = is_string( $value ) ? sanitize_hex_color( $value ) : null;
		return empty( $color ) ? $fallback : $color;
	}
}
