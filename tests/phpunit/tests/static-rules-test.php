<?php
/**
 * Static rules from the specification, checked against the shipped source files.
 *
 * @package HorizonPress\NewsBar\Tests
 */

class Static_Rules_Test extends HPRNB_Test_Case {

	private function files( string $ext ): array {
		$files = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( HPRNB_PATH, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && $ext === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	public function test_settings_option_is_only_read_in_the_settings_class() {
		foreach ( $this->files( 'php' ) as $file ) {
			$source = file_get_contents( $file );
			if ( str_ends_with( $file, 'class-settings.php' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( "get_option( 'hprnb_settings'", $source, $file );
			$this->assertStringNotContainsString( 'get_option( Settings::OPTION', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/get_option\s*\(\s*(self|Settings)::OPTION/', $source, $file );
		}
	}

	public function test_forbidden_php_constructs() {
		foreach ( $this->files( 'php' ) as $file ) {
			$source = file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/\beval\s*\(/', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/\bextract\s*\(/', $source, $file );
			$this->assertStringNotContainsString( '$_REQUEST', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/RAND\s*\(\)/i', $source, $file );
			$this->assertStringNotContainsString( 'wp_is_mobile', $source, $file );
			$this->assertStringNotContainsString( 'wp_schedule_event', $source, $file );
			$this->assertStringNotContainsString( 'dbDelta', $source, $file );
			$this->assertStringNotContainsString( 'wp_remote_', $source, $file );
			$this->assertStringNotContainsString( 'add_image_size', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/(add|update)_option\([^;]*[\'"](yes|no)[\'"]\s*\)/', $source, $file );
			if ( ! str_ends_with( $file, 'uninstall.php' ) ) {
				$this->assertMatchesRegularExpression( '/defined\(\s*\'ABSPATH\'\s*\)\s*\|\|\s*exit;/', $source, $file );
			}
		}
	}

	public function test_forbidden_js_constructs_and_no_external_urls() {
		foreach ( $this->files( 'js' ) as $file ) {
			$source = file_get_contents( $file );
			$this->assertStringNotContainsString( 'console.log', $source, $file );
			$this->assertStringNotContainsString( 'jQuery', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/\$\(/', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '/\beval\s*\(/', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '#https?://(?!www\.w3\.org)#', $source, $file );
		}
		foreach ( $this->files( 'css' ) as $file ) {
			$source = file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/@import|url\(\s*[\'"]?https?:/', $source, $file );
		}
	}

	public function test_asset_budgets() {
		$this->assertLessThanOrEqual( 8 * 1024, filesize( HPRNB_PATH . 'assets/css/hprnb-bar.min.css' ) );
		$this->assertLessThanOrEqual( 3 * 1024, filesize( HPRNB_PATH . 'assets/js/hprnb-bootstrap.min.js' ) );
		$this->assertLessThanOrEqual( 10 * 1024, filesize( HPRNB_PATH . 'assets/js/hprnb-bar.min.js' ) );
	}

	public function test_no_translation_before_init() {
		$this->assertSame( 10, has_action( 'init', array( \HorizonPress\NewsBar\Plugin::instance(), 'load_textdomain' ) ) );
		$this->assertFalse( has_action( 'plugins_loaded', array( \HorizonPress\NewsBar\Plugin::instance(), 'load_textdomain' ) ) );
	}

	public function test_plugin_headers() {
		$data = get_plugin_data( HPRNB_FILE, false, false );
		$this->assertSame( 'Horizon Press News Bar', $data['Name'] );
		$this->assertSame( '1.0.0', $data['Version'] );
		$this->assertSame( '6.6', $data['RequiresWP'] );
		$this->assertSame( '8.1', $data['RequiresPHP'] );
		$this->assertSame( 'horizon-press-news-bar', $data['TextDomain'] );
		$this->assertSame( '/languages', $data['DomainPath'] );
		$license = get_file_data( HPRNB_FILE, array( 'License' => 'License' ) );
		$this->assertSame( 'GPL-2.0-or-later', $license['License'] );
	}
}
