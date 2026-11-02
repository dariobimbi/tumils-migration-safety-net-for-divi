# Migration Safety Net for Divi 5 — v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship v1 of the plugin — snapshot a site's rendered pages, diff two snapshot runs with a noise-filtering DOM differ, and report changes in a native WP admin UI — ready for wordpress.org submission.

**Architecture:** Pure-PHP diff core (DOMDocument normalizer + tree differ, zero WP dependencies, standalone fixture tests) wrapped in thin WP glue: one custom table + files in `uploads/msn-snapshots/`, batched AJAX capture/compare (shared-hosting timeout safety), and a `WP_List_Table` report screen.

**Tech Stack:** PHP 7.4+ (DOMDocument, no Composer), vanilla JS, hand-written CSS, WordPress 6.0+, plain `tests/run.php` test runner (no PHPUnit).

**Spec:** [docs/superpowers/specs/2026-08-22-migration-safety-net-design.md](../specs/2026-08-22-migration-safety-net-design.md) — read it before starting.

## Global Constraints

- Slug/text domain: `migration-safety-net-for-divi` (must NOT lead with "divi"). Display name: **Migration Safety Net for Divi 5**. Prefix everything `msn_` / `MSN_`.
- License GPL v2 or later. The free plugin is complete — no license gates, no upsell-crippled code paths.
- No build step: plain PHP + vanilla JS + hand-written CSS. No npm, no Composer, no framework.
- Page capture = loopback `wp_remote_get` only. No headless browser, no exec, no external services.
- Diff core (`class-msn-normalizer.php`, `class-msn-differ.php`) must load with **no WordPress functions** — tests run it via bare `php tests/run.php`.
- Every admin action: `current_user_can( 'manage_options' )` + nonce. Escape all output (`esc_html`, `esc_attr`, `esc_url`).
- Repo root = the plugin. `docs/`, `tests/`, `CLAUDE.md` excluded from the .org build via `.gitattributes export-ignore`.
- Requires at least: WP 6.0, PHP 7.4. Version starts at `0.1.0`.
- Local PHP CLI: discover under `c:\wamp64\bin\php\` (Task 1 records the exact path — reuse it everywhere as `$PHP`).
- Commit after every task (steps say when). Windows/Wamp environment; Git Bash and PowerShell both available.

## File Structure (final)

```
migration-safety-net-for-divi.php   main file: header, constants, includes, hooks
uninstall.php                       drop table + delete snapshot files
includes/class-msn-normalizer.php   pure PHP — parse + strip noise (core IP)
includes/class-msn-differ.php       pure PHP — tree diff + severity classify (core IP)
includes/class-msn-store.php        custom table + snapshot files
includes/class-msn-capture.php      URL enumeration + loopback fetch, batched
includes/class-msn-compare.php      diff two runs, cache hunk JSON per page
includes/class-msn-admin.php        menu, screens, AJAX endpoints
includes/class-msn-report-table.php WP_List_Table for the report
assets/admin.css                    hand-written admin styles
assets/admin.js                     vanilla JS batch-progress loop
readme.txt                          .org readme
.gitattributes                      export-ignore for the .org zip
tests/run.php                       plain test runner
tests/test-normalizer.php
tests/test-differ.php
tests/fixtures/*.html               real-HTML fixtures
```

---

### Task 1: Plugin skeleton + test harness

**Files:**
- Create: `migration-safety-net-for-divi.php`
- Create: `uninstall.php`
- Create: `.gitattributes`
- Create: `tests/run.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `MSN_VERSION`, `MSN_PLUGIN_DIR`, `MSN_PLUGIN_URL` constants; main file `require`s every `includes/class-msn-*.php` (later tasks only add files, never touch the loader); test harness globals `check( string $label, bool $cond )` and `fixture( string $name ): string` that all `tests/test-*.php` files use.

- [ ] **Step 1: Find the PHP CLI and record it**

Run (Git Bash): `ls /c/wamp64/bin/php/`
Pick the newest 8.x directory. `$PHP` below means that full path, e.g. `/c/wamp64/bin/php/php8.3.14/php.exe`. Verify: `$PHP -v` prints PHP 8.x.

- [ ] **Step 2: Write the main plugin file**

`migration-safety-net-for-divi.php`:

```php
<?php
/**
 * Plugin Name:       Migration Safety Net for Divi 5
 * Plugin URI:        https://dariobimbi.com/migration-safety-net-for-divi/
 * Description:       Verify your Divi 4 → Divi 5 migration: snapshot every page before, re-scan after, and see exactly what changed — or get the clean bill of health.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dario Bimbi
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       migration-safety-net-for-divi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MSN_VERSION', '0.1.0' );
define( 'MSN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

foreach ( glob( MSN_PLUGIN_DIR . 'includes/class-msn-*.php' ) as $msn_file ) {
	require_once $msn_file;
}
unset( $msn_file );

if ( class_exists( 'MSN_Store' ) ) {
	register_activation_hook( __FILE__, array( 'MSN_Store', 'install' ) );
}
if ( is_admin() && class_exists( 'MSN_Admin' ) ) {
	MSN_Admin::init();
}
```

(The `class_exists` guards let the plugin activate before Tasks 5–8 exist.)

- [ ] **Step 3: Write uninstall.php**

```php
<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}msn_snapshots" ); // phpcs:ignore

$msn_up  = wp_upload_dir();
$msn_dir = trailingslashit( $msn_up['basedir'] ) . 'msn-snapshots';
if ( is_dir( $msn_dir ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $msn_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $msn_file ) {
		$msn_file->isDir() ? rmdir( $msn_file->getPathname() ) : unlink( $msn_file->getPathname() );
	}
	rmdir( $msn_dir );
}
```

- [ ] **Step 4: Write .gitattributes**

```
docs/ export-ignore
tests/ export-ignore
.gitattributes export-ignore
.gitignore export-ignore
CLAUDE.md export-ignore
```

- [ ] **Step 5: Write the test harness**

`tests/run.php`:

```php
<?php
// Plain fixture-based test runner. Usage: php tests/run.php
// The diff core is WP-free on purpose — these tests need no WordPress.
require __DIR__ . '/../includes/class-msn-normalizer.php';
require __DIR__ . '/../includes/class-msn-differ.php';

$GLOBALS['msn_pass'] = 0;
$GLOBALS['msn_fail'] = 0;

function check( $label, $cond ) {
	if ( $cond ) {
		$GLOBALS['msn_pass']++;
		echo "  ok  $label\n";
	} else {
		$GLOBALS['msn_fail']++;
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

echo "\n{$GLOBALS['msn_pass']} passed, {$GLOBALS['msn_fail']} failed\n";
exit( $GLOBALS['msn_fail'] ? 1 : 0 );
```

- [ ] **Step 6: Verify — lint everything, run the (empty) suite**

Run: `$PHP -l migration-safety-net-for-divi.php && $PHP -l uninstall.php`
Expected: "No syntax errors detected" twice.
Run: `$PHP tests/run.php`
Expected: FAILS with "No such file" for `class-msn-normalizer.php` — correct, Task 2 creates it. (This is the harness's failing state; do not "fix" it here.)

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat: plugin skeleton, uninstall, test harness"
```

---

### Task 2: Normalizer (the noise filter — TDD)

**Files:**
- Create: `includes/class-msn-normalizer.php`
- Create: `tests/test-normalizer.php`
- Create: `tests/fixtures/base.html`, `tests/fixtures/base-noise.html`, `tests/fixtures/base-migrated.html`

**Interfaces:**
- Consumes: test harness `check()` / `fixture()` from Task 1.
- Produces: `MSN_Normalizer::document( string $html ): DOMDocument` (parsed + normalized in place) and `MSN_Normalizer::canonical( string $html ): string` (serialized normalized `<body>`; two pages with only noise differences produce identical canonicals). Pure PHP — no WP functions allowed in this file.

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/base.html`:

```html
<!doctype html>
<html><head><title>T</title>
<meta charset="utf-8">
<link rel="stylesheet" href="style.css?ver=4.27.0">
<script src="a.js?ver=1.0"></script>
</head>
<body class="page et_pb_pagebuilder_layout">
<!-- server comment 1 -->
<div id="main" class="et_pb_section section-a">
  <div class="et_pb_row">
    <div class="et_pb_column et_pb_column_1_2"><p>Hello   world</p></div>
    <div class="et_pb_column et_pb_column_1_2"><p>Side</p></div>
  </div>
</div>
<form action="/?ver=1"><input type="hidden" name="_wpnonce" value="abc123"></form>
<style>.x{color:red}</style>
</body></html>
```

`tests/fixtures/base-noise.html` — the SAME page as a second request would render it. Every difference here is noise the normalizer must erase: class order flipped, nonce rotated, `ver=` bumped, whitespace reflowed, comment changed:

```html
<!doctype html>
<html><head><title>T</title>
<meta charset="utf-8">
<link rel="stylesheet" href="style.css?ver=5.2.1">
<script src="a.js?ver=9.9"></script>
</head>
<body class="et_pb_pagebuilder_layout page">
<!-- server comment 2 changed -->
<div id="main" class="section-a et_pb_section">
  <div class="et_pb_row">
    <div class="et_pb_column_1_2 et_pb_column"><p>Hello world</p></div>
    <div class="et_pb_column et_pb_column_1_2"><p>
      Side</p></div>
  </div>
</div>
<form action="/?ver=2"><input type="hidden" name="_wpnonce" value="zzz999"></form>
<style>.y{color:blue}</style>
</body></html>
```

`tests/fixtures/base-migrated.html` — same as `base.html` except the row gained a real layout class (what a D4→D5 flex conversion looks like): the row div's class is `et_pb_row et_flex_row`. Copy base.html and change only that one attribute.

- [ ] **Step 2: Write the failing tests**

`tests/test-normalizer.php`:

```php
<?php
$msn_base = MSN_Normalizer::canonical( fixture( 'base.html' ) );

check( 'canonical is non-empty', '' !== $msn_base );
check( 'canonical contains the body content', false !== strpos( $msn_base, 'Hello world' ) );
check( 'scripts stripped', false === strpos( $msn_base, 'a.js' ) );
check( 'styles stripped', false === strpos( $msn_base, 'color:red' ) );
check( 'comments stripped', false === strpos( $msn_base, 'server comment' ) );
check( 'nonce values stripped', false === strpos( $msn_base, 'abc123' ) );
check( 'classes sorted', false !== strpos( $msn_base, 'et_pb_section section-a' ) );
check( 'noise-only differences normalize away',
	$msn_base === MSN_Normalizer::canonical( fixture( 'base-noise.html' ) ) );
check( 'real changes survive normalization',
	$msn_base !== MSN_Normalizer::canonical( fixture( 'base-migrated.html' ) ) );
```

- [ ] **Step 3: Run tests, verify they fail**

Run: `$PHP tests/run.php`
Expected: fatal "No such file" for the normalizer include (or class not found).

- [ ] **Step 4: Implement the normalizer**

`includes/class-msn-normalizer.php`:

```php
<?php
/**
 * HTML normalizer: parses a rendered page and erases request-to-request noise
 * so that the differ only ever sees real changes. Pure PHP — no WordPress.
 * This whitelist of noise rules is the product's accuracy; extend it from
 * real-world fixtures, never speculatively.
 */
