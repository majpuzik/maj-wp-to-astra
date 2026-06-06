<?php
/**
 * convert-plus.php — convert-all.php + maps the *simple* Elementor widgets to native
 * Gutenberg blocks (deterministic, no AI), and for the hard ones emits a placeholder
 * block with the extracted data + a TODO so nothing is silently dropped.
 *
 *   wp eval-file tools/convert-plus.php            # convert + clean meta
 *   wp eval-file tools/convert-plus.php report     # dry: per-page widget report only
 *   (positional 'report' — wp-cli eats --flags before the script sees them)
 *
 * Mapping:
 *   ✅ auto  : html, shortcode, text-editor, heading, image, button, icon-list,
 *              divider, spacer, video, html
 *   ⚠️ semi  : image-box, icon-box  -> image + heading + text blocks + a TODO
 *   ❌ manual: posts, loop-grid, portfolio, form, accordion, tabs, anything unknown
 *              -> <!-- TODO MANUAL: type --> placeholder with extracted text/links
 *
 * Run analyze-elementor.php / --report first to see the mix.
 */
global $wpdb;
$REPORT = in_array('report', $GLOBALS['argv'] ?? array()) || in_array('--report', $GLOBALS['argv'] ?? array());

$rows = $wpdb->get_results("
  SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
  JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
  WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder'
    AND p.post_type IN ('page','post') AND p.post_status IN ('publish','draft') ORDER BY p.ID");

$esc  = function ($s) { return esc_html((string) $s); };
$lvl  = function ($h) { $h = strtolower((string) $h); return (preg_match('/^h([1-6])$/', $h, $m)) ? (int) $m[1] : 2; };

// returns array(class, block) — class is one of auto|semi|manual
$mapw = function ($el) use ($esc, $lvl, &$mapw) {
    $wt = $el['widgetType'] ?? null;
    $s  = $el['settings'] ?? array();
    switch ($wt) {
        case 'html':        $v = trim((string)($s['html'] ?? '')); return $v===''?null:array('auto', "<!-- wp:html -->\n$v\n<!-- /wp:html -->");
        case 'shortcode':   $v = trim((string)($s['shortcode'] ?? '')); return $v===''?null:array('auto', "<!-- wp:shortcode -->$v<!-- /wp:shortcode -->");
        case 'text-editor': $v = trim((string)($s['editor'] ?? '')); return $v===''?null:array('auto', "<!-- wp:html -->\n$v\n<!-- /wp:html -->");
        case 'heading':
            $t = $esc($s['title'] ?? ''); if ($t==='') return null;
            $n = $lvl($s['header_size'] ?? 'h2');
            return array('auto', "<!-- wp:heading {\"level\":$n} -->\n<h$n>$t</h$n>\n<!-- /wp:heading -->");
        case 'image':
            $u = $s['image']['url'] ?? ''; if ($u==='') return null;
            $a = $esc($s['image']['alt'] ?? '');
            return array('auto', "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"".esc_url($u)."\" alt=\"$a\"/></figure>\n<!-- /wp:image -->");
        case 'button':
            $t = $esc($s['text'] ?? 'Button'); $u = esc_url($s['link']['url'] ?? '#');
            return array('auto', "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"$u\">$t</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->");
        case 'icon-list':
            $items = $s['icon_list'] ?? array(); $li = '';
            foreach ((array)$items as $it) { $tx = $esc($it['text'] ?? ''); if ($tx!=='') $li .= "<li>$tx</li>"; }
            return $li===''?null:array('auto', "<!-- wp:list -->\n<ul class=\"wp-block-list\">$li</ul>\n<!-- /wp:list -->");
        case 'divider':
            return array('auto', "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->");
        case 'spacer':
            $h = (int)($s['space']['size'] ?? 50);
            return array('auto', "<!-- wp:spacer {\"height\":\"{$h}px\"} -->\n<div style=\"height:{$h}px\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->");
        case 'video':
            $u = $s['youtube_url'] ?? ($s['vimeo_url'] ?? ($s['hosted_url']['url'] ?? ''));
            return $u===''?null:array('auto', "<!-- wp:embed {\"url\":\"".esc_url($u)."\",\"type\":\"video\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">".esc_url($u)."</div></figure>\n<!-- /wp:embed -->");
        // ⚠️ semi — best effort, extract the useful data so the manual step is guided
        case 'icon':
            $ic = $esc($s['selected_icon']['value'] ?? ($s['icon'] ?? ''));
            return array('semi', "<!-- wp:html -->\n<!-- TODO check icon (needs icon font) -->\n<span class=\"$ic\"></span>\n<!-- /wp:html -->");
        case 'nav-menu':
            $mn = $esc($s['menu'] ?? '');
            return array('semi', "<!-- wp:html -->\n<!-- TODO: Elementor nav-menu '$mn' → wire to a wp:navigation block (same menu) -->\n<!-- /wp:html -->");
        case 'posts': case 'loop-grid': case 'portfolio':
            $pt = $esc($s['posts_post_type'] ?? ($s['query_post_type'] ?? 'post')); $pp = (int)($s['posts_per_page'] ?? 6);
            return array('semi', "<!-- wp:html -->\n<!-- TODO: Elementor '$wt' → wp:query loop. post_type=$pt, per_page=$pp -->\n<!-- /wp:html -->");
        case 'form':
            $fl = array(); foreach ((array)($s['form_fields'] ?? array()) as $ff) { $l = trim((string)($ff['field_label'] ?? $ff['field_type'] ?? '')); if ($l!=='') $fl[] = $esc($l); }
            $fls = $fl ? implode(', ', $fl) : '?';
            return array('semi', "<!-- wp:html -->\n<!-- TODO: Elementor form → rebuild as Contact Form 7 (it has its own config). Fields: $fls -->\n<!-- /wp:html -->");
        case 'image-box': case 'icon-box':
            $img = $s['image']['url'] ?? ''; $h = $esc($s['title_text'] ?? ($s['title'] ?? '')); $d = $esc($s['description_text'] ?? '');
            $b  = "<!-- wp:html -->\n<!-- TODO check (was $wt) -->\n";
            if ($img) $b .= "<figure class=\"wp-block-image\"><img src=\"".esc_url($img)."\" alt=\"$h\"/></figure>\n";
            if ($h)   $b .= "<h3>$h</h3>\n";
            if ($d)   $b .= "<p>$d</p>\n";
            return array('semi', $b."<!-- /wp:html -->");
        // ❌ manual — placeholder + extracted hint
        default:
            if ($wt === null) return null;
            $hint = $esc(mb_substr(trim(strip_tags(json_encode($s, JSON_UNESCAPED_UNICODE))), 0, 80));
            return array('manual', "<!-- wp:html -->\n<!-- TODO MANUAL: Elementor '$wt' widget — rebuild by hand. settings: $hint -->\n<!-- /wp:html -->");
    }
};

$compose = function ($post_id) use ($mapw) {
    $data = get_post_meta($post_id, '_elementor_data', true);
    $json = is_string($data) ? json_decode($data, true) : $data;
    if (!is_array($json)) return null;
    $blocks = array(); $stat = array('auto'=>0,'semi'=>0,'manual'=>0); $todo = array();
    $walk = function ($els) use (&$walk, &$blocks, &$stat, &$todo, $mapw) {
        foreach ((array)$els as $el) {
            if (!empty($el['widgetType'])) {
                $r = $mapw($el);
                if ($r) { list($c, $b) = $r; $blocks[] = $b; $stat[$c]++;
                    if ($c !== 'auto') $todo[] = $el['widgetType']; }
            }
            if (!empty($el['elements'])) $walk($el['elements']);
        }
    };
    $walk($json);
    return array('content'=>implode("\n\n", $blocks), 'stat'=>$stat, 'todo'=>$todo);
};

$tot = array('auto'=>0,'semi'=>0,'manual'=>0);
foreach ($rows as $r) {
    $c = $compose($r->ID);
    if (!$c) continue;
    foreach ($c['stat'] as $k=>$v) $tot[$k]+=$v;
    if ($REPORT) {
        printf("  #%d \"%s\": ✅%d ⚠️%d ❌%d%s\n", $r->ID, $r->post_title,
            $c['stat']['auto'], $c['stat']['semi'], $c['stat']['manual'],
            $c['todo'] ? "  → ruční: ".implode(', ', array_unique($c['todo'])) : '');
        continue;
    }
    wp_update_post(array('ID'=>$r->ID, 'post_content'=>$c['content']));
    $del = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s", $r->ID, '_elementor%'));
    printf("  OK #%d \"%s\": ✅%d ⚠️%d ❌%d, %d meta\n", $r->ID, $r->post_title,
        $c['stat']['auto'], $c['stat']['semi'], $c['stat']['manual'], $del);
}
printf("\n%s: ✅auto %d | ⚠️semi %d (zkontroluj) | ❌manual %d (TODO v obsahu)\n",
    $REPORT?'REPORT':'CONVERTED', $tot['auto'], $tot['semi'], $tot['manual']);
