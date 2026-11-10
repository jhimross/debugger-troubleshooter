<?php
/**
 * Manages the small MU plugin that loads includes/early-loader.php before regular plugins.
 *
 * The MU plugin only exists while Live Debug or the Performance Profiler is on, and
 * it does nothing if Debugger & Troubleshooter itself is deactivated.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Early MU loader manager.
 */
class DBGTBL_Early_MU
{
	const FILENAME = 'debugger-troubleshooter-early.php';
	const VERSION = '1.0';

	/**
	 * Full path to the MU plugin file.
	 *
	 * @return string
	 */
	public static function path()
	{
		return WPMU_PLUGIN_DIR . '/' . self::FILENAME;
	}

	/**
	 * Whether Live Debug or the profiler currently needs early loading.
	 *
	 * @return bool
	 */
	public static function is_needed()
	{
		return 'enabled' === get_option(Debug_Troubleshooter::DEBUG_MODE_OPTION, 'disabled') || DBGTBL_Profiler::is_globally_active();
	}

	/**
	 * Installs or removes the MU plugin to match current settings.
	 *
	 * @return bool True when the MU plugin is in the desired state.
	 */
	public static function sync()
	{
		return self::is_needed() ? self::install() : self::remove();
	}

	/**
	 * Writes the MU plugin.
	 *
	 * @return bool
	 */
	public static function install()
	{
		$file = self::path();

		if (file_exists($file)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			$existing = file_get_contents($file);
			if (false !== $existing && false !== strpos($existing, 'DBGTBL_EARLY_MU ' . self::VERSION)) {
				return true;
			}
		}

		if (!is_dir(WPMU_PLUGIN_DIR) && !wp_mkdir_p(WPMU_PLUGIN_DIR)) {
			return false;
		}

		$basename = var_export(DBGTBL_BASENAME, true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a safe PHP string literal.
		$loader = var_export(wp_normalize_path(DBGTBL_DIR . 'includes/early-loader.php'), true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates a safe PHP string literal.
		$version = self::VERSION;

		// Single-quoted lines keep the generated code literal; only the three values are inserted.
		$lines = array(
			'<?php',
			'/**',
			' * Plugin Name: Debugger & Troubleshooter (Early Loader)',
			' * Description: Applies Live Debug and Performance Profiler settings before regular plugins load. Removed automatically when both features are turned off.',
			' * Version: ' . $version,
			' * Author: Jhimross',
			' *',
			' * DBGTBL_EARLY_MU ' . $version,
			' */',
			'',
			'if (!defined(\'ABSPATH\')) {',
			"\texit;",
			'}',
			'',
			'$dbgtbl_basename = ' . $basename . ';',
			'$dbgtbl_loader = ' . $loader . ';',
			'$dbgtbl_active = in_array($dbgtbl_basename, (array) get_option(\'active_plugins\', array()), true);',
			'',
			'if (!$dbgtbl_active && is_multisite()) {',
			"\t" . '$dbgtbl_active = array_key_exists($dbgtbl_basename, (array) get_site_option(\'active_sitewide_plugins\', array()));',
			'}',
			'',
			'if ($dbgtbl_active && file_exists($dbgtbl_loader)) {',
			"\t" . 'include_once $dbgtbl_loader;',
			'}',
			'',
			'unset($dbgtbl_basename, $dbgtbl_loader, $dbgtbl_active);',
			'',
		);

		$content = implode("\n", $lines);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Direct write avoids FTP credential prompts during AJAX.
		return false !== @file_put_contents($file, $content);
	}

	/**
	 * Deletes the MU plugin.
	 *
	 * @return bool
	 */
	public static function remove()
	{
		$file = self::path();

		if (file_exists($file)) {
			wp_delete_file($file);
		}

		return !file_exists($file);
	}
}
