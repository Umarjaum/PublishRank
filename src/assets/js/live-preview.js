/* PublishRank Live Preview (post editor). Works in the block editor and the classic editor.
   Sends the *unsaved* editor state to /publishrank/v1/preview/<id>, which returns what PublishRank will output.
   Everything from the server is rendered with textContent / DOM nodes only. */
(function ($) {
	'use strict';
	var cfg = window.publishrankPreview, root = document.getElementById('pr-preview');
	if (!cfg || !root) { return; }
	var t = cfg.i18n || {}, postId = root.getAttribute('data-post-id');
	var state = { seq: 0, timer: null, inflight: false, pending: false, last: null, lastAt: 0, tab: 'search', device: 'desktop', social: 'facebook', sig: '', lastBody: '' };
	var $status = root.querySelector('[data-pr-pv-status]'), $pill = root.querySelector('[data-pr-pv-pill]'), $count = root.querySelector('[data-pr-pv-count]');
	var ctx = null; try { ctx = document.createElement('canvas').getContext('2d'); } catch (e) { ctx = null; }

	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = text; } return n; }
	function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }
	function px(text, font) { if (!ctx) { return String(text).length * 9; } ctx.font = font; return ctx.measureText(text).width; }
	function clip(text, font, max) {
		if (px(text, font) <= max) { return text; }
		var s = text; while (s.length > 1 && px(s + '…', font) > max) { s = s.slice(0, -1); }
		return s.replace(/\s+$/, '') + '…';
	}
	function panel(name) { return root.querySelector('[data-pr-pv-panel="' + name + '"]'); }

	/* ---------- collect unsaved state ---------- */
	function editorStore() { try { return window.wp && wp.data && wp.data.select && wp.data.select('core/editor'); } catch (e) { return null; } }
	function collectEditor() {
		var o = {}, ed = editorStore();
		if (ed && ed.getEditedPostAttribute) {
			try {
				o.pr_title = ed.getEditedPostAttribute('title') || '';
				o.pr_content = ed.getEditedPostContent() || '';
				o.pr_excerpt = ed.getEditedPostAttribute('excerpt') || '';
				o.pr_slug = ed.getEditedPostAttribute('slug') || '';
				var fm = ed.getEditedPostAttribute('featured_media'); if (fm) { o.pr_thumb = fm; }
				return o;
			} catch (e) { /* fall through to classic */ }
		}
		var v = function (s) { var n = document.querySelector(s); return n && typeof n.value === 'string' ? n.value : null; };
		var title = v('#title'); if (title !== null) { o.pr_title = title; }
		var content = null;
		if (window.tinymce && tinymce.get && tinymce.get('content') && !tinymce.get('content').isHidden()) { content = tinymce.get('content').getContent(); } else { content = v('#content'); }
		if (content !== null) { o.pr_content = content; }
		var ex = v('#excerpt'); if (ex !== null) { o.pr_excerpt = ex; }
		var slug = v('#post_name'); if (slug) { o.pr_slug = slug; }
		var th = v('#_thumbnail_id'); if (th && th !== '-1') { o.pr_thumb = th; }
		return o;
	}
	function collectMeta() {
		var pairs = [];
		document.querySelectorAll('[name^="_publishrank_seo_"],[name^="publishrank_faq_items"],[name="publishrank_seo_meta_nonce"],[name="publishrank_seo_article_fields"]').forEach(function (n) {
			if (n.disabled || !n.name) { return; }
			if ((n.type === 'checkbox' || n.type === 'radio') && !n.checked) { return; }
			if (n.tagName === 'SELECT' && n.multiple) { Array.prototype.forEach.call(n.selectedOptions, function (o) { pairs.push({ name: n.name, value: o.value }); }); return; }
			if (n.type === 'button' || n.type === 'submit') { return; }
			pairs.push({ name: n.name, value: n.value });
		});
		return pairs;
	}
	function formBody() {
		var params = new URLSearchParams(), meta = collectMeta(), editor = collectEditor();
		meta.forEach(function (pair) { params.append(pair.name, pair.value == null ? '' : pair.value); });
		Object.keys(editor).forEach(function (key) { params.append(key, editor[key] == null ? '' : editor[key]); });
		return params.toString();
	}

	/* ---------- request loop ---------- */
	function setStatus(text, off) { if ($status) { $status.textContent = text; } if ($pill) { $pill.classList.toggle('is-off', !!off); } }
	function tick(force) {
		if (!state.lastAt || (state.inflight && !force)) { return; }
		var s = Math.round((Date.now() - state.lastAt) / 1000);
		setStatus(t.updated + ' ' + (s < 4 ? t.justNow : s + t.ago));
	}
	function schedule(delay) {
		if (document.hidden) { return; }
		clearTimeout(state.timer);
		state.timer = setTimeout(run, typeof delay === 'number' ? delay : 900);
	}
	function run() {
		if (state.inflight) { state.pending = true; return; }
		state.inflight = true; var mine = ++state.seq; setStatus(t.updating);
		var body = formBody();
		if (body === state.lastBody && state.last) { state.inflight = false; render(state.last); return; }
		state.lastBody = body;
		fetch(cfg.url + postId, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body })
			.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
			.then(function (res) {
				if (mine !== state.seq) { return; }
				if (!res.ok || !res.j || res.j.error) { throw new Error('bad'); }
				state.last = res.j; state.lastAt = Date.now(); render(res.j); tick(true);
				var b = document.getElementById('publishrank-editor-score-refresh'); if (b) { setTimeout(function () { b.click(); }, 250); }
			})
				.catch(function () { state.lastBody = ''; setStatus(t.failed, true); })
			.then(function () { state.inflight = false; if (state.pending) { state.pending = false; schedule(300); } });
	}

	/* ---------- rendering ---------- */
	function meter(label, used, limit, unit) {
		var wrap = el('div', 'pr-pv-meter'), bar = el('div', 'pr-pv-meter__bar'), fill = el('span', 'pr-pv-meter__fill');
		var pct = Math.min(100, used / limit * 100); fill.style.width = pct + '%';
		fill.className += used > limit ? ' is-over' : (used < limit * 0.45 ? ' is-short' : ' is-good');
		bar.appendChild(fill); wrap.appendChild(el('span', 'pr-pv-meter__label', label)); wrap.appendChild(bar);
		wrap.appendChild(el('span', 'pr-pv-meter__val', Math.round(used) + ' ' + unit)); return wrap;
	}
	function crumbs(url) {
		try { var u = new URL(url); var parts = u.pathname.split('/').filter(Boolean).slice(0, 3); return u.hostname + (parts.length ? ' › ' + parts.join(' › ') : ''); } catch (e) { return url || ''; }
	}
	function renderSearch(d) {
		var p = panel('search'), mobile = state.device === 'mobile'; p.replaceChildren();
		var sw = el('div', 'pr-pv-switch');
		['desktop', 'mobile'].forEach(function (k) { var b = el('button', 'pr-pv-switch__btn' + (state.device === k ? ' is-active' : ''), t[k]); b.type = 'button'; b.addEventListener('click', function () { state.device = k; renderSearch(state.last); }); sw.appendChild(b); });
		p.appendChild(sw);
		var card = el('div', 'pr-serp' + (mobile ? ' is-mobile' : '')), top = el('div', 'pr-serp__top');
		var fav = d.favicon ? el('img', 'pr-serp__fav') : el('span', 'pr-serp__fav pr-serp__fav--ph', (d.site_name || 'S').charAt(0).toUpperCase());
		if (d.favicon && safeUrl(d.favicon)) { fav.src = d.favicon; fav.alt = ''; }
		top.appendChild(fav);
		var who = el('div', 'pr-serp__who'); who.appendChild(el('div', 'pr-serp__site', d.site_name)); who.appendChild(el('div', 'pr-serp__url', crumbs(d.canonical))); top.appendChild(who); card.appendChild(top);
		var tfont = (mobile ? '20px' : '20px') + ' Arial,sans-serif', tmax = mobile ? 560 : 600;
		card.appendChild(el('div', 'pr-serp__title', clip(d.title || '', tfont, tmax * (mobile ? 2 : 1))));
		var date = ''; try { date = new Date(d.date).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { /* none */ }
		var dfont = '14px Arial,sans-serif', dmax = mobile ? 640 : 920;
		var desc = el('div', 'pr-serp__desc'); if (date) { desc.appendChild(el('span', 'pr-serp__date', date + ' — ')); }
		desc.appendChild(document.createTextNode(clip(d.description || '', dfont, dmax * (mobile ? 3 : 2) - (date ? 70 : 0)))); card.appendChild(desc);
		p.appendChild(card);
		var m = el('div', 'pr-pv-meters');
		m.appendChild(meter(t.title, px(d.title || '', tfont), 600, t.pixels));
		m.appendChild(meter(t.description, (d.description || '').length, 160, t.chars));
		p.appendChild(m);
		if (!d.description) { p.appendChild(el('p', 'pr-pv-hint', '⚠ ' + (issueText(d, 'Description') || ''))); }
	}
	function issueText(d, area) { var f = (d.issues || []).filter(function (i) { return i.area === area; })[0]; return f ? f.msg : ''; }

	function renderSocial(d) {
		var p = panel('social'), s = d.social || {}; p.replaceChildren();
		var sw = el('div', 'pr-pv-switch');
		['facebook', 'x'].forEach(function (k) { var b = el('button', 'pr-pv-switch__btn' + (state.social === k ? ' is-active' : ''), t[k]); b.type = 'button'; b.addEventListener('click', function () { state.social = k; renderSocial(state.last); }); sw.appendChild(b); });
		p.appendChild(sw);
		var isX = state.social === 'x', img = safeUrl(isX ? (s.x_image || s.image) : s.image);
		var card = el('div', 'pr-social ' + (isX ? 'is-x' : 'is-fb')), media = el('div', 'pr-social__media');
		if (img) { var i = el('img'); i.src = img; i.alt = ''; media.appendChild(i); } else { media.appendChild(el('span', 'pr-social__none', t.noImage)); }
		card.appendChild(media);
		var body = el('div', 'pr-social__body'), host = ''; try { host = new URL(d.canonical).hostname; } catch (e) { host = d.site_name || ''; }
		body.appendChild(el('div', 'pr-social__host', host));
		body.appendChild(el('div', 'pr-social__title', clip((isX ? (s.x_title || s.title) : s.title) || d.title || '', '16px Arial,sans-serif', 520)));
		body.appendChild(el('div', 'pr-social__desc', clip((isX ? (s.x_description || s.description) : s.description) || '', '14px Arial,sans-serif', 900)));
		card.appendChild(body); p.appendChild(card);
		if (isX && s.card) { p.appendChild(el('p', 'pr-pv-hint', 'twitter:card = ' + s.card)); }
	}

	function renderMeta(d) {
		var p = panel('meta'); p.replaceChildren();
		var tbl = el('table', 'widefat striped pr-pv-table'), tb = el('tbody');
		var add = function (k, v, kind) { var tr = el('tr'); tr.appendChild(el('td', 'pr-pv-table__k', (kind ? kind + ' · ' : '') + k)); tr.appendChild(el('td', 'pr-pv-table__v', v)); tb.appendChild(tr); };
		add('title', d.title || '', '');
		(d.meta || []).forEach(function (m) { add(m.k, m.v, m.kind); });
		(d.links || []).forEach(function (l) { add(l.rel + (l.hreflang ? ' [' + l.hreflang + ']' : ''), l.href, 'link'); });
		tbl.appendChild(tb); p.appendChild(tbl);
		var det = el('details', 'pr-pv-raw'); det.appendChild(el('summary', '', '<head>')); var pre = el('pre', 'pr-pv-pre', d.head_raw || ''); det.appendChild(pre); p.appendChild(det);
	}

	function highlight(json) {
		var frag = document.createDocumentFragment(), re = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/g, last = 0, m;
		while ((m = re.exec(json))) {
			if (m.index > last) { frag.appendChild(document.createTextNode(json.slice(last, m.index))); }
			var cls = m[1] ? (m[2] ? 'k' : 's') : (m[3] ? 'b' : 'n');
			frag.appendChild(el('span', 'pr-j-' + cls, m[1] ? m[1] : m[0]));
			if (m[2]) { frag.appendChild(document.createTextNode(m[2])); }
			last = re.lastIndex;
		}
		if (last < json.length) { frag.appendChild(document.createTextNode(json.slice(last))); }
		return frag;
	}
	function copy(text, btn) {
		var done = function () { var o = btn.textContent; btn.textContent = t.copied; setTimeout(function () { btn.textContent = o; }, 1400); };
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); return; }
		var ta = el('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ } document.body.removeChild(ta);
	}
	function renderJson(d) {
		var p = panel('json'); p.replaceChildren();
		var blocks = d.jsonld || [];
		if (!blocks.length) { p.appendChild(el('p', 'pr-pv-hint', t.noJson)); return; }
		var head = el('div', 'pr-pv-jhead'), chips = el('div', 'pr-pv-chips'); chips.appendChild(el('span', 'pr-pv-chips__label', t.nodes + ':'));
		(d.nodes || []).forEach(function (n) { var c = el('span', 'pr-pv-chip', n.type || '?'); if (n.name) { c.title = n.name; } chips.appendChild(c); });
		head.appendChild(chips);
		var a = el('a', 'button', d.published ? t.rich : t.richCode); a.target = '_blank'; a.rel = 'noopener noreferrer';
		a.href = d.published && safeUrl(d.permalink) ? 'https://search.google.com/test/rich-results?url=' + encodeURIComponent(d.permalink) : 'https://search.google.com/test/rich-results';
		head.appendChild(a); p.appendChild(head);
		if (!d.published) { p.appendChild(el('p', 'pr-pv-hint', t.unsaved)); }
		blocks.forEach(function (b, i) {
			var box = el('div', 'pr-pv-json' + (b.valid ? '' : ' is-invalid')), bar = el('div', 'pr-pv-json__bar');
			bar.appendChild(el('strong', '', '#' + (i + 1) + (b.types && b.types.length ? ' · ' + b.types.join(', ') : '') + (b.valid ? '' : ' — ' + t.invalid + ': ' + b.error)));
			var cb = el('button', 'button button-small', t.copy); cb.type = 'button'; cb.addEventListener('click', function () { copy(b.pretty, cb); }); bar.appendChild(cb);
			var pre = el('pre', 'pr-pv-pre pr-pv-pre--json'); pre.appendChild(highlight(b.pretty || '')); box.appendChild(bar); box.appendChild(pre); p.appendChild(box);
		});
	}

	function renderChecks(d) {
		var p = panel('checks'); p.replaceChildren();
		var list = d.issues || [], order = { error: 0, warn: 1, info: 2, ok: 3 }, icons = { error: '✕', warn: '!', info: 'i', ok: '✓' };
		list.slice().sort(function (a, b) { return order[a.level] - order[b.level]; }).forEach(function (i) {
			var row = el('div', 'pr-pv-check is-' + i.level); row.appendChild(el('span', 'pr-pv-check__icon', icons[i.level] || 'i'));
			var txt = el('div', 'pr-pv-check__text'); txt.appendChild(el('strong', '', i.area + ' ')); txt.appendChild(document.createTextNode(i.msg)); row.appendChild(txt); p.appendChild(row);
		});
		if (!list.length) { p.appendChild(el('p', 'pr-pv-hint', t.allGood)); }
		var bad = list.filter(function (i) { return i.level === 'error' || i.level === 'warn'; }).length, err = list.some(function (i) { return i.level === 'error'; });
		if ($count) { $count.hidden = !bad; $count.textContent = bad; $count.className = 'pr-preview__count' + (err ? ' is-error' : ''); }
	}

	function render(d) { renderSearch(d); renderSocial(d); renderMeta(d); renderJson(d); renderChecks(d); }

	/* ---------- tabs + events ---------- */
	root.addEventListener('click', function (e) {
		var tab = e.target.closest('[data-pr-pv-tab]'); if (tab) {
			state.tab = tab.getAttribute('data-pr-pv-tab');
			root.querySelectorAll('[data-pr-pv-tab]').forEach(function (b) { var on = b === tab; b.classList.toggle('is-active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
			root.querySelectorAll('[data-pr-pv-panel]').forEach(function (pn) { pn.hidden = pn.getAttribute('data-pr-pv-panel') !== state.tab; });
		}
			if (e.target.closest('[data-pr-pv-refresh]')) { state.lastBody = ''; schedule(0); }
	});
		$(document).on('input change keyup paste', '[name^="_publishrank_seo_"],[name^="publishrank_faq_items"],[name="publishrank_seo_meta_nonce"],#title,#content,#excerpt,#post_name', function () { schedule(300); });
	$(document).on('tinymce-editor-init', function (e, ed) { if (ed && ed.id === 'content') { ed.on('input change keyup SetContent', function () { schedule(); }); } });
	$(document).ajaxComplete(function (e, x, s) { if (s && typeof s.data === 'string' && /set-post-thumbnail|get-post-thumbnail|_thumbnail_id/.test(s.data)) { schedule(400); } });
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { schedule(200); } });
	if (window.wp && wp.data && wp.data.subscribe) {
		wp.data.subscribe(function () {
			var ed = editorStore(), be; if (!ed || !ed.getEditedPostAttribute) { return; }
			try {
				be = wp.data.select('core/block-editor'); var blocks = be && be.getBlocks ? be.getBlocks() : null;
					var content = ed.getEditedPostContent ? ed.getEditedPostContent() : '';
					var meta = ed.getEditedPostAttribute('meta') || {};
					var sig = [ed.getEditedPostAttribute('title'), content, ed.getEditedPostAttribute('slug'), ed.getEditedPostAttribute('excerpt'), ed.getEditedPostAttribute('featured_media'), JSON.stringify(meta)].join('\u0001');
					if (sig !== state.sig) { state.sig = sig; schedule(300); }
			} catch (err) { /* editor not ready */ }
		});
	}
	setInterval(tick, 5000);
		$(function () { schedule(250); });
}(jQuery));
