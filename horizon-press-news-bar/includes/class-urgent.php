<?php
/**
 * Urgent articles: the red bar that replaces the news bar for a while after an article is flagged.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * An author ticks "Urgent article" on the edit screen and publishes or updates: the article carries
 * an expiry timestamp for the configured number of minutes. While at least one published article
 * has not expired, the payload carries an "urgent" bar listing them, newest flag first, and the
 * script shows it instead of the news bar; every article leaves the bar at its own expiry and the
 * news bar comes back when the last one has gone. No cron, no custom table: the expiry is a post
 * meta compared with the current time at query time and at display time.
 */
final class Urgent {

	/**
	 * Meta key: UTC timestamp until which the article is urgent.
	 */
	const META_UNTIL = '_hprnb_urgent_until';

	/**
	 * Meta key: UTC timestamp at which the article was flagged (newest first in the bar).
	 */
	const META_SINCE = '_hprnb_urgent_since';

	/**
	 * Meta key: the box was ticked on an article not yet published; the countdown starts at publication.
	 */
	const META_ARMED = '_hprnb_urgent_armed';

	/**
	 * Hooks the scheduled-publication trigger.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Before the invalidation (priority 10): the epoch must rotate after the meta is written.
		add_action( 'transition_post_status', array( self::class, 'on_transition_post_status' ), 9, 3 );
	}

	/**
	 * Whether the feature is switched on, on at least one device.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function enabled( array $settings ): bool {
		$devices = self::devices( $settings );
		return $devices['d'] || $devices['m'];
	}

	/**
	 * The devices the URGENT bar shows on (2.16): its switch, then one switch per device. Both off is
	 * the same as the bar switched off — no box on the edit screen, no query, nothing on the site.
	 *
	 * @param array $settings Settings.
	 * @return array{d:bool,m:bool}
	 */
	public static function devices( array $settings ): array {
		$on = ! empty( $settings['urgent_enabled'] );
		return array(
			'd' => $on && ! empty( $settings['urgent_desktop'] ?? true ),
			'm' => $on && ! empty( $settings['urgent_mobile'] ?? true ),
		);
	}

	/**
	 * Duration of one flag, in seconds.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function seconds( array $settings ): int {
		return max( 1, (int) ( $settings['urgent_minutes'] ?? 10 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Expiry timestamp of an article, 0 when it carries none.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function until( int $post_id ): int {
		return $post_id > 0 ? (int) get_post_meta( $post_id, self::META_UNTIL, true ) : 0;
	}

	/**
	 * Timestamp at which the article was flagged, 0 when it carries none.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function since( int $post_id ): int {
		return $post_id > 0 ? (int) get_post_meta( $post_id, self::META_SINCE, true ) : 0;
	}

	/**
	 * Whether the article is urgent right now.
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $now     Reference instant (tests).
	 * @return bool
	 */
	public static function is_active( int $post_id, ?int $now = null ): bool {
		return self::until( $post_id ) > ( null === $now ? time() : $now );
	}

	/**
	 * Whether the box is ticked on an article waiting for its publication.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_armed( int $post_id ): bool {
		return $post_id > 0 && '1' === (string) get_post_meta( $post_id, self::META_ARMED, true );
	}

	/**
	 * Starts (or restarts) the countdown of a published article.
	 *
	 * @param int      $post_id  Post ID.
	 * @param array    $settings Settings.
	 * @param int|null $now      Reference instant (tests).
	 * @return int The expiry timestamp.
	 */
	public static function flag( int $post_id, array $settings, ?int $now = null ): int {
		$now   = null === $now ? time() : $now;
		$until = $now + self::seconds( $settings );
		update_post_meta( $post_id, self::META_SINCE, (string) $now );
		update_post_meta( $post_id, self::META_UNTIL, (string) $until );
		delete_post_meta( $post_id, self::META_ARMED );
		return $until;
	}

	/**
	 * Ticks the box on an article not yet published: the countdown starts when it is.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function arm( int $post_id ): void {
		delete_post_meta( $post_id, self::META_UNTIL );
		delete_post_meta( $post_id, self::META_SINCE );
		update_post_meta( $post_id, self::META_ARMED, '1' );
	}

	/**
	 * Ends the urgency at once (or unticks a waiting article).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function unflag( int $post_id ): void {
		delete_post_meta( $post_id, self::META_UNTIL );
		delete_post_meta( $post_id, self::META_SINCE );
		delete_post_meta( $post_id, self::META_ARMED );
	}

	/**
	 * Where an article stands (2.19): 'active' while it is in the red bar (published, its countdown
	 * running), 'armed' while it is ticked but waits for its publication (or was unpublished while its
	 * countdown ran), 'off' otherwise (never flagged, over, or unticked). What the posts list shows.
	 *
	 * @param int      $post_id Post ID.
	 * @param int|null $now     Reference instant (tests).
	 * @return string
	 */
	public static function state( int $post_id, ?int $now = null ): string {
		if ( self::is_active( $post_id, $now ) ) {
			return 'publish' === get_post_status( $post_id ) ? 'active' : 'armed';
		}
		return self::is_armed( $post_id ) ? 'armed' : 'off';
	}

	/**
	 * Ticks or unticks an article (2.19: one rule for the edit screen's box and the posts list). Ticked
	 * on a published article: the countdown starts, unless it is already running and $restart is false
	 * — a typo fixed two minutes in does not give the red bar a fresh ten minutes. Ticked on an
	 * unpublished article: the countdown waits for the publication. Unticked: over at once. The caller
	 * checks the nonce, the capability and that the feature is on.
	 *
	 * @param WP_Post $post     The article.
	 * @param bool    $want     Ticked.
	 * @param bool    $restart  Start the countdown over when it is already running.
	 * @param array   $settings Settings.
	 * @return void
	 */
	public static function apply( WP_Post $post, bool $want, bool $restart, array $settings ): void {
		$post_id = (int) $post->ID;
		if ( ! $want ) {
			self::unflag( $post_id );
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			if ( ! self::is_armed( $post_id ) ) {
				self::arm( $post_id );
			}
			return;
		}
		if ( $restart || ! self::is_active( $post_id ) ) {
			self::flag( $post_id, $settings );
		}
	}

