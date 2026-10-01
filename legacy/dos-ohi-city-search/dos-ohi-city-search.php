<?php
/**
 * Plugin Name:       DoS Open Houses In City Search
 * Description:       City search box for an Open Houses In market site. Suggests every published city page (any page tagged with an ohi_city term) as the visitor types and goes straight to the one they pick. Auto-inserted as the second section of the homepage; also available as [ohi_city_search]. Settings and the city list live under Tools → City Search. Market-agnostic: the city list comes from the site's own pages.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Department of Search
 * License:           GPL-2.0-or-later
 * Text Domain:       dos-ohi-city-search
 *
 * @package OHI
 */

defined( 'ABSPATH' ) || exit;

define( 'DOS_OHI_CS_VERSION', '1.0.0' );
define( 'DOS_OHI_CS_TRANSIENT', 'dos_ohi_cs_index_v1' );
define( 'DOS_OHI_CS_META', 'dos_ohi_cs_index_meta' );
define( 'DOS_OHI_CS_OPTION', 'dos_ohi_cs_settings' );
define( 'DOS_OHI_CS_TAXONOMY', 'ohi_city' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

function dos_ohi_cs_defaults() {
	return array(
		'auto_insert'  => 1,
		'heading'      => 'Find Open Houses by City',
		'intro'        => 'Start typing a city and pick it from the list to see every open house scheduled there.',
		'placeholder'  => 'Start typing a city',
		'button_label' => 'Search',
		'bg_color'     => '#faf5f0',
		'accent_color' => '#b5522b',
	);
}

function dos_ohi_cs_settings() {
	return wp_parse_args( (array) get_option( DOS_OHI_CS_OPTION, array() ), dos_ohi_cs_defaults() );
}

/**
 * Builds the city list: one entry per ohi_city term that has a published page tagged with it.
 * Each entry is array( 'n' => term name, 'u' => page URL ). Pages tagged with more than one
 * city are skipped (they're hubs or guides, not a city's page); if two pages share a city,
 * the oldest one wins. Both cases are listed on the Tools dashboard.
 */
function dos_ohi_cs_build_index() {
	$out     = array();
	$skipped = array();

	if ( taxonomy_exists( DOS_OHI_CS_TAXONOMY ) ) {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => DOS_OHI_CS_TAXONOMY,
						'operator' => 'EXISTS',
					),
				),
			)
		);

		$seen = array();
		foreach ( $ids as $id ) {
			$terms = wp_get_object_terms( $id, DOS_OHI_CS_TAXONOMY );
			if ( is_wp_error( $terms ) || 1 !== count( $terms ) ) {
				$skipped[] = array( 'id' => $id, 'why' => 'tagged with ' . ( is_wp_error( $terms ) ? 0 : count( $terms ) ) . ' cities' );
				continue;
			}
			$term = $terms[0];
			if ( isset( $seen[ $term->term_id ] ) ) {
				$skipped[] = array( 'id' => $id, 'why' => 'another page already covers ' . $term->name );
				continue;
			}
			$seen[ $term->term_id ] = true;

			$out[] = array(
				'n' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				'u' => esc_url_raw( get_permalink( $id ) ),
			);
		}
	}

	usort(
		$out,
		function ( $a, $b ) {
			return strnatcasecmp( $a['n'], $b['n'] );
		}
	);

	update_option(
		DOS_OHI_CS_META,
		array(
			'built'   => time(),
			'skipped' => $skipped,
		),
		false
	);

	return $out;
}

function dos_ohi_cs_get_index() {
	$index = get_transient( DOS_OHI_CS_TRANSIENT );
	if ( false === $index ) {
		$index = dos_ohi_cs_build_index();
		set_transient( DOS_OHI_CS_TRANSIENT, $index, 12 * HOUR_IN_SECONDS );
	}
	return $index;
}

function dos_ohi_cs_flush() {
	delete_transient( DOS_OHI_CS_TRANSIENT );
}

