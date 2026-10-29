(function ($) {
	'use strict';

	var strings = (window.publishrankSeo && publishrankSeo.i18n) || {};

	function message(key, fallback) {
		return strings[key] || fallback || '';
	}

	function format(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(template || '').replace(/%(\d+)\$[sd]/g, function (match, position) {
			var index = parseInt(position, 10) - 1;
			return typeof args[index] === 'undefined' ? match : String(args[index]);
		});
	}

	function number(value) {
		try {
			var locale = document.documentElement.lang || undefined;
			return new Intl.NumberFormat(locale).format(value);
		} catch (error) {
			return String(value);
		}
	}

	function dimensionMessage(width, height) {
		var pixels = width * height;
		var text = format(message('imageDimensions', 'Current dimensions: %1$s × %2$s px (%3$s pixels).'), number(width), number(height), number(pixels));
		var closeToRatio = height > 0 && Math.abs((width / height) - (16 / 9)) < 0.025;
		var guidance = width >= 1200 && pixels > 300000
			? (closeToRatio ? message('discoverMeetsRatio', 'Meets Google’s image-size guidance and is close to the recommended 16:9 ratio.') : message('discoverMeets', 'Meets Google’s image-size guidance. A 16:9 landscape ratio is recommended, not required.'))
			: message('discoverBelow', 'Below Google’s suggested size for large Discover images: at least 1200 px wide and more than 300,000 total pixels.');
		return text + ' ' + guidance;
	}

	function discoverWidget() {
		return document.querySelector('.pr-discover-image-tool');
	}

	function socialImageInput(widget) {
		if (!widget || !widget.previousElementSibling) {
			return null;
		}
		return widget.previousElementSibling.querySelector('input[type="url"]');
	}

	function updateDiscoverSource(widget, attachmentId, url, width, height) {
		if (!widget) {
			return;
		}
		width = parseInt(width, 10) || 0;
		height = parseInt(height, 10) || 0;
		widget.dataset.attachmentId = parseInt(attachmentId, 10) || '';
		widget.dataset.width = width;
		widget.dataset.height = height;
		widget.dataset.imageUrl = url || '';

		var status = widget.querySelector('.pr-discover-image-status');
		var resizeButton = widget.querySelector('.pr-discover-resize');
		var preview = widget.querySelector('.pr-discover-image-preview');
		if (url && !preview) {
			preview = document.createElement('img');
			preview.className = 'pr-discover-image-preview';
			preview.alt = '';
			preview.loading = 'lazy';
			var actions = widget.querySelector('.pr-discover-image-actions');
			widget.insertBefore(preview, actions || null);
		}
		if (preview) {
			if (url) {
				preview.src = url;
				preview.hidden = false;
			} else {
				preview.removeAttribute('src');
				preview.hidden = true;
			}
		}
		if (status) {
			status.textContent = width && height
				? dimensionMessage(width, height)
				: (url ? message('imageNotLocal', 'This image is not in the WordPress Media Library. You can check its dimensions, but choose or upload it there to create a resized copy.') : message('imageChosen', 'Choose an image to inspect its pixel dimensions.'));
		}
		if (resizeButton) {
			resizeButton.disabled = !parseInt(attachmentId, 10);
		}
	}

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
			target.val(attachment.url).trigger('input').trigger('change');
			var widget = discoverWidget();
			if (widget && target[0] === socialImageInput(widget)) {
				updateDiscoverSource(widget, attachment.id, attachment.url, attachment.width, attachment.height);
			}
		});
		frame.open();
	});

	function copyStatusRegion() {
		var $status = $('#publishrank-copy-status');
		if (!$status.length) {
			$status = $('<span />', { id: 'publishrank-copy-status', 'class': 'screen-reader-text', role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true' }).appendTo('body');
		}
		return $status;
	}

	function legacyCopy(text) {
		var active = document.activeElement;
		var $input = $('<textarea />', { 'aria-hidden': 'true', tabindex: '-1' }).val(text).css({ position: 'fixed', opacity: 0, left: '-9999px' }).appendTo('body');
		$input.trigger('focus').trigger('select');
		var copied = false;
		try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
		$input.remove();
		if (active && typeof active.focus === 'function') { active.focus(); }
		return copied;
	}

	copyStatusRegion();

	$(document).on('click', '.rhs-copy', function (event) {
		event.preventDefault();
		var button = $(this);
		var text = String(button.data('copy') || '');
		var original = button.text();
		var $status = copyStatusRegion();
		var result = navigator.clipboard && navigator.clipboard.writeText
			? Promise.resolve().then(function () { return navigator.clipboard.writeText(text); }).then(function () { return true; }).catch(function () { return legacyCopy(text); })
			: Promise.resolve(legacyCopy(text));
		result.then(function (success) {
			button.text(success ? publishrankSeo.copied : original);
			$status.text(success ? publishrankSeo.copied : publishrankSeo.copyFailed);
			if (success) { setTimeout(function () { button.text(original); }, 1500); }
		});
	});

	function updateRepeaterLabels($repeater) {
		var label = String($repeater.data('label') || publishrankSeo.repeaterItem);
		var helpId = $repeater.find('.description[id]').first().attr('id');
		var $rows = $repeater.find('.rhs-repeater__row');
		$rows.each(function (index) {
			var number = index + 1;
			var fieldLabel = format(message('repeaterFieldLabel', '%1$s, item %2$d'), label, number);
			var removeLabel = format(message('removeRepeaterItem', 'Remove item %2$d from %1$s'), label, number);
			$(this).find('input').attr('aria-label', fieldLabel);
			if (helpId) { $(this).find('input').attr('aria-describedby', helpId); }
			$(this).find('.rhs-repeater__remove').attr('aria-label', removeLabel);
		});
		var max = parseInt($repeater.data('max'), 10) || 20;
		$repeater.find('.rhs-repeater__add').prop('disabled', $rows.length >= max);
	}

	function announceRepeater($repeater, text) {
		$repeater.find('.rhs-repeater__status').text('');
		window.setTimeout(function () { $repeater.find('.rhs-repeater__status').text(text); }, 20);
	}

	$(document).on('click', '.rhs-repeater__add', function (event) {
		event.preventDefault();
		var $button = $(this);
		var $repeater = $button.closest('.rhs-repeater');
		var key = $repeater.data('key');
		var label = String($repeater.data('label') || publishrankSeo.repeaterItem);
		var max = parseInt($repeater.data('max'), 10) || 20;
		var $rows = $repeater.find('.rhs-repeater__rows');
		if ($rows.find('.rhs-repeater__row').length >= max) {
			announceRepeater($repeater, format(message('maxItemsReached', 'No more than %2$d items can be added to %1$s.'), label, max));
			return;
		}
		var number = $rows.find('.rhs-repeater__row').length + 1;
		var helpId = $repeater.find('.description[id]').first().attr('id');
		var row = $('<div class="rhs-repeater__row" style="display:flex;gap:6px;margin-bottom:6px;align-items:center;" />');
		var field = $('<input />', { type: 'text', 'class': 'widefat', style: 'flex:1;', name: key + '[]', maxlength: 100, 'aria-label': format(message('repeaterFieldLabel', '%1$s, item %2$d'), label, number) });
		if (helpId) { field.attr('aria-describedby', helpId); }
		row.append(field);
		row.append($('<button />', { type: 'button', 'class': 'button rhs-repeater__remove', text: '×', 'aria-label': format(message('removeRepeaterItem', 'Remove item %2$d from %1$s'), label, number) }));
		$rows.append(row);
		updateRepeaterLabels($repeater);
		announceRepeater($repeater, format(message('addedRepeaterItem', 'Added item %2$d to %1$s.'), label, number));
		field.trigger('focus');
	});

	$(document).on('click', '.rhs-repeater__remove', function (event) {
		event.preventDefault();
		var $row = $(this).closest('.rhs-repeater__row');
		var $repeater = $row.closest('.rhs-repeater');
		var label = String($repeater.data('label') || publishrankSeo.repeaterItem);
		var $rows = $repeater.find('.rhs-repeater__row');
		var number = $rows.index($row) + 1;
		var $next = $row.next('.rhs-repeater__row').find('input').first();
		var $previous = $row.prev('.rhs-repeater__row').find('input').first();
		var $add = $repeater.find('.rhs-repeater__add').first();
		$row.remove();
		updateRepeaterLabels($repeater);
		announceRepeater($repeater, format(message('removedRepeaterItem', 'Removed item %2$d from %1$s.'), label, number));
		if ($next.length) { $next.trigger('focus'); }
		else if ($previous.length) { $previous.trigger('focus'); }
		else { $add.trigger('focus'); }
	});

	$(document).on('click', '.pr-discover-check', function () {
		var widget = discoverWidget();
		var input = socialImageInput(widget);
		if (!widget || !input) { return; }
		var url = (input.value || widget.dataset.imageUrl || '').trim();
		var status = widget.querySelector('.pr-discover-image-status');
		if (!url || !/^https?:\/\//i.test(url)) {
			if (status) { status.textContent = message('imageLoadFailed', 'The image could not be loaded to read its dimensions.'); }
			return;
		}
		if (status) { status.textContent = message('checkingDimensions', 'Checking image dimensions…'); }
		var image = new Image();
		image.referrerPolicy = 'no-referrer';
		image.onload = function () {
			if (image.naturalWidth && image.naturalHeight && status) {
				status.textContent = dimensionMessage(image.naturalWidth, image.naturalHeight) + (parseInt(widget.dataset.attachmentId, 10) ? '' : ' ' + message('imageNotLocal', 'This image is not in the WordPress Media Library. You can check its dimensions, but choose or upload it there to create a resized copy.'));
			}
		};
		image.onerror = function () {
			if (status) { status.textContent = message('checkDimensionsFail', 'The image dimensions could not be read. Check the URL or choose a Media Library image.'); }
		};
		image.src = url;
	});

	$(document).on('input change', '[name^="_publishrank_seo_"]', function () {
		var widget = discoverWidget();
		var input = socialImageInput(widget);
		if (!widget || !input || this !== input || input.value === widget.dataset.imageUrl) { return; }
		updateDiscoverSource(widget, 0, input.value.trim(), 0, 0);
	});

	$(document).on('click', '.pr-discover-resize', function () {
		var button = this;
		var widget = discoverWidget();
		var status = widget && widget.querySelector('.pr-discover-image-status');
		var widthText = widget && widget.querySelector('#pr-discover-image-width').value.trim();
		var width = /^\d+$/.test(widthText || '') ? parseInt(widthText, 10) : 0;
		var heightText = widget && widget.querySelector('#pr-discover-image-height').value.trim();
		var height = heightText ? (/^\d+$/.test(heightText) ? parseInt(heightText, 10) : 0) : '';
		var crop = !!(widget && widget.querySelector('.pr-discover-image-crop').checked);
		var attachmentId = parseInt(widget && widget.dataset.attachmentId, 10) || 0;
		if (!widget || !attachmentId) {
			if (status) { status.textContent = message('imageNotLocal', 'Choose a Media Library image to create a resized copy.'); }
			return;
		}
		if (width < 1 || width > 6000 || (height !== '' && (height < 1 || height > 6000))) {
			if (status) { status.textContent = message('validDimensions', 'Enter a width and optional height from 1 to 6000 px.'); }
			return;
		}
		if (crop && height === '') {
			if (status) { status.textContent = message('heightRequired', 'Enter a height before choosing an exact crop.'); }
			return;
		}
		var sourceWidth = parseInt(widget.dataset.width, 10) || 0;
		var sourceHeight = parseInt(widget.dataset.height, 10) || 0;
		if (sourceWidth && sourceHeight) {
			var scale = Math.min(width / sourceWidth, height === '' ? Infinity : height / sourceHeight);
			var requiresUpscale = crop ? (width > sourceWidth || height > sourceHeight) : scale > 1;
			var alreadySameSize = crop ? (width === sourceWidth && height === sourceHeight) : scale === 1;
			if (requiresUpscale) {
				if (status) { status.textContent = message('imageTooSmall', 'WordPress will not upscale this image. Choose an original large enough for the requested output; enlarging cannot restore image detail.'); }
				return;
			}
			if (alreadySameSize) {
				if (status) { status.textContent = message('alreadyImageSize', 'This image is already at the requested size. Keep it selected; no resized copy is needed.'); }
				return;
			}
		}
		button.disabled = true;
		if (status) { status.textContent = message('resizeWorking', 'Creating a resized copy…'); }
		var postId = parseInt(publishrankSeo.postId, 10) || parseInt($('#post_ID').val(), 10) || 0;
		try {
			if (!postId && window.wp && wp.data && wp.data.select) { postId = parseInt(wp.data.select('core/editor').getCurrentPostId(), 10) || 0; }
		} catch (error) {}
		$.post(publishrankSeo.ajaxUrl, {
			action: 'publishrank_resize_discover_image',
			nonce: publishrankSeo.resizeNonce,
			post_id: postId,
			attachment_id: attachmentId,
			width: width,
			height: height,
			crop: crop ? '1' : '0'
		}).done(function (response) {
			if (!response || !response.success || !response.data) {
				if (status) { status.textContent = response && response.data && response.data.message ? response.data.message : message('resizeFailed', 'The resized copy could not be created. Try another size or image.'); }
				return;
			}
			var data = response.data;
			var input = socialImageInput(widget);
			if (input) { input.value = data.url; input.dispatchEvent(new Event('input', { bubbles: true })); input.dispatchEvent(new Event('change', { bubbles: true })); }
			updateDiscoverSource(widget, data.attachmentId, data.url, data.width, data.height);
			if (status) { status.textContent = format(message('resizeCreated', 'A %1$s × %2$s px copy was created and selected. The original remains unchanged.'), number(data.width), number(data.height)); }
		}).fail(function (xhr) {
			var response = xhr && xhr.responseJSON;
			var text = response && response.data && response.data.message ? response.data.message : message('resizeFailed', 'The resized copy could not be created. Try another size or image.');
			if (status) { status.textContent = text; }
		}).always(function () {
			button.disabled = !parseInt(widget.dataset.attachmentId, 10);
		});
	});

	$(document).on('submit', '.rhs-internal-links-confirm', function (event) {
		var confirmMessage = $(this).data('confirm') || publishrankSeo.confirmInsert;
		if (!window.confirm(confirmMessage)) {
			event.preventDefault();
		}
	});

	$(function () {
		$('.rhs-internal-links-autosubmit').each(function () {
			var form = this;
			setTimeout(function () { HTMLFormElement.prototype.submit.call(form); }, 500);
		});
		$('.rhs-repeater').each(function () { updateRepeaterLabels($(this)); });
	});

	$(document).on('change', '#publishrank-select-all', function () {
		$('input[name="post_ids[]"]').prop('checked', this.checked);
	});
})(jQuery);
