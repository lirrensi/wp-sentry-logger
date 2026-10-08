<?php
/**
 * Settings screen (Settings API): DSN, severity level, blocklist and privacy.
 *
 * Everything also has a wp-config.php constant override — see Config. A
 * constant set in wp-config.php wins and the matching UI field is disabled.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

use Sentry\Dsn;
use function add_options_page;
use function add_settings_error;
use function add_settings_section;
use function checked;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_textarea;
use function esc_url;
use function get_option;
use function register_setting;
use function sanitize_textarea_field;
use function sprintf;
use function submit_button;
use function wp_nonce_url;

/**
 * Renders and persists the plugin settings.
 */
final class SettingsPage {
	/**
	 * Page slug used in the URL and the settings group name.
	 */
	public const PAGE_SLUG = 'wp-sentry-logger';

	private Plugin $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the page, its fields and the test-event action.
	 */
	public function register(): void {
		add_options_page(
			__( 'WP Sentry Logger', 'wp-sentry-logger' ),
			__( 'WP Sentry Logger', 'wp-sentry-logger' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);

		register_setting(
			'wp_sentry_logger',
			WP_SENTRY_LOGGER_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => Config::defaults(),
			]
		);

		add_settings_section(
			'wp_sentry_logger_connection',
			__( 'Connection', 'wp-sentry-logger' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'dsn',
			__( 'DSN', 'wp-sentry-logger' ),
			[ $this, 'field_dsn' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_connection'
		);

		add_settings_field(
			'browser',
			__( 'Browser errors (optional)', 'wp-sentry-logger' ),
			[ $this, 'field_browser' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_connection'
		);

		add_settings_section(
			'wp_sentry_logger_noise',
			__( 'Noise control', 'wp-sentry-logger' ),
			static function (): void {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'WordPress sites produce a steady stream of warnings that are not real errors. Pick a severity level and add regex lines for anything that is still spam in your setup — matching events are dropped on the server, before any traffic is sent.', 'wp-sentry-logger' )
				);
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'level',
			__( 'Severity level', 'wp-sentry-logger' ),
			[ $this, 'field_level' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_noise'
		);

		add_settings_field(
			'blocklist',
			__( 'Regex blocklist', 'wp-sentry-logger' ),
			[ $this, 'field_blocklist' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_noise'
		);

		add_settings_field(
			'error_types',
			__( 'Raw error mask (advanced)', 'wp-sentry-logger' ),
			[ $this, 'field_error_types' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_noise'
		);

		add_settings_field(
			'privacy',
			__( 'Privacy', 'wp-sentry-logger' ),
			[ $this, 'field_pii' ],
			self::PAGE_SLUG,
			'wp_sentry_logger_noise'
		);

		add_action( 'admin_post_wp_sentry_logger_test', [ $this, 'handle_test_event' ] );
	}

	/**
	 * Render the full settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config = $this->plugin->config();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP Sentry Logger', 'wp-sentry-logger' ); ?></h1>

			<p>
				<?php
				if ( $config->is_active() ) {
					printf(
						/* translators: %s: DSN host name */
						esc_html__( 'Active — sending error events to %s.', 'wp-sentry-logger' ),
						'<code>' . esc_html( $this->dsn_host( $config->dsn ) ) . '</code>'
					);
				} else {
					esc_html_e( 'Dormant — set a DSN below to start sending error events. Until then the plugin does nothing.', 'wp-sentry-logger' );
				}
				?>
			</p>

			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wp_sentry_logger_test' ), 'wp_sentry_logger_test' ) ); ?>">
					<?php esc_html_e( 'Send test event', 'wp-sentry-logger' ); ?>
				</a>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'wp_sentry_logger' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Field: DSN input.
	 */
	public function field_dsn(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_DSN' );
		?>
		<input
			type="text"
			class="regular-text code"
			name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[dsn]' ); ?>"
			value="<?php echo esc_attr( $locked ? '' : Config::enforce_https_dsn( (string) $settings['dsn'] ) ); ?>"
			placeholder="https://<key>@errors.example.com/<project-id>"
			<?php disabled( $locked ); ?>
		/>
		<p class="description">
			<?php esc_html_e( 'Project DSN from your Sentry-compatible backend. Saved as https:// (plain http:// is upgraded automatically). Empty = plugin dormant.', 'wp-sentry-logger' ); ?>
			<?php
			if ( $locked ) {
				esc_html_e( ' Pinned by the WP_SENTRY_LOGGER_DSN constant in wp-config.php.', 'wp-sentry-logger' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Field: browser (JavaScript) capture toggle.
	 */
	public function field_browser(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_BROWSER' );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[browser]' ); ?>"
				value="1"
				<?php checked( (bool) $settings['browser'] ); ?>
				<?php disabled( $locked ); ?>
			/>
			<?php esc_html_e( 'Capture uncaught JavaScript errors from visitor browsers, relayed through a first-party endpoint on this site (no CORS or ad-blocker issues). Optional — leave off if you host browser monitoring separately. Uses the same DSN.', 'wp-sentry-logger' ); ?>
		</label>
		<?php
		if ( $locked ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Pinned by the WP_SENTRY_LOGGER_BROWSER constant in wp-config.php.', 'wp-sentry-logger' )
			);
		}
	}

	/**
	 * Field: severity preset radios.
	 */
	public function field_level(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_ERROR_TYPES' ) || defined( 'WP_SENTRY_LOGGER_LEVEL' );
		$current  = (string) $settings['level'];

		$labels = [
			LevelPreset::Fatals->value     => __( 'Fatals only — crashes, parse and fatal errors', 'wp-sentry-logger' ),
			LevelPreset::Default->value    => __( 'Default — fatals + warnings (recommended)', 'wp-sentry-logger' ),
			LevelPreset::Everything->value => __( 'Everything — includes notices and deprecations (noisy!)', 'wp-sentry-logger' ),
		];

		foreach ( $labels as $value => $label ) {
			?>
			<label style="display:block; margin:4px 0;">
				<input
					type="radio"
					name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[level]' ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php checked( $current, $value ); ?>
					<?php disabled( $locked ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
		}

		if ( $locked ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Pinned by the WP_SENTRY_LOGGER_ERROR_TYPES / WP_SENTRY_LOGGER_LEVEL constant in wp-config.php.', 'wp-sentry-logger' )
			);
		}
	}

	/**
	 * Field: regex blocklist textarea.
	 */
	public function field_blocklist(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_BLOCKLIST' );
		?>
		<textarea
			class="large-text code"
			rows="8"
			name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[blocklist]' ); ?>"
			<?php disabled( $locked ); ?>
		><?php echo esc_textarea( $locked ? '' : (string) $settings['blocklist'] ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'One regex per line (case-insensitive), matched against the error message, exception text and stack trace paths. Matching events are dropped. Lines starting with # are comments. Pinned by the WP_SENTRY_LOGGER_BLOCKLIST constant.', 'wp-sentry-logger' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: raw error_types override.
	 */
	public function field_error_types(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_ERROR_TYPES' );
		?>
		<input
			type="text"
			class="regular-text code"
			name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[error_types]' ); ?>"
			value="<?php echo esc_attr( null === $settings['error_types'] ? '' : (string) $settings['error_types'] ); ?>"
			placeholder="e.g. <?php echo (string) ( E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE ); ?>"
			<?php disabled( $locked ); ?>
		/>
		<p class="description">
			<?php esc_html_e( 'PHP error_reporting-style mask overriding the severity preset. Leave empty to use the preset. Pinned by the WP_SENTRY_LOGGER_ERROR_TYPES constant.', 'wp-sentry-logger' ); ?>
		</p>
		<?php
	}

	/**
	 * Field: PII checkbox.
	 */
	public function field_pii(): void {
		$settings = $this->settings();
		$locked   = defined( 'WP_SENTRY_LOGGER_SEND_DEFAULT_PII' );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( WP_SENTRY_LOGGER_OPTION . '[send_default_pii]' ); ?>"
				value="1"
				<?php checked( (bool) $settings['send_default_pii'] ); ?>
				<?php disabled( $locked ); ?>
			/>
			<?php esc_html_e( 'Attach request bodies, IP address and cookies to events (not recommended for e-commerce sites; user e-mail/name are never sent either way).', 'wp-sentry-logger' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitize the whole settings array on save.
	 *
	 * @param array<string,mixed>|mixed $input Raw submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];

		// Start from the stored option, not bare defaults, so fields without
		// a UI control (release, environment, sample_rate) and
		// constant-pinned fields (disabled → not submitted) are never wiped
		// by a form save.
		$clean = array_merge( Config::defaults(), (array) get_option( WP_SENTRY_LOGGER_OPTION, [] ) );

		if ( isset( $input['dsn'] ) && is_string( $input['dsn'] ) ) {
			$dsn = trim( $input['dsn'] );

			if ( '' === $dsn ) {
				$clean['dsn'] = '';
			} else {
				try {
					Dsn::createFromString( $dsn );
					$clean['dsn'] = Config::enforce_https_dsn( $dsn );
				} catch ( \Throwable $e ) {
					add_settings_error(
						WP_SENTRY_LOGGER_OPTION,
						'wp_sentry_logger_dsn',
						__( 'Invalid DSN — the plugin stays dormant until it is fixed.', 'wp-sentry-logger' )
					);
					$clean['dsn'] = '';
				}
			}
		}

		if ( isset( $input['level'] ) && is_string( $input['level'] ) ) {
			$clean['level'] = LevelPreset::tryFrom( $input['level'] )?->value ?? $clean['level'];
		}

		if ( isset( $input['error_types'] ) ) {
			$raw_mask = $input['error_types'];

			if ( is_scalar( $raw_mask ) && '' === trim( (string) $raw_mask ) ) {
				$clean['error_types'] = null;
			} elseif ( is_scalar( $raw_mask ) && is_numeric( $raw_mask ) ) {
				$clean['error_types'] = (int) $raw_mask;
			} else {
				add_settings_error(
					WP_SENTRY_LOGGER_OPTION,
					'wp_sentry_logger_error_types',
					sprintf(
						/* translators: %d: example PHP error mask value. */
						__( 'The error mask must be a number — e.g. %d for "everything except notices and deprecations". Non-numeric input was ignored.', 'wp-sentry-logger' ),
						( E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_USER_DEPRECATED )
					)
				);
			}
		}

		if ( isset( $input['blocklist'] ) && is_string( $input['blocklist'] ) ) {
			$clean['blocklist'] = sanitize_textarea_field( $input['blocklist'] );

			// Report invalid lines now — at runtime they are skipped, and a
			// suppression that silently does nothing sends the admin hunting.
			$probe = new EventFilter( ( Config::from_settings( $clean ) )->blocklist_patterns() );

			foreach ( $probe->invalid as $line ) {
				add_settings_error(
					WP_SENTRY_LOGGER_OPTION,
					'wp_sentry_logger_blocklist',
					sprintf(
						/* translators: %s: blocklist entry that is not a valid regex. */
						__( 'Invalid blocklist regex, this line does nothing: %s', 'wp-sentry-logger' ),
						esc_html( $line )
					)
				);
			}
		}

		// Disabled (constant-pinned) checkboxes are not submitted; keep the
		// stored value then, so un-pinning later does not silently flip them.
		if ( ! defined( 'WP_SENTRY_LOGGER_SEND_DEFAULT_PII' ) ) {
			$clean['send_default_pii'] = ! empty( $input['send_default_pii'] );
		}

		if ( ! defined( 'WP_SENTRY_LOGGER_BROWSER' ) ) {
			$clean['browser'] = ! empty( $input['browser'] );
		}

		return $clean;
	}

	/**
	 * admin-post handler behind the "Send test event" button.
	 */
	public function handle_test_event(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'wp_sentry_logger_test' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wp-sentry-logger' ) );
		}

		$event_id = $this->plugin->send_test_event();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'                 => self::PAGE_SLUG,
					'wp_sentry_logger_ids' => $event_id,
				],
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Host name of a DSN (the key part is never printed to the screen).
	 */
	private function dsn_host( string $dsn ): string {
		try {
			return (string) Dsn::createFromString( $dsn )->getHost();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Saved settings merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		return array_merge( Config::defaults(), (array) get_option( WP_SENTRY_LOGGER_OPTION, [] ) );
	}
}
