<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../private/admin-core.php';require __DIR__.'/../private/probe.php';
$n=0;function ok(bool $v,string $s):void{global $n;if(!$v)throw new RuntimeException('FAILED: '.$s);$n++;echo 'PASS '.$s.PHP_EOL;}
function rejects(callable $f,int $code,string $s):void{try{$f();}catch(PaymentError $e){ok($e->http===$code,$s);return;}ok(false,$s);}
$root=sys_get_temp_dir().'/probe-test-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/locks',0700);
try{
$db=payment_db($root);telegram_db($db);crm_db($db);
$c=['shop_id'=>'1362860','secret_key'=>'fixture-only','mode'=>'live','enabled'=>false,'probe_enabled'=>true,'documents_approved'=>true,'fulfillment_ready'=>false,'test_payment_verified'=>false,'consent_version'=>'fixture','offer_url'=>'https://www.xn--80ajklwp.site/legal/offer.pdf','privacy_url'=>'https://www.xn--80ajklwp.site/legal/privacy.pdf','consent_url'=>'https://www.xn--80ajklwp.site/legal/consent.pdf','receipt'=>['vat_code'=>1,'payment_mode'=>'full_payment','payment_subject'=>'service']];
$input=['name'=>'Fixture','email'=>'fixture@example.invalid','phone'=>'+70000000000','telegram'=>'fixture_user','consent'=>true,'real_payment_ack'=>true,'request_id'=>str_repeat('a',32),'amount'=>'0.01','product'=>'start'];
$calls=0;$provider=[];$api=function($m,$p,$body=null,$key=null)use(&$calls,&$provider){$calls++;if($m==='GET')return $provider[substr($p,9)];$id='fixture-'.$key;return $provider[$id]=['id'=>$id,'metadata'=>$body['metadata'],'amount'=>$body['amount'],'recipient'=>['account_id'=>'1362860'],'test'=>false,'paid'=>false,'status'=>'pending','confirmation'=>['confirmation_token'=>'fixture-token']];};
$act=fn($action,$in=[])=>probe_action($action,$db,$c,'admin-owner',$in,$root,$api);
ok(probe_missing($c)===[],'probe can be ready while main checkout stays closed');ok(payment_missing($c)!==[]&&!$c['enabled'],'course launch gates preserved');
foreach(['probe_enabled','secret_key','documents_approved']as$key){$closed=$c;$closed[$key]=false;ok(probe_missing($closed)!==[],'missing '.$key.' blocks probe');}
$closed=$c;$closed['receipt']['payment_mode']='';$before=$calls;
rejects(fn()=>probe_action('probe-create',$db,$closed,'admin-owner',$input,$root,$api),503,'unconfirmed fiscal settings block create');
ok($calls===$before&&(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===0,'blocked create calls no provider and writes no orders');
$closed=$c;$closed['mode']='test';ok(probe_missing($closed)!==[],'page cannot silently become a no-charge simulator');
$bad=$input;$bad['real_payment_ack']=false;rejects(fn()=>$act('probe-create',$bad),422,'explicit real charge acknowledgement required');
$r=$act('probe-create',$input);$row=payment_find($db,$r['order_id']);
ok($row['amount']==='10.00'&&$row['product']==='probe10'&&!$row['test'],'server sets fixed real 10 RUB product');
$payload=json_decode($row['payload'],true);ok($payload['receipt']['items'][0]['amount']['value']==='10.00'&&$payload['metadata']['product']==='probe10','receipt and metadata match probe');
ok($payload['confirmation']['type']==='embedded'&&$payload['capture']===true,'embedded capture preserved');
ok($r['fulfillment_status']==='not_applicable','no course fulfillment for probe');
$again=$act('probe-create',$input);ok($again['order_id']===$r['order_id']&&$calls===1,'repeat click reuses provider payment');
rejects(fn()=>payment_order($db,$c,'admin-owner',$input,$api,'start'),409,'cannot reuse probe request as course order');
$other=$input;$other['request_id']=str_repeat('b',32);$other['product']='probe10';$course=payment_order($db,$c,'buyer-owner',$other,$api);
ok($course['amount']==='4990.00'&&$course['product']==='start','public checkout ignores client product and amount');
rejects(fn()=>$act('probe-status',['order_id'=>$course['id']]),404,'probe route rejects course order');
rejects(fn()=>probe_action('probe-status',$db,$c,'wrong-owner',['order_id'=>$row['id']],$root,$api),404,'probe route rejects different owner');
$payment=$provider[$row['payment_id']];$payment['status']='succeeded';$payment['paid']=true;$payment['receipt_registration']='pending';
foreach(['amount','product','shop','mode','paid']as$k){$bad=$payment;switch($k){case 'amount':$bad['amount']['value']='4990.00';break;case 'product':$bad['metadata']['product']='start';break;case 'shop':$bad['recipient']['account_id']='other';break;case 'mode':$bad['test']=true;break;case 'paid':$bad['paid']=false;break;}rejects(fn()=>payment_apply($db,$row,$bad),409,'mismatch '.$k.' cannot confirm payment');}
payment_apply($db,$row,$payment);$paid=payment_find($db,$row['id']);ok($paid['status']==='succeeded'&&$paid['paid_at']!==null,'verified probe success saved');ok(!telegram_eligible($paid),'paid probe cannot grant Telegram access');$spoof=$paid;$spoof['amount']='4990.00';ok(!telegram_eligible($spoof),'product blocks course access even with altered amount');
rejects(fn()=>telegram_assert_paid($db,$row['id'],$api),402,'server access verification rejects probe');
$payment['receipt_registration']='succeeded';$provider[$row['payment_id']]=$payment;$db->exec('UPDATE orders SET checked_at=0');$status=$act('probe-status',['order_id'=>$row['id']]);ok($status['receipt_status']==='succeeded','receipt can complete after payment success');
$stale=$payment;$stale['status']='pending';$stale['paid']=false;$stale['receipt_registration']='pending';payment_apply($db,$row,$stale);$final=payment_find($db,$row['id']);ok($final['status']==='succeeded'&&$final['receipt_status']==='succeeded','stale notification cannot downgrade payment or receipt');
$rows=crm_rows($db);$probes=array_values(array_filter($rows,fn($v)=>$v['probe']));ok(count($probes)===1&&$probes[0]['product']==='Проверка · 10 ₽'&&!$probes[0]['test'],'admin distinguishes real probe from provider test');
ok(array_intersect_key($status,array_fill_keys(['email','owner','payload','telegram','name'],true))===[],'status hides customer fields');
$db->exec('ALTER TABLE orders DROP COLUMN product');$db=null;$db=payment_db($root);ok(payment_find($db,$course['id'])['product']==='start'&&(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn()===2,'migration preserves old orders and defaults to course');
echo 'OK: '.$n.' probe assertions; mocked provider, no real charge'.PHP_EOL;
}finally{$db=null;foreach(glob($root.'/locks/*')as$f)unlink($f);rmdir($root.'/locks');foreach(glob($root.'/*')as$f)unlink($f);rmdir($root);}
