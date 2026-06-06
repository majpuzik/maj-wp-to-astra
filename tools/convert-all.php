<?php
/**
 * Bulk-convert every Elementor page/post to native content and remove its
 * Elementor meta.
 *
 * Usage: wp eval-file tools/convert-all.php [--dry]
 *   --dry  => report only, change nothing
 *
 * For each page it: extracts html/shortcode/text from _elementor_data into
 * native post_content, then deletes all _elementor_* postmeta so the_content()
 * renders natively. Run analyze-elementor.php first to confirm the site is a fit.
 */
global $wpdb;
$DRY = in_array('--dry', $GLOBALS['argv'] ?? array());
$rows = $wpdb->get_results("
  SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_status
  FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
  WHERE m.meta_key = '_elementor_edit_mode' AND m.meta_value = 'builder'
    AND p.post_type IN ('page','post')
  ORDER BY p.ID");

$extract = function ($post_id) {
    $data = get_post_meta($post_id, '_elementor_data', true);
    $json = is_string($data) ? json_decode($data, true) : $data;
    if (!is_array($json)) return null;
    $blocks = array();
    $walk = function ($els) use (&$walk, &$blocks) {
        foreach ((array) $els as $el) {
            $wt = $el['widgetType'] ?? ($el['settings']['widgetType'] ?? null);
            $s  = $el['settings'] ?? array();
            if      ($wt === 'html')        { $h = trim((string) ($s['html'] ?? '')); if ($h !== '') $blocks[] = "<!-- wp:html -->\n$h\n<!-- /wp:html -->"; }
            elseif  ($wt === 'shortcode')   { $sc = trim((string) ($s['shortcode'] ?? '')); if ($sc !== '') $blocks[] = "<!-- wp:shortcode -->$sc<!-- /wp:shortcode -->"; }
            elseif  ($wt === 'text-editor') { $e = trim((string) ($s['editor'] ?? '')); if ($e !== '') $blocks[] = "<!-- wp:html -->\n$e\n<!-- /wp:html -->"; }
            if (!empty($el['elements'])) $walk($el['elements']);
        }
    };
    $walk($json);
    return implode("\n\n", $blocks);
};

foreach ($rows as $r) {
    $content = $extract($r->ID);
    if ($DRY) {
        printf("  [DRY] #%d %s/%s \"%s\" => %dB\n", $r->ID, $r->post_type, $r->post_status, $r->post_title, strlen((string) $content));
        continue;
    }
    // 1) set native content
    wp_update_post(array('ID' => $r->ID, 'post_content' => (string) $content));
    // 2) delete all _elementor_* meta
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
        $r->ID, '_elementor%'));
    printf("  OK #%d \"%s\" => %dB content, %d elementor-meta deleted\n",
        $r->ID, $r->post_title, strlen((string) $content), $deleted);
}
