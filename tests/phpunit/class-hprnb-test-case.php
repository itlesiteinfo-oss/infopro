<?php
/**
 * Base test case for the Horizon Press News Bar plugin (development only).
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Frontend;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Settings;

/**
 * Shared helpers: settings reset, precise post dating, query counting.
 */
abstract class HPRNB_Test_Case extends WP_UnitTestCase {

	/**
	 * Number of WP_Query main post queries observed (via posts_pre_query).
	 *
	 * @var int
	 */
	protected int $post_queries = 0;

	/**
	 * Resets plugin state before every test.
	 */
	public function set_up() {
		parent::set_up();

		$this->post_queries = 0;
		add_filter( 'posts_pre_query', array( $this, 'count_post_query' ), 10, 2 );

		update_option( Settings::OPTION, Settings::defaults(), true );
		update_option( Settings::EPOCH_OPTION, wp_generate_uuid4(), true );
		update_option( 'timezone_string', 'UTC' );
		update_option( 'gmt_offset', 0 );

		Settings::flush();
		Payload::flush();
		Invalidation::reset_guard();
		Frontend::reset();
		$this->reset_rest_server();

		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Cleans up.
	 */
	public function tear_down() {
		remove_filter( 'posts_pre_query', array( $this, 'count_post_query' ), 10 );
		Frontend::reset();
		Payload::flush();
		Settings::flush();
		Invalidation::reset_guard();
		parent::tear_down();
	}

	/**
	 * Counts content queries for the `post` post type.
	 *
	 * @param mixed    $posts Posts (null to run the query).
	 * @param WP_Query $query Query.
	 * @return mixed
	 */
	public function count_post_query( $posts, $query ) {
		if ( $query instanceof WP_Query && in_array( 'post', (array) $query->get( 'post_type' ), true ) ) {
			++$this->post_queries;
		}
		return $posts;
	}

	/**
	 * Overrides settings (merged over defaults) and persists them.
	 *
	 * @param array $overrides Settings overrides.
	 * @return array Effective settings.
	 */
	protected function with_settings( array $overrides ): array {
		$settings = Settings::sanitize( array_merge( Settings::defaults(), $overrides ) );
		update_option( Settings::OPTION, $settings, true );
		Settings::flush();
		Payload::flush();
		Invalidation::reset_guard();
		Frontend::reset();
		return Settings::get();
	}

	/**
	 * Creates a published post dated exactly $seconds_ago seconds before now (UTC).
	 *
	 * @param int   $seconds_ago Age in seconds.
	 * @param array $args        Extra post args.
	 * @return int Post ID.
	 */
	protected function create_post_ago( int $seconds_ago, array $args = array() ): int {
		$ts       = time() - $seconds_ago;
		$gmt      = gmdate( 'Y-m-d H:i:s', $ts );
		$local    = get_date_from_gmt( $gmt );
		$defaults = array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => 'Post ' . $seconds_ago,
			'post_date'     => $local,
			'post_date_gmt' => $gmt,
		);
		return (int) self::factory()->post->create( array_merge( $defaults, $args ) );
	}

	/**
	 * Fresh REST server for each test.
	 *
	 * @return WP_REST_Server
	 */
	protected function reset_rest_server(): WP_REST_Server {
		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server;
	}

	/**
	 * Simulates a front-end request to a URL and runs the plugin's `wp` preparation.
	 *
	 * @param string $url URL.
	 */
	protected function go_to_front( string $url ): void {
		Frontend::reset();
		Payload::flush();
		$this->go_to( $url );
		Frontend::prepare();
	}

	/**
	 * Captures the footer output of the plugin.
	 *
	 * @return string
	 */
	protected function render_footer(): string {
		ob_start();
		Frontend::footer();
		return (string) ob_get_clean();
	}
}
