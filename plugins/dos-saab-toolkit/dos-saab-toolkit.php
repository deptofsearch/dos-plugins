<?php
/**
 * Plugin Name: DoS SAAB Toolkit
 * Plugin URI:  https://github.com/deptofsearch/dos-plugins
 * Description: SEO tags, responsive image markup and restaurant pages for shotandabeer.com. Modules are off by default and enabled one at a time.
 * Version:     0.8.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-saab-toolkit
 * Update URI:  https://github.com/deptofsearch/dos-plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This is the old "SAAB Toolkit" (saab-toolkit/saab-toolkit.php, 0.5.6) moved into dos-plugins under the dos- name.
 * Classes, option names and meta keys are unchanged, so module settings and restaurant data carry straight over.
 * Both declare SAAB_Toolkit: while the old copy is still active, this one stands down and says so, instead of a
 * "cannot redeclare class" fatal.
 *
 * WordPress loads plugins in alphabetical order, so "dos-saab-toolkit" comes before "saab-toolkit". Up to 0.7.2 the
 * only check was whether the old copy had already declared the class, which is true only during the click on
 * Activate (the plugin being activated loads last). On every request after that this file loaded first, the old one
 * then hit "cannot declare class SAAB_Toolkit", and the whole site went down. So the check also asks whether the old
 * copy is due to load later, and the class lives in includes/ so it is declared only once both checks pass.
 */

/**
 * Whether the old saab-toolkit is active and will be loaded. Matched on the file name, not the folder, because a
 * plugin folder is often renamed on its way onto a site. A listed plugin whose file is gone is skipped, as WordPress
 * skips it: renaming the old folder is how a site gets back up, and it must not keep this one stood down.
 */
function dos_saab_toolkit_old_copy_active() {
	$active = (array) get_option( 'active_plugins', array() );

	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	foreach ( $active as $plugin ) {
		if ( ! is_string( $plugin ) || 'saab-toolkit.php' !== basename( $plugin ) ) {
			continue;
		}
		if ( file_exists( trailingslashit( WP_PLUGIN_DIR ) . $plugin ) ) {
			return true;
		}
	}

	return false;
}

if ( class_exists( 'SAAB_Toolkit', false ) || dos_saab_toolkit_old_copy_active() ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'DoS SAAB Toolkit is waiting: deactivate the old "SAAB Toolkit" plugin and this one takes over with the same settings.', 'dos-saab-toolkit' ) . '</p></div>';
			}
		}
	);
	return;
}

define( 'SAAB_TOOLKIT_VERSION', '0.8.0' );
define( 'SAAB_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAAB_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

// Not behind is_admin(): WP-Cron and the REST API run the update check too.
require_once SAAB_TOOLKIT_DIR . 'includes/class-dos-github-updater.php';

( new DOS_GitHub_Updater(
	array(
		'slug'        => 'dos-saab-toolkit',
		'basename'    => plugin_basename( __FILE__ ),
		'version'     => SAAB_TOOLKIT_VERSION,
		'name'        => 'DoS SAAB Toolkit',
		'description' => 'SEO tags, responsive image markup and restaurant pages for shotandabeer.com.',
	)
) )->boot();

require_once SAAB_TOOLKIT_DIR . 'includes/class-saab-toolkit.php';

add_action( 'plugins_loaded', array( 'SAAB_Toolkit', 'boot' ) );
