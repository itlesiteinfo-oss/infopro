<?php
/**
 * Item template: one `<li>`.
 *
 * Available: $context['item'] (normalised item), $context['settings'], $context['urgent'] and $context['here'] (2.17: the article being read).
 * Override by copying this file to {theme}/horizon-press-news-bar/item.php.
 *
 * @package HorizonPress\NewsBar
 * @var array $context
 */

use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Settings;

defined( 'ABSPATH' ) || exit;

$hprnb_item     = $context['item'];
$hprnb_settings = $context['settings'];

if ( empty( $hprnb_item['title'] ) || empty( $hprnb_item['url'] ) ) {
	return;
}

$hprnb_urgent = ! empty( $context['urgent'] );
$hprnb_thumb  = ( ! $hprnb_urgent && Settings::wants_thumbnails( $hprnb_settings ) && ! empty( $hprnb_item['thumb']['url'] ) ) ? $hprnb_item['thumb'] : null;
// An urgent headline carries its flag time and its expiry (2.14): the script ends it on time.
$hprnb_timing = $hprnb_urgent ? ' data-hprnb-since="' . (int) ( $hprnb_item['since'] ?? 0 ) . '" data-hprnb-until="' . (int) ( $hprnb_item['until'] ?? 0 ) . '"' : '';
// The article being read (2.17): out of sight from the first paint, the script takes it out of the rotation.
$hprnb_here = $hprnb_urgent && (int) ( $context['here'] ?? 0 ) > 0 && (int) $hprnb_item['id'] === (int) $context['here'];
?>
<li class="hprnb-bar__item<?php echo $hprnb_here ? ' hprnb-bar__item--here' : ''; ?>" data-hprnb-id="<?php echo (int) $hprnb_item['id']; ?>"<?php echo $hprnb_timing; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Two integer attributes built below. ?>>
	<a class="hprnb-bar__link" href="<?php echo esc_url( $hprnb_item['url'] ); ?>">
		<?php if ( null !== $hprnb_thumb ) : ?>
		<img class="hprnb-bar__thumb" src="<?php echo esc_url( $hprnb_thumb['url'] ); ?>" width="<?php echo (int) $hprnb_thumb['width']; ?>" height="<?php echo (int) $hprnb_thumb['height']; ?>" loading="lazy" decoding="async" alt="">
		<?php endif; ?>
		<span class="hprnb-bar__title"><?php echo esc_html( $hprnb_item['title'] ); ?></span>
		<?php if ( ! empty( $hprnb_settings['show_relative_time'] ) ) : ?>
		<time class="hprnb-bar__time" datetime="<?php echo esc_attr( $hprnb_item['datetime'] ); ?>" data-hprnb-ts="<?php echo (int) $hprnb_item['timestamp']; ?>" data-hprnb-abs="<?php echo esc_attr( $hprnb_item['date_label'] ); ?>"><?php echo esc_html( Renderer::relative_time_label( (int) $hprnb_item['timestamp'], $hprnb_settings ) ); ?></time>
		<?php endif; ?>
	</a>
</li>
