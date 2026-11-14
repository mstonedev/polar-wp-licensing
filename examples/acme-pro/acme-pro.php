<?php
/**
 * Plugin Name: Acme Pro (Polar licensing example)
 * Description: Example plugin showing how to sell a WordPress plugin with Polar license keys.
 * Version:     1.0.0
 * Author:      Acme
 * Requires PHP: 7.4
 *
 * To try it: replace the four placeholder IDs below with your own, then add
 *
 *     define( 'POLARWP_SANDBOX', true );
 *
 * to the wp-config.php of your local test site so it uses your sandbox IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/polar-wp-licensing/polar-wp-licensing.php';

$acme_licensing = \PolarWP\Licensing\Licensing::register(
	array(
		'plugin_file'         => __FILE__,

		// LIVE IDs, from polar.sh. Every customer's site uses these.
		// None of these IDs are secret; it's normal for them to ship in your plugin.
		'organization_id'     => 'LIVE-ORGANIZATION-ID',    // Settings → General → Organization ID
		'benefit_id'          => 'LIVE-LICENSE-BENEFIT-ID', // Your product's License Keys benefit

		// SANDBOX IDs, from sandbox.polar.sh. Only used on a site whose wp-config.php
		// defines POLARWP_SANDBOX as true, i.e. your own development site.
		'sandbox'             => array(
			'organization_id' => 'SANDBOX-ORGANIZATION-ID',
			'benefit_id'      => 'SANDBOX-LICENSE-BENEFIT-ID',
			// Optional: a test manifest whose download_url points at a sandbox copy of download.php.
			'update_manifest_url' => 'https://updates.example.com/acme-pro-test.json',
		),

		'update_manifest_url' => 'https://updates.example.com/acme-pro.json',
		'purchase_url'        => 'https://buy.polar.sh/YOUR-CHECKOUT-LINK',
		'portal_url'          => 'https://polar.sh/YOUR-ORG/portal',
	)
);

// Gate Pro features. is_valid() only reads a stored option, so it's cheap anywhere.
add_action(
	'init',
	static function () use ( $acme_licensing ) {
		if ( ! $acme_licensing->is_valid() ) {
			return;
		}

		// Register Pro-only blocks, elements, settings...
	}
);

// Visible proof that gating works: a Dashboard notice that changes with the license.
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
