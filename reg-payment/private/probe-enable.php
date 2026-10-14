<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/admin-core.php';
require_once __DIR__.'/probe.php';
// Explicitly confirmed by the owner on 2026-10-14: service, full payment, no VAT.
umask(0077);
$path=__DIR__.'/config.json';
$raw=(string)file_get_contents($path);
$c=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
$c['receipt']['vat_code']=1;
$c['receipt']['payment_mode']='full_payment';
$c['receipt']['payment_subject']='service';
$c['probe_enabled']=true;
// Keep the main checkout's release flag and fulfillment gates unchanged.
$check=$c;
if(is_file(__DIR__.'/yookassa-key.txt'))$check['secret_key']=trim((string)file_get_contents(__DIR__.'/yookassa-key.txt'));
if(probe_missing($check)!==[])throw new RuntimeException('Probe prerequisites missing: '.implode(',',probe_missing($check)));
if(!admin_config(__DIR__))throw new RuntimeException('Administrator password not configured');
$backup=$path.'.before-probe-'.date('Ymd-His');file_put_contents($backup,$raw);chmod($backup,0600);
$tmp=$path.'.probe-tmp';file_put_contents($tmp,json_encode($c,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,$path);
echo json_encode(['probe_enabled'=>true,'amount'=>'10.00','main_checkout_enabled'=>$c['enabled']??false,'receipt'=>$c['receipt']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
