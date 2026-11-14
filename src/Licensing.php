<?php
/**
 * Entry point: one call wires up the license, the license screen, background
 * checks and updates for a plugin.
 *
 * Call it once, in your plugin's main file:
 *
 *     $licensing = \PolarWP\Licensing\Licensing::register( array(
 *         'plugin_file'         => __FILE__,
 *
 *         // Live IDs (polar.sh). Used on every customer's site.
 *         'organization_id'     => 'your-live-organization-id',
 *         'benefit_id'          => 'your-live-license-keys-benefit-id',
 *
 *         // Sandbox IDs (sandbox.polar.sh). Only used where POLARWP_SANDBOX is true.
 *         'sandbox'             => array(
 *             'organization_id' => 'your-sandbox-organization-id',
 *             'benefit_id'      => 'your-sandbox-license-keys-benefit-id',
 *         ),
 *
 *         'update_manifest_url' => 'https://updates.example.com/my-plugin.json',
 *     ) );
 *
 *     if ( $licensing->is_valid() ) { ... }
 *
 * The full list of options is on register() below, and in the README.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Licensing {

	/** @var array<string, self> */
	private static array $instances = array();

	private License $license;
	private ?AdminPage $admin_page = null;
	private ?Updater $updater      = null;
	private string $cron_hook;
	private bool $sandbox = false;

	/**
	 * @param array $config {
	 *     @type string       $plugin_file         Required. Main plugin file (__FILE__).
	 *     @type string       $organization_id     Required. Your LIVE Polar organization ID.
	 *     @type string       $benefit_id          Your LIVE License Keys benefit ID. Recommended if you sell more than one product.
	 *     @type array        $sandbox             Optional. Your SANDBOX settings: array( 'organization_id' => ..., 'benefit_id' => ...,
	 *                                             'update_manifest_url' => ... ). Only used on a site where sandbox mode is
	 *                                             turned on (see sandbox_requested()). update_manifest_url falls back to the live one.
	 *     @type string       $slug                Defaults to the plugin folder name.
	 *     @type string       $name                Defaults to the "Plugin Name" header.
	 *     @type string       $version             Defaults to the "Version" header.
	 *     @type string       $update_manifest_url Enables updates when set.
	 *     @type string|false $menu_parent         Admin page parent. Default 'options-general.php'; false = no page.
	 *     @type string       $purchase_url        "Buy a license" link (e.g. a Polar checkout link).
	 *     @type string       $portal_url          Polar customer portal link.
	 *     @type int          $grace_period        Seconds a valid license survives failed checks. Default 14 days.
	 *     @type int          $check_interval      Seconds between background checks. Default 1 day.
	 * }
	 */
	public static function register( array $config ): self {
		foreach ( array( 'plugin_file', 'organization_id' ) as $required ) {
			if ( empty( $config[ $required ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'PolarWP Licensing: "%s" is required.', $required ) );
			}
		}

		$plugin_file = (string) $config['plugin_file'];
		$slug        = sanitize_key( $config['slug'] ?? dirname( plugin_basename( $plugin_file ) ) );

		if ( isset( self::$instances[ $slug ] ) ) {
			return self::$instances[ $slug ];
		}

		self::$instances[ $slug ] = new self( $slug, $plugin_file, $config );
		return self::$instances[ $slug ];
	}

	public static function get( string $slug ): ?self {
		return self::$instances[ $slug ] ?? null;
	}

	private function __construct( string $slug, string $plugin_file, array $config ) {
		$headers = get_file_data( $plugin_file, array( 'Name' => 'Plugin Name', 'Version' => 'Version' ) );
		$name    = (string) ( $config['name'] ?? $headers['Name'] );
		$version = (string) ( $config['version'] ?? $headers['Version'] );

		// Live IDs everywhere, unless this site has opted into sandbox mode (developer machines only).
		$env           = self::resolve_environment( $config, self::sandbox_requested( $slug ) );
		$this->sandbox = $env['sandbox'];

		$this->warn_about_config( $name, $config, $env );

		$api = new ApiClient( $env['organization_id'], $env['sandbox'] );

		$this->license = new License(
			$slug,
			$api,
			array_merge(
				array_intersect_key( $config, array_flip( array( 'grace_period', 'check_interval' ) ) ),
				array(
					'benefit_id'    => $env['benefit_id'],
					// Sandbox and live licenses are stored separately, so switching modes
					// never mixes a test key into the real license (or the other way round).
					'option_suffix' => $env['sandbox'] ? '_sandbox' : '',
				)
			)
		);

		// Background re-check, so revoked/refunded licenses stop working without anyone visiting the license screen.
		$this->cron_hook = 'polarwp_license_check_' . $slug;
		add_action( $this->cron_hook, array( $this->license, 'maybe_check' ) );
		add_action( 'init', array( $this, 'schedule_check' ) );
		register_deactivation_hook( $plugin_file, array( $this, 'unschedule_check' ) );

		if ( is_admin() ) {
			$this->admin_page = new AdminPage( $this->license, $plugin_file, $name, array_merge( $config, array( 'sandbox_mode' => $env['sandbox'] ) ) );
			$this->admin_page->register();
		}

		if ( $env['update_manifest_url'] ) {
			$this->updater = new Updater( $plugin_file, $version, $env['update_manifest_url'], $this->license );
			$this->updater->register();
		}
	}

	/**
	 * Is sandbox mode turned on for this site?
	 *
	 * On by defining POLARWP_SANDBOX as true in wp-config.php. That file is per site
	 * and never ships with your plugin, so customers' sites always stay on live Polar.
	 * The filter lets you switch one plugin only, or decide in code.
	 */
	public static function sandbox_requested( string $slug ): bool {
		$requested = defined( 'POLARWP_SANDBOX' ) && POLARWP_SANDBOX;

		/**
		 * @param bool   $requested Whether to use the sandbox IDs and sandbox-api.polar.sh.
		 * @param string $slug      The plugin's slug.
		 */
		return (bool) apply_filters( 'polarwp_sandbox', $requested, $slug );
	}

	/**
	 * Pick the IDs and API host to use. Pure function, so it is easy to test.
	 *
	 * @return array{sandbox: bool, organization_id: string, benefit_id: ?string, update_manifest_url: ?string, problem: string}
	 */
	public static function resolve_environment( array $config, bool $sandbox_requested ): array {
		$live = array(
			'sandbox'         => false,
			'organization_id' => (string) $config['organization_id'],
			'benefit_id'          => empty( $config['benefit_id'] ) ? null : (string) $config['benefit_id'],
			'update_manifest_url' => empty( $config['update_manifest_url'] ) ? null : (string) $config['update_manifest_url'],
			'problem'             => '',
		);

		if ( ! $sandbox_requested ) {
			return $live;
		}

		$sandbox = $config['sandbox'] ?? null;

		if ( ! is_array( $sandbox ) || empty( $sandbox['organization_id'] ) ) {
			// Fail safe: without sandbox IDs, stay on live rather than sending live IDs to the sandbox API.
			$live['problem'] = __( "Sandbox mode is on, but this plugin has no sandbox IDs configured (the 'sandbox' option), so it is still using live Polar.", 'polarwp-licensing' );
			return $live;
		}

		return array(
			'sandbox'         => true,
			'organization_id' => (string) $sandbox['organization_id'],
			'benefit_id'          => empty( $sandbox['benefit_id'] ) ? null : (string) $sandbox['benefit_id'],
			// A separate test manifest lets you point downloads at a test download server.
			'update_manifest_url' => empty( $sandbox['update_manifest_url'] ) ? $live['update_manifest_url'] : (string) $sandbox['update_manifest_url'],
			'problem'             => '',
		);
	}

	public function is_sandbox(): bool {
		return $this->sandbox;
	}

	/**
	 * Admin notices for setup mistakes, and a reminder whenever sandbox mode is on.
	 *
	 * Polar IDs are UUIDs. A common mistake is pasting the organization's slug or a
	 * product name instead; Polar then answers every request with a confusing
	 * "Input should be a valid UUID" error. Catch it here with a clear message.
	 */
	private function warn_about_config( string $plugin_name, array $config, array $env ): void {
		$messages = array();

		$ids = array(
			'organization_id' => $config['organization_id'] ?? '',
			'benefit_id'      => $config['benefit_id'] ?? '',
		);
		if ( isset( $config['sandbox'] ) && is_array( $config['sandbox'] ) ) {
			$ids['sandbox.organization_id'] = $config['sandbox']['organization_id'] ?? '';
			$ids['sandbox.benefit_id']      = $config['sandbox']['benefit_id'] ?? '';
		} elseif ( isset( $config['sandbox'] ) ) {
			$messages[] = __( "The 'sandbox' option must be an array of sandbox IDs: array( 'organization_id' => ..., 'benefit_id' => ... ).", 'polarwp-licensing' );
		}

		$bad = array();
		foreach ( $ids as $option => $value ) {
			if ( '' !== (string) $value && ! self::is_uuid( (string) $value ) ) {
				$bad[] = sprintf( '%s: "%s"', $option, (string) $value );
			}
		}
		if ( $bad ) {
			$messages[] = __( 'These Polar settings should be IDs that look like 1f3c9a2e-7b4d-4c1a-9e8f-2a6b5c4d3e21, not names or slugs:', 'polarwp-licensing' ) . ' ' . implode( ', ', $bad );
		}

		if ( $env['problem'] ) {
			$messages[] = $env['problem'];
		}

		$sandbox = $env['sandbox'];

		if ( ! $messages && ! $sandbox ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $plugin_name, $messages, $sandbox ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				foreach ( $messages as $message ) {
					printf( '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>', esc_html( $plugin_name ), esc_html( $message ) );
				}
				if ( $sandbox ) {
					printf(
						'<div class="notice notice-warning"><p><strong>%s:</strong> %s</p></div>',
						esc_html( $plugin_name ),
						esc_html__( 'Polar sandbox mode is on. Only test license keys work here. To use live Polar, remove POLARWP_SANDBOX from wp-config.php (or the polarwp_sandbox filter).', 'polarwp-licensing' )
					);
				}
			}
		);
	}

	public static function is_uuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value );
	}

	public function is_valid(): bool {
		return $this->license->is_valid();
	}

	public function license(): License {
		return $this->license;
	}

	/** Render the license form inside your own settings page (use with 'menu_parent' => false). */
	public function render_license_form(): void {
		if ( $this->admin_page ) {
			$this->admin_page->render_form();
		}
	}

	public function schedule_check(): void {
		if ( ! wp_next_scheduled( $this->cron_hook ) ) {
			// Random offset so thousands of sites don't all hit Polar at the same minute.
			wp_schedule_event( time() + wp_rand( 0, DAY_IN_SECONDS ), 'daily', $this->cron_hook );
		}
	}

	public function unschedule_check(): void {
		wp_clear_scheduled_hook( $this->cron_hook );
	}
}
