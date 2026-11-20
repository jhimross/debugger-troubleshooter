<?php
/**
 * Troubleshooting session storage shared by Troubleshooting Mode and the Conflict Checker.
 *
 * A session is a random token stored in an HttpOnly cookie that maps to an entry in the
 * `dbgtbl_sessions` option. The entry holds the simulated theme and plugin lists for that
 * browser only. A small MU plugin applies the plugin list before regular plugins load.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Session helpers. All methods are static so every feature reads the same state.
 */
class DBGTBL_Session
{
	/**
	 * Troubleshooting session cookie name.
	 */
	const COOKIE = 'wp_debug_troubleshoot_mode';

	/**
	 * Option holding all sessions, keyed by token.
	 */
	const OPTION = 'dbgtbl_sessions';

	/**
	 * Filename of the MU plugin that applies the session's plugin list early.
	 */
	const MU_FILE = 'debugger-troubleshooter-mu.php';

	/**
	 * Global flag that makes the session filters (here and in the MU plugin) return the real value.
	 */
	const BYPASS_GLOBAL = 'dbgtbl_bypass_session';

	/**
	 * Runs a callback with the session's plugin filters switched off, so it sees and changes
	 * the site's real active plugins.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Callback's return value.
	 */
	public static function without_filters($callback)
	{
		$previous = !empty($GLOBALS[self::BYPASS_GLOBAL]);
		$GLOBALS['dbgtbl_bypass_session'] = true;

		try {
			return call_user_func($callback);
		} finally {
			$GLOBALS['dbgtbl_bypass_session'] = $previous;
		}
	}

	/**
	 * Whether the session filters are currently switched off.
	 *
	 * @return bool
	 */
	public static function is_bypassed()
	{
		return !empty($GLOBALS[self::BYPASS_GLOBAL]);
	}

	/**
	 * Hooks that stop a troubleshooting session from overwriting the site's real plugin list.
	 */
	public static function register_write_guards()
	{
		add_filter('pre_update_option_active_plugins', array(__CLASS__, 'guard_active_plugins'), 0, 2);
		add_filter('pre_update_site_option_active_sitewide_plugins', array(__CLASS__, 'guard_sitewide_plugins'), 0, 2);
	}

	/**
	 * When the active plugin list is saved while a session is simulating a different list
	 * (for example a plugin is activated from the Plugins screen during troubleshooting),
	 * applies only the change that was made to the real list instead of saving the simulated one.
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Old value, as filtered by the session.
	 * @return mixed
	 */
	public static function guard_active_plugins($value, $old_value)
	{
		if (self::is_bypassed() || !is_array($value) || !is_array($old_value)) {
			return $value;
		}

		$real = self::without_filters(static function () {
			return (array) get_option('active_plugins', array());
		});

		if ($real === $old_value) {
			return $value; // No session filtering on this request.
		}

		$added = array_diff($value, $old_value);
		$removed = array_diff($old_value, $value);

		return self::apply_list_change($real, $added, $removed);
	}

	/**
	 * Multisite version of guard_active_plugins() for network-active plugins (keyed by basename).
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Old value, as filtered by the session.
	 * @return mixed
	 */
	public static function guard_sitewide_plugins($value, $old_value)
	{
		if (self::is_bypassed() || !is_array($value) || !is_array($old_value)) {
			return $value;
		}

		$real = self::without_filters(static function () {
			return (array) get_site_option('active_sitewide_plugins', array());
		});

		if (array_keys($real) === array_keys($old_value)) {
			return $value;
		}

		foreach (array_diff_key($old_value, $value) as $plugin_file => $time) {
			unset($real[$plugin_file]);
		}
		foreach (array_diff_key($value, $old_value) as $plugin_file => $time) {
			$real[$plugin_file] = $time;
		}

		return $real;
	}

	/**
	 * Applies added and removed entries to a plugin list.
	 *
	 * @param string[] $list    Plugin list.
	 * @param string[] $added   Plugins to add.
	 * @param string[] $removed Plugins to remove.
	 * @return string[]
	 */
	public static function apply_list_change($list, $added, $removed)
	{
		return array_values(array_unique(array_merge(array_diff($list, $removed), $added)));
	}

	/**
	 * Returns the real authenticated user ID, ignoring user simulation.
	 *
	 * @return int
	 */
	public static function real_user_id()
	{
		if (!function_exists('wp_validate_auth_cookie') && defined('ABSPATH') && defined('WPINC') && file_exists(ABSPATH . WPINC . '/pluggable.php')) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		if (function_exists('wp_validate_auth_cookie')) {
			$user_id = wp_validate_auth_cookie('', 'logged_in');
			if ($user_id) {
				return (int) $user_id;
			}
		}

		return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
	}

