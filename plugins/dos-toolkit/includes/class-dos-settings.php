<?php
/**
 * One option row for the whole plugin, plus per-site helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Settings {

	const OPTION = 'dos_toolkit_settings';

	private static $cache = null;

	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = is_array( $stored ) ? $stored : array();
		}

		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;

		self::$cache = $all;

		update_option( self::OPTION, $all, false );
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );

		self::$cache = $all;

		update_option( self::OPTION, $all, false );
	}

	public static function delete( $key ) {
		$all = self::all();

		unset( $all[ $key ] );

		self::$cache = $all;

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Global default for batch jobs. Individual runs can override it, but a
	 * fresh install reports before it changes anything.
	 */
	public static function dry_run_default() {
		return (bool) self::get( 'dry_run_default', 1 );
	}

	public static function capability() {
		return apply_filters( 'dos_toolkit_capability', 'manage_options' );
	}
}
