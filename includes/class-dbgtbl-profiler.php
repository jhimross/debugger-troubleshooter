<?php
/**
 * Performance Profiler: per-request timing, query, HTTP, and plugin load analysis.
 *
 * Opt-in per administrator. Only that administrator's own requests are profiled,
 * so visitors and other users are unaffected.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Performance profiler.
 */
class DBGTBL_Profiler
{
	const USERS_OPTION = 'dbgtbl_profiler_users';
	const HISTORY_META = 'dbgtbl_profiler_history';
	const MAX_HISTORY = 25;
	const DURATION = DAY_IN_SECONDS;
	const SLOW_QUERY_MS = 50;

	/**
	 * Milestone hooks recorded on the request timeline.
	 *
	 * @var string[]
	 */
	private static $milestone_hooks = array(
		'setup_theme',
		'after_setup_theme',
		'init',
		'wp_loaded',
		'admin_init',
		'rest_api_init',
		'parse_request',
		'wp',
		'template_redirect',
		'wp_head',
		'admin_head',
		'wp_footer',
		'admin_footer',
	);

	/**
	 * Whether the current request is being profiled.
	 *
	 * @var bool
	 */
	private $active = false;

	/**
	 * Profiled user ID.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Request start time.
	 *
	 * @var float
	 */
	private $start = 0.0;

	/**
	 * Milestone name => seconds since start.
	 *
	 * @var array
	 */
	private $milestones = array();

	/**
	 * Completed HTTP calls.
	 *
	 * @var array
	 */
	private $http = array();

	/**
	 * In-flight HTTP calls.
	 *
	 * @var array
	 */
	private $http_pending = array();

