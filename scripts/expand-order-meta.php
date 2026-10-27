<?php
/** WP-CLI eval-file. Preview by default; authorized, backed-up widening only. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI only');
global $wpdb;
$table=$wpdb->prefix.'wc_orders_meta';
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) throw new RuntimeException('Invalid table');
$column=$wpdb->get_row("SHOW FULL COLUMNS FROM `$table` LIKE 'meta_value'",ARRAY_A);
if (!$column) throw new RuntimeException('HPOS metadata table unavailable');
echo wp_json_encode(['table'=>$table,'type'=>$column['Type'],'rows'=>$wpdb->get_var("SELECT COUNT(*) FROM `$table`"),'max_bytes'=>$wpdb->get_var("SELECT MAX(OCTET_LENGTH(meta_value)) FROM `$table`")])."\n";
if (getenv('LAVKA_ORDER_META_APPLY')!=='1') return;
if ($column['Type']==='longtext') { echo "Already longtext; no change\n"; return; }
if ($column['Type']!=='text' || $column['Null']!=='YES' || $column['Default']!==null || $column['Extra']!=='' || $column['Comment']!=='') throw new RuntimeException('Unexpected schema; review manually');
$collation=$column['Collation'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$collation)) throw new RuntimeException('Invalid collation');
$dir=realpath((string)getenv('LAVKA_ORDER_META_BACKUP_DIR'));
$web=realpath(ABSPATH);
if (!$dir || !$web || $dir===$web || strpos($dir,$web.DIRECTORY_SEPARATOR)===0 || (fileperms($dir)&0077)!==0) throw new RuntimeException('Provide an existing private backup directory outside the web root (0700)');
$lock='lavka:order-meta-schema';
if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1') throw new RuntimeException('Schema repair already running');
try {
    $file=$dir.'/order-meta-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
    $handle=fopen($file,'x');
    if (!$handle || !chmod($file,0600)) throw new RuntimeException('Cannot create private backup');
    $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        $ddl=$wpdb->get_row("SHOW CREATE TABLE `$table`",ARRAY_N);
        $rows=$wpdb->get_results("SELECT * FROM `$table` ORDER BY id",ARRAY_A);
        if (!$ddl || $wpdb->last_error) throw new RuntimeException('Backup read failed');
        $bytes=wp_json_encode(['schema'=>$ddl[1],'rows'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!$bytes || fwrite($handle,$bytes)!==strlen($bytes) || !fflush($handle)) throw new RuntimeException('Backup write failed');
        fclose($handle);
        if (hash_file('sha256',$file)!==hash('sha256',$bytes)) throw new RuntimeException('Backup verification failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    echo 'Backup verified: '.$file."\n";
    // Do not wait indefinitely for a busy table. No automatic retry after a DDL error.
    $wpdb->query('SET SESSION lock_wait_timeout=5');
    $ok=$wpdb->query("ALTER TABLE `$table` MODIFY COLUMN meta_value LONGTEXT COLLATE $collation NULL DEFAULT NULL, ALGORITHM=COPY, LOCK=SHARED");
    if ($ok===false) throw new RuntimeException('Widening failed; inspect schema before retry: '.$wpdb->last_error);
    $after=$wpdb->get_row("SHOW FULL COLUMNS FROM `$table` LIKE 'meta_value'",ARRAY_A);
    if (($after['Type']??'')!=='longtext' || $after['Collation']!==$collation) throw new RuntimeException('Unexpected result; inspect schema');
    echo "Verified LONGTEXT; existing values preserved. Do not narrow on rollback.\n";
} finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