// Rebuild when a page is published, unpublished, or renamed while published.
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'page' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			dos_ohi_cs_flush();
		}
	},
	10,
	3
);

// Rebuild when a page's city tag changes. Listing upserts from the sync also fire this, so only pages count.
add_action(
	'set_object_terms',
	function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( DOS_OHI_CS_TAXONOMY === $taxonomy && 'page' === get_post_type( $object_id ) ) {
			dos_ohi_cs_flush();
		}
	},
	10,
	4
);

// Rebuild when a city is renamed or deleted.
add_action( 'edited_' . DOS_OHI_CS_TAXONOMY, 'dos_ohi_cs_flush' );
add_action( 'delete_' . DOS_OHI_CS_TAXONOMY, 'dos_ohi_cs_flush' );

/**
 * The "browse all cities" link shown when a search has no match: the /by-city/ hub when the site has one.
 */
function dos_ohi_cs_browse_url() {
	$hub = get_page_by_path( 'by-city' );
	$url = ( $hub && 'publish' === $hub->post_status ) ? get_permalink( $hub ) : '';
	return (string) apply_filters( 'dos_ohi_cs_browse_url', $url );
}

/**
 * Markup for the search section. The city list rides along as JSON, so there's no extra request.
 * Without JavaScript the form falls back to a normal site search.
 */
function dos_ohi_cs_markup() {
	static $n = 0;
	$n++;
	$id = 'ohi-cs-' . $n;
	$o  = dos_ohi_cs_settings();

	dos_ohi_cs_enqueue();

	ob_start();
	?>
<section class="ohi-city-search" data-cities="<?php echo esc_attr( wp_json_encode( dos_ohi_cs_get_index() ) ); ?>" data-browse="<?php echo esc_url( dos_ohi_cs_browse_url() ); ?>">
<h2 class="ohi-cs-heading"><?php echo esc_html( $o['heading'] ); ?></h2>
<?php if ( '' !== $o['intro'] ) : ?><p class="ohi-cs-intro"><?php echo esc_html( $o['intro'] ); ?></p><?php endif; ?>
<form class="ohi-cs-form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" autocomplete="off">
<label class="ohi-cs-sr" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'City', 'dos-ohi-city-search' ); ?></label>
<div class="ohi-cs-wrap">
<input id="<?php echo esc_attr( $id ); ?>" name="s" type="text" placeholder="<?php echo esc_attr( $o['placeholder'] ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-list" spellcheck="false" autocapitalize="words" enterkeyhint="go">
<ul id="<?php echo esc_attr( $id ); ?>-list" class="ohi-cs-list" role="listbox" hidden></ul>
</div>
<button type="submit" class="ohi-cs-btn"><?php echo esc_html( $o['button_label'] ); ?></button>
</form>
<p class="ohi-cs-msg" role="status" aria-live="polite"></p>
</section>
	<?php
	return ob_get_clean();
}

add_shortcode( 'ohi_city_search', 'dos_ohi_cs_markup' );

/**
 * Where the section goes in the homepage content, as a byte offset:
 * after the first <section> when the page opens with one (a hero section),
 * otherwise right before the first <h2> (after the H1 and intro),
 * otherwise after the first paragraph.
 */
function dos_ohi_cs_insert_at( $content ) {
	if ( preg_match( '/^\s*(?:<p>\s*<\/p>\s*)?<section\b.*?<\/section>/s', $content, $m ) ) {
		return strlen( $m[0] );
	}
	$h2 = stripos( $content, '<h2' );
	if ( false !== $h2 ) {
		return $h2;
	}
	$p = stripos( $content, '</p>' );
	return false === $p ? 0 : $p + 4;
}

