/* DoS Tax Votes: filter, sort and switch between all / business / individual taxes.
   The server renders every legislator; without this script the page shows all taxes, by district. */
(function () {
	'use strict';

	function init(root) {
		var controls = root.querySelector('[data-eoa-controls]');
		var list = root.querySelector('[data-eoa-people]');
		if (!controls || !list) { return; }
		var people = Array.prototype.slice.call(list.querySelectorAll('[data-eoa-person]'));
		var shown = root.querySelector('[data-eoa-shown]');
		var state = { cat: 'all', chamber: '', party: '', sort: 'district', q: '', district: '' };
		var pick = root.querySelector('[data-eoa-district]');

		function counts(li) {
			return (li.getAttribute('data-' + state.cat) || '0,0,0,0').split(',').map(Number);
		}

		function setMeter(li, kind, yes, total) {
			var el = li.querySelector('[data-kind="' + kind + '"]');
			if (!el) { return; }
			var text = el.querySelector('.eoa-tv-count__text');
			var bar = el.querySelector('.eoa-tv-meter i');
			if (total > 0) {
				text.innerHTML = 'Yes on <b data-n="yes"></b> of <b data-n="total"></b>';
				text.querySelector('[data-n="yes"]').textContent = String(yes);
				text.querySelector('[data-n="total"]').textContent = String(total);
			} else {
				text.textContent = 'No votes cast';
			}
			bar.style.width = (total > 0 ? Math.round(100 * yes / total) : 0) + '%';
		}

		function apply() {
			var q = state.q.trim().toLowerCase();
			var visible = 0;
			people.forEach(function (li) {
				var c = counts(li);
				setMeter(li, 'up', c[0], c[1]);
				setMeter(li, 'down', c[2], c[3]);
				Array.prototype.forEach.call(li.querySelectorAll('.eoa-tv-votes li'), function (v) {
					var cat = v.getAttribute('data-cat');
					v.hidden = !(state.cat === 'all' || cat === state.cat || cat === 'both');
				});
				var ok = (!state.district || li.getAttribute('data-district') === state.district) &&
					(!state.chamber || li.getAttribute('data-chamber') === state.chamber) &&
					(!state.party || li.getAttribute('data-party') === state.party) &&
					(!q || (li.getAttribute('data-search') || '').indexOf(q) !== -1);
				li.hidden = !ok;
				if (ok) { visible++; }
			});
			var sorted = people.slice().sort(function (a, b) {
				if (state.sort === 'name') {
					return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
				}
				if (state.sort === 'up-desc' || state.sort === 'up-asc') {
					var ca = counts(a), cb = counts(b);
					var ra = ca[1] ? ca[0] / ca[1] : -1, rb = cb[1] ? cb[0] / cb[1] : -1;
					if (ra !== rb) { return state.sort === 'up-desc' ? rb - ra : ra - rb; }
				}
				var d = Number(a.getAttribute('data-district')) - Number(b.getAttribute('data-district'));
				if (d) { return d; }
				var ch = a.getAttribute('data-chamber').localeCompare(b.getAttribute('data-chamber'));
				return ch || a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
			});
			sorted.forEach(function (li) { list.appendChild(li); });
			if (shown) { shown.textContent = 'Showing ' + visible + ' of ' + people.length + ' lawmakers.'; }
			if (pick) {
				pick.hidden = !state.district;
				pick.querySelector('span').textContent = state.district ? 'District ' + state.district : '';
			}
			Array.prototype.forEach.call(root.querySelectorAll('.eoa-tv-map path'), function (pth) {
				pth.classList.toggle('is-picked', pth.getAttribute('data-district') === state.district);
			});
		}

		controls.addEventListener('click', function (e) {
			var b = e.target.closest('button[data-cat]');
			if (!b) { return; }
			state.cat = b.getAttribute('data-cat');
			Array.prototype.forEach.call(controls.querySelectorAll('button[data-cat]'), function (x) {
				x.setAttribute('aria-pressed', String(x === b));
			});
			apply();
		});
		controls.addEventListener('input', function (e) {
			var f = e.target.getAttribute('data-f');
			if (!f) { return; }
			state[f] = e.target.value;
			apply();
		});
		controls.addEventListener('click', function (e) {
			if (e.target.closest('[data-clear-district]')) { state.district = ''; apply(); }
		});
		controls.hidden = false;
		apply();
		initMap(root, people, function (d) {
			state.district = state.district === d ? '' : d;
			apply();
			if (state.district) { list.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
		}, function () { return state.cat; });
	}

	// The district map: hover or focus shows the delegation and their records; select filters the list.
	function initMap(root, people, onPick, currentCat) {
		var svg = root.querySelector('.eoa-tv-map');
		var tip = root.querySelector('[data-eoa-tip]');
		var zoom = root.querySelector('.eoa-tv-mapzoom');
		if (!svg || !tip) { return; }
		var box = svg.parentNode;

		function show(pth, evt) {
			var d = pth.getAttribute('data-district');
			var who = people.filter(function (li) { return li.getAttribute('data-district') === d; });
			who.sort(function (a, b) { return a.getAttribute('data-chamber') === 'Senate' ? -1 : (b.getAttribute('data-chamber') === 'Senate' ? 1 : 0); });
			tip.textContent = '';
			var h = document.createElement('b');
			h.textContent = 'District ' + d;
			tip.appendChild(h);
			var ul = document.createElement('ul');
			who.forEach(function (li) {
				var c = (li.getAttribute('data-' + currentCat()) || '0,0,0,0').split(',').map(Number);
				var item = document.createElement('li');
				var sw = document.createElement('i');
				sw.className = 'eoa-tv-tip-p';
				sw.style.background = li.getAttribute('data-party') === 'D' ? 'var(--eoa-tv-dem)' : (li.getAttribute('data-party') === 'R' ? 'var(--eoa-tv-rep)' : 'var(--eoa-tv-mix)');
				item.appendChild(sw);
				item.appendChild(document.createTextNode((li.getAttribute('data-chamber') === 'Senate' ? 'Sen. ' : 'Rep. ') + li.getAttribute('data-name') + ' (' + li.getAttribute('data-party') + ')'));
				var rec = document.createElement('span');
				rec.textContent = 'Tax increases: yes on ' + c[0] + ' of ' + c[1] + (c[3] > 0 ? ' · Tax cuts: yes on ' + c[2] + ' of ' + c[3] : '');
				item.appendChild(rec);
				ul.appendChild(item);
			});
			tip.appendChild(ul);
			tip.hidden = false;
			var r = box.getBoundingClientRect();
			var x, y;
			if (evt && evt.clientX) { x = evt.clientX - r.left; y = evt.clientY - r.top; }
			else { var b = pth.getBoundingClientRect(); x = b.left + b.width / 2 - r.left; y = b.top + b.height / 2 - r.top; }
			var tw = tip.offsetWidth, th = tip.offsetHeight;
			x = Math.max(0, Math.min(x + 14, r.width - tw));
			y = y + 14 + th > r.height ? Math.max(0, y - th - 14) : y + 14;
			tip.style.left = x + 'px';
			tip.style.top = y + 'px';
		}
		function hide() { tip.hidden = true; }

		svg.addEventListener('mousemove', function (e) { var p = e.target.closest('path[data-district]'); if (p) { show(p, e); } });
		svg.addEventListener('mouseleave', hide);
		svg.addEventListener('focusin', function (e) { var p = e.target.closest('path[data-district]'); if (p) { show(p); } });
		svg.addEventListener('focusout', hide);
		svg.addEventListener('click', function (e) {
			var p = e.target.closest('path[data-district]');
			if (p) { show(p, e); onPick(p.getAttribute('data-district')); }
		});
		svg.addEventListener('keydown', function (e) {
			var p = e.target.closest('path[data-district]');
			if (p && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); onPick(p.getAttribute('data-district')); }
		});
		if (zoom) {
			zoom.hidden = false;
			zoom.addEventListener('click', function (e) {
				var b = e.target.closest('button[data-zoom]');
				if (!b) { return; }
				svg.setAttribute('viewBox', svg.getAttribute(b.getAttribute('data-zoom') === 'puget' ? 'data-puget' : 'data-full'));
				Array.prototype.forEach.call(zoom.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
				hide();
			});
		}
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-eoa-tax-votes]'), init);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
