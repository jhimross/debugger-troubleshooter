<?php
/**
 * Plugin Name:       Debugger & Troubleshooter
 * Plugin URI:        https://wordpress.org/plugins/debugger-troubleshooter
 * Description:       A WordPress plugin for debugging and troubleshooting, allowing simulated plugin deactivation and theme switching without affecting the live site.
 * Version:           1.6.1
 * Author:            Jhimross
 * Author URI:        https://profiles.wordpress.org/jhimross
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       debugger-troubleshooter
 */


// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Define plugin constants.
 */
define('DBGTBL_VERSION', '1.6.1');
define('DBGTBL_DIR', plugin_dir_path(__FILE__));
define('DBGTBL_URL', plugin_dir_url(__FILE__));
define('DBGTBL_BASENAME', plugin_basename(__FILE__));

require_once DBGTBL_DIR . 'includes/class-dbgtbl-components.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-early-mu.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-session.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-site-info.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-troubleshoot-mode.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-user-simulator.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-live-debug.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-log-viewer.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-mail-tester.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-conflict-checker.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-compat-scanner.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-compat-checker.php';
require_once DBGTBL_DIR . 'includes/class-dbgtbl-profiler.php';

// Applies Live Debug and profiler settings. Normally already loaded by the early MU
// loader; this is the fallback for when the MU file could not be written.
require_once DBGTBL_DIR . 'includes/early-loader.php';

/**
 * The main plugin class: creates each feature and renders the admin page.
 */
class Debug_Troubleshooter
{
	/**
	 * Kept for backward compatibility with code that read these constants.
	 */
	const TROUBLESHOOT_COOKIE = DBGTBL_Session::COOKIE;
	const DEBUG_MODE_OPTION = DBGTBL_Live_Debug::OPTION;
	const SIMULATE_USER_COOKIE = DBGTBL_User_Simulator::COOKIE;

	/**
	 * Troubleshooting Mode.
	 *
	 * @var DBGTBL_Troubleshoot_Mode
	 */
	private $troubleshoot_mode;

	/**
	 * User Role Simulator.
	 *
	 * @var DBGTBL_User_Simulator
	 */
	private $user_simulator;

	/**
	 * Live Debugging.
	 *
	 * @var DBGTBL_Live_Debug
	 */
	private $live_debug;

	/**
	 * Mail debugger.
	 *
	 * @var DBGTBL_Mail_Tester
	 */
	private $mail_tester;

	/**
	 * Conflict Checker.
	 *
	 * @var DBGTBL_Conflict_Checker
	 */
	private $conflict_checker;

	/**
	 * PHP Compatibility checker.
	 *
	 * @var DBGTBL_Compat_Checker
	 */
	private $compat_checker;

	/**
	 * Performance profiler.
	 *
	 * @var DBGTBL_Profiler
	 */
	private $profiler;

