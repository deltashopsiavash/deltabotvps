<?php
function deltaSmsReviewKeys(array $rows): string {return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);}
function deltaSmsReviewEsc($s): string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function deltaSmsReviewRender(string $text,array $rows): void {
    global $message_id,$data;
    if(!is_string($data)||!str_starts_with($data,'smsReview'))sendMessage($text,deltaSmsReviewKeys($rows),'HTML');
    else smartSendOrEdit($message_id,$text,deltaSmsReviewKeys($rows),'HTML');
}
function deltaSmsReviewInvoiceText(array $p): string {
    $h=(string)$p['hash_id'];$uid=(int)$p['user_id'];
    return "🔖 کد پیگیری: <code>".deltaTrackingCode($h)."</code>\n👤 مشتری: <code>$uid</code>\n💰 مبلغ: ".number_format((int)$p['price'])." تومان\n🧾 نوع: ".deltaSmsReviewEsc($p['type']??'-')."\n📌 وضعیت: ".deltaSmsReviewEsc($p['state'])."\n🕒 ایجاد: ".gmdate('Y-m-d H:i:s',(int)$p['request_date'])." UTC";
}
function deltaSmsReviewHandle(string $data): void {
    global $connection,$from_id,$admin,$isChildBot;
    if(!empty($isChildBot)||(string)$from_id!==(string)$admin)return;
    if(!deltaCardEnsureTable()||!deltaSmsReviewSchema()){alert('جدول مدیریت پیامکی آماده نیست.',true);return;}
    $home=[['text'=>'بازگشت به مدیریت پیامکی','callback_data'=>'smsReviewHome']];
    if($data==='smsReviewHome'){
        $mode=deltaReceiptApprovalMode(json_decode((string)getSettingValue('BOT_STATES','{}'),true)?:[]);
        $r=$connection->query("SELECT COUNT(*) AS c FROM pays p WHERE p.state IN ('pending','have_sent') AND EXISTS(SELECT 1 FROM payment_card_displays d WHERE BINARY d.hash_id=BINARY p.hash_id)");
        $count=(int)($r->fetch_assoc()['c']??0);
        deltaSmsReviewRender("📲 <b>مدیریت تأیید پیامکی ربات مادر</b>\n\nحالت فعلی: ".deltaSmsReviewEsc($mode)."\nفاکتورهای کارت‌به‌کارت باز: $count\n\nمبلغ فاکتور تغییر نمی‌کند. پیامک هم‌مبلغ مبهم نیاز به بررسی مالک دارد. کد پیگیری، رسید را به فاکتور متصل می‌کند نه به بانک.",[
            [['text'=>'📋 فاکتورهای باز / بررسی و رد','callback_data'=>'smsReviewList0']],
            [['text'=>'🔎 جستجو با کد پیگیری','callback_data'=>'smsReviewTrackAsk']],
            [['text'=>'📨 پیامک‌های ثبت‌شده اخیر','callback_data'=>'smsReviewEvents0']],
            [['text'=>'🔄 ریست مبدأ پیامکی','callback_data'=>'smsReviewResetAsk']],
            [['text'=>'📊 گزارش عملیات مدیریت','callback_data'=>'smsReviewLog0']],
            [['text'=>'بازگشت به تنظیمات','callback_data'=>'botSettings']]
        ]);return;
    }
    if($data==='smsReviewTrackAsk'){
        global $cancelKey;
        setUser('smsReviewTrack');sendMessage('کد پیگیری ۸ رقمی فاکتور را ارسال کنید.',$cancelKey);return;
    }
    if(preg_match('/^smsReviewList(\d{1,6})$/D',$data,$m)){
        $page=(int)$m[1];$offset=$page*8;
        $s=$connection->prepare("SELECT p.* FROM pays p WHERE p.state IN ('pending','have_sent') AND EXISTS(SELECT 1 FROM payment_card_displays d WHERE BINARY d.hash_id=BINARY p.hash_id) ORDER BY (p.state='have_sent') DESC,p.id DESC LIMIT 9 OFFSET ?");
        $s->bind_param('i',$offset);$s->execute();$items=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $rows=[];$more=count($items)>8;
        foreach(array_slice($items,0,8) as $p){
            $rows[]=[['text'=>($p['state']==='have_sent'?'📩 ':'⏳ ').deltaTrackingCode($p['hash_id']).' | '.number_format((int)$p['price']).' تومان','callback_data'=>'smsReviewInvoice'.(int)$p['id']]];
        }
        $nav=[];if($page>0)$nav[]=['text'=>'قبلی','callback_data'=>'smsReviewList'.($page-1)];if($more)$nav[]=['text'=>'بعدی','callback_data'=>'smsReviewList'.($page+1)];if($nav)$rows[]=$nav;$rows[]=$home;
        deltaSmsReviewRender("📋 فاکتورهای کارت‌به‌کارت\n📩 رسید ارسال شده | ⏳ منتظر رسید\nصفحه ".($page+1),$rows);return;
    }
    if(preg_match('/^smsReviewInvoice(\d+)$/D',$data,$m)){
        $id=(int)$m[1];$p=deltaSmsReviewPay($id);if(!$p){alert('فاکتور پیدا نشد.',true);return;}
        $b=deltaSmsReviewBinding($p);$rows=[];$text=deltaSmsReviewInvoiceText($p);
        if($b&&!empty($b['event_id']))$text.="\n\nاین فاکتور پیامک رزروشده یا مصرف‌شده دارد؛ دوباره تأیید نکنید.";
        elseif($b&&$p['state']==='have_sent'){
            $events=deltaSmsReviewCandidates($p,$b);
            $text.="\n\nپیامک‌های احتمالی استفاده‌نشده: ".count($events)."\nفقط پس از تطبیق رسید و تراکنش واقعی انتخاب کنید.";
            foreach(array_slice($events,0,8,true) as $e){
                $rows[]=[['text'=>strtoupper($e['bank']).' | '.gmdate('m/d H:i:s',(int)$e['received_at']+12600).' ایران','callback_data'=>'smsReviewLinkAsk'.$id.'_'.substr($e['event_id'],0,16)]];
            }
        }else $text.="\n\nپیامک قابل اتصال ثبت نشده یا رسید هنوز ارسال نشده است.";
        if(in_array($p['state'],['pending','have_sent'],true)&&(!$b||empty($b['event_id'])))$rows[]=[['text'=>'❌ رد این فاکتور پس از بررسی','callback_data'=>'smsReviewRejectAsk'.$id]];
        $rows[]=[['text'=>'فهرست فاکتورها','callback_data'=>'smsReviewList0']];$rows[]=$home;
        deltaSmsReviewRender($text,$rows);return;
    }
    if(preg_match('/^smsReviewLink(Ask|Do)(\d+)_([a-f0-9]{16})$/D',$data,$m)){
        $id=(int)$m[2];$prefix=$m[3];$p=deltaSmsReviewPay($id);
        if(!$p){alert('فاکتور پیدا نشد.',true);return;}
        if($m[1]==='Ask'){
            $b=deltaSmsReviewBinding($p);$matches=$b?array_filter(deltaSmsReviewCandidates($p,$b),static function($e)use($prefix){return substr($e['event_id'],0,16)===$prefix;}):[];
            if(count($matches)!==1){alert('پیامک دیگر در دسترس نیست.',true);return;}$e=reset($matches);
            deltaSmsReviewRender(deltaSmsReviewInvoiceText($p)."\n\n🏦 بانک: ".deltaSmsReviewEsc($e['bank'])."\n💰 واریز: ".number_format((int)$e['amount_irr'])." ریال\n🕒 ".gmdate('Y-m-d H:i:s',(int)$e['received_at']+12600)." ایران\n🔍 حساب: ".deltaSmsReviewEsc($e['account_hint']??'-')."\n\n⚠️ تأیید شما یعنی مالکیت این واریز را با رسید مشتری بررسی کرده‌اید. این پیامک دیگر برای فاکتور دیگری قابل استفاده نیست.",[
                [['text'=>'✅ تطبیق را بررسی کردم؛ ارسال برای تحویل','callback_data'=>'smsReviewLinkDo'.$id.'_'.$prefix]],
                [['text'=>'انصراف','callback_data'=>'smsReviewInvoice'.$id]]
            ]);return;
        }
        if(!deltaSmsReviewQueue($id,$prefix,(int)$from_id)){alert('اتصال انجام نشد؛ پیامک یا فاکتور دوباره بررسی شود.',true);return;}
        sendToAdmins("📲 اتصال دستی پیامک به فاکتور ثبت شد؛ در صف تحویل ربات مادر.\n".deltaSmsReviewInvoiceText($p),null,'HTML');
        deltaSmsReviewRender('✅ پیامک رزرو و درخواست تحویل ثبت شد؛ نتیجه تحویل جداگانه گزارش می‌شود.',[$home]);return;
    }
    if(preg_match('/^smsReviewReject(Ask|Do)(\d+)$/D',$data,$m)){
        $id=(int)$m[2];$p=deltaSmsReviewPay($id);if(!$p){alert('فاکتور پیدا نشد.',true);return;}
        if($m[1]==='Ask'){
            deltaSmsReviewRender(deltaSmsReviewInvoiceText($p)."\n\n⚠️ فقط اگر مطمئن هستید این فاکتور پرداخت نشده یا تکراری است رد کنید. اگر پول واقعی دریافت شده، ابتدا آن را تعیین تکلیف کنید.",[
                [['text'=>'❌ تأیید رد؛ واریزی متعلق به این فاکتور نیست','callback_data'=>'smsReviewRejectDo'.$id]],
                [['text'=>'انصراف','callback_data'=>'smsReviewInvoice'.$id]]
            ]);return;
        }
        $p=deltaSmsReviewReject($id,(int)$from_id);
        if(!$p){alert('رد انجام نشد؛ فاکتور یا پرداخت قبلاً بررسی شده است.',true);return;}
        $reason='فاکتور پرداخت‌نشده یا تکراری پس از بررسی مدیریت';
        $keys=deltaSmsReviewKeys([[['text'=>'❌ رد شد','callback_data'=>'deltach']]]);
        foreach(deltaReceiptAdminMessageTargets($p) as [$chat,$msg])editKeys($keys,$msg,$chat);
        sendMessage(deltaAppendTracking("❌ فاکتور شما توسط مدیریت رد شد.\nعلت: $reason\nاگر واریز واقعی انجام داده‌اید، با پشتیبانی تماس بگیرید.",$p['hash_id']),null,'HTML',(int)$p['user_id']);
        sendToAdmins("❌ گزارش رد فاکتور پیامکی\n".deltaSmsReviewInvoiceText($p)."\n📝 علت: $reason\n👤 مدیر: <code>".(int)$from_id."</code>\n📌 وضعیت جدید: declined",null,'HTML');
        deltaSmsReviewRender('❌ فاکتور رد شد؛ گزارش مدیریت و پیام مشتری ارسال شد.',[$home]);return;
    }
    if($data==='smsReviewResetAsk'){
        deltaSmsReviewRender("🔄 <b>ریست مبدأ پیامکی</b>\nفقط فاکتورهای تازه برای تأیید خودکار بررسی می‌شوند. هیچ رسید، واریزی، فاکتور یا شناسه پیامک مصرف‌شده پاک یا رد نمی‌شود. واریزی قدیمی هنوز ممکن است متعلق به فاکتور قدیمی باشد؛ موارد مبهم باید دستی تعیین تکلیف شوند.",[
            [['text'=>'تأیید ریست مبدأ','callback_data'=>'smsReviewResetDo']],$home
        ]);return;
    }
    if($data==='smsReviewResetDo'){
        if(!deltaSmsReviewReset((int)$from_id)){alert('ریست انجام نشد.',true);return;}
        sendToAdmins("🔄 مبدأ تأیید پیامکی توسط مدیر <code>".(int)$from_id."</code> ریست شد. هیچ فاکتوری رد یا حذف نشد.\nزمان UTC: ".gmdate('Y-m-d H:i:s'),null,'HTML');
        deltaSmsReviewRender('✅ مبدأ ریست شد؛ رسیدهای قدیمی در فهرست مدیریت باقی هستند.',[$home]);return;
    }
    if(preg_match('/^smsReviewEvents(\d{1,6})$/D',$data,$m)){
        $page=(int)$m[1];$events=deltaSmsApprovalEvents();usort($events,static function($a,$b){return ($b['received_at']??0)<=>($a['received_at']??0);});
        $text="📨 پیامک‌های اخیر (حدود دو ساعت)\n";
        foreach(array_slice($events,$page*10,10) as $e){
            $text.="\n".deltaSmsReviewEsc($e['bank']??'-')." | ".number_format((int)($e['amount_irr']??0))." ریال | ".gmdate('H:i:s',(int)($e['received_at']??0)+12600)."\n".(deltaSmsReviewEventUsed((string)$e['event_id'])?'رزرو/مصرف‌شده':'بدون اتصال ثبت‌شده (پرداخت دستی قبلی را بررسی کنید)')." | ".deltaSmsReviewEsc($e['source']??'-')."\n";
        }
        $rows=[];$nav=[];if($page>0)$nav[]=['text'=>'قبلی','callback_data'=>'smsReviewEvents'.($page-1)];if(count($events)>($page+1)*10)$nav[]=['text'=>'بعدی','callback_data'=>'smsReviewEvents'.($page+1)];if($nav)$rows[]=$nav;$rows[]=$home;
        deltaSmsReviewRender($text,$rows);return;
    }
    if(preg_match('/^smsReviewLog(\d{1,6})$/D',$data,$m)){
        $page=(int)$m[1];$offset=$page*8;$s=$connection->prepare('SELECT * FROM delta_sms_admin_audit ORDER BY id DESC LIMIT 9 OFFSET ?');$s->bind_param('i',$offset);$s->execute();$items=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        $text="📊 گزارش ثبت‌شده مدیریت پیامکی\n";
        foreach(array_slice($items,0,8) as $r){
            $text.="\n".deltaSmsReviewEsc($r['action'])." | ".deltaSmsReviewEsc($r['status'])." | ".gmdate('m/d H:i',(int)$r['created_at']+12600)."\nمدیر: <code>".(int)$r['actor_id']."</code>";
            if($r['hash_id']!=='')$text.=" | پیگیری: <code>".deltaTrackingCode($r['hash_id'])."</code> | ".number_format((int)$r['amount_irr'])." ریال";
            $text.="\n";
        }
        $rows=[];$nav=[];if($page>0)$nav[]=['text'=>'قبلی','callback_data'=>'smsReviewLog'.($page-1)];if(count($items)>8)$nav[]=['text'=>'بعدی','callback_data'=>'smsReviewLog'.($page+1)];if($nav)$rows[]=$nav;$rows[]=$home;
        deltaSmsReviewRender($text,$rows);return;
    }
    alert('درخواست معتبر نیست.',true);
}
