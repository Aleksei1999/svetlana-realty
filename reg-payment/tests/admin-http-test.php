<?php
declare(strict_types=1);
require __DIR__.'/../private/admin-core.php';
$root=sys_get_temp_dir().'/admin-http-'.bin2hex(random_bytes(8));mkdir($root,0700);mkdir($root.'/sessions',0700);mkdir($root.'/locks',0700);
foreach(['admin-app.php','admin-core.php','core.php','telegram.php']as$f)copy(__DIR__.'/../private/'.$f,$root.'/'.$f);
file_put_contents($root.'/index.php','<?php require __DIR__."/admin-app.php";');
file_put_contents($root.'/config.json',json_encode(['enabled'=>false,'mode'=>'live','installation_secret'=>bin2hex(random_bytes(32))]));
$pw=bin2hex(random_bytes(18));file_put_contents($root.'/admin-auth.json',json_encode(['password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'version'=>'qa-only']));
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$addr=stream_socket_get_name($socket,false);fclose($socket);
$process=proc_open([PHP_BINARY,'-S',$addr,'-t',$root],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);$cookie='';$csrf='';$n=0;
function ok(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);echo 'PASS '.$m.PHP_EOL;$n++;}
function req(string $action,?array $body=null,string $origin='https://www.xn--80ajklwp.site',?string $token=null):array{global $addr,$cookie,$csrf;$h=curl_init('http://'.$addr.'/index.php?action='.$action);$heads=['Origin: '.$origin,'Content-Type: application/json','X-CSRF-Token: '.($token??$csrf)];if($cookie)$heads[]='Cookie: '.$cookie;curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_HTTPHEADER=>$heads,CURLOPT_TIMEOUT=>5]);if($body!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,json_encode($body));}$r=curl_exec($h);$code=curl_getinfo($h,CURLINFO_HTTP_CODE);$size=curl_getinfo($h,CURLINFO_HEADER_SIZE);curl_close($h);$head=substr($r?:'',0,$size);if(preg_match('/Set-Cookie: (__Host-realty_admin=[^;]*)/i',$head,$m))$cookie=$m[1];return[$code,json_decode(substr($r?:'',$size),true),$head];}
try{
 for($i=0;$i<30;$i++){usleep(50000);[$code,$s,$headers]=req('session');if($code===200)break;}
 ok($code===200&&!$s['authenticated']&&$s['configured'],'login session bootstraps');$csrf=$s['csrf'];$old=$cookie;
 ok(str_contains($headers,'secure')&&str_contains(strtolower($headers),'httponly')&&str_contains($headers,'SameSite=Strict'),'secure admin cookie flags');
 [$code]=req('data',[]);ok($code===401,'anonymous cannot read data');
 [$code]=req('login',['password'=>$pw],'https://example.com');ok($code===403,'cross origin login denied');
 [$code]=req('login',['password'=>$pw],'https://www.xn--80ajklwp.site','wrong');ok($code===403,'wrong csrf denied');
 [$code]=req('login',['password'=>'wrong']);ok($code===401,'wrong password denied');
 [$code,$s]=req('login',['password'=>$pw]);ok($code===200&&$s['authenticated'],'password login works');ok($cookie!==$old,'session id rotates at login');$csrf=$s['csrf'];
 [$code,$data,$headers]=req('data',[]);ok($code===200&&$data['rows']===[]&&$data['stats']['paid']===0,'authenticated dashboard reads empty database');ok(str_contains($headers,'no-store'),'responses not cached');
 [$code]=req('save',['id'=>str_repeat('a',32),'type'=>'order','note'=>'qa']);ok($code===404,'unknown order edit denied');
 [$code]=req('logout',[]);ok($code===200,'logout works');[$code]=req('data',[]);ok(in_array($code,[401,403]),'logout revokes access');
 [$code,$s]=req('session');$csrf=$s['csrf'];for($i=0;$i<8;$i++)[$code]=req('login',['password'=>'wrong']);ok($code===429,'password brute force throttled');
 echo 'OK: '.$n.' admin HTTP assertions; isolated local server, no live credentials'.PHP_EOL;
}finally{proc_terminate($process);proc_close($process);foreach(glob($root.'/sessions/*')as$f)unlink($f);rmdir($root.'/sessions');rmdir($root.'/locks');foreach(glob($root.'/*')as$f)unlink($f);rmdir($root);}
