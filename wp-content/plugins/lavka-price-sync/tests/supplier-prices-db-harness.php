<?php
if (!in_array(PHP_SAPI,['cli','cli-server'],true)) exit;
$dsn=getenv('LPS_SP_TEST_DSN');
if (!$dsn || !str_contains($dsn,'/private/tmp/lavka-price-import.sock')) exit('Isolated test socket required');
$pdo=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS lavka_supplier_test');$pdo->exec('USE lavka_supplier_test');
class TestWpdb {
 public $prefix='test_',$last_error='',$insert_id=0,$failHead=false;
 public function __construct(public PDO $pdo){}
 public function prepare($q,...$args){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:$this->pdo->quote((string)$v);},$q);}
 public function query($q){$this->last_error='';try{if($this->failHead&&str_starts_with($q,'UPDATE test_lps_supplier_price_heads')){$this->failHead=false;throw new Exception('injected failure');}$n=$this->pdo->exec($q);$this->insert_id=(int)$this->pdo->lastInsertId();return $n;}catch(Throwable $e){$this->last_error=$e->getMessage();return false;}}
 public function get_results($q,$mode=null){$this->last_error='';try{return $this->pdo->query($q)->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable $e){$this->last_error=$e->getMessage();return null;}}
 public function get_row($q,$mode=null){return $this->get_results($q)[0]??null;}
 public function get_var($q){$r=$this->get_row($q);return $r?array_values($r)[0]:null;}
 public function insert($t,$data){return $this->query('INSERT INTO '.$t.' (`'.implode('`,`',array_keys($data)).'`) VALUES ('.implode(',',array_map(fn($v)=>$v===null?'NULL':$this->pdo->quote((string)$v),array_values($data))).')');}
 public function update($t,$data,$where){return $this->query('UPDATE '.$t.' SET '.implode(',',array_map(fn($k)=>$k.'='.($data[$k]===null?'NULL':$this->pdo->quote((string)$data[$k])),array_keys($data))).' WHERE '.implode(' AND ',array_map(fn($k)=>$k.'='.$this->pdo->quote((string)$where[$k]),array_keys($where))));}
 public function get_charset_collate(){return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';}
}
$wpdb=new TestWpdb($pdo);
define('ABSPATH','/private/tmp/lavka-supplier-wp/');define('LPS_CAP','manage_lavka_prices');define('ARRAY_A','ARRAY_A');
@mkdir(ABSPATH.'wp-admin/includes',0777,true);file_put_contents(ABSPATH.'wp-admin/includes/upgrade.php','<?php');
function dbDelta($q){global $wpdb;$wpdb->query(str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$q));}
function get_option($k){return $GLOBALS['opts'][$k]??false;}
function update_option($k,$v,$autoload=false){$GLOBALS['opts'][$k]=$v;}
function add_action($k,$cb){$GLOBALS['hooks'][$k]=$cb;}
function __($s,$domain=''){return $s;}
function esc_html__($s,$domain=''){return esc_html($s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function current_user_can($c){return ($_SERVER['HTTP_X_TEST_DENY']??'')!=='1';}
function get_current_user_id(){return 1;}
function current_time($f,$gmt=false){return gmdate('Y-m-d H:i:s');}
function get_temp_dir(){return '/private/tmp/';}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function wp_unslash($v){return $v;}
function absint($v){return abs((int)$v);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return strip_tags((string)$s);}
function sanitize_file_name($s){return basename($s);}
function selected($a,$b,$echo=true){return (string)$a===(string)$b?'selected':'';}
function checked($a,$b,$echo=true){return $a===$b?'checked':'';}
function admin_url($s){return '/?'.(str_starts_with($s,'admin.php?')?substr($s,10):'');}
function add_query_arg($key,$value=null,$url=null){if(is_array($key)){$args=$key;$u=$value;}else{$args=[$key=>$value];$u=$url;}return $u.'&'.http_build_query($args);}
function wp_nonce_field($s){echo '<input type="hidden" name="_wpnonce" value="test-only">';}
function check_admin_referer($s){if(($_POST['_wpnonce']??'')!=='test-only'){http_response_code(403);exit('Nonce rejected');}}
function wp_die($s,$title='',$args=[]){http_response_code($args['response']??500);exit($s);}
function wp_safe_redirect($u){header('Location: '.$u);}
function nocache_headers(){}
$base=dirname(__DIR__);
require $base.'/inc/product-analytics-export.php';require $base.'/inc/supplier-prices.php';
lps_sp_install();
if(PHP_SAPI==='cli-server'){
 if($_SERVER['REQUEST_METHOD']==='POST'){($GLOBALS['hooks']['admin_post_lps_supplier_prices'])();exit;}
 echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px system-ui;margin:20px;background:#f0f0f1}input,select,button{font:inherit;padding:5px}td,th{padding:8px;text-align:left}table{background:white;width:100%}.notice-error{color:#a00}button{cursor:pointer}</style>';
 lps_sp_render();
}
