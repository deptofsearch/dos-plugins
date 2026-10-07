(function () {
  'use strict';

  /* ---------- Card grid: search + amenity chips, all client side ---------- */
  function initGrid(root) {
    var filters = root.querySelector('[data-osn-filters]');
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-osn-card]'));
    var q = root.querySelector('[data-osn-q]');
    var chips = Array.prototype.slice.call(root.querySelectorAll('[data-osn-chip]'));
    var clear = root.querySelector('[data-osn-clear]');
    var count = root.querySelector('[data-osn-count]');
    var empty = root.querySelector('[data-osn-empty]');
    var dBtns = Array.prototype.slice.call(root.querySelectorAll('[data-osn-district]'));
    var hBtns = Array.prototype.slice.call(root.querySelectorAll('[data-osn-hood]'));
    var hRow = root.querySelector('[data-osn-hoods]');
    if (hRow && hRow.parentNode) hRow.parentNode.hidden = true;
    var area = { district: '', hood: '' };
    if (!cards.length) return;
    // Filters stay hidden until now so crawlers and no-JS visitors just see every card.
    if (filters) filters.hidden = false;

    function update() {
      var term = q ? q.value.trim().toLowerCase() : '';
      var on = chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; })
        .map(function (c) { return c.getAttribute('data-osn-chip'); });
      var shown = 0;
      cards.forEach(function (card) {
        var ok = !term || (card.getAttribute('data-name') || '').indexOf(term) !== -1;
        if (ok && on.length) {
          var have = ' ' + (card.getAttribute('data-amenities') || '') + ' ';
          ok = on.every(function (s) { return have.indexOf(' ' + s + ' ') !== -1; });
        }
        if (ok && area.district) ok = card.getAttribute('data-district') === area.district;
        if (ok && area.hood) ok = card.getAttribute('data-hood') === area.hood;
        card.hidden = !ok;
        if (ok) shown++;
      });
      if (count) count.textContent = shown + (shown === 1 ? ' place' : ' places');
      if (empty) empty.hidden = shown !== 0;
      if (clear) clear.hidden = !on.length && !term && !area.district && !area.hood;
    }

    // Mobile: chips past the 6th sit behind a "More filters (N)" toggle (CSS shows it at <= 640px only).
    var more = root.querySelector('[data-osn-more]');
    var chipBox = root.querySelector('[data-osn-amenity-chips]');
    var moreOpen = false;
    function syncMore() {
      if (!more || !chipBox) return;
      var activeExtra = chips.some(function (c) {
        return c.classList.contains('osn-chip--extra') && c.getAttribute('aria-pressed') === 'true';
      });
      var open = moreOpen || activeExtra; // A hidden chip that is active keeps the panel open.
      var n = more.getAttribute('data-more');
      chipBox.classList.add('is-collapsible');
      chipBox.classList.toggle('is-open', open);
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
      more.textContent = open ? 'Fewer filters' : 'More filters (' + n + ')';
      if (activeExtra) more.setAttribute('aria-disabled', 'true'); else more.removeAttribute('aria-disabled');
    }
    if (more) {
      more.addEventListener('click', function () {
        if (more.getAttribute('aria-disabled') === 'true') return;
        moreOpen = !moreOpen;
        syncMore();
      });
      syncMore();
    }

    // District / neighborhood chips. URL state (?district=slug / ?hood=slug, short slugs) via replaceState so links share.
    function writeUrl() {
      if (!window.history || !history.replaceState || !window.URLSearchParams) return;
      var p = new URLSearchParams(window.location.search);
      p.delete('district'); p.delete('hood');
      if (area.hood) p.set('hood', area.hood); else if (area.district) p.set('district', area.district);
      var qs = p.toString();
      history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    }
    function setArea(district, hood, persist) {
      area.district = district; area.hood = hood;
      dBtns.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-osn-district') === district ? 'true' : 'false'); });
      var any = false;
      hBtns.forEach(function (b) {
        var vis = !!district && b.getAttribute('data-district') === district;
        b.hidden = !vis;
        if (vis) any = true;
        b.setAttribute('aria-pressed', vis && b.getAttribute('data-osn-hood') === hood ? 'true' : 'false');
      });
      if (hRow) { hRow.hidden = !any; if (hRow.parentNode) hRow.parentNode.hidden = !any; }
      update();
      if (persist) writeUrl();
    }
    if (dBtns.length) {
      dBtns.forEach(function (b) {
        b.addEventListener('click', function () { setArea(b.getAttribute('data-osn-district'), '', true); });
      });
      hBtns.forEach(function (b) {
        b.addEventListener('click', function () {
          var h = b.getAttribute('data-osn-hood');
          setArea(b.getAttribute('data-district'), area.hood === h ? '' : h, true);
        });
      });
      if (window.URLSearchParams) {
        var sp = new URLSearchParams(window.location.search), wantH = sp.get('hood'), wantD = (sp.get('district') || '').replace(/-district$/, '');
        var hb = wantH ? hBtns.filter(function (b) { return b.getAttribute('data-osn-hood') === wantH; })[0] : null;
        if (hb) setArea(hb.getAttribute('data-district'), wantH, false);
        else if (wantD && dBtns.some(function (b) { return b.getAttribute('data-osn-district') === wantD; })) setArea(wantD, '', false);
        else if (sp.get('district') && dBtns.some(function (b) { return b.getAttribute('data-osn-district') === sp.get('district'); })) setArea(sp.get('district'), '', false);
      }
    }

    if (q) q.addEventListener('input', update);
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        update();
        syncMore();
      });
    });
    if (clear) clear.addEventListener('click', function () {
      chips.forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
      if (dBtns.length) setArea('', '', true);
      if (q) q.value = '';
      update();
      syncMore();
      if (q) q.focus();
    });
  }

  /* ---------- Venue page: open now + today's hours row ---------- */
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  function nowIn(tz) {
    var parts = new Intl.DateTimeFormat('en-US', {
      timeZone: tz, weekday: 'short', hour: 'numeric', minute: 'numeric', hourCycle: 'h23'
    }).formatToParts(new Date());
    var map = {};
    parts.forEach(function (p) { map[p.type] = p.value; });
    var day = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 }[map.weekday];
    return { day: day, min: (parseInt(map.hour, 10) % 24) * 60 + parseInt(map.minute, 10) };
  }
  function fmtMin(m) {
    m = m % 1440;
    var h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM';
    return (h % 12 || 12) + (mm ? ':' + (mm < 10 ? '0' : '') + mm : '') + ' ' + ap;
  }
  // One day's text ("11 AM–2 PM, 5 PM–2 AM", "Closed", "Open 24 hours") to [[open, close]] minutes.
  // A close past midnight is > 1440. Returns null when unparseable. Mirrors Util::parse_day_ranges() in PHP.
  function parseDay(text) {
    var s = String(text).replace(/[\u202F\u00A0\u2009]/g, ' ').trim();
    if (!s) return null;
    if (/^closed$/i.test(s)) return [];
    if (/^open\s+24\s+hours?$/i.test(s)) return [[0, 1440]];
    var out = [], parts = s.split(/\s*,\s*/);
    for (var i = 0; i < parts.length; i++) {
      var m = /^(\d{1,2})(?::(\d{2}))?\s*(?:([AaPp])\.?[Mm]?\.?)?\s*[\u2013\u2014-]\s*(\d{1,2})(?::(\d{2}))?\s*([AaPp])\.?[Mm]?\.?$/.exec(parts[i]);
      if (!m) return null;
      var h1 = +m[1], m1 = m[2] ? +m[2] : 0, h2 = +m[4], m2 = m[5] ? +m[5] : 0;
      if (h1 < 1 || h1 > 12 || h2 < 1 || h2 > 12 || m1 > 59 || m2 > 59) return null;
      var pm2 = /p/i.test(m[6]), has = !!m[3], pm1 = has ? /p/i.test(m[3]) : pm2;
      var c = ((h2 % 12) + (pm2 ? 12 : 0)) * 60 + m2;
      var o = ((h1 % 12) + (pm1 ? 12 : 0)) * 60 + m1;
      if (!has && o > c) o = ((h1 % 12) + (pm1 ? 0 : 12)) * 60 + m1; // "11-2 PM": open is AM
      if (c <= o) c += 1440;
      out.push([o, c]);
    }
    return out;
  }
  function initOpen(el) {
    var hours, tz = el.getAttribute('data-tz');
    try { hours = JSON.parse(el.getAttribute('data-hours') || '{}'); } catch (e) { return; }
    if (!tz) return;
    var now;
    try { now = nowIn(tz); } catch (e) { return; }
    var WEEK = 7 * 1440, t = now.day * 1440 + now.min;

    var parsed = 0, openNow = false, closeAt = null, always = false;
    DAYS.forEach(function (name, d) {
      if (hours[name] === undefined) return;
      var ranges = parseDay(hours[name]);
      if (ranges === null) return;
      parsed++;
      ranges.forEach(function (r) {
        if (r[0] === 0 && r[1] === 1440) always = true;
        var o = d * 1440 + r[0], c = d * 1440 + r[1];
        [t, t + WEEK].forEach(function (x) {
          if (x >= o && x < c) { openNow = true; closeAt = r[1]; }
        });
      });
    });
    if (!parsed) return;
    // "Open 24 hours" every day reads better than a closing time.
    var all24 = always && DAYS.every(function (n) { return hours[n] !== undefined && /^open\s+24/i.test(hours[n]); });

    el.hidden = false;
    el.setAttribute('data-state', openNow ? 'open' : 'closed');
    el.textContent = openNow
      ? (all24 ? 'Open 24 hours' : 'Open now · closes ' + fmtMin(closeAt))
      : 'Closed now';

    var row = document.querySelector('[data-osn-hours-table] tr[data-day="' + now.day + '"]');
    if (row) row.setAttribute('aria-current', 'date');
  }

  /* ---------- City search typeahead ---------- */
  function norm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/[.,'’]/g, '').replace(/[\s-]+/g, ' ').trim();
  }
  function esc(s) {
    return s.replace(/[&<>"]/g, function (ch) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch]; });
  }
  function initCitySearch(sec) {
    var list;
    try { list = JSON.parse(sec.getAttribute('data-cities') || '[]'); } catch (e) { return; }
    var src = sec.getAttribute('data-src'); // Big lists are fetched on first focus instead of embedded.
    if (!list.length && !src) return;
    list.forEach(function (c) { c.k = norm(c.n); });
    var loading = false;
    function lazyLoad() {
      if (!src || loading) return;
      loading = true;
      fetch(src, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
        list = (rows || []).map(function (c) { return { n: c.n, u: c.p || c.u, s: c.s, k: norm(c.n) }; });
        src = null;
        if (document.activeElement === input) update();
      }).catch(function () { loading = false; });
    }

    var browse = sec.getAttribute('data-browse');
    var form = sec.querySelector('form'), input = sec.querySelector('input');
    var ul = sec.querySelector('.osn-city-search__list'), msg = sec.querySelector('.osn-city-search__msg');
    var results = [], active = -1;

    function search(q) {
      var k = norm(q);
      if (!k) return list.slice(0, 50);
      var starts = [], words = [];
      list.forEach(function (c) {
        if (c.k.indexOf(k) === 0) starts.push(c);
        else if (c.k.indexOf(' ' + k) > 0) words.push(c);
      });
      return starts.concat(words).slice(0, 50);
    }
    function close() {
      ul.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    }
    function go(c) {
      input.value = c.n;
      close();
      msg.textContent = 'Opening ' + c.n + '…';
      window.location.href = c.u;
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
    function update() { msg.textContent = ''; results = search(input.value); active = -1; paint(); }

    input.addEventListener('focus', function () { lazyLoad(); update(); });
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

  /* ---------- Homepage "Popular cities" carousel: scroll-snap track + prev/next ---------- */
  function initCarousel(root) {
    var track = root.querySelector('.osn-hc__track'), prev = root.querySelector('.osn-hc__prev'), next = root.querySelector('.osn-hc__next');
    if (!track || !prev || !next) return;
    function step() {
      var s = track.querySelector('.osn-hc__slide');
      if (!s) return track.clientWidth;
      var w = s.getBoundingClientRect().width + 16;
      return w * Math.max(1, Math.floor(track.clientWidth / w));
    }
    function sync() {
      prev.hidden = track.scrollLeft <= 4;
      next.hidden = track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    }
    prev.addEventListener('click', function () { track.scrollBy({ left: -step() }); });
    next.addEventListener('click', function () { track.scrollBy({ left: step() }); });
    track.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
  }

  /* ---------- Browse by neighborhood accordion: open the district named by ?district= / ?hood= ---------- */
  function initHoods(sec) {
    if (!window.URLSearchParams) return;
    var sp = new URLSearchParams(window.location.search), d = sp.get('district') || '', h = sp.get('hood');
    var groups = Array.prototype.slice.call(sec.querySelectorAll('[data-osn-hoods-group]'));
    groups.forEach(function (g) {
      var hit = false;
      if (h) hit = Array.prototype.some.call(g.querySelectorAll('[data-osn-hood-link]'), function (li) { return li.getAttribute('data-osn-hood-link') === h; });
      else if (d) hit = g.getAttribute('data-osn-hoods-group') === d || g.getAttribute('data-osn-hoods-group') === d.replace(/-district$/, '');
      if (hit) g.open = true;
    });
  }

  function boot() {
    document.querySelectorAll('[data-osn-carousel]').forEach(initCarousel);
    document.querySelectorAll('[data-osn-grid]').forEach(initGrid);
    document.querySelectorAll('#hoods.osn-hoods').forEach(initHoods);
    document.querySelectorAll('[data-osn-open]').forEach(initOpen);
    document.querySelectorAll('[data-osn-city-search]').forEach(initCitySearch);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
