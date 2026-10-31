/* PublishRank admin navigation: collapsible task groups and visible, colored tool icons. */
(function () {
	'use strict';

	function pageSlug(link) {
		try {
			var url = new URL(link.href, window.location.origin);
			return url.searchParams.get('page') || '';
		} catch (error) {
			return '';
		}
	}

	var icons = {
		'publishrank-seo-dashboard': ['dashicons-dashboard', 'blue'],
		'publishrank-seo-score': ['dashicons-chart-area', 'violet'],
		'publishrank-author-expertise': ['dashicons-admin-users', 'teal'],
		'publishrank-publisher-toolkit': ['dashicons-admin-tools', 'orange'],
		'publishrank-image-optimizer': ['dashicons-format-image', 'pink'],
		'publishrank-seo-internal-links': ['dashicons-admin-links', 'green'],
		'publishrank-link-intelligence': ['dashicons-networking', 'cyan'],
		'publishrank-local-seo': ['dashicons-location', 'red'],
		'uj-newsroom': ['dashicons-media-document', 'purple'],
		'publishrank-search-console': ['dashicons-search', 'blue'],
		'publishrank-sitemap-health': ['dashicons-sitemap', 'indigo'],
		'publishrank-indexnow': ['dashicons-upload', 'green'],
		'publishrank-baidu': ['dashicons-globe', 'orange'],
		'publishrank-verification': ['dashicons-yes-alt', 'teal'],
		'publishrank-schema': ['dashicons-editor-code', 'violet'],
		'publishrank-breadcrumbs': ['dashicons-menu-alt', 'pink'],
		'publishrank-seo-robots': ['dashicons-shield', 'red'],
		'publishrank-crawl-optimization': ['dashicons-performance', 'cyan'],
		'publishrank-llms-txt': ['dashicons-database-view', 'indigo'],
		'publishrank-analytics': ['dashicons-chart-line', 'blue'],
		'publishrank-insights': ['dashicons-lightbulb', 'yellow'],
		'publishrank-seo-redirects': ['dashicons-randomize', 'orange'],
		'publishrank-seo-404': ['dashicons-warning', 'red'],
		'publishrank-traffic-guard': ['dashicons-lock', 'purple'],
		'publishrank-wp-hide-login': ['dashicons-hidden', 'slate'],
		'publishrank-seo-config-history': ['dashicons-backup', 'teal'],
		'publishrank-seo-diagnostics': ['dashicons-heart', 'green'],
		'publishrank': ['dashicons-admin-settings', 'slate'],
		'publishrank-onboarding': ['dashicons-welcome-learn-more', 'yellow'],
		'publishrank-documentation': ['dashicons-book', 'cyan']
	};

	function addToolIcon(item) {
		var link = item.querySelector('a');
		if (!link || link.querySelector('.pr-submenu-icon')) {
			return;
		}
		var slug = pageSlug(link);
		var config = icons[slug] || ['dashicons-admin-generic', 'slate'];
		var icon = document.createElement('span');
		icon.className = 'pr-submenu-icon pr-submenu-icon--' + config[1];
		icon.setAttribute('aria-hidden', 'true');
		var glyph = document.createElement('span');
		glyph.className = 'dashicons ' + config[0];
		icon.appendChild(glyph);
		link.insertBefore(icon, link.firstChild);
		item.setAttribute('data-pr-page', slug);
	}

	function setupGroups(menu) {
		var items = Array.prototype.slice.call(menu.children);
		var currentGroup = null;
		var groupNumber = 0;
		items.forEach(function (item) {
			var link = item.querySelector('a');
			var marker = link ? link.querySelector('.pr-submenu-group') : null;
			if (marker) {
				groupNumber += 1;
				currentGroup = 'group-' + groupNumber;
				var groupKey = currentGroup;
				var title = marker.textContent.trim();
				marker.remove();
				item.id = currentGroup;
				var toggle = document.createElement('button');
				toggle.type = 'button';
				toggle.className = 'pr-submenu-group-toggle';
				toggle.setAttribute('aria-expanded', 'true');
				toggle.setAttribute('aria-controls', currentGroup);
				toggle.innerHTML = '<span class="pr-submenu-group-toggle__label"></span><span class="pr-submenu-group-toggle__chevron" aria-hidden="true">⌄</span>';
				toggle.querySelector('.pr-submenu-group-toggle__label').textContent = title;
				item.parentNode.insertBefore(toggle, item);
				toggle.addEventListener('click', function () {
					var expanded = toggle.getAttribute('aria-expanded') === 'true';
					toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
					Array.prototype.slice.call(menu.children).forEach(function (candidate) {
					if (candidate.getAttribute('data-pr-group') === groupKey) {
							candidate.hidden = expanded;
						}
					});
				});
			}
			if (currentGroup) {
				item.setAttribute('data-pr-group', currentGroup);
			}
			addToolIcon(item);
		});
	}

	function init() {
		var menu = document.querySelector('#toplevel_page_publishrank-seo-dashboard > .wp-submenu') || document.querySelector('#toplevel_page_publishrank-seo-dashboard .wp-submenu');
		if (!menu) {
			return;
		}
		setupGroups(menu);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
