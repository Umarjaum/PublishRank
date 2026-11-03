(function ($, window, document) {
	'use strict';

	var config = window.PublishRankNewsroomNotifications;
	if (!config || !config.ajaxUrl || !config.nonce) return;

	var storageKey = 'publishrank-newsroom-seen-' + String(config.userId || 'user');
	var seen = new Set();

	try {
		var saved = JSON.parse(window.localStorage.getItem(storageKey) || '[]');
		if (Array.isArray(saved)) saved.forEach(function (id) { seen.add(String(id)); });
	} catch (error) {
		// Storage can be disabled by browser policy; polling still works for this page.
	}

	function remember(id) {
		seen.add(String(id));
		try {
			window.localStorage.setItem(storageKey, JSON.stringify(Array.from(seen).slice(-100)));
		} catch (error) {
			// Do not prevent alerts from working when local storage is unavailable.
		}
	}

	function updateCount(count) {
		var link = document.querySelector('#toplevel_page_publishrank-seo-dashboard .wp-submenu a[href*="page=uj-newsroom-notifications"]');
		if (link) {
			var label = config.labels && config.labels.bar ? config.labels.bar : 'Newsroom notifications';
			var badge = link.querySelector('.uj-notification-count');
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'uj-notification-count';
				badge.setAttribute('aria-hidden', 'true');
				link.appendChild(badge);
			}
			badge.textContent = count > 0 ? String(count) : '';
			link.setAttribute('aria-label', label + (count > 0 ? ' (' + String(count) + ')' : ''));
		}
	}

	function markRead(id) {
		return $.post(config.ajaxUrl, {
			action: 'uj_newsroom_mark_notification',
			nonce: config.nonce,
			notification_id: id
		}).done(function (response) {
			if (response && response.success && response.data) updateCount(response.data.unread || 0);
		});
	}

	function removeToast(toast) {
		if (toast && toast.parentNode) toast.parentNode.removeChild(toast);
	}

	function showToast(item) {
		var container = document.getElementById('uj-newsroom-toast-container');
		if (!container) {
			container = document.createElement('div');
			container.id = 'uj-newsroom-toast-container';
			container.setAttribute('role', 'status');
			container.setAttribute('aria-live', 'polite');
			container.setAttribute('aria-relevant', 'additions');
			document.body.appendChild(container);
		}

		var toast = document.createElement('section');
		toast.className = 'uj-newsroom-toast';
		var heading = document.createElement('strong');
		heading.className = 'uj-newsroom-toast-title';
		heading.textContent = item.title || 'Newsroom update';
		var message = document.createElement('p');
		message.textContent = item.message || '';
		var actions = document.createElement('div');
		actions.className = 'uj-newsroom-toast-actions';
		var open = document.createElement('a');
		open.className = 'button button-primary';
		open.href = item.url || '#';
		open.textContent = config.labels && config.labels.open ? config.labels.open : 'Open story';
		open.addEventListener('click', function (event) {
		event.preventDefault();
		var destination = open.href;
		markRead(item.id).always(function () { window.location.href = destination; });
	});
		var dismiss = document.createElement('button');
		dismiss.type = 'button';
		dismiss.className = 'button';
		dismiss.textContent = config.labels && config.labels.dismiss ? config.labels.dismiss : 'Dismiss';
		dismiss.addEventListener('click', function () {
			markRead(item.id);
			removeToast(toast);
		});
		actions.appendChild(open);
		actions.appendChild(dismiss);
		toast.appendChild(heading);
		toast.appendChild(message);
		toast.appendChild(actions);
		container.appendChild(toast);
		window.setTimeout(function () { removeToast(toast); }, 15000);
	}

	document.addEventListener('click', function (event) {
var link = event.target.closest && event.target.closest('.uj-notification-list li[data-notification-id] a');
if (!link) return;
var row = link.closest('li[data-notification-id]');
if (row) markRead(row.getAttribute('data-notification-id'));
});

	function poll() {
		if (document.visibilityState === 'hidden') return;
		$.post(config.ajaxUrl, {
			action: 'uj_newsroom_poll_notifications',
			nonce: config.nonce
		}).done(function (response) {
			if (!response || !response.success || !response.data) return;
			var items = Array.isArray(response.data.items) ? response.data.items : [];
			updateCount(response.data.unread || 0);
			var fresh = [];
			items.forEach(function (item) {
				if (!item || !item.id) return;
				if (!seen.has(String(item.id))) fresh.push(item);
				remember(item.id);
			});
			if (config.popupsEnabled && fresh.length) {
				// Avoid flooding users on first load; the remaining unread items stay in the inbox.
				fresh.slice(0, 3).reverse().forEach(showToast);
			}
			});
	}

	poll();
	window.setInterval(poll, Math.max(15000, Number(config.interval) || 45000));
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') poll();
	});
}(window.jQuery, window, document));
