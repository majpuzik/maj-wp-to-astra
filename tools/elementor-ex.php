<?php
/**
 * elementor-ex.php — one entry point. Tells you what's convertible, converts what can
 * be converted automatically, and writes a manual-completion guide for the rest.
 * Deterministic, no AI.
 *
 *   wp eval-file tools/elementor-ex.php           # explanation + the two choices
 *   wp eval-file tools/elementor-ex.php test      # (1) ANALYZE ONLY — changes nothing
 *   wp eval-file tools/elementor-ex.php convert   # (2) CONVERT the auto parts + write
 *                                                 #     MANUAL-TODO.md for the rest
 *
 * (positional arg — wp-cli eats --flags before the script sees them.)
 */
global $wpdb;
$argv = $GLOBALS['argv'] ?? array();
$MODE = in_array('convert', $argv) ? 'convert' : (in_array('test', $argv) ? 'test' : 'help');

if ($MODE === 'help') {
    echo <<<TXT

  elementor-ex — remove Elementor, keep the content native.

  It reads each Elementor page, and for every widget either converts it to a native
  Gutenberg block (deterministically) or, if it can't, leaves a labelled placeholder
  carrying the widget's data so you can finish it by hand. Nothing is silently lost.

  Choose:
    wp eval-file tools/elementor-ex.php test      (1) ANALYZE ONLY — per-page report of
                                                       what converts automatically vs what
                                                       needs a hand. Changes nothing.
    wp eval-file tools/elementor-ex.php convert   (2) CONVERT — write the native blocks,
                                                       remove Elementor meta, and write a
                                                       MANUAL-TODO.md guide listing every
                                                       item to finish (with its data).

  ALWAYS work on a copy. Run 'test' first. Verify with tools/visual-diff.js afterwards.

TXT;
    return;
}

$esc = function ($s) { return function_exists('esc_html') ? esc_html((string) $s) : htmlspecialchars((string) $s); };
$url = function ($s) { return function_exists('esc_url') ? esc_url((string) $s) : (string) $s; };
$lvl = function ($h) { return (preg_match('/^h([1-6])$/', strtolower((string) $h), $m)) ? (int) $m[1] : 2; };

