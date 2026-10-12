<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/private/core.php';
$count=0;
function ok(bool $v,string $name):void{global $count;if(!$v)throw new RuntimeException('FAILED: '.$name);$count++;echo 'PASS '.$name.PHP_EOL;}
function rejects(callable $f,string $name):void{try{$f();}catch(PaymentError $e){ok(true,$name);return;}ok(false,$name);}
$c=['shop_id'=>'test-shop','mode'=>'test','secret_key'=>'test-only','receipt'=>['vat_code'=>1,'payment_mode'=>'full_payment','payment_subject'=>'service'],'consent_version'=>'test'];
$tmp=sys_get_temp_dir().'/realty-test-'.bin2hex(random_bytes(8));mkdir($tmp,0700);$db=payment_db($tmp);
$input=['name'=>'Тест','email'=>'test@example.com','phone'=>'+70000000000','telegram'=>'test_user','consent'=>true,'request_id'=>str_repeat('a',32),'amount'=>'1.00'];
$calls=[];$provider=function($method,$path,$payload,$key)use(&$calls){$calls[]=[$method,$path,$payload,$key];return ['id'=>'payment-1','metadata'=>$payload['metadata'],'amount'=>$payload['amount'],'recipient'=>['account_id'=>'test-shop'],'test'=>true,'paid'=>false,'status'=>'pending','confirmation'=>['confirmation_token'=>'test-token']];};
$row=payment_order($db,$c,'owner-1',$input,$provider);
ok($row['amount']==='4990.00','client cannot lower price');
ok($calls[0][2]['receipt']['items'][0]['amount']['value']==='4990.00','receipt equals server price');
ok($calls[0][2]['confirmation']['type']==='embedded','payment remains on website');
ok($calls[0][2]['capture']===true,'one-stage capture');
$again=payment_order($db,$c,'owner-1',$input,$provider);
ok(count($calls)===1&&$again['id']===$row['id'],'repeat click reuses same payment');
$success=['id'=>'payment-1','metadata'=>['order_id'=>$row['id']],'amount'=>['value'=>'4990.00','currency'=>'RUB'],'recipient'=>['account_id'=>'test-shop'],'test'=>true,'paid'=>true,'status'=>'succeeded','receipt_registration'=>'succeeded'];
foreach(['amount','currency','shop','mode','metadata','id','paid'] as $field){$bad=$success;switch($field){case 'amount':$bad['amount']['value']='1.00';break;case 'currency':$bad['amount']['currency']='USD';break;case 'shop':$bad['recipient']['account_id']='other';break;case 'mode':$bad['test']=false;break;case 'metadata':$bad['metadata']['order_id']='other';break;case 'id':$bad['id']='payment-2';break;case 'paid':$bad['paid']=false;break;}rejects(fn()=>payment_apply($db,$row,$bad),'reject mismatched '.$field);}
payment_apply($db,$row,$success);$paid=payment_find($db,$row['id']);ok($paid['status']==='succeeded'&&$paid['paid_at']!==null,'verified success stored');
payment_apply($db,$row,$success);ok(payment_find($db,$row['id'])['paid_at']===$paid['paid_at'],'duplicate webhook is idempotent');
$stale=$success;$stale['status']='pending';$stale['paid']=false;payment_apply($db,$row,$stale);ok(payment_find($db,$row['id'])['status']==='succeeded','stale notification cannot downgrade success');
$public=payment_public($paid);ok(array_intersect_key($public,array_fill_keys(['email','name','payload','owner'],true))===[],'status excludes private customer fields');
$input['request_id']=str_repeat('b',32);$attempts=[];
$timeout=function($m,$p,$body,$key)use(&$attempts){$attempts[]=$key;throw new PaymentError(503,'timeout');};
rejects(fn()=>payment_order($db,$c,'owner-2',$input,$timeout),'ambiguous API failure recorded');
rejects(fn()=>payment_order($db,$c,'owner-2',$input,$timeout),'retry still ambiguous');ok(count($attempts)===2&&$attempts[0]===$attempts[1],'retry uses identical provider idempotence key');
$db->exec('UPDATE orders SET created_at=0 WHERE owner="owner-2"');rejects(fn()=>payment_order($db,$c,'owner-2',$input,$timeout),'expired idempotence window cannot create another payment');
$bad=$input;$bad['consent']=false;rejects(fn()=>payment_customer($bad),'consent required');$bad=$input;$bad['email']='bad';rejects(fn()=>payment_customer($bad),'valid email required');
ok(payment_missing(['mode'=>'live'])!==[],'unconfigured production is closed');
payment_rate($db,'test',1,60);rejects(fn()=>payment_rate($db,'test',1,60),'rate limit enforced');
$db=null;foreach(glob($tmp.'/*')as$f)unlink($f);rmdir($tmp);echo "OK: $count assertions".PHP_EOL;
