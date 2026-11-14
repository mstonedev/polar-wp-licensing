<?php
/**
 * Thin client for Polar's public customer-portal license key endpoints.
 *
 * These endpoints need no access token, which is exactly what we want: the plugin
 * ships to customers' sites, so it must never contain a secret. Polar scopes every
 * call to your organization via organization_id instead.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApiClient {

	const PRODUCTION_URL = 'https://api.polar.sh';
	const SANDBOX_URL    = 'https://sandbox-api.polar.sh';

	private string $base_url;
	private string $organization_id;
	private int $timeout;

	public function __construct( string $organization_id, bool $sandbox = false, int $timeout = 15 ) {
		$this->organization_id = $organization_id;
		$this->base_url        = $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
		$this->timeout         = $timeout;
	}

	/**
	 * Check a key (and, if given, a specific activation of it).
	 * Success returns the license key object: status, expires_at, limit_activations, customer...
	 */
	public function validate( string $key, ?string $activation_id = null, ?string $benefit_id = null ): ApiResponse {
		$body = array(
			'key'             => $key,
			'organization_id' => $this->organization_id,
		);

		if ( $activation_id ) {
			$body['activation_id'] = $activation_id;
		}
		if ( $benefit_id ) {
			$body['benefit_id'] = $benefit_id;
		}

		return $this->post( '/v1/customer-portal/license-keys/validate', $body );
	}

	/**
	 * Use up one activation seat. Success returns the activation object; its "id" is
	 * what we store and send back on every later validate/deactivate call.
	 */
	public function activate( string $key, string $label, array $meta = array() ): ApiResponse {
		$body = array(
			'key'             => $key,
			'organization_id' => $this->organization_id,
			'label'           => $label,
		);

		if ( $meta ) {
			$body['meta'] = $meta;
		}

		return $this->post( '/v1/customer-portal/license-keys/activate', $body );
	}

	/** Free the activation seat. Success is HTTP 204 with no body. */
	public function deactivate( string $key, string $activation_id ): ApiResponse {
		return $this->post(
			'/v1/customer-portal/license-keys/deactivate',
			array(
				'key'             => $key,
				'organization_id' => $this->organization_id,
				'activation_id'   => $activation_id,
			)
		);
	}

	private function post( string $path, array $body ): ApiResponse {
		$response = wp_remote_post(
			$this->base_url . $path,
			array(
				'timeout' => $this->timeout,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return ApiResponse::transport_failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return new ApiResponse( $code, is_array( $data ) ? $data : array() );
	}
}
