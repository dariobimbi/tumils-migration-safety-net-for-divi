# Divi Marketplace submission crib

Seller account: **Tumil's Plugins** (approved 2026-08-24; dashboard shows $0 earned / 0 sales).
Product type: **Divi Extension** — ET's own definition covers us: *"any WordPress plugin built
specifically to enhance Divi."*

## ET's hard requirements, and where we stand (2026-08-24)

| ET requirement (verbatim intent) | Status |
| --- | --- |
| Zip is an installable WP plugin as-is, not nested in a wrapper folder | ✅ `git archive` puts the plugin folder at the zip root — that IS the installable layout. Do not add a wrapper. |
| GPLv2, plus a `license.txt` "that follows a specific format" listing credits/sources for all third-party files | ✅ `license.txt` in the repo root, built from ET's own template (downloaded from their licensing page) — their four sections in order, GPLv2 body byte-identical, both credits sections filled with an explicit "None". |
| No custom update system, no licensing system | ✅ Never existed. ET serves updates via the ET API key. |
| No additional requirements beyond Divi + WordPress | ✅ No composer, no npm, no CLI, no other plugin. |
| Photos/graphics created by you, or CC0 / CC BY 4.0 (Unsplash, Pexels, Pixabay are banned) | ✅ All artwork original — the mark is traced from Dario's own logo. No stock photography anywhere. |
| Prefix everything | ✅ `tumils_msn_` / `Tumils_MSN_` / `TUMILS_MSN_` / `tumils-msn-`. |
| No inline styles, minimal inline JS, proper enqueueing | ✅ One registered style + one registered script, both versioned and scoped to the plugin's own admin page. |
| Nonces, capability checks, sanitize input, escape output | ✅ Every handler checks a nonce first, then `manage_options`; superglobals go through `sanitize_key`/`absint`/`sanitize_text_field`; output escaped at the point of echo. |
| PHP 7.4 → latest, no warnings/errors under `WP_DEBUG` | ✅ Runs on 7.4 syntax; verified on PHP 8.4. |
| Translation ready | ✅ Every user-visible string wrapped, text domain `tumils-migration-safety-net-for-divi`. |
| No customer tracking, no hidden functions | ✅ Zero external requests. The only outbound call is a loopback `wp_remote_get` to the site's own URLs. |

## Field-by-field (the "Submit A New Product" form)

**Category** → Extensions · **Extension Type** → the non-module/utility option (this is not a Divi module).

**Upload Product ZIP File** → `tumils-migration-safety-net-for-divi.zip` at the repo root.
Rebuild it first, and only after `license.txt` is committed:
```bash
git archive --format=zip --prefix=tumils-migration-safety-net-for-divi/ \
  -o tumils-migration-safety-net-for-divi.zip HEAD
```
Then confirm `license.txt` is inside — ET rejects products without it.

**Upload Supplementary Product Files** → none. Everything ships inside the plugin.

**Product Name** → `Tumil's Migration Safety Net`

**Rejected 2026-08-27 (first submission).** ET's only complaint was the listing text: *"Please remove
'Divi 5' from the Product Tags and Product Title. We can't allow Product Version numbers in the
Product Tags or Product Title."* No code, guideline or asset finding — fix the two fields and it
auto-resubmits. Note this collides with what wordpress.org demanded (brand word first, "Divi" only
after "for"): the .org listing keeps its name, the Marketplace one drops the trademark entirely.
Everything on the Marketplace is for Divi anyway, so "for Divi" earns nothing in the title.

**Type** → Commercial · **Product Price** → `19` · **Product Version** → `1.0.1`

**Second rejection, 2026-09-01 — PHPCS, two findings, both fixed in 1.0.1.** Their sniffer rejected
the `%i` table placeholder in `Store::get_runs()` and `Store::get_run_pages()`. `%i` is valid
`$wpdb->prepare()` syntax as of WP 6.2 and current WPCS accepts it silently — **the Marketplace
sniffer runs WPCS 2.x**, which does not know it. Reproduced and fixed under both rulesets; see the
WPCS-version note in CLAUDE.md's ground truth. Upload the 1.0.1 zip with the resubmission — the zip
ET currently holds is the 1.0.0 build and still contains the finding.

