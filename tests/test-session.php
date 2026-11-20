<?php
/**
 * Tests that a troubleshooting session never overwrites the site's real plugin list.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Runs a callback while get_option('active_plugins') returns $real normally and $session
 * when a troubleshooting session would be filtering it. Nothing is saved.
 *
 * @param string[] $real     The site's real active plugins.
 * @param string[] $session  The session's simulated list.
 * @param callable $callback Callback.
 * @return mixed
 */
$dbgtbl_with_lists = static function ($real, $session, $callback) {
	$real_filter = static function () use ($real) {
		return $real;
	};
	$session_filter = static function ($value) use ($session) {
		return DBGTBL_Session::is_bypassed() ? $value : $session;
	};

	add_filter('option_active_plugins', $real_filter, -1000);
	add_filter('option_active_plugins', $session_filter, 1000);

	try {
		return $callback();
	} finally {
		remove_filter('option_active_plugins', $real_filter, -1000);
		remove_filter('option_active_plugins', $session_filter, 1000);
	}
};

return array(
	'apply_list_change adds and removes without duplicates' => static function () {
		DBGTBL_Test::same(array('a', 'c', 'd'), DBGTBL_Session::apply_list_change(array('a', 'b', 'c'), array('d', 'a'), array('b')));
		DBGTBL_Test::same(array('a'), DBGTBL_Session::apply_list_change(array('a'), array(), array('x')), 'removing a missing plugin is harmless');
	},

	'without_filters restores the flag, even after an exception' => static function () {
		DBGTBL_Test::same(false, DBGTBL_Session::is_bypassed(), 'off by default');
		DBGTBL_Test::same(true, DBGTBL_Session::without_filters(array('DBGTBL_Session', 'is_bypassed')), 'on inside');
		try {
			DBGTBL_Session::without_filters(static function () {
				throw new RuntimeException('x');
			});
		} catch (RuntimeException $e) {
			unset($e);
		}
		DBGTBL_Test::same(false, DBGTBL_Session::is_bypassed(), 'off again after an exception');
	},

	'deactivating during a session only removes that plugin from the real list' => static function () use ($dbgtbl_with_lists) {
		$real = array('me.php', 'a/a.php', 'b/b.php', 'c/c.php');
		$session = array('me.php', 'a/a.php');

		$saved = $dbgtbl_with_lists($real, $session, static function () use ($session) {
			// What deactivate_plugins('a/a.php') would save: the session list minus a.
			return DBGTBL_Session::guard_active_plugins(array('me.php'), $session);
		});

		DBGTBL_Test::same(array('me.php', 'b/b.php', 'c/c.php'), $saved, 'b and c stay active');
	},

	'activating during a session adds to the real list' => static function () use ($dbgtbl_with_lists) {
		$real = array('me.php', 'a/a.php', 'b/b.php');
		$session = array('me.php');

		$saved = $dbgtbl_with_lists($real, $session, static function () use ($session) {
			return DBGTBL_Session::guard_active_plugins(array('me.php', 'new/new.php'), $session);
		});

		DBGTBL_Test::same(array('me.php', 'a/a.php', 'b/b.php', 'new/new.php'), $saved);
	},

	'saves pass through unchanged when no session is filtering' => static function () use ($dbgtbl_with_lists) {
		$real = array('me.php', 'a/a.php');

		$saved = $dbgtbl_with_lists($real, $real, static function () use ($real) {
			return DBGTBL_Session::guard_active_plugins(array('me.php'), $real);
		});

		DBGTBL_Test::same(array('me.php'), $saved);
	},

	'saves made with filters bypassed are not altered' => static function () use ($dbgtbl_with_lists) {
		$saved = $dbgtbl_with_lists(array('me.php', 'a/a.php'), array('me.php'), static function () {
			return DBGTBL_Session::without_filters(static function () {
				return DBGTBL_Session::guard_active_plugins(array('me.php'), array('me.php', 'a/a.php'));
			});
		});

		DBGTBL_Test::same(array('me.php'), $saved);
	},
);
