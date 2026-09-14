<?php
/**
 * Front-end orchestration: assets, body class, footer, dismiss script, single root.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Shortcode;

class Frontend_Test extends HPRNB_Test_Case {

	private function enqueue(): void {
		Assets::register_front();
		Frontend::enqueue();
	}

	private function head(): string {
		ob_start();
		Frontend::head();
		return (string) ob_get_clean();
	}

	public function test_hybrid_with_no_item_renders_hidden_root_and_bootstrap_only() {
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$this->assertTrue( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'hprnb-bar', 'enqueued' ) );

		$footer = $this->render_footer();
		$this->assertStringContainsString( '<div id="hprnb-root"', $footer );
		$this->assertStringContainsString( 'data-hprnb-empty="1"', $footer );
		$this->assertStringContainsString( ' hidden>', $footer );
		$this->assertStringNotContainsString( '<aside', $footer );
		$this->assertNotContains( 'hprnb-reserve', Frontend::body_class( array() ) );
	}

	public function test_php_mode_with_no_item_renders_nothing() {
		$this->with_settings( array( 'render_mode' => 'php' ) );
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$this->assertFalse( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertSame( '', $this->render_footer() );
	}

	public function test_hybrid_with_items() {
		$this->create_post_ago( 60, array( 'post_title' => 'Front headline' ) );
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$this->assertTrue( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'hprnb-bar', 'enqueued' ), 'The default mobile presentation needs the interactive script.' );
		$this->assertContains( 'body.hprnb-reserve{--hprnb-height:44px;--hprnb-m-height:80px}', wp_styles()->get_data( 'hprnb-bar', 'after' ) );
		$this->assertSame( 'replace', wp_styles()->get_data( 'hprnb-bar', 'rtl' ) );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'hprnb-bootstrap', 'strategy' ) );

		$footer = $this->render_footer();
		$this->assertStringContainsString( '<div id="hprnb-root"', $footer );
		$this->assertStringContainsString( 'Front headline', $footer );
		$this->assertSame( 1, substr_count( $footer, 'id="hprnb-root"' ) );
		$this->assertContains( 'hprnb-reserve', Frontend::body_class( array( 'home' ) ) );
		$this->assertSame( '', $this->render_footer(), 'A second footer call renders nothing.' );
	}

	public function test_php_mode_with_items() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'render_mode' => 'php', 'ticker_enabled' => true, 'bar_height' => 60, 'mobile_layout' => 'inline' ) );
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$this->assertFalse( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertContains( 'body.hprnb-reserve{--hprnb-height:60px;--hprnb-m-height:60px}', wp_styles()->get_data( 'hprnb-bar', 'after' ) );


		$footer = $this->render_footer();
		$this->assertStringContainsString( '<aside', $footer );
		$this->assertStringNotContainsString( 'data-hprnb-endpoint', $footer );
		$this->with_settings( array( 'mobile_layout' => 'inline', 'mobile_ticker_mode' => 'static' ) );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();
		$this->assertFalse( wp_script_is( 'hprnb-bar', 'enqueued' ), 'No interactive feature at all: no script.' );
	}

	public function test_overlay_layout_has_no_body_class() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'layout_mode' => 'overlay' ) );
		$this->go_to_front( home_url( '/' ) );
		$this->assertNotContains( 'hprnb-reserve', Frontend::body_class( array() ) );
	}

	public function test_not_eligible_renders_nothing_and_runs_no_query() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'enabled' => false ) );
		$this->post_queries = 0;
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$this->assertSame( 0, $this->post_queries );
		$this->assertFalse( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertSame( '', $this->render_footer() );
		$this->assertSame( '', $this->head() );
	}

	public function test_auto_display_off() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'auto_display' => false ) );
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();
		$this->assertFalse( Frontend::is_eligible() );
		$this->assertSame( '', $this->render_footer() );
		$this->assertFalse( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
	}

	public function test_dismiss_script_only_when_remembered() {
		$this->create_post_ago( 60 );
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', $this->head() );

		$this->with_settings( array( 'close_button' => true ) );
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', $this->head() );

		$this->with_settings( array( 'close_button' => true, 'remember_dismiss' => true ) );
		$this->go_to_front( home_url( '/' ) );
		$head = $this->head();
		$this->assertStringContainsString( '<script id="hprnb-dismiss">', $head );
		$this->assertStringContainsString( "localStorage.getItem('hprnb_dismissed_until')", $head );
		$this->assertStringContainsString( "classList.add('hprnb-dismissed')", $head );

		$this->with_settings( array( 'close_button' => true, 'remember_dismiss' => true, 'render_mode' => 'php', 'content_exclude_post_ids' => wp_list_pluck( get_posts( array( 'numberposts' => -1 ) ), 'ID' ) ) );
		$this->go_to_front( home_url( '/' ) );
		$this->assertSame( '', $this->head(), 'PHP mode with no item: no script.' );
	}

	public function test_shortcode_before_footer_prevents_a_second_root() {
		$this->create_post_ago( 60 );
		$this->go_to_front( home_url( '/' ) );
		$this->enqueue();

		$shortcode = do_shortcode( '[' . Shortcode::TAG . ']' );
		$this->assertStringContainsString( 'id="hprnb-root"', $shortcode );
		$this->assertSame( '', do_shortcode( '[' . Shortcode::TAG . ']' ), 'Second shortcode returns nothing.' );
		$this->assertSame( '', $this->render_footer(), 'Footer does not render a second bar.' );
	}
}
