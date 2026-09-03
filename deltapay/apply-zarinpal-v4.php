<?php
/**
 * Upgrade the legacy ZarinPal SOAP/WebGate implementation in pay/index.php
 * and pay/back.php to the REST v4 request/verify flow.
 *
 * Safe/idempotent: if the v4 markers already exist nothing is changed. If a
 * patch or PHP syntax check fails, both files are restored exactly.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$indexPath = $root . '/pay/index.php';
$backPath = $root . '/pay/back.php';

foreach ([$indexPath, $backPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "[DeltaPay] Missing payment file: {$path}\n");
        exit(1);
    }
}

function zpReplaceOnce(string $content, string $search, string $replace, string $label): string
{
    if (strpos($content, $replace) !== false) return $content;
    if (strpos($content, $search) === false) {
        throw new RuntimeException("ZarinPal patch anchor not found: {$label}");
    }
    $count = 0;
    $out = str_replace($search, $replace, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException("Unexpected replacement count for {$label}: {$count}");
    }
    return $out;
}

function zpLint(string $path): void
{
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("PHP syntax check failed for {$path}:\n" . implode("\n", $output));
    }
}

$indexOriginal = file_get_contents($indexPath);
$backOriginal = file_get_contents($backPath);
if ($indexOriginal === false || $backOriginal === false) {
    fwrite(STDERR, "[DeltaPay] Could not read payment source files.\n");
    exit(1);
}

$index = $indexOriginal;
$back = $backOriginal;

$oldRequest = <<<'PHP'
    elseif(isset($_GET['zarinpal'])){
        $CallbackURL = $botUrl . "pay/back.php?zarinpal&hash_id=$hash_id";
        $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
        $result = $client->PaymentRequest([
        'MerchantID' => $paymentKeys['zarinpal'],
        'Amount' => $amount,
        'Description' => "خرید اکانت",
        'Email' => $Email,
        'Mobile' => $Mobile,
        'CallbackURL' => $CallbackURL,
        ]);
        //==============================================================
        Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority.'/ZarinGate');
        exit();
    }
PHP;

$newRequest = <<<'PHP'
    elseif(isset($_GET['zarinpal'])){
        // ZARINPAL_REST_V4_REQUEST
        $merchantId = trim((string)($paymentKeys['zarinpal'] ?? ''));
        if($merchantId === ''){
            showForm('مرچنت زرین پال تنظیم نشده است');
            exit();
        }

        $CallbackURL = $botUrl . "pay/back.php?zarinpal&hash_id=" . rawurlencode($hash_id);
        // Bot prices are stored/displayed in toman. ZarinPal v4 payment amount
        // is sent in rial, therefore convert toman -> rial for request/verify.
        $zarinAmount = max(10, ((int)$amount) * 10);
        $payload = [
            'merchant_id' => $merchantId,
            'amount' => $zarinAmount,
            'callback_url' => $CallbackURL,
            'description' => (string)$type,
        ];
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'DeltaPay ZarinPal REST v4',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $rawResult = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode((string)$rawResult, true);
        if(!is_array($result)) $result = [];

        $code = (int)($result['data']['code'] ?? 0);
        $authority = trim((string)($result['data']['authority'] ?? ''));
        if($curlError === '' && $httpCode >= 200 && $httpCode < 300 && $code === 100 && $authority !== ''){
            // Bind this provider authority to exactly this bot invoice. The
            // callback must present the same Authority before verification.
            $stmt = $connection->prepare("UPDATE `pays` SET `payid`=? WHERE `hash_id`=? AND `state`='pending'");
            $stmt->bind_param('ss', $authority, $hash_id);
            $stmt->execute();
            $stmt->close();

            header('Location: https://www.zarinpal.com/pg/StartPay/' . rawurlencode($authority), true, 302);
            exit();
        }

        $apiCodeValue = $result['errors']['code'] ?? ($code !== 0 ? $code : 'unknown');
        $apiCode = (string)$apiCodeValue;
        $apiMessage = (string)($result['errors']['message'] ?? '');
        error_log('ZarinPal v4 request failed. HTTP=' . $httpCode . ' code=' . $apiCode . ' curl=' . $curlError . ' message=' . $apiMessage);
        showForm('اتصال به زرین پال ناموفق بود (کد: ' . htmlspecialchars($apiCode, ENT_QUOTES, 'UTF-8') . ')');
        exit();
    }
PHP;

$oldVerify = <<<'PHP'
elseif(isset($_GET['zarinpal'])){
$hash_id = $_GET['hash_id'];
$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send')");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$payInfo = $stmt->get_result();
$stmt->close();

if(mysqli_num_rows($payInfo)==0){
    showForm("کد پرداخت یافت نشد","خطا!");
}else{
    $payParam = $payInfo->fetch_assoc();
    $rowId = $payParam['id'];
    $amount = $payParam['price'];
    $user_id = $payParam['user_id'];
    $payType = $payParam['type'];


    $Authority = $_GET['Authority'];
    //==============================================================
    $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']);
    $result = $client->PaymentVerification([
    'MerchantID' => $paymentKeys['zarinpal'],
    'Authority' => $Authority,
    'Amount' => $amount,
    ]);
    //==============================================================
    if ($_GET['Status'] == 'OK' and $result->Status == 100){
        doAction($rowId, "zarinpal");
    }else{
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'canceled' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $hash_id);
        $stmt->execute();
        $stmt->close();
        
        showForm("پرداخت شما انجام نشد!","درگاه زرین پال");
    }
}
}
PHP;

$newVerify = <<<'PHP'
elseif(isset($_GET['zarinpal'])){
// ZARINPAL_REST_V4_VERIFY
$hash_id = trim((string)($_GET['hash_id'] ?? ''));
$Authority = trim((string)($_GET['Authority'] ?? ''));
$zarinStatus = strtoupper(trim((string)($_GET['Status'] ?? '')));

if($hash_id === '' || $Authority === ''){
    showForm("اطلاعات بازگشت زرین پال ناقص است","خطا!");
    exit();
}

$stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ? AND (`state` = 'pending' OR `state` = 'send') LIMIT 1");
$stmt->bind_param("s", $hash_id);
$stmt->execute();
$payInfo = $stmt->get_result();
$stmt->close();

if(mysqli_num_rows($payInfo)==0){
    showForm("کد پرداخت یافت نشد یا قبلاً پردازش شده است","خطا!");
}else{
    $payParam = $payInfo->fetch_assoc();
    $rowId = (int)$payParam['id'];
    $amount = (int)$payParam['price'];
    $storedAuthority = trim((string)($payParam['payid'] ?? ''));

    // Authority is issued during the request and stored on this exact invoice.
    // Never verify/deliver a transaction belonging to a different order.
    if($storedAuthority === '' || !hash_equals($storedAuthority, $Authority)){
        error_log('ZarinPal callback authority mismatch for order ' . $hash_id);
        showForm("شناسه تراکنش با سفارش مطابقت ندارد","خطا!");
        exit();
    }

    if($zarinStatus !== 'OK'){
        $stmt = $connection->prepare("UPDATE `pays` SET `state`='canceled' WHERE `id`=? AND (`state`='pending' OR `state`='send')");
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $stmt->close();
        showForm("پرداخت توسط کاربر لغو شد یا انجام نشد","درگاه زرین پال");
        exit();
    }

    $merchantId = trim((string)($paymentKeys['zarinpal'] ?? ''));
    if($merchantId === ''){
        showForm("مرچنت زرین پال تنظیم نشده است","خطا!");
        exit();
    }

    $zarinAmount = max(10, $amount * 10);
    $payload = [
        'merchant_id' => $merchantId,
        'amount' => $zarinAmount,
        'authority' => $Authority,
    ];
    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'DeltaPay ZarinPal REST v4',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    $rawResult = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $result = json_decode((string)$rawResult, true);
    if(!is_array($result)) $result = [];

    $code = (int)($result['data']['code'] ?? 0);
    if($curlError === '' && $httpCode >= 200 && $httpCode < 300 && ($code === 100 || $code === 101)){
        doAction($rowId, "zarinpal");
    }else{
        $apiCodeValue = $result['errors']['code'] ?? ($code !== 0 ? $code : 'unknown');
        $apiCode = (string)$apiCodeValue;
        $apiMessage = (string)($result['errors']['message'] ?? '');
        error_log('ZarinPal v4 verify failed. order=' . $hash_id . ' HTTP=' . $httpCode . ' code=' . $apiCode . ' curl=' . $curlError . ' message=' . $apiMessage);
        showForm("تأیید پرداخت زرین پال ناموفق بود (کد: " . htmlspecialchars($apiCode, ENT_QUOTES, 'UTF-8') . ")","درگاه زرین پال");
    }
}
}
PHP;

try {
    if (strpos($index, 'ZARINPAL_REST_V4_REQUEST') === false) {
        $index = zpReplaceOnce($index, $oldRequest, $newRequest, 'payment request');
    }
    if (strpos($back, 'ZARINPAL_REST_V4_VERIFY') === false) {
        $back = zpReplaceOnce($back, $oldVerify, $newVerify, 'payment verify');
    }

    if ($index !== $indexOriginal && file_put_contents($indexPath, $index) === false) {
        throw new RuntimeException('Could not write pay/index.php');
    }
    if ($back !== $backOriginal && file_put_contents($backPath, $back) === false) {
        throw new RuntimeException('Could not write pay/back.php');
    }

    zpLint($indexPath);
    zpLint($backPath);
    echo "[DeltaPay] ZarinPal REST v4 integration is installed and PHP syntax is valid.\n";
} catch (Throwable $e) {
    @file_put_contents($indexPath, $indexOriginal);
    @file_put_contents($backPath, $backOriginal);
    fwrite(STDERR, "[DeltaPay] ZarinPal v4 integration failed: " . $e->getMessage() . "\n");
    exit(1);
}
