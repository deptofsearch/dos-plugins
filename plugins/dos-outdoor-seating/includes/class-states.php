<?php
/**
 * The one table of US states (50 + DC): code, name, page slug, primary IANA timezone.
 *
 * Everything that needs a state name, state page slug or fallback timezone reads it from here.
 * The original six launch states come first so iteration order is unchanged for them.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class States {

	/** code => array( name, IANA timezone covering the most population ). */
	const TABLE = array(
		'WA' => array( 'Washington', 'America/Los_Angeles' ),
		'OR' => array( 'Oregon', 'America/Los_Angeles' ),
		'CA' => array( 'California', 'America/Los_Angeles' ),
		'ID' => array( 'Idaho', 'America/Boise' ),
		'AZ' => array( 'Arizona', 'America/Phoenix' ),
		'NV' => array( 'Nevada', 'America/Los_Angeles' ),
		'AL' => array( 'Alabama', 'America/Chicago' ),
		'AK' => array( 'Alaska', 'America/Anchorage' ),
		'AR' => array( 'Arkansas', 'America/Chicago' ),
		'CO' => array( 'Colorado', 'America/Denver' ),
		'CT' => array( 'Connecticut', 'America/New_York' ),
		'DE' => array( 'Delaware', 'America/New_York' ),
		'DC' => array( 'District of Columbia', 'America/New_York' ),
		'FL' => array( 'Florida', 'America/New_York' ),
		'GA' => array( 'Georgia', 'America/New_York' ),
		'HI' => array( 'Hawaii', 'Pacific/Honolulu' ),
		'IL' => array( 'Illinois', 'America/Chicago' ),
		'IN' => array( 'Indiana', 'America/Indiana/Indianapolis' ),
		'IA' => array( 'Iowa', 'America/Chicago' ),
		'KS' => array( 'Kansas', 'America/Chicago' ),
		'KY' => array( 'Kentucky', 'America/New_York' ),
		'LA' => array( 'Louisiana', 'America/Chicago' ),
		'ME' => array( 'Maine', 'America/New_York' ),
		'MD' => array( 'Maryland', 'America/New_York' ),
		'MA' => array( 'Massachusetts', 'America/New_York' ),
		'MI' => array( 'Michigan', 'America/Detroit' ),
		'MN' => array( 'Minnesota', 'America/Chicago' ),
		'MS' => array( 'Mississippi', 'America/Chicago' ),
		'MO' => array( 'Missouri', 'America/Chicago' ),
		'MT' => array( 'Montana', 'America/Denver' ),
		'NE' => array( 'Nebraska', 'America/Chicago' ),
		'NH' => array( 'New Hampshire', 'America/New_York' ),
		'NJ' => array( 'New Jersey', 'America/New_York' ),
		'NM' => array( 'New Mexico', 'America/Denver' ),
		'NY' => array( 'New York', 'America/New_York' ),
		'NC' => array( 'North Carolina', 'America/New_York' ),
		'ND' => array( 'North Dakota', 'America/Chicago' ),
		'OH' => array( 'Ohio', 'America/New_York' ),
		'OK' => array( 'Oklahoma', 'America/Chicago' ),
		'PA' => array( 'Pennsylvania', 'America/New_York' ),
		'RI' => array( 'Rhode Island', 'America/New_York' ),
		'SC' => array( 'South Carolina', 'America/New_York' ),
		'SD' => array( 'South Dakota', 'America/Chicago' ),
		'TN' => array( 'Tennessee', 'America/Chicago' ),
		'TX' => array( 'Texas', 'America/Chicago' ),
		'UT' => array( 'Utah', 'America/Denver' ),
		'VT' => array( 'Vermont', 'America/New_York' ),
		'VA' => array( 'Virginia', 'America/New_York' ),
		'WV' => array( 'West Virginia', 'America/New_York' ),
		'WI' => array( 'Wisconsin', 'America/Chicago' ),
		'WY' => array( 'Wyoming', 'America/Denver' ),
	);

	/** @return array<string,string> code => name. */
	public static function names() {
		$out = array();
		foreach ( self::TABLE as $code => $row ) {
			$out[ $code ] = $row[0];
		}
		return $out;
	}

	/** @return string[] All state codes. */
	public static function all() {
		return array_keys( self::TABLE );
	}

	public static function has( $code ) {
		return is_string( $code ) && isset( self::TABLE[ $code ] );
	}

	/** Name for a code, '' when unknown. */
	public static function name( $code ) {
		return self::TABLE[ $code ][0] ?? '';
	}

	/** Page slug ("new-mexico") for a code, '' when unknown. */
	public static function slug( $code ) {
		return isset( self::TABLE[ $code ] ) ? strtolower( str_replace( ' ', '-', self::TABLE[ $code ][0] ) ) : '';
	}

	/** Primary IANA timezone for a code, '' when unknown. */
	public static function tz( $code ) {
		return self::TABLE[ $code ][1] ?? '';
	}

	/** Code for a page slug, '' when unknown. */
	public static function from_slug( $slug ) {
		$slug = strtolower( (string) $slug );
		foreach ( self::TABLE as $code => $row ) {
			if ( self::slug( $code ) === $slug ) {
				return $code;
			}
		}
		return '';
	}

	/** @return array<string,string> slug => name. */
	public static function slug_map() {
		$out = array();
		foreach ( self::TABLE as $code => $row ) {
			$out[ self::slug( $code ) ] = $row[0];
		}
		return $out;
	}

	/** @return array<string,string> code => timezone. */
	public static function tz_map() {
		$out = array();
		foreach ( self::TABLE as $code => $row ) {
			$out[ $code ] = $row[1];
		}
		return $out;
	}

	/** Slug => state name map for state landing pages. Filter: osn_state_pages. */
	public static function pages() {
		return (array) apply_filters( 'osn_state_pages', self::slug_map() );
	}
}
