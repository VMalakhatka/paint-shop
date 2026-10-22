<?php
require __DIR__.'/../../../vendor/autoload.php';
define('ABSPATH',__DIR__);
function add_action(...$args){}
require __DIR__.'/../inc/folio-manager-import.php';
require __DIR__.'/../inc/folio-manager-export.php';
function check($value){if(!$value)throw new RuntimeException('Assertion failed');}
function fails($fn){try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected failure');}
$file=tempnam(sys_get_temp_dir(),'manager-test');
try {
    check(lps_manager_text("COD_ARTIC\tNAME_ARTIC\n001\tНазвание",'NAME')===[['COD_ARTIC'=>'001','NAME_ARTIC'=>'Название']]);
    check(lps_manager_text("A;\n",'UNIT')===[['COD_ARTIC'=>'A','EDIN_IZMER'=>null]]);
    check(lps_manager_text("ARTIC_BAZA\nA\nA\nB",'BASE')===[['ARTIC_BAZA'=>'A'],['ARTIC_BAZA'=>'A'],['ARTIC_BAZA'=>'B']]);
    check(lps_manager_text('A;one;;;;;','GROUPS')[0]['NGROUP_TV6']===null);
    check(lps_manager_text("A;B;2,5;;;0",'ASSEMBLY')[0]['KOL_rod_razob']==='2.5');
    check(lps_manager_text("A;\"Line 1\nLine 2\"",'DESCRIPTION')[0]['DESCRIPTION']==="Line 1\nLine 2");
    fails(fn()=>lps_manager_text('A;one;two','GROUPS'));
    fails(fn()=>lps_manager_text("A;one\na;two",'NAME'));
    fails(fn()=>lps_manager_text('A;B;1;;;','ASSEMBLY'));
    fails(fn()=>lps_manager_text('A;B;=1+2;;;0','ASSEMBLY'));
    fails(fn()=>lps_manager_text("A;".str_repeat('я',201),'NAME'));
    fails(fn()=>lps_manager_text("A\tB\n".chr(255),'NAME'));
    fails(fn()=>lps_manager_text(str_repeat("A\n",501),'BASE'));
    foreach(['Xls','Xlsx'] as $format)foreach(['NAME','GROUPS','UNIT','DESCRIPTION','BASE','ASSEMBLY'] as $op)foreach([true,false] as $header) {
        $columns=lps_manager_columns($op);$row=array_fill(0,count($columns),'');$row[0]='001';
        if($op==='ASSEMBLY'){$row[1]='B';$row[2]='2,5';$row[5]='0';}elseif($op!=='BASE')$row[1]='Значение';
        $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$r=$header?2:1;
        if($header)$sheet->fromArray($columns,null,'A1');
        foreach($row as $i=>$v)$sheet->setCellValueExplicit([$i+1,$r],$v,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
        check(lps_manager_file($file,strtolower($format),$op)===lps_manager_rows([$row],$op));
        $sheet->setCellValue('A'.$r,'=1+1');\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
        fails(fn()=>lps_manager_file($file,strtolower($format),$op));$book->disconnectWorksheets();
    }
    lps_manager_xlsx(['Input'=>['columns'=>['COD_ARTIC','DESCRIPTION'],'rows'=>[['001','=HYPERLINK("https://example.invalid")']]],'BASE'=>['columns'=>['ARTIC','ARTIC_BAZA'],'rows'=>[[null,null],['001','BASE']]]],$file);
    $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    check($book->getSheet(0)->getCell('A2')->getValue()==='001');check($book->getSheet(0)->getCell('B2')->getDataType()==='s');
    check($book->getSheet(1)->getCell('A2')->getValue()===null);check($book->getSheet(1)->getCell('A3')->getValue()==='001');$book->disconnectWorksheets();
}finally{unlink($file);}
echo "PASS: six schemas; 24 Excel cases; headerless/CSV/paste; null, duplicates, formulas, bounds; safe multi-sheet export\n";
