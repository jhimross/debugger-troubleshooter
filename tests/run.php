<?php
/**
 * Test runner for Debugger & Troubleshooter.
 *
 * Runs inside WordPress so the plugin's classes and WordPress functions are available,
 * without needing PHPUnit or Composer:
 *
 *     studio wp eval-file wp-content/plugins/debugger-troubleshooter/tests/run.php
 *     wp eval-file wp-content/plugins/debugger-troubleshooter/tests/run.php
 *
 * Exits with status 1 when any test fails. The tests only call pure functions: they do
 * not touch the database, files or the current user. This folder is not shipped to
 * WordPress.org (see .distignore).
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Minimal assertion helper.
 */
final class DBGTBL_Test
{
	/**
	 * Number of passed assertions.
	 *
	 * @var int
	 */
	public static $passed = 0;

	/**
	 * Failure messages.
	 *
	 * @var string[]
	 */
	public static $failures = array();

	/**
	 * Name of the test currently running.
	 *
	 * @var string
	 */
	public static $current = '';

	/**
	 * Asserts strict equality.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $label    What is being checked.
	 */
	public static function same($expected, $actual, $label = '')
	{
		if ($expected === $actual) {
			self::$passed++;
			return;
		}

		self::$failures[] = sprintf(
			"%s%s\n      expected: %s\n      actual:   %s",
			self::$current,
			$label ? ' - ' . $label : '',
			var_export($expected, true), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			var_export($actual, true) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
	}

	/**
	 * Marks the start of a test.
	 *
	 * @param string $name Test name.
	 */
	public static function start($name)
	{
		self::$current = $name;
	}

	/**
	 * Records a test that threw.
	 *
	 * @param Throwable $e Exception or error.
	 */
	public static function threw($e)
	{
		self::$failures[] = self::$current . ' - threw ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
	}

	/**
	 * Asserts a condition is true.
	 *
	 * @param bool   $condition Condition.
	 * @param string $label     What is being checked.
	 */
	public static function ok($condition, $label = '')
	{
		self::same(true, (bool) $condition, $label);
	}
}

$dbgtbl_test_files = glob(__DIR__ . '/test-*.php');
sort($dbgtbl_test_files);

foreach ($dbgtbl_test_files as $dbgtbl_test_file) {
	$dbgtbl_tests = require $dbgtbl_test_file;

	foreach ($dbgtbl_tests as $dbgtbl_name => $dbgtbl_test) {
		DBGTBL_Test::start(basename($dbgtbl_test_file, '.php') . ' :: ' . $dbgtbl_name);
		$dbgtbl_before = count(DBGTBL_Test::$failures);

		try {
			$dbgtbl_test();
		} catch (Throwable $dbgtbl_error) {
			DBGTBL_Test::threw($dbgtbl_error);
		}

		echo (count(DBGTBL_Test::$failures) === $dbgtbl_before ? '  ✓ ' : '  ✗ ') . DBGTBL_Test::$current . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	}
}

echo "\n";
foreach (DBGTBL_Test::$failures as $dbgtbl_failure) {
	echo 'FAIL ' . $dbgtbl_failure . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
}

printf("%d assertions passed, %d failed.\n", (int) DBGTBL_Test::$passed, count(DBGTBL_Test::$failures));

if (DBGTBL_Test::$failures) {
	exit(1);
}
