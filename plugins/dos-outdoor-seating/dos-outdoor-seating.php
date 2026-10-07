<?php
/**
 * Plugin Name:       DoS Outdoor Seating
 * Plugin URI:        https://github.com/deptofsearch/dos-plugins
 * Description:       Restaurant venue pages, searchable patio card grids, a REST upsert for the n8n enrichment pipeline, featured venue photos, a city search box, redesigned state pages, an opt-in homepage takeover, an opt-in takeover of TablePress city tables, city neighborhoods, landmark zones with casino browse, and SEO titles/descriptions for DoS Toolkit for outdoorseatingnearme.com.
 * Version:           0.6.4
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Department of Search
 * License:           GPL-2.0-or-later
 * Text Domain:       dos-outdoor-seating
 * Update URI:        https://github.com/deptofsearch/dos-plugins
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.6.4';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-fields.php';
require_once __DIR__ . '/includes/class-data-model.php';
require_once __DIR__ . '/includes/class-icons.php';
require_once __DIR__ . '/includes/class-states.php';
require_once __DIR__ . '/includes/class-util.php';
require_once __DIR__ . '/includes/class-assets.php';
require_once __DIR__ . '/includes/class-repository.php';
require_once __DIR__ . '/includes/class-city-index.php';
require_once __DIR__ . '/includes/class-venue-image.php';
require_once __DIR__ . '/includes/class-hoods.php';
require_once __DIR__ . '/includes/class-landmarks.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-cards.php';
require_once __DIR__ . '/includes/class-venue-page.php';
require_once __DIR__ . '/includes/class-takeover.php';
require_once __DIR__ . '/includes/class-city-search.php';
require_once __DIR__ . '/includes/class-state-cities.php';
require_once __DIR__ . '/includes/class-home.php';
require_once __DIR__ . '/includes/class-page-title.php';
require_once __DIR__ . '/includes/class-image-redirects.php';
require_once __DIR__ . '/includes/class-seo.php';
require_once __DIR__ . '/includes/class-admin.php';

Data_Model::hooks();
Assets::hooks();
City_Index::hooks();
Rest::hooks();
Hoods::hooks();
Landmarks::hooks();
Cards::hooks();
Venue_Page::hooks();
Takeover::hooks();
City_Search::hooks();
State_Cities::hooks();
Home::hooks();
Page_Title::hooks();
Image_Redirects::hooks();
Seo::hooks();
Admin::hooks();

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';

( new \DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-outdoor-seating',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => VERSION,
		'name'        => 'DoS Outdoor Seating',
		'description' => 'Restaurant venue pages, searchable patio card grids and the n8n venue upsert for outdoorseatingnearme.com.',
	)
) )->boot();

register_activation_hook( __FILE__, array( Data_Model::class, 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
