<?php
/**
 * PHP compatibility scanner.
 *
 * Reads PHP source with PHP's own tokenizer instead of regular expressions, so code
 * inside comments, strings and heredocs is never reported, and method calls such as
 * `$collection->each(...)` are not mistaken for removed global functions.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Token-based scanner for removed and deprecated PHP features.
 */
class DBGTBL_Compat_Scanner
{
	/**
	 * Maximum number of PHP files scanned per plugin or theme.
	 */
	const MAX_FILES = 5000;

	/**
	 * Files larger than this are skipped (bytes).
	 */
	const MAX_FILE_BYTES = 524288; // 512 KB.

	/**
	 * Time budget per directory scan (seconds), to stay well under PHP's execution limit.
	 */
	const TIME_BUDGET = 10;

	/**
	 * Folders that are never loaded at runtime and are skipped.
	 */
	const SKIP_DIRS = array('node_modules', '.git', '.svn');

	/**
	 * Removed or deprecated global functions, keyed by lowercase name.
	 *
	 * @param string $target_php Target PHP version (e.g. "8.2").
	 * @return array<string, array{level:string, message:string}>
	 */
	public static function function_rules($target_php)
	{
		$rules = array();

		if (version_compare($target_php, '8.0', '>=')) {
			$removed = array(
				'create_function' => __('Use of removed function `create_function()`.', 'debugger-troubleshooter'),
				'each' => __('Use of removed function `each()`. Replace with foreach.', 'debugger-troubleshooter'),
				'define_syslog_variables' => __('Use of removed function `define_syslog_variables()`.', 'debugger-troubleshooter'),
				'magic_quotes_runtime' => __('Use of removed function `magic_quotes_runtime()`.', 'debugger-troubleshooter'),
				'get_magic_quotes_gpc' => __('Use of removed function `get_magic_quotes_gpc()`.', 'debugger-troubleshooter'),
				'get_magic_quotes_runtime' => __('Use of removed function `get_magic_quotes_runtime()`.', 'debugger-troubleshooter'),
				'convert_cyr_string' => __('Use of removed function `convert_cyr_string()`.', 'debugger-troubleshooter'),
				'ezmlm_hash' => __('Use of removed function `ezmlm_hash()`.', 'debugger-troubleshooter'),
				'restore_include_path' => __('Use of removed function `restore_include_path()`.', 'debugger-troubleshooter'),
				'hebrevc' => __('Use of removed function `hebrevc()`. Use nl2br(hebrev()) instead.', 'debugger-troubleshooter'),
				'money_format' => __('Use of removed function `money_format()`. Use NumberFormatter instead.', 'debugger-troubleshooter'),
				'fgetss' => __('Use of removed function `fgetss()`.', 'debugger-troubleshooter'),
				'image2wbmp' => __('Use of removed function `image2wbmp()`.', 'debugger-troubleshooter'),
				'ldap_sort' => __('Use of removed function `ldap_sort()`.', 'debugger-troubleshooter'),
			);
			foreach ($removed as $name => $message) {
				$rules[$name] = array('level' => 'incompatible', 'message' => $message);
			}
		}

		if (version_compare($target_php, '8.1', '>=')) {
			$rules['strftime'] = array(
				'level' => 'warning',
				'message' => __('Use of deprecated function `strftime()`. Use date() or IntlDateFormatter instead.', 'debugger-troubleshooter'),
			);
			$rules['gmstrftime'] = array(
				'level' => 'warning',
				'message' => __('Use of deprecated function `gmstrftime()`. Use gmdate() or IntlDateFormatter instead.', 'debugger-troubleshooter'),
			);
		}

		if (version_compare($target_php, '8.2', '>=')) {
			$rules['utf8_encode'] = array(
				'level' => 'warning',
				'message' => __('Use of deprecated function `utf8_encode()`. Use mb_convert_encoding instead.', 'debugger-troubleshooter'),
			);
			$rules['utf8_decode'] = array(
				'level' => 'warning',
				'message' => __('Use of deprecated function `utf8_decode()`. Use mb_convert_encoding instead.', 'debugger-troubleshooter'),
			);
		}

		return $rules;
	}

