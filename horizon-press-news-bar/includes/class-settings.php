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
	 * Maximum number of IDs kept in a list setting.
	 */
	const MAX_LIST_ITEMS = 500;

	/**
	 * Keys of the `contexts` map.
	 */
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
			'enabled'                  => array(
				'type'    => 'bool',
				'default' => true,
			),
			'label_text'               => array(
				'type'        => 'text',
				'default'     => 'TOUTE L’ACTUALITÉ',
				'max_length'  => 120,
				'allow_empty' => true,
			),
			'label_position'           => array(
				'type'    => 'enum',
				'default' => 'end',
				'options' => array( 'start', 'end' ),
			),
			'window_value'             => array(
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 1440,
			),
			'window_unit'              => array(
				'type'    => 'enum',
				'default' => 'hours',
				'options' => array( 'minutes', 'hours', 'days' ),
			),
			'categories_include'       => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'categories_exclude'       => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'tags_include'             => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'content_exclude_post_ids' => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'max_items'                => array(
				'type'    => 'int',
				'default' => 10,
				'min'     => 1,
				'max'     => 50,
			),
			'orderby'                  => array(
				'type'    => 'enum',
				'default' => 'date_desc',
				'options' => array( 'date_desc', 'date_asc' ),
			),
			'bg_color'                 => array(
				'type'    => 'color',
				'default' => '#B00000',
			),
			'text_color'               => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'label_bg_color'           => array(
				'type'    => 'color',
				'default' => '#8F0000',
			),
			'label_text_color'         => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'link_hover_color'         => array(
				'type'    => 'color',
				'default' => '#FFFFFF',
			),
			'font_size'                => array(
				'type'    => 'int',
				'default' => 14,
				'min'     => 10,
				'max'     => 24,
			),
			'bar_height'               => array(
				'type'    => 'int',
				'default' => 44,
				'min'     => 28,
				'max'     => 120,
			),
			'z_index'                  => array(
				'type'    => 'int',
				'default' => 99990,
				'min'     => 1,
				'max'     => 2147483647,
			),
			'layout_mode'              => array(
				'type'    => 'enum',
				'default' => 'reserve',
				'options' => array( 'reserve', 'overlay' ),
			),
			'show_relative_time'       => array(
				'type'    => 'bool',
				'default' => false,
			),
			'relative_time_max_hours'  => array(
				'type'    => 'int',
				'default' => 48,
				'min'     => 1,
				'max'     => 720,
			),
			'show_thumbnail'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'thumbnail_size'           => array(
				'type'    => 'key',
				'default' => 'thumbnail',
			),
			'show_separator'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'separator_char'           => array(
				'type'        => 'text',
				'default'     => '•',
				'max_length'  => 8,
				'allow_empty' => false,
			),
			'separator_after_last'     => array(
				'type'    => 'bool',
				'default' => true,
			),
			'ticker_enabled'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'ticker_mode'              => array(
				'type'    => 'enum',
				'default' => 'marquee',
				'options' => array( 'marquee', 'rotate', 'manual' ),
			),
			'ticker_speed'             => array(
				'type'    => 'int',
				'default' => 60,
				'min'     => 10,
				'max'     => 300,
			),
			'rotate_interval'          => array(
				'type'    => 'int',
				'default' => 5000,
				'min'     => 1000,
				'max'     => 60000,
			),
			'pause_on_hover'           => array(
				'type'    => 'bool',
				'default' => false,
			),
			'close_button'             => array(
				'type'    => 'bool',
				'default' => false,
			),
			'remember_dismiss'         => array(
				'type'    => 'bool',
				'default' => false,
			),
			'dismiss_duration_hours'   => array(
				'type'    => 'int',
				'default' => 24,
				'min'     => 1,
				'max'     => 720,
			),
			'show_on_desktop'          => array(
				'type'    => 'bool',
				'default' => true,
			),
			'show_on_mobile'           => array(
				'type'    => 'bool',
				'default' => true,
			),
			'display_scope'            => array(
				'type'    => 'enum',
				'default' => 'everywhere',
				'options' => array( 'everywhere', 'custom' ),
			),
			'contexts'                 => array(
				'type'    => 'bool_map',
				'default' => $contexts_default,
				'keys'    => self::CONTEXT_KEYS,
			),
			'display_exclude_ids'      => array(
				'type'    => 'id_list',
				'default' => array(),
			),
			'render_mode'              => array(
				'type'    => 'enum',
				'default' => 'hybrid',
				'options' => array( 'hybrid', 'php' ),
			),
			'cache_ttl'                => array(
				'type'    => 'int',
				'default' => 120,
				'min'     => 30,
				'max'     => 600,
			),
			'stale_threshold'          => array(
				'type'    => 'int',
				'default' => 180,
				'min'     => 30,
				'max'     => 3600,
			),
			'auto_display'             => array(
				'type'    => 'bool',
				'default' => true,
			),
			'shortcode_enabled'        => array(
				'type'    => 'bool',
				'default' => true,
			),
			'uninstall_delete_data'    => array(
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

		return $clean;
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
