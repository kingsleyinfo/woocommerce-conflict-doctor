<?php
// Only runs when WordPress triggers plugin uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove MU-plugin.
$wcd_mu_file = WP_CONTENT_DIR . '/mu-plugins/wcd-loader.php';
if ( file_exists( $wcd_mu_file ) ) {
	@unlink( $wcd_mu_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

// Delete all wcd_* options (sessions, config).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wcd\_%'" );
