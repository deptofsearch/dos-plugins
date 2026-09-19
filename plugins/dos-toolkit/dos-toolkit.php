<?php
/**
 * Plugin Name: DoS Toolkit
 * Plugin URI:  https://github.com/ryantoddrose/dos-toolkit
 * Description: Department of Search standard toolkit. SEO, AI search, image and media management, and site utilities, shipped as modules that are disabled until you turn them on.
 * Version:     0.5.0
 * Author:      Department of Search
 * License:     GPL-2.0-or-later
 * Text Domain: dos-toolkit
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_TOOLKIT_VERSION', '0.5.0' );
define( 'DOS_TOOLKIT_FILE', __FILE__ );
define( 'DOS_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOS_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'DOS_TOOLKIT_BASENAME', plugin_basename( __FILE__ ) );

require_once DOS_TOOLKIT_DIR . 'includes/class-dos-settings.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-log.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-module.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-batch.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-admin.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-updater.php';
require_once DOS_TOOLKIT_DIR . 'includes/class-dos-toolkit.php';

register_activation_hook( __FILE__, array( 'DOS_Toolkit', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DOS_Toolkit', 'deactivate' ) );

DOS_Toolkit::boot();
