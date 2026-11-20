<?php
/**
 * Tests for the debug.log parser and grouping.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

$dbgtbl_plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
$dbgtbl_core_dir = wp_normalize_path(ABSPATH . WPINC);

return array(
	'parses PHP error levels' => static function () use ($dbgtbl_plugin_dir) {
		$log = implode("\n", array(
			"[20-Nov-2026 07:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in $dbgtbl_plugin_dir/acme/acme.php:12",
			"[20-Nov-2026 07:00:01 UTC] PHP Warning:  Undefined variable \$x in $dbgtbl_plugin_dir/acme/acme.php on line 5",
			"[20-Nov-2026 07:00:02 UTC] PHP Notice:  Something in $dbgtbl_plugin_dir/acme/acme.php on line 6",
			"[20-Nov-2026 07:00:03 UTC] PHP Deprecated:  Old thing in $dbgtbl_plugin_dir/acme/acme.php on line 7",
			"[20-Nov-2026 07:00:04 UTC] PHP Parse error:  syntax error in $dbgtbl_plugin_dir/acme/acme.php on line 8",
			'[20-Nov-2026 07:00:05 UTC] WordPress database error Table missing for query SELECT 1',
			'[20-Nov-2026 07:00:06 UTC] Custom message from error_log()',
		));

		$levels = wp_list_pluck(DBGTBL_Log_Viewer::parse($log), 'level');
		DBGTBL_Test::same(array('fatal', 'warning', 'notice', 'deprecated', 'fatal', 'database', 'other'), $levels);
	},

	'extracts file, line and owning plugin' => static function () use ($dbgtbl_plugin_dir) {
		$entries = DBGTBL_Log_Viewer::parse("[20-Nov-2026 07:00:01 UTC] PHP Warning:  Bad in $dbgtbl_plugin_dir/acme/inc/a.php on line 42");

		DBGTBL_Test::same(1, count($entries), 'one entry');
		DBGTBL_Test::same("$dbgtbl_plugin_dir/acme/inc/a.php", $entries[0]['file'], 'file');
		DBGTBL_Test::same(42, $entries[0]['line'], 'line');
		DBGTBL_Test::same('plugin:acme', $entries[0]['component'], 'component');
		DBGTBL_Test::same(false, $entries[0]['via_trace'], 'attributed directly');
	},

	'blames the plugin from the stack trace when the error is in core' => static function () use ($dbgtbl_plugin_dir, $dbgtbl_core_dir) {
		$log = implode("\n", array(
			"[20-Nov-2026 07:00:00 UTC] PHP Fatal error:  Uncaught TypeError: bad arg in $dbgtbl_core_dir/formatting.php:100",
			'Stack trace:',
			"#0 $dbgtbl_core_dir/class-wp-hook.php(324): esc_html()",
			"#1 $dbgtbl_plugin_dir/culprit/culprit.php(9): apply_filters()",
			'#2 {main}',
			"  thrown in $dbgtbl_core_dir/formatting.php on line 100",
		));

		$entries = DBGTBL_Log_Viewer::parse($log);
		DBGTBL_Test::same(1, count($entries), 'trace lines belong to one entry');
		DBGTBL_Test::same(5, count($entries[0]['trace']), 'trace lines kept');
		DBGTBL_Test::same('plugin:culprit', $entries[0]['component'], 'component from trace');
		DBGTBL_Test::same(true, $entries[0]['via_trace'], 'via trace');
	},

	'timestamped PHP stack trace lines attach to the previous entry' => static function () use ($dbgtbl_plugin_dir) {
		$log = implode("\n", array(
			"[20-Nov-2026 07:00:00 UTC] PHP Warning:  Oops in $dbgtbl_plugin_dir/acme/acme.php on line 3",
			'[20-Nov-2026 07:00:00 UTC] PHP Stack trace:',
			'[20-Nov-2026 07:00:00 UTC] PHP   1. {main}() /var/www/index.php:0',
		));

		$entries = DBGTBL_Log_Viewer::parse($log);
		DBGTBL_Test::same(1, count($entries), 'one entry');
		DBGTBL_Test::same(2, count($entries[0]['trace']), 'two trace lines');
	},

	'handles Windows line endings and blank lines' => static function () {
		$entries = DBGTBL_Log_Viewer::parse("[a] PHP Notice:  One\r\n\r\n[b] PHP Notice:  Two\r\n");
		DBGTBL_Test::same(array('One', 'Two'), wp_list_pluck($entries, 'message'));
	},

	'groups repeated errors, newest first' => static function () use ($dbgtbl_plugin_dir) {
		$log = implode("\n", array(
			"[t1] PHP Warning:  A in $dbgtbl_plugin_dir/x/x.php on line 1",
			"[t2] PHP Notice:  B in $dbgtbl_plugin_dir/x/x.php on line 2",
			"[t3] PHP Warning:  A in $dbgtbl_plugin_dir/x/x.php on line 1",
			"[t4] PHP Warning:  A in $dbgtbl_plugin_dir/x/x.php on line 1",
		));

		$groups = DBGTBL_Log_Viewer::group(DBGTBL_Log_Viewer::parse($log));

		DBGTBL_Test::same(2, count($groups), 'two groups');
		DBGTBL_Test::same('warning', $groups[0]['level'], 'most recent group first');
		DBGTBL_Test::same(3, $groups[0]['count'], 'count');
		DBGTBL_Test::same('t1', $groups[0]['first_seen'], 'first seen');
		DBGTBL_Test::same('t4', $groups[0]['last_seen'], 'last seen');
		DBGTBL_Test::same(1, $groups[1]['count'], 'single notice');
	},

	'empty log gives no entries' => static function () {
		DBGTBL_Test::same(array(), DBGTBL_Log_Viewer::parse(''));
		DBGTBL_Test::same(array(), DBGTBL_Log_Viewer::group(array()));
	},
);
