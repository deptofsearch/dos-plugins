<?php
/**
 * City search matching shared by the no-JS fallback (server) and mirrored in assets/blnm.js (browser).
 *
 * Keep the two in step: both normalise the same way, read the same state names and rank the same way.
 * The query may be "Seattle", "seattle wa", "Seattle, WA", "Seattle, Washington", "Saint Helens", "St. Helens"
 * or a partial prefix of any of them.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Search {

	const STATES = array(
		'AL' => 'alabama', 'AK' => 'alaska', 'AZ' => 'arizona', 'AR' => 'arkansas', 'CA' => 'california',
		'CO' => 'colorado', 'CT' => 'connecticut', 'DE' => 'delaware', 'DC' => 'district of columbia', 'FL' => 'florida',
		'GA' => 'georgia', 'HI' => 'hawaii', 'ID' => 'idaho', 'IL' => 'illinois', 'IN' => 'indiana',
		'IA' => 'iowa', 'KS' => 'kansas', 'KY' => 'kentucky', 'LA' => 'louisiana', 'ME' => 'maine',
		'MD' => 'maryland', 'MA' => 'massachusetts', 'MI' => 'michigan', 'MN' => 'minnesota', 'MS' => 'mississippi',
		'MO' => 'missouri', 'MT' => 'montana', 'NE' => 'nebraska', 'NV' => 'nevada', 'NH' => 'new hampshire',
		'NJ' => 'new jersey', 'NM' => 'new mexico', 'NY' => 'new york', 'NC' => 'north carolina', 'ND' => 'north dakota',
		'OH' => 'ohio', 'OK' => 'oklahoma', 'OR' => 'oregon', 'PA' => 'pennsylvania', 'RI' => 'rhode island',
		'SC' => 'south carolina', 'SD' => 'south dakota', 'TN' => 'tennessee', 'TX' => 'texas', 'UT' => 'utah',
		'VT' => 'vermont', 'VA' => 'virginia', 'WA' => 'washington', 'WV' => 'west virginia', 'WI' => 'wisconsin',
		'WY' => 'wyoming',
	);

	/** Lowercase, no accents or punctuation, single spaces; Saint/Mount/Fort folded to St/Mt/Ft. */
	public static function norm( $s ) {
		$s = (string) $s;
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		$s = strtolower( $s );
		$s = preg_replace( "/[.'\xE2\x80\x99]/u", '', $s );
		$s = trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
		return preg_replace( array( '/\bsaint\b/', '/\bmount\b/', '/\bfort\b/' ), array( 'st', 'mt', 'ft' ), $s );
	}

	/** State codes a normalised string can mean: exact code, exact name, or a name/code prefix of $min_prefix+ letters. */
	public static function resolve_states( $s, $min_prefix ) {
		if ( '' === $s ) {
			return array();
		}
		$code = strtoupper( $s );
		if ( isset( self::STATES[ $code ] ) ) {
			return array( $code );
		}
		$out = array();
		foreach ( self::STATES as $abbr => $name ) {
			if ( $s === $name ) {
				return array( $abbr );
			}
			if ( strlen( $s ) >= $min_prefix && 0 === strpos( $name, $s ) ) {
				$out[] = $abbr;
			}
		}
		return $out;
	}

	/** Ways to read a query: list of [ city_norm, state_codes|null ]. */
	public static function readings( $raw ) {
		$raw = (string) $raw;
		$out = array();
		$pos = strpos( $raw, ',' );
		if ( false !== $pos ) {
			$city  = self::norm( substr( $raw, 0, $pos ) );
			$state = self::norm( substr( $raw, $pos + 1 ) );
			if ( '' === $city ) {
				return $out;
			}
			if ( '' === $state ) {
				$out[] = array( $city, null );
			} else {
				$st = self::resolve_states( $state, 1 );
				if ( $st ) {
					$out[] = array( $city, $st );
				}
			}
			return $out;
		}
		$norm = self::norm( $raw );
		if ( '' === $norm ) {
			return $out;
		}
		$out[]  = array( $norm, null );
		$tokens = explode( ' ', $norm );
		for ( $i = 1, $n = count( $tokens ); $i < $n; $i++ ) {
			$st = self::resolve_states( implode( ' ', array_slice( $tokens, $i ) ), 3 );
			if ( $st ) {
				$out[] = array( implode( ' ', array_slice( $tokens, 0, $i ) ), $st );
			}
		}
		return $out;
	}

	/**
	 * Rank published cities against a query. $rows are Rest::index() rows (n city, s state, u url).
	 * Each hit is the row plus 'rank': 0/1 exact city (1 = no state given), 2/3 prefix, 4/5 later word starts with it.
	 * Hits with a state in the query rank ahead of the same match without one.
	 */
	public static function match( array $rows, $raw ) {
		$readings = self::readings( $raw );
		$hits     = array();
		if ( ! $readings ) {
			return $hits;
		}
		foreach ( $rows as $r ) {
			$k    = self::norm( $r['n'] );
			$best = null;
			foreach ( $readings as $rd ) {
				list( $c, $states ) = $rd;
				if ( null !== $states && ! in_array( $r['s'], $states, true ) ) {
					continue;
				}
				if ( $k === $c ) {
					$tier = 0;
				} elseif ( 0 === strpos( $k, $c ) ) {
					$tier = 2;
				} elseif ( false !== strpos( $k, ' ' . $c ) ) {
					$tier = 4;
				} else {
					continue;
				}
				$rank = $tier + ( null === $states ? 1 : 0 );
				if ( null === $best || $rank < $best ) {
					$best = $rank;
				}
			}
			if ( null !== $best ) {
				$r['rank'] = $best;
				$r['k']    = $k;
				$hits[]    = $r;
			}
		}
		usort(
			$hits,
			static function ( $a, $b ) {
				return $a['rank'] <=> $b['rank'] ?: strcmp( $a['k'], $b['k'] ) ?: strcmp( $a['s'], $b['s'] );
			}
		);
		return $hits;
	}

	/** The one city a query clearly means (one exact match, or no exact match and only one hit); else null. */
	public static function resolve_unique( array $rows, $raw ) {
		$hits  = self::match( $rows, $raw );
		$exact = array_values( array_filter( $hits, static function ( $h ) { return $h['rank'] <= 1; } ) );
		if ( 1 === count( $exact ) ) {
			return $exact[0];
		}
		if ( ! $exact && 1 === count( $hits ) ) {
			return $hits[0];
		}
		return null;
	}

	/**
	 * No-JS fallback: the search form submits ?s=...&post_type=blnm_city. When exactly one published city matches,
	 * go straight to its page; otherwise fall through to WordPress as before.
	 */
	public static function hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'fallback_redirect' ), 1 );
	}

	public static function fallback_redirect() {
		if ( ! is_search() || is_admin() ) {
			return;
		}
		$pt = get_query_var( 'post_type' );
		if ( Data_Model::CITY !== ( is_array( $pt ) ? reset( $pt ) : $pt ) ) {
			return;
		}
		$q = trim( (string) get_query_var( 's' ) );
		if ( '' === $q ) {
			return;
		}
		$hit = self::resolve_unique( Rest::index(), $q );
		if ( $hit && ! empty( $hit['u'] ) ) {
			wp_safe_redirect( $hit['u'], 302 );
			exit;
		}
	}
}
