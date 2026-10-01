<?php
/**
 * City Search 2.0.0: moving the settings of the two plugins it merges (the REVNM 1.x settings and
 * the Open Houses In plugin's) into one shape, and the handoff that retires the Open Houses In plugin.
 * The migration runs on every load, so the cases that matter most are the ones where it must do nothing.
 */

require __DIR__ . '/wp-stubs-city-search.php';
require dirname( __DIR__ ) . '/plugins/dos-city-search/dos-city-search.php';
require dirname( __DIR__ ) . '/plugins/dos-city-search/includes/admin.php';

// Only the admin page render needs these.
function settings_errors() {} function settings_fields() {} function submit_button() {} function wp_nonce_field() {}
function checked( $a, $b = true ) { echo $a == $b ? 'checked="checked"' : ''; }
function selected( $a, $b = true ) { echo $a == $b ? 'selected="selected"' : ''; }
function number_format_i18n( $n ) { return number_format( $n ); }
function human_time_diff( $t ) { return '1 min'; }
function get_edit_post_link( $id ) { return 'https://example.com/wp-admin/post.php?post=' . $id; }
function get_the_title( $id ) { return 'Page ' . $id; }

$old_ohi = array(
	'auto_insert'  => 1,
	'heading'      => 'Find Open Houses in Tampa',
	'intro'        => 'Pick a city.',
	'placeholder'  => 'Start typing',
	'button_label' => 'Go',
	'bg_color'     => '#fff0e0',
	'accent_color' => '#aa3311',
);

echo "--- (a) REVNM upgrade ---\n";
cs_reset();
$revnm = array( 'auto_insert' => 1, 'heading' => 'Search Home Values by City', 'intro' => 'My intro.', 'placeholder' => 'City, State', 'button_label' => 'Find', 'bg_color' => '#abcdef' );
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = $revnm;
check( 'reports that it wrote', true === dos_city_search_maybe_migrate() );
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'keeps the copy and the background colour', 'My intro.' === $o['intro'] && 'Find' === $o['button_label'] && '#abcdef' === $o['bg_color'] && 'Search Home Values by City' === $o['heading'] );
check( 'switches to the slug source, placed after the hero, no browse link', 'slug_state' === $o['source'] && 'hero_class' === $o['insert_after'] && '' === $o['browse_slug'] && 'rv-hero' === $o['hero_class'] );
check( 'adds the classes the site CSS is written for', 'rv-sec rv-city-search' === $o['section_class'] && 'rv-search' === $o['form_class'] && 'rv-btn' === $o['button_class'] );
check( 'marks the settings as schema 2', 2 === $o['schema'] );
check( 'fills the new keys from the defaults', 8 === $o['max_results'] && 0 === $o['show_all_on_focus'] && '#1a5fb4' === $o['accent_color'] && 'ohi_city' === $o['taxonomy'] );
check( 'is not marked as carried over from anything', ! isset( $o['migrated_from'] ) );
$n = count( $GLOBALS['writes'] );
check( 're-running writes nothing', false === dos_city_search_maybe_migrate() && $n === count( $GLOBALS['writes'] ) );
check( 'the settings read back through settings() are the migrated ones', 'slug_state' === dos_city_search_settings()['source'] );

echo "\n--- (b) Open Houses In move ---\n";
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
$GLOBALS['options'][ DOS_CITY_SEARCH_META ]        = array( 'untouched' => 1 );
check( 'reports that it wrote', true === dos_city_search_maybe_migrate() );
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'copies the six shared keys', 1 === $o['auto_insert'] && 'Find Open Houses in Tampa' === $o['heading'] && 'Pick a city.' === $o['intro'] && 'Start typing' === $o['placeholder'] && 'Go' === $o['button_label'] && '#fff0e0' === $o['bg_color'], json_encode( $o ) );
check( 'copies accent_color', '#aa3311' === $o['accent_color'] );
check( 'uses the City taxonomy, automatic placement, and the by-city browse page', 'taxonomy' === $o['source'] && 'ohi_city' === $o['taxonomy'] && 'auto' === $o['insert_after'] && 'by-city' === $o['browse_slug'] );
check( 'adds no classes of its own', '' === $o['section_class'] && '' === $o['form_class'] && '' === $o['button_class'] );
check( 'keeps the old behaviour: every city on focus, no cap', 1 === $o['show_all_on_focus'] && 0 === $o['max_results'] );
check( 'records where it came from, at schema 2', 'dos-ohi-city-search' === $o['migrated_from'] && 2 === $o['schema'] );
check( 'the old plugin\'s options are untouched', $old_ohi === $GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] && array( 'untouched' => 1 ) === $GLOBALS['options'][ DOS_CITY_SEARCH_META ] );
check( 'the only option written was this plugin\'s own', array( DOS_CITY_SEARCH_OPTION ) === $GLOBALS['writes'], json_encode( $GLOBALS['writes'] ) );
$n = count( $GLOBALS['writes'] );
check( 're-running writes nothing', false === dos_city_search_maybe_migrate() && $n === count( $GLOBALS['writes'] ) );
check( 'and still leaves the old option alone', $old_ohi === $GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] );

