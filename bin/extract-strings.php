<?php
/**
 * Extracts translatable strings from the plugin PHP files (WordPress i18n functions).
 *
 * Dev-only tool. Prints JSON: [ { msgid, plural, context, comment, references[] } ].
 * Usage: php bin/extract-strings.php > /path/strings.json
 */

$root   = dirname( __DIR__ ) . '/horizon-press-news-bar';
$domain = 'horizon-press-news-bar';

// function => [ position of msgid, position of plural, position of context ] (0-based), domain must be last.
$functions = array(
	'__'         => array( 0, null, null ),
	'_e'         => array( 0, null, null ),
	'esc_html__' => array( 0, null, null ),
	'esc_html_e' => array( 0, null, null ),
	'esc_attr__' => array( 0, null, null ),
	'esc_attr_e' => array( 0, null, null ),
	'_x'         => array( 0, null, 1 ),
	'_ex'        => array( 0, null, 1 ),
	'esc_html_x' => array( 0, null, 1 ),
	'esc_attr_x' => array( 0, null, 1 ),
	'_n'         => array( 0, 1, null ),
	'_nx'        => array( 0, 1, 3 ),
	'_n_noop'    => array( 0, 1, null ),
	'_nx_noop'   => array( 0, 1, 2 ),
);

$entries = array();

$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$files    = array();
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

foreach ( $files as $path ) {
	$tokens   = token_get_all( file_get_contents( $path ) );
	$relative = substr( $path, strlen( dirname( $root ) ) + 1 );
	$count    = count( $tokens );
	$last_comment = null;

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			if ( preg_match( '#translators:\s*(.+?)\s*\*/#s', $token[1], $m ) || preg_match( '#//\s*translators:\s*(.+)$#m', $token[1], $m ) ) {
				$last_comment = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
			}
			continue;
		}
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $functions[ $token[1] ] ) ) {
			continue;
		}
		// Must be a function call, not a method/static call.
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			$prev--;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		// Collect top-level arguments.
		$args  = array();
		$depth = 0;
		$buf   = array();
		for ( $k = $j; $k < $count; $k++ ) {
			$t = $tokens[ $k ];
			if ( '(' === $t ) {
				$depth++;
				if ( 1 === $depth ) {
					continue;
				}
			} elseif ( ')' === $t ) {
				$depth--;
				if ( 0 === $depth ) {
					$args[] = $buf;
					break;
				}
			} elseif ( ',' === $t && 1 === $depth ) {
				$args[] = $buf;
				$buf    = array();
				continue;
			}
			$buf[] = $t;
		}
		$line = $token[2];
		$str  = static function ( $arg ) {
			$parts = array();
			foreach ( $arg as $t ) {
				if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
					$parts[] = $t[1];
				} elseif ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
					continue;
				} elseif ( '.' === $t ) {
					continue;
				} else {
					return null;
				}
			}
			if ( empty( $parts ) ) {
				return null;
			}
			$out = '';
			foreach ( $parts as $p ) {
				$q = $p[0];
				$v = substr( $p, 1, -1 );
				$out .= '"' === $q ? stripcslashes( $v ) : str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $v );
			}
			return $out;
		};
		list( $msgid_pos, $plural_pos, $context_pos ) = $functions[ $token[1] ];
		$msgid = isset( $args[ $msgid_pos ] ) ? $str( $args[ $msgid_pos ] ) : null;
		if ( null === $msgid ) {
			fwrite( STDERR, "Non-literal msgid in {$relative}:{$line}\n" );
			continue;
		}
		$domain_arg = $str( end( $args ) );
		if ( $domain !== $domain_arg ) {
			fwrite( STDERR, "Wrong or missing text domain in {$relative}:{$line}\n" );
		}
		$plural  = null !== $plural_pos && isset( $args[ $plural_pos ] ) ? $str( $args[ $plural_pos ] ) : null;
		$context = null !== $context_pos && isset( $args[ $context_pos ] ) ? $str( $args[ $context_pos ] ) : null;
		$key     = $context . "\x04" . $msgid;
		if ( ! isset( $entries[ $key ] ) ) {
			$entries[ $key ] = array(
				'msgid'      => $msgid,
				'plural'     => $plural,
				'context'    => $context,
				'comment'    => $last_comment,
				'references' => array(),
			);
		}
		if ( $last_comment && empty( $entries[ $key ]['comment'] ) ) {
			$entries[ $key ]['comment'] = $last_comment;
		}
		$entries[ $key ]['references'][] = $relative . ':' . $line;
		$last_comment = null;
	}
}

echo json_encode( array_values( $entries ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
