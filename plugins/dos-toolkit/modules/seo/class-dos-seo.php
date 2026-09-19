<?php
/**
 * SEO module: descriptions, Open Graph, canonicals, schema.
 *
 * Ported from SAAB Toolkit's SEO module. Behaviour is unchanged except where
 * noted: settings moved into the shared DOS_Settings store, site-specific copy
 * was genericised, the author-archive noindex became a setting, and the schema
 * graph gained a WebPage node.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DOS_Module_SEO extends DOS_Module {

	const KEY       = 'seo';
	const DESC_KEY  = '_dos_seo_description';
	const IMAGE_KEY = '_dos_seo_image';

	// SAAB Toolkit's keys. Read-only fallback so per-post overrides written
	// before the port are not silently lost on the site it came from.
	const LEGACY_DESC_KEY  = '_saab_seo_description';
	const LEGACY_IMAGE_KEY = '_saab_seo_image';
	const MAX_DESC  = 155;

	public static function init() {
		// If a full SEO plugin is ever installed, get out of its way entirely.
		if ( self::conflicting_plugin() ) {
			return;
		}

		// Core only emits a canonical on singular views. We handle every view instead.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 1 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );

		add_action( 'admin_init', array( __CLASS__, 'handle_post' ), 20 );
	}

	/**
	 * Name of the SEO plugin already handling this site, or '' if none. The
	 * module page reports this rather than silently doing nothing.
	 */
	public static function conflicting_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'Rank Math';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'SEOPress';
		}

		return '';
	}

	public static function pages() {
		return array(
			array(
				'slug'     => 'dos-seo',
				'title'    => __( 'SEO', 'dos-toolkit' ),
				'callback' => array( __CLASS__, 'render_page' ),
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Head output
	 * ------------------------------------------------------------------- */

	public static function render_head() {
		$ctx = self::context();

		echo "\n<!-- DoS SEO -->\n";

		if ( $ctx['noindex'] ) {
			echo '<meta name="robots" content="noindex, follow">' . "\n";
		} else {
			echo '<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">' . "\n";
		}

		if ( $ctx['description'] ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $ctx['description'] ) );
		}

		if ( $ctx['canonical'] ) {
			printf( '<link rel="canonical" href="%s">' . "\n", esc_url( $ctx['canonical'] ) );
		}

		self::render_pagination_links();
		self::render_open_graph( $ctx );
		self::render_schema( $ctx );

		echo "<!-- /DoS SEO -->\n\n";
	}

	private static function render_open_graph( $ctx ) {
		$tags = array(
			'og:type'        => $ctx['og_type'],
			'og:title'       => $ctx['title'],
			'og:description' => $ctx['description'],
			'og:url'         => $ctx['canonical'],
			'og:site_name'   => get_bloginfo( 'name' ),
			'og:locale'      => str_replace( '-', '_', get_bloginfo( 'language' ) ),
		);

		foreach ( $tags as $property => $content ) {
			if ( $content ) {
				printf( '<meta property="%s" content="%s">' . "\n", esc_attr( $property ), esc_attr( $content ) );
			}
		}

		if ( $ctx['image'] ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $ctx['image']['url'] ) );

			if ( $ctx['image']['width'] ) {
				printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $ctx['image']['width'] );
				printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $ctx['image']['height'] );
			}

			if ( $ctx['image']['alt'] ) {
				printf( '<meta property="og:image:alt" content="%s">' . "\n", esc_attr( $ctx['image']['alt'] ) );
			}
		}

		if ( 'article' === $ctx['og_type'] && $ctx['post_id'] ) {
			printf(
				'<meta property="article:published_time" content="%s">' . "\n",
				esc_attr( get_the_date( DATE_W3C, $ctx['post_id'] ) )
			);
			printf(
				'<meta property="article:modified_time" content="%s">' . "\n",
				esc_attr( get_the_modified_date( DATE_W3C, $ctx['post_id'] ) )
			);

			$terms = get_the_category( $ctx['post_id'] );

			if ( $terms ) {
				printf( '<meta property="article:section" content="%s">' . "\n", esc_attr( $terms[0]->name ) );
			}
		}

		// Twitter reads the og:* tags for everything except the card type.
		$card = $ctx['image'] && $ctx['image']['width'] >= 600 ? 'summary_large_image' : 'summary';

		printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( $card ) );

		$handle = trim( (string) self::setting( 'twitter', '' ) );

		if ( $handle ) {
			printf( '<meta name="twitter:site" content="@%s">' . "\n", esc_attr( ltrim( $handle, '@' ) ) );
		}
	}

	private static function render_pagination_links() {
		if ( is_singular() ) {
			return;
		}

		global $wp_query;

		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$max   = (int) $wp_query->max_num_pages;

		if ( $paged > 1 ) {
			printf( '<link rel="prev" href="%s">' . "\n", esc_url( get_pagenum_link( $paged - 1 ) ) );
		}

		if ( $paged < $max ) {
			printf( '<link rel="next" href="%s">' . "\n", esc_url( get_pagenum_link( $paged + 1 ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Context: what page is this, and what should it say about itself
	 * ------------------------------------------------------------------- */

	private static function context() {
		$ctx = array(
			'title'       => wp_get_document_title(),
			'description' => '',
			'canonical'   => '',
			'image'       => null,
			'og_type'     => 'website',
			'noindex'     => false,
			'post_id'     => 0,
		);

		// The front page must be tested before is_singular(): a static front
		// page is BOTH, and the singular branch would describe it from page
		// content that a page builder leaves empty.
		if ( is_front_page() ) {
			$front_id           = get_queried_object_id();
			$ctx['post_id']     = $front_id;
			$ctx['canonical']   = self::paged_url( home_url( '/' ) );
			$ctx['description'] = self::clean( self::setting( 'home_description', '' ) )
				?: self::clean( get_bloginfo( 'description' ) );
			$ctx['image']       = $front_id ? self::image_for_post( $front_id ) : self::fallback_image();

		} elseif ( is_home() ) {
			$blog_id            = get_queried_object_id();
			$ctx['canonical']   = self::paged_url( get_permalink( $blog_id ) ?: home_url( '/' ) );
			$ctx['description'] = ( $blog_id ? self::clean( self::describe_post( $blog_id ) ) : '' )
				?: self::clean( get_bloginfo( 'description' ) );
			$ctx['image']       = self::fallback_image();

		} elseif ( is_singular() ) {
			$post_id            = get_queried_object_id();
			$ctx['post_id']     = $post_id;
			$ctx['og_type']     = is_singular( 'post' ) ? 'article' : 'website';
			$ctx['canonical']   = self::paged_url( get_permalink( $post_id ) );
			$ctx['description'] = self::describe_post( $post_id );
			$ctx['image']       = self::image_for_post( $post_id );

		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term               = get_queried_object();
			$ctx['canonical']   = self::paged_url( get_term_link( $term ) );
			$ctx['description'] = self::clean( $term->description )
				?: self::clean( sprintf(
					/* translators: 1: term name, 2: site name */
					__( '%1$s — posts and pages from %2$s.', 'dos-toolkit' ),
					$term->name,
					get_bloginfo( 'name' )
				) );
			$ctx['image']       = self::fallback_image();

		} elseif ( is_author() ) {
			// On a single-author site the author archive duplicates the blog.
			// Sites with several bylines want it indexed, so this is a setting.
			$ctx['noindex']   = (bool) self::setting( 'noindex_author', 1 );
			$ctx['canonical'] = self::paged_url( get_author_posts_url( get_queried_object_id() ) );

		} elseif ( is_search() || is_404() ) {
			$ctx['noindex'] = true;

		} elseif ( is_archive() ) {
			$ctx['canonical']   = self::paged_url( self::current_url_base() );
			$ctx['description'] = self::clean( wp_strip_all_tags( get_the_archive_description() ) )
				?: self::clean( get_the_archive_title() . ' — ' . get_bloginfo( 'name' ) );
			$ctx['image']       = self::fallback_image();
		}

		if ( ! $ctx['image'] ) {
			$ctx['image'] = self::fallback_image();
		}

		// Never ship an empty description. Page-builder pages hold their text
		// in post meta, so stripping shortcodes out of post_content can leave
		// nothing at all — the title plus the tagline is the floor.
		if ( ! $ctx['description'] && ! $ctx['noindex'] ) {
			$ctx['description'] = self::floor_description( $ctx['title'] );
		}

		return $ctx;
	}

	private static function floor_description( $title ) {
		$title   = self::clean( preg_replace( '/\s*[|–—-]\s*' . preg_quote( get_bloginfo( 'name' ), '/' ) . '\s*$/u', '', (string) $title ) );
		$tagline = self::clean( get_bloginfo( 'description' ) );

		if ( $title && $tagline ) {
			return self::clean( $title . ' — ' . $tagline );
		}

		return $title ?: $tagline;
	}

	/**
	 * Hand-written description, then excerpt, then the opening of the body.
	 */
	public static function describe_post( $post_id ) {
		$manual = get_post_meta( $post_id, self::DESC_KEY, true );

		if ( ! $manual ) {
			$manual = get_post_meta( $post_id, self::LEGACY_DESC_KEY, true );
		}

		if ( $manual ) {
			return self::clean( $manual );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return '';
		}

		if ( $post->post_excerpt ) {
			return self::clean( $post->post_excerpt );
		}

		$content = strip_shortcodes( $post->post_content );
		$content = wp_strip_all_tags( $content );

		return self::clean( $content );
	}

	/**
	 * Social image override, then featured image, then the first image in the
	 * body, then the sitewide fallback.
	 */
	private static function image_for_post( $post_id ) {
		$override = (int) get_post_meta( $post_id, self::IMAGE_KEY, true );

		if ( ! $override ) {
			$override = (int) get_post_meta( $post_id, self::LEGACY_IMAGE_KEY, true );
		}

		if ( $override ) {
			return self::image_from_attachment( $override );
		}

		$thumb_id = get_post_thumbnail_id( $post_id );

		if ( $thumb_id ) {
			return self::image_from_attachment( $thumb_id );
		}

		$post = get_post( $post_id );

		if ( $post && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
			$attachment_id = attachment_url_to_postid( $m[1] );

			if ( $attachment_id ) {
				return self::image_from_attachment( $attachment_id );
			}

			return array(
				'url'    => $m[1],
				'width'  => 0,
				'height' => 0,
				'alt'    => get_the_title( $post_id ),
			);
		}

		return self::fallback_image();
	}

	private static function image_from_attachment( $attachment_id ) {
		// "full" is the right choice here: uploads are 1024px+ and Facebook
		// wants at least 1200px on the long edge for a large card.
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );

		if ( ! $src ) {
			return null;
		}

		return array(
			'url'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
			'alt'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	private static function fallback_image() {
		$id = (int) self::setting( 'fallback_image', 0 );

		return $id ? self::image_from_attachment( $id ) : null;
	}

	/* ---------------------------------------------------------------------
	 * Schema
	 * ------------------------------------------------------------------- */

	private static function render_schema( $ctx ) {
		$site_url = home_url( '/' );
		$org_id   = $site_url . '#organization';
		$site_id  = $site_url . '#website';

		$organization = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => get_bloginfo( 'name' ),
			'url'   => $site_url,
		);

		$logo = self::fallback_image();

		if ( $logo ) {
			$organization['logo'] = array(
				'@type'  => 'ImageObject',
				'url'    => $logo['url'],
				'width'  => (int) $logo['width'],
				'height' => (int) $logo['height'],
			);
		}

		$graph = array(
			$organization,
			array(
				'@type'     => 'WebSite',
				'@id'       => $site_id,
				'url'       => $site_url,
				'name'      => get_bloginfo( 'name' ),
				'publisher' => array( '@id' => $org_id ),
			),
		);

		// A WebPage node gives every view — not just posts — a node the rest
		// of the graph can point at, and is what AI crawlers read to work out
		// what a page is about.
		if ( $ctx['canonical'] ) {
			$page = array(
				'@type'    => 'WebPage',
				'@id'      => $ctx['canonical'],
				'url'      => $ctx['canonical'],
				'name'     => $ctx['title'],
				'isPartOf' => array( '@id' => $site_id ),
			);

			if ( $ctx['description'] ) {
				$page['description'] = $ctx['description'];
			}

			if ( $ctx['post_id'] ) {
				$page['datePublished'] = get_the_date( DATE_W3C, $ctx['post_id'] );
				$page['dateModified']  = get_the_modified_date( DATE_W3C, $ctx['post_id'] );
			}

			$graph[] = $page;
		}

		if ( 'article' === $ctx['og_type'] && $ctx['post_id'] ) {
			$post_id = $ctx['post_id'];
			$author  = (int) get_post_field( 'post_author', $post_id );

			$article = array(
				'@type'            => 'BlogPosting',
				'@id'              => $ctx['canonical'] . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $post_id ) ),
				'description'      => $ctx['description'],
				'datePublished'    => get_the_date( DATE_W3C, $post_id ),
				'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
				'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $ctx['canonical'] ),
				'publisher'        => array( '@id' => $org_id ),
				'isPartOf'         => array( '@id' => $site_id ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $author ),
					'url'   => get_author_posts_url( $author ),
				),
			);

			if ( $ctx['image'] ) {
				$article['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $ctx['image']['url'],
					'width'  => (int) $ctx['image']['width'],
					'height' => (int) $ctx['image']['height'],
				);
			}

			$graph[] = $article;
		}

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode(
				array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Collapse entities and whitespace, then cut on a word boundary.
	 */
	private static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		if ( mb_strlen( $text ) <= self::MAX_DESC ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, self::MAX_DESC );
		$space = mb_strrpos( $cut, ' ' );

		return rtrim( $space ? mb_substr( $cut, 0, $space ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * Page 2 of an archive canonicalises to itself, not to page 1 — pointing
	 * every page at page 1 hides the deeper posts from crawlers.
	 */
	private static function paged_url( $base ) {
		if ( is_wp_error( $base ) || ! $base ) {
			return '';
		}

		$paged = (int) get_query_var( 'paged' );

		if ( $paged > 1 ) {
			return get_pagenum_link( $paged );
		}

		$page = (int) get_query_var( 'page' );

		if ( $page > 1 && is_singular() ) {
			return trailingslashit( $base ) . user_trailingslashit( $page, 'single_paged' );
		}

		return $base;
	}

	private static function current_url_base() {
		global $wp;

		return home_url( add_query_arg( array(), $wp->request ) );
	}

	/* ---------------------------------------------------------------------
	 * Editor UI
	 * ------------------------------------------------------------------- */

	public static function add_meta_box() {
		foreach ( get_post_types( array( 'public' => true ) ) as $screen ) {
			if ( 'attachment' === $screen ) {
				continue;
			}

			add_meta_box(
				'dos-seo',
				__( 'Search & Social', 'dos-toolkit' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'dos_seo_save', 'dos_seo_nonce' );

		$description = get_post_meta( $post->ID, self::DESC_KEY, true );
		$image_id    = (int) get_post_meta( $post->ID, self::IMAGE_KEY, true );
		$auto        = self::describe_post( $post->ID );
		?>
		<p>
			<label for="dos_seo_description"><strong><?php esc_html_e( 'Meta description', 'dos-toolkit' ); ?></strong></label><br>
			<textarea id="dos_seo_description" name="dos_seo_description" rows="3" style="width:100%" maxlength="200"><?php echo esc_textarea( $description ); ?></textarea>
			<span class="description">
				<?php esc_html_e( 'Aim for 120–155 characters. Lead with what the page is actually about.', 'dos-toolkit' ); ?>
				<?php esc_html_e( 'Leave blank and this is used:', 'dos-toolkit' ); ?><br>
				<em><?php echo esc_html( $auto ); ?></em>
			</span>
		</p>
		<p>
			<label for="dos_seo_image"><strong><?php esc_html_e( 'Social share image', 'dos-toolkit' ); ?></strong></label><br>
			<input type="number" id="dos_seo_image" name="dos_seo_image" value="<?php echo $image_id ? (int) $image_id : ''; ?>" style="width:120px" placeholder="<?php esc_attr_e( 'Media ID', 'dos-toolkit' ); ?>">
			<span class="description">
				<?php esc_html_e( 'Optional. Leave blank to use the featured image, then the first photo in the post.', 'dos-toolkit' ); ?>
			</span>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['dos_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dos_seo_nonce'] ) ), 'dos_seo_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$description = isset( $_POST['dos_seo_description'] )
			? sanitize_text_field( wp_unslash( $_POST['dos_seo_description'] ) )
			: '';

		if ( $description ) {
			update_post_meta( $post_id, self::DESC_KEY, $description );
		} else {
			delete_post_meta( $post_id, self::DESC_KEY );
		}

		$image_id = isset( $_POST['dos_seo_image'] ) ? (int) $_POST['dos_seo_image'] : 0;

		if ( $image_id ) {
			update_post_meta( $post_id, self::IMAGE_KEY, $image_id );
		} else {
			delete_post_meta( $post_id, self::IMAGE_KEY );
		}
	}

	/* ---------------------------------------------------------------------
	 * Settings screen
	 * ------------------------------------------------------------------- */

	public static function handle_post() {
		if ( empty( $_POST['dos_action'] ) || 'save_seo' !== sanitize_key( wp_unslash( $_POST['dos_action'] ) ) ) {
			return;
		}

		if ( ! current_user_can( DOS_Settings::capability() ) ) {
			return;
		}

		check_admin_referer( 'dos_save_seo' );

		DOS_Settings::update(
			array(
				'seo_home_description' => isset( $_POST['home_description'] ) ? sanitize_text_field( wp_unslash( $_POST['home_description'] ) ) : '',
				'seo_fallback_image'   => isset( $_POST['fallback_image'] ) ? absint( $_POST['fallback_image'] ) : 0,
				'seo_twitter'          => isset( $_POST['twitter'] ) ? sanitize_text_field( wp_unslash( $_POST['twitter'] ) ) : '',
				'seo_noindex_author'   => empty( $_POST['noindex_author'] ) ? 0 : 1,
			)
		);

		self::log( 'settings_saved', 'SEO settings updated.' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'dos-seo', 'dos_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		$conflict = self::conflicting_plugin();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO', 'dos-toolkit' ); ?></h1>

			<?php DOS_Admin::notice(); ?>

			<?php if ( $conflict ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: name of the conflicting SEO plugin */
							esc_html__( '%s is active, so this module is standing down and emitting nothing. Settings below are saved but unused until that plugin is deactivated.', 'dos-toolkit' ),
							esc_html( $conflict )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Meta descriptions, Open Graph and Twitter Cards, canonicals on every view, and an Organization / WebSite / WebPage schema graph. Per-post overrides live in the Search & Social box on the editor screen.', 'dos-toolkit' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'dos_save_seo' ); ?>
				<input type="hidden" name="dos_action" value="save_seo" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="home_description"><?php esc_html_e( 'Homepage description', 'dos-toolkit' ); ?></label></th>
						<td>
							<textarea id="home_description" name="home_description" rows="3" class="large-text"><?php echo esc_textarea( self::setting( 'home_description', '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Falls back to the site tagline. This is the snippet search engines show for the homepage.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fallback_image"><?php esc_html_e( 'Fallback share image', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="number" id="fallback_image" name="fallback_image" value="<?php echo self::setting( 'fallback_image', 0 ) ? (int) self::setting( 'fallback_image', 0 ) : ''; ?>" class="small-text">
							<p class="description">
								<?php esc_html_e( 'Media library ID, 1200×630 or larger. Used on archives and on posts with no photo, and becomes the schema publisher logo.', 'dos-toolkit' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="twitter"><?php esc_html_e( 'X / Twitter handle', 'dos-toolkit' ); ?></label></th>
						<td>
							<input type="text" id="twitter" name="twitter" value="<?php echo esc_attr( self::setting( 'twitter', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'handle, without the @', 'dos-toolkit' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Author archives', 'dos-toolkit' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="noindex_author" value="1" <?php checked( self::setting( 'noindex_author', 1 ) ); ?> />
								<?php esc_html_e( 'Noindex author archives', 'dos-toolkit' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Right for a single-author site, where the author archive duplicates the blog. Untick it on sites with several bylines.', 'dos-toolkit' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
