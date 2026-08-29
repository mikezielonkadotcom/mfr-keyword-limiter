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
 * @version 4.8.0
 */

namespace UM\PluginUpdater;

defined( 'ABSPATH' ) || exit;

// Guard: multiple plugins may include this file. Wrap declarations.

// Every bundled copy records itself here at include time — even when another
// copy's classes win the class_exists race below — so the copy that DOES boot
// can detect version skew and warn (see Updater::maybe_warn_version_skew).
// Keep this literal in sync with @version.
$GLOBALS['um_updater_sdk_copies']['4.8.0'][] = __FILE__;

/**
 * Validate an SDK endpoint before any hooks or requests are registered.
 *
 * Plain HTTP is limited to an explicit local-development escape hatch. It can
 * never be enabled for a public hostname.
 */
if ( ! function_exists( __NAMESPACE__ . '\\is_allowed_endpoint' ) ) {
function is_allowed_endpoint( $url, bool $allow_insecure_localhost = false ): bool {
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] )
		|| isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return false;
	}

	$scheme = strtolower( (string) $parts['scheme'] );
	$host   = trim( strtolower( rtrim( (string) $parts['host'], '.' ) ), '[]' );
	if ( 'https' === $scheme ) {
		return true;
	}
	if ( 'http' !== $scheme || ! $allow_insecure_localhost ) {
		return false;
	}

	return 'localhost' === $host
		|| '127.0.0.1' === $host
		|| '::1' === $host
		|| ( strlen( $host ) > 6 && '.local' === substr( $host, -6 ) );
}
} // end function_exists guard

/**
 * Register a plugin for self-hosted updates.
 *
 * @param array $config {
 *     @type string $file       Full path to the plugin's main file (__FILE__).
 *     @type string $slug       Plugin directory slug (e.g. 'my-plugin').
 *     @type string $update_url Full URL to the update.json manifest.
 *     @type string $server     Base URL of the update server (e.g. 'https://updatemachine.com').
 *     @type callable $usage_callback Optional callback returning flat usage data for telemetry.
 *     @type array $feature_telemetry Optional versioned feature telemetry schema and callback.
 *     @type array $activity_telemetry Optional reviewed activity fields merged into feature telemetry.
 *     @type string $telemetry_consent_mode Optional opt_out, opt_in, or disabled policy. Default opt_out.
 *     @type string $telemetry_privacy_url Optional public privacy-policy URL used by the settings control.
 *     @type string $telemetry_data_description Optional exact local disclosure shown beside the control.
 *     @type bool $allow_insecure_localhost Optional HTTP escape hatch for loopback and .local development only.
 *     @type array $parent {
 *         Optional. Declares this plugin as an add-on of an installed parent plugin.
 *         Add-on plugins MUST call register_addon() instead of register() — it is
 *         the only entry point that keeps parent gating in mixed-SDK fleets where
 *         an older bundled SDK copy can load first (see docs/addon-packages.md).
 *         When present, updates are only offered/installed while the installed parent
 *         satisfies the manifest's declared compatibility range.
 *
 *         @type string       $file      Required. Parent plugin basename, e.g. 'email-mic/email-mic.php'.
 *                                       Explicit — never inferred from manifest slugs.
 *         @type string       $slug      Required. Parent slug as published on Update Machine, e.g. 'email-mic'.
 *                                       Must match the manifest's parent.slug.
 *         @type int|callable $api_major Optional. The installed parent's runtime API major, either as a
 *                                       literal integer or a callable returning ?int evaluated lazily at
 *                                       gate time (recommended: read the parent's declared constant).
 *     }
 *     @type string $addon_auth Required for add-ons. Explicit authorization
 *                             mode: `parent_license` dynamically uses the
 *                             registered parent's site credential,
 *                             `package_key` keeps slug-local registration for
 *                             separately keyed packages, and `public` sends no
 *                             update credential. The server remains the source
 *                             of truth for license status and entitlements.
 * }
 * @return Updater|null The updater instance, the existing instance for a duplicate slug, or null for an empty slug.
 */
if ( ! function_exists( __NAMESPACE__ . '\\register' ) ) {
function register( array $config ): ?Updater {
	static $registered = [];

	$slug = $config['slug'] ?? '';
	if ( empty( $slug ) ) {
		return null;
	}
	if ( isset( $registered[ $slug ] ) ) {
		$existing = $registered[ $slug ];
		// An add-on registration must never inherit an ordinary updater that
		// happened to claim the same slug first.
		if ( array_key_exists( 'parent', $config ) && $existing instanceof Updater && ! $existing->is_addon_registration() ) {
			return null;
		}
		return $existing;
	}

	$allow_insecure = true === ( $config['allow_insecure_localhost'] ?? false );
	if ( ! is_allowed_endpoint( $config['update_url'] ?? '', $allow_insecure )
		|| ! is_allowed_endpoint( $config['server'] ?? '', $allow_insecure ) ) {
		return null;
	}

	$updater = new Updater( $config );
	$updater->init();
	$registered[ $slug ] = $updater;

	return $updater;
}
} // end function_exists guard

/**
 * Register an add-on plugin for parent-gated self-hosted updates.
 *
 * Add-on plugins MUST use this entry point instead of register(). Every SDK
 * copy that understands add-on gating (4.8.0+) defines this function even when
 * an older bundled SDK copy from another plugin loaded first and won the
 * register()/Updater symbol race. In that mixed old-first order the add-on is
 * never handed to the older SDK — it would serve ungated updates — so add-on
 * updates fail closed behind a request-scoped guard: any stale update offer is
 * stripped, downloads and uploaded overwrites for this plugin are blocked, and
 * an admin notice explains that the plugin bundling the outdated SDK copy must
 * be updated first. Healthy installed code is never deactivated.
 *
 * @param array $config Same shape as register(); the 'parent' entry is
 *                      required and validated by the add-on contract.
 * @return Updater|null Updater when a 4.8.0+ SDK copy serves the registration
 *                      (including the fail-closed duplicate/invalid-config
 *                      semantics of register()), or null when add-on updates
 *                      are disabled fail-closed for this request.
 */
if ( ! function_exists( __NAMESPACE__ . '\\register_addon' ) ) {
function register_addon( array $config ): ?Updater {
	static $guarded = [];

	// register() treats a present-but-invalid 'parent' as a fail-closed
	// add-on registration, so a missing block must not degrade to an
	// ordinary ungated registration.
	if ( ! array_key_exists( 'parent', $config ) || null === $config['parent'] ) {
		$config['parent'] = false;
	}

	$winning_sdk = defined( __NAMESPACE__ . '\\Updater::SDK_VERSION' )
		? (string) constant( __NAMESPACE__ . '\\Updater::SDK_VERSION' )
		: '0.0.0';
	if ( version_compare( $winning_sdk, '4.8.0', '>=' ) ) {
		$updater = register( $config );
		if ( null !== $updater ) {
			return $updater;
		}
		// A duplicate ordinary slug or invalid endpoint must remain fail-closed.
		$slug = isset( $config['slug'] ) && is_string( $config['slug'] ) ? $config['slug'] : '';
		if ( '' === $slug || isset( $guarded[ $slug ] ) ) {
			return null;
		}
		$guarded[ $slug ] = true;
		$guard = new Addon_Update_Guard( $config, $winning_sdk );
		$guard->init();
		return null;
	}

	// An older SDK copy owns register()/Updater for this request. Fail closed:
	// never register the add-on with it.
	$slug = isset( $config['slug'] ) && is_string( $config['slug'] ) ? $config['slug'] : '';
	if ( '' === $slug || isset( $guarded[ $slug ] ) ) {
		return null;
	}
	$guarded[ $slug ] = true;

	$guard = new Addon_Update_Guard( $config, $winning_sdk );
	$guard->init();

	return null;
}
} // end function_exists guard

/**
 * Request-scoped fail-closed guard used when an older SDK copy won the
 * register()/Updater symbol race in a mixed-version fleet.
 *
 * This class name is new in 4.8.0, so the copy bundled with the add-on always
 * owns it — the class_exists race that hands register() to an older copy can
 * never hand add-on gating to code that does not implement it.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Addon_Update_Guard' ) ) {
class Addon_Update_Guard {

	private string $slug;
	private string $file;
	private string $basename;
	private string $winning_sdk;
	private string $parent_basename = '';
	private ?string $parent_min_version = null;
	private ?string $parent_max_version_exclusive = null;
	private bool $parent_config_valid = false;
	private bool $pending_activation_rollback = false;
	private bool $pending_rollback_network = false;

	public function __construct( array $config, string $winning_sdk ) {
		$this->slug        = isset( $config['slug'] ) && is_string( $config['slug'] ) ? $config['slug'] : '';
		$file              = isset( $config['file'] ) && is_string( $config['file'] ) ? $config['file'] : '';
		$this->file        = $file;
		$this->basename    = '' !== $file && function_exists( 'plugin_basename' ) ? plugin_basename( $file ) : '';
		$this->winning_sdk = $winning_sdk;

		$parent = $config['parent'] ?? null;
		if ( is_array( $parent ) ) {
			$parent_file = $parent['file'] ?? null;
			$parent_slug = $parent['slug'] ?? null;
			if ( is_string( $parent_file ) && strlen( $parent_file ) <= 191
				&& false === strpos( $parent_file, '..' )
				&& preg_match( '#^(?:[A-Za-z0-9._\- ]+/)?[A-Za-z0-9._\- ]+\.php$#', $parent_file )
				&& is_string( $parent_slug )
				&& preg_match( '/^[a-z0-9][a-z0-9._\-]{0,190}$/', $parent_slug ) ) {
				$min = $parent['min_version'] ?? null;
				$max = $parent['max_version_exclusive'] ?? null;
				$valid_range = ( null === $min || ( is_string( $min ) && preg_match( '/^[0-9][0-9A-Za-z.\-+]{0,63}$/', $min ) ) )
					&& ( null === $max || ( is_string( $max ) && preg_match( '/^[0-9][0-9A-Za-z.\-+]{0,63}$/', $max ) ) )
					&& ( null === $min || null === $max || version_compare( $min, $max, '<' ) );
				if ( ! $valid_range ) {
					return;
				}
				$this->parent_basename     = $parent_file;
				$this->parent_min_version  = $min;
				$this->parent_max_version_exclusive = $max;
				$this->parent_config_valid = true;
			}
		}
	}

	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'strip_update_offer' ], PHP_INT_MAX );
		add_filter( 'site_transient_update_plugins', [ $this, 'strip_update_offer' ], PHP_INT_MAX );
		add_filter( 'upgrader_pre_download', [ $this, 'block_download' ], 0, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'block_source_selection' ], 0, 4 );
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'network_admin_notices', [ $this, 'render_notice' ] );
		if ( '' !== $this->file && function_exists( 'register_activation_hook' ) ) {
			register_activation_hook( $this->file, [ $this, 'guard_activation' ] );
		}
	}

	/**
	 * Remove any stale/native update offer for the guarded add-on.
	 */
	public function strip_update_offer( $transient ) {
		if ( is_object( $transient ) && '' !== $this->basename && isset( $transient->response[ $this->basename ] ) ) {
			unset( $transient->response[ $this->basename ] );
		}
		return $transient;
	}

	/**
	 * Block package downloads for the guarded add-on only.
	 */
	public function block_download( $reply, $package = '', $upgrader = null, $hook_extra = [] ) {
		if ( '' === $this->basename || ! is_array( $hook_extra )
			|| ( $hook_extra['plugin'] ?? '' ) !== $this->basename ) {
			return $reply;
		}

		return new \WP_Error( 'um_addon_sdk_conflict', $this->conflict_message() );
	}

	/**
	 * Block uploaded/manual installs that would overwrite the guarded add-on.
	 */
	public function block_source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = [] ) {
		if ( '' === $this->basename || ! is_string( $source ) || '' === $source ) {
			return $source;
		}

		$our_dir = dirname( $this->basename );
		if ( '.' === $our_dir || basename( rtrim( $source, '/\\' ) ) !== $our_dir ) {
			return $source;
		}

		return new \WP_Error( 'um_addon_sdk_conflict', $this->conflict_message() );
	}

	/**
	 * Fail closed when an add-on is activated while an old SDK owns the symbols.
	 *
	 * Full manifest/API gating is unavailable in this mixed order, but the
	 * explicit registration contract still lets this copy enforce the same
	 * installed/active parent and declared version-range prerequisites.
	 *
	 * @param mixed $network_wide Whether WordPress is network-activating the add-on.
	 */
	public function guard_activation( $network_wide = false ): void {
		$network_wide = true === $network_wide;
		if ( $this->parent_is_ready( $network_wide ) ) {
			return;
		}

		$this->pending_activation_rollback = true;
		$this->pending_rollback_network    = $network_wide;
		add_action( 'activated_plugin', [ $this, 'rollback_blocked_activation' ], 10, 2 );
	}

	/**
	 * Roll back only the activation attempt that this guard rejected.
	 */
	public function rollback_blocked_activation( $plugin, $network_wide = false ): void {
		unset( $network_wide );
		if ( ! $this->pending_activation_rollback || $plugin !== $this->basename ) {
			return;
		}

		$this->pending_activation_rollback = false;
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $this->basename, true, $this->pending_rollback_network );
		}
	}

	/**
	 * Whether the explicitly registered parent is installed and active in the
	 * activation context. Network activation requires network activation of the
	 * parent; site activation accepts a network- or site-active parent.
	 */
	private function parent_is_ready( bool $network_wide ): bool {
		if ( ! $this->parent_config_valid ) {
			return false;
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$plugin_dir = (string) WP_PLUGIN_DIR;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$plugin_dir = (string) WP_CONTENT_DIR . '/plugins';
		} else {
			return false;
		}

		$parent_path = rtrim( $plugin_dir, '/\\' ) . '/' . $this->parent_basename;
		if ( ! is_file( $parent_path ) || ! is_readable( $parent_path ) ) {
			return false;
		}
		if ( null !== $this->parent_min_version || null !== $this->parent_max_version_exclusive ) {
			$headers = function_exists( 'get_file_data' ) ? get_file_data( $parent_path, [ 'Version' => 'Version' ] ) : [];
			$version = isset( $headers['Version'] ) ? trim( (string) $headers['Version'] ) : '';
			if ( '' === $version
				|| ( null !== $this->parent_min_version && version_compare( $version, $this->parent_min_version, '<' ) )
				|| ( null !== $this->parent_max_version_exclusive && version_compare( $version, $this->parent_max_version_exclusive, '>=' ) ) ) {
				return false;
			}
		}

		$network_active = function_exists( 'is_multisite' ) && is_multisite()
			? (array) get_site_option( 'active_sitewide_plugins', [] )
			: [];
		if ( isset( $network_active[ $this->parent_basename ] ) ) {
			return true;
		}
		if ( $network_wide ) {
			return false;
		}

		return in_array( $this->parent_basename, (array) get_option( 'active_plugins', [] ), true );
	}

	public function render_notice(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $this->conflict_message() )
		);
	}

	private function conflict_message(): string {
		return sprintf(
			/* translators: 1: add-on plugin slug, 2: SDK version that loaded first. */
			__( 'Update Machine: updates for the add-on "%1$s" are paused because an older um-updater SDK copy (v%2$s) bundled by another plugin loaded first and cannot enforce parent compatibility. Update the plugin bundling the older SDK copy to restore add-on updates.', 'um-updater' ),
			'' !== $this->slug ? $this->slug : $this->basename,
			$this->winning_sdk
		);
	}
}
} // end class_exists guard

