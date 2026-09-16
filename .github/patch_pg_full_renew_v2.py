from pathlib import Path
import re

# ---------- config.php ----------
p=Path('config.php')
s=p.read_text(encoding='utf-8')

# Add official PasarGuard traffic reset helper before removePasarguardUser.
marker="function removePasarguardUser($server_id, $remark){"
if 'function resetPasarguardTraffic(' not in s:
    if marker not in s:
        raise SystemExit('removePasarguardUser marker not found')
    helper=r'''function resetPasarguardTraffic($server_id, $remark){
    global $connection;
    $server_id=(int)$server_id;
    $remark=trim((string)$remark);
    if($server_id<=0 || $remark==='') return (object)['success'=>false,'msg'=>'PasarGuard reset: invalid user'];

    $stmt=$connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    if(!$stmt) return (object)['success'=>false,'msg'=>'PasarGuard reset: server query failed'];
    $stmt->bind_param('i',$server_id);$stmt->execute();$server=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$server) return (object)['success'=>false,'msg'=>'PasarGuard reset: server not found'];

    $token=getPasarguardToken($server_id);
    if(empty($token->success) || empty($token->access_token)) return (object)['success'=>false,'msg'=>$token->msg ?? 'PasarGuard token error'];

    // Official PasarGuard route is POST /api/user/{username}/reset.
    // Alternative paths are kept only for older compatible builds.
    $paths=[
        '/api/user/'.rawurlencode($remark).'/reset',
        '/api/user/by-username/'.rawurlencode($remark).'/reset',
        '/api/users/'.rawurlencode($remark).'/reset'
    ];
    $last='';$lastHttp=0;
    foreach(pasarguardPanelApiBases($server['panel_url']) as $base){
        foreach($paths as $path){
            $ch=curl_init();
            curl_setopt_array($ch,[
                CURLOPT_URL=>$base.$path,
                CURLOPT_POST=>true,
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>30,
                CURLOPT_SSL_VERIFYHOST=>false,
                CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_MAXREDIRS=>3,
                CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token->access_token]
            ]);
            $raw=curl_exec($ch);$err=curl_error($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            $lastHttp=$http;
            if($err){$last=$err;continue;}
            if($http>=200 && $http<300) return (object)['success'=>true,'http'=>$http,'obj'=>json_decode((string)$raw)];
            $last=trim((string)$raw)!==''?(string)$raw:('HTTP '.$http);
            if($http===401 || $http===403) return (object)['success'=>false,'msg'=>$last,'http'=>$http];
        }
    }
    return (object)['success'=>false,'msg'=>$last ?: 'PasarGuard traffic reset failed','http'=>$lastHttp];
}

'''
    s=s.replace(marker,helper+marker,1)

# Replace only PasarGuard section of editMarzbanConfig, keeping all other panels unchanged.
start=s.index("    if(($typeRow['type'] ?? '') === 'pasarguard'){", s.index('function editMarzbanConfig'))
end_marker='    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");'
end=s.index(end_marker,start)
old=s[start:end]
new=r'''    if(($typeRow['type'] ?? '') === 'pasarguard'){
        $fields=[];
        $fullReset=!empty($info['full_reset']);

        if($fullReset){
            // Full renewal is a replacement, not an addition: burn old traffic/time,
            // reset usage to zero, then apply the new limit/expiry from NOW.
            $reset=resetPasarguardTraffic($server_id,$info['remark']);
            if(!is_object($reset) || empty($reset->success)) return $reset ?: (object)['success'=>false,'msg'=>'PasarGuard traffic reset failed'];

            $days=(int)($info['days']??0);
            $volume=(float)($info['volume']??0);
            $fields['expire']=$days>0 ? gmdate('c',time()+($days*86400)) : 0;
            $fields['data_limit']=$volume>0 ? (int)floor($volume*1073741824) : 0;
            $fields['status']='active';
            return editPasarguardUser($server_id,$info['remark'],$fields);
        }

        // Volume/day-only renewals remain additive exactly as before.
        $user=getPasarguardUserInfo($server_id,$info['remark']);
        $currentLimit=0;
        if(is_object($user)) $currentLimit=(int)($user->data_limit ?? $user->dataLimit ?? 0);
        $currentExpireTs=0;
        if(is_object($user) && !empty($user->expire)){
            $tmpTs=is_numeric($user->expire)?(int)$user->expire:strtotime((string)$user->expire);
            if($tmpTs)$currentExpireTs=$tmpTs;
        }
        $baseExpire=($currentExpireTs>time())?$currentExpireTs:time();

        if(isset($info['plus_day'])) $fields['expire']=gmdate('c',$baseExpire+((int)$info['plus_day']*86400));
        elseif(isset($info['days'])) $fields['expire']=gmdate('c',$baseExpire+((int)$info['days']*86400));

        if(isset($info['plus_volume'])) $fields['data_limit']=$currentLimit+(int)floor(((float)$info['plus_volume'])*1073741824);
        elseif(isset($info['volume'])) $fields['data_limit']=$currentLimit+(int)floor(((float)$info['volume'])*1073741824);

        if(isset($info['status']))$fields['status']=$info['status'];
        if(empty($fields))return (object)['success'=>true,'msg'=>'nothing to update'];
        return editPasarguardUser($server_id,$info['remark'],$fields);
    }
    
'''
s=s[:start]+new+s[end:]

