(function (wp, settings) {
    if (!wp || !wp.plugins || !wp.components || !wp.data || !wp.element || !wp.apiFetch) {
        return;
    }

    const registerPlugin = wp.plugins.registerPlugin;
    const PluginDocumentSettingPanel = wp.editPost && wp.editPost.PluginDocumentSettingPanel;
    const TextareaControl = wp.components.TextareaControl;
    const Button = wp.components.Button;
    const Modal = wp.components.Modal;
    const Notice = wp.components.Notice;
    const SelectControl = wp.components.SelectControl;
    const Spinner = wp.components.Spinner;
    const ToggleControl = wp.components.ToggleControl;
    const createElement = wp.element.createElement;
    const Fragment = wp.element.Fragment;
    const useEffect = wp.element.useEffect;
    const useState = wp.element.useState;
    const useRef = wp.element.useRef;
    const useSelect = wp.data.useSelect;
    const apiFetch = wp.apiFetch;
    const blockEditor = wp.blockEditor || wp.editor || {};
    const RichTextToolbarButton = blockEditor.RichTextToolbarButton;
    const wpBlocks = wp.blocks || {};
    const serializeBlocks = wpBlocks.serialize;
    const parseBlocks = wpBlocks.parse;
    const useDispatch = wp.data.useDispatch;
    const PluginBlockSettingsMenuItem = (wp.editor && wp.editor.PluginBlockSettingsMenuItem) || (wp.editPost && wp.editPost.PluginBlockSettingsMenuItem);
    const richText = wp.richText || {};
    const registerFormatType = richText.registerFormatType;
    const slice = richText.slice;
    const insert = richText.insert;
    const selectionFeatureAvailable = !!(registerFormatType && RichTextToolbarButton && slice && insert);
    const blockTranslationAvailable = !!(serializeBlocks && parseBlocks && PluginBlockSettingsMenuItem && useDispatch);
    const hasTranslationPlugin = !!(settings && settings.translationPluginAvailable);
    const selectionLanguageCodes = [
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'he', 'hi', 'hr', 'hu', 'id',
        'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'nl', 'no', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sl', 'sv',
        'th', 'tr', 'uk', 'vi', 'zh-CN', 'zh-TW'
    ];

    if (!PluginDocumentSettingPanel && !selectionFeatureAvailable) {
        return;
    }

    function normalizeLanguageCode(languageCode) {
        return typeof languageCode === 'string' ? languageCode.trim().replace(/_/g, '-').toLowerCase() : '';
    }

    function getLanguageBaseCode(languageCode) {
        const normalizedLanguageCode = normalizeLanguageCode(languageCode);
        return normalizedLanguageCode ? normalizedLanguageCode.split('-')[0] : '';
    }

    function getLanguageDisplayName(languageCode) {
        const baseLanguageCode = getLanguageBaseCode(languageCode);
        const userLanguage = typeof window !== 'undefined' && window.navigator && window.navigator.language ? window.navigator.language : 'en';

        if (typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function' && baseLanguageCode) {
            try {
                const languageNames = new Intl.DisplayNames([userLanguage], { type: 'language' });
                const displayName = languageNames.of(baseLanguageCode);

                if (displayName) {
                    return displayName;
                }
            } catch (error) {
            }
        }

        return languageCode.toUpperCase();
    }

    function getSelectionLanguages() {
        // Prepend languages configured in the active translation plugin
        // (Polylang) so the user sees them at the top of the dropdown,
        // followed by the generic fallback list. Names from the plugin take
        // precedence over Intl.DisplayNames-derived names because the plugin
        // values are typically already localised by the site administrator.
        var pluginLanguages = (settings && Array.isArray(settings.translationPluginLanguages))
            ? settings.translationPluginLanguages
            : [];

        var seenBaseCodes = {};
        var result = [];

        pluginLanguages.forEach(function (entry) {
            if (!entry || !entry.code) { return; }
            var baseCode = getLanguageBaseCode(entry.code) || normalizeLanguageCode(entry.code);
            if (!baseCode || seenBaseCodes[baseCode]) { return; }
            seenBaseCodes[baseCode] = true;
            result.push({
                code: entry.code,
                name: entry.name || getLanguageDisplayName(entry.code),
            });
        });

        selectionLanguageCodes.forEach(function (languageCode) {
            var baseCode = getLanguageBaseCode(languageCode) || normalizeLanguageCode(languageCode);
            if (!baseCode || seenBaseCodes[baseCode]) { return; }
            seenBaseCodes[baseCode] = true;
            result.push({
                code: languageCode,
                name: getLanguageDisplayName(languageCode),
            });
        });

        return result;
    }

    function text(key, fallback) {
        if (settings && settings.strings && settings.strings[key]) {
            return settings.strings[key];
        }

        return fallback;
    }

    function getEditorRestPath(route) {
        const basePath = settings && settings.abilitiesRunBasePath ? settings.abilitiesRunBasePath : '/ai-translate/v1/';
        return basePath + route;
    }

    function getRunAbilityPath(abilityName) {
        return getEditorRestPath(abilityName) + '/run';
    }

    function buildLanguageOptions(languages) {
        return languages.map(function (language) {
            return {
                label: language.name + ' (' + language.code + ')',
                value: language.code,
            };
        });
    }

    function renderLanguageSelectField(config) {
        var options = buildLanguageOptions(config.options || []);

        return createElement(
            Fragment,
            null,
            createElement(
                'label',
                {
                    htmlFor: config.id,
                    style: {
                        gridArea: config.labelArea,
                        display: 'block',
                        fontSize: '12px',
                        lineHeight: '1.4',
                        color: '#50575e',
                    },
                },
                config.label
            ),
            createElement(
                'select',
                {
                    id: config.id,
                    value: config.value,
                    disabled: options.length < 1,
                    onChange: function (event) {
                        config.onChange(event.target.value);
                    },
                    className: 'components-select-control__input',
                    style: {
                        gridArea: config.controlArea,
                        width: '100%',
                        minWidth: 0,
                        height: '40px',
                        minHeight: '40px',
                    },
                },
                options.length ? options.map(function (option) {
                    return createElement('option', { key: option.value, value: option.value }, option.label);
                }) : createElement('option', { value: '' }, '')
            )
        );
    }

    function renderLanguagePairControls(config) {
        var languages = Array.isArray(config.languages) ? config.languages : [];
        var targetLanguages = Array.isArray(config.targetLanguages) ? config.targetLanguages : [];

        if (!languages.length) {
            return null;
        }

        return createElement(
            'div',
            {
                style: {
                    display: 'grid',
                    gridTemplateColumns: 'minmax(0, 1fr) 40px minmax(0, 1fr)',
                    gridTemplateAreas: "'source-label switch-label target-label' 'source-control switch-control target-control'",
                    columnGap: '14px',
                    rowGap: '6px',
                    alignItems: 'start',
                    marginBottom: '12px',
                },
            },
            renderLanguageSelectField({
                id: config.sourceId || 'slytranslate-source-language',
                label: text('sourceLanguageLabel', 'Source language'),
                labelArea: 'source-label',
                controlArea: 'source-control',
                value: config.sourceLanguage,
                onChange: config.onSourceChange,
                options: languages,
            }),
            createElement('div', {
                'aria-hidden': true,
                style: {
                    gridArea: 'switch-label',
                    display: 'block',
                    fontSize: '12px',
                    lineHeight: '1.4',
                    visibility: 'hidden',
                    overflow: 'hidden',
                    whiteSpace: 'nowrap',
                },
            }, '\u00a0'),
            createElement(
                'div',
                {
                    style: {
                        gridArea: 'switch-control',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        alignSelf: 'center',
                    },
                },
                createElement(Button, {
                    icon: 'controls-repeat',
                    variant: 'tertiary',
                    label: text('swapLanguagesButton', 'Swap source and target language'),
                    showTooltip: true,
                    disabled: !config.sourceLanguage || !config.targetLanguage,
                    onClick: config.onSwap,
                    style: {
                        width: '40px',
                        minWidth: '40px',
                        height: '40px',
                        minHeight: '40px',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: '0',
                    },
                })
            ),
            renderLanguageSelectField({
                id: config.targetId || 'slytranslate-target-language',
                label: text('targetLanguageLabel', 'Target language'),
                labelArea: 'target-label',
                controlArea: 'target-control',
                value: config.targetLanguage,
                onChange: config.onTargetChange,
                options: targetLanguages,
            })
        );
    }

    function slyDebugEnabled() {
        return !!(typeof window !== 'undefined' && window.SLY_TRANSLATE_DEBUG);
    }

    function slyDebug() {
        if (!slyDebugEnabled() || typeof console === 'undefined' || !console.log) {
            return;
        }
        var args = ['[SlyTranslate]'];
        for (var i = 0; i < arguments.length; i++) { args.push(arguments[i]); }
        try { console.log.apply(console, args); } catch (e) { }
    }

    function getCurrentWpglobusLanguageHint() {
        var candidates = [];

        try {
            if (typeof window !== 'undefined' && window.location && window.location.search) {
                var params = new window.URLSearchParams(window.location.search);
                ['wpglobus_language', 'wpglobus-language', 'language', 'lang'].forEach(function (key) {
                    var value = params.get(key);
                    if (value) {
                        candidates.push(value);
                    }
                });
            }
        } catch (e) { }

        try {
            if (typeof document !== 'undefined' && document.querySelector) {
                [
                    'input[name="wpglobus_language"]',
                    'input[name="wpglobus-language"]',
                    'input[name="language"]',
                    'select[name="wpglobus_language"]',
                    'select[name="wpglobus-language"]',
                    'select[name="language"]'
                ].forEach(function (selector) {
                    var field = document.querySelector(selector);
                    if (field && field.value) {
                        candidates.push(field.value);
                    }
                });
            }
        } catch (e) { }

        try {
            if (typeof document !== 'undefined' && typeof document.cookie === 'string') {
                var match = document.cookie.match(/(?:^|; )wpglobus-builder-language=([^;]+)/);
                if (match && match[1]) {
                    candidates.push(decodeURIComponent(match[1]).split('+', 1)[0]);
                }
            }
        } catch (e) { }

        for (var index = 0; index < candidates.length; index++) {
            var normalized = String(candidates[index] || '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
            if (normalized) {
                return normalized;
            }
        }

        return '';
    }

    function buildTranslationStatusInput(postId) {
        var input = { post_id: postId };
        var wpglobusLanguage = getCurrentWpglobusLanguageHint();

        if (wpglobusLanguage) {
            input.wpglobus_language = wpglobusLanguage;
        }

        return input;
    }

    function runAbility(abilityName, input, signal) {
        const request = {
            path: getRunAbilityPath(abilityName),
            method: 'POST',
            data: {
                input: input || {},
            },
        };

        if (settings && settings.restNonce) {
            request.headers = {
                'X-WP-Nonce': settings.restNonce,
            };
        }

        if (signal) {
            request.signal = signal;
        }

        slyDebug('runAbility request', abilityName, input);
        return apiFetch(request).then(function (response) {
            slyDebug('runAbility response', abilityName, response);
            return response;
        }, function (error) {
            slyDebug('runAbility error', abilityName, error);
            throw error;
        });
    }

    function getErrorMessage(error) {
        if (error && error.message) {
            return error.message;
        }

        return text('unknownError', 'An unexpected error occurred.');
    }

    function normalizeTranslationProgress(progress) {
        if (!progress || typeof progress !== 'object') {
            return null;
        }

        const phase = typeof progress.phase === 'string' ? progress.phase : '';
        if (!phase) {
            return null;
        }

        return {
            phase: phase,
            currentChunk: Math.max(0, parseInt(progress.current_chunk, 10) || 0),
            totalChunks: Math.max(0, parseInt(progress.total_chunks, 10) || 0),
            percent: Math.max(0, Math.min(100, parseInt(progress.percent, 10) || 0)),
        };
    }

    function pollTranslationProgress() {
        const editorPostId = getCurrentEditorPostId();
        return runAbility('ai-translate/get-progress', editorPostId ? { post_id: editorPostId } : {})
            .then(normalizeTranslationProgress);
    }

    function formatText(template, replacements) {
        return Object.keys(replacements).reduce(function (result, key) {
            return result.split('{' + key + '}').join(String(replacements[key]));
        }, template);
    }

    function getTranslationProgressLabel(translationProgress) {
        if (!translationProgress || !translationProgress.phase) {
            return '';
        }

        switch (translationProgress.phase) {
            case 'title':
                return text('progressTitle', 'Translating title...');
            case 'content':
                if (translationProgress.totalChunks > 0 && translationProgress.currentChunk >= translationProgress.totalChunks) {
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
                return text('loadingStatus', 'Loading translation status...');
        }
    }

    function indexTranslations(translations) {
        const indexed = {};

        if (!Array.isArray(translations)) {
            return indexed;
        }

        translations.forEach(function (translation) {
            if (translation && translation.lang) {
                indexed[translation.lang] = translation;
            }
        });

        return indexed;
    }

    function readStoredTargetLanguage() {
        try {
            return window.localStorage ? window.localStorage.getItem('aiTranslateTargetLanguage') || '' : '';
        } catch (error) {
            return '';
        }
    }

    function storeTargetLanguage(targetLanguage) {
        if (!targetLanguage) {
            return;
        }

        try {
            if (window.localStorage) {
                window.localStorage.setItem('aiTranslateTargetLanguage', targetLanguage);
            }
        } catch (error) {
        }
    }

    function getAvailableTargetLanguages(languages, sourceLanguage) {
        const normalizedSourceLanguage = normalizeLanguageCode(sourceLanguage);

        return languages.filter(function (language) {
            return language && language.code && normalizeLanguageCode(language.code) !== normalizedSourceLanguage;
        });
    }

    function findMatchingLanguageCode(languages, preferredLanguageCode) {
        const normalizedPreferredLanguageCode = normalizeLanguageCode(preferredLanguageCode);

        if (!normalizedPreferredLanguageCode) {
            return '';
        }

        const exactMatch = languages.find(function (language) {
            return normalizeLanguageCode(language.code) === normalizedPreferredLanguageCode;
        });

        if (exactMatch) {
            return exactMatch.code;
        }

        const preferredBaseLanguageCode = getLanguageBaseCode(normalizedPreferredLanguageCode);
        const baseMatch = languages.find(function (language) {
            return getLanguageBaseCode(language.code) === preferredBaseLanguageCode;
        });

        return baseMatch ? baseMatch.code : '';
    }

    function getDefaultSelectionSourceLanguage(languages) {
        const candidateLanguages = [
            settings && settings.defaultSourceLanguage ? settings.defaultSourceLanguage : '',
            typeof document !== 'undefined' && document.documentElement ? document.documentElement.lang : '',
            typeof window !== 'undefined' && window.navigator ? window.navigator.language : '',
            'en',
        ];

        for (let index = 0; index < candidateLanguages.length; index += 1) {
            const matchingLanguageCode = findMatchingLanguageCode(languages, candidateLanguages[index]);

            if (matchingLanguageCode) {
                return matchingLanguageCode;
            }
        }

        return languages[0] ? languages[0].code : 'en';
    }

    // Cached per-post source-language lookup. The selection / block toolbar
    // dialogs use this to default the "Source language" dropdown to the actual
    // language of the post being edited (as configured in Polylang) rather
    // than the browser locale.
    const _postSourceLanguageCache = {};

    function getCurrentEditorPostId() {
        try {
            if (wp && wp.data && typeof wp.data.select === 'function') {
                const editorStore = wp.data.select('core/editor');
                if (editorStore && typeof editorStore.getCurrentPostId === 'function') {
                    return parseInt(editorStore.getCurrentPostId(), 10) || 0;
                }
            }
        } catch (e) { }
        return 0;
    }

    function fetchCurrentPostSourceLanguage() {
        const postId = getCurrentEditorPostId();
        if (!postId) { return Promise.resolve(''); }
        if (Object.prototype.hasOwnProperty.call(_postSourceLanguageCache, postId)) {
            return Promise.resolve(_postSourceLanguageCache[postId]);
        }
        return runAbility('ai-translate/get-translation-status', buildTranslationStatusInput(postId))
            .then(function (response) {
                const sourceLanguage = response && response.source_language ? String(response.source_language) : '';
                _postSourceLanguageCache[postId] = sourceLanguage;
                return sourceLanguage;
            })
            .catch(function () { return ''; });
    }

    // Module-level cache so both Sidebar and Toolbar share the latest value within a page session.
    let _lastAdditionalPrompt = settings && settings.lastAdditionalPrompt ? settings.lastAdditionalPrompt : '';
    try {
        if (typeof window !== 'undefined' && window.localStorage) {
            const storedAdditionalPrompt = window.localStorage.getItem('aiTranslateLastAdditionalPrompt') || '';
            if (storedAdditionalPrompt) {
                _lastAdditionalPrompt = storedAdditionalPrompt;
            } else if (_lastAdditionalPrompt) {
                window.localStorage.setItem('aiTranslateLastAdditionalPrompt', _lastAdditionalPrompt);
            }
        }
    } catch (error) {
    }

    // Module-level model slug shared between Sidebar and Selection modal.
    // _availableModels is mutable so the sidebar's "refresh models" button
    // can replace the cached list after fetching fresh data from the server.
    let _availableModels = settings && Array.isArray(settings.models) ? settings.models : [];
    let _selectedModelSlug = '';
    // Subscribers (React setState callbacks) that get notified when
    // _availableModels is replaced via fetchAvailableModels(refresh=true).
    const _modelListSubscribers = [];

    function subscribeToModelList(callback) {
        _modelListSubscribers.push(callback);
        return function unsubscribe() {
            const index = _modelListSubscribers.indexOf(callback);
            if (index !== -1) { _modelListSubscribers.splice(index, 1); }
        };
    }

    function notifyModelListSubscribers() {
        _modelListSubscribers.forEach(function (cb) {
            try { cb(_availableModels); } catch (e) { }
        });
    }

    function fetchAvailableModels(forceRefresh) {
        return runAbility('ai-translate/get-available-models', { refresh: !!forceRefresh }).then(function (response) {
            const models = response && Array.isArray(response.models) ? response.models : [];
            _availableModels = models;
            // If the currently selected model is no longer available, reset
            // the selection to the connector default so the sidebar does not
            // keep submitting an unknown slug.
            if (_selectedModelSlug && !models.some(function (m) { return m.value === _selectedModelSlug; })) {
                _selectedModelSlug = '';
                try { if (window.localStorage) { window.localStorage.removeItem('aiTranslateModelSlug'); } } catch (e) { }
            }
            notifyModelListSubscribers();
            return models;
        });
    }

    // Compute the label for the "Auto" option, showing the effective default model.
    var _autoOptionLabel = '— Auto —';

    function initSelectedModelSlug() {
        var stored = readStoredModelSlug();
        var resolved = resolveAvailableModelSlug(stored);

        if (resolved !== stored) {
            storeModelSlug(resolved);
        }

        _selectedModelSlug = resolved;
        return resolved;
    }

    function resolveAvailableModelSlug(preferredSlug) {
        if (preferredSlug && _availableModels.some(function (m) { return m.value === preferredSlug; })) {
            return preferredSlug;
        }

        var defaultSlug = settings && settings.defaultModelSlug ? settings.defaultModelSlug : '';
        if (defaultSlug && _availableModels.some(function (m) { return m.value === defaultSlug; })) {
            return defaultSlug;
        }

        return '';
    }

    function readStoredModelSlug() {
        try {
            return window.localStorage ? window.localStorage.getItem('aiTranslateModelSlug') || '' : '';
        } catch (error) {
            return '';
        }
    }

    function storeModelSlug(slug) {
        _selectedModelSlug = slug || '';
        try {
            if (window.localStorage) {
                if (slug) {
                    window.localStorage.setItem('aiTranslateModelSlug', slug);
                } else {
                    window.localStorage.removeItem('aiTranslateModelSlug');
                }
            }
        } catch (error) { }
    }

    function getLastAdditionalPrompt() {
        return _lastAdditionalPrompt;
    }

    function saveAdditionalPromptPreference(additionalPrompt) {
        _lastAdditionalPrompt = typeof additionalPrompt === 'string' ? additionalPrompt : '';

        try {
            if (window.localStorage) {
                window.localStorage.setItem('aiTranslateLastAdditionalPrompt', _lastAdditionalPrompt);
            }
        } catch (error) {
        }

        const path = getRunAbilityPath('ai-translate/save-additional-prompt');
        const request = {
            path: path,
            method: 'POST',
            data: { input: { additional_prompt: _lastAdditionalPrompt } },
        };

        if (settings && settings.restNonce) {
            request.headers = { 'X-WP-Nonce': settings.restNonce };
        }

        apiFetch(request).catch(function () { });
    }

    function resolveSelectionTargetLanguage(languages, sourceLanguage, preferredLanguageCode) {
        const availableLanguages = getAvailableTargetLanguages(languages, sourceLanguage);
        const preferredTargetLanguage = findMatchingLanguageCode(availableLanguages, preferredLanguageCode);

        if (preferredTargetLanguage) {
            return preferredTargetLanguage;
        }

        const storedTargetLanguage = findMatchingLanguageCode(availableLanguages, readStoredTargetLanguage());
        if (storedTargetLanguage) {
            return storedTargetLanguage;
        }

        const englishLanguage = availableLanguages.find(function (language) {
            return getLanguageBaseCode(language.code) === 'en';
        });

        if (englishLanguage) {
            return englishLanguage.code;
        }

        return availableLanguages[0] ? availableLanguages[0].code : '';
    }

    function isSingleEntryTranslationMode() {
        return !!(settings && settings.singleEntryTranslationMode);
    }

    function AiTranslatePanel() {
        const postContext = useSelect(function (select) {
            const editorStore = select('core/editor');
            if (!editorStore) {
                return {
                    postId: 0,
                    postStatus: '',
                    isDirty: false,
                    isSaving: false,
                };
            }

            return {
                postId: editorStore.getCurrentPostId() || 0,
                postStatus: editorStore.getEditedPostAttribute('status') || '',
                isDirty: editorStore.isEditedPostDirty(),
                isSaving: editorStore.isSavingPost(),
            };
        }, []);

        const postId = postContext.postId;
        const [languages, setLanguages] = useState([]);
        const [statusData, setStatusData] = useState(null);
        const [targetLanguage, setTargetLanguage] = useState('');
        const [overwrite, setOverwrite] = useState(false);
        // Title translation is always enabled now; the toggle was removed
        // from the sidebar UI but the translate-content endpoint still
        // expects translate_title to be passed.
        const translateTitle = true;
        const [additionalPrompt, setAdditionalPrompt] = useState(getLastAdditionalPrompt);
        const [modelSlug, setModelSlug] = useState(initSelectedModelSlug);
        const [availableModels, setAvailableModels] = useState(_availableModels);
        const [isRefreshingModels, setIsRefreshingModels] = useState(false);
        const [isRefreshing, setIsRefreshing] = useState(false);
        const [isTranslating, setIsTranslating] = useState(false);
        const [translationProgress, setTranslationProgress] = useState(null);
        const [hasLoadedData, setHasLoadedData] = useState(false);
        const [errorMessage, setErrorMessage] = useState('');
        const [successState, setSuccessState] = useState(null);
        const translateAbortControllerRef = useRef(null);
        const progressPollingIntervalRef = useRef(null);

        function stopTranslationProgressPolling() {
            if (progressPollingIntervalRef.current) {
                window.clearInterval(progressPollingIntervalRef.current);
                progressPollingIntervalRef.current = null;
            }
        }

        function requestTranslationProgress() {
            pollTranslationProgress()
                .then(function (progress) {
                    setTranslationProgress(progress);
                })
                .catch(function () {
                });
        }

        function startTranslationProgressPolling() {
            stopTranslationProgressPolling();
            requestTranslationProgress();
            // 2s cadence keeps the overlay visibly responsive while halving
            // the REST round-trip rate compared to the original 1s loop. Long
            // page translations otherwise generate 600+ progress polls per
            // job, each touching a transient + capability check.
            progressPollingIntervalRef.current = window.setInterval(function () {
                requestTranslationProgress();
            }, 2000);
        }

        function refreshData() {
            if (!postId) {
                return;
            }

            setIsRefreshing(true);
            setHasLoadedData(false);
            setErrorMessage('');

            Promise.allSettled([
                runAbility('ai-translate/get-languages', {}),
                runAbility('ai-translate/get-translation-status', buildTranslationStatusInput(postId)),
            ])
                .then(function (results) {
                    const nextMessages = [];
                    const languagesResult = results[0];
                    const statusResult = results[1];

                    if (languagesResult && languagesResult.status === 'fulfilled') {
                        setLanguages(Array.isArray(languagesResult.value) ? languagesResult.value : []);
                    } else {
                        setLanguages([]);
                        if (languagesResult) {
                            nextMessages.push(getErrorMessage(languagesResult.reason));
                        }
                    }

                    if (statusResult && statusResult.status === 'fulfilled') {
                        setStatusData(statusResult.value || null);
                    } else {
                        setStatusData(null);
                        if (statusResult) {
                            nextMessages.push(getErrorMessage(statusResult.reason));
                        }
                    }

                    const uniqueMessages = nextMessages.filter(function (message, index) {
                        return !!message && nextMessages.indexOf(message) === index;
                    });

                    if (uniqueMessages.length) {
                        setErrorMessage(uniqueMessages.join(' '));
                    }
                })
                .catch(function (error) {
                    setLanguages([]);
                    setStatusData(null);
                    setErrorMessage(getErrorMessage(error));
                })
                .finally(function () {
                    setIsRefreshing(false);
                    setHasLoadedData(true);
                });
        }

        useEffect(function () {
            setSuccessState(null);
            setTargetLanguage('');
            setHasLoadedData(false);
            setTranslationProgress(null);
            stopTranslationProgressPolling();
            refreshData();
        }, [postId]);

        useEffect(function () {
            return function () {
                stopTranslationProgressPolling();
            };
        }, []);

        // Keep this panel in sync with the module-level model list so that
        // refreshes triggered from anywhere (sidebar refresh button, future
        // modal refreshes) propagate without remounting.
        useEffect(function () {
            return subscribeToModelList(function (nextModels) {
                setAvailableModels(nextModels.slice());
                if (modelSlug && !nextModels.some(function (m) { return m.value === modelSlug; })) {
                    var nextModelSlug = resolveAvailableModelSlug('');
                    setModelSlug(nextModelSlug);
                    storeModelSlug(nextModelSlug);
                }
            });
        }, [modelSlug]);

        function handleRefreshModels() {
            if (isRefreshingModels) { return; }
            setIsRefreshingModels(true);
            fetchAvailableModels(true)
                .catch(function () { })
                .finally(function () { setIsRefreshingModels(false); });
        }

        const translationIndex = indexTranslations(statusData && statusData.translations ? statusData.translations : []);
        const sourceLanguage = statusData && statusData.source_language ? statusData.source_language : '';
        const availableLanguages = getAvailableTargetLanguages(languages, sourceLanguage);

        useEffect(function () {
            if (!availableLanguages.length) {
                if (targetLanguage) {
                    setTargetLanguage('');
                }
                return;
            }

            if (targetLanguage) {
                const hasTargetLanguage = availableLanguages.some(function (language) {
                    return language.code === targetLanguage;
                });

                if (hasTargetLanguage) {
                    return;
                }
            }

            const storedTargetLanguage = readStoredTargetLanguage();
            if (storedTargetLanguage) {
                const matchingStoredLanguage = availableLanguages.find(function (language) {
                    return language.code === storedTargetLanguage;
                });

                if (matchingStoredLanguage) {
                    setTargetLanguage(matchingStoredLanguage.code);
                    return;
                }
            }

            const firstMissingTranslation = availableLanguages.find(function (language) {
                return !translationIndex[language.code] || !translationIndex[language.code].exists;
            });

            setTargetLanguage((firstMissingTranslation || availableLanguages[0]).code);
        }, [availableLanguages, targetLanguage, translationIndex]);

        const selectedTranslation = targetLanguage && translationIndex[targetLanguage] ? translationIndex[targetLanguage] : null;
        const hasExistingTranslation = !!(selectedTranslation && selectedTranslation.exists);
        const canTranslate = !!postId && !!targetLanguage && !postContext.isDirty && !postContext.isSaving && !isRefreshing && !isTranslating && (overwrite || !hasExistingTranslation);

        function updateTargetLanguage(nextTargetLanguage) {
            setTargetLanguage(nextTargetLanguage);
            storeTargetLanguage(nextTargetLanguage);
        }

        function handleTranslate() {
            if (!canTranslate) {
                return;
            }

            const wasExistingTranslation = hasExistingTranslation;
            const abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            translateAbortControllerRef.current = abortController;
            setIsTranslating(true);
            setTranslationProgress(null);
            setErrorMessage('');
            setSuccessState(null);
            startTranslationProgressPolling();

            runAbility('ai-translate/translate-content', {
                post_id: postId,
                source_language: sourceLanguage || undefined,
                target_language: targetLanguage,
                post_status: postContext.postStatus || 'draft',
                overwrite: overwrite,
                translate_title: translateTitle,
                additional_prompt: additionalPrompt || undefined,
                model_slug: modelSlug || undefined,
            }, abortController ? abortController.signal : undefined)
                .then(function (response) {
                    storeTargetLanguage(targetLanguage);
                    saveAdditionalPromptPreference(additionalPrompt);
                    setSuccessState({
                        message: wasExistingTranslation ? text('translationUpdatedNotice', 'Translation updated successfully.') : text('translationCreatedNotice', 'Translation created successfully.'),
                        editLink: !isSingleEntryTranslationMode() && response && response.edit_link ? response.edit_link : '',
                    });
                    refreshData();
                })
                .catch(function (error) {
                    if (error && (error.name === 'AbortError' || error.code === 'abort_error')) {
                        return;
                    }
                    setErrorMessage(getErrorMessage(error));
                })
                .finally(function () {
                    translateAbortControllerRef.current = null;
                    stopTranslationProgressPolling();
                    setTranslationProgress(null);
                    setIsTranslating(false);
                });
        }

        function handleCancelTranslate() {
            if (translateAbortControllerRef.current) {
                translateAbortControllerRef.current.abort();
            }
            // Reset the local progress state immediately so the bar drops to 0
            // before the next translation starts. Without this the editor
            // briefly re-displays the cancelled job's last percentage when the
            // user clicks "Translate now" again.
            stopTranslationProgressPolling();
            setTranslationProgress(null);
            setIsTranslating(false);
            runAbility('ai-translate/cancel-translation', { post_id: postId }).catch(function () { });
        }

        const statusItems = Array.isArray(statusData && statusData.translations) ? statusData.translations : [];
        const showTranslationStatusList = !isSingleEntryTranslationMode() && statusItems.length > 0;

        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'ai-translate-editor-panel',
                title: text('panelTitle', 'AI Translation with SlyTranslate'),
                icon: 'translation',
            },
            sourceLanguage ? createElement(
                'div',
                { style: { marginBottom: '12px' } },
                createElement(
                    'p',
                    { style: { margin: 0 } },
                    createElement('strong', null, text('sourceLanguageLabel', 'Source language') + ': '),
                    getLanguageDisplayName(sourceLanguage)
                ),
                createElement(
                    'p',
                    { style: { margin: '4px 0 0', fontSize: '12px', color: '#50575e' } },
                    text('sourceLanguageManagedHint', 'The source language is managed by your language plugin.')
                )
            ) : null,
            !postId ? createElement(Notice, { status: 'warning', isDismissible: false }, text('saveFirstNotice', 'Save the content before creating a translation.')) : null,
            postContext.isDirty ? createElement(Notice, { status: 'warning', isDismissible: false }, text('saveChangesNotice', 'Save your latest changes before translating so the translation uses the current content.')) : null,
            errorMessage ? createElement(Notice, { status: 'error', isDismissible: true, onRemove: function () { setErrorMessage(''); } }, errorMessage) : null,
            successState ? createElement(
                Notice,
                { status: 'success', isDismissible: true, onRemove: function () { setSuccessState(null); } },
                createElement(
                    Fragment,
                    null,
                    successState.message,
                    successState.editLink ? createElement(Fragment, null, ' ', createElement('a', { href: successState.editLink }, text('openTranslation', 'Open translation'))) : null
                )
            ) : null,
            isRefreshing ? createElement('p', null, createElement(Spinner, null), ' ', text('loadingStatus', 'Loading translation status...')) : null,
            availableLanguages.length ? createElement(
                'div',
                { style: { marginTop: '4px', marginBottom: '20px' } },
                createElement(SelectControl, {
                    label: text('targetLanguageLabel', 'Target language'),
                    value: targetLanguage,
                    onChange: updateTargetLanguage,
                    options: availableLanguages.map(function (language) {
                        return {
                            label: language.name + ' (' + language.code + ')',
                            value: language.code,
                        };
                    }),
                })
            ) : (!isRefreshing && hasLoadedData && !errorMessage ? createElement(Notice, { status: 'info', isDismissible: false }, text('noLanguages', 'No target languages are available for this content item.')) : null),
            availableModels.length > 0 ? createElement(
                'div',
                { style: { marginTop: '4px', marginBottom: '20px' } },
                createElement(
                    'div',
                    { style: { display: 'flex', gap: '6px', alignItems: 'flex-end', width: '100%' } },
                    createElement(
                        'div',
                        { style: { flex: 1, minWidth: 0 } },
                        createElement(SelectControl, {
                            label: text('modelLabel', 'AI model'),
                            value: modelSlug,
                            onChange: function (nextModelSlug) {
                                setModelSlug(nextModelSlug);
                                storeModelSlug(nextModelSlug);
                            },
                            options: [{ label: _autoOptionLabel, value: '' }].concat(availableModels),
                        })
                    ),
                    createElement(Button, {
                        icon: 'update',
                        variant: 'tertiary',
                        label: text('refreshModelsButton', 'Refresh model list'),
                        showTooltip: true,
                        onClick: handleRefreshModels,
                        disabled: isRefreshingModels,
                        isBusy: isRefreshingModels,
                        style: { flexShrink: 0, width: '36px', minWidth: '36px', padding: '0', justifyContent: 'center' },
                    })
                )
            ) : null,
            createElement(
                'div',
                { style: { marginTop: '4px', marginBottom: '12px' } },
                createElement(TextareaControl, {
                    label: text('additionalPromptLabel', 'Additional instructions (optional)'),
                    help: text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.'),
                    value: additionalPrompt,
                    onChange: setAdditionalPrompt,
                    rows: 3,
                })
            ),
            (modelSlug || '').toLowerCase().indexOf('translategemma') !== -1 ? createElement(
                Notice,
                { status: 'warning', isDismissible: false, style: { marginBottom: '12px' } },
                text('translateGemmaAdditionalPromptWarning', 'TranslateGemma does not reliably follow style guidelines. For tone, address forms, and language register, consider Instruct-LLMs.')
            ) : null,
            createElement(
                'div',
                { style: { display: 'grid', rowGap: '10px', marginTop: '4px', marginBottom: '18px' } },
                createElement(Button, {
                    variant: 'primary',
                    onClick: isTranslating ? handleCancelTranslate : handleTranslate,
                    disabled: isTranslating ? false : !canTranslate,
                    isBusy: isTranslating,
                    style: { width: '100%', justifyContent: 'center' },
                }, isTranslating ? text('cancelTranslationButton', 'Cancel translation') : text('translateButton', 'Translate now')),
                isTranslating && translationProgress ? createElement(
                    'div',
                    { style: { display: 'grid', rowGap: '8px' } },
                    createElement(
                        'div',
                        {
                            style: {
                                height: '8px',
                                borderRadius: '999px',
                                overflow: 'hidden',
                                background: '#dcdcde',
                            },
                        },
                        createElement('div', {
                            style: {
                                width: translationProgress.percent + '%',
                                height: '100%',
                                background: 'linear-gradient(90deg, #3858e9 0%, #1d4ed8 100%)',
                                transition: 'width 0.3s ease',
                            },
                        })
                    ),
                    createElement(
                        'div',
                        { style: { fontSize: '12px', color: '#50575e' } },
                        getTranslationProgressLabel(translationProgress)
                    )
                ) : null,
                hasExistingTranslation ? createElement(
                    'div',
                    { style: { marginTop: '10px', marginBottom: '10px' } },
                    !overwrite ? createElement(Notice, { status: 'warning', isDismissible: false }, text('existingTranslationNotice', 'A translation already exists for the selected language. Enable overwrite to update it.')) : null,
                    createElement(
                        'div',
                        { style: { marginTop: !overwrite ? '8px' : 0 } },
                        createElement(ToggleControl, {
                            label: text('overwriteLabel', 'Overwrite existing translation'),
                            checked: overwrite,
                            onChange: function (value) {
                                setOverwrite(!!value);
                            },
                        })
                    )
                ) : null
            ),
            showTranslationStatusList ? createElement(
                'div',
                { style: { textAlign: 'left' } },
                createElement('p', { style: { margin: '0 0 4px', textAlign: 'left' } }, createElement('strong', null, text('translationStatusLabel', 'Translation status'))),
                createElement(
                    'ul',
                    { style: { margin: 0, paddingLeft: '18px', textAlign: 'left' } },
                    statusItems.map(function (translation) {
                        const matchingLanguage = languages.find(function (language) {
                            return language.code === translation.lang;
                        });
                        const languageLabel = matchingLanguage ? matchingLanguage.name + ' (' + translation.lang + ')' : translation.lang;

                        if (translation.exists) {
                            // Direct link to the translated post; fall back to
                            // a plain "Available" label if no edit link is
                            // exposed (e.g. caps-restricted users).
                            return createElement(
                                'li',
                                { key: translation.lang },
                                languageLabel + ': ',
                                translation.edit_link
                                    ? createElement('a', { href: translation.edit_link }, text('openTranslationShort', 'Open'))
                                    : text('translationExists', 'Available')
                            );
                        }

                        return createElement(
                            'li',
                            { key: translation.lang },
                            languageLabel + ': ' + text('translationMissing', 'Not translated yet')
                        );
                    })
                )
            ) : null,
            createElement(
                'div',
                { style: { marginTop: showTranslationStatusList ? '12px' : 0 } },
                createElement(Button, {
                    variant: 'secondary',
                    onClick: refreshData,
                    disabled: !postId || isRefreshing || isTranslating,
                    style: { width: '100%', justifyContent: 'center' },
                }, text('refreshButton', 'Refresh translation status'))
            )
        );
    }

    function TranslateSelectionControl(props) {
        const [isOpen, setIsOpen] = useState(false);
        const [selectedText, setSelectedText] = useState('');
        const [selectionStart, setSelectionStart] = useState(0);
        const [selectionEnd, setSelectionEnd] = useState(0);
        const [languages, setLanguages] = useState([]);
        const [sourceLanguage, setSourceLanguage] = useState('');
        const [targetLanguage, setTargetLanguage] = useState('');
        const [errorMessage, setErrorMessage] = useState('');
        const [isTranslating, setIsTranslating] = useState(false);
        const [additionalPrompt, setAdditionalPrompt] = useState(getLastAdditionalPrompt);
        const selectionAbortRef = useRef(null);
        // Keep a ref to the latest props so the async .then() always uses the
        // current onChange / value instead of a stale closure capture. The
        // RichTextToolbarButton format component may re-render while the modal
        // is open, so we always read the freshest onChange/value through this
        // ref.
        const propsRef = useRef(props);
        propsRef.current = props;
        // Snapshot of the RichText value at dialog-open time. We replace into
        // this snapshot to guarantee the original selection range is valid even
        // if the live value lost its selection while the modal had focus.
        const selectionValueRef = useRef(null);

        const hasSelection = !!(
            props.value &&
            typeof props.value.start === 'number' &&
            typeof props.value.end === 'number' &&
            props.value.start !== props.value.end
        );
        const availableTargetLanguages = getAvailableTargetLanguages(languages, sourceLanguage);

        function closeDialog() {
            if (isTranslating) {
                return;
            }

            setIsOpen(false);
            setErrorMessage('');
        }

        function handleCancelSelection() {
            if (selectionAbortRef.current) {
                selectionAbortRef.current.abort();
                selectionAbortRef.current = null;
            }
            setIsTranslating(false);
        }

        function openDialog() {
            if (!hasSelection) {
                return;
            }

            const selectedValue = slice(props.value, props.value.start, props.value.end);
            const nextSelectedText = selectedValue && selectedValue.text ? selectedValue.text : '';

            if (!nextSelectedText.trim()) {
                return;
            }

            const nextLanguages = getSelectionLanguages();
            const fallbackSourceLanguage = getDefaultSelectionSourceLanguage(nextLanguages);
            const nextTargetLanguage = resolveSelectionTargetLanguage(nextLanguages, fallbackSourceLanguage, '');

            selectionValueRef.current = props.value;
            setSelectionStart(props.value.start);
            setSelectionEnd(props.value.end);
            setSelectedText(nextSelectedText);
            slyDebug('openDialog selection', { start: props.value.start, end: props.value.end, text: nextSelectedText });
            setLanguages(nextLanguages);
            setSourceLanguage(fallbackSourceLanguage);
            setTargetLanguage(nextTargetLanguage);
            setErrorMessage(nextTargetLanguage ? '' : text('translateSelectionUnavailable', 'No target languages are available for the selected text.'));
            setIsOpen(true);

            // Refine the source language to the actual post language (Polylang)
            // once the REST status responds. Falls back silently if unavailable.
            fetchCurrentPostSourceLanguage().then(function (postSourceLanguage) {
                if (!postSourceLanguage) { return; }
                const matchedSource = findMatchingLanguageCode(nextLanguages, postSourceLanguage);
                if (!matchedSource || matchedSource === fallbackSourceLanguage) { return; }
                slyDebug('source language refined from post', { from: fallbackSourceLanguage, to: matchedSource });
                setSourceLanguage(matchedSource);
                setTargetLanguage(function (currentTargetLanguage) {
                    return resolveSelectionTargetLanguage(nextLanguages, matchedSource, currentTargetLanguage);
                });
            });
        }

        function handleTranslateSelection() {
            if (!selectedText.trim() || !sourceLanguage || !targetLanguage) {
                return;
            }

            var abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
            selectionAbortRef.current = abortController;
            setIsTranslating(true);
            setErrorMessage('');

            runAbility('ai-translate/translate-text', {
                text: selectedText,
                source_language: sourceLanguage || 'en',
                target_language: targetLanguage,
                additional_prompt: additionalPrompt || undefined,
                model_slug: _selectedModelSlug || undefined,
            }, abortController ? abortController.signal : undefined)
                .then(function (response) {
                    const translatedText = response && response.translated_text ? response.translated_text : '';

                    if (!translatedText) {
                        setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                        return;
                    }

                    // Guard against model echoing back only the additional_prompt instead of translating.
                    if (additionalPrompt && translatedText.trim() === additionalPrompt.trim()) {
                        setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                        return;
                    }

                    storeTargetLanguage(targetLanguage);
                    saveAdditionalPromptPreference(additionalPrompt);

                    // Apply replacement synchronously into the snapshot value
                    // captured at dialog-open time, then close the modal. This
                    // restores the working behaviour from 1.4.0 — the deferred
                    // useEffect approach failed because the format-type wrapper
                    // around RichTextToolbarButton can re-mount while the modal
                    // is open, leaving propsRef pointing at a defunct onChange.
                    var snapshotValue = selectionValueRef.current || propsRef.current.value;
                    var onChange = propsRef.current.onChange;
                    slyDebug('applying inline replacement', { translatedText: translatedText, start: selectionStart, end: selectionEnd, hasOnChange: typeof onChange === 'function' });
                    if (typeof onChange === 'function' && snapshotValue) {
                        onChange(insert(snapshotValue, translatedText, selectionStart, selectionEnd));
                    } else {
                        slyDebug('inline replacement skipped: missing onChange or snapshotValue');
                    }
                    setIsOpen(false);
                    setErrorMessage('');
                })
                .catch(function (error) {
                    if (error && (error.name === 'AbortError' || error.code === 'abort_error')) {
                        return;
                    }
                    setErrorMessage(getErrorMessage(error));
                })
                .finally(function () {
                    selectionAbortRef.current = null;
                    setIsTranslating(false);
                });
        }

        return createElement(
            Fragment,
            null,
            createElement(RichTextToolbarButton, {
                name: 'unknown',
                icon: 'translation',
                title: text('pickerTitle', 'Translate'),
                onClick: openDialog,
                isDisabled: !hasSelection,
            }),
            isOpen && Modal ? createElement(
                Modal,
                {
                    title: text('pickerTitle', 'Translate'),
                    onRequestClose: closeDialog,
                    shouldCloseOnClickOutside: !isTranslating,
                    shouldCloseOnEsc: !isTranslating,
                },
                errorMessage ? createElement(Notice, { status: 'error', isDismissible: true, onRemove: function () { setErrorMessage(''); } }, errorMessage) : null,
                renderLanguagePairControls({
                    languages: languages,
                    targetLanguages: availableTargetLanguages,
                    sourceId: 'slytranslate-inline-source-language',
                    targetId: 'slytranslate-inline-target-language',
                    sourceLanguage: sourceLanguage,
                    targetLanguage: targetLanguage,
                    onSourceChange: function (nextSourceLanguage) {
                        setSourceLanguage(nextSourceLanguage);
                        setTargetLanguage(function (currentTargetLanguage) {
                            return resolveSelectionTargetLanguage(languages, nextSourceLanguage, currentTargetLanguage);
                        });
                    },
                    onTargetChange: function (nextTargetLanguage) {
                        setTargetLanguage(nextTargetLanguage);
                    },
                    onSwap: function () {
                        if (!sourceLanguage || !targetLanguage) { return; }
                        var nextSource = targetLanguage;
                        var nextTarget = sourceLanguage;
                        setSourceLanguage(nextSource);
                        setTargetLanguage(resolveSelectionTargetLanguage(languages, nextSource, nextTarget));
                    },
                }),
                createElement(TextareaControl, {
                    label: text('additionalPromptLabel', 'Additional instructions (optional)'),
                    help: text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.'),
                    value: additionalPrompt,
                    onChange: setAdditionalPrompt,
                    rows: 3,
                }),
                (_selectedModelSlug || '').toLowerCase().indexOf('translategemma') !== -1 ? createElement(
                    Notice,
                    { status: 'warning', isDismissible: false, style: { marginTop: '8px' } },
                    text('translateGemmaAdditionalPromptWarning', 'TranslateGemma does not reliably follow style guidelines. For tone, address forms, and language register, consider Instruct-LLMs.')
                ) : null,
                createElement(
                    'div',
                    { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } },
                    !isTranslating ? createElement(Button, { variant: 'secondary', onClick: closeDialog }, text('cancelButton', 'Cancel')) : null,
                    createElement(Button, { variant: 'primary', onClick: isTranslating ? handleCancelSelection : handleTranslateSelection, disabled: isTranslating ? false : (!sourceLanguage || !targetLanguage || !selectedText.trim()), isBusy: isTranslating }, isTranslating ? text('cancelTranslationButton', 'Cancel translation') : text('pickerStartButton', 'Start translation'))
                )
            ) : null
        );
    }

    if (PluginDocumentSettingPanel && hasTranslationPlugin) {
        registerPlugin('ai-translate-editor-panel', {
            render: AiTranslatePanel,
        });
    }

    // Block-level translation: toolbar button for selected blocks.
    if (blockTranslationAvailable) {
        function TranslateBlockPlugin() {
            var selectedBlockClientIds = useSelect(function (select) {
                var blockEditorStore = select('core/block-editor');
                if (!blockEditorStore) { return []; }
                // getSelectedBlockClientIds covers single and multi-selection.
                if (typeof blockEditorStore.getSelectedBlockClientIds === 'function') {
                    return blockEditorStore.getSelectedBlockClientIds();
                }
                // Fallback: combine single + multi selection.
                var multi = typeof blockEditorStore.getMultiSelectedBlockClientIds === 'function'
                    ? blockEditorStore.getMultiSelectedBlockClientIds()
                    : [];
                if (multi.length > 0) { return multi; }
                var single = typeof blockEditorStore.getSelectedBlockClientId === 'function'
                    ? blockEditorStore.getSelectedBlockClientId()
                    : null;
                return single ? [single] : [];
            }, []);

            var blockEditorDispatch = useDispatch('core/block-editor');
            var replaceBlocks = blockEditorDispatch ? blockEditorDispatch.replaceBlocks : null;

            var [isOpen, setIsOpen] = useState(false);
            var [languages, setLanguages] = useState([]);
            var [sourceLanguage, setSourceLanguage] = useState('');
            var [targetLanguage, setTargetLanguage] = useState('');
            var [additionalPrompt, setAdditionalPrompt] = useState(getLastAdditionalPrompt);
            var [isTranslating, setIsTranslating] = useState(false);
            var [errorMessage, setErrorMessage] = useState('');
            var blockAbortRef = useRef(null);
            // Capture the client IDs when the dialog opens so they stay stable during translation.
            var capturedClientIdsRef = useRef([]);
            // Keep replaceBlocks in a ref so the .then() callback always sees the
            // latest dispatcher even after re-renders of the menu-item host.
            var replaceBlocksRef = useRef(replaceBlocks);
            replaceBlocksRef.current = replaceBlocks;

            var hasBlocks = selectedBlockClientIds.length > 0;

            function openBlockTranslateDialog() {
                if (!hasBlocks) { return; }
                capturedClientIdsRef.current = selectedBlockClientIds.slice();
                var nextLanguages = getSelectionLanguages();
                var fallbackSourceLanguage = getDefaultSelectionSourceLanguage(nextLanguages);
                var nextTargetLanguage = resolveSelectionTargetLanguage(nextLanguages, fallbackSourceLanguage, '');
                setLanguages(nextLanguages);
                setSourceLanguage(fallbackSourceLanguage);
                setTargetLanguage(nextTargetLanguage);
                setErrorMessage('');
                setIsOpen(true);

                // Refine the source language to the actual post language
                // (Polylang) once the REST status responds.
                fetchCurrentPostSourceLanguage().then(function (postSourceLanguage) {
                    if (!postSourceLanguage) { return; }
                    var matchedSource = findMatchingLanguageCode(nextLanguages, postSourceLanguage);
                    if (!matchedSource || matchedSource === fallbackSourceLanguage) { return; }
                    slyDebug('block source language refined from post', { from: fallbackSourceLanguage, to: matchedSource });
                    setSourceLanguage(matchedSource);
                    setTargetLanguage(function (currentTargetLanguage) {
                        return resolveSelectionTargetLanguage(nextLanguages, matchedSource, currentTargetLanguage);
                    });
                });
            }

            function closeBlockDialog() {
                if (isTranslating) { return; }
                setIsOpen(false);
                setErrorMessage('');
            }

            function handleCancelBlockTranslation() {
                if (blockAbortRef.current) {
                    blockAbortRef.current.abort();
                    blockAbortRef.current = null;
                }
                setIsTranslating(false);
            }

            function handleTranslateBlocks() {
                if (!capturedClientIdsRef.current.length || !sourceLanguage || !targetLanguage || !replaceBlocks) {
                    return;
                }

                var blockEditorStore = wp.data.select('core/block-editor');
                if (!blockEditorStore) { return; }

                var selectedBlocks = capturedClientIdsRef.current.map(function (clientId) {
                    return blockEditorStore.getBlock(clientId);
                }).filter(Boolean);

                if (!selectedBlocks.length) {
                    setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                    return;
                }

                var serializedContent = serializeBlocks(selectedBlocks);
                if (!serializedContent.trim()) {
                    setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                    return;
                }

                var abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
                blockAbortRef.current = abortController;
                setIsTranslating(true);
                setErrorMessage('');

                runAbility('ai-translate/translate-blocks', {
                    content: serializedContent,
                    source_language: sourceLanguage,
                    target_language: targetLanguage,
                    additional_prompt: additionalPrompt || undefined,
                    model_slug: _selectedModelSlug || undefined,
                }, abortController ? abortController.signal : undefined)
                    .then(function (response) {
                        var translatedContent = response && response.translated_content ? response.translated_content : '';
                        if (!translatedContent.trim()) {
                            setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                            return;
                        }

                        storeTargetLanguage(targetLanguage);
                        saveAdditionalPromptPreference(additionalPrompt);

                        var translatedBlocks = parseBlocks(translatedContent);
                        if (!translatedBlocks || !translatedBlocks.length) {
                            setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                            return;
                        }

                        // Apply replaceBlocks synchronously: replaceBlocks is a
                        // global block-editor dispatcher and works regardless of
                        // current focus, so deferring it via useEffect only adds
                        // a window where the host component can unmount and the
                        // call is silently dropped.
                        var dispatch = replaceBlocksRef.current;
                        slyDebug('applying block replacement', { ids: capturedClientIdsRef.current, blockCount: translatedBlocks.length, hasDispatch: typeof dispatch === 'function' });
                        if (typeof dispatch === 'function') {
                            dispatch(capturedClientIdsRef.current, translatedBlocks);
                        } else {
                            slyDebug('block replacement skipped: replaceBlocks dispatcher unavailable');
                            setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                            return;
                        }
                        setIsOpen(false);
                        setErrorMessage('');
                    })
                    .catch(function (error) {
                        if (error && (error.name === 'AbortError' || error.code === 'abort_error')) {
                            return;
                        }
                        setErrorMessage(getErrorMessage(error));
                    })
                    .finally(function () {
                        blockAbortRef.current = null;
                        setIsTranslating(false);
                    });
            }

            var availableTargetLanguages = getAvailableTargetLanguages(languages, sourceLanguage);
            var blockCount = selectedBlockClientIds.length;

            return createElement(
                Fragment,
                null,
                createElement(PluginBlockSettingsMenuItem, {
                    icon: 'translation',
                    label: text('pickerTitle', 'Translate'),
                    onClick: openBlockTranslateDialog,
                }),
                isOpen && Modal ? createElement(
                    Modal,
                    {
                        title: text('pickerTitle', 'Translate'),
                        onRequestClose: closeBlockDialog,
                        shouldCloseOnClickOutside: !isTranslating,
                        shouldCloseOnEsc: !isTranslating,
                    },
                    errorMessage ? createElement(Notice, { status: 'error', isDismissible: true, onRemove: function () { setErrorMessage(''); } }, errorMessage) : null,
                    renderLanguagePairControls({
                        languages: languages,
                        targetLanguages: availableTargetLanguages,
                        sourceId: 'slytranslate-block-source-language',
                        targetId: 'slytranslate-block-target-language',
                        sourceLanguage: sourceLanguage,
                        targetLanguage: targetLanguage,
                        onSourceChange: function (nextSourceLanguage) {
                            setSourceLanguage(nextSourceLanguage);
                            setTargetLanguage(function (currentTargetLanguage) {
                                return resolveSelectionTargetLanguage(languages, nextSourceLanguage, currentTargetLanguage);
                            });
                        },
                        onTargetChange: function (nextTargetLanguage) { setTargetLanguage(nextTargetLanguage); },
                        onSwap: function () {
                            if (!sourceLanguage || !targetLanguage) { return; }
                            var nextSource = targetLanguage;
                            var nextTarget = sourceLanguage;
                            setSourceLanguage(nextSource);
                            setTargetLanguage(resolveSelectionTargetLanguage(languages, nextSource, nextTarget));
                        },
                    }),
                    createElement(TextareaControl, {
                        label: text('additionalPromptLabel', 'Additional instructions (optional)'),
                        help: text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.'),
                        value: additionalPrompt,
                        onChange: setAdditionalPrompt,
                        rows: 3,
                    }),
                    createElement(
                        'div',
                        { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } },
                        !isTranslating ? createElement(Button, { variant: 'secondary', onClick: closeBlockDialog }, text('cancelButton', 'Cancel')) : null,
                        createElement(Button, {
                            variant: 'primary',
                            onClick: isTranslating ? handleCancelBlockTranslation : handleTranslateBlocks,
                            disabled: isTranslating ? false : (!sourceLanguage || !targetLanguage),
                            isBusy: isTranslating,
                        }, isTranslating ? text('cancelTranslationButton', 'Cancel translation') : text('pickerStartButton', 'Start translation'))
                    )
                ) : null
            );
        }

        registerPlugin('ai-translate-block-translation', {
            render: TranslateBlockPlugin,
        });
    }

    if (selectionFeatureAvailable) {
        registerFormatType('ai-translate/translate-selection', {
            title: text('pickerTitle', 'Translate'),
            tagName: 'span',
            className: 'slytranslate-selection-action',
            edit: TranslateSelectionControl,
        });
    }
})(window.wp, window.slyTranslateEditor);
