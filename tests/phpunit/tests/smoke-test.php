<?php
/**
 * Smoke tests: the plugin loads, activates and renders.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Activator;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

/**
 * @group smoke
 */
class Smoke_Test extends HPRNB_Test_Case {

	public function test_plugin_constants_and_classes() {
		$this->assertSame( '2.0.0', HPRNB_VERSION );
		$this->assertTrue( class_exists( Settings::class ) );
		$this->assertTrue( class_exists( \HorizonPress\NewsBar\Admin\Settings_Page::class ) );
		$this->assertTrue( has_action( 'wp_footer', array( \HorizonPress\NewsBar\Frontend::class, 'footer' ) ) !== false );
	}

	public function test_activation_creates_options_with_boolean_autoload() {
		delete_option( Settings::OPTION );
		delete_option( Settings::EPOCH_OPTION );
		delete_option( Settings::SCHEMA_OPTION );

		Activator::activate( false );

		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', get_option( Settings::EPOCH_OPTION ) );
		$this->assertSame( '2', get_option( Settings::SCHEMA_OPTION ) );

		$autoloaded = wp_load_alloptions();
		$this->assertArrayHasKey( Settings::OPTION, $autoloaded );
		$this->assertArrayHasKey( Settings::EPOCH_OPTION, $autoloaded );
	}

	public function test_default_scenario_renders_recent_post_only() {
		$recent = $this->create_post_ago( HOUR_IN_SECONDS, array( 'post_title' => 'Recent headline' ) );
		$old    = $this->create_post_ago( 25 * HOUR_IN_SECONDS, array( 'post_title' => 'Old headline' ) );

		$payload = Payload::get( Settings::get() );

		$this->assertSame( 1, $payload['count'] );
		$this->assertSame( $recent, $payload['items'][0]['id'] );
		$this->assertStringContainsString( 'Recent headline', $payload['html'] );
		$this->assertStringNotContainsString( 'Old headline', $payload['html'] );
		$this->assertStringStartsWith( '<aside class="hprnb-bar', $payload['html'] );
		$this->assertStringContainsString( 'aria-live="off"', $payload['html'] );
		$this->assertStringContainsString( 'EN CONTINU', $payload['html'] );
		$this->assertNotSame( $old, $payload['items'][0]['id'] );
	}

	public function test_rest_items_endpoint_returns_payload() {
		$this->create_post_ago( 600, array( 'post_title' => 'REST headline' ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['count'] );
		$this->assertStringContainsString( 'REST headline', $data['html'] );
		$this->assertArrayNotHasKey( 'items', $data );
		$headers = $response->get_headers();
		$this->assertSame( 'public, max-age=60, s-maxage=60, stale-while-revalidate=120', $headers['Cache-Control'] );
	}

	public function test_root_markup_is_empty_and_hidden_without_items() {
		$payload = Payload::get( Settings::get() );
		$this->assertSame( 0, $payload['count'] );
		$this->assertSame( '', $payload['html'] );

		$root = Renderer::root( $payload, Settings::get() );
		$this->assertStringContainsString( 'id="hprnb-root"', $root );
		$this->assertStringContainsString( 'data-hprnb-empty="1"', $root );
		$this->assertStringContainsString( ' hidden>', $root );
		$this->assertStringNotContainsString( '<aside', $root );
	}
}
