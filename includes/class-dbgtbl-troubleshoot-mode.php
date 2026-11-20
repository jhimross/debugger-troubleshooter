<?php
/**
 * Troubleshooting Mode: simulate plugin deactivation and theme switching for one browser.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Applies and manages the current browser's troubleshooting session.
 */
class DBGTBL_Troubleshoot_Mode
{
	/**
	 * Current session state, or false when not troubleshooting.
	 *
	 * @var array|false
	 */
	private $state = false;

	/**
	 * Registers hooks.
	 */
	public function __construct()
	{
		add_action('plugins_loaded', array($this, 'init'), 0);
		add_action('admin_init', array('DBGTBL_Session', 'refresh_mu_plugin'));
		add_action('admin_notices', array($this, 'admin_notice'));
		DBGTBL_Session::register_write_guards();
		add_action('wp_ajax_debug_troubleshoot_toggle_mode', array($this, 'ajax_toggle'));
		add_action('wp_ajax_debug_troubleshoot_update_state', array($this, 'ajax_update_state'));
	}

	/**
	 * Loads the session for this request and applies its filters.
	 * Runs very early so filters apply before most of WordPress loads.
	 */
	public function init()
	{
		DBGTBL_Session::prune_expired();

		if (!isset($_COOKIE[DBGTBL_Session::COOKIE])) {
			return;
		}

		$session = DBGTBL_Session::get_valid();
		if (empty($session[1])) {
			return;
		}

		$this->state = $session[1];

		// Prevent caching plugins from serving or storing this simulated view.
		if (!defined('DONOTCACHEPAGE')) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
			define('DONOTCACHEPAGE', true);
		}
		nocache_headers();

		// Plugin deactivation itself happens in the MU plugin; these keep later reads consistent.
		add_filter('option_active_plugins', array($this, 'filter_active_plugins'), 0);
		if (is_multisite()) {
			add_filter('site_option_active_sitewide_plugins', array($this, 'filter_active_sitewide_plugins'), 0);
		}

