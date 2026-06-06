<?php
// restore.php — drive UpdraftPlus' OWN restorer headlessly for files
// (themes+plugins+uploads+others). Run with: wp eval-file restore.php
// The backup files must already be in wp-content/updraft/. UpdraftPlus must be
// active. DB is imported separately (headless UpdraftPlus DB stage is unreliable).
if (!defined('UPDRAFTPLUS_DIR')) { echo "NO_UPDRAFTPLUS\n"; return; }
if (!class_exists('Updraft_Restorer')) require_once(UPDRAFTPLUS_DIR.'/restorer.php');
UpdraftPlus_Backup_History::rebuild();          // scan the dropped-in files into history
$hist = UpdraftPlus_Backup_History::get_history();
if (empty($hist)) { echo "NO_HISTORY\n"; return; }
$ts = array_keys($hist)[0];
$backup_set = UpdraftPlus_Backup_History::get_history($ts);
$backup_set['timestamp'] = $ts;
add_filter('filesystem_method', function(){ return 'direct'; });
if (!function_exists('WP_Filesystem')) require_once(ABSPATH.'wp-admin/includes/file.php');
WP_Filesystem();
$opts = array(
  'updraft_encryptionphrase' => '', 'delete_during_restore' => false,
  'updraft_restorer_replacesiteurl' => false,
  'include_unspecified_plugins' => true, 'include_unspecified_themes' => true,
  'include_unspecified_uploads' => true, 'include_unspecified_others' => true,
);
@require_once(UPDRAFTPLUS_DIR.'/includes/updraft-restorer-skin.php');
$skin = class_exists('Updraft_Restorer_Skin') ? new Updraft_Restorer_Skin() : null;
$restorer = new Updraft_Restorer($skin, $backup_set, false, $opts);
// value=type (NOT 1 — array_flip collapse bug that silently restores nothing)
$entities = array('plugins'=>'plugins','themes'=>'themes','uploads'=>'uploads','others'=>'others');
$res = $restorer->perform_restore($entities, $opts);
echo "RESTORE_RES=".(is_wp_error($res) ? ('ERR:'.$res->get_error_message()) : var_export($res, true))."\n";
