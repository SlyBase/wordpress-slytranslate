(function () {
	'use strict';

	var cfg = window.SlyTranslateTranslatePressEditor || {};
	var strings = cfg.i18n || {};
	var mounted = false;
	var pollTimer = null;
	var abortCtrl = null;
	var cancelRevealTimer = null;
	var isRunning = false;
	var cancelVisible = false;
	var cancelRevealDelayMs = 1200;
	var state = {
		modelSlug: readStoredModelSlug() || cfg.defaultModelSlug || '',
		additionalPrompt: typeof cfg.lastAdditionalPrompt === 'string' ? cfg.lastAdditionalPrompt : '',
	};

	function text(key, fallback) {
		return strings[key] || fallback;
	}

	function debugLog(eventName, context) {
		if (!cfg.debugLogEnabled) {
			return;
		}

		apiPost('ai-translate/log-editor-event/run', {
			input: Object.assign({
				event: eventName,
				post_id: cfg.postId || 0,
			}, context || {}),
		}).catch(function () {
		});
	}

	function getTargetLanguages() {
		var source = cfg.sourceLanguage || '';
		var languages = Array.isArray(cfg.languages) ? cfg.languages : [];
		return languages.filter(function (language) {
			return language && language.code && language.code !== source;
		});
	}

	function readStoredModelSlug() {
		try {
			return window.localStorage ? window.localStorage.getItem('slytranslateTrpModelSlug') || '' : '';
		} catch (error) {
			return '';
		}
	}

	function storeModelSlug(slug) {
		try {
			if (!window.localStorage) {
				return;
			}

			if (slug) {
				window.localStorage.setItem('slytranslateTrpModelSlug', slug);
			} else {
				window.localStorage.removeItem('slytranslateTrpModelSlug');
			}
		} catch (error) {
		}
	}

	function apiPost(endpoint, body, signal) {
		return fetch((cfg.restUrl || '') + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce || '',
			},
			body: JSON.stringify(body || {}),
			signal: signal || undefined,
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (payload && typeof payload === 'object' && typeof payload.code === 'string' && typeof payload.message === 'string') {
					var error = new Error(payload.message);
					error.code = payload.code;
					error.data = payload.data || null;
					throw error;
				}

				return payload;
			});
		});
	}

	function getErrorMessage(error) {
		if (error && error.message) {
			return error.message;
		}

		return text('unknownError', 'An unexpected error occurred.');
	}

	function getProgressLabel(progress) {
		if (!progress || !progress.phase) {
			return '';
		}

		switch (progress.phase) {
			case 'title':
				return text('progressTitle', 'Translating title...');
			case 'content':
				if ((progress.total_chunks || 0) > 0 && (progress.current_chunk || 0) >= (progress.total_chunks || 0)) {
					return text('progressContentFinishing', 'Processing translated content...');
				}
				return text('progressContent', 'Translating content...');
			case 'excerpt':
				return text('progressExcerpt', 'Translating excerpt...');
			case 'meta':
				return text('progressMeta', 'Translating metadata...');
			case 'saving':
				return text('progressSaving', 'Saving translation...');
			case 'done':
				return text('progressDone', 'Translation complete.');
			default:
				return '';
		}
	}

	function saveAdditionalPromptPreference(value) {
		apiPost('ai-translate/save-additional-prompt/run', {
			input: {
				additional_prompt: value || '',
			},
		}).catch(function () {
		});
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	function clearCancelRevealTimer() {
		if (cancelRevealTimer) {
			window.clearTimeout(cancelRevealTimer);
			cancelRevealTimer = null;
		}
	}

	function schedulePolling(panel) {
		stopPolling();

		function tick() {
			apiPost('ai-translate/get-progress/run', { input: { post_id: cfg.postId || 0 } }).then(function (progress) {
				if (!isRunning) {
					return;
				}

				var percent = Math.max(0, Math.min(100, parseInt(progress && progress.percent, 10) || 0));
				panel.progressBar.style.width = percent + '%';
				panel.progressLabel.textContent = getProgressLabel(progress);
				if (progress && progress.phase) {
					debugLog('progress_tick', {
						phase: progress.phase,
						percent: percent,
						is_running: isRunning,
					});
				}
			}).catch(function () {
			}).finally(function () {
				if (!isRunning) {
					return;
				}

				pollTimer = window.setTimeout(tick, 2000);
			});
		}

		tick();
	}

	function setStatus(panel, message, tone) {
		panel.status.className = 'slytranslate-trp-status slytranslate-trp-status-' + tone;
		panel.status.innerHTML = '';
		panel.status.appendChild(document.createTextNode(message));
		panel.status.hidden = false;
	}

	function updateFormState(panel) {
		var disabled = !cfg.enabled || isRunning;
		var canCancel = isRunning && cancelVisible;
		panel.model.disabled = disabled;
		panel.refreshModels.disabled = disabled;
		panel.prompt.disabled = disabled;
		panel.start.disabled = !cfg.enabled || getTargetLanguages().length < 1 || (isRunning && !canCancel);
		panel.start.textContent = canCancel ? text('cancelButton', 'Übersetzung abbrechen') : text('startButton', 'Übersetzen');
		panel.start.classList.toggle('slytranslate-trp-start-cancel', canCancel);
		panel.progress.hidden = !isRunning;
		panel.progressLabel.hidden = !isRunning;
	}

	function resetProgress(panel) {
		stopPolling();
		clearCancelRevealTimer();
		panel.progress.hidden = true;
		panel.progressLabel.hidden = true;
		panel.progressBar.style.width = '0%';
		panel.progressLabel.textContent = '';
	}

	function scheduleCancelReveal(panelApi) {
		clearCancelRevealTimer();

		cancelRevealTimer = window.setTimeout(function () {
			cancelRevealTimer = null;
			if (!isRunning) {
				return;
			}

			cancelVisible = true;
			debugLog('cancel_revealed', {
				target_count: getTargetLanguages().length,
				is_running: isRunning,
			});
			updateFormState(panelApi);
		}, cancelRevealDelayMs);
	}

	function isVisibleElement(element) {
		return !!(element && (element.offsetWidth || element.offsetHeight || (element.getClientRects && element.getClientRects().length)));
	}

	function findVisibleTranslatePressFields() {
		var controls = document.querySelector('#trp-controls');
		if (!controls) {
			return null;
		}

		var candidates = Array.prototype.filter.call(
			controls.querySelectorAll('textarea, input[type="text"]'),
			function (field) {
				return isVisibleElement(field) && !field.classList.contains('slytranslate-trp-textarea');
			}
		);

		if (!candidates.length) {
			return null;
		}

		return {
			sourceField: candidates.length > 1 ? candidates[0] : null,
			targetField: candidates[candidates.length - 1],
			controls: controls,
		};
	}

	function inferVisibleTargetLanguage(fields) {
		var haystacks = [];
		var targetField = fields && fields.targetField ? fields.targetField : null;
		var controls = fields && fields.controls ? fields.controls : null;

		if (targetField) {
			if (targetField.labels && targetField.labels.length) {
				haystacks = haystacks.concat(Array.prototype.map.call(targetField.labels, function (label) {
					return label.textContent || '';
				}));
			}
			haystacks.push(targetField.getAttribute('aria-label') || '');
			haystacks.push(targetField.getAttribute('placeholder') || '');
			haystacks.push(targetField.name || '');
			haystacks.push(targetField.id || '');
			if (targetField.parentElement) {
				haystacks.push(targetField.parentElement.textContent || '');
			}
		}

		if (controls) {
			haystacks.push(controls.textContent || '');
		}

		var joined = haystacks.join(' ').toLowerCase();
		var languages = Array.isArray(cfg.languages) ? cfg.languages.slice() : [];
		languages.sort(function (left, right) {
			return (right.name || '').length - (left.name || '').length;
		});

		for (var index = 0; index < languages.length; index += 1) {
			var language = languages[index];
			if (!language || !language.code || language.code === cfg.sourceLanguage) {
				continue;
			}

			var name = String(language.name || '').toLowerCase();
			var code = String(language.code || '').toLowerCase();
			if ((name && joined.indexOf(name) !== -1) || joined.indexOf('(' + code + ')') !== -1 || joined.indexOf(' ' + code + ' ') !== -1 || (name && joined.indexOf('in ' + name) !== -1)) {
				return language.code;
			}
		}

		return languages.length === 1 ? languages[0].code : '';
	}

	function updateVisibleTranslatePressField(textValue) {
		var fields = findVisibleTranslatePressFields();
		if (!fields || !fields.targetField || !textValue) {
			return false;
		}

		fields.targetField.value = textValue;
		fields.targetField.dispatchEvent(new Event('input', { bubbles: true }));
		fields.targetField.dispatchEvent(new Event('change', { bubbles: true }));
		fields.targetField.dispatchEvent(new Event('keyup', { bubbles: true }));
		return true;
	}

	function syncVisibleTranslatePressTranslation() {
		var fields = findVisibleTranslatePressFields();
		if (!fields || !fields.targetField || !fields.sourceField) {
			debugLog('sync_skipped_missing_fields', {
				has_source_field: !!(fields && fields.sourceField),
				has_target_field: !!(fields && fields.targetField),
			});
			return Promise.resolve(false);
		}

		var sourceText = String(fields.sourceField.value || '').trim();
		var targetLanguage = inferVisibleTargetLanguage(fields);
		if (!sourceText || !targetLanguage) {
			debugLog('sync_skipped_missing_values', {
				source_length: sourceText.length,
				target_language: targetLanguage || '',
			});
			return Promise.resolve(false);
		}

		return apiPost('ai-translate/get-existing-translation/run', {
			input: {
				source_text: sourceText,
				target_language: targetLanguage,
			},
		}).then(function (response) {
			if (!response || !response.found || !response.translated_text) {
				debugLog('sync_not_found', {
					target_language: targetLanguage,
					found: !!(response && response.found),
					source_length: sourceText.length,
				});
				return false;
			}

			debugLog('sync_applied', {
				target_language: targetLanguage,
				source_length: sourceText.length,
			});
			return updateVisibleTranslatePressField(response.translated_text);
		}).catch(function () {
			debugLog('sync_failed', {
				target_language: targetLanguage,
				source_length: sourceText.length,
			});
			return false;
		});
	}

	function populateSelect(select, items, selectedValue, includeEmptyLabel) {
		select.innerHTML = '';
		if (includeEmptyLabel) {
			var emptyOption = document.createElement('option');
			emptyOption.value = '';
			emptyOption.textContent = includeEmptyLabel;
			select.appendChild(emptyOption);
		}

		items.forEach(function (item) {
			if (!item || !item.value) {
				return;
			}
			var option = document.createElement('option');
			option.value = item.value;
			option.textContent = item.label;
			option.selected = item.value === selectedValue;
			select.appendChild(option);
		});
	}

	function createField(labelText, control) {
		var wrap = document.createElement('div');
		wrap.className = 'slytranslate-trp-field';
		var label = document.createElement('label');
		label.className = 'slytranslate-trp-label';
		label.textContent = labelText;
		wrap.appendChild(label);
		wrap.appendChild(control);
		return wrap;
	}

	function findMountTarget() {
		var controls = document.querySelector('#trp-controls');
		if (!controls) {
			return null;
		}

		var children = Array.prototype.slice.call(controls.children || []);
		var upsellMatch = children.find(function (child) {
			var childText = (child && child.textContent ? child.textContent : '').toLowerCase();
			return childText.indexOf('extra translation features') !== -1
				|| childText.indexOf('extra-übersetzungs-funktionen') !== -1
				|| childText.indexOf('upgrade to pro') !== -1
				|| childText.indexOf('support for 130') !== -1;
		});

		if (upsellMatch) {
			return {
				mode: 'before',
				element: upsellMatch,
			};
		}

		var firstSection = document.querySelector('#trp-controls-section-first');
		if (firstSection && firstSection.nextElementSibling) {
			return {
				mode: 'after',
				element: firstSection.nextElementSibling,
			};
		}

		return {
			mode: 'append',
			element: controls,
		};
	}

	function runTranslationsForAllLanguages(panelApi, languages, index) {
		if (!isRunning) {
			return Promise.resolve();
		}

		if (index >= languages.length) {
			return Promise.resolve();
		}

		var language = languages[index];
		panelApi.progressLabel.textContent = text('translatingLanguage', 'Translating {language}...').replace('{language}', language.name);

		abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;

		return apiPost('ai-translate/translate-content/run', {
			input: {
				post_id: cfg.postId || 0,
				source_language: cfg.sourceLanguage || undefined,
				target_language: language.code,
				post_status: 'draft',
				translate_title: true,
				overwrite: true,
				model_slug: state.modelSlug || undefined,
				additional_prompt: state.additionalPrompt || undefined,
			},
		}, abortCtrl ? abortCtrl.signal : undefined).then(function () {
			return runTranslationsForAllLanguages(panelApi, languages, index + 1);
		});
	}

	function mountPanel(anchor) {
		if (mounted || !anchor) {
			return;
		}

		var panel = document.createElement('section');
		panel.className = 'slytranslate-trp-panel';
		panel.innerHTML = '<div class="slytranslate-trp-header"></div><div class="slytranslate-trp-actions slytranslate-trp-actions-top"></div><div class="slytranslate-trp-status" hidden></div><div class="slytranslate-trp-progress" hidden><div class="slytranslate-trp-progress-bar"></div></div><p class="slytranslate-trp-progress-label" hidden></p><div class="slytranslate-trp-fields"></div>';

		var header = panel.querySelector('.slytranslate-trp-header');
		header.textContent = text('panelTitle', 'Translate with SlyTranslate');

		var fields = panel.querySelector('.slytranslate-trp-fields');
		var actionsTop = panel.querySelector('.slytranslate-trp-actions-top');

		var start = document.createElement('button');
		start.type = 'button';
		start.className = 'button button-primary slytranslate-trp-start';
		start.textContent = text('startButton', 'Übersetzen');
		actionsTop.appendChild(start);

		var modelRow = document.createElement('div');
		modelRow.className = 'slytranslate-trp-model-row';
		var model = document.createElement('select');
		model.className = 'slytranslate-trp-select';
		var refreshModels = document.createElement('button');
		refreshModels.type = 'button';
		refreshModels.className = 'button button-secondary slytranslate-trp-refresh-button';
		refreshModels.setAttribute('aria-label', text('refreshModelsButton', 'Neu laden'));
		refreshModels.title = text('refreshModelsButton', 'Neu laden');
		refreshModels.textContent = text('refreshModelsButton', 'Neu laden');
		modelRow.appendChild(model);
		modelRow.appendChild(refreshModels);
		fields.appendChild(createField(text('modelLabel', 'AI model'), modelRow));

		var promptDetails = document.createElement('details');
		promptDetails.className = 'slytranslate-trp-details';
		var promptSummary = document.createElement('summary');
		promptSummary.className = 'slytranslate-trp-details-summary';
		promptSummary.textContent = text('additionalPromptLabel', 'Additional instructions (optional)');
		promptDetails.appendChild(promptSummary);

		var prompt = document.createElement('textarea');
		prompt.className = 'slytranslate-trp-textarea';
		prompt.rows = 4;
		prompt.value = state.additionalPrompt;
		var promptField = document.createElement('div');
		promptField.className = 'slytranslate-trp-field slytranslate-trp-field-nested';
		promptField.appendChild(prompt);
		var promptHelp = document.createElement('p');
		promptHelp.className = 'slytranslate-trp-help';
		promptHelp.textContent = text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.');
		promptField.appendChild(promptHelp);
		promptDetails.appendChild(promptField);
		fields.appendChild(promptDetails);

		if (anchor.mode === 'before' && anchor.element && anchor.element.parentNode) {
			anchor.element.parentNode.insertBefore(panel, anchor.element);
		} else if (anchor.mode === 'after' && anchor.element) {
			anchor.element.insertAdjacentElement('afterend', panel);
		} else if (anchor.element) {
			anchor.element.appendChild(panel);
		}
		mounted = true;
		debugLog('panel_mounted', {
			target_count: getTargetLanguages().length,
			model_slug: state.modelSlug || '',
		});

		var panelApi = {
			status: panel.querySelector('.slytranslate-trp-status'),
			progress: panel.querySelector('.slytranslate-trp-progress'),
			progressBar: panel.querySelector('.slytranslate-trp-progress-bar'),
			progressLabel: panel.querySelector('.slytranslate-trp-progress-label'),
			model: model,
			refreshModels: refreshModels,
			prompt: prompt,
			start: start,
		};

		populateSelect(model, [{ value: '', label: '— Auto —' }].concat((cfg.models || []).map(function (entry) {
			return { value: entry.value, label: entry.label };
		})), state.modelSlug, '');

		if (!cfg.enabled) {
			setStatus(panelApi, text('unsupportedNotice', 'SlyTranslate is only available here for singular posts and pages you can edit.'), 'warning');
		}

		model.addEventListener('change', function () {
			state.modelSlug = model.value;
			storeModelSlug(state.modelSlug);
		});

		prompt.addEventListener('input', function () {
			state.additionalPrompt = prompt.value;
		});

		prompt.addEventListener('blur', function () {
			saveAdditionalPromptPreference(state.additionalPrompt);
		});

		refreshModels.addEventListener('click', function () {
			debugLog('refresh_models_clicked', {
				model_slug: state.modelSlug || '',
			});
			refreshModels.disabled = true;
			apiPost('ai-translate/get-available-models/run', { input: { refresh: true } }).then(function (response) {
				cfg.models = response && Array.isArray(response.models) ? response.models : [];
				populateSelect(model, [{ value: '', label: '— Auto —' }].concat((cfg.models || []).map(function (entry) {
					return { value: entry.value, label: entry.label };
				})), state.modelSlug, '');
			}).catch(function (error) {
				debugLog('refresh_models_failed', {
					reason: getErrorMessage(error),
				});
				setStatus(panelApi, text('errorPrefix', 'Translation failed:') + ' ' + getErrorMessage(error), 'error');
			}).finally(function () {
				debugLog('refresh_models_finished', {
					model_slug: state.modelSlug || '',
				});
				refreshModels.disabled = false;
			});
		});

		start.addEventListener('click', function () {
			debugLog('start_clicked', {
				is_running: isRunning,
				target_count: getTargetLanguages().length,
				model_slug: state.modelSlug || '',
			});
			if (isRunning) {
				debugLog('cancel_requested', {
					target_count: getTargetLanguages().length,
				});
				if (abortCtrl) {
					abortCtrl.abort();
				}
				apiPost('ai-translate/cancel-translation/run', {
					input: {
						post_id: cfg.postId || 0,
					},
				}).catch(function () {
				});
				return;
			}

			var targetLanguages = getTargetLanguages();
			if (!cfg.enabled || !targetLanguages.length || isRunning) {
				debugLog('start_blocked', {
					reason: !cfg.enabled ? 'disabled_context' : (!targetLanguages.length ? 'no_target_languages' : 'already_running'),
					target_count: targetLanguages.length,
					is_running: isRunning,
				});
				return;
			}

			var visibleFields = findVisibleTranslatePressFields();
			debugLog('translation_run_started', {
				target_count: targetLanguages.length,
				model_slug: state.modelSlug || '',
				has_source_field: !!(visibleFields && visibleFields.sourceField),
				has_target_field: !!(visibleFields && visibleFields.targetField),
				source_length: visibleFields && visibleFields.sourceField ? String(visibleFields.sourceField.value || '').trim().length : 0,
			});

			isRunning = true;
			cancelVisible = false;
			panelApi.status.hidden = true;
			resetProgress(panelApi);
			panelApi.progress.hidden = false;
			panelApi.progressLabel.hidden = false;
			panelApi.progressLabel.textContent = text('startButton', 'Übersetzen');
			updateFormState(panelApi);
			scheduleCancelReveal(panelApi);
			schedulePolling(panelApi);

			runTranslationsForAllLanguages(panelApi, targetLanguages, 0).then(function () {
				saveAdditionalPromptPreference(state.additionalPrompt);
				debugLog('translation_run_completed', {
					target_count: targetLanguages.length,
				});
				return syncVisibleTranslatePressTranslation();
			}).then(function () {
				var successMessage = text('successNotice', 'Translation completed successfully.');
				setStatus(panelApi, successMessage, 'success');
			}).catch(function (error) {
				if (error && error.name === 'AbortError') {
					debugLog('translation_run_aborted', {
						reason: 'abort_error',
					});
					setStatus(panelApi, text('cancelNotice', 'Translation cancelled.'), 'warning');
					return;
				}
				debugLog('translation_run_failed', {
					reason: getErrorMessage(error),
				});
				setStatus(panelApi, text('errorPrefix', 'Translation failed:') + ' ' + getErrorMessage(error), 'error');
			}).finally(function () {
				debugLog('translation_run_finally', {
					is_running: isRunning,
					target_count: targetLanguages.length,
				});
				isRunning = false;
				cancelVisible = false;
				abortCtrl = null;
				resetProgress(panelApi);
				updateFormState(panelApi);
			});
		});

		updateFormState(panelApi);
	}

	function tryMount() {
		if (mounted) {
			return;
		}

		var anchor = findMountTarget();
		if (!anchor) {
			return;
		}

		mountPanel(anchor);
	}

	function boot() {
		tryMount();
		if (mounted) {
			return;
		}

		var observer = new MutationObserver(function () {
			tryMount();
			if (mounted) {
				observer.disconnect();
			}
		});

		observer.observe(document.body, { childList: true, subtree: true });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();