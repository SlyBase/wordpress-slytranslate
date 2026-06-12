/**
 * SlyTranslate settings page (Settings → SlyTranslate).
 *
 * Thin client over GET/POST ai-translate/v1/settings: all state lives in the
 * backend payload, the page renders it and posts back only changed fields so
 * server-side side effects (direct-API probe on URL change) fire exactly when
 * intended. Built on @wordpress/components for native admin look & feel.
 */
( function ( wp, config ) {
	'use strict';

	if ( ! wp || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;
	var c = wp.components;

	// Fields the form edits; everything else in the payload is diagnostics.
	var EDITABLE_FIELDS = [
		'model_slug',
		'prompt_addon',
		'meta_keys_translate',
		'meta_keys_clear',
		'prompt_template',
		'context_window_tokens',
		'direct_api_url',
		'string_table_concurrency',
	];

	function pickEditable( payload ) {
		var form = {};
		EDITABLE_FIELDS.forEach( function ( key ) {
			form[ key ] = payload[ key ];
		} );
		return form;
	}

	function changedFields( baseline, form ) {
		var changed = {};
		EDITABLE_FIELDS.forEach( function ( key ) {
			if ( String( baseline[ key ] ) !== String( form[ key ] ) ) {
				changed[ key ] = form[ key ];
			}
		} );
		return changed;
	}

	function formatTimestamp( unix ) {
		if ( ! unix ) {
			return '';
		}
		return new Date( unix * 1000 ).toLocaleString();
	}

	function StatusBadge( props ) {
		var ok = props.ok;
		var symbol = ok ? '✓' : '✗';
		return el(
			'span',
			{ style: { marginRight: '24px', color: ok ? '#00753f' : '#b32d2e' } },
			symbol + ' ' + props.label
		);
	}

	function StatusSection( props ) {
		var s = props.settings;
		var modelLabel = s.model_slug || __( 'Connector default', 'slytranslate' );
		return el(
			c.PanelBody,
			{ title: __( 'Status', 'slytranslate' ), initialOpen: true },
			el(
				'p',
				null,
				el( StatusBadge, {
					ok: s.language_plugin_detected,
					label: s.language_plugin_detected
						? sprintf( __( '%s detected', 'slytranslate' ), s.language_plugin_label )
						: __( 'No language plugin detected', 'slytranslate' ),
				} ),
				el( StatusBadge, {
					ok: !! s.detected_seo_plugin,
					label: s.detected_seo_plugin
						? sprintf( __( '%s detected', 'slytranslate' ), s.detected_seo_plugin_label )
						: __( 'No SEO plugin detected', 'slytranslate' ),
				} ),
				el( StatusBadge, {
					ok: s.ai_client_available,
					label: s.ai_client_available
						? __( 'AI client available', 'slytranslate' )
						: __( 'AI client unavailable', 'slytranslate' ),
				} ),
				el(
					'span',
					{ style: { marginRight: '24px' } },
					__( 'Model:', 'slytranslate' ) + ' ' + modelLabel
				)
			),
			s.direct_api_url
				? el(
						'p',
						null,
						el( StatusBadge, {
							ok: s.direct_api_kwargs_supported,
							label: s.direct_api_kwargs_supported
								? __( 'Direct API supports chat_template_kwargs', 'slytranslate' )
								: __( 'Direct API: chat_template_kwargs not detected', 'slytranslate' ),
						} ),
						s.direct_api_kwargs_last_probed_at
							? el(
									'span',
									{ style: { color: '#757575' } },
									sprintf(
										__( 'Last probed: %s', 'slytranslate' ),
										formatTimestamp( s.direct_api_kwargs_last_probed_at )
									)
							  )
							: null
				  )
				: null
		);
	}

	function ModelSection( props ) {
		var options = [
			{ value: '', label: __( 'Connector default', 'slytranslate' ) },
		].concat(
			props.models.map( function ( m ) {
				return { value: m.value, label: m.label };
			} )
		);

		// Keep a saved slug visible even when its connector is offline.
		var known = options.some( function ( o ) {
			return o.value === props.value;
		} );
		if ( ! known && props.value ) {
			options.push( { value: props.value, label: props.value } );
		}

		return el(
			c.PanelBody,
			{ title: __( 'Model', 'slytranslate' ), initialOpen: true },
			el( c.SelectControl, {
				label: __( 'Default model', 'slytranslate' ),
				help: __( 'Site-wide default model for all translations. Individual translations can override it in the editor.', 'slytranslate' ),
				value: props.value,
				options: options,
				onChange: props.onChange,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				c.Button,
				{
					variant: 'secondary',
					isBusy: props.refreshing,
					disabled: props.refreshing,
					onClick: props.onRefresh,
					style: { marginTop: '8px' },
				},
				__( 'Refresh model list', 'slytranslate' )
			)
		);
	}

	function TranslationSection( props ) {
		return el(
			c.PanelBody,
			{ title: __( 'Translation', 'slytranslate' ), initialOpen: true },
			el( c.TextareaControl, {
				label: __( 'Additional instructions (site-wide)', 'slytranslate' ),
				help: __( 'Appended to every translation request. Example: Use informal language.', 'slytranslate' ),
				value: props.promptAddon,
				onChange: props.onChangePromptAddon,
				rows: 3,
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	function MetaSection( props ) {
		var s = props.settings;
		var effective = ( s.effective_meta_keys_translate || [] ).join( ', ' );
		var effectiveClear = ( s.effective_meta_keys_clear || [] ).join( ', ' );
		return el(
			c.PanelBody,
			{ title: __( 'Meta fields', 'slytranslate' ), initialOpen: true },
			s.detected_seo_plugin
				? el(
						'p',
						{ style: { color: '#757575' } },
						sprintf(
							__( 'Detected automatically (%s): translated: %s — cleared: %s', 'slytranslate' ),
							s.detected_seo_plugin_label,
							effective || '—',
							effectiveClear || '—'
						)
				  )
				: null,
			el( c.TextareaControl, {
				label: __( 'Additional keys to translate', 'slytranslate' ),
				help: __( 'Whitespace-separated meta keys translated in addition to the automatically detected ones.', 'slytranslate' ),
				value: props.form.meta_keys_translate,
				onChange: function ( v ) {
					props.onChange( 'meta_keys_translate', v );
				},
				rows: 2,
				__nextHasNoMarginBottom: true,
			} ),
			el( c.TextareaControl, {
				label: __( 'Additional keys to clear', 'slytranslate' ),
				help: __( 'Whitespace-separated meta keys emptied on the translated post.', 'slytranslate' ),
				value: props.form.meta_keys_clear,
				onChange: function ( v ) {
					props.onChange( 'meta_keys_clear', v );
				},
				rows: 2,
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	function ProbeResultTable( props ) {
		var levels = props.result.levels || [];
		if ( ! props.result.supported ) {
			return el(
				c.Notice,
				{ status: 'warning', isDismissible: false },
				__( 'No parallel HTTP transport is available on this server.', 'slytranslate' )
			);
		}
		return el(
			Fragment,
			null,
			el(
				'p',
				null,
				sprintf(
					__( 'Recommended concurrency: %d', 'slytranslate' ),
					props.result.recommended
				)
			),
			levels.length
				? el(
						'table',
						{ className: 'widefat striped', style: { maxWidth: '480px' } },
						el(
							'thead',
							null,
							el(
								'tr',
								null,
								el( 'th', null, __( 'Level', 'slytranslate' ) ),
								el( 'th', null, __( 'Wall time (ms)', 'slytranslate' ) ),
								el( 'th', null, __( 'Speedup', 'slytranslate' ) ),
								el( 'th', null, __( 'Errors', 'slytranslate' ) )
							)
						),
						el(
							'tbody',
							null,
							levels.map( function ( level ) {
								return el(
									'tr',
									{ key: level.level },
									el( 'td', null, String( level.level ) ),
									el( 'td', null, String( level.wall_ms ) ),
									el( 'td', null, String( level.speedup ) ),
									el( 'td', null, String( level.errors ) )
								);
							} )
						)
				  )
				: null
		);
	}

	function AdvancedSection( props ) {
		var s = props.settings;
		var form = props.form;
		var learned = s.learned_context_windows || {};
		var learnedKeys = Object.keys( learned );
		var diagnostics = s.last_transport_diagnostics || {};
		var diagnosticsKeys = Object.keys( diagnostics );

		return el(
			c.PanelBody,
			{ title: __( 'Advanced', 'slytranslate' ), initialOpen: false },
			el( c.TextareaControl, {
				label: __( 'Prompt template', 'slytranslate' ),
				help: __( 'Use {FROM_CODE} and {TO_CODE} as placeholders for the source and target language.', 'slytranslate' ),
				value: form.prompt_template,
				onChange: function ( v ) {
					props.onChange( 'prompt_template', v );
				},
				rows: 4,
				__nextHasNoMarginBottom: true,
			} ),
			el(
				c.Button,
				{
					variant: 'tertiary',
					disabled: form.prompt_template === s.default_prompt_template,
					onClick: function () {
						props.onChange( 'prompt_template', s.default_prompt_template );
					},
				},
				__( 'Reset to default', 'slytranslate' )
			),
			el( c.TextControl, {
				label: __( 'Context window override (tokens)', 'slytranslate' ),
				help:
					__( '0 = automatic detection.', 'slytranslate' ) +
					( learnedKeys.length
						? ' ' +
						  sprintf(
								__( 'Learned values: %s', 'slytranslate' ),
								learnedKeys
									.map( function ( key ) {
										return key + ': ' + learned[ key ];
									} )
									.join( ', ' )
						  )
						: '' ),
				type: 'number',
				min: 0,
				value: String( form.context_window_tokens ),
				onChange: function ( v ) {
					props.onChange( 'context_window_tokens', parseInt( v, 10 ) || 0 );
				},
				style: { maxWidth: '200px', marginTop: '16px' },
				__nextHasNoMarginBottom: true,
			} ),
			el( c.TextControl, {
				label: __( 'Direct API URL', 'slytranslate' ),
				help: __( 'Base URL of an optional OpenAI-compatible server. Used only by models that require direct API handling (e.g. TranslateGemma). Saving probes the endpoint.', 'slytranslate' ),
				value: form.direct_api_url,
				onChange: function ( v ) {
					props.onChange( 'direct_api_url', v );
				},
				style: { marginTop: '16px' },
				__nextHasNoMarginBottom: true,
			} ),
			s.is_string_table_adapter
				? el(
						Fragment,
						null,
						el( c.RangeControl, {
							label: __( 'String-table concurrency', 'slytranslate' ),
							help: __( 'Maximum parallel batches for string-table translations. Values above 1 only activate after a successful probe.', 'slytranslate' ),
							min: 1,
							max: 4,
							value: form.string_table_concurrency,
							onChange: function ( v ) {
								props.onChange( 'string_table_concurrency', v );
							},
							style: { maxWidth: '320px', marginTop: '16px' },
							__nextHasNoMarginBottom: true,
						} ),
						el(
							c.Button,
							{
								variant: 'secondary',
								isBusy: props.probing,
								disabled: props.probing,
								onClick: props.onProbe,
							},
							__( 'Test concurrency', 'slytranslate' )
						),
						props.probeResult
							? el( ProbeResultTable, { result: props.probeResult } )
							: null
				  )
				: null,
			diagnosticsKeys.length
				? el(
						Fragment,
						null,
						el( 'h4', null, __( 'Transport diagnostics', 'slytranslate' ) ),
						el(
							'ul',
							{ style: { color: '#757575' } },
							diagnosticsKeys.map( function ( key ) {
								return el(
									'li',
									{ key: key },
									key + ': ' + String( diagnostics[ key ] )
								);
							} )
						)
				  )
				: null
		);
	}

	function SettingsApp() {
		var settingsState = useState( null );
		var settings = settingsState[ 0 ];
		var setSettings = settingsState[ 1 ];

		var formState = useState( null );
		var form = formState[ 0 ];
		var setForm = formState[ 1 ];

		var modelsState = useState( [] );
		var models = modelsState[ 0 ];
		var setModels = modelsState[ 1 ];

		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];

		var refreshingState = useState( false );
		var refreshing = refreshingState[ 0 ];
		var setRefreshing = refreshingState[ 1 ];

		var probingState = useState( false );
		var probing = probingState[ 0 ];
		var setProbing = probingState[ 1 ];

		var probeResultState = useState( null );
		var probeResult = probeResultState[ 0 ];
		var setProbeResult = probeResultState[ 1 ];

		var snackbarState = useState( '' );
		var snackbar = snackbarState[ 0 ];
		var setSnackbar = snackbarState[ 1 ];

		function applyPayload( payload ) {
			setSettings( payload );
			setForm( pickEditable( payload ) );
		}

		function loadModels( refresh ) {
			return apiFetch( {
				path: config.modelsPath,
				method: 'POST',
				data: { input: { refresh: !! refresh } },
			} ).then( function ( response ) {
				setModels( ( response && response.models ) || [] );
			} );
		}

		useEffect( function () {
			Promise.all( [
				apiFetch( { path: config.settingsPath } ),
				loadModels( false ),
			] )
				.then( function ( results ) {
					applyPayload( results[ 0 ] );
				} )
				.catch( function ( err ) {
					setError( ( err && err.message ) || __( 'Loading settings failed.', 'slytranslate' ) );
				} );
		}, [] );

		if ( error && ! settings ) {
			return el( c.Notice, { status: 'error', isDismissible: false }, error );
		}

		if ( ! settings || ! form ) {
			return el( c.Spinner, null );
		}

		var dirty = changedFields( pickEditable( settings ), form );
		var hasChanges = Object.keys( dirty ).length > 0;

		function onChangeField( key, value ) {
			var next = {};
			Object.keys( form ).forEach( function ( existing ) {
				next[ existing ] = form[ existing ];
			} );
			next[ key ] = value;
			setForm( next );
		}

		function onSave() {
			setSaving( true );
			setError( '' );
			apiFetch( {
				path: config.settingsPath,
				method: 'POST',
				data: dirty,
			} )
				.then( function ( payload ) {
					applyPayload( payload );
					setSnackbar( __( 'Settings saved.', 'slytranslate' ) );
				} )
				.catch( function ( err ) {
					setError( ( err && err.message ) || __( 'Saving failed.', 'slytranslate' ) );
				} )
				.finally( function () {
					setSaving( false );
				} );
		}

		function onRefreshModels() {
			setRefreshing( true );
			loadModels( true ).finally( function () {
				setRefreshing( false );
			} );
		}

		function onProbe() {
			setProbing( true );
			setProbeResult( null );
			apiFetch( {
				path: config.probeConcurrencyPath,
				method: 'POST',
				data: { model_slug: form.model_slug },
			} )
				.then( setProbeResult )
				.catch( function ( err ) {
					setError( ( err && err.message ) || __( 'Concurrency probe failed.', 'slytranslate' ) );
				} )
				.finally( function () {
					setProbing( false );
				} );
		}

		return el(
			Fragment,
			null,
			el( 'h1', null, 'SlyTranslate' ),
			error ? el( c.Notice, { status: 'error', onRemove: function () { setError( '' ); } }, error ) : null,
			el(
				c.Panel,
				null,
				el( StatusSection, { settings: settings } ),
				el( ModelSection, {
					models: models,
					value: form.model_slug,
					refreshing: refreshing,
					onChange: function ( v ) {
						onChangeField( 'model_slug', v );
					},
					onRefresh: onRefreshModels,
				} ),
				el( TranslationSection, {
					promptAddon: form.prompt_addon,
					onChangePromptAddon: function ( v ) {
						onChangeField( 'prompt_addon', v );
					},
				} ),
				el( MetaSection, {
					settings: settings,
					form: form,
					onChange: onChangeField,
				} ),
				el( AdvancedSection, {
					settings: settings,
					form: form,
					onChange: onChangeField,
					probing: probing,
					probeResult: probeResult,
					onProbe: onProbe,
				} )
			),
			el(
				'p',
				{ style: { marginTop: '16px' } },
				el(
					c.Button,
					{
						variant: 'primary',
						isBusy: saving,
						disabled: saving || ! hasChanges,
						onClick: onSave,
					},
					__( 'Save settings', 'slytranslate' )
				)
			),
			snackbar
				? el(
						c.Snackbar,
						{
							onRemove: function () {
								setSnackbar( '' );
							},
						},
						snackbar
				  )
				: null
		);
	}

	function mount() {
		var root = document.getElementById( 'slytranslate-settings-root' );
		if ( ! root ) {
			return;
		}
		if ( wp.element.createRoot ) {
			wp.element.createRoot( root ).render( el( SettingsApp ) );
		} else {
			wp.element.render( el( SettingsApp ), root );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', mount );
	} else {
		mount();
	}
} )( window.wp, window.slyTranslateSettings );
