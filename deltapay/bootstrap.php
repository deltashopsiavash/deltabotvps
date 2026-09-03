<?php
/**
 * DeltaPay bootstrap.
 *
 * This layer intentionally never receives bank-card credentials. It only
 * reads an existing bot invoice and hands the customer to the configured
 * provider through the dedicated DeltaPay domain.
 */

declare(strict_types=1);

$deltaPayProjectRoot = dirname(__DIR__);
$deltaPayBaseInfo = $deltaPayProjectRoot . '/baseInfo.php';

if (!is_file($deltaPayBaseInfo)) {
    http_response_code(503);
    exit('DeltaPay is not configured yet.');
}

require_once $deltaPayBaseInfo;

if (!isset($dbUserName, $dbPassword, $dbName)) {
    http_response_code(503);
    exit('Database configuration is unavailable.');
}

$deltaPayDb = @new mysqli('localhost', (string)$dbUserName, (string)$dbPassword, (string)$dbName);
if ($deltaPayDb->connect_errno) {
    error_log('DeltaPay DB connection failed: ' . $deltaPayDb->connect_error);
    http_response_code(503);
    exit('Payment service is temporarily unavailable.');
}
$deltaPayDb->set_charset('utf8mb4');

function deltaPayConfig(mysqli $db): array
{
    global $paymentDomain;

    $defaults = [
        'enabled' => true,
        'domain' => isset($paymentDomain) ? trim((string)$paymentDomain) : '',
        'title' => 'درگاه پرداخت دلتا',
        'default_method' => '',
    ];

    $stmt = $db->prepare("SELECT `value` FROM `setting` WHERE `type`='DELTA_PAY_CONFIG' LIMIT 1");
    if (!$stmt) {
        return $defaults;
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !isset($row['value'])) {
        return $defaults;
    }

    $saved = json_decode((string)$row['value'], true);
    if (!is_array($saved)) {
        return $defaults;
    }

    return array_merge($defaults, $saved);
}

function deltaPayFindOrder(mysqli $db, string $orderId): ?array
{
    $stmt = $db->prepare("SELECT `id`,`hash_id`,`description`,`user_id`,`type`,`plan_id`,`volume`,`day`,`price`,`request_date`,`state`,`special_offer_id` FROM `pays` WHERE `hash_id`=? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function deltaPayTypeLabel(string $type): string
{
    if ($type === 'BUY_SUB') return 'خرید اشتراک';
    if ($type === 'RENEW_ACCOUNT' || $type === 'RENEW_SCONFIG') return 'تمدید اشتراک';
    if ($type === 'INCREASE_WALLET') return 'شارژ کیف پول';
    if (preg_match('/^INCREASE_DAY_/', $type)) return 'افزایش زمان اشتراک';
    if (preg_match('/^INCREASE_VOLUME_/', $type)) return 'افزایش حجم اشتراک';
    return 'پرداخت سفارش';
}

function deltaPayStateLabel(string $state): array
{
    return match ($state) {
        'paid' => ['پرداخت شده', 'success'],
        'pending', 'send' => ['در انتظار پرداخت', 'pending'],
        'canceled' => ['لغو شده', 'danger'],
        'low_payment' => ['پرداخت ناقص', 'danger'],
        default => ['وضعیت: ' . $state, 'muted'],
    };
}

function deltaPayAllowedMethod(string $method): bool
{
    return in_array($method, ['zarinpal', 'nextpay', 'nowpayment'], true);
}

function deltaPayMethodEnabled(mysqli $db, string $method): bool
{
    $stmt = $db->prepare("SELECT `value` FROM `setting` WHERE `type`='BOT_STATES' LIMIT 1");
    if (!$stmt) return false;
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;
    $states = json_decode((string)$row['value'], true);
    if (!is_array($states)) return false;

    $key = $method === 'nowpayment' ? 'nowPaymentOther' : $method;
    return (($states[$key] ?? 'off') === 'on');
}

function deltaPayBotGatewayUrl(string $method, string $orderId): string
{
    global $paymentDomain;
    $domain = strtolower(trim((string)($paymentDomain ?? '')));
    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = preg_replace('#/.*$#', '', $domain);
    if ($domain === '' || !preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
        return '';
    }
    return 'https://' . $domain . '/pay/provider/?method=' . rawurlencode($method) . '&order_id=' . rawurlencode($orderId);
}

function deltaPayPublicBaseUrl(array $config): string
{
    $domain = trim((string)($config['domain'] ?? ''));
    if ($domain === '') return '';
    return 'https://' . $domain;
}
