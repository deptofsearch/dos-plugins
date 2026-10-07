<?php
/**
 * Server-rendered blocks. They output the exact design-system markup (dos-card, dos-post, dos-nav)
 * so the dynamic lists match the approved static designs pixel for pixel.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Server-rendered block slugs (without the dos/ namespace) => render callbacks. */
function dos_dept_blocks() {
	return array(
		'nav'             => 'dos_block_nav',
		'home-works'      => 'dos_block_home_works',
		'home-dispatches' => 'dos_block_home_dispatches',
		'works-grid'      => 'dos_block_works_grid',
		'works-case'      => 'dos_block_works_case',
		'dispatch-list'   => 'dos_block_dispatch_list',
		'term-filter'     => 'dos_block_term_filter',
		'entry-kicker'    => 'dos_block_entry_kicker',
		'entry-tags'      => 'dos_block_entry_tags',
		'post-nav'        => 'dos_block_post_nav',
		'work-header'     => 'dos_block_work_header',
		'work-result'     => 'dos_block_work_result',
	);
}

/** Full block names, shared with the editor script. */
function dos_dept_block_names() {
	return array_map(
		static function ( $name ) {
			return 'dos/' . $name;
		},
		array_keys( dos_dept_blocks() )
	);
}

function dos_dept_register_blocks() {
	$blocks = dos_dept_blocks();
	foreach ( $blocks as $name => $cb ) {
		register_block_type(
			'dos/' . $name,
			array(
				'api_version'     => 3,
				'title'           => 'DoS ' . ucwords( str_replace( '-', ' ', $name ) ),
				'category'        => 'theme',
				'render_callback' => $cb,
				'attributes'      => array(
					'featured' => array( 'type' => 'boolean', 'default' => false ),
				),
				'supports'        => array( 'html' => false ),
			)
		);
	}
}
add_action( 'init', 'dos_dept_register_blocks' );

/* ---------- Navigation ---------- */

function dos_nav_current() {
	if ( is_singular( 'dos_work' ) || is_page( 'works' ) ) {
		return 'works';
	}
	if ( is_home() || is_singular( 'post' ) || is_category() || is_tag() || is_page( 'dispatches' ) ) {
		return 'dispatches';
	}
	if ( is_page( 'personnel-file' ) ) {
		return 'personnel-file';
	}
	if ( is_page( 'contact' ) ) {
		return 'contact';
	}
	return '';
}

function dos_block_nav() {
	$cur   = dos_nav_current();
	$links = array(
		'works'          => array( 'Works', '/works/' ),
		'dispatches'     => array( 'Dispatches', '/dispatches/' ),
		'personnel-file' => array( 'Personnel File', '/personnel-file/' ),
		'contact'        => array( 'Contact', '/contact/' ),
	);
	$li = '';
	foreach ( $links as $key => $l ) {
		$li .= '<li><a href="' . esc_url( home_url( $l[1] ) ) . '"' . ( $cur === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $l[0] ) . '</a></li>';
	}
	$logo = get_theme_file_uri( 'assets/logos/' );
	ob_start();
	?>
<nav class="dos-nav" aria-label="<?php esc_attr_e( 'Main', 'dos-department' ); ?>">
<a class="dos-nav__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><img class="dos-logo--light" src="<?php echo esc_url( $logo . 'dos-icon.svg' ); ?>" alt="" width="32" height="32"><img class="dos-logo--dark" src="<?php echo esc_url( $logo . 'dos-icon-reversed.svg' ); ?>" alt="" width="32" height="32"><span>Department of Search</span></a>
<ul class="dos-nav__links"><?php echo $li; // phpcs:ignore ?></ul>
<details class="dos-nav__menu">
<summary><span class="dos-nav__menu-label">Menu</span><span class="dos-nav__menu-icon" aria-hidden="true"></span></summary>
<ul><?php echo $li; // phpcs:ignore ?></ul>
</details>
</nav>
	<?php
	return ob_get_clean();
}

/* ---------- Home sections ---------- */

function dos_block_home_works() {
	$works = dos_get_works( 3, false );
	if ( ! $works ) {
		return '';
	}
	$cards = '';
	foreach ( $works as $w ) {
		$cards .= dos_work_card( $w, 'h3' );
	}
	ob_start();
	?>
<section class="dos-wrap dos-section dos-home-section" id="works">
<div class="dos-section-top">
<header class="dos-section-head">
<span class="dos-kicker dos-kicker--accent">Division 02 · Works</span>
<h2 class="dos-h2">Projects <span class="dos-accent">underway</span></h2>
</header>
<a class="dos-link" href="<?php echo esc_url( home_url( '/works/' ) ); ?>">All works <?php echo dos_arrow_svg(); // phpcs:ignore ?></a>
</div>
<div class="dos-grid-3"><?php echo $cards; // phpcs:ignore ?></div>
</section>
	<?php
	return ob_get_clean();
}

