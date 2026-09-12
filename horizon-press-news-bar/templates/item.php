<?php
/**
 * Item template: one `<li>`.
 *
 * Available: $context['item'] (normalised item), $context['settings'].
 * Override by copying this file to {theme}/horizon-press-news-bar/item.php.
 *
 * @package HorizonPress\NewsBar
 * @var array $context
 */

use HorizonPress\NewsBar\Renderer;

defined( 'ABSPATH' ) || exit;

$hprnb_item     = $context['item'];
$hprnb_settings = $context['settings'];

if ( empty( $hprnb_item['title'] ) || empty( $hprnb_item['url'] ) ) {
	return;
}

$hprnb_thumb = ( ! empty( $hprnb_settings['show_thumbnail'] ) && ! empty( $hprnb_item['thumb']['url'] ) ) ? $hprnb_item['thumb'] : null;
?>
<li class="hprnb-bar__item">
	<a class="hprnb-bar__link" href="<?php echo esc_url( $hprnb_item['url'] ); ?>">
		<?php if ( null !== $hprnb_thumb ) : ?>
		<img class="hprnb-bar__thumb" src="<?php echo esc_url( $hprnb_thumb['url'] ); ?>" width="<?php echo (int) $hprnb_thumb['width']; ?>" height="<?php echo (int) $hprnb_thumb['height']; ?>" loading="lazy" decoding="async" alt="">
		<?php endif; ?>
		<span class="hprnb-bar__title"><?php echo esc_html( $hprnb_item['title'] ); ?></span>
		<?php if ( ! empty( $hprnb_settings['show_relative_time'] ) ) : ?>
		<time class="hprnb-bar__time" datetime="<?php echo esc_attr( $hprnb_item['datetime'] ); ?>" data-hprnb-ts="<?php echo (int) $hprnb_item['timestamp']; ?>" data-hprnb-abs="<?php echo esc_attr( $hprnb_item['date_label'] ); ?>"><?php echo esc_html( Renderer::relative_time_label( (int) $hprnb_item['timestamp'], $hprnb_settings ) ); ?></time>
		<?php endif; ?>
	</a>
	<?php if ( ! empty( $hprnb_settings['show_separator'] ) ) : ?>
	<span class="hprnb-bar__sep" aria-hidden="true"><?php echo esc_html( $hprnb_settings['separator_char'] ); ?></span>
	<?php endif; ?>
</li>
