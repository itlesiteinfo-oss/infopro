<?php
/**
 * Settings: single source of truth for option storage, schema and validation.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * The only class allowed to read or write the `hprnb_settings` option.
 */
final class Settings {

	/**
	 * Option name holding every setting.
	 */
	const OPTION = 'hprnb_settings';

	/**
	 * Option name holding the cache epoch (short unique string).
	 */
	const EPOCH_OPTION = 'hprnb_cache_epoch';

	/**
	 * Option name holding the settings schema version.
	 */
	const SCHEMA_OPTION = 'hprnb_schema_version';

	/**
	 * Post meta: this article is never listed among the headlines.
	 */
	const META_EXCLUDE = '_hprnb_exclude_item';

	/**
	 * Post meta: the bar is never displayed on this post's own page.
	 */
	const META_HIDE = '_hprnb_hide_bar';

	/**
	 * Maximum number of IDs kept in a list setting.
	 */
	const MAX_LIST_ITEMS = 500;

	/**
	 * Keys of the `contexts` map.
	 */
	const REVEAL_MODES = array( 'immediate', 'scroll', 'percent', 'end', 'paragraph', 'smart' );

	/**
	 * The one choice per device of the Mobile and Desktop tabs. Each name but `custom` stands for a
	 * fixed set of detailed values (behavior_presets()); `custom` leaves every detail to the admin.
	 */
	const BEHAVIORS = array( 'reading', 'fold', 'always', 'custom' );

	const CONTEXT_KEYS = array( 'front_page', 'blog_home', 'single_post', 'page', 'category', 'tag', 'archive', 'search', 'not_found' );

	/**
	 * Per-request memo of get().
	 *
	 * @var array|null
	 */
	private static ?array $memo = null;

