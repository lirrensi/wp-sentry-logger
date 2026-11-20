# Bundled Sentry Browser SDK

`sentry-browser.js` and `sentry-browser.min.js` are the official
`@sentry/browser` 11.5.0 UMD bundles, MIT licensed, © Sentry.

- Unminified source: https://browser.sentry-cdn.com/11.5.0/bundle.js
- Production bundle: https://browser.sentry-cdn.com/11.5.0/bundle.min.js
- Upstream project: https://github.com/getsentry/sentry-javascript

The unminified file is included so the WordPress.org plugin directory review
can inspect human-readable source for the minified bundle. The plugin loads the
unminified file automatically when `SCRIPT_DEBUG` is enabled.

SHA-256:

```
sentry-browser.js     8b50af457e6e81c055b2b2e8ccafb2c5621b04f2b80586b361df5f6aee3b54e7
sentry-browser.min.js d9c7360293424794bf1efd8372439d101e2e38dcc5b787af2c5fb68f20ca0821
```
