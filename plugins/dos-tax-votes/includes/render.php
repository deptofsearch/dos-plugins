<?php
/**
 * DoS Tax Votes: data loading and server-side rendering.
 *
 * Everything a reader needs is in the HTML the server sends. The script only filters, sorts and
 * switches the counts between all taxes, business taxes and individual taxes.
 *
 * Data: data/<biennium>.json, written by electedorappointed/tax-votes/export_public.py in the
 * deptofsearch/Claude repository from Ryan's reviewed bill classifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Load one biennium's data file. Returns null when the biennium is unknown or the file is bad. */
function dos_tax_votes_data( $biennium ) {
	static $cache = array();
	$biennium = (string) $biennium;
	if ( ! preg_match( '/^\d{4}-\d{2}$/', $biennium ) ) {
		return null;
	}
	if ( array_key_exists( $biennium, $cache ) ) {
		return $cache[ $biennium ];
	}
	$file = DOS_TAX_VOTES_DIR . '/data/' . $biennium . '.json';
	$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $data ) || empty( $data['legislators'] ) || empty( $data['bills'] ) ) {
		$data = null;
	}
	$cache[ $biennium ] = $data;
	return $data;
}

/** Every biennium shipped with the plugin, newest first. */
function dos_tax_votes_bienniums() {
	static $names = null;
	if ( null === $names ) {
		$names = array();
		foreach ( (array) glob( DOS_TAX_VOTES_DIR . '/data/*.json' ) as $f ) {
			$b = basename( $f, '.json' );
			if ( preg_match( '/^\d{4}-\d{2}$/', $b ) ) {
				$names[] = $b;
			}
		}
		rsort( $names );
	}
	return $names;
}

/** The newest biennium shipped with the plugin. */
function dos_tax_votes_latest_biennium() {
	$names = dos_tax_votes_bienniums();
	return $names ? $names[0] : '';
}

/**
 * Which biennium to show: a ?biennium=YYYY-YY in the URL when it names a shipped data file,
 * else the shortcode's biennium attribute, else the newest.
 */
