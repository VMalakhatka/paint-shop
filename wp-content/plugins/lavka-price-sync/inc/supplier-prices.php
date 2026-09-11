<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/supplier-price-model.php';

function lps_sp_table(): string { global $wpdb; return $wpdb->prefix . 'lps_supplier_prices'; }
function lps_sp_heads(): string { global $wpdb; return $wpdb->prefix . 'lps_supplier_price_heads'; }
function lps_sp_json($data): string { return wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function lps_sp_decode($json): array { return json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR); }
function lps_sp_scope(string $supplier, string $database): string { return hash('sha256', $database . "\n" . $supplier); }

function lps_sp_install(): void {
    if (get_option('lps_supplier_prices_schema') === '1') return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate(); $t = lps_sp_table(); $h = lps_sp_heads();
    dbDelta("CREATE TABLE $t (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        scope_key char(64) NOT NULL,
        supplier varchar(191) NOT NULL,
        source_database varchar(64) NOT NULL,
        filename varchar(255) NOT NULL,
        checksum char(64) NOT NULL,
        original_base64 longtext NOT NULL,
        config_json longtext NOT NULL,
        preview_json longtext NOT NULL,
        catalog_hash char(64) NOT NULL DEFAULT '',
        approval_hash char(64) NOT NULL DEFAULT '',
        base_id bigint unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'uploaded',
        created_by bigint unsigned NOT NULL,
        created_at datetime NOT NULL,
        activated_by bigint unsigned NOT NULL DEFAULT 0,
        activated_at datetime NULL,
        PRIMARY KEY  (id),
        KEY scope_status (scope_key,status)
    ) ENGINE=InnoDB $charset;");
    if ($wpdb->last_error) throw new RuntimeException('STORAGE_ERROR');
    dbDelta("CREATE TABLE $h (
        scope_key char(64) NOT NULL,
        version_id bigint unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (scope_key)
    ) ENGINE=InnoDB $charset;");
    if ($wpdb->last_error) throw new RuntimeException('STORAGE_ERROR');
    update_option('lps_supplier_prices_schema', '1', false);
}

function lps_sp_version(int $id): array {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . lps_sp_table() . ' WHERE id=%d', $id), ARRAY_A);
    if (!$r) throw new RuntimeException('VERSION_NOT_FOUND');
    return $r;
}

function lps_sp_head(string $scope): int {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare('SELECT version_id FROM ' . lps_sp_heads() . ' WHERE scope_key=%s', $scope));
}

function lps_sp_catalog(string $supplier, string $database): array {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT c.sku,c.primary_barcode AS gtin,c.generation_id
        FROM folio_product_metric_current c
        JOIN folio_product_snapshot_generation g ON g.id=c.generation_id AND g.status='ACTIVE'
        WHERE c.source_database=%s AND BINARY c.current_supplier=BINARY %s
        ORDER BY c.sku,c.primary_barcode,c.generation_id LIMIT 30001", $database, $supplier), ARRAY_A);
    if ($wpdb->last_error || !$rows) throw new RuntimeException('CATALOG_NOT_READY');
    if (count($rows)>30000) throw new RuntimeException('TOO_MANY_ROWS');
    return $rows;
}

function lps_sp_temporary(array $version, callable $callback) {
    $path = tempnam(get_temp_dir(), 'lps-price-');
    if (!$path) throw new RuntimeException('STORAGE_ERROR');
    try {
        if (file_put_contents($path, base64_decode($version['original_base64'], true)) === false) throw new RuntimeException('STORAGE_ERROR');
        return $callback($path);
    } finally { unlink($path); }
}

