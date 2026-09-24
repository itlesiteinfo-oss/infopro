<?php
/**
 * List template: the `<ul>` of items.
 *
 * Available: $context['items'], $context['settings'], $context['urgent'], $context['here'].
 * Override by copying this file to {theme}/horizon-press-news-bar/list.php.
 *
 * @package HorizonPress\NewsBar
 * @var array $context
 */

use HorizonPress\NewsBar\Renderer;

defined( 'ABSPATH' ) || exit;

$hprnb_list = '';
foreach ( $context['items'] as $hprnb_item ) {
	$hprnb_list .= Renderer::render_template(
		'item',
		array(
			'item'     => $hprnb_item,
			'settings' => $context['settings'],
			'urgent'   => ! empty( $context['urgent'] ),
			'here'     => (int) ( $context['here'] ?? 0 ),
		)
	);
}
?>
<ul class="hprnb-bar__list">
<?php echo $hprnb_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Item templates escape their own output. ?>
</ul>
