<?php
/**
 * 2.19: the Urgent switch of Posts → All Posts — the same data as the edit screen's box, over a
 * private REST route, with a row tint and an "Urgent" view.
 *
 * @package HorizonPress\NewsBar\Tests
 */

use HorizonPress\NewsBar\Admin\Post_Controls;
use HorizonPress\NewsBar\Admin\Urgent_List;
use HorizonPress\NewsBar\Invalidation;
use HorizonPress\NewsBar\Payload;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;

class Urgent_List_Test extends HPRNB_Test_Case {

	public function set_up() {
		parent::set_up();
		Urgent_List::reset();
	}

	/**
	 * A request to the switch's route, as a user.
	 *
	 * @param int        $post_id Post ID.
	 * @param mixed|null $urgent  The wish (null: not sent).
	 * @return WP_REST_Response
	 */
	private function toggle( int $post_id, $urgent ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/hprnb/v1/urgent/' . $post_id );
		if ( null !== $urgent ) {
			$request->set_param( 'urgent', $urgent );
		}
		Urgent_List::reset();
		return rest_get_server()->dispatch( $request );
	}

	private function editor(): int {
		$id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $id );
		return $id;
	}

	public function test_the_column_comes_right_after_the_title_and_only_with_the_urgent_bar_on() {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'title'    => 'Title',
			'var'      => 'VAR ARTICLE',
			'author'   => 'Author',
		);
		$this->assertSame( array( 'cb', 'title', 'hprnb_urgent', 'var', 'author' ), array_keys( Urgent_List::columns( $columns ) ) );
		$this->with_settings( array( 'urgent_enabled' => false ) );
		$this->assertSame( $columns, Urgent_List::columns( $columns ), 'The URGENT bar off: no column.' );
	}

	public function test_the_cell_is_a_real_switch_with_the_state_the_server_stored() {
		$this->editor();
		$off   = self::factory()->post->create( array( 'post_title' => 'Séisme' ) );
		$cell  = Urgent_List::cell( get_post( $off ) );
		$this->assertStringContainsString( '<button type="button" class="hprnb-urgent-switch" aria-pressed="false"', $cell );
		$this->assertStringContainsString( 'aria-label="Mark “Séisme” as urgent news"', $cell );
		$this->assertStringContainsString( 'data-hprnb-state="off"', $cell );
		$this->assertStringContainsString( '>Urgent</span>', $cell );
		$this->assertStringNotContainsString( 'hprnb-urgent-cell__note', $cell );

		Urgent::flag( $off, Settings::get() );
		$cell = Urgent_List::cell( get_post( $off ) );
		$this->assertStringContainsString( 'aria-pressed="true"', $cell );
		$this->assertStringContainsString( 'aria-label="Remove “Séisme” from urgent news"', $cell );
		$this->assertStringContainsString( 'data-hprnb-state="active"', $cell );
		$this->assertStringContainsString( '>URGENT</span>', $cell );
		$this->assertStringContainsString( 'hprnb-urgent-cell__note">until ', $cell );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		Urgent::arm( $draft );
		$cell = Urgent_List::cell( get_post( $draft ) );
		$this->assertStringContainsString( 'data-hprnb-state="armed"', $cell );
		$this->assertStringContainsString( 'aria-pressed="true"', $cell );
		$this->assertStringContainsString( 'when published', $cell );

		// Someone who may not edit the article sees the state, not a switch.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$cell = Urgent_List::cell( get_post( $off ) );
		$this->assertStringNotContainsString( '<button', $cell );
		$this->assertStringContainsString( 'hprnb-urgent-switch is-static', $cell );
		// Its state spelled out for screen readers.
		$this->assertStringContainsString( '<span class="screen-reader-text">Urgent: in the red bar</span>', $cell );
		$this->assertStringContainsString( '<span class="screen-reader-text">Not urgent</span>', Urgent_List::cell( get_post( self::factory()->post->create() ) ) );
		$this->assertStringContainsString( '<span class="screen-reader-text">Urgent: when published</span>', Urgent_List::cell( get_post( $draft ) ) );
	}

	public function test_the_cell_says_how_long_is_left_and_an_unpublished_article_is_not_in_the_red_bar() {
		$this->editor();
		$post = self::factory()->post->create();
		Urgent::flag( $post, Settings::get() );
		$this->assertMatchesRegularExpression( '/data-hprnb-left="(59\d|600)"/', Urgent_List::cell( get_post( $post ) ) );
		$this->assertStringNotContainsString( 'data-hprnb-left', Urgent_List::cell( get_post( self::factory()->post->create() ) ) );

		// Unpublished while its countdown runs: out of the red bar, still ticked (back when published again).
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'armed', Urgent::state( $post ) );
		$cell = Urgent_List::cell( get_post( $post ) );
		$this->assertStringContainsString( 'aria-pressed="true"', $cell );
		$this->assertStringContainsString( 'when published', $cell );
		$this->assertStringNotContainsString( 'data-hprnb-left', $cell );
		set_current_screen( 'edit-post' );
		$this->assertNotContains( 'hprnb-urgent-row', Urgent_List::row_class( array(), '', $post ), 'Not tinted: it is not in the red bar.' );
		set_current_screen( 'front' );
		$this->assertContains( $post, Urgent_List::ticked_ids(), 'Still counted as ticked.' );
		$this->assertSame( 'off', $this->toggle( $post, false )->get_data()['state'] );
	}

	public function test_an_article_open_in_someone_elses_editor_is_left_to_them() {
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		$me    = $this->editor();
		$post  = self::factory()->post->create( array( 'post_title' => 'Séisme' ) );
		update_post_meta( $post, '_edit_lock', time() . ':' . $other );
		$this->assertSame( $other, Urgent_List::locked_by( $post ) );
		$cell = Urgent_List::cell( get_post( $post ) );
		$this->assertStringNotContainsString( '<button', $cell );
		$this->assertStringContainsString( 'being edited', $cell );
		$response = $this->toggle( $post, true );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'hprnb_urgent_locked', $response->get_data()['code'] );
		$this->assertStringContainsString( '“Séisme” is being edited by', $response->get_data()['message'] );
		$this->assertSame( 'off', Urgent::state( $post ) );
		// One's own lock, or an old one, does not count.
		update_post_meta( $post, '_edit_lock', time() . ':' . $me );
		$this->assertSame( 0, Urgent_List::locked_by( $post ) );
		update_post_meta( $post, '_edit_lock', ( time() - 200 ) . ':' . $other );
		$this->assertSame( 0, Urgent_List::locked_by( $post ) );
		$this->assertSame( 'active', $this->toggle( $post, true )->get_data()['state'] );
	}

	public function test_the_route_reads_where_an_article_stands_for_whoever_may_read_it() {
		$this->editor();
		$post = self::factory()->post->create();
		Urgent::flag( $post, Settings::get() );
		$request  = new WP_REST_Request( 'GET', '/hprnb/v1/urgent/' . $post );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'id', 'state', 'until', 'html', 'count' ), array_keys( $data ) );
		$this->assertSame( 'active', $data['state'] );
		$this->assertStringContainsString( 'aria-pressed="true"', $data['html'] );
		$this->assertSame( 'active', Urgent::state( $post ), 'Reading writes nothing.' );
		// A contributor reads it (their badge), a subscriber and a visitor may not.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'is-static', $response->get_data()['html'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		// Someone else's private article, for someone who may not read it.
		$this->editor();
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/hprnb/v1/urgent/' . $private ) )->get_status() );
	}

	public function test_the_route_writes_what_the_edit_screen_box_writes() {
		$this->editor();
		$post = self::factory()->post->create( array( 'post_title' => 'Flash' ) );

		$response = $this->toggle( $post, true );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'active', $data['state'] );
		$this->assertSame( Urgent::until( $post ), $data['until'] );
		$this->assertTrue( Urgent::is_active( $post ) );
		$this->assertSame( 1, $data['count'] );
		$this->assertStringContainsString( 'aria-pressed="true"', $data['html'] );

		// Ticking it again never restarts a countdown already running (the box's rule).
		update_post_meta( $post, Urgent::META_UNTIL, (string) ( time() + 30 ) );
		$this->assertSame( 200, $this->toggle( $post, true )->get_status() );
		$this->assertLessThanOrEqual( time() + 30, Urgent::until( $post ) );

		// The edit screen's box shows the same data.
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post ) );
		$this->assertMatchesRegularExpression( '/id="hprnb-urgent"[^>]*checked/', (string) ob_get_clean() );

		// Unticked: over at once, and the box follows.
		$data = $this->toggle( $post, false )->get_data();
		$this->assertSame( 'off', $data['state'] );
		$this->assertSame( 0, Urgent::until( $post ) );
		$this->assertSame( '', get_post_meta( $post, Urgent::META_SINCE, true ) );
		$this->assertSame( 0, $data['count'] );
		ob_start();
		Post_Controls::render_urgent_box( get_post( $post ) );
		$this->assertDoesNotMatchRegularExpression( '/id="hprnb-urgent"[^>]*checked/', (string) ob_get_clean() );

		// A draft or a scheduled article waits for its publication, then its countdown starts.
		$scheduled = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->assertSame( 'armed', $this->toggle( $scheduled, true )->get_data()['state'] );
		$this->assertTrue( Urgent::is_armed( $scheduled ) );
		$this->assertFalse( Urgent::is_active( $scheduled ) );
		wp_publish_post( $scheduled );
		$this->assertTrue( Urgent::is_active( $scheduled ) );
	}

	public function test_the_route_refuses_whoever_may_not_edit_the_article_and_anything_else() {
		$post  = self::factory()->post->create();
		$other = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->toggle( $post, true )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->toggle( $post, true )->get_status() );
		// An author may switch their own articles, not someone else's.
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );
		$this->assertSame( 403, $this->toggle( $other, true )->get_status() );
		$this->assertSame( 200, $this->toggle( $own, true )->get_status() );
		$this->assertFalse( Urgent::is_active( $other ) );

		$this->editor();
		$this->assertSame( 400, $this->toggle( $post, null )->get_status(), 'The wish is required.' );
		$this->assertSame( 404, $this->toggle( 999999, true )->get_status() );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertSame( 404, $this->toggle( $page, true )->get_status(), 'Articles only.' );
		wp_trash_post( $post );
		$this->assertSame( 404, $this->toggle( $post, true )->get_status() );
		$this->with_settings( array( 'urgent_enabled' => false ) );
		$this->editor();
		$fresh    = self::factory()->post->create();
		$response = $this->toggle( $fresh, true );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'hprnb_urgent_off', $response->get_data()['code'] );
		$this->assertFalse( Urgent::is_active( $fresh ) );
	}

	public function test_a_switched_article_enters_the_red_bar_like_one_ticked_on_its_edit_screen() {
		$this->editor();
		$this->create_post_ago( 60, array( 'post_title' => 'Une actualité' ) );
		$post = self::factory()->post->create( array( 'post_title' => 'Flash de la liste' ) );
		Invalidation::reset_guard();
		$this->toggle( $post, true );
		Invalidation::reset_guard();
		Payload::flush();
		$payload = Payload::get( Settings::get() );
		$this->assertSame( 1, $payload['urgent_count'] );
		$this->assertStringContainsString( 'Flash de la liste', $payload['urgent_html'] );
		Invalidation::reset_guard();
		$this->toggle( $post, false );
		Invalidation::reset_guard();
		Payload::flush();
		$this->assertSame( 0, Payload::get( Settings::get() )['urgent_count'] );
	}

	public function test_the_urgent_view_counts_and_lists_the_articles_ticked() {
		$this->editor();
		$active = self::factory()->post->create();
		$armed  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$over   = self::factory()->post->create();
		self::factory()->post->create();
		Urgent::flag( $active, Settings::get() );
		Urgent::arm( $armed );
		update_post_meta( $over, Urgent::META_UNTIL, (string) ( time() - 10 ) );
		Urgent_List::reset();
		$ids = Urgent_List::ticked_ids();
		sort( $ids );
		$this->assertSame( array( $active, $armed ), $ids );

		$views = Urgent_List::views( array( 'all' => '<a href="edit.php" class="current" aria-current="page">All</a>' ) );
		$this->assertStringContainsString( 'hprnb_urgent=1', $views['hprnb_urgent'] );
		$this->assertStringContainsString( '<span class="hprnb-urgent-count">2</span>', $views['hprnb_urgent'] );
		$this->assertStringContainsString( 'class="hprnb-urgent-view"', $views['hprnb_urgent'] );
		$this->assertStringNotContainsString( 'aria-current', $views['hprnb_urgent'] );
		$this->assertStringContainsString( '<svg ', $views['hprnb_urgent'], 'An inline lightning, not an emoji.' );

		// Counted as the list shows them: someone who may not read a private article does not count it.
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		Urgent::flag( $private, Settings::get() );
		Urgent_List::reset();
		$this->assertContains( $private, Urgent_List::ticked_ids() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		Urgent_List::reset();
		$this->assertNotContains( $private, Urgent_List::ticked_ids() );
		$this->editor();
		Urgent::unflag( $private );
		Urgent_List::reset();

		// The view shown: current, the others not; the list's main query keeps only these IDs.
		$_GET['hprnb_urgent'] = '1';
		$GLOBALS['pagenow']   = 'edit.php';
		set_current_screen( 'edit-post' );
		$views = Urgent_List::views( array( 'all' => '<a href="edit.php" class="current" aria-current="page">All</a>' ) );
		$this->assertStringContainsString( 'class="hprnb-urgent-view current" aria-current="page"', $views['hprnb_urgent'] );
		$this->assertStringNotContainsString( 'current', $views['all'] );
		$query = new WP_Query();
		$query->query_vars['post_type'] = 'post';
		$GLOBALS['wp_the_query']        = $query;
		Urgent_List::filter_query( $query );
		$in = $query->get( 'post__in' );
		sort( $in );
		$this->assertSame( array( $active, $armed ), $in );
		ob_start();
		Urgent_List::keep_view( 'post' );
		$this->assertSame( '<input type="hidden" name="hprnb_urgent" value="1" />', ob_get_clean(), 'Search and filters keep the view.' );

		// Nothing ticked: an empty list, not every article.
		Urgent::unflag( $active );
		Urgent::unflag( $armed );
		Urgent_List::reset();
		$query = new WP_Query();
		$query->query_vars['post_type'] = 'post';
		$GLOBALS['wp_the_query']        = $query;
		Urgent_List::filter_query( $query );
		$this->assertSame( array( 0 ), $query->get( 'post__in' ) );
		unset( $_GET['hprnb_urgent'] );
		set_current_screen( 'front' );
	}

	public function test_the_row_of_an_article_in_the_red_bar_is_tinted() {
		$active = self::factory()->post->create();
		$armed  = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		Urgent::flag( $active, Settings::get() );
		Urgent::arm( $armed );
		set_current_screen( 'edit-post' );
		$this->assertContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $active ) );
		$this->assertNotContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $armed ), 'Waiting for its publication: not in the red bar yet.' );
		set_current_screen( 'front' );
		$this->assertNotContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $active ), 'Only on the posts list.' );
	}

	public function test_quick_edit_renders_the_row_again_with_its_tint() {
		$active = self::factory()->post->create();
		Urgent::flag( $active, Settings::get() );
		// admin-ajax sets no current screen; Quick Edit names the list's.
		set_current_screen( 'dashboard' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_POST = array(
			'action' => 'inline-save',
			'screen' => 'edit-post',
		);
		$this->assertContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $active ) );
		$_POST['screen'] = 'edit-page';
		$this->assertNotContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $active ), 'Another list.' );
		$_POST = array( 'action' => 'heartbeat' );
		$this->assertNotContains( 'hprnb-urgent-row', Urgent_List::row_class( array( 'type-post' ), '', $active ), 'Another request.' );
		$_POST = array();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );
	}
}
