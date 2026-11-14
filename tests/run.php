<?php
/**
 * Scenario tests: php tests/run.php
 * Runs the real License/Updater code against stubbed WordPress and fake Polar responses.
 */

require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../src/ApiResponse.php';
require __DIR__ . '/../src/ApiClient.php';
require __DIR__ . '/../src/License.php';
require __DIR__ . '/../src/Updater.php';
require __DIR__ . '/../src/Licensing.php';

use PolarWP\Licensing\ApiClient;
use PolarWP\Licensing\License;
use PolarWP\Licensing\Licensing;
use PolarWP\Licensing\Updater;

$now   = strtotime( '2026-11-13 12:00:00 UTC' );
$clock = static function () use ( &$now ) { return $now; };

$passed = 0;
$failed = 0;

function check_that( string $label, bool $condition ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  ok   $label\n";
	} else {
		$failed++;
		echo "  FAIL $label\n";
	}
}

function fresh_license( callable $clock, array $args = array() ): License {
	$GLOBALS['options']   = array();
	$GLOBALS['fake_http'] = array();
	$GLOBALS['http_log']  = array();
	$GLOBALS['actions']   = array();
	return new License( 'acme-pro', new ApiClient( 'org_123' ), array_merge( array( 'clock' => $clock, 'benefit_id' => 'ben_1' ), $args ) );
}

function granted( array $extra = array() ): array {
	return array_merge(
		array(
			'id'                => 'lk_1',
			'key'               => 'ABCD-1234',
			'display_key'       => '****-1234',
			'status'            => 'granted',
			'limit_activations' => null,
			'expires_at'        => null,
			'customer'          => array( 'email' => 'buyer@example.com' ),
		),
		$extra
	);
}

echo "Activate a key with no activation limit\n";
$license = fresh_license( $clock );
respond( 200, granted() );
$result = $license->activate( "  ABCD-\n1234 " );
check_that( 'activation succeeds', $result['success'] );
check_that( 'whitespace stripped from key', 'ABCD-1234' === $license->state()['key'] );
check_that( 'no activate call made (only 1 request)', 1 === count( $GLOBALS['http_log'] ) );
check_that( 'validate sent org + benefit id', 'org_123' === $GLOBALS['http_log'][0]['body']['organization_id'] && 'ben_1' === $GLOBALS['http_log'][0]['body']['benefit_id'] );
check_that( 'hits public customer-portal endpoint', str_ends_with( $GLOBALS['http_log'][0]['url'], '/v1/customer-portal/license-keys/validate' ) );
check_that( 'is_valid() true', $license->is_valid() );
check_that( 'customer email stored', 'buyer@example.com' === $license->state()['customer_email'] );

echo "\nActivate a key with an activation limit\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'limit_activations' => 3 ) ) );
respond( 200, array( 'id' => 'act_9', 'license_key_id' => 'lk_1', 'label' => 'customer-site.test' ) );
$result = $license->activate( 'ABCD-1234' );
check_that( 'activation succeeds', $result['success'] );
check_that( 'activation id stored', 'act_9' === $license->state()['activation_id'] );
check_that( 'site host used as activation label', 'customer-site.test' === $GLOBALS['http_log'][1]['body']['label'] );

echo "\nRe-submitting the same key does not use a second seat\n";
respond( 200, granted( array( 'limit_activations' => 3 ) ) );
$result = $license->activate( 'ABCD-1234' );
check_that( 'succeeds', $result['success'] );
check_that( 'only a validate call, no new activate', str_ends_with( end( $GLOBALS['http_log'] )['url'], '/validate' ) &&3 === count( $GLOBALS['http_log'] ) );

echo "\nActivation limit reached\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'limit_activations' => 1 ) ) );
respond( 403, array( 'error' => 'NotPermitted', 'detail' => 'License key activation limit reached' ) );
$result = $license->activate( 'ABCD-1234' );
check_that( 'activation fails', ! $result['success'] );
check_that( 'friendly limit message', false !== strpos( $result['message'], 'activation limit' ) );
check_that( 'nothing stored', License::STATUS_INACTIVE === $license->status() );

echo "\nUnknown key\n";
$license = fresh_license( $clock );
respond( 404, array( 'error' => 'ResourceNotFound', 'detail' => 'Not found' ) );
$result = $license->activate( 'NOPE' );
check_that( 'fails with not-found message', ! $result['success'] && false !== strpos( $result['message'], 'not found' ) );

