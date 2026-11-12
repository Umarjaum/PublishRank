/* PublishRank SEO Analysis panel.
 * Mirrors every run of the PublishRank SEO analysis (meta box), keeps it live while typing in the block
 * editor, and can run on its own when the meta box is not on the page. All server text is set with
 * textContent.
 *
 * Placement modes (remembered per browser):
 * - docked (default): lives at the top of the editor sidebar. The block editor sidebar hands over a slot
 *   via PublishRankScoreCard.dock(); the classic editor gets a slot at the top of the right column.
 *   While docked and no slot is visible (block editor sidebar closed) the panel is parked (hidden) and
 *   the live score badge in the top toolbar is the summary.
 * - floating: draggable panel over the editor, can be minimized to a pill.
 * Every repaint dispatches a `publishrank:score` event; mode changes dispatch `publishrank:mode`. */
(function ($) {
	'use strict';
	var cfg = window.publishrankScoreCard || {}, t = cfg.i18n || {};
	var CIRC = 2 * Math.PI * 44;
	var STORE_POS = 'publishrank-editor-score-position', STORE_MIN = 'publishrank-editor-score-minimized', STORE_MODE = 'publishrank-editor-score-mode';
	var reduceMotion = false;
	try { reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

	function band(score) { return score >= 90 ? 'excellent' : (score >= 80 ? 'good' : (score >= 55 ? 'ok' : 'bad')); }
	var LABEL = { excellent: 'excellent', good: 'good', ok: 'ok', bad: 'bad' };
	var MSG = { excellent: 'msgExcellent', good: 'msgGood', ok: 'msgOk', bad: 'msgBad' };

	function intelAction() { return window.publishrankIntel && publishrankIntel.action; }

	/* ---------- painting ---------- */
	function animateNumber(el, to) {
		if (!el) { return; }
		var from = parseInt(el.textContent, 10);
		if (isNaN(from) || reduceMotion || from === to) { el.textContent = String(to); return; }
		var start = null, dur = 600;
		function step(ts) {
			if (!start) { start = ts; }
			var p = Math.min(1, (ts - start) / dur), eased = 1 - Math.pow(1 - p, 3);
			el.textContent = String(Math.round(from + (to - from) * eased));
			if (p < 1) { window.requestAnimationFrame(step); }
		}
		window.requestAnimationFrame(step);
	}

	function renderList(card, kind, rows) {
		var group = card.querySelector('[data-pr-esc-group="' + kind + '"]');
		if (!group) { return; }
		var list = group.querySelector('[data-pr-esc-list]'), count = group.querySelector('[data-pr-esc-count]');
		list.replaceChildren();
		if (count) { count.textContent = String(rows.length); }
		if (!rows.length) { var e = document.createElement('li'); e.className = 'pr-esc__empty'; e.textContent = list.getAttribute('data-empty') || ''; list.appendChild(e); return; }
		rows.forEach(function (row) {
			var li = document.createElement('li'), dot = document.createElement('span'), text = document.createElement('span'), strong = document.createElement('strong'), small = document.createElement('small');
			li.className = 'pr-esc__check is-' + (row.status || 'ok');
			dot.className = 'pr-esc__dot'; dot.setAttribute('aria-hidden', 'true');
			strong.textContent = row.title || '';
			small.textContent = row.message || '';
			text.appendChild(strong); text.appendChild(small);
			li.appendChild(dot); li.appendChild(text);
			if (row.status !== 'good' && row.fix) {
				var b = document.createElement('button');
				b.type = 'button'; b.className = 'button button-small pr-esc__fix';
				b.setAttribute('data-pr-esc-fix', row.fix);
				b.setAttribute('aria-label', String(t.fixAria || 'Fix: %s').replace('%s', row.title || ''));
				b.textContent = t.fix || 'Fix';
				li.appendChild(b);
			}
			list.appendChild(li);
		});
	}

	function paint(data) {
		var card = document.getElementById('publishrank-editor-score');
		if (!card || !data) { return; }
		var score = Math.max(0, Math.min(100, parseInt(data.score, 10) || 0)), b = band(score);
		card.setAttribute('data-score', score);
		card.className = card.className.replace(/\bpr-esc--(excellent|good|ok|bad)\b/g, '').trim() + ' pr-esc--' + b;
		var arc = card.querySelector('[data-pr-esc-arc]');
		if (arc) { arc.setAttribute('stroke-dashoffset', (CIRC * (1 - score / 100)).toFixed(2)); }
		var ring = card.querySelector('[data-pr-esc-ring]');
		if (ring && t.scoreAria) { ring.setAttribute('aria-label', String(t.scoreAria).replace('%d', score)); }
		animateNumber(card.querySelector('[data-pr-esc-score]'), score);
		var pill = card.querySelector('[data-pr-esc-pill-score]'); if (pill) { pill.textContent = String(score); }
		var pillRing = card.querySelector('.pr-esc__pill-ring'); if (pillRing) { pillRing.style.setProperty('--pr-esc-score', score); }
		var label = t[LABEL[b]] || '';
		var rating = card.querySelector('[data-pr-esc-rating]'); if (rating) { rating.textContent = label; }
		var pillLabel = card.querySelector('[data-pr-esc-pill-label]'); if (pillLabel) { pillLabel.textContent = label; }
		var msg = card.querySelector('[data-pr-esc-msg]'); if (msg) { msg.textContent = t[MSG[b]] || ''; }
		if (data.stats) {
			['words', 'images', 'internal', 'external'].forEach(function (k) {
				var el = card.querySelector('[data-pr-esc-metric="' + k + '"]');
				if (el) { el.textContent = Number(data.stats[k] || 0).toLocaleString(); }
			});
		}
		if (typeof data.keyphrase === 'string') {
			var kw = card.querySelector('[data-pr-esc-keyword]');
			if (kw) { kw.textContent = data.keyphrase || t.noKeyword || ''; kw.classList.toggle('is-empty', !data.keyphrase); }
		}
		if (Array.isArray(data.checks)) {
			renderList(card, 'fix', data.checks.filter(function (c) { return c.status !== 'good'; }));
			renderList(card, 'pass', data.checks.filter(function (c) { return c.status === 'good'; }));
		}
		paintLengths();
		emit('publishrank:score', { score: score, band: b, label: label });
		setStatus(t.updated || '');
		card.classList.remove('is-busy');
		card.classList.add('is-pulse');
		window.setTimeout(function () { card.classList.remove('is-pulse'); }, 700);
	}

	function emit(name, detail) { try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {} }
	function setStatus(text) { var s = document.querySelector('[data-pr-esc-status]'); if (s) { s.textContent = text; } }
	function busy() { var c = document.getElementById('publishrank-editor-score'); if (c) { c.classList.add('is-busy'); } setStatus(t.analyzing || ''); }

	/* ---------- mirror the meta box analysis ---------- */
	$(document).ajaxSend(function (e, xhr, settings) {
		var a = intelAction();
		if (a && settings && typeof settings.data === 'string' && settings.data.indexOf('action=' + a) !== -1) { busy(); }
	});
	$(document).ajaxSuccess(function (e, xhr, settings, res) {
		var a = intelAction();
		if (!a || !settings || typeof settings.data !== 'string' || settings.data.indexOf('action=' + a) === -1) { return; }
		if (res && res.success) { paint(res.data); } else { setStatus(t.failed || ''); }
	});
	$(document).ajaxError(function (e, xhr, settings) {
		var a = intelAction();
		if (a && settings && typeof settings.data === 'string' && settings.data.indexOf('action=' + a) !== -1) { setStatus(t.failed || ''); var c = document.getElementById('publishrank-editor-score'); if (c) { c.classList.remove('is-busy'); } }
	});

	/* ---------- standalone analysis when the meta box is not present ---------- */
	var TITLE_SEL = ['#publishrank_seo_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]'];
	var DESC_SEL = ['#publishrank_meta_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]'];
	function edMeta(key) { try { var m = wp.data.select('core/editor').getEditedPostAttribute('meta'); return (m && m[key]) || ''; } catch (e) { return ''; } }
	/* Live character counts in the floating card: SEO title 30–60, meta description 120–160. */
	function paintLengths() {
		var card = document.getElementById('publishrank-editor-score');
		if (!card) { return; }
		var title = value(TITLE_SEL) || edMeta('_publishrank_seo_title') || edAttr('title') || value(['#title']);
		var desc = value(DESC_SEL) || edMeta('_publishrank_seo_description');
		[['title', title.length, 30, 60], ['desc', desc.length, 120, 160]].forEach(function (r) {
			var el = card.querySelector('[data-pr-esc-len="' + r[0] + '"]');
			if (!el) { return; }
			el.textContent = String(r[1]);
			el.className = r[1] > r[3] ? 'is-over' : (r[1] < r[2] ? 'is-short' : 'is-ok');
		});
	}
	$(document).on('input', TITLE_SEL.concat(DESC_SEL, ['#title']).join(','), paintLengths);
	function value(selectors) { for (var i = 0; i < selectors.length; i++) { var el = document.querySelector(selectors[i]); if (el && typeof el.value === 'string') { return el.value; } } return ''; }
	function edAttr(name) { try { return wp.data.select('core/editor').getEditedPostAttribute(name) || ''; } catch (e) { return ''; } }
	function edContent() {
		try { if (window.wp && wp.data && wp.data.select('core/editor')) { return wp.data.select('core/editor').getEditedPostContent() || ''; } } catch (e) {}
		try { var mce = window.tinymce && tinymce.get('content'); if (mce && !mce.isHidden()) { return mce.getContent(); } } catch (e) {}
		return value(['#content']);
	}
	function kpFields() {
		function list(key) { var el = document.getElementById(key); return el && typeof el.value === 'string' ? el.value : (Array.isArray(edMeta(key)) ? edMeta(key).join('\n') : ''); }
		return { secondary: list('_publishrank_seo_secondary_keyphrases'), synonyms: list('_publishrank_seo_keyphrase_synonyms'), related: list('_publishrank_seo_related_terms') };
	}
	function standalone() {
		var card = document.getElementById('publishrank-editor-score');
		if (!card || !window.publishrankIntel) { return; }
		$.post(publishrankIntel.ajaxUrl, $.extend({
			action: publishrankIntel.action, nonce: publishrankIntel.nonce, post_id: card.getAttribute('data-post-id') || 0,
			title: edAttr('title') || value(['#title']), content: edContent(), excerpt: edAttr('excerpt') || value(['#excerpt']),
			slug: edAttr('slug') || value(['#post_name']),
			seo_title: value(['#publishrank_seo_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]']),
			description: value(['#publishrank_meta_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]']),
			keyphrase: value(['#publishrank_focus_keyword', '[name="publishrank_focus_keyword"]', '#_publishrank_seo_focus_keyword', '[name="_publishrank_seo_focus_keyword"]']) || edMeta('_publishrank_seo_focus_keyword'),
			has_thumbnail: !!(edAttr('featured_media') || document.querySelector('#postimagediv .inside img'))
		}, kpFields()));
	}
	function analyzeNow() {
		var btn = document.getElementById('pr-intel-refresh');
		if (btn && !btn.disabled) { btn.click(); } else if (!btn) { standalone(); }
	}

	/* Live updates in the block editor: the meta box only listens to classic inputs. */
	var liveTimer = 0, lastSig = null;
	function scheduleLive() { window.clearTimeout(liveTimer); liveTimer = window.setTimeout(analyzeNow, 1500); }
	function watchBlockEditor() {
		if (!(window.wp && wp.data && wp.data.subscribe)) { return; }
		wp.data.subscribe(function () {
			var ed; try { ed = wp.data.select('core/editor'); } catch (e) { return; }
			if (!ed || !ed.getEditedPostAttribute) { return; }
			var sig;
			try { sig = [ed.getEditedPostAttribute('title'), ed.getEditedPostContent(), ed.getEditedPostAttribute('excerpt'), ed.getEditedPostAttribute('slug'), ed.getEditedPostAttribute('featured_media'), JSON.stringify(ed.getEditedPostAttribute('meta') || {})].join('\u0001'); } catch (e) { return; }
			if (lastSig === null) { lastSig = sig; return; }
			if (sig !== lastSig) { lastSig = sig; scheduleLive(); }
		});
	}

	/* ---------- one-click fixes ---------- */
	var META_KEYS = { keyphrase: '_publishrank_seo_focus_keyword', description: '_publishrank_seo_description', seo_title: '_publishrank_seo_title' };
	function postId() { var c = document.getElementById('publishrank-editor-score'); return c ? (c.getAttribute('data-post-id') || 0) : 0; }
	function payload(extra) {
		var d = {
			post_id: postId(), title: edAttr('title') || value(['#title']), content: edContent(), excerpt: edAttr('excerpt') || value(['#excerpt']),
			slug: edAttr('slug') || value(['#post_name']),
			seo_title: value(['#publishrank_seo_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]']),
			description: value(['#publishrank_meta_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]']),
			keyphrase: value(['#publishrank_focus_keyword', '[name="publishrank_focus_keyword"]', '#_publishrank_seo_focus_keyword', '[name="_publishrank_seo_focus_keyword"]']) || edMeta('_publishrank_seo_focus_keyword')
		};
		return $.extend(d, kpFields(), extra || {});
	}
	function suggest(kind) {
		return $.post(publishrankIntel.ajaxUrl, payload({ action: publishrankIntel.suggestAction, nonce: publishrankIntel.suggestNonce, kind: kind }));
	}
	function setField(field, val) {
		var map = { keyphrase: ['#publishrank_focus_keyword', '[name="publishrank_focus_keyword"]', '#_publishrank_seo_focus_keyword'], description: ['#publishrank_meta_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '#pr-studio-description'], seo_title: ['#publishrank_seo_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '#pr-studio-headline'] };
		(map[field] || []).forEach(function (sel) { var el = document.querySelector(sel); if (el) { el.value = val; el.dispatchEvent(new Event('input', { bubbles: true })); } });
		try { if (META_KEYS[field] && wp.data.select('core/editor')) { var m = {}; m[META_KEYS[field]] = val; wp.data.dispatch('core/editor').editPost({ meta: m }); } } catch (e) {}
	}
	function saveField(field, val) {
		return $.post(publishrankIntel.ajaxUrl, { action: publishrankIntel.applyAction, nonce: publishrankIntel.applyNonce, post_id: postId(), field: field, value: val })
			.then(function (r) { if (!r || !r.success) { return $.Deferred().reject(); } setField(field, val); return r; });
	}
	function openReport(kind) {
		var box = document.getElementById('publishrank_article_intelligence');
		if (box && box.classList.contains('closed')) { var h = box.querySelector('.handlediv, .postbox-header button'); if (h) { h.click(); } else { box.classList.remove('closed'); } }
		var target = document.querySelector('.pr-studio__block[data-studio="' + kind + '"]') || box;
		if (target) { target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' }); var i = target.querySelector && target.querySelector('input,textarea'); if (i) { i.focus(); } }
	}
	function addLinks(btn) {
		if (!window.publishrankIntel || !window.PublishRankLinks) { openReport('links'); return $.Deferred().resolve(); }
		setStatus(t.linking || '');
		return suggest('links').then(function (r) {
			var items = r && r.success && r.data && Array.isArray(r.data.items) ? r.data.items : [];
			if (!items.length) { setStatus(t.noLinks || ''); return; }
			var res = window.PublishRankLinks.insert(items);
			setStatus(res.message);
			if (res.ok && (res.placed + res.related) > 0) { scheduleLive(); }
		}, function () { setStatus(t.fixFailed || ''); });
	}
	function runFix(kind, btn) {
		if (kind === 'links') { return addLinks(btn); }
		if (!window.publishrankIntel || ['description', 'headline', 'keyphrase'].indexOf(kind) === -1) { openReport(kind); return $.Deferred().resolve(); }
		setStatus(t.fixing || '');
		return suggest(kind).then(function (r) {
			var items = r && r.success && r.data ? r.data.items : null, val = '', field = kind === 'headline' ? 'seo_title' : kind;
			if (kind === 'keyphrase') { val = Array.isArray(items) && items[0] ? String(items[0].phrase || '') : ''; } else { val = items && items.text ? String(items.text) : ''; }
			if (!val) { setStatus(t.fixFailed || ''); openReport(kind); return; }
			return saveField(field, val).then(function () { setStatus(t.fixed || ''); analyzeNow(); }, function () { setStatus(t.fixFailed || ''); });
		}, function () { setStatus(t.fixFailed || ''); });
	}
	$(document).on('click', '[data-pr-esc-fix], [data-pr-esc-links]', function () {
		var btn = this, kind = btn.getAttribute('data-pr-esc-fix') || 'links';
		btn.disabled = true;
		$.when(runFix(kind, btn)).always(function () { btn.disabled = false; });
	});

	/* ---------- panel behaviour: minimize, drag, full report ---------- */
	function setMinimized(card, min, persist) {
		card.classList.toggle('is-minimized', min);
		var c = card.querySelector('[data-pr-esc-collapse]'), x = card.querySelector('[data-pr-esc-expand]');
		if (c) { c.setAttribute('aria-expanded', min ? 'false' : 'true'); }
		if (x) { x.setAttribute('aria-expanded', min ? 'false' : 'true'); }
		if (persist !== false) { try { window.localStorage.setItem(STORE_MIN, min ? '1' : '0'); } catch (e) {} }
	}

	/* ---------- placement: docked (sidebar) or floating ---------- */
	var dockSlot = null;
	function getCard() { return document.getElementById('publishrank-editor-score'); }
	function isBlockEditor() { return document.body.classList.contains('block-editor-page'); }
	function getMode() {
		var m = null; try { m = window.localStorage.getItem(STORE_MODE); } catch (e) {}
		return m === 'floating' ? 'floating' : 'docked';
	}
	function clearPosition(card) { card.style.left = card.style.top = card.style.right = card.style.bottom = ''; }
	function restoreFloating(card) {
		clearPosition(card);
		var min = false; try { min = window.localStorage.getItem(STORE_MIN) === '1'; } catch (e) {}
		setMinimized(card, min, false);
		var saved = null; try { saved = JSON.parse(window.localStorage.getItem(STORE_POS) || 'null'); } catch (e) {}
		if (saved && typeof saved.left === 'number' && typeof saved.top === 'number' && window.innerWidth > 782) {
			card.style.left = saved.left + 'px'; card.style.top = saved.top + 'px'; card.style.right = 'auto'; card.style.bottom = 'auto';
			window.requestAnimationFrame(function () { clampInto(card); });
		}
	}
	function applyMode() {
		var card = getCard();
		if (!card) { return; }
		var mode = getMode(), wasFloating = card.classList.contains('is-floating');
		card.classList.toggle('is-floating', mode === 'floating');
		card.classList.toggle('is-docked', mode === 'docked' && !!dockSlot);
		card.classList.toggle('is-parked', mode === 'docked' && !dockSlot);
		if (mode === 'docked' && dockSlot) {
			if (card.parentNode !== dockSlot) { dockSlot.appendChild(card); }
			clearPosition(card);
			setMinimized(card, false, false);
		} else {
			if (card.parentNode !== document.body) { document.body.appendChild(card); }
			if (mode === 'floating' && !wasFloating) { restoreFloating(card); }
		}
		var btn = card.querySelector('[data-pr-esc-mode]');
		if (btn) {
			var label = mode === 'floating' ? (t.dock || 'Dock to the sidebar') : (t.popOut || 'Pop out as a floating panel');
			btn.setAttribute('aria-label', label); btn.setAttribute('title', label);
		}
		emit('publishrank:mode', { mode: mode, docked: card.classList.contains('is-docked') });
	}
	function setMode(mode) {
		try { window.localStorage.setItem(STORE_MODE, mode === 'floating' ? 'floating' : 'docked'); } catch (e) {}
		applyMode();
		/* Docking from the block editor with its sidebar closed: ask the sidebar to open and take the panel. */
		if (mode === 'docked' && !dockSlot && isBlockEditor()) { emit('publishrank:open-sidebar', {}); }
		var card = getCard();
		var focusEl = card && card.querySelector('[data-pr-esc-mode]');
		if (focusEl && !card.classList.contains('is-parked')) { window.requestAnimationFrame(function () { focusEl.focus(); }); }
	}
	/* Public hooks used by the block editor sidebar (block-editor-sidebar.js). */
	window.PublishRankScoreCard = {
		mode: getMode,
		setMode: setMode,
		dock: function (slot) { if (slot) { dockSlot = slot; applyMode(); } },
		release: function (slot) { if (!slot || dockSlot === slot) { dockSlot = null; applyMode(); } },
		score: function () {
			var c = getCard(), n = c ? Math.max(0, Math.min(100, parseInt(c.getAttribute('data-score'), 10) || 0)) : 0;
			return { score: n, band: band(n), label: t[LABEL[band(n)]] || '' };
		},
		analyze: function () { busy(); analyzeNow(); }
	};
	function classicSlot() {
		if (isBlockEditor()) { return null; }
		var side = document.getElementById('postbox-container-1');
		if (!side) { return null; }
		var slot = document.createElement('div');
		slot.className = 'pr-esc-dock pr-esc-dock--classic';
		/* Directly under the Publish box so the Publish button is never pushed out of view. */
		var publish = document.getElementById('submitdiv');
		if (publish && side.contains(publish)) { publish.parentNode.insertBefore(slot, publish.nextSibling); } else { side.insertBefore(slot, side.firstChild); }
		return slot;
	}

	function clampInto(card) {
		var rect = card.getBoundingClientRect();
		var left = Math.max(0, Math.min(window.innerWidth - rect.width, rect.left)), top = Math.max(0, Math.min(window.innerHeight - rect.height, rect.top));
		card.style.left = left + 'px'; card.style.top = top + 'px'; card.style.right = 'auto'; card.style.bottom = 'auto';
	}

	$(function () {
		var card = document.getElementById('publishrank-editor-score');
		if (!card) { return; }
		if (!dockSlot) { dockSlot = classicSlot(); }
		applyMode();

		card.addEventListener('click', function (e) {
			if (e.target.closest('[data-pr-esc-expand]')) { setMinimized(card, false); var r = card.querySelector('[data-pr-esc-refresh]'); if (r) { r.focus(); } return; }
			if (e.target.closest('[data-pr-esc-collapse]')) { setMinimized(card, true); var p = card.querySelector('[data-pr-esc-expand]'); if (p) { p.focus(); } return; }
			if (e.target.closest('[data-pr-esc-refresh]')) { e.preventDefault(); busy(); analyzeNow(); return; }
			if (e.target.closest('[data-pr-esc-mode]')) { e.preventDefault(); setMode(getMode() === 'floating' ? 'docked' : 'floating'); return; }
			var link = e.target.closest('.pr-esc__report');
			if (link) {
				var box = document.getElementById('publishrank_article_intelligence');
				if (box) {
					e.preventDefault();
					box.classList.remove('closed');
					box.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
				}
			}
		});

		/* Drag by the header (desktop only). */
		var head = card.querySelector('[data-pr-esc-drag]'), drag = null;
		if (head) {
			head.addEventListener('pointerdown', function (e) {
				if (!card.classList.contains('is-floating') || window.innerWidth <= 782 || (e.button !== undefined && e.button !== 0) || e.target.closest('button,a')) { return; }
				var rect = card.getBoundingClientRect();
				drag = { x: e.clientX, y: e.clientY, left: rect.left, top: rect.top };
				card.classList.add('is-dragging');
				if (head.setPointerCapture) { head.setPointerCapture(e.pointerId); }
				e.preventDefault();
			});
			head.addEventListener('pointermove', function (e) {
				if (!drag) { return; }
				var w = card.offsetWidth, h = card.offsetHeight;
				card.style.left = Math.max(0, Math.min(window.innerWidth - w, drag.left + e.clientX - drag.x)) + 'px';
				card.style.top = Math.max(0, Math.min(window.innerHeight - h, drag.top + e.clientY - drag.y)) + 'px';
				card.style.right = 'auto'; card.style.bottom = 'auto';
			});
			var end = function () {
				if (!drag) { return; }
				drag = null; card.classList.remove('is-dragging');
				var rect = card.getBoundingClientRect();
				try { window.localStorage.setItem(STORE_POS, JSON.stringify({ left: Math.round(rect.left), top: Math.round(rect.top) })); } catch (e) {}
			};
			head.addEventListener('pointerup', end); head.addEventListener('pointercancel', end);
			head.addEventListener('dblclick', function (e) {
				if (!card.classList.contains('is-floating') || e.target.closest('button,a')) { return; }
				card.style.left = card.style.top = card.style.right = card.style.bottom = '';
				try { window.localStorage.removeItem(STORE_POS); } catch (err) {}
			});
		}
		window.addEventListener('resize', function () { if (card.classList.contains('is-floating') && card.style.left) { clampInto(card); } });

		watchBlockEditor();
		/* No meta box on the page (hidden by a filter or post type): run one analysis so the panel is current. */
		if (!document.getElementById('pr-intel')) { window.setTimeout(standalone, 800); }
	});
}(jQuery));