// returns array(class, block, guide|null) — class auto|semi|manual; guide = how to finish
$mapw = function ($el) use ($esc, $url, $lvl) {
    $wt = $el['widgetType'] ?? null; $s = $el['settings'] ?? array();
    switch ($wt) {
        case 'html':        $v = trim((string)($s['html'] ?? '')); return $v===''?null:array('auto',"<!-- wp:html -->\n$v\n<!-- /wp:html -->",null);
        case 'shortcode':   $v = trim((string)($s['shortcode'] ?? '')); return $v===''?null:array('auto',"<!-- wp:shortcode -->$v<!-- /wp:shortcode -->",null);
        case 'text-editor': $v = trim((string)($s['editor'] ?? '')); return $v===''?null:array('auto',"<!-- wp:html -->\n$v\n<!-- /wp:html -->",null);
        case 'heading':     $t=$esc($s['title']??''); if($t==='')return null; $n=$lvl($s['header_size']??'h2'); return array('auto',"<!-- wp:heading {\"level\":$n} -->\n<h$n>$t</h$n>\n<!-- /wp:heading -->",null);
        case 'image':       $u=$s['image']['url']??''; if($u==='')return null; return array('auto',"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"".$url($u)."\" alt=\"".$esc($s['image']['alt']??'')."\"/></figure>\n<!-- /wp:image -->",null);
        case 'button':      $t=$esc($s['text']??'Button'); return array('auto',"<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"".$url($s['link']['url']??'#')."\">$t</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->",null);
        case 'icon-list':   $li=''; foreach((array)($s['icon_list']??array()) as $it){$x=$esc($it['text']??''); if($x!=='')$li.="<li>$x</li>";} return $li===''?null:array('auto',"<!-- wp:list -->\n<ul class=\"wp-block-list\">$li</ul>\n<!-- /wp:list -->",null);
        case 'divider':     return array('auto',"<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",null);
        case 'spacer':      $h=(int)($s['space']['size']??50); return array('auto',"<!-- wp:spacer {\"height\":\"{$h}px\"} -->\n<div style=\"height:{$h}px\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->",null);
        case 'video':       $u=$s['youtube_url']??($s['vimeo_url']??($s['hosted_url']['url']??'')); return $u===''?null:array('auto',"<!-- wp:embed {\"url\":\"".$url($u)."\",\"type\":\"video\"} -->\n<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">".$url($u)."</div></figure>\n<!-- /wp:embed -->",null);
        // ⚠️ semi — converted best-effort, but verify; guide explains the manual finish
        case 'icon':        $ic=$esc($s['selected_icon']['value']??($s['icon']??'')); return array('semi',"<!-- wp:html -->\n<span class=\"$ic\"></span>\n<!-- /wp:html -->","Icon widget → add an Icon block, or load the icon font for '$ic'.");
        case 'nav-menu':    $mn=$esc($s['menu']??''); return array('semi',"<!-- wp:html --><!-- elementor nav-menu '$mn' --><!-- /wp:html -->","Nav-menu → add a wp:navigation block pointing at menu '$mn'.");
        case 'posts': case 'loop-grid': case 'portfolio':
                            $pt=$esc($s['posts_post_type']??($s['query_post_type']??'post')); $pp=(int)($s['posts_per_page']??6);
                            return array('semi',"<!-- wp:html --><!-- elementor $wt --><!-- /wp:html -->","'$wt' → add a wp:query loop: post_type=$pt, per_page=$pp.");
        case 'form':        $fl=array(); foreach((array)($s['form_fields']??array()) as $ff){$l=trim((string)($ff['field_label']??$ff['field_type']??'')); if($l!=='')$fl[]=$esc($l);} $f=$fl?implode(', ',$fl):'?';
                            return array('semi',"<!-- wp:html --><!-- elementor form --><!-- /wp:html -->","Form → rebuild as Contact Form 7 with fields: $f.");
        case 'image-box': case 'icon-box':
                            $img=$s['image']['url']??''; $h=$esc($s['title_text']??($s['title']??'')); $d=$esc($s['description_text']??'');
                            $b="<!-- wp:html -->\n"; if($img)$b.="<figure class=\"wp-block-image\"><img src=\"".$url($img)."\" alt=\"$h\"/></figure>\n"; if($h)$b.="<h3>$h</h3>\n"; if($d)$b.="<p>$d</p>\n"; $b.="<!-- /wp:html -->";
                            return array('semi',$b,"'$wt' → check the auto image+heading+text block; adjust layout/spacing.");
        // ❌ manual — placeholder + the settings, plus a generic instruction
        default:
            if($wt===null) return null;
            $hint=$esc(mb_substr(trim(json_encode($s,JSON_UNESCAPED_UNICODE)),0,90));
            return array('manual',"<!-- wp:html --><!-- elementor '$wt' (manual) --><!-- /wp:html -->","Elementor '$wt' has no native equivalent — rebuild by hand. settings: $hint");
    }
};

$rows = $wpdb->get_results("SELECT DISTINCT p.ID,p.post_title FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE m.meta_key='_elementor_edit_mode' AND m.meta_value='builder' AND p.post_type IN('page','post') AND p.post_status IN('publish','draft') ORDER BY p.ID");

$tot=array('auto'=>0,'semi'=>0,'manual'=>0); $guide=array();
foreach ($rows as $r) {
    $data=get_post_meta($r->ID,'_elementor_data',true); $json=is_string($data)?json_decode($data,true):$data;
    if(!is_array($json)) continue;
    $blocks=array(); $st=array('auto'=>0,'semi'=>0,'manual'=>0); $todo=array();
    $walk=function($els)use(&$walk,&$blocks,&$st,&$todo,$mapw){ foreach((array)$els as $el){ if(!empty($el['widgetType'])){ $m=$mapw($el); if($m){ list($c,$b,$g)=$m; $blocks[]=$b; $st[$c]++; if($g)$todo[]=array($c,$el['widgetType'],$g);} } if(!empty($el['elements']))$walk($el['elements']); } };
    $walk($json);
    foreach($st as $k=>$v)$tot[$k]+=$v;
    if($todo)$guide[]=array($r->ID,$r->post_title,$todo);

    if($MODE==='test'){
        printf("  #%d \"%s\": ✅%d ⚠️%d ❌%d%s\n",$r->ID,$r->post_title,$st['auto'],$st['semi'],$st['manual'], $todo?"  → ".implode(', ',array_unique(array_map(function($t){return $t[1];},$todo))):'');
        continue;
    }
    wp_update_post(array('ID'=>$r->ID,'post_content'=>implode("\n\n",$blocks)));
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s",$r->ID,'_elementor%'));
    printf("  OK #%d \"%s\": ✅%d ⚠️%d ❌%d\n",$r->ID,$r->post_title,$st['auto'],$st['semi'],$st['manual']);
}

echo "\n  TOTAL: ✅ auto $tot[auto]  |  ⚠️ semi $tot[semi] (verify)  |  ❌ manual $tot[manual]\n";

if($MODE==='convert' && $guide){
    $md="# Manual completion — what elementor-ex couldn't fully convert\n\nEach item below was left as a placeholder in the page. Finish it by hand.\n";
    foreach($guide as $g){ list($id,$title,$todo)=$g; $md.="\n## #$id — $title\n\n"; foreach($todo as $t){ $tag=$t[0]==='manual'?'❌':'⚠️'; $md.="- $tag **{$t[1]}** — {$t[2]}\n"; } }
    $f=trailingslashit(wp_upload_dir()['basedir'])."elementor-ex-MANUAL-TODO.md"; @file_put_contents($f,$md);
    echo "\n  → manual guide written: ".str_replace(ABSPATH,'',$f)." (".count($guide)." pages with TODOs)\n";
} elseif($MODE==='test'){
    echo "  (run with 'convert' to apply + get a MANUAL-TODO.md guide)\n";
}
