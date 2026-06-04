<?php
/**
 * Plugin Name:       Debugger & Troubleshooter
 * Plugin URI:        https://wordpress.org/plugins/debugger-troubleshooter
 * Description:       A WordPress plugin for debugging and troubleshooting, allowing simulated plugin deactivation and theme switching without affecting the live site.
 * Version:           1.5.0
 * Author:            Jhimross
 * Author URI:        https://profiles.wordpress.org/jhimross
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       debugger-troubleshooter
 * Domain Path:       /languages
 */


// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Define plugin constants.
 */
define('DBGTBL_VERSION', '1.4.1');
define('DBGTBL_DIR', plugin_dir_path(__FILE__));
define('DBGTBL_URL', plugin_dir_url(__FILE__));
define('DBGTBL_BASENAME', plugin_basename(__FILE__));

/**
 * The main plugin class.
 */
class Debug_Troubleshooter
{

	/**
	 * Troubleshooting mode cookie name.
	 */
	const TROUBLESHOOT_COOKIE = 'wp_debug_troubleshoot_mode';
	const DEBUG_MODE_OPTION = 'wp_debug_troubleshoot_debug_mode';
	const SIMULATE_USER_COOKIE = 'wp_debug_troubleshoot_simulate_user';

	/**
	 * Stores the current troubleshooting state from the cookie.
	 *
	 * @var array|false
	 */
	private $troubleshoot_state = false;

	/**
	 * Stores the simulated user ID.
	 *
	 * @var int|false
	 */
	private $simulated_user_id = false;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Load text domain for internationalization.
		// Load text domain for internationalization.
		// add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Initialize admin hooks.
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('wp_ajax_debug_troubleshoot_toggle_mode', array($this, 'ajax_toggle_troubleshoot_mode'));
		add_action('wp_ajax_debug_troubleshoot_update_state', array($this, 'ajax_update_troubleshoot_state'));
		add_action('wp_ajax_debug_troubleshoot_toggle_debug_mode', array($this, 'ajax_toggle_debug_mode'));
		add_action('wp_ajax_debug_troubleshoot_clear_debug_log', array($this, 'ajax_clear_debug_log'));
		add_action('wp_ajax_debug_troubleshoot_toggle_simulate_user', array($this, 'ajax_toggle_simulate_user'));
		add_action('wp_ajax_debug_troubleshoot_send_test_email', array($this, 'ajax_send_test_email'));
		
		// Conflict Checker AJAX hooks
		add_action('wp_ajax_debug_troubleshoot_detective_start', array($this, 'ajax_detective_start'));
		add_action('wp_ajax_debug_troubleshoot_detective_step', array($this, 'ajax_detective_step'));
		add_action('wp_ajax_debug_troubleshoot_detective_reset', array($this, 'ajax_detective_reset'));
		
		// PHP Compatibility Checker AJAX hooks
		add_action('wp_ajax_debug_troubleshoot_compat_start', array($this, 'ajax_compat_start'));
		add_action('wp_ajax_debug_troubleshoot_compat_scan_item', array($this, 'ajax_compat_scan_item'));

		// Core troubleshooting logic (very early hook).
		add_action('plugins_loaded', array($this, 'init_troubleshooting_mode'), 0);
		add_action('plugins_loaded', array($this, 'init_live_debug_mode'), 0);
		add_action('plugins_loaded', array($this, 'init_user_simulation'), 0);

		// Admin notice for troubleshooting mode.
		add_action('admin_notices', array($this, 'troubleshooting_mode_notice'));
		add_action('admin_bar_menu', array($this, 'admin_bar_exit_simulation'), 999);

