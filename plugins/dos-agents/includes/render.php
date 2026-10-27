<?php
/**
 * [breanm_agents city="boise-id" initial="12"]: server-rendered agent cards, with client-side search,
 * chip filters and sort (assets/breanm-agents.js). Every card is in the HTML, so crawlers and
 * visitors without JS see all of them; the filter controls stay hidden until the script runs.
 *
 * TODO (after 0.1.0): ItemList JSON-LD for the listed agents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_agents_register_shortcode() {
	add_shortcode( 'breanm_agents', 'dos_agents_shortcode' );
}

function dos_agents_default_disclosure() {
	return __( 'Information only, not a ranking or endorsement. Profile facts come from online or public agent profiles and may be out of date. Review summaries and tags are generated from recent public reviews and checked against them.', 'dos-agents' );
}

/** The time the page is rendered at. Filterable so a test, or a cache-aware setup, can pin it. */
function dos_agents_now() {
	return (int) apply_filters( 'breanm_agents_now', time() );
}

/**
 * Oldest last_review date (YYYY-MM-DD) that still counts as "in the last 12 months" at $now.
 * The date is the site's local one (Settings > General), not UTC: in the evening in Pacific time it
 * is already tomorrow in UTC, which would move the cutoff, and the chip count, a day too early.
 */
function dos_agents_recent_cutoff( $now ) {
	return wp_date( 'Y-m-d', (int) $now - YEAR_IN_SECONDS );
}

function dos_agents_is_recent( $agent, $cutoff ) {
	return '' !== $agent['last_review'] && $agent['last_review'] >= $cutoff;
}

/** How many years back the newest review can be before the card says the reviews on file are old. Filterable. */
function dos_agents_old_reviews_years() {
	return max( 1, (int) apply_filters( 'dos_agents_old_reviews_years', 3 ) );
}

/**
 * Newest last_review date (YYYY-MM-DD) that is still too old to call current: a card whose last
 * review is before this gets the "Reviews on file are from {year} or earlier" label. Same local-date
 * and year-length logic as dos_agents_recent_cutoff(), so the two thresholds agree about "now".
 */
function dos_agents_old_cutoff( $now ) {
	return wp_date( 'Y-m-d', (int) $now - dos_agents_old_reviews_years() * YEAR_IN_SECONDS );
}

function dos_agents_is_old( $agent, $cutoff ) {
	return '' !== $agent['last_review'] && $agent['last_review'] < $cutoff;
}

/** The labelled parts of a review summary, in the order a card prints them. */
function dos_agents_summary_part_labels() {
	return array(
		'buyers'        => __( 'Buyers', 'dos-agents' ),
		'sellers'       => __( 'Sellers', 'dos-agents' ),
		'working_style' => __( 'Working style', 'dos-agents' ),
	);
}

/**
 * wp_enqueue_scripts: puts the assets in <head> on a post or page whose content holds the shortcode,
 * so the stylesheet is there before the cards paint. Content a page builder stores elsewhere is not
 * seen here; the shortcode enqueues them itself as a fallback (they then print in the footer).
 */
function dos_agents_maybe_enqueue() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( $post && has_shortcode( (string) $post->post_content, 'breanm_agents' ) ) {
		dos_agents_enqueue();
	}
}