class MSN_Normalizer {

	/**
	 * Parse HTML into a DOMDocument, normalized in place.
	 *
	 * @param string $html Raw page HTML.
	 * @return DOMDocument
	 */
	public static function document( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		self::strip_noise_nodes( $doc );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body instanceof DOMElement ) {
			self::normalize_element( $body );
		}
		return $doc;
	}

	/**
	 * Canonical string form of the normalized <body>.
	 * Equal canonicals ⇒ the pages are the same page.
	 *
	 * @param string $html Raw page HTML.
	 * @return string
	 */
	public static function canonical( $html ) {
		$doc  = self::document( $html );
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		return $body ? $doc->saveHTML( $body ) : '';
	}

	/** Remove node types that never carry layout signal. */
	private static function strip_noise_nodes( DOMDocument $doc ) {
		$xpath = new DOMXPath( $doc );
		$kill  = array();
		foreach ( $xpath->query( '//script | //style | //noscript | //link | //meta | //comment()' ) as $node ) {
			$kill[] = $node;
		}
		foreach ( $kill as $node ) {
			$node->parentNode->removeChild( $node );
		}
	}

	/** Recursively normalize attributes and text of an element. */
	private static function normalize_element( DOMElement $el ) {
		// Class order is not a change.
		if ( $el->hasAttribute( 'class' ) ) {
			$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
			sort( $classes );
			$el->setAttribute( 'class', implode( ' ', $classes ) );
		}

		// Cache-buster query args vary per asset version, not per layout.
		foreach ( array( 'href', 'src', 'action' ) as $attr ) {
			if ( $el->hasAttribute( $attr ) ) {
				$el->setAttribute( $attr, self::strip_cache_busters( $el->getAttribute( $attr ) ) );
			}
		}

		// Nonces rotate every request.
		$remove = array();
		foreach ( $el->attributes as $a ) {
			if ( false !== stripos( $a->name, 'nonce' ) ) {
				$remove[] = $a->name;
			}
		}
		foreach ( $remove as $name ) {
			$el->removeAttribute( $name );
		}
		if ( 'input' === $el->tagName && false !== stripos( (string) $el->getAttribute( 'name' ), 'nonce' ) ) {
			$el->setAttribute( 'value', '' );
		}

		// Children: collapse text whitespace, drop blank text, recurse into elements.
		$children = array();
		foreach ( $el->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text = preg_replace( '/\s+/u', ' ', $child->nodeValue );
				if ( '' === trim( $text ) ) {
					$el->removeChild( $child );
				} else {
					$child->nodeValue = trim( $text );
				}
			} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
				self::normalize_element( $child );
			}
		}
	}

	/** Drop ver= query args (WP cache busters) from a URL. */
	private static function strip_cache_busters( $url ) {
		$url = preg_replace( '/([?&])ver=[^&#]*/', '$1', $url );
		return rtrim( $url, '?&' );
	}
}
```

- [ ] **Step 5: Run tests, verify all normalizer checks pass**

Run: `$PHP tests/run.php`
Expected: all 9 normalizer checks "ok", 0 failed. (Differ tests don't exist yet.)

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: HTML normalizer with noise fixtures (core IP part 1)"
```

---

### Task 3: Differ + severity classifier (TDD)

**Files:**
- Create: `includes/class-msn-differ.php`
- Create: `tests/test-differ.php`
- Create: `tests/fixtures/base-text.html`, `tests/fixtures/base-removed.html`

**Interfaces:**
- Consumes: `MSN_Normalizer::document()`, `MSN_Normalizer::canonical()`.
- Produces: `MSN_Differ::diff( string $before_html, string $after_html ): array` — list of hunks, each an assoc array with keys `type` (`added|removed|classes|style|text`), `severity` (`structural|cosmetic`), `path` (string like `body > div.et_pb_section > div.et_pb_row`), `before` (string), `after` (string). Empty array = clean page. Pure PHP — no WP functions.

- [ ] **Step 1: Write the fixtures**

`tests/fixtures/base-text.html`: copy `base.html`, change only `Hello   world` → `Hello   there`.
`tests/fixtures/base-removed.html`: copy `base.html`, delete the entire second `<div class="et_pb_column et_pb_column_1_2"><p>Side</p></div>`.

- [ ] **Step 2: Write the failing tests**

`tests/test-differ.php`:

```php
<?php
check( 'identical page → zero hunks',
	array() === MSN_Differ::diff( fixture( 'base.html' ), fixture( 'base.html' ) ) );

check( 'noise-only pair → zero hunks',
	array() === MSN_Differ::diff( fixture( 'base.html' ), fixture( 'base-noise.html' ) ) );

$msn_h = MSN_Differ::diff( fixture( 'base.html' ), fixture( 'base-migrated.html' ) );
check( 'migrated → exactly one hunk', 1 === count( $msn_h ) );
check( 'migrated hunk is a class change', 'classes' === $msn_h[0]['type'] );
check( 'migrated hunk is structural', 'structural' === $msn_h[0]['severity'] );
check( 'migrated hunk names the new class', 'et_flex_row' === $msn_h[0]['after'] );
check( 'migrated hunk path reaches the row', false !== strpos( $msn_h[0]['path'], 'et_pb_row' ) );

$msn_t = MSN_Differ::diff( fixture( 'base.html' ), fixture( 'base-text.html' ) );
check( 'text change → exactly one hunk', 1 === count( $msn_t ) );
check( 'text hunk is cosmetic text', 'text' === $msn_t[0]['type'] && 'cosmetic' === $msn_t[0]['severity'] );
check( 'text hunk carries before/after', 'Hello world' === $msn_t[0]['before'] && 'Hello there' === $msn_t[0]['after'] );

$msn_r = MSN_Differ::diff( fixture( 'base.html' ), fixture( 'base-removed.html' ) );
check( 'removed column → exactly one hunk', 1 === count( $msn_r ) );
check( 'removed hunk is structural removal', 'removed' === $msn_r[0]['type'] && 'structural' === $msn_r[0]['severity'] );
check( 'removed hunk shows the lost markup', false !== strpos( $msn_r[0]['before'], 'Side' ) );
```

- [ ] **Step 3: Run tests, verify differ tests fail**

Run: `$PHP tests/run.php`
Expected: normalizer checks pass; run aborts or fails on `MSN_Differ` not found.

- [ ] **Step 4: Implement the differ**

`includes/class-msn-differ.php`:

