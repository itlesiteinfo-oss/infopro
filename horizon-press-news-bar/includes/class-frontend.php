<?php
/**
 * Front-end orchestration: eligibility, assets, body class and footer rendering.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates SSR according to the decision tree of the specification.
 */
final class Frontend {

	/**
	 * Settings resolved at `wp`.
	 *
	 * @var array|null
	 */
	private static ?array $settings = null;

	/**
	 * Whether automatic display is eligible for the current request.
	 *
	 * @var bool
	 */
	private static bool $eligible = false;

	/**
	 * Whether prepare() has run.
	 *
	 * @var bool
	 */
	private static bool $prepared = false;

	/**
	 * Whether the single `#hprnb-root` has been rendered (footer or shortcode).
	 *
	 * @var bool
	 */
	private static bool $root_claimed = false;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp', array( self::class, 'prepare' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_head', array( self::class, 'head' ), 1 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_action( 'wp_footer', array( self::class, 'footer' ) );
	}

	/**
	 * Evaluates eligibility once and warms the payload when eligible.
	 *
	 * @return void
	 */
	public static function prepare(): void {
		if ( self::$prepared ) {
			return;
		}
		self::$prepared = true;

		$settings       = Settings::get();
		self::$settings = $settings;
		self::$eligible = ! empty( $settings['auto_display'] ) && Visibility::should_display( $settings );

		if ( self::$eligible ) {
			Payload::get( $settings );
		}
	}

	/**
	 * Whether the automatic display is eligible.
	 *
	 * @return bool
	 */
	public static function is_eligible(): bool {
		self::prepare();
		return self::$eligible;
	}

	/**
	 * Claims the single root. True only for the first caller.
	 *
	 * @return bool
	 */
	public static function claim_root(): bool {
		if ( self::$root_claimed ) {
			return false;
		}
		self::$root_claimed = true;
		return true;
	}

	/**
	 * Whether the root has been rendered already.
	 *
	 * @return bool
	 */
	public static function root_claimed(): bool {
		return self::$root_claimed;
	}

	/**
	 * Enqueues assets per the decision tree.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::is_eligible() ) {
			return;
		}

		$settings = self::$settings;
		$payload  = Payload::get( $settings );
		$count    = (int) $payload['count'];

		if ( 'hybrid' === $settings['render_mode'] ) {
			Assets::enqueue_bootstrap();
		}
		if ( $count > 0 ) {
			self::enqueue_bar_assets( $settings );
		}
	}

	/**
	 * Enqueues the style (with the body height variable) and, when needed, the interactive script.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public static function enqueue_bar_assets( array $settings ): void {
		Assets::enqueue_style();
		wp_add_inline_style( 'hprnb-bar', sprintf( 'body.hprnb-reserve{--hprnb-height:%1$dpx;--hprnb-m-height:%2$dpx}', Renderer::profile_height( $settings, 'd' ), Renderer::profile_height( $settings, 'm' ) ) );
		if ( Renderer::needs_interactive_js( $settings ) ) {
			Assets::enqueue_bar_script();
		}
	}

	/**
	 * Prints the anti-flash micro-script when dismissal is remembered.
	 *
	 * @return void
	 */
	public static function head(): void {
		self::prepare();
		$settings = self::$settings;

		if ( empty( $settings['remember_dismiss'] ) || empty( $settings['close_button'] ) ) {
			return;
		}
		if ( ! self::is_eligible() && ! self::shortcode_expected() ) {
			return;
		}

		$payload = Payload::get( $settings );
		if ( 'hybrid' === $settings['render_mode'] || (int) $payload['count'] > 0 ) {
			Assets::print_dismiss_script();
		}
	}

	/**
	 * Adds `hprnb-reserve` when a bar will be server-rendered in reserve layout.
	 *
	 * @param mixed $classes Body classes.
	 * @return mixed
	 */
	public static function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}
		self::prepare();
		$settings = self::$settings;

		if ( 'reserve' !== $settings['layout_mode'] ) {
			return $classes;
		}
		if ( ! self::is_eligible() && ! self::shortcode_expected() ) {
			return $classes;
		}

		$payload = Payload::get( $settings );
		if ( (int) $payload['count'] > 0 && ! in_array( 'hprnb-reserve', $classes, true ) ) {
			$classes[] = 'hprnb-reserve';
		}
		return $classes;
	}

	/**
	 * Renders the root in the footer per the decision tree.
	 *
	 * @return void
	 */
	public static function footer(): void {
		if ( ! self::is_eligible() || self::$root_claimed ) {
			return;
		}

		$settings = self::$settings;
		$payload  = Payload::get( $settings );

		if ( 'php' === $settings['render_mode'] && (int) $payload['count'] < 1 ) {
			return;
		}

		self::$root_claimed = true;
		echo Renderer::root( $payload, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup built and escaped by the Renderer.
	}

	/**
	 * Whether the shortcode is expected to render on the current singular content.
	 *
	 * @return bool
	 */
	public static function shortcode_expected(): bool {
		$settings = self::$settings ?? Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['shortcode_enabled'] ) || ! Visibility::device_enabled( $settings ) ) {
			return false;
		}
		if ( Visibility::is_absolute_exclusion() || ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, Shortcode::TAG );
	}

	/**
	 * Clears static state (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$settings     = null;
		self::$eligible     = false;
		self::$prepared     = false;
		self::$root_claimed = false;
	}
}
