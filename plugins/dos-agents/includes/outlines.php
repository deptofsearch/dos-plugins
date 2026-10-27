<?php
/**
 * State names and the outline drawings behind the "Browse by state" cards.
 *
 * assets/state-outlines.json is the US Census Bureau's generalized state geometry (TIGERweb
 * Generalized_ACS2023 States 20M, public domain), each state already projected and fitted to one
 * 300x200 box. It is shared with DoS - Best Lenders, which generated it.
 *
 * The outlines are drawn inline rather than written out as files and linked. There are 51 of them on
 * one page, they are decorative, and they have to recolour on hover, which an <img> cannot do. Inline
 * also means no uploads directory to be unwritable and no stale tiles to version. The file is read
 * once per request and only on a page that asks for a card.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Every state the site covers, plus DC, by postal code. */
function dos_agents_state_names() {
	return array(
		'AL' => 'Alabama',       'AK' => 'Alaska',        'AZ' => 'Arizona',       'AR' => 'Arkansas',
		'CA' => 'California',    'CO' => 'Colorado',      'CT' => 'Connecticut',   'DE' => 'Delaware',
		'DC' => 'District of Columbia',                   'FL' => 'Florida',       'GA' => 'Georgia',
		'HI' => 'Hawaii',        'ID' => 'Idaho',         'IL' => 'Illinois',      'IN' => 'Indiana',
		'IA' => 'Iowa',          'KS' => 'Kansas',        'KY' => 'Kentucky',      'LA' => 'Louisiana',
		'ME' => 'Maine',         'MD' => 'Maryland',      'MA' => 'Massachusetts', 'MI' => 'Michigan',
		'MN' => 'Minnesota',     'MS' => 'Mississippi',   'MO' => 'Missouri',      'MT' => 'Montana',
		'NE' => 'Nebraska',      'NV' => 'Nevada',        'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
		'NM' => 'New Mexico',    'NY' => 'New York',      'NC' => 'North Carolina','ND' => 'North Dakota',
		'OH' => 'Ohio',          'OK' => 'Oklahoma',      'OR' => 'Oregon',        'PA' => 'Pennsylvania',
		'RI' => 'Rhode Island',  'SC' => 'South Carolina','SD' => 'South Dakota',  'TN' => 'Tennessee',
		'TX' => 'Texas',         'UT' => 'Utah',          'VT' => 'Vermont',       'VA' => 'Virginia',
		'WA' => 'Washington',    'WV' => 'West Virginia', 'WI' => 'Wisconsin',     'WY' => 'Wyoming',
	);
}

/** The full name for a postal code, or the code itself when it is not one we cover. */
function dos_agents_state_name( $state ) {
	$names = dos_agents_state_names();
	return isset( $names[ $state ] ) ? $names[ $state ] : (string) $state;
}

/**
 * The state hub page for a postal code, or null. The hubs are named after the state
 * ("/in-washington/"); a state whose hub is missing or unpublished gets an unlinked card.
 */
function dos_agents_state_page( $state ) {
	$name = dos_agents_state_name( $state );
	if ( '' === $name ) {
		return null;
	}
	$page = get_page_by_path( 'in-' . sanitize_title( $name ), OBJECT, 'page' );
	if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
		return null;
	}
	return $page;
}

/** The decoded outline file: {viewBox: [w, h], paths: {ST: "M..."}}. Read once per request. */
function dos_agents_outlines() {
	static $data = null;
	if ( null !== $data ) {
		return $data;
	}
	$data = array(
		'viewBox' => array( 300, 200 ),
		'paths'   => array(),
	);
	$file = plugin_dir_path( DOS_AGENTS_FILE ) . 'assets/state-outlines.json';
	if ( ! is_readable( $file ) ) {
		return $data;
	}
	$raw = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a bundled file, not a remote request.
	if ( is_array( $raw ) && ! empty( $raw['paths'] ) && is_array( $raw['paths'] ) ) {
		$data['paths'] = $raw['paths'];
		if ( isset( $raw['viewBox'][0], $raw['viewBox'][1] ) ) {
			$data['viewBox'] = array( (int) $raw['viewBox'][0], (int) $raw['viewBox'][1] );
		}
	}
	return $data;
}

/**
 * The inline outline for a state, or '' when there is no drawing for it. Decorative next to the
 * state's own name, so it is labelled for screen readers rather than hidden: a card read aloud as
 * "California, 9 cities" says everything the picture does.
 */
function dos_agents_outline_svg( $state ) {
	$data = dos_agents_outlines();
	if ( empty( $data['paths'][ $state ] ) ) {
		return '';
	}
	// The path data is ours, from the bundled file, but it still goes through esc_attr like any attribute.
	return '<svg class="breanm-home__sc-img" viewBox="0 0 ' . (int) $data['viewBox'][0] . ' ' . (int) $data['viewBox'][1] . '"'
		. ' width="' . (int) $data['viewBox'][0] . '" height="' . (int) $data['viewBox'][1] . '" role="img" aria-hidden="true" focusable="false">'
		. '<path d="' . esc_attr( (string) $data['paths'][ $state ] ) . '"/></svg>';
}
