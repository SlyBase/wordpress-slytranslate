(function () {
	if (window.SlyTranslateBg) { return; }

	var _data = window.SlyTranslateBgBar || {};
	var REST_URL = _data.restUrl || '';
	var REST_NONCE = _data.restNonce || '';
	var S = _data.i18n || {};
	var STORAGE_KEY = 'slytranslate_bg_tasks_v1';
	var DONE_RETENTION_MS = 5000; // auto-dismiss completed tasks after 5s
	var bgPollStartedAt = Date.now();
	function getBgNextPollDelay() {
		var elapsed = Date.now() - bgPollStartedAt;
		if (elapsed < 30000) { return 1000; }
		if (elapsed < 120000) { return 2000; }
		return 5000;
	}

	function escHtml(s) { var d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
	function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

	function slyDebugEnabled() {
		return !!(typeof window !== 'undefined' && window.SLY_TRANSLATE_DEBUG);
	}

	function slyDebug() {
		if (!slyDebugEnabled() || typeof console === 'undefined' || !console.log) { return; }
		var args = ['[SlyTranslate:bg-bar]'];
		for (var i = 0; i < arguments.length; i++) { args.push(arguments[i]); }
		try { console.log.apply(console, args); } catch (e) { }
	}

	function loadTasks() {
		try {
			var raw = window.localStorage.getItem(STORAGE_KEY);
			if (!raw) { return []; }
			var parsed = JSON.parse(raw);
			return Array.isArray(parsed) ? parsed : [];
		} catch (e) { return []; }
	}

	function saveTasks(tasks) {
		try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(tasks)); } catch (e) { }
	}

	function pruneTasks(tasks) {
		var now = Date.now();
		return tasks.filter(function (t) {
			if (t.status === 'running') { return true; }
			return (now - (t.finishedAt || now)) < DONE_RETENTION_MS;
		});
	}

	var tasks = pruneTasks(loadTasks());
	saveTasks(tasks);

	function findTask(id) {
		for (var i = 0; i < tasks.length; i++) { if (tasks[i].id === id) { return tasks[i]; } }
		return null;
	}

	function ensureContainer() {
		var existing = document.getElementById('slytranslate-bg-notice');
		if (existing) { return existing; }

		var notice = document.createElement('div');
		notice.id = 'slytranslate-bg-notice';
		notice.className = 'notice notice-info';
		notice.style.padding = '8px 12px';
		notice.style.margin = '8px 0';

		var headerEnd = document.querySelector('.wp-header-end');
		if (headerEnd && headerEnd.parentNode) {
			headerEnd.parentNode.insertBefore(notice, headerEnd.nextSibling);
		} else {
			var wrap = document.querySelector('#wpbody-content');
			if (wrap) { wrap.insertBefore(notice, wrap.firstChild); }
			else { document.body.appendChild(notice); }
		}
		return notice;
	}

	function removeContainer() {
		var existing = document.getElementById('slytranslate-bg-notice');
		if (existing && existing.parentNode) { existing.parentNode.removeChild(existing); }
	}

	function statusLabel(task) {
		var label = task.postTitle ? task.postTitle : ('#' + (task.postId || '?'));
		var lang = task.langName || task.lang;
		var suffix = '';
		if (task.status === 'done') { suffix = ' — ' + S.success; }
		if (task.status === 'error') { suffix = ' — ' + S.error; }
		if (task.status === 'cancelled') { suffix = ' — ' + S.cancelled; }
		return label + ' (' + lang + ')' + suffix;
	}

	function statusClass(task) {
		if (task.status === 'done') { return 'notice-success'; }
		if (task.status === 'error') { return 'notice-error'; }
		if (task.status === 'cancelled') { return 'notice-warning'; }
		return 'notice-info';
	}

	function statusBadge(task) {
		var fg = '#1d4ed8';
		if (task.status === 'done') { fg = '#1e4620'; }
		if (task.status === 'error') { fg = '#8a1f1f'; }
		if (task.status === 'cancelled') { fg = '#614a19'; }
		return '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:' + fg + ';margin-right:8px;flex:none;"></span>';
	}

	function isCollapsed() {
		try { return window.localStorage.getItem(STORAGE_KEY + '_collapsed') === '1'; } catch (e) { return false; }
	}

	function setCollapsed(v) {
		try { window.localStorage.setItem(STORAGE_KEY + '_collapsed', v ? '1' : '0'); } catch (e) { }
	}

	function summaryText() {
		var running = 0, done = 0, error = 0;
		tasks.forEach(function (t) {
			if (t.status === 'running') { running++; }
			if (t.status === 'done') { done++; }
			if (t.status === 'error') { error++; }
		});
		var parts = [];
		if (running) { parts.push(running + ' ' + S.summaryRunning); }
		if (done) { parts.push(done + ' ' + S.summaryDone); }
		if (error) { parts.push(error + ' ' + S.summaryError); }
		return parts.join(' · ');
	}

	function render() {
		if (!tasks.length) { removeContainer(); return; }

		var notice = ensureContainer();
		notice.className = 'notice ' + statusClass(tasks[0]);
		notice.style.padding = '8px 12px';

		var collapsed = isCollapsed();
		var html = '';

		html += '<div style="display:flex;align-items:center;gap:8px;">';
		html += '<button type="button" class="button-link slytranslate-bg-toggle" aria-expanded="' + (collapsed ? 'false' : 'true') + '" style="padding:0;color:#1d2327;text-decoration:none;font-weight:600;display:flex;align-items:center;gap:6px;">';
		html += '<span class="dashicons dashicons-arrow-' + (collapsed ? 'right' : 'down') + '" style="margin:0;"></span>';
		html += escHtml(S.header);
		html += '</button>';
		html += '<span style="flex:1;color:#50575e;font-size:12px;">' + escHtml(summaryText()) + '</span>';
		html += '<button type="button" class="button-link slytranslate-bg-dismiss-all" style="color:#50575e;font-size:12px;">' + escHtml(S.dismissAll) + '</button>';
		html += '</div>';

		if (collapsed) {
			notice.innerHTML = html;
			return;
		}

		html += '<ul style="list-style:none;margin:6px 0 0;padding:0;">';
		tasks.forEach(function (t) {
			var labelColor = '#1d2327';
			if (t.status === 'done') { labelColor = '#1e4620'; }
			if (t.status === 'error') { labelColor = '#8a1f1f'; }
			if (t.status === 'cancelled') { labelColor = '#614a19'; }

			html += '<li style="padding:4px 0;border-top:1px solid #f0f0f1;">';
			html += '<div style="display:flex;align-items:center;gap:8px;">';
			html += statusBadge(t);
			html += '<span style="flex:1;min-width:0;color:' + labelColor + ';overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escAttr(statusLabel(t)) + '">' + escHtml(statusLabel(t));
			if (t.status === 'done' && t.editLink) {
				html += ' <a href="' + escAttr(t.editLink) + '" style="margin-left:6px;">' + escHtml(S.openTranslation) + '</a>';
			}
			html += '</span>';
			if (t.status === 'running') {
				if (typeof t.percent === 'number' && t.percent >= 0) {
					html += '<span style="color:#50575e;font-size:12px;font-variant-numeric:tabular-nums;flex:none;">' + Math.min(100, t.percent) + '%</span>';
				}
				html += '<button type="button" class="button button-small slytranslate-bg-cancel" data-task-id="' + escAttr(t.id) + '">' + escHtml(S.cancel) + '</button>';
			} else {
				html += '<button type="button" class="button-link slytranslate-bg-dismiss" aria-label="' + escAttr(S.dismiss) + '" data-task-id="' + escAttr(t.id) + '" style="color:#50575e;font-size:18px;line-height:1;flex:none;">&times;</button>';
			}
			html += '</div>';

			if (t.status === 'running') {
				var pct = (typeof t.percent === 'number' && t.percent >= 0) ? Math.min(100, t.percent) : 0;
				var phaseLabel = t.phaseLabel ? t.phaseLabel : '';
				html += '<div style="margin-top:4px;display:flex;align-items:center;gap:8px;">';
				html += '<div style="flex:1;height:4px;border-radius:999px;overflow:hidden;background:#dcdcde;">';
				html += '<div style="width:' + pct + '%;height:100%;background:linear-gradient(90deg,#3858e9 0%,#1d4ed8 100%);transition:width .3s ease;"></div>';
				html += '</div>';
				if (phaseLabel) {
					html += '<span style="font-size:11px;color:#50575e;flex:none;max-width:50%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escAttr(phaseLabel) + '">' + escHtml(phaseLabel) + '</span>';
				}
				html += '</div>';
			}

			html += '</li>';
		});
		html += '</ul>';
		notice.innerHTML = html;
	}

	function persistAndRender() {
		tasks = pruneTasks(tasks);
		saveTasks(tasks);
		render();
	}

	function apiPost(endpoint, body) {
		return fetch(REST_URL + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
			body: JSON.stringify(body || {}),
		}).then(function (r) { return r.json(); });
	}

	function getProgressLabel(p) {
		if (!p || !p.phase) { return S.translating; }
		switch (p.phase) {
			case 'title': return S.progressTitle;
			case 'content':
				var total = parseInt(p.total_chunks, 10) || 0;
				var curr = parseInt(p.current_chunk, 10) || 0;
				if (total > 0 && curr >= total) {
					return S.progressContentFinishing;
				}
				return S.progressContent;
			case 'excerpt': return S.progressExcerpt;
			case 'meta': return S.progressMeta;
			case 'saving': return S.progressSaving;
			default: return S.translating;
		}
	}

	function pollTask(task) {
		if (task.status !== 'running') { return; }
		if (!task.postId || !task.lang) { return; }

		apiPost('ai-translate/get-translation-status/run', { input: { post_id: task.postId } })
			.then(function (resp) {
				if (!resp || !Array.isArray(resp.translations)) { return; }
				for (var i = 0; i < resp.translations.length; i++) {
					var entry = resp.translations[i];
					if (entry.lang === task.lang && entry.exists) {
						slyDebug('translation finished (status check)', { taskId: task.id, postId: task.postId, lang: task.lang, editLink: entry.edit_link });
						finishTask(task.id, 'done', entry.edit_link || '');
						return;
					}
				}
			})
			.catch(function (err) { slyDebug('status check FAILED', { taskId: task.id, error: String(err && err.message || err) }); });
	}

	function pollProgress() {
		var runningTasks = tasks.filter(function (t) { return t.status === 'running'; });
		if (!runningTasks.length) { return; }

		runningTasks.forEach(function (t) {
			if (!t.postId) { return; }
			var startedAt = Date.now();
			apiPost('ai-translate/get-progress/run', { input: { post_id: t.postId } })
				.then(function (p) {
					var elapsedMs = Date.now() - startedAt;
					if (slyDebugEnabled()) {
						slyDebug('progress poll', {
							taskId: t.id,
							postId: t.postId,
							postTitle: t.postTitle,
							lang: t.lang,
							runningSec: Math.round((Date.now() - (t.startedAt || Date.now())) / 1000),
							responseMs: elapsedMs,
							response: p,
						});
					}
					if (!p || !p.phase) { return; }
					var percent = Math.max(0, Math.min(100, parseInt(p.percent, 10) || 0));
					if (typeof t.percent === 'number' && t.percent > percent) {
						percent = t.percent;
					}
					var phaseLabel = getProgressLabel(p);
					if (t.percent !== percent || t.phaseLabel !== phaseLabel) {
						slyDebug('progress changed', { taskId: t.id, from: { percent: t.percent, label: t.phaseLabel }, to: { percent: percent, label: phaseLabel } });
						t.percent = percent;
						t.phaseLabel = phaseLabel;
						t.lastChangeAt = Date.now();
						persistAndRender();
					} else if (t.lastChangeAt && (Date.now() - t.lastChangeAt) > 90000) {
						slyDebug('marking task as error — no progress for >90s', { taskId: t.id, postId: t.postId, lastPhase: p.phase, lastPercent: percent });
						finishTask(t.id, 'error', '');
					} else if (t.lastChangeAt && (Date.now() - t.lastChangeAt) > 30000) {
						slyDebug('progress STALLED — no change for >30s', {
							taskId: t.id,
							postId: t.postId,
							percent: percent,
							phase: p.phase,
							phaseLabel: phaseLabel,
							currentChunk: p.current_chunk,
							totalChunks: p.total_chunks,
							stalledForSec: Math.round((Date.now() - t.lastChangeAt) / 1000),
						});
					}
				})
				.catch(function (err) {
					slyDebug('progress poll FAILED', { taskId: t.id, postId: t.postId, error: String(err && err.message || err) });
				});
		});
	}

	function pollAll() {
		tasks.forEach(pollTask);
		pollProgress();
	}

	function addTask(spec) {
		var id = 'sly-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
		var task = {
			id: id,
			postId: parseInt(spec.postId, 10) || 0,
			postTitle: String(spec.postTitle || ''),
			lang: String(spec.lang || ''),
			langName: String(spec.langName || spec.lang || ''),
			status: 'running',
			startedAt: Date.now(),
			lastChangeAt: Date.now(),
			editLink: '',
		};
		slyDebug('addTask', task);
		tasks.push(task);
		persistAndRender();
		setTimeout(function () { pollTask(task); }, 1500);
		// Kick the poll loop in case it was idle (no previous running task).
		bgStartPoll();
		return id;
	}

	function finishTask(id, status, editLink) {
		var task = findTask(id);
		if (!task) { return; }
		slyDebug('finishTask', { taskId: id, status: status, editLink: editLink, totalDurationSec: Math.round((Date.now() - (task.startedAt || Date.now())) / 1000) });
		task.status = status;
		task.editLink = editLink || '';
		task.finishedAt = Date.now();
		persistAndRender();
	}

	function dismissTask(id) {
		tasks = tasks.filter(function (t) { return t.id !== id; });
		persistAndRender();
	}

	function cancelTask(id) {
		apiPost('ai-translate/cancel-translation/run', {}).catch(function () { });
		finishTask(id, 'cancelled', '');
	}

	document.addEventListener('click', function (e) {
		var cancelEl = e.target.closest('.slytranslate-bg-cancel');
		if (cancelEl) { cancelTask(cancelEl.getAttribute('data-task-id')); return; }
		var dismissEl = e.target.closest('.slytranslate-bg-dismiss');
		if (dismissEl) { dismissTask(dismissEl.getAttribute('data-task-id')); return; }
		var dismissAllEl = e.target.closest('.slytranslate-bg-dismiss-all');
		if (dismissAllEl) {
			tasks = tasks.filter(function (t) { return t.status === 'running'; });
			persistAndRender();
			return;
		}
		var toggleEl = e.target.closest('.slytranslate-bg-toggle');
		if (toggleEl) { setCollapsed(!isCollapsed()); render(); return; }
	});

	window.addEventListener('storage', function (event) {
		if (event.key !== STORAGE_KEY) { return; }
		tasks = pruneTasks(loadTasks());
		render();
		// Another tab may have added a task – ensure poll loop is running.
		bgStartPoll();
	});

	/* -------------------------------------------------------
	 * Managed poll loop: only active while tasks are running
	 * and paused when the browser tab is hidden.
	 * ------------------------------------------------------- */
	var bgPollTimer = null;
	var bgPollPaused = false;

	function hasRunningTasks() {
		for (var i = 0; i < tasks.length; i++) {
			if (tasks[i].status === 'running') { return true; }
		}
		return false;
	}

	function bgStopPoll() {
		if (bgPollTimer !== null) {
			clearTimeout(bgPollTimer);
			bgPollTimer = null;
		}
	}

	function bgScheduleNextPoll() {
		bgStopPoll();
		if (bgPollPaused || !hasRunningTasks()) { return; }
		bgPollTimer = setTimeout(function () {
			bgPollTimer = null;
			pollAll();
			bgScheduleNextPoll();
		}, getBgNextPollDelay());
	}

	function bgStartPoll() {
		bgPollStartedAt = Date.now();
		if (!bgPollPaused && hasRunningTasks() && bgPollTimer === null) {
			pollAll();
			bgScheduleNextPoll();
		}
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			bgPollPaused = true;
			bgStopPoll();
		} else {
			bgPollPaused = false;
			bgStartPoll();
		}
	});

	window.SlyTranslateBg = {
		addTask: addTask,
		finishTask: finishTask,
		dismissTask: dismissTask,
	};

	render();
	bgStartPoll();
})();
