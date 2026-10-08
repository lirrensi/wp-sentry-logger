<?php
/**
 * Zip up the plugin for distribution (vendor included, dev junk excluded).
 */
declare(strict_types=1);

$dst  = $argv[1] ?? 'wp-sentry-logger-local.zip';
$zip  = new ZipArchive();

if ( $zip->open( $dst, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
	fwrite( STDERR, "zip open failed\n" );
	exit(1);
}

$root = getcwd();

// WordPress treats an archive with a single top-level folder as the plugin
// folder. Wrapping under the slug gives every install (and every update) the
// same deterministic path: wp-content/plugins/wp-sentry-logger/.
$slug = 'wp-sentry-logger';

$it   = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $it as $file ) {
	$path = (string) $file;

	// ZIP members must use forward slashes (a Windows build gives backslashes,
	// which breaks extraction on Linux hosts).
	$rel = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );

	if ( $file->isDir() ) {
		continue;
	}
	if ( str_starts_with( $rel, '.' ) || str_contains( $rel, '/.git/' ) || str_contains( $rel, '/.github/' ) ) {
		continue;
	}
	if ( str_starts_with( $rel, 'tests/' ) || str_starts_with( $rel, 'bin/' ) ) {
		continue;
	}
	if ( str_ends_with( $rel, '.zip' ) ) {
		continue;
	}

	$zip->addFile( $path, $slug . '/' . $rel );
}

$num = $zip->numFiles;

$zip->close();
echo "files: {$num}\n";
