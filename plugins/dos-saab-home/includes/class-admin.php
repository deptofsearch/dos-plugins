<?php
/**
 * Settings → SAAB Home, and the card photo field on Posts → Categories.
 *
 * @package DOS_SAAB
 */

namespace DOS_SAAB;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PAGE = 'dos-saab-home';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'category_edit_form_fields', array( __CLASS__, 'term_field' ) );
		add_action( 'edited_category', array( __CLASS__, 'save_term' ) );
		add_action( 'admin_post_saab_home_rebuild', array( __CLASS__, 'rebuild' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DOS_SAAB_HOME_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'dos-saab-home' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_options_page( __( 'SAAB Home', 'dos-saab-home' ), __( 'SAAB Home', 'dos-saab-home' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function register() {
		register_setting(
			self::PAGE,
			Home::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Home::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$def = Home::defaults();
		return array(
			'on'      => ! empty( $in['on'] ),
			'eyebrow' => sanitize_text_field( $in['eyebrow'] ?? $def['eyebrow'] ),
			'h1'      => sanitize_text_field( $in['h1'] ?? '' ) ?: $def['h1'],
			'intro'   => sanitize_textarea_field( $in['intro'] ?? $def['intro'] ),
			'cats'    => implode( ', ', array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) ( $in['cats'] ?? '' ) ) ) ) ),
			'cats_shown' => max( 0, min( 60, (int) ( $in['cats_shown'] ?? $def['cats_shown'] ) ) ),
			'latest'  => max( 0, min( 24, (int) ( $in['latest'] ?? $def['latest'] ) ) ),
			'cities'  => max( 0, min( 30, (int) ( $in['cities'] ?? $def['cities'] ) ) ),
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Home::settings();
		$name = Home::OPTION;
		$tax  = Search::groups();
		?>
<div class="wrap">
	<h1><?php esc_html_e( 'Shot and a Beer homepage', 'dos-saab-home' ); ?></h1>
	<p><?php esc_html_e( 'While switched on, the front page shows the search, category cards, latest reviews and city links instead of its own content. The page itself is never edited: switch this off and it is back as it was.', 'dos-saab-home' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( Home::preview_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the new homepage', 'dos-saab-home' ); ?></a>
		<?php esc_html_e( 'Only administrators see the preview while the setting is off.', 'dos-saab-home' ); ?></p>
	<form method="post" action="options.php">
		<?php settings_fields( self::PAGE ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><?php esc_html_e( 'Homepage takeover', 'dos-saab-home' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[on]" value="1" <?php checked( $s['on'] ); ?>> <?php esc_html_e( 'Show the new homepage to everyone', 'dos-saab-home' ); ?></label></td></tr>
			<tr><th scope="row"><label for="saab-eyebrow"><?php esc_html_e( 'Line above the heading', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-eyebrow" class="regular-text" name="<?php echo esc_attr( $name ); ?>[eyebrow]" value="<?php echo esc_attr( $s['eyebrow'] ); ?>"></td></tr>
			<tr><th scope="row"><label for="saab-h1"><?php esc_html_e( 'Heading (H1)', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-h1" class="regular-text" name="<?php echo esc_attr( $name ); ?>[h1]" value="<?php echo esc_attr( $s['h1'] ); ?>"></td></tr>
			<tr><th scope="row"><label for="saab-intro"><?php esc_html_e( 'Intro', 'dos-saab-home' ); ?></label></th>
				<td><textarea id="saab-intro" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[intro]"><?php echo esc_textarea( $s['intro'] ); ?></textarea></td></tr>
			<tr><th scope="row"><label for="saab-cats"><?php esc_html_e( 'Category cards', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-cats" class="large-text" name="<?php echo esc_attr( $name ); ?>[cats]" value="<?php echo esc_attr( $s['cats'] ); ?>" placeholder="mexican-cuisine-in-tri-cities, road-trips">
				<p class="description"><?php esc_html_e( 'Category slugs, comma-separated, in the order the cards appear. Empty: every category with a published review, most reviews first. A card uses the photo set on its category (Posts → Categories), else the newest review\'s featured image.', 'dos-saab-home' ); ?></p></td></tr>
			<tr><th scope="row"><label for="saab-cats-shown"><?php esc_html_e( 'Category cards before "Show all"', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-cats-shown" type="number" min="0" max="60" class="small-text" name="<?php echo esc_attr( $name ); ?>[cats_shown]" value="<?php echo esc_attr( (string) $s['cats_shown'] ); ?>">
				<p class="description"><?php esc_html_e( 'The rest fold behind a "Show all categories" button. 0 shows every card with no button.', 'dos-saab-home' ); ?></p></td></tr>
			<tr><th scope="row"><label for="saab-latest"><?php esc_html_e( 'Latest reviews shown', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-latest" type="number" min="0" max="24" class="small-text" name="<?php echo esc_attr( $name ); ?>[latest]" value="<?php echo esc_attr( (string) $s['latest'] ); ?>"></td></tr>
			<tr><th scope="row"><label for="saab-cities"><?php esc_html_e( 'City links shown', 'dos-saab-home' ); ?></label></th>
				<td><input id="saab-cities" type="number" min="0" max="30" class="small-text" name="<?php echo esc_attr( $name ); ?>[cities]" value="<?php echo esc_attr( (string) $s['cities'] ); ?>"></td></tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<h2><?php esc_html_e( 'Search index', 'dos-saab-home' ); ?></h2>
	<p><?php
		/* translators: 1: number of entries, 2: list of groups */
		printf( esc_html__( '%1$d entries from: %2$s. Rebuilt automatically when a review, a restaurant page or one of their tags changes, and after every plugin update.', 'dos-saab-home' ), count( Search::full_index() ), esc_html( $tax ? implode( ', ', $tax ) : __( 'none — the restaurant, cuisine, dish and city taxonomies were not found', 'dos-saab-home' ) ) );
	?></p>
	<?php if ( isset( $_GET['saab_rebuilt'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
		<div class="notice notice-success inline"><p><?php
			/* translators: %d: number of entries */
			printf( esc_html__( 'Search index rebuilt: %d entries.', 'dos-saab-home' ), absint( $_GET['saab_rebuilt'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		?></p></div>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="saab_home_rebuild">
		<?php wp_nonce_field( 'saab_home_rebuild' ); ?>
		<?php submit_button( __( 'Rebuild search index now', 'dos-saab-home' ), 'secondary', 'submit', false ); ?>
		<p class="description"><?php esc_html_e( 'Use it if a search result still shows something you have already changed. Clear the page cache afterwards.', 'dos-saab-home' ); ?></p>
	</form>
</div>
		<?php
	}

	/** "Rebuild search index now": drop the cached index and build it again, so a change shows without waiting. */
	public static function rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'dos-saab-home' ), 403 );
		}
		check_admin_referer( 'saab_home_rebuild' );
		Search::flush();
		$n = count( Search::full_index() );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'saab_rebuilt' => $n ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function term_field( $term ) {
		$id = (int) get_term_meta( $term->term_id, Home::IMAGE_KEY, true );
		wp_nonce_field( 'saab_card_image', 'saab_card_image_nonce' );
		?>
<tr class="form-field">
	<th scope="row"><label for="saab-card-image"><?php esc_html_e( 'Homepage card photo', 'dos-saab-home' ); ?></label></th>
	<td><input id="saab-card-image" name="saab_card_image" type="number" min="0" value="<?php echo $id ? esc_attr( (string) $id ) : ''; ?>" style="width: 10em">
		<?php echo $id ? wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'display:block;margin-top:8px' ) ) : ''; ?>
		<p class="description"><?php esc_html_e( 'Media Library attachment ID (open the image in Media; the ID is in its address). Empty: the newest review\'s featured image.', 'dos-saab-home' ); ?></p></td>
</tr>
		<?php
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['saab_card_image_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['saab_card_image_nonce'] ), 'saab_card_image' ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$id = isset( $_POST['saab_card_image'] ) ? absint( $_POST['saab_card_image'] ) : 0;
		if ( $id ) {
			update_term_meta( $term_id, Home::IMAGE_KEY, $id );
		} else {
			delete_term_meta( $term_id, Home::IMAGE_KEY );
		}
	}
}
