(function($){
  'use strict';
  function getValue(selectors){for(var i=0;i<selectors.length;i++){var el=document.querySelector(selectors[i]);if(el&&typeof el.value==='string')return el.value;}return '';}
  function getEditorContent(){
    var classic=document.getElementById('content');
    if(classic&&typeof classic.value==='string')return classic.value;
    try{if(window.wp&&wp.data&&wp.data.select){var c=wp.data.select('core/editor').getEditedPostContent();if(typeof c==='string')return c;}}catch(e){}
    return '';
  }
  function analyze(){
    var box=$('#pr-intel'); if(!box.length||!window.publishrankIntel)return;
    box.addClass('pr-intel__busy'); $('#pr-intel-refresh').prop('disabled',true).text(publishrankIntel.analyzing);
    $.post(publishrankIntel.ajaxUrl,{
      action:publishrankIntel.action,nonce:publishrankIntel.nonce,post_id:publishrankIntel.postId,
      title:getValue(['#title','.editor-post-title__input']),content:getEditorContent(),
      slug:getValue(['#post_name','#post_name_display']),
      seo_title:getValue(['#publishrank_seo_title','#publishrank_title','[name="publishrank_seo_title"]']),
      description:getValue(['#publishrank_meta_description','#publishrank_description','[name="publishrank_seo_description"]']),
      keyphrase:getValue(['#publishrank_focus_keyword','#publishrank_keyphrase','[name="publishrank_focus_keyword"]']),
      has_thumbnail:!!document.querySelector('#postimagediv .inside img,.editor-post-featured-image__preview img')
    }).done(function(r){if(r&&r.success){$('#pr-intel-body').html(r.data.html);$('#pr-intel').attr('data-score',r.data.score);}}).fail(function(){window.alert(publishrankIntel.failed);}).always(function(){box.removeClass('pr-intel__busy');$('#pr-intel-refresh').prop('disabled',false).text('Re-analyze');});
  }
  $(document).on('click','#pr-intel-refresh',function(e){e.preventDefault();analyze();});
  $(document).on('click','.pr-intel__use-keyphrase',function(){
    var phrase=$(this).data('phrase');
    var el=document.querySelector('#publishrank_focus_keyword,#publishrank_keyphrase,[name="publishrank_focus_keyword"]');
    if(el){el.value=phrase;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}
  });
  $(document).on('click','.pr-intel__use-description',function(){
    var text=$(this).data('text');
    var el=document.querySelector('#publishrank_meta_description,#publishrank_description,[name="publishrank_seo_description"]');
    if(el){el.value=text;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}
  });
})(jQuery);
