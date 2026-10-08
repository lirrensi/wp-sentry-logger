# WP Sentry Logger

Error monitoring for WordPress via the **official Sentry PHP SDK** (`sentry/sentry` 4.x).
Works with any Sentry-compatible backend. Protocol and transport are upstream;
this plugin is only WordPress glue, noise management and privacy.

## Design principle

Dumb middleware, nothing else: capture native errors — PHP through the SDK's
error-handler chain and, optionally, browser JavaScript through a first-party
tunnel — filter them (severity preset, regex blocklist, privacy scrub), push
them over the Sentry protocol. No per-plugin integrations and no
service-specific code — site-specific noise belongs in the site's blocklist,
not in this plugin.

## Features

- Server-side PHP error capture, severity presets: `fatals` / `default` (fatal+warning) / `everything` (fatal+warning+notice+deprecated), or a raw PHP error mask.
- **Regex blocklist**: one case-insensitive regex per line, matched against error message, exception text, and stack-trace file paths. Matching events are dropped client-side, before any network traffic. Ships with a pre-seeded WordPress/crawler noise preset; every line is editable in the UI.
- Developer filters: `wp_sentry_logger_blocklist_text`, `wp_sentry_logger_blocklist_pattern`, `wp_sentry_logger_error_types`, `wp_sentry_logger_event` (final veto; return null to drop).
- DSN scheme engineering guard: DSN is forced onto `https://` even when the backend hands out a plain-`http://` DSN (the SDK won't follow HTTP→HTTPS redirects and would silently eat every event otherwise).
- URL querystrings, request bodies, cookies, auth headers, and server environment stripped before send (`send_default_pii` off by default).
- WordPress context tags: WP version, plugin version, theme, environment, blog id, WooCommerce version when present; user id and role only (never e-mail / display name / IP).
- Settings UI: Settings -> WP Sentry Logger (Settings API, one option row, capability `manage_options`).
- WP-CLI: `wp sentry-logger test`.
- Optional **browser (JavaScript) capture**: bundles the official Sentry Browser SDK (no third-party requests) and relays page errors through a first-party tunnel endpoint on this site — no CORS, no ad-blocker blocking. Off by default; skip it if you host browser monitoring separately.
- Missing `vendor/` degrades to an admin notice — not a white screen.

## Requirements

- WordPress 6.4+, PHP 8.2+
- `composer install` inside the plugin directory produces `vendor/` (or build a distributable zip with `bin/build.sh`).

## Configuration

Set the DSN in the settings UI **or** pin it in `wp-config.php`:

```php
define( 'WP_SENTRY_LOGGER_DSN', 'https://<key>@errors.example.com/<project-id>' );
```

All settings also support a wp-config.php constant override:

| Constant | Meaning | Default |
|---|---|---|
| `WP_SENTRY_LOGGER_DSN` | tracker DSN | one per site, from the DSN input |
| `WP_SENTRY_LOGGER_LEVEL` | `fatals` \| `default` \| `everything` | `default` |
| `WP_SENTRY_LOGGER_ERROR_TYPES` | raw PHP error mask overriding the level | null |
| `WP_SENTRY_LOGGER_SAMPLE_RATE` | 0..1 | 1.0 |
| `WP_SENTRY_LOGGER_BLOCKLIST` | newline-separated regexes | preset WordPress noise list |
| `WP_SENTRY_LOGGER_SEND_DEFAULT_PII` | bool (user email/name never sent either way) | false |
| `WP_SENTRY_LOGGER_BROWSER` | capture browser JavaScript errors via a first-party tunnel | false |
| `WP_SENTRY_LOGGER_ENVIRONMENT` | environment label | `production` (or `development` with WP_DEBUG) |
| `WP_SENTRY_LOGGER_RELEASE` | release label stamped on events | `wp<WP version>+logger<plugin version>` |

### Tuning in either direction

- **Too much noise?** Add one case-insensitive regex per offending pattern to the blocklist. It is matched anywhere in the error message, exception text, or stack-trace paths — e.g. `SomePluginName` or `wp-content/plugins/foo/`. Invalid lines are flagged when you save.
- **Missing errors you care about?** Widen the severity: pick *Everything*, or set a raw numeric mask (the field placeholder shows the number for "everything except notices and deprecations"). Capture starts the moment the plugin boots, so load-time fatals are included.
- **Browser events** are relayed verbatim (size-capped and rate-limited) and skip the regex blocklist — filter them browser-side via the `wp_sentry_logger_browser_init` filter if needed.

## Dev filters quick reference

```php
add_filter( 'wp_sentry_logger_blocklist_pattern', fn( $line ) => str_starts_with( $line, 'HTTP_USER_AGENT' ) ? null : $line );
add_filter( 'wp_sentry_logger_error_types', fn( $mask ) => $mask | E_USER_WARNING );
add_filter( 'wp_sentry_logger_event', fn( $event, $hint ) => $event ); // return null to drop
add_filter( 'wp_sentry_logger_browser_init', fn( $options ) => array_merge( $options, [ 'ignoreErrors' => [ '/SomePluginName/' ] ] ) );
```

## Uninstall

Removes one option (`wp_sentry_logger_settings`) and nothing else.

## Credits

This plugin forks [CodingIT-dev/wp-sentry](https://github.com/CodingIT-dev/wp-sentry) and carries it forward: a full modernization for PHP 8.2+, WordPress 6.4+ and the official Sentry PHP SDK 4.x.

That fork is itself based on [stayallive/wp-sentry](https://github.com/stayallive/wp-sentry) — the original unofficial WordPress → Sentry plugin by Alex Bouma (MIT). Thanks to both projects for the work this stands on.

Browser coverage bundles the official [Sentry Browser SDK](https://github.com/getsentry/sentry-javascript) (`assets/sentry-browser.min.js`, MIT, © Sentry).

## License

MIT — see [LICENSE](LICENSE).