# qInfo: preserve selected full-plan id for DB plan update + reset mode.
s=s.replace("$info=['eligible'=>false,'order_id'=>0,'days'=>0,'volume'=>0.0,'quota_charge'=>0,'kind'=>''];",
            "$info=['eligible'=>false,'order_id'=>0,'days'=>0,'volume'=>0.0,'quota_charge'=>0,'kind'=>'','plan_id'=>0];",1)
s=s.replace("$info['order_id']=(int)$m[1]; $pid=(int)$m[2];\n        $stmt=$connection->prepare(\"SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1 LIMIT 1\");",
            "$info['order_id']=(int)$m[1]; $pid=(int)$m[2]; $info['plan_id']=$pid;\n        $stmt=$connection->prepare(\"SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1 LIMIT 1\");",1)

# Replace pgRenewApply with reset-aware version. Only full renewal opts into reset.
pat=re.compile(r"function pgRenewApply\(\$orderId, \$days, \$volume\)\{.*?\n\}\n\n\n// ---------- NPVTSUB",re.S)
m=pat.search(s)
if not m:
    raise SystemExit('pgRenewApply block not found')
new_apply=r'''function pgRenewApply($orderId,$days,$volume,$fullReset=false,$fullPlanId=0){
    global $connection;
    $orderId=(int)$orderId;$days=(int)$days;$volume=(float)$volume;$fullPlanId=(int)$fullPlanId;
    $stmt=$connection->prepare("SELECT * FROM `orders_list` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i',$orderId);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$order)return (object)['success'=>false,'msg'=>'order not found'];
    $server_id=(int)$order['server_id'];$remark=(string)$order['remark'];

    if($fullReset){
        $stmt=$connection->prepare("SELECT `type` FROM `server_config` WHERE `id`=? LIMIT 1");
        $stmt->bind_param('i',$server_id);$stmt->execute();$srv=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(($srv['type']??'')!=='pasarguard')return (object)['success'=>false,'msg'=>'full reset is only available for PasarGuard'];

        if($fullPlanId>0){
            $stmt=$connection->prepare("SELECT `id` FROM `server_plans` WHERE `id`=? AND `server_id`=? AND `type`='pasarguard' AND `active`=1 AND COALESCE(`show_full_renew`,1)=1 LIMIT 1");
            $stmt->bind_param('ii',$fullPlanId,$server_id);$stmt->execute();$valid=$stmt->get_result()->fetch_assoc();$stmt->close();
            if(!$valid)return (object)['success'=>false,'msg'=>'renew plan does not belong to this PasarGuard server'];
        }

        $fields=['remark'=>$remark,'full_reset'=>1,'days'=>$days,'volume'=>$volume,'status'=>'active'];
        $res=editMarzbanConfig($server_id,$fields);
        if(!is_object($res)||empty($res->success))return $res ?: (object)['success'=>false,'msg'=>'bad response'];

        // Full renewal always starts fresh from the approval/payment time.
        $newExpire=$days>0 ? time()+($days*86400) : 0;
        if($fullPlanId>0){
            $stmt=$connection->prepare("UPDATE `orders_list` SET `expire_date`=?,`fileid`=?,`notif`=0,`expired_warned_at`=0,`delete_after`=0 WHERE `id`=?");
            $stmt->bind_param('iii',$newExpire,$fullPlanId,$orderId);
        }else{
            $stmt=$connection->prepare("UPDATE `orders_list` SET `expire_date`=?,`notif`=0,`expired_warned_at`=0,`delete_after`=0 WHERE `id`=?");
            $stmt->bind_param('ii',$newExpire,$orderId);
        }
        $stmt->execute();$stmt->close();
        return (object)['success'=>true,'order'=>$order,'full_reset'=>true];
    }

    // Volume/day-only renewal behavior is unchanged: it adds to current service.
    $fields=['remark'=>$remark];
    if($days>0)$fields['days']=$days;
    if($volume>0)$fields['volume']=$volume;
    $res=editMarzbanConfig($server_id,$fields);
    if(!is_object($res)||empty($res->success))return $res ?: (object)['success'=>false,'msg'=>'bad response'];
    $newExpire=max((int)$order['expire_date'],time())+($days*86400);
    $stmt=$connection->prepare("UPDATE `orders_list` SET `expire_date`=?,`notif`=0,`expired_warned_at`=0,`delete_after`=0 WHERE `id`=?");
    $stmt->bind_param('ii',$newExpire,$orderId);$stmt->execute();$stmt->close();
    return (object)['success'=>true,'order'=>$order,'full_reset'=>false];
}


// ---------- NPVTSUB'''
s=pat.sub(new_apply,s,count=1)
p.write_text(s,encoding='utf-8')

