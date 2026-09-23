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
	 * @param array    $urgent       Urgent items (2.14): shown instead of the bar while they last.
	 * @return array
	 */
	public static function payload( array $items, array $settings, ?int $generated_at = null, array $urgent = array() ): array {
		$items  = array_values( $items );
		$urgent = array_values( $urgent );

		return array(
			'version'      => HPRNB_VERSION,
			'generated_at' => null === $generated_at ? time() : $generated_at,
			'count'        => count( $items ),
			'items'        => $items,
			'html'         => empty( $items ) ? '' : self::bar( $items, $settings ),
			'urgent_count' => count( $urgent ),
			'urgent_items' => $urgent,
			'urgent_html'  => empty( $urgent ) ? '' : self::urgent_bar( $urgent, $settings ),
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
	 * The `<aside>` of the urgent articles (2.14): the bar template with the urgent flag, rendered
	 * under the settings of Urgent::render_settings() — no picture, a close button, no memory.
	 *
	 * @param array $items    Urgent items (each with `since` and `until`).
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function urgent_bar( array $items, array $settings ): string {
		$items = array_values( $items );
		if ( empty( $items ) ) {
			return '';
		}

		$html = trim(
			self::render_template(
				'bar',
				array(
					'items'    => $items,
					'settings' => Urgent::render_settings( $settings ),
					'urgent'   => true,
				)
			)
		);

		/**
		 * Filters the complete urgent bar markup before it is cached.
		 *
		 * @param string $html     Bar markup (the `<aside>` element).
		 * @param array  $items    Urgent items.
		 * @param array  $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_urgent_bar_html', $html, $items, $settings );

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
		if ( $count < 1 || '' === $html ) {
			$count = 0;
			$html  = '';
		}
		// The urgent bar (2.14) rides in front of the news bar: the script shows the one that applies
		// and hands over to the other when the last urgent article expires or the reader closes it.
		$urgent_html  = isset( $payload['urgent_html'] ) && is_string( $payload['urgent_html'] ) ? $payload['urgent_html'] : '';
		$urgent_count = isset( $payload['urgent_count'] ) ? (int) $payload['urgent_count'] : 0;
		if ( $urgent_count < 1 || '' === $urgent_html ) {
			$urgent_count = 0;
			$urgent_html  = '';
		}
		$urgent = $urgent_count > 0;
		$empty  = ( $count < 1 && ! $urgent );

		$attributes = array(
			'id'                   => 'hprnb-root',
			'class'                => implode( ' ', self::root_classes( $settings, false, $urgent ) ),
			'data-hprnb-generated' => (string) (int) ( $payload['generated_at'] ?? 0 ),
			'data-hprnb-stale'     => (string) self::stale_threshold( $settings ),
			'data-hprnb-layout'    => 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve',
			'data-hprnb-empty'     => $empty ? '1' : '0',
			// Read by the stylesheet (a single headline carries no separator) and by the analytics.
			'data-hprnb-count'     => (string) $count,
			// Urgent articles in front of the news bar right now (the script keeps it current); "off" when
			// the feature is switched off, so the bootstrap never asks the server on their account.
			'data-hprnb-urgent'    => Urgent::enabled( $settings ) ? (string) $urgent_count : 'off',
			// The article being read, so an impression can be tied to its page. 0 off a singular.
			'data-hprnb-post'      => (string) self::current_post_id(),
			'data-hprnb-desktop'   => (string) wp_json_encode( self::profile_data( $settings, 'd' ) ),
			'data-hprnb-mobile'    => (string) wp_json_encode( self::profile_data( $settings, 'm' ) ),
			'data-hprnb-reveal'    => (string) wp_json_encode( self::reveal_data( $settings ) ),
		);
		// 2.15: a page where only one of the two bars may show says which ("news" or "urgent"): the
		// bootstrap refreshes from the complete REST body and keeps only that one.
		if ( isset( $payload['show'] ) && in_array( $payload['show'], array( 'news', 'urgent' ), true ) ) {
			$attributes['data-hprnb-show'] = $payload['show'];
		}

		$urls = array();
		if ( 'hybrid' === $settings['render_mode'] ) {
			$urls['data-hprnb-endpoint'] = rest_url( Rest_Controller::NAMESPACE . '/items' );
			$urls['data-hprnb-css']      = Assets::style_url();
			// An urgent article brought in by the bootstrap needs the script that ends it on time.
			if ( self::needs_interactive_js( $settings ) || Urgent::enabled( $settings ) ) {
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
		$out .= '>' . ( $empty ? '' : $urgent_html . $html ) . '</div>';

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
	 * Mobile "flow" layout (v2 card): title line-height ratio (16px → 26px), minimum block padding
	 * and the extra pixels of the collapsed strip below the first line (12 + 26 + 2 = 40px by default).
	 */
	const FLOW_LINE  = 1.625;
	const FLOW_PAD   = 6;
	const PEEK_EXTRA = 2;

	/**
	 * Mobile "card" layout (the client's "Explore More" reference): block padding, the label row and
	 * the gap under it, the gap beside the 16:9 picture, and the distance a floating card keeps from
	 * the edges. Mirrored by the stylesheet and the admin script.
	 */
	const CARD_PAD       = 12;
	const CARD_GAP       = 10;
	const CARD_RATIO     = 0.5625;
	const CARD_FONT_PLUS = 2;
	const CARD_LINE      = 1.24;
	const CARD_LABEL     = 20;
	const CARD_ROW       = 8;
	const CARD_LINES_MAX = 3;
	const CARD_FLOAT     = 8;

	/**
	 * The URGENT bar (2.16, the chyron design): the one-line desktop bar is never under 48px, keeps at
	 * least 12px above and below its text (the top 12px carry the bevel), and the phone design sets
	 * its headline on a 1.4 line.
	 */
	const URGENT_D_MIN = 48;
	const URGENT_PAD   = 12;
	const URGENT_LINE  = 1.4;

	/**
	 * Inline CSS variables carried by the root (and by the admin preview root).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function root_style( array $settings ): string {
		return sprintf(
			'--hprnb-bg:%1$s;--hprnb-fg:%2$s;--hprnb-label-bg:%3$s;--hprnb-label-fg:%4$s;--hprnb-hover:%5$s;--hprnb-accent:%6$s;--hprnb-font-size:%7$dpx;--hprnb-height:%8$dpx;--hprnb-d-lines:%9$d;--hprnb-max:%10$dpx;--hprnb-gutter:%11$dpx;--hprnb-z:%12$d;--hprnb-sep:%13$s;--hprnb-m-bg:%14$s;--hprnb-m-fg:%15$s;--hprnb-m-accent:%16$s;--hprnb-m-label-fg:%17$s;--hprnb-m-font-size:%18$dpx;--hprnb-m-height:%19$dpx;--hprnb-m-lines:%20$d;--hprnb-m-line:%21$dpx;--hprnb-m-pad:%22$dpx;--hprnb-peek:%23$dpx;--hprnb-m-ctrls:%24$d;--hprnb-d-thumb:%25$dpx;--hprnb-m-thumb:%26$dpx;--hprnb-m-card-thumb:%27$dpx;--hprnb-m-card-thumb-h:%28$dpx;--hprnb-m-card-lines:%29$d;--hprnb-m-gap:%30$dpx;--hprnb-u-bg:%31$s;--hprnb-u-fg:%32$s;--hprnb-u-height:%33$dpx;--hprnb-u-m-height:%34$dpx;--hprnb-u-line:%35$dpx;--hprnb-u-pad:%36$dpx;--hprnb-u-lines:%37$d;--hprnb-u-fs:%38$dpx;--hprnb-u-m-fs:%39$dpx',
			self::color( $settings['bg_color'], '#1B1C20' ),
			self::color( $settings['text_color'], '#F5F5F5' ),
			self::color( $settings['label_bg_color'], '#CE3029' ),
			self::color( $settings['label_text_color'], '#FFFFFF' ),
			self::color( $settings['link_hover_color'], '#FFFFFF' ),
			self::color( $settings['accent_color'] ?? '', '#CE3029' ),
			(int) $settings['font_size'],
			self::profile_height( $settings, 'd' ),
			self::profile_lines( $settings, 'd' ),
			(int) ( $settings['max_width'] ?? 1230 ),
			(int) ( $settings['gutter'] ?? 15 ),
			(int) $settings['z_index'],
			self::css_string( isset( $settings['separator_char'] ) ? (string) $settings['separator_char'] : '•' ),
			self::color( $settings['mobile_bg_color'] ?? '', '#1B1C20' ),
			self::color( $settings['mobile_text_color'] ?? '', '#F5F5F5' ),
			self::color( $settings['mobile_accent_color'] ?? '', '#CE3029' ),
			self::color( $settings['mobile_label_text_color'] ?? '', '#FFFFFF' ),
			(int) ( $settings['mobile_font_size'] ?? 16 ),
			self::profile_height( $settings, 'm' ),
			self::profile_lines( $settings, 'm' ),
			self::mobile_metrics( $settings )['line'],
			self::mobile_metrics( $settings )['pad'],
			self::peek_height( $settings ),
			self::mobile_controls( $settings ),
			(int) ( $settings['desktop_thumb_size'] ?? 32 ),
			(int) ( $settings['mobile_thumb_size'] ?? 48 ),
			self::card_metrics( $settings )['thumb'],
			self::card_metrics( $settings )['thumb_height'],
			self::card_metrics( $settings )['lines'],
			self::mobile_gap( $settings ),
			self::color( $settings['urgent_bg_color'] ?? '', '#E11D2B' ),
			self::color( $settings['urgent_text_color'] ?? '', '#FFFFFF' ),
			self::urgent_height( $settings, 'd' ),
			self::urgent_height( $settings, 'm' ),
			self::urgent_metrics( $settings )['line'],
			self::urgent_metrics( $settings )['pad'],
			self::urgent_metrics( $settings )['lines'],
			self::urgent_font( $settings, 'd' ),
			self::urgent_font( $settings, 'm' )
		);
	}

	/**
	 * Every class of the root element: base, device, layout, separator and the two presentation
	 * profiles (d = desktop, from 768px; m = mobile, under 768px): layout (inline|stacked, plus
	 * `-end` when the inline label follows the headline), label style, live dot, multi-line wrap.
	 *
	 * @param array $settings Settings.
	 * @param bool  $preview  True for the admin preview root, which never carries the pending classes.
	 * @param bool  $urgent   True while urgent articles are in front (2.14): the root says so and waits for nobody.
	 * @return string[]
	 */
	public static function root_classes( array $settings, bool $preview = false, bool $urgent = false ): array {
		// 2.16: the URGENT bar has a switch per device. It is in front only where it shows; elsewhere the
		// news bar keeps the page as if nothing were urgent (its wait, its restriction, its height).
		$front  = self::urgent_front( $settings, $urgent );
		$device = self::device_class( $settings );
		$names  = array(
			'd' => 'desktop',
			'm' => 'mobile',
		);
		foreach ( $names as $p => $name ) {
			if ( $front[ $p ] ) {
				$device = str_replace( 'hprnb-hide-' . $name, 'hprnb-news-hide-' . $name, $device );
			}
		}
		$classes = array(
			'hprnb-root',
			$device,
			'hprnb-root--' . ( 'overlay' === $settings['layout_mode'] ? 'overlay' : 'reserve' ),
		);
		if ( ! empty( $settings['align_container'] ) ) {
			$classes[] = 'hprnb-root--align';
		}
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
			if ( $profile['lines'] > 1 && ! in_array( $profile['layout'], array( 'flow', 'card' ), true ) ) {
				$classes[] = 'hprnb-root--' . $p . '-wrap';
			}
			if ( 'inline' === $profile['placement'] ) {
				// Inside the article instead of pinned to the viewport: no fixed position, no
				// reserved space, no collapsing. `alignfull` is the WordPress way of telling a
				// constrained block layout to let the element span the screen.
				$classes[] = 'hprnb-root--' . $p . '-inflow';
				if ( ! in_array( 'alignfull', $classes, true ) ) {
					$classes[] = 'alignfull';
				}
			}
			if ( $profile['thumb'] ) {
				$classes[] = 'hprnb-root--' . $p . '-thumb';
				if ( 'after' === $profile['thumb_position'] ) {
					$classes[] = 'hprnb-root--' . $p . '-thumb-after';
				}
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
		if ( self::profile( $settings, 'd' )['collapse'] ) {
			$classes[] = 'hprnb-root--d-collapse';
		}
		if ( 'label' === ( $settings['mobile_peek'] ?? 'headline' ) ) {
			$classes[] = 'hprnb-root--peek-label';
		}
		if ( ! empty( $settings['accent_edge'] ) ) {
			$classes[] = 'hprnb-root--edge';
		}
		// Server-rendered so the bar never flashes before the reader reaches that device's trigger.
		// The admin preview is never pending: it must show the bar, whatever the site waits for.
		$waits = false;
		foreach ( array( 'd', 'm' ) as $p ) {
			if ( 'immediate' !== self::reveal_mode( $settings, $p ) ) {
				$waits = true;
				if ( ! $preview && ! $front[ $p ] ) {
					$classes[] = 'hprnb-root--' . $p . '-pending';
				}
			}
		}
		if ( $waits && ! $preview && ! ( $front['d'] && $front['m'] ) ) {
			// The entrance carries its own transition: the script removes the pending class, not
			// this one, so the bar slides in whether or not the profile folds away afterwards.
			$classes[] = 'hprnb-root--reveal';
		}
		$outside = 'outside' === ( $settings['mobile_controls_place'] ?? 'inside' );
		if ( 'card' === self::profile( $settings, 'm' )['layout'] ) {
			$outside = false; // The card places its own buttons: a tab of its own colour above its end corner.
			if ( ! empty( $settings['mobile_card_float'] ) ) {
				$classes[] = 'hprnb-root--m-float';
			}
		} elseif ( self::profile( $settings, 'm' )['tab'] ) {
			// The flowing bar with its picture: the picture takes the buttons' place, the buttons
			// move to a tab above the end corner — the card's tab.
			$classes[] = 'hprnb-root--m-ctrl-tab';
		} elseif ( $outside ) {
			$classes[] = 'hprnb-root--m-ctrl-out';
		} elseif ( 'row' !== ( $settings['mobile_controls_layout'] ?? 'column' ) ) {
			// The floating group is a row of its own: the stacked column never applies to it.
			$classes[] = 'hprnb-root--m-ctrl-col';
		}
		// Both designs carry their picture into the folded strip (the image switch still serves the
		// fallback label row of the other ticker modes).
		$mobile      = self::profile( $settings, 'm' );
		$has_picture = $mobile['tab'] || 'card' === $mobile['layout'] || ! empty( $settings['mobile_show_thumbnail'] );
		if ( $has_picture && ! empty( $settings['mobile_peek_thumbnail'] ) ) {
			$classes[] = 'hprnb-root--m-peek-thumb';
		}
		if ( ! empty( $settings['mobile_show_thumbnail'] ) ) {
			if ( ! empty( $settings['mobile_label_compact'] ) ) {
				$classes[] = 'hprnb-root--m-label-compact';
			}
		}
		$pulse     = (string) ( $settings['mobile_label_pulse'] ?? 'appear' );
		$classes[] = 'hprnb-root--m-pulse-' . ( in_array( $pulse, array( 'always', 'appear', 'collapsed', 'never' ), true ) ? $pulse : 'appear' );
		if ( $urgent ) {
			// Urgent articles in front: the news bar is out of sight until the script hands over.
			$classes[] = 'hprnb-root--urgent';
		}
		// 2.16: a device the URGENT bar is switched off on (the feature itself being on).
		$devices = Urgent::devices( $settings );
		if ( $devices['d'] !== $devices['m'] ) {
			$classes[] = $devices['d'] ? 'hprnb-root--u-no-m' : 'hprnb-root--u-no-d';
		}
		if ( 'theme' !== ( $settings['bar_font'] ?? 'news' ) ) {
			// 2.16: both bars in the news face (the system sans of each platform) unless the site keeps its own.
			$classes[] = 'hprnb-root--font-news';
		}
		if ( 'mobile' === ( $settings['urgent_desktop_layout'] ?? 'line' ) ) {
			// 2.15: the URGENT bar keeps its phone design from 768px too (two lines, the tab above the corner).
			$classes[] = 'hprnb-root--u-d-flow';
		}

		return $classes;
	}

	/**
	 * Normalised presentation profile.
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' (desktop, from 768px) or 'm' (mobile, under 768px).
	 * @return array{layout:string,label:string,dot:bool,counter:bool,lines:int,progress:bool,mode:string,font_size:int,thumb:bool,thumb_position:string,thumb_size:int,collapse:bool,swipe:bool,next:bool,tab:bool}
	 */
	public static function profile( array $settings, string $p ): array {
		$mobile = ( 'm' === $p );
		$prefix = $mobile ? 'mobile_' : 'desktop_';
		$mode   = $mobile ? self::mobile_ticker( $settings ) : self::desktop_ticker( $settings );
		$layout = (string) ( $settings[ $prefix . 'layout' ] ?? ( $mobile ? 'flow_image' : 'inline' ) );
		// The flowing bar with the article picture: the flowing layout itself, with its picture always
		// at the end, where the buttons were, and the buttons in a tab above the corner, as on the card.
		$image_bar = $mobile && 'flow_image' === $layout;
		if ( $image_bar ) {
			$layout = 'flow';
		}
		$layout = in_array( $layout, $mobile ? array( 'flow', 'stacked', 'inline', 'card' ) : array( 'inline', 'stacked' ), true ) ? $layout : ( $mobile ? 'flow' : 'inline' );
		if ( in_array( $layout, array( 'flow', 'card' ), true ) && 'rotate' !== $mode ) {
			$layout = 'stacked'; // The card designs show one headline at a time: any other mode uses the label row.
		}
		$label   = (string) ( $settings[ $prefix . 'label_style' ] ?? ( $mobile ? 'pill' : 'strip' ) );
		$label   = in_array( $label, array( 'pill', 'strip', 'hidden' ), true ) ? $label : ( $mobile ? 'pill' : 'strip' );
		$lines   = max( 1, min( 4, (int) ( $settings[ $prefix . 'lines' ] ?? ( $mobile ? 2 : 1 ) ) ) );
		$stacked = ( 'inline' !== $layout );

		return array(
			'layout'         => $layout,
			'label'          => $label,
			'dot'            => 'hidden' !== $label && ! empty( $settings[ $prefix . 'label_dot' ] ),
			'counter'        => 'rotate' === $mode && ! empty( $settings[ $prefix . 'show_counter' ] ),
			'lines'          => 'marquee' === $mode ? 1 : $lines,
			'progress'       => 'rotate' === $mode && ! empty( $settings[ $prefix . 'show_progress' ] ),
			'mode'           => $mode,
			'font_size'      => (int) ( $mobile ? ( $settings['mobile_font_size'] ?? 16 ) : $settings['font_size'] ),
			// The label row (stacked), the first line (flow) or the heading (card) stays visible when
			// collapsed; on desktop the bar slides away entirely and leaves its chevron tab.
			'collapse'       => $mobile
				? ( $stacked && ! empty( $settings['mobile_hide_on_scroll'] ) )
				: ! empty( $settings['desktop_hide_on_scroll'] ),
			'placement'      => 'inline' === ( $settings[ $prefix . 'placement' ] ?? 'fixed' ) ? 'inline' : 'fixed',
			'swipe'          => $mobile && 'rotate' === $mode && ! empty( $settings['mobile_swipe'] ),
			'thumb'          => ! empty( $settings[ $prefix . 'show_thumbnail' ] ) || 'card' === $layout || $image_bar,
			'thumb_position' => ( $image_bar || 'after' === ( $settings[ $prefix . 'thumb_position' ] ?? ( $mobile ? 'after' : 'before' ) ) ) ? 'after' : 'before',
			// Buttons in a tab of the bar's own colour above its end corner (the card has its own rule).
			'tab'            => $image_bar,
			'thumb_size'     => (int) ( $settings[ $prefix . 'thumb_size' ] ?? ( $mobile ? 48 : 32 ) ),
			'peek'           => 'label' === ( $settings['mobile_peek'] ?? 'headline' ) ? 'label' : 'headline',
			'deep'           => $mobile && $stacked && ! empty( $settings['mobile_deep_collapse'] ),
			// Continuous loading: gone for good once the reader is in the next article. A bar placed
			// inside the article scrolls away with it and needs nothing of the kind.
			'next'           => ! empty( $settings[ $prefix . 'next_hide' ] ) && 'inline' !== ( $settings[ $prefix . 'placement' ] ?? 'fixed' ),
			'kbd'            => $mobile && ! empty( $settings['mobile_kbd_hide'] ),
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
			'place'    => $profile['placement'],
		);
		// Only a single article has a "next article" below it; a listing of full posts does not.
		if ( $profile['next'] && self::current_post_id() > 0 ) {
			$data['next'] = true;
		}
		if ( 'd' === $p ) {
			$data['collapse'] = $profile['collapse'];
			$data['trigger']  = (string) ( $settings['desktop_collapse_mode'] ?? 'scroll' );
			$data['after']    = (int) ( $settings['desktop_collapse_after'] ?? 120 );
		}
		if ( 'm' === $p ) {
			$data['swipe']    = $profile['swipe'];
			$data['collapse'] = $profile['collapse'];
			$data['peek']     = $profile['peek'];
			$data['deep']     = $profile['deep'];
			$data['kbd']      = $profile['kbd'];
			$data['pause']    = ! empty( $settings['mobile_show_pause'] );
			$data['close']    = ! empty( $settings['mobile_show_close'] );
			$data['trigger']  = (string) ( $settings['mobile_collapse_mode'] ?? 'scroll' );
			$data['after']    = (int) ( $settings['mobile_collapse_after'] ?? 120 );
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
		if ( 'flow' === $profile['layout'] ) {
			return self::flow_metrics( $settings )['height'];
		}
		if ( 'card' === $profile['layout'] ) {
			return self::card_metrics( $settings )['height'];
		}
		$needed = $profile['lines'] * (int) ceil( $profile['font_size'] * self::LINE_HEIGHT ) + self::BLOCK_PAD;
		if ( 'stacked' === $profile['layout'] ) {
			$needed += self::STRIP_HEIGHT + self::ROW_GAP;
		}
		if ( $profile['thumb'] ) {
			$needed = max( $needed, $profile['thumb_size'] + self::BLOCK_PAD - 4 );
		}
		return max( (int) $settings['bar_height'], $needed );
	}

	/**
	 * Metrics of the mobile "flow" card: title line-height (px), card height (never below
	 * `mobile_bar_height`, never clipping the configured lines), block padding, and the height
	 * of the collapsed strip (first line + padding). 16px / 2 lines / 76px → line 26, pad 12, peek 40.
	 *
	 * @param array $settings Settings.
	 * @return array{line:int,height:int,pad:int,peek:int}
	 */
	public static function flow_metrics( array $settings ): array {
		$profile = self::profile( $settings, 'm' );
		$line    = (int) round( $profile['font_size'] * self::FLOW_LINE );
		$height  = max( (int) ( $settings['mobile_bar_height'] ?? 76 ), $profile['lines'] * $line + 2 * self::FLOW_PAD );
		$pad     = (int) floor( ( $height - $profile['lines'] * $line ) / 2 );
		return array(
			'line'   => $line,
			'height' => $height,
			'pad'    => $pad,
			'peek'   => $pad + $line + self::PEEK_EXTRA,
		);
	}

	/**
	 * Line height and block padding of the mobile card in use (flow or "discover"): the values the
	 * root exposes as --hprnb-m-line / --hprnb-m-pad.
	 *
	 * @param array $settings Settings.
	 * @return array{line:int,pad:int}
	 */
	public static function mobile_metrics( array $settings ): array {
		$metrics = 'card' === self::profile( $settings, 'm' )['layout'] ? self::card_metrics( $settings ) : self::flow_metrics( $settings );
		return array(
			'line' => $metrics['line'],
			'pad'  => $metrics['pad'],
		);
	}

	/**
	 * Metrics of the mobile card: a label row, then the picture at the start of the line and the
	 * headline beside it. The headline follows `mobile_lines`, capped at CARD_LINES_MAX so a card
	 * stays a card. 16px / 3 lines / a 132px picture → line 22, picture 132 x 74, height 126
	 * (12 + 20 + 8 + max(74, 66) + 12), peek 36.
	 *
	 * @param array $settings Settings.
	 * @return array{line:int,thumb:int,thumb_height:int,height:int,pad:int,peek:int}
	 */
	public static function card_metrics( array $settings ): array {
		$profile = self::profile( $settings, 'm' );
		// The headline is the point of this design: two sizes above the profile, tight line.
		$font    = $profile['font_size'] + self::CARD_FONT_PLUS;
		$line    = (int) round( $font * self::CARD_LINE );
		$thumb   = max( 72, min( 160, (int) ( $settings['mobile_card_thumb'] ?? 132 ) ) );
		$thumb_h = (int) round( $thumb * self::CARD_RATIO );
		// The headline over as many lines as the profile asks, up to the card cap, beside the picture;
		// the label has a row of its own above them.
		$lines  = max( 1, min( self::CARD_LINES_MAX, (int) $profile['lines'] ) );
		$text   = $lines * $line;
		$body   = max( $thumb_h, $text );
		$height = 2 * self::CARD_PAD + self::CARD_LABEL + self::CARD_ROW + $body;

		return array(
			'font'         => $font,
			'line'         => $line,
			'lines'        => $lines,
			'thumb'        => $thumb,
			'thumb_height' => $thumb_h,
			'text'         => $text,
			'height'       => $height,
			'pad'          => self::CARD_PAD,
			// Collapsed it is the same strip as the flowing card: the pulsing pill and one line.
			'peek'         => self::CARD_PAD + $line + self::PEEK_EXTRA,
		);
	}

	/**
	 * On which device the URGENT bar is in front (2.16): urgent articles there, and the bar switched on
	 * for that device.
	 *
	 * @param array $settings Settings.
	 * @param bool  $urgent   Whether urgent articles are there at all.
	 * @return array{d:bool,m:bool}
	 */
	public static function urgent_front( array $settings, bool $urgent ): array {
		$devices = Urgent::devices( $settings );
		return array(
			'd' => $urgent && $devices['d'],
			'm' => $urgent && $devices['m'],
		);
	}

	/**
	 * Metrics of the urgent bar on a phone (2.14): the flowing bar's line and padding for the profile's
	 * font size, at most two lines, never below the mobile bar height — whatever the news bar's design,
	 * since the urgent bar keeps one shape. 17px / 2 lines / 76px → line 24, pad 14 (2.16).
	 *
	 * @param array $settings Settings.
	 * @return array{font:int,line:int,lines:int,height:int,pad:int}
	 */
	public static function urgent_metrics( array $settings ): array {
		$font   = self::urgent_font( $settings, 'm' );
		$lines  = max( 1, min( 2, (int) ( $settings['mobile_lines'] ?? 2 ) ) );
		$line   = (int) round( $font * self::URGENT_LINE );
		$height = max( (int) ( $settings['mobile_bar_height'] ?? 76 ), $lines * $line + 2 * self::URGENT_PAD );
		return array(
			'font'   => $font,
			'line'   => $line,
			'lines'  => $lines,
			'height' => $height,
			'pad'    => (int) floor( ( $height - $lines * $line ) / 2 ),
		);
	}

	/**
	 * Height of the urgent bar (2.14) on a device: one line on desktop, the metrics above on a phone —
	 * and on desktop too when it keeps the phone design there (2.15).
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' or 'm'.
	 * @return int
	 */
	public static function urgent_height( array $settings, string $p ): int {
		if ( 'm' === $p || 'mobile' === ( $settings['urgent_desktop_layout'] ?? 'line' ) ) {
			return self::urgent_metrics( $settings )['height'];
		}
		return max( self::URGENT_D_MIN, (int) ( $settings['bar_height'] ?? 40 ), (int) ceil( self::urgent_font( $settings, 'd' ) * self::LINE_HEIGHT ) + 2 * self::URGENT_PAD );
	}

	/**
	 * Size of the URGENT headline (2.16): the one-line desktop design, or phones and the phone design.
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' (one-line desktop design) or 'm'.
	 * @return int
	 */
	public static function urgent_font( array $settings, string $p ): int {
		if ( 'd' === $p ) {
			return max( 14, min( 22, (int) ( $settings['urgent_font_size'] ?? 17 ) ) );
		}
		return max( 14, min( 20, (int) ( $settings['urgent_mobile_font_size'] ?? 17 ) ) );
	}

	/**
	 * The singular object being viewed, for the analytics payload. Conditional tags are only
	 * meaningful once the main query has run, and the REST and shortcode paths have no page of
	 * their own: 0 then, which the script reads as "not an article".
	 *
	 * @return int
	 */
	public static function current_post_id(): int {
		if ( ! did_action( 'wp' ) || ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}

	/**
	 * Gap the mobile bar leaves between itself and the edges of the screen: the "discover" card
	 * floats, every other layout is flush. The body reserves that gap on top of the bar height, and
	 * the card carries the safe area itself so it is never counted twice.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function mobile_gap( array $settings ): int {
		return ( 'card' === self::profile( $settings, 'm' )['layout'] && ! empty( $settings['mobile_card_float'] ) ) ? self::CARD_FLOAT : 0;
	}

	/**
	 * Effective reveal mode of a profile.
	 *
	 * @param array  $settings Settings.
	 * @param string $p        'd' or 'm'.
	 * @return string
	 */
	public static function reveal_mode( array $settings, string $p ): string {
		$mode = (string) ( $settings[ ( 'm' === $p ? 'mobile_' : 'desktop_' ) . 'reveal_mode' ] ?? 'immediate' );
		return in_array( $mode, Settings::REVEAL_MODES, true ) ? $mode : 'immediate';
	}

	/**
	 * When the bar is allowed to appear: right away, after a scroll distance, after a share of the
	 * page, or near its end. Read by the interactive script (data-hprnb-reveal).
	 *
	 * @param array $settings Settings.
	 * @return array{mode:string,value:int}
	 */
	public static function reveal_data( array $settings ): array {
		$profile = static function ( string $p ) use ( $settings ): array {
			$prefix = 'm' === $p ? 'mobile_' : 'desktop_';
			$mode   = self::reveal_mode( $settings, $p );
			$value  = (int) ( $settings[ $prefix . 'reveal_value' ] ?? 400 );
			if ( 'percent' === $mode ) {
				$value = max( 1, min( 100, $value ) );
			} elseif ( 'end' === $mode ) {
				$value = 90;
			}
			$data = array(
				'mode'  => $mode,
				'value' => $value,
			);
			if ( 'paragraph' === $mode ) {
				// Counted from the end of the article body: 2 is the second-to-last paragraph.
				$data['paragraph'] = max( 1, min( 30, (int) ( $settings[ $prefix . 'reveal_paragraph' ] ?? 2 ) ) );
			}
			return $data;
		};
		// Each device decides for itself; the script reads the block of the active profile.
		$data = array(
			'd' => $profile( 'd' ),
			'm' => $profile( 'm' ),
		);
		if ( 'smart' === $data['d']['mode'] || 'smart' === $data['m']['mode'] ) {
			$data['smart'] = self::smart_data( $settings );
		}
		// The editorial-body selector serves the paragraph trigger and the "follows the reading"
		// collapse as well as the smart mode, so it travels at the top level whenever it is set.
		$selector = trim( (string) ( $settings['smart_selector'] ?? '' ) );
		if ( '' !== $selector ) {
			$data['sel'] = $selector;
		}

		return $data;
	}

	/**
	 * Tuning of the "smart" reveal, per profile, plus the optional editorial-body selector. Short
	 * key names: this travels in an attribute on every page.
	 *
	 * @param array $settings Settings.
	 * @return array{sel:string,d:array<string,int>,m:array<string,int>}
	 */
	public static function smart_data( array $settings ): array {
		$profile = static function ( string $prefix ) use ( $settings ): array {
			return array(
				// Reading of the article body, in percent, before a scroll up counts as intent.
				'p'  => max( 1, min( 100, (int) ( $settings[ $prefix . '_progress' ] ?? 55 ) ) ),
				// Active reading seconds before that same intent counts.
				't'  => max( 0, min( 120, (int) ( $settings[ $prefix . '_time' ] ?? 15 ) ) ),
				// Pixels of deliberate upward scrolling.
				'u'  => max( 50, min( 1200, (int) ( $settings[ $prefix . '_up' ] ?? 300 ) ) ),
				// Fallback: a reader deep in the article who never scrolled back up.
				'fp' => max( 1, min( 100, (int) ( $settings[ $prefix . '_fallback' ] ?? 75 ) ) ),
				'ft' => max( 0, min( 180, (int) ( $settings[ $prefix . '_fallback_time' ] ?? 25 ) ) ),
			);
		};

		return array(
			'sel' => (string) ( $settings['smart_selector'] ?? '' ),
			'd'   => $profile( 'smart_desktop' ),
			'm'   => $profile( 'smart_mobile' ),
		);
	}

	/**
	 * Height of the collapsed strip under 768px: the first line of the flow card, or the label row
	 * of the stacked layout (6 + 22 + 8). Also the mobile `--hprnb-offset` while collapsed.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function peek_height( array $settings ): int {
		$layout = self::profile( $settings, 'm' )['layout'];
		if ( 'flow' === $layout ) {
			return self::flow_metrics( $settings )['peek'];
		}
		if ( 'card' === $layout ) {
			return self::card_metrics( $settings )['peek'];
		}
		return 'stacked' === $layout ? 36 : self::profile_height( $settings, 'm' );
	}

	/**
	 * Width the control buttons take under 768px, in button columns: one when they are stacked
	 * (`mobile_controls_layout = column`, close above pause), otherwise one per button.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function mobile_controls( array $settings ): int {
		$profile = self::profile( $settings, 'm' );
		if ( 'outside' === ( $settings['mobile_controls_place'] ?? 'inside' ) || 'card' === $profile['layout'] || $profile['tab'] ) {
			return 0; // The buttons float above the bar, or sit in a tab above it: the headline takes the whole width.
		}
		$mode  = self::mobile_ticker( $settings );
		$pause = ! empty( $settings['mobile_show_pause'] ) && in_array( $mode, array( 'marquee', 'rotate' ), true );
		$count = ( ! empty( $settings['close_button'] ) && ! empty( $settings['mobile_show_close'] ) ) ? 1 : 0;
		if ( $pause ) {
			++$count;
		} elseif ( 'manual' === $mode ) {
			$count += 2;
		}
		if ( 0 === $count ) {
			return 0; // Both buttons hidden: the open card takes the whole width (the collapsed
			// strip still reserves one column for its chevron, in the stylesheet).
		}
		if ( 'row' !== ( $settings['mobile_controls_layout'] ?? 'column' ) ) {
			return 1;
		}
		return $count;
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
			|| self::profile( $settings, 'm' )['collapse']
			|| self::profile( $settings, 'd' )['collapse']
			|| self::profile( $settings, 'm' )['kbd']
			|| self::profile( $settings, 'm' )['next']
			|| self::profile( $settings, 'd' )['next']
			|| 'inline' === self::profile( $settings, 'm' )['placement']
			|| 'inline' === self::profile( $settings, 'd' )['placement']
			|| 'immediate' !== self::reveal_mode( $settings, 'd' )
			|| 'immediate' !== self::reveal_mode( $settings, 'm' );
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
