<?php
/**
 * REST endpoints: public items (ETag/304) and private preview.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Rest_Controller;
use HorizonPress\NewsBar\Settings;

class Rest_Test extends HPRNB_Test_Case {

	private function items_request( array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/hprnb/v1/items' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/hprnb/v1/items', $routes );
		$this->assertArrayHasKey( '/hprnb/v1/preview', $routes );
		$this->assertSame( '__return_true', $routes['/hprnb/v1/items'][0]['permission_callback'] );
	}

	public function test_items_200_with_headers() {
		$this->create_post_ago( 60, array( 'post_title' => 'REST one' ) );
		$response = $this->items_request();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'version', 'generated_at', 'count', 'html', 'urgent_count', 'urgent_html' ), array_keys( $data ), '2.14: the urgent bar rides in the same body.' );
		$this->assertSame( 0, $data['urgent_count'] );
		$this->assertSame( '', $data['urgent_html'] );
		$this->assertSame( HPRNB_VERSION, $data['version'] );
		$this->assertIsInt( $data['generated_at'] );
		$this->assertSame( 1, $data['count'] );
		$this->assertStringContainsString( 'REST one', $data['html'] );

		$headers = $response->get_headers();
		$this->assertSame( Rest_Controller::CACHE_CONTROL, $headers['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^"[0-9a-f]{32}"$/', $headers['ETag'] );
		$this->assertSame( gmdate( 'D, d M Y H:i:s', $data['generated_at'] ) . ' GMT', $headers['Last-Modified'] );
	}

	public function test_items_count_zero() {
		$data = $this->items_request()->get_data();
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( '', $data['html'] );
	}

	public function test_items_disabled_plugin_returns_empty() {
		$this->create_post_ago( 60 );
		$this->with_settings( array( 'enabled' => false ) );
		$data = $this->items_request()->get_data();
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( '', $data['html'] );
	}

	public function test_items_uses_the_same_cache_as_ssr() {
		$this->create_post_ago( 60 );
		$this->post_queries = 0;
		$this->items_request();
		$this->assertSame( 2, $this->post_queries, 'Headlines and urgent articles (2.14).' );
		$this->assertNotNull( Cache::get( Settings::get() ) );

		\HorizonPress\NewsBar\Payload::flush();
		$this->post_queries = 0;
		$this->items_request();
		$this->assertSame( 0, $this->post_queries );
	}

	public function test_etag_304() {
		$this->create_post_ago( 60 );
		$first = $this->items_request();
		$etag  = $first->get_headers()['ETag'];

		$second = $this->items_request( array( 'If-None-Match' => $etag ) );
		$this->assertSame( 304, $second->get_status() );
		$this->assertNull( $second->get_data() );
		$this->assertSame( $etag, $second->get_headers()['ETag'] );
		$this->assertTrue( has_filter( 'rest_pre_serve_request', array( Rest_Controller::class, 'serve_not_modified' ) ) !== false );
		$this->assertTrue( Rest_Controller::serve_not_modified( false, $second ) );
		remove_filter( 'rest_pre_serve_request', array( Rest_Controller::class, 'serve_not_modified' ) );

		$weak = $this->items_request( array( 'If-None-Match' => 'W/' . $etag ) );
		$this->assertSame( 304, $weak->get_status() );
		remove_filter( 'rest_pre_serve_request', array( Rest_Controller::class, 'serve_not_modified' ) );

		$list = $this->items_request( array( 'If-None-Match' => '"other", ' . $etag ) );
		$this->assertSame( 304, $list->get_status() );
		remove_filter( 'rest_pre_serve_request', array( Rest_Controller::class, 'serve_not_modified' ) );

		$miss = $this->items_request( array( 'If-None-Match' => '"nope"' ) );
		$this->assertSame( 200, $miss->get_status() );
		$this->assertFalse( has_filter( 'rest_pre_serve_request', array( Rest_Controller::class, 'serve_not_modified' ) ) );
		$this->assertFalse( Rest_Controller::serve_not_modified( false, $miss ) );
	}

	public function test_preview_requires_manage_options() {
		$request = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$request->set_body_params( array( 'settings' => array( 'enabled' => '1' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_preview_renders_unsaved_settings_without_writing_or_caching() {
		$this->create_post_ago( 60, array( 'post_title' => 'Preview headline' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$request->set_body_params( array( 'settings' => array( 'label_text' => 'PREVIEW LABEL', 'enabled' => '1', 'window_value' => '24', 'window_unit' => 'hours', 'max_items' => '10' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['count'] );
		$this->assertStringContainsString( 'PREVIEW LABEL', $data['html'] );
		$this->assertStringContainsString( 'Preview headline', $data['html'] );
		$this->assertStringContainsString( '--hprnb-bg:', $data['style'] );
		$this->assertStringContainsString( 'no-store', $response->get_headers()['Cache-Control'] );

		$this->assertSame( 'EN CONTINU', Settings::raw()['label_text'], 'Preview never writes settings.' );
		$previewed = Settings::sanitize_form( array( 'label_text' => 'PREVIEW LABEL', 'enabled' => '1', 'window_value' => '24', 'window_unit' => 'hours', 'max_items' => '10' ) );
		$this->assertFalse( get_transient( Cache::key( $previewed ) ), 'Preview never writes the public cache.' );

		$empty = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$empty->set_body_params( array( 'settings' => array( 'window_value' => '1', 'window_unit' => 'minutes', 'content_exclude_post_ids' => implode( ',', wp_list_pluck( get_posts( array( 'numberposts' => -1 ) ), 'ID' ) ) ) ) );
		$data = rest_get_server()->dispatch( $empty )->get_data();
		$this->assertSame( 0, $data['count'] );
		$this->assertSame( '', $data['html'] );

		$missing = new WP_REST_Request( 'POST', '/hprnb/v1/preview' );
		$this->assertSame( 400, rest_get_server()->dispatch( $missing )->get_status() );
	}
}
