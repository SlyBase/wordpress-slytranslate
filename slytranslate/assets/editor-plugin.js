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
    const useSelect = wp.data.useSelect;
    const apiFetch = wp.apiFetch;
    const blockEditor = wp.blockEditor || wp.editor || {};
    const RichTextToolbarButton = blockEditor.RichTextToolbarButton;
    const richText = wp.richText || {};
    const registerFormatType = richText.registerFormatType;
    const slice = richText.slice;
    const insert = richText.insert;
    const selectionFeatureAvailable = !!(registerFormatType && RichTextToolbarButton && slice && insert);
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
        return selectionLanguageCodes.map(function (languageCode) {
            return {
                code: languageCode,
                name: getLanguageDisplayName(languageCode),
            };
        });
    }

    function text(key, fallback) {
        if (settings && settings.strings && settings.strings[key]) {
            return settings.strings[key];
        }

        return fallback;
    }

    function getRunAbilityPath(abilityName) {
        const basePath = settings && settings.abilitiesRunBasePath ? settings.abilitiesRunBasePath : '/wp-abilities/v1/run/';
        return basePath + abilityName;
    }

    function runAbility(abilityName, input) {
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

        return apiFetch(request);
    }

    function getErrorMessage(error) {
        if (error && error.message) {
            return error.message;
        }

        return text('unknownError', 'An unexpected error occurred.');
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

    // Module-level cache so both Sidebar and Toolbar share the latest value within a page session.
    let _lastAdditionalPrompt = settings && settings.lastAdditionalPrompt ? settings.lastAdditionalPrompt : '';

    function getLastAdditionalPrompt() {
        return _lastAdditionalPrompt;
    }

    function saveAdditionalPromptPreference(additionalPrompt) {
        _lastAdditionalPrompt = typeof additionalPrompt === 'string' ? additionalPrompt : '';

        const path = getRunAbilityPath('ai-translate/user-preference');
        const request = {
            path: path,
            method: 'POST',
            data: { input: { additional_prompt: _lastAdditionalPrompt } },
        };

        if (settings && settings.restNonce) {
            request.headers = { 'X-WP-Nonce': settings.restNonce };
        }

        apiFetch(request).catch(function () {});
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
        const [translateTitle, setTranslateTitle] = useState(true);
        const [additionalPrompt, setAdditionalPrompt] = useState(getLastAdditionalPrompt);
        const [isRefreshing, setIsRefreshing] = useState(false);
        const [isTranslating, setIsTranslating] = useState(false);
        const [hasLoadedData, setHasLoadedData] = useState(false);
        const [errorMessage, setErrorMessage] = useState('');
        const [successState, setSuccessState] = useState(null);

        function refreshData() {
            if (!postId) {
                return;
            }

            setIsRefreshing(true);
            setHasLoadedData(false);
            setErrorMessage('');

            Promise.allSettled([
                runAbility('ai-translate/get-languages', {}),
                runAbility('ai-translate/get-translation-status', { post_id: postId }),
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
            refreshData();
        }, [postId]);

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
            setIsTranslating(true);
            setErrorMessage('');
            setSuccessState(null);

            runAbility('ai-translate/translate-content', {
                post_id: postId,
                target_language: targetLanguage,
                post_status: postContext.postStatus || 'draft',
                overwrite: overwrite,
                translate_title: translateTitle,
                additional_prompt: additionalPrompt || undefined,
            })
                .then(function (response) {
                    storeTargetLanguage(targetLanguage);
                    saveAdditionalPromptPreference(additionalPrompt);
                    setSuccessState({
                        message: wasExistingTranslation ? text('translationUpdatedNotice', 'Translation updated successfully.') : text('translationCreatedNotice', 'Translation created successfully.'),
                        editLink: response && response.edit_link ? response.edit_link : '',
                    });
                    refreshData();
                })
                .catch(function (error) {
                    setErrorMessage(getErrorMessage(error));
                })
                .finally(function () {
                    setIsTranslating(false);
                });
        }

        const statusItems = Array.isArray(statusData && statusData.translations) ? statusData.translations : [];

        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'ai-translate-editor-panel',
                title: text('panelTitle', 'AI Translation with SlyTranslate'),
                icon: 'translation',
            },
            sourceLanguage ? createElement('p', null, createElement('strong', null, text('sourceLanguageLabel', 'Source language') + ': '), sourceLanguage) : null,
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
            createElement(
                'div',
                { style: { marginTop: '20px', marginBottom: '20px' } },
                createElement(
                    'div',
                    { style: { marginBottom: '16px' } },
                    createElement(ToggleControl, {
                        label: text('translateTitleLabel', 'Translate title'),
                        checked: translateTitle,
                        onChange: function (value) {
                            setTranslateTitle(!!value);
                        },
                    })
                ),
                createElement(
                    'div',
                    null,
                    createElement(ToggleControl, {
                        label: text('overwriteLabel', 'Overwrite existing translation'),
                        checked: overwrite,
                        onChange: function (value) {
                            setOverwrite(!!value);
                        },
                    })
                ),
                createElement(
                    'div',
                    { style: { marginTop: '8px' } },
                    createElement(TextareaControl, {
                        label: text('additionalPromptLabel', 'Additional instructions (optional)'),
                        help: text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.'),
                        value: additionalPrompt,
                        onChange: setAdditionalPrompt,
                        rows: 3,
                    })
                )
            ),
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
            createElement(
                'div',
                { style: { display: 'grid', rowGap: '10px', marginTop: '4px', marginBottom: '18px' } },
                createElement(Button, {
                    variant: 'primary',
                    onClick: handleTranslate,
                    disabled: !canTranslate,
                    isBusy: isTranslating,
                    style: { width: '100%', justifyContent: 'center' },
                }, text('translateButton', 'Translate now')),
                hasExistingTranslation && !overwrite ? createElement(
                    'div',
                    { style: { marginTop: '10px', marginBottom: '10px' } },
                    createElement(Notice, { status: 'warning', isDismissible: false }, text('existingTranslationNotice', 'A translation already exists for the selected language. Enable overwrite to update it.'))
                ) : null,
                createElement(Button, {
                    variant: 'secondary',
                    onClick: refreshData,
                    disabled: !postId || isRefreshing,
                    style: { width: '100%', justifyContent: 'center' },
                }, text('refreshButton', 'Refresh translation status'))
            ),
            statusItems.length ? createElement(
                Fragment,
                null,
                createElement('p', null, createElement('strong', null, text('translationStatusLabel', 'Translation status'))),
                createElement(
                    'ul',
                    { style: { margin: 0, paddingLeft: '18px' } },
                    statusItems.map(function (translation) {
                        const matchingLanguage = languages.find(function (language) {
                            return language.code === translation.lang;
                        });
                        const languageLabel = matchingLanguage ? matchingLanguage.name + ' (' + translation.lang + ')' : translation.lang;

                        return createElement(
                            'li',
                            { key: translation.lang },
                            languageLabel + ': ' + (translation.exists ? text('translationExists', 'Available') : text('translationMissing', 'Not translated yet')),
                            translation.edit_link ? createElement(Fragment, null, ' ', createElement('a', { href: translation.edit_link }, text('openTranslation', 'Open translation'))) : null
                        );
                    })
                )
            ) : null
        );
    }

    function TranslateSelectionControl(props) {
        const [isOpen, setIsOpen] = useState(false);
        const [selectedText, setSelectedText] = useState('');
        const [selectionValue, setSelectionValue] = useState(null);
        const [selectionStart, setSelectionStart] = useState(0);
        const [selectionEnd, setSelectionEnd] = useState(0);
        const [languages, setLanguages] = useState([]);
        const [sourceLanguage, setSourceLanguage] = useState('');
        const [targetLanguage, setTargetLanguage] = useState('');
        const [errorMessage, setErrorMessage] = useState('');
        const [isTranslating, setIsTranslating] = useState(false);
        const [additionalPrompt, setAdditionalPrompt] = useState(getLastAdditionalPrompt);

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
            const nextSourceLanguage = getDefaultSelectionSourceLanguage(nextLanguages);
            const nextTargetLanguage = resolveSelectionTargetLanguage(nextLanguages, nextSourceLanguage, '');

            setSelectionValue(props.value);
            setSelectionStart(props.value.start);
            setSelectionEnd(props.value.end);
            setSelectedText(nextSelectedText);
            setLanguages(nextLanguages);
            setSourceLanguage(nextSourceLanguage);
            setTargetLanguage(nextTargetLanguage);
            setErrorMessage(nextTargetLanguage ? '' : text('translateSelectionUnavailable', 'No target languages are available for the selected text.'));
            setIsOpen(true);
        }

        function handleTranslateSelection() {
            if (!selectionValue || !selectedText.trim() || !sourceLanguage || !targetLanguage) {
                return;
            }

            setIsTranslating(true);
            setErrorMessage('');

            runAbility('ai-translate/translate-text', {
                text: selectedText,
                source_language: sourceLanguage || 'en',
                target_language: targetLanguage,
                additional_prompt: additionalPrompt || undefined,
            })
                .then(function (response) {
                    const translatedText = response && response.translated_text ? response.translated_text : '';

                    if (!translatedText) {
                        setErrorMessage(text('unknownError', 'An unexpected error occurred.'));
                        return;
                    }

                    storeTargetLanguage(targetLanguage);
                    saveAdditionalPromptPreference(additionalPrompt);
                    props.onChange(insert(selectionValue, translatedText, selectionStart, selectionEnd));
                    closeDialog();
                })
                .catch(function (error) {
                    setErrorMessage(getErrorMessage(error));
                })
                .finally(function () {
                    setIsTranslating(false);
                });
        }

        return createElement(
            Fragment,
            null,
            createElement(RichTextToolbarButton, {
                name: 'unknown',
                icon: 'translation',
                title: text('translateSelectionButton', 'Translate (SlyTranslate)'),
                onClick: openDialog,
                isDisabled: !hasSelection,
            }),
            isOpen && Modal ? createElement(
                Modal,
                {
                    title: text('translateSelectionTitle', 'Translate selected text with SlyTranslate'),
                    onRequestClose: closeDialog,
                    shouldCloseOnClickOutside: !isTranslating,
                    shouldCloseOnEsc: !isTranslating,
                },
                errorMessage ? createElement(Notice, { status: 'error', isDismissible: true, onRemove: function () { setErrorMessage(''); } }, errorMessage) : null,
                languages.length ? createElement(SelectControl, {
                    label: text('sourceLanguageLabel', 'Source language'),
                    value: sourceLanguage,
                    onChange: function (nextSourceLanguage) {
                        setSourceLanguage(nextSourceLanguage);
                        setTargetLanguage(function (currentTargetLanguage) {
                            return resolveSelectionTargetLanguage(languages, nextSourceLanguage, currentTargetLanguage);
                        });
                    },
                    options: languages.map(function (language) {
                        return {
                            label: language.name + ' (' + language.code + ')',
                            value: language.code,
                        };
                    }),
                }) : null,
                availableTargetLanguages.length ? createElement(SelectControl, {
                    label: text('targetLanguageLabel', 'Target language'),
                    value: targetLanguage,
                    onChange: function (nextTargetLanguage) {
                        setTargetLanguage(nextTargetLanguage);
                    },
                    options: availableTargetLanguages.map(function (language) {
                        return {
                            label: language.name + ' (' + language.code + ')',
                            value: language.code,
                        };
                    }),
                }) : null,
                createElement(TextareaControl, {
                    label: text('additionalPromptLabel', 'Additional instructions (optional)'),
                    help: text('additionalPromptHelp', 'Supplements the site-wide translation instructions. Example: Use informal language.'),
                    value: additionalPrompt,
                    onChange: setAdditionalPrompt,
                    rows: 3,
                }),
                selectedText ? createElement(
                    Fragment,
                    null,
                    createElement('p', null, createElement('strong', null, text('translateSelectionTextLabel', 'Selected text'))),
                    createElement('div', {
                        style: {
                            maxHeight: '160px',
                            overflowY: 'auto',
                            padding: '12px',
                            border: '1px solid #dcdcde',
                            borderRadius: '4px',
                            background: '#f6f7f7',
                            whiteSpace: 'pre-wrap',
                        },
                    }, selectedText)
                ) : null,
                createElement(
                    'div',
                    { style: { display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' } },
                    createElement(Button, { variant: 'secondary', onClick: closeDialog, disabled: isTranslating }, text('cancelButton', 'Cancel')),
                    createElement(Button, { variant: 'primary', onClick: handleTranslateSelection, disabled: isTranslating || !sourceLanguage || !targetLanguage || !selectedText.trim(), isBusy: isTranslating }, text('translateSelectionButton', 'Translate with SlyTranslate'))
                )
            ) : null
        );
    }

    if (PluginDocumentSettingPanel && hasTranslationPlugin) {
        registerPlugin('ai-translate-editor-panel', {
            render: AiTranslatePanel,
        });
    }

    if (selectionFeatureAvailable) {
        registerFormatType('ai-translate/translate-selection', {
            title: text('translateSelectionButton', 'Translate with SlyTranslate'),
            tagName: 'span',
            className: 'slytranslate-selection-action',
            edit: TranslateSelectionControl,
        });
    }
})(window.wp, window.aiTranslateEditor);