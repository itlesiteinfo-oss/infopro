<?php
/**
 * Post selection: arguments, filters, statuses, sticky, normalisation, thumbnails, N+1.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Query;
use HorizonPress\NewsBar\Settings;

class Query_Test extends HPRNB_Test_Case {

	public function test_base_arguments() {
		$args = Query::args( Settings::get() );

		$this->assertSame( array( 'post' ), $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertFalse( $args['has_password'] );
		$this->assertTrue( $args['ignore_sticky_posts'] );
		$this->assertSame( 10, $args['posts_per_page'] );
		$this->assertTrue( $args['no_found_rows'] );
		$this->assertFalse( $args['update_post_term_cache'] );
		$this->assertFalse( $args['update_post_meta_cache'] );
		$this->assertSame( 'date', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertSame( 'post_date_gmt', $args['date_query'][0]['column'] );

		foreach ( array( 'category__in', 'category__not_in', 'tag__in', 'post__not_in', 'suppress_filters' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $args );
		}
	}

	public function test_list_arguments_only_when_non_empty() {
		$settings = $this->with_settings(
			array(
				'categories_include'       => array( 3 ),
				'categories_exclude'       => array( 4, 5 ),
				'tags_include'             => array( 6 ),
				'content_exclude_post_ids' => array( 7 ),
				'orderby'                  => 'date_asc',
				'max_items'                => 4,
			)
		);
		$args = Query::args( $settings );

		$this->assertSame( array( 3 ), $args['category__in'] );
		$this->assertSame( array( 4, 5 ), $args['category__not_in'] );
		$this->assertSame( array( 6 ), $args['tag__in'] );
		$this->assertSame( array( 7 ), $args['post__not_in'] );
		$this->assertSame( 'ASC', $args['order'] );
		$this->assertSame( 4, $args['posts_per_page'] );
	}

	public function test_meta_and_term_cache_flags() {
		$settings = $this->with_settings( array( 'desktop_show_thumbnail' => true ) );
		$this->assertTrue( Query::args( $settings )['update_post_meta_cache'] );

		update_option( 'permalink_structure', '/%category%/%postname%/' );
		$this->assertTrue( Query::args( $settings )['update_post_term_cache'] );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertFalse( Query::args( $settings )['update_post_term_cache'] );
	}

	public function test_query_args_filter() {
		add_filter(
			'hprnb_query_args',
			static function ( $args ) {
				$args['posts_per_page'] = 1;
				return $args;
			}
		);
		$this->create_post_ago( 60 );
		$this->create_post_ago( 120 );
		$this->assertCount( 1, Query::items( Settings::get() ) );
	}

	public function test_excluded_statuses_and_passwords() {
		$published = $this->create_post_ago( 60 );
		$this->create_post_ago( 60, array( 'post_status' => 'draft' ) );
		$this->create_post_ago( 60, array( 'post_status' => 'private' ) );
		$this->create_post_ago( 60, array( 'post_status' => 'pending' ) );
		$this->create_post_ago( 60, array( 'post_password' => 'secret' ) );
		self::factory()->post->create( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ) );
		$this->create_post_ago( 60, array( 'post_type' => 'page' ) );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertSame( array( $published ), $ids );
	}

	public function test_sticky_posts_follow_the_window_and_the_date_order() {
		$old_sticky    = $this->create_post_ago( 3 * DAY_IN_SECONDS );
		$recent_sticky = $this->create_post_ago( 2 * HOUR_IN_SECONDS );
		$newest        = $this->create_post_ago( HOUR_IN_SECONDS );
		update_option( 'sticky_posts', array( $old_sticky, $recent_sticky ) );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertSame( array( $newest, $recent_sticky ), $ids );
	}

	public function test_order_and_limit() {
		$a = $this->create_post_ago( 300 );
		$b = $this->create_post_ago( 200 );
		$c = $this->create_post_ago( 100 );

		$this->assertSame( array( $c, $b, $a ), wp_list_pluck( Query::items( Settings::get() ), 'id' ) );

		$settings = $this->with_settings( array( 'orderby' => 'date_asc', 'max_items' => 2 ) );
		$this->assertSame( array( $a, $b ), wp_list_pluck( Query::items( $settings ), 'id' ) );
	}

	public function test_category_include_exclude_and_tags() {
		$politics = self::factory()->category->create( array( 'name' => 'Politics' ) );
		$sport    = self::factory()->category->create( array( 'name' => 'Sport' ) );
		$tag      = self::factory()->tag->create( array( 'name' => 'Breaking' ) );

		$p1 = $this->create_post_ago( 100, array( 'post_category' => array( $politics ) ) );
		$p2 = $this->create_post_ago( 200, array( 'post_category' => array( $sport ) ) );
		$p3 = $this->create_post_ago( 300, array( 'post_category' => array( $politics, $sport ), 'tags_input' => array( 'Breaking' ) ) );
		$p4 = $this->create_post_ago( 400 );

		// All categories.
		$this->assertSame( array( $p1, $p2, $p3, $p4 ), wp_list_pluck( Query::items( Settings::get() ), 'id' ) );

		// One category.
		$settings = $this->with_settings( array( 'categories_include' => array( $politics ) ) );
		$this->assertSame( array( $p1, $p3 ), wp_list_pluck( Query::items( $settings ), 'id' ) );

		// Several categories.
		$settings = $this->with_settings( array( 'categories_include' => array( $politics, $sport ) ) );
		$this->assertSame( array( $p1, $p2, $p3 ), wp_list_pluck( Query::items( $settings ), 'id' ) );

		// Excluded category wins.
		$settings = $this->with_settings( array( 'categories_exclude' => array( $sport ) ) );
		$this->assertSame( array( $p1, $p4 ), wp_list_pluck( Query::items( $settings ), 'id' ) );

		// Tag.
		$settings = $this->with_settings( array( 'tags_include' => array( $tag ) ) );
		$this->assertSame( array( $p3 ), wp_list_pluck( Query::items( $settings ), 'id' ) );

		// Excluded post ID.
		$settings = $this->with_settings( array( 'content_exclude_post_ids' => array( $p1, $p4 ) ) );
		$this->assertSame( array( $p2, $p3 ), wp_list_pluck( Query::items( $settings ), 'id' ) );
	}

	public function test_empty_title_is_dropped() {
		$this->create_post_ago( 60, array( 'post_title' => '' ) );
		$kept = $this->create_post_ago( 120, array( 'post_title' => 'Kept' ) );
		$this->assertSame( array( $kept ), wp_list_pluck( Query::items( Settings::get() ), 'id' ) );
	}

	public function test_normalised_item_shape() {
		update_option( 'date_format', 'j F Y' );
		update_option( 'time_format', 'H:i' );
		update_option( 'timezone_string', 'Europe/Paris' );
		$id   = $this->create_post_ago( 60, array( 'post_title' => 'Tom &amp; "Jerry" <em>bold</em>' ) );
		$item = Query::items( Settings::get() )[0];

		$this->assertSame( array( 'id', 'title', 'url', 'timestamp', 'datetime', 'date_label', 'thumb' ), array_keys( $item ) );
		$this->assertSame( $id, $item['id'] );
		$this->assertStringNotContainsString( '<', $item['title'] );
		$this->assertStringContainsString( 'Jerry', $item['title'] );
		$this->assertSame( get_permalink( $id ), $item['url'] );
		$this->assertSame( get_post_timestamp( $id ), $item['timestamp'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $item['datetime'] );
		$this->assertSame( wp_date( 'j F Y H:i', $item['timestamp'] ), $item['date_label'] );
		$this->assertNull( $item['thumb'] );
	}

	public function test_thumbnails_are_resolved_without_n_plus_one() {
		$settings = $this->with_settings( array( 'desktop_show_thumbnail' => true, 'thumbnail_size' => 'thumbnail' ) );
		$file     = DIR_TESTDATA . '/images/canola.jpg';

		$post_ids = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$post_id       = $this->create_post_ago( 60 + $i );
			$attachment_id = self::factory()->attachment->create_upload_object( $file, $post_id );
			set_post_thumbnail( $post_id, $attachment_id );
			$post_ids[] = $post_id;
		}

		// Measure with one post allowed…
		$one = $this->with_settings( array( 'desktop_show_thumbnail' => true, 'max_items' => 1 ) );
		wp_cache_flush();
		wp_load_alloptions();
		get_option( 'permalink_structure' );
		$before = get_num_queries();
		$items  = Query::items( $one );
		$queries_one = get_num_queries() - $before;
		$this->assertCount( 1, $items );
		$this->assertNotNull( $items[0]['thumb'] );

		// …and with six posts: the number of queries must not grow with the number of items.
		wp_cache_flush();
		wp_load_alloptions();
		get_option( 'permalink_structure' );
		$before = get_num_queries();
		$items  = Query::items( $settings );
		$queries_six = get_num_queries() - $before;
		$this->assertCount( 6, $items );
		foreach ( $items as $item ) {
			$this->assertIsArray( $item['thumb'] );
			$this->assertNotEmpty( $item['thumb']['url'] );
			$this->assertGreaterThan( 0, $item['thumb']['width'] );
			$this->assertGreaterThan( 0, $item['thumb']['height'] );
		}
		$this->assertSame( $queries_one, $queries_six, "Queries for 1 item: {$queries_one}, for 6 items: {$queries_six}" );
		$this->assertLessThanOrEqual( 6, $queries_six );
	}

	public function test_thumbnail_size_falls_back_to_thumbnail() {
		$this->assertSame( 'thumbnail', Query::thumbnail_size( array( 'thumbnail_size' => 'does-not-exist' ) ) );
		$this->assertSame( 'medium', Query::thumbnail_size( array( 'thumbnail_size' => 'medium' ) ) );
	}

	public function test_items_filter() {
		$this->create_post_ago( 60 );
		add_filter( 'hprnb_items', '__return_empty_array' );
		$this->assertSame( array(), Query::items( Settings::get() ) );
	}
}
