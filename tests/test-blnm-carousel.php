<?php
/**
 * Best Lenders homepage state carousel: rollout order, card order and links, outline asset. No WordPress needed.
 */

define( 'ABSPATH', '/tmp/' );
error_reporting( E_ALL );
set_error_handler( static function ( $no, $str, $file, $line ) { throw new ErrorException( $str, 0, $no, $file, $line ); } );
$plugin = dirname( __DIR__ ) . '/plugins/dos-best-lenders';

$GLOBALS['filter'] = null;
function apply_filters( $tag, $v ) { return $GLOBALS['filter'] ? ( $GLOBALS['filter'] )( $v ) : $v; }
function esc_attr__( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html__( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function _n( $a, $b, $n ) { return 1 === (int) $n ? $a : $b; }
function number_format_i18n( $n ) { return (string) $n; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['up'], 'baseurl' => 'https://x.test/uploads' ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_mkdir_p( $d ) { try { return is_dir( $d ) || mkdir( $d, 0777, true ); } catch ( ErrorException $e ) { return false; } }
function sanitize_file_name( $s ) { return $s; }
$GLOBALS['up'] = sys_get_temp_dir() . '/blnm-carousel-' . getmypid();

require $plugin . '/includes/class-data-model.php';
require $plugin . '/includes/class-rest.php';
require $plugin . '/includes/class-maps.php';
require $plugin . '/includes/class-render.php';
require $plugin . '/includes/class-search.php';
require $plugin . '/includes/class-shortcodes.php';

use BLNM\Maps;
use BLNM\Rest;
use BLNM\Shortcodes;

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; } else { $fail++; echo "  FAIL  $label\n"; }
}

// ---- Rollout order ----
$order = Rest::ROLLOUT;
check( 'rollout list has 51 entries', 51 === count( $order ) );
check( 'rollout codes are unique', 51 === count( array_unique( $order ) ) );
check( 'every rollout code is a known state', ! array_diff( $order, array_keys( Rest::STATE_NAMES ) ) && ! array_diff( array_keys( Rest::STATE_NAMES ), $order ) );
check( 'WA first, HI last', 'WA' === $order[0] && 'HI' === $order[50] && 'OR' === $order[1] && 'ND' === $order[11] );
check( 'rollout_order() matches the constant', Rest::rollout_order() === $order );
$GLOBALS['filter'] = static function ( $v ) { return array( 'tx', 'ZZ', 'TX', 'WA' ); };
$f = Rest::rollout_order();
check( 'filter: normalised, deduped, unknown dropped, missing appended', 'TX' === $f[0] && 'WA' === $f[1] && 51 === count( $f ) && 51 === count( array_unique( $f ) ) && ! in_array( 'ZZ', $f, true ) );
$GLOBALS['filter'] = null;

// ---- Cards ----
$live  = array(
	array( 'c' => 'WA', 'name' => 'Washington', 'n' => 91, 'u' => 'https://x.test/washington/' ),
	array( 'c' => 'CA', 'name' => 'California', 'n' => 1, 'u' => 'https://x.test/california/' ),
);
$cards = Rest::carousel_cards( $live );
check( '51 cards', 51 === count( $cards ) );
check( 'live first in rollout order', 'WA' === $cards[0]['c'] && 'CA' === $cards[1]['c'] && $cards[0]['live'] && $cards[1]['live'] && ! $cards[2]['live'] );
check( 'upcoming follow rollout order and skip live', 'OR' === $cards[2]['c'] && 'NV' === $cards[3]['c'] && 'HI' === $cards[50]['c'] );

$html = Shortcodes::carousel( $cards );
check( 'section is a labelled carousel', false !== strpos( $html, 'aria-roledescription="carousel"' ) && false !== strpos( $html, 'aria-label="Browse by state"' ) );
check( 'buttons labelled and hidden until JS', false !== strpos( $html, 'aria-label="Previous states" hidden' ) && false !== strpos( $html, 'aria-label="Next states" hidden' ) );
check( 'track is focusable', false !== strpos( $html, 'class="blnm-carousel-track" tabindex="0"' ) );
preg_match_all( '/<li class="blnm-sc blnm-sc--(live|soon)">(.*?)<\/li>/s', $html, $m );
check( '51 list items', 51 === count( $m[1] ) );
check( 'live items come first', array( 'live', 'live' ) === array_slice( $m[1], 0, 2 ) && 49 === count( array_filter( array_slice( $m[1], 2 ), static function ( $c ) { return 'soon' === $c; } ) ) );
check( 'live cards link to their page with a city count', false !== strpos( $m[2][0], '<a class="blnm-sc-link" href="https://x.test/washington/">' ) && false !== strpos( $m[2][0], '91 cities' ) && false !== strpos( $m[2][1], '1 city' ) );
$soon_ok = true;
foreach ( $m[2] as $i => $inner ) {
	if ( 'soon' === $m[1][ $i ] && ( false !== strpos( $inner, '<a ' ) || false === strpos( $inner, 'Coming soon' ) ) ) {
		$soon_ok = false;
	}
}
check( 'coming-soon cards have no link and say so', $soon_ok );
check( 'only 2 links in the carousel', 2 === substr_count( $html, '<a ' ) );

// ---- Outline files ----
check( 'cards carry lazy outline images with alt', 51 === substr_count( $html, 'loading="lazy"' ) && false !== strpos( $html, 'alt="Outline map of Washington"' ) && false !== strpos( $html, 'width="300" height="200"' ) );
$url = Maps::outline_url( 'WA', 'Washington' );
$file = $GLOBALS['up'] . '/blnm-maps/v' . Maps::TILE_VER . '/states/' . basename( $url );
check( 'outline url under states/ and file written', false !== strpos( $url, '/blnm-maps/v' . Maps::TILE_VER . '/states/wa-' ) && is_file( $file ) );
$svg = (string) @file_get_contents( $file );
check( 'svg uses brand colours only and has no dot', false !== strpos( $svg, Maps::COLORS['paper'] ) && false !== strpos( $svg, Maps::COLORS['rule'] ) && false === strpos( $svg, '<circle' ) );
check( 'second call reuses the same url', Maps::outline_url( 'WA', 'Washington' ) === $url );
$GLOBALS['up'] = '/dev/null/nope';
check( 'unwritable uploads gives no image, no crash', '' === Maps::outline_url( 'OR', 'Oregon' ) );
$noimg = Shortcodes::carousel( array( array( 'c' => 'OR', 'name' => 'Oregon', 'n' => 0, 'u' => '', 'live' => false ) ) );
check( 'card still renders without an image', false === strpos( $noimg, '<img' ) && false !== strpos( $noimg, 'Oregon' ) );

// ---- Outline asset ----
$asset = json_decode( (string) file_get_contents( $plugin . '/assets/state-outlines.json' ), true );
check( 'asset viewBox is 300x200', array( 300, 200 ) === $asset['viewBox'] );
check( 'asset has all 51 states', 51 === count( $asset['paths'] ) && ! array_diff( array_keys( Rest::STATE_NAMES ), array_keys( $asset['paths'] ) ) );
$bad = array();
foreach ( $asset['paths'] as $st => $d ) {
	$ok = (bool) preg_match( '/^(?:[ML]-?\d+ -?\d+|Z)+$/', $d ) && 'M' === $d[0];
	preg_match_all( '/[ML](-?\d+) (-?\d+)/', $d, $pts );
	foreach ( $pts[1] as $x ) { $ok = $ok && $x >= 0 && $x <= 300; }
	foreach ( $pts[2] as $y ) { $ok = $ok && $y >= 0 && $y <= 200; }
	if ( ! $ok || count( $pts[1] ) < 3 ) { $bad[] = $st; }
}
check( 'every path is integer-only and inside the viewBox' . ( $bad ? ' (bad: ' . implode( ',', $bad ) . ')' : '' ), ! $bad );
check( 'asset under 60 KB', filesize( $plugin . '/assets/state-outlines.json' ) < 60000 );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