function dos_tax_votes_pick_biennium( $attr ) {
	$names = dos_tax_votes_bienniums();
	$req   = isset( $_GET['biennium'] ) ? (string) wp_unslash( $_GET['biennium'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	if ( '' !== $req && in_array( $req, $names, true ) ) {
		return $req;
	}
	$attr = (string) $attr;
	return ( '' !== $attr && in_array( $attr, $names, true ) ) ? $attr : dos_tax_votes_latest_biennium();
}

/** Links between bienniums. Plain links (?biennium=...), so each biennium is its own crawlable page view. */
function dos_tax_votes_switcher( $current ) {
	$names = dos_tax_votes_bienniums();
	if ( count( $names ) < 2 ) {
		return '';
	}
	$out = '<nav class="eoa-tv-bien" aria-label="' . esc_attr__( 'Legislative session', 'dos-tax-votes' ) . '"><span class="eoa-tv-muted">' . esc_html__( 'Session', 'dos-tax-votes' ) . '</span>';
	foreach ( $names as $b ) {
		$label = dos_tax_votes_biennium_label( $b );
		$out  .= $b === $current
			? '<span class="eoa-tv-bien__on" aria-current="page">' . esc_html( $label ) . '</span>'
			: '<a href="' . esc_attr( '?biennium=' . rawurlencode( $b ) . '#eoa-tax-votes' ) . '">' . esc_html( $label ) . '</a>';
	}
	return $out . '</nav>';
}

function dos_tax_votes_party_name( $p ) {
	$names = array( 'D' => __( 'Democrat', 'dos-tax-votes' ), 'R' => __( 'Republican', 'dos-tax-votes' ), 'I' => __( 'Independent', 'dos-tax-votes' ) );
	return isset( $names[ $p ] ) ? $names[ $p ] : (string) $p;
}

function dos_tax_votes_biennium_label( $b ) {
	return str_replace( '-', '–', (string) $b ); // en dash: 2025–26
}

/** "$1.12B", "−$46K". Signed, compact. */
function dos_tax_votes_money( $v ) {
	if ( null === $v || '' === $v ) {
		return '';
	}
	$v    = (float) $v;
	$a    = abs( $v );
	$sign = $v < 0 ? '−' : '+';
	if ( $a >= 1e9 ) {
		return $sign . '$' . number_format( $a / 1e9, 2 ) . 'B';
	}
	if ( $a >= 1e6 ) {
		return $sign . '$' . number_format( $a / 1e6, 1 ) . 'M';
	}
	if ( $a >= 1e3 ) {
		return $sign . '$' . number_format( $a / 1e3 ) . 'K';
	}
	return $sign . '$' . number_format( $a );
}

/** One count with a meter: "Yes on 17 of 17". */
function dos_tax_votes_meter( $kind, $yes, $total ) {
	$yes   = (int) $yes;
	$total = (int) $total;
	$pct   = $total > 0 ? round( 100 * $yes / $total ) : 0;
	$label = 'up' === $kind ? __( 'Tax increases', 'dos-tax-votes' ) : __( 'Tax cuts', 'dos-tax-votes' );
	/* translators: 1: yes votes, 2: votes cast */
	$text = $total > 0 ? sprintf( __( 'Yes on %1$s of %2$s', 'dos-tax-votes' ), '<b data-n="yes">' . $yes . '</b>', '<b data-n="total">' . $total . '</b>' ) : __( 'No votes cast', 'dos-tax-votes' );
	return '<div class="eoa-tv-count eoa-tv-count--' . esc_attr( $kind ) . '" data-kind="' . esc_attr( $kind ) . '">'
		. '<span class="eoa-tv-count__label">' . esc_html( $label ) . '</span>'
		. '<span class="eoa-tv-count__text">' . wp_kses( $text, array( 'b' => array( 'data-n' => true ) ) ) . '</span>'
		. '<span class="eoa-tv-meter" aria-hidden="true"><i style="width:' . (int) $pct . '%"></i></span>'
		. '</div>';
}

/** data-* attributes carrying a person's counts for all three categories, for the switcher. */
function dos_tax_votes_count_attrs( $split ) {
	$out = '';
	foreach ( array( 'all', 'business', 'individual' ) as $cat ) {
		$s    = isset( $split[ $cat ] ) && is_array( $split[ $cat ] ) ? $split[ $cat ] : array();
		$vals = array(
			isset( $s['increasesYes'] ) ? (int) $s['increasesYes'] : ( isset( $s['increasesSigned'] ) ? (int) $s['increasesSigned'] : 0 ),
			isset( $s['increases'] ) ? (int) $s['increases'] : 0,
			isset( $s['cutsYes'] ) ? (int) $s['cutsYes'] : ( isset( $s['cutsSigned'] ) ? (int) $s['cutsSigned'] : 0 ),
			isset( $s['cuts'] ) ? (int) $s['cuts'] : 0,
		);
		$out .= ' data-' . $cat . '="' . esc_attr( implode( ',', $vals ) ) . '"';
	}
	return $out;
}

function dos_tax_votes_vote_word( $v ) {
	if ( 'Y' === $v ) {
		return __( 'Yes', 'dos-tax-votes' );
	}
	if ( 'N' === $v ) {
		return __( 'No', 'dos-tax-votes' );
	}
	return __( 'Did not vote', 'dos-tax-votes' );
}

function dos_tax_votes_bill_link( $bill ) {
	$url = isset( $bill['billUrl'] ) ? (string) $bill['billUrl'] : '';
	$id  = esc_html( (string) $bill['billId'] );
	return $url ? '<a href="' . esc_url( $url ) . '" rel="noopener">' . $id . '</a>' : $id;
}

/** One legislator. */
function dos_tax_votes_person( $p, $bills_by_key, $has_cuts = true ) {
	$s       = isset( $p['split']['all'] ) ? $p['split']['all'] : array();
	$chamber = 'Senate' === $p['chamber'] ? __( 'Senate', 'dos-tax-votes' ) : __( 'House', 'dos-tax-votes' );
	$title   = 'Senate' === $p['chamber'] ? __( 'Sen.', 'dos-tax-votes' ) : __( 'Rep.', 'dos-tax-votes' );

	$rows = '';
	foreach ( $bills_by_key as $key => $b ) {
		if ( ! isset( $p['votes'][ $key ] ) ) {
			continue;
		}
		$v     = (string) $p['votes'][ $key ];
		$dir   = 'increase' === $b['direction'] ? __( 'raises taxes', 'dos-tax-votes' ) : __( 'cuts taxes', 'dos-tax-votes' );
		$rows .= '<li data-cat="' . esc_attr( $b['category'] ) . '" data-dir="' . esc_attr( $b['direction'] ) . '">'
			. '<span class="eoa-tv-vote eoa-tv-vote--' . esc_attr( strtolower( $v ) ) . '">' . esc_html( dos_tax_votes_vote_word( $v ) ) . '</span> '
			. dos_tax_votes_bill_link( $b ) . ' <span class="eoa-tv-billtitle">' . esc_html( (string) $b['title'] ) . '</span>'
			. ' <span class="eoa-tv-tag eoa-tv-tag--' . ( 'increase' === $b['direction'] ? 'up' : 'down' ) . '">' . esc_html( $dir ) . '</span></li>';
	}

	$spons = array();
	foreach ( array( 'prime' => __( 'Prime sponsor', 'dos-tax-votes' ), 'cosponsor' => __( 'Co-sponsor', 'dos-tax-votes' ) ) as $k => $label ) {
		if ( ! empty( $p['sponsored'][ $k ] ) ) {
			$spons[] = esc_html( $label ) . ': ' . esc_html( implode( ', ', array_map( 'strval', $p['sponsored'][ $k ] ) ) );
		}
	}

	$missed = (int) ( $p['missed'] ?? 0 );
	$name   = (string) $p['name'];
	$search = strtolower( $name . ' ' . $p['district'] );

	return '<li class="eoa-tv-person" data-eoa-person'
		. ' data-chamber="' . esc_attr( $p['chamber'] ) . '" data-party="' . esc_attr( $p['party'] ) . '"'
		. ' data-district="' . (int) $p['district'] . '" data-name="' . esc_attr( $name ) . '" data-search="' . esc_attr( $search ) . '"'
		. dos_tax_votes_count_attrs( $p['split'] ) . '>'
		. '<div class="eoa-tv-who"><span class="eoa-tv-name">' . esc_html( $title . ' ' . $name ) . '</span>'
		. '<span class="eoa-tv-muted">' . esc_html( dos_tax_votes_party_name( $p['party'] ) ) . ' · '
		/* translators: %d: legislative district number */
		. esc_html( sprintf( __( 'District %d', 'dos-tax-votes' ), (int) $p['district'] ) ) . ' · ' . esc_html( $chamber ) . '</span>'
		. '<span class="eoa-tv-links">'
		. ( ! empty( $p['page'] ) ? '<a href="' . esc_url( $p['page'] ) . '" rel="noopener">' . esc_html__( 'Official page', 'dos-tax-votes' ) . '</a>' : '' )
		. ( ! empty( $p['districtMap'] ) ? '<a href="' . esc_url( $p['districtMap'] ) . '" rel="noopener">' . esc_html__( 'District map', 'dos-tax-votes' ) . '</a>' : '' )
		. '</span></div>'
		. dos_tax_votes_meter( 'up', $s['increasesYes'] ?? 0, $s['increases'] ?? 0 )
		. ( $has_cuts ? dos_tax_votes_meter( 'down', $s['cutsYes'] ?? 0, $s['cuts'] ?? 0 ) : '' )
		. '<details class="eoa-tv-details"><summary>' . esc_html__( 'Votes', 'dos-tax-votes' )
		. ( $missed ? ' <span class="eoa-tv-muted">' . esc_html( sprintf( /* translators: %d: votes missed */ _n( '(%d not voting)', '(%d not voting)', $missed, 'dos-tax-votes' ), $missed ) ) . '</span>' : '' )
		. '</summary><ul class="eoa-tv-votes">' . $rows . '</ul>'
		. ( $spons ? '<p class="eoa-tv-muted">' . implode( '<br>', $spons ) . '</p>' : '' )
		. '</details></li>';
}

/** Shortcode: [eoa_tax_votes biennium="2025-26" show="all|legislators|bills|method"]. */
function dos_tax_votes_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'biennium' => '', 'show' => 'all', 'switcher' => 'yes' ), $atts, 'eoa_tax_votes' );
	$bien = dos_tax_votes_pick_biennium( $atts['biennium'] );
	$data = dos_tax_votes_data( $bien );
	if ( ! $data ) {
		return current_user_can( 'edit_posts' ) ? '<p>' . esc_html__( 'DoS Tax Votes: no data for that biennium.', 'dos-tax-votes' ) . '</p>' : '';
	}
	wp_enqueue_style( 'dos-tax-votes' );
	wp_enqueue_script( 'dos-tax-votes' );
	$html = dos_tax_votes_render( $data, (string) $atts['show'] );
	if ( 'no' !== $atts['switcher'] ) {
		$nav  = dos_tax_votes_switcher( $bien );
		$html = preg_replace_callback( '/^<div class="eoa-tv[^"]*" id="eoa-tax-votes" data-eoa-tax-votes>/', static function ( $m ) use ( $nav ) { return $m[0] . $nav; }, $html, 1 );
	}
	return $html;
}

