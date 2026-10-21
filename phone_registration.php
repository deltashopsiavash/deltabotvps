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
        'duplicate' => ['شماره تکراری', '❌ این شماره را قبلاً ثبت کرده‌اید.']
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
        created_at BIGINT UNSIGNED NOT NULL,
        updated_at BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY unique_user_phone (user_id, phone),
        KEY user_records (user_id, id),
        KEY registered_latest (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ($ok) $checked[$db] = true;
    return (bool)$ok;
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
            "\nنام مستعار / آیدی: ".$row['nickname'].
            "\nزمان ثبت: ".phoneRegDate($row['created_at']);
        if ((int)$row['updated_at'] > (int)$row['created_at'])
            $message .= "\nآخرین ویرایش: ".phoneRegDate($row['updated_at']);
    }
    sendMessage($message, phoneRegKeyboard($rows), null);
}

function phoneRegStart($mode, $id = 0) {
    global $from_id;
    $rows = phoneRegRows((int)$from_id);
    if ($mode === 'new' && count($rows) >= 3) {
        sendMessage(phoneRegText('limit'), phoneRegKeyboard($rows), null);
        return;
    }
    if ($mode === 'edit' && !phoneRegRecord($id, (int)$from_id)) {
        sendMessage('❌ این شماره متعلق به شما نیست یا حذف شده است.', null, null);
        return;
    }
    setUser(json_encode(['mode'=>$mode,'id'=>(int)$id], JSON_UNESCAPED_UNICODE), 'temp');
    setUser('phoneRegWaitPhone', 'step');
    sendMessage(phoneRegText('phone'), json_encode(['inline_keyboard'=>[
        [['text'=>'❌ انصراف', 'callback_data'=>'phoneRegCancel']]
    ]], JSON_UNESCAPED_UNICODE), null);
}

function phoneRegCancel() {
    setUser('none', 'step');
    setUser('', 'temp');
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
    }
    sendToAdmins($body, null, 'HTML');
}

function phoneRegAdminSettingsKeys() {
    global $botState;
    $rows = [
        [['text'=>phoneRegEnabled() ? '✅ ثبت شماره: روشن' : '❌ ثبت شماره: خاموش', 'callback_data'=>'phoneRegToggle']]
    ];
    foreach (phoneRegTextFields() as $key => $value)
        $rows[] = [['text'=>'✏️ '.$value[0], 'callback_data'=>'phoneRegText_'.$key]];
    $rows[] = [['text'=>'📋 شماره‌های ثبت‌شده', 'callback_data'=>'phoneRegAdminList_0']];
    $rows[] = [['text'=>'↩️ بازگشت به تنظیمات ربات', 'callback_data'=>'botSettings']];
    return json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE);
}

function phoneRegAdminList($page) {
    global $connection, $message_id;
    $page = max(0, (int)$page);
    $total = (int)($connection->query('SELECT COUNT(*) AS c FROM phone_registrations')->fetch_assoc()['c'] ?? 0);
    $pages = max(1, (int)ceil($total / 25));
    $page = min($page, $pages-1);
    $offset = $page*25;
    $result = $connection->query('SELECT id, phone, nickname FROM phone_registrations ORDER BY id DESC LIMIT 25 OFFSET '.$offset);
    $rows = [];
    $i = $offset + 1;
    while ($row = $result->fetch_assoc()) {
        $name = mb_substr(str_replace(["\n", "\r"], ' ', $row['nickname']), 0, 18, 'UTF-8');
        $rows[] = [['text'=>($i++).'. '.$row['phone'].' | '.$name, 'callback_data'=>'phoneRegAdminView_'.(int)$row['id']]];
    }
    $nav = [];
    if ($page > 0) $nav[] = ['text'=>'⬅️ قبلی', 'callback_data'=>'phoneRegAdminList_'.($page-1)];
    if ($page < $pages-1) $nav[] = ['text'=>'بعدی ➡️', 'callback_data'=>'phoneRegAdminList_'.($page+1)];
    if ($nav) $rows[] = $nav;
    $rows[] = [['text'=>'↩️ بازگشت', 'callback_data'=>'phoneRegSettings']];
    smartSendOrEdit($message_id, '📋 شماره‌های ثبت‌شده'."\nتعداد: $total\nصفحه ".($page+1)." از $pages",
        json_encode(['inline_keyboard'=>$rows], JSON_UNESCAPED_UNICODE), 'HTML');
}

