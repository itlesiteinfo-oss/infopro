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

		$this->assertStringStartsWith( '<aside class="hprnb-bar hprnb-bar--label-start hprnb-bar--reserve hprnb-bar--ticker-marquee hprnb-bar--has-thumbs"', $html );
		$this->assertStringContainsString( 'role="region"', $html );
		$this->assertStringContainsString( 'aria-label="Latest news"', $html );
		$this->assertStringContainsString( 'aria-live="off"', $html );
		$this->assertStringContainsString( 'dir="auto"', $html );
		$this->assertStringContainsString( '<p class="hprnb-bar__label"><span class="hprnb-bar__label-text">EN CONTINU</span></p>', $html );
		$this->assertStringContainsString( '<ul class="hprnb-bar__list">', $html );
		$this->assertStringContainsString( '<li class="hprnb-bar__item" data-hprnb-id="1">', $html );
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
		$settings = $this->with_settings(
			array(
				'label_text'     => '',
				'label_position' => 'start',
				'layout_mode'    => 'overlay',
			)
		);
		$html     = Renderer::bar( array( $this->item() ), $settings );
		$this->assertStringNotContainsString( 'hprnb-bar__label', $html );
		$this->assertStringContainsString( 'hprnb-bar--label-start', $html );
		$this->assertStringContainsString( 'hprnb-bar--overlay', $html );
	}

	public function test_optional_features_render_only_when_enabled() {
		$settings = $this->with_settings(
			array(
				'desktop_show_thumbnail' => true,
				'show_separator'         => true,
				'separator_char'         => '|',
				'show_relative_time'     => true,
			)
		);
		$item     = $this->item(
			array(
				'thumb' => array(
					'url'    => 'https://example.org/t.jpg',
					'width'  => 150,
					'height' => 100,
				),
			)
		);
		$html     = Renderer::bar( array( $item, $this->item( array( 'id' => 2 ) ) ), $settings );

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
		$marquee = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_enabled'     => true,
					'ticker_mode'        => 'marquee',
					'pause_on_hover'     => true,
					'mobile_ticker_mode' => 'inherit',
					'close_button'       => false,
				)
			)
		);
		$this->assertStringContainsString( 'data-hprnb-ticker="marquee"', $marquee );
		$this->assertStringContainsString( 'data-hprnb-hover="1"', $marquee );
		$this->assertStringContainsString( 'class="hprnb-bar__btn hprnb-bar__btn--toggle" aria-label="Pause" data-hprnb-label-pause="Pause" data-hprnb-label-play="Play"', $marquee );
		$this->assertStringContainsString( 'hprnb-bar__icon--pause', $marquee );
		$this->assertStringContainsString( 'hprnb-bar__icon--play', $marquee );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--prev', $marquee );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--close', $marquee );

		$rotate = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_enabled'     => true,
					'ticker_mode'        => 'rotate',
					'rotate_interval'    => 3000,
					'mobile_ticker_mode' => 'inherit',
				)
			)
		);
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $rotate );
		$this->assertStringContainsString( 'data-hprnb-interval="3000"', $rotate );

		$manual = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_enabled'     => true,
					'ticker_mode'        => 'manual',
					'mobile_ticker_mode' => 'inherit',
				)
			)
		);
		$this->assertStringContainsString( 'hprnb-bar__btn--prev" aria-label="Previous" disabled>', $manual );
		$this->assertStringContainsString( 'hprnb-bar__btn--next" aria-label="Next">', $manual );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--toggle', $manual );

		$close = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'close_button'           => true,
					'remember_dismiss'       => true,
					'dismiss_duration_hours' => 6,
					'mobile_ticker_mode'     => 'inherit',
				)
			)
		);
		$this->assertStringContainsString( 'hprnb-bar__btn--close" aria-label="Close the news bar">', $close );
		$this->assertStringContainsString( 'data-hprnb-remember="1" data-hprnb-dismiss-hours="6"', $close );
		$this->assertStringContainsString( 'focusable="false"', $close );

		$off = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_mode'        => 'marquee',
					'ticker_enabled'     => false,
					'mobile_ticker_mode' => 'inherit',
					'close_button'       => false,
				)
			)
		);
		$this->assertStringContainsString( 'data-hprnb-ticker="none" data-hprnb-ticker-mobile="none"', $off );
		$this->assertStringNotContainsString( '<button', $off );

		// Desktop static + mobile manual: previous / next only; desktop manual + mobile rotate: all three.
		$mixed = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_enabled'     => false,
					'mobile_ticker_mode' => 'manual',
				)
			)
		);
		$this->assertStringContainsString( 'data-hprnb-ticker-mobile="manual"', $mixed );
		$this->assertStringContainsString( 'hprnb-bar__btn--prev', $mixed );
		$this->assertStringNotContainsString( 'hprnb-bar__btn--toggle', $mixed );
		$both = Renderer::bar(
			array( $this->item() ),
			$this->with_settings(
				array(
					'ticker_enabled'     => true,
					'ticker_mode'        => 'manual',
					'mobile_ticker_mode' => 'rotate',
				)
			)
		);
		$this->assertStringContainsString( 'hprnb-bar__btn--prev', $both );
		$this->assertStringContainsString( 'hprnb-bar__btn--toggle', $both );
	}

	public function test_escaping_of_titles_urls_and_label() {
		$settings = $this->with_settings(
			array(
				'label_text'     => 'A & B <script>x</script>',
				'show_separator' => true,
				'separator_char' => '<b>',
			)
		);
		$item     = $this->item(
			array(
				'title' => 'Tom & "Jerry" <script>alert(1)</script>',
				'url'   => 'javascript:alert(1)',
			)
		);
		$html     = Renderer::bar( array( $item ), $settings );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Tom &amp; &quot;Jerry&quot; &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '<span class="hprnb-bar__label-text">A &amp; B</span>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_filters_and_actions() {
		$fired = array();
		add_action(
			'hprnb_before_bar',
			static function () use ( &$fired ) {
				$fired[] = 'before';
			}
		);
		add_action(
			'hprnb_after_bar',
			static function () use ( &$fired ) {
				$fired[] = 'after';
			}
		);
		add_filter( 'hprnb_bar_html', static fn( $html ) => '<!-- filtered -->' . $html );

		$html = Renderer::bar( array( $this->item() ), Settings::get() );
		$this->assertSame( array( 'before', 'after' ), $fired );
		$this->assertStringStartsWith( '<!-- filtered --><aside', $html );
	}

	public function test_root_markup_hybrid_and_php() {
		$settings = Settings::get();
		$payload  = Renderer::payload( array( $this->item() ), $settings, 1757600000 );
		$root     = Renderer::root( $payload, $settings );

		$this->assertStringStartsWith( '<div id="hprnb-root" class="hprnb-root hprnb-device-all hprnb-root--reserve hprnb-root--align hprnb-bar--sep hprnb-bar--sep-loop hprnb-root--d-inline hprnb-root--d-label-pill hprnb-root--d-dot hprnb-root--m-flow hprnb-root--m-label-pill hprnb-root--m-dot hprnb-root--m-thumb hprnb-root--m-thumb-after hprnb-root--m-collapse hprnb-root--edge hprnb-root--m-pending hprnb-root--reveal hprnb-root--m-ctrl-tab hprnb-root--m-peek-thumb hprnb-root--m-pulse-appear hprnb-root--font-news hprnb-root--u-bn" data-hprnb-generated="1757600000" data-hprnb-stale="180" data-hprnb-layout="reserve" data-hprnb-empty="0" data-hprnb-count="1" data-hprnb-urgent="0" data-hprnb-post="0" data-hprnb-here="1" data-hprnb-desktop="', $root );
		$this->assertStringContainsString( esc_attr( '{"layout":"inline","lines":1,"counter":false,"progress":false,"place":"fixed","collapse":false,"trigger":"scroll","after":120}' ), $root );
		// 2.11: the bar with the article picture, pause off, continuous reading on mobile.
		$this->assertStringContainsString( esc_attr( '{"layout":"flow","lines":2,"counter":false,"progress":true,"place":"fixed","swipe":true,"collapse":true,"peek":"headline","deep":true,"kbd":true,"pause":false,"close":true,"trigger":"up","after":120}' ), $root );
		$this->assertStringContainsString( esc_attr( '{"d":{"mode":"immediate","value":400},"m":{"mode":"paragraph","value":400,"paragraph":2}}' ), $root, 'Desktop shows up with the page, mobile waits for the paragraph.' );
		$this->assertStringContainsString( 'data-hprnb-endpoint="' . esc_url( rest_url( 'hprnb/v1/items' ) ) . '"', $root );
		$this->assertStringContainsString( 'data-hprnb-css="', $root );
		$this->assertStringContainsString( 'hprnb-bar.min.css?ver=2.18.0"', $root );
		$this->assertStringContainsString( 'data-hprnb-js', $root, 'The default mobile presentation (rotate, flow card) needs the interactive script.' );
		$this->assertStringContainsString( 'style="--hprnb-bg:#1B1C20;--hprnb-fg:#F5F5F5;--hprnb-label-bg:#CE3029;--hprnb-label-fg:#FFFFFF;--hprnb-hover:#FFFFFF;--hprnb-accent:#CE3029;--hprnb-font-size:15px;--hprnb-height:40px;--hprnb-d-lines:1;--hprnb-max:1230px;--hprnb-gutter:15px;--hprnb-z:99990;--hprnb-sep:&#039;•&#039;;--hprnb-m-bg:#1B1C20;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#CE3029;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:16px;--hprnb-m-height:76px;--hprnb-m-lines:2;--hprnb-m-line:26px;--hprnb-m-pad:12px;--hprnb-peek:40px;--hprnb-m-ctrls:0;--hprnb-d-thumb:32px;--hprnb-m-thumb:48px;--hprnb-m-card-thumb:132px;--hprnb-m-card-thumb-h:74px;--hprnb-m-card-lines:2;--hprnb-m-gap:0px;--hprnb-u-bg:#E11D2B;--hprnb-u-fg:#FFFFFF;--hprnb-u-height:48px;--hprnb-u-m-height:76px;--hprnb-u-line:24px;--hprnb-u-pad:14px;--hprnb-u-lines:2;--hprnb-u-fs:17px;--hprnb-u-m-fs:17px"', $root );
		$this->assertStringNotContainsString( ' hidden', $root );
		$this->assertStringContainsString( '<aside', $root );
		$this->assertStringEndsWith( '</aside></div>', $root );
		$this->assertSame( 1, substr_count( $root, 'id="hprnb-root"' ) );

		$php  = $this->with_settings(
			array(
				'render_mode'     => 'php',
				'show_on_mobile'  => false,
				'close_button'    => true,
				'stale_threshold' => 300,
			)
		);
		$root = Renderer::root( Renderer::payload( array( $this->item() ), $php ), $php );
		$this->assertStringNotContainsString( 'data-hprnb-endpoint', $root );
		$this->assertStringNotContainsString( 'data-hprnb-css', $root );
		$this->assertStringNotContainsString( 'data-hprnb-js', $root );
		$this->assertStringContainsString( 'hprnb-hide-mobile', $root );
		$this->assertStringContainsString( 'data-hprnb-stale="300"', $root );

		$hybrid_js = $this->with_settings(
			array(
				'close_button'    => true,
				'show_on_desktop' => false,
			)
		);
		$root      = Renderer::root( Renderer::payload( array( $this->item() ), $hybrid_js ), $hybrid_js );
		$this->assertStringContainsString( 'data-hprnb-js="', $root );
		$this->assertStringContainsString( 'hprnb-bar.min.js?ver=2.18.0"', $root );
		$this->assertStringContainsString( 'hprnb-hide-desktop', $root );

		add_filter( 'hprnb_stale_threshold', static fn() => 900 );
		$this->assertStringContainsString( 'data-hprnb-stale="900"', Renderer::root( $payload, Settings::get() ) );
	}

	public function test_needs_interactive_js() {
		$this->assertTrue( Renderer::needs_interactive_js( Settings::defaults() ), 'Default mobile presentation (flow card + rotate).' );
		// Raw arrays: the renderer still knows the label-row layouts the other ticker modes fall back on.
		$plain = array_merge(
			Settings::defaults(),
			array(
				'mobile_ticker_mode' => 'static',
				'mobile_layout'      => 'inline',
				'ticker_enabled'     => false,
				'close_button'       => false,
				'mobile_kbd_hide'    => false,
				'mobile_reveal_mode' => 'immediate',
				'mobile_next_hide'   => false,
			)
		);
		$this->assertFalse( Renderer::needs_interactive_js( $plain ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_layout' => 'stacked' ) ) ) );
		$this->assertTrue( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_ticker_mode' => 'rotate' ) ) ) );
		$this->assertFalse( Renderer::needs_interactive_js( array_merge( $plain, array( 'mobile_ticker_mode' => 'inherit' ) ) ) );
		$this->assertTrue(
			Renderer::needs_interactive_js(
				array_merge(
					$plain,
					array(
						'mobile_ticker_mode' => 'inherit',
						'ticker_enabled'     => true,
					)
				)
			)
		);
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
		$this->assertSame(
			'none',
			Renderer::mobile_ticker(
				array_merge(
					$settings,
					array(
						'mobile_ticker_mode' => 'inherit',
						'ticker_enabled'     => false,
					)
				)
			)
		);
		$this->assertSame( 'none', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'static' ) ) ) );
		$this->assertSame( 'manual', Renderer::mobile_ticker( array_merge( $settings, array( 'mobile_ticker_mode' => 'manual' ) ) ) );

		// Heights. Desktop: lines × ceil(font × 1.3) + 12, never below bar_height (40). Mobile flow card:
		// max(mobile_bar_height, lines × round(font × 1.625) + 12); the collapsed strip is pad + line + 2.
		$this->assertSame( 40, Renderer::profile_height( $settings, 'd' ) );
		$this->assertSame( 76, Renderer::profile_height( $settings, 'm' ), 'The bar with the article picture, the default since 2.11.0: 12 + 2 × 26 + 12.' );
		$this->assertSame(
			126,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'mobile_layout' => 'card',
						'mobile_lines'  => 3,
					)
				),
				'm'
			),
			'The card on the reference: 12 + 20 + 8 + max(74, 66) + 12.'
		);
		$flow = array_merge(
			$settings,
			array(
				'mobile_layout'     => 'flow',
				'mobile_lines'      => 2,
				'mobile_show_pause' => true,
			)
		);
		$this->assertSame( 76, Renderer::profile_height( $flow, 'm' ), 'Flow card: 12 + 2 × 26 + 12.' );
		$this->assertSame(
			array(
				'line'   => 26,
				'height' => 76,
				'pad'    => 12,
				'peek'   => 40,
			),
			Renderer::flow_metrics( $flow )
		);
		$this->assertSame(
			array(
				'line'   => 26,
				'height' => 64,
				'pad'    => 6,
				'peek'   => 34,
			),
			Renderer::flow_metrics( array_merge( $flow, array( 'mobile_bar_height' => 64 ) ) )
		);
		$this->assertSame(
			array(
				'line'   => 26,
				'height' => 96,
				'pad'    => 22,
				'peek'   => 50,
			),
			Renderer::flow_metrics( array_merge( $flow, array( 'mobile_bar_height' => 96 ) ) )
		);
		$this->assertSame( 40, Renderer::peek_height( $flow ) );
		$this->assertSame( 40, Renderer::peek_height( $settings ), 'The bar with the picture folds like the flowing bar.' );
		$this->assertSame( 36, Renderer::peek_height( array_merge( $settings, array( 'mobile_layout' => 'card' ) ) ), 'The card collapses to one line and its padding.' );
		$this->assertSame( 36, Renderer::peek_height( array_merge( $settings, array( 'mobile_layout' => 'stacked' ) ) ), 'Stacked: the 22px label row and its padding.' );
		$this->assertSame(
			54,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'mobile_layout' => 'inline',
						'mobile_lines'  => 2,
					)
				),
				'm'
			),
			'Inline, two 16px lines.'
		);
		$this->assertSame(
			54,
			Renderer::peek_height(
				array_merge(
					$settings,
					array(
						'mobile_layout' => 'inline',
						'mobile_lines'  => 2,
					)
				)
			),
			'Inline never collapses: the strip is the bar.'
		);
		$this->assertSame(
			40,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'mobile_layout' => 'inline',
						'mobile_lines'  => 1,
					)
				),
				'm'
			)
		);
		$this->assertSame(
			78,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'desktop_layout' => 'stacked',
						'desktop_lines'  => 2,
						'ticker_enabled' => false,
					)
				),
				'd'
			),
			'22 + 4 + 2 × 20 + 12.'
		);
		$this->assertSame(
			56,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'bar_height'     => 56,
						'desktop_lines'  => 2,
						'ticker_enabled' => false,
					)
				),
				'd'
			),
			'Minimum height above the computed one (2 × 20 + 12 = 52 < 56).'
		);
		$this->assertSame(
			40,
			Renderer::profile_height(
				array_merge(
					$settings,
					array(
						'mobile_ticker_mode' => 'marquee',
						'mobile_layout'      => 'inline',
						'mobile_lines'       => 4,
					)
				),
				'm'
			),
			'Marquee is always one line.'
		);
		$this->assertSame( 1, Renderer::profile_lines( array_merge( $settings, array( 'desktop_lines' => 3 ) ), 'd' ), 'Marquee (default) is always one line.' );
		$this->assertSame(
			3,
			Renderer::profile_lines(
				array_merge(
					$settings,
					array(
						'desktop_lines'  => 3,
						'ticker_enabled' => false,
					)
				),
				'd'
			)
		);
		$this->assertSame( 'stacked', Renderer::profile( array_merge( $settings, array( 'mobile_ticker_mode' => 'static' ) ), 'm' )['layout'], 'The flow card needs the rotation: other modes fall back to the label row.' );
		// Featured image: one switch, one position and one size per profile; shared markup, CSS decides.
		$this->assertTrue( Settings::wants_thumbnails( $settings ), 'The card, the default, always carries its picture.' );
		$this->assertFalse( Settings::wants_thumbnails( $flow ) );
		$images = array_merge(
			$settings,
			array(
				'mobile_layout'          => 'flow',
				'mobile_lines'           => 2,
				'desktop_show_thumbnail' => true,
				'mobile_show_thumbnail'  => true,
				'mobile_thumb_position'  => 'after',
				'desktop_thumb_size'     => 56,
				'ticker_enabled'         => false,
			)
		);
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
		$this->assertStringContainsString( '--hprnb-d-thumb:56px;--hprnb-m-thumb:48px;--hprnb-m-card-thumb:132px;--hprnb-m-card-thumb-h:74px;--hprnb-m-card-lines:2;--hprnb-m-gap:0px', Renderer::root_style( $images ) );

		// Stacked buttons (default) take one column whatever their number; side by side, one each.
		$this->assertSame( 0, Renderer::mobile_controls( $settings ), 'The default design places its buttons in a tab: no column.' );
		$this->assertSame( 1, Renderer::mobile_controls( $flow ), 'Close above pause: a single column.' );
		$row = array_merge( $flow, array( 'mobile_controls_layout' => 'row' ) );
		$this->assertSame( 2, Renderer::mobile_controls( $row ), 'Pause and close.' );
		$this->assertSame( 3, Renderer::mobile_controls( array_merge( $row, array( 'mobile_ticker_mode' => 'manual' ) ) ) );
		$this->assertSame(
			0,
			Renderer::mobile_controls(
				array_merge(
					$row,
					array(
						'mobile_ticker_mode' => 'static',
						'close_button'       => false,
					)
				)
			),
			'No button left: the card takes the whole width.'
		);
		$this->assertSame(
			0,
			Renderer::mobile_controls(
				array_merge(
					$flow,
					array(
						'mobile_show_pause' => false,
						'mobile_show_close' => false,
					)
				)
			),
			'Both hidden on mobile.'
		);
		$this->assertSame( 1, Renderer::mobile_controls( array_merge( $flow, array( 'mobile_show_pause' => false ) ) ), 'Close alone.' );
		$this->assertSame( 0, Renderer::mobile_controls( array_merge( $flow, array( 'mobile_controls_place' => 'outside' ) ) ), 'Floating above the bar.' );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $settings ), 'The card has no button column: its tab is above the corner.' );
		$this->assertContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $flow ) );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $row ) );

		// Pulsing pill and the two image sub-options of the collapsed strip.
		$this->assertContains( 'hprnb-root--m-pulse-appear', Renderer::root_classes( $settings ) );
		$this->assertContains( 'hprnb-root--m-pulse-never', Renderer::root_classes( array_merge( $settings, array( 'mobile_label_pulse' => 'never' ) ) ) );
		$this->assertContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $settings ), '2.11: the default design keeps its picture in the folded strip.' );
		$bare = array_merge( $settings, array( 'mobile_layout' => 'flow' ) ); // The label-row fallback, raw.
		$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $bare ), 'No image on mobile: no sub-option.' );
		$with_image = array_merge( $bare, array( 'mobile_show_thumbnail' => true ) );
		$this->assertContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $with_image ), 'The image stays in the strip by default.' );
		$this->assertNotContains( 'hprnb-root--m-label-compact', Renderer::root_classes( $with_image ), 'The full pill keeps its text by default.' );
		$this->assertContains( 'hprnb-root--m-label-compact', Renderer::root_classes( array_merge( $with_image, array( 'mobile_label_compact' => true ) ) ) );
		$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( array_merge( $with_image, array( 'mobile_peek_thumbnail' => false ) ) ) );

		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--align', 'hprnb-bar--sep', 'hprnb-bar--sep-loop', 'hprnb-root--d-inline', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--m-flow', 'hprnb-root--m-label-pill', 'hprnb-root--m-dot', 'hprnb-root--m-collapse', 'hprnb-root--edge', 'hprnb-root--m-pending', 'hprnb-root--reveal', 'hprnb-root--m-ctrl-col', 'hprnb-root--m-pulse-appear', 'hprnb-root--font-news', 'hprnb-root--u-bn' ), Renderer::root_classes( $flow ), 'The label-row fallback; mobile waits for the paragraph by default (2.11).' );
		$inline = array_merge(
			$settings,
			array(
				'mobile_layout'         => 'inline',
				'mobile_lines'          => 2,
				'mobile_label_style'    => 'hidden',
				'mobile_label_dot'      => true,
				'mobile_custom_colors'  => false,
				'mobile_hide_on_scroll' => true,
				'label_position'        => 'start',
				'align_container'       => false,
				'show_separator'        => false,
			)
		);
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--d-inline', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--m-inline', 'hprnb-root--m-label-hidden', 'hprnb-root--m-wrap', 'hprnb-root--edge', 'hprnb-root--m-pending', 'hprnb-root--reveal', 'hprnb-root--m-ctrl-col', 'hprnb-root--m-pulse-appear', 'hprnb-root--font-news', 'hprnb-root--u-bn' ), Renderer::root_classes( $inline ), 'Inline layout never collapses; a hidden label has no dot; label at the start has no -end class.' );
		$this->assertNotContains( 'hprnb-root--m-end', Renderer::root_classes( array_merge( $inline, array( 'label_position' => 'end' ) ) ), 'On a phone the inline label always precedes the headline.' );
		$this->assertContains( 'hprnb-root--d-end', Renderer::root_classes( array_merge( $inline, array( 'label_position' => 'end' ) ) ), 'On desktop the inline label may follow the headline.' );
		$desktop = array_merge(
			$settings,
			array(
				'desktop_layout'        => 'stacked',
				'desktop_lines'         => 2,
				'ticker_enabled'        => false,
				'mobile_show_separator' => true,
				'mobile_lines'          => 1,
				'mobile_peek'           => 'label',
				'mobile_custom_colors'  => true,
			)
		);
		$this->assertSame( array( 'hprnb-root', 'hprnb-device-all', 'hprnb-root--reserve', 'hprnb-root--align', 'hprnb-bar--sep', 'hprnb-bar--sep-loop', 'hprnb-root--d-stacked', 'hprnb-root--d-label-pill', 'hprnb-root--d-dot', 'hprnb-root--d-wrap', 'hprnb-root--m-flow', 'hprnb-root--m-label-pill', 'hprnb-root--m-dot', 'hprnb-root--m-thumb', 'hprnb-root--m-thumb-after', 'hprnb-root--m-sep', 'hprnb-root--m-sep-loop', 'hprnb-root--m-colors', 'hprnb-root--m-collapse', 'hprnb-root--peek-label', 'hprnb-root--edge', 'hprnb-root--m-pending', 'hprnb-root--reveal', 'hprnb-root--m-ctrl-tab', 'hprnb-root--m-peek-thumb', 'hprnb-root--m-pulse-appear', 'hprnb-root--font-news', 'hprnb-root--u-bn' ), Renderer::root_classes( $desktop ) );

		$this->assertSame(
			array(
				'layout'   => 'flow',
				'lines'    => 2,
				'counter'  => false,
				'progress' => true,
				'place'    => 'fixed',
				'swipe'    => true,
				'collapse' => true,
				'peek'     => 'headline',
				'deep'     => true,
				'kbd'      => true,
				'pause'    => false,
				'close'    => true,
				'trigger'  => 'up',
				'after'    => 120,
			),
			Renderer::profile_data( $settings, 'm' )
		);
		$this->assertSame(
			array(
				'layout'   => 'inline',
				'lines'    => 2,
				'counter'  => false,
				'progress' => true,
				'place'    => 'fixed',
				'swipe'    => true,
				'collapse' => false,
				'peek'     => 'headline',
				'deep'     => false,
				'kbd'      => true,
				'pause'    => false,
				'close'    => true,
				'trigger'  => 'up',
				'after'    => 120,
			),
			Renderer::profile_data( $inline, 'm' )
		);
		$this->assertSame(
			array(
				'layout'   => 'inline',
				'lines'    => 1,
				'counter'  => false,
				'progress' => false,
				'place'    => 'fixed',
				'collapse' => false,
				'trigger'  => 'scroll',
				'after'    => 120,
			),
			Renderer::profile_data( $settings, 'd' ),
			'Marquee desktop: no counter, no progress.'
		);
		$rotate = array_merge(
			$settings,
			array(
				'ticker_mode'          => 'rotate',
				'desktop_show_counter' => true,
			)
		);
		$this->assertSame(
			array(
				'layout'   => 'inline',
				'lines'    => 1,
				'counter'  => true,
				'progress' => true,
				'place'    => 'fixed',
				'collapse' => false,
				'trigger'  => 'scroll',
				'after'    => 120,
			),
			Renderer::profile_data( $rotate, 'd' )
		);

		$custom = $this->with_settings(
			array(
				'mobile_layout'       => 'flow',
				'mobile_bg_color'     => '#000000',
				'mobile_font_size'    => 20,
				'mobile_lines'        => 3,
				'mobile_accent_color' => '#0000FF',
			)
		);
		$style  = Renderer::root_style( $custom );
		$this->assertStringContainsString( '--hprnb-m-bg:#000000;--hprnb-m-fg:#F5F5F5;--hprnb-m-accent:#0000FF;--hprnb-m-label-fg:#FFFFFF;--hprnb-m-font-size:20px;--hprnb-m-height:111px;--hprnb-m-lines:3;--hprnb-m-line:33px;--hprnb-m-pad:6px;--hprnb-peek:41px;--hprnb-m-ctrls:0', $style, '3 × 33 + 12 = 111 > 76; pad (111 − 99) / 2 = 6; peek 6 + 33 + 2; the buttons sit in the tab.' );
	}

	/**
	 * When the bar appears (§4.6), when the mobile strip collapses, and the accent edge.
	 */
	public function test_reveal_collapse_and_edge() {
		$settings = Settings::defaults();

		// 2.11 defaults: desktop with the page, mobile at the second-to-last paragraph (Continuous reading).
		$this->assertSame(
			array(
				'd' => array(
					'mode'  => 'immediate',
					'value' => 400,
				),
				'm' => array(
					'mode'      => 'paragraph',
					'value'     => 400,
					'paragraph' => 2,
				),
			),
			Renderer::reveal_data( $settings )
		);
		$this->assertNotContains( 'hprnb-root--d-pending', Renderer::root_classes( $settings ) );
		$this->assertContains( 'hprnb-root--m-pending', Renderer::root_classes( $settings ) );
		$this->assertContains( 'hprnb-root--reveal', Renderer::root_classes( $settings ), 'Mobile slides in.' );
		$this->assertStringContainsString( 'data-hprnb-reveal="{&quot;d&quot;:{&quot;mode&quot;:&quot;immediate&quot;,&quot;value&quot;:400},&quot;m&quot;:{&quot;mode&quot;:&quot;paragraph&quot;,&quot;value&quot;:400,&quot;paragraph&quot;:2}}"', Renderer::root( Renderer::payload( array(), $settings ), $settings ) );
		$immediate = array_merge( $settings, array( 'mobile_reveal_mode' => 'immediate' ) );
		$this->assertNotContains( 'hprnb-root--m-pending', Renderer::root_classes( $immediate ) );
		$this->assertNotContains( 'hprnb-root--reveal', Renderer::root_classes( $immediate ), 'Nothing to slide in.' );

		// 2.8.0: each device decides for itself, and carries its own pending class — so a desktop
		// that appears at once and a mobile that waits share one root.
		$scroll = $this->with_settings(
			array(
				'mobile_reveal_mode'  => 'scroll',
				'mobile_reveal_value' => 600,
			)
		);
		$this->assertSame(
			array(
				'mode'  => 'scroll',
				'value' => 600,
			),
			Renderer::reveal_data( $scroll )['m']
		);
		$this->assertSame(
			array(
				'mode'  => 'immediate',
				'value' => 400,
			),
			Renderer::reveal_data( $scroll )['d'],
			'Desktop untouched.'
		);
		$this->assertSame( 'scroll', Renderer::reveal_mode( $scroll, 'm' ) );
		$this->assertSame( 'immediate', Renderer::reveal_mode( $scroll, 'd' ) );
		$this->assertContains( 'hprnb-root--m-pending', Renderer::root_classes( $scroll ) );
		$this->assertNotContains( 'hprnb-root--d-pending', Renderer::root_classes( $scroll ) );
		$this->assertContains( 'hprnb-root--reveal', Renderer::root_classes( $scroll ) );
		$this->assertTrue( Renderer::needs_interactive_js( $scroll ), 'The threshold is watched by the script.' );
		$both = $this->with_settings(
			array(
				'mobile_reveal_mode'   => 'smart',
				'desktop_reveal_mode'  => 'percent',
				'desktop_reveal_value' => 4000,
			)
		);
		$this->assertContains( 'hprnb-root--m-pending', Renderer::root_classes( $both ) );
		$this->assertContains( 'hprnb-root--d-pending', Renderer::root_classes( $both ) );
		$this->assertSame(
			array(
				'mode'  => 'percent',
				'value' => 100,
			),
			Renderer::reveal_data( $both )['d'],
			'Clamped to 1-100.'
		);
		$this->assertArrayHasKey( 'smart', Renderer::reveal_data( $both ), 'One device on smart is enough to ship the tuning.' );
		$this->assertArrayNotHasKey( 'smart', Renderer::reveal_data( $scroll ) );

		// The admin preview must show the bar whatever the site waits for: never pending there.
		$this->assertNotContains( 'hprnb-root--m-pending', Renderer::root_classes( $both, true ) );
		$this->assertNotContains( 'hprnb-root--d-pending', Renderer::root_classes( $both, true ) );
		$this->assertNotContains( 'hprnb-root--reveal', Renderer::root_classes( $both, true ) );
		$this->assertContains( 'hprnb-root--m-ctrl-tab', Renderer::root_classes( $both, true ), 'Everything else is rendered as usual.' );

		// The entrance owns its transition instead of borrowing the collapse one, which the desktop
		// profile does not switch on by default — the bar used to snap into place.
		$no_fold = $this->with_settings(
			array(
				'desktop_reveal_mode'    => 'smart',
				'desktop_hide_on_scroll' => false,
				'mobile_hide_on_scroll'  => false,
			)
		);
		$classes = Renderer::root_classes( $no_fold );
		$this->assertContains( 'hprnb-root--reveal', $classes, 'Folding off, the slide still belongs to the entrance.' );
		$this->assertNotContains( 'hprnb-root--d-collapse', $classes );
		$this->assertNotContains( 'hprnb-root--m-collapse', $classes );
		$end = $this->with_settings(
			array(
				'desktop_reveal_mode'  => 'end',
				'desktop_reveal_value' => 10,
			)
		);
		$this->assertSame(
			array(
				'mode'  => 'end',
				'value' => 90,
			),
			Renderer::reveal_data( $end )['d']
		);
		$this->assertSame( 'immediate', Renderer::reveal_data( array_merge( $settings, array( 'mobile_reveal_mode' => 'nonsense' ) ) )['m']['mode'], 'Unknown modes fall back.' );

		// "Before the end of the article" carries the paragraph count, from the end, clamped.
		$para = $this->with_settings(
			array(
				'mobile_reveal_mode'      => 'paragraph',
				'mobile_reveal_paragraph' => 2,
			)
		);
		$this->assertSame(
			array(
				'mode'      => 'paragraph',
				'value'     => 400,
				'paragraph' => 2,
			),
			Renderer::reveal_data( $para )['m']
		);
		$this->assertContains( 'hprnb-root--m-pending', Renderer::root_classes( $para ), 'It waits like every other deferred mode.' );
		$this->assertTrue( Renderer::needs_interactive_js( $para ) );
		$this->assertSame(
			30,
			Renderer::reveal_data(
				$this->with_settings(
					array(
						'desktop_reveal_mode'      => 'paragraph',
						'desktop_reveal_paragraph' => 99,
					)
				)
			)['d']['paragraph'],
			'Sanitised to the schema maximum.'
		);
		$this->assertSame(
			1,
			Renderer::reveal_data(
				$this->with_settings(
					array(
						'desktop_reveal_mode'      => 'paragraph',
						'desktop_reveal_paragraph' => 0,
					)
				)
			)['d']['paragraph'],
			'And to the minimum.'
		);
		$this->assertSame( 2, Renderer::reveal_data( array_merge( $settings, array( 'mobile_reveal_mode' => 'paragraph' ) ) )['m']['paragraph'], 'Second-to-last by default.' );
		$this->assertArrayNotHasKey( 'paragraph', Renderer::reveal_data( $scroll )['m'], 'Only the mode that uses it carries it.' );

		// The body selector serves both devices and every body-based trigger, so it travels at the
		// top level whenever it is set — and never as an empty string.
		$with_sel = $this->with_settings(
			array(
				'mobile_reveal_mode' => 'paragraph',
				'smart_selector'     => '.my-article',
			)
		);
		$this->assertSame( '.my-article', Renderer::reveal_data( $with_sel )['sel'] );
		$this->assertArrayNotHasKey( 'sel', Renderer::reveal_data( $para ) );
		$smart_sel = $this->with_settings(
			array(
				'desktop_reveal_mode' => 'smart',
				'smart_selector'      => '.my-article',
			)
		);
		$this->assertSame( '.my-article', Renderer::reveal_data( $smart_sel )['sel'] );
		$this->assertSame( '.my-article', Renderer::reveal_data( $smart_sel )['smart']['sel'], 'The smart block keeps its own copy.' );

		// The "follows the reading" collapse is a trigger value on each profile, and nothing else.
		$follow = $this->with_settings(
			array(
				'desktop_hide_on_scroll' => true,
				'desktop_collapse_mode'  => 'article',
				'mobile_collapse_mode'   => 'article',
			)
		);
		$this->assertSame( 'article', Renderer::profile_data( $follow, 'm' )['trigger'] );
		$this->assertSame( 'article', Renderer::profile_data( $follow, 'd' )['trigger'] );
		$this->assertTrue( Renderer::profile_data( $follow, 'd' )['collapse'] );
		$this->assertContains( 'hprnb-root--d-collapse', Renderer::root_classes( $follow ) );
		$this->assertSame( 'up', Renderer::profile_data( $this->with_settings( array( 'mobile_collapse_mode' => 'sideways' ) ), 'm' )['trigger'], 'An unknown trigger falls back to the default (folds on a scroll up since 2.13).' );

		// The mobile profile carries the collapse trigger, its threshold and the two buttons.
		$mobile = Renderer::profile_data( $settings, 'm' );
		$this->assertSame( 'up', $mobile['trigger'] );
		$this->assertSame( 120, $mobile['after'] );
		$this->assertFalse( $mobile['pause'], 'Pause hidden by default since 2.11: the cross alone in the tab.' );
		$this->assertTrue( $mobile['close'] );
		$tuned = Renderer::profile_data(
			$this->with_settings(
				array(
					'mobile_collapse_mode'  => 'immediate',
					'mobile_collapse_after' => 300,
					'mobile_show_pause'     => false,
				)
			),
			'm'
		);
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
		// Raw: only the label-row fallback still has a place for them (both designs use their tab).
		$outside = Renderer::root_classes(
			array_merge(
				$settings,
				array(
					'mobile_layout'         => 'flow',
					'mobile_controls_place' => 'outside',
				)
			)
		);
		$this->assertContains( 'hprnb-root--m-ctrl-out', $outside );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', $outside, 'The floating group is a row of its own.' );
		$this->assertNotContains( 'hprnb-root--m-ctrl-out', Renderer::root_classes( $settings ) );
	}

	/**
	 * The mobile "discover" card: the label takes the first line beside the picture, the headline
	 * fills what it leaves under it, and the collapsed strip is one line like the flowing card.
	 */
	public function test_card_metrics_follow_the_picture() {
		// The client's reference: a label row, then a 16:9 picture at the start of the line and the
		// headline beside it on three lines. 2.8.0 made it the default; since 2.11.0 it is the second design.
		$card = Settings::sanitize(
			array(
				'mobile_layout' => 'card',
				'mobile_lines'  => 3,
			)
		);
		$this->assertSame( 'card', $card['mobile_layout'] );
		$this->assertSame( 'card', Renderer::profile( $card, 'm' )['layout'] );
		$this->assertTrue( Renderer::profile( $card, 'm' )['thumb'], 'The design always carries its picture.' );
		$this->assertTrue( Settings::wants_thumbnails( $card ), 'So the <img> enters the cached markup.' );

		// 16px profile → an 18px headline on 22px lines; a 132px picture is 74px tall (16:9).
		$metrics = Renderer::card_metrics( $card );
		$this->assertSame( 18, $metrics['font'] );
		$this->assertSame( 22, $metrics['line'] );
		$this->assertSame( 132, $metrics['thumb'] );
		$this->assertSame( 74, $metrics['thumb_height'] );
		$this->assertSame( 3, $metrics['lines'], 'Three lines by default, as on the reference.' );
		$this->assertSame( 66, $metrics['text'], 'Three 22px lines.' );
		$this->assertSame( 126, $metrics['height'], '12 + label row 20 + 8 + max(74, 66) + 12: the picture drives it.' );
		$this->assertSame( 36, $metrics['peek'], 'Collapsed: one line and its padding.' );
		$this->assertSame( 126, Renderer::profile_height( $card, 'm' ) );
		$this->assertSame( 36, Renderer::peek_height( $card ) );
		$this->assertStringContainsString( '--hprnb-m-card-thumb:132px;--hprnb-m-card-thumb-h:74px;--hprnb-m-card-lines:3;--hprnb-m-gap:0px', Renderer::root_style( $card ) );

		// Edge to edge by default, like the reference; floating is an option and carries the gap.
		$this->assertSame( 0, Renderer::mobile_gap( $card ), 'Flush: nothing between the card and the edges.' );
		$this->assertNotContains( 'hprnb-root--m-float', Renderer::root_classes( $card ) );
		$float = $this->with_settings(
			array(
				'mobile_layout'     => 'card',
				'mobile_card_float' => true,
			)
		);
		$this->assertSame( 8, Renderer::mobile_gap( $float ) );
		$this->assertContains( 'hprnb-root--m-float', Renderer::root_classes( $float ) );
		$this->assertStringContainsString( '--hprnb-m-gap:8px', Renderer::root_style( $float ) );
		$this->assertSame(
			0,
			Renderer::mobile_gap(
				$this->with_settings(
					array(
						'mobile_layout'     => 'flow',
						'mobile_card_float' => true,
					)
				)
			),
			'Only the card floats.'
		);
		$this->assertNotContains(
			'hprnb-root--m-float',
			Renderer::root_classes(
				$this->with_settings(
					array(
						'mobile_layout'     => 'flow',
						'mobile_card_float' => true,
					)
				)
			)
		);

		// The picture width is the editor's, 72 to 160: the text drives the height until the picture
		// is taller than three lines (from 120px on).
		foreach ( array(
			72  => 118,
			96  => 118,
			118 => 118,
			120 => 120,
			132 => 126,
			160 => 142,
		) as $width => $height ) {
			$sized = Renderer::card_metrics(
				$this->with_settings(
					array(
						'mobile_layout'     => 'card',
						'mobile_lines'      => 3,
						'mobile_card_thumb' => $width,
					)
				)
			);
			$this->assertSame( $height, $sized['height'], 'A ' . $width . 'px picture.' );
			$this->assertSame( (int) round( $width * 0.5625 ), $sized['thumb_height'], '16:9.' );
		}
		$this->assertSame( 160, Renderer::card_metrics( $this->with_settings( array( 'mobile_card_thumb' => 220 ) ) )['thumb'], 'Clamped to the schema maximum.' );
		$this->assertSame( 72, Renderer::card_metrics( $this->with_settings( array( 'mobile_card_thumb' => 10 ) ) )['thumb'] );

		// The card honours mobile_lines like every other mobile design, up to its own cap of three.
		foreach ( array(
			1 => 1,
			2 => 2,
			3 => 3,
			4 => 3,
		) as $asked => $effective ) {
			$this->assertSame( $effective, Renderer::card_metrics( $this->with_settings( array( 'mobile_lines' => $asked ) ) )['lines'], $asked . ' lines asked.' );
		}
		$two = $this->with_settings(
			array(
				'mobile_lines'      => 2,
				'mobile_card_thumb' => 96,
			)
		);
		$this->assertSame( 106, Renderer::card_metrics( $two )['height'], '12 + 20 + 8 + max(54, 44) + 12: a small picture, two lines.' );
		$three = $this->with_settings(
			array(
				'mobile_lines'      => 3,
				'mobile_card_thumb' => 96,
			)
		);
		$this->assertSame( 118, Renderer::card_metrics( $three )['height'], 'The third line grows it.' );
		$this->assertSame( 36, Renderer::card_metrics( $three )['peek'], 'The collapsed strip is one line whatever the count.' );
		$this->assertStringContainsString( '--hprnb-m-card-lines:3', Renderer::root_style( $three ) );

		// Whatever the font and the count, the reserved height is what card_metrics() computed.
		foreach ( array( 12, 16, 20, 24 ) as $font ) {
			foreach ( array( 1, 2, 3 ) as $lines ) {
				$probe   = $this->with_settings(
					array(
						'mobile_layout'    => 'card',
						'mobile_font_size' => $font,
						'mobile_lines'     => $lines,
					)
				);
				$metrics = Renderer::card_metrics( $probe );
				$this->assertSame( $lines, $metrics['lines'] );
				$this->assertSame( 24 + 20 + 8 + max( $metrics['thumb_height'], $metrics['text'] ), $metrics['height'] );
				$this->assertSame( $metrics['height'], Renderer::profile_height( $probe, 'm' ), $font . 'px / ' . $lines . ' lines.' );
			}
		}

		// Marquee flattens every design to one line, the card included.
		$this->assertSame(
			1,
			Renderer::card_metrics(
				$this->with_settings(
					array(
						'mobile_lines'       => 3,
						'mobile_ticker_mode' => 'marquee',
					)
				)
			)['lines']
		);

		// The design places its own buttons in a tab above the corner: nothing is reserved and
		// neither placement option applies.
		$this->assertSame( 0, Renderer::mobile_controls( $card ) );
		$this->assertSame( 0, Renderer::mobile_controls( array_merge( $card, array( 'mobile_controls_place' => 'outside' ) ) ) );
		$this->assertNotContains( 'hprnb-root--m-ctrl-out', Renderer::root_classes( array_merge( $card, array( 'mobile_controls_place' => 'outside' ) ) ) );
		$this->assertNotContains( 'hprnb-root--m-ctrl-col', Renderer::root_classes( $card ) );
		$this->assertStringContainsString( '--hprnb-m-ctrls:0', Renderer::root_style( $card ) );
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
		$off  = $this->with_settings( array( 'show_separator' => false ) );
		$root = Renderer::root( $payload, $off );
		$this->assertTrue( $off['separator_after_last'] );
		$this->assertStringNotContainsString( 'hprnb-bar--sep', $root );
		$this->assertSame( array(), Renderer::separator_classes( $off ) );
		$this->assertSame( array( 'hprnb-bar--sep', 'hprnb-bar--sep-loop' ), Renderer::separator_classes( Settings::defaults() ), 'v2 default: separators between and after the headlines.' );

		// Separator on + after last (default): both classes, no markup.
		$on   = $this->with_settings(
			array(
				'show_separator' => true,
				'separator_char' => '|',
			)
		);
		$root = Renderer::root( Renderer::payload( array( $this->item() ), $on ), $on );
		$this->assertMatchesRegularExpression( '/class="hprnb-root [^"]*hprnb-bar--sep hprnb-bar--sep-loop[^"]*"/', $root );
		$this->assertStringContainsString( '--hprnb-sep:&#039;|&#039;', $root );
		$this->assertStringNotContainsString( 'hprnb-bar__sep', $root );
		$this->assertSame( array( 'hprnb-bar--sep', 'hprnb-bar--sep-loop' ), Renderer::separator_classes( $on ) );

		// Separator on, not after last.
		$no_loop = $this->with_settings(
			array(
				'show_separator'       => true,
				'separator_after_last' => false,
			)
		);
		$this->assertSame( array( 'hprnb-bar--sep' ), Renderer::separator_classes( $no_loop ) );
		$this->assertStringNotContainsString( 'hprnb-bar--sep-loop', Renderer::root( $payload, $no_loop ) );

		// after_last is ignored server-side when the separator is off.
		$off = $this->with_settings(
			array(
				'show_separator'       => false,
				'separator_after_last' => true,
			)
		);
		$this->assertSame( array(), Renderer::separator_classes( $off ) );

		// The CSS string is escaped.
		$this->assertSame( "'a\\'b\\\\c'", Renderer::css_string( "a'b\\c" ) );
		$this->assertSame( "'•'", Renderer::css_string( '•' ) );
		$tricky = $this->with_settings(
			array(
				'show_separator' => true,
				'separator_char' => "'",
			)
		);
		$this->assertStringContainsString( '--hprnb-sep:&#039;\\&#039;&#039;', Renderer::root( $payload, $tricky ) );
	}

	public function test_icons() {
		$this->assertStringContainsString( 'aria-hidden="true"', Renderer::icon( 'close' ) );
		$this->assertSame( '', Renderer::icon( 'nope' ) );
	}
}
