<?php
declare(strict_types=1);require __DIR__.'/../private/admin-core.php';
$dir=sys_get_temp_dir().'/realty-admin-'.bin2hex(random_bytes(8));mkdir($dir,0700);$db=payment_db($dir);telegram_db($db);crm_db($db);$n=0;
function check(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);echo 'PASS '.$m.PHP_EOL;$n++;}
function blocked(callable $f,int $status,string $m):void{try{$f();throw new RuntimeException('Not blocked');}catch(PaymentError $e){check($e->http===$status,$m);}}
$c=['documents_approved'=>true,'consent_version'=>'test','privacy_url'=>'https://www.xn--80ajklwp.site/privacy','consent_url'=>'https://www.xn--80ajklwp.site/consent'];
$in=['name'=>'Test <script>','email'=>'qa@example.invalid','phone'=>'+79999999999','telegram'=>'test_user','consent'=>true,'request_id'=>str_repeat('a',32),'goal'=>'Test goal'];
blocked(fn()=>crm_lead($db,[],'owner',$in,'magic'),503,'unconfigured lead collection blocked');
blocked(fn()=>crm_lead($db,$c,'owner',array_merge($in,['consent'=>false]),'magic'),422,'consent required');
blocked(fn()=>crm_lead($db,$c,'owner',array_merge($in,['phone'=>'wrong']),'magic'),422,'invalid contact rejected');
$id=crm_lead($db,$c,'owner',$in,'magic');check(crm_lead($db,$c,'owner',$in,'magic')===$id,'duplicate submission reuses lead');
$rows=crm_rows($db);check(count($rows)===1&&$rows[0]['product']==='Магия','magic application recorded');check(!isset($rows[0]['request_key'])&&!isset($rows[0]['owner']),'internal session identifiers excluded');
crm_update($db,['id'=>$id,'type'=>'lead','note'=>'Called','status'=>'contacted']);check(crm_rows($db)[0]['status']==='contacted','lead status saved');
blocked(fn()=>crm_update($db,['id'=>$id,'type'=>'lead','note'=>'','status'=>'succeeded']),422,'cannot mark lead as paid');
$intent=crm_lead($db,$c,'buyer',array_merge($in,['request_id'=>str_repeat('b',32)]),'intent');check(count(crm_rows($db))===2,'payment intention recorded');
$pc=['shop_id'=>'1362860','mode'=>'test','receipt'=>['vat_code'=>1,'payment_mode'=>'full_payment','payment_subject'=>'service'],'consent_version'=>'test'];
try{payment_order($db,$pc,'buyer',array_merge($in,['request_id'=>str_repeat('b',32)]),fn()=>throw new PaymentError(503,'network'));}catch(PaymentError $e){}
$rows=crm_rows($db);check(count($rows)===2,'intention and order not double counted');$o=array_values(array_filter($rows,fn($r)=>$r['type']==='order'))[0];check($o['status']==='creating','failed provider request remains visible');
check(!isset($o['confirmation_token'])&&!isset($o['payload'])&&!isset($o['invite_url']),'payment secrets absent from admin projection');
crm_update($db,['id'=>$o['id'],'type'=>'order','note'=>'Follow up','status'=>'succeeded']);check(payment_find($db,$o['id'])['status']==='creating','notes cannot forge payment status');
check(admin_config($dir)===null,'admin closed before password setup');$ac=['version'=>'v1'];$session=['admin_version'=>'v1','login_at'=>time(),'seen_at'=>time()];check(admin_authenticated($ac,$session),'valid session accepted');check(!admin_authenticated($ac,[]),'anonymous rejected');check(!admin_authenticated(['version'=>'v2'],$session),'password rotation invalidates session');check(!admin_authenticated($ac,array_merge($session,['seen_at'=>time()-1801])),'idle timeout enforced');check(!admin_authenticated($ac,array_merge($session,['login_at'=>time()-28801])),'absolute timeout enforced');
$db=null;foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);echo 'OK: '.$n.' admin assertions; isolated temporary database'.PHP_EOL;
