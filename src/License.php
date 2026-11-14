<?php
/**
 * Stores and checks one plugin's license on one site.
 *
 * State lives in a single autoloaded option, so is_valid() is a cheap in-memory
 * check you can call anywhere (even on every front-end request). Talking to Polar
 * only happens on activate/deactivate, on a daily cron, and when an admin opens
 * the license screen.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class License {

	const STATUS_INACTIVE = 'inactive'; // No key entered yet.
	const STATUS_VALID    = 'valid';
	const STATUS_EXPIRED  = 'expired';
	const STATUS_REVOKED  = 'revoked';  // Refund, chargeback, cancelled subscription...
	const STATUS_DISABLED = 'disabled'; // Turned off by the seller in Polar.
	const STATUS_INVALID  = 'invalid';  // Unknown key, or this site's activation was removed.

	private string $slug;
	private ApiClient $api;
	private ?string $benefit_id;

	/** How long a valid license stays valid while Polar can't be reached. */
	private int $grace_period;

	/** Minimum seconds between background checks. */
	private int $check_interval;

	/** @var callable(): int */
	private $clock;

	public function __construct( string $slug, ApiClient $api, array $args = array() ) {
		$this->slug           = $slug;
		$this->api            = $api;
		$this->benefit_id     = isset( $args['benefit_id'] ) ? (string) $args['benefit_id'] : null;
		$this->grace_period   = isset( $args['grace_period'] ) ? (int) $args['grace_period'] : 14 * DAY_IN_SECONDS;
		$this->check_interval = isset( $args['check_interval'] ) ? (int) $args['check_interval'] : DAY_IN_SECONDS;
		$this->clock          = isset( $args['clock'] ) && is_callable( $args['clock'] ) ? $args['clock'] : 'time';
	}

	public function slug(): string {
		return $this->slug;
	}

	public function option_name(): string {
		return 'polarwp_license_' . $this->slug;
	}

	/* ------------------------------------------------------------------ */
	/* Reading state                                                       */
	/* ------------------------------------------------------------------ */

	public function state(): array {
		$stored = get_option( $this->option_name(), array() );

		return array_merge(
			array(
				'key'            => '',
				'display_key'    => '',
				'activation_id'  => null,
				'status'         => self::STATUS_INACTIVE,
				'expires_at'     => null, // Unix timestamp or null for "never".
				'customer_email' => '',
				'last_check'     => 0,    // Last time we tried to reach Polar.
				'last_success'   => 0,    // Last time Polar gave us a definitive answer.
				'last_error'     => '',
			),
			is_array( $stored ) ? $stored : array()
		);
	}

	public function status(): string {
		$state = $this->state();

		if ( self::STATUS_VALID === $state['status'] && $this->has_expired( $state['expires_at'] ) ) {
			return self::STATUS_EXPIRED;
		}

		return $state['status'];
	}

	/**
	 * The one method plugin authors gate features with.
	 */
	public function is_valid(): bool {
		$state = $this->state();

		$valid = self::STATUS_VALID === $state['status']
			&& ! $this->has_expired( $state['expires_at'] )
			&& ! $this->grace_period_exceeded( $state );

		/**
		 * Filter the final answer, e.g. to always return true on local dev sites.
		 *
		 * @param bool    $valid
		 * @param License $license
		 */
		return (bool) apply_filters( 'polarwp_license_is_valid', $valid, $this );
	}

	/** True when we're running on the last known good status because Polar couldn't be reached. */
	public function is_in_grace_period(): bool {
		$state = $this->state();

		return self::STATUS_VALID === $state['status']
			&& '' !== $state['last_error']
			&& $state['last_check'] > $state['last_success']
			&& ! $this->grace_period_exceeded( $state );
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Validate the key, use an activation seat if the key has a limit, and save it.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function activate( string $raw_key ): array {
		$key = self::sanitize_key( $raw_key );

		if ( '' === $key ) {
			return $this->result( false, __( 'Please enter a license key.', 'polarwp-licensing' ) );
		}

		$current = $this->state();
		if ( $current['key'] && $current['activation_id'] ) {
			if ( $current['key'] !== $key ) {
				return $this->result( false, __( 'Deactivate the current license before entering a new one.', 'polarwp-licensing' ) );
			}

			// Same key, already holds a seat: re-check instead of using up a second seat.
			$this->check();
			$status = $this->status();

			return self::STATUS_VALID === $status
				? $this->result( true, __( 'License is active.', 'polarwp-licensing' ) )
				: $this->result( false, $this->describe_status( $status ) );
		}

		// 1. Is the key real, for this product, and still granted?
		$check = $this->api->validate( $key, null, $this->benefit_id );

		if ( ! $check->is_success() ) {
			return $this->result( false, $this->describe_failure( $check ) );
		}

		$status = $this->map_status( $check );
		if ( self::STATUS_VALID !== $status ) {
			return $this->result( false, $this->describe_status( $status ) );
		}

		$activation_id = null;

		// 2. Keys with an activation limit need a seat for this site. Keys without one don't.
		if ( (int) $check->get( 'limit_activations', 0 ) > 0 ) {
			$activation = $this->api->activate( $key, $this->site_label(), $this->site_meta() );

			if ( ! $activation->is_success() ) {
				$message = 403 === $activation->code()
					? __( 'This license key has reached its activation limit. Deactivate it on another site, or from your Polar customer portal, and try again.', 'polarwp-licensing' )
					: $this->describe_failure( $activation );

				return $this->result( false, $message );
			}

			$activation_id = (string) $activation->get( 'id', '' );
		}

		// 3. Save.
		$now = ( $this->clock )();
		$this->save(
			array(
				'key'            => $key,
				'display_key'    => (string) $check->get( 'display_key', self::mask( $key ) ),
				'activation_id'  => $activation_id ?: null,
				'status'         => self::STATUS_VALID,
				'expires_at'     => self::parse_time( $check->get( 'expires_at' ) ),
				'customer_email' => self::customer_email( $check ),
				'last_check'     => $now,
				'last_success'   => $now,
				'last_error'     => '',
			)
		);

		do_action( 'polarwp_license_activated', $this );

		return $this->result( true, __( 'License activated. Thank you!', 'polarwp-licensing' ) );
	}

	/**
	 * Free this site's seat and forget the key.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function deactivate(): array {
		$state = $this->state();

		if ( '' === $state['key'] ) {
			return $this->result( true, __( 'No license is active.', 'polarwp-licensing' ) );
		}

		if ( $state['activation_id'] ) {
			$response = $this->api->deactivate( $state['key'], $state['activation_id'] );

			// 404 means the seat is already gone (removed in the portal, key revoked...), which is fine.
			if ( ! $response->is_success() && 404 !== $response->code() ) {
				return $this->result(
					false,
					sprintf(
						/* translators: %s: error message from Polar */
						__( 'Could not deactivate the license: %s Please try again.', 'polarwp-licensing' ),
						$response->error_message()
					)
				);
			}
		}

		delete_option( $this->option_name() );
		do_action( 'polarwp_license_deactivated', $this );

		return $this->result( true, __( 'License deactivated on this site.', 'polarwp-licensing' ) );
	}

	/**
	 * Re-check the stored key with Polar. Safe to call often; see maybe_check().
	 */
	public function check(): void {
		$state = $this->state();

		if ( '' === $state['key'] ) {
			return;
		}

		$now      = ( $this->clock )();
		$response = $this->api->validate( $state['key'], $state['activation_id'], $this->benefit_id );

		$state['last_check'] = $now;

		if ( $response->is_inconclusive() ) {
			// Keep the last known status; is_valid() falls back on the grace period.
			$state['last_error'] = $response->error_message();
			$this->save( $state );
			return;
		}

		$previous = $state['status'];

		if ( $response->is_success() ) {
			$state['status']         = $this->map_status( $response );
			$state['expires_at']     = self::parse_time( $response->get( 'expires_at' ) );
			$state['customer_email'] = self::customer_email( $response ) ?: $state['customer_email'];
			$state['last_error']     = '';
		} else {
			// 404 / 403 / 422: Polar says no. Typically the key was deleted, or this
			// site's activation was removed from the customer portal.
			$state['status']     = self::STATUS_INVALID;
			$state['last_error'] = $response->error_message();
		}

		$state['last_success'] = $now;
		$this->save( $state );

		if ( $previous !== $state['status'] ) {
			do_action( 'polarwp_license_status_changed', $state['status'], $previous, $this );
		}
	}

	/** Check only if the last attempt is older than the check interval. */
	public function maybe_check(): void {
		$state = $this->state();

		if ( ( $this->clock )() - (int) $state['last_check'] >= $this->check_interval ) {
			$this->check();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	public function describe_status( string $status ): string {
		switch ( $status ) {
			case self::STATUS_VALID:
				return __( 'Active', 'polarwp-licensing' );
			case self::STATUS_EXPIRED:
				return __( 'This license has expired. Renew it to keep getting updates.', 'polarwp-licensing' );
			case self::STATUS_REVOKED:
				return __( 'This license has been revoked.', 'polarwp-licensing' );
			case self::STATUS_DISABLED:
				return __( 'This license has been disabled by the seller.', 'polarwp-licensing' );
			case self::STATUS_INVALID:
				return __( 'This license key is not valid for this site.', 'polarwp-licensing' );
			default:
				return __( 'No license key entered.', 'polarwp-licensing' );
		}
	}

	private function describe_failure( ApiResponse $response ): string {
		if ( $response->is_inconclusive() ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Could not reach the license server (%s). Please try again in a few minutes.', 'polarwp-licensing' ),
				$response->error_message()
			);
		}

		if ( 404 === $response->code() ) {
			return __( 'That license key was not found. Check it for typos, and make sure it is a key for this plugin.', 'polarwp-licensing' );
		}

		return $response->error_message();
	}

	private function map_status( ApiResponse $response ): string {
		switch ( (string) $response->get( 'status' ) ) {
			case 'granted':
				return $this->has_expired( self::parse_time( $response->get( 'expires_at' ) ) )
					? self::STATUS_EXPIRED
					: self::STATUS_VALID;
			case 'revoked':
				return self::STATUS_REVOKED;
			case 'disabled':
				return self::STATUS_DISABLED;
			default:
				return self::STATUS_INVALID;
		}
	}

	private function has_expired( ?int $expires_at ): bool {
		return null !== $expires_at && $expires_at <= ( $this->clock )();
	}

	private function grace_period_exceeded( array $state ): bool {
		return ( ( $this->clock )() - (int) $state['last_success'] ) > $this->grace_period;
	}

	private function save( array $state ): void {
		update_option( $this->option_name(), $state, true );
	}

	private function result( bool $success, string $message ): array {
		return array(
			'success' => $success,
			'message' => $message,
		);
	}

	private function site_label(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? (string) $host : home_url();
	}

	private function site_meta(): array {
		return array(
			'site_url'   => home_url(),
			'wp_version' => get_bloginfo( 'version' ),
		);
	}

	public static function sanitize_key( string $key ): string {
		// Keys get pasted with stray spaces and line breaks; Polar keys never contain whitespace.
		return (string) preg_replace( '/\s+/', '', $key );
	}

	public static function mask( string $key ): string {
		return strlen( $key ) <= 4 ? $key : str_repeat( '*', 8 ) . substr( $key, -4 );
	}

	private static function parse_time( $value ): ?int {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? null : $timestamp;
	}

	private static function customer_email( ApiResponse $response ): string {
		$customer = $response->get( 'customer' );
		return is_array( $customer ) && isset( $customer['email'] ) ? (string) $customer['email'] : '';
	}
}
