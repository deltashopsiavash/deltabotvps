<?php
require_once __DIR__ . "/../baseInfo.php";

// Support running for reseller bots (child bots) from CLI / cron
$bid = 0;
if(isset($_GET['bid'])) $bid = (int)$_GET['bid'];
if(php_sapi_name() === 'cli'){
    $bid = (int)($argv[1] ?? $bid);
}
if($bid > 0){
    $_GET['bid'] = $bid; // let config.php switch token + DB
}

require_once __DIR__ . "/../config.php";

// If running on mother (no bid), also run for all active reseller bots
if($bid <= 0){
    if(function_exists('ensureResellerTables')){
        ensureResellerTables();
        $res = $connection->query("SELECT `id` FROM `reseller_bots` WHERE `status`=1 AND `is_deleted`=0");
        if($res){
            while($r = $res->fetch_assoc()){
                $rid = (int)$r['id'];
                if($rid <= 0) continue;
                $cmd = 'nohup php ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg((string)$rid) . ' >/dev/null 2>&1 &';
                @shell_exec($cmd);
            }
        }
    }
}

$sellState=$botState['sellState']=="off"?"خاموش ❌":"روشن ✅";
$searchState=$botState['searchState']=="off"?"خاموش ❌":"روشن ✅";
$rewaredTime = ($botState['rewaredTime']??0);
$rewaredChannel = $botState['rewardChannel'];

if($rewaredTime>0 && $rewaredChannel != null){
    $lastTime = $botState['lastRewardMessage']??0;
    if(time() > $lastTime){
        $time = time() - ($rewaredTime * 60 * 60);
        
        $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved')");
        $stmt->bind_param("i", $time);
        $stmt->execute();
        $totalRewards = number_format($stmt->get_result()->fetch_assoc()['total']);
        $stmt->close();
        
        $botState['lastRewardMessage']=time() + ($rewaredTime * 60 * 60);
        
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
        $stmt->execute();
        $isExists = $stmt->get_result();
        $stmt->close();
        if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
        else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
        $newData = json_encode($botState);
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $newData);
        $stmt->execute();
        $stmt->close();

        $todayStart = strtotime('today');
        $topText = "";
        $stmt = $connection->prepare("SELECT p.user_id, SUM(p.price) AS total, COUNT(*) AS cnt, u.name, u.username FROM pays p LEFT JOIN users u ON u.userid=p.user_id WHERE p.request_date>=? AND p.type<>'INCREASE_WALLET' AND p.state IN ('paid','approved','paid_with_wallet') GROUP BY p.user_id ORDER BY total DESC LIMIT 5");
        if($stmt){
            $stmt->bind_param("i",$todayStart); $stmt->execute(); $top=$stmt->get_result(); $stmt->close();
            $rank=1;
            while($tr=$top->fetch_assoc()){
                $nm=trim((string)($tr['name']??'')); $un=trim((string)($tr['username']??''));
                $label=$nm!==''?$nm:($un!==''?'@'.$un:(string)$tr['user_id']);
                $topText .= "\n{$rank}) {$label} | ".number_format((int)$tr['total'])." تومان | ".(int)$tr['cnt']." خرید";
                $rank++;
            }
        }
        if($topText==='') $topText="\nهنوز خریدی ثبت نشده است.";

        $txt = "⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
🔰 درآمد من در $rewaredTime ساعت گذشته

💰 مبلغ: $totalRewards تومان

🏆 بیشترین خریدهای امروز:
$topText

☑️ $channelLock

";
        sendMessage($txt, null, null, $rewaredChannel);
    }
}    

$deltaForcedExists = false;
$deltaForcedQ = $connection->query("SELECT id FROM setting WHERE type LIKE 'USER_FORCE_AUTOAPPROVE_%' AND value='1' LIMIT 1");
if($deltaForcedQ && $deltaForcedQ->num_rows>0) $deltaForcedExists = true;

