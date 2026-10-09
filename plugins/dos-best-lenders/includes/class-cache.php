<?php
/**
 * Page-cache purging. Bestlendersnearme.com sits behind a server-level LiteSpeed page cache (x-litespeed-cache),
 * so after a plugin update cached city pages keep serving old HTML with old ?ver assets. The plugin purges itself
 * once per version, and the settings page has a manual button.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Cache {

	const OPT_VERSION = 'blnm_purged_version';
	const OPT_PENDING = 'blnm_purge_pending';
	const ACTION      = 'blnm_clear_cache';
	const NOTICE_ARG  = 'blnm_cache_cleared';

	/** Hostinger's own cache plugin: hook names are not documented, so only fire the ones something listens on. */
	const HOSTINGER_HOOKS = array( 'hostinger_purge_cache', 'hostinger_clear_cache', 'hostinger_cache_flush' );

	public static function hooks() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_purge' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flag_after_upgrade' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Purge every page cache we can reach. Safe when none of them exist. */
	public static function purge_all() {
		// LiteSpeed Cache plugin API; a no-op without the plugin.
		do_action( 'litespeed_purge_all' );

		// The LiteSpeed server itself honors this response header, plugin or not.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
		}

		foreach ( self::HOSTINGER_HOOKS as $hook ) {
			if ( has_action( $hook ) ) {
				do_action( $hook );
			}
		}

		// Our own transients (city index, state hubs). The object cache is deliberately left alone.
		Rest::flush_index();
	}

	/** Purge once per plugin version, or when an upgrade flagged one. Runs on admin requests so the header can go out. */
	public static function maybe_purge() {
		$pending = (bool) get_option( self::OPT_PENDING, false );
		if ( ! $pending && VERSION === get_option( self::OPT_VERSION, '' ) ) {
			return false;
		}
		self::purge_all();
		update_option( self::OPT_VERSION, VERSION, false );
		delete_option( self::OPT_PENDING );
		return true;
	}

	/** After this plugin is updated, make the next admin request purge (the upgrade request may have sent headers). */
	public static function flag_after_upgrade( $upgrader, $extra ) {
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) || 'update' !== ( $extra['action'] ?? '' ) ) {
			return;
		}
		$ours    = plugin_basename( PLUGIN_FILE );
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		if ( in_array( $ours, $plugins, true ) ) {
			update_option( self::OPT_PENDING, 1, false );
		}
	}

	/** admin-post.php handler for the settings-page button. */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'dos-best-lenders' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		self::purge_all();
		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, '1', admin_url( 'options-general.php?page=dos-best-lenders' ) ) );
		exit;
	}

	public static function notice() {
		if ( empty( $_GET[ self::NOTICE_ARG ] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Page cache cleared', 'dos-best-lenders' ) . '</p></div>';
	}

	/** The button form, printed on the settings page (outside the options.php form). */
	public static function render_button() {
		?>
<h2><?php esc_html_e( 'Page cache', 'dos-best-lenders' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
<?php wp_nonce_field( self::ACTION ); ?>
<?php submit_button( __( 'Clear BLNM page cache', 'dos-best-lenders' ), 'secondary', 'submit', false ); ?>
<p class="description"><?php esc_html_e( 'Purges the LiteSpeed page cache (plugin and server) and this plugin\'s cached city index. It also runs automatically once after each plugin update.', 'dos-best-lenders' ); ?></p>
</form>
		<?php
	}
}
