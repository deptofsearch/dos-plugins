<?php
/**
 * DoS - Agents: payload sanitising, storage and the REST callbacks.
 *
 * The upsert endpoint takes JSON from outside, so these pin what may be stored: only the schema,
 * only https Zillow profile links, at most 500 agents, and each field coerced to its type.
 */

require __DIR__ . '/wp-stubs-agents.php';
require dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php';

$SCHEMA = array(
	'zuid', 'name', 'profile_url', 'brokerage', 'is_team', 'is_top_agent', 'rating', 'rating_count', 'sales_in_city', 'sales_12mo',
	'price_range', 'avg_price', 'years', 'specialties', 'tier', 'office', 'areas', 'area_evidence', 'zip_sales', 'summary', 'tags',
	'reviews_analyzed', 'last_review', 'tagged_at',
);

echo "--- wiring ---\n";
check( 'the shared updater class is loaded', class_exists( 'DOS_GitHub_Updater' ) );
$header = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-agents/dos-agents.php' );
check( 'the header version and the constant agree', 1 === preg_match( '/^\s*\*\s*Version:\s*' . preg_quote( DOS_AGENTS_VERSION, '/' ) . '\s*$/m', $header ), DOS_AGENTS_VERSION );
check( 'the header names the text domain and a DoS display name', false !== strpos( $header, 'Text Domain: dos-agents' ) && false !== strpos( $header, 'Plugin Name: DoS - Agents' ) );
check( 'the shortcode and REST hooks are registered', isset( $GLOBALS['actions']['init'], $GLOBALS['actions']['rest_api_init'] ) );

echo "\n--- the Boise fixture ---\n";
$fx    = agents_fixture();
$clean = dos_agents_sanitize_payload( $fx );
check( 'the fixture sanitises', is_array( $clean ), is_wp_error( $clean ) ? $clean->message : '' );
check( 'every fixture agent survives and none are skipped', count( $fx['agents'] ) === count( $clean['agents'] ) && 0 === $clean['skipped'], count( $clean['agents'] ) . ' / ' . $clean['skipped'] );
check( 'city and state come through', 'Boise' === $clean['city'] && 'ID' === $clean['state'] );
check( 'every agent has exactly the schema keys, in order', array_reduce( $clean['agents'], function ( $ok, $a ) use ( $SCHEMA ) { return $ok && array_keys( $a ) === $SCHEMA; }, true ) );
check( 'tags, areas and summaries are kept', $clean['agents'][0]['tags'][0]['slug'] === $fx['agents'][0]['tags'][0]['slug'] && $clean['agents'][0]['areas'] === $fx['agents'][0]['areas'] && $clean['agents'][0]['summary'] === $fx['agents'][0]['summary'] );

echo "\n--- profile links ---\n";
$bad = array(
	'http, not https'            => 'http://www.zillow.com/profile/jane',
	'another host'               => 'https://example.com/profile/jane',
	'a look-alike host'          => 'https://www.zillow.com.evil.com/profile/jane',
	'userinfo trick'             => 'https://www.zillow.com@evil.com/profile/jane',
	'not the profile path'       => 'https://www.zillow.com/homedetails/123',
	'javascript:'                => 'javascript:alert(1)',
	'a quote to break the href'  => 'https://www.zillow.com/profile/jane"onmouseover="x',
	'an empty profile name'      => 'https://www.zillow.com/profile/',
	'a bare zillow.com'          => 'https://zillow.com/profile/jane',
	'empty'                      => '',
);
foreach ( $bad as $label => $url ) {
	$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'profile_url' => $url ) ) ) ) );
	check( "rejected: $label", 0 === count( $r['agents'] ) && 1 === $r['skipped'], $url );
}
$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'profile_url' => 'https://www.zillow.com/profile/jane-doe_1/' ) ) ) ) );
check( 'a real profile link is kept', 1 === count( $r['agents'] ) && 'https://www.zillow.com/profile/jane-doe_1/' === $r['agents'][0]['profile_url'] );
$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'name' => '   ' ) ) ) ) );
check( 'an agent with no name is dropped', 0 === count( $r['agents'] ) );

