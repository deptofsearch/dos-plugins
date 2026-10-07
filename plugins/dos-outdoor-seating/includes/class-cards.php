<?php
/**
 * [osn_venues] card grid: server-rendered cards, client-side search + amenity filters, ItemList JSON-LD.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Cards {

	public static function hooks() {
		add_shortcode( 'osn_venues', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			array(
				'city'    => '',
				'limit'   => '0',
				'filters' => '1',
				'search'  => '1',
			),
			$atts,
			'osn_venues'
		);
		return self::render( $a['city'], (int) $a['limit'], '0' !== (string) $a['filters'], '0' !== (string) $a['search'] );
	}

	/**
	 * @param string        $city    "City, ST" (empty for all venues).
	 * @param \WP_Term|null $hood    Neighborhood (or district) term: render that area's cards only (hood view).
	 */
	public static function render( $city, $limit = 0, $filters = true, $search = true, $hood = null ) {
		static $n = 0;
		++$n;
		$term = '' !== trim( (string) $city ) ? Util::find_city( $city ) : null;
		if ( '' !== trim( (string) $city ) && ! $term ) {
			return '<p class="osn osn-empty-note">' . esc_html__( 'No patios listed here yet.', 'dos-outdoor-seating' ) . '</p>';
		}
		$ids = Util::sorted_ids( $term ? $term->term_id : 0, $limit );
		if ( ! $ids ) {
			return '<p class="osn osn-empty-note">' . esc_html__( 'No patios listed here yet.', 'dos-outdoor-seating' ) . '</p>';
		}
		_prime_post_caches( $ids, true, false );
		update_object_term_cache( $ids, Data_Model::POST_TYPE );

		// Neighborhoods: the city view gets district/neighborhood chips; the hood view keeps only that area's cards.
		$tree = ( $term && taxonomy_exists( Data_Model::HOOD ) ) ? Hoods::city_tree( $term, $ids ) : null;
		if ( $hood && $tree ) {
			$ids = array_values(
				array_filter(
					$ids,
					function ( $id ) use ( $tree, $hood ) {
						return Hoods::venue_in( $tree['by_venue'][ $id ] ?? null, $hood );
					}
				)
			);
			if ( ! $ids ) {
				return '<p class="osn osn-empty-note">' . esc_html__( 'No patios listed here yet.', 'dos-outdoor-seating' ) . '</p>';
			}
		}
		$hood_ui = ! $hood && $tree && $tree['districts'];
		self::prime_thumbs( $ids );
		Assets::enqueue();

		// Amenity counts for the filter chips.
		$counts = array();
		$names  = array();
		foreach ( $ids as $id ) {
			foreach ( (array) get_the_terms( $id, Data_Model::AMENITY ) as $t ) {
				if ( $t instanceof \WP_Term ) {
					$counts[ $t->slug ] = ( $counts[ $t->slug ] ?? 0 ) + 1;
					$names[ $t->slug ]  = $t->name;
				}
			}
		}
		$order  = self::sort_slugs( array_keys( $counts ) );
		$sorted = array();
		foreach ( $order as $slug ) {
			$sorted[ $slug ] = $counts[ $slug ];
		}
		$counts = $sorted;

		$uid   = 'osn-grid-' . $n;
		$label = $term ? Util::city_label( $term->name ) : '';
		$area  = $label;
		if ( $hood ) { // Hood view: the page's own hood name ("Judkins"), not the matched term's ("Atlantic").
			$hname = $hood->name;
			$cur   = get_post();
			if ( $cur ) {
				$flt = Page_Title::filter_from_content( (string) $cur->post_content );
				$hm  = '' !== $flt ? Hoods::match_page( $cur, $flt ) : null;
				if ( $hm && $hm->term_id === $hood->term_id ) {
					$hname = Hoods::page_hood_name( $flt, $hood );
				}
			}
			$area  = $hname . ', ' . $label; // "Judkins, Seattle".
			$label = $hname;
		}
		$list  = array();

		ob_start();
		?>
<div class="osn osn-venues" id="<?php echo esc_attr( $uid ); ?>" data-osn-grid>
<?php if ( $search || $hood_ui || ( $filters && $counts ) ) : ?>
<div class="osn-filters" data-osn-filters hidden>
<?php if ( $search ) : ?>
<div class="osn-filters__search">
<label class="osn-sr" for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'Search by name, cuisine, or street', 'dos-outdoor-seating' ); ?></label>
<input id="<?php echo esc_attr( $uid ); ?>-q" type="search" class="osn-input" placeholder="<?php echo esc_attr( $label ? sprintf( /* translators: %s: city */ __( 'Search %s patios by name, cuisine, or street', 'dos-outdoor-seating' ), $label ) : __( 'Search by name, cuisine, or street', 'dos-outdoor-seating' ) ); ?>" data-osn-q autocomplete="off">
</div>
<?php endif; ?>
<?php
if ( $hood_ui ) {
	echo Hoods::chips_html( $tree, count( $ids ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Hoods.
}
?>
<?php if ( $filters && $counts ) : ?>
<?php
// Show the 8 most common amenities (kept in priority order); the rest sit behind "More filters (N)" (JS only, so no-JS shows all).
$chip_keep = 8;
$collapse  = count( $counts ) > $chip_keep + 1;
$top       = array();
if ( $collapse ) {
	$by_count = array_keys( $counts );
	usort(
		$by_count,
		function ( $a, $b ) use ( $counts, $by_count ) {
			return ( $counts[ $b ] <=> $counts[ $a ] ) ?: ( array_search( $a, $by_count, true ) <=> array_search( $b, $by_count, true ) );
		}
	);
	$top = array_slice( $by_count, 0, $chip_keep );
}
$chip_more = $collapse ? count( $counts ) - $chip_keep : 0;
$ordered   = $collapse ? array_merge( array_values( array_intersect( array_keys( $counts ), $top ) ), array_values( array_diff( array_keys( $counts ), $top ) ) ) : array_keys( $counts );
?>
<div class="osn-filter-section osn-filter-section--amenity">
<p class="osn-filter-label"><?php esc_html_e( 'Filter by', 'dos-outdoor-seating' ); ?></p>
<div class="osn-filters__chips" data-osn-amenity-chips id="<?php echo esc_attr( $uid ); ?>-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by amenity', 'dos-outdoor-seating' ); ?>">
<?php
foreach ( $ordered as $chip_i => $slug ) :
	$count = $counts[ $slug ];
	if ( $chip_more && $chip_keep === $chip_i ) :
		?>
<button type="button" class="osn-chip osn-chip--more" aria-expanded="false" aria-controls="<?php echo esc_attr( $uid ); ?>-chips" data-osn-more data-more="<?php echo (int) $chip_more; ?>"><?php echo esc_html( sprintf( /* translators: %d: number of hidden filters */ __( 'More filters (%d)', 'dos-outdoor-seating' ), $chip_more ) ); ?></button>
<?php endif; ?>
<button type="button" class="osn-chip osn-chip--filter<?php echo $chip_more && $chip_i >= $chip_keep ? ' osn-chip--extra' : ''; ?>" aria-pressed="false" data-osn-chip="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $names[ $slug ] ); ?> <span class="osn-chip__count"><?php echo (int) $count; ?></span></button>
<?php
endforeach;
?>
<button type="button" class="osn-chip osn-chip--clear" data-osn-clear hidden><?php esc_html_e( 'Clear filters', 'dos-outdoor-seating' ); ?></button>
</div>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<p class="osn-count" id="<?php echo esc_attr( Hoods::results_anchor( $n ) ); ?>" data-osn-count role="status" aria-live="polite"><?php echo esc_html( sprintf( /* translators: %d: number of venues */ _n( '%d place', '%d places', count( $ids ), 'dos-outdoor-seating' ), count( $ids ) ) ); ?></p>
<ul class="osn-grid">
<?php
foreach ( $ids as $pos => $id ) {
	echo self::card( $id, ! $hood ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in card().
	$list[] = array(
		'@type'    => 'ListItem',
		'position' => $pos + 1,
		'url'      => get_permalink( $id ),
		'name'     => get_the_title( $id ),
	);
}
?>
</ul>
<p class="osn-empty" data-osn-empty hidden><?php esc_html_e( 'No places match those filters. Try clearing a filter or a different search.', 'dos-outdoor-seating' ); ?></p>
<?php
if ( $hood_ui ) {
	echo Hoods::browse_html( $tree, Hoods::results_anchor( $n ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Hoods.
} elseif ( $hood && $tree ) {
	echo Hoods::nearby_html( $tree, $hood, $term, Util::city_label( $term->name ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Hoods.
}
echo Util::json_ld( // phpcs:ignore WordPress.Security.EscapeOutput
	array(
		'@context'        => 'https://schema.org',
		'@type'           => 'ItemList',
		'name'            => $label ? sprintf( 'Restaurants with outdoor seating in %s', $hood ? $area : $term->name ) : 'Restaurants with outdoor seating',
		'numberOfItems'   => count( $list ),
		'itemListElement' => $list,
	)
);
?>
</div>
		<?php
		return ob_get_clean();
	}

	/** Prime the featured image attachment posts for a list of venues in one query. */
	public static function prime_thumbs( array $ids ) {
		$tids = array();
		foreach ( $ids as $id ) {
			$t = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $t > 0 ) {
				$tids[] = $t;
			}
		}
		if ( $tids ) {
			_prime_post_caches( array_values( array_unique( $tids ) ), true, false );
		}
	}

	/** Fixed chip priority; wheelchair-accessible always sorts last. Filter: osn_amenity_chip_order. */
	const CHIP_ORDER = array( 'outdoor-seating', 'happy-hour', 'dog-friendly', 'brunch', 'live-music', 'cocktails', 'beer', 'wine', 'rooftop', 'fire-pit', 'sports-on-tv', 'kid-friendly', 'reservations', 'breakfast', 'lunch', 'dinner', 'vegetarian', 'takeout', 'delivery', 'waterfront', 'wheelchair-accessible' );

	/** Orders amenity slugs: known priority first, unknown slugs alphabetically, wheelchair-accessible last. */
	public static function sort_slugs( array $slugs ) {
		$order = array_values( (array) apply_filters( 'osn_amenity_chip_order', self::CHIP_ORDER ) );
		$last  = 'wheelchair-accessible';
		$known = array();
		$other = array();
		$tail  = array();
		foreach ( array_unique( $slugs ) as $s ) {
			if ( $last === $s ) {
				$tail[] = $s;
			} elseif ( false !== ( $i = array_search( $s, $order, true ) ) ) {
				$known[ $s ] = $i;
			} else {
				$other[] = $s;
			}
		}
		asort( $known );
		sort( $other, SORT_STRING );
		return array_merge( array_keys( $known ), $other, $tail );
	}

	/** One card <li>. Everything is escaped here. */
	public static function card( $id, $show_hood = true ) {
		$title  = get_the_title( $id );
		$cat    = Util::first_term( $id, Data_Model::CATEGORY );
		$street = (string) Util::meta( $id, 'street' );
		$phone  = (string) Util::meta( $id, 'phone' );
		$os     = (string) Util::meta( $id, 'outdoor_seating' );
		$rating = (float) Util::meta( $id, 'rating' );
		$count  = (int) Util::meta( $id, 'rating_count' );
		$price  = Util::price_label( $id );
		$terms  = get_the_terms( $id, Data_Model::AMENITY );
		$slugs  = array();
		$chips  = array();
		$byslug = array();
		foreach ( ( $terms && ! is_wp_error( $terms ) ) ? $terms : array() as $t ) {
			$slugs[]              = $t->slug;
			$byslug[ $t->slug ] = $t->name;
		}
		foreach ( self::sort_slugs( array_keys( $byslug ) ) as $sl ) {
			if ( 'outdoor-seating' !== $sl ) {
				$chips[] = $byslug[ $sl ];
			}
		}

		// Photo: the featured image (sideloaded from the venue's site), else any stored photo URL, else an icon band.
		$thumb_html = '';
		$tid        = Util::thumb_id( $id );
		if ( $tid ) {
			$thumb_html = wp_get_attachment_image(
				$tid,
				'medium_large',
				false,
				array(
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 600px) 100vw, 360px',
				)
			);
		}
		if ( '' === $thumb_html ) {
			$photo_url = '';
			$photos    = Util::photos( $id );
			if ( $photos && ! empty( $photos[0]['url'] ) ) {
				$photo_url = $photos[0]['url'];
			} else {
				$photo_url = (string) Util::meta( $id, 'featured_media_url' );
			}
			if ( '' !== $photo_url ) {
				$thumb_html = '<img src="' . esc_url( $photo_url ) . '" alt="" loading="lazy" decoding="async">';
			}
		}
		$icon = Icons::name_for( $cat ? $cat->name : '', Util::list_meta( $id, 'types_json' ) );

		$info    = Hoods::venue_info( $id );
		$hood_t  = $info['hood'] ? $info['hood'] : $info['district'];
		$hood_u  = $hood_t ? Hoods::page_url( $hood_t ) : '';
		$search  = Util::lower( $title . ' ' . ( $cat ? $cat->name : '' ) . ' ' . $street );
		$closed  = 'temporarily_closed' === Util::meta( $id, 'business_status' );

		ob_start();
		?>
<li class="osn-card" data-osn-card data-name="<?php echo esc_attr( $search ); ?>" data-amenities="<?php echo esc_attr( implode( ' ', $slugs ) ); ?>"<?php echo $info['district'] ? ' data-district="' . esc_attr( Hoods::short( $info['district'] ) ) . '"' : ''; ?><?php echo $info['hood'] ? ' data-hood="' . esc_attr( Hoods::short( $info['hood'] ) ) . '"' : ''; ?>>
<?php if ( '' !== $thumb_html ) : ?>
<a class="osn-card__media" href="<?php echo esc_url( get_permalink( $id ) ); ?>" tabindex="-1" aria-hidden="true">
<?php echo $thumb_html; // phpcs:ignore WordPress.Security.EscapeOutput -- core image markup or escaped above. ?>
</a>
<?php else : ?>
<a class="osn-card__media osn-card__media--band" style="<?php echo esc_attr( Icons::band_style( $icon ) ); ?>" href="<?php echo esc_url( get_permalink( $id ) ); ?>" tabindex="-1" aria-hidden="true">
<?php echo Icons::svg( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput -- trusted constants. ?>
</a>
<?php endif; ?>
<div class="osn-card__body">
<h3 class="osn-card__title"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>"><?php echo esc_html( $title ); ?></a></h3>
<p class="osn-card__meta">
<?php if ( $show_hood && $hood_t ) : ?><span class="osn-card__hood"><?php echo $hood_u ? '<a href="' . esc_url( $hood_u ) . '">' . esc_html( $hood_t->name ) . '</a>' : esc_html( $hood_t->name ); ?></span><?php endif; ?>
<?php if ( $cat ) : ?><span class="osn-card__cat"><?php echo esc_html( $cat->name ); ?></span><?php endif; ?>
<?php if ( $count > 0 && $rating > 0 ) : ?><span class="osn-card__rating"><span aria-hidden="true">&#9733;</span> <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?> <span class="osn-card__count">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span></span><?php endif; ?>
<?php if ( '' !== $price ) : ?><span class="osn-card__price" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: $ signs */ __( 'Price %s', 'dos-outdoor-seating' ), $price ) ); ?>"><?php echo esc_html( $price ); ?></span><?php endif; ?>
</p>
<?php if ( $closed ) : ?><p class="osn-card__badge"><span class="osn-badge osn-badge--temp"><?php esc_html_e( 'Temporarily closed', 'dos-outdoor-seating' ); ?></span></p>
<?php endif; ?>
<?php if ( 'yes' === $os ) : ?><p class="osn-card__badge"><span class="osn-badge osn-badge--yes"><?php esc_html_e( 'Outdoor seating', 'dos-outdoor-seating' ); ?></span></p>
<?php elseif ( 'no' === $os ) : ?><p class="osn-card__badge"><span class="osn-badge osn-badge--no"><?php esc_html_e( 'No patio', 'dos-outdoor-seating' ); ?></span></p><?php endif; ?>
<?php if ( $chips ) : ?><ul class="osn-chips osn-chips--card"><?php foreach ( array_slice( $chips, 0, 4 ) as $c ) : ?><li class="osn-chip"><?php echo esc_html( $c ); ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if ( '' !== $street ) : ?><p class="osn-card__addr"><?php echo esc_html( $street ); ?></p><?php endif; ?>
<?php
if ( '' !== $phone ) :
	$fp = Util::format_phone( $phone );
	?>
<p class="osn-card__phone"><a href="tel:<?php echo esc_attr( $fp[1] ); ?>"><?php echo esc_html( $fp[0] ); ?></a></p><?php endif; ?>
</div>
</li>
		<?php
		return ob_get_clean();
	}
}
