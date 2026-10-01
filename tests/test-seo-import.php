<?php
/**
 * Importing titles and descriptions from Yoast and All in One SEO.
 *
 * These write to live posts, so what is pinned is the careful half: variables
 * resolve, the ones that cannot are stripped and reported, a value already set
 * here is never replaced, and a dry run writes nothing at all.
 */
require __DIR__ . '/wp-stubs-seo.php';

define( 'ARRAY_A', 'ARRAY_A' );

$pass = 0;
$fail = 0;

function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

/**
 * $wpdb over the in-memory fixtures: Yoast data lives in $GLOBALS['pmeta'],
 * AIOSEO's in $GLOBALS['aioseo_rows'].
 */
$GLOBALS['tables']      = array( 'wp_aioseo_posts' );
$GLOBALS['aioseo_rows'] = array();
$GLOBALS['queries']     = array();   // every query a read method received, after prepare()
$GLOBALS['bad_prepare'] = array();   // prepare() calls whose placeholders and args disagree

class FakeWpdb {
    public $prefix   = 'wp_';
    public $postmeta = 'wp_postmeta';

    public function prepare( $q, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        if ( preg_match_all( '/%[sd]/', $q ) !== count( $args ) ) { $GLOBALS['bad_prepare'][] = $q; }
        return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$args ) {
            $v = array_shift( $args );
            return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'";
        }, $q );
    }
    public function esc_like( $v ) { return addcslashes( (string) $v, '_%\\' ); }

    private function yoast_ids() {
        $ids = array();
        foreach ( $GLOBALS['pmeta'] as $id => $meta ) {
            foreach ( $meta as $k => $v ) {
                if ( 0 === strpos( $k, '_yoast_wpseo_' ) && '' !== $v ) { $ids[ $id ] = true; }
            }
        }
        ksort( $ids );
        return array_keys( $ids );
    }
    private function page( array $list, $q ) {
        preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $q, $m );
        return array_slice( $list, (int) $m[2], (int) $m[1] );
    }
    private function aioseo_rows() {
        $rows = array_filter( $GLOBALS['aioseo_rows'], function ( $r ) {
            return '' !== (string) $r['title'] || '' !== (string) $r['description'] || ( 0 === (int) $r['robots_default'] && 1 === (int) $r['robots_noindex'] ) || '' !== (string) $r['canonical_url'];
        } );
        usort( $rows, function ( $a, $b ) { return $a['post_id'] - $b['post_id']; } );
        return $rows;
    }

    public function get_var( $q ) {
        $GLOBALS['queries'][] = $q;
        if ( preg_match( "/SHOW TABLES LIKE '([^']*)'/", $q, $m ) ) {
            $name = stripslashes( $m[1] );
            return in_array( $name, $GLOBALS['tables'], true ) ? $name : null;
        }
        if ( false !== strpos( $q, 'COUNT(DISTINCT post_id)' ) ) { return count( $this->yoast_ids() ); }
        if ( false !== strpos( $q, 'FROM wp_aioseo_posts' ) ) { return count( $this->aioseo_rows() ); }
        return 0;
    }
    public function get_col( $q ) { $GLOBALS['queries'][] = $q; return $this->page( $this->yoast_ids(), $q ); }
    public function get_results( $q, $out = null ) { $GLOBALS['queries'][] = $q; return $this->page( $this->aioseo_rows(), $q ); }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function reset_site() {
    $GLOBALS['pmeta']       = array();
    $GLOBALS['written']     = array();
    $GLOBALS['log']         = array();
    $GLOBALS['queries']     = array();
    $GLOBALS['bad_prepare'] = array();
    $GLOBALS['aioseo_rows'] = array();
    $GLOBALS['tables']      = array( 'wp_aioseo_posts' );
    $GLOBALS['options']     = array();
    $GLOBALS['state']       = array( 'view' => 'singular', 'posts' => array(), 'categories' => array() );
}
function add_post( $id, $title, $excerpt = '' ) {
    $GLOBALS['state']['posts'][ $id ] = (object) array( 'ID' => $id, 'post_title' => $title, 'post_excerpt' => $excerpt, 'post_content' => '' );
}
function meta( $id, $key ) { return $GLOBALS['pmeta'][ $id ][ $key ] ?? null; }
function all_notes( array $result ) { return implode( "\n", $result['notes'] ); }
function writes_to( $key ) {
    return array_values( array_filter( $GLOBALS['written'], function ( $w ) use ( $key ) { return $w[2] === $key; } ) );
}