function dos_tax_votes_render( $data, $show = 'all' ) {
	$show  = in_array( $show, array( 'all', 'legislators', 'bills', 'method' ), true ) ? $show : 'all';
	$label = dos_tax_votes_biennium_label( $data['biennium'] );
	$bills = array();
	foreach ( $data['bills'] as $b ) {
		$bills[ (string) $b['key'] ] = $b;
	}
	$n_up   = (int) ( $data['counts']['increases'] ?? 0 );
	$n_down = (int) ( $data['counts']['cuts'] ?? 0 );

	$html = '<div class="eoa-tv' . ( $n_down > 0 ? '' : ' eoa-tv--nocuts' ) . '" id="eoa-tax-votes" data-eoa-tax-votes>';

	if ( 'all' === $show || 'legislators' === $show ) {
		$lede = $n_down > 0
			? sprintf(
				/* translators: 1: biennium, 2: bills that raise taxes, 3: bills that cut taxes */
				__( 'In the %1$s Legislature, %2$d bills that raise taxes and %3$d bills that cut taxes by $1 million or more reached a final floor vote. For each lawmaker, the counts below show how many of each they voted yes on.', 'dos-tax-votes' ),
				$label,
				$n_up,
				$n_down
			)
			: sprintf(
				/* translators: 1: biennium, 2: bills that raise taxes */
				__( 'In the %1$s Legislature, %2$d bills that raise taxes by $1 million or more over six years reached a final floor vote, and no bill that cut taxes by that much did. For each lawmaker, the counts below show how many of the %2$d they voted yes on.', 'dos-tax-votes' ),
				$label,
				$n_up
			);
		$html .= '<p class="eoa-tv-lede">' . esc_html( $lede ) . '</p>';

		// Governor.
		$g = $data['governor'];
		$gs = $g['split'];
		$html .= '<section class="eoa-tv-gov" aria-label="' . esc_attr__( 'Governor', 'dos-tax-votes' ) . '">'
			. '<h3 class="eoa-tv-h">' . esc_html( sprintf( /* translators: %s: governor name */ __( 'Gov. %s', 'dos-tax-votes' ), $g['name'] ) ) . '</h3>'
			. '<p>' . esc_html(
				(int) $gs['cuts'] > 0
					? sprintf(
						/* translators: 1: increases signed, 2: increases sent, 3: cuts signed, 4: cuts sent */
						__( 'Signed %1$d of %2$d bills that raise taxes and %3$d of %4$d bills that cut taxes.', 'dos-tax-votes' ),
						(int) $gs['increasesSigned'],
						(int) $gs['increases'],
						(int) $gs['cutsSigned'],
						(int) $gs['cuts']
					)
					: sprintf(
						/* translators: 1: increases signed, 2: increases sent */
						__( 'Signed %1$d of %2$d bills that raise taxes.', 'dos-tax-votes' ),
						(int) $gs['increasesSigned'],
						(int) $gs['increases']
					)
			) . '</p></section>';

		// Party chart and map side by side on wide screens; they stack on narrow ones.
		$html .= '<div class="eoa-tv-overview">' . dos_tax_votes_party_section( $data ) . dos_tax_votes_map( $data ) . '</div>';

		// Controls (work only with JS; hidden until the script runs).
		$html .= '<div class="eoa-tv-controls" data-eoa-controls hidden>'
			. '<div class="eoa-tv-seg" role="group" aria-label="' . esc_attr__( 'Taxes', 'dos-tax-votes' ) . '">'
			. '<button type="button" data-cat="all" aria-pressed="true">' . esc_html__( 'All taxes', 'dos-tax-votes' ) . '</button>'
			. '<button type="button" data-cat="business" aria-pressed="false">' . esc_html__( 'Business taxes', 'dos-tax-votes' ) . '</button>'
			. '<button type="button" data-cat="individual" aria-pressed="false">' . esc_html__( 'Individual taxes', 'dos-tax-votes' ) . '</button></div>'
			. '<label>' . esc_html__( 'Chamber', 'dos-tax-votes' ) . ' <select data-f="chamber"><option value="">' . esc_html__( 'Both', 'dos-tax-votes' ) . '</option><option value="House">' . esc_html__( 'House', 'dos-tax-votes' ) . '</option><option value="Senate">' . esc_html__( 'Senate', 'dos-tax-votes' ) . '</option></select></label>'
			. '<label>' . esc_html__( 'Party', 'dos-tax-votes' ) . ' <select data-f="party"><option value="">' . esc_html__( 'Any', 'dos-tax-votes' ) . '</option><option value="D">' . esc_html__( 'Democrat', 'dos-tax-votes' ) . '</option><option value="R">' . esc_html__( 'Republican', 'dos-tax-votes' ) . '</option></select></label>'
			. '<label>' . esc_html__( 'Sort', 'dos-tax-votes' ) . ' <select data-f="sort"><option value="district">' . esc_html__( 'District', 'dos-tax-votes' ) . '</option><option value="name">' . esc_html__( 'Name', 'dos-tax-votes' ) . '</option><option value="up-desc">' . esc_html__( 'Most yes votes on increases', 'dos-tax-votes' ) . '</option><option value="up-asc">' . esc_html__( 'Fewest yes votes on increases', 'dos-tax-votes' ) . '</option></select></label>'
			. '<label class="eoa-tv-search">' . esc_html__( 'Find', 'dos-tax-votes' ) . ' <input type="search" data-f="q" placeholder="' . esc_attr__( 'Name or district number', 'dos-tax-votes' ) . '"></label>'
			. '<p class="eoa-tv-muted" data-eoa-shown aria-live="polite"></p>'
			. '<p class="eoa-tv-districtpick" data-eoa-district hidden><span></span> <button type="button" data-clear-district>' . esc_html__( 'Show everyone', 'dos-tax-votes' ) . '</button></p>'
			. '</div>';

		$html .= '<ul class="eoa-tv-people" data-eoa-people>';
		foreach ( $data['legislators'] as $p ) {
			$html .= dos_tax_votes_person( $p, $bills, $n_down > 0 );
		}
		$html .= '</ul>';
	}

	if ( 'all' === $show || 'bills' === $show ) {
		// Foldable: closed on the full page, open when shown on its own.
		$html .= '<details class="eoa-tv-fold"' . ( 'bills' === $show ? ' open' : '' ) . '><summary><h3 class="eoa-tv-h">' . esc_html__( 'The bills counted', 'dos-tax-votes' ) . '</h3>'
			. '<span class="eoa-tv-muted">' . esc_html( sprintf( /* translators: %d: bills */ _n( '%d bill', '%d bills', count( $bills ), 'dos-tax-votes' ), count( $bills ) ) ) . '</span></summary>'
			. '<div class="eoa-tv-tablewrap"><table class="eoa-tv-bills"><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Bill', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'What it does', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Counted as', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Revenue, six years', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'House', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Senate', 'dos-tax-votes' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Governor', 'dos-tax-votes' ) . '</th></tr></thead><tbody>';
		foreach ( $bills as $key => $b ) {
			$counted = ( 'increase' === $b['direction'] ? __( 'Raises', 'dos-tax-votes' ) : __( 'Cuts', 'dos-tax-votes' ) ) . ' · '
				. ( 'both' === $b['category'] ? __( 'business and individual', 'dos-tax-votes' ) : ( 'business' === $b['category'] ? __( 'business', 'dos-tax-votes' ) : __( 'individual', 'dos-tax-votes' ) ) );
			$tally = static function ( $c ) {
				return is_array( $c ) ? esc_html( (int) $c['yea'] . '–' . (int) $c['nay'] ) : '—';
			};
			$gov  = isset( $data['governor']['actions'][ $key ] ) ? (string) $data['governor']['actions'][ $key ] : '—';
			$note = ! empty( $b['fiscalNoteUrl'] ) ? ' <a href="' . esc_url( $b['fiscalNoteUrl'] ) . '" rel="noopener">' . esc_html__( 'fiscal note', 'dos-tax-votes' ) . '</a>' : '';
			$html .= '<tr><th scope="row">' . dos_tax_votes_bill_link( $b ) . '</th>'
				. '<td>' . esc_html( (string) $b['description'] ) . '</td>'
				. '<td><span class="eoa-tv-tag eoa-tv-tag--' . ( 'increase' === $b['direction'] ? 'up' : 'down' ) . '">' . esc_html( $counted ) . '</span><br><span class="eoa-tv-muted">' . esc_html( (string) $b['taxType'] ) . '</span></td>'
				. '<td class="eoa-tv-num">' . esc_html( dos_tax_votes_money( $b['revenue6yr'] ) ?: '—' ) . $note . '</td>'
				. '<td class="eoa-tv-num">' . $tally( $b['house'] ) . '</td>'
				. '<td class="eoa-tv-num">' . $tally( $b['senate'] ) . '</td>'
				. '<td>' . esc_html( $gov ) . '</td></tr>';
		}
		$html .= '</tbody></table></div></details>';
	}

	if ( 'all' === $show || 'method' === $show ) {
		$items = array(
			__( 'Bills counted: every bill in the biennium that changed state tax law (RCW Title 82 or 84) and passed at least one chamber, then classified by its fiscal note from the state Office of Financial Management.', 'dos-tax-votes' ),
			__( 'A bill counts as raising or cutting taxes by its net effect on state revenue over six years, as the Department of Revenue estimated it. Bills with no change in what taxpayers owe, such as technical fixes, budgets and changes to how existing tax money is spent, are not counted.', 'dos-tax-votes' ),
			__( 'Bills that change state revenue by less than $1 million over six years are not counted. Their tax effect is usually a small part of a bill passed for another purpose.', 'dos-tax-votes' ),
			__( 'Property tax exemptions are not counted. Under Washington\'s property tax levy limits, an exemption mostly shifts the tax onto other property owners rather than lowering the total.', 'dos-tax-votes' ),
			__( 'Only final passage votes count, not votes on amendments or procedural motions. When a member voted on a bill more than once, such as on passage and again on the other chamber\'s changes, their last vote counts.', 'dos-tax-votes' ),
			__( 'Business taxes are taxes a business pays, such as the business and occupation tax. Individual taxes are those people pay directly, such as the capital gains, estate and motor vehicle taxes. Sales tax extended to services both businesses and households buy counts in both.', 'dos-tax-votes' ),
			__( 'Packages that raise some taxes and cut others are not counted when the two sides are comparable. A bill whose net effect is a large increase is counted as one, and the bill list shows its revenue estimate so you can judge.', 'dos-tax-votes' ),
			__( 'For the governor, signing a bill (including with a partial veto) is counted as a yes and a full veto as a no.', 'dos-tax-votes' ),
		);
		$html .= '<details class="eoa-tv-fold"' . ( 'method' === $show ? ' open' : '' ) . '><summary><h3 class="eoa-tv-h">' . esc_html__( 'How we counted', 'dos-tax-votes' ) . '</h3>'
			. '<span class="eoa-tv-muted">' . esc_html__( 'Which bills count, and why', 'dos-tax-votes' ) . '</span></summary><ul class="eoa-tv-method">';
		// Exceptions and judgment calls for this biennium, from its data file.
		foreach ( (array) ( $data['notes'] ?? array() ) as $note ) {
			$items[] = (string) $note;
		}
		foreach ( $items as $i ) {
			$html .= '<li>' . esc_html( $i ) . '</li>';
		}
		$html .= '</ul><p class="eoa-tv-muted">' . esc_html(
			sprintf(
				/* translators: %s: date */
				__( 'Sources: Washington State Legislature roll calls and bill records; Office of Financial Management fiscal notes. Updated %s.', 'dos-tax-votes' ),
				(string) ( $data['updated'] ?? '' )
			)
		) . '</p></details>';
	}

	return $html . '</div>';
}

