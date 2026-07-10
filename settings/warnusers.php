<?php
require_once __DIR__ . "/../baseInfo.php";
require_once __DIR__ . "/../config.php";
$time=time();

// PasarGuard: warn exactly when volume/date ends, allow 3 days for renewal, then delete.
$sql="SELECT o.*,u.pg_renew_suggestion FROM orders_list o JOIN server_config s ON s.id=o.server_id LEFT JOIN users u ON u.userid=o.userid WHERE o.status=1 AND s.type='pasarguard' ORDER BY o.id ASC LIMIT 300";
$res=$connection->query($sql);
if($res){
 while($order=$res->fetch_assoc()){
  $info=getPasarguardUserInfo((int)$order['server_id'],$order['remark']);
  if(!$info || (!isset($info->username) && empty($info->success))) continue;
  $total=(float)($info->data_limit ?? $info->traffic_limit ?? $info->volume ?? $info->total ?? 0);
  $used=(float)($info->used_traffic ?? $info->traffic_used ?? $info->used ?? 0);
  $expire=(int)($info->expire ?? $info->expiry_time ?? $info->expires_at ?? $order['expire_date'] ?? 0);
  if($expire>20000000000) $expire=(int)floor($expire/1000);
  $volumeEnded=($total>0 && $used >= $total);
  $dateEnded=($expire>0 && $expire <= $time);
  $ended=$volumeEnded || $dateEnded;
  if($ended && (int)$order['expired_warned_at']===0){
    $reason=$volumeEnded && $dateEnded ? 'حجم و تاریخ' : ($volumeEnded ? 'حجم' : 'تاریخ');
    $due=$time+(3*86400);
    $stmt=$connection->prepare("UPDATE orders_list SET expired_warned_at=?,delete_after=?,notif=? WHERE id=?");
    $stmt->bind_param('iiii',$time,$due,$due,$order['id']); $stmt->execute(); $stmt->close();
    if((int)($order['pg_renew_suggestion'] ?? 1)===1){
      $kb=json_encode(['inline_keyboard'=>[[['text'=>'🔁 تمدید این اشتراک','callback_data'=>'pgRenewMenu'.$order['id']]]]],JSON_UNESCAPED_UNICODE);
      sendMessage("⚠️ حجم و یا تاریخ اشتراک «{$order['remark']}» به پایان رسیده است.\n\nدرصورتی که تمدید نکنید، بعد از گذشت 3 روز این اشتراک به‌صورت خودکار از لیست شما و پنل حذف خواهد شد.",$kb,null,$order['userid']);
    }
  }elseif(!$ended && ((int)$order['expired_warned_at']>0 || (int)$order['delete_after']>0)){
    $stmt=$connection->prepare("UPDATE orders_list SET expired_warned_at=0,delete_after=0,notif=0 WHERE id=?"); $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
  }elseif($ended && (int)$order['delete_after']>0 && (int)$order['delete_after'] <= $time){
    $del=deleteMarzban((int)$order['server_id'],$order['remark']);
    if($del!==null && (!is_object($del) || !isset($del->success) || $del->success!==false)){
      $stmt=$connection->prepare("DELETE FROM orders_list WHERE id=?"); $stmt->bind_param('i',$order['id']); $stmt->execute(); $stmt->close();
      sendMessage("🗑 اشتراک «{$order['remark']}» به دلیل پایان حجم/تاریخ و عدم تمدید طی 3 روز، از پنل و لیست اشتراک‌های شما حذف شد.",null,null,$order['userid']);
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