```php
<?php
/**
 * DOM tree differ. Compares two normalized pages and emits hunks
 * classified structural (layout-relevant) vs cosmetic. Pure PHP — no WordPress.
 */
class MSN_Differ {

	// Class fragments that make a change layout-relevant. Extend from real migrations.
	const LAYOUT_RX = '/et_pb_(section|row|column|gutters)|_builder|et_flex|flex_|_grid|grid_/';

	// Tags whose addition/removal is structural regardless of classes.
	const STRUCTURAL_TAGS = array( 'section', 'header', 'footer', 'nav', 'aside', 'main', 'table', 'ul', 'ol', 'article' );

	/**
	 * Diff two raw HTML pages.
	 *
	 * @return array[] Hunks: {type, severity, path, before, after}. Empty = clean.
	 */
	public static function diff( $before_html, $after_html ) {
		if ( MSN_Normalizer::canonical( $before_html ) === MSN_Normalizer::canonical( $after_html ) ) {
			return array();
		}
		$a = self::body( $before_html );
		$b = self::body( $after_html );
		$hunks = array();
		if ( $a && $b ) {
			self::compare_element( $a, $b, 'body', $hunks );
		}
		return $hunks;
	}

	private static function body( $html ) {
		$body = MSN_Normalizer::document( $html )->getElementsByTagName( 'body' )->item( 0 );
		return ( $body instanceof DOMElement ) ? $body : null;
	}

	/** Compare two matched elements: classes, inline style, then children. */
	private static function compare_element( DOMElement $a, DOMElement $b, $path, array &$hunks ) {
		$ca = self::classes( $a );
		$cb = self::classes( $b );
		if ( $ca !== $cb ) {
			$added   = array_values( array_diff( $cb, $ca ) );
			$removed = array_values( array_diff( $ca, $cb ) );
			$hunks[] = array(
				'type'     => 'classes',
				'severity' => self::layoutish( array_merge( $added, $removed ) ) ? 'structural' : 'cosmetic',
				'path'     => $path,
				'before'   => implode( ' ', $removed ),
				'after'    => implode( ' ', $added ),
			);
		}
		if ( $a->getAttribute( 'style' ) !== $b->getAttribute( 'style' ) ) {
			$hunks[] = array(
				'type'     => 'style',
				'severity' => 'cosmetic',
				'path'     => $path,
				'before'   => $a->getAttribute( 'style' ),
				'after'    => $b->getAttribute( 'style' ),
			);
		}
		self::compare_children( $a, $b, $path, $hunks );
	}

	/** Align child lists with LCS, recurse on matches, emit add/remove for the rest. */
	private static function compare_children( DOMElement $a, DOMElement $b, $path, array &$hunks ) {
		$ka = self::child_nodes( $a );
		$kb = self::child_nodes( $b );
		$sa = array_map( array( __CLASS__, 'signature' ), $ka );
		$sb = array_map( array( __CLASS__, 'signature' ), $kb );

		$pairs   = self::lcs_pairs( $sa, $sb );
		$pairs[] = array( count( $ka ), count( $kb ) ); // sentinel to flush trailing adds/removes

		$i = 0;
		$j = 0;
		foreach ( $pairs as $pair ) {
			for ( ; $i < $pair[0]; $i++ ) {
				$hunks[] = self::node_hunk( 'removed', $ka[ $i ], $path );
			}
			for ( ; $j < $pair[1]; $j++ ) {
				$hunks[] = self::node_hunk( 'added', $kb[ $j ], $path );
			}
			if ( $pair[0] < count( $ka ) ) {
				$na = $ka[ $pair[0] ];
				$nb = $kb[ $pair[1] ];
				if ( XML_TEXT_NODE === $na->nodeType ) {
					if ( $na->nodeValue !== $nb->nodeValue ) {
						$hunks[] = array(
							'type'     => 'text',
							'severity' => 'cosmetic',
							'path'     => $path,
							'before'   => $na->nodeValue,
							'after'    => $nb->nodeValue,
						);
					}
				} else {
					self::compare_element( $na, $nb, $path . ' > ' . self::label( $na ), $hunks );
				}
				$i++;
				$j++;
			}
		}
	}

	/**
	 * Alignment signature. Deliberately loose (tag + id only): a div whose
	 * classes changed must still align with itself so we report a class-change
	 * hunk, not a bogus remove+add pair. That looseness is what makes the
	 * D4→D5 case (same wrappers, new classes) read correctly.
	 */
	private static function signature( DOMNode $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return '#text';
		}
		return $node->tagName . '#' . $node->getAttribute( 'id' );
	}

	/** Element + text children only. */
	private static function child_nodes( DOMElement $el ) {
		$out = array();
		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType || XML_TEXT_NODE === $child->nodeType ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/** Longest common subsequence → matched index pairs, ascending. */
	private static function lcs_pairs( array $a, array $b ) {
		$n = count( $a );
		$m = count( $b );
		// ponytail: O(n*m) DP; child lists are short. Positional fallback guards pathological pages.
		if ( $n * $m > 250000 ) {
			$pairs = array();
			$min   = min( $n, $m );
			for ( $k = 0; $k < $min; $k++ ) {
				if ( $a[ $k ] === $b[ $k ] ) {
					$pairs[] = array( $k, $k );
				}
			}
			return $pairs;
		}
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = ( $a[ $i ] === $b[ $j ] )
					? $dp[ $i + 1 ][ $j + 1 ] + 1
					: max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}
		$pairs = array();
		$i     = 0;
		$j     = 0;
		while ( $i < $n && $j < $m ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				$pairs[] = array( $i, $j );
				$i++;
				$j++;
			} elseif ( $dp[ $i + 1 ][ $j ] >= $dp[ $i ][ $j + 1 ] ) {
				$i++;
			} else {
				$j++;
			}
		}
		return $pairs;
	}

	/** Hunk for an unmatched (added/removed) node. */
	private static function node_hunk( $type, DOMNode $node, $path ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return array(
				'type'     => $type,
				'severity' => 'cosmetic',
				'path'     => $path,
				'before'   => 'removed' === $type ? $node->nodeValue : '',
				'after'    => 'added' === $type ? $node->nodeValue : '',
			);
		}
		$structural = in_array( $node->tagName, self::STRUCTURAL_TAGS, true )
			|| self::layoutish( self::classes( $node ) );
		$snippet = self::snippet( $node );
		return array(
			'type'     => $type,
			'severity' => $structural ? 'structural' : 'cosmetic',
			'path'     => $path,
			'before'   => 'removed' === $type ? $snippet : '',
			'after'    => 'added' === $type ? $snippet : '',
		);
	}

	private static function classes( DOMElement $el ) {
		return preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	private static function layoutish( array $classes ) {
		foreach ( $classes as $c ) {
			if ( preg_match( self::LAYOUT_RX, $c ) ) {
				return true;
			}
		}
		return false;
	}

	private static function label( DOMElement $el ) {
		$classes = self::classes( $el );
		return $el->tagName . ( $classes ? '.' . $classes[0] : '' );
	}

	private static function snippet( DOMElement $el ) {
		$html = $el->ownerDocument->saveHTML( $el );
		return strlen( $html ) > 300 ? substr( $html, 0, 300 ) . '…' : $html;
	}
}
```

- [ ] **Step 5: Run tests, verify all pass**

Run: `$PHP tests/run.php`
Expected: all normalizer + differ checks "ok", exit 0. If the migrated hunk's `path` check fails: `label()` uses the first class alphabetically (normalizer sorted them) — adjust the test's expectation to the actual sorted-first class, never the code.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat: DOM differ with severity classifier (core IP part 2)"
```

---

### Task 4: Local dev environment (test WP install)

**Files:** none in the repo (throwaway install at `c:\wamp64\www\msn-test`). This task exists because Tasks 5–9 verify against a real WordPress.

**Interfaces:**
- Consumes: nothing from the repo.
- Produces: WP at `http://localhost/msn-test` (admin `admin`/`admin`), wp-cli at `c:\wamp64\tmp\wp-cli.phar` (invoke as `$PHP c:/wamp64/tmp/wp-cli.phar --path=c:/wamp64/www/msn-test <cmd>` — call this `$WP` in later tasks), plugin junction-linked into it, Divi 5 active, ~6 sample pages.

- [ ] **Step 1: Get wp-cli**

Run: `curl -L -o /c/wamp64/tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`
Verify: `$PHP /c/wamp64/tmp/wp-cli.phar --version` prints WP-CLI 2.x.

- [ ] **Step 2: Install WordPress**

```bash
mkdir -p /c/wamp64/www/msn-test
$PHP /c/wamp64/tmp/wp-cli.phar core download --path=/c/wamp64/www/msn-test
/c/wamp64/bin/mysql/mysql8.4.7/bin/mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS msn_test"
$PHP /c/wamp64/tmp/wp-cli.phar config create --path=/c/wamp64/www/msn-test --dbname=msn_test --dbuser=root --dbpass= --dbhost=127.0.0.1
$PHP /c/wamp64/tmp/wp-cli.phar core install --path=/c/wamp64/www/msn-test --url=http://localhost/msn-test --title="MSN Test" --admin_user=admin --admin_password=<local-password> --admin_email=you@example.com --skip-email
```

