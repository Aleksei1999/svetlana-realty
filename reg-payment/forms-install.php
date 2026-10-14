<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$public='/var/www/u3681014/data/www/xn--80ajklwp.site';
$private='/var/www/u3681014/data/realty-private';
if(!is_file(__DIR__.'/site-app.js')||!is_file(__DIR__.'/public/lead-entry.js'))throw new RuntimeException('Frontend files missing');
$html=(string)file_get_contents($public.'/index.html');
$html=str_replace('<button class="pill light" data-form="start">Купить «Старт» за 4 990 ₽</button>','<a class="pill light" href="https://www.xn--80ajklwp.site/checkout/">Купить «Старт» за 4 990 ₽</a>',$html);
$html=str_replace('Предпросмотр анкеты. Приём заявок и оплата пока не открыты. Введённые данные никуда не отправляются.','Проверяем доступность отправки заявки…',$html);
$html=str_replace('<button class="pill gold" type="submit">Проверить заполнение <span>↗</span></button>','<button class="pill gold" type="submit" disabled>Отправить заявку <span>↗</span></button>',$html);
$html=preg_replace('~app\.js\?v=[^"\s]+~','app.js?v=live-forms-20261014',$html);
$html=preg_replace('~/lead-entry\.js\?v=[^"\s]+~','/lead-entry.js?v=live-forms-20261014',$html);
if(str_contains($html,'Предпросмотр анкеты')||str_contains($html,'Проверить заполнение')||!str_contains($html,'href="https://www.xn--80ajklwp.site/checkout/"'))throw new RuntimeException('Expected form markup not found');
$stamp=date('Ymd-His');
foreach(['index.html','app.js','lead-entry.js']as$f){copy($public.'/'.$f,$private.'/'.$f.'.before-form-fix-'.$stamp);chmod($private.'/'.$f.'.before-form-fix-'.$stamp,0600);}
copy(__DIR__.'/site-app.js',$public.'/app.js');copy(__DIR__.'/public/lead-entry.js',$public.'/lead-entry.js');
file_put_contents($public.'/index.html',$html);
foreach(['index.html','app.js','lead-entry.js']as$f)chmod($public.'/'.$f,0644);
echo "Live checkout link and Magic application form deployed.\n";