echo "\nRevoked key on activation\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'status' => 'revoked' ) ) );
$result = $license->activate( 'ABCD-1234' );
check_that( 'fails', ! $result['success'] && false !== strpos( $result['message'], 'revoked' ) );

echo "\nPolar unreachable on activation\n";
$license = fresh_license( $clock );
respond_error( 'cURL error 28: timed out' );
$result = $license->activate( 'ABCD-1234' );
check_that( 'fails with retry message', ! $result['success'] && false !== strpos( $result['message'], 'try again' ) );

echo "\nOutage after activation: grace period\n";
$license = fresh_license( $clock, array( 'grace_period' => 14 * DAY_IN_SECONDS ) );
respond( 200, granted() );
$license->activate( 'ABCD-1234' );
$now += 2 * DAY_IN_SECONDS;
respond( 503, array( 'detail' => 'Service Unavailable' ) );
$license->check();
check_that( 'status unchanged after 503', License::STATUS_VALID === $license->state()['status'] );
check_that( 'still valid inside grace period', $license->is_valid() );
check_that( 'reports grace period', $license->is_in_grace_period() );
$now += 13 * DAY_IN_SECONDS; // 15 days since last good answer.
check_that( 'invalid once grace period runs out', ! $license->is_valid() );
respond( 200, granted() );
$license->check();
check_that( 'valid again when Polar answers', $license->is_valid() && ! $license->is_in_grace_period() );

echo "\nRefund: key revoked, picked up by background check\n";
$license = fresh_license( $clock );
respond( 200, granted() );
$license->activate( 'ABCD-1234' );
$now += DAY_IN_SECONDS;
respond( 200, granted( array( 'status' => 'revoked' ) ) );
$license->maybe_check();
check_that( 'status revoked', License::STATUS_REVOKED === $license->status() );
check_that( 'not valid', ! $license->is_valid() );
check_that( 'status_changed action fired', in_array( 'polarwp_license_status_changed', $GLOBALS['actions'], true ) );

echo "\nActivation removed in customer portal (404 on check)\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'limit_activations' => 2 ) ) );
respond( 200, array( 'id' => 'act_9' ) );
$license->activate( 'ABCD-1234' );
$now += DAY_IN_SECONDS;
respond( 404, array( 'error' => 'ResourceNotFound', 'detail' => 'License key activation not found.' ) );
$license->check();
check_that( 'status invalid', License::STATUS_INVALID === $license->status() );
check_that( 'activation_id sent on check', 'act_9' === end( $GLOBALS['http_log'] )['body']['activation_id'] );

echo "\nmaybe_check() respects the interval\n";
$license = fresh_license( $clock );
respond( 200, granted() );
$license->activate( 'ABCD-1234' );
$requests = count( $GLOBALS['http_log'] );
$now     += HOUR_IN_SECONDS;
$license->maybe_check(); // Would throw on an unexpected request.
check_that( 'no request within the interval', count( $GLOBALS['http_log'] ) === $requests );

echo "\nExpiry\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'expires_at' => gmdate( 'c', $now + DAY_IN_SECONDS ) ) ) );
$license->activate( 'ABCD-1234' );
check_that( 'valid before expiry', $license->is_valid() );
$now += 2 * DAY_IN_SECONDS; // No check needed: expiry is enforced locally.
check_that( 'expired after expires_at', License::STATUS_EXPIRED === $license->status() && ! $license->is_valid() );

echo "\nDeactivate\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'limit_activations' => 2 ) ) );
respond( 200, array( 'id' => 'act_9' ) );
$license->activate( 'ABCD-1234' );
respond( 503 );
$result = $license->deactivate();
check_that( 'failed remote deactivation keeps the license', ! $result['success'] && 'ABCD-1234' === $license->state()['key'] );
respond( 204 );
$result = $license->deactivate();
check_that( 'deactivation succeeds', $result['success'] );
check_that( 'sent activation id', 'act_9' === end( $GLOBALS['http_log'] )['body']['activation_id'] );
check_that( 'state cleared', License::STATUS_INACTIVE === $license->status() );

