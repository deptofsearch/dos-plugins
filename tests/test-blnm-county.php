<?php
/** Best Lenders: county labels and county-name sanitizing. Runs without WordPress. */
define( 'ABSPATH', '/tmp/' );
error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
function __( $s ) { return $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return (string) $s; }
function esc_html__( $s ) { return $s; }
function esc_attr__( $s ) { return $s; }
function esc_attr_e( $s ) { echo $s; }
function esc_html_e( $s ) { echo $s; }
function wp_date( $f, $t ) { return date( $f, $t ); }
function number_format_i18n( $n ) { return number_format( $n ); }
require dirname( __DIR__ ) . '/plugins/dos-best-lenders/includes/class-render.php';
require dirname( __DIR__ ) . '/plugins/dos-best-lenders/includes/class-data-model.php';
use BLNM\Render;
use BLNM\Data_Model;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; $fail += $ok ? 0 : 1; }

$cases = array(
	array( 'King', 'WA', 'King County' ),
	array( 'King County', 'WA', 'King County' ),
	array( 'king county', 'WA', 'king County' ),
	array( 'Orleans Parish', 'LA', 'Orleans Parish' ),
	array( 'orleans parish', 'LA', 'orleans Parish' ),
	array( 'Baltimore city', 'MD', 'City of Baltimore' ),
	array( 'St. Louis city', 'MO', 'City of St. Louis' ),
	array( 'Richmond city', 'VA', 'City of Richmond' ),
	array( 'Alexandria city', 'VA', 'City of Alexandria' ),
	array( 'Anchorage Municipality', 'AK', 'Anchorage Municipality' ),
	array( 'Juneau City and Borough', 'AK', 'Juneau City and Borough' ),
	array( 'Matanuska-Susitna Borough', 'AK', 'Matanuska-Susitna Borough' ),
	array( 'Bethel Census Area', 'AK', 'Bethel Census Area' ),
	array( 'Capitol Planning Region', 'CT', 'Capitol Planning Region' ),
	array( 'District of Columbia', 'DC', 'District of Columbia' ),
	array( 'Carson City', 'NV', 'Carson City' ),
	array( 'Carson City', 'nv', 'Carson City' ),
	array( 'Carson City', '', 'Carson City County' ),
	array( 'New York city', 'NY', 'New York City (five boroughs)' ),
	array( 'New York City', 'ny', 'New York City (five boroughs)' ),
	array( 'New York', 'NY', 'New York County' ),
	array( 'Kings', 'NY', 'Kings County' ),
	array( '', 'WA', '' ),
	array( '  ', 'WA', '' ),
);
foreach ( $cases as $c ) {
	$got = Render::county_label( $c[0], $c[1] );
	check( "label '{$c[0]}' {$c[1]} => '{$c[2]}' (got '$got')", $c[2] === $got );
}

$s = array(
	'King County'             => 'King',
	'King'                    => 'King',
	'Orleans Parish'          => 'Orleans Parish',
	'Baltimore city'          => 'Baltimore city',
	'Carson City'             => 'Carson City',
	'Juneau City and Borough' => 'Juneau City and Borough',
	' Pierce  County '        => 'Pierce',
);
foreach ( $s as $in => $out ) {
	check( "sanitize '$in' => '$out'", $out === Data_Model::sanitize_county_name( $in ) );
}
foreach ( array( 'King', 'King County', 'Orleans Parish', 'Baltimore city', 'Carson City', 'Anchorage Municipality' ) as $n ) {
	$st = Data_Model::sanitize_county_name( $n );
	check( "round-trip '$n' stable", Data_Model::sanitize_county_name( $st ) === $st );
}
check( 'stored King renders King County', 'King County' === Render::county_label( Data_Model::sanitize_county_name( 'King County' ), 'WA' ) );
check( 'stored NYC pseudo-county renders five boroughs', 'New York City (five boroughs)' === Render::county_label( Data_Model::sanitize_county_name( 'New York city' ), 'NY' ) );
check( 'stored Orleans Parish renders as-is', 'Orleans Parish' === Render::county_label( Data_Model::sanitize_county_name( 'Orleans Parish' ), 'LA' ) );

$l = array( array( 'name' => 'A Lender', 'type' => 'mortgage_company', 'loans_2025' => 10, 'score' => 80 ) );
$h = Render::city( array( 'name' => 'Carson City', 'state' => 'NV', 'county' => 'Carson City', 'year' => 2025 ), $l );
check( 'Carson header has no "County"', false !== strpos( $h, '1 lender in Carson City, NV · 2025 HMDA data' ) && false === strpos( $h, 'Carson City County' ) );
$h = Render::city( array( 'name' => 'Seattle', 'state' => 'WA', 'county' => 'King', 'year' => 2025 ), $l );
check( 'Seattle header', false !== strpos( $h, '1 lender in King County, WA · 2025 HMDA data' ) );
$h = Render::city( array( 'name' => 'New Orleans', 'state' => 'LA', 'county' => 'Orleans Parish', 'year' => 2025, 'reviewed_at' => '2026-10-01' ), array() );
check( 'no-lender block uses parish', false !== strpos( $h, 'No lender in Orleans Parish made our list yet' ) );

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