const T = '_dos_seo_title';
const D = '_dos_seo_description';

echo "--- separators ---\n";
$expected = array(
    'sc-dash' => '-', 'sc-pipe' => '|', 'sc-ndash' => '–', 'sc-mdash' => '—', 'sc-middot' => '·', 'sc-bull' => '•',
    'sc-star' => '*', 'sc-smaller' => '<', 'sc-greater' => '>', 'sc-tilde' => '~', 'sc-raquo' => '»', 'sc-laquo' => '«',
);
$all = true;
foreach ( $expected as $key => $char ) {
    if ( DOS_SEO_Import::yoast_separator( $key ) !== $char ) { $all = false; echo "    $key\n"; }
}
check( 'every Yoast separator key maps to its character', $all );
check( 'an unknown or missing key falls back to a hyphen', '-' === DOS_SEO_Import::yoast_separator( 'sc-weird' ) && '-' === DOS_SEO_Import::yoast_separator( '' ) );
check( 'AIOSEO: reads the entity-encoded separator from its JSON option', '·' === DOS_SEO_Import::aioseo_separator( '{"searchAppearance":{"global":{"separator":"&middot;"}}}' ) );
check( '  a numeric entity', '-' === DOS_SEO_Import::aioseo_separator( '{"searchAppearance":{"global":{"separator":"&#45;"}}}' ) );
check( '  a plain pipe', '|' === DOS_SEO_Import::aioseo_separator( '{"searchAppearance":{"global":{"separator":"|"}}}' ) );
check( '  missing or unreadable option gives a hyphen', '-' === DOS_SEO_Import::aioseo_separator( '' ) && '-' === DOS_SEO_Import::aioseo_separator( 'not json' ) && '-' === DOS_SEO_Import::aioseo_separator( '{"searchAppearance":[]}' ) );

echo "\n--- resolving Yoast variables ---\n";
$vars = array( 'title' => 'Furnace Repair', 'sitename' => 'Acme Heating & Air', 'sep' => '|', 'excerpt' => 'We fix furnaces.', 'category' => 'Heating' );
$r = DOS_SEO_Import::resolve_yoast( '%%title%% %%page%% %%sep%% %%sitename%%', $vars );
check( 'title, separator, site name; %%page%% vanishes without a note', 'Furnace Repair | Acme Heating & Air' === $r['text'] && array() === $r['unresolved'], json_encode( $r ) );
$r = DOS_SEO_Import::resolve_yoast( '%%excerpt%% Filed under %%primary_category%% / %%category%%.', $vars );
check( 'excerpt and both category variables', 'We fix furnaces. Filed under Heating / Heating.' === $r['text'], $r['text'] );
$r = DOS_SEO_Import::resolve_yoast( '%%title%% %%sep%% %%cf_subtitle%% %%sep%% %%sitename%%', $vars );
check( 'an unresolved variable is removed', 'Furnace Repair | Acme Heating & Air' === $r['text'], $r['text'] );
check( '  its stray separator goes with it', false === strpos( $r['text'], '| |' ) );
check( '  and it is named', array( '%%cf_subtitle%%' ) === $r['unresolved'], json_encode( $r['unresolved'] ) );
$r = DOS_SEO_Import::resolve_yoast( '%%title%% %%sep%% %%ct_custom%%', $vars );
check( 'a trailing separator left by a stripped variable goes too', 'Furnace Repair' === $r['text'], $r['text'] );
$r = DOS_SEO_Import::resolve_yoast( '%%sitename%% - Tri-Cities %%focuskw%%', $vars );
check( 'a hyphen inside a word survives', 'Acme Heating & Air - Tri-Cities' === $r['text'], $r['text'] );
$r = DOS_SEO_Import::resolve_yoast( 'Plumbers %%sep%% %%title%%', array_merge( $vars, array( 'sep' => '<' ) ) );
check( '"<" as a separator is not read as the start of a tag', 'Plumbers < Furnace Repair' === $r['text'], $r['text'] );
$r = DOS_SEO_Import::resolve_yoast( '100%% sure', $vars );
check( 'a literal percent sign is left alone', '100%% sure' === $r['text'], $r['text'] );

