(function($){
  'use strict';

  var metaKeys={keyphrase:'_publishrank_seo_focus_keyword',seo_title:'_publishrank_seo_title',description:'_publishrank_seo_description'};
  var activeRequest=null,requestNumber=0,timer=0,lastEditorSnapshot='';

  function editorSelect(){try{return window.wp&&wp.data&&wp.data.select?wp.data.select('core/editor'):null;}catch(e){return null;}}
  function editorDispatch(){try{return window.wp&&wp.data&&wp.data.dispatch?wp.data.dispatch('core/editor'):null;}catch(e){return null;}}
  function editorAttribute(name){var store=editorSelect();try{return store&&store.getEditedPostAttribute?store.getEditedPostAttribute(name):null;}catch(e){return null;}}
  function getValue(selectors){for(var i=0;i<selectors.length;i++){var el=document.querySelector(selectors[i]);if(el&&typeof el.value==='string')return el.value;}return '';}
  function fieldForMeta(key){return document.querySelector('[name="'+key+'"],#'+key);}
  function metaValue(key,selectors){
    var meta=editorAttribute('meta');
    if(meta&&Object.prototype.hasOwnProperty.call(meta,key)&&typeof meta[key]==='string')return meta[key];
    var field=fieldForMeta(key);if(field&&typeof field.value==='string')return field.value;
    return getValue(selectors||[]);
  }
  function getPostId(){
    var card=document.getElementById('publishrank-editor-score'),cardId=card?parseInt(card.getAttribute('data-post-id')||0,10):0;
    if(cardId>0)return cardId;
    var store=editorSelect();try{if(store&&store.getCurrentPostId){var current=parseInt(store.getCurrentPostId()||0,10);if(current>0)return current;}}catch(e){}
    return parseInt(window.publishrankIntel&&publishrankIntel.postId||0,10)||0;
  }
  function getEditorContent(){
    try{if(window.tinymce&&tinymce.editors){var editor=tinymce.get('content');if(editor&&!editor.isHidden()&&typeof editor.getContent==='function')return editor.getContent();}}catch(e){}
    try{if(window.wp&&wp.data&&wp.data.select){var c=wp.data.select('core/editor').getEditedPostContent();if(typeof c==='string')return c;}}catch(e){}
    var classic=document.getElementById('content');return classic&&typeof classic.value==='string'?classic.value:'';
  }
  function payload(){
    var title=editorAttribute('title'),excerpt=editorAttribute('excerpt'),slug=editorAttribute('slug');
    return {
      action:publishrankIntel.action,nonce:publishrankIntel.nonce,post_id:getPostId(),
      title:typeof title==='string'?title:getValue(['#title','.editor-post-title__input']),
      content:getEditorContent(),excerpt:typeof excerpt==='string'?excerpt:getValue(['#excerpt']),
      slug:typeof slug==='string'?slug:getValue(['#post_name','#post_name_display']),
      seo_title:metaValue(metaKeys.seo_title,['#_publishrank_seo_title','#publishrank_seo_title','[name="_publishrank_seo_title"]']),
      description:metaValue(metaKeys.description,['#_publishrank_seo_description','#publishrank_meta_description','[name="_publishrank_seo_description"]']),
      keyphrase:metaValue(metaKeys.keyphrase,['#_publishrank_seo_focus_keyword','#publishrank_focus_keyword','[name="_publishrank_seo_focus_keyword"]']),
      has_thumbnail:!!(parseInt(editorAttribute('featured_media')||0,10)>0||document.querySelector('#postimagediv .inside img,.editor-post-featured-image__preview img'))
    };
  }
  function updateStatus(message){$('.pr-intel__save-status').text(message);$('#pr-intel-status,#pr-editor-score-status').text(message);}
  function syncMeta(key,value){
    var field=fieldForMeta(key);
    if(field&&field.value!==value){field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}));}
    var dispatch=editorDispatch(),store=editorSelect();
    try{
      if(dispatch&&dispatch.editPost&&store&&store.getEditedPostAttribute){
        var meta=store.getEditedPostAttribute('meta')||{},next={};
        Object.keys(meta).forEach(function(k){next[k]=meta[k];});next[key]=value;dispatch.editPost({meta:next});
      }
    }catch(e){if(window.console)console.warn('PublishRank could not sync SEO metadata to the editor state.',e);}
  }
  function applyMeta(key,value){
    if(typeof value!=='string'||[metaKeys.keyphrase,metaKeys.seo_title,metaKeys.description].indexOf(key)===-1)return;
    var quick=document.querySelector('.pr-intel__field[data-meta-key="'+key+'"]');
    if(quick){quick.value=value;quick.dispatchEvent(new Event('input',{bubbles:true}));}
    syncMeta(key,value);updateStatus(publishrankIntel.synced||'SEO values synced. Save or update the article to keep them.');schedule();
  }
  function paint(data){
    if(!data)return;
    var score=Math.max(0,Math.min(100,parseInt(data.score,10)||0));
    var active=document.activeElement,activeKey=active&&active.classList&&active.classList.contains('pr-intel__field')?active.getAttribute('data-meta-key'):'';
    var selection=activeKey&&typeof active.selectionStart==='number'?[active.selectionStart,active.selectionEnd]:null;
    var quick=document.querySelector('.pr-intel__quick'),quickOpen=quick?quick.open:null;
    $('#pr-intel-body').html(data.html||'');$('#pr-intel').attr('data-score',score);
    var refreshedQuick=document.querySelector('.pr-intel__quick');if(refreshedQuick&&quickOpen!==null)refreshedQuick.open=quickOpen;
    if(activeKey){var restored=document.querySelector('.pr-intel__field[data-meta-key="'+activeKey+'"]');if(restored){restored.focus();if(selection&&typeof restored.setSelectionRange==='function'){try{restored.setSelectionRange(selection[0],selection[1]);}catch(e){}}}}
    var card=$('#publishrank-editor-score');
    if(card.length){
      card.find('.pr-editor-score-card__number').text(score);card.attr('data-score',score).css('--pr-score',score+'%').removeClass('is-good is-ok is-bad').addClass(score>=80?'is-good':(score>=55?'is-ok':'is-bad'));
      card.find('.pr-editor-score-card__gauge').attr('aria-valuenow',score).attr('aria-label',(publishrankIntel.scoreLabel||'Editorial SEO score')+': '+score+' / 100');
      if(data.stats){card.find('.pr-editor-score-card__words').text(data.stats.words);card.find('.pr-editor-score-card__images').text(data.stats.images);card.find('.pr-editor-score-card__keyword').text(data.stats.focus_keyword?(data.stats.focus_keyword+' ('+data.stats.focus_keyword_occurrences+'×)'):'—');}
      var groups=data.groups||{};
      card.find('[data-score-group]').each(function(){
        var row=$(this),key=row.attr('data-score-group'),value=Object.prototype.hasOwnProperty.call(groups,key)?Math.max(0,Math.min(100,parseInt(groups[key],10)||0)):0;
        row.find('.pr-editor-score-card__bar-value').text(value);
        row.find('.pr-editor-score-card__bar-fill').css('width',value+'%').attr('aria-valuenow',value).attr('aria-label',row.children('span').first().text()+': '+value+' / 100');
        row.removeClass('is-good is-ok is-bad').addClass(value>=80?'is-good':(value>=55?'is-ok':'is-bad'));
      });
    }
  }
  function analyze(){
    var box=$('#pr-intel'),card=$('#publishrank-editor-score');if((!box.length&&!card.length)||!window.publishrankIntel)return;
    if(activeRequest&&activeRequest.readyState!==4)activeRequest.abort();
    var thisRequest=++requestNumber;
    box.add(card).addClass('pr-intel__busy');$('#pr-intel-refresh,#publishrank-editor-score-refresh').prop('disabled',true).each(function(){$(this).text(publishrankIntel.analyzing||'Analyzing…');});
    activeRequest=$.ajax({url:publishrankIntel.ajaxUrl,method:'POST',data:payload()}).done(function(r){if(thisRequest!==requestNumber)return;if(r&&r.success)paint(r.data);else updateStatus((r&&r.data&&r.data.message)||publishrankIntel.failed||'Analysis could not be completed.');}).fail(function(xhr,status){if(thisRequest!==requestNumber||'abort'===status)return;updateStatus(publishrankIntel.failed||'Analysis could not be completed. Save the draft and try again.');if(window.console)console.warn('PublishRank analyzer request failed',xhr.status);}).always(function(){if(thisRequest!==requestNumber)return;activeRequest=null;box.add(card).removeClass('pr-intel__busy');$('#pr-intel-refresh').prop('disabled',false).text(publishrankIntel.refresh||'Re-analyze');$('#publishrank-editor-score-refresh').prop('disabled',false).text(publishrankIntel.analyze||'Analyze article');});
  }
  function schedule(){window.clearTimeout(timer);timer=window.setTimeout(analyze,700);}
  function editorSnapshot(){
    var title=editorAttribute('title'),excerpt=editorAttribute('excerpt'),meta=editorAttribute('meta')||{};
    return [typeof title==='string'?title:'',getEditorContent(),typeof excerpt==='string'?excerpt:'',meta[metaKeys.keyphrase]||'',meta[metaKeys.seo_title]||'',meta[metaKeys.description]||''].join('\u0001');
  }
  function watchBlockEditor(){
    if(!window.wp||!wp.data||!wp.data.subscribe)return;
    lastEditorSnapshot=editorSnapshot();
    wp.data.subscribe(function(){var snapshot=editorSnapshot();if(snapshot!==lastEditorSnapshot){lastEditorSnapshot=snapshot;schedule();}});
  }

  $(document).on('click','#pr-intel-refresh,#publishrank-editor-score-refresh',function(e){e.preventDefault();analyze();});
  $(document).on('input change','#title,.editor-post-title__input,#content,#excerpt,#_publishrank_seo_title,#_publishrank_seo_description,#_publishrank_seo_focus_keyword,[name="_publishrank_seo_title"],[name="_publishrank_seo_description"],[name="_publishrank_seo_focus_keyword"],.pr-intel__field',function(){
    var field=this,key=field.getAttribute('data-meta-key');if(key){syncMeta(key,field.value);updateStatus(publishrankIntel.synced||'SEO values synced. Save or update the article to keep them.');}schedule();
  });
  $(document).on('click','.pr-intel__apply-suggestion',function(){applyMeta(String($(this).attr('data-meta-key')||''),String($(this).attr('data-value')||''));});
  $(document).on('click','.pr-intel__use-keyphrase',function(){applyMeta(metaKeys.keyphrase,String($(this).attr('data-phrase')||''));});
  $(document).on('click','.pr-intel__use-description',function(){applyMeta(metaKeys.description,String($(this).attr('data-text')||''));});
  $(function(){
    var card=$('#publishrank-editor-score'),n=parseInt(card.attr('data-score')||0,10);card.css('--pr-score',n+'%').addClass(n>=80?'is-good':(n>=55?'is-ok':'is-bad'));
    watchBlockEditor();window.setTimeout(analyze,250);
  });
})(jQuery);
