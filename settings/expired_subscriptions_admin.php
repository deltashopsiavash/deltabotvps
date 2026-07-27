<?php
// Lazy admin module: loaded only for expiredSub* callbacks.
// Lists are database-backed and paginated to avoid panel/API timeouts.

function adminExpiredNormalizeEpoch($value){
    $v=(int)$value;
    if($v>20000000000) $v=(int)floor($v/1000);
    return $v;
}
function adminExpiredPanelCondition($kind){
    return $kind === 'pg'
        ? "LOWER(TRIM(s.type))='pasarguard'"
        : "LOWER(TRIM(s.type))<>'pasarguard'";
}
function adminExpiredBaseWhere($olderThan3Days=false){
    $now=time();
    $cut=$now-259200;
    $ended="(COALESCE(o.expired_warned_at,0)>0 OR (COALESCE(o.expire_date,0)>0 AND ((o.expire_date<20000000000 AND o.expire_date<={$now}) OR (o.expire_date>=20000000000 AND o.expire_date<=".($now*1000)."))))";
    if(!$olderThan3Days) return $ended;
    return "(".$ended." AND ((COALESCE(o.expired_warned_at,0)>0 AND o.expired_warned_at<={$cut}) OR (COALESCE(o.expire_date,0)>0 AND ((o.expire_date<20000000000 AND o.expire_date<={$cut}) OR (o.expire_date>=20000000000 AND o.expire_date<=".($cut*1000).")))))";
}
function adminExpiredDbState($order){
    $now=time();
    $exp=adminExpiredNormalizeEpoch($order['expire_date'] ?? 0);
    $warn=(int)($order['expired_warned_at'] ?? 0);
    $dateEnded=($exp>0 && $exp<=$now);
    $endedAt=$warn>0?$warn:($dateEnded?$exp:0);
    return [
        'ended'=>($warn>0 || $dateEnded),
        'reason'=>$dateEnded ? ($warn>0?'حجم یا تاریخ':'تاریخ') : 'حجم/پنل',
        'ended_at'=>$endedAt,
        'found'=>true
    ];
}
function adminExpiredOrderState($order){
    global $connection;
    $now=time();
    $serverId=(int)($order['server_id'] ?? 0);
    $remark=(string)($order['remark'] ?? '');
    $inboundId=(int)($order['inbound_id'] ?? 0);
    $uuid=(string)($order['uuid'] ?? '');
    $type=strtolower((string)($order['server_type'] ?? ''));
    $dateEnd=adminExpiredNormalizeEpoch($order['expire_date'] ?? 0);
    $volumeEnded=false;
    $dateEnded=($dateEnd>0 && $dateEnd<=$now);
    $found=false;
    $liveEnd=0;

    if($type==='pasarguard' && function_exists('getPasarguardUserInfo')){
        $info=getPasarguardUserInfo($serverId,$remark);
        if($info){
            $found=true;
            $total=(float)($info->data_limit ?? $info->traffic_limit ?? $info->volume ?? $info->total ?? 0);
            $used=(float)($info->used_traffic ?? $info->traffic_used ?? $info->used ?? 0);
            $liveEnd=adminExpiredNormalizeEpoch($info->expire ?? $info->expiry_time ?? $info->expires_at ?? 0);
            $volumeEnded=($total>0 && $used >= $total);
            if($liveEnd>0) $dateEnded=($liveEnd<=$now);
        }
    }elseif($type==='marzban' && function_exists('getMarzbanUser')){
        $info=getMarzbanUser($serverId,$remark);
        if($info && isset($info->username)){
            $found=true;
            $total=(float)($info->data_limit ?? 0);
            $used=(float)($info->used_traffic ?? 0);
            $liveEnd=adminExpiredNormalizeEpoch($info->expire ?? 0);
            $volumeEnded=($total>0 && $used >= $total);
            if($liveEnd>0) $dateEnded=($liveEnd<=$now);
        }
    }else{
        $response=getJson($serverId);
        if($response && !empty($response->success) && isset($response->obj) && is_array($response->obj)){
            foreach($response->obj as $row){
                if($inboundId===0){
                    $clients=json_decode($row->settings ?? '{}')->clients ?? [];
                    $c=$clients[0] ?? null;
                    if($c && (($c->id ?? '')===$uuid || ($c->password ?? '')===$uuid)){
                        $found=true;
                        $total=(float)($row->total ?? 0);
                        $used=(float)($row->up ?? 0)+(float)($row->down ?? 0);
                        $liveEnd=adminExpiredNormalizeEpoch($row->expiryTime ?? 0);
                        $volumeEnded=($total>0 && $used >= $total);
                        if($liveEnd>0) $dateEnded=($liveEnd<=$now);
                        break;
                    }
                }elseif((int)($row->id ?? 0)===$inboundId){
                    $settings=json_decode($row->settings ?? '{}',true);
                    $clients=$settings['clients'] ?? [];
                    foreach($clients as $c){
                        if(($c['id'] ?? '')===$uuid || ($c['password'] ?? '')===$uuid){
                            $found=true;
                            $total=(float)($c['totalGB'] ?? 0);
                            $liveEnd=adminExpiredNormalizeEpoch($c['expiryTime'] ?? 0);
                            foreach(($row->clientStats ?? []) as $st){
                                if(($st->email ?? '')===($c['email'] ?? '')){
                                    $used=(float)($st->up ?? 0)+(float)($st->down ?? 0);
                                    if(!$liveEnd) $liveEnd=adminExpiredNormalizeEpoch($st->expiryTime ?? 0);
                                    break;
                                }
                            }
                            $volumeEnded=($total>0 && $used >= $total);
                            if($liveEnd>0) $dateEnded=($liveEnd<=$now);
                            break 2;
                        }
                    }
                }
            }
        }
    }

    $ended=$volumeEnded || $dateEnded || (int)($order['expired_warned_at'] ?? 0)>0;
    $endedAt=(int)($order['expired_warned_at'] ?? 0);
    if($endedAt<=0 && $dateEnded) $endedAt=$liveEnd>0?$liveEnd:$dateEnd;
    $reason=$volumeEnded && $dateEnded?'حجم و تاریخ':($volumeEnded?'حجم':($dateEnded?'تاریخ':'حجم/پنل'));
    return ['ended'=>$ended,'reason'=>$reason,'ended_at'=>$endedAt,'found'=>$found];
}
function adminExpiredFetch($kind='pg',$olderThan3Days=false,$limit=15,$offset=0){
    global $connection;
    $where=adminExpiredBaseWhere($olderThan3Days);
    $panel=adminExpiredPanelCondition($kind);
    $sql="SELECT o.*,s.type AS server_type,COALESCE(si.title,s.type) AS server_title
          FROM orders_list o
          JOIN server_config s ON s.id=o.server_id
          LEFT JOIN server_info si ON si.id=o.server_id
          WHERE o.status=1 AND {$panel} AND {$where}
          ORDER BY COALESCE(NULLIF(o.expired_warned_at,0),o.expire_date) DESC,o.id DESC
          LIMIT ".max(1,(int)$limit)." OFFSET ".max(0,(int)$offset);
    $res=$connection->query($sql);
    $out=[];
    if(!$res) return $out;
    while($o=$res->fetch_assoc()){
        $o['_expired']=adminExpiredDbState($o);
        $out[]=$o;
    }
    return $out;
}
function adminExpiredCount($kind='pg',$olderThan3Days=false){
    global $connection;
    $where=adminExpiredBaseWhere($olderThan3Days);
    $panel=adminExpiredPanelCondition($kind);
    $res=$connection->query("SELECT COUNT(*) AS c FROM orders_list o JOIN server_config s ON s.id=o.server_id WHERE o.status=1 AND {$panel} AND {$where}");
    if(!$res) return 0;
    $row=$res->fetch_assoc();
    return (int)($row['c'] ?? 0);
}
function adminDeleteExpiredOrder($orderId){
    global $connection;
    $stmt=$connection->prepare("SELECT o.*,s.type AS server_type FROM orders_list o JOIN server_config s ON s.id=o.server_id WHERE o.id=? LIMIT 1");
    if(!$stmt) return [false,'خطای دیتابیس'];
    $stmt->bind_param('i',$orderId);
    $stmt->execute();
    $o=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$o) return [false,'اشتراک پیدا نشد'];

    $state=adminExpiredOrderState($o);
    if(!$state['ended']) return [false,'این اشتراک تمام‌شده نیست'];

    $type=strtolower((string)$o['server_type']);
    $res=null;
    if($type==='pasarguard' && function_exists('removePasarguardUser')){
        $res=removePasarguardUser((int)$o['server_id'],$o['remark']);
    }elseif(($type==='marzban' || $type==='pasarguard') && function_exists('deleteMarzban')){
        $res=deleteMarzban((int)$o['server_id'],$o['remark']);
    }elseif((int)$o['inbound_id']>0){
        $res=deleteClient((int)$o['server_id'],(int)$o['inbound_id'],$o['uuid'] ?? '0',1);
    }else{
        $res=deleteInbound((int)$o['server_id'],$o['uuid'] ?? '0',1);
    }
    $failed=($res===null || (is_object($res) && isset($res->success) && $res->success===false));
    if($failed) return [false,'حذف از پنل ناموفق بود'];

    $stmt=$connection->prepare("UPDATE server_info SET ucount=ucount+1 WHERE id=?");
    if($stmt){
        $sid=(int)$o['server_id'];
        $stmt->bind_param('i',$sid);
        $stmt->execute();
        $stmt->close();
    }
    $stmt=$connection->prepare("DELETE FROM orders_list WHERE id=?");
    $stmt->bind_param('i',$orderId);
    $stmt->execute();
    $ok=$stmt->affected_rows===1;
    $stmt->close();
    return [$ok,$ok?'حذف شد':'حذف رکورد دیتابیس ناموفق بود'];
}
function adminExpiredKeyboard($kind='pg',$older=false,$page=0){
    $per=15;
    $page=max(0,(int)$page);
    $count=adminExpiredCount($kind,$older);
    $lastPage=max(0,(int)ceil($count/$per)-1);
    if($page>$lastPage) $page=$lastPage;
    $items=adminExpiredFetch($kind,$older,$per,$page*$per);
    $rows=[];
    foreach($items as $o){
        $label='❌ '.($o['remark'] ?: ('#'.$o['id'])).' | '.$o['_expired']['reason'];
        $rows[]=[['text'=>$label,'callback_data'=>'expiredSubView'.(int)$o['id'].'_'.$kind.'_'.($older?1:0).'_'.$page]];
    }
    if(!$items) $rows[]=[['text'=>'اشتراک تمام‌شده‌ای پیدا نشد','callback_data'=>'deltach']];
    $nav=[];
    if($page>0) $nav[]=['text'=>'⬅️ قبلی','callback_data'=>'expiredSubsList_'.$kind.'_'.($older?1:0).'_'.($page-1)];
    if(($page+1)*$per<$count) $nav[]=['text'=>'بعدی ➡️','callback_data'=>'expiredSubsList_'.$kind.'_'.($older?1:0).'_'.($page+1)];
    if($nav) $rows[]=$nav;
    if($count>0) $rows[]=[['text'=>'🗑 حذف یکجای همه ('.$count.')','callback_data'=>'expiredSubsBulkAsk_'.$kind.'_'.($older?1:0)]];
    $rows[]=[['text'=>'🔙 بازگشت','callback_data'=>'expiredSubsKind_'.$kind]];
    return [json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),$count,$page];
}
function handleExpiredSubscriptionsAdmin(){
    global $data,$from_id,$admin,$userInfo,$message_id,$connection;
    try{
        $isAdmin=($from_id == $admin || !empty($userInfo['isAdmin']));
        if(!$isAdmin) return false;

        if($data === 'expiredSubsAdmin'){
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'🛡 اشتراک‌های پنل پاسارگاد','callback_data'=>'expiredSubsKind_pg']],
                [['text'=>'🖥 اشتراک‌های غیر پنل پاسارگاد','callback_data'=>'expiredSubsKind_other']],
                [['text'=>'🔙 بازگشت','callback_data'=>'managePanels']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"🗑 مدیریت اشتراک‌های تمام‌شده\n\nنوع پنل را انتخاب کنید:",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsKind_(pg|other)$/',$data,$m)){
            $kind=$m[1];
            $title=$kind==='pg'?'اشتراک‌های پنل پاسارگاد':'اشتراک‌های غیر پنل پاسارگاد';
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'📋 همه اشتراک‌های تمام‌شده','callback_data'=>'expiredSubsList_'.$kind.'_0_0']],
                [['text'=>'⏳ تمام‌شده‌های بیشتر از ۳ روز','callback_data'=>'expiredSubsList_'.$kind.'_1_0']],
                [['text'=>'🔙 بازگشت','callback_data'=>'expiredSubsAdmin']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"🗑 {$title}\n\nنوع فهرست را انتخاب کنید:",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsList_(pg|other)_([01])_(\d+)$/',$data,$m)){
            $kind=$m[1];
            $older=((int)$m[2]===1);
            $page=(int)$m[3];
            [$kb,$count,$page]=adminExpiredKeyboard($kind,$older,$page);
            $panelTitle=$kind==='pg'?'پاسارگاد':'غیر پاسارگاد';
            $listTitle=$older?'تمام‌شده‌های بیشتر از ۳ روز':'همه تمام‌شده‌ها';
            smartSendOrEdit($message_id,"🗑 {$panelTitle} — {$listTitle}\n\nتعداد: {$count}\nصفحه: ".($page+1)."\nدر هر صفحه ۱۵ اشتراک نمایش داده می‌شود.",$kb);
            exit;
        }
        if(preg_match('/^expiredSubView(\d+)_(pg|other)_([01])_(\d+)$/',$data,$m)){
            $oid=(int)$m[1]; $kind=$m[2]; $older=(int)$m[3]; $page=(int)$m[4];
            $stmt=$connection->prepare("SELECT o.*,s.type AS server_type,COALESCE(si.title,s.type) AS server_title FROM orders_list o JOIN server_config s ON s.id=o.server_id LEFT JOIN server_info si ON si.id=o.server_id WHERE o.id=? LIMIT 1");
            $stmt->bind_param('i',$oid); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$o){ alert('اشتراک پیدا نشد',true); exit; }
            $st=adminExpiredOrderState($o);
            $endedAt=(int)$st['ended_at']>0?jdate('Y/m/d H:i',(int)$st['ended_at']):'نامشخص';
            $txt="🗑 اشتراک تمام‌شده\n\n🆔 شماره سفارش: <code>{$oid}</code>\n👤 کاربر: <code>{$o['userid']}</code>\n📌 نام: <code>".htmlspecialchars($o['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n🖥 پنل: ".htmlspecialchars($o['server_title'] ?: $o['server_type'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n⚠️ علت پایان: {$st['reason']}\n🕒 زمان پایان: {$endedAt}";
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'🗑 حذف این اشتراک از پنل','callback_data'=>'expiredSubDeleteAsk'.$oid.'_'.$kind.'_'.$older.'_'.$page]],
                [['text'=>'🔙 بازگشت','callback_data'=>'expiredSubsList_'.$kind.'_'.$older.'_'.$page]]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,$txt,$kb,'HTML');
            exit;
        }
        if(preg_match('/^expiredSubDeleteAsk(\d+)_(pg|other)_([01])_(\d+)$/',$data,$m)){
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ بله، حذف شود','callback_data'=>'expiredSubDeleteDo'.$m[1].'_'.$m[2].'_'.$m[3].'_'.$m[4]]],
                [['text'=>'❌ لغو','callback_data'=>'expiredSubView'.$m[1].'_'.$m[2].'_'.$m[3].'_'.$m[4]]]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,'⚠️ این اشتراک از خود پنل و لیست ربات حذف می‌شود. مطمئن هستید؟',$kb);
            exit;
        }
        if(preg_match('/^expiredSubDeleteDo(\d+)_(pg|other)_([01])_(\d+)$/',$data,$m)){
            [$ok,$msg]=adminDeleteExpiredOrder((int)$m[1]);
            if(!$ok){ alert($msg,true); exit; }
            [$kb,$count]=adminExpiredKeyboard($m[2],((int)$m[3]===1),(int)$m[4]);
            smartSendOrEdit($message_id,"✅ اشتراک حذف شد.\n\nتعداد باقی‌مانده: {$count}",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsBulkAsk_(pg|other)_([01])$/',$data,$m)){
            $kind=$m[1]; $older=((int)$m[2]===1); $count=adminExpiredCount($kind,$older);
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ شروع حذف همه '.$count.' اشتراک','callback_data'=>'expiredSubsBulkDo_'.$kind.'_'.($older?1:0)]],
                [['text'=>'❌ لغو','callback_data'=>'expiredSubsList_'.$kind.'_'.($older?1:0).'_0']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"⚠️ تعداد {$count} اشتراک از خود پنل‌ها و دیتابیس حذف می‌شوند. این عملیات قابل بازگشت نیست.",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsBulkDo_(pg|other)_([01])$/',$data,$m)){
            $kind=$m[1]; $older=((int)$m[2]===1); $ok=0; $fail=0;
            // Small chunks prevent Telegram webhook timeouts on large panels.
            $items=adminExpiredFetch($kind,$older,15,0);
            foreach($items as $o){
                [$done,$why]=adminDeleteExpiredOrder((int)$o['id']);
                $done?$ok++:$fail++;
            }
            $left=adminExpiredCount($kind,$older);
            $rows=[];
            if($left>0) $rows[]=[['text'=>'ادامه حذف باقی‌مانده‌ها ('.$left.')','callback_data'=>'expiredSubsBulkDo_'.$kind.'_'.($older?1:0)]];
            $rows[]=[['text'=>'🔙 بازگشت به فهرست','callback_data'=>'expiredSubsList_'.$kind.'_'.($older?1:0).'_0']];
            $kb=json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"✅ این مرحله پایان یافت.\n\nحذف موفق: {$ok}\nناموفق: {$fail}\nباقی‌مانده: {$left}",$kb);
            exit;
        }
    }catch(Throwable $e){
        error_log('expired subscriptions admin: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        if(function_exists('alert')) @alert('خطا در بخش اشتراک‌های تمام‌شده. جزئیات در error_log ثبت شد.',true);
        exit;
    }
    return false;
}
