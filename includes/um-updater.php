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
 * @version 4.4.1
 */

namespace UM\PluginUpdater;

defined( 'ABSPATH' ) || exit;

// Guard: multiple plugins may include this file. Wrap declarations.

// Every bundled copy records itself here at include time — even when another
// copy's classes win the class_exists race below — so the copy that DOES boot
// can detect version skew and warn (see Updater::maybe_warn_version_skew).
// Keep this literal in sync with @version.
$GLOBALS['um_updater_sdk_copies']['4.4.1'][] = __FILE__;

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
	private string $challenge_transient;

	/** SDK version reported in telemetry — must match the file's @version. */
	public const SDK_VERSION = '4.4.1';

	private const CHALLENGE_TTL = 15 * MINUTE_IN_SECONDS;

	/** @var Telemetry_Opt_Out Per-plugin telemetry opt-out (option storage + settings UI). */
	private Telemetry_Opt_Out $opt_out;

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
		$this->challenge_transient = 'um_challenge_' . $this->slug;
		$this->opt_out    = new Telemetry_Opt_Out( $this->slug );
	}

	/**
	 * Get the telemetry opt-out handler for this plugin.
	 *
	 * Drop the opt-out checkbox into any admin settings form:
	 *
	 *     $updater->telemetry_opt_out()->render_field();
	 *
	 * Saving is handled automatically on admin_init (own nonce), so it works
	 * inside Settings API forms and custom panels alike.
	 */
	public function telemetry_opt_out(): Telemetry_Opt_Out {
		return $this->opt_out;
	}

	/**
	 * Delete all options/transients this updater stores for a plugin.
	 *
	 * Call from the host plugin's uninstall.php:
	 *
	 *     \UM\PluginUpdater\Updater::cleanup( 'my-plugin' );
	 */
	public static function cleanup( string $slug ): void {
		delete_option( 'um_site_key_' . $slug );
		delete_option( 'um_telemetry_optout_' . $slug );
		delete_transient( 'um_update_' . $slug );
		delete_transient( 'um_challenge_' . $slug );
		wp_clear_scheduled_hook( 'um_updater_challenge_verify_' . $slug );
	}

	/**
	 * Warn admins when a newer SDK copy is bundled but an older copy loaded
	 * first and is serving all plugins (first class_exists wins). Only copies
	 * v4.4.0+ self-report, so pre-4.4 stragglers can't be detected — but the
	 * common case (fleet mostly current, one plugin ahead) is.
	 */
	public static function maybe_warn_version_skew(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$newest = self::SDK_VERSION;
		foreach ( array_keys( $GLOBALS['um_updater_sdk_copies'] ?? [] ) as $version ) {
			if ( version_compare( (string) $version, $newest, '>' ) ) {
				$newest = (string) $version;
			}
		}

		if ( version_compare( $newest, self::SDK_VERSION, '<=' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: newest bundled SDK version, 2: SDK version actually running. */
				__( 'um-updater SDK version skew: a plugin bundles v%1$s, but v%2$s loaded first and is serving all plugins. Update the plugins bundling older copies.', 'um-updater' ),
				$newest,
				self::SDK_VERSION
			) )
		);
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

		// Wire the stored opt-out preference into the telemetry filter
		// and handle settings-form saves on admin_init.
		$this->opt_out->register_hooks();

		// Zero-config registration plumbing: the challenge route only
		// registers while a challenge transient exists, and the verify
		// event only fires after begin_challenge_registration schedules it.
		add_action( 'rest_api_init', [ $this, 'register_challenge_route' ] );
		add_action( 'um_updater_challenge_verify_' . $this->slug, [ $this, 'run_challenge_verify' ] );

		// Version-skew watchdog, hooked once no matter how many plugins
		// register an updater.
		if ( empty( $GLOBALS['um_updater_skew_hooked'] ) ) {
			$GLOBALS['um_updater_skew_hooked'] = true;
			add_action( 'admin_notices', [ __CLASS__, 'maybe_warn_version_skew' ] );
		}

		// Auto-register on activation if there's no key yet — HMAC when a
		// shared secret is configured, challenge–response otherwise.
		register_activation_hook( $this->file, [ $this, 'on_activation' ] );
	}

	/**
	 * Resolve the shared secret for HMAC-signed auto-registration.
	 *
	 * The update server verifies signatures against its REGISTRATION_SECRET,
	 * so registration only works with a secret shared with the server —
	 * define UM_REGISTRATION_SECRET in wp-config.php or supply one via the
	 * um_updater_registration_secret filter. When neither is set, the
	 * zero-config challenge flow runs instead (v4.3.0+, requires server
	 * support); if that's unavailable the site simply stays keyless —
	 * updates still work, keyed downloads require a site key.
	 */
	private function get_registration_secret(): string {
		$secret = defined( 'UM_REGISTRATION_SECRET' ) ? UM_REGISTRATION_SECRET : '';

		/**
		 * Filter the auto-registration shared secret.
		 *
		 * @param string $secret Secret from UM_REGISTRATION_SECRET, or '' if undefined.
		 * @param string $slug   Plugin slug being registered.
		 */
		return (string) apply_filters( 'um_updater_registration_secret', $secret, $this->slug );
	}

	/**
	 * Auto-register with the update server on plugin activation.
	 */
	public function on_activation(): void {
		if ( empty( $this->server ) ) {
			return;
		}

		// If we already have a key, don't re-register.
		$existing = get_option( $this->key_option );
		if ( ! empty( $existing ) ) {
			return;
		}

		$secret = $this->get_registration_secret();
		if ( ! empty( $secret ) ) {
			$this->register_with_secret( $secret );
			return;
		}

		// No shared secret configured — zero-config challenge registration
		// (requires ENABLE_CHALLENGE_REGISTRATION on the server; a 404 from
		// init just means it's off, and the site stays keyless as before).
		$this->begin_challenge_registration();
	}

	/**
	 * HMAC shared-secret registration (the original path, unchanged).
	 */
	private function register_with_secret( string $secret ): void {
		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = $plugin_data['Version'] ?? '';

		$site_url    = get_site_url();
		$plugin_slug = $this->slug;
		$timestamp   = time();

		// HMAC signature: SHA-256( site_url|plugin_slug|timestamp, secret )
		$message   = "{$site_url}|{$plugin_slug}|{$timestamp}";
		$signature = hash_hmac( 'sha256', $message, $secret );

		// Canonical endpoint is /api/register; older SDKs hit /register and
		// ride the server's compatibility rewrite.
		$response = wp_remote_post( $this->server . '/api/register', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'       => $site_url,
				'site_name'      => $this->opt_out->is_opted_out() ? '' : get_bloginfo( 'name' ),
				'plugin_slug'    => $plugin_slug,
				'plugin_version' => $current_version,
				'sdk_version'    => self::SDK_VERSION,
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
	 * Zero-config registration, step 1: request a challenge from the server
	 * and stage it for the verify fetch-back (see the server's
	 * SPEC-ZERO-CONFIG-REGISTRATION.md).
	 */
	private function begin_challenge_registration(): void {
		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = $plugin_data['Version'] ?? '';

		$response = wp_remote_post( $this->server . '/api/register/init', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'       => get_site_url(),
				'plugin_slug'    => $this->slug,
				'plugin_version' => $current_version,
				'sdk_version'    => self::SDK_VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) || 201 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['challenge_id'] ) || empty( $body['challenge_token'] ) ) {
			return;
		}

		set_transient( $this->challenge_transient, [
			'id'      => (string) $body['challenge_id'],
			'token'   => (string) $body['challenge_token'],
			'retried' => false,
		], self::CHALLENGE_TTL );

		$delay = max( 5, (int) ( $body['verify_after'] ?? 30 ) );
		wp_schedule_single_event( time() + $delay, 'um_updater_challenge_verify_' . $this->slug );
	}

	/**
	 * Serve the pending challenge token at
	 * GET /wp-json/um-updater/v1/challenge/{challenge_id}.
	 *
	 * Only registered while a challenge transient exists; returns the token
	 * only for the matching challenge id. Read-only, no side effects — the
	 * token is worthless to anyone who can't also answer for this domain.
	 */
	public function register_challenge_route(): void {
		$challenge = get_transient( $this->challenge_transient );
		if ( empty( $challenge['id'] ) || empty( $challenge['token'] ) ) {
			return;
		}

		$GLOBALS['um_updater_pending_challenges'][ (string) $challenge['id'] ] = [
			'slug'  => $this->slug,
			'token' => (string) $challenge['token'],
		];

		if ( ! empty( $GLOBALS['um_updater_challenge_route_registered'] ) ) {
			return;
		}

		$GLOBALS['um_updater_challenge_route_registered'] = true;

		register_rest_route( 'um-updater/v1', '/challenge/(?P<id>[0-9a-fA-F\-]{36})', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'serve_challenge_route' ],
		] );
	}

	/**
	 * Serve a challenge token from any plugin instance with a pending challenge.
	 *
	 * The route path stays global/back-compatible, so multiple bundled SDK
	 * plugins on the same site cannot race to replace each other's callback.
	 */
	public static function serve_challenge_route( $request ) {
		$id        = (string) $request['id'];
		$challenge = $GLOBALS['um_updater_pending_challenges'][ $id ] ?? null;

		if ( empty( $challenge['token'] ) ) {
			return new \WP_Error( 'um_unknown_challenge', __( 'Unknown challenge.', 'um-updater' ), [ 'status' => 404 ] );
		}

		return [ 'token' => $challenge['token'] ];
	}

	/**
	 * Zero-config registration, step 2 (wp-cron): ask the server to verify.
	 * Retries once at +10 minutes if the server couldn't reach this site,
	 * then gives up quietly — the site stays keyless, same as today.
	 */
	public function run_challenge_verify(): void {
		$challenge = get_transient( $this->challenge_transient );
		if ( empty( $challenge['id'] ) ) {
			return;
		}

		$response = wp_remote_post( $this->server . '/api/register/verify', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [ 'challenge_id' => $challenge['id'] ] ),
		] );

		if ( is_wp_error( $response ) ) {
			$this->maybe_retry_challenge( $challenge );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === $code && ! empty( $body['site_key'] ) ) {
			update_option( $this->key_option, $body['site_key'], false );
			delete_transient( $this->challenge_transient );
			return;
		}

		if ( 'unreachable' === ( $body['reason'] ?? '' ) ) {
			$this->maybe_retry_challenge( $challenge );
			return;
		}

		// token_mismatch / expired / anything else — give up quietly.
		delete_transient( $this->challenge_transient );
	}

	/**
	 * One retry at +10 minutes for transient reachability failures.
	 */
	private function maybe_retry_challenge( array $challenge ): void {
		if ( ! empty( $challenge['retried'] ) ) {
			delete_transient( $this->challenge_transient );
			return;
		}
		$challenge['retried'] = true;
		set_transient( $this->challenge_transient, $challenge, self::CHALLENGE_TTL );
		wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'um_updater_challenge_verify_' . $this->slug );
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

		$allowed_host   = parse_url( $this->server, PHP_URL_HOST );
		$url_host       = parse_url( $url, PHP_URL_HOST );
		$allowed_scheme = parse_url( $this->server, PHP_URL_SCHEME );
		$url_scheme     = parse_url( $url, PHP_URL_SCHEME );

		// Host AND scheme must match the configured server — a same-host http://
		// URL in a tampered manifest would otherwise downgrade the download to
		// plaintext and reopen the MITM door the origin check exists to close.
		if ( $url_host !== $allowed_host || $url_scheme !== $allowed_scheme ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Download URL '{$url_scheme}://{$url_host}' does not match server '{$allowed_scheme}://{$allowed_host}' — blocked." );
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
	 * Sends site telemetry via POST for analytics tracking — filterable via
	 * um_updater_telemetry, disabled entirely via um_updater_disable_telemetry.
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
			$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
			if ( wp_verify_nonce( $nonce, 'um_check_' . $this->slug ) ) {
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

		/**
		 * Filter whether to disable telemetry on update checks.
		 *
		 * When true, the update check sends an empty body — no site_url,
		 * site_name, or plugin_version. Auth headers (site key / license)
		 * still go out because they're needed to serve the manifest.
		 *
		 * @param bool   $disabled Default false.
		 * @param string $slug     Plugin slug being checked.
		 */
		$telemetry_disabled = (bool) apply_filters( 'um_updater_disable_telemetry', false, $this->slug );

		/**
		 * Filter the telemetry payload sent with update checks.
		 *
		 * @param array  $telemetry Payload: site_url, site_name, plugin_version, sdk_version.
		 * @param string $slug      Plugin slug being checked.
		 */
		$telemetry = apply_filters( 'um_updater_telemetry', [
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'plugin_version' => $current_version,
			'sdk_version'    => self::SDK_VERSION,
		], $this->slug );

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

		// POST (server responds with update.json content). All telemetry fields
		// are optional server-side, so a disabled payload is just "{}" — the
		// POST itself must stay because license-gated responses (download
		// tokens, warnings) only come back on this path.
		$response = wp_remote_post( $this->update_url, [
			'timeout' => 10,
			'headers' => $request_headers,
			'body'    => wp_json_encode( $telemetry_disabled ? (object) [] : $telemetry ),
		] );

		// Fallback to GET if POST fails (e.g. server doesn't support POST yet).
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
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

/**
 * Per-plugin telemetry opt-out: persistent preference + drop-in settings UI.
 *
 * Created and hooked automatically by Updater. Host plugins integrate with a
 * single line inside any admin settings <form>:
 *
 *     $updater->telemetry_opt_out()->render_field();
 *
 * Saving is self-contained: the field carries its own nonce, and maybe_save()
 * runs on admin_init, so it works inside Settings API forms (options.php),
 * custom panels, or anywhere else that POSTs to wp-admin. When opted out, the
 * hourly update check sends an empty body and registration omits the site
 * name — see the um_updater_disable_telemetry filter in Updater.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Telemetry_Opt_Out' ) ) {
class Telemetry_Opt_Out {

	private string $slug;
	private string $option;

	public function __construct( string $slug ) {
		$this->slug   = $slug;
		$this->option = 'um_telemetry_optout_' . $slug;
	}

	/**
	 * Hook the stored preference into the updater's telemetry filter and
	 * listen for settings-form saves. Called by Updater::init().
	 */
	public function register_hooks(): void {
		add_filter( 'um_updater_disable_telemetry', [ $this, 'filter_disabled' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_save' ] );
	}

	/**
	 * Whether this site has opted out of telemetry for this plugin.
	 */
	public function is_opted_out(): bool {
		return (bool) get_option( $this->option, false );
	}

	/**
	 * Persist the opt-out preference (programmatic API — the settings field
	 * uses this too). Clears the cached update check so the next request
	 * honors the new preference immediately.
	 */
	public function set_opted_out( bool $opted_out ): void {
		update_option( $this->option, $opted_out ? 1 : 0 );
		delete_transient( 'um_update_' . $this->slug );
	}

	/**
	 * Feed the stored preference into the um_updater_disable_telemetry filter.
	 *
	 * @param bool   $disabled Current filter value.
	 * @param string $slug     Plugin slug being checked.
	 */
	public function filter_disabled( bool $disabled, string $slug ): bool {
		if ( $slug === $this->slug && $this->is_opted_out() ) {
			return true;
		}
		return $disabled;
	}

	/**
	 * Render the opt-out checkbox. Place inside any admin settings <form>.
	 */
	public function render_field(): void {
		wp_nonce_field( 'um_privacy_optout_' . $this->slug, '_um_privacy_nonce_' . $this->slug );
		?>
		<fieldset class="um-telemetry-opt-out">
			<label for="<?php echo esc_attr( $this->option ); ?>">
				<input type="checkbox"
					id="<?php echo esc_attr( $this->option ); ?>"
					name="<?php echo esc_attr( $this->option ); ?>"
					value="1"
					<?php checked( $this->is_opted_out() ); ?> />
				<?php esc_html_e( 'Don\'t share this site\'s details during update checks', 'um-updater' ); ?>
			</label>
			<p class="description">
				<?php esc_html_e( 'By default, update checks share this site\'s URL, name, and the installed plugin version with the update server so updates can be delivered and support requests matched to installs. Opt out to send update checks with no site information. Updates keep working either way.', 'um-updater' ); ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Save the preference when a settings form containing render_field() is
	 * submitted. Hooked to admin_init; no-op unless our nonce is present.
	 */
	public function maybe_save(): void {
		$nonce_key = '_um_privacy_nonce_' . $this->slug;
		if ( ! isset( $_POST[ $nonce_key ] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) );
		if ( ! wp_verify_nonce( $nonce, 'um_privacy_optout_' . $this->slug ) ) {
			return;
		}

		$opted_out = ! empty( $_POST[ $this->option ] );
		if ( $opted_out !== $this->is_opted_out() ) {
			$this->set_opted_out( $opted_out );
		}
	}
}
} // end class_exists guard
