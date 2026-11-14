<?php
/**
 * Delivers plugin updates from your own JSON manifest, only to licensed sites.
 *
 * The manifest is a small JSON file you host anywhere (see examples/update-server/acme-pro.json).
 * Unlicensed sites still SEE that an update exists (WordPress shows "Automatic update
 * is unavailable for this plugin"), which is a gentle nudge to renew.
 *
 * The license key and activation id are appended to the download URL so your
 * download endpoint can check them with Polar before serving the zip
 * (see examples/update-server/download.php). Never put the zip at a public URL.
 *
 * @package PolarWP\Licensing
 */

namespace PolarWP\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {

	private string $plugin_file;
	private string $basename;
	private string $slug;
	private string $version;
	private string $manifest_url;
	private License $license;
	private int $cache_ttl;

	public function __construct( string $plugin_file, string $version, string $manifest_url, License $license, int $cache_ttl = 12 * HOUR_IN_SECONDS ) {
		$this->plugin_file  = $plugin_file;
		$this->basename     = plugin_basename( $plugin_file );
		$this->slug         = dirname( $this->basename );
		$this->version      = $version;
		$this->manifest_url = $manifest_url;
		$this->license      = $license;
		$this->cache_ttl    = $cache_ttl;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
		add_action( 'polarwp_license_activated', array( $this, 'flush_cache_for' ) );
		add_action( 'polarwp_license_deactivated', array( $this, 'flush_cache_for' ) );
	}

	/**
	 * @param mixed $transient The update_plugins transient (object, or false early on).
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$manifest = $this->manifest();
		if ( ! $manifest ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => $this->basename,
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $manifest['version'],
			'url'          => isset( $manifest['homepage'] ) ? (string) $manifest['homepage'] : '',
			'package'      => $this->license->is_valid() ? $this->package_url( $manifest ) : '',
			'tested'       => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires'     => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'requires_php' => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
		);

		if ( version_compare( $manifest['version'], $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			// Listing it under no_update lets WordPress show the auto-update toggle.
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Fills the "View details" modal on the Plugins screen.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$manifest = $this->manifest();
		if ( ! $manifest ) {
			return $result;
		}

		$data = get_file_data( $this->plugin_file, array( 'Name' => 'Plugin Name', 'Author' => 'Author' ) );

		return (object) array(
			'name'          => isset( $manifest['name'] ) ? (string) $manifest['name'] : $data['Name'],
			'slug'          => $this->slug,
			'version'       => $manifest['version'],
			'author'        => $data['Author'],
			'homepage'      => isset( $manifest['homepage'] ) ? (string) $manifest['homepage'] : '',
			'requires'      => isset( $manifest['requires'] ) ? (string) $manifest['requires'] : '',
			'tested'        => isset( $manifest['tested'] ) ? (string) $manifest['tested'] : '',
			'requires_php'  => isset( $manifest['requires_php'] ) ? (string) $manifest['requires_php'] : '',
			'last_updated'  => isset( $manifest['last_updated'] ) ? (string) $manifest['last_updated'] : '',
			'download_link' => $this->license->is_valid() ? $this->package_url( $manifest ) : '',
			'sections'      => array(
				'description' => isset( $manifest['description'] ) ? wp_kses_post( $manifest['description'] ) : '',
				'changelog'   => isset( $manifest['changelog'] ) ? wp_kses_post( $manifest['changelog'] ) : '',
			),
		);
	}

	public function flush_cache(): void {
		delete_site_transient( $this->cache_key() );
	}

	/** Hook callback: only flush when it's our license that changed. */
	public function flush_cache_for( License $license ): void {
		if ( $license === $this->license ) {
			$this->flush_cache();
			delete_site_transient( 'update_plugins' );
		}
	}

	/** @return array|null Manifest with at least "version" and "download_url", or null. */
	private function manifest(): ?array {
		$cached = get_site_transient( $this->cache_key() );
		if ( is_array( $cached ) ) {
			return $cached ?: null;
		}

		$response = wp_remote_get(
			$this->manifest_url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		$manifest = null;
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['version'] ) && ! empty( $data['download_url'] ) ) {
				$manifest = $data;
			}
		}

		// Cache failures too (as an empty array, for an hour) so a dead manifest URL
		// doesn't add a slow HTTP request to every admin page load.
		set_site_transient( $this->cache_key(), $manifest ?: array(), $manifest ? $this->cache_ttl : HOUR_IN_SECONDS );

		return $manifest;
	}

	private function package_url( array $manifest ): string {
		$state = $this->license->state();

		return add_query_arg(
			array_filter(
				array(
					'license_key'   => rawurlencode( $state['key'] ),
					'activation_id' => $state['activation_id'] ? rawurlencode( $state['activation_id'] ) : null,
					'site'          => rawurlencode( home_url() ),
				)
			),
			(string) $manifest['download_url']
		);
	}

	private function cache_key(): string {
		return 'polarwp_update_' . md5( $this->basename );
	}
}
