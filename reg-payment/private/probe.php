<?php
declare(strict_types=1);
// Invoked only after the admin session, Origin and CSRF checks in admin-app.php.
function probe_missing(array $c): array {
    // This check cannot release the course: fulfillment and the launch verification
    // remain separate gates on the normal checkout. All fiscal/document gates apply.
    $missing = array_values(array_diff(payment_missing($c), ['fulfillment_ready', 'test_payment_verified']));
    if (($c['mode'] ?? '') !== 'live') $missing[] = 'live_shop_required';
    if (($c['probe_enabled'] ?? false) !== true) $missing[] = 'probe_enabled';
    if (!in_array($c['receipt']['vat_code'] ?? null, [1,2,3,4,5,6,7,8,9,10,11,12], true)) $missing[] = 'receipt.vat_code';
    if (!in_array($c['receipt']['payment_mode'] ?? '', ['full_prepayment','partial_prepayment','advance','full_payment','partial_payment','credit','credit_payment'], true)) $missing[] = 'receipt.payment_mode';
    if (!preg_match('/^[a-z_]{3,40}$/D', $c['receipt']['payment_subject'] ?? '')) $missing[] = 'receipt.payment_subject';
    return array_values(array_unique($missing));
}
function probe_action(string $action, PDO $db, array $c, string $owner, array $input, string $dir, callable $api): array {
    if ($action === 'probe-bootstrap') {
        return ['available' => probe_missing($c) === [], 'amount' => '10.00', 'currency' => 'RUB',
            'message' => probe_missing($c) === [] ? 'Реальный платёж 10 ₽. Доступ к курсу не предоставляется.' : 'Оплата пока закрыта. Ожидаем подтверждения настроек чека и включения проверки.',
            'privacy_url' => $c['privacy_url'] ?? '', 'consent_url' => $c['consent_url'] ?? ''];
    }
    if ($action === 'probe-create') {
        if (probe_missing($c) !== []) throw new PaymentError(503, 'Оплата пока закрыта: настройки чека ещё не подтверждены или проверка не включена.');
        if (($input['real_payment_ack'] ?? false) !== true) throw new PaymentError(422, 'Подтвердите реальное списание 10 ₽ без доступа к курсу.');
        payment_rate($db, 'probe-create:'.$owner, 10, 3600);
        // Serialize retries before creating the order/provider payment.
        $lock = fopen($dir.'/locks/probe.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX|LOCK_NB)) throw new PaymentError(503, 'Предыдущий запрос ещё выполняется. Повторите через несколько секунд.');
        try { $row = payment_order($db,$c,$owner,$input,$api,'probe10'); }
        finally { flock($lock,LOCK_UN); fclose($lock); }
        return payment_public($row);
    }
    if ($action === 'probe-status') {
        $id = $input['order_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new PaymentError(404,'Заказ не найден.');
        $row = payment_find($db,$id);
        if (!$row || !hash_equals($owner,$row['owner']) || $row['product'] !== 'probe10') throw new PaymentError(404,'Заказ не найден.');
        payment_rate($db,'probe-status:'.$owner,120,60);
        if ($row['payment_id'] && $row['status'] !== 'canceled' && time()-(int)$row['checked_at'] >= 5) {
            payment_apply($db,$row,$api('GET','payments/'.$row['payment_id']));
            $row = payment_find($db,$id);
        }
        return payment_public($row);
    }
    throw new PaymentError(404,'Не найдено.');
}
