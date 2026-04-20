=== WooCommerce Conflict Doctor ===
Contributors: kingsleyinfo
Tags: woocommerce, conflict, troubleshooting, debug
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automated plugin/theme conflict testing. Finds the culprit in minutes instead of hours.

== Description ==

WooCommerce Conflict Doctor automates the standard conflict-testing workflow:

1. Pick your symptom (checkout broken, admin broken, etc.)
2. Click Go
3. Answer "did that fix it?" at each step

The plugin runs a binary search across your active plugins — disabling half at a time — until it identifies the one causing your issue. Your live customers see nothing. Only you see the changes while testing.

**Safe by design:**

* Per-user testing mode — your customers never see changes
* All plugins are restored automatically after every test
* WooCommerce and security plugins are never disabled
* 1-hour session timeout with automatic cleanup

**Requirements:** An admin user with `manage_options` capability (store owner or administrator role).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/woocommerce-conflict-doctor/`
2. Activate it in **Plugins → Installed Plugins**
3. Navigate to **WooCommerce → Conflict Doctor**

== Frequently Asked Questions ==

= Will this affect my customers? =

No. The conflict test runs in a per-user mode — only the browser where you started
the test sees any changes. Your customers see the live site the entire time.

= What if the test session expires? =

Sessions last 1 hour. If yours expires, the plugin restores everything automatically.
You'll see a prompt to restart the test.

= My host is WP Engine / Kinsta / Pressable. Will this work? =

Managed WordPress hosts often block writes to `wp-content/mu-plugins/`, which this
plugin needs. If your host blocks it, you'll see a clear message explaining what to do.
Testing on a staging environment is the recommended workaround.

== Changelog ==

= 0.1.0 =
* Initial release (internal dogfood)
