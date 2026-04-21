<?php
defined( 'ABSPATH' ) || exit;

class WCD_Wizard {

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'wp_ajax_' . WCD_AJAX_START, array( __CLASS__, 'ajax_start_test' ) );
		add_action( 'wp_ajax_' . WCD_AJAX_ROUND, array( __CLASS__, 'ajax_round_result' ) );
		add_action( 'wp_ajax_' . WCD_AJAX_ABORT, array( __CLASS__, 'ajax_abort_test' ) );
		add_action( 'wp_ajax_' . WCD_AJAX_PURGE, array( __CLASS__, 'ajax_purge_cache' ) );
	}

	public static function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Conflict Doctor', 'woocommerce-conflict-doctor' ),
			__( 'Conflict Doctor', 'woocommerce-conflict-doctor' ),
			'manage_options',
			'wcd-wizard',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wcd-wizard' !== $hook ) {
			return;
		}
		wp_enqueue_script(
			'wcd-wizard',
			WCD_PLUGIN_URL . 'assets/wizard.js',
			array(),
			WCD_VERSION,
			true
		);
		wp_enqueue_style(
			'wcd-wizard',
			WCD_PLUGIN_URL . 'assets/wizard.css',
			array(),
			WCD_VERSION
		);

		wp_localize_script( 'wcd-wizard', 'wcdData', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( WCD_NONCE ),
			'actions'      => array(
				'start' => WCD_AJAX_START,
				'round' => WCD_AJAX_ROUND,
				'abort' => WCD_AJAX_ABORT,
				'purge' => WCD_AJAX_PURGE,
			),
			'symptoms'     => WCD_SYMPTOMS,
			'symptomUrls'  => self::build_symptom_urls(),
			'ttl'          => WCD_SESSION_TTL,
			'plugins'      => self::get_suspect_ranked_plugins(),
			'themes'       => self::get_safe_themes(),
			'currentTheme' => get_stylesheet(),
			'cachePlugin'  => self::detect_cache_plugin(),
			'session'      => self::get_active_session_for_js(),
			'canInstallMu' => WCD_Troubleshoot_Mode::can_install_mu_plugin(),
			'debugMode'    => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
			'homeUrl'      => home_url( '/' ),
			'strings'      => self::translatable_strings(),
		) );
	}

	public static function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woocommerce-conflict-doctor' ) );
		}
		echo '<div class="wrap" id="wcd-wizard-root">';
		echo '<h1>' . esc_html__( 'WooCommerce Conflict Doctor', 'woocommerce-conflict-doctor' ) . '</h1>';
		echo '<div id="wcd-wizard-app" data-loading="true"></div>';
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// AJAX — start test
	// -------------------------------------------------------------------------

	public static function ajax_start_test() {
		self::verify_request();

		// Pre-flight: check MU-plugin can be installed.
		if ( ! WCD_Troubleshoot_Mode::can_install_mu_plugin() ) {
			self::send_managed_host_error();
		}

		$result = WCD_Troubleshoot_Mode::install_mu_plugin();
		if ( is_wp_error( $result ) ) {
			self::send_error( 'wcd_mu_write_failed', $result->get_error_message() );
		}

		// Re-entry: check if a session already exists for this user.
		$existing_token = WCD_Troubleshoot_Mode::get_token_from_cookie();
		if ( $existing_token ) {
			$existing = WCD_Troubleshoot_Mode::get_session( $existing_token );
			if ( $existing ) {
				wp_send_json_success( array(
					'status'  => 'resume_prompt',
					'session' => self::sanitize_session_for_js( $existing ),
				) );
			}
		}

		$symptom  = isset( $_POST['symptom'] ) ? sanitize_key( $_POST['symptom'] ) : '';
		$theme    = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'full';
		$suspects = isset( $_POST['suspects'] ) && is_array( $_POST['suspects'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['suspects'] ) )
			: array();

		$all_plugins   = self::get_testable_plugins();
		$allowlist     = self::get_allowlist();

		if ( 'focused' === $mode && ! empty( $suspects ) ) {
			$disabled    = array_intersect( $suspects, $all_plugins );
			$candidates  = array_values( $disabled );
			$phase       = 'sequential';
		} else {
			$disabled    = array_values( array_diff( $all_plugins, $allowlist ) );
			$candidates  = $disabled;
			$phase       = 'full';
		}

		$token   = WCD_Troubleshoot_Mode::generate_token();
		$user_id = get_current_user_id();

		$session = WCD_Troubleshoot_Mode::start_session( $user_id, $token, $disabled, $theme );
		$session['candidates'] = $candidates;
		$session['phase']      = $phase;
		$session['symptom']    = $symptom;
		WCD_Troubleshoot_Mode::update_session( $token, array(
			'candidates' => $candidates,
			'phase'      => $phase,
			'symptom'    => $symptom,
		) );

		wp_send_json_success( array(
			'status'  => 'started',
			'token'   => $token,
			'session' => self::sanitize_session_for_js( WCD_Troubleshoot_Mode::get_session( $token ) ),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX — round result
	// -------------------------------------------------------------------------

	public static function ajax_round_result() {
		self::verify_request();

		$token = WCD_Troubleshoot_Mode::get_token_from_cookie();
		if ( ! $token ) {
			self::send_error( 'wcd_session_expired', __( 'No active test session. Please start again.', 'woocommerce-conflict-doctor' ) );
		}

		$session = WCD_Troubleshoot_Mode::get_session( $token );
		if ( ! $session ) {
			self::send_error( 'wcd_session_expired', __( 'Your test session has expired. Please start again.', 'woocommerce-conflict-doctor' ) );
		}

		$answer = isset( $_POST['answer'] ) ? sanitize_key( $_POST['answer'] ) : '';
		if ( ! in_array( $answer, array( 'fixed', 'broken', 'not_sure' ), true ) ) {
			self::send_error( 'wcd_invalid_answer', __( 'Invalid answer value.', 'woocommerce-conflict-doctor' ) );
		}

		// Re-validate that WooCommerce and the plugin itself are still active.
		self::assert_allowlist_intact();

		// "Not sure" is treated as "broken" — safest choice.
		$effective_answer = ( 'not_sure' === $answer ) ? 'broken' : $answer;

		$candidates = isset( $session['candidates'] ) ? (array) $session['candidates'] : array();
		$phase      = isset( $session['phase'] ) ? $session['phase'] : 'full';

		// First round: all plugins disabled. If still broken → not a conflict.
		if ( 'full' === $phase && 'broken' === $effective_answer && $candidates === $session['disabled'] ) {
			WCD_Troubleshoot_Mode::clear_session( $token );
			wp_send_json_success( array( 'status' => 'not_a_conflict' ) );
		}

		// Sequential mode (focused suspects tested one at a time).
		if ( 'sequential' === $phase ) {
			$result = self::sequential_next_suspect( $candidates, $effective_answer );
		} else {
			$result = self::binary_search_next_round( $candidates, $effective_answer );
		}

		// Terminal: single culprit identified.
		if ( 1 === count( $result ) ) {
			$culprit = $result[0];
			// Re-enable everything, store result for 48 hours.
			WCD_Troubleshoot_Mode::update_session( $token, array(
				'disabled'    => array(),
				'culprit'     => $culprit,
				'result_at'   => time(),
				'expires_at'  => time() + ( 48 * HOUR_IN_SECONDS ),
			) );
			$plugin_data = self::get_plugin_display_data( $culprit );
			wp_send_json_success( array(
				'status'  => 'culprit_found',
				'culprit' => $plugin_data,
			) );
		}

		// Empty result: no culprit found (exhausted non-allowlisted plugins).
		if ( empty( $result ) ) {
			WCD_Troubleshoot_Mode::clear_session( $token );
			wp_send_json_success( array( 'status' => 'not_a_conflict' ) );
		}

		// Continuing — update session with new narrowed candidates.
		$new_disabled = array_slice( $result, 0, (int) floor( count( $result ) / 2 ) );
		WCD_Troubleshoot_Mode::update_session( $token, array(
			'candidates' => $result,
			'disabled'   => $new_disabled,
		) );

		wp_send_json_success( array(
			'status'    => 'narrowing',
			'session'   => self::sanitize_session_for_js( WCD_Troubleshoot_Mode::get_session( $token ) ),
			'not_sure'  => ( 'not_sure' === $answer ),
		) );
	}

	// -------------------------------------------------------------------------
	// AJAX — abort test
	// -------------------------------------------------------------------------

	public static function ajax_abort_test() {
		self::verify_request();

		$token = WCD_Troubleshoot_Mode::get_token_from_cookie();
		if ( $token ) {
			WCD_Troubleshoot_Mode::clear_session( $token );
		}

		wp_send_json_success( array( 'status' => 'aborted' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX — purge cache
	// -------------------------------------------------------------------------

	public static function ajax_purge_cache() {
		self::verify_request();

		$active = wp_get_active_and_valid_plugins();
		foreach ( WCD_CACHE_PLUGINS as $plugin_file => $plugin_name ) {
			$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;
			if ( ! in_array( $full_path, $active, true ) ) {
				continue;
			}

			$purged = self::purge_known_cache( $plugin_file );
			if ( is_wp_error( $purged ) ) {
				self::send_error( 'wcd_cache_purge_failed', sprintf(
					/* translators: %s: cache plugin name */
					__( "Couldn't clear %s cache automatically. Clear it manually, then continue.", 'woocommerce-conflict-doctor' ),
					$plugin_name
				) );
			}

			wp_send_json_success( array(
				'status' => 'purged',
				'plugin' => $plugin_name,
			) );
		}

		wp_send_json_success( array( 'status' => 'no_cache_plugin' ) );
	}

	// -------------------------------------------------------------------------
	// Pure functions — binary search and sequential narrowing
	// -------------------------------------------------------------------------

	/**
	 * Binary search narrowing step.
	 *
	 * Determines the next candidate set based on the merchant's round result.
	 * PURE FUNCTION: no WP calls, no side effects. Array in, array out.
	 *
	 * @param array  $candidates Plugins currently under investigation.
	 * @param string $answer     'fixed' if disabling the first half resolved the issue;
	 *                           'broken' if it did not.
	 * @return array Narrowed candidate set for the next round.
	 */
	public static function binary_search_next_round( array $candidates, $answer ) {
		if ( empty( $candidates ) ) {
			return array();
		}

		$half = (int) floor( count( $candidates ) / 2 );

		if ( 'fixed' === $answer ) {
			// Disabling the first half fixed it: culprit is in the first half.
			return array_slice( $candidates, 0, $half );
		}

		// 'broken': disabling the first half didn't help; culprit is in the second half.
		return array_slice( $candidates, $half );
	}

	/**
	 * Sequential suspect narrowing step.
	 *
	 * For focused mode: tests one suspect at a time. If disabling the current
	 * suspect fixed it, we're done (returns that suspect alone). If not, move to next.
	 * PURE FUNCTION: no WP calls, no side effects. Array in, array out.
	 *
	 * @param array  $suspects Remaining untested suspects.
	 * @param string $answer   'fixed' or 'broken'.
	 * @return array Next candidate set. Single-element = culprit found.
	 */
	public static function sequential_next_suspect( array $suspects, $answer ) {
		if ( empty( $suspects ) ) {
			return array();
		}

		if ( 'fixed' === $answer ) {
			// The first suspect (currently disabled) caused the issue.
			return array( $suspects[0] );
		}

		// 'broken': first suspect is clear, try the next one.
		return array_values( array_slice( $suspects, 1 ) );
	}

	// -------------------------------------------------------------------------
	// Support helpers
	// -------------------------------------------------------------------------

	/**
	 * Emit a JSON error response and exit. Named error codes allow grep-driven debug.
	 *
	 * @param string $code    Machine-readable error code (e.g. 'wcd_nonce_invalid').
	 * @param string $message Human-readable message shown to the merchant.
	 */
	public static function send_error( $code, $message ) {
		wp_send_json_error( array(
			'code'    => $code,
			'message' => $message,
		), 200 );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private static function verify_request() {
		if ( ! check_ajax_referer( WCD_NONCE, 'nonce', false ) ) {
			self::send_error( 'wcd_nonce_invalid', __( 'Security check failed. Please refresh and try again.', 'woocommerce-conflict-doctor' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::send_error( 'wcd_permission_denied', __( 'You do not have permission to run conflict tests.', 'woocommerce-conflict-doctor' ) );
		}
	}

	private static function send_managed_host_error() {
		self::send_error(
			'wcd_mu_write_failed',
			__( 'Your hosting provider blocks the safe per-user testing mode. This means we can\'t run the conflict test without affecting your live site. Contact your host and ask them to allow writes to wp-content/mu-plugins/, or test on a staging environment.', 'woocommerce-conflict-doctor' )
		);
	}

	private static function get_testable_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_keys( get_plugins() );
	}

	private static function get_allowlist() {
		$defaults = WCD_ALLOWLIST_DEFAULTS;
		// Normalize to basename format used by WP (plugin-dir/plugin-file.php).
		$own = plugin_basename( WCD_PLUGIN_FILE );
		if ( ! in_array( $own, $defaults, true ) ) {
			$defaults[] = $own;
		}
		return apply_filters( 'wcd_allowlist', $defaults );
	}

	private static function assert_allowlist_intact() {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::get_allowlist() as $plugin ) {
			if ( ! in_array( $plugin, $active, true ) ) {
				// An allowlisted plugin was deactivated out-of-band. Abort the test.
				$token = WCD_Troubleshoot_Mode::get_token_from_cookie();
				if ( $token ) {
					WCD_Troubleshoot_Mode::clear_session( $token );
				}
				self::send_error(
					'wcd_allowlist_deactivated',
					sprintf(
						/* translators: %s: plugin file path */
						__( '%s was deactivated while your test was running. Restoring everything — restart when ready.', 'woocommerce-conflict-doctor' ),
						esc_html( $plugin )
					)
				);
			}
		}
	}

	private static function sanitize_session_for_js( $session ) {
		if ( ! is_array( $session ) ) {
			return array();
		}
		return array(
			'disabled'   => $session['disabled'] ?? array(),
			'candidates' => $session['candidates'] ?? array(),
			'phase'      => $session['phase'] ?? 'full',
			'symptom'    => $session['symptom'] ?? '',
			'theme'      => $session['theme'] ?? '',
			'expires_at' => $session['expires_at'] ?? 0,
		);
	}

	private static function get_plugin_display_data( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( isset( $plugins[ $plugin_file ] ) ) {
			$p = $plugins[ $plugin_file ];
			return array(
				'file'    => $plugin_file,
				'name'    => $p['Name'],
				'version' => $p['Version'],
				'author'  => $p['Author'],
			);
		}
		return array( 'file' => $plugin_file, 'name' => $plugin_file, 'version' => '', 'author' => '' );
	}

	private static function get_suspect_ranked_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins   = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$allowlist = self::get_allowlist();
		$ranked    = array();

		foreach ( $plugins as $file => $data ) {
			$full_path = WP_PLUGIN_DIR . '/' . $file;
			$mtime     = file_exists( $full_path ) ? filemtime( $full_path ) : 0;
			$ranked[]  = array(
				'file'         => $file,
				'name'         => $data['Name'],
				'version'      => $data['Version'],
				'mtime'        => $mtime,
				'active'       => in_array( $file, $active, true ),
				'allowlisted'  => in_array( $file, $allowlist, true ),
				'installed_ago' => human_time_diff( $mtime ),
			);
		}

		// Newest first — most recently installed/updated is the likeliest culprit.
		usort( $ranked, static function( $a, $b ) {
			return $b['mtime'] <=> $a['mtime'];
		} );

		return $ranked;
	}

	private static function get_safe_themes() {
		$themes  = wp_get_themes();
		$safe    = array();
		$preferred = array( 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo' );

		foreach ( $preferred as $slug ) {
			if ( isset( $themes[ $slug ] ) ) {
				$safe[] = array(
					'slug'      => $slug,
					'name'      => $themes[ $slug ]->get( 'Name' ),
					'preferred' => true,
				);
			}
		}

		// Include other installed themes as fallback options.
		foreach ( $themes as $slug => $theme ) {
			if ( in_array( $slug, $preferred, true ) ) {
				continue;
			}
			$safe[] = array(
				'slug'      => $slug,
				'name'      => $theme->get( 'Name' ),
				'preferred' => false,
			);
		}

		return $safe;
	}

	private static function detect_cache_plugin() {
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( WCD_CACHE_PLUGINS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return array(
					'file' => $file,
					'name' => $name,
				);
			}
		}
		return null;
	}

	private static function get_active_session_for_js() {
		$token = WCD_Troubleshoot_Mode::get_token_from_cookie();
		if ( ! $token ) {
			return null;
		}
		$session = WCD_Troubleshoot_Mode::get_session( $token );
		if ( ! $session ) {
			return null;
		}
		return self::sanitize_session_for_js( $session );
	}

	private static function build_symptom_urls() {
		$home = home_url( '/' );
		$urls = array(
			'checkout' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $home,
			'cart'     => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : $home,
			'admin'    => admin_url(),
			'frontend' => $home,
			'products' => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : $home,
			'emails'   => $home,
			'other'    => $home,
		);
		return $urls;
	}

	private static function translatable_strings() {
		return array(
			'loading'                => __( 'Loading…', 'woocommerce-conflict-doctor' ),
			'updating'               => __( 'Updating your test…', 'woocommerce-conflict-doctor' ),
			'worksNow'               => __( 'Works now', 'woocommerce-conflict-doctor' ),
			'stillBroken'            => __( 'Still broken', 'woocommerce-conflict-doctor' ),
			'notSure'                => __( "Not sure \xe2\x80\x94 I can't tell", 'woocommerce-conflict-doctor' ),
			'notSureDisclosure'      => __( "We'll treat this as \"still broken\" and keep narrowing down. That's the safest choice.", 'woocommerce-conflict-doctor' ),
			'tryItNow'               => __( 'Try it now', 'woocommerce-conflict-doctor' ),
			'tryItHint'              => __( 'Open this on the same device and browser where you first saw the problem.', 'woocommerce-conflict-doctor' ),
			'waitingHeader'          => __( 'Your test is paused', 'woocommerce-conflict-doctor' ),
			'waitingBody'            => __( 'Come back here after you have tested your site.', 'woocommerce-conflict-doctor' ),
			'abort'                  => __( 'Abort and restore everything', 'woocommerce-conflict-doctor' ),
			'back'                   => __( 'Back', 'woocommerce-conflict-doctor' ),
			'continue'               => __( 'Continue', 'woocommerce-conflict-doctor' ),
			'cancel'                 => __( 'Cancel', 'woocommerce-conflict-doctor' ),
			'go'                     => __( 'Go', 'woocommerce-conflict-doctor' ),
			'done'                   => __( 'Done', 'woocommerce-conflict-doctor' ),
			'restart'                => __( 'Restart', 'woocommerce-conflict-doctor' ),
			'copyDiagnosis'          => __( 'Copy diagnosis to clipboard', 'woocommerce-conflict-doctor' ),
			'copied'                 => __( 'Copied!', 'woocommerce-conflict-doctor' ),
			'restoredHeader'         => __( 'Your site is back to normal.', 'woocommerce-conflict-doctor' ),
			'restoredBody'           => __( 'All plugins re-enabled. Theme restored. Nothing was permanently changed.', 'woocommerce-conflict-doctor' ),
			'notAConflictHeader'     => __( 'We ruled out plugins and themes', 'woocommerce-conflict-doctor' ),
			'notAConflictBody'       => __( "That means the issue is likely in your settings or data, and your support agent can diagnose it much faster now.\nNote: some plugins leave behind stored data even when disabled \xe2\x80\x94 if the issue is data-related, this test won't catch it.", 'woocommerce-conflict-doctor' ),
			'culpritHeader'          => __( 'We found the plugin causing your issue', 'woocommerce-conflict-doctor' ),
			'managedHostHeader'      => __( 'Your host blocks safe testing', 'woocommerce-conflict-doctor' ),
			'managedHostBody'        => __( "Your hosting provider blocks the safe per-user testing mode. This means we can't run the conflict test without affecting your live site.\n\nWhat to do: Contact your host and ask them to allow writes to wp-content/mu-plugins/, or test on a staging environment.", 'woocommerce-conflict-doctor' ),
			'resumeHeader'           => __( 'You have an active test in progress', 'woocommerce-conflict-doctor' ),
			'resumePrompt'           => __( 'A test started on another tab or device is still running.', 'woocommerce-conflict-doctor' ),
			'resume'                 => __( 'Resume test', 'woocommerce-conflict-doctor' ),
			'debugWarning'           => __( 'Your site is in debug mode. You may see technical messages during testing \xe2\x80\x94 these are not new errors caused by this tool.', 'woocommerce-conflict-doctor' ),
			'cacheWarning'           => __( "Cached pages are served before WordPress boots, which means your customers won't see changes during the test unless we clear the cache first.", 'woocommerce-conflict-doctor' ),
			'cachePurgeBefore'       => __( 'Yes, purge cache before each round', 'woocommerce-conflict-doctor' ),
			'cacheSkip'              => __( 'Skip \xe2\x80\x94 I understand results may be inconsistent', 'woocommerce-conflict-doctor' ),
			'confirmHeader'          => __( "Ready? Here's what will happen:", 'woocommerce-conflict-doctor' ),
			'confirmReassure'        => __( "Your customers won't see any changes during this test \xe2\x80\x94 only you will.", 'woocommerce-conflict-doctor' ),
			'confirmStayActive'      => __( 'These will stay on throughout:', 'woocommerce-conflict-doctor' ),
			'confirmTempOff'         => __( 'These will be temporarily hidden from you only:', 'woocommerce-conflict-doctor' ),
			'confirmTheme'           => __( "We'll switch your theme to:", 'woocommerce-conflict-doctor' ),
			'showList'               => __( 'Show list', 'woocommerce-conflict-doctor' ),
			'hideList'               => __( 'Hide list', 'woocommerce-conflict-doctor' ),
			'symptomHeader'          => __( 'What is broken?', 'woocommerce-conflict-doctor' ),
			'modeHeader'             => __( 'Do you have a specific plugin in mind?', 'woocommerce-conflict-doctor' ),
			'modeLikely'             => __( "We think these are the most likely culprits \xe2\x80\x94 recently installed or updated:", 'woocommerce-conflict-doctor' ),
			'modeTestAll'            => __( "I don't know \xe2\x80\x94 test everything", 'woocommerce-conflict-doctor' ),
			'modeShowAll'            => __( 'Show all plugins', 'woocommerce-conflict-doctor' ),
			'themeHeader'            => __( 'Choose a safe theme to test with', 'woocommerce-conflict-doctor' ),
			'themeRecommended'       => __( 'recommended', 'woocommerce-conflict-doctor' ),
			'themeKeepCurrent'       => __( 'Keep current theme (not recommended \xe2\x80\x94 may mask theme conflicts)', 'woocommerce-conflict-doctor' ),
			'genericError'           => __( 'Something went wrong. Please try again.', 'woocommerce-conflict-doctor' ),
			'expired'                => __( 'Your test session has expired. Please restart.', 'woocommerce-conflict-doctor' ),
		);
	}

	private static function purge_known_cache( $plugin_file ) {
		switch ( $plugin_file ) {
			case 'wp-rocket/wp-rocket.php':
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
					return true;
				}
				break;
			case 'litespeed-cache/litespeed-cache.php':
				do_action( 'litespeed_purge_all' );
				return true;
			case 'w3-total-cache/w3-total-cache.php':
				if ( function_exists( 'w3tc_flush_all' ) ) {
					w3tc_flush_all();
					return true;
				}
				break;
			case 'autoptimize/autoptimize.php':
				if ( class_exists( 'autoptimizeCache' ) ) {
					autoptimizeCache::clearall();
					return true;
				}
				break;
			case 'wp-super-cache/wp-cache.php':
				if ( function_exists( 'wp_cache_clear_cache' ) ) {
					wp_cache_clear_cache();
					return true;
				}
				break;
		}
		return new WP_Error( 'wcd_cache_purge_failed', 'Cache plugin API not available.' );
	}
}
