<?php
/**
 * Move between posts without going back to the list.
 *
 * Editing a run of pages means the same four clicks between each one: update,
 * back to the list, find your place, open the next. The arrows remove three of
 * them.
 *
 * The list is whatever list you arrived from, filters and sorting included.
 * Recomputing "the next page" from scratch would give the next by date, which
 * is rarely the next in the set somebody is actually working through.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Editor_Nav {

	const META = '_dos_editor_list';

	/**
	 * Most posts remembered from one list.
	 *
	 * Enough for any list a person is working through by hand, and small
	 * enough that holding the IDs in user meta costs nothing.
	 */
	const LIMIT = 500;

	public static function is_enabled() {
		$value = DOS_Settings::get( 'utilities_editor_nav', null );

		return null === $value ? true : (bool) $value;
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'load-edit.php', array( __CLASS__, 'capture' ), 20 );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'render' ) );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect_after_save' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Remembering the list
	 * ------------------------------------------------------------------- */

	/**
	 * Store the list being viewed, in its current order.
	 *
	 * Runs on the list screen itself, where the filters, search and sorting
	 * are already resolved into a query — reconstructing them later from a
	 * URL would mean reimplementing whatever core and other plugins did to
	 * that query.
	 */
	public static function capture() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}

		global $wp_query;

		if ( ! $wp_query instanceof WP_Query ) {
			return;
		}

		$args = $wp_query->query_vars;

		// Everything except which page of it we happen to be looking at.
		unset( $args['paged'], $args['offset'], $args['posts_per_page'], $args['fields'], $args['nopaging'] );

		$args['post_type']       = $screen->post_type ? $screen->post_type : 'post';
		$args['posts_per_page']  = self::LIMIT;
		$args['fields']          = 'ids';
		$args['no_found_rows']   = true;
		$args['cache_results']   = false;

		$ids = get_posts( $args );

		if ( ! $ids ) {
			return;
		}

		update_user_meta(
			get_current_user_id(),
			self::META,
			array(
				'ids'       => array_map( 'intval', $ids ),
				'post_type' => $args['post_type'],
				'url'       => esc_url_raw( admin_url( 'edit.php' ) . ( isset( $_SERVER['QUERY_STRING'] ) ? '?' . wp_unslash( $_SERVER['QUERY_STRING'] ) : '' ) ),
				'filtered'  => self::is_filtered(),
				'captured'  => time(),
			)
		);
	}

	/**
	 * Whether the list was narrowed, so the arrows can say so. Following a
	 * filtered list is the case where it matters that these are not simply
	 * the next post by date.
	 */
	private static function is_filtered() {
		foreach ( array( 's', 'post_status', 'author', 'cat', 'tag', 'm', 'orderby', 'dos_tag', 'dos_featured_filter' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function stored() {
		$list = get_user_meta( get_current_user_id(), self::META, true );

		if ( ! is_array( $list ) || empty( $list['ids'] ) ) {
			return null;
		}

		return $list;
	}

	/* ---------------------------------------------------------------------
	 * Position
	 * ------------------------------------------------------------------- */

	/**
	 * @return array|null position, total, previous, next, url, filtered
	 */
	public static function place( $post_id ) {
		$list = self::stored();

		if ( ! $list ) {
			return null;
		}

		$index = array_search( (int) $post_id, $list['ids'], true );

		if ( false === $index ) {
			return null;
		}

		return array(
			'position' => $index + 1,
			'total'    => count( $list['ids'] ),
			'previous' => $index > 0 ? $list['ids'][ $index - 1 ] : 0,
			'next'     => isset( $list['ids'][ $index + 1 ] ) ? $list['ids'][ $index + 1 ] : 0,
			'url'      => isset( $list['url'] ) ? $list['url'] : admin_url( 'edit.php' ),
			'filtered' => ! empty( $list['filtered'] ),
		);
	}

	public static function edit_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	}

	/* ---------------------------------------------------------------------
	 * In the editor
	 * ------------------------------------------------------------------- */

	public static function render( $post = null ) {
		$post = $post ? $post : get_post();

		if ( ! $post ) {
			return;
		}

		$place = self::place( $post->ID );

		if ( ! $place ) {
			return;
		}

		?>
		<div class="misc-pub-section dos-editor-nav">
			<span class="dos-nav-label">
				<?php
				printf(
					/* translators: 1: position in the list, 2: number of items */
					esc_html__( '%1$d of %2$d', 'dos-toolkit' ),
					(int) $place['position'],
					(int) $place['total']
				);
				?>
				<?php if ( $place['filtered'] ) : ?>
					<span class="description"><?php esc_html_e( 'in your filtered list', 'dos-toolkit' ); ?></span>
				<?php endif; ?>
			</span>

			<span class="dos-nav-buttons">
				<?php if ( $place['previous'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::edit_url( $place['previous'] ) ); ?>" title="<?php esc_attr_e( 'Previous', 'dos-toolkit' ); ?>">&larr;</a>
				<?php else : ?>
					<span class="button disabled">&larr;</span>
				<?php endif; ?>

				<?php if ( $place['next'] ) : ?>
					<a class="button" href="<?php echo esc_url( self::edit_url( $place['next'] ) ); ?>" title="<?php esc_attr_e( 'Next', 'dos-toolkit' ); ?>">&rarr;</a>
				<?php else : ?>
					<span class="button disabled">&rarr;</span>
				<?php endif; ?>
			</span>

			<?php if ( $place['next'] ) : ?>
				<p class="dos-nav-save">
					<button type="submit" name="dos_save_next" value="1" class="button">
						<?php esc_html_e( 'Update and open the next', 'dos-toolkit' ); ?>
					</button>
				</p>
			<?php endif; ?>

			<p class="dos-nav-back">
				<a href="<?php echo esc_url( $place['url'] ); ?>"><?php esc_html_e( 'Back to the list', 'dos-toolkit' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save, then open the next one instead of returning here.
	 *
	 * WordPress has already checked the nonce and the capability by the time
	 * a post is saved; this only chooses where to land afterwards.
	 */
	public static function redirect_after_save( $location, $post_id ) {
		if ( empty( $_POST['dos_save_next'] ) ) {
			return $location;
		}

		$place = self::place( $post_id );

		if ( ! $place || ! $place['next'] ) {
			return $location;
		}

		if ( ! current_user_can( 'edit_post', $place['next'] ) ) {
			return $location;
		}

		return add_query_arg( 'dos_saved', '1', self::edit_url( $place['next'] ) );
	}
}
