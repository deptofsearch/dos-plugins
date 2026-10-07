<?php
/**
 * Single venue page: appended to the_content so it works in any theme's normal single template.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Venue_Page {

	/** Chip sections built from details_json, in page order. Payments is never shown. */
	const SECTIONS = array(
		'Food & drinks' => array( 'Offerings', 'Dining options', 'Popular for' ),
		'Highlights'    => array( 'Highlights', 'Atmosphere', 'Crowd' ),
		'Good to know'  => array( 'Service options', 'Planning', 'Children', 'Amenities', 'Accessibility' ),
	);

	/** JS getDay() index for each day name; drives the "today" row highlight. */
	const JS_DAY = array( 'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6 );

	public static function hooks() {
		// Priority 20: after wpautop (10) and shortcodes (11) so the markup isn't mangled.
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 99 );
	}

	/** Venue pages have no sidebar content; give Themify's full-width layout instead of an empty column. */
	public static function body_class( $classes ) {
		if ( ! is_singular( Data_Model::POST_TYPE ) || ! apply_filters( 'osn_venue_full_width', true ) ) {
			return $classes;
		}
		$classes   = array_diff( $classes, array( 'sidebar1', 'sidebar2', 'sidebar1 sidebar-left', 'sidebar-left' ) );
		$classes[] = 'sidebar-none';
		return $classes;
	}

	/** Thin venue pages (no summary, no confirmed patio, no hours) are noindex,follow. */
	public static function robots( $robots ) {
		if ( ! is_singular( Data_Model::POST_TYPE ) ) {
			return $robots;
		}
		if ( self::is_thin( get_queried_object_id() ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * The thin-page rule: no summary, no confirmed patio, no hours. Used by robots() and by the sitemap flag
	 * (Seo::sync_thin), so the two can never disagree.
	 */
	public static function is_thin( $id ) {
		return '' === trim( (string) Util::meta( $id, 'summary' ) ) && 'yes' !== Util::meta( $id, 'outdoor_seating' ) && ! Util::hours( $id );
	}

	/** Generic Google Maps values that say nothing useful as chips. Filter: osn_hidden_detail_values. */
	const HIDDEN_VALUES = array( 'Seating', 'Food', 'Alcohol', 'Table service', 'Dine-in', 'Restroom', 'Comfort food', 'Solo dining', 'Credit cards', 'Debit cards', 'NFC mobile payments', 'Onsite services' );

	/** @return array<string,string[]> Section title => deduped values, empty sections skipped. */
	public static function detail_sections( array $details ) {
		$lc = array();
		foreach ( $details as $name => $values ) {
			$lc[ strtolower( $name ) ] = (array) $values;
		}
		$hidden = array_map( 'strtolower', array_map( 'strval', (array) apply_filters( 'osn_hidden_detail_values', self::HIDDEN_VALUES ) ) );
		$out    = array();
		foreach ( self::SECTIONS as $title => $keys ) {
			$vals = array();
			foreach ( $keys as $k ) {
				foreach ( $lc[ strtolower( $k ) ] ?? array() as $v ) {
					// The Outdoor seating callout already covers this one.
					if ( is_string( $v ) && '' !== $v && 0 !== strcasecmp( $v, 'Outdoor seating' ) && ! in_array( strtolower( trim( $v ) ), $hidden, true ) ) {
						$vals[ $v ] = true;
					}
				}
			}
			if ( $vals ) {
				$out[ $title ] = array_keys( $vals );
			}
		}
		return $out;
	}

	public static function filter_content( $content ) {
		static $busy = false;
		if ( $busy || ! is_singular( Data_Model::POST_TYPE ) || ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || Data_Model::POST_TYPE !== $post->post_type || get_queried_object_id() !== $post->ID ) {
			return $content;
		}
		$busy = true;
		$html = self::render( $post, $content );
		$busy = false;
		return $html;
	}

	public static function render( $post, $content = '' ) {
		$id = $post->ID;
		Assets::enqueue();

		$title   = get_the_title( $id );
		$term    = Util::first_term( $id, Data_Model::CITY );
		$city    = $term ? Util::city_label( $term->name ) : (string) Util::meta( $id, 'city_name' );
		$city_url = Util::city_url( $term );
		$hood     = Hoods::venue_info( $id );
		$hood     = $hood['hood'] ? $hood['hood'] : $hood['district'];
		$hood_url = $hood ? Hoods::page_url( $hood ) : '';
		$cat     = Util::first_term( $id, Data_Model::CATEGORY );
		$rating  = (float) Util::meta( $id, 'rating' );
		$count   = (int) Util::meta( $id, 'rating_count' );
		$price   = Util::price_label( $id );
		$tz      = Util::timezone( $id );
		$hours   = Util::hours( $id );
		$photos  = Util::photos( $id );
		$os      = (string) Util::meta( $id, 'outdoor_seating' );
		$notes   = (string) Util::meta( $id, 'patio_notes' );
		$summary = (string) Util::meta( $id, 'summary' );
		$address = (string) Util::meta( $id, 'address' );
		$phone   = (string) Util::meta( $id, 'phone' );
		$site    = (string) Util::meta( $id, 'website' );
		$status  = (string) Util::meta( $id, 'business_status' );
		$sections = self::detail_sections( Util::details( $id ) );
		$sources  = Util::list_meta( $id, 'data_sources' );
		$checked  = (string) Util::meta( $id, 'enriched_at' );
		$hero     = self::hero( $id, $title, $city );

		ob_start();
		?>
<div class="osn osn-venue">
<?php echo $hero; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in hero(). ?>
<header class="osn-venue__header">
<?php if ( apply_filters( 'osn_venue_print_title', true ) ) : // Themify Landing hides post titles on this site. ?>
<h1 class="osn-venue__title"><?php echo esc_html( get_the_title( $id ) ); ?></h1>
<?php endif; ?>
<p class="osn-venue__badges">
<?php if ( $city && $hood ) : ?>
	<?php if ( $hood_url ) : ?><a class="osn-venue__hood" href="<?php echo esc_url( $hood_url ); ?>"><?php echo esc_html( $hood->name ); ?></a><?php else : ?><span class="osn-venue__hood"><?php echo esc_html( $hood->name ); ?></span><?php endif; ?><span class="osn-venue__dot" aria-hidden="true">&middot;</span>
<?php endif; ?>
<?php if ( $city ) : ?>
	<?php if ( $city_url ) : ?><a class="osn-venue__city" href="<?php echo esc_url( $city_url ); ?>"><?php echo esc_html( $term ? $term->name : $city ); ?></a><?php else : ?><span class="osn-venue__city"><?php echo esc_html( $term ? $term->name : $city ); ?></span><?php endif; ?>
<?php endif; ?>
<?php if ( $cat ) : ?><span class="osn-venue__cat"><?php echo esc_html( $cat->name ); ?></span><?php endif; ?>
<?php if ( $count > 0 && $rating > 0 ) : ?><span class="osn-venue__rating"><span aria-hidden="true">&#9733;</span> <?php echo esc_html( number_format_i18n( $rating, 1 ) ); ?> <span class="osn-venue__count">(<?php echo esc_html( number_format_i18n( $count ) ); ?> <?php esc_html_e( 'reviews', 'dos-outdoor-seating' ); ?>)</span></span><?php endif; ?>
<?php if ( '' !== $price ) : ?><span class="osn-venue__price" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: $ signs */ __( 'Price %s', 'dos-outdoor-seating' ), $price ) ); ?>"><?php echo esc_html( $price ); ?></span><?php endif; ?>
<?php if ( 'temporarily_closed' === $status ) : ?><span class="osn-badge osn-badge--temp"><?php esc_html_e( 'Temporarily closed', 'dos-outdoor-seating' ); ?></span><?php elseif ( 'permanently_closed' === $status ) : ?><span class="osn-badge osn-badge--no"><?php esc_html_e( 'Permanently closed', 'dos-outdoor-seating' ); ?></span><?php endif; ?>
<?php if ( $hours && $tz && 'permanently_closed' !== $status ) : ?><span class="osn-venue__open" data-osn-open data-tz="<?php echo esc_attr( $tz ); ?>" data-hours="<?php echo esc_attr( wp_json_encode( $hours, JSON_UNESCAPED_UNICODE ) ); ?>" hidden></span><?php endif; ?>
</p>
<?php if ( '' !== $summary ) : ?><p class="osn-venue__summary"><?php echo esc_html( $summary ); ?></p><?php endif; ?>
</header>

<?php if ( '' !== trim( wp_strip_all_tags( $content ) ) ) : ?>
<div class="osn-venue__about"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- already-filtered post content. ?></div>
<?php endif; ?>

<?php if ( $photos ) : ?>
<section class="osn-venue__section osn-venue__photos" aria-label="<?php esc_attr_e( 'Photos', 'dos-outdoor-seating' ); ?>">
<ul class="osn-venue__photo-strip">
	<?php foreach ( $photos as $p ) : ?>
<li class="osn-venue__photo"><figure>
<img src="<?php echo esc_url( $p['url'] ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %s: venue name */ __( 'Photo of %s', 'dos-outdoor-seating' ), $title ) ); ?>" loading="lazy" decoding="async"<?php echo ! empty( $p['width'] ) ? ' width="' . (int) $p['width'] . '"' : ''; ?><?php echo ! empty( $p['height'] ) ? ' height="' . (int) $p['height'] . '"' : ''; ?>>
		<?php if ( ! empty( $p['attribution_html'] ) ) : ?><figcaption><?php echo wp_kses( $p['attribution_html'], array( 'a' => array( 'href' => true ) ) ); ?></figcaption><?php endif; ?>
</figure></li>
	<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>

<section class="osn-venue__section osn-venue__seating">
<h2><?php esc_html_e( 'Outdoor seating', 'dos-outdoor-seating' ); ?></h2>
<div class="osn-callout osn-callout--<?php echo esc_attr( in_array( $os, array( 'yes', 'no' ), true ) ? $os : 'unknown' ); ?>">
<p class="osn-callout__status"><strong>
<?php
if ( 'yes' === $os ) {
	echo esc_html( sprintf( /* translators: %s: venue name */ __( '%s has outdoor seating.', 'dos-outdoor-seating' ), $title ) );
} elseif ( 'no' === $os ) {
	echo esc_html( sprintf( /* translators: %s: venue name */ __( '%s does not list outdoor seating.', 'dos-outdoor-seating' ), $title ) );
} else {
	esc_html_e( 'Outdoor seating not confirmed yet. Call ahead to ask about patio tables.', 'dos-outdoor-seating' );
}
?>
</strong></p>
<?php if ( '' !== $notes ) : ?><p class="osn-callout__notes"><?php echo esc_html( $notes ); ?></p><?php endif; ?>
</div>
</section>

<?php foreach ( $sections as $sec_title => $sec_values ) : ?>
<section class="osn-venue__section osn-venue__details osn-venue__details--<?php echo esc_attr( sanitize_title( $sec_title ) ); ?>">
<h2><?php echo esc_html( $sec_title ); ?></h2>
<ul class="osn-chips"><?php foreach ( $sec_values as $c ) : ?><li class="osn-chip"><?php echo esc_html( $c ); ?></li><?php endforeach; ?></ul>
</section>
<?php endforeach; ?>

<?php if ( $hours ) : ?>
<section class="osn-venue__section osn-venue__hours">
<h2><?php esc_html_e( 'Hours', 'dos-outdoor-seating' ); ?></h2>
<table class="osn-hours" data-osn-hours-table>
<tbody>
	<?php foreach ( $hours as $day => $text ) : ?>
<tr data-day="<?php echo (int) self::JS_DAY[ $day ]; ?>"><th scope="row"><?php echo esc_html( $day ); ?></th><td><?php echo esc_html( $text ); ?></td></tr>
	<?php endforeach; ?>
</tbody>
</table>
</section>
<?php endif; ?>

<?php if ( '' !== $address || '' !== $title ) : ?>
<section class="osn-venue__section osn-venue__map">
<h2><?php esc_html_e( 'Map', 'dos-outdoor-seating' ); ?></h2>
<div class="osn-map">
<iframe title="<?php echo esc_attr( sprintf( /* translators: %s: venue name */ __( 'Map of %s', 'dos-outdoor-seating' ), $title ) ); ?>" src="<?php echo esc_url( Util::embed_url( $id, $title ) ); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
</div>
</section>
<?php endif; ?>

<section class="osn-venue__section osn-venue__contact">
<h2><?php esc_html_e( 'Contact', 'dos-outdoor-seating' ); ?></h2>
<ul class="osn-contact">
<?php if ( '' !== $address ) : ?><li class="osn-contact__addr"><?php echo esc_html( $address ); ?></li><?php endif; ?>
<?php
if ( '' !== $phone ) :
	$fp = Util::format_phone( $phone );
	?>
<li><a href="tel:<?php echo esc_attr( $fp[1] ); ?>"><?php echo esc_html( $fp[0] ); ?></a></li><?php endif; ?>
<?php if ( '' !== $site ) : ?><li><a href="<?php echo esc_url( $site ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Website', 'dos-outdoor-seating' ); ?></a></li><?php endif; ?>
<li><a href="<?php echo esc_url( Util::directions_url( $id, $title ) ); ?>" rel="noopener nofollow" target="_blank"><?php esc_html_e( 'Get directions', 'dos-outdoor-seating' ); ?></a></li>
</ul>
</section>

<?php
if ( $term ) :
	$more = array_slice( array_diff( Util::sorted_ids( $term->term_id, 12 ), array( $id ) ), 0, 6 );
	if ( $more ) :
		_prime_post_caches( $more, true, true );
		Cards::prime_thumbs( $more );
		?>
<section class="osn-venue__section osn-venue__more">
<h2><?php echo esc_html( sprintf( /* translators: %s: city */ __( 'More outdoor seating in %s', 'dos-outdoor-seating' ), $city ) ); ?></h2>
<ul class="osn-grid osn-grid--more">
		<?php
		foreach ( $more as $mid ) {
			echo self::card_for( $mid ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
</ul>
</section>
	<?php endif; ?>
	<?php if ( $city_url ) : ?>
<p class="osn-venue__back"><a href="<?php echo esc_url( $city_url ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s: city */ __( 'Back to all %s patios', 'dos-outdoor-seating' ), $city ) ); ?></a></p>
	<?php endif; ?>
<?php endif; ?>
<p class="osn-venue__source"><?php echo esc_html( self::source_line( $title, $sources, $checked ) ); ?></p>
<?php echo self::restaurant_ld( $post, $term, $cat, $photos, $hours ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Featured image hero (21:9 crop via CSS) with a credit link, or '' when the venue has no photo.
	 * The credit is the venue website by default (osn_photo_credit_url / osn_photo_credit_text).
	 */
	private static function hero( $id, $title, $city ) {
		$tid = Util::thumb_id( $id );
		if ( ! $tid ) {
			return '';
		}
		$alt = get_post_meta( $tid, '_wp_attachment_image_alt', true );
		if ( '' === (string) $alt ) {
			/* translators: 1: venue name, 2: city */
			$alt = '' !== $city ? sprintf( __( '%1$s in %2$s', 'dos-outdoor-seating' ), $title, $city ) : $title;
		}
		$img = wp_get_attachment_image(
			$tid,
			'large',
			false,
			array(
				'class'         => 'osn-venue__hero-img',
				'alt'           => $alt,
				'loading'       => 'eager',
				'decoding'      => 'async',
				'fetchpriority' => 'high',
				'sizes'         => '(min-width: 1100px) 1000px, 100vw',
			)
		);
		if ( '' === $img ) {
			return '';
		}
		$url  = (string) get_post_meta( $id, Fields::key( 'photo_credit_url' ), true );
		$text = (string) get_post_meta( $id, Fields::key( 'photo_credit_text' ), true );
		if ( '' === $url ) {
			$url = (string) Util::meta( $id, 'website' );
		}
		if ( '' === $text ) {
			/* translators: %s: venue name */
			$text = sprintf( __( "Photo: %s's website", 'dos-outdoor-seating' ), $title );
		}
		$credit = '' !== $url
			? '<a href="' . esc_url( $url ) . '" rel="nofollow noopener" target="_blank">' . esc_html( $text ) . '</a>'
			: esc_html( $text );
		return '<figure class="osn-venue__hero">' . $img . '<figcaption class="osn-venue__credit">' . $credit . '</figcaption></figure>' . "\n";
	}

	private static function card_for( $id ) {
		return Cards::card( $id );
	}

	/** "Details from Google Maps listing data and Name's website, last checked Oct 1, 2026". */
	public static function source_line( $title, array $sources, $checked ) {
		$text = __( 'Details from Google Maps listing data', 'dos-outdoor-seating' );
		if ( in_array( 'website', $sources, true ) ) {
			/* translators: %s: venue name */
			$text .= ' ' . sprintf( __( "and %s's website", 'dos-outdoor-seating' ), $title );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $checked ) ) {
			/* translators: %s: date */
			$text .= ', ' . sprintf( __( 'last checked %s', 'dos-outdoor-seating' ), wp_date( 'F j, Y', strtotime( $checked . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) );
		}
		return $text;
	}

	private static function restaurant_ld( $post, $term, $cat, $photos, array $hours ) {
		$id      = $post->ID;
		$site    = (string) Util::meta( $id, 'website' );
		$maps    = (string) Util::meta( $id, 'google_maps_uri' );
		$details = Util::details( $id );
		$ld      = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'Restaurant',
			'@id'              => get_permalink( $id ) . '#restaurant',
			'name'             => get_the_title( $id ),
			'url'              => get_permalink( $id ),
			'mainEntityOfPage' => get_permalink( $id ),
		);
		$same = array_values( array_filter( array( $site, $maps ) ) );
		if ( $same ) {
			$ld['sameAs'] = $same;
		}

		$street = (string) Util::meta( $id, 'street' );
		$addr   = array( '@type' => 'PostalAddress', 'addressCountry' => 'US' );
		$loc    = (string) Util::meta( $id, 'city_name' );
		if ( '' === $loc && $term ) {
			$loc = Util::city_label( $term->name );
		}
		foreach ( array(
			'streetAddress'   => $street,
			'addressLocality' => $loc,
			'addressRegion'   => (string) Util::meta( $id, 'state' ),
			'postalCode'      => (string) Util::meta( $id, 'zip' ),
		) as $k => $v ) {
			if ( '' !== $v ) {
				$addr[ $k ] = $v;
			}
		}
		if ( count( $addr ) > 2 ) {
			$ld['address'] = $addr;
		}
		$phone = (string) Util::meta( $id, 'phone' );
		if ( '' !== $phone ) {
			$ld['telephone'] = Util::format_phone( $phone )[1];
		}
		$lat = Util::meta( $id, 'lat' );
		$lng = Util::meta( $id, 'lng' );
		if ( '' !== $lat && '' !== $lng ) {
			$ld['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $lat, 'longitude' => (float) $lng );
		}
		$count = (int) Util::meta( $id, 'rating_count' );
		$rate  = (float) Util::meta( $id, 'rating' );
		if ( $count > 0 && $rate > 0 ) {
			$ld['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => $rate,
				'reviewCount' => $count,
				'bestRating'  => 5,
			);
		}

		// Cuisine from the Maps types (minus generic ones), else the category term.
		$generic = array( 'restaurant', 'bar', 'food', 'establishment', 'point of interest' );
		$cuisine = array();
		foreach ( Util::list_meta( $id, 'types_json' ) as $t ) {
			if ( ! in_array( strtolower( $t ), $generic, true ) ) {
				$cuisine[] = $t;
			}
		}
		if ( ! $cuisine && $cat ) {
			$cuisine[] = $cat->name;
		}
		if ( $cuisine ) {
			$ld['servesCuisine'] = 1 === count( $cuisine ) ? $cuisine[0] : $cuisine;
		}
		foreach ( (array) ( $details['Planning'] ?? array() ) as $v ) {
			if ( false !== stripos( (string) $v, 'accepts reservations' ) ) {
				$ld['acceptsReservations'] = true;
				break;
			}
		}
		$os = (string) Util::meta( $id, 'outdoor_seating' );
		if ( 'yes' === $os || 'no' === $os ) {
			$ld['amenityFeature'] = array(
				array(
					'@type' => 'LocationFeatureSpecification',
					'name'  => 'Outdoor seating',
					'value' => 'yes' === $os,
				),
			);
		}
		$price = Util::price_label( $id );
		if ( '' !== $price ) {
			$ld['priceRange'] = $price;
		}
		$tid = Util::thumb_id( $id );
		if ( $tid && wp_get_attachment_url( $tid ) ) {
			$ld['image'] = wp_get_attachment_url( $tid );
		} elseif ( $photos ) {
			$ld['image'] = $photos[0]['url'];
		}

		$spec = array();
		foreach ( Util::parsed_hours( $hours ) as $day => $ranges ) {
			foreach ( $ranges as $r ) {
				$spec[] = array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => $day,
					'opens'     => sprintf( '%02d:%02d', intdiv( $r[0], 60 ), $r[0] % 60 ),
					'closes'    => self::ld_time( $r[1] ),
				);
			}
		}
		if ( $spec ) {
			$ld['openingHoursSpecification'] = $spec;
		}
		return Util::json_ld( $ld );
	}

	/** Minutes from midnight (may exceed 1440 for after-midnight closes) to HH:MM. */
	private static function ld_time( $min ) {
		if ( 1440 === $min ) {
			return '23:59';
		}
		$min %= 1440;
		return sprintf( '%02d:%02d', intdiv( $min, 60 ), $min % 60 );
	}
}
