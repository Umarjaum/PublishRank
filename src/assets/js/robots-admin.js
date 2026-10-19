/* PublishRank robots.txt screen: live preview, checks, crawlability matrix, URL tester and AI presets.
   Server text is rendered with textContent only. */
(function () {
	'use strict';
	var cfg = window.publishrankRobots, root = document.getElementById('pr-robots');
	if (!cfg || !root) { return; }
	var form = document.getElementById('pr-robots-form'), t = cfg.i18n || {};
	var q = function (s) { return root.querySelector(s); };
	var $text = q('[data-rb-text]'), $issues = q('[data-rb-issues]'), $matrix = q('[data-rb-matrix]'), $status = q('[data-rb-status]'), $pill = q('[data-rb-pill]'), $bytes = q('[data-rb-bytes]'), $test = q('[data-rb-test]');
	var $url = document.getElementById('pr-test-url'), $agent = document.getElementById('pr-test-agent');
	var timer = null, seq = 0, lastText = '';

	function el(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined) { n.textContent = text; } return n; }

	function params() {
		var p = new URLSearchParams();
		new FormData(form).forEach(function (v, k) {
			if (k === 'option_page' || k === 'action' || k === '_wpnonce' || k === '_wp_http_referer') { return; }
			p.append(k, v);
		});
		if ($url && $url.value.trim()) { p.append('test_url', $url.value.trim()); p.append('test_agent', $agent.value); }
		return p;
	}

	function renderText(d) {
		lastText = d.text;
		$text.replaceChildren();
		d.text.replace(/\n+$/, '').split('\n').forEach(function (line, i) {
			var s = el('span', d.ours_from >= 0 && i >= d.ours_from ? 'is-ours' : '', line); $text.appendChild(s); $text.appendChild(document.createTextNode('\n'));
		});
		$bytes.textContent = d.bytes.toLocaleString() + ' ' + t.bytes;
	}
	function renderIssues(list) {
		$issues.replaceChildren();
		list.forEach(function (i) { $issues.appendChild(el('li', 'is-' + i.level, i.msg)); });
	}
	function renderMatrix(m) {
		$matrix.replaceChildren();
		var head = el('thead'), hr = el('tr'); hr.appendChild(el('th', '', ''));
		m.agents.forEach(function (a) { var th = el('th', 'pr-rb__agent'); th.appendChild(el('span', '', a)); hr.appendChild(th); });
		head.appendChild(hr); $matrix.appendChild(head);
		var body = el('tbody');
		m.rows.forEach(function (r) {
			var tr = el('tr'), th = el('th', 'pr-rb__rowhead'); th.appendChild(el('strong', '', r.label)); th.appendChild(el('code', '', r.path)); tr.appendChild(th);
			m.agents.forEach(function (a) {
				var ok = r.cells[a], td = el('td', ok ? 'is-ok' : 'is-no', ok ? '✓' : '✕'); td.setAttribute('aria-label', a + ': ' + (ok ? t.allowed : t.blocked)); td.title = a + ': ' + (ok ? t.allowed : t.blocked); tr.appendChild(td);
			});
			body.appendChild(tr);
		});
		$matrix.appendChild(body);
	}
	function renderTest(x) {
		if (!x) { $test.hidden = true; return; }
		$test.hidden = false; $test.className = 'pr-rb__result ' + (x.allowed ? 'is-ok' : 'is-no'); $test.replaceChildren();
		$test.appendChild(el('strong', '', (x.allowed ? '✓ ' + t.allowed : '✕ ' + t.blocked) + ' · ' + x.agent));
		$test.appendChild(el('code', '', x.path));
		$test.appendChild(el('span', '', x.rule ? t.rule + ': ' + x.rule + ' (' + t.group + ' ' + x.group + ')' : t.noRule));
		if (x.foreign) { $test.appendChild(el('em', '', t.foreign)); }
	}

	function setStatus(msg, off) { $status.textContent = msg; $pill.classList.toggle('is-off', !!off); }

	function run() {
		var mine = ++seq; setStatus(t.updating);
		fetch(cfg.url, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: params().toString() })
			.then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
			.then(function (d) { if (mine !== seq) { return; } renderText(d); renderIssues(d.issues); renderMatrix(d.matrix); renderTest(d.test); setStatus(t.updated, false); })
			.catch(function () { if (mine === seq) { setStatus(t.failed, true); } });
	}
	function schedule(ms) { clearTimeout(timer); timer = setTimeout(run, typeof ms === 'number' ? ms : 450); }

	form.addEventListener('input', function () { schedule(); });
	form.addEventListener('change', function () { schedule(200); });
	[$url, $agent].forEach(function (n) { if (n) { n.addEventListener('input', function () { schedule(250); }); n.addEventListener('change', function () { schedule(100); }); } });

	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-preset]');
		if (b) {
			var preset = b.getAttribute('data-preset'), want = preset.split('+');
			form.querySelectorAll('select[data-bot]').forEach(function (s) {
				var kind = cfg.kinds[s.getAttribute('data-bot')];
				s.value = preset === 'clear' ? '' : ((preset === 'all' && kind !== 'seo') || want.indexOf(kind) > -1 ? 'block' : '');
			});
			schedule(100);
		}
		var c = e.target.closest('[data-rb-copy]');
		if (c && navigator.clipboard) { navigator.clipboard.writeText(lastText).then(function () { var o = c.textContent; c.textContent = t.copied; setTimeout(function () { c.textContent = o; }, 1300); }); }
	});
	run();
}());
