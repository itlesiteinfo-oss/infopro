<?php
/**
 * Per-post controls: the "News Bar" box on the edit screen.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Post_Controls;
use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Query;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Visibility;

class Post_Controls_Test extends HPRNB_Test_Case {

	private int $editor = 0;

	public function set_up() {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Posts the meta box the way the browser does, nonce included.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $ticked  Meta keys the editor ticked.
	 * @return void
	 */
	private function submit( int $post_id, array $ticked ): void {
		$_POST                         = array();
		$_POST[ Post_Controls::NONCE ] = wp_create_nonce( Post_Controls::NONCE );
		foreach ( $ticked as $key ) {
			$_POST[ $key ] = '1';
		}
		Post_Controls::save( $post_id, get_post( $post_id ) );
		$_POST = array();
	}

	public function test_the_two_keys_are_namespaced_and_protected() {
		$this->assertSame( '_hprnb_exclude_item', Settings::META_EXCLUDE );
		$this->assertSame( '_hprnb_hide_bar', Settings::META_HIDE );
		$this->assertSame( Settings::META_EXCLUDE, Post_Controls::META_EXCLUDE );
		$this->assertSame( Settings::META_HIDE, Post_Controls::META_HIDE );
		// A leading underscore keeps both out of the generic custom fields UI.
		$this->assertTrue( is_protected_meta( Settings::META_EXCLUDE, 'post' ) );
		$this->assertTrue( is_protected_meta( Settings::META_HIDE, 'post' ) );
	}

	public function test_an_excluded_article_leaves_the_bar_without_shortening_it() {
		$keep    = $this->create_post_ago( 60, array( 'post_title' => 'Kept' ) );
		$dropped = $this->create_post_ago( 120, array( 'post_title' => 'Dropped' ) );
		$third   = $this->create_post_ago( 180, array( 'post_title' => 'Third' ) );

		$settings = $this->with_settings( array( 'max_items' => 2 ) );
		$titles   = wp_list_pluck( Query::items( $settings ), 'title' );
		$this->assertSame( array( 'Kept', 'Dropped' ), $titles, 'Two newest, before anything is excluded.' );

		update_post_meta( $dropped, Settings::META_EXCLUDE, '1' );
		Payload::flush();
		Invalidation::reset_guard();

		$titles = wp_list_pluck( Query::items( Settings::get() ), 'title' );
		$this->assertSame( array( 'Kept', 'Third' ), $titles, 'The bar refills instead of shrinking.' );
		$this->assertCount( 2, $titles, 'max_items is still honoured.' );
		unset( $keep );
	}

	public function test_the_exclusion_is_a_sql_clause_not_a_post_filter() {
		$this->create_post_ago( 60 );
		$args = Query::args( Settings::get() );
		$this->assertArrayHasKey( 'meta_query', $args );
		$this->assertSame( Settings::META_EXCLUDE, $args['meta_query'][0]['key'] );
		$this->assertSame( 'NOT EXISTS', $args['meta_query'][0]['compare'], 'An article never flagged is kept.' );
	}

	public function test_hiding_the_bar_on_one_page_beats_every_other_rule() {
		$this->create_post_ago( 60 );
		$page     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$settings = $this->with_settings( array( 'display_scope' => 'everywhere' ) );

		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( Visibility::should_display( $settings ), 'Allowed everywhere by the settings.' );

		update_post_meta( $page, Settings::META_HIDE, '1' );
		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( Visibility::is_hidden_by_post( $page ) );
		$this->assertTrue( Visibility::is_excluded_id( $settings ) );
		$this->assertFalse( Visibility::should_display( $settings ), 'The author had the last word.' );

		// It is scoped to that page: a sibling is untouched.
		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $other ) );
		$this->assertTrue( Visibility::should_display( $settings ) );
	}

	public function test_the_two_flags_are_independent() {
		$post     = $this->create_post_ago( 60, array( 'post_title' => 'Both' ) );
		$settings = $this->with_settings( array() );

		update_post_meta( $post, Settings::META_EXCLUDE, '1' );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( Visibility::should_display( $settings ), 'Out of the bar, but the bar still shows on its own page.' );

		update_post_meta( $post, Settings::META_HIDE, '1' );
		$this->go_to( get_permalink( $post ) );
		$this->assertFalse( Visibility::should_display( $settings ) );
	}

	public function test_changing_either_flag_rotates_the_cache_epoch() {
		$post = $this->create_post_ago( 60 );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		update_post_meta( $post, Settings::META_EXCLUDE, '1' );
		$this->assertNotSame( $before, Cache::epoch(), 'A headline left the bar: the cached HTML is stale.' );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		update_post_meta( $post, Settings::META_HIDE, '1' );
		$this->assertNotSame( $before, Cache::epoch() );

		Invalidation::reset_guard();
		$before = Cache::epoch();
		delete_post_meta( $post, Settings::META_EXCLUDE );
		$this->assertNotSame( $before, Cache::epoch(), 'And unticking brings it back.' );

		// An unrelated meta key still costs nothing.
		Invalidation::reset_guard();
		$before = Cache::epoch();
		update_post_meta( $post, 'some_other_key', 'x' );
		$this->assertSame( $before, Cache::epoch() );
	}

	public function test_the_flags_never_fragment_the_payload_cache() {
		$this->assertNotContains( Settings::META_EXCLUDE, Cache::PAYLOAD_KEYS );
		$this->assertNotContains( Settings::META_HIDE, Cache::PAYLOAD_KEYS );
		$settings = Settings::get();
		$this->assertSame( Cache::hash( $settings ), Cache::hash( $settings ), 'One payload still serves the whole site.' );
	}

	public function test_saving_requires_a_nonce_and_the_capability() {
		$post = $this->create_post_ago( 60 );
		wp_set_current_user( $this->editor );

		// No nonce at all: a REST or quick-edit save must leave the flags alone.
		$_POST = array( Settings::META_HIDE => '1' );
		Post_Controls::save( $post, get_post( $post ) );
		$_POST = array();
		$this->assertFalse( Post_Controls::flagged( $post, Settings::META_HIDE ) );

		// A wrong nonce is refused just as firmly.
		$_POST = array(
			Post_Controls::NONCE => 'not-a-nonce',
			Settings::META_HIDE  => '1',
		);
		Post_Controls::save( $post, get_post( $post ) );
		$_POST = array();
		$this->assertFalse( Post_Controls::flagged( $post, Settings::META_HIDE ) );

		// A subscriber with a valid nonce still cannot flag someone else's article.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( $post, array( Settings::META_HIDE ) );
		$this->assertFalse( Post_Controls::flagged( $post, Settings::META_HIDE ) );

		// The editor can.
		wp_set_current_user( $this->editor );
		$this->submit( $post, array( Settings::META_HIDE, Settings::META_EXCLUDE ) );
		$this->assertTrue( Post_Controls::flagged( $post, Settings::META_HIDE ) );
		$this->assertTrue( Post_Controls::flagged( $post, Settings::META_EXCLUDE ) );
	}

	public function test_unticking_really_unticks_and_leaves_no_row_behind() {
		$post = $this->create_post_ago( 60 );
		wp_set_current_user( $this->editor );

		$this->submit( $post, array( Settings::META_HIDE, Settings::META_EXCLUDE ) );
		$this->assertTrue( Post_Controls::flagged( $post, Settings::META_HIDE ) );

		$this->submit( $post, array() );
		$this->assertFalse( Post_Controls::flagged( $post, Settings::META_HIDE ) );
		$this->assertFalse( Post_Controls::flagged( $post, Settings::META_EXCLUDE ) );
		$this->assertSame( array(), get_post_meta( $post, Settings::META_HIDE ), 'Deleted, not stored as an empty string.' );
	}

	public function test_an_autosave_never_clears_the_flags() {
		$post = $this->create_post_ago( 60 );
		wp_set_current_user( $this->editor );
		$this->submit( $post, array( Settings::META_HIDE ) );

		$revision = self::factory()->post->create(
			array(
				'post_type'   => 'revision',
				'post_parent' => $post,
			)
		);
		$_POST    = array( Post_Controls::NONCE => wp_create_nonce( Post_Controls::NONCE ) );
		Post_Controls::save( $revision, get_post( $revision ) );
		$_POST = array();

		$this->assertTrue( Post_Controls::flagged( $post, Settings::META_HIDE ), 'The parent keeps its flag.' );
	}

	public function test_the_box_is_offered_on_public_types_only() {
		$types = Post_Controls::post_types();
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertNotContains( 'attachment', $types, 'A media file has no bar of its own.' );
		$this->assertNotContains( 'revision', $types );
	}

	public function test_the_box_renders_both_checkboxes_on_an_article_and_one_on_a_page() {
		wp_set_current_user( $this->editor );

		ob_start();
		Post_Controls::render( get_post( $this->create_post_ago( 60 ) ) );
		$article = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . Settings::META_HIDE . '"', $article );
		$this->assertStringContainsString( 'name="' . Settings::META_EXCLUDE . '"', $article );
		$this->assertStringContainsString( 'name="' . Post_Controls::NONCE . '"', $article );

		ob_start();
		Post_Controls::render( get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
		$page = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="' . Settings::META_HIDE . '"', $page );
		$this->assertStringNotContainsString( 'name="' . Settings::META_EXCLUDE . '"', $page, 'A page is never a headline.' );
	}

	public function test_a_hidden_page_does_not_reserve_room_for_a_shortcode_bar() {
		$this->create_post_ago( 60 );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[hprnb_news_bar]',
			)
		);
		$this->with_settings(
			array(
				'shortcode_enabled' => true,
				'auto_display'      => false,
			)
		);

		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( \HorizonPress\NewsBar\Frontend::shortcode_expected(), 'Normally the space is reserved.' );

		update_post_meta( $page, Settings::META_HIDE, '1' );
		$this->go_to( get_permalink( $page ) );
		$this->assertFalse( \HorizonPress\NewsBar\Frontend::shortcode_expected(), 'A hand-placed bar obeys the same opt-out.' );
		$this->assertSame( '', do_shortcode( '[hprnb_news_bar]' ), 'And renders nothing.' );
	}
}
