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
		var state = { cat: 'all', chamber: '', party: '', sort: 'district', q: '' };

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
				var ok = (!state.chamber || li.getAttribute('data-chamber') === state.chamber) &&
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
		controls.hidden = false;
		apply();
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-eoa-tax-votes]'), init);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
