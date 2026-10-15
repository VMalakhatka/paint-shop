<?php
// Real SQL persistence and CAS semantics in an isolated SQLite database; no production writes.
require __DIR__ . '/purchase-export.php';
define('ARRAY_A', 'ARRAY_A');
function get_option($key) { return $key === 'lps_purchase_drafts_schema' ? '1' : false; }
function current_time($format, $gmt=false) { return '2026-10-15 12:00:00'; }
class DraftTestDatabase {
    public string $prefix = 'wp_';
    public PDO $db;
    function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->exec('CREATE TABLE wp_lps_purchase_drafts (token TEXT PRIMARY KEY, owner_id INTEGER, revision INTEGER, title TEXT, state_json TEXT, created_at TEXT, updated_at TEXT)');
    }
    function prepare($sql,...$args) {
        $i=0; return preg_replace_callback('/%[sd]/', function($m) use (&$i,$args) { $v=$args[$i++]; return $m[0]==='%d'?(string)(int)$v:$this->db->quote($v); },$sql);
    }
    function insert($table,$data) {
        $st=$this->db->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
        $st->execute(array_values($data)); return $st->rowCount();
    }
    function query($sql) { return $this->db->exec($sql); }
    function get_row($sql,$type) { return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: null; }
    function get_results($sql,$type) { return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
}
require __DIR__ . '/../inc/purchase-drafts.php';
$wpdb = new DraftTestDatabase(); $user=1;
$draft=$familyTemplate; $draft['complete']=true; $draft['previewVersion']=12;
$draft['scenario']=['id'=>1,'name'=>'Kreul','version'=>2];
$draft['groupsRevision']='saved-groups'; $draft['transitGenerationId']=null;
$items=lps_purchase_calculate_network($draft);
$token=str_repeat('a',32);
check(lps_purchase_draft_save($token,$draft,$items,0)===1,'Complete draft persisted');
$GLOBALS['sessions']=[];
$scenario['status']='archived'; $revision='changed';
$opened=lps_purchase_draft_open($token);
check($opened['revision']===1 && $opened['items']==$items,'Draft survives cache expiry and scenario changes without recalculation');
check(count(lps_purchase_drafts_list())===1,'Owner can list saved draft');
$user=2;
check(lps_purchase_draft_load($token)===null && lps_purchase_drafts_list()===[],'Other user cannot read or list draft');
rejected(fn()=>lps_purchase_draft_open($token),'Foreign draft cannot be opened');
rejected(fn()=>lps_purchase_draft_save($token,$draft,$items,1),'Foreign user cannot update draft');
$user=1;
$saved=lps_purchase_session($token);
check(lps_purchase_draft_save($token,$saved,$items,1)===2,'Atomic matching revision update');
rejected(fn()=>lps_purchase_draft_save($token,$saved,$items,1),'Stale tab rejected instead of overwriting edits');
check(lps_purchase_draft_load($token)['draftRevision']===2,'Rejected stale save retains latest revision');
rejected(fn()=>lps_purchase_adjust($token,'TEST-010',[],1),'Adjustment rejects stale browser revision before changing data');
$adjusted=lps_purchase_adjust($token,'TEST-010',[],2);
check($adjusted['draftRevision']===3,'Accepted edit is persisted automatically');
$GLOBALS['sessions']=[];
check(lps_purchase_draft_open($token)['items']==$adjusted['items'],'Reload retains edited results');
$graph['revision']='live-recipe-changed';
$_POST=['token'=>$token,'revision'=>3,'format'=>'csv'];
$actions['admin_post_lps_purchase_export']();
check(count($exported)===1,'Saved export uses pinned recipe and results after live recipe changes');
$_POST['revision']=2;
rejected(fn()=>$actions['admin_post_lps_purchase_export'](),'Stale-tab export is rejected');
$partial=$draft; $partial['complete']=false;
rejected(fn()=>lps_purchase_draft_save(str_repeat('b',32),$partial,[],0),'Incomplete calculations never become saved orders');
$new=str_repeat('c',32); lps_purchase_draft_save($new,$draft,$items,0);
check(count(lps_purchase_drafts_list())===2 && lps_purchase_draft_load($token)['draftRevision']===3,'New calculation creates a separate draft');
echo "PASS: durable drafts, cache expiry, frozen snapshots, owner isolation, atomic revision conflicts and saved adjustments\n";
