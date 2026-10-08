<?php
/**
 * Immutable runtime configuration for WP Sentry Logger.
 *
 * Resolution order for every value: PHP constant (wp-config.php) → saved
 * option → built-in default. Constants exist so a site owner can pin a
 * configuration in version control and stop admins from changing it in the UI.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

/**
 * Severity presets. The names double as values of the `level` setting.
 */
enum LevelPreset: string {
	/**
	 * Real crashes only: fatal, parse, core, compile, recoverable, user errors.
	 */
	case Fatals = 'fatals';

	/**
	 * Fatals plus warnings — the recommended default.
	 */
	case Default = 'default';

	/**
	 * Everything PHP can raise, including notices and deprecations.
	 * Combine with the blocklist or prepare for noise.
	 */
	case Everything = 'everything';

	/**
	 * Translate the preset into a PHP error_reporting-style mask.
	 */
	public function mask(): int {
		return match ( $this ) {
			self::Fatals => E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR,
			self::Default => self::Fatals->mask() | E_WARNING | E_USER_WARNING,
			self::Everything => E_ALL,
		};
	}
}

/**
 * Value object holding the fully resolved plugin configuration.
 */
final class Config {
	/**
	 * Sentry-compatible DSN. Empty string keeps the plugin dormant.
	 */
	public readonly string $dsn;

	/**
	 * PHP error mask the error handler reacts to.
	 */
	public readonly int $error_types;

	/**
	 * Client-side sampling rate (0..1). 1.0 sends every eligible event.
	 */
	public readonly float $sample_rate;

	/**
	 * Newline-separated regex blocklist; matching events are dropped
	 * before transport. Lines starting with `#` are comments.
	 */
	public readonly string $blocklist;

	/**
	 * Whether request data, user email/name and IP are attached to events.
	 * Deliberately defaults to false — e-commerce sites carry customer PII.
	 */
	public readonly bool $send_default_pii;

	/**
	 * Whether browser (JavaScript) errors are captured through the
	 * first-party tunnel. Optional; off by default so sites can skip it and
	 * host browser monitoring separately.
	 */
	public readonly bool $browser;

	/**
	 * Sentry environment label.
	 */
	public readonly string $environment;

	/**
	 * Sentry release label. Empty = SDK default (no release).
	 */
	public readonly string $release;

