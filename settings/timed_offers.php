<?php

// Timed offers / limited sales.
// This file contains only schema and reusable helpers. Telegram handlers live in
// settings/timed_offers_bot.php so payment endpoints can safely reuse the stock logic.

if(!function_exists('timedOfferDbKey')){
    function timedOfferDbKey(){
        global $connection;
        if(!$connection || $connection->connect_error) return '';
        $res = $connection->query("SELECT DATABASE() AS db");
        if(!$res) return '';
        return (string)($res->fetch_assoc()['db'] ?? '');
    }
}

if(!function_exists('ensureTimedOffersSchema')){
    function ensureTimedOffersSchema(){
        global $connection;
        static $checked = [];
        if(!$connection || $connection->connect_error) return false;
        $db = timedOfferDbKey();
        if($db !== '' && isset($checked[$db])) return true;

        $ok1 = $connection->query("CREATE TABLE IF NOT EXISTS `timed_offers` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `kind` VARCHAR(20) NOT NULL DEFAULT 'special',
            `plan_id` INT NOT NULL,
            `title` VARCHAR(160) NOT NULL,
            `note` TEXT NULL,
            `discount_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `start_at` INT NOT NULL,
            `end_at` INT NOT NULL,
            `stock_total` INT NULL DEFAULT NULL,
            `sold_count` INT NOT NULL DEFAULT 0,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_by` BIGINT NOT NULL DEFAULT 0,
            `created_at` INT NOT NULL DEFAULT 0,
            `updated_at` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `timed_offer_public` (`status`,`start_at`,`end_at`),
            KEY `timed_offer_plan` (`plan_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

        $ok2 = $connection->query("CREATE TABLE IF NOT EXISTS `timed_offer_redemptions` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `offer_id` INT NOT NULL,
            `pay_id` INT NOT NULL DEFAULT 0,
            `pay_hash` VARCHAR(191) NOT NULL,
            `user_id` BIGINT NOT NULL DEFAULT 0,
            `quantity` INT NOT NULL DEFAULT 1,
            `state` VARCHAR(20) NOT NULL DEFAULT 'reserved',
            `reserved_at` INT NOT NULL DEFAULT 0,
            `reservation_expires_at` INT NOT NULL DEFAULT 0,
            `completed_at` INT NOT NULL DEFAULT 0,
            `cancelled_at` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `timed_offer_pay_hash` (`pay_hash`),
            KEY `timed_offer_reservation` (`offer_id`,`state`,`reservation_expires_at`),
            KEY `timed_offer_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci");

        $paysExists = false;
        $q = $connection->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pays'");
        if($q) $paysExists = (int)($q->fetch_assoc()['c'] ?? 0) > 0;
        if($paysExists && function_exists('addColumnIfMissing')){
            addColumnIfMissing('pays', 'offer_id', '`offer_id` INT NOT NULL DEFAULT 0');
        }

        $paysReady = !$paysExists;
        if($paysExists){
            $q = $connection->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pays' AND COLUMN_NAME='offer_id'");
            $paysReady = $q && (int)($q->fetch_assoc()['c'] ?? 0) > 0;
        }
        $ready = (bool)($ok1 && $ok2 && $paysReady);
        if($db !== '' && $ready) $checked[$db] = true;
        return $ready;
    }
}

if(!function_exists('timedOfferMaintenance')){
    function timedOfferMaintenance($force = false){
        global $connection;
        static $done = [];
        if(!ensureTimedOffersSchema()) return;
        $db = timedOfferDbKey();
        if(!$force && isset($done[$db])) return;
        $done[$db] = true;
        $now = time();

        // Expired reservations and invoices removed/rejected elsewhere release stock.
        $sql = "UPDATE `timed_offer_redemptions` r
                LEFT JOIN `pays` p ON p.`hash_id`=r.`pay_hash`
                SET r.`state`='cancelled', r.`cancelled_at`={$now}
                WHERE r.`state`='reserved'
                  AND (r.`reservation_expires_at`<={$now}
                       OR p.`id` IS NULL
                       OR p.`state` IN ('canceled','cancelled','declined','low_payment','partially_paid','partiallyPaied'))";
        @$connection->query($sql);
        @$connection->query("UPDATE `timed_offers` SET `status`='expired',`updated_at`={$now} WHERE `status`='active' AND `end_at`<={$now}");
        @$connection->query("UPDATE `timed_offers` SET `status`='sold_out',`updated_at`={$now} WHERE `status`='active' AND `stock_total` IS NOT NULL AND `sold_count`>=`stock_total`");
    }
}

if(!function_exists('timedOfferDecorateRow')){
    function timedOfferDecorateRow($row){
        if(!is_array($row)) return null;
        $stock = array_key_exists('stock_total', $row) && $row['stock_total'] !== null ? (int)$row['stock_total'] : null;
        $sold = max(0, (int)($row['sold_count'] ?? 0));
        $reserved = max(0, (int)($row['reserved_count'] ?? 0));
        $row['stock_total'] = $stock;
        $row['sold_count'] = $sold;
        $row['reserved_count'] = $reserved;
        $row['available_count'] = $stock === null ? null : max(0, $stock - $sold - $reserved);
        $row['discounted_price'] = timedOfferDiscountedPrice((int)($row['plan_price'] ?? 0), (int)($row['discount_percent'] ?? 0));
        return $row;
    }
}

if(!function_exists('timedOfferFetchRows')){
    function timedOfferFetchRows($publicOnly = false, $offerId = 0, $limit = 50){
        global $connection;
        if(!ensureTimedOffersSchema()) return [];
        timedOfferMaintenance();
        $offerId = (int)$offerId;
        $limit = max(1, min(100, (int)$limit));
        $now = time();
        $where = [];
        if($offerId > 0) $where[] = "o.`id`={$offerId}";
        if($publicOnly){
            $where[] = "o.`status`='active'";
            $where[] = "o.`start_at`<={$now}";
            $where[] = "o.`end_at`>{$now}";
            $where[] = "p.`active`=1";
            $where[] = "(o.`stock_total` IS NULL OR o.`sold_count`<o.`stock_total`)";
        }else{
            $where[] = "o.`status`<>'deleted'";
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT o.*,
                       p.`title` AS plan_title,p.`price` AS plan_price,p.`volume` AS plan_volume,
                       p.`days` AS plan_days,p.`catid`,p.`server_id`,p.`active` AS plan_active,
                       COALESCE(si.`title`,si.`remark`,CONCAT('Server #',p.`server_id`)) AS server_title,
                       (SELECT COALESCE(SUM(r.`quantity`),0)
                          FROM `timed_offer_redemptions` r
                         WHERE r.`offer_id`=o.`id` AND r.`state`='reserved'
                           AND r.`reservation_expires_at`>{$now}) AS reserved_count
                  FROM `timed_offers` o
                  LEFT JOIN `server_plans` p ON p.`id`=o.`plan_id`
                  LEFT JOIN `server_info` si ON si.`id`=p.`server_id`
                  {$whereSql}
                 ORDER BY o.`start_at` DESC,o.`id` DESC
                 LIMIT {$limit}";
        $res = $connection->query($sql);
        $rows = [];
        if($res){
            while($row = $res->fetch_assoc()) $rows[] = timedOfferDecorateRow($row);
        }
        return $rows;
    }
}

if(!function_exists('timedOfferGetActive')){
    function timedOfferGetActive($offerId){
        $rows = timedOfferFetchRows(true, (int)$offerId, 1);
        return $rows ? $rows[0] : null;
    }
}

if(!function_exists('timedOfferGetActiveList')){
    function timedOfferGetActiveList($limit = 20){
        return timedOfferFetchRows(true, 0, $limit);
    }
}

if(!function_exists('timedOfferGetAdmin')){
    function timedOfferGetAdmin($offerId){
        $rows = timedOfferFetchRows(false, (int)$offerId, 1);
        return $rows ? $rows[0] : null;
    }
}

if(!function_exists('timedOfferHasActive')){
    function timedOfferHasActive(){
        global $connection;
        if(!ensureTimedOffersSchema()) return false;
        timedOfferMaintenance();
        $now = time();
        $q = $connection->query("SELECT 1 FROM `timed_offers` o INNER JOIN `server_plans` p ON p.`id`=o.`plan_id` WHERE o.`status`='active' AND o.`start_at`<={$now} AND o.`end_at`>{$now} AND p.`active`=1 AND (o.`stock_total` IS NULL OR o.`sold_count`<o.`stock_total`) LIMIT 1");
        return $q && $q->num_rows > 0;
    }
}

if(!function_exists('timedOfferDiscountedPrice')){
    function timedOfferDiscountedPrice($basePrice, $percent){
        $basePrice = max(0, (int)$basePrice);
        $percent = max(0, min(99, (int)$percent));
        return max(0, $basePrice - (int)floor($basePrice * $percent / 100));
    }
}

if(!function_exists('timedOfferIdFromBuyType')){
    function timedOfferIdFromBuyType($buyType){
        return preg_match('/^offer(\d+)$/', (string)$buyType, $m) ? (int)$m[1] : 0;
    }
}

if(!function_exists('timedOfferNormalizeDigits')){
    function timedOfferNormalizeDigits($value){
        return strtr((string)$value, [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'
        ]);
    }
}

if(!function_exists('timedOfferParseAdminDate')){
    function timedOfferParseAdminDate($input, &$error = null){
        $error = '';
        $input = trim(timedOfferNormalizeDigits($input));
        $lower = function_exists('mb_strtolower') ? mb_strtolower($input, 'UTF-8') : strtolower($input);
        if(in_array($lower, ['الان','اکنون','now'], true)) return time();
        if(ctype_digit($input) && (int)$input > 1000000000) return (int)$input;
        $input = str_replace(['T','  '], [' ',' '], $input);
        if(!preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})\s+(\d{1,2}):(\d{2})$/', $input, $m)){
            $error = 'فرمت درست نیست. نمونه: 1405/05/28 23:59 یا 2026-08-19 23:59';
            return false;
        }
        $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3]; $hour=(int)$m[4]; $minute=(int)$m[5];
        if($month<1 || $month>12 || $day<1 || $day>31 || $hour>23 || $minute>59){
            $error = 'تاریخ یا ساعت واردشده معتبر نیست.';
            return false;
        }
        if($year < 1700){
            if(!function_exists('jmktime')){
                $error = 'تبدیل تاریخ شمسی روی سرور در دسترس نیست؛ تاریخ میلادی وارد کنید.';
                return false;
            }
            if(function_exists('jcheckdate') && !jcheckdate($month,$day,$year)){
                $error = 'تاریخ شمسی معتبر نیست.';
                return false;
            }
            $ts = jmktime($hour,$minute,0,$month,$day,$year,'','Asia/Tehran');
            if(!$ts){ $error='تاریخ شمسی معتبر نیست.'; return false; }
            return (int)$ts;
        }
        $tz = new DateTimeZone('Asia/Tehran');
        $normalized = sprintf('%04d-%02d-%02d %02d:%02d',$year,$month,$day,$hour,$minute);
        $dt = DateTime::createFromFormat('!Y-m-d H:i', $normalized, $tz);
        $errors = DateTime::getLastErrors();
        if(!$dt || (is_array($errors) && (($errors['warning_count'] ?? 0)>0 || ($errors['error_count'] ?? 0)>0))){
            $error = 'تاریخ میلادی معتبر نیست.';
            return false;
        }
        return $dt->getTimestamp();
    }
}