	/**
	 * Scans PHP source code.
	 *
	 * @param string $code       PHP source.
	 * @param string $target_php Target PHP version.
	 * @return array[] Issues with line, level, message and snippet.
	 */
	public static function scan_code($code, $target_php)
	{
		if (!function_exists('token_get_all')) {
			return array();
		}

		$functions = self::function_rules($target_php);
		$check_curly_offset = version_compare($target_php, '8.0', '>=');
		$check_dollar_brace = version_compare($target_php, '8.2', '>=');

		if (empty($functions) && !$check_curly_offset && !$check_dollar_brace) {
			return array();
		}

		$tokens = token_get_all($code);
		$lines = explode("\n", $code);
		$issues = array();
		$line = 1;
		$count = count($tokens);

		// Token constants that only exist on newer PHP versions.
		$t_fully_qualified = defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -1;
		$t_nullsafe = defined('T_NULLSAFE_OBJECT_OPERATOR') ? T_NULLSAFE_OBJECT_OPERATOR : -1;

		// Tokens that, when they come right before a name, mean it is not a global function call.
		$not_a_call = array(T_OBJECT_OPERATOR, $t_nullsafe, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST);

		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];

			if (!is_array($token)) {
				continue;
			}

			$line = $token[2];
			$type = $token[0];

			// Removed / deprecated function calls: name followed by "(".
			if (T_STRING === $type || $t_fully_qualified === $type) {
				$name = strtolower(ltrim($token[1], '\\'));

				if (isset($functions[$name]) && '(' === self::next_significant($tokens, $i)) {
					$prev_index = self::prev_index($tokens, $i);
					$prev_type = self::type_at($tokens, $prev_index);

					// PHP 7.x splits Foo\each into tokens: a name before the separator means a namespaced function.
					if (T_NS_SEPARATOR === $prev_type) {
						$before_type = self::type_at($tokens, self::prev_index($tokens, $prev_index));
						$prev_type = in_array($before_type, array(T_STRING, T_NAMESPACE), true) ? T_NEW : null;
					}

					if (!in_array($prev_type, $not_a_call, true)) {
						$issues[] = self::issue($lines, $line, $functions[$name]['level'], $functions[$name]['message']);
					}
				}

				// $obj->prop{0}
				if ($check_curly_offset && T_STRING === $type && '{' === self::next_significant($tokens, $i)) {
					$prev = self::prev_significant($tokens, $i);
					if (is_array($prev) && in_array($prev[0], array(T_OBJECT_OPERATOR, $t_nullsafe), true)) {
						$issues[] = self::curly_issue($lines, $line);
					}
				}
				continue;
			}

			// $var{0}
			if ($check_curly_offset && T_VARIABLE === $type && '{' === self::next_significant($tokens, $i)) {
				$issues[] = self::curly_issue($lines, $line);
				continue;
			}

