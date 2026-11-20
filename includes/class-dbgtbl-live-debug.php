<?php
/**
 * Live Debugging: turns on error logging without editing wp-config.php.
 *
 * The setting is applied by includes/early-loader.php (normally via the early MU loader),
 * because WP_DEBUG cannot be defined by a regular plugin.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Live Debug toggle, log clearing and the Live Debugging section.
 */
class DBGTBL_Live_Debug
{
	/**
	 * Option storing 'enabled' or 'disabled'.
	 */
	const OPTION = 'wp_debug_troubleshoot_debug_mode';

	/**
	 * Registers hooks.
	 */
	public function __construct()
	{
		add_action('wp_ajax_debug_troubleshoot_toggle_debug_mode', array($this, 'ajax_toggle_debug_mode'));
		add_action('wp_ajax_debug_troubleshoot_clear_debug_log', array($this, 'ajax_clear_debug_log'));
	}

	/**
	 * Whether Live Debug is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		return 'enabled' === get_option(self::OPTION, 'disabled');
	}

	/**
	 * Renders the Live Debugging section with the log viewer.
	 */
	public function render_section()
	{
		$is_debug_mode_enabled = self::is_enabled();
		$core_debug_logging = dbgtbl_core_debug_logging_active();
		$early_loader_missing = $is_debug_mode_enabled && !file_exists(DBGTBL_Early_MU::path());
		?>
	<div class="debug-troubleshooter-section standalone-section full-width-section">
		<div class="section-header">
			<h2><?php esc_html_e('Live Debugging', 'debugger-troubleshooter'); ?></h2>
			<button type="button" id="debug-mode-toggle"
				class="button button-large <?php echo $is_debug_mode_enabled ? 'button-danger' : 'button-primary'; ?>">
				<?php echo $is_debug_mode_enabled ? esc_html__('Disable Live Debug', 'debugger-troubleshooter') : esc_html__('Enable Live Debug', 'debugger-troubleshooter'); ?>
			</button>
		</div>
		<div class="section-content">
			<p class="description">
				<?php esc_html_e('Log PHP errors, warnings, notices and WordPress deprecation notices without editing wp-config.php. Errors are written to the log file only and are never shown to visitors.', 'debugger-troubleshooter'); ?>
			</p>
			<?php if ($core_debug_logging) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e('WP_DEBUG and WP_DEBUG_LOG are already enabled in wp-config.php, so errors are being logged. Live Debug is not needed, but you can still use the log viewer below.', 'debugger-troubleshooter'); ?></p>
				</div>
			<?php endif; ?>
			<?php if ($early_loader_missing) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e('The early loader could not be written to wp-content/mu-plugins. Live Debug still works, but errors from plugins that load before this one may be missed. Check that the folder is writable, then disable and re-enable Live Debug.', 'debugger-troubleshooter'); ?></p>
				</div>
			<?php endif; ?>

			<div class="debug-log-viewer-wrapper" id="dbgtbl-log-viewer">
				<div class="debug-log-header">
					<h3><?php esc_html_e('Debug Log Viewer', 'debugger-troubleshooter'); ?></h3>
					<div class="dbgtbl-log-actions">
						<label class="dbgtbl-autorefresh">
							<input type="checkbox" id="dbgtbl-log-autorefresh">
							<?php esc_html_e('Auto-refresh', 'debugger-troubleshooter'); ?>
						</label>
						<button id="dbgtbl-log-refresh" type="button" class="button"><span class="dashicons dashicons-update"></span> <?php esc_html_e('Refresh', 'debugger-troubleshooter'); ?></button>
						<a id="dbgtbl-log-download" class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dbgtbl_download_log'), 'dbgtbl_download_log')); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e('Download', 'debugger-troubleshooter'); ?></a>
						<button id="clear-debug-log" type="button" class="button button-secondary"><?php esc_html_e('Clear Log', 'debugger-troubleshooter'); ?></button>
					</div>
				</div>

				<div id="dbgtbl-log-meta" class="dbgtbl-log-meta"></div>
				<div id="dbgtbl-log-warning" class="notice notice-warning inline hidden"><p></p></div>

				<div class="dbgtbl-log-toolbar">
					<div class="dbgtbl-log-levels" id="dbgtbl-log-levels" role="group" aria-label="<?php esc_attr_e('Filter by level', 'debugger-troubleshooter'); ?>"></div>
					<div class="dbgtbl-log-filters">
						<select id="dbgtbl-log-component" aria-label="<?php esc_attr_e('Filter by source', 'debugger-troubleshooter'); ?>">
							<option value=""><?php esc_html_e('All sources', 'debugger-troubleshooter'); ?></option>
						</select>
						<input type="search" id="dbgtbl-log-search" placeholder="<?php esc_attr_e('Search messages…', 'debugger-troubleshooter'); ?>">
						<span class="dbgtbl-view-toggle" role="group" aria-label="<?php esc_attr_e('View', 'debugger-troubleshooter'); ?>">
							<button type="button" class="button dbgtbl-view active" data-view="grouped"><?php esc_html_e('Grouped', 'debugger-troubleshooter'); ?></button>
							<button type="button" class="button dbgtbl-view" data-view="raw"><?php esc_html_e('Raw', 'debugger-troubleshooter'); ?></button>
						</span>
					</div>
				</div>

				<div id="dbgtbl-log-groups" class="dbgtbl-log-groups">
					<p class="dbgtbl-empty"><?php esc_html_e('Loading log…', 'debugger-troubleshooter'); ?></p>
				</div>
				<textarea id="debug-log-viewer" readonly class="large-text hidden"
					rows="18" aria-label="<?php esc_attr_e('Raw debug log', 'debugger-troubleshooter'); ?>"><?php echo esc_textarea(DBGTBL_Log_Viewer::get_raw_tail()); ?></textarea>
			</div>
		</div>
	</div>
		<?php
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

		$current_status = get_option(self::OPTION, 'disabled');
		$new_status = ('enabled' === $current_status) ? 'disabled' : 'enabled';

		update_option(self::OPTION, $new_status);

		// Install (or remove) the early loader so Live Debug applies before other plugins load.
		$early_ok = DBGTBL_Early_MU::sync();

		if ('enabled' === $new_status) {
			$message = sprintf(
				/* translators: %s: Log file path. */
				__('Live Debug enabled. PHP errors, warnings, notices and WordPress deprecation notices are now logged to %s (never shown to visitors).', 'debugger-troubleshooter'),
				DBGTBL_Components::relative_path(dbgtbl_core_debug_logging_active() ? DBGTBL_Log_Viewer::get_log_path() : dbgtbl_live_debug_log_path())
			);
			if (!$early_ok) {
				$message .= ' ' . __('Note: the early loader could not be written to wp-content/mu-plugins, so errors from plugins that load before this one may be missed.', 'debugger-troubleshooter');
			}
			wp_send_json_success(array('message' => $message));
		} else {
			wp_send_json_success(array('message' => __('Live Debug disabled.', 'debugger-troubleshooter')));
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
			if (!WP_Filesystem() || !$wp_filesystem) {
				wp_send_json_error(array('message' => __('Could not initialize the WordPress filesystem.', 'debugger-troubleshooter')));
			}
		}

		$log_file = DBGTBL_Log_Viewer::get_log_path();

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
}
