<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$config = deltaPayConfig($deltaPayDb);
$orderId = trim((string)($_GET['order_id'] ?? ''));
$method = strtolower(trim((string)($_GET['method'] ?? '')));

if ($method === '') {
    $method = strtolower(trim((string)($config['default_method'] ?? '')));
}

// Backward-compatible fallback: if no explicit DeltaPay provider has been
// saved yet, but exactly one supported online gateway is enabled in BOT_STATES,
// use that provider automatically. This makes an existing ZarinPal-only setup
// work immediately after enabling the Personal Gateway.
if ($method === '') {
    $enabledMethods = [];
    foreach (['zarinpal', 'nextpay', 'nowpayment'] as $candidate) {
        if (deltaPayMethodEnabled($deltaPayDb, $candidate)) {
            $enabledMethods[] = $candidate;
        }
    }
    if (count($enabledMethods) === 1) {
        $method = $enabledMethods[0];
    }
}

$error = '';
$order = null;
$statusLabel = '';
$statusClass = 'muted';
$continueUrl = '';

if ($orderId === '' || strlen($orderId) > 190 || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
    http_response_code(400);
    $error = 'شناسه سفارش معتبر نیست.';
} else {
    $order = deltaPayFindOrder($deltaPayDb, $orderId);
    if (!$order) {
        http_response_code(404);
        $error = 'سفارش پیدا نشد یا شناسه آن اشتباه است.';
    } else {
        [$statusLabel, $statusClass] = deltaPayStateLabel((string)$order['state']);

        if ((int)($order['special_offer_id'] ?? 0) > 0) {
            $error = 'پرداخت پیشنهاد ویژه فقط از موجودی کیف پول داخل ربات انجام می‌شود.';
        } elseif (($config['enabled'] ?? true) !== true && (string)($config['enabled'] ?? '1') !== '1') {
            $error = 'درگاه پرداخت موقتاً غیرفعال است.';
        } elseif (!in_array((string)$order['state'], ['pending', 'send'], true)) {
            if ((string)$order['state'] !== 'paid') {
                $error = 'این سفارش دیگر قابل پرداخت نیست.';
            }
        } elseif ($method === '') {
            $error = 'روش پرداخت هنوز برای این درگاه انتخاب نشده است.';
        } elseif (!deltaPayAllowedMethod($method)) {
            $error = 'روش پرداخت انتخاب‌شده معتبر نیست.';
        } elseif (!deltaPayMethodEnabled($deltaPayDb, $method)) {
            $error = 'این روش پرداخت در ربات غیرفعال است.';
        } else {
            $continueUrl = deltaPayBotGatewayUrl($method, $orderId);
        }
    }
}

$title = trim((string)($config['title'] ?? '')) ?: 'درگاه پرداخت دلتا';
$typeLabel = $order ? deltaPayTypeLabel((string)$order['type']) : 'پرداخت سفارش';
$price = $order ? number_format((int)$order['price']) : '0';
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        *{box-sizing:border-box}html,body{min-height:100%;margin:0}body{font-family:Tahoma,Arial,sans-serif;background:#f4f6f9;color:#151a23;display:flex;align-items:center;justify-content:center;padding:24px}.card{width:min(100%,480px);background:#fff;border:1px solid #e4e8ef;border-radius:22px;padding:28px;box-shadow:0 18px 55px rgba(22,30,46,.08)}.brand{text-align:center;margin-bottom:24px}.logo{width:58px;height:58px;border-radius:18px;background:#111827;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;margin-bottom:12px}.brand h1{font-size:20px;margin:0}.brand p{font-size:13px;color:#687386;margin:8px 0 0}.rows{border-top:1px solid #edf0f5;border-bottom:1px solid #edf0f5;padding:8px 0;margin:18px 0}.row{display:flex;justify-content:space-between;gap:16px;padding:10px 2px;font-size:14px}.row span:first-child{color:#737d8d}.row strong{text-align:left;overflow-wrap:anywhere}.price{font-size:20px}.badge{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.badge.success{background:#eaf8ef;color:#19713a}.badge.pending{background:#fff5d8;color:#8b6400}.badge.danger{background:#ffeded;color:#a42d2d}.badge.muted{background:#eef1f5;color:#606b7a}.notice{padding:13px 14px;border-radius:13px;background:#fff3f3;color:#9b3030;font-size:13px;line-height:1.9;margin:14px 0}.paid{padding:13px 14px;border-radius:13px;background:#ecf9f0;color:#176b38;font-size:13px;line-height:1.9;margin:14px 0}.btn{appearance:none;border:0;width:100%;display:block;text-decoration:none;text-align:center;background:#111827;color:#fff;border-radius:14px;padding:14px 16px;font-size:15px;font-weight:700;cursor:pointer}.btn.disabled{background:#c9ced7;cursor:not-allowed}.safe{font-size:11px;color:#828b99;line-height:1.8;text-align:center;margin:16px 8px 0}.order-id{direction:ltr;unicode-bidi:embed;font-family:monospace}
    </style>
</head>
<body>
<main class="card">
    <div class="brand">
        <div class="logo">Δ</div>
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>بررسی و ادامه پرداخت سفارش</p>
    </div>

    <?php if ($order): ?>
        <div class="rows">
            <div class="row"><span>شماره سفارش</span><strong class="order-id"><?= htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="row"><span>شرح</span><strong><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="row"><span>مبلغ</span><strong class="price"><?= $price ?> تومان</strong></div>
            <div class="row"><span>وضعیت</span><strong><span class="badge <?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></span></strong></div>
        </div>
    <?php endif; ?>

    <?php if ($order && (string)$order['state'] === 'paid'): ?>
        <div class="paid">این سفارش قبلاً با موفقیت پرداخت شده است.</div>
        <span class="btn disabled">پرداخت انجام شده</span>
    <?php elseif ($error !== ''): ?>
        <div class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <span class="btn disabled">امکان ادامه پرداخت نیست</span>
    <?php elseif ($continueUrl !== ''): ?>
        <a class="btn" href="<?= htmlspecialchars($continueUrl, ENT_QUOTES, 'UTF-8') ?>" rel="nofollow">ادامه و پرداخت امن</a>
    <?php endif; ?>

    <p class="safe">اطلاعات کارت، CVV2 و رمز پویا در این صفحه دریافت یا ذخیره نمی‌شود. ورود اطلاعات پرداخت فقط در صفحه رسمی سرویس پرداخت انجام خواهد شد.</p>
</main>
</body>
</html>
