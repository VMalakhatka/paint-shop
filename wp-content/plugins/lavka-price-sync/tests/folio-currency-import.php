<?php
require __DIR__.'/../../../vendor/autoload.php';
define('ABSPATH', __DIR__);
require __DIR__.'/../inc/folio-currency-import.php';
function check($v){if(!$v)throw new RuntimeException('Assertion failed');}
function fails($fn){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected failure');}
$expected=[['sku'=>'ML-47068','price'=>'1.8987','currency'=>'3'],['sku'=>'ML-18270','price'=>'1.6974','currency'=>'3']];
$file=tempnam(sys_get_temp_dir(),'currency-test');
try {
 foreach (["CODE_ARTIC;PRICE;KOD_VALUT\nML-47068;1,8987;3\nML-18270;1,6974;3", "ML-47068\t1,8987\t3\nML-18270\t1,6974\t3", "ML-47068,\"1,8987\",3\nML-18270,1.6974,3"] as $csv) {
  file_put_contents($file,$csv);check(lps_currency_file($file,'csv')===$expected);
 }
 foreach (["ML-47068,1,8987,3", "A;1;3\na;2;3", "A;=1+2;3", "CODE_ARTIC;PRICE;KOD_VALUT", "A;1;3\nB;bad;3"] as $csv) {
  file_put_contents($file,$csv);fails(fn()=>lps_currency_file($file,'csv'));
 }
 foreach (['Xls','Xlsx'] as $format) foreach ([true,false] as $header) {
  $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();
  $r=1;if($header){$sheet->fromArray(['CODE_ARTIC','PRICE','KOD_VALUT'],null,'A1');$r++;}
  $sheet->fromArray([['ML-47068','1,8987',3],['ML-18270',1.6974,3]],null,'A'.$r);
  \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
  check(lps_currency_file($file,strtolower($format))===$expected);
  $sheet->setCellValue('B'.$r,'=1+1');\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
  fails(fn()=>lps_currency_file($file,strtolower($format)));$book->disconnectWorksheets();
 }
} finally {unlink($file);}
echo "PASS: Excel/CSV headers, decimal comma, formats, invalid rows, duplicates and formulas\n";
