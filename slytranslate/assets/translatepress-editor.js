(function () {
	'use strict';

	var cfg = window.SlyTranslateTranslatePressEditor || {};
	var strings = cfg.i18n || {};
	var mounted = false;
	var pollTimer = null;
	var abortCtrl = null;
	var isRunning = false;
	var state = {
		targetLanguage: '',
		modelSlug: readStoredModelSlug() || cfg.defaultModelSlug || '',
		additionalPrompt: typeof cfg.lastAdditionalPrompt === 'string' ? cfg.lastAdditionalPrompt : '',
		overwrite: false,
	};

	function text(key, fallback) {
		return strings[key] || fallback;
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
			return response.json();
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

	function refreshPreview() {
		var iframe = document.querySelector('#trp-preview iframe, iframe[src*="trp-edit-translation=preview"]');
		if (iframe && iframe.contentWindow) {
			iframe.contentWindow.location.reload();
			return;
		}

		window.location.reload();
	}

	function stopPolling() {
		if (pollTimer) {
			window.clearTimeout(pollTimer);
			pollTimer = null;
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

	function setStatus(panel, message, tone, withReload) {
		panel.status.className = 'slytranslate-trp-status slytranslate-trp-status-' + tone;
		panel.status.innerHTML = '';
		panel.status.appendChild(document.createTextNode(message));
		if (withReload) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button button-secondary slytranslate-trp-reload';
			button.textContent = text('reloadPreviewButton', 'Reload preview');
			button.addEventListener('click', refreshPreview);
			panel.status.appendChild(document.createTextNode(' '));
			panel.status.appendChild(button);
		}
		panel.status.hidden = false;
	}

	function updateFormState(panel) {
		var disabled = !cfg.enabled || isRunning;
		panel.target.disabled = disabled || getTargetLanguages().length < 1;
		panel.model.disabled = disabled;
		panel.refreshModels.disabled = disabled;
		panel.prompt.disabled = disabled;
		panel.overwrite.disabled = disabled;
		panel.start.disabled = disabled || !state.targetLanguage;
		panel.cancel.disabled = !isRunning;
		panel.cancel.hidden = !isRunning;
		panel.progress.hidden = !isRunning;
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

	function mountPanel(anchor) {
		if (mounted || !anchor) {
			return;
		}

		var panel = document.createElement('section');
		panel.className = 'slytranslate-trp-panel';
		panel.innerHTML = '<div class="slytranslate-trp-header"></div><div class="slytranslate-trp-status" hidden></div><div class="slytranslate-trp-progress" hidden><div class="slytranslate-trp-progress-bar"></div></div><p class="slytranslate-trp-progress-label"></p><div class="slytranslate-trp-fields"></div><div class="slytranslate-trp-actions"></div>';

		var header = panel.querySelector('.slytranslate-trp-header');
		header.textContent = text('panelTitle', 'Translate with SlyTranslate');

		var fields = panel.querySelector('.slytranslate-trp-fields');
		var actions = panel.querySelector('.slytranslate-trp-actions');

		var sourceValue = document.createElement('div');
		sourceValue.className = 'slytranslate-trp-source';
		sourceValue.textContent = cfg.sourceLanguage || '—';
		fields.appendChild(createField(text('sourceLanguageLabel', 'Source language'), sourceValue));

		var target = document.createElement('select');
		target.className = 'slytranslate-trp-select';
		fields.appendChild(createField(text('targetLanguageLabel', 'Target language'), target));

		var modelRow = document.createElement('div');
		modelRow.className = 'slytranslate-trp-model-row';
		var model = document.createElement('select');
		model.className = 'slytranslate-trp-select';
		var refreshModels = document.createElement('button');
		refreshModels.type = 'button';
		refreshModels.className = 'button button-secondary';
		refreshModels.textContent = text('refreshModelsButton', 'Refresh model list');
		modelRow.appendChild(model);
		modelRow.appendChild(refreshModels);
		fields.appendChild(createField(text('modelLabel', 'AI model'), modelRow));

		var prompt = document.createElement('textarea');
		prompt.className = 'slytranslate-trp-textarea';
		prompt.rows = 4;
		prompt.value = state.additionalPrompt;
		var promptField = createField(text('additionalPromptLabel', 'Additional instructions (optional)'), prompt);
		var promptHelp = document.createElement('p');
		promptHelp.className = 'slytranslate-trp-help';
		promptHelp.textContent = text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.');
		promptField.appendChild(promptHelp);
		fields.appendChild(promptField);

		var overwriteWrap = document.createElement('label');
		overwriteWrap.className = 'slytranslate-trp-checkbox';
		var overwrite = document.createElement('input');
		overwrite.type = 'checkbox';
		overwriteWrap.appendChild(overwrite);
		overwriteWrap.appendChild(document.createTextNode(' ' + text('overwriteLabel', 'Overwrite existing translation')));
		fields.appendChild(overwriteWrap);

		var start = document.createElement('button');
		start.type = 'button';
		start.className = 'button button-primary';
		start.textContent = text('startButton', 'Start translation');
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button button-secondary';
		cancel.textContent = text('cancelButton', 'Cancel translation');
		actions.appendChild(start);
		actions.appendChild(cancel);

		anchor.insertAdjacentElement('afterend', panel);
		mounted = true;

		var panelApi = {
			status: panel.querySelector('.slytranslate-trp-status'),
			progress: panel.querySelector('.slytranslate-trp-progress'),
			progressBar: panel.querySelector('.slytranslate-trp-progress-bar'),
			progressLabel: panel.querySelector('.slytranslate-trp-progress-label'),
			target: target,
			model: model,
			refreshModels: refreshModels,
			prompt: prompt,
			overwrite: overwrite,
			start: start,
			cancel: cancel,
		};

		var targetLanguages = getTargetLanguages().map(function (language) {
			return { value: language.code, label: language.name + ' (' + language.code + ')' };
		});
		state.targetLanguage = targetLanguages[0] ? targetLanguages[0].value : '';
		populateSelect(target, targetLanguages, state.targetLanguage, targetLanguages.length ? '' : text('noTargetLanguages', 'No target languages are available for this content item.'));
		populateSelect(model, [{ value: '', label: '— Auto —' }].concat((cfg.models || []).map(function (entry) {
			return { value: entry.value, label: entry.label };
		})), state.modelSlug, '');

		if (!cfg.enabled) {
			setStatus(panelApi, text('unsupportedNotice', 'SlyTranslate is only available here for singular posts and pages you can edit.'), 'warning', false);
		}

		target.addEventListener('change', function () {
			state.targetLanguage = target.value;
			updateFormState(panelApi);
		});

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

		overwrite.addEventListener('change', function () {
			state.overwrite = overwrite.checked;
		});

		refreshModels.addEventListener('click', function () {
			refreshModels.disabled = true;
			apiPost('ai-translate/get-available-models/run', { input: { refresh: true } }).then(function (response) {
				cfg.models = response && Array.isArray(response.models) ? response.models : [];
				populateSelect(model, [{ value: '', label: '— Auto —' }].concat((cfg.models || []).map(function (entry) {
					return { value: entry.value, label: entry.label };
				})), state.modelSlug, '');
			}).catch(function (error) {
				setStatus(panelApi, text('errorPrefix', 'Translation failed:') + ' ' + getErrorMessage(error), 'error', false);
			}).finally(function () {
				refreshModels.disabled = false;
			});
		});

		start.addEventListener('click', function () {
			if (!cfg.enabled || !state.targetLanguage || isRunning) {
				return;
			}

			isRunning = true;
			panelApi.status.hidden = true;
			panelApi.progressBar.style.width = '0%';
			panelApi.progressLabel.textContent = text('progressTitle', 'Translating title...');
			abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
			updateFormState(panelApi);
			schedulePolling(panelApi);

			apiPost('ai-translate/translate-content/run', {
				input: {
					post_id: cfg.postId || 0,
					source_language: cfg.sourceLanguage || undefined,
					target_language: state.targetLanguage,
					post_status: 'draft',
					overwrite: !!state.overwrite,
					translate_title: true,
					model_slug: state.modelSlug || undefined,
					additional_prompt: state.additionalPrompt || undefined,
				},
			}, abortCtrl ? abortCtrl.signal : undefined).then(function () {
				saveAdditionalPromptPreference(state.additionalPrompt);
				setStatus(panelApi, text('successNotice', 'Translation completed successfully.'), 'success', true);
			}).catch(function (error) {
				if (error && error.name === 'AbortError') {
					setStatus(panelApi, text('cancelButton', 'Cancel translation'), 'warning', false);
					return;
				}
				setStatus(panelApi, text('errorPrefix', 'Translation failed:') + ' ' + getErrorMessage(error), 'error', false);
			}).finally(function () {
				isRunning = false;
				abortCtrl = null;
				stopPolling();
				panelApi.progress.hidden = true;
				updateFormState(panelApi);
			});
		});

		cancel.addEventListener('click', function () {
			if (abortCtrl) {
				abortCtrl.abort();
			}
			apiPost('ai-translate/cancel-translation/run', {
				input: {
					post_id: cfg.postId || 0,
				},
			}).catch(function () {
			});
		});

		updateFormState(panelApi);
	}

	function tryMount() {
		if (mounted) {
			return;
		}

		var anchor = document.querySelector('#trp-controls-section-first') || document.querySelector('#trp-controls');
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