echo "\nUpdater\n";
$license = fresh_license( $clock );
respond( 200, granted( array( 'limit_activations' => 2 ) ) );
respond( 200, array( 'id' => 'act_9' ) );
$license->activate( 'ABCD-1234' );
$GLOBALS['transients'] = array();
$updater  = new Updater( '/plugins/acme-pro/acme-pro.php', '1.0.0', 'https://updates.example.com/acme-pro.json', $license );
$manifest = json_decode( file_get_contents( __DIR__ . '/../examples/update-manifest.json' ), true );
respond( 200, $manifest );
$transient = $updater->inject_update( (object) array( 'response' => array() ) );
$item      = $transient->response['acme-pro/acme-pro.php'] ?? null;
check_that( 'update offered', $item && '1.1.0' === $item->new_version );
check_that( 'package carries key and activation', $item && false !== strpos( $item->package, 'license_key=ABCD-1234' ) && false !== strpos( $item->package, 'activation_id=act_9' ) );
$transient = $updater->inject_update( (object) array( 'response' => array() ) ); // Served from cache: would throw on a request.
check_that( 'manifest cached', isset( $transient->response['acme-pro/acme-pro.php'] ) );
$GLOBALS['options'] = array(); // Unlicensed.
$transient = $updater->inject_update( (object) array( 'response' => array() ) );
check_that( 'unlicensed: update shown without package', '' === $transient->response['acme-pro/acme-pro.php']->package );
$current   = new Updater( '/plugins/acme-pro/acme-pro.php', '1.1.0', 'https://updates.example.com/acme-pro.json', $license );
$transient = $current->inject_update( (object) array( 'response' => array() ) );
check_that( 'up to date: listed under no_update', empty( $transient->response ) && isset( $transient->no_update['acme-pro/acme-pro.php'] ) );
$info = $updater->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'acme-pro' ) );
check_that( 'details modal has changelog', is_object( $info ) && false !== strpos( $info->sections['changelog'], '1.1.0' ) );
check_that( 'other plugins untouched', false === $updater->plugin_information( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );

echo "\nLive vs sandbox IDs\n";
$live_org = '11111111-1111-4111-8111-111111111111';
$sb_org   = '22222222-2222-4222-8222-222222222222';
$config   = array(
	'organization_id' => $live_org,
	'benefit_id'      => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
	'sandbox'         => array(
		'organization_id' => $sb_org,
		'benefit_id'      => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
	),
);
$env = Licensing::resolve_environment( $config, false );
check_that( 'sandbox off: live IDs', ! $env['sandbox'] && $live_org === $env['organization_id'] && 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' === $env['benefit_id'] );
$env = Licensing::resolve_environment( $config, true );
check_that( 'sandbox on: sandbox IDs', $env['sandbox'] && $sb_org === $env['organization_id'] && 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' === $env['benefit_id'] );
$env = Licensing::resolve_environment( array( 'organization_id' => $live_org ), true );
check_that( 'sandbox on but no sandbox IDs: stays live, reports problem', ! $env['sandbox'] && $live_org === $env['organization_id'] && '' !== $env['problem'] );
$config['update_manifest_url'] = 'https://updates.example.com/live.json';
check_that( 'sandbox without its own manifest: uses live manifest', 'https://updates.example.com/live.json' === Licensing::resolve_environment( $config, true )['update_manifest_url'] );
$config['sandbox']['update_manifest_url'] = 'https://updates.example.com/test.json';
check_that( 'sandbox manifest used in sandbox mode', 'https://updates.example.com/test.json' === Licensing::resolve_environment( $config, true )['update_manifest_url'] );
check_that( 'live manifest used in live mode', 'https://updates.example.com/live.json' === Licensing::resolve_environment( $config, false )['update_manifest_url'] );
check_that( 'no benefit id: null', null === Licensing::resolve_environment( array( 'organization_id' => $live_org ), false )['benefit_id'] );

$GLOBALS['options'] = array();
$live_license       = new License( 'acme-pro', new ApiClient( $live_org ), array( 'clock' => $clock ) );
$sandbox_license    = new License( 'acme-pro', new ApiClient( $sb_org, true ), array( 'clock' => $clock, 'option_suffix' => '_sandbox' ) );
respond( 200, granted() );
$sandbox_license->activate( 'TEST-KEY' );
check_that( 'sandbox key stored separately', 'polarwp_license_acme-pro_sandbox' === $sandbox_license->option_name() && $sandbox_license->is_valid() );
check_that( 'live license untouched by sandbox key', License::STATUS_INACTIVE === $live_license->status() );
check_that( 'sandbox client uses sandbox API', str_starts_with( end( $GLOBALS['http_log'] )['url'], 'https://sandbox-api.polar.sh/' ) );
check_that( 'sandbox client sends sandbox org id', $sb_org === end( $GLOBALS['http_log'] )['body']['organization_id'] );

echo "\n$passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
