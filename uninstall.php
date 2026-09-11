<?php
/**
 * Uninstall routine for the Debugger & Troubleshooter plugin.
 *
 * Deletes all plugin options and removes the automatically generated MU
 * plugin drop-in that is used to intercept active plugins during
 * troubleshooting mode.
 *
 * @package Debugger_Troubleshooter
 */

// Exit if not called by WordPress uninstall.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Delete plugin options.
delete_option('dbgtbl_sessions');
delete_option('dbgtbl_sim_users');
delete_option('wp_debug_troubleshoot_debug_mode');

// Remove the MU plugin drop-in used for troubleshooting mode.
$mu_file = WPMU_PLUGIN_DIR . '/debugger-troubleshooter-mu.php';
if (file_exists($mu_file)) {
	@unlink($mu_file);
}