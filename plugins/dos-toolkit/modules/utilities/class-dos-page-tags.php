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

	/** Rows shown at once. The list scrolls; beyond this it paginates. */
	const PER_PAGE = 100;

	/** Titles pulled from the database in one go before filtering. */
	const MAX_CANDIDATES = 5000;

	/**
	 * Find pages by title, either by plain text or by regular expression.
	 *
	 * Titles are fetched in one query and matched in PHP rather than handed
	 * to MySQL's own REGEXP: its syntax is not PCRE, so a pattern that works
	 * here would behave differently there, and a bad pattern would be a
	 * database error rather than a message.
	 *
	 * @return array rows, total, pages, error
	 */
	public static function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'regex'  => false,
				'status' => 'any',
				'page'   => 1,
			)
		);

		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );

		if ( in_array( $args['status'], $statuses, true ) ) {
			$statuses = array( $args['status'] );
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status FROM {$wpdb->posts}
				WHERE post_type = 'page' AND post_status IN ({$placeholders})
				ORDER BY post_title ASC
				LIMIT %d",
				array_merge( $statuses, array( self::MAX_CANDIDATES ) )
			),
			ARRAY_A
		);

		$candidates = is_array( $candidates ) ? $candidates : array();
		$search     = trim( (string) $args['search'] );
		$error      = '';

		if ( '' !== $search ) {
			if ( $args['regex'] ) {
				$pattern = '/' . str_replace( '/', '\/', $search ) . '/iu';

				// Validate before use: an invalid pattern is a warning on
				// every row otherwise, and no results with no explanation.
				set_error_handler( function () { return true; } );
				$valid = false !== @preg_match( $pattern, '' );
				restore_error_handler();

				if ( ! $valid ) {
					return array(
						'rows'  => array(),
						'total' => 0,
						'pages' => 0,
						'error' => __( 'That is not a valid regular expression.', 'dos-toolkit' ),
					);
				}

				$candidates = array_values( array_filter( $candidates, function ( $row ) use ( $pattern ) {
					return 1 === preg_match( $pattern, (string) $row['post_title'] );
				} ) );
			} else {
				$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

				$candidates = array_values( array_filter( $candidates, function ( $row ) use ( $needle ) {
					$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $row['post_title'] ) : strtolower( (string) $row['post_title'] );

					return false !== strpos( $title, $needle );
				} ) );
			}
		}

		$total = count( $candidates );
		$page  = max( 1, (int) $args['page'] );
		$rows  = array_slice( $candidates, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		return array(
			'rows'  => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / self::PER_PAGE ),
			'error' => $error,
			'ids'   => wp_list_pluck( $candidates, 'ID' ),
		);
	}

	/**
	 * Tags for a set of pages, in one query rather than one per row.
	 */
	public static function tags_for( array $ids ) {
		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( ! $ids ) {
			return array();
		}

		$terms = wp_get_object_terms( $ids, 'post_tag', array( 'fields' => 'all_with_object_id' ) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$map = array();

		foreach ( $terms as $term ) {
			$map[ (int) $term->object_id ][] = $term->name;
		}

		return $map;
	}

	public static function handle_apply() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit pages.', 'dos-toolkit' ), 403 );
		}

		check_admin_referer( self::ACTION );

		// "Everything that matched" is re-run here rather than trusting a
		// list of several thousand IDs posted from a browser.
		if ( ! empty( $_POST['apply_all'] ) ) {
			$found = self::search(
				array(
					'search' => isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '',
					'regex'  => ! empty( $_POST['regex'] ),
					'status' => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'any',
				)
			);

			$page_ids = isset( $found['ids'] ) ? array_map( 'absint', $found['ids'] ) : array();
		} else {
			$page_ids = isset( $_POST['page_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['page_ids'] ) ) : array();
		}
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
