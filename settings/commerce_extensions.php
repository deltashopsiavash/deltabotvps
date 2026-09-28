<?php
// Delta commerce extensions: tracking codes, private discounts, USDT gateway,
// auto-approval baseline/forced users, and pending-order utilities.

if(!function_exists('deltaEnsureCommerceSchema')){
    function deltaEnsureCommerceSchema(){
        global $connection;
        if(!$connection || $connection->connect_error) return;

        if(function_exists('addColumnIfMissing')){
            addColumnIfMissing('pays','tracking_code',"`tracking_code` VARCHAR(8) NULL DEFAULT NULL AFTER `hash_id`");
            addColumnIfMissing('pays','payment_method',"`payment_method` VARCHAR(32) NULL DEFAULT NULL AFTER `state`");
            addColumnIfMissing('pays','usdt_rate_toman',"`usdt_rate_toman` BIGINT NOT NULL DEFAULT 0 AFTER `payment_method`");
            addColumnIfMissing('pays','usdt_amount',"`usdt_amount` DECIMAL(20,8) NOT NULL DEFAULT 0 AFTER `usdt_rate_toman`");
            addColumnIfMissing('pays','usdt_expires_at',"`usdt_expires_at` INT NOT NULL DEFAULT 0 AFTER `usdt_amount`");
            addColumnIfMissing('pays','usdt_tx_hash',"`usdt_tx_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `usdt_expires_at`");
            addColumnIfMissing('pays','auto_approved',"`auto_approved` TINYINT(1) NOT NULL DEFAULT 0 AFTER `usdt_tx_hash`");
            addColumnIfMissing('pays','auto_approved_at',"`auto_approved_at` INT NOT NULL DEFAULT 0 AFTER `auto_approved`");
            addColumnIfMissing('pays','pending_resend_at',"`pending_resend_at` INT NOT NULL DEFAULT 0 AFTER `auto_approved_at`");
            addColumnIfMissing('pays','cancelled_at',"`cancelled_at` INT NOT NULL DEFAULT 0 AFTER `pending_resend_at`");
            addColumnIfMissing('discounts','user_id',"`user_id` BIGINT NOT NULL DEFAULT 0 AFTER `hash_id`");
        }

        // Ignore every historical payment in global auto-approval until the admin
        // explicitly chooses a new baseline. Existing installations therefore
        // never approve old receipts just because this feature is enabled.
        $type='AUTO_APPROVE_BASELINE_PAY_ID';
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
        if($stmt){
            $stmt->bind_param('s',$type); $stmt->execute();
            $exists=$stmt->get_result()->num_rows>0; $stmt->close();
            if(!$exists){
                $res=$connection->query("SELECT COALESCE(MAX(`id`),0) AS m FROM `pays`");
                $maxId=$res?(int)($res->fetch_assoc()['m']??0):0;
                $val=(string)$maxId;
                $ins=$connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
                if($ins){$ins->bind_param('ss',$type,$val);$ins->execute();$ins->close();}
            }
        }
    }
}
deltaEnsureCommerceSchema();

if(!function_exists('deltaUpsertSetting')){
    function deltaUpsertSetting($type,$value){
        global $connection;
        $type=(string)$type; $value=(string)$value;
        $stmt=$connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? LIMIT 1");
        if(!$stmt) return false;
        $stmt->bind_param('s',$type);$stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
        if($exists) $stmt=$connection->prepare("UPDATE `setting` SET `value`=? WHERE `type`=?");
        else $stmt=$connection->prepare("INSERT INTO `setting` (`value`,`type`) VALUES (?,?)");
        if(!$stmt) return false;
        $stmt->bind_param('ss',$value,$type);$ok=$stmt->execute();$stmt->close();
        return $ok;
    }
}

if(!function_exists('deltaGetSetting')){
    function deltaGetSetting($type,$default=''){
        global $connection;
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
        if(!$stmt) return $default;
        $stmt->bind_param('s',$type);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        return $row ? (string)$row['value'] : $default;
    }
}

