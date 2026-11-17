<?php
// Pure tests: no Telegram requests, credentials or database required.
require_once dirname(__DIR__).'/payment_card_rotation.php';
function assertCard($ok,$why){ if(!$ok){fwrite(STDERR,"FAIL: $why\n");exit(1);} }
assertCard(deltaCardNormalizeNumber('۶۰۳۷ ۹۹۱۵-۶۸۲۱ ۱۷۷۹')==='6037991568211779','Persian digit normalization');
assertCard(deltaCardNormalizeNumber('٥٠٢٢ ٢٩١٥ ٣٨٤٤ ٤٦٥٢')==='5022291538444652','Arabic digit normalization');
assertCard(deltaCardValidNumber('6037991568211779'),'16-digit card accepted');
assertCard(!deltaCardValidNumber('60379915682117791'),'too many card digits rejected');
assertCard(!deltaCardValidNumber('1234notreal567890'),'non-digit rejected');
$pool=deltaCardPoolDecode([
    1=>['number'=>'6037991568211779','holder'=>'صاحب کارت یک'],
    2=>['number'=>'5022291538444652','holder'=>'صاحب کارت دو'],
    11=>['number'=>'1234567890123456','holder'=>'خارج از سقف'],
    3=>['number'=>'1234','holder'=>'شماره اشتباه'],
]);
assertCard(count($pool)===2,'only valid slots');
assertCard($pool[2]['holder']==='صاحب کارت دو','owner per slot');
for($i=0;$i<30;$i++){
    $card=deltaCardPick($pool,'6037991568211779');
    assertCard($card['number']==='5022291538444652','rotate away from most recent card');
}
assertCard(deltaCardPick([])===null,'empty pool');
$ref=deltaCardParseReceiptRef('K4Example~153');
assertCard($ref['hash']==='K4Example' && $ref['id']===153,'receipt callback reference');
$ref=deltaCardParseReceiptRef('OldHash');
assertCard($ref['hash']==='OldHash' && $ref['id']===0,'legacy callback reference');
assertCard(deltaCardOriginStep('H1','awaitingPayChoice|payCustomWithCartToCartH1')==='payCustomWithCartToCartH1','custom purchase origin');
assertCard(deltaCardOriginStep('H1','payTextReceipt|H1|increaseWalletWithCartToCartH1')==='increaseWalletWithCartToCartH1','wallet origin from text receipt');
// The tracking code is rendered into the payment text, not only in a button.
$template="💳 <code>ACCOUNT-NUMBER</code>\n👤 HOLDER-NAME\n🔖 TRACKING-CODE";
$rendered=deltaCardReplaceInvoiceTokens($template,'6037991568211779','صاحب & کارت','ABC-123');
assertCard(strpos($rendered,'6037991568211779')!==false,'card number placeholder');
assertCard(strpos($rendered,'صاحب &amp; کارت')!==false,'holder escaped in HTML');
assertCard(strpos($rendered,'ABC-123')!==false,'tracking placeholder');
assertCard(strpos($rendered,'TRACKING-CODE')===false,'no literal tracking placeholder remains');
assertCard(strpos($rendered,'ACCOUNT-NUMBER')===false,'no literal account placeholder remains');
$legacy=deltaCardReplaceInvoiceTokens('💳 ACCOUNT-NUMBER — HOLDER-NAME','5022291538444652','صاحب کارت','OLD-789');
assertCard(strpos($legacy,'🔖 کد پیگیری: <code>OLD-789</code>')!==false,
    'pre-existing editable payment templates get a tracking code automatically');
assertCard(substr_count($rendered,'ABC-123')===1,'tracking appears exactly once');
// Regression check: the generated keyboard must keep the requested row order.
$configSource=file_get_contents(dirname(__DIR__).'/config.php');
$keyboardStart=strpos($configSource,'function getCopyPaymentButtons(');
$keyboardEnd=strpos($configSource,'function deltaPaymentOriginStepByHash(',$keyboardStart);
assertCard($keyboardStart!==false && $keyboardEnd!==false,'payment keyboard function available');
$keyboardSource=substr($configSource,$keyboardStart,$keyboardEnd-$keyboardStart);
$switchPos=strpos($keyboardSource,"['text'=>'🔄 تغییر شماره کارت'");
$copyPos=strpos($keyboardSource,'if(!empty($copyRow)) $rows[] = $copyRow;');
$receiptPos=strpos($keyboardSource,"['text' => '📷 عکس واریزی'");
assertCard($switchPos!==false && $copyPos!==false && $receiptPos!==false
    && $switchPos<$copyPos && $copyPos<$receiptPos,'switch / copy / receipt rows in requested order');
assertCard(strpos($keyboardSource,"['text' => '📩 پیامک واریزی'")!==false,
    'concise SMS button label');
assertCard(strpos($keyboardSource,"📷 ارسال عکس واریزی")===false &&
    strpos($keyboardSource,"📩 ارسال پیامک واریزی")===false,'remove ارسال from both buttons');
assertCard(strpos($keyboardSource,"['text' => '📷 عکس واریزی'") <
    strpos($keyboardSource,"['text' => '📩 پیامک واریزی'"),'photo followed by SMS in same row');
// The awaiting-payment-choice error must have an inline cancel button that exits.
$waitKeys=json_decode(deltaCardAwaitChoiceCancelKeys(),true);
assertCard(($waitKeys['inline_keyboard'][0][0]['text']??'')==='❌ لغو',
    'short cancel label below awaiting-payment error');
assertCard(($waitKeys['inline_keyboard'][0][0]['callback_data']??'')==='deltaCardReceiptCancel',
    'awaiting-payment cancel routes to existing flow-reset handler');
$botText=file_get_contents(dirname(__DIR__).'/bot.php');
$waitGuard=strpos($botText,"if(preg_match('/^awaitingPayChoice");
assertCard($waitGuard!==false,'awaiting-choice guard exists');
$waitBlock=substr($botText,$waitGuard,1600);
assertCard(strpos($waitBlock,'deltaCardAwaitChoiceCancelKeys()')!==false,
    'awaiting-choice error has an inline escape button');
assertCard(strpos($waitBlock,"=== 'mainMenu'")!==false,
    'original payment cancellation does not remain trapped');
echo "Random card-to-card payment rules: PASS\n";
