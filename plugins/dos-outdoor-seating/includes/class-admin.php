<?php
/**
 * Tools -> Outdoor Seating: takeover list, colors, venue counts per city and state.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const GROUP = 'osn_settings';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'Outdoor Seating', 'dos-outdoor-seating' ),
			__( 'Outdoor Seating', 'dos-outdoor-seating' ),
			'manage_options',
			'dos-outdoor-seating',
			array( __CLASS__, 'page' )
		);
	}

	public static function register() {
		register_setting(
			self::GROUP,
			Takeover::OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_cities' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			State_Cities::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( State_Cities::class, 'clean_codes' ),
				'default'           => array(),
			)
		);
		register_setting(
			self::GROUP,
			Home::OPT_ON,
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return $v ? '1' : '';
				},
				'default'           => '',
			)
		);
		foreach ( array( Home::OPT_H1, Home::OPT_SEARCH_HEADING ) as $opt ) {
			register_setting(
				self::GROUP,
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default'           => '',
				)
			);
		}
		register_setting(
			self::GROUP,
			Home::OPT_INTRO,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Home::OPT_SEO,
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return wp_kses_post( (string) $v );
				},
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Home::OPT_CAR,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_carousel' ),
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Home::OPT_IMAGES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_state_images' ),
				'default'           => array(),
			)
		);
		foreach ( array( 'osn_accent_color', 'osn_ink_color' ) as $opt ) {
			register_setting(
				self::GROUP,
				$opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => function ( $v ) {
						return (string) sanitize_hex_color( (string) $v );
					},
					'default'           => '',
				)
			);
		}
	}

	/** Invalid carousel JSON is rejected with a notice and the saved value is kept. */
	public static function sanitize_carousel( $value ) {
		$rows = Home::clean_carousel( (string) $value );
		if ( is_wp_error( $rows ) ) {
			add_settings_error( Home::OPT_CAR, 'osn_carousel', $rows->get_error_message() . ' The carousel was not saved.', 'error' );
			return (string) get_option( Home::OPT_CAR, '' );
		}
		return Home::carousel_json( $rows );
	}

	/** Invalid state image JSON is rejected with a notice and the saved value is kept. */
	public static function sanitize_state_images( $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) && null === json_decode( $value, true ) ) {
			add_settings_error( Home::OPT_IMAGES, 'osn_state_images', 'State images is not valid JSON. It was not saved.', 'error' );
			return Home::clean_state_images( get_option( Home::OPT_IMAGES, array() ) );
		}
		return Home::clean_state_images( $value );
	}

	public static function sanitize_cities( $value ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $value ) as $line ) {
			$line = sanitize_text_field( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		$terms = is_wp_error( $terms ) ? array() : $terms;
		Util::prime_landing_pages( $terms ); // One query for all landing pages instead of one per row.
		usort(
			$terms,
			function ( $a, $b ) {
				return strnatcasecmp( $a->name, $b->name );
			}
		);
		$states  = array();
		$enabled  = Takeover::enabled();
		$state_on = State_Cities::enabled();
		?>
<div class="wrap">
<h1><?php esc_html_e( 'Outdoor Seating', 'dos-outdoor-seating' ); ?></h1>
<form method="post" action="options.php">
<?php settings_fields( self::GROUP ); ?>
<h2><?php esc_html_e( 'Homepage', 'dos-outdoor-seating' ); ?></h2>
<table class="form-table" role="presentation">
<tr>
<th scope="row"><?php esc_html_e( 'Homepage takeover', 'dos-outdoor-seating' ); ?></th>
<td>
<input type="hidden" name="<?php echo esc_attr( Home::OPT_ON ); ?>" value="0">
<label><input type="checkbox" name="<?php echo esc_attr( Home::OPT_ON ); ?>" value="1"<?php checked( (bool) get_option( Home::OPT_ON ) ); ?>> <?php esc_html_e( 'Replace the front page content with the new homepage', 'dos-outdoor-seating' ); ?></label>
<p class="description"><?php esc_html_e( 'H1, intro, city search, SEO copy, popular cities carousel and browse-by-state tiles. The page itself (including a Themify Builder layout) is never edited; unchecking restores it.', 'dos-outdoor-seating' ); ?> <a href="<?php echo esc_url( Home::preview_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the new homepage', 'dos-outdoor-seating' ); ?></a></p>
</td>
</tr>
<tr>
<th scope="row"><label for="osn-home-h1"><?php esc_html_e( 'H1', 'dos-outdoor-seating' ); ?></label></th>
<td><input type="text" id="osn-home-h1" name="<?php echo esc_attr( Home::OPT_H1 ); ?>" value="<?php echo esc_attr( Home::h1() ); ?>" class="large-text"><p class="description"><?php esc_html_e( 'The homepage heading (the page has exactly one H1). Clear it to restore the default.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
<tr>
<th scope="row"><label for="osn-home-search-heading"><?php esc_html_e( 'Search panel heading', 'dos-outdoor-seating' ); ?></label></th>
<td><input type="text" id="osn-home-search-heading" name="<?php echo esc_attr( Home::OPT_SEARCH_HEADING ); ?>" value="<?php echo esc_attr( Home::search_heading() ); ?>" class="large-text"><p class="description"><?php esc_html_e( 'Heading of the city search box under the intro. Clear it to restore the default.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
<tr>
<th scope="row"><label for="osn-home-intro"><?php esc_html_e( 'Intro', 'dos-outdoor-seating' ); ?></label></th>
<td><textarea id="osn-home-intro" name="<?php echo esc_attr( Home::OPT_INTRO ); ?>" rows="4" class="large-text"><?php echo esc_textarea( Home::intro() ); ?></textarea><p class="description"><?php esc_html_e( 'Plain text under the H1. Clear it to restore the default.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
<tr>
<th scope="row"><label for="osn-home-seo"><?php esc_html_e( 'SEO copy', 'dos-outdoor-seating' ); ?></label></th>
<td><textarea id="osn-home-seo" name="<?php echo esc_attr( Home::OPT_SEO ); ?>" rows="12" class="large-text code"><?php echo esc_textarea( (string) get_option( Home::OPT_SEO, '' ) !== '' ? (string) get_option( Home::OPT_SEO, '' ) : Home::DEFAULT_SEO ); ?></textarea><p class="description"><?php esc_html_e( 'HTML shown under the search (post-content tags only). Clear it to restore the default.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
<tr>
<th scope="row"><label for="osn-home-car"><?php esc_html_e( 'Popular cities carousel', 'dos-outdoor-seating' ); ?></label></th>
<td><textarea id="osn-home-car" name="<?php echo esc_attr( Home::OPT_CAR ); ?>" rows="10" class="large-text code" placeholder='[{"label":"Seattle, WA","url":"https://outdoorseatingnearme.com/seattle-wa/","attachment_id":123}]'><?php echo esc_textarea( (string) get_option( Home::OPT_CAR, '' ) ); ?></textarea><p class="description"><?php esc_html_e( 'JSON array, up to 24 cities: label ("City, ST"), url, attachment_id (Media Library ID; 0 or omitted shows the icon band). Invalid JSON is rejected and the saved list is kept. The patio count comes from published venues.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
<tr>
<th scope="row"><label for="osn-home-imgs"><?php esc_html_e( 'State tile images', 'dos-outdoor-seating' ); ?></label></th>
<td><textarea id="osn-home-imgs" name="<?php echo esc_attr( Home::OPT_IMAGES ); ?>" rows="5" class="large-text code" placeholder='{"WA":123,"OR":"https://example.com/or.jpg"}'><?php echo esc_textarea( get_option( Home::OPT_IMAGES ) ? wp_json_encode( Home::clean_state_images( get_option( Home::OPT_IMAGES ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '' ); ?></textarea><p class="description"><?php esc_html_e( 'JSON map of state code to a Media Library ID or an image URL. Blank states use the images already on the current homepage, else the icon band.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
</table>
<h2><?php esc_html_e( 'Cities and colors', 'dos-outdoor-seating' ); ?></h2>
<table class="form-table" role="presentation">
<tr>
<th scope="row"><label for="osn-takeover"><?php esc_html_e( 'TablePress takeover cities', 'dos-outdoor-seating' ); ?></label></th>
<td>
<textarea id="osn-takeover" name="<?php echo esc_attr( Takeover::OPTION ); ?>" rows="8" cols="40" class="large-text code"><?php echo esc_textarea( (string) get_option( Takeover::OPTION, '' ) ); ?></textarea>
<p class="description"><?php esc_html_e( 'One "City, ST" per line, or a single * for every city. When a page shows [table filter="City, ST"] for an enabled city that has published venues, the cards replace the table. Everything else still renders through TablePress. Changing this list refreshes the page caches of the cities that flip.', 'dos-outdoor-seating' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><?php esc_html_e( 'State page takeover', 'dos-outdoor-seating' ); ?></th>
<td>
<fieldset>
<input type="hidden" name="<?php echo esc_attr( State_Cities::OPTION ); ?>[]" value="">
		<?php foreach ( States::names() as $code => $name ) : ?>
<label style="display:block;margin:0 0 4px"><input type="checkbox" name="<?php echo esc_attr( State_Cities::OPTION ); ?>[]" value="<?php echo esc_attr( $code ); ?>"<?php checked( in_array( $code, $state_on, true ) ); ?>> <?php echo esc_html( $name ); ?></label>
		<?php endforeach; ?>
</fieldset>
<p class="description"><?php esc_html_e( 'For a checked state, its landing page keeps its intro paragraphs but the city table is replaced by a city search, popular city cards and an A-Z city list built from the links in that table. Pages are never edited; unchecking restores the original table. If a page has fewer than 5 city links it is left alone.', 'dos-outdoor-seating' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="osn-accent"><?php esc_html_e( 'Accent color', 'dos-outdoor-seating' ); ?></label></th>
<td><input id="osn-accent" type="text" name="osn_accent_color" value="<?php echo esc_attr( (string) get_option( 'osn_accent_color', '' ) ); ?>" placeholder="#c2562b" class="regular-text code"></td>
</tr>
<tr>
<th scope="row"><label for="osn-ink"><?php esc_html_e( 'Text color', 'dos-outdoor-seating' ); ?></label></th>
<td><input id="osn-ink" type="text" name="osn_ink_color" value="<?php echo esc_attr( (string) get_option( 'osn_ink_color', '' ) ); ?>" placeholder="#2b2420" class="regular-text code"><p class="description"><?php esc_html_e( 'Hex values like #c2562b. Leave blank for the defaults.', 'dos-outdoor-seating' ); ?></p></td>
</tr>
</table>
<?php submit_button(); ?>
</form>

		<?php Seo::admin_box(); ?>

<h2><?php esc_html_e( 'Venues by city', 'dos-outdoor-seating' ); ?></h2>
<table class="widefat striped" style="max-width:720px">
<thead><tr><th><?php esc_html_e( 'City', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Published venues', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Landing page', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Takeover', 'dos-outdoor-seating' ); ?></th></tr></thead>
<tbody>
		<?php
		if ( ! $terms ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No venues yet.', 'dos-outdoor-seating' ) . '</td></tr>';
		}
		foreach ( $terms as $t ) :
			$state = strtoupper( (string) get_term_meta( $t->term_id, 'osn_state', true ) );
			if ( '' === $state ) {
				$p     = Util::parse_city( $t->name );
				$state = $p ? $p[1] : '?';
			}
			$states[ $state ] = ( $states[ $state ] ?? 0 ) + (int) $t->count;
			$url              = Util::city_url( $t );
			$on               = true === $enabled || in_array( $t->slug, (array) $enabled, true );
			?>
<tr>
<td><?php echo esc_html( $t->name ); ?></td>
<td><?php echo (int) $t->count; ?></td>
<td><?php echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'dos-outdoor-seating' ) . '</a>' : '&mdash;'; ?></td>
<td><?php echo $on ? esc_html__( 'On', 'dos-outdoor-seating' ) : '&mdash;'; ?></td>
</tr>
		<?php endforeach; ?>
</tbody>
</table>

<h2><?php esc_html_e( 'Venues by state', 'dos-outdoor-seating' ); ?></h2>
<table class="widefat striped" style="max-width:320px">
<thead><tr><th><?php esc_html_e( 'State', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Published venues', 'dos-outdoor-seating' ); ?></th></tr></thead>
<tbody>
		<?php
		ksort( $states );
		foreach ( $states as $st => $n ) {
			echo '<tr><td>' . esc_html( $st ) . '</td><td>' . (int) $n . '</td></tr>';
		}
		if ( ! $states ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No venues yet.', 'dos-outdoor-seating' ) . '</td></tr>';
		}
		?>
</tbody>
</table>

<h2><?php esc_html_e( 'State pages', 'dos-outdoor-seating' ); ?></h2>
<table class="widefat striped" style="max-width:720px">
<thead><tr><th><?php esc_html_e( 'State', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Page', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'City links parsed', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Cities with venues', 'dos-outdoor-seating' ); ?></th><th><?php esc_html_e( 'Takeover', 'dos-outdoor-seating' ); ?></th></tr></thead>
<tbody>
		<?php foreach ( States::names() as $code => $name ) : ?>
			<?php
			if ( ! State_Cities::page_for( $code ) && ! in_array( $code, $state_on, true ) ) {
				continue; // Only states that have a page (or are switched on); skip the stats work for the rest.
			}
			$st = State_Cities::admin_stats( $code );
			?>
<tr>
<td><?php echo esc_html( $name ); ?></td>
<td><?php echo $st['page'] ? '<a href="' . esc_url( get_permalink( $st['page'] ) ) . '">' . esc_html( '/' . $st['page']->post_name . '/' ) . '</a>' : '&mdash;'; ?></td>
<td><?php echo (int) $st['links']; ?></td>
<td><?php echo (int) $st['cities']; ?></td>
<td><?php echo in_array( $code, $state_on, true ) ? esc_html__( 'On', 'dos-outdoor-seating' ) : '&mdash;'; ?></td>
</tr>
		<?php endforeach; ?>
</tbody>
</table>
</div>
		<?php
	}
}
