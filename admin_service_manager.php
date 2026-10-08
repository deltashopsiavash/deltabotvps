<?php
/**
 * Admin-only subscription management, shared by mother and reseller instances.
 * Called from bot.php after check() initializes the active bot/database.
 * The customer's orderDetails UI is deliberately not altered.
 */
function deltaSvcIsAdmin(){
    global $from_id, $admin, $userInfo;
    return (int)($from_id ?? 0)>0 && ((int)$from_id === (int)$admin || !empty($userInfo['isAdmin']));
}
function deltaSvcEsc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function deltaSvcKb($rows){ return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function deltaSvcOrder($id){
    global $connection;
    $st=$connection->prepare("SELECT * FROM orders_list WHERE id=? LIMIT 1");
    if(!$st) return null;
    $st->bind_param('i',$id);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return $row ?: null;
}
function deltaSvcServer($id){
    global $connection;
    $st=$connection->prepare("SELECT * FROM server_config WHERE id=? LIMIT 1");
    if(!$st) return null;
    $st->bind_param('i',$id);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return $row ?: null;
}
function deltaSvcTs($raw){
    if(is_numeric($raw)){
        $v=(int)$raw;
        return $v>20000000000 ? (int)floor($v/1000) : $v;
    }
    return $raw ? (int)strtotime((string)$raw) : 0;
}
function deltaSvcSnapshot($order){
    $sid=(int)$order['server_id'];
    $server=deltaSvcServer($sid);
    if(!$server) return ['found'=>false,'error'=>'اطلاعات سرور پیدا نشد'];
    $type=strtolower((string)($server['type'] ?? ''));
    $snap=['found'=>false,'server'=>$server,'type'=>$type,'status'=>'نامشخص','enabled'=>false,
        'total'=>0,'used'=>0,'expire'=>deltaSvcTs($order['expire_date']),'email'=>''];
    try {
        if($type==='pasarguard' || $type==='marzban'){
            $u=$type==='pasarguard'?getPasarguardUserInfo($sid,$order['remark']):getMarzbanUser($sid,$order['remark']);
            if(!is_object($u) || empty($u->username)) return $snap;
            $snap['found']=true;
            $snap['status']=(string)($u->status ?? 'unknown');
            $snap['enabled']=($snap['status']==='active');
            $snap['total']=max(0,(int)($u->data_limit ?? $u->dataLimit ?? 0));
            $snap['used']=max(0,(int)($u->used_traffic ?? $u->usedTraffic ?? 0));
            $exp=deltaSvcTs($u->expire ?? $u->expire_date ?? null);
            if($exp>0) $snap['expire']=$exp;
            $snap['panel_user']=$u;
            return $snap;
        }
        $res=getJson($sid);
        if(!is_object($res) || !isset($res->obj) || !is_iterable($res->obj)) return $snap;
        foreach($res->obj as $row){
            $iid=(int)($order['inbound_id'] ?? 0);
            if($iid>0 && (int)($row->id ?? 0)!==$iid) continue;
            $settings=xuiDecodeField($row->settings ?? '{}');
            foreach((array)($settings->clients ?? []) as $cl){
                $uuid=(string)($order['uuid'] ?? '');
                if($uuid==='' || ((string)($cl->id ?? '')!==$uuid && (string)($cl->password ?? '')!==$uuid)) continue;
                $snap['found']=true;
                $snap['inbound']=(int)($row->id ?? 0);
                $snap['email']=(string)($cl->email ?? '');
                $snap['total']=(int)($iid>0 ? ($cl->totalGB ?? 0) : ($row->total ?? 0));
                $snap['expire']=deltaSvcTs($iid>0 ? ($cl->expiryTime ?? 0):($row->expiryTime ?? 0)) ?: $snap['expire'];
                $snap['enabled']=(bool)($cl->enable ?? $row->enable ?? true);
                $snap['status']=$snap['enabled']?'active':'disabled';
                $snap['used']=max(0,(int)($iid>0 ? 0 : ($row->up ?? 0)+($row->down ?? 0)));
                if($iid>0){
                    foreach((array)($row->clientStats ?? []) as $stat){
                        if((string)($stat->email ?? '')===$snap['email']){
                            $snap['used']=max(0,(int)($stat->up ?? 0)+(int)($stat->down ?? 0));
                            if(isset($stat->total) && (int)$stat->total>0) $snap['total']=(int)$stat->total;
                            $snap['enabled']=$snap['enabled'] && (bool)($stat->enable ?? true);
                            $snap['status']=$snap['enabled']?'active':'disabled';
                            break;
                        }
                    }
                }
                return $snap;
            }
        }
    }catch(Throwable $e){ error_log('deltaSvcSnapshot: '.$e->getMessage()); }
    return $snap;
}
function deltaSvcTraffic($bytes){
    $bytes=max(0,(float)$bytes);
    if($bytes<1073741824) return (string)round($bytes/1048576, $bytes<10485760?1:0).'MB';
    return rtrim(rtrim(number_format($bytes/1073741824,2,'.',''),'0'),'.').'GB';
}
function deltaSvcBar($n,$d){
    if($d<=0) return '♾️ نامحدود';
    $full=max(0,min(15,(int)round(15*$n/$d)));
    return str_repeat('🟩',$full).str_repeat('⬜',15-$full).' '.(int)round(100*$n/$d).'%';
}
function deltaSvcView($id){
    $order=deltaSvcOrder((int)$id);
    if(!$order) return null;
    $s=deltaSvcSnapshot($order);
    $purchase=deltaSvcTs($order['date'] ?? 0);
    $expiration=(int)($s['expire']??0);
    $total=(int)($s['total']??0);
    $used=(int)($s['used']??0);
    $elapsed=$purchase>0?max(0,(int)floor((time()-$purchase)/86400)):0;
    $days=$purchase>0 && $expiration>$purchase?(int)ceil(($expiration-$purchase)/86400):0;
    $dUsed=$days>0?min($elapsed,$days):$elapsed;
    $status=!$s['found']?'⚠️ نامشخص (پنل در دسترس نیست)':($s['enabled']?'✅ فعال':'⛔️ غیرفعال');
    $text="🔎 <b>وضعیت کانفیگ:</b> ".$status."\n";
    $text.="🔮 <b>".deltaSvcEsc($order['remark'])."</b>\n🆔 سفارش: <code>".(int)$id."</code>\n\n";
    $text.="📊 <b>مصرف ترافیک</b>\n<b>".deltaSvcTraffic($used)." / ".($total>0?deltaSvcTraffic($total):'♾️')."</b>\n";
    $text.=deltaSvcBar($used,$total)."\n\n";
    $text.="🗓 <b>زمان اشتراک</b>\n<b>".$dUsed."D / ".($days>0?$days.'D':'♾️')."</b>\n";
    $text.=deltaSvcBar($dUsed,$days)."\n\n";
    $text.="📅 تاریخ خریداری‌شده: ".($purchase>0?jdate('Y/m/d H:i',$purchase):'نامشخص')."\n";
    $text.="⏳ تاریخ انقضا: ".($expiration>0?jdate('Y/m/d H:i',$expiration):'نامحدود / نامشخص');
    $rows=[
        [['text'=>'👤 مشخصات خریدار','callback_data'=>'dsBuyer_'.$id]],
        [['text'=>'🛠 مدیریت اشتراک','callback_data'=>'dsMenu_'.$id]],
        [['text'=>'🔄 انتقال این سرویس به کاربر دیگر','callback_data'=>'adminTransferOrder'.$id]],
        [['text'=>'🔙 بازگشت','callback_data'=>'managePanel']]
    ];
    return ['msg'=>$text,'keyboard'=>deltaSvcKb($rows)];
}
function deltaSvcScreen($id,$where='main',$edit=true){
    global $message_id;
    $v=deltaSvcView($id);
    if(!$v){ sendMessage('❌ سفارش پیدا نشد.'); return; }
    if($where==='menu'){
        $s=deltaSvcSnapshot(deltaSvcOrder($id));
        $rows=[
            [['text'=>!$s['found']?'⚠️ وضعیت نامشخص':($s['enabled']?'⛔️ غیرفعال‌سازی کانفیگ':'✅ فعال‌سازی کانفیگ'),'callback_data'=>'dsToggle_'.$id]],
            [['text'=>'🔐 تغییر لینک سابسکریپشن','callback_data'=>'dsRevoke_'.$id]],
            [['text'=>'➕ افزایش حجم','callback_data'=>'dsAskV_'.$id],['text'=>'📆 افزایش تاریخ','callback_data'=>'dsDays_'.$id]],
            [['text'=>'🔄 ریست حجم ترافیک','callback_data'=>'dsReset_'.$id]],
            [['text'=>'🔗 دریافت لینک ساب و QR','callback_data'=>'dsSub_'.$id]],
            [['text'=>'🗑 حذف کانفیگ','callback_data'=>'delUserConfig'.$id]],
            [['text'=>'🔙 بازگشت به اشتراک','callback_data'=>'dsView_'.$id]]
        ];
        $v=['msg'=>"🛠 <b>مدیریت اشتراک</b>\n\n".$v['msg'],'keyboard'=>deltaSvcKb($rows)];
    }elseif($where==='buyer'){
        global $connection;
        $o=deltaSvcOrder($id);$uid=(int)$o['userid'];
        $stmt=$connection->prepare("SELECT name,username FROM users WHERE userid=? LIMIT 1");
        $name='نامشخص';$username='';
        if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$a=$stmt->get_result()->fetch_assoc();$stmt->close();$name=$a['name']??$name;$username=$a['username']??'';}
        $handle=ltrim(trim((string)$username),'@');
        $url=$handle!==''?'https://t.me/'.rawurlencode($handle):'tg://user?id='.$uid;
        $v=['msg'=>"👤 <b>مشخصات خریدار</b>\n\n🆔 آیدی: <code>".$uid."</code>\n📛 نام: ".deltaSvcEsc($name)."\n🔖 یوزرنیم: ".($handle!==''?'@'.deltaSvcEsc($handle):'ثبت نشده'),
            'keyboard'=>deltaSvcKb([[['text'=>'💬 ورود به پی‌وی کاربر','url'=>$url]],[['text'=>'🔙 بازگشت','callback_data'=>'dsView_'.$id]]])];
    }
    if($edit && isset($message_id) && $message_id && empty($GLOBALS['isChildBot'])) smartSendOrEdit($message_id,$v['msg'],$v['keyboard'],'HTML');
    else sendMessage($v['msg'],$v['keyboard'],'HTML');
}
function deltaSvcIsQuotaBot(){
    return !empty($GLOBALS['isChildBot']) && (int)($GLOBALS['currentBotInstanceId']??0)>0 &&
        getResellerBotQuotaLimit((int)$GLOBALS['currentBotInstanceId'])!==null;
}
function deltaSvcCost($kind,$value,$usedBytes=0){
    if($kind==='V') return (int)$value;
    if($kind==='D') return $value<=40?2:($value<=50?4:5);
    if($kind==='R') return (int)floor(max(0,$usedBytes)/1073741824+0.5);
    return 0;
}
function deltaSvcQuotaPreview($cost){
    if(!deltaSvcIsQuotaBot()) return [true,'🌟 این ربات سهمیه حجمی محدود ندارد.',null];
    $r=getResellerBotQuotaRemaining((int)$GLOBALS['currentBotInstanceId']);
    return [($r!==null && $r >= $cost),"🎟 سهمیه فعلی: ".(int)$r." گیگ\n💸 کسر پس از تأیید: ".$cost." گیگ\n📦 مانده پس از عملیات: ".max(0,(int)$r-$cost)." گیگ",$r];
}
function deltaSvcStep($step){
    global $connection, $from_id;
    $st=$connection->prepare("SELECT step FROM users WHERE userid=? LIMIT 1");
    if(!$st)return false;
    $st->bind_param('i',$from_id);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return (string)($row['step']??'')===$step;
}
function deltaSvcConfirm($id,$kind,$amount,$snapshotBytes=0){
    $id=(int)$id;$amount=(int)$amount;
    $order=deltaSvcOrder($id);
    if(!$order) {sendMessage('❌ سفارش پیدا نشد.');return;}
    if($kind==='V'){
        $snap=deltaSvcSnapshot($order);
        if(empty($snap['found']) || (int)$snap['total']===0){
            sendMessage('⚠️ حجم فعلی این سرویس در پنل مشخص نیست یا نامحدود است. افزایش حجم به‌صورت دستی روی سرویس نامحدود مجاز نیست تا سقف ترافیک ناخواسته محدود نشود.');
            return;
        }
    }
    if($kind==='V' && $amount<5){sendMessage('⚠️ حداقل افزایش حجم ۵ گیگ است.');return;}
    if($kind==='D' && ($amount<30 || $amount>60)){sendMessage('⚠️ افزایش تاریخ باید بین ۳۰ تا ۶۰ روز باشد.');return;}
    $cost=deltaSvcCost($kind,$amount,$snapshotBytes);
    [$can,$quota]=deltaSvcQuotaPreview($cost);
    $action=$kind==='V'?("افزایش ".$amount." گیگ"):("افزایش ".$amount." روز");
    $nonce=bin2hex(random_bytes(4));
    $step='dsConfirm_'.$id.'_'.$kind.'_'.$amount.'_'.$nonce;
    if(!$can){
        sendMessage("❌ سهمیه ربات کافی نیست.\n\n".$quota,deltaSvcKb([[['text'=>'🔙 بازگشت','callback_data'=>'dsMenu_'.$id]]]));
        return;
    }
    setUser($step,'step');
    sendMessage("⚠️ <b>تأیید عملیات مدیر</b>\n\n📦 سرویس: <code>".deltaSvcEsc(deltaSvcOrder($id)['remark'])."</code>\n✅ عملیات: ".$action."\n\n".$quota,
        deltaSvcKb([[['text'=>'✅ تأیید و اعمال','callback_data'=>'dsExec_'.$id.'_'.$kind.'_'.$amount.'_'.$nonce]],
                    [['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');
}
function deltaSvcEnsureLedger(){
    global $connection;
    return (bool)$connection->query("CREATE TABLE IF NOT EXISTS admin_service_quota_charges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        op_key VARCHAR(100) NOT NULL UNIQUE,
        order_id INT NOT NULL,
        action_type VARCHAR(20) NOT NULL,
        gigabytes INT NOT NULL,
        created_at INT NOT NULL,
        INDEX(order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function deltaSvcLogCharge($key,$id,$kind,$cost){
    global $connection;
    if(!deltaSvcIsQuotaBot() || $cost<=0)return true;
    $now=time();$st=$connection->prepare("INSERT INTO admin_service_quota_charges (op_key,order_id,action_type,gigabytes,created_at) VALUES (?,?,?,?,?)");
    if(!$st)return false;
    $st->bind_param('sisii',$key,$id,$kind,$cost,$now);
    $ok=$st->execute();$st->close();
    return $ok;
}
function deltaSvcPanelOK($response){
    if(is_object($response))return !empty($response->success);
    if(is_array($response))return !empty($response['success']);
    return false;
}
function deltaSvcPgRevoke($order,$server){
    $sid=(int)$order['server_id'];$token=getPasarguardToken($sid);
    if(empty($token->success) || empty($token->access_token)) return ['ok'=>false,'msg'=>'دریافت توکن پنل ناموفق بود'];
    $last='';
    foreach(pasarguardPanelApiBases($server['panel_url']) as $base){
        foreach(['/api/user/'.rawurlencode($order['remark']).'/revoke_sub',
                 '/api/user/by-username/'.rawurlencode($order['remark']).'/revoke_sub'] as $path){
            $ch=curl_init();
            curl_setopt_array($ch,[CURLOPT_URL=>$base.$path,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token->access_token]]);
            $raw=curl_exec($ch);$err=curl_error($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if(!$err && $http>=200 && $http<300){
                $j=json_decode((string)$raw);
                if(is_object($j) && ((isset($j->success) && $j->success===false) || isset($j->detail))){
                    return ['ok'=>false,'msg'=>'پنل تغییر لینک ساب را رد کرد: '.substr((string)$raw,0,160)];
                }
                return ['ok'=>true,'msg'=>'لینک ساب در پنل تغییر کرد'];
            }
            $last=$err?:'HTTP '.$http.' '.substr((string)$raw,0,180);
            if($http===401 || $http===403)return ['ok'=>false,'msg'=>$last];
        }
    }
    return ['ok'=>false,'msg'=>$last];
}
function deltaSvcMarzbanPost($serverId,$remark,$operation){
    $server=deltaSvcServer((int)$serverId);
    if(!$server)return (object)['success'=>false,'msg'=>'سرور پیدا نشد'];
    $token=getMarzbanToken($serverId);
    if(!is_object($token)||empty($token->access_token))return (object)['success'=>false,'msg'=>'توکن پنل در دسترس نیست'];
    $url=rtrim((string)$server['panel_url'],'/').'/api/user/'.rawurlencode((string)$remark).'/'.$operation;
    $ch=curl_init();
    curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Bearer '.$token->access_token]]);
    $raw=curl_exec($ch);$err=curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($err || $http<200 || $http>=300)
        return (object)['success'=>false,'msg'=>($err?:'HTTP '.$http.' '.substr((string)$raw,0,160))];
    $json=json_decode((string)$raw);
    if(is_object($json) && (isset($json->detail) || (isset($json->success) && $json->success===false)))return (object)['success'=>false,'msg'=>(string)$raw];
    return (object)['success'=>true,'obj'=>$json];
}

function deltaSvcRevokeApply($order,$s){
    global $connection;
    $sid=(int)$order['server_id'];$id=(int)$order['id'];$type=$s['type'];
    if($type==='pasarguard'){
        $result=deltaSvcPgRevoke($order,$s['server']);
        if(!$result['ok'])return $result;
        $fresh=getPasarguardUserInfo($sid,$order['remark']);
        $sub=xuiFindSubscriptionString($fresh);
        if($sub!==''){
            $base=pasarguardPublicSubBase($s['server']);
            $sub=xuiBuildPanelSubLink($base,$sub,$base);
            $path=parse_url($sub,PHP_URL_PATH);
            if($path && preg_match('~/sub/([^/?#]+)~',$path,$match)){
                $new=urldecode($match[1]);
                $stmt=$connection->prepare("UPDATE orders_list SET token=? WHERE id=?");
                $stmt->bind_param('si',$new,$id);$stmt->execute();$stmt->close();
            }
        }
        return ['ok'=>true,'msg'=>'✅ لینک سابسکریپشن از پنل PasarGuard تغییر کرد. لینک جدید را از دکمهٔ دریافت لینک ساب بگیرید.'];
    }
    if($type==='marzban'){
        $revoke=deltaSvcMarzbanPost($sid,$order['remark'],'revoke_sub');
        if(!deltaSvcPanelOK($revoke))return ['ok'=>false,'msg'=>$revoke->msg??'خطا در تغییر لینک سابسکریپشن'];
        $r=getMarzbanUser($sid,$order['remark']);
        if(!is_object($r) || isset($r->detail) || (isset($r->success) && !$r->success))
            return ['ok'=>false,'msg'=>(string)($r->msg ?? $r->detail ?? 'خطا در پنل مرزبان')];
        $sub=xuiFindSubscriptionString($r);
        if($sub!==''){
            $path=parse_url($sub,PHP_URL_PATH);
            if($path && preg_match('~/sub/([^/?#]+)~',$path,$m)){
                $new=urldecode($m[1]);$st=$connection->prepare("UPDATE orders_list SET token=? WHERE id=?");
                $st->bind_param('si',$new,$id);$st->execute();$st->close();
            }
        }
        return ['ok'=>true,'msg'=>'✅ لینک سابسکریپشن در پنل Marzban تغییر کرد.'];
    }
    // Legacy x-ui: renew UUID only after explicit confirmation.
    $old=(string)($order['uuid']??'');
    $found=deltaFindXuiClientInPanel($sid,(int)$order['inbound_id'],$old,$order['remark']);
    if(!$found)return ['ok'=>false,'msg'=>'کلاینت در پنل پیدا نشد'];
    $r=deltaXuiRenewClientDirect($order);
    if(!is_object($r)||empty($r->success)||empty($r->newUuid))return ['ok'=>false,'msg'=>$r->msg??'خطای پنل در تغییر UUID'];
    $row=$found['row'];$new=$r->newUuid;
    $links=getConnectionLink($sid,$new,$row->protocol,$order['remark'],$row->port,xuiDecodeField($row->streamSettings)->network,
        (int)$order['inbound_id'],(int)$order['rahgozar']);
    $json=json_encode($links);$token=RandomString(30);
    $st=$connection->prepare("UPDATE orders_list SET uuid=?,token=?,link=? WHERE id=?");
    $st->bind_param('sssi',$new,$token,$json,$id);$ok=$st->execute();$st->close();
    return ['ok'=>$ok,'msg'=>$ok?'✅ شناسهٔ اتصال تغییر کرد.':'❌ ذخیرهٔ شناسهٔ جدید ناموفق بود؛ تنظیمات پنل را بررسی کنید'];
}
function deltaSvcApply($id,$kind,$amount,$expectedUsed=0){
    global $connection;
    $order=deltaSvcOrder($id);if(!$order)return ['ok'=>false,'msg'=>'سرویس حذف شده است'];
    $s=deltaSvcSnapshot($order);if(empty($s['found']))return ['ok'=>false,'msg'=>'کاربر در پنل یافت نشد؛ عملیات انجام نشد'];
    $sid=(int)$order['server_id'];$type=$s['type'];
    if($kind==='T'){
        $r=($type==='pasarguard'||$type==='marzban') ? changeMarzbanState($sid,$order['remark']):
            ((int)$order['inbound_id']===0?changeInboundState($sid,$order['uuid']):changeClientState($sid,$order['inbound_id'],$order['uuid']));
        if(!deltaSvcPanelOK($r))
            return ['ok'=>false,'msg'=>'❌ پنل عملیات تغییر وضعیت را تأیید نکرد'];
        $after=deltaSvcSnapshot($order);
        if(empty($after['found']) || $after['enabled']===$s['enabled'])
            return ['ok'=>false,'msg'=>'⚠️ پاسخ پنل دریافت شد، اما وضعیت تازه تأیید نشد. قبل از تکرار، وضعیت سرویس را در پنل بررسی کنید.'];
        return ['ok'=>true,'msg'=>$after['enabled']?'✅ کانفیگ دوباره فعال شد.':'✅ کانفیگ غیرفعال شد.'];
    }
    if($kind==='S')return deltaSvcRevokeApply($order,$s);
    if($kind==='R'){
        if(abs((int)$s['used']-(int)$expectedUsed)>1048576)
            return ['ok'=>false,'msg'=>'⚠️ میزان مصرف از زمان تأیید تغییر کرده است. دوباره ریست را انتخاب و مبلغ جدید را تأیید کنید.'];
        if($type==='pasarguard')$r=resetPasarguardTraffic($sid,$order['remark']);
        elseif($type==='marzban')$r=deltaSvcMarzbanPost($sid,$order['remark'],'reset');
        else $r=resetClientTraffic($sid,$s['email'],in_array($type,['sanaei','alireza'],true)?$s['inbound']:($order['inbound_id']?:null));
        return ['ok'=>deltaSvcPanelOK($r),'msg'=>deltaSvcPanelOK($r)?'✅ حجم مصرف‌شده در پنل ریست شد.':'❌ ریست حجم در پنل موفق نبود'];
    }
    if($kind==='V'||$kind==='D'){
        if($kind==='V' && (int)$s['total']===0)return ['ok'=>false,'msg'=>'افزایش حجم سرویس نامحدود یا با سقف نامشخص مجاز نیست'];
        $v=$kind==='V'?$amount:0;$d=$kind==='D'?$amount:0;
        if($type==='marzban'||$type==='pasarguard'){
            $changes=['remark'=>$order['remark'],'preserve_status'=>true];
            if($kind==='V') $changes['plus_volume']=$v;
            else $changes['plus_day']=$d;
            $r=editMarzbanConfig($sid,$changes);
        }
        elseif((int)$order['inbound_id']===0)$r=editInboundTraffic($sid,$order['uuid'],$v,$d);
        else $r=editClientTraffic($sid,(int)$order['inbound_id'],$order['uuid'],$v,$d);
        if(!deltaSvcPanelOK($r))return ['ok'=>false,'msg'=>'❌ پنل افزایش را تأیید نکرد؛ سهمیه کم نشد'];
        if($kind==='D'){
            $new=max(time(),deltaSvcTs($order['expire_date']))+$amount*86400;
            $st=$connection->prepare("UPDATE orders_list SET expire_date=?,expired_warned_at=0,delete_after=0 WHERE id=?");
            if($st){$st->bind_param('ii',$new,$id);$st->execute();$st->close();}
        }
        return ['ok'=>true,'msg'=>$kind==='V'?'✅ حجم اشتراک '.$amount.' گیگ افزایش یافت.':'✅ تاریخ اشتراک '.$amount.' روز افزایش یافت.'];
    }
    return ['ok'=>false,'msg'=>'عملیات نامعتبر'];
}
function deltaSvcExecute($id,$kind,$value,$nonce,$expectedStep,$expectedUsed=0){
    global $connection,$from_id;
    // Serialize all admin edits inside a quota-limited reseller, even across different orders.
    $rid=(int)($GLOBALS['currentBotInstanceId']??0);
    $lockName=deltaSvcIsQuotaBot() ? ('delta_svc_quota_'.$rid) : ('delta_svc_order_'.$rid.'_'.$id);
    $escaped=$connection->real_escape_string($lockName);
    $l=$connection->query("SELECT GET_LOCK('".$escaped."',10) AS ok");
    if(!$l || (int)($l->fetch_assoc()['ok']??0)!==1){sendMessage('⌛️ سرویس در حال تغییر است؛ دوباره امتحان کنید.');return;}
    try{
        if(!deltaSvcStep($expectedStep)){sendMessage('⚠️ این تأییدیه منقضی یا قبلاً استفاده شده است.');return;}
        $order=deltaSvcOrder($id);if(!$order){sendMessage('❌ سفارش یافت نشد');return;}
        $s=deltaSvcSnapshot($order);
        if(!$s['found']){sendMessage('❌ اطلاعات زندهٔ پنل در دسترس نیست.');return;}
        if($kind==='R' && abs((int)$s['used']-(int)$expectedUsed)>1048576){
            setUser('none','step');sendMessage('⚠️ مصرف تغییر کرده؛ ریست را دوباره باز کنید تا کسر سهمیه به‌روز شود.');return;
        }
        $charge=deltaSvcCost($kind,$value,$kind==='R'?(int)$s['used']:0);
        [$allowed,$quota]=deltaSvcQuotaPreview($charge);
        if(!$allowed){sendMessage('❌ سهمیهٔ ربات کافی نیست؛ تغییری اعمال نشد.'."\n".$quota);return;}
        $needsLedger=deltaSvcIsQuotaBot() && $charge>0;
        if($needsLedger && !deltaSvcEnsureLedger()){sendMessage('❌ جدول حسابداری سهمیه در دسترس نیست؛ عملیات لغو شد.');return;}
        // Consume confirmation before calling the remote panel to prevent double clicks.
        setUser('none','step');
        $r=deltaSvcApply($id,$kind,$value,$expectedUsed);
        if(!$r['ok']){sendMessage($r['msg'],deltaSvcKb([[['text'=>'🔙 مدیریت اشتراک','callback_data'=>'dsMenu_'.$id]]]));return;}
        $key='svc-'.$id.'-'.$kind.'-'.$nonce;
        $charged=$needsLedger?deltaSvcLogCharge($key,$id,$kind,$charge):true;
        $detail=$needsLedger?("\n💸 از سهمیه ربات: ".$charge." گیگ کسر شد."):("\n🎟 کسر از سهمیه: ندارد");
        if(!$charged)$detail="\n🚨 عملیات روی پنل موفق بود، اما ثبت کسر سهمیه خطا داشت. عملیات را تکرار نکنید؛ گزارش را بررسی کنید.";
        sendMessage($r['msg'].$detail,deltaSvcKb([[['text'=>'🛠 بازگشت به مدیریت اشتراک','callback_data'=>'dsMenu_'.$id]]]));
        deltaSvcScreen($id,'menu',false);
    }catch(Throwable $e){
        error_log('deltaSvcExecute: '.$e->getMessage());
        sendMessage('❌ خطای داخلی هنگام اعمال عملیات؛ برای جلوگیری از تکرار ناخواسته وضعیت را در پنل بررسی کنید.');
    }finally{
        $connection->query("SELECT RELEASE_LOCK('".$escaped."')");
    }
}
function deltaSvcHandleRequest(){
    global $data,$text,$userInfo,$buttonValues,$from_id;
    if(!deltaSvcIsAdmin()) return false;
    $data=(string)($data??'');
    $step=(string)($userInfo['step']??'');
    if(preg_match('/^changeUserConfigState(\d+)$/',$data,$m))$data='dsToggle_'.$m[1];
    if(preg_match('/^changAccountConnectionLink(\d+)$/',$data,$m))$data='dsRevoke_'.$m[1];
    if(preg_match('/^ds(View|Menu|Buyer)_(\d+)$/',$data,$m)){
        // Cancel any outstanding input/confirmation when navigating away.
        setUser('none','step');setUser('','temp');
        deltaSvcScreen((int)$m[2],strtolower($m[1]));return true;
    }
    if(preg_match('/^dsAsk([VD])_(\d+)$/',$data,$m)){
        $id=(int)$m[2];
        setUser('dsInput'.$m[1].'_'.$id,'step');
        $msg=$m[1]==='V'?"➕ <b>افزایش حجم</b>\n\n📥 لطفاً مقدار گیگ را وارد کنید.\n⚠️ کمتر از ۵ گیگ قابل قبول نیست.":
            "📆 <b>افزایش تاریخ</b>\n\n📥 تعداد روز را وارد کنید.\n⚠️ حداقل ۳۰ و حداکثر ۶۰ روز.";
        sendMessage($msg,deltaSvcKb([[['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');return true;
    }
    if($data==='' && preg_match('/^dsInput([VD])_(\d+)$/',$step,$m)){
        $kind=$m[1];$id=(int)$m[2];
        if(trim((string)$text)===(string)($buttonValues['cancel']??'')){setUser('none','step');deltaSvcScreen($id,'menu',false);return true;}
        $entered=trim((string)$text);
        if(!preg_match('/^[0-9]+$/D',$entered) || (float)$entered>1000000){
            sendMessage('🔢 لطفاً فقط یک عدد صحیح معتبر وارد کنید.');return true;
        }
        $n=(int)$entered;
        if(($kind==='V'&&$n<5)||($kind==='D'&&($n<30||$n>60))){
            sendMessage($kind==='V'?'⚠️ حداقل افزایش حجم ۵ گیگ است.':'⚠️ تاریخ باید بین ۳۰ تا ۶۰ روز باشد.');return true;
        }
        deltaSvcConfirm($id,$kind,$n);return true;
    }
    if(preg_match('/^dsDays_(\d+)$/',$data,$m)){
        $id=(int)$m[1];
        sendMessage("📆 <b>افزایش تاریخ</b>\n\nیک گزینه را انتخاب کنید یا تاریخ دلخواه (۳۰ تا ۶۰ روز) وارد کنید.",
            deltaSvcKb([
                [['text'=>'🗓 ۳۰ روز | کسر ۲ گیگ','callback_data'=>'dsDayPick_'.$id.'_30']],
                [['text'=>'🗓 ۶۰ روز | کسر ۵ گیگ','callback_data'=>'dsDayPick_'.$id.'_60']],
                [['text'=>'✍️ تاریخ دلخواه','callback_data'=>'dsAskD_'.$id]],
                [['text'=>'🔙 بازگشت','callback_data'=>'dsMenu_'.$id]]
            ]),'HTML');return true;
    }
    if(preg_match('/^dsDayPick_(\d+)_(30|60)$/',$data,$m)){deltaSvcConfirm((int)$m[1],'D',(int)$m[2]);return true;}
    if(preg_match('/^ds(Toggle|Revoke)_(\d+)$/',$data,$m)){
        $id=(int)$m[2];$kind=$m[1]==='Toggle'?'T':'S';$o=deltaSvcOrder($id);
        if(!$o){sendMessage('❌ سفارش پیدا نشد');return true;}
        if($kind==='T'){
            // No quota charge; one action and a refreshed manager page, never the customer's menu.
            $s=deltaSvcSnapshot($o);
            $nonce=bin2hex(random_bytes(4));
            $st='dsConfirm_'.$id.'_T_0_'.$nonce;
            setUser($st,'step');
            sendMessage("🔐 <b>تأیید تغییر وضعیت</b>\n\n".($s['enabled']?'⛔️ این کانفیگ غیرفعال شود؟':'✅ این کانفیگ فعال شود؟'),
                deltaSvcKb([[['text'=>'✅ تأیید','callback_data'=>'dsExec_'.$id.'_T_0_'.$nonce]],
                            [['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');
        }else{
            $nonce=bin2hex(random_bytes(4));$st='dsConfirm_'.$id.'_S_0_'.$nonce;
            setUser($st,'step');
            sendMessage("🔐 <b>Revoke Subscription</b>\n\n⚠️ با تأیید، لینک اشتراک قبلی باطل و لینک جدید در خود پنل ساخته می‌شود.",
                deltaSvcKb([[['text'=>'✅ تغییر لینک ساب','callback_data'=>'dsExec_'.$id.'_S_0_'.$nonce]],
                            [['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');
        }
        return true;
    }
    if(preg_match('/^dsReset_(\d+)$/',$data,$m)){
        $id=(int)$m[1];$o=deltaSvcOrder($id);if(!$o){sendMessage('❌ سفارش پیدا نشد');return true;}
        $s=deltaSvcSnapshot($o);if(!$s['found']){sendMessage('❌ پنل در دسترس نیست؛ امکان ریست وجود ندارد.');return true;}
        $used=(int)$s['used'];$cost=deltaSvcCost('R',0,$used);
        [$can,$quota]=deltaSvcQuotaPreview($cost);
        if(!$can){sendMessage('❌ سهمیهٔ ربات کافی نیست؛ ریست انجام نمی‌شود.'."\n".$quota);return true;}
        setUser('dsReset1_'.$id,'step');setUser((string)$used,'temp');
        sendMessage("⚠️ <b>مرحلهٔ اول تأیید ریست ترافیک</b>\n\n📦 حجم کل سرویس: ".deltaSvcTraffic($s['total'])."\n📉 مصرف قبل از ریست: ".deltaSvcTraffic($used)."\n♻️ فقط همین مقدار مصرف‌شده ریست می‌شود.\n\n".$quota."\n\n📐 کسر سهمیه به نزدیک‌ترین گیگ صحیح گرد می‌شود.",
            deltaSvcKb([[['text'=>'۱/۲ ✅ بررسی و ادامه','callback_data'=>'dsResetNext_'.$id]],
                        [['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');return true;
    }
    if(preg_match('/^dsResetNext_(\d+)$/',$data,$m)){
        $id=(int)$m[1];if(!deltaSvcStep('dsReset1_'.$id)){sendMessage('⚠️ تأییدیه منقضی شده است');return true;}
        $used=(int)($userInfo['temp']??0);$cost=deltaSvcCost('R',0,$used);
        $nonce=bin2hex(random_bytes(4));$st='dsReset2_'.$id.'_'.$nonce;
        setUser($st,'step');
        sendMessage("🚨 <b>مرحلهٔ دوم و نهایی تأیید</b>\n\n📊 حجم مصرفی: ".deltaSvcTraffic($used)."\n💸 کسر سهمیه: ".$cost." گیگ\n\nآیا ریست ترافیک را قطعی می‌کنید؟",
            deltaSvcKb([[['text'=>'۲/۲ ✅ ریست قطعی','callback_data'=>'dsResetExec_'.$id.'_'.$nonce]],
                        [['text'=>'❌ انصراف','callback_data'=>'dsMenu_'.$id]]]),'HTML');return true;
    }
    if(preg_match('/^dsResetExec_(\d+)_([0-9a-f]{8})$/',$data,$m)){
        $id=(int)$m[1];$nonce=$m[2];$used=(int)($userInfo['temp']??0);
        deltaSvcExecute($id,'R',0,$nonce,'dsReset2_'.$id.'_'.$nonce,$used);return true;
    }
    if(preg_match('/^dsExec_(\d+)_([VDTS])_(\d+)_([0-9a-f]{8})$/',$data,$m)){
        $id=(int)$m[1];$kind=$m[2];$num=(int)$m[3];$nonce=$m[4];
        if(($kind==='V'&&$num<5)||($kind==='D'&&($num<30||$num>60))||(($kind==='T'||$kind==='S')&&$num!==0)){
            sendMessage('❌ مقدار نامعتبر');return true;
        }
        deltaSvcExecute($id,$kind,$num,$nonce,'dsConfirm_'.$id.'_'.$kind.'_'.$num.'_'.$nonce);return true;
    }
    if(preg_match('/^dsSub_(\d+)$/',$data,$m)){
        $id=(int)$m[1];$order=deltaSvcOrder($id);
        if(!$order){sendMessage('❌ سفارش یافت نشد');return true;}
        // Read the live subscription URL FIRST; a revoked token cached in orders_list
        // must never be sent again if the panel already rotated it.
        $server=deltaSvcServer((int)$order['server_id']);
        $type=strtolower((string)($server['type']??''));
        $link='';
        if($type==='pasarguard' || $type==='marzban'){
            $current=$type==='pasarguard'
                ? getPasarguardUserInfo((int)$order['server_id'],$order['remark'])
                : getMarzbanUser((int)$order['server_id'],$order['remark']);
            $fresh=xuiFindSubscriptionString($current);
            if($fresh!==''){
                $base=$type==='pasarguard'?pasarguardPublicSubBase($server):
                    xuiGetServerSubBaseUrl((int)$order['server_id'],$server['panel_url']);
                $link=xuiBuildPanelSubLink($base,$fresh,$base);
            }
            if($link===''){
                sendMessage('⚠️ لینک زنده از پنل قابل دریافت نیست؛ برای جلوگیری از ارسال لینک ابطال‌شده، لینک قدیمی نمایش داده نشد.');
                return true;
            }
        }else $link=trim((string)xuiExtractOrderSubLink($order));
        if($link===''){sendMessage('❌ لینک سابسکریپشن در پنل یافت نشد.');return true;}
        $caption="🔗 <b>لینک اشتراک</b>\n<code>".deltaSvcEsc($link)."</code>\n\n📲 کد QR همین لینک:";
        $keys=deltaSvcKb([[['text'=>'📋 کپی لینک','copy_text'=>['text'=>$link]]],[['text'=>'🔙 مدیریت اشتراک','callback_data'=>'dsMenu_'.$id]]]);
        $file=tempnam(sys_get_temp_dir(),'dsqr_');
        if($file && file_exists(__DIR__.'/phpqrcode/qrlib.php')){
            require_once __DIR__.'/phpqrcode/qrlib.php';
            try {
                QRcode::png($link,$file,'M',7,2);
                $sent=sendPhoto($file,$caption,$keys,'HTML',$from_id);
                if(!is_object($sent)||empty($sent->ok))sendMessage($caption,$keys,'HTML');
            }catch(Throwable $e){error_log('deltaSvcQR: '.$e->getMessage());sendMessage($caption,$keys,'HTML');}
        }else sendMessage($caption,$keys,'HTML');
        if($file && is_file($file))@unlink($file);
        return true;
    }
    return false;
}
