# wordpress.org submission crib

## ✅ LIVE 2026-08-27 — r3669102

https://wordpress.org/plugins/tumils-migration-safety-net-for-divi/ — **v1.0.1 live, r3676734, 2026-09-01** (trunk + tags/1.0.1 in one commit; assets/ untouched, unchanged since 1.0.0). Verified from the server, not from the commit output: `svn ls -R` on trunk / tags/1.0.0 / assets, `svn cat` on the committed headers (Version 1.0.0, Stable tag 1.0.0), the api.wordpress.org record, all 8 assets served from ps.w.org, and the public download zip md5-identical to `git archive HEAD` across all 13 files. Next release: bump the header Version + readme Stable tag, `git archive HEAD` over trunk, `svn cp trunk tags/x.y.z`, one commit.

## ✅ APPROVED 2026-08-26

Email "APPROVED tumils-migration-safety-net-for-divi/dariobimbi/25Aug26/T2 26Aug26/4.2". They reserved the requested slug themselves — `tumils-migration-safety-net-for-divi`, now permanent. Public URL https://wordpress.org/plugins/tumils-migration-safety-net-for-divi stays blank until the first SVN push. One pended round, one reply, approved: total 4 days from first submission. Do not reply on that thread again unless there's an issue.

**SVN client on this box:** there is none, and it needs no install — the portable Apache Subversion CLI zip at https://www.visualsvn.com/files/Apache-Subversion-1.14.5.zip extracts to `svn/bin/svn.exe` and runs unelevated. TortoiseSVN and SlikSVN are admin-only MSIs; Git for Windows ships no `svn` and no `git svn` (no SVN::Core Perl bindings).

## Review round 1 outcome (email 2026-08-24, "AUTOPREREVIEW ❗TRM … T1 25Aug26/4.2")

Pended, not rejected. Three findings, all addressed 2026-08-24:

1. **Name too generic.** "Migration Safety Net for Divi 5" leads with a generic descriptive phrase; the reviewer wants a distinctive identifier *first* (their own suggestion was "Dario Bimbi Migration Safety Net for Divi 5"). Renamed to **"Tumil's Migration Safety Net for Divi 5"**, new slug **`tumils-migration-safety-net-for-divi`** — the storefront brand carries the portfolio, and "Tumil" returns zero hits on wordpress.org. The "for Divi" pattern (trademark after "for", never leading) was already correct and stays.
2. **Prefix too short/common.** `msn_` / `MSN_` is 3 characters and reads as Microsoft Network. Everything is now `tumils_msn_` / `Tumils_MSN_` / `TUMILS_MSN_`: classes, constants, ajax + admin_post actions, nonces, script/style handles, the localized JS object (`tumilsMsnAdmin`), the `{prefix}tumils_msn_snapshots` table, the `tumils_msn_run_errors` option, the `uploads/tumils-msn-snapshots/` storage dir, the `tumils_msn_max_urls` filter, and the `tumils-msn-` CSS class prefix.
3. **Plugin URI 404.** `https://dariobimbi.com/migration-safety-net-for-divi/` was never published. The header line is simply gone (Plugin URI is optional) — add one back in a later version only when a real landing page exists.

**The reply must explicitly request the new slug** (`tumils-migration-safety-net-for-divi`); changing it in the code is not enough, and it can never be changed after approval. Reply on that email thread, keep it short, never resubmit.

Watch out on re-upload: the "Add your plugin" page may warn that the Text Domain doesn't match the slug — expected until they reserve the new slug on their side.

## At submission (the Add Your Plugin form)

1. Upload the freshly built `tumils-migration-safety-net-for-divi.zip` from the repo root (rebuild first if any commit landed since: see docs/backlog.md → Release build).
2. **THE SLUG TRAP:** the slug autogenerates from `Plugin Name:` — "Tumil's Migration Safety Net for Divi 5" → something like `tumils-migration-safety-net-for-divi-5`. That is wrong twice over: slugs may not contain version-looking numbers, and the text domain is `tumils-migration-safety-net-for-divi`. The submission page allows ONE slug change before review begins — use it immediately after upload. If the link isn't shown, reply on the review thread BEFORE review resumes. The display name keeps the "5"; only the slug drops it.
3. Whitelist plugins@wordpress.org. All correspondence happens by replying on the same email thread — never resubmit.
4. Queue expectation: initial response within ~1–2 weeks; full review "as long as it takes". One submission per account at a time.

## Honest answers to the form's checkboxes (verified 2026-08-22)

- **Plugin Check:** run via wp-cli against the extracted distribution zip (not the repo dir — the repo dir shows artifacts: hidden files, tests/, the zip itself). Residue accepted as of v1.0.0: `DirectDatabaseQuery`/`NoCaching` warnings on the custom-table queries (standard for custom-table plugins; table names are interpolated from `$wpdb->prefix` and values go through `$wpdb->prepare` — `%i` was removed in 1.0.1, see CLAUDE.md ground truth), plus a text-domain artifact if the check folder isn't named exactly `tumils-migration-safety-net-for-divi`. Re-verified clean on the renamed build 2026-08-24 (only the four DirectDatabaseQuery/NoCaching warnings, no errors).
- **Guideline compliance highlights:** GPLv2+; zero external requests (privacy paragraph in readme); no trialware/gating (Pro will be a separate add-on plugin, never in this codebase); no bundled libraries (vanilla JS); 5 readme tags; trademark pattern "… for Divi" (never leading), distinctive brand word first.