function lps_sp_upload(array $file, string $supplier, string $database): int {
    global $wpdb;
    if (!in_array($database, ['Paint_Ua','Paint_Rus'], true) || $supplier === '' || strlen($supplier)>191) throw new RuntimeException('INVALID_SETTINGS');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('UPLOAD_ERROR');
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('XLSX_ONLY');
    lps_sp_xlsx($file['tmp_name']); // Validate before storing, without extracting into the web root.
    lps_sp_catalog($supplier, $database);
    $bytes = file_get_contents($file['tmp_name']); $hash = hash('sha256', $bytes); $scope=lps_sp_scope($supplier,$database);
    // Repeated uploads reopen the same draft; active versions remain immutable.
    $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . lps_sp_table() . " WHERE scope_key=%s AND checksum=%s AND status IN ('uploaded','draft') ORDER BY id DESC LIMIT 1",$scope,$hash));
    if ($existing) return (int)$existing;
    if ($wpdb->insert(lps_sp_table(), ['scope_key'=>$scope,'supplier'=>$supplier,'source_database'=>$database,
        'filename'=>sanitize_file_name($file['name']),'checksum'=>$hash,'original_base64'=>base64_encode($bytes),
        'config_json'=>'{}','preview_json'=>'{}','created_by'=>get_current_user_id(),'created_at'=>current_time('mysql', true)]) === false) throw new RuntimeException('STORAGE_ERROR');
    return (int)$wpdb->insert_id;
}

function lps_sp_build(int $id, array $input): void {
    global $wpdb;
    $v=lps_sp_version($id);
    if (!in_array($v['status'],['uploaded','draft'],true)) throw new RuntimeException('VERSION_CHANGED');
    $config=lps_sp_temporary($v,static fn($p)=>lps_sp_config($input,lps_sp_xlsx($p)['sheets']));
    $catalog=lps_sp_catalog($v['supplier'],$v['source_database']);
    $preview=lps_sp_temporary($v,static fn($p)=>lps_sp_preview(lps_sp_xlsx($p,$config['sheet'])['rows'],$config,$catalog));
    $catalogHash=hash('sha256',lps_sp_json($catalog));
    $base=lps_sp_head($v['scope_key']);
    if ($base) {
        $active=lps_sp_version($base);
        if ($active['checksum']===$v['checksum'] && $active['catalog_hash']===$catalogHash && lps_sp_decode($active['config_json'])===$config) throw new RuntimeException('ALREADY_ACTIVE');
    }
    $approval=hash('sha256',lps_sp_json([$v['checksum'],$config,$preview,$catalogHash,$base]));
    $ok=$wpdb->query($wpdb->prepare('UPDATE '.lps_sp_table()." SET config_json=%s,preview_json=%s,catalog_hash=%s,approval_hash=%s,base_id=%d,status='draft' WHERE id=%d AND status IN ('uploaded','draft')",
        lps_sp_json($config),lps_sp_json($preview),$catalogHash,$approval,$base,$id));
    if ($ok===false) throw new RuntimeException('STORAGE_ERROR');
}

