<?php
/**
 * Conflict Checker: finds the plugin causing a problem by halving the suspects each step.
 *
 * Runs inside a troubleshooting session, so only the current browser sees the
 * deactivated plugins.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Binary-search conflict checker.
 */
class DBGTBL_Conflict_Checker
{
	/**
	 * Troubleshooting mode, used to read the current session state.
	 *
	 * @var DBGTBL_Troubleshoot_Mode
	 */
	private $mode;

	/**
	 * Registers hooks.
	 *
	 * @param DBGTBL_Troubleshoot_Mode $mode Troubleshooting mode.
	 */
	public function __construct(DBGTBL_Troubleshoot_Mode $mode)
	{
		$this->mode = $mode;

		add_action('wp_ajax_debug_troubleshoot_detective_start', array($this, 'ajax_start'));
		add_action('wp_ajax_debug_troubleshoot_detective_step', array($this, 'ajax_step'));
		add_action('wp_ajax_debug_troubleshoot_detective_reset', array($this, 'ajax_reset'));
	}

	/**
	 * Splits suspects into the half kept active and the half deactivated.
	 * The active half gets the extra plugin when the count is odd.
	 *
	 * @param string[] $suspects Plugin basenames.
	 * @return array{0:string[],1:string[]} Active group and deactivated group.
	 */
	public static function split($suspects)
	{
		$suspects = array_values($suspects);
		$half = (int) ceil(count($suspects) / 2);
		return array(array_slice($suspects, 0, $half), array_slice($suspects, $half));
	}

	/**
	 * Builds the first-step checker state.
	 *
	 * @param string[] $all_active Every active plugin (site and network).
	 * @param string[] $must_keep  Plugins the user wants kept active. This plugin is always added.
	 * @return array|false Checker state, or false when there are no suspects.
	 */
	public static function initial_state($all_active, $must_keep)
	{
		if (!in_array(DBGTBL_BASENAME, $must_keep, true)) {
			$must_keep[] = DBGTBL_BASENAME;
		}

		$suspects = array_values(array_diff(array_unique($all_active), $must_keep));
		if (empty($suspects)) {
			return false;
		}

		list($active_group, $deactivated_group) = self::split($suspects);

		return array(
			'status' => 'active',
			'step' => 1,
			'must_keep' => array_values($must_keep),
			'suspects' => $suspects,
			'active_group' => $active_group,
			'deactivated_group' => $deactivated_group,
			'culprit' => '',
		);
	}

	/**
	 * Advances the checker after the user reports whether the problem is still there.
	 *
	 * @param array  $state  Current checker state.
	 * @param string $answer 'broken' (problem still happens) or 'fixed'.
	 * @return array New checker state. Status is 'active', 'found' or 'no_culprit'.
	 */
	public static function advance($state, $answer)
	{
		// Still broken: the culprit is among the plugins left active. Fixed: it was in the deactivated half.
		$new_suspects = array_values('broken' === $answer ? $state['active_group'] : $state['deactivated_group']);
		$count = count($new_suspects);

		if (1 === $count) {
			$state['status'] = 'found';
			$state['culprit'] = $new_suspects[0];
			$state['suspects'] = $new_suspects;
			$state['active_group'] = array();
			$state['deactivated_group'] = array();
			return $state;
		}

		if (0 === $count) {
			$state['status'] = 'no_culprit';
			$state['active_group'] = array();
			$state['deactivated_group'] = array();
			return $state;
		}

		list($active_group, $deactivated_group) = self::split($new_suspects);
		$state['step']++;
		$state['suspects'] = $new_suspects;
		$state['active_group'] = $active_group;
		$state['deactivated_group'] = $deactivated_group;
		return $state;
	}

	/**
	 * Plugins that should be active in the session for the given checker state.
	 *
	 * @param array $state Checker state.
	 * @return string[]
	 */
	public static function plugins_for_step($state)
	{
		return array_values(array_unique(array_merge($state['must_keep'], $state['active_group'])));
	}

	/**
	 * Writes the checker state and its plugin list into a session.
	 *
	 * @param array  $sessions All sessions.
	 * @param string $token    Session token.
	 * @param array  $state    Checker state.
	 * @return array Updated sessions.
	 */
	private static function apply_to_session($sessions, $token, $state)
	{
		$sessions[$token]['detective'] = $state;

		if ('active' === $state['status']) {
			list($site_plugins, $sitewide_plugins) = DBGTBL_Session::split_by_network(self::plugins_for_step($state));
			$sessions[$token]['plugins'] = $site_plugins;
			$sessions[$token]['sitewide_plugins'] = $sitewide_plugins;
		}

		return $sessions;
	}

