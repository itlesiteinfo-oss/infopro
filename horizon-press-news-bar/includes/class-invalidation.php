<?php
/**
 * Cache invalidation through epoch rotation.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Rotates the cache epoch when content or settings change. At most once per request.
 */
final class Invalidation {

	/**
	 * Static guard: one invalidation per PHP request.
	 *
	 * @var bool
	 */
	private static bool $done = false;

	/**
	 * Taxonomies that influence the selection.
	 */
	const TAXONOMIES = array( 'category', 'post_tag' );

	/**
	 * Hooks every trigger.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'transition_post_status', array( self::class, 'on_transition_post_status' ), 10, 3 );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 10, 2 );
		add_action( 'trashed_post', array( self::class, 'on_deleted_post' ), 10, 2 );
		add_action( 'untrashed_post', array( self::class, 'on_deleted_post' ), 10, 2 );
		add_action( 'set_object_terms', array( self::class, 'on_set_object_terms' ), 10, 4 );
		add_action( 'delete_term', array( self::class, 'on_delete_term' ), 10, 3 );
		add_action( 'added_post_meta', array( self::class, 'on_post_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_post_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_post_meta' ), 10, 3 );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'on_settings_changed' ) );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'on_settings_changed' ) );
		add_action( 'switch_theme', array( self::class, 'invalidate' ) );
	}

	/**
	 * Rotates the epoch (new unique value), clears the request memo and fires `hprnb_cache_invalidated`.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		if ( self::$done ) {
			return;
		}
		self::$done = true;

		$epoch = wp_generate_uuid4();
		if ( ! update_option( Settings::EPOCH_OPTION, $epoch, true ) ) {
			add_option( Settings::EPOCH_OPTION, $epoch, '', true );
		}
		Payload::flush();

		/**
		 * Fires after the bar cache has been invalidated.
		 */
		do_action( 'hprnb_cache_invalidated' );
	}

	/**
	 * Resets the static guard (tests only).
	 *
	 * @return void
	 */
	public static function reset_guard(): void {
		self::$done = false;
	}

	/**
	 * Post entering or leaving `publish`, or a published post being saved.
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
		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			self::invalidate();
		}
	}

	/**
	 * Post deleted, trashed or restored.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    Post object (deleted_post) or previous status (trashed/untrashed_post).
	 * @return void
	 */
	public static function on_deleted_post( $post_id, $post = null ): void {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( (int) $post_id );
		if ( 'post' === $type ) {
			self::invalidate();
		}
	}

	/**
	 * Category or tag assignment changed on a published post.
	 *
	 * @param mixed $object_id Post ID.
	 * @param mixed $terms     Terms.
	 * @param mixed $tt_ids    Term taxonomy IDs.
	 * @param mixed $taxonomy  Taxonomy.
	 * @return void
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( ! is_string( $taxonomy ) || ! in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( $post instanceof WP_Post && 'post' === $post->post_type && 'publish' === $post->post_status ) {
			self::invalidate();
		}
	}

	/**
	 * Category or tag deleted.
	 *
	 * @param mixed $term     Term ID.
	 * @param mixed $tt_id    Term taxonomy ID.
	 * @param mixed $taxonomy Taxonomy.
	 * @return void
	 */
	public static function on_delete_term( $term, $tt_id, $taxonomy ): void {
		if ( is_string( $taxonomy ) && in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Featured image changed on a post.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public static function on_post_meta( $meta_id, $object_id, $meta_key ): void {
		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}
		if ( 'post' === get_post_type( (int) $object_id ) ) {
			self::invalidate();
		}
	}

	/**
	 * Settings saved (admin form, import, reset).
	 *
	 * @return void
	 */
	public static function on_settings_changed(): void {
		Settings::flush();
		self::invalidate();
	}
}
