<?php
/**
 * Shared helpers: icons, post-meta access, Works poster + card markup.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dos_arrow_svg( $class = 'dos-icon' ) {
	return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="square" aria-hidden="true"><path d="M5 12l14 0"></path><path d="M13 18l6 -6"></path><path d="M13 6l6 6"></path></svg>';
}

function dos_work_meta( $post_id, $key ) {
	$v = get_post_meta( $post_id, $key, true );
	return is_string( $v ) ? trim( $v ) : '';
}

function dos_work_stack( $post_id ) {
	$raw = dos_work_meta( $post_id, 'dos_stack' );
	if ( '' === $raw ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
}

function dos_work_is_featured( $post_id ) {
	$v = get_post_meta( $post_id, 'dos_featured', true );
	return in_array( $v, array( true, 1, '1', 'true', 'yes', 'on' ), true );
}

/** "Client · Year" kicker line. */
function dos_work_kicker( $post_id ) {
	$parts = array_filter( array( dos_work_meta( $post_id, 'dos_client' ), dos_work_meta( $post_id, 'dos_year' ) ) );
	return implode( ' · ', $parts );
}

function dos_work_no_label( $post_id ) {
	$no = dos_work_meta( $post_id, 'dos_case_no' );
	return '' === $no ? 'WORK' : 'WORK NO. ' . $no;
}

/** Poster: featured image if the post has one, otherwise one of the six WPA illustrations. */
function dos_work_poster( $post_id ) {
	$no_label = '<span class="dos-work__no">' . esc_html( dos_work_no_label( $post_id ) ) . '</span>';
	if ( has_post_thumbnail( $post_id ) ) {
		$img = get_the_post_thumbnail( $post_id, 'dos-poster', array( 'alt' => '', 'loading' => 'lazy' ) );
		return '<div class="dos-work__poster">' . $img . $no_label . '</div>';
	}
	$svgs = dos_poster_svgs();
	$n    = (int) preg_replace( '/\D/', '', dos_work_meta( $post_id, 'dos_case_no' ) );
	$idx  = $n > 0 ? ( $n - 1 ) % count( $svgs ) : $post_id % count( $svgs );
	return '<div class="dos-work__poster">' . $svgs[ $idx ] . $no_label . '</div>';
}

function dos_work_tags_html( $post_id, $with_status = true ) {
	$out   = '';
	$stack = dos_work_stack( $post_id );
	foreach ( $stack as $i => $tag ) {
		$out .= '<span class="dos-tag' . ( 0 === $i ? ' dos-tag--navy' : '' ) . '">' . esc_html( $tag ) . '</span>';
	}
	$status = dos_work_meta( $post_id, 'dos_status' );
	if ( $with_status && '' !== $status && 'live' === strtolower( $status ) ) {
		$out .= '<span class="dos-tag dos-tag--live">' . esc_html( $status ) . '</span>';
	}
	return $out;
}

function dos_work_summary( $post, $words = 24 ) {
	$text = has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
	return wp_trim_words( $text, $words, '…' );
}

/** One Work card (exact dos-card / ProjectCard markup). */
function dos_work_card( $post, $tag = 'h3' ) {
	$id     = $post->ID;
	$kicker = dos_work_kicker( $id );
	ob_start();
	?>
<a class="dos-card" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
<?php echo dos_work_poster( $id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<div class="dos-card__body"><?php if ( $kicker ) : ?><span class="dos-kicker"><?php echo esc_html( $kicker ); ?></span><?php endif; ?><<?php echo $tag; // phpcs:ignore ?> class="dos-card__title"><?php echo esc_html( get_the_title( $id ) ); ?></<?php echo $tag; // phpcs:ignore ?>><p class="dos-card__text"><?php echo esc_html( dos_work_summary( $post ) ); ?></p><div class="dos-card__meta"><?php echo dos_work_tags_html( $id ); // phpcs:ignore ?></div></div>
</a>
	<?php
	return ob_get_clean();
}

/** One Dispatch row (exact dos-post markup). */
function dos_post_row( $post, $show_cat = false, $tag = 'h3' ) {
	$id   = $post->ID;
	$date = get_the_date( 'M j, Y', $id );
	$sub  = '';
	if ( $show_cat ) {
		if ( 'post' === $post->post_type ) {
			$cats = get_the_category( $id );
			$sub  = $cats ? $cats[0]->name : '';
		} else {
			$obj = get_post_type_object( $post->post_type );
			$sub = $obj ? $obj->labels->singular_name : '';
		}
	}
	$excerpt = wp_trim_words( has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 26, '…' );
	ob_start();
	?>
<a class="dos-post" href="<?php echo esc_url( get_permalink( $id ) ); ?>"><span class="dos-post__date"><?php echo esc_html( $date ); ?><?php if ( $sub ) : ?><br><?php echo esc_html( $sub ); ?><?php endif; ?></span><div><<?php echo $tag; // phpcs:ignore ?> class="dos-post__title"><?php echo esc_html( get_the_title( $id ) ); ?></<?php echo $tag; // phpcs:ignore ?>><p class="dos-post__excerpt"><?php echo esc_html( $excerpt ); ?></p></div></a>
	<?php
	return ob_get_clean();
}

/** Latest Works, featured first. */
function dos_get_works( $limit = -1, $featured_first = true ) {
	$posts = get_posts(
		array(
			'post_type'        => 'dos_work',
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => false,
		)
	);
	if ( $featured_first ) {
		usort(
			$posts,
			static function ( $a, $b ) {
				return (int) dos_work_is_featured( $b->ID ) <=> (int) dos_work_is_featured( $a->ID );
			}
		);
	}
	return $limit > 0 ? array_slice( $posts, 0, $limit ) : $posts;
}

/**
 * Pull Problem / Build / Result paragraphs out of a Work's content (headings named so).
 * Returns array( 'problem' => text, 'build' => text, 'result' => text ), empty strings when absent.
 */
function dos_work_sections( $post ) {
	$out     = array( 'problem' => '', 'build' => '', 'result' => '' );
	$content = $post->post_content;
	if ( preg_match_all( '#<h[2-4][^>]*>\s*(Problem|Build|Result)\s*</h[2-4]>(.*?)(?=<h[2-4]|\z)#is', $content, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $row ) {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', '', $row[2] ) ) ) );
			$out[ strtolower( $row[1] ) ] = wp_trim_words( $text, 40, '…' );
		}
	}
	if ( '' === $out['result'] ) {
		$out['result'] = dos_work_meta( $post->ID, 'dos_result' );
	}
	return $out;
}
