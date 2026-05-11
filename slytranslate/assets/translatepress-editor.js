(function () {
    'use strict';

    var cfg = window.SlyTranslateTranslatePressEditor || {};
    var strings = cfg.i18n || {};
    var mounted = false;
    var currentPanelApi = null;
    var pollTimer = null;
    var abortCtrl = null;
    var cancelRevealTimer = null;
    var contextRefreshPromise = null;
    var isRunning = false;
    var cancelVisible = false;
    var cancelRevealDelayMs = 1200;
    var lastObservedHref = window.location.href || '';
    var state = {
        modelSlug: getInitialModelSlug(),
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

    function hasAvailableModelSlug(slug, models) {
        return !!slug && Array.isArray(models) && models.some(function (entry) {
            return entry && entry.value === slug;
        });
    }

    function resolveModelSlug(preferredSlug, models, defaultSlug) {
        if (hasAvailableModelSlug(preferredSlug, models)) {
            return preferredSlug;
        }

        if (hasAvailableModelSlug(defaultSlug, models)) {
            return defaultSlug;
        }

        return '';
    }

    function getInitialModelSlug() {
        var storedSlug = readStoredModelSlug();
        var resolvedSlug = resolveModelSlug(storedSlug, cfg.models || [], cfg.defaultModelSlug || '');

        if (resolvedSlug !== storedSlug) {
            storeModelSlug(resolvedSlug);
        }

        return resolvedSlug;
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
        if (error && error.code === 'invalid_translation_language_passthrough') {
            return text('languagePassthroughError', 'Ein übersetzter Abschnitt scheint noch in der Ausgangssprache statt auf Englisch vorzuliegen.');
        }

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

    function getAutoModelLabel() {
        return '\u2014 Auto \u2014';
    }

    function applyBootstrapData(nextCfg) {
        if (!nextCfg || typeof nextCfg !== 'object') {
            return;
        }

        cfg = Object.assign({}, cfg, nextCfg);
        strings = cfg.i18n || strings;
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

    function clearStatus(panel) {
        panel.status.hidden = true;
        panel.status.innerHTML = '';
    }

    function updateFormState(panel) {
        var disabled = isRunning;
        var canCancel = isRunning && cancelVisible;
        panel.model.disabled = disabled;
        panel.refreshModels.disabled = disabled;
        panel.prompt.disabled = disabled;
        panel.start.disabled = getTargetLanguages().length < 1 || (isRunning && !canCancel);
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

    function getFieldLanguageHaystack(field) {
        if (!field) {
            return '';
        }

        var haystacks = [];
        if (field.labels && field.labels.length) {
            haystacks = haystacks.concat(Array.prototype.map.call(field.labels, function (label) {
                return label.textContent || '';
            }));
        }

        haystacks.push(field.getAttribute('aria-label') || '');
        haystacks.push(field.getAttribute('placeholder') || '');
        haystacks.push(field.name || '');
        haystacks.push(field.id || '');

        if (field.parentElement) {
            haystacks.push(field.parentElement.textContent || '');
        }

        return haystacks.join(' ').toLowerCase();
    }

    function fieldMatchesLanguage(field, languageCode, languageName) {
        if (!field || !languageCode) {
            return false;
        }

        var haystack = getFieldLanguageHaystack(field);
        var code = String(languageCode || '').toLowerCase();
        var name = String(languageName || '').toLowerCase();

        if (!haystack) {
            return false;
        }

        return (name && haystack.indexOf(name) !== -1)
            || haystack.indexOf('(' + code + ')') !== -1
            || haystack.indexOf(' ' + code + ' ') !== -1
            || haystack.indexOf('-' + code) !== -1
            || haystack.indexOf(code + '-') !== -1
            || (name && haystack.indexOf('in ' + name) !== -1);
    }

    function findLanguageConfig(languageCode) {
        var languages = Array.isArray(cfg.languages) ? cfg.languages : [];
        for (var index = 0; index < languages.length; index += 1) {
            if (languages[index] && languages[index].code === languageCode) {
                return languages[index];
            }
        }

        return null;
    }

    function resolveFieldByLanguage(candidates, languageCode, preferredIndex) {
        var language = findLanguageConfig(languageCode);
        var languageName = language && language.name ? language.name : '';
        var match = null;

        candidates.some(function (field) {
            if (fieldMatchesLanguage(field, languageCode, languageName)) {
                match = field;
                return true;
            }

            return false;
        });

        if (match) {
            return match;
        }

        if (typeof preferredIndex === 'number' && preferredIndex >= 0 && preferredIndex < candidates.length) {
            return candidates[preferredIndex];
        }

        return candidates.length ? candidates[0] : null;
    }

    function countMarkers(text, languageCode) {
        var normalized = String(text || '').toLowerCase();
        if (!normalized) {
            return 0;
        }

        var patterns = {
            de: /\b(?:der|die|das|und|mit|nicht|ist|sind|ein|eine|fuer|für|oder|auf|von|im|den|dem|zu)\b/gu,
            en: /\b(?:the|and|with|for|from|this|that|are|you|your|into|please|translate|content|following|by)\b/gu,
        };

        var shortCode = String(languageCode || '').slice(0, 2).toLowerCase();
        var pattern = patterns[shortCode];
        if (!pattern) {
            return 0;
        }

        var matches = normalized.match(pattern);
        return matches ? matches.length : 0;
    }

    function getInferredSourceLanguage(targetLanguage) {
        var sourceLanguage = String(cfg.sourceLanguage || '').slice(0, 2).toLowerCase();
        if (sourceLanguage) {
            return sourceLanguage;
        }

        var targetShortCode = String(targetLanguage || '').slice(0, 2).toLowerCase();
        if (targetShortCode === 'en') {
            return 'de';
        }

        if (targetShortCode === 'de') {
            return 'en';
        }

        return '';
    }

    function getSourceCandidateScore(field, targetLanguage) {
        if (!field) {
            return Number.NEGATIVE_INFINITY;
        }

        var value = String(field.value || '').trim();
        if (!value) {
            return Number.NEGATIVE_INFINITY;
        }

        var inferredSourceLanguage = getInferredSourceLanguage(targetLanguage);
        var sourceMarkers = countMarkers(value, inferredSourceLanguage);
        var targetMarkers = countMarkers(value, targetLanguage);
        var score = (sourceMarkers * 10) - (targetMarkers * 8);

        if (field.hasAttribute('readonly')) {
            score += 2;
        }

        return score;
    }

    function resolveSourceField(candidates, targetLanguage) {
        var bestField = null;
        var bestScore = Number.NEGATIVE_INFINITY;

        candidates.forEach(function (field) {
            var score = getSourceCandidateScore(field, targetLanguage);
            if (score > bestScore) {
                bestField = field;
                bestScore = score;
            }
        });

        if (bestField) {
            return bestField;
        }

        return resolveFieldByLanguage(candidates, cfg.sourceLanguage || '', 0);
    }

    function resolveTargetField(candidates, targetLanguage) {
        var editableCandidates = candidates.filter(function (field) {
            return !!field && !field.hasAttribute('readonly');
        });

        if (editableCandidates.length === 1) {
            return editableCandidates[0];
        }

        if (editableCandidates.length > 1) {
            return resolveFieldByLanguage(editableCandidates, targetLanguage, editableCandidates.length - 1);
        }

        return targetLanguage ? resolveFieldByLanguage(candidates, targetLanguage, candidates.length - 1) : candidates[candidates.length - 1];
    }

    function getTranslatePressAppStateCandidate(app) {
        if (!app || typeof app !== 'object') {
            return null;
        }

        var candidates = [
            app,
            app.$data,
            app._instance && app._instance.proxy,
            app._instance && app._instance.ctx,
            app.__vueParentComponent && app.__vueParentComponent.ctx,
            app.__vueParentComponent && app.__vueParentComponent.proxy,
        ];

        for (var index = 0; index < candidates.length; index += 1) {
            var candidate = candidates[index];
            if (candidate && typeof candidate === 'object' && candidate.dictionary && candidate.selectedIndexesArray) {
                return candidate;
            }
        }

        return null;
    }

    function getActiveTranslatePressRuntimeString() {
        var appState = getTranslatePressAppStateCandidate(window.tpStringTranslationApp);
        if (!appState) {
            return null;
        }

        var selectedIndexes = Array.isArray(appState.selectedIndexesArray) ? appState.selectedIndexesArray : [];
        if (!selectedIndexes.length) {
            return null;
        }

        var dictionary = appState.dictionary;
        if (!dictionary || typeof dictionary !== 'object') {
            return null;
        }

        for (var index = 0; index < selectedIndexes.length; index += 1) {
            var selectedIndex = selectedIndexes[index];
            var entry = dictionary[selectedIndex];
            if (!entry || typeof entry !== 'object') {
                continue;
            }

            if (typeof entry.original === 'string' && entry.original.trim()) {
                return {
                    original: entry.original,
                    selectedIndex: String(selectedIndex),
                };
            }
        }

        return null;
    }

    function getActiveTranslatePressFieldScope(controls) {
        var stringContainers = Array.prototype.filter.call(
            controls.querySelectorAll('.trp-string-container'),
            function (container) {
                return isVisibleElement(container);
            }
        );

        for (var index = 0; index < stringContainers.length; index += 1) {
            var container = stringContainers[index];
            var containerFields = Array.prototype.filter.call(
                container.querySelectorAll('textarea, input[type="text"]'),
                function (field) {
                    return isVisibleElement(field) && !field.classList.contains('slytranslate-trp-textarea');
                }
            );

            if (containerFields.length >= 2) {
                return {
                    element: container,
                    scope: 'trp-string-container',
                    candidates: containerFields,
                };
            }
        }

        var translationSection = controls.querySelector('#trp-translation-section');
        if (translationSection && isVisibleElement(translationSection)) {
            var sectionFields = Array.prototype.filter.call(
                translationSection.querySelectorAll('textarea, input[type="text"]'),
                function (field) {
                    return isVisibleElement(field) && !field.classList.contains('slytranslate-trp-textarea');
                }
            );

            if (sectionFields.length) {
                return {
                    element: translationSection,
                    scope: 'trp-translation-section',
                    candidates: sectionFields,
                };
            }
        }

        return {
            element: controls,
            scope: 'trp-controls',
            candidates: Array.prototype.filter.call(
                controls.querySelectorAll('textarea, input[type="text"]'),
                function (field) {
                    return isVisibleElement(field) && !field.classList.contains('slytranslate-trp-textarea');
                }
            ),
        };
    }

    function findVisibleTranslatePressFields() {
        var controls = document.querySelector('#trp-controls');
        if (!controls) {
            return null;
        }

        var fieldScope = getActiveTranslatePressFieldScope(controls);
        var candidates = fieldScope.candidates;

        if (!candidates.length) {
            return null;
        }

        var targetLanguage = inferVisibleTargetLanguage({
            targetField: candidates[candidates.length - 1],
            controls: controls,
        });
        var sourceField = resolveSourceField(candidates, targetLanguage);
        var targetField = resolveTargetField(candidates, targetLanguage);

        if (sourceField === targetField && candidates.length > 1) {
            sourceField = resolveFieldByLanguage(candidates, cfg.sourceLanguage || '', 0);
            targetField = targetLanguage ? resolveFieldByLanguage(candidates, targetLanguage, candidates.length - 1) : candidates[candidates.length - 1];
            if (sourceField === targetField) {
                sourceField = candidates[0];
                targetField = candidates[candidates.length - 1];
            }
        }

        return {
            sourceField: candidates.length > 1 ? sourceField : null,
            targetField: targetField,
            controls: controls,
            candidateCount: candidates.length,
            activeScope: fieldScope.scope,
        };
    }

    function inferVisibleTargetLanguage(fields) {
        var haystacks = [];
        var targetField = fields && fields.targetField ? fields.targetField : null;
        var controls = fields && fields.controls ? fields.controls : null;

        if (targetField) {
            haystacks.push(getFieldLanguageHaystack(targetField));
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

    function describeField(field) {
        if (!field) {
            return {
                readonly: false,
                name: '',
                id: '',
                preview: '',
            };
        }

        return {
            readonly: field.hasAttribute('readonly'),
            name: field.name || '',
            id: field.id || '',
            preview: String(field.value || '').trim().slice(0, 120),
        };
    }

    function getVisibleSourceText(fields) {
        var runtimeString = getActiveTranslatePressRuntimeString();
        if (runtimeString && typeof runtimeString.original === 'string' && runtimeString.original.trim()) {
            return {
                text: String(runtimeString.original).trim(),
                origin: 'runtime_string_original',
                selectedIndex: runtimeString.selectedIndex,
            };
        }

        var sourceField = fields && fields.sourceField ? fields.sourceField : null;
        return {
            text: sourceField ? String(sourceField.value || '').trim() : '',
            origin: 'dom_source_field',
            selectedIndex: '',
        };
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

        var sourceResolution = getVisibleSourceText(fields);
        var sourceText = sourceResolution.text;
        var targetLanguage = inferVisibleTargetLanguage(fields);
        if (!sourceText || !targetLanguage) {
            debugLog('sync_skipped_missing_values', {
                source_length: sourceText.length,
                target_language: targetLanguage || '',
                source_origin: sourceResolution.origin,
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

    function translateVisibleTranslatePressField(panelApi) {
        var fields = findVisibleTranslatePressFields();
        var sourceResolution = getVisibleSourceText(fields);
        var sourceText = sourceResolution.text;
        var targetLanguage = inferVisibleTargetLanguage(fields);

        if (!fields || !fields.sourceField || !fields.targetField) {
            debugLog('field_translation_blocked', {
                reason: 'missing_fields',
                has_source_field: !!(fields && fields.sourceField),
                has_target_field: !!(fields && fields.targetField),
            });
            return Promise.reject(new Error(text('fieldMissingError', 'Das sichtbare TranslatePress-Feld konnte nicht erkannt werden.')));
        }

        if (!sourceText) {
            debugLog('field_translation_blocked', {
                reason: 'missing_source_text',
                source_length: 0,
                source_origin: sourceResolution.origin,
            });
            return Promise.reject(new Error(text('fieldMissingError', 'Das sichtbare TranslatePress-Feld konnte nicht erkannt werden.')));
        }

        if (!targetLanguage) {
            debugLog('field_translation_blocked', {
                reason: 'missing_target_language',
                source_length: sourceText.length,
            });
            return Promise.reject(new Error(text('noTargetLanguages', 'No target languages are available for this content item.')));
        }

        panelApi.progressLabel.textContent = text('translatingLanguage', 'Translating {language}...').replace('{language}', targetLanguage.toUpperCase());

        abortCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null;

        return apiPost('ai-translate/translate-text/run', {
            input: {
                text: sourceText,
                source_language: cfg.sourceLanguage || undefined,
                target_language: targetLanguage,
                model_slug: state.modelSlug || undefined,
                additional_prompt: state.additionalPrompt || undefined,
            },
        }, abortCtrl ? abortCtrl.signal : undefined).then(function (response) {
            var translatedText = response && typeof response.translated_text === 'string' ? response.translated_text : '';
            if (!translatedText) {
                throw new Error(text('unknownError', 'An unexpected error occurred.'));
            }

            updateVisibleTranslatePressField(translatedText);
            debugLog('field_translation_applied', {
                target_language: targetLanguage,
                source_length: sourceText.length,
            });
            return true;
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

    function applyContextToPanel(panelApi) {
        var modelOptions = [{ value: '', label: getAutoModelLabel() }].concat((cfg.models || []).map(function (entry) {
            return { value: entry.value, label: entry.label };
        }));

        populateSelect(panelApi.model, modelOptions, state.modelSlug, '');

        if (getTargetLanguages().length < 1) {
            setStatus(panelApi, text('noTargetLanguages', 'No target languages are available for this content item.'), 'warning');
        } else {
            clearStatus(panelApi);
        }

        updateFormState(panelApi);
    }

    function refreshEditorContext(panelApi, force) {
        var href = window.location.href || '';

        if (!panelApi) {
            return Promise.resolve(false);
        }

        if (!force && href === lastObservedHref) {
            return contextRefreshPromise || Promise.resolve(false);
        }

        if (contextRefreshPromise) {
            return contextRefreshPromise;
        }

        lastObservedHref = href;
        contextRefreshPromise = apiPost('ai-translate/get-editor-context/run', {
            input: {
                current_url: href,
            },
        }).then(function (response) {
            applyBootstrapData(response);
            applyContextToPanel(panelApi);
            debugLog('context_refreshed', {
                post_id: cfg.postId || 0,
                target_count: getTargetLanguages().length,
                reason: cfg.postId ? 'post_context' : 'string_context',
            });
            return true;
        }).catch(function (error) {
            debugLog('context_refresh_failed', {
                reason: getErrorMessage(error),
            });
            return false;
        }).finally(function () {
            contextRefreshPromise = null;
        });

        return contextRefreshPromise;
    }

    function handleLocationChange() {
        if (!currentPanelApi || isRunning) {
            return;
        }

        if ((window.location.href || '') === lastObservedHref) {
            return;
        }

        refreshEditorContext(currentPanelApi, true);
    }

    function installNavigationHooks() {
        if (window.history && !window.history.__slytranslateTranslatePressPatched) {
            var wrapHistoryMethod = function (methodName) {
                if (typeof window.history[methodName] !== 'function') {
                    return;
                }

                var original = window.history[methodName];
                window.history[methodName] = function () {
                    var result = original.apply(this, arguments);
                    window.dispatchEvent(new Event('slytranslate:locationchange'));
                    return result;
                };
            };

            wrapHistoryMethod('pushState');
            wrapHistoryMethod('replaceState');
            window.history.__slytranslateTranslatePressPatched = true;
        }

        window.addEventListener('popstate', handleLocationChange);
        window.addEventListener('hashchange', handleLocationChange);
        window.addEventListener('slytranslate:locationchange', handleLocationChange);
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
        currentPanelApi = panelApi;

        populateSelect(model, [{ value: '', label: getAutoModelLabel() }].concat((cfg.models || []).map(function (entry) {
            return { value: entry.value, label: entry.label };
        })), state.modelSlug, '');
        applyContextToPanel(panelApi);

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
                cfg.defaultModelSlug = response && response.defaultModelSlug ? response.defaultModelSlug : '';
                state.modelSlug = resolveModelSlug(state.modelSlug, cfg.models, cfg.defaultModelSlug);
                storeModelSlug(state.modelSlug);
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

            var visibleFields = findVisibleTranslatePressFields();
            var sourceResolution = getVisibleSourceText(visibleFields);
            var sourceText = sourceResolution.text;
            var targetLanguage = inferVisibleTargetLanguage(visibleFields);
            if (!targetLanguage || isRunning) {
                debugLog('start_blocked', {
                    reason: !targetLanguage ? 'no_visible_target_language' : 'already_running',
                    target_count: getTargetLanguages().length,
                    is_running: isRunning,
                });
                return;
            }

            debugLog('translation_run_started', {
                target_count: 1,
                target_language: targetLanguage,
                model_slug: state.modelSlug || '',
                has_source_field: !!(visibleFields && visibleFields.sourceField),
                has_target_field: !!(visibleFields && visibleFields.targetField),
                source_length: sourceText.length,
                source_readonly: visibleFields && visibleFields.sourceField ? visibleFields.sourceField.hasAttribute('readonly') : false,
                target_readonly: visibleFields && visibleFields.targetField ? visibleFields.targetField.hasAttribute('readonly') : false,
                source_field_name: describeField(visibleFields && visibleFields.sourceField).name,
                target_field_name: describeField(visibleFields && visibleFields.targetField).name,
                source_field_id: describeField(visibleFields && visibleFields.sourceField).id,
                target_field_id: describeField(visibleFields && visibleFields.targetField).id,
                source_preview: describeField(visibleFields && visibleFields.sourceField).preview,
                target_preview: describeField(visibleFields && visibleFields.targetField).preview,
                candidate_count: visibleFields && visibleFields.candidateCount ? visibleFields.candidateCount : 0,
                active_scope: visibleFields && visibleFields.activeScope ? visibleFields.activeScope : '',
                source_origin: sourceResolution.origin,
                source_selected_index: sourceResolution.selectedIndex,
                source_runtime_preview: sourceResolution.origin === 'runtime_string_original' ? sourceText.slice(0, 120) : '',
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

            translateVisibleTranslatePressField(panelApi).then(function () {
                saveAdditionalPromptPreference(state.additionalPrompt);
                debugLog('translation_run_completed', {
                    target_count: 1,
                    target_language: targetLanguage,
                });
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
                    target_count: 1,
                    target_language: targetLanguage,
                });
                isRunning = false;
                cancelVisible = false;
                abortCtrl = null;
                resetProgress(panelApi);
                updateFormState(panelApi);
            });
        });

        refreshEditorContext(panelApi, true);
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
        installNavigationHooks();
        tryMount();

        var observer = new MutationObserver(function () {
            tryMount();
            handleLocationChange();
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();