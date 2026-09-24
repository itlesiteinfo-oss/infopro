<?php
/**
 * The Urgent switch of Posts → All Posts (2.19).
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Rest_Controller;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Posts → All Posts gets an "Urgent" column, right after the title: a small switch per article
 * (a real button, aria-pressed) that ticks or unticks the article as urgent without opening it, a
 * row tinted in the URGENT bar's red while the article is in the red bar, and an "⚡ Urgent" view
 * listing the articles ticked. The switch writes exactly what the edit screen's box writes — the
 * same post meta through Urgent::apply() — over a private REST route (nonce, edit_post capability),
 * and the cell it answers with is rendered by the same code as the list, so the page always shows
 * what the server stored. Nothing here decides what the red bar shows: a ticked article is only a
 * candidate, filtered as usual (the article being read, the page types, the expiry, closing).
 */
final class Urgent_List {

	/**
	 * Column key.
	 */
	const COLUMN = 'hprnb_urgent';

	/**
	 * Query argument of the view.
	 */
	const VIEW_ARG = 'hprnb_urgent';

	/**
	 * Row class while the article is in the red bar.
	 */
	const ROW_CLASS = 'hprnb-urgent-row';

	/**
	 * Most articles counted in the view (a ticked article is rare; this only bounds the lookup).
	 */
	const MAX = 500;

	/**
	 * IDs of the articles ticked, per request.
	 *
	 * @var int[]|null
	 */
	private static ?array $ids = null;

	/**
	 * Hooks of the posts list (admin requests).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'manage_post_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_post_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'post_class', array( self::class, 'row_class' ), 10, 3 );
		add_filter( 'views_edit-post', array( self::class, 'views' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
		add_action( 'restrict_manage_posts', array( self::class, 'keep_view' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * The REST route (every request: REST calls are not admin requests).
	 *
	 * @return void
	 */
	public static function register_rest(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Whether the column belongs on this site: the URGENT bar is on for at least one device.
	 *
	 * @return bool
	 */
	private static function on(): bool {
		return Urgent::enabled( Settings::get() );
	}

	/**
	 * Adds the column right after the title.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public static function columns( $columns ) {
		if ( ! is_array( $columns ) || ! self::on() ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'Urgent', 'horizon-press-news-bar' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Urgent', 'horizon-press-news-bar' );
		}
		return $out;
	}

	/**
	 * Prints the cell.
	 *
	 * @param mixed $column  Column key.
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( $post instanceof WP_Post ) {
			echo self::cell( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in cell().
		}
	}

	/**
	 * The cell of an article: the switch (a badge for someone who may not edit the article), and
	 * under it until when it is urgent, or that it waits for its publication.
	 *
	 * @param WP_Post $post The article.
	 * @return string
	 */
	public static function cell( WP_Post $post ): string {
		$id    = (int) $post->ID;
		$state = Urgent::state( $id );
		$on    = 'off' !== $state;
		$title = wp_strip_all_tags( get_the_title( $post ) );
		$title = '' === $title ? __( '(no title)', 'horizon-press-news-bar' ) : $title;
		/* translators: %s: title of the article. */
		$tick = sprintf( __( 'Mark “%s” as urgent news', 'horizon-press-news-bar' ), $title );
		/* translators: %s: title of the article. */
		$untick = sprintf( __( 'Remove “%s” from urgent news', 'horizon-press-news-bar' ), $title );
		$note   = '';
		if ( 'active' === $state ) {
			$time = (string) get_option( 'time_format' );
			/* translators: %s: time, e.g. 18:10. */
			$note = sprintf( __( 'until %s', 'horizon-press-news-bar' ), wp_date( '' === $time ? 'H:i' : $time, Urgent::until( $id ) ) );
		} elseif ( 'armed' === $state ) {
			$note = __( 'when published', 'horizon-press-news-bar' );
		}
		$icon = $on
			? '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><path d="M9.5 1 3 9h4.2L6.5 15 13 7H8.8L9.5 1Z" fill="currentColor"/></svg>'
			: '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="5.25" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>';
		$text = $on ? __( 'URGENT', 'horizon-press-news-bar' ) : __( 'Urgent', 'horizon-press-news-bar' );

		$html = '<div class="hprnb-urgent-cell" data-hprnb-state="' . esc_attr( $state ) . '">';
		if ( 'trash' !== $post->post_status && current_user_can( 'edit_post', $id ) ) {
			$html .= sprintf(
				'<button type="button" class="hprnb-urgent-switch" aria-pressed="%1$s" aria-label="%2$s" data-hprnb-post="%3$d" data-hprnb-state="%4$s">%5$s<span class="hprnb-urgent-switch__text">%6$s</span></button>',
				$on ? 'true' : 'false',
				esc_attr( $on ? $untick : $tick ),
				$id,
				esc_attr( $state ),
				$icon,
				esc_html( $text )
			);
		} else {
			$html .= sprintf( '<span class="hprnb-urgent-switch is-static" data-hprnb-state="%1$s">%2$s<span class="hprnb-urgent-switch__text">%3$s</span></span>', esc_attr( $state ), $icon, esc_html( $text ) );
		}
		if ( '' !== $note ) {
			$html .= '<span class="hprnb-urgent-cell__note">' . esc_html( $note ) . '</span>';
		}
		return $html . '</div>';
	}

	/**
	 * Tints the row of an article that is in the red bar (the list's rows use post_class()).
	 *
	 * @param mixed $classes Classes.
	 * @param mixed $css_class Extra classes asked for.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public static function row_class( $classes, $css_class = '', $post_id = 0 ) {
		unset( $css_class );
		if ( ! is_array( $classes ) || ! is_admin() || ! self::list_screen() ) {
			return $classes;
		}
		if ( 'active' === Urgent::state( (int) $post_id ) ) {
			$classes[] = self::ROW_CLASS;
		}
		return $classes;
	}

	/**
	 * Whether the current screen is Posts → All Posts.
	 *
	 * @return bool
	 */
	private static function list_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && 'edit-post' === $screen->id;
	}

