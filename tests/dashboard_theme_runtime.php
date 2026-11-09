<?php
/** Ensure WordPress's served dashboard theme contains the complete source styling. */
$runtime_path = __DIR__ . '/../assets/css/website-theme.css';
$source_path  = __DIR__ . '/../src/assets/css/website-theme.css';
$runtime      = file_get_contents( $runtime_path );
$source       = file_get_contents( $source_path );

if ( false === $runtime || false === $source || $runtime !== $source ) {
	fwrite( STDERR, "Runtime dashboard theme does not match its source stylesheet.\n" );
	exit( 1 );
}

$required_rules = array(
	'.wrap .pr-dash-metrics .rhs-grid',
	'.wrap .pr-dash-metrics .rhs-url-row',
	'.wrap .pr-dash-metrics .pr-dashboard-config-summary__grid',
	'.wrap .pr-dash-metrics .rhs-card:focus-within',
);
foreach ( $required_rules as $rule ) {
	if ( false === strpos( $runtime, $rule ) ) {
		fwrite( STDERR, "Missing dashboard styling rule: {$rule}\n" );
		exit( 2 );
	}
}

echo "Dashboard runtime theme checks passed.\n";
