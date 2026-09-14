<?php
/**
 * Renderer: markup, escaping, options, root, templates.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

class Renderer_Test extends HPRNB_Test_Case {

	private function item( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 1,
				'title'      => 'Hello',
				'url'        => 'https://example.org/hello/',
				'timestamp'  => time() - 3600,
				'datetime'   => '2026-09-11T15:04:00+00:00',
				'date_label' => '11 September 2026 15:04',
				'thumb'      => null,
			),
			$overrides
		);
	}

	public function test_zero_items_render_nothing() {
		$this->assertSame( '', Renderer::bar( array(), Settings::get() ) );
		$payload = Renderer::payload( array(), Settings::get() );
		$this->assertSame( 0, $payload['count'] );
		$this->assertSame( '', $payload['html'] );
		$this->assertSame( HPRNB_VERSION, $payload['version'] );
	}

	public function test_default_markup() {
		$html = Renderer::bar( array( $this->item() ), Settings::get() );

		$this->assertStringStartsWith( '<aside class="hprnb-bar hprnb-bar--label-end hprnb-bar--reserve hprnb-bar--ticker-none"', $html );
		$this->assertStringContainsString( 'role="region"', $html );
		$this->assertStringContainsString( 'aria-label="Latest news"', $html );
		$this->assertStringContainsString( 'aria-live="off"', $html );
		$this->assertStringContainsString( 'dir="auto"', $html );
		$this->assertStringContainsString( '<p class="hprnb-bar__label"><span class="hprnb-bar__label-text">TOUTE L’ACTUALITÉ</span></p>', $html );
		$this->assertStringContainsString( '<ul class="hprnb-bar__list">', $html );
		$this->assertStringContainsString( '<li class="hprnb-bar__item">', $html );
		$this->assertStringContainsString( '<a class="hprnb-bar__link" href="https://example.org/hello/">', $html );
		$this->assertStringContainsString( '<span class="hprnb-bar__title">Hello</span>', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__thumb', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__time', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__sep', $html );
		// Desktop is static but the default mobile mode is rotate: only the Pause/Play button is in the markup.
		$this->assertStringContainsString( 'data-hprnb-ticker="none" data-hprnb-ticker-mobile="rotate"', $html );
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--prev', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--close', $html );
		$this->assertSame( 1, substr_count( $html, '<button' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-live=' ) );
		$this->assertStringEndsWith( '</aside>', trim( $html ) );
	}

	public function test_empty_label_and_start_position() {
		$settings = $this->with_settings( array( 'label_text' => '', 'label_position' => 'start', 'layout_mode' => 'overlay' ) );
		$html     = Renderer::bar( array( $this->item() ), $settings );
		$this->assertStringNotContainsString( 'hprnb-bar__label', $html );
		$this->assertStringContainsString( 'hprnb-bar--label-start', $html );
		$this->assertStringContainsString( 'hprnb-bar--overlay', $html );
	}

	public function test_optional_features_render_only_when_enabled() {
		$settings = $this->with_settings(
			array(
				'show_thumbnail'     => true,
				'show_separator'     => true,
				'separator_char'     => '|',
				'show_relative_time' => true,
			)
		);
		$item = $this->item( array( 'thumb' => array( 'url' => 'https://example.org/t.jpg', 'width' => 150, 'height' => 100 ) ) );
		$html = Renderer::bar( array( $item, $this->item( array( 'id' => 2 ) ) ), $settings );

		$this->assertStringContainsString( '<img class="hprnb-bar__thumb" src="https://example.org/t.jpg" width="150" height="100" loading="lazy" decoding="async" alt="">', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__sep', $html, 'The separator is a CSS pseudo-element, never markup.' );
		$this->assertStringNotContainsString( '>|<', $html );
		$this->assertStringContainsString( '<time class="hprnb-bar__time" datetime="2026-09-11T15:04:00+00:00" data-hprnb-ts="', $html );
		$this->assertStringContainsString( 'data-hprnb-abs="11 September 2026 15:04"', $html );
		$this->assertStringContainsString( 'hour ago</time>', $html );
		$this->assertStringContainsString( 'hprnb-bar--has-thumbs hprnb-bar--has-time', $html );
		$this->assertStringNotContainsString( 'hprnb-bar--has-sep', $html );
		$this->assertSame( 1, substr_count( $html, 'hprnb-bar__thumb' ), 'Second item has no thumb.' );
	}

	public function test_controls() {
		$marquee = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => true, 'ticker_mode' => 'marquee', 'pause_on_hover' => true, 'mobile_ticker_mode' => 'inherit' ) ) );
		$this->assertStringContainsString( 'data-hprnb-ticker="marquee"', $marquee );
		$this->assertStringContainsString( 'data-hprnb-hover="1"', $marquee );
		$this->assertStringContainsString( 'class="hprnb-bar__btn hprnb-bar__btn--toggle" aria-label="Pause" data-hprnb-label-pause="Pause" data-hprnb-label-play="Play"', $marquee );
		$this->assertStringContainsString( 'hprnb-bar__icon--pause', $marquee );
		$this->assertStringContainsString( 'hprnb-bar__icon--play', $marquee );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--prev', $marquee );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--close', $marquee );

		$rotate = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => true, 'ticker_mode' => 'rotate', 'rotate_interval' => 3000, 'mobile_ticker_mode' => 'inherit' ) ) );
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $rotate );
		$this->assertStringContainsString( 'data-hprnb-interval="3000"', $rotate );

		$manual = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => true, 'ticker_mode' => 'manual', 'mobile_ticker_mode' => 'inherit' ) ) );
		$this->assertStringContainsString( 'hprnb-bar__btn--prev" aria-label="Previous" disabled>', $manual );
		$this->assertStringContainsString( 'hprnb-bar__btn--next" aria-label="Next">', $manual );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--toggle', $manual );

		$close = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'close_button' => true, 'remember_dismiss' => true, 'dismiss_duration_hours' => 6, 'mobile_ticker_mode' => 'inherit' ) ) );
		$this->assertStringContainsString( 'hprnb-bar__btn--close" aria-label="Close the news bar">', $close );
		$this->assertStringContainsString( 'data-hprnb-remember="1" data-hprnb-dismiss-hours="6"', $close );
		$this->assertStringContainsString( 'focusable="false"', $close );

		$off = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_mode' => 'marquee', 'ticker_enabled' => false, 'mobile_ticker_mode' => 'inherit' ) ) );
		$this->assertStringContainsString( 'data-hprnb-ticker="none" data-hprnb-ticker-mobile="none"', $off );
		$this->assertStringNotContainsString( '<button', $off );

		// Desktop static + mobile manual: previous / next only; desktop manual + mobile rotate: all three.
		$mixed = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'mobile_ticker_mode' => 'manual' ) ) );
		$this->assertStringContainsString( 'data-hprnb-ticker-mobile="manual"', $mixed );
		$this->assertStringContainsString( 'hprnb-bar__btn--prev', $mixed );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--toggle', $mixed );
		$both = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => true, 'ticker_mode' => 'manual', 'mobile_ticker_mode' => 'rotate' ) ) );
		$this->assertStringContainsString( 'hprnb-bar__btn--prev', $both );
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $both );
	}

	public function test_escaping_of_titles_urls_and_label() {
		$settings = $this->with_settings( array( 'label_text' => 'A & B <script>x</script>', 'show_separator' => true, 'separator_char' => '<b>' ) );
		$item     = $this->item( array( 'title' => 'Tom & "Jerry" <script>alert(1)</script>', 'url' => 'javascript:alert(1)' ) );
		$html     = Renderer::bar( array( $item ), $settings );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Tom &amp; &quot;Jerry&quot; &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '<span class="hprnb-bar__label-text">A &amp; B</span>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_filters_and_actions() {
		$fired = array();
		add_action( 'hprnb_before_bar', static function () use ( &$fired ) { $fired[] = 'before'; } );
		add_action( 'hprnb_after_bar', static function () use ( &$fired ) { $fired[] = 'after'; } );
		add_filter( 'hprnb_bar_html', static fn( $html ) => '<!-- filtered -->' . $html );

		$html = Renderer::bar( array( $this->item() ), Settings::get() );
		$this->assertSame( array( 'before', 'after' ), $fired );
		$this->assertStringStartsWith( '<!-- filtered --><aside', $html );
	}

	public function test_root_markup_hybrid_and_php() {
		$settings = Settings::get();
		$payload  = Renderer::payload( array( $this->item() ), $settings, 1757600000 );
		$root     = Renderer::root( $payload, $settings );

		$this->assertStringStartsWith( '<div id="hprnb-root" class="hprnb-root hprnb-device-all hprnb-root--reserve hprnb-root--d-inline hprnb-root--d-end hprnb-root--d-label-strip hprnb-root--m-stacked hprnb-root--m-label-pill hprnb-root--m-wrap hprnb-root--m-colors hprnb-root--m-collapse" data-hprnb-generated="1757600000" data-hprnb-stale="180" data-hprnb-layout="reserve" data-hprnb-empty="0" data-hprnb-desktop="', $root );
		$this->assertStringContainsString( esc_attr( '{"layout":"inline","lines":1,"counter":false,"progress":false}' ), $root );
		$this->assertStringContainsString( esc_attr( '{"layout":"stacked","lines":2,"counter":true,"progress":true,"swipe":true,"collapse":true}' ), $root );
		$this->assertStringContainsString( 'data-hprnb-endpoint="' . esc_url( rest_url( 'hprnb/v1/items' ) ) . '"', $root );
		$this->assertStringContainsString( 'data-hprnb-css="', $root );
		$this->assertStringContainsString( 'hprnb-bar.min.css?ver=1.3.0"', $root );
		$this->assertStringContainsString( 'data-hprnb-js', $root, 'The default mobile presentation (rotate, stacked) needs the interactive script.' );
		$this->assertStringContainsString( 'style="--hprnb-bg:#B00000;--hprnb-fg:#FFFFFF;--hprnb-label-bg:#8F0000;--hprnb-label-fg:#FFFFFF;--hprnb-hover:#FFFFFF;--hprnb-font-size:14px;--hprnb-height:44px;--hprnb-d-lines:1;--hprnb-z:99990;--hprnb-sep:&#039;•&#039;;--hprnb-m-bg:#141414;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#E11D2A;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:16px;--hprnb-m-height:80px;--hprnb-m-lines:2"', $root );
		$this->assertStringNotContainsString( ' hidden', $root );
		$this->assertStringContainsString( '<aside', $root );
		$this->assertStringEndsWith( '</aside></div>', $root );
		$this->assertSame( 1, substr_count( $root, 'id="hprnb-root"' ) );

		$php = $this->with_settings( array( 'render_mode' => 'php', 'show_on_mobile' => false, 'close_button' => true, 'stale_threshold' => 300 ) );
		$root = Renderer::root( Renderer::payload( array( $this->item() ), $php ), $php );
		$this->assertStringNotContainsString( 'data-hprnb-endpoint', $root );
		$this->assertStringNotContainsString( 'data-hprnb-css', $root );
		$this->assertStringNotContainsString( 'data-hprnb-js', $root );
		$this->assertStringContainsString( 'hprnb-hide-mobile', $root );
		$this->assertStringContainsString( 'data-hprnb-stale="300"', $root );

		$hybrid_js = $this->with_settings( array( 'close_button' => true, 'show_on_desktop' => false ) );
		$root = Renderer::root( Renderer::payload( array( $this->item() ), $hybrid_js ), $hybrid_js );
		$this->assertStringContainsString( 'data-hprnb-js="', $root );
		$this->assertStringContainsString( 'hprnb-bar.min.js?ver=1.3.0"', $root );
		$this->assertStringContainsString( 'hprnb-hide-desktop', $root );

		add_filter( 'hprnb_stale_threshold', static fn() => 900 );
		$this->assertStringContainsString( 'data-hprnb-stale="900"', Renderer::root( $payload, Settings::get() ) );
	}

	public function test_needs_interactive_js() {
		$this->assertTrue( Renderer::needs_interactive_js( Settings::defaults() ), 'Default mobile presentation (stacked + rotate).' );
		$plain = array_merge( Settings::defaults(), array( 'mobile_ticker_mode' => 'static', 'mobile_layout' => 'inline' ) );
		$this->assertFalse( Renderer::needs_interactive_js( $plain ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_layout' => 'stacked' ) ) ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_ticker_mode' => 'rotate' ) ) ) );
		$this->assertFalse( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_ticker_mode' => 'inherit' ) ) ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_ticker_mode' => 'inherit', 'ticker_enabled' => true ) ) ) );
		$this->assertFalse( Renderer::needs_interactive_js( Settings::defaults() ) === false );
		$defaults = $plain;
		$this->assertFalse( Renderer::needs_interactive_js( $defaults ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $defaults, array( 'ticker_enabled' => true ) ) ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $defaults, array( 'close_button' => true ) ) ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $defaults, array( 'show_relative_time' => true ) ) ) );
	}

	public function test_presentation_profiles_on_the_root() {
		$settings = Settings::defaults();
		$this->assertSame( 'rotate', Renderer::mobile_ticker( $settings ) );
		$this->assertSame( 'none', Renderer::desktop_ticker( $settings ) );
		$this->assertSame( 'none', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'inherit' ) ) ) );
		$this->assertSame( 'marquee', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'inherit', 'ticker_enabled' => true ) ) ) );
		$this->assertSame( 'none', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'static', 'ticker_enabled' => true ) ) ) );
		$this->assertSame( 'manual', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'manual' ) ) ) );

		// Heights: label row (22 + 4) + lines × ceil(font × 1.3) + 12, never below bar_height.
		$this->assertSame( 44, Renderer::profile_height( $settings, 'd' ), 'Inline, one 14px line: the 44px minimum wins.' );
		$this->assertSame( 80, Renderer::profile_height( $settings, 'm' ), 'Stacked, two 16px lines.' );
		$this->assertSame( 54, Renderer::profile_height( array_merge( $settings, array( 'mobile_layout' => 'inline' ) ), 'm' ), 'Inline, two 16px lines.' );
		$this->assertSame( 44, Renderer::profile_height( array_merge( $settings, array( 'mobile_layout' => 'inline', 'mobile_lines' => 1 ) ), 'm' ) );
		$this->assertSame( 76, Renderer::profile_height( array_merge( $settings, array( 'desktop_layout' => 'stacked', 'desktop_lines' => 2 ) ), 'd' ) );
		$this->assertSame( 60, Renderer::profile_height( array_merge( $settings, array( 'bar_height' => 60, 'desktop_lines' => 2 ) ), 'd' ), 'Minimum height above the computed one.' );
		$this->assertSame( 44, Renderer::profile_height( array_merge( $settings, array( 'mobile_ticker_mode' => 'marquee', 'mobile_layout' => 'inline', 'mobile_lines' => 4 ) ), 'm' ), 'Marquee is always one line.' );
		$this->assertSame( 1, Renderer::profile_lines( array_merge( $settings, array( 'ticker_enabled' => true, 'ticker_mode' => 'marquee', 'desktop_lines' => 3 ) ), 'd' ) );

		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--d-inline', 'hprnb-root--d-end', 'hprnb-root--d-label-strip', 'hprnb-root--m-stacked', 'hprnb-root--m-label-pill', 'hprnb-root--m-wrap', 'hprnb-root--m-colors', 'hprnb-root--m-collapse' ), Renderer::root_classes( $settings ) );
		$inline = array_merge( $settings, array( 'mobile_layout' => 'inline', 'mobile_label_style' => 'hidden', 'mobile_label_dot' => true, 'mobile_custom_colors' => false, 'mobile_hide_on_scroll' => true, 'label_position' => 'start' ) );
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--d-inline', 'hprnb-root--d-label-strip', 'hprnb-root--m-inline', 'hprnb-root--m-label-hidden', 'hprnb-root--m-wrap' ), Renderer::root_classes( $inline ), 'Inline layout never collapses; a hidden label has no dot; label at the start has no -end class.' );
		$this->assertNotContains( 'hprnb-root--m-end', Renderer::root_classes( array_merge( $inline, array( 'label_position' => 'end' ) ) ), 'On a phone the inline label always precedes the headline.' );
		$desktop = array_merge( $settings, array( 'desktop_layout' => 'stacked', 'desktop_label_style' => 'pill', 'desktop_label_dot' => true, 'desktop_lines' => 2, 'show_separator' => true, 'mobile_show_separator' => true, 'mobile_lines' => 1 ) );
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-bar--sep', 'hprnb-bar--sep-loop', 'hprnb-root--d-stacked', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--d-wrap', 'hprnb-root--m-stacked', 'hprnb-root--m-label-pill', 'hprnb-root--m-sep', 'hprnb-root--m-sep-loop', 'hprnb-root--m-colors', 'hprnb-root--m-collapse' ), Renderer::root_classes( $desktop ) );

		$this->assertSame( array( 'layout' => 'stacked', 'lines' => 2, 'counter' => true, 'progress' => true, 'swipe' => true, 'collapse' => true ), Renderer::profile_data( $settings, 'm' ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 2, 'counter' => true, 'progress' => true, 'swipe' => true, 'collapse' => false ), Renderer::profile_data( $inline, 'm' ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 1, 'counter' => false, 'progress' => false ), Renderer::profile_data( $settings, 'd' ), 'Static desktop: no counter, no progress.' );
		$rotate = array_merge( $settings, array( 'ticker_enabled' => true, 'ticker_mode' => 'rotate', 'desktop_show_counter' => true ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 1, 'counter' => true, 'progress' => true ), Renderer::profile_data( $rotate, 'd' ) );

		$custom = $this->with_settings( array( 'mobile_bg_color' => '#000000', 'mobile_font_size' => 20, 'mobile_lines' => 3, 'mobile_accent_color' => '#0000FF' ) );
		$style  = Renderer::root_style( $custom );
		$this->assertStringContainsString( '--hprnb-m-bg:#000000;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#0000FF;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:20px;--hprnb-m-height:116px;--hprnb-m-lines:3', $style );
	}

	public function test_relative_time_label() {
		$settings = array_merge( Settings::defaults(), array( 'relative_time_max_hours' => 48 ) );
		$now      = 1757600000;
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$this->assertSame( '2 hours ago', Renderer::relative_time_label( $now - 2 * HOUR_IN_SECONDS, $settings, $now ) );
		$this->assertSame( '10 seconds ago', Renderer::relative_time_label( $now - 10, $settings, $now ) );
		$this->assertSame( '1 minute ago', Renderer::relative_time_label( $now - 60, $settings, $now ) );
		$this->assertSame( wp_date( 'Y-m-d H:i', $now - 3 * DAY_IN_SECONDS ), Renderer::relative_time_label( $now - 3 * DAY_IN_SECONDS, $settings, $now ) );
		$this->assertSame( wp_date( 'Y-m-d H:i', $now + 60 ), Renderer::relative_time_label( $now + 60, $settings, $now ), 'Future timestamps fall back to the absolute date.' );
	}

	public function test_theme_template_override() {
		$theme_dir     = get_stylesheet_directory();
		$theme_existed = is_dir( $theme_dir );
		$dir           = trailingslashit( $theme_dir ) . 'horizon-press-news-bar';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/item.php', '<?php defined( "ABSPATH" ) || exit; ?><li class="hprnb-bar__item custom-item"><a class="hprnb-bar__link" href="<?php echo esc_url( $context["item"]["url"] ); ?>"><?php echo esc_html( $context["item"]["title"] ); ?></a></li>' );

		try {
			$this->assertSame( $dir . '/item.php', Renderer::locate_template( 'item' ) );
			$this->assertSame( HPRNB_PATH . 'templates/bar.php', Renderer::locate_template( 'bar' ) );
			$html = Renderer::bar( array( $this->item() ), Settings::get() );
			$this->assertStringContainsString( 'custom-item', $html );
		} finally {
			unlink( $dir . '/item.php' );
			rmdir( $dir );
			if ( ! $theme_existed ) {
				rmdir( $theme_dir );
			}
		}
	}

	public function test_separator_is_css_only_on_the_root() {
		$payload = Renderer::payload( array( $this->item(), $this->item( array( 'id' => 2 ) ) ), Settings::get() );

		// Default: separator off → no class at all, even though separator_after_last defaults to true.
		$root = Renderer::root( $payload, Settings::get() );
		$this->assertTrue( Settings::get()['separator_after_last'] );
		$this->assertStringNotContainsString( 'hprnb-bar--sep', $root );
		$this->assertSame( array(), Renderer::separator_classes( Settings::get() ) );

		// Separator on + after last (default): both classes, no markup.
		$on   = $this->with_settings( array( 'show_separator' => true, 'separator_char' => '|' ) );
		$root = Renderer::root( Renderer::payload( array( $this->item() ), $on ), $on );
		$this->assertMatchesRegularExpression( '/class="hprnb-root [^"]*hprnb-bar--sep hprnb-bar--sep-loop[^"]*"/', $root );
		$this->assertStringContainsString( "--hprnb-sep:&#039;|&#039;", $root );
		$this->assertStringNotContainsString( 'hprnb-bar__sep', $root );
		$this->assertSame( array( 'hprnb-bar--sep', 'hprnb-bar--sep-loop' ), Renderer::separator_classes( $on ) );

		// Separator on, not after last.
		$no_loop = $this->with_settings( array( 'show_separator' => true, 'separator_after_last' => false ) );
		$this->assertSame( array( 'hprnb-bar--sep' ), Renderer::separator_classes( $no_loop ) );
		$this->assertStringNotContainsString( 'hprnb-bar--sep-loop', Renderer::root( $payload, $no_loop ) );

		// after_last is ignored server-side when the separator is off.
		$off = $this->with_settings( array( 'show_separator' => false, 'separator_after_last' => true ) );
		$this->assertSame( array(), Renderer::separator_classes( $off ) );

		// The CSS string is escaped.
		$this->assertSame( "'a\\'b\\\\c'", Renderer::css_string( "a'b\\c" ) );
		$this->assertSame( "'•'", Renderer::css_string( '•' ) );
		$tricky = $this->with_settings( array( 'show_separator' => true, 'separator_char' => "'" ) );
		$this->assertStringContainsString( "--hprnb-sep:&#039;\\&#039;&#039;", Renderer::root( $payload, $tricky ) );
	}

	public function test_icons() {
		$this->assertStringContainsString( 'aria-hidden="true"', Renderer::icon( 'close' ) );
		$this->assertSame( '', Renderer::icon( 'nope' ) );
	}
}
