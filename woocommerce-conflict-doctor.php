<?php
/**
 * Plugin Name: WooCommerce Conflict Doctor
 * Plugin URI:  https://github.com/kingsleyinfo/woocommerce-conflict-doctor
 * Description: Automated plugin/theme conflict testing. Identifies the culprit in minutes instead of hours.
 * Version:     0.1.0
 * Author:      Kingsley Unuigbe
 * Author URI:  https://github.com/kingsleyinfo
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-conflict-doctor
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WCD_VERSION',    '0.1.0' );
define( 'WCD_PLUGIN_FILE', __FILE__ );
define( 'WCD_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WCD_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once WCD_PLUGIN_DIR . 'includes/wcd-constants.php';
require_once WCD_PLUGIN_DIR . 'includes/class-troubleshoot-mode.php';
require_once WCD_PLUGIN_DIR . 'includes/class-wizard.php';

register_deactivation_hook( __FILE__, array( 'WCD_Troubleshoot_Mode', 'handle_deactivation' ) );

add_action( 'plugins_loaded', array( 'WCD_Wizard', 'init' ) );
