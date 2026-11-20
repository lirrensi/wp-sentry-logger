=== Error Logger for Sentry ===
Contributors: lirrensi
Tags: sentry, error monitoring, php errors, javascript errors, logging
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 1.1.1
Requires PHP: 8.2
License: MIT
License URI: https://opensource.org/license/mit

Error monitoring for WordPress through the official Sentry PHP SDK. Works with any Sentry-compatible backend.

== Description ==

Error Logger for Sentry connects WordPress to a Sentry-compatible error tracker using the official `sentry/sentry` PHP SDK. It is intentionally small middleware: capture native PHP errors, drop noise locally, scrub private request data, and send the rest to the DSN you configure.

The plugin stays dormant until an administrator saves a DSN. It never contacts an external service on activation.

**Features**

* Server-side PHP error capture with severity presets: fatals only, fatals plus warnings (default), or everything including notices and deprecations.
* A case-insensitive regex blocklist matched against the error message, exception text, and stack-trace paths. Matching events are dropped before any network traffic.
* Raw PHP error-mask override for advanced setups.
* Privacy scrubbing: URL query strings, request bodies, cookies, authorization headers, and server environment variables are removed before send.
* WordPress context tags: WordPress version, theme, plugin version, environment, blog ID, and WooCommerce version when present. Logged-in users contribute only their numeric ID and role.
* Settings screen under Settings -> Error Logger for Sentry, plus `wp-config.php` constant overrides for every setting.
* WP-CLI command: `wp sentry-logger test` sends a synthetic event through the same pipeline as live errors.
* Optional browser (JavaScript) capture through the official Sentry Browser SDK, bundled locally and relayed through a same-origin tunnel. Off by default.
* Missing `vendor/` or an invalid DSN degrades to an admin notice instead of a white screen.

**Requirements**

* WordPress 6.4 or later.
* PHP 8.2 or later.
* A DSN from Sentry.io or any Sentry-compatible backend.

== Installation ==

1. Upload the plugin ZIP through Plugins -> Add New -> Upload Plugin, or install it from the WordPress.org directory.
2. Activate the plugin.
3. Open Settings -> Error Logger for Sentry and paste the DSN from your error tracker.
4. Optionally enable browser error capture on the same screen.
5. Click "Send test event" and confirm the event appears in the tracker.

For a manual install, the plugin directory must contain `vendor/` with the Sentry SDK. Release ZIPs from the plugin's GitHub repository include it. The plugin shows an admin notice if `vendor/` is missing.

== Configuration ==

The settings screen stores one option row (`wp_sentry_logger_settings`). Every value can be pinned in `wp-config.php`, which disables the matching field in the UI:

* `WP_SENTRY_LOGGER_DSN` - tracker DSN.
* `WP_SENTRY_LOGGER_LEVEL` - `fatals`, `default`, or `everything`.
* `WP_SENTRY_LOGGER_ERROR_TYPES` - raw PHP error mask overriding the preset.
* `WP_SENTRY_LOGGER_SAMPLE_RATE` - client-side sampling rate from 0 to 1.
* `WP_SENTRY_LOGGER_BLOCKLIST` - newline-separated regexes.
* `WP_SENTRY_LOGGER_SEND_DEFAULT_PII` - attach request bodies, cookies, and IP addresses. Off by default.
* `WP_SENTRY_LOGGER_BROWSER` - enable browser JavaScript capture. Off by default.
* `WP_SENTRY_LOGGER_ENVIRONMENT` - environment label.
* `WP_SENTRY_LOGGER_RELEASE` - release label stamped on events.

**Noise control**

Enter one case-insensitive regex per line in the blocklist. A line is matched anywhere in the error message, exception text, or stack-trace paths. Invalid regexes are reported when the settings are saved and are ignored at runtime. Lines starting with `#` are comments.

**Filters for developers**

