<?php
/**
 * In-content placement: the bar inside the article, at a paragraph offset, per profile.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

defined( 'ABSPATH' ) || exit;

/**
 * Inserts the root (and, when the two profiles disagree, an empty slot the script moves it into)
 * between the paragraphs of the singular content. Never queries the database.
 */
final class Placement {

	/**
	 * Whether the content filter already placed the root.
	 *
	 * @var bool
	 */
	private static bool $done = false;

	/**
	 * Registers the content filter after wpautop and the shortcodes have run.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'the_content', array( self::class, 'filter_content' ), 20 );
	}

	/**
	 * Whether at least one profile asks for the bar inside the article.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function wants_inline( array $settings ): bool {
		foreach ( array( 'd', 'm' ) as $p ) {
			if ( 'inline' === Renderer::profile( $settings, $p )['placement'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Offsets, in closing `</p>` tags, after which each profile wants the bar. `before` paragraph N
	 * closes N − 1 paragraphs first, `after` closes N, and `before_end` leaves N paragraphs behind.
	 *
	 * @param array $settings Settings.
	 * @param int   $total    Number of paragraphs in the content.
	 * @return array{d:?int,m:?int}
	 */
	public static function offsets( array $settings, int $total ): array {
		$out = array(
			'd' => null,
			'm' => null,
		);
		foreach ( array( 'd', 'm' ) as $p ) {
			if ( 'inline' !== Renderer::profile( $settings, $p )['placement'] ) {
				continue;
			}
			$prefix = ( 'm' === $p ) ? 'mobile_' : 'desktop_';
			$anchor = (string) ( $settings[ $prefix . 'inline_anchor' ] ?? 'after' );
			$number = max( 1, min( 30, (int) ( $settings[ $prefix . 'inline_paragraph' ] ?? 3 ) ) );

			if ( 'before' === $anchor ) {
				$offset = $number - 1;
			} elseif ( 'before_end' === $anchor ) {
				$offset = $total - $number;
			} else {
				$offset = $number;
			}
			$out[ $p ] = max( 0, min( $total, $offset ) );
		}
		return $out;
	}

	/**
	 * Splits the content after the nth closing paragraph. Offset 0 prepends, `$total` appends.
	 * Paragraphs nested in a block-level element are skipped: only top-level `</p>` count.
	 *
	 * @param string $content Content.
	 * @return string[] Chunks; count() - 1 is the number of usable paragraph ends.
	 */
	public static function paragraphs( string $content ): array {
		if ( ! preg_match_all( '#</p\s*>#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array( $content );
		}

		$chunks = array();
		$start  = 0;
		foreach ( $matches[0] as $match ) {
			$end      = (int) $match[1] + strlen( (string) $match[0] );
			$chunks[] = substr( $content, $start, $end - $start );
			$start    = $end;
		}
		$chunks[] = substr( $content, $start );

		return $chunks;
	}

	/**
	 * Inserts the root — and the second profile's slot when the two offsets differ — in the content.
	 *
	 * @param mixed $content Content.
	 * @return mixed
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || self::$done || is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( ! is_singular() || ! Frontend::is_eligible() || Frontend::root_claimed() ) {
			return $content;
		}

		$settings = Settings::get();
		if ( ! self::wants_inline( $settings ) ) {
			return $content;
		}

		// As the page shows it, like the body classes and the reserved heights: without the article being
		// read in the URGENT bar (2.17).
		$payload = Frontend::shown_payload();
		if ( 'php' === $settings['render_mode'] && (int) $payload['count'] < 1 ) {
			return $content;
		}

		$chunks = self::paragraphs( $content );
		$total  = count( $chunks ) - 1;
		if ( $total < 1 ) {
			return $content; // No paragraph to hang on to: the footer keeps the bar.
		}

		$offsets = self::offsets( $settings, $total );
		// The root goes where the desktop wants it; a phone-only placement stands on its own.
		$root_at = $offsets['d'] ?? $offsets['m'];
		$slot_at = ( null !== $offsets['d'] && null !== $offsets['m'] && $offsets['d'] !== $offsets['m'] ) ? $offsets['m'] : null;

		self::$done = true;
		Frontend::claim_root();

		$root = Renderer::root( $payload, Visibility::with_context_devices( $settings ) );
		$slot = '<div class="hprnb-slot" data-hprnb-slot="m" aria-hidden="true"></div>';

		// Offset 0 means "before the first paragraph": nothing is closed yet.
		$out = ( 0 === $root_at ? $root : '' ) . ( 0 === $slot_at ? $slot : '' );
		foreach ( $chunks as $index => $chunk ) {
			$out   .= $chunk;
			$closed = $index + 1;
			if ( $closed === $root_at ) {
				$out .= $root;
			}
			if ( $closed === $slot_at ) {
				$out .= $slot;
			}
		}

		return $out;
	}

	/**
	 * Clears static state (tests only).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$done = false;
	}
}
