<?php
// Pure feature checks: no database, Telegram API, or exchange connection.
$values = [];
function getSettingValue($key, $default = null){ global $values; return $values[$key] ?? $default; }
function upsertSettingValue($key, $value){ global $values; $values[$key] = $value; return true; }
require __DIR__ . '/../delta_order_features.php';

function expectFeature($condition, $message){
    if(!$condition){ fwrite(STDERR, $message . PHP_EOL); exit(1); }
}
function editKeys($keys,$messageId,$chatId){
    global $editedReceiptMessages;
    $editedReceiptMessages[]=['chat'=>$chatId,'message'=>$messageId,'keyboard'=>json_decode($keys,true)];
}

expectFeature(deltaUsdtAmount(45000, 243642) === '0.1847', 'USDT amount must round up to four decimals');
expectFeature(deltaUsdtAmount(1, 243642) === '0.0001', 'Small amounts must not round down to zero');
deltaSetDiscountOwner('VIP-ONE', 6166906522);
expectFeature(deltaDiscountAllowedForUser('VIP-ONE', 6166906522), 'Private discount should work for its owner');
expectFeature(!deltaDiscountAllowedForUser('VIP-ONE', 123456789), 'Private discount must reject other users');
expectFeature(deltaDiscountAllowedForUser('PUBLIC', 123456789), 'Ordinary discount should stay public');

// All four mother switch combinations; forced-user policies cannot bypass
// the main switch in SMS-only/manual mode. Child behavior stays unchanged.
foreach([['off','off','manual'],['off','on','sms'],['on','off','legacy'],['on','on','legacy']] as [$main,$sms,$expected]){
    $state=['cartToCartAutoAcceptState'=>$main,'cartToCartSmsAcceptState'=>$sms];
    expectFeature(deltaReceiptApprovalMode($state)===$expected,'Incorrect mother approval priority');
    foreach([false,true] as $forced){
        expectFeature(deltaLegacyReceiptWorkerAllowed($state,false,$forced)===($main==='on'),'Forced user must not bypass mother switch');
        expectFeature(deltaLegacyReceiptWorkerAllowed($state,true,$forced)===($main==='on'||$forced),'Reseller approval behavior must stay unchanged');
    }
    expectFeature(deltaReceiptApprovalMode($state,true)===($main==='on'?'legacy':'manual'),'SMS mode must not reach resellers');
}
expectFeature(deltaReceiptApprovalMode([])==='manual','Missing switches must fail closed');

$now=time();
$b=['shown_at'=>$now-60,'expires_at'=>$now+3500,'bank'=>'melli','account_hint'=>'00001','amount_irr'=>800001];
$e=['event_id'=>str_repeat('a',64),'bank'=>'melli','source'=>'sms','account_hint'=>'00001','amount_irr'=>800001,'received_at'=>$now-30,'ingested_at'=>$now-20];
expectFeature(deltaSmsEventMatches($b,$e,$now-10,$now-120,$now),'Exact trusted SMS should match reserved invoice');
foreach(['amount_irr'=>800000,'account_hint'=>'00002','bank'=>'pasargad','source'=>'notification','received_at'=>$now-61,'ingested_at'=>$now+1] as $key=>$bad){
    expectFeature(!deltaSmsEventMatches($b,array_replace($e,[$key=>$bad]),$now-10,$now-120,$now),'Reject mismatching SMS field '.$key);
}
$senderBinding=array_replace($b,['bank'=>'nominal','account_hint'=>'00000','amount_irr'=>800000]);
$senderEvent=array_replace($e,['sender'=>'bank-test','amount_irr'=>800000]);
expectFeature(!deltaSmsSenderReservation(array_replace($senderBinding,['bank'=>'sender'])),'Old suffix reservations remain manual');
expectFeature(strpos(deltaSmsPresentationText($senderBinding),'800000')!==false,'Payment instructions show the exact original rial price');
expectFeature(deltaSmsEventMatches($senderBinding,$senderEvent,$now-10,$now-120,$now),'Paired phone sender event matches without account configuration');
expectFeature(deltaSmsEventMatches($senderBinding,array_replace($senderEvent,['bank'=>'pasargad','account_hint'=>'98765','sender'=>'second-bank-test']),$now-10,$now-120,$now),'Multiple approved bank senders and accounts need no card mapping');
expectFeature(!deltaSmsEventMatches($senderBinding,array_replace($senderEvent,['sender'=>'']),$now-10,$now-120,$now),'Missing sender fails closed');
expectFeature(!deltaSmsEventMatches($senderBinding,array_replace($senderEvent,['source'=>'notification']),$now-10,$now-120,$now),'Notification alone cannot approve');
expectFeature(!deltaSmsEventMatches($senderBinding,array_replace($senderEvent,['amount_irr'=>800002]),$now-10,$now-120,$now),'Wrong exact amount cannot approve');
expectFeature(!deltaSmsEventMatches($b,$e,$now-10,$now-50,$now),'Reject old activation epoch');
expectFeature(!deltaSmsEventMatches($b,$e,$now+3600,$now-120,$now),'Reject expired receipt');
expectFeature(!deltaSmsEventMatches($b,$e,$now-10,$now-120,$now+4501),'Reject expired processing window');

