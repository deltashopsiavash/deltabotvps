<?php
/**
 * DELTA Black Friday fast storefront and Telegram-based per-plan selector.
 * No new database tables. Plan selection lives in DELTA_BLACK_FRIDAY_V1.
 * Opening the shop never produces a payment; it redirects the chosen plan
 * into the battle-tested normal single-service checkout with buyType='bf'.
 */
function bfGroupPeriod($group){
    if($group==='one')return [28,31,'یک‌ماهه'];
    if($group==='two')return [58,62,'دوماهه'];
    return null;
}
function bfPlanLabel($p){
    $name=trim((string)($p['title']??''));
    $vol=(float)($p['volume']??0);
    $size=$vol>0?number_format($vol,($vol==floor($vol)?0:1)).' گیگ':'نامحدود';
    return $size.' | '.($name!==''?mb_substr($name,0,45):'پلن #'.(int)$p['id']);
}
function bfSelectedCount($cfg,$group){
    if(!bfGroupPeriod($group))return 0;
    $ids=array_values(array_filter($cfg['eligible_plan_ids']??[],function($id){return (int)$id>0;}));
    if(!$ids)return 0;
    global $connection;
    $numbers=implode(',',array_map('intval',$ids));
    [$lo,$hi]=bfGroupPeriod($group);
    $q=$connection->query("SELECT COUNT(*) AS n FROM server_plans WHERE id IN ($numbers) AND active=1 AND price>0 AND days BETWEEN $lo AND $hi");
    return (int)($q?$q->fetch_assoc()['n']:0);
}
function bfListPlanRows($group,$page=0,$selectedOnly=false,$cfg=null){
    global $connection;
    $range=bfGroupPeriod($group);
    if(!$range)return ['total'=>0,'items'=>[],'page'=>0,'per_page'=>8];
    [$lo,$hi]=$range;
    $page=max(0,min((int)$page,99));$limit=8;$offset=$page*$limit;
    $where="sp.active=1 AND sp.price>0 AND sp.days BETWEEN $lo AND $hi";
    if($selectedOnly){
        $cfg=$cfg??bfConfig();
        $ids=array_values(array_filter(array_map('intval',$cfg['eligible_plan_ids']??[]),function($id){return $id>0;}));
            if(!$ids)return ['total'=>0,'items'=>[],'page'=>$page,'per_page'=>8];
        $where.=" AND sp.id IN (".implode(',',$ids).") AND si.active=1 AND si.state=1
          AND ( (sp.inbound_id=0 AND si.ucount>0) OR (sp.inbound_id<>0 AND sp.acount>0))";
    }
    $ct=$connection->query("SELECT COUNT(*) AS n FROM server_plans sp
      LEFT JOIN server_info si ON si.id=sp.server_id WHERE $where");
    $total=(int)($ct?$ct->fetch_assoc()['n']:0);
    $q=$connection->query("SELECT sp.id,sp.title,sp.days,sp.volume,sp.price,sp.catid,sp.server_id,
         sp.acount,sp.inbound_id,si.title AS server_title
         FROM server_plans sp LEFT JOIN server_info si ON si.id=sp.server_id
         WHERE $where ORDER BY sp.volume ASC,sp.price ASC,sp.id ASC LIMIT $limit OFFSET $offset");
    $items=[];
    if($q)while($item=$q->fetch_assoc())$items[]=$item;
    return ['total'=>$total,'items'=>$items,'page'=>$page,'per_page'=>$limit];
}
function bfLoadPlan($id){
    global $connection;
    $id=(int)$id;if($id<1)return null;
    $st=$connection->prepare("SELECT sp.id,sp.title,sp.days,sp.volume,sp.price,sp.catid,
       sp.server_id,sp.active,sp.inbound_id,sp.acount,
       si.title AS server_title,si.active AS server_active,si.state AS server_state,si.ucount
       FROM server_plans sp LEFT JOIN server_info si ON si.id=sp.server_id WHERE sp.id=? LIMIT 1");
    if(!$st)return null;
    $st->bind_param('i',$id);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    return $row?:null;
}
function bfIsCheckoutPlanAllowed($id,$user,$cfg=null){
    global $isChildBot,$botState,$admin;
    if(!empty($isChildBot)||($botState['sellState']??'off')!=='on' && (int)$user!==(int)$admin)return false;
    $cfg=$cfg??bfConfig();
    if(bfWindow($cfg)!=='active')return false;
    if(!in_array((int)$id,$cfg['eligible_plan_ids'],true))return false;
    $plan=bfLoadPlan($id);
    if(!$plan || (int)$plan['active']!==1 || (int)$plan['price']<=0
        || !bfDurationGroup($plan['days']))return false;
    if((int)$plan['server_active']!==1 || (int)$plan['server_state']!==1)return false;
    if((int)$plan['inbound_id']===0 && (int)$plan['ucount']<=0)return false;
    if((int)$plan['inbound_id']!==0 && (int)$plan['acount']<=0)return false;
    if(function_exists('userCanAccessServer') && (int)$user!==(int)$admin
       && !userCanAccessServer((int)$user,(int)$plan['server_id']))return false;
    return true;
}
function bfShowPlanPicker($group,$page=0){
    global $message_id;
    $period=bfGroupPeriod($group);if(!$period)return;
    $cfg=bfConfig();$data=bfListPlanRows($group,$page,false,$cfg);
    $selected=$cfg['eligible_plan_ids']??[];
    $keys=[];
    foreach($data['items'] as $plan){
        $id=(int)$plan['id'];
        $tick=in_array($id,$selected,true)?'✅':'⬜';
        $name=bfPlanLabel($plan);
        $server=mb_substr((string)($plan['server_title']??'سرور نامشخص'),0,20);
        $price=number_format((int)$plan['price']);
        $keys[]=[['text'=>"$tick $name | $server | #$id | $price",'callback_data'=>"bfPick_{$group}_{$id}_{$page}"]];
    }
    $pages=(int)ceil($data['total']/$data['per_page']);
    $nav=[];
    if($page>0)$nav[]=['text'=>'⬅️ قبلی','callback_data'=>'bfPlans_'.$group.'_'.($page-1)];
    if($page+1<$pages)$nav[]=['text'=>'بعدی ➡️','callback_data'=>'bfPlans_'.$group.'_'.($page+1)];
    if($nav)$keys[]=$nav;
    $keys[]=[['text'=>'🔙 مدیریت جشنواره','callback_data'=>'bfAdmin']];
    $count=bfSelectedCount($cfg,$group);
    $head="🎯 <b>انتخاب پلن‌های ".$period[2]."</b>\n\n".
         "روی هر پلن بزن تا ✅ انتخاب یا حذف شود.\n".
         "📦 تعداد انتخاب‌شده این مدت: <b>$count</b>\n".
         "📄 صفحه ".($page+1)." از ".max(1,$pages)."\n".
         "⚠️ فقط پلن‌های تیک‌خورده در فروشگاه جشنواره نمایش داده می‌شوند.";
    if(!$data['items'])$head.="\n\nدر این مدت پلن فعالی برای انتخاب پیدا نشد.";
    smartSendOrEdit($message_id,$head,json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
}
function bfToggleSelectedPlan($group,$id,$page){
    $plan=bfLoadPlan($id);
    if(!$plan || (int)$plan['active']!==1 || (int)$plan['price']<=0
       || bfDurationGroup($plan['days'])!==$group){
        sendMessage('❌ این پلن برای مدت انتخاب‌شده معتبر یا فعال نیست.');return;
    }
    $cfg=bfConfig();$ids=$cfg['eligible_plan_ids']??[];
    $id=(int)$id;
    if(in_array($id,$ids,true))$ids=array_values(array_diff($ids,[$id]));
    else $ids[]=$id;
    $cfg['eligible_plan_ids']=array_values(array_unique(array_map('intval',$ids)));
    if(!bfSave($cfg)){sendMessage('❌ ذخیره انتخاب پلن انجام نشد.');return;}
    bfShowPlanPicker($group,$page);
}
function bfStorefrontLanding(){
    global $isChildBot,$message_id,$botState;
    if(!empty($isChildBot))return;
    $cfg=bfConfig();$status=bfWindow($cfg);
    if($status==='disabled'||$status==='ended'){
        smartSendOrEdit($message_id,'⌛️ فروشگاه بلک فرایدی فعلاً فعال نیست.',
        json_encode(['inline_keyboard'=>[[['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']]]],JSON_UNESCAPED_UNICODE));return;
    }
    $title=$status==='active'?'🟢 جشنواره فعال است':'🕒 جشنواره به‌زودی شروع می‌شود';
    $txt="🖤 <b>فروشگاه اختصاصی بلک فرایدی دلتا</b>\n\n$title\n".
      "✅ فقط خرید اشتراک جدید | تمدید شامل تخفیف نیست.\n".
      "🔥 یک‌ماهه: {$cfg['one_percent']}٪ | دوماهه: {$cfg['two_percent']}٪\n".
      "🎁 خرید اول ۳۰ مشتری جدید: {$cfg['new_percent']}٪ (در صورت ظرفیت)\n".
      "⏰ پایان: <code>".bfClock($cfg['ends_at'])."</code>\n\n".
      "یکی از دو مدت اشتراک را انتخاب کن؛ مستقیم پلن‌های تخفیف‌خورده نمایش داده می‌شوند.";
    $keys=['inline_keyboard'=>[
        [['text'=>'📦 اشتراک یک‌ماهه','callback_data'=>'bfCatalog_one_0']],
        [['text'=>'📦 اشتراک دوماهه','callback_data'=>'bfCatalog_two_0']],
        [['text'=>'🔙 منوی اصلی','callback_data'=>'mainMenu']]
    ]];
    smartSendOrEdit($message_id,$txt,json_encode($keys,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
}
function bfShowCatalog($group,$page=0){
    global $message_id,$from_id,$admin,$userInfo;
    $period=bfGroupPeriod($group);if(!$period)return;
    $cfg=bfConfig();$state=bfWindow($cfg);
    if($state==='disabled'||$state==='ended'){bfStorefrontLanding();return;}
    $data=bfListPlanRows($group,$page,true,$cfg);
    $keys=[];$shown=0;
    foreach($data['items'] as $p){
        if((int)$from_id!==(int)$admin &&
           function_exists('userCanAccessServer') && !userCanAccessServer($from_id,(int)$p['server_id']))continue;
        $id=(int)$p['id'];
        $percentage=(int)$cfg[$group==='one'?'one_percent':'two_percent'];
        $price=bfPriceWithPercent((int)$p['price'],$percentage);
        $name=bfPlanLabel($p);
        $keys[]=[['text'=>"🛍 $name | ".number_format($price)." تومان",'callback_data'=>'bfBuy_'.$id]];
        $shown++;
    }
    $pages=(int)ceil($data['total']/$data['per_page']);
    $nav=[];
    if($page>0)$nav[]=['text'=>'⬅️ قبلی','callback_data'=>'bfCatalog_'.$group.'_'.($page-1)];
    if($page+1<$pages)$nav[]=['text'=>'بعدی ➡️','callback_data'=>'bfCatalog_'.$group.'_'.($page+1)];
    if($nav)$keys[]=$nav;
    $keys[]=[['text'=>'🔙 انتخاب مدت اشتراک','callback_data'=>'bfLanding']];
    $txt="🖤 <b>پلن‌های منتخب ".$period[2]." بلک فرایدی</b>\n\n".
      "قیمت‌های فهرست با تخفیف {$cfg[$group==='one'?'one_percent':'two_percent']}٪ هستند.\n".
      "🎁 تخفیف ۲۵٪ خرید اول، اگر ظرفیت باقی مانده باشد، هنگام صدور فاکتور اعمال می‌شود.\n".
      "روی پلن موردنظر بزن تا فاکتور خرید بسازی.";
    if($shown===0)$txt.="\n\nفعلاً پلن تخفیف‌خورده قابل خرید در این دسته وجود ندارد.";
    smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$keys],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
}
function bfRedirectToCheckout($planId){
    global $data,$from_id,$message_id,$userInfo;
    $planId=(int)$planId;
    if(!bfIsCheckoutPlanAllowed($planId,$from_id)){
        sendMessage('❌ این پلن الان در جشنواره قابل خرید نیست. لطفاً از فهرست جشنواره پلن دیگری انتخاب کن.');
        return true;
    }
    $plan=bfLoadPlan($planId);
    if(!$plan)return true;
    // Re-use normal service provisioning and all available payment methods.
    setUser('none','step');
    // Also reset the in-memory user state for this SAME webhook request.
    $userInfo['step']='none';
    $GLOBALS['userInfo']['step']='none';
    $data='selectPlan'.$planId.'_'.(int)$plan['catid'].'_bf';
    $GLOBALS['data']=$data;
    return false; // Continue into existing buy handler.
}
