<?php
/**
 * Bump the plugin version in wp-sentry-logger.php (header + constant).
 *
 * Usage: php bin/bump-version.php 1.2.0
 */
declare(strict_types=1);

$version = $argv[1] ?? '';
$root    = dirname( __DIR__ );
$file    = $root . '/wp-sentry-logger.php';

if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $version ) ) {
	fwrite( STDERR, "usage: php bin/bump-version.php <X.Y.Z>\n" );
	exit( 1 );
}

$source = file_get_contents( $file );

if ( false === $source ) {
	fwrite( STDERR, "cannot read {$file}\n" );
	exit( 1 );
}

$source = preg_replace( '/Version:(\s+)[0-9]+\.[0-9]+\.[0-9]+/', 'Version:${1}' . $version, $source, 1, $n1 );
$source = preg_replace( "/WP_SENTRY_LOGGER_VERSION(\s+)= '[0-9.]+'/", 'WP_SENTRY_LOGGER_VERSION${1}= \'' . $version . '\'', $source, 1, $n2 );

if ( null === $source || 1 !== $n1 || 1 !== $n2 ) {
	fwrite( STDERR, "version markers not found in {$file}\n" );
	exit( 1 );
}

file_put_contents( $file, $source );
echo "bumped {$file} to {$version}\n";
