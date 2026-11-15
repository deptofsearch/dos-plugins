<?php
/**
 * DoS Tax Votes: the rendered page.
 *
 * Pins that every lawmaker in the data file is in the server-rendered HTML (nothing waits for JS),
 * that each person's headline counts match the data for all three categories, that hostile text is
 * escaped, that only well-formed biennium names reach the filesystem, and the money format.
 */

define( 'ABSPATH', '/tmp/' );
define( 'DOS_TAX_VOTES_DIR', dirname( __DIR__ ) . '/plugins/dos-tax-votes' );

$pass = 0;
$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

function __( $s, $d = null ) { return $s; }
function _n( $one, $many, $n, $d = null ) { return 1 === (int) $n ? $one : $many; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function esc_attr__( $s, $d = null ) { return esc_attr( $s ); }
function esc_url( $u ) { return preg_match( '#^https?://#', (string) $u ) ? esc_attr( $u ) : ''; }
function wp_kses( $html, $allowed ) { return $html; }
function current_user_can( $c ) { return false; }
function shortcode_atts( $defaults, $atts, $tag = '' ) { return array_merge( $defaults, array_intersect_key( (array) $atts, $defaults ) ); }
$GLOBALS['enqueued'] = array();
function wp_enqueue_style( $h ) { $GLOBALS['enqueued'][] = "style:$h"; }
function wp_enqueue_script( $h ) { $GLOBALS['enqueued'][] = "script:$h"; }

require DOS_TAX_VOTES_DIR . '/includes/render.php';

echo "--- data ---\n";
check( 'the newest shipped biennium is found', '2025-26' === dos_tax_votes_latest_biennium() );
$data = dos_tax_votes_data( '2025-26' );
check( 'the 2025-26 data file loads', is_array( $data ) && count( $data['legislators'] ) > 100 && count( $data['bills'] ) > 0 );
check( 'a path-like biennium is refused before touching the filesystem', null === dos_tax_votes_data( '../../wp-config' ) && null === dos_tax_votes_data( '2025-26/../x' ) );
check( 'an unknown biennium returns null', null === dos_tax_votes_data( '1999-00' ) );
$ups = count( array_filter( $data['bills'], function ( $b ) { return 'increase' === $b['direction']; } ) );
check( 'the headline bill counts match the bill list', $ups === (int) $data['counts']['increases'] && count( $data['bills'] ) - $ups === (int) $data['counts']['cuts'] );

echo "\n--- render ---\n";
$html = dos_tax_votes_render( $data );
check( 'every lawmaker is in the server HTML', count( $data['legislators'] ) === substr_count( $html, 'data-eoa-person ' ) );
check( 'every counted bill is in the bill table', count( $data['bills'] ) === substr_count( $html, '<tr><th scope="row">' ) );
check( 'the controls stay hidden until the script runs', false !== strpos( $html, 'data-eoa-controls hidden' ) );
check( 'the method explains why property tax exemptions are left out', false !== strpos( $html, 'Property tax exemptions are not counted' ) );

$p     = $data['legislators'][0];
$html1 = dos_tax_votes_person( $p, array_column( $data['bills'], null, 'key' ) );
$ok    = true;
foreach ( array( 'all', 'business', 'individual' ) as $cat ) {
	$s    = $p['split'][ $cat ];
	$want = implode( ',', array( $s['increasesYes'], $s['increases'], $s['cutsYes'], $s['cuts'] ) );
	if ( false === strpos( $html1, 'data-' . $cat . '="' . $want . '"' ) ) { $ok = false; }
}
check( "a lawmaker's counts for all three categories are on their row", $ok );
check( 'the visible headline is the all-taxes count', false !== strpos( $html1, '<b data-n="yes">' . (int) $p['split']['all']['increasesYes'] . '</b> of <b data-n="total">' . (int) $p['split']['all']['increases'] . '</b>' ) );

$evil = array_merge( $p, array( 'name' => '<script>alert(1)</script>', 'party' => '"><img>' ) );
$h    = dos_tax_votes_person( $evil, array() );
check( 'a hostile name and party are escaped', false === strpos( $h, '<script>' ) && false === strpos( $h, '"><img>' ) );
$h = dos_tax_votes_render( array_merge( $data, array( 'bills' => array( array_merge( $data['bills'][0], array( 'billUrl' => 'javascript:alert(1)', 'title' => '<b>x</b>' ) ) ) ) ) );
check( 'a non-http bill link is dropped and bill text is escaped', false === strpos( $h, 'javascript:' ) && false === strpos( $h, '<b>x</b>' ) );

echo "\n--- shortcode ---\n";
$out = dos_tax_votes_shortcode( array() );
check( 'the bare shortcode renders the newest biennium and enqueues its assets', false !== strpos( $out, 'data-eoa-tax-votes' ) && in_array( 'script:dos-tax-votes', $GLOBALS['enqueued'], true ) );
check( 'an unknown biennium renders nothing for visitors', '' === dos_tax_votes_shortcode( array( 'biennium' => '1999-00' ) ) );
check( 'show="method" renders only the method', false === strpos( dos_tax_votes_shortcode( array( 'show' => 'method' ) ), 'data-eoa-person' ) );

echo "\n--- money ---\n";
check( 'billions', '+$8.46B' === dos_tax_votes_money( 8462700000 ) );
check( 'millions', '+$1.7M' === dos_tax_votes_money( 1700000 ) );
check( 'negative thousands use a real minus sign', '−$96K' === dos_tax_votes_money( -96000 ) );
check( 'no estimate is blank', '' === dos_tax_votes_money( null ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
