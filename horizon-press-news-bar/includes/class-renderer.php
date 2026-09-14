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

		$attributes = array(
			'id'                   => 'hprnb-root',
			'class'                => implode( ' ', self::root_classes( $settings ) ),
			'data-hprnb-generated' => (string) (int) ( $payload['generated_at'] ?? 0 ),
			'data-hprnb-stale'     => (string) self::stale_threshold( $settings ),
			'data-hprnb-layout'    => 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve',
			'data-hprnb-empty'     => $empty ? '1' : '0',
			'data-hprnb-desktop'   => (string) wp_json_encode( self::profile_data( $settings, 'd' ) ),
			'data-hprnb-mobile'    => (string) wp_json_encode( self::profile_data( $settings, 'm' ) ),
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
	 * Row height of the label strip in the stacked layout, row gap and block padding (px), and the
	 * title line-height factor. Mirrored by the stylesheet (--hprnb-strip, padding) and the admin script.
	 */
	const STRIP_HEIGHT = 22;
	const ROW_GAP      = 4;
	const BLOCK_PAD    = 12;
	const LINE_HEIGHT  = 1.3;

	/**
	 * Inline CSS variables carried by the root (and by the admin preview root).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function root_style( array $settings ): string {
		return sprintf(
			'--hprnb-bg:%1$s;--hprnb-fg:%2$s;--hprnb-label-bg:%3$s;--hprnb-label-fg:%4$s;--hprnb-hover:%5$s;--hprnb-font-size:%6$dpx;--hprnb-height:%7$dpx;--hprnb-d-lines:%8$d;--hprnb-z:%9$d;--hprnb-sep:%10$s;--hprnb-m-bg:%11$s;--hprnb-m-fg:%12$s;--hprnb-m-accent:%13$s;--hprnb-m-label-fg:%14$s;--hprnb-m-font-size:%15$dpx;--hprnb-m-height:%16$dpx;--hprnb-m-lines:%17$d',
			self::color( $settings['bg_color'], '#B00000' ),
			self::color( $settings['text_color'], '#FFFFFF' ),
			self::color( $settings['label_bg_color'], '#8F0000' ),
			self::color( $settings['label_text_color'], '#FFFFFF' ),
			self::color( $settings['link_hover_color'], '#FFFFFF' ),
			(int) $settings['font_size'],
			self::profile_height( $settings, 'd' ),
			self::profile_lines( $settings, 'd' ),
			(int) $settings['z_index'],
			self::css_string( isset( $settings['separator_char'] ) ? (string) $settings['separator_char'] : '•' ),
			self::color( $settings['mobile_bg_color'] ?? '', '#141414' ),
			self::color( $settings['mobile_text_color'] ?? '', '#F5F5F5' ),
			self::color( $settings['mobile_accent_color'] ?? '', '#E11D2A' ),
			self::color( $settings['mobile_label_text_color'] ?? '', '#FFFFFF' ),
			(int) ( $settings['mobile_font_size'] ?? 16 ),
			self::profile_height( $settings, 'm' ),
			self::profile_lines( $settings, 'm' )
		);
	}

	/**
	 * Every class of the root element: base, device, layout, separator and the two presentation
	 * profiles (d = desktop, from 768px; m = mobile, under 768px): layout (inline|stacked, plus
	 * `-end` when the inline label follows the headline), label style, live dot, multi-line wrap.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function root_classes( array $settings ): array {
		$classes = array(
			'hprnb-root',
			self::device_class( $settings ),
			'hprnb-root--' . ( 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve' ),
		);
		foreach ( self::separator_classes( $settings ) as $class ) {
			$classes[] = $class;
		}

		foreach ( array( 'd', 'm' ) as $p ) {
			$profile   = self::profile( $settings, $p );
			$classes[] = 'hprnb-root--' . $p . '-' . $profile['layout'];
			// label_position only applies from 768px: on a phone the inline label always precedes the headline.
			if ( 'd' === $p && 'inline' === $profile['layout'] && 'start' !== ( $settings['label_position'] ?? 'end' ) ) {
				$classes[] = 'hprnb-root--' . $p . '-end';
			}
			$classes[] = 'hprnb-root--' . $p . '-label-' . $profile['label'];
			if ( $profile['dot'] ) {
				$classes[] = 'hprnb-root--' . $p . '-dot';
			}
			if ( $profile['lines'] > 1 ) {
				$classes[] = 'hprnb-root--' . $p . '-wrap';
			}
		}
		if ( ! empty( $settings['mobile_show_separator'] ) ) {
			$classes[] = 'hprnb-root--m-sep';
			if ( ! empty( $settings['separator_after_last'] ) ) {
				$classes[] = 'hprnb-root--m-sep-loop';
			}
		}
		if ( ! empty( $settings['mobile_custom_colors'] ) ) {
			$classes[] = 'hprnb-root--m-colors';
		}
		if ( self::profile( $settings, 'm' )['collapse'] ) {
			$classes[] = 'hprnb-root--m-collapse';
		}

		return $classes;
	}

	/**
	 * Normalised presentation profile.
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' (desktop, from 768px) or 'm' (mobile, under 768px).
	 * @return array{layout:string,label:string,dot:bool,counter:bool,lines:int,progress:bool,mode:string,font_size:int,collapse:bool,swipe:bool}
	 */
	public static function profile( array $settings, string $p ): array {
		$mobile  = ( 'm' === $p );
		$prefix  = $mobile ? 'mobile_' : 'desktop_';
		$mode    = $mobile ? self::mobile_ticker( $settings ) : self::desktop_ticker( $settings );
		$layout  = 'stacked' === ( $settings[ $prefix . 'layout' ] ?? ( $mobile ? 'stacked' : 'inline' ) ) ? 'stacked' : 'inline';
		$label   = (string) ( $settings[ $prefix . 'label_style' ] ?? ( $mobile ? 'pill' : 'strip' ) );
		$label   = in_array( $label, array( 'pill', 'strip', 'hidden' ), true ) ? $label : ( $mobile ? 'pill' : 'strip' );
		$lines   = max( 1, min( 4, (int) ( $settings[ $prefix . 'lines' ] ?? ( $mobile ? 2 : 1 ) ) ) );
		$stacked = ( 'stacked' === $layout );

		return array(
			'layout'    => $layout,
			'label'     => $label,
			'dot'       => 'hidden' !== $label && ! empty( $settings[ $prefix . 'label_dot' ] ),
			'counter'   => 'rotate' === $mode && ! empty( $settings[ $prefix . 'show_counter' ] ),
			'lines'     => 'marquee' === $mode ? 1 : $lines,
			'progress'  => 'rotate' === $mode && ! empty( $settings[ $prefix . 'show_progress' ] ),
			'mode'      => $mode,
			'font_size' => (int) ( $mobile ? ( $settings['mobile_font_size'] ?? 16 ) : $settings['font_size'] ),
			'collapse'  => $mobile && $stacked && ! empty( $settings['mobile_hide_on_scroll'] ),
			'swipe'     => $mobile && 'rotate' === $mode && ! empty( $settings['mobile_swipe'] ),
		);
	}

	/**
	 * Behaviour flags of a profile read by the interactive script (data-hprnb-desktop / data-hprnb-mobile).
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' or 'm'.
	 * @return array<string, mixed>
	 */
	public static function profile_data( array $settings, string $p ): array {
		$profile = self::profile( $settings, $p );
		$data    = array(
			'layout'   => $profile['layout'],
			'lines'    => $profile['lines'],
			'counter'  => $profile['counter'],
			'progress' => $profile['progress'],
		);
		if ( 'm' === $p ) {
			$data['swipe']    = $profile['swipe'];
			$data['collapse'] = $profile['collapse'];
		}
		return $data;
	}

	/**
	 * Effective number of title lines of a profile (1 in marquee mode).
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' or 'm'.
	 * @return int
	 */
	public static function profile_lines( array $settings, string $p ): int {
		return self::profile( $settings, $p )['lines'];
	}

	/**
	 * Bar height of a profile in px: the label strip (stacked layout) plus the title lines plus the
	 * block padding, never below `bar_height`. The stylesheet lays the rows out with the same numbers.
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' or 'm'.
	 * @return int
	 */
	public static function profile_height( array $settings, string $p ): int {
		$profile = self::profile( $settings, $p );
		$needed  = $profile['lines'] * (int) ceil( $profile['font_size'] * self::LINE_HEIGHT ) + self::BLOCK_PAD;
		if ( 'stacked' === $profile['layout'] ) {
			$needed += self::STRIP_HEIGHT + self::ROW_GAP;
		}
		return max( (int) $settings['bar_height'], $needed );
	}

	/**
	 * Effective ticker mode from 768px: none|marquee|rotate|manual.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function desktop_ticker( array $settings ): string {
		$mode = (string) ( $settings['ticker_mode'] ?? 'marquee' );
		if ( empty( $settings['ticker_enabled'] ) || ! in_array( $mode, array( 'marquee', 'rotate', 'manual' ), true ) ) {
			return 'none';
		}
		return $mode;
	}

	/**
	 * Effective ticker mode under 768px: none|marquee|rotate|manual.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function mobile_ticker( array $settings ): string {
		$desktop = self::desktop_ticker( $settings );
		$mobile  = (string) ( $settings['mobile_ticker_mode'] ?? 'rotate' );
		if ( 'inherit' === $mobile ) {
			return $desktop;
		}
		if ( 'static' === $mobile ) {
			return 'none';
		}
		return in_array( $mobile, array( 'marquee', 'rotate', 'manual' ), true ) ? $mobile : $desktop;
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
		return ! empty( $settings['ticker_enabled'] )
			|| ! empty( $settings['close_button'] )
			|| ! empty( $settings['show_relative_time'] )
			|| 'none' !== self::mobile_ticker( $settings )
			|| self::profile( $settings, 'm' )['collapse'];
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
