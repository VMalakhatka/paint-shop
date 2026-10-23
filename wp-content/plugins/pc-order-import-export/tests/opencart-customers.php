<?php
define('ABSPATH',__DIR__);
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
require dirname(__DIR__).'/inc/OpenCartCustomerImport.php';
use PaintCore\PCOE\OpenCartCustomerImport as I;
foreach (['+7 999 1234567','7(999)1234567','8 999 1234567','0079991234567'] as $p) if(!I::excludedPhone($p))throw new RuntimeException('Phone not excluded');
foreach (['+380501234567','0501234567',''] as $p) if(I::excludedPhone($p))throw new RuntimeException('Wrong exclusion');
$r=array_fill_keys(I::HEADERS,'');$r+=[];$r['email']='fixture@example.test';$r['source_sites']='paint.dn.ua;1lavka.com';$r['role']='opt';$r['country']='UA';$r['addresses_json']='[]';I::validate($r);
foreach (['role'=>'administrator','source_sites'=>'evil.test','phone'=>'+79991234567','email'=>'broken','country'=>'RU'] as $k=>$v){$bad=$r;$bad[$k]=$v;try{I::validate($bad);throw new LogicException('Accepted '.$k);}catch(RuntimeException $e){}}
$text=I::invitation($r,'https://example.test/reset');
foreach (['paint.dn.ua','1lavka.com','https://example.test/reset','установіть пароль'] as $needle) if(!str_contains($text,$needle))throw new RuntimeException('Missing invite content');
echo "PASS OpenCart validators and Ukrainian invitation\n";
