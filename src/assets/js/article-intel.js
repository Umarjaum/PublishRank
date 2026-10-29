/* PublishRank Article SEO Intelligence — editor integration + fix studio.
 * Single initialization (duplicate-safe), debounced live analysis, stale-result
 * protection, Apply/Regenerate/Cancel suggestion flows, alt-text save,
 * and accessible internal-link copy. jQuery + vanilla, no frameworks. */
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
    var postId = parseInt((window.publishrankIntel && publishrankIntel.postId) || 0, 10) || parseInt((document.getElementById('post_ID') || {}).value || 0, 10) || 0;
    try { if (!postId && window.wp && wp.data && wp.data.select) { postId = parseInt(wp.data.select('core/editor').getCurrentPostId(), 10) || 0; } } catch (e) {}
    return postId;
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

  function paint(data) {
    if (!data) { return; }
    var score = parseInt(data.score, 10) || 0;
    var $box = $('#pr-intel');
    /* Keep anything the editor has typed into the fix assistant across a live re-analysis. */
    var keep = {};
    $('#pr-intel-body .pr-studio__input, #pr-intel-body .pr-studio__textarea').each(function () {
      if (this.id && $(this).data('dirty')) { keep[this.id] = this.value; }
    });
    $('#pr-intel-body').html(data.html || '');
    $.each(keep, function (id, val) {
      var el = document.getElementById(id);
      if (el) { el.value = val; $(el).data('dirty', 1); $(el).trigger('input'); }
    });
    $box.attr('data-score', score);
    var live = document.getElementById('pr-intel-status');
    var status = score >= 80 ? t('wellOptimized', 'Well optimized') : (score >= 55 ? t('needsImprovement', 'Needs improvement') : t('needsWork', 'Needs work'));
    if (live) { live.textContent = String(t('analysisComplete', 'Analysis complete. SEO score: %1$d out of 100. %2$s.').replace('%1$d', String(score)).replace('%2$s', status)); }
  }

  /* ---------------- Analysis (debounced, stale-safe) ---------------- */
  function analyze() {
    var $box = $('#pr-intel');
    if (!$box.length || !window.publishrankIntel) { return; }
    var seq = ++analyzeSeq;
    $box.addClass('pr-intel__busy');
    $('#pr-intel-body').attr('aria-busy', 'true');
    $('#pr-intel-status').text(publishrankIntel.analyzing);
    $('#pr-intel-refresh').prop('disabled', true).text(publishrankIntel.analyzing);
    $.post(publishrankIntel.ajaxUrl, payload())
      .done(function (r) { if (seq !== analyzeSeq) { return; } if (r && r.success) { paint(r.data); } else { $('#pr-intel-status').text(publishrankIntel.failed); if (window.console) { console.warn('PublishRank analyzer rejected the request', r); } } })
      .fail(function (xhr) { if (seq === analyzeSeq) { $('#pr-intel-status').text(publishrankIntel.failed); if (window.console) { console.warn('PublishRank analyzer request failed', xhr.status); } } })
      .always(function () {
        if (seq !== analyzeSeq) { return; }
        $box.removeClass('pr-intel__busy');
        $('#pr-intel-body').attr('aria-busy', 'false');
        $('#pr-intel-refresh').prop('disabled', false).text(t('reanalyze', 'Re-analyze'));
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
  var META_KEYS = {
    keyphrase: '_publishrank_seo_focus_keyword',
    description: '_publishrank_seo_description',
    seo_title: '_publishrank_seo_title'
  };
  /* Keep the block editor's own post state in step so "Update" does not write the old value back. */
  function editMeta(field, value) {
    try {
      if (META_KEYS[field] && window.wp && wp.data && wp.data.dispatch && wp.data.select('core/editor')) {
        var m = {}; m[META_KEYS[field]] = value;
        wp.data.dispatch('core/editor').editPost({ meta: m });
      }
    } catch (e) {}
  }
  function fileOf(u) {
    return String(u || '').split('#')[0].split('?')[0].split('/').pop().replace(/-\d{2,5}x\d{2,5}(?=\.[a-z0-9]+$)/i, '').toLowerCase();
  }
  function sameFile(a, b) { var x = fileOf(a); return x !== '' && x === fileOf(b); }
  /* Write alt text into the image itself (the alt attribute lives in the post content, not in the media library). */
  function setImageAlt(src, attId, value) {
    var done = false;
    try {
      if (window.wp && wp.data && wp.data.select && wp.data.dispatch && wp.data.select('core/block-editor')) {
        var be = wp.data.select('core/block-editor'), bd = wp.data.dispatch('core/block-editor');
        var walk = function (blocks) {
          (blocks || []).forEach(function (b) {
            var a = b.attributes || {};
            if ((b.name === 'core/image' || b.name === 'core/cover') && ((attId && a.id === attId) || (src && sameFile(a.url, src)))) {
              bd.updateBlockAttributes(b.clientId, { alt: value }); done = true;
            } else if (b.name === 'core/media-text' && ((attId && a.mediaId === attId) || (src && sameFile(a.mediaUrl, src)))) {
              bd.updateBlockAttributes(b.clientId, { mediaAlt: value }); done = true;
            }
            if (b.innerBlocks && b.innerBlocks.length) { walk(b.innerBlocks); }
          });
        };
        walk(be.getBlocks());
      }
    } catch (e) {}
    try {
      if (window.tinymce) {
        var ed = tinymce.get('content');
        if (ed && !ed.isHidden()) {
          $(ed.getBody()).find('img').each(function () {
            var cls = ' ' + (this.className || '') + ' ';
            if ((src && sameFile(this.getAttribute('src'), src)) || (attId && cls.indexOf(' wp-image-' + attId + ' ') > -1)) {
              ed.dom.setAttrib(this, 'alt', value); done = true;
            }
          });
          if (done) { ed.setDirty(true); if (ed.save) { ed.save(); } }
        }
      }
    } catch (e) {}
    var ta = document.getElementById('content');
    if (!done && ta && typeof ta.value === 'string' && ta.offsetParent !== null) {
      var html = ta.value;
      var next = html.replace(/<img\b[^>]*>/gi, function (tag) {
        var m = tag.match(/\bsrc\s*=\s*["']([^"']+)["']/i);
        if (!m || !sameFile(m[1], src)) { return tag; }
        var safe = String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        done = true;
        return /\balt\s*=/i.test(tag) ? tag.replace(/\balt\s*=\s*(?:"[^"]*"|'[^']*')/i, 'alt="' + safe + '"') : tag.replace(/<img\b/i, '<img alt="' + safe + '"');
      });
      if (done) { ta.value = next; }
    }
    return done;
  }
  /* Replace the text of the first paragraph (block editor, TinyMCE or the plain textarea). */
  function setFirstParagraph(text) {
    var done = false;
    var escaped = $('<div/>').text(text).html();
    try {
      if (window.wp && wp.data && wp.data.select && wp.data.select('core/block-editor')) {
        var blocks = wp.data.select('core/block-editor').getBlocks();
        for (var i = 0; i < blocks.length; i++) {
          if (blocks[i].name === 'core/paragraph') {
            wp.data.dispatch('core/block-editor').updateBlockAttributes(blocks[i].clientId, { content: escaped });
            return true;
          }
        }
      }
    } catch (e) {}
    try {
      if (window.tinymce) {
        var ed = tinymce.get('content');
        if (ed && !ed.isHidden()) {
          var p = ed.getBody().querySelector('p');
          if (p) { p.textContent = text; ed.setDirty(true); if (ed.save) { ed.save(); } return true; }
        }
      }
    } catch (e) {}
    var ta = document.getElementById('content');
    if (ta && typeof ta.value === 'string' && /<p\b[^>]*>[\s\S]*?<\/p>/i.test(ta.value)) {
      ta.value = ta.value.replace(/(<p\b[^>]*>)[\s\S]*?(<\/p>)/i, function (m, a, b) { return a + escaped + b; });
      done = true;
    }
    return done;
  }
  function findInput($btn) {
    var id = $btn.data('input');
    var $in = id ? $(document.getElementById(id)) : $();
    if (!$in.length) { $in = $btn.closest('.pr-studio__row').find('.pr-studio__input, .pr-studio__textarea').first(); }
    if (!$in.length) { $in = $btn.closest('.pr-studio__block').find('.pr-studio__input, .pr-studio__textarea').first(); }
    return $in;
  }
  function applyValue($btn) {
    var field = $btn.data('field');
    var value = $.trim(findInput($btn).val() || '');
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
          if (field === 'alt') {
            var inEditor = setImageAlt(String($btn.data('src') || ''), att, value);
            feedback($btn, true, inEditor ? t('applied', 'Saved.') : t('altLibraryOnly', 'Saved to the media library. Open the image block and paste the alt text there to finish.'));
          } else {
            feedback($btn, true, t('applied', 'Saved.'));
            setEditorField(field, value);
            editMeta(field, value);
          }
          analyze();
        } else {
          feedback($btn, false, (r && r.data && r.data.message) || t('applyFail', 'Could not save the value. Please try again.'));
        }
      })
      .fail(function () { feedback($btn, false, t('applyFail', 'Could not save the value. Please try again.')); })
      .always(function () { $btn.prop('disabled', false); });
  }
  function applyIntro($btn) {
    var value = $.trim(findInput($btn).val() || '');
    if (!value) { feedback($btn, false, t('empty', 'Enter a value first.')); return; }
    if (setFirstParagraph(value)) {
      feedback($btn, true, t('introInserted', 'First paragraph updated. Review it, then update the post.'));
      schedule();
    } else {
      feedback($btn, false, t('introFail', 'Could not find a first paragraph. Use Copy text and paste it into your content.'));
    }
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
    var $status = $btn.closest('.pr-studio__link').find('.pr-studio__copy-feedback').first();
    var done = function (ok) {
      var old = $btn.text();
      var text = ok ? t('copied', 'Link copied.') : t('copyFail', 'Copy failed — select the URL manually.');
      $btn.text(text);
      if ($status.length) { $status.text(text); }
      window.setTimeout(function () { $btn.text(old); }, 2500);
    };
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () { done(true); }, function () { legacyCopy(url) ? done(true) : done(false); });
      } else { done(legacyCopy(url)); }
    } catch (e) { done(legacyCopy(url)); }
  }
  function legacyCopy(text) {
    var active = document.activeElement;
    try {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('aria-hidden', 'true'); ta.tabIndex = -1; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      if (active && active.focus) { active.focus(); }
      return ok;
    } catch (e) { if (active && active.focus) { active.focus(); } return false; }
  }

  /* ---------------- Events (delegated, namespaced, single bind) ---------------- */
  $(document).on('click.prIntel', '#pr-intel-refresh', function (e) { e.preventDefault(); analyze(); });

  $(document).on('click.prIntel', '.pr-studio__chip', function () {
    var phrase = $(this).data('phrase');
    var $input = $(this).closest('.pr-studio__block').find('.pr-studio__input').first();
    $input.val(phrase).trigger('input').trigger('focus');
  });
  $(document).on('click.prIntel', '.pr-studio__apply', function () { applyValue($(this)); });
  $(document).on('click.prIntel', '.pr-studio__intro-apply', function () { applyIntro($(this)); });
  $(document).on('click.prIntel', '.pr-studio__copy-text', function () {
    var $btn = $(this), text = $.trim(findInput($btn).val() || '');
    if (!text) { feedback($btn, false, t('empty', 'Enter a value first.')); return; }
    var ok = false;
    try { if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text); ok = true; } } catch (e) {}
    if (!ok) { ok = legacyCopy(text); }
    feedback($btn, ok, ok ? t('copied', 'Link copied.').replace(/link/i, 'text') : t('copyFail', 'Copy failed — select the text manually.'));
  });
  $(document).on('input.prIntel', '.pr-studio__input, .pr-studio__textarea', function () { $(this).data('dirty', 1); });
  $(document).on('click.prIntel', '.pr-studio__regen', function () { regenerate($(this)); });
  $(document).on('click.prIntel', '.pr-studio__cancel', function () {
    var target = document.getElementById($(this).data('target'));
    if (target) { target.value = target.defaultValue || ''; $(target).trigger('input'); }
  });
  $(document).on('click.prIntel', '.pr-studio__copy', function () { copyLink($(this)); });
  $(document).on('input.prIntel', '#pr-studio-description', function () {
    var n = $(this).val().length;
    var format = t(n === 1 ? 'characterCountSingle' : 'characterCountPlural', n === 1 ? '%d character (target 70–160)' : '%d characters (target 70–160)');
    $('#pr-studio-description-count').text(String(format).replace('%d', String(n)));
  });

  /* "Fix" buttons in the priority list: jump to the matching studio block */
  $(document).on('click.prIntel', '.pr-issue__fix', function () {
    var kind = $(this).data('kind');
    var $block = $('.pr-studio__block[data-studio="' + kind + '"]').first();
    if (!$block.length) { $block = $('.pr-studio').first(); }
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

})(jQuery);
