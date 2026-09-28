<?php
// Interactive handlers for commerce_extensions.php.
// Included from bot.php after config.php has parsed the Telegram update.

if(!function_exists('deltaCommerceIsAdmin')){
    function deltaCommerceIsAdmin(){
        global $from_id,$admin,$userInfo;
        return ((int)$from_id===(int)$admin || !empty($userInfo['isAdmin']));
    }
}
if(!function_exists('deltaCommerceFetchPayByHash')){
    function deltaCommerceFetchPayByHash($hash){
        global $connection;
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? LIMIT 1");
        if(!$stmt) return null;
        $stmt->bind_param('s',$hash);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return $row?:null;
    }
}
if(!function_exists('deltaCommerceFetchPayById')){
    function deltaCommerceFetchPayById($id){
        global $connection;
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `id`=? LIMIT 1");
        if(!$stmt) return null;
        $stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return $row?:null;
    }
}
if(!function_exists('deltaCommercePrivateDiscountDraft')){
    function deltaCommercePrivateDiscountDraft(){
        global $userInfo;
        $d=json_decode((string)($userInfo['temp']??''),true);
        return is_array($d)?$d:[];
    }
}
if(!function_exists('deltaCommerceSavePrivateDiscountDraft')){
    function deltaCommerceSavePrivateDiscountDraft($d,$step){
        setUser(json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'temp');
        setUser($step);
    }
}

// ------------------------------------------------------------
// Guard: a user-cancelled receipt can never be approved from an old Telegram
// button, even if the admin still has a cached message.
// ------------------------------------------------------------
if(deltaCommerceIsAdmin() && isset($data) && is_string($data)){
    $guardPrefixes=[
        'approvePayment','approveRenewAcc','approvePgRenew','approveIncreaseDay',
        'approveIncreaseVolume','accCustom','accept'
    ];
    foreach($guardPrefixes as $gp){
        if(strpos($data,$gp)===0){
            $candidate=substr($data,strlen($gp));
            if($candidate!==''){
                $guardPay=deltaCommerceFetchPayByHash($candidate);
                if($guardPay && (string)($guardPay['state']??'')==='cancelled_by_user'){
                    if(function_exists('editKeys')){
                        @editKeys(json_encode(['inline_keyboard'=>[[['text'=>'لغو شده توسط کاربر ❌','callback_data'=>'deltach']]]],JSON_UNESCAPED_UNICODE));
                    }
                    alert('این سفارش توسط کاربر لغو شده است.',true);
                    exit;
                }
            }
            break;
        }
    }
}

// ------------------------------------------------------------
// Auto-approval baseline reset
// ------------------------------------------------------------
if(($data??'')==='autoApproveResetAsk' && deltaCommerceIsAdmin()){
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>'✅ بله، از این لحظه شروع شود','callback_data'=>'autoApproveResetDo']],
        [['text'=>'❌ انصراف','callback_data'=>'botSettings']]
    ]],JSON_UNESCAPED_UNICODE);
    smartSendOrEdit($message_id,
        "♻️ <b>ریست رسیدهای قدیمی</b>\n\n".
        "با انجام این کار، هیچ رسید قبلی به‌صورت خودکار تأیید نمی‌شود.\n".
        "فقط رسیدهایی که از این لحظه به بعد ثبت شوند وارد تأیید خودکار عمومی خواهند شد.\n\n".
        "رسیدهای قبلی همچنان برای تأیید دستی باقی می‌مانند.",
        $keys,'HTML');
    exit;
}
if(($data??'')==='autoApproveResetDo' && deltaCommerceIsAdmin()){
    $baseline=deltaResetAutoApproveBaseline();
    alert('رسیدهای قدیمی از تأیید خودکار خارج شدند.');
    smartSendOrEdit($message_id,
        "✅ ریست انجام شد.\n\nاز این لحظه فقط رسیدهای جدید وارد تأیید خودکار عمومی می‌شوند.\nشناسه مرز داخلی: <code>{$baseline}</code>",
        getBotSettingKeys(),'HTML');
    exit;
}

