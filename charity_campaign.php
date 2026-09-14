<?php
/**
 * Seven-day school-supplies charity campaign.
 *
 * - Starts automatically once, on the first mother-bot request after this file is deployed.
 * - Runs for exactly 7 * 24 hours.
 * - Adds 10% of every successful monetary row in `pays` created during the campaign.
 * - Allows users to donate an arbitrary amount directly from their wallet (100% counted).
 * - Payment rows are idempotent by (campaign_id, pay_id), direct donations by direct_token.
 */

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

        // This repository belongs to this bot, so an empty table means this campaign
        // has never been started. Do not auto-create a second campaign after it ends.
        $res = $connection->query("SELECT COUNT(*) AS c FROM `charity_campaigns`");
        $count = 0;
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        if ($count === 0) {
            $start = time();
            $end = $start + (7 * 24 * 60 * 60);
            $title = 'کمپین مهر مهربانی';
            $description = 'تهیه لوازم مدرسه برای دانش‌آموزانی که امکان خرید وسایل موردنیازشان را ندارند.';
            $percent = 10.00;
            $stmt = $connection->prepare("INSERT INTO `charity_campaigns` (`title`,`description`,`percent`,`starts_at`,`ends_at`,`active`,`created_at`) VALUES (?,?,?,?,?,1,?)");
            if ($stmt) {
                $stmt->bind_param('ssdiii', $title, $description, $percent, $start, $end, $start);
                $stmt->execute();
                $stmt->close();
            }
        }

        charityEnsureMainButton();
        return true;
    }
}

if (!function_exists('charityEnsureMainButton')) {
    function charityEnsureMainButton(){
        global $connection;
        if (!$connection || $connection->connect_error) return 0;
        $type = 'MAIN_BUTTONS🎒 هزینه جمع‌آوری‌شده';
        $stmt = $connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? ORDER BY `id` ASC LIMIT 1");
        if (!$stmt) return 0;
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) return (int)$row['id'];

        $value = "🎒 کمپین تهیه لوازم مدرسه\n\nبرای مشاهده مبلغ جمع‌آوری‌شده و کمک مستقیم روی همین دکمه بزنید.";
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
        if (!$stmt) return 0;
        $stmt->bind_param('ss', $type, $value);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('charityMainButtonId')) {
    function charityMainButtonId(){
        return charityEnsureMainButton();
    }
}

if (!function_exists('charityGetCampaign')) {
    function charityGetCampaign(){
        global $connection;
        $res = $connection->query("SELECT * FROM `charity_campaigns` ORDER BY `id` DESC LIMIT 1");
        return $res ? $res->fetch_assoc() : null;
    }
}

if (!function_exists('charityCampaignIsOpen')) {
    function charityCampaignIsOpen($campaign){
        if (!is_array($campaign)) return false;
        $now = time();
        return ((int)($campaign['active'] ?? 0) === 1 && $now >= (int)$campaign['starts_at'] && $now < (int)$campaign['ends_at']);
    }
}