# ---------- bot.php ----------
p=Path('bot.php')
s=p.read_text(encoding='utf-8')

# Fix text receipts: PG_RENEW must always use specialized approve callback, regardless of saved origin step.
old="""    if(strpos($originStep, 'increaseWalletWithCartToCart') === 0){
        $keyboard = getReceiptAdminKeyboard('approvePayment' . $hash, 'decPayment' . $hash, $uid);"""
new="""    if(preg_match('/^PG_RENEW_(FULL|VOLUME|DAY)_\\d+_\\d+$/',$payType)){
        $keyboard = getReceiptAdminKeyboard('approvePgRenew' . $hash, 'decPgRenew' . $hash, $uid);
    }elseif(strpos($originStep, 'increaseWalletWithCartToCart') === 0){
        $keyboard = getReceiptAdminKeyboard('approvePayment' . $hash, 'decPayment' . $hash, $uid);"""
if old not in s:
    raise SystemExit('text receipt keyboard target not found')
s=s.replace(old,new,1)

# Replace direct full-renew plan list with warning -> category -> same server -> duration -> volume plan flow.
start=s.index("if(preg_match('/^pgRenewFull_(\\d+)$/', $data, $m)){")
end=s.index("if(preg_match('/^pgRenewVolList_(\\d+)$/', $data, $m))",start)
new_flow=r'''if(preg_match('/^pgRenewFull_(\d+)$/',$data,$m)){
    $oid=(int)$m[1];
    $stmt=$connection->prepare("SELECT o.*,sc.type AS server_type FROM `orders_list` o LEFT JOIN `server_config` sc ON sc.id=o.server_id WHERE o.id=? AND o.userid=? LIMIT 1");
    $stmt->bind_param('ii',$oid,$from_id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$order){alert($mainValues['no_order_found']??'سرویس پیدا نشد');exit;}
    if(($order['server_type']??'')!=='pasarguard'){alert('تمدید کلی این بخش فقط برای سرویس پاسارگارد است.',true);exit;}
    $warning="<b>⚠️ توجه ⚠️</b>\n\n<b>کاربر عزیز، با تمدید کلی اشتراک، حجم باقی‌مانده و زمان باقی‌مانده فعلی شما صفر خواهد شد و پلن جدید روی همان اشتراک قبلی فعال می‌شود. ✅️</b>\n\n<b>لینک اشتراک شما تغییر نخواهد کرد.</b>\n\nدر صورت رضایت، روی دکمه «تأیید و ادامه» بزنید تا به مرحله بعد هدایت شوید.";
    $kb=json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید و ادامه','callback_data'=>'pgRenewFullConfirm_'.$oid]],[['text'=>$buttonValues['back_button'],'callback_data'=>'pgRenewMenu'.$oid]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    smartSendOrEdit($message_id,$warning,$kb,'HTML');exit;
}
if(preg_match('/^pgRenewFullConfirm_(\d+)$/',$data,$m)){
    $oid=(int)$m[1];
    $stmt=$connection->prepare("SELECT o.*,sc.type AS server_type FROM `orders_list` o LEFT JOIN `server_config` sc ON sc.id=o.server_id WHERE o.id=? AND o.userid=? LIMIT 1");
    $stmt->bind_param('ii',$oid,$from_id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$order||($order['server_type']??'')!=='pasarguard'){alert('سرویس پاسارگارد پیدا نشد.',true);exit;}
    $sid=(int)$order['server_id'];ensureServerPlansQuotaSchema();
    $stmt=$connection->prepare("SELECT COALESCE(sp.catid,0) AS catid,MAX(COALESCE(NULLIF(sc.title,''),'بدون دسته‌بندی')) AS cat_title FROM `server_plans` sp LEFT JOIN `server_categories` sc ON sc.id=sp.catid WHERE sp.server_id=? AND sp.active=1 AND sp.price>=0 AND sp.type='pasarguard' AND COALESCE(sp.show_full_renew,1)=1 GROUP BY COALESCE(sp.catid,0) ORDER BY catid ASC");
    $stmt->bind_param('i',$sid);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc()){$rows[]=[['text'=>'📂 '.($r['cat_title']?:'بدون دسته‌بندی'),'callback_data'=>'pgRenewFullCat_'.$oid.'_'.(int)$r['catid']]];}$stmt->close();
    if(!$rows)$rows[]=[['text'=>'پلنی برای تمدید کلی ثبت نشده','callback_data'=>'deltach']];
    $rows[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'pgRenewFull_'.$oid]];
    smartSendOrEdit($message_id,'📂 دسته‌بندی موردنظر را انتخاب کنید:',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));exit;
}
if(preg_match('/^pgRenewFullCat_(\d+)_(\d+)$/',$data,$m)){
    $oid=(int)$m[1];$catid=(int)$m[2];
    $stmt=$connection->prepare("SELECT o.*,sc.type AS server_type FROM `orders_list` o LEFT JOIN `server_config` sc ON sc.id=o.server_id WHERE o.id=? AND o.userid=? LIMIT 1");$stmt->bind_param('ii',$oid,$from_id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$order||($order['server_type']??'')!=='pasarguard'){alert('سرویس پاسارگارد پیدا نشد.',true);exit;}$sid=(int)$order['server_id'];
    $stmt=$connection->prepare("SELECT COUNT(*) AS c FROM `server_plans` WHERE server_id=? AND COALESCE(catid,0)=? AND active=1 AND price>=0 AND type='pasarguard' AND COALESCE(show_full_renew,1)=1");$stmt->bind_param('ii',$sid,$catid);$stmt->execute();$cnt=(int)($stmt->get_result()->fetch_assoc()['c']??0);$stmt->close();if($cnt<=0){alert('پلنی در این دسته وجود ندارد.',true);exit;}
    $serverName='سرور #'.$sid;
    $stmt=$connection->prepare("SELECT `remark` FROM `server_info` WHERE `id`=? LIMIT 1");if($stmt){$stmt->bind_param('i',$sid);$stmt->execute();$sr=$stmt->get_result()->fetch_assoc();$stmt->close();if(trim((string)($sr['remark']??''))!=='')$serverName=(string)$sr['remark'];}
    $rows=[[['text'=>'🌐 '.$serverName,'callback_data'=>'pgRenewFullServer_'.$oid.'_'.$catid]],[['text'=>$buttonValues['back_button'],'callback_data'=>'pgRenewFullConfirm_'.$oid]]];
    smartSendOrEdit($message_id,"🌐 سرور این اشتراک را تأیید کنید:\n\nبرای حفظ لینک اشتراک، تمدید کلی فقط روی همین سرور انجام می‌شود.",json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));exit;
}
if(preg_match('/^pgRenewFullServer_(\d+)_(\d+)$/',$data,$m)){
    $oid=(int)$m[1];$catid=(int)$m[2];
    $stmt=$connection->prepare("SELECT o.server_id,sc.type AS server_type FROM `orders_list` o LEFT JOIN `server_config` sc ON sc.id=o.server_id WHERE o.id=? AND o.userid=? LIMIT 1");$stmt->bind_param('ii',$oid,$from_id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$order||($order['server_type']??'')!=='pasarguard'){alert('سرویس پیدا نشد.',true);exit;}$sid=(int)$order['server_id'];
    $stmt=$connection->prepare("SELECT DISTINCT `days` FROM `server_plans` WHERE server_id=? AND COALESCE(catid,0)=? AND active=1 AND price>=0 AND type='pasarguard' AND COALESCE(show_full_renew,1)=1 ORDER BY days ASC");$stmt->bind_param('ii',$sid,$catid);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc()){$days=(int)$r['days'];$label=$days>0&&$days%30===0?(int)($days/30).' ماهه':($days>0?$days.' روزه':'بدون محدودیت زمانی');$rows[]=[['text'=>'🗓 '.$label,'callback_data'=>'pgRenewFullDays_'.$oid.'_'.$catid.'_'.$days]];}$stmt->close();if(!$rows)$rows[]=[['text'=>'مدتی ثبت نشده','callback_data'=>'deltach']];$rows[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'pgRenewFullCat_'.$oid.'_'.$catid]];
    smartSendOrEdit($message_id,'🗓 مدت تمدید را انتخاب کنید:',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));exit;
}
if(preg_match('/^pgRenewFullDays_(\d+)_(\d+)_(\d+)$/',$data,$m)){
    $oid=(int)$m[1];$catid=(int)$m[2];$days=(int)$m[3];
    $stmt=$connection->prepare("SELECT o.server_id,sc.type AS server_type FROM `orders_list` o LEFT JOIN `server_config` sc ON sc.id=o.server_id WHERE o.id=? AND o.userid=? LIMIT 1");$stmt->bind_param('ii',$oid,$from_id);$stmt->execute();$order=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$order||($order['server_type']??'')!=='pasarguard'){alert('سرویس پیدا نشد.',true);exit;}$sid=(int)$order['server_id'];
    $stmt=$connection->prepare("SELECT * FROM `server_plans` WHERE server_id=? AND COALESCE(catid,0)=? AND days=? AND active=1 AND price>=0 AND type='pasarguard' AND COALESCE(show_full_renew,1)=1 ORDER BY volume ASC,id ASC");$stmt->bind_param('iii',$sid,$catid,$days);$stmt->execute();$res=$stmt->get_result();$rows=[];while($p=$res->fetch_assoc()){$vol=(float)$p['volume'];$vlabel=$vol>0?rtrim(rtrim(number_format($vol,2,'.',''),'0'),'.').' گیگ':'نامحدود';$title=trim((string)$p['title']);$label='📦 '.$vlabel.($title!==''?' | '.$title:'').' | '.number_format((int)$p['price']).' تومان';$rows[]=[['text'=>$label,'callback_data'=>'pgRenewBuyFull_'.$oid.'_'.(int)$p['id']]];}$stmt->close();if(!$rows)$rows[]=[['text'=>'پلنی ثبت نشده','callback_data'=>'deltach']];$rows[]=[['text'=>$buttonValues['back_button'],'callback_data'=>'pgRenewFullServer_'.$oid.'_'.$catid]];
    smartSendOrEdit($message_id,'📦 حجم/پلن جدید را انتخاب کنید:',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));exit;
}
'''
s=s[:start]+new_flow+s[end:]

