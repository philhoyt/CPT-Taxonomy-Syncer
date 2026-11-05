<?php
/**
 * A configured post type / taxonomy pair.
 *
 * @package CPT_Taxonomy_Syncer
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object for one row of the pairs option.
 *
 * Owns the three meta keys the plugin writes so the concatenation lives in
 * one place, and the lookups every class used to repeat.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- a value object: three key builders plus the static finders.
 */
final class CPT_Tax_Syncer_Pair {
	/**
	 * The custom post type slug.
	 *
	 * @var string
	 */
	public $cpt_slug;

	/**
	 * The taxonomy slug.
	 *
	 * @var string
	 */
	public $taxonomy_slug;

	/**
	 * Whether the taxonomy archive redirects to the linked post.
	 *
	 * @var bool
	 */
	public $enable_redirect;

	/**
	 * Constructor.
	 *
	 * @param string $cpt_slug        The custom post type slug.
	 * @param string $taxonomy_slug   The taxonomy slug.
	 * @param bool   $enable_redirect Whether to redirect the taxonomy archive.
	 */
	public function __construct( string $cpt_slug, string $taxonomy_slug, bool $enable_redirect = false ) {
		$this->cpt_slug        = $cpt_slug;
		$this->taxonomy_slug   = $taxonomy_slug;
		$this->enable_redirect = $enable_redirect;
	}

	/**
	 * Builds a pair from a row of the pairs option.
	 *
	 * @param mixed $row Option row.
	 * @return self|null Null when the row has no slugs.
	 */
	public static function from_row( $row ): ?self {
		if ( ! is_array( $row ) || empty( $row['cpt_slug'] ) || empty( $row['taxonomy_slug'] ) ) {
			return null;
		}

		return new self(
			(string) $row['cpt_slug'],
			(string) $row['taxonomy_slug'],
			! empty( $row['enable_redirect'] )
		);
	}

	/**
	 * Every configured pair.
	 *
	 * @return self[]
	 */
	public static function all(): array {
		$rows  = get_option( CPT_TAX_SYNCER_OPTION_NAME, array() );
		$pairs = array();

		foreach ( (array) $rows as $row ) {
			$pair = self::from_row( $row );
			if ( $pair ) {
				$pairs[] = $pair;
			}
		}

		return $pairs;
	}

	/**
	 * The first pair configured for a post type.
	 *
	 * @param string $cpt_slug Post type slug.
	 * @return self|null
	 */
	public static function for_post_type( string $cpt_slug ): ?self {
		foreach ( self::all() as $pair ) {
			if ( $pair->cpt_slug === $cpt_slug ) {
				return $pair;
			}
		}

		return null;
	}

	/**
	 * The first pair configured for a taxonomy.
	 *
	 * @param string $taxonomy_slug Taxonomy slug.
	 * @return self|null
	 */
	public static function for_taxonomy( string $taxonomy_slug ): ?self {
		foreach ( self::all() as $pair ) {
			if ( $pair->taxonomy_slug === $taxonomy_slug ) {
				return $pair;
			}
		}

		return null;
	}

	/**
	 * The configured pair for an exact post type and taxonomy.
	 *
	 * @param string $cpt_slug      Post type slug.
	 * @param string $taxonomy_slug Taxonomy slug.
	 * @return self|null
	 */
	public static function find( string $cpt_slug, string $taxonomy_slug ): ?self {
		foreach ( self::all() as $pair ) {
			if ( $pair->cpt_slug === $cpt_slug && $pair->taxonomy_slug === $taxonomy_slug ) {
				return $pair;
			}
		}

		return null;
	}

	/**
	 * Every configured taxonomy slug.
	 *
	 * @return string[]
	 */
	public static function taxonomies(): array {
		return array_values( array_unique( wp_list_pluck( self::all(), 'taxonomy_slug' ) ) );
	}

	/**
	 * Every configured post type slug.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return array_values( array_unique( wp_list_pluck( self::all(), 'cpt_slug' ) ) );
	}

	/**
	 * Post meta key holding the linked term ID.
	 *
	 * @return string
	 */
	public function post_meta_key(): string {
		return CPT_TAX_SYNCER_META_PREFIX_TERM . $this->taxonomy_slug;
	}

	/**
	 * Term meta key holding the linked post ID.
	 *
	 * @return string
	 */
	public function term_meta_key(): string {
		return CPT_TAX_SYNCER_META_PREFIX_POST . $this->cpt_slug;
	}

	/**
	 * Post meta key holding the saved order of related posts.
	 *
	 * @return string
	 */
	public function order_meta_key(): string {
		return CPT_TAX_SYNCER_META_PREFIX_ORDER . $this->taxonomy_slug;
	}

	/**
	 * Unique key for instance registries.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->cpt_slug . '_' . $this->taxonomy_slug;
	}

	/**
	 * The pair as an option row.
	 *
	 * @return array
	 */
	public function to_row(): array {
		return array(
			'cpt_slug'        => $this->cpt_slug,
			'taxonomy_slug'   => $this->taxonomy_slug,
			'enable_redirect' => $this->enable_redirect,
		);
	}
}
