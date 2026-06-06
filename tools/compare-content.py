#!/usr/bin/env python3
"""
Visible-content parity check between two sites/URLs.

Compares the rendered, tag-stripped text of the original (with Elementor) and the
converted (without Elementor) page so you can confirm nothing was lost. It is a
TEXT comparison only — it does NOT catch pure CSS/layout differences (use a vision
screenshot diff for that).

Usage:
  compare-content.py <base-a> <base-b> [path1 path2 ...]

    <base-a>   original site base URL, e.g. https://example.com   (or http://127.0.0.1:8001)
    <base-b>   converted site base URL, e.g. http://127.0.0.1:8002
    paths      page paths to compare; defaults to the homepage ("")

Example:
  compare-content.py https://example.com http://127.0.0.1:8002 "" about/ contact/
"""
import sys, re, difflib, subprocess

def fetch(url):
    # curl with a browser UA — urllib gets 403 from Cloudflare/bot filters
    try:
        return subprocess.run(
            ["curl", "-s", "-m", "25", "-L", "-A", "Mozilla/5.0", url],
            capture_output=True, text=True, timeout=30).stdout
    except Exception as e:
        return ""

def visible(h):
    h = re.sub(r"<(script|style|noscript)[^>]*>.*?</\1>", "", h, flags=re.S | re.I)
    h = re.sub(r"<[^>]+>", " ", h)
    h = re.sub(r"&nbsp;|&[a-z]+;", " ", h)
    return re.sub(r"\s+", " ", h).strip()

def structs(h):
    return len(re.findall(r"<section|<h1|<h2|<h3", h)), len(re.findall(r"<img", h))

def main():
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(1)
    a_base, b_base = sys.argv[1].rstrip("/"), sys.argv[2].rstrip("/")
    paths = sys.argv[3:] or [""]
    print(f"  {'path':<24} {'text a/b':<16} {'sect':<8} {'img':<8} match")
    for p in paths:
        a = fetch(f"{a_base}/{p}"); b = fetch(f"{b_base}/{p}")
        ta, tb = visible(a), visible(b)
        if not ta or not tb:
            print(f"  {(p or '[home]'):<24} FETCH FAIL (a={len(ta)} b={len(tb)})"); continue
        sa, ia = structs(a); sb, ib = structs(b)
        r = difflib.SequenceMatcher(None, ta, tb).ratio() * 100
        flag = "" if r >= 97 else ("  <-- DIFF" if r >= 80 else "  <-- BIG DIFF")
        print(f"  {(p or '[home]'):<24} {len(ta):>6}/{len(tb):<8} {f'{sa}/{sb}':<8} {f'{ia}/{ib}':<8} {r:.1f}%{flag}")

if __name__ == "__main__":
    main()