echo "\n--- unknown fields ---\n";
$a            = agents_make();
$a['evil']    = '<script>alert(1)</script>';
$a['office']['phone'] = '555';
$a['tags']    = array( array( 'slug' => 'responsive', 'label' => 'Responsive', 'count' => 3, 'extra' => 'x' ) );
$a['zip_sales'] = array( array( 'zip' => '83702', 'sales' => 4, 'extra' => 'x' ) );
$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'secret' => 'x', 'agents' => array( $a ) ) );
check( 'top level keeps only city, state, generated_at, agents (and the reported skipped)', array( 'city', 'state', 'generated_at', 'agents', 'skipped' ) === array_keys( $r ) );
check( 'an unknown agent field is dropped', array_keys( $r['agents'][0] ) === $SCHEMA );
check( 'unknown fields inside office, tags and zip_sales are dropped', array( 'city', 'state', 'zip' ) === array_keys( $r['agents'][0]['office'] ) && array( 'slug', 'label', 'count' ) === array_keys( $r['agents'][0]['tags'][0] ) && array( 'zip', 'sales' ) === array_keys( $r['agents'][0]['zip_sales'][0] ) );

echo "\n--- the 500 cap ---\n";
$many = array();
for ( $i = 0; $i < 520; $i++ ) { $many[] = agents_make(); }
$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => $many ) );
check( '520 agents are cut to 500', 500 === count( $r['agents'] ) && 20 === $r['skipped'], count( $r['agents'] ) );
check( 'the first 500 are the ones kept', $many[0]['name'] === $r['agents'][0]['name'] && $many[499]['name'] === $r['agents'][499]['name'] );

echo "\n--- coercion ---\n";
$a = agents_make( array(
	'name'             => '<b>Jane</b> <i>Doe</i>',
	'rating'           => 9,
	'rating_count'     => '12',
	'years'            => -3,
	'sales_in_city'    => 'lots',
	'tier'             => 'platinum',
	'is_team'          => 'true',
	'is_top_agent'     => 0,
	'last_review'      => '2026-02-30',
	'summary'          => str_repeat( 'x', 5000 ),
	'specialties'      => 'not a list',
	'areas'            => array( 'Eagle, ID', array( 'nested' ), '', '<i>Nampa</i>, ID' ),
	'tags'             => array( array( 'slug' => 'Good Tag!', 'label' => 'Good', 'count' => 2 ), array( 'slug' => 'zero', 'label' => 'Zero', 'count' => 0 ), 'junk' ),
	'office'           => array( 'city' => 'Boise', 'state' => 'idaho', 'zip' => '83702abc' ),
) );
$r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( $a ) ) );
$c = $r['agents'][0];
check( 'tags are stripped from text', 'Jane Doe' === $c['name'], $c['name'] );
check( 'rating is clamped to 0..5', 5 === (int) $c['rating'] );
check( 'numeric strings become ints, junk and negatives become 0', 12 === $c['rating_count'] && 0 === $c['years'] && 0 === $c['sales_in_city'] );
check( 'an unknown tier becomes none', 'none' === $c['tier'] );
check( 'booleans are real booleans', true === $c['is_team'] && false === $c['is_top_agent'] );
check( 'an impossible date is dropped', '' === $c['last_review'] );
check( 'long text is cut to its limit', 1500 === strlen( $c['summary'] ) );
check( 'a non-list specialties becomes empty', array() === $c['specialties'] );
check( 'areas keep only usable strings', array( 'Eagle, ID', 'Nampa, ID' ) === $c['areas'], json_encode( $c['areas'] ) );
check( 'tag slugs are normalised and zero-count tags dropped', 1 === count( $c['tags'] ) && 'good-tag' === $c['tags'][0]['slug'], json_encode( $c['tags'] ) );
check( 'a bad state code becomes empty, a zip keeps digits and hyphens', '' === $c['office']['state'] && '83702' === $c['office']['zip'] );

