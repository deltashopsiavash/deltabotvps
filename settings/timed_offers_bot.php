<?php

// Telegram UI and admin wizard for timed offers. This file is included from
// bot.php after the main-menu route and intentionally executes in bot scope.

if(!function_exists('timedOfferJsonKeyboard')){
    function timedOfferJsonKeyboard($rows){
        return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if(!function_exists('timedOfferAdminMenuKeyboard')){
    function timedOfferAdminMenuKeyboard(){
        $rows = [
            [
                ['text'=>'🔥 ساخت پیشنهاد ویژه','callback_data'=>'offerAdminCreate_special'],
                ['text'=>'⏱ ساخت فروش محدود','callback_data'=>'offerAdminCreate_limited'],
            ],
        ];
        foreach(timedOfferFetchRows(false,0,30) as $offer){
            $icon = ($offer['kind'] ?? '') === 'limited' ? '⏱' : '🔥';
            $title = trim((string)($offer['title'] ?? ''));
            if(function_exists('mb_substr')) $title=mb_substr($title,0,28,'UTF-8'); else $title=substr($title,0,28);
            $rows[] = [[
                'text'=>$icon.' #'.(int)$offer['id'].' | '.$title.' | '.timedOfferStatusLabel($offer),
                'callback_data'=>'offerAdminView'.(int)$offer['id'],
            ]];
        }
        $rows[] = [['text'=>'🔙 بازگشت به مدیریت','callback_data'=>'managePanel']];
        return timedOfferJsonKeyboard($rows);
    }
}

if(!function_exists('timedOfferAdminPlanKeyboard')){
    function timedOfferAdminPlanKeyboard($kind,$offset=0){
        global $connection;
        $kind=$kind==='limited'?'limited':'special'; $offset=max(0,(int)$offset); $limit=18;
        $res=$connection->query("SELECT p.`id`,p.`title`,p.`price`,p.`volume`,p.`days`,COALESCE(si.`title`,si.`remark`,CONCAT('Server #',p.`server_id`)) AS server_title FROM `server_plans` p LEFT JOIN `server_info` si ON si.`id`=p.`server_id` WHERE p.`active`=1 AND p.`price`>0 ORDER BY p.`id` DESC LIMIT {$limit} OFFSET {$offset}");
        $rows=[]; $count=0;
        if($res){
            while($plan=$res->fetch_assoc()){
                $count++;
                $label='#'.(int)$plan['id'].' | '.$plan['title'].' | '.number_format((int)$plan['price']).' تومان';
                $rows[]=[['text'=>$label,'callback_data'=>'offerAdminPick_'.$kind.'_'.(int)$plan['id']]];
            }
        }
        $nav=[];
        if($offset>0) $nav[]=['text'=>'⬅️ قبلی','callback_data'=>'offerAdminPlans_'.$kind.'_'.max(0,$offset-$limit)];
        if($count===$limit) $nav[]=['text'=>'بعدی ➡️','callback_data'=>'offerAdminPlans_'.$kind.'_'.($offset+$limit)];
        if($nav) $rows[]=$nav;
        $rows[]=[['text'=>'🔙 بازگشت','callback_data'=>'offerAdmin']];
        return timedOfferJsonKeyboard($rows);
    }
}

if(!function_exists('timedOfferAdminDetailKeyboard')){
    function timedOfferAdminDetailKeyboard($offer){
        $id=(int)$offer['id']; $active=($offer['status']??'')==='active';
        $rows=[
            [['text'=>$active?'⏸ توقف نمایش':'▶️ فعال‌سازی','callback_data'=>'offerAdminToggle'.$id]],
            [
                ['text'=>'🕐 ویرایش شروع','callback_data'=>'offerAdminEdit_start_'.$id],
                ['text'=>'🕚 ویرایش پایان','callback_data'=>'offerAdminEdit_end_'.$id],
            ],
            [['text'=>'🎯 ویرایش تخفیف','callback_data'=>'offerAdminEdit_discount_'.$id]],
        ];
        if(($offer['kind']??'')==='limited') $rows[]=[['text'=>'📦 ویرایش تعداد','callback_data'=>'offerAdminEdit_stock_'.$id]];
        $rows[]=[['text'=>'🗑 حذف از پیشنهادها','callback_data'=>'offerAdminArchiveAsk'.$id]];
        $rows[]=[['text'=>'🔙 بازگشت','callback_data'=>'offerAdmin']];
        return timedOfferJsonKeyboard($rows);
    }
}

if(!function_exists('timedOfferDraftStore')){
    function timedOfferDraftStore($draft,$nextStep){
        setUser(json_encode($draft,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'temp');
        setUser($nextStep);
    }
}

if(!function_exists('timedOfferDraftSummary')){
    function timedOfferDraftSummary($draft){
        global $connection;
        $pid=(int)($draft['plan_id']??0); $plan=null;
        $stmt=$connection->prepare("SELECT `title`,`price`,`volume`,`days` FROM `server_plans` WHERE `id`=? LIMIT 1");
        $stmt->bind_param('i',$pid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $stock=($draft['kind']??'')==='limited'?((int)($draft['stock_total']??0).' عدد'):'نامحدود';
        $base=(int)($plan['price']??0); $final=timedOfferDiscountedPrice($base,(int)($draft['discount_percent']??0));
        return "✅ <b>پیش‌نمایش پیشنهاد</b>\n\n".
            "نوع: <b>".(($draft['kind']??'')==='limited'?'فروش محدود':'پیشنهاد ویژه')."</b>\n".
            "عنوان: <b>".timedOfferEsc($draft['title']??'')."</b>\n".
            "پلن: <b>".timedOfferEsc($plan['title']??('#'.$pid))."</b>\n".
            "حجم/مدت: <b>".timedOfferEsc($plan['volume']??0)." گیگ / ".timedOfferEsc($plan['days']??0)." روز</b>\n".
            "تخفیف: <b>".(int)($draft['discount_percent']??0)."٪</b>\n".
            "قیمت نهایی: <b>".number_format($final)." تومان</b>\n".
            "شروع: <code>".timedOfferFormatDate($draft['start_at']??0)."</code>\n".
            "پایان: <code>".timedOfferFormatDate($draft['end_at']??0)."</code>\n".
            "ظرفیت: <b>{$stock}</b>\n\n".
            "بعد از رسیدن زمان شروع، دکمه به منوی اصلی اضافه می‌شود و در پایان زمان یا اتمام موجودی خودکار ناپدید می‌شود.";
    }
}

// ---------------- Customer-facing section ----------------
if($data==='timedOffers'){
    $offers=timedOfferGetActiveList(20);
    if(!$offers){
        smartSendOrEdit($message_id,'⌛️ در حال حاضر پیشنهاد فعالی وجود ندارد.',getMainKeys());
        exit;
    }
    if(count($offers)===1){
        $offer=$offers[0]; $id=(int)$offer['id'];
        $rows=[];
        if($offer['available_count']===null || (int)$offer['available_count']>0) $rows[]=[['text'=>'🛒 خرید با قیمت ویژه','callback_data'=>'timedOfferBuy'.$id]];
        else $rows[]=[['text'=>'⏳ موجودی موقتاً رزرو شده','callback_data'=>'timedOfferRefresh'.$id]];
        $rows[]=[['text'=>'🔄 بروزرسانی شمارش معکوس','callback_data'=>'timedOfferRefresh'.$id]];
        $rows[]=[['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']];
        smartSendOrEdit($message_id,timedOfferPublicText($offer),timedOfferJsonKeyboard($rows),'HTML');
        exit;
    }
    $rows=[];
    foreach($offers as $offer){
        $remaining=$offer['available_count']===null?'':(' | '.(int)$offer['available_count'].' باقی‌مانده');
        $rows[]=[['text'=>(($offer['kind']??'')==='limited'?'⏱ ':'🔥 ').$offer['title'].' | '.(int)$offer['discount_percent'].'٪'.$remaining,'callback_data'=>'timedOfferView'.(int)$offer['id']]];
    }
    $rows[]=[['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']];
    smartSendOrEdit($message_id,"🔥 <b>پیشنهادهای فعال</b>\n\nیکی از پیشنهادها را انتخاب کنید:",timedOfferJsonKeyboard($rows),'HTML');
    exit;
}

if(preg_match('/^timedOffer(?:View|Refresh)(\d+)$/',(string)$data,$m)){
    $offer=timedOfferGetActive((int)$m[1]);
    if(!$offer){
        alert('این پیشنهاد به پایان رسیده یا موجودی آن تمام شده است.',true);
        smartSendOrEdit($message_id,'⌛️ این پیشنهاد دیگر فعال نیست.',getMainKeys());
        exit;
    }
    $id=(int)$offer['id']; $rows=[];
    if($offer['available_count']===null || (int)$offer['available_count']>0) $rows[]=[['text'=>'🛒 خرید با قیمت ویژه','callback_data'=>'timedOfferBuy'.$id]];
    else $rows[]=[['text'=>'⏳ موجودی موقتاً رزرو شده','callback_data'=>'timedOfferRefresh'.$id]];
    $rows[]=[['text'=>'🔄 بروزرسانی شمارش معکوس','callback_data'=>'timedOfferRefresh'.$id]];
    $rows[]=[['text'=>'🔙 پیشنهادها','callback_data'=>'timedOffers']];
    smartSendOrEdit($message_id,timedOfferPublicText($offer),timedOfferJsonKeyboard($rows),'HTML');
    exit;
}

if(preg_match('/^timedOfferBuy(\d+)$/',(string)$data,$m)){
    $offer=timedOfferGetActive((int)$m[1]);
    if(!$offer){ alert('مهلت یا موجودی این پیشنهاد تمام شده است.',true); exit; }
    if($offer['available_count']!==null && (int)$offer['available_count']<=0){
        alert('همه موجودی فعلاً در رزرو پرداخت است؛ کمی بعد دوباره بررسی کنید.',true); exit;
    }
    if(($botState['sellState']??'off')!=='on' && $from_id!=$admin && empty($userInfo['isAdmin'])){
        alert('فروش ربات در حال حاضر غیرفعال است.',true); exit;
    }
    if(!userCanAccessServer($from_id,(int)$offer['server_id']) && $from_id!=$admin && empty($userInfo['isAdmin'])){
        alert('پلن این پیشنهاد برای حساب شما مجاز نیست.',true); exit;
    }
    // Continue into the existing, fully-tested subscription invoice flow.
    $data='selectPlan'.(int)$offer['plan_id'].'_'.(int)$offer['catid'].'_offer'.(int)$offer['id'];
}

// ---------------- Admin menu ----------------
$timedOfferIsAdmin = ($from_id==$admin || !empty($userInfo['isAdmin']));
if($data==='offerAdmin' && $timedOfferIsAdmin){
    setUser(); setUser('','temp');
    smartSendOrEdit($message_id,"🔥 <b>پیشنهاد ویژه و فروش محدود</b>\n\nاز این بخش می‌توانید پیشنهاد زمان‌دار بسازید؛ پایان زمان و اتمام موجودی به‌صورت خودکار اعمال می‌شود.",timedOfferAdminMenuKeyboard(),'HTML');
    exit;
}

if(preg_match('/^offerAdminCreate_(special|limited)$/',(string)$data,$m) && $timedOfferIsAdmin){
    smartSendOrEdit($message_id,'📦 پلنی را انتخاب کنید که پیشنهاد روی آن اعمال شود:',timedOfferAdminPlanKeyboard($m[1],0));
    exit;
}

if(preg_match('/^offerAdminPlans_(special|limited)_(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    smartSendOrEdit($message_id,'📦 انتخاب پلن:',timedOfferAdminPlanKeyboard($m[1],(int)$m[2]));
    exit;
}

if(preg_match('/^offerAdminPick_(special|limited)_(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $kind=$m[1]; $pid=(int)$m[2];
    $stmt=$connection->prepare("SELECT `id`,`title`,`price` FROM `server_plans` WHERE `id`=? AND `active`=1 AND `price`>0 LIMIT 1");
    $stmt->bind_param('i',$pid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$plan){ alert('این پلن فعال یا قابل فروش نیست.',true); exit; }
    timedOfferDraftStore(['kind'=>$kind,'plan_id'=>$pid],'offerNewTitle');
    delMessage();
    sendMessage("یک عنوان کوتاه برای پیشنهاد بفرستید.\n\nمثال: <code>50GB / 60 روزه</code>",$cancelKey,'HTML');
    exit;
}

if(($userInfo['step']??'')==='offerNewTitle' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $title=trim((string)$text); $len=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);
    if($title==='' || $len>160){ sendMessage('عنوان باید بین ۱ تا ۱۶۰ کاراکتر باشد.'); exit; }
    $draft=json_decode((string)($userInfo['temp']??''),true); if(!is_array($draft)) $draft=[]; $draft['title']=$title;
    timedOfferDraftStore($draft,'offerNewNote');
    sendMessage("یک توضیح کوتاه بفرستید.\nمثال: <code>فقط تا امشب</code>\n\nاگر توضیح نمی‌خواهید، فقط <code>-</code> بفرستید.",null,'HTML');
    exit;
}

if(($userInfo['step']??'')==='offerNewNote' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $note=trim((string)$text); if($note==='-') $note='';
    $len=function_exists('mb_strlen')?mb_strlen($note,'UTF-8'):strlen($note);
    if($len>1000){ sendMessage('توضیح حداکثر ۱۰۰۰ کاراکتر باشد.'); exit; }
    $draft=json_decode((string)($userInfo['temp']??''),true); $draft['note']=$note;
    timedOfferDraftStore($draft,'offerNewDiscount');
    sendMessage('درصد تخفیف را از ۱ تا ۹۹ بفرستید. مثال: <code>20</code>',null,'HTML');
    exit;
}

if(($userInfo['step']??'')==='offerNewDiscount' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $value=timedOfferNormalizeDigits(trim((string)$text));
    if(!ctype_digit($value) || (int)$value<1 || (int)$value>99){ sendMessage('فقط یک عدد بین ۱ تا ۹۹ بفرستید.'); exit; }
    $draft=json_decode((string)($userInfo['temp']??''),true); $draft['discount_percent']=(int)$value;
    timedOfferDraftStore($draft,'offerNewStart');
    sendMessage("زمان شروع را بفرستید.\n\n✅ برای شروع فوری: <code>الان</code>\n✅ شمسی: <code>1405/05/28 18:00</code>\n✅ میلادی: <code>2026-08-19 18:00</code>",null,'HTML');
    exit;
}

if(($userInfo['step']??'')==='offerNewStart' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $err=''; $start=timedOfferParseAdminDate($text,$err);
    if($start===false){ sendMessage('❌ '.$err); exit; }
    $draft=json_decode((string)($userInfo['temp']??''),true); $draft['start_at']=(int)$start;
    timedOfferDraftStore($draft,'offerNewEnd');
    sendMessage("زمان پایان را بفرستید.\nمثال: <code>1405/05/28 23:59</code>",null,'HTML');
    exit;
}

if(($userInfo['step']??'')==='offerNewEnd' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $err=''; $end=timedOfferParseAdminDate($text,$err); $draft=json_decode((string)($userInfo['temp']??''),true);
    if($end===false){ sendMessage('❌ '.$err); exit; }
    if((int)$end<=(int)($draft['start_at']??0) || (int)$end<=time()){ sendMessage('زمان پایان باید بعد از شروع و بعد از زمان فعلی باشد.'); exit; }
    $draft['end_at']=(int)$end;
    if(($draft['kind']??'')==='limited'){
        timedOfferDraftStore($draft,'offerNewStock');
        sendMessage('تعداد کل قابل فروش را بفرستید. مثال: <code>50</code>',null,'HTML');
    }else{
        $draft['stock_total']=null; timedOfferDraftStore($draft,'offerNewConfirm');
        sendMessage(timedOfferDraftSummary($draft),timedOfferJsonKeyboard([[['text'=>'✅ ذخیره و فعال کن','callback_data'=>'offerAdminSave']],[['text'=>'❌ انصراف','callback_data'=>'offerAdmin']]]),'HTML');
    }
    exit;
}

if(($userInfo['step']??'')==='offerNewStock' && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $value=timedOfferNormalizeDigits(trim((string)$text));
    if(!ctype_digit($value) || (int)$value<1 || (int)$value>1000000){ sendMessage('تعداد باید عددی بین ۱ تا ۱٬۰۰۰٬۰۰۰ باشد.'); exit; }
    $draft=json_decode((string)($userInfo['temp']??''),true); $draft['stock_total']=(int)$value;
    timedOfferDraftStore($draft,'offerNewConfirm');
    sendMessage(timedOfferDraftSummary($draft),timedOfferJsonKeyboard([[['text'=>'✅ ذخیره و فعال کن','callback_data'=>'offerAdminSave']],[['text'=>'❌ انصراف','callback_data'=>'offerAdmin']]]),'HTML');
    exit;
}

if($data==='offerAdminSave' && $timedOfferIsAdmin){
    $draft=json_decode((string)($userInfo['temp']??''),true);
    if(!is_array($draft) || empty($draft['plan_id']) || empty($draft['title']) || empty($draft['discount_percent']) || empty($draft['start_at']) || empty($draft['end_at'])){ alert('اطلاعات پیش‌نویس ناقص است.',true); exit; }
    $kind=($draft['kind']??'')==='limited'?'limited':'special'; $pid=(int)$draft['plan_id']; $title=(string)$draft['title']; $note=(string)($draft['note']??'');
    $discount=(int)$draft['discount_percent']; $start=(int)$draft['start_at']; $end=(int)$draft['end_at']; $creator=(int)$from_id; $now=time();
    if($kind==='limited'){
        $stock=(int)($draft['stock_total']??0);
        $stmt=$connection->prepare("INSERT INTO `timed_offers` (`kind`,`plan_id`,`title`,`note`,`discount_percent`,`start_at`,`end_at`,`stock_total`,`sold_count`,`status`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,0,'active',?,?,?)");
        $stmt->bind_param('sissiiiiiii',$kind,$pid,$title,$note,$discount,$start,$end,$stock,$creator,$now,$now);
    }else{
        $stmt=$connection->prepare("INSERT INTO `timed_offers` (`kind`,`plan_id`,`title`,`note`,`discount_percent`,`start_at`,`end_at`,`stock_total`,`sold_count`,`status`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,NULL,0,'active',?,?,?)");
        $stmt->bind_param('sissiiiiii',$kind,$pid,$title,$note,$discount,$start,$end,$creator,$now,$now);
    }
    $ok=$stmt && $stmt->execute(); $newId=$ok?(int)$stmt->insert_id:0; if($stmt) $stmt->close();
    if(!$ok){ sendMessage('❌ ذخیره پیشنهاد انجام نشد: '.timedOfferEsc($connection->error)); exit; }
    setUser(); setUser('','temp'); timedOfferMaintenance(true); alert('پیشنهاد ذخیره شد ✅');
    $offer=timedOfferGetAdmin($newId);
    smartSendOrEdit($message_id,timedOfferAdminText($offer),timedOfferAdminDetailKeyboard($offer),'HTML');
    exit;
}

if(preg_match('/^offerAdminView(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $offer=timedOfferGetAdmin((int)$m[1]);
    if(!$offer){ alert('پیشنهاد پیدا نشد.',true); exit; }
    smartSendOrEdit($message_id,timedOfferAdminText($offer),timedOfferAdminDetailKeyboard($offer),'HTML');
    exit;
}

if(preg_match('/^offerAdminToggle(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $offer=timedOfferGetAdmin((int)$m[1]); if(!$offer){ alert('پیشنهاد پیدا نشد.',true); exit; }
    $newStatus=($offer['status']??'')==='active'?'disabled':'active';
    if($newStatus==='active'){
        if((int)$offer['end_at']<=time()){ alert('ابتدا زمان پایان را به آینده تغییر دهید.',true); exit; }
        if($offer['stock_total']!==null && (int)$offer['sold_count']>=(int)$offer['stock_total']){ alert('ابتدا تعداد کل را افزایش دهید.',true); exit; }
    }
    $now=time(); $id=(int)$offer['id']; $stmt=$connection->prepare("UPDATE `timed_offers` SET `status`=?,`updated_at`=? WHERE `id`=?");
    $stmt->bind_param('sii',$newStatus,$now,$id); $stmt->execute(); $stmt->close(); timedOfferMaintenance(true);
    $offer=timedOfferGetAdmin($id); smartSendOrEdit($message_id,timedOfferAdminText($offer),timedOfferAdminDetailKeyboard($offer),'HTML');
    exit;
}

if(preg_match('/^offerAdminEdit_(start|end|discount|stock)_(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $offer=timedOfferGetAdmin((int)$m[2]); if(!$offer){ alert('پیشنهاد پیدا نشد.',true); exit; }
    $field=$m[1]; setUser('offerAdminEditValue_'.$field.'_'.(int)$offer['id']);
    if($field==='discount') $prompt='درصد تخفیف جدید را از ۱ تا ۹۹ بفرستید.';
    elseif($field==='stock') $prompt='تعداد کل جدید را بفرستید؛ نباید از فروش واقعی و رزروهای فعلی کمتر باشد.';
    else $prompt='زمان جدید را بفرستید. نمونه: <code>1405/05/28 23:59</code>';
    delMessage(); sendMessage($prompt,$cancelKey,'HTML'); exit;
}

if(preg_match('/^offerAdminEditValue_(start|end|discount|stock)_(\d+)$/',(string)($userInfo['step']??''),$m) && $timedOfferIsAdmin && $text!=($buttonValues['cancel']??'')){
    $field=$m[1]; $id=(int)$m[2]; $offer=timedOfferGetAdmin($id); if(!$offer){ sendMessage('پیشنهاد پیدا نشد.'); setUser(); exit; }
    $now=time(); $value=null;
    if($field==='start' || $field==='end'){
        $err=''; $value=timedOfferParseAdminDate($text,$err); if($value===false){ sendMessage('❌ '.$err); exit; }
        if($field==='start' && (int)$value>=(int)$offer['end_at']){ sendMessage('شروع باید قبل از پایان باشد.'); exit; }
        if($field==='end' && ((int)$value<=(int)$offer['start_at'] || (int)$value<=time())){ sendMessage('پایان باید بعد از شروع و بعد از زمان فعلی باشد.'); exit; }
    }else{
        $raw=timedOfferNormalizeDigits(trim((string)$text)); if(!ctype_digit($raw)){ sendMessage('فقط عدد بفرستید.'); exit; } $value=(int)$raw;
        if($field==='discount' && ($value<1 || $value>99)){ sendMessage('درصد باید بین ۱ تا ۹۹ باشد.'); exit; }
        if($field==='stock'){
            $minimum=(int)$offer['sold_count']+(int)$offer['reserved_count'];
            if($value<1 || $value<$minimum){ sendMessage("تعداد کل نمی‌تواند کمتر از {$minimum} باشد."); exit; }
        }
    }
    $column=['start'=>'start_at','end'=>'end_at','discount'=>'discount_percent','stock'=>'stock_total'][$field];
    $sql="UPDATE `timed_offers` SET `{$column}`=?,`updated_at`=? WHERE `id`=?"; $stmt=$connection->prepare($sql); $stmt->bind_param('iii',$value,$now,$id); $stmt->execute(); $stmt->close();
    $offer=timedOfferGetAdmin($id);
    if(in_array($offer['status'],['expired','sold_out'],true) && (int)$offer['end_at']>time() && ($offer['stock_total']===null || (int)$offer['sold_count']<(int)$offer['stock_total'])){
        $stmt=$connection->prepare("UPDATE `timed_offers` SET `status`='active',`updated_at`=? WHERE `id`=?"); $stmt->bind_param('ii',$now,$id); $stmt->execute(); $stmt->close();
    }
    setUser(); timedOfferMaintenance(true); $offer=timedOfferGetAdmin($id);
    sendMessage('✅ تغییر ذخیره شد.',$removeKeyboard); sendMessage(timedOfferAdminText($offer),timedOfferAdminDetailKeyboard($offer),'HTML'); exit;
}

if(preg_match('/^offerAdminArchiveAsk(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $id=(int)$m[1];
    smartSendOrEdit($message_id,"⚠️ پیشنهاد از منوی کاربران حذف شود؟\n\nسوابق فروش برای گزارش باقی می‌ماند.",timedOfferJsonKeyboard([[['text'=>'✅ بله، حذف شود','callback_data'=>'offerAdminArchiveDo'.$id]],[['text'=>'❌ انصراف','callback_data'=>'offerAdminView'.$id]]]));
    exit;
}

if(preg_match('/^offerAdminArchiveDo(\d+)$/',(string)$data,$m) && $timedOfferIsAdmin){
    $id=(int)$m[1]; $now=time(); $stmt=$connection->prepare("UPDATE `timed_offers` SET `status`='deleted',`updated_at`=? WHERE `id`=?");
    $stmt->bind_param('ii',$now,$id); $stmt->execute(); $stmt->close(); timedOfferMaintenance(true); alert('پیشنهاد حذف شد.');
    smartSendOrEdit($message_id,'🔥 مدیریت پیشنهادها',timedOfferAdminMenuKeyboard()); exit;
}

?>
