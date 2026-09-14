<?php
/**
 * Managed 7-day school-supplies charity campaign.
 * Loaded from bot.php after config.php.
 *
 * Admin controls:
 * - enable/disable campaign; every OFF -> ON starts a fresh exact 7-day campaign
 * - editable contribution percentage
 * - editable main-menu campaign/report button labels
 * - report/proof button visibility
 * - upload/delete purchase-proof photos using Telegram file_id
 *
 * User features:
 * - campaign is pinned as the first main-menu row while enabled
 * - optional proof/report button is immediately below it
 * - live totals and exact remaining time on refresh
 * - direct wallet donations with idempotent confirmation
 */

if (!function_exists('charitySetting')) {
    function charitySetting($key, $default = ''){
        if (function_exists('getSettingValue')) return getSettingValue($key, $default);
        global $connection;
        $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? ORDER BY `id` ASC LIMIT 1");
        if (!$stmt) return $default;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? $row['value'] : $default;
    }
}

if (!function_exists('charitySetSetting')) {
    function charitySetSetting($key, $value){
        if (function_exists('upsertSettingValue')) return upsertSettingValue($key, (string)$value);
        global $connection;
        $stmt = $connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? ORDER BY `id` ASC LIMIT 1");
        if (!$stmt) return false;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $stmt = $connection->prepare("UPDATE `setting` SET `value`=? WHERE `id`=?");
            $id = (int)$row['id'];
            $value = (string)$value;
            $stmt->bind_param('si', $value, $id);
        } else {
            $stmt = $connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
            $value = (string)$value;
            $stmt->bind_param('ss', $key, $value);
        }
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('charityEnsureColumn')) {
    function charityEnsureColumn($table, $column, $definition){
        global $connection;
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($table === '' || $column === '') return;
        $res = $connection->query("SHOW COLUMNS FROM `{$table}` LIKE '" . $connection->real_escape_string($column) . "'");
        if ($res && $res->num_rows === 0) {
            @$connection->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}

if (!function_exists('charityEnsureInfrastructure')) {
    function charityEnsureInfrastructure(){
        global $connection;
        static $done = false;
        if ($done) return true;
        $done = true;
        if (!$connection || $connection->connect_error) return false;

        $connection->query("CREATE TABLE IF NOT EXISTS `charity_campaigns` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(190) NOT NULL,
            `description` TEXT NULL,
            `percent` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
            `starts_at` INT NOT NULL,
            `ends_at` INT NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` INT NOT NULL,
            PRIMARY KEY (`id`),
            KEY `campaign_window` (`active`,`starts_at`,`ends_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $connection->query("CREATE TABLE IF NOT EXISTS `charity_contributions` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `campaign_id` INT NOT NULL,
            `pay_id` INT NULL DEFAULT NULL,
            `user_id` BIGINT NOT NULL DEFAULT 0,
            `source` VARCHAR(32) NOT NULL,
            `base_amount` BIGINT NOT NULL DEFAULT 0,
            `amount` BIGINT NOT NULL DEFAULT 0,
            `created_at` INT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',
            `direct_token` VARCHAR(64) NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_campaign_pay` (`campaign_id`,`pay_id`),
            UNIQUE KEY `uniq_direct_token` (`direct_token`),
            KEY `campaign_status` (`campaign_id`,`status`),
            KEY `campaign_user` (`campaign_id`,`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $connection->query("CREATE TABLE IF NOT EXISTS `charity_proofs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `campaign_id` INT NOT NULL DEFAULT 0,
            `file_id` VARCHAR(255) NOT NULL,
            `caption` VARCHAR(1000) NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` INT NOT NULL,
            PRIMARY KEY (`id`),
            KEY `campaign_active` (`campaign_id`,`active`,`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        charityEnsureColumn('charity_campaigns', 'active', "TINYINT(1) NOT NULL DEFAULT 1");

        if (charitySetting('CHARITY_CONTROL_VERSION', '') !== '2') {
            charitySetSetting('CHARITY_ENABLED', '0');
            charitySetSetting('CHARITY_PERCENT', charitySetting('CHARITY_PERCENT', '10'));
            charitySetSetting('CHARITY_MAIN_BUTTON_LABEL', charitySetting('CHARITY_MAIN_BUTTON_LABEL', '🎒 هزینه جمع‌آوری‌شده'));
            charitySetSetting('CHARITY_REPORT_BUTTON_LABEL', charitySetting('CHARITY_REPORT_BUTTON_LABEL', '📸 گزارش خرید کمک‌ها'));
            charitySetSetting('CHARITY_REPORT_VISIBLE', charitySetting('CHARITY_REPORT_VISIBLE', '0'));
            charitySetSetting('CHARITY_CONTROL_VERSION', '2');
            $now = time();
            @$connection->query("UPDATE `charity_campaigns` SET `active`=0, `ends_at`=LEAST(`ends_at`, {$now}) WHERE `active`=1");

            $legacyType = 'MAIN_BUTTONS🎒 هزینه جمع‌آوری‌شده';
            $stmt = $connection->prepare("DELETE FROM `setting` WHERE `type`=?");
            if ($stmt) {
                $stmt->bind_param('s', $legacyType);
                $stmt->execute();
                $stmt->close();
            }
        }

        return true;
    }
}

if (!function_exists('charityIsEnabled')) {
    function charityIsEnabled(){ return charitySetting('CHARITY_ENABLED', '0') === '1'; }
}
if (!function_exists('charityReportVisible')) {
    function charityReportVisible(){ return charitySetting('CHARITY_REPORT_VISIBLE', '0') === '1'; }
}
if (!function_exists('charityConfiguredPercent')) {
    function charityConfiguredPercent(){
        $p = (float)charitySetting('CHARITY_PERCENT', '10');
        if ($p < 0) $p = 0;
        if ($p > 100) $p = 100;
        return $p;
    }
}
if (!function_exists('charityMainButtonLabel')) {
    function charityMainButtonLabel(){
        $v = trim((string)charitySetting('CHARITY_MAIN_BUTTON_LABEL', '🎒 هزینه جمع‌آوری‌شده'));
        return $v !== '' ? $v : '🎒 هزینه جمع‌آوری‌شده';
    }
}
if (!function_exists('charityReportButtonLabel')) {
    function charityReportButtonLabel(){
        $v = trim((string)charitySetting('CHARITY_REPORT_BUTTON_LABEL', '📸 گزارش خرید کمک‌ها'));
        return $v !== '' ? $v : '📸 گزارش خرید کمک‌ها';
    }
}

if (!function_exists('charityGetCampaign')) {
    function charityGetCampaign(){
        global $connection;
        $res = $connection->query("SELECT * FROM `charity_campaigns` ORDER BY `id` DESC LIMIT 1");
        return $res ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('charityRefreshCampaignState')) {
    function charityRefreshCampaignState($campaign = null){
        global $connection;
        if (!is_array($campaign)) $campaign = charityGetCampaign();
        if (!$campaign) return null;
        if ((int)$campaign['active'] === 1 && time() >= (int)$campaign['ends_at']) {
            $id = (int)$campaign['id'];
            $stmt = $connection->prepare("UPDATE `charity_campaigns` SET `active`=0 WHERE `id`=? AND `active`=1");
            if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close(); }
            $campaign['active'] = 0;
        }
        return $campaign;
    }
}

if (!function_exists('charityCampaignIsOpen')) {
    function charityCampaignIsOpen($campaign){
        if (!is_array($campaign)) return false;
        $now = time();
        return ((int)($campaign['active'] ?? 0) === 1 && $now >= (int)$campaign['starts_at'] && $now < (int)$campaign['ends_at']);
    }
}

if (!function_exists('charityStartNewCampaign')) {
    function charityStartNewCampaign(){
        global $connection;
        $now = time();
        $end = $now + (7 * 24 * 60 * 60);
        $percent = charityConfiguredPercent();
        $title = 'کمپین مهر مهربانی';
        $description = 'تهیه لوازم مدرسه برای دانش‌آموزانی که امکان خرید وسایل موردنیازشان را ندارند.';
        @$connection->query("UPDATE `charity_campaigns` SET `active`=0, `ends_at`=LEAST(`ends_at`, {$now}) WHERE `active`=1");
        $stmt = $connection->prepare("INSERT INTO `charity_campaigns` (`title`,`description`,`percent`,`starts_at`,`ends_at`,`active`,`created_at`) VALUES (?,?,?,?,?,1,?)");
        if (!$stmt) return false;
        $stmt->bind_param('ssdiii', $title, $description, $percent, $now, $end, $now);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) charitySetSetting('CHARITY_ENABLED', '1');
        return $ok;
    }
}

if (!function_exists('charityStopCampaign')) {
    function charityStopCampaign(){
        global $connection;
        $campaign = charityGetCampaign();
        if ($campaign && charityCampaignIsOpen($campaign)) charitySyncCompletedPayments($campaign);
        $now = time();
        @$connection->query("UPDATE `charity_campaigns` SET `active`=0, `ends_at`=LEAST(`ends_at`, {$now}) WHERE `active`=1");
        charitySetSetting('CHARITY_ENABLED', '0');
    }
}

if (!function_exists('charitySyncCompletedPayments')) {
    function charitySyncCompletedPayments($campaign = null){
        global $connection;
        if (!is_array($campaign)) $campaign = charityGetCampaign();
        if (!is_array($campaign)) return;
        $campaignId = (int)$campaign['id'];
        $start = (int)$campaign['starts_at'];
        $end = min((int)$campaign['ends_at'], time());
        $percent = (float)$campaign['percent'];
        if ($campaignId <= 0 || $end < $start || $percent < 0) return;
        $sql = "INSERT IGNORE INTO `charity_contributions`
            (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`)
            SELECT ?, p.`id`, p.`user_id`, 'transaction', p.`price`,
                   ROUND(p.`price` * ? / 100), p.`request_date`, 'confirmed', NULL
            FROM `pays` p
            WHERE p.`price` > 0
              AND p.`request_date` >= ?
              AND p.`request_date` <= ?
              AND p.`state` IN ('paid','approved','paid_with_wallet')";
        $stmt = $connection->prepare($sql);
        if (!$stmt) return;
        $stmt->bind_param('idii', $campaignId, $percent, $start, $end);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('charityChangePercent')) {
    function charityChangePercent($newPercent){
        global $connection;
        $newPercent = (float)$newPercent;
        if ($newPercent < 0 || $newPercent > 100) return false;
        $campaign = charityRefreshCampaignState(charityGetCampaign());
        if ($campaign && charityCampaignIsOpen($campaign)) {
            charitySyncCompletedPayments($campaign);
            $id = (int)$campaign['id'];
            $stmt = $connection->prepare("UPDATE `charity_campaigns` SET `percent`=? WHERE `id`=?");
            if ($stmt) { $stmt->bind_param('di', $newPercent, $id); $stmt->execute(); $stmt->close(); }
        }
        charitySetSetting('CHARITY_PERCENT', rtrim(rtrim(number_format($newPercent, 2, '.', ''), '0'), '.'));
        return true;
    }
}

if (!function_exists('charityTotals')) {
    function charityTotals($campaign){
        global $connection;
        if (!$campaign) return ['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0,'transaction_count'=>0,'direct_count'=>0];
        charitySyncCompletedPayments($campaign);
        $campaignId = (int)$campaign['id'];
        $out = ['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0,'transaction_count'=>0,'direct_count'=>0];
        $stmt = $connection->prepare("SELECT
            COALESCE(SUM(`amount`),0) AS total,
            COALESCE(SUM(CASE WHEN `source`='transaction' THEN `amount` ELSE 0 END),0) AS transactions,
            COALESCE(SUM(CASE WHEN `source`='direct' THEN `amount` ELSE 0 END),0) AS direct,
            COUNT(DISTINCT CASE WHEN `user_id`>0 THEN `user_id` END) AS participants,
            SUM(CASE WHEN `source`='transaction' THEN 1 ELSE 0 END) AS transaction_count,
            SUM(CASE WHEN `source`='direct' THEN 1 ELSE 0 END) AS direct_count
            FROM `charity_contributions` WHERE `campaign_id`=? AND `status`='confirmed'");
        if (!$stmt) return $out;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) foreach ($out as $k=>$v) $out[$k] = (int)($row[$k] ?? 0);
        return $out;
    }
}

if (!function_exists('charityRemainingText')) {
    function charityRemainingText($seconds){
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400); $seconds %= 86400;
        $hours = intdiv($seconds, 3600); $seconds %= 3600;
        $minutes = intdiv($seconds, 60); $secs = $seconds % 60;
        return $days . ' روز و ' . $hours . ' ساعت و ' . $minutes . ' دقیقه و ' . $secs . ' ثانیه';
    }
}
if (!function_exists('charityPercentText')) {
    function charityPercentText($p){ return rtrim(rtrim(number_format((float)$p, 2, '.', ''), '0'), '.'); }
}

if (!function_exists('charityBuildMainMenuRows')) {
    function charityBuildMainMenuRows(){
        global $isChildBot;
        $rows = [];
        if (!empty($isChildBot)) return $rows;
        if (charityIsEnabled()) $rows[] = [['text'=>charityMainButtonLabel(), 'callback_data'=>'charityCampaign']];
        if (charityReportVisible()) $rows[] = [['text'=>charityReportButtonLabel(), 'callback_data'=>'charityProofs']];
        return $rows;
    }
}

if (!function_exists('charityRenderCampaign')) {
    function charityRenderCampaign(){
        global $message_id, $buttonValues;
        $campaign = charityRefreshCampaignState(charityGetCampaign());
        if (!$campaign) {
            $text = "🎒 <b>کمپین خیریه</b>\n\nهنوز کمپینی شروع نشده است.";
            $rows = [[['text'=>$buttonValues['back_to_main'] ?? '🏠 صفحه اصلی','callback_data'=>'mainMenu']]];
            smartSendOrEdit($message_id, $text, json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), 'HTML');
            return;
        }
        $totals = charityTotals($campaign);
        $open = charityCampaignIsOpen($campaign);
        $now = time();
        $percentText = charityPercentText($campaign['percent']);
        if ($open) {
            $timeLabel = '⏳ زمان باقی‌مانده: <b>' . charityRemainingText((int)$campaign['ends_at'] - $now) . '</b>';
            $status = '🟢 کمپین در حال اجراست.';
        } else {
            $timeLabel = '⏳ زمان باقی‌مانده: <b>۰ روز و ۰ ساعت و ۰ دقیقه و ۰ ثانیه</b>';
            $status = '✅ این دوره از کمپین به پایان رسیده است.';
        }
        $text = "🎒 <b>" . htmlspecialchars((string)$campaign['title'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n"
              . htmlspecialchars((string)$campaign['description'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') . "\n\n"
              . "🤝 سهم تراکنش‌های مالی موفق: <b>{$percentText}٪</b>\n"
              . "❤️ کمک مستقیم کاربران: <b>۱۰۰٪</b>\n\n"
              . "💰 <b>مبلغ جمع‌آوری‌شده:</b>\n<b>" . number_format($totals['total']) . " تومان</b>\n\n"
              . "🛒 سهم خرید/تمدید/افزایش/شارژ: " . number_format($totals['transactions']) . " تومان\n"
              . "❤️ کمک مستقیم: " . number_format($totals['direct']) . " تومان\n"
              . "👥 مشارکت‌کنندگان: " . number_format($totals['participants']) . " نفر\n\n"
              . $timeLabel . "\n" . $status;
        $rows = [];
        if ($open && charityIsEnabled()) $rows[] = [['text'=>'❤️ کمک مستقیم به کمپین','callback_data'=>'charityDonate']];
        if (charityReportVisible()) $rows[] = [['text'=>charityReportButtonLabel(),'callback_data'=>'charityProofs']];
        $rows[] = [['text'=>'🔄 بروزرسانی مبلغ و زمان','callback_data'=>'charityCampaign']];
        $rows[] = [['text'=>$buttonValues['back_to_main'] ?? '🏠 صفحه اصلی','callback_data'=>'mainMenu']];
        smartSendOrEdit($message_id, $text, json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'HTML');
    }
}

if (!function_exists('charityRenderDonateMenu')) {
    function charityRenderDonateMenu(){
        global $connection, $from_id, $message_id;
        $campaign = charityRefreshCampaignState(charityGetCampaign());
        if (!charityIsEnabled() || !charityCampaignIsOpen($campaign)) { charityRenderCampaign(); return; }
        $wallet = 0;
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid`=? LIMIT 1");
        if ($stmt) { $stmt->bind_param('i', $from_id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); $wallet = (int)($row['wallet'] ?? 0); }
        $text = "❤️ <b>کمک مستقیم به کمپین</b>\n\nمبلغ کمک مستقیم به‌طور کامل به جمع کمپین اضافه می‌شود.\n\n"
              . "💰 موجودی شما: <b>" . number_format($wallet) . " تومان</b>\n\nمبلغ را انتخاب کنید:";
        $rows = [
            [['text'=>'۵۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_50000'],['text'=>'۱۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_100000']],
            [['text'=>'۲۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_200000'],['text'=>'۵۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_500000']],
            [['text'=>'✍️ مبلغ دلخواه','callback_data'=>'charityDonateCustom']],
            [['text'=>'💳 افزایش موجودی','callback_data'=>'increaseMyWallet']],
            [['text'=>'↩️ بازگشت','callback_data'=>'charityCampaign']],
        ];
        smartSendOrEdit($message_id, $text, json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), 'HTML');
    }
}

if (!function_exists('charityNormalizeAmountText')) {
    function charityNormalizeAmountText($value){
        $value = strtr((string)$value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value = str_replace([',','٬','،',' ','تومان','تومن','%','٪'], '', $value);
        return preg_match('/^\d+(?:\.\d+)?$/', $value) ? $value : '';
    }
}

if (!function_exists('charityCreateDonationConfirmation')) {
    function charityCreateDonationConfirmation($amount){
        global $connection, $from_id, $message_id;
        $amount = (int)$amount;
        $campaign = charityRefreshCampaignState(charityGetCampaign());
        if (!charityIsEnabled() || !charityCampaignIsOpen($campaign)) { charityRenderCampaign(); return; }
        if ($amount < 1000 || $amount > 2000000000) { alert('مبلغ کمک معتبر نیست.', true); return; }
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid`=? LIMIT 1");
        $wallet = 0;
        if ($stmt) { $stmt->bind_param('i',$from_id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close(); $wallet=(int)($r['wallet']??0); }
        if ($wallet < $amount) {
            $text = "❌ <b>موجودی کافی نیست.</b>\n\n❤️ مبلغ کمک: " . number_format($amount) . " تومان\n💰 موجودی: " . number_format($wallet) . " تومان\n➖ کسری: " . number_format($amount-$wallet) . " تومان";
            $keys = ['inline_keyboard'=>[[['text'=>'💳 افزایش موجودی','callback_data'=>'increaseMyWallet']],[['text'=>'↩️ انتخاب مبلغ دیگر','callback_data'=>'charityDonate']]]];
            smartSendOrEdit($message_id,$text,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML'); return;
        }
        try { $token = bin2hex(random_bytes(6)); } catch (Throwable $e) { $token = substr(sha1(uniqid((string)$from_id,true)),0,12); }
        setUser(json_encode(['charity_token'=>$token,'amount'=>$amount,'campaign_id'=>(int)$campaign['id']],JSON_UNESCAPED_UNICODE),'temp');
        setUser('none','step');
        $text = "❤️ <b>تأیید کمک مستقیم</b>\n\nمبلغ کمک: <b>" . number_format($amount) . " تومان</b>\nموجودی فعلی: " . number_format($wallet) . " تومان\nموجودی بعد از کمک: " . number_format($wallet-$amount) . " تومان\n\nبا تأیید، مبلغ از کیف پول کسر و کامل به صندوق اضافه می‌شود.";
        $keys = ['inline_keyboard'=>[[['text'=>'✅ تأیید و کمک','callback_data'=>'charityDonateConfirm_'.$token]],[['text'=>'❌ انصراف','callback_data'=>'charityDonate']]]];
        smartSendOrEdit($message_id,$text,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML');
    }
}

if (!function_exists('charityFinalizeDirectDonation')) {
    function charityFinalizeDirectDonation($token){
        global $connection, $from_id, $userInfo;
        $token = preg_replace('/[^a-f0-9]/i','',(string)$token);
        $payload = json_decode((string)($userInfo['temp'] ?? ''), true);
        if (!is_array($payload) || ($payload['charity_token'] ?? '') !== $token) { alert('این درخواست منقضی شده یا قبلاً استفاده شده است.', true); charityRenderCampaign(); return; }
        $amount=(int)($payload['amount']??0); $campaignId=(int)($payload['campaign_id']??0);
        $campaign=charityRefreshCampaignState(charityGetCampaign());
        if (!$campaign || (int)$campaign['id']!==$campaignId || !charityIsEnabled() || !charityCampaignIsOpen($campaign) || $amount<1000) { setUser('','temp'); charityRenderCampaign(); return; }
        $now=time();
        $stmt=$connection->prepare("INSERT IGNORE INTO `charity_contributions` (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`) VALUES (?,NULL,?,'direct',?,?,?,'pending',?)");
        if(!$stmt){ alert('خطا در ثبت کمک.',true); return; }
        $stmt->bind_param('iiiiis',$campaignId,$from_id,$amount,$amount,$now,$token); $stmt->execute(); $inserted=($stmt->affected_rows===1); $stmt->close();
        if(!$inserted){ setUser('','temp'); alert('این درخواست قبلاً ثبت شده است.'); charityRenderCampaign(); return; }
        $stmt=$connection->prepare("UPDATE `users` SET `wallet`=`wallet`-? WHERE `userid`=? AND `wallet`>=?");
        $stmt->bind_param('iii',$amount,$from_id,$amount); $stmt->execute(); $deducted=($stmt->affected_rows===1); $stmt->close();
        if(!$deducted){
            $stmt=$connection->prepare("UPDATE `charity_contributions` SET `status`='failed' WHERE `direct_token`=? AND `status`='pending'");
            $stmt->bind_param('s',$token); $stmt->execute(); $stmt->close(); setUser('','temp'); alert('موجودی کافی نیست.',true); charityRenderDonateMenu(); return;
        }
        $stmt=$connection->prepare("UPDATE `charity_contributions` SET `status`='confirmed' WHERE `direct_token`=? AND `status`='pending'");
        $stmt->bind_param('s',$token); $stmt->execute(); $stmt->close();
        setUser('','temp'); setUser('none','step');
        alert('❤️ کمک شما با موفقیت ثبت شد. ممنون از همراهی شما.'); charityRenderCampaign();
    }
}

if (!function_exists('charityProofRows')) {
    function charityProofRows($campaignId = 0, $limit = 30){
        global $connection;
        $campaignId = (int)$campaignId;
        $limit = max(1,min(50,(int)$limit));
        if ($campaignId <= 0) { $c=charityGetCampaign(); $campaignId=(int)($c['id']??0); }
        if ($campaignId <= 0) return [];
        $stmt=$connection->prepare("SELECT * FROM `charity_proofs` WHERE `campaign_id`=? AND `active`=1 ORDER BY `id` DESC LIMIT {$limit}");
        if(!$stmt) return [];
        $stmt->bind_param('i',$campaignId); $stmt->execute(); $res=$stmt->get_result(); $rows=[]; while($r=$res->fetch_assoc()) $rows[]=$r; $stmt->close(); return $rows;
    }
}

if (!function_exists('charityRenderProofList')) {
    function charityRenderProofList(){
        global $message_id, $buttonValues;
        $campaign=charityGetCampaign();
        $rows=charityProofRows((int)($campaign['id']??0),30);
        $text="📸 <b>گزارش خرید و تحویل کمک‌ها</b>\n\nدر این بخش تصاویر خریدهایی که با مبلغ جمع‌آوری‌شده انجام شده قرار می‌گیرد تا روند کمک‌ها شفاف باشد.";
        $kb=[];
        if(!$rows){ $text.="\n\nهنوز تصویری برای این دوره بارگذاری نشده است."; }
        else {
            $i=1;
            foreach($rows as $r){
                $cap=trim((string)($r['caption']??''));
                $label='🖼 تصویر '.$i;
                if($cap!=='') $label.=' - '.mb_substr($cap,0,28,'UTF-8');
                $kb[]=[['text'=>$label,'callback_data'=>'charityProofView_'.(int)$r['id']]]; $i++;
            }
        }
        if(charityIsEnabled()) $kb[]=[['text'=>'↩️ بازگشت به کمپین','callback_data'=>'charityCampaign']];
        $kb[]=[['text'=>$buttonValues['back_to_main']??'🏠 صفحه اصلی','callback_data'=>'mainMenu']];
        smartSendOrEdit($message_id,$text,json_encode(['inline_keyboard'=>$kb],JSON_UNESCAPED_UNICODE),'HTML');
    }
}

if (!function_exists('charitySendProof')) {
    function charitySendProof($id, $adminMode=false){
        global $connection,$from_id;
        $id=(int)$id;
        $stmt=$connection->prepare("SELECT * FROM `charity_proofs` WHERE `id`=? LIMIT 1");
        if(!$stmt) return;
        $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if(!$r || (!$adminMode && (int)$r['active']!==1)){ alert('تصویر پیدا نشد.',true); return; }
        $caption=trim((string)($r['caption']??''));
        if($caption==='') $caption='📸 گزارش خرید کمک‌ها';
        $keys=$adminMode
            ? ['inline_keyboard'=>[[['text'=>'🗑 حذف این تصویر','callback_data'=>'charityAdminDeleteProof_'.$id]],[['text'=>'↩️ مدیریت تصاویر','callback_data'=>'charityAdminProofs']]]]
            : ['inline_keyboard'=>[[['text'=>'↩️ بازگشت به گزارش‌ها','callback_data'=>'charityProofs']]]];
        sendPhoto($r['file_id'],$caption,json_encode($keys,JSON_UNESCAPED_UNICODE),'HTML',$from_id);
    }
}

if (!function_exists('charityAdminIsAllowed')) {
    function charityAdminIsAllowed(){
        global $from_id,$admin,$userInfo,$isChildBot;
        if(!empty($isChildBot)) return false;
        return ((int)$from_id===(int)$admin || (($userInfo['isAdmin']??false)==true));
    }
}

if (!function_exists('charityAdminSettingsKeyboard')) {
    function charityAdminSettingsKeyboard(){
        $enabled=charityIsEnabled(); $report=charityReportVisible();
        $rows=[
            [['text'=>$enabled?'🟢 کمپین: روشن':'🔴 کمپین: خاموش','callback_data'=>'charityAdminToggle']],
            [['text'=>'📊 درصد سهم: '.charityPercentText(charityConfiguredPercent()).'٪','callback_data'=>'charityAdminSetPercent']],
            [['text'=>'✏️ نام دکمه کمپین','callback_data'=>'charityAdminSetMainLabel']],
            [['text'=>$report?'🟢 دکمه گزارش: روشن':'🔴 دکمه گزارش: خاموش','callback_data'=>'charityAdminToggleReport']],
            [['text'=>'✏️ نام دکمه گزارش','callback_data'=>'charityAdminSetReportLabel']],
            [['text'=>'📸 مدیریت و بارگذاری تصاویر خرید','callback_data'=>'charityAdminProofs']],
            [['text'=>'↩️ بازگشت به تنظیمات ربات','callback_data'=>'botSettings']],
        ];
        return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('charityAdminRenderSettings')) {
    function charityAdminRenderSettings(){
        global $message_id;
        $campaign=charityRefreshCampaignState(charityGetCampaign());
        $status=charityIsEnabled()?'روشن':'خاموش';
        $detail='هنوز کمپین جدیدی شروع نشده است.';
        if($campaign){
            $totals=charityTotals($campaign);
            if(charityCampaignIsOpen($campaign)) $state='در حال اجرا — '.charityRemainingText((int)$campaign['ends_at']-time());
            else $state='پایان‌یافته/متوقف‌شده';
            $detail="وضعیت دوره آخر: <b>{$state}</b>\n💰 جمع دوره آخر: <b>".number_format($totals['total'])." تومان</b>";
        }
        $text="🎒 <b>تنظیمات کمپین خیریه</b>\n\n"
             ."وضعیت دکمه کمپین: <b>{$status}</b>\n"
             ."درصد فعلی: <b>".charityPercentText(charityConfiguredPercent())."٪</b>\n\n"
             .$detail."\n\n"
             ."ℹ️ هر بار کمپین را از حالت خاموش به روشن ببرید، همان لحظه یک دوره جدید دقیقاً ۷ روزه شروع می‌شود. خاموش‌کردن در میانه راه همان لحظه جمع‌آوری را متوقف می‌کند.";
        smartSendOrEdit($message_id,$text,charityAdminSettingsKeyboard(),'HTML');
    }
}

if (!function_exists('charityAdminRenderProofs')) {
    function charityAdminRenderProofs(){
        global $message_id;
        $campaign=charityGetCampaign(); $cid=(int)($campaign['id']??0); $proofs=charityProofRows($cid,30);
        $text="📸 <b>مدیریت تصاویر خرید و کمک‌ها</b>\n\nعکس‌هایی که اینجا بارگذاری می‌کنید با Telegram file_id ذخیره می‌شوند و کاربران از دکمه گزارش می‌توانند آن‌ها را ببینند.\n\nتعداد تصاویر دوره آخر: <b>".count($proofs)."</b>";
        $rows=[
            [['text'=>'➕ بارگذاری عکس جدید','callback_data'=>'charityAdminAddProof']],
            [['text'=>charityReportVisible()?'🟢 نمایش دکمه گزارش: روشن':'🔴 نمایش دکمه گزارش: خاموش','callback_data'=>'charityAdminToggleReport']],
        ];
        foreach($proofs as $r){
            $label='🖼 #'.(int)$r['id']; $cap=trim((string)($r['caption']??'')); if($cap!=='') $label.=' - '.mb_substr($cap,0,25,'UTF-8');
            $rows[]=[['text'=>$label,'callback_data'=>'charityAdminProofView_'.(int)$r['id']]];
        }
        $rows[]=[['text'=>'↩️ تنظیمات کمپین','callback_data'=>'charityAdminSettings']];
        smartSendOrEdit($message_id,$text,json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');
    }
}

charityEnsureInfrastructure();

$charityIsAdmin = charityAdminIsAllowed();
$charityData = isset($data) ? (string)$data : '';
$charityText = isset($text) ? (string)$text : '';
$charityStep = is_array($userInfo ?? null) ? (string)($userInfo['step'] ?? '') : '';

if($charityData==='charityCampaign'){ charityRenderCampaign(); exit; }
if($charityData==='charityProofs'){ charityRenderProofList(); exit; }
if(preg_match('/^charityProofView_(\d+)$/',$charityData,$m)){ charitySendProof((int)$m[1],false); exit; }
if($charityData==='charityDonate'){ charityRenderDonateMenu(); exit; }
if(preg_match('/^charityDonateAmount_(\d+)$/',$charityData,$m)){ charityCreateDonationConfirmation((int)$m[1]); exit; }
if($charityData==='charityDonateCustom'){
    setUser('charityDonateCustom','step'); setUser('','temp');
    sendMessage("✍️ مبلغ دلخواه را فقط به تومان بفرستید.\nمثال: 150000", $cancelKey ?? null); exit;
}
if($charityStep==='charityDonateCustom' && $charityText!=='' && $charityText!==($buttonValues['cancel']??'')){
    $n=charityNormalizeAmountText($charityText); if($n==='' || (int)$n<1000){ sendMessage('❌ مبلغ معتبر نیست. یک عدد حداقل ۱٬۰۰۰ تومان بفرستید.'); exit; }
    setUser('none','step'); charityCreateDonationConfirmation((int)$n); exit;
}
if(preg_match('/^charityDonateConfirm_([a-f0-9]+)$/i',$charityData,$m)){ charityFinalizeDirectDonation($m[1]); exit; }

if($charityIsAdmin){
    if($charityData==='charityAdminSettings'){ charityAdminRenderSettings(); exit; }
    if($charityData==='charityAdminToggle'){
        if(charityIsEnabled()){ charityStopCampaign(); alert('کمپین خاموش شد و جمع‌آوری از همین لحظه متوقف شد.'); }
        else { charityStartNewCampaign(); alert('کمپین روشن شد و تایمر ۷ روزه از همین لحظه شروع شد.'); }
        charityAdminRenderSettings(); exit;
    }
    if($charityData==='charityAdminSetPercent'){
        setUser('charityAdminWaitPercent','step');
        sendMessage("📊 درصد جدید را بفرستید.\nمثال: 10 یا 7.5\nمحدوده مجاز: 0 تا 100", $cancelKey ?? null); exit;
    }
    if($charityStep==='charityAdminWaitPercent' && $charityText!=='' && $charityText!==($buttonValues['cancel']??'')){
        $n=charityNormalizeAmountText($charityText); if($n==='' || (float)$n<0 || (float)$n>100){ sendMessage('❌ درصد معتبر نیست. عددی بین ۰ تا ۱۰۰ بفرستید.'); exit; }
        charityChangePercent((float)$n); setUser('none','step'); sendMessage('✅ درصد کمپین تغییر کرد.'); charityAdminRenderSettings(); exit;
    }
    if($charityData==='charityAdminSetMainLabel'){
        setUser('charityAdminWaitMainLabel','step'); sendMessage('✏️ اسم جدید دکمه اصلی کمپین را بفرستید.\nمثال: 🎒 مهر مهربانی', $cancelKey ?? null); exit;
    }
    if($charityStep==='charityAdminWaitMainLabel' && $charityText!=='' && $charityText!==($buttonValues['cancel']??'')){
        $v=trim($charityText); if($v==='' || mb_strlen($v,'UTF-8')>64){ sendMessage('❌ نام دکمه باید بین ۱ تا ۶۴ کاراکتر باشد.'); exit; }
        charitySetSetting('CHARITY_MAIN_BUTTON_LABEL',$v); setUser('none','step'); sendMessage('✅ نام دکمه کمپین تغییر کرد.'); charityAdminRenderSettings(); exit;
    }
    if($charityData==='charityAdminToggleReport'){
        charitySetSetting('CHARITY_REPORT_VISIBLE',charityReportVisible()?'0':'1'); alert('وضعیت دکمه گزارش تغییر کرد.'); charityAdminRenderSettings(); exit;
    }
    if($charityData==='charityAdminSetReportLabel'){
        setUser('charityAdminWaitReportLabel','step'); sendMessage('✏️ اسم جدید دکمه گزارش خریدها را بفرستید.\nمثال: 📸 گزارش خرید لوازم مدرسه', $cancelKey ?? null); exit;
    }
    if($charityStep==='charityAdminWaitReportLabel' && $charityText!=='' && $charityText!==($buttonValues['cancel']??'')){
        $v=trim($charityText); if($v==='' || mb_strlen($v,'UTF-8')>64){ sendMessage('❌ نام دکمه باید بین ۱ تا ۶۴ کاراکتر باشد.'); exit; }
        charitySetSetting('CHARITY_REPORT_BUTTON_LABEL',$v); setUser('none','step'); sendMessage('✅ نام دکمه گزارش تغییر کرد.'); charityAdminRenderSettings(); exit;
    }
    if($charityData==='charityAdminProofs'){ charityAdminRenderProofs(); exit; }
    if($charityData==='charityAdminAddProof'){
        $campaign=charityGetCampaign();
        if(!$campaign){ alert('ابتدا حداقل یک بار کمپین را روشن کنید تا یک دوره ساخته شود.',true); charityAdminRenderSettings(); exit; }
        setUser('charityAdminWaitProof','step');
        sendMessage("📸 عکس خرید/تحویل کمک‌ها را ارسال کنید.\nمی‌توانید روی خود عکس کپشن هم بنویسید؛ همان کپشن برای کاربران نمایش داده می‌شود.", $cancelKey ?? null); exit;
    }
    if($charityStep==='charityAdminWaitProof' && $charityText!==($buttonValues['cancel']??'')){
        if(isset($update->message->photo)){
            $photos=$update->message->photo; $ph=end($photos); $fileId=(string)($ph->file_id??'');
            $caption=trim((string)($update->message->caption??'')); $campaign=charityGetCampaign(); $cid=(int)($campaign['id']??0); $now=time();
            if($fileId!=='' && $cid>0){
                $stmt=$connection->prepare("INSERT INTO `charity_proofs` (`campaign_id`,`file_id`,`caption`,`active`,`created_at`) VALUES (?,?,?,1,?)");
                if($stmt){ $stmt->bind_param('issi',$cid,$fileId,$caption,$now); $stmt->execute(); $stmt->close(); }
                setUser('none','step'); sendMessage('✅ تصویر گزارش با موفقیت ذخیره شد.'); charityAdminRenderProofs(); exit;
            }
        }
        sendMessage('❌ لطفاً فقط عکس ارسال کنید.'); exit;
    }
    if(preg_match('/^charityAdminProofView_(\d+)$/',$charityData,$m)){ charitySendProof((int)$m[1],true); exit; }
    if(preg_match('/^charityAdminDeleteProof_(\d+)$/',$charityData,$m)){
        $id=(int)$m[1]; $stmt=$connection->prepare("UPDATE `charity_proofs` SET `active`=0 WHERE `id`=?");
        if($stmt){ $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); }
        alert('تصویر حذف شد.'); charityAdminRenderProofs(); exit;
    }
}
