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

	public function test_2_7_reveal_paragraph_and_article_collapse_are_sanitised() {
		// The detailed values only count with the "Custom" behaviour (2.9.0).
		$clean = Settings::sanitize( array_merge( Settings::defaults(), array( 'mobile_behavior' => 'custom', 'desktop_behavior' => 'custom', 'mobile_reveal_mode' => 'paragraph', 'mobile_reveal_paragraph' => 4, 'desktop_reveal_mode' => 'scroll', 'desktop_collapse_mode' => 'article', 'mobile_collapse_mode' => 'article' ) ) );
		$this->assertSame( 'paragraph', $clean['mobile_reveal_mode'] );
		$this->assertSame( 'scroll', $clean['desktop_reveal_mode'], 'Each device keeps its own.' );
		$this->assertSame( 4, $clean['mobile_reveal_paragraph'] );
		$this->assertSame( 'article', $clean['desktop_collapse_mode'] );
		$this->assertSame( 'article', $clean['mobile_collapse_mode'] );

		foreach ( array( 'mobile_', 'desktop_' ) as $prefix ) {
			$this->assertSame( 2, Settings::defaults()[ $prefix . 'reveal_paragraph' ], 'The second-to-last paragraph by default.' );
			$this->assertSame( 1, Settings::sanitize( array( $prefix . 'reveal_paragraph' => -3 ) )[ $prefix . 'reveal_paragraph' ] );
			$this->assertSame( 30, Settings::sanitize( array( $prefix . 'reveal_paragraph' => 500 ) )[ $prefix . 'reveal_paragraph' ] );
			$this->assertSame( 2, Settings::sanitize( array( $prefix . 'reveal_paragraph' => 'many' ) )[ $prefix . 'reveal_paragraph' ], 'Garbage falls back to the default.' );
			$this->assertSame( 'immediate', Settings::defaults()[ $prefix . 'reveal_mode' ], 'Nothing waits by default.' );
		}
		$this->assertArrayNotHasKey( 'reveal_mode', Settings::defaults(), 'The single setting is gone: each device decides.' );
	}

	/**
	 * 2.8.0: when the bar appears is decided per device. A site that had tuned the single setting
	 * keeps exactly that behaviour on both devices, and the mobile design is left alone.
	 */
	public function test_schema_5_splits_the_reveal_per_device() {
		$old = array_merge( Settings::defaults(), array( 'reveal_mode' => 'paragraph', 'reveal_value' => 700, 'reveal_paragraph' => 4, 'mobile_layout' => 'flow', 'mobile_lines' => 2 ) );
		foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
			unset( $old[ $prefix . 'reveal_mode' ], $old[ $prefix . 'reveal_value' ], $old[ $prefix . 'reveal_paragraph' ] );
		}
		unset( $old['mobile_card_float'] );
		// Keys born after schema 4 are not in a schema-4 option.
		unset( $old['desktop_behavior'], $old['mobile_behavior'], $old['desktop_next_hide'], $old['mobile_next_hide'] );
		update_option( Settings::OPTION, $old );
		update_option( Settings::SCHEMA_OPTION, '4' );
		Settings::flush();

		Settings::maybe_upgrade();

		$now = Settings::get();
		foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
			$this->assertSame( 'paragraph', $now[ $prefix . 'reveal_mode' ], $prefix . 'inherits the mode.' );
			$this->assertSame( 700, $now[ $prefix . 'reveal_value' ] );
			$this->assertSame( 4, $now[ $prefix . 'reveal_paragraph' ] );
		}
		$this->assertArrayNotHasKey( 'reveal_mode', $now );
		$this->assertSame( 'flow', $now['mobile_layout'], 'The design a site chose is never switched under it.' );
		$this->assertSame( 2, $now['mobile_lines'] );
		$this->assertFalse( $now['mobile_card_float'], 'The new key takes its default.' );
		$this->assertSame( (string) HPRNB_SCHEMA_VERSION, get_option( Settings::SCHEMA_OPTION ) );

		// The migration is pure: the same input gives the same output without touching the option.
		$twice = Settings::migrate( $old, 4 );
		$this->assertSame( 'paragraph', $twice['mobile_reveal_mode'] );
		$this->assertArrayNotHasKey( 'reveal_paragraph', $twice );
		// And a site that had already set a device keeps that device's choice.
		$mixed = Settings::migrate( array( 'reveal_mode' => 'smart', 'mobile_reveal_mode' => 'scroll' ), 4 );
		$this->assertSame( 'scroll', $mixed['mobile_reveal_mode'] );
		$this->assertSame( 'smart', $mixed['desktop_reveal_mode'] );
		// Nothing to migrate leaves the array untouched.
		$this->assertSame( array( 'label_text' => 'x' ), Settings::migrate( array( 'label_text' => 'x' ), HPRNB_SCHEMA_VERSION ) );
	}

	/**
	 * Until 2.8.0 an older export skipped every migration: import sanitised the file as-is, so a
	 * 2.7 export restored on 2.8 would have silently lost its reveal mode.
	 */
	public function test_import_migrates_an_older_export() {
		$export = array(
			'_meta'    => array( 'plugin' => 'horizon-press-news-bar', 'schema_version' => 4, 'plugin_version' => '2.7.0' ),
			'settings' => array_merge( Settings::defaults(), array( 'reveal_mode' => 'smart', 'reveal_value' => 900 ) ),
		);
		foreach ( array( 'desktop_', 'mobile_' ) as $prefix ) {
			unset( $export['settings'][ $prefix . 'reveal_mode' ], $export['settings'][ $prefix . 'reveal_value' ], $export['settings'][ $prefix . 'reveal_paragraph' ] );
			unset( $export['settings'][ $prefix . 'behavior' ], $export['settings'][ $prefix . 'next_hide' ] );
		}
		$json = wp_json_encode( $export );
		$this->assertIsString( $json );
		$result = \HorizonPress\NewsBar\Admin\Import_Export::import_json( $json );
		$this->assertContains( $result, array( 'imported', 'imported_ids' ), 'The older export is accepted.' );
		$this->assertSame( 'smart', Settings::get()['mobile_reveal_mode'] );
		$this->assertSame( 'smart', Settings::get()['desktop_reveal_mode'] );
		$this->assertSame( 900, Settings::get()['desktop_reveal_value'] );
	}

	/**
	 * A field rendered twice posts twice, and the last input wins silently. 2.6.0 listed the two
	 * device switches both as card toggles and as rows of the Where tab.
	 */
	public function test_no_setting_input_is_rendered_twice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();
		self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		ob_start();
		\HorizonPress\NewsBar\Admin\Settings_Page::render();
		$html = (string) ob_get_clean();

		// Radios legitimately share a name; everything else must appear exactly once.
		preg_match_all( '/<(?:input type="(?:text|number|checkbox)"|select)[^>]*name="(hprnb_settings\[[^"]+\])"/', $html, $m );
		$counts = array_count_values( $m[1] );
		$dupes  = array_keys( array_filter( $counts, static fn( $c ) => $c > 1 ) );
		$this->assertSame( array(), $dupes, 'Rendered more than once: ' . implode( ', ', $dupes ) );
		$this->assertSame( 1, $counts['hprnb_settings[show_on_desktop]'] ?? 0 );
		$this->assertSame( 1, $counts['hprnb_settings[show_on_mobile]'] ?? 0 );
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

	/**
	 * Until 2.6.0 the desktop font size was declared as a field but listed in no card, so it landed
	 * in the "other" safety-net panel — which the tab script hides on every key, making the setting
	 * unreachable. Worse, sanitize_form() resets an absent key to its default, so a page that stops
	 * rendering a field silently resets it on the next save. Every key must be on a tab.
	 */
	public function test_every_setting_is_reachable_from_the_form() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();
		// A term picker with nothing to pick renders no input at all, so give it one of each.
		self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );

		ob_start();
		\HorizonPress\NewsBar\Admin\Settings_Page::render();
		$html = (string) ob_get_clean();

		$panels = array();
		if ( preg_match_all( '/data-hprnb-panel="([a-z_-]+)"/', $html, $m ) ) {
			$panels = array_unique( $m[1] );
		}
		$this->assertNotContains( 'other', $panels, 'No field may fall into the hidden safety-net panel.' );

		$missing = array();
		foreach ( array_keys( Settings::defaults() ) as $key ) {
			if ( ! str_contains( $html, 'name="hprnb_settings[' . $key . ']' ) ) {
				$missing[] = $key;
			}
		}
		$this->assertSame( array(), $missing, 'Every setting is rendered by some card: ' . implode( ', ', $missing ) );

		// And a form that posts every rendered control round-trips to the same settings.
		$this->assertContains( 'where', $panels, 'The page-type tab exists under its own key.' );
		$this->assertContains( 'mobile', $panels, 'Each device has its own tab.' );
		$this->assertContains( 'desktop', $panels );
		$this->assertNotContains( 'timing', $panels, 'Appearing and folding live on the device tabs now.' );
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
		$this->assertSame( (string) HPRNB_SCHEMA_VERSION, get_option( Settings::SCHEMA_OPTION ) );

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
