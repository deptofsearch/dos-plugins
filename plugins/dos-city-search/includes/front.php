<?php
/**
 * Front end: the search box markup, where it goes on the homepage, and its CSS and JS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Above this many cities the list is fetched from the REST API instead of riding along in the
 * markup, so a big site doesn't ship hundreds of kilobytes of JSON on every homepage view.
 */
define( 'DOS_CITY_SEARCH_INLINE_MAX', 200 );

/**
 * Class names that mean a search box is already on the page, whichever plugin or shortcode put it there.
 */
function dos_city_search_markers() {
	return array( 'dos-city-search', 'rv-city-search', 'ohi-city-search' );
}

function dos_city_search_register_front() {
	// [dos_city_search] is the name; the other two keep content written for the plugins this one replaced working.
	add_shortcode( 'dos_city_search', 'dos_city_search_markup' );
	add_shortcode( 'revnm_city_search', 'dos_city_search_markup' );
	add_shortcode( 'ohi_city_search', 'dos_city_search_markup' );

	add_filter( 'the_content', 'dos_city_search_filter_content', 20 ); // After shortcodes (11), so an embed is already expanded and can't be split.
}

/**
 * "a b  a" + "c" -> "a b c": the canonical classes plus the site's extras, each once.
 */
function dos_city_search_classes( $canonical, $extra ) {
	$all = preg_split( '/\s+/', trim( $canonical . ' ' . $extra ), -1, PREG_SPLIT_NO_EMPTY );
	return implode( ' ', array_unique( $all ) );
}

/**
 * The "browse all cities" link shown when a search has no match: the hub page named in the
 * settings, when it is published. Empty when the setting is empty or the page isn't there.
 */
function dos_city_search_browse_url() {
	$slug = dos_city_search_settings()['browse_slug'];
	$url  = '';
	if ( '' !== $slug ) {
		$hub = get_page_by_path( $slug );
		$url = ( $hub && 'publish' === $hub->post_status ) ? get_permalink( $hub ) : '';
	}
	$url = apply_filters( 'dos_ohi_cs_browse_url', $url ); // The old plugin's filter, for sites that already use it.
	return (string) apply_filters( 'dos_city_search_browse_url', $url );
}

/**
 * Markup for the search section. Small lists ride along as JSON, so there is no extra request;
 * the REST URL is always present for the rest. Without JavaScript the form is a normal site search.
 */
function dos_city_search_markup() {
	static $n = 0;
	$n++;
	$id     = 'dos-cs-' . $n;
	$o      = dos_city_search_settings();
	$source = dos_city_search_resolve_source();
	$browse = dos_city_search_browse_url();

	dos_city_search_enqueue();

	$attrs  = ' data-api="' . esc_url( rest_url( 'dos-city-search/v1/cities' ) ) . '"';
	$attrs .= ' data-home="' . esc_url( home_url( '/' ) ) . '"';
	$attrs .= ' data-source="' . esc_attr( $source ) . '"';
	$attrs .= ' data-max="' . esc_attr( (int) $o['max_results'] ) . '"';
	$attrs .= ' data-all="' . ( $o['show_all_on_focus'] ? '1' : '0' ) . '"';
	if ( '' !== $browse ) {
		$attrs .= ' data-browse="' . esc_url( $browse ) . '"';
	}
	// The count is kept with the index meta, so a big site's homepage does not load the whole list
	// just to learn it is too big to inline. No count yet (first run after an update): load it once.
	$meta  = get_option( DOS_CITY_SEARCH_META, array() );
	$count = is_array( $meta ) && isset( $meta['count'] ) ? (int) $meta['count'] : null;
	$index = null;
	if ( null === $count ) {
		$index = dos_city_search_get_index();
		$count = count( $index );
	}
	if ( $count <= DOS_CITY_SEARCH_INLINE_MAX ) {
		$index = null === $index ? dos_city_search_get_index() : $index;
		// The stored count can lag a rebuild; the list just loaded is the truth.
		if ( count( $index ) <= DOS_CITY_SEARCH_INLINE_MAX ) {
			$attrs .= ' data-cities="' . esc_attr( wp_json_encode( $index ) ) . '"';
		}
	}

	$label = 'taxonomy' === $source ? 'City' : 'City, State';

	ob_start();
	?>
<section class="<?php echo esc_attr( dos_city_search_classes( 'dos-city-search', $o['section_class'] ) ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput -- each value is escaped above ?>>
<h2><?php echo esc_html( $o['heading'] ); ?></h2>
<?php if ( '' !== $o['intro'] ) : ?><p><?php echo esc_html( $o['intro'] ); ?></p><?php endif; ?>
<form class="<?php echo esc_attr( dos_city_search_classes( 'dos-cs-form', $o['form_class'] ) ); ?>" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" autocomplete="off">
<label class="dos-cs-sr" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
<div class="dos-cs-wrap">
<input id="<?php echo esc_attr( $id ); ?>" name="s" type="text" placeholder="<?php echo esc_attr( $o['placeholder'] ); ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>-list" spellcheck="false" autocapitalize="words" enterkeyhint="go">
<ul id="<?php echo esc_attr( $id ); ?>-list" class="dos-cs-list" role="listbox" hidden></ul>
</div>
<button type="submit" class="<?php echo esc_attr( dos_city_search_classes( 'dos-cs-btn', $o['button_class'] ) ); ?>"><?php echo esc_html( $o['button_label'] ); ?></button>
</form>
<p class="dos-cs-msg" role="status" aria-live="polite"></p>
</section>
	<?php
	return ob_get_clean();
}

