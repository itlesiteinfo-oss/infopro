<?php
/**
 * Activation, deactivation, uninstall.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Activator;
use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Settings;

class Lifecycle_Test extends HPRNB_Test_Case {

	public function test_activation_is_idempotent_and_keeps_existing_settings() {
		Settings::update( array( 'max_items' => 3 ) );
		Activator::activate( false );
		$this->assertSame( 3, Settings::raw()['max_items'] );
	}

	public function test_deactivation_keeps_settings_and_rotates_epoch() {
		Settings::update( array( 'max_items' => 3 ) );
		\HorizonPress\NewsBar\Invalidation::reset_guard();
		$before = Cache::epoch();
		Activator::deactivate();
		$this->assertSame( 3, Settings::raw()['max_items'] );
		$this->assertNotSame( $before, Cache::epoch() );
	}

	public function test_requirements_are_met_in_this_environment() {
		$this->assertTrue( Activator::requirements_met() );
		$this->assertTrue( hprnb_is_compatible() );
	}

	public function test_uninstall_respects_the_flag() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', HPRNB_BASENAME );
		}
		$uninstall = HPRNB_PATH . 'uninstall.php';

		// Flag off: everything stays.
		require_once $uninstall;
		$this->assertIsArray( get_option( Settings::OPTION ) );
		$this->assertNotFalse( get_option( Settings::EPOCH_OPTION ) );

		// Flag on: the three options are removed, posts untouched.
		$post_id = $this->create_post_ago( 60 );
		Settings::update( array( 'uninstall_delete_data' => true ) );
		update_option( Settings::SCHEMA_OPTION, '1', true );
		hprnb_uninstall_site();
		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Settings::EPOCH_OPTION ) );
		$this->assertFalse( get_option( Settings::SCHEMA_OPTION ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $post_id ) );
	}
}