/** Party colors for the chart and map. Muted versions of the conventional blue and red. */
function dos_tax_votes_party_color( $p ) {
	return 'D' === $p ? 'var(--eoa-tv-dem)' : ( 'R' === $p ? 'var(--eoa-tv-rep)' : 'var(--eoa-tv-mix)' );
}

/** Share bins for the party chart: what part of the bills put to them each lawmaker voted yes on. */
function dos_tax_votes_bins() {
	return array(
		array( 0, 0, __( 'None', 'dos-tax-votes' ) ),
		array( 0.0001, 0.2499, __( 'Under 25%', 'dos-tax-votes' ) ),
		array( 0.25, 0.4999, '25–49%' ),
		array( 0.5, 0.7499, '50–74%' ),
		array( 0.75, 0.9999, '75–99%' ),
		array( 1, 1, __( 'All', 'dos-tax-votes' ) ),
	);
}

/** Count lawmakers per party per bin for increases ('up') or cuts ('down'), all taxes. */
function dos_tax_votes_histogram( $data, $kind ) {
	$bins = dos_tax_votes_bins();
	$out  = array( 'D' => array_fill( 0, count( $bins ), 0 ), 'R' => array_fill( 0, count( $bins ), 0 ) );
	foreach ( $data['legislators'] as $p ) {
		$s     = $p['split']['all'] ?? array();
		$yes   = (int) ( 'up' === $kind ? ( $s['increasesYes'] ?? 0 ) : ( $s['cutsYes'] ?? 0 ) );
		$total = (int) ( 'up' === $kind ? ( $s['increases'] ?? 0 ) : ( $s['cuts'] ?? 0 ) );
		$party = (string) $p['party'];
		if ( $total < 1 || ! isset( $out[ $party ] ) ) {
			continue;
		}
		$share = $yes / $total;
		foreach ( $bins as $i => $b ) {
			if ( $share >= $b[0] - 1e-9 && $share <= $b[1] + 1e-9 ) {
				$out[ $party ][ $i ]++;
				break;
			}
		}
	}
	return $out;
}

