<?php
// Lazily loaded administration for expired subscriptions.
// This file is included only for callbacks whose data starts with `expiredSub`.

// Admin expired-subscription management helpers.
function adminExpiredNormalizeEpoch($value){
    $v=(int)$value;
    if($v>20000000000) $v=(int)floor($v/1000);
    return $v;
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
    $volumeEnded=false; $dateEnded=($dateEnd>0 && $dateEnd<=$now); $found=false;
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
            $total=(float)($info->data_limit ?? 0); $used=(float)($info->used_traffic ?? 0);
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
                        $found=true; $total=(float)($row->total ?? 0); $used=(float)($row->up ?? 0)+(float)($row->down ?? 0);
                        $liveEnd=adminExpiredNormalizeEpoch($row->expiryTime ?? 0); $volumeEnded=($total>0 && $used >= $total); if($liveEnd>0)$dateEnded=$liveEnd<=$now; break;
                    }
                }elseif((int)($row->id ?? 0)===$inboundId){
                    $settings=json_decode($row->settings ?? '{}',true); $clients=$settings['clients'] ?? [];
                    foreach($clients as $c){
                        if(($c['id'] ?? '')===$uuid || ($c['password'] ?? '')===$uuid){
                            $found=true; $total=(float)($c['totalGB'] ?? 0); $liveEnd=adminExpiredNormalizeEpoch($c['expiryTime'] ?? 0);
                            foreach(($row->clientStats ?? []) as $st){ if(($st->email ?? '')===($c['email'] ?? '')){ $used=(float)($st->up ?? 0)+(float)($st->down ?? 0); if(!$liveEnd)$liveEnd=adminExpiredNormalizeEpoch($st->expiryTime ?? 0); break; } }
                            $volumeEnded=($total>0 && $used >= $total); if($liveEnd>0)$dateEnded=$liveEnd<=$now; break 2;
                        }
                    }
                }
            }
        }
    }
    $ended=$volumeEnded || $dateEnded;
    $endedAt=(int)($order['expired_warned_at'] ?? 0);
    if($endedAt<=0 && $dateEnded) $endedAt=$liveEnd>0?$liveEnd:$dateEnd;
    $reason=$volumeEnded && $dateEnded?'حجم و تاریخ':($volumeEnded?'حجم':'تاریخ');
    return ['ended'=>$ended,'reason'=>$reason,'ended_at'=>$endedAt,'found'=>$found];
}
function adminExpiredFetch($olderThan3Days=false){
    global $connection;
    $sql="SELECT o.*,s.type AS server_type,COALESCE(si.title,s.type) AS server_title FROM orders_list o JOIN server_config s ON s.id=o.server_id LEFT JOIN server_info si ON si.id=o.server_id WHERE o.status=1 ORDER BY o.id DESC";
    $res=$connection->query($sql); $out=[]; $cut=time()-259200;
    if(!$res) return $out;
    while($o=$res->fetch_assoc()){
        $state=adminExpiredOrderState($o);
        if(!$state['ended']) continue;
        if($olderThan3Days && ((int)$state['ended_at']<=0 || (int)$state['ended_at']>$cut)) continue;
        $o['_expired']=$state; $out[]=$o;
    }
    return $out;
}
function adminDeleteExpiredOrder($orderId){
    global $connection;
    $stmt=$connection->prepare("SELECT o.*,s.type AS server_type FROM orders_list o JOIN server_config s ON s.id=o.server_id WHERE o.id=? LIMIT 1");
    if(!$stmt) return [false,'خطای دیتابیس'];
    $stmt->bind_param('i',$orderId); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$o) return [false,'اشتراک پیدا نشد'];
    $state=adminExpiredOrderState($o); if(!$state['ended']) return [false,'این اشتراک تمام‌شده نیست'];
    $type=strtolower((string)$o['server_type']); $res=null;
    if($type==='marzban' || $type==='pasarguard') $res=deleteMarzban((int)$o['server_id'],$o['remark']);
    elseif((int)$o['inbound_id']>0) $res=deleteClient((int)$o['server_id'],(int)$o['inbound_id'],$o['uuid'] ?? '0',1);
    else $res=deleteInbound((int)$o['server_id'],$o['uuid'] ?? '0',1);
    $failed=($res===null || (is_object($res) && isset($res->success) && $res->success===false));
    if($failed) return [false,'حذف از پنل ناموفق بود'];
    $stmt=$connection->prepare("UPDATE server_info SET ucount=ucount+1 WHERE id=?");
    if($stmt){ $sid=(int)$o['server_id']; $stmt->bind_param('i',$sid); $stmt->execute(); $stmt->close(); }
    $stmt=$connection->prepare("DELETE FROM orders_list WHERE id=?"); $stmt->bind_param('i',$orderId); $stmt->execute(); $ok=$stmt->affected_rows===1; $stmt->close();
    return [$ok,$ok?'حذف شد':'حذف رکورد دیتابیس ناموفق بود'];
}
function adminExpiredKeyboard($older=false,$page=0){
    $items=adminExpiredFetch($older); $per=10; $page=max(0,(int)$page); $slice=array_slice($items,$page*$per,$per); $rows=[];
    foreach($slice as $o){
        $label='❌ '.($o['remark'] ?: ('#'.$o['id'])).' | '.$o['_expired']['reason'];
        $rows[]=[['text'=>$label,'callback_data'=>'expiredSubView'.(int)$o['id'].'_'.($older?1:0).'_'.$page]];
    }
    if(!$slice) $rows[]=[['text'=>'اشتراک تمام‌شده‌ای پیدا نشد','callback_data'=>'deltach']];
    $nav=[]; if($page>0)$nav[]=['text'=>'⬅️ قبلی','callback_data'=>'expiredSubsList'.($older?1:0).'_'.($page-1)]; if(($page+1)*$per<count($items))$nav[]=['text'=>'بعدی ➡️','callback_data'=>'expiredSubsList'.($older?1:0).'_'.($page+1)]; if($nav)$rows[]=$nav;
    if(count($items)>0)$rows[]=[['text'=>'🗑 حذف یکجای همه ('.count($items).')','callback_data'=>'expiredSubsBulkAsk'.($older?1:0)]];
    $rows[]=[['text'=>'🔙 بازگشت','callback_data'=>'expiredSubsAdmin']];
    return [json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),count($items)];
}


