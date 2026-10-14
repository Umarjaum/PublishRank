(function ($) {
	'use strict';

	$(document).on('click', '.rhs-media-button', function (event) {
		event.preventDefault();
		var target = $('#' + $(this).data('target'));
		if (!target.length || typeof wp === 'undefined' || !wp.media) {
			return;
		}
		var frame = wp.media({
			title: publishrankSeo.chooseImage,
			button: { text: publishrankSeo.useImage },
			library: { type: 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			target.val(attachment.url).trigger('change');
		});
		frame.open();
	});

	$(document).on('click', '.rhs-copy', function (event) {
		event.preventDefault();
		var button = $(this);
		var text = String(button.data('copy') || '');
		var done = function () {
			var original = button.text();
			button.text(publishrankSeo.copied);
			setTimeout(function () { button.text(original); }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done);
		}
	});
	$(document).on('click', '.rhs-repeater__add', function (event) {
		event.preventDefault();
		var $repeater = $(this).closest('.rhs-repeater');
		var key = $repeater.data('key');
		var max = parseInt($repeater.data('max'), 10) || 20;
		if ($repeater.find('.rhs-repeater__row').length >= max) { return; }
		var row = $('<div class="rhs-repeater__row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;" />');
		row.append($('<input />', { type: 'text', 'class': 'widefat', style: 'flex:1;', name: key + '[]', maxlength: 100 }));
		row.append($('<button />', { type: 'button', 'class': 'button rhs-repeater__remove', text: '×', 'aria-label': 'Remove item' }));
		$repeater.find('.rhs-repeater__rows').append(row);
		row.find('input').trigger('focus');
	});

	$(document).on('click', '.rhs-repeater__remove', function (event) {
		event.preventDefault();
		$(this).closest('.rhs-repeater__row').remove();
	});

	$(document).on('submit', '.rhs-internal-links-confirm', function (event) {
		var message = $(this).data('confirm') || publishrankSeo.confirmInsert;
		if (!window.confirm(message)) {
			event.preventDefault();
		}
	});

	$(function () {
		$('.rhs-internal-links-autosubmit').each(function () {
			var form = this;
			// WordPress' submit_button() prints <input name="submit">, which shadows form.submit(); call the native method.
			setTimeout(function () { HTMLFormElement.prototype.submit.call(form); }, 500);
		});
	});

	$(document).on('change', '#publishrank-select-all', function () {
		$('input[name="post_ids[]"]').prop('checked', this.checked);
	});
})(jQuery);
