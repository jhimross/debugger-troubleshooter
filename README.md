# ⚙️ Debugger & Troubleshooter

A powerful, session-based WordPress plugin for safe debugging and comprehensive troubleshooting.

**Contributors:** jhimross
**Tags:** debug, troubleshoot, php info, developer, compatibility, conflict
**Requires at least:** 5.0
**Requires PHP:** 7.4
**Tested up to:** 7.1
**Stable tag:** 1.6.0
**License:** GPL-2.0+
**License URI:** http://www.gnu.org/licenses/gpl-2.0.txt
**Donate link:** https://paypal.me/jhimross28

---

## 🌟 Key Features

The "Debugger & Troubleshooter" plugin provides essential, non-disruptive tools for diagnosing and resolving issues on your WordPress site.

* **Troubleshooting Mode (Session-Based):**
    * Activate a unique mode **only visible to your current browser session**.
    * **Simulate Plugin Deactivation:** Selectively "deactivate" plugins. Their assets and code are disabled for you, while the live site remains unchanged for everyone else.
    * **Simulate Theme Switching:** Preview any installed theme safely, while the public-facing site continues to use the active theme.
* **User Role Simulator:** View your site as any other user or role (e.g., Subscriber, Editor) to test permissions and content visibility without knowing their password. Includes a safe "Exit" button in the Admin Bar.
* **Live Debugging:**
    * Start logging PHP errors, warnings, notices and WordPress deprecation notices with a single click—no editing `wp-config.php`.
    * Errors are written to the log only and never displayed on the public site.
* **Smart Log Viewer:**
    * Repeated errors are grouped with a count, filterable by level (Fatal, Warning, Notice, Deprecated, Database) and by source, and searchable.
    * Every entry shows which plugin or theme caused it, even when the error is thrown inside WordPress core (identified from the stack trace).
    * Expandable stack traces, auto-refresh, raw view, download and clear.
* **Performance Profiler:**
    * See why a page is slow: load time, memory, hook timeline, database query time per plugin, slow and duplicate queries, external HTTP requests and per-plugin load time.
    * Profiles only your own requests, switches itself off after 24 hours, and shows a live summary in the admin bar.
* **SMTP / Mail Debugger:** Send a test email and see the exact error if delivery fails.
* **Comprehensive Site Information:** Get an organized, collapsible overview of your entire WordPress environment:
    * Detailed PHP, Database, and Server information.
    * Full list of all themes and plugins with their status.
    * Important WordPress constants.
    * **Copy to Clipboard:** One-click button to copy all site info for easy sharing with support.
* **PHP Compatibility Checker:** Scan WordPress core, your active theme and active plugins for deprecated functions and syntax that may break when upgrading PHP. Results are displayed in a responsive card layout with clear warnings and incompatibility counts.
* **Conflict Checker:** Systematically identify plugin conflicts through a binary search process. Narrow down suspect plugins by answering whether each group resolves the issue, without disrupting live visitors.
* **Cache Bypassing:** Automatically attempts to bypass caching (by defining `DONOTCACHEPAGE`) when Troubleshooting Mode is active, ensuring your changes are reflected instantly.

---

## 🚀 Installation

### Standard Installation

1.  In your WordPress dashboard, navigate to **Plugins > Add New**.
2.  Click the **"Upload Plugin"** button.
3.  Choose the downloaded plugin ZIP file and click **"Install Now"**.
4.  **Activate** the plugin through the 'Plugins' menu.
5.  Access the features at **Tools > Debugger & Troubleshooter**.

### Manual Installation

1.  **Extract** the plugin ZIP file.
2.  **Upload** the `debugger-troubleshooter` folder to the `/wp-content/plugins/` directory via FTP or your hosting's file manager.
3.  **Activate** the plugin through the 'Plugins' menu in WordPress.

---

## 📖 Usage Guide

Navigate to **Tools > Debugger & Troubleshooter** in your WordPress dashboard to access all features.

### 1. Site Information

This section provides an organized overview of your environment in collapsible cards (closed by default). Click a card title (e.g., PHP Information, Database) to expand the details.