/**
 * Resolves storage and identity for site-active and network-active plugins.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Storage_Scope' ) ) {
class Storage_Scope {

	private bool $network;

	public function __construct( string $basename ) {
		$network_active = function_exists( 'is_multisite' ) && is_multisite()
			? (array) get_site_option( 'active_sitewide_plugins', [] )
			: [];
		$this->network = isset( $network_active[ $basename ] );
	}

	public function is_network(): bool {
		return $this->network;
	}

	public function force_network(): void {
		$this->network = true;
	}

	public function get_option( string $key, $default = false ) {
		return $this->network ? get_site_option( $key, $default ) : get_option( $key, $default );
	}

	public function update_option( string $key, $value ): void {
		if ( $this->network ) {
			update_site_option( $key, $value );
			return;
		}
		update_option( $key, $value, false );
	}

	public function delete_option( string $key ): void {
		if ( $this->network ) {
			delete_site_option( $key );
			return;
		}
		delete_option( $key );
	}

	public function get_transient( string $key ) {
		return $this->network ? get_site_transient( $key ) : get_transient( $key );
	}

	public function set_transient( string $key, $value, int $ttl = 0 ): void {
		if ( $this->network ) {
			set_site_transient( $key, $value, $ttl );
			return;
		}
		set_transient( $key, $value, $ttl );
	}

	public function delete_transient( string $key ): void {
		if ( $this->network ) {
			delete_site_transient( $key );
			return;
		}
		delete_transient( $key );
	}

	public function site_url(): string {
		return $this->network && function_exists( 'network_home_url' )
			? untrailingslashit( network_home_url() )
			: get_site_url();
	}

	public function site_name(): string {
		if ( $this->network && function_exists( 'get_network' ) ) {
			$network = get_network();
			if ( is_object( $network ) && ! empty( $network->site_name ) ) {
				return (string) $network->site_name;
			}
		}
		return get_bloginfo( 'name' );
	}

	public function can_run_network_task(): bool {
		return ! $this->network || ! function_exists( 'is_main_site' ) || is_main_site();
	}

	/**
	 * Move durable main-site options into network storage and discard legacy caches.
	 *
	 * WordPress does not expose a transient's remaining TTL, so copying a legacy
	 * transient would make it permanent. Network-scoped code regenerates these
	 * caches with the correct TTL on demand.
	 */
	public function migrate_main_site_state( array $options, array $transients ): void {
		if ( ! $this->network || ( function_exists( 'is_main_site' ) && ! is_main_site() ) ) {
			return;
		}

		$missing = new \stdClass();
		foreach ( $options as $key ) {
			$value = get_option( $key, $missing );
			if ( $missing === get_site_option( $key, $missing ) && $missing !== $value ) {
				update_site_option( $key, $value );
			}
			if ( $missing !== $value ) {
				delete_option( $key );
			}
		}

		foreach ( $transients as $key ) {
			delete_transient( $key );
		}
	}
}
} // end class_exists guard

/**
 * Records bounded local activity and exposes only fixed enum summaries.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Activity_Telemetry' ) ) {
class Activity_Telemetry {

	private const STATE_VERSION = 1;
	private const MAX_METRICS   = 2;
	private const MAX_FIELDS    = 4;

	private string $slug;
	private string $option;
	private Storage_Scope $scope;
	private Telemetry_Preference $preference;
	private array $fields = [];
	private array $metrics = [];
	private bool $valid = false;
	private bool $loaded = false;
	private bool $dirty = false;
	private bool $flush_scheduled = false;
	private bool $purged = false;
	private array $state = [ 'version' => self::STATE_VERSION, 'metrics' => [] ];
	private $clock;

	public function __construct( string $slug, Storage_Scope $scope, Telemetry_Preference $preference, array $config, $clock = null ) {
		$this->slug       = $slug;
		$this->option     = 'um_activity_telemetry_' . $slug;
		$this->scope      = $scope;
		$this->preference = $preference;
		$this->clock      = is_callable( $clock ) ? $clock : null;
		$this->valid      = $this->configure( $config );
	}

	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Enum definitions merged into the existing typed feature schema.
	 */
	public function schema_fields(): array {
		if ( ! $this->valid ) {
			return [];
		}

		$definitions = [
			'active_days' => [ 'type' => 'enum', 'values' => [ 'high', 'low', 'medium', 'none' ] ],
			'recency'     => [ 'type' => 'enum', 'values' => [ 'month', 'never', 'older', 'today', 'week' ] ],
			'health'      => [ 'type' => 'enum', 'values' => [ 'healthy', 'insufficient', 'mixed', 'no_attempts', 'poor' ] ],
		];
		$fields = [];
		foreach ( $this->fields as $key => $field ) {
			$fields[ $key ] = $definitions[ $field['summary'] ];
		}
		ksort( $fields, SORT_STRING );
		return $fields;
	}

	/**
	 * Mark the current UTC day active for a declared metric.
	 */
	public function record_activity( string $metric ): void {
		try {
			if ( ! $this->can_record( $metric ) ) {
				return;
			}
			$this->load_state();
			$now  = $this->now();
			$day  = gmdate( 'Y-m-d', $now );
			$entry = $this->state['metrics'][ $metric ] ?? [ 'days' => [], 'recent' => 'never', 'outcomes' => [] ];
			if ( isset( $entry['days'][ $day ] ) && $day === ( $entry['recent'] ?? '' ) ) {
				return;
			}
			$entry['days'][ $day ] = true;
			$entry['recent']       = $day;
			$this->state['metrics'][ $metric ] = $entry;
			$this->mark_dirty();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Mark activity and retain only the first boolean outcome in a UTC hour.
	 */
	public function record_outcome( string $metric, bool $successful ): void {
		try {
			if ( ! $this->can_record( $metric ) ) {
				return;
			}
			$this->load_state();
			$now   = $this->now();
			$day   = gmdate( 'Y-m-d', $now );
			$hour  = 'h' . gmdate( 'H', $now );
			$entry = $this->state['metrics'][ $metric ] ?? [ 'days' => [], 'recent' => 'never', 'outcomes' => [] ];
			$changed = false;
			if ( ! isset( $entry['days'][ $day ] ) || $day !== ( $entry['recent'] ?? '' ) ) {
				$entry['days'][ $day ] = true;
				$entry['recent']       = $day;
				$changed               = true;
			}
			if ( ! empty( $this->metrics[ $metric ]['health'] ) && ! isset( $entry['outcomes'][ $day ][ $hour ] ) ) {
				$entry['outcomes'][ $day ][ $hour ] = $successful;
				$changed = true;
			}
			if ( $changed ) {
				$this->state['metrics'][ $metric ] = $entry;
				$this->mark_dirty();
			}
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Build the fixed enum values sent in the next feature snapshot.
	 */
	public function collect_values(): array {
		try {
			if ( ! $this->sharing_enabled() ) {
				$this->purge();
				return [];
			}
			$this->load_state();
			$now    = $this->now();
			$values = [];
			foreach ( $this->fields as $key => $field ) {
				$entry = $this->state['metrics'][ $field['metric'] ] ?? [ 'days' => [], 'recent' => 'never', 'outcomes' => [] ];
				switch ( $field['summary'] ) {
					case 'active_days':
						$values[ $key ] = $this->active_days_bucket( $entry, $now );
						break;
					case 'recency':
						$values[ $key ] = $this->recency_bucket( $entry, $now );
						break;
					case 'health':
						$values[ $key ] = $this->health_bucket( $entry, $now );
						break;
				}
			}
			ksort( $values, SORT_STRING );
			return $values;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Persist at most once per request. Public only because WordPress invokes it.
	 */
	public function flush(): void {
		if ( ! $this->dirty ) {
			return;
		}
		try {
			if ( ! $this->sharing_enabled() ) {
				$this->purge();
				return;
			}
			$this->scope->update_option( $this->option, $this->state );
		} catch ( \Throwable $e ) {
			// Telemetry persistence must never affect the host request.
		}
		$this->dirty           = false;
		$this->flush_scheduled = false;
	}

	/**
	 * Delete all locally retained activity immediately.
	 */
	public function purge(): void {
		if ( $this->purged ) {
			return;
		}
		try {
			$missing = new \stdClass();
			if ( $missing !== $this->scope->get_option( $this->option, $missing ) ) {
				$this->scope->delete_option( $this->option );
			}
		} catch ( \Throwable $e ) {
			// A storage failure must never affect preference changes or updates.
		}
		$this->state  = [ 'version' => self::STATE_VERSION, 'metrics' => [] ];
		$this->loaded = true;
		$this->dirty  = false;
		$this->purged = true;
	}

	private function configure( array $config ): bool {
		$fields = $config['fields'] ?? null;
		if ( ! is_array( $fields ) || $this->is_list_array( $fields ) || empty( $fields ) || count( $fields ) > self::MAX_FIELDS ) {
			return false;
		}

		$metrics = [];
		foreach ( $fields as $key => $field ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) || ! is_array( $field ) ) {
				return false;
			}
			$metric  = $field['metric'] ?? null;
			$summary = $field['summary'] ?? null;
			if ( ! is_string( $metric ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $metric )
				|| ! in_array( $summary, [ 'active_days', 'recency', 'health' ], true ) ) {
				return false;
			}
			$this->fields[ $key ] = [ 'metric' => $metric, 'summary' => $summary ];
			$metrics[ $metric ][ $summary ] = true;
		}
		if ( count( $metrics ) > self::MAX_METRICS ) {
			$this->fields = [];
			return false;
		}
		$this->metrics = $metrics;
		return true;
	}

	private function can_record( string $metric ): bool {
		if ( ! $this->valid || ! isset( $this->metrics[ $metric ] ) ) {
			return false;
		}
		if ( ! $this->sharing_enabled() ) {
			$this->purge();
			return false;
		}
		return true;
	}

	private function sharing_enabled(): bool {
		if ( ! $this->preference->is_enabled() ) {
			return false;
		}
		return ! (bool) apply_filters( 'um_updater_disable_telemetry', false, $this->slug );
	}

	private function load_state(): void {
		if ( $this->loaded ) {
			return;
		}
		$stored = $this->scope->get_option( $this->option, [] );
		$this->state  = $this->normalize_state( is_array( $stored ) ? $stored : [] );
		$this->loaded = true;
	}

	private function normalize_state( array $stored ): array {
		$now       = $this->now();
		$today     = $this->day_number( gmdate( 'Y-m-d', $now ) );
		$normalized = [ 'version' => self::STATE_VERSION, 'metrics' => [] ];
		$source     = isset( $stored['metrics'] ) && is_array( $stored['metrics'] ) ? $stored['metrics'] : [];

		foreach ( $this->metrics as $metric => $summaries ) {
			$entry = isset( $source[ $metric ] ) && is_array( $source[ $metric ] ) ? $source[ $metric ] : [];
			$clean = [ 'days' => [], 'recent' => 'never', 'outcomes' => [] ];
			foreach ( isset( $entry['days'] ) && is_array( $entry['days'] ) ? $entry['days'] : [] as $day => $active ) {
				$age = $this->valid_day_age( $day, $today );
				if ( null !== $age && $age <= 30 && true === $active ) {
					$clean['days'][ $day ] = true;
				}
			}
			$recent = $entry['recent'] ?? 'never';
			if ( 'older' === $recent ) {
				$clean['recent'] = 'older';
			} else {
				$age = $this->valid_day_age( $recent, $today );
				if ( null !== $age ) {
					$clean['recent'] = $age > 30 ? 'older' : $recent;
				}
			}
			foreach ( isset( $entry['outcomes'] ) && is_array( $entry['outcomes'] ) ? $entry['outcomes'] : [] as $day => $hours ) {
				$age = $this->valid_day_age( $day, $today );
				if ( null === $age || $age > 30 || ! is_array( $hours ) ) {
					continue;
				}
				foreach ( $hours as $hour => $successful ) {
					if ( is_string( $hour ) && preg_match( '/^h(?:[01][0-9]|2[0-3])$/', $hour ) && is_bool( $successful ) ) {
						$clean['outcomes'][ $day ][ $hour ] = $successful;
					}
				}
			}
			ksort( $clean['days'], SORT_STRING );
			ksort( $clean['outcomes'], SORT_STRING );
			$normalized['metrics'][ $metric ] = $clean;
		}

		if ( ! empty( $stored ) && $normalized !== $stored ) {
			$this->state = $normalized;
			$this->mark_dirty();
		}
		return $normalized;
	}

	private function mark_dirty(): void {
		$this->dirty  = true;
		$this->purged = false;
		if ( ! $this->flush_scheduled ) {
			$this->flush_scheduled = true;
			add_action( 'shutdown', [ $this, 'flush' ], PHP_INT_MAX );
		}
	}

	private function active_days_bucket( array $entry, int $now ): string {
		$today = $this->day_number( gmdate( 'Y-m-d', $now ) );
		$count = 0;
		foreach ( $entry['days'] ?? [] as $day => $active ) {
			$age = $this->valid_day_age( $day, $today );
			if ( true === $active && null !== $age && $age <= 29 ) {
				++$count;
			}
		}
		if ( 0 === $count ) {
			return 'none';
		}
		if ( $count <= 3 ) {
			return 'low';
		}
		return $count <= 14 ? 'medium' : 'high';
	}

	private function recency_bucket( array $entry, int $now ): string {
		$recent = $entry['recent'] ?? 'never';
		if ( 'never' === $recent || 'older' === $recent ) {
			return $recent;
		}
		$age = $this->valid_day_age( $recent, $this->day_number( gmdate( 'Y-m-d', $now ) ) );
		if ( null === $age ) {
			return 'never';
		}
		if ( 0 === $age ) {
			return 'today';
		}
		if ( $age <= 7 ) {
			return 'week';
		}
		return $age <= 30 ? 'month' : 'older';
	}

	private function health_bucket( array $entry, int $now ): string {
		$today   = $this->day_number( gmdate( 'Y-m-d', $now ) );
		$samples = 0;
		$success = 0;
		foreach ( $entry['outcomes'] ?? [] as $day => $hours ) {
			$age = $this->valid_day_age( $day, $today );
			if ( null === $age || $age > 29 || ! is_array( $hours ) ) {
				continue;
			}
			foreach ( $hours as $outcome ) {
				if ( is_bool( $outcome ) ) {
					++$samples;
					$success += $outcome ? 1 : 0;
				}
			}
		}
		if ( 0 === $samples ) {
			return 'no_attempts';
		}
		if ( $samples < 5 ) {
			return 'insufficient';
		}
		$ratio = $success / $samples;
		if ( $ratio >= 0.95 ) {
			return 'healthy';
		}
		return $ratio >= 0.5 ? 'mixed' : 'poor';
	}

	private function valid_day_age( $day, int $today ): ?int {
		if ( ! is_string( $day ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $day ) ) {
			return null;
		}
		$number = $this->day_number( $day );
		if ( null === $number || $number > $today ) {
			return null;
		}
		return $today - $number;
	}

	private function day_number( string $day ): ?int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) )
			|| $date->format( 'Y-m-d' ) !== $day ) {
			return null;
		}
		return (int) floor( $date->getTimestamp() / DAY_IN_SECONDS );
	}

	private function now(): int {
		return null === $this->clock ? time() : (int) call_user_func( $this->clock );
	}

	private function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
} // end class_exists guard