echo "\n--- resolving AIOSEO smart tags ---\n";
$r = DOS_SEO_Import::resolve_aioseo( '#post_title #separator_sa #site_title', $vars );
check( 'post title, separator, site title', 'Furnace Repair | Acme Heating & Air' === $r['text'] && array() === $r['unresolved'], json_encode( $r ) );
$r = DOS_SEO_Import::resolve_aioseo( '#post_excerpt (#categories, #taxonomy_title)', $vars );
check( 'excerpt and category tags', 'We fix furnaces. (Heating, Heating)' === $r['text'], $r['text'] );
$r = DOS_SEO_Import::resolve_aioseo( '#post_title #separator_sa #custom_field-subtitle #separator_sa #author_name', $vars );
check( 'unsupported tags are removed', 'Furnace Repair' === $r['text'], $r['text'] );
check( '  and named', array( '#custom_field-subtitle', '#author_name' ) === $r['unresolved'], json_encode( $r['unresolved'] ) );
$r = DOS_SEO_Import::resolve_aioseo( '#hvac #1 pick: #post_title', $vars );
check( 'a hashtag that is not a smart tag is left exactly as written', '#hvac #1 pick: Furnace Repair' === $r['text'] && array() === $r['unresolved'], json_encode( $r ) );
$r = DOS_SEO_Import::resolve_aioseo( '#post_excerpt_only #post_title', $vars );
check( '#post_excerpt_only is not mistaken for #post_excerpt', 'Furnace Repair' === $r['text'] && array( '#post_excerpt_only' ) === $r['unresolved'], json_encode( $r ) );

echo "\n--- the Yoast job ---\n";
reset_site();
$GLOBALS['options']['wpseo_titles'] = array( 'separator' => 'sc-pipe' );
add_post( 10, 'Furnace Repair', 'We fix furnaces.' );
add_post( 11, 'About Us' );
add_post( 12, 'Boiler Install' );
add_post( 13, 'Duct Cleaning' );
add_post( 14, 'Private Page' );
add_post( 15, 'Heat Pumps' );
$GLOBALS['state']['categories'][12] = array( 'Heating', 'Cooling' );

$GLOBALS['pmeta'][10] = array( '_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%', '_yoast_wpseo_metadesc' => '%%excerpt%% %%page%%' );
$GLOBALS['pmeta'][11] = array( '_yoast_wpseo_title' => 'About %%cf_subtitle%% %%sep%% %%sitename%%' );
$GLOBALS['pmeta'][12] = array( '_yoast_wpseo_title' => '%%title%% %%sep%% %%primary_category%%' );
$GLOBALS['pmeta'][13] = array( '_yoast_wpseo_title' => 'Should not replace', '_yoast_wpseo_metadesc' => 'Duct cleaning, done properly.', T => 'Hand-written title' );
$GLOBALS['pmeta'][14] = array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_canonical' => 'https://example.com/elsewhere/' );
$GLOBALS['pmeta'][15] = array( '_yoast_wpseo_metadesc' => 'Should not replace', '_saab_seo_description' => 'From the SAAB days.' );

check( 'counts the posts carrying Yoast data', 6 === DOS_Module_SEO::count_yoast(), (string) DOS_Module_SEO::count_yoast() );

$dry = DOS_Module_SEO::step_yoast( 0, 50, true );
check( 'dry run: examines every post', 6 === $dry['processed'], json_encode( $dry ) );
check( '  writes nothing at all', array() === $GLOBALS['written'], json_encode( $GLOBALS['written'] ) );
check( '  leaves the post meta untouched', null === meta( 10, T ) && null === meta( 10, D ) );
check( '  says it is a dry run in its summary', false !== strpos( all_notes( $dry ), 'Would write' ) );
check( '  reports the posts that would change', 4 === $dry['changed'], (string) $dry['changed'] );

