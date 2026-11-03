/* PublishRank setup wizard: modal behaviour. */
jQuery(function ($) {
	var $modal = $('#prw-modal');
	if (!$modal.length) { return; }
	$('body').addClass('prw-modal-open');
	// Announce and focus the validation summary after a failed save; otherwise start at the first required field.
	var $errorSummary = $modal.find('.prw-validation-summary').first();
	if ($errorSummary.length) {
		$errorSummary.trigger('focus');
	} else {
		var $first = $modal.find('input[required],select[required],textarea[required]').filter(function () { return !this.value; }).first();
		if (!$first.length) { $first = $modal.find('input:visible,select:visible,textarea:visible,a.button-primary,button.button-primary').first(); }
		$first.trigger('focus');
	}
	// Keep Tab inside the dialog.
	$modal.on('keydown', function (e) {
		if (e.key !== 'Tab') { return; }
		var $f = $modal.find('a[href],button,input:not([type=hidden]),select,textarea').filter(':visible:not([disabled])');
		if (!$f.length) { return; }
		var first = $f[0], last = $f[$f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});
});
