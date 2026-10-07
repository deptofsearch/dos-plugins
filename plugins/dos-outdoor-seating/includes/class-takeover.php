<?php
/**
 * Takes over TablePress [table id=N filter="City, ST"] for enabled cities that have venues, and
 * [table filter="Hood, City"] on neighborhood pages (see Hoods) when the parent city is enabled.
 *
 * TablePress registers its `table` shortcode in init_shortcodes() on init at priority 20, so any
 * wrapper of that callback has to win a race it cannot win. Instead we hook pre_do_shortcode_tag,
 * which runs before whatever callback is registered, whenever that happened.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Takeover {

	const OPTION = 'osn_takeover_cities';

	public static function hooks() {
		add_filter( 'pre_do_shortcode_tag', array( __CLASS__, 'maybe_take_over' ), 10, 4 );
		// Last, so we can tell whether TablePress registered `table` (it does so at 20).
		add_action( 'init', array( __CLASS__, 'register_fallback' ), 99 );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'on_option_change' ), 10, 2 );
		add_action(
			'add_option_' . self::OPTION,
			function ( $option, $value ) {
				self::on_option_change( '', $value );
			},
			10,
			2
		);
	}

	/**
	 * @param false|string $return Short-circuit value so far.
	 * @param string       $tag    Shortcode tag.
	 * @param array|string $attr   Attributes.
	 * @param array        $m      Regex match.
	 * @return false|string
	 */
	public static function maybe_take_over( $return, $tag, $attr, $m ) {
		if ( 'table' !== $tag || false !== $return ) {
			return $return;
		}
		$out = self::resolve( $attr );
		return null !== $out ? $out : $return;
	}

	/** Cards for a city filter, else the hood view for a linked neighborhood page, else null. */
	private static function resolve( $atts ) {
		$term = self::match_city( $atts );
		if ( $term ) {
			return Cards::render( $term->name );
		}
		$hood = self::match_hood( $atts );
		if ( $hood ) {
			$city = Hoods::city_term( $hood );
			return Cards::render( $city->name, 0, true, true, $hood );
		}
		return null;
	}

	/**
	 * The hood term when this [table filter="Hood, City"] sits on a page linked to (or named for) a neighborhood
	 * of an enabled city, and the neighborhood has a visible venue. Null otherwise, so TablePress renders as normal.
	 */
	private static function match_hood( $atts ) {
		$atts   = is_array( $atts ) ? $atts : array();
		$filter = isset( $atts['filter'] ) ? trim( (string) $atts['filter'] ) : '';
		$post   = get_post();
		if ( '' === $filter || ! $post ) {
			return null;
		}
		$hood = Hoods::match_page( $post, $filter );
		$city = $hood ? Hoods::city_term( $hood ) : null;
		if ( ! $hood || ! $city || ! Hoods::city_enabled( $city ) || '' !== (string) get_post_meta( $post->ID, Hoods::REDIRECT_KEY, true ) ) {
			return null;
		}
		$ids = Util::sorted_ids( $city->term_id );
		if ( ! $ids ) {
			return null;
		}
		update_object_term_cache( $ids, Data_Model::POST_TYPE );
		foreach ( $ids as $id ) {
			if ( Hoods::venue_in( Hoods::venue_info( $id ), $hood ) ) {
				return $hood;
			}
		}
		return null;
	}

	/** Only when TablePress is gone: [table filter="City, ST"] renders cards for a matching city, else nothing. */
	public static function register_fallback() {
		if ( ! shortcode_exists( 'table' ) ) {
			add_shortcode( 'table', array( __CLASS__, 'fallback' ) );
		}
	}

	public static function fallback( $atts ) {
		$out = self::resolve( $atts );
		return null !== $out ? $out : '';
	}

	/** The city term when the filter names an enabled city that has a visible venue. */
	private static function match_city( $atts ) {
		$atts   = is_array( $atts ) ? $atts : array();
		$filter = isset( $atts['filter'] ) ? trim( (string) $atts['filter'] ) : '';
		if ( '' === $filter ) {
			return null;
		}
		$enabled = self::enabled();
		if ( true !== $enabled && ! in_array( sanitize_title( $filter ), $enabled, true ) ) {
			return null;
		}
		$term = Util::find_city( $filter );
		return ( $term && Util::city_has_venues( $term ) ) ? $term : null;
	}

	/** @return true|string[] true for "all", else city slugs. */
	public static function parse( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '*' === $line ) {
				return true;
			}
			if ( '' !== $line ) {
				$out[] = sanitize_title( $line );
			}
		}
		return $out;
	}

	/** Enabled cities as a list of slugs, or true for "all". */
	public static function enabled() {
		return self::parse( get_option( self::OPTION, '' ) );
	}

	/* ---------------------------------------------------------------- REST */

	/** GET/POST /osn/v1/takeover: read or change the takeover cities and states remotely (manage_options). */
	public static function register_routes() {
		register_rest_route(
			Rest::NAMESPACE_V1,
			'/takeover',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_post' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
					'args'                => array(
						'cities'  => array(
							'description' => 'Array of "City, ST" strings.',
							'type'        => 'array',
						),
						'states'  => array(
							'description' => 'Array of state codes or names (WA, Washington, ...).',
							'type'        => 'array',
						),
						'mode'    => array(
							'description' => '"add" (default), "remove" or "replace".',
							'type'        => 'string',
							'default'     => 'add',
							'enum'        => array( 'add', 'remove', 'replace' ),
						),
						'dry_run' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);
	}

	public static function rest_can() {
		return current_user_can( 'manage_options' );
	}

	/** Stored city list as an array of "City, ST" lines. */
	private static function stored_cities() {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) get_option( self::OPTION, '' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function rest_get() {
		return rest_ensure_response(
			array(
				'cities' => self::stored_cities(),
				'states' => State_Cities::enabled(),
			)
		);
	}

	/**
	 * Apply a list of items to a stored list.
	 *
	 * @param string[] $current Stored items.
	 * @param string[] $given   Requested items (already normalized).
	 * @param string   $mode    add|remove|replace.
	 * @return array { list, added, removed }
	 */
	private static function apply( array $current, array $given, $mode ) {
		$lc = function ( $v ) {
			return strtolower( $v );
		};
		$given_keys   = array_map( $lc, $given );
		$current_keys = array_map( $lc, $current );
		$list         = array();
		$added        = array();
		$removed      = array();
		if ( 'replace' === $mode ) {
			foreach ( $given as $g ) {
				if ( ! isset( $list[ $lc( $g ) ] ) ) {
					$list[ $lc( $g ) ] = $g;
				}
			}
			foreach ( $list as $k => $v ) {
				if ( ! in_array( $k, $current_keys, true ) ) {
					$added[] = $v;
				}
			}
			foreach ( $current as $c ) {
				if ( ! isset( $list[ $lc( $c ) ] ) ) {
					$removed[] = $c;
				}
			}
			return array( 'list' => array_values( $list ), 'added' => $added, 'removed' => $removed );
		}
		foreach ( $current as $c ) {
			if ( 'remove' === $mode && in_array( $lc( $c ), $given_keys, true ) ) {
				$removed[] = $c;
				continue;
			}
			$list[ $lc( $c ) ] = $c;
		}
		if ( 'add' === $mode ) {
			foreach ( $given as $g ) {
				if ( ! isset( $list[ $lc( $g ) ] ) ) {
					$list[ $lc( $g ) ] = $g;
					$added[]           = $g;
				}
			}
		}
		return array( 'list' => array_values( $list ), 'added' => $added, 'removed' => $removed );
	}

	public static function rest_post( \WP_REST_Request $request ) {
		$mode    = (string) $request->get_param( 'mode' );
		$mode    = in_array( $mode, array( 'add', 'remove', 'replace' ), true ) ? $mode : 'add';
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$cities  = $request->get_param( 'cities' );
		$states  = $request->get_param( 'states' );

		$rejected = array();
		$added    = array();
		$removed  = array();

		$current_cities = self::stored_cities();
		$new_cities     = $current_cities;
		if ( is_array( $cities ) ) {
			$given = array();
			foreach ( $cities as $c ) {
				$c = is_scalar( $c ) ? sanitize_text_field( (string) $c ) : '';
				if ( preg_match( '/^.+, [A-Z]{2}$/', $c ) ) {
					$given[] = $c;
				} else {
					$rejected[] = $c;
				}
			}
			$res        = self::apply( $current_cities, $given, $mode );
			$new_cities = $res['list'];
			$added      = $res['added'];
			$removed    = $res['removed'];
		}

		$current_states = State_Cities::enabled();
		$new_states     = $current_states;
		if ( is_array( $states ) ) {
			$given = array();
			foreach ( $states as $s ) {
				$s    = is_scalar( $s ) ? trim( (string) $s ) : '';
				$code = strtoupper( $s );
				if ( ! States::has( $code ) ) {
					$code = array_search( strtolower( $s ), array_map( 'strtolower', States::names() ), true );
				}
				if ( false !== $code && States::has( $code ) ) {
					$given[] = $code;
				} else {
					$rejected[] = $s;
				}
			}
			$res        = self::apply( $current_states, $given, $mode );
			$new_states = $res['list'];
			$added      = array_merge( $added, $res['added'] );
			$removed    = array_merge( $removed, $res['removed'] );
		}

		if ( ! $dry_run ) {
			// The option hooks purge page caches (on_option_change here and in State_Cities).
			if ( $new_cities !== $current_cities ) {
				update_option( self::OPTION, implode( "\n", $new_cities ) );
			}
			if ( $new_states !== $current_states ) {
				update_option( State_Cities::OPTION, $new_states );
			}
		}

		return rest_ensure_response(
			array(
				'mode'         => $mode,
				'dry_run'      => $dry_run,
				'added'        => $added,
				'removed'      => $removed,
				'rejected'     => $rejected,
				'cities_total' => count( $new_cities ),
				'states'       => $new_states,
			)
		);
	}

	/** A city flipped on or off: purge its page caches so the landing page re-renders. */
	public static function on_option_change( $old, $new ) {
		$old_set = self::parse( $old );
		$new_set = self::parse( $new );
		if ( true === $old_set || true === $new_set ) {
			if ( $old_set === $new_set ) {
				return;
			}
			$slugs = null; // Everything may have flipped.
		} else {
			$slugs = array_merge( array_diff( $old_set, $new_set ), array_diff( $new_set, $old_set ) );
			if ( ! $slugs ) {
				return;
			}
		}
		$ids = array();
		$all = get_terms(
			array(
				'taxonomy'   => Data_Model::CITY,
				'hide_empty' => false,
				'fields'     => 'id=>slug',
			)
		);
		foreach ( is_wp_error( $all ) ? array() : $all as $term_id => $slug ) {
			if ( null === $slugs || in_array( $slug, $slugs, true ) ) {
				$ids[] = (int) $term_id;
			}
		}
		Repository::purge_cities( $ids );
	}
}
