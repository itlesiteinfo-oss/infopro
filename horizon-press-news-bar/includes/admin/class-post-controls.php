<?php
/**
 * Per-post controls: keep one article out of the bar, or keep the bar off one page.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * A small meta box on the edit screen, for the two decisions that belong to a single post rather
 * than to the whole site. Classic editor and block editor alike: the block editor still posts
 * registered meta boxes through its own request, so `save_post` carries both checkboxes.
 */
final class Post_Controls {

	/**
	 * Meta key: this article is never listed among the headlines.
	 */
	const META_EXCLUDE = Settings::META_EXCLUDE;

	/**
	 * Meta key: the bar is never displayed on this post's own page.
	 */
	const META_HIDE = Settings::META_HIDE;

	/**
	 * Nonce action and field name.
	 */
	const NONCE = 'hprnb_post_controls';

	/**
	 * Hooks the meta box and its save handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Every public post type that can carry a front-end page, plus posts themselves.
	 *
	 * @return array
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		/**
		 * Filters the post types carrying the per-post news bar controls.
		 *
		 * @param array $types Post type names.
		 */
		return (array) apply_filters( 'hprnb_post_control_types', array_values( $types ) );
	}

	/**
	 * Registers the box on every eligible post type.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			'hprnb-post-controls',
			__( 'News Bar', 'horizon-press-news-bar' ),
			array( self::class, 'render' ),
			self::post_types(),
			'side',
			'default'
		);
	}

	/**
	 * Whether a post carries one of the two flags.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     One of the META_* constants.
	 * @return bool
	 */
	public static function flagged( int $post_id, string $key ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		return '1' === (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Draws the two checkboxes.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$id      = (int) $post->ID;
		$is_post = 'post' === $post->post_type;

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label for="hprnb-hide-bar">
				<input type="checkbox" id="hprnb-hide-bar" name="<?php echo esc_attr( self::META_HIDE ); ?>" value="1" <?php checked( self::flagged( $id, self::META_HIDE ) ); ?> />
				<?php esc_html_e( 'Never show the bar on this page', 'horizon-press-news-bar' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'Visitors reading this page see no bar at all, whatever the page types allow.', 'horizon-press-news-bar' ); ?></span>
		</p>
		<?php if ( $is_post ) : ?>
		<p>
			<label for="hprnb-exclude-item">
				<input type="checkbox" id="hprnb-exclude-item" name="<?php echo esc_attr( self::META_EXCLUDE ); ?>" value="1" <?php checked( self::flagged( $id, self::META_EXCLUDE ) ); ?> />
				<?php esc_html_e( 'Never list this article in the bar', 'horizon-press-news-bar' ); ?>
			</label>
			<span class="description"><?php esc_html_e( 'The headline is left out of the bar everywhere on the site. The two options are independent.', 'horizon-press-news-bar' ); ?></span>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Persists both checkboxes. Absent means false, so unticking really unticks.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		// The block editor posts its meta boxes in a second request; both carry the nonce. A REST
		// save without the box (quick edit, bulk edit, an API client) must leave the flags alone.
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( $post instanceof WP_Post && ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}

		$keys = array( self::META_HIDE );
		if ( ! $post instanceof WP_Post || 'post' === $post->post_type ) {
			$keys[] = self::META_EXCLUDE;
		}

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			$raw = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : '';
			$on  = '' !== $raw && Settings::to_bool_loose( $raw );
			if ( $on ) {
				update_post_meta( $post_id, $key, '1' );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}
