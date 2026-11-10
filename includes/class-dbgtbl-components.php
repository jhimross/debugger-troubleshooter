<?php
/**
 * Maps file paths to the plugin, theme, or core component that owns them.
 *
 * Shared by the Log Viewer (to attribute errors) and the Performance Profiler
 * (to attribute database queries and HTTP calls).
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Component identification helper.
 */
class DBGTBL_Components
{
	/**
	 * Cache of path => component key.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Cache of normalized root directories.
	 *
	 * @var array|null
	 */
	private static $roots = null;

	/**
	 * Cache of component key => label.
	 *
	 * @var array
	 */
	private static $labels = array();

	/**
	 * Returns the normalized root directories, most specific first.
	 *
	 * @return array
	 */
	private static function roots()
	{
		if (null === self::$roots) {
			$theme_root = function_exists('get_theme_root') ? get_theme_root() : WP_CONTENT_DIR . '/themes';

			self::$roots = array(
				'plugin' => trailingslashit(wp_normalize_path(WP_PLUGIN_DIR)),
				'mu-plugin' => trailingslashit(wp_normalize_path(WPMU_PLUGIN_DIR)),
				'theme' => trailingslashit(wp_normalize_path($theme_root)),
				'core-includes' => trailingslashit(wp_normalize_path(ABSPATH . WPINC)),
				'core-admin' => trailingslashit(wp_normalize_path(ABSPATH . 'wp-admin')),
				'content' => trailingslashit(wp_normalize_path(WP_CONTENT_DIR)),
				'abspath' => trailingslashit(wp_normalize_path(ABSPATH)),
			);
		}

		return self::$roots;
	}

	/**
	 * Identifies the component that owns a file.
	 *
	 * @param string $file Absolute file path.
	 * @return string Component key, e.g. "plugin:woocommerce", "theme:twentytwentyfive", "core", "other".
	 */
	public static function identify($file)
	{
		if (!is_string($file) || '' === $file) {
			return 'other';
		}

		if (isset(self::$cache[$file])) {
			return self::$cache[$file];
		}

		$path = wp_normalize_path($file);
		$roots = self::roots();
		$key = 'other';

		foreach (array('plugin', 'mu-plugin', 'theme') as $type) {
			if (0 === strpos($path, $roots[$type])) {
				$relative = substr($path, strlen($roots[$type]));
				$segments = explode('/', $relative);
				$key = $type . ':' . $segments[0];
				break;
			}
		}

		if ('other' === $key) {
			if (0 === strpos($path, $roots['core-includes']) || 0 === strpos($path, $roots['core-admin'])) {
				$key = 'core';
			} elseif (0 === strpos($path, $roots['content'])) {
				// Drop-ins such as db.php, object-cache.php, advanced-cache.php.
				$key = 'dropin:' . basename($path);
			} elseif (0 === strpos($path, $roots['abspath']) && false === strpos(substr($path, strlen($roots['abspath'])), '/')) {
				// Root files such as wp-settings.php, wp-load.php.
				$key = 'core';
			}
		}

		self::$cache[$file] = $key;

		return $key;
	}

	/**
	 * Whether a component key belongs to WordPress core.
	 *
	 * @param string $key Component key.
	 * @return bool
	 */
	public static function is_core($key)
	{
		return 'core' === $key || 'other' === $key || in_array($key, self::infrastructure(), true);
	}

	/**
	 * Components that act as part of core and should never be blamed for work they do on
	 * behalf of others: the db.php drop-in and whatever package provides the $wpdb class
	 * (e.g. the SQLite integration, HyperDB, LudicrousDB).
	 *
	 * @return string[]
	 */
	private static function infrastructure()
	{
		static $keys = null;

		if (null !== $keys) {
			return $keys;
		}

		$keys = array('dropin:db.php');

		if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
			try {
				$class = new ReflectionClass($GLOBALS['wpdb']);
				while ($class) {
					$file = $class->getFileName();
					if ($file) {
						$key = self::identify($file);
						if ('core' !== $key && !in_array($key, $keys, true)) {
							$keys[] = $key;
						}
					}
					$class = $class->getParentClass();
				}
			} catch (ReflectionException $e) {
				// Ignore; fall back to the drop-in only.
				unset($e);
			}
		}

