<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/private/telegram.php';
$n=0;
function ok(bool $v,string $s):void{global$n;if(!$v)throw new RuntimeException('FAILED '.$s);echo 'PASS '.$s.PHP_EOL;$n++;}
function no(callable $f,string $s):void{try{$f();}catch(PaymentError $e){ok(true,$s);return;}ok(false,$s);}
$tmp=sys_get_temp_dir().'/realty-bot-test-'.bin2hex(random_bytes(8));mkdir($tmp,0700);$db=payment_db($tmp);telegram_db($db);
$pc=['shop_id'=>'fake-shop','mode'=>'live','receipt'=>['vat_code'=>1,'payment_mode'=>'full_payment','payment_subject'=>'service'],'consent_version'=>'fixture','installation_secret'=>str_repeat('s',64)];
$tc=['enabled'=>true,'bot_username'=>'startreality_bot','chat_id'=>-100123,'webhook_secret'=>str_repeat('w',64)];
$provider=[];$calls=[];$seq=0;$tgFail='';$paymentFail=false;
$tg=function($method,$params=[])use(&$calls,&$seq,&$tgFail){$calls[]=[$method,$params];if($tgFail===$method){$tgFail='';throw new PaymentError(503,'fixture failure');}return match($method){'createChatInviteLink'=>['invite_link'=>'https://t.me/+fixture'.++$seq,'creates_join_request'=>true],'getChatMember'=>['status'=>'left'],default=>true};};
$api=function($method,$path)use(&$provider,&$paymentFail){if($paymentFail)throw new PaymentError(503,'fixture timeout');return $provider[substr($path,9)]??throw new PaymentError(503,'unknown fixture');};
function orderFixture(string $status='succeeded',bool $test=false):array{global$db,$pc,$provider;$inp=['name'=>'Fixture','email'=>'fixture@example.invalid','phone'=>'+70000000000','telegram'=>'fixture_user','consent'=>true,'request_id'=>bin2hex(random_bytes(16))];$cfg=$pc;$cfg['mode']=$test?'test':'live';return payment_order($db,$cfg,'owner',$inp,function($m,$p,$body,$key)use(&$provider,$status,$test){$pid='fixture-'.$key;$v=['id'=>$pid,'metadata'=>$body['metadata'],'amount'=>$body['amount'],'recipient'=>['account_id'=>'fake-shop'],'test'=>$test,'paid'=>$status==='succeeded','status'=>$status,'refunded_amount'=>['value'=>'0.00','currency'=>'RUB']];$provider[$pid]=$v;return$v;});}
function claim(array $r):string{global$db,$tc,$pc;$state=telegram_browser($db,$tc,$pc,$r);parse_str(parse_url($state['bot_url'],PHP_URL_QUERY),$q);return$q['start'];}
function startMessage(string $token,int $uid=101):array{return ['chat'=>['id'=>$uid,'type'=>'private'],'from'=>['id'=>$uid,'is_bot'=>false,'first_name'=>'Fixture','username'=>'fixture_user'],'text'=>'/start '.$token];}
function countCalls(string $method):int{global$calls;return count(array_filter($calls,fn($v)=>$v[0]===$method));}
foreach(['pending','waiting_for_capture','canceled']as$status){$r=orderFixture($status);ok(telegram_browser($db,$tc,$pc,$r)===['available'=>false],$status.' does not expose claim');no(fn()=>telegram_deliver($db,$tc,$r['id'],$api,$tg),$status.' cannot create invite');}
$r=orderFixture('succeeded',true);ok(telegram_browser($db,$tc,$pc,$r)===['available'=>false],'test payment cannot expose real chat');no(fn()=>telegram_deliver($db,$tc,$r['id'],$api,$tg),'test payment cannot issue real invite');
$r=orderFixture();$db->prepare('UPDATE orders SET paid_at=1 WHERE id=?')->execute([$r['id']]);no(fn()=>telegram_deliver($db,$tc,$r['id'],$api,$tg),'expired access cannot get invitation');
no(fn()=>telegram_auth($tc,''),'unsigned webhook rejected');no(fn()=>telegram_auth($tc,str_repeat('x',64)),'forged webhook rejected');telegram_auth($tc,str_repeat('w',64));ok(true,'correct webhook secret accepted');
telegram_update($db,$tc,['update_id'=>1,'message'=>startMessage(str_repeat('z',43))],$api,$tg);ok(countCalls('createChatInviteLink')===0,'unknown order token gives no invitation');
$r=orderFixture();$token=claim($r);ok(strlen($token)===43,'deep link uses opaque 256-bit claim');$msg=startMessage($token);
no(fn()=>telegram_confirm($db,$tc,$r['id'],'101',$api,$tg),'cannot confirm account before bot handshake');
telegram_update($db,$tc,['update_id'=>2,'message'=>$msg],$api,$tg);
ok(telegram_access($db,$r['id'])['candidate_id']==='101'&&telegram_access($db,$r['id'])['user_id']===null,'bot start only proposes account');ok(countCalls('createChatInviteLink')===0,'paid order still needs browser confirmation');
$before=count($calls);telegram_update($db,$tc,['update_id'=>2,'message'=>$msg],$api,$tg);ok(count($calls)===$before,'repeated Telegram update is ignored');
no(fn()=>telegram_confirm($db,$tc,$r['id'],'202',$api,$tg),'wrong candidate cannot be confirmed');
$group=$msg;$group['chat']=['id'=>-100123,'type'=>'supergroup'];telegram_update($db,$tc,['update_id'=>3,'message'=>$group],$api,$tg);ok(countCalls('createChatInviteLink')===0,'group command cannot claim buyer identity');
$forged=$msg;$forged['from']['id']=202;telegram_update($db,$tc,['update_id'=>4,'message'=>$forged],$api,$tg);ok(telegram_access($db,$r['id'])['candidate_id']==='101','private chat/from mismatch ignored');
$provider[$r['payment_id']]['status']='pending';no(fn()=>telegram_confirm($db,$tc,$r['id'],'101',$api,$tg),'cached paid status cannot override provider pending');ok(countCalls('createChatInviteLink')===0,'fresh pending check creates zero invitations');
$provider[$r['payment_id']]['status']='succeeded';$provider[$r['payment_id']]['refunded_amount']['value']='4990.00';no(fn()=>telegram_confirm($db,$tc,$r['id'],'101',$api,$tg),'refunded payment cannot get access');
$provider[$r['payment_id']]['refunded_amount']['value']='0.00';$paymentFail=true;no(fn()=>telegram_confirm($db,$tc,$r['id'],'101',$api,$tg),'payment network failure denies delivery');$paymentFail=false;
telegram_confirm($db,$tc,$r['id'],'101',$api,$tg);$a=telegram_access($db,$r['id']);ok($a['user_id']==='101'&&$a['state']==='invited','paid confirmed buyer gets invitation');ok(countCalls('createChatInviteLink')===1,'one invitation created');
$creation=array_values(array_filter($calls,fn($v)=>$v[0]==='createChatInviteLink'))[0][1];ok($creation['creates_join_request']===true&&!isset($creation['member_limit'])&&$creation['expire_date']<=time()+900,'invitation requires approval and expires in 15 minutes');
$before=count($calls);telegram_confirm($db,$tc,$r['id'],'101',$api,$tg);ok(count($calls)===$before,'repeat confirmation reuses delivered invitation');
telegram_update($db,$tc,['update_id'=>5,'message'=>startMessage($token,202)],$api,$tg);ok(telegram_access($db,$r['id'])['user_id']==='101'&&countCalls('createChatInviteLink')===1,'forwarded bot link cannot take bound order');
$j=['chat'=>['id'=>-100123],'from'=>['id'=>202],'invite_link'=>['invite_link'=>$a['invite_url']]];
telegram_update($db,$tc,['update_id'=>6,'chat_join_request'=>$j],$api,$tg);ok(countCalls('approveChatJoinRequest')===0&&countCalls('declineChatJoinRequest')===1,'forwarded chat invite denies stranger');
$j['from']['id']=101;$provider[$r['payment_id']]['refunded_amount']['value']='1.00';telegram_update($db,$tc,['update_id'=>7,'chat_join_request'=>$j],$api,$tg);ok(countCalls('approveChatJoinRequest')===0,'refund after invitation blocks joining');
$provider[$r['payment_id']]['refunded_amount']['value']='0.00';$paymentFail=true;no(fn()=>telegram_update($db,$tc,['update_id'=>8,'chat_join_request'=>$j],$api,$tg),'join retries on provider timeout without approving');$paymentFail=false;
$tgFail='revokeChatInviteLink';no(fn()=>telegram_update($db,$tc,['update_id'=>8,'chat_join_request'=>$j],$api,$tg),'revoke failure remains retryable');ok(telegram_access($db,$r['id'])['state']==='joined','successful approval stored before revoke retry');
telegram_update($db,$tc,['update_id'=>8,'chat_join_request'=>$j],$api,$tg);ok(countCalls('approveChatJoinRequest')===1&&(int)telegram_access($db,$r['id'])['revoke_pending']===0,'retry revokes without duplicate approval');ok(payment_find($db,$r['id'])['fulfillment_status']==='delivered','delivery recorded');
$before=count($calls);telegram_update($db,$tc,['update_id'=>8,'chat_join_request'=>$j],$api,$tg);ok(count($calls)===$before,'duplicate join webhook has no side effects');
telegram_deliver($db,$tc,$r['id'],$api,$tg);ok(countCalls('createChatInviteLink')===1,'used order cannot create another invite');
$r2=orderFixture();$token2=claim($r2);telegram_start($db,$tc,startMessage($token2,303),$api,$tg);$tgFail='sendMessage';no(fn()=>telegram_confirm($db,$tc,$r2['id'],'303',$api,$tg),'delivery message failure is retryable');ok(telegram_browser($db,$tc,$pc,payment_find($db,$r2['id']))['delivery_pending']===true,'browser offers delivery retry after Telegram failure');$before=countCalls('createChatInviteLink');telegram_confirm($db,$tc,$r2['id'],'303',$api,$tg);ok(countCalls('createChatInviteLink')===$before,'retry after send failure reuses invitation');
$a2=telegram_access($db,$r2['id']);$db->prepare('UPDATE telegram_access SET invite_expires=1 WHERE order_id=?')->execute([$r2['id']]);$j2=['chat'=>['id'=>-100123],'from'=>['id'=>303],'invite_link'=>['invite_link'=>$a2['invite_url']]];$before=countCalls('approveChatJoinRequest');telegram_join($db,$tc,$j2,$api,$tg);ok(countCalls('approveChatJoinRequest')===$before,'expired invitation cannot approve membership');
$before=count($calls);$j2['chat']['id']=-100999;telegram_join($db,$tc,$j2,$api,$tg);ok(count($calls)===$before,'other chats ignored');
$closed=$tc;$closed['enabled']=false;no(fn()=>telegram_update($db,$closed,['update_id'=>100,'message'=>$msg],$api,$tg),'disabled bot fails closed');
ok(countCalls('createChatInviteLink')===2,'only two independently paid confirmed orders created invitations');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "OK: $n Telegram assertions; all transports simulated; no real payments or invitations".PHP_EOL;
