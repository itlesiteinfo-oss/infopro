<?php
/**
 * One switch per bar, and one per device under each (2.16): the URGENT bar and the initial bar (the
 * news bar) are switched on and off separately, and each chooses its devices.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Post_Controls;
use HorizonPress\NewsBar\Admin\Settings_Page;
use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;

class Bar_Switches_Test extends HPRNB_Test_Case {

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
		Invalidation::reset_guard();
		Urgent::flag( $id, Settings::get() );
		Payload::flush();
		Invalidation::reset_guard();
		return $id;
	}

	/**
	 * The front page as a visitor gets it.
	 *
	 * @return array{footer:string,body:array,inline:string}
	 */
	private function front(): array {
		$this->go_to_front( home_url( '/' ) );
		wp_styles()->registered = array();
		Assets::register_front();
		Frontend::enqueue();
		return array(
			'footer' => $this->render_footer(),
			'body'   => Frontend::body_class( array() ),
			'inline' => implode( '', (array) wp_styles()->get_data( 'hprnb-bar', 'after' ) ),
		);
	}

	public function test_defaults_and_devices() {
		$d = Settings::defaults();
		foreach ( array( 'urgent_enabled', 'urgent_desktop', 'urgent_mobile', 'enabled', 'show_on_desktop', 'show_on_mobile' ) as $key ) {
			$this->assertTrue( $d[ $key ], $key . ': every bar on every device, as before.' );
		}
		$this->assertSame(
			array(
				'd' => true,
				'm' => true,
			),
			Urgent::devices( $d )
		);
		$this->assertSame(
			array(
				'd' => true,
				'm' => false,
			),
			Urgent::devices( Settings::sanitize( array( 'urgent_mobile' => false ) ) )
		);
		$this->assertSame(
			array(
				'd' => false,
				'm' => false,
			),
			Urgent::devices( Settings::sanitize( array( 'urgent_enabled' => false ) ) ),
			'Switched off: the device switches play no part.'
		);
		$this->assertFalse(
			Urgent::enabled(
				Settings::sanitize(
					array(
						'urgent_desktop' => false,
						'urgent_mobile'  => false,
					)
				)
			),
			'No device: the same as off.'
		);
		$this->assertTrue( Urgent::enabled( Settings::sanitize( array( 'urgent_desktop' => false ) ) ) );
	}

	public function test_schema_9_keeps_a_site_that_was_all_off_all_off() {
		$this->assertSame( 9, HPRNB_SCHEMA_VERSION );
		$off = Settings::migrate(
			array(
				'enabled'    => false,
				'label_text' => 'DIRECT',
			),
			8
		);
		$this->assertFalse( $off['urgent_enabled'], 'Before 2.16 "enabled" switched every bar off: it still does on that site.' );
		$on = Settings::migrate( array( 'enabled' => true ), 8 );
		$this->assertArrayNotHasKey( 'urgent_enabled', $on, 'A site that was on is left alone.' );
		$this->assertSame( array(), Settings::migrate( array(), 8 ), 'A new site takes the defaults.' );
		$this->assertSame( $off, Settings::migrate( $off, 9 ), 'Once.' );
	}

	public function test_the_urgent_box_follows_the_switches() {
		global $wp_meta_boxes;
		$cases = array(
			'on, both devices'  => array( array(), true ),
			'on, mobile only'   => array( array( 'urgent_desktop' => false ), true ),
			'on, no device'     => array(
				array(
					'urgent_desktop' => false,
					'urgent_mobile'  => false,
				),
				false,
			),
			'off, devices on'   => array( array( 'urgent_enabled' => false ), false ),
			'news bar off only' => array( array( 'enabled' => false ), true ),
		);
		foreach ( $cases as $name => $case ) {
			$this->with_settings( $case[0] );
			$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between cases.
			Post_Controls::add_meta_box();
			$this->assertSame( $case[1], isset( $wp_meta_boxes['post']['side']['high'][ Post_Controls::URGENT_BOX ] ), $name );
		}
	}

	public function test_the_news_bar_off_leaves_the_urgent_bar() {
		$this->create_post_ago( 60, array( 'post_title' => 'Plain headline' ) );
		$this->with_settings( array( 'enabled' => false ) );

		$this->post_queries = 0;
		$page               = $this->front();
		$this->assertSame( 1, $this->post_queries, 'Only the urgent query: the news bar\'s does not run while it is off.' );
		$this->assertStringContainsString( 'data-hprnb-show="urgent"', $page['footer'], 'An empty root waits for an urgent article.' );
		$this->assertStringNotContainsString( 'Plain headline', $page['footer'] );

		$this->urgent_post( 'Off but urgent' );
		$page = $this->front();
		$this->assertStringContainsString( 'hprnb-root--urgent', $page['footer'] );
		$this->assertStringContainsString( 'Off but urgent', $page['footer'] );
		$this->assertSame( 1, substr_count( $page['footer'], '<aside ' ) );

		// The REST body carries the red bar and no headline.
		$data = $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_data();
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( 1, $data['urgent_count'] );

		// Both bars off: nothing at all, no query, an empty REST body.
		$this->with_settings(
			array(
				'enabled'        => false,
				'urgent_enabled' => false,
			)
		);
		$this->post_queries = 0;
		$this->assertSame( '', $this->front()['footer'] );
		$this->assertSame( 0, $this->post_queries );
		$data = $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_data();
		$this->assertSame( array( 0, 0 ), array( $data['count'], $data['urgent_count'] ) );
	}

	public function test_the_urgent_bar_on_one_device_leaves_the_other_to_the_news_bar() {
		$this->create_post_ago( 60 );
		$this->urgent_post();

		// Desktop only: in front there; the phone keeps the news bar, its wait and its height.
		$this->with_settings( array( 'urgent_mobile' => false ) );
		$page = $this->front();
		$this->assertStringContainsString( 'hprnb-root--urgent', $page['footer'] );
		$this->assertStringContainsString( 'hprnb-root--u-no-m', $page['footer'] );
		$this->assertStringContainsString( 'hprnb-root--m-pending', $page['footer'], 'The phone still waits for the paragraph (Continuous reading).' );
		$this->assertStringNotContainsString( 'hprnb-root--u-no-d', $page['footer'] );
		$this->assertSame( 2, substr_count( $page['footer'], '<aside ' ) );
		$this->assertContains( 'hprnb-m-pending', $page['body'] );
		$this->assertNotContains( 'hprnb-d-pending', $page['body'] );
		$this->assertStringContainsString( '--hprnb-height:' . Renderer::urgent_height( Settings::get(), 'd' ) . 'px;--hprnb-m-height:' . Renderer::profile_height( Settings::get(), 'm' ) . 'px', $page['inline'] );
		$this->assertStringContainsString( '--hprnb-m-gap:' . Renderer::mobile_gap( Settings::get() ) . 'px', $page['inline'] );

		// Mobile only: the reverse.
		$this->with_settings( array( 'urgent_desktop' => false ) );
		$page = $this->front();
		$this->assertStringContainsString( 'hprnb-root--u-no-d', $page['footer'] );
		$this->assertStringNotContainsString( 'hprnb-root--m-pending', $page['footer'], 'The red bar waits for nobody on the phone.' );
		$this->assertNotContains( 'hprnb-m-pending', $page['body'] );
		$this->assertStringContainsString( '--hprnb-height:' . Renderer::profile_height( Settings::get(), 'd' ) . 'px;--hprnb-m-height:' . Renderer::urgent_height( Settings::get(), 'm' ) . 'px', $page['inline'] );
		$this->assertStringContainsString( '--hprnb-m-gap:0px', $page['inline'] );
	}

	public function test_a_news_restriction_is_parked_only_where_the_urgent_bar_shows() {
		$this->create_post_ago( 60 );
		$this->urgent_post();
		$no_front = array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'front_page' => false ) );

		// The news bar keeps off phones on the front page; the URGENT bar is desktop only: nothing to park.
		$this->with_settings(
			array(
				'mobile_contexts' => $no_front,
				'urgent_mobile'   => false,
			)
		);
		$this->assertStringContainsString( 'class="hprnb-root hprnb-hide-mobile ', $this->front()['footer'] );

		// The URGENT bar on phones: the restriction is parked while it is in front.
		$this->with_settings(
			array(
				'mobile_contexts' => $no_front,
				'urgent_desktop'  => false,
			)
		);
		$this->assertStringContainsString( 'class="hprnb-root hprnb-news-hide-mobile ', $this->front()['footer'] );

		// A root for the URGENT bar alone takes the URGENT bar's devices.
		$this->with_settings(
			array(
				'display_scope' => 'custom',
				'contexts'      => $no_front,
				'urgent_mobile' => false,
			)
		);
		$footer = $this->front()['footer'];
		$this->assertStringContainsString( 'class="hprnb-root hprnb-hide-mobile ', $footer, 'Nothing on phones: the root is not even shown there.' );
		$this->assertStringContainsString( 'data-hprnb-show="urgent"', $footer );
	}

	public function test_the_bars_card_comes_first_with_its_sub_choices() {
		$this->with_settings( array( 'urgent_enabled' => false ) );
		ob_start();
		Settings_Page::render();
		$page = (string) ob_get_clean();

		$content = substr( $page, strpos( $page, 'data-hprnb-panel="content"' ) );
		$this->assertMatchesRegularExpression( '/^[^§]*?<section class="hprnb-card hprnb-card--bars"/u', $content, 'The first card of the first tab.' );
		foreach ( array( 'urgent-enabled', 'urgent-desktop', 'urgent-mobile', 'enabled', 'show-on-desktop', 'show-on-mobile' ) as $id ) {
			$this->assertSame( 1, substr_count( $page, 'id="hprnb-field-' . $id . '"' ), $id . ' is rendered once, as a switch.' );
		}
		$this->assertStringContainsString( '<label class="hprnb-switch" for="hprnb-field-urgent-enabled"><input type="checkbox" id="hprnb-field-urgent-enabled"', $page );
		// Sub-choices: the script hides them while their bar is off (at load too); without the script
		// every row stays in view. Their value is kept either way.
		$this->assertMatchesRegularExpression( '/<tr class="hprnb-row hprnb-row--switch hprnb-row--sub" data-hprnb-reveal="urgent_enabled">/', $page );
		$this->assertMatchesRegularExpression( '/<tr class="hprnb-row hprnb-row--switch hprnb-row--sub" data-hprnb-reveal="enabled">/', $page );
		$this->assertStringNotContainsString( 'data-hprnb-reveal="urgent_enabled" hidden', $page );
		// Each switch is named by its row title too: "URGENT bar on desktop", not "From 768 px wide." alone.
		$this->assertStringContainsString( '<th scope="row"><label for="hprnb-field-urgent-desktop">URGENT bar on desktop</label></th>', $page );
		$this->assertStringContainsString( '<th scope="row"><label for="hprnb-field-show-on-mobile">Initial bar on mobile</label></th>', $page );
		$this->assertMatchesRegularExpression( '/id="hprnb-field-urgent-desktop" name="hprnb_settings\[urgent_desktop\]" value="1"\s+checked/', $page );
		// No card carries these switches any more.
		$this->assertStringNotContainsString( 'data-hprnb-switch="enabled"', $page );
		$this->assertStringNotContainsString( 'data-hprnb-switch="urgent_enabled"', $page );
		$this->assertStringNotContainsString( 'data-hprnb-switch="show_on_mobile"', $page );
		$this->assertStringNotContainsString( 'data-hprnb-switch="show_on_desktop"', $page );
	}

	public function test_the_news_bar_on_no_device_runs_no_query() {
		$this->create_post_ago( 60, array( 'post_title' => 'Plain headline' ) );
		$this->urgent_post( 'Still urgent' );
		$this->with_settings(
			array(
				'show_on_desktop' => false,
				'show_on_mobile'  => false,
			)
		);

		$this->assertFalse( \HorizonPress\NewsBar\Visibility::news_enabled( Settings::get() ) );
		$this->assertTrue( \HorizonPress\NewsBar\Visibility::news_enabled( Settings::sanitize( array( 'show_on_desktop' => false ) ) ) );
		$this->assertFalse( \HorizonPress\NewsBar\Visibility::news_enabled( Settings::sanitize( array( 'enabled' => false ) ) ) );

		$this->post_queries = 0;
		$page               = $this->front();
		$this->assertSame( 1, $this->post_queries, 'On no device, like off: only the urgent query runs.' );
		$this->assertStringContainsString( 'Still urgent', $page['footer'] );
		$this->assertStringNotContainsString( 'Plain headline', $page['footer'] );

		$data = $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_data();
		$this->assertSame( 0, $data['count'], 'No headline shipped for a bar that shows nowhere.' );
		$this->assertSame( 1, $data['urgent_count'] );
	}

	public function test_the_rest_body_says_where_the_urgent_bar_shows() {
		$this->urgent_post();
		$data = $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_data();
		$this->assertSame(
			array(
				'd' => true,
				'm' => true,
			),
			$data['urgent_devices']
		);

		// A page from a page cache may predate the choice: the bootstrap corrects the root with this.
		$this->with_settings( array( 'urgent_mobile' => false ) );
		$response = $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) );
		$this->assertSame(
			array(
				'd' => true,
				'm' => false,
			),
			$response->get_data()['urgent_devices']
		);
		$etag = $response->get_headers()['ETag'];
		$this->with_settings( array() );
		$this->assertNotSame( $etag, $this->reset_rest_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_headers()['ETag'], 'The ETag follows the switches.' );
	}

	public function test_the_preview_shows_a_news_bar_switched_off() {
		$this->create_post_ago( 60, array( 'post_title' => 'Designed while off' ) );
		foreach ( array(
			array( 'enabled' => '0' ),
			array(
				'show_on_desktop' => '0',
				'show_on_mobile'  => '0',
			),
		) as $form ) {
			$request = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
			$request->set_body_params( array( 'settings' => $form ) );
			$data = $this->reset_rest_server()->dispatch( $request )->get_data();
			$this->assertSame( 1, $data['count'], 'Its design can be prepared before it is switched on.' );
			$this->assertStringContainsString( 'Designed while off', $data['html'] );
		}
	}

	public function test_the_urgent_box_names_the_devices() {
		$post_id = $this->create_post_ago( 60 );
		$cases   = array(
			'on phones and desktops.' => array(),
			'on phones only.'         => array( 'urgent_desktop' => false ),
			'on desktops only.'       => array( 'urgent_mobile' => false ),
		);
		foreach ( $cases as $end => $settings ) {
			$this->with_settings( $settings );
			ob_start();
			Post_Controls::render_urgent_box( get_post( $post_id ) );
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'instead of the news bar, ' . $end, $html );
			$this->assertStringNotContainsString( 'every device', $html );
		}
	}

	public function test_the_news_face_is_a_choice() {
		$this->assertContains( 'hprnb-root--font-news', Renderer::root_classes( Settings::get() ), 'The news face by default, on both bars.' );
		$this->assertNotContains( 'hprnb-root--font-news', Renderer::root_classes( Settings::sanitize( array( 'bar_font' => 'theme' ) ) ), 'The theme\'s font when chosen.' );
		$this->assertSame( 'news', Settings::sanitize( array( 'bar_font' => 'comic' ) )['bar_font'] );
	}

	public function test_the_preview_root_is_never_hidden_by_the_admin_window() {
		$phones_only = Settings::sanitize( array( 'show_on_desktop' => false ) );
		$this->assertContains( 'hprnb-hide-desktop', Renderer::root_classes( $phones_only ), 'On the site, the device class hides it from 768px.' );
		$preview = Renderer::root_classes( $phones_only, true );
		$this->assertContains( 'hprnb-device-all', $preview, 'In the preview the frame is the device.' );
		$this->assertNotContains( 'hprnb-hide-desktop', $preview );
	}
}
