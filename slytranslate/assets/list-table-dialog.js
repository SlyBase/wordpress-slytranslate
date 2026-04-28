(function () {
	var _data = window.SlyTranslateListTable || {};
	var REST_URL = _data.restUrl || '';
	var REST_NONCE = _data.restNonce || '';
	var S = _data.i18n || {};

	var overlay = document.getElementById('slytranslate-list-overlay');
	var titleEl = document.getElementById('slytranslate-list-title');
	var barEl = document.getElementById('slytranslate-list-bar');
	var labelEl = document.getElementById('slytranslate-list-label');
	var progressWrap = document.getElementById('slytranslate-list-progress-wrap');
	var resultEl = document.getElementById('slytranslate-list-result');
	var cancelBtn = document.getElementById('slytranslate-list-cancel');
	var bgBtn = document.getElementById('slytranslate-list-bg');
	var closeBtn = document.getElementById('slytranslate-list-close');

	var pollTimer = null;
	var abortCtrl = null;
	var isRunning = false;
	var isCancelling = false;
	var movedToBackground = false;
	var currentBgTaskId = null;

	/* --- Background bar bridge --- */
	function bgApi() {
		return (typeof window !== 'undefined' && window.SlyTranslateBg) || null;
	}

	function addBgTask(postId, postTitle, lang, langName) {
		var api = bgApi();
		if (!api) { return null; }
		return api.addTask({ postId: postId, postTitle: postTitle, lang: lang, langName: langName });
	}

	function finishBgTask(id, status, editLink) {
		var api = bgApi();
		if (api && id) { api.finishTask(id, status, editLink || ''); }
	}

	function hasForegroundTranslationInProgress() {
		return isRunning && !isCancelling && currentPostId > 0 && !!currentLang;
	}

	function handOffRunningTranslationToBackground(options) {
		options = options || {};
		if (!hasForegroundTranslationInProgress() || movedToBackground) {
			return currentBgTaskId;
		}

		var taskId = addBgTask(currentPostId, currentPostTitle, currentLang, currentLangName);
		if (!taskId) {
			return null;
		}

		currentBgTaskId = taskId;
		movedToBackground = true;
		stopPolling();

		if (false !== options.hideOverlay) {
			hideOverlay({ bypassAutoBackground: true });
		}

		return currentBgTaskId;
	}

	/* --- Shared helpers --- */

	function apiPost(endpoint, body, signal, options) {
		options = options || {};
		return fetch(REST_URL + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE },
			body: JSON.stringify(body),
			keepalive: !!options.keepalive,
			signal: signal || undefined,
		}).then(function (r) { return r.json(); });
	}

	function getProgressLabel(p) {
		if (!p || !p.phase) return S.translating;
		switch (p.phase) {
			case 'title': return S.progressTitle;
			case 'content': return S.progressContent;
			case 'excerpt': return S.progressExcerpt;
			case 'meta': return S.progressMeta;
			case 'saving': return S.progressSaving;
			default: return S.translating;
		}
	}

	function getSafeEditLink(editLink) {
		if (!editLink || 'string' !== typeof editLink) {
			return '';
		}

		try {
			var parsed = new URL(editLink, window.location.href);
			if (parsed.origin !== window.location.origin) {
				return '';
			}
			if ('http:' !== parsed.protocol && 'https:' !== parsed.protocol) {
				return '';
			}
			return parsed.href;
		} catch (e) {
			return '';
		}
	}

	/* --- Overlay dialog (foreground) --- */

	function pollProgress() {
		apiPost('ai-translate/get-progress/run', { input: { post_id: currentPostId } }).then(function (p) {
			if (!isRunning) return;
			if (p && p.phase) {
				barEl.style.width = (p.percent || 0) + '%';
				labelEl.textContent = getProgressLabel(p);
			}
		}).catch(function () { });
	}

	function startPolling() {
		stopPolling();
		var pollStartedAt = Date.now();
		function getNextPollDelay() {
			var elapsed = Date.now() - pollStartedAt;
			if (elapsed < 30000) { return 1000; }
			if (elapsed < 120000) { return 2000; }
			return 5000;
		}
		function scheduleNextPoll() {
			pollTimer = setTimeout(function () {
				pollProgress();
				scheduleNextPoll();
			}, getNextPollDelay());
		}
		pollProgress();
		scheduleNextPoll();
	}

	function stopPolling() {
		if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
	}

	function showOverlay(langName) {
		titleEl.textContent = S.translatingTo.replace('%s', langName);
		barEl.style.width = '0%';
		labelEl.textContent = S.translating;
		progressWrap.style.display = '';
		resultEl.style.display = 'none';
		cancelBtn.style.display = '';
		bgBtn.style.display = '';
		closeBtn.style.display = 'none';
		overlay.style.display = 'flex';
		movedToBackground = false;
	}

	function showResult(message, type, editLink) {
		stopPolling();
		isRunning = false;
		progressWrap.style.display = 'none';
		cancelBtn.style.display = 'none';
		bgBtn.style.display = 'none';
		closeBtn.style.display = '';
		resultEl.style.display = '';
		resultEl.style.background = type === 'success' ? '#edfaef' : (type === 'warning' ? '#fef8ee' : '#fcecec');
		resultEl.style.color = type === 'success' ? '#1e4620' : (type === 'warning' ? '#614a19' : '#8a1f1f');
		var safeEditLink = getSafeEditLink(editLink);
		resultEl.textContent = '';
		resultEl.appendChild(document.createTextNode(String(message || '')));
		if (safeEditLink) {
			var link = document.createElement('a');
			link.href = safeEditLink;
			link.style.fontWeight = '600';
			link.textContent = S.openTranslation;
			resultEl.appendChild(document.createTextNode(' '));
			resultEl.appendChild(link);
		}
	}

	function hideOverlay(options) {
		options = options || {};
		if (!options.bypassAutoBackground && hasForegroundTranslationInProgress() && !movedToBackground) {
			handOffRunningTranslationToBackground();
			return;
		}
		overlay.style.display = 'none';
		stopPolling();
		isRunning = false;
	}

	var currentLangName = '';
	var currentPostId = 0;
	var currentPostTitle = '';
	var currentLang = '';
	var currentModelSlug = '';

	function doTranslate(postId, postTitle, sourceLang, lang, langName, overwrite, modelSlug, additionalPrompt) {
		showOverlay(langName);
		currentLangName = langName;
		currentPostId = postId;
		currentPostTitle = postTitle || '';
		currentLang = lang;
		currentModelSlug = modelSlug || '';
		isRunning = true;
		isCancelling = false;
		abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
		startPolling();

		apiPost('ai-translate/translate-content/run', {
			input: {
				post_id: postId,
				source_language: sourceLang || undefined,
				target_language: lang,
				post_status: 'draft',
				overwrite: !!overwrite,
				translate_title: true,
				model_slug: modelSlug || undefined,
				additional_prompt: additionalPrompt || undefined,
			}
		}, abortCtrl ? abortCtrl.signal : undefined, {
			keepalive: true,
		})
			.then(function (resp) {
				if (movedToBackground) {
					if (resp && resp.code && currentBgTaskId) {
						finishBgTask(currentBgTaskId, 'error', '');
					}
					return;
				}
				if (resp && resp.code) {
					showResult(S.error.replace('%s', resp.message || resp.code), 'error');
				} else {
					barEl.style.width = '100%';
					var editLink = resp && resp.edit_link ? resp.edit_link : '';
					showResult(S.success, 'success', editLink);
				}
			})
			.catch(function (err) {
				if (movedToBackground) {
					if (err && err.name !== 'AbortError' && currentBgTaskId) {
						finishBgTask(currentBgTaskId, 'error', '');
					}
					return;
				}
				if (err && err.name === 'AbortError') {
					showResult(S.cancelled, 'warning');
				} else {
					showResult(S.error.replace('%s', err && err.message ? err.message : ''), 'error');
				}
			})
			.finally(function () {
				abortCtrl = null;
				isCancelling = false;
			});
	}

	// Cancel button.
	cancelBtn.addEventListener('click', function () {
		isCancelling = true;
		if (abortCtrl) { abortCtrl.abort(); }
		apiPost('ai-translate/cancel-translation/run', { input: { post_id: currentPostId } }).catch(function () { });
	});

	// Background button.
	bgBtn.addEventListener('click', function () {
		handOffRunningTranslationToBackground();
	});

	overlay.addEventListener('click', function (e) {
		if (e.target !== overlay || !hasForegroundTranslationInProgress()) {
			return;
		}
		handOffRunningTranslationToBackground();
	});

	document.addEventListener('keydown', function (e) {
		if ('Escape' !== e.key || 'flex' !== overlay.style.display || !hasForegroundTranslationInProgress()) {
			return;
		}
		e.preventDefault();
		handOffRunningTranslationToBackground();
	});

	window.addEventListener('pagehide', function () {
		handOffRunningTranslationToBackground({ hideOverlay: false });
	});

	window.addEventListener('beforeunload', function () {
		handOffRunningTranslationToBackground({ hideOverlay: false });
	});

	// Close button.
	closeBtn.addEventListener('click', function () {
		hideOverlay();
		window.location.reload();
	});

	/* --- Model picker dialog --- */

	var pickerOverlay = document.getElementById('slytranslate-model-picker');
	var pickerTitle = document.getElementById('slytranslate-model-picker-title');
	var pickerSelect = document.getElementById('slytranslate-model-picker-select');
	var pickerStatus = document.getElementById('slytranslate-model-picker-status');
	var pickerStart = document.getElementById('slytranslate-model-picker-start');
	var pickerCancel = document.getElementById('slytranslate-model-picker-cancel');
	var pickerRefresh = document.getElementById('slytranslate-model-picker-refresh');
	var pickerSourceSel = document.getElementById('slytranslate-picker-source');
	var pickerTargetSel = document.getElementById('slytranslate-picker-target');
	var pickerSwapBtn = document.getElementById('slytranslate-picker-swap');
	var pickerPromptEl = document.getElementById('slytranslate-picker-additional-prompt');
	var pickerOverwriteEl = document.getElementById('slytranslate-picker-overwrite');
	var pickerOnConfirm = null;
	var pickerLastSlug = '';
	var pickerAllLanguages = [];
	var pickerMissingCodes = {};
	var pickerExistingCodes = {};
	var pickerBootstrapPrompt = _data.lastAdditionalPrompt || '';
	try { pickerLastSlug = (window.localStorage && window.localStorage.getItem('aiTranslateModelSlug')) || ''; } catch (e) { }

	function readStoredAdditionalPrompt() {
		try {
			var stored = (window.localStorage && window.localStorage.getItem('aiTranslateLastAdditionalPrompt')) || '';
			return stored || pickerBootstrapPrompt || '';
		} catch (e) {
			return pickerBootstrapPrompt || '';
		}
	}
	function storeAdditionalPrompt(value) {
		try { if (window.localStorage) { window.localStorage.setItem('aiTranslateLastAdditionalPrompt', value || ''); } } catch (e) { }
	}
	function readStoredTargetLang() {
		try { return (window.localStorage && window.localStorage.getItem('aiTranslateTargetLanguage')) || ''; } catch (e) { return ''; }
	}
	function storeTargetLang(value) {
		try { if (window.localStorage && value) { window.localStorage.setItem('aiTranslateTargetLanguage', value); } } catch (e) { }
	}

	function fillLanguageSelect(selectEl, languages, selectedCode) {
		selectEl.innerHTML = '';
		languages.forEach(function (l) {
			if (!l || !l.code) { return; }
			var opt = document.createElement('option');
			opt.value = l.code;
			opt.textContent = (l.name || l.code) + ' (' + l.code + ')';
			if (l.code === selectedCode) { opt.selected = true; }
			selectEl.appendChild(opt);
		});
	}

	function refreshTargetOptions(preferredCode) {
		var sourceCode = pickerSourceSel.value;
		var targets = pickerAllLanguages.filter(function (l) { return l && l.code && l.code !== sourceCode; });
		targets.sort(function (a, b) {
			var aMissing = pickerMissingCodes[a.code] ? 0 : 1;
			var bMissing = pickerMissingCodes[b.code] ? 0 : 1;
			return aMissing - bMissing;
		});
		var chosen = '';
		if (preferredCode && targets.some(function (l) { return l.code === preferredCode; })) {
			chosen = preferredCode;
		} else {
			var stored = readStoredTargetLang();
			if (stored && targets.some(function (l) { return l.code === stored; })) {
				chosen = stored;
			} else if (targets.length) {
				chosen = targets[0].code;
			}
		}
		fillLanguageSelect(pickerTargetSel, targets, chosen);
	}

	function fillPickerSelect(models, defaultSlug) {
		pickerSelect.innerHTML = '';
		var preselected = pickerLastSlug || defaultSlug || '';
		var hasPreselected = false;
		if (Array.isArray(models)) {
			models.forEach(function (m) {
				if (!m || !m.value) { return; }
				var opt = document.createElement('option');
				opt.value = m.value;
				opt.textContent = m.label || m.value;
				if (m.value === preselected) { opt.selected = true; hasPreselected = true; }
				pickerSelect.appendChild(opt);
			});
		}
		var autoOpt = document.createElement('option');
		autoOpt.value = '';
		autoOpt.textContent = S.pickerAutoOption;
		if (!hasPreselected) { autoOpt.selected = true; }
		pickerSelect.appendChild(autoOpt);
	}

	function loadPickerModels(forceRefresh) {
		pickerStatus.textContent = S.pickerLoading;
		pickerStart.disabled = true;
		return apiPost('ai-translate/get-available-models/run', { input: { refresh: !!forceRefresh } })
			.then(function (resp) {
				var models = resp && Array.isArray(resp.models) ? resp.models : [];
				var defaultSlug = resp && resp.defaultModelSlug ? resp.defaultModelSlug : '';
				fillPickerSelect(models, defaultSlug);
				if (!models.length) {
					pickerStatus.textContent = S.pickerNoModels;
				} else {
					pickerStatus.textContent = '';
				}
				pickerStart.disabled = false;
			})
			.catch(function () {
				fillPickerSelect([], '');
				pickerStatus.textContent = S.pickerNoModels;
				pickerStart.disabled = false;
			});
	}

	function showPicker(titleText, context, onConfirm) {
		pickerTitle.textContent = titleText;
		pickerOnConfirm = onConfirm;
		context = context || {};
		pickerAllLanguages = Array.isArray(context.allLanguages) && context.allLanguages.length
			? context.allLanguages
			: (Array.isArray(context.languages) ? context.languages : []);
		pickerMissingCodes = {};
		pickerExistingCodes = {};
		if (Array.isArray(context.languages)) {
			context.languages.forEach(function (l) { if (l && l.code) { pickerMissingCodes[l.code] = true; } });
		}
		if (Array.isArray(context.existingLanguages)) {
			context.existingLanguages.forEach(function (code) {
				if (code) { pickerExistingCodes[code] = true; }
			});
		}
		var sourceCode = context.sourceLang || (pickerAllLanguages[0] ? pickerAllLanguages[0].code : '');
		fillLanguageSelect(pickerSourceSel, pickerAllLanguages, sourceCode);
		refreshTargetOptions(context.preferredTarget || '');
		pickerPromptEl.value = readStoredAdditionalPrompt();
		if (pickerOverwriteEl) { pickerOverwriteEl.checked = false; }
		pickerOverlay.style.display = 'flex';
		loadPickerModels(false);
	}

	function hidePicker() {
		pickerOverlay.style.display = 'none';
		pickerOnConfirm = null;
	}

	document.addEventListener('keydown', function (e) {
		if ('Escape' !== e.key || 'flex' !== pickerOverlay.style.display) {
			return;
		}
		e.preventDefault();
		hidePicker();
	});

	pickerCancel.addEventListener('click', hidePicker);
	pickerRefresh.addEventListener('click', function () { loadPickerModels(true); });
	pickerSourceSel.addEventListener('change', function () {
		var prev = pickerTargetSel.value;
		refreshTargetOptions(prev);
	});
	pickerSwapBtn.addEventListener('click', function () {
		var src = pickerSourceSel.value;
		var tgt = pickerTargetSel.value;
		if (!src || !tgt) { return; }
		fillLanguageSelect(pickerSourceSel, pickerAllLanguages, tgt);
		refreshTargetOptions(src);
	});
	pickerStart.addEventListener('click', function () {
		var slug = pickerSelect.value || '';
		try {
			if (window.localStorage) {
				if (slug) { window.localStorage.setItem('aiTranslateModelSlug', slug); }
			}
		} catch (e) { }
		pickerLastSlug = slug;
		var sourceLang = pickerSourceSel.value || '';
		var targetLang = pickerTargetSel.value || '';
		var targetOpt = pickerTargetSel.options[pickerTargetSel.selectedIndex];
		var targetName = targetOpt ? (targetOpt.textContent || '').replace(/\s*\([^)]*\)\s*$/, '').trim() : targetLang;
		var overwrite = pickerOverwriteEl ? !!pickerOverwriteEl.checked : false;
		var additionalPrompt = pickerPromptEl.value || '';
		if (!targetLang) { return; }
		if (!overwrite && pickerExistingCodes[targetLang]) {
			window.alert(S.pickerExistingTranslationNotice || '');
			return;
		}
		if (overwrite && !window.confirm(S.pickerOverwriteWarning || '')) {
			return;
		}
		storeTargetLang(targetLang);
		storeAdditionalPrompt(additionalPrompt);
		var cb = pickerOnConfirm;
		hidePicker();
		if (typeof cb === 'function') {
			cb({
				modelSlug: slug,
				sourceLang: sourceLang,
				targetLang: targetLang,
				targetLangName: targetName,
				overwrite: overwrite,
				additionalPrompt: additionalPrompt,
			});
		}
	});

	/* --- Bulk translation runner (one dialog, many posts) --- */

	function getBulkSelectedPostIds() {
		var ids = [];
		var nodes = document.querySelectorAll('input[name="post[]"]:checked, input[name="ids[]"]:checked');
		for (var i = 0; i < nodes.length; i++) {
			var v = parseInt(nodes[i].value, 10);
			if (v > 0) { ids.push(v); }
		}
		return ids;
	}

	function getRowTitle(postId) {
		var row = document.getElementById('post-' + postId);
		if (!row) { return ''; }
		var titleEl = row.querySelector('.row-title');
		return titleEl ? (titleEl.textContent || '').trim() : '';
	}

	function runBulkTranslation(postIds, sourceLang, lang, langName, overwrite, modelSlug, additionalPrompt) {
		var i = 0;
		function next() {
			if (i >= postIds.length) { return; }
			var postId = postIds[i++];
			var title = getRowTitle(postId);
			var taskId = addBgTask(postId, title, lang, langName);
			apiPost('ai-translate/translate-content/run', {
				input: {
					post_id: postId,
					source_language: sourceLang || undefined,
					target_language: lang,
					post_status: 'draft',
					overwrite: !!overwrite,
					translate_title: true,
					model_slug: modelSlug || undefined,
					additional_prompt: additionalPrompt || undefined,
				}
			}, undefined, {
				keepalive: true,
			}).then(function (resp) {
				if (resp && resp.code) {
					finishBgTask(taskId, 'error', '');
				} else if (resp && resp.edit_link) {
					finishBgTask(taskId, 'done', resp.edit_link);
				}
			}).catch(function () {
				finishBgTask(taskId, 'error', '');
			}).then(next);
		}
		next();
	}

	document.addEventListener('submit', function (e) {
		var form = e.target;
		if (!form || form.id !== 'posts-filter') { return; }
		var topSel = form.querySelector('select[name="action"]');
		var botSel = form.querySelector('select[name="action2"]');
		var actionTop = topSel ? topSel.value : '';
		var actionBot = botSel ? botSel.value : '';
		var action = (actionTop && actionTop !== '-1') ? actionTop : actionBot;
		if (action !== 'ai_translate_bulk') { return; }

		var ids = getBulkSelectedPostIds();
		if (!ids.length) {
			e.preventDefault();
			window.alert(S.pickerNoSelection);
			return;
		}

		e.preventDefault();

		var firstRow = document.getElementById('post-' + ids[0]);
		var refLink = firstRow ? firstRow.querySelector('.slytranslate-ajax-translate') : null;
		var languages = [];
		var allLanguages = [];
		var existingLanguages = [];
		var sourceLang = '';
		if (refLink) {
			try { languages = JSON.parse(refLink.getAttribute('data-langs') || '[]'); } catch (err) { }
			try { allLanguages = JSON.parse(refLink.getAttribute('data-all-langs') || '[]'); } catch (err) { }
			try { existingLanguages = JSON.parse(refLink.getAttribute('data-existing-langs') || '[]'); } catch (err) { }
			sourceLang = refLink.getAttribute('data-source-lang') || '';
		}
		if (!allLanguages.length) { allLanguages = languages; }
		if (!allLanguages.length) {
			window.alert(S.pickerNoModels);
			if (topSel) { topSel.value = '-1'; }
			if (botSel) { botSel.value = '-1'; }
			return;
		}

		var titleText = S.pickerTitleBulk.replace('%d', ids.length);
		showPicker(titleText, {
			sourceLang: sourceLang,
			languages: languages,
			allLanguages: allLanguages,
			existingLanguages: existingLanguages,
		}, function (result) {
			runBulkTranslation(ids, result.sourceLang, result.targetLang, result.targetLangName, result.overwrite, result.modelSlug, result.additionalPrompt);
			if (topSel) { topSel.value = '-1'; }
			if (botSel) { botSel.value = '-1'; }
		});
	}, true);

	document.addEventListener('click', function (e) {
		var link = e.target.closest('.slytranslate-ajax-translate');
		if (!link) return;
		e.preventDefault();
		var postId = parseInt(link.getAttribute('data-post-id'), 10);
		var postTitle = link.getAttribute('data-post-title') || '';
		var sourceLang = link.getAttribute('data-source-lang') || '';
		var languages = [];
		var allLanguages = [];
		var existingLanguages = [];
		try { languages = JSON.parse(link.getAttribute('data-langs') || '[]'); } catch (err) { }
		try { allLanguages = JSON.parse(link.getAttribute('data-all-langs') || '[]'); } catch (err) { }
		try { existingLanguages = JSON.parse(link.getAttribute('data-existing-langs') || '[]'); } catch (err) { }
		if (!allLanguages.length) { allLanguages = languages; }
		if (!postId || !allLanguages.length) { return; }

		showPicker(S.pickerTitle, {
			sourceLang: sourceLang,
			languages: languages,
			allLanguages: allLanguages,
			existingLanguages: existingLanguages,
		}, function (result) {
			doTranslate(postId, postTitle, result.sourceLang, result.targetLang, result.targetLangName, result.overwrite, result.modelSlug, result.additionalPrompt);
		});
	});
})();
