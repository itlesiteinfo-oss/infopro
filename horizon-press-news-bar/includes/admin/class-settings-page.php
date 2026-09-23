<?php
/**
 * Settings page: single form, seven sections, live preview, tools.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Query;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and registers the settings form. Fully usable without JavaScript.
 */
final class Settings_Page {

	/**
	 * Settings group (option page) name.
	 */
	const GROUP = 'hprnb';

	/**
	 * Maximum number of terms listed in a picker.
	 */
	const MAX_TERMS = 500;

	/**
	 * Guards against the sanitize callback running twice on the same request.
	 *
	 * @var bool
	 */
	private static bool $notified = false;

	/**
	 * Registers the option with the Settings API (admin_init).
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitize callback: the shared validator plus a non-blocking contrast warning.
	 *
	 * @param mixed $input Raw form data.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$clean = Settings::sanitize_form( $input );

		if ( ! self::$notified && function_exists( 'add_settings_error' ) ) {
			self::$notified = true;
			$warnings       = array();

			$ratio = self::contrast_ratio( $clean['text_color'], $clean['bg_color'] );
			if ( $ratio < 4.5 ) {
				/* translators: %s: contrast ratio, e.g. "3.1:1". */
				$warnings[] = sprintf( __( 'Text / background contrast is %s, below the 4.5:1 recommended by WCAG AA.', 'horizon-press-news-bar' ), self::format_ratio( $ratio ) );
			}
			$ratio = self::contrast_ratio( $clean['label_text_color'], $clean['label_bg_color'] );
			if ( $ratio < 4.5 ) {
				/* translators: %s: contrast ratio, e.g. "3.1:1". */
				$warnings[] = sprintf( __( 'Label text / label background contrast is %s, below the 4.5:1 recommended by WCAG AA.', 'horizon-press-news-bar' ), self::format_ratio( $ratio ) );
			}
			if ( ! empty( $clean['mobile_custom_colors'] ) ) {
				$ratio = self::contrast_ratio( $clean['mobile_text_color'], $clean['mobile_bg_color'] );
				if ( $ratio < 4.5 ) {
					/* translators: %s: contrast ratio, e.g. "3.1:1". */
					$warnings[] = sprintf( __( 'Mobile text / mobile background contrast is %s, below the 4.5:1 recommended by WCAG AA.', 'horizon-press-news-bar' ), self::format_ratio( $ratio ) );
				}
			}

			if ( ! empty( $warnings ) ) {
				add_settings_error( Settings::OPTION, 'hprnb_settings_saved', __( 'Settings saved.', 'horizon-press-news-bar' ), 'success' );
				add_settings_error( Settings::OPTION, 'hprnb_contrast', implode( ' ', $warnings ), 'warning' );
			}
		}

		return $clean;
	}

	/**
	 * WCAG 2.x contrast ratio between two hex colours.
	 *
	 * @param string $foreground Hex colour.
	 * @param string $background Hex colour.
	 * @return float
	 */
	public static function contrast_ratio( string $foreground, string $background ): float {
		$l1 = self::luminance( $foreground );
		$l2 = self::luminance( $background );
		if ( $l1 < $l2 ) {
			$swap = $l1;
			$l1   = $l2;
			$l2   = $swap;
		}
		return ( $l1 + 0.05 ) / ( $l2 + 0.05 );
	}

	/**
	 * Relative luminance of a hex colour (#RGB or #RRGGBB).
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	private static function luminance( string $hex ): float {
		$hex = ltrim( trim( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) {
			return 0.0;
		}
		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * "4.2:1" style formatting.
	 *
	 * @param float $ratio Ratio.
	 * @return string
	 */
	private static function format_ratio( float $ratio ): string {
		return number_format_i18n( $ratio, 1 ) . ':1';
	}

	/**
	 * Message shown when the preview has no item.
	 *
	 * @return string
	 */
	public static function empty_message(): string {
		return __( 'No post matches these criteria. The bar will not be displayed on the site.', 'horizon-press-news-bar' );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'horizon-press-news-bar' ), '', array( 'response' => 403 ) );
		}

		$settings = Settings::raw();
		$layout   = self::layout();
		?>
		<div class="wrap hprnb-wrap">
			<header class="hprnb-header">
				<div class="hprnb-header__title">
					<h1><?php esc_html_e( 'Horizon Press News Bar', 'horizon-press-news-bar' ); ?></h1>
					<span class="hprnb-header__version"><?php echo esc_html( sprintf( /* translators: %s: plugin version. */ __( 'version %s', 'horizon-press-news-bar' ), HPRNB_VERSION ) ); ?></span>
				</div>
				<div class="hprnb-header__actions">
					<label class="hprnb-switch hprnb-advanced-toggle" for="hprnb-advanced-toggle" hidden>
						<input type="checkbox" id="hprnb-advanced-toggle" />
						<span class="hprnb-switch__track" aria-hidden="true"></span><span class="hprnb-switch__text"><?php esc_html_e( 'Advanced settings', 'horizon-press-news-bar' ); ?></span>
					</label>
					<button type="button" class="button" id="hprnb-reset-tab" hidden><?php esc_html_e( 'Reset this tab', 'horizon-press-news-bar' ); ?></button>
					<button type="submit" form="hprnb-form" class="button button-primary" id="hprnb-save"><?php esc_html_e( 'Save', 'horizon-press-news-bar' ); ?></button>
				</div>
			</header>
			<?php settings_errors( Settings::OPTION ); ?>
			<div class="hprnb-layout">
				<div class="hprnb-main">
					<nav class="hprnb-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'horizon-press-news-bar' ); ?>" hidden>
						<?php foreach ( $layout as $tab_key => $tab ) : ?>
						<button type="button" role="tab" class="hprnb-tabs__tab" id="hprnb-tab-btn-<?php echo esc_attr( $tab_key ); ?>" aria-controls="hprnb-tab-<?php echo esc_attr( $tab_key ); ?>" aria-selected="false" data-hprnb-tab="<?php echo esc_attr( $tab_key ); ?>"<?php echo self::tab_is_advanced( $tab ) ? ' data-hprnb-advanced="1"' : ''; ?>><?php echo esc_html( $tab['title'] ); ?></button>
						<?php endforeach; ?>
					</nav>
					<form method="post" action="options.php" id="hprnb-form" class="hprnb-form">
						<?php settings_fields( self::GROUP ); ?>
						<?php self::render_tabs( $settings, $layout ); ?>
						<?php submit_button( __( 'Save settings', 'horizon-press-news-bar' ) ); ?>
					</form>
					<?php self::render_tools( $settings ); ?>
				</div>
				<?php self::render_preview( $settings ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Tabs and cards of the settings page. Each card lists its field keys; an optional `switch` key
	 * (a boolean setting) is rendered as the "Enable" toggle of the card header and greys the rows out.
	 *
	 * @return array<string, array{title: string, cards: array<int, array<string, mixed>>}>
	 */
	private static function layout(): array {
		return array(
			'content'  => array(
				'title' => __( 'Content', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'  => __( 'General', 'horizon-press-news-bar' ),
						'switch' => 'enabled',
						'keys'   => array( 'label_text', 'label_position' ),
					),
					array(
						'title'       => __( 'Which articles', 'horizon-press-news-bar' ),
						'description' => __( 'Posts are selected when they are published, inside the sliding time window (never "since midnight") and match the filters below. Only the publication date counts, never the modification date.', 'horizon-press-news-bar' ),
						'keys'        => array( 'window', 'max_items', 'orderby', 'categories_include', 'categories_exclude', 'tags_include', 'content_exclude_post_ids' ),
						'scenarios'   => array(
							array(
								'title' => __( 'A rolling "breaking news" bar', 'horizon-press-news-bar' ),
								'body'  => __( 'Time window 6 hours, 5 posts, newest first. Outside busy hours the bar empties itself and nothing is rendered at all.', 'horizon-press-news-bar' ),
							),
							array(
								'title' => __( 'One section only', 'horizon-press-news-bar' ),
								'body'  => __( 'Pick that category under Categories and widen the window to 48 hours, so the bar is never empty on a slow section.', 'horizon-press-news-bar' ),
							),
							array(
								'title' => __( 'Everything except one article', 'horizon-press-news-bar' ),
								'body'  => __( 'Leave the filters open and tick "Never list this article in the bar" in the News Bar box on that article\'s edit screen.', 'horizon-press-news-bar' ),
							),
						),
					),
					array(
						'title'    => __( 'Headline details', 'horizon-press-news-bar' ),
						'advanced' => true,
						'keys'     => array( 'show_relative_time', 'relative_time_max_hours', 'show_separator', 'separator_char', 'separator_after_last' ),
					),
				),
			),
			'where'    => array(
				'title' => __( 'Where', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'       => __( 'Page types', 'horizon-press-news-bar' ),
						'description' => __( 'The one place that decides where the bar is allowed. Everything else on this tab only narrows it further.', 'horizon-press-news-bar' ),
						'keys'        => array( 'display_scope', 'contexts' ),
						'scenarios'   => array(
							array(
								'title' => __( 'Only on single articles', 'horizon-press-news-bar' ),
								'body'  => __( 'Scope: "Only the page types ticked below", then leave only "Single post" ticked. Nothing else to set anywhere.', 'horizon-press-news-bar' ),
							),
							array(
								'title' => __( 'Everywhere except the home page', 'horizon-press-news-bar' ),
								'body'  => __( 'Same scope, untick "Front page" and leave the other eight ticked.', 'horizon-press-news-bar' ),
							),
							array(
								'title' => __( 'Articles on mobile, everywhere on desktop', 'horizon-press-news-bar' ),
								'body'  => __( 'Leave the scope on "Everywhere", then use "Refine per device" below and untick everything but "Single post" for mobile.', 'horizon-press-news-bar' ),
							),
						),
					),
					array(
						'title'       => __( 'Refine per device (optional)', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'Only useful when the two devices must differ. Each list narrows the page types above for that device alone; untick everything in one list and that device never shows the bar. A device is switched off altogether at the top of its own tab.', 'horizon-press-news-bar' ),
						'keys'        => array( 'desktop_contexts', 'mobile_contexts' ),
					),
					array(
						'title'       => __( 'Exceptions', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'For one-off pages. Each article and page also carries a "News Bar" box on its edit screen with the same two switches, which is usually quicker than listing IDs here.', 'horizon-press-news-bar' ),
						'keys'        => array( 'display_exclude_ids' ),
					),
					array(
						'title'       => __( 'Position in the page', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'Fixed keeps the bar against the edge of the screen; inside the article it is placed between two paragraphs and scrolls away with the text.', 'horizon-press-news-bar' ),
						'keys'        => array( 'layout_mode', 'desktop_placement', 'desktop_inline_anchor', 'desktop_inline_paragraph', 'mobile_placement', 'mobile_inline_anchor', 'mobile_inline_paragraph', 'z_index' ),
					),
				),
			),
			'mobile'   => array(
				'title' => __( 'Mobile', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'       => __( 'Behaviour on mobile', 'horizon-press-news-bar' ),
						'description' => __( 'One choice decides when the bar appears, when it folds and when it goes away. Pick one and save: there is nothing else to set.', 'horizon-press-news-bar' ),
						'compact'     => true,
						'keys'        => array( 'mobile_behavior', 'mobile_reveal_paragraph' ),
					),
					array(
						'title'       => __( 'Custom: when the bar appears and goes away (mobile)', 'horizon-press-news-bar' ),
						'description' => __( 'Only with the "Custom" behaviour. Until the bar appears no space is reserved for it and it stays out of view; once it appears it stays, unless the reader moves on to the next article and that option is ticked.', 'horizon-press-news-bar' ),
						'depends'     => 'mobile_behavior:custom',
						'keys'        => array( 'mobile_reveal_mode', 'mobile_reveal_value', 'smart_mobile_progress', 'smart_mobile_time', 'smart_mobile_up', 'smart_mobile_fallback', 'smart_mobile_fallback_time', 'mobile_next_hide' ),
					),
					array(
						'title'       => __( 'Custom: folding (mobile)', 'horizon-press-news-bar' ),
						'description' => __( 'Only with the "Custom" behaviour. Off, the bar keeps its full height while the reader scrolls. On, it shrinks to its label row and the first line of the headline, and a tap opens it again.', 'horizon-press-news-bar' ),
						'depends'     => 'mobile_behavior:custom',
						'switch'      => 'mobile_hide_on_scroll',
						'keys'        => array( 'mobile_collapse_mode', 'mobile_collapse_after', 'mobile_deep_collapse' ),
					),
					array(
						'title'       => __( 'Design', 'horizon-press-news-bar' ),
						'description' => __( 'Under 768 px, two designs. The bar with the article picture, the default: the label in front of the headline, the picture where the buttons were and the close button in a tab above the corner. The "Explore More" card: a label row, then the picture and the headline beside it. Folded, both keep a strip with the first line and the small picture, and the unfold button in the same tab. The height shown beside the line count is the height the page reserves.', 'horizon-press-news-bar' ),
						'switch'      => 'show_on_mobile',
						'keys'        => array( 'mobile_layout', 'mobile_lines', 'mobile_thumb_size', 'mobile_card_thumb', 'mobile_card_float', 'mobile_font_size', 'mobile_bar_height', 'mobile_peek', 'mobile_peek_thumbnail', 'mobile_label_style', 'mobile_label_dot', 'mobile_label_pulse', 'mobile_label_compact', 'mobile_show_counter', 'mobile_show_progress', 'mobile_show_separator', 'mobile_show_thumbnail', 'mobile_thumb_position', 'mobile_swipe', 'mobile_kbd_hide' ),
						'scenarios'   => array(
							array(
								'title' => __( 'The bar with the article picture, as delivered', 'horizon-press-news-bar' ),
								'body'  => __( 'Bar with the article picture, 2 lines, 16 px font, a 48 px picture, pause button off: the close button alone in its tab above the corner, the picture kept in the folded strip. "Reset this tab" brings every mobile setting back to it, together with Continuous reading.', 'horizon-press-news-bar' ),
							),
							array(
								'title' => __( 'The "Explore More" card', 'horizon-press-news-bar' ),
								'body'  => __( 'Pick the card, 3 lines and a 132 px picture: the design of the first mock-up, 126 px tall.', 'horizon-press-news-bar' ),
							),
						),
					),
					array(
						'title'       => __( 'Headlines, one after another (mobile)', 'horizon-press-news-bar' ),
						'description' => __( 'The card and the flowing bar show one headline at a time and rotate through them; the interval is shared with the desktop rotate mode.', 'horizon-press-news-bar' ),
						'keys'        => array( 'mobile_ticker_mode', 'rotate_interval' ),
					),
					array(
						'title'       => __( 'Buttons (mobile)', 'horizon-press-news-bar' ),
						'description' => __( 'Both designs carry their buttons in a tab above the corner: the close button, the pause button when it is on, and the unfold button once the bar is folded. The placement settings only serve the label row that replaces the design when the headlines do not rotate.', 'horizon-press-news-bar' ),
						'keys'        => array( 'mobile_show_pause', 'mobile_show_close', 'mobile_controls_place', 'mobile_controls_layout' ),
					),
					array(
						'title'       => __( 'Closing (both devices)', 'horizon-press-news-bar' ),
						'description' => __( 'A cross in the bar hides it; with the memory option the visitor does not see it again for the chosen duration (anti-flash head script). Folding and closing are independent: a folded bar is still there, a closed one is gone.', 'horizon-press-news-bar' ),
						'switch'      => 'close_button',
						'keys'        => array( 'remember_dismiss', 'dismiss_duration_hours' ),
					),
				),
			),
			'desktop'  => array(
				'title' => __( 'Desktop', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'       => __( 'Behaviour on desktop', 'horizon-press-news-bar' ),
						'description' => __( 'The same one choice as on mobile, made separately for screens from 768 px. Pick one and save: there is nothing else to set.', 'horizon-press-news-bar' ),
						'compact'     => true,
						'keys'        => array( 'desktop_behavior', 'desktop_reveal_paragraph' ),
					),
					array(
						'title'       => __( 'Custom: when the bar appears and goes away (desktop)', 'horizon-press-news-bar' ),
						'description' => __( 'Only with the "Custom" behaviour. Until the bar appears no space is reserved for it and it stays out of view; once it appears it stays, unless the reader moves on to the next article and that option is ticked.', 'horizon-press-news-bar' ),
						'depends'     => 'desktop_behavior:custom',
						'keys'        => array( 'desktop_reveal_mode', 'desktop_reveal_value', 'smart_desktop_progress', 'smart_desktop_time', 'smart_desktop_up', 'smart_desktop_fallback', 'smart_desktop_fallback_time', 'desktop_next_hide' ),
					),
					array(
						'title'       => __( 'Custom: folding (desktop)', 'horizon-press-news-bar' ),
						'description' => __( 'Only with the "Custom" behaviour. Off, the bar simply stays put. On, it slides out of view and leaves a small tab to bring it back; the two settings below then decide when.', 'horizon-press-news-bar' ),
						'depends'     => 'desktop_behavior:custom',
						'switch'      => 'desktop_hide_on_scroll',
						'keys'        => array( 'desktop_collapse_mode', 'desktop_collapse_after' ),
					),
					array(
						'title'       => __( 'Design', 'horizon-press-news-bar' ),
						'description' => __( 'From 768 px: a fixed bar aligned on the site container. The height follows the number of headline lines. The close button and its memory are set on the Mobile tab, under Closing: they apply to both devices.', 'horizon-press-news-bar' ),
						'switch'      => 'show_on_desktop',
						'keys'        => array( 'desktop_layout', 'desktop_lines', 'font_size', 'bar_height', 'desktop_label_style', 'desktop_label_dot', 'desktop_show_counter', 'desktop_show_progress', 'desktop_show_thumbnail', 'desktop_thumb_position', 'desktop_thumb_size', 'align_container', 'max_width', 'gutter' ),
					),
					array(
						'title'       => __( 'Headlines, one after another (desktop)', 'horizon-press-news-bar' ),
						'description' => __( 'How several headlines take their turn. Marquee always uses a single line; rotate and manual honour the line count. The rotate interval is on the Mobile tab and serves both.', 'horizon-press-news-bar' ),
						'keys'        => array( 'ticker_enabled', 'ticker_mode', 'ticker_speed', 'pause_on_hover' ),
					),
				),
			),
			'colors'   => array(
				'title' => __( 'Colours', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'       => __( 'Palette', 'horizon-press-news-bar' ),
						'description' => __( 'One palette for desktop and mobile. The theme red is only used for the label pill: that is what catches the eye.', 'horizon-press-news-bar' ),
						'presets'     => true,
						'keys'        => array( 'bg_color', 'text_color', 'label_bg_color', 'label_text_color', 'accent_color', 'link_hover_color', 'accent_edge' ),
					),
					array(
						'title'       => __( 'Dedicated mobile palette', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'Optional: other colours under 768 px.', 'horizon-press-news-bar' ),
						'switch'      => 'mobile_custom_colors',
						'keys'        => array( 'mobile_bg_color', 'mobile_text_color', 'mobile_accent_color', 'mobile_label_text_color' ),
					),
				),
			),
			'advanced' => array(
				'title' => __( 'Advanced', 'horizon-press-news-bar' ),
				'cards' => array(
					array(
						'title'       => __( 'Jannah fixed elements', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'The bar exposes its visible height as --hprnb-offset on <body> (with body.hprnb-is-collapsed, body.hprnb-kbd and the hprnb:state event). With this option the theme\'s "go to top" button, "Check also" box and reading position indicator sit above the bar and follow its collapse. Nothing in the theme is modified.', 'horizon-press-news-bar' ),
						'switch'      => 'theme_offset',
						'keys'        => array(),
					),
					array(
						'title'       => __( 'Article body', 'horizon-press-news-bar' ),
						'advanced'    => true,
						'description' => __( 'Continuous reading, "Before the end of the article", Smart and the next-article detection all measure the text of the article. Most themes need nothing here.', 'horizon-press-news-bar' ),
						'keys'        => array( 'smart_selector' ),
					),
					array(
						'title'    => __( 'Technical', 'horizon-press-news-bar' ),
						'advanced' => true,
						'keys'     => array( 'thumbnail_size', 'render_mode', 'cache_ttl', 'stale_threshold', 'auto_display', 'shortcode_enabled', 'uninstall_delete_data' ),
					),
				),
			),
		);
	}

	/**
	 * The behaviour choices of the Mobile and Desktop tabs, in plain words: what the reader sees.
	 *
	 * @return array<string, array{title: string, text: string}>
	 */
	private static function behavior_choices(): array {
		return array(
			'reading' => array(
				'title' => __( 'Continuous reading — recommended on articles', 'horizon-press-news-bar' ),
				'text'  => __( 'Hidden at first. Appears in full once the reader reaches the paragraph chosen below, counted from the end of the article. Folds as soon as the reader scrolls back up and opens again when reading on; stays open once the article is over; goes away completely when the reader moves on to the next article.', 'horizon-press-news-bar' ),
			),
			'fold'    => array(
				'title' => __( 'Visible, folds while scrolling', 'horizon-press-news-bar' ),
				'text'  => __( 'Appears with the page. Gets out of the way while the reader scrolls down and comes back on a scroll up.', 'horizon-press-news-bar' ),
			),
			'always'  => array(
				'title' => __( 'Always visible', 'horizon-press-news-bar' ),
				'text'  => __( 'Appears with the page and keeps its full size, whatever the scrolling.', 'horizon-press-news-bar' ),
			),
			'custom'  => array(
				'title' => __( 'Custom', 'horizon-press-news-bar' ),
				'text'  => __( 'Every detail is yours: two more blocks open just below, one for when the bar appears and goes away, one for folding.', 'horizon-press-news-bar' ),
			),
		);
	}

	/**
	 * Field definitions.
	 *
	 * Types: checkbox, text, number, select, radio, choice, color, terms, ids, window, contexts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields(): array {
		$sizes = array();
		foreach ( get_intermediate_image_sizes() as $size ) {
			$sizes[ $size ] = $size;
		}

		return array(
			'enabled'                     => array(
				'section' => 'general',
				'type'    => 'checkbox',
				'label'   => __( 'Enable the news bar', 'horizon-press-news-bar' ),
				'text'    => __( 'Display the bar on the public site.', 'horizon-press-news-bar' ),
			),
			'label_text'                  => array(
				'section' => 'general',
				'type'    => 'text',
				'label'   => __( 'Label', 'horizon-press-news-bar' ),
				'desc'    => __( 'Short text shown next to the headlines. Leave empty to hide the label.', 'horizon-press-news-bar' ),
				'attrs'   => array( 'maxlength' => 120 ),
			),
			'label_position'              => array(
				'section' => 'general',
				'type'    => 'radio',
				'label'   => __( 'Label position', 'horizon-press-news-bar' ),
				'options' => array(
					'start' => __( 'Start of the line', 'horizon-press-news-bar' ),
					'end'   => __( 'End of the line', 'horizon-press-news-bar' ),
				),
				'desc'    => __( '"End" is the right side in left-to-right languages and the left side in right-to-left languages.', 'horizon-press-news-bar' ),
			),
			'window'                      => array(
				'section' => 'general',
				'type'    => 'window',
				'label'   => __( 'Time window', 'horizon-press-news-bar' ),
				'desc'    => __( 'Sliding window ending now: at 16:20 with 24 hours, posts published since yesterday 16:20 are eligible. Minutes: 1–1440, hours: 1–720, days: 1–30.', 'horizon-press-news-bar' ),
			),
			'categories_include'          => array(
				'section'  => 'general',
				'type'     => 'terms',
				'taxonomy' => 'category',
				'label'    => __( 'Categories', 'horizon-press-news-bar' ),
				'desc'     => __( 'No selection means every category.', 'horizon-press-news-bar' ),
			),
			'max_items'                   => array(
				'section' => 'general',
				'type'    => 'number',
				'label'   => __( 'Maximum number of posts', 'horizon-press-news-bar' ),
			),
			'orderby'                     => array(
				'section' => 'general',
				'type'    => 'select',
				'label'   => __( 'Order', 'horizon-press-news-bar' ),
				'options' => array(
					'date_desc' => __( 'Newest first', 'horizon-press-news-bar' ),
					'date_asc'  => __( 'Oldest first', 'horizon-press-news-bar' ),
				),
			),
			'categories_exclude'          => array(
				'section'  => 'content',
				'type'     => 'terms',
				'taxonomy' => 'category',
				'label'    => __( 'Excluded categories', 'horizon-press-news-bar' ),
			),
			'tags_include'                => array(
				'section'  => 'content',
				'type'     => 'terms',
				'taxonomy' => 'post_tag',
				'label'    => __( 'Tags', 'horizon-press-news-bar' ),
				'desc'     => __( 'When at least one tag is selected, only posts having one of these tags are eligible.', 'horizon-press-news-bar' ),
			),
			'content_exclude_post_ids'    => array(
				'section' => 'content',
				'type'    => 'ids',
				'label'   => __( 'Never list these articles in the bar', 'horizon-press-news-bar' ),
				'desc'    => __( 'Comma-separated post IDs that must never appear in the bar.', 'horizon-press-news-bar' ),
			),
			'bg_color'                    => array(
				'section' => 'appearance',
				'type'    => 'color',
				'label'   => __( 'Background colour', 'horizon-press-news-bar' ),
			),
			'text_color'                  => array(
				'section'  => 'appearance',
				'type'     => 'color',
				'label'    => __( 'Text colour', 'horizon-press-news-bar' ),
				'contrast' => 'text',
			),
			'label_bg_color'              => array(
				'section' => 'appearance',
				'type'    => 'color',
				'label'   => __( 'Label background colour', 'horizon-press-news-bar' ),
			),
			'label_text_color'            => array(
				'section'  => 'appearance',
				'type'     => 'color',
				'label'    => __( 'Label text colour', 'horizon-press-news-bar' ),
				'contrast' => 'label',
			),
			'link_hover_color'            => array(
				'section' => 'appearance',
				'type'    => 'color',
				'label'   => __( 'Link hover colour', 'horizon-press-news-bar' ),
			),
			'accent_color'                => array(
				'section' => 'appearance',
				'type'    => 'color',
				'label'   => __( 'Accent colour', 'horizon-press-news-bar' ),
				'desc'    => __( 'Progress line and link hover underline (the theme red by default).', 'horizon-press-news-bar' ),
			),
			'font_size'                   => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Font size (px)', 'horizon-press-news-bar' ),
			),
			'bar_height'                  => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Height (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '32 to 56 px; 40 px recommended. With the label on its own row or several headline lines the bar grows as needed.', 'horizon-press-news-bar' ),
			),
			'align_container'             => array(
				'section' => 'appearance',
				'type'    => 'checkbox',
				'label'   => __( 'Align on the site container', 'horizon-press-news-bar' ),
				'text'    => __( 'Same maximum width and gutter as the article (Jannah container: 1230 px, 15 px).', 'horizon-press-news-bar' ),
			),
			'max_width'                   => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Container width (px)', 'horizon-press-news-bar' ),
				'depends' => 'align_container',
			),
			'gutter'                      => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Gutter (px)', 'horizon-press-news-bar' ),
				'depends' => 'align_container',
			),
			'z_index'                     => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'z-index', 'horizon-press-news-bar' ),
			),
			'layout_mode'                 => array(
				'section' => 'appearance',
				'type'    => 'radio',
				'label'   => __( 'Layout', 'horizon-press-news-bar' ),
				'options' => array(
					'reserve' => __( 'Reserve — pushes the page content up so the bar never covers it (default)', 'horizon-press-news-bar' ),
					'overlay' => __( 'Overlay — floats over the page; may cover a fixed element of the theme or of another plugin', 'horizon-press-news-bar' ),
				),
			),
			'thumbnail_size'              => array(
				'section' => 'advanced',
				'type'    => 'select',
				'label'   => __( 'Source size of the images', 'horizon-press-news-bar' ),
				'options' => $sizes,
				'desc'    => __( 'An existing WordPress image size, loaded for both profiles; no new size is generated. The displayed size is set per device under Desktop & mobile.', 'horizon-press-news-bar' ),
			),
			'show_separator'              => array(
				'section' => 'appearance',
				'type'    => 'checkbox',
				'label'   => __( 'Separator', 'horizon-press-news-bar' ),
				'text'    => __( 'Show a separator between headlines.', 'horizon-press-news-bar' ),
			),
			'separator_char'              => array(
				'section' => 'appearance',
				'type'    => 'text',
				'label'   => __( 'Separator character', 'horizon-press-news-bar' ),
				'attrs'   => array(
					'maxlength' => 8,
					'size'      => 4,
				),
			),
			'separator_after_last'        => array(
				'section' => 'appearance',
				'type'    => 'checkbox',
				'label'   => __( 'Separator after the last post', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the separator after the last post (continuous loop).', 'horizon-press-news-bar' ),
				'desc'    => __( 'Makes the last → first junction identical to every other junction, for example when the marquee wraps. Only applies when the separator is enabled.', 'horizon-press-news-bar' ),
				'depends' => 'show_separator',
			),
			'show_relative_time'          => array(
				'section' => 'appearance',
				'type'    => 'checkbox',
				'label'   => __( 'Relative time', 'horizon-press-news-bar' ),
				'text'    => __( 'Show "2 hours ago" after each headline (refreshed every minute in the browser).', 'horizon-press-news-bar' ),
			),
			'relative_time_max_hours'     => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Relative time limit (hours)', 'horizon-press-news-bar' ),
				'desc'    => __( 'Beyond this age the absolute date is shown instead.', 'horizon-press-news-bar' ),
			),
			'desktop_layout'              => array(
				'section' => 'desktop',
				'type'    => 'radio',
				'label'   => __( 'Label placement', 'horizon-press-news-bar' ),
				'options' => array(
					'inline'  => __( 'In front of the headline, on the same line (default on desktop)', 'horizon-press-news-bar' ),
					'stacked' => __( 'On its own row above the headline', 'horizon-press-news-bar' ),
				),
			),
			'desktop_label_style'         => array(
				'section' => 'desktop',
				'type'    => 'select',
				'label'   => __( 'Label style', 'horizon-press-news-bar' ),
				'options' => array(
					'strip'  => __( 'Block — full-height label in the label colours', 'horizon-press-news-bar' ),
					'pill'   => __( 'Pill — small rounded uppercase badge', 'horizon-press-news-bar' ),
					'hidden' => __( 'Hidden', 'horizon-press-news-bar' ),
				),
			),
			'desktop_label_dot'           => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Live dot', 'horizon-press-news-bar' ),
				'text'    => __( 'Show a small pulsing dot in front of the label text.', 'horizon-press-news-bar' ),
			),
			'desktop_show_counter'        => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Counter', 'horizon-press-news-bar' ),
				'text'    => __( 'Show "2/8" next to the label (rotate mode).', 'horizon-press-news-bar' ),
			),
			'desktop_lines'               => array(
				'section' => 'desktop',
				'type'    => 'select',
				'label'   => __( 'Headline lines', 'horizon-press-news-bar' ),
				'options' => self::line_options(),
				'desc'    => __( 'Number of lines a headline may take; the bar height follows (marquee always uses one line).', 'horizon-press-news-bar' ),
				'hint'    => 'd',
			),
			'desktop_show_thumbnail'      => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Image', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the featured image next to each headline.', 'horizon-press-news-bar' ),
			),
			'desktop_thumb_position'      => array(
				'section' => 'desktop',
				'type'    => 'select',
				'label'   => __( 'Image position', 'horizon-press-news-bar' ),
				'options' => array(
					'before' => __( 'Before the headline', 'horizon-press-news-bar' ),
					'after'  => __( 'After the headline', 'horizon-press-news-bar' ),
				),
				'depends' => 'desktop_show_thumbnail',
			),
			'desktop_thumb_size'          => array(
				'section' => 'desktop',
				'type'    => 'number',
				'label'   => __( 'Image size (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '16 to 80 px, square. The bar grows if the image is taller than the headline lines.', 'horizon-press-news-bar' ),
				'depends' => 'desktop_show_thumbnail',
			),
			'desktop_show_progress'       => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Progress line', 'horizon-press-news-bar' ),
				'text'    => __( 'Show a thin progress line along the top edge until the next headline (rotate mode).', 'horizon-press-news-bar' ),
			),
			'mobile_layout'               => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Design', 'horizon-press-news-bar' ),
				'options' => array(
					'flow_image' => __( 'Bar with the article picture (default) — the label opens the headline on two lines, the picture of the article at the end of the line, the close button in a tab above the corner', 'horizon-press-news-bar' ),
					'card'       => __( '"Explore More" card — a label row, then the picture at the left and the headline beside it, the close button in a tab above the corner', 'horizon-press-news-bar' ),
				),
			),
			'mobile_label_style'          => array(
				'section' => 'mobile',
				'type'    => 'select',
				'label'   => __( 'Label style', 'horizon-press-news-bar' ),
				'options' => array(
					'pill'   => __( 'Pill — small rounded uppercase badge', 'horizon-press-news-bar' ),
					'strip'  => __( 'Block — full-height label in the label colours', 'horizon-press-news-bar' ),
					'hidden' => __( 'Hidden', 'horizon-press-news-bar' ),
				),
			),
			'mobile_label_dot'            => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Live dot', 'horizon-press-news-bar' ),
				'text'    => __( 'Show a small pulsing dot in front of the label text.', 'horizon-press-news-bar' ),
			),
			'mobile_show_counter'         => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Counter', 'horizon-press-news-bar' ),
				'text'    => __( 'Show "2/8" next to the label (rotate mode).', 'horizon-press-news-bar' ),
			),
			'mobile_lines'                => array(
				'section' => 'mobile',
				'type'    => 'select',
				'label'   => __( 'Headline lines', 'horizon-press-news-bar' ),
				'options' => self::line_options(),
				'desc'    => __( 'Number of lines a headline may take; the height shown beside this field follows it. Marquee always uses one line, and the "Explore More" card stops at three.', 'horizon-press-news-bar' ),
				'hint'    => 'm',
			),
			'mobile_bar_height'           => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Card height (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '64 to 96 px; 76 px = 12 + two lines of 26 + 12. Floating label only; grows with the number of lines.', 'horizon-press-news-bar' ),
			),
			'mobile_font_size'            => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Mobile font size (px)', 'horizon-press-news-bar' ),
			),
			'mobile_ticker_mode'          => array(
				'section' => 'mobile',
				'type'    => 'select',
				'label'   => __( 'Mobile ticker mode', 'horizon-press-news-bar' ),
				'options' => array(
					'rotate'  => __( 'Rotate — one headline at a time (recommended)', 'horizon-press-news-bar' ),
					'inherit' => __( 'Same as desktop', 'horizon-press-news-bar' ),
					'static'  => __( 'Static list', 'horizon-press-news-bar' ),
					'marquee' => __( 'Marquee', 'horizon-press-news-bar' ),
					'manual'  => __( 'Manual — previous / next buttons', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Rotate and marquee always come with the Pause / Play button and respect reduced-motion preferences.', 'horizon-press-news-bar' ),
			),
			'mobile_show_progress'        => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Progress line', 'horizon-press-news-bar' ),
				'text'    => __( 'Show a thin progress line along the top edge until the next headline (rotate mode).', 'horizon-press-news-bar' ),
			),
			'mobile_swipe'                => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Swipe', 'horizon-press-news-bar' ),
				'text'    => __( 'Swipe left or right to move between headlines (rotate mode).', 'horizon-press-news-bar' ),
			),
			'mobile_hide_on_scroll'       => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Fold the bar away', 'horizon-press-news-bar' ),
				'text'    => __( 'Turn folding on for mobile. Off, the bar keeps its full height and the settings below do nothing. Needs a design other than "Label in front of the headline".', 'horizon-press-news-bar' ),
			),
			'mobile_peek'                 => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Collapsed strip', 'horizon-press-news-bar' ),
				'options' => array(
					'headline' => __( 'Label and the first line of the headline', 'horizon-press-news-bar' ),
					'label'    => __( 'Label only', 'horizon-press-news-bar' ),
				),
				'depends' => 'mobile_hide_on_scroll',
			),
			'mobile_deep_collapse'        => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Landing mid-page', 'horizon-press-news-bar' ),
				'text'    => __( 'Start collapsed when the page opens further than 120 px down (anchor, back navigation).', 'horizon-press-news-bar' ),
				'depends' => 'mobile_hide_on_scroll',
			),
			'mobile_kbd_hide'             => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Keyboard open', 'horizon-press-news-bar' ),
				'text'    => __( 'Slide the bar away while a form field is active; bring it back on blur.', 'horizon-press-news-bar' ),
			),
			'mobile_show_thumbnail'       => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Image', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the featured image next to each headline.', 'horizon-press-news-bar' ),
			),
			'mobile_thumb_position'       => array(
				'section' => 'mobile',
				'type'    => 'select',
				'label'   => __( 'Image position', 'horizon-press-news-bar' ),
				'options' => array(
					'before' => __( 'Before the headline', 'horizon-press-news-bar' ),
					'after'  => __( 'After the headline', 'horizon-press-news-bar' ),
				),
				'depends' => 'mobile_show_thumbnail',
			),
			'mobile_thumb_size'           => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Image size (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '16 to 80 px, square. Never taller than the headline block, so the card keeps its height.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_show_thumbnail,mobile_layout:flow_image',
			),
			'mobile_collapse_mode'        => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Mobile — when it folds', 'horizon-press-news-bar' ),
				'options' => array(
					'scroll'    => __( 'While scrolling down past the threshold; it unfolds again when scrolling up', 'horizon-press-news-bar' ),
					'threshold' => __( 'As soon as the threshold is passed, and it stays folded while below it', 'horizon-press-news-bar' ),
					'immediate' => __( 'Always folded — the reader opens it with a tap', 'horizon-press-news-bar' ),
					'article'   => __( 'Follows the reading — open when reading on past the point where it appeared, folded on any scroll back up inside the article, never folded once the article is over', 'horizon-press-news-bar' ),
				),
				'depends' => 'mobile_hide_on_scroll',
			),
			'mobile_collapse_after'       => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Collapse threshold (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '0 to 800 px of scrolling; 120 px by default. Ignored when the bar is always folded or follows the reading.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_hide_on_scroll',
			),
			'mobile_controls_place'       => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Buttons placement', 'horizon-press-news-bar' ),
				'options' => array(
					'inside'  => __( 'Inside the bar', 'horizon-press-news-bar' ),
					'outside' => __( 'Floating just above the bar — the headline then takes the whole width', 'horizon-press-news-bar' ),
				),
			),
			'mobile_show_pause'           => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Pause button', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the Pause / Play button on mobile.', 'horizon-press-news-bar' ),
			),
			'mobile_show_close'           => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Close button', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the close button on mobile (the "Closing" block below must be enabled too).', 'horizon-press-news-bar' ),
			),
			'mobile_controls_layout'      => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Buttons', 'horizon-press-news-bar' ),
				'options' => array(
					'column' => __( 'Stacked — close above pause (one column, more room for the headline)', 'horizon-press-news-bar' ),
					'row'    => __( 'Side by side', 'horizon-press-news-bar' ),
				),
			),
			'mobile_label_pulse'          => array(
				'section' => 'mobile',
				'type'    => 'select',
				'label'   => __( 'Pulsing label', 'horizon-press-news-bar' ),
				'options' => array(
					'appear'    => __( 'A few beats when the bar arrives (recommended)', 'horizon-press-news-bar' ),
					'always'    => __( 'Always — the pill breathes like a button', 'horizon-press-news-bar' ),
					'collapsed' => __( 'Only in the collapsed strip', 'horizon-press-news-bar' ),
					'never'     => __( 'Never', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Ignored when the visitor asks for reduced motion.', 'horizon-press-news-bar' ),
			),
			'mobile_peek_thumbnail'       => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Image in the collapsed strip', 'horizon-press-news-bar' ),
				'text'    => __( 'Keep the picture at the end of the folded strip, resized so it never exceeds one line.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_hide_on_scroll',
			),
			'mobile_label_compact'        => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Compact label with an image', 'horizon-press-news-bar' ),
				'text'    => __( 'Shrink the pill to its red dot when an image is shown, so the headline keeps the width.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_show_thumbnail',
			),
			'mobile_show_separator'       => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Mobile separator', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the separator between headlines on mobile too (static, marquee and manual modes).', 'horizon-press-news-bar' ),
			),
			'mobile_custom_colors'        => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Mobile colours', 'horizon-press-news-bar' ),
				'text'    => __( 'Use a dedicated mobile palette (below) instead of the desktop colours.', 'horizon-press-news-bar' ),
			),
			'mobile_bg_color'             => array(
				'section' => 'mobile',
				'type'    => 'color',
				'label'   => __( 'Mobile background colour', 'horizon-press-news-bar' ),
				'depends' => 'mobile_custom_colors',
			),
			'mobile_text_color'           => array(
				'section'  => 'mobile',
				'type'     => 'color',
				'label'    => __( 'Mobile text colour', 'horizon-press-news-bar' ),
				'contrast' => 'mobile',
				'depends'  => 'mobile_custom_colors',
			),
			'mobile_accent_color'         => array(
				'section' => 'mobile',
				'type'    => 'color',
				'label'   => __( 'Mobile accent colour', 'horizon-press-news-bar' ),
				'desc'    => __( 'Label pill, live dot and progress line.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_custom_colors',
			),
			'mobile_label_text_color'     => array(
				'section' => 'mobile',
				'type'    => 'color',
				'label'   => __( 'Mobile label text colour', 'horizon-press-news-bar' ),
				'depends' => 'mobile_custom_colors',
			),
			'ticker_enabled'              => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Ticker', 'horizon-press-news-bar' ),
				'text'    => __( 'Animate the headlines. Marquee and rotate always come with a keyboard-accessible Pause / Play button and stop when the visitor prefers reduced motion.', 'horizon-press-news-bar' ),
			),
			'ticker_mode'                 => array(
				'section' => 'behavior',
				'type'    => 'select',
				'label'   => __( 'Ticker mode', 'horizon-press-news-bar' ),
				'options' => array(
					'marquee' => __( 'Marquee — continuous scrolling', 'horizon-press-news-bar' ),
					'rotate'  => __( 'Rotate — one headline at a time', 'horizon-press-news-bar' ),
					'manual'  => __( 'Manual — previous / next buttons, no automatic motion', 'horizon-press-news-bar' ),
				),
			),
			'ticker_speed'                => array(
				'section' => 'behavior',
				'type'    => 'number',
				'label'   => __( 'Marquee speed (px/s)', 'horizon-press-news-bar' ),
				'desc'    => __( '10 to 80 px per second; 30 recommended (20 px/s made a 20-headline loop last four minutes).', 'horizon-press-news-bar' ),
			),
			'rotate_interval'             => array(
				'section' => 'behavior',
				'type'    => 'number',
				'label'   => __( 'Rotate interval (ms)', 'horizon-press-news-bar' ),
				'desc'    => __( '3000 to 12000 ms (3 to 12 seconds) between two headlines.', 'horizon-press-news-bar' ),
			),
			'pause_on_hover'              => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Pause on hover', 'horizon-press-news-bar' ),
				'text'    => __( 'Extra comfort option; the Pause / Play button remains the accessible control.', 'horizon-press-news-bar' ),
			),
			'close_button'                => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Close button', 'horizon-press-news-bar' ),
				'text'    => __( 'Let visitors close the bar.', 'horizon-press-news-bar' ),
			),
			'remember_dismiss'            => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Remember closing', 'horizon-press-news-bar' ),
				'text'    => __( 'Keep the bar closed for the duration below (stored in the browser localStorage, no cookie).', 'horizon-press-news-bar' ),
			),
			'dismiss_duration_hours'      => array(
				'section' => 'behavior',
				'type'    => 'number',
				'label'   => __( 'Closing duration (hours)', 'horizon-press-news-bar' ),
			),
			'mobile_reveal_mode'          => array(
				'section' => 'appearance',
				'type'    => 'radio',
				'label'   => __( 'When the bar appears', 'horizon-press-news-bar' ),
				'options' => array(
					'immediate' => __( 'Right away, with the page', 'horizon-press-news-bar' ),
					'scroll'    => __( 'After a scroll distance — recommended on articles: the opening of the page stays clear', 'horizon-press-news-bar' ),
					'percent'   => __( 'After a share of the page has been read', 'horizon-press-news-bar' ),
					'end'       => __( 'Near the end of the page (90 %)', 'horizon-press-news-bar' ),
					'paragraph' => __( 'Before the end of the article — as soon as the chosen paragraph, counted from the end, comes into view', 'horizon-press-news-bar' ),
					'smart'     => __( 'Smart — recommended on articles: the end of the article, a real scroll back up, or a reader who got deep into it', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Until then no space is reserved and the bar stays out of view; once it appears it stays. "Before the end" and Smart measure the article body, not the page; Smart fires once on whichever signal comes first.', 'horizon-press-news-bar' ),
			),
			'mobile_reveal_value'         => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Appearance threshold', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:scroll|percent',
				'desc'    => __( 'Pixels of scrolling, or percentage of the page for the share option. 400 px is a good start on an article.', 'horizon-press-news-bar' ),
			),
			'mobile_reveal_paragraph'     => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Paragraphs before the end', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:paragraph',
				'desc'    => __( 'Where "Continuous reading" (and "Before the end of the article") shows the bar: 2 = the second-to-last paragraph of the article. Counted from the end of the article, so a larger number shows the bar earlier. 1 to 30.', 'horizon-press-news-bar' ),
			),
			'desktop_reveal_mode'         => array(
				'section' => 'appearance',
				'type'    => 'radio',
				'label'   => __( 'When the bar appears', 'horizon-press-news-bar' ),
				'options' => array(
					'immediate' => __( 'Right away, with the page', 'horizon-press-news-bar' ),
					'scroll'    => __( 'After a scroll distance — recommended on articles: the opening of the page stays clear', 'horizon-press-news-bar' ),
					'percent'   => __( 'After a share of the page has been read', 'horizon-press-news-bar' ),
					'end'       => __( 'Near the end of the page (90 %)', 'horizon-press-news-bar' ),
					'paragraph' => __( 'Before the end of the article — as soon as the chosen paragraph, counted from the end, comes into view', 'horizon-press-news-bar' ),
					'smart'     => __( 'Smart — recommended on articles: the end of the article, a real scroll back up, or a reader who got deep into it', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Until then no space is reserved and the bar stays out of view; once it appears it stays. "Before the end" and Smart measure the article body, not the page; Smart fires once on whichever signal comes first.', 'horizon-press-news-bar' ),
			),
			'desktop_reveal_value'        => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Appearance threshold', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:scroll|percent',
				'desc'    => __( 'Pixels of scrolling, or percentage of the page for the share option. 400 px is a good start on an article.', 'horizon-press-news-bar' ),
			),
			'desktop_reveal_paragraph'    => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Paragraphs before the end', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:paragraph',
				'desc'    => __( 'Where "Continuous reading" (and "Before the end of the article") shows the bar: 2 = the second-to-last paragraph of the article. Counted from the end of the article, so a larger number shows the bar earlier. 1 to 30.', 'horizon-press-news-bar' ),
			),
			'smart_selector'              => array(
				'section' => 'appearance',
				'type'    => 'text',
				'label'   => __( 'Article body selector', 'horizon-press-news-bar' ),
				'desc'    => __( 'Optional, and shared by both devices. A CSS selector for the editorial body of an article, when the theme needs one. Left empty, the usual containers are tried in turn (.entry-content, .post-content, .article-content, .wp-block-post-content…). Never the footer, the comments or what follows the article.', 'horizon-press-news-bar' ),
			),
			'smart_mobile_progress'       => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Mobile — article read before a scroll up counts (%)', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:smart',
			),
			'smart_mobile_time'           => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Mobile — active reading time before it counts (s)', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:smart',
			),
			'smart_mobile_up'             => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Mobile — upward scrolling that shows intent (px)', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:smart',
			),
			'smart_mobile_fallback'       => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Mobile — fallback: article read (%)', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:smart',
			),
			'smart_mobile_fallback_time'  => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Mobile — fallback: active reading time (s)', 'horizon-press-news-bar' ),
				'depends' => 'mobile_reveal_mode:smart',
			),
			'smart_desktop_progress'      => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Desktop — article read before a scroll up counts (%)', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:smart',
			),
			'smart_desktop_time'          => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Desktop — active reading time before it counts (s)', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:smart',
			),
			'smart_desktop_up'            => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Desktop — upward scrolling that shows intent (px)', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:smart',
			),
			'smart_desktop_fallback'      => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Desktop — fallback: article read (%)', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:smart',
			),
			'smart_desktop_fallback_time' => array(
				'section' => 'appearance',
				'type'    => 'number',
				'label'   => __( 'Desktop — fallback: active reading time (s)', 'horizon-press-news-bar' ),
				'depends' => 'desktop_reveal_mode:smart',
			),
			'accent_edge'                 => array(
				'section' => 'appearance',
				'type'    => 'checkbox',
				'label'   => __( 'Accent edge', 'horizon-press-news-bar' ),
				'text'    => __( 'Draw a 2 px line of the accent colour along the top edge; the rotation progress fills that same edge.', 'horizon-press-news-bar' ),
			),
			'theme_offset'                => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Theme fixed elements', 'horizon-press-news-bar' ),
				'text'    => __( 'Move the Jannah "go to top" button, the "Check also" box and the reading position indicator above the bar (they follow the collapse).', 'horizon-press-news-bar' ),
			),
			'show_on_desktop'             => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Desktop', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the bar on screens of 768 px and wider.', 'horizon-press-news-bar' ),
			),
			'show_on_mobile'              => array(
				'section' => 'behavior',
				'type'    => 'checkbox',
				'label'   => __( 'Mobile', 'horizon-press-news-bar' ),
				'text'    => __( 'Show the bar on screens narrower than 768 px (CSS only, no device detection).', 'horizon-press-news-bar' ),
			),
			'desktop_contexts'            => array(
				'section' => 'desktop',
				'type'    => 'contexts',
				'label'   => __( 'Page types', 'horizon-press-news-bar' ),
				'options' => array(
					'front_page'  => __( 'Front page', 'horizon-press-news-bar' ),
					'blog_home'   => __( 'Blog home', 'horizon-press-news-bar' ),
					'single_post' => __( 'Single post', 'horizon-press-news-bar' ),
					'page'        => __( 'Page', 'horizon-press-news-bar' ),
					'category'    => __( 'Category archive', 'horizon-press-news-bar' ),
					'tag'         => __( 'Tag archive', 'horizon-press-news-bar' ),
					'archive'     => __( 'Other archives', 'horizon-press-news-bar' ),
					'search'      => __( 'Search results', 'horizon-press-news-bar' ),
					'not_found'   => __( '404 page', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Narrows the page types above for this device only. Untick everything here and the bar never shows on this device.', 'horizon-press-news-bar' ),
			),
			'desktop_placement'           => array(
				'section' => 'desktop',
				'type'    => 'radio',
				'label'   => __( 'Placement', 'horizon-press-news-bar' ),
				'options' => array(
					'fixed'  => __( 'Pinned to the bottom of the screen', 'horizon-press-news-bar' ),
					'inline' => __( 'Inside the article, between the paragraphs', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Inside the article the bar becomes a block of the page: full width, no reserved space, no collapsing. On any page without paragraphs it falls back to the bottom of the screen.', 'horizon-press-news-bar' ),
			),
			'desktop_inline_anchor'       => array(
				'section' => 'desktop',
				'type'    => 'radio',
				'label'   => __( 'Where in the article', 'horizon-press-news-bar' ),
				'options' => array(
					'before'     => __( 'Before a paragraph', 'horizon-press-news-bar' ),
					'after'      => __( 'After a paragraph', 'horizon-press-news-bar' ),
					'before_end' => __( 'Before the last paragraphs', 'horizon-press-news-bar' ),
				),
				'depends' => 'desktop_placement:inline',
			),
			'desktop_inline_paragraph'    => array(
				'section' => 'desktop',
				'type'    => 'number',
				'label'   => __( 'Paragraph number', 'horizon-press-news-bar' ),
				'desc'    => __( '1 to 30. Counted from the start of the article, or from its end for the last option. A shorter article places the bar at its end.', 'horizon-press-news-bar' ),
				'depends' => 'desktop_placement:inline',
			),
			'mobile_contexts'             => array(
				'section' => 'mobile',
				'type'    => 'contexts',
				'label'   => __( 'Page types', 'horizon-press-news-bar' ),
				'options' => array(
					'front_page'  => __( 'Front page', 'horizon-press-news-bar' ),
					'blog_home'   => __( 'Blog home', 'horizon-press-news-bar' ),
					'single_post' => __( 'Single post', 'horizon-press-news-bar' ),
					'page'        => __( 'Page', 'horizon-press-news-bar' ),
					'category'    => __( 'Category archive', 'horizon-press-news-bar' ),
					'tag'         => __( 'Tag archive', 'horizon-press-news-bar' ),
					'archive'     => __( 'Other archives', 'horizon-press-news-bar' ),
					'search'      => __( 'Search results', 'horizon-press-news-bar' ),
					'not_found'   => __( '404 page', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Narrows the page types above for this device only. Untick everything here and the bar never shows on this device.', 'horizon-press-news-bar' ),
			),
			'mobile_placement'            => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Placement', 'horizon-press-news-bar' ),
				'options' => array(
					'fixed'  => __( 'Pinned to the bottom of the screen', 'horizon-press-news-bar' ),
					'inline' => __( 'Inside the article, between the paragraphs', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'Inside the article the bar becomes a block of the page: full width, no reserved space, no collapsing. On any page without paragraphs it falls back to the bottom of the screen.', 'horizon-press-news-bar' ),
			),
			'mobile_inline_anchor'        => array(
				'section' => 'mobile',
				'type'    => 'radio',
				'label'   => __( 'Where in the article', 'horizon-press-news-bar' ),
				'options' => array(
					'before'     => __( 'Before a paragraph', 'horizon-press-news-bar' ),
					'after'      => __( 'After a paragraph', 'horizon-press-news-bar' ),
					'before_end' => __( 'Before the last paragraphs', 'horizon-press-news-bar' ),
				),
				'depends' => 'mobile_placement:inline',
			),
			'mobile_inline_paragraph'     => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Paragraph number', 'horizon-press-news-bar' ),
				'desc'    => __( '1 to 30. Counted from the start of the article, or from its end for the last option. A shorter article places the bar at its end.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_placement:inline',
			),
			'desktop_hide_on_scroll'      => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Fold the bar away', 'horizon-press-news-bar' ),
				'text'    => __( 'Turn folding on for desktop. Off, the bar stays put and the two settings below do nothing.', 'horizon-press-news-bar' ),
			),
			'desktop_collapse_mode'       => array(
				'section' => 'desktop',
				'type'    => 'radio',
				'label'   => __( 'Desktop — when it folds', 'horizon-press-news-bar' ),
				'options' => array(
					'scroll'    => __( 'While scrolling down past the threshold; it unfolds again when scrolling up', 'horizon-press-news-bar' ),
					'threshold' => __( 'As soon as the threshold is passed, and it stays folded while below it', 'horizon-press-news-bar' ),
					'immediate' => __( 'Always folded — the reader opens it from the tab', 'horizon-press-news-bar' ),
					'article'   => __( 'Follows the reading — open when reading on past the point where it appeared, folded on any scroll back up inside the article, never folded once the article is over', 'horizon-press-news-bar' ),
				),
				'depends' => 'desktop_hide_on_scroll',
			),
			'desktop_collapse_after'      => array(
				'section' => 'desktop',
				'type'    => 'number',
				'label'   => __( 'Collapse threshold (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '0 to 800 px of scrolling; 120 px by default. Ignored when the bar is always folded or follows the reading.', 'horizon-press-news-bar' ),
				'depends' => 'desktop_hide_on_scroll',
			),
			'mobile_behavior'             => array(
				'section' => 'mobile',
				'type'    => 'choice',
				'label'   => __( 'Behaviour', 'horizon-press-news-bar' ),
				'options' => self::behavior_choices(),
			),
			'desktop_behavior'            => array(
				'section' => 'desktop',
				'type'    => 'choice',
				'label'   => __( 'Behaviour', 'horizon-press-news-bar' ),
				'options' => self::behavior_choices(),
			),
			'mobile_next_hide'            => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Next article', 'horizon-press-news-bar' ),
				'text'    => __( 'Hide the bar completely once the reader moves on to the next article loaded below this one (themes that load articles one after another). It comes back when the reader scrolls back up into this article.', 'horizon-press-news-bar' ),
			),
			'desktop_next_hide'           => array(
				'section' => 'desktop',
				'type'    => 'checkbox',
				'label'   => __( 'Next article', 'horizon-press-news-bar' ),
				'text'    => __( 'Hide the bar completely once the reader moves on to the next article loaded below this one (themes that load articles one after another). It comes back when the reader scrolls back up into this article.', 'horizon-press-news-bar' ),
			),
			'mobile_card_thumb'           => array(
				'section' => 'mobile',
				'type'    => 'number',
				'label'   => __( 'Card picture width (px)', 'horizon-press-news-bar' ),
				'desc'    => __( '72 to 160 px, in a 16:9 frame; 132 px by default, as on the reference (132 × 74). The headline takes the rest of the line.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_layout:card',
			),
			'mobile_card_float'           => array(
				'section' => 'mobile',
				'type'    => 'checkbox',
				'label'   => __( 'Floating card', 'horizon-press-news-bar' ),
				'text'    => __( 'Detach the card 8 px from the edges, with rounded corners and a shadow. Off, it runs edge to edge with square corners, like the reference.', 'horizon-press-news-bar' ),
				'depends' => 'mobile_layout:card',
			),
			'display_scope'               => array(
				'section' => 'visibility',
				'type'    => 'radio',
				'label'   => __( 'Where the bar may appear', 'horizon-press-news-bar' ),
				'options' => array(
					'everywhere' => __( 'Everywhere on the site', 'horizon-press-news-bar' ),
					'custom'     => __( 'Only the page types ticked below', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'To show the bar on single articles only, pick the second option and leave "Single post" as the only tick.', 'horizon-press-news-bar' ),
			),
			'contexts'                    => array(
				'section' => 'visibility',
				'type'    => 'contexts',
				'label'   => __( 'Page types', 'horizon-press-news-bar' ),
				'depends' => 'display_scope:custom',
				'options' => array(
					'front_page'  => __( 'Front page', 'horizon-press-news-bar' ),
					'blog_home'   => __( 'Blog home', 'horizon-press-news-bar' ),
					'single_post' => __( 'Single post', 'horizon-press-news-bar' ),
					'page'        => __( 'Page', 'horizon-press-news-bar' ),
					'category'    => __( 'Category archive', 'horizon-press-news-bar' ),
					'tag'         => __( 'Tag archive', 'horizon-press-news-bar' ),
					'archive'     => __( 'Other archives', 'horizon-press-news-bar' ),
					'search'      => __( 'Search results', 'horizon-press-news-bar' ),
					'not_found'   => __( '404 page', 'horizon-press-news-bar' ),
				),
				'desc'    => __( 'The bar is never shown in wp-admin, feeds, embeds, previews, the login page, sitemaps, AMP pages, REST, AJAX or cron requests.', 'horizon-press-news-bar' ),
			),
			'display_exclude_ids'         => array(
				'section' => 'visibility',
				'type'    => 'ids',
				'label'   => __( 'Never show the bar on these pages', 'horizon-press-news-bar' ),
				'desc'    => __( 'Comma-separated IDs of pages or posts on which the bar must not appear.', 'horizon-press-news-bar' ),
			),
			'render_mode'                 => array(
				'section' => 'advanced',
				'type'    => 'radio',
				'label'   => __( 'Render mode', 'horizon-press-news-bar' ),
				'options' => array(
					'hybrid' => __( 'Hybrid (recommended) — server rendering plus a conditional REST refresh when a page cache served a stale bar', 'horizon-press-news-bar' ),
					'php'    => __( 'PHP — server rendering only, no freshness check (diagnostics or sites without a long page cache)', 'horizon-press-news-bar' ),
				),
			),
			'cache_ttl'                   => array(
				'section' => 'advanced',
				'type'    => 'number',
				'label'   => __( 'Server cache TTL (seconds)', 'horizon-press-news-bar' ),
				'desc'    => __( '30 to 600 seconds. Kept short because a post can leave the window without any WordPress event.', 'horizon-press-news-bar' ),
			),
			'stale_threshold'             => array(
				'section' => 'advanced',
				'type'    => 'number',
				'label'   => __( 'Stale threshold (seconds)', 'horizon-press-news-bar' ),
				'desc'    => __( 'Hybrid mode: a server-rendered bar older than this triggers at most one REST request per page load.', 'horizon-press-news-bar' ),
			),
			'auto_display'                => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'label'   => __( 'Automatic display', 'horizon-press-news-bar' ),
				'text'    => __( 'Insert the bar automatically in the footer of eligible pages.', 'horizon-press-news-bar' ),
			),
			'shortcode_enabled'           => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'label'   => __( 'Shortcode', 'horizon-press-news-bar' ),
				'text'    => __( 'Allow [hprnb_news_bar]. Only one bar is ever rendered per page.', 'horizon-press-news-bar' ),
			),
			'uninstall_delete_data'       => array(
				'section' => 'advanced',
				'type'    => 'checkbox',
				'label'   => __( 'Uninstall', 'horizon-press-news-bar' ),
				'text'    => __( 'Delete the plugin settings when the plugin is uninstalled. Posts, media and terms are never touched.', 'horizon-press-news-bar' ),
			),
		);
	}

	/**
	 * Options of the "Headline lines" selects.
	 *
	 * @return array<int, string>
	 */
	private static function line_options(): array {
		$options = array();
		for ( $n = 1; $n <= 4; $n++ ) {
			/* translators: %d: number of lines. */
			$options[ $n ] = sprintf( _n( '%d line', '%d lines', $n, 'horizon-press-news-bar' ), $n );
		}
		return $options;
	}

	/**
	 * Settings shown only with "Advanced settings" on. Every other one is what a site works with
	 * day to day; cards flagged `advanced` in layout() are advanced as a whole. Hidden or not, every
	 * input stays in the form, so saving never loses a value.
	 *
	 * @return string[]
	 */
	public static function advanced_fields(): array {
		return array(
			// Content.
			'label_position',
			'orderby',
			'categories_exclude',
			'tags_include',
			'content_exclude_post_ids',
			// Mobile.
			'mobile_card_float',
			'mobile_font_size',
			'mobile_bar_height',
			'mobile_peek',
			'mobile_peek_thumbnail',
			'mobile_label_style',
			'mobile_label_dot',
			'mobile_label_pulse',
			'mobile_label_compact',
			'mobile_show_counter',
			'mobile_show_progress',
			'mobile_show_separator',
			'mobile_show_thumbnail',
			'mobile_thumb_position',
			'mobile_swipe',
			'mobile_kbd_hide',
			'mobile_ticker_mode',
			'mobile_controls_place',
			'mobile_controls_layout',
			// Desktop.
			'desktop_layout',
			'desktop_lines',
			'bar_height',
			'desktop_label_style',
			'desktop_label_dot',
			'desktop_show_counter',
			'desktop_show_progress',
			'desktop_show_thumbnail',
			'desktop_thumb_position',
			'desktop_thumb_size',
			'align_container',
			'max_width',
			'gutter',
			'ticker_enabled',
			'pause_on_hover',
			// Colours.
			'accent_color',
			'link_hover_color',
			'accent_edge',
		);
	}

	/**
	 * Whether a whole tab is advanced: every one of its cards is.
	 *
	 * @param array $tab Tab from layout().
	 * @return bool
	 */
	private static function tab_is_advanced( array $tab ): bool {
		foreach ( $tab['cards'] as $card ) {
			if ( empty( $card['advanced'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Renders the tab panels and their cards. Without JavaScript every panel is visible.
	 *
	 * @param array $settings Current settings.
	 * @param array $layout   Tabs / cards from layout().
	 * @return void
	 */
	private static function render_tabs( array $settings, array $layout ): void {
		$fields   = self::fields();
		$advanced = self::advanced_fields();
		$placed   = array();

		foreach ( $layout as $tab_key => $tab ) {
			echo '<div class="hprnb-panel" id="hprnb-tab-' . esc_attr( $tab_key ) . '" role="tabpanel" aria-labelledby="hprnb-tab-btn-' . esc_attr( $tab_key ) . '" data-hprnb-panel="' . esc_attr( $tab_key ) . '"' . ( self::tab_is_advanced( $tab ) ? ' data-hprnb-advanced="1"' : '' ) . '>';
			echo '<h2 class="hprnb-panel__title">' . esc_html( $tab['title'] ) . '</h2>';
			foreach ( $tab['cards'] as $index => $card ) {
				self::render_card( $card, $fields, $settings, $advanced, $tab_key . '-' . $index );
				foreach ( $card['keys'] as $key ) {
					$placed[ $key ] = true;
				}
				if ( ! empty( $card['switch'] ) ) {
					$placed[ $card['switch'] ] = true;
				}
			}
			echo '</div>';
		}

		// Safety net: a field that no card lists is still editable.
		$rest = array_diff_key( $fields, $placed );
		if ( ! empty( $rest ) ) {
			echo '<div class="hprnb-panel" data-hprnb-panel="other"><h2 class="hprnb-panel__title">' . esc_html__( 'Other', 'horizon-press-news-bar' ) . '</h2>';
			self::render_table( $rest, $settings );
			echo '</div>';
		}
	}

	/**
	 * Renders one card: header (title, optional "Enable" switch), description, presets, rows.
	 *
	 * @param array  $card     Card definition.
	 * @param array  $fields   Every field definition.
	 * @param array  $settings Current settings.
	 * @param array  $advanced Keys shown only with "Advanced settings" on.
	 * @param string $id       Card id suffix.
	 * @return void
	 */
	private static function render_card( array $card, array $fields, array $settings, array $advanced, string $id ): void {
		$switch  = ! empty( $card['switch'] ) && isset( $fields[ $card['switch'] ] ) ? $card['switch'] : '';
		$classes = 'hprnb-card' . ( empty( $card['compact'] ) ? '' : ' hprnb-card--compact' ) . ( empty( $card['advanced'] ) ? '' : ' hprnb-card--advanced' );
		// A card that only makes sense for one choice (the "Custom" behaviour) follows it as a whole:
		// the script hides it otherwise. Without the script every card stays in view.
		$depends = empty( $card['depends'] ) ? '' : ' data-hprnb-card-depends="' . esc_attr( $card['depends'] ) . '"';
		echo '<section class="' . esc_attr( $classes ) . '" id="hprnb-card-' . esc_attr( $id ) . '"' . ( $switch ? ' data-hprnb-switch="' . esc_attr( $switch ) . '"' : '' ) . $depends . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $depends is escaped above.
		echo '<header class="hprnb-card__header"><h3 class="hprnb-card__title">' . esc_html( $card['title'] ) . '</h3>';
		if ( $switch ) {
			$switch_id = 'hprnb-field-' . str_replace( '_', '-', $switch );
			echo '<label class="hprnb-switch" for="' . esc_attr( $switch_id ) . '">';
			echo '<input type="checkbox" id="' . esc_attr( $switch_id ) . '" name="' . esc_attr( Settings::OPTION . '[' . $switch . ']' ) . '" value="1"' . checked( ! empty( $settings[ $switch ] ), true, false ) . ' />';
			echo '<span class="hprnb-switch__track" aria-hidden="true"></span><span class="hprnb-switch__text">' . esc_html__( 'Enable', 'horizon-press-news-bar' ) . '</span></label>';
		}
		echo '</header>';
		if ( ! empty( $card['description'] ) ) {
			echo '<p class="description hprnb-card__description">' . esc_html( $card['description'] ) . '</p>';
		}
		if ( ! empty( $card['presets'] ) ) {
			echo '<p class="hprnb-presets"><span class="hprnb-presets__label">' . esc_html__( 'Presets:', 'horizon-press-news-bar' ) . '</span> ';
			echo '<button type="button" class="button hprnb-preset" data-hprnb-preset="dark">' . esc_html__( 'Dark + red pill (recommended)', 'horizon-press-news-bar' ) . '</button> ';
			echo '<button type="button" class="button hprnb-preset" data-hprnb-preset="red">' . esc_html__( 'Solid red', 'horizon-press-news-bar' ) . '</button></p>';
		}

		$rows = array();
		foreach ( $card['keys'] as $key ) {
			if ( ! isset( $fields[ $key ] ) || $key === $switch ) {
				continue;
			}
			$field = $fields[ $key ];
			if ( $switch && empty( $field['depends'] ) ) {
				$field['depends'] = $switch;
			}
			$field['advanced'] = in_array( $key, $advanced, true );
			$rows[ $key ]      = $field;
		}
		if ( ! empty( $rows ) ) {
			self::render_table( $rows, $settings );
		}
		if ( ! empty( $card['scenarios'] ) ) {
			echo '<details class="hprnb-details hprnb-scenarios"><summary>' . esc_html__( 'Typical set-ups', 'horizon-press-news-bar' ) . '</summary><dl class="hprnb-scenarios__list">';
			foreach ( $card['scenarios'] as $scenario ) {
				echo '<dt class="hprnb-scenarios__title">' . esc_html( $scenario['title'] ) . '</dt>';
				echo '<dd class="hprnb-scenarios__body">' . esc_html( $scenario['body'] ) . '</dd>';
			}
			echo '</dl></details>';
		}
		echo '</section>';
	}

	/**
	 * Renders a form table of fields.
	 *
	 * @param array $fields   Field definitions.
	 * @param array $settings Current settings.
	 * @return void
	 */
	private static function render_table( array $fields, array $settings ): void {
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $field ) {
			$id = 'hprnb-field-' . str_replace( '_', '-', $key );
			echo '<tr class="hprnb-row hprnb-row--' . esc_attr( $field['type'] ) . ( empty( $field['advanced'] ) ? '' : ' hprnb-row--advanced' ) . '"' . ( ! empty( $field['depends'] ) ? ' data-hprnb-depends="' . esc_attr( $field['depends'] ) . '"' : '' ) . '>';
			if ( 'choice' === $field['type'] ) {
				// The boxes carry their own titles: the whole width goes to them, the legend names the group.
				echo '<td colspan="2">';
				self::render_field( $key, $field, $settings, $id );
				echo '</td></tr>';
				continue;
			}
			echo '<th scope="row">';
			if ( in_array( $field['type'], array( 'text', 'number', 'select', 'color', 'ids' ), true ) ) {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
			} else {
				echo esc_html( $field['label'] );
			}
			echo '</th><td>';
			self::render_field( $key, $field, $settings, $id );
			if ( ! empty( $field['hint'] ) ) {
				/* translators: %d: bar height in pixels. */
				echo ' <span class="description hprnb-height-hint" data-hprnb-height="' . esc_attr( $field['hint'] ) . '" data-hprnb-height-format="' . esc_attr( __( 'Bar height: %d px', 'horizon-press-news-bar' ) ) . '">' . esc_html( sprintf( __( 'Bar height: %d px', 'horizon-press-news-bar' ), Renderer::profile_height( $settings, $field['hint'] ) ) ) . '</span>';
			}
			if ( ! empty( $field['desc'] ) ) {
				echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders one field control.
	 *
	 * @param string $key      Setting key (or the virtual "window" key).
	 * @param array  $field    Field definition.
	 * @param array  $settings Current settings.
	 * @param string $id       Control id.
	 * @return void
	 */
	private static function render_field( string $key, array $field, array $settings, string $id ): void {
		$name   = Settings::OPTION . '[' . $key . ']';
		$schema = Settings::schema();
		$value  = $settings[ $key ] ?? '';

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( $field['text'] ?? '' )
				);
				break;

			case 'text':
				$attrs = '';
				foreach ( (array) ( $field['attrs'] ?? array() ) as $attr => $attr_value ) {
					$attrs .= ' ' . esc_attr( $attr ) . '="' . esc_attr( (string) $attr_value ) . '"';
				}
				printf(
					'<input type="text" class="regular-text" id="%1$s" name="%2$s" value="%3$s"%4$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					$attrs // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above, attribute by attribute.
				);
				break;

			case 'number':
				$descriptor = $schema[ $key ] ?? array();
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$s" max="%5$s" step="1" inputmode="numeric">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) (int) $value ),
					esc_attr( (string) ( $descriptor['min'] ?? 0 ) ),
					esc_attr( (string) ( $descriptor['max'] ?? PHP_INT_MAX ) )
				);
				break;

			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( (string) $option_value ),
						selected( (string) $value, (string) $option_value, false ),
						esc_html( (string) $option_label )
					);
				}
				echo '</select>';
				break;

			case 'radio':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( (array) $field['options'] as $option_value => $option_label ) {
					$option_id = $id . '-' . sanitize_key( (string) $option_value );
					printf(
						'<label for="%1$s" class="hprnb-radio"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s> %5$s</label>',
						esc_attr( $option_id ),
						esc_attr( $name ),
						esc_attr( (string) $option_value ),
						checked( (string) $value, (string) $option_value, false ),
						esc_html( (string) $option_label )
					);
				}
				echo '</fieldset>';
				break;

			case 'choice':
				// Radio buttons drawn as large boxes: a title and one plain sentence each.
				echo '<fieldset class="hprnb-choices"><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( (array) $field['options'] as $option_value => $option ) {
					$option_id = $id . '-' . sanitize_key( (string) $option_value );
					printf(
						'<label for="%1$s" class="hprnb-choice"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s> <span class="hprnb-choice__body"><strong class="hprnb-choice__title">%5$s</strong> <span class="hprnb-choice__text">%6$s</span></span></label>',
						esc_attr( $option_id ),
						esc_attr( $name ),
						esc_attr( (string) $option_value ),
						checked( (string) $value, (string) $option_value, false ),
						esc_html( (string) ( $option['title'] ?? '' ) ),
						esc_html( (string) ( $option['text'] ?? '' ) )
					);
				}
				echo '</fieldset>';
				break;

			case 'color':
				$hex = sanitize_hex_color( (string) $value );
				if ( empty( $hex ) ) {
					$hex = (string) ( $schema[ $key ]['default'] ?? '#000000' );
				}
				if ( 4 === strlen( $hex ) ) {
					$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
				}
				printf(
					'<input type="color" class="hprnb-color" id="%1$s" name="%2$s" value="%3$s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( strtoupper( $hex ) )
				);
				if ( ! empty( $field['contrast'] ) ) {
					printf(
						' <span class="hprnb-contrast-warning notice notice-warning inline" id="hprnb-contrast-%1$s" hidden></span>',
						esc_attr( $field['contrast'] )
					);
				}
				break;

			case 'ids':
				printf(
					'<input type="text" class="regular-text code" id="%1$s" name="%2$s" value="%3$s" placeholder="12, 34">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( implode( ', ', array_map( 'intval', (array) $value ) ) )
				);
				break;

			case 'window':
				$bounds = Settings::window_bounds();
				printf(
					'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="number" class="small-text" id="%1$s" name="%3$s" value="%4$s" min="1" max="1440" step="1" inputmode="numeric"> ',
					esc_attr( $id . '-value' ),
					esc_html__( 'Window value', 'horizon-press-news-bar' ),
					esc_attr( Settings::OPTION . '[window_value]' ),
					esc_attr( (string) (int) $settings['window_value'] )
				);
				printf( '<label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" name="%3$s">', esc_attr( $id . '-unit' ), esc_html__( 'Window unit', 'horizon-press-news-bar' ), esc_attr( Settings::OPTION . '[window_unit]' ) );
				$units = array(
					'minutes' => __( 'minutes', 'horizon-press-news-bar' ),
					'hours'   => __( 'hours', 'horizon-press-news-bar' ),
					'days'    => __( 'days', 'horizon-press-news-bar' ),
				);
				foreach ( $units as $unit_value => $unit_label ) {
					printf(
						'<option value="%1$s" data-hprnb-min="%2$d" data-hprnb-max="%3$d"%4$s>%5$s</option>',
						esc_attr( $unit_value ),
						(int) $bounds[ $unit_value ][0],
						(int) $bounds[ $unit_value ][1],
						selected( (string) $settings['window_unit'], $unit_value, false ),
						esc_html( $unit_label )
					);
				}
				echo '</select>';
				break;

			case 'contexts':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( (array) $field['options'] as $context_key => $context_label ) {
					$context_id = $id . '-' . sanitize_key( (string) $context_key );
					printf(
						'<label for="%1$s" class="hprnb-check"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>',
						esc_attr( $context_id ),
						esc_attr( Settings::OPTION . '[' . $key . '][' . $context_key . ']' ),
						checked( ! empty( $value[ $context_key ] ), true, false ),
						esc_html( (string) $context_label )
					);
				}
				echo '</fieldset>';
				break;

			case 'terms':
				self::render_terms( $key, (string) $field['taxonomy'], array_map( 'intval', (array) $value ), $field['label'] );
				break;
		}
	}

	/**
	 * Checkbox list of terms with a local filter and select/deselect buttons (JS enhanced).
	 *
	 * @param string $key      Setting key.
	 * @param string $taxonomy Taxonomy.
	 * @param int[]  $selected Selected term IDs.
	 * @param string $label    Field label.
	 * @return void
	 */
	private static function render_terms( string $key, string $taxonomy, array $selected, string $label ): void {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_TERMS,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<p class="description">' . esc_html__( 'No term available.', 'horizon-press-news-bar' ) . '</p>';
			return;
		}

		$filter_id = 'hprnb-filter-' . str_replace( '_', '-', $key );
		echo '<div class="hprnb-terms" data-hprnb-terms="' . esc_attr( $key ) . '">';
		echo '<div class="hprnb-terms__tools">';
		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><input type="search" class="hprnb-terms__filter" id="%1$s" placeholder="%3$s" autocomplete="off">',
			esc_attr( $filter_id ),
			/* translators: %s: field label. */
			esc_html( sprintf( __( 'Filter: %s', 'horizon-press-news-bar' ), $label ) ),
			esc_attr__( 'Filter…', 'horizon-press-news-bar' )
		);
		echo ' <button type="button" class="button-link hprnb-terms__all">' . esc_html__( 'Select all', 'horizon-press-news-bar' ) . '</button>';
		echo ' <button type="button" class="button-link hprnb-terms__none">' . esc_html__( 'Deselect all', 'horizon-press-news-bar' ) . '</button>';
		echo '</div>';
		echo '<ul class="hprnb-terms__list">';
		foreach ( $terms as $term ) {
			$term_id = (int) $term->term_id;
			printf(
				'<li><label><input type="checkbox" name="%1$s" value="%2$d"%3$s> <span class="hprnb-terms__name">%4$s</span> <span class="hprnb-terms__count">(%5$s)</span></label></li>',
				esc_attr( Settings::OPTION . '[' . $key . '][]' ),
				absint( $term_id ),
				checked( in_array( $term_id, $selected, true ), true, false ),
				esc_html( $term->name ),
				esc_html( number_format_i18n( (int) $term->count ) )
			);
		}
		echo '</ul>';
		if ( count( $terms ) >= self::MAX_TERMS ) {
			/* translators: %d: maximum number of terms listed. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the first %d terms are listed.', 'horizon-press-news-bar' ), self::MAX_TERMS ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Tools: export, import, reset (separate forms posting to admin-post.php).
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private static function render_tools( array $settings ): void {
		unset( $settings );
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=hprnb_export' ), 'hprnb_export' );
		?>
		<section class="hprnb-section" id="hprnb-section-tools">
			<h2><?php esc_html_e( 'Tools', 'horizon-press-news-bar' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Export', 'horizon-press-news-bar' ); ?></th>
					<td>
						<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Download settings (JSON)', 'horizon-press-news-bar' ); ?></a>
						<p class="description"><?php esc_html_e( 'The file contains the settings only, never posts or personal data.', 'horizon-press-news-bar' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hprnb-import-file"><?php esc_html_e( 'Import', 'horizon-press-news-bar' ); ?></label></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="hprnb-import-form">
							<input type="hidden" name="action" value="hprnb_import">
							<?php wp_nonce_field( 'hprnb_import' ); ?>
							<input type="file" id="hprnb-import-file" name="hprnb_import_file" accept=".json,application/json" required>
							<?php submit_button( __( 'Import', 'horizon-press-news-bar' ), 'secondary', 'hprnb_import_submit', false ); ?>
							<p class="description"><?php esc_html_e( 'JSON file exported by this plugin, 256 KB maximum. Unknown keys are ignored; category, tag and post IDs may differ between sites.', 'horizon-press-news-bar' ); ?></p>
						</form>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reset', 'horizon-press-news-bar' ); ?></th>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hprnb-reset-form">
							<input type="hidden" name="action" value="hprnb_reset">
							<?php wp_nonce_field( 'hprnb_reset' ); ?>
							<label for="hprnb-reset-confirm"><input type="checkbox" id="hprnb-reset-confirm" name="hprnb_reset_confirm" value="1"> <?php esc_html_e( 'I understand that every setting will be restored to its default value.', 'horizon-press-news-bar' ); ?></label>
							<p><?php submit_button( __( 'Restore defaults', 'horizon-press-news-bar' ), 'delete', 'hprnb_reset_submit', false ); ?></p>
						</form>
					</td>
				</tr>
			</tbody></table>
		</section>
		<?php
	}

	/**
	 * Live preview panel (sticky on desktop).
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private static function render_preview( array $settings ): void {
		$items = Query::items( $settings );
		$html  = Renderer::bar( $items, $settings );
		?>
		<aside class="hprnb-preview" id="hprnb-preview" aria-label="<?php esc_attr_e( 'Preview', 'horizon-press-news-bar' ); ?>">
			<h2><?php esc_html_e( 'Preview', 'horizon-press-news-bar' ); ?></h2>
			<div class="hprnb-preview__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Preview device', 'horizon-press-news-bar' ); ?>">
				<button type="button" role="tab" id="hprnb-preview-tab-desktop" class="hprnb-preview__tab is-active" aria-selected="true" aria-controls="hprnb-preview-stage" data-hprnb-device="desktop"><?php esc_html_e( 'Desktop', 'horizon-press-news-bar' ); ?></button>
				<button type="button" role="tab" id="hprnb-preview-tab-mobile" class="hprnb-preview__tab" aria-selected="false" aria-controls="hprnb-preview-stage" data-hprnb-device="mobile"><?php esc_html_e( 'Mobile', 'horizon-press-news-bar' ); ?></button>
			</div>
			<div class="hprnb-preview__stage" id="hprnb-preview-stage" data-hprnb-device="desktop">
				<div class="hprnb-preview__frame">
				<div id="hprnb-preview-root" class="<?php echo esc_attr( implode( ' ', Renderer::root_classes( $settings, true ) ) ); ?> hprnb-root--preview hprnb-root--flat" style="<?php echo esc_attr( Renderer::root_style( $settings ) ); ?>" data-hprnb-desktop="<?php echo esc_attr( (string) wp_json_encode( Renderer::profile_data( $settings, 'd' ) ) ); ?>" data-hprnb-mobile="<?php echo esc_attr( (string) wp_json_encode( Renderer::profile_data( $settings, 'm' ) ) ); ?>">
					<?php if ( '' === $html ) : ?>
					<p class="hprnb-preview__empty" id="hprnb-preview-empty"><?php echo esc_html( self::empty_message() ); ?></p>
					<?php else : ?>
						<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by the Renderer. ?>
					<?php endif; ?>
				</div>
				</div>
			</div>
			<p class="hprnb-preview__actions">
				<button type="button" class="button" id="hprnb-preview-refresh"><?php esc_html_e( 'Refresh preview posts', 'horizon-press-news-bar' ); ?></button>
				<span class="hprnb-preview__status" id="hprnb-preview-status" role="status" aria-live="polite"></span>
			</p>
			<p class="description"><?php esc_html_e( 'Colours, sizes and label update instantly. Content criteria are applied by "Refresh preview posts" (also triggered automatically shortly after a change). Nothing is saved until you click "Save settings".', 'horizon-press-news-bar' ); ?></p>
		</aside>
		<?php
	}
}
