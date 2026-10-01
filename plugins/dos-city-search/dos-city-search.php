<?php
/**
 * Plugin Name: DoS City Search
 * Description: City, State search box for Real Estate Values Near Me. Autocompletes from published city pages and jumps straight to the chosen page. Auto-inserted below the homepage hero; also available as [revnm_city_search]. Settings and index status under Tools → City Search.
 * Version: 1.1.1
 * Author: Department of Search
 * Text Domain: dos-city-search
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOS_CITY_SEARCH_VERSION', '1.1.1' );
define( 'DOS_CITY_SEARCH_TRANSIENT', 'revnm_city_index_v1' );
define( 'DOS_CITY_SEARCH_META', 'dos_city_search_index_meta' );
define( 'DOS_CITY_SEARCH_OPTION', 'dos_city_search_settings' );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin.php';
}

function dos_city_search_defaults() {
	return array(
		'auto_insert'  => 1,
		'heading'      => 'Search Home Values by City',
		'intro'        => 'Start typing your city, pick it from the list, and press Enter to go straight to its market report.',
		'placeholder'  => 'City, State (e.g. Kennewick, WA)',
		'button_label' => 'Search',
		'bg_color'     => '#eaf2fc',
	);
}

function dos_city_search_settings() {
	return wp_parse_args( (array) get_option( DOS_CITY_SEARCH_OPTION, array() ), dos_city_search_defaults() );
}

/**
 * Two-letter codes a city page slug can end in (50 states + DC + PR).
 */
function dos_city_search_states() {
	return array(
		'al', 'ak', 'az', 'ar', 'ca', 'co', 'ct', 'de', 'dc', 'fl', 'ga', 'hi', 'id', 'il', 'in', 'ia', 'ks',
		'ky', 'la', 'me', 'md', 'ma', 'mi', 'mn', 'ms', 'mo', 'mt', 'ne', 'nv', 'nh', 'nj', 'nm', 'ny', 'nc',
		'nd', 'oh', 'ok', 'or', 'pa', 'ri', 'sc', 'sd', 'tn', 'tx', 'ut', 'vt', 'va', 'wa', 'wv', 'wi', 'wy', 'pr',
	);
}

/**
 * Builds the index of published city pages: a list of "City, ST|slug" strings.
 * Labels come from the page title ("McAllen TX – What's My Home Worth" -> "McAllen, TX").
 */
function dos_city_search_build_index() {
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT post_title, post_name FROM {$wpdb->posts}
		 WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0
		 AND post_name REGEXP '^[a-z0-9-]+-[a-z]{2}$'"
	);

	$states    = array_flip( dos_city_search_states() );
	$out       = array();
	$fallbacks = array();

	foreach ( $rows as $row ) {
		$slug = $row->post_name;
		$st   = substr( $slug, -2 );
		if ( ! isset( $states[ $st ] ) ) {
			continue;
		}

		$title = html_entity_decode( wp_strip_all_tags( $row->post_title ), ENT_QUOTES, 'UTF-8' );
		$parts = preg_split( '/\s+[–—-]\s+/u', $title, 2 );
		$name  = trim( $parts[0] );
		$code  = strtoupper( $st );

		// Expect "City ST"; anything else falls back to a label built from the slug.
		if ( preg_match( '/^(.+?)[\s,]+' . $code . '$/u', $name, $m ) ) {
			$city = trim( $m[1] );
		} else {
			$city        = ucwords( str_replace( '-', ' ', substr( $slug, 0, -3 ) ) );
			$fallbacks[] = $slug;
		}

		$out[] = $city . ', ' . $code . '|' . $slug;
	}

	sort( $out, SORT_NATURAL | SORT_FLAG_CASE );

	// Shown on the Tools dashboard: when the list was built, and which titles didn't parse as "City ST".
	update_option(
		DOS_CITY_SEARCH_META,
		array(
			'built'     => time(),
			'fallbacks' => array_slice( $fallbacks, 0, 200 ),
			'fallback_n' => count( $fallbacks ),
		),
		false
	);

	return $out;
}

function dos_city_search_get_index() {
	$index = get_transient( DOS_CITY_SEARCH_TRANSIENT );
	if ( false === $index ) {
		$index = dos_city_search_build_index();
		set_transient( DOS_CITY_SEARCH_TRANSIENT, $index, 12 * HOUR_IN_SECONDS );
	}
	return $index;
}

