<?php
/**
 * Dependency-free recursive PHP syntax check for local development and CI.
 *
 * @package Messageistic
 */

$root     = dirname( __DIR__ );
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
        static function ( SplFileInfo $file ): bool {
            return ! in_array( $file->getFilename(), [ '.git', 'vendor' ], true );
        }
    )
);
$failed = [];
$count  = 0;

foreach ( $iterator as $file ) {
    if ( ! $file instanceof SplFileInfo || 'php' !== strtolower( $file->getExtension() ) ) {
        continue;
    }

    ++$count;
    $command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ) . ' 2>&1';
    exec( $command, $output, $status );
    if ( 0 !== $status ) {
        $failed[] = implode( PHP_EOL, $output );
    }
    $output = [];
}

if ( $failed ) {
    fwrite( STDERR, implode( PHP_EOL . PHP_EOL, $failed ) . PHP_EOL );
    exit( 1 );
}

echo sprintf( "PHP syntax check passed for %d files on PHP %s.\n", $count, PHP_VERSION );