	/**
	 * Schema definition: the single definition of types, defaults, bounds, enums and sanitizers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		static $schema = null;
		if ( null !== $schema ) {
			return $schema;
		}

		$contexts_default = array_fill_keys( self::CONTEXT_KEYS, true );

		$schema = array(
			'enabled'                     => array(
				'type'    => 'bool',
				'default' => true,
			),
			'label_text'                  => array(
				'type'        => 'text',
				'default'     => 'EN CONTINU',
				'max_length'  => 120,
				'allow_empty' => true,
			),
			'label_position'              => array(
				'type'    => 'enum',
				'default' => 'start',
				'options' => array( 'start', 'end' ),
			),
			'window_value'                => array(
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 1440,
			),
			'window_unit'                 => array(
				'type'    => 'enum',
				'default' => 'hours',
				'options' => array( 'minutes', 'hours', 'days' ),
			),
			'categories_include'          => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'categories_exclude'          => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'tags_include'                => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'content_exclude_post_ids'    => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'max_items'                   => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 30,
			),
			'orderby'                     => array(
				'type'    => 'enum',
				'default' => 'date_desc',
				'options' => array( 'date_desc', 'date_asc' ),
			),
			'bg_color'                    => array(
				'type'    => 'color',
				'default' => '#1B1C20',
			),
			'text_color'                  => array(
				'type'    => 'color',
				'default' => '#F5F5F5',
			),
			'label_bg_color'              => array(
				'type'    => 'color',
				'default' => '#CE3029',
			),
			'label_text_color'            => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'link_hover_color'            => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'accent_color'                => array(
				'type'    => 'color',
				'default' => '#CE3029',
			),
			'font_size'                   => array(
				'type'    => 'int',
				'default' => 15,
				'min'     => 10,
				'max'     => 24,
			),
			'bar_height'                  => array(
				'type'    => 'int',
				'default' => 40,
				'min'     => 32,
				'max'     => 56,
			),
			'align_container'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'max_width'                   => array(
				'type'    => 'int',
				'default' => 1230,
				'min'     => 960,
				'max'     => 1920,
			),
			'gutter'                      => array(
				'type'    => 'int',
				'default' => 15,
				'min'     => 0,
				'max'     => 40,
			),
			'z_index'                     => array(
				'type'    => 'int',
				'default' => 99990,
				'min'     => 1,
				'max'     => 2147483647,
			),
			'layout_mode'                 => array(
				'type'    => 'enum',
				'default' => 'reserve',
				'options' => array( 'reserve', 'overlay' ),
			),
			'show_relative_time'          => array(
				'type'    => 'bool',
				'default' => false,
			),
			'relative_time_max_hours'     => array(
				'type'    => 'int',
				'default' => 48,
				'min'     => 1,
				'max'     => 720,
			),
			'thumbnail_size'              => array(
				'type'    => 'key',
				'default' => 'thumbnail',
			),
			'show_separator'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			'separator_char'              => array(
				'type'        => 'text',
				'default'     => '•',
				'max_length'  => 8,
				'allow_empty' => false,
			),
			'separator_after_last'        => array(
				'type'    => 'bool',
				'default' => true,
			),
			'ticker_enabled'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			'ticker_mode'                 => array(
				'type'    => 'enum',
				'default' => 'marquee',
				'options' => array( 'marquee', 'rotate', 'manual' ),
			),
			'ticker_speed'                => array(
				'type'    => 'int',
				'default' => 30,
				'min'     => 10,
				'max'     => 80,
			),
			'rotate_interval'             => array(
				'type'    => 'int',
				'default' => 5000,
				'min'     => 3000,
				'max'     => 12000,
			),
			'pause_on_hover'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			'close_button'                => array(
				'type'    => 'bool',
				'default' => true,
			),
			'remember_dismiss'            => array(
				'type'    => 'bool',
				'default' => true,
			),
			'dismiss_duration_hours'      => array(
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 720,
			),
			'desktop_behavior'            => array(
				'type'    => 'enum',
				'default' => 'always',
				'options' => self::BEHAVIORS,
			),
			'mobile_behavior'             => array(
				'type'    => 'enum',
				'default' => 'fold',
				'options' => self::BEHAVIORS,
			),
			'desktop_reveal_mode'         => array(
				'type'    => 'enum',
				'default' => 'immediate',
				'options' => self::REVEAL_MODES,
			),
			'desktop_reveal_value'        => array(
				'type'    => 'int',
				'default' => 400,
				'min'     => 0,
				'max'     => 4000,
			),
			'desktop_reveal_paragraph'    => array(
				'type'    => 'int',
				'default' => 2,
				'min'     => 1,
				'max'     => 30,
			),
			'mobile_reveal_mode'          => array(
				'type'    => 'enum',
				'default' => 'immediate',
				'options' => self::REVEAL_MODES,
			),
			'mobile_reveal_value'         => array(
				'type'    => 'int',
				'default' => 400,
				'min'     => 0,
				'max'     => 4000,
			),
			'mobile_reveal_paragraph'     => array(
				'type'    => 'int',
				'default' => 2,
				'min'     => 1,
				'max'     => 30,
			),
			'smart_selector'              => array(
				'type'        => 'text',
				'default'     => '',
				'max_length'  => 200,
				'allow_empty' => true,
			),
			'smart_mobile_progress'       => array(
				'type'    => 'int',
				'default' => 55,
				'min'     => 1,
				'max'     => 100,
			),
			'smart_mobile_time'           => array(
				'type'    => 'int',
				'default' => 15,
				'min'     => 0,
				'max'     => 120,
			),
			'smart_mobile_up'             => array(
				'type'    => 'int',
				'default' => 300,
				'min'     => 50,
				'max'     => 1200,
			),
			'smart_mobile_fallback'       => array(
				'type'    => 'int',
				'default' => 75,
				'min'     => 1,
				'max'     => 100,
			),
			'smart_mobile_fallback_time'  => array(
				'type'    => 'int',
				'default' => 25,
				'min'     => 0,
				'max'     => 180,
			),
			'smart_desktop_progress'      => array(
				'type'    => 'int',
				'default' => 50,
				'min'     => 1,
				'max'     => 100,
			),
			'smart_desktop_time'          => array(
				'type'    => 'int',
				'default' => 12,
				'min'     => 0,
				'max'     => 120,
			),
			'smart_desktop_up'            => array(
				'type'    => 'int',
				'default' => 350,
				'min'     => 50,
				'max'     => 1200,
			),
			'smart_desktop_fallback'      => array(
				'type'    => 'int',
				'default' => 65,
				'min'     => 1,
				'max'     => 100,
			),
			'smart_desktop_fallback_time' => array(
				'type'    => 'int',
				'default' => 20,
				'min'     => 0,
				'max'     => 180,
			),
			'accent_edge'                 => array(
				'type'    => 'bool',
				'default' => true,
			),
			'theme_offset'                => array(
				'type'    => 'bool',
				'default' => true,
			),
			'show_on_desktop'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'show_on_mobile'              => array(
				'type'    => 'bool',
				'default' => true,
			),
			'desktop_layout'              => array(
				'type'    => 'enum',
				'default' => 'inline',
				'options' => array( 'inline', 'stacked' ),
			),
			'desktop_label_style'         => array(
				'type'    => 'enum',
				'default' => 'pill',
				'options' => array( 'strip', 'pill', 'hidden' ),
			),
			'desktop_label_dot'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'desktop_show_counter'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			'desktop_lines'               => array(
				'type'    => 'int',
				'default' => 1,
				'min'     => 1,
				'max'     => 4,
			),
			'desktop_show_progress'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'desktop_show_thumbnail'      => array(
				'type'    => 'bool',
				'default' => false,
			),
			'desktop_thumb_position'      => array(
				'type'    => 'enum',
				'default' => 'before',
				'options' => array( 'before', 'after' ),
			),
			'desktop_thumb_size'          => array(
				'type'    => 'int',
				'default' => 32,
				'min'     => 16,
				'max'     => 80,
			),
			'desktop_contexts'            => array(
				'type'    => 'bool_map',
				'default' => $contexts_default,
				'keys'    => self::CONTEXT_KEYS,
			),
			'desktop_placement'           => array(
				'type'    => 'enum',
				'default' => 'fixed',
				'options' => array( 'fixed', 'inline' ),
			),
			'desktop_inline_anchor'       => array(
				'type'    => 'enum',
				'default' => 'after',
				'options' => array( 'before', 'after', 'before_end' ),
			),
			'desktop_inline_paragraph'    => array(
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
				'max'     => 30,
			),
			'desktop_hide_on_scroll'      => array(
				'type'    => 'bool',
				'default' => false,
			),
			'desktop_collapse_mode'       => array(
				'type'    => 'enum',
				'default' => 'scroll',
				'options' => array( 'scroll', 'threshold', 'immediate', 'article' ),
			),
			'desktop_collapse_after'      => array(
				'type'    => 'int',
				'default' => 120,
				'min'     => 0,
				'max'     => 800,
			),
			'desktop_next_hide'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_layout'               => array(
				'type'    => 'enum',
				'default' => 'card',
				'options' => array( 'card', 'flow', 'stacked', 'inline' ),
			),
			'mobile_card_thumb'           => array(
				'type'    => 'int',
				'default' => 132,
				'min'     => 72,
				'max'     => 160,
			),
			'mobile_contexts'             => array(
				'type'    => 'bool_map',
				'default' => $contexts_default,
				'keys'    => self::CONTEXT_KEYS,
			),
			'mobile_placement'            => array(
				'type'    => 'enum',
				'default' => 'fixed',
				'options' => array( 'fixed', 'inline' ),
			),
			'mobile_inline_anchor'        => array(
				'type'    => 'enum',
				'default' => 'after',
				'options' => array( 'before', 'after', 'before_end' ),
			),
			'mobile_inline_paragraph'     => array(
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
				'max'     => 30,
			),
			'mobile_label_style'          => array(
				'type'    => 'enum',
				'default' => 'pill',
				'options' => array( 'pill', 'strip', 'hidden' ),
			),
			'mobile_label_dot'            => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_show_counter'         => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_card_float'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_lines'                => array(
				'type'    => 'int',
				'default' => 3,
				'min'     => 1,
				'max'     => 4,
			),
			'mobile_bar_height'           => array(
				'type'    => 'int',
				'default' => 76,
				'min'     => 64,
				'max'     => 96,
			),
			'mobile_font_size'            => array(
				'type'    => 'int',
				'default' => 16,
				'min'     => 12,
				'max'     => 24,
			),
			'mobile_ticker_mode'          => array(
				'type'    => 'enum',
				'default' => 'rotate',
				'options' => array( 'inherit', 'static', 'marquee', 'rotate', 'manual' ),
			),
			'mobile_show_progress'        => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_swipe'                => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_hide_on_scroll'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_peek'                 => array(
				'type'    => 'enum',
				'default' => 'headline',
				'options' => array( 'headline', 'label' ),
			),
			'mobile_deep_collapse'        => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_kbd_hide'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_show_thumbnail'       => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_thumb_position'       => array(
				'type'    => 'enum',
				'default' => 'after',
				'options' => array( 'before', 'after' ),
			),
			'mobile_thumb_size'           => array(
				'type'    => 'int',
				'default' => 48,
				'min'     => 16,
				'max'     => 80,
			),
			'mobile_collapse_mode'        => array(
				'type'    => 'enum',
				'default' => 'scroll',
				'options' => array( 'scroll', 'threshold', 'immediate', 'article' ),
			),
			'mobile_collapse_after'       => array(
				'type'    => 'int',
				'default' => 120,
				'min'     => 0,
				'max'     => 800,
			),
			'mobile_next_hide'            => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_controls_place'       => array(
				'type'    => 'enum',
				'default' => 'inside',
				'options' => array( 'inside', 'outside' ),
			),
			'mobile_show_pause'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_show_close'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_controls_layout'      => array(
				'type'    => 'enum',
				'default' => 'column',
				'options' => array( 'column', 'row' ),
			),
			'mobile_peek_thumbnail'       => array(
				'type'    => 'bool',
				'default' => true,
			),
			'mobile_label_pulse'          => array(
				'type'    => 'enum',
				'default' => 'appear',
				'options' => array( 'always', 'appear', 'collapsed', 'never' ),
			),
			'mobile_label_compact'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_show_separator'       => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_custom_colors'        => array(
				'type'    => 'bool',
				'default' => false,
			),
			'mobile_bg_color'             => array(
				'type'    => 'color',
				'default' => '#1B1C20',
			),
			'mobile_text_color'           => array(
				'type'    => 'color',
				'default' => '#F5F5F5',
			),
			'mobile_accent_color'         => array(
				'type'    => 'color',
				'default' => '#CE3029',
			),
			'mobile_label_text_color'     => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'display_scope'               => array(
				'type'    => 'enum',
				'default' => 'everywhere',
				'options' => array( 'everywhere', 'custom' ),
			),
			'contexts'                    => array(
				'type'    => 'bool_map',
				'default' => $contexts_default,
				'keys'    => self::CONTEXT_KEYS,
			),
			'display_exclude_ids'         => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'render_mode'                 => array(
				'type'    => 'enum',
				'default' => 'hybrid',
				'options' => array( 'hybrid', 'php' ),
			),
			'cache_ttl'                   => array(
				'type'    => 'int',
				'default' => 120,
				'min'     => 30,
				'max'     => 600,
			),
			'stale_threshold'             => array(
				'type'    => 'int',
				'default' => 180,
				'min'     => 30,
				'max'     => 3600,
			),
			'auto_display'                => array(
				'type'    => 'bool',
				'default' => true,
			),
			'shortcode_enabled'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'uninstall_delete_data'       => array(
				'type'    => 'bool',
				'default' => false,
			),
		);

		return $schema;
	}

	/**
	 * Default values, in schema order.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::schema() as $key => $descriptor ) {
			$defaults[ $key ] = $descriptor['default'];
		}
		return $defaults;
	}

	/**
	 * Presentation values of the v2 design (« Sombre + pastille rouge »): applied once when a 1.x
	 * install is upgraded (schema 1 → 2) and offered as a preset in the Colours tab.
	 *
	 * @return array<string, mixed>
	 */
	public static function v2_preset(): array {
		return array(
			'bg_color'                => '#1B1C20',
			'text_color'              => '#F5F5F5',
			'label_bg_color'          => '#CE3029',
			'label_text_color'        => '#FFFFFF',
			'link_hover_color'        => '#FFFFFF',
			'accent_color'            => '#CE3029',
			'font_size'               => 15,
			'bar_height'              => 40,
			'align_container'         => true,
			'max_width'               => 1230,
			'gutter'                  => 15,
			'ticker_enabled'          => true,
			'ticker_mode'             => 'marquee',
			'ticker_speed'            => 30,
			'rotate_interval'         => 5000,
			'pause_on_hover'          => true,
			'show_separator'          => true,
			'separator_after_last'    => true,
			'close_button'            => true,
			'remember_dismiss'        => true,
			'dismiss_duration_hours'  => 24,
			'theme_offset'            => true,
			'label_position'          => 'start',
			'desktop_layout'          => 'inline',
			'desktop_label_style'     => 'pill',
			'desktop_label_dot'       => true,
			'mobile_layout'           => 'flow',
			'mobile_label_style'      => 'pill',
			'mobile_label_dot'        => true,
			'mobile_show_counter'     => false,
			'mobile_lines'            => 2,
			'mobile_bar_height'       => 76,
			'mobile_font_size'        => 16,
			'mobile_ticker_mode'      => 'rotate',
			'mobile_show_progress'    => true,
			'mobile_swipe'            => true,
			'mobile_hide_on_scroll'   => true,
			'mobile_peek'             => 'headline',
			'mobile_deep_collapse'    => true,
			'mobile_kbd_hide'         => true,
			'mobile_custom_colors'    => false,
			'mobile_bg_color'         => '#1B1C20',
			'mobile_text_color'       => '#F5F5F5',
			'mobile_accent_color'     => '#CE3029',
			'mobile_label_text_color' => '#FFFFFF',
		);
	}