### 2. Troubleshooting Mode

Use this session-based section to safely **Simulate Theme Switching** and **Simulate Plugin Deactivation** without affecting your live website for other visitors.

### 3. User Role Simulator

Click the button to switch your view to that of a different user role (like "Subscriber" or "Editor"). This is perfect for testing content restrictions and permissions. A prominent "Exit Simulation" button will appear in your Admin Bar to safely return to your administrator account.

### 4. Live Debugging

Log errors without editing `wp-config.php`.

* **Enable Live Debug:** Starts logging PHP errors, warnings, notices and WordPress deprecation notices to `wp-content/debug.log` (or your custom `WP_DEBUG_LOG` path). Errors are never shown to visitors.
* **Smart Log Viewer:** Errors are grouped, colour-coded by level and attributed to the plugin or theme responsible. Click an entry for the full message and stack trace; filter by level or source, search, or switch to the Raw view. Turn on Auto-refresh to watch new errors arrive.
* **Download / Clear Log:** Save a copy of the full log, or clear it with a click.

### 5. SMTP / Mail Debugger

Send a test email to check your site's mail configuration. If sending fails, the exact error is shown.

### 6. Conflict Checker

If you are experiencing an issue on your site (admin or front-end), use the Conflict Checker to identify the culprit plugin. The plugin systematically deactivates groups of plugins using your session-based Troubleshooting Mode, asking you whether the issue persists after each step. Once the culprit is found, you can deactivate it with one click.

### 7. PHP Compatibility Checker

Check whether WordPress core, your active theme and your active plugins are ready for a newer PHP version. Select a target version (e.g., 8.0, 8.2, 8.4) and click "Run Compatibility Check" to get a report for each item, with clear warnings and incompatibility counts.

### 8. Performance Profiler

Open the **Performance** tab and click **Enable Profiler**. For the next 24 hours, every request you make (front end, admin, AJAX and REST) is profiled; visitors and other users are not affected. A summary (time, queries, memory) appears in the admin bar. Click it to open the full report with the timeline, query time per plugin, slowest and duplicate queries, external HTTP requests and plugin load times.

---

## ❓ Frequently Asked Questions

**Q: How does Troubleshooting Mode work without affecting my live site?**
A: Troubleshooting Mode uses a browser cookie specific to your session. The plugin uses WordPress filters to redirect core functions (which determine active plugins and themes) to your simulated settings. This process is isolated to your browser.

**Q: Will this work if I have a caching plugin active?**
A: Yes. When Troubleshooting Mode is active, the plugin defines the `DONOTCACHEPAGE` constant, which instructs most caching plugins and hosting environments to bypass the cache for your session.

**Q: How does Live Debugging work without editing `wp-config.php`?**
A: WordPress defines `WP_DEBUG` itself before any plugin loads, so no plugin can change that constant. Instead, Live Debug applies the equivalent PHP settings directly (full error reporting, logging to `wp-content/debug.log` or your custom `WP_DEBUG_LOG` path, errors never displayed) and also logs WordPress's own deprecation and "doing it wrong" notices. A small must-use plugin loads these settings before other plugins so their errors are captured too; it is removed automatically when Live Debug is turned off.

**Q: Does the Performance Profiler slow down my site?**
A: Only for you. Profiling runs solely on requests made by the administrator who enabled it, and switches itself off after 24 hours. Visitors and other users are never profiled.

**Q: How does the PHP Compatibility Checker work?**
A: The checker reads the "Requires PHP" header of WordPress core, your active theme and active plugins, then scans their PHP files for removed or deprecated functions (like `create_function()`, `each()`, `utf8_encode()`), incompatible syntax (curly brace offset access, `${var}` interpolation) and other patterns known to break in newer PHP versions. It reports which plugins are compatible, have warnings, or are incompatible with your selected target version.

**Q: How does the Conflict Checker differ from Troubleshooting Mode?**
A: Troubleshooting Mode manually toggles plugins and themes for testing. The Conflict Checker automates the process using a binary search algorithm — it splits plugins into groups and asks you whether the issue is present after each test, quickly narrowing down the exact culprit.

