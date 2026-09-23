<?php
/**
 * Shortcode behaviour.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Shortcode;

class Shortcode_Test extends HPRNB_Test_Case {

	public function test_shortcode_is_registered_by_default() {
		$this->assertTrue( shortcode_exists( Shortcode::TAG ) );
	}

	public function test_renders_root_once() {
		$this->create_post_ago( 60, array( 'post_title' => 'Shortcode headline' ) );
		$this->go_to_front( home_url( '/' ) );

		$first = do_shortcode( '[hprnb_news_bar]' );
		$this->assertStringContainsString( 'id="hprnb-root"', $first );
		$this->assertStringContainsString( 'Shortcode headline', $first );
		$this->assertTrue( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );

		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ) );
		$this->assertTrue( Frontend::root_claimed() );
	}

	public function test_php_mode_without_items_returns_nothing_and_hybrid_returns_hidden_root() {
		$this->with_settings(
			array(
				'render_mode'  => 'php',
				'auto_display' => false,
			)
		);
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ) );
		$this->assertFalse( Frontend::root_claimed(), 'Nothing rendered: the root stays available.' );

		$this->with_settings( array( 'auto_display' => false ) );
		$this->go_to_front( home_url( '/' ) );
		$out = do_shortcode( '[hprnb_news_bar]' );
		$this->assertStringContainsString( 'data-hprnb-empty="1"', $out );
		$this->assertFalse( wp_style_is( 'hprnb-bar', 'enqueued' ) );
	}

	public function test_disabled_plugin_or_devices_or_shortcode() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'enabled' => false ) );
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ) );

		$this->with_settings(
			array(
				'show_on_desktop' => false,
				'show_on_mobile'  => false,
			)
		);
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ) );

		$this->with_settings( array( 'shortcode_enabled' => false ) );
		remove_shortcode( Shortcode::TAG );
		Shortcode::add();
		$this->assertFalse( shortcode_exists( Shortcode::TAG ) );
		$this->assertSame( '[hprnb_news_bar]', do_shortcode( '[hprnb_news_bar]' ) );
		Shortcode::add(); // no-op while disabled.
		$this->with_settings( array() );
		Shortcode::add();
	}

	public function test_shortcode_ignores_context_scope_but_not_absolute_exclusions() {
		$this->create_post_ago( 60 );
		$contexts = array_fill_keys( \HorizonPress\NewsBar\Settings::CONTEXT_KEYS, false );
		$this->with_settings(
			array(
				'display_scope' => 'custom',
				'contexts'      => $contexts,
			)
		);
		$this->go_to_front( home_url( '/' ) );
		$this->assertFalse( Frontend::is_eligible() );
		$this->assertStringContainsString( '<aside', do_shortcode( '[hprnb_news_bar]' ) );

		Frontend::reset();
		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ) );
	}
}
