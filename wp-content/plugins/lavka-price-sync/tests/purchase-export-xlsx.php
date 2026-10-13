<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function __($s,$d='') { return $s; }
function add_action(...$args) {}
function wp_die($message) { throw new RuntimeException($message); }
function check($condition,$message) { if (!$condition) throw new RuntimeException($message); }
$autoload=getenv('LPS_XLSX_AUTOLOAD');
if (!$autoload) { echo "SKIP: set LPS_XLSX_AUTOLOAD to the application's Composer autoloader\n";exit; }
require $autoload;require __DIR__ . '/../inc/product-analytics-export.php';
$columns=[['key'=>'sku','label'=>'SKU'],['key'=>'supplierArticle','label'=>'Supplier article'],['key'=>'gtin','label'=>'GTIN'],['key'=>'networkPhysical','label'=>'Physical including transit']];
$rows=[['sku'=>'TEST-010','supplierArticle'=>'0010','gtin'=>'03167860250109','networkPhysical'=>145.0]];
$book=lps_product_analytics_export_xlsx_workbook($columns,$rows,['currentStockCapture'=>'fixture'],
    [['title'=>'Groups','columns'=>[['key'=>'sku','label'=>'SKU']],'rows'=>[['sku'=>'TEST-010'],['sku'=>'TEST-010']]]],'Supplier order');
check(count($book->getAllSheets())===3,'Native XLSX contains summary, group detail and parameters');
check($book->getSheet(0)->getCell('B2')->getDataType()==='s' && $book->getSheet(0)->getCell('B2')->getValue()==='0010','Supplier article remains text with leading zeros');
check($book->getSheet(0)->getCell('C2')->getDataType()==='s','GTIN remains text');
check($book->getSheet(0)->getCell('B2')->getFormattedValue()==='0010' && $book->getSheet(0)->getCell('C2')->getFormattedValue()==='03167860250109','Native cell display preserves complete identifiers');
check($book->getSheet(0)->getAutoFilter()->getRange()==='A1:D2','Sort/filter range includes data rows');
$path=tempnam(sys_get_temp_dir(),'purchase-export-');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
$reopened=\PhpOffice\PhpSpreadsheet\IOFactory::load($path);unlink($path);
check((float)$reopened->getSheet(0)->getCell('D2')->getValue()===145.0,'Saved/reopened native XLSX retains complete stock');
check($reopened->getSheet(1)->getHighestRow()===3,'Group detail remains separate');
$book->disconnectWorksheets();$reopened->disconnectWorksheets();
$normal=lps_product_analytics_export_xlsx_workbook($columns,$rows,[]);
check(count($normal->getAllSheets())===2 && $normal->getSheet(1)->getHighestRow()===9,'Unrelated analytics exports retain their two-sheet structure and metadata');
$normal->disconnectWorksheets();
$literal=lps_product_analytics_export_xlsx_workbook([['key'=>'sku','label'=>'=1+1']],[] ,[]);
check($literal->getSheet(0)->getCell('A1')->getDataType()==='s','Source-derived column labels cannot become formulas');$literal->disconnectWorksheets();
if (($qa=getenv('LPS_EXPORT_QA_DATA')) && ($output=getenv('LPS_EXPORT_QA_XLSX'))) {
    $data=json_decode(file_get_contents($qa),true);
    $book=lps_product_analytics_export_xlsx_workbook($data['columns'],$data['rows'],['currentStockCapture'=>'Test fixture'],
        [['title'=>'Groups','columns'=>[['key'=>'sku','label'=>'SKU']],'rows'=>[['sku'=>'TEST-010'],['sku'=>'TEST-010']]]],'Supplier order');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($output);$book->disconnectWorksheets();
}
echo "PASS: native XLSX sheets, sort/filter ranges, identifiers and saved stock\n";
