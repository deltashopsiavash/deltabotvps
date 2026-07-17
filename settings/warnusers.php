<?php
require_once __DIR__ . "/../baseInfo.php";
require_once __DIR__ . "/../config.php";
$time=time();

// PasarGuard 3-day expiry cleanup — fail-safe edition.
// IMPORTANT: deletion is allowed only after a fresh, valid panel response confirms
// that every configured limit has ended. A remaining date OR remaining volume blocks deletion.

if(!function_exists('pgExpiryHtml')){
    function pgExpiryHtml($value){
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if(!function_exists('pgExpiryNormalizeEpoch')){
    function pgExpiryNormalizeEpoch($value){
        if($value === null || $value === '' || $value === false) return 0;
        if(is_numeric($value)){
            $n=(int)$value;
            if($n > 200000000000000) $n=(int)floor($n/1000000); // microseconds
            elseif($n > 20000000000) $n=(int)floor($n/1000); // milliseconds
            return max(0,$n);
        }
        $ts=strtotime((string)$value);
        return $ts ? (int)$ts : 0;
    }
}
if(!function_exists('pgExpiryFindUserObject')){
    function pgExpiryFindUserObject($value,$remark,$depth=0){
        if($depth>5) return null;
        if(is_array($value)){
            foreach($value as $item){
                $found=pgExpiryFindUserObject($item,$remark,$depth+1);
                if($found) return $found;
            }
            return null;
        }
        if(!is_object($value)) return null;
        $username=(string)($value->username ?? $value->remark ?? $value->name ?? '');
        $hasUserFields = property_exists($value,'data_limit') || property_exists($value,'used_traffic') ||
                         property_exists($value,'expire') || property_exists($value,'expiry_time') ||
                         property_exists($value,'expires_at') || property_exists($value,'status');
        if(($username!=='' && $username===(string)$remark) || ($username==='' && $hasUserFields)) return $value;
        foreach(['data','user','item','result','obj','items','results','users'] as $key){
            if(property_exists($value,$key)){
                $found=pgExpiryFindUserObject($value->{$key},$remark,$depth+1);
                if($found) return $found;
            }
        }
        return null;
    }
}
if(!function_exists('pgExpiryReadNumber')){
    function pgExpiryReadNumber($obj,$keys,&$known=false){
        $known=false;
        foreach($keys as $key){
            if(is_object($obj) && property_exists($obj,$key)){
                $known=true;
                $value=$obj->{$key};
                if($value===null || $value==='') return 0.0;
                if(is_numeric($value)) return (float)$value;
                $clean=preg_replace('/[^0-9.\-]/','',(string)$value);
                return is_numeric($clean)?(float)$clean:0.0;
            }
        }
        return 0.0;
    }
}
if(!function_exists('pgExpiryLiveState')){
    function pgExpiryLiveState($serverId,$remark,$now){
        $raw=getPasarguardUserInfo((int)$serverId,(string)$remark);
        $user=pgExpiryFindUserObject($raw,(string)$remark);
        if(!$user){
            $msg=is_object($raw)?($raw->msg ?? $raw->detail ?? 'پاسخ معتبر کاربر دریافت نشد'):'پاسخ معتبر کاربر دریافت نشد';
            return ['valid'=>false,'error'=>(string)$msg];
        }

        $totalKnown=false; $usedKnown=false; $expireKnown=false;
        $total=pgExpiryReadNumber($user,['data_limit','traffic_limit','dataLimit','volume','total'],$totalKnown);
        $used=pgExpiryReadNumber($user,['used_traffic','traffic_used','usedTraffic','used'],$usedKnown);

        $expireRaw=null;
        foreach(['expire','expiry_time','expires_at','expiryTime'] as $key){
            if(property_exists($user,$key)){ $expireKnown=true; $expireRaw=$user->{$key}; break; }
        }
        $expire=pgExpiryNormalizeEpoch($expireRaw);
        $status=strtolower(trim((string)($user->status ?? $user->state ?? 'unknown')));

        // A zero limit means unlimited/not configured. It is not considered expired.
        $volumeLimited=($totalKnown && $total>0);
        $dateLimited=($expireKnown && $expire>0);
        $remainingBytes=$volumeLimited?($total-$used):null;
        $volumeEnded=$volumeLimited && $remainingBytes<=0;
        $dateEnded=$dateLimited && $expire<=$now;

        // Warn when any configured dimension ends.
        $warningEnded=$volumeEnded || $dateEnded;

        // Delete only when ALL configured dimensions have ended.
        // This deliberately protects a service if either time or volume is still available.
        $allConfiguredLimitsEnded=false;
        if($volumeLimited || $dateLimited){
            $volumeGate=(!$volumeLimited) || $volumeEnded;
            $dateGate=(!$dateLimited) || $dateEnded;
            $allConfiguredLimitsEnded=$volumeGate && $dateGate;
        }

        return [
            'valid'=>true,'raw'=>$user,'status'=>$status,
            'total_known'=>$totalKnown,'used_known'=>$usedKnown,'expire_known'=>$expireKnown,
            'total'=>$total,'used'=>$used,'remaining_bytes'=>$remainingBytes,'expire'=>$expire,
            'volume_limited'=>$volumeLimited,'date_limited'=>$dateLimited,
            'volume_ended'=>$volumeEnded,'date_ended'=>$dateEnded,
            'warning_ended'=>$warningEnded,'safe_to_delete'=>$allConfiguredLimitsEnded
        ];
    }
}
if(!function_exists('pgExpiryGb')){
    function pgExpiryGb($bytes){
        if($bytes===null) return 'نامحدود/نامشخص';
        return number_format(((float)$bytes)/1073741824,2,'.','').' گیگ';
    }
}
if(!function_exists('pgExpiryDurationText')){
    function pgExpiryDurationText($seconds){
        $past=$seconds<0; $seconds=abs((int)$seconds);
        $days=(int)floor($seconds/86400); $hours=(int)floor(($seconds%86400)/3600); $mins=(int)floor(($seconds%3600)/60);
        $text=$days.' روز، '.$hours.' ساعت و '.$mins.' دقیقه';
        return $past ? $text.' گذشته' : $text.' باقی‌مانده';
    }
}
if(!function_exists('pgExpiryAdminDeleteReport')){
    function pgExpiryAdminDeleteReport($order,$state,$deletedAt,$deleteResponse){
        $expire=(int)($state['expire']??0);
        $dateText=$expire>0?date('Y-m-d H:i:s',$expire):'نامحدود/ثبت‌نشده';
        $dateDelta=$expire>0?pgExpiryDurationText($expire-$deletedAt):'نامحدود/ثبت‌نشده';
        $remaining=$state['remaining_bytes']??null;
        $remainingText=$remaining===null?'نامحدود/نامشخص':($remaining>=0?pgExpiryGb($remaining).' باقی‌مانده':pgExpiryGb(abs($remaining)).' بیشتر از سقف مصرف شده');
        $dbExpire=pgExpiryNormalizeEpoch($order['expire_date']??0);
        $dbExpireText=$dbExpire>0?date('Y-m-d H:i:s',$dbExpire):'ثبت‌نشده';
        $warned=(int)($order['expired_warned_at']??0);
        $due=(int)($order['delete_after']??0);
        $panelTitle=$order['server_title']??('سرور #'.($order['server_id']??0));
        $username=trim((string)($order['user_username']??''));
        $name=trim((string)($order['user_name']??''));
        $apiMsg=is_object($deleteResponse)?($deleteResponse->msg??'موفق'):'موفق';
        return "#حذف_3_روزه\n".
               "🗑 <b>گزارش حذف خودکار سه‌روزه</b>\n\n".
               "🧾 شناسه سفارش: <code>".(int)$order['id']."</code>\n".
               "👤 کاربر: <code>".pgExpiryHtml($order['userid'])."</code>".($username!==''?' @'.pgExpiryHtml($username):'').($name!==''?' — '.pgExpiryHtml($name):'')."\n".
               "🔮 نام اشتراک: <code>".pgExpiryHtml($order['remark'])."</code>\n".
               "🖥 پنل: <b>".pgExpiryHtml($panelTitle)."</b> — سرور <code>".(int)$order['server_id']."</code>\n".
               "📌 وضعیت زنده پنل: <b>".pgExpiryHtml($state['status']??'unknown')."</b>\n\n".
               "📦 حجم کل: <b>".pgExpiryGb($state['total']??0)."</b>\n".
               "📊 حجم مصرف‌شده: <b>".pgExpiryGb($state['used']??0)."</b>\n".
               "📉 وضعیت حجم: <b>".pgExpiryHtml($remainingText)."</b>\n".
               "📅 تاریخ زنده پنل: <code>".pgExpiryHtml($dateText)."</code>\n".
               "⏱ وضعیت تاریخ: <b>".pgExpiryHtml($dateDelta)."</b>\n".
               "🗃 تاریخ ثبت‌شده در دیتابیس: <code>".pgExpiryHtml($dbExpireText)."</code>\n\n".
               "⚠️ زمان ثبت پایان: <code>".($warned?date('Y-m-d H:i:s',$warned):'نامشخص')."</code>\n".
               "⏳ زمان برنامه‌ریزی حذف: <code>".($due>0?date('Y-m-d H:i:s',$due):'نامشخص')."</code>\n".
               "✅ زمان حذف واقعی: <code>".date('Y-m-d H:i:s',$deletedAt)."</code>\n".
               "🔎 علت حذف: <b>تمام محدودیت‌های فعال سرویس در استعلام زنده پنل پایان یافته بود</b>\n".
               "🌐 پاسخ حذف پنل: <code>".pgExpiryHtml($apiMsg)."</code>";
    }
}

$pgGlobalAlerts = (($botState['pgExpiryAlertsState'] ?? 'on') === 'on');
$sql="SELECT o.*,COALESCE(u.pg_renew_suggestion,1) AS pg_renew_suggestion,COALESCE(u.pg_expiry_alerts,1) AS pg_expiry_alerts,u.name AS user_name,u.username AS user_username,COALESCE(si.title,CONCAT('سرور #',o.server_id)) AS server_title FROM orders_list o JOIN server_config s ON s.id=o.server_id LEFT JOIN server_info si ON si.id=o.server_id LEFT JOIN users u ON u.userid=o.userid WHERE o.status=1 AND s.type='pasarguard' ORDER BY o.id ASC LIMIT 300";
$res=$connection->query($sql);
if($res){
 while($order=$res->fetch_assoc()){
  $state=pgExpiryLiveState((int)$order['server_id'],$order['remark'],$time);
  if(empty($state['valid'])){
      // Never infer expiry from stale DB data when live panel data is unavailable.
      error_log('PG 3-day check skipped order '.$order['id'].': '.($state['error']??'invalid live response'));
      continue;
  }

  $warningEnded=!empty($state['warning_ended']);
  $safeToDelete=!empty($state['safe_to_delete']);
  $warnedAt=(int)$order['expired_warned_at'];
  $deleteAfter=(int)$order['delete_after'];

  if(!$warningEnded){
    if($warnedAt>0 || $deleteAfter!==0){
      // Renewal or positive remaining volume/date detected: cancel pending deletion immediately.
      $stmt=$connection->prepare("UPDATE orders_list SET expired_warned_at=0,delete_after=0,notif=0 WHERE id=?");
      $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
    }
    continue;
  }

  if($warnedAt===0){
    // Record the warning once. The 3-day deletion countdown starts only if every configured limit is ended.
    $due=$safeToDelete?($time+(3*86400)):0;
    $notifValue=$due>0?$due:0;
    $stmt=$connection->prepare("UPDATE orders_list SET expired_warned_at=?,delete_after=?,notif=? WHERE id=? AND expired_warned_at=0");
    $stmt->bind_param('iiii',$time,$due,$notifValue,$order['id']);
    $stmt->execute(); $claimed=($stmt->affected_rows===1); $stmt->close();
    if($claimed && $pgGlobalAlerts && (int)$order['pg_expiry_alerts']===1){
      $reasons=[];
      if(!empty($state['volume_ended'])) $reasons[]='حجم';
      if(!empty($state['date_ended'])) $reasons[]='تاریخ';
      $reasonText=$reasons?implode(' و ',$reasons):'سرویس';
      $deleteNotice=$safeToDelete
          ? "مهلت 3 روزه این اشتراک آغاز شد و پس از پایان مهلت، وضعیت زنده پنل دوباره بررسی می‌شود."
          : "تا زمانی که تاریخ یا حجم دیگری باقی باشد، این اشتراک وارد مرحله حذف خودکار نمی‌شود.";
      $kb=json_encode(['inline_keyboard'=>[[['text'=>'🔁 تمدید این اشتراک','callback_data'=>'pgRenewMenu'.$order['id']]]]],JSON_UNESCAPED_UNICODE);
      sendMessage("⚠️ {$reasonText} اشتراک «{$order['remark']}» به پایان رسیده است.\n\n{$deleteNotice}",$kb,null,$order['userid']);
    }
    continue;
  }

  if(!$safeToDelete){
    // One dimension is still available. Keep the one-time warning marker, but cancel deletion countdown.
    if($deleteAfter!==0){
      $stmt=$connection->prepare("UPDATE orders_list SET delete_after=0,notif=0 WHERE id=?");
      $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
    }
    continue;
  }

  if($safeToDelete && $deleteAfter===0){
    // The service has now become fully expired. Start a fresh 3-day grace period from this moment.
    $due=$time+(3*86400);
    $stmt=$connection->prepare("UPDATE orders_list SET delete_after=?,notif=? WHERE id=? AND delete_after=0");
    $stmt->bind_param('iii',$due,$due,$order['id']); $stmt->execute(); $stmt->close();
    continue;
  }

  if($safeToDelete && $deleteAfter>0 && $deleteAfter <= $time){
    // Critical second live check immediately before deletion to close renewal/cron race conditions.
    $fresh=pgExpiryLiveState((int)$order['server_id'],$order['remark'],time());
    if(empty($fresh['valid']) || empty($fresh['safe_to_delete'])){
        // If any date or volume remains — or panel response is uncertain — deletion is cancelled.
        $stmt=$connection->prepare("UPDATE orders_list SET expired_warned_at=0,delete_after=0,notif=0 WHERE id=?");
        $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
        $reason=empty($fresh['valid'])?($fresh['error']??'اطلاعات زنده نامعتبر'):'حجم یا تاریخ هنوز باقی مانده است';
        error_log('PG 3-day delete protected order '.$order['id'].': '.$reason);
        continue;
    }

    $oldDue=(int)$order['delete_after']; $claim=-1;
    $stmt=$connection->prepare("UPDATE orders_list SET delete_after=? WHERE id=? AND delete_after=?");
    $stmt->bind_param('iii',$claim,$order['id'],$oldDue); $stmt->execute(); $claimed=($stmt->affected_rows===1); $stmt->close();
    if(!$claimed) continue;

    // Correct panel-specific delete call. The previous build incorrectly called deleteMarzban().
    $del=removePasarguardUser((int)$order['server_id'],$order['remark']);
    $deleteOk=($del!==null && (!is_object($del) || !isset($del->success) || $del->success!==false));
    if($deleteOk){
      $deletedAt=time();
      // Build and send admin report before removing the DB row.
      $order['delete_after']=$oldDue;
      sendToAdmins(pgExpiryAdminDeleteReport($order,$fresh,$deletedAt,$del),null,'HTML');

      $stmt=$connection->prepare("DELETE FROM orders_list WHERE id=?");
      $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
      if($pgGlobalAlerts && (int)$order['pg_expiry_alerts']===1){
        sendMessage("🗑 اشتراک «{$order['remark']}» پس از پایان کامل محدودیت‌های فعال و گذشت 3 روز، از پنل و لیست اشتراک‌های شما حذف شد.",null,null,$order['userid']);
      }
    }else{
      $stmt=$connection->prepare("UPDATE orders_list SET delete_after=? WHERE id=? AND delete_after=-1");
      $stmt->bind_param('ii',$oldDue,$order['id']); $stmt->execute(); $stmt->close();
      $err=is_object($del)?($del->msg??'خطای نامشخص'):'خطای نامشخص';
      sendToAdmins("#خطای_حذف_3_روزه\n❌ حذف خودکار اشتراک <code>".pgExpiryHtml($order['remark'])."</code> از پنل پاسارگاد ناموفق بود.\n🧾 سفارش: <code>".(int)$order['id']."</code>\n👤 کاربر: <code>".pgExpiryHtml($order['userid'])."</code>\n🌐 خطا: <code>".pgExpiryHtml($err)."</code>",null,'HTML');
    }
  }
 }
}

// Existing warning logic below is kept for non-PasarGuard panels.

if(file_exists("warnOffset.txt")) $warnOffset = file_get_contents("warnOffset.txt");
else $warnOffset = 0;
$limit = 50;

$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND (`notif`=0 OR `notif` = -1) ORDER BY `id` ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $limit, $warnOffset);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if($orders){
    if($orders->num_rows>0){
        while ($order = $orders->fetch_assoc()){
            $send = false;
    	    $from_id = $order['userid'];
    	    $token = $order['token'];
            $remark = $order['remark'];
            $uuid = $order['uuid']??"0";
            $server_id = $order['server_id'];
            $inbound_id = $order['inbound_id'];
            $links_list = $order['link']; 
            $notif = $order['notif'];
            $expiryTime = "";
            $amount = $order['amount'];

        	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        	$stmt->bind_param('i', $server_id);
        	$stmt->execute();
        	$serverConfig = $stmt->get_result()->fetch_assoc();
        	$stmt->close();
        	$serverType = $serverConfig['type'];
            if($serverType === 'pasarguard') continue;
            $panel_url = $serverConfig['panel_url'];

            
            $found = false;
            $logedIn = false;
            
            if($serverType == "marzban"){
                $info = getMarzbanUser($server_id, $remark);
                if(isset($info->username)){
                    $found = true;
                    $logedIn = true;
                    $total = $info->data_limit;
                    $totalLeft = $total - $info->used_traffic;
                    $expiryTime = $info->expire;
                    $enable = $info->status == "active"?true:false;
                }elseif(isset($info->detail)){
                    if($info->detail == "User not found") $logedIn = true;
                }
            }else{
                $response = getJson($server_id); 
                if($response->success){
                    $response = $response->obj;
                    $logedIn = true;
                    foreach($response as $row){
                        if($inbound_id == 0) { 
                            $clients = json_decode($row->settings)->clients;
                            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                                $found = true;
                                $total = $row->total;
                                $up = $row->up;
                                $down = $row->down;
                                $expiryTime = substr($row->expiryTime, 0, -3);
                                $enable = $row->enable;
                                break;
                            }
                        }else{
                            if($row->id == $inbound_id) {
                                $settings = json_decode($row->settings, true); 
                                $clients = $settings['clients'];
                                
                                $clientsStates = $row->clientStats;
                                foreach($clients as $key => $client){
                                    if($client['id'] == $uuid || $client['password'] == $uuid){
                                        $found = true;
                                        $email = $client['email'];
                                        $emails = array_column($clientsStates,'email');
                                        $emailKey = array_search($email,$emails);
                                        
                                        $total = $client['totalGB'];
                                        $up = $clientsStates[$emailKey]->up;
                                        $enable = $clientsStates[$emailKey]->enable;
                                        $down = $clientsStates[$emailKey]->down; 
                                        $expiryTime = substr($clientsStates[$emailKey]->expiryTime, 0, -3);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    $totalLeft = $total - $up - $down;
                }
            }
            if(!$found) continue;
            
            $leftgb = round( ($totalLeft) / 1073741824, 2);
            if($expiryTime != null && $total != null && $expiryTime >= 0 && $notif == 0){
                $send = "";
                if($expiryTime < time() + 86400) $send = "روز"; elseif($leftgb < 1) $send = "گیگ";
                if($send != ""){  
                    $msg = "💡 کاربر گرامی، 
        از سرویس اشتراک $remark تنها (۱ $send) باقی مانده است. میتواند از قسمت خرید های من سرویس فعلی خود را تمدید کنید یا سرویس جدید خریداری کنید.";
                    sendMessage( $msg, null, null, $from_id);
                    
                    $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= -1 WHERE `uuid`=?");
                    $stmt->bind_param("s", $uuid);
                    $stmt->execute();
                    $stmt->close();
                }
            }elseif(!$enable){
                $newTIme = $time + 86400 * 2;

                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= ? WHERE `uuid`=?");
                $stmt->bind_param("is", $newTIme, $uuid);
                $stmt->execute();
                $stmt->close();
            }
        }
        file_put_contents("warnOffset.txt", $warnOffset + $limit);
    }else unlink('warnOffset.txt');
}


$stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `notif` > 0 AND `notif` < ? LIMIT 50");
$stmt->bind_param("i", $time);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

if($orders){
    if($orders->num_rows>0){
        while ($order = $orders->fetch_assoc()){
            $send = false;
    	    $from_id = $order['userid'];
    	    $token = $order['token'];
            $remark = $order['remark'];
            $uuid = $order['uuid']??"0";
            $server_id = $order['server_id'];
            $inbound_id = $order['inbound_id'];
            $links_list = $order['link']; 
            $notif = $order['notif'];
            
        	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
        	$stmt->bind_param('i', $server_id);
        	$stmt->execute();
        	$serverConfig = $stmt->get_result()->fetch_assoc();
        	$stmt->close();
        	$serverType = $serverConfig['type'];
            if($serverType === 'pasarguard') continue;
            $panel_url = $serverConfig['panel_url'];

            $found = false;
            $logedIn = false;
            
            if($serverType == "marzban"){
                $info = getMarzbanUser($server_id, $remark);
                if(isset($info->username)){
                    $found = true;
                    $logedIn = true;
                    $total = $info->data_limit;
                    $totalLeft = $total - $info->used_traffic;
                    $expiryTime = $info->expire;
                    $enable = $info->status == "active"?true:false;
                }elseif(isset($info->detail)){
                    if($info->detail == "User not found") $logedIn = true;
                }
            }else{
                $response = getJson($server_id); 
                if($response->success){
                    $logedIn = true;
                    $response = $response->obj;
                    foreach($response as $row){
                        if($inbound_id == 0) {
                            $clients = json_decode($row->settings)->clients;
                            if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                                $total = $row->total;
                                $up = $row->up;
                                $down = $row->down;
                                $expiryTime = substr($row->expiryTime, 0, -3);
                                $enable = $row->enable;
                                $found = true;
                                break;
                            }
                        }else{
                            if($row->id == $inbound_id) {
                                $settings = json_decode($row->settings, true); 
                                $clients = $settings['clients'];
                                
                                
                                $clientsStates = $row->clientStats;
                                foreach($clients as $key => $client){
                                    if($client['id'] == $uuid || $client['password'] == $uuid){
                                        $email = $client['email'];
                                        $emails = array_column($clientsStates,'email');
                                        $emailKey = array_search($email,$emails);
                                        
                                        $total = $client['totalGB'];
                                        $up = $clientsStates[$emailKey]->up;
                                        $enable = $clientsStates[$emailKey]->enable;
                                        $down = $clientsStates[$emailKey]->down; 
                                        $expiryTime = substr($clientsStates[$emailKey]->expiryTime, 0, -3);
                                        $found = true;
                                        break;
                                    }
                                }
                            }
                        }
                    } 
                    $totalLeft = $total - $up - $down;
                }
            }
            
            if(!$found && !$logedIn) continue;
            
            $leftgb = round( ($totalLeft) / 1073741824, 2);
            if($expiryTime <= time()) $send = true; elseif($leftgb <= 0) $send = true;
            if($send){
                if($serverType == "marzban") $res = deleteMarzban($server_id, $remark);
                else{if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1); else $res = deleteInbound($server_id, $uuid, 1); }
        		if(!is_null($res)){
                    $msg = "💡 کاربر گرامی،
    اشتراک سرویس $remark منقضی شد و از لیست سفارش ها حذف گردید. لطفا از فروشگاه, سرویس جدید خریداری کنید.";
                    sendMessage( $msg, null, null, $from_id);
                    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `uuid`=?");
                    $stmt->bind_param("s", $uuid);
                    $stmt->execute();
                    $stmt->close();
                    continue;
        		}
            }                
            else{
                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif`= 0 WHERE `uuid`=?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}
