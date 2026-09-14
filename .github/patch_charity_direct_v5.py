from pathlib import Path

p = Path('charity_campaign.php')
s = p.read_text(encoding='utf-8')

# 1) Do not count direct charity card payments again as percentage-based purchases.
old = "AND p.`payment_channel`='card_to_card' AND p.`state`='approved' AND p.`payment_confirmed_at`>=?"
new = "AND p.`payment_channel`='card_to_card' AND p.`state`='approved' AND p.`type`<>'CHARITY_DIRECT' AND p.`payment_confirmed_at`>=?"
if old not in s:
    raise SystemExit('sync SQL target not found')
s = s.replace(old, new, 1)

# 2) Replace old wallet-based direct donation flow with card-to-card + receipt approval.
start = s.index("if (!function_exists('charityRenderDonateMenu')) {")
end = s.index("if (!function_exists('charityProofRows'))", start)
new_direct = r'''if (!function_exists('charityRenderDonateMenu')) {
    function charityRenderDonateMenu(){
        global $message_id;
        $c=charityRefreshCampaignState(charityGetCampaign());
        if(!$c||!charityIsCollecting()||!charityCampaignIsOpen($c)){charityRenderCampaign();return;}
        $txt="❤️ <b>کمک مستقیم به کمپین</b>\n\n💳 کمک مستقیم فقط از طریق <b>کارت‌به‌کارت</b> انجام می‌شود و هیچ مبلغی از کیف پول ربات شما کم نخواهد شد.\n\nمبلغ موردنظر را انتخاب کنید:";
        $rows=[
            [['text'=>'۵۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_50000'],['text'=>'۱۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_100000']],
            [['text'=>'۲۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_200000'],['text'=>'۵۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_500000']],
            [['text'=>'✍️ مبلغ دلخواه','callback_data'=>'charityDonateCustom']],
            [['text'=>'↩️ بازگشت','callback_data'=>'charityCampaign']]
        ];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}
if (!function_exists('charityDirectPaymentByHash')) {
    function charityDirectPaymentByHash($hash){
        global $connection;
        $hash=trim((string)$hash);if($hash==='')return null;
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? AND `type`='CHARITY_DIRECT' LIMIT 1");
        if(!$stmt)return null;$stmt->bind_param('s',$hash);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return $r?:null;
    }
}
if (!function_exists('charityCreateDirectCardPayment')) {
    function charityCreateDirectCardPayment($amount){
        global $connection,$from_id,$message_id,$paymentKeys;
        $amount=(int)$amount;$c=charityRefreshCampaignState(charityGetCampaign());
        if(!$c||!charityIsCollecting()||!charityCampaignIsOpen($c)){charityRenderCampaign();return;}
        if($amount<1000){alert('مبلغ معتبر نیست.',true);return;}
        try{$hash=bin2hex(random_bytes(12));}catch(Throwable $e){$hash=substr(sha1(uniqid('',true)),0,24);}
        $meta=json_encode(['campaign_id'=>(int)$c['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $now=time();
        $stmt=$connection->prepare("INSERT INTO `pays` (`hash_id`,`description`,`user_id`,`type`,`plan_id`,`volume`,`day`,`price`,`request_date`,`state`,`payment_channel`,`payment_confirmed_at`) VALUES (?, ?, ?, 'CHARITY_DIRECT', 0, 0, 0, ?, ?, 'pending', 'card_to_card', 0)");
        if(!$stmt){alert('خطا در ساخت پرداخت.',true);return;}
        $stmt->bind_param('ssiii',$hash,$meta,$from_id,$amount,$now);$ok=$stmt->execute();$stmt->close();
        if(!$ok){alert('خطا در ساخت پرداخت.',true);return;}
        setUser('charityDirectReceipt|'.$hash,'step');setUser('','temp');
        $bank=trim((string)($paymentKeys['bankAccount']??''));$holder=trim((string)($paymentKeys['holderName']??''));
        $bankHtml=htmlspecialchars($bank,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$holderHtml=htmlspecialchars($holder,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $txt="❤️ <b>کمک مستقیم به کمپین</b>\n\n💰 مبلغ کمک: <b>".number_format($amount)." تومان</b>\n\n💳 شماره کارت: <code>{$bankHtml}</code>\n👤 به نام: <b>{$holderHtml}</b>\n\n📸 پس از واریز، عکس رسید را همینجا ارسال کنید.\n\n✅ این پرداخت هیچ مبلغی از کیف پول ربات شما کم نمی‌کند.";
        $rows=[];$copy=[];$copy[]=['text'=>'📋 کپی مبلغ','copy_text'=>['text'=>(string)($amount*10)]];
        if($bank!=='')$copy[]=['text'=>'💳 کپی شماره کارت','copy_text'=>['text'=>$bank]];
        if($copy)$rows[]=$copy;
        $rows[]=[['text'=>'📷 ارسال رسید واریزی','callback_data'=>'charityDirectReceiptPrompt_'.$hash]];
        $rows[]=[['text'=>'❌ انصراف','callback_data'=>'charityDirectCancel_'.$hash]];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}
if (!function_exists('charityPromptDirectReceipt')) {
    function charityPromptDirectReceipt($hash){$pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}setUser('charityDirectReceipt|'.$hash,'step');sendMessage('📸 لطفاً فقط عکس رسید کارت‌به‌کارت را ارسال کنید.');}
}
if (!function_exists('charityHandleDirectReceipt')) {
    function charityHandleDirectReceipt($hash){
        global $connection,$from_id,$admin,$update,$userInfo,$first_name,$username;
        $pay=charityDirectPaymentByHash($hash);if(!$pay||(int)$pay['user_id']!==(int)$from_id){sendMessage('❌ پرداخت پیدا نشد.');setUser('none','step');return;}
        if(!in_array((string)$pay['state'],['pending','have_sent'],true)){sendMessage('این پرداخت قبلاً بررسی شده است.');setUser('none','step');return;}
        if(!isset($update->message->photo)){sendMessage('❌ لطفاً فقط عکس رسید کارت‌به‌کارت را ارسال کنید.');return;}
        $photos=$update->message->photo;$ph=end($photos);$fileId=(string)($ph->file_id??'');if($fileId===''){sendMessage('❌ عکس رسید دریافت نشد.');return;}
        $uid=(int)$pay['user_id'];$amount=(int)$pay['price'];$name=trim((string)($userInfo['name']??$first_name??''));$uname=trim((string)($userInfo['username']??$username??''));
        $nameHtml=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$userHtml=$uname!==''?'@'.htmlspecialchars(ltrim($uname,'@'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'-';
        $caption="❤️ <b>رسید کمک مستقیم کمپین</b>\n\n👤 کاربر: {$nameHtml}\n🆔 آیدی: <code>{$uid}</code>\n🔗 یوزرنیم: {$userHtml}\n💰 مبلغ: <b>".number_format($amount)." تومان</b>\n🧾 شناسه: <code>".htmlspecialchars($hash,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>";
        $kb=json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید کمک','callback_data'=>'charityDirectApprove_'.$hash],['text'=>'❌ رد رسید','callback_data'=>'charityDirectDecline_'.$hash]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res=bot('sendPhoto',['chat_id'=>$admin,'photo'=>$fileId,'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
        $msgId=0;if(is_object($res)&&isset($res->result->message_id))$msgId=(int)$res->result->message_id;elseif(is_array($res)&&isset($res['result']['message_id']))$msgId=(int)$res['result']['message_id'];
        $stmt=$connection->prepare("UPDATE `pays` SET `state`='have_sent',`message_id`=?,`chat_id`=? WHERE `id`=? AND `state` IN ('pending','have_sent')");
        if($stmt){$pid=(int)$pay['id'];$adm=(int)$admin;$stmt->bind_param('iii',$msgId,$adm,$pid);$stmt->execute();$stmt->close();}
        setUser('none','step');sendMessage('✅ رسید شما برای مدیر ارسال شد. پس از تأیید، مبلغ به کمک مستقیم کمپین اضافه می‌شود.');
    }
}
if (!function_exists('charityApproveDirectPayment')) {
    function charityApproveDirectPayment($hash){
        global $connection,$chat_id,$message_id;
        $pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}
        if((string)$pay['state']==='approved'){alert('این کمک قبلاً تأیید شده است.');return;}
        if((string)$pay['state']!=='have_sent'){alert('این پرداخت قابل تأیید نیست.',true);return;}
        $meta=json_decode((string)($pay['description']??''),true);$cid=(int)($meta['campaign_id']??0);if($cid<=0){alert('کمپین مربوطه پیدا نشد.',true);return;}
        $pid=(int)$pay['id'];$uid=(int)$pay['user_id'];$amount=(int)$pay['price'];$now=time();$token='card:'.$hash;
        $connection->begin_transaction();
        try{
            $stmt=$connection->prepare("UPDATE `pays` SET `state`='approved',`payment_channel`='card_to_card',`payment_confirmed_at`=? WHERE `id`=? AND `state`='have_sent'");
            if(!$stmt)throw new Exception('prepare failed');$stmt->bind_param('ii',$now,$pid);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();if($changed!==1)throw new Exception('already processed');
            $stmt=$connection->prepare("INSERT INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) VALUES (?,?,?,'direct',?,?,?,'confirmed',?)");
            if(!$stmt)throw new Exception('prepare failed');$stmt->bind_param('iiiiiss',$cid,$pid,$uid,$amount,$amount,$now,$token);$stmt->execute();$stmt->close();
            $connection->commit();
        }catch(Throwable $e){$connection->rollback();alert('خطا در ثبت کمک یا این پرداخت قبلاً بررسی شده است.',true);return;}
        bot('sendMessage',['chat_id'=>$uid,'text'=>'❤️ کمک مستقیم شما به مبلغ '.number_format($amount).' تومان تأیید و به کمپین اضافه شد. سپاس از همراهی شما.']);
        $done=json_encode(['inline_keyboard'=>[[['text'=>'✅ کمک تأیید شد','callback_data'=>'noop']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($chat_id)&&!empty($message_id))bot('editMessageReplyMarkup',['chat_id'=>$chat_id,'message_id'=>$message_id,'reply_markup'=>$done]);
        alert('کمک مستقیم تأیید و ثبت شد.');
    }
}
if (!function_exists('charityDeclineDirectPayment')) {
    function charityDeclineDirectPayment($hash){
        global $connection,$chat_id,$message_id;
        $pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}
        if(!in_array((string)$pay['state'],['pending','have_sent'],true)){alert('این پرداخت قبلاً بررسی شده است.');return;}
        $pid=(int)$pay['id'];$uid=(int)$pay['user_id'];$stmt=$connection->prepare("UPDATE `pays` SET `state`='rejected' WHERE `id`=? AND `state` IN ('pending','have_sent')");if($stmt){$stmt->bind_param('i',$pid);$stmt->execute();$stmt->close();}
        bot('sendMessage',['chat_id'=>$uid,'text'=>'❌ رسید کمک مستقیم شما تأیید نشد. در صورت نیاز با پشتیبانی در ارتباط باشید.']);
        $done=json_encode(['inline_keyboard'=>[[['text'=>'❌ رسید رد شد','callback_data'=>'noop']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($chat_id)&&!empty($message_id))bot('editMessageReplyMarkup',['chat_id'=>$chat_id,'message_id'=>$message_id,'reply_markup'=>$done]);
        alert('رسید رد شد.');
    }
}
if (!function_exists('charityCancelDirectPayment')) {
    function charityCancelDirectPayment($hash){global $connection;$pay=charityDirectPaymentByHash($hash);if($pay&&(string)$pay['state']==='pending'){$pid=(int)$pay['id'];$stmt=$connection->prepare("UPDATE `pays` SET `state`='cancelled' WHERE `id`=? AND `state`='pending'");if($stmt){$stmt->bind_param('i',$pid);$stmt->execute();$stmt->close();}}setUser('none','step');charityRenderCampaign();}
}
'''
s = s[:start] + new_direct + "\n\n" + s[end:]

