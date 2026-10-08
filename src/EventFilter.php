<?php
/**
 * Event-level noise control: the regex blocklist and the PII scrubber.
 *
 * Runs as the SDK `before_send` callback — the last stop before an event
 * leaves the server. Everything decided here is local: dropping an event
 * costs no network traffic, which is the whole point of keeping WordPress
 * noise out of the tracker.
 *
 * @package WPSentryLogger
 */

declare(strict_types=1);

namespace WPSentryLogger;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventType;

/**
 * Applies the configured blocklist to outgoing events.
 */
final class EventFilter {
	/**
	 * Compiled blocklist regexes. Invalid regexes are skipped (never
	 * thrown) and collected in $invalid.
	 *
	 * @var list<non-empty-string>
	 */
	private readonly array $patterns;

	/**
	 * Raw blocklist lines that are not valid regexes. They do nothing at
	 * runtime; the settings screen reports them on save.
	 *
	 * @var list<string>
	 */
	public readonly array $invalid;

	/**
	 * @param list<string> $patterns Raw blocklist lines from Config.
	 */
	public function __construct( array $patterns ) {
		$compiled = [];
		$invalid  = [];

		foreach ( $patterns as $pattern ) {
			$regex = $this->compile( $pattern );

			if ( null !== $regex ) {
				$compiled[] = $regex;
			} else {
				$invalid[] = $pattern;
			}
		}

		$this->patterns = $compiled;
		$this->invalid  = $invalid;
	}

	/**
	 * before_send callback: returns the event to send, or null to drop it.
	 */
	public function __invoke( Event $event, ?EventHint $hint ): ?Event {
		// Only SDK error events can be blocked; transactions/check-ins are
		// not produced by this plugin and pass through untouched.
		if ( EventType::event() === $event->getType() && $this->is_blocked( $event ) ) {
			return null;
		}

		$this->scrub_request( $event );

		/**
		 * Final veto / mutation filter, called for every event that survived
		 * the blocklist. Return null to drop the event, or the Event to send.
		 *
		 * @param Event|null  $event The event (null drops it).
		 * @param EventHint|null $hint  Hint with the original throwable, if any.
		 */
		return apply_filters( 'wp_sentry_logger_event', $event, $hint );
	}

	/**
	 * Does any blocklist pattern match this event's message, exception text
	 * or stack trace paths?
	 */
	private function is_blocked( Event $event ): bool {
		if ( [] === $this->patterns ) {
			return false;
		}

		$haystack = $this->haystack( $event );

		foreach ( $this->patterns as $regex ) {
			if ( preg_match( $regex, $haystack ) === 1 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the text a blocklist regex is matched against.
	 */
	private function haystack( Event $event ): string {
		$parts = [ (string) $event->getMessage() ];

		foreach ( $event->getExceptions() as $exception ) {
			$parts[] = $exception->getValue();

			$frames = $exception->getStacktrace()?->getFrames() ?? [];

			foreach ( $frames as $frame ) {
				$parts[] = (string) $frame->getFile();
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Remove URL query strings, request bodies, cookies, auth headers and
	 * server environment variables. WordPress URLs routinely carry customer
	 * search terms; those never belong in an error tracker.
	 */
	private function scrub_request( Event $event ): void {
		$request = $event->getRequest();

		if ( isset( $request['url'] ) && is_string( $request['url'] ) && '' !== $request['url'] ) {
			$parsed = parse_url( $request['url'] );

			if ( isset( $parsed['scheme'], $parsed['host'] ) ) {
				$request['url'] = $parsed['scheme'] . '://' . $parsed['host'] . ( $parsed['path'] ?? '' );
			}
		}

		unset(
			$request['query_string'],
			$request['data'],
			$request['cookies'],
			$request['env']
		);

		if ( isset( $request['headers'] ) && is_array( $request['headers'] ) ) {
			foreach ( [ 'Cookie', 'Authorization', 'Php-Auth-Digest' ] as $header ) {
				unset( $request['headers'][ $header ] );
			}
		}

		$event->setRequest( $request );
	}

	/**
	 * Wrap a raw blocklist line into a case-insensitive delimited regex.
	 *
	 * @return non-empty-string|null Null when the line is not a valid regex.
	 */
	private function compile( string $pattern ): ?string {
		if ( str_starts_with( $pattern, '/' ) ) {
			// Raw regex escape hatch. Keep it case-insensitive like the
			// simple form unless the line carries its own flags.
			$closing = strrpos( $pattern, '/' );
			$flags   = false !== $closing ? substr( $pattern, $closing + 1 ) : '';

			if ( false !== $closing && $closing > 0 && '' === preg_replace( '/[a-zA-Z]/', '', $flags ) && ! str_contains( $flags, 'i' ) ) {
				$pattern = substr( $pattern, 0, $closing + 1 ) . $flags . 'i';
			}

			$regex = $pattern;
		} else {
			$regex = '/' . str_replace( '/', '\/', $pattern ) . '/i';
		}

		// preg_match() on an empty subject both validates the pattern and
		// emits a PHP warning for invalid ones; the warning is silenced
		// because a broken admin-entered line must never break a request.
		return ( @preg_match( $regex, '' ) !== false ) ? $regex : null;
	}
}
