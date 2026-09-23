<?php
/**
 * Sliding window: units, UTC, DST, date_query and end-to-end selection.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Query;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Time_Window;

class Time_Window_Test extends HPRNB_Test_Case {

	public function test_intervals_per_unit() {
		$this->assertSame(
			30 * MINUTE_IN_SECONDS,
			Time_Window::seconds(
				array(
					'window_value' => 30,
					'window_unit'  => 'minutes',
				)
			)
		);
		$this->assertSame(
			24 * HOUR_IN_SECONDS,
			Time_Window::seconds(
				array(
					'window_value' => 24,
					'window_unit'  => 'hours',
				)
			)
		);
		$this->assertSame(
			3 * DAY_IN_SECONDS,
			Time_Window::seconds(
				array(
					'window_value' => 3,
					'window_unit'  => 'days',
				)
			)
		);
		$this->assertSame( 'PT45M', 'PT' . Time_Window::interval( 45, 'minutes' )->i . 'M' );
		$this->assertSame( 2, Time_Window::interval( 2, 'days' )->d );
	}

	public function test_cutoff_is_computed_in_utc() {
		$now      = new DateTimeImmutable( '2026-09-11 16:20:00', new DateTimeZone( 'Europe/Paris' ) );
		$settings = array(
			'window_value' => 24,
			'window_unit'  => 'hours',
		);
		$cutoff   = Time_Window::cutoff( $settings, $now );

		$this->assertSame( 'UTC', $cutoff->getTimezone()->getName() );
		$this->assertSame( '2026-09-10 14:20:00', $cutoff->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $now->getTimestamp() - DAY_IN_SECONDS, $cutoff->getTimestamp() );
	}

	public function test_dst_transition_does_not_change_the_window_length() {
		// 2026-03-29 03:30 Europe/Paris is one hour after the spring-forward gap.
		$now      = new DateTimeImmutable( '2026-03-29 03:30:00', new DateTimeZone( 'Europe/Paris' ) );
		$settings = array(
			'window_value' => 24,
			'window_unit'  => 'hours',
		);
		$cutoff   = Time_Window::cutoff( $settings, $now );
		$this->assertSame( $now->getTimestamp() - DAY_IN_SECONDS, $cutoff->getTimestamp() );
		$this->assertSame( '2026-03-28 01:30:00', $cutoff->format( 'Y-m-d H:i:s' ) );

		// Autumn: 2026-10-25 02:30 CET (after the fall-back).
		$now    = new DateTimeImmutable( '2026-10-25 02:30:00 CET' );
		$cutoff = Time_Window::cutoff(
			array(
				'window_value' => 1,
				'window_unit'  => 'days',
			),
			$now
		);
		$this->assertSame( $now->getTimestamp() - DAY_IN_SECONDS, $cutoff->getTimestamp() );
	}

	public function test_date_query_shape() {
		$now   = new DateTimeImmutable( '2026-09-11 15:04:05', new DateTimeZone( 'UTC' ) );
		$query = Time_Window::date_query(
			array(
				'window_value' => 90,
				'window_unit'  => 'minutes',
			),
			$now
		);

		$this->assertCount( 1, $query );
		$this->assertSame( 'post_date_gmt', $query[0]['column'] );
		$this->assertTrue( $query[0]['inclusive'] );
		$this->assertSame(
			array(
				'year'   => 2026,
				'month'  => 9,
				'day'    => 11,
				'hour'   => 13,
				'minute' => 34,
				'second' => 5,
			),
			$query[0]['after']
		);
		$this->assertSame(
			array(
				'year'   => 2026,
				'month'  => 9,
				'day'    => 11,
				'hour'   => 15,
				'minute' => 4,
				'second' => 5,
			),
			$query[0]['before']
		);
	}

	public function test_24h_window_boundaries() {
		$in  = $this->create_post_ago( 23 * HOUR_IN_SECONDS + 59 * MINUTE_IN_SECONDS );
		$out = $this->create_post_ago( 24 * HOUR_IN_SECONDS + MINUTE_IN_SECONDS );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );
	}

	public function test_30_minutes_window() {
		$settings = $this->with_settings(
			array(
				'window_value' => 30,
				'window_unit'  => 'minutes',
			)
		);
		$in       = $this->create_post_ago( 29 * MINUTE_IN_SECONDS );
		$out      = $this->create_post_ago( 31 * MINUTE_IN_SECONDS );

		$ids = wp_list_pluck( Query::items( $settings ), 'id' );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );
	}

	public function test_3_days_window() {
		$settings = $this->with_settings(
			array(
				'window_value' => 3,
				'window_unit'  => 'days',
			)
		);
		$in       = $this->create_post_ago( 71 * HOUR_IN_SECONDS );
		$out      = $this->create_post_ago( 73 * HOUR_IN_SECONDS );

		$ids = wp_list_pluck( Query::items( $settings ), 'id' );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );
	}

	public function test_modified_date_is_ignored() {
		$old = $this->create_post_ago( 5 * DAY_IN_SECONDS );
		wp_update_post(
			array(
				'ID'           => $old,
				'post_content' => 'Updated today',
			)
		);
		clean_post_cache( $old );
		$this->assertSame( gmdate( 'Y-m-d' ), substr( get_post( $old )->post_modified_gmt, 0, 10 ) );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertNotContains( $old, $ids );
	}

	public function test_site_timezone_different_from_server() {
		update_option( 'timezone_string', 'America/New_York' );
		$in  = $this->create_post_ago( 2 * HOUR_IN_SECONDS );
		$out = $this->create_post_ago( 26 * HOUR_IN_SECONDS );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );

		update_option( 'timezone_string', 'Pacific/Auckland' );
		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertContains( $in, $ids );
		$this->assertNotContains( $out, $ids );
	}

	public function test_future_dated_published_content_is_excluded_by_the_upper_bound() {
		global $wpdb;
		$id = $this->create_post_ago( 60 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ),
				'post_status'   => 'publish',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		$ids = wp_list_pluck( Query::items( Settings::get() ), 'id' );
		$this->assertNotContains( $id, $ids );
	}
}
