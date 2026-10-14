<?php
// Offline permission policy check; no WordPress, database or network.
define('ABSPATH', __DIR__);
$roles=[];$caps=[];
function wp_get_current_user(){global $roles;return (object)['roles'=>$roles];}
function current_user_can($cap){global $caps;return in_array($cap,$caps,true);}
require dirname(__DIR__).'/inc/FolioCustomerImport.php';
use PaintCore\PCOE\FolioCustomerImport;
$cases=[
 [['lavka_manager'],['manage_woocommerce'],false],
 [['lavka_manager'],['manage_woocommerce','pcoe_import_customers'],true],
 [['customer'],['pcoe_import_customers'],false],
 [['shop_manager'],['manage_woocommerce','promote_users'],true],
 [['administrator'],['manage_woocommerce','create_users'],true],
 [['lavka_manager'],['manage_woocommerce','promote_users'],false],
];
foreach($cases as [$roles,$caps,$expected])if(FolioCustomerImport::allowed()!==$expected)throw new RuntimeException('Permission mismatch');
echo "PASS: 6 import permission cases\n";