echo "\n--- summary_parts ---\n";
$sp   = function ( $v ) { $r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'summary_parts' => $v ) ) ) ) ); return $r['agents'][0]; };
$full = $sp( array( 'working_style' => 'Calm.', 'buyers' => '  Patient   with   first-timers. ', 'sellers' => "Priced\nit right." ) );
check( 'a valid object is kept, with whitespace collapsed, in the order buyers, sellers, working_style', array( 'buyers' => 'Patient with first-timers.', 'sellers' => 'Priced it right.', 'working_style' => 'Calm.' ) === $full['summary_parts'] && array( 'buyers', 'sellers', 'working_style' ) === array_keys( $full['summary_parts'] ), json_encode( $full['summary_parts'] ?? null ) );
check( 'it sits right after summary in the stored agent', array_search( 'summary_parts', array_keys( $full ), true ) === array_search( 'summary', array_keys( $full ), true ) + 1 );
$tagged = $sp( array( 'buyers' => '<b>Great</b> with <script>x()</script>buyers <a href="https://evil.example">link</a>', 'sellers' => '<p>Wrapped</p>' ) );
check( 'tags are stripped from every part', 'Great with x()buyers link' === $tagged['summary_parts']['buyers'] && 'Wrapped' === $tagged['summary_parts']['sellers'] && false === strpos( json_encode( $tagged['summary_parts'] ), '<' ), json_encode( $tagged['summary_parts'] ) );
$long = $sp( array( 'buyers' => str_repeat( 'b', 900 ), 'sellers' => str_repeat( 's', 320 ), 'working_style' => str_repeat( 'w', 321 ) ) );
check( 'each part is cut to 320 characters', 320 === strlen( $long['summary_parts']['buyers'] ) && 320 === strlen( $long['summary_parts']['sellers'] ) && 320 === strlen( $long['summary_parts']['working_style'] ) );
$multi = $sp( array( 'buyers' => str_repeat( 'é', 400 ) ) );
check( 'the cut counts characters, not bytes', 320 === mb_strlen( $multi['summary_parts']['buyers'], 'UTF-8' ) );
$some = $sp( array( 'buyers' => '', 'sellers' => 'Kept.', 'working_style' => "  \n " ) );
check( 'empty and blank parts are dropped, the others kept', array( 'sellers' => 'Kept.' ) === $some['summary_parts'] );
$nonstr = $sp( array( 'buyers' => 12, 'sellers' => array( 'x' ), 'working_style' => true, 'extra' => 'Not a part.' ) );
check( 'a number, list or boolean in a slot is ignored, not stringified; unknown keys are ignored', ! array_key_exists( 'summary_parts', $nonstr ) );
foreach ( array( 'all empty' => array( 'buyers' => '', 'sellers' => '', 'working_style' => '' ), 'an empty object' => array(), 'a string' => 'Buyers: x', 'a number' => 5, 'null' => null, 'a list of strings' => array( 'a', 'b' ) ) as $label => $v ) {
	$c = $sp( $v );
	check( "the object is dropped whole for $label", ! array_key_exists( 'summary_parts', $c ) && array_keys( $c ) === $SCHEMA );
}
check( 'an agent that never sent it stores the same keys as before', ! array_key_exists( 'summary_parts', dos_agents_sanitize_payload( agents_fixture() )['agents'][0] ) );
$both = $sp( array( 'buyers' => 'Parts.' ) );
check( 'the old summary string is untouched next to it', '' === $both['summary'] && 'Old.' === dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'summary' => 'Old.', 'summary_parts' => array( 'buyers' => 'Parts.' ) ) ) ) ) )['agents'][0]['summary'] );
$with_parts = array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'summary' => 'Old.', 'summary_parts' => array( 'buyers' => 'B.', 'sellers' => 'S.', 'working_style' => 'W.' ) ) ), agents_make( array( 'summary' => 'Only old.' ) ) ) );
dos_agents_rest_upsert( new WP_REST_Request( $with_parts ) );
check( 'it is stored with the agent', array( 'buyers' => 'B.', 'sellers' => 'S.', 'working_style' => 'W.' ) === $GLOBALS['options']['breanm_agents_boise-id']['agents'][0]['summary_parts'] && ! array_key_exists( 'summary_parts', $GLOBALS['options']['breanm_agents_boise-id']['agents'][1] ) );
$res = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'boise-id' ) ) );
check( 'the authenticated GET returns it unchanged, and an old-style agent without it unchanged too', array( 'buyers' => 'B.', 'sellers' => 'S.', 'working_style' => 'W.' ) === $res->data['agents'][0]['summary_parts'] && 'Old.' === $res->data['agents'][0]['summary'] && ! array_key_exists( 'summary_parts', $res->data['agents'][1] ) && 'Only old.' === $res->data['agents'][1]['summary'] );
$opt = $GLOBALS['options']['breanm_agents_boise-id'];
$opt['agents'][0]['summary_parts'] = array( 'buyers' => '<script>x()</script>Clean', 'sellers' => str_repeat( 's', 999 ), 'working_style' => array( 'x' ), 'extra' => 'x' );
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
$back = dos_agents_get( 'boise-id' );
check( 'on read the stored parts are re-sanitised like the rest', array( 'buyers', 'sellers' ) === array_keys( $back['agents'][0]['summary_parts'] ) && 'x()Clean' === $back['agents'][0]['summary_parts']['buyers'] && 320 === strlen( $back['agents'][0]['summary_parts']['sellers'] ) );
$opt['agents'][0]['summary_parts'] = 'junk';
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
check( 'stored parts that are no longer an object read as none', ! array_key_exists( 'summary_parts', dos_agents_get( 'boise-id' )['agents'][0] ) );
$norm = dos_agents_normalise_agent( agents_make() );
check( 'for display every agent has the key, empty when there is none', array() === $norm['summary_parts'] );

