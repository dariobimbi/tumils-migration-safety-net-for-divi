# Backlog — deferred with rationale (triaged at v1 merge, 2026-08-22)

Everything here was found during v1's review process, judged shippable, and deferred deliberately. Each line notes why it can wait. Full audit trail lived in the SDD ledger (deleted at merge; git history + this file are the record).

## v1.1 candidates (user-visible value)

- **Surface capture `errors` per report row** — an `only-before`/`only-after` row currently gets a generic warning notice when the run recorded failures; annotating the specific rows ("capture failed", not "Missing after") is the full fix. The runs screen badge + report notice shipped in v1.
- **"View report" link on the runs list** — today the only route to a report is the post-compare redirect; the cached compare is unreachable from the UI (re-running is cheap since hash-equal pages skip).
- **Renamed-wrapper localization** — when a wrapper's id regenerates *and* deep content changes, the report correctly flags structural removed+added, but the 300-char snippets can hide the actual edited text ("flagged but not localizable" — measured 7.8% of a text-edit fuzz corpus). Options: larger snippets, or a full-page diff view. Fundamental ambiguity; conservative over-reporting was the ruled choice.
- **Surface the fetch's final URL** — a redirected page (3 redirects allowed) stores the target's body under the original URL. Show the final URL in the report.
- **Schema upgrade path** — `MSN_Store::install()` runs only on activation; store `MSN_VERSION` in an option and re-run `dbDelta()` on mismatch before any 0.2.0 schema change.
- **JS i18n** — `assets/admin.js` has two hardcoded English strings ("server error", the retry hint — which also reads oddly for capture failures); move them into the `wp_localize_script` payload.
- **Report table pagination** — all rows render at once; fine at the 200-URL cap, revisit if the cap rises.
- **A11y** — progress bar lacks `role="progressbar"`/`aria-live`; the label input has a placeholder but no `<label>`.

## Hardening (do when touching the file anyway)

- `MSN_Store::dir()` creates directories on read paths (`get_html`, `delete_run`, report views) — add a `$create = false` mode for reads.
- `delete_run()` leaves orphan `compare-*` dirs for pairs involving the deleted run (uninstall cleans them; stale JSON is never read).
- `save_snapshot()` doesn't check `$wpdb->insert()`'s charset-level truncation beyond the 64-char `mb_substr` guard.
- `clean_url_delims()` doesn't strip a dangling `?` immediately before a `#fragment` (`/page?#section`); pre-existing, only matters if an asset URL carries a fragment.
- Comma inside a `ver=` value on a *srcset candidate* mis-splits the entry list (WP version strings never contain commas).
- `compare_batch()` doesn't check `wp_json_encode`/`file_put_contents` success — a write failure leaves that page "Not compared yet" with no diagnostic (self-heals on re-compare).
- `get_post_types( array( 'has_archive' => true ) )` in the enumerator isn't filtered to `public`, so a private CPT archive can be enumerated and then counted as a fetch error.
- PHPCS: direct-DB-query warnings on `MSN_Store` (interpolated table name is `$wpdb->prefix`-derived) — add annotated `phpcs:ignore` lines to keep Plugin Check output clean.
- PHPCS: `delete_run()`'s and `uninstall.php`'s `rmdir()` calls trip `AlternativeFunctions.file_system_operations_rmdir` — annotated `phpcs:ignore`; WP_Filesystem migration is the "proper" fix, deliberately skipped for removing the plugin's own empty dirs. (The `unlink()` half of this is resolved — both files now use `wp_delete_file()`.)

## Known limitations (documented, not bugs — do not "fix" without re-reading the rationale)

- **CSS-only visual changes are invisible** (e.g. a module background color living purely in generated `<style>`): inherent to the no-headless-browser constraint. Also why the `et_pb_with_background` class strip is correct — the class's meaning lives in CSS we can't see, and treating it as signal would flag every background module on every migration.
- **`sslverify => false` on loopback** — WP-core-style, loopback-to-self.
- **Snapshot `.html` files rely on `.htaccess` + per-dir `index.php`** — nginx/LiteSpeed ignore `.htaccess`, but the content is exactly what an anonymous visitor already sees, and run ids carry 48 bits of entropy.
- **libxml caps HTML nesting at 256 levels** and silently truncates below — a change deeper than that would hash clean. Real Divi pages are ~20–30 levels.
- **Id-less full content swaps read as text hunks** (loose tier pairs id-less same-tag siblings positionally) — pre-v1 behavior, accepted; the full before/after text is still shown.
- **Gap pairing is positional for 3+ same-signature leftovers** with simultaneous non-adjacent changes — revisit only on real-world evidence.

## Release build

```bash
git archive --format=zip --prefix=tumils-migration-safety-net-for-divi/ -o tumils-migration-safety-net-for-divi.zip HEAD
```

Rebuild after every change — the zip at the repo root is untracked and goes stale silently.