if(!function_exists('deltaGenerateTrackingCode')){
    function deltaGenerateTrackingCode(){
        global $connection;
        for($i=0;$i<40;$i++){
            try{$code=(string)random_int(10000000,99999999);}catch(Throwable $e){$code=(string)mt_rand(10000000,99999999);}
            $stmt=$connection->prepare("SELECT `id` FROM `pays` WHERE `tracking_code`=? LIMIT 1");
            if(!$stmt) return $code;
            $stmt->bind_param('s',$code);$stmt->execute();$exists=$stmt->get_result()->num_rows>0;$stmt->close();
            if(!$exists) return $code;
        }
        return substr((string)(time().mt_rand(1000,9999)),-8);
    }
}

if(!function_exists('deltaEnsureTrackingCode')){
    function deltaEnsureTrackingCode($hashOrPay){
        global $connection;
        $hash='';
        if(is_array($hashOrPay)) $hash=(string)($hashOrPay['hash_id']??'');
        else $hash=(string)$hashOrPay;
        if($hash==='') return '';
        $stmt=$connection->prepare("SELECT `tracking_code` FROM `pays` WHERE `hash_id`=? LIMIT 1");
        if(!$stmt) return '';
        $stmt->bind_param('s',$hash);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        $code=trim((string)($row['tracking_code']??''));
        if(preg_match('/^\d{8}$/',$code)) return $code;
        $code=deltaGenerateTrackingCode();
        $stmt=$connection->prepare("UPDATE `pays` SET `tracking_code`=? WHERE `hash_id`=? AND (COALESCE(`tracking_code`,'')='' OR CHAR_LENGTH(`tracking_code`)<>8)");
        if($stmt){$stmt->bind_param('ss',$code,$hash);$stmt->execute();$stmt->close();}
        return $code;
    }
}

if(!function_exists('deltaTrackingLine')){
    function deltaTrackingLine($hashOrPay,$html=false){
        $code=deltaEnsureTrackingCode($hashOrPay);
        if($code==='') return '';
        return $html ? "🔖 کد پیگیری: <code>{$code}</code>" : "🔖 کد پیگیری: {$code}";
    }
}

if(!function_exists('deltaAppendTrackingText')){
    function deltaAppendTrackingText($text,$hashOrPay,$html=false){
        $line=deltaTrackingLine($hashOrPay,$html);
        if($line==='') return $text;
        if(strpos((string)$text,'کد پیگیری')!==false || strpos((string)$text,'کد رهگیری')!==false) return $text;
        return rtrim((string)$text)."\n\n".$line;
    }
}

if(!function_exists('deltaExtractPayHashFromKeyboard')){
    function deltaExtractPayHashFromKeyboard($keyboard){
        if(is_string($keyboard)){
            $d=json_decode($keyboard,true);
        }elseif(is_array($keyboard)) $d=$keyboard;
        else return '';
        if(!is_array($d)) return '';
        $walk=function($node) use (&$walk){
            if(!is_array($node)) return '';
            if(isset($node['callback_data'])){
                $cb=(string)$node['callback_data'];
                $prefixes=['approvePayment','decPayment','approveRenewAcc','decRenewAcc','approvePgRenew','decPgRenew','accept','declineOffer'];
                foreach($prefixes as $p){
                    if(strpos($cb,$p)===0){
                        $rest=substr($cb,strlen($p));
                        if($p==='declineOffer' && preg_match('/^(.+)_\d+$/',$rest,$m)) $rest=$m[1];
                        if($rest!=='') return $rest;
                    }
                }
            }
            foreach($node as $v){$r=$walk($v);if($r!=='')return $r;}
            return '';
        };
        return $walk($d);
    }
}

if(!function_exists('deltaAppendTrackingFromKeyboard')){
    function deltaAppendTrackingFromKeyboard($caption,$keyboard,$html=true){
        $hash=deltaExtractPayHashFromKeyboard($keyboard);
        return $hash!==''?deltaAppendTrackingText($caption,$hash,$html):$caption;
    }
}