/** One diverging bar chart: Democrats above the line, Republicans below. Inline SVG. */
function dos_tax_votes_party_chart( $data, $kind ) {
	$bins  = dos_tax_votes_bins();
	$h     = dos_tax_votes_histogram( $data, $kind );
	$max   = max( 1, max( $h['D'] ), max( $h['R'] ) );
	$w     = 360;
	$colw  = 52;
	$left  = 28;
	$half  = 66; // px for the tallest bar
	$mid   = 84;
	$total = 'up' === $kind ? (int) $data['counts']['increases'] : (int) $data['counts']['cuts'];
	$title = 'up' === $kind
		/* translators: %d: number of bills */
		? sprintf( __( 'Yes votes on the %d bills that raise taxes', 'dos-tax-votes' ), $total )
		/* translators: %d: number of bills */
		: sprintf( __( 'Yes votes on the %d bills that cut taxes', 'dos-tax-votes' ), $total );
	$svg = '<svg class="eoa-tv-chart" viewBox="0 0 ' . $w . ' 186" role="img" aria-label="' . esc_attr( $title ) . '">';
	$svg .= '<line x1="' . $left . '" x2="' . ( $left + $colw * count( $bins ) ) . '" y1="' . $mid . '" y2="' . $mid . '" class="eoa-tv-axis"/>';
	$desc = array();
	foreach ( $bins as $i => $b ) {
		$x  = $left + $i * $colw + 6;
		$bw = $colw - 12;
		foreach ( array( 'D', 'R' ) as $party ) {
			$n  = (int) $h[ $party ][ $i ];
			$bh = $n ? max( 2, round( $half * $n / $max ) ) : 0;
			$y  = 'D' === $party ? $mid - $bh : $mid;
			if ( $bh ) {
				$svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . $bh . '" style="fill:' . dos_tax_votes_party_color( $party ) . '"><title>' . esc_html( dos_tax_votes_party_name( $party ) . 's, ' . $b[2] . ': ' . $n ) . '</title></rect>';
			}
			if ( $n ) {
				$ty   = 'D' === $party ? $mid - $bh - 4 : $mid + $bh + 12;
				$svg .= '<text x="' . ( $x + $bw / 2 ) . '" y="' . $ty . '" class="eoa-tv-chart-n">' . $n . '</text>';
			}
			$desc[] = dos_tax_votes_party_name( $party ) . 's ' . $b[2] . ': ' . $n;
		}
		$svg .= '<text x="' . ( $x + $bw / 2 ) . '" y="182" class="eoa-tv-chart-x">' . esc_html( $b[2] ) . '</text>';
	}
	$svg .= '</svg>';
	return '<figure class="eoa-tv-figure"><figcaption>' . esc_html( $title ) . '</figcaption>' . $svg
		. '<p class="eoa-tv-sr">' . esc_html( implode( '; ', $desc ) ) . '</p></figure>';
}

