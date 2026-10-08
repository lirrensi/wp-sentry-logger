<?php
/**
 * Plugin Name:       WP Sentry Logger
 * Description:       Error monitoring for WordPress via the official Sentry PHP SDK — works with any Sentry-compatible backend. Severity presets, regex noise blocklist, developer filters, and optional browser (JavaScript) capture via a same-origin tunnel.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Kiber Integration
 * License:           MIT
 * License URI:       https://opensource.org/license/mit
 * Text Domain:       wp-sentry-logger
 *
 * Forked from https://github.com/CodingIT-dev/wp-sentry, itself based on
 * https://github.com/stayallive/wp-sentry, and modernized for PHP 8.2+,
 * WordPress 6.4+ and the official Sentry PHP SDK 4.x. Credit to both
 * original projects.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger {

	defined( 'ABSPATH' ) || exit;

	const WP_SENTRY_LOGGER_VERSION     = '1.0.0';
	const WP_SENTRY_LOGGER_OPTION      = 'wp_sentry_logger_settings';
	const WP_SENTRY_LOGGER_MIN_PHP     = '8.2';
	const WP_SENTRY_LOGGER_PLUGIN_FILE = __FILE__;

	/**
	 * Activation: seed settings with sane defaults (no DSN yet — the plugin
	 * stays dormant until one is configured, so activating can never
	 * error-spam a third-party service from day one).
	 */
	register_activation_hook(
		WP_SENTRY_LOGGER_PLUGIN_FILE,
		static function (): void {
			if ( ! get_option( WP_SENTRY_LOGGER_OPTION ) ) {
				add_option( WP_SENTRY_LOGGER_OPTION, Config::defaults() );
			}
		}
	);

	/**
	 * Boot the plugin once all plugins are registered.
	 *
	 * The Sentry error handlers are installed right after boot (see
	 * Plugin::init_sdk) so errors raised while the rest of the site is still
	 * loading are captured too. The Sentry handler forwards to whatever error
	 * handler was registered before it: themes or plugins that install their
	 * own set_error_handler() keep working unchanged.
	 *
	 * A missing vendor/ directory (plugin copied from source control without
	 * a build step) degrades to an admin notice — never to a white screen.
	 */
	add_action(
		'plugins_loaded',
		static function (): void {
			global $wp_sentry_logger;

			if ( isset( $wp_sentry_logger ) ) {
				return; // Already booted by an earlier call (defensive).
			}

			if ( ! class_exists( \Sentry\SentrySdk::class ) ) {
				// Distribution builds ship a composer-generated
				// vendor/ directory; a source checkout that skipped
				// `composer install` lands here. Handle that gracefully.
				$vendor_autoload = dirname( WP_SENTRY_LOGGER_PLUGIN_FILE ) . '/vendor/autoload.php';

				if ( file_exists( $vendor_autoload ) ) {
					require_once $vendor_autoload;
				}
			}

			if ( ! class_exists( \Sentry\SentrySdk::class ) ) {
				add_action(
					'admin_notices',
					static function (): void {
						if ( ! current_user_can( 'activate_plugins' ) ) {
							return;
						}
						printf(
							'<div class="notice notice-error"><p>%s</p></div>',
							esc_html__( 'WP Sentry Logger: the vendor/ directory is missing — run "composer install" inside the plugin directory and reload.', 'wp-sentry-logger' )
						);
					}
				);

				return;
			}

			$wp_sentry_logger = new Plugin( Config::from_environment() );
			$wp_sentry_logger->register();
		},
		5
	);
}

namespace {
	function wp_sentry_logger(): ?\WPSentryLogger\Plugin {
		global $wp_sentry_logger;

		return $wp_sentry_logger ?? null;
	}
}
