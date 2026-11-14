<?php
/**
 * Minimal gated download endpoint. Host it on any PHP server, outside WordPress.
 *
 * WordPress requests:  download.php?license_key=...&activation_id=...&site=...
 * This script asks Polar whether the key (and activation) is valid, and only then
 * streams the zip. Keep the zip itself OUTSIDE the web root.
 */

// ---- Configure -------------------------------------------------------------
const POLAR_API       = 'https://api.polar.sh'; // https://sandbox-api.polar.sh while testing
const ORGANIZATION_ID = 'YOUR-POLAR-ORGANIZATION-ID';
const BENEFIT_ID      = 'YOUR-LICENSE-KEY-BENEFIT-ID'; // or '' to skip
const ZIP_PATH        = __DIR__ . '/../private/acme-pro.zip';
const ZIP_NAME        = 'acme-pro.zip';
// ---------------------------------------------------------------------------

function deny( int $code, string $message ): void {
	http_response_code( $code );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $message;
	exit;
}

$key           = isset( $_GET['license_key'] ) ? preg_replace( '/\s+/', '', (string) $_GET['license_key'] ) : '';
$activation_id = isset( $_GET['activation_id'] ) ? (string) $_GET['activation_id'] : '';

if ( '' === $key ) {
	deny( 401, 'A license key is required.' );
}

$body = array(
	'key'             => $key,
	'organization_id' => ORGANIZATION_ID,
);
if ( '' !== $activation_id ) {
	$body['activation_id'] = $activation_id;
}
if ( '' !== BENEFIT_ID ) {
	$body['benefit_id'] = BENEFIT_ID;
}

$ch = curl_init( POLAR_API . '/v1/customer-portal/license-keys/validate' );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( $body ),
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json', 'Accept: application/json' ),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
	)
);
$raw    = curl_exec( $ch );
$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
curl_close( $ch );

if ( false === $raw || $status >= 500 || 0 === $status ) {
	deny( 503, 'The license server is unavailable. Please try again later.' );
}

$license = json_decode( (string) $raw, true );

if ( 200 !== $status || ! is_array( $license ) ) {
	deny( 403, 'This license key is not valid.' );
}

if ( ( $license['status'] ?? '' ) !== 'granted' ) {
	deny( 403, 'This license is no longer active.' );
}

if ( ! empty( $license['expires_at'] ) && strtotime( $license['expires_at'] ) <= time() ) {
	deny( 403, 'This license has expired.' );
}

if ( ! is_readable( ZIP_PATH ) ) {
	deny( 500, 'Package not found.' );
}

header( 'Content-Type: application/zip' );
header( 'Content-Disposition: attachment; filename="' . ZIP_NAME . '"' );
header( 'Content-Length: ' . filesize( ZIP_PATH ) );
header( 'Cache-Control: no-store' );
readfile( ZIP_PATH );
