<?php
/**
 * [blnm_city_search] and [blnm_state_index state="WA"].
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public static function hooks() {
		add_shortcode( 'blnm_city_search', array( __CLASS__, 'city_search' ) );
		add_shortcode( 'blnm_state_index', array( __CLASS__, 'state_index' ) );
		add_shortcode( 'blnm_contact', array( __CLASS__, 'contact' ) );
	}

	/** [blnm_contact]: the Settings contact email as a mailto link, every character written as an entity. */
	public static function contact( $atts ) {
		$email = Settings::contact_email();
		if ( '' === $email ) {
			return '';
		}
		$a    = shortcode_atts( array( 'text' => '' ), $atts, 'blnm_contact' );
		$enc  = static function ( $str ) {
			$o = '';
			foreach ( str_split( $str ) as $ch ) { // sanitize_email output is ASCII.
				$o .= '&#' . ord( $ch ) . ';';
			}
			return $o;
		};
		$text = '' !== $a['text'] ? esc_html( $a['text'] ) : $enc( $email );
		return '<a class="blnm-contact" href="' . $enc( 'mailto:' ) . $enc( $email ) . '">' . $text . '</a>';
	}

	public static function city_search( $atts ) {
		static $n = 0;
		++$n;
		$id = 'blnm-cs-' . $n;
		$a  = shortcode_atts(
			array(
				'heading'     => __( 'Find a lender in your city', 'dos-best-lenders' ),
				'placeholder' => __( 'Type a city (for example Kennewick)', 'dos-best-lenders' ),
				'button'      => __( 'Search', 'dos-best-lenders' ),
			),
			$atts,
			'blnm_city_search'
		);
		Frontend::enqueue();

		ob_start();
		?>
<section class="blnm blnm-search" data-api="<?php echo esc_url( add_query_arg( 'v', Rest::index_ver(), rest_url( Rest::NAMESPACE_V1 . '/cities' ) ) ); ?>">
<h2 class="blnm-search-heading"><?php echo esc_html( $a['heading'] ); ?></h2>
<form class="blnm-search-form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" autocomplete="off">
<input type="hidden" name="post_type" value="<?php echo esc_attr( Data_Model::CITY ); ?>">
<label class="blnm-sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'City name', 'dos-best-lenders' ); ?></label>
<div class="blnm-search-wrap">
<input id="<?php echo esc_attr( $id ); ?>" name="s" type="text" placeholder="<?php echo esc_attr( $a['placeholder'] ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-list" spellcheck="false" autocapitalize="words" enterkeyhint="go">
<ul id="<?php echo esc_attr( $id ); ?>-list" class="blnm-search-list" role="listbox" hidden></ul>
</div>
<button type="submit" class="blnm-search-btn"><?php echo esc_html( $a['button'] ); ?></button>
</form>
<p class="blnm-search-msg" role="status" aria-live="polite"></p>
</section>
		<?php
		return ob_get_clean();
	}

	/** Cities for one state (state="WA"), or with no state a list of state names linking to each state page. */
	public static function state_index( $atts ) {
		$a     = shortcode_atts( array( 'state' => '' ), $atts, 'blnm_state_index' );
		$state = Data_Model::sanitize_state( $a['state'] );
		Frontend::enqueue();

		if ( '' === $state ) {
			$states = Rest::states();
			if ( ! $states ) {
				return '<p class="blnm blnm-empty">' . esc_html__( 'No state pages are published yet.', 'dos-best-lenders' ) . '</p>';
			}
			$out = '<div class="blnm blnm-states"><ul class="blnm-city-list blnm-state-list">';
			foreach ( $states as $s ) {
				$out .= '<li><a href="' . esc_url( $s['u'] ) . '">' . esc_html( $s['name'] ) . '</a> <span class="blnm-state-count">'
					. esc_html( sprintf( /* translators: %s: number of cities */ _n( '%s city', '%s cities', $s['n'], 'dos-best-lenders' ), number_format_i18n( $s['n'] ) ) )
					. '</span></li>';
			}
			return $out . '</ul></div>';
		}

		$hub = self::hub( $state );
		if ( '' !== $hub ) {
			return $hub;
		}
		return '<p class="blnm blnm-empty">' . esc_html__( 'No city pages are published yet.', 'dos-best-lenders' ) . '</p>';
	}

	/** Sanitized hub filters from the query string: q (city name) and has (1). Old ?county= and ?sort= are ignored. */
	public static function hub_request( array $src ) {
		$q = isset( $src['q'] ) && is_scalar( $src['q'] ) ? Data_Model::plain_text( wp_unslash( (string) $src['q'] ), 80 ) : '';
		$h = ! empty( $src['has'] ) && '0' !== $src['has'];
		return array( 'q' => $q, 'has' => $h );
	}

	/**
	 * The state hub: optional hero, one stats line, filters, and a grid of city cards (map tile, name, county,
	 * lender count, top lenders), always by population. The server filters from the query string, so it works without JS and
	 * the count is right on load; blnm.js then filters in place. Every card is in the HTML (non-matches are
	 * `hidden`) so crawlers see all links. Returns '' when the state has no published cities.
	 */
	public static function hub( $state ) {
		$rows = Rest::hub_index( $state );
		if ( ! $rows ) {
			return '';
		}
		static $n = 0;
		$uid = 'blnm-hub-' . ++$n; // ids stay unique when two hubs share a page
		$req        = self::hub_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$state_name = Rest::STATE_NAMES[ $state ] ?? $state;
		$qn         = Search::norm( $req['q'] );

		$counties = array();
		$with     = 0;
		$year     = 0;
		foreach ( $rows as $r ) {
			if ( '' !== $r['county'] ) {
				$counties[ $r['county'] ] = ( $counties[ $r['county'] ] ?? 0 ) + 1;
			}
			$with += $r['lenders'] > 0 ? 1 : 0;
			$year  = max( $year, (int) $r['yr'] );
		}
		ksort( $counties, SORT_NATURAL | SORT_FLAG_CASE );

		usort( $rows, static function ( $a, $b ) {
			return ( $b['pop'] <=> $a['pop'] ) ?: strnatcasecmp( $a['n'], $b['n'] );
		} );
		$visible = static function ( $r ) use ( $req, $qn ) {
			if ( '' !== $qn && false === strpos( Search::norm( $r['n'] ), $qn ) ) {
				return false;
			}
			return ! ( $req['has'] && $r['lenders'] < 1 );
		};
		$shown = 0;
		foreach ( $rows as $r ) {
			$shown += $visible( $r ) ? 1 : 0;
		}

		$stats = sprintf(
			/* translators: 1: cities, 2: counties */
			_n( '%1$s city across %2$s counties', '%1$s cities across %2$s counties', count( $rows ), 'dos-best-lenders' ),
			number_format_i18n( count( $rows ) ),
			number_format_i18n( count( $counties ) )
		);
		$stats .= ' · ' . sprintf( /* translators: %s: number of cities */ __( '%s with listed lenders', 'dos-best-lenders' ), number_format_i18n( $with ) );
		if ( $year ) {
			$stats .= ' · ' . sprintf( /* translators: %d: year */ __( '%d HMDA data', 'dos-best-lenders' ), $year );
		}

		ob_start();
		echo '<div class="blnm blnm-hub" data-blnm-hub>';
		$thumb = function_exists( 'get_queried_object_id' ) ? (int) get_post_thumbnail_id( get_queried_object_id() ) : 0;
		if ( $thumb ) {
			$img = wp_get_attachment_image( $thumb, 'large', false, array( 'class' => 'blnm-hero-img', 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(min-width: 1100px) 1100px, 100vw' ) );
			if ( $img ) {
				echo '<figure class="blnm-hero-fig">' . $img . '</figure>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		echo '<p class="blnm-hub-stats">' . esc_html( $stats ) . '</p>';
		?>
<form class="blnm-filters blnm-hub-filters" method="get" action="">
<div class="blnm-f blnm-f-q"><label for="<?php echo esc_attr( $uid ); ?>-q"><?php esc_html_e( 'City name', 'dos-best-lenders' ); ?></label>
<input id="<?php echo esc_attr( $uid ); ?>-q" name="q" type="search" value="<?php echo esc_attr( $req['q'] ); ?>" placeholder="<?php esc_attr_e( 'Filter by city name...', 'dos-best-lenders' ); ?>" autocomplete="off" spellcheck="false"></div>
<div class="blnm-f blnm-f-has"><label class="blnm-check" for="<?php echo esc_attr( $uid ); ?>-has"><input id="<?php echo esc_attr( $uid ); ?>-has" name="has" type="checkbox" value="1"<?php checked( $req['has'] ); ?>> <?php esc_html_e( 'Has lenders', 'dos-best-lenders' ); ?></label></div>
<div class="blnm-f blnm-f-status">
<p class="blnm-count" role="status" aria-live="polite"><?php echo esc_html( sprintf( _n( 'Showing %s city', 'Showing %s cities', $shown, 'dos-best-lenders' ), number_format_i18n( $shown ) ) ); ?></p>
<span class="blnm-hub-actions"><noscript><button type="submit" class="blnm-more"><?php esc_html_e( 'Apply', 'dos-best-lenders' ); ?></button> </noscript><a class="blnm-reset" href="<?php echo esc_url( remove_query_arg( array( 'q', 'county', 'has', 'sort' ) ) ); ?>"<?php echo ( '' === $req['q'] && ! $req['has'] ) ? ' hidden' : ''; ?>><?php esc_html_e( 'Reset', 'dos-best-lenders' ); ?></a></span>
</div>
</form>
<h2 class="blnm-sr"><?php echo esc_html( sprintf( /* translators: %s: state name */ __( 'Cities in %s', 'dos-best-lenders' ), $state_name ) ); ?></h2>
<ul class="blnm-hub-grid">
		<?php
		foreach ( $rows as $r ) {
			$lenders = (int) $r['lenders'];
			$alt     = Maps::alt( $r['n'], $r['county'], $state_name );
			$tile    = Maps::tile_url( $r + array( 'st' => $state, 'state_name' => $state_name, 'alt' => $alt ) );
			if ( $lenders > 1 ) {
				/* translators: %s: number of lenders */
				$line = sprintf( __( '%s lenders listed', 'dos-best-lenders' ), number_format_i18n( $lenders ) );
			} elseif ( 1 === $lenders ) {
				$line = __( '1 lender listed', 'dos-best-lenders' );
			} else {
				$line = __( 'No lenders listed yet · see nearby towns', 'dos-best-lenders' );
			}
			?>
<li class="blnm-hub-card" data-name="<?php echo esc_attr( strtolower( $r['n'] ) ); ?>" data-lenders="<?php echo (int) $lenders; ?>" data-pop="<?php echo (int) $r['pop']; ?>"<?php echo $visible( $r ) ? '' : ' hidden'; ?>>
<a class="blnm-hub-link" href="<?php echo esc_url( $r['u'] ); ?>">
<?php if ( '' !== $tile ) : ?><img class="blnm-hub-img" src="<?php echo esc_url( $tile, array( 'http', 'https', 'data' ) ); ?>" alt="<?php echo esc_attr( $alt ); ?>" width="300" height="200" loading="lazy" decoding="async"><?php endif; ?>
<h3 class="blnm-hub-name"><?php echo esc_html( $r['n'] ); ?></h3>
</a>
<?php if ( '' !== $r['county'] ) : ?><p class="blnm-hub-county"><?php echo esc_html( $r['county'] . ' County' ); ?></p><?php endif; ?>
<p class="blnm-hub-lenders"><?php echo esc_html( $line ); ?></p>
<?php if ( $r['top'] ) : ?><p class="blnm-hub-top"><?php echo esc_html( sprintf( /* translators: %s: lender names */ __( 'Including %s', 'dos-best-lenders' ), Render::join_names( $r['topd'] ?? array_map( array( Render::class, 'display_name' ), $r['top'] ) ) ) ); ?></p><?php endif; ?>
</li>
			<?php
		}
		echo '</ul></div>';
		return ob_get_clean();
	}
}