# Harden full-plan callback: same PasarGuard server only; invoice wording reflects replacement.
old=r'''    $stmt=$connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1 LIMIT 1");
    $stmt->bind_param('i',$pid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$order || !$plan){ alert('سرویس یا پلن پیدا نشد'); exit; }
    $hash=pgRenewCreatePay($from_id, 'PG_RENEW_FULL_'.$oid.'_'.$pid, (int)$plan['price']);
    $msg="🔁 فاکتور تمدید کلی\n\n🔮 سرویس: {$order['remark']}\n📦 حجم افزوده: {$plan['volume']} گیگ\n⏰ روز افزوده: {$plan['days']} روز\n💰 مبلغ: ".number_format((int)$plan['price'])." تومان";'''
new=r'''    if(!$order){alert('سرویس پیدا نشد');exit;}
    $sid=(int)$order['server_id'];
    $stmt=$connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `server_id`=? AND `active`=1 AND `type`='pasarguard' AND COALESCE(`show_full_renew`,1)=1 LIMIT 1");
    $stmt->bind_param('ii',$pid,$sid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if(!$plan){ alert('پلن تمدید کلی معتبر نیست'); exit; }
    $hash=pgRenewCreatePay($from_id, 'PG_RENEW_FULL_'.$oid.'_'.$pid, (int)$plan['price']);
    $vlabel=((float)$plan['volume']>0)?rtrim(rtrim(number_format((float)$plan['volume'],2,'.',''),'0'),'.').' گیگ':'نامحدود';
    $msg="🔁 فاکتور تمدید کلی\n\n🔮 سرویس: {$order['remark']}\n📦 حجم جدید: {$vlabel}\n⏰ مدت جدید: {$plan['days']} روز\n💰 مبلغ: ".number_format((int)$plan['price'])." تومان\n\n⚠️ با پرداخت این فاکتور، حجم و زمان باقی‌مانده قبلی حذف و پلن جدید از صفر روی همین لینک فعال می‌شود.";'''
