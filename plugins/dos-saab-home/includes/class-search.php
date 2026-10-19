<?php
/**
 * The search index: every restaurant, cuisine, dish and city term that has at least one published review.
 *
 * The site already tags each review with these taxonomies (saab_restaurant, saab_cuisine, saab_dish, saab_city), so
 * the search reads them rather than post text. Core search only matches words in titles and content, which cannot
 * tell "Mexican" the cuisine from a review that mentions Mexico, and cannot group suggestions by kind.
 *
 * The index rides along in the homepage markup (it is small: one entry per term) so suggestions appear without a
 * request; GET saab/v1/search answers the same match server-side, plus review titles, for anything else that wants it.
 *
 * @package DOS_SAAB
 */

namespace DOS_SAAB;

defined( 'ABSPATH' ) || exit;

final class Search {

	const TRANSIENT = 'saab_home_search_v1';
	const NS        = 'saab/v1';

	/** Taxonomy => [ group key, group label ]. Order here is the order groups are shown in. */
	const TAXONOMIES = array(
		'saab_restaurant' => array( 'r', 'Restaurants' ),
		'saab_cuisine'    => array( 'c', 'Cuisines' ),
		'saab_dish'       => array( 'd', 'Dishes' ),
		'saab_city'       => array( 'x', 'Cities' ),
	);

	/** Reviews' dishes, read from their titles (see reviews_index()). Cached apart from the terms: it is the big part. */
	const TRANSIENT_REVIEWS = 'saab_home_reviews_v1';

