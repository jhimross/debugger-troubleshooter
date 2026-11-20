<?php
/**
 * Tests for DBGTBL_Compat_Scanner and DBGTBL_Compat_Checker::check_core().
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Scans a snippet and returns "line:message-start" strings for compact assertions.
 *
 * @param string $code   PHP code (without the opening tag).
 * @param string $target Target PHP version.
 * @return string[]
 */
$dbgtbl_scan = static function ($code, $target = '8.4') {
	$out = array();
	foreach (DBGTBL_Compat_Scanner::scan_code("<?php\n" . $code, $target) as $issue) {
		$out[] = $issue['line'] . ':' . $issue['level'];
	}
	return $out;
};

return array(
	'flags removed global function calls' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('$x = each($arr);'), 'each()');
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('create_function("$a", "return $a;");'), 'create_function()');
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('EACH ( $arr );'), 'case-insensitive, space before (');
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('\\each($arr);'), 'fully qualified \\each()');
	},

	'ignores code in comments and strings' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array(), $dbgtbl_scan('// each($arr);'), 'line comment');
		DBGTBL_Test::same(array(), $dbgtbl_scan("/*\n each(\$arr);\n*/"), 'block comment');
		DBGTBL_Test::same(array(), $dbgtbl_scan('/** Use create_function() here. */'), 'doc comment');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$s = "call each($arr) later";'), 'double-quoted string');
		DBGTBL_Test::same(array(), $dbgtbl_scan("\$s = 'utf8_encode(\$x)';"), 'single-quoted string');
		DBGTBL_Test::same(array(), $dbgtbl_scan("\$s = <<<TXT\nconvert_cyr_string(\$a)\nTXT;"), 'heredoc');
	},

	'ignores methods, static calls, definitions and namespaced functions' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array(), $dbgtbl_scan('$collection->each(function () {});'), 'method call');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$collection?->each($cb);'), 'nullsafe method call');
		DBGTBL_Test::same(array(), $dbgtbl_scan('Collection::each($cb);'), 'static call');
		DBGTBL_Test::same(array(), $dbgtbl_scan('function each($a) { return $a; }'), 'function definition');
		DBGTBL_Test::same(array(), $dbgtbl_scan('class A { public function each() {} }'), 'method definition');
		DBGTBL_Test::same(array(), $dbgtbl_scan('My\\Lib\\each($arr);'), 'namespaced function');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$e = new each();'), 'class instantiation');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$f = $each($arr);'), 'variable function');
	},

	'curly brace offset access' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('$c = $str{0};'), '$var{0}');
		DBGTBL_Test::same(array('2:incompatible'), $dbgtbl_scan('$c = $obj->name{0};'), '$obj->prop{0}');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$s = "{$str}";'), '{$var} interpolation');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$s = "$str{0}";'), 'brace inside string text');
		DBGTBL_Test::same(array(), $dbgtbl_scan('if ($a) { $b = 1; }'), 'control structure');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$c = $str{0};', '7.4'), 'not reported for PHP 7.4');
	},

	'${var} interpolation is a PHP 8.2 warning' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array('2:warning'), $dbgtbl_scan('$s = "Hello ${name}";', '8.2'), 'string');
		DBGTBL_Test::same(array('3:warning'), $dbgtbl_scan("\$s = <<<TXT\n\${name}\nTXT;", '8.2'), 'heredoc, correct line');
		DBGTBL_Test::same(array(), $dbgtbl_scan('$s = "Hello ${name}";', '8.1'), 'not reported for PHP 8.1');
	},

	'rules depend on target version' => static function () use ($dbgtbl_scan) {
		DBGTBL_Test::same(array(), $dbgtbl_scan('each($a);', '7.4'), 'each() fine on 7.4');
		DBGTBL_Test::same(array(), $dbgtbl_scan('utf8_encode($a);', '8.1'), 'utf8_encode() fine on 8.1');
		DBGTBL_Test::same(array('2:warning'), $dbgtbl_scan('utf8_encode($a);', '8.2'), 'utf8_encode() deprecated on 8.2');
		DBGTBL_Test::same(array('2:warning'), $dbgtbl_scan('strftime("%Y");', '8.1'), 'strftime() deprecated on 8.1');
	},

	'reports correct line numbers and snippets' => static function () {
		$issues = DBGTBL_Compat_Scanner::scan_code("<?php\n\n\$a = 1;\n  \$b = each(\$list);\n", '8.0');
		DBGTBL_Test::same(1, count($issues), 'one issue');
		DBGTBL_Test::same(4, $issues[0]['line'], 'line');
		DBGTBL_Test::same('$b = each($list);', $issues[0]['snippet'], 'snippet is trimmed source line');
	},

	'scan_path counts files and skips node_modules' => static function () {
		$dir = trailingslashit(get_temp_dir()) . 'dbgtbl-scan-' . wp_generate_password(8, false);
		wp_mkdir_p($dir . '/sub');
		wp_mkdir_p($dir . '/node_modules/pkg');
		file_put_contents($dir . '/main.php', "<?php\neach(\$a);\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($dir . '/sub/ok.php', "<?php\necho 1;\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($dir . '/node_modules/pkg/bad.php', "<?php\neach(\$a);\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents($dir . '/readme.txt', 'each($a);'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = DBGTBL_Compat_Scanner::scan_path($dir, '8.0');

		foreach (array('/main.php', '/sub/ok.php', '/node_modules/pkg/bad.php', '/readme.txt') as $f) {
			wp_delete_file($dir . $f);
		}
		foreach (array('/node_modules/pkg', '/node_modules', '/sub', '') as $d) {
			rmdir($dir . $d); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}

		DBGTBL_Test::same(2, $result['scanned'], 'two PHP files scanned');
		DBGTBL_Test::same(0, $result['skipped'], 'nothing skipped');
		DBGTBL_Test::same(1, count($result['issues']), 'node_modules ignored');
		DBGTBL_Test::ok('/main.php' === substr($result['issues'][0]['file'], -9), 'issue has file path');
	},

	'this plugin scans itself without false positives' => static function () {
		$result = DBGTBL_Compat_Scanner::scan_path(DBGTBL_DIR, '8.4');
		DBGTBL_Test::same(array(), $result['issues'], 'no issues in our own code');
	},

	'core compatibility by WordPress version' => static function () {
		DBGTBL_Test::same('compatible', DBGTBL_Compat_Checker::check_core('6.8', '8.4')['status'], '6.8 on 8.4');
		DBGTBL_Test::same('compatible', DBGTBL_Compat_Checker::check_core('5.0', '7.4')['status'], '5.0 on 7.4');
		DBGTBL_Test::same('incompatible', DBGTBL_Compat_Checker::check_core('5.5', '8.0')['status'], '5.5 on 8.0');
		DBGTBL_Test::same('incompatible', DBGTBL_Compat_Checker::check_core('6.0', '8.4')['status'], '6.0 on 8.4 is incompatible, not just a warning');
		DBGTBL_Test::same('warning', DBGTBL_Compat_Checker::check_core('6.5', '8.4')['status'], '6.5 on 8.4');
		DBGTBL_Test::same('compatible', DBGTBL_Compat_Checker::check_core('6.10', '8.3')['status'], '6.10 is newer than 6.4');
	},
);
