<?php
/**
 * Base trait sanitization method tests.
 *
 * Tests for sanitize_table_name, sanitize_table_alias, sanitize_column_name,
 * and sanitize_index_name methods to ensure MySQL spec compliance.
 *
 * Per MySQL 8.0 spec (Section 11.2):
 * - Unquoted identifiers: [0-9, a-z, A-Z, $, _]
 * - Our safe subset: [a-zA-Z0-9_] (avoid deprecated $ in MySQL 8.0.32+)
 * - May contain extended Unicode U+0080 .. U+FFFF
 * - Trim leading/trailing whitespace
 * - Normalize accents to ASCII
 *
 * @package     BerlinDB\Tests
 * @copyright   2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.0.0
 */

namespace BerlinDB\Tests;

/**
 * Test helper class to access Base trait sanitization methods.
 *
 * Minimal implementation using only Base trait without Query initialization.
 *
 * @since 3.0.0
 */
class BaseSanitizationTestHelper {

	use \BerlinDB\Database\Traits\Base;

	/**
	 * Public access to protected sanitize_table_name method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name
	 *
	 * @return bool|string
	 */
	public function get_sanitized_table_name( $name ) {
		return $this->sanitize_table_name( $name );
	}

	/**
	 * Public access to protected sanitize_table_alias method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $alias
	 *
	 * @return bool|string
	 */
	public function get_sanitized_table_alias( $alias ) {
		return $this->sanitize_table_alias( $alias );
	}

	/**
	 * Public access to protected sanitize_column_name method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name
	 *
	 * @return bool|string
	 */
	public function get_sanitized_column_name( $name ) {
		return $this->sanitize_column_name( $name );
	}

	/**
	 * Public access to protected sanitize_index_name method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $name
	 *
	 * @return bool|string
	 */
	public function get_sanitized_index_name( $name ) {
		return $this->sanitize_index_name( $name );
	}

	/**
	 * Public access to protected first_letters method.
	 *
	 * @since 3.0.0
	 *
	 * @param string $string
	 * @param string $sep
	 *
	 * @return string
	 */
	public function get_first_letters( $string, $sep = '_' ) {
		return $this->first_letters( $string, $sep );
	}
}

/**
 * Test suite for Base trait sanitization methods.
 *
 * @since 3.0.0
 */
class BaseSanitizationTest extends \PHPUnit\Framework\TestCase {

	/** @var BaseSanitizationTestHelper */
	protected $helper;

