(function ($) {
	'use strict';
	var cfg = window.publishrankKeywordFields || {};
	var timer = 0;
	var request = null;

	function panel() { return document.querySelector('[data-publishrank-keyword-fields]'); }
	function status(text, error) {
		var el = document.querySelector('[data-publishrank-keyword-status]');
		if (!el) { return; }
		el.textContent = text || '';
		el.classList.toggle('is-error', !!error);
	}
	function value(key) {
		var el = document.getElementById(key);
		return el && typeof el.value === 'string' ? el.value : '';
	}
	function refreshSummary() {
		var target = document.querySelector('[data-publishrank-resolved-summary]');
		if (!target || !cfg.ajaxUrl || !cfg.summaryNonce) { return; }
		$.post(cfg.ajaxUrl, {
			action: cfg.summaryAction || 'publishrank_refresh_resolved_summary',
			nonce: cfg.summaryNonce,
			post_id: target.getAttribute('data-post-id') || cfg.postId || 0
		}).done(function (response) {
			if (response && response.success && response.data && typeof response.data.html === 'string') {
				// Update in place: replacing the node dropped the SEO dock tab state
				// (pr-seodock-off) and showed the summary on every tab.
				target.innerHTML = response.data.html;
			}
		});
	}
	function save() {
		var box = panel();
		if (!box || !cfg.ajaxUrl || !cfg.nonce) { return; }
		if (request && request.readyState !== 4) { request.abort(); }
		status(cfg.saving || 'Saving…', false);
		request = $.post(cfg.ajaxUrl, {
			action: 'publishrank_save_keyword_fields',
			nonce: cfg.nonce,
			post_id: box.getAttribute('data-post-id') || cfg.postId || 0,
			_publishrank_seo_focus_keyword: value('_publishrank_seo_focus_keyword'),
			_publishrank_seo_secondary_keyphrases: value('_publishrank_seo_secondary_keyphrases'),
			_publishrank_seo_keyphrase_synonyms: value('_publishrank_seo_keyphrase_synonyms'),
			_publishrank_seo_related_terms: value('_publishrank_seo_related_terms')
		}).done(function (response) {
			if (!response || !response.success) {
				status(cfg.failed || 'Could not save yet.', true);
				return;
			}
				status(cfg.saved || 'Saved automatically', false);
				refreshSummary();
			try {
				if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
					wp.data.dispatch('core/editor').editPost({ meta: {
						_publishrank_seo_focus_keyword: value('_publishrank_seo_focus_keyword'),
						_publishrank_seo_secondary_keyphrases: value('_publishrank_seo_secondary_keyphrases').split(/[\r\n,;،؛]+/).map(function (x) { return x.trim(); }).filter(Boolean),
						_publishrank_seo_keyphrase_synonyms: value('_publishrank_seo_keyphrase_synonyms').split(/[\r\n,;،؛]+/).map(function (x) { return x.trim(); }).filter(Boolean),
						_publishrank_seo_related_terms: value('_publishrank_seo_related_terms').split(/[\r\n,;،؛]+/).map(function (x) { return x.trim(); }).filter(Boolean)
					} });
				}
			} catch (e) {}
			$(document).trigger('publishrank:keyword-fields-saved');
		}).fail(function (xhr, textStatus) {
			if (textStatus !== 'abort') { status(cfg.failed || 'Could not save yet.', true); }
		});
	}
	function schedule() {
		window.clearTimeout(timer);
		timer = window.setTimeout(save, 650);
	}
	$(document).on('input.publishrankKeyword change.publishrankKeyword', '[data-publishrank-keyword-fields] input, [data-publishrank-keyword-fields] textarea', schedule);
	$(document).on('publishrank:analysis-updated publishrank:keyword-fields-saved', refreshSummary);
})(jQuery);
