<?php
/**
 * Plugin Name:       DoS Best Lenders
 * Plugin URI:        https://github.com/deptofsearch/dos-plugins
 * Description:       Brand layer (fonts, tokens, logo), data model, REST surface, and rendering for bestlendersnearme.com: one page per city (blnm_city) with filterable mortgage lender cards built from public HMDA data, lender profiles (blnm_lender), [blnm_city_search] and [blnm_state_index]. Market-agnostic: no city lists or lender data live in this code.
 * Version:           0.7.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Department of Search
 * License:           GPL-2.0-or-later
 * Text Domain:       dos-best-lenders
 * Update URI:        https://github.com/deptofsearch/dos-plugins
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.7.1';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-data-model.php';
require_once __DIR__ . '/includes/class-render.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-maps.php';
require_once __DIR__ . '/includes/class-brand.php';
require_once __DIR__ . '/includes/class-frontend.php';
require_once __DIR__ . '/includes/class-layout.php';
require_once __DIR__ . '/includes/class-social.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-shortcodes.php';
require_once __DIR__ . '/includes/class-admin.php';

Data_Model::hooks();
Rest::hooks();
Maps::hooks();
Brand::hooks();
Frontend::hooks();
Layout::hooks();
Social::hooks();
Settings::hooks();
Search::hooks();
Admin::hooks();
Shortcodes::hooks();

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';

( new \DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-best-lenders',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => VERSION,
		'name'        => 'DoS Best Lenders',
		'description' => 'City pages of mortgage lenders built from public HMDA data, with filterable lender cards and review summaries, for bestlendersnearme.com.',
	)
) )->boot();

register_activation_hook(
	__FILE__,
	static function () {
		Data_Model::register();
		flush_rewrite_rules();
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
