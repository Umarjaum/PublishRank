/* PublishRank one-click internal links.
 * Places suggested internal links on a matching phrase in the article text (block editor, classic
 * TinyMCE or the plain text editor). Suggestions with no matching phrase are added to one short
 * "Related coverage" line at the end. Only edits the unsaved editor content; nothing is saved until the
 * author updates the post. All text is inserted as DOM text nodes, never as raw HTML. */
(function (window, document) {
	'use strict';
	var cfg = window.publishrankLinker || {};

	function escRe(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
	function pattern(anchor) {
		try { return new RegExp('(^|[^\\p{L}\\p{N}])(' + escRe(anchor) + ')(?=$|[^\\p{L}\\p{N}])', 'iu'); }
		catch (e) { return new RegExp('(^|\\W)(' + escRe(anchor) + ')(?=$|\\W)', 'i'); }
	}
	function samePath(a, b) {
		try {
			var x = new URL(a, window.location.href), y = new URL(b, window.location.href);
			return x.host === y.host && x.pathname.replace(/\/+$/, '') === y.pathname.replace(/\/+$/, '');
		} catch (e) { return a === b; }
	}
	function hasLink(root, url) {
		var links = root.querySelectorAll('a[href]');
		for (var i = 0; i < links.length; i++) { if (samePath(links[i].getAttribute('href'), url)) { return true; } }
		return false;
	}
	function makeLink(doc, url, text) { var a = doc.createElement('a'); a.setAttribute('href', url); a.textContent = text; return a; }

	/* Wrap the first match of any anchor inside root's text (outside existing links/headings/code). */
	function linkInside(root, item) {
		var doc = root.ownerDocument || document;
		var anchors = (item.anchors && item.anchors.length ? item.anchors : [item.title]).filter(Boolean);
		for (var a = 0; a < anchors.length; a++) {
			var re = pattern(anchors[a]);
			var walker = doc.createTreeWalker(root, 4 /* SHOW_TEXT */, null);
			var node;
			while ((node = walker.nextNode())) {
				if (node.parentNode && node.parentNode.closest && node.parentNode.closest('a,h1,h2,h3,h4,h5,h6,code,pre,figcaption,button')) { continue; }
				var m = re.exec(node.nodeValue);
				if (!m) { continue; }
				var start = m.index + m[1].length, word = m[2];
				var after = node.splitText(start);
				after.nodeValue = after.nodeValue.slice(word.length);
				node.parentNode.insertBefore(makeLink(doc, item.url, word), after);
				return true;
			}
		}
		return false;
	}

	function relatedHtml(items) {
		var p = document.createElement('p'), strong = document.createElement('strong');
		strong.textContent = cfg.related || 'Related coverage:';
		p.appendChild(strong);
		items.forEach(function (item, i) {
			p.appendChild(document.createTextNode(i ? ' | ' : ' '));
			p.appendChild(makeLink(document, item.url, item.title));
		});
		return p;
	}

	function blockEditor() {
		try { var be = window.wp && wp.data && wp.data.select('core/block-editor'); return be && be.getBlocks ? be : null; } catch (e) { return null; }
	}

	function insertBlocks(items) {
		var be = blockEditor(), bd = wp.data.dispatch('core/block-editor');
		var paras = [];
		(function walk(blocks) {
			(blocks || []).forEach(function (b) {
				if (b.name === 'core/paragraph' || b.name === 'core/list-item') { paras.push(b); }
				if (b.innerBlocks && b.innerBlocks.length) { walk(b.innerBlocks); }
			});
		}(be.getBlocks()));
		var all = document.createElement('div');
		paras.forEach(function (b) { var d = document.createElement('div'); d.innerHTML = String(b.attributes.content || ''); all.appendChild(d); });
		var placed = 0, used = {}, rest = [];
		items.forEach(function (item) {
			if (hasLink(all, item.url)) { return; }
			var done = false;
			for (var i = 0; i < paras.length && !done; i++) {
				if (used[paras[i].clientId]) { continue; }
				var box = document.createElement('div');
				box.innerHTML = String(paras[i].attributes.content || '');
				if (linkInside(box, item)) {
					bd.updateBlockAttributes(paras[i].clientId, { content: box.innerHTML });
					used[paras[i].clientId] = true; done = true; placed++;
				}
			}
			if (!done) { rest.push(item); }
		});
		if (rest.length && window.wp.blocks && wp.blocks.createBlock) {
			bd.insertBlocks(wp.blocks.createBlock('core/paragraph', { content: relatedHtml(rest).innerHTML }));
		}
		return { placed: placed, related: rest.length };
	}

	function insertHtmlRoot(root, items, appendFn) {
		var placed = 0, rest = [];
		items.forEach(function (item) {
			if (hasLink(root, item.url)) { return; }
			var paras = root.querySelectorAll('p, li'), done = false;
			for (var i = 0; i < paras.length && !done; i++) {
				if (paras[i].querySelector('a[data-pr-linked]')) { continue; }
				if (linkInside(paras[i], item)) { paras[i].setAttribute('data-pr-linked', '1'); done = true; placed++; }
			}
			if (!done) { rest.push(item); }
		});
		root.querySelectorAll('[data-pr-linked]').forEach(function (el) { el.removeAttribute('data-pr-linked'); });
		if (rest.length) { appendFn(relatedHtml(rest)); }
		return { placed: placed, related: rest.length };
	}

	/**
	 * @param {Array<{url:string,title:string,anchors?:string[]}>} items
	 * @return {{placed:number,related:number,message:string,ok:boolean}}
	 */
	function insert(items) {
		items = (items || []).filter(function (i) { return i && i.url && i.title; });
		var res = null;
		try {
			if (blockEditor() && blockEditor().getBlocks().length) {
				res = insertBlocks(items);
			} else {
				var mce = window.tinymce && tinymce.get('content');
				if (mce && !mce.isHidden()) {
					var body = mce.getBody();
					res = insertHtmlRoot(body, items, function (p) { body.appendChild(mce.getDoc().importNode(p, true)); });
					mce.undoManager.add(); mce.setDirty(true); mce.fire('change');
				} else {
					var ta = document.getElementById('content');
					if (ta) {
						var box = document.createElement('div'); box.innerHTML = ta.value;
						res = insertHtmlRoot(box, items, function (p) { box.appendChild(p); });
						ta.value = box.innerHTML; ta.dispatchEvent(new Event('input', { bubbles: true }));
					}
				}
			}
		} catch (e) { if (window.console) { console.warn('PublishRank link insert failed', e); } }
		if (!res) { return { placed: 0, related: 0, ok: false, message: cfg.noEditor || 'Could not reach the editor.' }; }
		var msg = (res.placed + res.related) === 0 ? (cfg.none || '') : String(cfg.result || '%1$d placed, %2$d related').replace('%1$d', res.placed).replace('%2$d', res.related);
		return { placed: res.placed, related: res.related, ok: true, message: msg };
	}

	window.PublishRankLinks = { insert: insert };
}(window, document));
