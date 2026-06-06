#!/usr/bin/env node
/*
 * compare-all.js — run the Playwright page check across EVERY page automatically.
 *
 * visual-diff.js compares the paths you pass by hand. This discovers all pages
 * (from a sitemap or a paths file) and, for each one, runs a real-browser check that
 * curl can't do:
 *   • render-health  — HTTP status, page-level JS errors, console errors, and the
 *                      WordPress "There has been a critical error" text.
 *   • comparison     — if you give BOTH an original and a converted base, it also
 *                      pixel-diffs the two renders so you see layout/colour drift.
 *
 * It prints one summary table and an overall PASS/FAIL gate — the "for assurance"
 * step after a conversion or an Elementor cleanup. Deterministic; no AI.
 *
 * Deps:  npm i playwright pixelmatch pngjs   (then: npx playwright install chromium)
 *
 * Usage:
 *   node compare-all.js <base-converted> [base-original] [options]
 *     <base-converted>   the de-Elementorized / cleaned site (required), e.g. http://127.0.0.1:8002
 *     [base-original]    the Elementor reference (optional) — enables pixel comparison
 *   options:
 *     --host <vhost>       Host header (serve an internal port as the right site,
 *                          e.g. --host example.com  → no Cloudflare needed)
 *     --sitemap <url>      sitemap to read paths from (default: <base>/wp-sitemap.xml,
 *                          falling back to /sitemap.xml, /sitemap_index.xml)
 *     --paths <file>       one path per line instead of a sitemap
 *     --auth user:pass     HTTP Basic auth (for sites behind Cloudflare basic-auth)
 *     --threshold <pct>    flag a page when pixel diff exceeds N% (default 3)
 *     --max <n>            cap pages checked (default 200)
 *
 * Examples:
 *   # render-health of every page of the cleaned site (internal port + vhost):
 *   node compare-all.js http://127.0.0.1:8002 --host example.com
 *   # compare original (Elementor) vs converted across all pages:
 *   node compare-all.js http://127.0.0.1:8002 https://example.com --threshold 3
 *
 * Exit: 0 if every page is 2xx/3xx, error-free, and (when comparing) within threshold; 1 otherwise.
 */
const { chromium } = require('playwright');
let pixelmatch, PNG;
try { pixelmatch = require('pixelmatch'); PNG = require('pngjs').PNG; } catch { /* comparison disabled if missing */ }

function parseArgs(a) {
  const o = { _: [], threshold: 3, max: 200 };
  for (let i = 0; i < a.length; i++) {
    const k = a[i];
    if (k === '--host') o.host = a[++i];
    else if (k === '--sitemap') o.sitemap = a[++i];
    else if (k === '--paths') o.paths = a[++i];
    else if (k === '--auth') o.auth = a[++i];
    else if (k === '--threshold') o.threshold = parseFloat(a[++i]);
    else if (k === '--max') o.max = parseInt(a[++i], 10);
    else o._.push(k);
  }
  return o;
}

const opt = parseArgs(process.argv.slice(2));
const baseConv = opt._[0];
const baseOrig = opt._[1];
if (!baseConv) { console.error('usage: node compare-all.js <base-converted> [base-original] [--host h] [--sitemap u|--paths f] [--auth u:p] [--threshold n] [--max n]'); process.exit(2); }

