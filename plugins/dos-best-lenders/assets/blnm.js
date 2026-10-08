/* DoS Best Lenders: client-side filtering for the city grid and the city search box. No libraries. */
(function () {
  'use strict';

  var PAGE = 24;

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch];
    });
  }

  /* ---------- City grid ---------- */
  function initGrid(root) {
    var grid = root.querySelector('.blnm-grid');
    if (!grid) return;
    var cards = Array.prototype.slice.call(grid.children);
    var chips = Array.prototype.slice.call(root.querySelectorAll('.blnm-chip'));
    var selType = root.querySelector('[data-filter="type"]');
    var selMin = root.querySelector('[data-filter="min"]');
    var selSort = root.querySelector('[data-filter="sort"]');
    var selRating = root.querySelector('[data-filter="rating"]'); // only rendered when lenders have Google ratings
    var count = root.querySelector('.blnm-count');
    var empty = root.querySelector('.blnm-empty');
    var more = root.querySelector('.blnm-more');
    var reset = root.querySelector('.blnm-reset');
    var shown = PAGE;

    var sorters = {
      score: function (a, b) { return num(b, 'score') - num(a, 'score') || num(b, 'loans') - num(a, 'loans'); },
      // Highest Google rating first, then more reviews; unrated lenders go last.
      rating: function (a, b) { return stars(b) - stars(a) || num(b, 'reviews') - num(a, 'reviews') || num(b, 'score') - num(a, 'score'); },
      volume: function (a, b) { return num(b, 'loans') - num(a, 'loans') || num(b, 'score') - num(a, 'score'); },
      // Lowest rate first; lenders with no rate go last.
      rate: function (a, b) { return rate(a) - rate(b) || num(b, 'loans') - num(a, 'loans'); }
    };
    function num(el, k) { return parseFloat(el.getAttribute('data-' + k)) || 0; }
    function stars(el) { var r = parseFloat(el.getAttribute('data-rating')); return isNaN(r) ? -1 : r; }
    function rate(el) { var r = parseFloat(el.getAttribute('data-rate')); return isNaN(r) ? Infinity : r; }

    function selected() {
      return chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; })
        .map(function (c) { return c.getAttribute('data-loan'); });
    }

    function apply(resetPaging) {
      if (resetPaging) shown = PAGE;
      var loans = selected();
      var type = selType.value;
      var min = parseInt(selMin.value, 10) || 0;
      var minStars = selRating ? parseFloat(selRating.value) || 0 : 0;

      var matches = cards.filter(function (c) {
        if (type && c.getAttribute('data-type') !== type) return false;
        if (num(c, 'loans') < min) return false;
        if (minStars && stars(c) < minStars) return false;
        var have = ' ' + c.getAttribute('data-products') + ' ';
        return loans.every(function (l) { return have.indexOf(' ' + l + ' ') !== -1; });
      });
      matches.sort(sorters[selSort.value] || sorters.score);

      cards.forEach(function (c) { c.hidden = true; });
      matches.forEach(function (c, i) {
        grid.appendChild(c); // reorders in place
        c.hidden = i >= shown;
      });

      var total = matches.length;
      count.textContent = 'Showing ' + Math.min(shown, total) + ' of ' + total + ' lender' + (total === 1 ? '' : 's');
      empty.hidden = total !== 0;
      if (more) more.hidden = total <= shown;
      reset.hidden = !(loans.length || type || min || minStars || selSort.value !== 'score');
    }

    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        apply(true);
      });
    });
    [selType, selMin, selRating, selSort].forEach(function (s) { if (s) s.addEventListener('change', function () { apply(true); }); });
    if (more) more.addEventListener('click', function () { shown += PAGE; apply(false); });
    reset.addEventListener('click', function () {
      chips.forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
      selType.value = '';
      selMin.value = '0';
      if (selRating) selRating.value = '0';
      selSort.value = 'score';
      apply(true);
    });

    apply(true);
  }

  /* ---------- State hub: filter the city cards in place ----------
     The server already filtered from the query string and sorted by population (works without JS); this takes
     over once loaded and never reorders. Matching uses norm() below, the same folding the city search uses. */
  function initHub(root) {
    var grid = root.querySelector('.blnm-hub-grid');
    var form = root.querySelector('.blnm-hub-filters');
    if (!grid || !form) return;
    var cards = Array.prototype.slice.call(grid.children);
    var q = form.elements.q, has = form.elements.has;
    var count = root.querySelector('.blnm-count'), reset = root.querySelector('.blnm-reset');
    var timer = null;
    cards.forEach(function (c) { c._k = norm(c.getAttribute('data-name') || ''); });

    function n(c, k) { return parseInt(c.getAttribute('data-' + k), 10) || 0; }

    function apply() {
      var key = norm(q.value), only = has.checked, shown = 0;
      cards.forEach(function (c) {
        var ok = (!key || c._k.indexOf(key) !== -1) && (!only || n(c, 'lenders') > 0);
        c.hidden = !ok;
        if (ok) shown++;
      });
      count.textContent = 'Showing ' + shown + ' ' + (shown === 1 ? 'city' : 'cities');
      reset.hidden = !(q.value.trim() || only);
      if (window.history && history.replaceState) {
        var p = new URLSearchParams();
        if (q.value.trim()) p.set('q', q.value.trim());
        if (only) p.set('has', '1');
        var qs = p.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
      }
    }

    // The server rendered from the same query string, but read it again so a cached page still matches the URL.
    var params = new URLSearchParams(location.search);
    if (params.has('q')) q.value = params.get('q');
    if (params.has('has')) has.checked = params.get('has') !== '0' && params.get('has') !== '';

    form.addEventListener('input', function (e) {
      if (e.target === q) { clearTimeout(timer); timer = setTimeout(apply, 120); }
    });
    form.addEventListener('change', function (e) { if (e.target !== q) apply(); });
    form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); apply(); });
    reset.addEventListener('click', function (e) {
      e.preventDefault();
      q.value = ''; has.checked = false;
      apply();
    });
    apply();
  }

  /* ---------- City search: type a city name against blnm/v1/cities ----------
     Matching mirrors includes/class-search.php (the no-JS fallback); keep them in step. */
  var STATES = {
    AL: 'alabama', AK: 'alaska', AZ: 'arizona', AR: 'arkansas', CA: 'california', CO: 'colorado', CT: 'connecticut',
    DE: 'delaware', DC: 'district of columbia', FL: 'florida', GA: 'georgia', HI: 'hawaii', ID: 'idaho', IL: 'illinois',
    IN: 'indiana', IA: 'iowa', KS: 'kansas', KY: 'kentucky', LA: 'louisiana', ME: 'maine', MD: 'maryland',
    MA: 'massachusetts', MI: 'michigan', MN: 'minnesota', MS: 'mississippi', MO: 'missouri', MT: 'montana',
    NE: 'nebraska', NV: 'nevada', NH: 'new hampshire', NJ: 'new jersey', NM: 'new mexico', NY: 'new york',
    NC: 'north carolina', ND: 'north dakota', OH: 'ohio', OK: 'oklahoma', OR: 'oregon', PA: 'pennsylvania',
    RI: 'rhode island', SC: 'south carolina', SD: 'south dakota', TN: 'tennessee', TX: 'texas', UT: 'utah',
    VT: 'vermont', VA: 'virginia', WA: 'washington', WV: 'west virginia', WI: 'wisconsin', WY: 'wyoming'
  };

  // Lowercase, no accents or punctuation, single spaces; Saint/Mount/Fort folded to St/Mt/Ft.
  function norm(s) {
    return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[.'’]/g, '').replace(/[^a-z0-9]+/g, ' ').trim()
      .replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/\bfort\b/g, 'ft');
  }

  // State codes a normalised string can mean: exact code, exact name, or a name prefix of minPrefix+ letters.
  function resolveStates(s, minPrefix) {
    if (!s) return [];
    var code = s.toUpperCase();
    if (STATES[code]) return [code];
    var out = [];
    for (var ab in STATES) {
      if (STATES[ab] === s) return [ab];
      if (s.length >= minPrefix && STATES[ab].indexOf(s) === 0) out.push(ab);
    }
    return out;
  }

  // Ways to read a query: [{ c: city, st: [codes] | null }].
  function readings(raw) {
    raw = String(raw);
    var out = [], pos = raw.indexOf(',');
    if (pos !== -1) {
      var city = norm(raw.slice(0, pos)), state = norm(raw.slice(pos + 1));
      if (!city) return out;
      if (!state) out.push({ c: city, st: null });
      else {
        var st = resolveStates(state, 1);
        if (st.length) out.push({ c: city, st: st });
      }
      return out;
    }
    var n = norm(raw);
    if (!n) return out;
    out.push({ c: n, st: null });
    var t = n.split(' ');
    for (var i = 1; i < t.length; i++) {
      var s2 = resolveStates(t.slice(i).join(' '), 3);
      if (s2.length) out.push({ c: t.slice(0, i).join(' '), st: s2 });
    }
    return out;
  }

  // Rank rows ({ n: city, s: state, u: url }). rank 0/1 exact city (1 = no state given), 2/3 prefix, 4/5 later word.
  function match(rows, raw) {
    var rd = readings(raw), hits = [];
    if (!rd.length) return hits;
    rows.forEach(function (r) {
      var k = r.k !== undefined ? r.k : norm(r.n), best = null;
      rd.forEach(function (x) {
        if (x.st && x.st.indexOf(r.s) === -1) return;
        var tier;
        if (k === x.c) tier = 0;
        else if (k.indexOf(x.c) === 0) tier = 2;
        else if (k.indexOf(' ' + x.c) !== -1) tier = 4;
        else return;
        var rank = tier + (x.st ? 0 : 1);
        if (best === null || rank < best) best = rank;
      });
      if (best !== null) hits.push({ r: r, k: k, rank: best });
    });
    hits.sort(function (a, b) {
      return a.rank - b.rank || (a.k < b.k ? -1 : a.k > b.k ? 1 : 0) || (a.r.s < b.r.s ? -1 : a.r.s > b.r.s ? 1 : 0);
    });
    return hits.map(function (h) { h.r.rank = h.rank; return h.r; });
  }

  // Enter with nothing highlighted: { go: row } or { choose: [rows] } when the same city name is in several states.
  function decide(rows, raw) {
    var hits = match(rows, raw);
    if (!hits.length) return { none: true };
    var same = hits.filter(function (h) { return h.rank === hits[0].rank && norm(h.n) === norm(hits[0].n); });
    return same.length > 1 ? { choose: same } : { go: hits[0] };
  }

  function label(c) { return c.s ? c.n + ', ' + c.s : c.n; }

  function initSearch(sec) {
    var api = sec.getAttribute('data-api');
    var form = sec.querySelector('form'), input = sec.querySelector('input[type="text"]');
    var ul = sec.querySelector('.blnm-search-list'), msg = sec.querySelector('.blnm-search-msg');
    var list = null, loading = false, results = [], active = -1;

    function load(cb, fail) {
      if (list) { cb(); return; }
      if (loading) return;
      loading = true;
      fetch(api, { headers: { Accept: 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
        .then(function (rows) {
          list = (Array.isArray(rows) ? rows : []).map(function (c) { c.k = norm(c.n); return c; });
          loading = false;
          cb();
        })
        .catch(function () { loading = false; list = null; if (fail) fail(); });
    }

    function go(c) {
      input.value = label(c);
      close();
      msg.textContent = 'Opening ' + label(c) + '…';
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
      var k = input.value.trim().toLowerCase(), n = k.length;
      ul.innerHTML = results.map(function (c, i) {
        var lab = label(c), html = esc(lab);
        if (n && lab.toLowerCase().indexOf(k) === 0) html = '<mark>' + esc(lab.slice(0, n)) + '</mark>' + esc(lab.slice(n));
        return '<li role="option" id="' + input.id + '-o' + i + '" aria-selected="' + (i === active) + '">' + html + '</li>';
      }).join('');
      ul.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (active >= 0) input.setAttribute('aria-activedescendant', input.id + '-o' + active);
      else input.removeAttribute('aria-activedescendant');
    }
    // Typeahead starts at 2 characters.
    function update() {
      msg.textContent = '';
      if (norm(input.value).length < 2) { results = []; close(); return; }
      load(function () { results = match(list, input.value).slice(0, 8); active = -1; paint(); });
    }

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

    function submit() {
      var q = input.value.trim();
      if (active >= 0 && results[active]) { go(results[active]); return; }
      if (!q) { input.focus(); return; }
      var d = decide(list, q);
      if (d.go) { go(d.go); return; }
      if (d.choose) {
        results = d.choose; active = -1; paint();
        msg.textContent = 'There is more than one ' + d.choose[0].n + '. Pick the state you want.';
        return;
      }
      close();
      msg.textContent = 'We don’t have a page for “' + q + '” yet. We’re adding states through 2026.';
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      msg.textContent = '';
      // If the city list can't load, let the server-side fallback (?s=...) handle it.
      if (list) submit(); else load(submit, function () { form.submit(); });
    });
  }

  /* ---------- State carousel: previous/next buttons over a native scroll-snap list ---------- */
  function initCarousel(root) {
    var track = root.querySelector('.blnm-carousel-track');
    var prev = root.querySelector('[data-dir="-1"]');
    var next = root.querySelector('[data-dir="1"]');
    if (!track || !prev || !next) return;
    var reduce = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    prev.hidden = false;
    next.hidden = false;
    function update() {
      var max = track.scrollWidth - track.clientWidth;
      prev.disabled = track.scrollLeft <= 1;
      next.disabled = track.scrollLeft >= max - 1;
    }
    function go(dir) {
      var step = Math.max(track.clientWidth * 0.85, 120) * dir;
      var to = track.scrollLeft + step;
      if (typeof track.scrollTo === 'function') track.scrollTo({ left: to, behavior: reduce && reduce.matches ? 'auto' : 'smooth' });
      else track.scrollLeft = to;
    }
    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    var queued = false;
    track.addEventListener('scroll', function () {
      if (queued) return;
      queued = true;
      (window.requestAnimationFrame || setTimeout)(function () { queued = false; update(); });
    }, { passive: true });
    window.addEventListener('resize', update);
    update();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-blnm-carousel]'), initCarousel);
    Array.prototype.forEach.call(document.querySelectorAll('[data-blnm-grid]'), initGrid);
    Array.prototype.forEach.call(document.querySelectorAll('[data-blnm-hub]'), initHub);
    Array.prototype.forEach.call(document.querySelectorAll('.blnm-search'), initSearch);
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = { norm: norm, match: match, decide: decide, readings: readings, label: label };
  if (typeof document === 'undefined') return;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
