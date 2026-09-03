<?php

declare(strict_types=1);

// DeltaPay callback bridge for ZarinPal.
// Provider callbacks return to the dedicated payment domain and are then
// delegated internally to the bot's existing verified fulfilment handler.

$projectRoot = dirname(__DIR__, 4);
$legacyBack = $projectRoot . '/pay/back.php';

if (!is_file($legacyBack)) {
    http_response_code(503);
    exit('Payment service is temporarily unavailable.');
}

$method = strtolower(trim((string)($_GET['method'] ?? '')));
$orderId = trim((string)($_GET['order_id'] ?? ''));

if ($method !== 'zarinpal') {
    http_response_code(400);
    exit('Unsupported callback method.');
}
if ($orderId === '' || strlen($orderId) > 190 || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
    http_response_code(400);
    exit('Invalid order id.');
}

$authority = trim((string)($_GET['Authority'] ?? ''));
$status = trim((string)($_GET['Status'] ?? ''));

$_GET = [
    'zarinpal' => '',
    'hash_id' => $orderId,
    'Authority' => $authority,
    'Status' => $status,
];

require $legacyBack;
