<?php
/**
 * WP-CLI commands: `wp sentry-logger test`.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

/**
 * Registers the CLI command namespace when WP-CLI is running.
 */
final class Cli {
	/**
	 * Hook the command registration.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'sentry-logger', self::class );
	}

	/**
	 * wp sentry-logger test
	 *
	 * Sends one synthetic exception through the same pipeline live errors
	 * take: blocklist, sampling, transport. Prints the event id to paste
	 * into your tracker's UI search — that is the real end-to-end proof.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sentry-logger test
	 *
	 * @subcommand test
	 *
	 * @param array<string>        $args       Positional args (unused).
	 * @param array<string, mixed> $assoc_args Associative args (unused).
	 */
	public function test( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );

		$plugin = \wp_sentry_logger();

		if ( null === $plugin ) {
			\WP_CLI::error( 'The plugin is not active (missing vendor/ or not loaded).' );
		}

		if ( ! $plugin->config()->is_active() ) {
			\WP_CLI::error( 'No DSN configured — set it in Settings → WP Sentry Logger (or the WP_SENTRY_LOGGER_DSN constant).' );
		}

		$event_id = $plugin->send_test_event();

		if ( '' === $event_id ) {
			\WP_CLI::error( 'The SDK did not produce an event id — check the DSN and outgoing connectivity.' );
		}

		\WP_CLI::success( sprintf( 'Test event queued: %s (blocklist rules can drop it before transport — verify it appears in the backend UI).', $event_id ) );
	}
}