	/**
	 * AJAX: start a conflict check.
	 */
	public function ajax_start()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$must_keep = array();
		if (isset($_POST['must_keep']) && is_array($_POST['must_keep'])) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Type validated and sanitized in the loop below.
			foreach (wp_unslash($_POST['must_keep']) as $plugin_file) {
				if (is_string($plugin_file)) {
					$must_keep[] = sanitize_text_field($plugin_file);
				}
			}
		}

		$site_active = (array) get_option('active_plugins', array());
		$network_active = DBGTBL_Session::network_active_plugins();

		$state = self::initial_state(array_merge($site_active, $network_active), $must_keep);
		if (!$state) {
			wp_send_json_error(array('message' => __('There are no active suspect plugins to troubleshoot. Make sure there are other active plugins besides the excluded ones.', 'debugger-troubleshooter')));
		}
		$state['original_plugins'] = $site_active;
		$state['original_sitewide_plugins'] = $network_active;

		DBGTBL_Session::prune_expired();

		if (!DBGTBL_Session::install_mu_plugin()) {
			wp_send_json_error(array('message' => __('Could not install the troubleshooting MU plugin. Please check filesystem permissions.', 'debugger-troubleshooter')));
		}

		$session = DBGTBL_Session::get_valid();
		$sessions = DBGTBL_Session::all();

		if (empty($session[1])) {
			$token = wp_generate_password(64, false);
			$sessions[$token] = DBGTBL_Session::new_state();
			DBGTBL_Session::set_cookie($token);
		} else {
			$token = $session[0];
			$sessions[$token]['timestamp'] = time();
		}

		DBGTBL_Session::save_all(self::apply_to_session($sessions, $token, $state));

		wp_send_json_success(array(
			'message' => __('Conflict check started! Page will reload to apply deactivations.', 'debugger-troubleshooter'),
			'step' => 1,
		));
	}

	/**
	 * AJAX: record the user's answer and move to the next step.
	 */
	public function ajax_step()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$answer = isset($_POST['answer']) ? sanitize_text_field(wp_unslash($_POST['answer'])) : '';
		if (!in_array($answer, array('broken', 'fixed'), true)) {
			wp_send_json_error(array('message' => __('Invalid answer.', 'debugger-troubleshooter')));
		}

		$session = DBGTBL_Session::get_valid();
		if (empty($session[1]) || !isset($session[1]['detective'])) {
			wp_send_json_error(array('message' => __('Troubleshooting session or conflict check not found.', 'debugger-troubleshooter')));
		}

		$token = $session[0];
		$sessions = DBGTBL_Session::all();
		$state = self::advance($sessions[$token]['detective'], $answer);
		DBGTBL_Session::save_all(self::apply_to_session($sessions, $token, $state));

		if ('found' === $state['status']) {
			wp_send_json_success(array(
				'message' => __('Culprit plugin identified successfully!', 'debugger-troubleshooter'),
				'status' => 'found',
				'culprit' => $state['culprit'],
			));
		}

		if ('no_culprit' === $state['status']) {
			wp_send_json_success(array(
				'message' => __('Could not isolate the culprit plugin.', 'debugger-troubleshooter'),
				'status' => 'no_culprit',
			));
		}

		wp_send_json_success(array(
			/* translators: %d: Conflict check step number. */
			'message' => sprintf(__('Moving to Step %d. Reloading page...', 'debugger-troubleshooter'), absint($state['step'])),
			'status' => 'active',
			'step' => $state['step'],
		));
	}

	/**
	 * AJAX: end the check, optionally deactivating the culprit for everyone.
	 */
	public function ajax_reset()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$deactivate_culprit = isset($_POST['deactivate_culprit']) ? (bool) $_POST['deactivate_culprit'] : false;
		$session = DBGTBL_Session::get_valid();

		if (!empty($session[1])) {
			$culprit = isset($session[1]['detective']['culprit']) ? $session[1]['detective']['culprit'] : '';

			if ($deactivate_culprit && $culprit && DBGTBL_BASENAME !== $culprit) {
				$all_plugins = get_plugins();
				if (isset($all_plugins[$culprit])) {
					// Work on the real plugin list, not the session's simulated one. Otherwise every
					// plugin switched off in the last step would be deactivated for everyone too.
					DBGTBL_Session::without_filters(static function () use ($culprit) {
						$network_wide = is_multisite() && is_plugin_active_for_network($culprit);
						deactivate_plugins($culprit, false, $network_wide ? true : null);
					});
				}
			}

			DBGTBL_Session::destroy($session[0]);
		} else {
			DBGTBL_Session::clear_cookie();
		}

		wp_send_json_success(array('message' => __('Conflict check reset. Site returned to original settings.', 'debugger-troubleshooter')));
	}

	/**
	 * Renders the Conflict Checker tab view.
	 */
	public function render_tab()
	{
		$session_state = $this->mode->get_state();
		$detective_state = $this->mode->is_active() && isset($session_state['detective']) ? $session_state['detective'] : false;

		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('Conflict Checker (Binary Troubleshooter)', 'debugger-troubleshooter'); ?></h2>
				<?php if ($detective_state && 'active' === $detective_state['status']): ?>
					<span class="status-active" style="animation: pulse 1.5s infinite;"><?php
						/* translators: %d: Conflict check step number. */
						printf(esc_html__('Check Active - Step %d', 'debugger-troubleshooter'), absint($detective_state['step']));
					?></span>
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

							<button type="button" id="detective-start-btn" class="button button-primary button-large" <?php disabled(empty($active_plugins)); ?>>
								<span class="dashicons dashicons-search"></span>
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
								<?php
								/* translators: %d: Conflict check step number. */
								printf(esc_html__('Step %d: Narrowing down the suspects', 'debugger-troubleshooter'), absint($detective_state['step']));
								?>
							</h3>
							<p style="margin: 0; font-size: 13.5px; line-height: 1.5; color: #606266;">
								<?php esc_html_e('We have temporarily deactivated half of your suspect plugins in this troubleshooting session. Please test the bug on your website in another browser window or tab.', 'debugger-troubleshooter'); ?>
							</p>
						</div>

						<div class="debug-troubleshooter-card" style="padding: 25px; text-align: center; border-color: #dcdfe6; background: #fafafa;">
							<h3 style="font-size: 1.3em; margin-bottom: 20px; color: #2c3338;"><?php esc_html_e('Is the issue/error still happening on your site?', 'debugger-troubleshooter'); ?></h3>
							
							<div class="flex flex-col md:flex-row justify-center gap-4" style="max-width: 500px; margin: 0 auto 25px auto;">
								<button type="button" class="detective-answer-btn button-detective-broken" data-answer="broken" style="flex: 1; padding: 15px; height: auto; line-height: normal; font-size: 16px; font-weight: bold; border-radius: 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;">
									<span class="dashicons dashicons-warning" style="font-size: 32px; width: 32px; height: 32px; margin-bottom: 8px;"></span>
									<span><?php esc_html_e('Yes, still broken', 'debugger-troubleshooter'); ?></span>
									<small style="font-size: 11px; font-weight: normal; margin-top: 5px; opacity: 0.8;"><?php esc_html_e('The bug is still active', 'debugger-troubleshooter'); ?></small>
								</button>
								
								<button type="button" class="detective-answer-btn button-detective-fixed" data-answer="fixed" style="flex: 1; padding: 15px; height: auto; line-height: normal; font-size: 16px; font-weight: bold; border-radius: 6px; cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;">
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
									<button type="button" id="detective-abort-btn" class="button button-link" style="color: #909399; font-size: 13px; text-decoration: none;">
										<span class="dashicons dashicons-no"></span>
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
								<button type="button" id="detective-deactivate-btn" class="button button-primary button-large" data-culprit="<?php echo esc_attr($culprit_file); ?>" style="flex: 1; padding: 12px; height: auto; font-size: 15px; font-weight: bold; border-radius: 4px;">
									<span class="dashicons dashicons-dismiss"></span>
									<?php esc_html_e('Deactivate Plugin Globally', 'debugger-troubleshooter'); ?>
								</button>
								<button type="button" id="detective-reset-only-btn" class="button button-secondary button-large" style="flex: 1; padding: 12px; height: auto; font-size: 15px; font-weight: bold; border-radius: 4px;">
									<span class="dashicons dashicons-backup"></span>
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
							<button type="button" id="detective-reset-fail-btn" class="button button-primary button-large">
								<?php esc_html_e('Restart Check', 'debugger-troubleshooter'); ?>
							</button>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
