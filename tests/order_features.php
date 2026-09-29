<?php
// Pure feature checks: no database, Telegram API, or exchange connection.
$values = [];
function getSettingValue($key, $default = null){ global $values; return $values[$key] ?? $default; }
function upsertSettingValue($key, $value){ global $values; $values[$key] = $value; return true; }
require __DIR__ . '/../delta_order_features.php';

function expectFeature($condition, $message){
    if(!$condition){ fwrite(STDERR, $message . PHP_EOL); exit(1); }
}

expectFeature(deltaUsdtAmount(45000, 243642) === '0.1847', 'USDT amount must round up to four decimals');
expectFeature(deltaUsdtAmount(1, 243642) === '0.0001', 'Small amounts must not round down to zero');
deltaSetDiscountOwner('VIP-ONE', 6166906522);
expectFeature(deltaDiscountAllowedForUser('VIP-ONE', 6166906522), 'Private discount should work for its owner');
expectFeature(!deltaDiscountAllowedForUser('VIP-ONE', 123456789), 'Private discount must reject other users');
expectFeature(deltaDiscountAllowedForUser('PUBLIC', 123456789), 'Ordinary discount should stay public');

$old = time() - 20;
deltaMarkReceiptSubmitted('receipt-new', $old);
expectFeature(deltaReceiptHasSubmissionMarker('receipt-new'), 'New receipt needs a submission marker');
expectFeature(!deltaReceiptHasSubmissionMarker('receipt-old'), 'Old receipt must not be marked');
expectFeature(deltaReceiptSubmittedAt('receipt-new') === $old, 'Receipt marker must preserve submission time');
deltaSetForceAutoApprove(6166906522, true);
expectFeature(deltaForceAutoApprove(6166906522), 'Forced approval should work independently');
deltaSetForceAutoApprove(6166906522, false);
expectFeature(!deltaForceAutoApprove(6166906522), 'Forced approval should be reversible');
$code = deltaTrackingCode('known-invoice');
expectFeature((bool)preg_match('/^[1-9][0-9]{7}$/', $code), 'Tracking code must be eight digits');
expectFeature($code === deltaTrackingCode('known-invoice'), 'Tracking code must be stable');

$paymentKeys = ['usdtwallet' => '0xA5236df156BE4195735700caDa970Ac0Fef3a59B'];
$quote = ['rate' => 243642, 'price' => 45000, 'amount' => '0.1847', 'wallet' => $paymentKeys['usdtwallet']];
$invoice = deltaUsdtPayText(['hash_id' => 'known-invoice', 'price' => 45000], $quote);
expectFeature(strpos($invoice, '0.1847 USDT') !== false, 'Invoice must show the fixed quoted amount');
expectFeature(strpos($invoice, $code) !== false, 'Invoice must show its tracking code');
echo "Order feature checks passed\n";
