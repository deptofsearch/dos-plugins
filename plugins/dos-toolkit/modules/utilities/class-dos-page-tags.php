<?php
/**
 * Tag support for Pages.
 *
 * WordPress attaches post_tag to posts only. Sites that use Pages as their
 * main content type — which is most brochure and local-service sites — end up
 * with no way to group them. This attaches the existing taxonomy rather than
 * registering a parallel one, so the tags are the same tags, and archives,
 * related-content queries and the SEO module's term handling all work
 * unchanged.
 *
 * Ported from Page Tags Tools.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Page_Tags {

	const ACTION = 'dos_page_tags_apply';

	public static function is_enabled() {
		return (bool) DOS_Settings::get( 'utilities_page_tags', 0 );
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register' ), 11 );

		// No column of our own: attaching post_tag to pages makes WordPress
		// add its own Tags column, and adding a second produced two columns
		// with the same heading. The filter below is the part core does not
		// provide for a non-hierarchical taxonomy.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_apply' ) );
	}

	public static function register() {
		register_taxonomy_for_object_type( 'post_tag', 'page' );
	}

	public static function render_filter( $post_type ) {
		if ( 'page' !== $post_type ) {
			return;
		}

		$terms = self::page_tags();

		if ( ! $terms ) {
			return;
		}

		$current = isset( $_GET['dos_tag'] ) ? absint( $_GET['dos_tag'] ) : 0;
		?>
		<select name="dos_tag">
			<option value="0"><?php esc_html_e( 'All tags', 'dos-toolkit' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo (int) $term->term_id; ?>" <?php selected( $current, $term->term_id ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'page' !== $query->get( 'post_type' ) ) {
			return;
		}

		$tag = isset( $_GET['dos_tag'] ) ? absint( $_GET['dos_tag'] ) : 0;

		if ( ! $tag ) {
			return;
		}

		$query->set( 'tax_query', array( array(
			'taxonomy' => 'post_tag',
			'field'    => 'term_id',
			'terms'    => $tag,
		) ) );
	}

	/**
	 * Tags actually in use on pages, rather than every tag on the site.
	 */
	public static function page_tags() {
		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$used = array();

		foreach ( $terms as $term ) {
			$pages = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $term->term_id ) ),
			) );

			if ( $pages ) {
				$used[] = $term;
			}
		}

		return $used;
	}

	public static function pages( $search = '' ) {
		$args = array(
			'post_type'              => 'page',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page'         => 300,
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);

		if ( '' !== trim( (string) $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}

		return get_posts( $args );
	}

	public static function handle_apply() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit pages.', 'dos-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$page_ids = isset( $_POST['page_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['page_ids'] ) ) : array();
		$tag      = isset( $_POST['tag'] ) ? sanitize_text_field( wp_unslash( $_POST['tag'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

		$applied = 0;

		foreach ( $page_ids as $page_id ) {
			// Per-page capability check: edit_pages is not the same as being
			// allowed to edit this particular page.
			if ( ! $page_id || 'page' !== get_post_type( $page_id ) || ! current_user_can( 'edit_page', $page_id ) ) {
				continue;
			}

			$value = $term_id ? array( $term_id ) : $tag;

			if ( ! $value ) {
				continue;
			}

			// append = true: tagging must never drop tags already applied.
			$result = wp_set_object_terms( $page_id, $value, 'post_tag', true );

			if ( ! is_wp_error( $result ) ) {
				$applied++;
			}
		}

		DOS_Log::add( 'utilities', 'page_tags_applied', sprintf( 'Tagged %d pages.', $applied ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'dos-utilities', 'dos_notice' => 'saved', 'dos_tagged' => $applied ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