	/**
	 * Constructor.
	 */
	public function __construct()
	{
		// Order matters: these hook plugins_loaded at priority 0 and run in this order.
		$this->profiler = new DBGTBL_Profiler();
		new DBGTBL_Log_Viewer();
		$this->troubleshoot_mode = new DBGTBL_Troubleshoot_Mode();
		$this->user_simulator = new DBGTBL_User_Simulator();
		$this->live_debug = new DBGTBL_Live_Debug();
		$this->mail_tester = new DBGTBL_Mail_Tester();
		$this->conflict_checker = new DBGTBL_Conflict_Checker($this->troubleshoot_mode);
		$this->compat_checker = new DBGTBL_Compat_Checker();

		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
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
				'is_troubleshooting' => $this->troubleshoot_mode->is_active(),
				'current_state' => $this->troubleshoot_mode->get_state(),
				'is_debug_mode' => DBGTBL_Live_Debug::is_enabled(),
				'active_plugins' => get_option('active_plugins', array()),
				'active_sitewide_plugins' => DBGTBL_Session::network_active_plugins(),
				'current_theme' => get_stylesheet(),
				'alert_title_success' => __('Success', 'debugger-troubleshooter'),
				'alert_title_error' => __('Error', 'debugger-troubleshooter'),
				'copy_button_text' => __('Copy to Clipboard', 'debugger-troubleshooter'),
				'copied_button_text' => __('Copied!', 'debugger-troubleshooter'),
				'show_all_text' => __('Show All', 'debugger-troubleshooter'),
				'hide_text' => __('Hide', 'debugger-troubleshooter'),
				'is_simulating_user' => $this->user_simulator->is_active(),
				'i18n' => array(
					'levels' => array(
						'all' => __('All', 'debugger-troubleshooter'),
						'fatal' => __('Fatal', 'debugger-troubleshooter'),
						'warning' => __('Warning', 'debugger-troubleshooter'),
						'notice' => __('Notice', 'debugger-troubleshooter'),
						'deprecated' => __('Deprecated', 'debugger-troubleshooter'),
						'database' => __('Database', 'debugger-troubleshooter'),
						'other' => __('Other', 'debugger-troubleshooter'),
					),
					'all_sources' => __('All sources', 'debugger-troubleshooter'),
					'no_log' => __('No log file yet. It will be created when the first error is logged.', 'debugger-troubleshooter'),
					'not_readable' => __('The log file exists but is not readable by PHP.', 'debugger-troubleshooter'),
					'empty' => __('The log is empty. No errors have been logged.', 'debugger-troubleshooter'),
					'no_match' => __('No entries match the current filters.', 'debugger-troubleshooter'),
					/* translators: 1: Log file path, 2: File size, 3: Number of entries. */
					'meta' => __('%1$s · %2$s · %3$s entries', 'debugger-troubleshooter'),
					'truncated' => __('Showing entries from the last 2 MB of the log only.', 'debugger-troubleshooter'),
					'large' => __('The log file is very large. Download it for safekeeping, then clear it to keep the site fast.', 'debugger-troubleshooter'),
					/* translators: %d: Number of times an error occurred. */
					'times' => __('%d×', 'debugger-troubleshooter'),
					'first_seen' => __('First seen', 'debugger-troubleshooter'),
					'last_seen' => __('Last seen', 'debugger-troubleshooter'),
					'via_trace' => __('identified from the stack trace', 'debugger-troubleshooter'),
					'stack_trace' => __('Stack trace', 'debugger-troubleshooter'),
					/* translators: %s: Log file path. */
					'confirm_clear' => __('Are you sure you want to clear %s? This action cannot be undone.', 'debugger-troubleshooter'),
					'confirm_title' => __('Confirm Action', 'debugger-troubleshooter'),
					'confirm_clear_profiles' => __('Clear all recorded performance profiles?', 'debugger-troubleshooter'),
					'enable_profiler' => __('Enable Profiler', 'debugger-troubleshooter'),
					'disable_profiler' => __('Disable Profiler', 'debugger-troubleshooter'),
					'ajax_error' => __('An AJAX error occurred.', 'debugger-troubleshooter'),
				),
			)
		);
	}

	/**
	 * Renders the admin page content.
	 */
	public function render_admin_page()
	{
		?>
		<div class="wrap debug-troubleshooter-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Debugger & Troubleshooter', 'debugger-troubleshooter'); ?></h1>
			<hr class="wp-header-end">

			<!-- Navigation Tabs -->
			<h2 class="nav-tab-wrapper dbgtbl-nav-tabs" role="tablist" aria-label="<?php esc_attr_e('Debugger & Troubleshooter sections', 'debugger-troubleshooter'); ?>">
				<a href="#tab-general" id="tab-general" class="nav-tab nav-tab-active" data-tab="general" role="tab" aria-selected="true" aria-controls="tab-content-general"><?php esc_html_e('General Tools', 'debugger-troubleshooter'); ?></a>
				<a href="#tab-detective" id="tab-detective" class="nav-tab" data-tab="detective" role="tab" aria-selected="false" aria-controls="tab-content-detective" tabindex="-1"><?php esc_html_e('Conflict Checker', 'debugger-troubleshooter'); ?></a>
				<a href="#tab-compatibility" id="tab-compatibility" class="nav-tab" data-tab="compatibility" role="tab" aria-selected="false" aria-controls="tab-content-compatibility" tabindex="-1"><?php esc_html_e('PHP Compatibility', 'debugger-troubleshooter'); ?></a>
				<a href="#performance" id="tab-performance" class="nav-tab" data-tab="performance" role="tab" aria-selected="false" aria-controls="tab-content-performance" tabindex="-1"><?php esc_html_e('Performance', 'debugger-troubleshooter'); ?></a>
			</h2>

			<!-- Tab Content: General Tools -->
			<div id="tab-content-general" class="dbgtbl-tab-content" role="tabpanel" aria-labelledby="tab-general" tabindex="0">
				<div class="debug-troubleshooter-content">
					<div class="debug-troubleshooter-section">
						<div class="section-header">
							<h2><?php esc_html_e('Site Information', 'debugger-troubleshooter'); ?></h2>
							<button type="button" id="copy-site-info"
								class="button button-secondary"><?php esc_html_e('Copy to Clipboard', 'debugger-troubleshooter'); ?></button>
						</div>
						<div id="site-info-content" class="section-content">
							<?php DBGTBL_Site_Info::render(); ?>
						</div>
					</div>

					<?php $this->troubleshoot_mode->render_section(); ?>

					<?php $this->user_simulator->render_section(); ?>

					<?php $this->live_debug->render_section(); ?>

					<?php $this->mail_tester->render_section(); ?>
				</div>
			</div>

			<!-- Tab Content: Conflict Checker -->
			<div id="tab-content-detective" class="dbgtbl-tab-content hidden" role="tabpanel" aria-labelledby="tab-detective" tabindex="0">
				<?php $this->conflict_checker->render_tab(); ?>
			</div>

			<!-- Tab Content: PHP Compatibility -->
			<div id="tab-content-compatibility" class="dbgtbl-tab-content hidden" role="tabpanel" aria-labelledby="tab-compatibility" tabindex="0">
				<?php $this->compat_checker->render_tab(); ?>
			</div>

			<!-- Tab Content: Performance -->
			<div id="tab-content-performance" class="dbgtbl-tab-content hidden" role="tabpanel" aria-labelledby="tab-performance" tabindex="0">
				<?php $this->profiler->render_tab(); ?>
			</div>
		</div>

		<!-- Modal components -->
		<div id="debug-troubleshoot-alert-modal"
			class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50"
			role="dialog" aria-modal="true" aria-labelledby="debug-troubleshoot-alert-title">
			<div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
				<h3 id="debug-troubleshoot-alert-title" class="text-xl font-bold mb-4"></h3>
				<p id="debug-troubleshoot-alert-message" class="text-gray-700 mb-6"></p>
				<button type="button" id="debug-troubleshoot-alert-close"
					class="button button-primary"><?php esc_html_e('OK', 'debugger-troubleshooter'); ?></button>
			</div>
		</div>

		<div id="debug-troubleshoot-confirm-modal"
			class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50"
			role="dialog" aria-modal="true" aria-labelledby="debug-troubleshoot-confirm-title">
			<div class="bg-white p-6 rounded-lg shadow-xl max-w-sm w-full text-center">
				<h3 id="debug-troubleshoot-confirm-title" class="text-xl font-bold mb-4"></h3>
				<p id="debug-troubleshoot-confirm-message" class="text-gray-700 mb-6"></p>
				<div class="confirm-buttons">
					<button id="debug-troubleshoot-confirm-cancel" type="button"
						class="button button-secondary"><?php esc_html_e('Cancel', 'debugger-troubleshooter'); ?></button>
					<button id="debug-troubleshoot-confirm-ok" type="button"
						class="button button-danger"><?php esc_html_e('Confirm', 'debugger-troubleshooter'); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}

// Initialize the plugin.
new Debug_Troubleshooter();

/**
 * Removes the early MU loader on deactivation (it would be inert anyway, but keep things tidy).
 */
register_deactivation_hook(__FILE__, array('DBGTBL_Early_MU', 'remove'));

/**
 * Re-installs the early MU loader on activation if Live Debug or the profiler was left on.
 */
register_activation_hook(__FILE__, array('DBGTBL_Early_MU', 'sync'));
