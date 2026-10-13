<?php
declare(strict_types=1);
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/telegram.php';
ini_set('display_errors', '0');
umask(0077);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function respond(array $body, int $status=200): never { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit; }
function request_json(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) throw new PaymentError(413, 'Запрос слишком большой.');
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) throw new PaymentError(415, 'Требуется JSON.');
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw) > 8192) throw new PaymentError(413, 'Запрос слишком большой.');
    try { $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException $e) { throw new PaymentError(400,'Некорректный запрос.'); }
    if (!is_array($body)) throw new PaymentError(400, 'Некорректный запрос.');
    return $body;
}
try {
    $c = payment_config(__DIR__);
    $db = payment_db(__DIR__);
    $api = fn($method,$path,$body=null,$key=null) => payment_api($c,$method,$path,$body,$key);
    $tc=telegram_config(__DIR__);
    telegram_db($db);
    $tg=fn($method,$params=[])=>telegram_api($tc,$method,$params);
    $public=function($row)use($db,$tc,$c){$result=payment_public($row);$result['telegram']=telegram_browser($db,$tc,$c,$row);return $result;};
    $action = $_GET['action'] ?? '';
    if ($action==='telegram') {
        if ($_SERVER['REQUEST_METHOD']!=='POST') throw new PaymentError(405,'POST required');
        telegram_auth($tc,$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
        $update=request_json();
        telegram_lock(__DIR__,fn()=>telegram_update($db,$tc,$update,$api,$tg));
        respond(['received'=>true]);
    }
    $ip = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', (string)$c['installation_secret']);
    if ($action === 'webhook') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PaymentError(405,'POST required');
        payment_rate($db, 'webhook:' . $ip, 180, 60);
        $event = request_json();
        if (!in_array($event['event'] ?? '', ['payment.succeeded','payment.canceled','payment.waiting_for_capture'],true)) respond(['received'=>true]);
        $pid = $event['object']['id'] ?? '';
        if (!is_string($pid) || !preg_match('/^[a-zA-Z0-9-]{1,64}$/D',$pid)) throw new PaymentError(400,'Invalid payment id');
        $s=$db->prepare('SELECT * FROM orders WHERE payment_id=?'); $s->execute([$pid]); $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) $row=payment_find($db, (string)($event['object']['metadata']['order_id'] ?? ''));
        if (!$row) respond(['received'=>true]);
        // Never trust the notification payload. Authenticate a fresh GET to YooKassa.
        $verified=$api('GET','payments/'.$pid);
        payment_apply($db,$row,$verified);
        respond(['received'=>true]);
    }
    session_name('__Host-realty_session');
    session_save_path(__DIR__ . '/sessions');
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    $_SESSION['buyer'] ??= bin2hex(random_bytes(32));
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $owner=hash('sha256',$_SESSION['buyer']);
    $csrf=$_SESSION['csrf'];
    session_write_close();
    if ($action === 'bootstrap' && $_SERVER['REQUEST_METHOD']==='GET') {
        payment_rate($db, 'bootstrap:'.$ip, 60, 60);
        respond(['available'=>($c['enabled']??false)===true && payment_missing($c)===[], 'csrf'=>$csrf,
            'test'=>($c['mode']??'')==='test', 'price'=>'4990.00', 'product'=>'Тариф «Старт»',
            'offer_url'=>$c['offer_url']??'', 'privacy_url'=>$c['privacy_url']??'', 'consent_url'=>$c['consent_url']??'']);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new PaymentError(405,'POST required');
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if (!in_array($origin,['https://www.xn--80ajklwp.site','https://xn--80ajklwp.site'],true)) throw new PaymentError(403,'Недопустимый источник запроса.');
    if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN']??'')) throw new PaymentError(403,'Обновите страницу оплаты.');
    $input=request_json();
    if ($action==='create') {
        if (($c['enabled']??false)!==true || payment_missing($c)!==[]) throw new PaymentError(503,'Приём оплаты ещё настраивается. Деньги не списаны.');
        payment_rate($db,'create:'.$ip,12,3600);
        // Serialize this buyer's creates; no second request can race the same idempotence key.
        $lock=fopen(__DIR__.'/locks/'.$owner.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new PaymentError(409,'Заказ уже создаётся. Повторите проверку через несколько секунд.');
        try { $row=payment_order($db,$c,$owner,$input,$api); } finally { flock($lock,LOCK_UN); fclose($lock); }
        respond($public($row));
    }
    if (in_array($action,['telegram-confirm','telegram-reset'],true)) {
        payment_rate($db,'telegram-browser:'.$ip,20,60);
        $id=$input['order_id']??'';
        $row=is_string($id)?payment_find($db,$id):null;
        if (!$row || !hash_equals($row['owner'],$owner)) throw new PaymentError(404,'Заказ не найден в этом браузере.');
        if (($tc['enabled']??false)!==true) throw new PaymentError(503,'Выдача доступа ещё настраивается.');
        telegram_lock(__DIR__,function()use($action,$db,$tc,$id,$input,$api,$tg){
            if ($action==='telegram-confirm') {
                $candidate=$input['candidate_id']??'';
                if (!is_string($candidate)) throw new PaymentError(422,'Выберите аккаунт.');
                telegram_confirm($db,$tc,$id,$candidate,$api,$tg);
            } else {
                $s=$db->prepare('UPDATE telegram_access SET candidate_id=NULL,candidate_name=NULL,candidate_username=NULL WHERE order_id=? AND user_id IS NULL');$s->execute([$id]);
            }
        });
        respond($public(payment_find($db,$id)));
    }
    if ($action==='status') {
        payment_rate($db,'status:'.$ip,60,60);
        $id=$input['order_id']??'';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new PaymentError(404,'Заказ не найден.');
        $row=payment_find($db,$id);
        if (!$row || !hash_equals($row['owner'],$owner)) throw new PaymentError(404,'Заказ не найден в этом браузере.');
        if ($row['payment_id'] && !in_array($row['status'],['succeeded','canceled'],true) && time()-(int)$row['checked_at']>=5) {
            $verified=$api('GET','payments/'.$row['payment_id']); payment_apply($db,$row,$verified); $row=payment_find($db,$id);
        }
        respond($public($row));
    }
    throw new PaymentError(404,'Not found');
} catch (PaymentError $e) { respond(['error'=>$e->getMessage()],$e->http); }
catch (Throwable $e) { error_log('realty-payment: '.get_class($e)); respond(['error'=>'Сервис временно недоступен. Деньги повторно не списывайте.'],503); }
