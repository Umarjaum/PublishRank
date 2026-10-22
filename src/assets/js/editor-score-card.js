(function($) {
	'use strict';
	function value(s) {
		var e=document.querySelector(s);
		return e&&typeof e.value==='string'?e.value:''
	}
	function blockTitle() {
		try { if(window.wp&&wp.data&&wp.data.select)return wp.data.select('core/editor').getEditedPostAttribute('title')||'' }
		catch(e) {}
		return ''
	}
	function content() {
		try {
			if(window.wp&&wp.data&&wp.data.select)return wp.data.select('core/editor').getEditedPostContent()||''
		}
		catch(e) {

		}
		var e=document.getElementById('content');
		return e&&typeof e.value==='string'?e.value:''
	}
	function refresh() {
		var card=$('#publishrank-editor-score');
		if(!card.length||!window.publishrankIntel)return;
		var score=card.find('.pr-editor-score-card__number');
		score.text('…');
		$.post(publishrankIntel.ajaxUrl,  {
			action:publishrankIntel.action, nonce:publishrankIntel.nonce, post_id:card.data('post-id')||0, title:blockTitle()||value('#title,.editor-post-title__input'), content:content(), slug:value('#post_name,#post_name_display'), seo_title:value('#_publishrank_seo_title,[name="_publishrank_seo_title"]'), description:value('#_publishrank_seo_description,[name="_publishrank_seo_description"]'), keyphrase:value('#_publishrank_seo_focus_keyword,[name="_publishrank_seo_focus_keyword"]'), has_thumbnail:!!document.querySelector('#postimagediv .inside img,.editor-post-featured-image__preview img')
		}
		).done(function(r) {
			if(r&&r.success) {
				score.text(r.data.score);
				card.attr('data-score', r.data.score).removeClass('is-good is-ok is-bad').addClass(r.data.score>=80?'is-good':(r.data.score>=55?'is-ok':'is-bad'));
				if(r.data.stats) {
					card.find('.pr-editor-score-card__words').text(r.data.stats.words);
					card.find('.pr-editor-score-card__images').text(r.data.stats.images);
					card.find('.pr-editor-score-card__keyword').text(r.data.stats.focus_keyword?r.data.stats.focus_keyword+' ('+r.data.stats.focus_keyword_occurrences+'×)':'—')
				}
				$('#pr-intel-body').html(r.data.html)
			}

		}
		).fail(function() {
			score.text('—')
		}
		)
	}
	$(document).on('click', '#publishrank-editor-score-refresh', function(e) {
		e.preventDefault();
		refresh()
	}
	);
		$(function() {
			var card=$('#publishrank-editor-score');
			var n=parseInt(card.attr('data-score')||0, 10);
			card.addClass(n>=80?'is-good':(n>=55?'is-ok':'is-bad'));
			if(!card.length)return;
			var storageKey='publishrank-editor-score-position';
			var saved=null;
			try { saved=JSON.parse(window.localStorage.getItem(storageKey)||'null') } catch(e) {}
			if(saved&&typeof saved.left==='number'&&typeof saved.top==='number') {
				card.css({left:saved.left+'px',top:saved.top+'px',right:'auto',bottom:'auto'});
			}
			var dragging=false,startX=0,startY=0,startLeft=0,startTop=0;
			function point(e) { var t=e.originalEvent&&e.originalEvent.touches&&e.originalEvent.touches[0]; return t||e.originalEvent||e; }
			function move(e) {
				if(!dragging)return;
				var p=point(e), maxLeft=Math.max(0,window.innerWidth-card.outerWidth()), maxTop=Math.max(0,window.innerHeight-card.outerHeight());
				var left=Math.max(0,Math.min(maxLeft,startLeft+(p.clientX-startX))), top=Math.max(0,Math.min(maxTop,startTop+(p.clientY-startY)));
				card.css({left:left+'px',top:top+'px',right:'auto',bottom:'auto'});
				if(e.preventDefault)e.preventDefault();
			}
			function stop() {
				if(!dragging)return;
				dragging=false; card.removeClass('is-dragging');
				var rect=card[0].getBoundingClientRect();
				try { window.localStorage.setItem(storageKey,JSON.stringify({left:Math.round(rect.left),top:Math.round(rect.top)})) } catch(e) {}
			}
			card.on('pointerdown',function(e) {
				if(e.button!==undefined&&e.button!==0)return;
				if($(e.target).closest('button,a,input,select,textarea').length)return;
				var p=point(e), rect=card[0].getBoundingClientRect();
				dragging=true; startX=p.clientX; startY=p.clientY; startLeft=rect.left; startTop=rect.top; card.addClass('is-dragging');
				if(card[0].setPointerCapture&&e.pointerId!==undefined)card[0].setPointerCapture(e.pointerId);
				e.preventDefault();
			});
			card.on('pointermove',move).on('pointerup pointercancel',stop);

		}
	);

}
)(jQuery);
