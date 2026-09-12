<?php
/**
 * Sliding time window computed in UTC.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the UTC cutoff and the `date_query` argument. Never uses the modified date.
 */
final class Time_Window {

	/**
	 * Interval for a window value/unit pair.
	 *
	 * @param int    $value Window value (>= 1).
	 * @param string $unit  minutes|hours|days.
	 * @return DateInterval
	 */
	public static function interval( int $value, string $unit ): DateInterval {
		$value = max( 1, $value );
		switch ( $unit ) {
			case 'minutes':
				return new DateInterval( 'PT' . $value . 'M' );
			case 'days':
				return new DateInterval( 'P' . $value . 'D' );
			default:
				return new DateInterval( 'PT' . $value . 'H' );
		}
	}

	/**
	 * Window length in seconds.
	 *
	 * @param array $settings Settings.
	 * @return int
	 */
	public static function seconds( array $settings ): int {
		$value = max( 1, (int) $settings['window_value'] );
		switch ( (string) $settings['window_unit'] ) {
			case 'minutes':
				return $value * MINUTE_IN_SECONDS;
			case 'days':
				return $value * DAY_IN_SECONDS;
			default:
				return $value * HOUR_IN_SECONDS;
		}
	}

	/**
	 * Current instant in UTC (or the given instant converted to UTC).
	 *
	 * @param DateTimeImmutable|null $now Optional reference instant.
	 * @return DateTimeImmutable
	 */
	public static function now( ?DateTimeImmutable $now = null ): DateTimeImmutable {
		$utc = new DateTimeZone( 'UTC' );
		return null === $now ? new DateTimeImmutable( 'now', $utc ) : $now->setTimezone( $utc );
	}

	/**
	 * Lower bound of the window: now minus the configured duration, in UTC.
	 *
	 * @param array                  $settings Settings.
	 * @param DateTimeImmutable|null $now      Optional reference instant.
	 * @return DateTimeImmutable
	 */
	public static function cutoff( array $settings, ?DateTimeImmutable $now = null ): DateTimeImmutable {
		return self::now( $now )->sub( self::interval( (int) $settings['window_value'], (string) $settings['window_unit'] ) );
	}

	/**
	 * `date_query` argument for WP_Query, on `post_date_gmt`, inclusive on both bounds.
	 *
	 * @param array                  $settings Settings.
	 * @param DateTimeImmutable|null $now      Optional reference instant.
	 * @return array
	 */
	public static function date_query( array $settings, ?DateTimeImmutable $now = null ): array {
		$now    = self::now( $now );
		$cutoff = self::cutoff( $settings, $now );

		return array(
			array(
				'column'    => 'post_date_gmt',
				'after'     => self::parts( $cutoff ),
				'before'    => self::parts( $now ),
				'inclusive' => true,
			),
		);
	}

	/**
	 * Explicit date/time parts understood by WP_Date_Query.
	 *
	 * @param DateTimeImmutable $instant Instant (already in UTC).
	 * @return array<string, int>
	 */
	private static function parts( DateTimeImmutable $instant ): array {
		return array(
			'year'   => (int) $instant->format( 'Y' ),
			'month'  => (int) $instant->format( 'n' ),
			'day'    => (int) $instant->format( 'j' ),
			'hour'   => (int) $instant->format( 'G' ),
			'minute' => (int) $instant->format( 'i' ),
			'second' => (int) $instant->format( 's' ),
		);
	}
}
