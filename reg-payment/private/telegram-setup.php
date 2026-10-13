<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/telegram.php';
umask(0077);
try {
$c=telegram_config(__DIR__);
if(empty($c['chat_id'])||empty($c['token']))exit("Bot token and verified chat required\n");
$b=telegram_api($c,'getMe');
if(($b['username']??'')!=='startreality_bot')exit("Unexpected bot; no settings changed\n");
$r=telegram_api($c,'getChatMember',['chat_id'=>$c['chat_id'],'user_id'=>$b['id']]);
if(($r['status']??'')!=='administrator'||!($r['can_invite_users']??false))exit("Bot needs administrator invite permission\n");
$c['webhook_secret']??=bin2hex(random_bytes(32));
$c['enabled']=true;$c['transport']=in_array('--poll',$argv,true)?'polling':'webhook';$c['bot_id']=$b['id'];$c['bot_username']=$b['username'];
unset($c['token']);
$tmp=__DIR__.'/telegram-config.tmp';
file_put_contents($tmp,json_encode($c,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));chmod($tmp,0600);rename($tmp,__DIR__.'/telegram-config.json');
telegram_db(payment_db(__DIR__));
$c=telegram_config(__DIR__);
$url='https://www.xn--80ajklwp.site/api/index.php?action=telegram';
if($c['transport']==='polling')telegram_api($c,'deleteWebhook',['drop_pending_updates'=>false]);
else telegram_api($c,'setWebhook',['url'=>$url,'secret_token'=>$c['webhook_secret'],'max_connections'=>2,'allowed_updates'=>['message','chat_join_request'],'drop_pending_updates'=>false]);
$info=telegram_api($c,'getWebhookInfo');
echo json_encode(['bot'=>$b['username'],'transport'=>$c['transport'],'transport_ready'=>$c['transport']==='polling'?empty($info['url']):($info['url']??'')===$url,'pending_updates'=>$info['pending_update_count']??null,'payment_enabled'=>payment_config(__DIR__)['enabled']],JSON_PRETTY_PRINT).PHP_EOL;

} catch(Throwable $e) { echo "Bot setup could not complete: ".get_class($e).PHP_EOL; exit(1); }