if old not in s: raise SystemExit('pgRenewBuyFull target not found')
s=s.replace(old,new,1)

# Quota path: full plan invokes reset and passes plan id.
old='$res=pgRenewApply($oid,$days,$volume);'
# First occurrence after pgRenewPayQuota block.
pos=s.index("if(preg_match('/^pgRenewPayQuota")
idx=s.index(old,pos)
s=s[:idx]+"$fullReset=(($qInfo['kind']??'')==='full');$fullPlanId=(int)($qInfo['plan_id']??0);\n        $res=pgRenewApply($oid,$days,$volume,$fullReset,$fullPlanId);"+s[idx+len(old):]

# Wallet path flags.
wallet_start=s.index("if(preg_match('/^pgRenewPayWallet")
old_init="$type=$pay['type']; $days=0; $volume=0; $oid=0;"
idx=s.index(old_init,wallet_start)
s=s[:idx]+"$type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"+s[idx+len(old_init):]
old_full="$oid=(int)$mm[1]; $pid=(int)$mm[2];\n        $stmt=$connection->prepare(\"SELECT `days`,`volume` FROM `server_plans` WHERE `id`=? LIMIT 1\");"
idx=s.index(old_full,wallet_start)
new_full="$oid=(int)$mm[1]; $pid=(int)$mm[2]; $fullReset=true; $fullPlanId=$pid;\n        $stmt=$connection->prepare(\"SELECT `days`,`volume` FROM `server_plans` WHERE `id`=? LIMIT 1\");"
s=s[:idx]+new_full+s[idx+len(old_full):]
idx=s.index(old,wallet_start)
s=s[:idx]+"$res=pgRenewApply($oid,$days,$volume,$fullReset,$fullPlanId);"+s[idx+len(old):]

