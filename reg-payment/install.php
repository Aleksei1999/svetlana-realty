<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$home='/var/www/u3681014/data';
$public=$home.'/www/xn--80ajklwp.site';
$private=$home.'/realty-private';
if(!is_file($public.'/index.html'))throw new RuntimeException('Website not found');
foreach (['curl','pdo_sqlite'] as $ext) if(!extension_loaded($ext))throw new RuntimeException('Missing '.$ext);
umask(0077);
if(!is_dir($private))mkdir($private,0700,true);
foreach(glob(__DIR__.'/private/*')as$f){copy($f,$private.'/'.basename($f));chmod($private.'/'.basename($f),0600);}
require $private.'/initialize.php';
foreach(['api','checkout','admin']as$dir){if(!is_dir($public.'/'.$dir))mkdir($public.'/'.$dir,0755);chmod($public.'/'.$dir,0755);foreach(array_merge(glob(__DIR__.'/public/'.$dir.'/*'),glob(__DIR__.'/public/'.$dir.'/.htaccess'))as$f){copy($f,$public.'/'.$dir.'/'.basename($f));chmod($public.'/'.$dir.'/'.basename($f),0644);}}
copy(__DIR__.'/public/payment-entry.js',$public.'/payment-entry.js');chmod($public.'/payment-entry.js',0644);
$html=file_get_contents($public.'/index.html');
if(!str_contains($html,'payment-entry.js')){
 copy($public.'/index.html',$private.'/index-before-payment.html');
 $html=str_replace('</head>','<script defer src="/payment-entry.js?v=1"></script></head>',$html);
 file_put_contents($public.'/index.html',$html);
}
copy(__DIR__.'/public/lead-entry.js',$public.'/lead-entry.js');chmod($public.'/lead-entry.js',0644);
$html=file_get_contents($public.'/index.html');
if(!str_contains($html,'lead-entry.js')){file_put_contents($public.'/index.html',str_replace('</head>','<script defer src="/lead-entry.js?v=1"></script></head>',$html));}
if(!is_file($private.'/admin-password.txt')){touch($private.'/admin-password.txt');chmod($private.'/admin-password.txt',0600);}
require_once $private.'/admin-core.php';crm_db(payment_db($private));
echo 'Payment module deployed; enabled flag unchanged.'.PHP_EOL;
