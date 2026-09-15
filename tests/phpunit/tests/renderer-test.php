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

		$this->assertStringStartsWith( '<aside class="hprnb-bar hprnb-bar--label-start hprnb-bar--reserve hprnb-bar--ticker-marquee"', $html );
		$this->assertStringContainsString( 'role="region"', $html );
		$this->assertStringContainsString( 'aria-label="Latest news"', $html );
		$this->assertStringContainsString( 'aria-live="off"', $html );
		$this->assertStringContainsString( 'dir="auto"', $html );
		$this->assertStringContainsString( '<p class="hprnb-bar__label"><span class="hprnb-bar__label-text">EN CONTINU</span></p>', $html );
		$this->assertStringContainsString( '<ul class="hprnb-bar__list">', $html );
		$this->assertStringContainsString( '<li class="hprnb-bar__item">', $html );
		$this->assertStringContainsString( '<a class="hprnb-bar__link" href="https://example.org/hello/">', $html );
		$this->assertStringContainsString( '<span class="hprnb-bar__title">Hello</span>', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__thumb', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__time', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__sep', $html );
		// v2 defaults: desktop marquee at 30 px/s, mobile rotation, pause on hover, close button (24 h memory).
		$this->assertStringContainsString( 'data-hprnb-ticker="marquee" data-hprnb-ticker-mobile="rotate" data-hprnb-speed="30" data-hprnb-interval="5000" data-hprnb-hover="1" data-hprnb-remember="1" data-hprnb-dismiss-hours="24"', $html );
		$this->assertStringContainsString( 'data-hprnb-label-expand="Show the latest news"', $html );
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $html );
		$this->assertStringContainsString( 'hprnb-bar__btn--close', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--prev', $html );
		$this->assertSame( 2, substr_count( $html, '<button' ) );
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
				'desktop_show_thumbnail' => true,
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
		$marquee = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => true, 'ticker_mode' => 'marquee', 'pause_on_hover' => true, 'mobile_ticker_mode' => 'inherit', 'close_button' => false ) ) );
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

		$off = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_mode' => 'marquee', 'ticker_enabled' => false, 'mobile_ticker_mode' => 'inherit', 'close_button' => false ) ) );
		$this->assertStringContainsString( 'data-hprnb-ticker="none" data-hprnb-ticker-mobile="none"', $off );
		$this->assertStringNotContainsString( '<button', $off );

		// Desktop static + mobile manual: previous / next only; desktop manual + mobile rotate: all three.
		$mixed = Renderer::bar( array( $this->item() ), $this->with_settings( array( 'ticker_enabled' => false, 'mobile_ticker_mode' => 'manual' ) ) );
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

		$this->assertStringStartsWith( '<div id="hprnb-root" class="hprnb-root hprnb-device-all hprnb-root--reserve hprnb-root--align hprnb-bar--sep hprnb-bar--sep-loop hprnb-root--d-inline hprnb-root--d-label-pill hprnb-root--d-dot hprnb-root--m-flow hprnb-root--m-label-pill hprnb-root--m-dot hprnb-root--m-collapse hprnb-root--edge hprnb-root--m-ctrl-col hprnb-root--m-pulse-always" data-hprnb-generated="1757600000" data-hprnb-stale="180" data-hprnb-layout="reserve" data-hprnb-empty="0" data-hprnb-desktop="', $root );
		$this->assertStringContainsString( esc_attr( '{"layout":"inline","lines":1,"counter":false,"progress":false,"place":"fixed","collapse":false,"trigger":"scroll","after":120}' ), $root );
		$this->assertStringContainsString( esc_attr( '{"layout":"flow","lines":2,"counter":false,"progress":true,"place":"fixed","swipe":true,"collapse":true,"peek":"headline","deep":true,"kbd":true,"pause":true,"close":true,"trigger":"scroll","after":120}' ), $root );
		$this->assertStringContainsString( esc_attr( '{"mode":"immediate","value":400}' ), $root, 'The bar shows up with the page by default.' );
		$this->assertStringContainsString( 'data-hprnb-endpoint="' . esc_url( rest_url( 'hprnb/v1/items' ) ) . '"', $root );
		$this->assertStringContainsString( 'data-hprnb-css="', $root );
		$this->assertStringContainsString( 'hprnb-bar.min.css?ver=2.4.1"', $root );
		$this->assertStringContainsString( 'data-hprnb-js', $root, 'The default mobile presentation (rotate, flow card) needs the interactive script.' );
		$this->assertStringContainsString( 'style="--hprnb-bg:#1B1C20;--hprnb-fg:#F5F5F5;--hprnb-label-bg:#CE3029;--hprnb-label-fg:#FFFFFF;--hprnb-hover:#FFFFFF;--hprnb-accent:#CE3029;--hprnb-font-size:15px;--hprnb-height:40px;--hprnb-d-lines:1;--hprnb-max:1230px;--hprnb-gutter:15px;--hprnb-z:99990;--hprnb-sep:&#039;•&#039;;--hprnb-m-bg:#1B1C20;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#CE3029;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:16px;--hprnb-m-height:76px;--hprnb-m-lines:2;--hprnb-m-line:26px;--hprnb-m-pad:12px;--hprnb-peek:40px;--hprnb-m-ctrls:1;--hprnb-d-thumb:32px;--hprnb-m-thumb:48px;--hprnb-m-card-thumb:140px;--hprnb-m-card-thumb-h:88px;--hprnb-m-card-lines:3"', $root );
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
		$this->assertStringContainsString( 'hprnb-bar.min.js?ver=2.4.1"', $root );
		$this->assertStringContainsString( 'hprnb-hide-desktop', $root );

		add_filter( 'hprnb_stale_threshold', static fn() => 900 );
		$this->assertStringContainsString( 'data-hprnb-stale="900"', Renderer::root( $payload, Settings::get() ) );
	}

	public function test_needs_interactive_js() {
		$this->assertTrue( Renderer::needs_interactive_js( Settings::defaults() ), 'Default mobile presentation (flow card + rotate).' );
		$plain = array_merge( Settings::defaults(), array( 'mobile_ticker_mode' => 'static', 'mobile_layout' => 'inline', 'ticker_enabled' => false, 'close_button' => false, 'mobile_kbd_hide' => false ) );
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
		$this->assertSame( 'marquee', Renderer::desktop_ticker( $settings ), 'v2 default: the desktop bar scrolls.' );
		$this->assertSame( 'rotate', Renderer::mobile_ticker( $settings ) );
		$this->assertSame( 'marquee', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'inherit' ) ) ) );
		$this->assertSame( 'none', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'inherit', 'ticker_enabled' => false ) ) ) );
		$this->assertSame( 'none', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'static' ) ) ) );
		$this->assertSame( 'manual', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'manual' ) ) ) );

		// Heights. Desktop: lines × ceil(font × 1.3) + 12, never below bar_height (40). Mobile flow card:
		// max(mobile_bar_height, lines × round(font × 1.625) + 12); the collapsed strip is pad + line + 2.
		$this->assertSame( 40, Renderer::profile_height( $settings, 'd' ) );
		$this->assertSame( 76, Renderer::profile_height( $settings, 'm' ), 'Flow card: 12 + 2 × 26 + 12.' );
		$this->assertSame( array( 'line' => 26, 'height' => 76, 'pad' => 12, 'peek' => 40 ), Renderer::flow_metrics( $settings ) );
		$this->assertSame( array( 'line' => 26, 'height' => 64, 'pad' => 6, 'peek' => 34 ), Renderer::flow_metrics( array_merge( $settings, array( 'mobile_bar_height' => 64 ) ) ) );
		$this->assertSame( array( 'line' => 26, 'height' => 96, 'pad' => 22, 'peek' => 50 ), Renderer::flow_metrics( array_merge( $settings, array( 'mobile_bar_height' => 96 ) ) ) );
		$this->assertSame( 40, Renderer::peek_height( $settings ) );
		$this->assertSame( 36, Renderer::peek_height( array_merge( $settings, array( 'mobile_layout' => 'stacked' ) ) ), 'Stacked: the 22px label row and its padding.' );
		$this->assertSame( 54, Renderer::profile_height( array_merge( $settings, array( 'mobile_layout' => 'inline' ) ), 'm' ), 'Inline, two 16px lines.' );
		$this->assertSame( 54, Renderer::peek_height( array_merge( $settings, array( 'mobile_layout' => 'inline' ) ) ), 'Inline never collapses: the strip is the bar.' );
		$this->assertSame( 40, Renderer::profile_height( array_merge( $settings, array( 'mobile_layout' => 'inline', 'mobile_lines' => 1 ) ), 'm' ) );
		$this->assertSame( 78, Renderer::profile_height( array_merge( $settings, array( 'desktop_layout' => 'stacked', 'desktop_lines' => 2, 'ticker_enabled' => false ) ), 'd' ), '22 + 4 + 2 × 20 + 12.' );
		$this->assertSame( 56, Renderer::profile_height( array_merge( $settings, array( 'bar_height' => 56, 'desktop_lines' => 2, 'ticker_enabled' => false ) ), 'd' ), 'Minimum height above the computed one (2 × 20 + 12 = 52 < 56).' );
		$this->assertSame( 40, Renderer::profile_height( array_merge( $settings, array( 'mobile_ticker_mode' => 'marquee', 'mobile_layout' => 'inline', 'mobile_lines' => 4 ) ), 'm' ), 'Marquee is always one line.' );
		$this->assertSame( 1, Renderer::profile_lines( array_merge( $settings, array( 'desktop_lines' => 3 ) ), 'd' ), 'Marquee (default) is always one line.' );
		$this->assertSame( 3, Renderer::profile_lines( array_merge( $settings, array( 'desktop_lines' => 3, 'ticker_enabled' => false ) ), 'd' ) );
		$this->assertSame( 'stacked', Renderer::profile( array_merge( $settings, array( 'mobile_ticker_mode' => 'static' ) ), 'm' )['layout'], 'The flow card needs the rotation: other modes fall back to the label row.' );
		// Featured image: one switch, one position and one size per profile; shared markup, CSS decides.
		$this->assertFalse( Settings::wants_thumbnails( $settings ) );
		$images = array_merge( $settings, array( 'desktop_show_thumbnail' => true, 'mobile_show_thumbnail' => true, 'mobile_thumb_position' => 'after', 'desktop_thumb_size' => 56, 'ticker_enabled' => false ) );
		$this->assertTrue( Settings::wants_thumbnails( $images ) );
		$this->assertTrue( Renderer::profile( $images, 'd' )['thumb'] );
		$this->assertSame( 'before', Renderer::profile( $images, 'd' )['thumb_position'] );
		$this->assertSame( 'after', Renderer::profile( $images, 'm' )['thumb_position'] );
		$this->assertSame( 56, Renderer::profile( $images, 'd' )['thumb_size'] );
		$this->assertContains( 'hprnb-root--d-thumb', Renderer::root_classes( $images ) );
		$this->assertNotContains( 'hprnb-root--d-thumb-after', Renderer::root_classes( $images ) );
		$this->assertContains( 'hprnb-root--m-thumb-after', Renderer::root_classes( $images ) );
		$this->assertNotContains( 'hprnb-root--m-thumb', Renderer::root_classes( array_merge( $images, array( 'mobile_show_thumbnail' => false ) ) ) );
		$this->assertSame( 64, Renderer::profile_height( $images, 'd' ), 'A 56px image grows the 40px bar (56 + 12 − 4).' );
		$this->assertSame( 76, Renderer::profile_height( $images, 'm' ), 'The flow card keeps its height: the image is clamped by the stylesheet.' );
		$this->assertStringContainsString( '--hprnb-d-thumb:56px;--hprnb-m-thumb:48px;--hprnb-m-card-thumb:140px;--hprnb-m-card-thumb-h:88px;--hprnb-m-card-lines:3', Renderer::root_style( $images ) );

		// Stacked buttons (default) take one column whatever their number; side by side, one each.
		$this->assertSame( 1, Renderer::mobile_controls( $settings ), 'Close above pause: a single column.' );
		$row = array_merge( $settings, array( 'mobile_controls_layout' => 'row' ) );
		$this->assertSame( 2, Renderer::mobile_controls( $row ), 'Pause and close.' );
		$this->assertSame( 3, Renderer::mobile_controls( array_merge( $row, array( 'mobile_ticker_mode' => 'manual' ) ) ) );
		$this->assertSame( 0, Renderer::mobile_controls( array_merge( $row, array( 'mobile_ticker_mode' => 'static', 'close_button' => false ) ) ), 'No button left: the card takes the whole width.' );
		$this->assertSame( 0, Renderer::mobile_controls( array_merge( $settings, array( 'mobile_show_pause' => false, 'mobile_show_close' => false ) ) ), 'Both hidden on mobile.' );
		$this->assertSame( 1, Renderer::mobile_controls( array_merge( $settings, array( 'mobile_show_pause' => false ) ) ), 'Close alone.' );
		$this->assertSame( 0, Renderer::mobile_controls( array_merge( $settings, array( 'mobile_controls_place' => 'outside' ) ) ), 'Floating above the bar.' );
		$this->assertContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $settings ) );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $row ) );

		// Pulsing pill and the two image sub-options of the collapsed strip.
		$this->assertContains( 'hprnb-root--m-pulse-always', Renderer::root_classes( $settings ) );
		$this->assertContains( 'hprnb-root--m-pulse-never', Renderer::root_classes( array_merge( $settings, array( 'mobile_label_pulse' => 'never' ) ) ) );
		$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $settings ), 'No image on mobile: no sub-option.' );
		$with_image = array_merge( $settings, array( 'mobile_show_thumbnail' => true ) );
		$this->assertContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $with_image ), 'The image stays in the strip by default.' );
		$this->assertNotContains( 'hprnb-root--m-label-compact', Renderer::root_classes( $with_image ), 'The full pill keeps its text by default.' );
		$this->assertContains( 'hprnb-root--m-label-compact', Renderer::root_classes( array_merge( $with_image, array( 'mobile_label_compact' => true ) ) ) );
		$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( array_merge( $with_image, array( 'mobile_peek_thumbnail' => false ) ) ) );

		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--align', 'hprnb-bar--sep', 'hprnb-bar--sep-loop', 'hprnb-root--d-inline', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--m-flow', 'hprnb-root--m-label-pill', 'hprnb-root--m-dot', 'hprnb-root--m-collapse', 'hprnb-root--edge', 'hprnb-root--m-ctrl-col', 'hprnb-root--m-pulse-always' ), Renderer::root_classes( $settings ) );
		$inline = array_merge( $settings, array( 'mobile_layout' => 'inline', 'mobile_label_style' => 'hidden', 'mobile_label_dot' => true, 'mobile_custom_colors' => false, 'mobile_hide_on_scroll' => true, 'label_position' => 'start', 'align_container' => false, 'show_separator' => false ) );
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--d-inline', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--m-inline', 'hprnb-root--m-label-hidden', 'hprnb-root--m-wrap', 'hprnb-root--edge', 'hprnb-root--m-ctrl-col', 'hprnb-root--m-pulse-always' ), Renderer::root_classes( $inline ), 'Inline layout never collapses; a hidden label has no dot; label at the start has no -end class.' );
		$this->assertNotContains( 'hprnb-root--m-end', Renderer::root_classes( array_merge( $inline, array( 'label_position' => 'end' ) ) ), 'On a phone the inline label always precedes the headline.' );
		$this->assertContains( 'hprnb-root--d-end', Renderer::root_classes( array_merge( $inline, array( 'label_position' => 'end' ) ) ), 'On desktop the inline label may follow the headline.' );
		$desktop = array_merge( $settings, array( 'desktop_layout' => 'stacked', 'desktop_lines' => 2, 'ticker_enabled' => false, 'mobile_show_separator' => true, 'mobile_lines' => 1, 'mobile_peek' => 'label', 'mobile_custom_colors' => true ) );
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--align', 'hprnb-bar--sep', 'hprnb-bar--sep-loop', 'hprnb-root--d-stacked', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--d-wrap', 'hprnb-root--m-flow', 'hprnb-root--m-label-pill', 'hprnb-root--m-dot', 'hprnb-root--m-sep', 'hprnb-root--m-sep-loop', 'hprnb-root--m-colors', 'hprnb-root--m-collapse', 'hprnb-root--peek-label', 'hprnb-root--edge', 'hprnb-root--m-ctrl-col', 'hprnb-root--m-pulse-always' ), Renderer::root_classes( $desktop ) );

		$this->assertSame( array( 'layout' => 'flow', 'lines' => 2, 'counter' => false, 'progress' => true, 'place' => 'fixed', 'swipe' => true, 'collapse' => true, 'peek' => 'headline', 'deep' => true, 'kbd' => true, 'pause' => true, 'close' => true, 'trigger' => 'scroll', 'after' => 120 ), Renderer::profile_data( $settings, 'm' ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 2, 'counter' => false, 'progress' => true, 'place' => 'fixed', 'swipe' => true, 'collapse' => false, 'peek' => 'headline', 'deep' => false, 'kbd' => true, 'pause' => true, 'close' => true, 'trigger' => 'scroll', 'after' => 120 ), Renderer::profile_data( $inline, 'm' ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 1, 'counter' => false, 'progress' => false, 'place' => 'fixed', 'collapse' => false, 'trigger' => 'scroll', 'after' => 120 ), Renderer::profile_data( $settings, 'd' ), 'Marquee desktop: no counter, no progress.' );
		$rotate = array_merge( $settings, array( 'ticker_mode' => 'rotate', 'desktop_show_counter' => true ) );
		$this->assertSame( array( 'layout' => 'inline', 'lines' => 1, 'counter' => true, 'progress' => true, 'place' => 'fixed', 'collapse' => false, 'trigger' => 'scroll', 'after' => 120 ), Renderer::profile_data( $rotate, 'd' ) );

		$custom = $this->with_settings( array( 'mobile_bg_color' => '#000000', 'mobile_font_size' => 20, 'mobile_lines' => 3, 'mobile_accent_color' => '#0000FF' ) );
		$style  = Renderer::root_style( $custom );
		$this->assertStringContainsString( '--hprnb-m-bg:#000000;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#0000FF;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:20px;--hprnb-m-height:111px;--hprnb-m-lines:3;--hprnb-m-line:33px;--hprnb-m-pad:6px;--hprnb-peek:41px;--hprnb-m-ctrls:1', $style, '3 × 33 + 12 = 111 > 76; pad (111 − 99) / 2 = 6; peek 6 + 33 + 2.' );
	}

	/**
	 * When the bar appears (§4.6), when the mobile strip collapses, and the accent edge.
	 */
	public function test_reveal_collapse_and_edge() {
		$settings = Settings::defaults();

		// Immediate by default: nothing pending, no interactive JS needed for that alone.
		$this->assertSame( array( 'mode' => 'immediate', 'value' => 400 ), Renderer::reveal_data( $settings ) );
		$this->assertNotContains( 'hprnb-root--pending', Renderer::root_classes( $settings ) );
		$this->assertStringContainsString( 'data-hprnb-reveal="{&quot;mode&quot;:&quot;immediate&quot;,&quot;value&quot;:400}"', Renderer::root( Renderer::payload( array(), $settings ), $settings ) );

		// A scroll distance, a share of the page (clamped to 1-100) and the end of the page (90 %).
		$scroll = $this->with_settings( array( 'reveal_mode' => 'scroll', 'reveal_value' => 600 ) );
		$this->assertSame( array( 'mode' => 'scroll', 'value' => 600 ), Renderer::reveal_data( $scroll ) );
		$this->assertContains( 'hprnb-root--pending', Renderer::root_classes( $scroll ) );
		$this->assertTrue( Renderer::needs_interactive_js( $scroll ), 'The threshold is watched by the script.' );
		$percent = $this->with_settings( array( 'reveal_mode' => 'percent', 'reveal_value' => 4000 ) );
		$this->assertSame( array( 'mode' => 'percent', 'value' => 100 ), Renderer::reveal_data( $percent ) );
		$end = $this->with_settings( array( 'reveal_mode' => 'end', 'reveal_value' => 10 ) );
		$this->assertSame( array( 'mode' => 'end', 'value' => 90 ), Renderer::reveal_data( $end ) );
		$this->assertSame( array( 'mode' => 'immediate', 'value' => 400 ), Renderer::reveal_data( array_merge( $settings, array( 'reveal_mode' => 'nonsense' ) ) ), 'Unknown modes fall back.' );

		// The mobile profile carries the collapse trigger, its threshold and the two buttons.
		$mobile = Renderer::profile_data( $settings, 'm' );
		$this->assertSame( 'scroll', $mobile['trigger'] );
		$this->assertSame( 120, $mobile['after'] );
		$this->assertTrue( $mobile['pause'] );
		$this->assertTrue( $mobile['close'] );
		$tuned = Renderer::profile_data( $this->with_settings( array( 'mobile_collapse_mode' => 'immediate', 'mobile_collapse_after' => 300, 'mobile_show_pause' => false ) ), 'm' );
		$this->assertSame( 'immediate', $tuned['trigger'] );
		$this->assertSame( 300, $tuned['after'] );
		$this->assertFalse( $tuned['pause'] );

		// Collapsing off entirely: no class, and the desktop profile never carries these keys.
		$this->assertNotContains( 'hprnb-root--m-collapse', Renderer::root_classes( $this->with_settings( array( 'mobile_hide_on_scroll' => false ) ) ) );
		$desktop = Renderer::profile_data( $settings, 'd' );
		$this->assertFalse( $desktop['collapse'], 'Collapsing from 768px is off by default.' );
		$this->assertSame( 'scroll', $desktop['trigger'] );
		$this->assertArrayNotHasKey( 'swipe', $desktop, 'Swiping stays a phone gesture.' );

		// Accent edge, on by default.
		$this->assertContains( 'hprnb-root--edge', Renderer::root_classes( $settings ) );
		$this->assertNotContains( 'hprnb-root--edge', Renderer::root_classes( $this->with_settings( array( 'accent_edge' => false ) ) ) );

		// Buttons outside the bar.
		$outside = Renderer::root_classes( $this->with_settings( array( 'mobile_controls_place' => 'outside' ) ) );
		$this->assertContains( 'hprnb-root--m-ctrl-out', $outside );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', $outside, 'The floating group is a row of its own.' );
		$this->assertNotContains( 'hprnb-root--m-ctrl-out', Renderer::root_classes( $settings ) );
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

		// Separator off → no class at all, even though separator_after_last defaults to true.
		$off = $this->with_settings( array( 'show_separator' => false ) );
		$root = Renderer::root( $payload, $off );
		$this->assertTrue( $off['separator_after_last'] );
		$this->assertStringNotContainsString( 'hprnb-bar--sep', $root );
		$this->assertSame( array(), Renderer::separator_classes( $off ) );
		$this->assertSame( array( 'hprnb-bar--sep', 'hprnb-bar--sep-loop' ), Renderer::separator_classes( Settings::defaults() ), 'v2 default: separators between and after the headlines.' );

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
