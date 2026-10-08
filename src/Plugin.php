<?php
/**
 * WP glue: resolves the configuration, boots the Sentry SDK at the right
 * moment of the request lifecycle and enriches events with WordPress context.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

use Sentry\SentrySdk;
use function add_action;
use function add_filter;
use function class_exists;
use function get_bloginfo;
use function get_current_blog_id;
use function get_stylesheet;
use function is_multisite;
use function is_user_logged_in;
use function plugin_basename;
use function sprintf;
use function wp_get_environment_type;
use function wp_get_current_user;

/**
 * Core plugin service.
 */
final class Plugin {
	/**
	 * Resolved, immutable configuration.
	 */
	private readonly Config $config;

	/**
	 * Event filter wired as the SDK before_send callback.
	 */
	private readonly EventFilter $event_filter;

	/**
	 * Whether init_sdk() has already installed the SDK.
	 */
	private bool $initialized = false;

	public function __construct( Config $config ) {
		$this->config       = $config;
		$this->event_filter = new EventFilter( $config->blocklist_patterns() );
	}

	/**
	 * Access the resolved configuration (for CLI, tests and integrations).
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Hook the plugin into WordPress.
	 */
	public function register(): void {
		add_action( 'plugins_loaded', [ $this, 'init_sdk' ], 6 );
		add_action( 'plugins_loaded', [ $this, 'enrich_scope' ], 7 );

		if ( is_admin() ) {
			$settings = new SettingsPage( $this );
			$settings->register();
		}

		add_filter(
			'plugin_action_links_' . plugin_basename( WP_SENTRY_LOGGER_PLUGIN_FILE ),
			static function ( array $links ): array {
				array_unshift(
					$links,
					sprintf(
						'<a href="%s">%s</a>',
						esc_url( admin_url( 'options-general.php?page=wp-sentry-logger' ) ),
						esc_html__( 'Settings', 'wp-sentry-logger' )
					)
				);

				return $links;
			}
		);

		if ( class_exists( \WP_CLI::class ) ) {
			Cli::register();
		}
	}

	/**
	 * Attach WordPress context to every outgoing event.
	 *
	 * Runs right after the SDK is bound (still on `plugins_loaded`); the hub
	 * scope is client-agnostic, so the context lands on all events sent later
	 * in the request — including errors raised while the rest of the site is
	 * still loading.
	 */
	public function enrich_scope(): void {
		$hub = SentrySdk::getCurrentHub();

		$hub->configureScope( static function ( \Sentry\State\Scope $scope ): void {
			global $wp_version;

			$scope->setTag( 'wordpress', (string) $wp_version );
			$scope->setTag( 'plugin', WP_SENTRY_LOGGER_VERSION );
			$scope->setTag( 'theme', (string) get_stylesheet() );
			$scope->setTag( 'multisite', is_multisite() ? 'yes' : 'no' );
			$scope->setTag( 'wp_env', (string) wp_get_environment_type() );
			$scope->setTag( 'blog', (string) get_current_blog_id() );

			if ( class_exists( 'WooCommerce' ) && defined( 'WC_VERSION' ) ) {
				$scope->setTag( 'woocommerce', (string) WC_VERSION );
			}

			// Identity: id and role only. E-mail/display name are PII and are
			// never attached, regardless of the send_default_pii setting.
			if ( is_user_logged_in() ) {
				$user = wp_get_current_user();

				$scope->setUser( [
					'id' => (string) $user->ID,
				] );
				$scope->setTag( 'user_role', $user->roles[0] ?? 'none' );
			}
		} );
	}

	/**
	 * Install the Sentry error handlers.
	 *
	 * Runs immediately after boot (`plugins_loaded`, priority 6) so errors
	 * raised while the rest of the site loads — plugin boot, theme setup,
	 * `init` — are captured too, not only those after `wp_loaded`. The Sentry
	 * handler still forwards to whatever error handler was registered before
	 * it, so existing logging (e.g. a theme's own set_error_handler) keeps
	 * working.
	 */
	public function init_sdk(): void {
		if ( $this->initialized || ! $this->config->is_active() ) {
			return;
		}

		$this->initialized = true;

		\Sentry\SentrySdk::getCurrentHub()->bindClient(
			\Sentry\ClientBuilder::create( [
				'dsn'                  => $this->config->dsn,
				'error_types'          => $this->config->error_types,
				'sample_rate'          => $this->config->sample_rate,
				'send_default_pii'     => $this->config->send_default_pii,
				'environment'          => $this->config->environment,
				'release'              => $this->release(),
				'http_connect_timeout' => 2.0,
				'http_timeout'         => 3.0,
				'send_attempts'        => 1,
				'before_send'          => $this->event_filter,
			] )->getClient()
		);
	}

	/**
	 * Release label sent with every event (unset fields are blank).
	 */
	private function release(): string {
		if ( '' !== $this->config->release ) {
			return $this->config->release;
		}

		return sprintf(
			'wp%1$s+logger%2$s',
			(string) get_bloginfo( 'version' ),
			WP_SENTRY_LOGGER_VERSION
		);
	}

	/**
	 * Send one synthetic event (settings test button / WP-CLI).
	 *
	 * @return string The Sentry event id, or '' when the client refused.
	 */
	public function send_test_event(): string {
		$this->init_sdk();

		$event_id = SentrySdk::getCurrentHub()->captureException(
			new \RuntimeException( 'WP Sentry Logger test event' )
		);

		return ( $event_id !== null ) ? (string) $event_id : '';
	}

	/**
	 * Whether the SDK error handlers are installed (true after init_sdk).
	 */
	public function is_initialized(): bool {
		return $this->initialized;
	}
}
