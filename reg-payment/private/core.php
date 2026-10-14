<?php
declare(strict_types=1);

final class PaymentError extends RuntimeException {
    public function __construct(public readonly int $http, string $message) { parent::__construct($message); }
}

function payment_config(string $dir): array {
    $c = json_decode((string)file_get_contents($dir . '/config.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($c)) throw new RuntimeException('Invalid configuration');
    if (is_file($dir.'/yookassa-key.txt')) {
        $key=trim((string)file_get_contents($dir.'/yookassa-key.txt'));
        if ($key!=='') $c['secret_key']=$key;
    }
    return $c;
}
function payment_missing(array $c): array {
    $missing = [];
    foreach (['shop_id', 'secret_key'] as $key) if (empty($c[$key])) $missing[] = $key;
    if (!in_array($c['mode'] ?? '', ['test', 'live'], true)) $missing[] = 'mode';
    foreach (['vat_code', 'payment_mode', 'payment_subject'] as $key) {
        if (!isset($c['receipt'][$key]) || $c['receipt'][$key] === '') $missing[] = 'receipt.' . $key;
    }
    // These are explicit release gates, not inferred from merely having an API key.
    if (($c['mode'] ?? '') === 'live') {
        foreach (['documents_approved', 'fulfillment_ready'] as $key) {
            if (($c[$key] ?? false) !== true) $missing[] = $key;
        }
        // An explicit owner launch decision does not claim a payment test occurred.
        if (($c['test_payment_verified'] ?? false) !== true && ($c['launch_without_payment_test_approved'] ?? false) !== true) $missing[] = 'test_payment_verified';
        if (empty($c['consent_version'])) $missing[] = 'consent_version';
        foreach (['offer_url', 'privacy_url', 'consent_url'] as $key) {
            if (!preg_match('~^https://(?:www\.)?xn--80ajklwp\.site/[^\s]+$~', $c[$key] ?? '')) $missing[] = $key;
        }
    }
    return $missing;
}
function payment_db(string $dir): PDO {
    $db = new PDO('sqlite:' . $dir . '/payments.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $db->exec('CREATE TABLE IF NOT EXISTS orders (
        id TEXT PRIMARY KEY, owner TEXT NOT NULL, request_key TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT NOT NULL, telegram TEXT NOT NULL,
        amount TEXT NOT NULL, currency TEXT NOT NULL, shop_id TEXT NOT NULL, test INTEGER NOT NULL,
        status TEXT NOT NULL, payment_id TEXT UNIQUE, confirmation_token TEXT,
        payload TEXT NOT NULL, created_at INTEGER NOT NULL, checked_at INTEGER NOT NULL DEFAULT 0,
        paid_at INTEGER, receipt_status TEXT, consent_version TEXT NOT NULL,
        fulfillment_status TEXT NOT NULL DEFAULT "pending"
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS rate_limits (bucket TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL)');
    // Preserve all existing course orders when introducing the private 10 RUB check.
    $columns = $db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('product', $columns, true)) {
        $db->beginTransaction();
        try {
            $columns = $db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('product', $columns, true)) $db->exec("ALTER TABLE orders ADD COLUMN product TEXT NOT NULL DEFAULT 'start'");
            $db->commit();
        } catch (Throwable $e) { $db->rollBack(); throw $e; }
    }
    chmod($dir . '/payments.sqlite', 0600);
    return $db;
}
function payment_rate(PDO $db, string $bucket, int $limit, int $seconds): void {
    $window = intdiv(time(), $seconds);
    $key = hash('sha256', $bucket . ':' . $window);
    $db->beginTransaction();
    try {
        $s = $db->prepare('INSERT OR IGNORE INTO rate_limits VALUES (?,0,?)');
        $s->execute([$key, ($window + 1) * $seconds]);
        $s = $db->prepare('UPDATE rate_limits SET count=count+1 WHERE bucket=?'); $s->execute([$key]);
        $s = $db->prepare('SELECT count FROM rate_limits WHERE bucket=?'); $s->execute([$key]);
        $count = (int)$s->fetchColumn(); $db->commit();
    } catch (Throwable $e) { $db->rollBack(); throw $e; }
    if ($count > $limit) throw new PaymentError(429, 'Слишком много запросов. Попробуйте немного позже.');
    $db->exec('DELETE FROM rate_limits WHERE expires < ' . (time() - 3600));
}
function payment_customer(array $input): array {
    $out = [];
    foreach (['name' => 150, 'email' => 254, 'phone' => 25, 'telegram' => 33] as $key => $max) {
        $v = $input[$key] ?? null;
        if (!is_string($v) || strlen(trim($v)) < 1 || strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v)) {
            throw new PaymentError(422, 'Проверьте поле «' . $key . '».');
        }
        $out[$key] = trim($v);
    }
    if (!filter_var($out['email'], FILTER_VALIDATE_EMAIL)) throw new PaymentError(422, 'Укажите корректный email для чека.');
    if (!preg_match('/^[+0-9() \-]{7,25}$/D', $out['phone'])) throw new PaymentError(422, 'Проверьте номер телефона.');
    if (!preg_match('/^@?[A-Za-z0-9_]{5,32}$/D', $out['telegram'])) throw new PaymentError(422, 'Проверьте имя пользователя Telegram.');
    if (($input['consent'] ?? false) !== true) throw new PaymentError(422, 'Для оформления нужно согласие с документами.');
    return $out;
}
function payment_product(string $product): array {
    return match ($product) {
        'start' => ['amount' => '4990.00', 'description' => 'Тариф «Старт»: 7 видеоуроков', 'label' => 'Старт'],
        'probe10' => ['amount' => '10.00', 'description' => 'Проверочный платёж', 'label' => 'Проверка оплаты'],
        default => throw new PaymentError(422, 'Неверный тариф.'),
    };
}
function payment_payload(array $c, string $id, string $email, string $product = 'start'): array {
    $p = payment_product($product);
    $item = ['description' => $p['description'], 'quantity' => '1.00',
        'amount' => ['value' => $p['amount'], 'currency' => 'RUB'],
        'vat_code' => (int)$c['receipt']['vat_code'], 'payment_mode' => $c['receipt']['payment_mode'],
        'payment_subject' => $c['receipt']['payment_subject']];
    $receipt = ['customer' => ['email' => $email], 'items' => [$item]];
    if (isset($c['receipt']['tax_system_code'])) $receipt['tax_system_code'] = (int)$c['receipt']['tax_system_code'];
    return ['amount' => ['value' => $p['amount'], 'currency' => 'RUB'], 'capture' => true,
        'confirmation' => ['type' => 'embedded'], 'description' => $p['label'] . ' — заказ ' . $id,
        'metadata' => ['order_id' => $id, 'product' => $product], 'receipt' => $receipt];
}
function payment_api(array $c, string $method, string $path, ?array $body = null, ?string $idempotency = null): array {
    if (!preg_match('~^payments(?:/[a-zA-Z0-9-]{1,64})?$~D', $path)) throw new RuntimeException('Invalid API path');
    $ch = curl_init('https://api.yookassa.ru/v3/' . $path);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($idempotency !== null) $headers[] = 'Idempotence-Key: ' . $idempotency;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $c['shop_id'] . ':' . $c['secret_key'], CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $status >= 500 || $status === 429) throw new PaymentError(503, 'ЮKassa временно не отвечает. Повторите проверку: новый платёж создан не будет.');
    if ($status < 200 || $status >= 300) throw new PaymentError(502, 'Не удалось открыть оплату. Обратитесь к организатору.');
    $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($result)) throw new RuntimeException('Invalid API response');
    return $result;
}
function payment_validate(array $order, array $payment): void {
    $checks = [
        is_string($payment['id'] ?? null) && preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $payment['id']),
        empty($order['payment_id']) || ($payment['id'] ?? '') === $order['payment_id'],
        ($payment['metadata']['order_id'] ?? '') === $order['id'],
        ($order['product'] ?? 'start') === 'start'
            ? in_array($payment['metadata']['product'] ?? 'start', ['start'], true)
            : ($payment['metadata']['product'] ?? '') === $order['product'],
        ($payment['amount']['value'] ?? '') === $order['amount'],
        ($payment['amount']['currency'] ?? '') === $order['currency'],
        (string)($payment['recipient']['account_id'] ?? '') === $order['shop_id'],
        is_bool($payment['test'] ?? null) && $payment['test'] === (bool)$order['test'],
        in_array($payment['status'] ?? '', ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true),
    ];
    if (in_array(false, array_map('boolval', $checks), true)) throw new PaymentError(409, 'Не удалось подтвердить соответствие платежа заказу.');
    if ($payment['status'] === 'succeeded' && ($payment['paid'] ?? false) !== true) throw new PaymentError(409, 'Оплата ещё не подтверждена.');
}
function payment_apply(PDO $db, array $order, array $payment): void {
    payment_validate($order, $payment);
    // A stale or repeated notification can never downgrade a terminal status.
    $s = $db->prepare('UPDATE orders SET payment_id=?, status=?, confirmation_token=?, checked_at=?, paid_at=COALESCE(paid_at,?), receipt_status=? WHERE id=? AND status NOT IN ("succeeded","canceled")');
    $s->execute([$payment['id'], $payment['status'], $payment['confirmation']['confirmation_token'] ?? $order['confirmation_token'] ?? null,
        time(), $payment['status'] === 'succeeded' ? time() : null, $payment['receipt_registration'] ?? null, $order['id']]);
    // Receipt registration can finish after the payment. Never downgrade a registered receipt.
    if ($payment['status'] === 'succeeded' && isset($payment['receipt_registration'])) {
        $s = $db->prepare('UPDATE orders SET receipt_status=CASE WHEN receipt_status="succeeded" THEN receipt_status ELSE ? END,checked_at=? WHERE id=? AND status="succeeded"');
        $s->execute([$payment['receipt_registration'],time(),$order['id']]);
    }
}
function payment_find(PDO $db, string $id): ?array {
    $s = $db->prepare('SELECT * FROM orders WHERE id=?'); $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
function payment_public(array $row): array {
    return ['order_id' => $row['id'], 'status' => $row['status'], 'test' => (bool)$row['test'],
        'amount' => $row['amount'], 'currency' => $row['currency'], 'receipt_status' => $row['receipt_status'],
        'confirmation_token' => $row['status'] === 'pending' ? $row['confirmation_token'] : null,
        'fulfillment_status' => $row['fulfillment_status']];
}
function payment_order(PDO $db, array $c, string $owner, array $input, callable $api, string $product = 'start'): array {
    $p = payment_product($product); // Product is supplied by the server route, never by the customer's form.
    $customer = payment_customer($input);
    $key = $input['request_id'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key)) throw new PaymentError(422, 'Обновите страницу оплаты.');
    $requestKey = hash('sha256', $owner . ':' . $key);
    $s = $db->prepare('SELECT * FROM orders WHERE request_key=?'); $s->execute([$requestKey]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['product'] !== $product) throw new PaymentError(409, 'Заказ относится к другому тарифу.');
    if (!$row) {
        $id = bin2hex(random_bytes(16));
        $payload = payment_payload($c, $id, $customer['email'], $product);
        $s = $db->prepare('INSERT INTO orders (id,owner,request_key,name,email,phone,telegram,amount,currency,shop_id,test,status,payload,created_at,consent_version,product,fulfillment_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$id,$owner,$requestKey,$customer['name'],$customer['email'],$customer['phone'],$customer['telegram'],
            $p['amount'],'RUB',(string)$c['shop_id'],($c['mode']==='test'?1:0),'creating',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time(),$c['consent_version'] ?? 'unconfigured',$product,$product==='probe10'?'not_applicable':'pending']);
        $row = payment_find($db, $id);
    }
    if ($row['payment_id']) return $row;
    // The YooKassa idempotence window is 24h. An ambiguous older request must not be recreated.
    if (time() - (int)$row['created_at'] > 82800) throw new PaymentError(409, 'Нужна проверка заказа организатором. Не оплачивайте повторно.');
    $payment = $api('POST', 'payments', json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR), $row['id']);
    payment_apply($db, $row, $payment);
    return payment_find($db, $row['id']);
}
