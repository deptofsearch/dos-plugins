<?php
/**
 * City page intros written from the city's own listing data.
 *
 * City landing pages carry a hand-pasted intro that is one of a few stock templates, identical on every page except
 * the city name, and some of it is no longer true ("ratings") or goes nowhere (a closing section with no links).
 * On a city view that has cards, this filter swaps exactly those known stock blocks for a short summary of the city
 * (how many places, the common categories and amenities, the busiest neighborhoods) and replaces the dead closing
 * with a link to the state page. It works on the filtered output only: the stored content is never changed.
 *
 * Priority 7 on the_content: after Page_Title (5) and the state takeover (6), before wpautop and shortcodes (10, 11),
 * so the text is still the raw pasted HTML and the match has to be tolerant of how it was stored.
 *
 * Anything that is not a known stock block exactly is left alone: other wording, pages with no intro, the linked
 * closing sections, neighborhood and landmark pages.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class City_Intro {

	const OPTION = 'osn_city_intro';

	/** Whitespace between words: the pasted text has extra spaces, newlines and non-breaking spaces. */
	const WS = '(?:\s|&nbsp;|&#160;|\xC2\xA0)+';

	/** An apostrophe as it may be stored: straight, curly, or the entity forms. */
	const APOS = "(?:'|\xE2\x80\x99|&#8217;|&#0?39;|&#x27;|&rsquo;|&apos;)";

	/** An ampersand as it may be stored. */
	const AMP = '(?:&amp;|&#0?38;|&#x26;|&)';

	/** The city name in the pasted text: matched by position, never by value. */
	const NAME = '[^<>]{1,80}?';

	const P_OPEN = '<p(?:\s[^>]*)?>';
	const H3_OPEN = '<h3(?:\s[^>]*)?>';

	/** The stock sentence on templates A and C. "ratings" stopped being true when 0.6.11 removed rating markup. */
	const STOCK = 'Below is every place we have listed with a patio, deck, rooftop or sidewalk tables, with hours, ratings and directions.';

	/** Amenities the summary may mention, in the order that breaks ties. */
	const AMENITY_ORDER = array( 'happy-hour', 'dog-friendly', 'brunch', 'live-music', 'rooftop', 'fire-pit', 'waterfront' );

	public static function hooks() {
		add_filter( 'the_content', array( __CLASS__, 'filter' ), 7 );
	}

	/** The setting is the rollback switch; the filter lets code force it either way. */
	public static function enabled() {
		return (bool) apply_filters( 'osn_city_intro_enabled', '0' !== (string) get_option( self::OPTION, '1' ) );
	}

	public static function sanitize( $value ) {
		return '0' === (string) $value ? '0' : '1';
	}

	public static function filter( $content ) {
		if ( ! self::enabled() || ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		// Only the page body: not an excerpt built from it, and not a nested the_content call (a theme module or a
		// shortcode filtering other text inside the loop), which would otherwise get its own state link line.
		global $wp_current_filter;
		if ( doing_filter( 'get_the_excerpt' ) || count( array_keys( (array) $wp_current_filter, 'the_content', true ) ) > 1 ) {
			return $content;
		}
		$post   = get_post();
		$queued = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! $queued || (int) $queued->ID !== (int) $post->ID ) {
			return $content;
		}
		// Themify Builder can run the_content more than once: never rewrite our own output.
		if ( false !== strpos( (string) $content, 'osn-intro' ) ) {
			return $content;
		}
		// City views only. Neighborhood and landmark pages have their own wording and are out of scope.
		$ctx = Takeover::context( $post );
		if ( ! $ctx || empty( $ctx['city'] ) || ! empty( $ctx['hood'] ) || ! empty( $ctx['spot'] ) ) {
			return $content;
		}
		$view = Cards::view( $ctx['city'] ); // Memoized: the grid renders from the same view.
		if ( empty( $view['ids'] ) ) {
			return $content;
		}
		$out = self::rewrite( (string) $content, $ctx['city'], $view );
		if ( $out !== $content ) {
			Assets::enqueue(); // .osn-intro is in osn.css; the grid usually enqueues it, but not on every path.
		}
		return $out;
	}

	/* ---------------------------------------------------------------- matching */

	/** Regex source for $text: words separated by tolerant whitespace, apostrophes in any stored form. */
	private static function loose( $text ) {
		$q = preg_quote( $text, '~' );
		$q = str_replace( "'", self::APOS, $q );
		return str_replace( ' ', self::WS, $q );
	}

	private static function pattern_a() {
		return '~' . self::P_OPEN . '\s*' . self::NAME . self::loose( ' has restaurants, bars, cafes and breweries with patios and outdoor tables.' ) . '\s*' . self::loose( self::STOCK ) . '\s*</p>~i';
	}

	private static function pattern_b_heading() {
		return '~' . self::H3_OPEN . '\s*' . self::loose( 'List of Outdoor' ) . self::WS . self::AMP . self::loose( ' Patio Seating in ' ) . self::NAME . '\s*</h3>\s*~i';
	}

	private static function pattern_b_lead() {
		return '~' . self::P_OPEN . '\s*' . self::loose( 'The city of ' ) . self::NAME . self::loose( ' offers a great deal of sunshine and some great places to sit, eat, and drink while enjoying that sunshine.' ) . '\s*</p>~i';
	}

	private static function pattern_b_list() {
		return '~' . self::P_OPEN . '\s*' . self::loose( "If you're looking for a place to sit on a deck, patio, or just any outdoor seating you can find, here is your list:" ) . '\s*</p>\s*~i';
	}

	/** A paragraph that holds the stock sentence after a hand-written opening (template C). Group 1 is everything before it. */
	private static function pattern_c() {
		return '~(' . self::P_OPEN . '(?:(?!</p>|<p[\s>]).)*?)' . self::loose( self::STOCK ) . '~is';
	}

	private static function pattern_dead_closing() {
		$head = self::H3_OPEN . '\s*' . self::loose( 'Outdoor Seating in ' ) . self::NAME . '\s*</h3>\s*';
		$dead = self::P_OPEN . '\s*' . self::loose( "If you're open to other parts of the area for finding outdoor seating on patio or deck, there are plenty of options." ) . '\s*</p>';
		return '~(?:' . $head . ')?' . $dead . '~i';
	}

	/** Does the content have a closing section of any kind (the dead one, or one with links)? */
	private static function has_closing( $content ) {
		return (bool) preg_match( '~' . self::H3_OPEN . '\s*' . self::loose( 'Outdoor Seating in ' ) . '~i', $content )
			|| false !== stripos( $content, 'plenty of options' )
			|| false !== stripos( $content, 'other towns near here' );
	}

	/** preg_replace_callback that returns the subject untouched if the regex engine fails (null). */
	private static function sub( $pattern, $callback, $subject, &$count ) {
		$out = preg_replace_callback( $pattern, $callback, $subject, 1, $count );
		if ( null === $out ) {
			$count = 0;
			return $subject;
		}
		return $out;
	}

	private static function rewrite( $content, \WP_Term $term, array $view ) {
		$sentences = null; // Built on first use: most pages that reach here match something, but not all.
		$text      = function () use ( &$sentences, $term, $view ) {
			if ( null === $sentences ) {
				$sentences = self::sentences( $term, $view );
			}
			return $sentences;
		};
		$para = function () use ( $text ) {
			return '<p class="osn-intro">' . $text() . '</p>';
		};

		$blank = function () {
			return '';
		};
		$out   = $content;
		$done  = false;

		// Template A: one paragraph.
		$n   = 0;
		$out = self::sub( self::pattern_a(), $para, $out, $n );
		$done = $n > 0;

		// Template B: the lead paragraph is replaced; the heading above it and the "here is your list" line below go.
		if ( ! $done ) {
			$out  = self::sub( self::pattern_b_lead(), $para, $out, $n );
			$done = $n > 0;
			if ( $done ) {
				$out = self::sub( self::pattern_b_heading(), $blank, $out, $n );
				$out = self::sub( self::pattern_b_list(), $blank, $out, $n );
			}
		}

		// Template C: keep the hand-written opening, replace only the stock sentence.
		if ( ! $done ) {
			$out = self::sub(
				self::pattern_c(),
				function ( $m ) use ( $text ) {
					return $m[1] . $text();
				},
				$out,
				$n
			);
		}

		// Closing: the dead "plenty of options" section becomes the state link; linked sections stay as they are.
		$line = self::state_line( $term );
		$out  = self::sub(
			self::pattern_dead_closing(),
			function () use ( $line ) {
				return $line;
			},
			$out,
			$n
		);
		if ( 0 === $n && '' !== $line && ! self::has_closing( $out ) ) {
			$out .= "\n" . $line;
		}
		return $out;
	}

	/** The "More places nearby" line, or '' when the city has no published state page. */
	private static function state_line( \WP_Term $term ) {
		$p = Util::parse_city( $term->name );
		if ( ! $p ) {
			return '';
		}
		$name = States::name( $p[1] );
		$page = '' !== $name ? State_Cities::page_for( $p[1] ) : null;
		if ( ! $page || 'publish' !== $page->post_status ) {
			return '';
		}
		$link = '<a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( sprintf( /* translators: %s: state name */ __( 'every city in %s with outdoor seating', 'dos-outdoor-seating' ), $name ) ) . '</a>';
		return '<p class="osn-intro osn-intro--more">' . sprintf( /* translators: %s: link to the state page */ __( 'More places nearby: %s.', 'dos-outdoor-seating' ), $link ) . '</p>';
	}

	/* ---------------------------------------------------------------- text */

	/** "A", "A and B", "A, B and C" for already-escaped items. */
	private static function join_and( array $items ) {
		$last = array_pop( $items );
		if ( ! $items ) {
			return $last;
		}
		/* translators: 1: list of items, 2: the last item */
		return sprintf( __( '%1$s and %2$s', 'dos-outdoor-seating' ), implode( ', ', $items ), $last );
	}

	private static function amenity_phrase( $slug, $n ) {
		$d = 'dos-outdoor-seating';
		switch ( $slug ) {
			case 'happy-hour':
				return _n( 'has a happy hour', 'have a happy hour', $n, $d );
			case 'dog-friendly':
				return _n( 'is dog friendly', 'are dog friendly', $n, $d );
			case 'brunch':
				return _n( 'serves brunch', 'serve brunch', $n, $d );
			case 'live-music':
				return _n( 'has live music', 'have live music', $n, $d );
			case 'rooftop':
				return _n( 'has a rooftop', 'have a rooftop', $n, $d );
			case 'fire-pit':
				return _n( 'has a fire pit', 'have a fire pit', $n, $d );
			case 'waterfront':
				return _n( 'is on the water', 'are on the water', $n, $d );
		}
		return '';
	}

	/**
	 * The summary as escaped HTML text (sentences joined by a space, no wrapping tag). Every fact comes from the
	 * city view and the term cache view() primed: no query per venue.
	 *
	 * @param \WP_Term $term City term.
	 * @param array    $view Cards::view() result for the city.
	 */
	public static function sentences( \WP_Term $term, array $view ) {
		$d    = 'dos-outdoor-seating';
		$ids  = $view['ids'];
		$n    = count( $ids );
		$city = esc_html( Util::city_label( $term->name ) );

		$amen = array();
		$cats = array();
		foreach ( $ids as $id ) {
			foreach ( (array) get_the_terms( $id, Data_Model::AMENITY ) as $t ) {
				if ( $t instanceof \WP_Term ) {
					$amen[ $t->slug ] = ( $amen[ $t->slug ] ?? 0 ) + 1;
				}
			}
			$c = Util::first_term( $id, Data_Model::CATEGORY ); // The category a card shows.
			if ( $c instanceof \WP_Term ) {
				$cats[ $c->term_id ] = array( 'name' => $c->name, 'count' => ( $cats[ $c->term_id ]['count'] ?? 0 ) + 1 );
			}
		}
		$m   = (int) ( $amen['outdoor-seating'] ?? 0 );
		$out = array();

		if ( $n <= 3 ) {
			$names = array();
			foreach ( $ids as $id ) {
				$names[] = esc_html( Util::title( $id ) );
			}
			$count = 1 === $n ? __( 'one place', $d ) : sprintf( /* translators: %s: number of places */ _n( '%s place', '%s places', $n, $d ), number_format_i18n( $n ) );
			/* translators: 1: city, 2: "one place" or "2 places", 3: venue names */
			$out[] = sprintf( __( '%1$s has %2$s listed here: %3$s.', $d ), $city, esc_html( $count ), self::join_and( $names ) );
			if ( $m === $n ) {
				$out[] = 1 === $n ? __( 'It confirms outdoor seating.', $d ) : __( 'All of them confirm outdoor seating.', $d );
			} elseif ( $m > 0 ) {
				/* translators: %s: number of places */
				$out[] = sprintf( _n( '%s of them confirms outdoor seating.', '%s of them confirm outdoor seating.', $m, $d ), number_format_i18n( $m ) );
			}
		} else {
			$num = number_format_i18n( $n );
			if ( $m === $n ) {
				/* translators: 1: city, 2: number of places */
				$out[] = sprintf( __( '%1$s has %2$s places with outdoor seating listed here.', $d ), $city, $num );
			} elseif ( $m > 0 ) {
				/* translators: 1: city, 2: number of places, 3: number that confirm outdoor seating */
				$out[] = sprintf( _n( '%1$s has %2$s places listed here, and %3$s of them confirms outdoor seating.', '%1$s has %2$s places listed here, and %3$s of them confirm outdoor seating.', $m, $d ), $city, $num, number_format_i18n( $m ) );
			} else {
				/* translators: 1: city, 2: number of places */
				$out[] = sprintf( __( '%1$s has %2$s places listed here; none of them confirms outdoor seating yet.', $d ), $city, $num );
			}

			// Categories: only ones with 2 or more venues, and only when there are at least two of them.
			$cats = array_filter(
				$cats,
				function ( $c ) {
					return $c['count'] >= 2;
				}
			);
			if ( count( $cats ) >= 2 ) {
				$out[] = sprintf( /* translators: %s: list like "Bar (5), Cafe (3) and Brewery (2)" */ __( 'Most common: %s.', $d ), self::join_and( self::ranked( $cats, 'name' ) ) );
			}

			// Amenities: the first two with a count, biggest first, ties in list order.
			$have = array();
			foreach ( self::AMENITY_ORDER as $i => $slug ) {
				if ( ! empty( $amen[ $slug ] ) ) {
					$have[] = array( $slug, (int) $amen[ $slug ], $i );
				}
			}
			usort(
				$have,
				function ( $a, $b ) {
					return ( $b[1] <=> $a[1] ) ?: ( $a[2] <=> $b[2] );
				}
			);
			$parts = array();
			foreach ( array_slice( $have, 0, 2 ) as $h ) {
				$parts[] = number_format_i18n( $h[1] ) . ' ' . esc_html( self::amenity_phrase( $h[0], $h[1] ) );
			}
			if ( $parts ) {
				$out[] = self::join_and( $parts ) . '.';
			}

			// Neighborhoods: districts with a venue count, only when there are at least two.
			$dist = array();
			foreach ( (array) ( $view['tree']['districts'] ?? array() ) as $row ) {
				if ( ! empty( $row['count'] ) && isset( $row['term'] ) ) {
					$dist[] = array( 'name' => $row['term']->name, 'count' => (int) $row['count'] );
				}
			}
			if ( count( $dist ) >= 2 ) {
				$out[] = sprintf( /* translators: %s: list like "Downtown (12) and Old Town (8)" */ __( 'By area, %s have the most.', $d ), self::join_and( self::ranked( $dist, 'name' ) ) );
			}
		}
		$out[] = __( 'Each place has its own page with the address and directions.', $d ); // True of every listing; hours and phone are not always known.

		// Pieces that came from data were escaped where they were built; the fixed English has no markup characters.
		return implode( ' ', $out );
	}

	/** Top 3 of [ name, count ] rows, biggest first, ties by name, as escaped "Name (n)" items. */
	private static function ranked( array $rows, $key ) {
		$rows = array_values( $rows );
		usort(
			$rows,
			function ( $a, $b ) use ( $key ) {
				return ( $b['count'] <=> $a['count'] ) ?: ( strcasecmp( $a[ $key ], $b[ $key ] ) ?: strcmp( $a[ $key ], $b[ $key ] ) );
			}
		);
		$items = array();
		foreach ( array_slice( $rows, 0, 3 ) as $r ) {
			$items[] = esc_html( $r[ $key ] ) . ' (' . number_format_i18n( $r['count'] ) . ')';
		}
		return $items;
	}
}
