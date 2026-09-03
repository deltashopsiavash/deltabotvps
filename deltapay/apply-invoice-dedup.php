<?php
/**
 * Prevent duplicate online payment choices on Telegram invoices.
 * When the branded DeltaPay entry is enabled, the same provider is reached
 * through DeltaPay, so direct online provider buttons are omitted from the
 * invoice UI. Provider settings remain unchanged for backend processing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$botPath = dirname(__DIR__) . '/bot.php';
if (!is_file($botPath)) {
    fwrite(STDERR, "[DeltaPay] bot.php not found.\n");
    exit(1);
}

$original = file_get_contents($botPath);
if ($original === false) {
    fwrite(STDERR, "[DeltaPay] Could not read bot.php.\n");
    exit(1);
}

$bot = $original;
$count = 0;
$pattern = '/^([\t ]*)if\(\$botState\[\'(zarinpal|nextpay|nowPaymentWallet|nowPaymentOther)\'\] == "on"\)([^\r\n]*\$keyboard\[\][^\r\n]*)$/m';
$bot = preg_replace_callback($pattern, static function(array $m) use (&$count): string {
    $count++;
    return $m[1] . "if((\$botState['deltaPayState'] ?? 'off') != \"on\" && \$botState['" . $m[2] . "'] == \"on\")" . $m[3];
}, $bot) ?? $bot;

if ($bot !== $original && file_put_contents($botPath, $bot) === false) {
    fwrite(STDERR, "[DeltaPay] Could not update bot.php.\n");
    exit(1);
}

$output = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($botPath) . ' 2>&1', $output, $code);
if ($code !== 0) {
    @file_put_contents($botPath, $original);
    fwrite(STDERR, "[DeltaPay] Invoice button patch failed PHP lint; changes rolled back.\n" . implode("\n", $output) . "\n");
    exit(1);
}

echo $count > 0
    ? "[DeltaPay] Updated {$count} duplicate online invoice button condition(s).\n"
    : "[DeltaPay] Invoice payment buttons already use DeltaPay visibility rules.\n";
