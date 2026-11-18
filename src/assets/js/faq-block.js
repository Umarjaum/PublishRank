/* PublishRank FAQ block (editor). Plain wp.element so no build step is required. */
(function (wp) {
	'use strict';
	if (!wp || !wp.blocks || !wp.blockEditor) { return; }
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var be = wp.blockEditor;
	var c = wp.components;
	var useSelect = wp.data && wp.data.useSelect;
	var SSR = wp.serverSideRender;
	var cfg = window.publishrankFaqBlock || {};

	function uid() { return 'q' + Math.random().toString(36).slice(2, 10); }
	function strip(html) { var d = document.createElement('div'); d.innerHTML = html || ''; return (d.textContent || '').trim(); }

	var TEMPLATES = {
		shop: { label: __('Online shop', 'publishrank'), items: [
			[__('How long does delivery take?', 'publishrank'), __('Describe your usual delivery times and the regions you ship to.', 'publishrank')],
			[__('Can I return an item?', 'publishrank'), __('Explain your return window, the condition items must be in and how refunds are paid.', 'publishrank')],
			[__('Which payment methods do you accept?', 'publishrank'), __('List the payment options available at checkout.', 'publishrank')]
		] },
		service: { label: __('Service business', 'publishrank'), items: [
			[__('Which areas do you serve?', 'publishrank'), __('List the towns or regions you cover.', 'publishrank')],
			[__('How do I book an appointment?', 'publishrank'), __('Explain how customers can book and how far ahead.', 'publishrank')],
			[__('How much does it cost?', 'publishrank'), __('Give typical prices or explain how quotes work.', 'publishrank')]
		] },
		publisher: { label: __('News publisher', 'publishrank'), items: [
			[__('How do you correct mistakes?', 'publishrank'), __('Link to your corrections policy and explain how readers can report an error.', 'publishrank')],
			[__('How can I send a news tip?', 'publishrank'), __('Explain the safest way to contact the newsroom.', 'publishrank')],
			[__('Who funds this publication?', 'publishrank'), __('Describe ownership and funding sources.', 'publishrank')]
		] },
		product: { label: __('Product page', 'publishrank'), items: [
			[__('What is included in the box?', 'publishrank'), __('List everything the customer receives.', 'publishrank')],
			[__('Is there a warranty?', 'publishrank'), __('State the warranty period and what it covers.', 'publishrank')],
			[__('Which sizes are available?', 'publishrank'), __('Explain sizes, dimensions or variants.', 'publishrank')]
		] }
	};

	function toBulk(items) {
		return items.map(function (it) { return 'Q: ' + strip(it.q) + '\nA: ' + (it.a || '').replace(/<\/p>\s*<p>/g, '\n').replace(/<\/?p>/g, '').trim(); }).join('\n\n');
	}
	function fromBulk(text, previous) {
		var out = [];
		text.split(/\n\s*\n/).forEach(function (block) {
			var m = block.match(/^\s*Q:\s*([\s\S]*?)\n\s*A:\s*([\s\S]*)$/i);
			if (!m) { return; }
			var q = m[1].trim(), a = m[2].trim();
			if (!q || !a) { return; }
			var prev = (previous || []).filter(function (p) { return strip(p.q) === q; })[0];
			out.push({ id: prev ? prev.id : uid(), q: q.replace(/[<>]/g, ''), a: a.split(/\n+/).map(function (l) { return l.trim(); }).filter(Boolean).join('<br>') });
		});
		return out;
	}

	function styleVars(a) {
		var s = {};
		if (a.questionColor) { s['--prfaq-q'] = a.questionColor; }
		if (a.answerColor) { s['--prfaq-a'] = a.answerColor; }
		if (a.accentColor) { s['--prfaq-accent'] = a.accentColor; }
		if (a.itemBackground) { s['--prfaq-bg'] = a.itemBackground; }
		if (a.borderColor) { s['--prfaq-border'] = a.borderColor; }
		s['--prfaq-radius'] = (a.radius || 0) + 'px';
		s['--prfaq-gap'] = (a.gap || 0) + 'px';
		s['--prfaq-pad'] = (a.itemPadding || 0) + 'px';
		if (a.questionSize) { s['--prfaq-qsize'] = a.questionSize; }
		if (a.questionWeight) { s['--prfaq-qweight'] = a.questionWeight; }
		return s;
	}

	function issues(items) {
		var seen = {}, list = [];
		items.forEach(function (it, i) {
			var q = strip(it.q).toLowerCase();
			if (!q) { list.push(sprintf(__('Question %d is empty.', 'publishrank'), i + 1)); }
			if (!strip(it.a)) { list.push(sprintf(__('Question %d has no answer, so it is left out of the page and the structured data.', 'publishrank'), i + 1)); }
			if (q && seen[q]) { list.push(sprintf(__('Question %d repeats an earlier question; only the first copy is used in structured data.', 'publishrank'), i + 1)); }
			seen[q] = true;
		});
		return list;
	}

	function Edit(props) {
		var a = props.attributes, set = props.setAttributes;
		var items = Array.isArray(a.items) ? a.items : [];
		var st = useState(-1), drag = st[0], setDrag = st[1];
		var bm = useState(false), bulkOpen = bm[0], setBulkOpen = bm[1];
		var bt = useState(''), bulkText = bt[0], setBulkText = bt[1];
		var pv = useState(false), preview = pv[0], setPreview = pv[1];
		var postType = useSelect ? useSelect(function (s) { var ed = s('core/editor'); return ed && ed.getCurrentPostType ? ed.getCurrentPostType() : ''; }, []) : '';
		var isCollection = postType === cfg.cpt;
		var collections = useSelect ? useSelect(function (s) { return isCollection ? null : s('core').getEntityRecords('postType', cfg.cpt, { per_page: 100, status: 'publish', _fields: 'id,title' }); }, [isCollection]) : null;

		function update(i, key, value) { var next = items.slice(); next[i] = Object.assign({}, next[i], (function () { var o = {}; o[key] = value; return o; })()); set({ items: next }); }
		function move(from, to) { if (to < 0 || to >= items.length || from === to) { return; } var next = items.slice(); var it = next.splice(from, 1)[0]; next.splice(to, 0, it); set({ items: next }); }
		function remove(i) { var next = items.slice(); next.splice(i, 1); set({ items: next }); }
		function duplicate(i) { var next = items.slice(); next.splice(i + 1, 0, Object.assign({}, items[i], { id: uid() })); set({ items: next }); }
		function add() { set({ items: items.concat([{ id: uid(), q: '', a: '' }]) }); }
		function applyTemplate(key) { var t = TEMPLATES[key]; if (!t) { return; } set({ items: items.concat(t.items.map(function (p) { return { id: uid(), q: p[0], a: p[1] }; })) }); }

		var showSSR = !!(SSR && (preview || a.collection));
		var blockProps = be.useBlockProps(showSSR ? {} : { className: 'publishrank-faq publishrank-faq--editor publishrank-faq--' + a.layout + ' publishrank-faq--icon-' + a.icon + ' publishrank-faq--icon-' + a.iconPosition + ' publishrank-faq--sep-' + a.separator, style: styleVars(a) });
		var problems = issues(items);
		var complete = items.filter(function (it) { return strip(it.q) && strip(it.a); }).length;

		var inspector = el(be.InspectorControls, null,
			!isCollection && el(c.PanelBody, { title: __('Source', 'publishrank'), initialOpen: !!a.collection },
				el(c.SelectControl, {
					label: __('Questions come from', 'publishrank'),
					value: String(a.collection || 0),
					options: [{ value: '0', label: __('This block', 'publishrank') }].concat((collections || []).map(function (p) { return { value: String(p.id), label: (p.title && p.title.rendered) ? strip(p.title.rendered) : ('#' + p.id) }; })),
					onChange: function (v) { set({ collection: parseInt(v, 10) || 0 }); },
					help: __('Pick a reusable FAQ collection to show the same questions on many pages. Edit collections under PublishRank → FAQ Collections.', 'publishrank')
				})
			),
			el(c.PanelBody, { title: __('Heading', 'publishrank'), initialOpen: false },
				el(c.TextControl, { label: __('Section title', 'publishrank'), value: a.title, onChange: function (v) { set({ title: v }); }, help: __('Optional, e.g. "Frequently asked questions".', 'publishrank') }),
				el(c.SelectControl, { label: __('Title level', 'publishrank'), value: String(a.titleLevel), options: [2, 3, 4].map(function (n) { return { value: String(n), label: 'H' + n }; }), onChange: function (v) { set({ titleLevel: parseInt(v, 10) }); } }),
				el(c.SelectControl, { label: __('Question heading level', 'publishrank'), value: a.questionLevel, options: [{ value: 'none', label: __('None (plain text)', 'publishrank') }, { value: 'h3', label: 'H3' }, { value: 'h4', label: 'H4' }], onChange: function (v) { set({ questionLevel: v }); }, help: __('Headings help screen-reader users jump between questions.', 'publishrank') })
			),
			el(c.PanelBody, { title: __('Layout and behaviour', 'publishrank'), initialOpen: true },
				el(c.SelectControl, { label: __('Layout', 'publishrank'), value: a.layout, options: [{ value: 'accordion', label: __('Accordion (expand / collapse)', 'publishrank') }, { value: 'list', label: __('List (all answers visible)', 'publishrank') }], onChange: function (v) { set({ layout: v }); } }),
				a.layout === 'accordion' && el(Fragment, null,
					el(c.SelectControl, { label: __('Open on load', 'publishrank'), value: a.open, options: [{ value: 'first', label: __('First question', 'publishrank') }, { value: 'all', label: __('All questions', 'publishrank') }, { value: 'none', label: __('None', 'publishrank') }], onChange: function (v) { set({ open: v }); } }),
					el(c.ToggleControl, { label: __('Keep only one answer open', 'publishrank'), checked: !!a.singleOpen, onChange: function (v) { set({ singleOpen: v }); } }),
					el(c.ToggleControl, { label: __('Show a search box', 'publishrank'), checked: !!a.showSearch, onChange: function (v) { set({ showSearch: v }); } }),
					el(c.ToggleControl, { label: __('Show "Expand all / Collapse all"', 'publishrank'), checked: !!a.showControls, onChange: function (v) { set({ showControls: v }); } }),
					el(c.SelectControl, { label: __('Icon', 'publishrank'), value: a.icon, options: [{ value: 'chevron', label: __('Chevron', 'publishrank') }, { value: 'plus', label: __('Plus / minus', 'publishrank') }, { value: 'caret', label: __('Triangle', 'publishrank') }, { value: 'none', label: __('No icon', 'publishrank') }], onChange: function (v) { set({ icon: v }); } }),
					a.icon !== 'none' && el(c.SelectControl, { label: __('Icon position', 'publishrank'), value: a.iconPosition, options: [{ value: 'end', label: __('After the question', 'publishrank') }, { value: 'start', label: __('Before the question', 'publishrank') }], onChange: function (v) { set({ iconPosition: v }); } })
				)
			),
			el(c.PanelBody, { title: __('Style', 'publishrank'), initialOpen: false },
				el(c.SelectControl, { label: __('Separator', 'publishrank'), value: a.separator, options: [{ value: 'card', label: __('Cards', 'publishrank') }, { value: 'line', label: __('Lines', 'publishrank') }, { value: 'dashed', label: __('Dashed lines', 'publishrank') }, { value: 'none', label: __('None', 'publishrank') }], onChange: function (v) { set({ separator: v }); } }),
				el(c.RangeControl, { label: __('Corner radius', 'publishrank'), value: a.radius, min: 0, max: 40, onChange: function (v) { set({ radius: v || 0 }); } }),
				el(c.RangeControl, { label: __('Space between questions', 'publishrank'), value: a.gap, min: 0, max: 64, onChange: function (v) { set({ gap: v || 0 }); } }),
				el(c.RangeControl, { label: __('Inner padding', 'publishrank'), value: a.itemPadding, min: 0, max: 64, onChange: function (v) { set({ itemPadding: v || 0 }); } }),
				el(c.SelectControl, { label: __('Question size', 'publishrank'), value: a.questionSize, options: [{ value: '', label: __('Theme default', 'publishrank') }, { value: '0.95rem', label: __('Small', 'publishrank') }, { value: '1.1rem', label: __('Medium', 'publishrank') }, { value: '1.3rem', label: __('Large', 'publishrank') }], onChange: function (v) { set({ questionSize: v }); } }),
				el(c.SelectControl, { label: __('Question weight', 'publishrank'), value: a.questionWeight, options: [{ value: '', label: __('Default', 'publishrank') }, { value: '400', label: __('Regular', 'publishrank') }, { value: '600', label: __('Semi-bold', 'publishrank') }, { value: '800', label: __('Bold', 'publishrank') }], onChange: function (v) { set({ questionWeight: v }); } })
			),
			be.PanelColorSettings && el(be.PanelColorSettings, {
				title: __('FAQ colours', 'publishrank'), initialOpen: false,
				colorSettings: [
					{ label: __('Questions', 'publishrank'), value: a.questionColor, onChange: function (v) { set({ questionColor: v || '' }); } },
					{ label: __('Answers', 'publishrank'), value: a.answerColor, onChange: function (v) { set({ answerColor: v || '' }); } },
					{ label: __('Icon / accent', 'publishrank'), value: a.accentColor, onChange: function (v) { set({ accentColor: v || '' }); } },
					{ label: __('Item background', 'publishrank'), value: a.itemBackground, onChange: function (v) { set({ itemBackground: v || '' }); } },
					{ label: __('Borders', 'publishrank'), value: a.borderColor, onChange: function (v) { set({ borderColor: v || '' }); } }
				]
			}),
			el(c.PanelBody, { title: __('Search appearance', 'publishrank'), initialOpen: false },
				el(c.ToggleControl, { label: __('Add FAQ structured data', 'publishrank'), checked: a.schema !== false, onChange: function (v) { set({ schema: v }); } }),
				el('p', { className: 'components-base-control__help' }, cfg.schemaOn ? __('PublishRank marks this page as an FAQPage using exactly the questions shown here, once per page. Google currently shows FAQ rich results only for a limited set of sites, so markup does not guarantee a rich result.', 'publishrank') : __('FAQ structured data is switched off in PublishRank → Modules, so no FAQ markup will be added.', 'publishrank'))
			)
		);

		var toolbar = el(be.BlockControls, null,
			el(c.ToolbarGroup, null,
				el(c.ToolbarButton, { icon: 'plus-alt2', label: __('Add question', 'publishrank'), onClick: add, disabled: !!a.collection }),
				el(c.ToolbarButton, { icon: 'editor-ul', label: __('Bulk edit', 'publishrank'), onClick: function () { setBulkText(toBulk(items)); setBulkOpen(true); }, disabled: !!a.collection }),
				SSR && el(c.ToolbarButton, { icon: preview ? 'edit' : 'visibility', label: preview ? __('Back to editing', 'publishrank') : __('Preview as visitors see it', 'publishrank'), isPressed: preview, onClick: function () { setPreview(!preview); } })
			)
		);

		var bulk = bulkOpen && el(c.Modal, { title: __('Bulk edit questions', 'publishrank'), onRequestClose: function () { setBulkOpen(false); }, className: 'publishrank-faq-bulk' },
			el('p', null, __('One question per block: start with "Q:", put the answer on the next line after "A:", and leave an empty line between questions. Paste a whole list to import it.', 'publishrank')),
			el(c.TextareaControl, { label: __('Questions and answers', 'publishrank'), value: bulkText, rows: 16, onChange: setBulkText }),
			el('div', { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end' } },
				el(c.Button, { variant: 'tertiary', onClick: function () { setBulkOpen(false); } }, __('Cancel', 'publishrank')),
				el(c.Button, { variant: 'primary', onClick: function () { set({ items: fromBulk(bulkText, items) }); setBulkOpen(false); } }, __('Apply', 'publishrank'))
			)
		);

		if (showSSR) {
			return el(Fragment, null, toolbar, inspector, el('div', blockProps, el(SSR, { block: 'publishrank/faq', attributes: a, EmptyResponsePlaceholder: function () { return el(c.Notice, { status: 'warning', isDismissible: false }, a.collection ? __('This collection has no complete questions yet, or it is not published.', 'publishrank') : __('Add at least one question with an answer.', 'publishrank')); } })));
		}

		var body;
		if (!items.length) {
			body = el(c.Placeholder, { icon: 'editor-help', label: __('FAQ', 'publishrank'), instructions: __('Add questions one by one, start from a template, or paste a list with Bulk edit.', 'publishrank') },
				el(c.Button, { variant: 'primary', onClick: add }, __('Add first question', 'publishrank')),
				el(c.SelectControl, { label: __('Start from a template', 'publishrank'), hideLabelFromVision: true, value: '', options: [{ value: '', label: __('Start from a template…', 'publishrank') }].concat(Object.keys(TEMPLATES).map(function (k) { return { value: k, label: TEMPLATES[k].label }; })), onChange: applyTemplate }),
				el(c.Button, { variant: 'secondary', onClick: function () { setBulkText(''); setBulkOpen(true); } }, __('Paste a list', 'publishrank'))
			);
		} else {
			body = el(Fragment, null,
				a.title ? el('h' + a.titleLevel, { className: 'publishrank-faq__title' }, a.title) : null,
				el('ol', { className: 'publishrank-faq__items publishrank-faq__editor-list' },
					items.map(function (it, i) {
						return el('li', {
							key: it.id || i,
							className: 'publishrank-faq__item is-open' + (drag === i ? ' is-dragging' : ''),
							onDragOver: function (e) { if (drag > -1) { e.preventDefault(); } },
							onDrop: function (e) { e.preventDefault(); if (drag > -1) { move(drag, i); } setDrag(-1); }
						},
							el('div', { className: 'publishrank-faq__row' },
								el('span', { className: 'publishrank-faq__handle', draggable: true, title: __('Drag to reorder', 'publishrank'), 'aria-hidden': 'true', onDragStart: function (e) { setDrag(i); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(i)); } catch (x) {} }, onDragEnd: function () { setDrag(-1); } }, '⋮⋮'),
								el(be.RichText, { tagName: 'span', className: 'publishrank-faq__q', value: it.q, allowedFormats: [], placeholder: __('Question', 'publishrank'), 'aria-label': sprintf(__('Question %d', 'publishrank'), i + 1), onChange: function (v) { update(i, 'q', v); } }),
								el('span', { className: 'publishrank-faq__item-actions' },
									el(c.Button, { icon: 'arrow-up-alt2', size: 'small', label: __('Move up', 'publishrank'), disabled: i === 0, onClick: function () { move(i, i - 1); } }),
									el(c.Button, { icon: 'arrow-down-alt2', size: 'small', label: __('Move down', 'publishrank'), disabled: i === items.length - 1, onClick: function () { move(i, i + 1); } }),
									el(c.Button, { icon: 'admin-page', size: 'small', label: __('Duplicate', 'publishrank'), onClick: function () { duplicate(i); } }),
									el(c.Button, { icon: 'trash', size: 'small', isDestructive: true, label: __('Remove question', 'publishrank'), onClick: function () { remove(i); } })
								)
							),
							el(be.RichText, { tagName: 'div', className: 'publishrank-faq__answer', value: it.a, allowedFormats: ['core/bold', 'core/italic', 'core/link'], placeholder: __('Answer', 'publishrank'), 'aria-label': sprintf(__('Answer %d', 'publishrank'), i + 1), onChange: function (v) { update(i, 'a', v); } })
						);
					})
				),
				el('div', { className: 'publishrank-faq__editor-footer' },
					el(c.Button, { variant: 'secondary', icon: 'plus-alt2', onClick: add }, __('Add question', 'publishrank')),
					el('span', { className: 'publishrank-faq__count' }, sprintf(__('%1$d of %2$d questions complete', 'publishrank'), complete, items.length))
				),
				problems.length ? el(c.Notice, { status: 'warning', isDismissible: false, className: 'publishrank-faq__issues' }, el('ul', null, problems.slice(0, 5).map(function (p, k) { return el('li', { key: k }, p); }))) : null
			);
		}
		return el(Fragment, null, toolbar, inspector, bulk, el('div', blockProps, body));
	}

	wp.blocks.registerBlockType('publishrank/faq', {
		apiVersion: 3,
		title: __('FAQ', 'publishrank'),
		edit: Edit,
		save: function () { return null; },
		example: { attributes: { items: [{ id: 'qa', q: __('How do I reset my password?', 'publishrank'), a: __('Use the "Lost your password?" link on the sign-in page.', 'publishrank') }, { id: 'qb', q: __('Can I change my plan?', 'publishrank'), a: __('Yes, at any time from your account page.', 'publishrank') }] } }
	});
}(window.wp));
