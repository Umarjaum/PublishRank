/* PublishRank Content Graph: in-page scan runner.
 * Replaces the old reload-per-batch loop. Batches run over admin-ajax, progress updates in place,
 * and the scan stops on error, pause, cancel, completion or lack of progress.
 * Without JavaScript the "Resume graph scan" form still processes one batch per click. */
(function () {
	'use strict';

	var cfg = window.publishrankGraphScan;
	var card = document.getElementById('publishrank-graph-scan');
	if (!cfg || !card) {
		return;
	}

	var i18n = cfg.i18n || {};
	var resumeForm = card.querySelector('.rhs-scan-continue');
	var resumeBtn = document.getElementById('publishrank-scan-resume');
	var pauseBtn = document.getElementById('publishrank-scan-pause');
	var cancelForm = card.querySelector('.rhs-scan-cancel');
	var cancelBtn = document.getElementById('publishrank-scan-cancel');
	var phaseEl = document.getElementById('publishrank-graph-scan-phase');
	var messageEl = document.getElementById('publishrank-graph-scan-message');
	var bar = card.querySelector('.rhs-scan-bar');
	var barFill = bar ? bar.querySelector('span') : null;
	var runForm = document.querySelector('form input[value="publishrank_internal_links_scan"]');
	runForm = runForm ? runForm.form : null;

	var running = false;
	var pauseRequested = false;
	var failures = 0;
	var MAX_FAILURES = 3;
	var MAX_BATCHES = 5000; // Hard safety ceiling against any runaway loop.
	var batches = 0;

	// Never leave "?start=1" in the address bar: reloading the page must not silently restart a scan.
	if (window.history && window.history.replaceState && /[?&]start=1/.test(window.location.search)) {
		try {
			var url = new URL(window.location.href);
			url.searchParams.delete('start');
			window.history.replaceState({}, document.title, url.toString());
		} catch (e) { /* ignore */ }
	}

	function setPercent(percent) {
		percent = Math.max(0, Math.min(100, parseInt(percent, 10) || 0));
		if (barFill) { barFill.style.width = percent + '%'; }
		if (bar) { bar.setAttribute('aria-valuenow', String(percent)); }
		var pct = phaseEl ? phaseEl.querySelector('.rhs-scan-percent') : null;
		if (pct) { pct.textContent = percent + '%'; }
	}

	function setPhase(phase) {
		var strong = phaseEl ? phaseEl.querySelector('strong') : null;
		if (strong && phase) {
			strong.textContent = 'nodes' === phase ? (i18n.phaseNodes || strong.textContent) : (i18n.phaseRecs || strong.textContent);
		}
	}

	function setMessage(text, isError) {
		if (!messageEl) { return; }
		messageEl.textContent = text || '';
		messageEl.classList.toggle('rhs-scan-message--error', !!isError);
	}

	function setUi(state) {
		// state: 'running' | 'paused' | 'finished'
		var isRunning = 'running' === state;
		if (resumeForm) { resumeForm.hidden = isRunning || 'finished' === state; }
		if (pauseBtn) { pauseBtn.hidden = !isRunning; pauseBtn.disabled = false; pauseBtn.textContent = i18n.pause || 'Pause'; }
		if (cancelForm) { cancelForm.hidden = 'finished' === state; }
		if (resumeBtn) { resumeBtn.disabled = isRunning; }
		if (runForm) {
			var runBtn = runForm.querySelector('input[type="submit"], button[type="submit"]');
			if (runBtn) { runBtn.disabled = isRunning; }
		}
		card.classList.toggle('is-running', isRunning);
		card.setAttribute('aria-busy', isRunning ? 'true' : 'false');
	}

	function post(action, nonce) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		return window.fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json().then(function (json) {
				return { status: response.status, json: json };
			}, function () {
				return { status: response.status, json: null };
			});
		});
	}

	function finish(message, isError) {
		running = false;
		setUi('paused');
		setMessage(message, isError);
	}

	function complete() {
		running = false;
		setPercent(100);
		setUi('finished');
		setMessage(i18n.done || '', false);
		card.classList.add('is-complete');
		// One deliberate refresh so the tables show the new data. A completed scan has no state left, so this cannot loop.
		window.setTimeout(function () {
			var url = new URL(window.location.href);
			url.searchParams.delete('start');
			window.location.replace(url.toString());
		}, 1200);
	}

	function step() {
		if (!running) { return; }
		if (pauseRequested) {
			pauseRequested = false;
			finish(i18n.paused || '', false);
			return;
		}
		if (++batches > MAX_BATCHES) {
			finish(i18n.failed || '', true);
			return;
		}
		post('publishrank_internal_links_batch', cfg.batchNonce).then(function (res) {
			var json = res.json;
			if (res.status === 403 || res.status === 400 || res.status === -1 || (json && json.success === false)) {
				var msg = json && json.data && json.data.message ? json.data.message : (i18n.expired || i18n.failed);
				finish(msg, true);
				return;
			}
			if (!json || !json.success || !json.data) {
				throw new Error('bad response');
			}
			failures = 0;
			var data = json.data;
			if (data.idle) {
				finish(i18n.notRunning || '', false);
				card.classList.add('is-idle');
				return;
			}
			setPercent(data.percent);
			setPhase(data.phase);
			if (data.error) {
				finish(data.error, true);
				return;
			}
			if (data.done) {
				complete();
				return;
			}
			setMessage(i18n.running || '', false);
			window.setTimeout(step, 120);
		}).catch(function () {
			failures++;
			if (failures >= MAX_FAILURES) {
				failures = 0;
				finish(i18n.failed || '', true);
				return;
			}
			window.setTimeout(step, 1500 * failures); // Back off, then retry the same batch from the saved cursor.
		});
	}

	function start() {
		if (running) { return; }
		running = true;
		pauseRequested = false;
		failures = 0;
		setUi('running');
		setMessage(i18n.running || '', false);
		step();
	}

	if (resumeForm) {
		resumeForm.addEventListener('submit', function (event) {
			event.preventDefault();
			start();
		});
	}
	if (pauseBtn) {
		pauseBtn.addEventListener('click', function () {
			pauseRequested = true;
			pauseBtn.disabled = true;
		});
	}
	if (cancelForm) {
		cancelForm.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!window.confirm(i18n.confirmCancel || 'Cancel the scan?')) { return; }
			running = false;
			pauseRequested = false;
			if (cancelBtn) { cancelBtn.disabled = true; }
			post('publishrank_internal_links_cancel', cfg.cancelNonce).then(function (res) {
				if (res.json && res.json.success) {
					setUi('finished');
					setMessage(i18n.cancelled || '', false);
					window.setTimeout(function () {
						var url = new URL(window.location.href);
						url.searchParams.delete('start');
						window.location.replace(url.toString());
					}, 800);
					return;
				}
				if (cancelBtn) { cancelBtn.disabled = false; }
				setMessage(i18n.failed || '', true);
			}).catch(function () {
				if (cancelBtn) { cancelBtn.disabled = false; }
				setMessage(i18n.failed || '', true);
			});
		});
	}

	// Start immediately only when the user just pressed "Run graph scan" (?start=1). A stale or paused scan waits for Resume.
	if ('1' === card.getAttribute('data-autostart')) {
		start();
	} else {
		setUi('paused');
	}
})();
