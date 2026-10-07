<?php
/**
 * [osn_city_search] typeahead. Port of dos-ohi-city-search; the list rides along as JSON in data-cities.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class City_Search {

	public static function hooks() {
		add_shortcode( 'osn_city_search', array( __CLASS__, 'shortcode' ) );
	}

	public static function shortcode( $atts ) {
		$a = shortcode_atts(
			array(
				'heading'     => 'Find Outdoor Seating by City',
				'intro'       => 'Start typing a city and pick it from the list to see its patios.',
				'placeholder' => 'Start typing a city',
				'button'      => 'Search',
				'state'       => '',
			),
			$atts,
			'osn_city_search'
		);
		$state = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $a['state'] ) );
		return self::render( $a, City_Index::get( $state ), $state, false );
	}

	/**
	 * The search box markup.
	 *
	 * @param array  $a            heading, intro, placeholder, button.
	 * @param array  $list         Rows { n: "City, ST", u: absolute URL, s: "ST" }.
	 * @param string $state        Two-letter state code, used only for the lazy-load endpoint.
	 * @param bool   $always_embed True to always embed the list (state pages list cities that have no venues yet, so the REST index would miss them).
	 * @param string $src_url      When set, the list is never embedded: the JS fetches this URL on first focus (the homepage passes /cities?scope=all).
	 */
	public static function render( array $a, array $list, $state = '', $always_embed = false, $src_url = '' ) {
		static $n = 0;
		++$n;
		$id    = 'osn-cs-' . $n;
		$max   = (int) apply_filters( 'osn_city_search_embed_max', 300 );
		$lazy  = '' !== $src_url || ( ! $always_embed && count( $list ) > $max );
		$data  = array();
		if ( ! $lazy ) {
			foreach ( $list as $c ) {
				$data[] = array(
					'n' => $c['n'],
					'u' => City_Index::relative( $c['u'] ), // Paths relative to home_url keep the payload small.
					's' => $c['s'],
				);
			}
		}
		$src = $lazy ? ( '' !== $src_url ? $src_url : rest_url( Rest::NAMESPACE_V1 . '/cities' ) ) : '';
		if ( $lazy && '' !== $state && '' === $src_url ) {
			$src = add_query_arg( 'state', $state, $src );
		}
		Assets::enqueue();

		$hub    = get_page_by_path( 'by-city' );
		$browse = ( $hub && 'publish' === $hub->post_status ) ? get_permalink( $hub ) : '';
		$browse = (string) apply_filters( 'osn_city_search_browse_url', $browse );

		ob_start();
		?>
<section class="osn osn-city-search" data-osn-city-search data-cities="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"<?php echo $lazy ? ' data-src="' . esc_url( $src ) . '"' : ''; ?> data-browse="<?php echo esc_url( $browse ); ?>">
<?php if ( '' !== $a['heading'] ) : ?><h2 class="osn-city-search__heading"><?php echo esc_html( $a['heading'] ); ?></h2><?php endif; ?>
<?php if ( '' !== $a['intro'] ) : ?><p class="osn-city-search__intro"><?php echo esc_html( $a['intro'] ); ?></p><?php endif; ?>
<form class="osn-city-search__form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" autocomplete="off">
<label class="osn-sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'City', 'dos-outdoor-seating' ); ?></label>
<div class="osn-city-search__wrap">
<input id="<?php echo esc_attr( $id ); ?>" name="s" type="text" placeholder="<?php echo esc_attr( $a['placeholder'] ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-list" spellcheck="false" autocapitalize="words" enterkeyhint="go">
<ul id="<?php echo esc_attr( $id ); ?>-list" class="osn-city-search__list" role="listbox" hidden></ul>
</div>
<button type="submit" class="osn-btn"><?php echo esc_html( $a['button'] ); ?></button>
</form>
<p class="osn-city-search__msg" role="status" aria-live="polite"></p>
</section>
		<?php
		return ob_get_clean();
	}
}
