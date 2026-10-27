(function () {
  'use strict';

  /* DoS - Agents: search, chip filters, sort and "Show more agents", all client side.
     The server renders every card in "Local first" order; this only hides, reorders and reveals.
     Bump DOS_AGENTS_VERSION whenever this file changes (the CDN caches ?ver= URLs for years). */

  var SORTS = {
    // Each returns a negative number when a belongs before b. Ties fall back to the server order (data-i).
    local: function (a, b) { return (a.tier - b.tier) || (b.sales - a.sales); },
    sales: function (a, b) { return b.sales - a.sales; },
    // Rating to one decimal (4.96 and 5.0 both read "5.0"), then most reviews. An agent with fewer
    // than MIN_REVIEWS reviews goes below everyone else: one 5-star review is not a 5.0.
    rating: function (a, b) {
      return ((a.reviews < MIN_REVIEWS) - (b.reviews < MIN_REVIEWS)) ||
        (round1(b.rating) - round1(a.rating)) || (b.reviews - a.reviews);
    },
    reviews: function (a, b) { return b.reviews - a.reviews; },
    recent: function (a, b) { return b.last - a.last; }, // no review date is 0, so it sorts last
    years: function (a, b) { return b.years - a.years; }
  };

  var MIN_REVIEWS = 5;

  function round1(n) { return Math.round(n * 10) / 10; }

  // Fills "Showing %1$d of %2$d agents" style templates (PHP supplies them, already translated).
  function fmt(tpl, a, b) {
    return String(tpl).replace(/%1\$[ds]/g, a).replace(/%2\$[ds]/g, b).replace(/%d/g, a);
  }

  function num(el, attr) {
    var v = parseFloat(el.getAttribute(attr));
    return isNaN(v) ? 0 : v;
  }

  function initAgents(root) {
    var filters = root.querySelector('[data-breanm-filters]');
    var list = root.querySelector('[data-breanm-list]');
    var cards = Array.prototype.slice.call(root.querySelectorAll('[data-breanm-card]'));
    var q = root.querySelector('[data-breanm-q]');
    var chips = Array.prototype.slice.call(root.querySelectorAll('[data-breanm-chip]'));
    var clear = root.querySelector('[data-breanm-clear]');
    var count = root.querySelector('[data-breanm-count]');
    var empty = root.querySelector('[data-breanm-empty]');
    var sortBox = root.querySelector('[data-breanm-sortbox]');
    var sortSel = root.querySelector('[data-breanm-sort]');
    var moreBtn = root.querySelector('[data-breanm-more]');
    if (!list || !cards.length) return;

    var initial = parseInt(root.getAttribute('data-initial'), 10) || 12;
    var step = parseInt(root.getAttribute('data-step'), 10) || 12;
    var limit = initial;
    var matched = [];
    // Every string this script writes comes from PHP, so it is translated there.
    var text = {
      showingOne: root.getAttribute('data-text-showing-one') || '%1$d / %2$d',
      showingMany: root.getAttribute('data-text-showing-many') || '%1$d / %2$d',
      moreFilters: root.getAttribute('data-text-more-filters') || '%d',
      fewerFilters: root.getAttribute('data-text-fewer-filters') || ''
    };
    // Same cutoff the server used for the chip count: YYYY-MM-DD as a number, e.g. 20251016.
    var cutoff = parseInt((root.getAttribute('data-recent-cutoff') || '').replace(/-/g, ''), 10) || 0;

    var rows = cards.map(function (el) {
      var last = parseInt(el.getAttribute('data-last'), 10) || 0;
      var keys = ' ' + (el.getAttribute('data-keys') || '') + (cutoff && last >= cutoff ? ' p:recent' : '') + ' ';
      return {
        el: el,
        i: num(el, 'data-i'),
        tier: num(el, 'data-tier'),
        sales: num(el, 'data-sales'),
        rating: num(el, 'data-rating'),
        reviews: num(el, 'data-reviews'),
        last: last,
        years: num(el, 'data-years'),
        name: el.getAttribute('data-name') || '',
        keys: keys
      };
    });

    // Filters and the sort menu stay hidden until now, so crawlers and no-JS visitors just see every card.
    if (filters) filters.hidden = false;
    if (sortBox) sortBox.hidden = false;

    function pressed() {
      return chips.filter(function (c) { return c.getAttribute('aria-pressed') === 'true'; })
        .map(function (c) { return c.getAttribute('data-breanm-chip'); });
    }

    function update() {
      var words = q ? q.value.trim().toLowerCase().split(/\s+/).filter(Boolean) : [];
      var on = pressed();
      var matches = 0;
      var shown = 0;
      matched = [];
      // rows is kept in the current sort order, so the first `limit` matches are the ones shown.
      rows.forEach(function (r) {
        var ok = words.every(function (w) { return r.name.indexOf(w) !== -1; }) &&
          on.every(function (k) { return r.keys.indexOf(' ' + k + ' ') !== -1; });
        if (ok) { matches++; matched.push(r); }
        var visible = ok && matches <= limit;
        r.el.hidden = !visible;
        if (visible) shown++;
      });
      if (count) count.textContent = fmt(matches === 1 ? text.showingOne : text.showingMany, shown, matches);
      if (empty) empty.hidden = matches !== 0;
      if (moreBtn) moreBtn.hidden = matches <= limit;
      if (clear) clear.hidden = !on.length && !words.length;
    }

    function sortRows() {
      var by = SORTS[sortSel ? sortSel.value : 'local'] || SORTS.local;
      rows.sort(function (a, b) { return by(a, b) || (a.i - b.i); });
      rows.forEach(function (r) { list.appendChild(r.el); });
    }

    // Any change to what is being looked at starts again from the first page of results.
    function refresh() {
      limit = initial;
      update();
    }

    // "More filters (N)": chips past the first 8 sit behind a dashed chip (no-JS shows them all).
    var more = root.querySelector('[data-breanm-more-filters]');
    var chipBox = root.querySelector('[data-breanm-chips]');
    var moreOpen = false;
    function syncMore() {
      if (!more || !chipBox) return;
      var activeExtra = chips.some(function (c) {
        return c.classList.contains('breanm-agents__chip--extra') && c.getAttribute('aria-pressed') === 'true';
      });
      var open = moreOpen || activeExtra; // A hidden chip that is active keeps the panel open.
      chipBox.classList.add('is-collapsible');
      chipBox.classList.toggle('is-open', open);
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
      more.textContent = open ? text.fewerFilters : fmt(text.moreFilters, more.getAttribute('data-more'), '');
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

    if (q) q.addEventListener('input', refresh);
    chips.forEach(function (c) {
      c.addEventListener('click', function () {
        c.setAttribute('aria-pressed', c.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
        refresh();
        syncMore();
      });
    });
    if (clear) clear.addEventListener('click', function () {
      chips.forEach(function (c) { c.setAttribute('aria-pressed', 'false'); });
      if (q) q.value = '';
      refresh();
      syncMore();
      if (q) q.focus();
    });
    if (sortSel) sortSel.addEventListener('change', function () {
      sortRows();
      refresh();
    });
    if (moreBtn) moreBtn.addEventListener('click', function () {
      var first = matched[limit]; // the first card this click reveals
      limit += step;
      update();
      // The button hides itself once everything is showing; keyboard focus must not be lost with it.
      if (moreBtn.hidden && first) {
        var link = first.el.querySelector('a');
        if (link) link.focus();
      }
    });

    sortRows();
    update();
  }

  function boot() {
    Array.prototype.slice.call(document.querySelectorAll('[data-breanm-agents]')).forEach(initAgents);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