if (!function_exists('charitySyncCompletedPayments')) {
    function charitySyncCompletedPayments($campaign = null){
        global $connection;
        if (!is_array($campaign)) $campaign = charityGetCampaign();
        if (!is_array($campaign)) return;

        $campaignId = (int)$campaign['id'];
        $start = (int)$campaign['starts_at'];
        $end = (int)$campaign['ends_at'];
        $percent = (float)$campaign['percent'];
        if ($campaignId <= 0 || $end <= $start || $percent <= 0) return;

        // Monetary success states only. Quota-only renewals are intentionally excluded
        // because no money was paid for those rows.
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

if (!function_exists('charityTotals')) {
    function charityTotals($campaign){
        global $connection;
        charitySyncCompletedPayments($campaign);
        $campaignId = (int)($campaign['id'] ?? 0);
        $out = [
            'total' => 0,
            'transactions' => 0,
            'direct' => 0,
            'participants' => 0,
            'transaction_count' => 0,
            'direct_count' => 0,
        ];
        if ($campaignId <= 0) return $out;
        $stmt = $connection->prepare("SELECT
            COALESCE(SUM(`amount`),0) AS total,
            COALESCE(SUM(CASE WHEN `source`='transaction' THEN `amount` ELSE 0 END),0) AS transactions,
            COALESCE(SUM(CASE WHEN `source`='direct' THEN `amount` ELSE 0 END),0) AS direct,
            COUNT(DISTINCT CASE WHEN `user_id`>0 THEN `user_id` END) AS participants,
            SUM(CASE WHEN `source`='transaction' THEN 1 ELSE 0 END) AS transaction_count,
            SUM(CASE WHEN `source`='direct' THEN 1 ELSE 0 END) AS direct_count
            FROM `charity_contributions`
            WHERE `campaign_id`=? AND `status`='confirmed'");
        if (!$stmt) return $out;
        $stmt->bind_param('i', $campaignId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            foreach ($out as $k => $v) $out[$k] = (int)($row[$k] ?? 0);
        }
        return $out;
    }
}

if (!function_exists('charityRemainingText')) {
    function charityRemainingText($seconds){
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return $days . ' روز و ' . $hours . ' ساعت و ' . $minutes . ' دقیقه و ' . $secs . ' ثانیه';
    }
}

if (!function_exists('charityRenderCampaign')) {
    function charityRenderCampaign(){
        global $message_id, $buttonValues;
        $campaign = charityGetCampaign();
        if (!$campaign) {
            smartSendOrEdit($message_id, 'کمپین در دسترس نیست.', json_encode(['inline_keyboard'=>[[['text'=>'🏠 صفحه اصلی','callback_data'=>'mainMenu']]]], JSON_UNESCAPED_UNICODE));
            return;
        }
        $totals = charityTotals($campaign);
        $now = time();
        $start = (int)$campaign['starts_at'];
        $end = (int)$campaign['ends_at'];
        $open = charityCampaignIsOpen($campaign);

        if ($now < $start) {
            $timeLabel = '⏳ زمان تا شروع: <b>' . charityRemainingText($start - $now) . '</b>';
            $status = '🕒 این کمپین هنوز شروع نشده است.';
        } elseif ($open) {
            $timeLabel = '⏳ زمان باقی‌مانده: <b>' . charityRemainingText($end - $now) . '</b>';
            $status = '🟢 کمپین در حال اجراست.';
        } else {
            $timeLabel = '⏳ زمان باقی‌مانده: <b>۰ روز و ۰ ساعت و ۰ دقیقه و ۰ ثانیه</b>';
            $status = '✅ این کمپین به پایان رسیده است.';
        }

        $percentText = rtrim(rtrim(number_format((float)$campaign['percent'], 2, '.', ''), '0'), '.');
        $text = "🎒 <b>" . htmlspecialchars((string)$campaign['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n\n"
              . htmlspecialchars((string)$campaign['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n"
              . "🤝 از هر تراکنش مالی موفق در ربات، <b>{$percentText}٪</b> مبلغ برای این کمپین ثبت می‌شود.\n"
              . "❤️ کمک مستقیم کاربران هم <b>۱۰۰٪</b> به صندوق اضافه می‌شود.\n\n"
              . "💰 <b>مبلغ جمع‌آوری‌شده تا این لحظه:</b>\n"
              . "<b>" . number_format($totals['total']) . " تومان</b>\n\n"
              . "🛒 سهم {$percentText}٪ خرید/تمدید/افزایش/شارژ: " . number_format($totals['transactions']) . " تومان\n"
              . "❤️ کمک مستقیم: " . number_format($totals['direct']) . " تومان\n"
              . "👥 تعداد مشارکت‌کنندگان: " . number_format($totals['participants']) . " نفر\n\n"
              . $timeLabel . "\n"
              . $status . "\n\n"
              . "🔄 برای دیدن مبلغ و زمان دقیق همین لحظه، دکمه بروزرسانی را بزنید.";

        $rows = [];
        if ($open) {
            $rows[] = [['text'=>'❤️ کمک مستقیم به کمپین','callback_data'=>'charityDonate']];
        }
        $rows[] = [['text'=>'🔄 بروزرسانی مبلغ و زمان','callback_data'=>'charityCampaign']];
        $rows[] = [['text'=>$buttonValues['back_to_main'] ?? '🏠 صفحه اصلی','callback_data'=>'mainMenu']];
        smartSendOrEdit($message_id, $text, json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'HTML');
    }
}

if (!function_exists('charityRenderDonateMenu')) {
    function charityRenderDonateMenu(){
        global $connection, $from_id, $message_id;
        $campaign = charityGetCampaign();
        if (!charityCampaignIsOpen($campaign)) {
            charityRenderCampaign();
            return;
        }
        $wallet = 0;
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid`=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $from_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $wallet = (int)($row['wallet'] ?? 0);
        }
        $text = "❤️ <b>کمک مستقیم به کمپین</b>\n\n"
              . "مبلغ کمک مستقیم به‌طور کامل به جمع کمپین اضافه می‌شود.\n\n"
              . "💰 موجودی فعلی شما: <b>" . number_format($wallet) . " تومان</b>\n\n"
              . "یکی از مبلغ‌ها را انتخاب کنید یا مبلغ دلخواه وارد کنید:";
        $rows = [
            [
                ['text'=>'۵۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_50000'],
                ['text'=>'۱۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_100000'],
            ],
            [
                ['text'=>'۲۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_200000'],
                ['text'=>'۵۰۰,۰۰۰ تومان','callback_data'=>'charityDonateAmount_500000'],
            ],
            [['text'=>'✍️ مبلغ دلخواه','callback_data'=>'charityDonateCustom']],
            [['text'=>'💳 افزایش موجودی','callback_data'=>'increaseMyWallet']],
            [['text'=>'↩️ بازگشت به کمپین','callback_data'=>'charityCampaign']],
        ];
        smartSendOrEdit($message_id, $text, json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'HTML');
    }
}

if (!function_exists('charityCreateDonationConfirmation')) {
    function charityCreateDonationConfirmation($amount){
        global $connection, $from_id, $message_id;
        $amount = (int)$amount;
        $campaign = charityGetCampaign();
        if (!charityCampaignIsOpen($campaign)) {
            charityRenderCampaign();
            return;
        }
        if ($amount < 1000 || $amount > 2000000000) {
            alert('مبلغ کمک معتبر نیست.', true);
            return;
        }
        $wallet = 0;
        $stmt = $connection->prepare("SELECT `wallet` FROM `users` WHERE `userid`=? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $from_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $wallet = (int)($row['wallet'] ?? 0);
        }
        if ($wallet < $amount) {
            $short = $amount - $wallet;
            $text = "❌ <b>موجودی شما برای این کمک کافی نیست.</b>\n\n"
                  . "❤️ مبلغ انتخابی: " . number_format($amount) . " تومان\n"
                  . "💰 موجودی شما: " . number_format($wallet) . " تومان\n"
                  . "➖ کسری موجودی: " . number_format($short) . " تومان";
            $keys = ['inline_keyboard'=>[
                [['text'=>'💳 افزایش موجودی','callback_data'=>'increaseMyWallet']],
                [['text'=>'↩️ انتخاب مبلغ دیگر','callback_data'=>'charityDonate']],
            ]];
            smartSendOrEdit($message_id, $text, json_encode($keys, JSON_UNESCAPED_UNICODE), 'HTML');
            return;
        }

        try {
            $token = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $token = substr(sha1(uniqid((string)$from_id, true)), 0, 12);
        }
        $payload = json_encode(['charity_token'=>$token, 'amount'=>$amount, 'campaign_id'=>(int)$campaign['id']], JSON_UNESCAPED_UNICODE);
        setUser($payload, 'temp');
        setUser('none', 'step');

        $after = $wallet - $amount;
        $text = "❤️ <b>تأیید کمک مستقیم</b>\n\n"
              . "مبلغ کمک: <b>" . number_format($amount) . " تومان</b>\n"
              . "موجودی فعلی: " . number_format($wallet) . " تومان\n"
              . "موجودی بعد از کمک: " . number_format($after) . " تومان\n\n"
              . "با تأیید، مبلغ از کیف پول شما کسر و کامل به جمع کمپین اضافه می‌شود.";
        $keys = ['inline_keyboard'=>[
            [['text'=>'✅ تأیید و کمک','callback_data'=>'charityDonateConfirm_'.$token]],
            [['text'=>'❌ انصراف','callback_data'=>'charityDonate']],
        ]];
        smartSendOrEdit($message_id, $text, json_encode($keys, JSON_UNESCAPED_UNICODE), 'HTML');
    }
}

if (!function_exists('charityNormalizeAmountText')) {
    function charityNormalizeAmountText($value){
        $value = (string)$value;
        $value = strtr($value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        ]);
        $value = str_replace([',','٬','،',' ','تومان','تومن'], '', $value);
        return preg_match('/^\d+$/', $value) ? (int)$value : 0;
    }
}

if (!function_exists('charityFinalizeDirectDonation')) {
    function charityFinalizeDirectDonation($token){
        global $connection, $from_id, $userInfo, $message_id;
        $token = preg_replace('/[^a-f0-9]/i', '', (string)$token);
        $payload = json_decode((string)($userInfo['temp'] ?? ''), true);
        if (!is_array($payload) || ($payload['charity_token'] ?? '') !== $token) {
            alert('این درخواست منقضی شده یا قبلاً استفاده شده است.', true);
            charityRenderCampaign();
            return;
        }
        $amount = (int)($payload['amount'] ?? 0);
        $campaignId = (int)($payload['campaign_id'] ?? 0);
        $campaign = charityGetCampaign();
        if (!$campaign || (int)$campaign['id'] !== $campaignId || !charityCampaignIsOpen($campaign)) {
            setUser('', 'temp');
            charityRenderCampaign();
            return;
        }
        if ($amount < 1000) {
            setUser('', 'temp');
            alert('مبلغ کمک معتبر نیست.', true);
            return;
        }

        $now = time();
        $stmt = $connection->prepare("INSERT IGNORE INTO `charity_contributions`
            (`campaign_id`,`pay_id`,`user_id`,`source`,`base_amount`,`amount`,`created_at`,`status`,`direct_token`)
            VALUES (?,NULL,?,'direct',?,?,?,'pending',?)");
        if (!$stmt) {
            alert('خطا در ثبت کمک. دوباره تلاش کنید.', true);
            return;
        }
        $stmt->bind_param('iiiiis', $campaignId, $from_id, $amount, $amount, $now, $token);
        $stmt->execute();
        $inserted = ($stmt->affected_rows === 1);
        $stmt->close();

        if (!$inserted) {
            $stmt = $connection->prepare("SELECT `status`,`amount` FROM `charity_contributions` WHERE `direct_token`=? LIMIT 1");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $old = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            setUser('', 'temp');
            if (($old['status'] ?? '') === 'confirmed') {
                alert('این کمک قبلاً با موفقیت ثبت شده است.');
                charityRenderCampaign();
                return;
            }
            alert('این درخواست قابل استفاده نیست. مبلغ دیگری انتخاب کنید.', true);
            charityRenderDonateMenu();
            return;
        }

        // users is MyISAM in this project; use one conditional UPDATE so wallet
        // deduction itself is atomic even without a DB transaction.
        $stmt = $connection->prepare("UPDATE `users` SET `wallet`=`wallet`-? WHERE `userid`=? AND `wallet`>=?");
        $stmt->bind_param('iii', $amount, $from_id, $amount);
        $stmt->execute();
        $deducted = ($stmt->affected_rows === 1);
        $stmt->close();

        if (!$deducted) {
            $stmt = $connection->prepare("UPDATE `charity_contributions` SET `status`='failed' WHERE `direct_token`=? AND `status`='pending'");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
            setUser('', 'temp');
            alert('موجودی کیف پول کافی نیست.', true);
            charityRenderDonateMenu();
            return;
        }

        $stmt = $connection->prepare("UPDATE `charity_contributions` SET `status`='confirmed' WHERE `direct_token`=? AND `status`='pending'");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
        setUser('', 'temp');
        setUser('none', 'step');

        $totals = charityTotals($campaign);
        $text = "✅ <b>کمک شما با موفقیت ثبت شد.</b>\n\n"
              . "❤️ مبلغ کمک شما: <b>" . number_format($amount) . " تومان</b>\n"
              . "💰 جمع کل کمپین تا این لحظه: <b>" . number_format($totals['total']) . " تومان</b>\n\n"
              . "ممنون که در تهیه وسایل مدرسه دانش‌آموزان سهیم شدید. 🌱";
        $keys = ['inline_keyboard'=>[
            [['text'=>'🎒 مشاهده صندوق کمپین','callback_data'=>'charityCampaign']],
            [['text'=>'🏠 صفحه اصلی','callback_data'=>'mainMenu']],
        ]];
        smartSendOrEdit($message_id, $text, json_encode($keys, JSON_UNESCAPED_UNICODE), 'HTML');
    }
}

// Mother bot only: reseller/child bots do not get this campaign button or accounting UI.
if (empty($isChildBot)) {
    charityEnsureInfrastructure();

    $charityData = isset($data) ? (string)$data : '';
    $charityButtonId = charityMainButtonId();

    if ($charityButtonId > 0 && preg_match('/^showMainButtonAns(\d+)$/', $charityData, $m) && (int)$m[1] === $charityButtonId) {
        setUser('none', 'step');
        setUser('', 'temp');
        charityRenderCampaign();
        exit;
    }
    if ($charityData === 'charityCampaign') {
        setUser('none', 'step');
        setUser('', 'temp');
        charityRenderCampaign();
        exit;
    }
    if ($charityData === 'charityDonate') {
        setUser('none', 'step');
        setUser('', 'temp');
        charityRenderDonateMenu();
        exit;
    }
    if (preg_match('/^charityDonateAmount_(\d+)$/', $charityData, $m)) {
        charityCreateDonationConfirmation((int)$m[1]);
        exit;
    }
    if ($charityData === 'charityDonateCustom') {
        $campaign = charityGetCampaign();
        if (!charityCampaignIsOpen($campaign)) {
            charityRenderCampaign();
            exit;
        }
        setUser('charity_donation_amount', 'step');
        setUser('', 'temp');
        sendMessage("✍️ مبلغ دلخواه کمک را به تومان وارد کنید.\n\nمثال: <code>150000</code>\nحداقل مبلغ: ۱,۰۰۰ تومان", json_encode(['inline_keyboard'=>[[['text'=>'↩️ انصراف','callback_data'=>'charityDonate']]]], JSON_UNESCAPED_UNICODE), 'HTML');
        exit;
    }
    if (($userInfo['step'] ?? '') === 'charity_donation_amount' && isset($update->message)) {
        $amount = charityNormalizeAmountText($text ?? '');
        if ($amount < 1000) {
            sendMessage("❌ مبلغ معتبر نیست. فقط مبلغ به تومان وارد کنید.\nمثال: <code>150000</code>", null, 'HTML');
            exit;
        }
        charityCreateDonationConfirmation($amount);
        exit;
    }
    if (preg_match('/^charityDonateConfirm_([a-f0-9]{8,32})$/i', $charityData, $m)) {
        charityFinalizeDirectDonation($m[1]);
        exit;
    }
}
