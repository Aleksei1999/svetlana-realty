<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require '/var/www/u3681014/data/realty-private/admin-core.php';
$dir='/var/www/u3681014/data/realty-private';$c=payment_config($dir);$db=payment_db($dir);crm_db($db);
if(!crm_docs_ready($c))throw new RuntimeException('Documents not configured');
$db->beginTransaction();
try{
 $in=['name'=>'Проверка интеграции','phone'=>'+70000000000','telegram'=>'qa_test','consent'=>true,'request_id'=>bin2hex(random_bytes(16)),'goal'=>'Техническая проверка, откат транзакции'];
 $id=crm_lead($db,$c,'isolated-qa-owner',$in,'magic');
 $match=array_values(array_filter(crm_rows($db),fn($r)=>$r['id']===$id));
 if(count($match)!==1||$match[0]['product']!=='Магия'||$match[0]['status']!=='new')throw new RuntimeException('Admin projection failed');
 $s=$db->prepare('SELECT consent_version FROM leads WHERE id=?');$s->execute([$id]);if($s->fetchColumn()!=='2026-10-14')throw new RuntimeException('Consent version missing');
 if(crm_lead($db,$c,'isolated-qa-owner',$in,'magic')!==$id)throw new RuntimeException('Duplicate submission failed');
 echo 'PASS: Magic lead stored, visible in admin projection, consent version recorded, retry deduplicated'.PHP_EOL;
}finally{$db->rollBack();}
$s=$db->prepare('SELECT COUNT(*) FROM leads WHERE id=?');$s->execute([$id]);if((int)$s->fetchColumn()!==0)throw new RuntimeException('Rollback failed');
echo 'PASS: test transaction rolled back; customer records unchanged'.PHP_EOL;
