(function($){
  'use strict';
  function getValue(selectors){for(var i=0;i<selectors.length;i++){var el=document.querySelector(selectors[i]);if(el&&typeof el.value==='string')return el.value;}return '';}
  function getPostId(){
    var card=document.getElementById('publishrank-editor-score');
    if(card&&card.getAttribute('data-post-id'))return parseInt(card.getAttribute('data-post-id'),10)||0;
    return parseInt(window.publishrankIntel&&publishrankIntel.postId||0,10)||0;
  }
  function getEditorContent(){
    try{
      if(window.tinymce&&tinymce.editors){
        var editor=tinymce.get('content');
        if(editor&&!editor.isHidden()&&typeof editor.getContent==='function')return editor.getContent();
      }
    }catch(e){}
    try{if(window.wp&&wp.data&&wp.data.select){var c=wp.data.select('core/editor').getEditedPostContent();if(typeof c==='string')return c;}}catch(e){}
    var classic=document.getElementById('content');
    return classic&&typeof classic.value==='string'?classic.value:'';
  }
  function payload(){return {
    action:publishrankIntel.action,nonce:publishrankIntel.nonce,post_id:getPostId(),
    title:getValue(['#title','.editor-post-title__input']),content:getEditorContent(),
    slug:getValue(['#post_name','#post_name_display']),
    seo_title:getValue(['#publishrank_seo_title','#publishrank_title','[name="publishrank_seo_title"]']),
    description:getValue(['#publishrank_meta_description','#publishrank_description','[name="publishrank_seo_description"]']),
    keyphrase:getValue(['#publishrank_focus_keyword','#publishrank_keyphrase','[name="publishrank_focus_keyword"]']),
    has_thumbnail:!!document.querySelector('#postimagediv .inside img,.editor-post-featured-image__preview img')
  };}
  function paint(data){
    if(!data)return;
    var score=parseInt(data.score,10)||0;
    $('#pr-intel-body').html(data.html||'');$('#pr-intel').attr('data-score',score);
    var card=$('#publishrank-editor-score');
    if(card.length){card.find('.pr-editor-score-card__number').text(score);card.attr('data-score',score).removeClass('is-good is-ok is-bad').addClass(score>=80?'is-good':(score>=55?'is-ok':'is-bad'));if(data.stats){card.find('.pr-editor-score-card__words').text(data.stats.words);card.find('.pr-editor-score-card__images').text(data.stats.images);card.find('.pr-editor-score-card__keyword').text(data.stats.focus_keyword?(data.stats.focus_keyword+' ('+data.stats.focus_keyword_occurrences+'×)'):'—');}}
  }
  function analyze(){
    var box=$('#pr-intel'), card=$('#publishrank-editor-score');if(!box.length&&!card.length||!window.publishrankIntel)return;
    box.add(card).addClass('pr-intel__busy');$('#pr-intel-refresh,#publishrank-editor-score-refresh').prop('disabled',true).each(function(){$(this).text(publishrankIntel.analyzing);});
    $.post(publishrankIntel.ajaxUrl,payload()).done(function(r){if(r&&r.success)paint(r.data);else if(window.console)console.warn('PublishRank analyzer rejected the request',r);}).fail(function(xhr){if(window.console)console.warn('PublishRank analyzer request failed',xhr.status);}).always(function(){box.add(card).removeClass('pr-intel__busy');$('#pr-intel-refresh').prop('disabled',false).text('Re-analyze');$('#publishrank-editor-score-refresh').prop('disabled',false).text('Analyze article');});
  }
  var timer=0;function schedule(){window.clearTimeout(timer);timer=window.setTimeout(analyze,700);}
  $(document).on('click','#pr-intel-refresh,#publishrank-editor-score-refresh',function(e){e.preventDefault();analyze();});
  $(document).on('input change','#title,.editor-post-title__input,#content,#publishrank_seo_title,#publishrank_meta_description,#publishrank_focus_keyword,[name="publishrank_seo_title"],[name="publishrank_seo_description"],[name="publishrank_focus_keyword"]',schedule);
  $(document).on('click','.pr-intel__use-keyphrase',function(){var phrase=$(this).data('phrase');var el=document.querySelector('#publishrank_focus_keyword,#publishrank_keyphrase,[name="publishrank_focus_keyword"]');if(el){el.value=phrase;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));schedule();}});
  $(document).on('click','.pr-intel__use-description',function(){var text=$(this).data('text');var el=document.querySelector('#publishrank_meta_description,#publishrank_description,[name="publishrank_seo_description"]');if(el){el.value=text;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));schedule();}});
  $(function(){var card=$('#publishrank-editor-score'),n=parseInt(card.attr('data-score')||0,10);card.addClass(n>=80?'is-good':(n>=55?'is-ok':'is-bad'));});
})(jQuery);