# 3) Add manual amount adjustment helpers before admin keyboard.
marker = "if (!function_exists('charityAdminSettingsKeyboard')) {"
idx = s.index(marker)
manual_helpers = r'''if (!function_exists('charityAddManualAdjustment')) {
    function charityAddManualAdjustment($delta){
        global $connection;$delta=(int)$delta;if($delta===0)return 0;$c=charityGetCampaign();if(!$c)return 0;$cid=(int)$c['id'];$now=time();$base=abs($delta);
        $stmt=$connection->prepare("INSERT INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) VALUES (?,NULL,0,'manual',?,?,?,'confirmed',NULL)");
        if(!$stmt)return 0;$stmt->bind_param('iiii',$cid,$base,$delta,$now);$ok=$stmt->execute();$stmt->close();return $ok?$delta:0;
    }
}
if (!function_exists('charityAdminRenderAdjustMenu')) {
    function charityAdminRenderAdjustMenu(){
        global $message_id;$c=charityGetCampaign();$tot=$c?charityTotals($c):['total'=>0];$total=max(0,(int)($tot['total']??0));
        $txt="💰 <b>اصلاح مبلغ کمپین</b>\n\nمبلغ فعلی: <b>".number_format($total)." تومان</b>\n\nاز این بخش می‌توانید مبلغ نمایش‌داده‌شده را افزایش دهید، کاهش دهید یا صفر کنید. سوابق پرداخت‌های واقعی حذف نمی‌شوند.";
        $rows=[[['text'=>'➕ افزایش مبلغ','callback_data'=>'charityAdminAdjustAdd'],['text'=>'➖ کاهش مبلغ','callback_data'=>'charityAdminAdjustSubtract']],[['text'=>'🧹 صفر کردن مبلغ','callback_data'=>'charityAdminAdjustZero']],[['text'=>'↩️ تنظیمات کمپین','callback_data'=>'charityAdminSettings']]];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}
'''
s = s[:idx] + manual_helpers + "\n" + s[idx:]

