<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Session CRUD tests for WCD_Troubleshoot_Mode.
 *
 * These verify the security-critical path: token generation, TTL expiry,
 * option storage with autoload=false, and cookie handling. WP functions
 * are mocked with brain/monkey so no WP stack boot is needed.
 */
class TroubleshootModeTest extends TestCase {

	/** @var array Mock options table. */
	private $storage;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->storage = array();

		$ref = &$this->storage;

		Functions\when( 'get_option' )->alias( function ( $key, $default = false ) use ( &$ref ) {
			return $ref[ $key ] ?? $default;
		} );
		Functions\when( 'add_option' )->alias( function ( $key, $value, $deprecated = '', $autoload = 'yes' ) use ( &$ref ) {
			if ( array_key_exists( $key, $ref ) ) {
				return false;
			}
			$ref[ $key ] = $value;
			return true;
		} );
		Functions\when( 'update_option' )->alias( function ( $key, $value, $autoload = null ) use ( &$ref ) {
			$ref[ $key ] = $value;
			return true;
		} );
		Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$ref ) {
			if ( ! array_key_exists( $key, $ref ) ) {
				return false;
			}
			unset( $ref[ $key ] );
			return true;
		} );

		// Skip all cookie writes — the class guards on headers_sent().
		Functions\when( 'headers_sent' )->justReturn( true );
		Functions\when( 'is_ssl' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// start_session
	// -------------------------------------------------------------------------

	public function test_start_session_writes_option_with_expected_shape() {
		$token = str_repeat( 'a', 32 );

		$result = WCD_Troubleshoot_Mode::start_session( 7, $token, array( 'a/a.php', 'b/b.php' ), 'twentytwentyfour' );

		$this->assertSame( 7, $result['user_id'] );
		$this->assertSame( $token, $result['token'] );
		$this->assertSame( array( 'a/a.php', 'b/b.php' ), $result['disabled'] );
		$this->assertSame( 'twentytwentyfour', $result['theme'] );
		$this->assertGreaterThan( time() + 3500, $result['expires_at'], 'TTL should be ~1hr in the future' );
		$this->assertArrayHasKey( 'wcd_session_' . $token, $this->storage );
	}

	public function test_start_session_reindexes_disabled_array() {
		// Non-contiguous keys must be reset so wp_send_json serializes as array, not object.
		$token    = str_repeat( 'b', 32 );
		$disabled = array( 5 => 'a/a.php', 10 => 'b/b.php' );

		$result = WCD_Troubleshoot_Mode::start_session( 1, $token, $disabled );

		$this->assertSame( array( 0, 1 ), array_keys( $result['disabled'] ) );
	}

	// -------------------------------------------------------------------------
	// get_session
	// -------------------------------------------------------------------------

	public function test_get_session_returns_null_for_unknown_token() {
		$this->assertNull( WCD_Troubleshoot_Mode::get_session( 'nonexistent' ) );
	}

	public function test_get_session_returns_null_for_empty_token() {
		$this->assertNull( WCD_Troubleshoot_Mode::get_session( '' ) );
	}

	public function test_get_session_returns_null_when_expired() {
		$token = str_repeat( 'c', 32 );
		$this->storage[ 'wcd_session_' . $token ] = array(
			'user_id'    => 1,
			'token'      => $token,
			'disabled'   => array(),
			'expires_at' => time() - 60, // expired a minute ago
		);

		$this->assertNull( WCD_Troubleshoot_Mode::get_session( $token ) );
	}

	public function test_get_session_returns_data_when_valid() {
		$token   = str_repeat( 'd', 32 );
		$session = array(
			'user_id'    => 3,
			'token'      => $token,
			'disabled'   => array( 'plug/plug.php' ),
			'theme'      => 'tt4',
			'expires_at' => time() + 1800,
		);
		$this->storage[ 'wcd_session_' . $token ] = $session;

		$result = WCD_Troubleshoot_Mode::get_session( $token );

		$this->assertSame( $session, $result );
	}

	// -------------------------------------------------------------------------
	// update_session
	// -------------------------------------------------------------------------

	public function test_update_session_merges_updates_into_existing() {
		$token = str_repeat( 'e', 32 );
		$this->storage[ 'wcd_session_' . $token ] = array(
			'user_id'    => 4,
			'token'      => $token,
			'disabled'   => array( 'a/a.php' ),
			'expires_at' => time() + 1800,
		);

		$result = WCD_Troubleshoot_Mode::update_session( $token, array(
			'candidates' => array( 'a/a.php' ),
			'phase'      => 'full',
		) );

		$this->assertSame( array( 'a/a.php' ), $result['candidates'] );
		$this->assertSame( 'full', $result['phase'] );
		$this->assertSame( 4, $result['user_id'], 'user_id must be preserved' );
	}

	public function test_update_session_rejects_token_and_user_id_overwrites() {
		// Important: a client-supplied update must never rewrite the token or user_id.
		$token = str_repeat( 'f', 32 );
		$this->storage[ 'wcd_session_' . $token ] = array(
			'user_id'    => 4,
			'token'      => $token,
			'disabled'   => array(),
			'expires_at' => time() + 1800,
		);

		$result = WCD_Troubleshoot_Mode::update_session( $token, array(
			'token'   => 'evil-token',
			'user_id' => 999,
			'phase'   => 'full',
		) );

		$this->assertSame( $token, $result['token'] );
		$this->assertSame( 4, $result['user_id'] );
		$this->assertSame( 'full', $result['phase'] );
	}

	public function test_update_session_returns_false_for_missing_session() {
		$this->assertFalse( WCD_Troubleshoot_Mode::update_session( 'nope', array( 'phase' => 'full' ) ) );
	}

	// -------------------------------------------------------------------------
	// clear_session
	// -------------------------------------------------------------------------

	public function test_clear_session_deletes_option() {
		$token = str_repeat( '9', 32 );
		$this->storage[ 'wcd_session_' . $token ] = array( 'token' => $token );

		WCD_Troubleshoot_Mode::clear_session( $token );

		$this->assertArrayNotHasKey( 'wcd_session_' . $token, $this->storage );
	}

	public function test_clear_session_with_empty_token_is_noop() {
		$this->storage['wcd_session_keep'] = array( 'token' => 'keep' );

		WCD_Troubleshoot_Mode::clear_session( '' );

		$this->assertArrayHasKey( 'wcd_session_keep', $this->storage );
	}

	// -------------------------------------------------------------------------
	// generate_token / get_token_from_cookie
	// -------------------------------------------------------------------------

	public function test_generate_token_returns_32_char_hex() {
		$token = WCD_Troubleshoot_Mode::generate_token();
		$this->assertSame( 32, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $token );
	}

	public function test_generate_token_returns_distinct_values() {
		$a = WCD_Troubleshoot_Mode::generate_token();
		$b = WCD_Troubleshoot_Mode::generate_token();
		$this->assertNotSame( $a, $b );
	}

	public function test_get_token_from_cookie_accepts_valid_hex() {
		$valid = str_repeat( 'a', 32 );
		$_COOKIE[ WCD_COOKIE_NAME ] = $valid;

		$this->assertSame( $valid, WCD_Troubleshoot_Mode::get_token_from_cookie() );

		unset( $_COOKIE[ WCD_COOKIE_NAME ] );
	}

	public function test_get_token_from_cookie_rejects_invalid_format() {
		// Not hex, wrong length, or contains uppercase — all rejected. This is the
		// defensive regex that prevents cookie-tampering from hitting get_option().
		$bad_values = array(
			'short',                               // too short
			str_repeat( 'g', 32 ),                 // non-hex char
			str_repeat( 'A', 32 ),                 // uppercase hex
			str_repeat( 'a', 31 ),                 // 31 chars
			str_repeat( 'a', 33 ),                 // 33 chars
			'<script>alert(1)</script>',           // obvious attack
			'../../../../etc/passwd',              // path traversal
		);

		foreach ( $bad_values as $bad ) {
			$_COOKIE[ WCD_COOKIE_NAME ] = $bad;
			$this->assertSame( '', WCD_Troubleshoot_Mode::get_token_from_cookie(),
				sprintf( 'Expected empty for bogus cookie: %s', $bad ) );
		}

		unset( $_COOKIE[ WCD_COOKIE_NAME ] );
	}

	public function test_get_token_from_cookie_returns_empty_when_cookie_missing() {
		unset( $_COOKIE[ WCD_COOKIE_NAME ] );
		$this->assertSame( '', WCD_Troubleshoot_Mode::get_token_from_cookie() );
	}

	// -------------------------------------------------------------------------
	// End-to-end CRUD cycle
	// -------------------------------------------------------------------------

	public function test_full_session_lifecycle() {
		$token = WCD_Troubleshoot_Mode::generate_token();

		// Create.
		$created = WCD_Troubleshoot_Mode::start_session( 42, $token, array( 'plug/plug.php' ), 'tt4' );
		$this->assertSame( 42, $created['user_id'] );

		// Read.
		$fetched = WCD_Troubleshoot_Mode::get_session( $token );
		$this->assertSame( $created, $fetched );

		// Update.
		WCD_Troubleshoot_Mode::update_session( $token, array( 'phase' => 'full', 'candidates' => array( 'plug/plug.php' ) ) );
		$updated = WCD_Troubleshoot_Mode::get_session( $token );
		$this->assertSame( 'full', $updated['phase'] );

		// Clear.
		WCD_Troubleshoot_Mode::clear_session( $token );
		$this->assertNull( WCD_Troubleshoot_Mode::get_session( $token ) );
	}
}