# Admin receipt approval flags.
approve_start=s.index("if(preg_match('/^approvePgRenew")
old_init2="$type=$pay['type']; $days=0; $volume=0; $oid=0;"
idx=s.index(old_init2,approve_start)
s=s[:idx]+"$type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"+s[idx+len(old_init2):]
# inline full branch is one line
old_inline="if(preg_match('/^PG_RENEW_FULL_(\\d+)_(\\d+)$/',$type,$mm)){ $oid=(int)$mm[1]; $pid=(int)$mm[2];"
idx=s.index(old_inline,approve_start)
s=s[:idx]+"if(preg_match('/^PG_RENEW_FULL_(\\d+)_(\\d+)$/',$type,$mm)){ $oid=(int)$mm[1]; $pid=(int)$mm[2]; $fullReset=true; $fullPlanId=$pid;"+s[idx+len(old_inline):]
idx=s.index(old,approve_start)
s=s[:idx]+"$res=pgRenewApply($oid,$days,$volume,$fullReset,$fullPlanId);"+s[idx+len(old):]

p.write_text(s,encoding='utf-8')

# ---------- pay/back.php: make gateway renewals actually apply the same full-reset semantics ----------
p=Path('pay/back.php')
s=p.read_text(encoding='utf-8')
anchor='if($gateType == "zarinpal" || $gateType == "nextpay") $payDescription = "خرید اشتراک";\n\n$stmt = $connection->prepare("UPDATE `pays` SET `state` = \'paid\' WHERE `id` =?");'
if anchor not in s:
    raise SystemExit('pay/back doAction anchor not found')
