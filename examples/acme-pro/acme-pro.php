<?php
/**
 * Plugin Name: Acme Pro (Polar licensing test)
 * Description: Example plugin showing how to sell a WordPress plugin with Polar license keys.
 * Version:     1.0.0
 * Author:      Acme
 * Requires PHP: 7.4
 *
 * Configure it by adding these to wp-config.php (above "That's all, stop editing!"):
 *
 *     define( 'ACME_POLAR_ORG_ID', 'your-polar-organization-id' );
 *     define( 'ACME_POLAR_BENEFIT_ID', 'your-license-key-benefit-id' ); // optional
 *     define( 'ACME_POLAR_SANDBOX', true );                              // false for live keys
 *     define( 'ACME_UPDATE_MANIFEST_URL', 'https://...' );               // optional
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ACME_POLAR_ORG_ID' ) || '' === ACME_POLAR_ORG_ID ) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>Acme Pro:</strong> add <code>define( \'ACME_POLAR_ORG_ID\', \'your-org-id\' );</code> to wp-config.php to enable licensing.</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/polar-wp-licensing/polar-wp-licensing.php';

$acme_licensing = \PolarWP\Licensing\Licensing::register(
	array(
		'plugin_file'         => __FILE__,
		'organization_id'     => ACME_POLAR_ORG_ID,
		'benefit_id'          => defined( 'ACME_POLAR_BENEFIT_ID' ) ? ACME_POLAR_BENEFIT_ID : null,
		'sandbox'             => defined( 'ACME_POLAR_SANDBOX' ) ? (bool) ACME_POLAR_SANDBOX : true,
		'update_manifest_url' => defined( 'ACME_UPDATE_MANIFEST_URL' ) ? ACME_UPDATE_MANIFEST_URL : null,
		'menu_parent'         => 'options-general.php',
	)
);

// Visible proof that gating works: a notice on the Dashboard that changes with the license.
add_action(
	'admin_notices',
	static function () use ( $acme_licensing ) {
		$screen = get_current_screen();
		if ( ! $screen || 'dashboard' !== $screen->id ) {
			return;
		}

		if ( $acme_licensing->is_valid() ) {
			echo '<div class="notice notice-success"><p><strong>Acme Pro:</strong> licensed. Pro features are on.</p></div>';
		} else {
			printf(
				'<div class="notice notice-info"><p><strong>Acme Pro:</strong> not licensed (%s). <a href="%s">Enter your license key</a>.</p></div>',
				esc_html( $acme_licensing->license()->status() ),
				esc_url( admin_url( 'options-general.php?page=acme-pro-license' ) )
			);
		}
	}
);

// In a real plugin you might treat local dev sites as licensed. Left off here so you can test:
// add_filter( 'polarwp_license_is_valid', fn( $valid ) => $valid || 'local' === wp_get_environment_type() );
