<?php
/**
 * Settings schema, sanitization and storage.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Settings;

class Settings_Test extends HPRNB_Test_Case {

	public function test_defaults_match_the_specification() {
		$expected = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/defaults.json' ), true );
		$this->assertSame( $expected, Settings::defaults() );
	}

	public function test_schema_covers_every_default_and_has_valid_descriptors() {
		foreach ( Settings::schema() as $key => $descriptor ) {
			$this->assertArrayHasKey( 'type', $descriptor, $key );
			$this->assertArrayHasKey( 'default', $descriptor, $key );
			if ( 'int' === $descriptor['type'] ) {
				$this->assertLessThanOrEqual( $descriptor['max'], $descriptor['default'], $key );
				$this->assertGreaterThanOrEqual( $descriptor['min'], $descriptor['default'], $key );
			}
			if ( 'enum' === $descriptor['type'] ) {
				$this->assertContains( $descriptor['default'], $descriptor['options'], $key );
			}
		}
	}

	public function test_unknown_keys_are_ignored() {
		$clean = Settings::sanitize( array( 'evil' => 'x', 'enabled' => false ) );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertFalse( $clean['enabled'] );
		$this->assertSame( array_keys( Settings::defaults() ), array_keys( $clean ) );
	}

	public function test_boolean_sanitization() {
		foreach ( array( '1', 'true', 'on', 'yes', 1, true ) as $truthy ) {
			$this->assertTrue( Settings::sanitize( array( 'ticker_enabled' => $truthy ) )['ticker_enabled'], var_export( $truthy, true ) );
		}
		foreach ( array( '0', 'false', '', 0, false, 'nope' ) as $falsy ) {
			$this->assertFalse( Settings::sanitize( array( 'ticker_enabled' => $falsy ) )['ticker_enabled'], var_export( $falsy, true ) );
		}
		$this->assertFalse( Settings::sanitize( array( 'enabled' => array( 'x' ) ) )['enabled'] === array( 'x' ) );
	}

	public function test_integer_bounds_and_fallbacks() {
		$this->assertSame( 1, Settings::sanitize( array( 'max_items' => 0 ) )['max_items'] );
		$this->assertSame( 30, Settings::sanitize( array( 'max_items' => 999 ) )['max_items'] );
		$this->assertSame( 10, Settings::sanitize( array( 'max_items' => 'abc' ) )['max_items'] );
		$this->assertSame( 10, Settings::sanitize( array( 'font_size' => 5 ) )['font_size'] );
		$this->assertSame( 24, Settings::sanitize( array( 'font_size' => '99' ) )['font_size'] );
		$this->assertSame( 30, Settings::sanitize( array( 'cache_ttl' => 1 ) )['cache_ttl'] );
		$this->assertSame( 600, Settings::sanitize( array( 'cache_ttl' => 10000 ) )['cache_ttl'] );
		$this->assertSame( 30, Settings::sanitize( array( 'stale_threshold' => 0 ) )['stale_threshold'] );
	}

	public function test_window_value_is_bounded_by_its_unit() {
		$clean = Settings::sanitize( array( 'window_unit' => 'minutes', 'window_value' => 2000 ) );
		$this->assertSame( 1440, $clean['window_value'] );

		$clean = Settings::sanitize( array( 'window_unit' => 'days', 'window_value' => 60 ) );
		$this->assertSame( 30, $clean['window_value'] );

		$clean = Settings::sanitize( array( 'window_unit' => 'hours', 'window_value' => 800 ) );
		$this->assertSame( 720, $clean['window_value'] );

		$clean = Settings::sanitize( array( 'window_unit' => 'weeks', 'window_value' => 3 ) );
		$this->assertSame( 'hours', $clean['window_unit'] );
		$this->assertSame( 3, $clean['window_value'] );

		$clean = Settings::sanitize( array( 'window_value' => 0 ) );
		$this->assertSame( 1, $clean['window_value'] );
	}

	public function test_enum_fallback_to_default() {
		$this->assertSame( 'date_desc', Settings::sanitize( array( 'orderby' => 'rand' ) )['orderby'] );
		$this->assertSame( 'date_asc', Settings::sanitize( array( 'orderby' => 'date_asc' ) )['orderby'] );
		$this->assertSame( 'hybrid', Settings::sanitize( array( 'render_mode' => 'rest' ) )['render_mode'] );
		$this->assertSame( 'marquee', Settings::sanitize( array( 'ticker_mode' => 'MARQUEE' ) )['ticker_mode'] );
	}

	public function test_color_sanitization() {
		$this->assertSame( '#abc', Settings::sanitize( array( 'bg_color' => '#abc' ) )['bg_color'] );
		$this->assertSame( '#123456', Settings::sanitize( array( 'bg_color' => ' #123456 ' ) )['bg_color'] );
		$this->assertSame( '#1B1C20', Settings::sanitize( array( 'bg_color' => 'red' ) )['bg_color'] );
		$this->assertSame( '#1B1C20', Settings::sanitize( array( 'bg_color' => '#GGGGGG' ) )['bg_color'] );
		$this->assertSame( '#1B1C20', Settings::sanitize( array( 'bg_color' => 'expression(alert(1))' ) )['bg_color'] );
		$this->assertSame( '#F5F5F5', Settings::sanitize( array( 'text_color' => array( '#000' ) ) )['text_color'] );
	}

	public function test_label_text_is_stripped_and_bounded() {
		$clean = Settings::sanitize( array( 'label_text' => '<img src=x onerror=alert(1)>Breaking <b>news</b>' ) );
		$this->assertStringNotContainsString( '<', $clean['label_text'] );
		$this->assertStringContainsString( 'Breaking', $clean['label_text'] );
		$this->assertSame( '', Settings::sanitize( array( 'label_text' => '' ) )['label_text'] );
		$this->assertSame( 120, mb_strlen( Settings::sanitize( array( 'label_text' => str_repeat( 'a', 200 ) ) )['label_text'] ) );
	}

	public function test_separator_never_empty() {
		$this->assertSame( '•', Settings::sanitize( array( 'separator_char' => '' ) )['separator_char'] );
		$this->assertSame( '|', Settings::sanitize( array( 'separator_char' => ' | ' ) )['separator_char'] );
		$this->assertSame( 8, mb_strlen( Settings::sanitize( array( 'separator_char' => '——————————' ) )['separator_char'] ) );
	}

	public function test_id_lists_are_parsed_deduplicated_and_capped() {
		$this->assertSame( array( 12, 34, 5 ), Settings::sanitize( array( 'content_exclude_post_ids' => '12, 34,abc,0,-5, 34' ) )['content_exclude_post_ids'] );
		$this->assertSame( array( 3, 4 ), Settings::sanitize( array( 'categories_include' => array( '3', 4, '4', 'x' ) ) )['categories_include'] );
		$this->assertSame( array(), Settings::sanitize( array( 'tags_include' => new stdClass() ) )['tags_include'] );
		$this->assertCount( 500, Settings::sanitize( array( 'display_exclude_ids' => range( 1, 600 ) ) )['display_exclude_ids'] );
	}

	public function test_thumbnail_size_key() {
		$this->assertSame( 'medium', Settings::sanitize( array( 'thumbnail_size' => 'Medium' ) )['thumbnail_size'] );
		$this->assertSame( 'thumbnail', Settings::sanitize( array( 'thumbnail_size' => '' ) )['thumbnail_size'] );
		$this->assertSame( 'thumbnail', Settings::sanitize( array( 'thumbnail_size' => array() ) )['thumbnail_size'] );
	}

	public function test_contexts_map_semantics() {
		$clean = Settings::sanitize( array( 'contexts' => array( 'search' => '0', 'bogus' => '1' ) ) );
		$this->assertFalse( $clean['contexts']['search'] );
		$this->assertTrue( $clean['contexts']['front_page'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['contexts'] );
		$this->assertSame( Settings::CONTEXT_KEYS, array_keys( $clean['contexts'] ) );

		$form = Settings::sanitize_form( array( 'contexts' => array( 'search' => '1' ) ) );
		$this->assertTrue( $form['contexts']['search'] );
		$this->assertFalse( $form['contexts']['front_page'] );

		$form = Settings::sanitize_form( array() );
		$this->assertFalse( $form['contexts']['page'] );
	}

	public function test_sanitize_form_treats_missing_checkboxes_as_false_and_missing_lists_as_empty() {
		$form = Settings::sanitize_form( array( 'label_text' => 'X' ) );
		$this->assertFalse( $form['enabled'] );
		$this->assertFalse( $form['show_on_desktop'] );
		$this->assertSame( array(), $form['categories_include'] );
		$this->assertSame( 24, $form['window_value'] );
		$this->assertSame( 'X', $form['label_text'] );

		$plain = Settings::sanitize( array( 'label_text' => 'X' ) );
		$this->assertTrue( $plain['enabled'] );
	}

	public function test_sanitize_is_idempotent() {
		$once  = Settings::sanitize( array( 'max_items' => '7', 'bg_color' => '#abc', 'contexts' => array( 'tag' => 0 ) ) );
		$twice = Settings::sanitize( $once );
		$this->assertSame( $once, $twice );
	}

	public function test_get_applies_filter_and_resanitizes() {
		add_filter(
			'hprnb_settings',
			static function ( $settings ) {
				$settings['max_items'] = 9999;
				$settings['label_text'] = 'Filtered';
				return $settings;
			}
		);
		$settings = Settings::get();
		$this->assertSame( 30, $settings['max_items'] );
		$this->assertSame( 'Filtered', $settings['label_text'] );
		$this->assertSame( 10, Settings::raw()['max_items'] );
	}

	public function test_update_and_reset() {
		Settings::update( array( 'max_items' => 3 ) );
		$this->assertSame( 3, Settings::get()['max_items'] );
		$this->assertTrue( Settings::get()['enabled'] );

		Settings::reset();
		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION ) );
	}

	public function test_corrupted_option_falls_back_to_defaults() {
		update_option( Settings::OPTION, 'garbage', true );
		Settings::flush();
		$this->assertSame( Settings::defaults(), Settings::get() );
	}

	/**
	 * 2.4.0 rendered every "contexts" field under the name of the global scope, so the two per-profile
	 * page-type maps never reached the form handler and a single save hid the bar everywhere.
	 */
	public function test_every_bool_map_field_posts_under_its_own_name() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();

		ob_start();
		\HorizonPress\NewsBar\Admin\Settings_Page::render();
		$html = (string) ob_get_clean();

		foreach ( array( 'contexts', 'desktop_contexts', 'mobile_contexts' ) as $map_key ) {
			foreach ( Settings::CONTEXT_KEYS as $context ) {
				$this->assertStringContainsString( 'name="hprnb_settings[' . $map_key . '][' . $context . ']"', $html, $map_key . '/' . $context );
			}
		}
		$this->assertSame( count( Settings::CONTEXT_KEYS ), substr_count( $html, 'name="hprnb_settings[contexts][' ), 'One list for the global scope, not three.' );

		// What the browser posts from that form (every ticked box) keeps every type ticked.
		preg_match_all( '/<input type="checkbox"[^>]*name="hprnb_settings\[([a-z_]+)\](?:\[([a-z_]+)\])?"[^>]*checked/', $html, $m, PREG_SET_ORDER );
		$post = array();
		foreach ( $m as $match ) {
			if ( '' !== ( $match[2] ?? '' ) ) {
				$post[ $match[1] ][ $match[2] ] = '1';
			} else {
				$post[ $match[1] ] = '1';
			}
		}
		$this->assertSame( '1', $post['show_on_desktop'] ?? null, 'The device switch is among the ticked boxes.' );
		$clean = Settings::sanitize_form( $post );
		$this->assertSame( array_fill_keys( Settings::CONTEXT_KEYS, true ), $clean['desktop_contexts'] );
		$this->assertSame( array_fill_keys( Settings::CONTEXT_KEYS, true ), $clean['mobile_contexts'] );
		$this->assertTrue( \HorizonPress\NewsBar\Visibility::devices_for_context( $clean )['desktop'] );
	}

	public function test_schema_4_repairs_the_maps_emptied_by_the_2_4_0_form() {
		$broken                     = Settings::defaults();
		$broken['desktop_contexts'] = array_fill_keys( Settings::CONTEXT_KEYS, false );
		$broken['mobile_contexts']  = array_fill_keys( Settings::CONTEXT_KEYS, false );
		$broken['contexts']         = array_merge( array_fill_keys( Settings::CONTEXT_KEYS, true ), array( 'search' => false ) );
		update_option( Settings::OPTION, $broken );
		update_option( Settings::SCHEMA_OPTION, '3' );
		Settings::flush();

		Settings::maybe_upgrade();

		$repaired = Settings::get();
		$this->assertSame( array_fill_keys( Settings::CONTEXT_KEYS, true ), $repaired['desktop_contexts'] );
		$this->assertSame( array_fill_keys( Settings::CONTEXT_KEYS, true ), $repaired['mobile_contexts'] );
		$this->assertFalse( $repaired['contexts']['search'], 'The global scope, which the form saved correctly, is left alone.' );
		$this->assertSame( '4', get_option( Settings::SCHEMA_OPTION ) );

		// A deliberate partial map is not a symptom of the bug: it stays.
		$chosen                     = Settings::defaults();
		$chosen['mobile_contexts']  = array_merge( array_fill_keys( Settings::CONTEXT_KEYS, false ), array( 'single_post' => true ) );
		update_option( Settings::OPTION, $chosen );
		update_option( Settings::SCHEMA_OPTION, '3' );
		Settings::flush();
		Settings::maybe_upgrade();
		$this->assertSame( $chosen['mobile_contexts'], Settings::get()['mobile_contexts'] );
	}

	public function test_uninstall_flag() {
		$this->assertFalse( Settings::uninstall_delete_requested() );
		Settings::update( array( 'uninstall_delete_data' => true ) );
		$this->assertTrue( Settings::uninstall_delete_requested() );
	}
}
