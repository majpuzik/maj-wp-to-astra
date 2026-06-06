# De-Elementorization playbook (tabulkově)

Jak zbavit WordPress web Elementoru. Vše níže je **změřené** na reálné konverzi
(17 stránek), ne teorie.

## 1. Rozhodni, jestli to vůbec jde (analyzuj PRVNÍ)

`wp eval-file tools/analyze-elementor.php` → histogram widget typů.

| Co vidíš | Verdikt |
|---|---|
| Většinou `html`, `shortcode`, `text-editor` | ✅ **Fit** — obsah je už reálný kód, extrakce je mechanická |
| Pár visual widgetů mezi tím | ⚠️ Částečně — ty převeď ručně |
| Většinou `heading`, `image-box`, `accordion`, `tabs`… | ❌ **Není fit** — to je manual rebuild, žádný tool to spolehlivě neumí |

## 2. Vyber cestu (apples-to-apples, změřeno proti stejnému Elementor renderu)

| | CLEAN (`convert-all.php`) | FREEZE (`freeze-all.php`) |
|---|---|---|
| Markup | čistý Gutenberg (`wp:html`/`wp:shortcode`) | Elementorův (`elementor-*`, `e-con`) |
| CSS | jen téma + tvoje (`custom-css-js`) | + Elementorovo (`frontend.min.css` + `post-*.css`) |
| **Vizuální věrnost** | **95,4 % avg** (až ~99 % kde obsah self-boxuje) | **98,4 % avg** |
| Údržba | snadná, čistý kód | horší (verbose Elementor CSS) |
| Dynamika (shortcody) | zůstává živá | zůstává živá (re-tokenizace) |
| **Kdy** | obsah má **vlastní** layout CSS (`.section/.container`) | potřebuješ **pixel-věrnost**, nevadí dirty markup |

Rozdíl 95→98 je **celý** v Elementor-boxovaných sekcích, co se samy neboxují
(hero, formuláře). Kde má obsah vlastní layout → clean = freeze.

## 3. Postup (workflow)

| # | Krok | Nástroj | Pozn. |
|---|---|---|---|
| 1 | Duplikuj web (DB+dir+port) | — | originál nech běžet jako **vizuální ref** |
| 2 | Analyzuj | `analyze-elementor.php` | rozhodni fit (tab. 1) |
| 3 | (freeze) přepni na external CSS + render | `wp option … external` | vygeneruje `post-*.css` |
| 4 | Převeď | `convert-all.php` / `freeze-all.php` | freeze: CSS se zachová PŘED smazáním meta |
| 5 | Odstraň Elementor | deaktivuj+smaž plugin, `_elementor_*` meta, `uploads/elementor/` | |
| 6 | Ověř **text** | `compare-content.py` | cíl ≥97 %; zbytek = dynamika/locale |
| 7 | Ověř **vizuál** | `visual-diff.js` | **per-page**, ne jen homepage |
| 7b | **Sweep všech stránek** (pro jistotu) | `compare-all.js` | auto ze sitemapy → render-health (status + JS/console errory + „critical error") + volitelně pixel diff orig↔converted; jeden PASS/FAIL. `--host <vhost>` testuje interní port jako správný web (přes `--host-resolver-rules`, mimo Cloudflare). **Host hlavičku do Playwrightu NEcpát** — Chrome ji odmítne (`ERR_INVALID_ARGUMENT`). |
| 8 | Oprav container-loss | cílený `max-width:1140px !important` | viz gotchy |

## 4. Gotchy (každá změřená, ne odhad)

| Past | Proč | Fix |
|---|---|---|
| Boxed obsah jde **full-width** (hero 1140→1440) | ztracený Elementor container `.e-con-inner{max-width}` | `max-width:1140px;margin-inline:auto` |
| `max-width` se **tiše nepřebíjí** | Astra resetuje `section{max-width:none}` stejnou specificitou | **`!important`** nebo class-wrapper; ověř `getComputedStyle().maxWidth`, ne „rule je v CSS" |
| Broad `max-width` na wrapper **zhorší** | full-bleed tmavé pásy se zúží | aplikuj **jen na konkrétní** element, ne plošně |
| Freeze ztratí CSS = jen 87 % | smazání `_elementor_data` spustí Elementorovo **smazání `post-*.css`** | CSS **regeneruj+zkopíruj PŘED** smazáním meta |
| Per-post CSS chybí | `post-*.css` nese **per-element** layout (87 % → 98 %) | zachovej `uploads/elementor/css/post-*.css` + enqueue per `is_singular()` |
| Změny se neprojeví | `custom-css-js` servíruje **cache soubory** `uploads/custom-css-js/*.css` | edituj i ty cache soubory, ne jen DB post |
| Falešně „světlý" originál | **Wayback** nenačte Elementor CSS → vypadá nestylovaně | porovnávej proti **STYLOVANÉ živé produkci**, ne archivu |
| „1:1" claim | dva renderery se liší na sub-pixelu (font AA, JPEG) | **neměř, netvrď**; cíl = vizuálně k nerozeznání, doloženo `visual-diff.js` |

## 5. Co je „hotovo"

- text parity ≥97 % (`compare-content.py`)
- vizuál: žádná stránka výrazně pod průměrem (`visual-diff.js` per-page); container-loss opraven
- 0 `_elementor_*` meta, plugin pryč, web renderuje bez něj
- **deterministické** (2 běhy = identický hash), žádné AI
