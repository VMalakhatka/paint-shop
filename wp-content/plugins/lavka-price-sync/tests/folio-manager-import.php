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
    // Two-column manager workflows must reconstruct fresh Access defaults, not NULL numeric fields.
    $parent=['ARTIC_roditel'=>'001','ARTIC_rebenok'=>null,'KOL_rod_razob'=>'2.5','kol_reb_pol'=>'0','CENA_REB'=>'0','SLOG'=>'0'];
    $child=['ARTIC_roditel'=>null,'ARTIC_rebenok'=>'001','KOL_rod_razob'=>'0','kol_reb_pol'=>'3','CENA_REB'=>'0','SLOG'=>'0'];
    foreach(['PARENT'=>$parent,'CHILD'=>$child] as $mode=>$expected) {
        $cols=lps_manager_input_columns('ASSEMBLY',$mode);$qty=$mode==='PARENT'?'2,5':'3';
        check(lps_manager_text(implode(';',$cols)."\n001;".$qty,'ASSEMBLY',$mode)===[$expected]);
        check(lps_manager_text("001\t".$qty,'ASSEMBLY',$mode)===[$expected]);
        foreach(['Xls','Xlsx'] as $format)foreach([false,true] as $header) {
            $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$r=$header?2:1;
            if($header)$sheet->fromArray($cols,null,'A1');
            $sheet->setCellValue('A'.$r,1);$sheet->getStyle('A'.$r)->getNumberFormat()->setFormatCode('000');
            $sheet->setCellValue('B'.$r,(float)str_replace(',','.',$qty));$sheet->getStyle('B'.$r)->getNumberFormat()->setFormatCode('0.00');
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,$format)->save($file);
            check(lps_manager_file($file,strtolower($format),'ASSEMBLY',$mode)===[$expected]);
            $book->disconnectWorksheets();
        }
        foreach(['001;', ';2', '001;0','001;-1','001;=1+1','001;2;3'] as $bad) fails(fn()=>lps_manager_text($bad,'ASSEMBLY',$mode));
    }
    fails(fn()=>lps_manager_text('001;1.5','ASSEMBLY','CHILD'));
    fails(fn()=>lps_manager_text('001;32768','ASSEMBLY','CHILD'));
    fails(fn()=>lps_manager_text('001;2','ASSEMBLY','INVALID'));
    fails(fn()=>lps_manager_text("ARTIC_rebenok;kol_reb_pol\n001;2",'ASSEMBLY','PARENT'));
    $output=['ASSEMBLY_18'=>['columns'=>['ARTIC','KOL_R','PRICE','ARTIC_REB','cborka'],'rows'=>[['001','-2.5','12','CH','WWORK'],['CH','5','6','CH','WWORK']]],
        'ASSEMBLY_9'=>['columns'=>['ARTIC','KOL_R','PRICE','cborka','ARTIC_REB'],'rows'=>[['001','-1','2','multi','M'],['P2','-2','3','multi','M'],['M','1','8','multi','M']]],
        'ASSEMBLY_91'=>['columns'=>['ARTIC','KOL_R','PRICE','cborka','ARTIC_REB'],'rows'=>[]]];
    $invoice=lps_assembly_invoice($output);
    check($invoice['columns']===['ARTIC','KOL_R','PRICE']);check(count($invoice['rows'])===5);check($invoice['rows'][0]===['001','-2.5','12']);check($invoice['rows'][2]===['001','-1','2']);check($invoice['rows'][4]===['M','1','8']);
    $bad=$output;$bad['ASSEMBLY_9']['rows'][0][2]=null;fails(fn()=>lps_assembly_invoice($bad));
    $job=['id'=>'test','status'=>'READY','created'=>'test','filename'=>'test','sha256'=>'test','assembly_mode'=>'PARENT','result'=>['operation'=>'ASSEMBLY','input'=>[$parent],'outputs'=>$output]];
    $tables=lps_manager_export_tables($job);check(array_key_first($tables)==='IMPORT_DBF');check($tables['IMPORT_DBF']===$invoice);
    lps_manager_xlsx($tables,$file);$book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    check($book->getSheet(0)->getTitle()==='IMPORT_DBF');check($book->getSheet(0)->getHighestColumn()==='C');check($book->getSheet(0)->getCell('A2')->getValue()==='001');check($book->getSheet(0)->getCell('B2')->getValue()==='-2.5');$book->disconnectWorksheets();
    $job['result']['outputs']=$bad;check(!isset(lps_manager_export_tables($job)['IMPORT_DBF']));
}finally{unlink($file);}
echo "PASS: six schemas; 32 Excel cases; parent/child defaults and invoice projection; headerless/CSV/paste; null, duplicates, formulas, bounds; safe multi-sheet export\n";