**Product Tags** → `migration, visual regression, diff, snapshot` (no version numbers — see above)

**Product Demo URL** → `https://tumils.com/migration-safety-net-for-divi/` (moved off
`plugins.dariobimbi.com` onto the brand domain, 2026-08-28; both resolve 200 — the dariobimbi copy
is the fallback, not the canonical).

**This field is required in practice, and no ET guideline page says so.** The Extension Submission
Guidelines, Developer Guidelines, Design Guidelines and How It Works pages never mention a demo —
but the form input carries `aria-required="true"` and their JS flags it `is-danger` and refuses to
submit. Do not trust the documentation on this one.

The convention among existing extensions is a product page on the seller's own domain, not a
sandbox login — e.g. Divi Background Plus points at `divi5.madebysuperfly.com/background-plus/`,
which is just a Divi page showing the product with an H1, a feature list and demos of each feature.

Ours is admin-only, so the report screens *are* the demo. `docs/landing/` holds a self-contained
page for it: hero, four-step workflow, a keyboard-navigable tabbed viewer of the four real report
screenshots, why the diff is trustworthy, the honest limits, and the requirements table. No build
step, no framework, one HTML file plus six images (391 KB total).

**Lives on its own subdomain: `plugins.dariobimbi.com`** (decided 2026-08-24). Room for the next
plugin page beside it, and it reads as a product site rather than a page inside a personal portfolio.

**Why the subdomain needs care.** The theme sends `Strict-Transport-Security: max-age=31536000;
includeSubDomains` from `inc/security-headers.php`, and that policy is sticky in every browser that
has visited `dariobimbi.com` in the past year. A subdomain without a valid certificate is therefore
not "insecure but reachable" — it is a hard failure with no click-through. **Do not put the URL in
the ET form until https resolves.** DNS is at Bluehost (`ns1/ns2.bluehost.com`), so the cPanel
subdomain creation is authoritative; no Cloudflare dashboard is involved even though Cloudflare
fronts the site (its "Just a moment..." challenge is Bluehost-managed and not under our control).

**Setup, once:**

1. cPanel → Domains → **Create A New Domain** → `plugins.dariobimbi.com`.
2. **Leave the prefilled Document Root alone** and leave *Share document root* unchecked. This
   cPanel prefills the folder at the home directory, not inside `public_html`, giving
   `/home2/<cpanel-user>/plugins.dariobimbi.com` — which is what we want. Anything under `public_html`
   would inherit the WordPress `.htaccess` (a 404 would render the WP theme) and the same files
   would answer on two hostnames, splitting the canonical.
3. Upload `plugins-subdomain.zip` into that folder and **Extract**. It carries an `.htaccess` for
   the subdomain root (`Options -Indexes` plus a 302 from `/` to the page, so a trimmed URL is not
   a directory listing) and the page itself under `migration-safety-net-for-divi/`.
4. Wait for AutoSSL, then confirm `https://plugins.dariobimbi.com/migration-safety-net-for-divi/`
   loads before using the URL anywhere.
5. Delete the old copy at `public_html/migration-safety-net-for-divi/` — the canonical now points at
   the subdomain, and two live copies of one page is the thing worth avoiding.

Build the zip with the PHP ZipArchive class, **not** `Compress-Archive`: PowerShell 5.1 writes
`folder\file.png` backslash entry names, which the cPanel extractor turns into files literally named
that, in the wrong place.

No WordPress involvement at all — WP only rewrites a URL when no real file or directory matches it,
so a real directory serves straight through, with no theme edit, no `theme.json` entry and no theme
version bump.