# 4) Add adjustment button to settings keyboard.
old_keyboard = "[['text'=>'📊 درصد سهم: '.charityPercentText(charityConfiguredPercent()).'٪','callback_data'=>'charityAdminSetPercent']],[['text'=>'📝 ویرایش کامل متن صفحه کمپین','callback_data'=>'charityAdminSetTemplate']]"
new_keyboard = "[['text'=>'📊 درصد سهم: '.charityPercentText(charityConfiguredPercent()).'٪','callback_data'=>'charityAdminSetPercent']],[['text'=>'💰 اصلاح مبلغ جمع‌آوری‌شده','callback_data'=>'charityAdminAdjustMenu']],[['text'=>'📝 ویرایش کامل متن صفحه کمپین','callback_data'=>'charityAdminSetTemplate']]"
if old_keyboard not in s:
    raise SystemExit('admin keyboard target not found')
s = s.replace(old_keyboard,new_keyboard,1)

# 5) Update admin explanatory text.
old_text = "✅ فقط پرداخت‌های تأییدشده کارت‌به‌کارت محاسبه می‌شوند. خرید از موجودی قبلی کیف پول محاسبه نمی‌شود.\\n⏹ توقف جمع‌آوری دکمه را حذف نمی‌کند و مبلغ نهایی همچنان نمایش داده می‌شود."
new_text = "✅ خریدها فقط در صورت تأیید کارت‌به‌کارت محاسبه می‌شوند. خرید از موجودی قبلی کیف پول محاسبه نمی‌شود.\\n❤️ کمک مستقیم نیز فقط کارت‌به‌کارت است و پس از تأیید رسید، ۱۰۰٪ مبلغ به کمپین اضافه می‌شود؛ از کیف پول کاربر چیزی کم نمی‌شود.\\n⏹ توقف جمع‌آوری دکمه را حذف نمی‌کند و مبلغ نهایی همچنان نمایش داده می‌شود."
if old_text not in s:
    raise SystemExit('admin text target not found')