// Rebuild when a page is published or unpublished. Content-only updates leave the index alone.
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( 'page' === $post->post_type && $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			delete_transient( DOS_CITY_SEARCH_TRANSIENT );
		}
	},
	10,
	3
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'revnm/v1',
			'/cities',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					$res = new WP_REST_Response( dos_city_search_get_index() );
					$res->header( 'Cache-Control', 'public, max-age=3600' );
					return $res;
				},
			)
		);
	}
);

/**
 * Markup for the search section. Styling for .rv-search already lives in the site's Additional CSS.
 */
function dos_city_search_markup() {
	static $n = 0;
	$n++;
	$id  = 'revnm-cs-' . $n;
	$api = esc_url( rest_url( 'revnm/v1/cities' ) );
	$home = esc_url( home_url( '/' ) );
	$o    = dos_city_search_settings();

	dos_city_search_enqueue();

	ob_start();
	?>
<section class="rv-sec rv-city-search" data-api="<?php echo $api; ?>" data-home="<?php echo $home; ?>">
<h2><?php echo esc_html( $o['heading'] ); ?></h2>
<?php if ( '' !== $o['intro'] ) : ?><p><?php echo esc_html( $o['intro'] ); ?></p><?php endif; ?>
<form class="rv-search" role="search" autocomplete="off" novalidate>
<label class="rv-sr" for="<?php echo $id; ?>">City, State</label>
<div class="rv-cs-wrap">
<input id="<?php echo $id; ?>" type="text" placeholder="<?php echo esc_attr( $o['placeholder'] ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo $id; ?>-list" spellcheck="false" enterkeyhint="go">
<ul id="<?php echo $id; ?>-list" class="rv-cs-list" role="listbox" hidden></ul>
</div>
<button type="submit" class="rv-btn"><?php echo esc_html( $o['button_label'] ); ?></button>
</form>
<p class="rv-cs-msg" role="status" aria-live="polite"></p>
</section>
	<?php
	return ob_get_clean();
}

add_shortcode( 'revnm_city_search', 'dos_city_search_markup' );

// Insert the section directly below the homepage hero, once per request, unless the shortcode is already on the page.
add_filter(
	'the_content',
	function ( $content ) {
		static $done = false;
		if ( $done || ! dos_city_search_settings()['auto_insert'] || ! is_front_page() || ! in_the_loop() || false !== strpos( $content, 'rv-city-search' ) ) {
			return $content;
		}
		if ( ! preg_match( '/<section[^>]*\brv-hero\b[^>]*>.*?<\/section>/s', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}
		$done = true;
		$at   = $m[0][1] + strlen( $m[0][0] );
		return substr( $content, 0, $at ) . "\n" . dos_city_search_markup() . substr( $content, $at );
	},
	20
);

function dos_city_search_enqueue() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	wp_register_style( 'dos-city-search', false, array(), DOS_CITY_SEARCH_VERSION );
	wp_enqueue_style( 'dos-city-search' );
	wp_add_inline_style( 'dos-city-search', dos_city_search_css() );

	wp_register_script( 'dos-city-search', false, array(), DOS_CITY_SEARCH_VERSION, true );
	wp_enqueue_script( 'dos-city-search' );
	wp_add_inline_script( 'dos-city-search', dos_city_search_js() );
}

function dos_city_search_css() {
	$bg = sanitize_hex_color( dos_city_search_settings()['bg_color'] );
	$bg = $bg ? $bg : '#eaf2fc';
	return '.revnm-home .rv-sec.rv-city-search{background:' . $bg . ';border:1px solid rgba(0,0,0,.08);border-radius:10px;padding:28px 24px;margin:24px 0}'
		. '.rv-city-search h2{margin-top:0}'
		. '.rv-city-search .rv-search{display:flex;gap:8px;margin:16px 0 0;flex-wrap:wrap}'
		. '.rv-city-search .rv-cs-wrap{position:relative;flex:1 1 260px;min-width:0}'
		. '.rv-city-search input[type=text]{width:100%;max-width:none;box-sizing:border-box;margin:0;min-height:48px;padding:10px 14px;font:inherit;border:1px solid rgba(0,0,0,.25);border-radius:6px;background:#fff;color:#111}'
		. '.rv-city-search input:focus{outline:2px solid var(--rv-accent,#1a5fb4);outline-offset:1px}'
		. '.rv-city-search .rv-btn{width:auto;flex:0 0 auto;height:48px;min-height:48px;padding-top:0;padding-bottom:0;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box}'
		. '.rv-city-search input[type=text]{height:48px}'
		. '.rv-city-search .rv-cs-msg:empty{margin:0;min-height:0}'
		. '.rv-city-search .rv-cs-list{position:absolute;z-index:50;left:0;right:0;top:calc(100% + 4px);margin:0;padding:4px 0;list-style:none;background:#fff;border:1px solid rgba(0,0,0,.15);border-radius:6px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:320px;overflow-y:auto}'
		. '.rv-city-search .rv-cs-list li{margin:0;padding:10px 14px;cursor:pointer;color:#111;line-height:1.3}'
		. '.rv-city-search .rv-cs-list li[aria-selected=true],.rv-city-search .rv-cs-list li:hover{background:rgba(26,95,180,.1)}'
		. '.rv-city-search .rv-cs-list mark{background:none;color:inherit;font-weight:700}'
		. '.rv-city-search .rv-cs-msg{margin:8px 0 0;font-size:.9em;min-height:1.3em}'
		. '.rv-city-search .rv-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
		. '@media (max-width:480px){.revnm-home .rv-sec.rv-city-search{padding:20px 16px}.rv-city-search .rv-cs-wrap{flex-basis:100%}.rv-city-search .rv-btn{width:100%;margin:0}}';
}

