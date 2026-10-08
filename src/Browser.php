<?php
/**
 * Optional browser (JavaScript) coverage: injects the official Sentry
 * Browser SDK (bundled locally, no third-party requests) and relays its
 * envelopes through a first-party tunnel endpoint, so page errors reach the
 * tracker without CORS or ad-blocker fights.
 *
 * Inactive unless the `browser` setting — or the WP_SENTRY_LOGGER_BROWSER
 * constant — enables it; sites that host browser monitoring separately can
 * simply leave it off.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

use Sentry\Dsn;
use function add_action;
use function apply_filters;
use function get_transient;
use function is_wp_error;
use function plugins_url;
use function register_rest_route;
use function rest_url;
use function set_transient;
use function sprintf;
use function wp_add_inline_script;
use function wp_enqueue_script;
use function wp_json_encode;
use function wp_parse_url;
use function wp_remote_post;
use function wp_remote_retrieve_response_code;

/**
 * Browser SDK injection and the tunnel relay endpoint.
 */
final class Browser {
	/**
	 * REST namespace of the tunnel endpoint.
	 */
	public const REST_NAMESPACE = 'wp-sentry-logger/v1';

	/**
	 * REST route of the tunnel endpoint.
	 */
	public const REST_ROUTE = '/tunnel';

	/**
	 * Script handle of the injected browser bundle.
	 */
	private const SCRIPT_HANDLE = 'wp-sentry-logger-browser';

	/**
	 * Hard cap on a single relayed envelope, bytes. Real browser envelopes
	 * are far smaller; this only exists to keep abusers out.
	 */
	private const MAX_BODY_BYTES = 1048576;

	/**
	 * Tunnel requests allowed per IP per window.
	 */
	private const RATE_LIMIT_MAX = 60;

	/**
	 * Rate limit window, seconds.
	 */
	private const RATE_LIMIT_WINDOW = 60;

	private readonly Config $config;

	/**
	 * Release label shared with the PHP client, stamped on browser events.
	 */
	private readonly string $release;

	/**
	 * Parsed DSN, or null when the DSN is empty/invalid (plugin dormant).
	 */
	private readonly ?Dsn $dsn;

	public function __construct( Config $config, string $release ) {
		$this->config  = $config;
		$this->release = $release;

		try {
			$this->dsn = Dsn::createFromString( $config->dsn );
		} catch ( \Throwable $e ) {
			$this->dsn = null;
		}
	}

	/**
	 * Hook the SDK injection and the tunnel endpoint. No-op while the plugin
	 * is dormant (no DSN).
	 */
	public function register(): void {
		if ( ! $this->config->is_active() || null === $this->dsn ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	/**
	 * Load the bundled SDK on the front end and configure it. The init call
	 * runs synchronously right after the bundle, so uncaught errors from all
	 * later scripts are captured.
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/sentry-browser.min.js', WP_SENTRY_LOGGER_PLUGIN_FILE ),
			[],
			WP_SENTRY_LOGGER_VERSION,
			false
		);

		$options = [
			'dsn'         => $this->config->dsn,
			'tunnel'      => $this->tunnel_path(),
			'environment' => $this->config->environment,
			'release'     => $this->release,
		];

		/**
		 * Filter the browser SDK init options (e.g. ignoreErrors for
		 * browser-side noise filtering).
		 *
		 * @param array<string,mixed> $options Sentry.init options.
		 */
		$options = (array) apply_filters( 'wp_sentry_logger_browser_init', $options );

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf( 'window.Sentry && Sentry.init( %s );', wp_json_encode( $options ) ),
			'after'
		);
	}

	/**
	 * Register the tunnel endpoint (public — visitors' browsers post here).
	 */
	public function register_route(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Relay a browser envelope to the tracker. Accepts only envelopes aimed
	 * at this site's own DSN; everything else is rejected.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (string) $request->get_body();

		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_REST_Response( [ 'error' => 'payload not accepted' ], 413 );
		}

		if ( $this->is_rate_limited() ) {
			return new \WP_REST_Response( [ 'error' => 'rate limited' ], 429 );
		}

		$newline = strpos( $body, "\n" );
		$header  = json_decode( false !== $newline ? substr( $body, 0, $newline ) : $body, true );

		if ( ! is_array( $header ) || ! isset( $header['dsn'] ) || ! is_string( $header['dsn'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'not an envelope' ], 400 );
		}

		if ( ! $this->is_own_dsn( $header['dsn'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'unknown dsn' ], 403 );
		}

		$response = wp_remote_post(
			$this->dsn->getEnvelopeApiEndpointUrl(),
			[
				'headers' => [ 'Content-Type' => 'application/x-sentry-envelope' ],
				'body'    => $body,
				'timeout' => 3.0,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_REST_Response( [ 'error' => 'relay failed' ], 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return new \WP_REST_Response(
			[ 'id' => $header['event_id'] ?? null ],
			$status >= 200 && $status < 300 ? 200 : 502
		);
	}

	/**
	 * Does this envelope's DSN point at the same project as the configured
	 * one? Prevents the endpoint from being used as an open relay.
	 */
	private function is_own_dsn( string $dsn ): bool {
		try {
			$incoming = Dsn::createFromString( $dsn );
		} catch ( \Throwable $e ) {
			return false;
		}

		return strcasecmp( $incoming->getScheme(), $this->dsn->getScheme() ) === 0
			&& strcasecmp( $incoming->getHost(), $this->dsn->getHost() ) === 0
			&& $incoming->getPort() === $this->dsn->getPort()
			&& $incoming->getProjectId() === $this->dsn->getProjectId()
			&& $incoming->getPath() === $this->dsn->getPath();
	}

	/**
	 * Relative tunnel path — works for plain and pretty permalinks alike.
	 */
	private function tunnel_path(): string {
		$bits = wp_parse_url( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) );

		if ( ! is_array( $bits ) ) {
			return '';
		}

		return ( $bits['path'] ?? '/' ) . ( isset( $bits['query'] ) ? '?' . $bits['query'] : '' );
	}

	/**
	 * Transient-based per-IP limiter; keeps spam out of the tracker.
	 */
	private function is_rate_limited(): bool {
		$key  = 'wp_sentry_logger_tunnel_' . substr( hash( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 32 );
		$hits = (int) get_transient( $key );

		if ( $hits >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $hits + 1, self::RATE_LIMIT_WINDOW );

		return false;
	}
}