echo "\n--- reviews_window_years ---\n";
$rw      = function ( $v ) { $r = dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'reviews_window_years' => $v ) ) ) ) ); return $r['agents'][0]; };
$rw_keys = array_merge( array_slice( $SCHEMA, 0, array_search( 'reviews_analyzed', $SCHEMA, true ) + 1 ), array( 'reviews_window_years' ), array_slice( $SCHEMA, array_search( 'reviews_analyzed', $SCHEMA, true ) + 1 ) );
$c       = $rw( 3 );
check( 'a valid window is kept as an int and sits right after reviews_analyzed', 3 === $c['reviews_window_years'] && array_keys( $c ) === $rw_keys, json_encode( array_keys( $c ) ) );
check( 'the ends of the range, 1 and 20, are kept', 1 === $rw( 1 )['reviews_window_years'] && 20 === $rw( 20 )['reviews_window_years'] );
check( 'a numeric string is coerced like the other numeric fields', 5 === $rw( '5' )['reviews_window_years'] && 4 === $rw( 4.0 )['reviews_window_years'] );
foreach ( array( 'zero' => 0, 'negative' => -2, 'over 20' => 21, 'far over' => 9999, 'a fraction' => 2.5, 'a word' => 'three', 'an empty string' => '', 'a list' => array( 3 ), 'true' => true, 'null' => null ) as $label => $v ) {
	$c = $rw( $v );
	check( "dropped for $label (not clamped)", ! array_key_exists( 'reviews_window_years', $c ) && array_keys( $c ) === $SCHEMA, json_encode( $v ) );
}
check( 'an agent that never sent it stores the same keys as before', ! array_key_exists( 'reviews_window_years', dos_agents_sanitize_payload( agents_fixture() )['agents'][0] ) );
dos_agents_rest_upsert( new WP_REST_Request( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make( array( 'reviews_window_years' => 3 ) ), agents_make() ) ) ) );
$res = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'boise-id' ) ) );
check( 'it is stored and returned by the authenticated GET, and an agent without it stays without', 3 === $res->data['agents'][0]['reviews_window_years'] && ! array_key_exists( 'reviews_window_years', $res->data['agents'][1] ) );
$opt = $GLOBALS['options']['breanm_agents_boise-id'];
$opt['agents'][0]['reviews_window_years'] = 99;
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
check( 'on read a stored window that is out of range is dropped', ! array_key_exists( 'reviews_window_years', dos_agents_get( 'boise-id' )['agents'][0] ) );
check( 'for display every agent has the key, 0 when there is none', 0 === dos_agents_normalise_agent( agents_make() )['reviews_window_years'] && 6 === dos_agents_normalise_agent( agents_make( array( 'reviews_window_years' => 6 ) ) )['reviews_window_years'] && 0 === dos_agents_normalise_agent( agents_make( array( 'reviews_window_years' => 'x' ) ) )['reviews_window_years'] );

