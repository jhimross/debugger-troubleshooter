<?php
/**
 * Early bootstrap for Live Debug and the Performance Profiler.
 *
 * This file is included by the "debugger-troubleshooter-early.php" MU plugin so it
 * runs before regular plugins load, and again by the main plugin file as a fallback
 * when the MU plugin could not be installed. It only runs once per request.
 *
 * Why this exists: WordPress defines WP_DEBUG itself (in wp_initial_constants())
 * before any plugin loads, so a plugin can never define it. Instead, Live Debug
 * applies the equivalent PHP runtime settings directly.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

if (defined('DBGTBL_EARLY_LOADED')) {
	return;
}

define('DBGTBL_EARLY_LOADED', microtime(true));

require_once __DIR__ . '/class-dbgtbl-components.php';

if (!function_exists('dbgtbl_live_debug_log_path')) {
	/**
	 * Returns the log file Live Debug writes to.
	 *
	 * Respects a custom path set via WP_DEBUG_LOG in wp-config.php.
	 *
	 * @return string
	 */
	function dbgtbl_live_debug_log_path()
	{
		if (defined('WP_DEBUG_LOG') && is_string(WP_DEBUG_LOG) && !in_array(strtolower(WP_DEBUG_LOG), array('true', '1', ''), true)) {
			return WP_DEBUG_LOG;
		}

		return WP_CONTENT_DIR . '/debug.log';
	}
}

if (!function_exists('dbgtbl_core_debug_logging_active')) {
	/**
	 * Whether wp-config.php already enables WordPress debug logging.
	 *
	 * @return bool
	 */
	function dbgtbl_core_debug_logging_active()
	{
		return defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;
	}
}

if (!function_exists('dbgtbl_log_wp_notice')) {
	/**
	 * Writes a WordPress deprecation / doing-it-wrong notice to the error log in the
	 * same format PHP uses, so the Log Viewer can parse and attribute it.
	 *
	 * @param string $level   PHP level label, e.g. "Deprecated" or "Notice".
	 * @param string $message Message text (may contain HTML).
	 */
	function dbgtbl_log_wp_notice($level, $message)
	{
		$message = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($message)));
		$frame = DBGTBL_Components::caller_frame();

		if ($frame) {
			$message .= sprintf(' in %s on line %d', $frame['file'], $frame['line']);
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Live Debug intentionally writes to the error log.
		error_log(sprintf('PHP %s:  %s', $level, $message));
	}
}

/*
 * ---------------------------------------------------------------------------
 * Live Debug.
 * ---------------------------------------------------------------------------
 */