	/**
	 * A scheduled (or otherwise unpublished) article with the box ticked reaches `publish`: the
	 * countdown starts now, whoever or whatever published it.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! self::is_armed( (int) $post->ID ) ) {
			return;
		}
		$settings = Settings::get();
		if ( self::enabled( $settings ) ) {
			self::flag( (int) $post->ID, $settings );
		} else {
			self::unflag( (int) $post->ID );
		}
	}

	/**
	 * Settings under which the urgent bar is rendered: the news bar's own values, minus everything
	 * that has no place on a breaking-news strip (pictures, relative time, a remembered dismissal),
	 * plus a close button whatever the news bar does.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function render_settings( array $settings ): array {
		return array_merge(
			$settings,
			array(
				'mobile_layout'          => 'flow', // The flowing shape without the picture the image bar always pulls in.
				// One headline at a time on every device (2.16), with its pause button, never a marquee.
				'ticker_enabled'         => true,
				'ticker_mode'            => 'rotate',
				'mobile_ticker_mode'     => 'rotate',
				'mobile_show_thumbnail'  => false,
				'desktop_show_thumbnail' => false,
				'mobile_peek_thumbnail'  => false,
				'show_relative_time'     => false,
				'close_button'           => true,
				'remember_dismiss'       => false,
				'mobile_hide_on_scroll'  => false,
				'desktop_hide_on_scroll' => false,
				'mobile_next_hide'       => false,
				'desktop_next_hide'      => false,
				'mobile_reveal_mode'     => 'immediate',
				'desktop_reveal_mode'    => 'immediate',
				'mobile_behavior'        => 'custom',
				'desktop_behavior'       => 'custom',
				'mobile_lines'           => max( 1, min( 2, (int) ( $settings['mobile_lines'] ?? 2 ) ) ),
			)
		);
	}

	/**
	 * WP_Query arguments selecting the urgent articles: published, not expired, newest flag first.
	 *
	 * @param array    $settings Settings.
	 * @param int|null $now      Reference instant (tests).
	 * @return array
	 */
	public static function args( array $settings, ?int $now = null ): array {
		$now  = null === $now ? time() : $now;
		$args = array(
			'post_type'              => array( 'post' ),
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'posts_per_page'         => max( 1, min( 10, (int) ( $settings['max_items'] ?? 10 ) ) ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => true,
			// The expiry is the whole point of the selection: one indexed range on a capped query.
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the selection is the expiry itself.
				'relation'    => 'AND',
				'hprnb_until' => array(
					'key'     => self::META_UNTIL,
					'value'   => $now,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
				'hprnb_since' => array(
					'key'     => self::META_SINCE,
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'                => array(
				'hprnb_since' => 'DESC',
				'date'        => 'DESC',
			),
		);

		/**
		 * Filters the WP_Query arguments used to select the urgent articles.
		 *
		 * @param array $args     Query arguments.
		 * @param array $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_urgent_query_args', $args, $settings );

		return is_array( $filtered ) ? $filtered : $args;
	}

	/**
	 * The urgent articles as normalised items (the news bar's shape plus `since` and `until`).
	 *
	 * @param array    $settings Settings.
	 * @param int|null $now      Reference instant (tests).
	 * @return array<int, array>
	 */
	public static function items( array $settings, ?int $now = null ): array {
		if ( ! self::enabled( $settings ) ) {
			return array();
		}
		$now      = null === $now ? time() : $now;
		$query    = new WP_Query( self::args( $settings, $now ) );
		$posts    = is_array( $query->posts ) ? $query->posts : array();
		$rendered = self::render_settings( $settings );
		$items    = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$until = self::until( (int) $post->ID );
			if ( $until <= $now ) {
				continue; // A filter may have widened the selection: the expiry still rules.
			}
			$item = Query::normalize( $post, $rendered );
			if ( null === $item ) {
				continue;
			}
			$item['since'] = self::since( (int) $post->ID );
			$item['until'] = $until;
			$items[]       = $item;
		}

		/**
		 * Filters the urgent items before caching and rendering.
		 *
		 * @param array $items    Items (each with `since` and `until` timestamps).
		 * @param array $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_urgent_items', $items, $settings );

		return is_array( $filtered ) ? array_values( $filtered ) : $items;
	}

	/**
	 * Two invented urgent headlines for the settings-page preview: never expire, never persisted.
	 *
	 * @return array<int, array>
	 */
	public static function sample_items(): array {
		$now    = time();
		$titles = array(
			__( 'Example: the prime minister announces a snap election for next month', 'horizon-press-news-bar' ),
			__( 'Example: the airport is evacuated after a security alert', 'horizon-press-news-bar' ),
		);
		$items  = array();
		foreach ( $titles as $index => $title ) {
			$items[] = array(
				'id'         => -1 - $index,
				'title'      => $title,
				'url'        => '#',
				'timestamp'  => $now - 60 * ( $index + 1 ),
				'datetime'   => gmdate( 'c', $now ),
				'date_label' => '',
				'thumb'      => null,
				'since'      => $now - 60 * ( $index + 1 ),
				'until'      => $now + YEAR_IN_SECONDS,
			);
		}
		return $items;
	}
}
