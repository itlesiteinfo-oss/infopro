<?php
/**
 * Visibility rules on real front-end requests.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Visibility;

class Visibility_Test extends HPRNB_Test_Case {

	public function test_everywhere_by_default() {
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Visibility::should_display( Settings::get() ) );
		$this->assertSame( 'front_page', Visibility::context_key() );
	}

	public function test_disabled_or_no_device() {
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( Visibility::should_display( $this->with_settings( array( 'enabled' => false ) ) ) );
		$this->assertFalse( Visibility::should_display( $this->with_settings( array( 'show_on_desktop' => false, 'show_on_mobile' => false ) ) ) );
		$this->assertTrue( Visibility::should_display( $this->with_settings( array( 'show_on_desktop' => false ) ) ) );
	}

	public function test_custom_scope_contexts() {
		$post_id = $this->create_post_ago( 60 );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About' ) );
		$cat     = self::factory()->category->create( array( 'slug' => 'politics' ) );
		wp_set_post_categories( $post_id, array( $cat ) );

		$contexts = array_fill_keys( Settings::CONTEXT_KEYS, false );
		$only     = static fn( string $key ) => array_merge( $contexts, array( $key => true ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'single_post' ) ) );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertSame( 'single_post', Visibility::context_key() );
		$this->assertTrue( Visibility::should_display( $settings ) );
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( Visibility::should_display( $settings ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'page' ) ) );
		$this->go_to( get_permalink( $page_id ) );
		$this->assertSame( 'page', Visibility::context_key() );
		$this->assertTrue( Visibility::should_display( $settings ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'category' ) ) );
		$this->go_to( get_category_link( $cat ) );
		$this->assertSame( 'category', Visibility::context_key() );
		$this->assertTrue( Visibility::should_display( $settings ) );
		$this->go_to( get_permalink( $post_id ) );
		$this->assertFalse( Visibility::should_display( $settings ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'search' ) ) );
		$this->go_to( home_url( '/?s=hello' ) );
		$this->assertSame( 'search', Visibility::context_key() );
		$this->assertTrue( Visibility::should_display( $settings ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'not_found' ) ) );
		$this->go_to( home_url( '/?p=999999' ) );
		$this->assertSame( 'not_found', Visibility::context_key() );
		$this->assertTrue( Visibility::should_display( $settings ) );

		$settings = $this->with_settings( array( 'display_scope' => 'custom', 'contexts' => $only( 'front_page' ) ) );
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Visibility::should_display( $settings ) );
	}

	public function test_display_exclude_ids() {
		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$other_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$settings = $this->with_settings( array( 'display_exclude_ids' => array( $page_id ) ) );

		$this->go_to( get_permalink( $page_id ) );
		$this->assertFalse( Visibility::should_display( $settings ) );
		$this->go_to( get_permalink( $other_id ) );
		$this->assertTrue( Visibility::should_display( $settings ) );
	}

	public function test_feed_and_embed_are_absolute_exclusions() {
		$post_id = $this->create_post_ago( 60 );
		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertTrue( Visibility::is_absolute_exclusion() );
		$this->assertFalse( Visibility::should_display( Settings::get() ) );

		$this->go_to( add_query_arg( 'embed', 'true', get_permalink( $post_id ) ) );
		$this->assertTrue( Visibility::is_absolute_exclusion() );

		$this->go_to( home_url( '/' ) );
		$this->assertFalse( Visibility::is_absolute_exclusion() );
	}

	public function test_should_display_filter() {
		$this->go_to( home_url( '/' ) );
		add_filter( 'hprnb_should_display', '__return_false' );
		$this->assertFalse( Visibility::should_display( Settings::get() ) );
	}
}
