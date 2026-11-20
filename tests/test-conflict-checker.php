<?php
/**
 * Tests for the Conflict Checker's binary search.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds a list of fake plugin basenames.
 *
 * @param int $n Count.
 * @return string[]
 */
$dbgtbl_plugins = static function ($n) {
	$plugins = array();
	for ($i = 1; $i <= $n; $i++) {
		$plugins[] = "plugin-$i/plugin-$i.php";
	}
	return $plugins;
};

/**
 * Runs a full check, answering honestly as if $culprit causes the problem.
 *
 * @param string[] $active  Active plugins.
 * @param string   $culprit Plugin causing the problem.
 * @return array Final state.
 */
$dbgtbl_run = static function ($active, $culprit) {
	$state = DBGTBL_Conflict_Checker::initial_state($active, array());
	$guard = 0;

	while ('active' === $state['status'] && $guard++ < 50) {
		$running = DBGTBL_Conflict_Checker::plugins_for_step($state);
		$state = DBGTBL_Conflict_Checker::advance($state, in_array($culprit, $running, true) ? 'broken' : 'fixed');
	}

	return $state;
};

return array(
	'split keeps the extra plugin in the active half' => static function () use ($dbgtbl_plugins) {
		list($active, $deactivated) = DBGTBL_Conflict_Checker::split($dbgtbl_plugins(5));
		DBGTBL_Test::same(3, count($active), 'active half');
		DBGTBL_Test::same(2, count($deactivated), 'deactivated half');
		DBGTBL_Test::same($dbgtbl_plugins(5), array_merge($active, $deactivated), 'nothing lost or reordered');
	},

	'initial state excludes kept plugins and this plugin' => static function () use ($dbgtbl_plugins) {
		$active = array_merge($dbgtbl_plugins(4), array(DBGTBL_BASENAME));
		$state = DBGTBL_Conflict_Checker::initial_state($active, array('plugin-2/plugin-2.php'));

		DBGTBL_Test::same('active', $state['status'], 'status');
		DBGTBL_Test::same(1, $state['step'], 'step');
		DBGTBL_Test::same(array('plugin-1/plugin-1.php', 'plugin-3/plugin-3.php', 'plugin-4/plugin-4.php'), $state['suspects'], 'suspects');
		DBGTBL_Test::ok(in_array(DBGTBL_BASENAME, $state['must_keep'], true), 'this plugin always kept');

		$running = DBGTBL_Conflict_Checker::plugins_for_step($state);
		DBGTBL_Test::ok(in_array(DBGTBL_BASENAME, $running, true), 'this plugin stays active in every step');
		DBGTBL_Test::ok(in_array('plugin-2/plugin-2.php', $running, true), 'kept plugin stays active');
	},

	'initial state returns false with no suspects' => static function () {
		DBGTBL_Test::same(false, DBGTBL_Conflict_Checker::initial_state(array(DBGTBL_BASENAME), array()), 'only this plugin');
		DBGTBL_Test::same(false, DBGTBL_Conflict_Checker::initial_state(array('a/a.php', DBGTBL_BASENAME), array('a/a.php')), 'everything kept');
	},

	'finds every possible culprit within log2(n) steps' => static function () use ($dbgtbl_plugins, $dbgtbl_run) {
		foreach (array(2, 3, 5, 8, 13, 32) as $n) {
			$active = $dbgtbl_plugins($n);
			$max_steps = (int) ceil(log($n, 2));

			foreach ($active as $culprit) {
				$state = $dbgtbl_run($active, $culprit);
				DBGTBL_Test::same('found', $state['status'], "n=$n culprit=$culprit status");
				DBGTBL_Test::same($culprit, $state['culprit'], "n=$n culprit identified");
				DBGTBL_Test::ok($state['step'] <= $max_steps, "n=$n took {$state['step']} steps (max $max_steps)");
			}
		}
	},

	'single suspect: broken finds it, fixed means no culprit' => static function () {
		$state = DBGTBL_Conflict_Checker::initial_state(array('only/only.php'), array());
		DBGTBL_Test::same(array('only/only.php'), $state['active_group'], 'single suspect stays active');

		$found = DBGTBL_Conflict_Checker::advance($state, 'broken');
		DBGTBL_Test::same('found', $found['status'], 'broken');
		DBGTBL_Test::same('only/only.php', $found['culprit'], 'culprit');

		$none = DBGTBL_Conflict_Checker::advance($state, 'fixed');
		DBGTBL_Test::same('no_culprit', $none['status'], 'fixed');
	},

	'advancing increments the step and narrows suspects' => static function () use ($dbgtbl_plugins) {
		$state = DBGTBL_Conflict_Checker::initial_state($dbgtbl_plugins(8), array());
		$next = DBGTBL_Conflict_Checker::advance($state, 'fixed');

		DBGTBL_Test::same(2, $next['step'], 'step');
		DBGTBL_Test::same($state['deactivated_group'], $next['suspects'], 'suspects are the previously deactivated half');
		DBGTBL_Test::same(2, count($next['active_group']), 'halved again');
	},
);
