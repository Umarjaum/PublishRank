/* PublishRank admin navigation: collapsible task groups and uniform tool icons (no coloured tiles). */
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
		'publishrank-seo-dashboard': ['dashicons-dashboard'],
		'publishrank-seo-score': ['dashicons-chart-area'],
		'publishrank-author-expertise': ['dashicons-admin-users'],
		'publishrank-publisher-toolkit': ['dashicons-admin-tools'],
		'publishrank-image-optimizer': ['dashicons-format-image'],
		'publishrank-seo-internal-links': ['dashicons-admin-links'],
		'publishrank-link-intelligence': ['dashicons-networking'],
		'publishrank-local-seo': ['dashicons-location'],
		'uj-newsroom': ['dashicons-media-document'],
		'uj-newsroom-notifications': ['dashicons-bell'],
		'publishrank-search-console': ['dashicons-search'],
		'publishrank-sitemap-health': ['dashicons-sitemap'],
		'publishrank-indexnow': ['dashicons-upload'],
		'publishrank-baidu': ['dashicons-globe'],
		'publishrank-google-preferred-sources': ['dashicons-star-filled'],
		'publishrank-verification': ['dashicons-yes-alt'],
		'publishrank-schema': ['dashicons-editor-code'],
		'publishrank-breadcrumbs': ['dashicons-menu-alt'],
		'publishrank-seo-robots': ['dashicons-shield'],
		'publishrank-crawl-optimization': ['dashicons-performance'],
		'publishrank-llms-txt': ['dashicons-database-view'],
		'publishrank-analytics': ['dashicons-chart-line'],
		'publishrank-insights': ['dashicons-lightbulb'],
		'publishrank-seo-redirects': ['dashicons-randomize'],
		'publishrank-seo-404': ['dashicons-warning'],
		'publishrank-traffic-guard': ['dashicons-lock'],
		'publishrank-wp-hide-login': ['dashicons-hidden'],
		'publishrank-seo-config-history': ['dashicons-backup'],
		'publishrank-seo-diagnostics': ['dashicons-heart'],
		'publishrank': ['dashicons-admin-settings'],
		'publishrank-onboarding': ['dashicons-welcome-learn-more'],
		'publishrank-documentation': ['dashicons-book']
	};

	function addToolIcon(item) {
		var link = item.querySelector('a');
		if (!link || link.querySelector('.pr-submenu-icon')) {
			return;
		}
		var slug = pageSlug(link);
		var config = icons[slug] || ['dashicons-admin-generic'];
		var icon = document.createElement('span');
		icon.className = 'pr-submenu-icon' + ('publishrank-seo-dashboard' === slug ? ' pr-submenu-icon--brand' : '');
		icon.setAttribute('aria-hidden', 'true');
		if ('publishrank-seo-dashboard' !== slug) {
			var glyph = document.createElement('span');
			glyph.className = 'dashicons ' + config[0];
			icon.appendChild(glyph);
		}
		link.insertBefore(icon, link.firstChild);
		item.setAttribute('data-pr-page', slug);
	}

	function setupGroups(menu) {
		var items = Array.prototype.slice.call(menu.children);
		var currentGroup = null;
		var groupNumber = 0;
		items.forEach(function (item, index) {
			var link = item.querySelector('a');
			var marker = link ? link.querySelector('.pr-submenu-group') : null;
			if (marker) {
				var nextGroupIndex = index + 1;
				while (nextGroupIndex < items.length) {
					var nextLink = items[nextGroupIndex].querySelector('a');
					if (nextLink && nextLink.querySelector('.pr-submenu-group')) {
						break;
					}
					nextGroupIndex += 1;
				}
				var hasVisibleItems = items.slice(index, nextGroupIndex).some(function (candidate) {
					return !candidate.classList.contains('pr-submenu-hidden') && !candidate.hidden;
				});
				marker.remove();
				currentGroup = null;
				if (hasVisibleItems) {
					groupNumber += 1;
					currentGroup = 'group-' + groupNumber;
					var groupKey = currentGroup;
					var title = marker.textContent.trim();
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
