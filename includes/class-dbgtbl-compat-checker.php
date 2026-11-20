<?php
/**
 * PHP Compatibility tab: checks core, the active theme and active plugins against a target PHP version.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Compatibility tab UI and AJAX handlers. Code scanning is done by DBGTBL_Compat_Scanner.
 */
class DBGTBL_Compat_Checker
{
	/**
	 * PHP versions offered in the target dropdown.
	 */
	const TARGET_VERSIONS = array('7.4', '8.0', '8.1', '8.2', '8.3', '8.4');

	/**
	 * Registers hooks.
	 */
	public function __construct()
	{
		add_action('wp_ajax_debug_troubleshoot_compat_start', array($this, 'ajax_start'));
		add_action('wp_ajax_debug_troubleshoot_compat_scan_item', array($this, 'ajax_scan_item'));
	}

	/**
	 * Lowest WordPress version that supports each PHP version, and how serious running older is.
	 * Ordered from oldest PHP so the most fundamental problem is reported first.
	 *
	 * @return array<string, array{0:string,1:string}> PHP version => [WP version, level].
	 */
	private static function core_requirements()
	{
		return array(
			'8.0' => array('5.6', 'incompatible'),
			'8.1' => array('5.9', 'incompatible'),
			'8.2' => array('6.1', 'incompatible'),
			'8.3' => array('6.4', 'incompatible'),
			'8.4' => array('6.6', 'warning'),
		);
	}

	/**
	 * Checks whether a WordPress version supports a PHP version.
	 *
	 * @param string $wp_version WordPress version.
	 * @param string $target_php Target PHP version.
	 * @return array{status:string, details:array[]}
	 */
	public static function check_core($wp_version, $target_php)
	{
		foreach (self::core_requirements() as $php => $requirement) {
			list($min_wp, $level) = $requirement;

			if (version_compare($target_php, $php, '>=') && version_compare($wp_version, $min_wp, '<')) {
				$message = 'warning' === $level
					/* translators: 1: WordPress version, 2: Target PHP version, 3: Minimum WordPress version. */
					? __('WordPress %1$s has limited/beta support for PHP %2$s. Recommended WordPress version for this PHP version is %3$s+.', 'debugger-troubleshooter')
					/* translators: 1: WordPress version, 2: Target PHP version, 3: Minimum WordPress version. */
					: __('WordPress %1$s is not compatible with PHP %2$s. Recommended WordPress version for this PHP version is %3$s+.', 'debugger-troubleshooter');

				return array(
					'status' => $level,
					'details' => array(
						array(
							'level' => $level,
							'message' => sprintf($message, $wp_version, $target_php, $min_wp),
						),
					),
				);
			}
		}

		return array('status' => 'compatible', 'details' => array());
	}

	/**
	 * Combines a "Requires PHP" header check with a code scan into one result.
	 *
	 * @param string   $requires_php Requires PHP header value.
	 * @param string[] $paths        Paths to scan.
	 * @param string   $target_php   Target PHP version.
	 * @return array{status:string, requires_php:string, details:array[], scan_note:string}
	 */
	private static function check_package($requires_php, $paths, $target_php)
	{
		$status = 'compatible';
		$details = array();

		if ($requires_php && version_compare($target_php, $requires_php, '<')) {
			$status = 'incompatible';
			$details[] = array(
				'level' => 'incompatible',
				/* translators: 1: Required PHP version, 2: Selected target PHP version. */
				'message' => sprintf(__('Requires PHP %1$s+ (selected version is PHP %2$s).', 'debugger-troubleshooter'), $requires_php, $target_php),
			);
		}

		$scanned = 0;
		$skipped = 0;

		foreach ($paths as $path) {
			$scan = DBGTBL_Compat_Scanner::scan_path($path, $target_php);
			$scanned += $scan['scanned'];
			$skipped += $scan['skipped'];

			// Code findings raise the status to a warning only: the code may be guarded or never run.
			foreach ($scan['issues'] as $issue) {
				$details[] = $issue;
				if ('compatible' === $status) {
					$status = 'warning';
				}
			}
		}

		return array(
			'status' => $status,
			'requires_php' => $requires_php,
			'details' => $details,
			'scan_note' => self::scan_note($scanned, $skipped),
		);
	}

