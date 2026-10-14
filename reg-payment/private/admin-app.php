<?php
declare(strict_types=1);
require_once __DIR__.'/admin-core.php';
require_once __DIR__.'/probe.php';
ini_set('display_errors','0');umask(0077);
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
function admin_reply(array $v,int $code=200): never {http_response_code($code);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;}
try{
 $c=payment_config(__DIR__);$ac=admin_config(__DIR__);$db=payment_db(__DIR__);telegram_db($db);crm_db($db);
 ini_set('session.use_strict_mode','1');session_name('__Host-realty_admin');session_save_path(__DIR__.'/sessions');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_start();
 $_SESSION['csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['csrf'];$auth=admin_authenticated($ac,$_SESSION);$action=$_GET['action']??'session';
 if($action==='session'&&$_SERVER['REQUEST_METHOD']==='GET')admin_reply(['authenticated'=>$auth,'configured'=>$ac!==null,'csrf'=>$csrf]);
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new PaymentError(405,'POST required');
 if(!in_array($_SERVER['HTTP_ORIGIN']??'',['https://www.xn--80ajklwp.site','https://xn--80ajklwp.site'],true)||!hash_equals($csrf,$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new PaymentError(403,'Обновите страницу и повторите.');
 if((int)($_SERVER['CONTENT_LENGTH']??0)>12000)throw new PaymentError(413,'Слишком большой запрос.');
 $in=json_decode(file_get_contents('php://input',false,null,0,12001),true,16,JSON_THROW_ON_ERROR);if(!is_array($in))throw new PaymentError(400,'Неверный запрос.');
 $ip=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',$c['installation_secret']);
 if($action==='login'){
  payment_rate($db,'admin-login:'.$ip,8,900);payment_rate($db,'admin-login-global',60,900);
  $p=$in['password']??'';if(!$ac||!is_string($p)||strlen($p)>256||!password_verify($p,$ac['password_hash']))throw new PaymentError(401,'Не удалось войти. Проверьте пароль.');
  session_regenerate_id(true);$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'admin_version'=>$ac['version'],'login_at'=>time(),'seen_at'=>time()];admin_reply(['authenticated'=>true,'csrf'=>$_SESSION['csrf']]);
 }
 if(!$auth)throw new PaymentError(401,'Войдите в кабинет.');$_SESSION['seen_at']=time();
 if($action==='logout'){$_SESSION=[];session_destroy();setcookie('__Host-realty_admin','',['expires'=>time()-3600,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);admin_reply(['ok'=>true]);}
 if(str_starts_with($action,'probe-')){
  $owner=hash_hmac('sha256','admin-probe:'.$ac['version'],$c['installation_secret']);
  admin_reply(probe_action($action,$db,$c,$owner,$in,__DIR__,fn($m,$p,$b=null,$k=null)=>payment_api($c,$m,$p,$b,$k)));
 }
 if($action==='data'){
  $all=crm_rows($db);$live=array_values(array_filter($all,fn($r)=>!$r['test']&&!$r['probe']));$paid=array_filter($live,fn($r)=>$r['type']==='order'&&$r['status']==='succeeded');
  $stats=['leads'=>count(array_filter($live,fn($r)=>$r['type']==='lead'&&$r['product']==='Магия')),'paid'=>count($paid),'intent'=>count(array_filter($live,fn($r)=>$r['status']==='intent'||$r['status']==='creating')),'unpaid'=>count(array_filter($live,fn($r)=>$r['type']==='order'&&$r['status']!=='succeeded')),'revenue'=>array_sum(array_map(fn($r)=>(float)$r['amount'],$paid))];
  $query=trim((string)($in['query']??''));$filter=$in['filter']??'all';$test=($in['test']??false)===true;
  $filtered=array_values(array_filter($all,function($r)use($query,$filter,$test){if(($r['test']||$r['probe'])!==$test)return false;if($query!==''&&stripos(implode(' ',[$r['id'],$r['name'],$r['email'],$r['phone'],$r['telegram']]),$query)===false)return false;return match($filter){'leads'=>$r['type']==='lead'&&$r['product']==='Магия','paid'=>$r['status']==='succeeded','unpaid'=>$r['type']==='order'&&$r['status']!=='succeeded','intent'=>in_array($r['status'],['intent','creating'],true),default=>true};}));
  $page=max(1,(int)($in['page']??1));$pages=max(1,(int)ceil(count($filtered)/30));$page=min($page,$pages);
  admin_reply(['rows'=>array_slice($filtered,($page-1)*30,30),'total'=>count($filtered),'page'=>$page,'pages'=>$pages,'stats'=>$stats,'payments_enabled'=>($c['enabled']??false)&&payment_missing($c)===[],'leads_enabled'=>crm_docs_ready($c),'updated_at'=>time()]);
 }
 if($action==='save'){crm_update($db,$in);admin_reply(['ok'=>true]);}
 throw new PaymentError(404,'Not found');
}catch(PaymentError $e){admin_reply(['error'=>$e->getMessage()],$e->http);}catch(Throwable $e){error_log('realty-admin: '.get_class($e));admin_reply(['error'=>'Не удалось выполнить запрос. Повторите позже.'],503);}
