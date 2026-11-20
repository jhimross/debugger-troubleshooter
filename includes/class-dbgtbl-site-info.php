<?php
/**
 * Site Information panel.
 *
 * @package Debugger_Troubleshooter
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Renders WordPress, PHP, database, server and constant details.
 */
class DBGTBL_Site_Info
{
	/**
	 * Describes the database the site is using.
	 *
	 * Studio, Playground and other SQLite setups answer `SELECT VERSION()` with an emulated
	 * MySQL version (e.g. "8.0.38-mysql-on-sqlite-3.1.3"), which is misleading, so the
	 * real SQLite library version is reported instead.
	 *
	 * @return array{engine:string, version:string, is_sqlite:bool}
	 */
	public static function database_details()
	{
		global $wpdb;

		$engine = defined('DB_ENGINE') && DB_ENGINE ? strtolower((string) DB_ENGINE) : 'mysql';

		if ('sqlite' === $engine) {
			$version = self::sqlite_library_version();
			if (defined('SQLITE_DRIVER_VERSION')) {
				/* translators: %s: SQLite Database Integration driver version. */
				$driver = sprintf(__('SQLite Database Integration %s', 'debugger-troubleshooter'), SQLITE_DRIVER_VERSION);
				$version = $version ? $version . ' (' . $driver . ')' : $driver;
			}

			return array(
				'engine' => 'SQLite',
				'version' => $version ? $version : __('Unknown', 'debugger-troubleshooter'),
				'is_sqlite' => true,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off diagnostic read of the server version.
		$version = (string) $wpdb->get_var('SELECT VERSION()');
		$label = 'mysql' === $engine ? 'MySQL' : ucfirst($engine);
		if ('mysql' === $engine && false !== stripos($version, 'mariadb')) {
			$label = 'MariaDB';
		}

		return array(
			'engine' => $label,
			'version' => $version,
			'is_sqlite' => false,
		);
	}

	/**
	 * Version of the SQLite library PHP is linked against.
	 *
	 * @return string Empty when it cannot be determined.
	 */
	private static function sqlite_library_version()
	{
		if (class_exists('SQLite3')) {
			$info = SQLite3::version();
			if (!empty($info['versionString'])) {
				return $info['versionString'];
			}
		}

		// The SQLite integration passes SQLite's own functions through. Older versions may not.
		global $wpdb;
		$suppress = $wpdb->suppress_errors(true);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off diagnostic read of the database version.
		$version = (string) $wpdb->get_var('SELECT sqlite_version()');
		$wpdb->suppress_errors($suppress);

		return preg_match('/^\d+\.\d+/', $version) ? $version : '';
	}

	/**
	 * Value of a constant for display, or "Not defined".
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private static function constant_or_na($name)
	{
		return defined($name) ? (string) constant($name) : __('Not defined', 'debugger-troubleshooter');
	}

	/**
	 * Renders the site information cards.
	 */
	public static function render()
	{
		global $wpdb;
		echo '<div class="site-info-grid">';

		// WordPress Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed" role="button" tabindex="0" aria-expanded="false" aria-controls="site-info-wordpress"><h3>' . esc_html__('WordPress Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></div>';
		echo '<div id="site-info-wordpress" class="card-collapsible-content hidden">';
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
			echo '<p><strong>' . esc_html__('Inactive Themes:', 'debugger-troubleshooter') . '</strong> ' . esc_html($inactive_themes_count) . ' &middot; <a href="#" class="info-sub-list-toggle" data-target="themes-list">' . esc_html__('Show All', 'debugger-troubleshooter') . '</a></p>';
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
		echo '<p><strong>' . esc_html__('Inactive Plugins:', 'debugger-troubleshooter') . '</strong> ' . esc_html($inactive_plugins_count) . ' &middot; <a href="#" class="info-sub-list-toggle" data-target="plugins-list">' . esc_html__('Show All', 'debugger-troubleshooter') . '</a></p>';

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
		echo '<div class="card-collapsible-header collapsed" role="button" tabindex="0" aria-expanded="false" aria-controls="site-info-php"><h3>' . esc_html__('PHP Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></div>';
		echo '<div id="site-info-php" class="card-collapsible-content hidden">';
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
		$db = self::database_details();

		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed" role="button" tabindex="0" aria-expanded="false" aria-controls="site-info-database"><h3>' . esc_html__('Database Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></div>';
		echo '<div id="site-info-database" class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('Database Engine:', 'debugger-troubleshooter') . '</strong> ' . esc_html($db['engine']) . '</p>';
		if ($db['is_sqlite']) {
			echo '<p><strong>' . esc_html__('SQLite Version:', 'debugger-troubleshooter') . '</strong> ' . esc_html($db['version']) . '</p>';
			if (defined('FQDB')) {
				echo '<p><strong>' . esc_html__('Database File:', 'debugger-troubleshooter') . '</strong> ' . esc_html(DBGTBL_Components::relative_path(FQDB)) . '</p>';
			}
		} else {
			echo '<p><strong>' . esc_html__('Database Server Version:', 'debugger-troubleshooter') . '</strong> ' . esc_html($db['version']) . '</p>';
			echo '<p><strong>' . esc_html__('DB Name:', 'debugger-troubleshooter') . '</strong> ' . esc_html(self::constant_or_na('DB_NAME')) . '</p>';
			echo '<p><strong>' . esc_html__('DB Host:', 'debugger-troubleshooter') . '</strong> ' . esc_html(self::constant_or_na('DB_HOST')) . '</p>';
		}
		echo '<p><strong>' . esc_html__('DB Charset:', 'debugger-troubleshooter') . '</strong> ' . esc_html($wpdb->charset ? $wpdb->charset : self::constant_or_na('DB_CHARSET')) . '</p>';
		echo '<p><strong>' . esc_html__('DB Collate:', 'debugger-troubleshooter') . '</strong> ' . esc_html($wpdb->collate ? $wpdb->collate : self::constant_or_na('DB_COLLATE')) . '</p>';
		echo '</div></div>';

		// Server Information Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed" role="button" tabindex="0" aria-expanded="false" aria-controls="site-info-server"><h3>' . esc_html__('Server Information', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></div>';
		echo '<div id="site-info-server" class="card-collapsible-content hidden">';
		echo '<p><strong>' . esc_html__('Web Server:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_SOFTWARE'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Server Protocol:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_PROTOCOL']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PROTOCOL'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Server Address:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['SERVER_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_ADDR'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('Document Root:', 'debugger-troubleshooter') . '</strong> ' . esc_html(isset($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : 'N/A') . '</p>';
		echo '<p><strong>' . esc_html__('HTTPS:', 'debugger-troubleshooter') . '</strong> ' . (is_ssl() ? 'On' : 'Off') . '</p>';
		echo '</div></div>';

		// WordPress Constants Card
		echo '<div class="debug-troubleshooter-card collapsible">';
		echo '<div class="card-collapsible-header collapsed" role="button" tabindex="0" aria-expanded="false" aria-controls="site-info-constants"><h3>' . esc_html__('WordPress Constants', 'debugger-troubleshooter') . '</h3><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></div>';
		echo '<div id="site-info-constants" class="card-collapsible-content hidden">';
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
}
