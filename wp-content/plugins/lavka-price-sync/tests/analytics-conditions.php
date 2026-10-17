<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH',__DIR__);
function add_action(...$a) {}
function __($s,$d=''){return $s;}
function absint($v){return abs((int)$v);}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return strtolower(preg_replace('/[^a-zA-Z0-9_-]/','',(string)$v));}
function rest_sanitize_boolean($v){return (bool)$v;}
function lps_purchase_profile($v){return $v;}
function lps_analytics_stock_only_ids($v,$ids){return [];}
require __DIR__.'/../inc/analytics-period.php';
require __DIR__.'/../inc/analytics-scenarios.php';
$branches=[['mode'=>'INCLUDE','path'=>['Textile','Opaque']],['mode'=>'EXCLUDE','path'=>['Textile','Opaque','Kreul']]];
$profile=['schemaVersion'=>4,'context'=>['warehouseIds'=>[7]],'period'=>['mode'=>'FIXED','from'=>'2026-01-01','to'=>'2026-10-17'],'productFilters'=>['groupBranches'=>$branches,'currentSuppliers'=>['mode'=>'INCLUDE','values'=>['Kreul']]]];
$saved=lps_analytics_scenario_sanitize_profile($profile);
if($saved['productFilters']['groupBranches']!==$branches)throw new RuntimeException('Scenario dropped branch paths');
if(lps_analytics_scenario_sanitize_profile(json_decode(json_encode($saved),true))!==$saved)throw new RuntimeException('Roundtrip changed scenario');
foreach([[['mode'=>'ANY','path'=>['A']]],[['mode'=>'INCLUDE','path'=>[]]],[['mode'=>'EXCLUDE','path'=>['A','']]],array_fill(0,101,['mode'=>'INCLUDE','path'=>['A']])] as $invalid){
 try{lps_analytics_group_branches($invalid);throw new RuntimeException('Accepted invalid branches');}catch(InvalidArgumentException $e){}
}
echo "PASS: branch validation and saved scenario roundtrip\n";
