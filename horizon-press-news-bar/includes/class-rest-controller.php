<?php
/**
 * Public REST endpoint serving the cached bar payload.
 *
 * @package HorizonPress\NewsBar
 */

namespace HorizonPress\NewsBar;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/hprnb/v1/items — read-only, anonymous, same cache and renderer as SSR.
 */
final class Rest_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'hprnb/v1';

	/**
	 * Cache-Control value for the items response.
	 */
	const CACHE_CONTROL = 'public, max-age=60, s-maxage=60, stale-while-revalidate=120';

	/**
	 * Hooks route registration.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the public route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_items' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Route callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_items( WP_REST_Request $request ): WP_REST_Response {
		$settings = Settings::get();

		// The news bar switched off leaves the URGENT bar (2.16): the payload carries no headline then.
		if ( empty( $settings['enabled'] ) && ! Urgent::enabled( $settings ) ) {
			$payload = Renderer::payload( array(), $settings );
		} else {
			$payload = Payload::get( $settings );
		}

		$body = array(
			'version'      => HPRNB_VERSION,
			'generated_at' => (int) $payload['generated_at'],
			'count'        => (int) $payload['count'],
			'html'         => (string) $payload['html'],
			// Urgent articles (2.14): the bootstrap puts their bar in front of the news bar.
			'urgent_count' => (int) ( $payload['urgent_count'] ?? 0 ),
			'urgent_html'  => (string) ( $payload['urgent_html'] ?? '' ),
		);

		$etag    = self::etag( $body );
		$headers = array(
			'Cache-Control' => self::CACHE_CONTROL,
			'ETag'          => $etag,
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', $body['generated_at'] ) . ' GMT',
		);

		$if_none_match = $request->get_header( 'if_none_match' );
		if ( is_string( $if_none_match ) && self::etag_matches( $if_none_match, $etag ) ) {
			$response = new WP_REST_Response( null, 304 );
			$response->set_headers( $headers );
			add_filter( 'rest_pre_serve_request', array( self::class, 'serve_not_modified' ), 10, 2 );
			return $response;
		}

		$response = new WP_REST_Response( $body, 200 );
		$response->set_headers( $headers );

		return $response;
	}

	/**
	 * Strong ETag for a response body.
	 *
	 * @param array $body Response body.
	 * @return string
	 */
	public static function etag( array $body ): string {
		return '"' . md5( implode( '|', array( (string) $body['version'], (string) $body['generated_at'], (string) $body['count'], (string) $body['html'], (string) ( $body['urgent_count'] ?? 0 ), (string) ( $body['urgent_html'] ?? '' ) ) ) ) . '"';
	}

	/**
	 * Whether an If-None-Match header value matches the ETag (list-aware, weak prefix tolerated).
	 *
	 * @param string $header If-None-Match header value.
	 * @param string $etag   ETag to compare with.
	 * @return bool
	 */
	public static function etag_matches( string $header, string $etag ): bool {
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === strpos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			if ( $candidate === $etag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * `rest_pre_serve_request` callback added only for a 304 of this controller: send no body.
	 *
	 * @param mixed $served Whether the request has been served.
	 * @param mixed $result Response.
	 * @return mixed
	 */
	public static function serve_not_modified( $served, $result ) {
		if ( $served ) {
			return $served;
		}
		if ( $result instanceof WP_REST_Response && 304 === $result->get_status() ) {
			return true;
		}
		return $served;
	}
}
