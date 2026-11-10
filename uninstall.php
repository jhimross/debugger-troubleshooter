<?php
/**
 * Uninstall routine for Debugger & Troubleshooter.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

$dbgtbl_delete_site_data = static function () {
	delete_option('dbgtbl_sessions');
	delete_option('dbgtbl_sim_users');
	delete_option('wp_debug_troubleshoot_debug_mode');
	delete_option('dbgtbl_profiler_users');
};

if (is_multisite()) {
	$dbgtbl_site_ids = get_sites(array('fields' => 'ids'));

	foreach ($dbgtbl_site_ids as $dbgtbl_site_id) {
		switch_to_blog($dbgtbl_site_id);
		$dbgtbl_delete_site_data();
		restore_current_blog();
	}
} else {
	$dbgtbl_delete_site_data();
}

// Profiler history is stored per user (user meta is shared across a network).
delete_metadata('user', 0, 'dbgtbl_profiler_history', '', true);

foreach (array('debugger-troubleshooter-mu.php', 'debugger-troubleshooter-early.php') as $dbgtbl_mu_name) {
	$dbgtbl_mu_file = WPMU_PLUGIN_DIR . '/' . $dbgtbl_mu_name;
	if (file_exists($dbgtbl_mu_file)) {
		wp_delete_file($dbgtbl_mu_file);
	}
}