function handleExpiredSubscriptionsAdmin(){
    global $data,$from_id,$admin,$userInfo,$message_id,$connection;
    try{
        if($data === 'expiredSubsAdmin' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'📋 همه اشتراک‌های تمام‌شده','callback_data'=>'expiredSubsList0_0']],
                [['text'=>'⏳ تمام‌شده‌های بیشتر از ۳ روز','callback_data'=>'expiredSubsList1_0']],
                [['text'=>'🔙 بازگشت','callback_data'=>'managePanels']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"🗑 مدیریت اشتراک‌های تمام‌شده\n\nیکی از فهرست‌ها را انتخاب کنید:",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsList([01])_(\d+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $older=((int)$m[1]===1); $page=(int)$m[2];
            [$kb,$count]=adminExpiredKeyboard($older,$page);
            $title=$older?'اشتراک‌های تمام‌شده بیشتر از ۳ روز':'همه اشتراک‌های تمام‌شده';
            smartSendOrEdit($message_id,"🗑 {$title}\n\nتعداد: {$count}\nبرای مشاهده و حذف، روی هر اشتراک بزنید.",$kb);
            exit;
        }
        if(preg_match('/^expiredSubView(\d+)_([01])_(\d+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $oid=(int)$m[1]; $older=(int)$m[2]; $page=(int)$m[3];
            $stmt=$connection->prepare("SELECT o.*,s.type AS server_type,COALESCE(si.title,s.type) AS server_title FROM orders_list o JOIN server_config s ON s.id=o.server_id LEFT JOIN server_info si ON si.id=o.server_id WHERE o.id=? LIMIT 1");
            $stmt->bind_param('i',$oid); $stmt->execute(); $o=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$o){ alert('اشتراک پیدا نشد',true); exit; }
            $st=adminExpiredOrderState($o);
            $endedAt=(int)$st['ended_at']>0?jdate('Y/m/d H:i',(int)$st['ended_at']):'نامشخص';
            $txt="🗑 اشتراک تمام‌شده\n\n🆔 شماره سفارش: <code>{$oid}</code>\n👤 کاربر: <code>{$o['userid']}</code>\n📌 نام: <code>".htmlspecialchars($o['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n🖥 پنل: ".htmlspecialchars($o['server_title'] ?: $o['server_type'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n⚠️ علت پایان: {$st['reason']}\n🕒 زمان پایان: {$endedAt}";
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'🗑 حذف این اشتراک از پنل','callback_data'=>'expiredSubDeleteAsk'.$oid.'_'.$older.'_'.$page]],
                [['text'=>'🔙 بازگشت','callback_data'=>'expiredSubsList'.$older.'_'.$page]]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,$txt,$kb,'HTML');
            exit;
        }
        if(preg_match('/^expiredSubDeleteAsk(\d+)_([01])_(\d+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ بله، حذف شود','callback_data'=>'expiredSubDeleteDo'.$m[1].'_'.$m[2].'_'.$m[3]]],
                [['text'=>'❌ لغو','callback_data'=>'expiredSubView'.$m[1].'_'.$m[2].'_'.$m[3]]]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,'⚠️ این اشتراک از خود پنل و لیست ربات حذف می‌شود. مطمئن هستید؟',$kb);
            exit;
        }
        if(preg_match('/^expiredSubDeleteDo(\d+)_([01])_(\d+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            [$ok,$msg]=adminDeleteExpiredOrder((int)$m[1]);
            alert($ok?'اشتراک با موفقیت حذف شد':$msg,true);
            [$kb,$count]=adminExpiredKeyboard(((int)$m[2]===1),(int)$m[3]);
            smartSendOrEdit($message_id,"🗑 فهرست اشتراک‌های تمام‌شده\n\nتعداد باقی‌مانده: {$count}",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsBulkAsk([01])$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $older=((int)$m[1]===1); $items=adminExpiredFetch($older); $count=count($items);
            $kb=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ حذف همه '.$count.' اشتراک','callback_data'=>'expiredSubsBulkDo'.($older?1:0)]],
                [['text'=>'❌ لغو','callback_data'=>'expiredSubsList'.($older?1:0).'_0']]
            ]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"⚠️ تعداد {$count} اشتراک از خود پنل‌ها و دیتابیس حذف می‌شوند. این عملیات قابل بازگشت نیست.",$kb);
            exit;
        }
        if(preg_match('/^expiredSubsBulkDo([01])$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
            $older=((int)$m[1]===1); $items=adminExpiredFetch($older); $ok=0; $fail=0;
            foreach($items as $o){ [$done,$why]=adminDeleteExpiredOrder((int)$o['id']); if($done)$ok++; else $fail++; }
            $kb=json_encode(['inline_keyboard'=>[[['text'=>'🔙 بازگشت به مدیریت اشتراک‌ها','callback_data'=>'expiredSubsAdmin']]]],JSON_UNESCAPED_UNICODE);
            smartSendOrEdit($message_id,"✅ حذف گروهی پایان یافت.\n\nحذف موفق: {$ok}\nناموفق: {$fail}",$kb);
            exit;
        }
    }catch(Throwable $e){
        error_log('expired subscriptions admin: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        if(function_exists('alert')) @alert('خطا در بخش اشتراک‌های تمام‌شده. جزئیات در error_log ثبت شد.', true);
        return;
    }
}
