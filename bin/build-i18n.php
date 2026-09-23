<?php
/**
 * Builds languages/horizon-press-news-bar.pot, -fr_FR.po and -fr_FR.mo.
 *
 * Dev-only tool. Usage: php bin/build-i18n.php
 * Strings are extracted by bin/extract-strings.php; French translations live in bin/fr_FR.php.
 */

require __DIR__ . '/../vendor/autoload.php';

use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Translation;
use Gettext\Translations;

$root    = dirname( __DIR__ );
$plugin  = $root . '/horizon-press-news-bar';
$domain  = 'horizon-press-news-bar';
$version = '2.12.0';

$json    = shell_exec( 'php ' . escapeshellarg( __DIR__ . '/extract-strings.php' ) );
$entries = json_decode( (string) $json, true );
if ( ! is_array( $entries ) ) {
	fwrite( STDERR, "Extraction failed.\n" );
	exit( 1 );
}

$french = require __DIR__ . '/fr_FR.php';

$headers = array(
	'Project-Id-Version'         => 'Horizon Press News Bar ' . $version,
	'Report-Msgid-Bugs-To'       => 'https://horizonpress.example/news-bar',
	'POT-Creation-Date'          => gmdate( 'Y-m-d H:i+0000' ),
	'MIME-Version'               => '1.0',
	'Content-Type'               => 'text/plain; charset=UTF-8',
	'Content-Transfer-Encoding'  => '8bit',
	'X-Generator'                => 'bin/build-i18n.php',
	'X-Domain'                   => $domain,
);

$build = static function ( bool $translate ) use ( $entries, $french, $headers, $domain ): Translations {
	$translations = Translations::create( $domain );
	foreach ( $headers as $name => $value ) {
		$translations->getHeaders()->set( $name, $value );
	}
	if ( $translate ) {
		$translations->getHeaders()->set( 'Language', 'fr_FR' );
		$translations->getHeaders()->set( 'Plural-Forms', 'nplurals=2; plural=(n > 1);' );
		$translations->getHeaders()->set( 'PO-Revision-Date', gmdate( 'Y-m-d H:i+0000' ) );
		$translations->getHeaders()->set( 'Last-Translator', 'Horizon Press' );
		$translations->getHeaders()->set( 'Language-Team', 'Français' );
	} else {
		$translations->getHeaders()->set( 'Language', '' );
		$translations->getHeaders()->set( 'Plural-Forms', 'nplurals=INTEGER; plural=EXPRESSION;' );
	}

	$missing = array();
	foreach ( $entries as $entry ) {
		$translation = Translation::create( $entry['context'], $entry['msgid'] );
		if ( ! empty( $entry['plural'] ) ) {
			$translation->setPlural( $entry['plural'] );
		}
		if ( ! empty( $entry['comment'] ) ) {
			$translation->getExtractedComments()->add( 'translators: ' . $entry['comment'] );
		}
		foreach ( $entry['references'] as $reference ) {
			list( $file, $line ) = explode( ':', $reference );
			$translation->getReferences()->add( $file, (int) $line );
		}
		if ( $translate ) {
			$key = ( $entry['context'] ? $entry['context'] . "\x04" : '' ) . $entry['msgid'];
			if ( isset( $french[ $key ] ) ) {
				$fr = $french[ $key ];
				if ( is_array( $fr ) ) {
					$translation->translate( $fr[0] );
					$translation->translatePlural( ...array_slice( $fr, 1 ) );
				} else {
					$translation->translate( $fr );
				}
			} else {
				$missing[] = $entry['msgid'];
			}
		}
		$translations->add( $translation );
	}
	if ( $missing ) {
		fwrite( STDERR, "Missing French translations:\n - " . implode( "\n - ", $missing ) . "\n" );
		exit( 1 );
	}
	return $translations;
};

$pot = $build( false );
$po  = $build( true );

$generator = new PoGenerator();
$generator->generateFile( $pot, $plugin . '/languages/' . $domain . '.pot' );
$generator->generateFile( $po, $plugin . '/languages/' . $domain . '-fr_FR.po' );
( new MoGenerator() )->generateFile( $po, $plugin . '/languages/' . $domain . '-fr_FR.mo' );

printf( "POT: %d strings, PO/MO fr_FR: %d translations\n", count( $pot ), count( $po ) );