## After approval (SVN day)

SVN URL will be `https://plugins.svn.wordpress.org/tumils-migration-safety-net-for-divi`.

```bash
# svn.exe = the portable VisualSVN zip extracted to <scratchpad>/svn/bin (no install, no admin)
svn co https://plugins.svn.wordpress.org/tumils-migration-safety-net-for-divi msn-svn
cd msn-svn
# trunk = git archive HEAD, NOT the repo dir and NOT the root zip (which goes stale silently).
# .gitattributes export-ignore already drops docs/, tests/, CLAUDE.md, .git*.
(cd <repo> && git archive --format=tar HEAD) | tar -x -C trunk
# listing assets live OUTSIDE trunk, in assets/ (sibling of trunk/ and tags/)
mkdir -p assets
cp <repo>/docs/org-assets/screenshot-*.png assets/
cp <repo>/docs/org-assets/banner-*.png <repo>/docs/org-assets/icon-1*.png <repo>/docs/org-assets/icon-256x256.png assets/
svn add --force trunk assets
svn cp trunk tags/1.0.0        # Stable tag in readme.txt is 1.0.0 — the tag must exist
svn ci -m "v1.0.0 — initial release" --username dariobimbi
```

### Subsequent releases (exercised for 1.0.1, 2026-09-01)

Shorter than the first: **assets/ is already in SVN and stays untouched** unless the artwork itself
changed, so an update is trunk + one new tag. Bump the header Version and readme Stable tag, add the
changelog entry, and commit to git FIRST — trunk is filled from `git archive HEAD`, so an uncommitted
fix ships the old code under the new version number.

```bash
SVN=/c/wamp64/tmp/svn/bin/svn.exe     # portable zip from CLAUDE.md, extracted to c:/wamp64/tmp/svn (bin/ is at its root)
$SVN co https://plugins.svn.wordpress.org/tumils-migration-safety-net-for-divi msn-svn
cd msn-svn
(cd <repo> && git archive --format=tar -o /c/wamp64/tmp/msn-x.y.z.tar HEAD)
(cd trunk && tar -xf /c/wamp64/tmp/msn-x.y.z.tar)   # overlays; does NOT delete removed files
$SVN cp trunk tags/x.y.z
$SVN status        # expect only M on trunk + 'A +' on the tag. Any '?' line = svn add it first
$SVN ci -m "vx.y.z — ..." --username dariobimbi      # goes live instantly
```

**Trunk and the tag go in ONE commit.** `Stable tag: x.y.z` in trunk with no `tags/x.y.z` beside it
points every user's updater at a directory that does not exist.

Because the overlay never deletes, a release that *removes* a shipped file needs an explicit
`svn rm` — `svn status` showing a stale file still versioned is the only warning you get.

**Also tag the release in git** (`git tag -a vx.y.z`). The repo had no tags at all through 1.0.0, so
what shipped was recoverable only from SVN.

Verify from the server afterwards, never from the commit output — `svn ls tags/`, `svn cat` the
committed header and Stable tag, the api.wordpress.org record, and md5 the public download against
`git archive vx.y.z` file by file.

Rules that bite: pushing to SVN makes the plugin LIVE instantly (don't push until ready); no zips/compressed files in SVN; SVN is a release repo (no rapid-fire commits — GitHub is the dev home); every user-visible release = version bump in BOTH the plugin header and readme Stable tag + a tags/x.y.z copy; readme-only cosmetic changes don't need a version bump but DO need trunk+tag updates.

## Listing assets — all done (2026-08-22)

- Banners + icons: docs/org-assets/banner-{1544x500,772x250}.png, icon-{256x256,128x128}.png (+ icon-alt-256.png spare). Original artwork traced from the Tumil's Plugins logo — no ET/Divi logos (guideline: no official logos in banners/icons). Regenerable from docs/org-assets/src/.
- Screenshots done: docs/org-assets/screenshot-{1..4}.png (1280×800, captured from the real D4→D5 migration report).
- ✅ Artwork and screenshots refreshed 2026-08-24 for the rename: banner headline now reads "Tumil's Migration Safety Net" (the longer headline forced a rebalance — narrower mark column, 320px mark, 77px headline, measured in-browser), and all four screenshots were re-shot from `msn-d4` with the new page title, no dev plugins in the Tools menu, and the corrected clean-hero panel.

## Post-live

- Subscribe to the support forum (button on the plugin page sidebar) — review replies + support responsiveness feed search ranking.
- Search indexing takes 6–14 days after first SVN push; no usage data at first is normal.
- "Tested up to" maintenance: bump the readme value with each WP release you verify against (readme-only change, no version bump needed).