/** "Democrats: typically yes on 17 of 17 · Republicans: typically yes on 2 of 17", medians, all taxes. */
function dos_tax_votes_party_summary( $data ) {
	$vals = array( 'D' => array(), 'R' => array() );
	foreach ( $data['legislators'] as $p ) {
		$s = $p['split']['all'] ?? array();
		if ( isset( $vals[ $p['party'] ] ) && ( $s['increases'] ?? 0 ) > 0 ) {
			$vals[ $p['party'] ][] = (int) $s['increasesYes'];
		}
	}
	$total = (int) $data['counts']['increases'];
	$parts = array();
	foreach ( $vals as $party => $v ) {
		if ( ! $v ) {
			continue;
		}
		sort( $v );
		$n   = count( $v );
		$med = $n % 2 ? $v[ intdiv( $n, 2 ) ] : (int) round( ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2 );
		/* translators: 1: party name (plural), 2: median yes votes, 3: bills, 4: lawmakers in the party */
		$parts[] = sprintf( __( '%1$s: middle member voted yes on %2$d of %3$d (%4$d lawmakers)', 'dos-tax-votes' ), dos_tax_votes_party_name( $party ) . 's', $med, $total, $n );
	}
	return $parts ? '<ul class="eoa-tv-partysum"><li>' . implode( '</li><li>', array_map( 'esc_html', $parts ) ) . '</li></ul>' : '';
}