$live = DOS_Module_SEO::step_yoast( 0, 50, false );
check( 'live: same number changed as the dry run predicted', $dry['changed'] === $live['changed'], "{$dry['changed']} vs {$live['changed']}" );
check( '  title resolved with the site separator and a decoded site name', 'Furnace Repair | Acme Heating & Air' === meta( 10, T ), (string) meta( 10, T ) );
check( '  description resolved, %%page%% gone', 'We fix furnaces.' === meta( 10, D ), (string) meta( 10, D ) );
check( '  no entity stored', false === strpos( (string) meta( 10, T ), '&amp;' ) );
check( '  unresolved variable stripped from the stored title', 'About | Acme Heating & Air' === meta( 11, T ), (string) meta( 11, T ) );
check( '    and the note names the post and the variable', (bool) preg_match( '/Post 11:.*%%cf_subtitle%%/', all_notes( $live ) ), all_notes( $live ) );
check( '  first category only', 'Boiler Install | Heating' === meta( 12, T ), (string) meta( 12, T ) );

check( 'an existing DoS title is never overwritten', 'Hand-written title' === meta( 13, T ), (string) meta( 13, T ) );
check( '  and no write to that key was even attempted', array() === array_filter( writes_to( T ), function ( $w ) { return 13 === $w[1]; } ) );
check( '  it is reported as left alone', (bool) preg_match( '/Post 13: SEO title already set here, left alone/', all_notes( $live ) ), all_notes( $live ) );
check( '  but the description it did not have is imported', 'Duct cleaning, done properly.' === meta( 13, D ), (string) meta( 13, D ) );
check( 'a description in the old SAAB key counts as already set', null === meta( 15, D ) && (bool) preg_match( '/Post 15: meta description already set/', all_notes( $live ) ), all_notes( $live ) );

check( 'noindex is counted and named, not imported', (bool) preg_match( '/Post 14: set to noindex in Yoast SEO/', all_notes( $live ) ), all_notes( $live ) );
check( 'a custom canonical is counted and named with its URL', (bool) preg_match( '/Post 14: has a custom canonical in Yoast SEO \(https:\/\/example\.com\/elsewhere\/\)/', all_notes( $live ) ), all_notes( $live ) );
check( '  neither produced a write', array() === array_filter( $GLOBALS['written'], function ( $w ) { return 14 === $w[1]; } ) );
check( 'the summary counts them', false !== strpos( all_notes( $live ), '1 noindex and 1 canonical overrides not importable' ), all_notes( $live ) );
check( 'both runs are logged, the dry one flagged as such', 2 === count( $GLOBALS['log'] ) && 'import_yoast' === $GLOBALS['log'][1][1] && true === $GLOBALS['log'][0][4] && false === $GLOBALS['log'][1][4], json_encode( $GLOBALS['log'] ) );

$before = $GLOBALS['pmeta'];
$GLOBALS['written'] = array();
$again = DOS_Module_SEO::step_yoast( 0, 50, false );
check( 'running it again changes nothing', 0 === $again['changed'] && $before === $GLOBALS['pmeta'] && array() === $GLOBALS['written'], json_encode( $again ) );

echo "\n--- paging ---\n";
reset_site();
$GLOBALS['options']['wpseo_titles'] = array( 'separator' => 'sc-dash' );
for ( $i = 1; $i <= 5; $i++ ) {
    add_post( $i, "Post $i" );
    $GLOBALS['pmeta'][ $i ] = array( '_yoast_wpseo_title' => "Custom $i" );
}
$seen = 0;
foreach ( array( 0, 2, 4 ) as $offset ) {
    $r = DOS_Module_SEO::step_yoast( $offset, 2, true );
    $seen += $r['processed'];
}
check( 'pages of two cover five posts exactly once', 5 === $seen, (string) $seen );
$r = DOS_Module_SEO::step_yoast( 6, 2, true );
check( 'a page past the end processes nothing, which ends the run', 0 === $r['processed'] );

echo "\n--- a post whose Yoast data outlived it ---\n";
reset_site();
$GLOBALS['pmeta'][99] = array( '_yoast_wpseo_title' => 'Orphan' );
$r = DOS_Module_SEO::step_yoast( 0, 50, false );
check( 'skipped with a note, nothing written', 0 === $r['changed'] && array() === $GLOBALS['written'] && (bool) preg_match( '/Post 99: no longer exists/', all_notes( $r ) ), all_notes( $r ) );