function dos_agents_enqueue() {
	// Both entry points call this, so ask WordPress rather than keep a flag of our own.
	if ( wp_style_is( 'breanm-agents', 'enqueued' ) ) {
		return;
	}

	// VERSION is the cache-buster: the host's CDN caches ?ver= URLs for a very long time, so
	// bump DOS_AGENTS_VERSION whenever either asset changes.
	$url = plugins_url( 'assets/', DOS_AGENTS_FILE );
	wp_enqueue_style( 'breanm-agents', $url . 'breanm-agents.css', array(), DOS_AGENTS_VERSION );
	wp_enqueue_script(
		'breanm-agents',
		$url . 'breanm-agents.js',
		array(),
		DOS_AGENTS_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}

function dos_agents_note( $text ) {
	return '<p class="breanm-agents breanm-agents__note">' . esc_html( $text ) . '</p>';
}

function dos_agents_shortcode( $atts ) {
	$a = shortcode_atts(
		array(
			'city'    => '',
			'initial' => '12',
		),
		$atts,
		'breanm_agents'
	);

	$payload = dos_agents_get( (string) $a['city'] );
	if ( null === $payload || ! $payload['agents'] ) {
		return dos_agents_note( __( 'Agent information for this city is not available yet.', 'dos-agents' ) );
	}

	dos_agents_enqueue();
	return dos_agents_render( $payload, (int) $a['initial'], dos_agents_now() );
}

/** Tier -> position in the "Local first" order. */
function dos_agents_tier_rank( $tier ) {
	$i = array_search( $tier, dos_agents_tiers(), true );
	return false === $i ? count( dos_agents_tiers() ) : $i;
}

/**
 * The "Local first" order: tier, then sales in the city, most first. Ties keep payload order
 * (decorated with the index because sort stability is not guaranteed before PHP 8).
 */
function dos_agents_sort_local( array $agents ) {
	$rows = array();
	foreach ( array_values( $agents ) as $i => $a ) {
		$rows[] = array( $i, $a );
	}
	usort(
		$rows,
		function ( $x, $y ) {
			return ( dos_agents_tier_rank( $x[1]['tier'] ) <=> dos_agents_tier_rank( $y[1]['tier'] ) )
				?: ( $y[1]['sales_in_city'] <=> $x[1]['sales_in_city'] )
				?: ( $x[0] <=> $y[0] );
		}
	);
	return array_map(
		function ( $r ) {
			return $r[1];
		},
		$rows
	);
}

/**
 * The filter chips for a city's agents, in display order.
 *
 * Review tags first (most agents first), then profile chips. A chip is offered only when the share
 * of agents it matches is within [breanm_agents_chip_min, breanm_agents_chip_max]: a tag nearly
 * everyone has filters nothing, and one almost nobody has filters down to a lone agent. The
 * profile chips ("Office in {City}", Team, reviewed in the last 12 months, 10+ years) follow the
 * same bounds as the tags.
 *
 * @return array[] {key, label, count, group}
 */
function dos_agents_chips( array $agents, $city, $now ) {
	$total = count( $agents );
	if ( ! $total ) {
		return array();
	}
	$min    = (float) apply_filters( 'breanm_agents_chip_min', 0.05 );
	$max    = (float) apply_filters( 'breanm_agents_chip_max', 0.70 );
	$cutoff = dos_agents_recent_cutoff( $now );
	$within = function ( $n ) use ( $total, $min, $max ) {
		$share = $n / $total;
		return $share >= $min && $share <= $max;
	};

	$tags   = array();
	$labels = array();
	$office = 0;
	$team   = 0;
	$recent = 0;
	$senior = 0;
	foreach ( $agents as $a ) {
		$seen = array();
		foreach ( $a['tags'] as $t ) {
			if ( isset( $seen[ $t['slug'] ] ) ) {
				continue;
			}
			$seen[ $t['slug'] ]  = true;
			$tags[ $t['slug'] ]  = ( isset( $tags[ $t['slug'] ] ) ? $tags[ $t['slug'] ] : 0 ) + 1;
			$labels[ $t['slug'] ] = isset( $labels[ $t['slug'] ] ) ? $labels[ $t['slug'] ] : $t['label'];
		}
		$office += 'primary' === $a['tier'] ? 1 : 0;
		$team   += $a['is_team'] ? 1 : 0;
		$recent += dos_agents_is_recent( $a, $cutoff ) ? 1 : 0;
		$senior += $a['years'] >= 10 ? 1 : 0;
	}

	// Most agents first; equal counts keep the order the tags were first seen in.
	$slugs = array_keys( $tags );
	usort(
		$slugs,
		function ( $x, $y ) use ( $tags, $slugs ) {
			return ( $tags[ $y ] <=> $tags[ $x ] ) ?: ( array_search( $x, $slugs, true ) <=> array_search( $y, $slugs, true ) );
		}
	);

	$chips = array();
	foreach ( $slugs as $slug ) {
		if ( $within( $tags[ $slug ] ) ) {
			$chips[] = array(
				'key'   => 't:' . $slug,
				'label' => $labels[ $slug ],
				'count' => $tags[ $slug ],
				'group' => 'tag',
			);
		}
	}

	$profile = array(
		array( 'p:office', sprintf( /* translators: %s: city */ __( 'Office in %s', 'dos-agents' ), $city ), $office ),
		array( 'p:team', __( 'Team', 'dos-agents' ), $team ),
		array( 'p:recent', __( 'Reviewed in the last 12 months', 'dos-agents' ), $recent ),
		array( 'p:years10', __( '10+ years in the industry', 'dos-agents' ), $senior ),
	);
	foreach ( $profile as $p ) {
		if ( $within( $p[2] ) ) {
			$chips[] = array(
				'key'   => $p[0],
				'label' => $p[1],
				'count' => $p[2],
				'group' => 'profile',
			);
		}
	}
	return $chips;
}

/** The filter keys one agent matches, space separated. "p:recent" is worked out in the browser. */
function dos_agents_card_keys( $a ) {
	$keys = array();
	foreach ( $a['tags'] as $t ) {
		$keys[] = 't:' . $t['slug'];
	}
	if ( 'primary' === $a['tier'] ) {
		$keys[] = 'p:office';
	}
	if ( $a['is_team'] ) {
		$keys[] = 'p:team';
	}
	if ( $a['years'] >= 10 ) {
		$keys[] = 'p:years10';
	}
	return implode( ' ', array_unique( $keys ) );
}

/** "Aug 2026" from YYYY-MM-DD. */
function dos_agents_month_year( $ymd ) {
	$ts = strtotime( $ymd . ' 00:00:00 UTC' );
	return $ts ? gmdate( 'M Y', $ts ) : '';
}

/** The area badge text for a tier, or ''. */
function dos_agents_area_badge( $a, $city ) {
	$n = (int) $a['sales_in_city'];
	switch ( $a['tier'] ) {
		case 'primary':
			return sprintf( /* translators: %s: city */ __( 'Office in %s', 'dos-agents' ), $city );
		case 'served_with_sales':
			return sprintf(
				/* translators: 1: city, 2: sales formatted as "12 sales here" */
				__( 'Serves %1$s · %2$s', 'dos-agents' ),
				$city,
				sprintf( /* translators: %s: number of sales */ _n( '%s sale here', '%s sales here', $n, 'dos-agents' ), number_format_i18n( $n ) )
			);
		case 'served':
			return sprintf( /* translators: %s: city */ __( 'Serves %s', 'dos-agents' ), $city );
		case 'served_via_url':
			return sprintf( /* translators: %s: city */ __( 'Serves areas in %s', 'dos-agents' ), $city );
		case 'sales_only':
			return sprintf( /* translators: 1: number of sales, 2: city */ _n( '%1$s sale in %2$s', '%1$s sales in %2$s', $n, 'dos-agents' ), number_format_i18n( $n ), $city );
	}
	return '';
}

/**
 * Other areas the agent serves, leaving out the page's own city and its neighborhoods
 * ("East End, Boise, ID" is Boise as far as this page is concerned).
 *
 * @return string[]
 */
function dos_agents_other_areas( $a, $city, $state ) {
	$target = strtolower( trim( $city . ', ' . $state ) );
	$tail   = ', ' . $target;
	$out    = array();
	foreach ( $a['areas'] as $area ) {
		$l = strtolower( trim( $area ) );
		if ( $l === $target || strtolower( $city ) === $l || substr( $l, -strlen( $tail ) ) === $tail ) {
			continue;
		}
		$out[] = $area;
	}
	return $out;
}

/**
 * "Meridian, ID" for the Office line (display only). The pipeline sometimes leaves the state and zip
 * in the city ("Boise, ID 83716"), or the case off ("meridian"): cut at the first comma and tidy the
 * case. The state is added only when the city came without a comma, so "Meridian, ID 83642" does not
 * turn into "Meridian, ID 83642, ID".
 */
function dos_agents_office_label( $office ) {
	$city      = (string) $office['city'];
	$had_comma = false !== strpos( $city, ',' );
	if ( $had_comma ) {
		$city = substr( $city, 0, strpos( $city, ',' ) );
	}
	$city = ucwords( strtolower( trim( $city ) ) );
	if ( '' !== $city && ! $had_comma && '' !== $office['state'] ) {
		$city .= ', ' . $office['state'];
	}
	return $city;
}

/**
 * The freshness line under the facts: the newest review's month, then what the tags are based on.
 * With a review window the clause says so ("12 reviews from the last 3 years"); without one it
 * keeps the older "the N most recent reviews" wording. No review count means no tags clause.
 */
function dos_agents_fresh_line( $last, $analyzed, $window ) {
	if ( $analyzed <= 0 ) {
		return sprintf( /* translators: %s: month and year */ __( 'Last review %s', 'dos-agents' ), $last );
	}
	if ( $window > 0 ) {
		return sprintf(
			/* translators: 1: month and year, 2: "12 reviews", 3: "3 years" */
			__( 'Last review %1$s · tags based on %2$s from the last %3$s', 'dos-agents' ),
			$last,
			sprintf( /* translators: %s: number of reviews */ _n( '%s review', '%s reviews', $analyzed, 'dos-agents' ), number_format_i18n( $analyzed ) ),
			sprintf( /* translators: %s: number of years */ _n( '%s year', '%s years', $window, 'dos-agents' ), number_format_i18n( $window ) )
		);
	}
	return sprintf(
		/* translators: 1: month and year, 2: number of reviews */
		_n( 'Last review %1$s · tags based on the %2$s most recent review', 'Last review %1$s · tags based on the %2$s most recent reviews', $analyzed, 'dos-agents' ),
		$last,
		number_format_i18n( $analyzed )
	);
}

/**
 * One card <li>. Everything is escaped here.
 *
 * @param int|null $now Unix time the page is rendered at (the old-reviews label comes from it).
 */
function dos_agents_card( $a, $city, $state, $i, $now = null ) {
	// Missing keys become empty values, and an agent with no usable profile link has no card.
	$a = dos_agents_normalise_agent( $a );
	if ( null === $a ) {
		return '';
	}
	$now    = null === $now ? dos_agents_now() : (int) $now;
	$badge  = dos_agents_area_badge( $a, $city );
	$office = dos_agents_office_label( $a['office'] );
	$others = dos_agents_other_areas( $a, $city, $state );
	$tags   = $a['tags'];
	usort(
		$tags,
		function ( $x, $y ) {
			return $y['count'] <=> $x['count'];
		}
	);
	$tags      = array_slice( $tags, 0, 4 );
	$analyzed  = (int) $a['reviews_analyzed'];
	$last      = '' !== $a['last_review'] ? dos_agents_month_year( $a['last_review'] ) : '';
	$old       = '' !== $last && dos_agents_is_old( $a, dos_agents_old_cutoff( $now ) );
	$window    = (int) $a['reviews_window_years'];
	$parts     = array();
	foreach ( dos_agents_summary_part_labels() as $key => $label ) {
		if ( isset( $a['summary_parts'][ $key ] ) ) {
			$parts[ $label ] = $a['summary_parts'][ $key ];
		}
	}
	$fresh     = dos_agents_fresh_line( $last, $analyzed, $window );
	$name_attr = function_exists( 'mb_strtolower' ) ? mb_strtolower( $a['name'] . ' ' . $a['brokerage'], 'UTF-8' ) : strtolower( $a['name'] . ' ' . $a['brokerage'] );
	// The badge already says the number of sales for these two tiers; a stats line would repeat it.
	$show_sales = $a['sales_in_city'] > 0 && ! in_array( $a['tier'], array( 'served_with_sales', 'sales_only' ), true );
	$facts      = array();
	if ( $show_sales ) {
		$facts[] = sprintf( /* translators: 1: number of sales, 2: city */ _n( '%1$s sale in %2$s', '%1$s sales in %2$s', (int) $a['sales_in_city'], 'dos-agents' ), number_format_i18n( $a['sales_in_city'] ), $city );
	}
	if ( $a['years'] > 0 ) {
		$facts[] = sprintf( /* translators: %s: number of years */ _n( '%s year in the industry', '%s years in the industry', (int) $a['years'], 'dos-agents' ), number_format_i18n( $a['years'] ) );
	}

	ob_start();
	?>
<li class="breanm-agents__card" data-breanm-card data-i="<?php echo (int) $i; ?>" data-name="<?php echo esc_attr( $name_attr ); ?>" data-keys="<?php echo esc_attr( dos_agents_card_keys( $a ) ); ?>" data-tier="<?php echo (int) dos_agents_tier_rank( $a['tier'] ); ?>" data-sales="<?php echo (int) $a['sales_in_city']; ?>" data-rating="<?php echo esc_attr( (string) $a['rating'] ); ?>" data-reviews="<?php echo (int) $a['rating_count']; ?>" data-last="<?php echo esc_attr( str_replace( '-', '', $a['last_review'] ) ); ?>" data-years="<?php echo (int) $a['years']; ?>"<?php echo $old ? ' data-old="1"' : ''; ?>>
<div class="breanm-agents__body">
<div class="breanm-agents__head">
<h3 class="breanm-agents__title"><?php echo esc_html( $a['name'] ); ?></h3>
<?php if ( '' !== $a['brokerage'] || $a['is_team'] ) : ?>
<p class="breanm-agents__co"><?php echo esc_html( $a['brokerage'] ); ?><?php if ( $a['is_team'] ) : ?> <span class="breanm-agents__team"><?php esc_html_e( 'Team', 'dos-agents' ); ?></span><?php endif; ?></p>
<?php endif; ?>
</div>
<?php if ( $a['rating_count'] > 0 && $a['rating'] > 0 ) : ?>
<p class="breanm-agents__meta"><span class="breanm-agents__rating" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: rating, 2: number of reviews */ __( 'Rated %1$s out of 5 from %2$s reviews', 'dos-agents' ), number_format_i18n( $a['rating'], 1 ), number_format_i18n( $a['rating_count'] ) ) ); ?>"><span aria-hidden="true">&#9733;</span> <?php echo esc_html( number_format_i18n( $a['rating'], 1 ) ); ?> <span class="breanm-agents__rcount">(<?php echo esc_html( number_format_i18n( $a['rating_count'] ) ); ?>)</span></span></p>
<?php endif; ?>
<?php if ( '' !== $badge ) : ?>
<p class="breanm-agents__badge"><?php echo esc_html( $badge ); ?></p>
<?php endif; ?>
<?php if ( 'primary' !== $a['tier'] && '' !== $office ) : ?>
<p class="breanm-agents__area"><?php echo esc_html( sprintf( /* translators: %s: office city and state */ __( 'Office: %s', 'dos-agents' ), $office ) ); ?></p>
<?php endif; ?>
<?php if ( $others ) : ?>
<p class="breanm-agents__area"><?php echo esc_html( sprintf( /* translators: %s: list of areas */ __( 'Also serves: %s', 'dos-agents' ), implode( ' · ', array_slice( $others, 0, 5 ) ) . ( count( $others ) > 5 ? ' +' . ( count( $others ) - 5 ) : '' ) ) ); ?></p>
<?php endif; ?>
<?php if ( $facts ) : ?>
<p class="breanm-agents__facts"><?php echo esc_html( implode( ' · ', $facts ) ); ?></p>
<?php endif; ?>
<?php if ( '' !== $last ) : ?>
<p class="breanm-agents__fresh"><?php echo esc_html( $fresh ); ?></p>
<?php endif; ?>
<?php if ( $old ) : ?>
<span class="breanm-agents__old"><?php echo esc_html( sprintf( /* translators: %s: year of the newest review, e.g. 2022 */ __( 'Reviews on file are from %s or earlier', 'dos-agents' ), substr( $a['last_review'], 0, 4 ) ) ); ?></span>
<?php endif; ?>
<?php if ( $parts ) : ?>
<details class="breanm-agents__reviews">
<summary><?php esc_html_e( 'What reviewers say', 'dos-agents' ); ?></summary>
<?php foreach ( $parts as $label => $text ) : ?>
<p><strong class="breanm-agents__plabel"><?php echo esc_html( $label ); ?></strong> <?php echo esc_html( $text ); ?></p>
<?php endforeach; ?>
</details>
<?php elseif ( '' !== $a['summary'] ) : ?>
<p class="breanm-agents__sum"><?php echo esc_html( $a['summary'] ); ?></p>
<?php elseif ( $window > 0 ) : ?>
<p class="breanm-agents__nosum"><?php echo esc_html( sprintf( /* translators: %s: number of years the reviews were taken from, e.g. 3 */ _n( 'Not enough reviews from the last %s year to write a summary.', 'Not enough reviews from the last %s years to write a summary.', $window, 'dos-agents' ), number_format_i18n( $window ) ) ); ?></p>
<?php endif; ?>
<?php if ( $tags ) : ?>
<ul class="breanm-agents__tags">
<?php foreach ( $tags as $t ) : ?>
<?php
$mentioned = $analyzed >= $t['count'] ? sprintf( /* translators: 1: mentions, 2: reviews analyzed */ __( 'Mentioned in %1$s of %2$s recent reviews', 'dos-agents' ), number_format_i18n( $t['count'] ), number_format_i18n( $analyzed ) ) : sprintf( /* translators: %s: mentions */ __( 'Mentioned in %s recent reviews', 'dos-agents' ), number_format_i18n( $t['count'] ) );
?>
<li class="breanm-agents__chip" title="<?php echo esc_attr( $mentioned ); ?>"><?php echo esc_html( $t['label'] . ' · ' . $t['count'] ); ?> <span class="breanm-agents__sr"><?php echo esc_html( $mentioned ); ?></span></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</div>
</li>
	<?php
	return ob_get_clean();
}