function phoneRegHandle() {
    global $data, $userInfo, $from_id, $admin, $update, $text, $message_id, $buttonValues, $connection;
    $callback = (string)($data ?? '');
    $step = (string)($userInfo['step'] ?? '');
    $isAdmin = (int)$from_id === (int)$admin || !empty($userInfo['isAdmin']);
    if (strpos($callback, 'phoneReg') !== 0 && strpos($step, 'phoneReg') !== 0) return false;

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
            strpos($step, 'phoneRegText:') === 0) {
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
            } elseif (preg_match('/^phoneRegAdminView_(\d+)$/', $callback, $m)) {
                $row = phoneRegRecord((int)$m[1]);
                if (!$row) { answerQuery('شماره پیدا نشد.', true); return true; }
                $body = "📱 <b>جزئیات شماره ثبت‌شده</b>".
                    "\n🆔 ردیف: ".(int)$row['id'].
                    "\n👤 شناسه کاربر: <a href=\"tg://user?id=".(int)$row['user_id']."\">".(int)$row['user_id']."</a>".
                    "\n📞 شماره: <code>".phoneRegEsc($row['phone'])."</code>".
                    "\n✏️ نام مستعار/آیدی: ".phoneRegEsc($row['nickname']).
                    "\n🕒 زمان ثبت: ".phoneRegEsc(phoneRegDate($row['created_at'])).
                    "\n🔄 آخرین ویرایش: ".phoneRegEsc(phoneRegDate($row['updated_at']));
                smartSendOrEdit($message_id, $body,
                    json_encode(['inline_keyboard'=>[[['text'=>'↩️ بازگشت به فهرست', 'callback_data'=>'phoneRegAdminList_0']]]], JSON_UNESCAPED_UNICODE), 'HTML');
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
            else phoneRegStart('new');
            return true;
        }
        if ($callback === 'phoneRegNew') {
            phoneRegStart('new');
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
            if ($mode === 'edit') {
                $id = (int)($context['id'] ?? 0);
                $previous = phoneRegRecord($id, $uid);
                if (!$previous) { phoneRegCancel(); sendMessage('شماره دیگر وجود ندارد.', null, null); return true; }
                $stmt = $connection->prepare('UPDATE phone_registrations SET phone=?, nickname=?, updated_at=? WHERE id=? AND user_id=?');
                $stmt->bind_param('ssiii', $phone, $nickname, $now, $id, $uid);
                $stmt->execute();
                $stmt->close();
                $saved = phoneRegRecord($id, $uid);
                phoneRegCancel();
                phoneRegNotify('ویرایش شد', $previous, $saved);
                sendMessage(phoneRegText('edited'), null, null);
            } else {
                // Serialize concurrent submissions by this user to enforce the three-record limit.
                $connection->begin_transaction();
                try {
                    $stmt = $connection->prepare('SELECT userid FROM users WHERE userid=? FOR UPDATE');
                    $stmt->bind_param('i', $uid);
                    $stmt->execute(); $stmt->get_result(); $stmt->close();
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
                    $stmt = $connection->prepare('INSERT INTO phone_registrations (user_id,phone,nickname,created_at,updated_at) VALUES (?,?,?,?,?)');
                    $stmt->bind_param('issii', $uid, $phone, $nickname, $now, $now);
                    $stmt->execute();
                    $id = (int)$connection->insert_id;
                    $stmt->close();
                    $connection->commit();
                } catch (Throwable $e) {
                    $connection->rollback();
                    throw $e;
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
