<?php
/**
 * Category icons for the no-photo card band. Inline SVG, so no extra requests.
 *
 * The icon shapes are from Lucide (https://lucide.dev), ISC License, Copyright (c) Lucide Icons and Contributors.
 * Permission to use, copy, modify, and/or distribute the icons for any purpose with or without fee is granted,
 * provided the copyright notice and permission notice appear in all copies. See LICENSES.txt.
 *
 * @package OSN
 */

namespace OSN;

defined( 'ABSPATH' ) || exit;

final class Icons {

	/** Lucide icon name => inner SVG elements (24x24 viewBox, stroke-based). */
	const SHAPES = array(
		'pizza' => '<path d="m12 14-1 1"/><path d="m13.75 18.25-1.25 1.42"/><path d="M17.775 5.654a15.68 15.68 0 0 0-12.121 12.12"/><path d="M18.8 9.3a1 1 0 0 0 2.1 7.7"/><path d="M21.964 20.732a1 1 0 0 1-1.232 1.232l-18-5a1 1 0 0 1-.695-1.232A19.68 19.68 0 0 1 15.732 2.037a1 1 0 0 1 1.232.695z"/>',
		'utensils' => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
		'hamburger' => '<path d="M12 16H4a2 2 0 1 1 0-4h16a2 2 0 1 1 0 4h-4.25"/><path d="M5 12a2 2 0 0 1-2-2 9 7 0 0 1 18 0 2 2 0 0 1-2 2"/><path d="M5 16a2 2 0 0 0-2 2 3 3 0 0 0 3 3h12a3 3 0 0 0 3-3 2 2 0 0 0-2-2q0 0 0 0"/><path d="m6.67 12 6.13 4.6a2 2 0 0 0 2.8-.4l3.15-4.2"/>',
		'coffee' => '<path d="M10 2v2"/><path d="M14 2v2"/><path d="M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1"/><path d="M6 2v2"/>',
		'beer' => '<path d="M17 11h1a3 3 0 0 1 0 6h-1"/><path d="M9 12v6"/><path d="M13 12v6"/><path d="M14 7.5c-1 0-1.44.5-3 .5s-2-.5-3-.5-1.72.5-2.5.5a2.5 2.5 0 0 1 0-5c.78 0 1.57.5 2.5.5S9.44 2 11 2s2 1.5 3 1.5 1.72-.5 2.5-.5a2.5 2.5 0 0 1 0 5c-.78 0-1.5-.5-2.5-.5Z"/><path d="M5 8v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V8"/>',
		'wine' => '<path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/>',
		'martini' => '<path d="M8 22h8"/><path d="M12 11v11"/><path d="m19 3-7 8-7-8Z"/>',
		'fish' => '<path d="M6.5 12c.94-3.46 4.94-6 8.5-6 3.56 0 6.06 2.54 7 6-.94 3.47-3.44 6-7 6s-7.56-2.53-8.5-6Z"/><path d="M18 12v.5"/><path d="M16 17.93a9.77 9.77 0 0 1 0-11.86"/><path d="M7 10.67C7 8 5.58 5.97 2.73 5.5c-1 1.5-1 5 .23 6.5-1.24 1.5-1.24 5-.23 6.5C5.58 18.03 7 16 7 13.33"/><path d="M10.46 7.26C10.2 5.88 9.17 4.24 8 3h5.8a2 2 0 0 1 1.98 1.67l.23 1.4"/><path d="m16.01 17.93-.23 1.4A2 2 0 0 1 13.8 21H9.5a5.96 5.96 0 0 0 1.49-3.98"/>',
		'soup' => '<path d="M12 21a9 9 0 0 0 9-9H3a9 9 0 0 0 9 9Z"/><path d="M7 21h10"/><path d="M19.5 12 22 6"/><path d="M16.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.73 1.62"/><path d="M11.25 3c.27.1.8.53.74 1.36-.05.83-.93 1.2-.98 2.02-.06.78.33 1.24.72 1.62"/><path d="M6.25 3c.27.1.8.53.75 1.36-.06.83-.93 1.2-1 2.02-.05.78.34 1.24.74 1.62"/>',
		'croissant' => '<path d="M10.2 18H4.774a1.5 1.5 0 0 1-1.352-.97 11 11 0 0 1 .132-6.487"/><path d="M18 10.2V4.774a1.5 1.5 0 0 0-.97-1.352 11 11 0 0 0-6.486.132"/><path d="M18 5a4 3 0 0 1 4 3 2 2 0 0 1-2 2 10 10 0 0 0-5.139 1.42"/><path d="M5 18a3 4 0 0 0 3 4 2 2 0 0 0 2-2 10 10 0 0 1 1.42-5.14"/><path d="M8.709 2.554a10 10 0 0 0-6.155 6.155 1.5 1.5 0 0 0 .676 1.626l9.807 5.42a2 2 0 0 0 2.718-2.718l-5.42-9.807a1.5 1.5 0 0 0-1.626-.676"/>',
		'ice-cream-cone' => '<path d="m7 11 4.08 10.35a1 1 0 0 0 1.84 0L17 11"/><path d="M17 7A5 5 0 0 0 7 7"/><path d="M17 7a2 2 0 0 1 0 4H7a2 2 0 0 1 0-4"/>',
		'beef' => '<path d="M16.4 13.7A6.5 6.5 0 1 0 6.28 6.6c-1.1 3.13-.78 3.9-3.18 6.08A3 3 0 0 0 5 18c4 0 8.4-1.8 11.4-4.3"/><path d="m18.5 6 2.19 4.5a6.48 6.48 0 0 1-2.29 7.2C15.4 20.2 11 22 7 22a3 3 0 0 1-2.68-1.66L2.4 16.5"/><circle cx="12.5" cy="8.5" r="2.5"/>',
		'utensils-crossed' => '<path d="m16 2-2.3 2.3a3 3 0 0 0 0 4.2l1.8 1.8a3 3 0 0 0 4.2 0L22 8"/><path d="M15 15 3.3 3.3a4.2 4.2 0 0 0 0 6l7.3 7.3c.7.7 2 .7 2.8 0L15 15Zm0 0 7 7"/><path d="m2.1 21.8 6.4-6.3"/><path d="m19 5-7 7"/>',
	);

