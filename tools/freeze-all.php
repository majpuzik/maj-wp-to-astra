<?php
/**
 * freeze-all.php — the literal pixel-1:1 path. Keeps Elementor's OWN rendered markup
 * AND its compiled CSS, so pages look byte-identical WITHOUT the plugin. Dynamic
 * shortcodes stay dynamic (re-tokenized before capture). Deterministic, no AI.
 *
 *   wp eval-file tools/freeze-all.php
 *
 * ORDER MATTERS (learned the hard way, measured): deleting `_elementor_data` makes
 * Elementor delete its own compiled CSS files (uploads/elementor/css/post-*.css). So
 * this script preserves the CSS FIRST, then captures HTML, then deletes the meta:
 *   1. force-(re)generate every page's CSS + the kit CSS
 *   2. copy frontend.min.css + uploads/elementor/css/post-*.css into an mu-plugin and
 *      write an enqueue loader (frontend + kit + per-page)
 *   3. capture each page's rendered HTML (shortcodes left literal) -> post_content
 *   4. delete _elementor_* meta
 * After it runs, deactivate+delete the Elementor plugin and verify with
 * tools/visual-diff.js. With ONLY frontend.min.css (skipping the post-*.css) a test
 * site matched ~87%; the per-post/kit CSS is the rest — don't skip it.
 *
 * Trade-off vs convert-all.php: output keeps elementor-* classes and Elementor's CSS
 * (verbose). Use when literal parity is required; convert-all.php is cleaner otherwise.
 */
if (!class_exists('\Elementor\Plugin')) { fwrite(STDERR, "Elementor must be ACTIVE to freeze its render/CSS\n"); return; }
global $wpdb;

$mu = WP_CONTENT_DIR . '/mu-plugins/exl-frozen';
@wp_mkdir_p($mu);
$up = wp_upload_dir();
$css_dir = $up['basedir'] . '/elementor/css';
$css_url = $up['baseurl'] . '/elementor/css';

$rows = $wpdb->get_results("
  SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
  JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
  WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder'
    AND p.post_type IN ('page','post') AND p.post_status = 'publish'
  ORDER BY p.ID");

$kit = (int) get_option('elementor_active_kit_id');

// 1) (re)generate CSS files for every page + the kit, then copy them out BEFORE we
//    touch any meta (meta deletion would wipe them).
$gen = function ($id) {
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        $c = \Elementor\Core\Files\CSS\Post::create($id); $c->update();
    }
};
foreach ($rows as $r) $gen($r->ID);
if ($kit) $gen($kit);

@copy(WP_CONTENT_DIR . '/plugins/elementor/assets/css/frontend.min.css', $mu . '/frontend.min.css');
$copied = 0;
foreach (glob($css_dir . '/post-*.css') as $f) { if (@copy($f, $mu . '/' . basename($f))) $copied++; }
printf("  preserved CSS: frontend.min.css + %d post-*.css (kit #%d)\n", $copied, $kit);

// 2) enqueue loader as a real mu-plugin
$php = "<?php\n/* Plugin Name: exl-frozen — Elementor frontend+kit+per-post CSS kept after plugin removal */\n"
     . "add_action('wp_enqueue_scripts',function(){\n"
     . "  \$d=WP_CONTENT_DIR.'/mu-plugins/exl-frozen'; \$u=content_url('mu-plugins/exl-frozen');\n"
     . "  \$add=function(\$h,\$f,\$dep=array()){\$p=\$GLOBALS['exl_d'].'/'.\$f; if(file_exists(\$p)) wp_enqueue_style(\$h,\$GLOBALS['exl_u'].'/'.\$f,\$dep,filemtime(\$p));};\n"
     . "  \$GLOBALS['exl_d']=\$d; \$GLOBALS['exl_u']=\$u;\n"
     . "  \$add('exl-frontend','frontend.min.css');\n"
     . "  " . ($kit ? "\$add('exl-kit','post-{$kit}.css',array('exl-frontend'));\n" : "")
     . "  if(is_singular()){\$id=get_queried_object_id(); \$f='post-'.\$id.'.css'; if(file_exists(\$d.'/'.\$f)) wp_enqueue_style('exl-post-'.\$id,\$u.'/'.\$f,array('exl-frontend'),filemtime(\$d.'/'.\$f));}\n"
     . "},5);\n";
@file_put_contents(WP_CONTENT_DIR . '/mu-plugins/exl-frozen.php', $php);

// 3) capture each page's rendered HTML (shortcodes stay literal -> dynamic), 4) delete meta
$frontend = \Elementor\Plugin::$instance->frontend;
$saved = $GLOBALS['shortcode_tags'];
foreach ($rows as $r) {
    $GLOBALS['shortcode_tags'] = array();
    $html = $frontend->get_builder_content_for_display($r->ID, true);
    $GLOBALS['shortcode_tags'] = $saved;
    if (trim($html) === '') { printf("  SKIP #%d (empty)\n", $r->ID); continue; }
    wp_update_post(array('ID' => $r->ID, 'post_content' => "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->"));
    $del = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s", $r->ID, '_elementor%'));
    printf("  FROZE #%d \"%s\" => %dB, %d meta\n", $r->ID, $r->post_title, strlen($html), $del);
}
$GLOBALS['shortcode_tags'] = $saved;
echo "  done — deactivate+delete Elementor, then verify with tools/visual-diff.js\n";
