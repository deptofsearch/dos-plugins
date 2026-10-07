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
				'placeholder' => __( 'City, ST (for example Kennewick, WA)', 'dos-best-lenders' ),
				'button'      => __( 'Search', 'dos-best-lenders' ),
			),
			$atts,
			'blnm_city_search'
		);
		Frontend::enqueue();

		ob_start();
		?>
<section class="blnm blnm-search" data-api="<?php echo esc_url( rest_url( Rest::NAMESPACE_V1 . '/cities' ) ); ?>">
<h2 class="blnm-search-heading"><?php echo esc_html( $a['heading'] ); ?></h2>
<form class="blnm-search-form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" autocomplete="off">
<input type="hidden" name="post_type" value="<?php echo esc_attr( Data_Model::CITY ); ?>">
<label class="blnm-sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'City and state', 'dos-best-lenders' ); ?></label>
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

	/** Cities for one state, or every state grouped when no state is given. */
	public static function state_index( $atts ) {
		$a     = shortcode_atts( array( 'state' => '' ), $atts, 'blnm_state_index' );
		$state = Data_Model::sanitize_state( $a['state'] );
		Frontend::enqueue();

		$rows = Rest::index();
		if ( '' !== $state ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $state ) {
						return $r['s'] === $state;
					}
				)
			);
		}
		if ( ! $rows ) {
			return '<p class="blnm blnm-empty">' . esc_html__( 'No city pages are published yet.', 'dos-best-lenders' ) . '</p>';
		}

		$by = array();
		foreach ( $rows as $r ) {
			$by[ $r['s'] ][] = $r;
		}
		ksort( $by );

		ob_start();
		echo '<div class="blnm blnm-states">';
		foreach ( $by as $st => $cities ) {
			if ( '' === $state ) {
				echo '<h3 class="blnm-state-name">' . esc_html( $st ) . '</h3>';
			}
			echo '<ul class="blnm-city-list">';
			foreach ( $cities as $c ) {
				echo '<li><a href="' . esc_url( $c['u'] ) . '">' . esc_html( $c['n'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '</div>';
		return ob_get_clean();
	}
}
