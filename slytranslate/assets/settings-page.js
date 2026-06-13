/**
 * SlyTranslate settings page (Settings → SlyTranslate).
 *
 * Small wp.element app without a build step, mirroring the other plugin
 * assets. All reads and writes go through the ai-translate/configure REST
 * bridge so the UI shares one backend with the MCP configure ability —
 * including the direct-API probe side effects on save.
 */
(function () {
    'use strict';

    var wp = window.wp;
    if (!wp || !wp.element || !wp.components || !wp.apiFetch) {
        return;
    }

    var bootstrap = window.slyTranslateSettings || {};
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var apiFetch = wp.apiFetch;
    // Strings are translated server-side and passed via wp_localize_script
    // (same pattern as editor-plugin.js) — no JS translation files needed.
    var coreI18n = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (text) { return text; };
    var localizedStrings = bootstrap.strings || {};
    var __ = function (text) {
        return localizedStrings[text] || coreI18n(text, 'slytranslate');
    };
    var components = wp.components;
    var Button = components.Button;
    var CheckboxControl = components.CheckboxControl;
    var Notice = components.Notice;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var Snackbar = components.Snackbar;
    var Spinner = components.Spinner;
    var TextControl = components.TextControl;
    var TextareaControl = components.TextareaControl;
    var ToggleControl = components.ToggleControl;

    var BASE = bootstrap.abilitiesRunBasePath || '/ai-translate/v1/';

    function callAbility(name, input) {
        return apiFetch({
            path: BASE + 'ai-translate/' + name + '/run',
            method: 'POST',
            data: { input: input || {} },
        });
    }

    function splitKeys(value) {
        return String(value || '').split(/\s+/).filter(function (key) { return key !== ''; });
    }

    /* ---------------------------------------------------------------
     * Glossary helpers: option entries ↔ editable rows
     * ------------------------------------------------------------- */

    function glossaryEntriesToRows(entries) {
        return (entries || []).map(function (entry) {
            var toText = Object.keys(entry.to || {}).map(function (code) {
                return code + '=' + entry.to[code];
            }).join(', ');
            return { term: entry.term || '', mode: entry.mode || 'keep', toText: toText };
        });
    }

    function glossaryRowsToEntries(rows) {
        return rows.map(function (row) {
            var to = {};
            String(row.toText || '').split(/[,;]/).forEach(function (pair) {
                var idx = pair.indexOf('=');
                if (idx > 0) {
                    var code = pair.slice(0, idx).trim().toLowerCase();
                    var translation = pair.slice(idx + 1).trim();
                    if (code && translation) {
                        to[code] = translation;
                    }
                }
            });
            return { term: String(row.term || '').trim(), mode: row.mode === 'translate' ? 'translate' : 'keep', to: to };
        }).filter(function (entry) {
            return entry.term !== '' && (entry.mode === 'keep' || Object.keys(entry.to).length > 0);
        });
    }

    /* ---------------------------------------------------------------
     * Sections
     * ------------------------------------------------------------- */

    function StatusRow(props) {
        var ok = props.ok;
        return el('span', { style: { marginRight: '24px', display: 'inline-block' } },
            el('span', { style: { color: ok ? '#00a32a' : '#996800', marginRight: '4px' } }, ok ? '✓' : '—'),
            props.label
        );
    }

    function sourceLabel(field) {
        if (field.source === 'acf') {
            var detail = field.field_label || field.key;
            return 'ACF: ' + detail + (field.field_type ? ' (' + field.field_type + ')' : '');
        }
        if (field.source === 'seo') {
            return __('SEO plugin', 'slytranslate');
        }
        if (field.source === 'default') {
            return __('Built-in default', 'slytranslate');
        }
        if (field.source === 'filter') {
            return __('Filter API', 'slytranslate');
        }
        return __('Manual key', 'slytranslate');
    }

    function SettingsApp() {
        var settingsState = useState(null);
        var settings = settingsState[0];
        var setSettings = settingsState[1];

        var modelsState = useState([]);
        var models = modelsState[0];
        var setModels = modelsState[1];

        var fieldsState = useState(null);
        var fieldsReport = fieldsState[0];
        var setFieldsReport = fieldsState[1];

        var excludeState = useState([]);
        var excludedKeys = excludeState[0];
        var setExcludedKeys = excludeState[1];

        var glossaryState = useState([]);
        var glossaryRows = glossaryState[0];
        var setGlossaryRows = glossaryState[1];

        var previewPostState = useState('');
        var previewPostId = previewPostState[0];
        var setPreviewPostId = previewPostState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var noticeState = useState(null);
        var notice = noticeState[0];
        var setNotice = noticeState[1];

        var snackbarState = useState('');
        var snackbar = snackbarState[0];
        var setSnackbar = snackbarState[1];

        var probeState = useState(null);
        var probeResult = probeState[0];
        var setProbeResult = probeState[1];

        function applyPayload(payload) {
            setSettings(payload);
            setExcludedKeys(splitKeys(payload.meta_keys_exclude));
            setGlossaryRows(glossaryEntriesToRows(payload.glossary));
        }

        function loadFieldsReport(postId) {
            return callAbility('get-translatable-fields', postId > 0 ? { post_id: postId } : {})
                .then(setFieldsReport)
                .catch(function () { setFieldsReport(null); });
        }

        useEffect(function () {
            callAbility('configure', {})
                .then(applyPayload)
                .catch(function (error) {
                    setNotice((error && error.message) || __('Failed to load settings.', 'slytranslate'));
                });
            callAbility('get-available-models', {})
                .then(function (response) { setModels(response.models || []); })
                .catch(function () { setModels([]); });
            loadFieldsReport(0);
        }, []);

        function updateSetting(key, value) {
            var next = {};
            Object.keys(settings).forEach(function (k) { next[k] = settings[k]; });
            next[key] = value;
            setSettings(next);
        }

        function toggleExclusion(key, active) {
            setExcludedKeys(function (current) {
                var without = current.filter(function (k) { return k !== key; });
                return active ? without : without.concat([key]);
            });
        }

        function save() {
            setBusy(true);
            setNotice(null);
            callAbility('configure', {
                model_slug: settings.model_slug,
                prompt_template: settings.prompt_template,
                prompt_addon: settings.prompt_addon,
                meta_keys_translate: settings.meta_keys_translate,
                meta_keys_clear: settings.meta_keys_clear,
                meta_keys_exclude: excludedKeys.join(' '),
                auto_translate_new: !!settings.auto_translate_new,
                translate_terms: !!settings.translate_terms,
                translate_slugs: !!settings.translate_slugs,
                glossary: glossaryRowsToEntries(glossaryRows),
                context_window_tokens: parseInt(settings.context_window_tokens, 10) || 0,
                direct_api_url: settings.direct_api_url,
                string_table_concurrency: parseInt(settings.string_table_concurrency, 10) || 1,
            }).then(function (payload) {
                applyPayload(payload);
                setSnackbar(__('Settings saved.', 'slytranslate'));
                window.setTimeout(function () { setSnackbar(''); }, 4000);
                return loadFieldsReport(parseInt(previewPostId, 10) || 0);
            }).catch(function (error) {
                setNotice((error && error.message) || __('Saving failed.', 'slytranslate'));
            }).then(function () {
                setBusy(false);
            });
        }

        function refreshModels() {
            setBusy(true);
            callAbility('get-available-models', { refresh: true })
                .then(function (response) { setModels(response.models || []); })
                .catch(function () { })
                .then(function () { setBusy(false); });
        }

        function probeConcurrency() {
            setBusy(true);
            setProbeResult(null);
            callAbility('probe-string-table-concurrency', {})
                .then(setProbeResult)
                .catch(function (error) {
                    setNotice((error && error.message) || __('Concurrency probe failed.', 'slytranslate'));
                })
                .then(function () { setBusy(false); });
        }

        if (!settings) {
            return el('div', null,
                notice ? el(Notice, { status: 'error', isDismissible: false }, notice) : el(Spinner, null)
            );
        }

        var modelOptions = [{ label: __('Connector default', 'slytranslate'), value: '' }].concat(
            models.map(function (model) { return { label: model.label, value: model.value }; })
        );
        // Keep an unknown configured slug selectable instead of silently jumping.
        if (settings.model_slug && !models.some(function (m) { return m.value === settings.model_slug; })) {
            modelOptions.push({ label: settings.model_slug, value: settings.model_slug });
        }

        var translateFields = (fieldsReport && fieldsReport.fields || []).filter(function (f) { return f.action === 'translate'; });
        var clearFields = (fieldsReport && fieldsReport.fields || []).filter(function (f) { return f.action === 'clear'; });

        return el(Fragment, null,
            notice ? el(Notice, {
                status: 'error',
                onRemove: function () { setNotice(null); },
            }, notice) : null,

            /* ---- Status ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '12px 16px', margin: '16px 0' } },
                el('h2', { style: { marginTop: 0 } }, __('Status', 'slytranslate')),
                el('div', null,
                    el(StatusRow, {
                        ok: !!bootstrap.languagePlugin,
                        label: bootstrap.languagePlugin
                            ? bootstrap.languagePlugin + ' ' + __('detected', 'slytranslate')
                            : __('No language plugin detected', 'slytranslate'),
                    }),
                    el(StatusRow, {
                        ok: !!settings.detected_seo_plugin,
                        label: settings.detected_seo_plugin
                            ? (settings.detected_seo_plugin_label || settings.detected_seo_plugin) + ' ' + __('detected', 'slytranslate')
                            : __('No SEO plugin detected', 'slytranslate'),
                    })
                ),
                el('div', null,
                    el(StatusRow, {
                        ok: !!bootstrap.serverTranslationAvailable,
                        label: bootstrap.serverTranslationAvailable
                            ? __('AI client available', 'slytranslate')
                            : __('AI client missing — install an AI connector for in-WordPress translation', 'slytranslate'),
                    }),
                    el(StatusRow, {
                        ok: true,
                        label: __('Model', 'slytranslate') + ': ' + (settings.model_slug || __('Connector default', 'slytranslate')),
                    })
                ),
                (bootstrap.fieldPlugins || []).length > 0 ? el('div', null,
                    el(StatusRow, {
                        ok: true,
                        label: __('Field plugins', 'slytranslate') + ': ' + bootstrap.fieldPlugins.join(', '),
                    })
                ) : null,
                settings.direct_api_url ? el('div', null,
                    el(StatusRow, {
                        ok: !!settings.direct_api_kwargs_supported,
                        label: settings.direct_api_kwargs_supported
                            ? __('Direct API: chat_template_kwargs supported', 'slytranslate')
                            : __('Direct API: chat_template_kwargs not detected', 'slytranslate'),
                    })
                ) : null
            ),

            /* ---- Model ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '12px 16px', margin: '16px 0' } },
                el('h2', { style: { marginTop: 0 } }, __('Model', 'slytranslate')),
                el(SelectControl, {
                    label: __('Default model', 'slytranslate'),
                    value: settings.model_slug || '',
                    options: modelOptions,
                    onChange: function (value) { updateSetting('model_slug', value); },
                    __nextHasNoMarginBottom: true,
                }),
                el(Button, { variant: 'secondary', onClick: refreshModels, disabled: busy, style: { marginTop: '8px' } },
                    __('Refresh model list', 'slytranslate'))
            ),

            /* ---- Translation ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '12px 16px', margin: '16px 0' } },
                el('h2', { style: { marginTop: 0 } }, __('Translation', 'slytranslate')),
                el(TextareaControl, {
                    label: __('Additional instructions (site-wide)', 'slytranslate'),
                    help: __('Appended to every translation request, e.g. tone or brand wording.', 'slytranslate'),
                    value: settings.prompt_addon || '',
                    onChange: function (value) { updateSetting('prompt_addon', value); },
                    rows: 3,
                    __nextHasNoMarginBottom: true,
                }),
                el('h3', null, __('Glossary / do not translate', 'slytranslate')),
                glossaryRows.map(function (row, index) {
                    return el('div', { key: 'glossary-' + index, style: { display: 'flex', gap: '8px', alignItems: 'flex-end', marginBottom: '8px' } },
                        el(TextControl, {
                            label: index === 0 ? __('Term', 'slytranslate') : null,
                            value: row.term,
                            onChange: function (value) {
                                var rows = glossaryRows.slice();
                                rows[index] = { term: value, mode: row.mode, toText: row.toText };
                                setGlossaryRows(rows);
                            },
                            __nextHasNoMarginBottom: true,
                        }),
                        el(SelectControl, {
                            label: index === 0 ? __('Mode', 'slytranslate') : null,
                            value: row.mode,
                            options: [
                                { label: __('Keep unchanged', 'slytranslate'), value: 'keep' },
                                { label: __('Fixed translation', 'slytranslate'), value: 'translate' },
                            ],
                            onChange: function (value) {
                                var rows = glossaryRows.slice();
                                rows[index] = { term: row.term, mode: value, toText: row.toText };
                                setGlossaryRows(rows);
                            },
                            __nextHasNoMarginBottom: true,
                        }),
                        row.mode === 'translate' ? el(TextControl, {
                            label: index === 0 ? __('Translations (en=…, fr=…)', 'slytranslate') : null,
                            value: row.toText,
                            onChange: function (value) {
                                var rows = glossaryRows.slice();
                                rows[index] = { term: row.term, mode: row.mode, toText: value };
                                setGlossaryRows(rows);
                            },
                            __nextHasNoMarginBottom: true,
                        }) : null,
                        el(Button, {
                            variant: 'tertiary',
                            isDestructive: true,
                            onClick: function () {
                                setGlossaryRows(glossaryRows.filter(function (unused, i) { return i !== index; }));
                            },
                        }, __('Remove', 'slytranslate'))
                    );
                }),
                el(Button, {
                    variant: 'secondary',
                    onClick: function () {
                        setGlossaryRows(glossaryRows.concat([{ term: '', mode: 'keep', toText: '' }]));
                    },
                }, __('Add glossary entry', 'slytranslate'))
            ),

            /* ---- Meta fields ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '12px 16px', margin: '16px 0' } },
                el('h2', { style: { marginTop: 0 } }, __('Meta fields', 'slytranslate')),
                el('p', { className: 'description' },
                    __('Detected automatically from your SEO and field plugins. Unchecking a field excludes it from translation; the filter API can still override this.', 'slytranslate')),
                translateFields.length === 0
                    ? el('p', null, __('No translatable meta fields detected for this context.', 'slytranslate'))
                    : translateFields.map(function (field) {
                        var isExcluded = excludedKeys.indexOf(field.key) !== -1;
                        return el(CheckboxControl, {
                            key: 'field-' + field.key,
                            label: field.key,
                            help: sourceLabel(field),
                            checked: !isExcluded,
                            onChange: function (checked) { toggleExclusion(field.key, checked); },
                            __nextHasNoMarginBottom: true,
                        });
                    }),
                clearFields.length > 0 ? el(Fragment, null,
                    el('h3', null, __('Cleared on translation', 'slytranslate')),
                    el('p', { className: 'description' },
                        clearFields.map(function (f) { return f.key; }).join(', '))
                ) : null,
                el('h3', null, __('Additional keys', 'slytranslate')),
                el(TextControl, {
                    label: __('Translate (space-separated meta keys)', 'slytranslate'),
                    value: settings.meta_keys_translate || '',
                    onChange: function (value) { updateSetting('meta_keys_translate', value); },
                    __nextHasNoMarginBottom: true,
                }),
                el(TextControl, {
                    label: __('Clear (space-separated meta keys)', 'slytranslate'),
                    value: settings.meta_keys_clear || '',
                    onChange: function (value) { updateSetting('meta_keys_clear', value); },
                    __nextHasNoMarginBottom: true,
                }),
                el('div', { style: { display: 'flex', gap: '8px', alignItems: 'flex-end', marginTop: '12px' } },
                    el(TextControl, {
                        label: __('Preview for post ID', 'slytranslate'),
                        type: 'number',
                        value: previewPostId,
                        onChange: setPreviewPostId,
                        __nextHasNoMarginBottom: true,
                    }),
                    el(Button, {
                        variant: 'secondary',
                        disabled: busy,
                        onClick: function () { loadFieldsReport(parseInt(previewPostId, 10) || 0); },
                    }, __('Load preview', 'slytranslate'))
                ),
                fieldsReport && fieldsReport.post_id > 0 ? el('p', { className: 'description' },
                    __('Showing resolution for post', 'slytranslate') + ' #' + fieldsReport.post_id) : null
            ),

            /* ---- Automation ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '12px 16px', margin: '16px 0' } },
                el('h2', { style: { marginTop: 0 } }, __('Automation', 'slytranslate')),
                el(ToggleControl, {
                    label: __('Automatically translate new posts on publish (as draft)', 'slytranslate'),
                    checked: !!settings.auto_translate_new,
                    onChange: function (value) { updateSetting('auto_translate_new', value); },
                    __nextHasNoMarginBottom: true,
                }),
                el(ToggleControl, {
                    label: __('Translate taxonomy terms alongside content', 'slytranslate'),
                    checked: !!settings.translate_terms,
                    onChange: function (value) { updateSetting('translate_terms', value); },
                    __nextHasNoMarginBottom: true,
                }),
                el(ToggleControl, {
                    label: __('Translate post slugs', 'slytranslate'),
                    checked: !!settings.translate_slugs,
                    onChange: function (value) { updateSetting('translate_slugs', value); },
                    __nextHasNoMarginBottom: true,
                })
            ),

            /* ---- Advanced (collapsed) ---- */
            el('div', { className: 'card', style: { maxWidth: '720px', padding: '0', margin: '16px 0' } },
                el(PanelBody, { title: __('Advanced', 'slytranslate'), initialOpen: false },
                    el(TextareaControl, {
                        label: __('Prompt template', 'slytranslate'),
                        help: __('Placeholders: {FROM_CODE} and {TO_CODE}.', 'slytranslate'),
                        value: settings.prompt_template || '',
                        onChange: function (value) { updateSetting('prompt_template', value); },
                        rows: 4,
                        __nextHasNoMarginBottom: true,
                    }),
                    el(Button, {
                        variant: 'tertiary',
                        onClick: function () { updateSetting('prompt_template', bootstrap.defaultPromptTemplate || ''); },
                        style: { marginBottom: '16px' },
                    }, __('Reset to default', 'slytranslate')),
                    el(TextControl, {
                        label: __('Context window override (tokens, 0 = automatic)', 'slytranslate'),
                        type: 'number',
                        help: __('Effective:', 'slytranslate') + ' ' + (settings.effective_context_window_tokens || 0)
                            + ' ' + __('tokens', 'slytranslate')
                            + ' · ' + __('learned:', 'slytranslate') + ' ' + (settings.learned_context_window_tokens || 0)
                            + ' · ' + __('chunk size:', 'slytranslate') + ' ' + (settings.effective_chunk_chars || 0),
                        value: String(settings.context_window_tokens || 0),
                        onChange: function (value) { updateSetting('context_window_tokens', value); },
                        __nextHasNoMarginBottom: true,
                    }),
                    el(TextControl, {
                        label: __('Direct API URL (OpenAI-compatible, optional)', 'slytranslate'),
                        help: settings.direct_api_url
                            ? (settings.direct_api_kwargs_supported
                                ? __('Probe: chat_template_kwargs supported.', 'slytranslate')
                                : __('Probe: chat_template_kwargs not detected.', 'slytranslate'))
                            : __('Only used by model profiles that require a direct endpoint (e.g. TranslateGemma). Saving runs a capability probe.', 'slytranslate'),
                        value: settings.direct_api_url || '',
                        onChange: function (value) { updateSetting('direct_api_url', value); },
                        __nextHasNoMarginBottom: true,
                    }),
                    bootstrap.isStringTableAdapter ? el(Fragment, null,
                        el(SelectControl, {
                            label: __('String-table concurrency (TranslatePress batches)', 'slytranslate'),
                            help: __('Effective:', 'slytranslate') + ' ' + (settings.string_table_concurrency_effective || 1)
                                + ' · ' + __('recommended:', 'slytranslate') + ' ' + (settings.string_table_concurrency_recommended || 1),
                            value: String(settings.string_table_concurrency || 1),
                            options: ['1', '2', '3', '4'].map(function (n) { return { label: n, value: n }; }),
                            onChange: function (value) { updateSetting('string_table_concurrency', value); },
                            __nextHasNoMarginBottom: true,
                        }),
                        el(Button, { variant: 'secondary', onClick: probeConcurrency, disabled: busy, style: { margin: '8px 0' } },
                            __('Test concurrency', 'slytranslate')),
                        probeResult ? el('table', { className: 'widefat striped', style: { marginBottom: '16px' } },
                            el('thead', null, el('tr', null,
                                el('th', null, __('Level', 'slytranslate')),
                                el('th', null, __('Wall time (ms)', 'slytranslate')),
                                el('th', null, __('Speedup', 'slytranslate')),
                                el('th', null, __('Errors', 'slytranslate'))
                            )),
                            el('tbody', null,
                                (probeResult.levels || []).map(function (level) {
                                    return el('tr', { key: 'level-' + level.level },
                                        el('td', null, String(level.level)),
                                        el('td', null, String(level.wall_ms)),
                                        el('td', null, String(level.speedup)),
                                        el('td', null, String(level.errors))
                                    );
                                }),
                                (probeResult.levels || []).length === 0 ? el('tr', null,
                                    el('td', { colSpan: 4 }, probeResult.reason || __('No parallel transport available.', 'slytranslate'))
                                ) : null
                            )
                        ) : null,
                        probeResult ? el('p', { className: 'description' },
                            __('Recommended concurrency:', 'slytranslate') + ' ' + probeResult.recommended) : null
                    ) : null,
                    settings.last_transport_diagnostics ? el(Fragment, null,
                        el('h3', null, __('Transport diagnostics', 'slytranslate')),
                        el('pre', {
                            style: { background: '#f6f7f7', padding: '8px', overflow: 'auto', fontSize: '12px' },
                        }, JSON.stringify(settings.last_transport_diagnostics, null, 2))
                    ) : null
                )
            ),

            /* ---- Save ---- */
            el('div', { style: { maxWidth: '720px', margin: '16px 0' } },
                el(Button, { variant: 'primary', onClick: save, isBusy: busy, disabled: busy },
                    __('Save settings', 'slytranslate'))
            ),

            snackbar ? el('div', { style: { position: 'fixed', bottom: '24px', left: '50%', transform: 'translateX(-50%)', zIndex: 100000 } },
                el(Snackbar, null, snackbar)
            ) : null
        );
    }

    function mount() {
        var root = document.getElementById('slytranslate-settings-root');
        if (!root) {
            return;
        }
        root.innerHTML = '<h1>SlyTranslate</h1>';
        var appContainer = document.createElement('div');
        root.appendChild(appContainer);

        if (wp.element.createRoot) {
            wp.element.createRoot(appContainer).render(el(SettingsApp, null));
        } else {
            wp.element.render(el(SettingsApp, null), appContainer);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
