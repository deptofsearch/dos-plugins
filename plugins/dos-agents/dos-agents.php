<?php
/**
 * Plugin Name: DoS - Agents
 * Plugin URI: https://github.com/deptofsearch/dos-plugins
 * Description: Filterable real-estate agent cards for a city page on Best Real Estate Agents Near Me, plus the homepage city search and "Browse by state" carousel and the site's brand layer. Agent data is pushed to a REST endpoint and stored per city; [breanm_agents city="boise-id"] renders the cards, search, filters and sort.
 * Version: 0.2.4
 * Author: Department of Search
 * Text Domain: dos-agents
 * Update URI: https://github.com/deptofsearch/dos-plugins
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_AGENTS_VERSION', '0.2.4' );
define( 'DOS_AGENTS_FILE', __FILE__ );
// One option per city, never autoloaded: a city holds up to 500 agents and only its own page reads it.
define( 'DOS_AGENTS_OPTION_PREFIX', 'breanm_agents_' );
define( 'DOS_AGENTS_DISCLOSURE_OPTION', 'breanm_agents_disclosure' );
define( 'DOS_AGENTS_MAX_AGENTS', 500 );
// The list of cities that have cards, derived from the per-city options. Not autoloaded: only the
// homepage, the state hubs and the cities route read it. See includes/index.php.
define( 'DOS_AGENTS_INDEX_OPTION', 'breanm_agents_index' );
define( 'DOS_AGENTS_INDEX_VER_OPTION', 'breanm_agents_index_ver' );

require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/outlines.php';
require_once __DIR__ . '/includes/index.php';
require_once __DIR__ . '/includes/search.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/render.php';
require_once __DIR__ . '/includes/home.php';
require_once __DIR__ . '/includes/hub.php';
require_once __DIR__ . '/includes/brand.php';
require_once __DIR__ . '/includes/layout.php';

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-agents',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => DOS_AGENTS_VERSION,
		'name'        => 'DoS - Agents',
		'description' => 'Best Real Estate Agents Near Me: filterable agent cards for a city page, the homepage city search and state carousel, the state hub blocks, and the site brand layer.',
	)
) )->boot();

// No activation hook: "Replace current with uploaded" and updater installs never fire one.
add_action( 'init', 'dos_agents_register_shortcode' );
add_action( 'init', 'dos_agents_register_home_shortcodes' );
add_action( 'rest_api_init', 'dos_agents_register_routes' );
add_action( 'wp_enqueue_scripts', 'dos_agents_maybe_enqueue' );
add_action( 'wp_enqueue_scripts', 'dos_agents_maybe_enqueue_home' );

// The index follows the sweep's two steps: the agents are pushed (store.php), then the page is edited.
add_action( 'save_post', 'dos_agents_index_on_save', 10, 2 );
add_action( 'deleted_post', 'dos_agents_index_on_delete', 10, 2 );

// The homepage search without JavaScript: one match goes straight to the city.
add_action( 'template_redirect', 'dos_agents_search_fallback', 1 );

// Brand layer, site-wide. Priority 5 only so the brand paints early; it prints BEFORE the theme's
// stylesheet, so order is not what wins. Every rule is scoped under body.breanm-brand, and that
// extra class is what beats Themify. Do not drop the scope on the strength of this priority.
add_action( 'wp_enqueue_scripts', 'dos_agents_brand_enqueue', 5 );
add_filter( 'body_class', 'dos_agents_brand_body_class' );
add_action( 'template_redirect', 'dos_agents_brand_start_buffer', 1 );
add_action( 'wp_head', 'dos_agents_brand_icons', 2 );

// State hubs get the cities-with-cards block without the 49 pages being rewritten. Priority 9 so it
// runs before wpautop (10), which would otherwise wrap the block's markup in stray paragraphs.
add_filter( 'the_content', 'dos_agents_hub_the_content', 9 );

// City pages and state hubs render as Pages, not posts: no sidebar, no post meta.
add_filter( 'get_post_metadata', 'dos_agents_layout_meta', 10, 4 );
add_filter( 'body_class', 'dos_agents_layout_body_class', 99 );
