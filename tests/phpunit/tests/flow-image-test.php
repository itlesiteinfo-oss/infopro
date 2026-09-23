<?php
/**
 * 2.10.0: the flowing mobile bar with the article picture at the end and its buttons in a tab.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

class Flow_Image_Test extends HPRNB_Test_Case {

	public function test_it_is_a_mobile_design_of_its_own() {
		$this->assertContains( 'flow_image', Settings::schema()['mobile_layout']['options'] );
		$this->assertSame( 'flow_image', Settings::sanitize( array( 'mobile_layout' => 'flow_image' ) )['mobile_layout'] );
		$this->assertSame( 'card', Settings::defaults()['mobile_layout'], 'The card stays the default.' );
	}

	public function test_the_flowing_bar_with_its_picture_at_the_end_and_no_button_column() {
		$settings = $this->with_settings( array( 'mobile_layout' => 'flow_image', 'mobile_lines' => 2, 'mobile_show_thumbnail' => false, 'mobile_thumb_position' => 'before' ) );
		$mobile   = Renderer::profile( $settings, 'm' );
		$this->assertSame( 'flow', $mobile['layout'], 'The flowing bar itself: the pill opens the headline.' );
		$this->assertTrue( $mobile['thumb'], 'The picture is always there, whatever the image switch says.' );
		$this->assertSame( 'after', $mobile['thumb_position'], 'At the end of the line, where the buttons were.' );
		$this->assertTrue( $mobile['tab'] );
		$this->assertFalse( Renderer::profile( $settings, 'd' )['tab'], 'Desktop is untouched.' );
		$this->assertSame( 'inline', Renderer::profile( $settings, 'd' )['layout'] );

		$this->assertSame( 0, Renderer::mobile_controls( $settings ), 'No column is kept for the buttons: the headline runs up to the picture.' );
		$flow = $this->with_settings( array( 'mobile_layout' => 'flow', 'mobile_lines' => 2 ) );
		$this->assertSame( Renderer::profile_height( $flow, 'm' ), Renderer::profile_height( $settings, 'm' ), 'Same height as the flowing bar: 76 px on two lines.' );
		$this->assertSame( 76, Renderer::profile_height( $settings, 'm' ) );
		$this->assertTrue( Settings::wants_thumbnails( $settings ), 'The picture is in the markup.' );
	}

	public function test_root_classes_put_the_buttons_in_the_tab() {
		$settings = $this->with_settings( array( 'mobile_layout' => 'flow_image', 'mobile_lines' => 2 ) );
		$classes  = Renderer::root_classes( $settings );
		foreach ( array( 'hprnb-root--m-flow', 'hprnb-root--m-thumb', 'hprnb-root--m-thumb-after', 'hprnb-root--m-ctrl-tab', 'hprnb-root--m-peek-thumb' ) as $class ) {
			$this->assertContains( $class, $classes );
		}
		foreach ( array( 'hprnb-root--m-ctrl-col', 'hprnb-root--m-ctrl-out', 'hprnb-root--m-card', 'hprnb-root--m-float' ) as $class ) {
			$this->assertNotContains( $class, $classes );
		}
		// "Buttons outside" and the stacked column have nothing to say here: the tab wins.
		$outside = $this->with_settings( array( 'mobile_layout' => 'flow_image', 'mobile_controls_place' => 'outside' ) );
		$this->assertContains( 'hprnb-root--m-ctrl-tab', Renderer::root_classes( $outside ) );
		$this->assertNotContains( 'hprnb-root--m-ctrl-out', Renderer::root_classes( $outside ) );
		// Without the "image in the strip" option the folded strip drops the picture.
		$bare = $this->with_settings( array( 'mobile_layout' => 'flow_image', 'mobile_peek_thumbnail' => false ) );
		$this->assertNotContains( 'hprnb-root--m-peek-thumb', Renderer::root_classes( $bare ) );
		// The other designs never get the tab.
		foreach ( array( 'card', 'flow', 'stacked', 'inline' ) as $layout ) {
			$this->assertNotContains( 'hprnb-root--m-ctrl-tab', Renderer::root_classes( $this->with_settings( array( 'mobile_layout' => $layout ) ) ), $layout );
		}
	}

	public function test_the_picture_changes_the_cached_markup() {
		$flow  = Settings::sanitize( array( 'mobile_layout' => 'flow' ) );
		$image = Settings::sanitize( array( 'mobile_layout' => 'flow_image' ) );
		$this->assertNotSame( Cache::hash( $flow ), Cache::hash( $image ), 'A cached bar without pictures is never served to this design.' );
	}

	public function test_the_design_is_offered_with_its_image_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();
		ob_start();
		\HorizonPress\NewsBar\Admin\Settings_Page::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="hprnb_settings[mobile_layout]" value="flow_image"', $html );
		// Its picture size and its picture in the folded strip stay reachable without the image switch.
		$this->assertSame( 2, substr_count( $html, 'data-hprnb-depends="mobile_show_thumbnail,mobile_layout:flow_image"' ) );
	}
}
