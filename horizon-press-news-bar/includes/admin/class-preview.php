<?php
/**
 * Private REST endpoint rendering a preview from unsaved settings.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar\Admin;

use HorizonPress\NewsBar\Query;
use HorizonPress\NewsBar\Renderer;
use HorizonPress\NewsBar\Rest_Controller;
use HorizonPress\NewsBar\Settings;
use HorizonPress\NewsBar\Urgent;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/hprnb/v1/preview — manage_options + REST nonce, never cached, never persisted.
 */
final class Preview {

	/**
	 * Hooks route registration (safe to call in any context).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the private route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest_Controller::NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => array( self::class, 'permission' ),
				'args'                => array(
					'settings' => array(
						'type'     => 'object',
						'required' => true,
					),
					'urgent'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Only administrators (cookie auth requires a valid REST nonce).
	 *
	 * @return bool
	 */
	public static function permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds a payload from the submitted (unsaved) settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function preview( WP_REST_Request $request ): WP_REST_Response {
		$input    = $request->get_param( 'settings' );
		$settings = Settings::sanitize_form( is_array( $input ) ? $input : array() );
		$urgent   = (bool) $request->get_param( 'urgent' );

		if ( $urgent ) {
			// The Urgent tab: two invented breaking stories, never expiring, so the red bar can be seen and styled.
			$items = Urgent::sample_items();
			$body  = array(
				'count'  => count( $items ),
				'html'   => Renderer::urgent_bar( $items, $settings ),
				'style'  => Renderer::root_style( $settings ),
				'urgent' => true,
			);
		} else {
			// The news bar's design can be prepared while it is switched off (2.16): its preview ignores
			// the switches, and the URGENT articles, which have a tab of their own.
			$payload = Renderer::payload( Query::items( $settings ), $settings );
			$body    = array(
				'count'  => (int) $payload['count'],
				'html'   => (string) $payload['html'],
				'style'  => Renderer::root_style( $settings ),
				'urgent' => false,
			);
		}

		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
