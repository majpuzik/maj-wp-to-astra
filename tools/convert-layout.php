<?php
/**
 * convert-layout.php — like convert-all.php, but ALSO reproduces Elementor's
 * container layout (the boxed/full-width box model) that plain extraction loses.
 *
 * Why: an Elementor container with no custom settings still boxes its content to
 * the global "Content Width" (default 1140px) via Elementor's frontend.css
 * (`.e-con-inner{max-width:…}`). Remove Elementor and that wrapper is gone, so the
 * content expands full-viewport (the homepage-hero regression). This tool keeps the
 * extracted content clean but wraps each top-level Elementor container in
 * `<div class="exl-con exl-boxed|exl-full">` and writes a tiny `exl-layout.css`
 * (+ an mu-plugin to enqueue it) that reproduces just the boxing — not all of
 * Elementor's CSS. Deterministic, no AI.
 *
 *   wp eval-file tools/convert-layout.php [--dry]
 *
 * CAVEAT (verify with tools/visual-diff.js): if your content already self-boxes
 * (its own CSS sets the column width and full-bleed sections), this wrapper is
 * redundant and can FIGHT full-bleed bands. Boxed-by-default suits raw html/text
 * content; sites with a hand-rolled `.section/.container` system may want
 * convert-all.php + a targeted fix instead. There is no one-size flag — measure.
 */
global $wpdb;
$DRY = in_array('--dry', $GLOBALS['argv'] ?? array());

// Global content width: Elementor kit "container_width", else the 1140 default.
$kit = (int) get_option('elementor_active_kit_id');
$cw  = 1140;
if ($kit) {
    $ks = get_post_meta($kit, '_elementor_page_settings', true);
    if (is_array($ks) && !empty($ks['container_width']['size'])) $cw = (int) $ks['container_width']['size'];
}

$rows = $wpdb->get_results("
  SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
  JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
  WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder'
    AND p.post_type IN ('page','post') ORDER BY p.ID");

// is this top-level container full-width / stretched? else boxed (Elementor default)
$is_full = function ($s) {
    $s = (array) $s;
    if (($s['content_width'] ?? '') === 'full') return true;          // flexbox container
    if (($s['layout'] ?? '') === 'full_width') return true;           // legacy section
    if (!empty($s['stretch_section'])) return true;                  // stretched section
    return false;
};

// extract a node's widget content (same mapping as convert-all.php)
$content_of = function ($els) use (&$content_of) {
    $out = array();
    foreach ((array) $els as $el) {
        $wt = $el['widgetType'] ?? null; $s = $el['settings'] ?? array();
        if      ($wt === 'html')        { $h = trim((string) ($s['html'] ?? '')); if ($h !== '') $out[] = "<!-- wp:html -->\n$h\n<!-- /wp:html -->"; }
        elseif  ($wt === 'shortcode')   { $c = trim((string) ($s['shortcode'] ?? '')); if ($c !== '') $out[] = "<!-- wp:shortcode -->$c<!-- /wp:shortcode -->"; }
        elseif  ($wt === 'text-editor') { $e = trim((string) ($s['editor'] ?? '')); if ($e !== '') $out[] = "<!-- wp:html -->\n$e\n<!-- /wp:html -->"; }
        if (!empty($el['elements'])) { $c = $content_of($el['elements']); if ($c !== '') $out[] = $c; }
    }
    return implode("\n\n", $out);
};

$compose = function ($post_id) use ($content_of, $is_full) {
    $data = get_post_meta($post_id, '_elementor_data', true);
    $json = is_string($data) ? json_decode($data, true) : $data;
    if (!is_array($json)) return null;
    $blocks = array();
    foreach ($json as $top) {                       // each top-level container/section
        $inner = $content_of(array($top));
        if ($inner === '') continue;
        $cls = 'exl-con ' . ($is_full($top['settings'] ?? array()) ? 'exl-full' : 'exl-boxed');
        $blocks[] = "<!-- wp:html -->\n<div class=\"$cls\">\n$inner\n</div>\n<!-- /wp:html -->";
    }
    return implode("\n\n", $blocks);
};

foreach ($rows as $r) {
    $content = $compose($r->ID);
    if ($DRY) { printf("  [DRY] #%d \"%s\" => %dB\n", $r->ID, $r->post_title, strlen((string) $content)); continue; }
    wp_update_post(array('ID' => $r->ID, 'post_content' => (string) $content));
    $del = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s", $r->ID, '_elementor%'));
    printf("  OK #%d \"%s\" => %dB, %d meta deleted\n", $r->ID, $r->post_title, strlen((string) $content), $del);
}

if (!$DRY) {
    // ship the boxing CSS + an mu-plugin that enqueues it
    $css = ":root{--exl-content-width:{$cw}px}\n"
         . ".exl-con{width:100%}\n"
         . ".exl-boxed{max-width:var(--exl-content-width);margin-inline:auto;padding-inline:10px;box-sizing:border-box}\n"
         . ".exl-full{max-width:none}\n";
    $mu = WP_CONTENT_DIR . '/mu-plugins';
    if (!is_dir($mu)) @mkdir($mu, 0755, true);
    @file_put_contents($mu . '/exl-layout.css', $css);
    $php = "<?php\n/* Plugin Name: elementor-ex layout (boxing reproduced from removed Elementor container) */\n"
         . "add_action('wp_enqueue_scripts',function(){\$f=WP_CONTENT_DIR.'/mu-plugins/exl-layout.css';"
         . "if(file_exists(\$f))wp_enqueue_style('exl-layout',content_url('mu-plugins/exl-layout.css'),array(),filemtime(\$f));},99);\n";
    @file_put_contents($mu . '/exl-layout.php', $php);
    echo "  wrote mu-plugins/exl-layout.css (content-width {$cw}px) + exl-layout.php enqueue\n";
}