Verify: `curl -s http://localhost/msn-test/ | grep -o "<title>[^<]*"` prints the site title. (If Wamp isn't running, start it first.)

- [ ] **Step 3: Copy Divi 5 in and activate**

```powershell
robocopy c:\wamp64\www\nickeyperformance.com\wp-content\themes\Divi c:\wamp64\www\msn-test\wp-content\themes\Divi /E /NFL /NDL /NJH
```
(Copy only — never modify the nickeyperformance source; it mirrors production.) Then:
`$WP theme activate Divi`

- [ ] **Step 4: Link the plugin and create sample content**

```powershell
New-Item -ItemType Junction -Path c:\wamp64\www\msn-test\wp-content\plugins\migration-safety-net-for-divi -Target c:\wamp64\www\migration-safety-net-for-divi
```

```bash
$WP rewrite structure '/%postname%/' --hard
for i in 1 2 3; do $WP post create --post_type=page --post_status=publish --post_title="Sample page $i" --post_content="<p>Sample body $i</p>"; done
for i in 1 2 3; do $WP post create --post_status=publish --post_title="Sample post $i" --post_content="<p>Post body $i</p>"; done
```

- [ ] **Step 5: Verify plugin activates**

Run: `$WP plugin activate migration-safety-net-for-divi && $WP plugin list`
Expected: plugin listed `active`. (Table doesn't exist yet — `class_exists( 'MSN_Store' )` guard makes activation a no-op until Task 5.)

No commit (nothing in the repo changed).

---

### Task 5: Store — custom table + snapshot files

**Files:**
- Create: `includes/class-msn-store.php`

**Interfaces:**
- Consumes: `MSN_Normalizer::canonical()`; the activation hook wired in Task 1.
- Produces:
  - `MSN_Store::install(): void` — dbDelta the table.
  - `MSN_Store::table(): string` — prefixed table name.
  - `MSN_Store::dir( string $sub = '' ): string` — `uploads/msn-snapshots[/sub]`, created on demand.
  - `MSN_Store::save_snapshot( string $run_id, string $label, string $url, string $html ): void`
  - `MSN_Store::get_runs(): array` — rows of `{run_id, label, created, pages}` newest first.
  - `MSN_Store::get_run_pages( string $run_id ): array` — rows of `{url, url_hash, content_hash}`.
  - `MSN_Store::get_html( string $run_id, string $url_hash ): string` — `''` if missing.
  - `MSN_Store::delete_run( string $run_id ): void`

- [ ] **Step 1: Implement**

`includes/class-msn-store.php`:

```php
<?php
/**
 * Persistence: one index table + raw HTML files under uploads/msn-snapshots/.
 */
class MSN_Store {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'msn_snapshots';
	}

	/** Activation: create/upgrade the index table. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id char(12) NOT NULL,
			label varchar(64) NOT NULL default '',
			url text NOT NULL,
			url_hash char(32) NOT NULL,
			content_hash char(32) NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY url_hash (url_hash)
		) " . $wpdb->get_charset_collate() . ';' );
	}

	/** Snapshot storage dir (created on demand, listing-protected). */
	public static function dir( $sub = '' ) {
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . 'msn-snapshots';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
			file_put_contents( $base . '/index.php', "<?php // Silence is golden.\n" );
		}
		$dir = $sub ? $base . '/' . $sub : $base;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function save_snapshot( $run_id, $label, $url, $html ) {
		global $wpdb;
		$url_hash = md5( $url );
		file_put_contents( self::dir( $run_id ) . '/' . $url_hash . '.html', $html );
		$wpdb->insert(
			self::table(),
			array(
				'run_id'       => $run_id,
				'label'        => $label,
				'url'          => $url,
				'url_hash'     => $url_hash,
				'content_hash' => md5( MSN_Normalizer::canonical( $html ) ),
				'created'      => current_time( 'mysql' ),
			)
		);
	}

	public static function get_runs() {
		global $wpdb;
		return $wpdb->get_results(
			'SELECT run_id, MAX(label) AS label, MIN(created) AS created, COUNT(*) AS pages
			 FROM ' . self::table() . ' GROUP BY run_id ORDER BY created DESC'
		);
	}

	public static function get_run_pages( $run_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT url, url_hash, content_hash FROM ' . self::table() . ' WHERE run_id = %s ORDER BY id ASC',
				$run_id
			)
		);
	}

	public static function get_html( $run_id, $url_hash ) {
		$file = self::dir( $run_id ) . '/' . $url_hash . '.html';
		return is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	}

	public static function delete_run( $run_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'run_id' => $run_id ) );
		$dir = self::dir( $run_id );
		foreach ( glob( $dir . '/*.html' ) ?: array() as $f ) {
			unlink( $f );
		}
		@rmdir( $dir ); // phpcs:ignore
	}
}
```

- [ ] **Step 2: Verify against the real install**

```bash
$PHP -l includes/class-msn-store.php
$WP plugin deactivate migration-safety-net-for-divi && $WP plugin activate migration-safety-net-for-divi
$WP db query "SHOW TABLES LIKE 'wp_msn_snapshots'"
$WP eval 'MSN_Store::save_snapshot( "testrun00001", "smoke", home_url("/"), "<html><body><p>hi</p></body></html>" ); var_dump( count( MSN_Store::get_run_pages( "testrun00001" ) ) );'
$WP eval 'var_dump( strlen( MSN_Store::get_html( "testrun00001", md5( home_url("/") ) ) ) ); MSN_Store::delete_run( "testrun00001" ); var_dump( count( MSN_Store::get_runs() ) );'
```
Expected: table listed; `int(1)`; a positive length; `int(0)`.

- [ ] **Step 3: Commit**

```bash
git add includes/class-msn-store.php && git commit -m "feat: snapshot store (table + files)"
```

---

### Task 6: Capture engine — URL enumeration + batched loopback fetch

**Files:**
- Create: `includes/class-msn-capture.php`

**Interfaces:**
- Consumes: `MSN_Store::save_snapshot()`.
- Produces:
  - `MSN_Capture::urls(): array` — deduped list: front page, every published post of every public post type (capped by filter `msn_max_urls`, default 200), post-type archive links, one term archive per public taxonomy.
  - `MSN_Capture::capture_batch( string $run_id, string $label, int $offset, int $size = 5 ): array` — fetches+stores one slice; returns `array( 'done' => int, 'total' => int, 'errors' => string[] )`. Callers loop until `done === total`.

- [ ] **Step 1: Implement**

`includes/class-msn-capture.php`:

```php
<?php
/**
 * Page capture: enumerate public URLs, fetch each via loopback wp_remote_get.
 * Batched so a big site never hits a PHP timeout — shared hosting is the target.
 */
class MSN_Capture {

	/** Every public URL we snapshot, stable order, deduped. */
	public static function urls() {
		$urls  = array( home_url( '/' ) );
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		$cap = (int) apply_filters( 'msn_max_urls', 200 );
		$ids = get_posts(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => $cap,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}

		// One sample archive per type: post-type archives + first term of each public taxonomy.
		foreach ( get_post_types( array( 'has_archive' => true ), 'names' ) as $pt ) {
			$urls[] = get_post_type_archive_link( $pt );
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'number' => 1, 'hide_empty' => true ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$link = get_term_link( $terms[0] );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/** Loopback fetch. False on any non-200. */
	public static function fetch( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'sslverify'   => false, // loopback to self; local certs are often self-signed
			)
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		return wp_remote_retrieve_body( $res );
	}

	/** Capture one slice of the URL list. */
	public static function capture_batch( $run_id, $label, $offset, $size = 5 ) {
		$urls   = self::urls();
		$errors = array();
		foreach ( array_slice( $urls, $offset, $size ) as $url ) {
			$html = self::fetch( $url );
			if ( false === $html ) {
				$errors[] = $url;
			} else {
				MSN_Store::save_snapshot( $run_id, $label, $url, $html );
			}
		}
		return array(
			'done'   => min( $offset + $size, count( $urls ) ),
			'total'  => count( $urls ),
			'errors' => $errors,
		);
	}

	/** New unique run id. */
	public static function new_run_id() {
		return substr( md5( uniqid( 'msn', true ) ), 0, 12 );
	}
}
```

- [ ] **Step 2: Verify a full capture on the test install**

```bash
$PHP -l includes/class-msn-capture.php
$WP eval 'var_dump( MSN_Capture::urls() );'
```
Expected: front page + the 6 sample pages/posts + blog/category archives, no duplicates, no empty entries.

```bash
$WP eval '$r = MSN_Capture::new_run_id(); $o = 0; do { $b = MSN_Capture::capture_batch( $r, "verify", $o ); $o = $b["done"]; } while ( $b["done"] < $b["total"] ); echo $r, " ", $b["done"], "/", $b["total"], " errors:", count( $b["errors"] );'
$WP eval 'foreach ( MSN_Store::get_runs() as $run ) { echo $run->run_id, " ", $run->label, " ", $run->pages, "\n"; }'
```
Expected: `done == total`, 0 errors, one run row with that page count. Spot-check one stored file contains real page HTML (`<body`).

- [ ] **Step 3: Commit**

```bash
git add includes/class-msn-capture.php && git commit -m "feat: batched loopback capture engine"
```

---

### Task 7: Compare engine — diff two runs, cache results

**Files:**
- Create: `includes/class-msn-compare.php`

**Interfaces:**
- Consumes: `MSN_Store::get_run_pages()`, `MSN_Store::get_html()`, `MSN_Store::dir()`, `MSN_Differ::diff()`.
- Produces:
  - `MSN_Compare::compare_batch( string $run_a, string $run_b, int $offset, int $size = 5 ): array` — `array( 'done' => int, 'total' => int )`; diffs one slice of run_b's URL set against run_a and writes one JSON per URL into `uploads/msn-snapshots/compare-{run_a}-{run_b}/{url_hash}.json`.
  - `MSN_Compare::results( string $run_a, string $run_b ): array` — list of assoc rows `{url, url_hash, status ('clean'|'changed'|'only-before'|'only-after'|'pending'), structural, cosmetic}` for every URL in either run.
  - `MSN_Compare::page_hunks( string $run_a, string $run_b, string $url_hash ): array` — the hunk list from that page's JSON (empty array if clean/missing).
  - JSON file shape: `{"url": "...", "hunks": [<MSN_Differ hunks>], "structural": n, "cosmetic": n}`.

- [ ] **Step 1: Implement**

`includes/class-msn-compare.php`:

```php
<?php
/**
 * Compares two snapshot runs. Hash-equal pages are clean without parsing;
 * changed pages get a full MSN_Differ pass, cached as JSON per page.
 */
class MSN_Compare {

	public static function dir( $run_a, $run_b ) {
		return MSN_Store::dir( 'compare-' . $run_a . '-' . $run_b );
	}

	/** URLs present in either run, keyed by url_hash. */
	private static function url_map( $run_a, $run_b ) {
		$map = array();
		foreach ( MSN_Store::get_run_pages( $run_a ) as $row ) {
			$map[ $row->url_hash ] = array( 'url' => $row->url, 'a' => $row->content_hash, 'b' => null );
		}
		foreach ( MSN_Store::get_run_pages( $run_b ) as $row ) {
			if ( ! isset( $map[ $row->url_hash ] ) ) {
				$map[ $row->url_hash ] = array( 'url' => $row->url, 'a' => null, 'b' => null );
			}
			$map[ $row->url_hash ]['b'] = $row->content_hash;
		}
		return $map;
	}

	public static function compare_batch( $run_a, $run_b, $offset, $size = 5 ) {
		$map  = self::url_map( $run_a, $run_b );
		$keys = array_keys( $map );
		$dir  = self::dir( $run_a, $run_b );

		foreach ( array_slice( $keys, $offset, $size ) as $url_hash ) {
			$row  = $map[ $url_hash ];
			$file = $dir . '/' . $url_hash . '.json';
			if ( null === $row['a'] || null === $row['b'] || $row['a'] === $row['b'] ) {
				continue; // missing on one side or hash-clean: no JSON needed
			}
			$hunks = MSN_Differ::diff(
				MSN_Store::get_html( $run_a, $url_hash ),
				MSN_Store::get_html( $run_b, $url_hash )
			);
			$structural = 0;
			foreach ( $hunks as $h ) {
				if ( 'structural' === $h['severity'] ) {
					$structural++;
				}
			}
			file_put_contents(
				$file,
				wp_json_encode(
					array(
						'url'        => $row['url'],
						'hunks'      => $hunks,
						'structural' => $structural,
						'cosmetic'   => count( $hunks ) - $structural,
					)
				)
			);
		}

		return array(
			'done'  => min( $offset + $size, count( $keys ) ),
			'total' => count( $keys ),
		);
	}

	/** Site-level result rows for the report table. */
	public static function results( $run_a, $run_b ) {
		$dir = self::dir( $run_a, $run_b );
		$out = array();
		foreach ( self::url_map( $run_a, $run_b ) as $url_hash => $row ) {
			$item = array(
				'url'        => $row['url'],
				'url_hash'   => $url_hash,
				'status'     => 'clean',
				'structural' => 0,
				'cosmetic'   => 0,
			);
			if ( null === $row['a'] ) {
				$item['status'] = 'only-after';
			} elseif ( null === $row['b'] ) {
				$item['status'] = 'only-before';
			} elseif ( $row['a'] !== $row['b'] ) {
				$json = self::read_json( $dir . '/' . $url_hash . '.json' );
				if ( null === $json ) {
					$item['status'] = 'pending'; // hash differs but compare_batch hasn't reached it
				} else {
					// Differ may still say clean (hash includes head-order quirks the differ ignores).
					$item['status']     = $json['hunks'] ? 'changed' : 'clean';
					$item['structural'] = $json['structural'];
					$item['cosmetic']   = $json['cosmetic'];
				}
			}
			$out[] = $item;
		}
		usort(
			$out,
			function ( $x, $y ) {
				return array( $y['structural'], $y['cosmetic'] ) <=> array( $x['structural'], $x['cosmetic'] );
			}
		);
		return $out;
	}

	public static function page_hunks( $run_a, $run_b, $url_hash ) {
		$json = self::read_json( self::dir( $run_a, $run_b ) . '/' . $url_hash . '.json' );
		return $json ? $json['hunks'] : array();
	}

	private static function read_json( $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : null;
	}
}
```

- [ ] **Step 2: Verify — the clean re-scan case (spec success criterion #2)**

Capture the same unchanged site twice, compare — everything must be clean:

```bash
$PHP -l includes/class-msn-compare.php
$WP eval '
$a = MSN_Capture::new_run_id(); $o = 0; do { $b1 = MSN_Capture::capture_batch( $a, "before", $o ); $o = $b1["done"]; } while ( $o < $b1["total"] );
$b = MSN_Capture::new_run_id(); $o = 0; do { $b2 = MSN_Capture::capture_batch( $b, "after", $o ); $o = $b2["done"]; } while ( $o < $b2["total"] );
$o = 0; do { $c = MSN_Compare::compare_batch( $a, $b, $o ); $o = $c["done"]; } while ( $o < $c["total"] );
$bad = 0; foreach ( MSN_Compare::results( $a, $b ) as $r ) { if ( "clean" !== $r["status"] ) { $bad++; echo $r["url"], " => ", $r["status"], "\n"; } }
echo "non-clean: ", $bad, "\n"; update_option( "msn_verify_runs", array( $a, $b ) );'
```
Expected: `non-clean: 0`. **If any page is not clean, that is a normalizer gap — the exact iteration loop the spec predicts.** Diagnose (`$WP eval` the two stored HTML files through `MSN_Differ::diff` and read the hunks), add the noise pattern to `MSN_Normalizer` **with a new fixture pair reproducing it in `tests/`**, re-run `$PHP tests/run.php`, then re-run this verification. Repeat until 0.

- [ ] **Step 3: Verify — a real change is caught**

```bash
$WP eval '$p = get_posts( array( "post_type" => "page", "posts_per_page" => 1, "orderby" => "ID", "order" => "ASC" ) ); wp_update_post( array( "ID" => $p[0]->ID, "post_content" => "<p>Sample body EDITED</p><section class=\"et_pb_section new-thing\">added</section>" ) );'
$WP eval '
list( $a, ) = get_option( "msn_verify_runs" );
$c2 = MSN_Capture::new_run_id(); $o = 0; do { $b3 = MSN_Capture::capture_batch( $c2, "edited", $o ); $o = $b3["done"]; } while ( $o < $b3["total"] );
$o = 0; do { $c = MSN_Compare::compare_batch( $a, $c2, $o ); $o = $c["done"]; } while ( $o < $c["total"] );
foreach ( MSN_Compare::results( $a, $c2 ) as $r ) { if ( "clean" !== $r["status"] ) { echo $r["url"], " s:", $r["structural"], " c:", $r["cosmetic"], "\n"; } }'
```
Expected: exactly the edited page reported, with ≥1 structural (the added `et_pb_section`) and ≥1 cosmetic (the text edit) hunk; every other page clean.

- [ ] **Step 4: Commit**

```bash
git add includes/class-msn-compare.php && git commit -m "feat: run comparison engine with cached page diffs"
```

---

### Task 8: Admin UI — runs screen, capture + compare with AJAX progress

**Files:**
- Create: `includes/class-msn-admin.php`
- Create: `assets/admin.js`
- Create: `assets/admin.css`

**Interfaces:**
- Consumes: `MSN_Capture`, `MSN_Compare`, `MSN_Store`; `MSN_Admin::init()` already called from the main file (Task 1).
- Produces: Tools → Migration Safety Net screen (`tools_page_msn` hook, capability `manage_options`); AJAX actions `msn_capture_batch` and `msn_compare_batch` (both return the engines' `{done, total}` JSON via `wp_send_json_success`); report rendering delegated to `MSN_Admin::render_report( $run_a, $run_b )` and `MSN_Admin::render_page_detail( $run_a, $run_b, $url_hash )` which Task 9 fills in (stub them with `echo 'TODO Task 9';` for now — the ONLY allowed stub, replaced next task).

- [ ] **Step 1: Implement the admin class**

`includes/class-msn-admin.php`:

```php
<?php
/**
 * Admin UI: snapshot runs list, capture/compare triggers with AJAX progress.
 */
class MSN_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_msn_capture_batch', array( __CLASS__, 'ajax_capture_batch' ) );
		add_action( 'wp_ajax_msn_compare_batch', array( __CLASS__, 'ajax_compare_batch' ) );
		add_action( 'admin_post_msn_delete_run', array( __CLASS__, 'handle_delete_run' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'Migration Safety Net for Divi 5', 'migration-safety-net-for-divi' ),
			__( 'Migration Safety Net', 'migration-safety-net-for-divi' ),
			'manage_options',
			'msn',
			array( __CLASS__, 'render' )
		);
	}

	public static function assets( $hook ) {
		if ( 'tools_page_msn' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'msn-admin', MSN_PLUGIN_URL . 'assets/admin.css', array(), MSN_VERSION );
		wp_enqueue_script( 'msn-admin', MSN_PLUGIN_URL . 'assets/admin.js', array(), MSN_VERSION, true );
		wp_localize_script(
			'msn-admin',
			'msnAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'msn_batch' ),
			)
		);
	}

	/** Router. */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'migration-safety-net-for-divi' ) );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'runs'; // phpcs:ignore WordPress.Security.NonceVerification
		$run_a = isset( $_GET['run_a'] ) ? sanitize_key( $_GET['run_a'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$run_b = isset( $_GET['run_b'] ) ? sanitize_key( $_GET['run_b'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		echo '<div class="wrap msn-wrap"><h1>' . esc_html__( 'Migration Safety Net for Divi 5', 'migration-safety-net-for-divi' ) . '</h1>';
		if ( 'report' === $view && $run_a && $run_b ) {
			self::render_report( $run_a, $run_b );
		} elseif ( 'page' === $view && $run_a && $run_b && isset( $_GET['url_hash'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_page_detail( $run_a, $run_b, sanitize_key( $_GET['url_hash'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			self::render_runs();
		}
		echo '</div>';
	}

	/** Runs list + new-snapshot + compare forms. */
	private static function render_runs() {
		$runs = MSN_Store::get_runs();
		?>
		<div class="msn-card">
			<h2><?php esc_html_e( '1. Take a snapshot', 'migration-safety-net-for-divi' ); ?></h2>
			<p><?php esc_html_e( 'Capture how every public page renders right now. Take one before you migrate and one after.', 'migration-safety-net-for-divi' ); ?></p>
			<input type="text" id="msn-label" maxlength="64" placeholder="<?php esc_attr_e( 'Label, e.g. pre-migration', 'migration-safety-net-for-divi' ); ?>">
			<button class="button button-primary" id="msn-capture"><?php esc_html_e( 'Snapshot now', 'migration-safety-net-for-divi' ); ?></button>
			<div class="msn-progress" id="msn-capture-progress" hidden><div class="msn-progress-bar"></div><span class="msn-progress-text"></span></div>
		</div>

		<div class="msn-card">
			<h2><?php esc_html_e( '2. Compare two snapshots', 'migration-safety-net-for-divi' ); ?></h2>
			<?php if ( count( $runs ) < 2 ) : ?>
				<p><?php esc_html_e( 'You need at least two snapshots to compare.', 'migration-safety-net-for-divi' ); ?></p>
			<?php else : ?>
				<select id="msn-run-a">
					<?php foreach ( $runs as $r ) : ?>
						<option value="<?php echo esc_attr( $r->run_id ); ?>"><?php echo esc_html( $r->label . ' — ' . $r->created . ' (' . $r->pages . ' pages)' ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="msn-vs"><?php esc_html_e( 'vs', 'migration-safety-net-for-divi' ); ?></span>
				<select id="msn-run-b">
					<?php foreach ( $runs as $r ) : ?>
						<option value="<?php echo esc_attr( $r->run_id ); ?>"><?php echo esc_html( $r->label . ' — ' . $r->created . ' (' . $r->pages . ' pages)' ); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button button-primary" id="msn-compare"><?php esc_html_e( 'Compare', 'migration-safety-net-for-divi' ); ?></button>
				<div class="msn-progress" id="msn-compare-progress" hidden><div class="msn-progress-bar"></div><span class="msn-progress-text"></span></div>
			<?php endif; ?>
		</div>

		<?php if ( $runs ) : ?>
		<div class="msn-card">
			<h2><?php esc_html_e( 'Snapshots', 'migration-safety-net-for-divi' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Label', 'migration-safety-net-for-divi' ); ?></th>
					<th><?php esc_html_e( 'Taken', 'migration-safety-net-for-divi' ); ?></th>
					<th><?php esc_html_e( 'Pages', 'migration-safety-net-for-divi' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $runs as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->label ); ?> <code><?php echo esc_html( $r->run_id ); ?></code></td>
						<td><?php echo esc_html( $r->created ); ?></td>
						<td><?php echo esc_html( $r->pages ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this snapshot?', 'migration-safety-net-for-divi' ) ); ?>');">
								<?php wp_nonce_field( 'msn_delete_run' ); ?>
								<input type="hidden" name="action" value="msn_delete_run">
								<input type="hidden" name="run_id" value="<?php echo esc_attr( $r->run_id ); ?>">
								<button class="button-link-delete" type="submit"><?php esc_html_e( 'Delete', 'migration-safety-net-for-divi' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>
		<?php
	}

	// Task 9 replaces these two stubs with the real report.
	public static function render_report( $run_a, $run_b ) {
		echo 'TODO Task 9';
	}
	public static function render_page_detail( $run_a, $run_b, $url_hash ) {
		echo 'TODO Task 9';
	}

	private static function check_ajax() {
		check_ajax_referer( 'msn_batch' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
	}

	public static function ajax_capture_batch() {
		self::check_ajax();
		$run_id = isset( $_POST['run_id'] ) ? sanitize_key( $_POST['run_id'] ) : MSN_Capture::new_run_id();
		$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$result = MSN_Capture::capture_batch( $run_id, $label, $offset );
		$result['run_id'] = $run_id;
		wp_send_json_success( $result );
	}

	public static function ajax_compare_batch() {
		self::check_ajax();
		$run_a  = isset( $_POST['run_a'] ) ? sanitize_key( $_POST['run_a'] ) : '';
		$run_b  = isset( $_POST['run_b'] ) ? sanitize_key( $_POST['run_b'] ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		if ( ! $run_a || ! $run_b || $run_a === $run_b ) {
			wp_send_json_error( 'bad runs', 400 );
		}
		$result = MSN_Compare::compare_batch( $run_a, $run_b, $offset );
		$result['report_url'] = add_query_arg(
			array( 'page' => 'msn', 'view' => 'report', 'run_a' => $run_a, 'run_b' => $run_b ),
			admin_url( 'tools.php' )
		);
		wp_send_json_success( $result );
	}

	public static function handle_delete_run() {
		check_admin_referer( 'msn_delete_run' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		MSN_Store::delete_run( sanitize_key( $_POST['run_id'] ?? '' ) );
		wp_safe_redirect( add_query_arg( 'page', 'msn', admin_url( 'tools.php' ) ) );
		exit;
	}
}
```

- [ ] **Step 2: Write the JS batch loop**

`assets/admin.js`:

```js
/* Migration Safety Net admin: drive capture/compare AJAX batches with a progress bar. */
(function () {
	'use strict';

	function runBatches(action, params, progressEl, onDone) {
		var bar = progressEl.querySelector('.msn-progress-bar');
		var txt = progressEl.querySelector('.msn-progress-text');
		progressEl.hidden = false;

		function step(extra) {
			var data = new FormData();
			data.append('action', action);
			data.append('_ajax_nonce', msnAdmin.nonce);
			Object.keys(params).forEach(function (k) { data.append(k, params[k]); });
			Object.keys(extra).forEach(function (k) { data.append(k, extra[k]); });

			fetch(msnAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
				.then(function (r) { return r.json(); })
				.then(function (json) {
					if (!json.success) { throw new Error('server error'); }
					var d = json.data;
					bar.style.width = Math.round((d.done / d.total) * 100) + '%';
					txt.textContent = d.done + ' / ' + d.total;
					if (d.done < d.total) {
						step({ offset: d.done, run_id: d.run_id || params.run_id || '' });
					} else {
						onDone(d);
					}
				})
				.catch(function (err) {
					txt.textContent = 'Error: ' + err.message + ' — reload and retry.';
				});
		}
		step({ offset: 0 });
	}

	var captureBtn = document.getElementById('msn-capture');
	if (captureBtn) {
		captureBtn.addEventListener('click', function () {
			captureBtn.disabled = true;
			runBatches(
				'msn_capture_batch',
				{ label: document.getElementById('msn-label').value },
				document.getElementById('msn-capture-progress'),
				function () { window.location.reload(); }
			);
		});
	}

	var compareBtn = document.getElementById('msn-compare');
	if (compareBtn) {
		compareBtn.addEventListener('click', function () {
			compareBtn.disabled = true;
			runBatches(
				'msn_compare_batch',
				{
					run_a: document.getElementById('msn-run-a').value,
					run_b: document.getElementById('msn-run-b').value
				},
				document.getElementById('msn-compare-progress'),
				function (d) { window.location = d.report_url; }
			);
		});
	}
}());
```

Note: consecutive capture batches must reuse the `run_id` the first response returns — that's the `run_id: d.run_id || params.run_id || ''` line.

- [ ] **Step 3: Write the CSS**

`assets/admin.css`:

```css
/* Migration Safety Net admin styles — hand-written, no build step. */
.msn-wrap .msn-card {
	background: #fff;
	border: 1px solid #c3c4c7;
	border-radius: 4px;
	padding: 16px 20px;
	margin: 16px 0;
	max-width: 900px;
}
.msn-wrap .msn-vs { margin: 0 8px; }
.msn-progress {
	margin-top: 12px;
	background: #f0f0f1;
	border-radius: 3px;
	height: 22px;
	position: relative;
	overflow: hidden;
}
.msn-progress-bar {
	background: #2271b1;
	height: 100%;
	width: 0;
	transition: width 0.3s;
}
.msn-progress-text {
	position: absolute;
	inset: 0;
	text-align: center;
	line-height: 22px;
	font-size: 12px;
}
.msn-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}
.msn-badge-structural { background: #fcf0f1; color: #d63638; }
.msn-badge-cosmetic { background: #fcf9e8; color: #996800; }
.msn-badge-clean { background: #edfaef; color: #007a2f; }
.msn-clean-hero {
	text-align: center;
	padding: 48px 24px;
	font-size: 18px;
}
.msn-clean-hero .msn-clean-mark { font-size: 48px; display: block; margin-bottom: 8px; color: #00a32a; }
.msn-hunk {
	border: 1px solid #c3c4c7;
	border-left-width: 4px;
	border-radius: 3px;
	margin: 12px 0;
	padding: 10px 14px;
	overflow-x: auto;
}
.msn-hunk-structural { border-left-color: #d63638; }
.msn-hunk-cosmetic { border-left-color: #dba617; }
.msn-hunk pre {
	margin: 6px 0;
	padding: 8px;
	background: #f6f7f7;
	white-space: pre-wrap;
	word-break: break-word;
}
.msn-hunk .msn-before { background: #fcf0f1; }
.msn-hunk .msn-after { background: #edfaef; }
.msn-path { font-family: monospace; font-size: 12px; color: #646970; word-break: break-all; }
@media (max-width: 980px) {
	.msn-wrap .msn-card { padding: 12px; }
	.msn-wrap select, .msn-wrap input[type="text"] { max-width: 100%; width: 100%; margin-bottom: 8px; display: block; }
	.msn-wrap .msn-vs { display: block; margin: 4px 0; }
}
```

- [ ] **Step 4: Verify in the browser**

Lint all three files (`$PHP -l` for the class; JS/CSS by loading the page). Open `http://localhost/msn-test/wp-admin/tools.php?page=msn` (login admin/admin — use the Chrome DevTools or Playwright MCP tools). Then:
1. Enter label "ui-test", click **Snapshot now** → progress bar fills to N/N → page reloads → run appears in Snapshots table.
2. Take a second snapshot, select the two runs, click **Compare** → progress completes → lands on the report URL (shows "TODO Task 9" — correct for now).
3. Delete one run → confirm dialog → row gone.
4. No JS console errors on any step.

- [ ] **Step 5: Commit**

```bash
git add includes/class-msn-admin.php assets/ && git commit -m "feat: admin screen with batched capture/compare and progress UI"
```

---

### Task 9: Report UI — ranked worklist + per-page drill-down

**Files:**
- Create: `includes/class-msn-report-table.php`
- Modify: `includes/class-msn-admin.php` (replace the two Task-8 stubs)

**Interfaces:**
- Consumes: `MSN_Compare::results()`, `MSN_Compare::page_hunks()`.
- Produces: the report screen (`view=report`) and page detail (`view=page`). No new public API.

- [ ] **Step 1: Implement the list table**

`includes/class-msn-report-table.php`:

```php
<?php
/**
 * Report list table: one row per URL, ranked worst-first.
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MSN_Report_Table extends WP_List_Table {

	private $rows;
	private $run_a;
	private $run_b;

	public function __construct( array $rows, $run_a, $run_b ) {
		parent::__construct( array( 'plural' => 'msn_pages', 'ajax' => false ) );
		$this->rows  = $rows;
		$this->run_a = $run_a;
		$this->run_b = $run_b;
	}

	public function get_columns() {
		return array(
			'url'        => __( 'Page', 'migration-safety-net-for-divi' ),
			'status'     => __( 'Status', 'migration-safety-net-for-divi' ),
			'structural' => __( 'Structural', 'migration-safety-net-for-divi' ),
			'cosmetic'   => __( 'Cosmetic', 'migration-safety-net-for-divi' ),
		);
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $this->rows; // already ranked by MSN_Compare::results()
	}

	protected function column_url( $item ) {
		$detail = add_query_arg(
			array(
				'page'     => 'msn',
				'view'     => 'page',
				'run_a'    => $this->run_a,
				'run_b'    => $this->run_b,
				'url_hash' => $item['url_hash'],
			),
			admin_url( 'tools.php' )
		);
		$actions = array(
			'view_page' => '<a href="' . esc_url( $item['url'] ) . '" target="_blank">' . esc_html__( 'View page', 'migration-safety-net-for-divi' ) . '</a>',
		);
		if ( 'changed' === $item['status'] ) {
			$actions = array( 'detail' => '<a href="' . esc_url( $detail ) . '">' . esc_html__( 'View changes', 'migration-safety-net-for-divi' ) . '</a>' ) + $actions;
		}
		$title = 'changed' === $item['status']
			? '<a href="' . esc_url( $detail ) . '"><strong>' . esc_html( $item['url'] ) . '</strong></a>'
			: esc_html( $item['url'] );
		return $title . $this->row_actions( $actions );
	}

	protected function column_status( $item ) {
		$map = array(
			'clean'       => array( 'msn-badge-clean', __( 'Clean', 'migration-safety-net-for-divi' ) ),
			'changed'     => array( 'msn-badge-structural', __( 'Changed', 'migration-safety-net-for-divi' ) ),
			'only-before' => array( 'msn-badge-cosmetic', __( 'Missing after', 'migration-safety-net-for-divi' ) ),
			'only-after'  => array( 'msn-badge-cosmetic', __( 'New page', 'migration-safety-net-for-divi' ) ),
			'pending'     => array( 'msn-badge-cosmetic', __( 'Not compared yet', 'migration-safety-net-for-divi' ) ),
		);
		list( $class, $text ) = $map[ $item['status'] ];
		return '<span class="msn-badge ' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		return esc_html( (string) $item[ $column_name ] );
	}
}
```

- [ ] **Step 2: Replace the Task-8 stubs in class-msn-admin.php**

Replace `render_report` and `render_page_detail` bodies entirely:

```php
	/** Site-level report: ranked worklist, or the clean bill of health. */
	public static function render_report( $run_a, $run_b ) {
		$rows    = MSN_Compare::results( $run_a, $run_b );
		$changed = array_filter(
			$rows,
			function ( $r ) {
				return 'clean' !== $r['status'];
			}
		);
		$back = add_query_arg( 'page', 'msn', admin_url( 'tools.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to snapshots', 'migration-safety-net-for-divi' ) . '</a></p>';

		if ( ! $changed ) {
			// The payoff state: nothing broke.
			echo '<div class="msn-card msn-clean-hero"><span class="msn-clean-mark">&#10003;</span>';
			printf(
				/* translators: %d: number of pages checked */
				esc_html__( 'Migration verified clean — all %d pages render identically.', 'migration-safety-net-for-divi' ),
				count( $rows )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p>' . esc_html__( '%1$d of %2$d pages changed.', 'migration-safety-net-for-divi' ) . '</p>',
			(int) count( $changed ),
			(int) count( $rows )
		);
		$table = new MSN_Report_Table( $rows, $run_a, $run_b );
		$table->prepare_items();
		$table->display();
	}

	/** Per-page drill-down: every hunk with context. */
	public static function render_page_detail( $run_a, $run_b, $url_hash ) {
		$hunks = MSN_Compare::page_hunks( $run_a, $run_b, $url_hash );
		$url   = '';
		foreach ( MSN_Compare::results( $run_a, $run_b ) as $r ) {
			if ( $r['url_hash'] === $url_hash ) {
				$url = $r['url'];
				break;
			}
		}
		$back = add_query_arg(
			array( 'page' => 'msn', 'view' => 'report', 'run_a' => $run_a, 'run_b' => $run_b ),
			admin_url( 'tools.php' )
		);
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to report', 'migration-safety-net-for-divi' ) . '</a></p>';
		echo '<h2>' . esc_html( $url ) . '</h2>';
		echo '<p><a class="button" target="_blank" href="' . esc_url( $url ) . '">' . esc_html__( 'View page', 'migration-safety-net-for-divi' ) . '</a> ';
		echo '<a class="button" target="_blank" href="' . esc_url( add_query_arg( 'et_fb', '1', $url ) ) . '">' . esc_html__( 'Open in Divi builder', 'migration-safety-net-for-divi' ) . '</a></p>';

		if ( ! $hunks ) {
			echo '<p>' . esc_html__( 'No differences on this page.', 'migration-safety-net-for-divi' ) . '</p>';
			return;
		}
		foreach ( $hunks as $h ) {
			echo '<div class="msn-hunk msn-hunk-' . esc_attr( $h['severity'] ) . '">';
			echo '<span class="msn-badge msn-badge-' . esc_attr( $h['severity'] ) . '">' . esc_html( $h['severity'] ) . '</span> ';
			echo '<strong>' . esc_html( $h['type'] ) . '</strong>';
			echo '<div class="msn-path">' . esc_html( $h['path'] ) . '</div>';
			if ( '' !== $h['before'] ) {
				echo '<pre class="msn-before">&minus; ' . esc_html( $h['before'] ) . '</pre>';
			}
			if ( '' !== $h['after'] ) {
				echo '<pre class="msn-after">+ ' . esc_html( $h['after'] ) . '</pre>';
			}
			echo '</div>';
		}
	}
```

- [ ] **Step 3: Verify in the browser (both success criteria visually)**

Using the runs from Task 7 verification (or fresh ones):
1. Compare the two unchanged runs → **the green "Migration verified clean — all N pages render identically" hero** shows.
2. Compare pre-edit vs post-edit runs → the edited page tops the list with a Changed badge and structural/cosmetic counts; drill-down shows the class/text hunks with −/+ blocks; "View page" and "Open in Divi builder" links work.
3. Resize to ≤980px (DevTools responsive mode): cards, table, and hunk `<pre>` blocks don't overflow horizontally.
4. No PHP notices (`WP_DEBUG` on in the test install's wp-config: `$WP config set WP_DEBUG true --raw`), no JS console errors.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: report worklist and per-page diff drill-down"
```

---

### Task 10: readme.txt + .org build zip

**Files:**
- Create: `readme.txt`
- Modify: `.gitignore` (already ignores `*.zip`)

**Interfaces:**
- Consumes: nothing new.
- Produces: `readme.txt` at repo root; a reviewable zip via `git archive`.

- [ ] **Step 1: Write readme.txt**

```
=== Migration Safety Net for Divi 5 ===
Contributors: dariobimbi
Tags: divi, migration, diff, snapshot, regression
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify your Divi 4 → Divi 5 migration: snapshot every page before, re-scan after, and see exactly what changed.

== Description ==

Divi 5's built-in migrator predicts what *will* convert. Migration Safety Net proves what *did*.

**Workflow:**

1. **Snapshot** — capture how every public page renders, before you migrate.
2. Run Divi's migrator (or update the theme, or change anything).
3. **Re-scan** — take a second snapshot.
4. **Compare** — a noise-filtered DOM diff of every page: structure changes, class changes, text changes, ranked by severity.

A clean result is a first-class outcome: **"Migration verified clean"** is your proof that nothing silently broke.

Works on any host — pages are captured with WordPress's own HTTP layer. No headless browser, no external services, nothing leaves your server.

Not just for migrations: snapshot before any theme update, plugin update, or bulk edit and verify the result.

**Privacy:** this plugin makes no external requests and collects no data. Snapshots are stored in your own uploads folder.

This plugin is not affiliated with or endorsed by Elegant Themes. Divi is a trademark of Elegant Themes, Inc.

== Frequently Asked Questions ==

= Does it take screenshots? =

No — it compares the rendered HTML structure (DOM), which catches layout-relevant changes without needing a browser engine on your server.

= Will it slow down my site? =

Snapshots run only when you click the button, in small batches, using WordPress's own HTTP layer.

= How many pages does it capture? =

Published pages, posts, and custom post types (up to 200 by default — filterable via `msn_max_urls`), plus your front page and a sample of each archive.

== Changelog ==

= 0.1.0 =
* Initial release: snapshot engine, DOM diff engine with severity classification, comparison report.
```

- [ ] **Step 2: Build and inspect the zip**

```bash
git archive --format=zip --prefix=migration-safety-net-for-divi/ -o migration-safety-net-for-divi.zip HEAD
unzip -l migration-safety-net-for-divi.zip
```
Expected: NO `docs/`, `tests/`, `CLAUDE.md`, `.gitattributes`, `.gitignore` in the listing; main file, `uninstall.php`, `readme.txt`, `includes/`, `assets/` present.

- [ ] **Step 3: Full regression — tests + activation**

```bash
$PHP tests/run.php
$WP plugin deactivate migration-safety-net-for-divi && $WP plugin activate migration-safety-net-for-divi
```
Expected: all tests pass; activation clean, no notices.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "feat: readme.txt and .org build via git archive"
```

---

### Task 11: End-to-end validation on a real Divi 4 → 5 migration

**Files:** likely `includes/class-msn-normalizer.php` + new `tests/fixtures/*` pairs (every real-world noise pattern found becomes a fixture); possibly `MSN_Differ::LAYOUT_RX` additions.

**Interfaces:** no API changes — this task hardens the normalizer against reality and proves the spec's success criteria.

**⚠ Human checkpoint:** this task needs Dario to download **Divi 4.27** from his Elegant Themes account (login required) to `c:\wamp64\tmp\Divi-4.27.zip`. Ask, then continue.

- [ ] **Step 1: Build the Divi 4 install**

Same recipe as Task 4 with `--path=/c/wamp64/www/msn-d4 --dbname=msn_d4 --url=http://localhost/msn-d4`, then `$WP4` = wp-cli with that path. Install Divi 4.27 from the zip (`$WP4 theme install /c/wamp64/tmp/Divi-4.27.zip --activate`). Junction-link the plugin. Import demo content: use a Divi layout pack (Divi → Layouts in wp-admin, or `$WP4 post create` with real `[et_pb_*]` shortcode content copied from a layout JSON) — at least 5 pages with sections/rows/columns/blurbs/toggles, set one as the front page.

- [ ] **Step 2: Baseline noise check (success criterion #2 on real Divi)**

Snapshot the D4 site twice with NO changes between, compare via the admin UI.
Expected: green clean hero. Every non-clean page = a real-world noise pattern → capture the two HTML files from `uploads/msn-snapshots/`, reduce to a minimal fixture pair in `tests/fixtures/`, add the rule to `MSN_Normalizer`, `$PHP tests/run.php` green, re-verify. Iterate to zero. Commit each rule: `git commit -m "fix(normalizer): <pattern>"`.

- [ ] **Step 3: The real migration**

On the D4 install: snapshot ("pre-migration"), then upgrade the theme to Divi 5.2.1 (`robocopy` the Divi 5 theme from the nickeyperformance copy over `msn-d4`'s theme after `$WP4 theme activate` a twenty-seventeen fallback, then reactivate — or install as a second theme dir `Divi5` and switch). Run ET's migration wizard in wp-admin on all pages. Snapshot ("post-migration"). Compare.

- [ ] **Step 4: Validate against the spec's success criteria**

1. Open every changed page side by side (D4 HTML file vs live D5 page). Every *visible* layout difference must appear in the report. Misses = differ gaps — fix with fixtures.
2. Count noise hunks (reported changes with no visible/behavioral difference): must be **< 20% of total hunks**. Over budget = normalizer iteration, same fixture loop.
3. Report UI stays usable at desktop and ≤980px with the real (larger) hunk lists.

- [ ] **Step 5: Update project state + commit**

Flip the CLAUDE.md "Current state" checkboxes (v1 built ✅, validated ✅; next: .org submission). Record any discovered D5 noise patterns worth remembering in CLAUDE.md's ground-truth section.

```bash
git add -A && git commit -m "test: harden normalizer against real D4→D5 migration noise"
```

---

## Post-plan (not in this plan)

- .org SVN submission (needs Dario's wordpress.org account; submit early — review queue is weeks).
- Screenshots + banner assets for the .org listing.
- Pro add-on plugin (separate repo): CSS-risk audit, PDF export, scheduled re-checks, multisite.
