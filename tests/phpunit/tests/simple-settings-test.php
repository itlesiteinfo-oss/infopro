<?php
/**
 * 2.11.0: two mobile designs, the client's defaults, and a settings page that shows what matters.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Settings_Page;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

class Simple_Settings_Test extends HPRNB_Test_Case {

	private function page(): string {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();
		ob_start();
		Settings_Page::render();
		return (string) ob_get_clean();
	}

	public function test_the_new_defaults_are_the_clients_set_up() {
		$d = Settings::defaults();
		$this->assertSame( 'flow_image', $d['mobile_layout'], 'The bar with the article picture.' );
		$this->assertFalse( $d['mobile_show_pause'], 'The cross alone in the tab.' );
		$this->assertSame( 2, $d['mobile_lines'] );
		$this->assertSame( 'reading', $d['mobile_behavior'], 'Folds as soon as the reader scrolls back up.' );
		$this->assertTrue( $d['mobile_peek_thumbnail'], 'The folded strip keeps its picture.' );
		$this->assertSame( 'headline', $d['mobile_peek'] );
		$this->assertSame( 9, HPRNB_SCHEMA_VERSION );
	}

	/**
	 * Schema 7: the three designs taken away move to the bar with the picture. On its own (from a
	 * schema-7 site's point of view, nothing else moves), then with schema 8 on top.
	 */
	public function test_schema_7_moves_the_removed_designs() {
		foreach ( array( 'flow', 'stacked', 'inline' ) as $removed ) {
			$this->assertSame( 'flow_image', Settings::migrate( array( 'mobile_layout' => $removed ), 6 )['mobile_layout'], $removed );
		}
		$this->assertSame( array( 'mobile_layout' => 'card' ), Settings::migrate( array( 'mobile_layout' => 'card' ), HPRNB_SCHEMA_VERSION ), 'Up to date: nothing moves.' );
		$this->assertSame( array(), Settings::migrate( array(), 6 ), 'A fresh install has nothing to move.' );
	}

	/**
	 * Schema 8 (2.12, at the client's request): the bar with the article picture becomes the phone
	 * design of an existing site too, exactly as delivered — and nothing but the design moves.
	 */
	public function test_schema_8_applies_the_image_bar_to_an_existing_site() {
		$old = array_merge(
			Settings::defaults(),
			array(
				'mobile_layout'         => 'card',
				'mobile_lines'          => 3,
				'mobile_show_pause'     => true,
				'mobile_label_style'    => 'strip',
				'mobile_label_dot'      => false,
				'mobile_peek_thumbnail' => false,
				'mobile_behavior'       => 'fold',
				'mobile_bg_color'       => '#000000',
				'label_text'            => 'DIRECT',
				'mobile_show_close'     => false,
			)
		);
		update_option( Settings::OPTION, $old );
		update_option( Settings::SCHEMA_OPTION, '7' );
		Settings::flush();
		Settings::maybe_upgrade();
		$now = Settings::get();

		foreach ( Settings::image_bar_design() as $key => $value ) {
			$this->assertSame( $value, $now[ $key ], $key );
		}
		$this->assertSame( 'flow_image', $now['mobile_layout'] );
		$this->assertFalse( $now['mobile_show_pause'], 'The cross alone in the tab.' );
		$this->assertSame( 'fold', $now['mobile_behavior'], 'The behaviour stays the site\'s.' );
		$this->assertSame( '#000000', $now['mobile_bg_color'], 'So do the colours.' );
		$this->assertSame( 'DIRECT', $now['label_text'], 'And the content.' );
		$this->assertFalse( $now['mobile_show_close'], 'A cross the site switched off stays off.' );
		$this->assertSame( '9', get_option( Settings::SCHEMA_OPTION ) );
		$this->assertContains( 'hprnb-root--m-ctrl-tab', Renderer::root_classes( $now ) );
		$this->assertContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $now ) );

		// Once only: a site that picks the card afterwards keeps it.
		Settings::update( array_merge( $now, array( 'mobile_layout' => 'card' ) ) );
		Settings::maybe_upgrade();
		Settings::flush();
		$this->assertSame( 'card', Settings::get()['mobile_layout'] );

		// The design as delivered is the defaults of a new site.
		foreach ( Settings::image_bar_design() as $key => $value ) {
			$this->assertSame( $value, Settings::defaults()[ $key ], $key );
		}
		$this->assertSame( array(), Settings::migrate( array(), 7 ), 'A fresh install has nothing to move.' );
	}

	public function test_both_designs_keep_their_picture_in_the_folded_strip() {
		foreach ( array( 'flow_image', 'card' ) as $layout ) {
			$settings = $this->with_settings( array( 'mobile_layout' => $layout ) );
			$this->assertContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $settings ), $layout );
			$off = $this->with_settings( array( 'mobile_layout' => $layout, 'mobile_peek_thumbnail' => false ) );
			$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $off ), $layout . ' without the option.' );
		}
	}

	public function test_advanced_settings_are_every_one_a_real_setting() {
		$schema = Settings::schema();
		foreach ( Settings_Page::advanced_fields() as $key ) {
			$this->assertArrayHasKey( $key, $schema, $key );
		}
		// What a site works with never hides behind the switch.
		foreach ( array( 'enabled', 'label_text', 'max_items', 'categories_include', 'display_scope', 'contexts', 'mobile_behavior', 'mobile_reveal_paragraph', 'show_on_mobile', 'mobile_layout', 'mobile_lines', 'mobile_thumb_size', 'mobile_show_pause', 'mobile_show_close', 'close_button', 'desktop_behavior', 'show_on_desktop', 'font_size', 'ticker_mode', 'bg_color', 'text_color', 'label_bg_color', 'label_text_color' ) as $key ) {
			$this->assertNotContains( $key, Settings_Page::advanced_fields(), $key );
		}
	}

	public function test_the_page_marks_what_the_switch_reveals() {
		$html = $this->page();

		// The switch is not a setting: no name, so it never reaches the option.
		$this->assertMatchesRegularExpression( '/<input type="checkbox" id="hprnb-advanced-toggle" \/>/', $html );
		$this->assertStringNotContainsString( 'hprnb_settings[advanced', $html );

		// Rows: the advanced ones carry the class, the essential ones do not.
		$this->assertMatchesRegularExpression( '/<tr class="hprnb-row hprnb-row--[a-z]+ hprnb-row--advanced"[^>]*>\s*<th scope="row"><label for="hprnb-field-mobile-font-size"/', $html );
		$this->assertMatchesRegularExpression( '/<tr class="hprnb-row hprnb-row--choice"[^>]*>\s*<td colspan="2"><fieldset class="hprnb-choices hprnb-choices--designs">/', $html, 'The design choice is essential, and drawn.' );
		// 2.12: each design is shown open and folded.
		$this->assertSame( 1, substr_count( $html, 'hprnb-mock hprnb-mock--image' ) );
		$this->assertSame( 1, substr_count( $html, 'hprnb-mock hprnb-mock--card' ) );
		$this->assertSame( 2, substr_count( $html, 'hprnb-mock__state hprnb-mock__state--folded' ) );
		$this->assertMatchesRegularExpression( '/value="flow_image" checked=\'checked\'> <span class="hprnb-choice__body"><strong class="hprnb-choice__title">Bar with the article picture/', $html, 'Ticked by default.' );

		// Whole cards and a whole tab.
		$this->assertMatchesRegularExpression( '/<section class="hprnb-card hprnb-card--advanced"[^>]*><header class="hprnb-card__header"><h3 class="hprnb-card__title">Headline details/', $html );
		$this->assertStringContainsString( 'data-hprnb-tab="advanced" data-hprnb-advanced="1"', $html );
		$this->assertStringContainsString( 'data-hprnb-panel="advanced" data-hprnb-advanced="1"', $html );
		$this->assertStringNotContainsString( 'data-hprnb-tab="mobile" data-hprnb-advanced', $html );

		// Every input is still rendered once: hiding never drops a value on save.
		preg_match_all( '/<(?:input type="(?:text|number|checkbox)"|select)[^>]*name="(hprnb_settings\[[^"]+\])"/', $html, $m );
		$this->assertSame( array(), array_keys( array_filter( array_count_values( $m[1] ), static fn( $c ) => $c > 1 ) ) );
		$this->assertStringContainsString( 'name="hprnb_settings[mobile_font_size]"', $html );

		// Two designs, the bar with the picture first.
		$this->assertLessThan( strpos( $html, 'name="hprnb_settings[mobile_layout]" value="card"' ), strpos( $html, 'name="hprnb_settings[mobile_layout]" value="flow_image"' ) );
		$this->assertStringNotContainsString( 'name="hprnb_settings[mobile_layout]" value="stacked"', $html );
		$this->assertStringNotContainsString( 'name="hprnb_settings[mobile_layout]" value="flow"', $html );
	}
}
