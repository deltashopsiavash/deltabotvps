<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

// Values are required only when the corresponding option is present. Using a
// single colon is intentional: PHP getopt() may ignore a space-separated value
// for optional-value (::) long options such as `--method zarinpal`.
$options = getopt('', ['domain:', 'method:', 'enabled:', 'title:']);
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
if ($json === false) {
    fwrite(STDERR, "Could not encode DeltaPay configuration.\n");
    exit(1);
}

// The legacy `setting` table does not have a UNIQUE index on `type`, so use
// an explicit SELECT + UPDATE/INSERT instead of ON DUPLICATE KEY UPDATE.
$stmt = $deltaPayDb->prepare("SELECT `id` FROM `setting` WHERE `type`='DELTA_PAY_CONFIG' ORDER BY `id` ASC LIMIT 1");
if (!$stmt) {
    fwrite(STDERR, "Could not read DeltaPay configuration.\n");
    exit(1);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    $id = (int)$row['id'];
    $stmt = $deltaPayDb->prepare("UPDATE `setting` SET `value`=? WHERE `id`=?");
    if (!$stmt) {
        fwrite(STDERR, "Could not update DeltaPay configuration.\n");
        exit(1);
    }
    $stmt->bind_param('si', $json, $id);
} else {
    $type = 'DELTA_PAY_CONFIG';
    $stmt = $deltaPayDb->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
    if (!$stmt) {
        fwrite(STDERR, "Could not create DeltaPay configuration.\n");
        exit(1);
    }
    $stmt->bind_param('ss', $type, $json);
}

$stmt->execute();
$stmt->close();

echo "DeltaPay configuration saved.\n";