function dos_city_search_js() {
	return <<<'JS'
(function () {
  var MAX = 8, cache = null;

  function norm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\bsaint\b/g, 'st').replace(/[.,'’]/g, '').replace(/[\s-]+/g, ' ').trim();
  }
  function slugGuess(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[.'’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function load(api) {
    if (!cache) {
      cache = fetch(api, { credentials: 'omit' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(function (rows) {
          return rows.map(function (row) {
            var i = row.lastIndexOf('|'), label = row.slice(0, i);
            return { label: label, slug: row.slice(i + 1), key: norm(label) };
          });
        })
        .catch(function (e) { cache = null; throw e; });
    }
    return cache;
  }
  function search(list, q) {
    var k = norm(q), starts = [], words = [];
    if (!k) return [];
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.key.indexOf(k) === 0) starts.push(c);
      else if (words.length < MAX && c.key.indexOf(' ' + k) > 0) words.push(c);
    }
    return starts.concat(words).slice(0, MAX);
  }
  function esc(s) {
    return s.replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; });
  }

  function init(sec) {
    var api = sec.getAttribute('data-api'), home = sec.getAttribute('data-home');
    var form = sec.querySelector('form'), input = sec.querySelector('input');
    var ul = sec.querySelector('.rv-cs-list'), msg = sec.querySelector('.rv-cs-msg');
    var results = [], active = -1;

    function go(c) {
      input.value = c.label;
      close();
      msg.textContent = 'Opening ' + c.label + '…';
      window.location.href = home.replace(/\/?$/, '/') + c.slug + '/';
    }
    function close() { ul.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; }
    function paint() {
      if (!results.length) { close(); return; }
      var k = input.value.trim();
      ul.innerHTML = results.map(function (c, i) {
        var lab = esc(c.label), n = k.length;
        if (n && c.label.toLowerCase().indexOf(k.toLowerCase()) === 0) lab = '<mark>' + esc(c.label.slice(0, n)) + '</mark>' + esc(c.label.slice(n));
        return '<li role="option" id="' + input.id + '-o' + i + '" aria-selected="' + (i === active) + '">' + lab + '</li>';
      }).join('');
      ul.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (active >= 0) input.setAttribute('aria-activedescendant', input.id + '-o' + active);
      else input.removeAttribute('aria-activedescendant');
    }
    function update() {
      msg.textContent = '';
      var q = input.value;
      load(api).then(function (list) {
        if (q !== input.value) return;
        results = search(list, q);
        active = -1;
        paint();
      }).catch(function () {});
    }

    input.addEventListener('focus', function () { load(api).catch(function () {}); });
    input.addEventListener('input', update);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
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
      if (!q) { input.focus(); return; }
      if (active >= 0 && results[active]) { go(results[active]); return; }
      msg.textContent = 'Searching…';
      load(api).then(function (list) {
        var k = norm(q), hits = search(list, q);
        var exact = hits.filter(function (c) { return c.key === k; })[0];
        if (exact || hits.length) { go(exact || hits[0]); return; }
        msg.textContent = 'We don’t have a report for “' + q + '” yet. Try a nearby city, or browse by state.';
      }).catch(function () {
        // Index unavailable: fall back to the page URL the city would have.
        if (/,?\s[a-z]{2}$/i.test(q)) window.location.href = home.replace(/\/?$/, '/') + slugGuess(q) + '/';
        else msg.textContent = 'Please enter a city and state, like “Kennewick, WA”.';
      });
    });
  }

  function boot() { document.querySelectorAll('.rv-city-search').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS;
}
