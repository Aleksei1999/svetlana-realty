<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
umask(0077);
$root='/var/www/u3681014/data/www/xn--80ajklwp.site';
foreach(['offer','privacy','consent','marketing']as$slug){
 $f=$root.'/legal/'.$slug.'-2026-10-14.pdf';
 if(!is_file($f)||filesize($f)<1000)throw new RuntimeException('Legal document missing: '.$slug);
}
$path=__DIR__.'/config.json';$c=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
$backup=__DIR__.'/config-before-legal-20261014.json';if(!is_file($backup))copy($path,$backup);
$c['documents_approved']=true;$c['consent_version']='2026-10-14';
foreach(['offer','privacy','consent']as$slug)$c[$slug.'_url']='https://www.xn--80ajklwp.site/legal/'.$slug.'-2026-10-14.pdf';
file_put_contents($path.'.new',json_encode($c,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);chmod($path.'.new',0600);rename($path.'.new',$path);
echo json_encode(['documents_connected'=>true,'consent_version'=>$c['consent_version'],'payment_enabled'=>$c['enabled']],JSON_UNESCAPED_UNICODE).PHP_EOL;
