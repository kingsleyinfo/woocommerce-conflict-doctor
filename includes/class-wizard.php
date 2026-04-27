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
		add_action( 'wp_ajax_' . WCD_AJAX_TTL_CHECK, array( __CLASS__, 'ajax_ttl_check' ) );
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

		$plugins_by_symptom = self::build_plugins_by_symptom();

		wp_localize_script( 'wcd-wizard', 'wcdData', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( WCD_NONCE ),
			'actions'      => array(
				'start'    => WCD_AJAX_START,
				'round'    => WCD_AJAX_ROUND,
				'abort'    => WCD_AJAX_ABORT,
				'purge'    => WCD_AJAX_PURGE,
				'ttlCheck' => WCD_AJAX_TTL_CHECK,
			),
			'symptoms'         => WCD_SYMPTOMS,
			'symptomUrls'      => self::build_symptom_urls(),
			'ttl'              => WCD_SESSION_TTL,
			'plugins'          => $plugins_by_symptom[''],
			'pluginsBySymptom' => $plugins_by_symptom,
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

		// Contradictory result detection: same candidate set answered two different ways.
		// Skip if this is the first round (no history yet) or if the merchant already
		// acknowledged the intermittent warning via force=1.
		$force    = ! empty( $_POST['force'] );
		$history  = isset( $session['answer_history'] ) ? (array) $session['answer_history'] : array();
		$fingerprint = md5( wp_json_encode( $candidates ) );
		if ( ! $force && isset( $history[ $fingerprint ] ) && $history[ $fingerprint ] !== $effective_answer ) {
			wp_send_json_success( array(
				'status'  => 'intermittent',
				'session' => self::sanitize_session_for_js( $session ),
			) );
		}
		$history[ $fingerprint ] = $effective_answer;
		WCD_Troubleshoot_Mode::update_session( $token, array( 'answer_history' => $history ) );

		// First round: all plugins disabled. If still broken → not a conflict.
		if ( 'full' === $phase && 'broken' === $effective_answer && $candidates === $session['disabled'] ) {
			WCD_Troubleshoot_Mode::clear_session( $token );
			wp_send_json_success( array(
				'status'         => 'not_a_conflict',
				'allowlist_kept' => self::get_allowlist_kept_display(),
			) );
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
			wp_send_json_success( array(
				'status'         => 'not_a_conflict',
				'allowlist_kept' => self::get_allowlist_kept_display(),
			) );
		}

		// Continuing — update session with new narrowed candidates.
		$new_disabled = array_slice( $result, 0, (int) floor( count( $result ) / 2 ) );

		// Diff what's changing so the merchant gets a plain-English summary in
		// the waiting screen before they click "Try again" again.
		$prev_disabled = isset( $session['disabled'] ) ? (array) $session['disabled'] : array();
		$reenabled     = array_values( array_diff( $prev_disabled, $new_disabled ) );

		WCD_Troubleshoot_Mode::update_session( $token, array(
			'candidates' => $result,
			'disabled'   => $new_disabled,
		) );

		wp_send_json_success( array(
			'status'          => 'narrowing',
			'session'         => self::sanitize_session_for_js( WCD_Troubleshoot_Mode::get_session( $token ) ),
			'not_sure'        => ( 'not_sure' === $answer ),
			'last_answer'     => $effective_answer,
			'reenabled_names' => self::plugin_names( $reenabled ),
			'disabled_names'  => self::plugin_names( $new_disabled ),
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
	// AJAX — TTL pre-check (silent ping before "Try it now" navigation)
	// -------------------------------------------------------------------------

	public static function ajax_ttl_check() {
		self::verify_request();

		$token = WCD_Troubleshoot_Mode::get_token_from_cookie();
		if ( ! $token ) {
			self::send_error( 'wcd_ttl_expired', __( 'No active test session.', 'woocommerce-conflict-doctor' ) );
		}

		$session = WCD_Troubleshoot_Mode::get_session( $token );
		if ( ! $session ) {
			self::send_error( 'wcd_ttl_expired', __( 'Your test session has expired. Please restart.', 'woocommerce-conflict-doctor' ) );
		}

		wp_send_json_success( array(
			'status'     => 'ok',
			'expires_at' => $session['expires_at'] ?? 0,
		) );
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
		$active    = (array) get_option( 'active_plugins', array() );
		$installed = self::get_testable_plugins();
		foreach ( self::get_allowlist() as $plugin ) {
			// Allowlist is "if installed, don't touch" — not "must be installed".
			// A fresh WP without Jetpack should still pass the check.
			if ( ! in_array( $plugin, $installed, true ) ) {
				continue;
			}
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

	/**
	 * Return the allowlisted plugins that stayed active during the test, as
	 * display objects. Used in the "not a conflict" screen so the HE knows
	 * which plugins were never tested and could still be the cause.
	 */
	private static function get_allowlist_kept_display() {
		$active    = (array) get_option( 'active_plugins', array() );
		$allowlist = self::get_allowlist();
		$kept      = array_values( array_intersect( $allowlist, $active ) );
		$out       = array();
		foreach ( $kept as $file ) {
			$out[] = self::get_plugin_display_data( $file );
		}
		return $out;
	}

	/**
	 * Map an array of plugin file paths to their human-readable names. Unknown
	 * files fall through to the raw file path so the merchant still sees something.
	 *
	 * @param array $files Plugin file paths (slug/plugin-file.php form).
	 * @return array Plugin display names, preserving input order.
	 */
	private static function plugin_names( array $files ) {
		if ( empty( $files ) ) {
			return array();
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$out     = array();
		foreach ( $files as $file ) {
			$out[] = isset( $plugins[ $file ] ) ? $plugins[ $file ]['Name'] : $file;
		}
		return $out;
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

	/**
	 * Build a map of symptom-key → ranked plugin list. The empty-string key
	 * holds the default mtime-only ranking; each WCD_SYMPTOMS key holds a
	 * ranking with that symptom's known suspects floated to the top.
	 *
	 * Computed once per page load (the wizard is a SPA — symptom is unknown
	 * at script-localize time, so we pre-compute every variant). Plugin
	 * metadata is gathered once and re-ranked, not re-fetched.
	 *
	 * @return array<string, array> Map keyed by symptom slug ('' included).
	 */
	private static function build_plugins_by_symptom() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins   = get_plugins();
		$active    = (array) get_option( 'active_plugins', array() );
		$allowlist = self::get_allowlist();
		$map       = apply_filters( 'wcd_symptom_suspect_map', WCD_SYMPTOM_SUSPECT_MAP );

		$candidates = array();
		foreach ( $plugins as $file => $data ) {
			$full_path = WP_PLUGIN_DIR . '/' . $file;
			$mtime     = file_exists( $full_path ) ? filemtime( $full_path ) : 0;
			$candidates[] = array(
				'file'          => $file,
				'name'          => $data['Name'],
				'version'       => $data['Version'],
				'mtime'         => $mtime,
				'active'        => in_array( $file, $active, true ),
				'allowlisted'   => in_array( $file, $allowlist, true ),
				'installed_ago' => $mtime ? human_time_diff( $mtime ) : '',
			);
		}

		$by_symptom = array();
		// Empty-symptom variant: pure mtime ranking, every entry tagged 'recent'.
		$by_symptom[''] = apply_filters(
			'wcd_suspect_plugins_by_symptom',
			self::rank_suspects( $candidates, array() ),
			'',
			$plugins
		);
		foreach ( array_keys( WCD_SYMPTOMS ) as $symptom_key ) {
			$slugs = isset( $map[ $symptom_key ] ) ? (array) $map[ $symptom_key ] : array();
			$by_symptom[ $symptom_key ] = apply_filters(
				'wcd_suspect_plugins_by_symptom',
				self::rank_suspects( $candidates, $slugs ),
				$symptom_key,
				$plugins
			);
		}

		return $by_symptom;
	}

	/**
	 * Rank a candidate list: plugins whose folder slug matches the symptom's
	 * known-suspect map are floated to the top, the rest follow by mtime
	 * (newest first). Each entry is tagged with `reason`: 'symptom' or 'recent'.
	 *
	 * Pure function — no WordPress calls. Tested directly in unit tests.
	 *
	 * @param array $candidates    Plugin entries with at least 'file' and 'mtime'.
	 * @param array $symptom_slugs Plugin folder slugs to float to top. Matched case-insensitively.
	 * @return array Re-ordered list with 'reason' added to each entry.
	 */
	public static function rank_suspects( array $candidates, array $symptom_slugs ) {
		$slug_set = array();
		foreach ( $symptom_slugs as $slug ) {
			$slug_set[ strtolower( (string) $slug ) ] = true;
		}

		$matched = array();
		$rest    = array();
		foreach ( $candidates as $entry ) {
			$folder = self::plugin_folder_slug( isset( $entry['file'] ) ? $entry['file'] : '' );
			if ( '' !== $folder && isset( $slug_set[ $folder ] ) ) {
				$entry['reason'] = 'symptom';
				$matched[]       = $entry;
			} else {
				$entry['reason'] = 'recent';
				$rest[]          = $entry;
			}
		}

		$by_mtime = static function( $a, $b ) {
			$am = isset( $a['mtime'] ) ? (int) $a['mtime'] : 0;
			$bm = isset( $b['mtime'] ) ? (int) $b['mtime'] : 0;
			return $bm <=> $am;
		};
		usort( $matched, $by_mtime );
		usort( $rest, $by_mtime );

		return array_merge( $matched, $rest );
	}

	/**
	 * Extract the folder slug from a plugin file path, lowercased.
	 * 'wp-mail-smtp/wp_mail_smtp.php' → 'wp-mail-smtp'. A single-file plugin
	 * like 'hello.php' → 'hello'. Empty input → ''.
	 *
	 * Public for unit testability.
	 */
	public static function plugin_folder_slug( $file ) {
		$file = (string) $file;
		if ( '' === $file ) {
			return '';
		}
		if ( false !== strpos( $file, '/' ) ) {
			$folder = strstr( $file, '/', true );
		} else {
			$folder = preg_replace( '/\.php$/i', '', $file );
		}
		return strtolower( (string) $folder );
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
			'modeFocused'            => __( 'I suspect one of these plugins', 'woocommerce-conflict-doctor' ),
			'modeFocusedHelper'      => __( "Pick one or more plugins below \xe2\x80\x94 we'll test those first.", 'woocommerce-conflict-doctor' ),
			'modeFocusedNeedPick'    => __( 'Pick at least one plugin to continue.', 'woocommerce-conflict-doctor' ),
			'modeShowAll'            => __( 'Show all plugins', 'woocommerce-conflict-doctor' ),
			'roundSummaryAnswerFixed' => __( 'Last round: you said it worked.', 'woocommerce-conflict-doctor' ),
			'roundSummaryAnswerBroken' => __( 'Last round: you said it was still broken.', 'woocommerce-conflict-doctor' ),
			'roundSummaryReenabled'  => __( "We've re-enabled: %s.", 'woocommerce-conflict-doctor' ),
			'roundSummaryDisabled'   => __( 'Still off for this round: %s.', 'woocommerce-conflict-doctor' ),
			'roundSummaryCta'        => __( 'Click "Try it now" to retest the site, then tell us how it went.', 'woocommerce-conflict-doctor' ),
			'themeHeader'            => __( 'Choose a safe theme to test with', 'woocommerce-conflict-doctor' ),
			'themeRecommended'       => __( 'recommended', 'woocommerce-conflict-doctor' ),
			'themeKeepCurrent'       => __( 'Keep current theme (not recommended \xe2\x80\x94 may mask theme conflicts)', 'woocommerce-conflict-doctor' ),
			'genericError'           => __( 'Something went wrong. Please try again.', 'woocommerce-conflict-doctor' ),
			'expired'                => __( 'Your test session has expired. Please restart.', 'woocommerce-conflict-doctor' ),
			'allowlistBoundaryHeader' => __( 'Plugins we kept active (not tested)', 'woocommerce-conflict-doctor' ),
			'allowlistBoundaryBody'  => __( "These plugins stayed on throughout the test, so the issue could still involve one of them. Share this list with your support agent.", 'woocommerce-conflict-doctor' ),
			'intermittentHeader'     => __( "We're seeing inconsistent results", 'woocommerce-conflict-doctor' ),
			'intermittentBody'       => __( 'The same plugin set gave different answers on different rounds. This may be an intermittent issue that comes and goes on its own. You can continue the test, but the result may be unreliable.', 'woocommerce-conflict-doctor' ),
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