// Node fetch (sitemap) MAY send Host; the browser MUST NOT (Host is a forbidden header →
// Chrome ERR_INVALID_ARGUMENT). For the browser we map the vhost to the internal ip:port
// via --host-resolver-rules and navigate the vhost URL instead.
const authHeader = opt.auth ? { Authorization: 'Basic ' + Buffer.from(opt.auth).toString('base64') } : {};
const nodeHeaders = () => ({ headers: { ...(opt.host ? { Host: opt.host } : {}), ...authHeader } });
const ipPort = baseConv.replace(/^https?:\/\//, '').replace(/\/$/, '');           // host:port, e.g. 127.0.0.1:8002
const navConverted = opt.host ? `http://${opt.host}` : baseConv;                   // what the browser navigates

async function getText(url) {
  try { const r = await fetch(url, nodeHeaders()); return r.ok ? await r.text() : ''; } catch { return ''; }
}

function locs(xml) { return [...xml.matchAll(/<loc>\s*([^<\s]+)\s*<\/loc>/g)].map(m => m[1]); }

async function discoverPaths() {
  if (opt.paths) return require('fs').readFileSync(opt.paths, 'utf8').split('\n').map(s => s.trim()).filter(Boolean);
  const tries = opt.sitemap ? [opt.sitemap]
    : ['/wp-sitemap.xml', '/sitemap.xml', '/sitemap_index.xml'].map(p => baseConv.replace(/\/$/, '') + p);
  const found = new Set(['/']);
  for (const sm of tries) {
    const xml = await getText(sm);
    if (!xml) continue;
    for (let loc of locs(xml)) {
      if (loc.endsWith('.xml')) { for (const u of locs(await getText(loc))) found.add(toPath(u)); }
      else found.add(toPath(loc));
    }
    if (found.size > 1) break;
  }
  return [...found].slice(0, opt.max);
}
const toPath = u => { try { return new URL(u).pathname || '/'; } catch { return u.startsWith('/') ? u : '/'; } };

async function render(ctx, base, path) {
  const errs = [];
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push('JS: ' + (e.message || e).toString().slice(0, 120)));
  p.on('console', m => { if (m.type() === 'error') errs.push('console: ' + m.text().slice(0, 120)); });
  let status = 0;
  const url = base.replace(/\/$/, '') + path;
  try {
    const resp = await p.goto(url + (url.includes('?') ? '&' : '?') + 'z=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
    status = resp ? resp.status() : 0;
  } catch (e) { errs.push('nav: ' + (e.message || '').slice(0, 80)); }
  await p.evaluate(async () => { await new Promise(r => { let y = 0; const t = setInterval(() => { scrollBy(0, 800); y += 800; if (y > document.body.scrollHeight) { clearInterval(t); r(); } }, 30); }); }).catch(() => {});
  await p.evaluate(() => scrollTo(0, 0)).catch(() => {});
  await p.waitForTimeout(1500);
  const body = await p.content().catch(() => '');
  const critical = /There has been a critical error|Fatal error<|<b>Fatal error<\/b>/i.test(body);
  let png = null;
  if (baseOrig && pixelmatch) png = await p.screenshot({ clip: { x: 0, y: 0, width: 1440, height: 3200 } }).catch(() => null);
  await p.close();
  return { status, errs, critical, png };
}

function diffPct(a, b) {
  if (!a || !b) return null;
  const A = PNG.sync.read(a), B = PNG.sync.read(b);
  const w = Math.min(A.width, B.width), h = Math.min(A.height, B.height);
  const out = new PNG({ width: w, height: h });
  const n = pixelmatch(A.data, B.data, out.data, w, h, { threshold: 0.25, includeAA: false });
  return +(100 * n / (w * h)).toFixed(2);
}

(async () => {
  const paths = await discoverPaths();
  console.log(`compare-all: ${paths.length} pages on ${baseConv}` + (opt.host ? ` (Host: ${opt.host})` : '') + (baseOrig ? `  vs original ${baseOrig}` : '  (render-health only)'));
  const launchArgs = opt.host ? [`--host-resolver-rules=MAP ${opt.host} ${ipPort}`] : [];
  const browser = await chromium.launch({ channel: 'chrome', args: launchArgs }).catch(() => chromium.launch({ args: launchArgs }));
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, extraHTTPHeaders: authHeader });
  const rows = []; let fails = 0;
  for (const path of paths) {
    const conv = await render(ctx, navConverted, path);
    let pct = null;
    if (baseOrig) { const orig = await render(ctx, baseOrig, path); pct = (conv.png && orig.png) ? diffPct(orig.png, conv.png) : null; }
    const okStatus = conv.status >= 200 && conv.status < 400;
    const bad = !okStatus || conv.critical || conv.errs.length || (pct != null && pct > opt.threshold);
    if (bad) fails++;
    rows.push({ path, status: conv.status, critical: conv.critical, errs: conv.errs.length, pct, bad });
    const flag = bad ? '❌' : '✅';
    console.log(`  ${flag} ${String(conv.status).padEnd(3)} ${pct != null ? (pct + '%').padStart(7) : '   —   '}  ${conv.critical ? 'CRITICAL ' : ''}${conv.errs.length ? conv.errs.length + ' err ' : ''}${path}`);
    if (bad && conv.errs.length) conv.errs.slice(0, 2).forEach(e => console.log(`        ↳ ${e}`));
  }
  await browser.close();
  const total = rows.length;
  console.log(`\n${fails === 0 ? '✅ PASS' : '❌ FAIL'}: ${total - fails}/${total} pages clean` + (fails ? ` — ${fails} need a look` : ''));
  process.exit(fails === 0 ? 0 : 1);
})();
