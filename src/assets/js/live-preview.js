/* PublishRank Live Preview (post editor). Works in the block editor and the classic editor.
   Sends the *unsaved* editor state to /publishrank/v1/preview/<id>, which returns what PublishRank will output.
   Everything from the server is rendered with textContent / DOM nodes only. */
(function ($) {
	'use strict';
	var cfg = window.publishrankPreview, root = document.getElementById('pr-preview');
	if (!cfg || !root) { return; }
	var t = cfg.i18n || {}, postId = root.getAttribute('data-post-id');
	var state = { seq: 0, timer: null, inflight: false, pending: false, last: null, lastAt: 0, tab: 'search', device: 'desktop', social: 'facebook', sig: '', lastBody: '', snippetTitle: null, snippetDescription: null };
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
	function field(selectors) { for (var i = 0; i < selectors.length; i++) { var n = document.querySelector(selectors[i]); if (n && typeof n.value === 'string') { return n; } } return null; }
	var titleFields = ['#publishrank_seo_title', '#publishrank_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]'];
	var descriptionFields = ['#publishrank_meta_description', '#publishrank_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]'];
	function setEditorField(selectors, value) { var n = field(selectors); if (!n) { return; } n.value = value; n.dispatchEvent(new Event('input', { bubbles: true })); n.dispatchEvent(new Event('change', { bubbles: true })); }

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
		if (state.snippetTitle !== null) { params.append('pr_snippet_title', state.snippetTitle); }
		if (state.snippetDescription !== null) { params.append('pr_snippet_description', state.snippetDescription); }
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
		var editor = el('div', 'pr-pv-snippet-editor');
		editor.appendChild(el('strong', 'pr-pv-snippet-editor__heading', t.editSnippet));
		var hint = el('span', 'pr-pv-snippet-editor__hint', t.variableHelp); hint.id = 'pr-pv-token-help'; editor.appendChild(hint);
		var titleField = el('div', 'pr-pv-snippet-editor__field');
		var titleLabel = el('label', '', t.searchTitle); titleLabel.htmlFor = 'pr-pv-snippet-title'; titleField.appendChild(titleLabel);
		var titleInput = el('input'); titleInput.type = 'text'; titleInput.id = 'pr-pv-snippet-title'; titleInput.maxLength = 255; titleInput.setAttribute('aria-describedby', hint.id); titleInput.value = state.snippetTitle !== null ? state.snippetTitle : ((field(titleFields) || {}).value || d.title || ''); titleField.appendChild(titleInput);
		var titleSlot = el('span', 'pr-var-slot'); titleField.appendChild(titleSlot); editor.appendChild(titleField);
		var descField = el('div', 'pr-pv-snippet-editor__field');
		var descLabel = el('label', '', t.metaDescription); descLabel.htmlFor = 'pr-pv-snippet-description'; descField.appendChild(descLabel);
		var descInput = el('textarea'); descInput.id = 'pr-pv-snippet-description'; descInput.rows = 3; descInput.maxLength = 320; descInput.setAttribute('aria-describedby', hint.id); descInput.value = state.snippetDescription !== null ? state.snippetDescription : ((field(descriptionFields) || {}).value || d.description || ''); descField.appendChild(descInput);
		var descSlot = el('span', 'pr-var-slot'); descField.appendChild(descSlot); editor.appendChild(descField);
		p.appendChild(editor);
		function changed(input, kind, selectors) { state[kind] = input.value; setEditorField(selectors, input.value); schedule(250); }
		titleInput.addEventListener('input', function () { changed(titleInput, 'snippetTitle', titleFields); }); descInput.addEventListener('input', function () { changed(descInput, 'snippetDescription', descriptionFields); });
		if (window.PublishRankVariables) { PublishRankVariables.mount(titleSlot, titleInput, 'title', 'post'); PublishRankVariables.mount(descSlot, descInput, 'description', 'post'); }
		var sw = el('div', 'pr-pv-switch');
		sw.setAttribute('role', 'group'); sw.setAttribute('aria-label', t.previewDevice);
		['desktop', 'mobile'].forEach(function (k) { var b = el('button', 'pr-pv-switch__btn' + (state.device === k ? ' is-active' : ''), t[k]); b.type = 'button'; b.setAttribute('aria-pressed', state.device === k ? 'true' : 'false'); b.addEventListener('click', function () { state.device = k; renderSearch(state.last); }); sw.appendChild(b); });
		p.appendChild(sw);
		var card = el('div', 'pr-serp' + (mobile ? ' is-mobile' : '')), top = el('div', 'pr-serp__top');
		var fav = d.favicon ? el('img', 'pr-serp__fav') : el('span', 'pr-serp__fav pr-serp__fav--ph', (d.site_name || 'S').charAt(0).toUpperCase());
		if (d.favicon && safeUrl(d.favicon)) { fav.src = d.favicon; fav.alt = ''; }
		top.appendChild(fav);
		var who = el('div', 'pr-serp__who'); who.appendChild(el('div', 'pr-serp__site', d.site_name)); who.appendChild(el('div', 'pr-serp__url', crumbs(d.canonical))); top.appendChild(who); card.appendChild(top);
		var tfont = (mobile ? '20px' : '20px') + ' Arial,sans-serif', tmax = mobile ? 560 : 600;
		card.appendChild(el('div', 'pr-serp__title', clip(d.title || '', tfont, tmax * (mobile ? 2 : 1))));
			var date = ''; try { date = new Date(d.date).toLocaleDateString(document.documentElement.lang || undefined, { month: 'short', day: 'numeric', year: 'numeric' }); } catch (e) { /* none */ }
		var dfont = '14px Arial,sans-serif', dmax = mobile ? 640 : 920;
		var desc = el('div', 'pr-serp__desc'); if (date) { desc.appendChild(el('span', 'pr-serp__date', date + ' — ')); }
		desc.appendChild(document.createTextNode(clip(d.description || '', dfont, dmax * (mobile ? 3 : 2) - (date ? 70 : 0)))); card.appendChild(desc);
		p.appendChild(card);
		var m = el('div', 'pr-pv-meters');
		m.appendChild(meter(t.title, px(d.title || '', tfont), 600, t.pixels));
		m.appendChild(meter(t.metaDescription, (d.description || '').length, 160, t.chars));
		p.appendChild(m);
			if (!d.description) { p.appendChild(el('p', 'pr-pv-hint', issueText(d, t.descriptionIssue) || t.descriptionIssue)); }
		}
		function issueText(d, area) { var f = (d.issues || []).filter(function (i) { return i.area === area; })[0]; return f ? f.msg : ''; }
		function renderWebsite(d) {
			var p = panel('website'); p.replaceChildren();
			p.appendChild(el('p', 'pr-preview__note', t.websiteNote));
			var card = el('article', 'pr-website-card');
			card.appendChild(el('div', 'pr-website-card__eyebrow', d.site_name || t.website));
			card.appendChild(el('h3', 'pr-website-card__title', d.visible_title || d.title || t.untitled));
			if (d.visible_description) { card.appendChild(el('p', 'pr-website-card__summary', d.visible_description)); }
			var link = el('a', 'pr-website-card__url', d.canonical || d.permalink || ''); link.href = safeUrl(d.canonical || d.permalink) || '#'; link.target = '_blank'; link.rel = 'noopener noreferrer'; card.appendChild(link);
			p.appendChild(card);
			var table = el('table', 'widefat striped pr-pv-table'), caption = table.createCaption(), body = el('tbody'); caption.className = 'screen-reader-text'; caption.textContent = t.websiteTable;
			var row = function (label, value, ok) { var tr = el('tr'), key = el('th', 'pr-pv-table__k', label); key.scope = 'row'; tr.appendChild(key); var td = el('td', 'pr-pv-table__v', value || '—'); if (ok !== undefined) { td.appendChild(el('span', 'pr-website-check ' + (ok ? 'is-good' : 'is-warn'), ok ? ' ✓ ' + t.consistent : ' ! ' + t.review)); } tr.appendChild(td); body.appendChild(tr); };
			row(t.visibleHeading, d.visible_title || '', true);
			row(t.searchTitle, d.title || '', !d.visible_title || !d.title || d.visible_title === d.title);
			row(t.visibleSummary, d.visible_description || '', true);
			row(t.metaDescription, d.description || '', !d.visible_description || !d.description || d.visible_description === d.description);
			row(t.canonicalUrl, d.canonical || d.permalink || '', !!(d.canonical || d.permalink));
			row(t.socialImage, d.social && d.social.image ? d.social.image : '', !!(d.social && d.social.image));
			row(t.structuredData, (d.nodes || []).map(function (n) { return n.type || ''; }).filter(Boolean).join(', '), (d.nodes || []).length > 0);
			table.appendChild(body); p.appendChild(table);
			p.appendChild(el('p', 'pr-pv-hint', t.rewriteNote));
		}

		function renderSocial(d) {
		var p = panel('social'), s = d.social || {}; p.replaceChildren();
		var sw = el('div', 'pr-pv-switch'); sw.setAttribute('role', 'group'); sw.setAttribute('aria-label', t.socialPlatform);
		['facebook', 'x'].forEach(function (k) { var b = el('button', 'pr-pv-switch__btn' + (state.social === k ? ' is-active' : ''), t[k]); b.type = 'button'; b.setAttribute('aria-pressed', state.social === k ? 'true' : 'false'); b.addEventListener('click', function () { state.social = k; renderSocial(state.last); }); sw.appendChild(b); });
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
		if (isX && s.card) { p.appendChild(el('p', 'pr-pv-hint', t.xCard + ': ' + s.card)); }
	}

	function renderMeta(d) {
		var p = panel('meta'); p.replaceChildren();
		var tbl = el('table', 'widefat striped pr-pv-table'), caption = tbl.createCaption(), tb = el('tbody'); caption.className = 'screen-reader-text'; caption.textContent = t.metadataTable;
			var add = function (k, v, kind) { var tr = el('tr'), key = el('th', 'pr-pv-table__k', (kind ? kind + ' · ' : '') + k); key.scope = 'row'; tr.appendChild(key); tr.appendChild(el('td', 'pr-pv-table__v', v)); tb.appendChild(tr); };
			add(t.title, d.title || '', '');
		(d.meta || []).forEach(function (m) { add(m.k, m.v, m.kind); });
			(d.links || []).forEach(function (l) { add(l.rel + (l.hreflang ? ' [' + l.hreflang + ']' : ''), l.href, t.link); });
			tbl.appendChild(tb); p.appendChild(tbl);
			var det = el('details', 'pr-pv-raw'); det.appendChild(el('summary', '', t.headMarkup)); var pre = el('pre', 'pr-pv-pre', d.head_raw || ''); det.appendChild(pre); p.appendChild(det);
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
		var region = root.querySelector('[data-pr-pv-copy-status]');
		if (!region) { region = el('span', 'screen-reader-text'); region.setAttribute('role', 'status'); region.setAttribute('aria-live', 'polite'); region.setAttribute('aria-atomic', 'true'); region.setAttribute('data-pr-pv-copy-status', ''); root.appendChild(region); }
		var done = function (ok) { var original = btn.textContent; btn.textContent = ok ? t.copied : t.copyFailed; region.textContent = ok ? t.copied : t.copyFailed; if (ok) { setTimeout(function () { btn.textContent = original; }, 1400); } };
		var fallback = function () {
			var active = document.activeElement, ta = el('textarea'); ta.value = text; ta.setAttribute('aria-hidden', 'true'); ta.tabIndex = -1; ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
			var ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; } document.body.removeChild(ta); if (active && active.focus) { active.focus(); } return ok;
		};
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(fallback()); }); return; }
		done(fallback());
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
			var row = el('div', 'pr-pv-check is-' + i.level); var icon = el('span', 'pr-pv-check__icon', icons[i.level] || 'i'); icon.setAttribute('aria-hidden', 'true'); row.appendChild(icon);
			var txt = el('div', 'pr-pv-check__text'); txt.appendChild(el('strong', '', i.area + ' ')); txt.appendChild(document.createTextNode(i.msg)); row.appendChild(txt); p.appendChild(row);
		});
		if (!list.length) { p.appendChild(el('p', 'pr-pv-hint', t.allGood)); }
		var bad = list.filter(function (i) { return i.level === 'error' || i.level === 'warn'; }).length, err = list.some(function (i) { return i.level === 'error'; });
		if ($count) { $count.hidden = !bad; $count.textContent = bad; $count.className = 'pr-preview__count' + (err ? ' is-error' : ''); }
	}

		function render(d) { renderSearch(d); renderWebsite(d); renderSocial(d); renderMeta(d); renderJson(d); renderChecks(d); }

	/* ---------- tabs + events ---------- */
	function activateTab(tab, moveFocus) {
		if (!tab) { return; }
		state.tab = tab.getAttribute('data-pr-pv-tab');
		root.querySelectorAll('[data-pr-pv-tab]').forEach(function (button) {
			var active = button === tab;
			button.classList.toggle('is-active', active);
			button.setAttribute('aria-selected', active ? 'true' : 'false');
			button.setAttribute('tabindex', active ? '0' : '-1');
		});
		root.querySelectorAll('[data-pr-pv-panel]').forEach(function (pane) { pane.hidden = pane.getAttribute('data-pr-pv-panel') !== state.tab; });
		if (moveFocus) { tab.focus(); }
	}
	root.addEventListener('click', function (e) {
		var tab = e.target.closest('[data-pr-pv-tab]');
		if (tab) { activateTab(tab, false); }
		if (e.target.closest('[data-pr-pv-refresh]')) { state.lastBody = ''; schedule(0); }
	});
	root.addEventListener('keydown', function (e) {
		var current = e.target.closest('[data-pr-pv-tab]');
		if (!current) { return; }
		var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-pr-pv-tab]'));
		var index = tabs.indexOf(current), next = index;
		if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { next = (index + 1) % tabs.length; }
		else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { next = (index - 1 + tabs.length) % tabs.length; }
		else if (e.key === 'Home') { next = 0; }
		else if (e.key === 'End') { next = tabs.length - 1; }
		else { return; }
		e.preventDefault(); activateTab(tabs[next], true);
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
