/*
 * DoS - Agents: the homepage city box and the "Browse by state" carousel.
 *
 * The matching rules here mirror includes/search.php exactly, so the box behaves the same whether
 * this script ran or the form was submitted without it. Change one and change the other: both
 * normalise the same way, read the same state names and rank the same way.
 *
 * Everything degrades. The form posts to the site root with ?s=&breanm_city=1 and the server
 * redirects on a single match; the carousel is a scrollable list and the arrow buttons only appear
 * once this file has run. Bump DOS_AGENTS_VERSION whenever this file changes (the CDN caches ?ver=
 * URLs for years).
 */
(function () {
	'use strict';

	var STATES = {
		AL: 'alabama', AK: 'alaska', AZ: 'arizona', AR: 'arkansas', CA: 'california',
		CO: 'colorado', CT: 'connecticut', DE: 'delaware', DC: 'district of columbia', FL: 'florida',
		GA: 'georgia', HI: 'hawaii', ID: 'idaho', IL: 'illinois', IN: 'indiana',
		IA: 'iowa', KS: 'kansas', KY: 'kentucky', LA: 'louisiana', ME: 'maine',
		MD: 'maryland', MA: 'massachusetts', MI: 'michigan', MN: 'minnesota', MS: 'mississippi',
		MO: 'missouri', MT: 'montana', NE: 'nebraska', NV: 'nevada', NH: 'new hampshire',
		NJ: 'new jersey', NM: 'new mexico', NY: 'new york', NC: 'north carolina', ND: 'north dakota',
		OH: 'ohio', OK: 'oklahoma', OR: 'oregon', PA: 'pennsylvania', RI: 'rhode island',
		SC: 'south carolina', SD: 'south dakota', TN: 'tennessee', TX: 'texas', UT: 'utah',
		VT: 'vermont', VA: 'virginia', WA: 'washington', WV: 'west virginia', WI: 'wisconsin',
		WY: 'wyoming'
	};

	/* Lowercase, accents and punctuation dropped, single spaces, Saint/Mount/Fort folded to St/Mt/Ft. */
	function norm(s) {
		s = String(s == null ? '' : s);
		s = s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s;
		s = s.toLowerCase().replace(/['’.]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
		return s.replace(/\bsaint\b/g, 'st').replace(/\bmount\b/g, 'mt').replace(/\bfort\b/g, 'ft');
	}

	function resolveStates(s, minPrefix) {
		if (!s) { return []; }
		if (STATES[s.toUpperCase()]) { return [s.toUpperCase()]; }
		var out = [], code;
		for (code in STATES) {
			if (!Object.prototype.hasOwnProperty.call(STATES, code)) { continue; }
			if (STATES[code] === s) { return [code]; }
			if (s.length >= minPrefix && STATES[code].indexOf(s) === 0) { out.push(code); }
		}
		return out;
	}

	/* The ways one query can be read: [ normalised city, state codes or null ]. */
	function readings(raw) {
		raw = String(raw == null ? '' : raw);
		var out = [], pos = raw.indexOf(','), city, state, codes, n, tokens, i;

		// With a comma the visitor has said which part is the state, so one letter resolves it.
		if (pos !== -1) {
			city = norm(raw.slice(0, pos));
			state = norm(raw.slice(pos + 1));
			if (!city) { return out; }
			if (!state) { out.push([city, null]); return out; }
			codes = resolveStates(state, 1);
			if (codes.length) { out.push([city, codes]); }
			return out;
		}

		n = norm(raw);
		if (!n) { return out; }
		out.push([n, null]);
		tokens = n.split(' ');
		for (i = 1; i < tokens.length; i++) {
			codes = resolveStates(tokens.slice(i).join(' '), 3);
			if (codes.length) { out.push([tokens.slice(0, i).join(' '), codes]); }
		}
		return out;
	}

	/*
	 * One row's rank for a query, or null when it does not match: 0/1 the whole city name, 2/3 a
	 * prefix of it, 4/5 the start of a later word. The even number of each pair is the one where the
	 * query also named the state, so "boise id" beats a bare "boise".
	 */
	function score(row, rds) {
		var best = null;
		rds.forEach(function (rd) {
			var city = rd[0], states = rd[1], tier, rank;
			if (states && states.indexOf(row.s) === -1) { return; }
			if (row._k === city) { tier = 0; }
			else if (row._k.indexOf(city) === 0) { tier = 2; }
			else if (row._k.indexOf(' ' + city) !== -1) { tier = 4; }
			else { return; }
			rank = tier + (states ? 0 : 1);
			if (best === null || rank < best) { best = rank; }
		});
		return best;
	}

	/* The matching rows, best first. Each keeps its rank, which resolveUnique() then reads. */
	function match(rows, raw) {
		var rds = readings(raw), hits = [];
		if (!rds.length) { return hits; }
		rows.forEach(function (r) {
			var rank = score(r, rds);
			if (rank === null) { return; }
			r._rank = rank;
			hits.push(r);
		});
		hits.sort(function (a, b) {
			return a._rank - b._rank ||
				(a._k < b._k ? -1 : a._k > b._k ? 1 : 0) ||
				(a.s < b.s ? -1 : a.s > b.s ? 1 : 0);
		});
		return hits;
	}

	/* The one city a query clearly means, or null: one whole-name match, or a single hit of any kind. */
	function resolveUnique(rows, raw) {
		var hits = match(rows, raw),
			exact = hits.filter(function (r) { return r._rank <= 1; });
		return exact.length === 1 ? exact[0] : (!exact.length && hits.length === 1 ? hits[0] : null);
	}

	function prepare(rows) {
		return (rows || []).filter(function (r) {
			return r && r.c && r.u;
		}).map(function (r) {
			r._k = norm(r.c);
			return r;
		});
	}

	function setupSearch(root) {
		var form = root.querySelector('.breanm-home__search-form'),
			input = root.querySelector('.breanm-home__input'),
			list = root.querySelector('.breanm-home__list'),
			msg = root.querySelector('.breanm-home__msg'),
			rows = null,
			pending = null,
			shown = [],
			cur = -1;

		if (!form || !input || !list) { return; }

		var inline = root.getAttribute('data-cities');
		if (inline) {
			try { rows = prepare(JSON.parse(inline)); } catch (e) { rows = null; }
		}

		/* Beyond the inline cap the index comes from the cities route, once, on first use. */
		function load() {
			if (rows || pending) { return pending; }
			var api = root.getAttribute('data-api');
			if (!api || !window.fetch) { return null; }
			pending = window.fetch(api, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : []; })
				.then(function (d) { rows = prepare(d); return rows; })
				.catch(function () { rows = []; return rows; });
			return pending;
		}

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			input.setAttribute('aria-expanded', 'false');
			input.removeAttribute('aria-activedescendant');
			shown = [];
			cur = -1;
		}

		function paint() {
			var html = '', i;
			for (i = 0; i < shown.length; i++) {
				html += '<li class="breanm-home__opt" role="option" id="' + input.id + '-opt' + i + '"' +
					' aria-selected="' + (i === cur) + '"><b></b> <span></span></li>';
			}
			list.innerHTML = html;
			// Text goes in as text, never as markup: these are city names from the index, but the
			// index is data and the rule is the same for all of it.
			for (i = 0; i < shown.length; i++) {
				list.children[i].firstChild.textContent = shown[i].c;
				list.children[i].lastChild.textContent = shown[i].s;
			}
			list.hidden = shown.length === 0;
			input.setAttribute('aria-expanded', shown.length ? 'true' : 'false');
			if (cur > -1) {
				input.setAttribute('aria-activedescendant', input.id + '-opt' + cur);
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function suggest() {
			var v = input.value.trim();
			if (msg) { msg.textContent = ''; }
			if (v.length < 2 || !rows) { close(); return; }
			shown = match(rows, v).slice(0, 8);
			cur = -1;
			paint();
		}

		function go(row) {
			close();
			window.location.href = row.u;
		}

		input.addEventListener('focus', function () {
			var p = load();
			if (p) { p.then(suggest); }
		});
		input.addEventListener('input', function () {
			var p = load();
			if (p) { p.then(suggest); } else { suggest(); }
		});
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { close(); return; }
			if (!shown.length) { return; }
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				cur = (cur + (ev.key === 'ArrowDown' ? 1 : -1) + shown.length) % shown.length;
				paint();
			} else if (ev.key === 'Enter' && cur > -1) {
				ev.preventDefault();
				go(shown[cur]);
			}
		});
		// mousedown, not click: blur fires first and would close the list before a click lands.
		list.addEventListener('mousedown', function (ev) {
			var li = ev.target.closest ? ev.target.closest('.breanm-home__opt') : null;
			if (!li) { return; }
			ev.preventDefault();
			var i = Array.prototype.indexOf.call(list.children, li);
			if (shown[i]) { go(shown[i]); }
		});
		input.addEventListener('blur', function () { window.setTimeout(close, 120); });

		form.addEventListener('submit', function (ev) {
			// Index not here yet: let the form go to the server, which matches the same way.
			if (!rows) { return; }
			var v = input.value.trim();
			if (!v) { return; }
			ev.preventDefault();
			var one = resolveUnique(rows, v);
			if (one) { go(one); return; }
			shown = match(rows, v).slice(0, 8);
			cur = -1;
			paint();
			if (msg) {
				// The wording is translated in PHP and handed over on the section.
				msg.textContent = root.getAttribute(shown.length ? 'data-msg-many' : 'data-msg-none') || '';
			}
		});
	}

	/*
	 * The by-state grid's filter. Matches the start of the state's name, the start of any later word
	 * in it ("mexico" finds New Mexico), or the postal code, so "new", "mexico", "nm" and "NM" all
	 * land on the same card. Cards are hidden rather than removed, so clearing the box is instant
	 * and nothing has to be re-rendered.
	 */
	function setupStateFilter(root) {
		var bar = root.querySelector('.breanm-home__filter'),
			input = bar && bar.querySelector('.breanm-home__input'),
			count = bar && bar.querySelector('.breanm-home__count'),
			cards = [].slice.call(root.querySelectorAll('.breanm-home__sc'));

		if (!bar || !input || !cards.length) { return; }
		bar.hidden = false;

		var total = cards.length,
			tAll = bar.getAttribute('data-label-all') || '',
			tSome = bar.getAttribute('data-label-some') || '',
			tNone = bar.getAttribute('data-label-none') || '';

		function label(n) {
			if (!n) { return tNone; }
			return (n === total ? tAll : tSome).replace('{n}', n).replace('{total}', total);
		}

		function hit(card, q) {
			var name = card.getAttribute('data-name') || '',
				code = card.getAttribute('data-code') || '';
			return name.indexOf(q) === 0 || name.indexOf(' ' + q) !== -1 || code.indexOf(q) === 0;
		}

		function apply() {
			var q = norm(input.value), shown = 0;
			cards.forEach(function (c) {
				var on = !q || hit(c, q);
				c.hidden = !on;
				if (on) { shown++; }
			});
			if (count) { count.textContent = q ? label(shown) : ''; }
		}

		input.addEventListener('input', apply);
		input.addEventListener('search', apply);   // the native clear button on type=search
		apply();
	}

	function setupCarousel(root) {
		var track = root.querySelector('.breanm-home__car');
		if (!track) { return; }
		root.querySelectorAll('.breanm-home__car-btn').forEach(function (btn) {
			btn.hidden = false;
			btn.addEventListener('click', function () {
				track.scrollBy({
					left: Number(btn.getAttribute('data-dir')) * track.clientWidth * 0.8,
					behavior: 'smooth'
				});
			});
		});
	}

	function init() {
		document.querySelectorAll('.breanm-home__search').forEach(setupSearch);
		document.querySelectorAll('[data-breanm-carousel]').forEach(setupCarousel);
		document.querySelectorAll('[data-breanm-state-filter]').forEach(setupStateFilter);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