if(!function_exists('deltaResetAutoApproveBaseline')){
    function deltaResetAutoApproveBaseline(){
        global $connection;
        $r=$connection->query("SELECT COALESCE(MAX(`id`),0) AS m FROM `pays`");
        $max=$r?(int)($r->fetch_assoc()['m']??0):0;
        deltaUpsertSetting('AUTO_APPROVE_BASELINE_PAY_ID',(string)$max);
        return $max;
    }
}

if(!function_exists('deltaGetAutoApproveBaseline')){
    function deltaGetAutoApproveBaseline(){
        return max(0,(int)deltaGetSetting('AUTO_APPROVE_BASELINE_PAY_ID','0'));
    }
}

if(!function_exists('deltaIsForceAutoApproveUser')){
    function deltaIsForceAutoApproveUser($uid){
        return deltaGetSetting('USER_FORCE_AUTOAPPROVE_'.(int)$uid,'0')==='1';
    }
}

if(!function_exists('deltaSetForceAutoApproveUser')){
    function deltaSetForceAutoApproveUser($uid,$enabled){
        return deltaUpsertSetting('USER_FORCE_AUTOAPPROVE_'.(int)$uid,$enabled?'1':'0');
    }
}

if(!function_exists('deltaGetForceAutoApproveUsers')){
    function deltaGetForceAutoApproveUsers(){
        global $connection;
        $out=[];
        $q=$connection->query("SELECT `type`,`value` FROM `setting` WHERE `type` LIKE 'USER_FORCE_AUTOAPPROVE_%' AND `value`='1' ORDER BY `id` DESC");
        if($q){
            while($r=$q->fetch_assoc()){
                if(preg_match('/USER_FORCE_AUTOAPPROVE_(\d+)/',$r['type'],$m)) $out[]=(int)$m[1];
            }
        }
        return array_values(array_unique($out));
    }
}

if(!function_exists('deltaGetPaymentKeysFresh')){
    function deltaGetPaymentKeysFresh(){
        global $connection;
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`='PAYMENT_KEYS' LIMIT 1");
        if(!$stmt) return [];
        $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        $v=$row?json_decode((string)$row['value'],true):[];
        return is_array($v)?$v:[];
    }
}

if(!function_exists('deltaGetBotStatesFresh')){
    function deltaGetBotStatesFresh(){
        global $connection;
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`='BOT_STATES' LIMIT 1");
        if(!$stmt) return [];
        $stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        $v=$row?json_decode((string)$row['value'],true):[];
        return is_array($v)?$v:[];
    }
}

if(!function_exists('deltaUsdtGatewayEnabled')){
    function deltaUsdtGatewayEnabled(){
        $state=deltaGetBotStatesFresh();
        $keys=deltaGetPaymentKeysFresh();
        return (($state['usdtGatewayState']??'off')==='on' && trim((string)($keys['usdtBep20Address']??''))!=='');
    }
}

if(!function_exists('deltaFetchUsdtTomanRate')){
    function deltaFetchUsdtTomanRate(&$source=null){
        $source='Nobitex USDT/RLS';
        $url='https://apiv2.nobitex.ir/market/stats?srcCurrency=usdt&dstCurrency=rls';
        $ch=curl_init();
        curl_setopt_array($ch,[
            CURLOPT_URL=>$url,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>8,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: DeltaBot/1.0']
        ]);
        $raw=curl_exec($ch);$err=curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if(!$err && $http>=200 && $http<300){
            $j=json_decode((string)$raw,true);
            $s=$j['stats']['usdt-rls']??($j['stats']['USDT-RLS']??null);
            if(is_array($s)){
                $rial=(float)($s['latest']??($s['mark']??($s['bestSell']??0)));
                if($rial>0){
                    $toman=(int)round($rial/10);
                    deltaUpsertSetting('USDT_RATE_CACHE',json_encode(['rate'=>$toman,'time'=>time(),'source'=>$source],JSON_UNESCAPED_UNICODE));
                    return $toman;
                }
            }
        }
        $cache=json_decode(deltaGetSetting('USDT_RATE_CACHE',''),true);
        if(is_array($cache) && (int)($cache['rate']??0)>0 && time()-(int)($cache['time']??0)<=120){
            $source=(string)($cache['source']??'Nobitex cache').' (cache)';
            return (int)$cache['rate'];
        }
        return 0;
    }
}