function dos_dispatches_url() {
	$page = get_option( 'page_for_posts' );
	return $page ? get_permalink( $page ) : home_url( '/dispatches/' );
}

function dos_block_home_dispatches() {
	$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 3, 'ignore_sticky_posts' => true ) );
	if ( ! $posts ) {
		return '';
	}
	$rows = '';
	foreach ( $posts as $p ) {
		$rows .= dos_post_row( $p, false, 'h3' );
	}
	ob_start();
	?>
<section class="dos-wrap dos-section dos-home-section dos-home-section--tight" id="dispatches">
<div class="dos-section-top">
<header class="dos-section-head">
<span class="dos-kicker dos-kicker--accent">Division 03 · Dispatches</span>
<h2 class="dos-h2">Field <span class="dos-accent">notes</span></h2>
</header>
<a class="dos-link" href="<?php echo esc_url( dos_dispatches_url() ); ?>">All dispatches <?php echo dos_arrow_svg(); // phpcs:ignore ?></a>
</div>
<div><?php echo $rows; // phpcs:ignore ?></div>
</section>
	<?php
	return ob_get_clean();
}

/* ---------- Works archive ---------- */

function dos_block_works_grid() {
	$works = dos_get_works( -1, true );
	if ( ! $works ) {
		return '<p class="dos-empty">' . esc_html__( 'No works filed yet. Check back soon.', 'dos-department' ) . '</p>';
	}
	$cards = '';
	foreach ( $works as $w ) {
		$cards .= dos_work_card( $w, 'h2' );
	}
	return '<div class="dos-grid-3">' . $cards . '</div>';
}

/** Case-file band for the featured Work (falls back to the newest). */
function dos_block_works_case() {
	$works = dos_get_works( 1, true );
	if ( ! $works ) {
		return '';
	}
	$w    = $works[0];
	$id   = $w->ID;
	$sec  = dos_work_sections( $w );
	$tags = '';
	foreach ( dos_work_stack( $id ) as $i => $t ) {
		$tags .= '<span class="dos-tag' . ( 0 === $i ? ' dos-tag--navy' : '' ) . '">' . esc_html( $t ) . '</span>';
	}
	$no   = dos_work_meta( $id, 'dos_case_no' );
	$rows = array(
		'Problem' => $sec['problem'],
		'Build'   => $sec['build'],
		'Result'  => $sec['result'],
	);
	ob_start();
	?>
<section id="case" class="dos-band">
<div class="dos-wrap dos-section dos-grid-2 dos-case-band">
<div class="dos-case-band__lead">
<span class="dos-kicker">Case file<?php echo $no ? ' · Work No. ' . esc_html( $no ) : ''; ?></span>
<h2 class="dos-h2"><?php echo esc_html( get_the_title( $id ) ); ?></h2>
<p class="dos-case-band__text"><?php echo esc_html( dos_work_summary( $w, 30 ) ); ?></p>
<div class="dos-case-band__tags"><?php echo $tags; // phpcs:ignore ?></div>
</div>
<div class="dos-case-band__rows">
<?php foreach ( $rows as $label => $text ) : if ( '' === $text ) { continue; } ?>
<div class="dos-case-row"><span class="dos-kicker"><?php echo esc_html( $label ); ?></span><p><?php echo esc_html( $text ); ?></p></div>
<?php endforeach; ?>
<div class="dos-case-row dos-case-row--cta"><a class="dos-btn dos-btn--primary" href="<?php echo esc_url( get_permalink( $id ) ); ?>">Read the full case file</a></div>
</div>
</div>
</section>
	<?php
	return ob_get_clean();
}

/* ---------- Dispatches listing (main query) ---------- */