	/** Tint palette: low-saturation background + a darker shade of the same hue for the icon. */
	const TINTS = array(
		'terracotta' => array( '#f8e7de', '#9f4a2c' ),
		'olive'      => array( '#eef0df', '#5b6a2b' ),
		'amber'      => array( '#fbf0d6', '#8a5c0c' ),
		'teal'       => array( '#ddf0ee', '#25686a' ),
		'plum'       => array( '#f1e5ef', '#6d3b69' ),
		'slate'      => array( '#e6eaef', '#44546a' ),
		'neutral'    => array( '#efebe6', '#7b6f65' ),
	);

	/** Icon => tint name. */
	const ICON_TINT = array(
		'pizza'            => 'terracotta',
		'hamburger'        => 'terracotta',
		'beef'             => 'terracotta',
		'utensils'         => 'olive',
		'coffee'           => 'amber',
		'beer'             => 'amber',
		'croissant'        => 'amber',
		'fish'             => 'teal',
		'soup'             => 'teal',
		'wine'             => 'plum',
		'martini'          => 'plum',
		'ice-cream-cone'   => 'plum',
		'utensils-crossed' => 'slate',
	);

	/** Ordered rules, first match wins: icon => case-insensitive regex over category + types. */
	const RULES = array(
		'pizza'          => '/pizza/',
		'utensils-mex'   => '/mexican|taco|tex-mex|burrito/',
		'hamburger'      => '/burger|hamburger/',
		'beef'           => '/steak|barbecue|bbq|chophouse|\bbeef\b/',
		'coffee'         => '/coffee|cafe|caf\x{e9}|espresso/u',
		'beer'           => '/brewery|brewpub|\bbeer\b|\bpub\b|tavern|taproom/',
		'wine'           => '/\bwine/',
		'martini'        => '/cocktail|\bbar\b|lounge/',
		'fish-asian'     => '/sushi|japanese|asian|thai|chinese|vietnamese|ramen|korean/',
		'fish'           => '/seafood|\bfish\b|oyster/',
		'croissant'      => '/breakfast|brunch|bakery|pastry/',
		'ice-cream-cone' => '/ice cream|dessert|gelato|frozen yogurt/',
		'utensils-ita'   => '/italian|pasta/',
	);

	/** Rule key => real icon (a few rules share a shape). */
	const ALIAS = array(
		'utensils-mex' => 'utensils',
		'utensils-ita' => 'utensils',
		'fish-asian'   => 'soup',
	);

	/** Icon name for a category and Maps types, '' text falls back to the default. */
	public static function name_for( $category, array $types = array() ) {
		$text = Util::lower( trim( $category . ' ' . implode( ' ', $types ) ) );
		foreach ( self::RULES as $key => $re ) {
			if ( preg_match( $re, $text ) ) {
				return self::ALIAS[ $key ] ?? $key;
			}
		}
		return 'utensils-crossed';
	}

	/** Inline SVG markup (trusted constants). */
	public static function svg( $name, $size = 40 ) {
		$shape = self::SHAPES[ $name ] ?? self::SHAPES['utensils-crossed'];
		return '<svg class="osn-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $shape . '</svg>';
	}

	/** @return string[] array( background, foreground ) hex pair for an icon. */
	public static function tint( $name ) {
		$t = self::ICON_TINT[ $name ] ?? 'slate';
		return self::TINTS[ $t ];
	}

	/** The whole no-photo band content: inline style vars + svg. */
	public static function band_style( $name ) {
		$t = self::tint( $name );
		return '--osn-band-bg:' . $t[0] . ';--osn-band-fg:' . $t[1] . ';';
	}

	/** Neutral tint for state city cards that have no photo. */
	public static function neutral_style() {
		$t = self::TINTS['neutral'];
		return '--osn-band-bg:' . $t[0] . ';--osn-band-fg:' . $t[1] . ';';
	}
}
