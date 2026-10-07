<?php
/**
 * Markup for the city lender grid. Takes plain arrays (no post lookups) so the same code
 * renders on the site and in tools/build_preview.php.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Render {

	const DISCLOSURE = 'Rates are 2025 medians from public HMDA data, not quotes. Contact a lender for current rates and a Loan Estimate. Lenders cannot pay to be listed or ranked.';

	const MIN_LOANS_STEPS = array( 0, 5, 10, 25, 50, 100, 250, 500 );

	public static function type_label( $type ) {
		$map = array(
			'bank'             => __( 'Bank', 'dos-best-lenders' ),
			'credit_union'     => __( 'Credit union', 'dos-best-lenders' ),
			'mortgage_company' => __( 'Mortgage company', 'dos-best-lenders' ),
		);
		return $map[ $type ] ?? $map['mortgage_company'];
	}

	public static function loan_label( $t ) {
		$map = array(
			'conventional' => 'Conventional',
			'fha'          => 'FHA',
			'va'           => 'VA',
			'usda'         => 'USDA',
			'jumbo'        => 'Jumbo',
		);
		return $map[ $t ] ?? ucfirst( $t );
	}

	/** "Oct 2026" from YYYY-MM-DD, or '' when unparseable. */
	public static function month_year( $ymd ) {
		$ts = strtotime( (string) $ymd . ' 12:00:00 UTC' );
		return $ts ? gmdate( 'M Y', $ts ) : '';
	}

	/**
	 * "Google rating 4.9 (212 reviews)" with a star glyph, linked to the Maps listing, plus "as of Mon YYYY".
	 *
	 * Deliberately plain HTML. Do NOT add schema.org Review / AggregateRating (or any JSON-LD) built from
	 * this data: it comes from Google, and marking up third-party ratings as our own is against Google's
	 * structured-data guidelines and would risk a manual action. Keep it visible text only.
	 */
	public static function rating_line( array $l ) {
		if ( ! isset( $l['google_rating'] ) ) {
			return '';
		}
		$n    = (int) ( $l['google_review_count'] ?? 0 );
		$text = sprintf( /* translators: %s: rating like 4.9 */ __( 'Google rating %s', 'dos-best-lenders' ), number_format( (float) $l['google_rating'], 1 ) );
		if ( $n ) {
			$text .= ' ' . sprintf( _n( '(%s review)', '(%s reviews)', $n, 'dos-best-lenders' ), number_format_i18n( $n ) );
		}
		$star  = '<svg class="blnm-star" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2l2.9 6.9 7.1.6-5.4 4.7 1.7 7.1L12 17.6 5.7 21.3l1.7-7.1L2 9.5l7.1-.6z"/></svg>';
		$inner = $star . ' ' . esc_html( $text );
		$out   = '<p class="blnm-rating">';
		$out  .= ! empty( $l['google_maps_url'] )
			? '<a class="blnm-rating-link" href="' . esc_url( $l['google_maps_url'] ) . '" target="_blank" rel="nofollow noopener">' . $inner . '</a>'
			: '<span class="blnm-rating-link">' . $inner . '</span>';
		$as_of = ! empty( $l['rating_as_of'] ) ? self::month_year( $l['rating_as_of'] ) : '';
		if ( '' !== $as_of ) {
			$out .= ' <small class="blnm-asof">' . esc_html( sprintf( /* translators: %s: Mon YYYY */ __( 'as of %s', 'dos-best-lenders' ), $as_of ) ) . '</small>';
		}
		return $out . '</p>';
	}

	/**
	 * "What reviewers mention": 2-3 paraphrased client experiences ("One client said ..."), then our own summary
	 * of the overall pattern, then pros/cons. All of it is our writing; no review text is quoted.
	 * Collapsible; blnm.js closes it on narrow screens.
	 */
	public static function review_block( array $l ) {
		$sum  = (string) ( $l['review_summary'] ?? '' );
		$hl   = (array) ( $l['review_highlights'] ?? array() );
		$pros = (array) ( $l['review_pros'] ?? array() );
		$cons = (array) ( $l['review_cons'] ?? array() );
		if ( '' === $sum && ! $hl && ! $pros && ! $cons ) {
			return '';
		}
		ob_start();
		?>
<details class="blnm-says" open>
<summary><?php esc_html_e( 'What reviewers mention', 'dos-best-lenders' ); ?></summary>
<?php if ( $hl ) : ?>
<ul class="blnm-highlights">
<?php foreach ( $hl as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ( '' !== $sum ) : ?><p class="blnm-says-text"><?php echo esc_html( $sum ); ?></p><?php endif; ?>
<?php if ( $pros ) : ?>
<ul class="blnm-pros" aria-label="<?php esc_attr_e( 'Reviewers liked', 'dos-best-lenders' ); ?>">
<?php foreach ( $pros as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ( $cons ) : ?>
<ul class="blnm-cons" aria-label="<?php esc_attr_e( 'Reviewers disliked', 'dos-best-lenders' ); ?>">
<?php foreach ( $cons as $x ) : ?><li><?php echo esc_html( $x ); ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
</details>
		<?php
		return ob_get_clean();
	}

	/** RateDelta: blue = lower than the local median (better), orange = higher. Always an arrow plus the words. */
	public static function rate_delta( $bps ) {
		if ( null === $bps ) {
			return '';
		}
		if ( 0 === $bps ) {
			return '<span class="blnm-delta blnm-delta-even">' . esc_html__( 'at the median', 'dos-best-lenders' ) . '</span>';
		}
		$down = $bps < 0;
		$icon = '<svg class="blnm-delta-icon" viewBox="0 0 10 10" width="10" height="10" aria-hidden="true" focusable="false"><path fill="currentColor" d="' . ( $down ? 'M0 2h10L5 9z' : 'M0 8h10L5 1z' ) . '"/></svg>';
		$text = ( $down ? '−' : '+' ) . abs( $bps ) . ' bps ' . ( $down ? __( 'below median', 'dos-best-lenders' ) : __( 'above median', 'dos-best-lenders' ) );
		return '<span class="blnm-delta ' . ( $down ? 'blnm-delta-good' : 'blnm-delta-bad' ) . '">' . $icon . esc_html( $text ) . '</span>';
	}

	public static function builder_tag() {
		return '<span class="blnm-builder" title="' . esc_attr__( 'Offered by a home builder to buyers of its new homes. Not an open-market lender, so its rate is left out of the score.', 'dos-best-lenders' ) . '">' . esc_html__( 'Builder lender: new homes only', 'dos-best-lenders' ) . '</span>';
	}

	/**
	 * @param array $city    name, state, county (optional, without the word County), year, updated_at (optional).
	 * @param array $lenders Rows as stored in blnm_lenders_json; each may carry an optional 'url' (profile page).
	 */
	public static function city( array $city, array $lenders, array $nearby = array() ) {
		usort(
			$lenders,
			static function ( $a, $b ) {
				return ( $b['score'] ?? -1 ) <=> ( $a['score'] ?? -1 ) ?: ( $b['loans_2025'] ?? 0 ) <=> ( $a['loans_2025'] ?? 0 );
			}
		);

		$year  = (int) ( $city['year'] ?? 2025 );
		$count = count( $lenders );
		$loans = 0;
		$max   = 0;
		foreach ( $lenders as $l ) {
			$loans += (int) $l['loans_2025'];
			$max    = max( $max, (int) $l['loans_2025'] );
		}
		$steps = array_values( array_filter( self::MIN_LOANS_STEPS, static function ( $s ) use ( $max ) {
			return 0 === $s || $s <= $max;
		} ) );
		// The rating filter and sort only appear once the page's lenders carry Google ratings.
		$rated   = false;
		$ratings = array();
		foreach ( $lenders as $l ) {
			if ( isset( $l['google_rating'] ) && '' !== $l['google_rating'] ) {
				$rated     = true;
				$ratings[] = (float) $l['google_rating'];
			}
		}
		// Only offer rating thresholds that narrow the list without emptying it. If none do, no filter.
		$rating_steps = array();
		foreach ( array( '4.9' => __( '4.9 and up', 'dos-best-lenders' ), '5' => __( '5.0 only', 'dos-best-lenders' ) ) as $min => $label ) {
			$hits = count( array_filter( $ratings, static function ( $r ) use ( $min ) { return $r >= (float) $min; } ) );
			if ( $hits > 0 && $hits < count( $lenders ) ) {
				$rating_steps[ $min ] = $label;
			}
		}
		$name   = trim( $city['name'] . ', ' . $city['state'], ', ' );
		$county = trim( (string) ( $city['county'] ?? '' ) );
		$loc    = $county ? sprintf( /* translators: %s: county */ __( '%s County', 'dos-best-lenders' ), $county ) : $name;

		ob_start();
		?>
<section class="blnm blnm-city" data-blnm-grid data-max-loans="<?php echo (int) $max; ?>">
<header class="blnm-head">
<?php // The theme's H1 already says "Mortgage Lenders in <City>"; a visible H2 repeating it read as a double title. Kept for screen readers and document outline. ?>
<h2 class="blnm-title blnm-sr"><?php echo esc_html( sprintf( /* translators: %s: City, ST */ __( 'Lenders in %s ranked by Local Lending Score', 'dos-best-lenders' ), $name ) ); ?></h2>
<p class="blnm-sub"><?php
		if ( $county ) {
			echo esc_html(
				sprintf(
					/* translators: 1: lender count, 2: county, 3: state, 4: year */
					_n( '%1$s lender in %2$s County, %3$s · %4$d home-purchase loans', '%1$s lenders in %2$s County, %3$s · %4$d home-purchase loans', $count, 'dos-best-lenders' ),
					number_format_i18n( $count ),
					$county,
					$city['state'],
					$year
				)
			);
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: lender count, 2: loan count, 3: year */
					_n( '%1$s lender made %2$s home loans here in %3$d', '%1$s lenders made %2$s home loans here in %3$d', $count, 'dos-best-lenders' ),
					number_format_i18n( $count ),
					number_format_i18n( $loans ),
					$year
				)
			);
		}
		?></p>
</header>

<?php if ( $count ) : ?>
<form class="blnm-filters<?php echo $rating_steps ? ' blnm-filters-rated' : ''; ?>" onsubmit="return false">
<fieldset class="blnm-f blnm-f-loan">
<legend><?php esc_html_e( 'Loan type', 'dos-best-lenders' ); ?></legend>
<div class="blnm-chips">
<?php foreach ( Data_Model::LOAN_TYPES as $t ) : ?>
<button type="button" class="blnm-chip" data-loan="<?php echo esc_attr( $t ); ?>" aria-pressed="false"><?php echo esc_html( self::loan_label( $t ) ); ?></button>
<?php endforeach; ?>
</div>
</fieldset>
<div class="blnm-f">
<label for="blnm-ltype"><?php esc_html_e( 'Lender type', 'dos-best-lenders' ); ?></label>
<select id="blnm-ltype" data-filter="type">
<option value=""><?php esc_html_e( 'All types', 'dos-best-lenders' ); ?></option>
<?php foreach ( Data_Model::LENDER_TYPES as $t ) : ?>
<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( self::type_label( $t ) ); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="blnm-f">
<label for="blnm-min"><?php esc_html_e( 'Minimum loans', 'dos-best-lenders' ); ?></label>
<select id="blnm-min" data-filter="min">
<?php foreach ( $steps as $s ) : ?>
<option value="<?php echo (int) $s; ?>"><?php echo $s ? esc_html( number_format_i18n( $s ) . '+' ) : esc_html__( 'Any', 'dos-best-lenders' ); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php if ( $rating_steps ) : ?>
<div class="blnm-f">
<label for="blnm-rating"><?php esc_html_e( 'Google rating', 'dos-best-lenders' ); ?></label>
<select id="blnm-rating" data-filter="rating">
<option value="0"><?php esc_html_e( 'Any', 'dos-best-lenders' ); ?></option>
<?php foreach ( $rating_steps as $min => $label ) : ?>
<option value="<?php echo esc_attr( $min ); ?>"><?php echo esc_html( $label ); ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<div class="blnm-f">
<label for="blnm-sort"><?php esc_html_e( 'Sort by', 'dos-best-lenders' ); ?></label>
<select id="blnm-sort" data-filter="sort">
<option value="score"><?php esc_html_e( 'Local Lending Score', 'dos-best-lenders' ); ?></option>
<?php if ( $rated ) : ?><option value="rating"><?php esc_html_e( 'Google rating', 'dos-best-lenders' ); ?></option><?php endif; ?>
<option value="volume"><?php esc_html_e( 'Loan volume', 'dos-best-lenders' ); ?></option>
<option value="rate"><?php esc_html_e( 'Lowest median rate', 'dos-best-lenders' ); ?></option>
</select>
</div>
<div class="blnm-f blnm-f-status">
<span class="blnm-count" role="status" aria-live="polite"></span>
<button type="button" class="blnm-reset" hidden><?php esc_html_e( 'Reset filters', 'dos-best-lenders' ); ?></button>
</div>
</form>

<ul class="blnm-grid">
<?php foreach ( $lenders as $l ) : ?>
<?php echo self::card( $l ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside card(). ?>
<?php endforeach; ?>
</ul>
<p class="blnm-empty" hidden><?php esc_html_e( 'No lenders match those filters.', 'dos-best-lenders' ); ?></p>
<?php if ( $count > 24 ) : // Pages normally carry up to 10 lenders; the button only exists for big lists. ?>
<button type="button" class="blnm-more" hidden><?php esc_html_e( 'Show more lenders', 'dos-best-lenders' ); ?></button>
<?php endif; ?>
<?php elseif ( ! empty( $city['reviewed_at'] ) ) : ?>
<?php echo self::no_lenders( $city, $loc, $nearby ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
<?php else : ?>
<p class="blnm-empty"><?php echo esc_html( sprintf( /* translators: %s: "<County> County" or "City, ST" */ __( "We're still checking reviews for lenders in %s.", 'dos-best-lenders' ), $loc ) ); ?></p>
<?php endif; ?>

<p class="blnm-disclosure"><?php echo esc_html( self::DISCLOSURE ); ?></p>
</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Checked, and no lender in the county met the bar: say so plainly, then point to the nearest towns that do
	 * have listed lenders. Wording is fixed here so every empty page reads the same.
	 */
	public static function no_lenders( array $city, $loc, array $nearby ) {
		$name  = (string) ( $city['name'] ?? '' );
		$ts    = strtotime( (string) $city['reviewed_at'] );
		$asof  = $ts ? wp_date( 'F Y', $ts ) : '';
		ob_start();
		?>
<div class="blnm-none">
<h2 class="blnm-none-title"><?php echo esc_html( sprintf( /* translators: %s: "<County> County" */ __( 'No lender in %s made our list yet', 'dos-best-lenders' ), $loc ) ); ?></h2>
<p><?php echo esc_html( sprintf( /* translators: 1: "<County> County", 2: "Month YYYY" */ __( 'We only list lenders that have a local branch in %1$s and a Google rating of 4.8 or higher from at least 10 reviews. When we last checked, in %2$s, none here met all three.', 'dos-best-lenders' ), $loc, $asof ) ); ?></p>
<?php if ( $nearby ) : ?>
<p><?php echo esc_html( sprintf( /* translators: %s: city name */ __( 'Buyers in %s often work with a lender based in a nearby town. These are the closest places where qualifying lenders are listed:', 'dos-best-lenders' ), $name ) ); ?></p>
<ul class="blnm-nearby">
<?php foreach ( $nearby as $n ) : ?>
<li class="blnm-nearby-item">
<a class="blnm-nearby-link" href="<?php echo esc_url( $n['url'] ); ?>"><?php echo esc_html( sprintf( /* translators: 1: city, 2: state */ __( 'Mortgage lenders in %1$s, %2$s', 'dos-best-lenders' ), $n['city'], $n['state'] ) ); ?></a>
<span class="blnm-nearby-meta"><?php
		$bits = array();
		if ( $n['county'] ) {
			$bits[] = sprintf( /* translators: %s: county */ __( '%s County', 'dos-best-lenders' ), $n['county'] );
		}
		if ( null !== $n['distance_mi'] ) {
			$bits[] = sprintf( /* translators: %d: miles */ _n( 'about %d mile away', 'about %d miles away', max( 1, $n['distance_mi'] ), 'dos-best-lenders' ), max( 1, $n['distance_mi'] ) );
		}
		if ( $n['lender_count'] ) {
			$bits[] = sprintf( /* translators: %d: lenders */ _n( '%d lender listed', '%d lenders listed', $n['lender_count'], 'dos-best-lenders' ), $n['lender_count'] );
		}
		echo esc_html( implode( ' · ', $bits ) );
		?></span>
<?php if ( $n['top_lenders'] ) : ?><span class="blnm-nearby-lenders"><?php echo esc_html( sprintf( /* translators: %s: lender names */ __( 'Including %s', 'dos-best-lenders' ), self::join_names( $n['top_lenders'] ) ) ); ?></span><?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<p class="blnm-none-foot"><?php esc_html_e( 'Ratings and branches change, so we recheck this page regularly. Any lender that qualifies is added automatically.', 'dos-best-lenders' ); ?></p>
</div>
		<?php
		return ob_get_clean();
	}

	private static function join_names( array $names ) {
		if ( count( $names ) < 2 ) {
			return implode( '', $names );
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' ' . __( 'and', 'dos-best-lenders' ) . ' ' . $last;
	}

	public static function card( array $l ) {
		$score = isset( $l['score'] ) && null !== $l['score'] ? (float) $l['score'] : null;
		$tier  = null === $score ? 'na' : ( $score >= 80 ? 'strong' : ( $score >= 60 ? 'solid' : 'fair' ) );
		$rate  = $l['median_rate'] ?? null;
		$city  = $l['city_median_rate'] ?? null;
		$types = array_values( (array) ( $l['loan_types'] ?? array() ) );
		$bps   = ( null !== $rate && null !== $city ) ? (int) round( ( $rate - $city ) * 100 ) : null;
		$nmls  = ! empty( $l['nmls_url'] ) ? $l['nmls_url'] : 'https://www.nmlsconsumeraccess.org/';

		ob_start();
		?>
<li class="blnm-card" data-name="<?php echo esc_attr( strtolower( $l['name'] ) ); ?>" data-type="<?php echo esc_attr( $l['type'] ); ?>" data-loans="<?php echo (int) $l['loans_2025']; ?>" data-score="<?php echo esc_attr( null === $score ? '-1' : (string) $score ); ?>" data-rate="<?php echo esc_attr( null === $rate ? '' : (string) $rate ); ?>" data-products="<?php echo esc_attr( implode( ' ', $types ) ); ?>" data-rating="<?php echo esc_attr( isset( $l['google_rating'] ) ? (string) (float) $l['google_rating'] : '' ); ?>" data-reviews="<?php echo (int) ( $l['google_review_count'] ?? 0 ); ?>">
<div class="blnm-card-top">
<div class="blnm-card-id">
<h3 class="blnm-name"><?php echo esc_html( $l['name'] ); ?></h3>
<span class="blnm-badge blnm-badge-<?php echo esc_attr( $l['type'] ); ?>"><?php echo esc_html( self::type_label( $l['type'] ) ); ?></span>
</div>
<div class="blnm-score blnm-score-<?php echo esc_attr( $tier ); ?>" title="<?php echo esc_attr( null === $score ? __( 'No score (fewer than 5 loans in this county)', 'dos-best-lenders' ) : __( 'Local Lending Score (LLS), 0 to 100', 'dos-best-lenders' ) ); ?>">
<span class="blnm-score-num"><?php echo null === $score ? '&mdash;' : esc_html( (string) round( $score ) ); ?></span>
<span class="blnm-score-label"><abbr title="<?php esc_attr_e( 'Local Lending Score', 'dos-best-lenders' ); ?>"><?php esc_html_e( 'LLS', 'dos-best-lenders' ); ?></abbr></span>
</div>
</div>
<dl class="blnm-stats">
<div><dt><?php esc_html_e( 'Loans in 2025', 'dos-best-lenders' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $l['loans_2025'] ) ); ?></dd></div>
<div><dt><?php esc_html_e( 'Approval', 'dos-best-lenders' ); ?></dt><dd><?php echo null === ( $l['approval_rate'] ?? null ) ? '&mdash;' : esc_html( round( $l['approval_rate'] ) . '%' ); ?></dd></div>
<div><dt><?php esc_html_e( 'Median rate', 'dos-best-lenders' ); ?></dt><dd><?php echo null === $rate ? '&mdash;' : esc_html( number_format( $rate, 2 ) . '%' ); ?>
</dd></div>
</dl>
<?php if ( null !== $bps ) : ?><p class="blnm-delta-row"><?php echo self::rate_delta( $bps ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></p><?php endif; ?>
<?php if ( $types ) : ?>
<ul class="blnm-tags">
<?php foreach ( $types as $t ) : ?><li><?php echo esc_html( self::loan_label( $t ) ); ?></li><?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ( ! empty( $l['is_builder_lender'] ) ) : ?><p class="blnm-builder-wrap"><?php echo self::builder_tag(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></p><?php endif; ?>
<?php echo self::rating_line( $l ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
<?php if ( ! empty( $l['branch_name'] ) || ! empty( $l['branch_address'] ) ) : ?>
<p class="blnm-branch"><?php echo esc_html( implode( ' · ', array_filter( array( $l['branch_name'] ?? '', $l['branch_address'] ?? '' ) ) ) ); ?></p>
<?php endif; ?>
<?php echo self::review_block( $l ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
<p class="blnm-links">
<?php if ( ! empty( $l['url'] ) ) : ?><a class="blnm-link" href="<?php echo esc_url( $l['url'] ); ?>"><?php esc_html_e( 'View lender', 'dos-best-lenders' ); ?></a><?php endif; ?>
<a class="blnm-link" href="<?php echo esc_url( $nmls ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'NMLS lookup', 'dos-best-lenders' ); ?></a>
</p>
</li>
		<?php
		return ob_get_clean();
	}
}
