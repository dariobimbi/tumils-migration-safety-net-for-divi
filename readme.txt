=== Tumil's Migration Safety Net for Divi 5 ===
Contributors: dariobimbi
Tags: divi, migration, diff, snapshot, regression
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify your Divi 4 → Divi 5 migration: snapshot every page before, re-scan after, and see exactly what changed.

== Description ==

Divi 5's built-in migrator predicts what *will* convert. Tumil's Migration Safety Net proves what *did*.

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

Published pages, posts, and custom post types (up to 200 by default — filterable via `tumils_msn_max_urls`), plus your front page and a sample of each archive.

== Screenshots ==

1. The clean bill of health — every page verified identical after migrating.
2. The report: changed pages ranked by severity, clean pages confirmed below.
3. Drill-down — every change on a page, classified structural or cosmetic, with before/after.
4. Snapshots screen — capture a labeled snapshot before and after migrating, then compare.

== Changelog ==

= 1.0.1 =
* Internal: snapshot index queries no longer use the %i table placeholder, for compatibility with coding-standards tooling. No change to behaviour.

= 1.0.0 =
* Initial release: snapshot engine, DOM diff engine with severity classification, comparison report.
