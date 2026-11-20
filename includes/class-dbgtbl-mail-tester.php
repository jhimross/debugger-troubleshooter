<?php
/**
 * SMTP / Mail Debugger: sends a test email and reports the failure reason.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Test email sender.
 */
class DBGTBL_Mail_Tester
{
	/**
	 * Last error captured by the wp_mail_failed hook.
	 *
	 * @var WP_Error|null
	 */
	private $last_error = null;

	/**
	 * Registers hooks.
	 */
	public function __construct()
	{
		add_action('wp_ajax_debug_troubleshoot_send_test_email', array($this, 'ajax_send'));
	}

	/**
	 * Captures mail failures.
	 *
	 * @param WP_Error $error Error.
	 */
	public function capture_failure($error)
	{
		$this->last_error = $error;
	}

	/**
	 * Renders the mail debugger section.
	 */
	public function render_section()
	{
		$admin_email = get_option('admin_email');
		?>
		<div class="debug-troubleshooter-section standalone-section full-width-section">
			<div class="section-header">
				<h2><?php esc_html_e('SMTP / Mail Debugger', 'debugger-troubleshooter'); ?></h2>
			</div>
			<div class="section-content">
				<p class="description">
					<?php esc_html_e('Send a test email to verify your site\'s mail configuration. If the email fails, the plugin will attempt to capture the exact error message.', 'debugger-troubleshooter'); ?>
				</p>
				<div class="mail-debugger-form mt-4">
					<div class="dbgtbl-mail-row flex flex-col md:flex-row gap-4 items-end">
						<div class="flex-1">
							<label for="test-email-recipient" class="block mb-2 font-medium"><?php esc_html_e('Recipient Email Address', 'debugger-troubleshooter'); ?></label>
							<input type="email" id="test-email-recipient" class="regular-text w-full"
								placeholder="<?php echo esc_attr($admin_email); ?>"
								value="<?php echo esc_attr($admin_email); ?>">
						</div>
						<button type="button" id="send-test-email" class="button button-primary button-large">
							<?php esc_html_e('Send Test Email', 'debugger-troubleshooter'); ?>
						</button>
					</div>
					<div id="mail-debug-result" class="hidden mt-4 p-4 rounded-md border" role="status" aria-live="polite">
						<h4 class="mt-0 font-bold" id="mail-debug-result-title"></h4>
						<div id="mail-debug-result-message" class="text-sm font-mono whitespace-pre-wrap"></div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: send a test email.
	 */
	public function ajax_send()
	{
		check_ajax_referer('debug_troubleshoot_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Permission denied.', 'debugger-troubleshooter')));
		}

		$to = isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';

		if (!is_email($to)) {
			wp_send_json_error(array('message' => __('Invalid email address.', 'debugger-troubleshooter')));
		}

		/* translators: %s: Site name. */
		$subject = sprintf(__('Test Email from %s', 'debugger-troubleshooter'), get_bloginfo('name'));
		$message = sprintf(
			/* translators: 1: Site URL, 2: Current time, 3: User display name. */
			__("This is a test email sent from the Debugger & Troubleshooter plugin to verify mail delivery.\n\nSite: %1\$s\nTime: %2\$s\nSent by: %3\$s\n\nIf you received this, your site's mail configuration is working correctly.", 'debugger-troubleshooter'),
			home_url(),
			current_time('mysql'),
			wp_get_current_user()->display_name
		);

		$this->last_error = null;
		add_action('wp_mail_failed', array($this, 'capture_failure'));
		$sent = wp_mail($to, $subject, $message, array('Content-Type: text/plain; charset=UTF-8'));
		remove_action('wp_mail_failed', array($this, 'capture_failure'));

		if ($sent) {
			wp_send_json_success(array(
				'message' => __('Test email sent successfully! Please check your inbox (and spam folder).', 'debugger-troubleshooter'),
			));
		}

		$error_msg = __('The email could not be sent.', 'debugger-troubleshooter');
		$debug_info = '';

		if ($this->last_error && is_wp_error($this->last_error)) {
			$error_msg = $this->last_error->get_error_message();
			$error_data = $this->last_error->get_error_data();
			if (!empty($error_data)) {
				$debug_info = wp_json_encode($error_data, JSON_PRETTY_PRINT);
				if (false === $debug_info) {
					$debug_info = '';
				}
			}
		} else {
			if (!extension_loaded('openssl')) {
				$debug_info .= "\n- OpenSSL extension is not loaded.";
			}
			if (false === ini_get('sendmail_path')) {
				$debug_info .= "\n- sendmail_path is not configured in php.ini.";
			}
		}

		wp_send_json_error(array(
			'message' => $error_msg,
			'debug' => $debug_info,
		));
	}
}
