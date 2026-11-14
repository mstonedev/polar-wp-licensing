<?php
/**
 * A small value object wrapping one HTTP response from the Polar API.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApiResponse {

	/** HTTP status code. 0 means the request never got a response (DNS, timeout, TLS...). */
	private int $code;

	/** Decoded JSON body (empty array when there was no body or it wasn't JSON). */
	private array $data;

	/** Transport error message, only set when $code is 0. */
	private string $transport_error;

	public function __construct( int $code, array $data = array(), string $transport_error = '' ) {
		$this->code            = $code;
		$this->data            = $data;
		$this->transport_error = $transport_error;
	}

	public static function transport_failure( string $message ): self {
		return new self( 0, array(), $message );
	}

	public function code(): int {
		return $this->code;
	}

	public function data(): array {
		return $this->data;
	}

	public function get( string $key, $fallback = null ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $fallback;
	}

	public function is_success(): bool {
		return $this->code >= 200 && $this->code < 300;
	}

	/**
	 * True when we could not get a definitive answer from Polar: the network failed,
	 * Polar had a server error, or we were rate limited. In these cases we must NOT
	 * change the stored license status, otherwise a Polar outage would lock paying
	 * customers out of their features.
	 */
	public function is_inconclusive(): bool {
		return 0 === $this->code || 429 === $this->code || $this->code >= 500;
	}

	/**
	 * Human-readable error. Polar errors look like {"error": "NotPermitted", "detail": "..."};
	 * validation errors (422) have "detail" as a list of {loc, msg, type} objects.
	 */
	public function error_message(): string {
		if ( 0 === $this->code ) {
			return $this->transport_error;
		}

		$detail = $this->get( 'detail' );

		if ( is_string( $detail ) && '' !== $detail ) {
			return $detail;
		}

		if ( is_array( $detail ) ) {
			$messages = array();
			foreach ( $detail as $item ) {
				if ( is_array( $item ) && isset( $item['msg'] ) ) {
					$messages[] = (string) $item['msg'];
				}
			}
			if ( $messages ) {
				return implode( ' ', $messages );
			}
		}

		return sprintf( 'Polar returned HTTP %d.', $this->code );
	}
}
