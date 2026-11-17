/* PublishRank charts: interactive line/area charts, world dot map and live visitor refresh.
 * No dependencies. Colours come from CSS variables (--prc-plum, --prc-teal, …) so they always match the brand. */
(function () {
	'use strict';
	var cfg = window.PRCharts || { i18n: {}, locale: 'en' };
	var NS = 'http://www.w3.org/2000/svg';

	function css(name, fallback) {
		var v = getComputedStyle(document.documentElement).getPropertyValue('--prc-' + name);
		return (v && v.trim()) || fallback;
	}
	var TONE = { plum: '#9B1245', teal: '#3D7264', brass: '#B08A45', slate: '#4B5666', coral: '#C4574F', sage: '#7FA393', ink: '#252833', rose: '#D88AA6' };
	function color(t) { return css(t, TONE[t] || TONE.plum); }
	function el(tag, attrs, parent) {
		var n = document.createElementNS(NS, tag);
		for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) { n.setAttribute(k, attrs[k]); } }
		if (parent) { parent.appendChild(n); }
		return n;
	}
	function fmt(v, format) {
		if (format === 'duration') {
			v = Math.round(v); var m = Math.floor(v / 60), s = v % 60;
			return m ? m + 'm ' + (s < 10 ? '0' : '') + s + 's' : s + 's';
		}
		if (format === 'percent') { return (Math.round(v * 10) / 10) + '%'; }
		try { return new Intl.NumberFormat(cfg.locale, { notation: v >= 10000 ? 'compact' : 'standard', maximumFractionDigits: 1 }).format(v); } catch (e) { return String(v); }
	}
	function niceMax(v) {
		if (v <= 0) { return 4; }
		var p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p;
		var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10;
		return n * p;
	}
	function dateLabel(iso, mode) {
		var d = new Date(iso + 'T12:00:00');
		try {
			if (mode === 'months') { return d.toLocaleDateString(cfg.locale, { month: 'short', year: '2-digit' }); }
			return d.toLocaleDateString(cfg.locale, { month: 'short', day: 'numeric' });
		} catch (e) { return iso; }
	}
	function group(labels, series, mode) {
		if (mode === 'days') { return { labels: labels.slice(), series: series }; }
		var keys = [], idx = {};
		labels.forEach(function (l, i) {
			var d = new Date(l + 'T12:00:00'), k;
			if (mode === 'weeks') { var day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day); k = d.toISOString().slice(0, 10); }
			else { k = l.slice(0, 7) + '-01'; }
			if (!(k in idx)) { idx[k] = keys.length; keys.push(k); }
			labels[i] = labels[i];
		});
		var out = series.map(function (s) {
			var vals = keys.map(function () { return 0; }), cnt = keys.map(function () { return 0; });
			labels.forEach(function (l, i) {
				var d = new Date(l + 'T12:00:00'), k;
				if (mode === 'weeks') { var day = (d.getDay() + 6) % 7; d.setDate(d.getDate() - day); k = d.toISOString().slice(0, 10); }
				else { k = l.slice(0, 7) + '-01'; }
				vals[idx[k]] += +s.values[i] || 0; cnt[idx[k]]++;
			});
			if (s.avg) { vals = vals.map(function (v, i) { return cnt[i] ? v / cnt[i] : 0; }); }
			return { name: s.name, tone: s.tone, values: vals, avg: s.avg };
		});
		return { labels: keys, series: out };
	}
	function smooth(pts) {
		if (pts.length < 3) { return 'M' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' L'); }
		var d = 'M' + pts[0][0] + ',' + pts[0][1];
		for (var i = 0; i < pts.length - 1; i++) {
			var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2, t = 0.18;
			var c1x = p1[0] + (p2[0] - p0[0]) * t, c1y = p1[1] + (p2[1] - p0[1]) * t;
			var c2x = p2[0] - (p3[0] - p1[0]) * t, c2y = p2[1] - (p3[1] - p1[1]) * t;
			d += ' C' + c1x.toFixed(1) + ',' + c1y.toFixed(1) + ' ' + c2x.toFixed(1) + ',' + c2y.toFixed(1) + ' ' + p2[0].toFixed(1) + ',' + p2[1].toFixed(1);
		}
		return d;
	}

	function LineChart(node) {
		this.node = node;
		this.data = JSON.parse(node.getAttribute('data-prc-line') || '{}');
		this.mode = 'days';
		this.hidden = {};
		this.build();
	}
	LineChart.prototype.build = function () {
		var self = this, d = this.data;
		this.node.innerHTML = '';
		var head = document.createElement('div'); head.className = 'prc-line__head';
		var legend = document.createElement('div'); legend.className = 'prc-line__legend';
		(d.series || []).forEach(function (s, i) {
			var b = document.createElement('button'); b.type = 'button'; b.className = 'prc-line__key'; b.setAttribute('aria-pressed', 'true');
			b.innerHTML = '<span class="prc-dot" style="background:' + color(s.tone) + '"></span>' + s.name.replace(/</g, '&lt;');
			b.addEventListener('click', function () { self.hidden[i] = !self.hidden[i]; b.setAttribute('aria-pressed', self.hidden[i] ? 'false' : 'true'); self.draw(); });
			legend.appendChild(b);
		});
		head.appendChild(legend);
		if (d.group && (d.labels || []).length > 14) {
			var seg = document.createElement('div'); seg.className = 'prc-seg'; seg.setAttribute('role', 'group');
			['days', 'weeks', 'months'].forEach(function (m) {
				if (m === 'months' && d.labels.length < 60) { return; }
				var b = document.createElement('button'); b.type = 'button'; b.textContent = cfg.i18n[m] || m; b.setAttribute('aria-pressed', m === self.mode ? 'true' : 'false');
				b.addEventListener('click', function () { self.mode = m; [].forEach.call(seg.children, function (c) { c.setAttribute('aria-pressed', c === b ? 'true' : 'false'); }); self.draw(); });
				seg.appendChild(b);
			});
			head.appendChild(seg);
		}
		this.node.appendChild(head);
		this.wrap = document.createElement('div'); this.wrap.className = 'prc-line__plot';
		this.node.appendChild(this.wrap);
		this.tip = document.createElement('div'); this.tip.className = 'prc-tip'; this.tip.hidden = true;
		this.wrap.appendChild(this.tip);
		this.draw();
		if (window.ResizeObserver) { new ResizeObserver(function () { self.draw(); }).observe(this.wrap); }
	};
	LineChart.prototype.draw = function () {
		var self = this, g = group(this.data.labels || [], this.data.series || [], this.mode);
		var W = Math.max(280, this.wrap.clientWidth || 600), H = parseInt(getComputedStyle(this.node).getPropertyValue('--prc-h'), 10) || 300;
		var pad = { l: 48, r: 16, t: 12, b: 30 }, iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
		var old = this.wrap.querySelector('svg'); if (old) { old.remove(); }
		var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H, class: 'prc-line__svg', role: 'img' });
		this.wrap.insertBefore(svg, this.tip);
		var visible = g.series.filter(function (s, i) { return !self.hidden[i]; });
		var max = 0; visible.forEach(function (s) { s.values.forEach(function (v) { max = Math.max(max, +v || 0); }); });
		var n = g.labels.length, empty = !n || !max;
		if (empty) {
			el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'prc-line__empty' }, svg).textContent = cfg.i18n.noData || 'No data';
		}
		max = niceMax(max * 1.08);
		var defs = el('defs', {}, svg);
		for (var t = 0; t <= 4; t++) {
			var y = pad.t + ih - ih * t / 4;
			el('line', { x1: pad.l, x2: W - pad.r, y1: y, y2: y, class: 'prc-gridline' }, svg);
			el('text', { x: pad.l - 8, y: y + 4, 'text-anchor': 'end', class: 'prc-axis' }, svg).textContent = fmt(max * t / 4, this.data.format);
		}
		var step = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 78))));
		g.labels.forEach(function (l, i) {
			if (i % step !== 0 && i !== n - 1) { return; }
			if (i === n - 1 && i % step !== 0 && (n - 1) % step < step / 2) { return; }
			var x = pad.l + (n > 1 ? iw * i / (n - 1) : iw / 2);
			el('text', { x: x, y: H - 8, 'text-anchor': i === 0 && n > 1 ? 'start' : (i === n - 1 && n > 1 ? 'end' : 'middle'), class: 'prc-axis' }, svg).textContent = dateLabel(l, self.mode);
		});
		var X = function (i) { return pad.l + (n > 1 ? iw * i / (n - 1) : iw / 2); };
		var Y = function (v) { return pad.t + ih - ih * (+v || 0) / max; };
		g.series.forEach(function (s, si) {
			if (self.hidden[si] || empty) { return; }
			var c = color(s.tone), id = 'prcg' + Math.random().toString(36).slice(2);
			var lg = el('linearGradient', { id: id, x1: 0, x2: 0, y1: 0, y2: 1 }, defs);
			el('stop', { offset: '0', 'stop-color': c, 'stop-opacity': '0.22' }, lg);
			el('stop', { offset: '1', 'stop-color': c, 'stop-opacity': '0' }, lg);
			var pts = s.values.map(function (v, i) { return [X(i), Y(v)]; });
			if (!pts.length) { return; }
			var line = smooth(pts);
			el('path', { d: line + ' L' + pts[pts.length - 1][0] + ',' + (pad.t + ih) + ' L' + pts[0][0] + ',' + (pad.t + ih) + ' Z', fill: 'url(#' + id + ')' }, svg);
			el('path', { d: line, fill: 'none', stroke: c, 'stroke-width': 2.4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, svg);
			if (n <= 45) { pts.forEach(function (p) { el('circle', { cx: p[0], cy: p[1], r: 3.2, fill: '#fff', stroke: c, 'stroke-width': 2 }, svg); }); }
		});
		var cross = el('line', { y1: pad.t, y2: pad.t + ih, class: 'prc-cross', visibility: 'hidden' }, svg);
		var hit = el('rect', { x: pad.l, y: pad.t, width: iw, height: ih, fill: 'transparent' }, svg);
		var move = function (ev) {
			if (!n) { return; }
			var r = svg.getBoundingClientRect(), x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
			var i = Math.max(0, Math.min(n - 1, Math.round((x - pad.l) / (iw / Math.max(1, n - 1)))));
			cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.setAttribute('visibility', 'visible');
			var html = '<strong>' + (self.mode === 'weeks' ? (cfg.i18n.weekOf || 'Week of') + ' ' : '') + dateLabel(g.labels[i], self.mode) + '</strong>';
			g.series.forEach(function (s, si) { if (!self.hidden[si]) { html += '<span><i style="background:' + color(s.tone) + '"></i>' + s.name.replace(/</g, '&lt;') + ' <b>' + fmt(s.values[i], self.data.format) + '</b></span>'; } });
			self.tip.innerHTML = html; self.tip.hidden = false;
			var tx = X(i) + 14; if (tx + 180 > W) { tx = X(i) - 194; }
			self.tip.style.left = tx + 'px'; self.tip.style.top = (pad.t + 6) + 'px';
		};
		hit.addEventListener('mousemove', move); hit.addEventListener('touchstart', move, { passive: true });
		hit.addEventListener('mouseleave', function () { cross.setAttribute('visibility', 'hidden'); self.tip.hidden = true; });
	};

	function WorldMap(node) {
		var map = window.PRWorldMap; if (!map) { return; }
		var data = JSON.parse(node.getAttribute('data-prc-map') || '{}'), vals = data.values || {}, names = data.names || {};
		var max = 0; Object.keys(vals).forEach(function (k) { max = Math.max(max, vals[k]); });
		var cell = 10, svg = el('svg', { viewBox: '0 0 ' + map.w * cell + ' ' + map.h * cell, class: 'prc-map__svg' });
		var tip = document.createElement('div'); tip.className = 'prc-tip'; tip.hidden = true;
		var base = color('plum');
		Object.keys(map.c).forEach(function (cc) {
			var v = vals[cc] || 0, a = v ? 0.35 + 0.65 * Math.sqrt(v / max) : 0;
			var gEl = el('g', { 'data-cc': cc }, svg);
			map.c[cc].split(' ').forEach(function (p) {
				var xy = p.split('.');
				el('circle', { cx: xy[0] * cell + cell / 2, cy: xy[1] * cell + cell / 2, r: cell * 0.38, fill: v ? base : css('track', '#E6DED3'), 'fill-opacity': v ? a.toFixed(2) : 1 }, gEl);
			});
			if (v) {
				gEl.style.cursor = 'pointer';
				gEl.addEventListener('mousemove', function (ev) {
					var r = node.getBoundingClientRect();
					tip.innerHTML = '<strong>' + (names[cc] || cc) + '</strong><span>' + fmt(v) + '</span>'; tip.hidden = false;
					tip.style.left = Math.min(r.width - 150, ev.clientX - r.left + 12) + 'px'; tip.style.top = (ev.clientY - r.top + 12) + 'px';
				});
				gEl.addEventListener('mouseleave', function () { tip.hidden = true; });
			}
		});
		node.appendChild(svg); node.appendChild(tip);
	}

	function live(node) {
		var url = node.getAttribute('data-prc-live'); if (!url || !window.fetch) { return; }
		var tick = function () {
			if (document.hidden) { return; }
			fetch(url, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
				if (!d) { return; }
				var c = node.querySelector('[data-prc-live-count]'); if (c) { c.textContent = d.live; }
				var list = node.querySelector('[data-prc-live-list]'); if (list && d.html) { list.innerHTML = d.html; }
			}).catch(function () {});
		};
		setInterval(tick, 30000);
	}

	function init() {
		[].forEach.call(document.querySelectorAll('[data-prc-line]'), function (n) { if (!n.__prc) { n.__prc = new LineChart(n); } });
		[].forEach.call(document.querySelectorAll('[data-prc-map]'), function (n) { if (!n.__prc) { n.__prc = 1; WorldMap(n); } });
		[].forEach.call(document.querySelectorAll('[data-prc-live]'), function (n) { if (!n.__prc) { n.__prc = 1; live(n); } });
		[].forEach.call(document.querySelectorAll('[data-prc-filter]'), function (input) {
			var table = document.querySelector(input.getAttribute('data-prc-filter')); if (!table) { return; }
			input.addEventListener('input', function () {
				var q = input.value.toLowerCase();
				[].forEach.call(table.tBodies[0].rows, function (r) { r.hidden = q && r.textContent.toLowerCase().indexOf(q) < 0; });
			});
		});
		[].forEach.call(document.querySelectorAll('[data-prc-range]'), function (s) {
			s.addEventListener('change', function () { if (s.form) { s.form.submit(); } });
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	// Charts inside hidden tabs need a redraw when the tab opens.
	document.addEventListener('prx:tab', function () {
		setTimeout(function () { [].forEach.call(document.querySelectorAll('[data-prc-line]'), function (n) { if (n.__prc && n.__prc.draw) { n.__prc.draw(); } }); }, 20);
	});
	window.PRChartsInit = init;
})();
