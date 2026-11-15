<?php
/**
 * Plugin Name: DoS Tax Votes
 * Plugin URI:  https://github.com/deptofsearch/dos-plugins
 * Description: Elected or Appointed: how each Washington legislator and the governor voted on bills that raise or cut taxes. Shortcode [eoa_tax_votes].
 * Version:     0.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-tax-votes
 * Update URI:  https://github.com/deptofsearch/dos-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_TAX_VOTES_VERSION', '0.4.0' );
define( 'DOS_TAX_VOTES_DIR', __DIR__ );

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once __DIR__ . '/includes/class-dos-github-updater.php';
require_once __DIR__ . '/includes/render.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-tax-votes',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => DOS_TAX_VOTES_VERSION,
		'name'        => 'DoS Tax Votes',
		'description' => 'How Washington legislators and the governor voted on bills that raise or cut taxes, for Elected or Appointed.',
	)
) )->boot();

add_shortcode( 'eoa_tax_votes', 'dos_tax_votes_shortcode' );
add_action( 'wp_enqueue_scripts', 'dos_tax_votes_register_assets' );

function dos_tax_votes_register_assets() {
	$url = plugins_url( 'assets/', __FILE__ );
	wp_register_style( 'dos-tax-votes', $url . 'tax-votes.css', array(), DOS_TAX_VOTES_VERSION );
	wp_register_script( 'dos-tax-votes', $url . 'tax-votes.js', array(), DOS_TAX_VOTES_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}