	/**
	 * Real user ID, falling back to the current user when the auth cookie is unavailable.
	 *
	 * @return int
	 */
	public static function creator_id()
	{
		$user_id = self::real_user_id();
		return $user_id ? $user_id : get_current_user_id();
	}

	/**
	 * Returns the session token from the request cookie.
	 *
	 * @return string|false
	 */
	public static function cookie_token()
	{
		return isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : false;
	}

	/**
	 * Returns all stored sessions.
	 *
	 * @return array
	 */
	public static function all()
	{
		$sessions = get_option(self::OPTION, array());
		return is_array($sessions) ? $sessions : array();
	}

	/**
	 * Saves all sessions.
	 *
	 * @param array $sessions Sessions keyed by token.
	 */
	public static function save_all($sessions)
	{
		update_option(self::OPTION, $sessions);
	}

	/**
	 * Checks whether a session state is well formed, unexpired and owned by the current user.
	 *
	 * @param mixed $state Session state.
	 * @return bool
	 */
	public static function is_valid_state($state)
	{
		if (!is_array($state)) {
			return false;
		}

		$created_by = isset($state['created_by']) ? (int) $state['created_by'] : 0;
		$timestamp = isset($state['timestamp']) ? (int) $state['timestamp'] : 0;
		$real_user_id = self::real_user_id();

		if (!$created_by || !$timestamp || !$real_user_id) {
			return false;
		}

		return $created_by === $real_user_id && (time() - $timestamp) <= DAY_IN_SECONDS;
	}

	/**
	 * Gets a session token and state if valid for the current user.
	 *
	 * @param string|false $token Optional token. Defaults to the cookie token.
	 * @return array{0:string|false,1:array|false} Token and session state.
	 */
	public static function get_valid($token = false)
	{
		if (!$token) {
			$token = self::cookie_token();
		}

		if (!$token) {
			return array(false, false);
		}

		$sessions = self::all();
		if (!isset($sessions[$token]) || !self::is_valid_state($sessions[$token])) {
			return array($token, false);
		}

		return array($token, $sessions[$token]);
	}

	/**
	 * Builds a fresh session state from the site's current theme and plugins.
	 *
	 * @return array
	 */
	public static function new_state()
	{
		return array(
			'theme' => get_stylesheet(),
			'plugins' => (array) get_option('active_plugins', array()),
			'sitewide_plugins' => self::network_active_plugins(),
			'timestamp' => time(),
			'created_by' => self::creator_id(),
		);
	}

	/**
	 * Creates a new session, stores it and sets the cookie.
	 *
	 * @return string|false Token, or false if the MU plugin could not be installed.
	 */
	public static function create()
	{
		self::prune_expired();

		// Create the MU plugin before storing a session so failures do not leave a half-enabled state.
		if (!self::install_mu_plugin()) {
			return false;
		}

		$token = wp_generate_password(64, false);
		$sessions = self::all();
		$sessions[$token] = self::new_state();
		self::save_all($sessions);
		self::set_cookie($token);

		return $token;
	}

	/**
	 * Deletes the current browser's session, clears the cookie and removes the MU plugin when idle.
	 *
	 * @param string|false $token Token to delete. Defaults to the cookie token.
	 */
	public static function destroy($token = false)
	{
		if (!$token) {
			$token = self::cookie_token();
		}

		if ($token) {
			$sessions = self::all();
			if (isset($sessions[$token])) {
				unset($sessions[$token]);
				self::save_all($sessions);
			}
		}

		self::prune_expired();
		if (empty(self::all())) {
			self::remove_mu_plugin();
		}

		self::clear_cookie();
	}

	/**
	 * Removes expired or legacy sessions.
	 */
	public static function prune_expired()
	{
		$sessions = self::all();
		if (empty($sessions)) {
			return;
		}

		$changed = false;
		$now = time();

		foreach ($sessions as $token => $state) {
			$created_by = is_array($state) && isset($state['created_by']) ? (int) $state['created_by'] : 0;
			$timestamp = is_array($state) && isset($state['timestamp']) ? (int) $state['timestamp'] : 0;

			if (!$created_by || !$timestamp || ($now - $timestamp) > DAY_IN_SECONDS) {
				unset($sessions[$token]);
				$changed = true;
			}
		}

		if ($changed) {
			self::save_all($sessions);

			if (empty($sessions)) {
				self::remove_mu_plugin();
			}
		}
	}

	/**
	 * Splits a list of plugin files into site-active and network-active lists.
	 *
	 * @param string[] $plugin_files Plugin basenames.
	 * @return array{0:string[],1:string[]} Site plugins and network plugins.
	 */
	public static function split_by_network($plugin_files)
	{
		$network = self::network_active_plugins();
		$site_plugins = array();
		$sitewide_plugins = array();

		foreach ($plugin_files as $plugin_file) {
			if (is_multisite() && in_array($plugin_file, $network, true)) {
				$sitewide_plugins[] = $plugin_file;
			} else {
				$site_plugins[] = $plugin_file;
			}
		}

		return array($site_plugins, $sitewide_plugins);
	}

