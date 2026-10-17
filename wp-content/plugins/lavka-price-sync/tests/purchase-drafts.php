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
        // Production uses a 16 MiB packet limit; oversized inserts must fail here too.
        if (strlen($data['state_json']) > 16777216) return false;
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

// Full-size history and results previously exceeded the production packet limit.
$large=$draft;
$large['history']=array_fill(0,1800,['description'=>str_repeat('Товар Kreul / snapshot ',350),'quantity'=>1.0,'nullable'=>null,'sku'=>'KR-001']);
$large['draftResults']=$items;
$raw=wp_json_encode($large,JSON_PRESERVE_ZERO_FRACTION);
check(strlen($raw)>20000000,'Regression fixture exceeds the failed production payload');
check($wpdb->insert('wp_lps_purchase_drafts',['state_json'=>$raw])===false,'Old uncompressed write fails the packet regression');
$largeToken=str_repeat('d',32);
check(lps_purchase_draft_save($largeToken,$large,$items,0)===1,'Large draft saved under 16 MiB packet limit');
$stored=$wpdb->get_row($wpdb->prepare('SELECT state_json FROM wp_lps_purchase_drafts WHERE token=%s',$largeToken),ARRAY_A)['state_json'];
check(strlen($stored)<1048576,'Large snapshot compressed before database insert');
check(lps_purchase_draft_decode($stored)===$large,'Every field, Unicode, numeric type and null preserved');
$GLOBALS['sessions']=[];
check(lps_purchase_draft_open($largeToken)['items']===$items,'Large draft reopens after cache expiry');
$savedLarge=lps_purchase_draft_load($largeToken);
check(lps_purchase_draft_save($largeToken,$savedLarge,$items,1)===2,'Large draft revision update succeeds');
rejected(fn()=>lps_purchase_draft_save($largeToken,$savedLarge,$items,1),'Large draft stale revision rejected');
check(lps_purchase_draft_decode($raw)===$large,'Legacy uncompressed large snapshots remain readable');
$envelope=json_decode($stored,true);
$bad=$envelope;$bad['sha256']=str_repeat('0',64);
check(lps_purchase_draft_decode(json_encode($bad))===null,'Corrupt checksum cannot become a usable order');
$bad=$envelope;$bad['bytes']--;
check(lps_purchase_draft_decode(json_encode($bad))===null,'Truncated decompression rejected');
$bad=$envelope;$bad['bytes']=268435457;
check(lps_purchase_draft_decode(json_encode($bad))===null,'Decompression size bound enforced');
$bad=$envelope;$bad['payload']='not base64!';
check(lps_purchase_draft_decode(json_encode($bad))===null,'Corrupt encoding rejected');
$bad=$envelope;$bad['draftStorageVersion']=2;
check(lps_purchase_draft_decode(json_encode($bad))===null,'Unknown storage version rejected');
$wpdb->query($wpdb->prepare('UPDATE wp_lps_purchase_drafts SET state_json=%s WHERE token=%s',json_encode($bad),$largeToken));
rejected(fn()=>lps_purchase_draft_open($largeToken),'Corrupt stored draft cannot open or export');
echo "PASS: large drafts below packet limit, exact compression roundtrip, legacy JSON, CAS and corruption checks\n";
