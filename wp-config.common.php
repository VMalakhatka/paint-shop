<?php
/**
 * wp-config.common.php
 *
 * Общие настройки WordPress для всех окружений.
 */

define( 'AUTH_KEY',          '<SQzsc,LJV~gH/AhJNZP-*8OM(!tFYu:1y&={H .*a1?2x0B}(,HN ^M%JG?{LH6' );
define( 'SECURE_AUTH_KEY',   'TMzaoPV#AIf*IyI!C@10EO,_]R*o 3tn-90=6BsguicF6Ipnt!_P?l;AoRb,n aB' );
define( 'LOGGED_IN_KEY',     'T/#XE7+{EsL1cB:NPo&uM==PqzI)aNQG^.%)0!l^^lDH2E5[|G{e:W6dS=CjOZT7' );
define( 'NONCE_KEY',         'T72@1Qwrap;)Ztb%fGY(gq9gL8cO2Mw@h[(#1r@v/ug;VTF#oliIlUkyt8+_~HQ=' );
define( 'AUTH_SALT',         'Wh9@hm>%D?nLwPAI6]ERp*;GA-;0^UDij^.O*y?&8=&.11b%`4J}SoT?nd7PQdQx' );
define( 'SECURE_AUTH_SALT',  'Q,I7.]VJvjTIS/h&Ci4U[=&v&|= d3YN) ,xnFJ1ns5i I:SOBvJqAqwFp<%Z}>f' );
define( 'LOGGED_IN_SALT',    'Wy(d7AV?+EF#QEg:Ga@U@-WAT?(@-N]ck9tc(&*+h5%a_^ydda>KoMI[#RAP.,/-' );
define( 'NONCE_SALT',        '/6V`FBh{M}e*r8w,{bWc>j;=s [;,Jvi@#IY>MkgE`MGESd<I%bDPw7F(VVcw:gg' );
define( 'WP_CACHE_KEY_SALT', 'b[0jiym L>}8b=rt^#FXul;C5?CM0#[.YnS*SoP42N-R6^`7Lv]dprj6,Y]-Cp(Y' );

define('PC_GTIN_META_KEY', '_global_unique_id');

$table_prefix = 'wp_';

// Общие оптимизации
define('WP_CACHE', true);
define('WP_POST_REVISIONS', 10);
define('EMPTY_TRASH_DAYS', 7);
define('AUTOMATIC_UPDATER_DISABLED', true);

// HTTPS behind proxy
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}