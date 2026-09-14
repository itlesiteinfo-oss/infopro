<?php
/**
 * Transient cache, request memo, epoch and invalidation triggers.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Settings;

class Cache_Test extends HPRNB_Test_Case {

	private int $invalidations = 0;

	public function set_up() {
		parent::set_up();
		$this->invalidations = 0;
		add_action( 'hprnb_cache_invalidated', array( $this, 'count_invalidation' ) );
	}

	public function count_invalidation() {
		++$this->invalidations;
	}

	public function test_empty_result_is_cached() {
		$settings = Settings::get();
		$payload  = Payload::get( $settings );
		$this->assertSame( 0, $payload['count'] );
		$this->assertSame( '', $payload['html'] );

		$stored = get_transient( Cache::key( $settings ) );
		$this->assertSame( $payload, $stored );
	}

	public function test_cache_hit_runs_no_content_query_and_miss_runs_exactly_one() {
		$this->create_post_ago( 60 );
		$settings = Settings::get();

		$this->post_queries = 0;
		Payload::get( $settings );
		$this->assertSame( 1, $this->post_queries, 'A miss runs exactly one content query.' );

		Payload::flush();
		$this->post_queries = 0;
		Payload::get( $settings );
		$this->assertSame( 0, $this->post_queries, 'A transient hit runs no content query.' );

		$this->post_queries = 0;
		Payload::get( $settings );
		Payload::get( $settings );
		$this->assertSame( 0, $this->post_queries, 'The request memo runs no query either.' );
	}

	public function test_key_format_and_hash_inputs() {
		$settings = Settings::get();
		$epoch    = Cache::epoch();
		$key      = Cache::key( $settings );

		$this->assertSame( 'hprnb_bar_' . $epoch . '_' . Cache::hash( $settings ), $key );
		$this->assertLessThanOrEqual( 172, strlen( $key ) );

		$colors = Settings::sanitize( array_merge( $settings, array( 'bg_color' => '#000000', 'font_size' => 20, 'z_index' => 5, 'bar_height' => 60 ) ) );
		$this->assertSame( $key, Cache::key( $colors ), 'Colours, sizes and z-index do not change the payload key.' );

		$label = Settings::sanitize( array_merge( $settings, array( 'label_text' => 'Other' ) ) );
		$this->assertNotSame( $key, Cache::key( $label ) );

		$window = Settings::sanitize( array_merge( $settings, array( 'window_value' => 12 ) ) );
		$this->assertNotSame( $key, Cache::key( $window ) );

		$ticker = Settings::sanitize( array_merge( $settings, array( 'ticker_enabled' => false ) ) );
		$this->assertNotSame( $key, Cache::key( $ticker ) );

		// Mobile: only the ticker mode (it changes the markup) is part of the key.
		$mobile_mode = Settings::sanitize( array_merge( $settings, array( 'mobile_ticker_mode' => 'manual' ) ) );
		$this->assertNotSame( $key, Cache::key( $mobile_mode ) );
		$mobile_rest = Settings::sanitize( array_merge( $settings, array( 'mobile_layout' => 'inline', 'mobile_font_size' => 20, 'mobile_lines' => 4, 'mobile_label_style' => 'hidden', 'mobile_label_dot' => true, 'mobile_show_counter' => false, 'mobile_show_progress' => false, 'mobile_swipe' => false, 'mobile_hide_on_scroll' => false, 'mobile_show_separator' => true, 'mobile_custom_colors' => false, 'mobile_bg_color' => '#000000', 'desktop_layout' => 'stacked', 'desktop_label_style' => 'pill', 'desktop_label_dot' => true, 'desktop_show_counter' => true, 'desktop_lines' => 3, 'desktop_show_progress' => false ) ) );
		$this->assertSame( $key, Cache::key( $mobile_rest ), 'Every other presentation setting lives on the root, outside the cache.' );

		// The separator is a CSS concern on the root: none of its settings may fragment the cache.
		$separator = Settings::sanitize( array_merge( $settings, array( 'show_separator' => true, 'separator_char' => '|', 'separator_after_last' => false ) ) );
		$this->assertSame( $key, Cache::key( $separator ), 'show_separator / separator_char / separator_after_last never enter the cache key.' );
		$this->assertNotContains( 'separator_after_last', Cache::PAYLOAD_KEYS );
		$this->assertNotContains( 'show_separator', Cache::PAYLOAD_KEYS );
		$this->assertNotContains( 'separator_char', Cache::PAYLOAD_KEYS );

		add_filter( 'locale', static fn() => 'fr_FR' );
		$this->assertNotSame( $key, Cache::key( $settings ), 'The locale is part of the key.' );
	}

	public function test_ttl_is_filtered_and_clamped() {
		$settings = Settings::get();
		$this->assertSame( 120, Cache::ttl( $settings ) );
		add_filter( 'hprnb_cache_ttl', static fn() => 5 );
		$this->assertSame( 30, Cache::ttl( $settings ) );
		remove_all_filters( 'hprnb_cache_ttl' );
		add_filter( 'hprnb_cache_ttl', static fn() => 5000 );
		$this->assertSame( 600, Cache::ttl( $settings ) );
	}

	public function test_invalid_payloads_are_ignored() {
		$settings = Settings::get();
		set_transient( Cache::key( $settings ), array( 'version' => '0.9.0', 'generated_at' => 1, 'count' => 0, 'items' => array(), 'html' => '' ), 60 );
		$this->assertNull( Cache::get( $settings ) );
		set_transient( Cache::key( $settings ), 'string', 60 );
		$this->assertNull( Cache::get( $settings ) );
		$this->assertFalse( Cache::is_valid( array( 'version' => HPRNB_VERSION, 'generated_at' => '1', 'count' => 0, 'items' => array(), 'html' => '' ) ) );
	}

	public function test_epoch_is_created_on_demand() {
		delete_option( Settings::EPOCH_OPTION );
		$epoch = Cache::epoch();
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $epoch );
		$this->assertSame( $epoch, get_option( Settings::EPOCH_OPTION ) );
	}

	public function test_publishing_a_post_rotates_the_epoch_once() {
		$before = Cache::epoch();
		$this->create_post_ago( 60 );
		$this->assertNotSame( $before, Cache::epoch() );
		$this->assertSame( 1, $this->invalidations, 'The static guard allows a single invalidation per request.' );

		// A second event in the same request does not rotate again.
		$after = Cache::epoch();
		$this->create_post_ago( 60 );
		$this->assertSame( $after, Cache::epoch() );
		$this->assertSame( 1, $this->invalidations );
	}

	public function test_draft_creation_does_not_invalidate() {
		$before = Cache::epoch();
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame( $before, Cache::epoch() );
		$this->assertSame( 0, $this->invalidations );
	}

	public function test_unpublish_trash_delete_and_edit_invalidate() {
		$id = $this->create_post_ago( 60 );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Edited' ) );
		$this->assertNotSame( $before, Cache::epoch(), 'Editing a published post invalidates.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
		$this->assertNotSame( $before, Cache::epoch(), 'Leaving publish invalidates.' );

		$id2 = $this->create_post_ago( 60 );
		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_trash_post( $id2 );
		$this->assertNotSame( $before, Cache::epoch(), 'Trashing invalidates.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_untrash_post( $id2 );
		$this->assertNotSame( $before, Cache::epoch(), 'Restoring invalidates.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_delete_post( $id2, true );
		$this->assertNotSame( $before, Cache::epoch(), 'Deleting invalidates.' );
	}

	public function test_term_changes_invalidate() {
		$id  = $this->create_post_ago( 60 );
		$cat = self::factory()->category->create();

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_set_post_categories( $id, array( $cat ) );
		$this->assertNotSame( $before, Cache::epoch(), 'Changing categories invalidates.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_set_post_tags( $id, array( 'urgent' ) );
		$this->assertNotSame( $before, Cache::epoch(), 'Changing tags invalidates.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		wp_delete_term( $cat, 'category' );
		$this->assertNotSame( $before, Cache::epoch(), 'Deleting a category invalidates.' );
	}

	public function test_settings_save_and_theme_switch_invalidate() {
		$before = Cache::epoch();
		Settings::update( array( 'max_items' => 3 ) );
		$this->assertNotSame( $before, Cache::epoch() );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		do_action( 'switch_theme', 'x', wp_get_theme(), wp_get_theme() );
		$this->assertNotSame( $before, Cache::epoch() );
	}

	public function test_thumbnail_change_invalidates() {
		$id = $this->create_post_ago( 60 );
		Invalidation::reset_guard();
		$before = Cache::epoch();
		update_post_meta( $id, '_thumbnail_id', 123 );
		$this->assertNotSame( $before, Cache::epoch() );
	}

	public function test_old_payload_is_unreachable_after_invalidation() {
		$this->create_post_ago( 60 );
		Invalidation::reset_guard();
		$settings = Settings::get();
		Payload::get( $settings );
		$old_key = Cache::key( $settings );
		$this->assertNotFalse( get_transient( $old_key ) );

		Invalidation::invalidate();
		$this->assertNotSame( $old_key, Cache::key( $settings ) );
		$this->assertNull( Cache::get( $settings ) );
	}
}
