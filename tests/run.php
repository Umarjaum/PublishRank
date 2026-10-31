<?php
/**
 * Test runner: executes every tests/cases/*.php file in its own PHP process (so function definitions and hooks
 * from one file cannot leak into another) and prints a summary. Exit code is non-zero on any failure.
 *
 * Usage: PR_WP_PATH=/path/to/wordpress php tests/run.php [name-filter]
 */
if ( isset( $argv[1] ) && '--case' === $argv[1] ) {
	// Child process: run one file.
	require __DIR__ . '/bootstrap.php';
	$file   = $argv[2];
	$filter = isset( $argv[3] ) ? $argv[3] : '';
	$tests  = require $file;
	$failed = 0;
	$passed = 0;
	foreach ( $tests as $pr_name => $pr_fn ) {
		if ( '' !== $filter && false === stripos( $pr_name, $filter ) ) {
			continue;
		}
		try {
			pr_reset_state();
			$pr_fn();
			++$passed;
			echo "  ok    {$pr_name}\n";
		} catch ( \Throwable $e ) {
			++$failed;
			echo "  FAIL  {$pr_name}\n        " . str_replace( "\n", "\n        ", $e->getMessage() ) . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ")\n";
		}
	}
	echo "RESULT {$passed} {$failed}\n";
	exit( $failed ? 1 : 0 );
}

$filter = isset( $argv[1] ) ? $argv[1] : '';
$files  = glob( __DIR__ . '/cases/*.php' );
sort( $files );
$total_pass = 0;
$total_fail = 0;
foreach ( $files as $file ) {
	echo basename( $file ) . "\n";
	$cmd    = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --case ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $filter ) . ' 2>&1';
	$output = array();
	exec( $cmd, $output, $code );
	$text = implode( "\n", $output );
	$lines = array();
	foreach ( $output as $line ) {
		if ( 0 === strpos( $line, 'RESULT ' ) ) {
			list( , $p, $f ) = explode( ' ', $line );
			$total_pass     += (int) $p;
			$total_fail     += (int) $f;
		} else {
			$lines[] = $line;
		}
	}
	if ( false === strpos( $text, 'RESULT ' ) ) {
		++$total_fail;
		echo "  FAIL  (crashed before reporting)\n";
	}
	echo implode( "\n", $lines ) . "\n";
}
echo "\n{$total_pass} passed, {$total_fail} failed\n";
exit( $total_fail ? 1 : 0 );