/**
 * Validates a declarative feature schema and builds bounded snapshots.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Feature_Telemetry' ) ) {
class Feature_Telemetry {

	private const MAX_FIELDS                  = 20;
	private const MAX_KEY_LENGTH              = 32;
	private const MAX_ENUM_VALUES             = 12;
	private const MAX_ENUM_LENGTH             = 32;
	private const MAX_SCHEMA_BYTES            = 4096;
	private const MAX_VALUES_BYTES            = 2048;
	private const MAX_ENVELOPE_BYTES          = 6144;
	private const MAX_NUMBER_ABS              = 1000000000;
	private const MAX_SCHEMA_VERSION          = 65535;
	private const MAX_FLOAT_PRECISION         = 4;

	private string $slug;
	private array $config;
	private ?Activity_Telemetry $activity;

	public function __construct( string $slug, array $config, ?Activity_Telemetry $activity = null ) {
		$this->slug   = $slug;
		$this->config = $config;
		$this->activity = $activity;
	}

	/**
	 * Whether configured and generated fields form one valid reviewed schema.
	 */
	public function accepts_activity(): bool {
		$schema = $this->sanitize_schema();
		if ( null === $schema ) {
			return false;
		}
		$activity_fields = null === $this->activity ? [] : $this->activity->schema_fields();
		$fields          = array_merge( $schema['fields'], $activity_fields );
		ksort( $fields, SORT_STRING );
		return ! array_intersect_key( $schema['fields'], $activity_fields )
			&& count( $fields ) <= self::MAX_FIELDS
			&& strlen( wp_json_encode( [ 'fields' => $fields ] ) ) <= self::MAX_SCHEMA_BYTES;
	}

	/**
	 * Return a schema + values envelope, or null when absent or invalid.
	 */
	public function collect(): ?array {
		try {
			$schema          = $this->sanitize_schema();
			$activity_fields = null === $this->activity ? [] : $this->activity->schema_fields();
			if ( null === $schema || array_intersect_key( $schema['fields'], $activity_fields ) ) {
				return null;
			}
			$configured_fields = $schema['fields'];
			$schema['fields']   = array_merge( $configured_fields, $activity_fields );
			ksort( $schema['fields'], SORT_STRING );
			if ( count( $schema['fields'] ) > self::MAX_FIELDS
				|| strlen( wp_json_encode( [ 'fields' => $schema['fields'] ] ) ) > self::MAX_SCHEMA_BYTES ) {
				return null;
			}

			$values = [];
			try {
				$callback = $this->config['callback'] ?? null;
				if ( is_callable( $callback ) ) {
					$values = call_user_func( $callback, $this->slug );
				}

				/**
				 * Filter raw configured-feature values before schema validation.
				 *
				 * Activity values are merged afterward so host code cannot forge
				 * SDK-owned summaries.
				 *
				 * @param mixed  $values Raw callback values, or [].
				 * @param string $slug   Plugin slug being checked.
				 * @param array  $schema Sanitized combined declarative schema.
				 */
				$values = apply_filters( 'um_updater_features_' . $this->slug, $values, $this->slug, $schema );
				$values = $this->sanitize_values( $values, $configured_fields, ! empty( $activity_fields ) );
			} catch ( \Throwable $e ) {
				$values = ! empty( $activity_fields ) ? [] : null;
			}
			if ( null === $values ) {
				return null;
			}

			$activity_values = null === $this->activity ? [] : $this->activity->collect_values();
			$activity_values = $this->sanitize_values( $activity_values, $activity_fields, true ) ?? [];
			$values          = array_merge( $values, $activity_values );
			ksort( $values, SORT_STRING );
			if ( empty( $values ) || strlen( wp_json_encode( $values ) ) > self::MAX_VALUES_BYTES ) {
				return null;
			}

			$schema_json = wp_json_encode( [ 'fields' => $schema['fields'] ] );
			$envelope    = [
				'schema_version' => $schema['version'],
				'schema_hash'    => hash( 'sha256', $schema_json ),
				'schema'         => [ 'fields' => $schema['fields'] ],
				'values'         => $values,
			];

			if ( strlen( wp_json_encode( $envelope ) ) > self::MAX_ENVELOPE_BYTES ) {
				return null;
			}

			return $envelope;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Sanitize and canonicalize the plugin-provided schema.
	 */
	private function sanitize_schema(): ?array {
		$version = $this->config['schema_version'] ?? null;
		$fields  = $this->config['fields'] ?? null;
		if ( ! is_int( $version ) || $version < 1 || $version > self::MAX_SCHEMA_VERSION ) {
			return null;
		}
		if ( ! is_array( $fields ) || $this->is_list_array( $fields ) || count( $fields ) > self::MAX_FIELDS ) {
			return null;
		}
		if ( empty( $fields ) && ( null === $this->activity || empty( $this->activity->schema_fields() ) ) ) {
			return null;
		}

		$sanitized = [];
		foreach ( $fields as $key => $definition ) {
			if ( ! is_string( $key ) || strlen( $key ) > self::MAX_KEY_LENGTH || ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
				return null;
			}
			if ( ! is_array( $definition ) || empty( $definition['type'] ) ) {
				return null;
			}

			$type = $definition['type'];
			if ( 'boolean' === $type ) {
				$sanitized[ $key ] = [ 'type' => 'boolean' ];
				continue;
			}

			if ( 'integer' === $type || 'float' === $type ) {
				$min = $definition['min'] ?? null;
				$max = $definition['max'] ?? null;
				if ( ! $this->valid_number_bound( $min, $type ) || ! $this->valid_number_bound( $max, $type ) || $min > $max ) {
					return null;
				}
				$field = [ 'type' => $type, 'min' => $min, 'max' => $max ];
				if ( 'float' === $type ) {
					$precision = $definition['precision'] ?? 2;
					if ( ! is_int( $precision ) || $precision < 0 || $precision > self::MAX_FLOAT_PRECISION ) {
						return null;
					}
					$scale = 10 ** $precision;
					if ( abs( ( (float) $min * $scale ) - round( (float) $min * $scale ) ) > 0.0000001
						|| abs( ( (float) $max * $scale ) - round( (float) $max * $scale ) ) > 0.0000001 ) {
						return null;
					}
					$field['precision'] = $precision;
				}
				$sanitized[ $key ] = $field;
				continue;
			}

			if ( 'enum' === $type ) {
				$values = $definition['values'] ?? null;
				if ( ! is_array( $values ) || ! $this->is_list_array( $values ) || empty( $values ) || count( $values ) > self::MAX_ENUM_VALUES ) {
					return null;
				}
				$enum = [];
				foreach ( $values as $value ) {
					if ( ! is_string( $value ) || strlen( $value ) > self::MAX_ENUM_LENGTH || ! preg_match( '/^[A-Za-z0-9._+~-]+$/', $value ) ) {
						return null;
					}
					$enum[] = $value;
				}
				$enum = array_values( array_unique( $enum ) );
				if ( count( $enum ) !== count( $values ) ) {
					return null;
				}
				sort( $enum, SORT_STRING );
				$sanitized[ $key ] = [ 'type' => 'enum', 'values' => $enum ];
				continue;
			}

			return null;
		}

		ksort( $sanitized, SORT_STRING );
		if ( strlen( wp_json_encode( [ 'fields' => $sanitized ] ) ) > self::MAX_SCHEMA_BYTES ) {
			return null;
		}

		return [ 'version' => $version, 'fields' => $sanitized ];
	}

	/**
	 * Keep only values declared by the schema and matching the declared type.
	 */
	private function sanitize_values( $values, array $fields, bool $allow_empty = false ): ?array {
		if ( ! is_array( $values ) || $this->is_list_array( $values ) ) {
			return null;
		}

		$sanitized = [];
		foreach ( $fields as $key => $definition ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$value = $values[ $key ];
			switch ( $definition['type'] ) {
				case 'boolean':
					if ( is_bool( $value ) ) {
						$sanitized[ $key ] = $value;
					}
					break;
				case 'integer':
					if ( is_int( $value ) && $value >= $definition['min'] && $value <= $definition['max'] ) {
						$sanitized[ $key ] = $value;
					}
					break;
				case 'float':
					if ( ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value ) && $value >= $definition['min'] && $value <= $definition['max'] ) {
						$sanitized[ $key ] = round( (float) $value, $definition['precision'] );
					}
					break;
				case 'enum':
					if ( is_string( $value ) && in_array( $value, $definition['values'], true ) ) {
						$sanitized[ $key ] = $value;
					}
					break;
			}
		}

		if ( ( empty( $sanitized ) && ! $allow_empty ) || strlen( wp_json_encode( $sanitized ) ) > self::MAX_VALUES_BYTES ) {
			return null;
		}

		return $sanitized;
	}

	private function valid_number_bound( $value, string $type ): bool {
		if ( 'integer' === $type && ! is_int( $value ) ) {
			return false;
		}
		if ( 'float' === $type && ! is_int( $value ) && ! is_float( $value ) ) {
			return false;
		}
		return is_finite( (float) $value ) && abs( (float) $value ) <= self::MAX_NUMBER_ABS;
	}

	private function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
} // end class_exists guard

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
	private string $hash_expected_option;
	private string $challenge_transient;
	private string $challenge_expired_option;
	private string $download_403_option;
	private string $opportunistic_registration_option;
	private Storage_Scope $scope;
	private $usage_callback = null;
	private ?Feature_Telemetry $feature_telemetry = null;
	private ?Activity_Telemetry $activity_telemetry = null;

	/** @var array|null Normalized add-on parent registration, or null for ordinary plugins. */
	private ?array $parent_config = null;

	/** @var bool True when a 'parent' registration was supplied but rejected — all updates fail closed. */
	private bool $addon_registration_invalid = false;

	/** @var string Explicit add-on authorization mode. */
	private string $addon_auth_mode = '';

	/** @var bool True while a blocked add-on activation is waiting to be rolled back. */
	private bool $pending_activation_rollback = false;

	/** @var bool Whether the pending activation rollback is network-wide. */
	private bool $pending_rollback_network = false;

	/** SDK version reported in telemetry — must match the file's @version. */
	public const SDK_VERSION = '4.8.0';

	private const CHALLENGE_TTL             = 15 * MINUTE_IN_SECONDS;
	private const CHALLENGE_EXPIRED_WINDOW  = DAY_IN_SECONDS;
	private const DOWNLOAD_403_WINDOW       = 15 * MINUTE_IN_SECONDS;
	private const GLOBAL_PLUGIN_REGISTRY    = 'um_updater_registered_plugins';
	private const GLOBAL_VERSION_DISMISSALS = 'um_updater_dismissed_version_skew';
	private const REGISTRATION_RETRY_DELAYS = [
		1 => 5 * MINUTE_IN_SECONDS,
		2 => 30 * MINUTE_IN_SECONDS,
		3 => 2 * HOUR_IN_SECONDS,
	];
	private const MAX_REGISTRATION_RETRIES = 3;
	private const MAX_EXPIRED_CHALLENGES   = 3;

	/** @var Telemetry_Opt_Out Per-plugin telemetry preference compatibility wrapper. */
	private Telemetry_Opt_Out $opt_out;

	/** @var \DPT_License_Client|null Optional license client for gated updates. */
	private $license_client = null;

	private const CACHE_TTL = HOUR_IN_SECONDS;
	private const ERROR_TTL = 10 * MINUTE_IN_SECONDS;

	public function __construct( array $config ) {
		$this->file       = $config['file'];
		$this->slug       = $config['slug'];
		$this->update_url = $config['update_url'];
		$this->server     = rtrim( $config['server'] ?? '', '/' );
		$this->basename   = plugin_basename( $this->file );
		$this->cache_key  = 'um_update_' . $this->slug;
		$this->key_option = 'um_site_key_' . $this->slug;
		$this->hash_expected_option = 'um_hash_expected_' . $this->slug;
		$this->challenge_transient = 'um_challenge_' . $this->slug;
		$this->challenge_expired_option = 'um_challenge_expired_' . $this->slug;
		$this->download_403_option = 'um_download_403_' . $this->slug;
		$this->opportunistic_registration_option = 'um_registration_last_attempt_' . $this->slug;
		$this->scope      = new Storage_Scope( $this->basename );
		$this->scope->migrate_main_site_state(
			[
				$this->key_option,
				$this->hash_expected_option,
				'um_telemetry_consent_' . $this->slug,
				'um_telemetry_optout_' . $this->slug,
				'um_activity_telemetry_' . $this->slug,
				$this->challenge_expired_option,
				$this->download_403_option,
				$this->opportunistic_registration_option,
			],
			[ $this->cache_key, $this->challenge_transient ]
		);
		$this->usage_callback = $config['usage_callback'] ?? null;
		if ( array_key_exists( 'parent', $config ) ) {
			$this->parent_config = $this->normalize_parent_config( $config['parent'] );
			$auth_mode = $config['addon_auth'] ?? null;
			if ( is_string( $auth_mode ) && in_array( $auth_mode, [ 'parent_license', 'package_key', 'public' ], true ) ) {
				$this->addon_auth_mode = $auth_mode;
			}
			if ( null === $this->parent_config || '' === $this->addon_auth_mode ) {
				// Fail closed: a plugin that declared add-on semantics but got the
				// contract wrong must never receive ungated updates.
				$this->addon_registration_invalid = true;
			}
		}
		$this->opt_out    = new Telemetry_Opt_Out(
			$this->slug,
			$this->scope,
			[
				'mode'             => $config['telemetry_consent_mode'] ?? Telemetry_Preference::MODE_OPT_OUT,
				'privacy_url'      => $config['telemetry_privacy_url'] ?? '',
				'data_description' => $config['telemetry_data_description'] ?? '',
			]
		);
		$feature_config = ! empty( $config['feature_telemetry'] ) && is_array( $config['feature_telemetry'] )
			? $config['feature_telemetry']
			: null;
		if ( null !== $feature_config && ! empty( $config['activity_telemetry'] ) && is_array( $config['activity_telemetry'] ) ) {
			$activity = new Activity_Telemetry( $this->slug, $this->scope, $this->opt_out, $config['activity_telemetry'] );
			if ( $activity->is_valid() ) {
				$this->activity_telemetry = $activity;
			}
		}
		if ( null !== $feature_config ) {
			$features = new Feature_Telemetry( $this->slug, $feature_config, $this->activity_telemetry );
			if ( null !== $this->activity_telemetry && ! $features->accepts_activity() ) {
				$this->activity_telemetry = null;
				$features = new Feature_Telemetry( $this->slug, $feature_config );
			}
			$this->feature_telemetry = $features;
		}
		if ( null !== $this->activity_telemetry ) {
			$this->opt_out->set_disabled_callback( [ $this->activity_telemetry, 'purge' ] );
		}
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
	 * Get the positive telemetry sharing preference used by settings and onboarding.
	 */
	public function telemetry_preference(): Telemetry_Preference {
		return $this->opt_out;
	}

	/**
	 * Get the bounded activity helper declared by this plugin, if valid.
	 */
	public function activity_telemetry(): ?Activity_Telemetry {
		return $this->activity_telemetry;
	}

	/**
	 * Delete all options/transients this updater stores for a plugin.
	 *
	 * Call from the host plugin's uninstall.php:
	 *
	 *     \UM\PluginUpdater\Updater::cleanup( 'my-plugin' );
	 */
	public static function cleanup( string $slug ): void {
		$options = [
			'um_site_key_' . $slug,
			'um_hash_expected_' . $slug,
			'um_telemetry_consent_' . $slug,
			'um_telemetry_optout_' . $slug,
			'um_activity_telemetry_' . $slug,
			'um_challenge_expired_' . $slug,
			'um_download_403_' . $slug,
			'um_registration_last_attempt_' . $slug,
		];
		$transients = [ 'um_update_' . $slug, 'um_challenge_' . $slug ];
		$clean_site = static function () use ( $slug, $options, $transients ): void {
			foreach ( $options as $option ) {
				delete_option( $option );
			}
			foreach ( $transients as $transient ) {
				delete_transient( $transient );
			}
			wp_unschedule_hook( 'um_updater_challenge_verify_' . $slug );
			wp_unschedule_hook( 'um_updater_challenge_init_retry_' . $slug );
			self::remove_global_site_registration( $slug );
		};

		$clean_site();
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
			foreach ( get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
				if ( function_exists( 'get_current_blog_id' ) && (int) $site_id === get_current_blog_id() ) {
					continue;
				}
				switch_to_blog( (int) $site_id );
				$clean_site();
				restore_current_blog();
			}
		}

		$current_network_id = function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 0;
		$clean_network = static function ( int $network_id = 0 ) use ( $options, $transients, $current_network_id ): void {
			foreach ( $options as $option ) {
				if ( 0 < $network_id && function_exists( 'delete_network_option' ) ) {
					delete_network_option( $network_id, $option );
				} elseif ( function_exists( 'delete_site_option' ) ) {
					delete_site_option( $option );
				}
			}
			foreach ( $transients as $transient ) {
				if ( $network_id === $current_network_id && function_exists( 'delete_site_transient' ) ) {
					delete_site_transient( $transient );
				} elseif ( 0 < $network_id && function_exists( 'delete_network_option' ) ) {
					delete_network_option( $network_id, '_site_transient_' . $transient );
					delete_network_option( $network_id, '_site_transient_timeout_' . $transient );
				} elseif ( function_exists( 'delete_site_transient' ) ) {
					delete_site_transient( $transient );
				}
			}
		};

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_networks' ) ) {
			foreach ( get_networks( [ 'fields' => 'ids', 'number' => 0 ] ) as $network_id ) {
				$clean_network( (int) $network_id );
			}
		} else {
			$clean_network();
		}
	}

	/**
	 * Record that a site has loaded an Update Machine-powered plugin.
	 *
	 * The registry lets slug-scoped uninstall cleanup know when it is safe to
	 * remove cross-plugin SDK preferences without affecting another plugin.
	 */
	private function register_global_site_state(): void {
		$plugins = (array) get_option( self::GLOBAL_PLUGIN_REGISTRY, [] );
		if ( ! isset( $plugins[ $this->slug ] ) ) {
			$plugins[ $this->slug ] = true;
			update_option( self::GLOBAL_PLUGIN_REGISTRY, $plugins, false );
		}
	}

	/**
	 * Remove one plugin from the site registry and sweep global SDK state only
	 * after the final registered plugin is uninstalled.
	 */
	private static function remove_global_site_registration( string $slug ): void {
		$plugins = (array) get_option( self::GLOBAL_PLUGIN_REGISTRY, [] );
		if ( empty( $plugins ) || ! isset( $plugins[ $slug ] ) ) {
			return;
		}

		unset( $plugins[ $slug ] );

		if ( empty( $plugins ) ) {
			delete_option( self::GLOBAL_PLUGIN_REGISTRY );
			delete_option( self::GLOBAL_VERSION_DISMISSALS );
			return;
		}

		update_option( self::GLOBAL_PLUGIN_REGISTRY, $plugins, false );
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

		$pair      = self::version_skew_pair( self::SDK_VERSION, $newest );
		$dismissed = (array) get_option( self::GLOBAL_VERSION_DISMISSALS, [] );
		if ( ! empty( $dismissed[ $pair ] ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg(
				[
					'um_dismiss_sdk_skew' => $pair,
				],
				admin_url( 'plugins.php' )
			),
			'um_dismiss_sdk_skew_' . $pair
		);

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( sprintf(
				/* translators: 1: newest bundled SDK version, 2: SDK version actually running. */
				__( 'um-updater SDK version skew: a plugin bundles v%1$s, but v%2$s loaded first and is serving all plugins. Update the plugins bundling older copies.', 'um-updater' ),
				$newest,
				self::SDK_VERSION
			) ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this warning for this version pair.', 'um-updater' )
		);
	}

	/**
	 * Persist dismissal for the current loaded/newest SDK version pair.
	 */
	public static function maybe_dismiss_version_skew(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pair = sanitize_text_field( wp_unslash( $_GET['um_dismiss_sdk_skew'] ?? '' ) );
		if ( '' === $pair ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'um_dismiss_sdk_skew_' . $pair ) ) {
			return;
		}

		$dismissed          = (array) get_option( self::GLOBAL_VERSION_DISMISSALS, [] );
		$dismissed[ $pair ] = true;
		update_option( self::GLOBAL_VERSION_DISMISSALS, $dismissed, false );
	}

	/**
	 * Stable option key fragment for a loaded/newest SDK version pair.
	 */
	private static function version_skew_pair( string $loaded, string $newest ): string {
		return sanitize_key( $loaded . '__' . $newest );
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
		$this->register_global_site_state();

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 2 );
		add_filter( 'upgrader_pre_download', [ $this, 'verify_download' ], 10, 4 );

		// Add-on plugins: explain a withheld update instead of failing silently,
		// guard uploaded/manual installs that bypass the download hook, and
		// explain a blocked activation attempt.
		if ( null !== $this->parent_config || $this->addon_registration_invalid ) {
			add_action( 'admin_notices', [ $this, 'maybe_notice_withheld_addon_update' ] );
			add_action( 'network_admin_notices', [ $this, 'maybe_notice_withheld_addon_update' ] );
			add_action( 'admin_notices', [ $this, 'maybe_notice_blocked_addon_activation' ] );
			add_action( 'network_admin_notices', [ $this, 'maybe_notice_blocked_addon_activation' ] );
			add_filter( 'upgrader_source_selection', [ $this, 'guard_source_selection' ], 10, 4 );
		}

		// Wire the stored sharing preference into the telemetry filter
		// and handle settings-form saves on admin_init.
		$this->opt_out->register_hooks();

		// Zero-config registration plumbing: the challenge route only
		// registers while a challenge transient exists, and the verify
		// event only fires after begin_challenge_registration schedules it.
		add_action( 'rest_api_init', [ $this, 'register_challenge_route' ] );
		add_action( 'um_updater_challenge_verify_' . $this->slug, [ $this, 'run_challenge_verify' ] );
		add_action( 'um_updater_challenge_init_retry_' . $this->slug, [ $this, 'run_challenge_init_retry' ] );

		// Version-skew watchdog, hooked once no matter how many plugins
		// register an updater.
		if ( empty( $GLOBALS['um_updater_skew_hooked'] ) ) {
			$GLOBALS['um_updater_skew_hooked'] = true;
			add_action( 'admin_notices', [ __CLASS__, 'maybe_warn_version_skew' ] );
			add_action( 'admin_init', [ __CLASS__, 'maybe_dismiss_version_skew' ] );
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
	 *
	 * WordPress 6.0 and older WP-CLI activation paths can pass null instead
	 * of false for a site activation, so normalize the hook argument here.
	 *
	 * @param mixed $network_wide Whether the plugin is network activated.
	 */
	public function on_activation( $network_wide = false ): void {
		$network_wide = true === $network_wide;

		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			$this->scope->force_network();
			$this->scope->migrate_main_site_state(
				[
					$this->key_option,
					$this->hash_expected_option,
					'um_telemetry_consent_' . $this->slug,
					'um_telemetry_optout_' . $this->slug,
					$this->challenge_expired_option,
					$this->download_403_option,
					$this->opportunistic_registration_option,
				],
				[ $this->cache_key, $this->challenge_transient ]
			);
		}

		// Add-on activation guard: activating an add-on whose registered parent
		// is missing or inactive in the requested context is rolled back after
		// WordPress records the activation. This only affects the activation
		// attempt itself — already-active healthy installed code is never
		// touched — and an admin notice explains what to fix.
		if ( $this->is_addon_registration() ) {
			$blocked = $this->addon_activation_error( $network_wide );
			if ( null !== $blocked ) {
				$this->pending_activation_rollback = true;
				$this->pending_rollback_network    = $network_wide;
				add_action( 'activated_plugin', [ $this, 'rollback_blocked_activation' ], 10, 2 );
				$this->scope->set_transient( 'um_addon_activation_blocked_' . $this->slug, $blocked, 10 * MINUTE_IN_SECONDS );
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Add-on activation blocked — {$blocked}" );
				return;
			}
		}

		if ( empty( $this->server ) || ! $this->scope->can_run_network_task() ) {
			return;
		}

		// Public add-ons need no credential. Parent-licensed add-ons borrow the
		// parent's credential dynamically at request time and must never create a
		// slug-local ordinary key that could shadow that license activation.
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		// If we already have a key, don't re-register.
		$existing = $this->scope->get_option( $this->key_option );
		if ( ! empty( $existing ) ) {
			return;
		}

		$this->attempt_registration();
	}

	/**
	 * Why activating this add-on must be rolled back, or null when allowed.
	 *
	 * Network activation requires a network-active parent because the add-on's
	 * code would run on every site; site activation accepts a network-active
	 * or site-active parent.
	 */
	private function addon_activation_error( bool $network_wide ): ?string {
		if ( $this->addon_registration_invalid ) {
			return __( 'its add-on parent registration is invalid. Contact the plugin author.', 'um-updater' );
		}

		$parent_slug = $this->parent_config['slug'];
		$parent_path = $this->parent_plugin_path();
		if ( '' === $parent_path || ! is_file( $parent_path ) || ! is_readable( $parent_path ) ) {
			/* translators: %s: parent plugin slug. */
			return sprintf( __( 'it requires the "%s" plugin, which is not installed.', 'um-updater' ), $parent_slug );
		}

		// Enforce the same declared parent range at activation time as at update
		// gate time. This prevents an add-on from being activated against a
		// parent version that its manifest contract explicitly excludes.
		$parent_headers = function_exists( 'get_file_data' )
			? get_file_data( $parent_path, [ 'Version' => 'Version' ] )
			: [];
		$parent_version = isset( $parent_headers['Version'] ) ? trim( (string) $parent_headers['Version'] ) : '';
		if ( '' === $parent_version ) {
			return sprintf( __( 'it requires a readable version from the "%s" plugin before it can be activated.', 'um-updater' ), $parent_slug );
		}
		if ( null !== $this->parent_config['min_version']
			&& version_compare( $parent_version, $this->parent_config['min_version'], '<' ) ) {
			return sprintf( __( 'it requires "%1$s" %2$s or newer (installed: %3$s).', 'um-updater' ), $parent_slug, $this->parent_config['min_version'], $parent_version );
		}
		if ( null !== $this->parent_config['max_version_exclusive']
			&& version_compare( $parent_version, $this->parent_config['max_version_exclusive'], '>=' ) ) {
			return sprintf( __( 'it supports "%1$s" versions below %2$s (installed: %3$s).', 'um-updater' ), $parent_slug, $this->parent_config['max_version_exclusive'], $parent_version );
		}

		$parent_basename = $this->parent_config['file'];
		$network_active  = function_exists( 'is_multisite' ) && is_multisite()
			? (array) get_site_option( 'active_sitewide_plugins', [] )
			: [];
		if ( isset( $network_active[ $parent_basename ] ) ) {
			return null;
		}
		if ( $network_wide ) {
			/* translators: %s: parent plugin slug. */
			return sprintf( __( 'its parent plugin "%s" must be network-activated before this add-on can be network-activated.', 'um-updater' ), $parent_slug );
		}
		if ( in_array( $parent_basename, (array) get_option( 'active_plugins', [] ), true ) ) {
			return null;
		}

		/* translators: %s: parent plugin slug. */
		return sprintf( __( 'its parent plugin "%s" must be activated first.', 'um-updater' ), $parent_slug );
	}

	/**
	 * Deactivate a just-recorded blocked activation. Public only because
	 * WordPress invokes it on 'activated_plugin' (which fires after core
	 * writes the active-plugins option, so deactivating earlier is a no-op).
	 */
	public function rollback_blocked_activation( $plugin, $network_wide = false ): void {
		unset( $network_wide );
		if ( ! $this->pending_activation_rollback || $plugin !== $this->basename ) {
			return;
		}

		$this->pending_activation_rollback = false;
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $this->basename, true, $this->pending_rollback_network );
		}
	}

	/**
	 * Explain a rolled-back add-on activation to admins who can act on it.
	 */
	public function maybe_notice_blocked_addon_activation(): void {
		if ( ! $this->is_addon_registration() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$key     = 'um_addon_activation_blocked_' . $this->slug;
		$message = $this->scope->get_transient( $key );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		$this->scope->delete_transient( $key );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: plugin slug, 2: reason the activation was rolled back. */
				__( 'Update Machine: "%1$s" was not activated because %2$s', 'um-updater' ),
				$this->slug,
				$message
			) )
		);
	}

	/**
	 * Attempt whichever registration mode is configured for this site.
	 */
	private function attempt_registration(): void {
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
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

		$site_url    = $this->scope->site_url();
		$plugin_slug = $this->slug;
		$timestamp   = time();

		// HMAC signature: SHA-256( site_url|plugin_slug|timestamp, secret )
		$message   = "{$site_url}|{$plugin_slug}|{$timestamp}";
		$signature = hash_hmac( 'sha256', $message, $secret );

		// Canonical endpoint is /api/register; older SDKs hit /register and
		// ride the server's compatibility rewrite.
		$response = wp_remote_post( $this->server . '/api/register', [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'       => $site_url,
				'site_name'      => $this->opt_out->is_enabled() ? $this->scope->site_name() : '',
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
			$this->scope->update_option( $this->key_option, $body['site_key'] );
			$this->clear_registration_recovery_state();
		}
	}

	/**
	 * Zero-config registration, step 1: request a challenge from the server
	 * and stage it for the verify fetch-back (see the server's
	 * SPEC-ZERO-CONFIG-REGISTRATION.md).
	 */
	private function begin_challenge_registration( int $attempt = 0 ): void {
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = $plugin_data['Version'] ?? '';

		$response = wp_remote_post( $this->server . '/api/register/init', [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'       => $this->scope->site_url(),
				'plugin_slug'    => $this->slug,
				'plugin_version' => $current_version,
				'sdk_version'    => self::SDK_VERSION,
			] ),
		] );

		if ( is_wp_error( $response ) || 201 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->schedule_challenge_init_retry( $attempt + 1, $response );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['challenge_id'] ) || empty( $body['challenge_token'] ) ) {
			return;
		}

		$this->scope->set_transient( $this->challenge_transient, [
			'id'             => (string) $body['challenge_id'],
			'token'          => (string) $body['challenge_token'],
			'retried'        => false,
			'verify_attempt' => 0,
		], self::CHALLENGE_TTL );

		$delay = max( 5, (int) ( $body['verify_after'] ?? 30 ) );
		wp_schedule_single_event( time() + $delay, 'um_updater_challenge_verify_' . $this->slug );
	}

	/**
	 * Cron callback for delayed challenge-init retries.
	 */
	public function run_challenge_init_retry( int $attempt = 1 ): void {
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		if ( ! $this->scope->can_run_network_task() || empty( $this->server ) || $this->get_site_key() || $this->scope->get_transient( $this->challenge_transient ) ) {
			return;
		}

		$this->begin_challenge_registration( max( 1, $attempt ) );
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
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		$challenge = $this->scope->get_transient( $this->challenge_transient );
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
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			$this->scope->delete_transient( $this->challenge_transient );
			return;
		}

		if ( ! $this->scope->can_run_network_task() ) {
			return;
		}

		$challenge = $this->scope->get_transient( $this->challenge_transient );
		if ( empty( $challenge['id'] ) ) {
			$this->maybe_attempt_opportunistic_registration();
			return;
		}

		$response = wp_remote_post( $this->server . '/api/register/verify', [
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [ 'challenge_id' => $challenge['id'] ] ),
		] );

		if ( is_wp_error( $response ) ) {
			$this->maybe_retry_challenge( $challenge, $response );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 201 === $code && ! empty( $body['site_key'] ) ) {
			$this->scope->update_option( $this->key_option, $body['site_key'] );
			$this->scope->delete_transient( $this->challenge_transient );
			$this->clear_registration_recovery_state();
			return;
		}

		if ( 'unreachable' === ( $body['reason'] ?? '' ) ) {
			$this->maybe_retry_challenge( $challenge );
			return;
		}

		if ( 'expired' === ( $body['reason'] ?? '' ) ) {
			$this->handle_expired_challenge();
			return;
		}

		if ( $this->is_retryable_response( $response ) ) {
			$this->maybe_retry_challenge( $challenge, $response );
			return;
		}

		// token_mismatch / anything else non-retryable — give up quietly.
		$this->scope->delete_transient( $this->challenge_transient );
	}

	/**
	 * Re-initialize an expired challenge at most three times per day.
	 */
	private function handle_expired_challenge(): void {
		$this->scope->delete_transient( $this->challenge_transient );

		$now   = time();
		$state = $this->scope->get_option( $this->challenge_expired_option, [] );
		if ( ! is_array( $state ) || empty( $state['started_at'] ) || ( $now - (int) $state['started_at'] ) >= self::CHALLENGE_EXPIRED_WINDOW ) {
			$state = [
				'count'      => 0,
				'started_at' => $now,
			];
		}

		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
		$this->scope->update_option( $this->challenge_expired_option, $state );

		if ( $state['count'] >= self::MAX_EXPIRED_CHALLENGES ) {
			$this->scope->update_option( $this->opportunistic_registration_option, $now );
			return;
		}

		$this->attempt_registration();
	}

	/**
	 * Clear retry and cooldown state after registration succeeds.
	 */
	private function clear_registration_recovery_state(): void {
		$this->scope->delete_option( $this->challenge_expired_option );
		$this->scope->delete_option( $this->opportunistic_registration_option );
	}

	/**
	 * One retry at +10 minutes for transient reachability failures.
	 */
	private function maybe_retry_challenge( array $challenge, $response = null ): void {
		if ( null === $response ) {
			if ( ! empty( $challenge['retried'] ) ) {
				$this->scope->delete_transient( $this->challenge_transient );
				return;
			}
			$challenge['retried'] = true;
			$this->scope->set_transient( $this->challenge_transient, $challenge, self::CHALLENGE_TTL );
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, 'um_updater_challenge_verify_' . $this->slug );
			return;
		}

		$attempt = (int) ( $challenge['verify_attempt'] ?? 0 ) + 1;
		if ( $attempt > self::MAX_REGISTRATION_RETRIES || ! $this->is_retryable_response( $response ) ) {
			$this->scope->delete_transient( $this->challenge_transient );
			return;
		}

		$challenge['verify_attempt'] = $attempt;
		$this->scope->set_transient( $this->challenge_transient, $challenge, self::CHALLENGE_TTL );
		wp_schedule_single_event( time() + $this->retry_delay( $attempt, $response ), 'um_updater_challenge_verify_' . $this->slug );
	}

	/**
	 * Schedule a retry for transient challenge-init failures.
	 */
	private function schedule_challenge_init_retry( int $attempt, $response ): void {
		if ( $attempt > self::MAX_REGISTRATION_RETRIES || ! $this->is_retryable_response( $response ) ) {
			return;
		}

		wp_schedule_single_event( time() + $this->retry_delay( $attempt, $response ), 'um_updater_challenge_init_retry_' . $this->slug, [ $attempt ] );
	}

	/**
	 * Whether a registration response should be retried.
	 */
	private function is_retryable_response( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return 429 === $code || $code >= 500;
	}

	/**
	 * Backoff delay, honoring Retry-After on 429s.
	 */
	private function retry_delay( int $attempt, $response ): int {
		$default = self::REGISTRATION_RETRY_DELAYS[ $attempt ] ?? ( 2 * HOUR_IN_SECONDS );

		if ( ! is_wp_error( $response ) && 429 === wp_remote_retrieve_response_code( $response ) ) {
			$retry_after = $this->retry_after_seconds( $response );
			if ( $retry_after > 0 ) {
				return $retry_after;
			}
		}

		return $default;
	}

	/**
	 * Parse Retry-After as seconds or an HTTP date.
	 */
	private function retry_after_seconds( $response ): int {
		if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
			return 0;
		}

		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' === $value || null === $value ) {
			return 0;
		}

		if ( is_numeric( $value ) ) {
			return min( max( 0, (int) $value ), 6 * HOUR_IN_SECONDS );
		}

		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) {
			return 0;
		}

		return min( max( 0, $timestamp - time() ), 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Build optional plugin usage telemetry.
	 *
	 * @return array|null Sanitized usage object, or null when absent/invalid.
	 */
	private function collect_usage(): ?array {
		try {
			$usage = [];

			if ( is_callable( $this->usage_callback ) ) {
				$usage = call_user_func( $this->usage_callback, $this->slug );
			}

			/**
			 * Filter optional usage telemetry for this plugin.
			 *
			 * Return a flat associative array with up to 20 scalar feature flags,
			 * counters, or short string values. Invalid entries are dropped.
			 *
			 * @param mixed  $usage Raw usage data from the callback, or [].
			 * @param string $slug  Plugin slug being checked.
			 */
			$usage = apply_filters( 'um_updater_usage_' . $this->slug, $usage, $this->slug );

			return $this->sanitize_usage( $usage );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Build the versioned, typed feature telemetry envelope.
	 */
	private function collect_features(): ?array {
		return null === $this->feature_telemetry ? null : $this->feature_telemetry->collect();
	}

	/**
	 * Preserve the legacy telemetry filter as a field-removal hook only.
	 *
	 * This prevents plugins from adding free-form or secret-bearing fields to
	 * the SDK request while retaining the documented site-name removal use case.
	 */
	private function filter_base_telemetry( array $telemetry ): ?array {
		try {
			$filtered = apply_filters( 'um_updater_telemetry', $telemetry, $this->slug );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_array( $filtered ) ) {
			return null;
		}

		foreach ( array_keys( $telemetry ) as $key ) {
			if ( ! array_key_exists( $key, $filtered ) || $filtered[ $key ] !== $telemetry[ $key ] ) {
				unset( $telemetry[ $key ] );
			}
		}

		return $telemetry;
	}

	/**
	 * Sanitize usage telemetry to the server contract.
	 *
	 * @param mixed $usage Raw callback/filter return.
	 * @return array|null Sanitized usage object, or null when absent/invalid.
	 */
	private function sanitize_usage( $usage ): ?array {
		if ( ! is_array( $usage ) || $this->is_list_array( $usage ) ) {
			return null;
		}

		$sanitized = [];
		foreach ( $usage as $key => $value ) {
			$key = substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) ), 0, 32 );
			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				if ( ! is_finite( (float) $value ) ) {
					continue;
				}
				$sanitized[ $key ] = max( -1000000000, min( 1000000000, $value ) );
			} elseif ( is_string( $value ) ) {
				$sanitized[ $key ] = substr( preg_replace( '/[^A-Za-z0-9._+~-]/', '', $value ), 0, 64 );
			} else {
				continue;
			}

			if ( count( $sanitized ) >= 20 ) {
				break;
			}
		}

		if ( empty( $sanitized ) || strlen( wp_json_encode( $sanitized ) ) > 2048 ) {
			return null;
		}

		return $sanitized;
	}

	/**
	 * PHP 7.4-compatible list-array check.
	 */
	private function is_list_array( array $value ): bool {
		if ( [] === $value ) {
			return false;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Get the stored site key for this plugin.
	 */
	private function get_site_key(): string {
		return (string) $this->scope->get_option( $this->key_option, '' );
	}

	/**
	 * Resolve the request credential and its server-bound site identity.
	 *
	 * Parent-licensed add-ons read the exact registered parent's scoped option
	 * on every request. The key is never copied into add-on storage, so parent
	 * rotation is immediate and a stale/add-on-local auto-registration key can
	 * never shadow license authorization. Package entitlement, license status,
	 * domain binding, and revocation are still verified by Update Machine.
	 *
	 * @return array{site_key:string,site_url:string,site_name:string}
	 */
	private function request_identity(): array {
		$identity = [
			'site_key'  => '',
			'site_url'  => $this->scope->site_url(),
			'site_name' => $this->scope->site_name(),
		];

		if ( ! $this->is_addon_registration() ) {
			$identity['site_key'] = $this->get_site_key();
			return $identity;
		}

		if ( $this->addon_registration_invalid || null === $this->parent_config ) {
			return $identity;
		}

		if ( 'package_key' === $this->addon_auth_mode ) {
			$identity['site_key'] = $this->get_site_key();
			return $identity;
		}

		if ( 'parent_license' !== $this->addon_auth_mode || ! $this->is_parent_active_in_context() ) {
			return $identity;
		}

		$parent_path = $this->parent_plugin_path();
		if ( '' === $parent_path || ! is_file( $parent_path ) || ! is_readable( $parent_path ) ) {
			return $identity;
		}

		$parent_scope          = new Storage_Scope( $this->parent_config['file'] );
		$identity['site_key']  = (string) $parent_scope->get_option( 'um_site_key_' . $this->parent_config['slug'], '' );
		$identity['site_url']  = $parent_scope->site_url();
		$identity['site_name'] = $parent_scope->site_name();

		return $identity;
	}

	/**
	 * Opportunistically re-enter registration from update checks.
	 */
	private function maybe_attempt_opportunistic_registration(): void {
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		if ( ! $this->scope->can_run_network_task() || empty( $this->server ) || $this->get_site_key() || $this->scope->get_transient( $this->challenge_transient ) ) {
			return;
		}

		if ( $this->registration_is_cooling_down() ) {
			return;
		}

		$this->scope->update_option( $this->opportunistic_registration_option, time() );
		$this->attempt_registration();
	}

	/**
	 * Whether registration recovery has already run in the last 24 hours.
	 */
	private function registration_is_cooling_down(): bool {
		$last_attempt = (int) $this->scope->get_option( $this->opportunistic_registration_option, 0 );
		return $last_attempt > 0 && ( time() - $last_attempt ) < DAY_IN_SECONDS;
	}

	/**
	 * Append download auth query args.
	 */
	private function add_download_auth_args( string $download_url, string $site_key, string $site_url = '' ): string {
		if ( '' === $download_url || '' === $site_key ) {
			return $download_url;
		}

		$download_url = add_query_arg( 'key', $site_key, $download_url );

		// site_url is auth identity for domain-locked keys, not telemetry. A
		// parent-licensed add-on must use the parent's scoped identity along with
		// the parent's credential.
		if ( '' === $site_url ) {
			$site_url = $this->request_identity()['site_url'];
		}
		return add_query_arg( 'site_url', $site_url, $download_url );
	}

	/**
	 * Normalize and validate the add-on 'parent' registration config.
	 *
	 * The parent's location comes exclusively from this explicit registration —
	 * filesystem paths are never inferred from untrusted manifest slugs.
	 *
	 * @param mixed $raw Raw 'parent' config value.
	 * @return array|null Normalized [ file, slug, api_major ] or null when invalid.
	 */
	private function normalize_parent_config( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$file = $raw['file'] ?? null;
		if ( ! is_string( $file ) || strlen( $file ) > 191 || false !== strpos( $file, '..' )
			|| ! preg_match( '#^(?:[A-Za-z0-9._\- ]+/)?[A-Za-z0-9._\- ]+\.php$#', $file ) ) {
			return null;
		}

		$slug = $raw['slug'] ?? null;
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9._\-]{0,190}$/', $slug ) ) {
			return null;
		}

		$api_major = $raw['api_major'] ?? null;
		if ( null !== $api_major && ! is_int( $api_major ) && ! is_callable( $api_major ) ) {
			return null;
		}

		$min_version = $raw['min_version'] ?? null;
		$max_version = $raw['max_version_exclusive'] ?? null;
		if ( null !== $min_version && ( ! is_string( $min_version ) || ! $this->is_valid_version_string( $min_version ) ) ) {
			return null;
		}
		if ( null !== $max_version && ( ! is_string( $max_version ) || ! $this->is_valid_version_string( $max_version )
			|| ( null !== $min_version && version_compare( $min_version, $max_version, '>=' ) ) ) ) {
			return null;
		}

		return [
			'file'                  => $file,
			'slug'                  => $slug,
			'api_major'             => $api_major,
			'min_version'           => $min_version,
			'max_version_exclusive' => $max_version,
		];
	}

	/**
	 * Whether this updater was registered with add-on semantics (valid or not).
	 */
	public function is_addon_registration(): bool {
		return null !== $this->parent_config || $this->addon_registration_invalid;
	}

	/**
	 * Bounded version-string check; version_compare() handles ordering.
	 */
	private function is_valid_version_string( string $version ): bool {
		return 1 === preg_match( '/^[0-9][0-9A-Za-z.\-+]{0,63}$/', $version );
	}

	/**
	 * Validate one manifest parent-constraint object.
	 *
	 * @param mixed $parent Decoded manifest value.
	 * @return array|null Normalized constraints, or null when malformed.
	 */
	private function validate_manifest_parent_object( $parent ): ?array {
		if ( ! is_object( $parent ) ) {
			return null;
		}

		$slug = $parent->slug ?? null;
		if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9._\-]{0,190}$/', $slug ) ) {
			return null;
		}

		// Update Machine omits min_version when the range is unbounded below, so
		// absent/null reads as "no lower bound". When present it must be a valid
		// version string.
		$min = property_exists( $parent, 'min_version' ) ? $parent->min_version : null;
		if ( null !== $min && ( ! is_string( $min ) || ! $this->is_valid_version_string( $min ) ) ) {
			return null;
		}

		// The exclusive maximum may be null (or absent) to declare no upper
		// bound. Anything else must be a valid version string, strictly above
		// the minimum when both bounds are declared.
		$max = property_exists( $parent, 'max_version_exclusive' ) ? $parent->max_version_exclusive : null;
		if ( null !== $max ) {
			if ( ! is_string( $max ) || ! $this->is_valid_version_string( $max )
				|| ( null !== $min && version_compare( $min, $max, '>=' ) ) ) {
				return null;
			}
		}

		// API major is an explicit integer when declared.
		$api_major = property_exists( $parent, 'api_major' ) ? $parent->api_major : null;
		if ( null !== $api_major && ! is_int( $api_major ) ) {
			return null;
		}

		return [
			'slug'                  => $slug,
			'min_version'           => $min,
			'max_version_exclusive' => $max,
			'api_major'             => $api_major,
		];
	}

	/**
	 * Classify a manifest's package metadata.
	 *
	 * Canonical server shape (Update Machine v2): top-level
	 * `package_type: "addon"` plus `parent: { slug, min_version,
	 * max_version_exclusive, api_major }`. A transitional alternate shape,
	 * `package: { slug, type: "addon", parent: {...} }`, is accepted through
	 * the 4.8.x line only and must agree with the canonical shape when both
	 * appear. Unknown explicit package types and malformed structures fail
	 * closed; manifests without either declaration are ordinary core
	 * manifests and behave exactly as before.
	 *
	 * @return array { 'type' => 'core'|'addon'|'invalid', 'parent' => ?array }
	 */
	private function extract_addon_manifest( object $manifest ): array {
		$invalid = [ 'type' => 'invalid', 'parent' => null ];

		$canonical = null;
		if ( property_exists( $manifest, 'package_type' ) ) {
			if ( 'addon' !== $manifest->package_type ) {
				return $invalid;
			}
			// The manifest belongs to this installed add-on, not merely to a
			// compatible parent. Without this binding, a cached response for one
			// add-on could be used to gate and authorize another add-on package.
			if ( ! property_exists( $manifest, 'slug' ) || ! is_string( $manifest->slug ) || $manifest->slug !== $this->slug ) {
				return $invalid;
			}
			$canonical = $this->validate_manifest_parent_object(
				property_exists( $manifest, 'parent' ) ? $manifest->parent : null
			);
			if ( null === $canonical ) {
				return $invalid;
			}
		}

		$alternate = null;
		if ( property_exists( $manifest, 'package' ) && is_object( $manifest->package )
			&& property_exists( $manifest->package, 'type' ) ) {
			$package = $manifest->package;
			if ( 'addon' !== $package->type ) {
				return $invalid;
			}
			if ( ! property_exists( $package, 'slug' )
				|| ! is_string( $package->slug ) || $package->slug !== $this->slug ) {
				return $invalid;
			}
			$alternate = $this->validate_manifest_parent_object(
				property_exists( $package, 'parent' ) ? $package->parent : null
			);
			if ( null === $alternate ) {
				return $invalid;
			}
		}

		if ( null !== $canonical && null !== $alternate && $canonical !== $alternate ) {
			return $invalid;
		}

		$parent = null !== $canonical ? $canonical : $alternate;
		if ( null === $parent ) {
			return [ 'type' => 'core', 'parent' => null ];
		}

		return [ 'type' => 'addon', 'parent' => $parent ];
	}

	/**
	 * Absolute path to the registered parent plugin's main file.
	 */
	private function parent_plugin_path(): string {
		if ( null === $this->parent_config ) {
			return '';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$dir = WP_PLUGIN_DIR;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = WP_CONTENT_DIR . '/plugins';
		} else {
			return '';
		}

		return rtrim( (string) $dir, '/\\' ) . '/' . $this->parent_config['file'];
	}

	/**
	 * Whether the parent is active in the context this add-on updates from.
	 *
	 * A network-active add-on updates code shared by every site, so its parent
	 * must be network-active — activation on a single subsite is not enough.
	 * A site-active add-on (or any single-site install) may rely on either a
	 * network-active parent or the current site's own activation.
	 */
	private function is_parent_active_in_context(): bool {
		$parent_basename = $this->parent_config['file'];

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( isset( $network_active[ $parent_basename ] ) ) {
				return true;
			}
			if ( $this->scope->is_network() ) {
				return false;
			}
		}

		return in_array( $parent_basename, (array) get_option( 'active_plugins', [] ), true );
	}

	/**
	 * Resolve the installed parent's runtime API major from the registration.
	 *
	 * Callables run lazily (all plugins are loaded by gate time) and any
	 * failure or non-integer result reads as "unknown", which fails closed
	 * when the manifest declares a required API major.
	 */
	private function resolve_parent_api_major(): ?int {
		$api = null !== $this->parent_config ? $this->parent_config['api_major'] : null;
		if ( is_int( $api ) ) {
			return $api;
		}
		if ( is_callable( $api ) ) {
			try {
				$value = call_user_func( $api );
			} catch ( \Throwable $e ) {
				return null;
			}
			return is_int( $value ) ? $value : null;
		}

		return null;
	}

	/**
	 * Decide whether an update from this manifest may be offered/installed.
	 *
	 * Gating always uses the actual installed plugin state — never server
	 * telemetry — because WordPress update orchestration is not atomic.
	 * Healthy installed code is never deactivated; an incompatible update is
	 * simply withheld with a reason.
	 *
	 * @return array|null Null when the update may proceed, otherwise
	 *                    [ 'code' => string, 'message' => string ].
	 */
	private function evaluate_addon_gate( object $manifest ): ?array {
		$meta = $this->extract_addon_manifest( $manifest );

		if ( 'invalid' === $meta['type'] ) {
			return [
				'code'    => 'manifest_malformed',
				'message' => __( 'its update metadata is malformed. Contact the plugin author.', 'um-updater' ),
			];
		}

		if ( $this->addon_registration_invalid ) {
			return [
				'code'    => 'registration_invalid',
				'message' => __( 'its add-on parent registration is invalid. Contact the plugin author.', 'um-updater' ),
			];
		}

		if ( 'core' === $meta['type'] ) {
			if ( null !== $this->parent_config ) {
				return [
					'code'    => 'manifest_malformed',
					'message' => __( 'this add-on update did not declare its required parent plugin. Contact the plugin author.', 'um-updater' ),
				];
			}
			return null; // Ordinary plugin, ordinary manifest — unchanged behavior.
		}

		if ( null === $this->parent_config ) {
			return [
				'code'    => 'registration_missing',
				'message' => __( 'the update is published as an add-on but this plugin did not register a parent plugin. Contact the plugin author.', 'um-updater' ),
			];
		}

		$required = $meta['parent'];
		if ( $required['slug'] !== $this->parent_config['slug'] ) {
			return [
				'code'    => 'manifest_malformed',
				'message' => __( 'the update declares a different parent plugin than this plugin registered. Contact the plugin author.', 'um-updater' ),
			];
		}

		$parent_slug = $required['slug'];
		$parent_path = $this->parent_plugin_path();
		if ( '' === $parent_path || ! is_file( $parent_path ) || ! is_readable( $parent_path ) ) {
			return [
				'code'    => 'parent_missing',
				/* translators: %s: parent plugin slug. */
				'message' => sprintf( __( 'it requires the "%s" plugin, which is not installed.', 'um-updater' ), $parent_slug ),
			];
		}

		$parent_data    = get_file_data( $parent_path, [ 'Version' => 'Version' ] );
		$parent_version = trim( (string) ( $parent_data['Version'] ?? '' ) );
		if ( '' === $parent_version ) {
			return [
				'code'    => 'parent_missing',
				/* translators: %s: parent plugin slug. */
				'message' => sprintf( __( 'the installed "%s" plugin version could not be determined.', 'um-updater' ), $parent_slug ),
			];
		}

		if ( ! $this->is_parent_active_in_context() ) {
			return [
				'code'    => 'parent_inactive',
				'message' => $this->scope->is_network()
					/* translators: %s: parent plugin slug. */
					? sprintf( __( 'its parent plugin "%s" must be network-activated before this network-active add-on can update.', 'um-updater' ), $parent_slug )
					/* translators: %s: parent plugin slug. */
					: sprintf( __( 'its parent plugin "%s" is installed but not active here.', 'um-updater' ), $parent_slug ),
			];
		}

		if ( null !== $required['min_version']
			&& version_compare( $parent_version, $required['min_version'], '<' ) ) {
			return [
				'code'    => 'parent_too_old',
				/* translators: 1: parent plugin slug, 2: required minimum version, 3: installed version. */
				'message' => sprintf( __( 'it requires "%1$s" %2$s or newer (installed: %3$s). Update "%1$s" first.', 'um-updater' ), $parent_slug, $required['min_version'], $parent_version ),
			];
		}

		if ( null !== $required['max_version_exclusive']
			&& version_compare( $parent_version, $required['max_version_exclusive'], '>=' ) ) {
			return [
				'code'    => 'parent_too_new',
				/* translators: 1: parent plugin slug, 2: exclusive maximum version, 3: installed version. */
				'message' => sprintf( __( 'it supports "%1$s" versions below %2$s (installed: %3$s).', 'um-updater' ), $parent_slug, $required['max_version_exclusive'], $parent_version ),
			];
		}

		if ( null !== $required['api_major'] ) {
			$installed_api = $this->resolve_parent_api_major();
			if ( null === $installed_api ) {
				return [
					'code'    => 'parent_api_unknown',
					/* translators: 1: parent plugin slug, 2: required API major. */
					'message' => sprintf( __( 'it requires "%1$s" API v%2$d, but the installed "%1$s" does not report an API version. Update "%1$s" first.', 'um-updater' ), $parent_slug, $required['api_major'] ),
				];
			}
			if ( $installed_api !== $required['api_major'] ) {
				return [
					'code'    => 'parent_api_mismatch',
					/* translators: 1: parent plugin slug, 2: required API major, 3: installed API major. */
					'message' => sprintf( __( 'it requires "%1$s" API v%2$d (installed: API v%3$d).', 'um-updater' ), $parent_slug, $required['api_major'], $installed_api ),
				];
			}
		}

		return null;
	}

	/**
	 * Explain a withheld add-on update to admins who can act on it.
	 *
	 * Reads only the cached manifest — never triggers an HTTP request — and
	 * renders nothing when there is no newer version or the gate passes.
	 */
	public function maybe_notice_withheld_addon_update(): void {
		if ( ! $this->is_addon_registration() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$cached = $this->scope->get_transient( $this->cache_key );
		if ( ! is_object( $cached ) || empty( $cached->version ) ) {
			return;
		}

		$plugin_data     = get_file_data( $this->file, [ 'Version' => 'Version' ] );
		$current_version = (string) ( $plugin_data['Version'] ?? '' );
		if ( '' === $current_version || ! version_compare( (string) $cached->version, $current_version, '>' ) ) {
			return;
		}

		$gate = $this->evaluate_addon_gate( $cached );
		if ( null === $gate ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: plugin slug, 2: available version, 3: reason the update is withheld. */
				__( 'Update Machine: %1$s %2$s is available but is not being offered because %3$s', 'um-updater' ),
				$this->slug,
				(string) $cached->version,
				$gate['message']
			) )
		);
	}

	/**
	 * Check for updates and inject into the update transient.
	 */
	public function check_update( object $transient ): object {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$this->maybe_attempt_opportunistic_registration();

		$remote = $this->fetch_update_data();

		if ( ! $remote ) {
			return $transient;
		}

		// Add-on parent compatibility gate: never advertise an update the
		// installed parent cannot support (see docs/addon-packages.md). The
		// admin notice hooked in init() explains why the update is withheld.
		if ( null !== $this->evaluate_addon_gate( $remote ) ) {
			if ( isset( $transient->response[ $this->basename ] ) ) {
				unset( $transient->response[ $this->basename ] );
			}
			return $transient;
		}

		$current_version = $transient->checked[ $this->basename ] ?? '0.0.0';

		// Validate download URL origin, then append key if we have one.
		$download_url = $this->validate_download_url( $remote->download_url ?? '' );
		$identity     = $this->request_identity();
		$site_key     = $identity['site_key'];
		if ( $download_url && $site_key ) {
			$download_url = $this->add_download_auth_args( $download_url, $site_key, $identity['site_url'] );
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
		$identity     = $this->request_identity();
		$site_key     = $identity['site_key'];
		if ( $download_url && $site_key ) {
			$download_url = $this->add_download_auth_args( $download_url, $site_key, $identity['site_url'] );
		}

		// Withhold the download link (but keep the details view) while the
		// add-on parent compatibility gate fails.
		if ( null !== $this->evaluate_addon_gate( $remote ) ) {
			$download_url = '';
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
	 * Guard uploaded/manual installs that would overwrite this add-on.
	 *
	 * WordPress's uploaded-plugin overwrite flow never passes
	 * $hook_extra['plugin'], so verify_download() cannot see it. This filter
	 * inspects the extracted source directory instead: when the incoming
	 * plugin would land in this add-on's directory, reject it unless it is the
	 * native updater flow that already passed verify_download() (parent gate,
	 * manifest/package binding, and ZIP SHA-256). The source-selection hook has
	 * no trustworthy handle for the original uploaded ZIP, so comparing only an
	 * extracted Version header would let an attacker replace the package bytes
	 * while retaining the published version number. Unrelated plugins and
	 * ordinary (non-add-on) registrations are never touched, and blocking an
	 * install never modifies the code already on disk.
	 *
	 * @param string|\WP_Error $source        Extracted source directory.
	 * @param string           $remote_source Remote package path.
	 * @param object|null      $upgrader      Upgrader instance.
	 * @param array|null       $hook_extra    Extra data; uploads omit 'plugin'.
	 * @return string|\WP_Error
	 */
	public function guard_source_selection( $source, $remote_source = '', $upgrader = null, $hook_extra = null ) {
		if ( ! $this->is_addon_registration() || ! is_string( $source ) || '' === $source ) {
			return $source;
		}

		$our_dir = dirname( $this->basename );
		if ( '.' === $our_dir || basename( rtrim( $source, '/\\' ) ) !== $our_dir ) {
			return $source; // Unrelated install.
		}

		// The native update flow identifies the plugin and already ran
		// verify_download() (gate, package binding, and hash) before any
		// files were fetched — do not re-gate it here.
		if ( is_array( $hook_extra ) && ( $hook_extra['plugin'] ?? '' ) === $this->basename ) {
			return $source;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "um-updater [{$this->slug}]: Manual add-on overwrite blocked because its ZIP cannot be bound to the verified manifest hash." );
		return new \WP_Error(
			'um_addon_manual_install_blocked',
			__( 'Installation blocked: manually uploaded add-on packages cannot be verified. Install add-on updates through the WordPress updater instead.', 'um-updater' )
		);
	}

	/**
	 * Bind an add-on download to the cached manifest, or explain the refusal.
	 *
	 * The requested package must be exactly the manifest's origin-validated
	 * download URL (plus this install's own auth args), and add-on packages
	 * must always carry a valid SHA-256 — the hash pins the exact bytes the
	 * parent-compatibility gate evaluated.
	 */
	private function addon_package_binding_error( object $cached, string $package ): ?\WP_Error {
		$expected = $this->validate_download_url( (string) ( $cached->download_url ?? '' ) );
		if ( '' === $expected ) {
			return new \WP_Error(
				'um_addon_package_unbound',
				__( 'Update blocked: the add-on update manifest does not provide a valid download URL for this package.', 'um-updater' )
			);
		}

		$allowed  = [ $expected ];
		$identity = $this->request_identity();
		$site_key = $identity['site_key'];
		if ( '' !== $site_key ) {
			$allowed[] = $this->add_download_auth_args( $expected, $site_key, $identity['site_url'] );
		}
		if ( ! in_array( $package, $allowed, true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Requested add-on package does not match the manifest download URL — refusing update." );
			return new \WP_Error(
				'um_addon_package_mismatch',
				__( 'Update blocked: the requested package does not match the add-on update manifest. Refresh available updates and try again.', 'um-updater' )
			);
		}

		if ( ! isset( $cached->sha256 ) || '' === $this->normalize_sha256( $cached->sha256 ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Add-on manifest omits a valid sha256 — refusing update." );
			return new \WP_Error(
				'um_addon_sha256_required',
				__( 'Update blocked: add-on packages require a SHA-256 integrity hash in the update manifest. Please contact the plugin author.', 'um-updater' )
			);
		}

		return null;
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

		$cached = $this->scope->get_transient( $this->cache_key );

		$hash_expected = (bool) $this->scope->get_option( $this->hash_expected_option, false );

		// WordPress can retain its update offer longer than our manifest cache.
		// Refresh an expired cache before deciding whether the hash disappeared.
		if ( false === $cached ) {
			$cached        = $this->fetch_update_data();
			$hash_expected = (bool) $this->scope->get_option( $this->hash_expected_option, false );
		}

		// Add-on parent compatibility gate: manual upgrader flows and stale
		// native update transients cannot bypass the check. Scoped to this
		// plugin's package only — the basename match above already excluded
		// every unrelated plugin update.
		if ( $this->is_addon_registration() && ! is_object( $cached ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Update manifest unavailable while confirming add-on parent compatibility — refusing update." );
			return new \WP_Error(
				'um_addon_manifest_unavailable',
				__( 'Update blocked: the update manifest could not be retrieved to confirm add-on compatibility. Please try again.', 'um-updater' )
			);
		}

		if ( is_object( $cached ) ) {
			$gate = $this->evaluate_addon_gate( $cached );
			if ( null !== $gate ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Add-on update blocked ({$gate['code']})." );
				return new \WP_Error(
					'um_addon_' . $gate['code'],
					sprintf(
						/* translators: %s: reason the update is blocked. */
						__( 'Update blocked: %s', 'um-updater' ),
						$gate['message']
					)
				);
			}

			// Add-on downloads are bound to the manifest that passed the gate:
			// exact package URL and a mandatory SHA-256. A stale offer or a
			// crafted same-host package URL cannot ride an earlier gate pass.
			if ( $this->is_addon_registration() ) {
				$binding_error = $this->addon_package_binding_error( $cached, $package );
				if ( null !== $binding_error ) {
					return $binding_error;
				}
			}
		}

		if ( ! is_object( $cached ) ) {
			if ( $hash_expected ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Update manifest unavailable while confirming SHA-256 integrity — refusing update." );
				return new \WP_Error(
					'um_manifest_unavailable',
					__( 'Update blocked: the update manifest could not be retrieved to confirm package integrity. Please try again.', 'um-updater' )
				);
			}

			return $reply;
		}

		// Preserve compatibility for plugins that have never shipped hashes, but
		// fail closed once this install has observed a valid manifest hash.
		if ( ! isset( $cached->sha256 ) ) {
			if ( $hash_expected ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Update manifest omits sha256 after hashes were previously observed — refusing update." );
				return new \WP_Error(
					'um_sha256_missing',
					__( 'Update blocked: expected an integrity hash but the update manifest did not provide one. Please contact the plugin author.', 'um-updater' )
				);
			}

			if ( $cached ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( "um-updater [{$this->slug}]: Update manifest missing sha256 field — skipping integrity check." );
			}
			return $reply;
		}

		$expected_hash = $this->normalize_sha256( $cached->sha256 );
		if ( '' === $expected_hash ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Update manifest contains an invalid sha256 value — refusing update." );
			return new \WP_Error(
				'um_sha256_invalid',
				__( 'Update blocked: the update manifest contains an invalid integrity hash. Please contact the plugin author.', 'um-updater' )
			);
		}

		if ( ! $hash_expected ) {
			$this->scope->update_option( $this->hash_expected_option, 1 );
		}

		// download_url() accepts no request arguments, so pin TLS verification
		// with a narrowly scoped filter and always remove it after the request.
		$force_sslverify = static function ( $args ) {
			if ( is_array( $args ) ) {
				$args['sslverify'] = true;
			}
			return $args;
		};
		add_filter( 'http_request_args', $force_sslverify, PHP_INT_MAX );
		try {
			$tmp = download_url( $package );
		} finally {
			remove_filter( 'http_request_args', $force_sslverify, PHP_INT_MAX );
		}

		if ( is_wp_error( $tmp ) ) {
			$this->maybe_self_heal_domain_locked_key( $tmp );
			return $tmp;
		}

		$this->scope->delete_option( $this->download_403_option );

		// Compute and compare SHA-256.
		$actual = @hash_file( 'sha256', $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_string( $actual ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: Downloaded ZIP could not be read for SHA-256 verification — refusing update." );
			return new \WP_Error(
				'um_sha256_unreadable',
				__( 'Update blocked: the downloaded ZIP could not be verified. Please try again or contact the plugin author.', 'um-updater' )
			);
		}

		if ( ! hash_equals( $expected_hash, $actual ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "um-updater [{$this->slug}]: SHA-256 mismatch — expected {$expected_hash}, got {$actual}. Update blocked." );
			return new \WP_Error(
				'um_sha256_mismatch',
				__( 'Update blocked: ZIP integrity check failed. Please contact the plugin author.', 'um-updater' )
			);
		}

		return $tmp;
	}

	/**
	 * Normalize a manifest SHA-256 value, or return an empty string if invalid.
	 *
	 * @param mixed $value Remote manifest value.
	 */
	private function normalize_sha256( $value ): string {
		$hash = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{64}$/', $hash ) ? $hash : '';
	}

	/**
	 * After three 403s inside a short window, assume a cloned domain-locked key
	 * and re-register through the normal 24-hour recovery cooldown.
	 */
	private function maybe_self_heal_domain_locked_key( $error ): void {
		// A parent-license failure must never delete/replace the parent's key;
		// rotation and revocation belong to the parent license lifecycle.
		if ( $this->is_addon_registration() && 'package_key' !== $this->addon_auth_mode ) {
			return;
		}

		if ( ! $this->is_forbidden_download_error( $error ) || ! $this->get_site_key() ) {
			return;
		}

		$now   = time();
		$state = $this->scope->get_option( $this->download_403_option, [] );
		if ( ! is_array( $state ) || empty( $state['started_at'] ) || ( $now - (int) $state['started_at'] ) >= self::DOWNLOAD_403_WINDOW ) {
			$state = [
				'count'      => 0,
				'started_at' => $now,
			];
		}

		$state['count'] = (int) ( $state['count'] ?? 0 ) + 1;
		if ( $state['count'] < 3 ) {
			$this->scope->update_option( $this->download_403_option, $state );
			return;
		}

		$this->scope->delete_option( $this->download_403_option );
		$this->scope->delete_option( $this->key_option );
		$this->scope->delete_transient( $this->cache_key );
		$this->maybe_attempt_opportunistic_registration();
	}

	/**
	 * Detect 403 download failures from common WP_Error shapes.
	 */
	private function is_forbidden_download_error( $error ): bool {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}

		if ( method_exists( $error, 'get_error_code' ) && false !== strpos( (string) $error->get_error_code(), '403' ) ) {
			return true;
		}

		$data = method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
		if ( is_array( $data ) ) {
			$code = $data['response']['code'] ?? $data['code'] ?? $data['status'] ?? null;
			return 403 === (int) $code;
		}

		return 403 === (int) $data;
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
			$this->scope->delete_transient( $this->cache_key );
		}

		$cached = $this->scope->get_transient( $this->cache_key );

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
		 * When true, the update check sends an empty telemetry body. Auth
		 * identity still goes out when needed: site keys, license headers,
		 * and site_url for domain-locked keyed downloads.
		 *
		 * @param bool   $disabled Default false.
		 * @param string $slug     Plugin slug being checked.
		 */
		$telemetry_disabled = (bool) apply_filters( 'um_updater_disable_telemetry', false, $this->slug );

		$request_identity   = $this->request_identity();
		$telemetry          = $this->filter_base_telemetry( [
			'site_url'         => $request_identity['site_url'],
			'site_name'        => $request_identity['site_name'],
			'plugin_version'   => $current_version,
			'sdk_version'      => self::SDK_VERSION,
			'php_version'      => PHP_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
			'is_multisite'     => function_exists( 'is_multisite' ) && is_multisite(),
			'activation_scope' => $this->scope->is_network() ? 'network' : 'site',
		] );
		$filter_failed      = null === $telemetry;
		$telemetry          = $telemetry ?? [];

		if ( ! $telemetry_disabled && ! $filter_failed ) {
			$usage = $this->collect_usage();
			if ( null !== $usage ) {
				$telemetry['usage'] = $usage;
			}

			$features = $this->collect_features();
			if ( null !== $features ) {
				$telemetry['features'] = $features;
			}
		}

		// Build request headers, including auth key if available.
		$request_headers = [
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		];

		$site_key = $request_identity['site_key'];
		if ( $site_key ) {
			$request_headers['X-Update-Key'] = $site_key;
			$request_headers['X-Site-URL']   = $request_identity['site_url'];
		}

		// Include license credentials when a license client is wired up.
		if ( null !== $this->license_client ) {
			$license_key = $this->license_client->decrypt_key();
			if ( '' !== $license_key ) {
				$request_headers['X-License-Key'] = $license_key;
				$request_headers['X-Site-URL']    = $request_identity['site_url'];
			}
		}

		$get_headers = [ 'Accept' => 'application/json' ];
		if ( $site_key ) {
			$get_headers['X-Update-Key'] = $site_key;
			$get_headers['X-Site-URL']   = $request_identity['site_url'];
		}
		if ( null !== $this->license_client ) {
			$license_key = $this->license_client->decrypt_key();
			if ( '' !== $license_key ) {
				$get_headers['X-License-Key'] = $license_key;
				$get_headers['X-Site-URL']    = $request_identity['site_url'];
			}
		}

		// POST (server responds with update.json content). All telemetry fields
		// are optional server-side, so a disabled payload is just "{}" — the
		// POST itself must stay because license-gated responses (download
		// tokens, warnings) only come back on this path.
		$response = wp_remote_post( $this->update_url, [
			'timeout'   => 10,
			'sslverify' => true,
			'headers'   => $request_headers,
			'body'      => wp_json_encode( ( $telemetry_disabled || $filter_failed ) ? (object) [] : $telemetry ),
		] );

		// Fallback to GET if POST fails (e.g. server doesn't support POST yet).
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$response = wp_remote_get( $this->update_url, [
				'timeout'   => 10,
				'sslverify' => true,
				'headers'   => $get_headers,
			] );
		}

		if ( is_wp_error( $response ) ) {
			$this->scope->set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$this->scope->set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );

		if ( ! $data || empty( $data->version ) ) {
			$this->scope->set_transient( $this->cache_key, 'error', self::ERROR_TTL );
			return null;
		}

		// Record valid hash support as soon as it is observed. Waiting until an
		// install begins would leave a downgrade window between update checks.
		if ( isset( $data->sha256 ) && '' !== $this->normalize_sha256( $data->sha256 ) ) {
			$this->scope->update_option( $this->hash_expected_option, 1 );
		}

		// Forward server-side warnings to the license client (e.g. "payment past due").
		if ( null !== $this->license_client && isset( $data->warning ) ) {
			$this->license_client->store_update_warning( $data->warning );
		}

		$this->scope->set_transient( $this->cache_key, $data, self::CACHE_TTL );

		return $data;
	}
}
} // end class_exists guard

/**
 * Per-plugin optional telemetry policy, scoped preference, and settings control.
 */
if ( ! class_exists( __NAMESPACE__ . '\\Telemetry_Preference' ) ) {
class Telemetry_Preference {

	public const MODE_OPT_OUT = 'opt_out';
	public const MODE_OPT_IN  = 'opt_in';
	public const MODE_DISABLED = 'disabled';

	private string $slug;
	private string $option;
	private string $legacy_option;
	private string $mode;
	private string $privacy_url;
	private string $data_description;
	private Storage_Scope $scope;
	private $disabled_callback = null;

	public function __construct( string $slug, Storage_Scope $scope, array $config = [] ) {
		$this->slug             = $slug;
		$this->option           = 'um_telemetry_consent_' . $slug;
		$this->legacy_option    = 'um_telemetry_optout_' . $slug;
		$this->scope            = $scope;
		$this->mode             = $this->sanitize_mode( $config['mode'] ?? self::MODE_OPT_OUT );
		$this->privacy_url      = is_string( $config['privacy_url'] ?? null ) ? $config['privacy_url'] : '';
		$this->data_description = is_string( $config['data_description'] ?? null ) ? $config['data_description'] : '';
	}

	/**
	 * Hook preference enforcement and settings-form saves.
	 */
	public function register_hooks(): void {
		add_filter( 'um_updater_disable_telemetry', [ $this, 'filter_disabled' ], 10, 2 );
		add_action( 'admin_init', [ $this, 'maybe_save' ] );
	}

	public function mode(): string {
		return $this->mode;
	}

	public function is_network(): bool {
		return $this->scope->is_network();
	}

	public function required_capability(): string {
		return $this->is_network() ? 'manage_network_options' : 'manage_options';
	}

	public function field_name(): string {
		return $this->option;
	}

	/**
	 * Whether optional telemetry is enabled under the configured policy.
	 */
	public function is_enabled(): bool {
		if ( self::MODE_DISABLED === $this->mode ) {
			return false;
		}

		$missing = new \stdClass();
		$stored  = $this->scope->get_option( $this->option, $missing );
		if ( $missing !== $stored ) {
			return 'enabled' === $stored;
		}

		$legacy = $this->scope->get_option( $this->legacy_option, $missing );
		if ( $missing !== $legacy ) {
			return ! (bool) $legacy;
		}

		return self::MODE_OPT_OUT === $this->mode;
	}

	/**
	 * Register the scoped activity cleanup invoked when sharing is disabled.
	 */
	public function set_disabled_callback( callable $callback ): void {
		$this->disabled_callback = $callback;
	}

	/**
	 * Persist a positive sharing preference and mirror the legacy opt-out bit.
	 */
	public function set_enabled( bool $enabled ): void {
		$enabled = self::MODE_DISABLED === $this->mode ? false : $enabled;
		$this->scope->update_option( $this->option, $enabled ? 'enabled' : 'disabled' );
		$this->scope->update_option( $this->legacy_option, $enabled ? 0 : 1 );
		$this->scope->delete_transient( 'um_update_' . $this->slug );
		if ( ! $enabled && is_callable( $this->disabled_callback ) ) {
			try {
				call_user_func( $this->disabled_callback );
			} catch ( \Throwable $e ) {
				// Telemetry cleanup must never block the preference change.
			}
		}
	}

	/**
	 * Compatibility API retained for host plugins using SDK 4.2 through 4.5.
	 */
	public function is_opted_out(): bool {
		return ! $this->is_enabled();
	}

	/**
	 * Compatibility API retained for host plugins using SDK 4.2 through 4.5.
	 */
	public function set_opted_out( bool $opted_out ): void {
		$this->set_enabled( ! $opted_out );
	}

	/**
	 * Feed the preference into the existing telemetry-disable filter.
	 *
	 * @param bool   $disabled Current filter value.
	 * @param string $slug     Plugin slug being checked.
	 */
	public function filter_disabled( bool $disabled, string $slug ): bool {
		return $disabled || ( $slug === $this->slug && ! $this->is_enabled() );
	}

	/**
	 * Render the positive sharing checkbox used by settings and onboarding.
	 */
	public function render_control( bool $include_nonce = true ): void {
		if ( self::MODE_DISABLED === $this->mode ) {
			echo '<p class="description">' . esc_html__( 'Optional update telemetry is disabled for this plugin.', 'um-updater' ) . '</p>';
			return;
		}

		if ( $include_nonce ) {
			wp_nonce_field( 'um_telemetry_preference_' . $this->slug, '_um_telemetry_nonce_' . $this->slug );
		}
		$description = '' !== $this->data_description
			? $this->data_description
			: __( 'Share this site\'s URL and name, plugin and server-environment versions, multisite scope, and bounded plugin feature settings with Update Machine. No post content, user data, license keys, site keys, or free-form text is included. Updates keep working if sharing is disabled.', 'um-updater' );
		?>
		<fieldset class="um-telemetry-preference">
			<label for="<?php echo esc_attr( $this->option ); ?>">
				<input type="checkbox"
					id="<?php echo esc_attr( $this->option ); ?>"
					name="<?php echo esc_attr( $this->option ); ?>"
					value="1"
					<?php checked( $this->is_enabled() ); ?> />
				<?php esc_html_e( 'Share optional update and feature telemetry', 'um-updater' ); ?>
			</label>
			<p class="description">
				<?php echo esc_html( $description ); ?>
				<?php if ( '' !== $this->privacy_url ) : ?>
					<a href="<?php echo esc_url( $this->privacy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'um-updater' ); ?></a>
				<?php endif; ?>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Backward-compatible renderer. The UI is now positive in every mode.
	 */
	public function render_field(): void {
		$this->render_control();
	}

	/**
	 * Save a submitted positive sharing preference.
	 */
	public function maybe_save(): void {
		$nonce_key = '_um_telemetry_nonce_' . $this->slug;
		if ( ! isset( $_POST[ $nonce_key ] ) || self::MODE_DISABLED === $this->mode ) {
			return;
		}
		if ( ! current_user_can( $this->required_capability() ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ $nonce_key ] ) );
		if ( ! wp_verify_nonce( $nonce, 'um_telemetry_preference_' . $this->slug ) ) {
			return;
		}

		$enabled = ! empty( $_POST[ $this->option ] );
		if ( $enabled !== $this->is_enabled() ) {
			$this->set_enabled( $enabled );
		}
	}

	private function sanitize_mode( $mode ): string {
		return in_array( $mode, [ self::MODE_OPT_OUT, self::MODE_OPT_IN, self::MODE_DISABLED ], true )
			? $mode
			: self::MODE_OPT_OUT;
	}
}
} // end class_exists guard

/**
 * Legacy class name retained for plugins calling telemetry_opt_out().
 */
if ( ! class_exists( __NAMESPACE__ . '\\Telemetry_Opt_Out' ) ) {
class Telemetry_Opt_Out extends Telemetry_Preference {
}
} // end class_exists guard
