/* PublishRank Article SEO Intelligence — editor integration + fix studio.
 * Single initialization (duplicate-safe), debounced live analysis, stale-result
 * protection, Apply/Regenerate/Cancel suggestion flows, alt-text save,
 * internal-link copy, floating widget sync. jQuery + vanilla, no frameworks. */
(function ($) {
  'use strict';
  if (window.__prIntelInit) { return; }
  window.__prIntelInit = true;

  var I18N = (window.publishrankIntel && publishrankIntel.i18n) || {};
  var t = function (k, fb) { return I18N[k] || fb; };
  var reduceMotion = false;
  try { reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

  var analyzeSeq = 0, suggestSeq = 0, timer = 0, variants = {};

  /* ---------------- Editor value collection ---------------- */
  function getValue(selectors) {
    for (var i = 0; i < selectors.length; i++) {
      var el = document.querySelector(selectors[i]);
      if (el && typeof el.value === 'string') { return el.value; }
    }
    return '';
  }
  function getPostId() {
    var card = document.getElementById('publishrank-editor-score');
    if (card && card.getAttribute('data-post-id')) { return parseInt(card.getAttribute('data-post-id'), 10) || 0; }
    return parseInt((window.publishrankIntel && publishrankIntel.postId) || 0, 10) || 0;
  }
  function getEditorContent() {
    try {
      if (window.tinymce && tinymce.editors) {
        var ed = tinymce.get('content');
        if (ed && !ed.isHidden() && typeof ed.getContent === 'function') { return ed.getContent(); }
      }
    } catch (e) {}
    try {
      if (window.wp && wp.data && wp.data.select) {
        var c = wp.data.select('core/editor').getEditedPostContent();
        if (typeof c === 'string') { return c; }
      }
    } catch (e) {}
    var classic = document.getElementById('content');
    return (classic && typeof classic.value === 'string') ? classic.value : '';
  }
  function getBlockAttr(name) {
    try { if (window.wp && wp.data && wp.data.select) { return wp.data.select('core/editor').getEditedPostAttribute(name) || ''; } } catch (e) {}
    return '';
  }
  function payload() {
    return {
      action: publishrankIntel.action, nonce: publishrankIntel.nonce, post_id: getPostId(),
      title: getValue(['#title', '.editor-post-title__input']) || getBlockAttr('title'),
      content: getEditorContent(),
      excerpt: getValue(['#excerpt', '[name="post_excerpt"]']) || getBlockAttr('excerpt'),
      slug: getValue(['#post_name', '#post_name_display']),
      seo_title: getValue(['#publishrank_seo_title', '#publishrank_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]']),
      description: getValue(['#publishrank_meta_description', '#publishrank_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]']),
      keyphrase: getValue(['#publishrank_focus_keyword', '#publishrank_keyphrase', '[name="publishrank_focus_keyword"]', '#_publishrank_seo_focus_keyword', '[name="_publishrank_seo_focus_keyword"]']),
      has_thumbnail: !!document.querySelector('#postimagediv .inside img,.editor-post-featured-image__preview img')
    };
  }

  /* ---------------- Widget helpers ---------------- */
  function setRing(el, score, rating) {
    if (!el) { return; }
    var circles = el.querySelectorAll('.pr-ring__val');
    for (var i = 0; i < circles.length; i++) {
      var c = parseFloat(circles[i].getAttribute('stroke-dasharray')) || 326.73;
      circles[i].setAttribute('stroke-dashoffset', String(c * (1 - score / 100)));
      circles[i].setAttribute('class', 'pr-ring__val pr-ring__val--' + rating);
    }
  }
  function statusText(score) {
    return score >= 80 ? 'Well optimized' : (score >= 55 ? 'Needs improvement' : 'Needs work');
  }

  function paint(data) {
    if (!data) { return; }
    var score = parseInt(data.score, 10) || 0;
    var rating = score >= 80 ? 'good' : (score >= 55 ? 'ok' : 'bad');
    var $box = $('#pr-intel');
    $('#pr-intel-body').html(data.html || '');
    $box.attr('data-score', score);

    /* Floating widget sync */
    var card = document.getElementById('publishrank-editor-score');
	    if (card) {
	      card.setAttribute('data-score', String(score));
	      var num = card.querySelector('.pr-editor-score-card__number');
	      if (num) { num.textContent = String(score); }
	      var ringText = card.querySelector('.pr-ring__text');
	      if (ringText) { ringText.textContent = String(score); }
	      setRing(card, score, rating);
      card.className = card.className.replace(/\bis-(good|ok|bad)\b/g, '').trim();
      card.classList.add('is-' + rating);
      var st = card.querySelector('.pr-editor-score-card__status-text');
      if (st) { st.textContent = statusText(score); }
      if (data.stats) {
        var w = card.querySelector('.pr-editor-score-card__words'); if (w) { w.textContent = data.stats.words; }
        var im = card.querySelector('.pr-editor-score-card__images'); if (im) { im.textContent = data.stats.images; }
        var kw = card.querySelector('.pr-editor-score-card__keyword');
        if (kw) {
          if (data.stats.focus_keyword) { kw.textContent = data.stats.focus_keyword; kw.parentNode.classList.add('has-keyphrase'); }
          else { kw.textContent = '—'; kw.parentNode.classList.remove('has-keyphrase'); }
        }
        var hi = card.querySelector('.pr-editor-score-card__issues b');
        if (hi) { hi.textContent = String(data.stats.issues_high || 0); }
      }
      var at = card.querySelector('.pr-editor-score-card__at');
      if (at) { at.textContent = t('justNow', 'just now'); }
    }
  }

  /* ---------------- Analysis (debounced, stale-safe) ---------------- */
  function analyze() {
    var $box = $('#pr-intel'), $card = $('#publishrank-editor-score');
    if ((!$box.length && !$card.length) || !window.publishrankIntel) { return; }
    var seq = ++analyzeSeq;
    $box.add($card).addClass('pr-intel__busy');
    $('#pr-intel-refresh,#publishrank-editor-score-refresh').prop('disabled', true).each(function () { $(this).text(publishrankIntel.analyzing); });
    $.post(publishrankIntel.ajaxUrl, payload())
      .done(function (r) { if (seq !== analyzeSeq) { return; } if (r && r.success) { paint(r.data); } else if (window.console) { console.warn('PublishRank analyzer rejected the request', r); } })
      .fail(function (xhr) { if (seq === analyzeSeq && window.console) { console.warn('PublishRank analyzer request failed', xhr.status); } })
      .always(function () {
        if (seq !== analyzeSeq) { return; }
        $box.add($card).removeClass('pr-intel__busy');
        $('#pr-intel-refresh').prop('disabled', false).text('Re-analyze');
        $('#publishrank-editor-score-refresh').prop('disabled', false).text('Analyze article');
      });
  }
  function schedule() { window.clearTimeout(timer); timer = window.setTimeout(analyze, 600); }

  /* ---------------- Apply / regenerate ---------------- */
  function feedback($btn, ok, msg) {
    var $f = $btn.closest('.pr-studio__row, .pr-studio__block').find('.pr-studio__feedback').first();
    $f.text(msg).css('color', ok ? '#008a20' : '#b32d2e');
    window.setTimeout(function () { $f.text(''); }, 4000);
  }
  function setEditorField(kind, value) {
    var map = {
      keyphrase: ['#publishrank_focus_keyword', '#publishrank_keyphrase', '[name="publishrank_focus_keyword"]', '#_publishrank_seo_focus_keyword', '[name="_publishrank_seo_focus_keyword"]'],
      description: ['#publishrank_meta_description', '#publishrank_description', '[name="publishrank_seo_description"]', '#_publishrank_seo_description', '[name="_publishrank_seo_description"]'],
      seo_title: ['#publishrank_seo_title', '#publishrank_title', '[name="publishrank_seo_title"]', '#_publishrank_seo_title', '[name="_publishrank_seo_title"]']
    };
    var sel = map[kind] || [];
    for (var i = 0; i < sel.length; i++) {
      var el = document.querySelector(sel[i]);
      if (el) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }
    }
    return false;
  }
  function applyValue($btn) {
    var field = $btn.data('field');
    var $row = $btn.closest('.pr-studio__row');
    var value = $.trim($row.find('.pr-studio__input, .pr-studio__textarea').first().val() || '');
    if (!value) { feedback($btn, false, t('empty', 'Enter a value first.')); return; }
    var data = {
      action: publishrankIntel.applyAction, nonce: publishrankIntel.applyNonce,
      post_id: getPostId(), field: field, value: value
    };
    var att = parseInt($btn.data('attachment'), 10) || 0;
    if (att) { data.attachment_id = att; }
    $btn.prop('disabled', true);
    $.post(publishrankIntel.ajaxUrl, data)
      .done(function (r) {
        if (r && r.success) {
          feedback($btn, true, t('applied', 'Saved.'));
          if (field !== 'alt') { setEditorField(field, value); }
          analyze();
        } else {
          feedback($btn, false, (r && r.data && r.data.message) || t('applyFail', 'Could not save the value. Please try again.'));
        }
      })
      .fail(function () { feedback($btn, false, t('applyFail', 'Could not save the value. Please try again.')); })
      .always(function () { $btn.prop('disabled', false); });
  }
  function regenerate($btn) {
    var kind = $btn.data('kind');
    variants[kind] = (variants[kind] || 0) + 1;
    var seq = ++suggestSeq;
    $btn.prop('disabled', true);
    $.post(publishrankIntel.ajaxUrl, $.extend(payload(), {
      action: publishrankIntel.suggestAction, nonce: publishrankIntel.suggestNonce,
      kind: kind, variant: variants[kind]
    })).done(function (r) {
      if (seq !== suggestSeq || !r || !r.success) { return; }
      var items = r.data.items || [];
      if (kind === 'description') {
        if (items.text) { $('#pr-studio-description').val(items.text).trigger('input'); }
      } else if (kind === 'headline') {
        if (items.text) { $('#pr-studio-headline').val(items.text); }
      } else if (kind === 'keyphrase') {
        var $chips = $btn.closest('.pr-studio__block').find('.pr-studio__chips').empty();
        if (items.length) {
          $.each(items, function (i, k) {
            $('<button type="button" class="button button-small pr-studio__chip"/>')
              .attr('data-phrase', k.phrase).text(k.phrase + ' ×' + k.count).appendTo($chips);
          });
        } else { $chips.append('<em>' + t('noSuggest', 'No reliable suggestion could be derived from the current content.') + '</em>'); }
      }
    }).always(function () { $btn.prop('disabled', false); });
  }
  function copyLink($btn) {
    var url = $btn.data('url') || '';
    var done = function (ok) {
      var old = $btn.text();
      $btn.text(ok ? t('copied', 'Link copied.') : t('copyFail', 'Copy failed — select the URL manually.'));
      window.setTimeout(function () { $btn.text(old); }, 2500);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () { done(true); }, function () { legacyCopy(url) ? done(true) : done(false); });
    } else { done(legacyCopy(url)); }
  }
  function legacyCopy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch (e) { return false; }
  }

  /* ---------------- Events (delegated, namespaced, single bind) ---------------- */
  $(document).on('click.prIntel', '#pr-intel-refresh,#publishrank-editor-score-refresh', function (e) { e.preventDefault(); analyze(); });

  $(document).on('click.prIntel', '#publishrank-editor-score-open', function (e) {
    e.preventDefault();
    var box = document.getElementById('pr-intel');
    if (box) {
      box.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
      box.classList.add('pr-intel__flash');
      window.setTimeout(function () { box.classList.remove('pr-intel__flash'); }, 1600);
    }
  });

  $(document).on('click.prIntel', '.pr-studio__chip', function () {
    var phrase = $(this).data('phrase');
    var $input = $(this).closest('.pr-studio__block').find('.pr-studio__input').first();
    $input.val(phrase).trigger('input').trigger('focus');
  });
  $(document).on('click.prIntel', '.pr-studio__apply', function () { applyValue($(this)); });
  $(document).on('click.prIntel', '.pr-studio__regen', function () { regenerate($(this)); });
  $(document).on('click.prIntel', '.pr-studio__cancel', function () {
    var target = document.getElementById($(this).data('target'));
    if (target) { target.value = target.defaultValue || ''; $(target).trigger('input'); }
  });
  $(document).on('click.prIntel', '.pr-studio__copy', function () { copyLink($(this)); });
  $(document).on('input.prIntel', '#pr-studio-description', function () {
    var n = $(this).val().length;
    $('#pr-studio-description-count').text(n + ' characters (target 70–160)');
  });

  /* "Fix" buttons in the priority list: jump to the matching studio block */
  $(document).on('click.prIntel', '.pr-issue__fix', function () {
    var kind = $(this).data('kind');
    var $block = $('.pr-studio__block[data-studio="' + kind + '"]').first();
    if ($block.length) {
      $block[0].scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
      $block.addClass('pr-studio__flash');
      window.setTimeout(function () { $block.removeClass('pr-studio__flash'); }, 1600);
      var $input = $block.find('.pr-studio__input, .pr-studio__textarea').first();
      if ($input.length) { $input.trigger('focus'); }
    }
  });

  /* Legacy suggestion buttons (kept for backward compatibility) */
  $(document).on('click.prIntel', '.pr-intel__use-keyphrase', function () {
    setEditorField('keyphrase', $(this).data('phrase')); schedule();
  });
  $(document).on('click.prIntel', '.pr-intel__use-description', function () {
    setEditorField('description', $(this).data('text')); schedule();
  });

  /* Live re-analysis with debounce */
  $(document).on('input.prIntel change.prIntel',
    '#title,.editor-post-title__input,#content,#excerpt,#publishrank_seo_title,#publishrank_meta_description,#publishrank_focus_keyword,[name="publishrank_seo_title"],[name="publishrank_seo_description"],[name="publishrank_focus_keyword"],[name="_publishrank_seo_title"],[name="_publishrank_seo_description"],[name="_publishrank_seo_focus_keyword"]',
    schedule);

  /* Widget collapse toggle */
  $(document).on('click.prIntel', '.pr-editor-score-card__toggle', function () {
    var card = document.getElementById('publishrank-editor-score');
    if (!card) { return; }
    var collapsed = card.classList.toggle('is-collapsed');
    this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    try { window.localStorage.setItem('prScoreCardCollapsed', collapsed ? '1' : '0'); } catch (e) {}
  });

  $(function () {
    var card = document.getElementById('publishrank-editor-score');
    if (card) {
      var n = parseInt(card.getAttribute('data-score') || '0', 10);
      card.classList.add(n >= 80 ? 'is-good' : (n >= 55 ? 'is-ok' : 'is-bad'));
      try { if (window.localStorage.getItem('prScoreCardCollapsed') === '1') { card.classList.add('is-collapsed'); } } catch (e) {}
      setRing(card, n, n >= 80 ? 'good' : (n >= 55 ? 'ok' : 'bad'));
      var st = card.querySelector('.pr-editor-score-card__status-text');
      if (st) { st.textContent = statusText(n); }
    }
  });
})(jQuery);