	/**
	 * Returns network-active plugin basenames (empty on single site).
	 *
	 * @return string[]
	 */
	public static function network_active_plugins()
	{
		return is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
	}

	/**
	 * Sets an HttpOnly cookie for this plugin.
	 *
	 * @param string $name  Cookie name.
	 * @param string $value Cookie value.
	 */
	public static function send_cookie($name, $value)
	{
		setcookie($name, $value, array(
			'expires' => '' === $value ? time() - HOUR_IN_SECONDS : time() + DAY_IN_SECONDS,
			'path' => COOKIEPATH,
			'domain' => COOKIE_DOMAIN,
			'samesite' => 'Lax',
			'httponly' => true,
			'secure' => is_ssl(),
		));
	}

	/**
	 * Sets the session cookie.
	 *
	 * @param string $token Session token.
	 */
	public static function set_cookie($token)
	{
		self::send_cookie(self::COOKIE, $token);
	}

	/**
	 * Expires the session cookie.
	 */
	public static function clear_cookie()
	{
		self::send_cookie(self::COOKIE, '');
	}

	/**
	 * Installs the MU plugin used to intercept active plugins before standard plugins are loaded.
	 *
	 * @return bool
	 */
	public static function install_mu_plugin()
	{
		$mu_dir = WPMU_PLUGIN_DIR;
		if (!is_dir($mu_dir) && !wp_mkdir_p($mu_dir)) {
			return false;
		}

		$basename = DBGTBL_BASENAME;
		$mu_content = "<?php
/**
 * Plugin Name: Debugger & Troubleshooter (MU Plugin)
 * Description: Intercepts active plugins to apply troubleshooting mode correctly.
 * Version: 1.2
 * Author: Jhimross
 */

if (!defined('ABSPATH')) {
	exit;
}

// Ensure the token from cookie exists and maps to an active, non-expired session.
if (isset(\$_COOKIE['wp_debug_troubleshoot_mode'])) {
	\$token = sanitize_text_field(wp_unslash(\$_COOKIE['wp_debug_troubleshoot_mode']));
	\$sessions = get_option('dbgtbl_sessions', array());

	if (isset(\$sessions[\$token]) && is_array(\$sessions[\$token]) && !empty(\$sessions[\$token]['created_by']) && isset(\$sessions[\$token]['timestamp']) && (time() - (int) \$sessions[\$token]['timestamp']) <= DAY_IN_SECONDS) {
		// Replace active plugins for this request
		add_filter('option_active_plugins', function (\$plugins) use (\$sessions, \$token) {
			if (empty(\$GLOBALS['dbgtbl_bypass_session']) && isset(\$sessions[\$token]['plugins'])) {
				\$active = (array) \$sessions[\$token]['plugins'];
				if (!in_array('{$basename}', \$active, true)) {
					\$active[] = '{$basename}';
				}
				return \$active;
			}
			return \$plugins;
		}, 0);

		if (is_multisite()) {
			add_filter('site_option_active_sitewide_plugins', function (\$plugins) use (\$sessions, \$token) {
				if (empty(\$GLOBALS['dbgtbl_bypass_session']) && isset(\$sessions[\$token]['sitewide_plugins'])) {
					\$new_plugins = array();
					foreach (\$sessions[\$token]['sitewide_plugins'] as \$plugin_file) {
						\$new_plugins[\$plugin_file] = time();
					}
					return \$new_plugins;
				}
				return \$plugins;
			}, 0);
		}
	}
}
";

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Runs before WP_Filesystem credentials can be requested; failure is reported to the user.
		return false !== @file_put_contents($mu_dir . '/' . self::MU_FILE, $mu_content);
	}

	/**
	 * Rewrites the MU plugin if it was written by an older version of this plugin.
	 */
	public static function refresh_mu_plugin()
	{
		$mu_file = WPMU_PLUGIN_DIR . '/' . self::MU_FILE;
		if (!file_exists($mu_file)) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file.
		$content = (string) file_get_contents($mu_file);
		if (false === strpos($content, self::BYPASS_GLOBAL)) {
			self::install_mu_plugin();
		}
	}

	/**
	 * Removes the MU plugin when no site has an active session.
	 */
	public static function remove_mu_plugin()
	{
		if (is_multisite()) {
			$site_ids = get_sites(array('fields' => 'ids'));

			foreach ($site_ids as $site_id) {
				switch_to_blog($site_id);
				$sessions = get_option(self::OPTION, array());
				restore_current_blog();

				if (!empty($sessions)) {
					return;
				}
			}
		}

		$mu_file = WPMU_PLUGIN_DIR . '/' . self::MU_FILE;
		if (file_exists($mu_file)) {
			wp_delete_file($mu_file);
		}
	}
}
