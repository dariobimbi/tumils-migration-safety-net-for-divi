<?php
// A missing snapshot file must never read as a clean page.

// Why the guard has to exist at all: the differ is right to find nothing
// between two empty strings — so "nothing" cannot be the clean signal.
check( 'differ finds no hunks between two empty documents', array() === Tumils_MSN_Differ::diff( '', '' ) );
check(
	'normalizer canonicals of two empty documents are equal (so the opaque guard stays silent too)',
	Tumils_MSN_Normalizer::canonical( '' ) === Tumils_MSN_Normalizer::canonical( '' )
);

$both   = Tumils_MSN_Compare::missing_snapshot_hunks( true, true );
$before = Tumils_MSN_Compare::missing_snapshot_hunks( true, false );
$after  = Tumils_MSN_Compare::missing_snapshot_hunks( false, true );

check( 'both readable -> no hunk, page compares normally', array() === Tumils_MSN_Compare::missing_snapshot_hunks( false, false ) );
check( 'before missing -> exactly one hunk', 1 === count( $before ) );
check( 'after missing -> exactly one hunk', 1 === count( $after ) );
check( 'both missing -> exactly one hunk', 1 === count( $both ) );
check( 'hunk is structural, so the page cannot be reported clean', 'structural' === $both[0]['severity'] );
check( 'hunk is typed missing-snapshot for the report label', 'missing-snapshot' === $both[0]['type'] );
// The sentence the user reads is built in Tumils_MSN_Admin so it can be
// translated at render time; what the stored hunk owes its caller is the slug.
check( 'hunk names the before run as data, not prose', 'before' === $before[0]['which'] );
check( 'hunk names the after run as data, not prose', 'after' === $after[0]['which'] );
check( 'hunk names both runs as data, not prose', 'both' === $both[0]['which'] );
check( 'no prose is cached in the hunk, so a report cannot freeze a locale', '' === $both[0]['before'] && '' === $both[0]['after'] );
