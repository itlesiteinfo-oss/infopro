<?php
/**
 * Import / export / reset.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Import_Export;
use HorizonPress\NewsBar\Cache;
use HorizonPress\NewsBar\Settings;

class Import_Export_Test extends HPRNB_Test_Case {

	private function document( array $settings, array $meta = array() ): string {
		return wp_json_encode(
			array(
				'_meta'    => array_merge(
					array(
						'plugin'         => 'horizon-press-news-bar',
						'schema_version' => 3,
						'plugin_version' => '1.0.0',
						'exported_at'    => '2026-09-11T10:00:00+00:00',
					),
					$meta
				),
				'settings' => $settings,
			)
		);
	}

	public function test_export_structure() {
		$data = Import_Export::export_data();
		$this->assertSame( 'horizon-press-news-bar', $data['_meta']['plugin'] );
		$this->assertSame( 3, $data['_meta']['schema_version'] );
		$this->assertSame( HPRNB_VERSION, $data['_meta']['plugin_version'] );
		$this->assertNotFalse( strtotime( $data['_meta']['exported_at'] ) );
		$this->assertSame( Settings::raw(), $data['settings'] );
	}

	public function test_import_whitelists_and_sanitizes() {
		$before = Cache::epoch();
		$code   = Import_Export::import_json(
			$this->document(
				array(
					'label_text'   => '<script>alert(1)</script>Imported',
					'max_items'    => 999,
					'bg_color'     => 'javascript:x',
					'unknown_key'  => 'ignored',
					'render_mode'  => 'php',
					'orderby'      => 'rand',
				)
			)
		);

		$this->assertSame( 'imported', $code );
		$settings = Settings::raw();
		$this->assertStringNotContainsString( '<', $settings['label_text'] );
		$this->assertStringContainsString( 'Imported', $settings['label_text'] );
		$this->assertSame( 30, $settings['max_items'] );
		$this->assertSame( '#1B1C20', $settings['bg_color'] );
		$this->assertSame( 'php', $settings['render_mode'] );
		$this->assertSame( 'date_desc', $settings['orderby'] );
		$this->assertArrayNotHasKey( 'unknown_key', $settings );
		$this->assertTrue( $settings['enabled'], 'Missing keys take the defaults.' );
		$this->assertNotSame( $before, Cache::epoch(), 'Import invalidates the cache.' );
	}

	public function test_import_warns_about_ids() {
		$this->assertSame( 'imported_ids', Import_Export::import_json( $this->document( array( 'categories_include' => array( 5 ) ) ) ) );
		$this->assertSame( 'imported_ids', Import_Export::import_json( $this->document( array( 'display_exclude_ids' => '7,8' ) ) ) );
	}

	public function test_import_rejects_invalid_documents() {
		$this->assertSame( 'import_error_json', Import_Export::import_json( '{not json' ) );
		$this->assertSame( 'import_error_json', Import_Export::import_json( '"string"' ) );
		$this->assertSame( 'import_error_schema', Import_Export::import_json( wp_json_encode( array( 'settings' => array() ) ) ) );
		$this->assertSame( 'import_error_schema', Import_Export::import_json( $this->document( array(), array( 'plugin' => 'other' ) ) ) );
		$this->assertSame( 'import_error_schema', Import_Export::import_json( $this->document( array(), array( 'schema_version' => 99 ) ) ) );
		$this->assertSame( 'import_error_schema', Import_Export::import_json( wp_json_encode( array( '_meta' => array( 'plugin' => 'horizon-press-news-bar', 'schema_version' => 1 ), 'settings' => 'x' ) ) ) );
		$this->assertSame( 'import_error_size', Import_Export::import_json( str_repeat( ' ', 262145 ) ) );
		$this->assertSame( 'import_error_json', Import_Export::import_json( str_repeat( '[', 20 ) . str_repeat( ']', 20 ) ), 'Depth is bounded.' );
		$this->assertSame( Settings::defaults(), Settings::raw(), 'Nothing was written.' );
	}

	public function test_import_uploaded_file_checks() {
		$this->assertSame( 'import_error_upload', Import_Export::import_uploaded_file( array() ) );
		$this->assertSame( 'import_error_upload', Import_Export::import_uploaded_file( array( 'error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => 'a.json' ) ) );
		$this->assertSame( 'import_error_upload', Import_Export::import_uploaded_file( array( 'error' => 0, 'tmp_name' => __FILE__, 'name' => 'a.json', 'size' => 10 ) ), 'Not an uploaded file.' );
	}

	public function test_reset_restores_defaults() {
		Settings::update( array( 'max_items' => 2, 'label_text' => 'X' ) );
		$this->assertSame( 2, Settings::raw()['max_items'] );
		Settings::reset();
		$this->assertSame( Settings::defaults(), get_option( Settings::OPTION ) );
	}
}