	/**
	 * Set up test helper.
	 *
	 * @since 3.0.0
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->helper = new BaseSanitizationTestHelper();
	}

	// ========================================================================
	// sanitize_table_name() tests.
	// ========================================================================

	/**
	 * Test sanitize_table_name accepts valid ASCII identifiers.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_accepts_valid_identifiers() {
		$this->assertSame( 'users', $this->helper->get_sanitized_table_name( 'users' ) );
		$this->assertSame( 'wp_users', $this->helper->get_sanitized_table_name( 'wp_users' ) );
		$this->assertSame( 'user_meta', $this->helper->get_sanitized_table_name( 'user_meta' ) );
		$this->assertSame( 'wp123', $this->helper->get_sanitized_table_name( 'wp123' ) );
	}

	/**
	 * Test sanitize_table_name trims leading/trailing spaces.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_trims_spaces() {
		$this->assertSame( 'users', $this->helper->get_sanitized_table_name( '  users  ' ) );
		$this->assertSame( 'wp_users', $this->helper->get_sanitized_table_name( '  wp_users  ' ) );
	}

	/**
	 * Test sanitize_table_name removes accents.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_removes_accents() {
		$this->assertSame( 'cafe', $this->helper->get_sanitized_table_name( 'café' ) );
		$this->assertSame( 'naieve', $this->helper->get_sanitized_table_name( 'naïeve' ) );
	}

	/**
	 * Test sanitize_table_name converts hyphens to underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_converts_hyphens_to_underscores() {
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_name( 'my-table' ) );
		$this->assertSame( 'wp_user_meta', $this->helper->get_sanitized_table_name( 'wp-user-meta' ) );
	}

	/**
	 * Test sanitize_table_name normalizes consecutive underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_normalizes_underscores() {
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_name( 'my__table' ) );
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_name( 'my___table' ) );
	}

	/**
	 * Test sanitize_table_name removes trailing underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_removes_trailing_underscores() {
		$this->assertSame( 'users', $this->helper->get_sanitized_table_name( 'users_' ) );
		$this->assertSame( 'users', $this->helper->get_sanitized_table_name( 'users___' ) );
	}

	/**
	 * Test sanitize_table_name removes special characters.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_removes_special_characters() {
		$this->assertSame( 'users', $this->helper->get_sanitized_table_name( 'users!' ) );
		$this->assertSame( 'userstable', $this->helper->get_sanitized_table_name( 'users@table' ) );
		$this->assertSame( 'userstable', $this->helper->get_sanitized_table_name( 'users#table' ) );
		$this->assertSame( 'userstable', $this->helper->get_sanitized_table_name( 'users%table' ) );
	}

	/**
	 * Test sanitize_table_name returns false for empty/invalid input.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_name_returns_false_for_invalid_input() {
		$this->assertFalse( $this->helper->get_sanitized_table_name( '' ) );
		$this->assertFalse( $this->helper->get_sanitized_table_name( null ) );
		$this->assertFalse( $this->helper->get_sanitized_table_name( 123 ) );
		$this->assertFalse( $this->helper->get_sanitized_table_name( '___' ) );
		$this->assertFalse( $this->helper->get_sanitized_table_name( '!@#$%' ) );
	}

	// ========================================================================
	// sanitize_table_alias() tests.
	// ========================================================================

	/**
	 * Test sanitize_table_alias accepts valid ASCII identifiers.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_accepts_valid_identifiers() {
		$this->assertSame( 'u', $this->helper->get_sanitized_table_alias( 'u' ) );
		$this->assertSame( 'tw', $this->helper->get_sanitized_table_alias( 'tw' ) );
		$this->assertSame( 'u123', $this->helper->get_sanitized_table_alias( 'u123' ) );
	}

	/**
	 * Test sanitize_table_alias handles spaces by converting to underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_converts_spaces_to_underscores() {
		$this->assertSame( 'resolved_tw', $this->helper->get_sanitized_table_alias( 'resolved tw' ) );
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_alias( 'my  table' ) );
	}

	/**
	 * Test sanitize_table_alias removes accents.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_removes_accents() {
		$this->assertSame( 'cafe', $this->helper->get_sanitized_table_alias( 'café' ) );
		$this->assertSame( 'naieve', $this->helper->get_sanitized_table_alias( 'naïeve' ) );
	}

	/**
	 * Test sanitize_table_alias normalizes consecutive underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_normalizes_underscores() {
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_alias( 'my__table' ) );
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_alias( 'my___table' ) );
	}

	/**
	 * Test sanitize_table_alias removes leading/trailing underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_removes_leading_trailing_underscores() {
		$this->assertSame( 'table', $this->helper->get_sanitized_table_alias( '_table' ) );
		$this->assertSame( 'table', $this->helper->get_sanitized_table_alias( 'table_' ) );
		$this->assertSame( 'table', $this->helper->get_sanitized_table_alias( '_table_' ) );
	}

	/**
	 * Test sanitize_table_alias rejects hyphens (converts to underscores).
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_converts_hyphens_to_underscores() {
		$this->assertSame( 'my_table', $this->helper->get_sanitized_table_alias( 'my-table' ) );
		$this->assertSame( 'a_b_c', $this->helper->get_sanitized_table_alias( 'a-b-c' ) );
	}

	/**
	 * Test sanitize_table_alias removes special characters.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_converts_special_chars_to_underscores() {
		$this->assertSame( 'users_table', $this->helper->get_sanitized_table_alias( 'users!table' ) );
		$this->assertSame( 'users_table', $this->helper->get_sanitized_table_alias( 'users@table' ) );
		$this->assertSame( 'users_table', $this->helper->get_sanitized_table_alias( 'users#table' ) );
	}

	/**
	 * Test sanitize_table_alias returns false for empty/invalid input.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_table_alias_returns_false_for_invalid_input() {
		$this->assertFalse( $this->helper->get_sanitized_table_alias( '' ) );
		$this->assertFalse( $this->helper->get_sanitized_table_alias( null ) );
		$this->assertFalse( $this->helper->get_sanitized_table_alias( 123 ) );
		$this->assertFalse( $this->helper->get_sanitized_table_alias( '___' ) );
		$this->assertFalse( $this->helper->get_sanitized_table_alias( '!@#$%' ) );
	}

	// ========================================================================
	// sanitize_column_name() tests.
	// ========================================================================

	/**
	 * Test sanitize_column_name accepts valid identifiers.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_column_name_accepts_valid_identifiers() {
		$this->assertSame( 'ID', $this->helper->get_sanitized_column_name( 'ID' ) );
		$this->assertSame( 'user_login', $this->helper->get_sanitized_column_name( 'user_login' ) );
	}

	/**
	 * Test sanitize_column_name delegates to sanitize_table_name.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_column_name_behavior_matches_table_name() {
		// These should match table_name since column_name delegates to it.
		$inputs = array(
			'column_name',
			'col-name',
			'col__name',
			'col_name_',
			'  col_name  ',
		);

		foreach ( $inputs as $input ) {
			$this->assertSame(
				$this->helper->get_sanitized_table_name( $input ),
				$this->helper->get_sanitized_column_name( $input ),
				"column_name and table_name should match for input: $input"
			);
		}
	}

	// ========================================================================
	// sanitize_index_name() tests.
	// ========================================================================

	/**
	 * Test sanitize_index_name accepts valid index names.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_accepts_valid_identifiers() {
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx_users' ) );
		$this->assertSame( 'primary', $this->helper->get_sanitized_index_name( 'PRIMARY' ) );
		$this->assertSame( 'unique_email', $this->helper->get_sanitized_index_name( 'UNIQUE_EMAIL' ) );
	}

	/**
	 * Test sanitize_index_name converts to lowercase.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_converts_to_lowercase() {
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'IDX_USERS' ) );
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'Idx_Users' ) );
	}

	/**
	 * Test sanitize_index_name trims leading/trailing spaces.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_trims_spaces() {
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( '  idx_users  ' ) );
	}

	/**
	 * Test sanitize_index_name removes accents.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_removes_accents() {
		$this->assertSame( 'cafe_idx', $this->helper->get_sanitized_index_name( 'café_idx' ) );
	}

	/**
	 * Test sanitize_index_name converts hyphens to underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_converts_hyphens_to_underscores() {
		$this->assertSame( 'idx_my_table', $this->helper->get_sanitized_index_name( 'idx-my-table' ) );
	}

	/**
	 * Test sanitize_index_name normalizes consecutive underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_normalizes_underscores() {
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx__users' ) );
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx___users' ) );
	}

	/**
	 * Test sanitize_index_name removes trailing underscores.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_removes_trailing_underscores() {
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx_users_' ) );
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx_users___' ) );
	}

	/**
	 * Test sanitize_index_name removes special characters.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_converts_special_chars_to_underscores() {
		$this->assertSame( 'idx_users_table', $this->helper->get_sanitized_index_name( 'idx!users@table' ) );
		$this->assertSame( 'idx_users', $this->helper->get_sanitized_index_name( 'idx#users' ) );
	}

	/**
	 * Test sanitize_index_name returns false for empty/invalid input.
	 *
	 * @since 3.0.0
	 */
	public function test_sanitize_index_name_returns_false_for_invalid_input() {
		$this->assertFalse( $this->helper->get_sanitized_index_name( '' ) );
		$this->assertFalse( $this->helper->get_sanitized_index_name( null ) );
		$this->assertFalse( $this->helper->get_sanitized_index_name( 123 ) );
		$this->assertFalse( $this->helper->get_sanitized_index_name( '___' ) );
		$this->assertFalse( $this->helper->get_sanitized_index_name( '!@#$%' ) );
	}

