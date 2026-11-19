/*
 * DoS SAAB Food Trucks: the city filter and the map. The server already sent every park; this only
 * shows or hides them and draws the pins. Without it (or without Leaflet) the list still works.
 */
(function () {
	'use strict';

	function init(root) {
		var points = [];
		var pointsEl = root.querySelector('[data-saab-ft-points]');
		if (pointsEl) {
			try { points = JSON.parse(pointsEl.textContent) || []; } catch (e) { points = []; }
		}

		var map = null;
		var markers = [];
		var mapEl = root.querySelector('[data-saab-ft-map]');
		if (mapEl && window.L && points.length) {
			mapEl.hidden = false;
			map = window.L.map(mapEl, { scrollWheelZoom: false });
			window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
			}).addTo(map);
			// Parks that share a spot (same building, or a street-level lookup) fan out around it in
			// screen pixels, so every pin stays visible and clickable at any zoom.
			var shared = {};
			points.forEach(function (p) {
				var k = Number(p.lat).toFixed(4) + ',' + Number(p.lng).toFixed(4);
				(shared[k] = shared[k] || []).push(p);
			});
			Object.keys(shared).forEach(function (k) {
				var group = shared[k];
				group.forEach(function (p, i) {
					p.dx = 0;
					p.dy = 0;
					if (group.length > 1) {
						var a = (2 * Math.PI * i) / group.length - Math.PI / 2;
						p.dx = Math.round(22 * Math.cos(a));
						p.dy = Math.round(22 * Math.sin(a));
					}
				});
			});
			points.forEach(function (p) {
				var badge = document.createElement('span');
				badge.textContent = p.n || '';
				var icon = window.L.divIcon({
					className: 'saab-ft-pin' + (p.event ? ' saab-ft-pin--event' : ''),
					html: badge.outerHTML,
					iconSize: [34, 34],
					iconAnchor: [17 - p.dx, 17 - p.dy],
					popupAnchor: [p.dx, p.dy - 18]
				});
				var marker = window.L.marker([Number(p.lat), Number(p.lng)], { icon: icon, title: p.name, alt: p.name, keyboard: true });
				marker.bindPopup(popup(p));
				marker.addTo(map);
				markers.push({ marker: marker, point: p });
			});
			fit(markers);
		}

		function popup(p) {
			var box = document.createElement('div');
			var name = document.createElement('b');
			name.textContent = p.name;
			box.appendChild(name);
			if (p.address) {
				box.appendChild(document.createElement('br'));
				box.appendChild(document.createTextNode(p.address));
			}
			if (root.querySelector('#park-' + p.id)) {
				box.appendChild(document.createElement('br'));
				var a = document.createElement('a');
				a.href = '#park-' + p.id;
				a.textContent = 'Details';
				box.appendChild(a);
			}
			return box;
		}

		function fit(list) {
			if (!map || !list.length) { return; }
			if (list.length === 1) {
				map.setView(list[0].marker.getLatLng(), 14);
				return;
			}
			var bounds = window.L.latLngBounds(list.map(function (m) { return m.marker.getLatLng(); }));
			map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
		}

		var filter = root.querySelector('[data-saab-ft-filter]');
		var status = root.querySelector('[data-saab-ft-status]');
		if (!filter) { return; }
		filter.hidden = false;
		var chips = Array.prototype.slice.call(filter.querySelectorAll('button[data-city]'));

		function apply(city) {
			chips.forEach(function (c) { c.setAttribute('aria-pressed', c.getAttribute('data-city') === city ? 'true' : 'false'); });
			var shown = 0;
			root.querySelectorAll('.saab-ft__park[data-city-item]').forEach(function (el) {
				var on = city === 'all' || el.getAttribute('data-city-item') === city;
				el.hidden = !on;
				if (on) { shown++; }
			});
			root.querySelectorAll('tr[data-city-item]').forEach(function (el) {
				el.hidden = !(city === 'all' || el.getAttribute('data-city-item') === city);
			});
			var visible = [];
			markers.forEach(function (m) {
				var on = city === 'all' || m.point.city === city;
				if (on) { visible.push(m); m.marker.addTo(map); } else { m.marker.remove(); }
			});
			fit(visible);
			if (status) {
				var label = '';
				chips.forEach(function (c) { if (c.getAttribute('data-city') === city) { label = c.firstChild.textContent.trim(); } });
				status.textContent = city === 'all' ? '' : 'Showing ' + shown + (shown === 1 ? ' place' : ' places') + ' in ' + label + '.';
			}
		}

		chips.forEach(function (c) {
			c.addEventListener('click', function () { apply(c.getAttribute('data-city')); });
		});
	}

	function boot() {
		document.querySelectorAll('[data-saab-ft]').forEach(init);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