echo "\n--- (c) fresh install ---\n";
cs_reset();
check( 'reports that it wrote', true === dos_city_search_maybe_migrate() );
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'defaults, automatic source, schema 2', dos_city_search_defaults() === $o && 'auto' === $o['source'] && 2 === $o['schema'] );
check( 'nothing is read from or written to the old option', ! isset( $GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] ) && array( DOS_CITY_SEARCH_OPTION ) === $GLOBALS['writes'] );
$n = count( $GLOBALS['writes'] );
check( 're-running writes nothing', false === dos_city_search_maybe_migrate() && $n === count( $GLOBALS['writes'] ) );

echo "\n--- precedence ---\n";
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = array_merge( dos_city_search_defaults(), array( 'heading' => 'Mine', 'source' => 'taxonomy' ) );
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
check( 'settings already at schema 2 are never overwritten, even with the old option present', false === dos_city_search_maybe_migrate() && array() === $GLOBALS['writes'] && 'Mine' === $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['heading'] );
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = $revnm;
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
dos_city_search_maybe_migrate();
check( 'REVNM settings without schema win over a stray old option', 'slug_state' === $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['source'] && ! isset( $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['migrated_from'] ) );
check( 'the migration is hooked to plugins_loaded and needs no activation hook', false === strpos( file_get_contents( dirname( __DIR__ ) . '/plugins/dos-city-search/dos-city-search.php' ), 'register_activation_hook' ) );

echo "\n--- sites that never saved settings ---\n";
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_META ] = array( 'built' => 1, 'fallbacks' => array(), 'fallback_n' => 0 ); // what 1.x wrote
check( 'REVNM site that never saved settings: migrated, not treated as fresh', true === dos_city_search_maybe_migrate() );
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'it gets the rv-* classes, hero placement and slug source', 'rv-sec rv-city-search' === $o['section_class'] && 'rv-search' === $o['form_class'] && 'rv-btn' === $o['button_class'] && 'hero_class' === $o['insert_after'] && 'rv-hero' === $o['hero_class'] && 'slug_state' === $o['source'] && '' === $o['browse_slug'] && 2 === $o['schema'], json_encode( $o ) );
check( 'with the default copy and colour, and no migrated_from', '#eaf2fc' === $o['bg_color'] && 'Search Home Values by City' === $o['heading'] && ! isset( $o['migrated_from'] ) );
$n = count( $GLOBALS['writes'] );
check( 're-running writes nothing', false === dos_city_search_maybe_migrate() && $n === count( $GLOBALS['writes'] ) );

cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_META ] = array( 'built' => 1, 'source' => 'slug_state', 'count' => 3, 'fallbacks' => array(), 'fallback_n' => 0 ); // a 2.x build
dos_city_search_maybe_migrate();
check( 'an index meta from a 2.x build is not 1.x evidence', 'auto' === $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['source'] );
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_META ] = array( 'built' => 1 );
dos_city_search_maybe_migrate();
check( 'neither is a meta with no fallback fields', 'auto' === $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['source'] );

cs_reset();
$GLOBALS['options']['dos_ohi_cs_index_meta'] = array( 'built' => 1, 'skipped' => array() );
check( 'OHI site, old plugin already deactivated, never saved settings: migrated', true === dos_city_search_maybe_migrate() && ! function_exists( 'dos_ohi_cs_markup' ) );
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'it gets the taxonomy source, the old plugin\'s own wording and colours, and migrated_from', 'taxonomy' === $o['source'] && 'by-city' === $o['browse_slug'] && 'Find Open Houses by City' === $o['heading'] && '#b5522b' === $o['accent_color'] && '#faf5f0' === $o['bg_color'] && 'dos-ohi-city-search' === $o['migrated_from'], json_encode( $o ) );
check( 'and the old plugin\'s index meta is untouched', array( 'built' => 1, 'skipped' => array() ) === $GLOBALS['options']['dos_ohi_cs_index_meta'] && array( DOS_CITY_SEARCH_OPTION ) === $GLOBALS['writes'] );

