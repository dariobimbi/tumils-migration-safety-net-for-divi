<?php
// Plain fixture-based test runner. Usage: php tests/run.php
// The diff core is WP-free on purpose — these tests need no WordPress.
// Every shipped file carries the ABSPATH guard a wordpress.org reviewer looks
// for, so the runner satisfies it before the first require. The classes' own
// WordPress calls all live inside methods, so the pure parts still run here.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-tumils-msn-normalizer.php';
require __DIR__ . '/../includes/class-tumils-msn-differ.php';
require __DIR__ . '/../includes/class-tumils-msn-compare.php';

$GLOBALS['tumils_msn_pass'] = 0;
$GLOBALS['tumils_msn_fail'] = 0;

function check( $label, $cond ) {
	if ( $cond ) {
		$GLOBALS['tumils_msn_pass']++;
		echo "  ok  $label\n";
	} else {
		$GLOBALS['tumils_msn_fail']++;
		echo "FAIL  $label\n";
	}
}

function fixture( $name ) {
	return file_get_contents( __DIR__ . '/fixtures/' . $name );
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $t ) {
	echo basename( $t ) . "\n";
	require $t;
}

echo "\n{$GLOBALS['tumils_msn_pass']} passed, {$GLOBALS['tumils_msn_fail']} failed\n";
exit( $GLOBALS['tumils_msn_fail'] ? 1 : 0 );
