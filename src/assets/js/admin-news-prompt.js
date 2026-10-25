(function () {
	'use strict';

	var prompt = document.getElementById('pr-news-activation-prompt');
	if (!prompt) {
		return;
	}

	document.body.classList.add('pr-news-prompt-is-open');
	var initial = prompt.querySelector('[data-pr-news-focus]');
	if (initial) {
		initial.focus();
	}

	document.addEventListener('keydown', function (event) {
		var dismiss = prompt.querySelector('button[name="choice"][value="dismiss"]');
		if ('Escape' === event.key && dismiss) {
			event.preventDefault();
			dismiss.click();
			return;
		}
		if ('Tab' !== event.key) {
			return;
		}
		var controls = prompt.querySelectorAll('button:not([disabled]), a[href]');
		if (!controls.length) {
			return;
		}
		var first = controls[0];
		var last = controls[controls.length - 1];
		if (event.shiftKey && (document.activeElement === first || !prompt.contains(document.activeElement))) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && (document.activeElement === last || !prompt.contains(document.activeElement))) {
			event.preventDefault();
			first.focus();
		}
	}, true);
})();