s = s.replace(old_text,new_text,1)

# 6) Replace public direct-donation routes.
route_start = s.index("if($charityData==='charityDonate'){charityRenderDonateMenu();exit;}")
route_end = s.index("\n\nif($charityIsAdmin){", route_start)
new_routes = r'''if($charityData==='charityDonate'){charityRenderDonateMenu();exit;}
if(preg_match('/^charityDonateAmount_(\d+)$/',$charityData,$m)){charityCreateDirectCardPayment((int)$m[1]);exit;}
if($charityData==='charityDonateCustom'){setUser('charityDonateCustom','step');sendMessage('✍️ مبلغ دلخواه کمک را به تومان بفرستید. این مبلغ فقط کارت‌به‌کارت خواهد شد.',$cancelKey??null);exit;}
if($charityStep==='charityDonateCustom'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<1000){sendMessage('❌ مبلغ معتبر نیست.');exit;}charityCreateDirectCardPayment((int)$n);exit;}
if(preg_match('/^charityDirectReceiptPrompt_([a-f0-9]+)$/i',$charityData,$m)){charityPromptDirectReceipt($m[1]);exit;}
if(preg_match('/^charityDirectCancel_([a-f0-9]+)$/i',$charityData,$m)){charityCancelDirectPayment($m[1]);exit;}
if(preg_match('/^charityDirectReceipt\|([a-f0-9]+)$/i',$charityStep,$m)){charityHandleDirectReceipt($m[1]);exit;}'''
s = s[:route_start] + new_routes + s[route_end:]