gateway=r'''if(preg_match('/^PG_RENEW_(FULL|VOLUME|DAY)_(\d+)_(\d+)$/',$payType,$pgm)){
    $kind=$pgm[1];$oid=(int)$pgm[2];$pid=(int)$pgm[3];$rDays=0;$rVolume=0.0;$fullReset=false;$fullPlanId=0;
    if($kind==='FULL'){
        $stmt=$connection->prepare("SELECT `days`,`volume` FROM `server_plans` WHERE `id`=? AND `active`=1 LIMIT 1");$stmt->bind_param('i',$pid);$stmt->execute();$pl=$stmt->get_result()->fetch_assoc();$stmt->close();
        $rDays=(int)($pl['days']??0);$rVolume=(float)($pl['volume']??0);$fullReset=true;$fullPlanId=$pid;
    }elseif($kind==='VOLUME'){
        $stmt=$connection->prepare("SELECT `amount` FROM `pg_renew_plans` WHERE `id`=? AND `active`=1 AND `kind`='volume' LIMIT 1");$stmt->bind_param('i',$pid);$stmt->execute();$pl=$stmt->get_result()->fetch_assoc();$stmt->close();$rVolume=(float)($pl['amount']??0);
    }else{
        $stmt=$connection->prepare("SELECT `amount` FROM `pg_renew_plans` WHERE `id`=? AND `active`=1 AND `kind`='day' LIMIT 1");$stmt->bind_param('i',$pid);$stmt->execute();$pl=$stmt->get_result()->fetch_assoc();$stmt->close();$rDays=(int)($pl['amount']??0);
    }
    $renew=pgRenewApply($oid,$rDays,$rVolume,$fullReset,$fullPlanId);
    if(!is_object($renew)||empty($renew->success)){
        // Money is already captured by the gateway; refund to bot wallet if panel action fails.
        $stmt=$connection->prepare("UPDATE `pays` SET `state`='renew_failed_refunded' WHERE `id`=?");$stmt->bind_param('i',$payRowId);$stmt->execute();$stmt->close();
        $stmt=$connection->prepare("UPDATE `users` SET `wallet`=`wallet`+? WHERE `userid`=?");$stmt->bind_param('ii',$amount,$user_id);$stmt->execute();$stmt->close();
        sendMessage("⚠️ پرداخت تمدید انجام شد اما پنل پاسخ موفق نداد؛ مبلغ ".number_format($amount)." تومان به کیف پول شما برگشت داده شد.",null,null,$user_id);
        sendToAdmins("⚠️ تمدید پاسارگارد در درگاه ناموفق بود و مبلغ به کیف پول برگشت داده شد. کاربر: {$user_id} | فاکتور: {$payRowId}",null,null);
        showForm("پرداخت انجام شد اما تمدید پنل ناموفق بود؛ مبلغ به کیف پول ربات شما برگشت داده شد.","تمدید پاسارگارد",false);
        return;
    }
    $stmt=$connection->prepare("UPDATE `pays` SET `state`='paid' WHERE `id`=?");$stmt->bind_param('i',$payRowId);$stmt->execute();$stmt->close();
    $mode=$fullReset?'تمدید کلی (ریست کامل)':'تمدید';
    sendMessage("✅ {$mode} پاسارگارد با موفقیت انجام شد.",null,null,$user_id);
    sendToAdmins("✅ {$mode} پاسارگارد از طریق درگاه انجام شد. کاربر: {$user_id} | مبلغ: ".number_format($amount)." تومان",null,null);
    showForm("تمدید سرویس با موفقیت انجام شد.","تمدید پاسارگارد",false);
    return;
}

if($gateType == "zarinpal" || $gateType == "nextpay") $payDescription = "خرید اشتراک";

$stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid' WHERE `id` =?");'''
s=s.replace(anchor,gateway,1)
p.write_text(s,encoding='utf-8')

print('patched config.php, bot.php, pay/back.php')