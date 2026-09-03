<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$path = (string)($argv[1] ?? '');
$domain = strtolower(trim((string)($argv[2] ?? '')));

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "baseInfo.php not found.\n");
    exit(1);
}

if ($domain === '' || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
    fwrite(STDERR, "Invalid payment domain.\n");
    exit(1);
}

$content = file_get_contents($path);
if ($content === false) {
    fwrite(STDERR, "Could not read baseInfo.php.\n");
    exit(1);
}

$line = '$paymentDomain = ' . var_export($domain, true) . ';';
$pattern = '/^\s*\$paymentDomain\s*=.*?;\s*$/m';

if (preg_match($pattern, $content)) {
    $content = preg_replace($pattern, $line, $content, 1);
} elseif (strpos($content, '?>') !== false) {
    $content = preg_replace('/\?>\s*$/', $line . "\n?>\n", $content, 1);
} else {
    $content = rtrim($content) . "\n" . $line . "\n";
}

if ($content === null || file_put_contents($path, $content) === false) {
    fwrite(STDERR, "Could not update baseInfo.php.\n");
    exit(1);
}

echo "Payment domain saved to baseInfo.php.\n";