---

## 🖼️ Screenshots

1.  The main Debug & Troubleshooter dashboard showing Site Information.
![screenshot-1](https://github.com/user-attachments/assets/fb8beb25-06f9-4c5d-b19c-094520c95670)
  
2. The Troubleshooting Mode section with theme and plugin selection.
![screenshot-2](https://github.com/user-attachments/assets/f14e61ee-837b-46ff-ae1f-ffadbba2344b)

3.  An example of the admin notice when Troubleshooting Mode is active.
![screenshot-3](https://github.com/user-attachments/assets/c64e2e99-8b3b-4d5c-8ee0-3881b74361a1)

4.  The Live Debugging section with the log viewer.
<img width="1918" height="975" alt="screenshot-4" src="https://github.com/user-attachments/assets/18d6ddbc-d487-4c81-a6db-3ba34db3a0ed" />

5. The User Role Simulator.
<img width="1750" height="279" alt="image" src="https://github.com/user-attachments/assets/aa95b2ed-8987-4a74-aea5-8aa3c94c9262" />

6. Conflict Checker.
<img width="1917" height="916" alt="Conflict Checker" src="https://github.com/user-attachments/assets/d6a09be6-17f6-4b08-b490-1d0cb8eac800" />

7. The PHP Compatibility Checker.
<img width="1902" height="915" alt="PHP Compatibility Checker" src="https://github.com/user-attachments/assets/befdfafd-a43e-42cd-a260-6c13b0b893a2" />



---

## Changelog

### 1.6.0 - 2026-11-10
* **Fix (Important):** Live Debug now actually logs errors. It previously tried to define `WP_DEBUG` after WordPress had already defined it, so it had no effect on most sites.
* **Feature:** Live Debug also logs WordPress deprecation and `_doing_it_wrong()` notices with the responsible file and line.
* **Feature:** Smart Log Viewer with grouping, level/source filters, search, plugin attribution (including via stack traces), auto-refresh and download.
* **Feature:** Performance Profiler with hook timeline, query time by plugin, slow/duplicate queries, external HTTP requests, plugin load times and an admin bar summary.
* **Fix:** The log viewer uses the log file actually in use (custom `WP_DEBUG_LOG` path or PHP `error_log`) and reads only the last 2 MB.
* **Enhancement:** Tabs can be opened directly via URL hash (e.g. `#performance`).

### 1.5.1 - 2026-10-20
* **Security Fix (Critical):** Fixed a privilege escalation in the User Role Simulator. A simulated low-privilege user could start another simulation for any account, including an administrator. Starting a simulation now requires `manage_options`, the target user is validated, and tokens are bound to the administrator who created them.
* **Security Fix (High):** Simulation and troubleshooting tokens now expire after 24 hours, are bound to the creating user, and are cleaned up on uninstall. The "Exit Simulation" action now uses a dedicated nonce.
* **Security Fix (Medium):** The simulated theme is now validated against installed themes before Troubleshooting Mode applies it.
* **Compatibility:** Tested up to WordPress 7.1.
* **Fix:** Updated the asset cache-busting version and removed the unused `Domain Path` header.
* **Fix:** Live Debug now warns when `WP_DEBUG` is already defined in `wp-config.php` and cannot be overridden.
* **Fix:** Hardened filesystem handling when installing the troubleshooting MU plugin and clearing the debug log.
* **Fix:** Added translator comments and ordered placeholders; replaced direct filesystem calls with WordPress APIs.

### 1.5.0 - 2026-06-04
* **Feature:** Added Plugin Compatibility Checker to scan and report PHP version compatibility across all installed plugins.
* **Feature:** Added Conflict Checker (replaces Plugin Detective) to systematically identify plugin conflicts through binary search.
* **Fix:** Added HTML escaping to all dynamically injected compatibility result content.
* **Fix:** Removed unused `$roles` variable from User Simulation section.

### 1.4.1 - 2026-05-20
* **Enhancement:** Tested up to WordPress 7.0.
* **Feature:** Added "SMTP / Mail Debugger" to test and verify site mail configuration.
* **Feature:** Captures detailed `WP_Error` data and PHP environment diagnostics upon mail delivery failure.
* **Enhancement:** Improved AJAX feedback loops and visual status indicators in the dashboard.
* **Fix:** Resolved minor UI layout issues in the Site Information cards.

### 1.4.0 - 2026-03-19
* **Security Fix (Critical):** Resolved a privilege escalation vulnerability where unauthenticated users could bypass authentication via the User Simulation feature.
* **Security Fix (High):** Patched an authorization bypass in the Troubleshooting Mode configuration. State is now securely validated against database records using cryptographic tokens.
* **Security Fix (Medium):** Added strict nonces to the "Exit Simulation" admin bar action to prevent Cross-Site Request Forgery (CSRF). 
* **Fix:** Completely overhauled the "Troubleshooting Mode" plugin logic. The plugin now correctly intercepts early plugin loading via an automatically dropping MU (Must-Use) plugin template, resolving the issue where plugins were not adequately disabled during simulations.
* **Fix:** Disconnected WP_Filesystem API usage from AJAX context actions down to native PHP handlers to prevent silent AJAX failures on servers requiring FTP credentials.
* **Fix:** Adjusted the "Exit Simulation" JavaScript rendering hook to ensure the "Exit Simulation" admin bar button functions properly on the front-end.

### 1.3.2 - 2026-02-11
* **Fix:** Resolved a UI conflict where the confirmation modal appeared automatically upon page load due to CSS class interference from other plugins.
* **Fix:** Improved modal layering (z-index) to ensure the Success/Error alert modal correctly appears above the confirmation modal.
* **Fix:** Updated the "Confirm" action in the Debug Log viewer to automatically close the confirmation dialog before showing the result, preventing the UI from becoming unclickable.
* **Enhancement:** Hardened the .hidden CSS utility with !important to prevent external themes or plugins from forcing hidden elements to display.

###  1.3.1 - 2025-11-21
* **Fix:** Resolved a critical issue where admin scripts were not loading due to a hook name mismatch.
* **Fix:** Addressed WordPress coding standard issues (deprecated functions, security hardening).

### 1.3.0 - 2025-11-21
* **Feature:** Added "User Role Simulator" to view the site as any user or role for the current session.
* **Enhancement:** Added an Admin Bar "Exit Simulation" button for safe return to the administrator account.
* **Fix:** Improved layout stability for the troubleshooting dashboard.

### 1.2.1 - 2025-07-11
* **Fix:** Addressed all security and code standard issues reported by the Plugin Check plugin, including escaping all output and using the `WP_Filesystem` API for file operations.
* **Fix:** Replaced the native browser `confirm()` dialog with a custom modal for a better user experience and to prevent potential browser compatibility issues.

### 1.2.0 - 2025-07-11
* **Feature:** Added "Live Debugging" section to safely enable/disable `WP_DEBUG` and `WP_DEBUG_LOG` from the UI without editing `wp-config.php`.
* **Feature:** Added a `debug.log` file viewer and a "Clear Log" button to the Live Debugging section.

### 1.1.1 - 2025-07-10
* **Fix:** Implemented cache-bypassing measures for Troubleshooting Mode. The plugin now defines the `DONOTCACHEPAGE` constant and sends no-cache headers to ensure compatibility with most caching plugins and server-side caches.

### 1.1.0 - 2025-07-09
* **Feature:** Site Information cards (WordPress, PHP, Database, Server, Constants) are now collapsible and closed by default for a cleaner interface.
* **Feature:** Added a "Copy to Clipboard" button to easily copy all site information for support requests or documentation.
* **Enhancement:** The "WordPress Information" card now displays a detailed list of all installed themes and plugins, along with their respective active, inactive, or network-active status.
* **Enhancement:** The theme and plugin lists within the "WordPress Information" card are now compact, showing counts by default with a "Show All" toggle to view the complete list.
* **Enhancement:** Expanded the displayed information for PHP, Server, and WordPress constants.
* **Fix:** Resolved a bug that prevented the collapsible sections from functioning correctly.

### 1.0.0 – 2025-06-25
* Initial release.