# 7) Add admin routes for direct receipt approval and manual adjustments.
needle = "    if($charityData==='charityAdminToggleButton'){charitySetSetting('CHARITY_BUTTON_VISIBLE',charityButtonVisible()?'0':'1');alert('وضعیت نمایش دکمه کمپین تغییر کرد.');charityAdminRenderSettings();exit;}\n"
if needle not in s:
    raise SystemExit('admin insertion target not found')
admin_extra = r'''    if(preg_match('/^charityDirectApprove_([a-f0-9]+)$/i',$charityData,$m)){charityApproveDirectPayment($m[1]);exit;}
    if(preg_match('/^charityDirectDecline_([a-f0-9]+)$/i',$charityData,$m)){charityDeclineDirectPayment($m[1]);exit;}
    if($charityData==='charityAdminAdjustMenu'){charityAdminRenderAdjustMenu();exit;}
    if($charityData==='charityAdminAdjustAdd'){setUser('charityAdminWaitAdjustAdd','step');sendMessage('➕ مبلغی که می‌خواهید به جمع کمپین اضافه شود را به تومان بفرستید.',$cancelKey??null);exit;}
    if($charityData==='charityAdminAdjustSubtract'){setUser('charityAdminWaitAdjustSubtract','step');sendMessage('➖ مبلغی که می‌خواهید از جمع کمپین کم شود را به تومان بفرستید.',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitAdjustAdd'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<=0){sendMessage('❌ مبلغ معتبر نیست.');exit;}if(!charityGetCampaign()){sendMessage('❌ هنوز کمپینی ساخته نشده است.');setUser('none','step');exit;}charityAddManualAdjustment((int)$n);setUser('none','step');sendMessage('✅ مبلغ به جمع کمپین اضافه شد.');charityAdminRenderAdjustMenu();exit;}
    if($charityStep==='charityAdminWaitAdjustSubtract'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<=0){sendMessage('❌ مبلغ معتبر نیست.');exit;}$c=charityGetCampaign();if(!$c){sendMessage('❌ هنوز کمپینی ساخته نشده است.');setUser('none','step');exit;}$tot=charityTotals($c);$cur=max(0,(int)($tot['total']??0));$take=min((int)$n,$cur);if($take>0)charityAddManualAdjustment(-$take);setUser('none','step');sendMessage('✅ '.number_format($take).' تومان از جمع کمپین کم شد.');charityAdminRenderAdjustMenu();exit;}
    if($charityData==='charityAdminAdjustZero'){$c=charityGetCampaign();$tot=$c?charityTotals($c):['total'=>0];$cur=max(0,(int)($tot['total']??0));$txt='⚠️ مبلغ فعلی '.number_format($cur).' تومان است. مطمئن هستید مبلغ نمایش‌داده‌شده صفر شود؟';$kb=json_encode(['inline_keyboard'=>[[['text'=>'✅ بله، صفر کن','callback_data'=>'charityAdminAdjustZeroConfirm']],[['text'=>'❌ انصراف','callback_data'=>'charityAdminAdjustMenu']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);smartSendOrEdit($message_id,$txt,$kb);exit;}
    if($charityData==='charityAdminAdjustZeroConfirm'){$c=charityGetCampaign();if($c){$tot=charityTotals($c);$cur=max(0,(int)($tot['total']??0));if($cur>0)charityAddManualAdjustment(-$cur);}alert('مبلغ کمپین صفر شد.');charityAdminRenderAdjustMenu();exit;}
'''
s = s.replace(needle, needle + admin_extra, 1)

p.write_text(s, encoding='utf-8')
print('patched charity_campaign.php')