add_filter(
	'the_content',
	function ( $content ) {
		static $done = false;
		if ( $done || ! dos_ohi_cs_settings()['auto_insert'] || ! is_front_page() || ! in_the_loop() || ! is_main_query() || false !== strpos( $content, 'ohi-city-search' ) ) {
			return $content;
		}
		$done = true;
		$at   = dos_ohi_cs_insert_at( $content );
		$add  = dos_ohi_cs_markup();
		return substr( $content, 0, $at ) . ( $at ? "\n" : '' ) . $add . "\n" . substr( $content, $at );
	},
	20 // After shortcodes (11), so an [ohi_open_houses] embed is already expanded and can't be split.
);

function dos_ohi_cs_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_register_style( 'dos-ohi-city-search', false, array(), DOS_OHI_CS_VERSION );
	wp_enqueue_style( 'dos-ohi-city-search' );
	wp_add_inline_style( 'dos-ohi-city-search', dos_ohi_cs_css() );

	wp_register_script( 'dos-ohi-city-search', false, array(), DOS_OHI_CS_VERSION, true );
	wp_enqueue_script( 'dos-ohi-city-search' );
	wp_add_inline_script( 'dos-ohi-city-search', dos_ohi_cs_js() );
}

function dos_ohi_cs_css() {
	$o      = dos_ohi_cs_settings();
	$d      = dos_ohi_cs_defaults();
	$bg     = sanitize_hex_color( $o['bg_color'] ) ? $o['bg_color'] : $d['bg_color'];
	$accent = sanitize_hex_color( $o['accent_color'] ) ? $o['accent_color'] : $d['accent_color'];

	return '.ohi-city-search{--ohi-cs-bg:' . $bg . ';--ohi-cs-accent:' . $accent . ';background:var(--ohi-cs-bg);border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:24px;margin:24px 0;box-sizing:border-box}'
		. '.ohi-city-search .ohi-cs-heading{margin:0 0 6px}'
		. '.ohi-city-search .ohi-cs-intro{margin:0}'
		. '.ohi-city-search .ohi-cs-form{display:flex;gap:8px;margin:14px 0 0;align-items:stretch}'
		. '.ohi-city-search .ohi-cs-wrap{position:relative;flex:1 1 auto;min-width:0}'
		. '.ohi-city-search input[type=text]{display:block;width:100%;max-width:none;height:48px;box-sizing:border-box;margin:0;padding:10px 14px;font:inherit;font-size:16px;line-height:1.2;border:1px solid rgba(0,0,0,.25);border-radius:8px;background:#fff;color:#111;box-shadow:none}'
		. '.ohi-city-search input[type=text]:focus{outline:2px solid var(--ohi-cs-accent);outline-offset:1px;border-color:var(--ohi-cs-accent)}'
		. '.ohi-city-search .ohi-cs-btn{flex:0 0 auto;height:48px;margin:0;padding:0 20px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;border:0;border-radius:8px;background:var(--ohi-cs-accent);color:#fff;font:inherit;font-size:16px;font-weight:600;line-height:1;cursor:pointer;box-shadow:none;text-transform:none;letter-spacing:normal}'
		. '.ohi-city-search .ohi-cs-btn:hover{filter:brightness(1.08)}'
		. '.ohi-city-search .ohi-cs-btn:focus-visible{outline:2px solid var(--ohi-cs-accent);outline-offset:2px}'
		. '.ohi-city-search .ohi-cs-list{position:absolute;z-index:50;left:0;right:0;top:calc(100% + 4px);margin:0;padding:4px 0;list-style:none;background:#fff;border:1px solid rgba(0,0,0,.15);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.14);max-height:min(320px,50vh);overflow-y:auto;text-align:left}'
		. '.ohi-city-search .ohi-cs-list li{list-style:none;margin:0;padding:11px 14px;cursor:pointer;color:#111;line-height:1.3;background:none}'
		. '.ohi-city-search .ohi-cs-list li::before{content:none;display:none}'
		. '.ohi-city-search .ohi-cs-list li[aria-selected=true],.ohi-city-search .ohi-cs-list li:hover{background:rgba(0,0,0,.06)}'
		. '.ohi-city-search .ohi-cs-list mark{background:none;color:inherit;font-weight:700;padding:0}'
		. '.ohi-city-search .ohi-cs-msg{margin:8px 0 0;font-size:.9em}'
		. '.ohi-city-search .ohi-cs-msg:empty{display:none}'
		. '.ohi-city-search .ohi-cs-msg a{color:var(--ohi-cs-accent)}'
		. '.ohi-city-search .ohi-cs-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
		. '@media (max-width:640px){.ohi-city-search{padding:16px;margin:16px 0}.ohi-city-search .ohi-cs-btn{padding:0 16px}}';
}

