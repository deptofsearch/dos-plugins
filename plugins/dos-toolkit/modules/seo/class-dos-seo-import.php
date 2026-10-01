<?php
/**
 * Reading titles and descriptions out of Yoast SEO and All in One SEO.
 *
 * Both plugins store a template, not a title: "%%title%% %%sep%% %%sitename%%"
 * in Yoast, "#post_title #separator_sa #site_title" in AIOSEO. Copying that
 * string across verbatim would put the literal variables in front of search
 * engines, so each is resolved against the post first. This class holds only
 * the parsing and resolution — nothing here touches WordPress — so it can be
 * tested without it. The module reads the data and writes the result.
 *
 * Anything it cannot resolve is stripped rather than left in, and reported, so
 * a person can look at that post. A visible "%%cf_subtitle%%" in a title tag
 * is worse than a title missing a clause.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_SEO_Import {

	/**
	 * Yoast stores the separator as a key, not as the character.
	 */
	const YOAST_SEPARATORS = array(
		'sc-dash'     => '-',
		'sc-pipe'     => '|',
		'sc-ndash'    => '–',
		'sc-mdash'    => '—',
		'sc-middot'   => '·',
		'sc-bull'     => '•',
		'sc-star'     => '*',
		'sc-smaller'  => '<',
		'sc-greater'  => '>',
		'sc-tilde'    => '~',
		'sc-raquo'    => '»',
		'sc-laquo'    => '«',
	);

	/**
	 * AIOSEO tags that are real but that this importer does not resolve. They
	 * are matched so they can be stripped and reported; a hashtag in a title
	 * that merely looks like a tag is not in this list and is left alone.
	 */
	const AIOSEO_UNSUPPORTED = array(
		'post_excerpt_only', 'post_content', 'post_date', 'post_day', 'post_month',
		'post_year', 'permalink', 'post_link', 'site_link', 'author_link',
		'author_name', 'author_first_name', 'author_last_name', 'author_bio',
		'tagline', 'current_date', 'current_day', 'current_month', 'current_year',
		'page_number', 'archive_title', 'alt_tag', 'image_title', 'categories_all',
	);

	/* ---------------------------------------------------------------------
	 * Separators
	 * ------------------------------------------------------------------- */

	/**
	 * The character for a Yoast separator key. Anything unrecognised, or an
	 * option that was never saved, falls back to a plain hyphen — Yoast's own
	 * default.
	 */
	public static function yoast_separator( $key ) {
		$key = (string) $key;

		return isset( self::YOAST_SEPARATORS[ $key ] ) ? self::YOAST_SEPARATORS[ $key ] : '-';
	}

	/**
	 * AIOSEO keeps its settings as one JSON string, with the separator at
	 * searchAppearance.global.separator, entity-encoded ("&#45;", "&middot;").
	 * Takes the raw option, decoded or not. Falls back to a hyphen whenever the
	 * value is missing or does not look like a single separator.
	 */
	public static function aioseo_separator( $option ) {
		if ( is_string( $option ) ) {
			$option = json_decode( $option, true );
		}

		$raw = is_array( $option ) && isset( $option['searchAppearance']['global']['separator'] )
			? $option['searchAppearance']['global']['separator']
			: '';

		if ( ! is_string( $raw ) ) {
			return '-';
		}

		$sep = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );

		return '' !== $sep && mb_strlen( $sep ) <= 2 ? $sep : '-';
	}

	/* ---------------------------------------------------------------------
	 * Resolving
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $template Raw Yoast title or description.
	 * @param array  $vars     title, sitename, sep, excerpt, category — all
	 *                         plain text, any of which may be ''.
	 * @return array text, and unresolved (the variables that were stripped).
	 */
	public static function resolve_yoast( $template, array $vars ) {
		$vars = self::vars( $vars );
		$map  = array(
			'title'            => $vars['title'],
			'sitename'         => $vars['sitename'],
			'sep'              => $vars['sep'],
			'excerpt'          => $vars['excerpt'],
			'category'         => $vars['category'],
			'primary_category' => $vars['category'],
			// Yoast prints "Page 2 of 5" on paginated views. A post has no
			// page number of its own, so this is empty here and is not worth
			// a note.
			'page'             => '',
		);

		$unresolved = array();

		$text = preg_replace_callback(
			'/%%([A-Za-z0-9_\-]+)%%/',
			function ( $m ) use ( $map, &$unresolved ) {
				if ( array_key_exists( $m[1], $map ) ) {
					return $map[ $m[1] ];
				}

				$unresolved[] = $m[0];

				return '';
			},
			(string) $template
		);

		return array(
			'text'       => self::tidy( $text ),
			'unresolved' => array_values( array_unique( $unresolved ) ),
		);
	}

	/**
	 * @see resolve_yoast() — same shape, with AIOSEO's smart tags.
	 */
	public static function resolve_aioseo( $template, array $vars ) {
		$vars = self::vars( $vars );
		$map  = array(
			'post_title'     => $vars['title'],
			'site_title'     => $vars['sitename'],
			'separator_sa'   => $vars['sep'],
			'post_excerpt'   => $vars['excerpt'],
			'taxonomy_title' => $vars['category'],
			'categories'     => $vars['category'],
		);

		// Longest first, so #post_excerpt_only is not read as #post_excerpt
		// followed by "_only". #custom_field-name and #tax_name-name carry a
		// suffix naming what to read; both are unsupported.
		$names = array_merge( array_keys( $map ), self::AIOSEO_UNSUPPORTED );
		usort( $names, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		$pattern = '/#(' . implode( '|', array_map( 'preg_quote', $names ) ) . '|(?:custom_field|tax_name)-[A-Za-z0-9_\-]+)(?![A-Za-z0-9_])/';

		$unresolved = array();

		$text = preg_replace_callback(
			$pattern,
			function ( $m ) use ( $map, &$unresolved ) {
				if ( array_key_exists( $m[1], $map ) ) {
					return $map[ $m[1] ];
				}

				$unresolved[] = $m[0];

				return '';
			},
			(string) $template
		);

		return array(
			'text'       => self::tidy( $text ),
			'unresolved' => array_values( array_unique( $unresolved ) ),
		);
	}

	private static function vars( array $vars ) {
		return array_merge(
			array(
				'title'    => '',
				'sitename' => '',
				'sep'      => '-',
				'excerpt'  => '',
				'category' => '',
			),
			$vars
		);
	}

	/**
	 * Tags are not stripped here: "<" is one of Yoast's separators, and
	 * strip_tags() would eat everything after it. The caller hands in
	 * variable values that are already plain text.
	 *
	 * Collapse whitespace and remove separators left with nothing on one side.
	 *
	 * Only a separator standing on its own between spaces counts. The hyphen
	 * in "Tri-Cities" is part of a word and is left alone.
	 */
	public static function tidy( $text ) {
		$text   = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
		$tokens = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$out    = array();

		foreach ( $tokens as $token ) {
			if ( self::is_separator( $token ) ) {
				// Nothing before it, or another separator already there.
				if ( ! $out || self::is_separator( end( $out ) ) ) {
					continue;
				}
			}

			$out[] = $token;
		}

		while ( $out && self::is_separator( end( $out ) ) ) {
			array_pop( $out );
		}

		return implode( ' ', $out );
	}

	private static function is_separator( $token ) {
		return 1 === preg_match( '/^[-|–—·•*<>~»«]$/u', $token );
	}
}
