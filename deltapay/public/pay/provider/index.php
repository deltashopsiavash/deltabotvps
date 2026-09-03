<?php

declare(strict_types=1);

// DeltaPay provider bridge.
// Keeps the customer-facing payment flow on the dedicated payment domain while
// reusing the bot's existing provider request implementation internally.

$projectRoot = dirname(__DIR__, 4);
$bootstrap = $projectRoot . '/deltapay/bootstrap.php';
$legacyPay = $projectRoot . '/pay/index.php';

if (!is_file($bootstrap) || !is_file($legacyPay)) {
    http_response_code(503);
    exit('Payment service is temporarily unavailable.');
}

require_once $bootstrap;

$method = strtolower(trim((string)($_GET['method'] ?? '')));
$orderId = trim((string)($_GET['order_id'] ?? ''));

if (!deltaPayAllowedMethod($method)) {
    http_response_code(400);
    exit('Invalid payment method.');
}
if ($orderId === '' || strlen($orderId) > 190 || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
    http_response_code(400);
    exit('Invalid order id.');
}

$order = deltaPayFindOrder($deltaPayDb, $orderId);
if (!$order || !in_array((string)$order['state'], ['pending', 'send'], true)) {
    http_response_code(404);
    exit('Order is not payable.');
}
if (!deltaPayMethodEnabled($deltaPayDb, $method)) {
    http_response_code(403);
    exit('Payment method is disabled.');
}

// Reuse the existing provider handler without exposing the bot/webhook domain.
$_GET = [
    $method => '',
    'hash_id' => $orderId,
    'direct' => '1',
];

require $legacyPay;
