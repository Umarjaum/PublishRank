/**
 * PublishRank readable XML sitemap view: local time formatting, URL filter, paging and copy button.
 * Loaded by the XSLT stylesheet served at the sitemap XSL URL (no WordPress page is rendered there).
 */
(function(){
	var d=document,fmt,rtf;
	try{fmt=new Intl.DateTimeFormat(undefined,{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});rtf=new Intl.RelativeTimeFormat(undefined,{numeric:'auto'});}catch(e){}
	function rel(t){var s=(t-Date.now())/1000,a=Math.abs(s);if(!rtf)return '';
		if(a<60)return rtf.format(Math.round(s),'second');if(a<3600)return rtf.format(Math.round(s/60),'minute');
		if(a<86400)return rtf.format(Math.round(s/3600),'hour');if(a<2592000)return rtf.format(Math.round(s/86400),'day');return '';}
	[].forEach.call(d.querySelectorAll('time[datetime]'),function(el){var v=new Date(el.getAttribute('datetime'));if(isNaN(v)||!fmt)return;
		var r=rel(v.getTime());el.title=el.textContent+' UTC';el.textContent=r||fmt.format(v);if(r)el.title=fmt.format(v);});
	[].forEach.call(d.querySelectorAll('[data-copy]'),function(b){b.addEventListener('click',function(){
		var done=function(){var o=b.textContent;b.textContent='Copied';setTimeout(function(){b.textContent=o;},1400);};
		if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(location.href.split('?')[0]).then(done);}});});
	var body=d.getElementById('pr-rows');if(!body)return;
	var rows=[].slice.call(body.rows),q=d.getElementById('pr-filter'),size=d.getElementById('pr-size'),cnt=d.getElementById('pr-count'),rng=d.getElementById('pr-range'),prev=d.getElementById('pr-prev'),next=d.getElementById('pr-next'),page=0,hits=rows;
	var keys=rows.map(function(r,i){r._i=i;return r.textContent.toLowerCase();});
	function draw(){var s=parseInt(size.value,10)||100,pages=Math.max(1,Math.ceil(hits.length/s));if(page>=pages)page=pages-1;
		var from=page*s,to=from+s,set={};hits.slice(from,to).forEach(function(r){set[r._i]=1;});
		rows.forEach(function(r,i){r.hidden=!set[i];});
		cnt.textContent=hits.length===rows.length?rows.length.toLocaleString()+' URLs':hits.length.toLocaleString()+' of '+rows.length.toLocaleString()+' match';
		rng.textContent=hits.length?('Showing '+(from+1).toLocaleString()+'–'+Math.min(to,hits.length).toLocaleString()+' of '+hits.length.toLocaleString()):'No matches';
		prev.disabled=page===0;next.disabled=page>=pages-1;}
	function filter(){var v=q.value.trim().toLowerCase();hits=v?rows.filter(function(r){return keys[r._i].indexOf(v)>-1;}):rows;page=0;draw();}
	var timer;q.addEventListener('input',function(){clearTimeout(timer);timer=setTimeout(filter,120);});
	size.addEventListener('change',function(){page=0;draw();});
	prev.addEventListener('click',function(){page--;draw();window.scrollTo(0,0);});next.addEventListener('click',function(){page++;draw();window.scrollTo(0,0);});
	draw();
})();