Verified locally before shipping: fonts load, all four screenshots load in all four tabs, tab
switching and arrow-key navigation work, and there is no horizontal scroll at 1280px or 390px. Then proved inside a real WordPress install (the local dariobimbi.com copy, same
generated `.htaccess` block as prod): the directory and its PNGs return 200 at their exact byte
sizes with no WP markup, because `RewriteCond %{REQUEST_FILENAME} !-d` exempts real directories.

**Product Small Description** (117 chars):
> Snapshot every page before you migrate to Divi 5, re-scan after, and see exactly what changed — or prove nothing did.

**Product Description**:
> Divi 5's built-in migrator predicts what *will* convert. This proves what *did*.
>
> **How it works**
> 1. **Snapshot** — capture how every published page actually renders, before you migrate.
> 2. **Migrate** — run Divi's migrator, update the theme, change anything you like.
> 3. **Re-scan** — take a second snapshot.
> 4. **Compare** — a noise-filtered DOM diff of every page: structure, classes, attributes and text, ranked by severity, with the changed markup shown side by side.
>
> A clean result is a first-class outcome. **"Migration verified clean"** is your proof that nothing silently broke — the answer you actually want, stated plainly instead of left to hope.
>
> **Why the diff is trustworthy**
> Divi 5 rewrites a great deal of markup that changes nothing visually: marker classes it adds everywhere, gutter and alignment classes it drops, plain spans it unwraps, wrapper divs it consolidates. Every one of those patterns is normalised away before comparison — verified against a real Divi 4.27 → 5.2 migration and locked down by 102 automated tests. False positives are what make a verification tool worthless, so that is where the work went.
>
> **Built for real hosting**
> Page capture uses WordPress's own loopback HTTP request. No headless browser, no external service, no API key, no account. Nothing about your site leaves your server.
>
> **Honest about its limits**
> This measures rendered HTML, not painted pixels. A change that exists only in generated CSS — a background colour in a `<style>` block, say — is invisible to a markup diff, and the plugin says so rather than implying coverage it does not have.
>
> Requires WordPress 6.2+ and PHP 7.4+. Works alongside Divi 4 and Divi 5.

**Product Documentation**:
> **Install** — upload and activate. The tool lives at **Tools → Migration Safety Net**.
>
> **1. Before you migrate.** Label the run `pre-migration` and click *Take snapshot*. Every published page, post and custom post type is captured (up to 200 URLs, adjustable with the `tumils_msn_max_urls` filter), plus the front page and a sample of each archive.
>
> **2. Migrate.** Run Divi's migrator, or whatever change you want to verify.
>
> **3. After.** Take a second snapshot, labelled `post-migration`.
>
> **4. Compare.** Pick the two runs and click *Compare*. Each page gets a status:
> - **Clean** — renders identically.
> - **Changed** — click through for the exact hunks, split into structural and cosmetic.
> - **Cannot verify** — the stored snapshot file is missing or unreadable, so no comparison was made. Never confused with clean.
> - **Missing after / New page** — the page exists on only one side.
>
> **Storage** — snapshots live in `wp-content/uploads/tumils-msn-snapshots/`, indexed in one custom table. Delete a run from the UI at any time; uninstalling the plugin removes the table, its option and every snapshot file.
>
> **Filter** — `tumils_msn_max_urls` (int, default 200) caps how many URLs a run captures.
>
> **Support** — tumilssupport@gmail.com

**Upload Product Featured Image** → `docs/org-assets/featured-1426x796.jpg` — **JPEG, not PNG**:
the Design Guidelines specify JPEG for all three product images. Colored background required (no
white, no transparency); the bone `#EFE8DA` ground satisfies that, though it is the lightest thing
we could get away with against ET's white chrome — if the listing looks washed out in the grid,
re-render `src/featured.html` on the pine `#26313A` ground. Their other rule: text must not occupy
more than 50% of the image's visual footprint (ours is ~⅓).

