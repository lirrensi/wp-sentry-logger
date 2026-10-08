<?php
/**
 * Uninstall cleanup: drop the plugin's own option. Nothing else is stored.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_sentry_logger_settings' );
