<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for WCD_Wizard::rank_suspects() and ::plugin_folder_slug().
 *
 * Pure-function tests — these methods do no WordPress calls so we can run
 * them with synthetic input (no brain/monkey, no wp-env). The full
 * build_plugins_by_symptom() wrapper is exercised end-to-end in Playwright.
 */
class SuspectRankingTest extends TestCase {

	// -------------------------------------------------------------------------
	// rank_suspects — symptom-bucket ordering + reason tagging
	// -------------------------------------------------------------------------

	public function test_no_symptom_keeps_mtime_order_and_tags_all_recent() {
		$candidates = array(
			array( 'file' => 'old/old.php',     'mtime' => 100 ),
			array( 'file' => 'newer/newer.php', 'mtime' => 300 ),
			array( 'file' => 'mid/mid.php',     'mtime' => 200 ),
		);

		$ranked = WCD_Wizard::rank_suspects( $candidates, array() );

		$this->assertSame(
			array( 'newer/newer.php', 'mid/mid.php', 'old/old.php' ),
			array_column( $ranked, 'file' ),
			'Empty symptom-slug list ranks purely by mtime descending.'
		);
		foreach ( $ranked as $entry ) {
			$this->assertSame( 'recent', $entry['reason'] );
		}
	}

	public function test_symptom_matches_float_to_top_then_remainder_by_mtime() {
		$candidates = array(
			array( 'file' => 'newer/newer.php',           'mtime' => 500 ),
			array( 'file' => 'wp-mail-smtp/wp-mail.php',  'mtime' => 100 ),
			array( 'file' => 'mid/mid.php',               'mtime' => 300 ),
			array( 'file' => 'mailgun/mailgun.php',       'mtime' => 50 ),
		);

		$ranked = WCD_Wizard::rank_suspects(
			$candidates,
			array( 'wp-mail-smtp', 'mailgun' )
		);

		// Bucket 1: wp-mail-smtp (mtime 100) before mailgun (mtime 50).
		// Bucket 2: newer (500) before mid (300).
		$this->assertSame(
			array(
				'wp-mail-smtp/wp-mail.php',
				'mailgun/mailgun.php',
				'newer/newer.php',
				'mid/mid.php',
			),
			array_column( $ranked, 'file' )
		);

		$by_file = array();
		foreach ( $ranked as $entry ) {
			$by_file[ $entry['file'] ] = $entry['reason'];
		}
		$this->assertSame( 'symptom', $by_file['wp-mail-smtp/wp-mail.php'] );
		$this->assertSame( 'symptom', $by_file['mailgun/mailgun.php'] );
		$this->assertSame( 'recent',  $by_file['newer/newer.php'] );
		$this->assertSame( 'recent',  $by_file['mid/mid.php'] );
	}

	public function test_symptom_slug_match_is_case_insensitive() {
		$candidates = array(
			array( 'file' => 'WP-Mail-SMTP/WP-Mail-SMTP.php', 'mtime' => 100 ),
			array( 'file' => 'other/other.php',               'mtime' => 200 ),
		);

		$ranked = WCD_Wizard::rank_suspects(
			$candidates,
			array( 'wp-mail-smtp' )
		);

		$this->assertSame( 'WP-Mail-SMTP/WP-Mail-SMTP.php', $ranked[0]['file'] );
		$this->assertSame( 'symptom', $ranked[0]['reason'] );
	}

	public function test_unknown_symptom_slug_does_not_match_anything() {
		$candidates = array(
			array( 'file' => 'a/a.php', 'mtime' => 100 ),
			array( 'file' => 'b/b.php', 'mtime' => 200 ),
		);

		$ranked = WCD_Wizard::rank_suspects( $candidates, array( 'nonexistent' ) );

		// Nothing matched — pure mtime order, all 'recent'.
		$this->assertSame( array( 'b/b.php', 'a/a.php' ), array_column( $ranked, 'file' ) );
		$this->assertSame( 'recent', $ranked[0]['reason'] );
		$this->assertSame( 'recent', $ranked[1]['reason'] );
	}

	public function test_preserves_extra_entry_fields() {
		$candidates = array(
			array(
				'file'        => 'wp-mail-smtp/wp-mail.php',
				'mtime'       => 100,
				'name'        => 'WP Mail SMTP',
				'active'      => true,
				'allowlisted' => false,
			),
		);

		$ranked = WCD_Wizard::rank_suspects( $candidates, array( 'wp-mail-smtp' ) );

		$this->assertSame( 'WP Mail SMTP', $ranked[0]['name'] );
		$this->assertTrue( $ranked[0]['active'] );
		$this->assertFalse( $ranked[0]['allowlisted'] );
		$this->assertSame( 'symptom', $ranked[0]['reason'] );
	}

	public function test_empty_candidates_returns_empty_array() {
		$this->assertSame( array(), WCD_Wizard::rank_suspects( array(), array() ) );
		$this->assertSame( array(), WCD_Wizard::rank_suspects( array(), array( 'foo' ) ) );
	}

	public function test_entry_missing_mtime_treated_as_zero() {
		$candidates = array(
			array( 'file' => 'no-mtime/x.php' ),
			array( 'file' => 'has-mtime/y.php', 'mtime' => 50 ),
		);

		$ranked = WCD_Wizard::rank_suspects( $candidates, array() );

		// Has-mtime sorts above no-mtime (which is treated as 0).
		$this->assertSame( 'has-mtime/y.php', $ranked[0]['file'] );
		$this->assertSame( 'no-mtime/x.php',  $ranked[1]['file'] );
	}

	// -------------------------------------------------------------------------
	// plugin_folder_slug — file-path → folder-slug extraction
	// -------------------------------------------------------------------------

	public function test_folder_slug_extracts_directory_from_standard_path() {
		$this->assertSame(
			'wp-mail-smtp',
			WCD_Wizard::plugin_folder_slug( 'wp-mail-smtp/wp_mail_smtp.php' )
		);
	}

	public function test_folder_slug_handles_single_file_plugin() {
		$this->assertSame( 'hello', WCD_Wizard::plugin_folder_slug( 'hello.php' ) );
	}

	public function test_folder_slug_lowercases_mixed_case() {
		$this->assertSame(
			'wp-mail-smtp',
			WCD_Wizard::plugin_folder_slug( 'WP-Mail-SMTP/Loader.php' )
		);
	}

	public function test_folder_slug_handles_empty_input() {
		$this->assertSame( '', WCD_Wizard::plugin_folder_slug( '' ) );
	}

	public function test_folder_slug_handles_non_string_gracefully() {
		// Cast happens internally — null becomes ''.
		$this->assertSame( '', WCD_Wizard::plugin_folder_slug( null ) );
	}
}