if(!function_exists('timedOfferFormatDate')){
    function timedOfferFormatDate($timestamp){
        $timestamp = (int)$timestamp;
        return $timestamp > 0 && function_exists('jdate') ? jdate('Y/m/d H:i', $timestamp) : date('Y/m/d H:i', $timestamp);
    }
}

if(!function_exists('timedOfferCountdown')){
    function timedOfferCountdown($seconds){
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        $clock = sprintf('%02d:%02d:%02d',$hours,$minutes,$secs);
        return $days > 0 ? ($days . ' روز و ' . $clock) : $clock;
    }
}

if(!function_exists('timedOfferStatusLabel')){
    function timedOfferStatusLabel($offer){
        $status = (string)($offer['status'] ?? '');
        $now = time();
        if($status === 'disabled') return '⏸ متوقف';
        if($status === 'deleted') return '🗑 حذف‌شده';
        if($status === 'sold_out') return '⛔️ اتمام موجودی';
        if($status === 'expired' || (int)($offer['end_at'] ?? 0) <= $now) return '⌛️ منقضی';
        if((int)($offer['start_at'] ?? 0) > $now) return '🗓 زمان‌بندی‌شده';
        return '🟢 فعال';
    }
}

if(!function_exists('timedOfferEsc')){
    function timedOfferEsc($value){
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if(!function_exists('timedOfferPublicText')){
    function timedOfferPublicText($offer){
        $kind = ($offer['kind'] ?? 'special') === 'limited' ? '⏱ <b>فروش محدود</b>' : '🔥 <b>پیشنهاد ویژه امروز</b>';
        $base = (int)($offer['plan_price'] ?? 0);
        $final = (int)($offer['discounted_price'] ?? timedOfferDiscountedPrice($base,(int)($offer['discount_percent'] ?? 0)));
        $lines = [$kind,'','🎁 <b>'.timedOfferEsc($offer['title'] ?? '').'</b>'];
        $lines[] = '📦 پلن: <b>'.timedOfferEsc($offer['plan_title'] ?? '').'</b>';
        $lines[] = '🔋 حجم: <b>'.timedOfferEsc($offer['plan_volume'] ?? 0).' گیگ</b>';
        $lines[] = '📅 مدت: <b>'.timedOfferEsc($offer['plan_days'] ?? 0).' روز</b>';
        $lines[] = '🎯 تخفیف: <b>'.(int)($offer['discount_percent'] ?? 0).'٪</b>';
        $lines[] = '💰 قیمت اصلی: <s>'.number_format($base).' تومان</s>';
        $lines[] = '💎 قیمت پیشنهاد: <b>'.number_format($final).' تومان</b>';
        if(trim((string)($offer['note'] ?? '')) !== '') $lines[] = '📝 '.timedOfferEsc($offer['note']);
        $lines[] = '';
        $lines[] = '⏳ زمان باقی‌مانده: <code>'.timedOfferCountdown((int)$offer['end_at']-time()).'</code>';
        $lines[] = '🕚 پایان: <code>'.timedOfferFormatDate($offer['end_at']).'</code>';
        if($offer['stock_total'] !== null){
            $lines[] = '📣 فقط <b>'.(int)$offer['available_count'].' عدد</b> قابل خرید باقی مانده';
            $lines[] = '✅ خرید واقعی: <b>'.(int)$offer['sold_count'].'</b> عدد';
        }
        return implode("\n",$lines);
    }
}

if(!function_exists('timedOfferAdminText')){
    function timedOfferAdminText($offer){
        $kind = ($offer['kind'] ?? '') === 'limited' ? 'فروش محدود' : 'پیشنهاد ویژه';
        $stock = $offer['stock_total'] === null ? 'نامحدود' : ((int)$offer['stock_total'].' عدد');
        $available = $offer['available_count'] === null ? 'نامحدود' : ((int)$offer['available_count'].' عدد');
        return "🔥 <b>مدیریت پیشنهاد #".(int)$offer['id']."</b>\n\n".
            "نوع: <b>{$kind}</b>\n".
            "وضعیت: <b>".timedOfferStatusLabel($offer)."</b>\n".
            "عنوان: <b>".timedOfferEsc($offer['title'])."</b>\n".
            "پلن: <b>".timedOfferEsc($offer['plan_title'] ?? ('#'.$offer['plan_id']))."</b>\n".
            "تخفیف: <b>".(int)$offer['discount_percent']."٪</b>\n".
            "شروع: <code>".timedOfferFormatDate($offer['start_at'])."</code>\n".
            "پایان: <code>".timedOfferFormatDate($offer['end_at'])."</code>\n".
            "ظرفیت: <b>{$stock}</b>\n".
            "فروش واقعی: <b>".(int)$offer['sold_count']." عدد</b>\n".
            "رزرو موقت: <b>".(int)$offer['reserved_count']." عدد</b>\n".
            "قابل خرید: <b>{$available}</b>";
    }
}

if(!function_exists('timedOfferReservationCountLocked')){
    function timedOfferReservationCountLocked($offerId, $excludeHash = ''){
        global $connection;
        $offerId=(int)$offerId; $now=time();
        $sql="SELECT COALESCE(SUM(`quantity`),0) AS c FROM `timed_offer_redemptions` WHERE `offer_id`=? AND `state`='reserved' AND `reservation_expires_at`>?";
        if($excludeHash !== '') $sql .= " AND `pay_hash`<>?";
        $stmt=$connection->prepare($sql);
        if($excludeHash !== '') $stmt->bind_param('iis',$offerId,$now,$excludeHash);
        else $stmt->bind_param('ii',$offerId,$now);
        $stmt->execute(); $count=(int)($stmt->get_result()->fetch_assoc()['c'] ?? 0); $stmt->close();
        return $count;
    }
}

if(!function_exists('timedOfferReservePay')){
    function timedOfferReservePay($offerId, $payHash, $payId, $userId, &$error = null, $seconds = 1800){
        global $connection;
        $error=''; $offerId=(int)$offerId; $payId=(int)$payId; $userId=(int)$userId;
        $payHash=substr(trim((string)$payHash),0,191); $seconds=max(300,(int)$seconds);
        if($offerId<=0 || $payHash===''){ $error='اطلاعات رزرو ناقص است.'; return false; }
        if(!ensureTimedOffersSchema()){ $error='جدول‌های پیشنهاد آماده نیست.'; return false; }
        timedOfferMaintenance(true);
        $now=time();
        $connection->begin_transaction();
        try{
            $stmt=$connection->prepare("SELECT * FROM `timed_offers` WHERE `id`=? FOR UPDATE");
            $stmt->bind_param('i',$offerId); $stmt->execute(); $offer=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$offer || $offer['status']!=='active' || (int)$offer['start_at']>$now || (int)$offer['end_at']<=$now) throw new Exception('این پیشنهاد دیگر فعال نیست.');

            $stmt=$connection->prepare("SELECT `id`,`plan_id`,`user_id` FROM `pays` WHERE `hash_id`=? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('s',$payHash); $stmt->execute(); $pay=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$pay || (int)$pay['plan_id']!==(int)$offer['plan_id'] || (int)$pay['user_id']!==$userId) throw new Exception('فاکتور با این پیشنهاد هماهنگ نیست.');
            $payId=(int)$pay['id'];

            $stmt=$connection->prepare("SELECT * FROM `timed_offer_redemptions` WHERE `pay_hash`=? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('s',$payHash); $stmt->execute(); $red=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($red && $red['state']==='completed'){
                $connection->commit();
                return true;
            }

            $reserved=timedOfferReservationCountLocked($offerId,$payHash);
            if($offer['stock_total']!==null && ((int)$offer['sold_count']+$reserved+1)>(int)$offer['stock_total']) throw new Exception('موجودی این فروش محدود تمام شده است.');
            $expires=$now+$seconds;
            if($red){
                $stmt=$connection->prepare("UPDATE `timed_offer_redemptions` SET `offer_id`=?,`pay_id`=?,`user_id`=?,`quantity`=1,`state`='reserved',`reserved_at`=?,`reservation_expires_at`=?,`completed_at`=0,`cancelled_at`=0 WHERE `id`=?");
                $rid=(int)$red['id']; $stmt->bind_param('iiiiii',$offerId,$payId,$userId,$now,$expires,$rid);
            }else{
                $stmt=$connection->prepare("INSERT INTO `timed_offer_redemptions` (`offer_id`,`pay_id`,`pay_hash`,`user_id`,`quantity`,`state`,`reserved_at`,`reservation_expires_at`) VALUES (?,?,?, ?,1,'reserved',?,?)");
                $stmt->bind_param('iisiii',$offerId,$payId,$payHash,$userId,$now,$expires);
            }
            if(!$stmt->execute()) throw new Exception('ثبت رزرو انجام نشد.');
            $stmt->close();
            $stmt=$connection->prepare("UPDATE `pays` SET `offer_id`=? WHERE `id`=?");
            $stmt->bind_param('ii',$offerId,$payId); $stmt->execute(); $stmt->close();
            $connection->commit();
            return true;
        }catch(Throwable $e){
            $connection->rollback(); $error=$e->getMessage();
            return false;
        }
    }
}

if(!function_exists('timedOfferEnsurePayReservation')){
    function timedOfferEnsurePayReservation($payInfo, $seconds = 7200, &$error = null){
        global $connection;
        $error='';
        if(!is_array($payInfo) || (int)($payInfo['offer_id'] ?? 0)<=0) return true;
        if(!ensureTimedOffersSchema()){ $error='جدول‌های پیشنهاد آماده نیست.'; return false; }
        $offerId=(int)$payInfo['offer_id']; $hash=substr((string)$payInfo['hash_id'],0,191); $now=time(); $seconds=max(300,(int)$seconds);
        $connection->begin_transaction();
        try{
            $stmt=$connection->prepare("SELECT * FROM `timed_offers` WHERE `id`=? FOR UPDATE");
            $stmt->bind_param('i',$offerId); $stmt->execute(); $offer=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$offer || (int)$offer['plan_id']!==(int)$payInfo['plan_id']) throw new Exception('پیشنهاد این فاکتور معتبر نیست.');
            $stmt=$connection->prepare("SELECT * FROM `timed_offer_redemptions` WHERE `pay_hash`=? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('s',$hash); $stmt->execute(); $red=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($red && $red['state']==='completed'){ $connection->commit(); return true; }
            $hasLiveReservation=$red && $red['state']==='reserved' && (int)$red['reservation_expires_at']>$now;
            if(!$hasLiveReservation){
                $currentlyActive=$offer['status']==='active' && (int)$offer['start_at']<=$now && (int)$offer['end_at']>$now;
                if(!$currentlyActive) throw new Exception('مهلت این پیشنهاد یا رزرو آن تمام شده است.');
                $reserved=timedOfferReservationCountLocked($offerId,$hash);
                if($offer['stock_total']!==null && ((int)$offer['sold_count']+$reserved+1)>(int)$offer['stock_total']) throw new Exception('موجودی این فروش محدود تمام شده است.');
            }
            $expires=$now+$seconds; $payId=(int)($payInfo['id'] ?? 0); $userId=(int)($payInfo['user_id'] ?? 0);
            if($red){
                $rid=(int)$red['id'];
                $stmt=$connection->prepare("UPDATE `timed_offer_redemptions` SET `state`='reserved',`pay_id`=?,`user_id`=?,`reservation_expires_at`=?,`cancelled_at`=0 WHERE `id`=?");
                $stmt->bind_param('iiii',$payId,$userId,$expires,$rid);
            }else{
                $stmt=$connection->prepare("INSERT INTO `timed_offer_redemptions` (`offer_id`,`pay_id`,`pay_hash`,`user_id`,`quantity`,`state`,`reserved_at`,`reservation_expires_at`) VALUES (?,?,?, ?,1,'reserved',?,?)");
                $stmt->bind_param('iisiii',$offerId,$payId,$hash,$userId,$now,$expires);
            }
            if(!$stmt->execute()) throw new Exception('تمدید رزرو انجام نشد.');
            $stmt->close(); $connection->commit(); return true;
        }catch(Throwable $e){
            $connection->rollback(); $error=$e->getMessage(); return false;
        }
    }
}

if(!function_exists('timedOfferCompletePay')){
    function timedOfferCompletePay($payInfo, &$error = null){
        global $connection;
        $error='';
        if(!is_array($payInfo) || (int)($payInfo['offer_id'] ?? 0)<=0) return true;
        if(!ensureTimedOffersSchema()){ $error='جدول‌های پیشنهاد آماده نیست.'; return false; }
        $offerId=(int)$payInfo['offer_id']; $hash=substr((string)$payInfo['hash_id'],0,191); $now=time();
        $connection->begin_transaction();
        try{
            $stmt=$connection->prepare("SELECT * FROM `timed_offers` WHERE `id`=? FOR UPDATE");
            $stmt->bind_param('i',$offerId); $stmt->execute(); $offer=$stmt->get_result()->fetch_assoc(); $stmt->close();
            $stmt=$connection->prepare("SELECT * FROM `timed_offer_redemptions` WHERE `pay_hash`=? LIMIT 1 FOR UPDATE");
            $stmt->bind_param('s',$hash); $stmt->execute(); $red=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$offer || !$red) throw new Exception('رزرو این خرید پیدا نشد.');
            if($red['state']==='completed'){ $connection->commit(); return true; }
            if($red['state']!=='reserved') throw new Exception('رزرو این خرید لغو شده است.');
            $qty=max(1,(int)$red['quantity']);
            if($offer['stock_total']!==null && ((int)$offer['sold_count']+$qty)>(int)$offer['stock_total']) throw new Exception('ظرفیت فروش محدود تکمیل شده است.');
            $stmt=$connection->prepare("UPDATE `timed_offer_redemptions` SET `state`='completed',`completed_at`=?,`reservation_expires_at`=0 WHERE `id`=?");
            $rid=(int)$red['id']; $stmt->bind_param('ii',$now,$rid); $stmt->execute(); $stmt->close();
            $newSold=(int)$offer['sold_count']+$qty;
            $newStatus=($offer['stock_total']!==null && $newSold>=(int)$offer['stock_total'])?'sold_out':$offer['status'];
            $stmt=$connection->prepare("UPDATE `timed_offers` SET `sold_count`=?,`status`=?,`updated_at`=? WHERE `id`=?");
            $stmt->bind_param('isii',$newSold,$newStatus,$now,$offerId); $stmt->execute(); $stmt->close();
            $connection->commit(); return true;
        }catch(Throwable $e){
            $connection->rollback(); $error=$e->getMessage(); return false;
        }
    }
}

if(!function_exists('timedOfferReleasePay')){
    function timedOfferReleasePay($payOrHash){
        global $connection;
        if(!ensureTimedOffersSchema()) return false;
        $hash=is_array($payOrHash)?(string)($payOrHash['hash_id'] ?? ''):(string)$payOrHash;
        $hash=substr(trim($hash),0,191); if($hash==='') return false;
        $now=time();
        $stmt=$connection->prepare("UPDATE `timed_offer_redemptions` SET `state`='cancelled',`cancelled_at`=? WHERE `pay_hash`=? AND `state`='reserved'");
        $stmt->bind_param('is',$now,$hash); $ok=$stmt->execute(); $stmt->close();
        return $ok;
    }
}

?>