	/**
	 * @param array<string,mixed> $settings Saved option values.
	 */
	private function __construct( array $settings ) {
		$settings = $this->normalize( $settings );

		$this->dsn              = static::enforce_https_dsn( (string) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_DSN' ) ?? $settings['dsn'] ) );
		$this->environment      = (string) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_ENVIRONMENT' ) ?? $settings['environment'] );
		$this->release          = (string) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_RELEASE' ) ?? $settings['release'] );
		$this->error_types      = (int) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_ERROR_TYPES' ) ?? $this->level_mask( (string) $settings['level'], $settings['error_types'] ) );
		$this->sample_rate      = (float) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_SAMPLE_RATE' ) ?? $settings['sample_rate'] );
		$this->send_default_pii = (bool) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_SEND_DEFAULT_PII' ) ?? $settings['send_default_pii'] );
		$this->browser          = (bool) ( $this->constant_or_null( 'WP_SENTRY_LOGGER_BROWSER' ) ?? $settings['browser'] );

		$blocklist = $this->constant_or_null( 'WP_SENTRY_LOGGER_BLOCKLIST' ) ?? $settings['blocklist'];

		/**
		 * Filter the raw blocklist text (newline-separated regexes).
		 *
		 * @param string $blocklist Blocklist text from settings or constant.
		 */
		$this->blocklist = (string) apply_filters( 'wp_sentry_logger_blocklist_text', $blocklist );
	}

	/**
	 * Resolve configuration from constants, the saved option and defaults.
	 */
	public static function from_environment(): self {
		return new self( (array) get_option( WP_SENTRY_LOGGER_OPTION, [] ) );
	}

	/**
	 * Build a configuration from an explicit array (used by the settings
	 * sanitiser and by test harnesses).
	 *
	 * @param array<string,mixed> $settings Settings array (merged over defaults).
	 */
	public static function from_settings( array $settings ): self {
		return new self( $settings );
	}

	/**
	 * Built-in settings used on activation and as fallbacks.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'dsn'              => '',
			'level'            => LevelPreset::Default->value,
			'error_types'      => null,
			'sample_rate'      => 1.0,
			'blocklist'        => self::default_blocklist(),
			'send_default_pii' => false,
			'browser'          => false,
			'environment'      => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'development' : 'production',
			'release'          => '',
		];
	}

	/**
	 * Starter blocklist: the classic WordPress/crawler noise classes. Every
	 * line is editable in the settings UI — delete what you want to see.
	 *
	 * @return string
	 */
	public static function default_blocklist(): string {
		return <<<'BLOCKLIST'
		# One regex per line, matched case-insensitively against the error message,
		# exception text and stack trace paths. Lines starting with # are comments.
		# Crawler noise: requests without a User-Agent header.
		HTTP_USER_AGENT
		BLOCKLIST;
	}

	/**
	 * Is the plugin active enough to send anything?
	 */
	public function is_active(): bool {
		return '' !== $this->dsn && $this->sample_rate > 0.0;
	}

	/**
	 * Blocklist split into individual regex pattern strings (comments dropped,
	 * empty lines dropped, per-line filters still apply).
	 *
	 * @return list<string>
	 */
	public function blocklist_patterns(): array {
		$patterns = [];

		foreach ( preg_split( '/\R/', $this->blocklist ) ?: [] as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			/**
			 * Filter a single blocklist entry before it is compiled to a regex.
			 *
			 * @param string|null $pattern Null drops the line.
			 * @param string      $line    Raw line from the blocklist.
			 */
			$pattern = apply_filters( 'wp_sentry_logger_blocklist_pattern', $line, $line );

			if ( null !== $pattern && '' !== $pattern ) {
				$patterns[] = (string) $pattern;
			}
		}

		return $patterns;
	}

	/**
	 * A defined constant's value, or null when the constant does not exist.
	 * PHP 8 turns an undefined constant() lookup into an Error, so every
	 * optional override goes through this helper.
	 */
	private function constant_or_null( string $name ): mixed {
		return defined( $name ) ? \constant( $name ) : null;
	}

	/**
	 * Scheme of an arbitrary DSN string, null when unparsable.
	 */
	private static function dsn_scheme( string $dsn ): ?string {
		$parsed = parse_url( $dsn );

		return is_array( $parsed ) && isset( $parsed['scheme'] ) ? (string) $parsed['scheme'] : null;
	}

	/**
	 * Force an HTTPS DSN scheme. The Sentry PHP SDK does not follow HTTP→HTTPS
	 * redirects (curl without FOLLOWLOCATION), so a plain-http DSN behind an
	 * HTTPS terminator would silently lose every event while the transport
	 * still reports success. Many backends hand out http:// DSNs, hence this
	 * guard rather than a docs fix. Public: the settings save path reuses it
	 * to normalise the stored DSN.
	 */
	public static function enforce_https_dsn( string $dsn ): string {
		if ( '' === $dsn || self::dsn_scheme( $dsn ) !== 'http' ) {
			return $dsn;
		}

		$parsed = parse_url( $dsn );

		if ( ! is_array( $parsed ) || ! isset( $parsed['scheme'], $parsed['host'] ) ) {
			return $dsn;
		}

		return 'https' . substr( $dsn, (int) strpos( $dsn, 'http://', 0 ) + strlen( 'http' ) );
	}

	/**
	 * Merge saved settings over defaults and coerce types.
	 *
	 * @param array<string,mixed> $settings Raw saved settings.
	 * @return array<string,mixed>
	 */
	private function normalize( array $settings ): array {
		$defaults = self::defaults();
		$merged   = array_merge( $defaults, $settings );

		$level = is_string( $merged['level'] ) ? LevelPreset::tryFrom( $merged['level'] ) : null;
		$merged['level'] = $level?->value ?? LevelPreset::Default->value;

		$merged['error_types'] = is_numeric( $merged['error_types'] ) ? (int) $merged['error_types'] : null;

		$merged['sample_rate']      = max( 0.0, min( 1.0, (float) $merged['sample_rate'] ) );
		$merged['send_default_pii'] = (bool) $merged['send_default_pii'];
		$merged['browser']          = (bool) $merged['browser'];

		return $merged;
	}

	/**
	 * Error mask for the configured preset, or the explicit raw override.
	 *
	 * @param string   $level     Preset value.
	 * @param int|null $raw_types Raw error_types override, if set.
	 */
	private function level_mask( string $level, int|null $raw_types ): int {
		$mask = $raw_types ?? LevelPreset::from( $level )->mask();

		/**
		 * Filter the final error mask (advanced use).
		 *
		 * @param int    $mask   Resolved error mask.
		 * @param string $level  Preset value the mask came from.
		 */
		return (int) apply_filters( 'wp_sentry_logger_error_types', $mask, $level );
	}
}
