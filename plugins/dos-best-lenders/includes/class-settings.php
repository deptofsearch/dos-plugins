<?php
/**
 * Settings → DoS Best Lenders: GA4 Measurement ID, public contact email, header logo switch, default social image.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'blnm_settings';
	const GROUP  = 'blnm_settings_group';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// Register on init (not only admin_init) so the setting also exists for the REST /wp/v2/settings route.
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_head', array( __CLASS__, 'ga4' ), 20 );
		// Hostinger's persistent object cache can keep serving a stale "option missing" copy after a save,
		// so drop the cached option entries whenever ours is written.
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache() {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( self::OPTION, 'options' );
	}

	public static function get( $key ) {
		$o = get_option( self::OPTION, array() );
		if ( ! is_array( $o ) || ! isset( $o[ $key ] ) || '' === (string) $o[ $key ] ) {
			// Fall back to the database row when the object cache disagrees with it.
			$o = self::db_value();
		}
		return is_array( $o ) && isset( $o[ $key ] ) ? (string) $o[ $key ] : '';
	}

	/** The stored option read straight from the options table, bypassing every cache. Memoized per request. */
	public static function db_value() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		global $wpdb;
		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::OPTION ) );
		$val  = null === $raw ? array() : maybe_unserialize( $raw );
		$memo = is_array( $val ) ? $val : array();
		return $memo;
	}

	public static function contact_email() {
		return self::get( 'contact_email' );
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$ga  = strtoupper( trim( (string) ( $in['ga4_id'] ?? '' ) ) );
		$old = get_option( self::OPTION, array() );
		// A save that omits the logo flag (REST, older forms) keeps what was stored; default is on.
		$logo = $in['brand_logo'] ?? ( is_array( $old ) && isset( $old['brand_logo'] ) ? $old['brand_logo'] : '1' );
		$out  = array(
			'ga4_id'        => preg_match( '/^G-[A-Z0-9]{4,20}$/', $ga ) ? $ga : '',
			'contact_email' => sanitize_email( (string) ( $in['contact_email'] ?? '' ) ),
			'brand_logo'    => in_array( (string) $logo, array( '0', 'false', '' ), true ) ? '0' : '1',
			// A save that omits it (REST, older forms) keeps what was stored.
			'social_image_id' => (string) absint( $in['social_image_id'] ?? ( is_array( $old ) ? ( $old['social_image_id'] ?? 0 ) : 0 ) ),
		);
		if ( '0' === $out['social_image_id'] ) {
			$out['social_image_id'] = '';
		}
		return $out;
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
				'show_in_rest'      => array(
					'name'   => 'blnm_settings',
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'ga4_id'        => array( 'type' => 'string' ),
							'contact_email' => array( 'type' => 'string' ),
							'brand_logo'    => array( 'type' => 'string' ),
							'social_image_id' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	public static function menu() {
		add_options_page( __( 'DoS Best Lenders', 'dos-best-lenders' ), __( 'DoS Best Lenders', 'dos-best-lenders' ), 'manage_options', 'dos-best-lenders', array( __CLASS__, 'page' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
<div class="wrap">
<h1><?php esc_html_e( 'DoS Best Lenders', 'dos-best-lenders' ); ?></h1>
<form method="post" action="options.php">
<?php settings_fields( self::GROUP ); ?>
<table class="form-table" role="presentation">
<tr><th scope="row"><label for="blnm-ga4"><?php esc_html_e( 'GA4 Measurement ID', 'dos-best-lenders' ); ?></label></th>
<td><input id="blnm-ga4" class="regular-text" type="text" name="<?php echo esc_attr( self::OPTION ); ?>[ga4_id]" value="<?php echo esc_attr( self::get( 'ga4_id' ) ); ?>" placeholder="G-XXXXXXXXXX">
<p class="description"><?php esc_html_e( 'The gtag snippet is added to every page for visitors. It is skipped for logged-in administrators. Leave blank to turn it off.', 'dos-best-lenders' ); ?></p></td></tr>
<tr><th scope="row"><label for="blnm-contact"><?php esc_html_e( 'Contact email', 'dos-best-lenders' ); ?></label></th>
<td><input id="blnm-contact" class="regular-text" type="email" name="<?php echo esc_attr( self::OPTION ); ?>[contact_email]" value="<?php echo esc_attr( self::get( 'contact_email' ) ); ?>">
<p class="description"><?php esc_html_e( 'Shown by the [blnm_contact] shortcode as an obfuscated mailto link.', 'dos-best-lenders' ); ?></p></td></tr>
<tr><th scope="row"><?php esc_html_e( 'Header logo', 'dos-best-lenders' ); ?></th>
<td><input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[brand_logo]" value="0">
<label for="blnm-logo"><input id="blnm-logo" type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[brand_logo]" value="1" <?php checked( '0' !== self::get( 'brand_logo' ) ); ?>> <?php esc_html_e( 'Use brand logo in header', 'dos-best-lenders' ); ?></label>
<p class="description"><?php esc_html_e( 'Replaces the Themify header site title or logo with the Best Lenders Near Me horizontal lockup. Turn off to use the theme\'s own logo.', 'dos-best-lenders' ); ?></p></td></tr>
<tr><th scope="row"><label for="blnm-social"><?php esc_html_e( 'Default social image', 'dos-best-lenders' ); ?></label></th>
<td><input id="blnm-social" class="small-text" type="number" min="0" name="<?php echo esc_attr( self::OPTION ); ?>[social_image_id]" value="<?php echo esc_attr( self::get( 'social_image_id' ) ); ?>">
<p class="description"><?php esc_html_e( 'Media library ID of the image used for og:image and twitter:image when a page has no featured image. 1200 x 630 works best. Skipped when an SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) is active.', 'dos-best-lenders' ); ?></p></td></tr>
</table>
<?php
$db = self::db_value();
printf(
	'<p class="description">%s</p>',
	esc_html(
		sprintf(
			/* translators: 1: GA4 status, 2: email status */
			__( 'Saved in the database right now: GA4 ID %1$s, contact email %2$s.', 'dos-best-lenders' ),
			empty( $db['ga4_id'] ) ? __( 'not set', 'dos-best-lenders' ) : $db['ga4_id'],
			empty( $db['contact_email'] ) ? __( 'not set', 'dos-best-lenders' ) : $db['contact_email']
		)
	)
);
submit_button();
?>
</form>
<?php Cache::render_button(); ?>
</div>
		<?php
	}

	/** Standard gtag.js snippet. Not for logged-in admins, so Ryan's own visits don't count. */
	public static function ga4() {
		$id = self::get( 'ga4_id' );
		if ( '' === $id || is_admin() || current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo esc_js( $id ); ?>');
</script>
		<?php
	}
}
