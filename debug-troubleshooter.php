<?php
/**
 * Plugin Name:       Debugger & Troubleshooter
 * Plugin URI:        https://wordpress.org/plugins/debugger-troubleshooter
 * Description:       A WordPress plugin for debugging and troubleshooting, allowing simulated plugin deactivation and theme switching without affecting the live site.
 * Version:           1.4.2
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
define('DBGTBL_VERSION', '1.4.2');
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
		$roles = wp_roles()->get_names();
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
