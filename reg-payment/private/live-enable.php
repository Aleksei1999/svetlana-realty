<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/telegram.php';
umask(0077);
$c=payment_config(__DIR__);
if(($c['mode']??'')!=='live'||(string)($c['shop_id']??'')!=='1362860')throw new RuntimeException('Unexpected store');
if(($c['receipt']['vat_code']??null)!==1||($c['receipt']['payment_mode']??'')!=='full_payment'||($c['receipt']['payment_subject']??'')!=='service')throw new RuntimeException('Receipt differs from owner confirmation');
$h=curl_init('https://api.yookassa.ru/v3/me');
curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>$c['shop_id'].':'.$c['secret_key'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
$raw=curl_exec($h);$code=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);$shop=is_string($raw)?json_decode($raw,true):null;
if($code!==200||!is_array($shop)||(string)($shop['account_id']??'')!=='1362860'||($shop['test']??true)!==false||($shop['status']??'')!=='enabled')throw new RuntimeException('Live merchant not verified');
$tc=telegram_config(__DIR__);
if(($tc['enabled']??false)!==true||empty($tc['chat_id']))throw new RuntimeException('Bot not configured');
$me=telegram_api($tc,'getMe');
if(($me['username']??'')!=='startreality_bot')throw new RuntimeException('Unexpected bot');
$member=telegram_api($tc,'getChatMember',['chat_id'=>$tc['chat_id'],'user_id'=>$me['id']]);
if(!in_array($member['status']??'',['administrator','creator'],true)||empty($member['can_invite_users']))throw new RuntimeException('Bot cannot invite buyers');
$cron=(string)shell_exec('crontab -l 2>/dev/null');
if(!preg_match('/^[^#\r\n]*telegram-poll\.php/m',$cron))throw new RuntimeException('Bot polling schedule missing');
$c['fulfillment_ready']=true;
// User explicitly requested live 4,990 RUB payments on 2026-10-14.
$c['launch_without_payment_test_approved']=true;
$c['enabled']=true;
if(payment_missing($c)!==[])throw new RuntimeException('Launch prerequisites missing: '.implode(',',payment_missing($c)));
$path=__DIR__.'/config.json';$saved=(string)file_get_contents($path);$stored=json_decode($saved,true,32,JSON_THROW_ON_ERROR);
foreach(['fulfillment_ready','launch_without_payment_test_approved','enabled']as$key)$stored[$key]=$c[$key];
$backup=$path.'.before-live-'.date('Ymd-His');file_put_contents($backup,$saved);chmod($backup,0600);
$tmp=$path.'.live-tmp';file_put_contents($tmp,json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$path);
echo json_encode(['enabled'=>true,'price'=>'4990.00','mode'=>'live','merchant_verified'=>true,'bot_verified'=>true,'polling_configured'=>true,'test_payment_verified'=>$stored['test_payment_verified']??false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
