<?php
if (!defined('ABSPATH')) exit;
/** Project the three disjoint macro result sets, preserving signs, rows and duplicates. */
function lps_assembly_invoice(array $outputs): array {
    $rows=[];
    foreach (['ASSEMBLY_18','ASSEMBLY_9','ASSEMBLY_91'] as $name) {
        $table=$outputs[$name]??null;
        if (!is_array($table) || array_slice($table['columns']??[],0,3)!==['ARTIC','KOL_R','PRICE'] || !is_array($table['rows']??null)) throw new RuntimeException('ASSEMBLY_INCOMPLETE');
        foreach ($table['rows'] as $row) {
            $values=array_slice($row,0,3);
            if (count($values)!==3 || $values[0]===null || trim((string)$values[0])==='' || preg_match('/[\x00-\x1f\x7f]/',(string)$values[0])) throw new RuntimeException('ASSEMBLY_INCOMPLETE');
            foreach ([1,2] as $i) if (!is_numeric($values[$i]) || !is_finite((float)$values[$i])) throw new RuntimeException('ASSEMBLY_INCOMPLETE');
            $rows[]=$values;
        }
    }
    if (!$rows) throw new RuntimeException('ASSEMBLY_INCOMPLETE');
    return ['columns'=>['ARTIC','KOL_R','PRICE'],'rows'=>$rows];
}
function lps_manager_export_tables(array $job): array {
    $data=$job['result'];$columns=lps_manager_columns($data['operation']);$tables=[];$invoiceError=false;
    if ($data['operation']==='ASSEMBLY') {
        try {$tables['IMPORT_DBF']=lps_assembly_invoice($data['outputs']);} catch (RuntimeException $e) {$invoiceError=true;}
    }
    $tables['Input']=['columns'=>$columns,'rows'=>array_map(static fn($r)=>array_map(static fn($c)=>$r[$c]??null,$columns),$data['input'])];
    if(!in_array($data['operation'],['BASE','ASSEMBLY'],true)) {
        $rows=[];
        foreach($data['before'] as $row)foreach($row['values'] as $field=>$old)$rows[]=[$row['sku'],(string)$row['warehouse'],$field,$old,$data['input'][$row['input']][$field]];
        $tables['Changes']=['columns'=>['COD_ARTIC','ID_SCLAD','FIELD','BEFORE','REQUESTED'],'rows'=>$rows];
        $rows=[];foreach($data['after']??[] as $row)foreach($row['values'] as $field=>$value)$rows[]=[$row['sku'],(string)$row['warehouse'],$field,$value];
        $tables['Saved']=['columns'=>['COD_ARTIC','ID_SCLAD','FIELD','VALUE'],'rows'=>$rows];
    }
    foreach($data['outputs'] as $name=>$table)$tables[$name]=$table;
    $meta=[['Source','Paint_Ua'],['Operation',$data['operation']],['Status',$job['status']],['Job',$job['id']],['Created',$job['created']],['File',$job['filename']],['SHA256',$job['sha256']]];
    if($invoiceError)$meta[]=['Issue','ASSEMBLY_INCOMPLETE'];
    if(isset($job['assembly_mode']))$meta[]=['Assembly input mode',$job['assembly_mode']];
    foreach($data['missing']??[] as $sku)$meta[]=['Missing target',$sku];
    foreach($data['issues']??[] as $issue)$meta[]=['Issue',$issue];
    $tables['Report']=['columns'=>['Key','Value'],'rows'=>$meta];return $tables;
}
function lps_manager_xlsx(array $tables,string $path): void {
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$book->removeSheetByIndex(0);
    try {
        foreach($tables as $name=>$table){
            $sheet=$book->createSheet();$sheet->setTitle($name);
            foreach(array_merge([$table['columns']],$table['rows']) as $r=>$row)foreach($row as $c=>$value){
                if($value===null)continue;
                // Codes, arbitrary text and formula-like descriptions are always inert Excel strings.
                $sheet->setCellValueExplicit([$c+1,$r+1],(string)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($table['columns']));
            $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);$sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:'.$last.(count($table['rows'])+1));
            foreach($table['columns'] as $i=>$column)$sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1))->setWidth(in_array($column,['BEFORE','REQUESTED','DESCRIPTION'],true)?60:24);
        }
        $book->setActiveSheetIndex(0);\PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book,'Xlsx')->save($path);
    }finally{$book->disconnectWorksheets();}
}
add_action('admin_post_lps_manager_export',static function(){
    if(!current_user_can(LPS_CAP))wp_die(esc_html__('Access denied.','lps-manager'));
    check_admin_referer(LPS_MANAGER_NONCE);$path=null;
    try {
        $id=$_GET['job']??'';if(!is_string($id))throw new RuntimeException('JOB');$job=lps_manager_job($id);
        $path=tempnam(get_temp_dir(),'folio-manager-');if(!$path)throw new RuntimeException('TEMP');
        lps_manager_xlsx(lps_manager_export_tables($job),$path);
        nocache_headers();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="folio-manager-'.$job['result']['operation'].'.xlsx"');header('Content-Length: '.filesize($path));
        readfile($path);
    }catch(Throwable $e){wp_die(esc_html__('Could not export this operation. Reopen it from history and try again.','lps-manager'));}
    finally{if($path&&is_file($path))unlink($path);}exit;
});
