<?php
/**
 * Apply the DeltaPay personal-gateway integration to the legacy monolithic
 * bot/config files after a fresh clone/update.
 *
 * The project historically keeps very large bot.php/config.php files. Keeping
 * this small, idempotent patcher lets fresh installs and updates apply the same
 * integration safely without duplicating those files.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$botPath = $root . '/bot.php';
$configPath = $root . '/config.php';

foreach ([$botPath, $configPath] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "[DeltaPay] Missing required file: {$required}\n");
        exit(1);
    }
}

function deltaPatchReplaceOnce(string $content, string $search, string $replace, string $label): string
{
    if (strpos($content, $replace) !== false) {
        return $content;
    }
    if (strpos($content, $search) === false) {
        throw new RuntimeException("Patch anchor not found: {$label}");
    }
    $count = 0;
    $result = str_replace($search, $replace, $content, $count);
    if ($count < 1) {
        throw new RuntimeException("Patch replacement failed: {$label}");
    }
    return $result;
}

function deltaPatchLint(string $path): void
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("PHP syntax check failed for {$path}:\n" . implode("\n", $output));
    }
}

$botOriginal = file_get_contents($botPath);
$configOriginal = file_get_contents($configPath);
if ($botOriginal === false || $configOriginal === false) {
    fwrite(STDERR, "[DeltaPay] Could not read bot source files.\n");
    exit(1);
}

$bot = $botOriginal;
$config = $configOriginal;

try {
    // 1) Stable public URL builder for the personal gateway button.
    if (strpos($bot, 'function deltaPersonalPayUrl(') === false) {
        $anchor = "include_once 'config.php';\n";
        $helper = <<<'PHP'
include_once 'config.php';

// DELTAPAY_PERSONAL_GATEWAY_V1
if(!function_exists('deltaPersonalPayUrl')){
    function deltaPersonalPayUrl($orderId){
        global $paymentDomain;
        $domain = strtolower(trim((string)($paymentDomain ?? '')));
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        if($domain === '' || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) return '';
        return 'https://' . $domain . '/pay/start/?order_id=' . rawurlencode((string)$orderId);
    }
}
PHP;
        $bot = deltaPatchReplaceOnce($bot, $anchor, $helper . "\n", 'bot DeltaPay helper');
    }

    // 2) Existing gateway/channel toggle handler should safely support a key
    //    that does not exist yet in older BOT_STATES JSON documents.
    $oldToggle = '$newValue = $botState[$match[1]]=="on"?"off":"on";';
    $newToggle = '$newValue = (($botState[$match[1]] ?? "off") == "on") ? "off" : "on";';
    if (strpos($bot, $newToggle) === false) {
        $bot = deltaPatchReplaceOnce($bot, $oldToggle, $newToggle, 'safe gateway toggle');
    }

    // 3) If the personal gateway is the only online method, buying must still
    //    be allowed even when card-to-card and wallet are disabled.
    $oldSellGuard = 'if($botState[\'cartToCartState\'] == "off" && $botState[\'walletState\'] == "off"){';
    $newSellGuard = 'if($botState[\'cartToCartState\'] == "off" && $botState[\'walletState\'] == "off" && (($botState[\'deltaPayState\'] ?? "off") != "on")){';
    if (strpos($bot, $newSellGuard) === false && strpos($bot, $oldSellGuard) !== false) {
        $bot = str_replace($oldSellGuard, $newSellGuard, $bot);
    }

    // 4) Add "Personal Gateway" next to the existing online gateways in every
    //    standard invoice keyboard (buy, renew, increase, wallet top-up, etc.).
    $personalButtonNeedle = "if((\$botState['deltaPayState'] ?? 'off') == \"on\"";
    if (strpos($bot, $personalButtonNeedle) === false) {
        $nextPayPattern = '/^([\\t ]*)if\\(\\$botState\\[\'nextpay\'\\] == "on"\\) \\$keyboard\\[\\] = \\[\\[\'text\' => \\$buttonValues\\[\'nextpay_gateway\'\\],  \'url\' => \\$botUrl \\. "pay\\/\\?nextpay&hash_id=" \\. \\$hash_id\\]\\];$/m';
        $count = 0;
        $bot = preg_replace_callback($nextPayPattern, static function(array $m): string {
            $indent = $m[1];
            return $m[0] . "\n" . $indent . "if((\$botState['deltaPayState'] ?? 'off') == \"on\" && !empty(\$paymentDomain) && deltaPersonalPayUrl(\$hash_id) !== '') \$keyboard[] = [['text' => '💳 درگاه شخصی', 'url' => deltaPersonalPayUrl(\$hash_id)]];";
        }, $bot, -1, $count) ?? $bot;
        if ($count < 1) {
            throw new RuntimeException('No invoice gateway rows were found to attach DeltaPay.');
        }
        echo "[DeltaPay] Personal gateway button added to {$count} invoice keyboard block(s).\n";
    }

    // 5) Add the on/off toggle and configured domain to the existing
    //    "gateway & channel" admin settings screen.
    $gateGlobalOld = "function getGateWaysKeys(){\n    global \$connection, \$mainValues, \$buttonValues, \$isChildBot;";
    $gateGlobalNew = "function getGateWaysKeys(){\n    global \$connection, \$mainValues, \$buttonValues, \$isChildBot, \$paymentDomain;";
    if (strpos($config, $gateGlobalNew) === false) {
        $config = deltaPatchReplaceOnce($config, $gateGlobalOld, $gateGlobalNew, 'getGateWaysKeys globals');
    }

    $nextPayState = '$nextpay = $botState[\'nextpay\']=="on"?$buttonValues[\'on\']:$buttonValues[\'off\'];';
    $deltaStateBlock = <<<'PHP'
$nextpay = $botState['nextpay']=="on"?$buttonValues['on']:$buttonValues['off'];
    $deltaPayState = (($botState['deltaPayState'] ?? 'off') == "on")?$buttonValues['on']:$buttonValues['off'];
    $deltaPayDomain = trim((string)($paymentDomain ?? ''));
PHP;
    if (strpos($config, '$deltaPayState = ((') === false) {
        $config = deltaPatchReplaceOnce($config, $nextPayState, $deltaStateBlock, 'DeltaPay admin state');
    }

    $cartRow = <<<'PHP'
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"deltach"]
        ],
PHP;
    $cartAndDeltaRows = <<<'PHP'
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$deltaPayState,'callback_data'=>"changeGateWaysdeltaPayState"],
            ['text'=>"💳 درگاه شخصی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>($deltaPayDomain !== '' ? $deltaPayDomain : 'تنظیم نشده'),'callback_data'=>"deltach"],
            ['text'=>"🌐 دامنه درگاه شخصی",'callback_data'=>"deltach"]
        ],
PHP;
    if (strpos($config, 'changeGateWaysdeltaPayState') === false) {
        $config = deltaPatchReplaceOnce($config, $cartRow, $cartAndDeltaRows, 'DeltaPay admin keyboard');
    }

    if ($bot !== $botOriginal) {
        if (file_put_contents($botPath, $bot) === false) {
            throw new RuntimeException('Could not write bot.php');
        }
    }
    if ($config !== $configOriginal) {
        if (file_put_contents($configPath, $config) === false) {
            throw new RuntimeException('Could not write config.php');
        }
    }

    deltaPatchLint($botPath);
    deltaPatchLint($configPath);

    echo "[DeltaPay] Bot integration is installed and PHP syntax is valid.\n";
} catch (Throwable $e) {
    // Never leave a half-patched monolithic bot behind.
    @file_put_contents($botPath, $botOriginal);
    @file_put_contents($configPath, $configOriginal);
    fwrite(STDERR, "[DeltaPay] Integration failed: " . $e->getMessage() . "\n");
    exit(1);
}
