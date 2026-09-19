<?php
/**
 * Base class for modules. A module is expected to override init() and, if it
 * needs admin screens, pages().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class DOS_Module {

	/**
	 * Registry key, e.g. 'seo'. Must match the key in DOS_Toolkit::$modules.
	 */
	const KEY = '';

	/**
	 * Hook whatever the module does on the front end or in admin. Called on
	 * `init` priority 0, and only when the module is enabled.
	 */
	public static function init() {}

	/**
	 * Admin screens this module contributes, in menu order.
	 *
	 * Each entry: array(
	 *   'slug'     => 'dos-seo',            // unique
	 *   'title'    => 'SEO',                // submenu label
	 *   'callback' => array( __CLASS__, 'render_page' ),
	 * )
	 */
	public static function pages() {
		return array();
	}

	/**
	 * Batch jobs this module contributes. See DOS_Batch::register() for the
	 * expected shape. Called once per admin request.
	 */
	public static function jobs() {
		return array();
	}

	protected static function setting( $key, $default = null ) {
		return DOS_Settings::get( static::KEY . '_' . $key, $default );
	}

	protected static function save_setting( $key, $value ) {
		DOS_Settings::set( static::KEY . '_' . $key, $value );
	}

	protected static function log( $action, $message = '', $object_id = 0, $dry_run = false ) {
		DOS_Log::add( static::KEY, $action, $message, $object_id, $dry_run );
	}

	/**
	 * Guard for any admin handler. Dies on failure rather than returning, so
	 * callers do not have to remember to check.
	 */
	protected static function require_admin( $nonce_action, $nonce_name = '_wpnonce' ) {
		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dos-toolkit' ), 403 );
		}

		check_admin_referer( $nonce_action, $nonce_name );
	}
}