function dos_block_dispatch_list( $atts ) {
	global $wp_query;
	if ( ! $wp_query->have_posts() ) {
		$msg = is_search() ? __( 'No dispatches match that search.', 'dos-department' ) : __( 'Nothing filed here yet.', 'dos-department' );
		return '<section class="dos-wrap dos-list-section"><p class="dos-empty">' . esc_html( $msg ) . '</p></section>';
	}
	$posts    = $wp_query->posts;
	$featured = ! empty( $atts['featured'] ) && is_home() && ! is_paged();
	$out      = '';

	if ( $featured ) {
		$f   = array_shift( $posts );
		$fid = $f->ID;
		$cat = get_the_category( $fid );
		if ( has_post_thumbnail( $fid ) ) {
			$poster = '<div class="dos-work__poster dos-feature-poster">' . get_the_post_thumbnail( $fid, 'large', array( 'alt' => '' ) ) . '<span class="dos-work__no">LATEST DISPATCH</span></div>';
		} else {
			$poster = '<div class="dos-work__poster dos-feature-poster">' . dos_dispatch_poster_svg() . '<span class="dos-work__no">LATEST DISPATCH</span></div>';
		}
		$kick = get_the_date( 'M j, Y', $fid ) . ( $cat ? ' · ' . $cat[0]->name : '' );
		ob_start();
		?>
<section class="dos-wrap dos-featured-section">
<a class="dos-card dos-grid-2 dos-feature-card" href="<?php echo esc_url( get_permalink( $fid ) ); ?>">
<?php echo $poster; // phpcs:ignore ?>
<div class="dos-card__body dos-feature-card__body">
<span class="dos-kicker"><?php echo esc_html( $kick ); ?></span>
<h2 class="dos-h2"><?php echo esc_html( get_the_title( $fid ) ); ?></h2>
<p class="dos-card__text"><?php echo esc_html( wp_trim_words( has_excerpt( $f ) ? $f->post_excerpt : wp_strip_all_tags( strip_shortcodes( $f->post_content ) ), 34, '…' ) ); ?></p>
<span class="dos-link">Read dispatch <?php echo dos_arrow_svg(); // phpcs:ignore ?></span>
</div>
</a>
</section>
		<?php
		$out .= ob_get_clean();
	}

	$rows = '';
	foreach ( $posts as $p ) {
		$rows .= dos_post_row( $p, true, 'h2' );
	}

	$links = paginate_links(
		array(
			'type'      => 'array',
			'mid_size'  => 1,
			'prev_text' => '← Newer',
			'next_text' => 'Older →',
		)
	);
	$pag = '';
	if ( $links ) {
		$pag = '<nav class="dos-pagination" aria-label="' . esc_attr__( 'Pagination', 'dos-department' ) . '">' . implode( '', $links ) . '</nav>';
	}
	if ( $rows ) {
		$out .= '<section class="dos-wrap dos-list-section"><div>' . $rows . '</div>' . $pag . '</section>';
	} elseif ( $pag ) {
		$out .= '<section class="dos-wrap dos-list-section">' . $pag . '</section>';
	}
	return $out;
}