echo "\n--- the AIOSEO job ---\n";
reset_site();
$GLOBALS['tables'] = array();
check( 'no table: nothing to count', 0 === DOS_Module_SEO::count_aioseo() );
$r = DOS_Module_SEO::step_aioseo( 0, 50, false );
check( 'no table: a clear note, nothing processed, nothing written', 0 === $r['processed'] && array() === $GLOBALS['written'] && false !== strpos( all_notes( $r ), 'wp_aioseo_posts does not exist' ), all_notes( $r ) );

reset_site();
$GLOBALS['options']['aioseo_options'] = '{"searchAppearance":{"global":{"separator":"&middot;"}}}';
add_post( 20, 'Furnace Repair', 'We fix furnaces.' );
add_post( 21, 'About Us' );
add_post( 22, 'Hashtags' );
add_post( 23, 'Private Page' );
add_post( 24, 'Duct Cleaning' );
add_post( 25, 'Nothing Here' );
$GLOBALS['state']['categories'][22] = array( 'Heating' );
// robots_default is 1 on a post that has not customised its robots settings;
// AIOSEO then ignores robots_noindex, so only default = 0 is a real noindex.
$row = function ( $id, $title, $desc, $noindex = 0, $canonical = '', $default = null ) {
    return array( 'post_id' => $id, 'title' => $title, 'description' => $desc, 'robots_default' => null === $default ? ( $noindex ? 0 : 1 ) : $default, 'robots_noindex' => $noindex, 'canonical_url' => $canonical );
};
$GLOBALS['aioseo_rows'] = array(
    $row( 20, '#post_title #separator_sa #site_title', '#post_excerpt' ),
    $row( 21, '#post_title #separator_sa #custom_field-subtitle #separator_sa #site_title', '' ),
    $row( 22, '#hvac #post_title', '#categories' ),
    $row( 23, '', '', 1, 'https://example.com/canonical-elsewhere/' ),
    $row( 24, 'Should not replace', 'A fresh description.' ),
    $row( 25, null, null ),
    $row( 26, '', '', 1, '', 1 ),
    $row( 27, '', 'Has a description.', 1, '', 1 ),
);
add_post( 26, 'Inherits Site Robots' );
add_post( 27, 'Also Inherits' );
$GLOBALS['pmeta'][24] = array( T => 'Hand-written title' );

check( 'counts rows that carry something importable or reportable; a stale noindex under robots_default = 1 is not one', 6 === DOS_Module_SEO::count_aioseo(), (string) DOS_Module_SEO::count_aioseo() );

$dry = DOS_Module_SEO::step_aioseo( 0, 50, true );
check( 'dry run: writes nothing', array() === $GLOBALS['written'], json_encode( $GLOBALS['written'] ) );
check( '  but reports the same count of changes the live run makes', 5 === $dry['changed'], (string) $dry['changed'] );

$live = DOS_Module_SEO::step_aioseo( 0, 50, false );
check( 'live: same count as predicted', $dry['changed'] === $live['changed'], "{$dry['changed']} vs {$live['changed']}" );
check( '  variables resolved, separator read from AIOSEO\'s own option', 'Furnace Repair · Acme Heating & Air' === meta( 20, T ), (string) meta( 20, T ) );
check( '  excerpt tag resolved', 'We fix furnaces.' === meta( 20, D ), (string) meta( 20, D ) );
check( '  no entity leaks into what is stored', false === strpos( (string) meta( 20, T ), '&amp;' ) );
check( '  unresolved tag stripped, with its separator', 'About Us · Acme Heating & Air' === meta( 21, T ), (string) meta( 21, T ) );
check( '    and the note names the post and the tag', (bool) preg_match( '/Post 21:.*#custom_field-subtitle/', all_notes( $live ) ), all_notes( $live ) );
check( '  a hashtag in the title is kept', '#hvac Hashtags' === meta( 22, T ), (string) meta( 22, T ) );
check( '  category tag resolved', 'Heating' === meta( 22, D ), (string) meta( 22, D ) );
check( 'an existing DoS title is not overwritten', 'Hand-written title' === meta( 24, T ), (string) meta( 24, T ) );
check( '  while its description, which was empty, is imported', 'A fresh description.' === meta( 24, D ), (string) meta( 24, D ) );
check( 'noindex with robots_default = 1 is NOT reported: AIOSEO ignores it', ! preg_match( '/Post 2[67]: set to noindex/', all_notes( $live ) ), all_notes( $live ) );
check( '  but the description on such a row is still imported', 'Has a description.' === meta( 27, D ) );
check( 'noindex and canonical are named, not imported', (bool) preg_match( '/Post 23: set to noindex in All in One SEO/', all_notes( $live ) ) && (bool) preg_match( '/Post 23: has a custom canonical in All in One SEO \(https:\/\/example\.com\/canonical-elsewhere\/\)/', all_notes( $live ) ), all_notes( $live ) );
check( '  and Post 23 was not written to', array() === array_filter( $GLOBALS['written'], function ( $w ) { return 23 === $w[1]; } ) );