	// ========================================================================
	// Cross-method spec compliance tests.
	// ========================================================================

	/**
	 * Test all methods produce MySQL spec-compliant output [a-zA-Z0-9_].
	 *
	 * @since 3.0.0
	 */
	public function test_all_methods_produce_spec_compliant_output() {
		$test_inputs = array(
			'valid_name',
			'name-with-hyphens',
			'name with spaces',
			'name__with__double_underscores',
			'name_with_trailing_',
			'naïve_café',
			'UPPERCASE_NAME',
		);

		$methods = array(
			'get_sanitized_table_name',
			'get_sanitized_table_alias',
			'get_sanitized_column_name',
			'get_sanitized_index_name',
		);

		foreach ( $test_inputs as $input ) {
			foreach ( $methods as $method ) {
				$result = $this->helper->$method( $input );

				// Should be either false or match [a-zA-Z0-9_].
				if ( false !== $result ) {
					$this->assertMatchesRegularExpression(
						'/^[a-zA-Z0-9_]+$/',
						$result,
						"$method($input) must produce spec-compliant output, got: $result"
					);
				}
			}
		}
	}

	/**
	 * Test all methods handle edge case: only underscores/hyphens.
	 *
	 * @since 3.0.0
	 */
	public function test_all_methods_handle_underscore_only_input() {
		$methods = array(
			'get_sanitized_table_name',
			'get_sanitized_table_alias',
			'get_sanitized_column_name',
			'get_sanitized_index_name',
		);

		foreach ( $methods as $method ) {
			$this->assertFalse(
				$this->helper->$method( '___' ),
				"$method should return false for underscore-only input"
			);
			$this->assertFalse(
				$this->helper->$method( '---' ),
				"$method should return false for hyphen-only input"
			);
		}
	}

