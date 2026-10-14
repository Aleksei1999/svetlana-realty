<?php
declare(strict_types=1);
require_once __DIR__.'/telegram.php';
function crm_db(PDO $db): void {
 $db->exec('CREATE TABLE IF NOT EXISTS leads (id TEXT PRIMARY KEY, request_key TEXT NOT NULL UNIQUE, kind TEXT NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL DEFAULT "", phone TEXT NOT NULL, telegram TEXT NOT NULL, details TEXT NOT NULL, status TEXT NOT NULL DEFAULT "new", note TEXT NOT NULL DEFAULT "", consent_version TEXT NOT NULL, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)');
 $db->exec('CREATE TABLE IF NOT EXISTS crm_notes (order_id TEXT PRIMARY KEY REFERENCES orders(id),note TEXT NOT NULL,updated_at INTEGER NOT NULL)');
}
function crm_docs_ready(array $c): bool {
 return ($c['documents_approved']??false)===true && !empty($c['consent_version']) && !empty($c['privacy_url']) && !empty($c['consent_url']);
}
function crm_lead(PDO $db,array $c,string $owner,array $input,string $kind): string {
 if(!crm_docs_ready($c))throw new PaymentError(503,'Приём заявок ещё готовится. Данные не сохранены.');
 if(!in_array($kind,['magic','intent'],true))throw new PaymentError(422,'Неверный формат.');
 $customer=payment_customer($kind==='magic'?array_merge($input,['email'=>'not-provided@example.invalid']):$input);
 $key=$input['request_id']??'';
 if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))throw new PaymentError(422,'Обновите форму.');
 $details=[];foreach(['experience','goal','capital','question']as$f){$v=$input[$f]??'';if(!is_string($v)||strlen($v)>4000)throw new PaymentError(422,'Слишком длинное поле.');$details[$f]=trim($v);}
 if($kind==='magic'&&$details['goal']==='')throw new PaymentError(422,'Укажите цель участия.');
 $rk=hash('sha256',$owner.':'.$key);$s=$db->prepare('SELECT id FROM leads WHERE request_key=?');$s->execute([$rk]);$existing=$s->fetchColumn();if($existing)return $existing;
 $id=bin2hex(random_bytes(16));$s=$db->prepare('INSERT INTO leads(id,request_key,kind,name,email,phone,telegram,details,consent_version,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
 $s->execute([$id,$rk,$kind,$customer['name'],$kind==='magic'?'':$customer['email'],$customer['phone'],$customer['telegram'],json_encode($details,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$c['consent_version'],time(),time()]);return $id;
}
function admin_config(string $dir): ?array {
 if(!is_file($dir.'/admin-auth.json'))return null;$c=json_decode(file_get_contents($dir.'/admin-auth.json'),true);return is_array($c)&&!empty($c['password_hash'])?$c:null;
}
function admin_authenticated(?array $c,array $session): bool {
 return $c!==null && isset($session['admin_version'],$session['login_at'],$session['seen_at']) && hash_equals($c['version'],(string)$session['admin_version']) && time()-(int)$session['login_at']<28800 && time()-(int)$session['seen_at']<1800;
}
function crm_rows(PDO $db): array {
 $rows=[];
 // Explicit projection: no owner hashes, payment payloads, tokens or invitation links leave the server.
 foreach($db->query('SELECT l.id,l.kind,l.name,l.email,l.phone,l.telegram,l.details,l.status,l.note,l.created_at,l.updated_at FROM leads l WHERE l.kind="magic" OR NOT EXISTS(SELECT 1 FROM orders o WHERE o.request_key=l.request_key) ORDER BY l.created_at DESC')as$r){
  $rows[]=['id'=>$r['id'],'type'=>'lead','product'=>$r['kind']==='magic'?'Магия':'Старт','name'=>$r['name'],'email'=>$r['email'],'phone'=>$r['phone'],'telegram'=>$r['telegram'],'details'=>json_decode($r['details'],true),'status'=>$r['kind']==='intent'?'intent':$r['status'],'work_status'=>$r['status'],'note'=>$r['note'],'created_at'=>(int)$r['created_at'],'amount'=>null,'test'=>false,'probe'=>false,'access'=>null];
 }
 foreach($db->query('SELECT o.id,o.product,o.name,o.email,o.phone,o.telegram,o.status,o.amount,o.test,o.created_at,o.paid_at,o.checked_at,o.receipt_status,o.payment_id,o.fulfillment_status,t.state,t.user_id,t.candidate_username,n.note FROM orders o LEFT JOIN telegram_access t ON t.order_id=o.id LEFT JOIN crm_notes n ON n.order_id=o.id ORDER BY o.created_at DESC')as$r){
  $rows[]=['id'=>$r['id'],'type'=>'order','product'=>$r['product']==='probe10'?'Проверка · 10 ₽':'Старт','probe'=>$r['product']==='probe10','name'=>$r['name'],'email'=>$r['email'],'phone'=>$r['phone'],'telegram'=>$r['telegram'],'details'=>[],'status'=>$r['status'],'note'=>$r['note']??'','created_at'=>(int)$r['created_at'],'amount'=>$r['amount'],'test'=>(bool)$r['test'],'paid_at'=>$r['paid_at'],'checked_at'=>$r['checked_at'],'receipt'=>$r['receipt_status'],'payment_id'=>$r['payment_id'],'access'=>$r['state']??$r['fulfillment_status'],'telegram_id'=>$r['user_id']];
 }
 usort($rows,fn($a,$b)=>$b['created_at']<=>$a['created_at']);return $rows;
}
function crm_update(PDO $db,array $input): void {
 $id=$input['id']??'';$type=$input['type']??'';$note=$input['note']??'';
 if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_string($note)||strlen($note)>6000)throw new PaymentError(422,'Проверьте заметку.');
 if($type==='lead'){
  $status=$input['status']??'';if(!in_array($status,['new','contacted','waiting','closed'],true))throw new PaymentError(422,'Неверный статус.');
  $s=$db->prepare('UPDATE leads SET note=?,status=?,updated_at=? WHERE id=?');$s->execute([$note,$status,time(),$id]);if(!$s->rowCount())throw new PaymentError(404,'Заявка не найдена.');
 }elseif($type==='order'){
  if(!payment_find($db,$id))throw new PaymentError(404,'Заказ не найден.');
  $s=$db->prepare('INSERT OR REPLACE INTO crm_notes(order_id,note,updated_at) VALUES(?,?,?)');$s->execute([$id,$note,time()]);
 }else throw new PaymentError(422,'Неверный тип записи.');
}
