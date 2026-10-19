<?php
/**
 * Dish names: reading the dish out of a review title, and telling whether two dish names are the same dish.
 *
 * The dish board ("The Dishes" on a restaurant page) is built from saab_ryan_dishes, which the n8n syncs fill
 * from review titles, matching dishes by exact name. Titles are written by hand over years, so one dish turns up
 * under several names: Hill's had "Pulled Pork Eggs Benedict" (2021) and "Pulled Pork Benedict" (2025) as two
 * cards. These helpers are pure (no WordPress calls) so tests/test-saab-toolkit-dishes.php can pin them.
 *
 * @package SAAB_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'SAAB_Dishes', false ) ) {
	return;
}

class SAAB_Dishes {

	/** Words that never tell two dishes apart. */
	const STOPWORDS = array( 'the', 'a', 'an', 'and', 'with', 'of', 'on', 'in', 'w' );

	/**
	 * Words that may be added to a dish name without making it a different dish ("Pulled Pork Eggs Benedict" is
	 * "Pulled Pork Benedict"; "Classic Reuben" is "Reuben"). A word outside this list, like "pulled" or "chicken",
	 * does make a different dish when the shorter name is only one or two words ("Pho" vs "Chicken Pho").
	 */
	const FILLER = array( 'egg', 'eggs', 'style', 'plate', 'platter', 'special', 'house', 'classic', 'original', 'famous', 'homemade', 'signature', 'big', 'giant', 'combo', 'meal', 'dish', 'order' );

	/** Lower case, accents and curly quotes off, "&" read as "and", punctuation dropped. */
	public static function normalize( $s ) {
		$s = str_replace( array( '’', '‘', '`' ), "'", (string) $s );
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		$s = strtolower( str_replace( '&', ' and ', $s ) );
		$s = preg_replace( "/'s\b/", '', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/**
	 * The words of a dish name that matter, singularised ("biscuits" → "biscuit"), as a set (word => true).
	 *
	 * @return array<string,bool>
	 */
	public static function words( $name ) {
		$out = array();
		foreach ( explode( ' ', self::normalize( $name ) ) as $w ) {
			if ( '' === $w || in_array( $w, self::STOPWORDS, true ) ) {
				continue;
			}
			if ( strlen( $w ) > 3 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 );
			}
			$out[ $w ] = true;
		}
		return $out;
	}

	/**
	 * Whether two dish names are the same dish.
	 *
	 * Same words (in any order, "&" or "and", plural or not), or one name is the other plus a single extra word,
	 * where the shorter name is specific enough (three words or more) or the extra word is filler. So
	 * "Pulled Pork Benedict" = "Pulled Pork Eggs Benedict", but "Eggs Benedict" ≠ "Pulled Pork Eggs Benedict"
	 * (two extra words) and "Pho" ≠ "Chicken Pho" (one short word, not filler).
	 */
	public static function same( $a, $b ) {
		$wa = self::words( $a );
		$wb = self::words( $b );
		if ( ! $wa || ! $wb ) {
			return false;
		}
		if ( count( $wa ) > count( $wb ) ) {
			list( $wa, $wb ) = array( $wb, $wa );
		}
		if ( array_diff_key( $wa, $wb ) ) {
			return false; // The shorter name has a word the longer one lacks.
		}
		$extra = array_keys( array_diff_key( $wb, $wa ) );
		if ( ! $extra ) {
			return true;
		}
		if ( 1 !== count( $extra ) ) {
			return false;
		}
		return count( $wa ) >= 3 || in_array( $extra[0], self::FILLER, true );
	}

	/**
	 * The dish a review title names: "Pulled Pork Benedict at Hills Restaurant & Lounge in Kennewick – December
	 * 28, 2025" → "Pulled Pork Benedict". A title that leads with the place, "Breakfast at Hill's Restaurant —
	 * Pulled Pork Benedict – March 8, 2026", gives the part after the dash. "… from Bangkok Thai …" counts like
	 * "at". A title with neither is all dish.
	 */
	public static function from_title( $title ) {
		$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );
		$t = trim( preg_replace( '/\s+[\x{2013}\x{2014}-]\s+[^\x{2013}\x{2014}]*\d{4}\s*$/u', '', $t ) ); // Trailing date.
		if ( preg_match( '/^(.+?)\s+(?:at|from)\s+(.+?)\s+[\x{2013}\x{2014}]\s+(.+)$/iu', $t, $m ) ) {
			return trim( $m[3] );
		}
		$parts = preg_split( '/\s+(?:at|from)\s+/i', $t, 2 );
		return trim( $parts[0] );
	}

	/**
	 * Groups a dish list so each dish appears once.
	 *
	 * @param array[] $dishes Each { name, post_id, posts, photo_id?, date? } (date: the post's date, for "newest").
	 * @return array[] One entry per dish: the most-eaten name (ties: the shorter) and all its names ('names'), posts
	 *                 added up, post_id and date of the newest visit, every member's photo_id in newest-first order
	 *                 ('photo_ids') and its members' post IDs ('post_ids').
	 */
	public static function merge( array $dishes ) {
		$groups = array();
		foreach ( $dishes as $d ) {
			if ( empty( $d['name'] ) ) {
				continue;
			}
			$hit = null;
			foreach ( $groups as $i => $g ) {
				foreach ( $g['names'] as $n ) {
					if ( self::same( $n, $d['name'] ) ) {
						$hit = $i;
						break 2;
					}
				}
			}
			if ( null === $hit ) {
				$groups[] = array( 'names' => array(), 'members' => array() );
				$hit      = count( $groups ) - 1;
			}
			$groups[ $hit ]['names'][]   = $d['name'];
			$groups[ $hit ]['members'][] = $d;
		}

		$out = array();
		foreach ( $groups as $g ) {
			$m = $g['members'];
			usort(
				$m,
				function ( $a, $b ) {
					return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) ); // Newest first.
				}
			);
			$by_name = array();
			foreach ( $m as $d ) {
				$by_name[ $d['name'] ] = ( $by_name[ $d['name'] ] ?? 0 ) + max( 1, (int) ( $d['posts'] ?? 1 ) );
			}
			uksort(
				$by_name,
				function ( $a, $b ) use ( $by_name ) {
					return ( $by_name[ $b ] <=> $by_name[ $a ] ) ?: ( strlen( $a ) <=> strlen( $b ) );
				}
			);
			$photos = array();
			foreach ( $m as $d ) {
				if ( array_key_exists( 'photo_id', $d ) ) {
					$photos[] = (int) $d['photo_id'];
				}
			}
			$out[] = array(
				'name'      => array_key_first( $by_name ),
				'names'     => array_keys( $by_name ),
				'post_id'   => (int) $m[0]['post_id'],
				'date'      => (string) ( $m[0]['date'] ?? '' ),
				'posts'     => array_sum( $by_name ),
				'photo_ids' => $photos,
				'post_ids'  => array_values( array_unique( array_map( 'intval', array_column( $m, 'post_id' ) ) ) ),
			);
		}
		return $out;
	}
}
