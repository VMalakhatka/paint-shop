<?php
if (PHP_SAPI !== 'cli') exit;
if (!getenv('LPS_SP_TEST_XLSX')) exit('Set LPS_SP_TEST_XLSX to the read-only supplier fixture');
require __DIR__.'/supplier-prices-db-harness.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function rejected($fn,$code){try{$fn();}catch(RuntimeException $e){check($e->getMessage()===$code,$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
$pdo->exec('DROP TABLE IF EXISTS folio_product_metric_current');$pdo->exec('DROP TABLE IF EXISTS folio_product_snapshot_generation');
$pdo->exec('CREATE TABLE folio_product_snapshot_generation(id BIGINT PRIMARY KEY,status VARCHAR(24))');
$pdo->exec('CREATE TABLE folio_product_metric_current(sku VARCHAR(64),primary_barcode VARCHAR(128),generation_id BIGINT,source_database VARCHAR(64),current_supplier VARCHAR(100))');
$pdo->exec("INSERT INTO folio_product_snapshot_generation VALUES (1,'ACTIVE')");
$pdo->exec("INSERT INTO folio_product_metric_current VALUES ('KR-17003','4000798170035',1,'Paint_Rus','Kreul'),('KR-17817','4000798178178',1,'Paint_Rus','Kreul'),('MISSING','12345670',1,'Paint_Rus','Kreul'),('NO-GTIN','',1,'Paint_Rus','Kreul'),('OTHER','12345670',1,'Paint_Rus','Other')");
$pdo->exec('DELETE FROM test_lps_supplier_price_heads');$pdo->exec('DELETE FROM test_lps_supplier_prices');
function draft(){global $wpdb;$bytes=file_get_contents(getenv('LPS_SP_TEST_XLSX'));$wpdb->insert(lps_sp_table(),['scope_key'=>lps_sp_scope('Kreul','Paint_Rus'),'supplier'=>'Kreul','source_database'=>'Paint_Rus','filename'=>'Kreul 2026.xlsx','checksum'=>hash('sha256',$bytes),'original_base64'=>base64_encode($bytes),'config_json'=>'{}','preview_json'=>'{}','created_by'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);return $wpdb->insert_id;}
$config=['sheet'=>'Preise 2025','validFrom'=>'2026-04-01','full'=>1];$id=draft();lps_sp_build($id,$config);$v=lps_sp_version($id);
rejected(fn()=>lps_sp_activate($id,'stale'),'VERSION_CHANGED');
$wpdb->query("UPDATE folio_product_metric_current SET primary_barcode='4000798170036' WHERE sku='KR-17003'");
rejected(fn()=>lps_sp_activate($id,$v['approval_hash']),'CATALOG_CHANGED');
$wpdb->query("UPDATE folio_product_metric_current SET primary_barcode='4000798170035' WHERE sku='KR-17003'");
lps_sp_activate($id,$v['approval_hash']);lps_sp_activate($id,$v['approval_hash']);
check(lps_sp_head($v['scope_key'])===$id,'Idempotent activation');
$p=lps_sp_decode(lps_sp_version($id)['preview_json']);check($p['statuses']['MISSING']==='DISCONTINUED' && $p['statuses']['NO-GTIN']==='REVIEW' && !isset($p['statuses']['OTHER']),'Supplier-scoped discontinued markers');
$frozen=lps_sp_active_versions('Paint_Rus');check(count(lps_sp_product('KR-17003',['Kreul'],$frozen)[0]['offers'])===2,'Variants reach order helper');
$duplicate=draft();rejected(fn()=>lps_sp_build($duplicate,$config),'ALREADY_ACTIVE');
$second=draft();$config['full']=0;$config['netFallback']=1;lps_sp_build($second,$config);$v2=lps_sp_version($second);
$wpdb->failHead=true;rejected(fn()=>lps_sp_activate($second,$v2['approval_hash']),'STORAGE_ERROR');
check(lps_sp_head($v['scope_key'])===$id && lps_sp_version($id)['status']==='active' && lps_sp_version($second)['status']==='draft','Atomic rollback on storage failure');
lps_sp_activate($second,$v2['approval_hash']);
check(lps_sp_decode(lps_sp_version($second)['preview_json'])['statuses']['MISSING']==='DISCONTINUED','Partial update retains prior absence status');
check(lps_sp_product('KR-17003',['Kreul'],$frozen)[0]['versionId']===$id,'Existing order retains archived source version');
$third=draft();$config['full']=1;lps_sp_build($third,$config);$v3=lps_sp_version($third);
// Competing version cannot replace a head that changed since its preview.
$fourth=draft();$config['netFallback']=0;lps_sp_build($fourth,$config);$v4=lps_sp_version($fourth);
lps_sp_activate($third,$v3['approval_hash']);rejected(fn()=>lps_sp_activate($fourth,$v4['approval_hash']),'VERSION_CHANGED');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM folio_product_metric_current')===5,'Source catalog unchanged');
echo "PASS: MySQL schema, preview, stale catalog, double activation, supplier scope, rollback, partial merge, version conflict and pinned order data\n";