			// "${var}" inside a double-quoted string or heredoc.
			if ($check_dollar_brace && T_DOLLAR_OPEN_CURLY_BRACES === $type) {
				$issues[] = self::issue(
					$lines,
					$line,
					'warning',
					__('The ${var} string interpolation syntax is deprecated in PHP 8.2. Use {$var} instead.', 'debugger-troubleshooter')
				);
			}
		}

		return $issues;
	}

	/**
	 * Scans a plugin or theme directory, or a single PHP file.
	 *
	 * @param string $path       Directory or file.
	 * @param string $target_php Target PHP version.
	 * @return array{issues:array[], scanned:int, skipped:int}
	 */
	public static function scan_path($path, $target_php)
	{
		$result = array('issues' => array(), 'scanned' => 0, 'skipped' => 0);

		if (!file_exists($path) || !is_readable($path)) {
			return $result;
		}

		$started = microtime(true);

		foreach (self::php_files($path) as $file) {
			$out_of_budget = $result['scanned'] >= self::MAX_FILES || (microtime(true) - $started) > self::TIME_BUDGET;

			if ($out_of_budget || filesize($file) > self::MAX_FILE_BYTES) {
				$result['skipped']++;
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local source files.
			$code = file_get_contents($file);
			if (false === $code) {
				$result['skipped']++;
				continue;
			}

			$result['scanned']++;
			$relative = DBGTBL_Components::relative_path($file);

			foreach (self::scan_code($code, $target_php) as $issue) {
				$issue['file'] = $relative;
				$result['issues'][] = $issue;
			}
		}

		return $result;
	}

	/**
	 * Lists PHP files under a path, skipping folders that never run.
	 *
	 * @param string $path Directory or file.
	 * @return string[]
	 */
	private static function php_files($path)
	{
		if (is_file($path)) {
			return 'php' === strtolower(pathinfo($path, PATHINFO_EXTENSION)) ? array($path) : array();
		}

		$files = array();

		try {
			$directory = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
			$filter = new RecursiveCallbackFilterIterator($directory, static function ($current) {
				return !($current->isDir() && in_array($current->getFilename(), self::SKIP_DIRS, true));
			});

			foreach (new RecursiveIteratorIterator($filter) as $fileinfo) {
				if ($fileinfo->isFile() && 'php' === strtolower($fileinfo->getExtension())) {
					$files[] = $fileinfo->getPathname();
				}
			}
		} catch (Exception $e) {
			// Unreadable folder: return whatever was collected.
			unset($e);
		}

		sort($files);
		return $files;
	}

	/**
	 * Returns the next token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Current index.
	 * @return array|string|null
	 */
	private static function next_significant($tokens, $i)
	{
		$count = count($tokens);
		for ($j = $i + 1; $j < $count; $j++) {
			if (!self::is_insignificant($tokens[$j])) {
				return $tokens[$j];
			}
		}
		return null;
	}

	/**
	 * Returns the previous token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Current index.
	 * @return array|string|null
	 */
	private static function prev_significant($tokens, $i)
	{
		$j = self::prev_index($tokens, $i);
		return $j >= 0 ? $tokens[$j] : null;
	}

	/**
	 * Index of the previous token that is not whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Current index.
	 * @return int Index, or -1 when there is none.
	 */
	private static function prev_index($tokens, $i)
	{
		for ($j = $i - 1; $j >= 0; $j--) {
			if (!self::is_insignificant($tokens[$j])) {
				return $j;
			}
		}
		return -1;
	}

	/**
	 * Token type (or the character for single-character tokens) at an index.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Index; negative returns null.
	 * @return int|string|null
	 */
	private static function type_at($tokens, $i)
	{
		if ($i < 0 || !isset($tokens[$i])) {
			return null;
		}
		return is_array($tokens[$i]) ? $tokens[$i][0] : $tokens[$i];
	}

	/**
	 * Whether a token is whitespace or a comment.
	 *
	 * @param array|string $token Token.
	 * @return bool
	 */
	private static function is_insignificant($token)
	{
		return is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
	}

	/**
	 * Builds an issue record.
	 *
	 * @param string[] $lines   Source lines.
	 * @param int      $line    1-based line number.
	 * @param string   $level   'incompatible' or 'warning'.
	 * @param string   $message Message.
	 * @return array
	 */
	private static function issue($lines, $line, $level, $message)
	{
		return array(
			'line' => $line,
			'level' => $level,
			'message' => $message,
			'snippet' => isset($lines[$line - 1]) ? trim($lines[$line - 1]) : '',
		);
	}

	/**
	 * Issue for curly brace offset access.
	 *
	 * @param string[] $lines Source lines.
	 * @param int      $line  Line number.
	 * @return array
	 */
	private static function curly_issue($lines, $line)
	{
		return self::issue($lines, $line, 'incompatible', __('Curly brace offset access is removed in PHP 8.0.', 'debugger-troubleshooter'));
	}
}
