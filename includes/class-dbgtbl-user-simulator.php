<?php
/**
 * User Role Simulator: view the site as another user for this browser only.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Overrides the current user for the browser holding a valid simulation token.
 */
class DBGTBL_User_Simulator
{
	/**
	 * Simulation cookie name.
	 */
	const COOKIE = 'wp_debug_troubleshoot_simulate_user';

	/**
	 * Option holding simulation tokens.
	 */
	const OPTION = 'dbgtbl_sim_users';

	/**
	 * Simulated user ID, or false when not simulating.
	 *
	 * @var int|false
	 */
	private $simulated_user_id = false;

	/**
	 * Registers hooks.
	 */
	public function __construct()
	{
		add_action('plugins_loaded', array($this, 'init'), 0);
		add_action('wp_ajax_debug_troubleshoot_toggle_simulate_user', array($this, 'ajax_toggle'));
		add_action('admin_bar_menu', array($this, 'admin_bar_exit'), 999);
		add_action('wp_footer', array($this, 'print_exit_script'));
		add_action('admin_footer', array($this, 'print_exit_script'));
	}

	/**
	 * Loads the simulation for this request.
	 */
	public function init()
	{
		$this->prune_expired();

		if (!isset($_COOKIE[self::COOKIE])) {
			return;
		}

		$token = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
		$sim_users = get_option(self::OPTION, array());

		if (!isset($sim_users[$token]) || !is_array($sim_users[$token])) {
			return;
		}

		$entry = $sim_users[$token];
		$user_id = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;
		$created_by = isset($entry['created_by']) ? (int) $entry['created_by'] : 0;
		$created = isset($entry['created']) ? (int) $entry['created'] : 0;

		if (!$user_id || !$created_by || !$created || (time() - $created) > DAY_IN_SECONDS) {
			return;
		}

		// Only the user who started the simulation may use its token.
		if (DBGTBL_Session::real_user_id() !== $created_by) {
			return;
		}

		$this->simulated_user_id = $user_id;

		// Priority 20 runs after the standard authentication checks.
		add_filter('determine_current_user', array($this, 'filter_current_user'), 20);
	}

	/**
	 * Overrides the current user ID.
	 *
	 * @param int|false $user_id Determined user ID.
	 * @return int|false
	 */
	public function filter_current_user($user_id)
	{
		return $this->simulated_user_id ? $this->simulated_user_id : $user_id;
	}

	/**
	 * Whether a simulation is active.
	 *
	 * @return bool
	 */
	public function is_active()
	{
		return !empty($this->simulated_user_id);
	}

	/**
	 * Removes expired or legacy tokens.
	 */
	private function prune_expired()
	{
		$sim_users = get_option(self::OPTION, array());
		if (!is_array($sim_users) || empty($sim_users)) {
			return;
		}

		$changed = false;
		$now = time();

		foreach ($sim_users as $token => $entry) {
			$created = is_array($entry) && isset($entry['created']) ? (int) $entry['created'] : 0;
			if (!$created || ($now - $created) > DAY_IN_SECONDS) {
				unset($sim_users[$token]);
				$changed = true;
			}
		}

		if ($changed) {
			update_option(self::OPTION, $sim_users);
		}
	}

