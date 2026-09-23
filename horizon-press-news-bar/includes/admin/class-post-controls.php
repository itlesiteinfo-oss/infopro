<?php
/**
 * Per-post controls: keep one article out of the bar, or keep the bar off one page.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Assets;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;
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
	 * Form fields of the urgent box (2.14): the flag itself, and "start over from now" on an update.
	 */
	const FIELD_URGENT  = 'hprnb_urgent';
	const FIELD_RESTART = 'hprnb_urgent_restart';

	/**
	 * The URGENT box of its own (2.15): id, and its nonce action and field name.
	 */
	const URGENT_BOX   = 'hprnb-urgent-postbox';
	const URGENT_NONCE = 'hprnb_urgent_box';

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
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * The box's own few rules, on the edit screens only.
	 *
	 * @param mixed $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'hprnb-post', HPRNB_URL . 'assets/css/hprnb-post' . Assets::suffix() . '.css', array(), HPRNB_VERSION );
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
		// 2.15: the urgent flag in a box of its own, first at the top of the side column — "high" boxes
		// come before the Publish box and before any order an editor saved by dragging boxes around.
		if ( Urgent::enabled( Settings::get() ) ) {
			add_meta_box(
				self::URGENT_BOX,
				__( 'URGENT bar', 'horizon-press-news-bar' ),
				array( self::class, 'render_urgent_box' ),
				array( 'post' ),
				'side',
				'high'
			);
		}
	}

	/**
	 * Draws the URGENT box: its own nonce, then the checkbox and the state of the countdown.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public static function render_urgent_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		wp_nonce_field( self::URGENT_NONCE, self::URGENT_NONCE );
		self::render_urgent( (int) $post->ID, $post );
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
	 * The urgent section (2.14): one checkbox that starts the red bar's countdown when the article is
	 * published or updated, the state while it runs, and "start over" for an update meant as news.
	 *
	 * @param int     $id   Post ID.
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	private static function render_urgent( int $id, WP_Post $post ): void {
		$settings = Settings::get();
		?>
		<div class="hprnb-urgent-box">
		<?php if ( ! Urgent::enabled( $settings ) ) : ?>
			<p class="description"><?php esc_html_e( 'The URGENT bar is switched off in Settings → News Bar → Urgent.', 'horizon-press-news-bar' ); ?></p>
		<?php else : ?>
			<?php
			$now       = time();
			$until     = Urgent::until( $id );
			$active    = $until > $now;
			$armed     = Urgent::is_armed( $id );
			$published = 'publish' === $post->post_status;
			$minutes   = (int) $settings['urgent_minutes'];
			$time      = (string) get_option( 'time_format' );
			$time      = '' === $time ? 'H:i' : $time;
			?>
			<p>
				<label for="hprnb-urgent" class="hprnb-urgent-box__label">
					<input type="checkbox" id="hprnb-urgent" name="<?php echo esc_attr( self::FIELD_URGENT ); ?>" value="1" <?php checked( $active || $armed ); ?> />
					<strong><?php esc_html_e( 'Urgent article', 'horizon-press-news-bar' ); ?></strong>
				</label>
				<span class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of minutes. */
						_n( 'For %d minute after you publish or update, the red URGENT bar shows this headline instead of the news bar, on every page and every device.', 'For %d minutes after you publish or update, the red URGENT bar shows this headline instead of the news bar, on every page and every device.', $minutes, 'horizon-press-news-bar' ),
						$minutes
					)
				);
				?>
				</span>
			</p>
			<?php if ( $active ) : ?>
			<p class="hprnb-urgent-box__state">
				<?php
				$left = (int) ceil( ( $until - $now ) / MINUTE_IN_SECONDS );
				echo esc_html(
					sprintf(
						/* translators: 1: time, e.g. 18:10; 2: number of minutes left. */
						_n( 'Urgent until %1$s (%2$d minute left).', 'Urgent until %1$s (%2$d minutes left).', $left, 'horizon-press-news-bar' ),
						wp_date( $time, $until ),
						$left
					)
				);
				?>
				<span class="description"><?php esc_html_e( 'Untick and update to end it now.', 'horizon-press-news-bar' ); ?></span>
			</p>
			<p>
				<label for="hprnb-urgent-restart">
					<input type="checkbox" id="hprnb-urgent-restart" name="<?php echo esc_attr( self::FIELD_RESTART ); ?>" value="1" />
					<?php esc_html_e( 'Start the countdown over from now when I update', 'horizon-press-news-bar' ); ?>
				</label>
			</p>
			<?php elseif ( $armed && ! $published ) : ?>
			<p class="hprnb-urgent-box__state"><?php esc_html_e( 'The countdown starts when the article is published.', 'horizon-press-news-bar' ); ?></p>
			<?php elseif ( $until > 0 ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: time, e.g. 18:10. */
						__( 'Was urgent until %s. Tick the box again and update to start over.', 'horizon-press-news-bar' ),
						wp_date( $time, $until )
					)
				);
				?>
			</p>
			<?php endif; ?>
		<?php endif; ?>
		</div>
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
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// 2.15: the URGENT box has a nonce of its own; either box may be hidden from Screen Options.
		if ( $post instanceof WP_Post && 'post' === $post->post_type && self::verified( self::URGENT_NONCE ) ) {
			self::save_urgent( $post_id, $post );
		}
		// The block editor posts its meta boxes in a second request; both carry the nonce. A REST
		// save without the box (quick edit, bulk edit, an API client) must leave the flags alone.
		if ( ! self::verified( self::NONCE ) ) {
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

	/**
	 * Whether the request carries a valid nonce of one of the two boxes.
	 *
	 * @param string $action Nonce action, also the field name.
	 * @return bool
	 */
	private static function verified( string $action ): bool {
		return isset( $_POST[ $action ] ) && false !== wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ $action ] ) ), $action );
	}

	/**
	 * The urgent flag (2.14). Ticked on a published article: the countdown starts, unless it is
	 * already running and "start over" is not ticked — a typo fixed two minutes in does not give the
	 * red bar a fresh ten minutes. Ticked on an unpublished article: the countdown waits for the
	 * publication. Unticked: over at once. The nonce and the capability were verified by the caller.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	private static function save_urgent( int $post_id, WP_Post $post ): void {
		$settings = Settings::get();
		if ( ! Urgent::enabled( $settings ) ) {
			return; // The box was not shown: leave whatever the article carries alone.
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by save() through verified().
		$want    = isset( $_POST[ self::FIELD_URGENT ] ) && Settings::to_bool_loose( sanitize_key( wp_unslash( $_POST[ self::FIELD_URGENT ] ) ) );
		$restart = isset( $_POST[ self::FIELD_RESTART ] ) && Settings::to_bool_loose( sanitize_key( wp_unslash( $_POST[ self::FIELD_RESTART ] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $want ) {
			Urgent::unflag( $post_id );
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			if ( ! Urgent::is_armed( $post_id ) ) {
				Urgent::arm( $post_id );
			}
			return;
		}
		if ( $restart || ! Urgent::is_active( $post_id ) ) {
			Urgent::flag( $post_id, $settings );
		}
	}
}
