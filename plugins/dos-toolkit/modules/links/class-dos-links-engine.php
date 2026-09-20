<?php
/**
 * Finding and inserting internal links.
 *
 * This writes into post content, so it works on the raw HTML string rather
 * than round-tripping through DOMDocument. A DOM parse and re-serialise
 * rewrites entities, closes tags the author left open and reorders
 * attributes — harmless when rendering a page, unacceptable when the result
 * is saved back over somebody's post.
 *
 * Instead the markup is walked once to work out which byte ranges are
 * ordinary readable text, and only those are considered. Everything else —
 * inside tags, inside excluded elements, inside shortcodes — is invisible to
 * the matcher and comes out exactly as it went in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Links_Engine {

	/**
	 * Elements whose text is never linked.
	 *
	 * Headings and bold are emphasis the author chose, and a link inside them
	 * competes with it. Lists and tables are scannable structures where a link
	 * on every row reads as noise. The rest would be broken outright.
	 */
	const EXCLUDED = array(
		'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
		'b', 'strong', 'li', 'ul', 'ol', 'dl', 'dt', 'dd',
		'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
		'script', 'style', 'code', 'pre', 'textarea', 'button', 'select', 'option',
		'figcaption', 'label',
	);

	const VOID = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	const LINK_CLASS = 'dos-ilink';

	/* ---------------------------------------------------------------------
	 * Where the readable text is
	 * ------------------------------------------------------------------- */

	/**
	 * Byte ranges of the HTML that are ordinary text outside every excluded
	 * element.
	 *
	 * @return array List of array( offset, length ).
	 */
	public static function text_spans( $html ) {
		$spans = array();
		$stack = array();
		$depth = 0;              // How many excluded elements we are inside.
		$len   = strlen( $html );
		$pos   = 0;

		while ( $pos < $len ) {
			$lt = strpos( $html, '<', $pos );

			if ( false === $lt ) {
				if ( 0 === $depth ) {
					$spans[] = array( $pos, $len - $pos );
				}

				break;
			}

			if ( $lt > $pos && 0 === $depth ) {
				$spans[] = array( $pos, $lt - $pos );
			}

			// Comments, including Gutenberg block delimiters.
			if ( 0 === substr_compare( $html, '<!--', $lt, 4 ) ) {
				$end = strpos( $html, '-->', $lt );
				$pos = false === $end ? $len : $end + 3;

				continue;
			}

			$gt = strpos( $html, '>', $lt );

			if ( false === $gt ) {
				break;
			}

			$raw = substr( $html, $lt + 1, $gt - $lt - 1 );
			$pos = $gt + 1;

			if ( '' === $raw ) {
				continue;
			}

			if ( '/' === $raw[0] ) {
				$name = strtolower( trim( substr( $raw, 1 ) ) );

				// Unwind to the matching open tag. Markup in the wild is not
				// always balanced, so anything left open in between is closed
				// with it rather than leaving the stack wrong for the rest of
				// the document.
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $stack[ $i ] === $name ) {
						for ( $j = count( $stack ) - 1; $j >= $i; $j-- ) {
							if ( in_array( $stack[ $j ], self::EXCLUDED, true ) ) {
								$depth--;
							}

							array_pop( $stack );
						}

						break;
					}
				}

				continue;
			}

			if ( ! preg_match( '/^([a-z0-9]+)/i', $raw, $m ) ) {
				continue;
			}

			$name = strtolower( $m[1] );

			// Self-closing or void: nothing to push.
			if ( '/' === substr( rtrim( $raw ), -1 ) || in_array( $name, self::VOID, true ) ) {
				continue;
			}

			$stack[] = $name;

			if ( in_array( $name, self::EXCLUDED, true ) ) {
				$depth++;
			}
		}

		return $spans;
	}

	/**
	 * Byte ranges occupied by shortcodes. Linking inside one would break it.
	 */
	public static function shortcode_ranges( $html ) {
		$ranges = array();

		if ( preg_match_all( '/\[[^\]\[]+\]/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $match ) {
				$ranges[] = array( $match[1], strlen( $match[0] ) );
			}
		}

		return $ranges;
	}

	private static function in_ranges( $offset, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $offset >= $range[0] && $offset < $range[0] + $range[1] ) {
				return true;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------- */

	/**
	 * Every occurrence of a phrase that could legitimately be linked, in
	 * document order, before any of the rule's limits are applied.
	 *
	 * @return array List of array( 'offset' => int, 'length' => int, 'text' => string ).
	 */
	public static function occurrences( $html, $phrase ) {
		$phrase = trim( (string) $phrase );

		if ( '' === $phrase || '' === trim( (string) $html ) ) {
			return array();
		}

		$found      = array();
		$shortcodes = self::shortcode_ranges( $html );

		// Not \b: a phrase can legitimately end in punctuation, and \b would
		// behave differently depending on the last character.
		$pattern = '/(?<![\w\-])' . self::space_tolerant( $phrase ) . '(?![\w\-])/iu';

		foreach ( self::text_spans( $html ) as $span ) {
			$text = substr( $html, $span[0], $span[1] );

			if ( '' === trim( $text ) ) {
				continue;
			}

			if ( ! preg_match_all( $pattern, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $m[0] as $match ) {
				$offset = $span[0] + $match[1];

				if ( self::in_ranges( $offset, $shortcodes ) ) {
					continue;
				}

				$found[] = array(
					'offset' => $offset,
					'length' => strlen( $match[0] ),
					'text'   => $match[0],
				);
			}
		}

		usort(
			$found,
			function ( $a, $b ) {
				return $a['offset'] - $b['offset'];
			}
		);

		return $found;
	}

	/**
	 * Quote a phrase so each space in it matches any run of whitespace.
	 *
	 * A phrase typed into the settings screen is separated by ordinary
	 * spaces. The same words in a page may not be: editors and pasted text
	 * routinely carry non-breaking spaces, and HTML source wraps lines
	 * wherever it likes, so "open houses in Phoenix" in a post can contain a
	 * newline or a U+00A0 and look identical while matching nothing.
	 *
	 * The alternative — normalising the content before matching — would move
	 * every byte offset after the first substitution, and those offsets are
	 * what the links are spliced in at.
	 */
	public static function space_tolerant( $phrase ) {
		// The phrase can carry exotic spaces too — it was typed or pasted by
		// a person. Rules saved before these were normalised on the way in
		// still hold them, and would otherwise never match anything.
		$phrase = preg_replace( '/[\x{00A0}\x{2007}\x{202F}\x{2009}]/u', ' ', (string) $phrase );

		$quoted = preg_quote( trim( $phrase ), '/' );

		// preg_quote leaves spaces alone, so they are still literal here.
		return preg_replace( '/[ ]+/', '[\s\x{00A0}\x{2007}\x{202F}\x{2009}]+', $quoted );
	}

	/* ---------------------------------------------------------------------
	 * Applying a rule's limits
	 * ------------------------------------------------------------------- */

	/**
	 * Why a rule found the phrase and linked it anyway — or did not.
	 *
	 * A count of zero has several causes that look identical from outside,
	 * and the operator cannot tell a phrase that appears nowhere from one
	 * that appears and was filtered out by a limit they set. This reports
	 * which it was.
	 *
	 * @return array occurrences, placements, reason
	 */
	public static function analyse( $html, array $rule, $post_id ) {
		$out = array(
			'occurrences' => 0,
			'placements'  => 0,
			'reason'      => '',
		);

		if ( (int) $rule['target_id'] === (int) $post_id ) {
			$out['reason'] = 'self';

			return $out;
		}

		$occurrences        = self::occurrences( $html, $rule['phrase'] );
		$out['occurrences'] = count( $occurrences );

		if ( ! $occurrences ) {
			$out['reason'] = 'absent';

			return $out;
		}

		$placements        = self::placements( $html, $rule, $post_id );
		$out['placements'] = count( $placements );

		if ( $placements ) {
			return $out;
		}

		// It is here, and something the operator configured removed it.
		$max = isset( $rule['max_per_page'] ) ? (int) $rule['max_per_page'] : 1;

		if ( $max > 0 && self::linked_phrase_count( $html, $rule['phrase'] ) >= $max ) {
			$out['reason'] = 'already_linked';

			return $out;
		}

		if ( 'skip' === ( isset( $rule['first_instance'] ) ? $rule['first_instance'] : 'link' ) && 1 === count( $occurrences ) ) {
			$out['reason'] = 'first_only';

			return $out;
		}

		if ( 100 > (int) ( isset( $rule['throttle'] ) ? $rule['throttle'] : 100 ) ) {
			$out['reason'] = 'throttled';

			return $out;
		}

		$out['reason'] = 'filtered';

		return $out;
	}

	/**
	 * Narrow a post's occurrences down to the ones a rule would actually
	 * link.
	 *
	 * @param array $rule id, phrase, target_id, max_per_page, first_instance, throttle.
	 */
	public static function placements( $html, array $rule, $post_id ) {
		// A page never links to itself.
		if ( (int) $rule['target_id'] === (int) $post_id ) {
			return array();
		}

		$occurrences = self::occurrences( $html, $rule['phrase'] );

		if ( ! $occurrences ) {
			return array();
		}

		// "skip" leaves the opening mention plain, which reads better when the
		// phrase is the subject of the sentence that introduces the topic.
		if ( 'skip' === ( isset( $rule['first_instance'] ) ? $rule['first_instance'] : 'link' ) ) {
			array_shift( $occurrences );
		}

		$throttle = isset( $rule['throttle'] ) ? (int) $rule['throttle'] : 100;

		if ( $throttle < 100 ) {
			$kept = array();

			foreach ( $occurrences as $index => $occurrence ) {
				if ( self::passes_throttle( $rule['id'], $post_id, $index, $throttle ) ) {
					$kept[] = $occurrence;
				}
			}

			$occurrences = $kept;
		}

		$max = isset( $rule['max_per_page'] ) ? (int) $rule['max_per_page'] : 1;

		if ( $max > 0 ) {
			// Links already on the page count against the allowance, whoever
			// put them there. Otherwise a page carrying one link on the
			// phrase gains a second and the limit reads as satisfied.
			$allowance = $max - self::linked_phrase_count( $html, $rule['phrase'] );

			if ( $allowance < 1 ) {
				return array();
			}

			$occurrences = array_slice( $occurrences, 0, $allowance );
		}

		return $occurrences;
	}

	/**
	 * Deterministic thinning.
	 *
	 * The same occurrence must make the same decision every time, or a rule
	 * would link a different set of places each time it ran and the counts
	 * would never settle. A hash of the rule, post and position gives a
	 * stable spread without keeping a record of every coin toss.
	 */
	public static function passes_throttle( $rule_id, $post_id, $index, $throttle ) {
		$throttle = max( 0, min( 100, (int) $throttle ) );

		if ( 100 <= $throttle ) {
			return true;
		}

		if ( 0 >= $throttle ) {
			return false;
		}

		$hash = crc32( $rule_id . ':' . $post_id . ':' . $index );

		return ( $hash % 100 ) < $throttle;
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------- */

	public static function link_markup( $text, $url, $rule_id ) {
		return sprintf(
			'<a href="%s" class="%s" data-dos-link="%d">%s</a>',
			esc_url( $url ),
			esc_attr( self::LINK_CLASS ),
			(int) $rule_id,
			$text
		);
	}

	/**
	 * Choose which candidates to keep when a page has more than it may carry.
	 *
	 * Taking them in document order would hand the whole allowance to
	 * whichever phrases happen to appear near the top, and a phrase already
	 * carrying most of the site's links would keep taking more. Instead the
	 * phrases are ordered by how many links they have placed so far, fewest
	 * first, and given one slot each in turn.
	 *
	 * Every phrase therefore gets its first link on a page before any phrase
	 * gets its second, which is what pulls an unbalanced profile back towards
	 * the middle over repeated runs. Ties break on rule id so the result is
	 * the same every time.
	 *
	 * @param array $grouped rule_id => list of candidates.
	 * @param array $weights rule_id => links already placed site-wide.
	 * @param int   $limit   How many may be kept in total.
	 */
	public static function select( array $grouped, array $weights, $limit ) {
		$order = array_keys( $grouped );

		usort(
			$order,
			function ( $a, $b ) use ( $weights ) {
				$wa = isset( $weights[ $a ] ) ? (int) $weights[ $a ] : 0;
				$wb = isset( $weights[ $b ] ) ? (int) $weights[ $b ] : 0;

				return $wa === $wb ? $a - $b : $wa - $wb;
			}
		);

		$kept  = array();
		$taken = 0;

		while ( $taken < $limit ) {
			$moved = false;

			foreach ( $order as $rule_id ) {
				if ( empty( $grouped[ $rule_id ] ) ) {
					continue;
				}

				$kept[] = array_shift( $grouped[ $rule_id ] );
				$taken++;
				$moved  = true;

				if ( $taken >= $limit ) {
					break;
				}
			}

			if ( ! $moved ) {
				break;
			}
		}

		return $kept;
	}

	/**
	 * Links this module has already placed in a piece of content.
	 */
	public static function existing_count( $html ) {
		return substr_count( (string) $html, 'data-dos-link="' );
	}

	/**
	 * Insert links for a set of rules into one post's content.
	 *
	 * Replacements are made from the end of the string backwards so that each
	 * earlier offset is still valid when it is used.
	 *
	 * @param int $cap Most links one page may carry in total, counting any
	 *                 this module placed on a previous run. 0 for no limit.
	 *
	 * @return array html, added (rule_id => count), capped (bool)
	 */
	public static function apply( $html, array $rules, $post_id, $cap = 0 ) {
		$cap = max( 0, (int) $cap );

		// Links placed on an earlier run count against the allowance, or
		// every pass would add a fresh set.
		$already = self::existing_count( $html );

		if ( $cap && $already >= $cap ) {
			return array( 'html' => $html, 'added' => array(), 'capped' => true );
		}

		$insertions = array();
		$weights    = array();

		foreach ( $rules as $rule ) {
			$url = get_permalink( (int) $rule['target_id'] );

			if ( ! $url ) {
				continue;
			}

			// Weighted on every link carrying the phrase, including ones
			// added by hand — a phrase already linked twenty times is not
			// under-used just because this module did not place them.
			$weights[ (int) $rule['id'] ] = ( isset( $rule['links_made'] ) ? (int) $rule['links_made'] : 0 )
				+ ( isset( $rule['manual_links'] ) ? (int) $rule['manual_links'] : 0 );

			foreach ( self::placements( $html, $rule, $post_id ) as $placement ) {
				$insertions[] = array(
					'offset'  => $placement['offset'],
					'length'  => $placement['length'],
					'text'    => $placement['text'],
					'url'     => $url,
					'rule_id' => (int) $rule['id'],
				);
			}
		}

		if ( ! $insertions ) {
			return array( 'html' => $html, 'added' => array(), 'capped' => false );
		}

		// Two rules can match overlapping text; the earlier rule wins and the
		// overlapping one is dropped rather than producing nested links.
		usort(
			$insertions,
			function ( $a, $b ) {
				return $a['offset'] - $b['offset'];
			}
		);

		$filtered = array();
		$reach    = -1;

		foreach ( $insertions as $insertion ) {
			if ( $insertion['offset'] < $reach ) {
				continue;
			}

			$filtered[] = $insertion;
			$reach      = $insertion['offset'] + $insertion['length'];
		}

		$capped = false;

		if ( $cap && count( $filtered ) > ( $cap - $already ) ) {
			$grouped = array();

			foreach ( $filtered as $insertion ) {
				$grouped[ $insertion['rule_id'] ][] = $insertion;
			}

			$filtered = self::select( $grouped, $weights, $cap - $already );
			$capped   = true;

			usort(
				$filtered,
				function ( $a, $b ) {
					return $a['offset'] - $b['offset'];
				}
			);
		}

		$added = array();

		foreach ( $filtered as $insertion ) {
			$id           = $insertion['rule_id'];
			$added[ $id ] = isset( $added[ $id ] ) ? $added[ $id ] + 1 : 1;
		}

		foreach ( array_reverse( $filtered ) as $insertion ) {
			$html = substr_replace(
				$html,
				self::link_markup( $insertion['text'], $insertion['url'], $insertion['rule_id'] ),
				$insertion['offset'],
				$insertion['length']
			);
		}

		return array( 'html' => $html, 'added' => $added, 'capped' => $capped );
	}

	/**
	 * Links this module has placed in a piece of content, by rule.
	 *
	 * @return array rule_id => count
	 */
	public static function linked_counts( $html ) {
		$counts = array();

		if ( preg_match_all( '#<a\b[^>]*data-dos-link="(\d+)"#i', (string) $html, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id            = (int) $id;
				$counts[ $id ] = isset( $counts[ $id ] ) ? $counts[ $id ] + 1 : 1;
			}
		}

		return $counts;
	}

	/**
	 * Occurrences of a phrase sitting inside a link somebody added by hand.
	 *
	 * The matcher skips these, correctly — the words are already a link. But
	 * they are part of the site's linking profile, and a share worked out
	 * without them describes only what this module did rather than what is
	 * actually on the page.
	 */
	public static function manual_count( $html, $phrase ) {
		return self::linked_phrase_count( $html, $phrase, true );
	}

	/**
	 * Links on this page whose text is the phrase.
	 *
	 * Counting only the ones this module placed would make a per-page limit
	 * mean "links I added" rather than "links on this page", and a page that
	 * already carried a link on the phrase would quietly end up with two.
	 *
	 * @param bool $only_manual Ignore links this module placed.
	 */
	public static function linked_phrase_count( $html, $phrase, $only_manual = false ) {
		$html = (string) $html;

		if ( '' === trim( $html ) || false === stripos( $html, '<a' ) ) {
			return 0;
		}

		if ( ! preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $links, PREG_SET_ORDER ) ) {
			return 0;
		}

		$pattern = '/(?<![\w\-])' . self::space_tolerant( $phrase ) . '(?![\w\-])/iu';
		$count   = 0;

		foreach ( $links as $link ) {
			if ( $only_manual && false !== stripos( $link[1], 'data-dos-link=' ) ) {
				continue;
			}

			$count += preg_match_all( $pattern, wp_strip_all_tags( $link[2] ) );
		}

		return $count;
	}

	/**
	 * Remove links whose rule no longer exists.
	 *
	 * Deleting a phrase leaves its links behind in the content, pointing
	 * somewhere real but belonging to nothing. They are invisible to every
	 * count that works from the rules table, which is how a site's linking
	 * profile quietly stops matching its own reports.
	 *
	 * @param array $valid_ids Rule IDs that still exist.
	 */
	public static function strip_orphans( $html, array $valid_ids ) {
		$valid   = array_map( 'intval', $valid_ids );
		$removed = 0;

		$result = preg_replace_callback(
			'#<a\b[^>]*data-dos-link="(\d+)"[^>]*>(.*?)</a>#is',
			function ( $m ) use ( $valid, &$removed ) {
				if ( in_array( (int) $m[1], $valid, true ) ) {
					return $m[0];
				}

				$removed++;

				return $m[2];
			},
			(string) $html
		);

		return array( 'html' => null === $result ? $html : $result, 'removed' => $removed );
	}

	/**
	 * Strip links this module created, leaving the text behind. Links added
	 * by a person are untouched: only ones carrying the marker attribute are
	 * matched.
	 */
	public static function strip( $html, $rule_id = 0 ) {
		$attribute = $rule_id ? 'data-dos-link="' . (int) $rule_id . '"' : 'data-dos-link="';

		$pattern = $rule_id
			? '#<a\b[^>]*' . preg_quote( $attribute, '#' ) . '[^>]*>(.*?)</a>#is'
			: '#<a\b[^>]*data-dos-link="\d+"[^>]*>(.*?)</a>#is';

		$count  = 0;
		$result = preg_replace( $pattern, '$1', $html, -1, $count );

		return array( 'html' => null === $result ? $html : $result, 'removed' => (int) $count );
	}
}