echo "\n--- saving settings keeps them intact ---\n";
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = $revnm;
dos_city_search_maybe_migrate();
$migrated = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
$saved    = dos_city_search_sanitize( $migrated );
ksort( $migrated ); ksort( $saved );
check( 'saving the migrated REVNM settings unchanged changes nothing', $migrated === $saved, json_encode( array_diff_assoc( $saved, $migrated ) ) );
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
dos_city_search_maybe_migrate();
$saved = dos_city_search_sanitize( array( 'heading' => 'New' ) + $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] );
check( 'a save keeps migrated_from', 'dos-ohi-city-search' === ( $saved['migrated_from'] ?? '' ) );
$bad = dos_city_search_sanitize( array(
	'source' => 'nonsense', 'insert_after' => 'x', 'taxonomy' => 'Bad Tax!', 'hero_class' => 'a b<c', 'browse_slug' => '/Browse/Us/',
	'section_class' => 'ok  <b>bad</b> ok', 'form_class' => '', 'button_class' => 'x"onclick="y', 'max_results' => '-5', 'accent_color' => 'red', 'show_all_on_focus' => '1',
) );
check( 'junk is cleaned: enums fall back, names are stripped', 'auto' === $bad['source'] && 'auto' === $bad['insert_after'] && 'badtax' === $bad['taxonomy'] && 'abc' === $bad['hero_class'] && 'browse/us' === $bad['browse_slug'], json_encode( $bad ) );
check( 'classes lose anything that is not a class-name character, and duplicates', 'ok bbad/b' !== $bad['section_class'] && false === strpos( $bad['section_class'], '<' ) && 'xonclicky' === preg_replace( '/[^a-z]/', '', $bad['button_class'] ) && 1 === substr_count( $bad['section_class'], 'ok' ), json_encode( $bad ) );
check( 'numbers are absolute, colours fall back, ticks are 0 or 1', 5 === $bad['max_results'] && '#1a5fb4' === $bad['accent_color'] && 1 === $bad['show_all_on_focus'] && 0 === $bad['auto_insert'] );
check( 'every default key is present after a save', array() === array_diff_key( dos_city_search_defaults(), $bad ) );

echo "\n--- the Open Houses In handoff: front end ---\n";
check( 'the decision is false while the old plugin is active', false === dos_city_search_front_enabled( true ) );
check( 'and true otherwise', true === dos_city_search_front_enabled( false ) );
cs_reset();
dos_city_search_boot();
check( 'old plugin absent: the front end is registered, [ohi_city_search] alias included', isset( $GLOBALS['shortcodes']['ohi_city_search'], $GLOBALS['shortcodes']['dos_city_search'] ) && ! empty( $GLOBALS['filters']['the_content'] ) );
check( 'the index and REST hooks are registered either way', ! empty( $GLOBALS['actions']['transition_post_status'] ) && ! empty( $GLOBALS['actions']['rest_api_init'] ) );

// The old plugin's markup function: its presence means it is loaded. Declared inside a block so it
// only exists from here on; a bare declaration is hoisted to the top of the file.
if ( ! function_exists( 'dos_ohi_cs_markup' ) ) {
	function dos_ohi_cs_markup() {}
}
cs_reset();
dos_city_search_boot();
check( 'old plugin loaded: no shortcodes at all', array() === $GLOBALS['shortcodes'], implode( ',', array_keys( $GLOBALS['shortcodes'] ) ) );
check( 'old plugin loaded: no the_content filter', empty( $GLOBALS['filters']['the_content'] ) );
check( 'old plugin loaded: the index and REST hooks still run', ! empty( $GLOBALS['actions']['transition_post_status'] ) && ! empty( $GLOBALS['actions']['rest_api_init'] ) );

cs_reset();
dos_city_search_maybe_migrate();
$o = $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ];
check( 'old plugin loaded but never saved settings: it still counts as an Open Houses In move, with its own wording', 'dos-ohi-city-search' === ( $o['migrated_from'] ?? '' ) && 'Find Open Houses by City' === $o['heading'] && '#b5522b' === $o['accent_color'] && 'taxonomy' === $o['source'], json_encode( $o ) );

