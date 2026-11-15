<?php
/**
 * Per-bot multi-card card-to-card payments.
 * A payment presentation is immutable; later card edits/removals never rewrite receipts.
 */
function deltaCardNormalizeNumber($raw) {
    $raw=strtr((string)$raw,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    return preg_replace('/[\s\-]+/u','',$raw);
}
function deltaCardValidNumber($raw) {
    return preg_match('/^[0-9]{16}$/D',deltaCardNormalizeNumber($raw))===1;
}
function deltaCardPoolDecode($raw) {
    $data=is_array($raw)?$raw:json_decode((string)$raw,true);
    if(!is_array($data)) return [];
    $out=[];
    foreach($data as $slot=>$card) {
        $slot=(int)$slot;
        if($slot<1 || $slot>10 || !is_array($card)) continue;
        $number=deltaCardNormalizeNumber($card['number']??'');
        $holder=trim((string)($card['holder']??''));
        if(!deltaCardValidNumber($number) || $holder==='' || mb_strlen($holder,'UTF-8')>190) continue;
        $out[$slot]=['number'=>$number,'holder'=>$holder];
    }
    ksort($out,SORT_NUMERIC);
    return $out;
}
function deltaCardPool() {
    $stored=getSettingValue('PAYMENT_CARD_POOL_V1',null);
    if($stored!==null) return deltaCardPoolDecode($stored);
    // Backward compatibility until the administrator saves the first pool.
    $legacy=getSettingValue('PAYMENT_KEYS','{}');
    $settings=json_decode((string)$legacy,true);
    $bank=(string)($settings['bankAccount']??'');
    $holder=trim((string)($settings['holderName']??''));
    $bank=strtr($bank,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
    $out=[];
    if($holder!=='' && preg_match_all('/(?<![0-9])([0-9]{16})(?![0-9])/', $bank,$matches)) {
        foreach(array_unique($matches[1]) as $n) {
            if(count($out)>=10) break;
            $out[count($out)+1]=['number'=>$n,'holder'=>$holder];
        }
    }
    return $out;
}
function deltaCardSavePool($cards) {
    return upsertSettingValue('PAYMENT_CARD_POOL_V1',json_encode(deltaCardPoolDecode($cards),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function deltaCardPoolCount() {
    return count(deltaCardPool());
}
function deltaCardPick($cards,$previous='') {
    $choices=array_values($cards);
    if(count($choices)>1) {
        $filtered=array_values(array_filter($choices,function($card)use($previous){
            return (string)$card['number'] !== (string)$previous;
        }));
        if($filtered) $choices=$filtered;
    }
    if(!$choices) return null;
    return $choices[random_int(0,count($choices)-1)];
}
function deltaCardAdminMarkup() {
    $pool=deltaCardPool(); $rows=[];
    for($i=1;$i<=10;$i++){
        if(isset($pool[$i])) {
            $c=$pool[$i];
            $label="💳 کارت $i: ".$c['number']." | ".mb_substr($c['holder'],0,26,'UTF-8');
        } else $label="➕ کارت $i: خالی";
        $rows[]=[['text'=>$label,'callback_data'=>'deltaCardSlot_'.$i]];
    }
    $rows[]=[['text'=>'↩️ بازگشت به درگاه و کانال','callback_data'=>'gateWays_Channels']];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
}
function deltaCardShowAdminMenu() {
    global $message_id;
    $msg="💳 <b>مدیریت کارت‌های کارت‌به‌کارت</b>\n\n".
        "تعداد کارت‌ها: <b>".deltaCardPoolCount()." از ۱۰</b>\n".
        "برای افزودن یا ویرایش، شماره ردیف را انتخاب کنید. هر شماره کارت، نام صاحب حساب خودش را دارد.\n".
        "در هر درخواست پرداخت، یک کارت به‌صورت تصادفی نمایش داده می‌شود.";
    smartSendOrEdit($message_id,$msg,deltaCardAdminMarkup(),'HTML');
}
function deltaCardAdminSlot($slot) {
    global $message_id;
    $pool=deltaCardPool();
    if(isset($pool[$slot])) {
        $card=$pool[$slot];
        $txt="💳 کارت شماره $slot\n\nشماره: <code>".htmlspecialchars($card['number'],ENT_QUOTES,'UTF-8').
            "</code>\nصاحب حساب: ".htmlspecialchars($card['holder'],ENT_QUOTES,'UTF-8');
        $rows=[
            [['text'=>'✏️ ویرایش کارت','callback_data'=>'deltaCardEdit_'.$slot]],
            [['text'=>'🗑 حذف کارت','callback_data'=>'deltaCardDeleteAsk_'.$slot]],
            [['text'=>'↩️ بازگشت','callback_data'=>'deltaCardMenu']]
        ];
    }else{
        $txt="💳 جایگاه کارت شماره $slot خالی است.";
        $rows=[[['text'=>'➕ ثبت کارت','callback_data'=>'deltaCardEdit_'.$slot]],
            [['text'=>'↩️ بازگشت','callback_data'=>'deltaCardMenu']]];
    }
    smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');
}
function deltaCardAdminCancelKeys() {
    return json_encode(['inline_keyboard'=>[[['text'=>'❌ انصراف','callback_data'=>'deltaCardMenu']]]],JSON_UNESCAPED_UNICODE);
}
function deltaCardHandle() {
    global $data,$text,$userInfo,$from_id,$admin,$update,$message_id,$buttonValues;
    $cb=(string)($data??''); $step=(string)($userInfo['step']??'');
    if($cb==='deltaCardReceiptCancel') {
        setUser('none','step'); setUser('','temp');
        sendMessage('❌ ارسال رسید لغو شد. برای ادامه، گزینه موردنظر را از منوی اصلی انتخاب کنید.',getMainKeys(),null);
        return true;
    }
    if(strpos($cb,'deltaCard')!==0 && strpos($step,'deltaCard')!==0) return false;
    $adminAllowed=(int)$from_id===(int)$admin || !empty($userInfo['isAdmin']);
    if(!$adminAllowed) { if(strpos($cb,'deltaCard')===0) answerQuery('دسترسی ندارید.',true); return true; }
    if($cb!=='' && strpos($cb,'deltaCard')!==0) {
        setUser('none','step'); setUser('','temp'); return false;
    }
    if(trim((string)$text)==='/start') {
        setUser('none','step'); setUser('','temp'); return false;
    }
    try {
        if($cb==='deltaCardMenu') {
            setUser('none','step'); setUser('','temp');
            deltaCardShowAdminMenu(); return true;
        }
        if(preg_match('/^deltaCardSlot_(10|[1-9])$/',$cb,$m)) {
            deltaCardAdminSlot((int)$m[1]); return true;
        }
        if(preg_match('/^deltaCardEdit_(10|[1-9])$/',$cb,$m)) {
            $slot=(int)$m[1];
            setUser('deltaCardNumber_'.$slot,'step'); setUser('','temp');
            sendMessage("💳 شماره ۱۶ رقمی کارت $slot را ارسال کنید.\n(اعداد فارسی یا انگلیسی پذیرفته می‌شوند.)",
                deltaCardAdminCancelKeys(),null); return true;
        }
        if(preg_match('/^deltaCardNumber_(10|[1-9])$/',$step,$m) && isset($update->message)) {
            $number=deltaCardNormalizeNumber($update->message->text??'');
            if(!deltaCardValidNumber($number)) {
                sendMessage('❌ شماره کارت باید دقیقاً ۱۶ رقم باشد. دوباره ارسال کنید.',deltaCardAdminCancelKeys(),null); return true;
            }
            foreach(deltaCardPool() as $slot=>$item) {
                if((int)$slot!==(int)$m[1] && $item['number']===$number) {
                    sendMessage('❌ این شماره کارت در یک جایگاه دیگر ثبت شده است.',deltaCardAdminCancelKeys(),null); return true;
                }
            }
            setUser($number,'temp'); setUser('deltaCardHolder_'.$m[1],'step');
            sendMessage("👤 نام صاحب این کارت را ارسال کنید.\n💳 <code>$number</code>",deltaCardAdminCancelKeys(),'HTML'); return true;
        }
        if(preg_match('/^deltaCardHolder_(10|[1-9])$/',$step,$m) && isset($update->message)) {
            $holder=trim((string)($update->message->text??''));
            $number=deltaCardNormalizeNumber((string)($userInfo['temp']??''));
            if(!deltaCardValidNumber($number)) { setUser('none','step'); sendMessage('⚠️ فرآیند ثبت کارت نامعتبر شد. دوباره شروع کنید.'); return true; }
            if($holder==='' || mb_strlen($holder,'UTF-8')>190) {
                sendMessage('❌ نام صاحب حساب باید متن غیرخالی و حداکثر ۱۹۰ کاراکتر باشد.',deltaCardAdminCancelKeys(),null); return true;
            }
            $cards=deltaCardPool(); $cards[(int)$m[1]]=['number'=>$number,'holder'=>$holder];
            deltaCardSavePool($cards);
            setUser('none','step'); setUser('','temp');
            sendMessage("✅ کارت شماره ".$m[1]." با نام صاحب حساب ذخیره شد.",null,null);
            deltaCardShowAdminMenu(); return true;
        }
        if(preg_match('/^deltaCardDeleteAsk_(10|[1-9])$/',$cb,$m)) {
            $slot=(int)$m[1];$cards=deltaCardPool();
            if(!isset($cards[$slot])){ deltaCardShowAdminMenu(); return true; }
            $txt="⚠️ از حذف کارت $slot مطمئن هستید؟\n\n".$cards[$slot]['number']."\n".$cards[$slot]['holder'].
                "\n\nاین کار فقط نمایش کارت در پرداخت‌های جدید را متوقف می‌کند؛ رسیدهای قبلی محفوظ‌اند.";
            smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>[
                [['text'=>'🗑 تأیید حذف','callback_data'=>'deltaCardDelete_'.$slot]],
                [['text'=>'❌ انصراف','callback_data'=>'deltaCardSlot_'.$slot]]
            ]],JSON_UNESCAPED_UNICODE));return true;
        }
        if(preg_match('/^deltaCardDelete_(10|[1-9])$/',$cb,$m)) {
            $cards=deltaCardPool();unset($cards[(int)$m[1]]);
            deltaCardSavePool($cards);
            sendMessage('✅ کارت از فهرست پرداخت‌های آینده حذف شد. سوابق فاکتورها باقی می‌مانند.',null,null);
            deltaCardShowAdminMenu();return true;
        }
        return true;
    }catch(Throwable $e){
        error_log('deltaCardHandle: '.$e->getMessage());
        sendMessage('❌ خطا در تنظیم کارت‌ها. لطفاً دوباره تلاش کنید.',null,null);
        return true;
    }
}
function deltaCardEnsureTable() {
    global $connection;
    static $ready=[];
    $dbRes=$connection->query('SELECT DATABASE() AS db');
    $db=$dbRes ? (string)($dbRes->fetch_assoc()['db']??'') : '';
    if(isset($ready[$db])) return true;
    $created=$connection->query("CREATE TABLE IF NOT EXISTS payment_card_displays (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        hash_id VARCHAR(128) NOT NULL,
        user_id BIGINT NOT NULL,
        card_number VARCHAR(16) NOT NULL,
        holder_name VARCHAR(190) NOT NULL,
        shown_at BIGINT UNSIGNED NOT NULL,
        KEY card_hash (hash_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if($created) $ready[$db]=true;
    return (bool)$created;
}
function deltaCardPayOwned($hash) {
    global $connection,$from_id;
    $hash=(string)$hash;
    if($hash==='' || strlen($hash)>128) return null;
    $st=$connection->prepare('SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 1');
    if(!$st) return null;
    $uid=(int)$from_id;$st->bind_param('si',$hash,$uid);
    $st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    return $row?:null;
}
function deltaCardChoosePresentation($hash,$uid) {
    global $connection;
    if(!deltaCardEnsureTable()) throw new RuntimeException('Cannot create payment card assignments table');
    $cards=deltaCardPool();
    if(!$cards) return null;
    $st=$connection->prepare('SELECT card_number FROM payment_card_displays WHERE hash_id=? ORDER BY id DESC LIMIT 1');
    $st->bind_param('s',$hash);$st->execute();
    $last=$st->get_result()->fetch_assoc();$st->close();
    $pick=deltaCardPick($cards,$last['card_number']??'');
    if(!$pick) return null;
    $number=$pick['number'];$holder=$pick['holder'];$now=time();
    $st=$connection->prepare('INSERT INTO payment_card_displays (hash_id,user_id,card_number,holder_name,shown_at) VALUES (?,?,?,?,?)');
    $st->bind_param('sissi',$hash,$uid,$number,$holder,$now);
    $st->execute();$id=(int)$connection->insert_id;$st->close();
    return ['id'=>$id,'number'=>$number,'holder'=>$holder];
}
function deltaCardDefaultText() {
    return "جهت خرید و یا افزایش موجودی، هزینه را به شماره کارت زیر واریز کنید 👇🏻\n\n".
        "====================\n".
        "🔰 <code>ACCOUNT-NUMBER</code>\n".
        "👤 HOLDER-NAME\n".
        "====================\n\n".
        "پس از واریز، در صورت امکان با دکمه ارسال عکس واریزی، رسید را بفرستید. اگر اینترنت ضعیف است، از دکمه ارسال پیامک واریزی استفاده کنید. ⚠️\n\n".
        "❌ این تراکنش تا یک ساعت اعتبار دارد و پس از آن امکان پرداخت این تراکنش وجود ندارد.\n".
        "‼️ امکان برداشت وجه از کیف پول نیست.\n".
        "‼️ مسئولیت واریز اشتباهی با شماست.\n".
        "💵 بعد از تأیید پرداخت توسط مدیر، کیف پول شما شارژ می‌شود و در صورت داشتن سفارش، انجام خواهد شد.";
}
function deltaCardRenderText($number,$holder) {
    $custom=trim((string)getSettingValue('MAINVALUE_card_to_card_text',''));
    // An old custom message may contain literal, now-invalid bank numbers.
    // Never display such a message in place of the selected card.
    $template=(strpos($custom,'ACCOUNT-NUMBER')!==false && strpos($custom,'HOLDER-NAME')!==false)
        ? $custom : deltaCardDefaultText();
    return str_replace(['ACCOUNT-NUMBER','HOLDER-NAME'],
        [htmlspecialchars((string)$number,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
         htmlspecialchars((string)$holder,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')],$template);
}
function deltaCardShowInvoice($pay) {
    global $from_id;
    if(!is_array($pay) || (int)($pay['user_id']??0)!==(int)$from_id) {
        sendMessage('❌ فاکتور متعلق به شما نیست یا پیدا نشد.',null,null);return false;
    }
    if(!in_array((string)($pay['state']??''),['pending'],true)) {
        sendMessage('❌ این فاکتور دیگر در وضعیت انتظار پرداخت نیست. لطفاً فاکتور جدید دریافت کنید.',null,null);return false;
    }
    try {
        $hash=(string)$pay['hash_id'];
        $card=deltaCardChoosePresentation($hash,(int)$from_id);
        if(!$card) { sendMessage('⚠️ شماره کارت فعالی برای پرداخت ثبت نشده است. لطفاً با پشتیبانی تماس بگیرید.',null,null);return false; }
        $kb=getCopyPaymentButtons((int)($pay['price']??0),$card['number'],'mainMenu',$hash,(int)$card['id']);
        $sent=sendMessage(deltaCardRenderText($card['number'],$card['holder']),$kb,'HTML');
        return is_object($sent) && !empty($sent->ok);
    }catch(Throwable $e) {
        error_log('deltaCardShowInvoice: '.$e->getMessage());
        sendMessage('❌ نمایش شماره کارت با مشکل مواجه شد. لطفاً دوباره تلاش کنید.',null,null);
        return false;
    }
}
function deltaCardParseReceiptRef($raw) {
    $raw=(string)$raw;
    if(preg_match('/^(.+)~([0-9]+)$/D',$raw,$m)) return ['hash'=>$m[1],'id'=>(int)$m[2]];
    return ['hash'=>$raw,'id'=>0];
}
function deltaCardOriginStep($hash,$storedStep) {
    $hash=(string)$hash;
    $step=(string)$storedStep;
    if(strpos($step,'awaitingPayChoice|')===0) $step=substr($step,strlen('awaitingPayChoice|'));
    if(preg_match('/^payTextReceipt\|([^|]+)\|(.*)$/D',$step,$m) && $m[1]===$hash) $step=$m[2];
    // Keep the original payment route for custom purchases, wallet top-ups and renewals.
    if($hash!=='' && str_ends_with($step,$hash) &&
        preg_match('/^(?:payCustomWithCartToCart|payWithCartToCart|increaseWalletWithCartToCart|payRenewWithCartToCart|payIncreaseDayWithCartToCart|payIncreaseWithCartToCart|pgRenewPayCart)/',$step)) return $step;
    return deltaPaymentOriginStepByHash($hash);
}
function deltaCardBindReceipt($hash,$id,$uid) {
    global $connection;
    if(!deltaCardEnsureTable()) return false;
    $pay=deltaCardPayOwned($hash);
    if(!$pay || (int)$pay['user_id']!==(int)$uid || ($pay['state']??'')!=='pending') return false;
    if($id>0) {
        $st=$connection->prepare('SELECT id FROM payment_card_displays WHERE id=? AND hash_id=? AND user_id=? LIMIT 1');
        $st->bind_param('isi',$id,$hash,$uid);
    }else{
        // Compatibility for pre-update messages with no presentation token.
        $st=$connection->prepare('SELECT id FROM payment_card_displays WHERE hash_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
        $st->bind_param('si',$hash,$uid);
    }
    $st->execute();$rec=$st->get_result()->fetch_assoc();$st->close();
    if(!$rec) return $id===0; // Accept already-shown pre-update payment messages.
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)(int)$rec['id']);
    return true;
}
function deltaCardAdminReceiptNote($hash,$parse='HTML') {
    global $connection;
    if(!deltaCardEnsureTable()) return '';
    $id=(int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1((string)$hash),'0');
    if($id>0){
        $st=$connection->prepare('SELECT card_number,holder_name FROM payment_card_displays WHERE id=? AND hash_id=? LIMIT 1');
        $st->bind_param('is',$id,$hash);
    }else{
        $st=$connection->prepare('SELECT card_number,holder_name FROM payment_card_displays WHERE hash_id=? ORDER BY id DESC LIMIT 1');
        $st->bind_param('s',$hash);
    }
    $st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    if(!$row) return '';
    $num=$row['card_number'];$holder=$row['holder_name'];
    if(strcasecmp((string)$parse,'HTML')===0 || strtolower((string)$parse)==='html') {
        return "\n\n💳 <b>شماره کارت نمایش‌داده‌شده به مشتری:</b> <code>".
            htmlspecialchars($num,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
            "👤 صاحب کارت: ".htmlspecialchars($holder,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    return "\n\n💳 کارت نمایش‌داده‌شده به مشتری: ".$num."\n👤 صاحب کارت: ".$holder;
}
function deltaCardAppendAdminReceipt($body,$hash,$parse='HTML',$photo=false) {
    $note=deltaCardAdminReceiptNote((string)$hash,$parse);
    if($note==='') return $body;
    // Telegram photo captions are restricted to 1024 characters (including entities).
    // Keep the existing report intact, and send the card note separately if needed.
    if($photo && mb_strlen(strip_tags((string)$body.$note),'UTF-8')>900) return $body;
    return (string)$body.$note;
}
function deltaCardReceiptCancelKeys() {
    return json_encode(['inline_keyboard'=>[
        [['text'=>'❌ لغو ارسال رسید','callback_data'=>'deltaCardReceiptCancel']]
    ]],JSON_UNESCAPED_UNICODE);
}
function deltaCardPhotoPrompt() {
    return (string)getSettingValue('MAINVALUE_payment_photo_prompt',
        "📷 لطفاً عکس رسید واریزی را ارسال کنید.\n\nجهت لغو روی دکمه زیر بزنید.");
}
function deltaCardSmsPrompt() {
    return (string)getSettingValue('MAINVALUE_payment_sms_prompt',
        "📩 لطفاً ساعت و تاریخ دقیق واریزی، شماره کارت مبدأ، شماره کارت مقصد و مبلغ واریزی را در قالب یک پیام ارسال کنید.\n\nجهت لغو روی دکمه زیر بزنید.");
}