echo "\n--- the SQL the importers send ---\n";
// The fakes above filter rows in PHP, so a typo in a column or table name
// would pass everything. These pin the text itself.
function sql_like( $needle ) {
    foreach ( $GLOBALS['queries'] as $q ) { if ( false !== strpos( $q, $needle ) ) { return $q; } }
    return '';
}
$in = "'_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_canonical'";

reset_site();
add_post( 1, 'One' );
$GLOBALS['pmeta'][1] = array( '_yoast_wpseo_title' => 'x' );
DOS_Module_SEO::count_yoast();
DOS_Module_SEO::step_yoast( 20, 50, true );
check( 'Yoast count: DISTINCT post_id from wp_postmeta over the four meta keys, non-empty values only', "SELECT COUNT(DISTINCT post_id) FROM wp_postmeta WHERE meta_key IN ( $in ) AND meta_value <> ''" === ( $GLOBALS['queries'][0] ?? '' ), $GLOBALS['queries'][0] ?? '' );
check( 'Yoast page: same filter, ordered by post_id, with LIMIT and OFFSET in that order', "SELECT DISTINCT post_id FROM wp_postmeta WHERE meta_key IN ( $in ) AND meta_value <> '' ORDER BY post_id ASC LIMIT 50 OFFSET 20" === ( $GLOBALS['queries'][1] ?? '' ), $GLOBALS['queries'][1] ?? '' );

reset_site();
DOS_Module_SEO::count_aioseo();
DOS_Module_SEO::step_aioseo( 40, 50, true );
$where = "( title <> '' OR description <> '' OR ( robots_default = 0 AND robots_noindex = 1 ) OR canonical_url <> '' )";
check( 'AIOSEO probes for wp_aioseo_posts before reading it', false !== strpos( sql_like( 'SHOW TABLES' ), "'wp\\_aioseo\\_posts'" ), sql_like( 'SHOW TABLES' ) );
check( 'AIOSEO count: wp_aioseo_posts, only rows with something to import or report', "SELECT COUNT(*) FROM wp_aioseo_posts WHERE $where" === sql_like( 'COUNT(*)' ), sql_like( 'COUNT(*)' ) );
check( 'AIOSEO page: the six columns the importer reads, ordered, LIMIT then OFFSET', "SELECT post_id, title, description, robots_default, robots_noindex, canonical_url FROM wp_aioseo_posts WHERE $where ORDER BY post_id ASC LIMIT 50 OFFSET 40" === sql_like( 'SELECT post_id' ), sql_like( 'SELECT post_id' ) );
check( 'every prepare() had as many placeholders as arguments', array() === $GLOBALS['bad_prepare'], json_encode( $GLOBALS['bad_prepare'] ) );

echo "\n--- registration ---\n";
$jobs = DOS_Module_SEO::jobs();
check( 'both jobs are registered', array( 'seo_import_yoast', 'seo_import_aioseo' ) === array_keys( $jobs ) );
$callable = true;
foreach ( $jobs as $job ) {
    $callable = $callable && is_callable( $job['count'] ) && is_callable( $job['step'] ) && empty( $job['destructive'] );
}
check( '  each is callable and not destructive: the source is never changed', $callable );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