echo "\n--- payload errors ---\n";
check( 'a non-array payload is an error', is_wp_error( dos_agents_sanitize_payload( 'nope' ) ) );
check( 'a missing city is an error', is_wp_error( dos_agents_sanitize_payload( array( 'state' => 'ID', 'agents' => array() ) ) ) );
check( 'a bad state is an error', is_wp_error( dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'Idaho', 'agents' => array() ) ) ) );
check( 'missing agents is an error', is_wp_error( dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID' ) ) ) );
check( 'agents that is not a list is an error', is_wp_error( dos_agents_sanitize_payload( array( 'city' => 'Boise', 'state' => 'ID', 'agents' => 'x' ) ) ) );

echo "\n--- the REST routes ---\n";
dos_agents_register_routes();
$up  = $GLOBALS['routes']['breanm/v1/agents/upsert'] ?? null;
$get = $GLOBALS['routes']['breanm/v1/agents/(?P<slug>[a-z0-9-]+)'] ?? null;
check( 'both routes are registered under breanm/v1', null !== $up && null !== $get );
check( 'upsert is a POST', 'POST' === $up['methods'] );
check( 'upsert requires manage_options', 'dos_agents_rest_can_manage' === $up['permission_callback'] );
check( 'the GET route is a GET that requires manage_options too, not public', 'GET' === $get['methods'] && 'dos_agents_rest_can_manage' === $get['permission_callback'] && '__return_true' !== $get['permission_callback'] );
$GLOBALS['wp']['can'] = false;
check( 'a user without manage_options (an unauthenticated request) is refused on upsert', false === call_user_func( $up['permission_callback'] ) );
check( 'and on the GET', false === call_user_func( $get['permission_callback'] ) );
$GLOBALS['wp']['can'] = true;
check( 'an administrator is allowed on both', true === call_user_func( $up['permission_callback'] ) && true === call_user_func( $get['permission_callback'] ) );

$res = dos_agents_rest_upsert( new WP_REST_Request( $fx ) );
check( 'upsert answers ok with slug and count', true === $res->data['ok'] && 'boise-id' === $res->data['slug'] && count( $fx['agents'] ) === $res->data['count'], json_encode( $res->data ) );
check( 'it is stored under breanm_agents_boise-id', isset( $GLOBALS['options']['breanm_agents_boise-id'] ) );
check( 'the option is not autoloaded', false === $GLOBALS['autoload']['breanm_agents_boise-id'] );
check( 'the stored copy has only the schema (no skipped counter)', array( 'city', 'state', 'generated_at', 'agents' ) === array_keys( $GLOBALS['options']['breanm_agents_boise-id'] ) );

$res = dos_agents_rest_upsert( new WP_REST_Request( array( 'city' => 'Boise', 'state' => 'ID' ) ) );
check( 'a bad payload is a 400 error and stores nothing new', is_wp_error( $res ) && 400 === $res->data['status'] );
$before = count( $GLOBALS['options'] );
dos_agents_rest_upsert( new WP_REST_Request( array( 'city' => '', 'state' => 'ID', 'agents' => array() ) ) );
check( 'no option is written for a payload without a city', $before === count( $GLOBALS['options'] ) );

$res = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'boise-id' ) ) );
check( 'GET returns the stored payload', count( $fx['agents'] ) === count( $res->data['agents'] ) && 'Boise' === $res->data['city'] );
check( 'GET carries no skipped counter and only schema keys', array( 'city', 'state', 'generated_at', 'agents' ) === array_keys( $res->data ) && array_keys( $res->data['agents'][0] ) === $SCHEMA );
$GLOBALS['options']['breanm_agents_boise-id']['agents'][0]['password'] = 'leak';
$res = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'boise-id' ) ) );
check( 'GET drops a field that is not in the schema even if it got into the option', !isset( $res->data['agents'][0]['password'] ) );
$res = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'nowhere-zz' ) ) );
check( 'GET for an unknown city is a 404', is_wp_error( $res ) && 404 === $res->data['status'] );

