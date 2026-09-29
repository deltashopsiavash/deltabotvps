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

        $txt = "⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
🔰 <b>گزارش درآمد {$rewaredTime} ساعت گذشته</b>

💰 مبلغ: <b>{$totalRewards} تومان</b>

☑️ {$channelLock}
";

        // Users with the highest paid volume today.
        $todayStart = strtotime('today');
        $stmt = $connection->prepare("SELECT p.`user_id`,COUNT(*) AS buy_count,SUM(p.`price`) AS total,u.`name`,u.`username`
            FROM `pays` p LEFT JOIN `users` u ON u.`userid`=p.`user_id`
            WHERE p.`request_date`>=? AND p.`state` IN ('paid','approved','paid_with_wallet')
            GROUP BY p.`user_id`,u.`name`,u.`username` ORDER BY total DESC LIMIT 10");
        if($stmt){
            $stmt->bind_param('i',$todayStart); $stmt->execute(); $top=$stmt->get_result(); $stmt->close();
            if($top && $top->num_rows>0){
                $txt .= "\n\n🏆 <b>بیشترین خرید امروز</b>";
                $rank=1;
                while($r=$top->fetch_assoc()){
                    $uid=(int)$r['user_id'];
                    $nm=trim((string)($r['name']??''));
                    $un=trim((string)($r['username']??''));
                    $who=$un!==''?'@'.htmlspecialchars(ltrim($un,'@'),ENT_QUOTES,'UTF-8'):($nm!==''?htmlspecialchars($nm,ENT_QUOTES,'UTF-8'):'بدون نام');
                    $txt .= "\n{$rank}) <code>{$uid}</code> | {$who} | <b>".number_format((int)$r['total'])." تومان</b> | ".(int)$r['buy_count']." خرید";
                    $rank++;
                }
            }
        }

        // Auto-approved receipts that were finalized during this report window.
        $stmt=$connection->prepare("SELECT `hash_id`,`tracking_code`,`user_id`,`price`,`type`,`approved_at` FROM `pays` WHERE `auto_approved`=1 AND `approved_at`>? ORDER BY `approved_at` DESC LIMIT 20");
        if($stmt){
            $stmt->bind_param('i',$time); $stmt->execute(); $auto=$stmt->get_result(); $stmt->close();
            if($auto && $auto->num_rows>0){
                $txt .= "\n\n🤖 <b>تأییدهای خودکار این بازه</b>";
                while($ar=$auto->fetch_assoc()){
                    $tracking=trim((string)($ar['tracking_code']??'')) ?: deltaEnsurePayTrackingCode((string)$ar['hash_id']);
                    $txt .= "\n• <code>{$tracking}</code> | کاربر <code>".(int)$ar['user_id']."</code> | <b>".number_format((int)$ar['price'])." تومان</b> | ".htmlspecialchars((string)$ar['type'],ENT_QUOTES,'UTF-8');
                }
            }
        }
        sendMessage($txt, null, "HTML", $rewaredChannel);
    }
}    

// Run whenever global auto-approval is on OR at least one user has a force-auto exception.
$hasForcedAuto=false;
$forcedCheck=$connection->query("SELECT 1 FROM `setting` WHERE `type` LIKE 'USER_FORCE_AUTOAPPROVE_%' AND CAST(`value` AS UNSIGNED)>0 LIMIT 1");
if($forcedCheck && $forcedCheck->num_rows>0) $hasForcedAuto=true;

