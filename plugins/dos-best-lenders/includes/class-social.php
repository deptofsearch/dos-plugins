<?php
/**
 * Open Graph and Twitter card tags, so shared links and search features get a real image with alt text.
 * The image is the page's featured image when it has one, else the default social image from Settings.
 * Nothing is printed when an SEO plugin is active, since those already write these tags.
 *
 * @package BLNM
 */

namespace BLNM;

defined( 'ABSPATH' ) || exit;

final class Social {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'tags' ), 6 );
	}

	public static function seo_plugin_active() {
		// DoS Toolkit's SEO module writes the same tags (description, canonical, OG/Twitter, schema); defer to it.
		$dos_seo = class_exists( 'DOS_Toolkit' ) && method_exists( 'DOS_Toolkit', 'is_active' ) && \DOS_Toolkit::is_active( 'seo' );
		return $dos_seo || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || function_exists( 'the_seo_framework' );
	}

	/** @return array{url:string,width:int,height:int,alt:string,type:string}|null */
	public static function image_for_request() {
		$id = 0;
		if ( is_singular() && has_post_thumbnail( get_queried_object_id() ) ) {
			$id = (int) get_post_thumbnail_id( get_queried_object_id() );
		}
		if ( ! $id ) {
			$id = absint( Settings::get( 'social_image_id' ) );
		}
		if ( ! $id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src ) {
			return null;
		}
		$alt = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => '' !== $alt ? $alt : (string) get_the_title( $id ),
			'type'   => (string) get_post_mime_type( $id ),
		);
	}

	public static function tags() {
		if ( self::seo_plugin_active() || is_404() || is_search() ) {
			return;
		}
		$url = is_singular() ? (string) wp_get_canonical_url( get_queried_object_id() ) : home_url( add_query_arg( array() ) );
		if ( is_front_page() ) {
			$url = home_url( '/' );
		}
		$meta = array(
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:type'        => 'website',
			'og:locale'      => str_replace( '-', '_', get_bloginfo( 'language' ) ),
			'og:title'       => wp_get_document_title(),
			'og:description' => Frontend::description_for_request(),
			'og:url'         => $url,
		);
		$img = self::image_for_request();
		if ( $img ) {
			$meta['og:image']        = $img['url'];
			$meta['og:image:width']  = $img['width'] ? (string) $img['width'] : '';
			$meta['og:image:height'] = $img['height'] ? (string) $img['height'] : '';
			$meta['og:image:type']   = $img['type'];
			$meta['og:image:alt']    = $img['alt'];
		}
		foreach ( $meta as $prop => $content ) {
			if ( '' !== (string) $content ) {
				printf( "<meta property=\"%s\" content=\"%s\">\n", esc_attr( $prop ), 'og:url' === $prop || 'og:image' === $prop ? esc_url( $content ) : esc_attr( $content ) );
			}
		}
		$tw = array(
			'twitter:card'        => $img ? 'summary_large_image' : 'summary',
			'twitter:title'       => $meta['og:title'],
			'twitter:description' => $meta['og:description'],
			'twitter:image'       => $img ? $img['url'] : '',
			'twitter:image:alt'   => $img ? $img['alt'] : '',
		);
		foreach ( $tw as $name => $content ) {
			if ( '' !== (string) $content ) {
				printf( "<meta name=\"%s\" content=\"%s\">\n", esc_attr( $name ), 'twitter:image' === $name ? esc_url( $content ) : esc_attr( $content ) );
			}
		}
	}
}
