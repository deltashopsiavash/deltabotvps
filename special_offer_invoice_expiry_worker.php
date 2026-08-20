<?php
// CLI-only one-shot worker for special-offer invoice expiration notices.
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$expiresAt=(int)($argv[1] ?? 0);
$hashId=(string)($argv[2] ?? '');
$botInstanceId=(int)($argv[3] ?? 0);
if($expiresAt<=0 || $hashId==='') exit(1);

$lockPath=sys_get_temp_dir().'/delta-special-invoice-'.sha1($botInstanceId.'|'.$hashId).'.lock';
$lock=@fopen($lockPath,'c');
if(!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) exit(0);

while(($remaining=$expiresAt-time())>0){
    sleep(min(30,$remaining));
}

if($botInstanceId>0) $_GET['bid']=$botInstanceId;
require_once __DIR__.'/baseInfo.php';
require_once __DIR__.'/config.php';

try{
    $stmt=$connection->prepare("SELECT `user_id`,`state`,`special_offer_id`,`special_offer_reserved_until`,`special_offer_expiry_notified`
                                FROM `pays` WHERE `hash_id`=? LIMIT 1");
    $stmt->bind_param('s',$hashId); $stmt->execute();
    $invoice=$stmt->get_result()->fetch_assoc(); $stmt->close();

    if($invoice && (int)$invoice['special_offer_id']>0
        && in_array((string)$invoice['state'],['pending','expired_special_offer'],true)
        && (int)$invoice['special_offer_expiry_notified']===0){
        $stmt=$connection->prepare("UPDATE `pays`
                                    SET `state`='expired_special_offer',`special_offer_expiry_notified`=1
                                    WHERE `hash_id`=? AND `special_offer_id`>0
                                      AND `special_offer_expiry_notified`=0
                                      AND `state` IN ('pending','expired_special_offer')");
        $stmt->bind_param('s',$hashId); $stmt->execute();
        $claimed=$stmt->affected_rows>0; $stmt->close();
        if($claimed){
            $isFiveMinute=(int)$invoice['special_offer_reserved_until']>0;
            $text=$isFiveMinute
                ? "⌛️ ۵ دقیقه شما تمام شد و خرید شما لغو شد.\n\nاز صفحه پیشنهاد امروز دوباره فاکتور بسازید."
                : "⌛️ زمان اعتبار فاکتور شما تمام شد و خرید شما لغو شد.\n\nاز صفحه پیشنهاد امروز دوباره فاکتور بسازید.";
            $keys=json_encode(['inline_keyboard'=>[
                [['text'=>'🔥 پیشنهاد امروز','callback_data'=>'specialOfferToday']],
                [['text'=>'↩️ بازگشت به منوی اصلی','callback_data'=>'mainMenu']]
            ]],JSON_UNESCAPED_UNICODE);
            bot('sendMessage',[
                'chat_id'=>(int)$invoice['user_id'],
                'text'=>$text,
                'reply_markup'=>$keys
            ]);
        }
    }
}catch(Throwable $e){
    error_log('special_offer_invoice_expiry_worker: '.$e->getMessage());
}

@flock($lock,LOCK_UN);
@fclose($lock);