if(!function_exists('deltaPrepareUsdtQuote')){
    function deltaPrepareUsdtQuote($hash,$uid=0,&$error=null){
        global $connection;
        $error=null;$hash=(string)$hash;
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? LIMIT 1");
        if(!$stmt){$error='فاکتور پیدا نشد';return null;}
        $stmt->bind_param('s',$hash);$stmt->execute();$pay=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$pay){$error='فاکتور پیدا نشد';return null;}
        if($uid>0 && (int)$pay['user_id']!==(int)$uid){$error='این فاکتور متعلق به شما نیست';return null;}
        if(in_array((string)$pay['state'],['approved','paid','paid_with_wallet','cancelled_by_user'],true)){$error='این فاکتور دیگر قابل پرداخت نیست';return null;}
        if(!deltaUsdtGatewayEnabled()){$error='درگاه USDT غیرفعال است';return null;}
        $rateSource='';$rate=deltaFetchUsdtTomanRate($rateSource);
        if($rate<=0){$error='دریافت نرخ لحظه‌ای تتر ناموفق بود؛ کمی بعد دوباره تلاش کنید';return null;}
        $price=max(0,(int)$pay['price']);
        $amount=$price>0?(ceil(($price/$rate)*10000)/10000):0.0;
        $expires=time()+1800;
        $method='usdt_bep20';
        $stmt=$connection->prepare("UPDATE `pays` SET `payment_method`=?,`usdt_rate_toman`=?,`usdt_amount`=?,`usdt_expires_at`=? WHERE `hash_id`=?");
        if($stmt){$stmt->bind_param('sidis',$method,$rate,$amount,$expires,$hash);$stmt->execute();$stmt->close();}
        $pay['payment_method']=$method;$pay['usdt_rate_toman']=$rate;$pay['usdt_amount']=$amount;$pay['usdt_expires_at']=$expires;
        $pay['tracking_code']=deltaEnsureTrackingCode($hash);$pay['rate_source']=$rateSource;
        return $pay;
    }
}

