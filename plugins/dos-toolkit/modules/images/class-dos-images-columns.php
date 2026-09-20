<?php
/**
 * Featured image at a glance, in the post and page lists.
 *
 * Ported from Media Usage Manager. A post with no featured image is invisible
 * in a list of fifty until you open each one, and it is the sort of omission
 * that goes unnoticed for months — the archive still renders, just with a
 * hole where the thumbnail should be.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Images_Columns {

	const COLUMN = 'dos_featured';
	const FILTER = 'dos_featured_filter';
	const USAGE  = 'dos_usage';

	public static function is_enabled() {
		$value = DOS_Settings::get( 'images_featured_column', null );

		// On by default: it reads data and changes nothing, and the absence
		// of a featured image is the thing people want to see.
		return null === $value ? true : (bool) $value;
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );

		// The media library's own view of the same data.
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_usage_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_usage_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'usage_field' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Usage, in the media library
	 * ------------------------------------------------------------------- */

	public static function add_usage_column( $columns ) {
		$columns[ self::USAGE ] = __( 'Used', 'dos-toolkit' );

		return $columns;
	}

	public static function render_usage_column( $column, $attachment_id ) {
		if ( self::USAGE !== $column ) {
			return;
		}

		if ( 0 !== strpos( (string) get_post_mime_type( $attachment_id ), 'image/' ) ) {
			echo '&mdash;';

			return;
		}

		echo wp_kses_post( self::status_badge( get_post_meta( $attachment_id, DOS_Images_Usage::META_STATUS, true ) ) );
		echo '<div class="dos-used-in">' . wp_kses_post( self::format_used_in( get_post_meta( $attachment_id, DOS_Images_Usage::META_USED_IN, true ), 2 ) ) . '</div>';
	}

	/**
	 * The same answer in the attachment details panel, where somebody is
	 * usually deciding whether they can safely change or delete the file.
	 */
	public static function usage_field( $fields, $post ) {
		if ( 0 !== strpos( (string) get_post_mime_type( $post ), 'image/' ) ) {
			return $fields;
		}

		$fields[ self::USAGE ] = array(
			'label' => __( 'Used', 'dos-toolkit' ),
			'input' => 'html',
			'html'  => self::status_badge( get_post_meta( $post->ID, DOS_Images_Usage::META_STATUS, true ) )
				. '<br>' . self::format_used_in( get_post_meta( $post->ID, DOS_Images_Usage::META_USED_IN, true ) ),
		);

		return $fields;
	}

	/**
	 * Three states, not two. An image nobody has scanned yet is not the same
	 * as one a scan found no reference to, and showing them alike would put
	 * unexamined images on a deletion list.
	 */
	public static function status_badge( $status ) {
		if ( 'used' === $status ) {
			return '<span class="dos-badge dos-badge-on">' . esc_html__( 'Used', 'dos-toolkit' ) . '</span>';
		}

		if ( 'unused' === $status ) {
			return '<span class="dos-badge dos-badge-missing">' . esc_html__( 'Unused', 'dos-toolkit' ) . '</span>';
		}

		return '<span class="dos-badge dos-badge-off">' . esc_html__( 'Not scanned', 'dos-toolkit' ) . '</span>';
	}

	/**
	 * Where an image is used, as links to the pages using it.
	 *
	 * @param int $limit Show at most this many, then a count of the rest.
	 */
	public static function format_used_in( $used_in, $limit = 0 ) {
		$used_in = is_array( $used_in ) ? $used_in : array();

		if ( ! $used_in ) {
			return '&mdash;';
		}

		$items = array();

		foreach ( $used_in as $usage ) {
			if ( $limit && count( $items ) >= $limit ) {
				break;
			}

			$post_id = isset( $usage['post_id'] ) ? absint( $usage['post_id'] ) : 0;

			if ( ! $post_id ) {
				continue;
			}

			$title = get_the_title( $post_id );
			$title = $title ? $title : sprintf( /* translators: %d: post ID */ __( 'Post #%d', 'dos-toolkit' ), $post_id );
			$type  = isset( $usage['type'] ) ? sanitize_key( $usage['type'] ) : 'content';

			$items[] = sprintf(
				'<a href="%s">%s</a> <span class="description">(%s)</span>',
				esc_url( (string) get_edit_post_link( $post_id ) ),
				esc_html( $title ),
				'featured' === $type ? esc_html__( 'featured', 'dos-toolkit' ) : esc_html__( 'in content', 'dos-toolkit' )
			);
		}

		if ( ! $items ) {
			return '&mdash;';
		}

		$more = count( $used_in ) - count( $items );

		if ( $more > 0 ) {
			$items[] = '<span class="description">' . esc_html( sprintf( /* translators: %d: number of further pages */ __( '+%d more', 'dos-toolkit' ), $more ) ) . '</span>';
		}

		return implode( '<br>', $items );
	}

	/**
	 * Types that can actually have a featured image. Asking about one on a
	 * type that does not support thumbnails would be noise.
	 */
	public static function post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}

			if ( post_type_supports( $type, 'thumbnail' ) ) {
				$types[] = $type;
			}
		}

		return apply_filters( 'dos_toolkit_featured_column_post_types', $types );
	}

	public static function register() {
		foreach ( self::post_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	public static function add_column( $columns ) {
		$out = array();

		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out[ self::COLUMN ] = __( 'Featured image', 'dos-toolkit' );
			}
		}

		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Featured image', 'dos-toolkit' );
		}

		return $out;
	}

	public static function render_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );

		if ( ! $thumbnail_id ) {
			echo '<span class="dos-featured-no">' . esc_html__( 'No', 'dos-toolkit' ) . '</span>';

			return;
		}

		echo wp_get_attachment_image( $thumbnail_id, array( 50, 50 ), true );
		echo '<br><span class="dos-featured-yes">' . esc_html__( 'Yes', 'dos-toolkit' ) . '</span>';
	}

	public static function render_filter( $post_type ) {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}

		$selected = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';
		?>
		<select name="<?php echo esc_attr( self::FILTER ); ?>">
			<option value=""><?php esc_html_e( 'Any featured image', 'dos-toolkit' ); ?></option>
			<option value="has" <?php selected( $selected, 'has' ); ?>><?php esc_html_e( 'Has a featured image', 'dos-toolkit' ); ?></option>
			<option value="missing" <?php selected( $selected, 'missing' ); ?>><?php esc_html_e( 'Missing a featured image', 'dos-toolkit' ); ?></option>
		</select>
		<?php
	}

	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;

		if ( 'edit.php' !== $pagenow ) {
			return;
		}

		$filter = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';

		if ( ! in_array( $filter, array( 'has', 'missing' ), true ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_type = $post_type ? $post_type : 'post';

		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}

		$query->set( 'meta_query', self::meta_query( $filter, (array) $query->get( 'meta_query' ) ) );
	}

	/**
	 * A thumbnail can be absent in two ways: no row at all, or a row holding
	 * an empty value left behind when one was removed. Both mean no image.
	 */
	public static function meta_query( $filter, array $existing = array() ) {
		if ( 'has' === $filter ) {
			$existing[] = array(
				'key'     => '_thumbnail_id',
				'compare' => 'EXISTS',
			);

			return $existing;
		}

		$existing[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => '_thumbnail_id',
				'value' => '',
			),
		);

		return $existing;
	}
}
