<?php
/**
 * Plugin Name: DoS SAAB Toolkit
 * Plugin URI:  https://github.com/deptofsearch/dos-plugins
 * Description: SEO tags, responsive image markup and restaurant pages for shotandabeer.com. Modules are off by default and enabled one at a time.
 * Version:     0.7.2
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
 * The test is "SAAB_Toolkit exists and was declared by a different file", never class_exists() alone: PHP declares
 * this file's own SAAB_Toolkit before the file's first line runs (early binding), so class_exists() is always true
 * here. 0.6.0 used it alone and stood down on every request, which took the restaurant pages offline.
 */
if ( class_exists( 'SAAB_Toolkit', false ) && ( new ReflectionClass( 'SAAB_Toolkit' ) )->getFileName() !== __FILE__ ) {
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

define( 'SAAB_TOOLKIT_VERSION', '0.7.2' );
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

class SAAB_Toolkit {

	/**
	 * Every module ships disabled. Activating this plugin changes nothing
	 * until a box is ticked — which matters when the only test environment
	 * is the live site.
	 */
	private static $modules = array(
		'seo'         => array(
			'class' => 'SAAB_SEO',
			'file'  => 'class-saab-seo.php',
			'label' => 'SEO tags',
			'blurb' => 'Meta descriptions, Open Graph and Twitter Cards, canonicals on every page type, Organization/WebSite/BlogPosting schema. Steps aside if Yoast, Rank Math or SEOPress is installed.',
		),
		'images'      => array(
			'class' => 'SAAB_Images',
			'file'  => 'class-saab-images.php',
			'label' => 'Responsive image markup',
			'blurb' => 'Adds srcset and sizes to images the theme renders bare, and swaps oversized files for the smallest registered size that covers the slot. Does not touch image files — compression and WebP stay with the host optimizer. Starts in dry-run mode.',
		),
		'restaurants' => array(
			'class' => 'SAAB_Restaurants',
			'file'  => 'class-saab-restaurants.php',
			'label' => 'Restaurant pages',
			'blurb' => 'Adds a "Restaurants" post type with root-level permalinks, region/cuisine archives, synced Google data and menu, and a built front-end page. Meant to be filled by n8n over the REST API, with a "Ryan\'s Take" written by hand.',
		),
	);

	/**
	 * Modules whose class was already declared by something else — in
	 * practice the standalone SAAB SEO plugin, which ships the same
	 * SAAB_SEO class. Loading ours on top is a fatal "cannot redeclare",
	 * so a conflicted module is skipped and flagged on the settings page.
	 */
	private static $conflicts = array();

	public static function boot() {
		foreach ( self::$modules as $key => $module ) {
			if ( ! self::is_enabled( $key ) ) {
				continue;
			}
			if ( class_exists( $module['class'], false ) ) {
				self::$conflicts[ $key ] = true;
				continue;
			}
			require_once SAAB_TOOLKIT_DIR . 'includes/' . $module['file'];
		}

		// Modules start on `init`, not here: the SEO module checks for Yoast
		// and Rank Math, and those constants aren't defined yet at
		// plugins_loaded. Everything a module hooks (template_redirect,
		// admin_init, wp_head) fires later, so nothing is missed.
		add_action( 'init', array( __CLASS__, 'start_modules' ), 0 );

		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function start_modules() {
		foreach ( self::$modules as $key => $module ) {
			if ( ! self::is_enabled( $key ) || isset( self::$conflicts[ $key ] ) || ! class_exists( $module['class'] ) ) {
				continue;
			}

			call_user_func( array( $module['class'], 'init' ) );
		}
	}

	public static function is_enabled( $key ) {
		return (bool) get_option( 'saab_toolkit_enable_' . $key, 0 );
	}

	public static function register_settings() {
		foreach ( array_keys( self::$modules ) as $key ) {
			register_setting( 'saab_toolkit', 'saab_toolkit_enable_' . $key, array( 'sanitize_callback' => 'absint' ) );
		}
	}

	public static function add_settings_page() {
		add_options_page( 'DoS SAAB Toolkit', 'SAAB Toolkit', 'manage_options', 'saab-toolkit', array( __CLASS__, 'render_settings_page' ) );
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1>DoS SAAB Toolkit <span style="font-size:13px;color:#666">v<?php echo esc_html( SAAB_TOOLKIT_VERSION ); ?></span></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'saab_toolkit' ); ?>

				<h2>Modules</h2>
				<table class="form-table" role="presentation">
					<?php foreach ( self::$modules as $key => $module ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $module['label'] ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="saab_toolkit_enable_<?php echo esc_attr( $key ); ?>"
										value="1" <?php checked( self::is_enabled( $key ) ); ?>>
									Enabled
								</label>
								<p class="description" style="max-width:60em"><?php echo esc_html( $module['blurb'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php foreach ( self::$modules as $key => $module ) : ?>
					<?php
					if ( ! self::is_enabled( $key ) || ! class_exists( $module['class'] ) ) {
						continue;
					}
					if ( ! method_exists( $module['class'], 'render_settings_fields' ) ) {
						continue;
					}
					?>
					<h2><?php echo esc_html( $module['label'] ); ?></h2>
					<table class="form-table" role="presentation">
						<?php call_user_func( array( $module['class'], 'render_settings_fields' ) ); ?>
					</table>
				<?php endforeach; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'SAAB_Toolkit', 'boot' ) );
