<?php
/**
 * 2.9.0: one behaviour per device, and the bar that goes away in the next article.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Import_Export;
use HorizonPress\NewsBar\Admin\Settings_Page;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

class Behavior_Test extends HPRNB_Test_Case {

	const PREFIXES = array( 'desktop_', 'mobile_' );

	public function test_the_defaults_already_hold_their_behaviour() {
		$defaults = Settings::defaults();
		$this->assertSame( 'reading', $defaults['mobile_behavior'], '2.11: Continuous reading is the mobile default.' );
		$this->assertSame( 'always', $defaults['desktop_behavior'], 'Desktop keeps the bar with the page.' );
		$this->assertSame( $defaults, Settings::sanitize( $defaults ), 'Saving the defaults changes nothing.' );
		foreach ( self::PREFIXES as $prefix ) {
			$this->assertSame( $defaults[ $prefix . 'behavior' ], Settings::detect_behavior( $defaults, $prefix ) );
		}
		$this->assertTrue( $defaults['mobile_next_hide'], 'Part of Continuous reading.' );
		$this->assertFalse( $defaults['desktop_next_hide'] );
		$this->assertSame( array( 'reading', 'fold', 'always', 'custom' ), Settings::BEHAVIORS );
		$this->assertSame( 'reading', Settings::sanitize( array( 'mobile_behavior' => 'nonsense' ) )['mobile_behavior'], 'Garbage falls back to the default.' );
	}

	public function test_each_behaviour_writes_its_detailed_values() {
		foreach ( self::PREFIXES as $prefix ) {
			foreach ( Settings::behavior_presets() as $name => $preset ) {
				// Detailed values that disagree with the choice, as a hidden part of the form posts them.
				$clean = Settings::sanitize(
					array(
						$prefix . 'behavior'         => $name,
						$prefix . 'reveal_mode'      => 'smart',
						$prefix . 'hide_on_scroll'   => ! ( $preset['hide_on_scroll'] ),
						$prefix . 'collapse_mode'    => 'threshold',
						$prefix . 'next_hide'        => ! ( $preset['next_hide'] ),
						$prefix . 'reveal_paragraph' => 5,
						$prefix . 'collapse_after'   => 300,
					)
				);
				foreach ( $preset as $key => $value ) {
					$this->assertSame( $value, $clean[ $prefix . $key ], "$prefix$name decides $key." );
				}
				$this->assertSame( 5, $clean[ $prefix . 'reveal_paragraph' ], 'The number of paragraphs stays the admin\'s.' );
				$this->assertSame( 300, $clean[ $prefix . 'collapse_after' ], 'So does the threshold.' );
				$this->assertSame( $name, Settings::detect_behavior( $clean, $prefix ), 'And the values read back as that behaviour.' );
			}
		}
	}

	public function test_custom_keeps_every_detail_and_devices_stay_independent() {
		$clean = Settings::sanitize(
			array(
				'mobile_behavior'        => 'reading',
				'desktop_behavior'       => 'custom',
				'desktop_reveal_mode'    => 'smart',
				'desktop_hide_on_scroll' => true,
				'desktop_collapse_mode'  => 'threshold',
				'desktop_next_hide'      => true,
			)
		);
		$this->assertSame( 'smart', $clean['desktop_reveal_mode'] );
		$this->assertTrue( $clean['desktop_hide_on_scroll'] );
		$this->assertSame( 'threshold', $clean['desktop_collapse_mode'] );
		$this->assertTrue( $clean['desktop_next_hide'] );
		$this->assertSame( 'paragraph', $clean['mobile_reveal_mode'], 'Mobile follows its own choice.' );
		$this->assertSame( 'up', $clean['mobile_collapse_mode'] );
	}

	/**
	 * The client's condition, in one choice: at the chosen paragraph before the end, folded on any
	 * scroll back up, open when reading on, gone in the next article.
	 */
	public function test_continuous_reading_is_the_whole_condition() {
		$post     = $this->create_post_ago( 60 );
		$settings = $this->with_settings(
			array(
				'mobile_behavior'         => 'reading',
				'mobile_reveal_paragraph' => 3,
			)
		);

		$reveal = Renderer::reveal_data( $settings );
		$this->assertSame(
			array(
				'mode'      => 'paragraph',
				'value'     => 400,
				'paragraph' => 3,
			),
			$reveal['m']
		);
		$this->assertSame( 'immediate', $reveal['d']['mode'], 'Desktop keeps its own behaviour.' );

		$this->go_to( get_permalink( $post ) );
		$mobile = Renderer::profile_data( $settings, 'm' );
		$this->assertTrue( $mobile['collapse'] );
		$this->assertSame( 'up', $mobile['trigger'], '2.13: every scroll up folds it, every scroll down opens it.' );
		$this->assertTrue( $mobile['next'], 'Goes away in the next article.' );
		$this->assertArrayNotHasKey( 'next', Renderer::profile_data( $settings, 'd' ) );

		$classes = Renderer::root_classes( $settings );
		$this->assertContains( 'hprnb-root--m-pending', $classes, 'Out of view until the paragraph.' );
		$this->assertNotContains( 'hprnb-root--d-pending', $classes );
	}

	public function test_the_next_article_flag_needs_a_single_article_and_a_fixed_bar() {
		$post     = $this->create_post_ago( 60 );
		$settings = $this->with_settings(
			array(
				'mobile_behavior'  => 'reading',
				'desktop_behavior' => 'reading',
			)
		);

		$this->go_to( home_url( '/' ) );
		$this->assertArrayNotHasKey( 'next', Renderer::profile_data( $settings, 'm' ), 'A listing of full posts has no "next article".' );

		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( Renderer::profile_data( $settings, 'm' )['next'] );
		$this->assertTrue( Renderer::profile_data( $settings, 'd' )['next'] );

		$inline = $this->with_settings(
			array(
				'mobile_behavior'  => 'reading',
				'mobile_placement' => 'inline',
			)
		);
		$this->go_to( get_permalink( $post ) );
		$this->assertArrayNotHasKey( 'next', Renderer::profile_data( $inline, 'm' ), 'A bar inside the article scrolls away with it.' );

		// On its own it is enough to load the interactive script.
		$quiet = array_merge(
			Settings::defaults(),
			array(
				'ticker_enabled'        => false,
				'close_button'          => false,
				'show_relative_time'    => false,
				'mobile_ticker_mode'    => 'none',
				'mobile_hide_on_scroll' => false,
				'mobile_kbd_hide'       => false,
				'mobile_reveal_mode'    => 'immediate',
				'mobile_next_hide'      => false,
			)
		);
		$this->assertFalse( Renderer::needs_interactive_js( $quiet ) );
		$quiet['desktop_next_hide'] = true;
		$this->assertTrue( Renderer::needs_interactive_js( $quiet ) );
	}

	/**
	 * Schema 6: a site keeps exactly what it had. Its detailed values are named after the behaviour
	 * they already match, or "custom" — never "reading", whose next-article part is new.
	 */
	public function test_schema_6_names_what_a_site_already_has() {
		// A 2.8 option: the mobile bar with the page, folding while scrolling down.
		$old = array_merge(
			Settings::defaults(),
			array(
				'mobile_reveal_mode'    => 'immediate',
				'mobile_hide_on_scroll' => true,
				'mobile_collapse_mode'  => 'scroll',
			)
		);
		unset( $old['desktop_behavior'], $old['mobile_behavior'], $old['desktop_next_hide'], $old['mobile_next_hide'] );

		$upgraded = Settings::migrate( $old, 5 );
		$this->assertSame( 'fold', $upgraded['mobile_behavior'] );
		$this->assertSame( 'always', $upgraded['desktop_behavior'] );

		// The client's 2.8 set-up: paragraph 2, folding while scrolling.
		$tuned = array_merge(
			$old,
			array(
				'mobile_reveal_mode'      => 'paragraph',
				'mobile_reveal_paragraph' => 2,
				'mobile_collapse_mode'    => 'scroll',
			)
		);
		$this->assertSame( 'custom', Settings::migrate( $tuned, 5 )['mobile_behavior'] );

		// Even the full 2.7 recipe stays "custom": naming it "reading" would switch the next-article part on.
		$recipe = array_merge(
			$old,
			array(
				'mobile_reveal_mode'    => 'paragraph',
				'mobile_hide_on_scroll' => true,
				'mobile_collapse_mode'  => 'article',
			)
		);
		$this->assertSame( 'custom', Settings::migrate( $recipe, 5 )['mobile_behavior'] );
		// Even though the next-article option now defaults to on: a migrated site gets it off.
		$this->assertFalse( Settings::migrate( $recipe, 5 )['mobile_next_hide'] );
		$this->assertFalse( Settings::sanitize( Settings::migrate( $recipe, 5 ) )['mobile_next_hide'] );

		// Through the real upgrade path: stored, and the details untouched.
		update_option( Settings::OPTION, $tuned );
		update_option( Settings::SCHEMA_OPTION, '5' );
		Settings::flush();
		Settings::maybe_upgrade();
		$now = Settings::get();
		$this->assertSame( 'custom', $now['mobile_behavior'] );
		$this->assertSame( 'paragraph', $now['mobile_reveal_mode'] );
		$this->assertSame( 'scroll', $now['mobile_collapse_mode'] );
		$this->assertFalse( $now['mobile_next_hide'] );
		$this->assertSame( 'always', $now['desktop_behavior'] );
		$this->assertSame( (string) HPRNB_SCHEMA_VERSION, get_option( Settings::SCHEMA_OPTION ) );

		// An explicit choice is never second-guessed, and an empty option is left alone.
		$this->assertSame(
			'reading',
			Settings::migrate(
				array(
					'mobile_behavior'  => 'reading',
					'mobile_next_hide' => true,
				),
				5
			)['mobile_behavior']
		);
		$this->assertSame( array(), Settings::migrate( array(), 5 ) );
	}

	public function test_a_2_8_export_imports_with_its_details() {
		$settings = Settings::defaults();
		unset( $settings['desktop_behavior'], $settings['mobile_behavior'], $settings['desktop_next_hide'], $settings['mobile_next_hide'] );
		$settings['mobile_reveal_mode']   = 'paragraph';
		$settings['mobile_collapse_mode'] = 'article';
		$json                             = wp_json_encode(
			array(
				'_meta'    => array(
					'plugin'         => 'horizon-press-news-bar',
					'schema_version' => 5,
					'plugin_version' => '2.8.0',
				),
				'settings' => $settings,
			)
		);
		$this->assertContains( Import_Export::import_json( (string) $json ), array( 'imported', 'imported_ids' ) );
		$now = Settings::get();
		$this->assertSame( 'custom', $now['mobile_behavior'] );
		$this->assertSame( 'paragraph', $now['mobile_reveal_mode'], 'The fold default never overwrites an imported detail.' );
		$this->assertSame( 'article', $now['mobile_collapse_mode'] );
	}

	public function test_the_form_saves_the_choice_over_the_hidden_details() {
		// The hidden "Custom" blocks still post their inputs: the choice wins over them.
		$form  = array(
			'mobile_behavior'         => 'reading',
			'mobile_reveal_mode'      => 'immediate',
			'mobile_collapse_mode'    => 'scroll',
			'mobile_reveal_paragraph' => '2',
			'desktop_behavior'        => 'always',
			'show_on_mobile'          => '1',
			'show_on_desktop'         => '1',
		);
		$clean = Settings_Page::sanitize( $form );
		$this->assertSame( 'reading', $clean['mobile_behavior'] );
		$this->assertSame( 'paragraph', $clean['mobile_reveal_mode'] );
		$this->assertTrue( $clean['mobile_hide_on_scroll'], 'An unticked hidden checkbox does not undo the choice.' );
		$this->assertSame( 'up', $clean['mobile_collapse_mode'] );
		$this->assertTrue( $clean['mobile_next_hide'] );
		$this->assertSame( 2, $clean['mobile_reveal_paragraph'] );
		$this->assertFalse( $clean['desktop_hide_on_scroll'] );
	}

	public function test_the_device_tabs_open_on_the_one_choice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Settings::OPTION, Settings::defaults() );
		Settings::flush();

		ob_start();
		Settings_Page::render();
		$html = (string) ob_get_clean();

		foreach ( array( 'mobile', 'desktop' ) as $device ) {
			foreach ( Settings::BEHAVIORS as $name ) {
				$this->assertSame( 1, substr_count( $html, 'name="hprnb_settings[' . $device . '_behavior]" value="' . $name . '"' ), "$device offers $name once." );
			}
			$this->assertSame( 2, substr_count( $html, 'data-hprnb-card-depends="' . $device . '_behavior:custom"' ), 'Two detailed cards, shown only for "Custom".' );
			// The choice comes first on its tab.
			$panel = substr( $html, (int) strpos( $html, 'data-hprnb-panel="' . $device . '"' ) );
			$first = strpos( $panel, 'hprnb-card' );
			$this->assertStringContainsString( 'hprnb-card--compact', substr( $panel, $first, 80 ) );
			$this->assertLessThan( strpos( $panel, $device . '_layout]' ), strpos( $panel, $device . '_behavior]' ) );
		}
		$this->assertStringContainsString( 'checked=\'checked\'', substr( $html, (int) strpos( $html, 'value="reading"' ), 60 ), 'The stored behaviour is ticked.' );
		$this->assertStringContainsString( 'hprnb-choice__title', $html );
		$this->assertStringContainsString( 'name="hprnb_settings[mobile_next_hide]"', $html );
		// The article body selector serves every device and behaviour: it lives on the Advanced tab.
		$advanced = substr( $html, (int) strpos( $html, 'data-hprnb-panel="advanced"' ) );
		$this->assertStringContainsString( 'name="hprnb_settings[smart_selector]"', $advanced );
	}
}