	/**
	 * Entries shown per group. Everything shows a few of each kind, with an "All N dishes" row when there are more;
	 * a single kind (a scope button) shows them all, up to PER_SCOPE, in a scrolling list.
	 */
	const PER_GROUP = 5;
	const PER_SCOPE = 60;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		foreach ( array_keys( self::TAXONOMIES ) as $tax ) {
			add_action( 'created_' . $tax, array( __CLASS__, 'flush' ) );
			add_action( 'edited_' . $tax, array( __CLASS__, 'flush' ) );
			add_action( 'delete_' . $tax, array( __CLASS__, 'flush' ) );
		}
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT_REVIEWS );
	}

	/** A review published, unpublished or edited while published changes counts or titles, so the index is rebuilt. */
	public static function on_status( $new, $old, $post ) {
		if ( $post && 'post' === $post->post_type && ( 'publish' === $new || 'publish' === $old ) ) {
			self::flush();
		}
	}

	/** Group key => label, for the taxonomies this site actually has. */
	public static function groups() {
		$out = array();
		foreach ( self::TAXONOMIES as $tax => $g ) {
			if ( taxonomy_exists( $tax ) ) {
				$out[ $g[0] ] = $g[1];
			}
		}
		return $out;
	}

	/**
	 * @return array[] Each { n: name, g: group key, u: url, s: sub-line, k: review count }. Cached 12 hours.
	 */
	public static function index() {
		$hit = get_transient( self::TRANSIENT );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = array();
		foreach ( self::TAXONOMIES as $tax => $g ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => true,
				)
			);
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $t ) {
				$url = get_term_link( $t );
				if ( is_wp_error( $url ) ) {
					// A taxonomy without public archives still searches: fall back to core search for the name.
					$url = add_query_arg( 's', rawurlencode( $t->name ), home_url( '/' ) );
				}
				$out[] = array(
					'n' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
					'g' => $g[0],
					'u' => $url,
					's' => self::sub_line( $tax, $t ),
					'k' => (int) $t->count,
				);
			}
		}
		set_transient( self::TRANSIENT, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * One Dishes entry per published review, the dish read from its title.
	 *
	 * The saab_dish taxonomy is sparse (on 2026-10-19 "Pizza" was on 2 reviews while a dozen reviews were about
	 * pizza), but the titles follow one pattern, "<dish> at <restaurant> – <date>", so the dish is always there. Each
	 * entry links to the review and names the restaurant underneath. Cached 12 hours; served by GET saab/v1/index and
	 * loaded by the search box on first use, so the homepage markup stays small however many reviews there are.
	 *
	 * @return array[] Same shape as index() entries, g = 'd', k = 0.
	 */
	public static function reviews_index() {
		$hit = get_transient( self::TRANSIENT_REVIEWS );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$posts = get_posts(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
			)
		);
		$has_r = taxonomy_exists( 'saab_restaurant' );
		$out   = array();
		foreach ( $posts as $p ) {
			$title = html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' );
			$dish  = self::dish_part( $title );
			if ( '' === $dish ) {
				continue;
			}
			$sub = '';
			if ( $has_r ) {
				$r = get_the_terms( $p, 'saab_restaurant' );
				if ( $r && ! is_wp_error( $r ) ) {
					$sub = html_entity_decode( $r[0]->name, ENT_QUOTES, 'UTF-8' );
				}
			}
			if ( '' === $sub ) {
				$sub = self::place_part( $title );
			}
			$out[] = array(
				'n' => $dish,
				'g' => 'd',
				'u' => get_permalink( $p ),
				's' => $sub,
				'k' => 0,
			);
		}
		set_transient( self::TRANSIENT_REVIEWS, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/** Terms and review dishes together: what the search box matches against once it has loaded everything. */
	public static function full_index() {
		return array_merge( self::index(), self::reviews_index() );
	}

	/** A title without its trailing date: "X at Y – March 8, 2026" → "X at Y". */
	private static function undated( $title ) {
		$t = trim( preg_replace( '/\s+/u', ' ', (string) $title ) );
		return trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+[^\x{2013}\x{2014}]*\d{4}\s*$/u', '', $t ) );
	}

	/**
	 * The dish in a review title: everything before the first " at ", with the date and a leading "Breakfast at …
	 * — " style prefix handled. "The Meaty Pizza at Greek Islands in Richland – September 11, 2026" → "The Meaty
	 * Pizza". A title with no " at " is all dish ("Mashed Potatoes & Turkey Gravy – August 27, 2026"). When the title
	 * leads with the place ("Breakfast at Hill's Restaurant — Pulled Pork Benedict"), the part after the dash wins.
	 */
	public static function dish_part( $title ) {
		$t = self::undated( $title );
		if ( preg_match( '/^(.+?)\s+at\s+(.+?)\s+[\x{2013}\x{2014}]\s+(.+)$/iu', $t, $m ) ) {
			return trim( $m[3] );
		}
		$parts = preg_split( '/\s+at\s+/i', $t, 2 );
		return trim( $parts[0] );
	}

	/** The place in a review title, for the grey line when the review has no restaurant tag: "Greek Islands in Richland". */
	public static function place_part( $title ) {
		$t     = self::undated( $title );
		$parts = preg_split( '/\s+at\s+/i', $t, 2 );
		if ( count( $parts ) < 2 ) {
			return '';
		}
		$place = preg_split( '/\s+[\x{2013}\x{2014}]\s+/u', $parts[1], 2 );
		return trim( $place[0] );
	}

	/** The grey second line: a dish names its restaurant (when one review says it), everything else its review count. */
	private static function sub_line( $tax, $term ) {
		if ( 'saab_dish' === $tax && taxonomy_exists( 'saab_restaurant' ) ) {
			$ids = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array( array( 'taxonomy' => $tax, 'terms' => $term->term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			if ( $ids ) {
				$r = wp_get_post_terms( $ids[0], 'saab_restaurant', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $r ) && $r ) {
					return html_entity_decode( $r[0], ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		return self::reviews( (int) $term->count );
	}

	public static function reviews( $n ) {
		/* translators: %d: number of reviews */
		return sprintf( _n( '%d review', '%d reviews', $n, 'dos-saab-home' ), $n );
	}

	/** Lower case, accents off, curly quotes straightened, so "kc's" finds "KC’s". */
	public static function fold( $s ) {
		$s = str_replace( array( '’', '‘', '`' ), "'", (string) $s );
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		return trim( preg_replace( '/\s+/', ' ', strtolower( $s ) ) );
	}

	/**
	 * Matches $q against the index. The same rules run in assets/saab-home.js; keep the two in step.
	 *
	 * Rank: name starts with the query, then a word in the name starts with it, then it appears anywhere; ties go to
	 * the term with more reviews, then the name.
	 *
	 * @param array[] $index Entries from index().
	 * @param string  $q     What was typed. Under 2 characters matches nothing.
	 * @param string  $scope A group key (r, c, d, x) or '' for all.
	 * @param int     $per   Most entries per group.
	 * @return array[] Each { key, label, total, items[] } (total counts every match, items at most $per), groups in
	 *                 TAXONOMIES order, empty groups left out.
	 */
	public static function match( array $index, $q, $scope = '', $per = self::PER_GROUP ) {
		$q = self::fold( $q );
		if ( strlen( $q ) < 2 ) {
			return array();
		}
		$by = array();
		foreach ( $index as $e ) {
			if ( '' !== $scope && $e['g'] !== $scope ) {
				continue;
			}
			$n   = self::fold( $e['n'] );
			$pos = strpos( $n, $q );
			if ( false === $pos ) {
				continue;
			}
			$rank = 0 === $pos ? 0 : ( ' ' === $n[ $pos - 1 ] ? 1 : 2 );
			$by[ $e['g'] ][] = array( $rank, $e );
		}
		$labels = array();
		foreach ( self::TAXONOMIES as $g ) {
			$labels[ $g[0] ] = $g[1];
		}
		$out = array();
		foreach ( $labels as $key => $label ) {
			if ( empty( $by[ $key ] ) ) {
				continue;
			}
			$rows = $by[ $key ];
			usort(
				$rows,
				function ( $a, $b ) {
					return array( $a[0], -$a[1]['k'], $a[1]['n'] ) <=> array( $b[0], -$b[1]['k'], $b[1]['n'] );
				}
			);
			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'total' => count( $rows ),
				'items' => array_column( array_slice( $rows, 0, max( 1, (int) $per ) ), 1 ),
			);
		}
		return $out;
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'     => array( 'type' => 'string', 'required' => true ),
					'scope' => array( 'type' => 'string', 'default' => '', 'enum' => array( '', 'r', 'c', 'd', 'x' ) ),
				),
				'callback'            => array( __CLASS__, 'rest_search' ),
			)
		);
		register_rest_route(
			self::NS,
			'/index',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_index' ),
			)
		);
	}

	/** Everything the search box matches against: terms plus review dishes. Loaded once, on first use of the box. */
	public static function rest_index() {
		$res = rest_ensure_response( self::full_index() );
		$res->header( 'Cache-Control', 'public, max-age=900' );
		return $res;
	}

	public static function rest_search( \WP_REST_Request $req ) {
		$q      = sanitize_text_field( (string) $req['q'] );
		$scope  = (string) $req['scope'];
		$groups = self::match( self::full_index(), $q, $scope, '' === $scope ? self::PER_GROUP : self::PER_SCOPE );
		$posts  = array();
		if ( strlen( self::fold( $q ) ) >= 2 && '' === $scope ) {
			$found = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					's'              => $q,
					'posts_per_page' => self::PER_GROUP,
					'no_found_rows'  => true,
				)
			);
			foreach ( $found as $p ) {
				$posts[] = array(
					'n' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
					'u' => get_permalink( $p ),
				);
			}
		}
		$res = rest_ensure_response( array( 'groups' => $groups, 'reviews' => $posts ) );
		$res->header( 'Cache-Control', 'public, max-age=300' );
		return $res;
	}
}
