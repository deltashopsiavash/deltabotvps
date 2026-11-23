<?php
/** Customer and administrator interfaces for the DELTA seasonal campaign. */
function bfAdminKeyboard($c){
    $toggle=!empty($c['enabled'])?'⛔ توقف اضطراری جشنواره':'✅ فعال‌کردن جشنواره';
    return json_encode(['inline_keyboard'=>[
        [['text'=>$toggle,'callback_data'=>'bfToggle']],
        [['text'=>'✏️ یک‌ماهه: '.$c['one_percent'].'٪','callback_data'=>'bfEdit_one_percent'],
         ['text'=>'✏️ دوماهه: '.$c['two_percent'].'٪','callback_data'=>'bfEdit_two_percent']],
        [['text'=>'🎁 خرید اول: '.$c['new_percent'].'٪','callback_data'=>'bfEdit_new_percent'],
         ['text'=>'👥 ظرفیت جدید: '.$c['new_capacity'],'callback_data'=>'bfEdit_new_capacity']],
        [['text'=>'📅 زمان شروع: '.bfClock($c['starts_at']),'callback_data'=>'bfEdit_starts_at']],
        [['text'=>'📅 زمان پایان: '.bfClock($c['ends_at']),'callback_data'=>'bfEdit_ends_at']],
        [['text'=>'🎯 پلن‌های مشمول ('.(empty($c['eligible_plan_ids'])?'همه':count($c['eligible_plan_ids']).' عدد').')','callback_data'=>'bfEdit_eligible_plan_ids']],
        [['text'=>'💸 هزینه هر گیگ: '.number_format($c['gb_cost']),'callback_data'=>'bfEdit_gb_cost']],
        [['text'=>'📊 گزارش فروش و ظرفیت','callback_data'=>'bfReport']],
        [['text'=>'🔙 بازگشت به مدیریت','callback_data'=>'managePanel']]
    ]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function bfAdminMenu(){
    global $message_id;
    $c=bfConfig();
    $states=['disabled'=>'🔴 غیرفعال','upcoming'=>'🕒 آماده شروع','active'=>'🟢 فعال','ended'=>'⚫ پایان‌یافته'];
    $state=$states[bfWindow($c)]??'نامشخص';
    // No runtime SQL/schema creation on initial admin menu: keep it instant.
    // Live quota is available from the dedicated report button.
    $txt="🖤 <b>مدیریت جشنواره دلتا</b>\n\n".
        "وضعیت: <b>{$state}</b>\n".
        "شروع: <code>".bfClock($c['starts_at'])."</code>\n".
        "پایان: <code>".bfClock($c['ends_at'])."</code>\n".
        "⏰ تمام ساعت‌ها به وقت ایران هستند.\n\n".
        "یک‌ماهه: <b>{$c['one_percent']}٪</b>\n".
        "دوماهه: <b>{$c['two_percent']}٪</b>\n".
        "خرید اول یک‌ماهه یا دوماهه: <b>{$c['new_percent']}٪</b>\n".
        "ظرفیت کل خرید اول ویژه: <b>{$c['new_capacity']}</b> نفر\n".
        "📊 ظرفیت باقی‌مانده را در «گزارش فروش و ظرفیت» مشاهده کنید.\n\n".
        "تخفیف‌ها با کدهای دیگر جمع نمی‌شوند، روی قیمت اصلی محاسبه می‌شوند و قیمت دائمی پلن‌ها را تغییر نمی‌دهند.\n".
        "فقط پلن‌های عادی ۳۰ یا ۶۰ روزه مشمول‌اند؛ شارژ کیف پول، خرید سفارشی و نمایندگی مشمول نیستند.\n".
        "شناسه پلن‌های انتخاب‌شده: ".(empty($c['eligible_plan_ids'])?'همه پلن‌ها':implode(', ',$c['eligible_plan_ids']))."\n\n".
        "برای فعال‌شدن خودکار در زمان تعیین‌شده، ابتدا دکمه «فعال‌کردن جشنواره» را بزنید.";
    smartSendOrEdit($message_id,$txt,bfAdminKeyboard($c),'HTML');
}
function bfAdminReport(){
    global $message_id;
    $c=bfConfig();$s=bfSaleStats();$used=bfVipReservedCount();
    $txt="📊 <b>گزارش جشنواره بلک فرایدی</b>\n\n".
        "فروش‌های قطعی: <b>{$s['total']}</b>\n".
        "مشتری جدید با پیشنهاد ویژه: <b>{$s['new']}</b>\n".
        "ظرفیت رزرو یا مصرف‌شده: <b>{$used} / {$c['new_capacity']}</b>\n\n".
        "مبلغ فروش: <b>".number_format($s['revenue'])."</b> تومان\n".
        "قیمت عادی سفارش‌ها: ".number_format($s['regular'])." تومان\n".
        "مجموع تخفیف: ".number_format($s['discount'])." تومان\n".
        "حجم فروش‌رفته: ".number_format($s['gb'],1)." گیگ\n".
        "هزینه حجم با نرخ {$c['gb_cost']} تومان/گیگ: ".number_format($s['cost'])." تومان\n".
        "مانده پس از هزینه حجم: <b>".number_format($s['margin'])."</b> تومان\n\n".
        "⚠️ مانده بالا سود خالص نیست و هزینه ثابت سرور، پشتیبانی و کارمزد از آن کسر نشده است.";
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>'🔄 به‌روزرسانی گزارش','callback_data'=>'bfReport']],
        [['text'=>'🔙 تنظیمات جشنواره','callback_data'=>'bfAdmin']]
    ]],JSON_UNESCAPED_UNICODE);
    smartSendOrEdit($message_id,$txt,$keys,'HTML');
}
function bfLanding(){
    global $message_id,$isChildBot;
    if(!empty($isChildBot))return;
    $c=bfConfig();
    $status=bfWindow($c);
    if($status==='disabled' || $status==='ended'){
        smartSendOrEdit($message_id,'⌛ جشنواره بلک فرایدی در حال حاضر فعال نیست.',
            json_encode(['inline_keyboard'=>[[['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']]]],JSON_UNESCAPED_UNICODE));
        return;
    }
    $remaining=max(0,(int)$c['new_capacity']-bfVipReservedCount());
    $txt="🖤 <b>جشنواره بلک فرایدی دلتا</b>\n\n".
        "🔥 اشتراک یک‌ماهه: <b>{$c['one_percent']}٪ تخفیف</b>\n".
        "🔥 اشتراک دوماهه: <b>{$c['two_percent']}٪ تخفیف</b>\n".
        "🎁 خرید اول یک‌ماهه یا دوماهه: <b>{$c['new_percent']}٪</b> (ظرفیت باقی‌مانده: {$remaining})\n\n".
        "⏰ شروع: <code>".bfClock($c['starts_at'])."</code>\n".
        "⏰ پایان: <code>".bfClock($c['ends_at'])."</code>\n\n".
        "نیازی به کد تخفیف نیست؛ تخفیف موقع صدور فاکتور اعمال می‌شود.";
    if($status==='upcoming')$txt.="\n\n⏳ جشنواره هنوز شروع نشده است.";
    else $txt.="\n\n✅ جشنواره فعال است؛ همین حالا خرید یا تمدید کنید!";
    $keys=json_encode(['inline_keyboard'=>[
        [['text'=>'🛍 خرید اشتراک','callback_data'=>'buySubscription']],
        [['text'=>'🔄 تمدید اشتراک','callback_data'=>'mySubscriptions']],
        [['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']]
    ]],JSON_UNESCAPED_UNICODE);
    smartSendOrEdit($message_id,$txt,$keys,'HTML');
}
function bfMainMenuRow(){
    global $isChildBot;
    if(!empty($isChildBot))return null;
    $c=bfConfig();$status=bfWindow($c);
    if($status==='upcoming')return [['text'=>'🖤 بلک فرایدی دلتا | به‌زودی','callback_data'=>'bfLanding']];
    if($status==='active')return [['text'=>'🖤 بلک فرایدی دلتا | تا '.$c['new_percent'].'٪ تخفیف','callback_data'=>'bfLanding']];
    return null;
}
function bfHandleRequestCore(){
    global $data,$text,$userInfo,$from_id,$admin,$update;
    $cb=(string)($data??'');$step=(string)($userInfo['step']??'');
    if($cb==='bfLanding'){bfLanding();return true;}
    if(strpos($step,'bfEdit_')===0 && (trim((string)$text)==='/start' || $cb==='mainMenu' || $cb==='managePanel')){
        setUser('none','step');return false;
    }
    if(strpos($cb,'bf')!==0 && strpos($step,'bfEdit_')!==0)return false;
    $allowed=(int)$from_id===(int)$admin || !empty($userInfo['isAdmin']);
    if(!$allowed){if(isset($update->callback_query))answerQuery('دسترسی ندارید.',true);return true;}
    if($cb==='bfAdmin'){bfAdminMenu();return true;}
    if($cb==='bfReport'){bfAdminReport();return true;}
    if($cb==='bfToggle'){
        $c=bfConfig();$c['enabled']=empty($c['enabled'])?1:0;
        if($c['ends_at']<=$c['starts_at']){answerQuery('زمان پایان باید بعد از شروع باشد.',true);return true;}
        bfSave($c);bfAdminMenu();return true;
    }
    $fields=['one_percent','two_percent','new_percent','new_capacity','starts_at','ends_at','gb_cost','eligible_plan_ids'];
    if(preg_match('/^bfEdit_(\w+)$/D',$cb,$m) && in_array($m[1],$fields,true)){
        $field=$m[1];setUser('bfEdit_'.$field,'step');
        if($field==='eligible_plan_ids')$hint="🎯 شناسه پلن‌هایی که تخفیف می‌گیرند را با کاما بفرستید (مثال: 12, 15, 23).\nبرای همه پلن‌های یک‌ماهه و دوماهه، بنویسید: all";
        elseif(in_array($field,['starts_at','ends_at'],true))
            $hint="📅 تاریخ و ساعت جدید را به وقت ایران وارد کنید.\nمثال: 2026-11-27 00:00";
        elseif($field==='gb_cost')$hint="💰 هزینه تمام‌شده هر گیگ (تومان) را عددی وارد کنید.";
        elseif($field==='new_capacity')$hint="👥 ظرفیت پیشنهاد ویژه را عددی از ۰ تا ۱۰۰۰۰ وارد کنید.";
        else $hint="🎯 درصد جدید تخفیف را به صورت عددی از ۰ تا ۶۰ ارسال کنید.";
        sendMessage($hint,json_encode(['inline_keyboard'=>[[['text'=>'❌ انصراف','callback_data'=>'bfAdmin']]]],JSON_UNESCAPED_UNICODE));
        return true;
    }
    if(preg_match('/^bfEdit_(\w+)$/D',$step,$m) && in_array($m[1],$fields,true)){
        if($cb!=='' && $cb!=='bfAdmin'){setUser('none','step');return false;}
        if(!isset($update->message->text))return true;
        $field=$m[1];$input=trim((string)$text);$c=bfConfig();
        if($field==='eligible_plan_ids'){
            $ids=bfPlanIdInput($input);
            if($ids===null){sendMessage('❌ شناسه‌ها معتبر نیستند؛ اعداد را با کاما جدا کنید یا all بفرستید.');return true;}
            $c['eligible_plan_ids']=$ids;
        }elseif(in_array($field,['starts_at','ends_at'],true)){
            $n=bfParseClock($input);
            if($n===null){sendMessage('❌ تاریخ معتبر نیست. مثال: 2026-11-27 00:00');return true;}
            $c[$field]=$n;
            if((int)$c['ends_at']<=(int)$c['starts_at']){
                sendMessage('❌ زمان پایان باید از شروع جلوتر باشد. دوباره وارد کنید.');return true;
            }
        }else{
            if(!preg_match('/^\d+$/D',$input)){sendMessage('❌ لطفاً فقط عدد بفرستید.');return true;}
            $n=(int)$input;
            $max=in_array($field,['one_percent','two_percent','new_percent'],true)?60:
                ($field==='new_capacity'?10000:1000000);
            if($n>$max){sendMessage('❌ حداکثر مجاز: '.$max);return true;}
            $c[$field]=$n;
        }
        bfSave($c);
        setUser('none','step');
        sendMessage('✅ تنظیمات جشنواره ذخیره شد.');
        bfAdminMenu();
        return true;
    }
    return false;
}