/**
 * Where the section goes in the homepage content, as a byte offset, or false for "nowhere".
 *
 * hero_class: right after the first <section> carrying that class; nothing if there isn't one.
 * auto: after the first <section> when the page opens with one, otherwise right before the
 * first <h2> (after the H1 and intro), otherwise after the first paragraph.
 */
function dos_city_search_insert_at( $content, $mode, $hero_class ) {
	if ( 'hero_class' === $mode ) {
		if ( ! preg_match( '/<section[^>]*\b' . preg_quote( $hero_class, '/' ) . '\b[^>]*>.*?<\/section>/s', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return false;
		}
		return $m[0][1] + strlen( $m[0][0] );
	}

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

/**
 * One insertion per request. A tiny accessor so a test can stand in for "a new request".
 */
function dos_city_search_inserted( $set = null ) {
	static $done = false;
	if ( null !== $set ) {
		$done = (bool) $set;
	}
	return $done;
}

function dos_city_search_filter_content( $content ) {
	$o = dos_city_search_settings();

	if ( dos_city_search_inserted() || ! $o['auto_insert'] || ! is_front_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	foreach ( dos_city_search_markers() as $marker ) {
		if ( false !== strpos( $content, $marker ) ) {
			return $content;
		}
	}

	$at = dos_city_search_insert_at( $content, $o['insert_after'], $o['hero_class'] );
	if ( false === $at ) {
		return $content;
	}

	dos_city_search_inserted( true );
	return substr( $content, 0, $at ) . ( $at ? "\n" : '' ) . trim( dos_city_search_markup() ) . "\n" . substr( $content, $at );
}

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

/**
 * Self-contained CSS under .dos-city-search. It never targets a site's own classes (.rv-*, .ohi-*).
 *
 * A site that adds its own classes through the section/form/button settings has CSS written for
 * them, so this stylesheet yields to it where it would otherwise fight:
 * - the button's colours, radius and type are emitted only when no extra button class is set;
 *   its size and alignment always are, because the row depends on them;
 * - the heading and intro spacing tweaks are emitted only when no extra section class is set.
 * The section box itself is raised to three-class specificity. A site that scoped its own rule to
 * win over the theme (the REVNM homepage did, with a body-level wrapper) would otherwise beat the
 * background and padding configured here, and the colour picked on this plugin's page would
 * silently do nothing.
 */
function dos_city_search_css() {
	$o      = dos_city_search_settings();
	$d      = dos_city_search_defaults();
	$bg     = sanitize_hex_color( $o['bg_color'] ) ? $o['bg_color'] : $d['bg_color'];
	$accent = sanitize_hex_color( $o['accent_color'] ) ? $o['accent_color'] : $d['accent_color'];

	$site_box = '' !== $o['section_class'];
	$own_btn  = '' === $o['button_class'];

	$box   = '.dos-city-search.dos-city-search.dos-city-search';
	$pad   = $site_box ? '28px 24px' : '24px';
	$ctl_r = $site_box ? '6px' : '8px';
	// The hover tint is the REVNM-era blue when the site brings its own section classes (so that box is unchanged), neutral otherwise.
	$hover = $site_box ? 'rgba(26,95,180,.1)' : 'rgba(0,0,0,.06)';
	// REVNM's stylesheet defines --rv-accent; follow it when present so the focus ring matches the site.
	$ring = $site_box ? 'var(--rv-accent,var(--dos-cs-accent))' : 'var(--dos-cs-accent)';

	$css  = '.dos-city-search{--dos-cs-bg:' . $bg . ';--dos-cs-accent:' . $accent . '}';
	$css .= $box . '{background:var(--dos-cs-bg);border:1px solid rgba(0,0,0,.08);border-radius:' . ( $site_box ? '10px' : '12px' ) . ';padding:' . $pad . ';margin:24px 0;box-sizing:border-box}';
	$css .= '.dos-city-search h2{margin-top:0}';
	if ( ! $site_box ) {
		$css .= '.dos-city-search h2{margin:0 0 6px}.dos-city-search>p:not(.dos-cs-msg){margin:0}';
	}
	$css .= '.dos-city-search .dos-cs-form{display:flex;gap:8px;margin:16px 0 0;flex-wrap:wrap}'
		. '.dos-city-search .dos-cs-wrap{position:relative;flex:1 1 260px;min-width:0}'
		. '.dos-city-search input[type=text]{width:100%;max-width:none;box-sizing:border-box;margin:0;min-height:48px;height:48px;padding:10px 14px;font:inherit;font-size:max(16px,1em);border:1px solid rgba(0,0,0,.25);border-radius:' . $ctl_r . ';background:#fff;color:#111;box-shadow:none' . ( $site_box ? '' : ';display:block;line-height:1.2' ) . '}'
		. '.dos-city-search input[type=text]:focus{outline:2px solid ' . $ring . ';outline-offset:1px' . ( $site_box ? '' : ';border-color:var(--dos-cs-accent)' ) . '}'
		. '.dos-city-search .dos-cs-btn{width:auto;flex:0 0 auto;height:48px;min-height:48px;padding-top:0;padding-bottom:0;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box}';
	if ( $own_btn ) {
		$css .= '.dos-city-search .dos-cs-btn{margin:0;padding-left:20px;padding-right:20px;border:0;border-radius:' . $ctl_r . ';background:var(--dos-cs-accent);color:#fff;font:inherit;font-size:16px;font-weight:600;line-height:1;cursor:pointer;box-shadow:none;text-transform:none;letter-spacing:normal}'
			. '.dos-city-search .dos-cs-btn:hover{filter:brightness(1.08)}'
			. '.dos-city-search .dos-cs-btn:focus-visible{outline:2px solid var(--dos-cs-accent);outline-offset:2px}';
	}
	$css .= '.dos-city-search .dos-cs-msg{margin:8px 0 0;font-size:.9em;min-height:1.3em}'
		. '.dos-city-search .dos-cs-msg:empty{margin:0;min-height:0}'
		. '.dos-city-search .dos-cs-msg a{color:var(--dos-cs-accent)}'
		. '.dos-city-search .dos-cs-list{position:absolute;z-index:50;left:0;right:0;top:calc(100% + 4px);margin:0;padding:4px 0;list-style:none;background:#fff;border:1px solid rgba(0,0,0,.15);border-radius:' . $ctl_r . ';box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:min(320px,50vh);overflow-y:auto;text-align:left}'
		. '.dos-city-search .dos-cs-list li{list-style:none;margin:0;padding:10px 14px;cursor:pointer;color:#111;line-height:1.3;background:none}'
		. '.dos-city-search .dos-cs-list li::before{content:none;display:none}'
		. '.dos-city-search .dos-cs-list li[aria-selected=true],.dos-city-search .dos-cs-list li:hover{background:' . $hover . '}'
		. '.dos-city-search .dos-cs-list mark{background:none;color:inherit;font-weight:700;padding:0}'
		. '.dos-city-search .dos-cs-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}'
		. '@media (max-width:480px){' . $box . '{padding:' . ( $site_box ? '20px 16px' : '16px' ) . '}.dos-city-search .dos-cs-wrap{flex-basis:100%}.dos-city-search .dos-cs-btn{width:100%;margin:0}}';

	return $css;
}

function dos_city_search_js() {
	return <<<'JS'
(function () {
  var fetched = {};

  function norm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/[.,'’]/g, '').replace(/[\s-]+/g, ' ').trim();
  }
  function slugGuess(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[.'’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  function esc(s) {
    return s.replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; });
  }
  function prep(rows) {
    rows.forEach(function (c) { c.k = norm(c.n); });
    return rows;
  }
  // One request per page per URL, however many boxes there are; a failure is not remembered.
  function fetchList(api) {
    if (!fetched[api]) {
      fetched[api] = fetch(api, { credentials: 'omit' })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
        .then(prep)
        .catch(function (e) { delete fetched[api]; throw e; });
    }
    return fetched[api];
  }

  function init(sec) {
    var inline = null;
    try { inline = JSON.parse(sec.getAttribute('data-cities') || 'null'); } catch (e) { inline = null; }
    // An inline list that is empty means a site with no city pages: leave the form a plain site search.
    if (inline && !inline.length) return;
    if (inline) prep(inline);

    var api = sec.getAttribute('data-api'), home = sec.getAttribute('data-home');
    var browse = sec.getAttribute('data-browse');
    var max = parseInt(sec.getAttribute('data-max'), 10) || 0;
    var all = sec.getAttribute('data-all') === '1';
    var bySlug = sec.getAttribute('data-source') === 'slug_state';
    var form = sec.querySelector('form'), input = sec.querySelector('input');
    var ul = sec.querySelector('.dos-cs-list'), msg = sec.querySelector('.dos-cs-msg');
    var results = [], active = -1;

    function load() { return inline ? Promise.resolve(inline) : fetchList(api); }

    // Empty box: every city, if the site wants that. Otherwise names that start with the query, then
    // names with a word that does. Capped at max when max is set.
    function search(list, q) {
      var k = norm(q), out;
      if (!k) {
        if (!all) return [];
        out = list.slice();
      } else {
        var starts = [], words = [];
        for (var i = 0; i < list.length; i++) {
          var c = list[i];
          if (c.k.indexOf(k) === 0) starts.push(c);
          else if ((!max || words.length < max) && c.k.indexOf(' ' + k) > 0) words.push(c);
        }
        out = starts.concat(words);
      }
      return max ? out.slice(0, max) : out;
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
      var q = input.value;
      load().then(function (list) {
        if (q !== input.value) return;
        results = search(list, q);
        active = -1;
        paint();
      }).catch(function () {});
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
      msg.textContent = 'Searching…';
      load().then(function (list) {
        var k = norm(q), hits = search(list, q);
        var exact = hits.filter(function (c) { return c.k === k; })[0];
        if (exact || hits.length) { go(exact || hits[0]); return; }
        close();
        msg.innerHTML = 'No city page for “' + esc(q) + '” yet.' + (browse ? ' <a href="' + esc(browse) + '">See every city</a>.' : '');
      }).catch(function () {
        // The list could not be fetched.
        if (bySlug) {
          // City pages are named city-st, so the page the city would have is a good guess.
          if (/,?\s[a-z]{2}$/i.test(q)) window.location.href = home.replace(/\/?$/, '/') + slugGuess(q) + '/';
          else msg.textContent = 'Please enter a city and state, like “Kennewick, WA”.';
        } else {
          // No guessable address: hand the search to the site.
          form.submit();
        }
      });
    });
  }

  function boot() { document.querySelectorAll('.dos-city-search').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
JS;
}
