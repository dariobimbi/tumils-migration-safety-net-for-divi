# Migration Safety Net for Divi 5 — Design Spec

**Date:** 2026-08-22. **Status:** Approved by Dario (chat review, nickeyperformance.com session).
**Product owner:** Dario Bimbi — personal venture, sold under his own name. Clicks Web Design (employer) is not involved.

## The problem

Divi 5 (stable 2026-02-26) ships a built-in Migrator that predicts what will convert. **Nothing tells a site owner what actually changed after converting.** Documented post-migration pain with no product serving it: custom CSS behaving differently (layout engine moved float → flex/grid even though most `et_pb_*` classes survived), responsive settings silently not transferring, compat-mode fallbacks slipping in. ~1M Divi installs must cross this bridge over the next ~2–3 years; agencies bill for exactly this verification and currently do it by eyeballing.

Competitive check (2026-08-22): ET's migrator = pre-flight prediction only. Respira "Migration Copilot" = AI checklists/workflow, no breakage detection. No CSS-selector auditor or before/after diff tool exists in the ecosystem. Full market analysis: [../market-research.md](../market-research.md).

## The product

WordPress plugin. Workflow: **Snapshot → (user runs ET's migrator) → Re-scan → Diff report.**

Answers: *"did my migration silently break anything, and where?"* The empty diff — "clean ✓" — is a first-class outcome: proof, not just problems.

### 1. Snapshot engine

- Enumerate public URLs: published pages, posts, CPT singles, front page, one sample per archive type. Capped and filterable (agency sites can be huge).
- Fetch each via loopback `wp_remote_get` of the permalink. **No headless browser** — must run on cheap shared hosting (Bluehost-class).
- Store raw rendered HTML in `uploads/msn-snapshots/{run-id}/{hash}.html`.
- Index runs in one custom table: run id, URL, content hash, timestamp, label ("pre-migration", "post-migration", arbitrary).

### 2. Diff engine (the core IP)

- PHP `DOMDocument`. Parse both captures of a URL.
- **Normalize first**: strip nonces, cache-buster query strings, timestamps, random/generated ids, WP emoji/script noise, comment nodes known to vary. The normalizer whitelist is where the accuracy lives — expect iteration.
- Diff the normalized trees: elements added/removed, class-set changes per element, inline-style changes, text changes.
- Classify per hunk: **structural** (layout-relevant: section/row/column wrappers, display-affecting classes) vs **cosmetic** (text/attr tweaks) vs **noise** (suppressed).
- Output per page: severity score + hunk list. Site-level: ranked worklist.
- Non-trivial logic → fixture-based tests are required (small PHPUnit-free test runner is fine; one `tests/run.php` with real HTML fixtures beats a framework).

### 3. Report UI

- Admin screen (native `WP_List_Table`, plain PHP + vanilla JS, **no build step** — house philosophy).
- Ranked list of changed pages → drill-down per page: each diff hunk with context, link to view page and open in builder.
- Empty state = the big green "migration verified clean" moment.

## Free / Pro split (wordpress.org compliant)

.org rules: GPL v2+; the hosted plugin may contain **no license gates, trials, or crippled features** — free must be genuinely whole. Pro is a *separate add-on plugin* sold outside .org.

- **Free (.org):** full snapshot + diff + report. Complete and honest — it's also the marketing funnel ("divi 5 migration" search traffic).
- **Pro (add-on, Freemius checkout + listed on Divi Marketplace at 70% cut):** CSS-risk heuristics audit (rules targeting float-era column structures, `:nth-child` column hacks, gutter classes), client-ready PDF/HTML export, scheduled re-checks, multisite support. Agency-friendly unlimited-site license, ~$59–79.

## Naming (trademark constraint)

.org slugs must not *lead* with "Divi" (Elegant Themes trademark). Pattern: "… for Divi".

- Display name: **Migration Safety Net for Divi 5**
- Slug: `migration-safety-net-for-divi`
- PHP prefix: `msn_` / `MSN_` (constants `MSN_VERSION` etc.), text domain `migration-safety-net-for-divi`.

## v1 scope cuts (explicit)

- ❌ CSS audit — Pro/v1.1, not v1.
- ❌ Screenshots/pixel diffs — DOM diff only (no browser engine on shared hosting).
- ❌ Multisite — Pro.
- ❌ Scheduled/automatic re-checks — Pro.
- ✅ v1 = snapshot, diff, report, readme.txt, submit to .org review (review queue takes weeks — submit early, iterate in SVN after approval).

## Test/dev environment

- Repo = the plugin folder only. Test WP installs are separate and disposable.
- **Two local Wamp installs**: one fresh WP + **Divi 4.27** + demo/layout-pack content (the "before"), one with **Divi 5.2.x** (Dario owns an ET license; Divi 5.2.1 already present in `c:\wamp64\www\nickeyperformance.com\wp-content\themes\Divi`). Real verification = snapshot the D4 site, run ET's migrator, re-scan, confirm the report catches real changes and suppresses noise.
- Ground truth confirmed 2026-08-22 from real data: D4 content = `[et_pb_*]` shortcodes in `post_content`; D5 = Gutenberg-style `<!-- wp:divi/* {json} -->` blocks with `builderVersion` attr.

## Risks

1. **Noise-vs-signal in the diff** is the make-or-break. A report full of false positives is worse than no report. Mitigation: normalizer iterated against real D4→D5 migrations before launch; classify conservatively (unknown → cosmetic, not structural).
2. **ET could add "verify" to their migrator.** Unlikely (their incentive is "migration is safe", and they don't build agency-ops tooling — but Divi Booster/Switch prove they absorb utilities). Mitigation: the diff engine generalizes beyond migration (theme updates, plugin updates, any before/after) — that's the long-term product if the migration wave ends.
3. **Time-boxed market** (~2–3 yr). Accepted knowingly — see market research. Target: $300–800/mo. Top new-extension benchmark: ~$150/mo marketplace net in year one, plus direct sales.

## Success criteria for v1

- On a real D4 demo site migrated with ET's tool, the report surfaces every visible layout change (validated by hand) with < 20% noise hunks.
- On the same site with *no* changes (re-scan without migrating), the report is clean — zero hunks.
- Approved and live on wordpress.org.
