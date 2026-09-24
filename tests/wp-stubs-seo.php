<?php
/** Minimal WordPress stubs, enough to execute DOS_Module_SEO::render_head(). */

define( 'ABSPATH', '/tmp/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options'] = array();
$GLOBALS['state']   = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function apply_filters( $t, $v ) { return $v; }
function add_action() {} function remove_action() {} function add_filter() {}
function __( $s, $d = '' ) { return $s; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function is_wp_error( $t ) { return false; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function user_trailingslashit( $s ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function absint( $v ) { return abs( (int) $v ); }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function current_time() { return date( 'Y-m-d H:i:s' ); }
function home_url( $p = '/' ) { return 'https://example.com' . $p; }
// The ampersand here is deliberate: get_bloginfo() returns display-filtered
// text, exactly what a real WordPress install hands back, entity and all.
function get_bloginfo( $w ) {
    return array( 'name' => 'Acme Heating &amp; Air', 'description' => 'A site about things', 'language' => 'en-US' )[ $w ] ?? '';
}
function wp_get_document_title() { return $GLOBALS['state']['title'] ?? 'A Post Title | Acme Heating &amp; Air'; }
function get_query_var( $v ) { return $GLOBALS['state'][ $v ] ?? 0; }
function get_pagenum_link( $n ) { return 'https://example.com/page/' . $n . '/'; }
function get_queried_object_id() { return $GLOBALS['state']['queried_id'] ?? 0; }
function get_queried_object() { return $GLOBALS['state']['queried_object'] ?? null; }
function get_permalink( $id = 0 ) { return 'https://example.com/hello-world/'; }
function get_term_link( $t ) { return 'https://example.com/category/news/'; }
function get_author_posts_url( $id ) { return 'https://example.com/author/ryan/'; }
function get_the_author_meta( $f, $id ) { return 'Ryan Rose'; }
function get_post_field( $f, $id ) { return 7; }
function get_the_title( $id = 0 ) { return 'A Post Title'; }
function get_the_date( $f, $id = 0 ) { return '2026-01-15T09:00:00+00:00'; }
// The unfiltered pair the SEO module uses for anything machine-readable.
function get_post_time( $f, $gmt = false, $post = null, $translate = false ) { return '2026-01-15T09:00:00+00:00'; }
function get_post_modified_time( $f, $gmt = false, $post = null, $translate = false ) { return '2026-02-01T11:30:00+00:00'; }
function get_the_modified_date( $f, $id = 0 ) { return '2026-02-01T11:30:00+00:00'; }
function get_the_category( $id = 0 ) { return array( (object) array( 'name' => 'News' ) ); }
function get_the_archive_description() { return ''; }
function get_the_archive_title() { return 'Archives'; }
function get_post_thumbnail_id( $id ) { return $GLOBALS['state']['thumb_id'] ?? 0; }
function attachment_url_to_postid( $u ) { return 0; }
function strip_shortcodes( $c ) { return $c; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['state']['meta'][ $k ] ?? ''; }
function update_post_meta() {} function delete_post_meta() {}
function get_post( $id = 0 ) { return $GLOBALS['state']['post'] ?? null; }
function wp_get_attachment_image_src( $id, $size ) {
    return array( 'https://example.com/wp-content/uploads/hero.jpg', 1200, 630 );
}
function is_front_page() { return $GLOBALS['state']['view'] === 'front'; }
function is_home() { return $GLOBALS['state']['view'] === 'home'; }
function is_singular( $t = '' ) {
    if ( $GLOBALS['state']['view'] !== 'singular' ) { return false; }
    return $t === '' ? true : ( $GLOBALS['state']['post_type'] ?? 'post' ) === $t;
}
function is_category() { return $GLOBALS['state']['view'] === 'category'; }
function is_tag() { return false; } function is_tax() { return false; }
function is_author() { return $GLOBALS['state']['view'] === 'author'; }
function is_search() { return $GLOBALS['state']['view'] === 'search'; }
function is_404() { return $GLOBALS['state']['view'] === '404'; }
function is_archive() { return in_array( $GLOBALS['state']['view'], array( 'archive', 'category', 'author' ), true ); }

class DOS_Log { public static function add() {} }
class FakeQuery { public $max_num_pages = 5; }
$GLOBALS['wp_query'] = new FakeQuery();
$GLOBALS['wp'] = (object) array( 'request' => 'archive' );
function add_query_arg( $a, $b = null ) { return '/archive/'; }

$base = dirname( __DIR__ ) . '/plugins/dos-toolkit/';
require_once $base . 'includes/class-dos-settings.php';
require_once $base . 'includes/class-dos-module.php';
require_once $base . 'modules/seo/class-dos-seo.php';
