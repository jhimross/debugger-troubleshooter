# ⚙️ Debugger & Troubleshooter

A powerful, session-based WordPress plugin for safe debugging and comprehensive troubleshooting.

**Contributors:** jhimross
**Tags:** debug, troubleshoot, php info, developer
**Requires at least:** 5.0
**Requires PHP:** 7.4
**Tested up to:** 7.0
**Stable tag:** 1.4.1
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
    * Safely enable `WP_DEBUG` and `WP_DEBUG_LOG` from the admin dashboard with a single click—no editing `wp-config.php`.
    * Errors are logged to `debug.log` without being displayed on the public site (`WP_DEBUG_DISPLAY` is kept off).
    * View and clear the `debug.log` file directly in the plugin's interface.
* **Comprehensive Site Information:** Get an organized, collapsible overview of your entire WordPress environment:
    * Detailed PHP, Database, and Server information.
    * Full list of all themes and plugins with their status.
    * Important WordPress constants.
    * **Copy to Clipboard:** One-click button to copy all site info for easy sharing with support.
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
2.  **Upload** the `debug-troubleshooter` folder to the `/wp-content/plugins/` directory via FTP or your hosting's file manager.
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

Safely manage WordPress's debugging constants from the UI.

* **Enable Live Debug:** Programmatically enables `WP_DEBUG` and `WP_DEBUG_LOG`, logging errors to `wp-content/debug.log` without displaying them on the site.
* **Debug Log Viewer:** A text area displays the contents of your `debug.log` file, allowing real-time error viewing.
* **Clear Log:** Safely clear the `debug.log` file with a single click.

---

## ❓ Frequently Asked Questions

**Q: How does Troubleshooting Mode work without affecting my live site?**
A: Troubleshooting Mode uses a browser cookie specific to your session. The plugin uses WordPress filters to redirect core functions (which determine active plugins and themes) to your simulated settings. This process is isolated to your browser.

**Q: Will this work if I have a caching plugin active?**
A: Yes. When Troubleshooting Mode is active, the plugin defines the `DONOTCACHEPAGE` constant, which instructs most caching plugins and hosting environments to bypass the cache for your session.

**Q: How does Live Debugging work without editing `wp-config.php`?**
A: The plugin leverages the `plugins_loaded` hook to define the necessary `WP_DEBUG` constants programmatically very early in the WordPress loading sequence, effectively enabling debug mode for all requests while the feature is turned on.

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


---

## Changelog

### 1.4.1 - 2026-05-11
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