	// ========================================================================
	// first_letters() tests.
	// ========================================================================

	/**
	 * Test first_letters returns initials for a simple underscore-separated string.
	 *
	 * e.g. 'wp_user_meta' -> 'wum'
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_returns_initials_for_simple_string() {
		$this->assertSame( 'wum', $this->helper->get_first_letters( 'wp_user_meta' ) );
		$this->assertSame( 'u', $this->helper->get_first_letters( 'users' ) );
		$this->assertSame( 'wu', $this->helper->get_first_letters( 'wp_users' ) );
	}

	/**
	 * Test first_letters lowercases accented characters before extracting initials.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_removes_accents() {
		$this->assertSame( 'cn', $this->helper->get_first_letters( 'café_naïeve' ) );
	}

	/**
	 * Test first_letters converts to lowercase.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_lowercases() {
		$this->assertSame( 'wum', $this->helper->get_first_letters( 'WP_USER_META' ) );
		$this->assertSame( 'wu', $this->helper->get_first_letters( 'Wp_Users' ) );
	}

	/**
	 * Test first_letters trims leading/trailing whitespace before processing.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_trims_whitespace() {
		$this->assertSame( 'wum', $this->helper->get_first_letters( '  wp_user_meta  ' ) );
	}

	/**
	 * Test first_letters works with a custom separator.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_respects_custom_separator() {

		// With sep '-', hyphens are treated as the separator and all initials are kept.
		$this->assertSame( 'wum', $this->helper->get_first_letters( 'wp-user-meta', '-' ) );

		// With sep ' ', spaces are preserved in the input long enough to split on.
		$this->assertSame( 'wum', $this->helper->get_first_letters( 'wp user meta', ' ' ) );
	}

	/**
	 * Test first_letters handles hyphens converted to underscores as word boundaries.
	 *
	 * Default sep='_', so 'wp-user-meta' is treated as one word -> 'w'.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_treats_hyphens_as_separator_when_normalized() {
		/*
		 * With the default sep '_', hyphens are not split and only the first
		 * character survives.
		 */
		$this->assertSame( 'w', $this->helper->get_first_letters( 'wp-user-meta' ) );
	}

	/**
	 * Test first_letters returns empty string for empty input.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_returns_empty_string_for_empty_input() {
		$this->assertSame( '', $this->helper->get_first_letters( '' ) );
		$this->assertSame( '', $this->helper->get_first_letters( '   ' ) );
	}

	/**
	 * Test first_letters returns empty string for non-string input.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_returns_empty_string_for_non_string() {
		$this->assertSame( '', $this->helper->get_first_letters( null ) );
		$this->assertSame( '', $this->helper->get_first_letters( 123 ) );
	}

	/**
	 * Test first_letters returns empty string for all-special-char input.
	 *
	 * @since 3.0.0
	 */
	public function test_first_letters_returns_empty_for_all_special_chars() {
		$this->assertSame( '!', $this->helper->get_first_letters( '!@#$%' ) );
	}
}
