#!/usr/bin/env node
/*
 * visual-diff.js — the screenshot/CSS parity check that compare-content.py can't do.
 *
 * compare-content.py checks visible TEXT. This checks PIXELS and computed CSS tokens,
 * so it catches the layout/colour regressions that de-Elementorizing can introduce
 * (most importantly: Elementor container max-width/padding being lost — see README,
 * "Container layout is lost"). It renders the original (with Elementor) and the
 * converted (without) page, pixel-diffs them, prints a per-band breakdown of where
 * they differ, and extracts a small table of design tokens (bg, colours, fonts,
 * sizes, section backgrounds) per page so you can see *what* differs, not just *how
 * much*.
 *
 * Deps:  npm i playwright pixelmatch pngjs   (then: npx playwright install chromium)
 *
 * Usage:
 *   node visual-diff.js <base-original> <base-converted> [path1 path2 ...]
 *     <base-original>   site that still has Elementor, e.g. https://example.com
 *     <base-converted>  the de-Elementorized copy, e.g. http://127.0.0.1:8002
 *     paths             page paths to compare; default "/"
 *
 * Example:
 *   node visual-diff.js https://example.com http://127.0.0.1:8002 / /about/ /contact/
 *
 * Output: per page a token table + diff %, and a diff-<slug>.png map in the cwd.
 * Exit 0 always; read the numbers. >~3% in a band that isn't a photo = a real layout diff.
 */
const { chromium } = require('playwright');
const pixelmatch = require('pixelmatch');
const { PNG } = require('pngjs');
const fs = require('fs');

const [, , baseA, baseB, ...rest] = process.argv;
if (!baseA || !baseB) { console.error('usage: node visual-diff.js <base-original> <base-converted> [paths...]'); process.exit(2); }
const paths = rest.length ? rest : ['/'];
const W = 1440, H = 3400, THRESH = 0.25; // includeAA:false ignores sub-pixel font AA

const rgb2hex = s => { const m = (s || '').match(/\d+/g); if (!m) return s; if (m.length >= 4 && m[3] === '0') return 'transparent'; return '#' + m.slice(0, 3).map(x => (+x).toString(16).padStart(2, '0')).join(''); };

async function snap(ctx, url) {
  const p = await ctx.newPage();
  await p.goto(url + (url.includes('?') ? '&' : '?') + 'z=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 }).catch(() => {});
  // scroll to trigger lazy-loaded images, then back to top
  await p.evaluate(async () => { await new Promise(r => { let y = 0; const t = setInterval(() => { scrollBy(0, 700); y += 700; if (y > document.body.scrollHeight) { clearInterval(t); r(); } }, 40); }); });
  await p.evaluate(() => scrollTo(0, 0)); await p.waitForTimeout(3500);
  const tokens = await p.evaluate(() => {
    const h = s => { const m = (s || '').match(/\d+/g); if (!m) return s; if (m.length >= 4 && m[3] === '0') return 'transparent'; return '#' + m.slice(0, 3).map(x => (+x).toString(16).padStart(2, '0')).join(''); };
    const samp = sel => { const e = document.querySelector(sel); if (!e) return null; const c = getComputedStyle(e); return { color: h(c.color), font: c.fontFamily.split(',')[0].replace(/["']/g, ''), size: c.fontSize, weight: c.fontWeight }; };
    const b = getComputedStyle(document.body);
    const seen = new Set(), sections = [];
    document.querySelectorAll('section,[class*="elementor-"],[class*="cr-"],[class*="ak-"],.wp-block-group,.wp-block-cover').forEach(el => {
      const r = el.getBoundingClientRect(); if (r.height < 80 || r.width < 400) return;
      const c = getComputedStyle(el); const bg = h(c.backgroundColor); const img = c.backgroundImage !== 'none';
      if (bg === 'transparent' && !img) return;
      const key = bg + '|' + Math.round(r.width); if (seen.has(key)) return; seen.add(key);
      sections.push({ bg, img, w: Math.round(r.width), h: Math.round(r.height) });
    });
    return { bodyBg: h(b.backgroundColor), bodyColor: h(b.color), bodyFont: b.fontFamily.split(',')[0].replace(/["']/g, ''), h1: samp('h1'), h2: samp('h2'), p: samp('p'), sections };
  });
  const buf = await p.screenshot({ clip: { x: 0, y: 0, width: W, height: H } });
  await p.close();
  return { png: PNG.sync.read(buf), tokens };
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  for (const path of paths) {
    const ctx = await browser.newContext({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
    const A = await snap(ctx, baseA.replace(/\/$/, '') + path);
    const B = await snap(ctx, baseB.replace(/\/$/, '') + path);
    await ctx.close();
    const diff = new PNG({ width: W, height: H });
    const n = pixelmatch(A.png.data, B.png.data, diff.data, W, H, { threshold: THRESH, includeAA: false });
    const slug = path.replace(/[^a-z0-9]+/gi, '_') || 'home';
    fs.writeFileSync(`diff-${slug}.png`, PNG.sync.write(diff));
    console.log(`\n=== ${path} ===  diff ${(100 * n / (W * H)).toFixed(2)}%   → diff-${slug}.png`);
    const t = (a, b) => (JSON.stringify(a) === JSON.stringify(b) ? '✓' : `✗  orig:${JSON.stringify(a)}  conv:${JSON.stringify(b)}`);
    console.log(`  body-bg ${t(A.tokens.bodyBg, B.tokens.bodyBg)}   text ${t(A.tokens.bodyColor, B.tokens.bodyColor)}   font ${t(A.tokens.bodyFont, B.tokens.bodyFont)}`);
    console.log(`  H1 ${t(A.tokens.h1, B.tokens.h1)}`);
    console.log(`  H2 ${t(A.tokens.h2, B.tokens.h2)}`);
    console.log(`  P  ${t(A.tokens.p, B.tokens.p)}`);
    console.log(`  section widths orig: ${A.tokens.sections.map(s => s.w).join(',')}`);
    console.log(`  section widths conv: ${B.tokens.sections.map(s => s.w).join(',')}  ← a width that differs = lost Elementor container constraint`);
    // per-band breakdown: where is the diff concentrated
    const band = 200;
    for (let y = 0; y < H; y += band) {
      let cnt = 0; for (let yy = y; yy < Math.min(y + band, H); yy++) for (let x = 0; x < W; x++) { const i = (yy * W + x) * 4; if (diff.data[i] === 255 && diff.data[i + 1] < 100) cnt++; }
      const pct = 100 * cnt / (W * band); if (pct > 3) console.log(`    band y=${y}-${y + band}: ${pct.toFixed(1)}%`);
    }
  }
  await browser.close();
})().catch(e => { console.error('ERR', e.message); process.exit(1); });