echo "\n--- the Open Houses In handoff: admin ---\n";
cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
$GLOBALS['cs']['active'] = array( DOS_CITY_SEARCH_OLD_PLUGIN );
check( 'hooked to admin_init at priority 5, before the settings are registered at 10', false !== strpos( file_get_contents( dirname( __DIR__ ) . '/plugins/dos-city-search/includes/admin.php' ), "add_action( 'admin_init', 'dos_city_search_handoff', 5 );" ) );
dos_city_search_handoff();
check( 'the old plugin is deactivated', array( 'dos-ohi-city-search/dos-ohi-city-search.php' ) === $GLOBALS['cs']['deactivated'] );
check( 'its settings were migrated first', 'dos-ohi-city-search' === ( $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['migrated_from'] ?? '' ) && '#aa3311' === $GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ]['accent_color'] );
check( 'its options are still there for a rollback', $old_ohi === $GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] );
ob_start(); dos_city_search_handoff_notice(); $note = ob_get_clean();
check( 'a notice says what happened', false !== strpos( $note, 'DoS - City Search replaced DoS Open Houses In City Search; its settings were carried over. You can delete the old plugin.' ), $note );
ob_start(); dos_city_search_handoff_notice(); $again = ob_get_clean();
check( 'and shows only once', '' === $again );
$w = count( $GLOBALS['writes'] );
dos_city_search_handoff();
check( 'a second admin load does nothing more (the old plugin is no longer active)', 1 === count( $GLOBALS['cs']['deactivated'] ) && $w === count( $GLOBALS['writes'] ) );

cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
$GLOBALS['cs']['active'] = array( DOS_CITY_SEARCH_OLD_PLUGIN );
$GLOBALS['cs']['can']    = false;
dos_city_search_handoff();
check( 'a user who cannot activate plugins changes nothing', array() === $GLOBALS['cs']['deactivated'] && array() === $GLOBALS['writes'] );
ob_start(); dos_city_search_handoff_notice(); check( '… and sees no notice', '' === ob_get_clean() );

cs_reset();
dos_city_search_handoff();
check( 'no old plugin active: nothing happens', array() === $GLOBALS['cs']['deactivated'] && array() === $GLOBALS['writes'] );

echo "\n--- the rebuild action and the dashboard ---\n";
$src = file_get_contents( dirname( __DIR__ ) . '/plugins/dos-city-search/includes/admin.php' );
check( 'the rebuild handler checks capability and nonce, then flushes and rebuilds', 1 === preg_match( '/admin_post_dos_city_search_rebuild.*?current_user_can\( \'manage_options\' \).*?check_admin_referer\( \'dos_city_search_rebuild\' \).*?dos_city_search_flush\(\).*?dos_city_search_get_index\(\)/s', $src ) );

function cs_page_html() { ob_start(); dos_city_search_render_page(); return ob_get_clean(); }

cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OPTION ] = $revnm;
dos_city_search_maybe_migrate();
cs_cities( 2 );
$html = cs_page_html();
check( 'dashboard renders for a REVNM site; the preview is wrapped for its rv- CSS', false !== strpos( $html, '<div id="dos-cs-preview" class="revnm-home">' ) && false !== strpos( $html, 'states covered' ), substr( $html, 0, 100 ) );

cs_reset();
$GLOBALS['options'][ DOS_CITY_SEARCH_OLD_OPTION ] = $old_ohi;
dos_city_search_maybe_migrate();
$GLOBALS['cs']['taxonomies'] = array( 'ohi_city' );
$GLOBALS['cs']['page_ids']   = array( 3, 5 );
$GLOBALS['cs']['slugs']      = array( 3 => 'austin', 5 => 'austin-2' );
$GLOBALS['cs']['terms']      = array( 3 => array( cs_term( 1, 'Austin' ) ), 5 => array( cs_term( 1, 'Austin' ) ) );
$html = cs_page_html();
check( 'dashboard for an Open Houses In site: no REVNM wrapper, source card, migrated-from card, skipped list', false !== strpos( $html, '<div id="dos-cs-preview">' ) && false !== strpos( $html, 'Pages tagged with a City (ohi_city)' ) && false !== strpos( $html, 'dos-ohi-city-search' ) && false !== strpos( $html, 'City-tagged pages left out' ), substr( $html, 0, 100 ) );
check( 'no missing-taxonomy notice while the taxonomy exists', false === strpos( $html, 'isn’t registered' ) );
$GLOBALS['cs']['taxonomies'] = array();
dos_city_search_flush();
check( 'the notice appears when the taxonomy source is set but the taxonomy is missing', false !== strpos( cs_page_html(), 'isn’t registered' ) );

finish();
