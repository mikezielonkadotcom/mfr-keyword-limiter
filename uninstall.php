<?php
/**
 * Uninstall cleanup for MFR Keyword Limiter.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$updater_file = __DIR__ . '/includes/um-updater.php';

if ( ! class_exists( '\\UM\\PluginUpdater\\Updater' ) && file_exists( $updater_file ) ) {
	require_once $updater_file;
}

if ( class_exists( '\\UM\\PluginUpdater\\Updater' ) ) {
	\UM\PluginUpdater\Updater::cleanup( 'mfr-keyword-limiter' );
}
