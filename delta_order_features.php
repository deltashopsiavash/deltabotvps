<?php
// Delta Shop extended order/payment features.

if(!function_exists('deltaTrackingCode')){
    function deltaTrackingCode($hash){
        $n=(int)sprintf('%u', crc32('delta-track|'.(string)$hash));
        return (string)(10000000 + ($n % 90000000));
    }
    function deltaTrackingLine($hash){
        return "🔖 کد پیگیری: <code>".deltaTrackingCode($hash)."</code>";
    }
    function deltaAppendTracking($text,$hash){
        return trim((string)$hash)==='' ? $text : rtrim((string)$text)."\n\n".deltaTrackingLine($hash);
    }
}
if(!function_exists('deltaDiscountOwner')){
    function deltaDiscountOwner($code){ return (int)getSettingValue('DISCOUNT_OWNER_'.strtoupper(trim((string)$code)),'0'); }
    function deltaDiscountAllowedForUser($code,$uid){ $owner=deltaDiscountOwner($code); return $owner<=0 || $owner===(int)$uid; }
    function deltaSetDiscountOwner($code,$uid){ return upsertSettingValue('DISCOUNT_OWNER_'.strtoupper(trim((string)$code)),(string)(int)$uid); }
}
if(!function_exists('deltaForceAutoApprove')){
    function deltaForceAutoApprove($uid){ return getSettingValue('USER_FORCE_AUTOAPPROVE_'.(int)$uid,'0')==='1'; }
    function deltaSetForceAutoApprove($uid,$state){ return upsertSettingValue('USER_FORCE_AUTOAPPROVE_'.(int)$uid,$state?'1':'0'); }
    function deltaAutoApproveFrom(){ return (int)getSettingValue('AUTOAPPROVE_FROM_TS','0'); }
    function deltaResetAutoApproveFrom(){ $now=time(); upsertSettingValue('AUTOAPPROVE_FROM_TS',(string)$now); return $now; }
}
if(!function_exists('deltaUsdtRateToman')){
    function deltaUsdtRateToman(){
        static $cached=null,$at=0;
        if($cached!==null && time()-$at<15) return $cached;
        $at=time();
        $ch=curl_init('https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>7,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>'DeltaBot/1.0']);
        $raw=curl_exec($ch); curl_close($ch);
        if($raw){
            $j=json_decode($raw,true);
            if(is_array($j) && isset($j['stats']['usdt-rls'])){
                $s=$j['stats']['usdt-rls'];
                foreach(['latest','bestSell','bestBuy','mark'] as $k){
                    if(isset($s[$k]) && is_numeric($s[$k]) && (float)$s[$k]>1000){
                        $cached=(int)round(((float)$s[$k])/10);
                        if($cached>0) return $cached;
                    }
                }
            }
        }
        $manual=(int)getSettingValue('USDT_FALLBACK_RATE_TOMAN','0');
        return $manual>0?$manual:0;
    }
}
if(!function_exists('deltaUsdtPayText')){
    function deltaUsdtPayText($pay){
        global $paymentKeys;
        $price=(int)($pay['price']??0); $rate=deltaUsdtRateToman();
        if($rate<=0) return null;
        $amountText=number_format($price/$rate,4,'.','');
        $wallet=trim((string)($paymentKeys['usdtwallet']??''));
        $track=deltaTrackingCode($pay['hash_id']??'');
        return "✅ پرداخت ارزی شما آماده‌ست♡\n\n".
            "💰 مبلغ: <b>".number_format($price)." تومان</b>\n".
            "🪙 ارز پرداخت: <b>USDT BEP20</b>\n".
            "💲 مبلغ قابل پرداخت: <b>{$amountText} USDT</b>\n\n".
            "⚠️ کارمزد صرافی بر عهده شماست؛ واریزی خالص شما باید عدد بالا باشد لطفاً مبلغ فاکتور را دقیقاً و به‌صورت کامل به آدرس کیف پول واریز کنید.\n\n".
            "📋 آدرس پرداخت:\n<code>".htmlspecialchars($wallet,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n\n".
            "⚠️ بعد از واریز تا ثبت و نشستن ارز صبر کنید.\n\n".
            "📸 پس از تأیید تراکنش، عکس رسید را ارسال کنید و هش تراکنش را در کپشن همان عکس بنویسید.\n\n".
            "🔖 کد پیگیری: <code>{$track}</code>\n".
            "📊 نرخ تبدیل: <b>".number_format($rate)." تومان</b>\n\n".
            "⚠️ توجه:\n💳 پرداخت فقط با USDT BEP20 روی شبکه BSC (BEP20) انجام شود.\n".
            "📌 مبلغ باید دقیقاً مطابق فاکتور واریز شود.\n".
            "❌ در صورت واریز مبلغ اشتباه یا ارسال از شبکه دیگر، پرداخت تأیید نمی‌شود.\n\n".
            "⏰ این فاکتور ارزی تا 30 دقیقه معتبر است.";
    }
}
if(!function_exists('deltaPendingOrdersKeyboard')){
    function deltaPendingOrdersKeyboard($uid){
        global $connection,$buttonValues;
        $uid=(int)$uid;
        $stmt=$connection->prepare("SELECT hash_id,type,price,request_date FROM pays WHERE user_id=? AND state IN ('have_sent','need_admin') ORDER BY id DESC LIMIT 20");
        $stmt->bind_param('i',$uid); $stmt->execute(); $res=$stmt->get_result(); $stmt->close();
        $rows=[];
        while($p=$res->fetch_assoc()) $rows[]=[['text'=>'سفارش :'.deltaTrackingCode($p['hash_id']),'callback_data'=>'deltaPendingView_'.$p['hash_id']]];
        if(!$rows) $rows[]=[['text'=>'سفارش در حال انتظاری ندارید','callback_data'=>'deltach']];
        $rows[]=[['text'=>$buttonValues['back_to_main']??'بازگشت','callback_data'=>'mainMenu']];
        return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
    }
}
if(!function_exists('deltaReceiptCallbacks')){
    function deltaReceiptCallbacks($pay){
        $hash=(string)$pay['hash_id']; $uid=(int)$pay['user_id']; $type=(string)$pay['type'];
        if($type==='INCREASE_WALLET') return ['approvePayment'.$hash,'decPayment'.$hash];
        if($type==='RENEW_SCONFIG') return ['approveRenewAcc'.$hash,'decRenewAcc'.$hash];
        if(strpos($type,'INCREASE_DAY_')===0) return ['approveIncreaseDay'.$hash,'decIncreaseDay'.$hash];
        if(strpos($type,'INCREASE_VOLUME_')===0) return ['approveIncreaseVolume'.$hash,'decIncreaseVolume'.$hash];
        if(strpos($type,'PG_RENEW_')===0) return ['approvePgRenew'.$hash,'decPgRenew'.$hash];
        return ['accept'.$hash,'declineOffer'.$hash.'_'.$uid];
    }
}
if(!function_exists('deltaFeatureHandleRequest')){
    function deltaFeatureHandleRequest(){
        global $connection,$data,$text,$from_id,$admin,$userInfo,$message_id,$buttonValues,$cancelKey,$removeKeyboard,$update,$fileid,$paymentKeys,$botState;
        $isAdmin=((int)$from_id===(int)$admin)||!empty($userInfo['isAdmin']);

        if($isAdmin && $data==='deltaAutoApproveReset'){
            $t=deltaResetAutoApproveFrom();
            alert('از این لحظه فقط رسیدهای جدید وارد تأیید خودکار می‌شوند.');
            smartSendOrEdit($message_id,'مبدأ تأیید خودکار: '.jdate('Y/m/d H:i:s',$t),getBotSettingKeys());
            exit;
        }
        if($isAdmin && preg_match('/^deltaForceAutoAsk_(\d+)$/',(string)$data,$m)){
            $uid=(int)$m[1]; $state=deltaForceAutoApprove($uid);
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ آره؛ همیشه خودکار تأیید شود','callback_data'=>'deltaForceAutoSet_'.$uid.'_1']],
                [['text'=>'❌ نه؛ حالت عادی','callback_data'=>'deltaForceAutoSet_'.$uid.'_0']],
                [['text'=>'بازگشت','callback_data'=>'uRefresh'.$uid]]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"آیا می‌خواهید این کاربر همیشه خودکار رسیدش تأیید شود؟\n\nوضعیت فعلی: ".($state?'فعال ✅':'غیرفعال ❌'),$kb);
            exit;
        }
        if($isAdmin && preg_match('/^deltaForceAutoSet_(\d+)_(0|1)$/',(string)$data,$m)){
            deltaSetForceAutoApprove((int)$m[1],$m[2]==='1');
            alert($m[2]==='1'?'استثنای تأیید خودکار فعال شد':'استثنا لغو شد');
            smartSendOrEdit($message_id,renderUserInfoTitle((int)$m[1]),getUserInfoKeys((int)$m[1]),'HTML');
            exit;
        }
        if($isAdmin && $data==='deltaForceAutoUsers'){
            $res=$connection->query("SELECT type,value FROM setting WHERE type LIKE 'USER_FORCE_AUTOAPPROVE_%' AND value='1' ORDER BY id DESC");
            $rows=[]; if($res) while($r=$res->fetch_assoc()){ $uid=(int)str_replace('USER_FORCE_AUTOAPPROVE_','',$r['type']); $rows[]=[['text'=>'❌ لغو '.$uid,'callback_data'=>'deltaForceAutoSet_'.$uid.'_0'],['text'=>(string)$uid,'callback_data'=>'uRefresh'.$uid]]; }
            if(!$rows) $rows[]=[['text'=>'کاربری استثنا نشده','callback_data'=>'deltach']];
            $rows[]=[['text'=>'بازگشت','callback_data'=>'generalSettings']];
            smartSendOrEdit($message_id,'👥 کاربرهای استثنا شده تأیید خودکار',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
            exit;
        }

        if($isAdmin && $data==='changePaymentKeysusdtwallet'){
            setUser('deltaSetUsdtWallet'); sendMessage('آدرس کیف پول USDT روی شبکه BSC (BEP20) را ارسال کنید.',$cancelKey); exit;
        }
        if($isAdmin && ($userInfo['step']??'')==='deltaSetUsdtWallet' && $text!=($buttonValues['cancel']??'')){
            $stmt=$connection->prepare("SELECT value FROM setting WHERE type='PAYMENT_KEYS' LIMIT 1"); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
            $pk=json_decode((string)($r['value']??'{}'),true); if(!is_array($pk)) $pk=[];
            $pk['usdtwallet']=trim((string)$text);
            upsertSettingValue('PAYMENT_KEYS',json_encode($pk,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            setUser(); sendMessage('✅ آدرس کیف پول USDT ذخیره شد.',$removeKeyboard); sendMessage('تنظیمات درگاه و کانال',getGateWaysKeys()); exit;
        }

        if(preg_match('/^payWithUsdt(.+)$/',(string)$data,$m)){
            $hash=$m[1];
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay){ alert('فاکتور پیدا نشد.',true); exit; }
            if(($botState['usdtState']??'off')!=='on' || empty($paymentKeys['usdtwallet'])){ alert('پرداخت ارزی در حال حاضر فعال نیست.',true); exit; }
            $txt=deltaUsdtPayText($pay); if($txt===null){ alert('دریافت نرخ لحظه‌ای تتر ناموفق بود؛ دوباره تلاش کنید.',true); exit; }
            $rate=deltaUsdtRateToman();
            upsertSettingValue('USDT_INVOICE_'.$hash,json_encode(['rate'=>$rate,'created_at'=>time(),'expires_at'=>time()+1800],JSON_UNESCAPED_UNICODE));
            setUser('deltaUsdtReceipt_'.$hash);
            smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>[[['text'=>'↩️ بازگشت','callback_data'=>'mainMenu']]]],JSON_UNESCAPED_UNICODE),'HTML');
            exit;
        }
        if(preg_match('/^deltaUsdtReceipt_(.+)$/',(string)($userInfo['step']??''),$m)){
            $hash=$m[1];
            if(!isset($update->message->photo)){ sendMessage('📸 فقط عکس رسید را ارسال کنید و هش تراکنش را در کپشن همان عکس بنویسید.'); exit; }
            $tx=trim((string)($update->message->caption??''));
            if($tx===''){ sendMessage('❌ هش تراکنش داخل کپشن عکس نیست. عکس را دوباره با هش تراکنش در کپشن ارسال کنید.'); exit; }
            $meta=json_decode((string)getSettingValue('USDT_INVOICE_'.$hash,'{}'),true);
            if(!is_array($meta) || (int)($meta['expires_at']??0)<time()){ sendMessage('⏰ اعتبار ۳۰ دقیقه‌ای فاکتور ارزی تمام شده است. دوباره فاکتور ارزی بسازید.'); setUser(); exit; }
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay){ setUser(); exit; }
            $rate=(int)($meta['rate']??0); $usdt=$rate>0?((int)$pay['price']/$rate):0;
            $name=htmlspecialchars((string)($userInfo['name']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $uname=htmlspecialchars((string)($userInfo['username']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $msg="💵 رسید پرداخت ارزی (USDT BEP20)\n\n👤 آیدی: <code>{$from_id}</code>\n👨‍💼 نام: {$name}\n⚡️ نام کاربری: {$uname}\n💰 مبلغ سرویس: ".number_format((int)$pay['price'])." تومان\n📊 نرخ تتر: ".number_format($rate)." تومان\n💲 مبلغ فاکتور: ".number_format($usdt,4,'.','')." USDT\n🧾 هش تراکنش:\n<code>".htmlspecialchars($tx,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".deltaTrackingLine($hash);
            [$ok,$no]=deltaReceiptCallbacks($pay); $kb=getReceiptAdminKeyboard($ok,$no,$from_id);
            $res=sendPhotoToAdmins($fileid,$msg,$kb,'HTML'); $mid=(int)($res->result->message_id??0);
            $stmt=$connection->prepare("UPDATE pays SET state='have_sent',message_id=?,chat_id=? WHERE hash_id=?"); $stmt->bind_param('iis',$mid,$admin,$hash); $stmt->execute(); $stmt->close();
            sendMessage("✅ رسید ارزی شما ثبت شد و برای مدیریت ارسال شد.\n\n".deltaTrackingLine($hash),$removeKeyboard,'HTML'); setUser(); exit;
        }

        if($data==='deltaPendingOrders'){ smartSendOrEdit($message_id,'⏳ سفارش‌های در حال انتظار شما',deltaPendingOrdersKeyboard($from_id)); exit; }
        if(preg_match('/^deltaPendingView_(.+)$/',(string)$data,$m)){
            $hash=$m[1];
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin') LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $p=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$p){ alert('این سفارش دیگر در انتظار نیست.',true); exit; }
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'❌ لغو سفارش','callback_data'=>'deltaPendingCancel_'.$hash],['text'=>'🔁 ارسال مجدد','callback_data'=>'deltaPendingResend_'.$hash]],
                [['text'=>'بازگشت','callback_data'=>'deltaPendingOrders']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"⏳ سفارش ".deltaTrackingCode($hash)." در حال انتظار تأیید مدیریت است.\n\n💰 مبلغ: ".number_format((int)$p['price'])." تومان\n".deltaTrackingLine($hash),$kb,'HTML'); exit;
        }
        if(preg_match('/^deltaPendingCancel_(.+)$/',(string)$data,$m)){
            $hash=$m[1];
            $stmt=$connection->prepare("SELECT message_id,chat_id FROM pays WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin') LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $p=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$p){ alert('سفارش قابل لغو نیست.',true); exit; }
            $stmt=$connection->prepare("UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin')"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $stmt->close();
            if(!empty($p['message_id'])) @editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ لغو شده توسط کاربر','callback_data'=>'deltach']]]],JSON_UNESCAPED_UNICODE),(int)$p['message_id'],(int)($p['chat_id']?:$admin));
            sendToAdmins("❌ سفارش <code>".deltaTrackingCode($hash)."</code> توسط کاربر <code>{$from_id}</code> لغو شد.",null,'HTML');
            smartSendOrEdit($message_id,'✅ سفارش لغو شد.',deltaPendingOrdersKeyboard($from_id)); exit;
        }
        if(preg_match('/^deltaPendingResend_(.+)$/',(string)$data,$m)){
            $hash=$m[1]; $key='PENDING_RESEND_'.$from_id.'_'.sha1($hash); $last=(int)getSettingValue($key,'0');
            if(time()-$last<3600){ alert('ارسال مجدد هر یک ساعت یک بار امکان‌پذیر است.',true); exit; }
            $stmt=$connection->prepare("SELECT id FROM pays WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin') LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $ok=$stmt->get_result()->num_rows>0; $stmt->close();
            if(!$ok){ alert('این سفارش دیگر در انتظار نیست.',true); exit; }
            upsertSettingValue($key,(string)time());
            sendToAdmins("🔔 سفارش <code>".deltaTrackingCode($hash)."</code> در حال انتظار است؛ جهت تأیید آن اقدام نمایید.\n👤 کاربر: <code>{$from_id}</code>",null,'HTML');
            alert('یادآوری برای مدیریت ارسال شد.'); exit;
        }
    }
}
