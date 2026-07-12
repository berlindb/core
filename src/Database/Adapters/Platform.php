<?php
/**
 * Database platform descriptor.
 *
 * @package     BerlinDB\Database\Adapters
 * @copyright   2021-2026 - JJJ and all BerlinDB contributors
 * @license     https://opensource.org/licenses/MIT MIT
 * @since       3.1.0
 */

declare( strict_types = 1 );

namespace BerlinDB\Database\Adapters;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Describes the underlying database product, version, and SQL feature support.
 *
 * A read-only value object (Doctrine calls this layer a "platform"): it names the
 * product ( MySQL / MariaDB / SQLite ) and version, and answers supports() about
 * individual SQL constructs. It does NOT render SQL - that is the job of a future
 * per-engine rendering seam (#220, 4.0); this only lets BerlinDB detect the engine
 * and fail-fast or degrade on a construct the engine cannot run.
 *
 * Named Platform, not "Capabilities" (which collides with WordPress roles/caps and
 * wpdb::has_cap()) nor "Engine" (which is the MySQL STORAGE engine here, ENGINE=).
 *
 * BerlinDB asks the platform NAMED capability questions (has_storage_engines(), ...)
 * rather than a generic supports( 'some_flag' ), so a call site reads as a domain
 * question and the platform - not the caller - owns the vocabulary. Each question
 * answers PERMISSIVELY for an UNKNOWN platform (a custom Connection that provides
 * none) and for the MySQL family, so BerlinDB keeps emitting its MySQL/MariaDB SQL
 * exactly as before; only a recognized engine that genuinely lacks a construct
 * answers false. One question exists per construct BerlinDB actually gates on - add
 * the next when a feature (index hints, FULLTEXT, ...) lands, not before.
 *
 * SQLite note: WordPress Playground's SQLite Database Integration plugin translates
 * much of BerlinDB's MySQL SQL at runtime (AUTO_INCREMENT -> AUTOINCREMENT, REGEXP,
 * SHOW/DESCRIBE via PRAGMA), so BerlinDB does NOT add capability questions for those
 * - gating them off would fight the translator. Only add a question for what it
 * cannot paper over (e.g. SQLite has no storage engines).
 *
 * @since 3.1.0
 */
final class Platform {

	/** Products **************************************************************/

	/**
	 * Recognized database products.
	 *
	 * @since 3.1.0
	 */
	public const MYSQL   = 'mysql';
	public const MARIADB = 'mariadb';
	public const SQLITE  = 'sqlite';
	public const UNKNOWN = 'unknown';

	/** Attributes ************************************************************/

	/**
	 * The database product (one of the product constants).
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private string $product;

	/**
	 * The database server version string (e.g. '8.0.35'); '' when unknown.
	 *
	 * @since 3.1.0
	 * @var   string
	 */
	private string $version;

	/** Construction **********************************************************/

	/**
	 * @since 3.1.0
	 *
	 * @param string $product One of the product constants; anything else becomes UNKNOWN.
	 * @param string $version Server version string. Default ''.
	 */
	public function __construct( string $product = self::UNKNOWN, string $version = '' ) {
		$product = strtolower( trim( $product ) );

		$this->product = in_array( $product, array( self::MYSQL, self::MARIADB, self::SQLITE ), true )
			? $product
			: self::UNKNOWN;

		$this->version = trim( $version );
	}

	/**
	 * Build an UNKNOWN platform - the permissive default (supports everything).
	 *
	 * @since 3.1.0
	 *
	 * @return self
	 */
	public static function unknown(): self {
		return new self( self::UNKNOWN, '' );
	}

	/** Public API ************************************************************/

	/**
	 * Return the database product.
	 *
	 * @since 3.1.0
	 *
	 * @return string One of the product constants.
	 */
	public function product(): string {
		return $this->product;
	}

	/**
	 * Return the database server version string.
	 *
	 * @since 3.1.0
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Return whether this platform is the given product.
	 *
	 * @since 3.1.0
	 *
	 * @param string $product One of the product constants.
	 * @return bool
	 */
	public function is( string $product ): bool {
		return strtolower( trim( $product ) ) === $this->product;
	}

	/**
	 * Return whether the product has been identified (not UNKNOWN).
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function is_known(): bool {
		return self::UNKNOWN !== $this->product;
	}

	/** Capability questions **************************************************/

	/**
	 * Whether the platform has pluggable storage engines (the SQL ENGINE= clause).
	 *
	 * False only for SQLite, which has none. Every other product - including an
	 * UNKNOWN one (a custom adapter that provides no platform) - answers true, so
	 * BerlinDB keeps emitting ENGINE= exactly as before unless it KNOWS the engine
	 * cannot use it.
	 *
	 * @since 3.1.0
	 *
	 * @return bool
	 */
	public function has_storage_engines(): bool {
		return self::SQLITE !== $this->product;
	}
}