function dos_ohi_cs_js() {
	return <<<'JS'
(function () {
  function norm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/[.,'’]/g, '').replace(/[\s-]+/g, ' ').trim();
  }
  function esc(s) {
    return s.replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; });
  }

  function init(sec) {
    var list;
    try { list = JSON.parse(sec.getAttribute('data-cities') || '[]'); } catch (e) { return; }
    if (!list.length) return;
    list.forEach(function (c) { c.k = norm(c.n); });

    var browse = sec.getAttribute('data-browse');
    var form = sec.querySelector('form'), input = sec.querySelector('input');
    var ul = sec.querySelector('.ohi-cs-list'), msg = sec.querySelector('.ohi-cs-msg');
    var results = [], active = -1;

    // Empty box: every city. Otherwise names that start with the query, then names with a word that does.
    function search(q) {
      var k = norm(q);
      if (!k) return list.slice();
      var starts = [], words = [];
      list.forEach(function (c) {
        if (c.k.indexOf(k) === 0) starts.push(c);
        else if (c.k.indexOf(' ' + k) > 0) words.push(c);
      });
      return starts.concat(words);
    }
    function go(c) {
      input.value = c.n;
      close();
      msg.textContent = 'Opening ' + c.n + '…';
      window.location.href = c.u;
    }
    function close() {
      ul.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    }
    function paint() {
      if (!results.length) { close(); return; }
      var k = input.value.trim(), n = k.length;
      ul.innerHTML = results.map(function (c, i) {
        var lab = esc(c.n);
        if (n && c.n.toLowerCase().indexOf(k.toLowerCase()) === 0) lab = '<mark>' + esc(c.n.slice(0, n)) + '</mark>' + esc(c.n.slice(n));
        return '<li role="option" id="' + input.id + '-o' + i + '" aria-selected="' + (i === active) + '">' + lab + '</li>';
      }).join('');
      ul.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (active >= 0) input.setAttribute('aria-activedescendant', input.id + '-o' + active);
      else input.removeAttribute('aria-activedescendant');
    }
    function update() {
      msg.textContent = '';
      results = search(input.value);
      active = -1;
      paint();
    }

    input.addEventListener('focus', update);
    input.addEventListener('input', update);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (ul.hidden) update();
        if (!results.length) return;
        e.preventDefault();
        active = e.key === 'ArrowDown' ? (active + 1) % results.length : (active <= 0 ? results.length - 1 : active - 1);
        paint();
        var el = document.getElementById(input.id + '-o' + active);
        if (el) el.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Escape') {
        close();
      }
    });
    ul.addEventListener('mousedown', function (e) {
      var li = e.target.closest('li');
      if (!li) return;
      e.preventDefault();
      go(results[Array.prototype.indexOf.call(ul.children, li)]);
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var q = input.value.trim();
      if (active >= 0 && results[active]) { go(results[active]); return; }
      if (!q) { input.focus(); update(); return; }
      var k = norm(q), hits = search(q);
      var exact = hits.filter(function (c) { return c.k === k; })[0];
      if (exact || hits.length) { go(exact || hits[0]); return; }
      close();
      msg.innerHTML = 'No city page for “' + esc(q) + '” yet.' + (browse ? ' <a href="' + esc(browse) + '">See every city</a>.' : '');
    });
  }

  function boot() { document.querySelectorAll('.ohi-city-search').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS;
}
