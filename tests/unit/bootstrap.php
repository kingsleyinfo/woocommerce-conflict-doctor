<?php
/**
 * PHPUnit bootstrap for WCD unit tests.
 *
 * Uses brain/monkey to stub WordPress functions so tests run in < 5 seconds
 * without a full WP stack. Only the pure-logic classes are loaded here.
 * Session/MU-plugin integration tests run in wp-env (see tests/e2e/).
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Minimum WordPress constants needed by the files under test.
defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wp-test/' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 31536000 );
defined( 'COOKIEPATH' ) || define( 'COOKIEPATH', '/' );
defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', '' );

require_once dirname( __DIR__, 2 ) . '/includes/wcd-constants.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-troubleshoot-mode.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wizard.php';
