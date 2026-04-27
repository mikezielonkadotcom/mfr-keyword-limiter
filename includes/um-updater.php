<?php
/**
 * Update Machine Plugin Updater — Drop-in self-update for private WordPress plugins.
 *
 * Checks a public release server for updates and hooks into WordPress's
 * native update system. Supports auto-registration with HMAC authentication
 * and optional license-gated updates via DPT_License_Client.
 *
 * Usage in your plugin's main file:
 *
 *     require_once __DIR__ . '/includes/um-updater.php';
 *     \UM\PluginUpdater\register( [
 *         'file'       => __FILE__,
 *         'slug'       => 'my-plugin',
 *         'update_url' => 'https://updatemachine.com/my-plugin/update.json',
 *         'server'     => 'https://updatemachine.com',
 *     ] );
 *
 * For license-gated updates (DPT plugins):
 *
 *     $updater = \UM\PluginUpdater\register( [ ... ] );
 *     $updater->set_license_client( $license_client );
 *
 * @package UM\PluginUpdater
 * @version 4.0.0
 */

namespace UM\PluginUpdater;

defined( 'ABSPATH' ) || exit;

// Guard: multiple plugins may include this file. Wrap declarations.

/**
 * Shared secret for HMAC-signed auto-registration.
 * Falls back to WordPress's AUTH_KEY — unique per install, no operator action needed.
 * Override via wp-config.php: define( 'UM_REGISTRATION_SECRET', 'your-secret' );
 */
if ( ! defined( 'UM_REGISTRATION_SECRET' ) ) {
	define( 'UM_REGISTRATION_SECRET', AUTH_KEY );
}

/**
 * Register a plugin for self-hosted updates.
 *
 * @param array $config {
 *     @type string $file       Full path to the plugin's main file (__FILE__).
 *     @type string $slug       Plugin directory slug (e.g. 'my-plugin').
 *     @type string $update_url Full URL to the update.json manifest.
 *     @type string $server     Base URL of the update server (e.g. 'https://updatemachine.com').
 * }
 * @return Updater|null The updater instance, or null if already registered.
 */
if ( ! function_exists( __NAMESPACE__ . '\\register' ) ) {
function register( array $config ): ?Updater {
	static $registered = [];

	$slug = $config['slug'] ?? '';
	if ( empty( $slug ) || isset( $registered[ $slug ] ) ) {
		return $registered[ $slug ] ?? null;
	}

	$updater = new Updater( $config );
	$updater->init();
	$registered[ $slug ] = $updater;

	return $updater;
}
} // end function_exists guard

