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
echo "Random card-to-card payment rules: PASS\n";
