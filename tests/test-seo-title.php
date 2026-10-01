<?php
/**
 * Per-post SEO title. The rule being pinned: a hand-written title becomes the
 * whole document title, and the same text reaches og:title and the schema
 * WebPage name — because they all read it back from wp_get_document_title() —
 * without an entity leaking into either.
 */
require __DIR__ . '/wp-stubs-seo.php';

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

$GLOBALS['options']['blog_public'] = 1;

// Real init(), so the filter is registered the way production registers it.
DOS_Module_SEO::init();

function view( array $state ) {
    DOS_Module_SEO::reset_context_cache();
    $GLOBALS['state'] = $state;
}

function head() {
    ob_start();
    DOS_Module_SEO::render_head();
    return ob_get_clean();
}

function webpage_node( $out ) {
    preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
    $json = $m ? json_decode( $m[1], true ) : array( '@graph' => array() );
    foreach ( $json['@graph'] as $node ) {
        if ( 'WebPage' === $node['@type'] ) { return $node; }
    }
    return array();
}

function post_view( $id, $type = 'post' ) {
    return array(
        'view' => 'singular', 'post_type' => $type, 'queried_id' => $id,
        'post' => (object) array( 'ID' => $id, 'post_title' => 'A Post Title', 'post_excerpt' => 'An excerpt.', 'post_content' => '' ),
    );
}

echo "--- the filter is hooked ---\n";
check( 'init() registers pre_get_document_title', ! empty( $GLOBALS['filters']['pre_get_document_title'] ) );

echo "\n--- an override reaches the title, og:title and the schema ---\n";
$GLOBALS['pmeta'][7][ DOS_Module_SEO::TITLE_KEY ] = 'Furnace Repair & Install | Acme';
view( post_view( 7 ) );

check( 'the document title is the override, whole, escaped for markup because core prints it raw', 'Furnace Repair &amp; Install | Acme' === wp_get_document_title(), wp_get_document_title() );

$out = head();
preg_match( '#<meta property="og:title" content="([^"]*)">#', $out, $m );
$og = $m[1] ?? '';
check( 'og:title carries it, encoded exactly once', 'Furnace Repair &amp; Install | Acme' === $og, $og );
check( '  not double-encoded', false === strpos( $og, '&amp;amp;' ) );

$page = webpage_node( $out );
check( 'schema WebPage name is the decoded text', ( $page['name'] ?? null ) === 'Furnace Repair & Install | Acme', json_encode( $page ) );
check( '  no entity of any kind inside the JSON-LD', ! preg_match( '#<script type="application/ld\+json">[^<]*&(amp|\#\d+);#', $out ) );
check( 'the site-name suffix is not appended behind it', false === strpos( $og, 'Heating' ) );

echo "\n--- a title stored with an entity does not double-encode ---\n";
$GLOBALS['pmeta'][7][ DOS_Module_SEO::TITLE_KEY ] = 'Tom &amp; Jerry &#8211; Plumbing';
view( post_view( 7 ) );
check( 'decoded, then escaped exactly once', 'Tom &amp; Jerry – Plumbing' === wp_get_document_title(), wp_get_document_title() );
$page = webpage_node( head() );
check( '  and the schema name is plain text', ( $page['name'] ?? null ) === 'Tom & Jerry – Plumbing', json_encode( $page ) );

echo "\n--- a stored payload cannot break out of <title> ---\n";
// sanitize_text_field() does not decode entities, so this survives a save.
// pre_get_document_title returns early and core prints the result unescaped.
$payload = '&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;';
$GLOBALS['pmeta'][8][ DOS_Module_SEO::TITLE_KEY ] = $payload;
view( post_view( 8 ) );
$doc = wp_get_document_title();
check( 'the filter output has no raw <script', false === stripos( $doc, '<script' ), $doc );
check( '  and no raw </title>', false === stripos( $doc, '</title' ), $doc );
check( '  the text is still there, as text', false !== strpos( $doc, '&lt;script&gt;alert(1)' ), $doc );
$out = head();
check( 'og:title has no raw <script', 1 === preg_match( '#<meta property="og:title" content="[^"<]*">#', $out ) );
preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
check( 'the JSON-LD block holds no raw <script or </title>', false === stripos( $m[1] ?? '', '<script' ) && false === stripos( $m[1] ?? '', '</title' ), $m[1] ?? '' );
check( '  and only the one legitimate script tag is in the head output', 1 === substr_count( strtolower( $out ), '<script' ), $out );
$GLOBALS['written'] = array();