/**
 * Handles update checks for a single plugin.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Updater' ) ) {
class Updater {

	private string $file;
	private string $slug;
	private string $update_url;
	private string $server;
	private string $basename;
	private string $cache_key;
	private string $key_option;

	/** @var \DPT_License_Client|null Optional license client for gated updates. */
	private $license_client = null;

	private const CACHE_TTL = HOUR_IN_SECONDS;
	private const ERROR_TTL = HOUR_IN_SECONDS;

	public function __construct( array $config ) {
		$this->file       = $config['file'];
		$this->slug       = $config['slug'];
		$this->update_url = $config['update_url'];
		$this->server     = rtrim( $config['server'] ?? '', '/' );
		$this->basename   = plugin_basename( $this->file );
		$this->cache_key  = 'um_update_' . $this->slug;
		$this->key_option = 'um_site_key_' . $this->slug;
	}

	/**
	 * Set a license client for license-gated updates.
	 *
	 * When set, updates are only downloadable with a valid license.
	 * When null (default), updates flow freely (MZV/free plugins).
	 *
	 * @param \DPT_License_Client $client License client instance.
	 */
	public function set_license_client( $client ): void {
		$this->license_client = $client;
	}

	/**
	 * Hook into WordPress update system.
	 */
	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
		add_filter( 'upgrader_pre_download', [ $this, 'verify_download' ], 10, 4 );

		// Auto-register on activation if we have a secret and no key yet.
		register_activation_hook( $this->file, [ $this, 'on_activation' ] );
	}

	/**
	 * Auto-register with the update server on plugin activation.
	 */
	public function on_activation(): void {
		$secret = defined( 'UM_REGISTRATION_SECRET' ) ? UM_REGISTRATION_SECRET : '';
		if ( empty( $secret ) || empty( $this->server ) ) {
			return;
		}

		// If we already have a key, don't re-register.
		$existing = get_option( $this->key_option );
		if ( ! empty( $existing ) ) {
			return;
		}

		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = $plugin_data['Version'] ?? '';

		$site_url    = get_site_url();
		$plugin_slug = $this->slug;
		$timestamp   = time();

		// HMAC signature: SHA-256( site_url|plugin_slug|timestamp, secret )
		$message   = "{$site_url}|{$plugin_slug}|{$timestamp}";
		$signature = hash_hmac( 'sha256', $message, $secret );

		$response = wp_remote_post( $this->server . '/register', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'       => $site_url,
				'site_name'      => get_bloginfo( 'name' ),
				'admin_email'    => get_bloginfo( 'admin_email' ),
				'plugin_slug'    => $plugin_slug,
				'plugin_version' => $current_version,
				'timestamp'      => $timestamp,
				'signature'      => $signature,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 201 !== $code ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['site_key'] ) ) {
			update_option( $this->key_option, $body['site_key'], false );
		}
	}

	/**
	 * Get the stored site key for this plugin.
	 */
	private function get_site_key(): string {
		return (string) get_option( $this->key_option, '' );
	}

	/**
	 * Check for updates and inject into the update transient.
	 */
	public function check_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->fetch_update_data();

		if ( ! $remote ) {
			return $transient;
		}

		$current_version = $transient->checked[ $this->basename ] ?? '0.0.0';

		// Validate download URL origin, then append key if we have one.
		$download_url = $this->validate_download_url( $remote->download_url ?? '' );
		$site_key     = $this->get_site_key();
		if ( $download_url && $site_key ) {
			$download_url = add_query_arg( 'key', $site_key, $download_url );
		}

		// License-gated: if license client is set and invalid, show update but block download.
		if ( null !== $this->license_client && ! $this->license_client->is_valid() ) {
			if ( version_compare( $remote->version, $current_version, '>' ) ) {
				$transient->response[ $this->basename ] = (object) [
					'slug'           => $this->slug,
					'plugin'         => $this->basename,
					'new_version'    => $remote->version,
					'url'            => $remote->homepage ?? '',
					'package'        => '', // Empty = WP won't offer download.
					'icons'          => (array) ( $remote->icons ?? [] ),
					'banners'        => (array) ( $remote->banners ?? [] ),
					'tested'         => $remote->tested ?? '',
					'requires'       => $remote->requires ?? '',
					'requires_php'   => $remote->requires_php ?? '',
					'upgrade_notice' => __( 'A valid license is required to download this update.', 'um-updater' ),
				];
			}
			return $transient;
		}

		$plugin_data = (object) [
			'slug'         => $this->slug,
			'plugin'       => $this->basename,
			'new_version'  => $remote->version,
			'url'          => $remote->homepage ?? '',
			'package'      => $download_url,
			'icons'        => (array) ( $remote->icons ?? [] ),
			'banners'      => (array) ( $remote->banners ?? [] ),
			'tested'       => $remote->tested ?? '',
			'requires'     => $remote->requires ?? '',
			'requires_php' => $remote->requires_php ?? '',
		];

		// License in grace period — allow update but warn about payment.
		if ( null !== $this->license_client && 'past_due' === $this->license_client->get_status() ) {
			$plugin_data->upgrade_notice = __( 'Your payment is past due. Please update your payment method to continue receiving updates.', 'um-updater' );
		}

		if ( version_compare( $remote->version, $current_version, '>' ) ) {
			$transient->response[ $this->basename ] = $plugin_data;
		} else {
			$transient->no_update[ $this->basename ] = $plugin_data;
		}

		return $transient;
	}

	/**
	 * Populate the plugin information modal ("View details" link).
	 */
	public function plugin_info( $result, string $action, object $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ( $args->slug ?? '' ) !== $this->slug ) {
			return $result;
		}

		$remote = $this->fetch_update_data();

		if ( ! $remote ) {
			return $result;
		}

		$download_url = $this->validate_download_url( $remote->download_url ?? '' );
		$site_key     = $this->get_site_key();
		if ( $download_url && $site_key ) {
			$download_url = add_query_arg( 'key', $site_key, $download_url );
		}

		return (object) [
			'name'           => $remote->name ?? $this->slug,
			'slug'           => $this->slug,
			'version'        => $remote->version,
			'author'         => $remote->author ?? '',
			'author_profile' => $remote->author_homepage ?? '',
			'homepage'       => $remote->homepage ?? '',
			'download_link'  => $download_url,
			'trunk'          => $download_url,
			'last_updated'   => $remote->last_updated ?? '',
			'requires'       => $remote->requires ?? '',
			'requires_php'   => $remote->requires_php ?? '',
			'tested'         => $remote->tested ?? '',
			'sections'       => (array) ( $remote->sections ?? [] ),
			'banners'        => (array) ( $remote->banners ?? [] ),
			'icons'          => (array) ( $remote->icons ?? [] ),
		];
	}

	/**
	 * Add "Check for updates" link to plugin row meta.
	 */
	public function plugin_row_meta( array $meta, string $plugin ): array {
		if ( $plugin !== $this->basename ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'plugins.php?um_check_update=' . $this->slug ), 'um_check_' . $this->slug ) ),
			esc_html__( 'Check for updates', 'um-updater' )
		);

		return $meta;
	}

	/**
	 * Validate that a download URL's host matches the configured update server.
	 *
	 * Blocks supply-chain attacks where a compromised manifest redirects downloads
	 * to an attacker-controlled host.
	 *
	 * @param string $url Download URL from the remote manifest.
	 * @return string The original URL if valid, empty string if blocked.
	 */
	private function validate_download_url( string $url ): string {
		if ( empty( $url ) ) {
			return '';
		}

		$allowed_host = parse_url( $this->server, PHP_URL_HOST );
		$url_host     = parse_url( $url, PHP_URL_HOST );

		if ( $url_host !== $allowed_host ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Download URL host '{$url_host}' does not match server host '{$allowed_host}' — blocked." );
			return '';
		}

		return $url;
	}

	/**
	 * Intercept plugin download to verify SHA-256 integrity when the manifest provides it.
	 *
	 * @param bool|string|\WP_Error $reply    Default false (no pre-download).
	 * @param string                $package  Download URL.
	 * @param \WP_Upgrader          $upgrader Upgrader instance.
	 * @param array                 $hook_extra Extra data including 'plugin' basename.
	 * @return bool|string|\WP_Error Tmp file path, WP_Error on failure, or original $reply.
	 */
	public function verify_download( $reply, string $package, $upgrader, array $hook_extra ) {
		// Only intercept upgrades for our plugin.
		if ( ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
			return $reply;
		}

		$cached = get_transient( $this->cache_key );

		// No sha256 in cached manifest — allow but warn.
		if ( ! $cached || ! isset( $cached->sha256 ) ) {
			if ( $cached ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Update manifest missing sha256 field — skipping integrity check." );
			}
			return $reply;
		}

		// Download the ZIP to a temp file.
		$tmp = download_url( $package );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Compute and compare SHA-256.
		$actual = hash_file( 'sha256', $tmp );
		if ( ! hash_equals( $cached->sha256, $actual ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: SHA-256 mismatch — expected {$cached->sha256}, got {$actual}. Update blocked." );
			return new \WP_Error(
				'um_sha256_mismatch',
				__( 'Update blocked: ZIP integrity check failed. Please contact the plugin author.', 'um-updater' )
			);
		}

		return $tmp;
	}

	/**
	 * Fetch update data from the release server (with caching).
	 *
	 * Sends site telemetry via POST for analytics tracking.
	 * Includes X-Update-Key header if a site key is available.
	 * When a license client is set, includes license credentials in headers.
	 *
	 * @return object|null Parsed update manifest or null on failure.
	 */
	private function fetch_update_data(): ?object {
		// Bypass cache on manual "Check Again" click (WP core uses force-check=1).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force = isset( $_GET['force-check'] ) && '1' === $_GET['force-check'];

		// Also support our custom check URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['um_check_update'] ) && $_GET['um_check_update'] === $this->slug ) {
			if ( wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'um_check_' . $this->slug ) ) {
				$force = true;
			}
		}

		if ( $force ) {
			delete_transient( $this->cache_key );
		}

		$cached = get_transient( $this->cache_key );

		if ( false !== $cached ) {
			if ( 'error' === $cached ) {
				return null;
			}
			return $cached;
		}

		// Get current plugin version from file headers.
		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = $plugin_data['Version'] ?? '';

		// Build telemetry payload.
		$telemetry = [
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'admin_email'    => get_bloginfo( 'admin_email' ),
			'plugin_version' => $current_version,
		];

		// Build request headers, including auth key if available.
		$request_headers = [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		];

		$site_key = $this->get_site_key();
		if ( $site_key ) {
			$request_headers['X-Update-Key'] = $site_key;
		}

		// Include license credentials when a license client is wired up.
		if ( null !== $this->license_client ) {
			$license_key = $this->license_client->decrypt_key();
			if ( '' !== $license_key ) {
				$request_headers['X-License-Key'] = $license_key;
				$request_headers['X-Site-URL']    = get_site_url();
			}
		}

		// POST with telemetry (server responds with update.json content).
		$response = wp_remote_post( $this->update_url, [
			'timeout' => 10,
			'headers' => $request_headers,
			'body'    => wp_json_encode( $telemetry ),
		] );

		// Fallback to GET if POST fails (e.g. server doesn't support POST yet).
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$get_headers = [ 'Accept' => 'application/json' ];
			if ( $site_key ) {
				$get_headers['X-Update-Key'] = $site_key;
			}
			if ( null !== $this->license_client ) {
				$license_key = $this->license_client->decrypt_key();
				if ( '' !== $license_key ) {
					$get_headers['X-License-Key'] = $license_key;
					$get_headers['X-Site-URL']    = get_site_url();
				}
			}
			$response = wp_remote_get( $this->update_url, [
				'timeout' => 10,
				'headers' => $get_headers,
			] );
		}

		if ( is_wp_error( $response ) ) {
			set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( ! $data || empty( $data->version ) ) {
			set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		// Forward server-side warnings to the license client (e.g. "payment past due").
		if ( null !== $this->license_client && isset( $data->warning ) ) {
			$this->license_client->store_update_warning( $data->warning );
		}

		set_transient( $this->cache_key, $data, self::CACHE_TTL );

		return $data;
	}
}
} // end class_exists guard
