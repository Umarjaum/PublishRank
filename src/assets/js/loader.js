/* PublishRank loader: hide when the screen is ready, show again while navigating or saving. */
(function () {
	'use strict';

	var loader = document.getElementById('publishrank-loader');
	if (!loader) {
		return;
	}

	function hide() {
		loader.classList.remove('is-navigating');
		loader.classList.add('is-done');
		loader.setAttribute('aria-hidden', 'true');
	}

	var navigationTimer = 0;

	function show() {
		loader.classList.remove('is-done');
		loader.classList.add('is-navigating');
		loader.removeAttribute('aria-hidden');
		// Downloads (exports) never unload the page, so never leave the loader up indefinitely.
		window.clearTimeout(navigationTimer);
		navigationTimer = window.setTimeout(hide, 12000);
	}

	// Export, download and CSV actions return a file instead of a page.
	var downloadPattern = /(export|download|csv|calendar_feed)/i;

	if (document.readyState === 'complete') {
		hide();
	} else {
		window.addEventListener('load', hide);
		// Do not wait for slow third-party assets longer than necessary.
		window.setTimeout(hide, 4000);
	}

	// Returning with the back button restores the page from the browser cache.
	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			hide();
		}
	});

	function isPluginUrl(url) {
		try {
			var target = new URL(url, window.location.href);
			if (target.origin !== window.location.origin || !/\/wp-admin\//.test(target.pathname)) {
				return false;
			}
			if (target.pathname === window.location.pathname && target.search === window.location.search && target.hash) {
				return false; // In-page anchor.
			}
			if (downloadPattern.test(target.search) || downloadPattern.test(target.pathname.split('/').pop())) {
				return false;
			}
			var page = target.searchParams.get('page') || '';
			return 0 === page.indexOf('publishrank') || 0 === page.indexOf('uj-newsroom');
		} catch (error) {
			return false;
		}
	}

	document.addEventListener('click', function (event) {
		if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;
		}
		var link = event.target.closest ? event.target.closest('a[href]') : null;
		if (!link || (link.target && '_self' !== link.target) || link.hasAttribute('download')) {
			return;
		}
		if (isPluginUrl(link.getAttribute('href'))) {
			show();
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (event.defaultPrevented || !form || form.hasAttribute('data-pr-no-loader') || (form.target && '_self' !== form.target)) {
			return;
		}
		var action = form.getAttribute('action') || '';
		var actionField = form.querySelector('input[name="action"]');
		if (downloadPattern.test(action) || (actionField && downloadPattern.test(actionField.value))) {
			return;
		}
		// Let any other handler cancel the submit first; only then show the loader.
		window.setTimeout(function () {
			if (!event.defaultPrevented) {
				show();
			}
		}, 0);
	});
}());