	/**
	 * Renders the User Role Simulator section.
	 */
	public function render_section()
	{
		// Limited to 50 users to keep the dropdown fast.
		$users = get_users(array('fields' => array('ID', 'display_name', 'user_login'), 'number' => 50));
		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('User Role Simulator', 'debugger-troubleshooter'); ?></h2>
			</div>
			<div class="section-content">
				<p class="description">
					<?php esc_html_e('View the site as a specific user or role. This allows you to test permissions and user-specific content without logging out. This only affects your session.', 'debugger-troubleshooter'); ?>
				</p>
				<div class="user-simulation-controls">
					<div class="debug-troubleshooter-card">
						<h3><?php esc_html_e('Select User to Simulate', 'debugger-troubleshooter'); ?></h3>
						<div class="flex items-center gap-4">
							<select id="simulate-user-select" class="regular-text">
								<option value=""><?php esc_html_e('-- Select a User --', 'debugger-troubleshooter'); ?></option>
								<?php foreach ($users as $user) : ?>
									<option value="<?php echo esc_attr($user->ID); ?>">
										<?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="simulate-user-btn"
								class="button button-primary"><?php esc_html_e('Simulate User', 'debugger-troubleshooter'); ?></button>
						</div>
						<p class="description mt-2">
							<?php esc_html_e('Note: You can exit the simulation at any time using the "Exit Simulation" button in the Admin Bar.', 'debugger-troubleshooter'); ?>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds an "Exit User Simulation" item to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function admin_bar_exit($wp_admin_bar)
	{
		if (!$this->is_active()) {
			return;
		}

		$wp_admin_bar->add_node(array(
			'id' => 'debug-troubleshooter-exit-sim',
			'title' => '<span style="color: #ff4444; font-weight: bold;">' . esc_html__('Exit User Simulation', 'debugger-troubleshooter') . '</span>',
			'href' => '#',
			'meta' => array(
				'onclick' => 'debugTroubleshootExitSimulation(); return false;',
				'title' => __('Click to return to your original user account', 'debugger-troubleshooter'),
			),
		));
	}

	/**
	 * Prints the inline script used by the admin bar exit link.
	 */
	public function print_exit_script()
	{
		if (!$this->is_active()) {
			return;
		}

		$nonce = wp_create_nonce('debug_troubleshoot_exit_simulation');
		$exit_url = admin_url('admin-ajax.php?action=debug_troubleshoot_toggle_simulate_user&enable=0&nonce=' . $nonce);
		?>
		<script type="text/javascript">
			function debugTroubleshootExitSimulation() {
				if (confirm('<?php echo esc_js(__('Are you sure you want to exit User Simulation?', 'debugger-troubleshooter')); ?>')) {
					window.location.href = <?php echo wp_json_encode($exit_url); ?>;
				}
			}
		</script>
		<?php
	}

	/**
	 * AJAX: start or stop a simulation.
	 */
	public function ajax_toggle()
	{
		$nonce = isset($_REQUEST['nonce']) && is_string($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';

		if (!wp_verify_nonce($nonce, 'debug_troubleshoot_nonce') && !wp_verify_nonce($nonce, 'debug_troubleshoot_exit_simulation')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'debugger-troubleshooter')));
		}

		$enable = isset($_REQUEST['enable']) && is_scalar($_REQUEST['enable']) ? (bool) $_REQUEST['enable'] : false;
		$user_id = isset($_REQUEST['user_id']) && is_scalar($_REQUEST['user_id']) ? absint($_REQUEST['user_id']) : 0;
		$is_post = isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ($enable) {
			// The exit-simulation nonce is intentionally not accepted for starting a simulation.
			if (!wp_verify_nonce($nonce, 'debug_troubleshoot_nonce')) {
				wp_send_json_error(array('message' => __('Security check failed.', 'debugger-troubleshooter')));
			}

			if (!current_user_can('manage_options')) {
				wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
			}

			if (!$is_post) {
				wp_send_json_error(array('message' => __('Invalid request method.', 'debugger-troubleshooter')));
			}

			if (!$user_id || !get_userdata($user_id)) {
				wp_send_json_error(array('message' => __('Invalid user.', 'debugger-troubleshooter')));
			}

			$this->prune_expired();

			$token = wp_generate_password(64, false);
			$sim_users = get_option(self::OPTION, array());

			// Remove this browser's previous token before creating a new one.
			$old_token = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : false;
			if ($old_token && isset($sim_users[$old_token])) {
				unset($sim_users[$old_token]);
			}

			$sim_users[$token] = array(
				'user_id' => $user_id,
				'created_by' => DBGTBL_Session::creator_id(),
				'created' => time(),
			);
			update_option(self::OPTION, $sim_users);

			DBGTBL_Session::send_cookie(self::COOKIE, $token);
			wp_send_json_success(array(
				'message' => __('User simulation activated. Redirecting...', 'debugger-troubleshooter'),
				'redirect' => admin_url(),
			));
		}

		// Stopping is allowed for the simulated user (admin bar link) or an administrator.
		if (!current_user_can('manage_options') && !$this->is_active()) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$token = isset($_COOKIE[self::COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE])) : false;
		if ($token) {
			$sim_users = get_option(self::OPTION, array());
			unset($sim_users[$token]);
			update_option(self::OPTION, $sim_users);
		}

		DBGTBL_Session::send_cookie(self::COOKIE, '');

		if (!$is_post) {
			// GET request from the admin bar: go back to the dashboard.
			wp_safe_redirect(admin_url());
			exit;
		}

		wp_send_json_success(array('message' => __('User simulation deactivated.', 'debugger-troubleshooter')));
	}
}
