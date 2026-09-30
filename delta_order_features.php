<?php
// Delta Shop extended order/payment features.

if(!function_exists('deltaTrackingCode')){
    function deltaTrackingCode($hash){
        global $connection;
        $hash=(string)$hash;
        // For normal invoices use the database payment ID and a bijective
        // permutation over 0..89,999,999. This keeps codes 8 digits, non-sequential
        // looking, and collision-free while pay IDs stay within that range.
        if($hash!=='' && isset($connection) && $connection){
            $stmt=$connection->prepare("SELECT id FROM pays WHERE hash_id=? LIMIT 1");
            if($stmt){
                $stmt->bind_param('s',$hash);
                $stmt->execute();
                $row=$stmt->get_result()->fetch_assoc();
                $stmt->close();
                $id=(int)($row['id']??0);
                if($id>0 && $id<90000000){
                    $mixed=(($id * 32452843) + 27182818) % 90000000;
                    return (string)(10000000 + $mixed);
                }
            }
        }
        // Defensive fallback for non-persisted/legacy hashes.
        $n=(int)sprintf('%u', crc32('delta-track|'.$hash));
        return (string)(10000000 + ($n % 90000000));
    }
    function deltaTrackingLine($hash){
        return "🔖 کد پیگیری: <code>".deltaTrackingCode($hash)."</code>";
    }
    function deltaTrackingIdFromCode($code){
        if(!preg_match('/^[1-9][0-9]{7}$/',(string)$code)) return 0;
        $mixed=((int)$code-10000000-27182818+90000000)%90000000;
        return ($mixed*58880707)%90000000;
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
    function deltaAutoApprovePolicy($uid){
        $uid=(int)$uid;
        $policy=getSettingValue('USER_AUTOAPPROVE_POLICY_'.$uid,'');
        if(in_array($policy,['always','never','normal'],true)) return $policy;
        if(getSettingValue('USER_FORCE_AUTOAPPROVE_'.$uid,'0')==='1') return 'always';
        if(getSettingValue('USER_NO_AUTOAPPROVE_'.$uid,'0')==='1') return 'never';
        return 'normal';
    }
    function deltaSetAutoApprovePolicy($uid,$policy){
        if(!in_array($policy,['always','never','normal'],true)) return false;
        $uid=(int)$uid;
        upsertSettingValue('USER_AUTOAPPROVE_POLICY_'.$uid,$policy);
        upsertSettingValue('USER_FORCE_AUTOAPPROVE_'.$uid,$policy==='always'?'1':'0');
        upsertSettingValue('USER_NO_AUTOAPPROVE_'.$uid,$policy==='never'?'1':'0');
        if($policy==='always') upsertSettingValue('USER_FORCE_AUTOAPPROVE_FROM_'.$uid,(string)time());
        return true;
    }
    function deltaForceAutoApprove($uid){ return deltaAutoApprovePolicy($uid)==='always'; }
    function deltaSetForceAutoApprove($uid,$state){
        return deltaSetAutoApprovePolicy($uid,$state?'always':'normal');
    }
    function deltaForceAutoApproveFrom($uid){ return (int)getSettingValue('USER_FORCE_AUTOAPPROVE_FROM_'.(int)$uid,'0'); }
    function deltaAutoApproveFrom(){ return (int)getSettingValue('AUTOAPPROVE_FROM_TS','0'); }
    function deltaResetAutoApproveFrom(){ $now=time(); upsertSettingValue('AUTOAPPROVE_FROM_TS',(string)$now); return $now; }
    function deltaReceiptMarkerKey($hash){ return 'RECEIPT_AT_'.sha1((string)$hash); }
    function deltaMarkReceiptSubmitted($hash,$at=null){
        $hash=trim((string)$hash);
        if($hash==='') return false;
        $at=$at===null?time():(int)$at;
        return upsertSettingValue(deltaReceiptMarkerKey($hash),(string)$at);
    }
    function deltaReceiptSubmittedAt($hash,$fallback=0){
        return (int)getSettingValue(deltaReceiptMarkerKey($hash),(string)(int)$fallback);
    }
    function deltaReceiptHasSubmissionMarker($hash){
        return (int)getSettingValue(deltaReceiptMarkerKey($hash),'0')>0;
    }
}
if(!function_exists('deltaRememberReceiptAdminMessage')){
    function deltaReceiptAdminMessagesKey($hash){ return 'RECEIPT_ADMIN_MSG_'.sha1((string)$hash); }
    function deltaRememberReceiptAdminMessage($hash,$adminId,$response){
        $messageId=(int)($response->result->message_id??0);
        if(empty($response->ok) || $messageId<=0 || (int)$adminId===0) return false;
        $key=deltaReceiptAdminMessagesKey($hash);
        $messages=json_decode((string)getSettingValue($key,'{}'),true);
        if(!is_array($messages)) $messages=[];
        $messages[(string)(int)$adminId]=$messageId;
        return upsertSettingValue($key,json_encode($messages,JSON_UNESCAPED_UNICODE));
    }
    function deltaReceiptAdminMessageTargets($pay){
        $messages=json_decode((string)getSettingValue(deltaReceiptAdminMessagesKey($pay['hash_id']??''),'{}'),true);
        if(!is_array($messages)) $messages=[];
        $chatId=(int)($pay['chat_id']??0);
        $messageId=(int)($pay['message_id']??0);
        if($chatId!==0 && $messageId>0 && !isset($messages[(string)$chatId])) $messages[(string)$chatId]=$messageId;
        $targets=[];
        foreach($messages as $chat=>$message){
            if((int)$chat!==0 && (int)$message>0) $targets[]=[(int)$chat,(int)$message];
        }
        return $targets;
    }
    function deltaAutoApprovedReceiptKeyboard($uid){
        return json_encode(['inline_keyboard'=>[
            [['text'=>'✅ خودکار تأیید شد','callback_data'=>'deltach']],
            [['text'=>'👤 مشخصات کاربر','callback_data'=>'receiptUserInfo_'.(int)$uid]]
        ]],JSON_UNESCAPED_UNICODE);
    }
    function deltaSyncAutoApprovedReceiptMessages($pay){
        $keys=deltaAutoApprovedReceiptKeyboard($pay['user_id']??0);
        foreach(deltaReceiptAdminMessageTargets($pay) as [$chat,$message]) editKeys($keys,$message,$chat);
    }
}
if(!function_exists('deltaUsdtRateToman')){
    function deltaParseUsdtRate($j,$source){
        if(!is_array($j)) return 0;
        if($source==='nobitex-stats'){
            if(($j['status']??'')!=='ok') return 0;
            $stats=$j['stats']['usdt-rls']??$j['stats']['USDT-RLS']??[];
            $rate=$stats['bestSell']??$stats['latest']??0;
            return is_numeric($rate) && (float)$rate>10000 ? (int)ceil((float)$rate/10) : 0;
        }
        if($source==='nobitex-book'){
            if(($j['status']??'')!=='ok') return 0;
            $updated=(int)($j['lastUpdate']??0);
            if($updated>0 && time()-(int)($updated/1000)>180) return 0;
            $rate=$j['asks'][0][0]??$j['lastTradePrice']??0;
            return is_numeric($rate) && (float)$rate>10000 ? (int)ceil((float)$rate/10) : 0;
        }
        if($source==='wallex'){
            if(empty($j['success'])) return 0;
            $rate=$j['result']['symbols']['USDTTMN']['stats']['askPrice']??0;
            return is_numeric($rate) && (float)$rate>1000 ? (int)ceil((float)$rate) : 0;
        }
        return 0;
    }
    function deltaUsdtRateToman(){
        static $cached=null,$at=0;
        if($cached!==null && time()-$at<15) return $cached;
        $at=time();
        $sources=[
            ['https://api.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls','nobitex-stats'],
            ['https://api.nobitex.ir/v3/orderbook/USDTIRT','nobitex-book'],
            ['https://api.wallex.ir/v1/markets','wallex']
        ];
        foreach($sources as [$url,$source]){
            $ch=curl_init($url);
            if(!$ch) continue;
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>6,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Mozilla/5.0 DeltaBot/1.0',CURLOPT_HTTPHEADER=>['Accept: application/json']]);
            $raw=curl_exec($ch);
            $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
            $error=curl_error($ch);
            curl_close($ch);
            $rate=$status===200 && is_string($raw) ? deltaParseUsdtRate(json_decode($raw,true),$source) : 0;
            if($rate>0){ $cached=$rate; return $rate; }
            error_log('USDT quote '.$source.' failed: HTTP '.$status.' '.substr($error,0,120));
        }
        return 0;
    }
}
if(!function_exists('deltaUsdtPayText')){
    function deltaIsUsdtInvoice($hash){
        return getSettingValue('USDT_INVOICE_'.(string)$hash,null)!==null;
    }
    function deltaUsdtAmount($price,$rate){
        // Round upwards: the net amount received must cover the invoice.
        return number_format(ceil(((int)$price/(int)$rate)*10000)/10000,4,'.','');
    }
    function deltaUsdtPayText($pay,$quote){
        global $paymentKeys;
        $price=(int)($pay['price']??0); $rate=(int)($quote['rate']??0);
        if($rate<=0 || $price<=0) return null;
        $amountText=(string)$quote['amount'];
        $wallet=trim((string)($quote['wallet']??$paymentKeys['usdtwallet']??''));
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
if(!function_exists('deltaQrBackgroundPath')){
    function deltaQrBackgroundPath($botInstanceId=0){
        $base=__DIR__ . '/settings/';
        $main=$base.'qrcodes/qr_main.jpg';
        $botInstanceId=(int)$botInstanceId;
        if($botInstanceId>0){
            $custom=$base.'qrcodes/qr_rb'.$botInstanceId.'.jpg';
            if(is_file($custom)) return $custom;
        }
        return is_file($main)?$main:$base.'QRCode.jpg';
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
        if($type==='RENEW_ACCOUNT') return ['approveRenewAcc'.$hash,'decRenewAcc'.$hash];
        if($type==='RENEW_SCONFIG') return ['accept'.$hash,'declineOffer'.$hash.'_'.$uid];
        if(strpos($type,'INCREASE_DAY_')===0) return ['approveIncreaseDay'.$hash,'decIncreaseDay'.$hash];
        if(strpos($type,'INCREASE_VOLUME_')===0) return ['approveIncreaseVolume'.$hash,'decIncreaseVolume'.$hash];
        if(strpos($type,'PG_RENEW_')===0) return ['approvePgRenew'.$hash,'decPgRenew'.$hash];
        return ['accept'.$hash,'declineOffer'.$hash.'_'.$uid];
    }
}
if(!function_exists('deltaOrderDetails')){
    function deltaOrderDetails($pay){
        global $connection;
        $type=(string)($pay['type']??'');
        $lines=[];
        $label='سفارش';
        if($type==='INCREASE_WALLET') $label='شارژ حساب';
        elseif($type==='BUY_SUB') $label='خرید سرویس';
        elseif($type==='RENEW_SCONFIG' || $type==='RENEW_ACCOUNT' || strpos($type,'PG_RENEW_')===0) $label='تمدید سرویس';
        elseif(strpos($type,'INCREASE_VOLUME_')===0) $label='افزایش حجم سرویس';
        elseif(strpos($type,'INCREASE_DAY_')===0) $label='افزایش مدت سرویس';
        $lines[]='🧾 نوع سفارش: '.$label;
        $lines[]='💰 مبلغ: '.number_format((int)($pay['price']??0)).' تومان';
        $planId=(int)($pay['plan_id']??0);
        if(in_array($type,['BUY_SUB','RENEW_SCONFIG'],true) && $planId>0){
            $stmt=$connection->prepare('SELECT title,volume,days,server_id FROM server_plans WHERE id=? LIMIT 1');
            $stmt->bind_param('i',$planId); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($plan){
                $lines[]='📦 پلن: '.htmlspecialchars((string)$plan['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $lines[]='🔋 حجم: '.((int)($pay['volume']??0)>0?(int)$pay['volume']:(int)$plan['volume']).' گیگ';
                $lines[]='⏰ مدت: '.((int)($pay['day']??0)>0?(int)$pay['day']:(int)$plan['days']).' روز';
                $lines[]='🚦 شناسه سرور: '.(int)$plan['server_id'];
            }
            if($type==='BUY_SUB' && (int)($pay['agent_count']??0)>1) $lines[]='👥 تعداد اکانت: '.(int)$pay['agent_count'];
        }
        $orderId=0;
        if($type==='RENEW_ACCOUNT') $orderId=$planId;
        elseif(preg_match('/^(?:INCREASE_(?:DAY|VOLUME)|PG_RENEW_(?:FULL|VOLUME|DAY))_(\d+)_/',$type,$m)) $orderId=(int)$m[1];
        if($orderId>0){
            $stmt=$connection->prepare('SELECT remark,server_id,fileid FROM orders_list WHERE id=? LIMIT 1');
            $stmt->bind_param('i',$orderId); $stmt->execute(); $order=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($order){
                $lines[]='🔮 سرویس: '.htmlspecialchars((string)$order['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $lines[]='🚦 شناسه سرور: '.(int)$order['server_id'];
                if($type==='RENEW_ACCOUNT'){
                    $renewPlanId=(int)$order['fileid'];
                    $stmt=$connection->prepare('SELECT title,volume,days FROM server_plans WHERE id=? LIMIT 1');
                    $stmt->bind_param('i',$renewPlanId); $stmt->execute(); $renewPlan=$stmt->get_result()->fetch_assoc(); $stmt->close();
                    if($renewPlan){
                        $lines[]='📦 پلن تمدید: '.htmlspecialchars((string)$renewPlan['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                        $lines[]='🔋 حجم تمدید: '.(int)$renewPlan['volume'].' گیگ';
                        $lines[]='⏰ مدت تمدید: '.(int)$renewPlan['days'].' روز';
                    }
                }
            }
        }
        if($type==='RENEW_SCONFIG'){
            $conf=json_decode((string)($pay['description']??''),true);
            if(is_array($conf) && isset($conf['remark'])) $lines[]='🔮 سرویس: '.htmlspecialchars((string)$conf['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        }
        if(preg_match('/^INCREASE_VOLUME_\d+_(\d+)$/',$type,$m)) $lines[]='➕ حجم افزوده: '.(int)$m[1].' گیگ';
        if(preg_match('/^INCREASE_DAY_\d+_(\d+)$/',$type,$m)) $lines[]='➕ روز افزوده: '.(int)$m[1].' روز';
        $lines[]='🔖 کد پیگیری: <code>'.deltaTrackingCode($pay['hash_id']).'</code>';
        return implode("\n",$lines);
    }
    function deltaOrderStateLabel($state){
        $labels=['pending'=>'فاکتور ساخته شده؛ منتظر پرداخت','have_sent'=>'رسید ارسال شده؛ منتظر تأیید','need_admin'=>'در انتظار بررسی مدیر','approved'=>'تأیید و سرویس تحویل شده','paid'=>'تأیید خودکار شده','paid_with_wallet'=>'پرداخت با موجودی و تحویل شده','declined'=>'رد شده','rejected'=>'رد شده','cancelled_by_user'=>'لغو شده توسط کاربر','processing_receipt'=>'در حال پردازش رسید','processing_quota'=>'در حال پردازش تمدید','paid_with_quota'=>'تمدید با سهمیه انجام شده','0'=>'در انتظار درگاه','1'=>'پرداخت درگاه تأیید شده'];
        return $labels[(string)$state]??htmlspecialchars((string)$state,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    function deltaDeliveryReportText($hash,$uid,$remarks,$volume,$days,$price){
        $remarks=is_array($remarks)?$remarks:[$remarks];
        $text="✅ کانفیگ را برای کاربر ارسال کردم\n👤 آیدی عددی کاربر: <code>".(int)$uid."</code>\n";
        foreach($remarks as $remark){
            $text.='🔮 ریمارک: '.htmlspecialchars((string)$remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n";
        }
        $text.='🔋 حجم سرویس: '.htmlspecialchars((string)$volume,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')." گیگ\n";
        $text.='⏰ مدت زمان سرویس: '.htmlspecialchars((string)$days,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')." روز\n";
        $text.='💰 مبلغ: '.number_format((int)$price)." تومان";
        return deltaAppendTracking($text,$hash);
    }
}
if(!function_exists('deltaFeatureHandleRequest')){
    function deltaFeatureHandleRequest(){
        global $connection,$data,$text,$from_id,$admin,$userInfo,$message_id,$buttonValues,$cancelKey,$removeKeyboard,$update,$fileid,$paymentKeys,$botState,$mainValues;
        $data=isset($data)?(string)$data:'';
        $text=isset($text)?$text:'';
        $isAdmin=((int)$from_id===(int)$admin)||!empty($userInfo['isAdmin']);

        if($isAdmin && $data==='deltaAutoApproveReset'){
            $t=deltaResetAutoApproveFrom();
            alert('از این لحظه فقط رسیدهای جدید وارد تأیید خودکار می‌شوند.');
            smartSendOrEdit($message_id,'مبدأ تأیید خودکار: '.jdate('Y/m/d H:i:s',$t),getBotSettingKeys());
            exit;
        }
        if($isAdmin && preg_match('/^deltaForceAutoAsk_(\d+)$/',(string)$data,$m)){
            $uid=(int)$m[1]; $state=deltaAutoApprovePolicy($uid);
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ آره؛ همیشه تأیید خودکار','callback_data'=>'deltaForceAutoSet_'.$uid.'_always']],
                [['text'=>'❌ خیر؛ هیچ‌وقت تأیید خودکار','callback_data'=>'deltaForceAutoSet_'.$uid.'_never']],
                [['text'=>'⚙️ عادی؛ تابع تنظیمات عمومی','callback_data'=>'deltaForceAutoSet_'.$uid.'_normal']],
                [['text'=>'بازگشت','callback_data'=>'uRefresh'.$uid]]
            ]],JSON_UNESCAPED_UNICODE);
            $labels=['always'=>'آره ✅','never'=>'خیر ❌','normal'=>'عادی ⚙️'];
            smartSendOrEdit($message_id,"تأیید خودکار رسید این کاربر:\nآره: حتی با خاموش بودن تأیید عمومی، رسیدهای جدید تأیید شوند.\nخیر: حتی با روشن بودن تأیید عمومی، رسیدها تأیید نشوند.\nعادی: از تنظیمات عمومی پیروی کند.\n\nوضعیت فعلی: ".$labels[$state],$kb);
            exit;
        }
        if($isAdmin && preg_match('/^deltaForceAutoSet_(\d+)_(always|never|normal|0|1)$/',(string)$data,$m)){
            $policy=['1'=>'always','0'=>'normal'][$m[2]]??$m[2];
            deltaSetAutoApprovePolicy((int)$m[1],$policy);
            alert('وضعیت تأیید خودکار کاربر ذخیره شد.');
            smartSendOrEdit($message_id,renderUserInfoTitle((int)$m[1]),getUserInfoKeys((int)$m[1]),'HTML');
            exit;
        }
        if($isAdmin && $data==='deltaForceAutoUsers'){
            $res=$connection->query("SELECT type,value FROM setting WHERE type REGEXP '^USER_(AUTOAPPROVE_POLICY|FORCE_AUTOAPPROVE|NO_AUTOAPPROVE)_[0-9]+$' ORDER BY id DESC");
            $rows=[]; $seen=[]; if($res) while($r=$res->fetch_assoc()){
                if(!preg_match('/_(\d+)$/',$r['type'],$idMatch)) continue;
                $uid=(int)$idMatch[1]; if(isset($seen[$uid])) continue; $seen[$uid]=true;
                $policy=deltaAutoApprovePolicy($uid); if($policy==='normal') continue;
                $rows[]=[['text'=>($policy==='always'?'✅ همیشه ':'❌ هرگز ').$uid,'callback_data'=>'deltaForceAutoAsk_'.$uid],['text'=>'⚙️ عادی','callback_data'=>'deltaForceAutoSet_'.$uid.'_normal']];
            }
            if(!$rows) $rows[]=[['text'=>'کاربری استثنا نشده','callback_data'=>'deltach']];
            $rows[]=[['text'=>'بازگشت','callback_data'=>'generalSettings']];
            smartSendOrEdit($message_id,'👥 کاربرهای استثنا شده تأیید خودکار',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
            exit;
        }
        if($isAdmin && $data==='deltaTrackSearch'){
            setUser('deltaTrackSearchInput');
            sendMessage('کد پیگیری ۸ رقمی سفارش را ارسال کنید.',$cancelKey);
            exit;
        }
        if($isAdmin && ($userInfo['step']??'')==='deltaTrackSearchInput' && $text!=($buttonValues['cancel']??'')){
            $code=trim((string)$text);
            if(!preg_match('/^[1-9][0-9]{7}$/',$code)){ sendMessage('لطفاً کد پیگیری ۸ رقمی معتبر ارسال کنید.'); exit; }
            $id=deltaTrackingIdFromCode($code);
            $stmt=$connection->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
            $stmt->bind_param('i',$id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay || deltaTrackingCode($pay['hash_id'])!==$code){ sendMessage('سفارشی با این کد پیگیری پیدا نشد. کد دیگری ارسال کنید.'); exit; }
            setUser();
            $uid=(int)$pay['user_id'];
            $stmt=$connection->prepare('SELECT name,username FROM users WHERE userid=? LIMIT 1');
            $stmt->bind_param('i',$uid); $stmt->execute(); $customer=$stmt->get_result()->fetch_assoc(); $stmt->close();
            $name=htmlspecialchars((string)($customer['name']??'-'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $username=htmlspecialchars((string)($customer['username']??'-'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $details="🔎 نتیجه جستجوی سفارش\n\n👤 کاربر: <code>{$uid}</code>\n👨‍💼 نام: {$name}\n⚡️ نام کاربری: {$username}\n".deltaOrderDetails($pay)."\n📌 وضعیت: ".deltaOrderStateLabel($pay['state'])."\n🕒 تاریخ فاکتور: ".date('Y-m-d H:i:s',(int)$pay['request_date']);
            $receiptAt=deltaReceiptSubmittedAt($pay['hash_id']);
            if($receiptAt>0) $details.="\n📸 زمان ثبت رسید: ".date('Y-m-d H:i:s',$receiptAt);
            $meta=json_decode((string)getSettingValue('USDT_INVOICE_'.$pay['hash_id'],'{}'),true);
            if(is_array($meta) && isset($meta['amount'],$meta['rate'])) $details.="\n💵 روش پرداخت: USDT BEP20\n💲 مبلغ تتر: ".htmlspecialchars((string)$meta['amount'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')." USDT\n📊 نرخ: ".number_format((int)$meta['rate'])." تومان";
            if($pay['type']==='BUY_SUB'){
                $stmt=$connection->prepare('SELECT remark FROM orders_list WHERE userid=? AND transid=? ORDER BY id ASC LIMIT 10');
                $hash=(string)$pay['hash_id']; $stmt->bind_param('is',$uid,$hash); $stmt->execute(); $orders=$stmt->get_result();
                while($order=$orders->fetch_assoc()) $details.="\n✅ سرویس تحویل شده: ".htmlspecialchars((string)$order['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $stmt->close();
            }
            $rows=[];
            if(in_array($pay['state'],['have_sent','need_admin'],true)){
                [$ok,$no]=deltaReceiptCallbacks($pay);
                $rows[]=[['text'=>'✅ تأیید','callback_data'=>$ok],['text'=>'❌ رد','callback_data'=>$no]];
            }
            $rows[]=[['text'=>'👤 حساب کاربر','callback_data'=>'uRefresh'.$uid]];
            $rows[]=[['text'=>'🔎 جستجوی مجدد','callback_data'=>'deltaTrackSearch']];
            sendMessage($details,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');
            exit;
        }

        if($isAdmin && $data==='changePaymentKeysusdtwallet'){
            setUser('deltaSetUsdtWallet'); sendMessage('آدرس کیف پول USDT روی شبکه BSC (BEP20) را ارسال کنید.',$cancelKey); exit;
        }
        if($isAdmin && ($userInfo['step']??'')==='deltaSetUsdtWallet' && $text!=($buttonValues['cancel']??'')){
            $stmt=$connection->prepare("SELECT value FROM setting WHERE type='PAYMENT_KEYS' LIMIT 1"); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
            $pk=json_decode((string)($r['value']??'{}'),true); if(!is_array($pk)) $pk=[];
            $wallet=trim((string)$text);
            if(!preg_match('/^0x[a-fA-F0-9]{40}$/',$wallet)){
                sendMessage('❌ آدرس BSC باید با 0x شروع شود و دقیقاً ۴۰ رقم یا حرف هگز پس از آن داشته باشد.');
                exit;
            }
            $pk['usdtwallet']=$wallet;
            upsertSettingValue('PAYMENT_KEYS',json_encode($pk,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            setUser(); sendMessage('✅ آدرس کیف پول USDT ذخیره شد.',$removeKeyboard); sendMessage('تنظیمات درگاه و کانال',getGateWaysKeys()); exit;
        }

        if(preg_match('/^deltaUsdtCancel_(.+)$/',(string)$data,$m)){
            $hash=$m[1];
            $stmt=$connection->prepare('SELECT state FROM pays WHERE hash_id=? AND user_id=? LIMIT 1');
            $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay){ alert('این فاکتور پیدا نشد.',true); exit; }
            if((string)$pay['state']!=='pending'){
                alert('این سفارش دیگر در مرحله پرداخت نیست. اگر رسید فرستاده‌اید، آن را از سفارش‌های در حال انتظار پیگیری کنید.',true);
                exit;
            }
            $stmt=$connection->prepare("UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND state='pending'");
            $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $changed=$stmt->affected_rows; $stmt->close();
            if($changed!==1){ alert('وضعیت سفارش تغییر کرده است؛ دوباره بررسی کنید.',true); exit; }
            if(($userInfo['step']??'')==='deltaUsdtReceipt_'.$hash){ setUser(); $userInfo['step']='none'; }
            smartSendOrEdit($message_id,"✅ فاکتور ارزی لغو شد.\n\n".deltaTrackingLine($hash),json_encode(['inline_keyboard'=>[[['text'=>'🏠 صفحه اصلی','callback_data'=>'mainMenu']]]],JSON_UNESCAPED_UNICODE),'HTML');
            exit;
        }
        if(preg_match('/^deltaUsdtReceipt_(.+)$/',(string)($userInfo['step']??''),$m)){
            $cancelText=trim((string)$text);
            $isCancelText=$cancelText!=='' && ($cancelText===($buttonValues['cancel']??'') ||
                (mb_strpos($cancelText,'منصرف')!==false && mb_strpos($cancelText,'بیخیال')!==false));
            $isStart=(bool)preg_match('/^\/start(?:\s|$)/i',$cancelText);
            if($isCancelText || $isStart || $data==='mainMenu'){
                $hash=$m[1];
                $stmt=$connection->prepare("UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND state='pending'");
                $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $changed=$stmt->affected_rows; $stmt->close();
                setUser(); $userInfo['step']='none';
                if($changed===1) sendMessage('✅ فاکتور ارزی شما لغو شد.',$removeKeyboard);
                if($data==='mainMenu') return; // The normal menu callback will render the main menu.
                if($isStart) return; // The normal /start handler will render the main menu.
                if($changed!==1) sendMessage('از مرحله ارسال رسید خارج شدید؛ وضعیت سفارش تغییر کرده است.',$removeKeyboard);
                sendMessage($mainValues['start_message'],getMainKeys());
                exit;
            }
        }
        if(preg_match('/^payWithUsdt(.+)$/',(string)$data,$m)){
            $hash=$m[1];
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay || (string)$pay['state']!=='pending'){ alert('این فاکتور دیگر برای پرداخت معتبر نیست.',true); exit; }
            if(($botState['usdtState']??'off')!=='on' || empty($paymentKeys['usdtwallet'])){ alert('پرداخت ارزی در حال حاضر فعال نیست.',true); exit; }
            $rate=deltaUsdtRateToman();
            if($rate<=0 || (int)$pay['price']<=0){ alert('دریافت نرخ لحظه‌ای تتر ناموفق بود؛ دوباره تلاش کنید.',true); exit; }
            $quote=['rate'=>$rate,'amount'=>deltaUsdtAmount($pay['price'],$rate),'wallet'=>trim((string)$paymentKeys['usdtwallet']),'price'=>(int)$pay['price'],'created_at'=>time(),'expires_at'=>time()+1800];
            $txt=deltaUsdtPayText($pay,$quote);
            upsertSettingValue('USDT_INVOICE_'.$hash,json_encode($quote,JSON_UNESCAPED_UNICODE));
            setUser('deltaUsdtReceipt_'.$hash);
            smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>[[['text'=>'❌ لغو سفارش','callback_data'=>'deltaUsdtCancel_'.$hash]]]],JSON_UNESCAPED_UNICODE),'HTML');
            exit;
        }
        if(preg_match('/^deltaUsdtReceipt_(.+)$/',(string)($userInfo['step']??''),$m)){
            $hash=$m[1];
            if(!isset($update->message->photo)){ sendMessage('📸 فقط عکس رسید را ارسال کنید و هش تراکنش را در کپشن همان عکس بنویسید.'); exit; }
            $tx=trim((string)($update->message->caption??''));
            if(!preg_match('/\b0x[a-fA-F0-9]{64}\b/',$tx,$txMatch)){ sendMessage('❌ هش تراکنش BEP20 را در کپشن عکس بفرستید (0x به‌همراه ۶۴ رقم یا حرف).'); exit; }
            $tx=strtolower($txMatch[0]);
            $txKey='USDT_TX_'.sha1($tx);
            $previous=getSettingValue($txKey,'');
            if($previous!=='' && $previous!==$hash){ sendMessage('❌ این هش تراکنش قبلاً برای فاکتور دیگری ثبت شده است.'); exit; }
            $meta=json_decode((string)getSettingValue('USDT_INVOICE_'.$hash,'{}'),true);
            if(!is_array($meta) || (int)($meta['expires_at']??0)<time()){ sendMessage('⏰ اعتبار ۳۰ دقیقه‌ای فاکتور ارزی تمام شده است. دوباره فاکتور ارزی بسازید.'); setUser(); exit; }
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay || (string)$pay['state']!=='pending'){ sendMessage('این سفارش قبلاً ثبت یا بسته شده است.'); setUser(); exit; }
            $rate=(int)($meta['rate']??0);
            if($rate<=0 || (int)($meta['price']??-1)!==(int)$pay['price']){ sendMessage('اطلاعات فاکتور ارزی معتبر نیست؛ دوباره فاکتور بسازید.'); setUser(); exit; }
            $usdt=(string)$meta['amount'];
            $name=htmlspecialchars((string)($userInfo['name']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $uname=htmlspecialchars((string)($userInfo['username']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $msg="💵 رسید پرداخت ارزی (USDT BEP20)\n\n👤 آیدی: <code>{$from_id}</code>\n👨‍💼 نام: {$name}\n⚡️ نام کاربری: {$uname}\n💰 مبلغ سرویس: ".number_format((int)$pay['price'])." تومان\n📊 نرخ تتر: ".number_format($rate)." تومان\n💲 مبلغ فاکتور: {$usdt} USDT\n🧾 نوع سفارش: <code>".htmlspecialchars((string)$pay['type'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n📋 آدرس مقصد: <code>".htmlspecialchars((string)$meta['wallet'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n🧾 هش تراکنش:\n<code>{$tx}</code>\n".deltaTrackingLine($hash);
            [$ok,$no]=deltaReceiptCallbacks($pay); $kb=getReceiptAdminKeyboard($ok,$no,$from_id);
            $res=sendPhotoToAdmins($fileid,$msg,$kb,'HTML',$hash);
            if(empty($res->ok)){ sendMessage('ارسال رسید به مدیریت ناموفق بود؛ لطفاً دوباره تلاش کنید.'); exit; }
            $mid=(int)($res->result->message_id??0);
            $stmt=$connection->prepare("UPDATE pays SET state='have_sent',message_id=?,chat_id=? WHERE hash_id=? AND user_id=? AND state='pending'"); $stmt->bind_param('iisi',$mid,$admin,$hash,$from_id); $stmt->execute(); $stmt->close();
            upsertSettingValue($txKey,$hash);
            deltaMarkReceiptSubmitted($hash);
            sendMessage("✅ رسید ارزی شما ثبت شد و برای مدیریت ارسال شد.\n\n".deltaTrackingLine($hash),$removeKeyboard,'HTML'); setUser(); exit;
        }

        if($isAdmin && preg_match('/^deltaRejectPay_([^_]+)_(\d+)$/',$data,$m)){
            setUser('deltaRejectPayReason|'.$m[1].'|'.$m[2].'|'.$message_id);
            sendMessage('دلیل رد سفارش را ارسال کنید.',$cancelKey);
            exit;
        }
        if($isAdmin && preg_match('/^deltaRejectPayReason\|([^|]+)\|(\d+)\|(\d+)$/',(string)($userInfo['step']??''),$m) && $text!=($buttonValues['cancel']??'')){
            $hash=$m[1]; $uid=(int)$m[2]; $receiptMessageId=(int)$m[3];
            $stmt=$connection->prepare("UPDATE pays SET state='declined' WHERE hash_id=? AND state IN ('have_sent','need_admin','pending')");
            $stmt->bind_param('s',$hash); $stmt->execute(); $stmt->close();
            @editKeys(json_encode(['inline_keyboard'=>[[['text'=>'لغو شد ❌','callback_data'=>'deltach']]]],JSON_UNESCAPED_UNICODE),$receiptMessageId);
            sendMessage(deltaAppendTracking(htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$hash),null,'HTML',$uid);
            sendMessage('رسید رد شد و از سفارش‌های در انتظار حذف شد.',$removeKeyboard);
            setUser();
            exit;
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
            $stmt=$connection->prepare("UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin')"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $changed=$stmt->affected_rows; $stmt->close();
            if($changed!==1){ alert('این سفارش دیگر قابل لغو نیست.',true); exit; }
            if(!empty($p['message_id'])) @editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ لغو شده توسط کاربر','callback_data'=>'deltach']]]],JSON_UNESCAPED_UNICODE),(int)$p['message_id'],(int)($p['chat_id']?:$admin));
            sendToAdmins("❌ سفارش <code>".deltaTrackingCode($hash)."</code> توسط کاربر <code>{$from_id}</code> لغو شد.",null,'HTML');
            smartSendOrEdit($message_id,'✅ سفارش لغو شد.',deltaPendingOrdersKeyboard($from_id)); exit;
        }
        if(preg_match('/^deltaPendingResend_(.+)$/',(string)$data,$m)){
            $hash=$m[1]; $key='PENDING_RESEND_'.$from_id.'_'.sha1($hash); $last=(int)getSettingValue($key,'0');
            if(time()-$last<3600){ alert('ارسال مجدد هر یک ساعت یک بار امکان‌پذیر است.',true); exit; }
            $stmt=$connection->prepare("SELECT * FROM pays WHERE hash_id=? AND user_id=? AND state IN ('have_sent','need_admin') LIMIT 1"); $stmt->bind_param('si',$hash,$from_id); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay){ alert('این سفارش دیگر در انتظار نیست.',true); exit; }
            upsertSettingValue($key,(string)time());
            [$approve,$decline]=deltaReceiptCallbacks($pay);
            sendToAdmins("🔔 سفارش در حال انتظار؛ جهت تأیید اقدام نمایید.\n👤 کاربر: <code>{$from_id}</code>\n".deltaOrderDetails($pay),getReceiptAdminKeyboard($approve,$decline,$from_id),'HTML');
            alert('یادآوری برای مدیریت ارسال شد.'); exit;
        }
    }
}
