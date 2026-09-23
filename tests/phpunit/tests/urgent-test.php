<?php
/**
 * Urgent articles (2.14): the red bar that replaces the news bar for a while.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Post_Controls;
use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;

class Urgent_Test extends HPRNB_Test_Case {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Posts the News Bar box the way the browser does, nonce included.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Field names the editor ticked.
	 * @return void
	 */
	private function submit( int $post_id, array $fields ): void {
		$_POST                         = array();
		$_POST[ Post_Controls::NONCE ] = wp_create_nonce( Post_Controls::NONCE );
		$_POST[ Post_Controls::URGENT_NONCE ] = wp_create_nonce( Post_Controls::URGENT_NONCE );
		foreach ( $fields as $key ) {
			$_POST[ $key ] = '1';
		}
		Post_Controls::save( $post_id, get_post( $post_id ) );
		$_POST = array();
		Payload::flush();
		Invalidation::reset_guard();
	}

	public function test_settings_and_keys() {
		$defaults = Settings::defaults();
		$this->assertTrue( $defaults['urgent_enabled'] );
		$this->assertSame( 10, $defaults['urgent_minutes'], 'The client\'s example: ten minutes.' );
		$this->assertSame( 'URGENT', $defaults['urgent_label'] );
		$this->assertSame( '#E11D2B', $defaults['urgent_bg_color'] );
		$this->assertSame( '#FFFFFF', $defaults['urgent_text_color'] );

		$clean = Settings::sanitize( array( 'urgent_minutes' => 5000, 'urgent_label' => '', 'urgent_bg_color' => 'red' ) );
		$this->assertSame( 1440, $clean['urgent_minutes'], 'A day at most.' );
		$this->assertSame( 'URGENT', $clean['urgent_label'], 'An empty label falls back: the red bar always says what it is.' );
		$this->assertSame( '#E11D2B', $clean['urgent_bg_color'] );
		$this->assertSame( 0, Settings::sanitize( array( 'urgent_minutes' => 0 ) )['urgent_minutes'] - 1, 'One minute at least.' );

		foreach ( array( Urgent::META_UNTIL, Urgent::META_SINCE, Urgent::META_ARMED ) as $key ) {
			$this->assertTrue( is_protected_meta( $key, 'post' ), $key );
		}
		$this->assertContains( 'urgent_label', Cache::PAYLOAD_KEYS, 'The label is markup: the cache key follows it.' );
		$this->assertContains( 'urgent_enabled', Cache::PAYLOAD_KEYS );
	}

	public function test_ticking_the_box_on_a_published_article_starts_the_countdown_once() {
		$post_id  = $this->create_post_ago( 3600, array( 'post_title' => 'Breaking' ) );
		$settings = Settings::get();
		$before   = time();

		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$until = Urgent::until( $post_id );
		$since = Urgent::since( $post_id );
		$this->assertGreaterThanOrEqual( $before, $since );
		$this->assertSame( $since + 10 * MINUTE_IN_SECONDS, $until, 'Ten minutes from the save.' );
		$this->assertTrue( Urgent::is_active( $post_id ) );
		$this->assertFalse( Urgent::is_armed( $post_id ) );

		// A typo fixed two minutes in: the countdown is left alone.
		update_post_meta( $post_id, Urgent::META_SINCE, (string) ( $since - 120 ) );
		update_post_meta( $post_id, Urgent::META_UNTIL, (string) ( $until - 120 ) );
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$this->assertSame( $until - 120, Urgent::until( $post_id ), 'Still ticked, still running: nothing restarts.' );

		// "Start over from now": the full time again.
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT, Post_Controls::FIELD_RESTART ) );
		$this->assertGreaterThanOrEqual( $until - 1, Urgent::until( $post_id ), 'Restarted from this save.' );

		// Unticked: over at once.
		$this->submit( $post_id, array() );
		$this->assertSame( 0, Urgent::until( $post_id ) );
		$this->assertSame( 0, Urgent::since( $post_id ) );
		$this->assertFalse( Urgent::is_active( $post_id ) );

		// Expired, ticked again: a new countdown.
		update_post_meta( $post_id, Urgent::META_SINCE, (string) ( $before - 3600 ) );
		update_post_meta( $post_id, Urgent::META_UNTIL, (string) ( $before - 3000 ) );
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$this->assertGreaterThan( $before, Urgent::until( $post_id ) );
		$this->assertSame( 10 * MINUTE_IN_SECONDS, Urgent::until( $post_id ) - Urgent::since( $post_id ) );
		unset( $settings );
	}

	public function test_the_duration_setting_rules_the_countdown() {
		$this->with_settings( array( 'urgent_minutes' => 45 ) );
		$post_id = $this->create_post_ago( 60 );
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$this->assertSame( 45 * MINUTE_IN_SECONDS, Urgent::until( $post_id ) - Urgent::since( $post_id ) );
	}

	public function test_a_scheduled_article_waits_for_its_publication() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$this->assertTrue( Urgent::is_armed( $post_id ) );
		$this->assertSame( 0, Urgent::until( $post_id ), 'No countdown before the publication.' );

		$before = time();
		wp_publish_post( $post_id );
		$this->assertFalse( Urgent::is_armed( $post_id ) );
		$this->assertGreaterThanOrEqual( $before + 10 * MINUTE_IN_SECONDS, Urgent::until( $post_id ), 'The countdown starts at the publication, whoever publishes.' );
		$this->assertTrue( Urgent::is_active( $post_id ) );

		// Unticking a waiting article forgets it.
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->submit( $draft, array( Post_Controls::FIELD_URGENT ) );
		$this->assertTrue( Urgent::is_armed( $draft ) );
		$this->submit( $draft, array() );
		$this->assertFalse( Urgent::is_armed( $draft ) );
	}

	public function test_the_box_is_inert_when_the_feature_is_off() {
		$this->with_settings( array( 'urgent_enabled' => false ) );
		$post_id = $this->create_post_ago( 60 );
		$this->submit( $post_id, array( Post_Controls::FIELD_URGENT ) );
		$this->assertSame( 0, Urgent::until( $post_id ), 'Nothing is written: the box was not shown.' );
		$this->assertSame( array(), Urgent::items( Settings::get() ) );

		ob_start();
		Post_Controls::render_urgent_box( get_post( $post_id ) );
		$box = ob_get_clean();
		$this->assertStringContainsString( 'switched off', $box );
		$this->assertStringNotContainsString( 'name="' . Post_Controls::FIELD_URGENT . '"', $box );
	}

	public function test_the_box_shows_the_state() {
		$post_id = $this->create_post_ago( 60 );
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post_id ) );
		$box = ob_get_clean();
		$this->assertStringContainsString( 'name="' . Post_Controls::FIELD_URGENT . '"', $box );
		$this->assertStringContainsString( 'For 10 minutes after you publish or update', $box );
		$this->assertStringNotContainsString( Post_Controls::FIELD_RESTART, $box, 'No "start over" before it runs.' );

		Urgent::flag( $post_id, Settings::get() );
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post_id ) );
		$box = ob_get_clean();
		$this->assertMatchesRegularExpression( '/name="' . Post_Controls::FIELD_URGENT . '" value="1"\s+checked/', $box );
		$this->assertStringContainsString( 'Urgent until ' . wp_date( (string) get_option( 'time_format' ), Urgent::until( $post_id ) ), $box );
		$this->assertStringContainsString( 'name="' . Post_Controls::FIELD_RESTART . '"', $box );

		update_post_meta( $post_id, Urgent::META_UNTIL, (string) ( time() - 60 ) );
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post_id ) );
		$box = ob_get_clean();
		$this->assertDoesNotMatchRegularExpression( '/name="' . Post_Controls::FIELD_URGENT . '" value="1"\s+checked/', $box, 'Expired: the box is clear again.' );
		$this->assertStringContainsString( 'Was urgent until', $box );
	}

	public function test_urgent_items_are_the_live_ones_newest_flag_first_and_capped() {
		$settings = Settings::get();
		$now      = time();
		$old      = $this->create_post_ago( 7200, array( 'post_title' => 'Old but flagged last' ) );
		$fresh    = $this->create_post_ago( 60, array( 'post_title' => 'Fresh, flagged first' ) );
		$gone     = $this->create_post_ago( 120, array( 'post_title' => 'Expired' ) );
		$draft    = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Draft' ) );
		$this->create_post_ago( 30, array( 'post_title' => 'Never flagged' ) );

		Urgent::flag( $fresh, $settings, $now - 300 );
		Urgent::flag( $old, $settings, $now - 60 );
		Urgent::flag( $gone, $settings, $now - 1200 ); // Ten minutes: over at $now - 600.
		Urgent::flag( $draft, $settings, $now );

		$items = Urgent::items( $settings, $now );
		$this->assertSame( array( 'Old but flagged last', 'Fresh, flagged first' ), wp_list_pluck( $items, 'title' ), 'Newest flag first, whatever the publication date; expired and unpublished left out.' );
		$this->assertSame( $now - 60 + 600, $items[0]['until'] );
		$this->assertSame( $now - 60, $items[0]['since'] );
		$this->assertNull( $items[0]['thumb'], 'No picture on the red bar.' );

		// The cap follows max_items, ten at most.
		$this->assertSame( 10, Urgent::args( Settings::sanitize( array( 'max_items' => 30 ) ) )['posts_per_page'] );
		$this->assertSame( 2, Urgent::args( Settings::sanitize( array( 'max_items' => 2 ) ) )['posts_per_page'] );

		// At $now + 700 only the old one is left; at $now + 600 the fresh one has just gone.
		$this->assertSame( array( 'Old but flagged last' ), wp_list_pluck( Urgent::items( $settings, $now + 400 ), 'title' ) );
		$this->assertSame( array(), Urgent::items( $settings, $now + 600 ) );
	}

	public function test_the_payload_carries_both_bars_and_the_root_puts_the_urgent_one_in_front() {
		$this->create_post_ago( 60, array( 'post_title' => 'Plain headline' ) );
		$urgent = $this->create_post_ago( 120, array( 'post_title' => 'Urgent headline' ) );
		Urgent::flag( $urgent, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();

		$settings = Settings::get();
		$payload  = Payload::get( $settings );
		$this->assertSame( 2, $payload['count'], 'The news bar still lists the urgent article among the headlines.' );
		$this->assertSame( 1, $payload['urgent_count'] );
		$this->assertStringStartsWith( '<aside class="hprnb-bar hprnb-bar--label-start hprnb-bar--reserve hprnb-bar--ticker-marquee hprnb-bar--urgent"', $payload['urgent_html'] );
		$this->assertStringContainsString( 'aria-label="Breaking news"', $payload['urgent_html'] );
		$this->assertStringContainsString( '<span class="hprnb-bar__label-text">URGENT</span><span class="hprnb-bar__label-chevron" aria-hidden="true"><svg', $payload['urgent_html'] );
		$this->assertStringContainsString( 'data-hprnb-since="' . Urgent::since( $urgent ) . '" data-hprnb-until="' . Urgent::until( $urgent ) . '"', $payload['urgent_html'] );
		$this->assertStringContainsString( 'hprnb-bar__btn--close', $payload['urgent_html'], 'Always closable.' );
		$this->assertStringContainsString( 'data-hprnb-remember="0"', $payload['urgent_html'], 'Its own memory, not the news bar\'s.' );
		$this->assertStringNotContainsString( 'hprnb-bar__thumb', $payload['urgent_html'] );
		$this->assertStringNotContainsString( 'hprnb-bar--has-thumbs', $payload['urgent_html'] );
		$this->assertTrue( Cache::is_valid( $payload ) );

		$root = Renderer::root( $payload, $settings );
		$this->assertStringContainsString( ' hprnb-root--urgent', $root );
		$this->assertStringContainsString( 'data-hprnb-urgent="1"', $root );
		$this->assertStringContainsString( 'data-hprnb-count="2"', $root );
		$this->assertStringNotContainsString( 'hprnb-root--m-pending', $root, 'Urgent articles wait for nobody.' );
		$this->assertStringNotContainsString( 'hprnb-root--reveal', $root );
		$this->assertLessThan( strpos( $root, 'Plain headline' ), strpos( $root, 'hprnb-bar--urgent' ), 'The urgent bar comes first, the news bar behind it.' );
		$this->assertSame( 2, substr_count( $root, '<aside ' ) );
		$this->assertStringContainsString( '--hprnb-u-bg:#E11D2B;--hprnb-u-fg:#FFFFFF;--hprnb-u-height:40px;--hprnb-u-m-height:76px;--hprnb-u-line:26px;--hprnb-u-pad:12px;--hprnb-u-lines:2', $root );

		// Without urgent articles: the pending class is back, the attribute says 0, one aside.
		Urgent::unflag( $urgent );
		Payload::flush();
		Invalidation::reset_guard();
		$root = Renderer::root( Payload::get( Settings::get() ), Settings::get() );
		$this->assertStringContainsString( 'hprnb-root--m-pending', $root );
		$this->assertStringContainsString( 'data-hprnb-urgent="0"', $root );
		$this->assertSame( 1, substr_count( $root, '<aside ' ) );
	}

	public function test_an_urgent_article_alone_still_renders_a_root() {
		// The news bar is empty (quiet hours: a two-hour window, a five-hour-old article), the red bar is not.
		$this->with_settings( array( 'window_value' => 2, 'window_unit' => 'hours' ) );
		$urgent = $this->create_post_ago( 5 * HOUR_IN_SECONDS, array( 'post_title' => 'Late breaking' ) );
		Urgent::flag( $urgent, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();

		$payload = Payload::get( Settings::get() );
		$this->assertSame( 0, $payload['count'] );
		$this->assertSame( 1, $payload['urgent_count'] );
		$root = Renderer::root( $payload, Settings::get() );
		$this->assertStringNotContainsString( ' hidden', $root );
		$this->assertStringContainsString( 'data-hprnb-empty="0"', $root );
		$this->assertStringContainsString( 'Late breaking', $root );

		$this->go_to_front( home_url( '/' ) );
		\HorizonPress\NewsBar\Assets::register_front();
		Frontend::enqueue();
		$footer = $this->render_footer();
		$this->assertStringContainsString( 'hprnb-root--urgent', $footer );
		$this->assertTrue( wp_style_is( 'hprnb-bar', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'hprnb-bar', 'enqueued' ), 'The script ends the urgency on time.' );
		$this->assertContains( 'hprnb-reserve', get_body_class() );
		$this->assertNotContains( 'hprnb-m-pending', get_body_class(), 'No wait while urgent articles are in front.' );
		$inline = wp_styles()->get_data( 'hprnb-bar', 'after' );
		$this->assertStringContainsString( '--hprnb-height:40px;--hprnb-m-height:76px', implode( '', (array) $inline ), 'The reserved space is the red bar\'s.' );
	}

	public function test_flagging_and_expiring_invalidate_the_cache() {
		$post_id = $this->create_post_ago( 60 );
		$epoch   = Cache::epoch();

		Invalidation::reset_guard();
		Urgent::flag( $post_id, Settings::get() );
		$this->assertNotSame( $epoch, Cache::epoch(), 'A new flag rotates the epoch.' );

		$epoch = Cache::epoch();
		Invalidation::reset_guard();
		Urgent::unflag( $post_id );
		$this->assertNotSame( $epoch, Cache::epoch(), 'Ending it does too.' );
	}

	public function test_the_rest_body_and_the_preview_carry_the_urgent_bar() {
		$urgent = $this->create_post_ago( 60, array( 'post_title' => 'REST urgent' ) );
		Urgent::flag( $urgent, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();

		$server   = $this->reset_rest_server();
		$response = $server->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) );
		$data     = $response->get_data();
		$this->assertSame( 1, $data['urgent_count'] );
		$this->assertStringContainsString( 'hprnb-bar--urgent', $data['urgent_html'] );
		$this->assertStringContainsString( 'REST urgent', $data['urgent_html'] );

		$request = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$request->set_body_params( array( 'settings' => array( 'urgent_label' => 'FLASH' ), 'urgent' => true ) );
		$data = $server->dispatch( $request )->get_data();
		$this->assertTrue( $data['urgent'] );
		$this->assertSame( 2, $data['count'], 'Two invented breaking stories.' );
		$this->assertStringContainsString( '<span class="hprnb-bar__label-text">FLASH</span>', $data['html'] );
		$this->assertStringContainsString( 'Example: the prime minister', $data['html'] );
		$this->assertStringNotContainsString( 'REST urgent', $data['html'], 'Invented, never the site\'s.' );

		$request = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$request->set_body_params( array( 'settings' => array( 'enabled' => '1' ) ) );
		$data = $server->dispatch( $request )->get_data();
		$this->assertFalse( $data['urgent'] );
		$this->assertStringContainsString( 'REST urgent', $data['html'], 'The news bar preview lists the site\'s headlines.' );
	}

	public function test_render_settings_and_heights() {
		$settings = Settings::sanitize( array( 'mobile_layout' => 'card', 'mobile_lines' => 3, 'mobile_font_size' => 18, 'close_button' => false, 'show_relative_time' => true ) );
		$rendered = Urgent::render_settings( $settings );
		$this->assertSame( 'flow', $rendered['mobile_layout'], 'The flowing shape, never the image bar: no picture on the red bar.' );
		$this->assertTrue( $rendered['close_button'] );
		$this->assertFalse( $rendered['show_relative_time'] );
		$this->assertFalse( $rendered['remember_dismiss'] );
		$this->assertSame( 2, $rendered['mobile_lines'], 'Two lines at most on the red bar.' );

		$metrics = Renderer::urgent_metrics( $settings );
		$this->assertSame( array( 'line' => 29, 'lines' => 2, 'height' => 76, 'pad' => 9 ), $metrics, '18px: line 29, two lines under the 76px floor.' );
		$this->assertSame( 76, Renderer::urgent_height( $settings, 'm' ) );
		$this->assertSame( 40, Renderer::urgent_height( $settings, 'd' ) );
		$this->assertSame( 32, Renderer::urgent_height( Settings::sanitize( array( 'font_size' => 15, 'bar_height' => 20 ) ), 'd' ), 'One line of 15px plus padding when the bar height is lower.' );
	}

	public function test_frontend_urgent_count_reads_a_payload() {
		$this->assertSame( 0, Frontend::urgent_count( array() ) );
		$this->assertSame( 0, Frontend::urgent_count( array( 'urgent_count' => 2, 'urgent_html' => '' ) ) );
		$this->assertSame( 2, Frontend::urgent_count( array( 'urgent_count' => 2, 'urgent_html' => '<aside></aside>' ) ) );
	}
}
