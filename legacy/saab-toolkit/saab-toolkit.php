<?php
/**
 * Plugin Name: SAAB Toolkit
 * Description: SEO tags and responsive image markup for shotandabeer.com. Modules are off by default and enabled one at a time.
 * Version:     0.1.0
 * Author:      Shot And A Beer
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SAAB_TOOLKIT_VERSION', '0.1.0' );
define( 'SAAB_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );

class SAAB_Toolkit {

	/**
	 * Every module ships disabled. Activating this plugin changes nothing
	 * until a box is ticked — which matters when the only test environment
	 * is the live site.
	 */
	private static $modules = array(
		'seo'    => array(
			'class' => 'SAAB_SEO',
			'file'  => 'class-saab-seo.php',
			'label' => 'SEO tags',
			'blurb' => 'Meta descriptions, Open Graph and Twitter Cards, canonicals on every page type, Organization/WebSite/BlogPosting schema. Steps aside if Yoast, Rank Math or SEOPress is installed.',
		),
		'images' => array(
			'class' => 'SAAB_Images',
			'file'  => 'class-saab-images.php',
			'label' => 'Responsive image markup',
			'blurb' => 'Adds srcset and sizes to images the theme renders bare, and swaps oversized files for the smallest registered size that covers the slot. Does not touch image files — compression and WebP stay with the host optimizer. Starts in dry-run mode.',
		),
	);

	public static function boot() {
		foreach ( self::$modules as $key => $module ) {
			if ( self::is_enabled( $key ) ) {
				require_once SAAB_TOOLKIT_DIR . 'includes/' . $module['file'];
			}
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
			if ( ! self::is_enabled( $key ) || ! class_exists( $module['class'] ) ) {
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
		add_options_page( 'SAAB Toolkit', 'SAAB Toolkit', 'manage_options', 'saab-toolkit', array( __CLASS__, 'render_settings_page' ) );
	}

	public static function render_settings_page() {
		?>
		<div class="wrap">
			<h1>SAAB Toolkit <span style="font-size:13px;color:#666">v<?php echo esc_html( SAAB_TOOLKIT_VERSION ); ?></span></h1>

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
