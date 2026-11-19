<?php
/**
 * Plugin Name: DoS SAAB Food Trucks
 * Plugin URI:  https://github.com/deptofsearch/dos-plugins
 * Description: Shot and a Beer: Tri-Cities food truck parks with a city filter, a map, an at-a-glance table and a section per park. Shortcode [saab_food_truck_parks].
 * Version:     0.1.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-saab-food-trucks
 * Update URI:  https://github.com/deptofsearch/dos-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_SAAB_FT_VERSION', '0.1.1' );
define( 'DOS_SAAB_FT_DIR', __DIR__ );
define( 'DOS_SAAB_FT_LEAFLET', '1.9.4' );

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';
require_once __DIR__ . '/includes/render.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-saab-food-trucks',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => DOS_SAAB_FT_VERSION,
		'name'        => 'DoS SAAB Food Trucks',
		'description' => 'Tri-Cities food truck parks for Shot and a Beer: city filter, map, table and a section per park.',
	)
) )->boot();

add_shortcode( 'saab_food_truck_parks', 'dos_saab_ft_shortcode' );
add_action( 'wp_enqueue_scripts', 'dos_saab_ft_register_assets' );
add_action( DOS_SAAB_FT_GEOCODE_HOOK, 'dos_saab_ft_geocode_missing' );

function dos_saab_ft_register_assets() {
	$url     = plugins_url( 'assets/', __FILE__ );
	$leaflet = 'https://unpkg.com/leaflet@' . DOS_SAAB_FT_LEAFLET . '/dist/';
	wp_register_style( 'dos-saab-ft-leaflet', $leaflet . 'leaflet.css', array(), null );
	wp_register_script( 'dos-saab-ft-leaflet', $leaflet . 'leaflet.js', array(), null, array( 'in_footer' => true ) );
	wp_register_style( 'dos-saab-food-trucks', $url . 'food-trucks.css', array(), DOS_SAAB_FT_VERSION );
	wp_register_script( 'dos-saab-food-trucks', $url . 'food-trucks.js', array(), DOS_SAAB_FT_VERSION, array( 'in_footer' => true ) );
}
