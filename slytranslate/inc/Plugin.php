<?php

namespace AI_Translate;

defined( 'ABSPATH' ) || exit;

/**
 * Central plugin-wide constants.
 *
 * All other classes must reference Plugin::VERSION, Plugin::REST_NAMESPACE,
 * and Plugin::EDITOR_SCRIPT instead of duplicating the strings.
 */
final class Plugin {
	public const VERSION        = '1.5.3';
	public const REST_NAMESPACE = 'ai-translate/v1';
	public const EDITOR_SCRIPT  = 'ai-translate-editor';
}