/** "All / category" tag links for the Dispatches header. */
function dos_block_term_filter() {
	$cats = get_categories( array( 'hide_empty' => true, 'number' => 8, 'orderby' => 'count', 'order' => 'DESC' ) );
	$cats = array_filter(
		$cats,
		static function ( $c ) {
			return 'uncategorized' !== $c->slug;
		}
	);
	$here = is_category() ? get_queried_object_id() : 0;
	$out  = '<a class="dos-tag' . ( $here ? '' : ' dos-tag--solid' ) . '" href="' . esc_url( dos_dispatches_url() ) . '">All</a>';
	foreach ( $cats as $c ) {
		$out .= '<a class="dos-tag' . ( $here === $c->term_id ? ' dos-tag--solid' : '' ) . '" href="' . esc_url( get_category_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>';
	}
	return '<div class="dos-tag-row">' . $out . '</div>';
}

/* ---------- Single Dispatch pieces ---------- */

function dos_block_entry_kicker() {
	$id  = get_the_ID();
	$bit = array( get_the_date( 'M j, Y', $id ) );
	$cat = get_the_category( $id );
	if ( $cat && 'uncategorized' !== $cat[0]->slug ) {
		$bit[] = '<a href="' . esc_url( get_category_link( $cat[0] ) ) . '">' . esc_html( $cat[0]->name ) . '</a>';
	}
	return '<p class="dos-kicker dos-kicker--accent dos-entry-kicker">' . implode( ' · ', $bit ) . '</p>'; // phpcs:ignore
}

function dos_block_entry_tags() {
	$tags = get_the_tags();
	if ( ! $tags ) {
		return '';
	}
	$out = '';
	foreach ( $tags as $t ) {
		$out .= '<a class="dos-tag" href="' . esc_url( get_tag_link( $t ) ) . '">' . esc_html( $t->name ) . '</a>';
	}
	return '<div class="dos-tag-row dos-entry-tags">' . $out . '</div>';
}

function dos_block_post_nav() {
	$prev = get_adjacent_post( false, '', true );
	$next = get_adjacent_post( false, '', false );
	if ( ! $prev && ! $next ) {
		return '';
	}
	$cell = static function ( $p, $label, $cls ) {
		if ( ! $p ) {
			return '<span class="dos-postnav__cell dos-postnav__cell--empty"></span>';
		}
		return '<a class="dos-postnav__cell ' . $cls . '" href="' . esc_url( get_permalink( $p ) ) . '" rel="' . ( 'prev' === $cls ? 'prev' : 'next' ) . '"><span class="dos-kicker">' . esc_html( $label ) . '</span><span class="dos-postnav__title">' . esc_html( get_the_title( $p ) ) . '</span></a>';
	};
	return '<nav class="dos-postnav" aria-label="' . esc_attr__( 'More dispatches', 'dos-department' ) . '">' . $cell( $prev, '← Previous dispatch', 'prev' ) . $cell( $next, 'Next dispatch →', 'next' ) . '</nav>'; // phpcs:ignore
}

/* ---------- Single Work (case file) ---------- */

function dos_block_work_header() {
	$id     = get_the_ID();
	$no     = dos_work_meta( $id, 'dos_case_no' );
	$client = dos_work_meta( $id, 'dos_client' );
	$year   = dos_work_meta( $id, 'dos_year' );
	$status = dos_work_meta( $id, 'dos_status' );
	$stack  = dos_work_stack( $id );
	$facts  = array(
		'Client' => $client,
		'Year'   => $year,
		'Status' => $status,
	);
	ob_start();
	?>
<header class="dos-wrap dos-case-head">
<a class="dos-link dos-case-head__back" href="<?php echo esc_url( home_url( '/works/' ) ); ?>">← All works</a>
<div class="dos-case-head__grid">
<div class="dos-case-head__main">
<span class="dos-kicker dos-kicker--accent">Case file<?php echo $no ? ' · Work No. ' . esc_html( $no ) : ''; ?></span>
<h1 class="dos-h1 dos-case-head__title"><?php echo esc_html( get_the_title( $id ) ); ?></h1>
<?php if ( has_excerpt( $id ) ) : ?><p class="dos-lead"><?php echo esc_html( get_the_excerpt( $id ) ); ?></p><?php endif; ?>
<?php if ( $stack ) : ?><div class="dos-tag-row"><?php foreach ( $stack as $i => $t ) : ?><span class="dos-tag<?php echo 0 === $i ? ' dos-tag--navy' : ''; ?>"><?php echo esc_html( $t ); ?></span><?php endforeach; ?></div><?php endif; ?>
</div>
<aside class="dos-stamp" aria-label="<?php esc_attr_e( 'Case file details', 'dos-department' ); ?>">
<div class="dos-stamp__no"><span class="dos-kicker">Case no.</span><strong><?php echo esc_html( $no ? $no : '—' ); ?></strong></div>
<dl class="dos-stamp__facts">
<?php foreach ( $facts as $label => $val ) : if ( '' === $val ) { continue; } ?>
<div><dt class="dos-kicker"><?php echo esc_html( $label ); ?></dt><dd><?php if ( 'Status' === $label && 'live' === strtolower( $val ) ) : ?><span class="dos-tag dos-tag--live"><?php echo esc_html( $val ); ?></span><?php else : echo esc_html( $val ); endif; ?></dd></div>
<?php endforeach; ?>
</dl>
</aside>
</div>
<?php if ( has_post_thumbnail( $id ) ) : ?><div class="dos-case-head__image"><?php echo get_the_post_thumbnail( $id, 'large' ); // phpcs:ignore ?></div><?php endif; ?>
</header>
	<?php
	return ob_get_clean();
}

function dos_block_work_result() {
	$result = dos_work_meta( get_the_ID(), 'dos_result' );
	// Skip the callout when the body already has its own Result section.
	if ( '' === $result || preg_match( '#<h[2-4][^>]*>\\s*Result\\s*</h[2-4]>#i', (string) get_post_field( 'post_content', get_the_ID() ) ) ) {
		return '';
	}
	return '<aside class="dos-result"><span class="dos-kicker">Result</span><p>' . esc_html( $result ) . '</p></aside>';
}
