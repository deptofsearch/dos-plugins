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

  /* ---------- City search: "City, ST" against blnm/v1/cities ---------- */
  function norm(s) {
    return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/[.,'’]/g, '').replace(/[\s-]+/g, ' ').trim();
  }

  function initSearch(sec) {
    var api = sec.getAttribute('data-api');
    var form = sec.querySelector('form'), input = sec.querySelector('input[type="text"]');
    var ul = sec.querySelector('.blnm-search-list'), msg = sec.querySelector('.blnm-search-msg');
    var list = null, loading = false, results = [], active = -1;

    function load(cb) {
      if (list) { cb(); return; }
      if (loading) return;
      loading = true;
      fetch(api, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          list = (Array.isArray(rows) ? rows : []).map(function (c) { c.k = norm(c.n); return c; });
          loading = false;
          cb();
        })
        .catch(function () { loading = false; list = null; });
    }

    // Empty box: every city. Otherwise names that start with the query, then names with a word that does.
    function search(q) {
      var k = norm(q);
      if (!list) return [];
      if (!k) return list.slice(0, 50);
      var starts = [], words = [];
      list.forEach(function (c) {
        if (c.k.indexOf(k) === 0) starts.push(c);
        else if (c.k.indexOf(' ' + k) > 0) words.push(c);
      });
      return starts.concat(words).slice(0, 50);
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
      load(function () { results = search(input.value); active = -1; paint(); });
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
      var q = input.value.trim();
      if (!list) { e.preventDefault(); load(function () { form.dispatchEvent(new Event('submit', { cancelable: true })); }); return; }
      e.preventDefault();
      if (active >= 0 && results[active]) { go(results[active]); return; }
      if (!q) { input.focus(); update(); return; }
      var k = norm(q), hits = search(q);
      var exact = hits.filter(function (c) { return c.k === k; })[0];
      if (exact || hits.length) { go(exact || hits[0]); return; }
      close();
      msg.innerHTML = 'No city page for “' + esc(q) + '” yet. Check back soon.';
    });
  }

  /* Reviewer summaries start open (no-JS default) and collapse on phones. */
  function initSays() {
    if (!window.matchMedia || !window.matchMedia('(max-width: 640px)').matches) return;
    Array.prototype.forEach.call(document.querySelectorAll('.blnm-says'), function (d) { d.removeAttribute('open'); });
  }

  function boot() {
    initSays();
    Array.prototype.forEach.call(document.querySelectorAll('[data-blnm-grid]'), initGrid);
    Array.prototype.forEach.call(document.querySelectorAll('.blnm-search'), initSearch);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