if(!function_exists('deltaBuildUsdtInvoiceText')){
    function deltaBuildUsdtInvoiceText($pay){
        $keys=deltaGetPaymentKeysFresh();
        $addr=trim((string)($keys['usdtBep20Address']??''));
        $price=(int)($pay['price']??0);
        $amount=number_format((float)($pay['usdt_amount']??0),4,'.','');
        $rate=(int)($pay['usdt_rate_toman']??0);
        $tracking=htmlspecialchars((string)($pay['tracking_code']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $safeAddr=htmlspecialchars($addr,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return "✅ پرداخت ارزی شما آماده‌ست♡\n\n".
            "💰 مبلغ: <b>".number_format($price)." تومان</b>\n".
            "🪙 ارز پرداخت: <b>USDT BEP20</b>\n".
            "💲 مبلغ قابل پرداخت: <b>{$amount} USDT</b>\n\n".
            "⚠️ کارمزد صرافی بر عهده شماست؛ واریزی خالص شما باید عدد بالا باشد. لطفاً مبلغ فاکتور را دقیقاً و کامل به آدرس کیف پول واریز کنید.\n\n".
            "📋 آدرس پرداخت:\n<code>{$safeAddr}</code>\n\n".
            "⚠️ بعد از واریز تا ثبت و نشستن ارز صبر کنید.\n\n".
            "📸 پس از ثبت تراکنش، از صفحه تأیید اسکرین‌شات بگیرید و هش تراکنش را در کپشن عکس ارسال کنید.\n\n".
            "🔖 کد پیگیری: <code>{$tracking}</code>\n".
            "📊 نرخ تبدیل: <b>".number_format($rate)." تومان</b>\n\n".
            "⚠️ توجه:\n".
            "💳 پرداخت فقط با USDT BEP20 روی شبکه BSC (BEP20) انجام شود.\n".
            "📌 مبلغ باید دقیقاً مطابق فاکتور واریز شود.\n".
            "❌ در صورت واریز مبلغ اشتباه یا ارسال از شبکه دیگر، پرداخت نیازمند بررسی دستی مدیریت است.\n\n".
            "⏰ این نرخ و فاکتور ارزی فقط تا ۳۰ دقیقه معتبر است.";
    }
}

if(!function_exists('deltaAppendUsdtPaymentButton')){
    function deltaAppendUsdtPaymentButton(&$keyboard,$hash){
        if(!is_array($keyboard) || !deltaUsdtGatewayEnabled()) return;
        $hash=(string)$hash;
        if($hash==='') return;
        foreach($keyboard as $row){
            foreach((array)$row as $btn){
                if(($btn['callback_data']??'')==='payWithUsdt'.$hash) return;
            }
        }
        $keyboard[]=[['text'=>'💵 پرداخت ارزی (USDT)','callback_data'=>'payWithUsdt'.$hash]];
    }
}

if(!function_exists('deltaPendingPayRows')){
    function deltaPendingPayRows($uid){
        global $connection;
        $rows=[];
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `user_id`=? AND `state`='have_sent' ORDER BY `id` DESC LIMIT 50");
        if(!$stmt) return [];
        $stmt->bind_param('i',$uid);$stmt->execute();$res=$stmt->get_result();
        while($r=$res->fetch_assoc()){if(empty($r['tracking_code']))$r['tracking_code']=deltaEnsureTrackingCode($r['hash_id']);$rows[]=$r;}
        $stmt->close();return $rows;
    }
}

if(!function_exists('deltaDisableAdminReceiptButtons')){
    function deltaDisableAdminReceiptButtons($pay,$label='لغو شده ❌'){
        $chat=(string)($pay['chat_id']??'');$mid=(int)($pay['message_id']??0);
        if($chat===''||$mid<=0||!function_exists('bot')) return;
        @bot('editMessageReplyMarkup',[
            'chat_id'=>$chat,
            'message_id'=>$mid,
            'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>$label,'callback_data'=>'deltach']]]],JSON_UNESCAPED_UNICODE)
        ]);
    }
}

if(!function_exists('deltaAutoApprovalIncomeReport')){
    function deltaAutoApprovalIncomeReport($pay,$userInfo=[]){
        $state=deltaGetBotStatesFresh();
        $channel=trim((string)($state['rewardChannel']??''));
        if($channel==='' || !function_exists('sendMessage')) return;
        $uid=(int)($pay['user_id']??0);
        $tracking=deltaEnsureTrackingCode($pay['hash_id']??'');
        $name=trim((string)($userInfo['name']??''));
        $username=trim((string)($userInfo['username']??''));
        $method=(string)($pay['payment_method']??'card_to_card');
        $type=(string)($pay['type']??'');
        $msg="🤖 <b>تأیید خودکار رسید</b>\n\n".
             "👤 کاربر: <code>{$uid}</code>\n".
             "👨‍💼 نام: <code>".htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
             "⚡️ یوزرنیم: <code>@".htmlspecialchars($username,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
             "🧾 نوع سفارش: <code>".htmlspecialchars($type,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
             "💳 روش پرداخت: <code>".htmlspecialchars($method,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>\n".
             "💰 مبلغ: <b>".number_format((int)($pay['price']??0))." تومان</b>\n".
             "🔖 کد پیگیری: <code>{$tracking}</code>\n".
             "🕒 زمان: <code>".date('Y-m-d H:i:s')."</code>";
        if((float)($pay['usdt_amount']??0)>0){
            $msg.="\n🪙 مبلغ تتر: <b>".number_format((float)$pay['usdt_amount'],4,'.','')." USDT</b>".
                  "\n📊 نرخ تتر: <b>".number_format((int)($pay['usdt_rate_toman']??0))." تومان</b>".
                  "\n🔗 هش: <code>".htmlspecialchars((string)($pay['usdt_tx_hash']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>";
        }
        @sendMessage($msg,null,'HTML',$channel);
    }
}
?>