if(($botState['cartToCartAutoAcceptState']??'off')=="on" || $hasForcedAuto){
    $date = strtotime("-" . ($botState['cartToCartAutoAcceptTime']??10) . " minutes");
    // receipt_submitted_at is the important timestamp. Falling back to request_date
    // keeps legacy rows readable, while activation cutoffs below prevent old sweeps.
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `state` = 'have_sent' AND COALESCE(NULLIF(`receipt_submitted_at`,0),`request_date`) <= ?");
    $stmt->bind_param('i', $date);
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();

    while($payInfo = $info->fetch_assoc()){
        $time = time();
        $rowId = (int)$payInfo['id'];
        $price = (int)$payInfo['price'];
        $user_id = (int)$payInfo['user_id'];
        $payType = (string)$payInfo['type'];
        $deviceId = $payInfo['device_id'] ?? '';
        $receiptAt=(int)($payInfo['receipt_submitted_at']??0);
        if($receiptAt<=0) $receiptAt=(int)($payInfo['request_date']??0);

        // USDT receipts require hash/network review by the manager and are never
        // swept by the card-to-card auto-approval worker.
        if(($payInfo['payment_method']??'')==='usdt_bep20') continue;

        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userinfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if(!$userinfo) continue;

        $globalEnabled=(($botState['cartToCartAutoAcceptState']??'off')==='on');
        $globalSince=(int)($botState['cartToCartAutoAcceptSince']??0);
        $globalEligible=$globalEnabled && ($globalSince<=0 || $receiptAt >= $globalSince);

        $forceType="USER_FORCE_AUTOAPPROVE_".$user_id;
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
        $stmt->bind_param('s',$forceType); $stmt->execute(); $forceRow=$stmt->get_result()->fetch_assoc(); $stmt->close();
        $forceSince=(int)($forceRow['value']??0);
        $forceEligible=$forceSince>0 && $receiptAt >= $forceSince;

        if(!$globalEligible && !$forceEligible) continue;

        // Keep the old NO_AUTOAPPROVE flag only as a backwards-compatible global
        // exclusion. A new explicit FORCE_AUTO exception intentionally overrides it.
        if(!$forceEligible){
            $type = "USER_NO_AUTOAPPROVE_" . $user_id;
            $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
            $stmt->bind_param("s", $type);
            $stmt->execute();
            $noAuto = $stmt->get_result()->fetch_assoc()['value']??"0";
            $stmt->close();
            if($noAuto == "1") continue;

            if(($userinfo['is_agent']??0) == 1 && ($botState['cartToCartAutoAcceptType']??2) == 1) continue;
            elseif(($userinfo['is_agent']??0) != 1 && ($botState['cartToCartAutoAcceptType']??2) == 0) continue;
        }

        $agentBought = $payInfo['agent_bought'];
        $approvedAt=time();
        $stmt = $connection->prepare("UPDATE `pays` SET `state`='paid',`auto_approved`=1,`approved_at`=?,`payment_method`=IF(`payment_method`='', 'card_to_card', `payment_method`) WHERE `id`=? AND `state`='have_sent'");
        $stmt->bind_param("ii", $approvedAt, $rowId);
        $stmt->execute();
        $locked=$stmt->affected_rows>0;
        $stmt->close();
        if(!$locked) continue;
        
        
        if($payType == "INCREASE_WALLET"){
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $price, $user_id);
            $stmt->execute();
            $stmt->close();
            
            sendMessage("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد", null, null, $user_id);
        }
        elseif($payType == "BUY_SUB"){
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
            if($acount == 0 and $inbound_id != 0){
                sendMessage('پرداخت شما انجام شد ولی ظرفیت این کانکشن پر شده است، مبلغ ' . number_format($price) . " تومان به کیف پول شما اضافه شد", null,null, $user_id);
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $user_id);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id توسط درگاه اضافه شد میخواست کانفیگ بخره، ظرفیت پر بود",null,null,$admin);                

                exit;
            }
            if($inbound_id == 0) {
                $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $server_info = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            
                if($server_info['ucount'] <= 0) {
                    sendMessage('پرداخت شما انجام شد ولی ظرفیت این سرور پر شده است، مبلغ ' . number_format($price) . " تومان به کیف پول شما اضافه شد", null,null, $user_id);
                    
                    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                    $stmt->bind_param("ii", $price, $user_id);
                    $stmt->execute();
                    $stmt->close();

                    sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id توسط درگاه اضافه شد میخواست کانفیگ بخره، ظرفیت پر بود",null,null,$admin);                
                    exit;
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
            include '../phpqrcode/qrlib.php';
        
            define('IMAGE_WIDTH',540);
            define('IMAGE_HEIGHT',540);
            for($i = 1; $i <= $accountCount; $i++){
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
                    if($serverType == "marzban"){
                        $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                        if(!$response->success){
                            if($response->msg == "User already exists"){
                                $remark .= rand(1111,99999);
                                $response = addMarzbanUser($server_id, $remark, $volume, $days, $fid);
                            }
                        }
                    }else{
                        $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid); 
                        if(!$response->success){
                            if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                            elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);
                            
                            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $fid);
                        } 
                    }
                }else {
                    $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid); 
                    if(!$response->success){
                        if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
        
                        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $fid);
                    } 
                }
                
                if(is_null($response)){
                    sendMessage('پرداخت شما با موفقیت انجام شد ولی گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...مبلغ ' . number_format($price) ." به کیف پولت اضافه شد",null,null, $user_id);
                    
                    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                    $stmt->bind_param("ii", $price, $user_id);
                    $stmt->execute();
                    $stmt->close();

                    sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id توسط درگاه اضافه شد میخواست کانفیگ بخره، اتصال به سرور برقرار نبود",null,null,$admin);                
                    exit;
                }
                if($response == "inbound not Found"){
                    sendMessage("پرداخت شما با موفقیت انجام شد ولی ❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...مبلغ " . number_format($price) . " به کیف پول شما اضافه شد",null,null,$user_id);
            
                    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                    $stmt->bind_param("ii", $price, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    
                    sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id توسط درگاه اضافه شد میخواست کانفیگ بخره، ولی انباند پیدا نشد",null,null,$admin);                
                	exit;
                }
                if(!$response->success){
                    sendMessage('پرداخت شما با موفقیت انجام شد ولی خطا داد لطفا سریع به مدیر بگو ... مبلغ '. number_format($price) . " تومان به کیف پولت اضافه شد",null,null,$user_id);
                    sendMessage("خطای سرور {$server_info['title']}:\n\n" . $response->msg, null, null, $admin);
                    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                    $stmt->bind_param("ii", $price, $user_id);
                    $stmt->execute();
                    $stmt->close();
                    sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id توسط درگاه اضافه شد میخواست کانفیگ بخره، ولی خطا داد",null,null,$admin);                
                    exit;
                }
                
                if($serverType == "marzban"){
                    $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                    $subLink = (xuiBotStateIsOn($botState, 'subLinkState', 'on') || xuiBotStateIsOn($botState, 'qrSubState', 'off'))?xuiResolveClientSubLink($server_id, $panelUrl, $response->sub_link ?? '', $inbound_id, $uniqid, $remark):"";
                    $vraylink = [$subLink];
                    $vray_link = json_encode($response->vray_links);
                }else{
                    $token = RandomString(30);
                    $subLink = (xuiBotStateIsOn($botState, 'subLinkState', 'on') || xuiBotStateIsOn($botState, 'qrSubState', 'off'))?xuiResolveClientSubLink($server_id, $panelUrl, $response->sub_link ?? '', $inbound_id, $uniqid, $remark):"";
            
                    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
                    $vray_link = json_encode($vraylink);
                }
                foreach($vraylink as $link){
                $acc_text = "
                
        😍 سفارش جدید شما
        📡 پروتکل: $protocol
        🔮 نام سرویس: $remark
        🔋حجم سرویس: $volume گیگ
        ⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
        " . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"
        💝 config : <code>$link</code>":"");
        
$acc_text .= xuiBuildOrderLinksText($botState, $botUrl, $uniqid, $subLink);
                      
                    $file = RandomString() .".png";
                    $ecc = 'L';
                    $pixel_Size = 11;
                    $frame_Size = 0;
                    
                    QRcode::png(xuiChooseOrderQrPayload($botState, $link, $subLink), $file, $ecc, $pixel_Size, $frame_Size);
                	addBorderImage($file);
                	
                	$backgroundImage = imagecreatefromjpeg("QRCode.jpg");
                    $qrImage = imagecreatefrompng($file);
                    
                    $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                    imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                    imagepng($backgroundImage, $file);
                    imagedestroy($backgroundImage);
                    imagedestroy($qrImage);
        
                	$res = sendPhoto($botUrl . "/settings/" . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $user_id);
                    unlink($file);
                }
                
                $agentBought = $payInfo['agent_bought'];
                
                $stmt = $connection->prepare("INSERT INTO `orders_list` 
                    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
                    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
                $stmt->bind_param("ssiiisssisiiii", $user_id, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agentBought);
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
                $stmt->bind_param("ii", $accountCount, $server_id);
                $stmt->execute();
                $stmt->close();
            }else{
                $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
                $stmt->bind_param("ii", $accountCount, $fid);
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
        
            sendMessage("✅سرویس $remark با موفقیت تمدید شد",getMainKeys(), null, $user_id);
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
                
                sendMessage("✅$volume روز به مدت زمان سرویس شما اضافه شد",getMainKeys(), null, $user_id);
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
                sendMessage( "✅$volume گیگ به حجم سرویس شما اضافه شد",getMainKeys(), null, $user_id);
            }else {
                sendMessage("پرداخت شما با موفقیت انجام شد ولی مشکل فنی در ارتباط با سرور. لطفا سلامت سرور را بررسی کنید مبلغ " . number_format($price) . " تومان به کیف پول شما اضافه شد",null,null,$user_id);
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $price, $user_id);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $user_id اضافه شد، میخواست حجم کانفیگشو افزایش بده",null,null,$admin);                
            }
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
            
            $remark = $payInfo['description'];
            $inbound_id = $payInfo['volume']; 
            
            if($isMarzban){
                $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
            }else{
                if($inbound_id > 0)
                    $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
                else
                    $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
            }
            
        	if(is_null($response)){
        		sendMessage('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',null,null,$user_Id);
        		exit;
        	}
        	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        	$stmt->bind_param("iiisii", $user_id, $server_id, $inbound_id, $remark, $price, $time);
        	$stmt->execute();
        	$stmt->close();

            sendMessage("✅سرویس $remark با موفقیت تمدید شد",null,null,$user_id);
        }
        
        $tracking=deltaEnsurePayTrackingCode((string)$payInfo['hash_id']);
        if(!empty($rewaredChannel)){
            $safeName=htmlspecialchars((string)($userinfo['name']??''),ENT_QUOTES,'UTF-8');
            $safeUsername=htmlspecialchars((string)($userinfo['username']??''),ENT_QUOTES,'UTF-8');
            $safeType=htmlspecialchars((string)$payType,ENT_QUOTES,'UTF-8');
            $method=htmlspecialchars(deltaPaymentMethodLabel((string)($payInfo['payment_method']??'card_to_card')),ENT_QUOTES,'UTF-8');
            $source=$forceEligible?'استثنای دائمی کاربر':'تأیید خودکار عمومی';
            $desc=trim((string)($payInfo['description']??''));
            if(mb_strlen($desc,'UTF-8')>300) $desc=mb_substr($desc,0,300,'UTF-8').'…';
            $desc=htmlspecialchars($desc,ENT_QUOTES,'UTF-8');
            $created=(int)($payInfo['request_date']??0);
            $submitted=(int)($payInfo['receipt_submitted_at']??0);
            $report="🤖 <b>گزارش کامل تأیید خودکار رسید</b>\n\n"
                ."🔖 کد پیگیری: <code>{$tracking}</code>\n"
                ."👤 آیدی عددی: <code>{$user_id}</code>\n"
                ."👨‍💼 نام: {$safeName}\n"
                ."⚡ نام کاربری: ".($safeUsername!==''?'@'.$safeUsername:'ندارد')."\n"
                ."🧾 نوع سفارش: <code>{$safeType}</code>\n"
                ."💰 مبلغ: <b>".number_format($price)." تومان</b>\n"
                ."💳 روش پرداخت: <b>{$method}</b>\n"
                ."⚙️ منبع تأیید: <b>{$source}</b>\n"
                ."📦 Plan ID: <code>".(int)($payInfo['plan_id']??0)."</code>\n"
                ."🔋 حجم ثبت‌شده: <b>".(float)($payInfo['volume']??0)."</b>\n"
                ."⏰ روز ثبت‌شده: <b>".(float)($payInfo['day']??0)."</b>\n"
                ."🕒 ساخت فاکتور: <code>".($created>0?date('Y-m-d H:i:s',$created):'-')."</code>\n"
                ."📨 ثبت رسید: <code>".($submitted>0?date('Y-m-d H:i:s',$submitted):'-')."</code>\n"
                ."✅ زمان تأیید: <code>".date('Y-m-d H:i:s',$approvedAt)."</code>";
            if($desc!=='') $report.="\n📝 توضیحات: <code>{$desc}</code>";
            sendMessage($report,null,'HTML',$rewaredChannel);
        }

        if(!empty($payInfo['message_id']) && !empty($payInfo['chat_id'])){
            editKeys(json_encode(['inline_keyboard'=>[[['text'=>"خودکار تأیید شد",'callback_data'=>"deltach"]]]]), $payInfo['message_id'], $payInfo['chat_id']);
        }
    }
}