<?php
/**
 * Security: capability and nonce enforcement on the admin-post handlers.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Import_Export;
use HorizonPress\NewsBar\Admin\Preview;
use HorizonPress\NewsBar\Settings;

class Security_Test extends HPRNB_Test_Case {

	public function set_up() {
		parent::set_up();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function expect_die_with_status( int $status ): void {
		add_filter(
			'wp_die_handler',
			static function () use ( $status ) {
				return static function ( $message, $title = '', $args = array() ) use ( $status ) {
					$code = isset( $args['response'] ) ? (int) $args['response'] : ( is_int( $args ) ? $args : 200 );
					throw new WPDieException( 'die:' . $code );
				};
			}
		);
		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'die:' . $status );
	}

	public function test_export_refuses_anonymous_users() {
		wp_set_current_user( 0 );
		$this->expect_die_with_status( 403 );
		Import_Export::export();
	}

	public function test_export_refuses_users_without_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expect_die_with_status( 403 );
		Import_Export::export();
	}

	public function test_import_refuses_missing_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'invalid';
		$this->expect_die_with_status( 403 );
		Import_Export::import();
	}

	public function test_reset_refuses_missing_nonce_and_keeps_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'max_items' => 3 ) );
		try {
			$this->expect_die_with_status( 403 );
			Import_Export::reset();
		} finally {
			$this->assertSame( 3, Settings::raw()['max_items'] );
		}
	}

	public function test_reset_refuses_subscribers_even_with_a_valid_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce']         = wp_create_nonce( 'hprnb_reset' );
		$_POST['hprnb_reset_confirm'] = '1';
		$this->expect_die_with_status( 403 );
		Import_Export::reset();
	}

	public function test_preview_permission_callback() {
		wp_set_current_user( 0 );
		$this->assertFalse( Preview::permission() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( Preview::permission() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( Preview::permission() );
	}

	public function test_public_rest_items_never_exposes_private_content() {
		$this->create_post_ago( 60, array( 'post_title' => 'Public one' ) );
		$this->create_post_ago(
			60,
			array(
				'post_title'  => 'Private secret',
				'post_status' => 'private',
			)
		);
		$this->create_post_ago(
			60,
			array(
				'post_title'  => 'Draft secret',
				'post_status' => 'draft',
			)
		);
		$this->create_post_ago(
			60,
			array(
				'post_title'    => 'Locked secret',
				'post_password' => 'pw',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/items' ) )->get_data();
		$this->assertSame( 1, $data['count'] );
		$this->assertStringContainsString( 'Public one', $data['html'] );
		$this->assertStringNotContainsString( 'secret', $data['html'] );
	}
}
