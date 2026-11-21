/* Access Shield sign-in page designer: media pickers, live preview and a contrast hint. */
(function ($) {
	'use strict';
	var form = document.querySelector('.prl-designer');
	if (!form) { return; }
	var cfg = window.publishrankLoginDesign || {};
	var preview = form.querySelector('[data-prl-preview]');
	form.querySelectorAll('[data-prl-device]').forEach(function (b) {
		b.addEventListener('click', function () {
			var mobile = b.getAttribute('data-prl-device') === 'mobile';
			if (preview) { preview.classList.toggle('is-mobile', mobile); }
			form.querySelectorAll('[data-prl-device]').forEach(function (o) { var on = o === b; o.classList.toggle('is-active', on); o.setAttribute('aria-pressed', on ? 'true' : 'false'); });
		});
	});
	function val(key) { var el = form.querySelector('[data-prl="' + key + '"]'); return el ? el.value : ''; }
	function hex(c) { c = (c || '').replace('#', ''); if (c.length === 3) { c = c.replace(/(.)/g, '$1$1'); } return [parseInt(c.substr(0, 2), 16), parseInt(c.substr(2, 2), 16), parseInt(c.substr(4, 2), 16)]; }
	function lum(rgb) { var a = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]; }
	function ratio(a, b) { var x = lum(hex(a)), y = lum(hex(b)); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
	var fonts = { system: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif', serif: 'Georgia,"Times New Roman",serif', rounded: 'ui-rounded,"Nunito","Segoe UI",sans-serif' };
	function render() {
		if (!preview) { return; }
		var s = preview.style;
		s.setProperty('--prl-bg', val('bg_color'));
		s.setProperty('--prl-card', val('card_color'));
		s.setProperty('--prl-text', val('text_color'));
		s.setProperty('--prl-accent', val('accent_color'));
		s.setProperty('--prl-btn-text', val('button_text'));
		s.setProperty('--prl-radius', (parseInt(val('radius'), 10) || 0) + 'px');
		s.setProperty('--prl-logo', (Math.min(320, parseInt(val('logo_width'), 10) || 84) / 2) + 'px');
		s.fontFamily = fonts[val('font')] || fonts.system;
		var bg = preview.getAttribute('data-bg');
		var ov = (parseInt(val('bg_overlay'), 10) || 0) / 100;
		s.backgroundImage = bg ? 'linear-gradient(rgba(0,0,0,' + ov + '),rgba(0,0,0,' + ov + ')),url("' + bg + '")' : 'none';
		preview.classList.toggle('has-bg', !!bg);
		preview.className = preview.className.replace(/prl-preview--\w+/, 'prl-preview--' + (val('layout') || 'center'));
		['heading', 'message', 'footer_note'].forEach(function (k) {
			var out = preview.querySelector('[data-prl-out="' + k + '"]');
			if (out) { out.textContent = val(k); out.hidden = !val(k); }
		});
		var back = form.querySelector('input[type=checkbox][name="design[hide_back]"]'), backOut = preview.querySelector('[data-prl-back]');
		if (back && backOut) { backOut.hidden = back.checked; }
		var shadow = form.querySelector('input[type=checkbox][name="design[shadow]"]');
		preview.classList.toggle('no-shadow', shadow && !shadow.checked);
		var hint = form.querySelector('[data-prl-contrast]');
		if (hint) {
			var msgs = [];
			if (ratio(val('text_color'), val('card_color')) < 4.5) { msgs.push('Text and form background have low contrast (below 4.5:1).'); }
			if (ratio(val('button_text'), val('accent_color')) < 4.5) { msgs.push('Button text and button colour have low contrast (below 4.5:1).'); }
			hint.textContent = msgs.join(' ');
			hint.classList.toggle('is-warn', msgs.length > 0);
		}
	}
	form.addEventListener('input', render);
	form.addEventListener('change', render);
	render();

	$(form).on('click', '[data-prl-pick]', function (e) {
		e.preventDefault();
		if (!window.wp || !wp.media) { return; }
		var key = this.getAttribute('data-prl-pick');
		var frame = wp.media({ title: cfg.choose || 'Choose image', button: { text: cfg.use || 'Use this image' }, library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = (a.sizes && (a.sizes.medium || a.sizes.full) || a).url;
			var input = form.querySelector('[data-prl-media="' + key + '"]');
			var wrap = input.closest('.prl-media');
			input.value = a.id;
			var img = wrap.querySelector('img'); img.src = url; img.hidden = false;
			wrap.querySelector('[data-prl-clear]').hidden = false;
			if (key === 'logo_id') { preview.querySelector('[data-prl-logo]').style.backgroundImage = 'url("' + url + '")'; }
			if (key === 'bg_image_id') { preview.setAttribute('data-bg', (a.sizes && a.sizes.large ? a.sizes.large.url : a.url)); }
			render();
		});
		frame.open();
	});
	$(form).on('click', '[data-prl-clear]', function (e) {
		e.preventDefault();
		var key = this.getAttribute('data-prl-clear');
		var input = form.querySelector('[data-prl-media="' + key + '"]');
		var wrap = input.closest('.prl-media');
		input.value = '0';
		wrap.querySelector('img').hidden = true;
		this.hidden = true;
		if (key === 'logo_id') { preview.querySelector('[data-prl-logo]').style.backgroundImage = ''; }
		if (key === 'bg_image_id') { preview.setAttribute('data-bg', ''); }
		render();
	});
}(window.jQuery));
