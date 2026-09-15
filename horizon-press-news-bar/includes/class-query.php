<?php
/**
 * Post selection: builds the WP_Query arguments and normalises the results.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use DateTimeImmutable;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Selects published posts inside the sliding window. Renders no HTML.
 */
final class Query {

	/**
	 * WP_Query arguments for the current settings.
	 *
	 * @param array                  $settings Settings.
	 * @param DateTimeImmutable|null $now      Optional reference instant (tests).
	 * @return array
	 */
	public static function args( array $settings, ?DateTimeImmutable $now = null ): array {
		$permalink_structure = (string) get_option( 'permalink_structure' );

		$args = array(
			'post_type'              => array( 'post' ),
			'post_status'            => 'publish',
			'has_password'           => false,
			'ignore_sticky_posts'    => true,
			'posts_per_page'         => max( 1, (int) $settings['max_items'] ),
			'no_found_rows'          => true,
			'update_post_term_cache' => false !== strpos( $permalink_structure, '%category%' ),
			'update_post_meta_cache' => Settings::wants_thumbnails( $settings ),
			'date_query'             => Time_Window::date_query( $settings, $now ),
			'orderby'                => 'date',
			'order'                  => 'date_asc' === $settings['orderby'] ? 'ASC' : 'DESC',
		);

		$lists = array(
			'category__in'     => 'categories_include',
			'category__not_in' => 'categories_exclude',
			'tag__in'          => 'tags_include',
			'post__not_in'     => 'content_exclude_post_ids',
		);
		foreach ( $lists as $arg => $setting ) {
			if ( ! empty( $settings[ $setting ] ) && is_array( $settings[ $setting ] ) ) {
				$args[ $arg ] = array_map( 'intval', $settings[ $setting ] );
			}
		}

		/**
		 * Filters the WP_Query arguments used to select the bar items.
		 *
		 * @param array $args     Query arguments.
		 * @param array $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_query_args', $args, $settings );

		return is_array( $filtered ) ? $filtered : $args;
	}

	/**
	 * Runs the query and returns normalised items (never WP_Post objects).
	 *
	 * @param array                  $settings Settings.
	 * @param DateTimeImmutable|null $now      Optional reference instant (tests).
	 * @return array<int, array>
	 */
	public static function items( array $settings, ?DateTimeImmutable $now = null ): array {
		$query = new WP_Query( self::args( $settings, $now ) );
		$posts = is_array( $query->posts ) ? $query->posts : array();

		if ( Settings::wants_thumbnails( $settings ) && ! empty( $posts ) ) {
			update_post_thumbnail_cache( $query );
		}

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$item = self::normalize( $post, $settings );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		/**
		 * Filters the normalised items before caching and rendering.
		 *
		 * @param array $items    Items.
		 * @param array $settings Settings.
		 */
		$filtered = apply_filters( 'hprnb_items', $items, $settings );

		return is_array( $filtered ) ? array_values( $filtered ) : $items;
	}

	/**
	 * Normalises one post into a plain array. Returns null when the title is empty.
	 *
	 * @param WP_Post $post     Post.
	 * @param array   $settings Settings.
	 * @return array|null
	 */
	public static function normalize( WP_Post $post, array $settings ): ?array {
		$title = trim( wp_strip_all_tags( (string) get_the_title( $post ) ) );
		if ( '' === $title ) {
			return null;
		}

		$timestamp = get_post_timestamp( $post );
		if ( false === $timestamp ) {
			return null;
		}

		$datetime = get_post_datetime( $post );
		$format   = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );

		$item = array(
			'id'         => (int) $post->ID,
			'title'      => $title,
			'url'        => (string) get_permalink( $post ),
			'timestamp'  => (int) $timestamp,
			'datetime'   => false !== $datetime ? $datetime->format( 'c' ) : wp_date( 'c', (int) $timestamp ),
			'date_label' => (string) wp_date( '' === $format ? 'Y-m-d H:i' : $format, (int) $timestamp ),
			'thumb'      => null,
		);

		if ( Settings::wants_thumbnails( $settings ) ) {
			$thumb_id = (int) get_post_thumbnail_id( $post );
			if ( $thumb_id > 0 ) {
				$src = wp_get_attachment_image_src( $thumb_id, self::thumbnail_size( $settings ) );
				if ( is_array( $src ) && ! empty( $src[0] ) ) {
					$item['thumb'] = array(
						'url'    => (string) $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
					);
				}
			}
		}

		return $item;
	}

	/**
	 * Registered image size to use for thumbnails (falls back to `thumbnail`).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function thumbnail_size( array $settings ): string {
		$size = isset( $settings['thumbnail_size'] ) ? (string) $settings['thumbnail_size'] : 'thumbnail';
		return in_array( $size, get_intermediate_image_sizes(), true ) ? $size : 'thumbnail';
	}
}
