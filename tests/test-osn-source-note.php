<?php
/**
 * OSN\Source_Note: the sentences with and without the name, the date and an About page, latest() / site_latest(),
 * and where the note is printed (the city grid, once; not the REST parts).
 */

require __DIR__ . '/wp-stubs-osn.php';
osn_load();

use OSN\Cards;
use OSN\Source_Note;

function sn_reset() {
	osn_reset();
}
function sn_about( $status = 'publish' ) {
	$p = osn_post( 80, 'about', '' );
	$p->post_status = $status;
}

echo "--- html() ---\n";
sn_reset();
sn_about();
$h = Source_Note::html( '2026-10-01' );
check( 'one paragraph with the two classes', 0 === strpos( $h, '<p class="osn osn-source-note">' ) && '</p>' === substr( $h, -4 ) && 1 === substr_count( $h, '<p ' ) );
check( 'compiled by the default name', false !== strpos( $h, 'Compiled by Ryan R.' ) );
check( 'a name that ends in a full stop does not get a second one', false === strpos( $h, 'R..' ) && false !== strpos( $h, 'Compiled by Ryan R. Listings' ) );
check( 'where the data comes from', false !== strpos( $h, 'Listings come from Google Maps listing data and each venue&#039;s own website.' ) );
check( 'last checked, formatted like the venue page', false !== strpos( $h, 'Last checked October 1, 2026.' ), $h );
check( 'link to the About page', false !== strpos( $h, '<a href="https://example.com/about/">How this directory is made</a>' ) );
check( 'order: name, source, date, link', strpos( $h, 'Compiled' ) < strpos( $h, 'Listings' ) && strpos( $h, 'Listings' ) < strpos( $h, 'Last checked' ) && strpos( $h, 'Last checked' ) < strpos( $h, '<a ' ) );

check( 'a site-wide date says "Directory last updated"', false !== strpos( Source_Note::html( '2026-10-01', true ), 'Directory last updated October 1, 2026.' ) && false === strpos( Source_Note::html( '2026-10-01', true ), 'Last checked' ) );
check( 'and a grid keeps "Last checked"', false === strpos( $h, 'Directory last updated' ) );

sn_reset();
add_filter( 'osn_compiled_by', function () { return ''; } );
$h = Source_Note::html( '2026-10-01' );
check( 'an empty name drops the first sentence', false === strpos( $h, 'Compiled' ) && 0 === strpos( $h, '<p class="osn osn-source-note">Listings come' ) );
sn_reset();
add_filter( 'osn_compiled_by', function () { return 'A & B <i>'; } );
check( 'the name is escaped', false !== strpos( Source_Note::html(), 'Compiled by A &amp; B &lt;i&gt;.' ) );

sn_reset();
foreach ( array( '', 'soon', '2026-13-45', '2026-10-1', '2026-10-01T00:00:00' ) as $bad ) {
	check( 'no date sentence for "' . $bad . '"', false === strpos( Source_Note::html( $bad ), 'Last checked' ) );
}
check( 'no About page: no link', false === strpos( Source_Note::html( '2026-10-01' ), '<a ' ) );
sn_reset();
sn_about( 'draft' );
check( 'an unpublished About page does not count', false === strpos( Source_Note::html(), '<a ' ) );
sn_reset();
add_filter( 'osn_about_url', function ( $u ) { return 'https://example.com/who/?a=1&b=2'; } );
$h = Source_Note::html();
check( 'osn_about_url overrides the lookup (and is escaped)', false !== strpos( $h, 'href="https://example.com/who/?a=1&#038;b=2"' ) );
sn_reset();
sn_about();
add_filter( 'osn_about_url', function ( $u ) { return ''; } );
check( 'osn_about_url returning an empty string drops the link', false === strpos( Source_Note::html(), '<a ' ) );
sn_reset();
sn_about();
Source_Note::html();
unset( $GLOBALS['osn']['posts'][80] );
check( 'the About lookup is memoised for the request', false !== strpos( Source_Note::html(), '/about/' ) );

echo "\n--- latest() ---\n";
sn_reset();
$m = &$GLOBALS['osn']['meta'];
$m[1]['osn_enriched_at'] = '2026-09-01';
$m[2]['osn_enriched_at'] = '2026-10-05T08:00:00Z';
$m[3]['osn_enriched_at'] = '2026-08-30';
$m[4]['osn_enriched_at'] = 'garbage';
$m[5]['osn_enriched_at'] = '9999-99-99';
$m[6]['osn_enriched_at'] = '';
$key = OSN\Fields::key( 'enriched_at' );
check( 'the greatest date wins (first 10 characters of a timestamp)', '2026-10-05' === Source_Note::latest( array( 1, 2, 3 ) ) );
check( 'invalid values are ignored, even when they sort highest', '2026-09-01' === Source_Note::latest( array( 1, 4, 5, 6 ) ) );
check( 'none valid: empty string', '' === Source_Note::latest( array( 4, 5, 6, 7 ) ) && '' === Source_Note::latest( array() ) );
check( 'only the given IDs are read', '2026-09-01' === Source_Note::latest( array( 1 ) ) );

echo "\n--- site_latest() ---\n";
sn_reset();
$GLOBALS['osn']['db_var'] = '2026-10-09';
check( 'one query, the greatest date', '2026-10-09' === Source_Note::site_latest() && 1 === count( $GLOBALS['osn']['calls']['db'] ) );
$GLOBALS['osn']['db_var'] = '2000-01-01';
check( 'cached: a second call runs no query', '2026-10-09' === Source_Note::site_latest() && 1 === count( $GLOBALS['osn']['calls']['db'] ) );
check( 'the query uses the enriched_at key on published venues', false !== strpos( $GLOBALS['osn']['calls']['db'][0], "post_status = 'publish'" ) && false !== strpos( $GLOBALS['osn']['calls']['db'][0], 'MAX(LEFT(pm.meta_value, 10))' ) );
sn_reset();
$GLOBALS['osn']['db_var'] = null;
check( 'no venues: empty string', '' === Source_Note::site_latest() );

echo "\n--- Cards::render() and rest_part() ---\n";
osn_world( 3 );
$GLOBALS['osn']['meta'][1001][ $key ] = '2026-10-01';
$GLOBALS['osn']['meta'][1002][ $key ] = '2026-10-04';
$GLOBALS['osn']['meta'][1003][ $key ] = '2026-09-01';
$GLOBALS['osn']['query_posts'] = array( 1001, 1002, 1003 );
osn_request( 500 );
$html = Cards::render( 'Testville, TX', 0, true, true, null, null, 0 );
check( 'the grid has the note once', 1 === substr_count( $html, 'osn-source-note' ), (string) substr_count( $html, 'osn-source-note' ) );
check( 'with the newest date of the cards on the page', false !== strpos( $html, 'Last checked October 4, 2026.' ) );
check( 'after the grid', strpos( $html, 'osn-source-note' ) > strpos( $html, '</ul>' ) );
osn_request( 500 );
$part = Cards::rest_part( new WP_REST_Request( array( 'post' => 500, 'part' => 1, 'v' => 'x' ) ) );
$body = is_object( $part ) ? ( $part->data['html'] ?? json_encode( $part->data ) ) : (string) $part;
check( 'the REST part has no note', is_object( $part ) && false === strpos( $body, 'osn-source-note' ) );

finish();