		return $keys;
	}

	/**
	 * Whether a component key refers to this plugin.
	 *
	 * @param string $key Component key.
	 * @return bool
	 */
	public static function is_self($key)
	{
		// Use the directory name so this also works before the main plugin file has loaded.
		return 'plugin:' . basename(dirname(__DIR__)) === $key;
	}

	/**
	 * Finds the first non-core, non-self component in the current call stack.
	 *
	 * @param int $skip Number of innermost frames to skip.
	 * @return string Component key.
	 */
	public static function from_backtrace($skip = 0)
	{
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used for attribution in the opt-in profiler and debug logging.
		$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$frames = array_slice($frames, $skip);

		foreach ($frames as $frame) {
			if (empty($frame['file'])) {
				continue;
			}

			$key = self::identify($frame['file']);

			if (self::is_core($key) || self::is_self($key)) {
				continue;
			}

			return $key;
		}

		return 'core';
	}

	/**
	 * Finds the first non-core frame (file and line) in the current call stack.
	 *
	 * @return array{file:string,line:int}|null
	 */
	public static function caller_frame()
	{
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Used to attribute debug log entries to their source.
		$frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

		foreach ($frames as $frame) {
			if (empty($frame['file'])) {
				continue;
			}

			$key = self::identify($frame['file']);

			if (self::is_core($key) || self::is_self($key)) {
				continue;
			}

			return array(
				'file' => $frame['file'],
				'line' => isset($frame['line']) ? (int) $frame['line'] : 0,
			);
		}

		return null;
	}

	/**
	 * Returns a human readable label for a component key.
	 *
	 * @param string $key Component key.
	 * @return string
	 */
	public static function label($key)
	{
		if (isset(self::$labels[$key])) {
			return self::$labels[$key];
		}

		$label = $key;
		$parts = explode(':', $key, 2);
		$type = $parts[0];
		$slug = isset($parts[1]) ? $parts[1] : '';

		switch ($type) {
			case 'core':
				$label = __('WordPress Core', 'debugger-troubleshooter');
				break;

			case 'plugin':
				if (!function_exists('get_plugins')) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$label = $slug;
				foreach (get_plugins() as $plugin_file => $plugin_data) {
					if ($plugin_file === $slug || dirname($plugin_file) === $slug) {
						$label = $plugin_data['Name'];
						break;
					}
				}
				break;

			case 'mu-plugin':
				/* translators: %s: MU plugin file or folder name. */
				$label = sprintf(__('MU Plugin: %s', 'debugger-troubleshooter'), $slug);
				break;

			case 'theme':
				$theme = wp_get_theme($slug);
				$label = $theme->exists() ? $theme->get('Name') : $slug;
				/* translators: %s: Theme name. */
				$label = sprintf(__('Theme: %s', 'debugger-troubleshooter'), $label);
				break;

			case 'dropin':
				/* translators: %s: Drop-in file name. */
				$label = sprintf(__('Drop-in: %s', 'debugger-troubleshooter'), $slug);
				break;

			default:
				$label = __('Other', 'debugger-troubleshooter');
		}

		self::$labels[$key] = $label;

		return $label;
	}

	/**
	 * Returns a path relative to ABSPATH for display.
	 *
	 * @param string $file Absolute file path.
	 * @return string
	 */
	public static function relative_path($file)
	{
		$path = wp_normalize_path($file);
		$abspath = wp_normalize_path(ABSPATH);

		if (0 === strpos($path, $abspath)) {
			return substr($path, strlen($abspath));
		}

		return $path;
	}
}
