<?php
/**
 * Module registry and boot sequence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Toolkit {

	/**
	 * Every module ships disabled. Activating the plugin on a live site
	 * changes nothing until a box is ticked.
	 *
	 * A module is "available" when its file is present on disk, so the shell
	 * can be deployed before every module is written.
	 */
	private static $modules = array(
		'seo' => array(
			'class' => 'DOS_Module_SEO',
			'file'  => 'seo/class-dos-seo.php',
			'label' => 'SEO',
			'blurb' => 'Meta descriptions, Open Graph and Twitter Cards, canonicals, and an Organization/WebSite/WebPage schema graph. Stands down automatically if Yoast, Rank Math or SEOPress is active.',
		),
		'ai' => array(
			'class' => 'DOS_Module_AI',
			'file'  => 'ai/class-dos-ai.php',
			'label' => 'AI Search',
			'blurb' => 'llms.txt, per-bot crawler policy for GPTBot / ClaudeBot / PerplexityBot / CCBot, FAQ and QAPage schema, author and entity markup, content freshness stamps.',
		),
		'images' => array(
			'class' => 'DOS_Module_Images',
			'file'  => 'images/class-dos-images.php',
			'label' => 'Images & Media',
			'blurb' => 'Responsive srcset/sizes on bare theme images, alt-text audit and bulk fill, oversized-file reports, media usage scan and cleanup. Never rewrites an image file.',
		),
		'utilities' => array(
			'class' => 'DOS_Module_Utilities',
			'file'  => 'utilities/class-dos-utilities.php',
			'label' => 'Utilities',
			'blurb' => 'Download any installed plugin as a ZIP, tag support for Pages, permalink and cache flush, settings export and import.',
		),
	);

	public static function boot() {
		DOS_Log::boot();
		DOS_Batch::boot();
		DOS_Admin::boot();
		DOS_Updater::boot();

		foreach ( self::$modules as $key => $module ) {
			if ( self::is_active( $key ) ) {
				require_once DOS_TOOLKIT_DIR . 'modules/' . $module['file'];
			}
		}

		// Modules start on `init`, not here. The SEO module checks for Yoast
		// and Rank Math, and their constants are not defined yet at
		// plugins_loaded. Everything a module hooks fires later, so nothing
		// is missed by waiting.
		add_action( 'init', array( __CLASS__, 'start_modules' ), 0 );
	}

	public static function start_modules() {
		foreach ( self::$modules as $key => $module ) {
			if ( ! self::is_active( $key ) || ! class_exists( $module['class'] ) ) {
				continue;
			}

			call_user_func( array( $module['class'], 'init' ) );
		}
	}

	public static function modules() {
		return self::$modules;
	}

	public static function module( $key ) {
		return isset( self::$modules[ $key ] ) ? self::$modules[ $key ] : null;
	}

	/**
	 * Present on disk.
	 */
	public static function is_available( $key ) {
		$module = self::module( $key );

		return $module && file_exists( DOS_TOOLKIT_DIR . 'modules/' . $module['file'] );
	}

	/**
	 * Ticked in the dashboard.
	 */
	public static function is_enabled( $key ) {
		return (bool) DOS_Settings::get( 'enable_' . $key, 0 );
	}

	/**
	 * Enabled and present. This is the one modules and menus should ask.
	 */
	public static function is_active( $key ) {
		return self::is_enabled( $key ) && self::is_available( $key );
	}

	public static function activate() {
		DOS_Log::install_table();
		DOS_Log::add( 'core', 'activated', 'DoS Toolkit ' . DOS_TOOLKIT_VERSION . ' activated.' );
	}

	public static function deactivate() {
		DOS_Log::add( 'core', 'deactivated', 'DoS Toolkit deactivated.' );
	}
}
