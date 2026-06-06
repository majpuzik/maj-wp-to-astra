# wp-to-astra — distributed coverage survey (run 2026-06-06)

**Co se měřilo:** read-only / suchá analytická fáze workflow — `survey-coverage.js`
stáhne homepage každého webu a deterministicky zařadí Elementor widgety do
auto/partial/manual. (Na cizí živé weby není admin/wp-cli, takže reálnou konverzi
ani UpdraftPlus backup na nich spustit nelze — ty fáze jsou ověřené zvlášť na
vlastním webu dogarnaa.)

**Jak:** 8 agentů, **4 na Dell + 4 na DGX**, každý 1 shard (~18 domén) reálně přes
SSH na své nodě. Wall-clock celé dávky: **37,7 s**. Seznam: 142 domén
(`elementor-sites.txt`). Agregace **deterministická z 8 stažených CSV (awk)**, ne
z čísel agentů.

## Výsledek (autoritativní, z CSV)

```
Surveyed 142 sites; 87 returned Elementor widgets to a plain fetch (61%)
  non-responding 55: unreachable 17 · not-elementor 33 · no-widgets 5

mezi 87 responding:
  ✅ fully auto-convertible        4   5%
  ⚠️  mostly (<25% manual+TODOs)  68  78%
  ❌ manual-heavy (≥25%)          15  17%
  ──
  convert-plus carries main content  72/87  83%
```

Rozpad po nodě:
```
  DELL (shard1-4)  surveyed=72 responded=45 | auto=2 mostly=34 heavy=9 | unreach=6  not-el=18 no-wid=3
  DGX  (shard5-8)  surveyed=70 responded=42 | auto=2 mostly=34 heavy=6 | unreach=11 not-el=15 no-wid=2
```

Dominant widgets (top-12/shard sečteno): heading 1865×, image 1050×, text-editor 892×,
button 626×, icon 274×, icon-list 177×, spacer 174×, divider 148×, theme-post-title 110×,
nav-menu 93×, html 81×, theme-post-featured-image 74×.

## Kontrola poctivosti (cross-check)

Self-reported čísla 8 agentů vs deterministická agregace z reálných CSV:
8 z 9 polí **přesně shodných** (responded/auto/mostly/heavy/carries/unreachable/
not-elementor/no-widgets). Jediná odchylka: `surveyed` agenti 141 vs CSV 142 — shard1
agent přepsal scalar na 17, ačkoli jeho vlastní rozpad stavů dává 18. CSV-agregace to
opravila → **142**. (Proč neagreguju čísly agentů.)

## Reprodukovatelnost vs minulý běh (SURVEY-RESULTS.md)

responded **87 identicky**, buckety **4/68/15 identicky**, carries **72/87 identicky**.
Liší se jen reachability o 1 web (not-elementor 34→33, unreachable 16→17) = síťová
variance. Survey je deterministický a reprodukovatelný i rozprostřený přes 8 agentů
na 2 strojích.

## Honest interpretace pásma

83 % (carries) je ze **showcase** vzorku → horní hranice. Dokumentovaná **60 %** je
z náhodného vzorku 203 webů = poctivá spodní hranice. Reálné pásmo **60–83 %**.
