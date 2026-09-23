<?php
/**
 * The URGENT bar's reach (2.15): its own box first in the side column, its own page types (the front
 * page included, whatever the news bar does there), and the phone design as a second desktop design.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Post_Controls;
use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;
use HorizonPress\NewsBar\Visibility;

class Urgent_Reach_Test extends HPRNB_Test_Case {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
	}

	/**
	 * Flags a fresh published article as urgent.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function urgent_post( string $title = 'Breaking' ): int {
		$id = $this->create_post_ago( 120, array( 'post_title' => $title ) );
		// One invalidation per PHP request: a test runs several "requests" in one, so free the guard first.
		Invalidation::reset_guard();
		Urgent::flag( $id, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();
		return $id;
	}

	/**
	 * The front page as the visitor gets it: enqueues, body classes, footer.
	 *
	 * @return array{footer:string,body:array}
	 */
	private function front(): array {
		$this->go_to_front( home_url( '/' ) );
		Assets::register_front();
		Frontend::enqueue();
		return array(
			'footer' => $this->render_footer(),
			'body'   => Frontend::body_class( array() ),
		);
	}

	public function test_the_new_settings() {
		$defaults = Settings::defaults();
		$this->assertSame( array_fill_keys( Settings::CONTEXT_KEYS, true ), $defaults['urgent_contexts'], 'Every page type, the front page first.' );
		$this->assertSame( 'line', $defaults['urgent_desktop_layout'], 'The one-line design stays the first; the phone design is the second.' );
		$this->assertSame( 'line', Settings::sanitize( array( 'urgent_desktop_layout' => 'card' ) )['urgent_desktop_layout'] );
		$this->assertSame( 'mobile', Settings::sanitize( array( 'urgent_desktop_layout' => 'mobile' ) )['urgent_desktop_layout'] );
		$form = Settings::sanitize_form( array( 'urgent_contexts' => array( 'front_page' => '1' ) ) );
		$this->assertTrue( $form['urgent_contexts']['front_page'] );
		$this->assertFalse( $form['urgent_contexts']['single_post'], 'A box left unticked in the form is off.' );
	}

	public function test_the_urgent_box_comes_first_in_the_side_column() {
		global $wp_meta_boxes;
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between tests.
		Post_Controls::add_meta_box();
		$this->assertArrayHasKey( Post_Controls::URGENT_BOX, $wp_meta_boxes['post']['side']['high'], 'High priority: before the Publish box and any saved order.' );
		$this->assertSame( 'URGENT bar', $wp_meta_boxes['post']['side']['high'][ Post_Controls::URGENT_BOX ]['title'] );
		$this->assertArrayHasKey( 'hprnb-post-controls', $wp_meta_boxes['post']['side']['default'] );
		$this->assertArrayNotHasKey( 'page', array_filter( $wp_meta_boxes, static fn( $screen ) => isset( $screen['side']['high'][ Post_Controls::URGENT_BOX ] ) ), 'Articles only.' );

		// The News Bar box no longer carries the urgent checkbox; the URGENT box has its own nonce.
		$post_id = $this->create_post_ago( 60 );
		ob_start();
		Post_Controls::render( get_post( $post_id ) );
		$news = ob_get_clean();
		$this->assertStringNotContainsString( Post_Controls::FIELD_URGENT, $news );
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post_id ) );
		$box = ob_get_clean();
		$this->assertStringContainsString( 'name="' . Post_Controls::URGENT_NONCE . '"', $box );
		$this->assertStringContainsString( 'name="' . Post_Controls::FIELD_URGENT . '"', $box );

		// Switched off: no box at all.
		$this->with_settings( array( 'urgent_enabled' => false ) );
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between tests.
		Post_Controls::add_meta_box();
		$this->assertFalse( isset( $wp_meta_boxes['post']['side']['high'][ Post_Controls::URGENT_BOX ] ) );
	}

	public function test_each_box_saves_only_with_its_own_nonce() {
		$post_id = $this->create_post_ago( 60 );

		// The News Bar box's nonce alone: the urgent checkbox is not its business.
		$_POST = array(
			Post_Controls::NONCE        => wp_create_nonce( Post_Controls::NONCE ),
			Post_Controls::FIELD_URGENT => '1',
			Settings::META_EXCLUDE      => '1',
		);
		Post_Controls::save( $post_id, get_post( $post_id ) );
		$this->assertSame( 0, Urgent::until( $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, Settings::META_EXCLUDE, true ) );

		// The URGENT box's nonce alone (the News Bar box hidden): the flag is saved, the other two left alone.
		$_POST = array(
			Post_Controls::URGENT_NONCE => wp_create_nonce( Post_Controls::URGENT_NONCE ),
			Post_Controls::FIELD_URGENT => '1',
		);
		Post_Controls::save( $post_id, get_post( $post_id ) );
		$this->assertTrue( Urgent::is_active( $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, Settings::META_EXCLUDE, true ), 'Untouched without its own nonce.' );

		// A forged nonce does nothing.
		$_POST = array(
			Post_Controls::URGENT_NONCE => 'nope',
		);
		Post_Controls::save( $post_id, get_post( $post_id ) );
		$this->assertTrue( Urgent::is_active( $post_id ) );
		$_POST = array();
	}

	public function test_the_front_page_shows_the_urgent_bar_where_the_news_bar_stays_away() {
		$this->with_settings(
			array(
				'display_scope' => 'custom',
				'contexts'      => array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'front_page' => false ) ),
			)
		);
		$this->create_post_ago( 60, array( 'post_title' => 'Plain headline' ) );

		// No urgent article: in hybrid mode an empty root waits for one (the bootstrap can bring it).
		$page = $this->front();
		$this->assertFalse( Frontend::is_eligible(), 'The news bar stays away from the front page.' );
		$this->assertTrue( Frontend::urgent_allowed() );
		$this->assertStringContainsString( 'data-hprnb-show="urgent"', $page['footer'] );
		$this->assertStringContainsString( ' hidden>', $page['footer'] );
		$this->assertStringNotContainsString( 'Plain headline', $page['footer'] );
		$this->assertTrue( wp_script_is( 'hprnb-bootstrap', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertNotContains( 'hprnb-reserve', $page['body'] );

		// An urgent article: the red bar alone, on both devices, at once.
		$this->urgent_post( 'Front page breaking' );
		$page = $this->front();
		$this->assertStringContainsString( 'hprnb-root--urgent', $page['footer'] );
		$this->assertStringContainsString( 'hprnb-device-all', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-count="0" data-hprnb-urgent="1"', $page['footer'] );
		$this->assertStringContainsString( 'Front page breaking', $page['footer'] );
		$this->assertStringNotContainsString( 'Plain headline', $page['footer'], 'The news bar is not sent where it may not show.' );
		$this->assertSame( 1, substr_count( $page['footer'], '<aside ' ) );
		$this->assertTrue( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertContains( 'hprnb-reserve', $page['body'] );

		// PHP render mode without an urgent article: nothing at all on the front page.
		Urgent::unflag(
			(int) get_posts(
				array(
					's'      => 'Front page breaking',
					'fields' => 'ids',
				)
			)[0]
		);
		$this->with_settings(
			array(
				'render_mode'   => 'php',
				'display_scope' => 'custom',
				'contexts'      => array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'front_page' => false ) ),
			)
		);
		$this->assertSame( '', $this->front()['footer'] );
	}

	public function test_the_urgent_bar_has_its_own_page_types() {
		$this->urgent_post( 'Only elsewhere' );
		$this->with_settings( array( 'urgent_contexts' => array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'front_page' => false ) ) ) );
		$this->urgent_post( 'Only elsewhere too' );

		$page = $this->front();
		$this->assertTrue( Frontend::is_eligible() );
		$this->assertFalse( Frontend::urgent_allowed() );
		$this->assertStringContainsString( 'data-hprnb-show="news"', $page['footer'] );
		$this->assertStringNotContainsString( 'hprnb-bar--urgent', $page['footer'] );
		$this->assertStringNotContainsString( 'hprnb-root--urgent', $page['footer'] );
		$this->assertStringContainsString( 'Only elsewhere', $page['footer'], 'The article is still a headline of the news bar.' );

		// A single article, allowed by the urgent list: the red bar is there.
		$post = (int) get_posts(
			array(
				's'      => 'Only elsewhere too',
				'fields' => 'ids',
			)
		)[0];
		$this->go_to_front( get_permalink( $post ) );
		$this->assertTrue( Visibility::urgent_allowed( Settings::get() ) );
		$this->assertStringContainsString( 'hprnb-root--urgent', $this->render_footer() );

		// That page switched off in its News Bar box: no bar at all, not even the red one.
		update_post_meta( $post, Settings::META_HIDE, '1' );
		$this->go_to_front( get_permalink( $post ) );
		$this->assertFalse( Visibility::urgent_allowed( Settings::get() ) );
		$this->assertSame( '', $this->render_footer() );
	}

	public function test_a_news_bar_limited_to_one_device_parks_its_restriction_while_urgent() {
		$this->with_settings( array( 'mobile_contexts' => array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'front_page' => false ) ) ) );
		$this->create_post_ago( 60 );

		$footer = $this->front()['footer'];
		$this->assertStringContainsString( 'class="hprnb-root hprnb-hide-mobile ', $footer, 'Without urgent articles the news bar keeps to desktop.' );

		$this->urgent_post();
		$footer = $this->front()['footer'];
		$this->assertStringContainsString( 'class="hprnb-root hprnb-news-hide-mobile ', $footer, 'The red bar shows on phones too; the script puts the restriction back when it hands over.' );
		$this->assertStringNotContainsString( 'hprnb-hide-mobile', $footer );
	}

	public function test_the_phone_design_as_second_desktop_design() {
		$settings = $this->with_settings( array( 'urgent_desktop_layout' => 'mobile' ) );
		$this->assertSame( 76, Renderer::urgent_height( $settings, 'd' ), 'The phone design is as tall on desktop.' );
		$this->assertContains( 'hprnb-root--u-d-flow', Renderer::root_classes( $settings ) );
		$this->assertContains( 'hprnb-root--u-d-flow', Renderer::root_classes( $settings, true ), 'The preview shows it too.' );
		$this->assertStringContainsString( '--hprnb-u-height:76px;--hprnb-u-m-height:76px', Renderer::root_style( $settings ) );

		$this->urgent_post();
		$this->front();
		$inline = implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) );
		$this->assertStringContainsString( '--hprnb-height:76px;--hprnb-m-height:76px', $inline, 'The page keeps the phone design\'s height on desktop too.' );

		$settings = $this->with_settings( array() );
		$this->assertNotContains( 'hprnb-root--u-d-flow', Renderer::root_classes( $settings ) );
		$this->assertSame( 48, Renderer::urgent_height( $settings, 'd' ), '2.16: the one-line chyron is 48px tall.' );
	}

	public function test_the_root_tells_the_bootstrap_what_the_page_may_show() {
		$settings = $this->with_settings(
			array(
				'urgent_enabled'     => false,
				'close_button'       => false,
				'ticker_enabled'     => false,
				'mobile_ticker_mode' => 'static',
				'mobile_behavior'    => 'always',
				'desktop_behavior'   => 'always',
				'mobile_kbd_hide'    => false,
			)
		);
		$this->assertFalse( Renderer::needs_interactive_js( $settings ) );
		$root = Renderer::root( Renderer::payload( array(), $settings ), $settings );
		$this->assertStringContainsString( 'data-hprnb-urgent="off"', $root, 'Switched off: the bootstrap never asks the server on its account.' );
		$this->assertStringNotContainsString( 'data-hprnb-js=', $root, 'Nothing needs the script.' );

		$settings = $this->with_settings(
			array(
				'close_button'       => false,
				'ticker_enabled'     => false,
				'mobile_ticker_mode' => 'static',
				'mobile_behavior'    => 'always',
				'desktop_behavior'   => 'always',
				'mobile_kbd_hide'    => false,
			)
		);
		$root     = Renderer::root( Renderer::payload( array(), $settings ), $settings );
		$this->assertStringContainsString( 'data-hprnb-urgent="0"', $root );
		$this->assertStringContainsString( 'data-hprnb-js=', $root, 'An urgent article brought in later needs the script that ends it on time.' );
		$this->assertStringNotContainsString( 'data-hprnb-show=', $root, 'Both bars: nothing to say.' );
	}
}
