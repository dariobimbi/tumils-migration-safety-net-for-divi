<?php
$tumils_msn_base = Tumils_MSN_Normalizer::canonical( fixture( 'base.html' ) );

check( 'canonical is non-empty', '' !== $tumils_msn_base );
check( 'canonical contains the body content', false !== strpos( $tumils_msn_base, 'Hello world' ) );
check( 'scripts stripped', false === strpos( $tumils_msn_base, 'a.js' ) );
check( 'scripts stripped (incl. in-body)', false === strpos( $tumils_msn_base, 'inline-marker' ) );
check( 'styles stripped', false === strpos( $tumils_msn_base, 'color:red' ) );
check( 'comments stripped', false === strpos( $tumils_msn_base, 'server comment' ) );
check( 'nonce values stripped', false === strpos( $tumils_msn_base, 'abc123' ) );
check( 'classes sorted', false !== strpos( $tumils_msn_base, 'et_pb_section section-a' ) );
check( 'noise-only differences normalize away',
	$tumils_msn_base === Tumils_MSN_Normalizer::canonical( fixture( 'base-noise.html' ) ) );
check( 'real changes survive normalization',
	$tumils_msn_base !== Tumils_MSN_Normalizer::canonical( fixture( 'base-migrated.html' ) ) );

// Task 11: real Divi 4.27.8 -> 5.2.1 migration noise, found via the noise gate.
check( 'noise: D5 block-marker classes (et_block_*) normalize away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-block-marker-classes-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-block-marker-classes-after.html' ) ) );
check( 'noise: D4 gutter-width class (et_pb_gutters3) removal normalizes away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-gutters-class-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-gutters-class-after.html' ) ) );
check( 'noise: et_pb_text_align_left removal normalizes away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-text-align-left-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-text-align-left-after.html' ) ) );
check( 'real change: et_pb_text_align_center removal still flagged (not swept up by the left-align rule)',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-text-align-center-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-text-align-center-after.html' ) ) );
check( 'noise: bare <span> wrapper around header text unwraps away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-bare-span-unwrap-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-bare-span-unwrap-after.html' ) ) );
check( 'real change: a <span> that carries its own class is not unwrapped (icon loss still flagged)',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-span-with-class-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-span-with-class-after.html' ) ) );
check( 'noise: bare et_pb_module marker class normalizes away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-module-marker-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-module-marker-after.html' ) ) );
check( 'noise: et_pb_with_background toggle class normalizes away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-with-background-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-with-background-after.html' ) ) );
check( 'noise: clearfix utility class normalizes away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-clearfix-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-clearfix-after.html' ) ) );
check( 'noise: implicit vs explicit-default divider position normalize to the same class',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-divider-position-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-divider-position-after.html' ) ) );
check( 'real change: divider moved from default (top) to a non-default position (center) still flagged',
	Tumils_MSN_Normalizer::canonical( '<div class="et_pb_divider et_pb_divider_position_top">X</div>' )
	!== Tumils_MSN_Normalizer::canonical( '<div class="et_pb_divider et_pb_divider_position_center">X</div>' ) );

// Task 11 fix round: adjudicated review resolutions.
check( 'real change: gutters value change (gutters1 -> absent) is not swept up by the default-only strip',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-gutters-value-change-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-gutters-value-change-after.html' ) ) );
check( 'noise: blurb wrapper unwrap still fires when the wrapper carries an extra class (e.g. et_block_*)',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-blurb-unwrap-extra-class-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-blurb-unwrap-extra-class-after.html' ) ) );

// Final review F4: unwrapping a MID-SENTENCE bare span left three adjacent text
// nodes, each of which the collapse pass then trimmed independently — welding
// "Hello world today" into "Helloworldtoday". The whole point of the unwrap is
// that these two forms are the same sentence, so it was destroying the noise
// rule it implements. Fixed by merging adjacent text nodes before the collapse.
check( 'noise: mid-sentence bare <span> unwrap preserves the surrounding spaces',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-bare-span-midsentence-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-bare-span-midsentence-after.html' ) ) );
check( 'noise: mid-sentence bare <span> unwrap -> zero hunks',
	array() === Tumils_MSN_Differ::diff(
		fixture( 'noise-divi5-bare-span-midsentence-before.html' ),
		fixture( 'noise-divi5-bare-span-midsentence-after.html' )
	) );
check( 'mid-sentence unwrap does not weld words together',
	false === strpos(
		Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-bare-span-midsentence-before.html' ) ),
		'aclear-eyedaudit'
	) );

// Task 12 noise measurement: two patterns the new attribute diffing surfaced on
// the real Divi 4.27.8 -> 5.2.1 migration, each measured to have no rendered
// counterpart, each paired with a counter-fixture proving the rule is narrow.
check( 'noise: empty title="" vs no title attribute normalize the same',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-empty-title-attr-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-empty-title-attr-after.html' ) ) );
check( 'noise: empty title="" -> zero hunks',
	array() === Tumils_MSN_Differ::diff(
		fixture( 'noise-divi5-empty-title-attr-before.html' ),
		fixture( 'noise-divi5-empty-title-attr-after.html' )
	) );
check( 'real change: a title with actual text going missing is still flagged',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-title-attr-change-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-title-attr-change-after.html' ) ) );

check( 'noise: Divi header logo width/height (derived, overridden by #logo{width:auto}) normalize away',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-header-logo-dimensions-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-divi5-header-logo-dimensions-after.html' ) ) );
check( 'noise: Divi header logo dimension change -> zero hunks',
	array() === Tumils_MSN_Differ::diff(
		fixture( 'noise-divi5-header-logo-dimensions-before.html' ),
		fixture( 'noise-divi5-header-logo-dimensions-after.html' )
	) );
check( 'real change: the logo SETTING (data-height-percentage) changing is still flagged',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-header-logo-setting-change-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-header-logo-setting-change-after.html' ) ) );
check( 'real change: a CONTENT image losing its width is not swept up by the header-logo rule',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-img-src-change-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-img-src-change-after.html' ) ) );

// Fix round 2, B: strip_cache_busters() bounded the version value with [^&#]*,
// which runs straight through the spaces and commas separating srcset entries.
// A three-entry srcset therefore collapsed to its FIRST url — the other two
// images and every size descriptor deleted from the canonical form — so a real
// responsive-image change anywhere but the first entry compared equal and the
// page was certified clean. Bounded on whitespace and commas as well now.
check( 'noise: srcset ver= bump on every entry -> canonicals equal',
	Tumils_MSN_Normalizer::canonical( fixture( 'noise-srcset-ver-bump-before.html' ) )
	=== Tumils_MSN_Normalizer::canonical( fixture( 'noise-srcset-ver-bump-after.html' ) ) );
check( 'noise: srcset ver= bump -> zero hunks',
	array() === Tumils_MSN_Differ::diff(
		fixture( 'noise-srcset-ver-bump-before.html' ),
		fixture( 'noise-srcset-ver-bump-after.html' )
	) );
// The counter-fixture changes the SECOND entry on purpose: the collapsed
// canonical kept the first url, so a first-entry change would have been caught
// even by the broken regex and would prove nothing.
check( 'real change: a srcset entry pointing at a different image is still flagged',
	Tumils_MSN_Normalizer::canonical( fixture( 'real-srcset-change-before.html' ) )
	!== Tumils_MSN_Normalizer::canonical( fixture( 'real-srcset-change-after.html' ) ) );

// Direct assertion that normalization is lossless for everything in the srcset
// except the version: all three candidates and all three size descriptors must
// survive. Without this the equality check above could be satisfied by a rule
// that deletes the whole attribute on both sides.
$tumils_msn_srcset = Tumils_MSN_Normalizer::canonical( fixture( 'noise-srcset-ver-bump-before.html' ) );
check( 'srcset keeps all three size descriptors',
	false !== strpos( $tumils_msn_srcset, '300w' )
	&& false !== strpos( $tumils_msn_srcset, '600w' )
	&& false !== strpos( $tumils_msn_srcset, '900w' ) );
check( 'srcset keeps all three candidate image basenames',
	false !== strpos( $tumils_msn_srcset, 'hero-300x200.jpg' )
	&& false !== strpos( $tumils_msn_srcset, 'hero-600x400.jpg' )
	&& false !== strpos( $tumils_msn_srcset, 'hero-900x600.jpg' ) );
check( 'srcset ver= values are gone and leave no dangling separator',
	false === strpos( $tumils_msn_srcset, 'ver=' ) && false === strpos( $tumils_msn_srcset, '.jpg?' ) );

// Fix round 3, item 2a: stripping a param mid-query stranded its delimiter
// ("?ver=6.9&w=300" -> "?&w=300"), so the SAME url with its params written in
// the other order canonicalized differently and the page reported a change
// nobody made. Delimiter collapse now runs for every position, not just before
// a space or the end of the string.
check( 'noise: param ORDER around a stripped ver= does not change the canonical (scalar href)',
	Tumils_MSN_Normalizer::canonical( '<html><body><a href="/page?w=300&ver=6.9">x</a></body></html>' )
	=== Tumils_MSN_Normalizer::canonical( '<html><body><a href="/page?ver=6.9&w=300">x</a></body></html>' ) );
check( 'noise: param ORDER around a stripped ver= does not change the canonical (srcset)',
	Tumils_MSN_Normalizer::canonical( '<html><body><img srcset="/a.jpg?w=300&ver=6.9 300w, /b.jpg?w=600&ver=6.9 600w"></body></html>' )
	=== Tumils_MSN_Normalizer::canonical( '<html><body><img srcset="/a.jpg?ver=6.9&w=300 300w, /b.jpg?ver=6.9&w=600 600w"></body></html>' ) );
check( 'noise: reordered params around ver= -> zero hunks',
	array() === Tumils_MSN_Differ::diff(
		'<html><body><a href="/page?w=300&ver=6.9">x</a></body></html>',
		'<html><body><a href="/page?ver=6.9&w=300">x</a></body></html>'
	) );

// Item 2b: a comma is legal inside a query value, and only a srcset uses commas
// as a separator. Bounding the scalar strip on commas spliced the tail of a
// comma-bearing version straight into the path ("/page?ver=1,2,3&other=x" ->
// "/page,2,3&other=x") — a mangled url compared against a mangled url, with the
// path itself corrupted. Scalar attrs stop at & and # only; srcset values are
// split on commas first and each entry cleaned on its own.
$tumils_msn_comma = Tumils_MSN_Normalizer::canonical( '<html><body><a href="/page?ver=1,2,3&other=x">x</a></body></html>' );
check( 'scalar url: comma-bearing ver= value does not splice into the path',
	false !== strpos( $tumils_msn_comma, 'page?other=x' ) );
check( 'scalar url: comma-bearing ver= leaves no fragment of itself behind',
	false === strpos( $tumils_msn_comma, 'ver=' ) && false === strpos( $tumils_msn_comma, ',2,3' ) );

// Fix round 3, item 3: attribute order is never semantic — no browser, CSS
// selector or script can tell `<div id="a" data-x="1">` from
// `<div data-x="1" id="a">`. canonical() serialized source order though, so an
// order-only difference hashed differently while the differ (which compares a
// sorted attribute map) correctly found nothing to report. That combination is
// exactly the phantom: hash says changed, diff says nothing, and the page gets
// a synthetic 'opaque' hunk for a change that does not exist.
$tumils_msn_ord_a = '<html><body><div id="a" data-x="1" data-y="2" class="et_pb_row">t</div></body></html>';
$tumils_msn_ord_b = '<html><body><div data-y="2" data-x="1" id="a" class="et_pb_row">t</div></body></html>';
check( 'noise: attribute ORDER does not change the canonical',
	Tumils_MSN_Normalizer::canonical( $tumils_msn_ord_a ) === Tumils_MSN_Normalizer::canonical( $tumils_msn_ord_b ) );
check( 'noise: attribute order difference -> zero hunks',
	array() === Tumils_MSN_Differ::diff( $tumils_msn_ord_a, $tumils_msn_ord_b ) );
check( 'attribute sorting preserves every name and value',
	1 === preg_match( '/<div class="et_pb_row" data-x="1" data-y="2" id="a">t<\/div>/', Tumils_MSN_Normalizer::canonical( $tumils_msn_ord_a ) ) );

// The attribute-order rebuild above used removeAttribute(), which silently
// refuses namespace declarations and reserved xml:* attributes: it returned
// false, hasAttribute() agreed, and the re-add then appended a second copy. So
// inline SVG — which every Divi page with an icon module carries — canonicalized
// with duplicated xmlns/xml:* attributes, and those attributes stayed pinned in
// source order, resurrecting the phantom this rule exists to kill. No fixture
// contained a namespaced attribute, which is how a 3,740-case fuzz pass missed it.
$tumils_msn_svg_a = '<html><body><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" class="icon" xml:space="preserve" viewBox="0 0 10 10"><rect x="0" y="0" width="4" height="4"/></svg></body></html>';
$tumils_msn_svg_b = '<html><body><svg class="icon" viewBox="0 0 10 10" xml:space="preserve" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><rect x="0" y="0" width="4" height="4"/></svg></body></html>';
$tumils_msn_svg_c = Tumils_MSN_Normalizer::canonical( $tumils_msn_svg_a );
preg_match( '/<svg[^>]*>/', $tumils_msn_svg_c, $tumils_msn_svg_tag );
$tumils_msn_svg_tag = isset( $tumils_msn_svg_tag[0] ) ? $tumils_msn_svg_tag[0] : '';
check( 'inline svg: xmlns is not duplicated by the attribute rebuild',
	1 === substr_count( $tumils_msn_svg_tag, 'xmlns="' ) );
check( 'inline svg: a reserved xml:* attribute is not duplicated either',
	1 === substr_count( $tumils_msn_svg_tag, 'xml:space=' ) );
check( 'inline svg: every attribute survives the rebuild',
	false !== strpos( $tumils_msn_svg_tag, 'class="icon"' )
	&& false !== strpos( $tumils_msn_svg_tag, 'xmlns:xlink="http://www.w3.org/1999/xlink"' )
	&& false !== strpos( $tumils_msn_svg_tag, 'viewbox="0 0 10 10"' ) );
check( 'noise: namespaced attribute ORDER does not change the canonical',
	$tumils_msn_svg_c === Tumils_MSN_Normalizer::canonical( $tumils_msn_svg_b ) );
check( 'noise: reordered namespaced attributes -> zero hunks',
	array() === Tumils_MSN_Differ::diff( $tumils_msn_svg_a, $tumils_msn_svg_b ) );
check( 'real change: an inline svg attribute VALUE change is still flagged',
	array() !== Tumils_MSN_Differ::diff( $tumils_msn_svg_a, str_replace( 'viewBox="0 0 10 10"', 'viewBox="0 0 20 20"', $tumils_msn_svg_a ) ) );
