<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/telegram.php';
umask(0077);
$worker=fopen(__DIR__.'/locks/telegram-poll.lock','c');
if(!$worker||!flock($worker,LOCK_EX|LOCK_NB))exit;
$db=null;
try {
    $tc=telegram_config(__DIR__);if(($tc['enabled']??false)!==true||($tc['transport']??'')!=='polling')exit;
    $pc=payment_config(__DIR__);$db=payment_db(__DIR__);telegram_db($db);
    $api=fn($m,$p,$b=null,$k=null)=>payment_api($pc,$m,$p,$b,$k);
    $tg=fn($m,$p=[])=>telegram_api($tc,$m,$p);
    $deadline=time()+(in_array('--once',$argv,true)?0:45);
    do {
        $updates=$tg('getUpdates',['offset'=>telegram_cursor($db),'limit'=>10,'timeout'=>in_array('--once',$argv,true)?0:5,'allowed_updates'=>['message','chat_join_request']]);
        telegram_batch($db,$updates,fn($u)=>telegram_lock(__DIR__,fn()=>telegram_update($db,$tc,$u,$api,$tg)));
        telegram_runtime_set($db,'last_poll_ok',(string)time());
        telegram_runtime_set($db,'last_error','');
    }while(time()<$deadline);
    if(in_array('--once',$argv,true))echo 'Telegram poll completed; no credentials displayed.'.PHP_EOL;
} catch(Throwable $e) {
    if($db)telegram_runtime_set($db,'last_error',get_class($e));
    error_log('realty-telegram-poll: '.get_class($e));exit(1);
} finally {flock($worker,LOCK_UN);fclose($worker);}
