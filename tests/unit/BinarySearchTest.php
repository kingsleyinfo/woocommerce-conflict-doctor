<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for WCD_Wizard::binary_search_next_round() and sequential_next_suspect().
 * Pure function tests — no WordPress functions called, no wp-env needed.
 */
class BinarySearchTest extends TestCase {

	// -------------------------------------------------------------------------
	// binary_search_next_round
	// -------------------------------------------------------------------------

	public function test_binary_search_answer_fixed() {
		$disabled = array( 'a/a.php', 'b/b.php', 'c/c.php', 'd/d.php' );
		$result   = WCD_Wizard::binary_search_next_round( $disabled, 'fixed' );
		$this->assertSame( array( 'a/a.php', 'b/b.php' ), $result );
	}

	public function test_binary_search_answer_broken() {
		$disabled = array( 'a/a.php', 'b/b.php', 'c/c.php', 'd/d.php' );
		$result   = WCD_Wizard::binary_search_next_round( $disabled, 'broken' );
		$this->assertSame( array( 'c/c.php', 'd/d.php' ), $result );
	}

	public function test_binary_search_odd_count() {
		$disabled = array( 'a/a.php', 'b/b.php', 'c/c.php', 'd/d.php', 'e/e.php' );

		$fixed  = WCD_Wizard::binary_search_next_round( $disabled, 'fixed' );
		$this->assertCount( 2, $fixed, 'fixed: floor(5/2) = 2 re-enabled (still candidates)' );

		$broken = WCD_Wizard::binary_search_next_round( $disabled, 'broken' );
		$this->assertCount( 3, $broken, 'broken: ceil(5/2) = 3 remaining' );
	}

	public function test_binary_search_single_plugin_terminal() {
		// With a single candidate, the caller detects terminal; the function returns [] for
		// 'fixed' (floor(1/2) = 0) signalling we've gone past the culprit, and [plugin] for
		// 'broken'. Caller should check count() before calling — this verifies the math.
		$plugin = array( 'suspect/suspect.php' );
		$this->assertSame( array(), WCD_Wizard::binary_search_next_round( $plugin, 'fixed' ) );
		$this->assertSame( $plugin, WCD_Wizard::binary_search_next_round( $plugin, 'broken' ) );
	}

	public function test_binary_search_empty_input() {
		$this->assertSame( array(), WCD_Wizard::binary_search_next_round( array(), 'fixed' ) );
		$this->assertSame( array(), WCD_Wizard::binary_search_next_round( array(), 'broken' ) );
	}

	// -------------------------------------------------------------------------
	// sequential_next_suspect
	// -------------------------------------------------------------------------

	public function test_sequential_answer_fixed_returns_first_suspect() {
		$suspects = array( 'a/a.php', 'b/b.php', 'c/c.php' );
		$result   = WCD_Wizard::sequential_next_suspect( $suspects, 'fixed' );
		$this->assertSame( array( 'a/a.php' ), $result );
	}

	public function test_sequential_answer_broken_advances_to_next() {
		$suspects = array( 'a/a.php', 'b/b.php', 'c/c.php' );
		$result   = WCD_Wizard::sequential_next_suspect( $suspects, 'broken' );
		$this->assertSame( array( 'b/b.php', 'c/c.php' ), $result );
	}

	public function test_sequential_exhausted_suspects_returns_empty() {
		// Last suspect cleared — nothing left to test.
		$result = WCD_Wizard::sequential_next_suspect( array( 'a/a.php' ), 'broken' );
		$this->assertSame( array(), $result );
	}

	public function test_sequential_empty_input() {
		$this->assertSame( array(), WCD_Wizard::sequential_next_suspect( array(), 'fixed' ) );
	}
}
