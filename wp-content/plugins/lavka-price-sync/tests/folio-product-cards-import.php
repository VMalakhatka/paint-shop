<?php
require __DIR__.'/../../../vendor/autoload.php';
define('ABSPATH', __DIR__);function add_action(...$args){}
require __DIR__.'/../inc/folio-product-cards-import.php';
function check($v){if(!$v)throw new RuntimeException('Assertion failed');}
function fails($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected failure');}
foreach (['Xls','Xlsx'] as $format) {
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();
    $sheet->setCellValue('A1','Артикул');
    $sheet->setCellValueExplicit('A2','00123','s');$sheet->setCellValueExplicit('A3','К-42','s');
    $sheet->setCellValue('A4',42);$sheet->getStyle('A4')->getNumberFormat()->setFormatCode('00000');
    $sheet->setCellValueExplicit('A5','00123','s');$sheet->setCellValue('B2','ignored');
    $book->createSheet()->setCellValue('A2','ignored sheet');
    $file=tempnam(sys_get_temp_dir(),'card-import');
    try {
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
        check(lps_card_excel_skus($file,'a',2)===['00123','К-42','00042']);
        check(lps_card_excel_skus($file,'B',2)===['ignored']);
        fails(fn()=>lps_card_excel_skus($file,'C',2));
        fails(fn()=>lps_card_excel_skus($file,'XFE',2));
        $sheet->setCellValue('A6','=1+1');\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
        fails(fn()=>lps_card_excel_skus($file,'A',2));
        $sheet->setCellValue('A6','');
        for($i=7;$i<=507;$i++)$sheet->setCellValue('A'.$i,'sku-'.$i);
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
        fails(fn()=>lps_card_excel_skus($file,'A',2));
    } finally {unlink($file);$book->disconnectWorksheets();}
}
echo "PASS: XLS/XLSX, column/first row, Unicode, zeros, deduplication, first sheet, formulas and limits\n";
