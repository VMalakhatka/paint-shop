<?php
/** No WordPress, network or customer data. Real thumbnail conversion and XLSX drawings. */
namespace PaintCore\PCOE {
function microtime($float=true){return $GLOBALS['fake_clock']??\microtime($float);}
final class BroadcastStore {
    public static array $files=[];public static array $saved=[];
    public static function put_file($id,$key,$file): void {self::$files[$id][$key]=$file;}
    public static function file($id,$key): array {return self::$files[$id][$key];}
    public static function bytes($file): string {return base64_decode($file['content']);}
    public static function save($id,$data): void {self::$saved[$id]=$data;}
    public static function temporary_file(): string {$p=tempnam(sys_get_temp_dir(),'photo-test-');register_shutdown_function(static function()use($p){if(is_file($p))unlink($p);});return $p;}
}
}
namespace {
define('ABSPATH',__DIR__);
require dirname(__DIR__,3).'/vendor/autoload.php';
require dirname(__DIR__).'/inc/CommercialOffer.php';
require dirname(__DIR__).'/inc/BroadcastPhotos.php';
function __($s,$domain=null){return $s;}
function get_bloginfo($s){return 'Fixture';}
function wp_date($s){return date($s);}
function get_woocommerce_currency(){return 'UAH';}
function wp_strip_all_tags($s){return strip_tags($s);}
function strip_shortcodes($s){return $s;}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
function lps_java_post($url,$body,$options){return ['code'=>200,'body'=>json_encode(array_map(fn($s)=>['sku'=>$s,'unitsPerPack'=>5],$body['skus']))];}
class WC_Product {
    public function __construct(public int $id){}
    public function get_image_id(){return $this->id;}
    public function get_parent_id(){return 0;}
    public function get_short_description(){return '';}
    public function get_description(){return '';}
    public function get_regular_price($ctx){return '';}
    public function get_meta($s){return '';}
    public function get_attribute($s){return '';}
}
function wc_get_product($id){return new WC_Product($id);}
function wp_get_attachment_image_url($id,$size){return (string)$id;}
$GLOBALS['calls']=[];
$im=imagecreatetruecolor(180,120);ob_start();imagepng($im);$GLOBALS['png']=ob_get_clean();imagedestroy($im);
function wp_safe_remote_get($url,$args){
    if(isset($GLOBALS['fake_clock']))$GLOBALS['fake_clock']+=3;
    $id=(int)$url;$GLOBALS['calls'][$id]=($GLOBALS['calls'][$id]??0)+1;
    if($id===350 || ($id===349 && $GLOBALS['calls'][$id]===1))return ['code'=>503,'body'=>''];
    return ['code'=>200,'body'=>$GLOBALS['png']];
}
function delete_option($key){$GLOBALS['deleted'][]=$key;}
function check($ok,$label){if(!$ok)throw new \RuntimeException($label);echo "OK $label\n";}
use PaintCore\PCOE\BroadcastPhotos as Photos;
use PaintCore\PCOE\BroadcastStore as Store;
use PaintCore\PCOE\CommercialOffer as Offer;
$data=['ids'=>range(1,350),'groups'=>[['ids'=>range(1,350)],['ids'=>range(1,350)]]];
check(!Photos::prepare(1,$data),'first batch yields');
check(count($data['photo_progress']['done'])===40,'bounded to 40 products');
// Re-enter with only persisted campaign state, as a fresh cron request would.
$data=Store::$saved[1];$ticks=1;
while(!Photos::prepare(1,$data)){if(++$ticks>20)throw new \RuntimeException('Did not finish');$data=Store::$saved[1];}
check(count($data['photo_progress']['done'])===350,'all 350 products considered');
check($GLOBALS['calls'][1]===1 && $GLOBALS['calls'][300]===1,'completed photos reused across ticks and groups');
check($data['photo_progress']['done'][349]==='ready' && $GLOBALS['calls'][349]===2,'transient failure retried');
check($data['photo_progress']['done'][350]==='unavailable' && $GLOBALS['calls'][350]===2,'persistent failure bounded');
$entries=[];foreach(range(1,350) as $id)$entries[]=['product_id'=>$id,'depth'=>0,'values'=>['SKU'.$id,'GTIN'.$id,'Product'.$id,'',null,100,2,null,'https://example.invalid/'.$id]];
$result=Offer::workbook($entries,'one',fn($id)=>Photos::bytes(1,$id,$data));
check($result['photos']===349,'actual offer workbook embeds all 349 available photos');
$path=Store::temporary_file();(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($result['book']))->save($path);
$zip=new \ZipArchive();$zip->open($path);$drawing=$zip->getFromName('xl/drawings/drawing1.xml');
check(str_contains($drawing,'<xdr:row>349</xdr:row>'),'XLSX photo exists beyond row 221');
check($result['book']->getActiveSheet()->getCell('G351')->getValue()==='View product','unavailable photo retains product link');
$zip->close();$result['book']->disconnectWorksheets();
Photos::delete(1,$data);check(count($GLOBALS['deleted'])===350,'all campaign photo blobs cleaned with mailing');
$GLOBALS['fake_clock']=1000.0;
$slow=['ids'=>range(501,550),'groups'=>[['ids'=>range(501,550)]]];
check(!Photos::prepare(2,$slow) && count($slow['photo_progress']['done'])===5,'time budget yields with remaining photos pending');
unset($GLOBALS['fake_clock']);
while(!Photos::prepare(2,$slow))$slow=Store::$saved[2];
check($GLOBALS['calls'][501]===1 && $slow['photo_progress']['done'][550]==='ready','slow batch resumes through last photo without refetch');
try{Photos::bytes(2,999,$slow);throw new \RuntimeException('Missing state accepted');}catch(\RuntimeException $e){check($e->getMessage()==='Photo preparation is incomplete','incomplete preparation blocks assembly');}
echo "PASS\n";
}
