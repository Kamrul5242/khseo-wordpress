<?php
/**
 * Transport over the WordPress HTTP API with the connection pinned to a validated IP.
 *
 * @package KHSEO
 */

declare(strict_types=1);

namespace KHSEO\Http;

/**
 * Pins the TCP connection with CURLOPT_RESOLVE inside the http_api_curl hook, so a
 * second DNS lookup (DNS rebinding) cannot redirect the request to another address.
 * If the request is not made through curl, the hook never fires and the response is
 * refused: KHSEO fails closed rather than fetching unpinned.
 */
final class WpHttpTransport implements Transport {

	/**
	 * Perform a pinned GET request.
	 *
	 * @param string      $url       Absolute URL.
	 * @param string      $pinned_ip Validated IP.
	 * @param int         $port      Port.
	 * @param FetchPolicy $policy    Limits.
	 */
	public function get( string $url, string $pinned_ip, int $port, FetchPolicy $policy ): TransportResponse {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return TransportResponse::failure( 'The PHP curl extension is required for safe fetching.' );
		}
		$host = (string) parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- host is needed verbatim for CURLOPT_RESOLVE.
		$host = trim( $host, '[]' );
		$ip   = str_contains( $pinned_ip, ':' ) ? '[' . $pinned_ip . ']' : $pinned_ip;

		$pinned = false;
		$hook   = static function ( $handle, $args, $hook_url ) use ( $url, $host, $port, $ip, $policy, &$pinned ): void {
			if ( $hook_url !== $url ) {
				return;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pinning requires direct curl options.
			curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . $ip ) );
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, $policy->connect_timeout );
			curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
			curl_setopt( $handle, CURLOPT_ENCODING, 'identity' );
			if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
				curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			}
			// phpcs:enable
			$pinned = true;
		};

		add_action( 'http_api_curl', $hook, PHP_INT_MAX, 3 );
		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => $policy->timeout,
					'redirection'         => 0,
					'decompress'          => false,
					'limit_response_size' => $policy->max_bytes + 1,
					'user-agent'          => $policy->user_agent,
					'headers'             => array( 'Accept-Encoding' => 'identity' ),
					'sslverify'           => true,
				)
			);
		} finally {
			remove_action( 'http_api_curl', $hook, PHP_INT_MAX );
		}

		if ( ! $pinned ) {
			return TransportResponse::failure( 'Connection could not be pinned to the validated address; request refused.' );
		}
		if ( is_wp_error( $response ) ) {
			// WP_Error messages come from curl and contain no secrets; keep them short.
			return TransportResponse::failure( substr( $response->get_error_message(), 0, 200 ) );
		}

		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		return new TransportResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			$headers,
			$body,
			strlen( $body ) > $policy->max_bytes
		);
	}
}
