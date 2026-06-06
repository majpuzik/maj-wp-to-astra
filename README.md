# maj-wp-to-astra

> Take a WordPress site off **Elementor** and onto **native Astra + Gutenberg** — then
> **verify** the result from every angle (content, function, assets, plugins, pixels),
> not just by eye. Conversion tools + a multi-dimension verification gate in one repo.

![Real-world coverage](https://img.shields.io/badge/real--world_coverage-60%25-yellowgreen)
![Tested on](https://img.shields.io/badge/tested-203_live_sites-blue)
![Engine](https://img.shields.io/badge/deterministic-no_AI-brightgreen)

> Real-world coverage measured on **203 live Elementor sites** (parallel survey): of the
> 97 that responded, `elementor-ex` carries the main content on **59 (60%)** — the rest
> get guided `MANUAL-TODO.md` items, not blanks.

**A toolkit to remove Elementor from a WordPress site and replace it with native
WordPress content** (Gutenberg `wp:html` / `wp:shortcode` blocks) — **text-exact and
fully deterministic, no AI**. The *content* comes over 1:1; **visual** parity is not
automatic and must be verified — Elementor's container layout (section/column
`max-width` + padding) is CSS the converter can't see, and losing it shifts boxed
content to full-width. Run `tools/visual-diff.js` and read **“Container layout is
lost”** below. (Earlier this README said “visually 1:1” — that was optimistic: a real
conversion regressed the homepage hero to full-width until one CSS rule restored the
lost `max-width:1140px`.)

It extracts the content out of Elementor's `_elementor_data`, writes it back into the
native `post_content`, deletes the `_elementor_*` meta, and removes the plugin. The
theme then renders the page exactly as before — just without Elementor.

---

## Quick start — one command, two choices

`tools/elementor-ex.php` is the single entry point. It explains itself, then:

```bash
wp eval-file tools/elementor-ex.php          # explanation + the two choices
wp eval-file tools/elementor-ex.php test     # (1) ANALYZE ONLY — per-page report of what
                                             #     converts automatically vs needs a hand.
                                             #     Changes nothing.
wp eval-file tools/elementor-ex.php convert  # (2) CONVERT — write native blocks, remove
                                             #     Elementor, and write a MANUAL-TODO.md
                                             #     guide for every item to finish by hand.
```

Every widget is either converted to a native Gutenberg block (deterministically) or
left as a labelled placeholder carrying its data — **nothing is silently lost**. The
`convert` step writes `uploads/elementor-ex-MANUAL-TODO.md`, e.g.:

> ## #10 — Demo
> - ⚠️ **form** — rebuild as Contact Form 7 with fields: Jméno, Email.
> - ❌ **accordion** — no native equivalent — rebuild by hand. settings: {…}

Always work on a copy, run `test` first, and verify with `tools/visual-diff.js` after.
The individual tools below (`convert-all`, `convert-plus`, `freeze-all`, …) still exist
for finer control.

📖 **[PLAYBOOK.md](PLAYBOOK.md)** — the full tabular how-to: decide if a site is a fit →
clean vs freeze (measured 95.4% vs 98.4%) → the 8-step workflow → a gotchas table where
every row is something that actually bit during a real conversion.

---

## ⚠️ Read this first — the limitation

**This tool only works well for one specific (but very common) case: sites that use
Elementor as a thin wrapper around hand-written HTML, shortcodes, or rich text.**

It does **not** convert Elementor's *visual widgets* (Heading, Image Box, Accordion,
Tabs, Icon List, Testimonials, Posts grid, Forms, etc.) into equivalent Gutenberg
blocks. Those widgets have no source HTML — Elementor generates their markup at render
time from widget settings. Re-creating that as native blocks is a per-widget mapping
problem and is exactly why **no reliable automatic Elementor→Gutenberg converter
exists** (every migration guide says the same: *"there is no automated converter — it's
actually rebuilding the site"*).

### Decide in 30 seconds — run the analyzer

```bash
wp eval-file tools/analyze-elementor.php
```

It prints every Elementor page and a histogram of the widget *types* used site-wide.

| Widget types you see | Verdict |
|---|---|
| Mostly `html`, `shortcode`, `text-editor` | ✅ **Great fit.** Content is already real code — extraction is mechanical and exact. |
| A few visual widgets mixed in | ⚠️ Partial. Those widgets will come over as empty/placeholder; convert them by hand. |
| Mostly visual widgets (`heading`, `image-box`, `accordion`, …) | ❌ **Not a fit.** This is a manual rebuild — no tool (this one included) does it reliably. |

Real example this was built on: 17 Elementor pages, **only 3 widget types** —
`html` ×12, `shortcode` ×4, `text-editor` ×1. No Elementor Pro, no Elementor
header/footer (the theme did those), CSS independent of Elementor. → mechanical 1:1
conversion, 97–99% text parity against the live site, **zero** Elementor left.

### `convert-plus.php` — also maps the *simple* visual widgets (tested)

`convert-all.php` only carries html/shortcode/text. **`convert-plus.php`** additionally
maps the simple visual widgets to native blocks deterministically, and for the hard
ones emits a placeholder + the extracted data + a `<!-- TODO -->` so **nothing is
silently dropped**. Run `wp eval-file tools/convert-plus.php report` for a per-page
classification first.

| Elementor widget | → | Result |
|---|---|---|
| `html` / `shortcode` / `text-editor` | ✅ | `wp:html` / `wp:shortcode` |
| `heading` | ✅ | `wp:heading` (text + level) |
| `image` | ✅ | `wp:image` (url, alt) |
| `button` | ✅ | `wp:button` (text, link) |
| `icon-list` | ✅ | `wp:list` |
| `divider` / `spacer` | ✅ | `wp:separator` / `wp:spacer` |
| `video` | ✅ | `wp:embed` |
| `image-box` / `icon-box` | ⚠️ | image+heading+text + `TODO check` |
| `icon` | ⚠️ | `<span class="…">` + `TODO check icon font` |
| `nav-menu` | ⚠️ | `TODO` carrying the menu slug → wire to `wp:navigation` |
| `posts` / `loop-grid` / `portfolio` | ⚠️ | `TODO` carrying `post_type` + `per_page` → `wp:query` |
| `form` | ⚠️ | `TODO` listing the field labels → rebuild as CF7 |
| `accordion` / `tabs` / Pro / unknown | ❌ | `<!-- TODO MANUAL: type — settings… -->` placeholder |

### Real-world coverage — measured on 203 live Elementor sites

Surveyed 203 real public Elementor sites (widget types read from rendered HTML),
distributed in parallel across three nodes (a GPU box at low concurrency + two Macs).
97 responded with widgets (106 were bot-blocked/JS-only to a plain `curl`). Of the 97:

| | sites | |
|---|---|---|
| ✅ fully auto-convertible | 3 | 3% |
| ⚠️ mostly (<25% manual + guided TODOs) | 56 | 57% |
| ❌ manual-heavy (≥25%) | 38 | 39% |
| **convert-plus carries the main content** | **59/97** | **60%** |

The dominant widgets are exactly the auto-mapped ones (heading 2838×, image 1501×,
text-editor 1190×, button 768×, icon-list/spacer/divider). The manual half is dynamic
(JetEngine, posts), e-commerce (Woo), nav-menus and forms — the `⚠️` rows above turn
those into guided TODOs (menu slug, query, field labels) rather than blanks, which
pushes the practical coverage well past the raw 60%.

Reproduce it yourself: `node tools/survey-coverage.js --sites yourlist.txt` (read-only — GETs
homepages and buckets the widgets; bring your own one-domain-per-line list). On a 142-site
curated showcase sample (87 reachable to a plain fetch) it reports **83% carries-content** —
higher than the 60% above because showcase/"best-of" lists skew toward cleaner, simpler
builds. The 60% is the more conservative figure from a *random* public sample; treat 60–83%
as the real-world band, with 60% the honest floor.

Tested on a synthetic page (all the above widget types): the ✅ ones produced correct
native blocks that render via the theme; image-box came over as best-effort with a
TODO; `posts` became a labelled placeholder carrying its settings. Report output:
`✅auto 7 | ⚠️semi 1 | ❌manual 1`. This widens the "fit" from *only html/shortcode* to
*+ simple visual widgets* — only the genuinely dynamic/complex widgets stay manual,
and for those you get a precise recipe instead of "rebuild".

### Other things it does NOT do

- It does not migrate Elementor **theme-builder** headers/footers/templates (Pro). If
  your header/footer is built in Elementor, you must rebuild those in the theme first.
- `compare-content.py` checks **text only** (`difflib`) — it won't catch pure CSS/layout
  differences. For those, use **`tools/visual-diff.js`** (now included): a Playwright
  screenshot pixel-diff + computed-token table that surfaces exactly the container/colour
  regressions text parity misses.
- It assumes the page's CSS comes from the theme / a `custom-css-js`-style plugin /
  the custom plugins — i.e. **not** from Elementor's own widget CSS. Verify this, or
  the styling will break when Elementor's stylesheet stops loading.

---

## Why deterministic (no AI) is the right call here

For the thin-wrapper case the content is already hand-written HTML and shortcodes, so
extraction is a tree walk — **exact, repeatable, no hallucination risk.** An LLM would
only help for the *visual-widget* case (mapping widget+settings → block markup) or for
true pixel parity (a vision model diffing before/after screenshots). For this class of
site, AI adds nothing.

---

## Method (the proven workflow)

> **One command** ties the ends together: `tools/wp-to-astra.sh "<wp-cmd>"` runs the
> dry Elementor test first (and offers to convert if any Elementor pages remain), then
> at the end asks *"Save as UpdraftPlus backup?"* and produces a real one if you say yes.
> The numbered steps below are what it orchestrates (+ the verify steps you run between).

1. **Duplicate the site** (new DB + dir + port). Keep the original running as the
   visual reference.
2. **Analyze** — `tools/analyze-elementor.php` → decide fit (see table above).
3. **Convert** — `tools/convert-all.php` (via `wp eval-file`):
   - walks `_elementor_data` in order: `html`→`wp:html` block, `shortcode`→`wp:shortcode`,
     `text-editor`→`wp:html`
   - builds native `post_content`, saves with `wp_update_post`
   - deletes all `_elementor_*` postmeta → `the_content()` now renders natively
4. **Remove Elementor** — deactivate + delete the plugin, delete leftover `_elementor_*`
   meta (revisions + kit), `elementor_*` options, and the `uploads/elementor/` CSS cache.
5. **Verify text parity** — `tools/compare-content.py <url-original> <url-converted>`
   (target ≥97% visible-text similarity; the rest is dynamic content / locale).
6. **Verify visual parity** — `node tools/visual-diff.js <url-original> <url-converted> <paths…>`.
   Read the per-band diff and the “section widths” line; fix any lost container widths
   (see *Container layout is lost*). Text parity passing does **not** imply visual parity.
7. **Verify everything else** — `node tools/site-verify.js <orig-base> <converted-base>`.
   One table per page: text words & headings (should match), broken images / failed
   assets / console errors (converted should be ≤ original), forms present. This is the
   gate that catches non-pixel regressions — a 404'd placeholder image, a menu item that
   leaked in, a plugin grid that stopped rendering. On a real run it surfaced exactly
   those: a `placeholder.jpg` 404 (present in the *original* too) and one stray menu
   entry — both invisible to the pixel diff. Don't sign off on the visual diff alone.

### Gotchas learned the hard way

- **Native `post_content` ≠ Elementor content.** Elementor hijacks `the_content` and
  renders from `_elementor_data`; the stored `post_content` is usually stale. Always
  extract from `_elementor_data` — and **do not** trust Elementor's "Back to WordPress
  Editor", which reverts to that stale/empty `post_content`.
- **Container layout is lost (the one that bites even thin-wrapper sites).** Elementor
  doesn't just stretch sections edge-to-edge — its containers also *constrain* width and
  add padding. A hand-written HTML block sitting inside an Elementor container inherits
  that container's `.e-con-inner{max-width:min(100%,1140px)}` (and section padding).
  Strip Elementor and that wrapper is gone: the block, whose own CSS never set a width,
  expands to full viewport width. The content is byte-identical — it's just no longer
  boxed. Real case here: the homepage hero went **1140px → 1440px**, scaling its
  background photo differently; pixel-diff 11.5% → 5% once fixed.
  - **Detect:** `tools/visual-diff.js` — compare “section widths orig vs conv”; a width
    that grew = a lost container constraint.
  - **Fix:** re-add the constraint in the theme / `custom-css-js` CSS, e.g.
    `body.home .my-hero{max-width:1140px;margin-inline:auto}`. **Often needs
    `!important`** — Astra/resets set `max-width:none` on `section` with equal-or-higher
    specificity, so a plain rule is silently overridden (verify with
    `getComputedStyle().maxWidth`, not just “the rule is in the stylesheet”).
  - Conversely, sections that were meant to be full-bleed must stay full-width — Astra's
    `ast-page-builder-template` usually keeps that; verify per theme. Don't fix this with
    a blanket `max-width` on the content wrapper: full-bleed dark bands then shrink and
    the diff gets *worse* (measured).

### Reproducing the layout — two paths (tested on a real 17-page site)

What we found by spinning the source up with Elementor + wp-cli and inspecting:
the containers had **no custom layout settings** (`settings` was `{}`) and there was
**no active kit**, so Elementor emitted **zero per-post CSS** — the boxing came purely
from its `frontend.css` default `.e-con-inner{max-width:1140px}`. So there's nothing
page-specific to "keep"; the gap is just that one default rule.

- **`tools/convert-layout.php` — reproduce the boxing (clean, ~95–98%).** Same clean
  extraction as `convert-all.php`, but wraps each top-level Elementor container in
  `<div class="exl-con exl-boxed|exl-full">` and writes `mu-plugins/exl-layout.css`
  (+ enqueue) reproducing only the boxed/full width (from the kit's `container_width`,
  else the 1140 default). Deterministic, no AI, stays clean. **Caveat:** if the content
  already self-boxes (its own `.section/.container` CSS), this wrapper is redundant and
  can fight full-bleed bands — `convert-all.php` + a targeted fix for the one element
  that broke is then better. **Always confirm with `visual-diff.js`.**
- **`tools/freeze-all.php` — keep Elementor's own render + CSS (measured 96.7%).** A
  clean reimplementation can't be byte-for-byte; only keeping Elementor's *own* output
  is. This captures each page's rendered HTML (`get_builder_content_for_display`, with
  dynamic shortcodes re-tokenized so litter grids stay live), preserves Elementor's CSS
  (`frontend.min.css` + the per-post `uploads/elementor/css/post-*.css` + kit CSS) into
  an mu-plugin that enqueues it, then you remove the plugin.
  - **Tested per-page on the real source** (13 pages, frozen vs the live Elementor
    render, AA-insensitive pixel diff): **98.4% average, range 94.4–100%** (`vrhy` hit
    100.0%; the lowest, the long photo-heavy `o-nás`, 94.4%). The residual is photo
    edges + sub-pixel font AA — visually identical, and the photo-heavy pages score a
    touch lower for that reason, not because of layout. With *only* `frontend.min.css`
    (skipping the per-post CSS) the homepage was **87%** — the per-page `post-*.css` is
    what carries the per-element layout, don't skip it.
  - **Order gotcha (measured):** deleting `_elementor_data` makes Elementor delete its
    own `post-*.css`. So `freeze-all.php` regenerates + copies the CSS **first**, then
    captures HTML, then deletes the meta. Doing it the other way silently loses the CSS.
  - Trade-off: markup keeps `elementor-*` classes and the CSS is Elementor's (verbose).
    This is the path to use when literal parity is what's required.

Bottom line on “100%”: colour/typography/content come over exactly; **literal pixel
parity is a freeze, not a conversion** — two render engines differ at the sub-pixel
(font AA, image scaling) regardless. Aim for *visually indistinguishable*, verify with
`visual-diff.js`, and don't claim 1:1 you didn't measure.
- **On-disk CSS cache.** Background images (`background:url(...)`) often live in
  `uploads/custom-css-js/*.css` on disk, outside the DB — `wp search-replace` won't
  touch them; `sed` the files directly when changing domains.
- **macOS `sed` + UTF-8.** Use `LC_ALL=C sed` for URL rewrites in SQL dumps, or it dies
  with *"illegal byte sequence"* on diacritics (silently truncating the dump).

---

## Packaging back into an UpdraftPlus backup

`tools/package-updraft.sh` generates a **real** UpdraftPlus backup by driving the
**plugin itself** — `do_action("updraft_backupnow_backup_all", …)` on the running
site — then waits for the log to confirm completion and collects the produced files
(`backup_<DATE>_<Name>_<nonce>-{db,plugins,themes,uploads,others}.*` **plus** the
`log.<nonce>.txt`).

```bash
tools/package-updraft.sh "docker exec mysite wp --allow-root" docker:mysite ./out
tools/package-updraft.sh "wp --path=/var/www/html"           /var/www/html/wp-content/updraft ./out
```

> **Do not hand-craft the zip set.** A backup assembled by zipping files yourself does
> **not** restore: UpdraftPlus drives the theme/plugin restore from its own log/manifest,
> and without it the restorer **skips themes and plugins** — you get the DB restored but
> the default theme, no CSS, a shuffled menu, dead shortcodes. (Earlier versions of this
> script hand-zipped and were broken; only the plugin-generated set restores.)
>
> The backup is a resumable wp-cron job, so run it against a **live** site (apache/php-fpm)
> — a headless wp-cli box with no HTTP loopback won't finish it.

**Restore:** drop all the collected files (the `backup_..._*` **and** `log.*.txt`) into
`wp-content/updraft/` on the target → UpdraftPlus → *Existing backups* → *Rescan local
folder* → *Restore* (tick Database + Plugins + Themes + Uploads + Others).

> Note: UpdraftPlus **Free has no headless restore** (`perform_restore()` returns `true`
> but runs no stage) — restore via the UI as above.

## Optional: public hosting behind a Cloudflare tunnel

`hosting/` contains a sanitized stack template (MariaDB + `wordpress:php8.3-apache`,
`./html:/var/www/html`, an Apache reverse-proxy override), a `wp-config` HTTPS-behind-proxy
snippet, and the cloudflared ingress + DNS recipe. All passwords are placeholders.

---

## Files

```
tools/elementor-ex.php        ★ main entry: test | convert (+ MANUAL-TODO.md guide)
tools/analyze-elementor.php   inventory of Elementor pages + widget-type histogram
tools/extract-page.php        extract one page (dry-run / --apply)
tools/convert-all.php         bulk-convert every page + clean up meta (content only)
tools/convert-layout.php      bulk-convert + reproduce container boxing (exl-layout.css)
tools/freeze-all.php          keep Elementor's render + CSS = literal pixel parity (~97%)
tools/compare-content.py      visible-content (text) parity of two URLs
tools/visual-diff.js          pixel + computed-CSS-token parity (catches layout/colour)
tools/compare-all.js          ★ all-pages Playwright sweep — auto-discovers pages from the
                              sitemap, then per page: render-health (HTTP status + page/console
                              JS errors + the "critical error" text curl can't see) and, with a
                              second base, original-vs-converted pixel diff. One PASS/FAIL gate.
                              --host <vhost> tests an internal port as the right site (maps via
                              --host-resolver-rules → no Cloudflare in the way).
tools/survey-coverage.js      reproduces the real-world coverage number: reads widget types
                              from live sites' homepages, buckets them like convert-plus, prints
                              the auto/partial/manual table. Read-only; bring your own site list
                              via --sites <file> (one domain per line).
tools/site-verify.js          ★ multi-dimension original-vs-converted gate (complements
                              compare-all.js): per page it diffs visible TEXT (word + heading
                              sets), counts broken images (naturalWidth 0), failed assets
                              (HTTP ≥400), console JS errors, forms/CF7, and DOM structure.
                              Catches what a pixel diff can't — a hidden 404 placeholder, a
                              stray extra menu item, a plugin that stopped emitting output.
                              `node tools/site-verify.js <orig-base> <converted-base>`
tools/wp-to-astra.sh          ★ end-to-end orchestrator: START runs the dry Elementor
                              test (+ offers to convert if any remain), END asks "Save as
                              UpdraftPlus backup?" and makes a real one. AUTO_CONVERT=1 /
                              AUTO_BACKUP=1 skip the prompts.
tools/package-updraft.sh      generate a REAL UpdraftPlus backup via the plugin
                              (do_action backupnow) — NOT a hand-crafted zip (those don't
                              restore themes/plugins). Run against a live site.
hosting/docker-compose.yml    WP stack template (sanitized)
hosting/apache-override.conf  Apache reverse-proxy + AllowOverride
hosting/wp-config-snippet.php HTTPS-behind-proxy + WP_HOME
hosting/cloudflared.md        ingress + DNS recipe
```

See **PLAYBOOK.md** for the tabular how-to (decide fit → pick clean/freeze → workflow → measured gotchas).

## Requirements

WP-CLI, PHP 7.4+, MySQL/MariaDB, `zip`/`gzip`. Python 3 for the text parity check.
Node 18+ with `playwright pixelmatch pngjs` (`npx playwright install chromium`) for
the visual parity check.

## License

MIT. Use at your own risk — **always work on a copy and keep the original**, and run
the parity check before trusting the result.