function lps_sp_activate(int $id, string $approval): void {
    global $wpdb;
    $v=lps_sp_version($id); $t=lps_sp_table();$h=lps_sp_heads();
    if ($v['status']==='active' && hash_equals($v['approval_hash'],$approval)) return;
    if ($v['status']!=='draft' || !$approval || !hash_equals($v['approval_hash'],$approval)) throw new RuntimeException('VERSION_CHANGED');
    $config=lps_sp_decode($v['config_json']);$preview=lps_sp_decode($v['preview_json']);
    // Unresolved source identifiers keep absent catalog products in REVIEW.
    // Valid offers can still become active without inferring discontinuation.
    if (!hash_equals($v['catalog_hash'],hash('sha256',lps_sp_json(lps_sp_catalog($v['supplier'],$v['source_database']))))) throw new RuntimeException('CATALOG_CHANGED');
    if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $h (scope_key,version_id) VALUES (%s,0)",$v['scope_key']))===false) throw new RuntimeException('STORAGE_ERROR');
    if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('STORAGE_ERROR');
    try {
        $base=(int)$wpdb->get_var($wpdb->prepare("SELECT version_id FROM $h WHERE scope_key=%s FOR UPDATE",$v['scope_key']));
        $locked=$wpdb->get_row($wpdb->prepare("SELECT status,approval_hash FROM $t WHERE id=%d FOR UPDATE",$id),ARRAY_A);
        if ($locked['status']==='active' && $base===$id) { $wpdb->query('COMMIT'); return; }
        if ($locked['status']!=='draft' || !hash_equals($locked['approval_hash'],$approval) || $base!==(int)$v['base_id']) throw new RuntimeException('VERSION_CHANGED');
        if (!hash_equals($v['catalog_hash'],hash('sha256',lps_sp_json(lps_sp_catalog($v['supplier'],$v['source_database']))))) throw new RuntimeException('CATALOG_CHANGED');
        foreach ($preview['offers'] as &$offer) $offer['versionId']=$id;
        unset($offer);
        if (!$config['full'] && $base) {
            $old=lps_sp_decode(lps_sp_version($base)['preview_json']);
            $keys=[];
            foreach ($preview['offers'] as $offer) $keys[lps_sp_offer_key($offer)]=true;
            foreach ($old['offers'] as $offer) if (!isset($keys[lps_sp_offer_key($offer)])) $preview['offers'][]=$offer;
            foreach ($old['statuses'] as $sku=>$status) if (($preview['statuses'][$sku]??'NOT_IN_PARTIAL')==='NOT_IN_PARTIAL') $preview['statuses'][$sku]=$status;
        }
        $preview['sourceCounts']=$preview['counts'];
        $preview['counts']=['rows'=>count($preview['offers']),'matched'=>count(array_filter($preview['offers'],static fn($r)=>$r['sku']!==null)),
            'review'=>count(array_filter($preview['offers'],static fn($r)=>!empty($r['issues']))),
            'discontinued'=>count(array_filter($preview['statuses'],static fn($s)=>$s==='DISCONTINUED'))];
        if ($base && $wpdb->update($t,['status'=>'archived'],['id'=>$base])===false) throw new RuntimeException('STORAGE_ERROR');
        if ($wpdb->update($t,['status'=>'active','preview_json'=>lps_sp_json($preview),'activated_by'=>get_current_user_id(),'activated_at'=>current_time('mysql',true)],['id'=>$id])===false
            || $wpdb->update($h,['version_id'=>$id],['scope_key'=>$v['scope_key']])===false) throw new RuntimeException('STORAGE_ERROR');
        if ($wpdb->query('COMMIT')===false) throw new RuntimeException('STORAGE_ERROR');
    } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
}

function lps_sp_offer_key(array $offer): string { return lps_sp_json([$offer['article'],$offer['gtin'],$offer['pack']]); }

// Pin a version at preview start: a new supplier file never rewrites an existing calculation.
function lps_sp_active_versions(string $database): array {
    global $wpdb;
    if (get_option('lps_supplier_prices_schema')!=='1') return [];
    $rows=$wpdb->get_results($wpdb->prepare('SELECT v.id,v.supplier FROM '.lps_sp_table().' v JOIN '.lps_sp_heads().' h ON h.version_id=v.id WHERE v.source_database=%s ORDER BY v.supplier',$database),ARRAY_A);
    $result=[]; foreach ($rows?:[] as $row) $result[$row['supplier']]=(int)$row['id']; return $result;
}

function lps_sp_product(string $sku, array $suppliers, array $versions): array {
    static $cache=[]; $result=[];
    foreach ($suppliers as $supplier) {
        $id=$versions[$supplier]??0; if (!$id) continue;
        if (!isset($cache[$id])) $cache[$id]=lps_sp_decode(lps_sp_version($id)['preview_json']);
        $data=$cache[$id]; $offers=[];
        foreach ($data['offers'] as $offer) if ($offer['sku']===$sku) { unset($offer['raw']); $offers[]=$offer; }
        $result[]=['supplier'=>$supplier,'versionId'=>$id,'status'=>$data['statuses'][$sku]??'REVIEW','offers'=>$offers];
    }
    return $result;
}

require_once __DIR__ . '/supplier-prices-admin.php';