echo "\n--- themes that print wp_title() ---\n";
$GLOBALS['pmeta'][7][ DOS_Module_SEO::TITLE_KEY ] = 'Furnace Repair & Install | Acme';
view( post_view( 7 ) );
check( 'wp_title is hooked', ! empty( $GLOBALS['filters']['wp_title'] ) );
check( 'returns the same escaped title on a singular view', 'Furnace Repair &amp; Install | Acme' === apply_filters( 'wp_title', 'Theme Title' ), apply_filters( 'wp_title', 'Theme Title' ) );
check( '  agreeing with the title-tag path', apply_filters( 'wp_title', 'x' ) === wp_get_document_title() );
$GLOBALS['pmeta'][8][ DOS_Module_SEO::TITLE_KEY ] = $payload;
view( post_view( 8 ) );
check( '  and carries no raw markup either', false === stripos( apply_filters( 'wp_title', '' ), '<' ) );
unset( $GLOBALS['pmeta'][7], $GLOBALS['pmeta'][8] );
view( post_view( 7 ) );
check( 'leaves the theme title alone with no custom title', 'Theme Title' === apply_filters( 'wp_title', 'Theme Title' ) );
view( array( 'view' => 'category', 'queried_id' => 0, 'queried_object' => (object) array( 'name' => 'News', 'description' => '' ) ) );
check( '  and on an archive', 'Theme Title' === apply_filters( 'wp_title', 'Theme Title' ) );
$GLOBALS['pmeta'][7][ DOS_Module_SEO::TITLE_KEY ] = 'Furnace Repair & Install | Acme';

echo "\n--- no override leaves the normal title alone ---\n";
unset( $GLOBALS['pmeta'][7] );
view( array_merge( post_view( 7 ), array( 'title' => 'A Post Title | Acme Heating &amp; Air' ) ) );
check( 'normal title when the field is blank', 'A Post Title | Acme Heating &amp; Air' === wp_get_document_title(), wp_get_document_title() );

echo "\n--- a static front page ---\n";
$GLOBALS['pmeta'][2][ DOS_Module_SEO::TITLE_KEY ] = 'Acme Heating & Air | Tri-Cities HVAC';
view( array( 'view' => 'front', 'queried_id' => 2, 'post' => null ) );
check( 'applies on the front page', 'Acme Heating &amp; Air | Tri-Cities HVAC' === wp_get_document_title(), wp_get_document_title() );
$out = head();
check( '  and reaches og:title', false !== strpos( $out, 'property="og:title" content="Acme Heating &amp; Air | Tri-Cities HVAC"' ) );

echo "\n--- views that are not a single post are untouched ---\n";
$GLOBALS['pmeta'][5][ DOS_Module_SEO::TITLE_KEY ] = 'Should not be used';
view( array( 'view' => 'home', 'queried_id' => 5, 'title' => 'Blog | Acme' ) );
check( 'the posts page keeps the theme title', 'Blog | Acme' === wp_get_document_title(), wp_get_document_title() );
view( array( 'view' => 'category', 'queried_id' => 0, 'title' => 'News | Acme', 'queried_object' => (object) array( 'name' => 'News', 'description' => '' ) ) );
check( 'an archive keeps the theme title', 'News | Acme' === wp_get_document_title(), wp_get_document_title() );

echo "\n--- saving ---\n";
$_POST = array( 'dos_seo_nonce' => 'x', 'dos_seo_title' => '  Boiler Service <b>Plans</b>  ' );
$GLOBALS['written'] = array();
DOS_Module_SEO::save_meta_box( 31, (object) array() );
check( 'a title is saved, sanitised', array( 'update', 31, '_dos_seo_title', 'Boiler Service Plans' ) === ( $GLOBALS['written'][0] ?? null ), json_encode( $GLOBALS['written'] ) );

$_POST = array( 'dos_seo_nonce' => 'x', 'dos_seo_title' => '' );
$GLOBALS['written'] = array();
DOS_Module_SEO::save_meta_box( 31, (object) array() );
check( 'a blank title deletes the meta', array( 'delete', 31, '_dos_seo_title' ) === ( $GLOBALS['written'][0] ?? null ), json_encode( $GLOBALS['written'] ) );

$_POST = array( 'dos_seo_title' => 'No nonce' );
$GLOBALS['written'] = array();
DOS_Module_SEO::save_meta_box( 31, (object) array() );
check( 'no nonce, no write', array() === $GLOBALS['written'] );
$_POST = array();

echo "\n--- the field in the Search & Social box ---\n";
function wp_nonce_field() {}
function esc_html_e( $s, $d = '' ) { echo esc_html( $s ); }
class DOS_Media_Field { public static function render() {} }
$GLOBALS['pmeta'][40][ DOS_Module_SEO::TITLE_KEY ] = 'Saved "title" & more';
view( post_view( 40 ) );
ob_start();
DOS_Module_SEO::render_meta_box( (object) array( 'ID' => 40 ) );
$box = ob_get_clean();
check( 'has the field, with the stored value escaped', false !== strpos( $box, 'name="dos_seo_title" value="Saved &quot;title&quot; &amp; more"' ), $box );
check( '  above the meta description', strpos( $box, 'dos_seo_title' ) < strpos( $box, 'dos_seo_description' ) );
check( '  shows the 60-character hint', false !== strpos( $box, '60 characters' ) );
check( '  and the fallback it uses when blank', false !== strpos( $box, '<em>A Post Title</em>' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
