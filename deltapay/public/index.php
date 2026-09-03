<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$config = deltaPayConfig($deltaPayDb);

echo json_encode([
    'ok' => true,
    'service' => 'DeltaPay',
    'domain' => (string)($config['domain'] ?? ''),
    'payment_path' => '/pay/start/?order_id=ORDER_ID',
    'card_data_collected' => false,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
