#!/usr/bin/env node
/*
 * survey-coverage.js — measure how far this toolkit gets on REAL Elementor sites.
 *
 * Dry, read-only field survey: fetch each site's rendered homepage, read the
 * Elementor widget types straight out of the markup (`data-widget_type="<type>.…"`),
 * bucket every widget the same way convert-plus.php does (auto / partial-TODO /
 * manual), and classify each site. Prints the real-world coverage table behind the
 * README badge. Changes nothing, anywhere — it only does GET on public homepages.
 *
 * No deps (Node 18+ global fetch). Deterministic; no AI.
 *
 * Usage:
 *   node survey-coverage.js [--sites <file>] [--concurrency <n>] [--timeout <ms>] [--csv <file>]
 *     --sites <file>   one domain per line (default: tools/data/elementor-sites.txt)
 *     --concurrency    parallel fetches (default 12)
 *     --timeout        per-site ms (default 12000)
 *     --csv <file>     also write a per-site CSV
 *
 * A site "carries the main content" when auto+partial widgets are ≥75% of its
 * widget instances (manual-heavy = ≥25% manual). Same thresholds as the README.
 */
const fs = require('fs');
const path = require('path');

function parseArgs(a) {
  const o = { concurrency: 12, timeout: 12000 };
  for (let i = 0; i < a.length; i++) {
    if (a[i] === '--sites') o.sites = a[++i];
    else if (a[i] === '--concurrency') o.concurrency = parseInt(a[++i], 10);
    else if (a[i] === '--timeout') o.timeout = parseInt(a[++i], 10);
    else if (a[i] === '--csv') o.csv = a[++i];
  }
  return o;
}
const opt = parseArgs(process.argv.slice(2));
const sitesFile = opt.sites || path.join(__dirname, 'data', 'elementor-sites.txt');

// Same buckets as convert-plus.php: auto = carried 1:1 / mapped to a native block,
// partial = mapped to a guided TODO carrying its data, manual = no native equivalent.
const AUTO = new Set(['html', 'shortcode', 'text-editor', 'heading', 'image', 'button',
  'icon-list', 'divider', 'spacer', 'video', 'theme-post-content', 'theme-post-title']);
const PARTIAL = new Set(['image-box', 'icon-box', 'icon', 'nav-menu', 'posts', 'loop-grid',
  'portfolio', 'form', 'gallery', 'image-gallery', 'social-icons', 'google_maps', 'menu-anchor']);
// everything else (accordion, tabs, toggle, testimonial, counter, progress, price-*, Pro, JetEngine…) = manual

function bucket(type) { return AUTO.has(type) ? 'auto' : PARTIAL.has(type) ? 'partial' : 'manual'; }

async function fetchHtml(domain) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), opt.timeout);
  for (const scheme of ['https://', 'http://']) {
    try {
      const r = await fetch(scheme + domain + '/', {
        signal: ctrl.signal, redirect: 'follow',
        headers: { 'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36',
          'Accept': 'text/html,application/xhtml+xml' },
      });
      const html = await r.text();
      clearTimeout(t);
      return { ok: true, status: r.status, html };
    } catch (e) { if (scheme === 'http://') { clearTimeout(t); return { ok: false, err: (e.message || 'fetch').slice(0, 40) }; } }
  }
  clearTimeout(t);
  return { ok: false, err: 'unreachable' };
}

function widgets(html) {
  const counts = {};
  for (const m of html.matchAll(/data-widget_type="([^".]+)\.[^"]*"/g)) {
    const t = m[1]; counts[t] = (counts[t] || 0) + 1;
  }
  // fallback: class-based, only if data-widget_type produced nothing
  if (Object.keys(counts).length === 0) {
    for (const m of html.matchAll(/class="[^"]*elementor-widget-([a-z0-9-]+)[\s"]/g)) {
      const t = m[1]; if (t === 'container') continue; counts[t] = (counts[t] || 0) + 1;
    }
  }
  return counts;
}

