<?php
declare(strict_types=1);
// All configuration, customer records and payment logic are OUTSIDE the public website.
$private = dirname(__DIR__, 3) . '/realty-private/app.php';
if (!is_file($private)) {
    http_response_code(503); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo '{"error":"Приём оплаты ещё настраивается."}'; exit;
}
require $private;
