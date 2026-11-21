/* PublishRank Schema Studio. Vanilla JS app on top of publishrank/v1/schema-studio.
   Everything coming from the server is rendered with textContent / DOM nodes (no innerHTML with data). */
(function () {
	'use strict';
	var C = window.publishrankSchemaStudio;
	if (!C) { return; }
	var T = C.i18n || {};
	var S = { mode: (function () { try { return localStorage.getItem('publishrank-ss-mode') || 'basic'; } catch (e) { return 'basic'; } })(), typeFilter: '', googleOnly: false, collapsed: {}, templates: [], settings: {}, fields: null, catalog: null, typeCache: {}, current: null, saved: null, dirty: false, sample: null, samples: [], preview: null, previewTab: 'readable', seq: 0, timer: null };

	/* ---------- helpers ---------- */
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) { Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'text') { n.textContent = v; } else if (k === 'class') { n.className = v; } else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), v); } else if (k === 'value') { n.value = v; } else if (k === 'checked') { n.checked = !!v; } else { n.setAttribute(k, v === true ? '' : v); }
		}); }
		(kids || []).forEach(function (c) { if (c === null || c === undefined || c === false) { return; } n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } return n; }
	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', headers: { 'X-WP-Nonce': C.nonce }, credentials: 'same-origin' };
		if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(C.root + path, init).then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error((j && j.message) || ('HTTP ' + r.status)); } return j; }); });
	}
	function toast(msg, err) { var t = document.getElementById('pr-ss-toast'); t.textContent = msg; t.className = 'pr-ds-toast' + (err ? ' pr-ds-toast--err' : ''); t.hidden = false; clearTimeout(t._h); t._h = setTimeout(function () { t.hidden = true; }, 3500); }
	function panel(id) { return document.getElementById('pr-ss-panel-' + id); }
	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	function badge(text, kind) { return el('span', { class: 'pr-ds-badge' + (kind ? ' pr-ds-badge--' + kind : ''), text: text }); }
	function blankProp(name) { return { name: name || '', source: 'static', value: '', field: '', fallback: '', format: '', required: false, type: '', props: [], items: [] }; }
	function blankTemplate(type) { return { id: '', name: '', description: '', type: type || 'Thing', status: 'disabled', priority: 10, is_default: false, mode: 'merge', main_entity: true, replace_article: false, conditions: { post_types: [], posts: [], exclude: [], taxonomies: [], archives: [], front: false, business_types: [] }, properties: [] }; }
	function typeLabel(t) { var c = S.catalog && S.catalog.byType[t]; return c ? c.label : t; }
	function loadType(t) { if (S.typeCache[t]) { return Promise.resolve(S.typeCache[t]); } return api('/type/' + encodeURIComponent(t)).then(function (d) { S.typeCache[t] = d; return d; }); }
	function propDef(info, name) { var out = null; (info.groups || []).forEach(function (g) { g.properties.forEach(function (p) { if (p.name === name) { out = p; } }); }); return out; }
	var DATATYPES = ['Text', 'URL', 'Date', 'DateTime', 'Time', 'Number', 'Integer', 'Float', 'Boolean', 'CssSelectorType', 'XPathType', 'PronounceableText'];
	function classRanges(def) { return (def && def.ranges || []).filter(function (r) { return DATATYPES.indexOf(r) < 0; }); }

	/* ---------- guided editing helpers (2.26.1) ---------- */
	function isAdvanced() { return S.mode === 'advanced'; }
	function setMode(m) { S.mode = m; try { localStorage.setItem('publishrank-ss-mode', m); } catch (e) {} renderEditor(); }
	/* Example values shown as placeholders, by expected Schema.org value type. */
	function exampleFor(def, name) {
		var r = (def && def.ranges) || [];
		if (/duration|Time$/.test(name) && r.indexOf('Duration') >= 0) { return 'PT1H30M'; }
		if (r.indexOf('URL') >= 0) { return 'https://example.com/page'; }
		if (r.indexOf('DateTime') >= 0) { return '2026-05-01T09:00:00+00:00'; }
		if (r.indexOf('Date') >= 0) { return '2026-05-01'; }
		if (r.indexOf('Integer') >= 0) { return '12'; }
		if (r.indexOf('Number') >= 0 || r.indexOf('Float') >= 0) { return '4.5'; }
		if (r.indexOf('Boolean') >= 0) { return 'true or false'; }
		if (/^gtin/.test(name)) { return '00012345600012'; }
		if (r.indexOf('Duration') >= 0) { return 'PT30M'; }
		return 'Text, or {{post.title}} style dynamic values';
	}
	function gtinOk(v) { if (!/^\d{8}$|^\d{12,14}$/.test(v)) { return false; } var d = v.split('').map(Number), c = d.pop(), sum = 0; d.reverse().forEach(function (n, i) { sum += n * (i % 2 ? 1 : 3); }); return (10 - (sum % 10)) % 10 === c; }
	/* Contextual validation of a fixed value. Returns '' when fine. Dynamic {{tokens}} are checked on the preview. */
	function checkValue(def, name, v) {
		v = String(v || '').trim(); if (!v || /\{\{[^}]+\}\}/.test(v)) { return ''; }
		var r = (def && def.ranges) || [];
		if (def && def.enum && def.enum.length) { return ''; }
		var only = function (t) { return r.length && r.every(function (x) { return t.indexOf(x) >= 0; }); };
		if (only(['URL']) && !/^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(v)) { return 'Expected an absolute URL starting with https:// or http://'; }
		if (only(['Date']) && !/^\d{4}-\d{2}-\d{2}$/.test(v)) { return 'Expected a date like 2026-05-01 (ISO 8601)'; }
		if (only(['DateTime', 'Date']) && !/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/.test(v)) { return 'Expected an ISO 8601 date or date-time like 2026-05-01T09:00:00+00:00'; }
		if (only(['Integer']) && !/^-?\d+$/.test(v)) { return 'Expected a whole number'; }
		if (only(['Number', 'Integer', 'Float']) && !/^-?\d+(\.\d+)?$/.test(v)) { return 'Expected a number (use a dot for decimals)'; }
		if (only(['Boolean']) && !/^(true|false)$/i.test(v)) { return 'Expected true or false'; }
		if (only(['Duration']) && !/^P(?!$)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(\.\d+)?S)?)?$/.test(v)) { return 'Expected an ISO 8601 duration like PT1H30M'; }
		if (/^gtin(8|12|13|14)?$/.test(name) && !gtinOk(v.replace(/\s|-/g, ''))) { return 'This GTIN has the wrong length or check digit'; }
		if (r.indexOf('URL') >= 0 && /^www\./i.test(v)) { return 'Add https:// in front of the address'; }
		return '';
	}
	/* Sensible dynamic source for common properties, used by "Add required & recommended". */
	var AUTO_MAP = { name: 'post.title', headline: 'post.title', description: 'post.description', url: 'post.permalink', image: 'post.featured_image', thumbnailUrl: 'post.featured_image', datePublished: 'post.date_published', dateModified: 'post.date_modified', uploadDate: 'post.date_published', inLanguage: 'post.language', keywords: 'post.tags', articleSection: 'post.primary_category', wordCount: 'post.word_count', commentCount: 'post.comment_count' };
	function autoProp(name, def, g) {
		var np = blankProp(name), f = AUTO_MAP[name];
		if (f && S.fields && S.fields.fields.some(function (x) { return x.key === f; })) { np.source = 'dynamic'; np.field = f; }
		else if (def && classRanges(def).length && !DATATYPES.some(function (d) { return def.ranges.indexOf(d) >= 0; }) && !(def.enum && def.enum.length)) { np.source = 'object'; np.type = classRanges(def)[0]; }
		np.required = (g.req || []).indexOf(name) >= 0;
		return np;
	}
	/* Six guided steps; each reports done / attention so the editor reads top to bottom. */
	function stepState(t, info) {
		var g = (info && info.google) || { req: [], rec: [] }, names = t.properties.map(function (p) { return p.name; });
		var missingReq = (g.req || []).filter(function (r) { return names.indexOf(r) < 0; });
		var empty = t.properties.filter(function (p) { return p.source === 'static' && !String(p.value || '').trim() || (p.source === 'dynamic' || p.source === 'ref') && !p.field; }).length;
		var bad = t.properties.filter(function (p) { return p.source === 'static' && checkValue(propDef(info, p.name), p.name, p.value); }).length;
		var c = t.conditions, targeted = c.post_types.length || c.posts.length || c.archives.length || c.front || c.taxonomies.length;
		var pv = S.preview, errs = pv ? (pv.node_issues || []).filter(function (i) { return i.level === 'error'; }).length : -1;
		return [
			{ id: 'type', label: 'Choose type', ok: !!t.type && t.type !== 'Thing', hint: t.type },
			{ id: 'props', label: 'Select properties', ok: t.properties.length > 0 && !missingReq.length, hint: missingReq.length ? 'Google required: ' + missingReq.join(', ') : t.properties.length + ' properties' },
			{ id: 'values', label: 'Fill in values', ok: t.properties.length > 0 && !empty && !bad, hint: bad ? bad + ' invalid value(s)' : (empty ? empty + ' empty' : 'All set') },
			{ id: 'rules', label: 'Where it applies', ok: !!targeted, hint: targeted ? targetSummary(t) : 'Not targeted yet' },
			{ id: 'preview', label: 'Preview & validate', ok: errs === 0 && !(pv && pv.skipped), hint: errs < 0 ? 'Waiting for preview' : (pv && pv.skipped ? 'Not printed on the sample' : (errs ? errs + ' error(s)' : 'No Schema.org errors')) },
			{ id: 'save', label: 'Save & publish', ok: !S.dirty && !!t.id && t.status === 'enabled', hint: S.dirty ? 'Unsaved changes' : (t.status === 'enabled' ? 'Live on matching pages' : 'Saved as draft') }
		];
	}
	var STEP_TARGET = { type: 'pr-ss-card-basics', props: 'pr-ss-card-props', values: 'pr-ss-card-props', rules: 'pr-ss-card-rules', preview: 'pr-ss-pv', save: 'pr-ss-dirty' };
	function drawSteps() {
		var host = document.getElementById('pr-ss-steps'); if (!host || !S.current) { return; }
		clear(host);
		stepState(S.current, S.typeCache[S.current.type]).forEach(function (st, i) {
			host.appendChild(el('li', { class: 'pr-ss-step' + (st.ok ? ' is-done' : ' is-todo') }, [el('button', { type: 'button', class: 'pr-ss-step__btn', 'aria-describedby': 'pr-ss-step-h-' + st.id, onclick: function () { var n = document.getElementById(STEP_TARGET[st.id]); if (n) { n.scrollIntoView({ behavior: 'smooth', block: 'start' }); var f = n.querySelector('input,select,button'); if (f) { f.focus({ preventScroll: true }); } } } }, [
				el('span', { class: 'pr-ss-step__n', 'aria-hidden': 'true', text: st.ok ? '✓' : String(i + 1) }),
				el('span', { class: 'pr-ss-step__label', text: st.label }),
				el('span', { class: 'pr-ss-step__hint', id: 'pr-ss-step-h-' + st.id, text: st.hint || '' }),
				el('span', { class: 'pr-ds-sr', text: st.ok ? ' (done)' : ' (needs attention)' })
			])]));
		});
	}
	function copyText(text, btn) {
		var done = function () { var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = o; }, 1500); };
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done, function () { fallback(); }); } else { fallback(); }
		function fallback() { var ta = el('textarea', { class: 'pr-ds-sr' }); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { toast('Copy failed: select the code and copy it manually', true); } ta.remove(); }
	}
	/* Human-readable view of the generated entities. */
	function readable(graph, result) {
		var hl = {}; (result || []).forEach(function (r) { if (r.target) { hl[r.target] = true; } });
		var wrap = el('div', { class: 'pr-ss-readable' });
		function val(v, depth) {
			if (v === null || v === undefined) { return el('span', { class: 'pr-ds-help', text: '—' }); }
			if (Array.isArray(v)) { return el('ol', { class: 'pr-ss-readable__list' }, v.map(function (x) { return el('li', null, [val(x, depth + 1)]); })); }
			if (typeof v === 'object') {
				if (v['@id'] && Object.keys(v).length === 1) { return el('span', { class: 'pr-ss-readable__ref' }, ['→ ', el('code', { text: v['@id'] })]); }
				var dl = el('dl', { class: 'pr-ss-readable__obj' });
				if (v['@type']) { dl.appendChild(el('dt', { text: 'Type' })); dl.appendChild(el('dd', null, [el('code', { text: [].concat(v['@type']).join(', ') })])); }
				Object.keys(v).forEach(function (k) { if (k.charAt(0) === '@') { return; } dl.appendChild(el('dt', { text: k })); dl.appendChild(el('dd', null, [depth > 4 ? el('span', { text: '…' }) : val(v[k], depth + 1)])); });
				return dl;
			}
			var str = String(v);
			return /^https?:\/\//.test(str) ? el('a', { href: str, target: '_blank', rel: 'noopener noreferrer', text: str }) : el('span', { text: str });
		}
		(graph || []).forEach(function (n) {
			var t = [].concat(n['@type'] || '?').join(' / ');
			wrap.appendChild(el('details', { class: 'pr-ss-readable__node' + (hl[n['@id']] ? ' is-template' : ''), open: hl[n['@id']] ? true : null }, [
				el('summary', null, [el('strong', { text: t }), ' ', el('span', { class: 'pr-ds-help', text: typeof n.name === 'string' ? n.name : (typeof n.headline === 'string' ? n.headline : (n['@id'] || '')) })]),
				n['@id'] ? el('p', { class: 'pr-ds-help' }, ['@id ', el('code', { text: n['@id'] })]) : null,
				val(n, 0)
			]));
		});
		if (!wrap.firstChild) { wrap.appendChild(el('p', { class: 'pr-ds-help', text: 'Nothing to show for this sample.' })); }
		return wrap;
	}
	/* Three separate verdicts: Schema.org validity, Google's documented requirements, optional recommendations. */
	function verdicts(d) {
		var issues = d.node_issues || [], errs = issues.filter(function (i) { return i.level === 'error'; }).length, warns = issues.filter(function (i) { return i.level === 'warning'; }).length;
		var mine = S.current ? S.current.type : '';
		var el2 = (d.eligibility || []).filter(function (e) { return !mine || e.type === mine || String(e.type).split('/').indexOf(mine) >= 0; });
		if (d.skipped) {
			return el('div', { class: 'pr-ss-verdicts', role: 'status' }, [
				el('div', { class: 'pr-ss-verdict pr-ss-verdict--err' }, [el('strong', { text: 'Not printed on this sample' }), el('span', { text: d.skipped })]),
				el('div', { class: 'pr-ss-verdict pr-ss-verdict--info' }, [el('strong', { text: 'Google rich-result requirements' }), el('span', { text: 'Not evaluated until the entity is printed. Fill the required properties or pick another sample page.' })])
			]);
		}
		var incomplete = el2.filter(function (e) { return e.status === 'incomplete'; }), rec = 0; el2.forEach(function (e) { rec += (e.recommended || []).length; });
		var g = !el2.length ? { k: '', t: 'No Google Search feature documented for these entities' } : (incomplete.length ? { k: 'err', t: 'Google requirements: missing ' + incomplete.map(function (e) { return e.missing.join(', '); }).join('; ') } : { k: 'ok', t: 'Meets Google\'s documented required properties (Google still decides whether a rich result appears)' });
		return el('div', { class: 'pr-ss-verdicts', role: 'status' }, [
			el('div', { class: 'pr-ss-verdict pr-ss-verdict--' + (errs ? 'err' : (warns ? 'warn' : 'ok')) }, [el('strong', { text: 'Schema.org validity' }), el('span', { text: errs ? errs + ' error(s), ' + warns + ' warning(s)' : (warns ? 'Valid with ' + warns + ' warning(s)' : 'Valid vocabulary, types and nesting') })]),
			el('div', { class: 'pr-ss-verdict pr-ss-verdict--' + (g.k || 'info') }, [el('strong', { text: 'Google rich-result requirements' }), el('span', { text: g.t })]),
			el('div', { class: 'pr-ss-verdict pr-ss-verdict--' + (rec ? 'warn' : 'ok') }, [el('strong', { text: 'Optional recommended properties' }), el('span', { text: rec ? rec + ' recommended propert' + (rec === 1 ? 'y is' : 'ies are') + ' not set' : 'All documented recommendations are set' })])
		]);
	}

	/* ---------- tabs ---------- */
	var tabs = Array.prototype.slice.call(document.querySelectorAll('.pr-ss .pr-ds-tab'));
	function show(id, push) {
		tabs.forEach(function (b) { var on = b.getAttribute('data-tab') === id; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.tabIndex = on ? 0 : -1; panel(b.getAttribute('data-tab')).hidden = !on; });
		if (push !== false && history.replaceState) { history.replaceState(null, '', '#' + id); }
		if (id === 'templates') { renderTemplates(); }
		if (id === 'editor') { renderEditor(); }
		if (id === 'diagnostics') { renderDiagnostics(); }
		if (id === 'catalog') { renderCatalog(); }
		if (id === 'transfer') { renderTransfer(); }
		if (id === 'settings') { renderSettings(); }
	}
	tabs.forEach(function (b, i) {
		b.addEventListener('click', function () { if (b.getAttribute('data-tab') !== 'editor' && S.dirty && !confirm(T.confirmDiscard)) { return; } if (b.getAttribute('data-tab') !== 'editor') { S.dirty = false; } show(b.getAttribute('data-tab')); });
		b.addEventListener('keydown', function (e) { var d = e.key === 'ArrowRight' ? 1 : (e.key === 'ArrowLeft' ? -1 : 0); if (!d) { return; } var n = tabs[(i + d + tabs.length) % tabs.length]; n.focus(); n.click(); });
	});
	window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

	/* ---------- templates list ---------- */
	function targetSummary(t) {
		var c = t.conditions, parts = [];
		if (c.post_types.length) { parts.push(c.post_types.join(', ')); }
		if (c.archives.length) { parts.push('archives: ' + c.archives.join(', ')); }
		if (c.front) { parts.push('front page'); }
		if (c.posts.length) { parts.push(c.posts.length + ' item(s)'); }
		if (c.business_types.length) { parts.push('business: ' + c.business_types.join(', ')); }
		return parts.length ? parts.join(' · ') : 'Assigned per item only';
	}
	function renderTemplates() {
		var p = clear(panel('templates'));
		var head = el('div', { class: 'pr-ds-card__head' }, [
			el('div', null, [el('h2', { class: 'pr-ds-card__title', text: 'Templates' }), el('p', { class: 'pr-ds-card__sub', text: 'Built-in templates ship switched off, so existing output never changes until you enable one.' })]),
			el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'New template', onclick: function () { chooseType(function (type) { openEditor(blankTemplate(type)); }); } })
		]);
		p.appendChild(head);
		if (!S.templates.length) { p.appendChild(el('div', { class: 'pr-ds-empty' }, [el('strong', { text: 'No templates yet' }), 'Create one from any Schema.org type.'])); return; }
		var tbody = el('tbody');
		S.templates.forEach(function (t) {
			var sw = el('input', { type: 'checkbox', checked: t.status === 'enabled', 'aria-label': 'Enable ' + t.name, onchange: function () { api('/templates/' + t.id + '/status', { method: 'POST', body: { status: sw.checked ? 'enabled' : 'disabled' } }).then(function (r) { Object.assign(t, r); toast(t.name + ': ' + (sw.checked ? 'enabled' : 'disabled')); }).catch(function (e) { sw.checked = !sw.checked; toast(e.message, true); }); } });
			var g = S.catalog && S.catalog.byType[t.type] && S.catalog.byType[t.type].google;
			tbody.appendChild(el('tr', null, [
				el('td', null, [el('strong', { text: t.name }), t.builtin ? ' ' : '', t.builtin ? badge('built-in') : '', t.is_default ? ' ' : '', t.is_default ? badge('default', 'brand') : '', el('div', { class: 'pr-ds-help', text: t.description || '' })]),
				el('td', null, [el('code', { text: t.type }), g && g.retired ? ' ' : '', g && g.retired ? badge('Google feature retired', 'warn') : '']),
				el('td', { text: targetSummary(t) }),
				el('td', { text: String(t.priority) }),
				el('td', { text: t.mode }),
				el('td', null, [el('label', { class: 'pr-ds-switch' }, [sw, el('span', { class: 'pr-ds-switch__track' }), el('span', { class: 'pr-ds-sr', text: 'Enabled' })])]),
				el('td', null, [el('div', { class: 'pr-ds-btn-row' }, [
					el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Edit', onclick: function () { openEditor(clone(t)); } }),
					el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Duplicate', onclick: function () { api('/templates/' + t.id + '/duplicate', { method: 'POST' }).then(function (r) { toast('Duplicated as ' + r.name); reload(); }).catch(function (e) { toast(e.message, true); }); } }),
					el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: t.is_default ? 'Unset default' : 'Set default', onclick: function () { api('/templates/' + t.id + '/default', { method: 'POST', body: { value: !t.is_default } }).then(function () { reload(); }).catch(function (e) { toast(e.message, true); }); } }),
					el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Export', onclick: function () { api('/export?ids=' + t.id).then(function (d) { download(d, 'publishrank-schema-' + t.id + '.json'); }); } }),
					t.builtin ? el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Reset', onclick: function () { if (!confirm('Reset this built-in template to its shipped version?')) { return; } api('/templates/' + t.id + '/reset', { method: 'POST' }).then(function () { toast('Reset to defaults'); reload(); }); } })
						: el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--danger', text: 'Delete', onclick: function () { if (!confirm(T.confirmDelete)) { return; } api('/templates/' + t.id, { method: 'DELETE' }).then(function () { toast('Deleted'); reload(); }).catch(function (e) { toast(e.message, true); }); } })
				])])
			]));
		});
		p.appendChild(el('div', { class: 'pr-ds-table-wrap' }, [el('table', { class: 'pr-ds-table pr-ss-list' }, [el('thead', null, [el('tr', null, ['Template', 'Type', 'Applies to', 'Priority', 'Mode', 'On', 'Actions'].map(function (h) { return el('th', { scope: 'col', text: h }); }))]), tbody])]));
	}
	function download(data, name) { var a = el('a', { href: URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })), download: name }); document.body.appendChild(a); a.click(); a.remove(); }
	function reload() { return api('/templates').then(function (d) { S.templates = d.templates; S.settings = d.settings; if (!panel('templates').hidden) { renderTemplates(); } }); }

	/* ---------- type chooser ---------- */
	function chooseType(cb) {
		var modal = el('div', { class: 'pr-ds-modal', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'pr-ss-ct' });
		var list = el('div', { class: 'pr-ss-typelist' });
		var search = el('input', { type: 'search', placeholder: 'Search ' + S.catalog.types.length + ' Schema.org types by name or description…', 'aria-label': 'Search types' });
		var cats = []; S.catalog.types.forEach(function (t) { if (cats.indexOf(t.group) < 0) { cats.push(t.group); } });
		var chips = el('div', { class: 'pr-ss-catchips', role: 'group', 'aria-label': 'Type categories' });
		function chip(label, value) { return el('button', { type: 'button', class: 'pr-ss-catchip', 'aria-pressed': S.typeFilter === value ? 'true' : 'false', text: label, onclick: function () { S.typeFilter = value; Array.prototype.forEach.call(chips.children, function (b) { b.setAttribute('aria-pressed', b === this ? 'true' : 'false'); }, this); draw(); } }); }
		chips.appendChild(chip('All', '')); cats.forEach(function (g) { chips.appendChild(chip(g, g)); });
		var gOnly = el('input', { type: 'checkbox', checked: S.googleOnly }); gOnly.addEventListener('change', function () { S.googleOnly = gOnly.checked; draw(); });
		var count = el('p', { class: 'pr-ds-help', role: 'status', 'aria-live': 'polite' });
		function close() { modal.remove(); }
		function draw() {
			clear(list); var q = search.value.toLowerCase(), groups = {}, n = 0;
			var rank = function (t) { var nm = (t.type + ' ' + t.label).toLowerCase(); return !q ? 0 : (t.type.toLowerCase() === q || t.label.toLowerCase() === q ? 0 : (nm.indexOf(q) === 0 ? 1 : (nm.indexOf(q) > 0 ? 2 : 3))); };
			var hits = [];
			S.catalog.types.forEach(function (t, i) { if (q && (t.type + ' ' + t.label + ' ' + t.group + ' ' + (t.comment || '')).toLowerCase().indexOf(q) < 0) { return; } if (S.typeFilter && t.group !== S.typeFilter) { return; } if (S.googleOnly && !(t.google && !t.google.retired)) { return; } hits.push({ t: t, r: rank(t), i: i }); });
			hits.sort(function (a, b) { return a.r - b.r || a.i - b.i; });
			hits.forEach(function (h) { n++; (groups[h.t.group] = groups[h.t.group] || []).push(h.t); });
			count.textContent = n + ' type' + (n === 1 ? '' : 's');
			Object.keys(groups).forEach(function (g) {
				list.appendChild(el('h3', { class: 'pr-ss-typelist__group', text: g }));
				groups[g].forEach(function (t) {
					list.appendChild(el('button', { type: 'button', class: 'pr-ss-typelist__item', onclick: function () { close(); cb(t.type); } }, [
						el('span', { class: 'pr-ss-typelist__name', text: t.label }), ' ', el('code', { text: t.type }), t.pending ? ' ' : '', t.pending ? badge('pending', 'info') : '', t.google && t.google.retired ? ' ' : '', t.google && t.google.retired ? badge('Google feature retired', 'warn') : (t.google && t.google.via === t.type ? ' ' : ''), t.google && !t.google.retired && t.google.via === t.type ? badge('Google: ' + t.google.feature, 'ok') : '',
						el('span', { class: 'pr-ds-help', text: String(t.comment || '').replace(/\\n/g, ' ') })
					]));
				});
			});
			if (!list.firstChild) { list.appendChild(el('p', { class: 'pr-ds-help', text: 'No matching type. Names that are not Schema.org types (for example JobPosition) are not offered.' })); }
		}
		search.addEventListener('input', draw);
		modal.appendChild(el('div', { class: 'pr-ds-modal__box pr-ss-chooser' }, [el('div', { class: 'pr-ds-modal__head' }, [el('h2', { id: 'pr-ss-ct', text: 'Choose a Schema.org type' }), el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--ghost', text: 'Close', onclick: close })]), el('p', { class: 'pr-ds-help', text: 'Step 1 of 6. Pick what this content is. Each type lists what it describes; a green badge means Google documents a search feature for it.' }), search, chips, el('label', { class: 'pr-ds-check' }, [gOnly, 'Only types with a documented Google Search feature']), count, list]));
		modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
		modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
		document.body.appendChild(modal); draw(); search.focus();
	}

	/* ---------- editor ---------- */
	function openEditor(tpl) { S.current = tpl; S.saved = clone(tpl); S.dirty = false; show('editor'); }
	function changed() { S.dirty = true; updateDirty(); schedulePreview(); drawSteps(); }
	function updateDirty() { var b = document.getElementById('pr-ss-dirty'); if (b) { b.textContent = S.dirty ? T.unsaved : T.saved; b.className = 'pr-ds-badge ' + (S.dirty ? 'pr-ds-badge--warn' : 'pr-ds-badge--ok'); } }

	function renderEditor() {
		var p = clear(panel('editor'));
		if (!S.current) { p.appendChild(el('div', { class: 'pr-ds-empty' }, [el('strong', { text: 'Nothing open' }), 'Pick a template on the Templates tab, or create a new one.', el('p', null, [el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'New template', onclick: function () { chooseType(function (t) { openEditor(blankTemplate(t)); }); } })])])); return; }
		var t = S.current;
		var left = el('div', { class: 'pr-ss-editor__main' }), right = el('aside', { class: 'pr-ss-editor__preview', 'aria-label': 'Live preview' });
		p.appendChild(el('div', { class: 'pr-ss-editor__bar' }, [
			el('div', null, [el('strong', { text: (t.name || 'Untitled template') + ' ' }), el('code', { text: t.type }), ' ', el('span', { id: 'pr-ss-dirty', class: 'pr-ds-badge pr-ds-badge--ok', text: T.saved })]),
			el('div', { class: 'pr-ds-btn-row' }, [
				el('button', { type: 'button', class: 'pr-ds-btn', text: 'Discard changes', onclick: function () { if (!S.dirty || confirm(T.confirmDiscard)) { S.current = clone(S.saved); S.dirty = false; renderEditor(); } } }),
				t.id ? el('button', { type: 'button', class: 'pr-ds-btn', text: 'Revisions', onclick: revisions }) : null,
				el('button', { type: 'button', class: 'pr-ds-btn', text: 'Save draft', onclick: function () { save(false); } }),
				el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: t.status === 'enabled' ? 'Save & keep live' : 'Save & publish', onclick: function () { save(true); } })
			])
		]));
		var modeSeg = el('div', { class: 'pr-ds-seg pr-ss-mode', role: 'group', 'aria-label': 'Editing mode' }, [['basic', 'Basic'], ['advanced', 'Advanced']].map(function (o) { return el('button', { type: 'button', 'aria-pressed': S.mode === o[0] ? 'true' : 'false', text: o[1], onclick: function () { if (S.mode !== o[0]) { setMode(o[0]); } } }); }));
		p.appendChild(el('div', { class: 'pr-ss-guide' }, [
			el('div', { class: 'pr-ss-guide__head' }, [el('p', { class: 'pr-ds-help', text: S.mode === 'basic' ? 'Basic mode shows the essentials. Switch to Advanced for output modes, priorities, links between entities, lists and taxonomy rules.' : 'Advanced mode shows every option, including entity links (@id), lists, value formats and fine-grained targeting.' }), modeSeg]),
			el('ol', { class: 'pr-ss-steps', id: 'pr-ss-steps', 'aria-label': 'Schema editing steps' })
		]));
		p.appendChild(el('div', { class: 'pr-ss-editor' }, [left, right]));
		loadType(t.type).then(function (info) {
			left.appendChild(basics(t, info));
			left.appendChild(rules(t));
			left.appendChild(properties(t, info));
			right.appendChild(previewPanel());
			updateDirty(); schedulePreview(0); drawSteps();
		}).catch(function (e) { left.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--err', text: e.message })); });
	}

	function field(label, control, help) { return el('div', { class: 'pr-ds-field' }, [el('label', null, [label, control]), help ? el('p', { class: 'pr-ds-help', text: help }) : null]); }
	function bind(obj, key, input, kind) {
		input.addEventListener(input.tagName === 'SELECT' || input.type === 'checkbox' ? 'change' : 'input', function () { obj[key] = input.type === 'checkbox' ? input.checked : (kind === 'int' ? parseInt(input.value || '0', 10) : input.value); changed(); });
		return input;
	}

	function basics(t, info) {
		var g = info.google;
		var box = el('div', { class: 'pr-ds-card', id: 'pr-ss-card-basics' }, [el('div', { class: 'pr-ds-card__head' }, [el('div', null, [el('h2', { class: 'pr-ds-card__title', text: '1. Type & basics' }), el('p', { class: 'pr-ds-card__sub', text: info.comment })])])]);
		var meta = el('div', { class: 'pr-ss-typemeta' }, [badge('Schema.org: ' + [info.type].concat(info.parents.slice(0, 4)).join(' › ')), info.pending ? badge('pending vocabulary', 'info') : null]);
		if (g) { meta.appendChild(badge(g.retired ? 'Google: ' + g.feature : 'Google documents: ' + g.feature, g.retired ? 'warn' : 'ok')); }
		else { meta.appendChild(badge('No Google rich result for this type', '')); }
		box.appendChild(meta);
		if (g && g.note) { box.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--warn', text: g.note })); }
		var grid = el('div', { class: 'pr-ds-grid pr-ds-grid--2' });
		grid.appendChild(field('Name', bind(t, 'name', el('input', { type: 'text', value: t.name, placeholder: 'e.g. Hospital department page' }))));
		var typeBtn = el('button', { type: 'button', class: 'pr-ds-btn', text: 'Change type (' + t.type + ')', onclick: function () { chooseType(function (nt) { if (nt === t.type) { return; } t.type = nt; changed(); renderEditor(); }); } });
		grid.appendChild(el('div', { class: 'pr-ds-field' }, [el('span', { class: 'pr-ds-label', text: 'Schema.org type' }), typeBtn]));
		var mode = el('select', null, [['merge', 'Merge into the matching entity (recommended)'], ['replace', 'Merge, template values win'], ['add', 'Always add a separate entity']].map(function (o) { return el('option', { value: o[0], text: o[1], selected: t.mode === o[0] }); }));
		if (isAdvanced()) {
			grid.appendChild(field('Output mode', bind(t, 'mode', mode), 'Merge enriches what PublishRank or WooCommerce already prints for this page instead of creating duplicates.'));
			grid.appendChild(field('Priority', bind(t, 'priority', el('input', { type: 'number', min: -100, max: 100, value: t.priority }), 'int'), 'When two templates of the same type match, the higher priority wins. The default template wins ties.'));
		}
		box.appendChild(grid);
		box.appendChild(field('Description', bind(t, 'description', el('input', { type: 'text', value: t.description, placeholder: 'What this template is for' }))));
		box.appendChild(el('p', { class: 'pr-ds-help pr-ss-idnote' }, ['Entity @id on each page: ', el('code', { text: '{page URL}#' + (t.id || 'template-id') }), '. Other templates and properties can link to it with "Link to entity" (Advanced mode); PublishRank\'s validator reports broken or duplicate @id references.']));
		box.appendChild(el('div', { hidden: !isAdvanced() }, [
			el('label', { class: 'pr-ds-check' }, [bind(t, 'main_entity', el('input', { type: 'checkbox', checked: t.main_entity })), 'Main entity of the page (connects WebPage.mainEntity)']),
			el('label', { class: 'pr-ds-check' }, [bind(t, 'replace_article', el('input', { type: 'checkbox', checked: !!t.replace_article })), 'Replaces the built-in article markup on matching pages (use for courses, events, jobs and other non-article content)']),
			el('label', { class: 'pr-ds-check' }, [bind(t, 'is_default', el('input', { type: 'checkbox', checked: t.is_default })), 'Default template for its type and post types'])
		]));
		box.appendChild(el('div', null, [
			el('label', { class: 'pr-ds-check' }, [(function () { var c = el('input', { type: 'checkbox', checked: t.status === 'enabled' }); c.addEventListener('change', function () { t.status = c.checked ? 'enabled' : 'disabled'; changed(); }); return c; })(), 'Enabled (printed on matching pages)'])
		]));
		return box;
	}

	function checks(list, selected, onToggle) {
		return el('div', { class: 'pr-ss-checks' }, list.map(function (o) { var c = el('input', { type: 'checkbox', value: o.name, checked: selected.indexOf(o.name) >= 0 }); c.addEventListener('change', function () { onToggle(o.name, c.checked); }); return el('label', { class: 'pr-ds-check' }, [c, o.label]); }));
	}
	function toggleIn(arr, v, on) { var i = arr.indexOf(v); if (on && i < 0) { arr.push(v); } if (!on && i >= 0) { arr.splice(i, 1); } changed(); }
	function idList(arr, input) { input.addEventListener('input', function () { arr.length = 0; input.value.split(/[\s,]+/).forEach(function (x) { var n = parseInt(x, 10); if (n > 0) { arr.push(n); } }); changed(); }); return input; }

	function rules(t) {
		var c = t.conditions, F = S.fields;
		var box = el('div', { class: 'pr-ds-card', id: 'pr-ss-card-rules' }, [el('div', { class: 'pr-ds-card__head' }, [el('div', null, [el('h2', { class: 'pr-ds-card__title', text: '4. Where it applies' }), el('p', { class: 'pr-ds-card__sub', text: 'A template applies to an item when its post type is selected and every taxonomy rule matches, or when the item is listed below. Editors can also assign or switch off templates per item in the post editor.' })])])]);
		box.appendChild(el('div', { class: 'pr-ds-label', text: 'Post types' }));
		box.appendChild(checks(F.post_types, c.post_types, function (v, on) { toggleIn(c.post_types, v, on); }));
		if (!isAdvanced()) {
			box.appendChild(el('label', { class: 'pr-ds-check' }, [bind(c, 'front', el('input', { type: 'checkbox', checked: c.front })), 'Front page']));
			var adv = (c.archives.length || c.taxonomies.length || c.posts.length || c.exclude.length || c.business_types.length);
			box.appendChild(el('p', { class: 'pr-ds-help' }, [adv ? 'This template also has archive, term, item or business rules. ' : '', el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', text: 'Show taxonomy, item and business rules (Advanced)', onclick: function () { setMode('advanced'); } })]));
			return box;
		}
		box.appendChild(el('div', { class: 'pr-ds-label', text: 'Taxonomy archives' }));
		box.appendChild(checks(F.taxonomies, c.archives, function (v, on) { toggleIn(c.archives, v, on); }));
		box.appendChild(el('label', { class: 'pr-ds-check' }, [bind(c, 'front', el('input', { type: 'checkbox', checked: c.front })), 'Front page']));
		var taxWrap = el('div', { class: 'pr-ss-taxrules' });
		function drawTax() {
			clear(taxWrap);
			c.taxonomies.forEach(function (r, i) {
				var sel = el('select', null, F.taxonomies.map(function (x) { return el('option', { value: x.name, text: x.label, selected: r.taxonomy === x.name }); }));
				sel.addEventListener('change', function () { r.taxonomy = sel.value; changed(); });
				var ids = idList(r.terms, el('input', { type: 'text', value: r.terms.join(', '), placeholder: 'Term IDs, e.g. 12, 15' }));
				taxWrap.appendChild(el('div', { class: 'pr-ss-row' }, [sel, ids, el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', text: 'Remove', onclick: function () { c.taxonomies.splice(i, 1); changed(); drawTax(); } })]));
			});
			taxWrap.appendChild(el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Add taxonomy rule', onclick: function () { c.taxonomies.push({ taxonomy: (F.taxonomies[0] || {}).name || 'category', terms: [] }); changed(); drawTax(); } }));
		}
		drawTax();
		box.appendChild(el('div', { class: 'pr-ds-label', text: 'Only items in these terms' }));
		box.appendChild(taxWrap);
		var grid = el('div', { class: 'pr-ds-grid pr-ds-grid--2' });
		grid.appendChild(field('Always apply to item IDs', idList(c.posts, el('input', { type: 'text', value: c.posts.join(', '), placeholder: 'e.g. 42, 108' }))));
		grid.appendChild(field('Never apply to item IDs', idList(c.exclude, el('input', { type: 'text', value: c.exclude.join(', ') }))));
		box.appendChild(grid);
		var bt = el('select', { multiple: true, size: 6, 'aria-label': 'Business categories' }, Object.keys(F.business_types).map(function (k) { return el('option', { value: k, text: F.business_types[k] + ' (' + k + ')', selected: c.business_types.indexOf(k) >= 0 }); }));
		bt.addEventListener('change', function () { c.business_types = Array.prototype.map.call(bt.selectedOptions, function (o) { return o.value; }); changed(); });
		box.appendChild(field('Only when the Local SEO business category is', bt, 'Leave empty for every business. Hold Ctrl/Cmd to select several.'));
		return box;
	}

	function fieldSelect(p) {
		var F = S.fields, groups = {};
		F.fields.forEach(function (f) { (groups[f.group] = groups[f.group] || []).push(f); });
		var sel = el('select', { 'aria-label': 'Dynamic field' }, [el('option', { value: '', text: '— choose a field —' })]);
		Object.keys(groups).forEach(function (g) { sel.appendChild(el('optgroup', { label: g }, groups[g].filter(function (f) { return p.source === 'ref' ? f.kind === 'ref' : f.kind !== 'ref'; }).map(function (f) { return el('option', { value: f.key, text: f.label, selected: p.field === f.key }); }))); });
		if (p.source !== 'ref') {
			var pat = el('optgroup', { label: 'Custom sources' });
			F.meta_keys.forEach(function (k) { pat.appendChild(el('option', { value: 'meta:' + k, text: 'Custom field: ' + k, selected: p.field === 'meta:' + k })); });
			F.publishrank.forEach(function (k) { pat.appendChild(el('option', { value: 'pr:' + k, text: 'PublishRank field: ' + k, selected: p.field === 'pr:' + k })); });
			F.taxonomies.forEach(function (x) { pat.appendChild(el('option', { value: 'tax:' + x.name, text: 'Taxonomy: ' + x.label, selected: p.field === 'tax:' + x.name })); });
			pat.appendChild(el('option', { value: '__custom', text: 'Other custom field / ACF field…' }));
			sel.appendChild(pat);
		}
		if (p.field && !Array.prototype.some.call(sel.options, function (o) { return o.value === p.field; })) { sel.appendChild(el('option', { value: p.field, text: p.field, selected: true })); }
		sel.addEventListener('change', function () {
			if (sel.value === '__custom') { var k = prompt('Enter "meta:field_key" for a custom field or "acf:field_name" for an ACF field'); if (k && /^(meta|acf|termmeta):[A-Za-z0-9_\-]+$/.test(k)) { p.field = k; sel.appendChild(el('option', { value: k, text: k, selected: true })); } else { sel.value = p.field; return; } } else { p.field = sel.value; }
			changed();
		});
		return sel;
	}

	function propRow(p, def, list, idx, depth, redraw) {
		var row = el('div', { class: 'pr-ss-prop' + (depth ? ' pr-ss-prop--nested' : '') });
		var ranges = def ? def.ranges : [];
		var ginfo = (S.current && S.typeCache[S.current.type] && S.typeCache[S.current.type].google) || { req: [], rec: [] };
		var isReq = !depth && (ginfo.req || []).indexOf(p.name) >= 0, isRec = !depth && (ginfo.rec || []).indexOf(p.name) >= 0;
		var ckey = (depth + ':' + p.name + ':' + idx), collapsed = !!S.collapsed[ckey];
		var head = el('div', { class: 'pr-ss-prop__head' }, [
			el('strong', { class: 'pr-ss-prop__name', text: p.name || 'item' }),
			ranges.length ? el('span', { class: 'pr-ss-prop__ranges', text: ranges.join(' | ') }) : null,
			def && def.pending ? badge('pending', 'info') : null,
			isReq ? badge('Google required', 'err') : null, isRec ? badge('Google recommended', 'ok') : null, p.required ? badge('stops output when empty', '') : null,
			def ? null : (p.name ? badge('not in Schema.org vocabulary for this type', 'warn') : null),
			el('span', { class: 'pr-ss-prop__spacer' }),
			el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', 'aria-expanded': collapsed ? 'false' : 'true', 'aria-label': (collapsed ? 'Expand ' : 'Collapse ') + (p.name || 'item'), text: collapsed ? '▸' : '▾', onclick: function () { S.collapsed[ckey] = !collapsed; redraw(); } }),
			idx > 0 ? el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', 'aria-label': 'Move up', text: '↑', onclick: function () { list.splice(idx - 1, 0, list.splice(idx, 1)[0]); changed(); redraw(); } }) : null,
			el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--ghost', text: 'Remove', onclick: function () { list.splice(idx, 1); changed(); redraw(); } })
		]);
		row.appendChild(head);
		if (collapsed) { row.appendChild(el('p', { class: 'pr-ds-help pr-ss-prop__summary', text: p.source === 'static' ? (p.value ? 'Fixed: ' + p.value : 'Fixed value (empty)') : (p.source === 'dynamic' || p.source === 'ref' ? (p.field || 'No field chosen') : (p.source === 'object' ? 'Nested ' + (p.type || 'object') + ' · ' + (p.props || []).length + ' properties' : (p.items || []).length + ' list items')) })); return row; }
		if (def && def.comment) { row.appendChild(el('p', { class: 'pr-ds-help', text: def.comment })); }
		var srcOpts = [['static', 'Fixed value'], ['dynamic', 'Dynamic field'], ['ref', 'Link to entity (@id)'], ['object', 'Nested object'], ['list', 'List of values (repeatable)']];
		if (!isAdvanced()) { srcOpts = srcOpts.filter(function (o) { return ['static', 'dynamic', 'object'].indexOf(o[0]) >= 0 || o[0] === p.source; }); }
		var source = el('select', { 'aria-label': 'Value source for ' + p.name }, srcOpts.map(function (o) { return el('option', { value: o[0], text: o[1], selected: p.source === o[0] }); }));
		source.addEventListener('change', function () { p.source = source.value; if (p.source === 'object' && !p.type) { p.type = classRanges(def)[0] || 'Thing'; } changed(); redraw(); });
		var body = el('div', { class: 'pr-ss-prop__body' }, [source]);
		if (p.source === 'static') {
			var input;
			if (def && def.enum && def.enum.length) {
				input = el('select', null, [el('option', { value: '', text: '— choose —' })].concat(def.enum.map(function (m) { var v = 'https://schema.org/' + m; return el('option', { value: v, text: m, selected: p.value === v || p.value === m }); })));
			} else {
				input = el('input', { type: 'text', value: p.value, placeholder: exampleFor(def, p.name), 'aria-describedby': 'pr-ss-v-' + depth + '-' + idx + '-' + p.name });
			}
			body.appendChild(bind(p, 'value', input));
			var msg = el('p', { class: 'pr-ss-prop__err', id: 'pr-ss-v-' + depth + '-' + idx + '-' + p.name, role: 'status', 'aria-live': 'polite' });
			var validate = function () { var m = checkValue(def, p.name, input.value); msg.textContent = m; input.setAttribute('aria-invalid', m ? 'true' : 'false'); row.classList.toggle('is-invalid', !!m); };
			input.addEventListener('input', validate); validate();
			row._msg = msg;
		} else if (p.source === 'dynamic' || p.source === 'ref') {
			body.appendChild(fieldSelect(p));
			body.appendChild(bind(p, 'fallback', el('input', { type: 'text', value: p.fallback, placeholder: p.source === 'ref' ? 'Fallback @id URL (optional)' : 'Fallback when empty (optional)' })));
		} else if (p.source === 'object') {
			var opts = classRanges(def); if (opts.indexOf(p.type) < 0 && p.type) { opts.push(p.type); } if (!opts.length) { opts = [p.type || 'Thing']; }
			var tsel = el('select', { 'aria-label': 'Nested type' }, opts.map(function (o) { return el('option', { value: o, text: o, selected: p.type === o }); }));
			tsel.addEventListener('change', function () { p.type = tsel.value; changed(); redraw(); });
			body.appendChild(tsel);
		}
		if ((p.source === 'static' || p.source === 'dynamic') && isAdvanced()) {
			var fmt = el('select', { 'aria-label': 'Format' }, S.fields.formats.map(function (f) { return el('option', { value: f, text: f === '' ? 'Format: automatic' : 'Format: ' + f.replace('_', ' '), selected: p.format === f }); }));
			body.appendChild(bind(p, 'format', fmt));
		}
		body.appendChild(el('label', { class: 'pr-ds-check' }, [bind(p, 'required', el('input', { type: 'checkbox', checked: p.required })), 'Required']));
		row.appendChild(body);
		if (row._msg) { row.appendChild(row._msg); }
		if (p.source === 'object') {
			var nest = el('div', { class: 'pr-ss-nest' });
			loadType(p.type || 'Thing').then(function (info) { nest.appendChild(propList(p.props, info, depth + 1)); });
			row.appendChild(nest);
		}
		if (p.source === 'list') {
			var items = el('div', { class: 'pr-ss-nest' });
			function drawItems() {
				clear(items);
				p.items.forEach(function (it, i) { it.name = p.name; items.appendChild(propRow(it, def, p.items, i, depth + 1, drawItems)); });
				items.appendChild(el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Add another ' + (p.name || 'item'), onclick: function () { p.items.push(blankProp(p.name)); changed(); drawItems(); } }));
			}
			drawItems();
			row.appendChild(items);
		}
		return row;
	}

	function propList(list, info, depth) {
		var wrap = el('div', { class: 'pr-ss-props' });
		function draw() {
			clear(wrap);
			if (!list.length) { wrap.appendChild(el('p', { class: 'pr-ds-help', text: 'No properties yet.' })); }
			list.forEach(function (p, i) { wrap.appendChild(propRow(p, propDef(info, p.name), list, i, depth, draw)); });
			wrap.appendChild(directory(info, list, draw));
		}
		draw();
		return wrap;
	}

	/* Searchable property directory grouped by the class that declares each property. */
	function directory(info, list, onAdd) {
		var g = info.google || { req: [], rec: [] }, wrap = el('div', { class: 'pr-ss-dir' });
		var q = el('input', { type: 'search', placeholder: 'Add a property — search ' + info.type + ' properties…', 'aria-label': 'Search properties of ' + info.type });
		var res = el('div', { class: 'pr-ss-dir__results', hidden: true });
		var used = list.map(function (p) { return p.name; });
		function draw() {
			clear(res); var s = q.value.toLowerCase().trim(), shown = 0;
			info.groups.forEach(function (grp) {
				var items = grp.properties.filter(function (p) { return (!s || p.name.toLowerCase().indexOf(s) >= 0 || p.comment.toLowerCase().indexOf(s) >= 0); });
				if (!s && grp.class === 'Thing' && info.type !== 'Thing') { items = items.filter(function (p) { return ['name', 'description', 'url', 'image', 'sameAs', 'identifier', 'alternateName'].indexOf(p.name) >= 0; }); }
				if (!items.length) { return; }
				res.appendChild(el('div', { class: 'pr-ss-dir__group', text: 'From ' + grp.class }));
				items.slice(0, s ? 60 : 40).forEach(function (p) {
					shown++;
					var req = (g.req || []).indexOf(p.name) >= 0, rec = (g.rec || []).indexOf(p.name) >= 0;
					res.appendChild(el('button', { type: 'button', class: 'pr-ss-dir__item', disabled: used.indexOf(p.name) >= 0, onclick: function () { var np = blankProp(p.name); if (classRanges(p).length && DATATYPES.filter(function (d) { return p.ranges.indexOf(d) >= 0; }).length === 0 && !(p.enum && p.enum.length)) { np.source = 'object'; np.type = classRanges(p)[0]; } np.required = req; list.push(np); changed(); onAdd(); } }, [
						el('strong', { text: p.name }), ' ', el('span', { class: 'pr-ss-prop__ranges', text: p.ranges.join(' | ') }), req ? ' ' : '', req ? badge('Google required', 'err') : '', rec ? ' ' : '', rec ? badge('Google recommended', 'ok') : '', p.pending ? ' ' : '', p.pending ? badge('pending', 'info') : '',
						el('span', { class: 'pr-ds-help', text: p.comment })
					]));
				});
			});
			if (!shown) { res.appendChild(el('p', { class: 'pr-ds-help', text: 'No Schema.org property of ' + info.type + ' matches. Only properties defined for this type (or its parents) are offered.' })); }
		}
		q.addEventListener('focus', function () { res.hidden = false; draw(); });
		q.addEventListener('input', draw);
		q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { res.hidden = true; q.blur(); } });
		var miss = (g.req || []).filter(function (r) { return used.indexOf(r) < 0; });
		var recMiss = (g.rec || []).filter(function (r) { return used.indexOf(r) < 0; });
		if ((miss.length || recMiss.length) && !g.retired) {
			wrap.appendChild(el('div', { class: 'pr-ds-btn-row pr-ss-quickadd' }, [
				miss.length ? el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm pr-ds-btn--primary', text: 'Add ' + miss.length + ' required propert' + (miss.length === 1 ? 'y' : 'ies'), onclick: function () { miss.forEach(function (n) { list.push(autoProp(n, propDef(info, n), g)); }); changed(); onAdd(); } }) : null,
				recMiss.length ? el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Add ' + recMiss.length + ' recommended', onclick: function () { recMiss.forEach(function (n) { list.push(autoProp(n, propDef(info, n), g)); }); changed(); onAdd(); } }) : null
			]));
		}
		wrap.appendChild(q);
		if (miss.length && !g.retired) { wrap.appendChild(el('p', { class: 'pr-ds-help pr-ss-dir__hint' }, ['Google documents these as required for ' + (g.feature || info.type) + ': ', el('strong', { text: miss.join(', ') })])); }
		wrap.appendChild(res);
		return wrap;
	}

	function properties(t, info) {
		var box = el('div', { class: 'pr-ds-card', id: 'pr-ss-card-props' }, [el('div', { class: 'pr-ds-card__head' }, [el('div', null, [el('h2', { class: 'pr-ds-card__title', text: '2–3. Properties and values' }), el('p', { class: 'pr-ds-card__sub', text: 'Map each property to a fixed value, a dynamic field, a link to another entity, a nested object or a list. Empty values are never printed; a required property that is empty stops the entity from printing on that page.' })])])]);
		box.appendChild(propList(t.properties, info, 0));
		return box;
	}

	function revisions() {
		api('/templates/' + S.current.id + '/revisions').then(function (revs) {
			var modal = el('div', { class: 'pr-ds-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Revisions' });
			var close = function () { modal.remove(); };
			var body = el('div');
			if (!revs.length) { body.appendChild(el('p', { text: 'No earlier versions yet. A version is kept each time you save (last 10).' })); }
			revs.forEach(function (r, i) { body.appendChild(el('div', { class: 'pr-ss-row' }, [el('span', { text: new Date(r.saved).toLocaleString() + ' — ' + r.name + ' (' + r.properties + ' properties)' }), el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Restore', onclick: function () { api('/templates/' + S.current.id + '/restore', { method: 'POST', body: { index: i } }).then(function (tpl) { close(); toast('Restored'); reload(); openEditor(tpl); }); } })])); });
			modal.appendChild(el('div', { class: 'pr-ds-modal__box' }, [el('div', { class: 'pr-ds-modal__head' }, [el('h2', { text: 'Revisions' }), el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--ghost', text: 'Close', onclick: close })]), body]));
			document.body.appendChild(modal);
		});
	}

	function save(publish) {
		var t = S.current; if (publish) { t.status = 'enabled'; }
		var path = t.id ? '/templates/' + t.id : '/templates';
		api(path, { method: 'POST', body: t }).then(function (r) { S.current = r; S.saved = clone(r); S.dirty = false; toast(publish ? 'Saved and live on matching pages' : 'Saved'); reload(); renderEditor(); }).catch(function (e) { toast(e.message, true); });
	}

	/* ---------- preview ---------- */
	function sampleSelect(onChange) {
		var sel = el('select', { 'aria-label': 'Sample page' });
		function fill() { clear(sel); S.samples.forEach(function (s, i) { sel.appendChild(el('option', { value: String(i), text: s.label, selected: S.sample && s.kind === S.sample.kind && s.id === S.sample.id })); }); }
		fill();
		sel.addEventListener('change', function () { S.sample = S.samples[parseInt(sel.value, 10)]; onChange(); });
		return sel;
	}
	function previewPanel() {
		var box = el('div', { class: 'pr-ds-card pr-ss-pv', id: 'pr-ss-pv' });
		box.appendChild(el('div', { class: 'pr-ds-card__head' }, [el('h2', { class: 'pr-ds-card__title', text: '5. Preview & validate' }), el('span', { id: 'pr-ss-pv-status', class: 'pr-ds-help', role: 'status', 'aria-live': 'polite' })]));
		box.appendChild(field('Sample page', sampleSelect(function () { schedulePreview(0); })));
		box.appendChild(el('p', { class: 'pr-ds-help', text: T.previewNote }));
		var seg = el('div', { class: 'pr-ds-seg', role: 'group', 'aria-label': 'Preview view' });
		[['readable', 'Readable'], ['jsonld', 'JSON-LD'], ['graph', 'Graph'], ['checks', 'Checks'], ['google', 'Google notes'], ['trace', 'Sources']].forEach(function (o) { seg.appendChild(el('button', { type: 'button', 'aria-pressed': S.previewTab === o[0] ? 'true' : 'false', text: o[1], onclick: function (e) { S.previewTab = o[0]; Array.prototype.forEach.call(seg.children, function (b) { b.setAttribute('aria-pressed', b === e.currentTarget ? 'true' : 'false'); }); drawPreview(); } })); });
		box.appendChild(seg);
		box.appendChild(el('div', { id: 'pr-ss-pv-body', class: 'pr-ss-pv__body' }));
		return box;
	}
	function schedulePreview(delay) { clearTimeout(S.timer); S.timer = setTimeout(runPreview, delay === undefined ? 600 : delay); }
	function runPreview() {
		if (!S.current || !S.sample) { return; }
		var st = document.getElementById('pr-ss-pv-status'); if (st) { clear(st).appendChild(el('span', { class: 'pr-ds-spinner', 'aria-hidden': 'true' })); st.appendChild(document.createTextNode(' Updating…')); }
		var seq = ++S.seq;
		api('/preview', { method: 'POST', body: { template: S.current, sample: S.sample } }).then(function (d) { if (seq !== S.seq) { return; } S.preview = d; drawSteps(); if (st) { st.textContent = d.mode === 'page' ? 'Live page output' : 'Template only'; } drawPreview(); }).catch(function (e) { if (st) { st.textContent = e.message; } });
	}
	function issueList(issues) {
		if (!issues.length) { return el('div', { class: 'pr-ds-notice pr-ds-notice--ok', text: 'No Schema.org problems found.' }); }
		var order = { error: 0, warning: 1, notice: 2 };
		return el('ul', { class: 'pr-ss-issues' }, issues.slice().sort(function (a, b) { return order[a.level] - order[b.level]; }).map(function (i) { return el('li', { class: 'pr-ss-issue pr-ss-issue--' + i.level }, [badge(i.level, i.level === 'error' ? 'err' : (i.level === 'warning' ? 'warn' : 'info')), ' ', el('span', { text: i.message }), el('div', { class: 'pr-ds-help', text: i.path + ' · ' + i.code })]); }));
	}
	function eligibilityList(list, tools) {
		var wrap = el('div');
		wrap.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--info', text: 'These notes compare the markup with Google\'s documentation. They are not a promise of a rich result; check the live URL with Google\'s Rich Results Test.' }));
		if (!list.length) { wrap.appendChild(el('p', { class: 'pr-ds-help', text: 'No entity on this page maps to a documented Google Search feature.' })); }
		list.forEach(function (e) {
			var kind = e.status === 'retired' ? 'warn' : (e.status === 'incomplete' ? 'err' : 'ok');
			var label = e.status === 'retired' ? 'feature retired by Google' : (e.status === 'incomplete' ? 'missing required properties' : 'meets documented requirements');
			wrap.appendChild(el('div', { class: 'pr-ss-elig' }, [el('strong', { text: e.type + ' → ' + e.feature }), ' ', badge(label, kind),
				e.missing.length ? el('div', { class: 'pr-ds-help', text: 'Required: ' + e.missing.join(', ') }) : null,
				e.recommended.length ? el('div', { class: 'pr-ds-help', text: 'Recommended, not set: ' + e.recommended.join(', ') }) : null,
				e.note ? el('div', { class: 'pr-ds-help', text: e.note }) : null,
				el('a', { href: e.doc, target: '_blank', rel: 'noopener noreferrer', text: 'Google documentation' })]));
		});
		if (tools) { wrap.appendChild(el('div', { class: 'pr-ds-btn-row' }, [el('a', { class: 'pr-ds-btn pr-ds-btn--sm', href: tools.rich_results, target: '_blank', rel: 'noopener noreferrer', text: 'Open Rich Results Test' }), el('a', { class: 'pr-ds-btn pr-ds-btn--sm', href: tools.schema_org, target: '_blank', rel: 'noopener noreferrer', text: 'Open Schema Markup Validator' })])); }
		return wrap;
	}
	function drawPreview() {
		var b = document.getElementById('pr-ss-pv-body'), d = S.preview; if (!b || !d) { return; }
		clear(b);
		if (!d.matches) { b.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--warn', text: 'This sample does not match the template\'s rules. The preview applies it anyway so you can test it; on the live site it would not be printed here.' })); }
		if (d.skipped) { b.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--err', text: d.skipped })); }
		if (d.note) { b.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--info', text: d.note })); }
		(d.result || []).forEach(function (r) { if (r.id === S.current.id || r.id === 'preview' || (!S.current.id && r.id === '')) { b.appendChild(el('p', { class: 'pr-ds-help', text: 'Result: ' + r.result + (r.target ? ' → ' + r.target : '') + (r.result === 'woocommerce' ? ' (merged into WooCommerce\'s Product markup)' : '') })); } });
		b.appendChild(verdicts(d));
		if (S.previewTab === 'readable') { b.appendChild(readable(d.graph, d.result)); }
		if (S.previewTab === 'jsonld') {
			var cbtn = el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Copy JSON-LD', onclick: function () { copyText(d.jsonld || '', cbtn); } });
			b.appendChild(el('div', { class: 'pr-ds-btn-row' }, [cbtn]));
			b.appendChild(el('pre', { class: 'pr-ds-code', tabindex: '0', 'aria-label': 'Generated JSON-LD', text: d.jsonld }));
		}
		if (S.previewTab === 'graph') { b.appendChild(graphView(d.graph, d.result)); }
		if (S.previewTab === 'checks') { b.appendChild(el('h3', { text: 'This template\'s entity' })); b.appendChild(issueList(d.node_issues || [])); b.appendChild(el('h3', { text: 'Whole page graph' })); b.appendChild(issueList(d.issues || [])); }
		if (S.previewTab === 'google') { b.appendChild(eligibilityList(d.eligibility || [], d.tools)); }
		if (S.previewTab === 'trace') { var dl = el('dl', { class: 'pr-ds-kv' }); Object.keys(d.trace || {}).forEach(function (k) { dl.appendChild(el('dt', { text: k })); dl.appendChild(el('dd', { text: d.trace[k] })); }); b.appendChild(Object.keys(d.trace || {}).length ? dl : el('p', { class: 'pr-ds-help', text: 'No property resolved for this sample.' })); }
	}

	/* ---------- graph visualisation (SVG) ---------- */
	var SVGNS = 'http://www.w3.org/2000/svg';
	function svg(tag, attrs, text) { var n = document.createElementNS(SVGNS, tag); Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); }); if (text !== undefined) { n.textContent = text; } return n; }
	function collectRefs(v, prop, out) { if (Array.isArray(v)) { v.forEach(function (x) { collectRefs(x, prop, out); }); return; } if (v && typeof v === 'object') { if (v['@id'] && Object.keys(v).length === 1) { out.push({ prop: prop, id: v['@id'] }); return; } Object.keys(v).forEach(function (k) { if (k.charAt(0) !== '@') { collectRefs(v[k], prop + '.' + k, out); } }); } }
	function graphView(graph, result) {
		graph = graph || [];
		var hl = {}; (result || []).forEach(function (r) { if (r.target) { hl[r.target] = true; } });
		var nodes = graph.map(function (n, i) { var t = n['@type']; return { i: i, id: n['@id'] || ('#' + i), type: Array.isArray(t) ? t.join('/') : (t || '?'), name: typeof n.name === 'string' ? n.name : (typeof n.headline === 'string' ? n.headline : ''), refs: [] }; });
		var byId = {}; nodes.forEach(function (n) { byId[n.id] = n; });
		graph.forEach(function (n, i) { Object.keys(n).forEach(function (k) { if (k.charAt(0) !== '@') { collectRefs(n[k], k, nodes[i].refs); } }); });
		// Layer: identity/website first, page next, entities after.
		nodes.forEach(function (n) { n.level = /Organization|Person|WebSite/.test(n.type) && !/WebPage/.test(n.type) ? 0 : (/WebPage|Page$/.test(n.type) ? 1 : 2); if (/BreadcrumbList|ItemList/.test(n.type)) { n.level = 1; } });
		var cols = [[], [], []]; nodes.forEach(function (n) { cols[n.level].push(n); });
		var W = 230, H = 54, GX = 70, GY = 22, width = 3 * W + 2 * GX + 20, height = Math.max.apply(null, cols.map(function (c) { return c.length; }).concat([1])) * (H + GY) + 20;
		cols.forEach(function (c, ci) { c.forEach(function (n, ri) { n.x = 10 + ci * (W + GX); n.y = 10 + ri * (H + GY); }); });
		var s = svg('svg', { viewBox: '0 0 ' + width + ' ' + height, width: '100%', role: 'img', 'aria-label': 'Schema graph with ' + nodes.length + ' entities', class: 'pr-ss-graph' });
		var defs = svg('defs'); var mk = svg('marker', { id: 'pr-ss-arrow', viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' }); mk.appendChild(svg('path', { d: 'M0,0 L10,5 L0,10 z', fill: '#8E8A84' })); defs.appendChild(mk); s.appendChild(defs);
		var missing = [];
		nodes.forEach(function (n) { n.refs.forEach(function (r) { var to = byId[r.id]; if (!to) { missing.push(n.type + '.' + r.prop + ' → ' + r.id); return; } if (to === n) { return; }
			var x1 = n.x + (to.x > n.x ? W : (to.x < n.x ? 0 : W / 2)), y1 = n.y + H / 2, x2 = to.x + (to.x > n.x ? 0 : (to.x < n.x ? W : W / 2)), y2 = to.y + (to.x === n.x ? (to.y > n.y ? 0 : H) : H / 2);
			var mx = (x1 + x2) / 2; var path = svg('path', { d: 'M' + x1 + ',' + y1 + ' C' + mx + ',' + y1 + ' ' + mx + ',' + y2 + ' ' + x2 + ',' + y2, fill: 'none', stroke: '#B9B2A8', 'stroke-width': '1.4', 'marker-end': 'url(#pr-ss-arrow)' });
			path.appendChild(svg('title', {}, r.prop)); s.appendChild(path);
			s.appendChild(svg('text', { x: String(mx), y: String((y1 + y2) / 2 - 4), 'text-anchor': 'middle', class: 'pr-ss-graph__edge' }, r.prop.split('.')[0])); }); });
		nodes.forEach(function (n) {
			var g = svg('g', { class: 'pr-ss-graph__node' + (hl[n.id] ? ' is-template' : '') });
			g.appendChild(svg('rect', { x: String(n.x), y: String(n.y), width: String(W), height: String(H), rx: '10' }));
			g.appendChild(svg('text', { x: String(n.x + 12), y: String(n.y + 22), class: 'pr-ss-graph__type' }, n.type.length > 30 ? n.type.slice(0, 29) + '…' : n.type));
			g.appendChild(svg('text', { x: String(n.x + 12), y: String(n.y + 40), class: 'pr-ss-graph__name' }, (n.name || n.id.replace(/^https?:\/\/[^/]+/, '')).slice(0, 34)));
			g.appendChild(svg('title', {}, n.id)); s.appendChild(g);
		});
		var wrap = el('div', { class: 'pr-ss-graphwrap' }, [s]);
		wrap.appendChild(el('p', { class: 'pr-ds-help', text: 'Arrows are { "@id" } references between entities. The highlighted box is the entity this template created or enriched.' }));
		if (missing.length) { wrap.appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--warn', text: 'References to entities not on this page: ' + missing.join('; ') })); }
		return wrap;
	}

	/* ---------- diagnostics ---------- */
	function renderDiagnostics() {
		var p = clear(panel('diagnostics'));
		var box = el('div', { class: 'pr-ds-card' }, [el('div', { class: 'pr-ds-card__head' }, [el('div', null, [el('h2', { class: 'pr-ds-card__title', text: 'Structured data diagnostics' }), el('p', { class: 'pr-ds-card__sub', text: 'Loads the live page, reads every JSON-LD block (PublishRank, WooCommerce, other plugins and the theme) and checks it against the Schema.org vocabulary. Google notes are listed separately.' })])])]);
		var url = el('input', { type: 'url', placeholder: C.home, value: '', 'aria-label': 'Page URL on this site' });
		var out = el('div');
		var run = function (body) {
			clear(out).appendChild(el('span', { class: 'pr-ds-spinner' }));
			api('/validate', { method: 'POST', body: body }).then(function (r) {
				clear(out);
				var sum = r.summary;
				out.appendChild(el('div', { class: 'pr-ds-grid pr-ds-grid--3' }, [['error', 'Errors'], ['warning', 'Warnings'], ['notice', 'Notices']].map(function (k) { return el('div', { class: 'pr-ds-metric' }, [el('div', { class: 'pr-ds-metric__value', text: String(sum[k[0]]) }), el('div', { class: 'pr-ds-metric__label', text: k[1] })]); })));
				out.appendChild(el('h3', { text: 'JSON-LD blocks on the page' }));
				out.appendChild(el('ul', null, (r.blocks || []).map(function (b, i) { return el('li', { text: 'Block ' + (i + 1) + ': ' + b.source + ' — ' + (b.valid_json ? 'valid JSON' : 'invalid JSON') + ', ' + b.bytes + ' bytes' }); })));
				out.appendChild(el('h3', { text: 'Schema.org validity' })); out.appendChild(issueList(r.issues || []));
				out.appendChild(el('h3', { text: 'Google Search notes' })); out.appendChild(eligibilityList(r.eligibility || [], r.tools));
				out.appendChild(el('h3', { text: 'Graph' })); out.appendChild(graphView(r.nodes || [], []));
			}).catch(function (e) { clear(out).appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--err', text: e.message })); });
		};
		var sampleSel = sampleSelect(function () {});
		box.appendChild(el('div', { class: 'pr-ds-grid pr-ds-grid--2' }, [field('Check a sample page', sampleSel), field('…or any URL on this site', url)]));
		box.appendChild(el('div', { class: 'pr-ds-btn-row' }, [el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'Run diagnostics', onclick: function () { run(url.value ? { url: url.value } : { sample: S.sample }); } })]));
		var json = el('textarea', { rows: 5, placeholder: 'Or paste JSON-LD to validate it', 'aria-label': 'JSON-LD to validate' });
		box.appendChild(field('Validate pasted JSON-LD', json));
		box.appendChild(el('button', { type: 'button', class: 'pr-ds-btn', text: 'Validate JSON-LD', onclick: function () { clear(out).appendChild(el('span', { class: 'pr-ds-spinner' })); api('/validate', { method: 'POST', body: { json: json.value } }).then(function (r) { clear(out); out.appendChild(issueList(r.issues)); out.appendChild(eligibilityList(r.eligibility || [], null)); }).catch(function (e) { clear(out).appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--err', text: e.message })); }); } }));
		p.appendChild(box); p.appendChild(out);
		if (C.sample && C.sample.kind && !S._autoRan) { S._autoRan = true; run({ sample: C.sample }); }
	}

	/* ---------- catalog ---------- */
	function renderCatalog() {
		var p = clear(panel('catalog'));
		var q = el('input', { type: 'search', placeholder: 'Search types, groups or descriptions', 'aria-label': 'Search the type catalog' });
		var tbody = el('tbody');
		function draw() {
			clear(tbody); var s = q.value.toLowerCase();
			S.catalog.types.forEach(function (t) {
				if (s && (t.type + ' ' + t.label + ' ' + t.group + ' ' + t.comment).toLowerCase().indexOf(s) < 0) { return; }
				var g = t.google;
				tbody.appendChild(el('tr', null, [el('td', null, [el('strong', { text: t.label }), el('div', null, [el('code', { text: t.type })])]), el('td', { text: t.group }), el('td', { class: 'pr-ds-help', text: t.parents.slice(0, 3).join(' › ') }),
					el('td', null, [t.pending ? badge('pending', 'info') : badge('core', 'ok')]),
					el('td', null, [g ? badge((g.via !== t.type ? 'via ' + g.via + ': ' : '') + g.feature, g.retired ? 'warn' : 'ok') : el('span', { class: 'pr-ds-help', text: 'none' })]),
					el('td', null, [el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--sm', text: 'Create template', onclick: function () { openEditor(blankTemplate(t.type)); } })])]));
			});
		}
		q.addEventListener('input', draw);
		var ex = S.catalog.excluded || {};
		p.appendChild(el('div', { class: 'pr-ds-card' }, [el('div', { class: 'pr-ds-card__head' }, [el('div', null, [el('h2', { class: 'pr-ds-card__title', text: S.catalog.types.length + ' Schema.org types' }), el('p', { class: 'pr-ds-card__sub', text: 'Checked against the ' + S.catalog.version + '. "Pending" types are valid but still under review by Schema.org. The Google column is documentation-based and separate from Schema.org validity.' })])]),
			Object.keys(ex).length ? el('div', { class: 'pr-ds-notice pr-ds-notice--info' }, ['Not offered because they are not Schema.org types: ', Object.keys(ex).map(function (k) { return k + ' — ' + ex[k]; }).join(' ')]) : null,
			q, el('div', { class: 'pr-ds-table-wrap' }, [el('table', { class: 'pr-ds-table' }, [el('thead', null, [el('tr', null, ['Type', 'Group', 'Parents', 'Status', 'Google Search feature', ''].map(function (h) { return el('th', { scope: 'col', text: h }); }))]), tbody])])]));
		draw();
	}

	/* ---------- import / export ---------- */
	function renderTransfer() {
		var p = clear(panel('transfer'));
		var file = el('input', { type: 'file', accept: 'application/json,.json', 'aria-label': 'Template file' });
		var over = el('input', { type: 'checkbox' });
		var out = el('div');
		p.appendChild(el('div', { class: 'pr-ds-grid pr-ds-grid--2' }, [
			el('div', { class: 'pr-ds-card' }, [el('h2', { class: 'pr-ds-card__title', text: 'Export' }), el('p', { class: 'pr-ds-card__sub', text: 'Download every template as one JSON file to move them to another site or keep a backup.' }), el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'Export all templates', onclick: function () { api('/export').then(function (d) { download(d, 'publishrank-schema-templates.json'); }); } })]),
			el('div', { class: 'pr-ds-card' }, [el('h2', { class: 'pr-ds-card__title', text: 'Import' }), el('p', { class: 'pr-ds-card__sub', text: 'Imported templates are validated like edited ones. Existing templates with the same ID are kept unless you choose to overwrite them.' }), file, el('label', { class: 'pr-ds-check' }, [over, 'Overwrite templates with the same ID']), el('div', null, [el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'Import', onclick: function () {
				if (!file.files[0]) { toast('Choose a file first', true); return; }
				file.files[0].text().then(function (txt) { var data; try { data = JSON.parse(txt); } catch (e) { toast('That file is not valid JSON', true); return; } return api('/import', { method: 'POST', body: { data: data, overwrite: over.checked } }).then(function (r) { clear(out).appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--ok', text: 'Imported ' + r.imported + ' template(s).' + (r.skipped.length ? ' Skipped: ' + r.skipped.join('; ') : '') })); reload(); }); }).catch(function (e) { toast(e.message, true); });
			} })]), out])
		]));
	}

	/* ---------- settings ---------- */
	function renderSettings() {
		var p = clear(panel('settings'));
		var en = el('input', { type: 'checkbox', checked: !!S.settings.enabled }), orph = el('input', { type: 'checkbox', checked: !!S.settings.connect_orphans });
		p.appendChild(el('div', { class: 'pr-ds-card' }, [el('h2', { class: 'pr-ds-card__title', text: 'Output settings' }),
			el('p', null, [el('label', { class: 'pr-ds-switch' }, [en, el('span', { class: 'pr-ds-switch__track' }), 'Print enabled templates on the site'])]),
			el('p', null, [el('label', { class: 'pr-ds-switch' }, [orph, el('span', { class: 'pr-ds-switch__track' }), 'Connect stand-alone entities to their page (adds a stable @id and an isPartOf / mainEntityOfPage link)'])]),
			el('button', { type: 'button', class: 'pr-ds-btn pr-ds-btn--primary', text: 'Save settings', onclick: function () { api('/settings', { method: 'POST', body: { enabled: en.checked, connect_orphans: orph.checked } }).then(function (s) { S.settings = s; toast('Settings saved'); }); } })]));
	}

	/* ---------- boot ---------- */
	Promise.all([api('/templates'), api('/fields'), api('/catalog'), api('/samples')]).then(function (r) {
		S.templates = r[0].templates; S.settings = r[0].settings; S.fields = r[1]; S.catalog = r[2]; S.catalog.byType = {}; r[2].types.forEach(function (t) { S.catalog.byType[t.type] = t; });
		S.samples = r[3];
		if (C.sample && C.sample.kind) { S.samples.forEach(function (s) { if (s.kind === C.sample.kind && s.id === C.sample.id) { S.sample = s; } }); }
		if (!S.sample) { S.sample = S.samples[1] || S.samples[0]; }
		var hash = (location.hash || '').replace('#', '');
		show(['templates', 'editor', 'diagnostics', 'catalog', 'transfer', 'settings'].indexOf(hash) >= 0 ? hash : 'templates', false);
	}).catch(function (e) { panel('templates').hidden = false; panel('templates').appendChild(el('div', { class: 'pr-ds-notice pr-ds-notice--err', text: 'Schema Studio could not load: ' + e.message })); });
})();