* `wp_sentry_logger_blocklist_text` - filter the raw blocklist text.
* `wp_sentry_logger_blocklist_pattern` - filter or drop a single blocklist line.
* `wp_sentry_logger_error_types` - filter the resolved PHP error mask.
* `wp_sentry_logger_event` - final veto for every event; return `null` to drop it.
* `wp_sentry_logger_browser_init` - filter the Sentry Browser SDK init options.

== Other Notes ==

= External services =

This plugin is a client for a Sentry-compatible error tracking service. It does not contact any external service until an administrator saves a DSN, and it stops contacting the service when the DSN is cleared.

When the plugin is active, it sends the following data to the backend identified by that DSN:

* The PHP error type, message, stack trace, and the plugin/theme file paths involved.
* The request URL without its query string, the HTTP method, and request headers with cookies and authorization headers removed.
* WordPress version, theme, plugin version, environment label, blog ID, and WooCommerce version when present.
* For logged-in users, the numeric user ID and the first role only. Email address and display name are never attached.
* If browser capture is enabled, uncaught JavaScript errors and unhandled promise rejections from visitors' browsers, relayed through the site's own tunnel endpoint. This is off by default.
* If "Attach request bodies, IP address and cookies" is explicitly enabled, the request body, cookies, and IP address are included as well.

The service is operated by the provider behind your DSN, not by this plugin. If you use Sentry.io, the operator is Functional Software, Inc. (d/b/a Sentry): [Terms of Service](https://sentry.io/terms/) and [Privacy Policy](https://sentry.io/privacy/). If you use a self-hosted or third-party Sentry-compatible backend, that operator's terms and privacy policy apply.

This plugin is an independent client. It is not affiliated with, endorsed by, or sponsored by Functional Software, Inc.

== Privacy ==

By default the plugin does not send email addresses, display names, IP addresses, request bodies, cookies, or URL query strings. Severity filtering and the regex blocklist run on the WordPress server before any event leaves the site.

Site administrators choose whether to configure a DSN and whether to enable browser capture. The plugin does not phone home, does not create accounts, and does not collect usage analytics. Uninstalling it deletes the plugin's settings option.

== Frequently Asked Questions ==

= Do I need a Sentry.io account? =

No. You need a DSN from any Sentry-compatible backend, including a self-hosted one. Sentry.io is one supported provider.

= Does the plugin send anything before I configure it? =

No. With an empty DSN the plugin does not install error handlers, does not load front-end scripts, and makes no network requests.

= What happens if the DSN is invalid? =

The settings screen rejects an unparsable DSN and keeps the plugin dormant. A DSN using `http://` is upgraded to `https://` because the SDK does not follow HTTP-to-HTTPS redirects.

= Why are my browser errors not filtered by the regex blocklist? =

Browser envelopes are relayed verbatim to the backend. Use the `wp_sentry_logger_browser_init` filter to set the Browser SDK's `ignoreErrors`, `denyUrls`, `allowUrls`, or `sampleRate` options.

= Is browser capture enabled by default? =

No. It is opt-in because it adds a front-end script and a public tunnel endpoint. PHP error capture only needs a DSN.

= How do I test the connection? =

Use the "Send test event" button on the settings screen or run `wp sentry-logger test`. Both send a synthetic exception through the same filters and transport as live errors.

= Where is the source code? =

The development repository is [github.com/lirrensi/wp-sentry-logger](https://github.com/lirrensi/wp-sentry-logger). Build and release instructions are in the repository README.

== Changelog ==

= 1.1.1 =
* Initial WordPress.org release.
* PHP error capture through the official Sentry PHP SDK 4.x.
* Severity presets, regex blocklist, privacy scrubbing, and developer filters.
* Optional browser JavaScript capture through a bundled Browser SDK and same-origin tunnel.
* Settings screen, WP-CLI test command, and `wp-config.php` constant overrides.

= 1.1.0 =
* Internal foundation release.

== Upgrade Notice ==

= 1.1.1 =
Initial WordPress.org release.
