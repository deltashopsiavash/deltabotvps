<?php
/**
 * Managed 7-day school-supplies charity campaign (v4).
 * Only confirmed card-to-card money is counted automatically.
 * Spending an existing wallet balance is never counted as a purchase contribution.
 */

if (!function_exists('charitySetting')) {
    function charitySetting($key, $default=''){
        if (function_exists('getSettingValue')) return getSettingValue($key,$default);
        global $connection;
        $stmt=$connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? ORDER BY `id` ASC LIMIT 1");
        if(!$stmt) return $default;
        $stmt->bind_param('s',$key); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        return $row ? $row['value'] : $default;
    }
}
if (!function_exists('charitySetSetting')) {
    function charitySetSetting($key,$value){
        if(function_exists('upsertSettingValue')) return upsertSettingValue($key,(string)$value);
        global $connection;
        $stmt=$connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? ORDER BY `id` ASC LIMIT 1");
        if(!$stmt) return false;
        $stmt->bind_param('s',$key); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if($row){ $id=(int)$row['id']; $stmt=$connection->prepare("UPDATE `setting` SET `value`=? WHERE `id`=?"); $value=(string)$value; $stmt->bind_param('si',$value,$id); }
        else { $stmt=$connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)"); $value=(string)$value; $stmt->bind_param('ss',$key,$value); }
        $ok=$stmt->execute(); $stmt->close(); return $ok;
    }
}
if (!function_exists('charityEnsureColumn')) {
    function charityEnsureColumn($table,$column,$definition){
        global $connection;
        $table=preg_replace('/[^a-zA-Z0-9_]/','',(string)$table); $column=preg_replace('/[^a-zA-Z0-9_]/','',(string)$column);
        if($table===''||$column==='') return;
        $res=$connection->query("SHOW COLUMNS FROM `{$table}` LIKE '".$connection->real_escape_string($column)."'");
        if($res && $res->num_rows===0) @$connection->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}
if (!function_exists('charityDefaultTemplate')) {
    function charityDefaultTemplate(){
        return "🎒 کمپین تهیه لوازم مدرسه\n\n💰 مبلغ جمع‌آوری‌شده: {TOTAL} تومان\n💳 سهم خریدهای کارت‌به‌کارت: {CARD_TOTAL} تومان\n❤️ کمک مستقیم: {DIRECT_TOTAL} تومان\n🤝 درصد اختصاص داده‌شده: {PERCENT}٪\n👥 مشارکت‌کنندگان: {PARTICIPANTS} نفر\n\n⏳ {TIME}\n{STATUS}";
    }
}
if (!function_exists('charityEnsureInfrastructure')) {
    function charityEnsureInfrastructure(){
        global $connection;
        static $done=false; if($done) return true; $done=true;
        if(!$connection || $connection->connect_error) return false;
        $connection->query("CREATE TABLE IF NOT EXISTS `charity_campaigns` (`id` INT NOT NULL AUTO_INCREMENT,`title` VARCHAR(190) NOT NULL,`description` TEXT NULL,`percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,`starts_at` INT NOT NULL,`ends_at` INT NOT NULL,`active` TINYINT(1) NOT NULL DEFAULT 1,`created_at` INT NOT NULL,PRIMARY KEY (`id`),KEY `campaign_window` (`active`,`starts_at`,`ends_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->query("CREATE TABLE IF NOT EXISTS `charity_contributions` (`id` BIGINT NOT NULL AUTO_INCREMENT,`campaign_id` INT NOT NULL,`pay_id` INT NULL DEFAULT NULL,`user_id` BIGINT NOT NULL DEFAULT 0,`source` VARCHAR(32) NOT NULL,`base_amount` BIGINT NOT NULL DEFAULT 0,`amount` BIGINT NOT NULL DEFAULT 0,`created_at` INT NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',`direct_token` VARCHAR(64) NULL DEFAULT NULL,PRIMARY KEY (`id`),UNIQUE KEY `uniq_campaign_pay` (`campaign_id`,`pay_id`),UNIQUE KEY `uniq_direct_token` (`direct_token`),KEY `campaign_status` (`campaign_id`,`status`),KEY `campaign_user` (`campaign_id`,`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $connection->query("CREATE TABLE IF NOT EXISTS `charity_proofs` (`id` INT NOT NULL AUTO_INCREMENT,`campaign_id` INT NOT NULL DEFAULT 0,`file_id` VARCHAR(255) NOT NULL,`caption` VARCHAR(1000) NULL,`active` TINYINT(1) NOT NULL DEFAULT 1,`created_at` INT NOT NULL,PRIMARY KEY (`id`),KEY `campaign_active` (`campaign_id`,`active`,`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        charityEnsureColumn('pays','payment_channel',"VARCHAR(32) NOT NULL DEFAULT ''");
        charityEnsureColumn('pays','payment_confirmed_at',"INT NOT NULL DEFAULT 0");
        if(charitySetting('CHARITY_CONTROL_VERSION','')!=='4'){
            $oldEnabled=charitySetting('CHARITY_ENABLED','0')==='1';
            charitySetSetting('CHARITY_BUTTON_VISIBLE',charitySetting('CHARITY_BUTTON_VISIBLE',$oldEnabled?'1':'0'));
            charitySetSetting('CHARITY_COLLECTING',charitySetting('CHARITY_COLLECTING',$oldEnabled?'1':'0'));
            charitySetSetting('CHARITY_PERCENT',charitySetting('CHARITY_PERCENT','10'));
            charitySetSetting('CHARITY_MAIN_BUTTON_LABEL',charitySetting('CHARITY_MAIN_BUTTON_LABEL','🎒 هزینه جمع‌آوری‌شده'));
            charitySetSetting('CHARITY_MAIN_BUTTON_ICON',charitySetting('CHARITY_MAIN_BUTTON_ICON',''));
            charitySetSetting('CHARITY_REPORT_VISIBLE',charitySetting('CHARITY_REPORT_VISIBLE','0'));
            charitySetSetting('CHARITY_REPORT_BUTTON_LABEL',charitySetting('CHARITY_REPORT_BUTTON_LABEL','📸 گزارش خرید کمک‌ها'));
            charitySetSetting('CHARITY_REPORT_BUTTON_ICON',charitySetting('CHARITY_REPORT_BUTTON_ICON',''));
            charitySetSetting('CHARITY_PAGE_TEMPLATE',charitySetting('CHARITY_PAGE_TEMPLATE',charityDefaultTemplate()));
            charitySetSetting('CHARITY_PAGE_ENTITIES',charitySetting('CHARITY_PAGE_ENTITIES','[]'));

            // v4 is strict: never infer card-to-card from state=approved/payid.
            // Rebuild transaction totals only from payments explicitly tagged by the real card-to-card flow.
            @$connection->query("DELETE FROM `charity_contributions` WHERE `source`='transaction'");
            @$connection->query("UPDATE `pays` SET `payment_channel`='legacy_unverified',`payment_confirmed_at`=0 WHERE `payment_channel`='card_to_card' AND `state`='approved' AND `payment_confirmed_at`=`request_date`");
            @$connection->query("UPDATE `pays` SET `payment_channel`='wallet' WHERE `payment_channel`='' AND `state`='paid_with_wallet'");
            @$connection->query("UPDATE `pays` SET `payment_channel`='gateway' WHERE `payment_channel`='' AND `state`='paid'");
            @$connection->query("UPDATE `pays` SET `payment_channel`='other' WHERE `payment_channel`='' AND `state`='approved'");
            charitySetSetting('CHARITY_CONTROL_VERSION','4');
        }
        return true;
    }
}

if (!function_exists('charityButtonVisible')) { function charityButtonVisible(){ return charitySetting('CHARITY_BUTTON_VISIBLE','0')==='1'; } }
if (!function_exists('charityIsCollecting')) { function charityIsCollecting(){ return charitySetting('CHARITY_COLLECTING','0')==='1'; } }
if (!function_exists('charityIsEnabled')) { function charityIsEnabled(){ return charityIsCollecting(); } }
if (!function_exists('charityReportVisible')) { function charityReportVisible(){ return charitySetting('CHARITY_REPORT_VISIBLE','0')==='1'; } }
if (!function_exists('charityConfiguredPercent')) { function charityConfiguredPercent(){ $p=(float)charitySetting('CHARITY_PERCENT','10'); return max(0,min(100,$p)); } }
if (!function_exists('charityMainButtonLabel')) { function charityMainButtonLabel(){ $v=trim((string)charitySetting('CHARITY_MAIN_BUTTON_LABEL','🎒 هزینه جمع‌آوری‌شده')); return $v!==''?$v:'🎒 هزینه جمع‌آوری‌شده'; } }
if (!function_exists('charityReportButtonLabel')) { function charityReportButtonLabel(){ $v=trim((string)charitySetting('CHARITY_REPORT_BUTTON_LABEL','📸 گزارش خرید کمک‌ها')); return $v!==''?$v:'📸 گزارش خرید کمک‌ها'; } }
if (!function_exists('charityMainButtonIcon')) { function charityMainButtonIcon(){ return trim((string)charitySetting('CHARITY_MAIN_BUTTON_ICON','')); } }
if (!function_exists('charityReportButtonIcon')) { function charityReportButtonIcon(){ return trim((string)charitySetting('CHARITY_REPORT_BUTTON_ICON','')); } }
if (!function_exists('charityPageTemplate')) { function charityPageTemplate(){ $v=(string)charitySetting('CHARITY_PAGE_TEMPLATE',charityDefaultTemplate()); return trim($v)!==''?$v:charityDefaultTemplate(); } }
if (!function_exists('charityPageEntities')) { function charityPageEntities(){ $v=json_decode((string)charitySetting('CHARITY_PAGE_ENTITIES','[]'),true); return is_array($v)?$v:[]; } }
if (!function_exists('charityPercentText')) { function charityPercentText($p){ return rtrim(rtrim(number_format((float)$p,2,'.',''),'0'),'.'); } }
if (!function_exists('charityGetCampaign')) { function charityGetCampaign(){ global $connection; $r=$connection->query("SELECT * FROM `charity_campaigns` ORDER BY `id` DESC LIMIT 1"); return $r?$r->fetch_assoc():null; } }
if (!function_exists('charityCampaignIsOpen')) { function charityCampaignIsOpen($c){ if(!is_array($c)) return false; $n=time(); return (int)($c['active']??0)===1 && $n>=(int)$c['starts_at'] && $n<(int)$c['ends_at']; } }
if (!function_exists('charityRefreshCampaignState')) {
    function charityRefreshCampaignState($c=null){ global $connection; if(!is_array($c)) $c=charityGetCampaign(); if(!$c) return null; if((int)$c['active']===1 && time()>=(int)$c['ends_at']){ $id=(int)$c['id']; $stmt=$connection->prepare("UPDATE `charity_campaigns` SET `active`=0 WHERE `id`=?"); if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();} $c['active']=0; charitySetSetting('CHARITY_COLLECTING','0'); charitySetSetting('CHARITY_ENABLED','0'); } return $c; }
}
if (!function_exists('charityRemainingText')) {
    function charityRemainingText($s){ $s=max(0,(int)$s); $d=intdiv($s,86400);$s%=86400;$h=intdiv($s,3600);$s%=3600;$m=intdiv($s,60);$s%=60; return "$d روز و $h ساعت و $m دقیقه و $s ثانیه"; }
}
if (!function_exists('charityStartNewCampaign')) {
    function charityStartNewCampaign(){ global $connection; $now=time(); $end=$now+604800; $p=charityConfiguredPercent(); @$connection->query("UPDATE `charity_campaigns` SET `active`=0,`ends_at`=LEAST(`ends_at`,{$now}) WHERE `active`=1"); $title='کمپین مهر مهربانی';$desc='تهیه لوازم مدرسه برای دانش‌آموزان';$stmt=$connection->prepare("INSERT INTO `charity_campaigns` (`title`,`description`,`percent`,`starts_at`,`ends_at`,`active`,`created_at`) VALUES (?,?,?,?,?,1,?)"); if(!$stmt)return false; $stmt->bind_param('ssdiii',$title,$desc,$p,$now,$end,$now);$ok=$stmt->execute();$stmt->close(); if($ok){charitySetSetting('CHARITY_COLLECTING','1');charitySetSetting('CHARITY_ENABLED','1');charitySetSetting('CHARITY_BUTTON_VISIBLE','1');} return $ok; }
}
if (!function_exists('charityStopCampaign')) {
    function charityStopCampaign(){ global $connection; $c=charityGetCampaign(); if($c&&charityCampaignIsOpen($c)) charitySyncCompletedPayments($c); $now=time(); @$connection->query("UPDATE `charity_campaigns` SET `active`=0,`ends_at`=LEAST(`ends_at`,{$now}) WHERE `active`=1"); charitySetSetting('CHARITY_COLLECTING','0');charitySetSetting('CHARITY_ENABLED','0'); }
}

if (!function_exists('charityMarkCardToCardPayment')) {
    function charityMarkCardToCardPayment($hash){ global $connection; $hash=trim((string)$hash); if($hash==='')return; $stmt=$connection->prepare("UPDATE `pays` SET `payment_channel`='card_to_card' WHERE `hash_id`=? AND `state` NOT IN ('paid_with_wallet','paid')"); if($stmt){$stmt->bind_param('s',$hash);$stmt->execute();$stmt->close();} }
}
if (!function_exists('charityDetectCardToCardIntent')) {
    function charityDetectCardToCardIntent(){ global $data,$userInfo; $d=(string)($data??''); $step=is_array($userInfo??null)?(string)($userInfo['step']??''):''; $prefixes=['increaseWalletWithCartToCart','payCustomWithCartToCart','payWithCartToCart','payRenewWithCartToCart','payIncreaseDayWithCartToCart','payIncreaseWithCartToCart','pgRenewPayCart']; foreach($prefixes as $p){ if(strpos($d,$p)===0){charityMarkCardToCardPayment(substr($d,strlen($p)));return;} if(strpos($step,$p)===0){charityMarkCardToCardPayment(substr($step,strlen($p)));return;} } if(preg_match('/^payTextReceipt\\|([^|]+)/',$step,$m)) charityMarkCardToCardPayment($m[1]); }
}
if (!function_exists('charityFinalizeCardToCardConfirmations')) {
    function charityFinalizeCardToCardConfirmations(){ global $connection; if(!$connection||$connection->connect_error)return; $now=time(); @$connection->query("UPDATE `pays` SET `payment_confirmed_at`={$now} WHERE `payment_channel`='card_to_card' AND `state`='approved' AND `payment_confirmed_at`=0"); $c=charityRefreshCampaignState(charityGetCampaign()); if($c&&charityIsCollecting()&&charityCampaignIsOpen($c)) charitySyncCompletedPayments($c); }
}
if (!function_exists('charitySyncCompletedPayments')) {
    function charitySyncCompletedPayments($c=null){ global $connection; if(!is_array($c))$c=charityGetCampaign(); if(!is_array($c))return; $cid=(int)$c['id'];$start=(int)$c['starts_at'];$end=min((int)$c['ends_at'],time());$p=(float)$c['percent']; if($cid<=0||$end<$start)return; $sql="INSERT IGNORE INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) SELECT ?,p.`id`,p.`user_id`,'transaction',p.`price`,ROUND(p.`price`*?/100),p.`payment_confirmed_at`,'confirmed',NULL FROM `pays` p WHERE p.`price`>0 AND p.`payment_channel`='card_to_card' AND p.`state`='approved' AND p.`type`<>'CHARITY_DIRECT' AND p.`payment_confirmed_at`>=? AND p.`payment_confirmed_at`<=?"; $stmt=$connection->prepare($sql); if(!$stmt)return; $stmt->bind_param('idii',$cid,$p,$start,$end);$stmt->execute();$stmt->close(); }
}
if (!function_exists('charityChangePercent')) {
    function charityChangePercent($p){ global $connection; $p=(float)$p;if($p<0||$p>100)return false;$c=charityRefreshCampaignState(charityGetCampaign());if($c&&charityCampaignIsOpen($c)){charitySyncCompletedPayments($c);$id=(int)$c['id'];$stmt=$connection->prepare("UPDATE `charity_campaigns` SET `percent`=? WHERE `id`=?");if($stmt){$stmt->bind_param('di',$p,$id);$stmt->execute();$stmt->close();}}charitySetSetting('CHARITY_PERCENT',charityPercentText($p));return true; }
}
if (!function_exists('charityTotals')) {
    function charityTotals($c){ global $connection;if(!$c)return ['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0,'transaction_count'=>0,'direct_count'=>0];charitySyncCompletedPayments($c);$cid=(int)$c['id'];$out=['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0,'transaction_count'=>0,'direct_count'=>0];$stmt=$connection->prepare("SELECT COALESCE(SUM(`amount`),0) total,COALESCE(SUM(CASE WHEN `source`='transaction' THEN `amount` ELSE 0 END),0) transactions,COALESCE(SUM(CASE WHEN `source`='direct' THEN `amount` ELSE 0 END),0) direct,COUNT(DISTINCT CASE WHEN `user_id`>0 THEN `user_id` END) participants,SUM(CASE WHEN `source`='transaction' THEN 1 ELSE 0 END) transaction_count,SUM(CASE WHEN `source`='direct' THEN 1 ELSE 0 END) direct_count FROM `charity_contributions` WHERE `campaign_id`=? AND `status`='confirmed'");if(!$stmt)return $out;$stmt->bind_param('i',$cid);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if($r)foreach($out as $k=>$v)$out[$k]=(int)($r[$k]??0);return $out; }
}

if (!function_exists('charityBuildMainMenuRows')) {
    function charityBuildMainMenuRows(){ global $isChildBot; $rows=[];if(!empty($isChildBot))return $rows;if(charityButtonVisible()){ $b=['text'=>charityMainButtonLabel(),'callback_data'=>'charityCampaign'];$i=charityMainButtonIcon();if($i!=='')$b['icon_custom_emoji_id']=$i;$rows[]=[$b]; } if(charityReportVisible()){ $b=['text'=>charityReportButtonLabel(),'callback_data'=>'charityProofs'];$i=charityReportButtonIcon();if($i!=='')$b['icon_custom_emoji_id']=$i;$rows[]=[$b]; }return $rows; }
}
if (!function_exists('charityCampaignStatusText')) {
    function charityCampaignStatusText($c){ if(!$c)return 'هنوز دوره‌ای شروع نشده است.';if(charityCampaignIsOpen($c)&&charityIsCollecting())return '🟢 جمع‌آوری فعال است.';if(time()>=(int)$c['ends_at'])return '✅ مهلت جمع‌آوری این دوره تمام شده است.';return '⏸ جمع‌آوری متوقف شده است.'; }
}
if (!function_exists('charityGregorianToJalali')) {
    function charityGregorianToJalali($gy,$gm,$gd){
        $gdm=[0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2=$gm>2?$gy+1:$gy;
        $days=355666+(365*$gy)+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$gdm[$gm-1];
        $jy=-1595+33*intdiv($days,12053);$days%=12053;
        $jy+=4*intdiv($days,1461);$days%=1461;
        if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;}
        if($days<186){$jm=1+intdiv($days,31);$jd=1+($days%31);}else{$jm=7+intdiv($days-186,30);$jd=1+(($days-186)%30);}
        return [$jy,$jm,$jd];
    }
}
if (!function_exists('charityPersianDigits')) {
    function charityPersianDigits($v){return strtr((string)$v,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);}
}
if (!function_exists('charityIranDate')) {
    function charityIranDate($timestamp){
        $timestamp=(int)$timestamp;if($timestamp<=0)return '-';
        try{$dt=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Tehran'));}catch(Throwable $e){return '-';}
        [$jy,$jm,$jd]=charityGregorianToJalali((int)$dt->format('Y'),(int)$dt->format('n'),(int)$dt->format('j'));
        return charityPersianDigits(sprintf('%04d/%02d/%02d - %02d:%02d',$jy,$jm,$jd,(int)$dt->format('H'),(int)$dt->format('i')));
    }
}
if (!function_exists('charityTemplateMap')) {
    function charityTemplateMap($c,$t){
        $pct=$c?charityPercentText($c['percent']):charityPercentText(charityConfiguredPercent());
        $time=($c&&charityCampaignIsOpen($c))?charityRemainingText((int)$c['ends_at']-time()):'۰ روز و ۰ ساعت و ۰ دقیقه و ۰ ثانیه';
        return ['{TOTAL}'=>number_format($t['total']??0),'{CARD_TOTAL}'=>number_format($t['transactions']??0),'{CARDTOTAL}'=>number_format($t['transactions']??0),'{DIRECT_TOTAL}'=>number_format($t['direct']??0),'{DIRECTTOTAL}'=>number_format($t['direct']??0),'{PERCENT}'=>$pct,'{PARTICIPANTS}'=>number_format($t['participants']??0),'{TIME}'=>$time,'{STATUS}'=>charityCampaignStatusText($c),'{START_TIME}'=>$c?charityIranDate((int)$c['starts_at']):'-','{STARTTIME}'=>$c?charityIranDate((int)$c['starts_at']):'-','{END_TIME}'=>$c?charityIranDate((int)$c['ends_at']):'-','{ENDTIME}'=>$c?charityIranDate((int)$c['ends_at']):'-'];
    }
}
if (!function_exists('charityRenderTemplate')) {
    function charityRenderTemplate($c,$t){ return strtr(charityPageTemplate(),charityTemplateMap($c,$t)); }
}
if (!function_exists('charityBuildRenderedCustomEmojiEntities')) {
    function charityBuildRenderedCustomEmojiEntities($template,$map,$saved){
        $out=[];if(!is_array($saved))return $out;
        foreach($saved as $e){
            if((string)charityEntityValue($e,'type','')!=='custom_emoji')continue;
            $id=(string)charityEntityValue($e,'custom_emoji_id','');$off=(int)charityEntityValue($e,'offset',0);$len=(int)charityEntityValue($e,'length',0);
            if($id===''||$len<=0)continue;
            $prefix=charityUtf16Prefix($template,$off);
            $newOffset=charityUtf16Length(strtr($prefix,$map));
            $out[]=['type'=>'custom_emoji','offset'=>$newOffset,'length'=>$len,'custom_emoji_id'=>$id];
        }
        return $out;
    }
}
if (!function_exists('charitySendOrEditWithEntities')) {
    function charitySendOrEditWithEntities($text,$markup,$entities=[]){
        global $chat_id,$message_id,$update,$data;
        if(isset($update->callback_query)&&!empty($update->callback_query->id))bot('answerCallbackQuery',['callback_query_id'=>$update->callback_query->id]);
        $payload=['chat_id'=>$chat_id,'text'=>$text,'reply_markup'=>is_string($markup)?$markup:json_encode($markup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if($entities)$payload['entities']=json_encode($entities,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($data)&&!empty($message_id)){$p=$payload;$p['message_id']=$message_id;$r=bot('editMessageText',$p);$ok=is_object($r)?!empty($r->ok):(is_array($r)?!empty($r['ok']):$r===true);if($ok)return $r;}
        return bot('sendMessage',$payload);
    }
}
if (!function_exists('charityRenderCampaign')) {
    function charityRenderCampaign(){
        global $buttonValues;
        $c=charityRefreshCampaignState(charityGetCampaign());$tot=$c?charityTotals($c):['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0];
        $template=charityPageTemplate();$map=charityTemplateMap($c,$tot);$text=strtr($template,$map);$entities=charityBuildRenderedCustomEmojiEntities($template,$map,charityPageEntities());
        $rows=[];if($c&&charityCampaignIsOpen($c)&&charityIsCollecting())$rows[]=[['text'=>'❤️ کمک مستقیم به کمپین','callback_data'=>'charityDonate']];
        if(charityReportVisible()){$b=['text'=>charityReportButtonLabel(),'callback_data'=>'charityProofs'];$i=charityReportButtonIcon();if($i!=='')$b['icon_custom_emoji_id']=$i;$rows[]=[$b];}
        $rows[]=[['text'=>'🔄 بروزرسانی','callback_data'=>'charityCampaign']];$rows[]=[['text'=>$buttonValues['back_to_main']??'🏠 صفحه اصلی','callback_data'=>'mainMenu']];
        charitySendOrEditWithEntities($text,['inline_keyboard'=>$rows],$entities);
    }
}

if (!function_exists('charityNormalizeAmountText')) { function charityNormalizeAmountText($v){$v=strtr((string)$v,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);$v=str_replace([',','٬','،',' ','تومان','تومن','%','٪'],'',$v);return preg_match('/^\d+(?:\.\d+)?$/',$v)?$v:'';} }
if (!function_exists('charityRenderDonateMenu')) {
    function charityRenderDonateMenu(){
        global $message_id;
        $c=charityRefreshCampaignState(charityGetCampaign());
        if(!$c||!charityIsCollecting()||!charityCampaignIsOpen($c)){charityRenderCampaign();return;}
        $txt="❤️ <b>کمک مستقیم به کمپین</b>\n\n💳 کمک مستقیم فقط از طریق <b>کارت‌به‌کارت</b> انجام می‌شود و هیچ مبلغی از کیف پول ربات شما کم نخواهد شد.\n\nمبلغ موردنظر را انتخاب کنید:";
        $rows=[
            [['text'=>'۵۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_50000'],['text'=>'۱۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_100000']],
            [['text'=>'۲۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_200000'],['text'=>'۵۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_500000']],
            [['text'=>'✍️ مبلغ دلخواه','callback_data'=>'charityDonateCustom']],
            [['text'=>'↩️ بازگشت','callback_data'=>'charityCampaign']]
        ];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}
if (!function_exists('charityDirectPaymentByHash')) {
    function charityDirectPaymentByHash($hash){
        global $connection;
        $hash=trim((string)$hash);if($hash==='')return null;
        $stmt=$connection->prepare("SELECT * FROM `pays` WHERE `hash_id`=? AND `type`='CHARITY_DIRECT' LIMIT 1");
        if(!$stmt)return null;$stmt->bind_param('s',$hash);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return $r?:null;
    }
}
if (!function_exists('charityCreateDirectCardPayment')) {
    function charityCreateDirectCardPayment($amount){
        global $connection,$from_id,$message_id,$paymentKeys;
        $amount=(int)$amount;$c=charityRefreshCampaignState(charityGetCampaign());
        if(!$c||!charityIsCollecting()||!charityCampaignIsOpen($c)){charityRenderCampaign();return;}
        if($amount<1000){alert('مبلغ معتبر نیست.',true);return;}
        try{$hash=bin2hex(random_bytes(12));}catch(Throwable $e){$hash=substr(sha1(uniqid('',true)),0,24);}
        $meta=json_encode(['campaign_id'=>(int)$c['id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $now=time();
        $stmt=$connection->prepare("INSERT INTO `pays` (`hash_id`,`description`,`user_id`,`type`,`plan_id`,`volume`,`day`,`price`,`request_date`,`state`,`payment_channel`,`payment_confirmed_at`) VALUES (?, ?, ?, 'CHARITY_DIRECT', 0, 0, 0, ?, ?, 'pending', 'card_to_card', 0)");
        if(!$stmt){alert('خطا در ساخت پرداخت.',true);return;}
        $stmt->bind_param('ssiii',$hash,$meta,$from_id,$amount,$now);$ok=$stmt->execute();$stmt->close();
        if(!$ok){alert('خطا در ساخت پرداخت.',true);return;}
        setUser('charityDirectReceipt|'.$hash,'step');setUser('','temp');
        $bank=trim((string)($paymentKeys['bankAccount']??''));$holder=trim((string)($paymentKeys['holderName']??''));
        $bankHtml=htmlspecialchars($bank,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$holderHtml=htmlspecialchars($holder,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $txt="❤️ <b>کمک مستقیم به کمپین</b>\n\n💰 مبلغ کمک: <b>".number_format($amount)." تومان</b>\n\n💳 شماره کارت: <code>{$bankHtml}</code>\n👤 به نام: <b>{$holderHtml}</b>\n\n📸 پس از واریز، عکس رسید را همینجا ارسال کنید.\n\n✅ این پرداخت هیچ مبلغی از کیف پول ربات شما کم نمی‌کند.";
        $rows=[];$copy=[];$copy[]=['text'=>'📋 کپی مبلغ','copy_text'=>['text'=>(string)($amount*10)]];
        if($bank!=='')$copy[]=['text'=>'💳 کپی شماره کارت','copy_text'=>['text'=>$bank]];
        if($copy)$rows[]=$copy;
        $rows[]=[['text'=>'📷 ارسال رسید واریزی','callback_data'=>'charityDirectReceiptPrompt_'.$hash]];
        $rows[]=[['text'=>'❌ انصراف','callback_data'=>'charityDirectCancel_'.$hash]];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}
if (!function_exists('charityPromptDirectReceipt')) {
    function charityPromptDirectReceipt($hash){$pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}setUser('charityDirectReceipt|'.$hash,'step');sendMessage('📸 لطفاً فقط عکس رسید کارت‌به‌کارت را ارسال کنید.');}
}
if (!function_exists('charityHandleDirectReceipt')) {
    function charityHandleDirectReceipt($hash){
        global $connection,$from_id,$admin,$update,$userInfo,$first_name,$username;
        $pay=charityDirectPaymentByHash($hash);if(!$pay||(int)$pay['user_id']!==(int)$from_id){sendMessage('❌ پرداخت پیدا نشد.');setUser('none','step');return;}
        if(!in_array((string)$pay['state'],['pending','have_sent'],true)){sendMessage('این پرداخت قبلاً بررسی شده است.');setUser('none','step');return;}
        if(!isset($update->message->photo)){sendMessage('❌ لطفاً فقط عکس رسید کارت‌به‌کارت را ارسال کنید.');return;}
        $photos=$update->message->photo;$ph=end($photos);$fileId=(string)($ph->file_id??'');if($fileId===''){sendMessage('❌ عکس رسید دریافت نشد.');return;}
        $uid=(int)$pay['user_id'];$amount=(int)$pay['price'];$name=trim((string)($userInfo['name']??$first_name??''));$uname=trim((string)($userInfo['username']??$username??''));
        $nameHtml=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$userHtml=$uname!==''?'@'.htmlspecialchars(ltrim($uname,'@'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):'-';
        $caption="❤️ <b>رسید کمک مستقیم کمپین</b>\n\n👤 کاربر: {$nameHtml}\n🆔 آیدی: <code>{$uid}</code>\n🔗 یوزرنیم: {$userHtml}\n💰 مبلغ: <b>".number_format($amount)." تومان</b>\n🧾 شناسه: <code>".htmlspecialchars($hash,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</code>";
        $kb=json_encode(['inline_keyboard'=>[[['text'=>'✅ تأیید کمک','callback_data'=>'charityDirectApprove_'.$hash],['text'=>'❌ رد رسید','callback_data'=>'charityDirectDecline_'.$hash]]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res=bot('sendPhoto',['chat_id'=>$admin,'photo'=>$fileId,'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
        $msgId=0;if(is_object($res)&&isset($res->result->message_id))$msgId=(int)$res->result->message_id;elseif(is_array($res)&&isset($res['result']['message_id']))$msgId=(int)$res['result']['message_id'];
        $stmt=$connection->prepare("UPDATE `pays` SET `state`='have_sent',`message_id`=?,`chat_id`=? WHERE `id`=? AND `state` IN ('pending','have_sent')");
        if($stmt){$pid=(int)$pay['id'];$adm=(int)$admin;$stmt->bind_param('iii',$msgId,$adm,$pid);$stmt->execute();$stmt->close();}
        setUser('none','step');sendMessage('✅ رسید شما برای مدیر ارسال شد. پس از تأیید، مبلغ به کمک مستقیم کمپین اضافه می‌شود.');
    }
}
if (!function_exists('charityApproveDirectPayment')) {
    function charityApproveDirectPayment($hash){
        global $connection,$chat_id,$message_id;
        $pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}
        if((string)$pay['state']==='approved'){alert('این کمک قبلاً تأیید شده است.');return;}
        if((string)$pay['state']!=='have_sent'){alert('این پرداخت قابل تأیید نیست.',true);return;}
        $meta=json_decode((string)($pay['description']??''),true);$cid=(int)($meta['campaign_id']??0);if($cid<=0){alert('کمپین مربوطه پیدا نشد.',true);return;}
        $pid=(int)$pay['id'];$uid=(int)$pay['user_id'];$amount=(int)$pay['price'];$now=time();$token='card:'.$hash;
        $connection->begin_transaction();
        try{
            $stmt=$connection->prepare("UPDATE `pays` SET `state`='approved',`payment_channel`='card_to_card',`payment_confirmed_at`=? WHERE `id`=? AND `state`='have_sent'");
            if(!$stmt)throw new Exception('prepare failed');$stmt->bind_param('ii',$now,$pid);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();if($changed!==1)throw new Exception('already processed');
            $stmt=$connection->prepare("INSERT INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) VALUES (?,?,?,'direct',?,?,?,'confirmed',?)");
            if(!$stmt)throw new Exception('prepare failed');$stmt->bind_param('iiiiiis',$cid,$pid,$uid,$amount,$amount,$now,$token);$stmt->execute();$stmt->close();
            $connection->commit();
        }catch(Throwable $e){$connection->rollback();alert('خطا در ثبت کمک یا این پرداخت قبلاً بررسی شده است.',true);return;}
        bot('sendMessage',['chat_id'=>$uid,'text'=>'❤️ کمک مستقیم شما به مبلغ '.number_format($amount).' تومان تأیید و به کمپین اضافه شد. سپاس از همراهی شما.']);
        $done=json_encode(['inline_keyboard'=>[[['text'=>'✅ کمک تأیید شد','callback_data'=>'noop']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($chat_id)&&!empty($message_id))bot('editMessageReplyMarkup',['chat_id'=>$chat_id,'message_id'=>$message_id,'reply_markup'=>$done]);
        alert('کمک مستقیم تأیید و ثبت شد.');
    }
}
if (!function_exists('charityDeclineDirectPayment')) {
    function charityDeclineDirectPayment($hash){
        global $connection,$chat_id,$message_id;
        $pay=charityDirectPaymentByHash($hash);if(!$pay){alert('پرداخت پیدا نشد.',true);return;}
        if(!in_array((string)$pay['state'],['pending','have_sent'],true)){alert('این پرداخت قبلاً بررسی شده است.');return;}
        $pid=(int)$pay['id'];$uid=(int)$pay['user_id'];$stmt=$connection->prepare("UPDATE `pays` SET `state`='rejected' WHERE `id`=? AND `state` IN ('pending','have_sent')");if($stmt){$stmt->bind_param('i',$pid);$stmt->execute();$stmt->close();}
        bot('sendMessage',['chat_id'=>$uid,'text'=>'❌ رسید کمک مستقیم شما تأیید نشد. در صورت نیاز با پشتیبانی در ارتباط باشید.']);
        $done=json_encode(['inline_keyboard'=>[[['text'=>'❌ رسید رد شد','callback_data'=>'noop']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($chat_id)&&!empty($message_id))bot('editMessageReplyMarkup',['chat_id'=>$chat_id,'message_id'=>$message_id,'reply_markup'=>$done]);
        alert('رسید رد شد.');
    }
}
if (!function_exists('charityCancelDirectPayment')) {
    function charityCancelDirectPayment($hash){global $connection;$pay=charityDirectPaymentByHash($hash);if($pay&&(string)$pay['state']==='pending'){$pid=(int)$pay['id'];$stmt=$connection->prepare("UPDATE `pays` SET `state`='cancelled' WHERE `id`=? AND `state`='pending'");if($stmt){$stmt->bind_param('i',$pid);$stmt->execute();$stmt->close();}}setUser('none','step');charityRenderCampaign();}
}


if (!function_exists('charityProofRows')) { function charityProofRows($cid=0,$limit=30){global $connection;$cid=(int)$cid;$limit=max(1,min(50,(int)$limit));if($cid<=0){$c=charityGetCampaign();$cid=(int)($c['id']??0);}if($cid<=0)return[];$stmt=$connection->prepare("SELECT * FROM `charity_proofs` WHERE `campaign_id`=? AND `active`=1 ORDER BY `id` DESC LIMIT {$limit}");if(!$stmt)return[];$stmt->bind_param('i',$cid);$stmt->execute();$res=$stmt->get_result();$rows=[];while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();return$rows;} }
if (!function_exists('charityRenderProofList')) {
    function charityRenderProofList(){global $message_id,$buttonValues;$c=charityGetCampaign();$rows=charityProofRows((int)($c['id']??0));$txt="📸 <b>گزارش خرید و تحویل کمک‌ها</b>\n\nتصاویر واقعی خرید/تحویل کمک‌ها در این بخش قرار می‌گیرد.";$kb=[];if(!$rows)$txt.="\n\nهنوز تصویری ثبت نشده است.";else{$i=1;foreach($rows as$r){$cap=trim((string)($r['caption']??''));$label='🖼 تصویر '.$i.($cap!==''?' - '.mb_substr($cap,0,28,'UTF-8'):'');$kb[]=[['text'=>$label,'callback_data'=>'charityProofView_'.(int)$r['id']]];$i++;}}if(charityButtonVisible())$kb[]=[['text'=>'↩️ بازگشت به کمپین','callback_data'=>'charityCampaign']];$kb[]=[['text'=>$buttonValues['back_to_main']??'🏠 صفحه اصلی','callback_data'=>'mainMenu']];smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$kb],JSON_UNESCAPED_UNICODE),'HTML');}
}
if (!function_exists('charitySendProof')) {
    function charitySendProof($id,$adminMode=false){global $connection,$from_id;$id=(int)$id;$stmt=$connection->prepare("SELECT * FROM `charity_proofs` WHERE `id`=? LIMIT 1");if(!$stmt)return;$stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$r||(!$adminMode&&(int)$r['active']!==1)){alert('تصویر پیدا نشد.',true);return;}$cap=trim((string)($r['caption']??''));if($cap==='')$cap='📸 گزارش خرید کمک‌ها';$keys=$adminMode?['inline_keyboard'=>[[['text'=>'🗑 حذف تصویر','callback_data'=>'charityAdminDeleteProof_'.$id]],[['text'=>'↩️ مدیریت تصاویر','callback_data'=>'charityAdminProofs']]]]:['inline_keyboard'=>[[['text'=>'↩️ بازگشت','callback_data'=>'charityProofs']]]];sendPhoto($r['file_id'],$cap,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML',$from_id);}
}
if (!function_exists('charityAdminIsAllowed')) { function charityAdminIsAllowed(){global $from_id,$admin,$userInfo,$isChildBot;return empty($isChildBot)&&((int)$from_id===(int)$admin||(($userInfo['isAdmin']??false)==true));} }
if (!function_exists('charityEntityValue')) {
    function charityEntityValue($entity,$key,$default=null){
        if(is_array($entity)) return array_key_exists($key,$entity)?$entity[$key]:$default;
        if(is_object($entity)) return isset($entity->$key)?$entity->$key:$default;
        return $default;
    }
}
if (!function_exists('charityUtf16Bytes')) {
    function charityUtf16Bytes($text){
        $text=(string)$text;
        if(function_exists('mb_convert_encoding')) return mb_convert_encoding($text,'UTF-16LE','UTF-8');
        if(function_exists('iconv')) { $v=@iconv('UTF-8','UTF-16LE//IGNORE',$text); return $v===false?'':$v; }
        return '';
    }
}
if (!function_exists('charityFromUtf16Bytes')) {
    function charityFromUtf16Bytes($bytes){
        if(function_exists('mb_convert_encoding')) return mb_convert_encoding($bytes,'UTF-8','UTF-16LE');
        if(function_exists('iconv')) { $v=@iconv('UTF-16LE','UTF-8//IGNORE',$bytes); return $v===false?'':$v; }
        return '';
    }
}
if (!function_exists('charityUtf16Length')) {
    function charityUtf16Length($text){ return intdiv(strlen(charityUtf16Bytes($text)),2); }
}
if (!function_exists('charityUtf16Prefix')) {
    function charityUtf16Prefix($text,$units){ $b=charityUtf16Bytes($text); return charityFromUtf16Bytes(substr($b,0,max(0,(int)$units)*2)); }
}
if (!function_exists('charityUtf16Remove')) {
    function charityUtf16Remove($text,$offset,$length){
        $b=charityUtf16Bytes($text);$a=max(0,(int)$offset)*2;$z=max(0,(int)$length)*2;
        return charityFromUtf16Bytes(substr($b,0,$a).substr($b,$a+$z));
    }
}
if (!function_exists('charityExtractPremiumTextEntities')) {
    function charityExtractPremiumTextEntities($entities){
        $out=[];if(!is_array($entities)&&!($entities instanceof Traversable))return $out;
        foreach($entities as $e){
            if((string)charityEntityValue($e,'type','')!=='custom_emoji')continue;
            $id=(string)charityEntityValue($e,'custom_emoji_id','');if($id==='')continue;
            $out[]=['type'=>'custom_emoji','offset'=>(int)charityEntityValue($e,'offset',0),'length'=>(int)charityEntityValue($e,'length',0),'custom_emoji_id'=>$id];
        }
        return $out;
    }
}
if (!function_exists('charityExtractButtonPremium')) {
    function charityExtractButtonPremium($text,$entities){
        $text=(string)$text;$premium=charityExtractPremiumTextEntities($entities);
        if(!$premium)return ['text'=>$text,'custom_emoji_id'=>''];
        $e=$premium[0];$clean=charityUtf16Remove($text,$e['offset'],$e['length']);
        return ['text'=>trim($clean),'custom_emoji_id'=>(string)$e['custom_emoji_id']];
    }
}
if (!function_exists('charityAddManualAdjustment')) {
    function charityAddManualAdjustment($delta){
        global $connection;$delta=(int)$delta;if($delta===0)return 0;$c=charityGetCampaign();if(!$c)return 0;$cid=(int)$c['id'];$now=time();$base=abs($delta);
        $stmt=$connection->prepare("INSERT INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) VALUES (?,NULL,0,'manual',?,?,?,'confirmed',NULL)");
        if(!$stmt)return 0;$stmt->bind_param('iiii',$cid,$base,$delta,$now);$ok=$stmt->execute();$stmt->close();return $ok?$delta:0;
    }
}
if (!function_exists('charityAdminRenderAdjustMenu')) {
    function charityAdminRenderAdjustMenu(){
        global $message_id;$c=charityGetCampaign();$tot=$c?charityTotals($c):['total'=>0];$total=max(0,(int)($tot['total']??0));
        $txt="💰 <b>اصلاح مبلغ کمپین</b>\n\nمبلغ فعلی: <b>".number_format($total)." تومان</b>\n\nاز این بخش می‌توانید مبلغ نمایش‌داده‌شده را افزایش دهید، کاهش دهید یا صفر کنید. سوابق پرداخت‌های واقعی حذف نمی‌شوند.";
        $rows=[[['text'=>'➕ افزایش مبلغ','callback_data'=>'charityAdminAdjustAdd'],['text'=>'➖ کاهش مبلغ','callback_data'=>'charityAdminAdjustSubtract']],[['text'=>'🧹 صفر کردن مبلغ','callback_data'=>'charityAdminAdjustZero']],[['text'=>'↩️ تنظیمات کمپین','callback_data'=>'charityAdminSettings']]];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'HTML');
    }
}

if (!function_exists('charityAdminSettingsKeyboard')) {
    function charityAdminSettingsKeyboard(){ $collect=charityIsCollecting();$visible=charityButtonVisible();$report=charityReportVisible();$rows=[[['text'=>$collect?'⏹ توقف جمع‌آوری':'▶️ شروع دوره جدید ۷ روزه','callback_data'=>$collect?'charityAdminStop':'charityAdminStart']],[['text'=>$visible?'👁 دکمه کمپین: نمایان':'🙈 دکمه کمپین: مخفی','callback_data'=>'charityAdminToggleButton']],[['text'=>'📊 درصد سهم: '.charityPercentText(charityConfiguredPercent()).'٪','callback_data'=>'charityAdminSetPercent']],[['text'=>'💰 اصلاح مبلغ جمع‌آوری‌شده','callback_data'=>'charityAdminAdjustMenu']],[['text'=>'📝 ویرایش کامل متن صفحه کمپین','callback_data'=>'charityAdminSetTemplate']],[['text'=>'🔣 راهنمای متغیرهای متن','callback_data'=>'charityAdminTemplateHelp']],[['text'=>'✏️ نام/ایموجی پرمیوم دکمه کمپین','callback_data'=>'charityAdminSetMainLabel']],[['text'=>$report?'🟢 دکمه گزارش: روشن':'🔴 دکمه گزارش: خاموش','callback_data'=>'charityAdminToggleReport']],[['text'=>'✏️ نام/ایموجی پرمیوم دکمه گزارش','callback_data'=>'charityAdminSetReportLabel']],[['text'=>'📸 مدیریت تصاویر خرید','callback_data'=>'charityAdminProofs']],[['text'=>'↩️ بازگشت به تنظیمات ربات','callback_data'=>'botSettings']]];return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
if (!function_exists('charityAdminRenderSettings')) {
    function charityAdminRenderSettings(){global $message_id;$c=charityRefreshCampaignState(charityGetCampaign());$tot=$c?charityTotals($c):['total'=>0];$txt="🎒 <b>تنظیمات کمپین خیریه</b>\n\nجمع‌آوری: <b>".(charityIsCollecting()?'فعال':'متوقف')."</b>\nنمایش دکمه: <b>".(charityButtonVisible()?'روشن':'خاموش')."</b>\nدرصد: <b>".charityPercentText(charityConfiguredPercent())."٪</b>\nجمع دوره آخر: <b>".number_format($tot['total']??0)." تومان</b>\n\n✅ خریدها فقط در صورت تأیید کارت‌به‌کارت محاسبه می‌شوند. خرید از موجودی قبلی کیف پول محاسبه نمی‌شود.\n❤️ کمک مستقیم نیز فقط کارت‌به‌کارت است و پس از تأیید رسید، ۱۰۰٪ مبلغ به کمپین اضافه می‌شود؛ از کیف پول کاربر چیزی کم نمی‌شود.\n⏹ توقف جمع‌آوری دکمه را حذف نمی‌کند و مبلغ نهایی همچنان نمایش داده می‌شود.";smartSendOrEdit($message_id,$txt,charityAdminSettingsKeyboard(),'HTML');}
}
if (!function_exists('charityAdminRenderProofs')) {
    function charityAdminRenderProofs(){global $message_id;$c=charityGetCampaign();$proofs=charityProofRows((int)($c['id']??0),30);$txt="📸 <b>مدیریت تصاویر خرید و کمک‌ها</b>\n\nتعداد تصاویر: <b>".count($proofs)."</b>";$rows=[[['text'=>'➕ بارگذاری عکس جدید','callback_data'=>'charityAdminAddProof']],[['text'=>charityReportVisible()?'🟢 نمایش دکمه گزارش: روشن':'🔴 نمایش دکمه گزارش: خاموش','callback_data'=>'charityAdminToggleReport']]];foreach($proofs as$r){$rows[]=[['text'=>'🖼 #'.(int)$r['id'].' '.mb_substr(trim((string)($r['caption']??'')),0,25,'UTF-8'),'callback_data'=>'charityAdminProofView_'.(int)$r['id']]];}$rows[]=[['text'=>'↩️ تنظیمات کمپین','callback_data'=>'charityAdminSettings']];smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');}
}

charityEnsureInfrastructure();
charityDetectCardToCardIntent();
charityFinalizeCardToCardConfirmations();
if(!defined('DELTA_CHARITY_SHUTDOWN_REGISTERED')){define('DELTA_CHARITY_SHUTDOWN_REGISTERED',true);register_shutdown_function('charityFinalizeCardToCardConfirmations');}

$charityIsAdmin=charityAdminIsAllowed();$charityData=(string)($data??'');$charityText=(string)($text??'');$charityStep=is_array($userInfo??null)?(string)($userInfo['step']??''):'';
if($charityData==='charityCampaign'){charityRenderCampaign();exit;}
if($charityData==='charityProofs'){charityRenderProofList();exit;}
if(preg_match('/^charityProofView_(\d+)$/',$charityData,$m)){charitySendProof((int)$m[1],false);exit;}
if($charityData==='charityDonate'){charityRenderDonateMenu();exit;}
if(preg_match('/^charityDonateAmount_(\d+)$/',$charityData,$m)){charityCreateDirectCardPayment((int)$m[1]);exit;}
if($charityData==='charityDonateCustom'){setUser('charityDonateCustom','step');sendMessage('✍️ مبلغ دلخواه کمک را به تومان بفرستید. این مبلغ فقط کارت‌به‌کارت خواهد شد.',$cancelKey??null);exit;}
if($charityStep==='charityDonateCustom'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<1000){sendMessage('❌ مبلغ معتبر نیست.');exit;}charityCreateDirectCardPayment((int)$n);exit;}
if(preg_match('/^charityDirectReceiptPrompt_([a-f0-9]+)$/i',$charityData,$m)){charityPromptDirectReceipt($m[1]);exit;}
if(preg_match('/^charityDirectCancel_([a-f0-9]+)$/i',$charityData,$m)){charityCancelDirectPayment($m[1]);exit;}
if(preg_match('/^charityDirectReceipt\|([a-f0-9]+)$/i',$charityStep,$m)){charityHandleDirectReceipt($m[1]);exit;}

if($charityIsAdmin){
    if($charityData==='charityAdminSettings'){charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminStart'){if(charityIsCollecting()){alert('جمع‌آوری همین حالا فعال است.');}else{charityStartNewCampaign();alert('دوره جدید ۷ روزه از همین لحظه شروع شد.');}charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminStop'){if(charityIsCollecting()){charityStopCampaign();alert('جمع‌آوری متوقف شد؛ دکمه و مبلغ نهایی باقی می‌ماند.');}else alert('جمع‌آوری از قبل متوقف است.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminToggleButton'){charitySetSetting('CHARITY_BUTTON_VISIBLE',charityButtonVisible()?'0':'1');alert('وضعیت نمایش دکمه کمپین تغییر کرد.');charityAdminRenderSettings();exit;}
    if(preg_match('/^charityDirectApprove_([a-f0-9]+)$/i',$charityData,$m)){charityApproveDirectPayment($m[1]);exit;}
    if(preg_match('/^charityDirectDecline_([a-f0-9]+)$/i',$charityData,$m)){charityDeclineDirectPayment($m[1]);exit;}
    if($charityData==='charityAdminAdjustMenu'){charityAdminRenderAdjustMenu();exit;}
    if($charityData==='charityAdminAdjustAdd'){setUser('charityAdminWaitAdjustAdd','step');sendMessage('➕ مبلغی که می‌خواهید به جمع کمپین اضافه شود را به تومان بفرستید.',$cancelKey??null);exit;}
    if($charityData==='charityAdminAdjustSubtract'){setUser('charityAdminWaitAdjustSubtract','step');sendMessage('➖ مبلغی که می‌خواهید از جمع کمپین کم شود را به تومان بفرستید.',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitAdjustAdd'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<=0){sendMessage('❌ مبلغ معتبر نیست.');exit;}if(!charityGetCampaign()){sendMessage('❌ هنوز کمپینی ساخته نشده است.');setUser('none','step');exit;}charityAddManualAdjustment((int)$n);setUser('none','step');sendMessage('✅ مبلغ به جمع کمپین اضافه شد.');charityAdminRenderAdjustMenu();exit;}
    if($charityStep==='charityAdminWaitAdjustSubtract'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(int)$n<=0){sendMessage('❌ مبلغ معتبر نیست.');exit;}$c=charityGetCampaign();if(!$c){sendMessage('❌ هنوز کمپینی ساخته نشده است.');setUser('none','step');exit;}$tot=charityTotals($c);$cur=max(0,(int)($tot['total']??0));$take=min((int)$n,$cur);if($take>0)charityAddManualAdjustment(-$take);setUser('none','step');sendMessage('✅ '.number_format($take).' تومان از جمع کمپین کم شد.');charityAdminRenderAdjustMenu();exit;}
    if($charityData==='charityAdminAdjustZero'){$c=charityGetCampaign();$tot=$c?charityTotals($c):['total'=>0];$cur=max(0,(int)($tot['total']??0));$txt='⚠️ مبلغ فعلی '.number_format($cur).' تومان است. مطمئن هستید مبلغ نمایش‌داده‌شده صفر شود؟';$kb=json_encode(['inline_keyboard'=>[[['text'=>'✅ بله، صفر کن','callback_data'=>'charityAdminAdjustZeroConfirm']],[['text'=>'❌ انصراف','callback_data'=>'charityAdminAdjustMenu']]]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);smartSendOrEdit($message_id,$txt,$kb);exit;}
    if($charityData==='charityAdminAdjustZeroConfirm'){$c=charityGetCampaign();if($c){$tot=charityTotals($c);$cur=max(0,(int)($tot['total']??0));if($cur>0)charityAddManualAdjustment(-$cur);}alert('مبلغ کمپین صفر شد.');charityAdminRenderAdjustMenu();exit;}
    if($charityData==='charityAdminSetPercent'){setUser('charityAdminWaitPercent','step');sendMessage('📊 درصد جدید را بفرستید؛ مثال 10 یا 7.5',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitPercent'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$n=charityNormalizeAmountText($charityText);if($n===''||(float)$n<0||(float)$n>100){sendMessage('❌ درصد باید بین ۰ تا ۱۰۰ باشد.');exit;}charityChangePercent((float)$n);setUser('none','step');sendMessage('✅ درصد تغییر کرد.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminSetTemplate'){setUser('charityAdminWaitTemplate','step');sendMessage("📝 متن کامل صفحه کمپین را بفرستید.\nمی‌توانید متغیرهای {TOTAL} {CARD_TOTAL} {DIRECT_TOTAL} {PERCENT} {PARTICIPANTS} {TIME} {STATUS} {START_TIME} {END_TIME} را هرجا خواستید استفاده کنید یا اصلاً ننویسید.\nایموجی پرمیوم داخل همین پیام هم ذخیره می‌شود.",$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitTemplate'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$entities=charityExtractPremiumTextEntities($update->message->entities??[]);charitySetSetting('CHARITY_PAGE_TEMPLATE',$charityText);charitySetSetting('CHARITY_PAGE_ENTITIES',json_encode($entities,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));setUser('none','step');sendMessage('✅ متن صفحه و ایموجی‌های پرمیوم آن ذخیره شد.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminTemplateHelp'){sendMessage("🔣 متغیرها:\n{TOTAL} جمع کل\n{CARD_TOTAL} سهم کارت‌به‌کارت\n{DIRECT_TOTAL} کمک مستقیم\n{PERCENT} درصد\n{PARTICIPANTS} مشارکت‌کنندگان\n{TIME} زمان باقی‌مانده\n{STATUS} وضعیت\n{START_TIME} شروع\n{END_TIME} پایان\n\nهرکدام را نخواهید، از متن حذف کنید.");exit;}
    if($charityData==='charityAdminSetMainLabel'){setUser('charityAdminWaitMainLabel','step');sendMessage('✏️ نام جدید دکمه کمپین را بفرستید. می‌توانید یک ایموجی پرمیوم هم در پیام قرار دهید.',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitMainLabel'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$p=charityExtractButtonPremium($charityText,$update->message->entities??[]);$label=trim((string)($p['text']??$charityText));if($label==='')$label='کمک‌های جمع‌آوری‌شده';charitySetSetting('CHARITY_MAIN_BUTTON_LABEL',$label);charitySetSetting('CHARITY_MAIN_BUTTON_ICON',trim((string)($p['custom_emoji_id']??'')));setUser('none','step');sendMessage('✅ دکمه کمپین ذخیره شد.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminToggleReport'){charitySetSetting('CHARITY_REPORT_VISIBLE',charityReportVisible()?'0':'1');alert('وضعیت دکمه گزارش تغییر کرد.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminSetReportLabel'){setUser('charityAdminWaitReportLabel','step');sendMessage('✏️ نام جدید دکمه گزارش را بفرستید. ایموجی پرمیوم هم پشتیبانی می‌شود.',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitReportLabel'&&$charityText!==''&&$charityText!==($buttonValues['cancel']??'')){$p=charityExtractButtonPremium($charityText,$update->message->entities??[]);$label=trim((string)($p['text']??$charityText));if($label==='')$label='گزارش خرید کمک‌ها';charitySetSetting('CHARITY_REPORT_BUTTON_LABEL',$label);charitySetSetting('CHARITY_REPORT_BUTTON_ICON',trim((string)($p['custom_emoji_id']??'')));setUser('none','step');sendMessage('✅ دکمه گزارش ذخیره شد.');charityAdminRenderSettings();exit;}
    if($charityData==='charityAdminProofs'){charityAdminRenderProofs();exit;}
    if($charityData==='charityAdminAddProof'){$c=charityGetCampaign();if(!$c){alert('ابتدا یک دوره کمپین بسازید.',true);exit;}setUser('charityAdminWaitProof','step');sendMessage('📸 عکس خرید/تحویل کمک‌ها را ارسال کنید؛ کپشن اختیاری است.',$cancelKey??null);exit;}
    if($charityStep==='charityAdminWaitProof'&&$charityText!==($buttonValues['cancel']??'')){if(isset($update->message->photo)){$photos=$update->message->photo;$ph=end($photos);$fileId=(string)($ph->file_id??'');$caption=trim((string)($update->message->caption??''));$c=charityGetCampaign();$cid=(int)($c['id']??0);$now=time();if($fileId!==''&&$cid>0){$stmt=$connection->prepare("INSERT INTO `charity_proofs` (`campaign_id`,`file_id`,`caption`,`active`,`created_at`) VALUES (?,?,?,1,?)");$stmt->bind_param('issi',$cid,$fileId,$caption,$now);$stmt->execute();$stmt->close();setUser('none','step');sendMessage('✅ تصویر ذخیره شد.');charityAdminRenderProofs();exit;}}sendMessage('❌ فقط عکس ارسال کنید.');exit;}
    if(preg_match('/^charityAdminProofView_(\d+)$/',$charityData,$m)){charitySendProof((int)$m[1],true);exit;}
    if(preg_match('/^charityAdminDeleteProof_(\d+)$/',$charityData,$m)){$id=(int)$m[1];$stmt=$connection->prepare("UPDATE `charity_proofs` SET `active`=0 WHERE `id`=?");if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();}alert('تصویر حذف شد.');charityAdminRenderProofs();exit;}
}