// ------------------------------------------------------------
// Per-user ALWAYS auto-approve
// ------------------------------------------------------------
if(preg_match('/^uForceAutoAsk(\d+)$/',(string)($data??''),$m) && deltaCommerceIsAdmin()){
    $uid=(int)$m[1];
    $on=deltaIsForceAutoApproveUser($uid);
    $keys=json_encode(['inline_keyboard'=>[
        [
            ['text'=>'✅ آره، همیشه خودکار','callback_data'=>'uForceAutoSet'.$uid.'_1'],
            ['text'=>'❌ نه، حالت عادی','callback_data'=>'uForceAutoSet'.$uid.'_0']
        ],
        [['text'=>'🔙 برگشت','callback_data'=>'uRefresh'.$uid]]
    ]],JSON_UNESCAPED_UNICODE);
    smartSendOrEdit($message_id,
        "🤖 <b>استثنا کردن تأیید خودکار</b>\n\n".
        "آیا می‌خواهید رسیدهای این کاربر <b>همیشه خودکار تأیید شوند؟</b>\n\n".
        "اگر «آره» را بزنید، حتی وقتی تأیید خودکار عمومی ربات خاموش باشد، رسیدهای این کاربر همچنان خودکار پردازش می‌شوند.\n\n".
        "وضعیت فعلی: <b>".($on?'روشن ✅':'خاموش ❌')."</b>",
        $keys,'HTML');
    exit;
}
if(preg_match('/^uForceAutoSet(\d+)_(0|1)$/',(string)($data??''),$m) && deltaCommerceIsAdmin()){
    $uid=(int)$m[1];$enabled=$m[2]==='1';
    deltaSetForceAutoApproveUser($uid,$enabled);
    alert($enabled?'✅ تأیید خودکار دائمی فعال شد':'❌ تأیید خودکار دائمی لغو شد');
    refreshUserInfoPanel($uid,$message_id);
    exit;
}
if(($data??'')==='forceAutoUsers' && deltaCommerceIsAdmin()){
    $ids=deltaGetForceAutoApproveUsers();
    $rows=[];
    foreach($ids as $uid){
        $label=(string)$uid;
        try{
            $d=bot('getChat',['chat_id'=>$uid]);
            if(is_object($d) && !empty($d->ok) && isset($d->result)){
                $nm=trim((string)($d->result->first_name??''));
                $un=trim((string)($d->result->username??''));
                if($nm!=='') $label=$nm.' | '.$uid;
                elseif($un!=='') $label='@'.$un.' | '.$uid;
            }
        }catch(Throwable $e){}
        $rows[]=[
            ['text'=>'❌ لغو','callback_data'=>'forceAutoRemove'.$uid],
            ['text'=>$label,'callback_data'=>'uForceAutoAsk'.$uid]
        ];
    }
    if(!$rows) $rows[]=[['text'=>'کاربری در لیست استثنا نیست','callback_data'=>'deltach']];
    $rows[]=[['text'=>'🔙 برگشت','callback_data'=>'generalSettings']];
    smartSendOrEdit($message_id,
        "🤖 <b>کاربرهای استثنا شده تأیید خودکار</b>\n\nاین کاربران حتی با خاموش بودن تأیید خودکار عمومی، رسیدشان خودکار پردازش می‌شود.",
        json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');
    exit;
}
if(preg_match('/^forceAutoRemove(\d+)$/',(string)($data??''),$m) && deltaCommerceIsAdmin()){
    deltaSetForceAutoApproveUser((int)$m[1],false);
    alert('استثنا لغو شد');
    $data='forceAutoUsers';
    $ids=deltaGetForceAutoApproveUsers();$rows=[];
    foreach($ids as $uid){
        $rows[]=[
            ['text'=>'❌ لغو','callback_data'=>'forceAutoRemove'.$uid],
            ['text'=>(string)$uid,'callback_data'=>'uForceAutoAsk'.$uid]
        ];
    }
    if(!$rows)$rows[]=[['text'=>'کاربری در لیست استثنا نیست','callback_data'=>'deltach']];
    $rows[]=[['text'=>'🔙 برگشت','callback_data'=>'generalSettings']];
    smartSendOrEdit($message_id,'🤖 کاربرهای استثنا شده تأیید خودکار',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
    exit;
}

// ------------------------------------------------------------
// Private discount code creation
// ------------------------------------------------------------
if(($data??'')==='addPrivateDiscountCode' && deltaCommerceIsAdmin()){
    delMessage();
    setUser('privateDiscountUser');
    setUser('','temp');
    sendMessage("👤 آیدی عددی کاربری که این کد فقط برای او فعال باشد را ارسال کنید:",$cancelKey);
    exit;
}
if(($userInfo['step']??'')==='privateDiscountUser' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $v=trim((string)$text);
    if(!ctype_digit($v) || (int)$v<=0){sendMessage('❌ فقط آیدی عددی معتبر ارسال کنید.');exit;}
    $uid=(int)$v;
    $stmt=$connection->prepare("SELECT `userid` FROM `users` WHERE `userid`=? LIMIT 1");
    $stmt->bind_param('i',$uid);$stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
    if(!$exists){sendMessage('❌ این کاربر در ربات پیدا نشد.');exit;}
    deltaCommerceSavePrivateDiscountDraft(['user_id'=>$uid],'privateDiscountCode');
    sendMessage("🎟 متن کد تخفیف اختصاصی را خودت وارد کن.\n\nمثال: <code>SIAVASH30</code>\nفاصله مجاز نیست.",$cancelKey,'HTML');
    exit;
}
if(($userInfo['step']??'')==='privateDiscountCode' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $code=trim((string)$text);
    $len=function_exists('mb_strlen')?mb_strlen($code,'UTF-8'):strlen($code);
    if($len<3 || $len>50 || preg_match('/\s/u',$code)){sendMessage('❌ کد باید ۳ تا ۵۰ کاراکتر و بدون فاصله باشد.');exit;}
    $stmt=$connection->prepare("SELECT `id` FROM `discounts` WHERE `hash_id`=? LIMIT 1");
    $stmt->bind_param('s',$code);$stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
    if($exists){sendMessage('❌ این کد قبلاً وجود دارد؛ یک کد دیگر وارد کنید.');exit;}
    $d=deltaCommercePrivateDiscountDraft();$d['hash_id']=$code;
    deltaCommerceSavePrivateDiscountDraft($d,'privateDiscountAmount');
    sendMessage("🔘 مقدار تخفیف را وارد کنید.\nبرای درصد مثل <code>20%</code> و برای مبلغ ثابت مثل <code>50000</code> بفرستید.",$cancelKey,'HTML');
    exit;
}
if(($userInfo['step']??'')==='privateDiscountAmount' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $raw=trim((string)$text);$type=strpos($raw,'%')!==false?'percent':'amount';$num=trim(str_replace('%','',$raw));
    if(!is_numeric($num) || (float)$num<=0 || ($type==='percent' && (float)$num>100)){sendMessage('❌ مقدار تخفیف معتبر نیست.');exit;}
    $d=deltaCommercePrivateDiscountDraft();$d['type']=$type;$d['amount']=(int)$num;
    deltaCommerceSavePrivateDiscountDraft($d,'privateDiscountDate');
    sendMessage("📅 مدت اعتبار کد را به روز وارد کنید.\nبرای نامحدود <code>0</code> بفرستید.",$cancelKey,'HTML');
    exit;
}
if(($userInfo['step']??'')==='privateDiscountDate' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $v=trim((string)$text);if(!ctype_digit($v)){sendMessage('❌ فقط عدد بفرستید.');exit;}
    $days=(int)$v;$d=deltaCommercePrivateDiscountDraft();$d['expire_date']=$days>0?time()+($days*86400):0;
    deltaCommerceSavePrivateDiscountDraft($d,'privateDiscountCount');
    sendMessage("🔢 تعداد استفاده کل کد را وارد کنید.\nبرای نامحدود <code>0</code> بفرستید.",$cancelKey,'HTML');
    exit;
}
if(($userInfo['step']??'')==='privateDiscountCount' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $v=trim((string)$text);if(!ctype_digit($v)){sendMessage('❌ فقط عدد بفرستید.');exit;}
    $d=deltaCommercePrivateDiscountDraft();$d['expire_count']=(int)$v>0?(int)$v:-1;
    deltaCommerceSavePrivateDiscountDraft($d,'privateDiscountCanUse');
    sendMessage("👤 این کاربر حداکثر چند بار بتواند از کد استفاده کند؟\nبرای نامحدود <code>0</code> بفرستید.",$cancelKey,'HTML');
    exit;
}
if(($userInfo['step']??'')==='privateDiscountCanUse' && $text!=($buttonValues['cancel']??'') && deltaCommerceIsAdmin()){
    $v=trim((string)$text);if(!ctype_digit($v)){sendMessage('❌ فقط عدد بفرستید.');exit;}
    $d=deltaCommercePrivateDiscountDraft();
    foreach(['user_id','hash_id','type','amount','expire_date','expire_count'] as $k){if(!array_key_exists($k,$d)){sendMessage('اطلاعات ساخت کد ناقص شد؛ دوباره شروع کنید.');setUser();exit;}}
    $can=(int)$v>0?(int)$v:-1;
    $stmt=$connection->prepare("INSERT INTO `discounts` (`hash_id`,`user_id`,`type`,`amount`,`expire_date`,`expire_count`,`can_use`) VALUES (?,?,?,?,?,?,?)");
    $uid=(int)$d['user_id'];$amount=(int)$d['amount'];$expire=(int)$d['expire_date'];$cnt=(int)$d['expire_count'];
    $stmt->bind_param('sisiiii',$d['hash_id'],$uid,$d['type'],$amount,$expire,$cnt,$can);$stmt->execute();$stmt->close();
    setUser();setUser('','temp');
    sendMessage("✅ کد تخفیف اختصاصی ساخته شد.\n\n🎟 کد: <code>".htmlspecialchars($d['hash_id'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n👤 فقط برای کاربر: <code>{$uid}</code>",getDiscountCodeKeys(),'HTML');
    exit;
}

// ------------------------------------------------------------
// USDT BEP20 invoice and receipt
// ------------------------------------------------------------
if(preg_match('/^payWithUsdt(.+)$/',(string)($data??''),$m)){
    $hash=$m[1];$err=null;$pay=deltaPrepareUsdtQuote($hash,(int)$from_id,$err);
    if(!$pay){alert($err?:'ساخت فاکتور ارزی ناموفق بود',true);exit;}
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>'📸 ارسال رسید USDT','callback_data'=>'sendUsdtReceipt'.$hash]],
        [['text'=>'🔄 بروزرسانی نرخ لحظه‌ای','callback_data'=>'payWithUsdt'.$hash]],
        [['text'=>$buttonValues['back_to_main']??'بازگشت','callback_data'=>'mainMenu']]
    ]],JSON_UNESCAPED_UNICODE);
    smartSendOrEdit($message_id,deltaBuildUsdtInvoiceText($pay),$keys,'HTML');
    exit;
}
if(preg_match('/^sendUsdtReceipt(.+)$/',(string)($data??''),$m)){
    $hash=$m[1];$pay=deltaCommerceFetchPayByHash($hash);
    if(!$pay || (int)$pay['user_id']!==(int)$from_id){alert('فاکتور پیدا نشد',true);exit;}
    if((int)($pay['usdt_expires_at']??0)<time()){alert('⏰ اعتبار نرخ این فاکتور تمام شده؛ ابتدا نرخ را بروزرسانی کنید.',true);exit;}
    if((string)($pay['state']??'')!=='pending'){alert('این فاکتور دیگر در انتظار پرداخت نیست.',true);exit;}
    setUser('usdtReceipt|'.$hash);
    sendMessage("📸 اسکرین‌شات تأیید تراکنش را ارسال کنید و <b>هش تراکنش (TXID)</b> را حتماً در کپشن همان عکس بنویسید.",$cancelKey,'HTML');
    exit;
}
if(preg_match('/^usdtReceipt\|(.+)$/',(string)($userInfo['step']??''),$m) && $text!=($buttonValues['cancel']??'')){
    $hash=$m[1];$pay=deltaCommerceFetchPayByHash($hash);
    if(!$pay || (int)$pay['user_id']!==(int)$from_id){sendMessage('فاکتور پیدا نشد.',$removeKeyboard);setUser();exit;}
    if((int)($pay['usdt_expires_at']??0)<time()){sendMessage('⏰ اعتبار ۳۰ دقیقه‌ای نرخ تمام شده؛ به فاکتور برگردید و نرخ را بروزرسانی کنید.',$removeKeyboard);setUser();exit;}
    if(!isset($update->message->photo)){sendMessage('❌ فقط عکس اسکرین‌شات رسید را ارسال کنید و TXID را در کپشن بنویسید.');exit;}
    $tx=trim((string)($caption??''));
    if(!preg_match('/^0x[a-fA-F0-9]{64}$/',$tx)){
        sendMessage('❌ TXID معتبر شبکه BSC وارد کنید. هش تراکنش باید با <code>0x</code> شروع شود و ۶۴ کاراکتر هگز بعد از آن داشته باشد.',null,'HTML');
        exit;
    }
    $dupStmt=$connection->prepare("SELECT `id` FROM `pays` WHERE `usdt_tx_hash`=? AND `hash_id`<>? LIMIT 1");
    if($dupStmt){
        $dupStmt->bind_param('ss',$tx,$hash);$dupStmt->execute();$duplicate=$dupStmt->get_result()->num_rows>0;$dupStmt->close();
        if($duplicate){sendMessage('❌ این TXID قبلاً برای یک فاکتور دیگر ثبت شده است.');exit;}
    }
    $photos=$update->message->photo;$ph=end($photos);$receiptFile=(string)($ph->file_id??$fileid??'');
    $method='usdt_bep20';
    $stmt=$connection->prepare("UPDATE `pays` SET `state`='have_sent',`receipt_submitted_at`=UNIX_TIMESTAMP(),`payment_method`=?,`usdt_tx_hash`=? WHERE `hash_id`=? AND `state`='pending'");
    $stmt->bind_param('sss',$method,$tx,$hash);$stmt->execute();$changed=$stmt->affected_rows>0;$stmt->close();
    if(!$changed){sendMessage('این فاکتور قبلاً ارسال یا پردازش شده است.',$removeKeyboard);setUser();exit;}
    $pay=deltaCommerceFetchPayByHash($hash);$tracking=deltaEnsureTrackingCode($hash);
    $uid=(int)$pay['user_id'];$safeTx=htmlspecialchars($tx,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $msg="💵 <b>رسید پرداخت ارزی USDT</b>\n\n".
         "👤 آیدی کاربر: <code>{$uid}</code>\n".
         "👨‍💼 نام: <code>".htmlspecialchars((string)($userInfo['name']??$first_name??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
         "⚡️ نام کاربری: <code>@".htmlspecialchars((string)($userInfo['username']??$username??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
         "🧾 نوع سفارش: <code>".htmlspecialchars((string)$pay['type'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
         "💰 مبلغ ریالی: <b>".number_format((int)$pay['price'])." تومان</b>\n".
         "🪙 مبلغ فاکتور: <b>".number_format((float)$pay['usdt_amount'],4,'.','')." USDT</b>\n".
         "📊 نرخ تتر فاکتور: <b>".number_format((int)$pay['usdt_rate_toman'])." تومان</b>\n".
         "🌐 شبکه: <b>BSC (BEP20)</b>\n".
         "🔗 TXID: <code>{$safeTx}</code>\n".
         "🔖 کد پیگیری: <code>{$tracking}</code>";

    $ptype=(string)$pay['type'];
    if($ptype==='INCREASE_WALLET') $adminkeys=getPaymentAdminKeyboard($hash,$uid);
    elseif($ptype==='RENEW_ACCOUNT') $adminkeys=getReceiptAdminKeyboard('approveRenewAcc'.$hash,'decRenewAcc'.$hash,$uid);
    elseif(preg_match('/^PG_RENEW_(FULL|VOLUME|DAY)_/',$ptype)) $adminkeys=getReceiptAdminKeyboard('approvePgRenew'.$hash,'decPgRenew'.$hash,$uid);
    elseif(preg_match('/^INCREASE_DAY_/',$ptype)) $adminkeys=getReceiptAdminKeyboard('approveIncreaseDay'.$hash,'decIncreaseDay'.$hash,$uid);
    elseif(preg_match('/^INCREASE_VOLUME_/',$ptype)) $adminkeys=getReceiptAdminKeyboard('approveIncreaseVolume'.$hash,'decIncreaseVolume'.$hash,$uid);
    elseif($ptype==='BUY_SUB' && ((float)($pay['volume']??0)>0 || (int)($pay['day']??0)>0))
        $adminkeys=getReceiptAdminKeyboard('accCustom'.$hash,'declineOffer'.$hash.'_'.$uid,$uid);
    else $adminkeys=getReceiptAdminKeyboard('accept'.$hash,'declineOffer'.$hash.'_'.$uid,$uid);

    $res=sendPhotoToAdmins($receiptFile,$msg,$adminkeys,'HTML');
    $msgId=(is_object($res)&&isset($res->result->message_id))?(int)$res->result->message_id:0;
    if($msgId>0){
        $chat=(string)$admin;
        $stmt=$connection->prepare("UPDATE `pays` SET `message_id`=?,`chat_id`=? WHERE `hash_id`=?");
        $stmt->bind_param('iss',$msgId,$chat,$hash);$stmt->execute();$stmt->close();
    }
    setUser();
    // sendPhotoToAdmins() already sends the user a universal receipt confirmation
    // with the same tracking code. Avoid a duplicate USDT-only confirmation here.
    sendMessage($mainValues['reached_main_menu']??'منوی اصلی',getMainKeys());
    exit;
}

// ------------------------------------------------------------
// Pending user orders
// ------------------------------------------------------------
if(($data??'')==='pendingOrders'){
    if((($botState['pendingOrdersState']??'off')!=='on')){alert('این بخش غیرفعال است.',true);exit;}
    $pays=deltaPendingPayRows((int)$from_id);$rows=[];
    foreach($pays as $p){
        $code=$p['tracking_code']?:deltaEnsureTrackingCode($p['hash_id']);
        $rows[]=[['text'=>'⏳ سفارش: '.$code,'callback_data'=>'pendingOrder'.(int)$p['id']]];
    }
    if(!$rows)$rows[]=[['text'=>'✅ سفارش در حال انتظاری ندارید','callback_data'=>'deltach']];
    $rows[]=[['text'=>$buttonValues['back_to_main']??'بازگشت','callback_data'=>'mainMenu']];
    smartSendOrEdit($message_id,'📦 سفارش‌های در حال انتظار تأیید',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
    exit;
}
if(preg_match('/^pendingOrder(\d+)$/',(string)($data??''),$m)){
    $pay=deltaCommerceFetchPayById((int)$m[1]);
    if(!$pay || (int)$pay['user_id']!==(int)$from_id || (string)$pay['state']!=='have_sent'){alert('این سفارش دیگر در انتظار تأیید نیست.',true);exit;}
    $code=deltaEnsureTrackingCode($pay['hash_id']);
    $keys=json_encode(['inline_keyboard'=>[
        [
            ['text'=>'❌ لغو سفارش','callback_data'=>'pendingCancel'.(int)$pay['id']],
            ['text'=>'🔔 ارسال مجدد','callback_data'=>'pendingResend'.(int)$pay['id']]
        ],
        [['text'=>'🔙 برگشت','callback_data'=>'pendingOrders']]
    ]],JSON_UNESCAPED_UNICODE);
    $last=(int)($pay['pending_resend_at']??0);
    $resendInfo=$last>0?"\n🔔 آخرین یادآوری: <code>".date('Y-m-d H:i:s',$last)."</code>":"";
    smartSendOrEdit($message_id,
        "⏳ <b>سفارش در انتظار تأیید است</b>\n\n".
        "🔖 کد پیگیری: <code>{$code}</code>\n".
        "💰 مبلغ: <b>".number_format((int)$pay['price'])." تومان</b>\n".
        "🧾 نوع: <code>".htmlspecialchars((string)$pay['type'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>{$resendInfo}\n\n".
        "ارسال مجدد فقط هر یک ساعت یک‌بار امکان‌پذیر است.",
        $keys,'HTML');
    exit;
}
if(preg_match('/^pendingCancel(\d+)$/',(string)($data??''),$m)){
    $pay=deltaCommerceFetchPayById((int)$m[1]);
    if(!$pay || (int)$pay['user_id']!==(int)$from_id || (string)$pay['state']!=='have_sent'){alert('سفارش قابل لغو نیست.',true);exit;}
    $now=time();$id=(int)$pay['id'];
    $stmt=$connection->prepare("UPDATE `pays` SET `state`='cancelled_by_user',`cancelled_at`=? WHERE `id`=? AND `user_id`=? AND `state`='have_sent'");
    $stmt->bind_param('iii',$now,$id,$from_id);$stmt->execute();$ok=$stmt->affected_rows>0;$stmt->close();
    if(!$ok){alert('وضعیت سفارش تغییر کرده است.',true);exit;}
    deltaDisableAdminReceiptButtons($pay,'لغو شده توسط کاربر ❌');
    $code=deltaEnsureTrackingCode($pay['hash_id']);
    sendToAdmins("❌ سفارش <code>{$code}</code> توسط کاربر <code>{$from_id}</code> لغو شد؛ امکان تأیید آن بسته شد.",null,'HTML');
    smartSendOrEdit($message_id,"✅ سفارش <code>{$code}</code> لغو شد.",json_encode(['inline_keyboard'=>[[['text'=>'🔙 سفارش‌های در انتظار','callback_data'=>'pendingOrders']]]]),'HTML');
    exit;
}
if(preg_match('/^pendingResend(\d+)$/',(string)($data??''),$m)){
    $pay=deltaCommerceFetchPayById((int)$m[1]);
    if(!$pay || (int)$pay['user_id']!==(int)$from_id || (string)$pay['state']!=='have_sent'){alert('این سفارش دیگر در انتظار تأیید نیست.',true);exit;}
    $now=time();$last=(int)($pay['pending_resend_at']??0);
    if($last>0 && $now-$last<3600){
        $mins=(int)ceil((3600-($now-$last))/60);
        alert("ارسال مجدد هر یک ساعت یک‌بار ممکن است. حدود {$mins} دقیقه دیگر دوباره امتحان کنید.",true);
        exit;
    }
    $id=(int)$pay['id'];
    $stmt=$connection->prepare("UPDATE `pays` SET `pending_resend_at`=? WHERE `id`=? AND `user_id`=? AND `state`='have_sent'");
    $stmt->bind_param('iii',$now,$id,$from_id);$stmt->execute();$stmt->close();
    $code=deltaEnsureTrackingCode($pay['hash_id']);
    sendToAdmins("🔔 سفارش <code>{$code}</code> در حال انتظار است؛ جهت تأیید آن اقدام نمایید.\n👤 کاربر: <code>{$from_id}</code>\n💰 مبلغ: <b>".number_format((int)$pay['price'])." تومان</b>",null,'HTML');
    alert('یادآوری برای مدیریت ارسال شد.');
    exit;
}
?>