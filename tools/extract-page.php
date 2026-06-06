<?php
/**
 * Extract Elementor _elementor_data of one post into native post_content
 * (Gutenberg blocks).
 *
 * Usage: wp eval-file tools/extract-page.php <post_id> [--apply]
 *   without --apply  => dry-run, prints the composed content
 *   with    --apply  => writes it into post_content via wp_update_post
 */
$args = $GLOBALS['argv'] ?? array();
// wp eval-file passes positional args; pick them up after the script name
$pos = array();
foreach ($args as $a) {
    if ($a === '--apply') { $APPLY = true; continue; }
    if (is_numeric($a)) $pos[] = (int) $a;
}
$APPLY   = $APPLY ?? false;
$post_id = $pos[0] ?? 0;
if (!$post_id) { fwrite(STDERR, "missing post_id\n"); return; }

$data = get_post_meta($post_id, '_elementor_data', true);
$json = is_string($data) ? json_decode($data, true) : $data;
if (!is_array($json)) { fwrite(STDERR, "#$post_id: no _elementor_data\n"); return; }

$blocks = array();
$walk = function ($els) use (&$walk, &$blocks) {
    foreach ((array) $els as $el) {
        $wt = $el['widgetType'] ?? ($el['settings']['widgetType'] ?? null);
        $s  = $el['settings'] ?? array();
        if ($wt === 'html') {
            $html = trim((string) ($s['html'] ?? ''));
            if ($html !== '') $blocks[] = "<!-- wp:html -->\n" . $html . "\n<!-- /wp:html -->";
        } elseif ($wt === 'shortcode') {
            $sc = trim((string) ($s['shortcode'] ?? ''));
            if ($sc !== '') $blocks[] = "<!-- wp:shortcode -->" . $sc . "<!-- /wp:shortcode -->";
        } elseif ($wt === 'text-editor') {
            $ed = trim((string) ($s['editor'] ?? ''));
            if ($ed !== '') $blocks[] = "<!-- wp:html -->\n" . $ed . "\n<!-- /wp:html -->";
        }
        // NOTE: visual widgets (heading, image-box, accordion, ...) are skipped on
        // purpose — they have no source HTML. See README "the limitation".
        if (!empty($el['elements'])) $walk($el['elements']);
    }
};
$walk($json);
$content = implode("\n\n", $blocks);

if ($APPLY) {
    wp_update_post(array('ID' => $post_id, 'post_content' => $content));
    echo "#$post_id APPLIED: " . count($blocks) . " blocks, " . strlen($content) . "B\n";
} else {
    echo "=== #$post_id DRY-RUN: " . count($blocks) . " blocks, " . strlen($content) . "B ===\n";
    echo mb_substr($content, 0, 600) . "\n...\n";
}