**Upload Square Product Logo** → `docs/org-assets/product-logo-500x500.jpg` (JPEG, 500×500).
Deliberately distinct from `tumils-plugins-logo.png`: ET treats the *company* logo (submitted with
the seller application) and the *product* square image as separate assets, and explicitly says they
should differ when you sell more than one product.

**Upload Product Screenshots & Videos** → `docs/org-assets/screenshot-1..4.png` (1280×800, re-shot
2026-08-24 from the real Divi 4 → 5 migration report). Within ET's 2560px cap, and shown at actual
size as they ask.

**Upload Product SVG Icon** → `docs/org-assets/icon-16.svg`, **not** `icon.svg`. ET's spec for this
field is narrow and easy to fail: **16×16, no bitmap data, `#2b87da` only**, 2px strokes for line
work. `icon.svg` (256×256, full brand palette) would violate all three, so it stays the general-use
vector and this is the marketplace one. The badge — double ring, compass diamonds, the 1:1 traced
plug — is illegible at 16px, so `icon-16.svg` reduces the mark to the two features that survive: the
plug and the lightning-rune cord. The cord is a *filled* bolt, because a 2px stroked zigzag over
segments that short merges into a solid blob (proofed at 16/32/64/128px and on dark chrome via
`src/icon-16-proof.html`). Strictly, the guidelines scope this field to *module* extensions and ours
adds no Divi module — but a compliant file costs nothing and a non-compliant one invites a rejection.

## Decisions made (2026-08-24)

**Price: $19 one-time.** Comparable Divi utility extensions cluster in the $10–$40 band. This one is
narrow but high-pain, and its window is the Divi 5 migration wave happening right now. $19 is cheap
enough to be an impulse buy against the risk of a silently broken site, dear enough not to read as
abandonware. ET takes 30%, so it nets $13.30 a sale.

**Version: 1.0.0.** Bumped from 0.1.0 in the plugin header, the `TUMILS_MSN_VERSION` constant and the
readme `Stable tag`/changelog. 0.1.0 reads as beta to a buyer, and the same build now goes to both
storefronts. The .org submission is still in review and is simply re-read at whatever version is
uploaded, so there is no cost to the change there.

## The thing that bites

The same product being **free on wordpress.org** and **paid here** is legitimate (both are GPLv2,
and ET's guidelines anticipate a separately-submitted marketplace build) but it is a review-magnet
once the .org listing goes live, and buyers do notice. The original plan was for the marketplace to
carry a **Pro** edition instead.

**Pro does not exist** (checked 2026-08-25: no code, no repo, no stub — only a specification). It is
defined in [the design spec](superpowers/specs/2026-08-22-migration-safety-net-design.md) under
"Free / Pro split": a *separate add-on plugin* (never in this codebase — that is what keeps the .org
listing compliant, and .org has already been told so in [docs/org-submission.md](org-submission.md)),
sold via Freemius at roughly **$59–79** for an agency-friendly unlimited-site licence, carrying four
features deliberately cut from v1:

- CSS-risk heuristics audit — rules targeting float-era column structures, `:nth-child` column
  hacks, gutter classes. This is the substantial one, and it is the feature that attacks the free
  version's known blind spot, since a purely CSS-driven regression is invisible to a markup diff.
- Client-ready PDF / HTML export
- Scheduled re-checks
- Multisite support

So the real choice is not "free build or Pro build" — it is *ship the free build at $19 now* versus
*park the Marketplace listing for an unbounded build*.

**Recommendation on file (Claude, 2026-08-25): ship the free build now.** The Divi 5 migration wave
is the entire window and it does not wait for Pro; ET lets you revise a product, so $19 is not a
one-way door; the .org listing is still in review, so the awkward overlap is not even live yet; and
wordpress.org's one-submission-at-a-time rule means the .org lane is blocked anyway — the
Marketplace is the only lane that can move. Pro then becomes v2 of this listing rather than a gate
on v1. Not decided — Dario's call.
