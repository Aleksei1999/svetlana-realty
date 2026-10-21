<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$root='/var/www/u3681014/data/www/xn--80ajklwp.site';
$private='/var/www/u3681014/data/realty-private';
$script='<script defer src="/metrika.js?v=113667245-1"></script>';
$pixel='<noscript><div><img src="https://mc.yandex.ru/watch/113667245" style="position:absolute;left:-9999px;" alt="" /></div></noscript>';
if(!is_file(__DIR__.'/public/metrika.js'))throw new RuntimeException('Counter source missing');
$pages=['index.html','checkout/index.html','legal/index.html'];$prepared=[];
foreach($pages as$file){
 $html=(string)file_get_contents($root.'/'.$file);
 if(!str_contains($html,'</head>')||!preg_match('/<body\b[^>]*>/i',$html))throw new RuntimeException('Unexpected page structure: '.$file);
 if(!str_contains($html,'/metrika.js?'))$html=str_replace('</head>',$script.'</head>',$html);
 if(!str_contains($html,'mc.yandex.ru/watch/113667245'))$html=preg_replace('/(<body\b[^>]*>)/i','$1'.$pixel,$html,1);
 $prepared[$file]=$html;
}
$stamp=date('Ymd-His');
foreach($pages as$file){$backup=$private.'/'.str_replace('/','-',$file).'.before-metrika-'.$stamp;copy($root.'/'.$file,$backup);chmod($backup,0600);}
if(is_file($root.'/metrika.js')){copy($root.'/metrika.js',$private.'/metrika.js.before-'.$stamp);chmod($private.'/metrika.js.before-'.$stamp,0600);}
copy(__DIR__.'/public/metrika.js',$root.'/metrika.js');chmod($root.'/metrika.js',0644);
foreach($prepared as$file=>$html){file_put_contents($root.'/'.$file,$html);chmod($root.'/'.$file,0644);}
echo json_encode(['installed'=>true,'counter'=>113667245,'pages'=>$pages,'admin_tracked'=>false,'test_checkout_tracked'=>false],JSON_UNESCAPED_UNICODE).PHP_EOL;