echo "\n--- reading back (dos_agents_get) ---\n";
dos_agents_store( agents_fixture() );
$stored = $GLOBALS['options']['breanm_agents_boise-id'];
$n      = count( $stored['agents'] );
$stored['agents'][0]['profile_url'] = '';
$stored['agents'][1]['profile_url'] = 'https://evil.example/profile/x';
$stored['agents'][2]['injected']    = '<script>x</script>';
unset( $stored['agents'][3]['tags'], $stored['agents'][3]['office'] );
$GLOBALS['options']['breanm_agents_boise-id'] = $stored;
$got = dos_agents_get( 'boise-id' );
check( 'an agent whose stored profile_url was blanked or changed is dropped on read', $n - 2 === count( $got['agents'] ) );
check( 'an unknown key in a stored agent is dropped on read', !isset( $got['agents'][0]['injected'] ) && array_keys( $got['agents'][0] ) === $SCHEMA );
check( 'a stored agent missing keys comes back with every key', array() === $got['agents'][1]['tags'] && array( 'city', 'state', 'zip' ) === array_keys( $got['agents'][1]['office'] ) );
check( 'dos_agents_get never returns the skipped counter', array( 'city', 'state', 'generated_at', 'agents' ) === array_keys( $got ) );
$GLOBALS['options']['breanm_agents_bad-zz'] = array( 'nope' => 1 );
check( 'a stored value that is not a payload reads as nothing', null === dos_agents_get( 'bad-zz' ) && null === dos_agents_get( '' ) );