if(($botState['cartToCartAutoAcceptState']??'off')=="on" || $deltaForcedExists){
    $date = strtotime("-" . ($botState['cartToCartAutoAcceptTime']??10) . " minutes");
    $autoFrom = function_exists('deltaAutoApproveFrom') ? deltaAutoApproveFrom() : 0;
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'have_sent' ORDER BY `id` ASC");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();

    while($payInfo = $info->fetch_assoc()){
        $time = time();
        $rowId = $payInfo['id'];
        $price = $payInfo['price'];
        $user_id = $payInfo['user_id'];
        $payType = $payInfo['type'];
        $deviceId = $payInfo['device_id'];
        if(getSettingValue('AUTOAPPROVE_FAILED_'.(int)$rowId,'0')==='1') continue;
        // A photo and transaction hash do not prove an on-chain USDT deposit.
        // Crypto receipts must remain in the administrator's review queue.
        if(function_exists('deltaIsUsdtInvoice') && deltaIsUsdtInvoice($payInfo['hash_id'])) continue;
        // The invoice date is not a receipt date. Legacy receipts without a
        // submission marker always remain available for manual review.
        if(!function_exists('deltaReceiptHasSubmissionMarker') || !deltaReceiptHasSubmissionMarker($payInfo['hash_id'])) continue;
        if(!in_array($payType,['INCREASE_WALLET','BUY_SUB','RENEW_ACCOUNT','RENEW_SCONFIG'],true)
            && !preg_match('/^INCREASE_(?:DAY|VOLUME)_\d+_\d+$/',$payType)
            && !preg_match('/^PG_RENEW_(?:FULL|VOLUME|DAY)_\d+_\d+$/',$payType)) continue;
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $policy=function_exists('deltaAutoApprovePolicy') ? deltaAutoApprovePolicy($user_id) : 'normal';
        if($policy==='never') continue;
        $forcedAuto = $policy==='always';
        $receiptAt = function_exists('deltaReceiptSubmittedAt')
            ? deltaReceiptSubmittedAt($payInfo['hash_id'] ?? '', (int)$payInfo['request_date'])
            : (int)$payInfo['request_date'];
        if($forcedAuto){
            // Enabling the per-user exception must never sweep in that user's older receipts.
            $forceFrom = function_exists('deltaForceAutoApproveFrom') ? deltaForceAutoApproveFrom($user_id) : 0;
            if($forceFrom > 0 && $receiptAt < $forceFrom) continue;
        }else{
            // If the global switch is off, only explicitly forced users may pass.
            if(($botState['cartToCartAutoAcceptState']??'off')!=="on") continue;
            // Delay and reset baseline are based on the actual receipt submission time,
            // not on when the invoice itself was originally created.
            if($receiptAt > $date) continue;
            if($autoFrom > 0 && $receiptAt < $autoFrom) continue;
        }

        if(!$forcedAuto){
            if($userinfo['is_agent'] == 1 && ($botState['cartToCartAutoAcceptType']??2) == 1) continue;
            elseif($userinfo['is_agent'] != 1 && ($botState['cartToCartAutoAcceptType']??2) == 0) continue;
        }
        
        $agentBought = $payInfo['agent_bought'];
        
        // Claim once even if two cron runs overlap or an admin acts at once.
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid' WHERE `id` =? AND `state`='have_sent'");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $claimed=$stmt->affected_rows===1;
        $stmt->close();
        if(!$claimed) continue;

        $autoReport=null;
        unset($remark);
        $track = function_exists('deltaTrackingCode') ? deltaTrackingCode($payInfo['hash_id'] ?? '') : ($payInfo['hash_id'] ?? '');
        $mode = $forcedAuto ? 'استثنای همیشگی کاربر' : 'تأیید خودکار عمومی';
        $nm = trim((string)($userinfo['name'] ?? ''));
        $un = trim((string)($userinfo['username'] ?? ''));
        $detail='';
        if($payType==='BUY_SUB' || $payType==='RENEW_SCONFIG'){
            $pid=(int)$payInfo['plan_id'];
            $planStmt=$connection->prepare('SELECT title,volume,days FROM server_plans WHERE id=? LIMIT 1');
            $planStmt->bind_param('i',$pid); $planStmt->execute(); $plan=$planStmt->get_result()->fetch_assoc(); $planStmt->close();
            if($plan){
                $detail.='📦 پلن: '.htmlspecialchars((string)$plan['title'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n";
                $detail.='🔋 حجم: '.($payType==='BUY_SUB' && (int)$payInfo['volume']>0 ? $payInfo['volume'] : $plan['volume'])." گیگ\n";
                $detail.='⏰ مدت: '.($payType==='BUY_SUB' && (int)$payInfo['day']>0 ? $payInfo['day'] : $plan['days'])." روز\n";
            }
            if($payType==='RENEW_SCONFIG'){
                $conf=json_decode((string)$payInfo['description'],true);
                $detail.='🔮 سرویس: '.htmlspecialchars((string)($conf['remark']??'-'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n";
            }
        }elseif($payType==='RENEW_ACCOUNT' || preg_match('/^(?:INCREASE_(?:DAY|VOLUME)|PG_RENEW_(?:FULL|VOLUME|DAY))_(\d+)_/',$payType,$orderMatch)){
            $oid=$payType==='RENEW_ACCOUNT'?(int)$payInfo['plan_id']:(int)$orderMatch[1];
            $orderStmt=$connection->prepare('SELECT remark FROM orders_list WHERE id=? LIMIT 1');
            $orderStmt->bind_param('i',$oid); $orderStmt->execute(); $order=$orderStmt->get_result()->fetch_assoc(); $orderStmt->close();
            if($order) $detail.='🔮 سرویس: '.htmlspecialchars((string)$order['remark'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n";
        }
        $autoReport = "🤖 گزارش تأیید خودکار رسید\n\n".
            "👤 آیدی عددی: <code>{$user_id}</code>\n".
            "👨‍💼 نام: ".htmlspecialchars($nm,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n".
            "⚡️ نام کاربری: ".htmlspecialchars($un,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n".
            "🧾 نوع تراکنش: <code>".htmlspecialchars((string)$payType,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
            $detail.
            "💰 مبلغ: ".number_format((int)$price)." تومان\n".
            "🔖 کد پیگیری: <code>{$track}</code>\n".
            "⚙️ روش تأیید: {$mode}\n".
            "📌 وضعیت: تأیید شد و سفارش انجام شد\n".
            "🕒 زمان: ".date('Y-m-d H:i:s');

        if($payType == "INCREASE_WALLET"){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $user_id);
            $stmt->execute();
            $stmt->close();
            
            sendMessage(deltaAppendTracking("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد",$payInfo['hash_id']), null, 'HTML', $user_id);
        }
        elseif($payType == "BUY_SUB"){
            // A failed panel request needs human review, not a wallet refund.
            // Keep the receipt and its approval buttons available for retry.
            $awaitManualProvision = function($reason) use ($connection,$rowId,$payInfo,$user_id,$admin){
                $stmt=$connection->prepare("UPDATE pays SET state='need_admin' WHERE id=? AND state='paid'");
                $stmt->bind_param('i',$rowId); $stmt->execute(); $stmt->close();
                $track=deltaTrackingCode($payInfo['hash_id']);
                sendMessage("⏳ پرداخت شما تأیید شد، اما تحویل سرویس نیاز به بررسی مدیر دارد.\n🔖 کد پیگیری: <code>{$track}</code>",null,'HTML',$user_id);
                sendToAdmins("⚠️ تحویل خودکار سفارش <code>{$track}</code> انجام نشد: ".htmlspecialchars($reason,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n👤 کاربر: <code>{$user_id}</code>\n".deltaOrderDetails($payInfo),getReceiptAdminKeyboard('accept'.$payInfo['hash_id'],'declineOffer'.$payInfo['hash_id'].'_'.$user_id,$user_id),'HTML');
            };
            $fid = $payInfo['plan_id']; 
            $volume = $payInfo['volume'];
            $days = $payInfo['day'];
            $description = $payInfo['description'];
            
            
            $acctxt = '';
            
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $file_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if($volume == 0 && $days == 0){
                $volume = $file_detail['volume'];
                $days = $file_detail['days'];
            }
            
            $date = time();
            $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
            $expire_date = $date + (86400 * $days);
            $type = $file_detail['type'];
            $protocol = $file_detail['protocol'];
            $price = $payInfo['price'];   
            
            $server_id = $file_detail['server_id'];
            $netType = $file_detail['type'];
            $acount = $file_detail['acount'];
            $inbound_id = $file_detail['inbound_id'];
            $limitip = $file_detail['limitip'];
            $rahgozar = $file_detail['rahgozar'];
            $customPath = $file_detail['custom_path'];
            $customPort = $file_detail['custom_port'];
            $customSni = $file_detail['custom_sni'];
            
            $accountCount = function_exists('xuiResolvePayAccountCount') ? xuiResolvePayAccountCount($payInfo) : (($payInfo['agent_count'] ?? 0) != 0 ? (int)$payInfo['agent_count'] : 1);
            $eachPrice = $price / $accountCount;
            $countStmt=$connection->prepare('SELECT COUNT(*) AS c FROM orders_list WHERE userid=? AND fileid=? AND transid=?');
            $hash=(string)$payInfo['hash_id'];
            $countStmt->bind_param('iis',$user_id,$fid,$hash); $countStmt->execute();
            $existing=min($accountCount,(int)($countStmt->get_result()->fetch_assoc()['c']??0)); $countStmt->close();
            $remainingCount=$accountCount-$existing;
            if($inbound_id != 0 && $acount < $remainingCount){
                $awaitManualProvision('ظرفیت کانکشن کافی نیست');
                continue;
            }
            if($inbound_id == 0) {
                $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $server_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            
                if((int)($server_info['ucount']??0) < $remainingCount) {
                    $awaitManualProvision('ظرفیت سرور کافی نیست');
                    continue;
                }
            }
        
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $serverInfo = $stmt->get_result()->fetch_assoc();
            $serverTitle = $serverInfo['title'];
            $srv_remark = $serverInfo['remark'];
            $stmt->close();
        
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $serverConfig = $stmt->get_result()->fetch_assoc();
            $serverType = $serverConfig['type'];
            $portType = $serverConfig['port_type'];
            $panelUrl = $serverConfig['panel_url'];
            $stmt->close();
            include_once '../phpqrcode/qrlib.php';
        
            define('IMAGE_WIDTH',540);
            define('IMAGE_HEIGHT',540);
            for($i = $existing+1; $i <= $accountCount; $i++){
                $uniqid = generateRandomString(42,$protocol);
                
                $savedinfo = file_get_contents('temp.txt');
                $savedinfo = explode('-',$savedinfo);
                $port = $savedinfo[0] + 1;
                $last_num = $savedinfo[1] + 1;
                
                if($botState['remark'] == "digits"){
                    $rnd = rand(10000,99999);
                    $remark = "{$srv_remark}-{$rnd}";
                }
                elseif($botState['remark'] == "manual"){
                    $remark = $payInfo['description'];
                }
                else{
                    $rnd = rand(1111,99999);
                    $remark = "{$srv_remark}-{$user_id}-{$rnd}";
                }
                if(!empty($description)) $remark = $description;
                if($portType == "auto"){
                    file_put_contents('temp.txt',$port.'-'.$last_num);
                }else{
                    $port = rand(1111,65000);
                }
                
                if($inbound_id == 0){    
                    if($serverType == "marzban" || $serverType == "pasarguard"){
                        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                        if(is_object($response) && empty($response->success)){
                            if(($response->msg??'') == "User already exists"){
                                $remark .= rand(1111,99999);
                                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                            }
                        }
                    }else{
                        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                        if(is_object($response) && empty($response->success)){
                            if(strstr($response->msg??'', "Duplicate email")) $remark .= RandomString();
                            elseif(strstr($response->msg??'', "Port already exists")) $port = rand(1111,65000);
                            
                            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                        } 
                    }
                }else {
                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                    if(is_object($response) && empty($response->success)){
                        if(strstr($response->msg??'', "Duplicate email")) $remark .= RandomString();
        
                        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                    } 
                }
                
                if(is_null($response)){
                    $awaitManualProvision('اتصال به سرور برقرار نشد');
                    continue 2;
                }
                if($response == "inbound not Found"){
                    $awaitManualProvision('انباند با شناسه '.$inbound_id.' پیدا نشد');
                    continue 2;
                }
                if(!is_object($response) || empty($response->success)){
                    $awaitManualProvision('خطای پنل: '.(string)($response->msg??'پاسخ نامعتبر'));
                    continue 2;
                }
                
                if($serverType == "marzban" || $serverType == "pasarguard"){
                    $payload=xuiPreparePanelOrderPayload($server_id,$panelUrl,$serverType,$response,$remark,$inbound_id);
                    $subLink=$payload['subLink']; $token=$payload['token']; $uniqid=$payload['uuid'];
                    $vraylink=$payload['links']; $vray_link=$payload['json'];
                }else{
                    $token = RandomString(30);
                    $subLink = (xuiBotStateIsOn($botState, 'subLinkState', 'on') || xuiBotStateIsOn($botState, 'qrSubState', 'off'))?xuiResolveClientSubLink($server_id, $panelUrl, $response->sub_link ?? '', $inbound_id, $uniqid, $remark):"";
            
                    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
                    $vray_link = json_encode($vraylink);
                }
                // Use the same delivery path as wallet and manual card purchases.
                (function_exists('npvSendManualLockRequestOrNormal')
                    ? npvSendManualLockRequestOrNormal($user_id,$protocol,$remark,$volume,$days,$botState,$serverType,$vraylink,$botUrl,$uniqid,$subLink,'mainMenu',$file_detail,$payInfo['description']??'',$serverInfo)
                    : xuiSendOrderDeliveryPhoto($user_id,$protocol,$remark,$volume,$days,$botState,$serverType,$vraylink,$botUrl,$uniqid,$subLink,'mainMenu'));
                sendMessage(deltaTrackingLine($hash),null,'HTML',$user_id);

                $agentBought = $payInfo['agent_bought'];
                
                $stmt = $connection->prepare("INSERT INTO `orders_list` 
                    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
                $stmt->bind_param("sssiiisssisiiii", $user_id, $token, $hash, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agentBought);
                $stmt->execute();
                $order = $stmt->get_result(); 
                $stmt->close();
            }
            
            if($userInfo['refered_by'] != null){
                $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
                $stmt->execute();
                $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
                $stmt->close();
                $inviterId = $userInfo['refered_by'];
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $inviteAmount, $inviterId);
                $stmt->execute();
                $stmt->close();
                 
                sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
            }
                
            if($inbound_id == 0) {
                $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - ? WHERE `id`=?");
                $stmt->bind_param("ii", $remainingCount, $server_id);
                $stmt->execute();
                $stmt->close();
            }else{
                $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
                $stmt->bind_param("ii", $remainingCount, $fid);
                $stmt->execute();
                $stmt->close();
            }
        }
        elseif($payType == "RENEW_ACCOUNT"){
            $oid = $payInfo['plan_id'];
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $oid);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $fid = $order['fileid'];
            $remark = $order['remark'];
            $uuid = $order['uuid']??"0";
            $server_id = $order['server_id'];
            $inbound_id = $order['inbound_id'];
            $expire_date = $order['expire_date'];
            $expire_date = ($expire_date > $time) ? $expire_date : $time;
            
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $respd = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $name = $respd['title'];
            $days = $respd['days'];
            $volume = $respd['volume'];
            $price = $payInfo['price'];
            
            $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];
        
            if($serverType == "marzban"){
                $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
            }else{
                if($inbound_id > 0)
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                else
                    $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
            }
            
            if(is_null($response)){
        		sendMessage('پرداخت شما با موفقیت انجام شد ولی مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید، مبلغ ' . number_format($price) . " تومان به کیف پول شما اضافه شد",null,null,$user_id);
        		
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $user_id);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id اضافه شد، میخواست کانفیگش رو تمدید کنه، ولی اتصال به سرور برقرار نبود",null,null,$admin);
            	exit;
            }
            $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
            $newExpire = $time + $days * 86400;
            $stmt->bind_param("ii", $newExpire, $oid);
            $stmt->execute();
            $stmt->close();
            $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
            $stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $price, $time);
            $stmt->execute();
            $stmt->close();
        
            sendMessage(deltaAppendTracking("✅سرویس $remark با موفقیت تمدید شد",$payInfo['hash_id']),getMainKeys(), 'HTML', $user_id);
        }
        elseif(preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo)){
            $orderId = $increaseInfo[1];
            
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $server_id = $orderInfo['server_id'];
            $inbound_id = $orderInfo['inbound_id'];
            $remark = $orderInfo['remark'];
            $uuid = $orderInfo['uuid']??"0";
            
            $planid = $increaseInfo[2];
        
            
            
            $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price = $payInfo['price'];
            $volume = $res['volume'];
        
            $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $serverType = $server_info['type'];
        
            if($serverType == "marzban"){
                $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
            }else{
                if($inbound_id > 0)
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
                else
                    $response = editInboundTraffic($server_id, $uuid, 0, $volume);
            }
            
            if($response->success){
                $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
                $newVolume = $volume * 86400;
                $stmt->bind_param("is", $newVolume, $uuid);
                $stmt->execute();
                $stmt->close();
                
                $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
                $newVolume = $volume * 86400;
                $stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $price, $time);
                $stmt->execute();
                $stmt->close();
                
                sendMessage(deltaAppendTracking("✅$volume روز به مدت زمان سرویس شما اضافه شد",$payInfo['hash_id']),getMainKeys(), 'HTML', $user_id);
            }else {
                sendMessage("پرداخت شما با موفقیت انجام شد ولی به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید مبلغ " . number_format($price) . " تومان به کیف پول شما اضافه شد", $user_id);
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $user_id);
                $stmt->execute();
                $stmt->close();
    
                sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id اضافه شد، میخواست زمان سرویسشو افزایش بده",null,null,$admin);
            }
        }
        elseif(preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo)){
            $orderId = $increaseInfo[1];
            
            $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
            $stmt->bind_param("i", $orderId);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $server_id = $orderInfo['server_id'];
            $inbound_id = $orderInfo['inbound_id'];
            $remark = $orderInfo['remark'];
            $uuid = $orderInfo['uuid']??"0";
            
            $planid = $increaseInfo[2];
            
            $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
            $stmt->bind_param("i", $planid);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $price = $payInfo['price'];
            $volume = $res['volume'];
            
                $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $server_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $serverType = $server_info['type'];
            
                if($serverType == "marzban"){
                    $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
                }else{
                    if($inbound_id > 0)
                        $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
                    else
                        $response = editInboundTraffic($server_id, $uuid, $volume, 0);
                }
                
            if($response->success){
                $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
                $stmt->bind_param("s", $uuid);
                $stmt->execute();
                $stmt->close();
                sendMessage(deltaAppendTracking("✅$volume گیگ به حجم سرویس شما اضافه شد",$payInfo['hash_id']),getMainKeys(), 'HTML', $user_id);
            }else {
                sendMessage("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور. لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($price) . " تومان به کیف پول شما اضافه شد",null,null,$user_id);
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $user_id);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id اضافه شد، میخواست حجم کانفیگشو افزایش بده",null,null,$admin);                
            }
        }
        elseif(preg_match('/^PG_RENEW_(FULL|VOLUME|DAY)_(\d+)_(\d+)$/',$payType,$pgMatch)){
            $oid=(int)$pgMatch[2]; $pid=(int)$pgMatch[3]; $days=0; $volume=0; $fullReset=$pgMatch[1]==='FULL';
            if($fullReset){
                $stmt=$connection->prepare("SELECT days,volume FROM server_plans WHERE id=? LIMIT 1");
                $stmt->bind_param('i',$pid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
                $days=(int)($plan['days']??0); $volume=(float)($plan['volume']??0);
            }else{
                $stmt=$connection->prepare("SELECT amount FROM pg_renew_plans WHERE id=? LIMIT 1");
                $stmt->bind_param('i',$pid); $stmt->execute(); $plan=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if($pgMatch[1]==='DAY') $days=(int)($plan['amount']??0);
                else $volume=(float)($plan['amount']??0);
            }
            $response=($days>0 || $volume>0) ? pgRenewApply($oid,$days,$volume,$fullReset,$fullReset?$pid:0) : null;
            if(!is_object($response) || empty($response->success)){
                $stmt=$connection->prepare("UPDATE pays SET state='have_sent' WHERE id=? AND state='paid'");
                $stmt->bind_param('i',$rowId); $stmt->execute(); $stmt->close();
                upsertSettingValue('AUTOAPPROVE_FAILED_'.$rowId,'1');
                sendToAdmins('⚠️ تمدید خودکار پاسارگارد برای کد '.deltaTrackingCode($payInfo['hash_id']).' ناموفق بود؛ رسید برای بررسی دستی باقی ماند.',null,'HTML');
                continue;
            }
            sendToAdmins(pgRenewBuildAdminReport($payInfo,$oid,$days,$volume,$user_id),null,'HTML');
            sendMessage(deltaAppendTracking("✅ سرویس شما با موفقیت تمدید شد\n➕ حجم: $volume گیگ\n➕ روز: $days روز",$payInfo['hash_id']),null,'HTML',$user_id);
        }
        elseif($payType == "RENEW_SCONFIG"){
            $user_id = $user_id;
            $fid = $payInfo['plan_id']; 
        
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
            $stmt->bind_param("i", $fid);
            $stmt->execute();
            $file_detail = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $volume = $file_detail['volume'];
            $days = $file_detail['days'];
            
            $price = $payInfo['price'];   
            $server_id = $file_detail['server_id'];
            $configInfo = json_decode($payInfo['description'],true);
            $remark = $configInfo['remark'];
            $uuid = $configInfo['uuid'];
            $isMarzban = $configInfo['marzban'];
            
            $inbound_id = $payInfo['volume']; 
            
            if($isMarzban){
                $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
            }else{
                if($inbound_id > 0)
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                else
                    $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
            }
            
	        if(!is_object($response) || empty($response->success)){
                $stmt=$connection->prepare("UPDATE pays SET state='have_sent' WHERE id=? AND state='paid'");
                $stmt->bind_param('i',$rowId); $stmt->execute(); $stmt->close();
                upsertSettingValue('AUTOAPPROVE_FAILED_'.$rowId,'1');
                sendToAdmins('⚠️ تمدید خودکار برای کد '.deltaTrackingCode($payInfo['hash_id']).' ناموفق بود؛ رسید برای بررسی دستی باقی ماند.',null,'HTML');
                continue;
	        }
        	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        	$stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $price, $time);
        	$stmt->execute();
        	$stmt->close();

            sendMessage(deltaAppendTracking("✅سرویس $remark با موفقیت تمدید شد",$payInfo['hash_id']),null,'HTML',$user_id);
        }
        

        if($payType==='BUY_SUB' && !empty($remark)) $autoReport.="\n🔮 ریمارک سرویس: ".htmlspecialchars((string)$remark,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        sendToAdmins($autoReport,null,'HTML');
        if(trim((string)$rewaredChannel)!=='' && !in_array((int)$rewaredChannel,getAllAdminIds(),true)){
            sendMessage($autoReport,null,'HTML',$rewaredChannel);
        }
        if((int)($payInfo['message_id']??0)>0 && !empty($payInfo['chat_id'])){
            $approvedKeys=json_encode(['inline_keyboard'=>[
                [['text'=>'✅ خودکار تأیید شد','callback_data'=>'deltach']],
                [['text'=>'👤 مشخصات کاربر','callback_data'=>'receiptUserInfo_'.(int)$user_id]]
            ]],JSON_UNESCAPED_UNICODE);
            editKeys($approvedKeys,(int)$payInfo['message_id'],(int)$payInfo['chat_id']);
        }
    }
}