if ('enabled' === get_option('wp_debug_troubleshoot_debug_mode', 'disabled')) {
	define('DBGTBL_LIVE_DEBUG_ACTIVE', true);

	// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, WordPress.PHP.IniSet.display_errors_Disallowed, WordPress.PHP.IniSet.log_errors_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged, PluginCheck.CodeAnalysis.PHPErrorReporting
	// Live Debug's purpose is to enable error reporting and logging without editing wp-config.php.
	error_reporting(E_ALL);

	if (!dbgtbl_core_debug_logging_active()) {
		@ini_set('log_errors', 1);
		@ini_set('error_log', dbgtbl_live_debug_log_path());
	}

	// Never show errors to visitors, unless the site owner explicitly enabled display in wp-config.php.
	if (!(defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY)) {
		@ini_set('display_errors', 0);
	}
	// phpcs:enable

	// WordPress only reports its own deprecation / misuse notices when WP_DEBUG is true.
	// When it is not, log them ourselves so Live Debug catches them too.
	if (!(defined('WP_DEBUG') && WP_DEBUG)) {
		add_action('deprecated_function_run', function ($function_name, $replacement, $version) {
			$msg = $replacement
				? sprintf('Function %1$s is deprecated since version %2$s! Use %3$s instead.', $function_name, $version, $replacement)
				: sprintf('Function %1$s is deprecated since version %2$s with no alternative available.', $function_name, $version);
			dbgtbl_log_wp_notice('Deprecated', $msg);
		}, 10, 3);

		add_action('deprecated_constructor_run', function ($class_name, $version, $parent_class) {
			$msg = $parent_class
				? sprintf('The called constructor method for %1$s class in %2$s is deprecated since version %3$s! Use __construct() instead.', $class_name, $parent_class, $version)
				: sprintf('The called constructor method for %1$s class is deprecated since version %2$s! Use __construct() instead.', $class_name, $version);
			dbgtbl_log_wp_notice('Deprecated', $msg);
		}, 10, 3);

		add_action('deprecated_file_included', function ($file, $replacement, $version, $message) {
			$msg = $replacement
				? sprintf('File %1$s is deprecated since version %2$s! Use %3$s instead.', $file, $version, $replacement)
				: sprintf('File %1$s is deprecated since version %2$s with no alternative available.', $file, $version);
			dbgtbl_log_wp_notice('Deprecated', trim($msg . ' ' . $message));
		}, 10, 4);

		add_action('deprecated_argument_run', function ($function_name, $message, $version) {
			$msg = sprintf('Function %1$s was called with an argument that is deprecated since version %2$s!', $function_name, $version);
			dbgtbl_log_wp_notice('Deprecated', trim($msg . ' ' . $message));
		}, 10, 3);

		add_action('deprecated_hook_run', function ($hook, $replacement, $version, $message) {
			$msg = $replacement
				? sprintf('Hook %1$s is deprecated since version %2$s! Use %3$s instead.', $hook, $version, $replacement)
				: sprintf('Hook %1$s is deprecated since version %2$s with no alternative available.', $hook, $version);
			dbgtbl_log_wp_notice('Deprecated', trim($msg . ' ' . $message));
		}, 10, 4);

		add_action('doing_it_wrong_run', function ($function_name, $message, $version) {
			$msg = sprintf('Function %1$s was called incorrectly. %2$s', $function_name, $message);
			if ($version) {
				$msg .= sprintf(' (This message was added in version %s.)', $version);
			}
			dbgtbl_log_wp_notice('Notice', $msg);
		}, 10, 3);
	}
}

/*
 * ---------------------------------------------------------------------------
 * Performance Profiler: per-plugin load timing.
 *
 * Only runs while at least one administrator has the profiler switched on. The
 * cost is one microtime() call per loaded plugin. Whether the data is kept is
 * decided later (at plugins_loaded), once the current user is known.
 * ---------------------------------------------------------------------------
 */
$dbgtbl_profiler_users = get_option('dbgtbl_profiler_users', array());
if (is_array($dbgtbl_profiler_users) && !empty($dbgtbl_profiler_users) && max(array_map('intval', $dbgtbl_profiler_users)) > time()) {
	$GLOBALS['dbgtbl_profiler_early'] = array(
		'source' => did_action('muplugins_loaded') ? 'plugin' : 'mu',
		'marker' => microtime(true),
		'plugins' => array(),
	);

	$dbgtbl_record_load = static function ($file) {
		$now = microtime(true);
		$key = DBGTBL_Components::identify($file);
		if ('mu-plugin:debugger-troubleshooter-early.php' === $key) {
			$GLOBALS['dbgtbl_profiler_early']['marker'] = $now;
			return;
		}
		$GLOBALS['dbgtbl_profiler_early']['plugins'][$key] = round(($now - $GLOBALS['dbgtbl_profiler_early']['marker']) * 1000, 2);
		$GLOBALS['dbgtbl_profiler_early']['marker'] = $now;
	};

	add_action('mu_plugin_loaded', $dbgtbl_record_load);
	add_action('network_plugin_loaded', $dbgtbl_record_load);
	add_action('plugin_loaded', $dbgtbl_record_load);

	// Core work between MU plugins and regular plugins should not be charged to the first plugin.
	add_action('muplugins_loaded', static function () {
		$GLOBALS['dbgtbl_profiler_early']['marker'] = microtime(true);
	}, PHP_INT_MAX);

	unset($dbgtbl_record_load);
}
unset($dbgtbl_profiler_users);
