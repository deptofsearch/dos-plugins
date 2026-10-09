<?php
/**
 * Front-end integration with the active theme (Themify Magazine): no custom templates. The grid is
 * appended to the post content through the_content, like Open Houses In, so the theme's own page
 * layout, header, and footer stay in charge. Everything is scoped under .blnm.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Frontend {

	const HANDLE = 'dos-best-lenders';

	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 12 );
		add_filter( 'the_title', array( __CLASS__, 'lender_title' ), 10, 2 );
		add_filter( 'document_title_parts', array( __CLASS__, 'title_parts' ) );
		add_action( 'wp_head', array( __CLASS__, 'meta_description' ) );
	}

	public static function register_assets() {
		wp_register_style( self::HANDLE, plugins_url( 'assets/blnm.css', PLUGIN_FILE ), array( Brand::CSS ), VERSION );
		wp_register_script( self::HANDLE, plugins_url( 'assets/blnm.js', PLUGIN_FILE ), array(), VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		if ( is_singular( array( Data_Model::CITY, Data_Model::LENDER ) ) ) {
			self::enqueue();
		}
	}

	public static function enqueue() {
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	public static function content( $content ) {
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( is_singular( Data_Model::CITY ) ) {
			return self::city_view( get_the_ID() ) . $content;
		}
		if ( is_singular( Data_Model::LENDER ) ) {
			return self::lender_view( get_the_ID() ) . $content;
		}
		return $content;
	}

	public static function city_data( $id ) {
		return array(
			'name'       => (string) get_post_meta( $id, 'blnm_city_name', true ),
			'state'      => (string) get_post_meta( $id, 'blnm_state', true ),
			'county'     => (string) get_post_meta( $id, 'blnm_county_name', true ),
			'year'       => (int) get_post_meta( $id, 'blnm_data_year', true ) ?: 2025,
			'updated_at' => (string) get_post_meta( $id, 'blnm_updated_at', true ),
			'reviewed_at' => (string) get_post_meta( $id, 'blnm_reviewed_at', true ),
		);
	}

	/** Nearby towns for an empty city page, with permalinks. Only published pages are linked (drafts too for editors previewing). */
	private static function nearby_links( $id ) {
		$out = array();
		foreach ( Data_Model::city_nearby( $id ) as $n ) {
			$post = get_page_by_path( $n['slug'], OBJECT, Data_Model::CITY );
			if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $post->ID ) ) ) {
				continue;
			}
			$n['url'] = get_permalink( $post );
			$out[]    = $n;
		}
		return $out;
	}

	private static function city_view( $id ) {
		self::enqueue();
		$lenders = Data_Model::city_lenders( $id );

		// Link cards to a lender profile when one is published (post name is the lowercased LEI).
		$leis = array();
		foreach ( $lenders as $l ) {
			if ( ! empty( $l['lei'] ) ) {
				$leis[] = strtolower( $l['lei'] );
			}
		}
		$urls = array();
		if ( $leis ) {
			$posts = get_posts(
				array(
					'post_type'      => Data_Model::LENDER,
					'post_status'    => 'publish',
					'post_name__in'  => $leis,
					'posts_per_page' => count( $leis ),
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $p ) {
				$urls[ $p->post_name ] = get_permalink( $p );
			}
		}
		foreach ( $lenders as &$l ) {
			$k = strtolower( $l['lei'] ?? '' );
			if ( isset( $urls[ $k ] ) ) {
				$l['url'] = $urls[ $k ];
			}
		}
		unset( $l );

		$city = self::city_data( $id );
		$out  = Render::city( $city, $lenders, $lenders ? array() : self::nearby_links( $id ) );
		if ( $city['updated_at'] ) {
			$ts = strtotime( $city['updated_at'] );
			if ( $ts ) {
				$out .= '<p class="blnm blnm-updated">' . esc_html( sprintf( __( 'Data refreshed %s.', 'dos-best-lenders' ), wp_date( get_option( 'date_format' ), $ts ) ) ) . '</p>';
			}
		}
		return $out;
	}

	/** Visible lender profile heading: the cleaned display name. The stored post title stays the legal name. */
	public static function lender_title( $title, $id = 0 ) {
		if ( is_admin() || ! $id || Data_Model::LENDER !== get_post_type( $id ) ) {
			return $title;
		}
		return Render::display_name( (string) $title );
	}

	/** Lender profile: facts from meta plus a table of cities where this LEI appears. */
	private static function lender_view( $id ) {
		self::enqueue();
		$lei    = (string) get_post_meta( $id, 'blnm_lei', true );
		$type   = (string) get_post_meta( $id, 'blnm_lender_type', true );
		$hq     = (string) get_post_meta( $id, 'blnm_hq', true );
		$nmls   = (string) get_post_meta( $id, 'blnm_nmls_url', true );
		$sumtxt = (string) get_post_meta( $id, 'blnm_summary', true );
		$rev    = json_decode( (string) get_post_meta( $id, 'blnm_review_json', true ), true );
		$rev    = is_array( $rev ) ? $rev : array();
		$rows   = self::lender_cities( $lei );
		$total  = 0;
		foreach ( $rows as $r ) {
			$total += $r['loans'];
		}

		ob_start();
		?>
<section class="blnm blnm-lender">
<p class="blnm-sub"><?php echo esc_html( Render::type_label( $type ) . ( $hq ? ' · HQ ' . $hq : '' ) ); ?></p>
<p class="blnm-legal"><?php echo esc_html( sprintf( /* translators: %s: registered legal name */ __( 'Legal name: %s', 'dos-best-lenders' ), (string) get_post_field( 'post_title', $id ) ) ); ?></p>
<?php if ( ! empty( $rev['is_builder_lender'] ) ) : ?><p class="blnm-builder-wrap"><?php echo Render::builder_tag(); // phpcs:ignore WordPress.Security.EscapeOutput ?></p><?php endif; ?>
<?php if ( '' !== $sumtxt ) : ?><p class="blnm-lender-summary"><?php echo esc_html( $sumtxt ); ?></p><?php endif; ?>
<?php echo Render::rating_line( $rev ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php if ( ! empty( $rev['branch_name'] ) || ! empty( $rev['branch_address'] ) ) : ?><p class="blnm-branch"><?php echo esc_html( implode( ' · ', array_filter( array( $rev['branch_name'] ?? '', $rev['branch_address'] ?? '' ) ) ) ); ?></p><?php endif; ?>
<?php echo Render::review_block( $rev ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php if ( $rows ) : ?>
<p><?php echo esc_html( sprintf( _n( '%1$s home loans in %2$s city on this site.', '%1$s home loans across %2$s cities on this site.', count( $rows ), 'dos-best-lenders' ), number_format_i18n( $total ), number_format_i18n( count( $rows ) ) ) ); ?></p>
<table class="blnm-table">
<thead><tr><th><?php esc_html_e( 'City', 'dos-best-lenders' ); ?></th><th><?php esc_html_e( 'Loans', 'dos-best-lenders' ); ?></th><th><?php esc_html_e( 'Approval', 'dos-best-lenders' ); ?></th><th><?php esc_html_e( 'Median rate', 'dos-best-lenders' ); ?></th><th><?php esc_html_e( 'LLS', 'dos-best-lenders' ); ?></th></tr></thead>
<tbody>
<?php foreach ( $rows as $r ) : ?>
<tr><td><a href="<?php echo esc_url( $r['url'] ); ?>"><?php echo esc_html( $r['city'] ); ?></a></td><td><?php echo esc_html( number_format_i18n( $r['loans'] ) ); ?></td><td><?php echo null === $r['approval'] ? '&mdash;' : esc_html( round( $r['approval'] ) . '%' ); ?></td><td><?php echo null === $r['rate'] ? '&mdash;' : esc_html( number_format( $r['rate'], 2 ) . '%' ); ?></td><td><?php echo null === $r['score'] ? '&mdash;' : esc_html( (string) round( $r['score'] ) ); ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
<p class="blnm-links"><a class="blnm-link" href="<?php echo esc_url( $nmls ? $nmls : 'https://www.nmlsconsumeraccess.org/' ); ?>" target="_blank" rel="noopener nofollow"><?php esc_html_e( 'NMLS lookup', 'dos-best-lenders' ); ?></a></p>
<p class="blnm-disclosure"><?php echo esc_html( Render::DISCLOSURE ); ?></p>
</section>
		<?php
		return ob_get_clean();
	}

	/** Cities whose lender JSON contains this LEI. LIKE scan, cached 12h; fine for hundreds of cities, revisit past a few thousand. */
	private static function lender_cities( $lei ) {
		if ( '' === $lei ) {
			return array();
		}
		$key  = 'blnm_lc_' . md5( $lei );
		$rows = get_transient( $key );
		if ( false !== $rows ) {
			return $rows;
		}
		$ids  = get_posts(
			array(
				'post_type'      => Data_Model::CITY,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => 'blnm_lenders_json',
						'value'   => '"lei":"' . $lei . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		$rows = array();
		foreach ( $ids as $id ) {
			foreach ( Data_Model::city_lenders( $id ) as $l ) {
				if ( ( $l['lei'] ?? '' ) === $lei ) {
					$rows[] = array(
						'city'     => get_post_meta( $id, 'blnm_city_name', true ) . ', ' . get_post_meta( $id, 'blnm_state', true ),
						'url'      => get_permalink( $id ),
						'loans'    => (int) $l['loans_2025'],
						'approval' => $l['approval_rate'] ?? null,
						'rate'     => $l['median_rate'] ?? null,
						'score'    => $l['score'] ?? null,
					);
					break;
				}
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['loans'] <=> $a['loans'];
			}
		);
		set_transient( $key, $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	public static function title_parts( $parts ) {
		if ( is_singular( Data_Model::CITY ) ) {
			$c = self::city_data( get_queried_object_id() );
			if ( $c['name'] ) {
				$parts['title'] = sprintf( 'Mortgage Lenders in %s, %s (%d HMDA Data)', $c['name'], $c['state'], $c['year'] );
			}
		}
		return $parts;
	}

	public static function meta_description() {
		if ( Social::seo_plugin_active() ) {
			return;
		}
		$d = self::description_for_request();
		if ( '' !== $d ) {
			echo '<meta name="description" content="' . esc_attr( $d ) . "\">\n";
		}
	}

	/** The meta description for the current request ('' when there is none). Also used for og:description. */
	public static function description_for_request() {
		if ( is_front_page() && ! is_singular() ) {
			return (string) get_bloginfo( 'description' );
		}
		if ( ! is_singular( array( Data_Model::CITY, Data_Model::LENDER, 'page' ) ) ) {
			return '';
		}
		$id = get_queried_object_id();
		// A hand-written or generated excerpt wins over the templated description.
		$ex = trim( wp_strip_all_tags( (string) get_post_field( 'post_excerpt', $id ) ) );
		if ( '' !== $ex ) {
			return $ex;
		}
		if ( is_singular( Data_Model::CITY ) ) {
			$c = self::city_data( $id );
			$n = (int) get_post_meta( $id, 'blnm_lender_count', true );
			$t = (int) get_post_meta( $id, 'blnm_loan_total', true );
			if ( ! $c['name'] || ! $n ) {
				return '';
			}
			return sprintf( '%s lenders made %s home loans in %s, %s in %d. Compare volume, approval rate, and median rate from public HMDA data.', number_format_i18n( $n ), number_format_i18n( $t ), $c['name'], $c['state'], $c['year'] );
		}
		// Pages and lender profiles: the opening text, cut at a word boundary near 155 characters.
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $id ) ) ) ) );
		if ( strlen( $text ) > 158 ) {
			$text = rtrim( substr( $text, 0, strrpos( substr( $text, 0, 155 ), ' ' ) ), ' ,;:-' ) . '…';
		}
		return $text;
	}
}