/**
 * The whole UI for one city.
 *
 * @param array    $payload A stored payload ({city, state, agents, ...}).
 * @param int      $initial Cards shown before "Show more agents".
 * @param int|null $now     Unix time the page is rendered at (the 12-month cutoff comes from it).
 */
function dos_agents_render( array $payload, $initial = 12, $now = null ) {
	static $n = 0;
	++$n;
	$now     = null === $now ? dos_agents_now() : (int) $now;
	$initial = $initial > 0 ? $initial : 12;
	$city    = isset( $payload['city'] ) ? (string) $payload['city'] : '';
	$state   = isset( $payload['state'] ) ? (string) $payload['state'] : '';
	$agents  = array();
	foreach ( isset( $payload['agents'] ) && is_array( $payload['agents'] ) ? $payload['agents'] : array() as $raw ) {
		$agent = dos_agents_normalise_agent( $raw );
		if ( null !== $agent ) {
			$agents[] = $agent;
		}
	}
	$agents  = dos_agents_sort_local( $agents );
	$total   = count( $agents );
	$chips   = dos_agents_chips( $agents, $city, $now );
	$uid     = 'breanm-agents-' . $n;
	$keep    = 8;
	$more    = max( 0, count( $chips ) - $keep );

	// The disclosure that came with the city data wins; then the option; then the built-in default.
	// (dos_agents_get() has already sanitised the payload's; here it is only checked for shape.)
	$d_title = '';
	$d_paras = array();
	if ( isset( $payload['disclosure'] ) && is_array( $payload['disclosure'] ) ) {
		$d_title = isset( $payload['disclosure']['title'] ) && is_string( $payload['disclosure']['title'] ) ? trim( $payload['disclosure']['title'] ) : '';
		foreach ( isset( $payload['disclosure']['paragraphs'] ) && is_array( $payload['disclosure']['paragraphs'] ) ? $payload['disclosure']['paragraphs'] : array() as $p ) {
			if ( is_string( $p ) && '' !== trim( $p ) ) {
				$d_paras[] = trim( $p );
			}
		}
	}
	$disclosure = get_option( DOS_AGENTS_DISCLOSURE_OPTION, '' );
	$disclosure = ( is_string( $disclosure ) && '' !== trim( $disclosure ) ) ? $disclosure : dos_agents_default_disclosure();

	$sorts = array(
		'local'   => __( 'Local first', 'dos-agents' ),
		'sales'   => sprintf( /* translators: %s: city */ __( 'Most sales in %s', 'dos-agents' ), $city ),
		'rating'  => __( 'Rating', 'dos-agents' ),
		'reviews' => __( 'Number of reviews', 'dos-agents' ),
		'recent'  => __( 'Most recently reviewed', 'dos-agents' ),
		'years'   => __( 'Years in the industry', 'dos-agents' ),
	);

	ob_start();
	?>
<div class="breanm-agents" id="<?php echo esc_attr( $uid ); ?>" data-breanm-agents data-initial="<?php echo (int) $initial; ?>" data-step="12" data-recent-cutoff="<?php echo esc_attr( dos_agents_recent_cutoff( $now ) ); ?>" data-text-showing-one="<?php echo esc_attr( _n( 'Showing %1$d of %2$d agent', 'Showing %1$d of %2$d agents', 1, 'dos-agents' ) ); ?>" data-text-showing-many="<?php echo esc_attr( _n( 'Showing %1$d of %2$d agent', 'Showing %1$d of %2$d agents', 2, 'dos-agents' ) ); ?>" data-text-more-filters="<?php echo esc_attr( __( 'More filters (%d)', 'dos-agents' ) ); ?>" data-text-fewer-filters="<?php echo esc_attr( __( 'Fewer filters', 'dos-agents' ) ); ?>">
<div class="breanm-agents__filters" data-breanm-filters hidden>
<div class="breanm-agents__search">
<label class="breanm-agents__sr" for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Search by name or brokerage', 'dos-agents' ); ?></label>
<input id="<?php echo esc_attr( $uid ); ?>-q" type="search" class="breanm-agents__input" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: city */ __( 'Search %s agents by name or brokerage', 'dos-agents' ), $city ) ); ?>" data-breanm-q autocomplete="off">
</div>
<?php if ( $chips ) : ?>
<div class="breanm-agents__fsec">
<p class="breanm-agents__flabel"><?php esc_html_e( 'Filter by', 'dos-agents' ); ?></p>
<div class="breanm-agents__chips" data-breanm-chips id="<?php echo esc_attr( $uid ); ?>-chips" role="group" aria-label="<?php esc_attr_e( 'Filter agents', 'dos-agents' ); ?>">
<?php foreach ( $chips as $ci => $chip ) : ?>
<?php if ( $more && $keep === $ci ) : ?>
<button type="button" class="breanm-agents__chip breanm-agents__chip--more" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-extra" data-breanm-more-filters data-more="<?php echo (int) $more; ?>"><?php echo esc_html( sprintf( /* translators: %d: number of hidden filters */ __( 'More filters (%d)', 'dos-agents' ), $more ) ); ?></button>
<div class="breanm-agents__extra" id="<?php echo esc_attr( $uid ); ?>-extra">
<?php endif; ?>
<button type="button" class="breanm-agents__chip breanm-agents__chip--filter<?php echo $more && $ci >= $keep ? ' breanm-agents__chip--extra' : ''; ?>" aria-pressed="false" data-breanm-chip="<?php echo esc_attr( $chip['key'] ); ?>"><?php echo esc_html( $chip['label'] ); ?> <span class="breanm-agents__chip-count"><?php echo (int) $chip['count']; ?></span></button>
<?php endforeach; ?>
<?php if ( $more ) : ?>
</div>
<?php endif; ?>
<button type="button" class="breanm-agents__chip breanm-agents__chip--clear" data-breanm-clear hidden><?php esc_html_e( 'Clear filters', 'dos-agents' ); ?></button>
</div>
</div>
<?php endif; ?>
</div>
<div class="breanm-agents__bar">
<p class="breanm-agents__count" data-breanm-count role="status" aria-live="polite"><?php echo esc_html( sprintf( /* translators: 1: agents shown, 2: agents matching */ _n( 'Showing %1$d of %2$d agent', 'Showing %1$d of %2$d agents', $total, 'dos-agents' ), $total, $total ) ); ?></p>
<div class="breanm-agents__sortbox" data-breanm-sortbox hidden>
<label class="breanm-agents__flabel" for="<?php echo esc_attr( $uid ); ?>-sort"><?php esc_html_e( 'Sort by', 'dos-agents' ); ?></label>
<select id="<?php echo esc_attr( $uid ); ?>-sort" class="breanm-agents__select" data-breanm-sort>
<?php foreach ( $sorts as $value => $label ) : ?>
<option value="<?php echo esc_attr( $value ); ?>"<?php echo 'local' === $value ? ' selected' : ''; ?>><?php echo esc_html( $label ); ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<ul class="breanm-agents__grid" data-breanm-list>
<?php
foreach ( $agents as $i => $a ) {
	echo dos_agents_card( $a, $city, $state, $i, $now ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in dos_agents_card().
}
?>
</ul>
<p class="breanm-agents__empty" data-breanm-empty hidden><?php esc_html_e( 'No agents match those filters. Try clearing a filter or a different search.', 'dos-agents' ); ?></p>
<p class="breanm-agents__morewrap"><button type="button" class="breanm-agents__morebtn" data-breanm-more hidden><?php esc_html_e( 'Show more agents', 'dos-agents' ); ?></button></p>
<?php if ( $d_paras ) : ?>
<div class="breanm-agents__disc">
<?php if ( '' !== $d_title ) : ?><strong><?php echo esc_html( $d_title ); ?></strong>
<?php endif; ?>
<?php foreach ( $d_paras as $d_para ) : ?>
<p><?php echo esc_html( $d_para ); ?></p>
<?php endforeach; ?>
</div>
<?php else : ?>
<p class="breanm-agents__disc"><?php echo esc_html( $disclosure ); ?></p>
<?php endif; ?>
</div>
	<?php
	return ob_get_clean();
}
