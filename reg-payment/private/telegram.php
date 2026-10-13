<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

function telegram_config(string $dir): array {
    $path=$dir.'/telegram-config.json';
    if (!is_file($path)) return ['enabled'=>false];
    $c=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    $c['token']=trim((string)file_get_contents($dir.'/telegram-token.txt'));
    return $c;
}
function telegram_db(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS telegram_access (
        order_id TEXT PRIMARY KEY REFERENCES orders(id), claim_hash TEXT NOT NULL UNIQUE,
        candidate_id TEXT, candidate_name TEXT, candidate_username TEXT, user_id TEXT,
        invite_url TEXT UNIQUE, invite_expires INTEGER, sent_at INTEGER,
        state TEXT NOT NULL DEFAULT "unbound", revoke_pending INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS telegram_runtime (key TEXT PRIMARY KEY,value TEXT NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS telegram_updates (id INTEGER PRIMARY KEY, processed_at INTEGER NOT NULL)');
}
function telegram_api(array $c, string $method, array $params=[]): mixed {
    if (!in_array($method,['getUpdates','deleteWebhook','getMe','getChat','getChatMember','getWebhookInfo','setWebhook','sendMessage','createChatInviteLink','revokeChatInviteLink','approveChatJoinRequest','declineChatJoinRequest'],true)) throw new RuntimeException('Unsupported Telegram method');
    $h=curl_init('https://api.telegram.org/bot'.$c['token'].'/'.$method);
    curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params?:new stdClass,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_IPRESOLVE=>($c['ip_family']??6)===6?CURL_IPRESOLVE_V6:CURL_IPRESOLVE_WHATEVER]);
    $raw=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$errno=curl_errno($h);curl_close($h);
    $j=is_string($raw)?json_decode($raw,true):null;
    // Never log the URL, raw provider response, or token.
    if ($status!==200 || !is_array($j) || ($j['ok']??false)!==true) {
        error_log('realty-telegram: '.$method.' http='.$status.' curl='.$errno);
        throw new PaymentError(503,'Telegram временно недоступен. Повторите получение доступа позже.');
    }
    return $j['result'];
}
function telegram_auth(array $c, string $provided): void {
    $secret=$c['webhook_secret']??'';
    if (!is_string($secret) || strlen($secret)<32 || !hash_equals($secret,$provided)) throw new PaymentError(403,'Forbidden');
}
function telegram_lock(string $dir, callable $work): mixed {
    $h=fopen($dir.'/locks/telegram.lock','c');
    if (!$h || !flock($h,LOCK_EX|LOCK_NB)) throw new PaymentError(503,'Обрабатываем предыдущий запрос. Повторите через несколько секунд.');
    try { return $work(); } finally { flock($h,LOCK_UN);fclose($h); }
}
function telegram_access(PDO $db, string $id): ?array {
    $s=$db->prepare('SELECT * FROM telegram_access WHERE order_id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;
}
function telegram_eligible(array $r): bool {
    if ($r['status']!=='succeeded' || empty($r['payment_id']) || empty($r['paid_at']) || (bool)$r['test']) return false;
    $end=(new DateTimeImmutable('@'.(int)$r['paid_at']))->modify('+3 months')->getTimestamp();
    return $r['amount']==='4990.00' && $r['currency']==='RUB' && $end>time();
}
function telegram_assert_paid(PDO $db, string $id, callable $payments): array {
    $r=payment_find($db,$id);
    if (!$r || !telegram_eligible($r)) throw new PaymentError(402,'Подтверждённая оплата для доступа не найдена.');
    $p=$payments('GET','payments/'.$r['payment_id']);
    payment_validate($r,$p);
    if ($p['status']!=='succeeded' || ($p['paid']??false)!==true || ($p['test']??true)!==false || ($p['refunded_amount']['value']??'0.00')!=='0.00') {
        throw new PaymentError(402,'Оплата не подтверждена либо оформлен возврат. Доступ не выдан.');
    }
    payment_apply($db,$r,$p);
    return $r;
}
function telegram_browser(PDO $db, array $c, array $paymentConfig, array $row): array {
    if (($c['enabled']??false)!==true || !telegram_eligible($row)) return ['available'=>false];
    $token=rtrim(strtr(base64_encode(hash_hmac('sha256','telegram-claim:'.$row['id'],$paymentConfig['installation_secret'],true)),'+/','-_'),'=');
    $s=$db->prepare('INSERT OR IGNORE INTO telegram_access(order_id,claim_hash) VALUES(?,?)');$s->execute([$row['id'],hash('sha256',$token)]);
    $a=telegram_access($db,$row['id']);
    return ['available'=>true,'bot_url'=>'https://t.me/'.$c['bot_username'].'?start='.$token,'state'=>$a['state'],'bound_id'=>$a['user_id'],
        'delivery_pending'=>$a['user_id']!==null && $a['state']!=='joined' && !$a['sent_at'],
        'candidate'=>$a['candidate_id']&&!$a['user_id']?['id'=>$a['candidate_id'],'name'=>$a['candidate_name'],'username'=>$a['candidate_username']]:null];
}
function telegram_deliver(PDO $db, array $c, string $id, callable $payments, callable $tg): void {
    if (($c['enabled']??false)!==true) throw new PaymentError(503,'Выдача доступа ещё настраивается.');
    telegram_assert_paid($db,$id,$payments);
    $a=telegram_access($db,$id);
    if (!$a || !$a['user_id']) throw new PaymentError(403,'Подтвердите свой Telegram-аккаунт на странице заказа.');
    if ($a['state']==='joined') return;
    if (!$a['invite_url'] || (int)$a['invite_expires']<=time()) {
        $expires=time()+900;
        $invite=$tg('createChatInviteLink',['chat_id'=>$c['chat_id'],'name'=>'order-'.substr($id,0,16),'expire_date'=>$expires,'creates_join_request'=>true]);
        $url=$invite['invite_link']??'';
        if (!is_string($url) || !preg_match('~^https://t\.me/\+[A-Za-z0-9_-]+$~D',$url) || ($invite['creates_join_request']??false)!==true) throw new PaymentError(503,'Не удалось создать приглашение.');
        $s=$db->prepare('UPDATE telegram_access SET invite_url=?,invite_expires=?,sent_at=NULL,state="invited",revoke_pending=0 WHERE order_id=?');$s->execute([$url,$expires,$id]);
        $a=telegram_access($db,$id);
    }
    if (!$a['sent_at']) {
        $tg('sendMessage',['chat_id'=>$a['user_id'],'text'=>'Оплата подтверждена. Ваша ссылка в «Старт» действует 15 минут. Отправьте заявку на вступление: бот одобрит только ваш Telegram-аккаунт.',
            'reply_markup'=>['inline_keyboard'=>[[['text'=>'Вступить в «Старт»','url'=>$a['invite_url']]]]]]);
        $s=$db->prepare('UPDATE telegram_access SET sent_at=? WHERE order_id=?');$s->execute([time(),$id]);
        $s=$db->prepare('UPDATE orders SET fulfillment_status="invited" WHERE id=?');$s->execute([$id]);
    }
}
function telegram_confirm(PDO $db, array $c, string $id, string $candidate, callable $payments, callable $tg): void {
    telegram_assert_paid($db,$id,$payments);
    $a=telegram_access($db,$id);
    if (!$a || !preg_match('/^[1-9][0-9]{0,19}$/D',$candidate) || !hash_equals((string)($a['candidate_id']??''),$candidate)) throw new PaymentError(409,'Аккаунт изменился. Обновите данные заказа.');
    if ($a['user_id'] && !hash_equals($a['user_id'],$candidate)) throw new PaymentError(403,'К заказу уже привязан другой аккаунт.');
    $s=$db->prepare('UPDATE telegram_access SET user_id=?,state=CASE WHEN state="unbound" THEN "bound" ELSE state END WHERE order_id=? AND (user_id IS NULL OR user_id=?)');$s->execute([$candidate,$id,$candidate]);
    telegram_deliver($db,$c,$id,$payments,$tg);
}
function telegram_revoke(PDO $db, array $c, array $a, callable $tg): void {
    if (!(bool)$a['revoke_pending']) return;
    // If already expired, the link is unusable even if Telegram no longer accepts revocation.
    if ((int)$a['invite_expires']>time()) $tg('revokeChatInviteLink',['chat_id'=>$c['chat_id'],'invite_link'=>$a['invite_url']]);
    $s=$db->prepare('UPDATE telegram_access SET revoke_pending=0 WHERE order_id=?');$s->execute([$a['order_id']]);
}
function telegram_join(PDO $db, array $c, array $j, callable $payments, callable $tg): void {
    if ((string)($j['chat']['id']??'')!==(string)$c['chat_id']) return;
    $url=$j['invite_link']['invite_link']??'';
    if (!is_string($url) || $url==='') return;
    $s=$db->prepare('SELECT * FROM telegram_access WHERE invite_url=?');$s->execute([$url]);$a=$s->fetch(PDO::FETCH_ASSOC);
    if (!$a) return; // Manual invitations are outside this payment workflow.
    $uid=(string)($j['from']['id']??'');
    if ($uid!=='' && $uid===$a['user_id'] && $a['state']==='joined') { telegram_revoke($db,$c,$a,$tg);return; }
    if ($uid==='' || $uid!==$a['user_id'] || (int)$a['invite_expires']<=time()) {
        if ($uid!=='') $tg('declineChatJoinRequest',['chat_id'=>$c['chat_id'],'user_id'=>$uid]);
        return;
    }
    try { telegram_assert_paid($db,$a['order_id'],$payments); }
    catch(PaymentError $e) {
        if (!in_array($e->http,[402,409],true)) throw $e; // A timeout must not be interpreted as an unpaid order.
        $tg('declineChatJoinRequest',['chat_id'=>$c['chat_id'],'user_id'=>$uid]);return;
    }
    // Recover a timeout after Telegram accepted an earlier approval, without admitting a second person.
    $member=$tg('getChatMember',['chat_id'=>$c['chat_id'],'user_id'=>$uid]);
    if (!in_array($member['status']??'', ['member','administrator','creator'],true)) $tg('approveChatJoinRequest',['chat_id'=>$c['chat_id'],'user_id'=>$uid]);
    $s=$db->prepare('UPDATE telegram_access SET state="joined",revoke_pending=1 WHERE order_id=?');$s->execute([$a['order_id']]);
    $s=$db->prepare('UPDATE orders SET fulfillment_status="delivered" WHERE id=?');$s->execute([$a['order_id']]);
    telegram_revoke($db,$c,telegram_access($db,$a['order_id']),$tg);
}
function telegram_start(PDO $db, array $c, array $m, callable $payments, callable $tg): void {
    if (($m['chat']['type']??'')!=='private' || (string)($m['chat']['id']??'')!==(string)($m['from']['id']??'') || ($m['from']['is_bot']??true)) return;
    $uid=(string)$m['from']['id'];$text=trim($m['text']??'');
    if (!preg_match('~^/(?:start|access)(?:@startreality_bot)?(?:\s+([A-Za-z0-9_-]{43}))?$~iD',$text,$match)) return;
    try { payment_rate($db,'telegram-user:'.$uid,15,60); } catch(PaymentError $e) { return; }
    $token=$match[1]??'';
    if ($token==='') {
        $s=$db->prepare('SELECT order_id FROM telegram_access WHERE user_id=? ORDER BY rowid DESC LIMIT 1');$s->execute([$uid]);$id=$s->fetchColumn();
        if (!$id) { $tg('sendMessage',['chat_id'=>$uid,'text'=>'Доступ выдаётся после оплаты на сайте. После подтверждения оплаты нажмите «Получить доступ в Telegram» на странице заказа.']);return; }
    } else {
        $s=$db->prepare('SELECT * FROM telegram_access WHERE claim_hash=?');$s->execute([hash('sha256',$token)]);$a=$s->fetch(PDO::FETCH_ASSOC);
        if (!$a) { $tg('sendMessage',['chat_id'=>$uid,'text'=>'Ссылка на заказ не найдена. Откройте бота кнопкой со страницы подтверждённой оплаты.']);return; }
        $id=$a['order_id'];
    }
    try {
        telegram_assert_paid($db,$id,$payments);
        $a=telegram_access($db,$id);
        if ($a['user_id'] && $a['user_id']!==$uid) throw new PaymentError(403,'Этот заказ уже привязан к другому Telegram-аккаунту.');
        if ($a['user_id']) {
            if ($a['state']==='joined') { $tg('sendMessage',['chat_id'=>$uid,'text'=>'Доступ по этому заказу уже выдан.']);return; }
            $s=$db->prepare('UPDATE telegram_access SET sent_at=NULL WHERE order_id=?');$s->execute([$id]);
            telegram_deliver($db,$c,$id,$payments,$tg);return;
        }
        // Claiming the bot deep link alone cannot bind or deliver. Buyer must confirm in their secure website session.
        if ($a['candidate_id'] && $a['candidate_id']!==$uid) throw new PaymentError(409,'У заказа уже выбран Telegram-аккаунт. Сбросьте выбор на странице оплаты.');
        $s=$db->prepare('UPDATE telegram_access SET candidate_id=?,candidate_name=?,candidate_username=? WHERE order_id=? AND user_id IS NULL');
        $s->execute([$uid,substr((string)($m['from']['first_name']??''),0,160),substr((string)($m['from']['username']??''),0,32),$id]);
        $tg('sendMessage',['chat_id'=>$uid,'text'=>'Вернитесь на страницу оплаты в том же браузере и нажмите «Проверить Telegram», затем «Это мой аккаунт — получить доступ». До подтверждения приглашение не выдаётся.']);
    } catch(PaymentError $e) {
        if ($e->http>=500) throw $e;
        $tg('sendMessage',['chat_id'=>$uid,'text'=>$e->getMessage()]);
    }
}
function telegram_update(PDO $db, array $c, array $update, callable $payments, callable $tg): void {
    if (($c['enabled']??false)!==true) throw new PaymentError(503,'Bot disabled');
    $id=$update['update_id']??null;
    if (!is_int($id) || $id<0) throw new PaymentError(400,'Invalid update');
    $s=$db->prepare('SELECT 1 FROM telegram_updates WHERE id=?');$s->execute([$id]);if($s->fetchColumn())return;
    if (isset($update['chat_join_request'])) telegram_join($db,$c,$update['chat_join_request'],$payments,$tg);
    elseif (isset($update['message'])) telegram_start($db,$c,$update['message'],$payments,$tg);
    $s=$db->prepare('INSERT OR IGNORE INTO telegram_updates VALUES(?,?)');$s->execute([$id,time()]);
    $db->exec('DELETE FROM telegram_updates WHERE processed_at < '.(time()-7*86400));
}

function telegram_cursor(PDO $db): int {
    $s=$db->query('SELECT value FROM telegram_runtime WHERE key="offset"');return (int)($s->fetchColumn()?:0);
}
function telegram_runtime_set(PDO $db,string $key,string $value): void {
    $s=$db->prepare('INSERT OR REPLACE INTO telegram_runtime(key,value) VALUES(?,?)');$s->execute([$key,$value]);
}
function telegram_batch(PDO $db,array $updates,callable $process): void {
    foreach($updates as$update){
        $id=$update['update_id']??null;
        if(!is_int($id)||$id<0)throw new RuntimeException('Invalid Telegram batch');
        if($id<telegram_cursor($db))continue;
        $process($update); // A failed update is never acknowledged; next poll retries it.
        telegram_runtime_set($db,'offset',(string)($id+1));
    }
}