$old = time() - 20;
deltaMarkReceiptSubmitted('receipt-new', $old);
expectFeature(deltaReceiptHasSubmissionMarker('receipt-new'), 'New receipt needs a submission marker');
expectFeature(!deltaReceiptHasSubmissionMarker('receipt-old'), 'Old receipt must not be marked');
expectFeature(deltaReceiptSubmittedAt('receipt-new') === $old, 'Receipt marker must preserve submission time');
$fakeAdminReply=(object)['ok'=>true,'result'=>(object)['message_id'=>321]];
expectFeature(deltaRememberReceiptAdminMessage('receipt-new',111,$fakeAdminReply), 'Text receipt message should be stored for the first admin');
$fakeAdminReply->result->message_id=654;
expectFeature(deltaRememberReceiptAdminMessage('receipt-new',222,$fakeAdminReply), 'Text receipt message should be stored for a second admin');
expectFeature(deltaReceiptAdminMessageTargets(['hash_id'=>'receipt-new','chat_id'=>111,'message_id'=>321])===[[111,321],[222,654]], 'Every administrator needs the right message id');
$editedReceiptMessages=[];
deltaSyncAutoApprovedReceiptMessages(['hash_id'=>'receipt-new','chat_id'=>111,'message_id'=>321,'user_id'=>123456]);
expectFeature(count($editedReceiptMessages)===2, 'Auto approval should refresh both admin receipt buttons');
expectFeature($editedReceiptMessages[1]['keyboard']['inline_keyboard'][0][0]['text']==='✅ خودکار تأیید شد', 'Receipt must show its final approval state');
expectFeature($editedReceiptMessages[1]['keyboard']['inline_keyboard'][1][0]['callback_data']==='receiptUserInfo_123456', 'Auto-approved receipt keeps user details button');
deltaSetForceAutoApprove(6166906522, true);
expectFeature(deltaForceAutoApprove(6166906522), 'Forced approval should work independently');
deltaSetForceAutoApprove(6166906522, false);
expectFeature(!deltaForceAutoApprove(6166906522), 'Forced approval should be reversible');
expectFeature(deltaAutoApprovePolicy(6166906522)==='normal', 'Normal policy follows global settings');
deltaSetAutoApprovePolicy(6166906522,'never');
expectFeature(deltaAutoApprovePolicy(6166906522)==='never', 'Never policy overrides global approval');
expectFeature(getSettingValue('USER_NO_AUTOAPPROVE_6166906522')==='1', 'Legacy no-auto flag stays in sync');
deltaSetAutoApprovePolicy(6166906522,'always');
expectFeature(deltaAutoApprovePolicy(6166906522)==='always', 'Always policy overrides disabled global approval');
expectFeature(getSettingValue('USER_NO_AUTOAPPROVE_6166906522')==='0', 'Always policy clears legacy block');
deltaSetAutoApprovePolicy(6166906522,'normal');
expectFeature(deltaAutoApprovePolicy(6166906522)==='normal', 'Normal policy clears both exceptions');
$testId=12345;
$trackForId=(string)(10000000+(($testId*32452843+27182818)%90000000));
expectFeature(deltaTrackingIdFromCode($trackForId)===$testId, 'Tracking search must reverse the invoice permutation');
expectFeature(deltaTrackingIdFromCode('123')===0, 'Invalid tracking codes must not resolve');
expectFeature(deltaParseUsdtRate(['status'=>'ok','stats'=>['usdt-rls'=>['bestSell'=>'2436420']]],'nobitex-stats')===243642, 'Nobitex rials must convert to tomans');
expectFeature(deltaParseUsdtRate(['status'=>'ok','lastUpdate'=>time()*1000,'asks'=>[['2436420','2']]],'nobitex-book')===243642, 'Nobitex order book can quote USDT');
expectFeature(deltaParseUsdtRate(['success'=>true,'result'=>['symbols'=>['USDTTMN'=>['stats'=>['askPrice'=>'243642.50']]]]],'wallex')===243643, 'Wallex toman rate can back up Nobitex');
expectFeature(deltaParseUsdtRate(['status'=>'fail','stats'=>['usdt-rls'=>['bestSell'=>'2436420']]],'nobitex-stats')===0, 'Invalid exchange responses cannot set rates');
$projectQr=deltaQrBackgroundPath(0);
$originalDirectory=getcwd();
chdir(__DIR__ . '/../settings');
$cronQr=deltaQrBackgroundPath(0);
chdir($originalDirectory);
expectFeature($projectQr===$cronQr && is_file($cronQr), 'Webhook and cron must use the same QR background');
$deliveryReport=deltaDeliveryReportText('known-invoice',123456,'plan<one>',50,30,45000);
expectFeature(strpos($deliveryReport,'plan&lt;one&gt;')!==false, 'Admin delivery report must escape service names');
expectFeature(strpos($deliveryReport,deltaTrackingCode('known-invoice'))!==false, 'Admin delivery report must contain tracking code');
$code = deltaTrackingCode('known-invoice');
expectFeature((bool)preg_match('/^[1-9][0-9]{7}$/', $code), 'Tracking code must be eight digits');
expectFeature($code === deltaTrackingCode('known-invoice'), 'Tracking code must be stable');
expectFeature(!deltaIsUsdtInvoice('known-invoice'), 'Ordinary receipts are eligible for auto approval');
upsertSettingValue('USDT_INVOICE_known-invoice','{}');
expectFeature(deltaIsUsdtInvoice('known-invoice'), 'Crypto receipts require manual review');

$paymentKeys = ['usdtwallet' => '0xA5236df156BE4195735700caDa970Ac0Fef3a59B'];
$quote = ['rate' => 243642, 'price' => 45000, 'amount' => '0.1847', 'wallet' => $paymentKeys['usdtwallet']];
$invoice = deltaUsdtPayText(['hash_id' => 'known-invoice', 'price' => 45000], $quote);
expectFeature(strpos($invoice, '0.1847 USDT') !== false, 'Invoice must show the fixed quoted amount');
expectFeature(strpos($invoice, $code) !== false, 'Invoice must show its tracking code');
echo "Order feature checks passed\n";