function classify(counts) {
  const tot = Object.values(counts).reduce((s, n) => s + n, 0);
  if (tot === 0) return { tot: 0, verdict: 'no-widgets' };
  const by = { auto: 0, partial: 0, manual: 0 };
  for (const [t, n] of Object.entries(counts)) by[bucket(t)] += n;
  const manualPct = by.manual / tot;
  let verdict;
  if (by.manual === 0 && by.partial === 0) verdict = 'auto';
  else if (manualPct < 0.25) verdict = 'mostly';
  else verdict = 'manual-heavy';
  return { tot, by, manualPct, verdict, carries: verdict !== 'manual-heavy' };
}

async function pool(items, n, fn) {
  const out = new Array(items.length); let i = 0;
  await Promise.all(Array.from({ length: Math.min(n, items.length) }, async () => {
    while (i < items.length) { const k = i++; out[k] = await fn(items[k], k); }
  }));
  return out;
}

(async () => {
  if (!fs.existsSync(sitesFile)) {
    console.error(`No site list at ${sitesFile}. Pass one with --sites <file> (one domain per line).`);
    process.exit(2);
  }
  const domains = fs.readFileSync(sitesFile, 'utf8').split('\n').map(s => s.trim()).filter(Boolean);
  console.log(`survey-coverage: ${domains.length} real Elementor sites — read-only widget survey\n`);
  const widgetTotals = {};
  const rows = await pool(domains, opt.concurrency, async (d) => {
    const res = await fetchHtml(d);
    if (!res.ok) return { d, state: 'unreachable', note: res.err };
    const counts = widgets(res.html);
    const c = classify(counts);
    if (c.tot === 0) {
      const elem = /elementor|wp-content\/plugins\/elementor/i.test(res.html);
      process.stdout.write('·');
      return { d, state: elem ? 'no-widgets' : 'not-elementor', status: res.status };
    }
    for (const [t, n] of Object.entries(counts)) widgetTotals[t] = (widgetTotals[t] || 0) + n;
    process.stdout.write(c.verdict === 'manual-heavy' ? '✗' : '✓');
    return { d, state: 'responded', ...c };
  });
  process.stdout.write('\n\n');

  const responded = rows.filter(r => r.state === 'responded');
  const auto = responded.filter(r => r.verdict === 'auto');
  const mostly = responded.filter(r => r.verdict === 'mostly');
  const heavy = responded.filter(r => r.verdict === 'manual-heavy');
  const carries = responded.filter(r => r.carries);
  const noResp = rows.length - responded.length;
  const pct = (n) => responded.length ? (100 * n / responded.length).toFixed(0) + '%' : '—';

  console.log(`Surveyed ${rows.length} sites; ${responded.length} returned Elementor widgets ` +
    `(${noResp} bot-blocked / JS-only / not-Elementor to a plain fetch).\n`);
  console.log('                                            sites');
  console.log(`  ✅ fully auto-convertible                  ${String(auto.length).padStart(3)}  ${pct(auto.length)}`);
  console.log(`  ⚠️  mostly (<25% manual + guided TODOs)     ${String(mostly.length).padStart(3)}  ${pct(mostly.length)}`);
  console.log(`  ❌ manual-heavy (≥25%)                      ${String(heavy.length).padStart(3)}  ${pct(heavy.length)}`);
  console.log(`  ──`);
  console.log(`  convert-plus carries the main content     ${carries.length}/${responded.length}  ${pct(carries.length)}\n`);

  const top = Object.entries(widgetTotals).sort((a, b) => b[1] - a[1]).slice(0, 12);
  console.log('Dominant widgets: ' + top.map(([t, n]) => `${t} ${n}×`).join(', '));

  if (opt.csv) {
    const lines = ['domain,state,verdict,total_widgets,auto,partial,manual'];
    for (const r of rows) lines.push([r.d, r.state, r.verdict || '', r.tot || 0,
      r.by?.auto || 0, r.by?.partial || 0, r.by?.manual || 0].join(','));
    fs.writeFileSync(opt.csv, lines.join('\n'));
    console.log(`\nPer-site CSV → ${opt.csv}`);
  }
})();
