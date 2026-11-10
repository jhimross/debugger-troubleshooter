<?php
/**
 * Smart Log Viewer: locates, parses, groups, and attributes PHP error log entries.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Log viewer.
 */
class DBGTBL_Log_Viewer
{
	/**
	 * Maximum number of bytes read from the end of the log.
	 */
	const TAIL_BYTES = 2097152; // 2 MB.

	/**
	 * Log size above which a "large log" warning is shown.
	 */
	const LARGE_LOG_BYTES = 52428800; // 50 MB.

	/**
	 * Number of raw lines returned for the raw view.
	 */
	const RAW_LINES = 300;

	/**
	 * Maximum number of grouped entries returned.
	 */
	const MAX_GROUPS = 500;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		add_action('wp_ajax_debug_troubleshoot_get_log', array($this, 'ajax_get_log'));
		add_action('admin_post_dbgtbl_download_log', array($this, 'download_log'));
	}

	/**
	 * Resolves the log file currently in use.
	 *
	 * Order: the PHP error_log setting in effect (set by WordPress when WP_DEBUG_LOG is
	 * on, or by Live Debug), then a custom WP_DEBUG_LOG path, then wp-content/debug.log.
	 *
	 * @return string
	 */
	public static function get_log_path()
	{
		$ini = ini_get('error_log');

		if (is_string($ini) && '' !== $ini && 'syslog' !== strtolower($ini) && !is_dir($ini)) {
			return wp_normalize_path($ini);
		}

		if (function_exists('dbgtbl_live_debug_log_path')) {
			return wp_normalize_path(dbgtbl_live_debug_log_path());
		}

		return wp_normalize_path(WP_CONTENT_DIR . '/debug.log');
	}

	/**
	 * Reads the end of a file.
	 *
	 * @param string $path  File path.
	 * @param int    $bytes Maximum bytes to read.
	 * @return array{0:string,1:bool} Content and whether it was truncated.
	 */
	public static function read_tail($path, $bytes = self::TAIL_BYTES)
	{
		$size = filesize($path);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of a potentially large log; WP_Filesystem would load the whole file.
		$handle = fopen($path, 'rb');
		if (!$handle) {
			return array('', false);
		}

		$truncated = false;

		if ($size > $bytes) {
			fseek($handle, -$bytes, SEEK_END);
			fgets($handle); // Discard the partial first line.
			$truncated = true;
		}

		$content = stream_get_contents($handle);
		fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(false === $content ? '' : $content, $truncated);
	}

	/**
	 * Returns the last N lines of the log as plain text.
	 *
	 * @param int $lines_count Number of lines.
	 * @return string
	 */
	public static function get_raw_tail($lines_count = self::RAW_LINES)
	{
		$path = self::get_log_path();

		if (!file_exists($path) || !is_readable($path)) {
			/* translators: %s: Log file path. */
			return sprintf(__('Log file %s does not exist yet or is not readable.', 'debugger-troubleshooter'), $path);
		}

		if (0 === filesize($path)) {
			return __('The log file is empty.', 'debugger-troubleshooter');
		}

		list($content) = self::read_tail($path, 262144);
		$lines = preg_split('/\r\n|\n|\r/', rtrim($content));

		return implode("\n", array_slice($lines, -$lines_count));
	}

	/**
	 * Maps a PHP error label to a level.
	 *
	 * @param string $label Label such as "Fatal error" or "Warning".
	 * @return string One of fatal, warning, notice, deprecated.
	 */
	private static function level_from_label($label)
	{
		$label = strtolower($label);

		if (false !== strpos($label, 'fatal') || false !== strpos($label, 'parse')) {
			return 'fatal';
		}
		if (false !== strpos($label, 'warning')) {
			return 'warning';
		}
		if (false !== strpos($label, 'deprecated')) {
			return 'deprecated';
		}

		return 'notice';
	}

	/**
	 * Parses raw log content into entries.
	 *
	 * @param string $content Raw log content.
	 * @return array List of entries.
	 */
	public static function parse($content)
	{
		$lines = preg_split('/\r\n|\n|\r/', $content);
		$entries = array();
		$current = null;

		foreach ($lines as $line) {
			if ('' === trim($line)) {
				continue;
			}

			if (preg_match('/^\[([^\]]+)\]\s?(.*)$/', $line, $m)) {
				$body = $m[2];

				// Xdebug / PHP stack trace lines carry their own timestamp but belong to the previous entry.
				if (null !== $current && preg_match('/^PHP\s+(Stack trace:|\d+\.\s)/', $body)) {
					$current['trace'][] = $body;
					continue;
				}

				if (null !== $current) {
					$entries[] = $current;
				}

				$current = array(
					'time' => $m[1],
					'body' => $body,
					'trace' => array(),
				);
				continue;
			}

			// Continuation line (stack trace, multi-line message).
			if (null === $current) {
				$current = array(
					'time' => '',
					'body' => $line,
					'trace' => array(),
				);
			} else {
				$current['trace'][] = $line;
			}
		}

		if (null !== $current) {
			$entries[] = $current;
		}

		foreach ($entries as &$entry) {
			$entry = self::classify($entry);
		}
		unset($entry);

		return $entries;
	}

	/**
	 * Adds level, message, file, line, and component to a parsed entry.
	 *
	 * @param array $entry Entry with time, body, trace.
	 * @return array
	 */
	private static function classify($entry)
	{
		$body = $entry['body'];
		$level = 'other';
		$message = $body;

		if (preg_match('/^PHP\s+([A-Za-z ]+?)\s*:\s+(.*)$/s', $body, $m)) {
			$level = self::level_from_label($m[1]);
			$message = $m[2];
		} elseif (0 === strpos($body, 'WordPress database error')) {
			$level = 'database';
		}

		$file = '';
		$line = 0;

		if (preg_match('/\sin\s(\S+?\.php)(?:\s+on\s+line\s+|:|\()(\d+)/', $message, $fm)) {
			$file = $fm[1];
			$line = (int) $fm[2];
		}

		// Attribute the entry: the file itself first, then the first non-core path in the stack trace.
		$component = $file ? DBGTBL_Components::identify($file) : 'other';
		$via_trace = false;

		if (DBGTBL_Components::is_core($component) && !empty($entry['trace'])) {
			foreach ($entry['trace'] as $trace_line) {
				if (preg_match('/(\/[^\s:(]+\.php)/', wp_normalize_path($trace_line), $tm)) {
					$candidate = DBGTBL_Components::identify($tm[1]);
					if (!DBGTBL_Components::is_core($candidate)) {
						$component = $candidate;
						$via_trace = true;
						break;
					}
				}
			}
		}

		if ('other' === $component && $file) {
			$component = 'core';
		}

		$entry['level'] = $level;
		$entry['message'] = $message;
		$entry['file'] = $file;
		$entry['line'] = $line;
		$entry['component'] = $component;
		$entry['via_trace'] = $via_trace;

		return $entry;
	}

	/**
	 * Groups identical entries.
	 *
	 * @param array $entries Parsed entries in log order (oldest first).
	 * @return array Groups, most recently seen first.
	 */
	public static function group($entries)
	{
		$groups = array();

		foreach ($entries as $index => $entry) {
			$signature = md5($entry['level'] . '|' . $entry['message']);

			if (!isset($groups[$signature])) {
				$groups[$signature] = array(
					'level' => $entry['level'],
					'message' => $entry['message'],
					'file' => $entry['file'] ? DBGTBL_Components::relative_path($entry['file']) : '',
					'line' => $entry['line'],
					'component' => $entry['component'],
					'via_trace' => $entry['via_trace'],
					'count' => 0,
					'first_seen' => $entry['time'],
					'last_seen' => $entry['time'],
					'last_index' => $index,
					'trace' => array(),
				);
			}

			$groups[$signature]['count']++;
			$groups[$signature]['last_seen'] = $entry['time'];
			$groups[$signature]['last_index'] = $index;

			if (!empty($entry['trace'])) {
				$groups[$signature]['trace'] = array_slice($entry['trace'], 0, 40);
			}
		}

		usort($groups, static function ($a, $b) {
			return $b['last_index'] - $a['last_index'];
		});

		return $groups;
	}

	/**
	 * Builds the full payload for the log viewer UI.
	 *
	 * @return array
	 */
	public static function get_report()
	{
		$path = self::get_log_path();
		$report = array(
			'path' => DBGTBL_Components::relative_path($path),
			'exists' => file_exists($path),
			'readable' => is_readable($path),
			'writable' => wp_is_writable($path),
			'size' => 0,
			'size_human' => '0 B',
			'large' => false,
			'truncated' => false,
			'total_entries' => 0,
			'levels' => array(
				'fatal' => 0,
				'warning' => 0,
				'notice' => 0,
				'deprecated' => 0,
				'database' => 0,
				'other' => 0,
			),
			'components' => array(),
			'groups' => array(),
			'raw' => '',
		);

		if (!$report['exists'] || !$report['readable']) {
			$report['raw'] = self::get_raw_tail();
			return $report;
		}

		$size = (int) filesize($path);
		$report['size'] = $size;
		$report['size_human'] = size_format($size, 1);
		$report['large'] = $size > self::LARGE_LOG_BYTES;

		if (0 === $size) {
			$report['raw'] = __('The log file is empty.', 'debugger-troubleshooter');
			return $report;
		}

		list($content, $truncated) = self::read_tail($path);
		$report['truncated'] = $truncated;

		$entries = self::parse($content);
		$report['total_entries'] = count($entries);

		$components = array();
		foreach ($entries as $entry) {
			$report['levels'][$entry['level']]++;
			if (!isset($components[$entry['component']])) {
				$components[$entry['component']] = 0;
			}
			$components[$entry['component']]++;
		}

		arsort($components);
		foreach ($components as $key => $count) {
			$report['components'][] = array(
				'key' => $key,
				'label' => DBGTBL_Components::label($key),
				'count' => $count,
			);
		}

		$groups = array_slice(self::group($entries), 0, self::MAX_GROUPS);
		foreach ($groups as &$group) {
			$group['component_label'] = DBGTBL_Components::label($group['component']);
			unset($group['last_index']);
		}
		unset($group);

		$report['groups'] = $groups;

		$lines = preg_split('/\r\n|\n|\r/', rtrim($content));
		$report['raw'] = implode("\n", array_slice($lines, -self::RAW_LINES));

		return $report;
	}

	/**
	 * AJAX: returns the parsed log report.
	 */
	public function ajax_get_log()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		wp_send_json_success(self::get_report());
	}

	/**
	 * Streams the full log file as a download.
	 */
	public function download_log()
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Permission denied.', 'debugger-troubleshooter'), '', array('response' => 403));
		}

		check_admin_referer('dbgtbl_download_log');

		$path = self::get_log_path();

		if (!file_exists($path) || !is_readable($path)) {
			wp_die(esc_html__('The log file does not exist or is not readable.', 'debugger-troubleshooter'), '', array('response' => 404));
		}

		$filename = sanitize_file_name(wp_parse_url(home_url(), PHP_URL_HOST) . '-' . gmdate('Y-m-d-His') . '-' . basename($path));

		nocache_headers();
		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . filesize($path));

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams large files without loading them into memory.
		readfile($path);
		exit;
	}
}
