<?php
/**
 * AI Search module: crawler policy, FAQ extraction, llms.txt.
 */

define( 'ABSPATH', '/tmp/' );
define( 'PLUGIN', dirname( __DIR__ ) . '/plugins/dos-toolkit' );
define( 'DOS_TOOLKIT_VERSION', 'test' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['options'] = array();
$GLOBALS['posts']   = array();
$GLOBALS['meta']    = array();

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function _n( $s, $p, $n, $d = '' ) { return 1 === $n ? $s : $p; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function add_action() {} function add_filter() {} function apply_filters( $t, $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function home_url( $p = '/' ) { return 'https://example.com' . ltrim( $p, '/' ) === 'https://example.com' ? 'https://example.com/' : 'https://example.com' . $p; }
function get_bloginfo( $w ) { return array( 'name' => 'Example Site', 'description' => 'Plain tagline' )[ $w ] ?? ''; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function get_permalink( $p = 0 ) { $id = is_object( $p ) ? $p->ID : $p; return 'https://example.com/p/' . $id . '/'; }
function get_the_title( $p = 0 ) { $id = is_object( $p ) ? $p->ID : $p; return $GLOBALS['posts'][ $id ]->post_title ?? ''; }
function get_posts( $args ) {
    $type = $args['post_type'] ?? 'post';
    return array_values( array_filter( $GLOBALS['posts'], function ( $p ) use ( $type ) { return $p->post_type === $type; } ) );
}

class DOS_Log { public static function add() {} public static function prune() {} }
class DOS_Admin { public static function notice() {} }

require PLUGIN . '/includes/class-dos-settings.php';
require PLUGIN . '/includes/class-dos-module.php';
require PLUGIN . '/modules/ai/class-dos-ai-crawlers.php';
require PLUGIN . '/modules/ai/class-dos-ai-schema.php';
require PLUGIN . '/modules/ai/class-dos-ai-llms.php';

$pass = 0; $fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $pass, $fail;
    if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label  $detail\n"; }
}

/* ===================================================================== */
echo "--- crawler policy defaults ---\n";

check( 'blocks GPTBot (training) by default', 'block' === DOS_AI_Crawlers::policy( 'GPTBot' ) );
check( 'blocks ClaudeBot (training) by default', 'block' === DOS_AI_Crawlers::policy( 'ClaudeBot' ) );
check( 'blocks CCBot (training) by default', 'block' === DOS_AI_Crawlers::policy( 'CCBot' ) );
check( 'ALLOWS OAI-SearchBot (citation) by default', 'allow' === DOS_AI_Crawlers::policy( 'OAI-SearchBot' ) );
check( 'ALLOWS PerplexityBot (citation) by default', 'allow' === DOS_AI_Crawlers::policy( 'PerplexityBot' ) );
check( 'ALLOWS ChatGPT-User (reader asked) by default', 'allow' === DOS_AI_Crawlers::policy( 'ChatGPT-User' ) );
check( 'an unknown token defaults to allow', 'allow' === DOS_AI_Crawlers::policy( 'SomeFutureBot' ) );

$summary = DOS_AI_Crawlers::summary();
check( 'summary counts every registered bot', array_sum( $summary ) === count( DOS_AI_Crawlers::registry() ), json_encode( $summary ) );

echo "\n--- robots.txt output ---\n";

$rules = DOS_AI_Crawlers::rules();
check( 'disallows a blocked bot', false !== strpos( $rules, "User-agent: GPTBot\nDisallow: /" ), $rules );
check( 'does not mention an allowed bot', false === strpos( $rules, 'PerplexityBot' ), $rules );
check( 'is commented so it is identifiable in the file', false !== strpos( $rules, '# AI crawler policy' ) );

DOS_Settings::set( 'ai_crawler_policy', array( 'GPTBot' => 'allow', 'PerplexityBot' => 'block' ) );
$rules = DOS_AI_Crawlers::rules();
check( 'an explicit allow overrides the default block', false === strpos( $rules, 'User-agent: GPTBot' ), $rules );
check( 'an explicit block overrides the default allow', false !== strpos( $rules, 'User-agent: PerplexityBot' ), $rules );

$all_allow = array();
foreach ( array_keys( DOS_AI_Crawlers::registry() ) as $t ) { $all_allow[ $t ] = 'allow'; }
DOS_Settings::set( 'ai_crawler_policy', $all_allow );
check( 'emits nothing when everything is allowed', '' === DOS_AI_Crawlers::rules() );

DOS_Settings::set( 'ai_crawler_policy', array() );
DOS_Settings::set( 'ai_crawlers_enabled', 0 );
check( 'adds nothing while the feature is off', 'BASE' === DOS_AI_Crawlers::append_rules( 'BASE', true ) );

DOS_Settings::set( 'ai_crawlers_enabled', 1 );
check( 'appends to the existing robots.txt rather than replacing it', 0 === strpos( DOS_AI_Crawlers::append_rules( 'BASE', true ), 'BASE' ) );
check( 'stays out of the way on a non-public site', 'BASE' === DOS_AI_Crawlers::append_rules( 'BASE', false ) );

/* ===================================================================== */
echo "\n--- FAQ extraction ---\n";

$content = '<p>Intro text.</p>'
    . '<h2>What is a widget?</h2><p>A widget is a small thing.</p>'
    . '<h2>Our history</h2><p>Founded in 1998.</p>'
    . '<h2>How much does it cost?</h2><p>It costs $10.</p><ul><li>Bulk discounts apply</li></ul>'
    . '<h3>Do you ship overseas?</h3>'
    . '<h2>Is there a warranty?</h2><p>Yes &mdash; two years.</p>';

$pairs = DOS_AI_Schema::extract( $content );

check( 'finds question headings', 3 === count( $pairs ), json_encode( array_column( $pairs, 'question' ) ) );
check( '  skips a heading that is not a question', ! in_array( 'Our history', array_column( $pairs, 'question' ), true ) );
check( '  skips a question with no answer under it', ! in_array( 'Do you ship overseas?', array_column( $pairs, 'question' ), true ) );
check( '  keeps the question text', 'What is a widget?' === $pairs[0]['question'], $pairs[0]['question'] );
check( '  strips tags from the answer', 'A widget is a small thing.' === $pairs[0]['answer'], $pairs[0]['answer'] );
check( '  gathers answer text past the first tag', false !== strpos( $pairs[1]['answer'], 'Bulk discounts apply' ), $pairs[1]['answer'] );
check( '  decodes entities', false !== strpos( $pairs[2]['answer'], '—' ), $pairs[2]['answer'] );

check( 'handles content with no headings', array() === DOS_AI_Schema::extract( '<p>Just a paragraph.</p>' ) );
check( 'handles empty content', array() === DOS_AI_Schema::extract( '' ) );
check( 'ignores h1', array() === DOS_AI_Schema::extract( '<h1>Why?</h1><p>Because.</p>' ) );

/* ===================================================================== */
echo "\n--- llms.txt ---\n";

$GLOBALS['posts'] = array(
    1 => (object) array( 'ID' => 1, 'post_title' => 'About Us', 'post_type' => 'page', 'post_excerpt' => 'Who we are.' ),
    2 => (object) array( 'ID' => 2, 'post_title' => 'Contact', 'post_type' => 'page', 'post_excerpt' => '' ),
    3 => (object) array( 'ID' => 3, 'post_title' => 'A Post [with brackets]', 'post_type' => 'post', 'post_excerpt' => "Multi\nline" ),
);
$GLOBALS['meta'][2]['_dos_seo_description'] = 'Reach the team.';
DOS_Settings::set( 'seo_home_description', 'The hand-written homepage line.' );

$txt = DOS_AI_Llms::generate();

check( 'leads with the site name', 0 === strpos( $txt, '# Example Site' ), substr( $txt, 0, 40 ) );
check( 'prefers the SEO homepage description over the tagline', false !== strpos( $txt, '> The hand-written homepage line.' ), $txt );
check( 'lists pages and posts under headings', false !== strpos( $txt, '## Pages' ) && false !== strpos( $txt, '## Posts' ) );
check( 'links each entry', false !== strpos( $txt, '- [About Us](https://example.com/p/1/): Who we are.' ), $txt );
check( 'uses the SEO description when there is no excerpt', false !== strpos( $txt, '- [Contact](https://example.com/p/2/): Reach the team.' ), $txt );
check( 'neutralises brackets that would break a link', false === strpos( $txt, '[with brackets]' ), $txt );
check( 'collapses newlines that would break a list item', false === strpos( trim( $txt ), "Multi\nline" ) );
check( 'ends with a newline', "\n" === substr( $txt, -1 ) );

echo "\n--- llms.txt with nothing to list ---\n";
$GLOBALS['posts'] = array(
    1 => (object) array( 'ID' => 1, 'post_title' => 'About Us', 'post_type' => 'page', 'post_excerpt' => '' ),
);
$txt = DOS_AI_Llms::generate();
check( 'lists the pages it has', false !== strpos( $txt, '## Pages' ) );
check( 'omits a section with no posts rather than leaving a bare heading', false === strpos( $txt, '## Posts' ), $txt );

$GLOBALS['posts'] = array(
    2 => (object) array( 'ID' => 2, 'post_title' => '', 'post_type' => 'post', 'post_excerpt' => '' ),
);
$txt = DOS_AI_Llms::generate();
check( 'a section whose only entry is untitled is omitted too', false === strpos( $txt, '## Posts' ), $txt );

echo "\n$pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