	/**
	 * Whether SAVEQUERIES was usable for this request.
	 *
	 * @var bool
	 */
	private $queries_available = true;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		add_action('plugins_loaded', array($this, 'maybe_start'), 0);
		add_action('wp_ajax_debug_troubleshoot_profiler_toggle', array($this, 'ajax_toggle'));
		add_action('wp_ajax_debug_troubleshoot_profiler_clear', array($this, 'ajax_clear'));
	}

	/**
	 * Returns enabled user IDs mapped to expiry timestamps (expired entries removed).
	 *
	 * @return array
	 */
	public static function get_users()
	{
		$users = get_option(self::USERS_OPTION, array());
		if (!is_array($users)) {
			return array();
		}

		$now = time();
		$valid = array();
		foreach ($users as $user_id => $expires) {
			if ((int) $expires > $now) {
				$valid[(int) $user_id] = (int) $expires;
			}
		}

		return $valid;
	}

	/**
	 * Whether any administrator has the profiler on.
	 *
	 * @return bool
	 */
	public static function is_globally_active()
	{
		return !empty(self::get_users());
	}

	/**
	 * Returns the profiler expiry for a user, or 0 when off.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_expiry($user_id)
	{
		$users = self::get_users();

		return isset($users[$user_id]) ? $users[$user_id] : 0;
	}

	/**
	 * Decides whether to profile this request and wires up the collectors.
	 */
	public function maybe_start()
	{
		if (!self::is_globally_active()) {
			return;
		}

		$user_id = (int) wp_validate_auth_cookie('', 'logged_in');
		if (!$user_id || !self::get_expiry($user_id)) {
			return;
		}

		if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
			return;
		}

		if (wp_doing_ajax()) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Read-only check of the AJAX action name to skip noise.
			$action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
			if ('heartbeat' === $action || 0 === strpos($action, 'debug_troubleshoot_')) {
				return;
			}
		}

		$this->active = true;
		$this->user_id = $user_id;

		global $timestart;
		$this->start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : (float) $timestart;
		$this->milestones['plugins_loaded'] = microtime(true) - $this->start;

		if (!defined('SAVEQUERIES')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant, only defined for the profiling administrator's own requests.
			define('SAVEQUERIES', true);
		}
		$this->queries_available = SAVEQUERIES;

		add_filter('log_query_custom_data', array($this, 'tag_query'), 10, 5);
		add_filter('pre_http_request', array($this, 'http_start'), PHP_INT_MAX, 3);
		add_action('http_api_debug', array($this, 'http_end'), 10, 5);

		foreach (self::$milestone_hooks as $hook) {
			add_action($hook, function () use ($hook) {
				if (!isset($this->milestones[$hook])) {
					$this->milestones[$hook] = microtime(true) - $this->start;
				}
			}, PHP_INT_MIN);
		}

		add_action('admin_bar_menu', array($this, 'admin_bar'), 1000);
		add_action('shutdown', array($this, 'finish'), PHP_INT_MAX);
	}

	/**
	 * Adds the responsible component to each logged query.
	 *
	 * @param array  $query_data      Custom query data.
	 * @param string $query           SQL.
	 * @param float  $query_time      Execution time.
	 * @param string $query_callstack Call stack string.
	 * @param float  $query_start     Start time.
	 * @return array
	 */
	public function tag_query($query_data, $query, $query_time, $query_callstack, $query_start)
	{
		$query_data['dbgtbl_component'] = DBGTBL_Components::from_backtrace();

		return $query_data;
	}

	/**
	 * Records the start of an outgoing HTTP request.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error
	 */
	public function http_start($pre, $args, $url)
	{
		$entry = array(
			'url' => $this->redact_url($url),
			'method' => isset($args['method']) ? strtoupper((string) $args['method']) : 'GET',
			'component' => DBGTBL_Components::from_backtrace(),
			'start' => microtime(true),
		);

		if (false !== $pre) {
			$entry['duration'] = 0;
			$entry['status'] = __('Short-circuited by pre_http_request', 'debugger-troubleshooter');
			unset($entry['start']);
			$this->http[] = $entry;
		} else {
			$this->http_pending[] = $entry + array('raw_url' => $url);
		}

		return $pre;
	}

	/**
	 * Records the end of an outgoing HTTP request.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @param string         $context  Context, "response".
	 * @param string         $class    Transport class.
	 * @param array          $args     Request arguments.
	 * @param string         $url      URL.
	 */
	public function http_end($response, $context, $class, $args, $url)
	{
		if ('response' !== $context) {
			return;
		}

		for ($i = count($this->http_pending) - 1; $i >= 0; $i--) {
			if ($this->http_pending[$i]['raw_url'] === $url) {
				$entry = $this->http_pending[$i];
				array_splice($this->http_pending, $i, 1);

				$entry['duration'] = round((microtime(true) - $entry['start']) * 1000, 1);
				$entry['status'] = is_wp_error($response) ? $response->get_error_message() : (string) wp_remote_retrieve_response_code($response);
				$entry['error'] = is_wp_error($response);
				unset($entry['start'], $entry['raw_url']);

				$this->http[] = $entry;
				return;
			}
		}
	}

	/**
	 * Removes query strings (which often carry API keys) from URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function redact_url($url)
	{
		$parts = explode('?', (string) $url, 2);

		return isset($parts[1]) ? $parts[0] . '?…' : $parts[0];
	}

	/**
	 * Summarizes $wpdb->queries.
	 *
	 * @return array
	 */
	private function summarize_queries()
	{
		global $wpdb;

		$summary = array(
			'count' => 0,
			'time' => 0.0,
			'by_component' => array(),
			'slowest' => array(),
			'duplicates' => array(),
			'slow_count' => 0,
		);

		if (empty($wpdb->queries) || !is_array($wpdb->queries)) {
			return $summary;
		}

		$by_sql = array();
		$all = array();

		foreach ($wpdb->queries as $q) {
			$sql = isset($q[0]) ? (string) $q[0] : '';
			$time = isset($q[1]) ? (float) $q[1] : 0.0;
			$caller = isset($q[2]) ? (string) $q[2] : '';
			$component = isset($q[4]['dbgtbl_component']) ? $q[4]['dbgtbl_component'] : 'core';

			if (DBGTBL_Components::is_self($component)) {
				continue;
			}

			$summary['count']++;
			$summary['time'] += $time;

			if (!isset($summary['by_component'][$component])) {
				$summary['by_component'][$component] = array('count' => 0, 'time' => 0.0);
			}
			$summary['by_component'][$component]['count']++;
			$summary['by_component'][$component]['time'] += $time;

			if ($time * 1000 >= self::SLOW_QUERY_MS) {
				$summary['slow_count']++;
			}

			$closest = $this->closest_caller($caller);

			$all[] = array(
				'sql' => $sql,
				'time' => $time,
				'caller' => $closest ? $closest : '',
				'component' => $component,
			);

			$normalized = trim(preg_replace('/\s+/', ' ', $sql));
			if (!isset($by_sql[$normalized])) {
				$by_sql[$normalized] = array('count' => 0, 'time' => 0.0, 'component' => $component, 'caller' => $closest ? $closest : '');
			}
			$by_sql[$normalized]['count']++;
			$by_sql[$normalized]['time'] += $time;
		}

		usort($all, static function ($a, $b) {
			return $b['time'] <=> $a['time'];
		});

		foreach (array_slice($all, 0, 10) as $q) {
			$summary['slowest'][] = array(
				'sql' => $this->truncate($q['sql'], 600),
				'ms' => round($q['time'] * 1000, 2),
				'caller' => $q['caller'],
				'component' => $q['component'],
			);
		}

		$dupes = array_filter($by_sql, static function ($d) {
			return $d['count'] > 1;
		});
		uasort($dupes, static function ($a, $b) {
			return $b['count'] <=> $a['count'];
		});

		foreach (array_slice($dupes, 0, 10, true) as $sql => $d) {
			$summary['duplicates'][] = array(
				'sql' => $this->truncate($sql, 600),
				'count' => $d['count'],
				'ms' => round($d['time'] * 1000, 2),
				'caller' => $d['caller'],
				'component' => $d['component'],
			);
		}

		foreach ($summary['by_component'] as &$c) {
			$c['time'] = round($c['time'] * 1000, 2);
		}
		unset($c);

		uasort($summary['by_component'], static function ($a, $b) {
			return $b['time'] <=> $a['time'];
		});

		$summary['time'] = round($summary['time'] * 1000, 2);

		return $summary;
	}

	/**
	 * Returns the closest meaningful caller from a wpdb call stack string, skipping
	 * methods of the database class itself (e.g. WP_SQLite_DB->_do_query).
	 *
	 * @param string $callstack Comma separated call stack, outermost first.
	 * @return string
	 */
	private function closest_caller($callstack)
	{
		static $db_classes = null;

		if (null === $db_classes) {
			global $wpdb;
			$db_classes = array('wpdb');
			if (is_object($wpdb)) {
				$db_classes = array_merge($db_classes, array(get_class($wpdb)), array_values(class_parents($wpdb)));
			}
		}

		$callers = array_reverse(array_map('trim', explode(',', $callstack)));

		foreach ($callers as $call) {
			$class = strstr($call, '->', true);
			if (false === $class || !in_array($class, $db_classes, true)) {
				return $call;
			}
		}

		return isset($callers[0]) ? $callers[0] : '';
	}

	/**
	 * Truncates a string.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Max length.
	 * @return string
	 */
	private function truncate($text, $limit)
	{
		return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
	}

	/**
	 * Describes the current request type.
	 *
	 * @return string
	 */
	private function request_type()
	{
		if (wp_doing_ajax()) {
			return 'ajax';
		}
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return 'rest';
		}
		if (is_admin()) {
			return 'admin';
		}

		return 'front';
	}

	/**
	 * Builds the current request's profile.
	 *
	 * @return array
	 */
	private function build_profile()
	{
		$total = microtime(true) - $this->start;

		$milestones = array();
		asort($this->milestones);
		foreach ($this->milestones as $hook => $seconds) {
			$milestones[$hook] = round($seconds * 1000, 1);
		}

		$plugin_load = array();
		$early_source = '';
		if (!empty($GLOBALS['dbgtbl_profiler_early']['plugins'])) {
			$plugin_load = $GLOBALS['dbgtbl_profiler_early']['plugins'];
			arsort($plugin_load);
			$plugin_load = array_slice($plugin_load, 0, 25, true);
			$early_source = $GLOBALS['dbgtbl_profiler_early']['source'];
		}

		$http_time = 0;
		foreach ($this->http as $call) {
			$http_time += $call['duration'];
		}

		$uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		$method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'get';

		return array(
			'time' => time(),
			'uri' => $this->truncate($uri, 300),
			'method' => strtoupper($method),
			'type' => $this->request_type(),
			'status' => (int) http_response_code(),
			'total_ms' => round($total * 1000, 1),
			'memory' => memory_get_peak_usage(true),
			'memory_limit' => ini_get('memory_limit'),
			'queries_available' => $this->queries_available,
			'queries' => $this->summarize_queries(),
			'http' => array_slice($this->http, 0, 30),
			'http_count' => count($this->http),
			'http_ms' => round($http_time, 1),
			'milestones' => $milestones,
			'plugin_load' => $plugin_load,
			'plugin_load_source' => $early_source,
		);
	}

	/**
	 * Saves the profile at the very end of the request.
	 */
	public function finish()
	{
		if (!$this->active) {
			return;
		}

		$this->milestones['shutdown'] = microtime(true) - $this->start;
		$profile = $this->build_profile();

		// Stop tagging queries made while saving.
		remove_filter('log_query_custom_data', array($this, 'tag_query'), 10);
		$this->active = false;

		$history = get_user_meta($this->user_id, self::HISTORY_META, true);
		if (!is_array($history)) {
			$history = array();
		}

		array_unshift($history, $profile);
		update_user_meta($this->user_id, self::HISTORY_META, array_slice($history, 0, self::MAX_HISTORY));
	}

	/**
	 * Shows live request metrics in the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function admin_bar($wp_admin_bar)
	{
		if (!$this->active) {
			return;
		}

		global $wpdb;

		$elapsed = microtime(true) - $this->start;
		$query_count = is_array($wpdb->queries) ? count($wpdb->queries) : (int) $wpdb->num_queries;
		$query_ms = 0.0;
		$by_component = array();

		if (is_array($wpdb->queries)) {
			foreach ($wpdb->queries as $q) {
				$query_ms += (float) $q[1];
				$component = isset($q[4]['dbgtbl_component']) ? $q[4]['dbgtbl_component'] : 'core';
				if (!DBGTBL_Components::is_core($component)) {
					$by_component[$component] = (isset($by_component[$component]) ? $by_component[$component] : 0) + (float) $q[1];
				}
			}
		}

		$title = sprintf(
			'<span class="ab-icon dashicons dashicons-performance" style="margin-top:2px"></span><span class="ab-label">%s · %s · %s</span>',
			esc_html(number_format_i18n($elapsed, 2) . 's'),
			/* translators: %s: Number of database queries. */
			esc_html(sprintf(_n('%s query', '%s queries', $query_count, 'debugger-troubleshooter'), number_format_i18n($query_count))),
			esc_html(size_format(memory_get_peak_usage(true)))
		);

		$wp_admin_bar->add_node(array(
			'id' => 'dbgtbl-profiler',
			'title' => $title,
			'href' => admin_url('tools.php?page=debugger-troubleshooter#performance'),
			'meta' => array('title' => __('Debugger & Troubleshooter: Performance Profiler', 'debugger-troubleshooter')),
		));

		$wp_admin_bar->add_node(array(
			'parent' => 'dbgtbl-profiler',
			'id' => 'dbgtbl-profiler-queries',
			/* translators: 1: Query count, 2: Total query time in ms. */
			'title' => esc_html(sprintf(__('Database: %1$s queries in %2$s ms', 'debugger-troubleshooter'), number_format_i18n($query_count), number_format_i18n($query_ms * 1000, 1))),
		));

		if (!empty($by_component)) {
			arsort($by_component);
			$top_key = key($by_component);
			$wp_admin_bar->add_node(array(
				'parent' => 'dbgtbl-profiler',
				'id' => 'dbgtbl-profiler-top',
				/* translators: 1: Plugin/theme name, 2: Query time in ms. */
				'title' => esc_html(sprintf(__('Most query time: %1$s (%2$s ms)', 'debugger-troubleshooter'), DBGTBL_Components::label($top_key), number_format_i18n($by_component[$top_key] * 1000, 1))),
			));
		}

		$http_ms = 0;
		foreach ($this->http as $call) {
			$http_ms += $call['duration'];
		}
		$wp_admin_bar->add_node(array(
			'parent' => 'dbgtbl-profiler',
			'id' => 'dbgtbl-profiler-http',
			/* translators: 1: HTTP request count, 2: Total time in ms. */
			'title' => esc_html(sprintf(__('HTTP API: %1$s requests in %2$s ms', 'debugger-troubleshooter'), number_format_i18n(count($this->http)), number_format_i18n($http_ms, 1))),
		));

		$wp_admin_bar->add_node(array(
			'parent' => 'dbgtbl-profiler',
			'id' => 'dbgtbl-profiler-report',
			'title' => esc_html__('View full report →', 'debugger-troubleshooter'),
			'href' => admin_url('tools.php?page=debugger-troubleshooter#performance'),
		));
	}

	/**
	 * AJAX: toggles the profiler for the current administrator.
	 */
	public function ajax_toggle()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$user_id = (int) wp_validate_auth_cookie('', 'logged_in');
		if (!$user_id) {
			$user_id = get_current_user_id();
		}

		$users = self::get_users();
		$enable = empty($users[$user_id]);

		if ($enable) {
			$users[$user_id] = time() + self::DURATION;
		} else {
			unset($users[$user_id]);
		}

		update_option(self::USERS_OPTION, $users, false);

		$early_ok = DBGTBL_Early_MU::sync();

		if ($enable) {
			$message = __('Performance Profiler enabled for your account for the next 24 hours. Browse your site, then come back here to see the results.', 'debugger-troubleshooter');
			if (!$early_ok) {
				$message .= ' ' . __('Note: the early loader could not be written to wp-content/mu-plugins, so plugin load times will only cover plugins that load after this one.', 'debugger-troubleshooter');
			}
		} else {
			$message = __('Performance Profiler disabled.', 'debugger-troubleshooter');
		}

		wp_send_json_success(array(
			'message' => $message,
			'enabled' => $enable,
		));
	}

	/**
	 * AJAX: clears the current administrator's profile history.
	 */
	public function ajax_clear()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$user_id = (int) wp_validate_auth_cookie('', 'logged_in');
		delete_user_meta($user_id ? $user_id : get_current_user_id(), self::HISTORY_META);

		wp_send_json_success(array('message' => __('Profile history cleared.', 'debugger-troubleshooter')));
	}

	/**
	 * Formats milliseconds.
	 *
	 * @param float $ms Milliseconds.
	 * @return string
	 */
	private static function ms($ms)
	{
		return $ms >= 1000 ? number_format_i18n($ms / 1000, 2) . ' s' : number_format_i18n($ms, 1) . ' ms';
	}

	/**
	 * Returns a CSS class for a duration, for colour coding.
	 *
	 * @param float $ms        Milliseconds.
	 * @param float $warn_at   Warning threshold.
	 * @param float $danger_at Danger threshold.
	 * @return string
	 */
	private static function rating($ms, $warn_at, $danger_at)
	{
		if ($ms >= $danger_at) {
			return 'dbgtbl-bad';
		}

		return $ms >= $warn_at ? 'dbgtbl-warn' : 'dbgtbl-good';
	}

	/**
	 * Renders the Performance tab.
	 */
	public function render_tab()
	{
		$user_id = (int) wp_validate_auth_cookie('', 'logged_in');
		if (!$user_id) {
			$user_id = get_current_user_id();
		}

		$expiry = self::get_expiry($user_id);
		$history = get_user_meta($user_id, self::HISTORY_META, true);
		$history = is_array($history) ? $history : array();
		$type_labels = array(
			'front' => __('Front end', 'debugger-troubleshooter'),
			'admin' => __('Admin', 'debugger-troubleshooter'),
			'ajax' => __('AJAX', 'debugger-troubleshooter'),
			'rest' => __('REST API', 'debugger-troubleshooter'),
		);
		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('Performance Profiler', 'debugger-troubleshooter'); ?></h2>
				<button id="profiler-toggle" class="button button-large <?php echo $expiry ? 'button-danger' : 'button-primary'; ?>" data-enabled="<?php echo $expiry ? '1' : '0'; ?>">
					<?php echo $expiry ? esc_html__('Disable Profiler', 'debugger-troubleshooter') : esc_html__('Enable Profiler', 'debugger-troubleshooter'); ?>
				</button>
			</div>
			<div class="section-content">
				<p class="description">
					<?php esc_html_e('Find out what makes a page slow. While enabled, every page you load (front end, admin, AJAX and REST) records its load time, memory, database queries, external HTTP requests and per-plugin load time. Only your own requests are profiled; visitors are not affected.', 'debugger-troubleshooter'); ?>
				</p>

				<?php if ($expiry) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								/* translators: %s: Human readable time remaining, e.g. "23 hours". */
								esc_html__('Profiling is on. A live summary appears in the admin bar on every page. It turns off automatically in %s.', 'debugger-troubleshooter'),
								esc_html(human_time_diff(time(), $expiry))
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if (defined('SAVEQUERIES') && !SAVEQUERIES) : ?>
					<div class="notice notice-warning inline">
						<p><?php esc_html_e('SAVEQUERIES is defined as false in wp-config.php, so database query details cannot be collected. Remove that line to see query data.', 'debugger-troubleshooter'); ?></p>
					</div>
				<?php endif; ?>

				<div class="dbgtbl-profiler-toolbar">
					<h3><?php esc_html_e('Recent requests', 'debugger-troubleshooter'); ?></h3>
					<div>
						<button type="button" class="button" onclick="window.location.reload();"><?php esc_html_e('Refresh', 'debugger-troubleshooter'); ?></button>
						<button id="profiler-clear" type="button" class="button" <?php disabled(empty($history)); ?>><?php esc_html_e('Clear History', 'debugger-troubleshooter'); ?></button>
					</div>
				</div>

				<?php if (empty($history)) : ?>
					<p class="dbgtbl-empty"><?php echo $expiry ? esc_html__('No profiles yet. Visit a few pages of your site, then refresh this page.', 'debugger-troubleshooter') : esc_html__('Enable the profiler to start collecting data.', 'debugger-troubleshooter'); ?></p>
				<?php else : ?>
					<div class="dbgtbl-profiles">
						<div class="dbgtbl-profile-row dbgtbl-profile-head">
							<span><?php esc_html_e('Request', 'debugger-troubleshooter'); ?></span>
							<span><?php esc_html_e('Time', 'debugger-troubleshooter'); ?></span>
							<span><?php esc_html_e('Queries', 'debugger-troubleshooter'); ?></span>
							<span><?php esc_html_e('HTTP', 'debugger-troubleshooter'); ?></span>
							<span><?php esc_html_e('Memory', 'debugger-troubleshooter'); ?></span>
						</div>
						<?php foreach ($history as $index => $p) : ?>
							<?php $this->render_profile($p, $type_labels, 0 === $index); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a single profile.
	 *
	 * @param array $p           Profile.
	 * @param array $type_labels Request type labels.
	 * @param bool  $open        Whether the details are expanded.
	 */
	private function render_profile($p, $type_labels, $open)
	{
		$q = $p['queries'];
		$total = max(1, (float) $p['total_ms']);
		?>
		<details class="dbgtbl-profile" <?php echo $open ? 'open' : ''; ?>>
			<summary class="dbgtbl-profile-row">
				<span class="dbgtbl-profile-request">
					<span class="dbgtbl-chip"><?php echo esc_html(isset($type_labels[$p['type']]) ? $type_labels[$p['type']] : $p['type']); ?></span>
					<code><?php echo esc_html($p['method'] . ' ' . $p['uri']); ?></code>
					<small><?php
						/* translators: %s: Human readable time difference, e.g. "5 mins". */
						echo esc_html(sprintf(__('%s ago', 'debugger-troubleshooter'), human_time_diff($p['time'])));
						if (!empty($p['status'])) {
							echo ' · HTTP ' . esc_html($p['status']);
						}
					?></small>
				</span>
				<span class="<?php echo esc_attr(self::rating($p['total_ms'], 800, 2000)); ?>"><strong><?php echo esc_html(self::ms($p['total_ms'])); ?></strong></span>
				<span class="<?php echo esc_attr(self::rating($q['count'], 100, 300)); ?>"><?php echo esc_html(number_format_i18n($q['count'])); ?> <small>(<?php echo esc_html(self::ms($q['time'])); ?>)</small></span>
				<span class="<?php echo esc_attr(self::rating($p['http_ms'], 300, 1000)); ?>"><?php echo esc_html(number_format_i18n($p['http_count'])); ?> <small>(<?php echo esc_html(self::ms($p['http_ms'])); ?>)</small></span>
				<span><?php echo esc_html(size_format($p['memory'])); ?></span>
			</summary>

			<div class="dbgtbl-profile-body">
				<?php if (!empty($p['milestones'])) : ?>
					<h4><?php esc_html_e('Timeline', 'debugger-troubleshooter'); ?></h4>
					<div class="dbgtbl-timeline">
						<?php
						$prev = 0;
						foreach ($p['milestones'] as $hook => $at) :
							$width = max(0.5, (($at - $prev) / $total) * 100);
							?>
							<div class="dbgtbl-timeline-seg" style="width: <?php echo esc_attr(round($width, 2)); ?>%" title="<?php echo esc_attr(sprintf('%s: %s (+%s)', $hook, self::ms($at), self::ms($at - $prev))); ?>"></div>
							<?php
							$prev = $at;
						endforeach;
						?>
					</div>
					<div class="dbgtbl-timeline-legend">
						<?php
						$prev = 0;
						foreach ($p['milestones'] as $hook => $at) :
							?>
							<span><code><?php echo esc_html($hook); ?></code> <?php echo esc_html(self::ms($at)); ?> <small>(+<?php echo esc_html(self::ms($at - $prev)); ?>)</small></span>
							<?php
							$prev = $at;
						endforeach;
						?>
					</div>
				<?php endif; ?>

				<div class="dbgtbl-profile-grid">
					<?php if (!empty($q['by_component'])) : ?>
						<div>
							<h4><?php esc_html_e('Query time by plugin / theme', 'debugger-troubleshooter'); ?></h4>
							<table class="widefat striped dbgtbl-table">
								<thead><tr><th><?php esc_html_e('Component', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Queries', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Time', 'debugger-troubleshooter'); ?></th></tr></thead>
								<tbody>
									<?php
									$max = max(0.01, max(wp_list_pluck($q['by_component'], 'time')));
									foreach (array_slice($q['by_component'], 0, 15, true) as $key => $c) :
										?>
										<tr>
											<td><?php echo esc_html(DBGTBL_Components::label($key)); ?><div class="dbgtbl-bar"><span style="width: <?php echo esc_attr(round($c['time'] / $max * 100, 1)); ?>%"></span></div></td>
											<td><?php echo esc_html(number_format_i18n($c['count'])); ?></td>
											<td><?php echo esc_html(self::ms($c['time'])); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php elseif (empty($p['queries_available'])) : ?>
						<div><p class="description"><?php esc_html_e('Query details unavailable (SAVEQUERIES is disabled).', 'debugger-troubleshooter'); ?></p></div>
					<?php endif; ?>

					<?php if (!empty($p['plugin_load'])) : ?>
						<div>
							<h4><?php esc_html_e('Plugin load time', 'debugger-troubleshooter'); ?></h4>
							<table class="widefat striped dbgtbl-table">
								<thead><tr><th><?php esc_html_e('Plugin', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Load time', 'debugger-troubleshooter'); ?></th></tr></thead>
								<tbody>
									<?php
									$max = max(0.01, max($p['plugin_load']));
									foreach ($p['plugin_load'] as $key => $ms) :
										?>
										<tr>
											<td><?php echo esc_html(DBGTBL_Components::label($key)); ?><div class="dbgtbl-bar"><span style="width: <?php echo esc_attr(round($ms / $max * 100, 1)); ?>%"></span></div></td>
											<td><?php echo esc_html(self::ms($ms)); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p class="description">
								<?php
								echo 'plugin' === $p['plugin_load_source']
									? esc_html__('Time to include each plugin file. Plugins that load before Debugger & Troubleshooter are not measured because the early loader is not installed.', 'debugger-troubleshooter')
									: esc_html__('Time to include each plugin\'s main file (code that runs at load time, not on later hooks).', 'debugger-troubleshooter');
								?>
							</p>
						</div>
					<?php endif; ?>
				</div>

				<?php if (!empty($q['slowest'])) : ?>
					<h4>
						<?php esc_html_e('Slowest queries', 'debugger-troubleshooter'); ?>
						<?php if (!empty($q['slow_count'])) : ?>
							<span class="dbgtbl-chip dbgtbl-chip-bad">
								<?php
								/* translators: 1: Number of slow queries, 2: Threshold in ms. */
								echo esc_html(sprintf(__('%1$d over %2$d ms', 'debugger-troubleshooter'), $q['slow_count'], self::SLOW_QUERY_MS));
								?>
							</span>
						<?php endif; ?>
					</h4>
					<?php $this->render_query_table($q['slowest'], false); ?>
				<?php endif; ?>

				<?php if (!empty($q['duplicates'])) : ?>
					<h4><?php esc_html_e('Duplicate queries', 'debugger-troubleshooter'); ?></h4>
					<p class="description"><?php esc_html_e('The same query running many times on one page is usually a missing cache or a query inside a loop.', 'debugger-troubleshooter'); ?></p>
					<?php $this->render_query_table($q['duplicates'], true); ?>
				<?php endif; ?>

				<?php if (!empty($p['http'])) : ?>
					<h4><?php esc_html_e('External HTTP requests', 'debugger-troubleshooter'); ?></h4>
					<table class="widefat striped dbgtbl-table">
						<thead><tr><th><?php esc_html_e('URL', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Status', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Time', 'debugger-troubleshooter'); ?></th><th><?php esc_html_e('Component', 'debugger-troubleshooter'); ?></th></tr></thead>
						<tbody>
							<?php foreach ($p['http'] as $call) : ?>
								<tr>
									<td><code><?php echo esc_html($call['method'] . ' ' . $call['url']); ?></code></td>
									<td class="<?php echo !empty($call['error']) ? 'dbgtbl-bad' : ''; ?>"><?php echo esc_html($call['status']); ?></td>
									<td class="<?php echo esc_attr(self::rating($call['duration'], 300, 1000)); ?>"><?php echo esc_html(self::ms($call['duration'])); ?></td>
									<td><?php echo esc_html(DBGTBL_Components::label($call['component'])); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	/**
	 * Renders a query table.
	 *
	 * @param array $rows       Query rows.
	 * @param bool  $show_count Whether to show the count column.
	 */
	private function render_query_table($rows, $show_count)
	{
		?>
		<table class="widefat striped dbgtbl-table dbgtbl-query-table">
			<thead>
				<tr>
					<th><?php esc_html_e('Query', 'debugger-troubleshooter'); ?></th>
					<?php if ($show_count) : ?><th><?php esc_html_e('Count', 'debugger-troubleshooter'); ?></th><?php endif; ?>
					<th><?php esc_html_e('Time', 'debugger-troubleshooter'); ?></th>
					<th><?php esc_html_e('Caller', 'debugger-troubleshooter'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($rows as $row) : ?>
					<tr>
						<td><code class="dbgtbl-sql"><?php echo esc_html($row['sql']); ?></code></td>
						<?php if ($show_count) : ?><td><strong><?php echo esc_html(number_format_i18n($row['count'])); ?>×</strong></td><?php endif; ?>
						<td class="<?php echo esc_attr(self::rating($row['ms'], 20, self::SLOW_QUERY_MS)); ?>"><?php echo esc_html(self::ms($row['ms'])); ?></td>
						<td><code><?php echo esc_html($row['caller']); ?></code><br><small><?php echo esc_html(DBGTBL_Components::label($row['component'])); ?></small></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
