<?php
/**
 * Plugin Name: DoS Shot and a Beer Home
 * Plugin URI:  https://github.com/deptofsearch/dos-plugins
 * Description: The shotandabeer.com homepage: a search box that suggests restaurants, cuisines, dishes and cities as you type, category cards, latest reviews and city links. Replaces the front page's content while switched on; switching it off restores the page as it was.
 * Version:     0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-saab-home
 * Update URI:  https://github.com/deptofsearch/dos-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_SAAB_HOME_VERSION', '0.1.0' );
define( 'DOS_SAAB_HOME_FILE', __FILE__ );
define( 'DOS_SAAB_HOME_URL', plugin_dir_url( __FILE__ ) );

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';
require_once __DIR__ . '/includes/class-search.php';
require_once __DIR__ . '/includes/class-home.php';
require_once __DIR__ . '/includes/class-admin.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-saab-home',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => DOS_SAAB_HOME_VERSION,
		'name'        => 'DoS Shot and a Beer Home',
		'description' => 'The shotandabeer.com homepage: restaurant, cuisine and dish search, category cards and latest reviews.',
	)
) )->boot();

DOS_SAAB\Search::hooks();
DOS_SAAB\Home::hooks();
DOS_SAAB\Admin::hooks();