	/**
	 * Short text describing how many files were scanned.
	 *
	 * @param int $scanned Files scanned.
	 * @param int $skipped Files skipped.
	 * @return string
	 */
	private static function scan_note($scanned, $skipped)
	{
		if (!$scanned && !$skipped) {
			return '';
		}

		/* translators: %d: Number of PHP files. */
		$note = sprintf(_n('%d PHP file scanned', '%d PHP files scanned', $scanned, 'debugger-troubleshooter'), $scanned);

		if ($skipped) {
			/* translators: %d: Number of PHP files. */
			$note .= ' · ' . sprintf(_n('%d skipped (very large file or scan limit reached)', '%d skipped (very large files or scan limit reached)', $skipped, 'debugger-troubleshooter'), $skipped);
		}

		return $note;
	}

	/**
	 * AJAX: list the items to check.
	 */
	public function ajax_start()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$active_plugins = array_unique(array_merge((array) get_option('active_plugins', array()), DBGTBL_Session::network_active_plugins()));
		$all_plugins = get_plugins();
		$theme = wp_get_theme();

		$items = array(
			array(
				'id' => 'core',
				'name' => 'WordPress Core',
				'type' => 'core',
				'version' => get_bloginfo('version'),
			),
			array(
				'id' => 'theme',
				'name' => $theme->get('Name'),
				'type' => 'theme',
				'version' => $theme->get('Version'),
			),
		);

		foreach ($active_plugins as $plugin_file) {
			if (isset($all_plugins[$plugin_file])) {
				$items[] = array(
					'id' => 'plugin_' . md5($plugin_file),
					'name' => $all_plugins[$plugin_file]['Name'],
					'type' => 'plugin',
					'file' => $plugin_file,
					'version' => $all_plugins[$plugin_file]['Version'],
				);
			}
		}

		wp_send_json_success(array('items' => $items));
	}

	/**
	 * AJAX: check one item.
	 */
	public function ajax_scan_item()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
		$target_php = isset($_POST['target_php']) ? sanitize_text_field(wp_unslash($_POST['target_php'])) : '';

		if (!in_array($target_php, self::TARGET_VERSIONS, true)) {
			wp_send_json_error(array('message' => __('Invalid target PHP version.', 'debugger-troubleshooter')));
		}

		if ('core' === $type) {
			$result = self::check_core(get_bloginfo('version'), $target_php);
			$result['requires_php'] = '';
			wp_send_json_success($result);
		}

		if ('theme' === $type) {
			$theme = wp_get_theme();
			$paths = array(get_stylesheet_directory());
			if (get_template_directory() !== get_stylesheet_directory()) {
				$paths[] = get_template_directory();
			}
			wp_send_json_success(self::check_package((string) $theme->get('RequiresPHP'), $paths, $target_php));
		}

		if ('plugin' === $type) {
			$plugin_file = isset($_POST['file']) ? sanitize_text_field(wp_unslash($_POST['file'])) : '';
			$all_plugins = get_plugins();

			if (!isset($all_plugins[$plugin_file])) {
				wp_send_json_error(array('message' => __('Plugin file not found.', 'debugger-troubleshooter')));
			}

			$requires_php = isset($all_plugins[$plugin_file]['RequiresPHP']) ? $all_plugins[$plugin_file]['RequiresPHP'] : '';
			$path = false !== strpos($plugin_file, '/') ? WP_PLUGIN_DIR . '/' . dirname($plugin_file) : WP_PLUGIN_DIR . '/' . $plugin_file;

			wp_send_json_success(self::check_package($requires_php, array($path), $target_php));
		}

		wp_send_json_error(array('message' => __('Unknown item type.', 'debugger-troubleshooter')));
	}

	/**
	 * Renders the PHP Compatibility tab view.
	 */
	public function render_tab()
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
								$php_versions = self::TARGET_VERSIONS;
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
						<button type="button" id="compat-run-btn" class="button button-primary button-large" style="height: 35px;">
							<span class="dashicons dashicons-performance"></span>
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
}
