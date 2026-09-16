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
			// The minifier may name a local helper `$`; only the source can call jQuery.
			if ( ! str_ends_with( $file, '.min.js' ) ) {
				$this->assertDoesNotMatchRegularExpression( '/\$\(/', $source, $file );
			}
			$this->assertDoesNotMatchRegularExpression( '/\beval\s*\(/', $source, $file );
			$this->assertDoesNotMatchRegularExpression( '#https?://(?!www\.w3\.org)#', $source, $file );
		}
		foreach ( $this->files( 'css' ) as $file ) {
			$source = file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/@import|url\(\s*[\'"]?https?:/', $source, $file );
		}
	}

	public function test_asset_budgets() {
		$this->assertLessThanOrEqual( 36 * 1024, filesize( HPRNB_PATH . 'assets/css/hprnb-bar.min.css' ) );
		$this->assertLessThanOrEqual( 3 * 1024, filesize( HPRNB_PATH . 'assets/js/hprnb-bootstrap.min.js' ) );
		$this->assertLessThanOrEqual( 20 * 1024, filesize( HPRNB_PATH . 'assets/js/hprnb-bar.min.js' ) );
	}

	/**
	 * The desktop profile mapping (section 2, base rules) and the mobile one (section 14, container
	 * query) must define the same tokens for the same classes, prefix aside.
	 */
	public function test_presentation_profiles_stay_in_sync() {
		$css = file_get_contents( HPRNB_PATH . 'assets/css/hprnb-bar.css' );
		$this->assertSame( 1, preg_match( '/\* 2\. Desktop profile.*?\*\/(.*?)\/\* -{10,}\s*\* 3\./s', $css, $desktop ) );
		$this->assertSame( 1, preg_match( '/@container hprnb \(max-width: 767\.98px\) \{(.*?)\n\}\n\n\/\* Very narrow phones/s', $css, $mobile ) );

		$normalise = static function ( string $block, string $prefix ): array {
			$block = preg_replace( '/\/\*.*?\*\//s', '', $block );
			$block = str_replace( array( '.hprnb-bar--sep-loop', '.hprnb-bar--sep' ), array( '.hprnb-root--' . $prefix . '-sep-loop', '.hprnb-root--' . $prefix . '-sep' ), $block );
			$block = str_replace( array( 'hprnb-root--' . $prefix . '-', ' .hprnb-bar {', ' .hprnb-bar,' ), array( 'hprnb-root--P-', ' {', ',' ), $block );
			preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $block, $rules, PREG_SET_ORDER );
			$out = array();
			foreach ( $rules as $rule ) {
				$selector = preg_replace( '/\s+/', ' ', trim( $rule[1] ) );
				if ( '.hprnb-root' === $selector ) {
					continue; // The mobile reset of every token has no desktop counterpart (the root defaults play that role).
				}
				if ( preg_match( '/P-colors|P-collapse|P-flow|P-card|P-ctrl-col|P-ctrl-out|P-peek-thumb|P-pulse-|P-label-compact|hprnb-bar--collapsed|peek-label/', $selector ) ) {
					continue; // Mobile-only features (palette, collapse, flow card, strip, stacked buttons, pulse).
				}
				$declarations = array_filter( array_map( 'trim', explode( ';', $rule[2] ) ) );
				$out[ $selector ] = array_values( $declarations );
			}
			return $out;
		};

		$d = $normalise( $desktop[1], 'd' );
		$m = $normalise( $mobile[1], 'm' );
		// Only these tokens legitimately differ between the two profiles.
		$d['.hprnb-root--P-stacked'] = array_values( array_diff( $d['.hprnb-root--P-stacked'], array( '--hprnb-e-max: 1200px' ) ) );
		$m['.hprnb-root--P-stacked'] = array_values( array_diff( $m['.hprnb-root--P-stacked'], array( '--hprnb-e-max: none' ) ) );
		$this->assertSame( $d, $m );
	}

	public function test_no_translation_before_init() {
		$this->assertSame( 10, has_action( 'init', array( \HorizonPress\NewsBar\Plugin::instance(), 'load_textdomain' ) ) );
		$this->assertFalse( has_action( 'plugins_loaded', array( \HorizonPress\NewsBar\Plugin::instance(), 'load_textdomain' ) ) );
	}

	public function test_plugin_headers() {
		$data = get_plugin_data( HPRNB_FILE, false, false );
		$this->assertSame( 'Horizon Press News Bar', $data['Name'] );
		$this->assertSame( '2.5.0', $data['Version'] );
		$this->assertSame( '6.6', $data['RequiresWP'] );
		$this->assertSame( '8.0', $data['RequiresPHP'] );
		$this->assertSame( 'horizon-press-news-bar', $data['TextDomain'] );
		$this->assertSame( '/languages', $data['DomainPath'] );
		$license = get_file_data( HPRNB_FILE, array( 'License' => 'License' ) );
		$this->assertSame( 'GPL-2.0-or-later', $license['License'] );
	}
}