	/**
	 * Colour presets of the Colours tab (the keys they fill).
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function color_presets(): array {
		return array(
			'dark' => array(
				'bg_color'         => '#1B1C20',
				'text_color'       => '#F5F5F5',
				'label_bg_color'   => '#CE3029',
				'label_text_color' => '#FFFFFF',
				'link_hover_color' => '#FFFFFF',
				'accent_color'     => '#CE3029',
			),
			'red'  => array(
				'bg_color'         => '#CE3029',
				'text_color'       => '#FFFFFF',
				'label_bg_color'   => '#FFFFFF',
				'label_text_color' => '#1B1C20',
				'link_hover_color' => '#FFFFFF',
				'accent_color'     => '#FFFFFF',
			),
		);
	}

	/**
	 * What each behaviour of the Mobile and Desktop tabs stands for, as detailed settings without
	 * their device prefix. Saving a behaviour writes these values, so the front end only ever reads
	 * the detailed settings and "Custom" starts from whatever the last behaviour had set.
	 *
	 * - reading: the bar arrives in full at the chosen paragraph before the end of the article,
	 *   folds on any scroll back up, opens again when reading on and goes away completely in the
	 *   next article of a continuous-loading theme;
	 * - fold: visible with the page, out of the way while scrolling down, back on a scroll up;
	 * - always: visible with the page and never folded.
	 *
	 * The number of paragraphs, the collapse threshold and the look of the folded strip stay the
	 * admin's own settings: a behaviour never overwrites them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function behavior_presets(): array {
		return array(
			'reading' => array(
				'reveal_mode'    => 'paragraph',
				'hide_on_scroll' => true,
				'collapse_mode'  => 'article',
				'next_hide'      => true,
			),
			'fold'    => array(
				'reveal_mode'    => 'immediate',
				'hide_on_scroll' => true,
				'collapse_mode'  => 'scroll',
				'next_hide'      => false,
			),
			'always'  => array(
				'reveal_mode'    => 'immediate',
				'hide_on_scroll' => false,
				'next_hide'      => false,
			),
		);
	}

	/**
	 * Writes the detailed values of each device's behaviour (nothing for "custom").
	 *
	 * @param array $clean Sanitised settings.
	 * @return array
	 */
	private static function apply_behaviors( array $clean ): array {
		$presets = self::behavior_presets();
		foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
			$name = (string) ( $clean[ $prefix . 'behavior' ] ?? 'custom' );
			if ( ! isset( $presets[ $name ] ) ) {
				continue;
			}
			foreach ( $presets[ $name ] as $key => $value ) {
				$clean[ $prefix . $key ] = $value;
			}
		}
		return $clean;
	}

	/**
	 * The behaviour whose values a device's detailed settings already hold, or "custom".
	 *
	 * @param array  $settings Sanitised settings.
	 * @param string $prefix   'desktop_' or 'mobile_'.
	 * @return string
	 */
	public static function detect_behavior( array $settings, string $prefix ): string {
		foreach ( self::behavior_presets() as $name => $preset ) {
			$match = true;
			foreach ( $preset as $key => $value ) {
				if ( ! array_key_exists( $prefix . $key, $settings ) || $settings[ $prefix . $key ] !== $value ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return $name;
			}
		}
		return 'custom';
	}

	/**
	 * Brings stored settings to the current schema. Runs on `init`; a no-op once up to date.
	 * Schema 2 (plugin 2.0) applies the v2 presentation preset to an existing 1.x install.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $stored >= HPRNB_SCHEMA_VERSION ) {
			return;
		}
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$upgraded = self::migrate( $raw, $stored );
		if ( $upgraded !== $raw ) {
			update_option( self::OPTION, self::sanitize( $upgraded ), true );
			Invalidation::invalidate();
			self::$memo = null;
		}
		update_option( self::SCHEMA_OPTION, (string) HPRNB_SCHEMA_VERSION, true );
	}

	/**
	 * Brings a raw settings array written under an older schema up to the current one. Pure: it
	 * reads nothing and writes nothing, so the upgrade path and a settings import share it.
	 *
	 * @param array $raw    Stored (or imported) settings, not yet sanitised.
	 * @param int   $stored Schema version they were written under.
	 * @return array
	 */
	public static function migrate( array $raw, int $stored ): array {
		$upgraded = $raw;
		if ( $stored < 2 && ! empty( $raw ) ) {
			$upgraded = array_merge( $upgraded, self::v2_preset() );
		}
		// Schema 3: the single `show_thumbnail` switch became one per profile (position and size too).
		if ( $stored < 3 && ! empty( $raw['show_thumbnail'] ) ) {
			$upgraded['desktop_show_thumbnail'] = true;
			$upgraded['mobile_show_thumbnail']  = true;
		}
		// Schema 4: 2.4.0 saved the per-profile page types under the wrong form name, so a site that
		// saved its settings once ended up with every type unticked — and no bar anywhere. An
		// all-false map could only come from that bug: put it back to "every type".
		if ( $stored < 4 ) {
			foreach ( array( 'desktop_contexts', 'mobile_contexts' ) as $map_key ) {
				$map = $raw[ $map_key ] ?? null;
				if ( is_array( $map ) && ! in_array( true, array_map( array( self::class, 'to_bool_loose' ), $map ), true ) ) {
					$upgraded[ $map_key ] = array_fill_keys( self::CONTEXT_KEYS, true );
				}
			}
		}
		// Schema 5: when the bar appears is decided per device. A site that had tuned the single
		// setting keeps exactly that behaviour on both devices; the mobile design is untouched.
		if ( $stored < 5 ) {
			foreach ( array( 'reveal_mode', 'reveal_value', 'reveal_paragraph' ) as $key ) {
				if ( ! array_key_exists( $key, $raw ) ) {
					continue;
				}
				foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
					if ( ! array_key_exists( $prefix . $key, $raw ) ) {
						$upgraded[ $prefix . $key ] = $raw[ $key ];
					}
				}
				unset( $upgraded[ $key ] );
			}
		}
		// Schema 6: one behaviour per device. A site keeps exactly what it had: its detailed values
		// are named after the behaviour they already match, otherwise "custom". The next-article
		// setting is new and off, so "reading" is never picked here — it would change the site.
		if ( $stored < 6 && ! empty( $raw ) ) {
			$probe = self::sanitize(
				array_merge(
					$upgraded,
					array(
						'desktop_behavior' => 'custom',
						'mobile_behavior'  => 'custom',
					)
				)
			);
			foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
				if ( ! array_key_exists( $prefix . 'behavior', $raw ) ) {
					$upgraded[ $prefix . 'behavior' ] = self::detect_behavior( $probe, $prefix );
				}
			}
		}

		return $upgraded;
	}

	/**
	 * Whether any profile shows the featured image: the `<img>` then lives in the cached markup and
	 * each profile hides it or not (classes on the root, outside the cache).
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function wants_thumbnails( array $settings ): bool {
		// The mobile "card" design is built around its image: it always needs one in the markup.
		return ! empty( $settings['desktop_show_thumbnail'] )
			|| ! empty( $settings['mobile_show_thumbnail'] )
			|| 'card' === ( $settings['mobile_layout'] ?? 'flow' );
	}

	/**
	 * Bounds of the time window value for each unit.
	 *
	 * @return array<string, array{0:int,1:int}>
	 */
	public static function window_bounds(): array {
		return array(
			'minutes' => array( 1, 1440 ),
			'hours'   => array( 1, 720 ),
			'days'    => array( 1, 30 ),
		);
	}

	/**
	 * Stored settings, sanitized, without the `hprnb_settings` filter.
	 *
	 * @return array<string, mixed>
	 */
	public static function raw(): array {
		$stored = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Effective settings (sanitized stored values, then the `hprnb_settings` filter, then sanitized again).
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null === self::$memo ) {
			$raw = self::raw();

			/**
			 * Filters the effective plugin settings.
			 *
			 * @param array $settings Sanitized settings.
			 */
			$filtered   = apply_filters( 'hprnb_settings', $raw );
			self::$memo = self::sanitize( is_array( $filtered ) ? $filtered : $raw, $raw );
		}
		return self::$memo;
	}

	/**
	 * Clears the per-request memo.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = null;
	}

	/**
	 * Persists sanitized settings (autoload true, boolean).
	 *
	 * @param array $clean Settings already passed through sanitize().
	 * @return bool Whether the option value changed.
	 */
	public static function update( array $clean ): bool {
		self::flush();
		return update_option( self::OPTION, self::sanitize( $clean ), true );
	}

	/**
	 * Restores the exact defaults.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::update( self::defaults() );
	}

	/**
	 * Whether the administrator asked for data deletion at uninstall.
	 *
	 * @return bool
	 */
	public static function uninstall_delete_requested(): bool {
		$raw = self::raw();
		return ! empty( $raw['uninstall_delete_data'] );
	}

	/**
	 * The single validator used by the admin form, the JSON import and the preview.
	 *
	 * Unknown keys are ignored. Missing keys take the value found in $base (defaults by default).
	 *
	 * @param mixed      $input Untrusted input.
	 * @param array|null $base  Values used for missing keys. Defaults to defaults().
	 * @return array<string, mixed> Clean settings in schema order.
	 */
	public static function sanitize( $input, ?array $base = null ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$base     = null === $base ? $defaults : array_merge( $defaults, $base );
		$clean    = array();

		foreach ( self::schema() as $key => $descriptor ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$clean[ $key ] = self::sanitize_value( $descriptor, $base[ $key ], $base[ $key ] );
				continue;
			}
			$clean[ $key ] = self::sanitize_value( $descriptor, $input[ $key ], $base[ $key ] );
		}

		// Cross-field dependency: the window value is bounded by its unit.
		$bounds                = self::window_bounds();
		$limits                = $bounds[ $clean['window_unit'] ];
		$clean['window_value'] = max( $limits[0], min( $limits[1], (int) $clean['window_value'] ) );

		// Cross-field dependency: a behaviour other than "custom" decides its detailed values.
		return self::apply_behaviors( $clean );
	}

	/**
	 * Validator with HTML form semantics: a missing checkbox means false, a missing list means empty.
	 *
	 * @param mixed $input Raw form data (e.g. $_POST['hprnb_settings']).
	 * @return array<string, mixed>
	 */
	public static function sanitize_form( $input ): array {
		$base = array();
		foreach ( self::schema() as $key => $descriptor ) {
			switch ( $descriptor['type'] ) {
				case 'bool':
					$base[ $key ] = false;
					break;
				case 'id_list':
					$base[ $key ] = array();
					break;
				case 'bool_map':
					$base[ $key ] = array_fill_keys( $descriptor['keys'], false );
					break;
				default:
					$base[ $key ] = $descriptor['default'];
			}
		}
		return self::sanitize( $input, $base );
	}

	/**
	 * Sanitizes one value according to its schema descriptor.
	 *
	 * @param array $descriptor Schema descriptor.
	 * @param mixed $value      Untrusted value.
	 * @param mixed $fallback   Value used when the input is invalid (or for missing map keys).
	 * @return mixed
	 */
	private static function sanitize_value( array $descriptor, $value, $fallback ) {
		$default = $descriptor['default'];

		switch ( $descriptor['type'] ) {
			case 'bool':
				return self::to_bool( $value, (bool) $default );

			case 'int':
				if ( ! is_numeric( $value ) ) {
					$value = is_numeric( $fallback ) ? $fallback : $default;
				}
				return max( (int) $descriptor['min'], min( (int) $descriptor['max'], (int) $value ) );

			case 'text':
				$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
				$text = mb_substr( $text, 0, (int) $descriptor['max_length'] );
				if ( '' === $text && empty( $descriptor['allow_empty'] ) ) {
					return (string) $default;
				}
				return $text;

			case 'key':
				$key = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
				return '' === $key ? (string) $default : $key;

			case 'color':
				$color = is_string( $value ) ? sanitize_hex_color( trim( $value ) ) : null;
				return empty( $color ) ? (string) $default : $color;

			case 'enum':
				return ( is_string( $value ) && in_array( $value, $descriptor['options'], true ) ) ? $value : $default;

			case 'id_list':
				if ( ! is_array( $value ) && ! is_scalar( $value ) ) {
					return array();
				}
				$ids = wp_parse_id_list( $value );
				$ids = array_values( array_unique( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) ) );
				return array_slice( $ids, 0, self::MAX_LIST_ITEMS );

			case 'bool_map':
				$value    = is_array( $value ) ? $value : array();
				$fallback = is_array( $fallback ) ? $fallback : array();
				$map      = array();
				foreach ( $descriptor['keys'] as $map_key ) {
					if ( array_key_exists( $map_key, $value ) ) {
						$map[ $map_key ] = self::to_bool( $value[ $map_key ], false );
					} else {
						$map[ $map_key ] = ! empty( $fallback[ $map_key ] );
					}
				}
				return $map;
		}

		return $default;
	}

	/**
	 * Loose boolean for stored maps (the strict converter needs a fallback argument).
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function to_bool_loose( $value ): bool {
		return self::to_bool( $value, false );
	}

	/**
	 * Strict boolean conversion: true, 1, "1", "true", "on", "yes" are true; any other value is false.
	 *
	 * @param mixed $value    Untrusted value.
	 * @param bool  $fallback Value used when the input is not scalar.
	 * @return bool
	 */
	private static function to_bool( $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0.0 !== (float) $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'on', 'yes' ), true );
		}
		return $fallback;
	}
}
