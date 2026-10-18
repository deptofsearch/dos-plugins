<?php
/**
 * Remote self-update: GET/POST /osn/v1/self-update.
 *
 * Core's REST API can activate and install plugins but cannot update one, so an update released on GitHub otherwise
 * waits for someone to open Dashboard -> Updates. This lets the n8n side (or anyone holding an administrator's
 * application password) check for and install this plugin's newest release.
 *
 * GET reports the installed and latest versions. POST installs the latest release when it is newer; it always asks
 * GitHub afresh, so a release published minutes ago is seen. Only this plugin can be updated here, and only from the
 * package the shared updater already offers WordPress, so the route adds no new source of code.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Self_Update {

	/** @var \DOS_GitHub_Updater|null */
	private static $updater = null;

	public static function hooks( $updater ) {
		self::$updater = $updater;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/self-update',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_status' ),
					'permission_callback' => array( __CLASS__, 'can_update' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_update' ),
					'permission_callback' => array( __CLASS__, 'can_update' ),
				),
			)
		);
	}

	/** The same capability the Updates screen asks for, plus manage_options like the plugin's other admin routes. */
	public static function can_update() {
		return current_user_can( 'update_plugins' ) && current_user_can( 'manage_options' );
	}

	private static function basename() {
		return plugin_basename( PLUGIN_FILE );
	}

	public static function rest_status( \WP_REST_Request $request ) {
		if ( ! self::$updater ) {
			return new \WP_Error( 'osn_no_updater', 'The GitHub updater is not loaded.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( self::$updater->status( (bool) $request->get_param( 'force' ) ) );
	}

	public static function rest_update() {
		if ( ! self::$updater ) {
			return new \WP_Error( 'osn_no_updater', 'The GitHub updater is not loaded.', array( 'status' => 500 ) );
		}

		$status = self::$updater->status( true );
		if ( empty( $status['update'] ) ) {
			return rest_ensure_response( array( 'updated' => false, 'reason' => 'up to date' ) + $status );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Rebuild core's update list so it carries the release found above; the upgrader installs whatever package
		// that list names for this plugin.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$basename = self::basename();
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array( $basename ) );

		$errors = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return new \WP_Error( 'osn_update_failed', implode( ' ', $errors->get_error_messages() ), array( 'status' => 500, 'log' => $skin->get_upgrade_messages() ) );
		}
		if ( ! is_array( $result ) || empty( $result[ $basename ] ) || is_wp_error( $result[ $basename ] ) ) {
			$msg = is_array( $result ) && isset( $result[ $basename ] ) && is_wp_error( $result[ $basename ] ) ? $result[ $basename ]->get_error_message() : 'The upgrader did not install the package.';
			return new \WP_Error( 'osn_update_failed', $msg, array( 'status' => 500, 'log' => $skin->get_upgrade_messages() ) );
		}

		// This request is still running the old code, so read the new version from the file on disk.
		$data = get_plugin_data( PLUGIN_FILE, false, false );

		return rest_ensure_response(
			array(
				'updated'   => true,
				'from'      => $status['installed'],
				'to'        => isset( $data['Version'] ) ? $data['Version'] : $status['latest'],
				'active'    => is_plugin_active( $basename ),
				'log'       => $skin->get_upgrade_messages(),
			)
		);
	}
}
