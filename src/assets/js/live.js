/* PublishRank live updates: Heartbeat (15 s) + "Refresh now". Renders with textContent only — Google data is untrusted. */
(function ($) {
	'use strict';
	var cfg = window.publishrankLive;
	if (!cfg) { return; }
	var t = cfg.i18n || {};
	var state = { hash: '', data: null, prev: {} };
	var nf = new Intl.NumberFormat(undefined);

	function get(obj, path) { return path.split('.').reduce(function (o, k) { return o && o[k] !== undefined ? o[k] : undefined; }, obj); }
	function fmt(v, kind) {
		if (v === undefined || v === null || v === '') { return '—'; }
		if (kind === 'pct') { return (v * 100).toFixed(1) + '%'; }
		if (kind === 'pos') { return Number(v).toFixed(1); }
		return nf.format(v);
	}
	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }
	function ago(ts) {
		if (!ts) { return t.noData; }
		var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
		if (s < 45) { return t.updated + ' ' + t.justNow; }
		var m = Math.round(s / 60);
		if (m < 60) { return t.updated + ' ' + m + ' min ' + t.ago; }
		var h = Math.round(m / 60);
		return t.updated + ' ' + h + ' h ' + t.ago;
	}
	function flash(node) { node.classList.remove('pr-flash'); void node.offsetWidth; node.classList.add('pr-flash'); }

	function bindValues(d) {
		document.querySelectorAll('[data-pr-live]').forEach(function (n) {
			var path = n.getAttribute('data-pr-live'), v = get(d, path), out;
			out = (n.getAttribute('data-fmt')) ? fmt(v, n.getAttribute('data-fmt')) : (v === undefined || v === null || v === '' ? '' : String(v));
			if (n.textContent !== out) { var had = n.textContent !== ''; n.textContent = out; if (had && state.prev[path] !== undefined) { flash(n); } }
			state.prev[path] = v;
		});
		document.querySelectorAll('[data-pr-delta]').forEach(function (n) {
			var v = get(d, n.getAttribute('data-pr-delta'));
			n.className = 'pr-kpi__delta';
			if (v === null || v === undefined) { n.textContent = ''; return; }
			n.classList.add(v > 0 ? 'is-up' : (v < 0 ? 'is-down' : 'is-flat'));
			n.textContent = (v > 0 ? '▲ ' : (v < 0 ? '▼ ' : '')) + Math.abs(v).toFixed(1) + '% vs previous 7 days';
		});
		document.querySelectorAll('[data-pr-when]').forEach(function (n) {
			var want = n.getAttribute('data-pr-when') === 'connected';
			n.hidden = (!!d.connected) !== want;
		});
		document.querySelectorAll('[data-pr-live-reauth]').forEach(function (n) { n.hidden = !d.reauth; });
		document.querySelectorAll('[data-pr-live-pill]').forEach(function (n) {
			n.classList.toggle('is-off', !!d.reauth);
			var label = n.querySelector('span'); if (label) { label.textContent = d.reauth ? t.reauth.split('.')[0] : t.live; }
		});
	}

	function bindList(d) {
		document.querySelectorAll('[data-pr-live-list]').forEach(function (list) {
			var kind = list.getAttribute('data-pr-live-list'), rows = d[kind] || [], frag = document.createDocumentFragment();
			if (kind === 'latest') {
				rows = (d.newsroom && d.newsroom.latest) || [];
				rows.forEach(function (r) {
					var li = el('li', 'pr-feed__item'), a = el('a', 'pr-feed__title', r.title || '(no title)');
					if (r.edit) { a.href = r.edit; }
					li.appendChild(a);
					var meta = el('span', 'pr-feed__meta', ago(r.ts).replace(t.updated + ' ', '')); li.appendChild(meta);
					if (r.score !== null && r.score !== undefined) { li.appendChild(el('span', 'pr-score ' + (r.score >= 80 ? 'is-good' : (r.score >= 60 ? 'is-ok' : 'is-low')), String(r.score))); }
					frag.appendChild(li);
				});
			} else {
				rows.forEach(function (r) {
					var tr = el('tr'), td = el('td', 'pr-live__key');
					var label = r.key || '';
					if (kind === 'pages') { try { var u = new URL(label); label = u.pathname + u.search; } catch (e) { /* keep */ } }
					td.textContent = label; td.title = r.key || ''; tr.appendChild(td);
					tr.appendChild(el('td', '', fmt(r.clicks))); tr.appendChild(el('td', '', fmt(r.position, 'pos')));
					frag.appendChild(tr);
				});
			}
			if (!frag.childNodes.length) {
				if (kind === 'latest') { frag.appendChild(el('li', 'pr-feed__empty', t.noData)); }
				else { var tr2 = el('tr'), td2 = el('td', '', t.noData); td2.colSpan = 3; tr2.appendChild(td2); frag.appendChild(tr2); }
			}
			list.replaceChildren(frag);
		});
	}

	function svg(tag, attrs) { var n = document.createElementNS('http://www.w3.org/2000/svg', tag); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); return n; }
	function bindChart(d) {
		document.querySelectorAll('[data-pr-live-chart]').forEach(function (box) {
			var pts = d.hourly || [], note = box.parentNode.querySelector('[data-pr-live-chartnote]');
			box.replaceChildren();
			if (!pts.length) { if (note) { note.textContent = d.ready ? 'Hourly data is not available for this property yet.' : t.noData; } return; }
			if (note) { note.textContent = ''; }
			var W = 760, H = 190, pl = 8, pr = 8, pt = 10, pb = 22, iw = W - pl - pr, ih = H - pt - pb;
			var maxI = Math.max.apply(null, pts.map(function (p) { return p.impressions; })) || 1;
			var maxC = Math.max.apply(null, pts.map(function (p) { return p.clicks; })) || 1;
			var s = svg('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': t.impressions + ' / ' + t.clicks, preserveAspectRatio: 'none', 'class': 'pr-chart' });
			var bw = iw / pts.length, line = [];
			pts.forEach(function (p, i) {
				var x = pl + i * bw, h = Math.max(1, p.impressions / maxI * ih);
				var r = svg('rect', { x: x + 1, y: pt + ih - h, width: Math.max(1, bw - 2), height: h, rx: 2, 'class': 'pr-chart__bar' });
				var tt = svg('title'); tt.textContent = new Date(p.t * 1000).toLocaleString() + ' — ' + nf.format(p.impressions) + ' ' + t.impressions.toLowerCase() + ', ' + nf.format(p.clicks) + ' ' + t.clicks.toLowerCase();
				r.appendChild(tt); s.appendChild(r);
				line.push((x + bw / 2).toFixed(1) + ',' + (pt + ih - p.clicks / maxC * ih).toFixed(1));
			});
			s.appendChild(svg('polyline', { points: line.join(' '), 'class': 'pr-chart__line', fill: 'none' }));
			var a = new Date(pts[0].t * 1000), b = new Date(pts[pts.length - 1].t * 1000);
			var l1 = svg('text', { x: pl, y: H - 5, 'class': 'pr-chart__axis' }); l1.textContent = a.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric' });
			var l2 = svg('text', { x: W - pr, y: H - 5, 'class': 'pr-chart__axis', 'text-anchor': 'end' }); l2.textContent = b.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric' });
			s.appendChild(l1); s.appendChild(l2); box.appendChild(s);
		});
	}

	function tick() {
		document.querySelectorAll('[data-pr-live-updated]').forEach(function (n) { n.textContent = state.data ? ago(state.data.updated_at) : ''; });
	}

	function apply(d) {
		state.data = d; state.hash = d.hash || state.hash;
		bindValues(d); bindList(d); bindChart(d); tick();
	}

	$(document).on('heartbeat-send', function (e, data) { data.publishrank_live = { hash: state.hash }; });
	$(document).on('heartbeat-tick', function (e, data) {
		var r = data && data.publishrank_live; if (!r) { return; }
		if (r.changed && r.data) { apply(r.data); } else { state.hash = r.hash || state.hash; }
		tick();
	});

	$(document).on('click', '[data-pr-live-refresh]', function () {
		var btn = this; btn.disabled = true; var old = btn.textContent; btn.textContent = t.refreshing;
		fetch(cfg.refreshUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
			.then(function (res) { var d = res.ok ? res.j : (res.j && res.j.data); if (d) { apply(d); } if (!res.ok) { window.alert((res.j && res.j.message) || t.failed); } })
			.catch(function () { window.alert(t.failed); })
			.then(function () { btn.disabled = false; btn.textContent = old; });
	});

	apply(cfg.initial || {});
	setInterval(tick, 30000);
	if (window.wp && wp.heartbeat && wp.heartbeat.interval) { wp.heartbeat.interval(15); }
}(jQuery));
