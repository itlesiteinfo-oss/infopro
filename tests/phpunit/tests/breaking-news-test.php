<?php
/**
 * 2.17: the "Breaking News" design of the URGENT bar (the default, the chyron still selectable) and
 * the article being read left out of it.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Settings_Page;
use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Shortcode;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;

class Breaking_News_Test extends HPRNB_Test_Case {

	/**
	 * Flags a fresh published article as urgent.
	 *
	 * @param string $title Title.
	 * @return int
	 */
	private function urgent_post( string $title ): int {
		$id = $this->create_post_ago( 120, array( 'post_title' => $title ) );
		Invalidation::reset_guard();
		Urgent::flag( $id, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();
		return $id;
	}

	/**
	 * A page as a visitor gets it.
	 *
	 * @param string $url Address.
	 * @return array{footer:string,body:array,scripts:bool}
	 */
	private function page( string $url ): array {
		$this->go_to_front( $url );
		wp_styles()->registered  = array();
		wp_scripts()->registered = array();
		wp_scripts()->queue      = array();
		Assets::register_front();
		Frontend::enqueue();
		return array(
			'footer'  => $this->render_footer(),
			'body'    => Frontend::body_class( array() ),
			'scripts' => wp_script_is( 'hprnb-bar', 'enqueued' ),
		);
	}

	public function test_breaking_news_is_the_default_and_the_chyron_stays_selectable() {
		$this->assertSame( 'breaking', Settings::defaults()['urgent_design'] );
		$this->assertTrue( Settings::defaults()['urgent_exclude_current'] );
		$this->assertSame( 'breaking', Settings::sanitize( array( 'urgent_design' => 'neon' ) )['urgent_design'], 'An unknown design: Breaking News.' );
		$this->assertSame( 'breaking', Settings::sanitize( array() )['urgent_design'], 'A site from before 2.17 (no such key): Breaking News.' );
		$this->assertSame( 'chyron', Settings::sanitize( array( 'urgent_design' => 'chyron' ) )['urgent_design'] );

		$this->assertTrue( Renderer::urgent_breaking( Settings::get() ) );
		$this->assertContains( 'hprnb-root--u-bn', Renderer::root_classes( Settings::get() ) );
		$chyron = Settings::sanitize( array( 'urgent_design' => 'chyron' ) );
		$this->assertNotContains( 'hprnb-root--u-bn', Renderer::root_classes( $chyron ) );
		$this->assertFalse( Renderer::urgent_breaking( $chyron ) );
		$this->assertContains( 'hprnb-root--u-d-flow', Renderer::root_classes( array_merge( $chyron, array( 'urgent_desktop_layout' => 'mobile' ) ) ), 'The chyron keeps its second desktop design.' );
		$this->assertContains( 'hprnb-root--u-bn', Renderer::root_classes( Settings::get(), true ), 'The preview shows it too.' );
	}

	public function test_the_settings_page_offers_both_designs_and_the_checkbox() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Settings_Page::render();
		$page = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/name="hprnb_settings\[urgent_design\]" value="breaking"\s+checked/', $page );
		$this->assertStringContainsString( 'name="hprnb_settings[urgent_design]" value="chyron"', $page );
		$this->assertStringContainsString( 'Breaking News — recommended', $page );
		$this->assertMatchesRegularExpression( '/id="hprnb-field-urgent-exclude-current" name="hprnb_settings\[urgent_exclude_current\]" value="1"\s+checked/', $page );
		$this->assertStringContainsString( 'data-hprnb-card-depends="urgent_design:chyron"', $page, 'The desktop design of the chyron only shows for the chyron.' );
		$this->assertLessThan( strpos( $page, 'Where the URGENT bar shows' ), strpos( $page, 'Design of the URGENT bar' ), 'The design card comes before the page types.' );
	}

	public function test_the_article_being_read_is_left_out() {
		$first  = $this->urgent_post( 'Premier flash' );
		$second = $this->urgent_post( 'Second flash' );

		// On the first article: its headline is marked (out of sight), the other one is in front.
		$page = $this->page( get_permalink( $first ) );
		$this->assertStringContainsString( 'hprnb-root--urgent', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-here="1"', $page['footer'] );
		$this->assertMatchesRegularExpression( '/<li class="hprnb-bar__item hprnb-bar__item--here" data-hprnb-id="' . $first . '"/', $page['footer'] );
		$this->assertMatchesRegularExpression( '/<li class="hprnb-bar__item" data-hprnb-id="' . $second . '"/', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-urgent="1"', $page['footer'], 'One headline left in front.' );

		// Elsewhere (front page): both, nothing marked.
		$page = $this->page( home_url( '/' ) );
		$this->assertStringNotContainsString( 'hprnb-bar__item--here', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-urgent="2"', $page['footer'] );

		// The checkbox off: nothing left out.
		$this->with_settings( array( 'urgent_exclude_current' => false ) );
		$page = $this->page( get_permalink( $first ) );
		$this->assertStringNotContainsString( 'hprnb-bar__item--here', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-here="0"', $page['footer'] );
		$this->assertStringContainsString( 'data-hprnb-urgent="2"', $page['footer'] );
	}

	public function test_the_only_urgent_article_being_read_parks_the_bar() {
		$this->create_post_ago( 60, array( 'post_title' => 'Une actualité du jour' ) );
		$only = $this->urgent_post( 'Seul flash' );

		$page = $this->page( get_permalink( $only ) );
		$this->assertStringNotContainsString( 'hprnb-root--urgent', $page['footer'], 'Nothing urgent in front on its own page: the news bar has it.' );
		$this->assertStringContainsString( 'data-hprnb-urgent="0"', $page['footer'] );
		$this->assertStringContainsString( 'hprnb-bar--urgent', $page['footer'], 'Parked in the page, for a theme that moves on to the next article without a reload.' );
		$this->assertStringContainsString( 'hprnb-bar__item--here', $page['footer'] );
		$this->assertStringContainsString( 'Une actualité du jour', $page['footer'] );
		$this->assertTrue( $page['scripts'], 'The script that brings it back is there.' );
		$this->assertContains( 'hprnb-reserve', $page['body'] );

		// Without any news headline: an empty root, hidden, still holding the parked bar.
		$this->with_settings( array( 'enabled' => false ) );
		$page = $this->page( get_permalink( $only ) );
		$this->assertMatchesRegularExpression( '/<div id="hprnb-root"[^>]* hidden><aside class="hprnb-bar[^"]*hprnb-bar--urgent"/', $page['footer'] );
		$this->assertTrue( $page['scripts'] );
		$this->assertNotContains( 'hprnb-reserve', $page['body'], 'No space kept for a bar out of sight.' );
	}

	public function test_the_space_kept_before_the_script_is_the_longest_headline() {
		$settings = Settings::get();
		$item     = static function ( int $id, string $title ): array {
			return array(
				'id'    => $id,
				'title' => $title,
			);
		};
		$short = $item( 1, 'Séisme au large d’Al Hoceïma' );
		$long  = $item( 2, str_repeat( 'Le gouvernement annonce un plan ', 4 ) ); // 128 letters.

		// Phone (17px, 22px lines): the 44px label row, the lines, 12px under them.
		$this->assertSame( 56 + 22, Renderer::breaking_height( $settings, array( 'urgent_items' => array( $short ) ), 'm' ) );
		$this->assertSame( 56 + 4 * 22, Renderer::breaking_height( $settings, array( 'urgent_items' => array( $short, $long ) ), 'm' ), 'The longest headline decides.' );
		// Desktop (23px lines): 12px above and under, never under the one-line bar.
		$this->assertSame( Renderer::urgent_height( $settings, 'd' ), Renderer::breaking_height( $settings, array( 'urgent_items' => array( $short ) ), 'd' ) );
		$this->assertSame( 24 + 2 * 23, Renderer::breaking_height( $settings, array( 'urgent_items' => array( $short, $long ) ), 'd' ) );
		// The article being read is out of sight: its headline does not count.
		$this->assertSame(
			56 + 22,
			Renderer::breaking_height(
				$settings,
				array(
					'urgent_items' => array( $short, $long ),
					'urgent_here'  => 2,
				),
				'm'
			)
		);
		// A bigger headline size: fewer letters a line, taller lines.
		$this->assertSame( 56 + 5 * 26, Renderer::breaking_height( array_merge( $settings, array( 'urgent_mobile_font_size' => 20 ) ), array( 'urgent_items' => array( $long ) ), 'm' ) );
		// Nothing to measure: the bar's own height.
		$this->assertSame( Renderer::urgent_height( $settings, 'm' ), Renderer::breaking_height( $settings, array(), 'm' ) );

		// On the page: the reserved space, the chyron's otherwise.
		$this->urgent_post( $long['title'] );
		$this->page( home_url( '/' ) );
		$this->assertStringContainsString( '--hprnb-height:70px;--hprnb-m-height:144px', implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) ) );
		$this->with_settings( array( 'urgent_design' => 'chyron' ) );
		$this->page( home_url( '/' ) );
		$this->assertStringContainsString( '--hprnb-height:48px;--hprnb-m-height:76px', implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) ) );
	}

	public function test_the_chyron_desktop_design_and_short_screens_leave_breaking_news_its_own_height() {
		// The chyron's phone design on desktop (a setting hidden with Breaking News) no longer makes its band taller.
		$settings = array_merge( Settings::get(), array( 'urgent_desktop_layout' => 'mobile' ) );
		$this->assertSame( 48, Renderer::urgent_height( $settings, 'd' ) );
		$this->assertSame( Renderer::urgent_height( $settings, 'm' ), Renderer::urgent_height( array_merge( $settings, array( 'urgent_design' => 'chyron' ) ), 'd' ), 'The chyron keeps it.' );

		// A phone in landscape: one row, 45 letters a line at 17px.
		$long = array(
			'id'    => 1,
			'title' => str_repeat( 'Le gouvernement annonce un plan ', 4 ),
		);
		$this->assertSame( 24 + 3 * 22, Renderer::breaking_height( Settings::get(), array( 'urgent_items' => array( $long ) ), 's' ) );
		$this->urgent_post( $long['title'] );
		$this->page( home_url( '/' ) );
		$this->assertStringContainsString( '@media (max-height:480px) and (max-width:1023.98px){body.hprnb-reserve{--hprnb-m-height:90px !important}}', implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) ) );
		$this->with_settings( array( 'urgent_design' => 'chyron' ) );
		$this->page( home_url( '/' ) );
		$this->assertStringNotContainsString( '@media (max-height:480px)', implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) ), 'The chyron keeps the short-screen 44px.' );
	}

	public function test_the_article_placement_and_the_shortcode_leave_out_the_article_being_read() {
		$paragraphs = str_repeat( '<p>Un paragraphe de lecture.</p>', 6 );
		$this->create_post_ago( 60, array( 'post_title' => 'Une actualité du jour' ) );
		$only = $this->create_post_ago( 120, array( 'post_title' => 'Seul flash', 'post_content' => $paragraphs ) );
		Invalidation::reset_guard();
		Urgent::flag( $only, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();

		// Inside the article: the same parked bar as in the footer, whose headline is marked.
		$this->with_settings(
			array(
				'desktop_placement' => 'inline',
				'mobile_placement'  => 'inline',
			)
		);
		\HorizonPress\NewsBar\Placement::reset();
		$this->go_to_front( get_permalink( $only ) );
		the_post();
		$html = apply_filters( 'the_content', get_the_content() );
		$this->assertStringContainsString( 'id="hprnb-root"', $html );
		$this->assertStringContainsString( 'data-hprnb-urgent="0"', $html );
		$this->assertStringNotContainsString( 'hprnb-root--urgent', $html );
		$this->assertStringContainsString( 'hprnb-bar__item--here', $html );
		\HorizonPress\NewsBar\Placement::reset();

		// The shortcode likewise, with the script that brings the bar back.
		$this->with_settings( array( 'shortcode_enabled' => true ) );
		$this->go_to_front( get_permalink( $only ) );
		wp_scripts()->queue = array();
		$html = do_shortcode( '[' . Shortcode::TAG . ']' );
		$this->assertStringContainsString( 'data-hprnb-urgent="0"', $html );
		$this->assertStringContainsString( 'hprnb-bar__item--here', $html );
		$this->assertTrue( wp_script_is( 'hprnb-bar', 'enqueued' ) );
	}

	public function test_a_parked_bar_gets_the_theme_offset_for_when_it_comes_back() {
		$only = $this->urgent_post( 'Seul flash' );
		$this->with_settings( array( 'enabled' => false ) );
		$page = $this->page( get_permalink( $only ) );
		$this->assertContains( 'hprnb-theme-offset', $page['body'], 'Harmless without hprnb-reserve, which the script adds when the bar comes back.' );
		$this->assertNotContains( 'hprnb-reserve', $page['body'] );
		$this->with_settings(
			array(
				'enabled'      => false,
				'theme_offset' => false,
			)
		);
		$this->assertNotContains( 'hprnb-theme-offset', $this->page( get_permalink( $only ) )['body'] );
	}

	public function test_the_urgent_bar_markup_marks_only_the_article_given() {
		$items = array(
			array(
				'id'    => 11,
				'title' => 'A',
				'url'   => 'https://example.org/a/',
				'since' => 1,
				'until' => 2,
			),
			array(
				'id'    => 12,
				'title' => 'B',
				'url'   => 'https://example.org/b/',
				'since' => 1,
				'until' => 2,
			),
		);
		$html  = Renderer::urgent_bar( $items, Settings::get(), 12 );
		$this->assertSame( 1, substr_count( $html, 'hprnb-bar__item--here' ) );
		$this->assertStringContainsString( '<li class="hprnb-bar__item hprnb-bar__item--here" data-hprnb-id="12"', $html );
		$this->assertStringNotContainsString( 'hprnb-bar__item--here', Renderer::urgent_bar( $items, Settings::get() ) );
	}
}
