<?php
/**
 * Inventory of Elementor pages + a histogram of the widget TYPES used site-wide.
 * Usage: wp eval-file tools/analyze-elementor.php
 *
 * Use this to decide fit: if the widgets are mostly html / shortcode / text-editor,
 * the site is a great fit for direct de-Elementorization (convert-all.php). If it is
 * mostly visual widgets (heading, image-box, accordion, tabs, ...) this is a manual
 * rebuild — see README "the limitation".
 */
global $wpdb;
$rows = $wpdb->get_results("
  SELECT p.ID, p.post_title, p.post_type, p.post_status, p.post_name
  FROM {$wpdb->posts} p
  JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
  WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder'
    AND p.post_type NOT IN ('revision')
  ORDER BY p.post_type, p.post_status, p.ID");

$freq = array(); $total = 0;
echo "=== ELEMENTOR PAGES (" . count($rows) . ") ===\n";
foreach ($rows as $r) {
    $data = get_post_meta($r->ID, '_elementor_data', true);
    $json = is_string($data) ? json_decode($data, true) : $data;
    $widgets = array();
    $walk = function ($els) use (&$walk, &$widgets) {
        foreach ((array) $els as $el) {
            $wt = $el['widgetType'] ?? ($el['settings']['widgetType'] ?? null);
            if ($wt) $widgets[] = $wt;
            if (!empty($el['elements'])) $walk($el['elements']);
        }
    };
    $walk($json);
    foreach ($widgets as $w) { $freq[$w] = ($freq[$w] ?? 0) + 1; $total++; }
    $uniq = array_count_values($widgets);
    $parts = array();
    foreach ($uniq as $k => $v) $parts[] = "$k x$v";
    printf("  #%d [%s/%s] \"%s\" (/%s) — %d widgets: %s\n",
        $r->ID, $r->post_type, $r->post_status, mb_substr($r->post_title, 0, 40),
        $r->post_name, count($widgets), implode(", ", $parts));
}
echo "\n=== WIDGET TYPES (" . count($freq) . " types, $total instances) ===\n";
arsort($freq);
foreach ($freq as $w => $c) printf("  %-30s %dx\n", $w, $c);
echo "\nVerdict: if widgets are mostly html/shortcode/text-editor, this site is a fit\n";
echo "for direct de-Elementorization (convert-all.php). Visual widgets => manual rebuild.\n";