		// Include exit simulation script if active.
		add_action('wp_footer', array($this, 'print_exit_simulation_script'));
		add_action('admin_footer', array($this, 'print_exit_simulation_script'));
	}



	/**
	 * Add admin menu page.
	 */
	public function add_admin_menu()
	{
		add_management_page(
			__('Debugger & Troubleshooter', 'debugger-troubleshooter'),
			__('Debugger & Troubleshooter', 'debugger-troubleshooter'),
			'manage_options',
			'debugger-troubleshooter',
			array($this, 'render_admin_page')
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_admin_scripts($hook)
	{
		if ('tools_page_debugger-troubleshooter' !== $hook) {
			return;
		}

		// Enqueue the main admin stylesheet.
		wp_enqueue_style('debug-troubleshooter-admin', DBGTBL_URL . 'assets/css/admin.css', array(), DBGTBL_VERSION);
		// Enqueue the main admin JavaScript.
		wp_enqueue_script('debug-troubleshooter-admin', DBGTBL_URL . 'assets/js/admin.js', array('jquery'), DBGTBL_VERSION, true);

		// Localize script with necessary data.
		wp_localize_script(
			'debug-troubleshooter-admin',
			'debugTroubleshoot',
			array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('debug_troubleshoot_nonce'),
				'is_troubleshooting' => $this->is_troubleshooting_active(),
				'current_state' => $this->get_troubleshoot_state(),
				'is_debug_mode' => get_option(self::DEBUG_MODE_OPTION, 'disabled') === 'enabled',
				'active_plugins' => get_option('active_plugins', array()),
				'active_sitewide_plugins' => is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array(),
				'current_theme' => get_stylesheet(),
				'alert_title_success' => __('Success', 'debugger-troubleshooter'),
				'alert_title_error' => __('Error', 'debugger-troubleshooter'),
				'copy_button_text' => __('Copy to Clipboard', 'debugger-troubleshooter'),
				'copied_button_text' => __('Copied!', 'debugger-troubleshooter'),
				'show_all_text' => __('Show All', 'debugger-troubleshooter'),
				'hide_text' => __('Hide', 'debugger-troubleshooter'),
				'is_simulating_user' => $this->is_simulating_user(),
			)
		);
	}

	/**
	 * Renders the admin page content.
	 */
	public function render_admin_page()
	{
		$is_debug_mode_enabled = get_option(self::DEBUG_MODE_OPTION, 'disabled') === 'enabled';
		?>
		<div class="wrap debug-troubleshooter-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Debugger & Troubleshooter', 'debugger-troubleshooter'); ?></h1>
			<hr class="wp-header-end">

			<!-- Navigation Tabs -->
			<h2 class="nav-tab-wrapper dbgtbl-nav-tabs" style="margin-bottom: 20px;">
				<a href="#tab-general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e('General Tools', 'debugger-troubleshooter'); ?></a>
				<a href="#tab-detective" class="nav-tab" data-tab="detective"><?php esc_html_e('Conflict Checker', 'debugger-troubleshooter'); ?></a>
				<a href="#tab-compatibility" class="nav-tab" data-tab="compatibility"><?php esc_html_e('PHP Compatibility', 'debugger-troubleshooter'); ?></a>
			</h2>

			<!-- Tab Content: General Tools -->
			<div id="tab-content-general" class="dbgtbl-tab-content">
				<div class="debug-troubleshooter-content">
					<div class="debug-troubleshooter-section">
						<div class="section-header">
							<h2><?php esc_html_e('Site Information', 'debugger-troubleshooter'); ?></h2>
							<button id="copy-site-info"
								class="button button-secondary"><?php esc_html_e('Copy to Clipboard', 'debugger-troubleshooter'); ?></button>
						</div>
						<div id="site-info-content" class="section-content">
							<?php $this->display_site_info(); ?>
						</div>
					</div>

					<div class="debug-troubleshooter-section standalone-section">
						<div class="section-header">
							<h2><?php esc_html_e('Troubleshooting Mode', 'debugger-troubleshooter'); ?></h2>
							<button id="troubleshoot-mode-toggle"
								class="button button-large <?php echo $this->is_troubleshooting_active() ? 'button-danger' : 'button-primary'; ?>">
								<?php echo $this->is_troubleshooting_active() ? esc_html__('Exit Troubleshooting Mode', 'debugger-troubleshooter') : esc_html__('Enter Troubleshooting Mode', 'debugger-troubleshooter'); ?>
							</button>
						</div>
						<div class="section-content">
							<p class="description">
								<?php esc_html_e('Enter Troubleshooting Mode to simulate deactivating plugins and switching themes without affecting your live website for other visitors. This mode uses browser cookies and only applies to your session.', 'debugger-troubleshooter'); ?>
							</p>

							<div id="troubleshoot-mode-controls"
								class="troubleshoot-mode-controls <?php echo $this->is_troubleshooting_active() ? '' : 'hidden'; ?>">
								<div class="debug-troubleshooter-card">
									<h3><?php esc_html_e('Simulate Theme Switch', 'debugger-troubleshooter'); ?></h3>
									<p class="description">
										<?php esc_html_e('Select a theme to preview. This will change the theme for your session only.', 'debugger-troubleshooter'); ?>
									</p>
									<select id="troubleshoot-theme-select" class="regular-text">
										<?php
										$themes = wp_get_themes();
										$current_active = get_stylesheet();
										$troubleshoot_theme = $this->troubleshoot_state && !empty($this->troubleshoot_state['theme']) ? $this->troubleshoot_state['theme'] : $current_active;

										foreach ($themes as $slug => $theme) {
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
									$troubleshoot_active_plugins = $this->troubleshoot_state && !empty($this->troubleshoot_state['plugins']) ? $this->troubleshoot_state['plugins'] : get_option('active_plugins', array());
									$troubleshoot_active_sitewide_plugins = $this->troubleshoot_state && !empty($this->troubleshoot_state['sitewide_plugins']) ? $this->troubleshoot_state['sitewide_plugins'] : (is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array());

									if (!empty($plugins)) {
										echo '<div class="plugin-list">';
										foreach ($plugins as $plugin_file => $plugin_data) {
											$is_active_for_site = in_array($plugin_file, get_option('active_plugins', array())) || (is_multisite() && array_key_exists($plugin_file, get_site_option('active_sitewide_plugins', array())));
											$is_checked_in_troubleshoot_mode = (
												in_array($plugin_file, $troubleshoot_active_plugins) ||
												(is_multisite() && in_array($plugin_file, $troubleshoot_active_sitewide_plugins))
											);
											?>
											<label class="plugin-item flex items-center p-2 rounded-md transition-colors duration-200">
												<input type="checkbox" name="troubleshoot_plugins[]"
													value="<?php echo esc_attr($plugin_file); ?>" <?php checked($is_checked_in_troubleshoot_mode); ?>
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

								<button id="apply-troubleshoot-changes"
									class="button button-primary button-large"><?php esc_html_e('Apply Troubleshooting Changes', 'debugger-troubleshooter'); ?></button>
								<p class="description">
									<?php esc_html_e('Applying changes will refresh the page to reflect your simulated theme and plugin states.', 'debugger-troubleshooter'); ?>
								</p>
							</div><!-- #troubleshoot-mode-controls -->
						</div>
					</div>

					<div class="debug-troubleshooter-section standalone-section full-width-section">
						<div class="section-header">
							<h2><?php esc_html_e('User Role Simulator', 'debugger-troubleshooter'); ?></h2>
						</div>
						<div class="section-content">
							<p class="description">
								<?php esc_html_e('View the site as a specific user or role. This allows you to test permissions and user-specific content without logging out. This only affects your session.', 'debugger-troubleshooter'); ?>
							</p>
							<?php $this->render_user_simulation_section(); ?>
						</div>
					</div>

					<div class="debug-troubleshooter-section standalone-section full-width-section">
						<div class="section-header">
							<h2><?php esc_html_e('Live Debugging', 'debugger-troubleshooter'); ?></h2>
							<button id="debug-mode-toggle"
								class="button button-large <?php echo $is_debug_mode_enabled ? 'button-danger' : 'button-primary'; ?>">
								<?php echo $is_debug_mode_enabled ? esc_html__('Disable Live Debug', 'debugger-troubleshooter') : esc_html__('Enable Live Debug', 'debugger-troubleshooter'); ?>
							</button>
						</div>
						<div class="section-content">
							<p class="description">
								<?php esc_html_e('Enable this to turn on WP_DEBUG without editing your wp-config.php file. Errors will be logged to the debug.log file below, not displayed on the site.', 'debugger-troubleshooter'); ?>
							</p>

							<div class="debug-log-viewer-wrapper">
								<div class="debug-log-header">
									<h3><?php esc_html_e('Debug Log Viewer', 'debugger-troubleshooter'); ?></h3>
									<button id="clear-debug-log"
										class="button button-secondary"><?php esc_html_e('Clear Log', 'debugger-troubleshooter'); ?></button>
								</div>
								<textarea id="debug-log-viewer" readonly class="large-text"
									rows="15"><?php echo esc_textarea($this->get_debug_log_content()); ?></textarea>
							</div>
						</div>
					</div>

					<div class="debug-troubleshooter-section standalone-section full-width-section">
						<div class="section-header">
							<h2><?php esc_html_e('SMTP / Mail Debugger', 'debugger-troubleshooter'); ?></h2>
						</div>
						<div class="section-content">
							<p class="description">
								<?php esc_html_e('Send a test email to verify your site\'s mail configuration. If the email fails, the plugin will attempt to capture the exact error message.', 'debugger-troubleshooter'); ?>
							</p>
							<div class="mail-debugger-form mt-4">
								<div class="flex flex-col md:flex-row gap-4 items-end">
									<div class="flex-1">
										<label for="test-email-recipient" class="block mb-2 font-medium"><?php esc_html_e('Recipient Email Address', 'debugger-troubleshooter'); ?></label>
										<input type="email" id="test-email-recipient" class="regular-text w-full" 
											placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" 
											value="<?php echo esc_attr(get_option('admin_email')); ?>">
									</div>
									<button id="send-test-email" class="button button-primary button-large">
										<?php esc_html_e('Send Test Email', 'debugger-troubleshooter'); ?>
									</button>
								</div>
								<div id="mail-debug-result" class="hidden mt-4 p-4 rounded-md border">
									<h4 class="mt-0 font-bold" id="mail-debug-result-title"></h4>
									<div id="mail-debug-result-message" class="text-sm font-mono whitespace-pre-wrap"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Tab Content: Conflict Checker -->
			<div id="tab-content-detective" class="dbgtbl-tab-content hidden">
				<?php $this->render_detective_tab(); ?>
			</div>

			<!-- Tab Content: PHP Compatibility -->
			<div id="tab-content-compatibility" class="dbgtbl-tab-content hidden">
				<?php $this->render_compatibility_tab(); ?>
			</div>
		</div>

		<!-- Modal components -->
		<div id="debug-troubleshoot-alert-modal"
			class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
			<div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
				<h3 id="debug-troubleshoot-alert-title" class="text-xl font-bold mb-4"></h3>
				<p id="debug-troubleshoot-alert-message" class="text-gray-700 mb-6"></p>
				<button id="debug-troubleshoot-alert-close"
					class="button button-primary"><?php esc_html_e('OK', 'debugger-troubleshooter'); ?></button>
			</div>
		</div>

		<div id="debug-troubleshoot-confirm-modal"
			class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
			<div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
				<h3 id="debug-troubleshoot-confirm-title" class="text-xl font-bold mb-4"></h3>
				<p id="debug-troubleshoot-confirm-message" class="text-gray-700 mb-6"></p>
				<div class="confirm-buttons">
					<button id="debug-troubleshoot-confirm-cancel"
						class="button button-secondary"><?php esc_html_e('Cancel', 'debugger-troubleshooter'); ?></button>
					<button id="debug-troubleshoot-confirm-ok"
						class="button button-danger"><?php esc_html_e('Confirm', 'debugger-troubleshooter'); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Displays useful site information.
	 */
	private function display_site_info()
	{
		global $wpdb;
		echo '<div class="site-info-grid">';

		// WordPress Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed"><h3>' . esc_html__('WordPress Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2"></span></div>';
		echo '<div class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('WordPress Version:', 'debugger-troubleshooter') . '</strong> ' . esc_html(get_bloginfo('version')) . '</p>';
		echo '<p><strong>' . esc_html__('Site Language:', 'debugger-troubleshooter') . '</strong> ' . esc_html(get_locale()) . '</p>';
		echo '<p><strong>' . esc_html__('Permalink Structure:', 'debugger-troubleshooter') . '</strong> ' . esc_html(get_option('permalink_structure') ?: 'Plain') . '</p>';
		echo '<p><strong>' . esc_html__('Multisite:', 'debugger-troubleshooter') . '</strong> ' . (is_multisite() ? 'Yes' : 'No') . '</p>';

		// Themes List
		$all_themes = wp_get_themes();
		$active_theme_obj = wp_get_theme();
		$inactive_themes_count = count($all_themes) - 1;

		echo '<h4>' . esc_html__('Themes', 'debugger-troubleshooter') . '</h4>';
		echo '<p><strong>' . esc_html__('Active Theme:', 'debugger-troubleshooter') . '</strong> ' . esc_html($active_theme_obj->get('Name')) . ' (' . esc_html($active_theme_obj->get('Version')) . ')</p>';
		if ($inactive_themes_count > 0) {
			echo '<p><strong>' . esc_html__('Inactive Themes:', 'debugger-troubleshooter') . '</strong> ' . esc_html($inactive_themes_count) . ' <a href="#" class="info-sub-list-toggle" data-target="themes-list">' . esc_html__('Show All', 'debugger-troubleshooter') . '</a></p>';
		}

		if (!empty($all_themes)) {
			echo '<ul id="themes-list" class="info-sub-list hidden">';
			foreach ($all_themes as $stylesheet => $theme) {
				$status = ($stylesheet === $active_theme_obj->get_stylesheet()) ? '<span class="status-active">Active</span>' : '<span class="status-inactive">Inactive</span>';
				echo '<li><div>' . esc_html($theme->get('Name')) . ' (' . esc_html($theme->get('Version')) . ')</div>' . wp_kses_post($status) . '</li>';
			}
			echo '</ul>';
		}

		// Plugins List
		$all_plugins = get_plugins();
		$active_plugins = (array) get_option('active_plugins', array());
		$network_active_plugins = is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array();
		$inactive_plugins_count = count($all_plugins) - count($active_plugins) - count($network_active_plugins);

		echo '<h4>' . esc_html__('Plugins', 'debugger-troubleshooter') . '</h4>';
		echo '<p><strong>' . esc_html__('Active Plugins:', 'debugger-troubleshooter') . '</strong> ' . count($active_plugins) . '</p>';
		if (is_multisite()) {
			echo '<p><strong>' . esc_html__('Network Active Plugins:', 'debugger-troubleshooter') . '</strong> ' . count($network_active_plugins) . '</p>';
		}
		echo '<p><strong>' . esc_html__('Inactive Plugins:', 'debugger-troubleshooter') . '</strong> ' . esc_html($inactive_plugins_count) . ' <a href="#" class="info-sub-list-toggle" data-target="plugins-list">' . esc_html__('Show All', 'debugger-troubleshooter') . '</a></p>';

		if (!empty($all_plugins)) {
			echo '<ul id="plugins-list" class="info-sub-list hidden">';
			foreach ($all_plugins as $plugin_file => $plugin_data) {
				$status = '<span class="status-inactive">Inactive</span>';
				if (in_array($plugin_file, $active_plugins, true)) {
					$status = '<span class="status-active">Active</span>';
				} elseif (in_array($plugin_file, $network_active_plugins, true)) {
					$status = '<span class="status-network-active">Network Active</span>';
				}
				echo '<li><div>' . esc_html($plugin_data['Name']) . ' (' . esc_html($plugin_data['Version']) . ')</div>' . wp_kses_post($status) . '</li>';
			}
			echo '</ul>';
		}

		echo '</div></div>';

		// PHP Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed"><h3>' . esc_html__('PHP Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2"></span></div>';
		echo '<div class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('PHP Version:', 'debugger-troubleshooter') . '</strong> ' . esc_html(phpversion()) . '</p>';
		echo '<p><strong>' . esc_html__('Memory Limit:', 'debugger-troubleshooter') . '</strong> ' . esc_html(ini_get('memory_limit')) . '</p>';
		echo '<p><strong>' . esc_html__('Peak Memory Usage:', 'debugger-troubleshooter') . '</strong> ' . esc_html(size_format(memory_get_peak_usage(true))) . '</p>';
		echo '<p><strong>' . esc_html__('Post Max Size:', 'debugger-troubleshooter') . '</strong> ' . esc_html(ini_get('post_max_size')) . '</p>';
		echo '<p><strong>' . esc_html__('Upload Max Filesize:', 'debugger-troubleshooter') . '</strong> ' . esc_html(ini_get('upload_max_filesize')) . '</p>';
		echo '<p><strong>' . esc_html__('Max Execution Time:', 'debugger-troubleshooter') . '</strong> ' . esc_html(ini_get('max_execution_time')) . 's</p>';
		echo '<p><strong>' . esc_html__('Max Input Vars:', 'debugger-troubleshooter') . '</strong> ' . esc_html(ini_get('max_input_vars')) . '</p>';
		echo '<p><strong>' . esc_html__('cURL Extension:', 'debugger-troubleshooter') . '</strong> ' . (extension_loaded('curl') ? 'Enabled' : 'Disabled') . '</p>';
		echo '<p><strong>' . esc_html__('GD Library:', 'debugger-troubleshooter') . '</strong> ' . (extension_loaded('gd') ? 'Enabled' : 'Disabled') . '</p>';
		echo '<p><strong>' . esc_html__('Imagick Library:', 'debugger-troubleshooter') . '</strong> ' . (extension_loaded('imagick') ? 'Enabled' : 'Disabled') . '</p>';
		echo '</div></div>';

		// Database Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed"><h3>' . esc_html__('Database Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2"></span></div>';
		echo '<div class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('Database Engine:', 'debugger-troubleshooter') . '</strong> MySQL</p>';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Direct query is necessary to get the MySQL server version. Caching is not beneficial for this one-off diagnostic read.
		echo '<p><strong>' . esc_html__('MySQL Version:', 'debugger-troubleshooter') . '</strong> ' . esc_html($wpdb->get_var('SELECT VERSION()')) . '</p>';
		// phpcs:enable
		echo '<p><strong>' . esc_html__('DB Name:', 'debugger-troubleshooter') . '</strong> ' . esc_html(DB_NAME) . '</p>';
		echo '<p><strong>' . esc_html__('DB Host:', 'debugger-troubleshooter') . '</strong> ' . esc_html(DB_HOST) . '</p>';
		echo '<p><strong>' . esc_html__('DB Charset:', 'debugger-troubleshooter') . '</strong> ' . esc_html(DB_CHARSET) . '</p>';
		echo '<p><strong>' . esc_html__('DB Collate:', 'debugger-troubleshooter') . '</strong> ' . esc_html(DB_COLLATE) . '</p>';
		echo '</div></div>';

		// Server Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed"><h3>' . esc_html__('Server Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2"></span></div>';
		echo '<div class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('Web Server:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Server Protocol:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PROTOCOL'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Server Address:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Document Root:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('HTTPS:', 'debugger-troubleshooter') . '</strong> ' . (is_ssl() ? 'On' : 'Off') . '</p>';
		echo '</div></div>';

		// WordPress Constants Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed"><h3>' . esc_html__('WordPress Constants', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2"></span></div>';
		echo '<div class="card-collapsible-content hidden">';
		echo '<ul>';
		$wp_constants = array(
			'WP_ENVIRONMENT_TYPE',
			'WP_HOME',
			'WP_SITEURL',
			'WP_CONTENT_DIR',
			'WP_PLUGIN_DIR',
			'WP_DEBUG',
			'WP_DEBUG_DISPLAY',
			'WP_DEBUG_LOG',
			'SCRIPT_DEBUG',
			'WP_MEMORY_LIMIT',
			'WP_MAX_MEMORY_LIMIT',
			'CONCATENATE_SCRIPTS',
			'WP_CACHE',
			'DISABLE_WP_CRON',
			'DISALLOW_FILE_EDIT',
			'FS_METHOD',
			'FS_CHMOD_DIR',
			'FS_CHMOD_FILE',
		);
		foreach ($wp_constants as $constant) {
			echo '<li><strong>' . esc_html($constant) . ':</strong> ';
			if (defined($constant)) {
				$value = constant($constant);
				if (is_bool($value)) {
					echo esc_html($value ? 'true' : 'false');
				} elseif (is_numeric($value)) {
					echo esc_html($value);
				} elseif (is_string($value) && !empty($value)) {
					echo '"' . esc_html($value) . '"';
				} else {
					echo esc_html__('Defined but empty/non-scalar', 'debugger-troubleshooter');
				}
			} else {
				echo esc_html__('Undefined', 'debugger-troubleshooter');
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</div></div>';

		echo '</div>'; // End .site-info-grid
	}

	/**
	 * Initializes the troubleshooting mode.
	 * This hook runs very early to ensure filters are applied before most of WP loads.
	 */
	public function init_troubleshooting_mode()
	{
		if (isset($_COOKIE[self::TROUBLESHOOT_COOKIE])) {
			$token = sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE]));
			$sessions = get_option('dbgtbl_sessions', array());

			if (isset($sessions[$token]) && is_array($sessions[$token])) {
				$this->troubleshoot_state = $sessions[$token];

				// Define DONOTCACHEPAGE to prevent caching plugins from interfering.
				if (!defined('DONOTCACHEPAGE')) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
					define('DONOTCACHEPAGE', true);
				}
				// Send no-cache headers as a secondary measure.
				nocache_headers();

				// Filter active plugins. Note: The actual plugin deactivation happens via the MU plugin.
				add_filter('option_active_plugins', array($this, 'filter_active_plugins'), 0);
				if (is_multisite()) {
					add_filter('site_option_active_sitewide_plugins', array($this, 'filter_active_sitewide_plugins'), 0);
				}

				// Filter theme.
				add_filter('pre_option_template', array($this, 'filter_theme'));
				add_filter('pre_option_stylesheet', array($this, 'filter_theme'));
			}
		}
	}

	/**
	 * Initializes the live debug mode.
	 */
	public function init_live_debug_mode()
	{
		if (get_option(self::DEBUG_MODE_OPTION, 'disabled') === 'enabled') {
			if (!defined('WP_DEBUG')) {
				define('WP_DEBUG', true);
			}
			if (!defined('WP_DEBUG_LOG')) {
				define('WP_DEBUG_LOG', true);
			}
			if (!defined('WP_DEBUG_DISPLAY')) {
				define('WP_DEBUG_DISPLAY', false);
			}
			// This is necessary for the feature to function as intended.
			// phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged
			@ini_set('display_errors', 0);
		}
	}

	/**
	 * Checks if troubleshooting mode is active for the current user.
	 *
	 * @return bool
	 */
	public function is_troubleshooting_active()
	{
		return !empty($this->troubleshoot_state);
	}

	/**
	 * Returns the current troubleshooting state.
	 *
	 * @return array|false
	 */
	public function get_troubleshoot_state()
	{
		return $this->troubleshoot_state;
	}

	/**
	 * Gets the content of the debug.log file (last N lines).
	 *
	 * @param int $lines_count The number of lines to retrieve from the end of the file.
	 * @return string
	 */
	private function get_debug_log_content($lines_count = 200)
	{
		$log_file = WP_CONTENT_DIR . '/debug.log';

		if (!file_exists($log_file) || !is_readable($log_file)) {
			return __('debug.log file does not exist or is not readable.', 'debugger-troubleshooter');
		}

		if (0 === filesize($log_file)) {
			return __('debug.log is empty.', 'debugger-troubleshooter');
		}

		// More efficient way to read last N lines of a large file.
		$file = new SplFileObject($log_file, 'r');
		$file->seek(PHP_INT_MAX);
		$last_line = $file->key();
		$lines = new LimitIterator($file, max(0, $last_line - $lines_count), $last_line);

		return implode('', iterator_to_array($lines));
	}

	/**
	 * AJAX handler to toggle Live Debug mode.
	 */
	public function ajax_toggle_debug_mode()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$current_status = get_option(self::DEBUG_MODE_OPTION, 'disabled');
		$new_status = ('enabled' === $current_status) ? 'disabled' : 'enabled';
		update_option(self::DEBUG_MODE_OPTION, $new_status);

		if ('enabled' === $new_status) {
			wp_send_json_success(array('message' => __('Live Debug mode enabled.', 'debugger-troubleshooter')));
		} else {
			wp_send_json_success(array('message' => __('Live Debug mode disabled.', 'debugger-troubleshooter')));
		}
	}

	/**
	 * AJAX handler to clear the debug log.
	 */
	public function ajax_clear_debug_log()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		global $wp_filesystem;
		if (!$wp_filesystem) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$log_file = WP_CONTENT_DIR . '/debug.log';

		if ($wp_filesystem->exists($log_file)) {
			if (!$wp_filesystem->is_writable($log_file)) {
				wp_send_json_error(array('message' => __('Debug log is not writable.', 'debugger-troubleshooter')));
			}
			if ($wp_filesystem->put_contents($log_file, '')) {
				wp_send_json_success(array('message' => __('Debug log cleared successfully.', 'debugger-troubleshooter')));
			} else {
				wp_send_json_error(array('message' => __('Could not clear the debug log.', 'debugger-troubleshooter')));
			}
		} else {
			wp_send_json_success(array('message' => __('Debug log does not exist.', 'debugger-troubleshooter')));
		}
	}


	/**
	 * Filters active plugins based on troubleshooting state.
	 *
	 * @param array $plugins Array of active plugins.
	 * @return array Filtered array of active plugins.
	 */
	public function filter_active_plugins($plugins)
	{
		if ($this->is_troubleshooting_active() && isset($this->troubleshoot_state['plugins'])) {
			return $this->troubleshoot_state['plugins'];
		}
		return $plugins;
	}

	/**
	 * Filters active sitewide plugins based on troubleshooting state for multisite.
	 *
	 * @param array $plugins Array of active sitewide plugins.
	 * @return array Filtered array of active sitewide plugins.
	 */
	public function filter_active_sitewide_plugins($plugins)
	{
		if ($this->is_troubleshooting_active() && isset($this->troubleshoot_state['sitewide_plugins'])) {
			// Convert indexed array from cookie back to associative array expected by 'active_sitewide_plugins'.
			$new_plugins = array();
			foreach ($this->troubleshoot_state['sitewide_plugins'] as $plugin_file) {
				$new_plugins[$plugin_file] = time(); // Value doesn't matter much for activation state.
			}
			return $new_plugins;
		}
		return $plugins;
	}

	/**
	 * Filters the active theme based on troubleshooting state.
	 *
	 * @param string|false $theme The active theme stylesheet or template.
	 * @return string|false Filtered theme stylesheet or template.
	 */
	public function filter_theme($theme)
	{
		if ($this->is_troubleshooting_active() && isset($this->troubleshoot_state['theme'])) {
			return $this->troubleshoot_state['theme'];
		}
		return $theme;
	}

	/**
	 * AJAX handler to toggle troubleshooting mode on/off.
	 */
	public function ajax_toggle_troubleshoot_mode()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$enable_mode = isset($_POST['enable']) ? (bool) $_POST['enable'] : false;

		if ($enable_mode) {
			// Get current active plugins and theme to initialize the troubleshooting state.
			$current_active_plugins = get_option('active_plugins', array());
			$current_theme = get_stylesheet();
			$current_sitewide_plugins = is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array();

			$state = array(
				'theme' => $current_theme,
				'plugins' => $current_active_plugins,
				'sitewide_plugins' => $current_sitewide_plugins,
				'timestamp' => time(),
			);
			
			$token = wp_generate_password(64, false);
			$sessions = get_option('dbgtbl_sessions', array());
			$sessions[$token] = $state;
			update_option('dbgtbl_sessions', $sessions);
			
			// Create MU plugin drop-in to intercept early plugin loading
			$this->install_mu_plugin();

			// Set cookie with HttpOnly flag for security, and secure flag if site is HTTPS.
			setcookie(self::TROUBLESHOOT_COOKIE, $token, array(
				'expires' => time() + DAY_IN_SECONDS,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'samesite' => 'Lax', // or 'Strict' if preferred, 'Lax' is a good balance.
				'httponly' => true,
				'secure' => is_ssl(),
			));
			wp_send_json_success(array('message' => __('Troubleshooting mode activated.', 'debugger-troubleshooter')));
		} else {
			$token = isset($_COOKIE[self::TROUBLESHOOT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE])) : false;
			if ($token) {
				$sessions = get_option('dbgtbl_sessions', array());
				unset($sessions[$token]);
				update_option('dbgtbl_sessions', $sessions);
				
				if (empty($sessions)) {
					$this->remove_mu_plugin();
				}
			}

			// Unset the cookie to exit troubleshooting mode.
			setcookie(self::TROUBLESHOOT_COOKIE, '', array(
				'expires' => time() - 3600, // Expire the cookie.
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'samesite' => 'Lax',
				'httponly' => true,
				'secure' => is_ssl(),
			));
			wp_send_json_success(array('message' => __('Troubleshooting mode deactivated.', 'debugger-troubleshooter')));
		}
	}

	/**
	 * AJAX handler to update troubleshooting state (theme/plugins).
	 */
	public function ajax_update_troubleshoot_state()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		// Sanitize inputs.
		$selected_theme = isset($_POST['theme']) ? sanitize_text_field(wp_unslash($_POST['theme'])) : get_stylesheet();
		$selected_plugins = isset($_POST['plugins']) && is_array($_POST['plugins']) ? array_map('sanitize_text_field', wp_unslash($_POST['plugins'])) : array();

		// For multisite, we need to distinguish regular active plugins from network active ones.
		$all_plugins = get_plugins(); // Get all installed plugins to validate existence.
		$current_sitewide_plugins = is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array();

		$new_active_plugins = array();
		$new_active_sitewide_plugins = array();

		foreach ($selected_plugins as $plugin_file) {
			// Check if the plugin file actually exists in the plugin directory.
			if (isset($all_plugins[$plugin_file])) {
				// If it's a network active plugin, add it to the sitewide array.
				if (is_multisite() && in_array($plugin_file, $current_sitewide_plugins, true)) {
					$new_active_sitewide_plugins[] = $plugin_file;
				} else {
					// Otherwise, add to regular active plugins.
					$new_active_plugins[] = $plugin_file;
				}
			}
		}

		$state = array(
			'theme' => $selected_theme,
			'plugins' => $new_active_plugins,
			'sitewide_plugins' => $new_active_sitewide_plugins,
			'timestamp' => time(),
		);

		$token = isset($_COOKIE[self::TROUBLESHOOT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE])) : false;
		if (!$token) {
			wp_send_json_error(array('message' => __('Troubleshooting session not found.', 'debugger-troubleshooter')));
		}

		$sessions = get_option('dbgtbl_sessions', array());
		if (isset($sessions[$token])) {
			$sessions[$token] = $state;
			update_option('dbgtbl_sessions', $sessions);
		} else {
			wp_send_json_error(array('message' => __('Invalid troubleshooting session.', 'debugger-troubleshooter')));
		}

		wp_send_json_success(array('message' => __('Troubleshooting state updated successfully. Refreshing page...', 'debugger-troubleshooter')));
	}

	/**
	 * Display an admin notice if troubleshooting mode is active.
	 */
	public function troubleshooting_mode_notice()
	{
		if ($this->is_troubleshooting_active()) {
			$troubleshoot_url = admin_url('tools.php?page=debug-troubleshooter');
			?>
			<div class="notice notice-warning is-dismissible debug-troubleshoot-notice">
				<p>
					<strong><?php esc_html_e('Troubleshooting Mode is Active!', 'debugger-troubleshooter'); ?></strong>
					<?php esc_html_e('You are currently in a special troubleshooting session. Your simulated theme and plugin states are not affecting the live site for other visitors.', 'debugger-troubleshooter'); ?>
					<a
						href="<?php echo esc_url($troubleshoot_url); ?>"><?php esc_html_e('Go to Debugger & Troubleshooter page to manage.', 'debugger-troubleshooter'); ?></a>
				</p>
			</div>
			<?php
		}
	}
	/**
	 * Initializes the user simulation mode.
	 */
	public function init_user_simulation()
	{
		if (isset($_COOKIE[self::SIMULATE_USER_COOKIE])) {
			$token = sanitize_text_field(wp_unslash($_COOKIE[self::SIMULATE_USER_COOKIE]));
			$sim_users = get_option('dbgtbl_sim_users', array());
			
			if (isset($sim_users[$token])) {
				$this->simulated_user_id = (int) $sim_users[$token];

				// Hook into determine_current_user to override the user ID.
				// Priority 20 ensures we run after most standard authentication checks.
				add_filter('determine_current_user', array($this, 'simulate_user_filter'), 20);
			}
		}
	}

	/**
	 * Filter to override the current user ID.
	 *
	 * @param int|false $user_id The determined user ID.
	 * @return int|false The simulated user ID or the original ID.
	 */
	public function simulate_user_filter($user_id)
	{
		if ($this->simulated_user_id) {
			return $this->simulated_user_id;
		}
		return $user_id;
	}

	/**
	 * Checks if user simulation is active.
	 *
	 * @return bool
	 */
	public function is_simulating_user()
	{
		return !empty($this->simulated_user_id);
	}

	/**
	 * Renders the User Role Simulator section content.
	 */
	public function render_user_simulation_section()
	{
		$users = get_users(array('fields' => array('ID', 'display_name', 'user_login'), 'number' => 50)); // Limit to 50 for performance in dropdown
		?>
		<div class="user-simulation-controls">
			<div class="debug-troubleshooter-card">
				<h3><?php esc_html_e('Select User to Simulate', 'debugger-troubleshooter'); ?></h3>
				<div class="flex items-center gap-4">
					<select id="simulate-user-select" class="regular-text">
						<option value=""><?php esc_html_e('-- Select a User --', 'debugger-troubleshooter'); ?></option>
						<?php foreach ($users as $user): ?>
							<option value="<?php echo esc_attr($user->ID); ?>">
								<?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button id="simulate-user-btn"
						class="button button-primary"><?php esc_html_e('Simulate User', 'debugger-troubleshooter'); ?></button>
				</div>
				<p class="description mt-2">
					<?php esc_html_e('Note: You can exit the simulation at any time using the "Exit Simulation" button in the Admin Bar.', 'debugger-troubleshooter'); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Adds an "Exit Simulation" button to the Admin Bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar object.
	 */
	public function admin_bar_exit_simulation($wp_admin_bar)
	{
		if ($this->is_simulating_user()) {
			$wp_admin_bar->add_node(array(
				'id' => 'debug-troubleshooter-exit-sim',
				'title' => '<span style="color: #ff4444; font-weight: bold;">' . __('Exit User Simulation', 'debugger-troubleshooter') . '</span>',
				'href' => '#',
				'meta' => array(
					'onclick' => 'debugTroubleshootExitSimulation(); return false;',
					'title' => __('Click to return to your original user account', 'debugger-troubleshooter'),
				),
			));
		}
	}

	/**
	 * Prints the inline script for exiting simulation from the admin bar.
	 */
	public function print_exit_simulation_script()
	{
		if (!$this->is_simulating_user()) {
			return;
		}

		$nonce = wp_create_nonce('debug_troubleshoot_nonce');
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
	 * AJAX handler to toggle User Simulation.
	 */
	public function ajax_toggle_simulate_user()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options') && !$this->is_simulating_user()) {
			// Only allow admins to START simulation.
			// Anyone (simulated user) can STOP simulation.
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$enable = isset($_REQUEST['enable']) ? (bool) $_REQUEST['enable'] : false;
		$user_id = isset($_REQUEST['user_id']) ? (int) $_REQUEST['user_id'] : 0;
		$is_post = isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'];

		if ($enable && $user_id) {
			$token = wp_generate_password(64, false);
			$sim_users = get_option('dbgtbl_sim_users', array());
			$sim_users[$token] = $user_id;
			update_option('dbgtbl_sim_users', $sim_users);

			// Set cookie
			setcookie(self::SIMULATE_USER_COOKIE, $token, array(
				'expires' => time() + DAY_IN_SECONDS,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'samesite' => 'Lax',
				'httponly' => true,
				'secure' => is_ssl(),
			));
			wp_send_json_success(array(
				'message'  => __('User simulation activated. Redirecting...', 'debugger-troubleshooter'),
				'redirect' => admin_url()
			));
		} else {
			$token = isset($_COOKIE[self::SIMULATE_USER_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::SIMULATE_USER_COOKIE])) : false;
			if ($token) {
				$sim_users = get_option('dbgtbl_sim_users', array());
				unset($sim_users[$token]);
				update_option('dbgtbl_sim_users', $sim_users);
			}

			// Clear cookie
			setcookie(self::SIMULATE_USER_COOKIE, '', array(
				'expires' => time() - 3600,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'samesite' => 'Lax',
				'httponly' => true,
				'secure' => is_ssl(),
			));

			if (!$is_post) {
				// If it was a GET request (from Admin Bar), redirect back to home or dashboard.
				wp_safe_redirect(admin_url());
				exit;
			}

			wp_send_json_success(array('message' => __('User simulation deactivated.', 'debugger-troubleshooter')));
		}
	}


	/**
	 * Stores the last mail error captured via wp_mail_failed hook.
	 *
	 * @var WP_Error|null
	 */
	private $last_mail_error = null;

	/**
	 * Captures mail failures.
	 *
	 * @param WP_Error $error The error object.
	 */
	public function capture_mail_failure($error)
	{
		$this->last_mail_error = $error;
	}

	/**
	 * AJAX handler to send a test email.
	 */
	public function ajax_send_test_email()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$to = isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';

		if (!is_email($to)) {
			wp_send_json_error(array('message' => __('Invalid email address.', 'debugger-troubleshooter')));
		}

		$subject = sprintf(__('Test Email from %s', 'debugger-troubleshooter'), get_bloginfo('name'));
		$message = sprintf(
			__("This is a test email sent from the Debugger & Troubleshooter plugin to verify mail delivery.\n\nSite: %s\nTime: %s\nSent by: %s\n\nIf you received this, your site's mail configuration is working correctly.", 'debugger-troubleshooter'),
			home_url(),
			current_time('mysql'),
			wp_get_current_user()->display_name
		);
		
		$headers = array('Content-Type: text/plain; charset=UTF-8');

		// Capture failure if it happens
		add_action('wp_mail_failed', array($this, 'capture_mail_failure'));

		$sent = wp_mail($to, $subject, $message, $headers);

		remove_action('wp_mail_failed', array($this, 'capture_mail_failure'));

		if ($sent) {
			wp_send_json_success(array(
				'message' => __('Test email sent successfully! Please check your inbox (and spam folder).', 'debugger-troubleshooter')
			));
		} else {
			$error_msg = __('The email could not be sent.', 'debugger-troubleshooter');
			$debug_info = '';

			if ($this->last_mail_error && is_wp_error($this->last_mail_error)) {
				$error_msg = $this->last_mail_error->get_error_message();
				$error_data = $this->last_mail_error->get_error_data();
				if (!empty($error_data)) {
					$debug_info = print_r($error_data, true);
				}
			} else {
				// Fallback: check for common issues
				if (!extension_loaded('openssl')) {
					$debug_info .= "\n- OpenSSL extension is not loaded.";
				}
				if (ini_get('sendmail_path') === false) {
					$debug_info .= "\n- sendmail_path is not configured in php.ini.";
				}
			}

			wp_send_json_error(array(
				'message' => $error_msg,
				'debug' => $debug_info
			));
		}
	}

	/**
	 * Installs the MU plugin used to intercept active plugins before standard plugins are loaded.
	 */
	private function install_mu_plugin()
	{
		$mu_dir = WPMU_PLUGIN_DIR;
		if (!is_dir($mu_dir)) {
			@mkdir($mu_dir, 0755, true);
		}

		$mu_file = $mu_dir . '/debugger-troubleshooter-mu.php';

		$mu_content = "<?php
/**
 * Plugin Name: Debugger & Troubleshooter (MU Plugin)
 * Description: Intercepts active plugins to apply troubleshooting mode correctly.
 * Version: 1.0
 * Author: Jhimross
 */

if (!defined('ABSPATH')) {
	exit;
}

// Ensure the token from cookie exists and maps to an active session.
if (isset(\$_COOKIE['wp_debug_troubleshoot_mode'])) {
	\$token = sanitize_text_field(wp_unslash(\$_COOKIE['wp_debug_troubleshoot_mode']));
	\$sessions = get_option('dbgtbl_sessions', array());

	if (isset(\$sessions[\$token]) && is_array(\$sessions[\$token])) {
		// Replace active plugins for this request
		add_filter('option_active_plugins', function (\$plugins) use (\$sessions, \$token) {
			if (isset(\$sessions[\$token]['plugins'])) {
				return \$sessions[\$token]['plugins'];
			}
			return \$plugins;
		}, 0);

		if (is_multisite()) {
			add_filter('site_option_active_sitewide_plugins', function (\$plugins) use (\$sessions, \$token) {
				if (isset(\$sessions[\$token]['sitewide_plugins'])) {
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
		@file_put_contents($mu_file, $mu_content);
	}

	/**
	 * Renders the Conflict Checker tab view.
	 */
	public function render_detective_tab()
	{
		$detective_state = false;
		if ($this->is_troubleshooting_active() && isset($this->troubleshoot_state['detective'])) {
			$detective_state = $this->troubleshoot_state['detective'];
		}

		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('Conflict Checker (Binary Troubleshooter)', 'debugger-troubleshooter'); ?></h2>
				<?php if ($detective_state && 'active' === $detective_state['status']): ?>
					<span class="status-active" style="animation: pulse 1.5s infinite;"><?php printf(esc_html__('Check Active - Step %d', 'debugger-troubleshooter'), $detective_state['step']); ?></span>
				<?php endif; ?>
			</div>
			<div class="section-content">
				<?php if (!$detective_state || 'inactive' === $detective_state['status']): ?>
					<!-- Setup Screen -->
					<div class="detective-setup-screen">
						<p class="description" style="margin-bottom: 20px; font-size: 14px; line-height: 1.6;">
							<?php esc_html_e('Is your site experiencing a fatal error, white screen of death, or buggy behavior? Instead of deactivating every plugin one by one, the Conflict Checker uses a smart binary search algorithm to find the exact culprit plugin in a few steps. Best of all, it only runs for your session, keeping the live site fully functional for your visitors.', 'debugger-troubleshooter'); ?>
						</p>

						<div class="debug-troubleshooter-card" style="padding: 20px;">
							<h3 style="margin-bottom: 15px;"><?php esc_html_e('1. Select Essential Plugins to Keep Active', 'debugger-troubleshooter'); ?></h3>
							<p class="description" style="margin-bottom: 15px;">
								<?php esc_html_e('Select any critical plugins that MUST remain active during troubleshooting (e.g. WooCommerce or Page Builders). The Debugger & Troubleshooter plugin is automatically kept active to prevent breaking this interface.', 'debugger-troubleshooter'); ?>
							</p>
							
							<div class="plugin-list" style="margin-bottom: 20px; max-height: 300px;">
								<?php
								$plugins = get_plugins();
								$active_plugins = (array) get_option('active_plugins', array());
								if (is_multisite()) {
									$active_plugins = array_unique(array_merge($active_plugins, array_keys(get_site_option('active_sitewide_plugins', array()))));
								}

								if (!empty($active_plugins)) {
									foreach ($active_plugins as $plugin_file) {
										if (isset($plugins[$plugin_file])) {
											$plugin_data = $plugins[$plugin_file];
											$is_own_plugin = (DBGTBL_BASENAME === $plugin_file);
											?>
											<label class="plugin-item flex items-center p-2 rounded-md transition-colors duration-200 <?php echo $is_own_plugin ? 'bg-gray-100 text-gray-400' : ''; ?>" style="margin-bottom: 5px;">
												<input type="checkbox" class="detective-keep-plugin" 
													value="<?php echo esc_attr($plugin_file); ?>" 
													<?php checked($is_own_plugin); ?>
													<?php disabled($is_own_plugin); ?>>
												<span class="ml-2">
													<strong><?php echo esc_html($plugin_data['Name']); ?></strong>
													<?php if ($is_own_plugin): ?>
														<span style="font-size: 10px; background: #e0e0e0; padding: 2px 5px; border-radius: 3px; margin-left: 5px; color: #555;"><?php esc_html_e('Required', 'debugger-troubleshooter'); ?></span>
													<?php endif; ?>
													<br><small><?php echo esc_html($plugin_data['Version']); ?></small>
												</span>
											</label>
											<?php
										}
									}
								} else {
									echo '<p>' . esc_html__('No active plugins found to troubleshoot.', 'debugger-troubleshooter') . '</p>';
								}
								?>
							</div>

							<button id="detective-start-btn" class="button button-primary button-large" <?php disabled(empty($active_plugins)); ?>>
								<span class="dashicons dashicons-search" style="margin-top: 4px; margin-right: 4px;"></span>
								<?php esc_html_e('Start Conflict Check', 'debugger-troubleshooter'); ?>
							</button>
						</div>
					</div>
				<?php elseif ('active' === $detective_state['status']): ?>
					<!-- Guided Steps Screen -->
					<div class="detective-steps-screen">
						<div style="background: #fdf6ec; border-left: 4px solid #e6a23c; padding: 15px; margin-bottom: 25px; border-radius: 0 4px 4px 0;">
							<h3 style="margin: 0 0 10px 0; color: #b57a1b; font-size: 1.1em; display: flex; align-items: center;">
								<span class="dashicons dashicons-info" style="margin-right: 8px;"></span>
								<?php printf(esc_html__('Step %d: Narrowing down the suspects', 'debugger-troubleshooter'), $detective_state['step']); ?>
							</h3>
							<p style="margin: 0; font-size: 13.5px; line-height: 1.5; color: #606266;">
								<?php esc_html_e('We have temporarily deactivated half of your suspect plugins in this troubleshooting session. Please test the bug on your website in another browser window or tab.', 'debugger-troubleshooter'); ?>
							</p>
						</div>

						<div class="debug-troubleshooter-card" style="padding: 25px; text-align: center; border-color: #dcdfe6; background: #fafafa;">
							<h3 style="font-size: 1.3em; margin-bottom: 20px; color: #2c3338;"><?php esc_html_e('Is the issue/error still happening on your site?', 'debugger-troubleshooter'); ?></h3>
							
							<div class="flex flex-col md:flex-row justify-center gap-4" style="max-width: 500px; margin: 0 auto 25px auto;">
								<button class="detective-answer-btn button-detective-broken" data-answer="broken" style="flex: 1; padding: 15px; height: auto; line-height: normal; font-size: 16px; font-weight: bold; border-radius: 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;">
									<span class="dashicons dashicons-warning" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 8px;"></span>
									<span><?php esc_html_e('Yes, still broken', 'debugger-troubleshooter'); ?></span>
									<small style="font-size: 11px; font-weight: normal; margin-top: 5px; opacity: 0.8;"><?php esc_html_e('The bug is still active', 'debugger-troubleshooter'); ?></small>
								</button>
								
								<button class="detective-answer-btn button-detective-fixed" data-answer="fixed" style="flex: 1; padding: 15px; height: auto; line-height: normal; font-size: 16px; font-weight: bold; border-radius: 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;">
									<span class="dashicons dashicons-yes-alt" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 8px;"></span>
									<span><?php esc_html_e('No, it is fixed!', 'debugger-troubleshooter'); ?></span>
									<small style="font-size: 11px; font-weight: normal; margin-top: 5px; opacity: 0.8;"><?php esc_html_e('The bug is gone', 'debugger-troubleshooter'); ?></small>
								</button>
							</div>

							<div class="detective-meta-info" style="border-top: 1px solid #eee; padding-top: 20px; text-align: left; max-width: 600px; margin: 0 auto;">
								<h4 style="margin-top: 0; font-size: 14px;"><?php esc_html_e('Current Suspect Summary:', 'debugger-troubleshooter'); ?></h4>
								<div class="flex justify-between" style="font-size: 13px; color: #606266; margin-bottom: 10px;">
									<span><strong><?php esc_html_e('Active suspects:', 'debugger-troubleshooter'); ?></strong> <?php echo count($detective_state['active_group']); ?></span>
									<span><strong><?php esc_html_e('Deactivated suspects:', 'debugger-troubleshooter'); ?></strong> <?php echo count($detective_state['deactivated_group']); ?></span>
								</div>
								
								<div class="flex gap-4">
									<div style="flex: 1; background: #fff; border: 1px solid #e4e7ed; border-radius: 4px; padding: 10px; max-height: 150px; overflow-y: auto;">
										<div style="font-size: 11px; font-weight: bold; color: #909399; margin-bottom: 5px; text-transform: uppercase;"><?php esc_html_e('Active Group', 'debugger-troubleshooter'); ?></div>
										<ul style="margin: 0; padding: 0; list-style: none; font-size: 12px;">
											<?php 
											$all_installed = get_plugins();
											foreach ($detective_state['active_group'] as $p_file): 
												$p_name = isset($all_installed[$p_file]) ? $all_installed[$p_file]['Name'] : $p_file;
											?>
												<li style="margin-bottom: 4px; border-bottom: 1px dashed #f0f0f0; padding-bottom: 2px; color: #67c23a;"><?php echo esc_html($p_name); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
									<div style="flex: 1; background: #fff; border: 1px solid #e4e7ed; border-radius: 4px; padding: 10px; max-height: 150px; overflow-y: auto;">
										<div style="font-size: 11px; font-weight: bold; color: #909399; margin-bottom: 5px; text-transform: uppercase;"><?php esc_html_e('Deactivated Group', 'debugger-troubleshooter'); ?></div>
										<ul style="margin: 0; padding: 0; list-style: none; font-size: 12px;">
											<?php 
											foreach ($detective_state['deactivated_group'] as $p_file): 
												$p_name = isset($all_installed[$p_file]) ? $all_installed[$p_file]['Name'] : $p_file;
											?>
												<li style="margin-bottom: 4px; border-bottom: 1px dashed #f0f0f0; padding-bottom: 2px; color: #f56c6c; text-decoration: line-through;"><?php echo esc_html($p_name); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								</div>

								<div style="text-align: center; margin-top: 25px;">
									<button id="detective-abort-btn" class="button button-link" style="color: #909399; font-size: 13px; text-decoration: none;">
										<span class="dashicons dashicons-no" style="margin-top: 4px;"></span>
										<?php esc_html_e('Abort Check and Restore All Plugins', 'debugger-troubleshooter'); ?>
									</button>
								</div>
							</div>
						</div>
					</div>
				<?php elseif ('found' === $detective_state['status']): ?>
					<!-- Culprit Identified Screen -->
					<div class="detective-result-screen">
						<div style="background: #f0f9eb; border: 1px solid #e1f3d8; border-radius: 6px; padding: 30px; text-align: center; margin-bottom: 20px;">
							<div style="color: #67c23a; font-size: 48px; line-height: 1; margin-bottom: 15px;">
								<span class="dashicons dashicons-search" style="font-size: 64px; width: 64px; height: 64px; display: inline-block;"></span>
							</div>
							<h3 style="font-size: 1.8em; margin: 0 0 10px 0; color: #303133;"><?php esc_html_e('Conflict Found! Culprit Identified', 'debugger-troubleshooter'); ?></h3>
							<p style="font-size: 14.5px; color: #606266; margin: 0 0 25px 0;">
								<?php esc_html_e('The binary troubleshooter has isolated the plugin that is causing the conflict on your site.', 'debugger-troubleshooter'); ?>
							</p>

							<?php
							$all_installed = get_plugins();
							$culprit_file = $detective_state['culprit'];
							$culprit_name = $culprit_file;
							$culprit_version = '';
							$culprit_author = '';
							if (isset($all_installed[$culprit_file])) {
								$culprit_name = $all_installed[$culprit_file]['Name'];
								$culprit_version = $all_installed[$culprit_file]['Version'];
								$culprit_author = $all_installed[$culprit_file]['AuthorName'];
							}
							?>

							<div class="debug-troubleshooter-card" style="max-width: 500px; margin: 0 auto 30px auto; padding: 20px; text-align: left; border-left: 6px solid #67c23a; background: #fff; box-shadow: 0 2px 12px 0 rgba(0,0,0,0.05);">
								<h4 style="margin: 0 0 8px 0; font-size: 18px; color: #303133;"><?php echo esc_html($culprit_name); ?></h4>
								<?php if ($culprit_version): ?>
									<p style="margin: 0 0 6px 0; font-size: 13px; color: #606266; border: none; padding: 0;">
										<strong><?php esc_html_e('Version:', 'debugger-troubleshooter'); ?></strong> <?php echo esc_html($culprit_version); ?>
									</p>
								<?php endif; ?>
								<?php if ($culprit_author): ?>
									<p style="margin: 0; font-size: 13px; color: #606266; border: none; padding: 0;">
										<strong><?php esc_html_e('Author:', 'debugger-troubleshooter'); ?></strong> <?php echo esc_html($culprit_author); ?>
									</p>
								<?php endif; ?>
							</div>

							<div class="flex flex-col md:flex-row justify-center gap-4" style="max-width: 550px; margin: 0 auto;">
								<button id="detective-deactivate-btn" class="button button-primary button-large" data-culprit="<?php echo esc_attr($culprit_file); ?>" style="flex: 1; padding: 12px; height: auto; font-size: 15px; font-weight: bold; border-radius: 4px;">
									<span class="dashicons dashicons-dismiss" style="margin-top: 2px;"></span>
									<?php esc_html_e('Deactivate Plugin Globally', 'debugger-troubleshooter'); ?>
								</button>
								<button id="detective-reset-only-btn" class="button button-secondary button-large" style="flex: 1; padding: 12px; height: auto; font-size: 15px; font-weight: bold; border-radius: 4px;">
									<span class="dashicons dashicons-backup" style="margin-top: 2px;"></span>
									<?php esc_html_e('Close Check (Keep Enabled)', 'debugger-troubleshooter'); ?>
								</button>
							</div>
						</div>
					</div>
				<?php else: ?>
					<!-- No Culprit Screen -->
					<div class="detective-no-culprit-screen">
						<div style="background: #fef0f0; border: 1px solid #fde2e2; border-radius: 6px; padding: 30px; text-align: center; margin-bottom: 20px;">
							<div style="color: #f56c6c; font-size: 48px; line-height: 1; margin-bottom: 15px;">
								<span class="dashicons dashicons-search" style="font-size: 64px; width: 64px; height: 64px; display: inline-block;"></span>
							</div>
							<h3 style="font-size: 1.8em; margin: 0 0 10px 0; color: #303133;"><?php esc_html_e('Unable to Locate Conflict Source', 'debugger-troubleshooter'); ?></h3>
							<p style="font-size: 14.5px; color: #606266; margin: 0 0 25px 0;">
								<?php esc_html_e('We went through all steps but could not isolate a single plugin. This can happen if the issue is theme-related, core-related, or if the answers given during the process were inconsistent.', 'debugger-troubleshooter'); ?>
							</p>
							<button id="detective-reset-fail-btn" class="button button-primary button-large">
								<?php esc_html_e('Restart Check', 'debugger-troubleshooter'); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the PHP Compatibility tab view.
	 */
	public function render_compatibility_tab()
	{
		$current_php = phpversion();
		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('PHP Version Compatibility Checker', 'debugger-troubleshooter'); ?></h2>
			</div>
			<div class="section-content">
				<p class="description" style="margin-bottom: 20px; font-size: 14px; line-height: 1.6;">
					<?php esc_html_e('Planning on upgrading your server\'s PHP version? Use this scanner to verify whether WordPress core, your active plugins, and your active theme are compatible with your target PHP version. It scans the plugin/theme metadata headers and checks the code files for deprecated functions and compatibility issues.', 'debugger-troubleshooter'); ?>
				</p>

				<div class="debug-troubleshooter-card" style="padding: 20px; margin-bottom: 25px; background: #fafafa; border-color: #e4e7ed;">
					<div class="flex flex-col md:flex-row gap-4 items-end" style="max-width: 600px;">
						<div style="flex: 1;">
							<label for="compat-target-php" class="block mb-2 font-medium" style="font-size: 14px;"><?php esc_html_e('Select Target PHP Version:', 'debugger-troubleshooter'); ?></label>
							<select id="compat-target-php" class="regular-text" style="width: 100%; height: 35px; border-radius: 4px;">
								<?php
								$php_versions = array('7.4', '8.0', '8.1', '8.2', '8.3', '8.4');
								// Extract major.minor from current php version
								$current_php_short = substr($current_php, 0, 3);
								foreach ($php_versions as $ver) {
									$label = 'PHP ' . $ver;
									if ($ver === $current_php_short) {
										$label .= esc_html__(' (Current Server PHP)', 'debugger-troubleshooter');
									}
									echo '<option value="' . esc_attr($ver) . '"' . selected($ver, $current_php_short, false) . '>' . esc_html($label) . '</option>';
								}
								?>
							</select>
						</div>
						<button id="compat-run-btn" class="button button-primary button-large" style="height: 35px;">
							<span class="dashicons dashicons-performance" style="margin-top: 4px; margin-right: 4px;"></span>
							<?php esc_html_e('Run Compatibility Check', 'debugger-troubleshooter'); ?>
						</button>
					</div>

					<!-- Progress Bar Wrapper -->
					<div id="compat-progress-wrapper" class="hidden" style="margin-top: 20px;">
						<div style="display: flex; justify-content: space-between; font-size: 13px; color: #606266; margin-bottom: 6px;">
							<span id="compat-progress-status"><?php esc_html_e('Initializing...', 'debugger-troubleshooter'); ?></span>
							<span id="compat-progress-percent">0%</span>
						</div>
						<div style="background: #e4e7ed; border-radius: 10px; height: 12px; overflow: hidden; width: 100%;">
							<div id="compat-progress-bar" style="background: #409eff; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
						</div>
					</div>
				</div>

				<!-- Compatibility Results Cards -->
				<div id="compat-results-wrapper" class="hidden">
					<div id="compat-summary" class="compat-summary hidden">
						<div class="compat-summary-item compatible" data-count="0">
							<span class="compat-summary-count">0</span>
							<span class="compat-summary-label"><?php esc_html_e('Compatible', 'debugger-troubleshooter'); ?></span>
						</div>
						<div class="compat-summary-item warning" data-count="0">
							<span class="compat-summary-count">0</span>
							<span class="compat-summary-label"><?php esc_html_e('Warnings', 'debugger-troubleshooter'); ?></span>
						</div>
						<div class="compat-summary-item incompatible" data-count="0">
							<span class="compat-summary-count">0</span>
							<span class="compat-summary-label"><?php esc_html_e('Incompatible', 'debugger-troubleshooter'); ?></span>
						</div>
					</div>
					<div id="compat-cards"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler to start a Conflict Check.
	 */
	public function ajax_detective_start()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$must_keep = isset($_POST['must_keep']) && is_array($_POST['must_keep']) ? array_map('sanitize_text_field', wp_unslash($_POST['must_keep'])) : array();
		
		// Force our own plugin to be kept active so the user can continue troubleshooting.
		if (!in_array(DBGTBL_BASENAME, $must_keep, true)) {
			$must_keep[] = DBGTBL_BASENAME;
		}

		// Retrieve all active plugins
		$current_active_plugins = get_option('active_plugins', array());
		$current_sitewide_plugins = is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array();
		
		$all_active_plugins = array_unique(array_merge($current_active_plugins, $current_sitewide_plugins));

		// Exclude must_keep plugins from suspects list
		$suspects = array_values(array_diff($all_active_plugins, $must_keep));

		$count = count($suspects);
		if ($count === 0) {
			wp_send_json_error(array('message' => __('There are no active suspect plugins to troubleshoot. Make sure there are other active plugins besides the excluded ones.', 'debugger-troubleshooter')));
		}

		// Split suspects into two halves: Active Group (Group A) and Deactivated Group (Group B)
		$half = (int) ceil($count / 2);
		$active_group = array_slice($suspects, 0, $half);
		$deactivated_group = array_slice($suspects, $half);

		// Get or initialize troubleshooting session token
		$token = isset($_COOKIE[self::TROUBLESHOOT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE])) : false;
		$sessions = get_option('dbgtbl_sessions', array());

		if (!$token || !isset($sessions[$token])) {
			$token = wp_generate_password(64, false);
			$state = array(
				'theme' => get_stylesheet(),
				'plugins' => $current_active_plugins,
				'sitewide_plugins' => $current_sitewide_plugins,
				'timestamp' => time(),
			);
			$sessions[$token] = $state;
			
			// Setup Cookie
			setcookie(self::TROUBLESHOOT_COOKIE, $token, array(
				'expires' => time() + DAY_IN_SECONDS,
				'path' => COOKIEPATH,
				'domain' => COOKIE_DOMAIN,
				'samesite' => 'Lax',
				'httponly' => true,
				'secure' => is_ssl(),
			));
		}

		// Configure troubleshooting state active plugins: must_keep + active_group
		$active_in_step = array_unique(array_merge($must_keep, $active_group));

		$session_plugins = array();
		$session_sitewide_plugins = array();

		foreach ($active_in_step as $p) {
			if (is_multisite() && in_array($p, $current_sitewide_plugins, true)) {
				$session_sitewide_plugins[] = $p;
			} else {
				$session_plugins[] = $p;
			}
		}

		// Save the detective state inside the troubleshooting session option
		$sessions[$token]['detective'] = array(
			'status' => 'active',
			'step' => 1,
			'original_plugins' => $current_active_plugins,
			'original_sitewide_plugins' => $current_sitewide_plugins,
			'must_keep' => $must_keep,
			'suspects' => $suspects,
			'active_group' => $active_group,
			'deactivated_group' => $deactivated_group,
			'culprit' => ''
		);

		$sessions[$token]['plugins'] = $session_plugins;
		$sessions[$token]['sitewide_plugins'] = $session_sitewide_plugins;

		update_option('dbgtbl_sessions', $sessions);
		$this->install_mu_plugin();

		wp_send_json_success(array(
			'message' => __('Conflict check started! Page will reload to apply deactivations.', 'debugger-troubleshooter'),
			'step' => 1
		));
	}

	/**
	 * AJAX handler to advance to the next step in a Conflict Check.
	 */
	public function ajax_detective_step()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$answer = isset($_POST['answer']) ? sanitize_text_field(wp_unslash($_POST['answer'])) : '';
		if (!in_array($answer, array('broken', 'fixed'), true)) {
			wp_send_json_error(array('message' => __('Invalid answer.', 'debugger-troubleshooter')));
		}

		$token = isset($_COOKIE[self::TROUBLESHOOT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE])) : false;
		$sessions = get_option('dbgtbl_sessions', array());

		if (!$token || !isset($sessions[$token]) || !isset($sessions[$token]['detective'])) {
			wp_send_json_error(array('message' => __('Troubleshooting session or conflict check not found.', 'debugger-troubleshooter')));
		}

		$detective = $sessions[$token]['detective'];

		if ('broken' === $answer) {
			// Bug is still happening, so the culprit is in the group that was kept ACTIVE
			$new_suspects = $detective['active_group'];
		} else {
			// Bug is fixed, so the culprit must be in the group that was DEACTIVATED
			$new_suspects = $detective['deactivated_group'];
		}

		$count = count($new_suspects);

		if ($count === 1) {
			// Culprit found!
			$detective['status'] = 'found';
			$detective['culprit'] = reset($new_suspects);
			$detective['active_group'] = array();
			$detective['deactivated_group'] = array();
			$detective['suspects'] = $new_suspects;

			$sessions[$token]['detective'] = $detective;
			update_option('dbgtbl_sessions', $sessions);

			wp_send_json_success(array(
				'message' => __('Culprit plugin identified successfully!', 'debugger-troubleshooter'),
				'status' => 'found',
				'culprit' => $detective['culprit']
			));
		} elseif ($count === 0) {
			// No culprit found (can happen due to dynamic triggers or inconsistent user reports)
			$detective['status'] = 'no_culprit';
			$detective['active_group'] = array();
			$detective['deactivated_group'] = array();

			$sessions[$token]['detective'] = $detective;
			update_option('dbgtbl_sessions', $sessions);

			wp_send_json_success(array(
				'message' => __('Could not isolate the culprit plugin.', 'debugger-troubleshooter'),
				'status' => 'no_culprit'
			));
		} else {
			// Continue halving the remaining suspects
			$half = (int) ceil($count / 2);
			$active_group = array_slice($new_suspects, 0, $half);
			$deactivated_group = array_slice($new_suspects, $half);

			$must_keep = $detective['must_keep'];
			$active_in_step = array_unique(array_merge($must_keep, $active_group));

			$session_plugins = array();
			$session_sitewide_plugins = array();

			$current_sitewide_plugins = is_multisite() ? array_keys(get_site_option('active_sitewide_plugins', array())) : array();

			foreach ($active_in_step as $p) {
				if (is_multisite() && in_array($p, $current_sitewide_plugins, true)) {
					$session_sitewide_plugins[] = $p;
				} else {
					$session_plugins[] = $p;
				}
			}

			// Update the detective details
			$detective['step']++;
			$detective['suspects'] = $new_suspects;
			$detective['active_group'] = $active_group;
			$detective['deactivated_group'] = $deactivated_group;

			$sessions[$token]['detective'] = $detective;
			$sessions[$token]['plugins'] = $session_plugins;
			$sessions[$token]['sitewide_plugins'] = $session_sitewide_plugins;

			update_option('dbgtbl_sessions', $sessions);

			wp_send_json_success(array(
				'message' => sprintf(__('Moving to Step %d. Reloading page...', 'debugger-troubleshooter'), $detective['step']),
				'status' => 'active',
				'step' => $detective['step']
			));
		}
	}

	/**
	 * AJAX handler to reset and abort a Conflict Check.
	 */
	public function ajax_detective_reset()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$deactivate_culprit = isset($_POST['deactivate_culprit']) ? (bool) $_POST['deactivate_culprit'] : false;

		$token = isset($_COOKIE[self::TROUBLESHOOT_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::TROUBLESHOOT_COOKIE])) : false;
		
		if ($token) {
			$sessions = get_option('dbgtbl_sessions', array());
			
			if (isset($sessions[$token]) && isset($sessions[$token]['detective'])) {
				$detective = $sessions[$token]['detective'];
				
				// Check if we need to deactivate the identified culprit plugin globally
				if ($deactivate_culprit && !empty($detective['culprit'])) {
					$culprit = $detective['culprit'];
					
					// Deactivate plugin globally
					deactivate_plugins($culprit);
					
					// Let's also check if it's network active on multisite and deactivate network-wide if necessary
					if (is_multisite() && is_plugin_active_for_network($culprit)) {
						deactivate_plugins($culprit, false, true);
					}
				}
			}

			// Clean up troubleshooting session
			unset($sessions[$token]);
			update_option('dbgtbl_sessions', $sessions);
			
			if (empty($sessions)) {
				$this->remove_mu_plugin();
			}
		}

		// Clear cookie
		setcookie(self::TROUBLESHOOT_COOKIE, '', array(
			'expires' => time() - 3600,
			'path' => COOKIEPATH,
			'domain' => COOKIE_DOMAIN,
			'samesite' => 'Lax',
			'httponly' => true,
			'secure' => is_ssl(),
		));

		wp_send_json_success(array('message' => __('Conflict check reset. Site returned to original settings.', 'debugger-troubleshooter')));
	}

	/**
	 * AJAX handler to start the compatibility check.
	 */
	public function ajax_compat_start()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$active_plugins = (array) get_option('active_plugins', array());
		if (is_multisite()) {
			$active_plugins = array_unique(array_merge($active_plugins, array_keys(get_site_option('active_sitewide_plugins', array()))));
		}

		$all_plugins = get_plugins();
		$items = array();

		// 1. WordPress Core
		$items[] = array(
			'id' => 'core',
			'name' => 'WordPress Core',
			'type' => 'core',
			'version' => get_bloginfo('version'),
		);

		// 2. Active Theme
		$theme = wp_get_theme();
		$items[] = array(
			'id' => 'theme',
			'name' => $theme->get('Name'),
			'type' => 'theme',
			'version' => $theme->get('Version'),
		);

		// 3. Active Plugins
		foreach ($active_plugins as $plugin_file) {
			if (isset($all_plugins[$plugin_file])) {
				$plugin_data = $all_plugins[$plugin_file];
				$items[] = array(
					'id' => 'plugin_' . md5($plugin_file),
					'name' => $plugin_data['Name'],
					'type' => 'plugin',
					'file' => $plugin_file,
					'version' => $plugin_data['Version'],
				);
			}
		}

		wp_send_json_success(array('items' => $items));
	}

	/**
	 * AJAX handler to check compatibility of a single item.
	 */
	public function ajax_compat_scan_item()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
		$type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
		$target_php = isset($_POST['target_php']) ? sanitize_text_field(wp_unslash($_POST['target_php'])) : '';

		if (!in_array($target_php, array('7.4', '8.0', '8.1', '8.2', '8.3', '8.4'), true)) {
			wp_send_json_error(array('message' => __('Invalid target PHP version.', 'debugger-troubleshooter')));
		}

		$status = 'compatible';
		$requires_php = '';
		$details = array();

		if ('core' === $type) {
			$wp_version = get_bloginfo('version');
			$compat = $this->check_core_compatibility($wp_version, $target_php);
			$status = $compat['status'];
			$details = $compat['details'];
		} elseif ('theme' === $type) {
			$theme = wp_get_theme();
			$requires_php = $theme->get('RequiresPHP') ?: '';

			if ($requires_php && version_compare($target_php, $requires_php, '<')) {
				$status = 'incompatible';
				$details[] = array(
					'level' => 'incompatible',
					'message' => sprintf(__('Requires PHP %s+ (selected version is PHP %s).', 'debugger-troubleshooter'), $requires_php, $target_php)
				);
			}

			// Scan theme folder
			$warnings = $this->scan_directory_for_compatibility(get_stylesheet_directory(), $target_php);
			if (!empty($warnings)) {
				if ('compatible' === $status) {
					$status = 'warning';
				}
				$details = array_merge($details, $warnings);
			}

			// If it's a child theme, also scan parent theme folder
			if (get_template_directory() !== get_stylesheet_directory()) {
				$parent_warnings = $this->scan_directory_for_compatibility(get_template_directory(), $target_php);
				if (!empty($parent_warnings)) {
					if ('compatible' === $status) {
						$status = 'warning';
					}
					$details = array_merge($details, $parent_warnings);
				}
			}
		} elseif ('plugin' === $type) {
			$plugin_file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
			$all_plugins = get_plugins();

			if (!isset($all_plugins[$plugin_file])) {
				wp_send_json_error(array('message' => __('Plugin file not found.', 'debugger-troubleshooter')));
			}

			$plugin_data = $all_plugins[$plugin_file];
			$requires_php = isset($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '';

			if ($requires_php && version_compare($target_php, $requires_php, '<')) {
				$status = 'incompatible';
				$details[] = array(
					'level' => 'incompatible',
					'message' => sprintf(__('Requires PHP %s+ (selected version is PHP %s).', 'debugger-troubleshooter'), $requires_php, $target_php)
				);
			}

			// Scan plugin directory (skip self to avoid false positives from our own pattern definitions)
			$warnings = array();
			if ($plugin_file !== DBGTBL_BASENAME) {
				$plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
				$warnings = $this->scan_directory_for_compatibility($plugin_dir, $target_php);
			}
			if (!empty($warnings)) {
				if ('compatible' === $status) {
					$status = 'warning';
				}
				$details = array_merge($details, $warnings);
			}
		}

		wp_send_json_success(array(
			'status' => $status,
			'requires_php' => $requires_php,
			'details' => $details
		));
	}

	/**
	 * Private helper to check core compatibility.
	 */
	private function check_core_compatibility($wp_version, $target_php)
	{
		$status = 'compatible';
		$details = array();

		$wp_float = (float) $wp_version;

		if (version_compare($target_php, '5.6', '<')) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				'message' => sprintf(__('WordPress requires PHP 5.6.20 or higher. Target PHP %s is not supported.', 'debugger-troubleshooter'), $target_php)
			);
		} elseif (version_compare($target_php, '8.0', '>=') && $wp_float < 5.6) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				'message' => sprintf(__('WordPress %s is not compatible with PHP %s. Recommended WordPress version for PHP 8.0+ is 5.6+.', 'debugger-troubleshooter'), $wp_version, $target_php)
			);
		} elseif (version_compare($target_php, '8.1', '>=') && $wp_float < 5.9) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				'message' => sprintf(__('WordPress %s is not compatible with PHP %s. Recommended WordPress version for PHP 8.1+ is 5.9+.', 'debugger-troubleshooter'), $wp_version, $target_php)
			);
		} elseif (version_compare($target_php, '8.2', '>=') && $wp_float < 6.1) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				'message' => sprintf(__('WordPress %s is not compatible with PHP %s. Recommended WordPress version for PHP 8.2+ is 6.1+.', 'debugger-troubleshooter'), $wp_version, $target_php)
			);
		} elseif (version_compare($target_php, '8.3', '>=') && $wp_float < 6.4) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				'message' => sprintf(__('WordPress %s is not compatible with PHP %s. Recommended WordPress version for PHP 8.3+ is 6.4+.', 'debugger-troubleshooter'), $wp_version, $target_php)
			);
		} elseif (version_compare($target_php, '8.4', '>=') && $wp_float < 6.6) {
			$status = 'warning';
			$details[] = array(
				'level' => 'warning',
				'message' => sprintf(__('WordPress %s has limited/beta support for PHP %s. Recommended WordPress version for PHP 8.4+ is 6.6+.', 'debugger-troubleshooter'), $wp_version, $target_php)
			);
		}

		return array('status' => $status, 'details' => $details);
	}

	/**
	 * Scans a directory of PHP files for compatibility issues.
	 */
	private function scan_directory_for_compatibility($dir, $target_php)
	{
		$warnings = array();

		if (!is_dir($dir) || !is_readable($dir)) {
			return $warnings;
		}

		$file_count = 0;
		$max_files = 40; // Prevent PHP execution timeout

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::SELF_FIRST
			);

			// Define regex pattern arrays based on target PHP version
			$patterns = array();

			// PHP 8.0+ Removals / Deprecations
			if (version_compare($target_php, '8.0', '>=')) {
				$patterns['create_function\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `create_function()`.', 'debugger-troubleshooter')
				);
				$patterns['\beach\s*\(\s*\$'] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `each()`. Replace with foreach.', 'debugger-troubleshooter')
				);
				$patterns['\bdefine_syslog_variables\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `define_syslog_variables()`.', 'debugger-troubleshooter')
				);
				$patterns['\bmagic_quotes_runtime\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `magic_quotes_runtime()`.', 'debugger-troubleshooter')
				);
				$patterns['\bget_magic_quotes_gpc\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `get_magic_quotes_gpc()`.', 'debugger-troubleshooter')
				);
				$patterns['\bget_magic_quotes_runtime\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `get_magic_quotes_runtime()`.', 'debugger-troubleshooter')
				);
				$patterns['\bconvert_cyr_string\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `convert_cyr_string()`.', 'debugger-troubleshooter')
				);
				$patterns['\bezmlm_hash\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `ezmlm_hash()`.', 'debugger-troubleshooter')
				);
				$patterns['\brestore_include_path\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `restore_include_path()`.', 'debugger-troubleshooter')
				);
				$patterns['\bhebrevc\s*\('] = array(
					'level' => 'incompatible',
					'message' => __('Use of removed function `hebrevc()`. Use nl2br(hebrev()) instead.', 'debugger-troubleshooter')
				);
				// Curly brace offset access: e.g. $arr{0}
				$patterns['\$[a-zA-Z_][a-zA-Z0-9_]*(?:->[a-zA-Z_][a-zA-Z0-9_]*)?\s*\{\s*[\'"]?\w+[\'"]?\s*\}'] = array(
					'level' => 'incompatible',
					'message' => __('Curly brace offset access is removed in PHP 8.0.', 'debugger-troubleshooter')
				);
			}

			// PHP 8.2+ Deprecations
			if (version_compare($target_php, '8.2', '>=')) {
				$patterns['\butf8_encode\s*\('] = array(
					'level' => 'warning',
					'message' => __('Use of deprecated function `utf8_encode()`. Use mb_convert_encoding instead.', 'debugger-troubleshooter')
				);
				$patterns['\butf8_decode\s*\('] = array(
					'level' => 'warning',
					'message' => __('Use of deprecated function `utf8_decode()`. Use mb_convert_encoding instead.', 'debugger-troubleshooter')
				);
				// ${var} string interpolation
				$patterns['["\'][^"\']*\$\{[a-zA-Z_][a-zA-Z0-9_]*\}[^"\']*["\']'] = array(
					'level' => 'warning',
					'message' => __('The ${var} string interpolation syntax is deprecated in PHP 8.2.', 'debugger-troubleshooter')
				);
			}

			foreach ($iterator as $fileinfo) {
				if ($fileinfo->isFile() && 'php' === $fileinfo->getExtension()) {
					$file_count++;
					if ($file_count > $max_files) {
						break;
					}

					$file_path = $fileinfo->getPathname();

					// Skip files larger than 150KB to keep things fast
					if ($fileinfo->getSize() > 150 * 1024) {
						continue;
					}

					$content = file_get_contents($file_path);
					if (false === $content) {
						continue;
					}

					// Scan line by line
					$lines = explode("\n", $content);
					foreach ($lines as $line_num => $line) {
						// Remove inline comments
						$clean_line = preg_replace('!/\*.*?\*/!s', '', $line);
						$clean_line = preg_replace('!//.*?$!m', '', $clean_line);

						foreach ($patterns as $pattern => $info) {
							if (preg_match('/' . $pattern . '/', $clean_line)) {
								// Format file path relative to plugin/theme dir
								$relative_path = str_replace(dirname(dirname($dir)), '', $file_path);
								$relative_path = ltrim($relative_path, '/\\');

								$warnings[] = array(
									'file' => $relative_path,
									'line' => $line_num + 1,
									'level' => $info['level'],
									'message' => $info['message'],
									'snippet' => trim($line),
								);
							}
						}
					}
				}
			}
		} catch (Exception $e) {
			// Fallback silently if iteration fails
		}

		return $warnings;
	}

	/**
	 * Removes the MU plugin when no longer needed.
	 */
	private function remove_mu_plugin()
	{
		$mu_file = WPMU_PLUGIN_DIR . '/debugger-troubleshooter-mu.php';
		if (file_exists($mu_file)) {
			@unlink($mu_file);
		}
	}
}

// Initialize the plugin.
new Debug_Troubleshooter();
