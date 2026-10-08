<?php
/**
 * Shared customer service cards, subscription rotation and quota-safe deletion.
 * Included after admin_service_manager.php; no customer/admin privileges are mixed.
 */
function dsOwned($id,$allowAdmin=false){
    global $from_id;
    $o=deltaSvcOrder((int)$id);
    if(!$o) return null;
    if((int)$o['userid']===(int)$from_id) return $o;
    if($allowAdmin && deltaSvcIsAdmin())return $o;
    return null;
}
function dsLocked($order){
    global $connection;
    $st=$connection->prepare("SELECT * FROM server_plans WHERE id=? LIMIT 1");
    if(!$st)return false;
    $fid=(int)$order['fileid'];$st->bind_param('i',$fid);$st->execute();
    $p=$st->get_result()->fetch_assoc();$st->close();
    return is_array($p) && function_exists('npvPlanIsLockedPasarguard') && npvPlanIsLockedPasarguard($p);
}
function deltaSvcCustomerView($id,$ownerId){
    global $botState,$buttonValues;
    $o=deltaSvcOrder((int)$id);
    if(!$o || (int)$o['userid']!==(int)$ownerId)return null;
    $v=deltaSvcView($id);
    if(!$v)return null;
    $locked=dsLocked($o);
    $back=!empty($o['agent_bought'])?'agentConfigsList':'mySubscriptions';
    $rows=[];
    if(($botState['serviceTransferState']??'on')==='on')
        $rows[]=[['text'=>'🔄 انتقال سرویس','callback_data'=>'transferMyOrder'.$id]];
    if(($botState['renewAccountState']??'on')==='on')
        $rows[]=[['text'=>'🔁 تمدید سرویس','callback_data'=>(deltaSvcServer((int)$o['server_id'])['type']??'')==='pasarguard'?'pgRenewMenu'.$id:'renewAccount'.$id]];
    if(!$locked){
        $rows[]=[['text'=>'📱 کیو آر ساب پنل','callback_data'=>'dsCustomerSub_'.$id]];
        if(($botState['renewConfigLinkState']??'off')==='on')
            $rows[]=[['text'=>'🔐 تغییر لینک ساب','callback_data'=>'dsCustomerRevoke_'.$id]];
    }else{
        $rows[]=[['text'=>'📥 دریافت فایل اشتراک قفل‌شده','callback_data'=>'sendNpvOrderFile'.$id]];
    }
    $rows[]=[['text'=>'🗑 حذف کانفیگ','callback_data'=>'dsDeleteAsk_'.$id]];
    $rows[]=[['text'=>$buttonValues['back_button']??'🔙 بازگشت','callback_data'=>$back]];
    return ['msg'=>$v['msg'],'keyboard'=>deltaSvcKb($rows)];
}
function dsManualSpent($id){
    global $connection;
    if(!deltaSvcEnsureLedger())return null;
    $stmt=$connection->prepare("SELECT COALESCE(SUM(gigabytes),0) AS charged FROM admin_service_quota_charges WHERE order_id=? AND action_type IN ('V','D','R')");
    if(!$stmt)return null;
    $stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    return (int)($row['charged']??0);
}
function dsInitialCharge($order){
    global $connection;
    if((int)($order['status']??1)!==1)return 0; // Not counted in active-order quota.
    $stmt=$connection->prepare("SELECT volume,quota_charge_volume FROM server_plans WHERE id=? LIMIT 1");
    if(!$stmt)return null;
    $fid=(int)$order['fileid'];$stmt->bind_param('i',$fid);$stmt->execute();
    $p=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$p)return null;
    $vol=(float)($p['volume']??0);
    return (int)round($vol<=0?30:((float)($p['quota_charge_volume']??0)>0 ? (float)$p['quota_charge_volume'] : $vol));
}
function dsRefundEligible($purchaseTs,$currentTime,$usedBytes,$totalBytes,$resetCharges=0){
    $age=(int)$currentTime-(int)$purchaseTs;
    return $purchaseTs>0 && $age>=0 && $age<86400
        && $totalBytes>0 && $usedBytes>=0 && $usedBytes<1073741824
        && $resetCharges===0;
}
function dsDeleteQuote($order,$snapshot){
    $q=['quota'=>deltaSvcIsQuotaBot(),'eligible'=>false,'refund'=>0,'initial'=>0,'manual'=>0,
        'consumed'=>0,'balance'=>max(0,(int)($snapshot['total']??0)-(int)($snapshot['used']??0)),
        'error'=>null];
    if(!$q['quota'])return $q;
    $initial=dsInitialCharge($order);$manual=dsManualSpent((int)$order['id']);
    if($initial===null || $manual===null){
        $q['error']='اطلاعات مبلغ سهمیه یا دفتر حسابداری در دسترس نیست';return $q;
    }
    $q['initial']=$initial;$q['manual']=$manual;
    $ts=deltaSvcTs($order['date']??0);
    $used=(int)($snapshot['used']??0);$total=(int)($snapshot['total']??0);
    $q['consumed']=(int)ceil(max(0,$used)/1073741824);
    // No refund on missing/unlimited snapshots, nonexistent purchase dates or 24h boundary.
    // Any previously billed traffic reset makes the lifetime usage uncertain;
    // do not let reset → delete convert consumed traffic into a refund.
    $st=$GLOBALS['connection']->prepare("SELECT COALESCE(SUM(gigabytes),0) AS r FROM admin_service_quota_charges WHERE order_id=? AND action_type='R'");
    $resetCharges=0;
    if($st){$oid=(int)$order['id'];$st->bind_param('i',$oid);$st->execute();$resetCharges=(int)($st->get_result()->fetch_assoc()['r']??0);$st->close();}
    $q['eligible']=!empty($snapshot['found']) && dsRefundEligible($ts,time(),$used,$total,$resetCharges);
    if($q['eligible']){
        // Fractional gigabytes are never credited upward. Also never credit
        // more GB than the quota actually charged for this order.
        $q['refund']=min(max(0,$initial+$manual),(int)floor($q['balance']/1073741824));
    }
    return $q;
}
function dsQuotaText($order,$snapshot,$q){
    $remaining=deltaSvcTraffic($q['balance']);
    $msg="🗑 <b>حذف کامل اشتراک</b>\n\n🔮 سرویس: <code>".deltaSvcEsc($order['remark'])."</code>\n";
    $msg.="📊 حجم باقی‌مانده: <b>".$remaining."</b>\n";
    $msg.="📉 مصرف‌شده: <b>".deltaSvcTraffic($snapshot['used']??0)."</b>\n\n";
    if(!$q['quota'])return $msg."⚠️ حذف دائمی است و قابل بازگشت نیست.";
    $msg.="📌 <b>قانون بازگشت سهمیهٔ نمایندگی</b>\n";
    $msg.="✅ تنها در صورتی سهمیه برمی‌گردد که <b>کمتر از ۲۴ ساعت</b> از خرید/فعال‌سازی گذشته باشد و مصرف <b>کمتر از ۱ گیگ</b> باشد.\n";
    $msg.="🎯 فقط گیگ‌های کامل باقی‌مانده، حداکثر تا میزان سهمیهٔ کسرشده، قابل بازگشت‌اند.\n\n";
    if($q['eligible'])$msg.="✅ این اشتراک واجد شرایط است.\n🎁 بازگشت سهمیه: <b>".$q['refund']." گیگ</b>\n";
    else $msg.="⛔️ این اشتراک شامل بازگشت سهمیه نیست؛ با حذف، <b>۰ گیگ</b> بازمی‌گردد.\n";
    $msg.="\n💡 در صورت عدم احراز شرایط، می‌توانید به‌جای حذف، لینک ساب قدیمی را باطل کنید و لینک جدید بگیرید؛ <b>حجم و تاریخ باقی‌ماندهٔ اشتراک حفظ می‌شود.</b>\n";
    return $msg;
}
function dsDeleteDialog($id){
    global $botState;
    $o=dsOwned($id,true);
    if(!$o){sendMessage('⛔️ این اشتراک متعلق به شما نیست.');return;}
    $s=deltaSvcSnapshot($o);
    $q=dsDeleteQuote($o,$s);
    if($q['error']){sendMessage('❌ '.$q['error'].'؛ حذف سهمیه‌ای غیرفعال شد.');return;}
    if($q['quota'] && !$s['found']){sendMessage('⚠️ پنل در دسترس نیست؛ برای جلوگیری از دور زدن سهمیه امکان حذف وجود ندارد.');return;}
    $nonce=bin2hex(random_bytes(4));
    setUser('dsDelete_'.$id.'_'.$nonce,'step');
    setUser((string)$q['refund'],'temp');
    $row=[['text'=>'🗑 بله، حذف دائمی','callback_data'=>'dsDeleteYes_'.$id.'_'.$nonce]];
    $keyboard=[$row];
    if(!$q['eligible'] && (($botState['renewConfigLinkState']??'off')==='on') && !dsLocked($o))
        $keyboard[]=[['text'=>'🔐 به‌جای حذف، تغییر لینک ساب','callback_data'=>'dsCustomerRevoke_'.$id]];
    $back=(int)$o['userid']===(int)($GLOBALS['from_id']??0)?'orderDetails'.$id:'dsMenu_'.$id;
    $keyboard[]=[['text'=>'🔙 انصراف','callback_data'=>$back]];
    sendMessage(dsQuotaText($o,$s,$q)."\n\n⚠️ آیا حذف قطعی انجام شود؟",deltaSvcKb($keyboard),'HTML');
}
function dsVerifyPanelDelete($o,$s){
    $sid=(int)$o['server_id'];$type=(string)$s['type'];
    if($type==='pasarguard'||$type==='marzban'){
        $r=deleteMarzban($sid,$o['remark']);
        if(!deltaSvcPanelOK($r))return false;
        $check=deltaSvcSnapshot($o);
        return empty($check['found']);
    }
    if((int)$o['inbound_id']>0)$r=deleteClient($sid,(int)$o['inbound_id'],$o['uuid'],1);
    else $r=deleteInbound($sid,$o['uuid'],1);
    if(!is_array($r))return false;
    // The old X-UI delete helpers return old client stats without checking
    // the HTTP response. Re-fetch the panel and verify the client is gone.
    $check=deltaSvcSnapshot($o);
    return empty($check['found']);
}
function dsExecuteDelete($id,$nonce){
    global $connection,$from_id,$userInfo;
    if(!deltaSvcStep('dsDelete_'.$id.'_'.$nonce)){sendMessage('⚠️ درخواست حذف منقضی شده.');return;}
    $rid=(int)($GLOBALS['currentBotInstanceId']??0);
    $name=deltaSvcIsQuotaBot()?'delta_svc_quota_'.$rid:'delta_svc_delete_'.$rid.'_'.$id;
    $esc=$connection->real_escape_string($name);
    $lock=$connection->query("SELECT GET_LOCK('".$esc."',10) AS ok");
    if(!$lock || (int)($lock->fetch_assoc()['ok']??0)!==1){sendMessage('⌛️ سرویس در حال پردازش است.');return;}
    try{
        if(!deltaSvcStep('dsDelete_'.$id.'_'.$nonce))return;
        $o=dsOwned($id,true);
        if(!$o){sendMessage('❌ اشتراک پیدا نشد.');return;}
        $s=deltaSvcSnapshot($o);$q=dsDeleteQuote($o,$s);
        if($q['error'] || ($q['quota'] && !$s['found'])){
            sendMessage('❌ وضعیت پنل یا سهمیه قابل تأیید نیست؛ حذف انجام نشد.');return;
        }
        // Never silently replace a promised refund with zero, or a new
        // refund value, when the stats change between confirmation and click.
        if($q['refund']!==(int)($userInfo['temp']??-1)){
            setUser('none','step');
            sendMessage('⚠️ مصرف یا سهمیه تغییر کرده. لطفاً تأیید حذف را دوباره انجام دهید.');
            dsDeleteDialog($id);return;
        }
        if($q['quota'] && !deltaSvcEnsureLedger()){
            sendMessage('❌ امکان ثبت حسابداری سهمیه نیست؛ حذف لغو شد.');return;
        }
        setUser('none','step');
        if(!dsVerifyPanelDelete($o,$s)){
            sendMessage('❌ حذف در پنل تأیید نشد؛ سفارش و سهمیه دست‌نخورده باقی ماند.');return;
        }
        if($q['quota']){
            // The old quota calculator counts the base charge WHILE the order
            // exists and manual charges forever. Once the order is deleted,
            // add (base charge - refundable GB) to the permanent ledger.
            // This neutralizes automatic quota refunds for ineligible deletions.
            $delta=(int)$q['initial']-(int)$q['refund'];
            $key='DELETE_'.$id;
            $now=time();$kind='DELETE';
            $stmt=$connection->prepare("INSERT INTO admin_service_quota_charges (op_key,order_id,action_type,gigabytes,created_at) VALUES (?,?,?,?,?)");
            if(!$stmt){sendMessage('🚨 اشتراک در پنل حذف شد ولی ثبت سهمیه خطا داشت؛ به پشتیبانی اطلاع بدهید و حذف را تکرار نکنید.');return;}
            $stmt->bind_param('sisii',$key,$id,$kind,$delta,$now);
            $ok=$stmt->execute();$stmt->close();
            if(!$ok){sendMessage('🚨 سرویس از پنل حذف شد، اما دفتر سهمیه به‌روزرسانی نشد. سفارش حفظ شد؛ به مدیریت اطلاع دهید.');return;}
        }
        $stmt=$connection->prepare("DELETE FROM orders_list WHERE id=? AND userid=?");
        $owner=(int)$o['userid'];$stmt->bind_param('ii',$id,$owner);$ok=$stmt->execute();$count=$stmt->affected_rows;$stmt->close();
        if(!$ok || $count!==1){sendMessage('🚨 سرویس از پنل حذف شد اما حذف رکورد محلی تأیید نشد؛ به مدیر اطلاع دهید.');return;}
        $sid=(int)$o['server_id'];
        $stmt=$connection->prepare("UPDATE server_info SET ucount=ucount+1 WHERE id=?");
        if($stmt){$stmt->bind_param('i',$sid);$stmt->execute();$stmt->close();}
        $reply="✅ اشتراک <code>".deltaSvcEsc($o['remark'])."</code> برای همیشه حذف شد.";
        if($q['quota'])$reply.="\n🎟 بازگشت به سهمیهٔ ربات: <b>".$q['refund']." گیگ</b>.";
        sendMessage($reply,deltaSvcKb([[['text'=>'📋 سرویس‌های من','callback_data'=>'mySubscriptions']],[['text'=>'🏠 منوی اصلی','callback_data'=>'mainMenu']]]),'HTML');
    }catch(Throwable $e){
        error_log('dsExecuteDelete: '.$e->getMessage());
        sendMessage('❌ خطای حذف؛ قبل از تلاش دوباره، وضعیت پنل و سهمیه را بررسی کنید.');
    }finally{$connection->query("SELECT RELEASE_LOCK('".$esc."')");}
}
function dsHandleCustomer(){
    global $data,$from_id,$botState,$userInfo;
    $data=(string)($data??'');
    if(preg_match('/^(deleteMyConfig|delUserConfig|yesDeleteConfig|yesDeleteUserConfig|dsDeleteAsk_)(\d+)$/',$data,$m)){
        $id=(int)$m[2];
        if(!dsOwned($id,true)){sendMessage('⛔️ دسترسی به این سرویس ندارید.');return true;}
        dsDeleteDialog($id);return true;
    }
    if(preg_match('/^dsDeleteYes_(\d+)_([a-f0-9]{8})$/',$data,$m)){
        dsExecuteDelete((int)$m[1],$m[2]);return true;
    }
    if(preg_match('/^dsCustomerSub_(\d+)$/',$data,$m)){
        $id=(int)$m[1];$o=dsOwned($id,false);
        if(!$o)return true;
        // Reuse live-sub + QR generation with customer ownership checked here.
        dsSendSubscription($o,'orderDetails'.$id);
        return true;
    }
    if(preg_match('/^(dsCustomerRevoke_|changAccountConnectionLink)(\d+)$/',$data,$m)){
        $id=(int)$m[2];$o=dsOwned($id,false);
        if(!$o){sendMessage('⛔️ اشتراک متعلق به شما نیست');return true;}
        if(($botState['renewConfigLinkState']??'off')!=='on'||dsLocked($o)){
            sendMessage('🔒 تغییر لینک ساب از تنظیمات ربات فعال نیست.');return true;
        }
        $nonce=bin2hex(random_bytes(4));
        setUser('dsRevoke_'.$id.'_'.$nonce,'step');
        sendMessage("🔐 <b>تغییر لینک سابسکریپشن</b>\n\n⚠️ پس از تأیید، لینک قبلی باطل خواهد شد و باید لینک جدید را دریافت کنید.\n✅ حجم مصرف‌شده، حجم باقی‌مانده و تاریخ انقضا بدون تغییر می‌ماند.",
            deltaSvcKb([[['text'=>'✅ تأیید تغییر لینک','callback_data'=>'dsCustomerRevokeYes_'.$id.'_'.$nonce]],
                        [['text'=>'❌ انصراف','callback_data'=>'orderDetails'.$id]]]),'HTML');
        return true;
    }
    if(preg_match('/^dsCustomerRevokeYes_(\d+)_([a-f0-9]{8})$/',$data,$m)){
        $id=(int)$m[1];$nonce=$m[2];
        if(!deltaSvcStep('dsRevoke_'.$id.'_'.$nonce)){sendMessage('⚠️ تأییدیه منقضی شده است.');return true;}
        $o=dsOwned($id,false);
        if(!$o||($botState['renewConfigLinkState']??'off')!=='on')return true;
        setUser('none','step');
        $s=deltaSvcSnapshot($o);
        if(empty($s['found'])){sendMessage('❌ پنل پاسخگو نیست؛ لینک تغییر نکرد.');return true;}
        $result=deltaSvcRevokeApply($o,$s);
        sendMessage($result['msg'],deltaSvcKb([[['text'=>'🔗 دریافت لینک ساب جدید','callback_data'=>'dsCustomerSub_'.$id]],
                                                [['text'=>'🔙 مشخصات سرویس','callback_data'=>'orderDetails'.$id]]]));
        return true;
    }
    return false;
}
function dsSendSubscription($order,$backCallback){
    global $from_id;
    $server=deltaSvcServer((int)$order['server_id']);
    if(!$server){sendMessage('❌ پنل یافت نشد');return;}
    $type=strtolower((string)($server['type']??''));
    $link='';
    if($type==='pasarguard'||$type==='marzban'){
        $u=$type==='pasarguard'?getPasarguardUserInfo((int)$order['server_id'],$order['remark']):
            getMarzbanUser((int)$order['server_id'],$order['remark']);
        $raw=xuiFindSubscriptionString($u);
        if($raw!==''){
            $base=$type==='pasarguard'?pasarguardPublicSubBase($server):
                xuiGetServerSubBaseUrl((int)$order['server_id'],$server['panel_url']);
            $link=xuiBuildPanelSubLink($base,$raw,$base);
        }
    }else $link=xuiExtractOrderSubLink($order);
    if($link===''){sendMessage('⚠️ لینک زنده از پنل دریافت نشد؛ لینک قدیمی نمایش داده نمی‌شود.');return;}
    $key=deltaSvcKb([[['text'=>'📋 کپی لینک','copy_text'=>['text'=>$link]]],[['text'=>'🔙 بازگشت','callback_data'=>$backCallback]]]);
    $txt="🔗 <b>لینک سابسکریپشن</b>\n<code>".deltaSvcEsc($link)."</code>";
    $tmp=tempnam(sys_get_temp_dir(),'dsub_');
    if($tmp && file_exists(__DIR__.'/phpqrcode/qrlib.php')){
        require_once __DIR__.'/phpqrcode/qrlib.php';
        try{
            QRcode::png($link,$tmp,'M',6,2);
            $sent=sendPhoto($tmp,$txt,$key,'HTML',$from_id);
            if(!is_object($sent)||empty($sent->ok))sendMessage($txt,$key,'HTML');
        }catch(Throwable $e){error_log('dsSendSubscription: '.$e->getMessage());sendMessage($txt,$key,'HTML');}
    }else sendMessage($txt,$key,'HTML');
    if($tmp && is_file($tmp))@unlink($tmp);
}
