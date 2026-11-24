/* PublishRank Media tab (2.26.2) — shared by the block editor sidebar and the Classic dock/window.
 *
 * Lists every image the post uses with its role(s) (featured, social, author, video thumbnail, content), lets the
 * publisher edit attachment alt text, title, caption and description (with conflict detection), validates an
 * external social image URL, picks a social image from the Media Library, and starts a safe file URL change with
 * the Media Link Manager's dry run. Applying a URL change always happens on the Media Link Manager review screen.
 *
 * Framework-free ES5. Public API: window.PublishRankMediaTab.mount(root, opts) -> { refresh, destroy }.
 * opts: { postId, restRoot, nonce, getState: fn -> {featured, og_image, content}, setSocialImage: fn(url, id),
 *         canEdit, i18n }
 * Fires `publishrank:media-changed` on document after every change so previews refresh. */
(function () {
	'use strict';
	var cfg = window.publishrankMediaTab || {};
	var T = cfg.i18n || {};
	function tr(k, d) { return T[k] || d; }
	function h(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) { Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) { return; }
			if (k === 'text') { n.textContent = v; } else if (k === 'class') { n.className = v; } else if (k.indexOf('on') === 0 && typeof v === 'function') { n.addEventListener(k.slice(2), v); } else { n.setAttribute(k, v === true ? '' : v); }
		}); }
		(kids || []).forEach(function (c) { if (c === null || c === undefined || c === false) { return; } n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return n;
	}
	function emit(detail) { try { document.dispatchEvent(new CustomEvent('publishrank:media-changed', { detail: detail || {} })); } catch (e) {} }
	var uid = 0;
	var ROLE = function (r) {
		return ({ featured: tr('featured', 'Featured image'), social: tr('social', 'Social image'), author: tr('author', 'Author image'), video_thumbnail: tr('video', 'Video thumbnail'), content: tr('content', 'In content') })[r] || r;
	};

	function api(o, path, method, data) {
		var url = String(o.restRoot || cfg.restRoot || '').replace(/\/$/, '') + '/' + path.replace(/^\//, '');
		var init = { method: method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': o.nonce || cfg.nonce || '' } };
		if (data) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(data); }
		return window.fetch(url, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) { var e = new Error((j && j.message) || ('HTTP ' + r.status)); e.status = r.status; e.data = j; throw e; } return j; });
		});
	}

	function mount(root, opts) {
		if (!root) { return null; }
		var o = opts || {};
		var state = { items: [], perms: {}, links: {}, busy: false, timer: 0, open: {} };
		root.classList.add('pr-mt');
		root.setAttribute('aria-busy', 'false');

		var status = h('p', { class: 'pr-mt__status', role: 'status', 'aria-live': 'polite' });
		var list = h('ul', { class: 'pr-mt__list' });
		var toolbar = h('div', { class: 'pr-mt__bar' }, [
			h('button', { type: 'button', class: 'button button-primary pr-mt__pick', onclick: pickSocial }, [tr('pick', 'Choose social image')]),
			h('button', { type: 'button', class: 'button pr-mt__refresh', onclick: function () { refresh(true); } }, [tr('refresh', 'Refresh')])
		]);
		var help = h('p', { class: 'pr-mt__help' }, [tr('altHelp', 'Alt text here is the Media Library default for the image. An image block can carry its own alt text; PublishRank never rewrites block attributes.')]);
		var extForm = externalForm();
		root.replaceChildren(toolbar, help, extForm, status, list);

		function setStatus(text, tone) { status.textContent = text || ''; status.className = 'pr-mt__status' + (tone ? ' is-' + tone : ''); }

		function refresh(manual) {
			window.clearTimeout(state.timer);
			var st = (o.getState && o.getState()) || {};
			var body = {};
			if (st.featured !== undefined && st.featured !== null) { body.featured = parseInt(st.featured, 10) || 0; }
			if (st.og_image !== undefined && st.og_image !== null) { body.og_image = String(st.og_image); }
			if (st.content !== undefined && st.content !== null) { body.content = String(st.content).slice(0, 400000); }
			root.setAttribute('aria-busy', 'true');
			if (manual) { setStatus(tr('loading', 'Loading images…')); }
			return api(o, 'media-links/post/' + (o.postId || 0) + '/images', 'POST', body).then(function (r) {
				state.items = r.items || []; state.perms = r.permissions || {}; state.links = r.links || {};
				root.setAttribute('aria-busy', 'false');
				render();
				setStatus(state.items.length ? sprintfN(tr('count', '%d images used by this item.'), state.items.length) : tr('none', 'This item does not use any images yet.'));
			}).catch(function (e) { root.setAttribute('aria-busy', 'false'); setStatus(e.message, 'error'); });
		}
		function sprintfN(s, n) { return String(s).replace('%d', n); }
		function schedule() { window.clearTimeout(state.timer); state.timer = window.setTimeout(refresh, 800); }

		function render() {
			list.replaceChildren();
			state.items.forEach(function (it) { list.appendChild(row(it)); });
		}

		function row(it) {
			var id = 'pr-mt-' + (++uid);
			var dims = it.width && it.height ? it.width + '×' + it.height : '';
			var badges = h('span', { class: 'pr-mt__roles' }, it.roles.map(function (r) { return h('span', { class: 'pr-mt__role is-' + r, text: ROLE(r) }); }));
			var where = it.local ? h('span', { class: 'pr-mt__tag is-local', text: tr('local', 'Media Library') }) : h('span', { class: 'pr-mt__tag is-external', text: it.external ? tr('external', 'External') : tr('unlinked', 'Not in Media Library') });
			var meta = [dims, it.mime, it.refs !== null && it.refs !== undefined ? sprintfN(tr('refs', '%d references'), it.refs) : ''].filter(Boolean).join(' · ');
			var thumb = /^https?:\/\//i.test(it.thumb || '') ? h('img', { class: 'pr-mt__thumb', src: it.thumb, alt: '', loading: 'lazy', width: '56', height: '56' }) : h('span', { class: 'pr-mt__thumb is-empty', 'aria-hidden': 'true' });
			var warn = null;
			if (it.roles.indexOf('social') >= 0 && it.width && (it.width < 1200 || it.height < 630)) { warn = h('p', { class: 'pr-mt__warn', text: tr('small', 'Below 1200×630: some networks show a small card.') }); }
			if (it.local && !String(it.alt || '').trim() && it.roles.indexOf('content') >= 0) { warn = h('p', { class: 'pr-mt__warn', text: tr('noAlt', 'No default alt text.') }); }
			var toggle = h('button', { type: 'button', class: 'button button-small pr-mt__edit', 'aria-expanded': state.open[it.id || it.url] ? 'true' : 'false', 'aria-controls': id }, [it.local ? tr('edit', 'Edit details') : tr('details', 'Details')]);
			var panel = h('div', { class: 'pr-mt__panel', id: id, hidden: !state.open[it.id || it.url] }, [it.local ? metaForm(it) : externalNote(it), it.local && state.perms.change_urls ? urlForm(it) : null]);
			toggle.addEventListener('click', function () { var on = panel.hidden; panel.hidden = !on; toggle.setAttribute('aria-expanded', on ? 'true' : 'false'); state.open[it.id || it.url] = on; if (on) { var f = panel.querySelector('input,textarea'); if (f) { f.focus(); } } });
			return h('li', { class: 'pr-mt__item' }, [
				h('div', { class: 'pr-mt__main' }, [thumb, h('div', { class: 'pr-mt__info' }, [
					h('div', { class: 'pr-mt__line' }, [badges, where]),
					h('a', { class: 'pr-mt__url', href: it.url, target: '_blank', rel: 'noopener', text: it.url, title: it.url }),
					meta ? h('span', { class: 'pr-mt__meta', text: meta }) : null, warn
				]), toggle]),
				panel
			]);
		}

		function field(label, value, multi, name) {
			var fid = 'pr-mt-f-' + (++uid);
			var input = multi ? h('textarea', { id: fid, rows: '2', name: name, class: 'widefat' }) : h('input', { id: fid, type: 'text', name: name, class: 'widefat' });
			input.value = value || '';
			if (!o.canEdit && o.canEdit !== undefined) { input.readOnly = true; }
			return h('p', { class: 'pr-mt__field' }, [h('label', { for: fid, text: label }), input]);
		}
		function metaForm(it) {
			var msg = h('span', { class: 'pr-mt__msg', role: 'status', 'aria-live': 'polite' });
			var saveBtn = it.can_edit ? h('button', { type: 'button', class: 'button button-primary' }, [tr('save', 'Save image details')]) : null;
			var form = h('div', { class: 'pr-mt__form', role: 'group', 'aria-label': tr('detailsFor', 'Image details') }, [
				field(tr('alt', 'Alt text (default)'), it.alt, false, 'alt'),
				field(tr('title', 'Title'), it.title, false, 'title'),
				field(tr('caption', 'Caption'), it.caption, true, 'caption'),
				field(tr('description', 'Description'), it.description, true, 'description'),
				h('details', { class: 'pr-mt__rights' }, [
					h('summary', { text: tr('rights', 'Credit and licence (Google image metadata)') }),
					field(tr('credit', 'Credit line'), (it.rights || {}).credit, false, 'credit'),
					field(tr('creator', 'Photographer / creator'), (it.rights || {}).creator, false, 'creator'),
					field(tr('copyright', 'Copyright notice'), (it.rights || {}).copyright, false, 'copyright'),
					field(tr('license', 'Licence URL'), (it.rights || {}).license, false, 'license'),
					field(tr('licensePage', 'Where to license it (URL)'), (it.rights || {}).license_page, false, 'license_page')
				]),
				h('div', { class: 'pr-mt__actions' }, [saveBtn || h('em', { text: tr('noEdit', 'You cannot edit this image.') }), msg])
			]);
			// Saved over REST, never with the post form.
			if (saveBtn) { saveBtn.addEventListener('click', function () {
				var data = { modified: it.modified };
				['alt', 'title', 'caption', 'description', 'credit', 'creator', 'copyright', 'license', 'license_page'].forEach(function (k) { var f = form.querySelector('[name="' + k + '"]'); if (f) { data[k] = f.value; } });
				msg.textContent = tr('saving', 'Saving…'); msg.className = 'pr-mt__msg';
				api(o, 'media-links/attachments/' + it.id + '/metadata', 'POST', data).then(function (r) {
					Object.keys(r).forEach(function (k) { if (k !== 'roles' && k !== 'uses') { it[k] = r[k]; } });
					msg.textContent = tr('saved', 'Saved.'); msg.className = 'pr-mt__msg is-ok';
					emit({ attachment: it.id, fields: ['alt', 'title', 'caption', 'description'] });
				}).catch(function (e) {
					if (e.status === 409 && e.data && e.data.data && e.data.data.current) {
						var cur = e.data.data.current; it.modified = cur.modified;
						msg.textContent = e.message + ' ' + tr('current', 'Current values: alt "%1", title "%2".').replace('%1', cur.alt || '').replace('%2', cur.title || '');
					} else { msg.textContent = e.message; }
					msg.className = 'pr-mt__msg is-error';
				});
			}); }
			// Plain inputs inside the Classic post form must not post unknown fields with the post.
			form.querySelectorAll('input,textarea').forEach(function (f) { f.setAttribute('form', 'pr-mt-none'); });
			return form;
		}
		function externalNote(it) {
			return h('p', { class: 'pr-mt__help' }, [it.external ? tr('extNote', 'This image is on another website. It is stored only as a link in this post; no Media Library item is changed.') : tr('unlinkedNote', 'This address is on your site but is not a Media Library item, so it has no editable details.')]);
		}
		function urlForm(it) {
			var fid = 'pr-mt-u-' + (++uid);
			var input = h('input', { id: fid, type: 'url', class: 'widefat', placeholder: 'https://', form: 'pr-mt-none' });
			var out = h('div', { class: 'pr-mt__plan', role: 'status', 'aria-live': 'polite' });
			var go = h('button', { type: 'button', class: 'button' }, [tr('dry', 'Preview change (dry run)')]);
			go.addEventListener('click', function () {
				var v = input.value.trim();
				if (!/^https?:\/\//i.test(v)) { out.textContent = tr('badUrl', 'Use a full http:// or https:// address.'); out.className = 'pr-mt__plan is-error'; return; }
				go.disabled = true; out.textContent = tr('planning', 'Building a dry run… nothing is changed.'); out.className = 'pr-mt__plan';
				api(o, 'media-links/attachments/' + it.id + '/plan', 'POST', { operation: 'replace_url', new_url: v }).then(function (r) {
					go.disabled = false;
					var plan = r.plan || {}, variants = plan.pairs ? Object.keys(plan.pairs).length : 0;
					var mime = plan.precheck ? String(plan.precheck.detail || plan.precheck.status || '') : String(plan.mime || '');
					out.className = 'pr-mt__plan is-ok';
					out.replaceChildren(
						h('strong', { text: tr('dryDone', 'Dry run ready. Nothing has been changed yet.') }),
						h('ul', null, [
							h('li', { text: sprintfN(tr('variants', '%d file addresses (original and sizes) would be redirected to the new address'), variants) }),
							mime ? h('li', { text: tr('mime', 'File check: ') + mime }) : null,
							h('li', { text: tr('scanNext', 'Every post, setting and block that uses it is scanned and listed for your review before anything is written.') })
						]),
						h('a', { class: 'button button-primary', href: (state.links.manager || '#') + '&attachment=' + it.id, target: '_blank', rel: 'noopener' }, [tr('review', 'Review and apply in Media Link Manager')])
					);
				}).catch(function (e) { go.disabled = false; out.textContent = e.message; out.className = 'pr-mt__plan is-error'; });
			});
			return h('div', { class: 'pr-mt__url-change' }, [
				h('p', { class: 'pr-mt__field' }, [h('label', { for: fid, text: tr('newUrl', 'Point every use of this file to a new address') }), input]),
				h('div', { class: 'pr-mt__actions' }, [go]), out
			]);
		}
		function externalForm() {
			var fid = 'pr-mt-x-' + (++uid);
			var input = h('input', { id: fid, type: 'url', class: 'widefat', placeholder: 'https://cdn.example.com/social.jpg', form: 'pr-mt-none' });
			var out = h('span', { class: 'pr-mt__msg', role: 'status', 'aria-live': 'polite' });
			var btn = h('button', { type: 'button', class: 'button' }, [tr('useUrl', 'Check and use')]);
			btn.addEventListener('click', function () {
				var v = input.value.trim();
				out.className = 'pr-mt__msg'; out.textContent = tr('checking', 'Checking…');
				api(o, 'media-links/check-url', 'POST', { url: v, verify: true }).then(function (r) {
					if (!r.url) { out.textContent = r.message; out.className = 'pr-mt__msg is-error'; return; }
					if (o.setSocialImage) { o.setSocialImage(r.url, 0); }
					out.textContent = (r.ok ? tr('extOk', 'Social image set. It stays a plain link; no Media Library item is changed.') : tr('extWarn', 'Set, but the server could not confirm the image: ') + r.message);
					out.className = 'pr-mt__msg ' + (r.ok ? 'is-ok' : 'is-warn');
					emit({ social: r.url }); schedule();
				}).catch(function (e) { out.textContent = e.message; out.className = 'pr-mt__msg is-error'; });
			});
			return h('details', { class: 'pr-mt__ext' }, [
				h('summary', { text: tr('extTitle', 'Use an external social image address') }),
				h('p', { class: 'pr-mt__field' }, [h('label', { for: fid, text: tr('extLabel', 'Image address (http or https)') }), input]),
				h('div', { class: 'pr-mt__actions' }, [btn, out])
			]);
		}
		function pickSocial() {
			if (!window.wp || !wp.media) { setStatus(tr('noPicker', 'The Media Library is not available on this screen.'), 'error'); return; }
			var frame = wp.media({ title: tr('pickTitle', 'Choose the social image'), library: { type: 'image' }, multiple: false, button: { text: tr('pickButton', 'Use as social image') } });
			frame.on('select', function () {
				var a = frame.state().get('selection').first(); if (!a) { return; }
				var j = a.toJSON();
				if (o.setSocialImage) { o.setSocialImage(j.url, j.id); }
				setStatus(tr('picked', 'Social image updated. Save the post to publish it.'), 'ok');
				emit({ social: j.url, attachment: j.id }); schedule();
			});
			frame.open();
		}

		document.addEventListener('publishrank:media-state', schedule);
		refresh(true);
		return { refresh: refresh, schedule: schedule, destroy: function () { document.removeEventListener('publishrank:media-state', schedule); window.clearTimeout(state.timer); } };
	}

	window.PublishRankMediaTab = { mount: mount };

	/* Classic Editor: mount into the dock's Media pane and follow the featured image, social field and content. */
	function classicState() {
		var thumb = document.getElementById('_thumbnail_id');
		var og = document.querySelector('[name="_publishrank_seo_og_image"]');
		var content = '';
		try { if (window.tinymce && tinymce.get('content') && !tinymce.get('content').isHidden()) { content = tinymce.get('content').getContent(); } else { var ta = document.getElementById('content'); content = ta ? ta.value : ''; } } catch (e) {}
		return { featured: thumb ? (parseInt(thumb.value, 10) > 0 ? parseInt(thumb.value, 10) : 0) : null, og_image: og ? og.value : null, content: content };
	}
	function setClassicSocial(url) {
		var og = document.querySelector('[name="_publishrank_seo_og_image"]');
		if (og) { og.value = url; og.dispatchEvent(new Event('input', { bubbles: true })); og.dispatchEvent(new Event('change', { bubbles: true })); }
	}
	function bootClassic() {
		var root = document.querySelector('#publishrank-seo-dock [data-pr-media-root]');
		if (!root || document.body.classList.contains('block-editor-page')) { return; }
		var api = mount(root, { postId: parseInt(root.getAttribute('data-post-id'), 10) || 0, getState: classicState, setSocialImage: setClassicSocial, canEdit: cfg.canEdit !== false });
		var t = 0;
		function later() { window.clearTimeout(t); t = window.setTimeout(function () { try { document.dispatchEvent(new CustomEvent('publishrank:media-state')); } catch (e) {} }, 300); }
		var thumb = document.getElementById('_thumbnail_id');
		if (thumb && window.MutationObserver) { new MutationObserver(later).observe(thumb, { attributes: true, attributeFilter: ['value'] }); }
		document.addEventListener('change', function (e) { if (e.target && (e.target.id === '_thumbnail_id' || e.target.name === '_publishrank_seo_og_image')) { later(); } }, true);
		if (window.jQuery) { jQuery(document).on('ajaxSuccess', function (ev, xhr, settings) { if (settings && settings.data && String(settings.data).indexOf('set-post-thumbnail') >= 0) { window.setTimeout(later, 200); } }); }
		return api;
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', bootClassic); } else { bootClassic(); }
})();