	/**
	 * IDs of the articles ticked: in the red bar now, or waiting for their publication. Two lookups on
	 * one meta key each (indexed), never the whole table.
	 *
	 * @return int[]
	 */
	public static function ticked_ids(): array {
		if ( null !== self::$ids ) {
			return self::$ids;
		}
		$base      = array(
			'post_type'              => 'post',
			'post_status'            => 'any',
			'fields'                 => 'ids',
			'posts_per_page'         => self::MAX,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
			'orderby'                => 'ID',
		);
		$active    = get_posts(
			$base + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One indexed key, a handful of rows.
					array(
						'key'     => Urgent::META_UNTIL,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$armed     = get_posts(
			$base + array(
				'meta_key'   => Urgent::META_ARMED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One indexed key, a handful of rows.
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Idem.
			)
		);
		self::$ids = array_values( array_unique( array_map( 'intval', array_merge( $active, $armed ) ) ) );
		return self::$ids;
	}

	/**
	 * Clears the per-request memo (after a switch, and in tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ids = null;
	}

	/**
	 * Whether the list is showing the Urgent view.
	 *
	 * @return bool
	 */
	private static function viewing(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter, like the core ones.
		return isset( $_GET[ self::VIEW_ARG ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::VIEW_ARG ] ) );
	}

	/**
	 * Adds "⚡ Urgent (N)" after the core views (All, Mine, Published…), current while shown.
	 *
	 * @param mixed $views Views.
	 * @return mixed
	 */
	public static function views( $views ) {
		if ( ! is_array( $views ) || ! self::on() ) {
			return $views;
		}
		$current = self::viewing();
		if ( $current ) {
			foreach ( $views as $key => $link ) {
				$views[ $key ] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', (string) $link );
			}
		}
		$url                   = add_query_arg(
			array(
				'post_type'    => 'post',
				self::VIEW_ARG => '1',
			),
			admin_url( 'edit.php' )
		);
		$count                 = count( self::ticked_ids() );
		$views[ self::COLUMN ] = sprintf(
			'<a href="%1$s" class="hprnb-urgent-view%2$s"%3$s>%4$s <span class="count">(<span class="hprnb-urgent-count">%5$s</span>)</span></a>',
			esc_url( $url ),
			$current ? ' current' : '',
			$current ? ' aria-current="page"' : '',
			/* translators: the view of Posts → All Posts listing the urgent articles. */
			esc_html__( '⚡ Urgent', 'horizon-press-news-bar' ),
			esc_html( number_format_i18n( $count ) )
		);
		return $views;
	}

	/**
	 * The Urgent view: the main query of the list keeps only the articles ticked (every other filter —
	 * search, dates, categories, authors, sorting, pages — still applies).
	 *
	 * @param mixed $query Query.
	 * @return void
	 */
	public static function filter_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() || ! self::viewing() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow || 'post' !== ( $query->get( 'post_type' ) ? $query->get( 'post_type' ) : 'post' ) || ! self::on() ) {
			return;
		}
		$ids = self::ticked_ids();
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}

	/**
	 * Keeps the view when the list's form is sent (search, filters): a hidden field in that form.
	 *
	 * @param mixed $post_type Post type of the list.
	 * @return void
	 */
	public static function keep_view( $post_type ): void {
		if ( 'post' === $post_type && self::viewing() ) {
			echo '<input type="hidden" name="' . esc_attr( self::VIEW_ARG ) . '" value="1" />';
		}
	}

	/**
	 * The switch's script and styles, on Posts → All Posts only, with the red of the URGENT bar.
	 *
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix || ! self::list_screen() || ! self::on() ) {
			return;
		}
		$settings = Settings::get();
		wp_enqueue_style( 'hprnb-urgent-list', HPRNB_URL . 'assets/css/hprnb-urgent-list' . Assets::suffix() . '.css', array(), HPRNB_VERSION );
		wp_add_inline_style(
			'hprnb-urgent-list',
			sprintf(
				'.wp-list-table{--hprnb-ul-red:%1$s;--hprnb-ul-ink:%2$s}',
				esc_html( (string) $settings['urgent_bg_color'] ),
				esc_html( (string) $settings['urgent_text_color'] )
			)
		);
		wp_enqueue_script( 'hprnb-urgent-list', HPRNB_URL . 'assets/js/hprnb-urgent-list' . Assets::suffix() . '.js', array( 'wp-a11y' ), HPRNB_VERSION, true );
		wp_localize_script(
			'hprnb-urgent-list',
			'hprnbUrgentList',
			array(
				'endpoint' => esc_url_raw( rest_url( Rest_Controller::NAMESPACE . '/urgent/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'i18n'     => array(
					'error'  => __( 'The Urgent switch could not be saved. Nothing was changed; please try again.', 'horizon-press-news-bar' ),
					'on'     => __( 'Marked as urgent.', 'horizon-press-news-bar' ),
					'armed'  => __( 'Marked as urgent: the countdown starts when it is published.', 'horizon-press-news-bar' ),
					'off'    => __( 'No longer urgent.', 'horizon-press-news-bar' ),
					'saving' => __( 'Saving…', 'horizon-press-news-bar' ),
				),
			)
		);
	}

	/**
	 * POST /hprnb/v1/urgent/<id> { urgent: bool }.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/urgent/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'toggle' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'urgent' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Someone who may edit that very article (cookie authentication needs the REST nonce).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permission( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return new WP_Error( 'hprnb_urgent_not_found', __( 'No such article.', 'horizon-press-news-bar' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return new WP_Error( 'hprnb_urgent_forbidden', __( 'You may not edit this article.', 'horizon-press-news-bar' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Ticks or unticks the article (the rule of the edit screen's box: never a restart of a countdown
	 * already running), and answers with its new cell, rendered as the list renders it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function toggle( WP_REST_Request $request ) {
		$settings = Settings::get();
		if ( ! Urgent::enabled( $settings ) ) {
			return new WP_Error( 'hprnb_urgent_off', __( 'The URGENT bar is switched off in Settings → News Bar.', 'horizon-press-news-bar' ), array( 'status' => 409 ) );
		}
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			return new WP_Error( 'hprnb_urgent_not_found', __( 'No such article.', 'horizon-press-news-bar' ), array( 'status' => 404 ) );
		}
		Urgent::apply( $post, (bool) $request['urgent'], false, $settings );
		self::reset();
		$state = Urgent::state( (int) $post->ID );
		return new WP_REST_Response(
			array(
				'id'    => (int) $post->ID,
				'state' => $state,
				'until' => 'active' === $state ? Urgent::until( (int) $post->ID ) : 0,
				'html'  => self::cell( $post ),
				'count' => count( self::ticked_ids() ),
			),
			200
		);
	}
}
