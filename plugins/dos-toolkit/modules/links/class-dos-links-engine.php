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
		$pattern = '/(?<![\w\-])' . preg_quote( $phrase, '/' ) . '(?![\w\-])/iu';

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

	/* ---------------------------------------------------------------------
	 * Applying a rule's limits
	 * ------------------------------------------------------------------- */

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
			$occurrences = array_slice( $occurrences, 0, $max );
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
	 * Insert links for a set of rules into one post's content.
	 *
	 * Replacements are made from the end of the string backwards so that each
	 * earlier offset is still valid when it is used.
	 *
	 * @return array html, added (rule_id => count)
	 */
	public static function apply( $html, array $rules, $post_id ) {
		$insertions = array();
		$added      = array();

		foreach ( $rules as $rule ) {
			$url = get_permalink( (int) $rule['target_id'] );

			if ( ! $url ) {
				continue;
			}

			$placements = self::placements( $html, $rule, $post_id );

			foreach ( $placements as $placement ) {
				$insertions[] = array(
					'offset'  => $placement['offset'],
					'length'  => $placement['length'],
					'text'    => $placement['text'],
					'url'     => $url,
					'rule_id' => (int) $rule['id'],
				);
			}

			if ( $placements ) {
				$added[ (int) $rule['id'] ] = count( $placements );
			}
		}

		if ( ! $insertions ) {
			return array( 'html' => $html, 'added' => array() );
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
				$added[ $insertion['rule_id'] ] = max( 0, $added[ $insertion['rule_id'] ] - 1 );

				continue;
			}

			$filtered[] = $insertion;
			$reach      = $insertion['offset'] + $insertion['length'];
		}

		foreach ( array_reverse( $filtered ) as $insertion ) {
			$html = substr_replace(
				$html,
				self::link_markup( $insertion['text'], $insertion['url'], $insertion['rule_id'] ),
				$insertion['offset'],
				$insertion['length']
			);
		}

		return array( 'html' => $html, 'added' => array_filter( $added ) );
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
