<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$options = getopt('', ['domain:', 'method::', 'enabled::', 'title::']);
$domain = strtolower(trim((string)($options['domain'] ?? '')));
$method = strtolower(trim((string)($options['method'] ?? '')));
$title = trim((string)($options['title'] ?? 'درگاه پرداخت دلتا'));
$enabledArg = strtolower(trim((string)($options['enabled'] ?? '1')));
$enabled = !in_array($enabledArg, ['0', 'false', 'off', 'no'], true);

if ($domain === '' || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
    fwrite(STDERR, "Invalid payment domain.\n");
    exit(1);
}

if ($method !== '' && !deltaPayAllowedMethod($method)) {
    fwrite(STDERR, "Unsupported method.\n");
    exit(1);
}

$current = deltaPayConfig($deltaPayDb);
$current['domain'] = $domain;
$current['enabled'] = $enabled;
$current['title'] = $title !== '' ? $title : 'درگاه پرداخت دلتا';
if ($method !== '') {
    $current['default_method'] = $method;
} elseif (!isset($current['default_method'])) {
    $current['default_method'] = '';
}

$json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt = $deltaPayDb->prepare("INSERT INTO `setting` (`type`,`value`) VALUES ('DELTA_PAY_CONFIG', ?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
if (!$stmt) {
    fwrite(STDERR, "Could not prepare DeltaPay configuration update.\n");
    exit(1);
}
$stmt->bind_param('s', $json);
$stmt->execute();
$stmt->close();

echo "DeltaPay configuration saved.\n";
