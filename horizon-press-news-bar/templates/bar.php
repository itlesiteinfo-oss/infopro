<?php
/**
 * Bar template: the complete `<aside>` element.
 *
 * Available: $context['items'] (normalised items), $context['settings'] (settings).
 * Override by copying this file to {theme}/horizon-press-news-bar/bar.php.
 *
 * @package HorizonPress\NewsBar
 * @var array $context
 */

use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

defined( 'ABSPATH' ) || exit;

$hprnb_items    = $context['items'];
$hprnb_settings = $context['settings'];
$hprnb_urgent   = ! empty( $context['urgent'] ); // The red bar of the urgent articles (2.14): same skeleton, its own label and skin.
$hprnb_ticker   = ! empty( $hprnb_settings['ticker_enabled'] ) ? (string) $hprnb_settings['ticker_mode'] : 'none';
$hprnb_label    = (string) ( $hprnb_urgent ? $hprnb_settings['urgent_label'] : $hprnb_settings['label_text'] );
// The urgent label ends with a chevron into the headline (mirrored right-to-left by the stylesheet).
$hprnb_chevron = $hprnb_urgent ? '<span class="hprnb-bar__label-chevron" aria-hidden="true">' . Renderer::icon( 'next' ) . '</span>' : '';

$hprnb_classes = array(
	'hprnb-bar',
	'hprnb-bar--label-' . ( 'start' === $hprnb_settings['label_position'] ? 'start' : 'end' ),
	'hprnb-bar--' . ( 'overlay' === $hprnb_settings['layout_mode'] ? 'overlay' : 'reserve' ),
	'hprnb-bar--ticker-' . $hprnb_ticker,
);
if ( $hprnb_urgent ) {
	$hprnb_classes[] = 'hprnb-bar--urgent';
}
if ( Settings::wants_thumbnails( $hprnb_settings ) ) {
	$hprnb_classes[] = 'hprnb-bar--has-thumbs';
}
if ( ! empty( $hprnb_settings['show_relative_time'] ) ) {
	$hprnb_classes[] = 'hprnb-bar--has-time';
}

$hprnb_mobile       = Renderer::mobile_ticker( $hprnb_settings );
$hprnb_has_toggle   = in_array( $hprnb_ticker, array( 'marquee', 'rotate' ), true ) || in_array( $hprnb_mobile, array( 'marquee', 'rotate' ), true );
$hprnb_has_manual   = ( 'manual' === $hprnb_ticker || 'manual' === $hprnb_mobile );
$hprnb_has_close    = ! empty( $hprnb_settings['close_button'] );
$hprnb_has_controls = $hprnb_has_toggle || $hprnb_has_manual || $hprnb_has_close;
?>
<aside class="<?php echo esc_attr( implode( ' ', $hprnb_classes ) ); ?>" role="region" aria-label="<?php echo esc_attr( $hprnb_urgent ? __( 'Breaking news', 'horizon-press-news-bar' ) : __( 'Latest news', 'horizon-press-news-bar' ) ); ?>" aria-live="off" dir="auto" data-hprnb-ticker="<?php echo esc_attr( $hprnb_ticker ); ?>" data-hprnb-ticker-mobile="<?php echo esc_attr( $hprnb_mobile ); ?>" data-hprnb-speed="<?php echo (int) $hprnb_settings['ticker_speed']; ?>" data-hprnb-interval="<?php echo (int) $hprnb_settings['rotate_interval']; ?>" data-hprnb-hover="<?php echo empty( $hprnb_settings['pause_on_hover'] ) ? '0' : '1'; ?>" data-hprnb-remember="<?php echo empty( $hprnb_settings['remember_dismiss'] ) ? '0' : '1'; ?>" data-hprnb-dismiss-hours="<?php echo (int) $hprnb_settings['dismiss_duration_hours']; ?>" data-hprnb-reltime="<?php echo empty( $hprnb_settings['show_relative_time'] ) ? '0' : '1'; ?>" data-hprnb-reltime-max="<?php echo (int) $hprnb_settings['relative_time_max_hours']; ?>" data-hprnb-label-expand="<?php esc_attr_e( 'Show the latest news', 'horizon-press-news-bar' ); ?>">
	<div class="hprnb-bar__inner">
		<?php
		/**
		 * Fires inside the bar, before the label and the list.
		 *
		 * @param array $items    Normalised items.
		 * @param array $settings Settings.
		 */
		do_action( 'hprnb_before_bar', $hprnb_items, $hprnb_settings );
		?>
		<?php if ( '' !== $hprnb_label ) : ?>
		<p class="hprnb-bar__label"><span class="hprnb-bar__label-text"><?php echo esc_html( $hprnb_label ); ?></span><?php echo $hprnb_chevron; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></p>
		<?php endif; ?>
		<div class="hprnb-bar__viewport">
			<?php echo Renderer::render_template( 'list', $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output, escaped at the source. ?>
		</div>
		<?php if ( $hprnb_has_controls ) : ?>
		<div class="hprnb-bar__controls">
			<?php if ( $hprnb_has_manual ) : ?>
			<button type="button" class="hprnb-bar__btn hprnb-bar__btn--prev" aria-label="<?php esc_attr_e( 'Previous', 'horizon-press-news-bar' ); ?>" disabled><?php echo Renderer::icon( 'prev' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
			<button type="button" class="hprnb-bar__btn hprnb-bar__btn--next" aria-label="<?php esc_attr_e( 'Next', 'horizon-press-news-bar' ); ?>"><?php echo Renderer::icon( 'next' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
			<?php endif; ?>
			<?php if ( $hprnb_has_toggle ) : ?>
			<button type="button" class="hprnb-bar__btn hprnb-bar__btn--toggle" aria-label="<?php esc_attr_e( 'Pause', 'horizon-press-news-bar' ); ?>" data-hprnb-label-pause="<?php esc_attr_e( 'Pause', 'horizon-press-news-bar' ); ?>" data-hprnb-label-play="<?php esc_attr_e( 'Play', 'horizon-press-news-bar' ); ?>"><?php echo Renderer::icon( 'pause' ) . Renderer::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
			<?php endif; ?>
			<?php if ( $hprnb_has_close ) : ?>
			<button type="button" class="hprnb-bar__btn hprnb-bar__btn--close" aria-label="<?php esc_attr_e( 'Close the news bar', 'horizon-press-news-bar' ); ?>"><?php echo Renderer::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></button>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php
		/**
		 * Fires inside the bar, after the list and the controls.
		 *
		 * @param array $items    Normalised items.
		 * @param array $settings Settings.
		 */
		do_action( 'hprnb_after_bar', $hprnb_items, $hprnb_settings );
		?>
	</div>
</aside>
