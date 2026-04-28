<?php
defined( 'ABSPATH' ) || exit;

// AJAX action names — must match the strings registered with add_action('wp_ajax_*').
// The MU-plugin (mu-plugin/wcd-loader.php) hardcodes the cookie prefix and session prefix
// separately since it loads before this file. Both must stay in sync.
define( 'WCD_AJAX_START',      'wcd_start_test' );
define( 'WCD_AJAX_ROUND',      'wcd_round_result' );
define( 'WCD_AJAX_ABORT',      'wcd_abort_test' );
define( 'WCD_AJAX_PURGE',      'wcd_purge_cache' );
define( 'WCD_AJAX_TTL_CHECK',  'wcd_ttl_check' );

// Cookie name is site-specific to avoid cross-site collisions on shared hosting / multisite.
define( 'WCD_COOKIE_NAME',     'wcd_troubleshoot_' . substr( md5( ABSPATH ), 0, 8 ) );
define( 'WCD_SESSION_PREFIX',  'wcd_session_' );
define( 'WCD_NONCE',           'wcd_wizard' );
define( 'WCD_SESSION_TTL',     3600 ); // 1 hour in seconds

define( 'WCD_SYMPTOMS', array(
	'checkout'  => "Checkout \xe2\x80\x94 e.g., Place Order button does nothing",
	'cart'      => "Cart \xe2\x80\x94 items not adding or totals wrong",
	'admin'     => "Admin area \xe2\x80\x94 a settings screen is broken",
	'frontend'  => "Frontend display \xe2\x80\x94 layout or content is broken",
	'emails'    => "Emails \xe2\x80\x94 order confirmations not arriving",
	'products'  => "Product pages \xe2\x80\x94 can't view or add products",
	'other'     => 'Other',
) );

// Symptom → plugin slug map. Keys match WCD_SYMPTOMS. Values are plugin folder
// slugs (the segment before the slash in a WP plugin file path, e.g. 'wp-mail-smtp'
// for wp-mail-smtp/wp_mail_smtp.php). Seed list — grow from real HE tickets.
// Extensible via the wcd_symptom_suspect_map filter.
define( 'WCD_SYMPTOM_SUSPECT_MAP', array(
	'checkout' => array(
		'woocommerce-gateway-stripe',
		'woocommerce-paypal-payments',
		'woocommerce-shipping',
		'woocommerce-tax',
		'woocommerce-subscriptions',
	),
	'cart'     => array(
		'woocommerce-cart-fragments',
		'cart-all-in-one-for-woocommerce',
		'woocommerce-side-cart',
		'woocommerce-bulk-discount',
	),
	'admin'    => array(
		'admin-menu-editor',
		'adminimize',
		'user-role-editor',
		'wp-admin-ui-customize',
	),
	'frontend' => array(
		'elementor',
		'wpbakery-page-builder',
		'wp-rocket',
		'w3-total-cache',
		'autoptimize',
	),
	'emails'   => array(
		'wp-mail-smtp',
		'easy-wp-smtp',
		'fluent-smtp',
		'post-smtp',
		'mailgun',
	),
	'products' => array(
		'woocommerce-product-filter',
		'woocommerce-additional-variation-images',
		'woocommerce-variation-swatches',
		'woocommerce-bookings',
	),
	'other'    => array(),
) );

// Plugins that must never be disabled during conflict testing.
// Relative paths from wp-content/plugins/. Configurable per-install via wcd_allowlist filter.
define( 'WCD_ALLOWLIST_DEFAULTS', array(
	'woocommerce/woocommerce.php',
	'woocommerce-conflict-doctor/woocommerce-conflict-doctor.php',
	'jetpack/jetpack.php',
	'wordfence/wordfence.php',
	'really-simple-ssl/rlrsssl-really-simple-ssl.php',
	'wordfence-login-security/wordfence-login-security.php',
) );

// Known cache plugins — detected during pre-flight to offer cache purge.
define( 'WCD_CACHE_PLUGINS', array(
	'wp-rocket/wp-rocket.php'             => 'WP Rocket',
	'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
	'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
	'autoptimize/autoptimize.php'         => 'Autoptimize',
	'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
	'comet-cache/comet-cache.php'         => 'Comet Cache',
) );

// Server environment strings used to detect managed hosts that block mu-plugins writes.
define( 'WCD_MANAGED_HOST_MARKERS', array(
	'wpengine',
	'kinstahosting',
	'getflywheel',
	'pantheonsite',
	'pressable',
	'pagely',
) );
