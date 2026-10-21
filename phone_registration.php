<?php
/**
 * Optional Rubika phone registration for the existing Telegram bot.
 * Stored per active bot database; no phone verification or Rubika API calls.
 */

function phoneRegTextFields() {
    return [
        'phone' => ['شماره همراه', "لطفاً شماره همراه خود را وارد کنید.\n(شماره شما حتماً دارای اپلیکیشن روبیکا باشد)\n\nفرمت نمونه: 09041234567"],
        'invalid' => ['شماره نامعتبر', "❌ فقط شماره موبایل ۱۱ رقمی با اعداد انگلیسی و شروع 09 وارد کنید.\nمثال: 09041234567"],
        'alias' => ['نام مستعار یا آیدی', 'لطفاً یک اسم مستعار یا آیدی تلگرام خود را ارسال کنید.'],
        'success' => ['ثبت موفق', '✅ شماره شما با موفقیت ثبت شد.'],
        'existing' => ['ثبت قبلی', 'شما قبلاً شماره خود را ثبت کرده‌اید. مشخصات ثبت‌شده شما:'],
        'limit' => ['سقف ثبت', 'حداکثر ۳ شماره می‌توانید ثبت کنید. برای شماره جدید ابتدا یکی را حذف کنید.'],
        'edited' => ['ویرایش موفق', '✅ مشخصات شما با موفقیت ویرایش شد.'],
        'deleted' => ['حذف موفق', '✅ شماره ثبت‌شده حذف شد.'],
        'duplicate' => ['شماره تکراری', '❌ این شماره را قبلاً ثبت کرده‌اید.'],
        'noactive' => ['اشتراک فعال یافت نشد', '❌ شما از ما اشتراک فعالی ندارید و نمی‌توانید شماره ثبت کنید. لطفاً ابتدا یک اشتراک تهیه کنید، سپس برای ثبت شماره اقدام نمایید.'],
        'unavailable' => ['عدم امکان استعلام پنل', '⚠️ فعلاً امکان بررسی وضعیت اشتراک از پنل وجود ندارد. لطفاً کمی بعد دوباره تلاش کنید.']
    ];
}

function phoneRegText($key) {
    $fields = phoneRegTextFields();
    if (!isset($fields[$key])) return '';
    return (string)getSettingValue('PHONE_REG_TEXT_' . $key, $fields[$key][1]);
}

function phoneRegEnabled() {
    global $botState;
    return ($botState['phoneRegistrationState'] ?? 'off') === 'on';
}