echo "\n--- the disclosure that travels with the city ---\n";
$base = array( 'city' => 'Boise', 'state' => 'ID', 'agents' => array( agents_make() ) );
$with = function ( $d ) use ( $base ) { return dos_agents_sanitize_payload( $base + array( 'disclosure' => $d ) ); };
$r = $with( array( 'title' => '  About this list  ', 'paragraphs' => array( "First   paragraph\n\twith   odd  spacing.", 'Second paragraph.' ) ) );
check( 'a valid disclosure is accepted, trimmed, with whitespace collapsed inside each paragraph', array( 'title' => 'About this list', 'paragraphs' => array( 'First paragraph with odd spacing.', 'Second paragraph.' ) ) === $r['disclosure'], json_encode( $r['disclosure'] ?? null ) );
check( 'it sits between generated_at and agents in the payload', array( 'city', 'state', 'generated_at', 'disclosure', 'agents', 'skipped' ) !== array_keys( $r ) && in_array( 'disclosure', array_keys( $r ), true ) );
$r = $with( array( 'title' => '<b>Bold</b> <i>title</i>', 'paragraphs' => array( 'Plain <a href="https://evil.example">link</a> and <script>x()</script> text', '<p>Wrapped</p>' ) ) );
check( 'tags and HTML are stripped from the title and the paragraphs', false === strpos( json_encode( $r['disclosure'] ), '<' ) && 'Bold title' === $r['disclosure']['title'] && 'Wrapped' === $r['disclosure']['paragraphs'][1], json_encode( $r['disclosure'] ) );
$r = $with( array( 'title' => str_repeat( 't', 200 ), 'paragraphs' => array_fill( 0, 9, str_repeat( 'p', 2000 ) ) ) );
check( 'the title is cut to 80 characters', 80 === strlen( $r['disclosure']['title'] ) );
check( 'at most 6 paragraphs, each cut to 900 characters', 6 === count( $r['disclosure']['paragraphs'] ) && 900 === strlen( $r['disclosure']['paragraphs'][0] ) && 900 === strlen( $r['disclosure']['paragraphs'][5] ) );
$r = $with( array( 'paragraphs' => array( '', '   ', 'Kept', 7, array( 'x' ), null, "\n\t" ) ) );
check( 'empty, blank and non-string paragraphs are dropped; a missing title is empty', array( 'title' => '', 'paragraphs' => array( 'Kept' ) ) === $r['disclosure'] );
$r = $with( array( 'paragraphs' => array_merge( array_fill( 0, 3, '   ' ), array_fill( 0, 6, 'Real' ) ) ) );
check( 'the cap counts paragraphs that survive, so blanks do not use it up', 6 === count( $r['disclosure']['paragraphs'] ) );
$r = $with( array( 'title' => 'Only a title', 'paragraphs' => array() ) );
check( 'an object with no paragraphs is dropped whole, even with a title', ! array_key_exists( 'disclosure', $r ) );
check( 'so is one whose paragraphs are all blank', ! array_key_exists( 'disclosure', $with( array( 'title' => 'T', 'paragraphs' => array( '', '  ', "\n" ) ) ) ) );
check( 'and one that is not an object, or has paragraphs that are not a list', ! array_key_exists( 'disclosure', $with( 'a string' ) ) && ! array_key_exists( 'disclosure', $with( 42 ) ) && ! array_key_exists( 'disclosure', $with( array( 'paragraphs' => 'text' ) ) ) && ! array_key_exists( 'disclosure', $with( array() ) ) );
$r = $with( array( 'title' => array( 'x' ), 'paragraphs' => array( 'Kept' ) ) );
check( 'a title that is not a string becomes empty', '' === $r['disclosure']['title'] );
$r = $with( array( 'title' => 'T', 'paragraphs' => array( 'P' ), 'html' => '<script>x</script>', 'footer' => 'x' ) );
check( 'unknown keys inside the object are dropped', array( 'title', 'paragraphs' ) === array_keys( $r['disclosure'] ) );
check( 'a payload without one has no disclosure key at all (stored exactly as before)', ! array_key_exists( 'disclosure', dos_agents_sanitize_payload( $base ) ) );

$full = $base + array( 'disclosure' => array( 'title' => 'Read this', 'paragraphs' => array( 'One.', 'Two.' ) ) );
dos_agents_store( $full );
check( 'it is stored with the city payload', array( 'title' => 'Read this', 'paragraphs' => array( 'One.', 'Two.' ) ) === $GLOBALS['options']['breanm_agents_boise-id']['disclosure'] && ! array_key_exists( 'skipped', $GLOBALS['options']['breanm_agents_boise-id'] ) );
$got = dos_agents_rest_get( new WP_REST_Request( null, array( 'slug' => 'boise-id' ) ) );
check( 'the authenticated GET returns it unchanged', array( 'title' => 'Read this', 'paragraphs' => array( 'One.', 'Two.' ) ) === $got->data['disclosure'] );
$opt = $GLOBALS['options']['breanm_agents_boise-id'];
$opt['disclosure'] = array( 'title' => '<b>Hacked</b>', 'paragraphs' => array( '<script>x()</script>Kept', '' ), 'extra' => 'x' );
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
$back = dos_agents_get( 'boise-id' );
check( 'on read the stored disclosure is re-sanitised like the rest', false === strpos( json_encode( $back['disclosure'] ), '<' ) && array( 'title', 'paragraphs' ) === array_keys( $back['disclosure'] ) && 1 === count( $back['disclosure']['paragraphs'] ) );
$opt['disclosure'] = array( 'title' => 'T', 'paragraphs' => array() );
$GLOBALS['options']['breanm_agents_boise-id'] = $opt;
check( 'a stored disclosure that no longer has a paragraph reads as none', ! array_key_exists( 'disclosure', dos_agents_get( 'boise-id' ) ) );

finish();
