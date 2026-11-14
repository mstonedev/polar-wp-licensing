<?php
/**
 * Entry point: one call wires up the license, the license screen, background
 * checks and updates for a plugin.
 *
 *     $licensing = \PolarWP\Licensing\Licensing::register( array(
 *         'plugin_file'         => __FILE__,
 *         'organization_id'     => 'your-polar-organization-id',
 *         'update_manifest_url' => 'https://example.com/updates/my-plugin.json',
 *     ) );
 *
 *     if ( $licensing->is_valid() ) { ... }
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

	/**
	 * @param array $config {
	 *     @type string       $plugin_file         Required. Main plugin file (__FILE__).
	 *     @type string       $organization_id     Required. Your Polar organization ID.
	 *     @type string       $slug                Defaults to the plugin folder name.
	 *     @type string       $name                Defaults to the "Plugin Name" header.
	 *     @type string       $version             Defaults to the "Version" header.
	 *     @type string       $benefit_id          Polar license key benefit ID. Set it if you sell more than one product.
	 *     @type bool         $sandbox             Use sandbox-api.polar.sh. Default false.
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

		$this->warn_about_bad_ids( $name, $config );

		$api = new ApiClient( (string) $config['organization_id'], ! empty( $config['sandbox'] ) );

		$this->license = new License(
			$slug,
			$api,
			array_intersect_key( $config, array_flip( array( 'benefit_id', 'grace_period', 'check_interval' ) ) )
		);

		// Background re-check, so revoked/refunded licenses stop working without anyone visiting the license screen.
		$this->cron_hook = 'polarwp_license_check_' . $slug;
		add_action( $this->cron_hook, array( $this->license, 'maybe_check' ) );
		add_action( 'init', array( $this, 'schedule_check' ) );
		register_deactivation_hook( $plugin_file, array( $this, 'unschedule_check' ) );

		if ( is_admin() ) {
			$this->admin_page = new AdminPage( $this->license, $plugin_file, $name, $config );
			$this->admin_page->register();
		}

		if ( ! empty( $config['update_manifest_url'] ) ) {
			$this->updater = new Updater( $plugin_file, $version, (string) $config['update_manifest_url'], $this->license );
			$this->updater->register();
		}
	}

	/**
	 * Polar IDs are UUIDs. A common setup mistake is pasting the organization's slug or
	 * a product name instead; Polar then answers every request with a confusing
	 * "Input should be a valid UUID" error. Catch it here with a clear admin notice.
	 */
	private function warn_about_bad_ids( string $plugin_name, array $config ): void {
		$labels = array(
			'organization_id' => 'Organization ID (Polar → Settings → General)',
			'benefit_id'      => 'License Keys benefit ID',
		);

		$bad = array();
		foreach ( $labels as $option => $label ) {
			if ( ! empty( $config[ $option ] ) && ! self::is_uuid( (string) $config[ $option ] ) ) {
				$bad[] = sprintf( '%s: "%s"', $label, (string) $config[ $option ] );
			}
		}

		if ( ! $bad ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () use ( $plugin_name, $bad ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s:</strong> %s</p><ul style="list-style:disc;padding-left:2em">%s</ul></div>',
					esc_html( $plugin_name ),
					esc_html__( 'These Polar settings should be IDs that look like 1f3c9a2e-7b4d-4c1a-9e8f-2a6b5c4d3e21, not names or slugs:', 'polarwp-licensing' ),
					implode( '', array_map( static function ( $line ) { return '<li>' . esc_html( $line ) . '</li>'; }, $bad ) )
				);
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