function phoneRegEnsureTable() {
    global $connection;
    static $checked = [];
    $dbResult = $connection->query('SELECT DATABASE() AS db');
    $db = (string)($dbResult->fetch_assoc()['db'] ?? '');
    if (isset($checked[$db])) return true;
    $ok = $connection->query("CREATE TABLE IF NOT EXISTS phone_registrations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT NOT NULL,
        phone VARCHAR(11) NOT NULL,
        nickname TEXT NOT NULL,
        order_id BIGINT UNSIGNED NULL DEFAULT NULL,
        created_at BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_user_phone (user_id, phone),
        KEY user_records (user_id, id),
        KEY registered_latest (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$ok) return false;
    // Non-destructive migration: existing numbers remain unchanged (order_id = NULL).
    $column = $connection->query("SHOW COLUMNS FROM phone_registrations LIKE 'order_id'");
    if (!$column) return false;
    $missing = $column->num_rows === 0;
    $column->free();
    if ($missing && !$connection->query('ALTER TABLE phone_registrations ADD COLUMN order_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER nickname')) return false;
    $checked[$db] = true;
    return true;
}

function phoneRegEsc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function phoneRegDate($timestamp) {
    return (new DateTimeImmutable('@'.(int)$timestamp))
        ->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y/m/d H:i:s') . ' (ایران)';
}

function phoneRegRows($uid) {
    global $connection;
    $stmt = $connection->prepare('SELECT * FROM phone_registrations WHERE user_id=? ORDER BY id ASC');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function phoneRegRecord($id, $uid = null) {
    global $connection;
    if ($uid === null) {
        $stmt = $connection->prepare('SELECT * FROM phone_registrations WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $id);
    } else {
        $stmt = $connection->prepare('SELECT * FROM phone_registrations WHERE id=? AND user_id=? LIMIT 1');
        $stmt->bind_param('ii', $id, $uid);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}


/**
 * Reuse the established panel snapshot reader in admin_service_manager.php.
 * status=1 means a provisioned order; it does NOT prove that quota/time remains.
 */
function phoneRegAssessSnapshot($order, $snap, $now = null) {
    $now = $now === null ? time() : (int)$now;
    if (empty($snap['found'])) {
        $storedExpiry = function_exists('deltaSvcTs') ? deltaSvcTs($order['expire_date'] ?? 0) : (int)($order['expire_date'] ?? 0);
        return $storedExpiry > 0 && $storedExpiry <= $now ? 'inactive' : 'unknown';
    }
    if (empty($snap['enabled']) || strtolower((string)($snap['status'] ?? '')) !== 'active') return 'inactive';
    $expire = (int)($snap['expire'] ?? 0);
    if ($expire > 0 && $expire <= $now) return 'inactive';
    $total = max(0, (int)($snap['total'] ?? 0));
    $used = max(0, (int)($snap['used'] ?? 0));
    // Both supported panel families represent unlimited data as 0 bytes.
    if ($total > 0 && $used >= $total) return 'inactive';
    return 'active';
}

function phoneRegCheckOrder($orderId, $userId) {
    global $connection;
    $stmt = $connection->prepare('SELECT * FROM orders_list WHERE id=? AND userid=? AND status=1 LIMIT 1');
    if (!$stmt) throw new RuntimeException('Cannot query user order');
    $stmt->bind_param('ii', $orderId, $userId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) return ['state'=>'inactive'];
    if (!function_exists('deltaSvcSnapshot')) return ['state'=>'unknown'];
    $snap = deltaSvcSnapshot($order);
    $state = phoneRegAssessSnapshot($order, $snap);
    return ['state'=>$state, 'order'=>$order, 'snapshot'=>$snap];
}

function phoneRegLatestActive($userId) {
    global $connection;
    $stmt = $connection->prepare('SELECT id FROM orders_list WHERE userid=? AND status=1 ORDER BY id DESC');
    if (!$stmt) throw new RuntimeException('Cannot query user orders');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $ids = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $ids[] = (int)$row['id'];
    $stmt->close();
    $unknown = false;
    foreach ($ids as $id) {
        $checked = phoneRegCheckOrder($id, $userId);
        if ($checked['state'] === 'active') return $checked;
        if ($checked['state'] === 'unknown') $unknown = true;
    }
    // Panel outages are not evidence of an expired subscription.
    return ['state'=>$unknown ? 'unknown' : 'inactive'];
}

function phoneRegEligibilityError($status) {
    sendMessage(phoneRegText($status === 'unknown' ? 'unavailable' : 'noactive'), null, null);
}

function phoneRegOffer() {
    global $from_id;
    $rows = phoneRegRows((int)$from_id);
    if (count($rows) >= 3) {
        sendMessage(phoneRegText('limit'), phoneRegKeyboard($rows), null);
        return;
    }
    $checked = phoneRegLatestActive((int)$from_id);
    if ($checked['state'] !== 'active') {
        phoneRegEligibilityError($checked['state']);
        return;
    }
    $order = $checked['order'];
    $snap = $checked['snapshot'];
    $total = max(0, (int)($snap['total'] ?? 0));
    $used = max(0, (int)($snap['used'] ?? 0));
    $remain = $total > 0 ? (function_exists('deltaSvcTraffic') ? deltaSvcTraffic(max(0, $total-$used)) : round(max(0, $total-$used)/1073741824, 2).' GB') : '♾️ نامحدود';
    $expiry = (int)($snap['expire'] ?? 0);
    $days = $expiry > 0 ? max(0, (int)ceil(($expiry-time())/86400)).' روز' : '♾️ نامحدود';
    $caption = "✅ اشتراک فعال شما شناسایی شد.\n\n📦 اشتراک: <b>".phoneRegEsc($order['remark'] ?? ('#'.$order['id']))."</b>".
        "\n🟢 وضعیت: فعال\n📊 حجم باقی‌مانده: ".phoneRegEsc($remain).
        "\n📅 اعتبار باقی‌مانده: ".phoneRegEsc($days).
        "\n\nبرای ادامه روی دکمه زیر بزنید.";
    sendMessage($caption, json_encode(['inline_keyboard'=>[
        [['text'=>'ثبت شماره','callback_data'=>'phoneRegConfirm_'.(int)$order['id']]],
        [['text'=>'↩️ بازگشت','callback_data'=>'phoneRegCancel']]
    ]], JSON_UNESCAPED_UNICODE), 'HTML');
}

function phoneRegKeyboard($rows) {
    $keys = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $keys[] = [['text'=>'📱 '.$row['phone'], 'callback_data'=>'phoneRegView_'.$id]];
        $keys[] = [
            ['text'=>'✏️ ویرایش مشخصات', 'callback_data'=>'phoneRegEdit_'.$id],
            ['text'=>'🗑 حذف مشخصات', 'callback_data'=>'phoneRegDeleteAsk_'.$id]
        ];
    }
    if (count($rows) < 3) $keys[] = [['text'=>'➕ ثبت شماره جدید', 'callback_data'=>'phoneRegNew']];
    $keys[] = [['text'=>'↩️ بازگشت به منوی اصلی', 'callback_data'=>'mainMenu']];
    return json_encode(['inline_keyboard'=>$keys], JSON_UNESCAPED_UNICODE);
}

function phoneRegOverview() {
    global $from_id;
    $rows = phoneRegRows((int)$from_id);
    $message = count($rows) ? phoneRegText('existing') : '📱 هنوز شماره‌ای ثبت نکرده‌اید.';
    foreach ($rows as $i => $row) {
        $message .= "\n\n".($i+1).") شماره: ".$row['phone'].
            "\nنام مستعار / آیدی: ".mb_substr($row['nickname'], 0, 600, 'UTF-8').
            "\nزمان ثبت: ".phoneRegDate($row['created_at']);
        if ((int)$row['updated_at'] > (int)$row['created_at'])
            $message .= "\nآخرین ویرایش: ".phoneRegDate($row['updated_at']);
    }
    sendMessage($message, phoneRegKeyboard($rows), null);
}

function phoneRegStart($mode, $id = 0, $orderId = 0) {
    global $from_id;
    $rows = phoneRegRows((int)$from_id);
    if ($mode === 'new' && count($rows) >= 3) {
        sendMessage(phoneRegText('limit'), phoneRegKeyboard($rows), null);
        return;
    }
    if ($mode === 'new' && (int)$orderId <= 0) { phoneRegOffer(); return; }
    if ($mode === 'edit' && !phoneRegRecord($id, (int)$from_id)) {
        sendMessage('❌ این شماره متعلق به شما نیست یا حذف شده است.', null, null);
        return;
    }
    setUser(json_encode(['mode'=>$mode,'id'=>(int)$id,'order_id'=>(int)$orderId], JSON_UNESCAPED_UNICODE), 'temp');
    setUser('phoneRegWaitPhone', 'step');
    sendMessage(phoneRegText('phone'), json_encode(['inline_keyboard'=>[
        [['text'=>'❌ انصراف', 'callback_data'=>'phoneRegCancel']]
    ]], JSON_UNESCAPED_UNICODE), null);
}

function phoneRegCancel() {
    global $userInfo;
    setUser('none', 'step');
    setUser('', 'temp');
    if (is_array($userInfo)) { $userInfo['step'] = 'none'; $userInfo['temp'] = ''; }
}

function phoneRegNotify($action, $old, $new) {
    global $from_id, $first_name, $username;
    $uid = (int)$from_id;
    $name = phoneRegEsc($first_name ?? '');
    $user = phoneRegEsc($username ?? '');
    $body = "📱 <b>گزارش ثبت شماره - ".$action."</b>".
        "\n👤 کاربر: <a href=\"tg://user?id=".$uid."\">".$name."</a>".
        "\n🆔 شناسه تلگرام: <code>".$uid."</code>".
        "\n🔗 آیدی: @".$user.
        "\n🕒 زمان عملیات: ".phoneRegEsc(phoneRegDate(time()));
    if ($old) {
        $body .= "\n\n<b>مشخصات قبلی</b>".
            "\nشماره: <code>".phoneRegEsc($old['phone'])."</code>".
            "\nنام مستعار/آیدی: ".phoneRegEsc(mb_substr($old['nickname'], 0, 400, 'UTF-8')).
            "\nتاریخ ثبت: ".phoneRegEsc(phoneRegDate($old['created_at']));
    }
    if ($new) {
        $body .= "\n\n<b>مشخصات ".($old ? 'جدید' : 'ثبت‌شده')."</b>".
            "\nشماره: <code>".phoneRegEsc($new['phone'])."</code>".
            "\nنام مستعار/آیدی: ".phoneRegEsc(mb_substr($new['nickname'], 0, 400, 'UTF-8')).
            "\nتاریخ ثبت: ".phoneRegEsc(phoneRegDate($new['created_at']));
        if (!empty($new['order_id'])) $body .= "\n📦 سفارش مرتبط: <code>".(int)$new['order_id']."</code>";
    }
    sendToAdmins($body, null, 'HTML');
}


/**
 * Existing pre-gate rows have NULL order_id: retain them for manual review.
 * Newly registered and manually confirmed numbers have an order_id, which
 * remains as historical evidence after subscription expiry or panel deletion.
 */
function phoneRegIsReviewed($row) {
    return (int)($row['order_id'] ?? 0) > 0;
}

function phoneRegAdminKb($row, $scanned = null) {
    $id = (int)$row['id'];
    $rows = [
        [['text'=>'🔍 اسکن آخرین اشتراک فعال', 'callback_data'=>'phoneRegAdminScan_'.$id]]
    ];
    if (!phoneRegIsReviewed($row) && is_array($scanned) && ($scanned['state'] ?? '') === 'active') {
        $rows[] = [['text'=>'✅ تأیید و ثبت دستی این شماره', 'callback_data'=>'phoneRegAdminApprove_'.$id.'_'.(int)$scanned['order']['id']]];
    }
    $rows[] = [
        ['text'=>'✏️ ویرایش شماره', 'callback_data'=>'phoneRegAdminEditPhone_'.$id],
        ['text'=>'📝 ویرایش نام/آیدی', 'callback_data'=>'phoneRegAdminEditAlias_'.$id]
    ];
    $rows[] = [['text'=>'✉️ ارسال پیام خصوصی', 'callback_data'=>'phoneRegAdminMsg_'.$id]];
    $rows[] = [['text'=>'🗑 حذف شماره', 'callback_data'=>'phoneRegAdminDeleteAsk_'.$id]];
    $rows[] = [['text'=>'↩️ فهرست بررسی‌نشده‌ها', 'callback_data'=>'phoneRegAdminPending_0']];
    $rows[] = [['text'=>'📋 همه شماره‌ها', 'callback_data'=>'phoneRegAdminList_0']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function phoneRegAdminDetails($row, $scanned = null) {
    global $message_id;
    $body = "📱 <b>جزئیات شماره ثبت‌شده</b>".
        "\n🆔 ردیف: ".(int)$row['id'].
        "\n👤 شناسه کاربر: <a href=\"tg://user?id=".(int)$row['user_id']."\">".(int)$row['user_id']."</a>".
        "\n📞 شماره: <code>".phoneRegEsc($row['phone'])."</code>".
        "\n✏️ نام مستعار/آیدی: ".phoneRegEsc(mb_substr($row['nickname'], 0, 1000, 'UTF-8')).
        "\n🕒 زمان ثبت: ".phoneRegEsc(phoneRegDate($row['created_at'])).
        "\n🔄 آخرین ویرایش: ".phoneRegEsc(phoneRegDate($row['updated_at'])).
        "\n\n🔖 وضعیت ثبت: ".(phoneRegIsReviewed($row) ? '✅ تأییدشده (ماندگار)' : '🟡 قدیمی - نیازمند بررسی دستی');
    if (phoneRegIsReviewed($row)) {
        $body .= "\n📦 سفارش هنگام تأیید/ثبت: <code>".(int)$row['order_id']."</code>".
            "\n📌 پایان اعتبار اشتراک باعث حذف یا لغو این ثبت نمی‌شود.";
    }
    if (is_array($scanned)) {
        $state = $scanned['state'] ?? 'unknown';
        if ($state === 'active') {
            $order = $scanned['order'];
            $snap = $scanned['snapshot'];
            $total = max(0, (int)($snap['total'] ?? 0));
            $used = max(0, (int)($snap['used'] ?? 0));
            $remain = $total > 0 ? deltaSvcTraffic(max(0, $total-$used)) : '♾️ نامحدود';
            $expire = (int)($snap['expire'] ?? 0);
            $days = $expire > 0 ? max(0, (int)ceil(($expire-time())/86400)).' روز' : '♾️ نامحدود';
            $body .= "\n\n✅ <b>نتیجه اسکن: آخرین اشتراک فعال پیدا شد</b>".
                "\n📦 نام اشتراک: ".phoneRegEsc($order['remark'] ?? '#'.$order['id']).
                "\n🆔 سفارش: <code>".(int)$order['id']."</code>".
                "\n📊 حجم باقی‌مانده: ".phoneRegEsc($remain).
                "\n⏳ اعتبار باقی‌مانده: ".phoneRegEsc($days);
        } elseif ($state === 'unknown') {
            $body .= "\n\n⚠️ <b>نتیجه اسکن: نامشخص</b>\nحداقل یکی از پنل‌ها قابل استعلام نیست؛ این به معنی نداشتن اشتراک نیست. بعداً دوباره بررسی کنید.";
        } else {
            $body .= "\n\n❌ <b>نتیجه اسکن: اشتراک فعالی شناسایی نشد.</b>\nمی‌توانید متن دلخواه را خصوصی برای این کاربر ارسال کنید.";
        }
        $body .= "\n🕒 زمان اسکن: ".phoneRegEsc(phoneRegDate(time()));
    } else {
        $body .= "\n\nبرای بررسی وضعیت <b>فعلی</b> اشتراک، دکمه اسکن را بزنید.";
    }
    smartSendOrEdit($message_id, $body, phoneRegAdminKb($row, $scanned), 'HTML');
}

function phoneRegAdminAudit($action, $before, $after) {
    global $from_id;
    $row = $after ?: $before;
    if (!$row) return;
    $text = "🛠 <b>گزارش مدیریت شماره</b>".
        "\n🔹 عملیات: ".phoneRegEsc($action).
        "\n👨‍💼 مدیر: <code>".(int)$from_id."</code>".
        "\n👤 کاربر: <code>".(int)$row['user_id']."</code>".
        "\n🆔 ردیف: <code>".(int)$row['id']."</code>".
        "\n⏱ زمان: ".phoneRegEsc(phoneRegDate(time()));
    foreach (['قبل'=>$before,'بعد'=>$after] as $label=>$item) {
        if (!$item) continue;
        $text .= "\n\n<b>".phoneRegEsc($label).":</b>".
            "\n📞 <code>".phoneRegEsc($item['phone'])."</code>".
            "\n✏️ ".phoneRegEsc(mb_substr($item['nickname'], 0, 250, 'UTF-8')).
            "\n📦 سفارش: ".(int)($item['order_id'] ?? 0);
    }
    sendToAdmins($text, null, 'HTML');
}

function phoneRegAdminSettingsKeys() {
    global $botState;
    $rows = [
        [['text'=>phoneRegEnabled() ? '✅ ثبت شماره: روشن' : '❌ ثبت شماره: خاموش', 'callback_data'=>'phoneRegToggle']]
    ];
    foreach (phoneRegTextFields() as $key => $value)
        $rows[] = [['text'=>'✏️ '.$value[0], 'callback_data'=>'phoneRegText_'.$key]];
    $rows[] = [['text'=>'🟡 ثبت‌های قدیمی بررسی‌نشده', 'callback_data'=>'phoneRegAdminPending_0']];
    $rows[] = [['text'=>'📋 همه شماره‌های ثبت‌شده', 'callback_data'=>'phoneRegAdminList_0']];
    $rows[] = [['text'=>'↩️ بازگشت به تنظیمات ربات', 'callback_data'=>'botSettings']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function phoneRegAdminList($page, $pendingOnly = false) {
    global $connection, $message_id;
    $page = max(0, (int)$page);
    $where = $pendingOnly ? ' WHERE COALESCE(order_id, 0)=0' : '';
    $total = (int)($connection->query('SELECT COUNT(*) AS c FROM phone_registrations'.$where)->fetch_assoc()['c'] ?? 0);
    $pages = max(1, (int)ceil($total / 25));
    $page = min($page, $pages-1);
    $offset = $page*25;
    $result = $connection->query('SELECT id, phone, nickname, order_id FROM phone_registrations'.$where.' ORDER BY id DESC LIMIT 25 OFFSET '.$offset);
    $rows = [];
    $i = $offset + 1;
    while ($row = $result->fetch_assoc()) {
        $name = mb_substr(str_replace(["\n", "\r"], ' ', $row['nickname']), 0, 18, 'UTF-8');
        $status = phoneRegIsReviewed($row) ? '✅' : '🟡';
        $rows[] = [['text'=>$status.' '.($i++).'. '.$row['phone'].' | '.$name, 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]];
    }
    $prefix = $pendingOnly ? 'phoneRegAdminPending_' : 'phoneRegAdminList_';
    $nav = [];
    if ($page > 0) $nav[] = ['text'=>'⬅️ قبلی', 'callback_data'=>$prefix.($page-1)];
    if ($page < $pages-1) $nav[] = ['text'=>'بعدی ➡️', 'callback_data'=>$prefix.($page+1)];
    if ($nav) $rows[] = $nav;
    if (!$pendingOnly) $rows[] = [['text'=>'🟡 بررسی‌نشده‌ها', 'callback_data'=>'phoneRegAdminPending_0']];
    else $rows[] = [['text'=>'📋 همه شماره‌ها', 'callback_data'=>'phoneRegAdminList_0']];
    $rows[] = [['text'=>'↩️ بازگشت', 'callback_data'=>'phoneRegSettings']];
    smartSendOrEdit($message_id, ($pendingOnly ? '🟡 شماره‌های بررسی‌نشده' : '📋 شماره‌های ثبت‌شده').
        "\nتعداد: $total\nصفحه ".($page+1)." از $pages",
        json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), 'HTML');
}

function phoneRegHandle() {
    global $data, $userInfo, $from_id, $admin, $update, $text, $message_id, $buttonValues, $connection;
    $callback = (string)($data ?? '');
    $step = (string)($userInfo['step'] ?? '');
    $isAdmin = (int)$from_id === (int)$admin || !empty($userInfo['isAdmin']);
    if (strpos($callback, 'phoneReg') !== 0 && strpos($step, 'phoneReg') !== 0) return false;
    // Do not hijack other bot menu buttons while a registration form is open.
    if ($callback !== '' && strpos($callback, 'phoneReg') !== 0) {
        if (strpos($step, 'phoneReg') === 0) phoneRegCancel();
        return false;
    }
    // Keep the draft only while the admin confirms sending the previewed message.
    $confirmingDraft = preg_match('/^phoneRegAdminMsgConfirm_(\d+)$/', $callback, $draftMatch) &&
        $step === 'phoneRegAdminMsgReady:'.$draftMatch[1];
    if ($callback !== '' && strpos($step, 'phoneReg') === 0 && !$confirmingDraft) {
        phoneRegCancel();
        $step = 'none';
    }

    // Normal /start and main-menu navigation can always interrupt the flow.
    if ($callback === 'mainMenu' || trim((string)($text ?? '')) === '/start' ||
        trim((string)($text ?? '')) === ($buttonValues['cancel'] ?? '')) {
        phoneRegCancel();
        return false;
    }

    try {
        if (!phoneRegEnsureTable()) {
            sendMessage('❌ خطا در آماده‌سازی دیتابیس ثبت شماره. لطفاً به مدیر اطلاع دهید.', null, null);
            return true;
        }
        // All management callbacks and editable texts are strictly admin-only.
        if (preg_match('/^phoneReg(?:Settings|Toggle|Text_|Admin)/', $callback) ||
            strpos($step, 'phoneRegText:') === 0 || strpos($step, 'phoneRegAdmin') === 0) {
            if (!$isAdmin) { answerQuery('دسترسی ندارید.', true); return true; }

            if ($callback === 'phoneRegSettings') {
                smartSendOrEdit($message_id, '⚙️ تنظیمات ثبت شماره', phoneRegAdminSettingsKeys());
            } elseif ($callback === 'phoneRegToggle') {
                setSettings('phoneRegistrationState', phoneRegEnabled() ? 'off' : 'on');
                smartSendOrEdit($message_id, '⚙️ تنظیمات ثبت شماره', phoneRegAdminSettingsKeys());
            } elseif (preg_match('/^phoneRegText_([a-z]+)$/', $callback, $m) && isset(phoneRegTextFields()[$m[1]])) {
                setUser('phoneRegText:'.$m[1], 'step');
                sendMessage('متن جدید را ارسال کنید:'."\n\n".phoneRegText($m[1]),
                    json_encode(['inline_keyboard'=>[[['text'=>'❌ انصراف', 'callback_data'=>'phoneRegSettings']]]], JSON_UNESCAPED_UNICODE), null);
            } elseif (strpos($step, 'phoneRegText:') === 0 && isset($update->message)) {
                $key = substr($step, strlen('phoneRegText:'));
                if (isset(phoneRegTextFields()[$key]) && isset($update->message->text) && trim((string)$update->message->text) !== '') {
                    upsertSettingValue('PHONE_REG_TEXT_'.$key, (string)$update->message->text);
                    phoneRegCancel();
                    sendMessage('✅ متن جدید ذخیره شد.', phoneRegAdminSettingsKeys(), null);
                } else sendMessage('❌ لطفاً یک متن غیرخالی بفرستید.', null, null);
            } elseif (preg_match('/^phoneRegAdminList_(\d+)$/', $callback, $m)) {
                phoneRegAdminList((int)$m[1]);
            } elseif (preg_match('/^phoneRegAdminPending_(\d+)$/', $callback, $m)) {
                phoneRegAdminList((int)$m[1], true);
            } elseif (preg_match('/^phoneRegAdminView_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                phoneRegAdminDetails($row);
            } elseif (preg_match('/^phoneRegAdminScan_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                $checked = phoneRegLatestActive((int)$row['user_id']);
                phoneRegAdminDetails($row, $checked);
            } elseif (preg_match('/^phoneRegAdminApprove_(\d+)_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                if (phoneRegIsReviewed($row)) {
                    sendMessage('ℹ️ این شماره قبلاً تأیید شده است.', null, null);
                } else {
                    // Do not trust an old scan or a crafted callback: check ownership and live state again.
                    $checked = phoneRegLatestActive((int)$row['user_id']);
                    if (($checked['state'] ?? '') !== 'active' || (int)$checked['order']['id'] !== (int)$m[2]) {
                        sendMessage('⚠️ وضعیت اشتراک تغییر کرده یا قابل استعلام نیست. دوباره اسکن کنید.', null, null);
                    } else {
                        $oid = (int)$m[2]; $rid = (int)$row['id'];
                        $stmt = $connection->prepare('UPDATE phone_registrations SET order_id=? WHERE id=? AND COALESCE(order_id,0)=0');
                        $stmt->bind_param('ii', $oid, $rid);
                        $stmt->execute();
                        $changed = $stmt->affected_rows;
                        $stmt->close();
                        if ($changed) {
                            $new = phoneRegRecord($rid);
                            phoneRegAdminAudit('تأیید دستی با اشتراک فعال', $row, $new);
                            sendMessage('✅ ثبت این شماره تأیید شد. حتی پس از پایان اشتراک باقی می‌ماند.', null, null);
                        } else {
                            sendMessage('ℹ️ وضعیت این شماره قبلاً تغییر کرده است.', null, null);
                        }
                    }
                }
                $updated = phoneRegRecord((int)$m[1]);
                if ($updated) phoneRegAdminDetails($updated);
            } elseif (preg_match('/^phoneRegAdminDeleteAsk_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                $body = "⚠️ <b>تأیید حذف شماره</b>\nاین عمل فقط با تأیید شما انجام می‌شود.\n".
                    "📞 <code>".phoneRegEsc($row['phone'])."</code>\n".
                    "👤 کاربر: <code>".(int)$row['user_id']."</code>";
                smartSendOrEdit($message_id, $body, json_encode(['inline_keyboard'=>[
                    [['text'=>'🗑 بله، این شماره حذف شود', 'callback_data'=>'phoneRegAdminDeleteConfirm_'.(int)$row['id']]],
                    [['text'=>'❌ انصراف', 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]]
                ]], JSON_UNESCAPED_UNICODE), 'HTML');
            } elseif (preg_match('/^phoneRegAdminDeleteConfirm_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('این شماره قبلاً حذف شده است.', true); return true; }
                $id = (int)$row['id'];
                $stmt = $connection->prepare('DELETE FROM phone_registrations WHERE id=?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $changed = $stmt->affected_rows;
                $stmt->close();
                if ($changed) {
                    phoneRegAdminAudit('حذف دستی توسط مدیر', $row, null);
                    sendMessage('✅ شماره موردنظر حذف شد.', null, null);
                }
                phoneRegAdminList(0);
            } elseif (preg_match('/^phoneRegAdminEdit(Phone|Alias)_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[2]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                $field = $m[1];
                setUser('phoneRegAdminEdit'.$field.':'.(int)$row['id'], 'step');
                $prompt = $field === 'Phone'
                    ? "✏️ شماره جدید را به شکل 09xxxxxxxxx وارد کنید.\nشماره فعلی: ".$row['phone']
                    : "📝 نام مستعار یا آیدی جدید را ارسال کنید.\nمقدار فعلی: ".mb_substr($row['nickname'], 0, 200, 'UTF-8');
                sendMessage($prompt, json_encode(['inline_keyboard'=>[
                    [['text'=>'❌ انصراف', 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]]
                ]],JSON_UNESCAPED_UNICODE), null);
            } elseif (preg_match('/^phoneRegAdminEdit(Phone|Alias):(\d+)$/', $step, $m) && isset($update->message)) {
                $row = phoneRegRecord((int)$m[2]);
                if (!$row) { phoneRegCancel(); sendMessage('❌ شماره دیگر وجود ندارد.', null, null); return true; }
                $value = trim((string)($update->message->text ?? ''));
                if ($m[1] === 'Phone') {
                    if (!preg_match('/^09[0-9]{9}$/D', $value)) {
                        sendMessage(phoneRegText('invalid'), null, null);
                        return true;
                    }
                    $stmt = $connection->prepare('UPDATE phone_registrations SET phone=?, updated_at=? WHERE id=?');
                } else {
                    if ($value === '' || mb_strlen($value, 'UTF-8') > 500) {
                        sendMessage('❌ نام/آیدی باید متن غیرخالی و حداکثر ۵۰۰ کاراکتر باشد.', null, null);
                        return true;
                    }
                    $stmt = $connection->prepare('UPDATE phone_registrations SET nickname=?, updated_at=? WHERE id=?');
                }
                $now = time(); $id = (int)$row['id'];
                $stmt->bind_param('sii', $value, $now, $id);
                $stmt->execute();
                $stmt->close();
                $new = phoneRegRecord($id);
                phoneRegCancel();
                phoneRegAdminAudit('ویرایش دستی توسط مدیر', $row, $new);
                sendMessage('✅ مشخصات ویرایش شد.', null, null);
                phoneRegAdminDetails($new);
            } elseif (preg_match('/^phoneRegAdminMsg_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                setUser('phoneRegAdminMsg:'.(int)$row['id'], 'step');
                setUser('', 'temp');
                sendMessage("✉️ متن پیام خصوصی موردنظرتان را ارسال کنید.\n".
                    "👤 شناسه گیرنده: ".(int)$row['user_id'].
                    "\n\nنمونه پیشنهادی (ارسال خودکار نیست):\n".
                    "شما در حال حاضر اشتراک فعالی از دلتا شاپ ندارید. برای ثبت شماره لطفاً ابتدا اشتراک تهیه کنید.".
                    "\n\nبعد از ارسال متن، پیش‌نمایش و دکمه تأیید نمایش داده می‌شود.",
                    json_encode(['inline_keyboard'=>[[['text'=>'❌ انصراف', 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]]]], JSON_UNESCAPED_UNICODE), null);
            } elseif (preg_match('/^phoneRegAdminMsg:(\d+)$/', $step, $m) && isset($update->message)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { phoneRegCancel(); sendMessage('❌ شماره دیگر وجود ندارد.', null, null); return true; }
                $draft = trim((string)($update->message->text ?? ''));
                if ($draft === '' || mb_strlen($draft, 'UTF-8') > 500) {
                    sendMessage('❌ فقط متن غیرخالی تا ۵۰۰ کاراکتر ارسال کنید.', null, null);
                    return true;
                }
                setUser(json_encode(['id'=>(int)$row['id'],'message'=>$draft], JSON_UNESCAPED_UNICODE), 'temp');
                setUser('phoneRegAdminMsgReady:'.(int)$row['id'], 'step');
                sendMessage("📨 پیش‌نمایش پیام خصوصی برای کاربر ".(int)$row['user_id'].":\n\n".$draft.
                    "\n\n⚠️ پیام فقط پس از زدن دکمه تأیید ارسال می‌شود.",
                    json_encode(['inline_keyboard'=>[
                        [['text'=>'✅ تأیید و ارسال پیام', 'callback_data'=>'phoneRegAdminMsgConfirm_'.(int)$row['id']]],
                        [['text'=>'❌ لغو ارسال', 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]]
                    ]], JSON_UNESCAPED_UNICODE), null);
            } elseif (preg_match('/^phoneRegAdminMsgConfirm_(\d+)$/', $callback, $m)) {
                $id = (int)$m[1];
                $ctx = json_decode((string)($userInfo['temp'] ?? ''), true);
                $row = phoneRegRecord($id);
                if ($step !== 'phoneRegAdminMsgReady:'.$id || !$row || !is_array($ctx) ||
                    (int)($ctx['id'] ?? 0) !== $id || trim((string)($ctx['message'] ?? '')) === '') {
                    sendMessage('⚠️ پیش‌نویس معتبر نیست یا منقضی شده است. دوباره متن را وارد کنید.', null, null);
                    return true;
                }
                $draft = (string)$ctx['message'];
                $sent = sendMessage($draft, null, null, (int)$row['user_id']);
                if (is_object($sent) && !empty($sent->ok)) {
                    phoneRegCancel();
                    sendMessage('✅ پیام خصوصی با موفقیت ارسال شد.', null, null);
                    phoneRegAdminDetails($row);
                } else {
                    sendMessage('❌ ارسال پیام ناموفق بود. ممکن است کاربر ربات را مسدود کرده باشد. پیش‌نویس حفظ شده؛ می‌توانید دوباره تلاش کنید یا لغو کنید.', null, null);
                }
            } elseif (strpos($step, 'phoneRegAdminMsgReady:') === 0 && isset($update->message)) {
                sendMessage('📨 متن شما آماده است. برای ارسال از دکمه «تأیید و ارسال پیام» زیر پیش‌نمایش استفاده کنید یا لغو کنید.', null, null);
            }
            return true;
        }

        if (!phoneRegEnabled()) {
            if ($step === 'phoneRegWaitPhone' || $step === 'phoneRegWaitAlias') phoneRegCancel();
            sendMessage('⛔️ ثبت شماره در حال حاضر غیرفعال است.', null, null);
            return true;
        }

        if ($callback === 'phoneRegCancel') {
            phoneRegCancel();
            phoneRegOverview();
            return true;
        }
        if ($callback === 'phoneRegOpen') {
            phoneRegCancel();
            $rows = phoneRegRows((int)$from_id);
            if ($rows) phoneRegOverview();
            else phoneRegOffer();
            return true;
        }
        if ($callback === 'phoneRegNew') {
            phoneRegOffer();
            return true;
        }
        if (preg_match('/^phoneRegConfirm_(\d+)$/', $callback, $m)) {
            $checked = phoneRegCheckOrder((int)$m[1], (int)$from_id);
            if ($checked['state'] !== 'active') {
                phoneRegEligibilityError($checked['state']);
                return true;
            }
            phoneRegStart('new', 0, (int)$m[1]);
            return true;
        }
        if (preg_match('/^phoneRegView_(\d+)$/', $callback, $m)) {
            $row = phoneRegRecord((int)$m[1], (int)$from_id);
            if (!$row) { answerQuery('مشخصات پیدا نشد.', true); return true; }
            phoneRegOverview();
            return true;
        }
        if (preg_match('/^phoneRegEdit_(\d+)$/', $callback, $m)) {
            phoneRegStart('edit', (int)$m[1]);
            return true;
        }
        if (preg_match('/^phoneRegDeleteAsk_(\d+)$/', $callback, $m)) {
            $row = phoneRegRecord((int)$m[1], (int)$from_id);
            if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
            sendMessage('⚠️ شماره '.$row['phone'].' حذف شود؟',
                json_encode(['inline_keyboard'=>[
                    [['text'=>'✅ بله حذف کن', 'callback_data'=>'phoneRegDelete_'.$row['id']]],
                    [['text'=>'❌ انصراف', 'callback_data'=>'phoneRegCancel']]
                ]], JSON_UNESCAPED_UNICODE), null);
            return true;
        }
        if (preg_match('/^phoneRegDelete_(\d+)$/', $callback, $m)) {
            $row = phoneRegRecord((int)$m[1], (int)$from_id);
            if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
            $id = (int)$row['id']; $uid = (int)$from_id;
            $stmt = $connection->prepare('DELETE FROM phone_registrations WHERE id=? AND user_id=?');
            $stmt->bind_param('ii', $id, $uid);
            $stmt->execute();
            $changed = $stmt->affected_rows;
            $stmt->close();
            if ($changed) {
                phoneRegNotify('حذف شد', $row, null);
                sendMessage(phoneRegText('deleted'), null, null);
            }
            phoneRegOverview();
            return true;
        }

        if ($step === 'phoneRegWaitPhone' && isset($update->message)) {
            // Only typed ASCII digits: reject contacts, media, alphabetic characters and non-09 numbers.
            $phone = trim((string)($update->message->text ?? ''));
            if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
                sendMessage(phoneRegText('invalid'), null, null);
                return true;
            }
            $context = json_decode((string)($userInfo['temp'] ?? ''), true);
            if (!is_array($context)) $context = [];
            $uid = (int)$from_id;
            $id = (int)($context['id'] ?? 0);
            $mode = ($context['mode'] ?? 'new') === 'edit' ? 'edit' : 'new';
            if ($mode === 'edit' && !phoneRegRecord($id, $uid)) {
                phoneRegCancel();
                sendMessage('❌ شماره موردنظر دیگر وجود ندارد.', null, null);
                return true;
            }
            foreach (phoneRegRows($uid) as $row) {
                if ($row['phone'] === $phone && ($mode !== 'edit' || (int)$row['id'] !== $id)) {
                    sendMessage(phoneRegText('duplicate'), null, null);
                    return true;
                }
            }
            $context['phone'] = $phone;
            setUser(json_encode($context), 'temp');
            setUser('phoneRegWaitAlias', 'step');
            sendMessage(phoneRegText('alias'), null, null);
            return true;
        }
        if ($step === 'phoneRegWaitAlias' && isset($update->message)) {
            // Accept all text contents (Persian, emoji, handles, numbers, etc.), but not empty media.
            $nickname = (string)($update->message->text ?? $update->message->caption ?? '');
            if (trim($nickname) === '') {
                sendMessage('❌ لطفاً نام مستعار یا آیدی را به صورت متن بفرستید.', null, null);
                return true;
            }
            $context = json_decode((string)($userInfo['temp'] ?? ''), true);
            $phone = (string)($context['phone'] ?? '');
            if (!preg_match('/^09[0-9]{9}$/D', $phone)) {
                phoneRegCancel();
                sendMessage('❌ شماره معتبر نیست؛ دوباره از منو شروع کنید.', null, null);
                return true;
            }
            $uid = (int)$from_id; $now = time();
            $mode = ($context['mode'] ?? 'new') === 'edit' ? 'edit' : 'new';
            if ($mode === 'new') {
                // Revalidate at submission; an earlier green card is not permanent authorization.
                $checked = phoneRegCheckOrder((int)($context['order_id'] ?? 0), $uid);
                if ($checked['state'] !== 'active') {
                    phoneRegCancel();
                    phoneRegEligibilityError($checked['state']);
                    return true;
                }
            }
            if ($mode === 'edit') {
                $id = (int)($context['id'] ?? 0);
                $previous = phoneRegRecord($id, $uid);
                if (!$previous) { phoneRegCancel(); sendMessage('شماره دیگر وجود ندارد.', null, null); return true; }
                $stmt = $connection->prepare('UPDATE phone_registrations SET phone=?, nickname=?, updated_at=? WHERE id=? AND user_id=?');
                $stmt->bind_param('ssiii', $phone, $nickname, $now, $id, $uid);
                $stmt->execute();
                $stmt->close();
                $saved = phoneRegRecord($id, $uid);
                if (!$saved) throw new RuntimeException('Edited record unavailable');
                phoneRegCancel();
                phoneRegNotify('ویرایش شد', $previous, $saved);
                sendMessage(phoneRegText('edited'), null, null);
            } else {
                // users is MyISAM in older installs; use a MySQL advisory lock
                // instead of SELECT ... FOR UPDATE to enforce the three-record limit.
                $lockName = 'phone_reg_' . $uid;
                $lockStmt = $connection->prepare('SELECT GET_LOCK(?, 5) AS acquired');
                $lockStmt->bind_param('s', $lockName);
                $lockStmt->execute();
                $locked = (int)($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
                $lockStmt->close();
                if ($locked !== 1) throw new RuntimeException('Could not lock phone registrations');
                try {
                    $connection->begin_transaction();
                    try {
                    $stmt = $connection->prepare('SELECT COUNT(*) AS c FROM phone_registrations WHERE user_id=?');
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $count = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
                    if ($count >= 3) {
                        $connection->rollback();
                        phoneRegCancel();
                        sendMessage(phoneRegText('limit'), null, null);
                        return true;
                    }
                    $orderId = (int)$context['order_id'];
                    $stmt = $connection->prepare('INSERT INTO phone_registrations (user_id,phone,nickname,order_id,created_at,updated_at) VALUES (?,?,?,?,?,?)');
                    $stmt->bind_param('issiii', $uid, $phone, $nickname, $orderId, $now, $now);
                    $stmt->execute();
                    $id = (int)$connection->insert_id;
                    $stmt->close();
                    $connection->commit();
                    } catch (Throwable $e) {
                        $connection->rollback();
                        throw $e;
                    }
                } finally {
                    $unlockStmt = $connection->prepare('SELECT RELEASE_LOCK(?)');
                    $unlockStmt->bind_param('s', $lockName);
                    $unlockStmt->execute();
                    $unlockStmt->close();
                }
                $saved = phoneRegRecord($id, $uid);
                phoneRegCancel();
                phoneRegNotify('ثبت جدید', null, $saved);
                sendMessage(phoneRegText('success'), null, null);
            }
            phoneRegOverview();
            return true;
        }
    } catch (Throwable $e) {
        error_log('phoneRegHandle error: '.$e->getMessage());
        $duplicate = ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062);
        sendMessage($duplicate ? phoneRegText('duplicate') : '❌ خطایی رخ داد. لطفاً دوباره تلاش کنید.', null, null);
        return true;
    }
    return strpos($callback, 'phoneReg') === 0 || strpos($step, 'phoneReg') === 0;
}
