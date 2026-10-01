<?php
/**
 * Plugin Name: DoS - City Search
 * Plugin URI: https://github.com/deptofsearch/dos-plugins
 * Description: City search box for a market site. Autocompletes from the site's published city pages (by URL ending in a state code, or by a City taxonomy) and jumps straight to the chosen page. Auto-inserted on the homepage; also available as [dos_city_search]. Settings and index status under Tools → City Search.
 * Version: 2.0.0
 * Author: Department of Search
 * Text Domain: dos-city-search
 * Update URI: https://github.com/deptofsearch/dos-plugins
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_CITY_SEARCH_VERSION', '2.0.0' );
// The index changed shape in 2.0.0 (objects, not "label|slug" strings), so it gets a new key.
// The old revnm_city_index_v1 transient is never read: it holds strings.
define( 'DOS_CITY_SEARCH_TRANSIENT', 'dos_city_search_index_v2' );
define( 'DOS_CITY_SEARCH_META', 'dos_city_search_index_meta' );
define( 'DOS_CITY_SEARCH_OPTION', 'dos_city_search_settings' );
define( 'DOS_CITY_SEARCH_OLD_OPTION', 'dos_ohi_cs_settings' );
define( 'DOS_CITY_SEARCH_OLD_META', 'dos_ohi_cs_index_meta' );
define( 'DOS_CITY_SEARCH_OLD_PLUGIN', 'dos-ohi-city-search/dos-ohi-city-search.php' );

require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/front.php';

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-city-search',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => DOS_CITY_SEARCH_VERSION,
		'name'        => 'DoS - City Search',
		'description' => 'City search box for a market site. Autocompletes from the site\'s published city pages and jumps straight to the chosen page.',
	)
) )->boot();

// No activation hook: "Replace current with uploaded" and updater installs never fire one.
add_action( 'plugins_loaded', 'dos_city_search_maybe_migrate', 5 );
add_action( 'plugins_loaded', 'dos_city_search_boot', 10 );

function dos_city_search_defaults() {
	return array(
		'schema'            => 2,
		'auto_insert'       => 1,
		'source'            => 'auto',
		'taxonomy'          => 'ohi_city',
		'insert_after'      => 'auto',
		'hero_class'        => 'rv-hero',
		'browse_slug'       => 'by-city',
		'heading'           => 'Search Home Values by City',
		'intro'             => 'Start typing your city, pick it from the list, and press Enter to go straight to its market report.',
		'placeholder'       => 'City, State (e.g. Kennewick, WA)',
		'button_label'      => 'Search',
		'bg_color'          => '#eaf2fc',
		'accent_color'      => '#1a5fb4',
		'section_class'     => '',
		'form_class'        => '',
		'button_class'      => '',
		'max_results'       => 8,
		'show_all_on_focus' => 0,
	);
}

function dos_city_search_settings() {
	return wp_parse_args( (array) get_option( DOS_CITY_SEARCH_OPTION, array() ), dos_city_search_defaults() );
}

/**
 * Where the Open Houses In plugin's copy was when it was moved over: its own defaults, so a
 * setting it never saved still comes across as that site saw it, not as the REVNM wording.
 */
function dos_city_search_ohi_defaults() {
	return array(
		'auto_insert'  => 1,
		'heading'      => 'Find Open Houses by City',
		'intro'        => 'Start typing a city and pick it from the list to see every open house scheduled there.',
		'placeholder'  => 'Start typing a city',
		'button_label' => 'Search',
		'bg_color'     => '#faf5f0',
		'accent_color' => '#b5522b',
	);
}

/**
 * Whether the index meta is the one 1.x wrote: fallback counts, and no `source` key (2.0.0 adds one
 * to every build). Evidence that a REVNM site ran 1.x on its defaults and never saved a setting.
 */
function dos_city_search_has_1x_footprint() {
	$meta = get_option( DOS_CITY_SEARCH_META, false );
	return is_array( $meta ) && ! isset( $meta['source'] ) && ( isset( $meta['fallback_n'] ) || isset( $meta['fallbacks'] ) );
}

/**
 * Brings the stored settings up to the 2.0.0 shape. Runs on every load and writes only when the
 * settings have no `schema` key yet, so a site that is already current costs one get_option().
 *
 * a) REVNM upgrade: settings exist without `schema`, or 1.x left its index meta and no settings. Keep the copy and colour, and switch on the
 *    classes and placement the site's own CSS was built around, so the box renders as before.
 * b) Open Houses In move: no settings here, but the old plugin's exist (or it left an index behind). Carry them over.
 *    Its options are left alone, so the old plugin can be reactivated for a rollback.
 * c) Fresh install: defaults.
 *
 * @return bool Whether anything was written.
 */
function dos_city_search_maybe_migrate() {
	$cur = get_option( DOS_CITY_SEARCH_OPTION, false );

	if ( is_array( $cur ) && isset( $cur['schema'] ) && (int) $cur['schema'] >= 2 ) {
		return false;
	}

	$old = is_array( $cur ) ? false : get_option( DOS_CITY_SEARCH_OLD_OPTION, false );

	if ( is_array( $cur ) || dos_city_search_has_1x_footprint() ) {
		// $cur is empty for a REVNM site that never saved its 1.x settings: it ran on the defaults,
		// and the site's CSS still expects the rv-* markup, so it takes this branch all the same.
		$new = array_merge(
			dos_city_search_defaults(),
			is_array( $cur ) ? $cur : array(),
			array(
				'source'        => 'slug_state',
				'insert_after'  => 'hero_class',
				'browse_slug'   => '',
				'section_class' => 'rv-sec rv-city-search',
				'form_class'    => 'rv-search',
				'button_class'  => 'rv-btn',
				'schema'        => 2,
			)
		);
	} elseif ( is_array( $old ) || function_exists( 'dos_ohi_cs_markup' ) || is_array( get_option( DOS_CITY_SEARCH_OLD_META, false ) ) ) {
		// The old plugin being active, or having built an index, counts too: it may never have saved
		// its settings, and its defaults are not this plugin's.
		$old = array_merge( dos_city_search_ohi_defaults(), is_array( $old ) ? $old : array() );
		$new = dos_city_search_defaults();
		foreach ( array( 'auto_insert', 'heading', 'intro', 'placeholder', 'button_label', 'bg_color', 'accent_color' ) as $k ) {
			$new[ $k ] = $old[ $k ];
		}
		$new['source']        = 'taxonomy';
		$new['taxonomy']      = 'ohi_city';
		$new['insert_after']  = 'auto';
		$new['browse_slug']   = 'by-city';
		// The old plugin had no cap and listed every city on focus; keep that for the sites it ran on.
		$new['max_results']       = 0;
		$new['show_all_on_focus'] = 1;
		$new['schema']            = 2;
		$new['migrated_from']     = 'dos-ohi-city-search';
	} else {
		$new = dos_city_search_defaults();
	}

	update_option( DOS_CITY_SEARCH_OPTION, $new );
	return true;
}

/**
 * Whether this plugin may put the search box on the front end. While the Open Houses In plugin is
 * still active in the same request it owns the box, and a second one would render beside it.
 * Its own admin handoff (includes/admin.php) deactivates it on the next admin page load.
 */
function dos_city_search_front_enabled( $old_plugin_active ) {
	return ! $old_plugin_active;
}

function dos_city_search_boot() {
	dos_city_search_register_index_hooks();
	dos_city_search_register_rest();

	if ( dos_city_search_front_enabled( function_exists( 'dos_ohi_cs_markup' ) ) ) {
		dos_city_search_register_front();
	}
}
