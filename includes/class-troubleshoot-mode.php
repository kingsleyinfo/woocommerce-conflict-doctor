<?php
defined( 'ABSPATH' ) || exit;

class WCD_Troubleshoot_Mode {

	// -------------------------------------------------------------------------
	// MU-plugin lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Copies the MU-plugin source from the plugin directory to wp-content/mu-plugins/.
	 * Idempotent: no-op if the installed copy is already current.
	 *
	 * @return true|WP_Error
	 */
	public static function install_mu_plugin() {
		$mu_dir      = WP_CONTENT_DIR . '/mu-plugins/';
		$target      = $mu_dir . 'wcd-loader.php';
		$source      = WCD_PLUGIN_DIR . 'mu-plugin/wcd-loader.php';

		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		if ( ! is_writable( $mu_dir ) ) {
			return new WP_Error(
				'wcd_mu_install_failed',
				__( 'The mu-plugins directory is not writable. Contact your host.', 'woocommerce-conflict-doctor' )
			);
		}

		// Skip copy if target exists and content is identical to source.
		if ( file_exists( $target ) && md5_file( $target ) === md5_file( $source ) ) {
			return true;
		}

		if ( ! copy( $source, $target ) ) {
			return new WP_Error(
				'wcd_mu_install_failed',
				__( 'Could not copy the loader to mu-plugins/.', 'woocommerce-conflict-doctor' )
			);
		}

		return true;
	}

	public static function remove_mu_plugin() {
		$target = WP_CONTENT_DIR . '/mu-plugins/wcd-loader.php';
		if ( file_exists( $target ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	// -------------------------------------------------------------------------
	// Session CRUD
	// -------------------------------------------------------------------------

	/**
	 * Create a new troubleshoot session.
	 *
	 * @param int    $user_id  WordPress user ID of the admin starting the test.
	 * @param string $token    Device token (32-char hex, stored in browser localStorage).
	 * @param array  $disabled List of plugin file paths to disable.
	 * @param string $theme    Stylesheet slug to use as test theme, or ''.
	 * @return array The session data that was stored.
	 */
	public static function start_session( $user_id, $token, array $disabled, $theme = '' ) {
		$key  = WCD_SESSION_PREFIX . $token;
		$data = array(
			'user_id'    => (int) $user_id,
			'token'      => $token,
			'disabled'   => array_values( $disabled ),
			'theme'      => $theme,
			'expires_at' => time() + WCD_SESSION_TTL,
		);

		// autoload = false prevents session rows from loading into the WP options
		// cache on every page request, which would add ~N×50 bytes per active session.
		if ( get_option( $key ) !== false ) {
			update_option( $key, $data, false );
		} else {
			add_option( $key, $data, '', false );
		}

		self::set_cookie( $token );

		return $data;
	}

	/**
	 * Retrieve a session by token. Returns null if missing or expired.
	 *
	 * @param string $token
	 * @return array|null
	 */
	public static function get_session( $token ) {
		if ( empty( $token ) ) {
			return null;
		}

		$session = get_option( WCD_SESSION_PREFIX . $token );

		if ( ! is_array( $session ) ) {
			return null;
		}

		if ( empty( $session['expires_at'] ) || time() > (int) $session['expires_at'] ) {
			return null;
		}

		return $session;
	}

	/**
	 * Update specific keys of an existing session.
	 *
	 * @param string $token
	 * @param array  $updates Key-value pairs to merge into the session.
	 * @return array|false Updated session data, or false if session not found.
	 */
	public static function update_session( $token, array $updates ) {
		$session = self::get_session( $token );
		if ( ! $session ) {
			return false;
		}

		// Never allow overwriting token or user_id via updates.
		unset( $updates['token'], $updates['user_id'] );

		$session = array_merge( $session, $updates );
		update_option( WCD_SESSION_PREFIX . $token, $session, false );

		return $session;
	}

	/**
	 * Delete a session and clear the browser cookie.
	 *
	 * @param string $token
	 */
	public static function clear_session( $token ) {
		if ( empty( $token ) ) {
			return;
		}
		delete_option( WCD_SESSION_PREFIX . $token );
		self::clear_cookie();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the device token from the current request cookie.
	 *
	 * @return string Empty string if no valid cookie is present.
	 */
	public static function get_token_from_cookie() {
		if ( isset( $_COOKIE[ WCD_COOKIE_NAME ] ) ) {
			$token = $_COOKIE[ WCD_COOKIE_NAME ];
			// Token must be exactly 32 lowercase hex characters.
			if ( preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
				return $token;
			}
		}
		return '';
	}

	/**
	 * Generate a cryptographically secure 32-char hex device token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Check whether the mu-plugins directory is writable without actually writing.
	 *
	 * @return bool
	 */
	public static function can_install_mu_plugin() {
		$mu_dir = WP_CONTENT_DIR . '/mu-plugins/';
		if ( ! is_dir( $mu_dir ) ) {
			// Directory doesn't exist yet — check parent.
			return is_writable( WP_CONTENT_DIR );
		}
		return is_writable( $mu_dir );
	}

	/**
	 * Check if the MU-plugin is currently installed.
	 *
	 * @return bool
	 */
	public static function is_mu_plugin_installed() {
		return file_exists( WP_CONTENT_DIR . '/mu-plugins/wcd-loader.php' );
	}

	// -------------------------------------------------------------------------
	// Plugin deactivation hook
	// -------------------------------------------------------------------------

	/**
	 * Cleans up all active sessions when the plugin is deactivated.
	 * Does NOT remove the MU-plugin — a session may still be in progress.
	 * uninstall.php handles full removal.
	 */
	public static function handle_deactivation() {
		global $wpdb;

		$prefix  = $wpdb->esc_like( WCD_SESSION_PREFIX );
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$prefix . '%'
			)
		);

		foreach ( $options as $option_name ) {
			delete_option( $option_name );
		}

		self::clear_cookie();
	}

	// -------------------------------------------------------------------------
	// Cookie helpers (private)
	// -------------------------------------------------------------------------

	private static function set_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}

		$params = array(
			'expires'  => time() + WCD_SESSION_TTL,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		);

		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie( WCD_COOKIE_NAME, $token, $params );
		} else {
			// PHP < 7.3: SameSite must be appended to the path string.
			setcookie(
				WCD_COOKIE_NAME,
				$token,
				$params['expires'],
				$params['path'] . '; SameSite=Strict',
				$params['domain'],
				$params['secure'],
				$params['httponly']
			);
		}
	}

	private static function clear_cookie() {
		if ( headers_sent() ) {
			return;
		}
		setcookie( WCD_COOKIE_NAME, '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
		unset( $_COOKIE[ WCD_COOKIE_NAME ] );
	}
}