		add_filter('pre_option_stylesheet', array($this, 'filter_stylesheet'));
		add_filter('pre_option_template', array($this, 'filter_template'));
	}

	/**
	 * Whether troubleshooting mode is active for this browser.
	 *
	 * @return bool
	 */
	public function is_active()
	{
		return !empty($this->state);
	}

	/**
	 * Current session state.
	 *
	 * @return array|false
	 */
	public function get_state()
	{
		return $this->state;
	}

	/**
	 * Filters active plugins based on the session.
	 *
	 * @param array $plugins Active plugins.
	 * @return array
	 */
	public function filter_active_plugins($plugins)
	{
		if ($this->is_active() && isset($this->state['plugins']) && !DBGTBL_Session::is_bypassed()) {
			$active = (array) $this->state['plugins'];
			if (!in_array(DBGTBL_BASENAME, $active, true)) {
				$active[] = DBGTBL_BASENAME;
			}
			return $active;
		}
		return $plugins;
	}

	/**
	 * Filters network-active plugins based on the session.
	 *
	 * @param array $plugins Network-active plugins keyed by basename.
	 * @return array
	 */
	public function filter_active_sitewide_plugins($plugins)
	{
		if ($this->is_active() && isset($this->state['sitewide_plugins']) && !DBGTBL_Session::is_bypassed()) {
			$new_plugins = array();
			foreach ((array) $this->state['sitewide_plugins'] as $plugin_file) {
				$new_plugins[$plugin_file] = time();
			}
			return $new_plugins;
		}
		return $plugins;
	}

	/**
	 * Filters the active stylesheet.
	 *
	 * @param string|false $stylesheet Stylesheet.
	 * @return string|false
	 */
	public function filter_stylesheet($stylesheet)
	{
		if ($this->is_active() && isset($this->state['theme'])) {
			return $this->state['theme'];
		}
		return $stylesheet;
	}

	/**
	 * Filters the active template, resolving the parent for child themes.
	 *
	 * @param string|false $template Template.
	 * @return string|false
	 */
	public function filter_template($template)
	{
		if ($this->is_active() && isset($this->state['theme'])) {
			$theme = wp_get_theme($this->state['theme']);
			return $theme->exists() ? $theme->get_template() : $this->state['theme'];
		}
		return $template;
	}

	/**
	 * Renders the Troubleshooting Mode section.
	 */
	public function render_section()
	{
		$active = $this->is_active();
		?>
		<div class="debug-troubleshooter-section standalone-section">
			<div class="section-header">
				<h2><?php esc_html_e('Troubleshooting Mode', 'debugger-troubleshooter'); ?></h2>
				<button type="button" id="troubleshoot-mode-toggle"
					class="button button-large <?php echo $active ? 'button-danger' : 'button-primary'; ?>">
					<?php echo $active ? esc_html__('Exit Troubleshooting Mode', 'debugger-troubleshooter') : esc_html__('Enter Troubleshooting Mode', 'debugger-troubleshooter'); ?>
				</button>
			</div>
			<div class="section-content">
				<p class="description">
					<?php esc_html_e('Enter Troubleshooting Mode to simulate deactivating plugins and switching themes without affecting your live website for other visitors. This mode uses browser cookies and only applies to your session.', 'debugger-troubleshooter'); ?>
				</p>

				<div id="troubleshoot-mode-controls"
					class="troubleshoot-mode-controls <?php echo $active ? '' : 'hidden'; ?>">
					<div class="debug-troubleshooter-card">
						<h3><?php esc_html_e('Simulate Theme Switch', 'debugger-troubleshooter'); ?></h3>
						<p class="description">
							<?php esc_html_e('Select a theme to preview. This will change the theme for your session only.', 'debugger-troubleshooter'); ?>
						</p>
						<select id="troubleshoot-theme-select" class="regular-text">
							<?php
							$troubleshoot_theme = $active && !empty($this->state['theme']) ? $this->state['theme'] : get_stylesheet();
							foreach (wp_get_themes() as $slug => $theme) {
								echo '<option value="' . esc_attr($slug) . '"' . selected($slug, $troubleshoot_theme, false) . '>' . esc_html($theme->get('Name')) . '</option>';
							}
							?>
						</select>
					</div>

					<div class="debug-troubleshooter-card">
						<h3><?php esc_html_e('Simulate Plugin Deactivation', 'debugger-troubleshooter'); ?></h3>
						<p class="description">
							<?php esc_html_e('Check plugins to simulate deactivating them for your session. Unchecked plugins will remain active.', 'debugger-troubleshooter'); ?>
						</p>
						<?php
						$plugins = get_plugins();
						$site_active = (array) get_option('active_plugins', array());
						$network_active = DBGTBL_Session::network_active_plugins();
						$session_plugins = $active && !empty($this->state['plugins']) ? (array) $this->state['plugins'] : $site_active;
						$session_sitewide = $active && !empty($this->state['sitewide_plugins']) ? (array) $this->state['sitewide_plugins'] : $network_active;

						if (!empty($plugins)) {
							echo '<div class="plugin-list">';
							foreach ($plugins as $plugin_file => $plugin_data) {
								$is_active_for_site = in_array($plugin_file, $site_active, true) || in_array($plugin_file, $network_active, true);
								$is_checked = in_array($plugin_file, $session_plugins, true) || (is_multisite() && in_array($plugin_file, $session_sitewide, true));
								?>
								<label class="plugin-item flex items-center p-2 rounded-md transition-colors duration-200">
									<input type="checkbox" name="troubleshoot_plugins[]"
										value="<?php echo esc_attr($plugin_file); ?>" <?php checked($is_checked); ?>
										data-original-state="<?php echo $is_active_for_site ? 'active' : 'inactive'; ?>">
									<span class="ml-2">
										<strong><?php echo esc_html($plugin_data['Name']); ?></strong>
										<br><small><?php echo esc_html($plugin_data['Version']); ?> |
											<?php echo esc_html($plugin_data['AuthorName']); ?></small>
									</span>
								</label>
								<?php
							}
							echo '</div>';
						} else {
							echo '<p>' . esc_html__('No plugins found.', 'debugger-troubleshooter') . '</p>';
						}
						?>
					</div>

					<button type="button" id="apply-troubleshoot-changes"
						class="button button-primary button-large"><?php esc_html_e('Apply Troubleshooting Changes', 'debugger-troubleshooter'); ?></button>
					<p class="description">
						<?php esc_html_e('Applying changes will refresh the page to reflect your simulated theme and plugin states.', 'debugger-troubleshooter'); ?>
					</p>
				</div><!-- #troubleshoot-mode-controls -->
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: enter or exit troubleshooting mode.
	 */
	public function ajax_toggle()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$enable = isset($_POST['enable']) ? (bool) $_POST['enable'] : false;

		if ($enable) {
			if (!DBGTBL_Session::create()) {
				wp_send_json_error(array('message' => __('Could not install the troubleshooting MU plugin. Please check filesystem permissions.', 'debugger-troubleshooter')));
			}
			wp_send_json_success(array('message' => __('Troubleshooting mode activated.', 'debugger-troubleshooter')));
		}

		DBGTBL_Session::destroy();
		wp_send_json_success(array('message' => __('Troubleshooting mode deactivated.', 'debugger-troubleshooter')));
	}

	/**
	 * AJAX: save the simulated theme and plugin list.
	 */
	public function ajax_update_state()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$selected_theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : get_stylesheet();
		$themes = wp_get_themes();
		if (!isset($themes[$selected_theme])) {
			$selected_theme = get_stylesheet();
		}

		$all_plugins = get_plugins();
		$selected_plugins = array();
		if (isset($_POST['plugins']) && is_array($_POST['plugins'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type validated and sanitized in the loop below.
			foreach (wp_unslash($_POST['plugins']) as $plugin_file) {
				if (is_string($plugin_file)) {
					$plugin_file = sanitize_text_field($plugin_file);
					if (isset($all_plugins[$plugin_file])) {
						$selected_plugins[] = $plugin_file;
					}
				}
			}
		}

		// Keep this plugin active so the user can manage the session.
		if (!in_array(DBGTBL_BASENAME, $selected_plugins, true)) {
			$selected_plugins[] = DBGTBL_BASENAME;
		}

		list($new_active_plugins, $new_active_sitewide_plugins) = DBGTBL_Session::split_by_network($selected_plugins);

		$session = DBGTBL_Session::get_valid();
		if (empty($session[1])) {
			wp_send_json_error(array('message' => __('Invalid troubleshooting session.', 'debugger-troubleshooter')));
		}

		$token = $session[0];
		$sessions = DBGTBL_Session::all();
		$sessions[$token] = array(
			'theme' => $selected_theme,
			'plugins' => $new_active_plugins,
			'sitewide_plugins' => $new_active_sitewide_plugins,
			'timestamp' => time(),
			'created_by' => isset($session[1]['created_by']) ? (int) $session[1]['created_by'] : DBGTBL_Session::real_user_id(),
		);
		DBGTBL_Session::save_all($sessions);

		wp_send_json_success(array('message' => __('Troubleshooting state updated successfully. Refreshing page...', 'debugger-troubleshooter')));
	}

	/**
	 * Shows a notice on every admin page while troubleshooting.
	 */
	public function admin_notice()
	{
		if (!$this->is_active()) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible debug-troubleshoot-notice">
			<p>
				<strong><?php esc_html_e('Troubleshooting Mode is Active!', 'debugger-troubleshooter'); ?></strong>
				<?php esc_html_e('You are currently in a special troubleshooting session. Your simulated theme and plugin states are not affecting the live site for other visitors.', 'debugger-troubleshooter'); ?>
				<a href="<?php echo esc_url(admin_url('tools.php?page=debugger-troubleshooter')); ?>"><?php esc_html_e('Go to Debugger & Troubleshooter page to manage.', 'debugger-troubleshooter'); ?></a>
			</p>
		</div>
		<?php
	}
}