/** Party breakdown: legend plus the two charts. */
function dos_tax_votes_party_section( $data ) {
	return '<section class="eoa-tv-party" aria-label="' . esc_attr__( 'By party', 'dos-tax-votes' ) . '">'
		. '<h3 class="eoa-tv-h">' . esc_html__( 'By party', 'dos-tax-votes' ) . '</h3>'
		. '<p class="eoa-tv-legend"><span><i style="background:var(--eoa-tv-dem)"></i>' . esc_html__( 'Democrats (above the line)', 'dos-tax-votes' ) . '</span>'
		. '<span><i style="background:var(--eoa-tv-rep)"></i>' . esc_html__( 'Republicans (below the line)', 'dos-tax-votes' ) . '</span></p>'
		. '<p class="eoa-tv-muted">' . esc_html__( 'Each bar counts lawmakers by the share of the bills they voted on that they supported. Both chambers, all taxes.', 'dos-tax-votes' ) . '</p>'
		. '<div class="eoa-tv-charts">' . dos_tax_votes_party_chart( $data, 'up' ) . ( (int) $data['counts']['cuts'] > 0 ? dos_tax_votes_party_chart( $data, 'down' ) : '' ) . '</div>'
		. dos_tax_votes_party_summary( $data )
		. '</section>';
}