/**
 * Catch festival admin errors at the webhook boundary. Even if MySQL rejects
 * a query, the manager receives a diagnostic instead of an unresponsive button.
 */
function bfHandleRequest(){
    global $data,$text,$userInfo,$update;
    $cb=(string)($data??'');
    $step=(string)($userInfo['step']??'');
    if($cb!=='bfLanding' && strpos($cb,'bf')!==0 && strpos($step,'bfEdit_')!==0)return false;
    try{
        if(strpos($cb,'bf')===0 && isset($update->callback_query->id)){
            // Close Telegram's spinner before any SQL work.
            bot('answerCallbackQuery',['callback_query_id'=>$update->callback_query->id]);
        }
        return bfHandleRequestCore();
    }catch(Throwable $e){
        $code='BF-'.strtoupper(substr(sha1(get_class($e).':'.$e->getMessage()),0,8));
        error_log('DELTA black friday '.$code.': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
        try{
            sendMessage("❌ بخش مدیریت بلک فرایدی هنگام اجرا خطا داد.\n".
                "کد خطا: <code>".$code."</code>\n".
                "از روی این کد لاگ PHP سرور قابل بررسی است. خریدهای معمولی تغییری نکرده‌اند.",
                null,'HTML');
        }catch(Throwable $notifyError){
            error_log('DELTA black friday notification failed: '.$notifyError->getMessage());
        }
        return true;
    }
}