/**
 * District outlines for a biennium: the map its lawmakers were elected under
 * (data/wa-districts-<biennium>.json). Falls back to the newest outlines shipped.
 */
function dos_tax_votes_geo( $biennium = '' ) {
	static $cache = array();
	$biennium = preg_match( '/^\d{4}-\d{2}$/', (string) $biennium ) ? (string) $biennium : '';
	if ( ! array_key_exists( $biennium, $cache ) ) {
		$files = $biennium ? array( DOS_TAX_VOTES_DIR . '/data/wa-districts-' . $biennium . '.json' ) : array();
		$all   = (array) glob( DOS_TAX_VOTES_DIR . '/data/wa-districts-*.json' );
		rsort( $all );
		$geo = null;
		foreach ( array_merge( $files, $all ) as $file ) {
			$g = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
			if ( is_array( $g ) && ! empty( $g['districts'] ) ) {
				$geo = $g;
				break;
			}
		}
		$cache[ $biennium ] = $geo;
	}
	return $cache[ $biennium ];
}

/** The hoverable district map. Districts are colored by their delegation's party mix. */
function dos_tax_votes_map( $data ) {
	$geo = dos_tax_votes_geo( $data['biennium'] ?? '' );
	if ( ! $geo ) {
		return '';
	}
	$parties = array();
	foreach ( $data['legislators'] as $p ) {
		$parties[ (int) $p['district'] ][] = (string) $p['party'];
	}
	$svg = '<svg class="eoa-tv-map" viewBox="' . esc_attr( $geo['viewBox'] ) . '" data-full="' . esc_attr( $geo['viewBox'] ) . '" data-puget="' . esc_attr( $geo['puget'] ) . '" role="group" aria-label="' . esc_attr__( 'Map of Washington legislative districts', 'dos-tax-votes' ) . '">';
	foreach ( $geo['districts'] as $d => $path ) {
		$d    = (int) $d;
		$mix  = isset( $parties[ $d ] ) ? array_unique( $parties[ $d ] ) : array();
		$fill = 1 === count( $mix ) ? dos_tax_votes_party_color( $mix[0] ) : 'var(--eoa-tv-mix)';
		/* translators: %d: district number */
		$label = sprintf( __( 'District %d', 'dos-tax-votes' ), $d );
		$svg  .= '<path d="' . esc_attr( $path ) . '" data-district="' . $d . '" style="fill:' . $fill . '" tabindex="0" role="button" aria-label="' . esc_attr( $label ) . '"><title>' . esc_html( $label ) . '</title></path>';
	}
	$svg .= '</svg>';
	return '<section class="eoa-tv-mapwrap" aria-label="' . esc_attr__( 'Find your district', 'dos-tax-votes' ) . '">'
		. '<h3 class="eoa-tv-h">' . esc_html__( 'Find your district', 'dos-tax-votes' ) . '</h3>'
		. '<p class="eoa-tv-muted">' . esc_html__( 'Hover over or tap a district to see its senator, two representatives and their records. Select it to show only them in the list below. Not sure which district you live in? Use the Legislature\'s District Finder.', 'dos-tax-votes' )
		. ' <a href="https://app.leg.wa.gov/districtfinder" rel="noopener">' . esc_html__( 'District Finder', 'dos-tax-votes' ) . '</a></p>'
		. '<p class="eoa-tv-legend"><span><i style="background:var(--eoa-tv-dem)"></i>' . esc_html__( 'All Democrats', 'dos-tax-votes' ) . '</span>'
		. '<span><i style="background:var(--eoa-tv-rep)"></i>' . esc_html__( 'All Republicans', 'dos-tax-votes' ) . '</span>'
		. '<span><i style="background:var(--eoa-tv-mix)"></i>' . esc_html__( 'Split delegation', 'dos-tax-votes' ) . '</span></p>'
		. '<div class="eoa-tv-seg eoa-tv-mapzoom" role="group" aria-label="' . esc_attr__( 'Map view', 'dos-tax-votes' ) . '" hidden>'
		. '<button type="button" data-zoom="full" aria-pressed="true">' . esc_html__( 'Whole state', 'dos-tax-votes' ) . '</button>'
		. '<button type="button" data-zoom="puget" aria-pressed="false">' . esc_html__( 'Puget Sound', 'dos-tax-votes' ) . '</button></div>'
		. '<div class="eoa-tv-mapbox">' . $svg . '<div class="eoa-tv-tip" data-eoa-tip hidden></div></div>'
		. '<p class="eoa-tv-muted eoa-tv-source">' . esc_html( (string) $geo['source'] ) . '</p>'
		. '</section>';
}
