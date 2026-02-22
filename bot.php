<?php
include_once 'config.php';

check();

// ------------------------------------------------------------
// FIX: If we reset step/temp in the same request (because user clicked
// a menu button), we must also update the in-memory $userInfo cache.
// Otherwise step-based blocks below still see the old step and reply
// with "❌ فقط آیدی عددی ارسال کنید".
// ------------------------------------------------------------
if(!function_exists('resetUserFlow')){
    function resetUserFlow(){
        global $userInfo;
        setUser('none','step');
        setUser('', 'temp');
        if(is_array($userInfo)){
            $userInfo['step'] = 'none';
            $userInfo['temp'] = '';
        }
    }
}

// Per-bot auto-backup state key (prevents mother/child collision when DB is shared)
if(!function_exists('getAutoBackupStateKey')){
    function getAutoBackupStateKey(){
        // Use the effective token for this bot instance (mother/child).
        // Child bots set $GLOBALS['botToken'] in config.php.
        $tokenToUse = $GLOBALS['botToken'] ?? null;
        if(!$tokenToUse){
            // Mother bot uses $botToken from baseInfo.php
            global $botToken;
            $tokenToUse = $botToken ?? null;
        }
        $tokenToUse = $tokenToUse ?: 'main';
        return 'AUTO_BACKUP_STATE_' . substr(md5($tokenToUse), 0, 12);
    }
}

// ------------------------------------------------------------
// IMPORTANT: If user clicks any "menu" inline button while stuck
// in a step-based flow, we must allow that callback to escape the flow.
// (e.g. during "adminResBotsCreateUser" waiting for numeric owner id)
// ------------------------------------------------------------
if(isset($data) && is_string($data)){
    $escapeCallbacks = [
        // admin panels
        'managePanel',
        'adminResellerBots',
        'adminResPlans',
        'addResellerPlan',
        // list & navigation
        'adminResBotsList_0',
        'adminResBotsCreate',
    ];
    // also allow paginated list routes
    if(in_array($data, $escapeCallbacks, true) || preg_match('/^adminResBotsList_\d+$/', $data)){
        resetUserFlow();
    }
}


// ------------------------------------------------------------
// Reseller creation SAFETY NET:
// Some servers/users may lose the saved `step` (e.g. after /start or menu navigation),
// but they still send their numeric Telegram ID right after sending the token.
// If user sends ONLY digits and they have a recently-created reseller bot with admin_userid=0,
// treat it as the admin id step.
// ------------------------------------------------------------
if(isset($text) && is_string($text)){
    $tDigits = trim($text);
    // normalize invisible chars
    $tDigits = str_replace(["â", "â", "âª", "â¬"], '', $tDigits);
    if(preg_match('/^\d{5,}$/', $tDigits) && !empty($from_id)){
        // If not already in resellerAwaitAdmin step, try to recover
        $stepNow = $userInfo['step'] ?? 'none';
        if(!preg_match('/^resellerAwaitAdmin_\d+$/', $stepNow)){
            ensureResellerTables();
            $cut = time() - 900; // last 15 minutes
            $stmt = $connection->prepare("SELECT `id` FROM `reseller_bots` WHERE `owner_userid`=? AND `admin_userid`=0 AND `created_at`>=? AND `is_deleted`=0 ORDER BY `id` DESC LIMIT 1");
            $stmt->bind_param("ii", $from_id, $cut);
            $stmt->execute();
            $res = $stmt->get_result();
            $stmt->close();
            if($res && $res->num_rows>0){
                $r = $res->fetch_assoc();
                $ridRecover = (int)$r['id'];
                // restore step and continue normal flow below
                setUser("resellerAwaitAdmin_" . $ridRecover, "step");
                if(is_array($userInfo)){ $userInfo['step'] = "resellerAwaitAdmin_" . $ridRecover; }
            }
        }
    }
}
// ------------------------------------------------------------
// PRE-ROUTER (IMPORTANT)
// This block must run BEFORE any step-based flows, otherwise
// users can get stuck in a previous step (e.g. waiting for numeric
// owner id) and menu buttons won't work.
// Supports ReplyKeyboard (plain text) for admin menus.
// ------------------------------------------------------------
if(isset($text) && is_string($text)){
    $t0 = trim($text);
    // normalize common Persian/RTL invisible chars
    $t0 = str_replace(["\xE2\x80\x8C", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAC"], '', $t0);

    // Always reset flow on /start (escape any stuck step)
    if($t0 === '/start'){
        resetUserFlow();
        // allow normal /start handler to run later
    }

    // Always allow admin to cancel any flow (be tolerant to emoji/order)
    if(
        $t0 === '😪 منصرف شدم بیخیال' || $t0 === 'منصرف شدم بیخیال 😪' || $t0 === 'منصرف شدم بیخیال' ||
        (mb_strpos($t0, 'منصرف') !== false && mb_strpos($t0, 'بیخیال') !== false)
    ){
        resetUserFlow();
        // back to admin panel
        $data = 'managePanel';
    }

    // Admin: map text buttons to routes + reset step/temp
    // IMPORTANT: do not require empty($data). Some installs keep stale callback_data in memory
    // or run pre-router after other parsers; we want text buttons to always win.
    if(($from_id == $admin || (($userInfo['isAdmin'] ?? false) == true))){

        // Management of reseller bots
        if(
            $t0 === 'ربات ها🤖' || $t0 === 'ربات ها 🤖' || $t0 === '🤖 ربات ها' || $t0 === '🤖 ربات' ||
            $t0 === '🤖 ربات‌ها' || $t0 === 'ربات‌ها' || $t0 === 'ربات ها' ||
            $t0 === 'مدیریت ربات 🤖' || $t0 === 'مدیریت ربات' || $t0 === '🤖 مدیریت ربات' ||
            $t0 === 'مدیریت ربات ها 🤖' || $t0 === '🤖 مدیریت ربات ها' || $t0 === 'مدیریت ربات ها' ||
            $t0 === 'مدیریت ربات‌ها' || $t0 === '🤖 مدیریت ربات‌ها'
        ){
            resetUserFlow();
            $data = 'adminResellerBots';
        }

        // Plans (tolerant)
        if(
            $t0 === 'پلن های نمایندگی' || $t0 === '📦 پلن های نمایندگی' || $t0 === 'پلن های نمایندگی 📦' || $t0 === 'مدیریت پلن های نمایندگی' || $t0 === 'پلن‌های نمایندگی' || $t0 === 'پلن‌های نمایندگی 📦'
        ){
            resetUserFlow();
            // unify route name
            $data = 'adminResPlans';
        }
        if(
            $t0 === 'افزودن پلن' || $t0 === '➕ افزودن پلن' || $t0 === '➕ افزودن پلن نمایندگی' || $t0 === 'افزودن پلن نمایندگی' || $t0 === 'افزودن پلن نمایندگی +' || $t0 === '➕ افزودن پلن نمایندگی +' || $t0 === 'افزودن پلن نمایندگی➕' ||
            (mb_strpos($t0, 'افزودن') !== false && mb_strpos($t0, 'پلن') !== false)
        ){
            resetUserFlow();
            $data = 'addResellerPlan';
        }

        // List reseller bots (tolerant)
        if(
            $t0 === '📋 لیست ربات ها' || $t0 === 'لیست ربات ها' || $t0 === 'لیست ربات ها 📋' || $t0 === 'لیست ربات‌ها' ||
            (mb_strpos($t0,'لیست') !== false && mb_strpos($t0,'ربات') !== false)
        ){
            resetUserFlow();
            $data = 'adminResBotsList_0';
        }

        // Inside admin reseller bots menu
        if($t0 === '📋 لیست ربات ها' || $t0 === 'لیست ربات ها 📋' || $t0 === 'لیست ربات‌ها 📋' || $t0 === 'لیست ربات‌ها' || $t0 === 'لیست ربات ها'){
            resetUserFlow();
            $data = 'adminResBotsList_0';
        }
        if($t0 === '➕ ساخت ربات جدید' || $t0 === '➕ ساخت ربات' || $t0 === 'ساخت ربات جدید +' || $t0 === 'ساخت ربات جدید' || $t0 === '➕ ساخت ربات جدید +'){
            resetUserFlow();
            $data = 'adminResBotsCreate';
        }

        // Back (legacy)
        if($t0 === '🔙 بازگشت به پنل مدیریت' || $t0 === 'بازگشت به پنل مدیریت'){
            resetUserFlow();
            $data = 'managePanel';
        }
    }
}

// Periodic maintenance (expiry reminders + auto-disable expired reseller bots)
if(!isset($isChildBot) || !$isChildBot){
    $maintFile = sys_get_temp_dir() . '/deltabot_reseller_maint_ts';
    $last = @file_get_contents($maintFile);
    $last = is_numeric($last) ? (int)$last : 0;
    if(time() - $last > 900){ // at most once per 15 minutes
        @file_put_contents($maintFile, (string)time());
        resellerBotsMaintenance();
    }
}

// Auto DB backup (interval minutes)
// Important UX fix: do NOT run auto-backup on /start. On webhook-based bots, /start is the
// most frequent interaction; sending backups there looks like the bot is "hanging" or spamming.
$isStartCmd = false;
if(isset($text) && is_string($text)){
    $tt = trim($text);
    if($tt === '/start' || strpos($tt, '/start ') === 0) $isStartCmd = true;
}
if(!$isStartCmd){
    $abKey = getAutoBackupStateKey();
    $st = getSettingValue($abKey, '{"enabled":0,"last":0,"interval_min":1440}');
    $stj = json_decode($st, true);
    $enabled = (int)($stj['enabled'] ?? 0);
    $last = (int)($stj['last'] ?? 0);
    $intervalMin = (int)($stj['interval_min'] ?? 1440);
    if($intervalMin < 1) $intervalMin = 1;
    $intervalSec = $intervalMin * 60;
    if($enabled && (time() - $last > $intervalSec)){
        // IMPORTANT: Never create/send auto-backup synchronously in webhook.
        // It can take long and makes the bot "hang" (Telegram timeout), especially when interval is changed.
        // Mark last run immediately, then spawn background worker.
        $stj['last'] = time();
        upsertSettingValue($abKey, json_encode($stj, JSON_UNESCAPED_UNICODE));

        $tokenToUse = $GLOBALS['botToken'] ?? ($botToken ?? null);
        if($tokenToUse && isShellExecAvailable()){
            $worker = __DIR__ . '/backup_worker.php';
            $dbToUse = $GLOBALS['dbName'] ?? ($dbName ?? '');
            $cmd = 'nohup php ' . escapeshellarg($worker) . ' backup ' . escapeshellarg($tokenToUse) . ' ' . escapeshellarg($admin) . ' ' . escapeshellarg('deltabot_auto_backup') . ' ' . escapeshellarg($dbToUse) . ' >/dev/null 2>&1 &';
            @shell_exec($cmd);
        }else{
            // Fallback (sync) - may still take time on huge DBs
            $tmp = dbCreateSqlBackupFile('deltabot_auto_backup');
            if($tmp){
                if(@filesize($tmp) <= 49*1024*1024){
                    $sd = sendDocument($tmp, "🗄 بکاپ خودکار دیتابیس\n".date('Y-m-d H:i:s'));
                    if(isset($sd['ok']) && $sd['ok']) @unlink($tmp);
                }
            }
        }
    }
}

// Child bot expiration guard
if(isset($isChildBot) && $isChildBot && isset($childBotRow) && $childBotRow){
    $exp = (int)$childBotRow['expires_at'];
    if($exp > 0 && time() > $exp){
        // Inform only on /start or if owner/admin interacts
        if(isset($from_id) && ($from_id == (int)$childBotRow['owner_userid'] || $from_id == (int)$childBotRow['admin_userid'])){
            sendMessage("⛔️ این ربات منقضی شده است.
برای تمدید به ربات اصلی مراجعه کنید.");
        }
        exit;
    }
}



$robotState = $botState['botState']??"on";

GOTOSTART:
if ($userInfo['step'] == "banned" && $from_id != $admin && $userInfo['isAdmin'] != true) {
    sendMessage($mainValues['banned']);
    exit();
}
$checkSpam = checkSpam();
if(is_numeric($checkSpam)){
    $time = jdate("Y-m-d H:i:s", $checkSpam);
    sendMessage("اکانت شما به دلیل اسپم مسدود شده است\nزمان آزادسازی اکانت شما: \n$time");
    exit();
}
if(preg_match("/^haveJoined(.*)/",$data,$match)){
    if ($joniedState== "kicked" || $joniedState== "left"){
        alert($mainValues['not_joine_yet']);
        exit();
    }else{
        delMessage();
        $text = $match[1];
    }
}
if (($joniedState== "kicked" || $joniedState== "left") && $from_id != $admin){
    sendMessage(str_replace("CHANNEL-ID", $channelLock, $mainValues['join_channel_message']), json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['join_channel'],'url'=>"https://t.me/" . str_replace("@", "", $botState['lockChannel'])]],
        [['text'=>$buttonValues['have_joined'],'callback_data'=>'haveJoined' . $text]],
        ]]),"HTML");
    exit;
}
if($robotState == "off" && $from_id != $admin){
    sendMessage($mainValues['bot_is_updating']);
    exit();
}

// ===== Restore DB backup (admin) =====
if(($userInfo['step'] ?? '') == 'awaiting_backup_sql' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($update->message) && isset($update->message->document)){
        $doc = $update->message->document;
        $fileId = $doc->file_id ?? null;
        if($fileId){
            $url = getFileUrl($fileId);

            // Download SQL to backups dir (streaming - memory safe)
            $dir = ensureBackupDir();
            $path = $dir . '/restore_' . date('Ymd_His') . '.sql';

            $in = @fopen($url, 'r');
            $out = @fopen($path, 'w');
            if($in && $out){
                @stream_copy_to_stream($in, $out);
                @fclose($in);
                @fclose($out);

                setUser('none','step');
                sendMessage("✅ فایل بکاپ دریافت شد. در حال بازگردانی... ممکن است چند دقیقه طول بکشد.");

                $tokenToUse = $GLOBALS['botToken'] ?? ($botToken ?? null);
                if($tokenToUse && isShellExecAvailable()){
                    $worker = __DIR__ . '/backup_worker.php';
                    $dbToUse = $GLOBALS['dbName'] ?? ($dbName ?? '');
                    $cmd = 'nohup php ' . escapeshellarg($worker) . ' restore ' . escapeshellarg($tokenToUse) . ' ' . escapeshellarg($from_id) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($dbToUse) . ' >/dev/null 2>&1 &';
                    @shell_exec($cmd);
                }else{
                    // Fallback (sync) - may take time on large DB
                    $sql = @file_get_contents($path);
                    $ok = $sql !== false ? dbRestoreFromSql($sql) : false;
                    if($ok){
                        sendMessage("✅ بکاپ با موفقیت بازگردانی شد.");
                        @unlink($path);
                    }else{
                        sendMessage("❌ خطا در بازگردانی بکاپ. فایل SQL معتبر نیست یا اجرای کوئری‌ها با خطا مواجه شد.");
                    }
                }
            }else{
                if($in) @fclose($in);
                if($out) @fclose($out);
                sendMessage("❌ خطا در دانلود/ذخیره فایل بکاپ روی سرور.");
            }
        }
    }else{
        sendMessage("📤 لطفا فایل بکاپ را به صورت Document ارسال کنید.");
    }
    exit;
}

// (admin) =====
if(($userInfo['step'] ?? '') == 'awaiting_backup_interval' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $min = trim((string)($text ?? ''));
    if(!preg_match('/^\d+$/', $min)){
        sendMessage("❌ فقط عدد (دقیقه) ارسال کنید.");
        exit;
    }
    $min = (int)$min;
    if($min < 1) $min = 1;
    // safety upper bound (1 week)
    if($min > 10080) $min = 10080;

    $abKey = getAutoBackupStateKey();
    $st = getSettingValue($abKey, '{"enabled":0,"last":0,"interval_min":1440}');
    $stj = json_decode($st, true);
    if(!is_array($stj)) $stj = ['enabled'=>0,'last'=>0,'interval_min'=>1440];
    $stj['interval_min'] = $min;
    upsertSettingValue($abKey, json_encode($stj, JSON_UNESCAPED_UNICODE));

    setUser('none','step');
    sendMessage("✅ فاصله بکاپ خودکار تنظیم شد: {$min} دقیقه");
    // show menu again
    $data = 'adminBackupMenu';
}




if(($userInfo['step'] ?? '') == 'awaiting_main_buttons_order' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $inp = trim((string)($text ?? ''));
    $inp = str_replace(['،',';','|',' '], [',',',',',',''], $inp);
    if($inp === ''){
        sendMessage("❌ ورودی خالی است. مثال صحیح: 3,1,2");
        exit;
    }
    $parts = explode(',', $inp);
    $nums = [];
    foreach($parts as $p){
        if($p === '') continue;
        if(!preg_match('/^\d+$/', $p)){
            sendMessage("❌ فقط شماره‌ها را با کاما ارسال کنید. مثال: 3,1,2");
            exit;
        }
        $nums[] = (int)$p;
    }

    // Rebuild current visible buttons list (same as prompt)
    $kb = json_decode(getMainKeys(), true);
    $rows = $kb['inline_keyboard'] ?? [];
    $ordered = [];
    foreach($rows as $r){
        if(!is_array($r)) continue;
        foreach($r as $b){
            $cb = $b['callback_data'] ?? '';
            $tx = $b['text'] ?? '';
            if($cb === '' || $cb === 'deltach') continue;
            if($cb === 'managePanel') continue;
            if(trim((string)$tx) === '') continue;
            $ordered[] = ['cb'=>$cb,'title'=>$tx];
        }
    }

    $nTotal = count($ordered);
    if($nTotal == 0){
        setUser('none','step');
        sendMessage("❌ دکمه‌ای برای چینش پیدا نشد.");
        exit;
    }

    // Validate numbers
    $seen=[];
    foreach($nums as $n){
        if($n < 1 || $n > $nTotal){
            sendMessage("❌ شماره خارج از محدوده است. باید بین 1 تا {$nTotal} باشد.");
            exit;
        }
        if(isset($seen[$n])){
            sendMessage("❌ شماره تکراری است. هر شماره فقط یک‌بار.");
            exit;
        }
        $seen[$n]=1;
    }
    if(count($nums) != $nTotal){
        sendMessage("❌ باید دقیقاً {$nTotal} شماره بفرستید (همه دکمه‌ها).");
        exit;
    }

    $newOrderCbs=[];
    foreach($nums as $n){
        $newOrderCbs[] = (string)$ordered[$n-1]['cb'];
    }
    upsertSettingValue('MAIN_MENU_ORDER', json_encode($newOrderCbs, JSON_UNESCAPED_UNICODE));

    setUser('none','step');
    sendMessage("✅ چینش ذخیره شد.");
    $data = 'arrangeButtons';
}

// --- Reseller purchase flow: collect token / admin id after wallet payment
if(!$isChildBot && isset($text) && $text != null){
    if(preg_match('/^resellerAwaitToken_(\d+)$/', $userInfo['step'] ?? '', $mm)){
        ensureResellerTables();
        $rid = (int)$mm[1];
        $token = trim($text);

        // basic token format check
        if(!preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token)){
            sendMessage("❌ فرمت توکن درست نیست.

لطفا توکن صحیح رو ارسال کن.");
            exit;
        }
        // validate with getMe
        $me = botWithToken($token, "getMe", []);
        if(!isset($me['ok']) || !$me['ok']){
            sendMessage("❌ توکن معتبر نیست.

لطفا دوباره توکن صحیح رو ارسال کن.");
            exit;
        }
        $botId = $me['result']['id'] ?? null;
        $username = $me['result']['username'] ?? null;

        $stmt = $connection->prepare("UPDATE `reseller_bots` SET `bot_token`=?, `bot_tg_id`=?, `bot_username`=? WHERE `id`=? AND `owner_userid`=?");
        $stmt->bind_param("sissi", $token, $botId, $username, $rid, $from_id);
        $stmt->execute();
        $stmt->close();

        // Create a dedicated database for this reseller bot (so it doesn't share mother DB)
        // Best-effort: if MySQL user lacks CREATE privilege, we keep shared DB but continue.
        $dbOk = ensureResellerBotDatabase($rid);
        if(!$dbOk){
            // notify owner/admin (non-blocking)
            sendMessage("⚠️ توجه: ایجاد دیتابیس اختصاصی برای ربات نمایندگی انجام نشد.\n\nاین یعنی ربات نمایندگی فعلاً با دیتابیس ربات مادر مشترک است.\nبرای درست شدن، یوزر دیتابیس باید دسترسی CREATE DATABASE داشته باشد.");
        }

        setUser("resellerAwaitAdmin_" . $rid, "step");

        sendMessage("🟥🟥🟥🟥🟥🟥 50%

✅ توکن دریافت شد.

حالا ایدی عددی تلگرام خود را از این ربات دریافت کنید @userinfobot و برای ما بفرستید
⚠️توجه کنید⚠️ ایدی عددی باید فقط عدد باشه و هیج چیز اضافه دیگه ای نباشه");
        exit;
    }

    if(preg_match('/^resellerAwaitAdmin_(\d+)$/', $userInfo['step'] ?? '', $mm)){
        ensureResellerTables();
        $rid = (int)$mm[1];
        $adminId = trim($text);

        if(!preg_match('/^\d+$/', $adminId)){
            sendMessage("❌ فقط عدد ارسال کن (بدون متن اضافه).");
            exit;
        }

        $stmt = $connection->prepare("UPDATE `reseller_bots` SET `admin_userid`=? WHERE `id`=? AND `owner_userid`=?");
        $aid = (int)$adminId;
        $stmt->bind_param("iii", $aid, $rid, $from_id);
        $stmt->execute();
        $stmt->close();

// ضمانت نمایش 100% حتی اگر مراحل بعدی (وبهوک/کوئری‌ها) خطا بخورن یا کند بشن
@sendMessage("🟩🟩🟩🟩🟩🟩 100%\n\n✅ ربات با موفقیت فعال شد.\n
ℹ️ تنظیمات نهایی در پس‌زمینه انجام می‌شود...", null, "Markdown");



        // set webhook for the new bot to this same handler, with bid param
        $hookUrl = $botUrl . "bot.php?bid=" . $rid;
        $rowRes = $connection->query("SELECT `bot_token`,`expires_at`,`bot_username`,`admin_userid`,`db_name`,`owner_userid` FROM `reseller_bots` WHERE `id`={$rid} LIMIT 1");
        $row = $rowRes ? $rowRes->fetch_assoc() : null;
        if(!is_array($row)){
            $row = ['bot_token'=>'','expires_at'=>0,'bot_username'=>null,'admin_userid'=>$aid];
        }
        setUser("none", "step");
        $expAt = (int)($row['expires_at'] ?? 0);
        $exp = $expAt > 0 ? jdate('Y/m/d H:i', $expAt) : '---';
        $uname = !empty($row['bot_username']) ? '@'.$row['bot_username'] : '---';


// گزارش ساخت ربات نمایندگی برای مدیران
$dbn = !empty($row['db_name']) ? $row['db_name'] : '---';
$reportTxt = "📌 گزارش ساخت ربات نمایندگی\n\n"
    ."شناسه ربات (RID): {$rid}\n"
    ."یوزرنیم ربات: {$uname}\n"
    ."نام دیتابیس: {$dbn}\n"
    ."آیدی عددی سازنده: {$from_id}\n"
    ."آیدی عددی ادمین ربات: {$row['admin_userid']}\n"
    ."تاریخ انقضا: {$exp}";
$adminIds = getAllAdminIds();
foreach($adminIds as $aidReport){
    // به سازنده هم گزارش دوباره نده
    if((int)$aidReport === (int)$from_id) continue;
    @sendMessage($reportTxt, null, "Markdown", $aidReport);
}


        sendMessage("✅ اطلاعات ربات شما:

"
            ."یوزرنیم ربات: {$uname}
"
            ."آیدی عددی ادمین: {$row['admin_userid']}
"
            ."تاریخ انقضا: {$exp}

"
            ."از این به بعد میتونی ربات‌هات رو از بخش «{$buttonValues['my_reseller_bots']}» مدیریت کنی.");

        // Finalize (setWebhook + admin report) in background to avoid webhook timeouts
        $worker = __DIR__ . "/reseller_finalize_worker.php";
        if(file_exists($worker)){
            $cmd = "nohup php " . escapeshellarg($worker) . " " . escapeshellarg((string)$rid) . " > /dev/null 2>&1 &";
            @shell_exec($cmd);
        }

        exit;
    }
}



// --- Admin: reseller bots create / transfer steps
if(!$isChildBot && isset($text) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(preg_match('/^adminResBotTransfer_(\d+)$/', $userInfo['step'] ?? '', $mm)){
        ensureResellerTables();
        $rid=(int)$mm[1];
        $newOwner=trim($text);
        if(!preg_match('/^\d+$/',$newOwner)){
            sendMessage("❌ فقط آیدی عددی ارسال کنید.");
            exit;
        }
        $no=(int)$newOwner;
        $stmt=$connection->prepare("UPDATE reseller_bots SET owner_userid=? WHERE id=?");
        $stmt->bind_param("ii",$no,$rid);
        $stmt->execute();
        $stmt->close();
        setUser("none","step");
        sendMessage("✅ انتقال انجام شد. (ربات #$rid)");
        exit;
    }

    if(($userInfo['step'] ?? '') == "adminResBotsCreateUser"){
        ensureResellerTables();
        $owner=trim($text);
        if(!preg_match('/^\d+$/',$owner)){
            sendMessage("❌ فقط آیدی عددی ارسال کنید.");
            exit;
        }
        // ask plan
        $res=$connection->query("SELECT * FROM reseller_plans WHERE is_active=1 ORDER BY id ASC");
        $rows=[];
        if($res){
            while($p=$res->fetch_assoc()){
                $rows[]=[['text'=>$p['title']." - ".number_format($p['price'])." تومان",'callback_data'=>"adminResBotsCreatePlan_" . (int)$owner . "_" . $p['id']]];
            }
        }
        $rows[]=[['text'=>$buttonValues['cancel'],'callback_data'=>"adminResellerBots"]];
        setUser("none","step");
        sendMessage("پلن را انتخاب کنید:", ['inline_keyboard'=>$rows]);
        exit;
    }
}



// --- Admin: reseller plans add/edit steps (messages)
if(!$isChildBot && isset($text) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(($userInfo['step'] ?? '') == "adminResPlanAdd_title"){
        $title = trim($text);
        if($title==''){ sendMessage("❌ عنوان نامعتبر"); exit; }
        setUser($title,"temp");
        setUser("adminResPlanAdd_days","step");
        sendMessage("تعداد روز پلن را ارسال کنید (مثلا 30):");
        exit;
    }
    if(($userInfo['step'] ?? '') == "adminResPlanAdd_days"){
        $days = (int)trim($text);
        if($days<=0){ sendMessage("❌ تعداد روز نامعتبر"); exit; }
        setUser($days,"temp2");
        setUser("adminResPlanAdd_price","step");
        sendMessage("قیمت پلن (تومان) را ارسال کنید (عدد):");
        exit;
    }
    if(($userInfo['step'] ?? '') == "adminResPlanAdd_price"){
        ensureResellerTables();
        $price = (int)preg_replace('/\D/','', $text);
        $title = $userInfo['temp'] ?? '';
        $days = (int)($userInfo['temp2'] ?? 30);
        if($title==''){ sendMessage("❌ اطلاعات نامعتبر"); exit; }
        $now=time();
        $stmt=$connection->prepare("INSERT INTO reseller_plans (title, days, price, is_active, created_at) VALUES (?,?,?,?,?)");
        $active=1;
        $stmt->bind_param("siiii",$title,$days,$price,$active,$now);
        $stmt->execute();
        $stmt->close();
        setUser("none","step"); setUser("","temp"); setUser("","temp2");
        sendMessage("✅ پلن اضافه شد.");
        exit;
    }

    if(preg_match('/^adminResPlanEdit_(\d+)_title$/', $userInfo['step'] ?? '', $mm)){
        ensureResellerTables();
        $pid=(int)$mm[1];
        $title=trim($text);
        if($title==''){ sendMessage("❌ عنوان نامعتبر"); exit; }
        setUser($title,"temp");
        setUser("adminResPlanEdit_" . $pid . "_days","step");
        sendMessage("تعداد روز جدید را ارسال کنید:");
        exit;
    }
    if(preg_match('/^adminResPlanEdit_(\d+)_days$/', $userInfo['step'] ?? '', $mm)){
        $pid=(int)$mm[1];
        $days=(int)trim($text);
        if($days<=0){ sendMessage("❌ تعداد روز نامعتبر"); exit; }
        setUser($days,"temp2");
        setUser("adminResPlanEdit_" . $pid . "_price","step");
        sendMessage("قیمت جدید (تومان) را ارسال کنید:");
        exit;
    }
    if(preg_match('/^adminResPlanEdit_(\d+)_price$/', $userInfo['step'] ?? '', $mm)){
        ensureResellerTables();
        $pid=(int)$mm[1];
        $price=(int)preg_replace('/\D/','', $text);
        $title=$userInfo['temp'] ?? '';
        $days=(int)($userInfo['temp2'] ?? 30);
        $stmt=$connection->prepare("UPDATE reseller_plans SET title=?, days=?, price=? WHERE id=?");
        $stmt->bind_param("siii",$title,$days,$price,$pid);
        $stmt->execute();
        $stmt->close();
        setUser("none","step"); setUser("","temp"); setUser("","temp2");
        sendMessage("✅ ویرایش شد.");
        exit;
    }
}

if(strstr($text, "/start ")){
    $inviter = str_replace("/start ", "", $text);
    if($inviter < 0) exit();
    if($uinfo->num_rows == 0 && $inviter != $from_id){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $inviter);
        $stmt->execute();
        $inviterInfo = $stmt->get_result();
        $stmt->close();
        
        if($inviterInfo->num_rows > 0){
            $first_name = !empty($first_name)?$first_name:" ";
            $username = !empty($username)?$username:" ";
            if($uinfo->num_rows == 0){
                $sql = "INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`, `refered_by`)
                                    VALUES (?,?,?, 0,0,?,?)";
                $stmt = $connection->prepare($sql);
                $time = time();
                $stmt->bind_param("issii", $from_id, $first_name, $username, $time, $inviter);
                $stmt->execute();
                $stmt->close();
            }else{
                $refcode = time();
                $sql = "UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?";
                $stmt = $connection->prepare($sql);
                $stmt->bind_param("si", $inviter, $from_id);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $uinfo = $stmt->get_result();
            $userInfo = $uinfo->fetch_assoc();
            $stmt->close();
            
            setUser("referedBy" . $inviter);
            $userInfo['step'] = "referedBy" . $inviter;
            sendMessage($mainValues['invited_user_joined_message'],null,null, $inviter);
        }
    }
    
    $text = "/start";
}
if($userInfo['phone'] == null && $from_id != $admin && $userInfo['isAdmin'] != true && $botState['requirePhone'] == "on"){
    if(isset($update->message->contact)){
        $contact = $update->message->contact;
        $phone_number = $contact->phone_number;
        $phone_id = $contact->user_id;
        if($phone_id != $from_id){
            sendMessage($mainValues['please_select_from_below_buttons']);
            exit();
        }else{
            if(!preg_match('/^\+98(\d+)/',$phone_number) && !preg_match('/^98(\d+)/',$phone_number) && !preg_match('/^0098(\d+)/',$phone_number) && $botState['requireIranPhone'] == 'on'){
                sendMessage($mainValues['use_iranian_number_only']);
                exit();
            }
            setUser($phone_number, 'phone');
            
            sendMessage($mainValues['phone_confirmed'],$removeKeyboard);
            $text = "/start";
            
            $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $uinfo = $stmt->get_result();
            $userInfo = $uinfo->fetch_assoc();
            $stmt->close();
        }
    }else{
        sendMessage($mainValues['send_your_phone_number'], json_encode([
			'keyboard' => [[[
					'text' => $buttonValues['send_phone_number'],
					'request_contact' => true,
				]]],
			'resize_keyboard' => true
		]));
		exit();
    }
}
if(preg_match('/^\/([Ss]tart)/', $text) or $text == $buttonValues['back_to_main'] or $data == 'mainMenu') {
    setUser();
    setUser("", "temp"); 
    if(isset($data) and $data == "mainMenu"){
        $res = smartSendOrEdit($message_id, $mainValues['start_message'], getMainKeys());
        if(!$res->ok){
            sendMessage($mainValues['start_message'], getMainKeys());
        }
    }else{
        if($from_id != $admin && empty($userInfo['first_start'])){
            setUser('sent','first_start');
            $keys = json_encode(['inline_keyboard'=>[
                [['text'=>$buttonValues['send_message_to_user'],'callback_data'=>'sendMessageToUser' . $from_id]]
            ]]);
    
            sendToAdmins(str_replace(["FULLNAME", "USERNAME", "USERID"], ["<a href='tg://user?id=$from_id'>$first_name</a>", $username, $from_id], $mainValues['new_member_joined'])
                ,$keys, "html");
        }
        sendMessage($mainValues['start_message'],getMainKeys());
    }
}

// ------------------------------------------------------------
// Admin panel: support BOTH inline keyboards (callback_data) and
// reply keyboards (plain text buttons).
// Many installations use ReplyKeyboardMarkup for admin menus;
// in that case $data is empty and only $text is populated.
// ------------------------------------------------------------
if(($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true) && (empty($data) || $data === null)){
    $t = trim((string)$text);
    // normalize common Persian/RTL invisible chars
    $t = str_replace(["\xE2\x80\x8C", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAC"], '', $t);

    // loose matching for buttons in older themes
    if(
        $t === 'ربات ها🤖' || $t === 'ربات ها 🤖' || $t === '🤖 ربات ها' || $t === '🤖 ربات' ||
        $t === '🤖 ربات‌ها' || $t === 'ربات‌ها' || $t === 'ربات ها' || $t === 'ربات‌ها' ||
        $t === 'مدیریت ربات 🤖' || $t === 'مدیریت ربات' || $t === '🤖 مدیریت ربات' ||
        $t === 'مدیریت ربات ها 🤖' || $t === '🤖 مدیریت ربات ها' || $t === 'مدیریت ربات ها' ||
        $t === 'مدیریت ربات‌ها' || $t === '🤖 مدیریت ربات‌ها'
    ){
        resetUserFlow();
        // In child/reseller bots, don't show/route reseller-bots management menu
        if(empty($isChildBot)){
            $data = 'adminResellerBots';
        }
    }

    // Reseller plans management (admin)
    if($t === 'افزودن پلن' || $t === '➕ افزودن پلن' || $t === '➕ افزودن پلن نمایندگی' || $t === 'افزودن پلن نمایندگی'){
        setUser('none','step');
        $data = 'addResellerPlan';
    }
    if($t === 'پلن های نمایندگی' || $t === '📦 پلن های نمایندگی' || $t === 'مدیریت پلن های نمایندگی' || $t === 'پلن‌های نمایندگی'){
        setUser('none','step');
        // unify route name to the actual handler below
        $data = 'adminResPlans';
    }
    if($t === 'بکاپ 🗄' || $t === '🗄 بکاپ' || $t === 'بکاپ' || $t === 'مدیریت بکاپ 🗄' || $t === 'مدیریت بکاپ'){
        $data = 'adminBackupMenu';
    }

    // Admin reseller bots menu (reply keyboard fallbacks)
    if($t === '📋 لیست ربات ها' || $t === 'لیست ربات ها' || $t === 'لیست ربات ها 📋' || $t === 'لیست ربات‌ها'){
        $data = 'adminResBotsList_0';
    }
    if($t === '➕ ساخت ربات جدید' || $t === '➕ ساخت ربات'){
        $data = 'adminResBotsCreate';
    }
    if($t === '🔙 بازگشت به پنل مدیریت' || $t === 'بازگشت به پنل مدیریت' || $t === '😪 منصرف شدم بیخیال' || $t === 'منصرف شدم بیخیال 😪' || $t === 'منصرف شدم بیخیال'){
        setUser('none','step');
        $data = 'managePanel';
    }
}

// ===== User text keyboard routing for reseller bot management =====
if(!($from_id == $admin || ($userInfo['isAdmin'] ?? false) == true) && (empty($data) || $data === null)){
    $t2 = trim((string)$text);
    $t2 = str_replace(["\xE2\x80\x8C", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAC"], '', $t2);
    if($t2 === 'مدیریت ربات' || $t2 === 'مدیریت ربات 🤖' || $t2 === 'ربات نمایندگی 🤖' || $t2 === '🤖 ربات نمایندگی' || $t2 === 'ربات نمایندگی'){
        // For normal users, send them to their reseller bots list (or purchase flow)
        $data = 'myResellerBots';
    }
}



function smartSendOrEdit($msgId, $txt, $keys = null, $parse_mode = null){
    // If we have a callback context (inline button) we can edit the message.
    // If it's a normal text keyboard / message, we send a new message.
    global $chat_id;
    // Answer callback query to prevent Telegram clients from getting stuck on "Loading..."
    global $update;
    if(isset($update->callback_query) && !empty($update->callback_query->id)){
        bot('answerCallbackQuery', ['callback_query_id'=>$update->callback_query->id]);
    }


    // Normalize reply markup
    $replyMarkup = null;
    if($keys !== null){
        if(is_string($keys)){
            $replyMarkup = $keys;
        }else{
            $replyMarkup = json_encode($keys, JSON_UNESCAPED_UNICODE);
        }
    }

    if(!empty($GLOBALS['data']) && !empty($msgId)){
        $p = [
            'chat_id' => $chat_id,
            'message_id' => $msgId,
            'text' => $txt,
        ];
        if($parse_mode){ $p['parse_mode'] = $parse_mode; }
        if($replyMarkup){ $p['reply_markup'] = $replyMarkup; }
        return bot('editMessageText', $p);
    }

    $p = [
        'chat_id' => $chat_id,
        'text' => $txt,
    ];
    if($parse_mode){ $p['parse_mode'] = $parse_mode; }
    if($replyMarkup){ $p['reply_markup'] = $replyMarkup; }
    return bot('sendMessage', $p);
}





// NOTE: removed old ReplyKeyboard-based adminResellerBots menu.
// We use InlineKeyboard ("glass" buttons) below.


// ===== Admin Reseller Plans =====
if($data=='adminResPlans' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
        // Remove ReplyKeyboard (make UI glass/inline) when entering this menu
    if(!isset($update->callback_query) || empty($update->callback_query)){
        bot('sendMessage', [
            'chat_id'=>$chat_id,
            'text'=>' ',
            'reply_markup'=>json_encode(['remove_keyboard'=>true])
        ]);
    }

$res = $connection->query("SELECT * FROM reseller_plans ORDER BY id DESC");
    $msg = "📦 پلن های نمایندگی:\n\n";
    if(!$res || $res->num_rows==0){
        $msg .= "هیچ پلنی ثبت نشده.\n";
    }else{
        while($p = $res->fetch_assoc()){
            $status = ((int)$p['is_active']===1) ? "✅ فعال" : "⛔ غیرفعال";
            $msg .= "🆔 {$p['id']} | {$p['title']}\n⏳ {$p['days']} روز | 💰 ".number_format($p['price'])." تومان | {$status}\n\n";
        }
    }
    $ik = [
        'inline_keyboard'=>[
            [['text'=>'➕ افزودن پلن نمایندگی','callback_data'=>'addResellerPlan']],
            [['text'=>'🔄 فعال/غیرفعال کردن پلن','callback_data'=>'resellerPlanToggleMenu']],
            [['text'=>'🗑 حذف پلن','callback_data'=>'resellerPlanDeleteMenu']],
            [['text'=>'😪 منصرف شدم بیخیال','callback_data'=>'managePanel']]
        ]
    ];
    smartSendOrEdit($message_id, $msg, json_encode($ik));
    exit;
}

// Inline submenu: choose plan to toggle
if($data=='resellerPlanToggleMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $res = $connection->query("SELECT id,title,is_active FROM reseller_plans ORDER BY id DESC");
    $rows=[];
    if($res){
        while($p=$res->fetch_assoc()){
            $st = ((int)$p['is_active']===1) ? "✅" : "⛔";
            $rows[]=[[ 'text'=> $st." ".$p['title'], 'callback_data'=>"toggleResellerPlan_".$p['id'] ]];
        }
    }
    $rows[]=[[ 'text'=>$buttonValues['cancel'], 'callback_data'=>"adminResPlans" ]];
    smartSendOrEdit($message_id, "یک پلن برای تغییر وضعیت انتخاب کنید:", json_encode(['inline_keyboard'=>$rows]));
    exit;
}

if(preg_match('/^toggleResellerPlan_(\d+)$/',$data,$mm) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $pid = (int)$mm[1];
    $connection->query("UPDATE reseller_plans SET is_active = IF(is_active=1,0,1) WHERE id={$pid} LIMIT 1");
    smartSendOrEdit($message_id, "✅ وضعیت پلن تغییر کرد.", null);
    exit;
}

// Inline submenu: choose plan to delete
if($data=='resellerPlanDeleteMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $res = $connection->query("SELECT id,title FROM reseller_plans ORDER BY id DESC");
    $rows=[];
    if($res){
        while($p=$res->fetch_assoc()){
            $rows[]=[[ 'text'=> "🗑 ".$p['title'], 'callback_data'=>"delResellerPlan_".$p['id'] ]];
        }
    }
    $rows[]=[[ 'text'=>$buttonValues['cancel'], 'callback_data'=>"adminResPlans" ]];
    smartSendOrEdit($message_id, "یک پلن برای حذف انتخاب کنید:", json_encode(['inline_keyboard'=>$rows]));
    exit;
}

if(preg_match('/^delResellerPlan_(\d+)$/',$data,$mm) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $pid = (int)$mm[1];
    $connection->query("DELETE FROM reseller_plans WHERE id={$pid} LIMIT 1");
    smartSendOrEdit($message_id, "🗑 پلن حذف شد.", null);
    exit;
}

// Start add reseller plan flow (both inline + text)
if($data=='addResellerPlan' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addResellerPlan');
    delMessage();
    sendMessage("عنوان پلن، تعداد روز و قیمت را به صورت زیر ارسال کنید:\n\nعنوان-روز-قیمت\nمثال:\nپلن یک ماهه-30-500000", $cancelKey);
    exit;
}

if($userInfo['step']=='addResellerPlan' && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $parts = explode('-', trim($text));
    if(count($parts) < 3){
        sendMessage("فرمت اشتباه است.\n\nعنوان-روز-قیمت\nمثال:\nپلن یک ماهه-30-500000", $cancelKey);
        exit;
    }
    $title = trim($parts[0]);
    $days  = (int)trim($parts[1]);
    $price = (int)trim($parts[2]);
    if($title=='' || $days<=0 || $price<0){
        sendMessage("مقادیر نامعتبر است. دوباره تلاش کنید.\n\nعنوان-روز-قیمت", $cancelKey);
        exit;
    }
    $stmt = $connection->prepare("INSERT INTO reseller_plans (title,days,price,is_active,created_at) VALUES (?,?,?,?,?)");
    $isActive = 1;
    $now = time();
    $stmt->bind_param("siiii", $title, $days, $price, $isActive, $now);
    $stmt->execute();
    $stmt->close();
    setUser("none","step");
    sendMessage("✅ پلن نمایندگی ثبت شد.", null);
    exit;
}


// ===== Admin Backup Menu =====
if($data=='adminBackupMenu' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $abKey = getAutoBackupStateKey();
    $st = getSettingValue($abKey, '{"enabled":0,"last":0,"interval_min":1440}');
    $stj = json_decode($st, true);
    $enabled = (int)($stj['enabled'] ?? 0);
    $interval = (int)($stj['interval_min'] ?? 1440);
    if($interval < 1) $interval = 1;
    $keys = ['inline_keyboard'=>[
        [['text'=>($enabled?"✅ بکاپ خودکار: روشن":"❌ بکاپ خودکار: خاموش"),'callback_data'=>'adminBackupToggle']],
        [['text'=>"⏱ تنظیم فاصله بکاپ (الان: {$interval} دقیقه)",'callback_data'=>'adminBackupSetInterval']],
        [['text'=>'🗄 بکاپ دستی (همین الان)','callback_data'=>'adminBackupGet']],
        [['text'=>'📤 افزودن/بازگردانی بکاپ','callback_data'=>'adminBackupRestore']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'managePanel']],
    ]];
    smartSendOrEdit(
        $message_id,
        "🗄 مدیریت بکاپ\n\n".
        "- بکاپ خودکار: هر {$interval} دقیقه (در صورت فعال بودن)\n".
        "- بکاپ دستی: همین الان فایل SQL ارسال می‌شود\n".
        "- بازگردانی: فایل SQL را ارسال کنید",
        $keys
    );
}

if($data=='adminBackupToggle' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $abKey = getAutoBackupStateKey();
    $st = getSettingValue($abKey, '{"enabled":0,"last":0,"interval_min":1440}');
    $stj = json_decode($st, true);
    $enabled = (int)($stj['enabled'] ?? 0);
    $stj['enabled'] = $enabled ? 0 : 1;
    upsertSettingValue($abKey, json_encode($stj, JSON_UNESCAPED_UNICODE));
    alert($stj['enabled']?"بکاپ خودکار روشن شد":"بکاپ خودکار خاموش شد");
    $data = 'adminBackupMenu';
}

if($data=='adminBackupSetInterval' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('awaiting_backup_interval','step');
    smartSendOrEdit(
        $message_id,
        "⏱ فاصله بکاپ خودکار را به دقیقه ارسال کنید.\n\nمثال: 30\n(حداقل 1 دقیقه)",
        ['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'adminBackupMenu']]]]
    );
}

if($data=='adminBackupGet' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    // Run backup asynchronously to avoid webhook/callback timeouts (prevents bot "hang")
    alert('✅ درخواست بکاپ ثبت شد. در حال آماده‌سازی...');

    $tokenToUse = $GLOBALS['botToken'] ?? ($botToken ?? null);
    if(!$tokenToUse){
        alert('❌ توکن ربات پیدا نشد.', true);
        exit;
    }

    if(isShellExecAvailable()){
        $worker = __DIR__ . '/backup_worker.php';
        $dbToUse = $GLOBALS['dbName'] ?? ($dbName ?? '');
        $cmd = 'nohup php ' . escapeshellarg($worker) . ' backup ' . escapeshellarg($tokenToUse) . ' ' . escapeshellarg($from_id) . ' ' . escapeshellarg('deltabot_backup') . ' ' . escapeshellarg($dbToUse) . ' >/dev/null 2>&1 &';
        @shell_exec($cmd);
    }else{
        // Fallback (sync) - may take time on large DB
        $tmp = dbCreateSqlBackupFile('deltabot_backup');
        if(!$tmp){
            alert('❌ خطا در ساخت بکاپ (دسترسی/محدودیت هاست یا دیتابیس)', true);
        }else{
            if(@filesize($tmp) > 49*1024*1024){
                alert('❌ حجم بکاپ خیلی زیاد است و تلگرام اجازه ارسال نمی‌دهد.', true);
            }else{
                $sd = sendDocument($tmp, "🗄 بکاپ دیتابیس\n".date('Y-m-d H:i:s'));
                if(isset($sd['ok']) && $sd['ok']){
                    @unlink($tmp);
                    alert('✅ بکاپ ارسال شد');
                }else{
                    alert('❌ ارسال بکاپ ناموفق بود.', true);
                }
            }
        }
    }
}


if($data=='adminBackupRestore' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('awaiting_backup_sql','step');
    smartSendOrEdit($message_id, "📤 فایل بکاپ SQL را همینجا ارسال کنید.\n\n⚠️ توجه: بازگردانی بکاپ باعث جایگزینی کامل دیتابیس فعلی می‌شود.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'adminBackupMenu']]]]);
}

if(preg_match('/^adminResBotsList_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $page = (int)$m[1];
    $per = 15;
    $off = $page*$per;
    $res = $connection->query("SELECT rb.*, u.name as uname FROM reseller_bots rb LEFT JOIN users u ON u.userid=rb.owner_userid WHERE rb.status=1 ORDER BY rb.id DESC LIMIT $off, $per");
    if(!$res){
        smartSendOrEdit($message_id, "❌ خطای دیتابیس:
".$connection->error, ['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>'adminResellerBots']]]]);
        return;
    }
    $rows = [];
    if($res && $res->num_rows>0){
        while($b = $res->fetch_assoc()){
            $uname = $b['bot_username'] ? '@'.$b['bot_username'] : '---';
            $rows[] = [['text'=>$uname." | #".$b['id'],'callback_data'=>"adminResBot_" . $b['id']]];
        }
    }else{
        $rows[] = [['text'=>"موردی یافت نشد",'callback_data'=>"noop"]];
    }
    $nav=[];
    if($page>0) $nav[]=['text'=>"⬅️ قبلی",'callback_data'=>"adminResBotsList_" . ($page-1)];
    $nav[]=['text'=>"➡️ بعدی",'callback_data'=>"adminResBotsList_" . ($page+1)];
    $rows[]=$nav;
    $rows[]=[['text'=>$buttonValues,'callback_data'=>"adminResellerBots"]];
    smartSendOrEdit($message_id, "📋 لیست ربات ها", ['inline_keyboard'=>$rows]);
}

if(preg_match('/^adminResBot_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    $res=$connection->query("SELECT rb.*, u.name as uname FROM reseller_bots rb LEFT JOIN users u ON u.userid=rb.owner_userid WHERE rb.id=$rid LIMIT 1");
    $b=$res?$res->fetch_assoc():null;
    if(!$b){
        smartSendOrEdit($message_id,"❌ پیدا نشد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
    }else{
        $exp=jdate('Y/m/d H:i',(int)$b['expires_at']);
        $uname=$b['bot_username']?'@'.$b['bot_username']:'---';
        $txt="🤖 مشخصات ربات

"
            ."شناسه: #{$b['id']}
"
            ."یوزرنیم: {$uname}
"
            ."مالک: {$b['owner_userid']} ".($b['uname']?("({$b['uname']})"):"")."
"
            ."ادمین: {$b['admin_userid']}
"
            ."انقضا: {$exp}
";
        $keys=['inline_keyboard'=>[
            [['text'=>"🔁 تمدید",'callback_data'=>"adminResBotRenew_" . $b['id']]],
            [['text'=>"🔄 انتقال",'callback_data'=>"adminResBotTransfer_" . $b['id']]],
            [['text'=>"🗑 حذف",'callback_data'=>"adminResBotDelete_" . $b['id']]],
            [['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]],
        ]];
        smartSendOrEdit($message_id,$txt,$keys,"HTML");
    }
}

if(preg_match('/^adminResBotDelete_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    $res=$connection->query("SELECT * FROM reseller_bots WHERE id=$rid LIMIT 1");
    $b=$res?$res->fetch_assoc():null;
    if($b && !empty($b['bot_token'])) botWithToken($b['bot_token'],"setWebhook",['url'=>'']);
    $connection->query("UPDATE reseller_bots SET status=0 WHERE id=$rid");
    smartSendOrEdit($message_id,"✅ حذف شد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
}

if(preg_match('/^adminResBotRenew_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    // choose plan days to extend
    $res=$connection->query("SELECT * FROM reseller_plans WHERE is_active=1 ORDER BY id ASC");
    $rows=[];
    if($res){
        while($p=$res->fetch_assoc()){
            $rows[]=[['text'=>$p['title']." (+{$p['days']} روز)",'callback_data'=>"adminResBotDoRenew_" . $rid . "_" . $p['id']]];
        }
    }
    $rows[]=[['text'=>$buttonValues,'callback_data'=>"adminResBot_" . $rid]];
    smartSendOrEdit($message_id,"پلن تمدید را انتخاب کنید:",['inline_keyboard'=>$rows]);
}

if(preg_match('/^adminResBotDoRenew_(\d+)_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1]; $pid=(int)$m[2];
    $b=$connection->query("SELECT * FROM reseller_bots WHERE id=$rid LIMIT 1")->fetch_assoc();
    $p=$connection->query("SELECT * FROM reseller_plans WHERE id=$pid LIMIT 1")->fetch_assoc();
    if(!$b || !$p){
        smartSendOrEdit($message_id,"❌ پیدا نشد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
    }else{
        $base=(int)$b['expires_at']; if($base<time()) $base=time();
        $newExp=$base + ((int)$p['days']*86400);
        $connection->query("UPDATE reseller_bots SET expires_at=$newExp WHERE id=$rid");
        smartSendOrEdit($message_id,"✅ تمدید شد.
انقضا: ".jdate('Y/m/d H:i',$newExp),['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBot_" . $rid]]]]);
    }
}

if(preg_match('/^adminResBotTransfer_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $rid=(int)$m[1];
    setUser("adminResBotTransfer_" . $rid,"step");
    smartSendOrEdit($message_id,"آیدی عددی کاربر جدید را ارسال کنید:",['inline_keyboard'=>[[['text'=>$buttonValues['cancel'],'callback_data'=>"adminResBot_" . $rid]]]]);
}

if($data=='adminResBotsCreate' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    setUser("adminResBotsCreateUser","step");
    smartSendOrEdit($message_id,"آیدی عددی کاربر مالک را ارسال کنید:",['inline_keyboard'=>[[['text'=>$buttonValues['cancel'],'callback_data'=>"adminResellerBots"]]]]);
}

// ---------------- Reseller Bot Shop (main bot only) ----------------
if(!$isChildBot && $data == "resellerShop"){
    ensureResellerTables();
    // list active plans
    $plans = [];
    $res = $connection->query("SELECT * FROM `reseller_plans` WHERE `is_active`=1 ORDER BY `id` ASC");
    if($res){
        while($row = $res->fetch_assoc()){
            $plans[] = $row;
        }
    }
    if(count($plans) == 0){
        smartSendOrEdit($message_id, "❌ پلنی برای ربات نمایندگی تعریف نشده است.

از ادمین بخواهید از پنل مدیریت اضافه کند.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        $rows = [];
        foreach($plans as $p){
            $title = $p['title'] . " - " . number_format($p['price']) . " تومان";
            $rows[] = [['text'=>$title,'callback_data'=>"resPlan_" . $p['id']]];
        }
        $rows[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']];
        smartSendOrEdit($message_id, "🤖 ربات نمایندگی

یکی از پلن‌ها رو انتخاب کن:", ['inline_keyboard'=>$rows]);
    }
}

if(!$isChildBot && preg_match('/^resPlan_(\d+)/',$data,$m)){
    ensureResellerTables();
    $pid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_plans` WHERE `id`=? AND `is_active`=1 LIMIT 1");
    $stmt->bind_param("i",$pid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$plan){
        smartSendOrEdit($message_id, "❌ پلن پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        $about = "با سلام🩵
"
        ."شما درحال خرید ربات نمایندگی هستید ربات نمایندگی یهنی چی؟
"
        ."یعنی شما دقیقا رباتی مثل ربات ما خریداری میکنید جهت فروش vpn و اشتراک هاتون کپی ربات ما هست میتوانید روی این ربات پنل های خودتونو بزنید و پلن و لیست بزارید جهت فروش 
"
        ."توی ربات خودتون هیچ اسمی از ما برده نشده و مختص خودتان هست لطفا قبل خرید حتما ربات رو کامل بررسی کنید سپس روی دکمه زیر بزنید و مابقی مراحل خرید رو برید🙏🌸

"
        ."پلن انتخابی: " . $plan['title'] . "
"
        ."مدت: " . $plan['days'] . " روز
"
        ."هزینه: " . number_format($plan['price']) . " تومان";
        $keys = ['inline_keyboard'=>[
            [['text'=>"✅ موافقم و پرداخت",'callback_data'=>"resAgreePay_" . $plan['id']]],
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]
        ]];
        smartSendOrEdit($message_id, $about, $keys, "HTML");
    }
}

if(!$isChildBot && preg_match('/^resAgreePay_(\d+)/',$data,$m)){
    ensureResellerTables();
    $pid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_plans` WHERE `id`=? AND `is_active`=1 LIMIT 1");
    $stmt->bind_param("i",$pid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$plan){
        smartSendOrEdit($message_id, "❌ پلن پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        $need = (int)$plan['price'];
        $wallet = (int)($userInfo['wallet'] ?? 0);
        if($wallet < $need){
            smartSendOrEdit($message_id, "❌ موجودی کیف پول شما کافی نیست.

موجودی فعلی: ".number_format($wallet)." تومان
هزینه پلن: ".number_format($need)." تومان

ابتدا کیف پول را شارژ کنید.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
        }else{
            // deduct wallet
            $newWallet = $wallet - $need;
            $stmt = $connection->prepare("UPDATE `users` SET `wallet`=? WHERE `userid`=?");
            $stmt->bind_param("ii",$newWallet,$from_id);
            $stmt->execute();
            $stmt->close();

            $now = time();
            $expires = $now + ((int)$plan['days'] * 86400);

            // create pending reseller bot record (token/admin to be collected)
            $stmt = $connection->prepare("INSERT INTO `reseller_bots` (`owner_userid`,`bot_token`,`admin_userid`,`created_at`,`expires_at`,`status`) VALUES (?,?,?,?,?,1)");
            $emptyToken = '';
            $zero = 0;
            $stmt->bind_param("isiii",$from_id,$emptyToken,$zero,$now,$expires);
            $stmt->execute();
            $rid = $stmt->insert_id;
            $stmt->close();

            setUser("resellerAwaitToken_" . $rid, "step");
            smartSendOrEdit($message_id, "✅ پرداخت با موفقیت انجام شد.

لطفا توکن ربات خود را وارد کنید✅
شما میتوانید با مراجعه به این ربات @BotFather و استارت ربات سپس‌با زدن دکمه /newbot اقدام به ساخت ربات کنید و در اخر به شما یه توکن (API) میده اونو برای ما بفرستید", ['inline_keyboard'=>[[['text'=>$buttonValues['cancel'],'callback_data'=>'mainMenu']]]]);
        }
    }
}

if(!$isChildBot && preg_match('/^myResellerBots$/',$data)){
    ensureResellerTables();
    // Show all bots (active + inactive) so user can فعال/غیرفعال کند
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `owner_userid`=? AND `is_deleted`=0 ORDER BY `id` DESC LIMIT 50");
    $stmt->bind_param("i",$from_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    if($res && $res->num_rows>0){
        while($b = $res->fetch_assoc()){
            $uname = $b['bot_username'] ? '@'.$b['bot_username'] : 'بدون یوزرنیم';
            $st = ((int)$b['status'] === 1) ? '🟢' : '🔴';
            $rows[] = [['text'=> $st." ".$uname . " | #" . $b['id'], 'callback_data'=>"myResBot_" . $b['id']]];
        }
    }else{
        $rows[] = [['text'=>"هیچ رباتی ندارید",'callback_data'=>"noop"]];
    }
    $rows[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']];
    smartSendOrEdit($message_id, "🤖 ربات های من", ['inline_keyboard'=>$rows]);
}

if(!$isChildBot && preg_match('/^myResBot_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `is_deleted`=0 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$b){
        smartSendOrEdit($message_id, "❌ ربات پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        $uname = $b['bot_username'] ? '@'.$b['bot_username'] : '---';
        $exp = jdate('Y/m/d H:i', $b['expires_at']);
        $adminid = (int)$b['admin_userid'];
        $txt = "🤖 مشخصات ربات

"
            ."شناسه: #{$b['id']}
"
            ."یوزرنیم: {$uname}
"
            ."آیدی عددی ادمین: {$adminid}
"
            ."تاریخ انقضا: {$exp}
";
        $isActive = ((int)$b['status'] === 1);
        $toggleTxt = $isActive ? 'غیرفعال کردن 🔴' : 'فعال کردن 🟢';
        $toggleCb  = $isActive ? ("resDisable_".$b['id']) : ("resEnable_".$b['id']);
        $keys = ['inline_keyboard'=>[
            [['text'=>"🔄 بروزرسانی",'callback_data'=>"resUpdate_" . $b['id']]],
            [['text'=>$toggleTxt,'callback_data'=>$toggleCb]],
            [['text'=>"🔁 تمدید",'callback_data'=>"resRenew_" . $b['id']]],
            [['text'=>"🗑 حذف",'callback_data'=>"resDelete_" . $b['id']]],
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>'myResellerBots']]
        ]];
        smartSendOrEdit($message_id, $txt, $keys, "HTML");
    }
}

// Enable/disable reseller bot (owner)
if(!$isChildBot && preg_match('/^resDisable_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `is_deleted`=0 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$b){
        alert('❌ ربات پیدا نشد', true);
        exit;
    }
    if(!empty($b['bot_token'])){
        @botWithToken($b['bot_token'], 'setWebhook', ['url'=>'']);
    }
    $connection->query("UPDATE `reseller_bots` SET `status`=0 WHERE `id`={$rid} LIMIT 1");
    alert('✅ ربات غیرفعال شد');
    $data = 'myResBot_'.$rid;
}

if(!$isChildBot && preg_match('/^resEnable_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `is_deleted`=0 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$b){
        alert('❌ ربات پیدا نشد', true);
        exit;
    }
    $exp = (int)($b['expires_at'] ?? 0);
    if($exp > 0 && time() > $exp){
        alert('⛔️ ربات منقضی شده است. ابتدا تمدید کنید.', true);
        exit;
    }
    // re-set webhook
    if(!empty($b['bot_token'])){
        $hookUrl = $botUrl . "bot.php?bid=" . $rid;
        @botWithToken($b['bot_token'], 'setWebhook', ['url'=>$hookUrl]);
    }
    $connection->query("UPDATE `reseller_bots` SET `status`=1 WHERE `id`={$rid} LIMIT 1");
    alert('✅ ربات فعال شد');
    $data = 'myResBot_'.$rid;
}

// Update reseller bot (simulate progress + refresh webhook)
if(!$isChildBot && preg_match('/^resUpdate_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `is_deleted`=0 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$b){
        alert('❌ ربات پیدا نشد', true);
        exit;
    }

    // progress animation (quick)
    $steps = [0, 25, 50, 75, 100];
    foreach($steps as $p){
        $barCount = (int)round($p/10);
        $bar = str_repeat('🟩', $barCount) . str_repeat('⬜️', 10-$barCount);
        $msg = "🔄 بروزرسانی ربات\n\n{$bar}  {$p}%";
        smartSendOrEdit($message_id, $msg, ['inline_keyboard'=>[[['text'=>'⏳ در حال بروزرسانی...','callback_data'=>'noop']]]]);
        usleep(350000);
    }

    // refresh webhook to ensure it points to the latest handler
    if(!empty($b['bot_token'])){
        $hookUrl = $botUrl . "bot.php?bid=" . $rid;
        @botWithToken($b['bot_token'], 'setWebhook', ['url'=>$hookUrl]);
    }

    smartSendOrEdit($message_id, "✅ بروزرسانی انجام شد.\n\nاز این به بعد ربات شما دقیقا از امکانات نسخه مادر استفاده می‌کند.", ['inline_keyboard'=>[[['text'=>'بازگشت 🔙','callback_data'=>'myResBot_'.$rid]]]]);
}

if(!$isChildBot && preg_match('/^resDelete_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `is_deleted`=0 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$b){
        smartSendOrEdit($message_id, "❌ ربات پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        // disable webhook and mark inactive
        if(!empty($b['bot_token'])){
            botWithToken($b['bot_token'], "setWebhook", ['url'=>'']);
        }
        $stmt = $connection->prepare("UPDATE `reseller_bots` SET `status`=0, `is_deleted`=1, `deleted_at`=? WHERE `id`=?");
        $now = time();
        $stmt->bind_param("ii",$now,$rid);
        $stmt->execute();
        $stmt->close();
        smartSendOrEdit($message_id, "✅ ربات حذف شد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'myResellerBots']]]]);
    }
}

if(!$isChildBot && preg_match('/^resRenew_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    // show plan list for renew (same as shop)
    $res = $connection->query("SELECT * FROM `reseller_plans` WHERE `is_active`=1 ORDER BY `id` ASC");
    $rows = [];
    if($res){
        while($p = $res->fetch_assoc()){
            $rows[] = [['text'=>$p['title']." - ".number_format($p['price'])." تومان",'callback_data'=>"resDoRenew_" . $rid . "_" . $p['id']]];
        }
    }
    $rows[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"myResBot_" . $rid]];
    smartSendOrEdit($message_id, "پلن تمدید را انتخاب کنید:", ['inline_keyboard'=>$rows]);
}

if(!$isChildBot && preg_match('/^resDoRenew_(\d+)_(\d+)/',$data,$m)){
    ensureResellerTables();
    $rid = (int)$m[1];
    $pid = (int)$m[2];

    $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `owner_userid`=? AND `status`=1 LIMIT 1");
    $stmt->bind_param("ii",$rid,$from_id);
    $stmt->execute();
    $b = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `reseller_plans` WHERE `id`=? AND `is_active`=1 LIMIT 1");
    $stmt->bind_param("i",$pid);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$b || !$plan){
        smartSendOrEdit($message_id, "❌ اطلاعات پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>'mainMenu']]]]);
    }else{
        $need = (int)$plan['price'];
        $wallet = (int)($userInfo['wallet'] ?? 0);
        if($wallet < $need){
            smartSendOrEdit($message_id, "❌ موجودی کافی نیست.
موجودی: ".number_format($wallet)." تومان
هزینه: ".number_format($need)." تومان", ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"myResBot_" . $rid]]]]);
        }else{
            $newWallet = $wallet - $need;
            $stmt = $connection->prepare("UPDATE `users` SET `wallet`=? WHERE `userid`=?");
            $stmt->bind_param("ii",$newWallet,$from_id);
            $stmt->execute();
            $stmt->close();

            $add = (int)$plan['days'] * 86400;
            $base = (int)$b['expires_at'];
            if($base < time()) $base = time();
            $newExp = $base + $add;

            $stmt = $connection->prepare("UPDATE `reseller_bots` SET `expires_at`=? WHERE `id`=?");
            $stmt->bind_param("ii",$newExp,$rid);
            $stmt->execute();
            $stmt->close();

            smartSendOrEdit($message_id, "✅ تمدید انجام شد.
تاریخ انقضا: ".jdate('Y/m/d H:i',$newExp), ['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"myResBot_" . $rid]]]]);
        }
    }
}

if(preg_match('/^sendMessageToUser(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    smartSendOrEdit($message_id,'🔘|لطفا پیامت رو بفرست');
    setUser($data);
}
if(preg_match('/^sendMessageToUser(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    sendMessage($text,null,null,$match[1]);
    sendMessage("پیامت به کاربر ارسال شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if($data=='botReports' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id, "آمار ربات در این لحظه",getBotReportKeys());
}



if($data=='adminResellerBots' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
        // Remove ReplyKeyboard (make UI glass/inline) when entering this menu
    if(!isset($update->callback_query) || empty($update->callback_query)){
        bot('sendMessage', [
            'chat_id'=>$chat_id,
            'text'=>' ',
            'reply_markup'=>json_encode(['remove_keyboard'=>true])
        ]);
    }

// InlineKeyboard = "شیشه ای"
    $keys = ['inline_keyboard'=>[
        [['text'=>"📋 لیست ربات ها",'callback_data'=>"adminResBotsList_0"]],
        [['text'=>"➕ ساخت ربات جدید",'callback_data'=>"adminResBotsCreate"]],
        [['text'=>"📦 پلن های نمایندگی",'callback_data'=>"adminResPlans"]],
        [['text'=>"➕ افزودن پلن نمایندگی",'callback_data'=>"addResellerPlan"]],
        [['text'=>"🗄 دیتابیس ها",'callback_data'=>"adminResDBList_0"]],
        [['text'=>"😪 منصرف شدم بیخیال",'callback_data'=>"managePanel"]],
    ]];
    smartSendOrEdit($message_id, "🤖 مدیریت ربات ها\n\nیکی از گزینه‌ها را انتخاب کنید:", $keys);
}


// =======================
// Admin: Database manager (Mother + Reseller DBs)
// =======================
if(preg_match('/^adminResDBList_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $page = (int)$m[1];
    $per = 10;
    $baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');

    // Collect allowed DBs:
    $dbs = [];
    if(!empty($baseDb)) $dbs[$baseDb] = true;

    // From reseller_bots table
    $q = $connection->query("SELECT DISTINCT db_name FROM reseller_bots WHERE db_name IS NOT NULL AND db_name<>''");
    if($q){
        while($r = $q->fetch_assoc()){
            $n = trim($r['db_name'] ?? '');
            if($n !== '') $dbs[$n] = true;
        }
    }

    // From SHOW DATABASES (so admin can manage orphaned dbs too)
    $show = $connection->query("SHOW DATABASES");
    if($show){
        while($r = $show->fetch_assoc()){
            $n = $r['Database'] ?? '';
            if(!$n) continue;
            // allow mother db and reseller dbs with prefix mother_rb
            if($n === $baseDb || (strpos($n, $baseDb . "_rb") === 0)){
                $dbs[$n] = true;
            }
        }
    }

    $dbList = array_keys($dbs);
    sort($dbList, SORT_NATURAL);

    // Put mother db first
    if(in_array($baseDb, $dbList)){
        $dbList = array_values(array_diff($dbList, [$baseDb]));
        array_unshift($dbList, $baseDb);
    }

    $total = count($dbList);
    $pages = ($total>0) ? (int)ceil($total/$per) : 1;
    if($page < 0) $page = 0;
    if($page > $pages-1) $page = $pages-1;

    $slice = array_slice($dbList, $page*$per, $per);

    $text = "🗄 مدیریت دیتابیس‌ها\n\n";
    if($total==0){
        $text .= "هیچ دیتابیسی پیدا نشد.";
    } else {
        $text .= "تعداد دیتابیس‌ها: {$total}\n\n";
        $i = $page*$per + 1;
        foreach($slice as $dbn){
            $isMother = ($dbn === $baseDb);
            $label = $isMother ? " (مادر)" : "";
            $text .= "{$i}) `{$dbn}`{$label}\n";
            $i++;
        }
        $text .= "\nروی هر دیتابیس بزن تا گزینه‌هاش بیاد.";
    }

    $keys = ['inline_keyboard'=>[]];
    foreach($slice as $dbn){
        $keys['inline_keyboard'][] = [[ 'text'=>"🗄 ".$dbn, 'callback_data'=>"adminResDBInfo_".$dbn ]];
    }

    $nav = [];
    if($page>0) $nav[] = ['text'=>"⬅️ قبلی", 'callback_data'=>"adminResDBList_".($page-1)];
    if($page < $pages-1) $nav[] = ['text'=>"بعدی ➡️", 'callback_data'=>"adminResDBList_".($page+1)];
    if(!empty($nav)) $keys['inline_keyboard'][] = $nav;

    $keys['inline_keyboard'][] = [[ 'text'=>"🔙 برگشت", 'callback_data'=>"adminResellerBots" ]];
    smartSendOrEdit($message_id, $text, $keys, "Markdown");
}

if(preg_match('/^adminResDBInfo_([A-Za-z0-9_]+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $dbn = $m[1];
    $baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');

    // Security: only allow mother db or mother_rb*
    if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0))){
        alert("❌ دیتابیس معتبر نیست.", true);
        exit;
    }

    $cnt = 0;
    $q = $connection->query("SELECT COUNT(*) as c FROM reseller_bots WHERE db_name='".mysqli_real_escape_string($connection,$dbn)."'");
    if($q){ $r=$q->fetch_assoc(); $cnt=(int)($r['c'] ?? 0); }

    $text = "🗄 دیتابیس: `{$dbn}`\n";
    if($dbn === $baseDb) $text .= "نوع: مادر\n";
    else $text .= "نوع: نمایندگی\n";
    $text .= "ربات‌های وابسته: {$cnt}\n\n";
    $text .= "چه کاری انجام بدیم؟";

    $keys = ['inline_keyboard'=>[
        [
            ['text'=>"📤 بکاپ بگیر", 'callback_data'=>"adminResDBBackup_".$dbn],
            ['text'=>"🗑 حذف", 'callback_data'=>"adminResDBDropAsk_".$dbn],
        ],
        [
            ['text'=>"🔙 برگشت به لیست", 'callback_data'=>"adminResDBList_0"],
        ],
    ]];
    smartSendOrEdit($message_id, $text, $keys, "Markdown");
}

if(preg_match('/^adminResDBBackup_([A-Za-z0-9_]+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dbn = $m[1];
    $baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');
    if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0))){
        alert("❌ دیتابیس معتبر نیست.", true);
        exit;
    }

    $tokenToUse = $GLOBALS['botToken'] ?? null;
    if(!$tokenToUse){ alert('❌ توکن ربات پیدا نشد.', true); exit; }

    // Notify
    smartSendOrEdit($message_id, "⏳ در حال ساخت بکاپ...\nلطفاً صبر کنید", ['inline_keyboard'=>[[['text'=>"🔙 برگشت",'callback_data'=>"adminResDBInfo_".$dbn]]]]);

    if(isShellExecAvailable()){
        $worker = __DIR__ . '/backup_worker.php';
        $cmd = 'nohup php ' . escapeshellarg($worker) . ' backup ' . escapeshellarg($tokenToUse) . ' ' . escapeshellarg($from_id) . ' ' . escapeshellarg('deltabot_db_backup') . ' ' . escapeshellarg($dbn) . ' >/dev/null 2>&1 &';
        @shell_exec($cmd);
    }else{
        // Fallback: use existing sync backup code path (may be slow)
        // We reuse the existing backup routine by setting a temp global and calling worker directly is not possible without shell_exec.
        // So we just tell admin to enable shell_exec or take manual backup from server.
        sendMessage("⚠️ روی این سرور shell_exec غیرفعال است و بکاپ دیتابیس ممکن است باعث هنگ شود.\n\nلطفاً shell_exec را فعال کنید یا از طریق سرور بکاپ بگیرید.");
    }
    exit;
}

if(preg_match('/^adminResDBDropAsk_([A-Za-z0-9_]+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dbn = $m[1];
    $baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');
    if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0))){
        alert("❌ دیتابیس معتبر نیست.", true);
        exit;
    }
    if($dbn === $baseDb){
        alert("❌ حذف دیتابیس مادر از داخل ربات غیرفعال است.", true);
        exit;
    }

    $text = "⚠️ هشدار!\n\nآیا مطمئن هستید دیتابیس زیر حذف شود؟\n`{$dbn}`\n\nاین کار غیرقابل بازگشت است.";
    $keys = ['inline_keyboard'=>[
        [
            ['text'=>"✅ بله حذف کن", 'callback_data'=>"adminResDBDropYes_".$dbn],
            ['text'=>"❌ نه", 'callback_data'=>"adminResDBInfo_".$dbn],
        ],
    ]];
    smartSendOrEdit($message_id, $text, $keys, "Markdown");
}

if(preg_match('/^adminResDBDropYes_([A-Za-z0-9_]+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $dbn = $m[1];
    $baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');
    if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0))){
        alert("❌ دیتابیس معتبر نیست.", true);
        exit;
    }
    if($dbn === $baseDb){
        alert("❌ حذف دیتابیس مادر غیرفعال است.", true);
        exit;
    }

    // Try drop
    $dbEsc = str_replace('`','',$dbn);
    $ok = $connection->query("DROP DATABASE `{$dbEsc}`");
    if($ok){
        // Clean reseller_bots references
        $connection->query("DELETE FROM reseller_bots WHERE db_name='".mysqli_real_escape_string($connection,$dbn)."'");
        smartSendOrEdit($message_id, "✅ دیتابیس حذف شد: `{$dbn}`", ['inline_keyboard'=>[[['text'=>"🔙 برگشت به لیست",'callback_data'=>"adminResDBList_0"]]]], "Markdown");
    }else{
        $err = $connection->error;
        smartSendOrEdit($message_id, "❌ حذف انجام نشد.\n\nخطا: {$err}", ['inline_keyboard'=>[[['text'=>"🔙 برگشت",'callback_data'=>"adminResDBInfo_".$dbn]]]], "Markdown");
    }
    exit;
}

if(preg_match('/^adminResBotsList_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $page = (int)$m[1];
    $per = 15;
    $off = $page*$per;
    $res = $connection->query("SELECT rb.*, u.name as uname FROM reseller_bots rb LEFT JOIN users u ON u.userid=rb.owner_userid WHERE rb.status=1 ORDER BY rb.id DESC LIMIT $off, $per");
    $rows = [];
    if($res && $res->num_rows>0){
        while($b = $res->fetch_assoc()){
            $uname = $b['bot_username'] ? '@'.$b['bot_username'] : '---';
            $rows[] = [['text'=>$uname." | #".$b['id'],'callback_data'=>"adminResBot_" . $b['id']]];
        }
    }else{
        $rows[] = [['text'=>"موردی یافت نشد",'callback_data'=>"noop"]];
    }
    $nav=[];
    if($page>0) $nav[]=['text'=>"⬅️ قبلی",'callback_data'=>"adminResBotsList_" . ($page-1)];
    $nav[]=['text'=>"➡️ بعدی",'callback_data'=>"adminResBotsList_" . ($page+1)];
    $rows[]=$nav;
    $rows[]=[['text'=>$buttonValues,'callback_data'=>"adminResellerBots"]];
    smartSendOrEdit($message_id, "📋 لیست ربات ها", ['inline_keyboard'=>$rows]);
}

if(preg_match('/^adminResBot_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    $res=$connection->query("SELECT rb.*, u.name as uname FROM reseller_bots rb LEFT JOIN users u ON u.userid=rb.owner_userid WHERE rb.id=$rid LIMIT 1");
    $b=$res?$res->fetch_assoc():null;
    if(!$b){
        smartSendOrEdit($message_id,"❌ پیدا نشد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
    }else{
        $exp=jdate('Y/m/d H:i',(int)$b['expires_at']);
        $uname=$b['bot_username']?'@'.$b['bot_username']:'---';
        $txt="🤖 مشخصات ربات

"
            ."شناسه: #{$b['id']}
"
            ."یوزرنیم: {$uname}
"
            ."مالک: {$b['owner_userid']} ".($b['uname']?("({$b['uname']})"):"")."
"
            ."ادمین: {$b['admin_userid']}
"
            ."انقضا: {$exp}
";
        $keys=['inline_keyboard'=>[
            [['text'=>"🔁 تمدید",'callback_data'=>"adminResBotRenew_" . $b['id']]],
            [['text'=>"🔄 انتقال",'callback_data'=>"adminResBotTransfer_" . $b['id']]],
            [['text'=>"🗑 حذف",'callback_data'=>"adminResBotDelete_" . $b['id']]],
            [['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]],
        ]];
        smartSendOrEdit($message_id,$txt,$keys,"HTML");
    }
}

if(preg_match('/^adminResBotDelete_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    $res=$connection->query("SELECT * FROM reseller_bots WHERE id=$rid LIMIT 1");
    $b=$res?$res->fetch_assoc():null;
    if($b && !empty($b['bot_token'])) botWithToken($b['bot_token'],"setWebhook",['url'=>'']);
    $connection->query("UPDATE reseller_bots SET status=0 WHERE id=$rid");
    smartSendOrEdit($message_id,"✅ حذف شد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
}

if(preg_match('/^adminResBotRenew_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1];
    // choose plan days to extend
    $res=$connection->query("SELECT * FROM reseller_plans WHERE is_active=1 ORDER BY id ASC");
    $rows=[];
    if($res){
        while($p=$res->fetch_assoc()){
            $rows[]=[['text'=>$p['title']." (+{$p['days']} روز)",'callback_data'=>"adminResBotDoRenew_" . $rid . "_" . $p['id']]];
        }
    }
    $rows[]=[['text'=>$buttonValues,'callback_data'=>"adminResBot_" . $rid]];
    smartSendOrEdit($message_id,"پلن تمدید را انتخاب کنید:",['inline_keyboard'=>$rows]);
}

if(preg_match('/^adminResBotDoRenew_(\d+)_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    $rid=(int)$m[1]; $pid=(int)$m[2];
    $b=$connection->query("SELECT * FROM reseller_bots WHERE id=$rid LIMIT 1")->fetch_assoc();
    $p=$connection->query("SELECT * FROM reseller_plans WHERE id=$pid LIMIT 1")->fetch_assoc();
    if(!$b || !$p){
        smartSendOrEdit($message_id,"❌ پیدا نشد.",['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBotsList_0"]]]]);
    }else{
        $base=(int)$b['expires_at']; if($base<time()) $base=time();
        $newExp=$base + ((int)$p['days']*86400);
        $connection->query("UPDATE reseller_bots SET expires_at=$newExp WHERE id=$rid");
        smartSendOrEdit($message_id,"✅ تمدید شد.
انقضا: ".jdate('Y/m/d H:i',$newExp),['inline_keyboard'=>[[['text'=>$buttonValues,'callback_data'=>"adminResBot_" . $rid]]]]);
    }
}

if(preg_match('/^adminResBotTransfer_(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $rid=(int)$m[1];
    setUser("adminResBotTransfer_" . $rid,"step");
    smartSendOrEdit($message_id,"آیدی عددی کاربر جدید را ارسال کنید:",['inline_keyboard'=>[[['text'=>$buttonValues['cancel'],'callback_data'=>"adminResBot_" . $rid]]]]);
}

if($data=='adminResBotsCreate' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    ensureResellerTables();
    setUser("adminResBotsCreateUser","step");
    smartSendOrEdit($message_id,"آیدی عددی کاربر مالک را ارسال کنید:",['inline_keyboard'=>[[['text'=>$buttonValues['cancel'],'callback_data'=>"adminResellerBots"]]]]);
}

// -------- Admin: Users list & discount users
if(preg_match('/^adminUsersList(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $off = (int)$m[1];
    smartSendOrEdit($message_id, "👥 لیست کل کاربران", getAdminUsersListKeys($off), "HTML");
}
if(preg_match('/^adminDiscountUsers(\d+)/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $off = (int)$m[1];
    smartSendOrEdit($message_id, "٪ کاربران دارای تخفیف", getAdminDiscountUsersKeys($off), "HTML");
}
if(preg_match('/^adminUser_(\d+)_([0-9]+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = $m[1];
    $backOff = (int)$m[2];
    $txt = getAdminUserDetailsText($uid);
    smartSendOrEdit($message_id, $txt, getAdminUserDetailsKeys("adminUsersList{$backOff}"), "HTML");
}
if(preg_match('/^adminUser_(\d+)_disc([0-9]+)$/',$data,$m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = $m[1];
    $backOff = (int)$m[2];
    $txt = getAdminUserDetailsText($uid);
    smartSendOrEdit($message_id, $txt, getAdminUserDetailsKeys("adminDiscountUsers{$backOff}"), "HTML");
}
if($data=="adminsList" && $from_id == $admin){
    smartSendOrEdit($message_id, "لیست ادمین ها",getAdminsKeys());
}
if(preg_match('/^delAdmin(\d+)/',$data,$match) && $from_id === $admin){
    $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = false WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    smartSendOrEdit($message_id, "لیست ادمین ها",getAdminsKeys());

}
if($data=="addNewAdmin" && $from_id === $admin){
    delMessage();
    sendMessage("🧑‍💻| کسی که میخوای ادمین کنی رو آیدی عددیشو بفرست ببینم:",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewAdmin" && $from_id === $admin && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = true WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅ | 🥳 خب کاربر الان ادمین شد تبریک میگم",$removeKeyboard);
        setUser();
        
        sendMessage("لیست ادمین ها",getAdminsKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(($data=="botSettings" or preg_match("/^changeBot(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="botSettings"){
        if($match[1] == "cartToCartAutoAcceptType") $newValue = $botState[$match[1]] == "0"?"1":($botState[$match[1]] == "1"?"2":0);
        else $newValue = $botState[$match[1]]=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    smartSendOrEdit($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}

if($data=="toggleInviteButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = (($botState['inviteButton']??'on')=='on')?'off':'on';
    setSettings('inviteButton',$newValue);
    smartSendOrEdit($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if($data=="changeUpdateConfigLinkState" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $newValue = $botState['updateConnectionState']=="robot"?"site":"robot";
    setSettings('updateConnectionState', $newValue);
    smartSendOrEdit($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(($data=="gateWays_Channels" or preg_match("/^changeGateWays(\w+)/",$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($data!="gateWays_Channels"){
        $newValue = $botState[$match[1]]=="on"?"off":"on";
        setSettings($match[1], $newValue);
    }
    smartSendOrEdit($message_id,$mainValues['change_bot_settings_message'],getGateWaysKeys());
}
if($data=="changeConfigRemarkType"){
    switch($botState['remark']){
        case "digits":
            $newValue = "manual";
            break;
        case "manual":
            $newValue = "idanddigits";
            break;
        default:
            $newValue = "digits";
            break;
    }
    setSettings('remark', $newValue);
    smartSendOrEdit($message_id,$mainValues['change_bot_settings_message'],getBotSettingKeys());
}
if(preg_match('/^changePaymentKeys(\w+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    switch($match[1]){
        case "nextpay":
            $gate = "کد جدید درگاه نکست پی";
            break;
        case "nowpayment":
            $gate = "کد جدید درگاه nowPayment";
            break;
        case "zarinpal":
            $gate = "کد جدید درگاه زرین پال";
            break;
        case "bankAccount":
            $gate = "شماره حساب جدید";
            break;
        case "holderName":
            $gate = "اسم دارنده حساب";
            break;
        case "tronwallet":
            $gate = "آدرس والت ترون";
            break;
    }
    sendMessage("🔘|لطفا $gate را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changePaymentKeys(\w+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentInfo = $stmt->get_result();
    $stmt->close();
    $paymentKeys = json_decode($paymentInfo->fetch_assoc()['value'],true)??array();
    $paymentKeys[$match[1]] = $text;
    $paymentKeys = json_encode($paymentKeys);
    
    if($paymentInfo->num_rows > 0) $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'PAYMENT_KEYS'");
    else $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('PAYMENT_KEYS', ?)");
    $stmt->bind_param("s", $paymentKeys);
    $stmt->execute(); 
    $stmt->close();
    

    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
    setUser();
}
if(($data == "agentsList" || preg_match('/^nextAgentList(\d+)/',$data,$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getAgentsList($match[1]??0);
    if($keys != null) smartSendOrEdit($message_id,$mainValues['agents_list'], $keys);
    else alert("نماینده ای یافت نشد");
}
if(preg_match('/^agentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $userDetail = bot('getChat',['chat_id'=>$match[1]])->result;
    $userUserName = $userDetail->username;
    $fullName = $userDetail->first_name . " " . $userDetail->last_name;

    smartSendOrEdit($message_id,str_replace("AGENT-NAME", $fullName, $mainValues['agent_details']), getAgentDetails($match[1]));
}
if(preg_match('/^removeAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['agent_deleted_successfuly']);
    $keys = getAgentsList();
    if($keys != null) editKeys($keys);
    else editKeys(json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]]]));
}
if(preg_match('/^agentPercentDetails(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userName = $info['name'];
    smartSendOrEdit($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[1]));
}
if(preg_match('/^addDiscount(Server|Plan)Agent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match[2]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    if($match[1] == "Plan"){
        $offset = 0;
        $limit = 20;
        
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
                $stmt->bind_param("i", $row['catid']);
                $stmt->execute();
                $catInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match[2] . "_" . $row['id']]];
            }
            
            if($list->num_rows >= $limit){
                $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match[2] . "_" . ($offset + $limit)]];
            }
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            smartSendOrEdit($message_id,"لطفا سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
        }else alert("سروری باقی نمانده است");
    }else{
        $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['servers']??array()));
        $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
        $stmt = $connection->prepare("SELECT * FROM `server_info` $condition");
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        if($list->num_rows > 0){
            $keys = array();
            while($row = $list->fetch_assoc()){
                $keys[] = [['text'=>$row['title'],'callback_data'=>"editAgentDiscountServer" . $match[2] . "_" . $row['id']]];
            }
            
            $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match[2]]];
            $keys = json_encode(['inline_keyboard'=>$keys]);
            
            smartSendOrEdit($message_id,"لطفا سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
        }else alert("سروری باقی نمانده است");
    }
}
if(preg_match('/^nextAgentDiscountPlan(?<agentId>\d+)_(?<offset>\d+)/',$data,$match) &&($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userName = $info['name'];
    
    $offset = $match['offset'];
    $limit = 20;
    
    $condition = array_values(array_keys(json_decode($info['discount_percent'],true)['plans']??array()));
    $condition = count($condition) > 0? "WHERE `id` NOT IN (" . implode(",", $condition) . ")":"";
    $stmt = $connection->prepare("SELECT * FROM `server_plans` $condition LIMIT ? OFFSET ?");
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows > 0){
        $keys = array();
        while($row = $list->fetch_assoc()){
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $row['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>$row['title'] . " " . $catInfo['title'],'callback_data'=>"editAgentDiscountPlan" . $match['agentId'] . "_" . $row['id']]];
        }
        
        if($list->num_rows >= $limit && $offset == 0){
            $keys[] = [['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]];
        }
        elseif($list->num_rows >= $limit && $offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)],
                ['text'=>"▶️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset + $limit)]
                ];
        }
        elseif($offset != 0){
            $keys[] = [
                ['text'=>"◀️️",'callback_data'=>"nextAgentDiscountPlan" . $match['agentId'] . "_" . ($offset - $limit)]
                ];
        }
        $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentPercentDetails" . $match['agentId']]];
        $keys = json_encode(['inline_keyboard'=>$keys]);
        
        smartSendOrEdit($message_id,"لطفا سرور مورد نظر را برای افزودن تخفیف به نماینده $userName انتخاب کنید",$keys);
    }else alert("سروری باقی نمانده است");
}
if(preg_match('/^removePercentOfAgent(?<type>Server|Plan)(?<agentId>\d+)_(?<serverId>\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param('i',$match['agentId']);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $discounts = json_decode($info['discount_percent'],true);
    if($match['type'] == "Server") unset($discounts['servers'][$match['serverId']]);
    elseif($match['type'] == "Plan") unset($discounts['plans'][$match['serverId']]);
    
    $discounts = json_encode($discounts,488);
    $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    $stmt->bind_param("si", $discounts, $match['agentId']);
    $stmt->execute();
    $stmt->close();
    
    alert('با موفقیت حذف شد');
    smartSendOrEdit($message_id, str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match['agentId']));
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
    setUser($data);
}
if(preg_match('/^editAgentDiscount(Server|Plan|Normal)(\d+)_(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param('i',$match[2]);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $discountInfo = json_decode($info['discount_percent'],true);
        if($match[1] == "Server") $discountInfo['servers'][$match[3]] = $text;
        elseif($match[1] == "Plan") $discountInfo['plans'][$match[3]] = $text;
        elseif($match[1] == "Normal") $discountInfo['normal'] = $text;
        $text = json_encode($discountInfo);
        
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        
        $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
        $stmt->bind_param("si", $text, $match[2]);
        $stmt->execute();
        $stmt->close();
        sendMessage(str_replace("AGENT-NAME", $userName, $mainValues['agent_discount_settings']), getAgentDiscounts($match[2]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    if($match[1] == "RewaredTime") $txt = "🙃 | لطفا زمان تأخیر در ارسال گزارش رو به ساعت وارد کن\n\nنکته: هر n ساعت گزارش به ربات ارسال میشه! ";
    else $txt = "لطفا زمان مورد نظر را به دقیقه وارد کنید";
    
    sendMessage($txt,$cancelKey);
    setUser($data);
}
if($data=="userReports" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🙃 | لطفا آیدی عددی کاربر رو وارد کن",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "userReports" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        sendMessage($mainValues['please_wait_message'],$removeKeyboard);
        $keys = getUserInfoKeys($text);
        if($keys != null){
            sendMessage("اطلاعات کاربر <a href='tg://user?id=$text'>$fullName</a>",$keys,"html");
            setUser();
        }else sendMessage("کاربری با این آیدی یافت نشد");
    }else{
        sendMessage("😡|لطفا فقط عدد ارسال کن");
    }
}


// --- User quick actions (from user report panel)
function renderUserInfoTitle($uid){
    $detail = bot('getChat',['chat_id'=>$uid])->result;
    $fullName = trim(($detail->first_name??'') . " " . ($detail->last_name??''));
    if($fullName == '') $fullName = 'کاربر';
    return "اطلاعات کاربر <a href='tg://user?id=$uid'>$fullName</a>";
}
function refreshUserInfoPanel($uid, $msgId=null){
    global $message_id;
    $msgId = $msgId ?? $message_id;
    $keys = getUserInfoKeys($uid);
    if($keys != null){
        smartSendOrEdit($msgId, renderUserInfoTitle($uid), $keys, "HTML");
    }else{
        alert("کاربر یافت نشد");
    }
}

if(preg_match('/^uRefresh(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    refreshUserInfoPanel($uid, $message_id);
}


if(preg_match('/^uConfigsSearch(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    delMessage();
    sendMessage("🔎 عبارت جستجو را ارسال کنید (داخل عنوان/Remark کانفیگ‌ها)", $cancelKey);
    setUser("uSearchUserConfigs_$uid","step");
}

if(preg_match('/^uConfigs(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    $offset = (int)$match[2];
    smartSendOrEdit($message_id, renderUserInfoTitle($uid) . "\n\n🔎 لیست کانفیگ‌ها:", getUserConfigsListKeys($uid, $offset), "HTML");
}

if(preg_match('/^uIncWallet(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['enter_increase_amount'],$cancelKey);
    setUser("increaseWalletUserPanel" . $match[1]);
}
if(preg_match('/^uDecWallet(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['enter_decrease_amount'],$cancelKey);
    setUser("decreaseWalletUserPanel" . $match[1]);
}
if(preg_match('/^increaseWalletUserPanel(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $uid = (int)$match[1];
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $uid);
        $stmt->execute();
        $stmt->close();

        sendMessage("✅ مبلغ " . number_format($text). " تومان به کیف پول شما اضافه شد", null, null, $uid);
        sendMessage("✅ انجام شد",$removeKeyboard);
        setUser();
        sendMessage(renderUserInfoTitle($uid), getUserInfoKeys($uid), "HTML");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^decreaseWalletUserPanel(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $uid = (int)$match[1];
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $uid);
        $stmt->execute();
        $stmt->close();

        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']), null, null, $uid);
        sendMessage("✅ انجام شد",$removeKeyboard);
        setUser();
        sendMessage(renderUserInfoTitle($uid), getUserInfoKeys($uid), "HTML");
    }else sendMessage($mainValues['send_only_number']);
}

if(preg_match('/^uBan(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    $stmt = $connection->prepare("UPDATE `users` SET `step`='banned' WHERE `userid`=?");
    $stmt->bind_param("i",$uid);
    $stmt->execute();
    $stmt->close();
    alert("⛔️ مسدود شد");
    refreshUserInfoPanel($uid, $message_id);
}
if(preg_match('/^uUnban(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    $stmt = $connection->prepare("UPDATE `users` SET `step`='none' WHERE `userid`=? AND `step`='banned'");
    $stmt->bind_param("i",$uid);
    $stmt->execute();
    $stmt->close();
    alert("🔓 آزاد شد");
    refreshUserInfoPanel($uid, $message_id);
}
if(preg_match('/^uPm(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("✉️ پیام را ارسال کنید",$cancelKey);
    setUser("uPmSendPanel" . $match[1]);
}
if(preg_match('/^uPmSendPanel(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($update->message->text)){
        sendMessage("لطفا فقط متن ارسال کنید");
        exit();
    }
    $uid = (int)$match[1];
    sendMessage($text,null,null,$uid);
    setUser();
    sendMessage("✅ ارسال شد",$removeKeyboard);
    sendMessage(renderUserInfoTitle($uid), getUserInfoKeys($uid), "HTML");
}
if(preg_match('/^uReset(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    $stmt = $connection->prepare("UPDATE `users` SET `wallet`=0 WHERE `userid`=?");
    $stmt->bind_param("i",$uid);
    $stmt->execute();
    $stmt->close();
    alert("♻️ موجودی صفر شد");
    refreshUserInfoPanel($uid, $message_id);
}
if(preg_match('/^uOrders(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid = (int)$match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? ORDER BY `id` DESC LIMIT 10");
    $stmt->bind_param("i",$uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res->num_rows==0){
        alert("سفارشی یافت نشد");
    }else{
        $out="🧾 10 سفارش آخر:\n\n";
        while($row=$res->fetch_assoc()){
            $out .= "• #" . $row['id'] . " | " . number_format($row['amount']) . " تومان | " . jdate("Y/m/d H:i", $row['date']) . "\n";
        }
        sendMessage($out);
    }
}
if(preg_match('/^uDiscount(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🎯 درصد تخفیف را ارسال کنید (0 تا 100)",$cancelKey);
    setUser("uDiscountSetPanel" . $match[1]);
}
if(preg_match('/^uDiscountSetPanel(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text) || $text<0 || $text>100){
        sendMessage("عدد بین 0 تا 100 ارسال کنید");
        exit();
    }
    $uid=(int)$match[1];
    upsertSettingValue("USER_DISCOUNT_" . $uid, $text);
    setUser();
    sendMessage("✅ ذخیره شد",$removeKeyboard);
    sendMessage(renderUserInfoTitle($uid), getUserInfoKeys($uid), "HTML");
}
if(preg_match('/^uTestLimit(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🧪 محدودیت تست را ارسال کنید (مثلا 0=غیرفعال)",$cancelKey);
    setUser("uTestLimitSetPanel" . $match[1]);
}
if(preg_match('/^uTestLimitSetPanel(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text) || $text<0){
        sendMessage("فقط عدد 0 یا بیشتر ارسال کنید");
        exit();
    }
    $uid=(int)$match[1];
    upsertSettingValue("USER_TEST_LIMIT_" . $uid, $text);
    setUser();
    sendMessage("✅ ذخیره شد",$removeKeyboard);
    sendMessage(renderUserInfoTitle($uid), getUserInfoKeys($uid), "HTML");
}
if(preg_match('/^uAuto(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $uid=(int)$match[1];
    $type="USER_NO_AUTOAPPROVE_" . $uid;
    $val = getSettingValue($type,"0");
    $newVal = ($val=="1")?"0":"1";
    upsertSettingValue($type, $newVal);
    alert($newVal=="1"?"✅ استثنا شد":"❌ برداشته شد");
    refreshUserInfoPanel($uid, $message_id);
}


if($data=="inviteSetting" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
    $stmt->execute();
    $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " تومان";
    $stmt->close();
    setUser();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
        [
            ['text'=>$inviteAmount,'callback_data'=>"editInviteAmount"],
            ['text'=>"مقدار پورسانت",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
            ],
        ]]); 
    $res = smartSendOrEdit($message_id,"✅ تنظیمات بازاریابی",$keys);
    if(!$res->ok){
        delMessage();
        sendMessage("✅ تنظیمات بازاریابی",$keys);
    }
} 
if($data=="inviteBanner" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    $inviteText = $inviteText != null?json_decode($inviteText,true):array('type'=>'text');
    $stmt->close();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if($inviteText['type'] == "text"){
        smartSendOrEdit($message_id,"بنر فعلی: \n" . $inviteText['text'],$keys);
    }else{
        delMessage();
        $res = sendPhoto($inviteText['file_id'], $inviteText['caption'], $keys,null);
        if(!$res->ok){
            sendMessage("تصویر فعلی یافت نشد، لطفا اقدام به ویرایش بنر کنید",$keys);
        }
    }
    setUser();
}
if($data=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤖 | لطفا بنر جدید را بفرستید از متن  LINK برای نمایش لینک دعوت استفاده کنید)",$cancelKey);
    setUser($data);
}
if($userInfo['step']=="editInviteBannerText" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $data = array();
    if(isset($update->message->photo)){
        $data['type'] = 'photo';
        $data['caption'] = $caption;
        $data['file_id'] = $fileid;
    }
    elseif(isset($update->message->text)){
        $data['type'] = 'text';
        $data['text'] = $text;
    }else{
        sendMessage("🥺 | بنر ارسال شده پشتیبانی نمی شود");
        exit();
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $checkExist = $stmt->get_result();
    $stmt->close();
    $data = json_encode($data);
    if($checkExist->num_rows > 0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_TEXT'");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_TEXT')");
        $stmt->bind_param("s", $data);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"ویرایش",'callback_data'=>'editInviteBannerText']],
        [['text'=>$buttonValues['back_button'],'callback_data'=>'inviteSetting']]
        ]]);
    if(isset($update->message->text)){
        sendMessage("بنر فعلی: \n" . $text,$keys);
    }else{
        sendPhoto($fileid, $caption, $keys);
    }
    setUser();
}
if($data=="editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا مبلغ پورسانت رو به تومان وارد کن",$cancelKey);
    setUser($data);
} 
if($userInfo['step'] == "editInviteAmount" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows > 0){
            $stmt = $connection->prepare("UPDATE `setting` SET `value` = ? WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }else{
            $stmt = $connection->prepare("INSERT INTO `setting` (`value`, `type`) VALUES (?, 'INVITE_BANNER_AMOUNT')");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
        }
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"❗️بنر دعوت",'callback_data'=>"inviteBanner"]],
            [
                ['text'=>number_format($text) . " تومان",'callback_data'=>"editInviteAmount"],
                ['text'=>"مقدار پورسانت",'callback_data'=>"deltach"]
                ], 
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>"botSettings"]
                ],
            ]]); 
        sendMessage("✅ تنظیمات بازاریابی",$keys);
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^edit(RewaredTime|cartToCartAutoAcceptTime)/', $userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("لطفا عدد بفرستید");
        exit();
    }
    elseif($text <0 ){
        sendMessage("مقدار وارد شده معتبر نیست");
        exit();
    }
    
    setSettings(lcfirst($match[1]), $text);
    sendMessage($mainValues['change_bot_settings_message'],getBotSettingKeys());
    setUser();
    exit();
}
if($data=="inviteFriends"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_TEXT'");
    $stmt->execute();
    $inviteText = $stmt->get_result()->fetch_assoc()['value'];
    if($inviteText != null){
        delMessage();
        $inviteText = json_decode($inviteText,true);
    
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = number_format($stmt->get_result()->fetch_assoc()['value']??0) . " تومان";
        $stmt->close();
        
        $getBotInfo = json_decode(file_get_contents("http://api.telegram.org/bot" . $botToken . "/getMe"),true);
        $botId = $getBotInfo['result']['username'];
        
        $link = "t.me/$botId?start=" . $from_id;
        if($inviteText['type'] == "text"){
            $txt = str_replace('LINK',"<code>$link</code>",$inviteText['text']);
            $res = sendMessage($txt,null,"HTML");
        } 
        else{
            $txt = str_replace('LINK',"$link",$inviteText['caption']);
            $res = sendPhoto($inviteText['file_id'],$txt,null,"HTML");
        }
        $msgId = $res->result->message_id;
        sendMessage("با لینک بالا دوستاتو به ربات دعوت کن و با هر خرید $inviteAmount بدست بیار",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),null,null,$msgId);
    }
    else alert("این قسمت غیر فعال است");
}
if($data=="myInfo"){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $totalBuys = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $myWallet = number_format($userInfo['wallet']) . " تومان";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"شارژ کیف پول 💰",'callback_data'=>"increaseMyWallet"],
            ['text'=>"انتقال موجودی",'callback_data'=>"transferMyWallet"]
        ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]
            ]
        ]]);
    smartSendOrEdit($message_id, "
💞 اطلاعات حساب شما:
    
🔰 شناسه کاربری: <code> $from_id </code>
🍄 یوزرنیم: <code> @$username </code>
👤 اسم:  <code> $first_name </code>
💰 موجودی: <code> $myWallet </code>

☑️ کل سرویس ها : <code> $totalBuys </code> عدد
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",
            $keys,"html");
}
if($data=="transferMyWallet"){
    if($userInfo['wallet'] > 0 ){
        delMessage();
        sendMessage("لطفا آیدی عددی کاربر مورد نظر رو وارد کن",$cancelKey);
        setUser($data);
    }else alert("موجودی حساب شما کم است");
}
if($userInfo['step'] =="transferMyWallet" && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text != $from_id){
            $stmt= $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
            $stmt->bind_param("i", $text);
            $stmt->execute();
            $checkExist = $stmt->get_result();
            $stmt->close();
            
            if($checkExist->num_rows > 0){
                setUser("tranfserUserAmount" . $text);
                sendMessage("لطفا مبلغ مورد نظر رو وارد کن");
            }else sendMessage("کاربری با این آیدی یافت نشد");
        }else sendMessage("میخای به خودت انتقال بدی ؟؟");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^tranfserUserAmount(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text > 0){
            if($userInfo['wallet'] >= $text){
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $text, $match[1]);
                $stmt->execute();
                $stmt->close();
                
                $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
                $stmt->bind_param("ii", $text, $from_id);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("✅|مبلغ " . number_format($text) . " تومان به کیف پول شما توسط کاربر $from_id انتقال یافت",null,null,$match[1]);
                setUser();
                sendMessage("✅|مبلغ " . number_format($text) . " تومان به کیف پول کاربر مورد نظر شما انتقال یافت",$removeKeyboard);
                sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید",getMainKeys());
            }else sendMessage("موجودی حساب شما کم است");
        }else sendMessage("لطفا عددی بزرگتر از صفر وارد کنید");
    }else sendMessage($mainValues['send_only_number']);
}
if($data=="increaseMyWallet"){
    delMessage();
    sendMessage("🙂 عزیزم مقدار شارژ مورد نظر خود را به تومان وارد کن (بیشتر از 5000 تومان)",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseMyWallet" && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    elseif($text < 5000){
        sendMessage("لطفا مقداری بیشتر از 5000 وارد کن");
        exit();
    }
    sendMessage("🪄 لطفا صبور باشید ...",$removeKeyboard);
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'INCREASE_WALLET' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, 'INCREASE_WALLET', '0', '0', '0', ?, ?, 'pending')");
    $stmt->bind_param("siii", $hash_id, $from_id, $text, $time);
    $stmt->execute();
    $stmt->close();
    
    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "increaseWalletWithCartToCart" . $hash_id]];
    if($botState['nowPaymentWallet'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];

    
	$keys = json_encode(['inline_keyboard'=>$keyboard]);
    sendMessage("اطلاعات شارژ:\nمبلغ ". number_format($text) . " تومان\n\nلطفا روش پرداخت را انتخاب کنید",$keys);
    setUser();
}
if(preg_match('/increaseWalletWithCartToCart(?<hashId>.*)/',$data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param('s', $match['hashId']);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    
    delMessage();  
    setUser($data);

    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['increase_wallet_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/increaseWalletWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = number_format($payInfo['price']);

    

        sendMessage($mainValues['order_increase_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        $msg = str_replace(['PRICE', 'USERNAME', 'NAME', 'USER-ID'],[$price, $username, $name, $from_id], $mainValues['increase_wallet_request_message']);
        
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approvePayment{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decPayment{$match[1]}"]
                ]
            ]
        ]);
        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/^approvePayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];
    
    if($payInfo['state'] == "approved") exit();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    

    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $userId);
    $stmt->execute();
    $stmt->close();

    sendMessage("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد",null,null,$userId);
    
    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
}
if(preg_match('/^decPayment(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '❌', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    file_put_contents("temp" . $from_id . ".txt", $keys);
    sendMessage("لطفا دلیل عدم تأیید افزایش موجودی را وارد کنید",$cancelKey);
    setUser("decPayment" . $message_id . "_" . $match[1]);
}
if(preg_match('/^decPayment(\d+)_(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $price = $payInfo['price'];
    $userId = $payInfo['user_id'];
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'declined' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[2]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("💔 افزایش موجودی شما به مبلغ "  . number_format($price) . " به دلیل زیر رد شد\n\n$text",null,null,$userId);


    editKeys(file_get_contents("temp" . $from_id . ".txt"), $match[1]);
    setUser();
    sendMessage('پیامت رو براش ارسال کردم ... 🤝',$removeKeyboard);
    unlink("temp" . $from_id . ".txt");
}
if($data=="increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "increaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("increaseWalletUser" . $text);
            sendMessage($mainValues['enter_increase_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^increaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage("✅ مبلغ " . number_format($text). " تومان به حساب شما اضافه شد",null,null,$match[1]);
        sendMessage("✅ مبلغ " . number_format($text) . " تومان به کیف پول کاربر مورد نظر اضافه شد",$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'],$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "decreaseUserWallet" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $userCount = $stmt->get_result()->num_rows;
        $stmt->close();
        if($userCount > 0){
            setUser("decreaseWalletUser" . $text);
            sendMessage($mainValues['enter_decrease_amount']);
        }
        else{
            setUser();
            sendMessage($mainValues['user_not_found'], $removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match('/^decreaseWalletUser(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
    
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_your_wallet']),null,null,$match[1]);
        sendMessage(str_replace("AMOUNT", number_format($text), $mainValues['amount_decreased_from_user_wallet']),$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        setUser();
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗|لطفا ربات رو در کانال ادمین کن و آیدی کانال رو بفرست",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editRewardChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botId = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getme"))->result->id;
    $result = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getChatMember?chat_id=$text&user_id=$botId"));
    if($result->ok){
        if($result->result->status == "administrator"){
            setSettings('rewardChannel', $text);
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage("😡|ای بابا ،ربات هنوز تو کانال عضو نشده، اول ربات رو تو کانال ادمین کن و آیدیش رو بفرست");
}
if($data=="editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🤗|لطفا ربات رو در کانال ادمین کن و آیدی کانال رو بفرست",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "editLockChannel" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $botId = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getme"))->result->id;
    $result = json_decode(file_get_contents("https://api.telegram.org/bot$botToken/getChatMember?chat_id=$text&user_id=$botId"));
    if($result->ok){
        if($result->result->status == "administrator"){
            setSettings("lockChannel", $text);
            sendMessage($mainValues['change_bot_settings_message'],getGateWaysKeys());
            setUser();
            exit();
        }
    }
    sendMessage($mainValues['the_bot_in_not_admin']);
}
if(($data == "agentOneBuy" || $data=='buySubscription' || $data == "agentMuchBuy") && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    if($botState['cartToCartState'] == "off" && $botState['walletState'] == "off"){
        alert($mainValues['selling_is_off']);
        exit();
    }
    if($data=="buySubscription") $buyType = "none";
    elseif($data=="agentOneBuy") $buyType = "one";
    elseif($data== "agentMuchBuy") $buyType = "much";
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `state` = 1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "selectServer{$id}_{$buyType}"];
    }
    $keyboard = array_chunk($keyboard,1);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    smartSendOrEdit($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
}
if($data=='createMultipleAccounts' && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 and `ucount` > 0 ORDER BY `id` ASC");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        sendMessage($mainValues['no_server_available']);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $flag = $cat['flag'];
        $keyboard[] = ['text' => "$flag $name", 'callback_data' => "createAccServer$id"];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"];
    $keyboard = array_chunk($keyboard,1);
    smartSendOrEdit($message_id, $mainValues['buy_sub_select_location'], json_encode(['inline_keyboard'=>$keyboard]));
    

}
if(preg_match('/createAccServer(\d+)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) ) {
    $sid = $match[1];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert("هیچ دسته بندی برای این سرور وجود ندارد");
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "createAccCategory{$id}_{$sid}"];
        }
        if(empty($keyboard)){
            alert("هیچ دسته بندی برای این سرور وجود ندارد");exit;
        }
        alert("♻️ | دریافت دسته بندی ...");
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createMultipleAccounts"];
        $keyboard = array_chunk($keyboard,1);
        smartSendOrEdit($message_id, "2️⃣ مرحله دو:

دسته بندی مورد نظرت رو انتخاب کن 🤭", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/createAccCategory(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match[1];
    $sid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert("💡پلنی در این دسته بندی وجود ندارد ");
    }else{
        alert("📍در حال دریافت لیست پلن ها");
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $keyboard[] = ['text' => "$name", 'callback_data' => "createAccPlan{$id}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "createAccServer$sid"];
        $keyboard = array_chunk($keyboard,1);
        smartSendOrEdit($message_id, "3️⃣ مرحله سه:

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/^createAccPlan(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("❗️لطفا مدت زمان اکانت را به ( روز ) وارد کن:",$cancelKey);
    setUser('createAccDate' . $match[1]);
}
if(preg_match('/^createAccDate(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        if($text >0){
            sendMessage("❕حجم اکانت ها رو به گیگابایت ( GB ) وارد کن:");
            setUser('createAccVolume' . $match[1] . "_" . $text);
        }else{
            sendMessage("عدد باید بیشتر از 0 باشه");
        }
    }else{
        sendMessage('😡 | مگه نمیگم فقط عدد بفرس نمیفهمی؟ یا خودتو زدی به نفهمی؟');
    }
}
if(preg_match('/^createAccVolume(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }
    sendMessage($mainValues['enter_account_amount']);
    setUser("createAccAmount" . $match[1] . "_" . $match[2] . "_" . $text);
}
if(preg_match('/^createAccAmount(\d+)_(\d+)_(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }elseif($text <=0){
        sendMessage("مقداری بزرگتر از 0 وارد کن");
        exit();
    }
    $uid = $from_id;
    $fid = $match[1];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $match[2];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $match[3];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount < $text) {
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $srv_remark = $serverInfo['remark'];
    $stmt->close();
    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0];
    $last_num = $savedinfo[1];
    include 'phpqrcode/qrlib.php';
    $ecc = 'L';
    $pixel_Size = 11;
    $frame_Size = 0;
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $serverType = $serverConfig['type'];
    $portType = $serverConfig['port_type'];
    $panelUrl = $serverConfig['panel_url'];
    $stmt->close();


	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?);");
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    for($i = 1; $i<= $text; $i++){
        $uniqid = generateRandomString(42,$protocol); 
        if($portType == "auto"){
            $port++;
        }else{
            $port = rand(1111,65000);
        }
        $last_num++;
        
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
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
            }
            else{
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
            sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
            break;
        }
    	if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
            break;
    	}
    	if(!$response->success){
            sendMessage('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
            sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
            break;
        }
    
        if($serverType == "marzban"){
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
            $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links);
        }
        else{
            $token = RandomString(30);
            $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
            $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
            $vray_link = json_encode($vraylink);
        }
        foreach($vraylink as $link){
            $acc_text = "
    
        🔮 $remark \n " . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"<code>$link</code>":"");
            if($botState['subLinkState'] == "on") $acc_text .= 
            " \n🌐 subscription : <code>$subLink</code>";
        
            $file = RandomString() .".png";
            
            QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);


        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
        $stmt->bind_param("ssiiisssisiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar);
        $stmt->execute();
    }
    $stmt->close();
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - ? WHERE id=?");
        $stmt->bind_param("ii", $text, $fid);
        $stmt->execute();
        $stmt->close();
    }
    sendMessage("☑️|❤️ اکانت های جدید با موفقیت ساخته شد",getMainKeys());
    setUser();
}
if(preg_match('/payWithTronWallet(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    
    $price = $payInfo['price'];
    $priceInTrx = round($price / $botState['TRXRate'],2);
    
    $stmt = $connection->prepare("UPDATE `pays` SET `tron_price` = ? WHERE `hash_id` = ?");
    $stmt->bind_param("ds", $priceInTrx, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage(str_replace(["AMOUNT", "TRON-WALLET"], [$priceInTrx, $paymentKeys['tronwallet']], $mainValues['pay_with_tron_wallet']), $cancelKey, "html");
    setUser($data);
}
if(preg_match('/^payWithTronWallet(.*)/',$userInfo['step'], $match) && $text != $buttonValues['cancel']){
    if(!preg_match('/^[0-9a-f]{64}$/i',$text)){
        sendMessage($mainValues['incorrect_tax_id']);
        exit(); 
    }else{
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `payid` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $checkExist = $stmt->get_result();
        $stmt->close();
        
        if($checkExist->num_rows == 0){
            $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ?, `state` = '0' WHERE `hash_id` = ?");
            $stmt->bind_param("ss", $text, $match[1]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['in_review_tax_id'], $removeKeyboard);
            setUser();
            sendMessage($mainValues['reached_main_menu'],getMainKeys());
        }else sendMessage($mainValues['used_tax_id']);
    }

}
if(preg_match('/payWithWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    $fid = $payInfo['plan_id'];
    $type = $payInfo['type'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($type != "INCREASE_WALLET" && $type != "RENEW_ACCOUNT"){
        if($acount <= 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit; 
            }
        }else{
            if($acount <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit();
            }
        }
    }
    
    if($type == "RENEW_ACCOUNT"){
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result();
        $stmt->close();
        if($order->num_rows == 0){
            delMessage();
            sendMessage($mainValues['config_not_found'], getMainKeys());
            exit();
        }

    }
    
    delMessage();
    sendMessage($mainValues['please_wait_message'],$removeKeyboard);
    
    
    $price = $payInfo['price'];
    $priceInUSD = round($price / $botState['USDRate'],2);
    $priceInTrx = round($price / $botState['TRXRate'],2);
    $pay = NOWPayments('POST', 'payment', [
        'price_amount' => $priceInUSD,
        'price_currency' => 'usd',
        'pay_currency' => 'trx'
    ]);
    if(isset($pay->pay_address)){
        $payAddress = $pay->pay_address;
        
        $payId = $pay->payment_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `payid` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("is", $payId, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پرداخت با درگاه ارزی ریالی",'url'=>"https://changeto.technology/quick?amount=$priceInTrx&currency=TRX&address=$payAddress"]],
            [['text'=>"پرداخت کردم ✅",'callback_data'=>"havePaiedWeSwap" . $match[1]]]
            ]]);
sendMessage("
✅ لینک پرداخت با موفقیت ایجاد شد

💰مبلغ : " . $priceInTrx . " ترون

✔️ بعد از پرداخت حدود 1 الی 15 دقیقه صبر کنید تا پرداخت به صورت کامل انجام شود سپس روی پرداخت کردم کلیک کنید
⁮⁮ ⁮⁮
",$keys);
    }else{
        if($pay->statusCode == 400){
            sendMessage("مقدار انتخاب شده کمتر از حد مجاز است");
        }else{
            sendMessage("مشکلی رخ داده است، لطفا به پشتیبانی اطلاع بدهید");
        }
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید",getMainKeys());
    }
}
if(preg_match('/havePaiedWeSwap(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    if($payInfo['state'] == "pending"){
    $payid = $payInfo['payid'];
    $payType = $payInfo['type'];
    $price = $payInfo['price'];

    $request_json = NOWPayments('GET', 'payment', $payid);
    if($request_json->payment_status == 'finished' or $request_json->payment_status == 'confirmed' or $request_json->payment_status == 'sending'){
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
        
    if($payType == "INCREASE_WALLET"){
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("افزایش حساب شما با موفقیت تأیید شد\n✅ مبلغ " . number_format($price). " تومان به حساب شما اضافه شد");
        sendToAdmins("✅ مبلغ " . number_format($price) . " تومان به کیف پول کاربر $from_id توسط درگاه ارزی ریالی اضافه شد");                
    }
    elseif($payType == "BUY_SUB"){
    $uid = $from_id;
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
    
    $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
    $eachPrice = $price / $accountCount;
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
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
    include 'phpqrcode/qrlib.php';

    alert($mainValues['sending_config_to_user']);
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    for($i = 1; $i <= $accountCount; $i++){
        $uniqid = generateRandomString(42,$protocol);
        
        $savedinfo = file_get_contents('settings/temp.txt');
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
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
        if(!empty($description)) $remark = $description;
        if($portType == "auto"){
            file_put_contents('settings/temp.txt',$port.'-'.$last_num);
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
            sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
            exit;
        }
        if($response == "inbound not Found"){
            sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        	exit;
        }
        if(!$response->success){
            sendMessage('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
            sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
            exit;
        }
        
        if($serverType == "marzban"){
            $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
            $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
            $vraylink = [$subLink];
            $vray_link = json_encode($response->vray_links);
        }else{
            $token = RandomString(30);
            $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
    
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

if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>


🌐 subscription : <code>$subLink</code>
        
        ";
              
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;
            
            QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
        	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);

        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
        
        $agentBought = $payInfo['agent_bought'];
        
        $stmt = $connection->prepare("INSERT INTO `orders_list` 
            (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
            VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
        $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agentBought);
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
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"deltach"]
        ],
        ]]);
        
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
    $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'ارزی ریالی', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);
    
    sendToAdmins($msg, $keys, "html");
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
        	alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
        	exit;
        }
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
        $newExpire = $time + $days * 86400;
        $stmt->bind_param("ii", $newExpire, $oid);
        $stmt->execute();
        $stmt->close();
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
    
    sendMessage("✅سرویس $remark با موفقیت تمدید شد",getMainKeys());
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"به به تمدید 😍",'callback_data'=>"deltach"]
            ],
        ]]);
    
        $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);
    
    sendToAdmins($msg, $keys, "html");
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
        $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅$volume روز به مدت زمان سرویس شما اضافه شد",getMainKeys());
        
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی زمان زد 😁",'callback_data'=>"deltach"]
                ],
            ]]);
    sendToAdmins("
    🔋|💰 افزایش زمان با ( کیف پول )
    
    ▫️آیدی کاربر: $from_id
    👨‍💼اسم کاربر: $first_name
    ⚡️ نام کاربری: $username
    🎈 نام سرویس: $remark
    ⏰ مدت افزایش: $volume روز
    💰قیمت: $price تومان
    ⁮⁮ ⁮⁮
    ", $keys, "html");
    
        exit;
    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید", true);
        exit;
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
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی حجم زد 😁",'callback_data'=>"deltach"]
                ],
            ]]);
    sendToAdmins("
    🔋|💰 افزایش حجم با ( کیف پول )
    
    ▫️آیدی کاربر: $from_id
    👨‍💼اسم کاربر: $first_name
    ⚡️ نام کاربری: $username
    🎈 نام سرویس: $remark
    ⏰ مدت افزایش: $volume گیگ
    💰قیمت: $price تومان
    ⁮⁮ ⁮⁮
    ", $keys, "html");
        sendMessage( "✅$volume گیگ به حجم سرویس شما اضافه شد",getMainKeys());exit;
        
    
    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید",true);
        exit;
    }
    }
    elseif($payType == "RENEW_SCONFIG"){
        $uid = $from_id;
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
    		alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
    
        sendToAdmins("
        🔋|💰 تمدید مشخصات کانفیگ با ( کیف پول )
        
        ▫️آیدی کاربر: $from_id
        👨‍💼اسم کاربر: $first_name
        ⚡️ نام کاربری: $username
        🎈 نام سرویس: $remark
        ⏰ مدت کانفیگ: $volume گیگ
        حجم کانفیگ:  $days روز
        💰قیمت: $price تومان
        ⁮⁮ ⁮⁮
        ", $keys, "html");
    
    }
        
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"پرداخت انجام شد",'callback_data'=>"deltach"]]
		    ]]));
}else{
    if($request_json->payment_status == 'partially_paid'){
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'partiallyPaied' WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $stmt->close();
        alert("شما هزینه کمتری پرداخت کردید، لطفا به پشتیبانی پیام بدهید");
    }else{
        alert("پرداخت مورد نظر هنوز تکمیل نشده!");
    }
}
}else alert("این لینک پرداخت منقضی شده است");
}
if($data=="messageToSpeceficUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage($mainValues['send_user_id'], $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "messageToSpeceficUser" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!is_numeric($text)){
        sendMessage($mainValues['send_only_number']);
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i", $text);
    $stmt->execute();
    $usersCount = $stmt->get_result()->num_rows;
    $stmt->close();

    if($usersCount > 0 ){
        sendMessage("👀| خصوصی میخوای بهش پیام بدی شیطون، پیامت رو بفرس تا در گوشش بگم:");
        setUser("sendMessageToUser" . $text);
    }else{
        sendMessage($mainValues['user_not_found']);
    }
}
if($data == 'message2All' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` = 1");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();
    
    if($info->num_rows > 0){
        $sendInfo = $info->fetch_assoc();
        
        $offset = $sendInfo['offset']??0;
        $type = $sendInfo['type'];
        
        $stmt = $connection->prepare("SELECT * FROM `users`");
        $stmt->execute();
        $usersCount = $stmt->get_result()->num_rows;
        $stmt->close();

        $leftMessages = $usersCount - $offset;
        
        if($type == "forwardall"){
            sendMessage("
            ❗️ یک فروارد همگانی در صف انتشار می باشد لطفا صبور باشید ...
            
            🔰 تعداد کاربران : $usersCount
            ☑️ فروارد شده : $offset
            📣 باقیمانده : $leftMessages
            ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }else{
            sendMessage("
            ❗️ یک پیام همگانی در صف انتشار می باشد لطفا صبور باشید ...
            
            🔰 تعداد کاربران : $usersCount
            ☑️ ارسال شده : $offset
            📣 باقیمانده : $leftMessages
            ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }
    }else{
        setUser('s2a');
        sendMessage("لطفا پیامت رو بنویس ، میخوام برا همه بفرستمش: 🙂",$cancelKey);
    }
}
if($userInfo['step'] == 's2a' and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();

    if($fileid !== null) {
        $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `text`, `file_id`) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $filetype, $caption, $fileid);
    }
    else{
        $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `text`) VALUES ('text', ?)");
        $stmt->bind_param("s", $text);
    }
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    
    sendMessage('⏳ مرسی از پیامت  ...  ',$removeKeyboard);
    sendMessage("برای همه بفرستم؟",json_encode(['inline_keyboard'=>[
    [['text'=>"بفرست",'callback_data'=>"yesSend2All" . $id],['text'=>"نه نفرست",'callback_data'=>"noDontSend2all" . $id]]
    ]]));
}
if(preg_match('/^noDontSend2all(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `send_list` WHERE `id` = ?");
    $stmt->bind_param('i', $match[1]);
    $stmt->exeucte();
    $stmt->close();
    
    smartSendOrEdit($message_id,'ارسال پیام همگانی لغو شد',getMainKeys());
}
if(preg_match('/^yesSend2All(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `send_list` SET `state` = 1 WHERE `id` = ?") ;
    $stmt->bind_param('i', $match[1]);
    $stmt->execute();
    $stmt->close();
    // Start broadcast worker in background (works for main + reseller bots on VPS)
    $worker = __DIR__ . '/broadcast_worker.php';
    if(file_exists($worker) && function_exists('shell_exec')){
        $cmd = 'nohup php ' . escapeshellarg($worker) . ' >/dev/null 2>&1 &';
        @shell_exec($cmd);
    }
    
    smartSendOrEdit($message_id,'⏳ کم کم برا همه ارسال میشه ...  ',getMainKeys());
}
if($data=="forwardToAll" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `send_list` WHERE `state` = 1");
    $stmt->execute();
    $info = $stmt->get_result();
    $stmt->close();
    
    if($info->num_rows > 0){
        $sendInfo = $info->fetch_assoc();
        $offset = $sendInfo['offset']??0;
        $type = $sendInfo['type'];
        
        $stmt = $connection->prepare("SELECT * FROM `users`");
        $stmt->execute();
        $usersCount = $stmt->get_result()->num_rows;
        $stmt->close();
        
        $leftMessages = $usersCount - $offset;
        
        if($type == "forwardall"){
            sendMessage("
            ❗️ یک فروارد همگانی در صف انتشار می باشد لطفا صبور باشید ...
            
            🔰 تعداد کاربران : $usersCount
            ☑️ فروارد شده : $offset
            📣 باقیمانده : $leftMessages
            ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }else{
            sendMessage("
            ❗️ یک پیام همگانی در صف انتشار می باشد لطفا صبور باشید ...
            
            🔰 تعداد کاربران : $usersCount
            ☑️ ارسال شده : $offset
            📣 باقیمانده : $leftMessages
            ⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
            ");
        }
    }else{
        delMessage();
        sendMessage($mainValues['forward_your_message'], $cancelKey);
        setUser($data);
    }
}
if($userInfo['step'] == "forwardToAll" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("INSERT INTO `send_list` (`type`, `message_id`, `chat_id`) VALUES ('forwardall', ?, ?)");
    $stmt->bind_param('ss', $message_id, $chat_id);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    setUser();
    sendMessage('⏳ مرسی از پیامت  ...  ',$removeKeyboard);
    sendMessage("برای همه فروارد کنم؟",json_encode(['inline_keyboard'=>[
    [['text'=>"بفرست",'callback_data'=>"yesSend2All" . $id],['text'=>"نه نفرست",'callback_data'=>"noDontSend2all" . $id]]
    ]]));
}
if(preg_match('/selectServer(?<serverId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true)) ) {
    $sid = $match['serverId'];
        
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent`=0 order by `id` asc");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert($mainValues['category_not_avilable']);
    }else{
        
        $keyboard = [];
        while ($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1");
            $stmt->bind_param("ii", $sid, $id);
            $stmt->execute();
            $rowcount = $stmt->get_result()->num_rows; 
            $stmt->close();
            if($rowcount>0) $keyboard[] = ['text' => "$name", 'callback_data' => "selectCategory{$id}_{$sid}_{$match['buyType']}"];
        }
        if(empty($keyboard)){
            alert($mainValues['category_not_avilable']);exit;
        }
        alert($mainValues['receive_categories']);

        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => 
        ($match['buyType'] == "one"?"agentOneBuy":($match['buyType'] == "much"?"agentMuchBuy":"buySubscription"))];
        $keyboard = array_chunk($keyboard,1);
        $txt = $mainValues['buy_sub_select_category'];
        // Show user percent discount (if any) in all steps
        $txt = appendUserDiscountLine($from_id, $txt);
        smartSendOrEdit($message_id,$txt, json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCategory(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `price` != 0 and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows==0){
        alert($mainValues['no_plan_available']); 
    }else{
        alert($mainValues['receive_plans']);
        $keyboard = [];
        while($file = $respd->fetch_assoc()){
            $id = $file['id'];
            $name = $file['title'];
            $price = $file['price'];
            if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){
                $discounts = json_decode($userInfo['discount_percent'],true);
                if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
                else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
                
                $price -= floor($price * $discount / 100);
            }
    $price = applyUserPercentDiscount($from_id, (int)$price);
            $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
            $keyboard[] = ['text' => "$name - $price", 'callback_data' => "selectPlan{$id}_{$call_id}_{$match['buyType']}"];
        }
        if($botState['plandelkhahState'] == "on" && $match['buyType'] != "much"){
	        $keyboard[] = ['text' => $mainValues['buy_custom_plan'], 'callback_data' => "selectCustomPlan{$call_id}_{$sid}_{$match['buyType']}"];
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
        $keyboard = array_chunk($keyboard,1);
        $txt = $mainValues['buy_sub_select_plan'];
        $txt = appendUserDiscountLine($from_id, $txt);
        smartSendOrEdit($message_id,$txt, json_encode(['inline_keyboard'=>$keyboard]));
    }

}
if(preg_match('/selectCustomPlan(?<categoryId>\d+)_(?<serverId>\d+)_(?<buyType>\w+)/',$data,$match) && ($botState['sellState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)) {
    $call_id = $match['categoryId'];
    $sid = $match['serverId'];
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? and `catid`=? and `active`=1 order by `id` asc");
    $stmt->bind_param("ii", $sid, $call_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    alert($mainValues['receive_plans']);
    $keyboard = [];
    while($file = $respd->fetch_assoc()){
        $id = $file['id'];
        $name = preg_replace("/پلن\s(\d+)\sگیگ\s/","",$file['title']);
        $keyboard[] = ['text' => "$name", 'callback_data' => "selectCustomePlan{$id}_{$call_id}_{$match['buyType']}"];
    }
    $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "selectServer{$sid}_{$match['buyType']}"];
    $keyboard = array_chunk($keyboard,1);
    smartSendOrEdit($message_id, $mainValues['select_one_plan_to_edit'], json_encode(['inline_keyboard'=>$keyboard]));

}
if(preg_match('/selectCustomePlan(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id == $admin)){
	delMessage();
	$price = $botState['gbPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){ 
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$match[1]]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
	}
	sendMessage(str_replace("VOLUME-PRICE", $price, $mainValues['customer_custome_plan_volume']),$cancelKey);
	setUser("selectCustomPlanGB" . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
}
if(preg_match('/selectCustomPlanGB(?<planId>\d+)_(?<categoryId>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡|لطفا فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفا عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage(" عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }
    
    $id = $match['planId'];
    $price = $botState['dayPrice'];
	if($match['buyType'] == "one" && $userInfo['is_agent'] == true){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
        $stmt->close();

        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
	}
    
	sendMessage(str_replace("DAY-PRICE", $price, $mainValues['customer_custome_plan_day']));
	setUser("selectCustomPlanDay" . $id . "_" . $match['categoryId'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/selectCustomPlanDay(?<planId>\d+)_(?<categoryId>\d+)_(?<accountCount>\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id == $admin) && $text != $buttonValues['cancel']){
    if(!is_numeric($text)){
        sendMessage("😡|لطفا فقط عدد ارسال کن");
        exit();
    }
    elseif($text <1){
        sendMessage("لطفا عددی بزرگتر از 0 وارد کن");
        exit();
    }
    elseif(strstr($text,".")){
        sendMessage("عدد اعشاری مجاز نیست");
        exit();
    }
    elseif(substr($text, 0, 1) == '0'){
        sendMessage("❌عدد وارد شده نمیتواند با 0 شروع شود!");
        exit();
    }

	sendMessage($mainValues['customer_custome_plan_name']);
	setUser("enterCustomPlanName" . $match['planId'] . "_" . $match['categoryId'] . "_" . $match['accountCount'] . "_" . $text . "_" . $match['buyType']);
}
if((preg_match('/^discountCustomPlanDay(\d+)/',$userInfo['step'], $match) || preg_match('/enterCustomPlanName(\d+)_(\d+)_(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match)) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $rowId = $match[1];

        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $price = $payInfo['price'];
        $id = $payInfo['type'];
    	$volume = $payInfo['volume'];
        $days = $payInfo['day'];
        $stmt->close();
            
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
            
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $price * $amount / 100;
                    $price -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $price -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($price < 0) $price = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $price, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"deltach"]
                        ],
                    ]]);
            sendToAdmins(
                str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                ,$keys,null);
                }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
    }else{
        $id = $match[1];
    	$call_id = $match[2];
    	$volume = $match[3];
        $days = $match[4];
        if($match['buyType'] != "much"){
            if(preg_match('/^[a-z]+[0-9]+$/',$text)){} else{
                sendMessage($mainValues['incorrect_config_name']);
                exit();
            }
        }
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $token = base64_encode("{$from_id}.{$id}");

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])){
        $discountPrice = 0;
        $gbPrice = $botState['gbPrice'];
        $dayPrice = $botState['dayPrice'];
        
        if($userInfo['is_agent'] == true && $match['buyType'] == "one") {
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param("i", $match[1]);
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
            
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
            else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
            
            $gbPrice -= floor($gbPrice * $discount /100);
            $dayPrice -= floor($dayPrice * $discount / 100);
        }
        
        $agentBought = false;
        if($userInfo['is_agent'] == 1 && ($match['buyType'] == "one" || $match['buyType'] == "much")) {
            $agentBought = true;
        }
        
        $price =  ($volume * $gbPrice) + ($days * $dayPrice);
        // per-user discount (set in user info panel)
        $price = applyUserPercentDiscount($from_id, $price);
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();

        // apply user percent discount (set in user info panel)
        $price = applyUserPercentDiscount($from_id, $price);

        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`)
                                    VALUES (?, ?, ?, 'BUY_SUB', ?, ?, ?, ?, ?, 'pending', ?)");
        $stmt->bind_param("ssiiiiiii", $hash_id, $text, $from_id, $id, $volume, $days, $price, $time, $agentBought);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payCustomWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payCustomWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountCustomPlanDay/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountCustom_" . $rowId]];
	$keyboard[] = [['text' => $buttonValues['cancel'], 'callback_data' => "mainMenu"]];
    $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
    sendMessage(str_replace(['VOLUME', 'DAYS', 'PLAN-NAME', 'PRICE', 'DESCRIPTION'], [$volume, $days, $name, $price, $desc], $mainValues['buy_subscription_detail']),json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    setUser();
}
if(preg_match('/^haveDiscount(.+?)_(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['insert_discount_code'],$cancelKey);
    if($match[1] == "Custom") setUser('discountCustomPlanDay' . $match[2]);
    elseif($match[1] == "SelectPlan") setUser('discountSelectPlan' . $match[2]);
    elseif($match[1] == "Renew") setUser('discountRenew' . $match[2]);
}
if($data=="getTestAccount"){
    if($userInfo['freetrial'] != null && $from_id != $admin && $userInfo['isAdmin'] != true){
        alert("شما اکانت تست را قبلا استفاده کرده اید");
        exit();
    }
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `price`=0");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    
    if($respd->num_rows > 0){
        alert($mainValues['receving_information']);
    	$keyboard = array();
        while ($row = $respd->fetch_assoc()){
            $id = $row['id'];
            $catInfo = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $catInfo->bind_param("i", $row['catid']);
            $catInfo->execute();
            $catname = $catInfo->get_result()->fetch_assoc()['title'];
            $catInfo->close();
            
            $name = $catname." ".$row['title'];
            $price =  $row['price'];
            $desc = $row['descr'];
        	$sid = $row['server_id'];

            $keyboard[] = [['text' => $name, 'callback_data' => "freeTrial{$id}_normal"]];

        }
    	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
        smartSendOrEdit($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید", json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }else alert("این بخش موقتا غیر فعال است");
}
if((preg_match('/^discountSelectPlan(\d+)_(\d+)_(\d+)/',$userInfo['step'],$match) || 
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) || 
    preg_match('/enterAccountName(\d+)_(\d+)_(?<buyType>\w+)/',$userInfo['step'], $match) || 
    preg_match('/selectPlan(\d+)_(\d+)_(?<buyType>\w+)/',$data, $match)) && 
    ($botState['sellState']=="on" ||$from_id ==$admin) && 
    $text != $buttonValues['cancel']){
    if(preg_match('/^discountSelectPlan/', $userInfo['step'])){
        $rowId = $match[3];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $canUse = $discountInfo['can_use'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"deltach"]
                        ],
                    ]]);
                sendToAdmins(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }elseif(isset($data)) delMessage();


    if($botState['remark'] ==  "manual" && preg_match('/^selectPlan/',$data) && $match['buyType'] != "much"){
        sendMessage($mainValues['customer_custome_plan_name'], $cancelKey);
        setUser('enterAccountName' . $match[1] . "_" . $match[2] . "_" . $match['buyType']);
        exit();
    }

    $remark = "";
    if(preg_match("/selectPlan(\d+)_(\d+)_(\w+)/",$userInfo['step'])){
        if($match['buyType'] == "much"){
            if(is_numeric($text)){
                if($text > 0){
                    $accountCount = $text;
                    setUser();
                }else{sendMessage( $mainValues['send_positive_number']); exit(); }
            }else{ sendMessage($mainValues['send_only_number']); exit(); }
        }        
    }
    elseif(preg_match("/enterAccountName(\d+)_(\d+)/",$userInfo['step'])){
        if(preg_match('/^[a-z]+[0-9]+$/',$text)){
            $remark = $text;
            setUser();
        } else{
            sendMessage($mainValues['incorrect_config_name']);
            exit();
        }
    }
    else{
        if($match['buyType'] == "much"){
            setUser($data);
            sendMessage($mainValues['enter_account_amount'], $cancelKey);
            exit();
        }
    }
    
    
    $id = $match[1];
	$call_id = $match[2];
    alert($mainValues['receving_information']);
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    if(isset($accountCount)) $price *= $accountCount;
    
    $agentBought = false;
    if($userInfo['is_agent'] == true && ($match['buyType'] == "one" || $match['buyType'] == "much")){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$sid]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);

        $agentBought = true;
    }
    if($price == 0 or ($from_id == $admin)){
        $keyboard[] = [['text' => '📥 دریافت رایگان', 'callback_data' => "freeTrial{$id}_{$match['buyType']}"]];
        setUser($remark, 'temp');
    }else{
        $token = base64_encode("{$from_id}.{$id}");
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])){
            $hash_id = RandomString();
            $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'BUY_SUB' AND `state` = 'pending'");
            $stmt->bind_param("i", $from_id);
            $stmt->execute();
            $stmt->close();
            
            // apply user percent discount (from user info panel)
            $price = applyUserPercentDiscount($from_id, $price);

            $time = time();
            if(isset($accountCount)){
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`, `agent_count`)
                                            VALUES (?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?, ?)");
                $stmt->bind_param("siiiiii", $hash_id, $from_id, $id, $price, $time, $agentBought, $accountCount);
            }else{
                $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`, `agent_bought`)
                                            VALUES (?, ?, ?, 'BUY_SUB', ?, '0', '0', ?, ?, 'pending', ?)");
                $stmt->bind_param("ssiiiii", $hash_id, $remark, $from_id, $id, $price, $time, $agentBought);
            }
            $stmt->execute();
            $rowId = $stmt->insert_id;
            $stmt->close();
        }else{
            $price = applyUserPercentDiscount($from_id, $afterDiscount);
        }
        
        if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
        if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
        if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
        if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
        if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
        if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
        if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];
        
        if(!preg_match('/^discountSelectPlan/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountSelectPlan_" . $match[1] . "_" . $match[2] . "_" . $rowId]];

    }
	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "selectCategory{$call_id}_{$sid}_{$match['buyType']}"]];
    $priceC = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
    if(isset($accountCount)){
        $eachPrice = number_format($price / $accountCount) . " تومان";
        $currentWallet = (int)($userInfo['wallet'] ?? 0);
        $walletAfter = $currentWallet - (int)$price;
        if($walletAfter < 0) $walletAfter = 0;
        $msg = str_replace(
            ['ACCOUNT-COUNT','TOTAL-PRICE','PLAN-NAME','PRICE','DESCRIPTION','CURRENT-WALLET','WALLET-AFTER'],
            [$accountCount, $priceC, $name, $eachPrice, $desc, number_format($currentWallet), number_format($walletAfter)],
            $mainValues['buy_much_subscription_detail']
        );
    }else{
        $basePrice = (int)($respd['price'] ?? $price);
        $discountAmount = $basePrice - (int)$price;
        if($discountAmount < 0) $discountAmount = 0;
        $discountPercent = ($basePrice > 0) ? floor(($discountAmount * 100) / $basePrice) : 0;
        $currentWallet = (int)($userInfo['wallet'] ?? 0);
        $walletAfter = $currentWallet - (int)$price;
        if($walletAfter < 0) $walletAfter = 0;
        $msg = str_replace(
            ['PLAN-NAME','BASE-PRICE','FINAL-PRICE','DESCRIPTION','CURRENT-WALLET','WALLET-AFTER','PLAN-VOLUME','PLAN-DAYS','DISCOUNT-AMOUNT','DISCOUNT-PERCENT'],
            [$name, number_format($basePrice).' تومان', $priceC, $desc, number_format($currentWallet), number_format($walletAfter), ($respd['volume']??0), ($respd['days']??0), number_format($discountAmount), $discountPercent],
            $mainValues['buy_subscription_detail']
        );
    }
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/payCustomWithWallet(.*)/',$data, $match)){
    setUser();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();
    
    if($payInfo['state'] == "paid_with_wallet") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $uid = $from_id;
    $fid = $payInfo['plan_id']; 
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    
    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $price = $payInfo['price'];

    if($userInfo['wallet'] < $price){
        alert("موجودی حساب شما کم است");
        exit();
    }
    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
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

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$from_id}-{$rnd}";
    $remark = $payInfo['description']; 
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
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
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
        sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    include 'phpqrcode/qrlib.php';
    
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
        $vraylink = [$subLink];
        $vray_link = json_encode($response->vray_links);
    }
    else{
        $token = RandomString(30);
        $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
    
        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
        $vray_link = json_encode($vraylink);
    }
    delMessage();
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $link){
        $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>


🌐 subscription : <code>$subLink</code>"; 
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
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
    
    $agentBought = $payInfo['agent_bought'];
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
    $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result(); 
    $stmt->close();
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"deltach"]
        ],
        ]]);
    $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
    sendToAdmins($msg, $keys, "html");
}
if(preg_match('/^showQr(Sub|Config)(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $match[2]);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    include 'phpqrcode/qrlib.php';
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    if($match[1] == "Sub"){
        $subLink = $botUrl . "settings/subLink.php?token=" . $order['token'];
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        QRcode::png($subLink, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
    }
    elseif($match[1] == "Config"){

        
        
        $vraylink = json_decode($order['link'],true);
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        foreach($vraylink as $vray_link){
            $file = RandomString() .".png";
            $ecc = 'L';
            $pixel_Size = 11;
            $frame_Size = 0;
            
            QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
        	addBorderImage($file);
            	
        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
            $qrImage = imagecreatefrompng($file);
            
            $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
            imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
            imagepng($backgroundImage, $file);
            imagedestroy($backgroundImage);
            imagedestroy($qrImage);
            
        	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
            unlink($file);
        }
    }
}
if(preg_match('/payCustomWithCartToCart(.*)/',$data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];


    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }else{
        if($acount != 0 && $acount <= 0){
            sendMessage(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
            exit();
        }
    }
    
    setUser($data);
    delMessage();
    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['buy_account_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/payCustomWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $fid = $payInfo['plan_id'];
        $volume = $payInfo['volume'];
        $days = $payInfo['day'];
        
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $res['catid']);
        $stmt->execute();
        $catname = $stmt->get_result()->fetch_assoc()['title'];
        $stmt->close();
        $filename = $catname." ".$res['title']; 
        $fileprice = $payInfo['price'];
        $remark = $payInfo['description'];
        
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        $msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                            ["کارت به کارت", $from_id, $username, $first_name, $fileprice, $remark,$volume, $days], $mainValues['buy_custom_account_request']);
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "accCustom" . $match[1]],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decline$uid"]
                ]
            ]
        ]);
        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->execute();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/accCustom(.*)/',$data, $match) and $text != $buttonValues['cancel']){
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($payInfo['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $fid = $payInfo['plan_id'];
    $volume = $payInfo['volume'];
    $days = $payInfo['day'];
    $uid = $payInfo['user_id'];

    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

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

    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0) {
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }

    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
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

    // $rnd = rand(1111,99999);
    // $remark = "{$srv_remark}-{$uid}-{$rnd}";
    $remark = $payInfo['description'];
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
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
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
        sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
    
    include 'phpqrcode/qrlib.php';
    
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
        $vraylink = [$subLink];
        $vray_link= json_encode($response->vray_links);
    }
    else{
        $token = RandomString(30);
        $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
    
        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id);
        $vray_link= json_encode($vraylink);
    }
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);

    foreach($vraylink as $vray_link){
        $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز⁮⁮ ⁮⁮
" . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"
💝 config : <code>$vray_link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString() .".png";
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
    
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
    	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

    	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
        unlink($file);
    }
    sendMessage('✅ کانفیگ و براش ارسال کردم', getMainKeys());
    
    $agentBought = $payInfo['agent_bought'];
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
    $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();


    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"deltach"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);


    editKeys($keys);
    
    $filename = $file_detail['title'];
    $fileprice = number_format($file_detail['price']);
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user_detail= $stmt->get_result()->fetch_assoc();
    $stmt->close();


    if($user_detail['refered_by'] != null){
        $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
        $stmt->execute();
        $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
        $stmt->close();
        $inviterId = $user_detail['refered_by'];
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $inviteAmount, $inviterId);
        $stmt->execute();
        $stmt->close();
         
        sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
    }

    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE id=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $stmt->close();
    }

    $uname = $user_detail['name'];
    $user_name = $user_detail['username'];
    
    if($admin != $from_id){ 
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"به به 🛍",'callback_data'=>"deltach"]
            ],
            ]]);
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
            [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
        sendToAdmins($msg);
    }
    
}
if(preg_match('/payWithWallet(.*)/',$data, $match)){
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();
    
    $uid = $from_id;
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    if($payInfo['state'] == "paid_with_wallet") exit();
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $price = $payInfo['price'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    if($userInfo['wallet'] < $price){
        alert("موجودی حساب شما کم است");
        exit();
    }

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    
    
    $server_id = $file_detail['server_id'];
    $netType = $file_detail['type'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];


    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
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
        
    	if(is_null($response)){
    		alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]
            ],
            ]]);
        smartSendOrEdit($message_id,"✅سرویس $remark با موفقیت تمدید شد",$keys);
    }else{
        $accountCount = $payInfo['agent_count']!=0?$payInfo['agent_count']:1;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }        
    
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $srv_remark = $serverInfo['remark'];
        $serverTitle = $serverInfo['title'];
        $stmt->close();
    
        $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverConfig = $stmt->get_result()->fetch_assoc();
        $portType = $serverConfig['port_type'];
        $serverType = $serverConfig['type'];
        $panelUrl = $serverConfig['panel_url'];
        $stmt->close();

        include 'phpqrcode/qrlib.php';
        $msg = $message_id;

        $agent_bought = $payInfo['agent_bought'];
	    $eachPrice = $price / $accountCount;

        alert($mainValues['sending_config_to_user']);
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
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
                $remark = "{$srv_remark}-{$from_id}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
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
                }
                else{
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
                sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
                sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
                exit;
            }
        
        
            if($serverType == "marzban"){
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
                $vraylink = [$subLink];
                $vray_link= json_encode($response->vray_links);
            }
            else{
                $token = RandomString(30);
                $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
                $vray_link= json_encode($vraylink);
                $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
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
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
                
                QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
                unlink($file);
            }
            
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result(); 
            $stmt->close();
        }
    
        delMessage($msg);
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
    $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
    $stmt->bind_param("ii", $price, $uid);
    $stmt->execute();
    $stmt->close();
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"بنازم خرید جدید ❤️",'callback_data'=>"deltach"]
        ],
        ]]);
    if($payInfo['type'] == "RENEW_SCONFIG"){$msg = str_replace(['TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                ['کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['renew_account_request_message']);}
    else{$msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'VOLUME', 'DAYS'],
                [$serverTitle, 'کیف پول', $from_id, $username, $first_name, $price, $remark,$volume, $days], $mainValues['buy_new_account_request']);}

    sendToAdmins($msg, $keys, "html");
}
if(preg_match('/payWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();
    
    $fid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];

    if($payInfo['type'] != "RENEW_SCONFIG"){
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0) {
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }else{
            if($acount <= 0){
                alert(str_replace("AMOUNT", $acount, $mainValues['can_create_specific_account']));
                exit();
            }
        }
    }
    
    setUser($data);
    delMessage();
    sendMessage(str_replace(["ACCOUNT-NUMBER", "HOLDER-NAME"],[$paymentKeys['bankAccount'],$paymentKeys['holderName']], $mainValues['buy_account_cart_to_cart']),$cancelKey, "HTML");
    exit;
}
if(preg_match('/payWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        
        $fid = $payInfo['plan_id'];
        setUser();
        $uid = $userInfo['userid'];
        $name = $userInfo['name'];
        $username = $userInfo['username'];
    
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $days = $res['days'];
        $volume = $res['volume'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $res['server_id']);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $serverTitle = $serverInfo['title'];
    
        if($payInfo['type'] == "RENEW_SCONFIG"){
            $configInfo = json_decode($payInfo['description'],true);
            $filename = $configInfo['remark'];
        }else{
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
            $stmt->bind_param("i", $res['catid']);
            $stmt->execute();
            $catname = $stmt->get_result()->fetch_assoc()['title'];
            $stmt->close();
            $filename = $catname." ".$res['title']; 
        }
        $fileprice = $payInfo['price'];
    
        sendMessage($mainValues['order_buy_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        if($payInfo['agent_count'] != 0) $msg = str_replace(['ACCOUNT-COUNT', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK"],[$payInfo['agent_count'], 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename], $mainValues['buy_new_much_account_request']);
        else $msg = str_replace(['SERVERNAME', 'TYPE', 'USER-ID', "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],[$serverTitle, 'کارت به کارت', $from_id, $username, $name, $fileprice, $filename, $volume, $days], $mainValues['buy_new_account_request']);

        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "accept" . $match[1] ],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decline$uid"]
                ]
            ]
        ]);
        setUser('', 'temp');
        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if($data=="availableServers"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `acount` != 0 AND `inbound_id` != 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"تعداد باقیمانده",'callback_data'=>"deltach"],
        ['text'=>"پلن",'callback_data'=>"deltach"],
        ['text'=>'سرور','callback_data'=>"deltach"]
        ];
    while($file_detail = $serversList->fetch_assoc()){
        $days = $file_detail['days'];
        $title = $file_detail['title'];
        $server_id = $file_detail['server_id'];
        $acount = $file_detail['acount'];
        $inbound_id = $file_detail['inbound_id'];
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $name = $name->fetch_assoc()['title'];
            
            $keys[] = [
                ['text'=>$acount . " اکانت",'callback_data'=>"deltach"],
                ['text'=>$title??" ",'callback_data'=>"deltach"],
                ['text'=>$name??" ",'callback_data'=>"deltach"]
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    smartSendOrEdit($message_id, "🟢 | موجودی پلن اشتراکی:", $keys);
}
if($data=="availableServers2"){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `inbound_id` = 0");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();

    $keys = array();
    $keys[] = [
        ['text'=>"تعداد باقیمانده",'callback_data'=>"deltach"],
        ['text'=>'سرور','callback_data'=>"deltach"]
        ];
    while($file_detail2 = $serversList->fetch_assoc()){
        $days2 = $file_detail2['days'];
        $title2 = $file_detail2['title'];
        $server_id2 = $file_detail2['server_id'];
        $inbound_id2 = $file_detail2['inbound_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
        $stmt->bind_param("i", $server_id2);
        $stmt->execute();
        $name = $stmt->get_result();
        $stmt->close();

        if($name->num_rows>0){
            $sInfo = $name->fetch_assoc();
            $name = $sInfo['title'];
            $acount2 = $sInfo['ucount'];
            
            $keys[] = [
                ['text'=>$acount2 . " اکانت",'callback_data'=>"deltach"],
                ['text'=>$title2??" ",'callback_data'=>"deltach"],
                ];
        }
    }
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]];
    $keys = json_encode(['inline_keyboard'=>$keys]);
    smartSendOrEdit($message_id, "🟢 | موجودی پلن اختصاصی:", $keys);
}
if($data=="agencySettings" && $userInfo['is_agent'] == 1){
    smartSendOrEdit($message_id, $mainValues['agent_setting_message'] ,getAgentKeys());
}
if($data=="requestAgency"){
    if($userInfo['is_agent'] == 2){
        alert($mainValues['agency_request_already_sent']);
    }elseif($userInfo['is_agent'] == 0){
        $msg = str_replace(["USERNAME", "NAME", "USERID"], [$username, $first_name, $from_id], $mainValues['request_agency_message']);
        sendToAdmins($msg, json_encode(['inline_keyboard'=>[
            [
                ['text' => $buttonValues['approve'], 'callback_data' => "agencyApprove" . $from_id ],
                ['text' => $buttonValues['decline'], 'callback_data' => "agencyDecline" . $from_id]
            ]
            ]]), null);
        setUser(2, 'is_agent');
        alert($mainValues['agency_request_sent']);
    }elseif($userInfo['is_agent'] == -1) alert($mainValues['agency_request_declined']);
    elseif($userInfo['is_agent'] == 1) smartSendOrEdit($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید",getMainKeys());
}
if(preg_match('/^agencyDecline(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['declined'],'callback_data'=>"deltach"]]
        ]]));
    sendMessage($mainValues['agency_request_declined'], null,null,$match[1]);
    setUser(-1, 'is_agent', $match[1]);
}
if(preg_match('/^agencyApprove(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage($mainValues['send_agent_discount_percent'], $cancelKey);
}
if(preg_match('/^agencyApprove(\d+)_(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        editKeys(json_encode(['inline_keyboard'=>[
            [['text'=>$buttonValues['approved'],'callback_data'=>"deltach"]]
            ]]), $match[2]);
        sendMessage($mainValues['saved_successfuly']);
        setUser();
        $discount = json_encode(['normal'=>$text]);
        $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 1, `discount_percent` = ?, `agent_date` = ? WHERE `userid` = ?");
        $stmt->bind_param("sii", $discount, $time, $match[1]);
        $stmt->execute();
        $stmt->close();
        sendMessage($mainValues['agency_request_approved'], null,null,$match[1]);
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/accept(.*)/',$data, $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser();
    
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if($payInfo['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    $uid = $payInfo['user_id'];
    $fid = $payInfo['plan_id'];
    $acctxt = '';
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
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

    
    if($payInfo['type'] == "RENEW_SCONFIG"){
        $configInfo = json_decode($payInfo['description'],true);
        $uuid = $configInfo['uuid'];
        $remark = $configInfo['remark'];
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
        
    	if(is_null($response)){
    		alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
    		exit;
    	}
    	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
    	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
    	$stmt->execute();
    	$stmt->close();
        sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['renewed_config_to_user']), getMainKeys(),null,null);
        sendMessage("✅سرویس $remark با موفقیت تمدید شد",null,null,$uid);
    }else{
        $accountCount = $payInfo['agent_count'] != 0? $payInfo['agent_count']:1;
        $eachPrice = $price / $accountCount;
        
        if($acount == 0 and $inbound_id != 0){
            alert($mainValues['out_of_connection_capacity']);
            exit;
        }
        if($inbound_id == 0) {
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
            $stmt->bind_param("i", $server_id);
            $stmt->execute();
            $server_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
    
            if($server_info['ucount'] <= 0){
                alert($mainValues['out_of_server_capacity']);
                exit;
            }
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $serverInfo = $stmt->get_result()->fetch_assoc();
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
    
    
        alert($mainValues['sending_config_to_user']);
        include 'phpqrcode/qrlib.php';
        define('IMAGE_WIDTH',540);
        define('IMAGE_HEIGHT',540);
        for($i = 1; $i <= $accountCount; $i++){
            $uniqid = generateRandomString(42,$protocol); 
        
            $savedinfo = file_get_contents('settings/temp.txt');
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
                $remark = "{$srv_remark}-{$uid}-{$rnd}";
            }
        
            if($portType == "auto"){
                file_put_contents('settings/temp.txt',$port.'-'.$last_num);
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
                }
                else{
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
                sendMessage('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
                exit;
            }
        	if($response == "inbound not Found"){
                sendMessage("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
        		exit;
        	}
        	if(!$response->success){
                sendMessage('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
                sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
                exit;
            }
                
            if($serverType == "marzban"){
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = $botState['subLinkState'] == "on"?$panelUrl .$response->sub_link:"";
                $vraylink = [$subLink];
                $vray_link = json_encode($response->vray_links);
            }
            else{
                $token = RandomString(30);
                $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
        
                $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
                $vray_link = json_encode($vraylink);
            }
            foreach($vraylink as $link){
                $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز
" . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
            
                $file = RandomString() .".png";
                $ecc = 'L';
                $pixel_Size = 11;
                $frame_Size = 0;
            
                QRcode::png($link, $file, $ecc, $pixel_Size, $frame_Size);
            	addBorderImage($file);
            	
            	
	        	$backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
                $qrImage = imagecreatefrompng($file);
                
                $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
                imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
                imagepng($backgroundImage, $file);
                imagedestroy($backgroundImage);
                imagedestroy($qrImage);

            	sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML", $uid);
                unlink($file);
            }
            $agent_bought = $payInfo['agent_bought'];
    
        	$stmt = $connection->prepare("INSERT INTO `orders_list` 
        	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
        	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?);");
            $stmt->bind_param("ssiiisssisiiii", $uid, $token, $fid, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $eachPrice, $date, $rahgozar, $agent_bought);
            $stmt->execute();
            $order = $stmt->get_result();
            $stmt->close();
        }
        sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['sent_config_to_user']), getMainKeys());
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

    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"deltach"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
    if($payInfo['type'] != "RENEW_SCONFIG"){
        $filename = $file_detail['title'];
        $fileprice = number_format($file_detail['price']);
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user_detail= $stmt->get_result()->fetch_assoc();
        $stmt->close();
    
        if($user_detail['refered_by'] != null){
            $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'INVITE_BANNER_AMOUNT'");
            $stmt->execute();
            $inviteAmount = $stmt->get_result()->fetch_assoc()['value']??0;
            $stmt->close();
            $inviterId = $user_detail['refered_by'];
            
            $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` + ? WHERE `userid` = ?");
            $stmt->bind_param("ii", $inviteAmount, $inviterId);
            $stmt->execute();
            $stmt->close();
             
            sendMessage("تبریک یکی از زیر مجموعه های شما خرید انجام داد شما مبلغ " . number_format($inviteAmount) . " تومان جایزه دریافت کردید",null,null,$inviterId);
        }
    
    
        $uname = $user_detail['name'];
        $user_name = $user_detail['username'];
        
        if($admin != $from_id){
            $keys = json_encode(['inline_keyboard'=>[
                [
                    ['text'=>"به به 🛍",'callback_data'=>"deltach"]
                ],
                ]]);
                
        $msg = str_replace(['USER-ID', 'USERNAME', 'NAME', 'PRICE', 'REMARK', 'FILENAME'],
                    [$uid, $user_name, $uname, $price, $remark,$filename], $mainValues['invite_buy_new_account']);
            
            sendToAdmins($msg);
        }
    }
}
if(preg_match('/decline/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data . "_" . $message_id);
    sendMessage('دلیلت از عدم تایید چیه؟ ( بفرس براش ) 😔 ',$cancelKey);
}
if(preg_match('/decline(\d+)_(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']){
    setUser();
    $uid = $match[1];
    editKeys(
        json_encode(['inline_keyboard'=>[
	    [['text'=>"لغو شد ❌",'callback_data'=>"deltach"]]
	    ]]) ,$match[2]);

    sendMessage('پیامت رو براش ارسال کردم ... 🤝',$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
    sendMessage($text, null, null, $uid);
}
if($data=="supportSection"){
    smartSendOrEdit($message_id,"به بخش پشتیبانی خوش اومدی🛂\nلطفا، یکی از دکمه های زیر را انتخاب نمایید.",
        json_encode(['inline_keyboard'=>[
        [['text'=>"✉️ ثبت تیکت",'callback_data'=>"usersNewTicket"]],
        [['text'=>"تیکت های باز 📨",'callback_data'=>"usersOpenTickets"],['text'=>"📮 لیست تیکت ها", 'callback_data'=>"userAllTickets"]],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if($data== "usersNewTicket"){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $temp = array();
    if($ticketCategory->num_rows >0){
        while($row = $ticketCategory->fetch_assoc()){
            $ticketName = $row['value'];
            $temp[] = ['text'=>$ticketName,'callback_data'=>"supportCat$ticketName"];
            
            if(count($temp) == 2){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        
        if($temp != null){
            if(count($temp)>0){
                array_push($keys,$temp);
                $temp = null;
            }
        }
        $temp[] = ['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"];
        array_push($keys,$temp);
        smartSendOrEdit($message_id,"💠لطفا واحد مورد نظر خود را انتخاب نمایید!",json_encode(['inline_keyboard'=>$keys]));
    }else{
        alert("ای وای، ببخشید الان نیستم");
    }
}
if($data == 'dayPlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       smartSendOrEdit($message_id, 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"تعداد روز",'callback_data'=>"deltach"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    smartSendOrEdit($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if($data=='addNewDayPlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("تعداد روز و قیمت آن را بصورت زیر وارد کنید :
10-30000

مقدار اول مدت زمان (10) روز
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);exit;
}
if($userInfo['step'] == "addNewDayPlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_day` VALUES (NULL, ?, ?)");
    $stmt->bind_param("ii", $volume, $price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن زمانی جدید با موفقیت اضافه شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteDayPlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن موردنظر با موفقیت حذف شد");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       smartSendOrEdit($message_id, 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"تعداد روز",'callback_data'=>"deltach"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    smartSendOrEdit($message_id,$msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        setUser();
        $stmt = $connection->prepare("UPDATE `increase_day` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
        
        $stmt = $connection->prepare("SELECT * FROM `increase_day`");
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
    
        if($res->num_rows == 0){
           sendMessage( 'لیست پلن های زمانی خالی است ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]
                ]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"تعداد روز",'callback_data'=>"deltach"]];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            $acount =$cat['acount'];
    
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
        
        sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    
        
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeDayPlanDay(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("روز جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeDayPlanDay(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) and $text != $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("UPDATE `increase_day` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
       sendMessage( 'لیست پلن های زمانی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]],
                    [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
                ]
            ]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"تعداد روز",'callback_data'=>"deltach"]];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        $acount =$cat['acount'];

        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteDayPlan" . $id],['text'=>$price,'callback_data'=>"changeDayPlanPrice" . $id],['text'=>$title,'callback_data'=>"changeDayPlanDay" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن زمانی جدید", 'callback_data' =>"addNewDayPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن زمانی روی آن بزنید👇';
    
    sendMessage($msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));

    
}
if($data == 'volumePlanSettings' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       smartSendOrEdit($message_id, 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"backplan"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"مقدار حجم",'callback_data'=>"deltach"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "backplan"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = smartSendOrEdit($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit;
}
if($data=='addNewVolumePlan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم و قیمت آن را بصورت زیر وارد کنید :
10-30000

مقدار اول حجم (10) گیگابایت
مقدار دوم قیمت (30000) تومان
 ",$cancelKey);
 exit;
}
if($userInfo['step'] == "addNewVolumePlan" and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $input = explode('-',$text); 
    $volume = $input[0];
    $price = $input[1];
    $stmt = $connection->prepare("INSERT INTO `increase_plan` VALUES (NULL, ? ,?)");
    $stmt->bind_param("ii",$volume,$price);
    $stmt->execute();
    $stmt->close();
    
    sendMessage("پلن حجمی جدید با موفقیت اضافه شد",$removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    setUser();
}
if(preg_match('/^deleteVolumePlan(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن موردنظر با موفقیت حذف شد");
    
    
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       smartSendOrEdit($message_id, 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"مقدار حجم",'callback_data'=>"deltach"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = smartSendOrEdit($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("قیمت جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanPrice(\d+)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] and ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `increase_plan` SET `price` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $pid);
        $stmt->execute();
        $stmt->close();
        sendMessage("عملیات با موفقیت انجام شد",$removeKeyboard);
        
        setUser();
        $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
        $stmt->execute();
        $plans = $stmt->get_result();
        $stmt->close();
        
        if($plans->num_rows == 0){
           sendMessage( 'لیست پلن های حجمی خالی است ',json_encode([
                    'inline_keyboard' => [
                        [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                        [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                        ]]));
            exit;
        }
        $keyboard = [];
        $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"مقدار حجم",'callback_data'=>"deltach"]];
        while ($cat = $plans->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['volume'];
            $price=number_format($cat['price']) . " تومان";
            
            $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
        }
        $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
        $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
        $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
        
        $res = sendMessage($msg,json_encode([
                'inline_keyboard' => $keyboard
            ]));
    }else{
        sendMessage("یک مقدار عددی و صحیح وارد کنید");
    }
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser($data);
    delMessage();
    sendMessage("حجم جدید را وارد کنید:", $cancelKey);
    exit;
}
if(preg_match('/^changeVolumePlanVolume(\d+)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $pid=$match[1];
    $stmt = $connection->prepare("UPDATE `increase_plan` SET `volume` = ? WHERE `id` = ?");
    $stmt->bind_param("ii", $text, $pid);
    $stmt->execute();
    $stmt->close();
    sendMessage("✅عملیات با موفقیت انجام شد",$removeKeyboard);
    setUser();

    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    
    if($plans->num_rows == 0){
       sendMessage( 'لیست پلن های حجمی خالی است ',json_encode([
                'inline_keyboard' => [
                    [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]],
                    [['text' => $buttonValues['back_button'],'callback_data'=>"managePanel"]]
                    ]]));
        exit;
    }
    $keyboard = [];
    $keyboard[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"قیمت",'callback_data'=>"deltach"],['text'=>"مقدار حجم",'callback_data'=>"deltach"]];
    while ($cat = $plans->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price=number_format($cat['price']) . " تومان";
        
        $keyboard[] = [['text'=>"❌",'callback_data'=>"deleteVolumePlan" . $id],['text'=>$price,'callback_data'=>"changeVolumePlanPrice" . $id],['text'=>$title,'callback_data'=>"changeVolumePlanVolume" . $id]];
    }
    $keyboard[] = [['text' => "افزودن پلن حجمی جدید", 'callback_data' =>"addNewVolumePlan"]];
    $keyboard[] = [['text' =>$buttonValues['back_button'], 'callback_data' => "managePanel"]];
    $msg = ' 📍 برای دیدن جزییات پلن حجمی روی آن بزنید👇';
    
    $res = sendMessage( $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    
}
if(preg_match('/^supportCat(.*)/',$data,$match)){
    delMessage();
    sendMessage($mainValues['enter_ticket_title'], $cancelKey);
    setUser("newTicket_" . $match[1]);
}
if(preg_match('/^newTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    setUser($text, 'temp');
	setUser("sendTicket_" . $match[1]);
    sendMessage($mainValues['enter_ticket_description']);
}
if(preg_match('/^sendTicket_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    if(isset($text) || isset($update->message->photo)){
        $ticketCat = $match[1];
        
        $ticketTitle = $userInfo['temp'];
        $time = time();
    
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $stmt = $connection->prepare("INSERT INTO `chats` (`user_id`,`create_date`, `title`,`category`,`state`,`rate`) VALUES 
                            (?,?,?,?,'0','0')");
        $stmt->bind_param("iiss", $from_id, $time, $ticketTitle, $ticketCat);
        $stmt->execute();
        $inserId = $stmt->get_result();
        $chatRowId = $stmt->insert_id;
        $stmt->close();
        
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$chatRowId}"]]
            ]]);
        if(isset($text)){
            $txt = "تیکت جدید:\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: @$username\nآیدی عددی: $from_id\n\nموضوع تیکت: $ticketCat\n\nعنوان تیکت: " .$ticketTitle . "\nمتن تیکت: $text";
            $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendToAdmins($txt, $keys, "html");
        }else{
            $txt = "تیکت جدید:\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: @$username\nآیدی عددی: $from_id\n\nموضوع تیکت: $ticketCat\n\nعنوان تیکت: " .$ticketTitle . "\nمتن تیکت: $caption";
            $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                        (?,?,'USER',?)");
            $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
            $stmt->bind_param("iis", $chatRowId, $time, $text);
            sendPhoto($fileid, $txt,$keys, "HTML", $admin);
        }
        $stmt->execute();
        $stmt->close();
        
        sendMessage("پیام شما با موفقیت ثبت شد",$removeKeyboard,"HTML");
        sendMessage("لطفا یکی از کلید های زیر را انتخاب کنید",getMainKeys());
            
        setUser(NULL,'temp');
    	setUser("none");
    }else{
        sendMessage("پیام مورد نظر پشتیبانی نمی شود");
    }
    
}
if($data== "usersOpenTickets" || $data == "userAllTickets"){
    if($data== "usersOpenTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 AND `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = 2;
    }elseif($data == "userAllTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `user_id` = ? ORDER BY `state` ASC, `create_date` DESC");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $ticketList = $stmt->get_result();
        $stmt->close();
        $type = "all";
    }
	$allList = $ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	setUser("none");


	if($allList>0){
        while($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i", $rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="ADMIN"?"ادمین":"کاربر";
            if($state !=2){
                $keys = [
                        [['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ به تیکت 📝",'callback_data'=>"replySupport_{$rowId}"]],
                        [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [
                    [['text'=>"آخرین پیام ها 📩",'callback_data'=>"latestMsg_$rowId"]]
                    ];
            }
                
            if(isset(json_decode($lastmsg,true)['file_id'])){
                $info = json_decode($lastmsg,true);
                $fileid = $info['file_id'];
                $caption = $info['caption'];
                $txt ="🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType : $caption";
                sendPhoto($fileid, $txt,json_encode(['inline_keyboard'=>$keys]), "HTML");
            }else{
                sendMessage(" 🔘 موضوع: $title
            		💭 دسته بندی:  {$category}
            		\n
            		$sentType : $lastmsg",json_encode(['inline_keyboard'=>$keys]),"HTML");
            }

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    sendmessage("موارد بیشتر",json_encode(['inline_keyboard'=>[
                		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
                		        ]]),"HTML");
		}
	}else{
	    alert("تیکتی یافت نشد");
        exit();
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  $from_id != $admin){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $from_id = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    editKeys();

    $ticketClosed = " $title : $category \n\n" . "این تیکت بسته شد\n به این تیکت رأی بدهید";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"بسیار بد 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"بد 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"خوب 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"بسیار خوب 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"عالی 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html');
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"$from_id",'callback_data'=>"deltach"],
            ['text'=>"آیدی کاربر",'callback_data'=>'deltach']
        ],
        [
            ['text'=>$first_name??" ",'callback_data'=>"deltach"],
            ['text'=>"اسم کاربر",'callback_data'=>'deltach']
        ],
        [
            ['text'=>"$title",'callback_data'=>'deltach'],
            ['text'=>"عنوان",'callback_data'=>'deltach']
        ],
        [
            ['text'=>"$category",'callback_data'=>'deltach'],
            ['text'=>"دسته بندی",'callback_data'=>'deltach']
        ],
        ]]);
    sendToAdmins("☑️| تیکت توسط کاربر بسته شد", $keys, "HTML");

}
if(preg_match('/^replySupport_(.*)/',$data,$match)){
    delMessage();
    sendMessage("💠لطفا متن پیام خود را بصورت ساده و مختصر ارسال کنید!",$cancelKey);
	setUser("sendMsg_" . $match[1]);
}
if(preg_match('/^sendMsg_(.*)/',$userInfo['step'],$match)  and $text!=$buttonValues['cancel']){
    $ticketRowId = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $ticketRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];



    $time = time();
    if(isset($text)){
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nآیدی عددی: $from_id\n" . "\nمتن پیام: $text";
    
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $stmt->bind_param("iis",$ticketRowId, $time, $text);
        sendToAdmins($txt, json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ", 'callback_data'=>"reply_{$ticketRowId}"]]
            ]]),"HTML");
    }else{
        $txt = "پیام جدید:\n[$ticketTitle] <i>{$ticketCat}</i>\n\nکاربر: <a href='tg://user?id=$from_id'>$first_name</a>\nنام کاربری: $username\nآیدی عددی: $from_id\n" . "\nمتن پیام: $caption";
        
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'USER',?)");
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt->bind_param("iis", $ticketRowId, $time, $text);
        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"پاسخ",'callback_data'=>"reply_{$ticketRowId}"]]
            ]]);
        sendPhoto($fileid, $txt,$keys, "HTML", $admin);
    }
    $stmt->execute();
    $stmt->close();
                
    sendMessage("پیام شما با موفقیت ثبت شد",getMainKeys(),"HTML");
	setUser("none");
}
if(preg_match("/^rate_+([0-9])+_+([0-9])/",$data,$match)){
    $rowChatId = $match[1];
    $rate = $match[2];
    
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i",$rowChatId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
    
    
    $stmt = $connection->prepare("UPDATE `chats` SET `rate` = $rate WHERE `id` = ?");
    $stmt->bind_param("i", $rowChatId);
    $stmt->execute();
    $stmt->close();
    smartSendOrEdit($message_id,"✅");
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"رای تیکت",'callback_data'=>"deltach"]
            ],
        ]]);

    sendToAdmins("
📨|رأی به تیکت 

👤 آیدی عددی: $from_id
❕نام کاربر: $first_name
❗️نام کاربری: $username
〽️ عنوان: $title
⚜️ دسته بندی: $category
❤️ رای: $rate
 ⁮⁮
    ", $keys, "HTML");
}
if($data=="ticketsList" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $ticketSection = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"تیکت های باز",'callback_data'=>"openTickets"],
            ['text'=>"تیکت های جدید",'callback_data'=>"newTickets"]
            ],
        [
            ['text'=>"همه ی تیکت ها",'callback_data'=>"allTickets"],
            ['text'=>"دسته بندی تیکت ها",'callback_data'=>"ticketsCategory"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]]
        ]]);
    smartSendOrEdit($message_id, "به بخش تیکت ها خوش اومدید، 
    
🚪 /start
    ",$ticketSection);
}
if($data=='ticketsCategory' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"دسته بندی",'callback_data'=>"deltach"]];
    
    if($ticketCategory->num_rows>0){
        while($row = $ticketCategory->fetch_assoc()){
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"deltach"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"deltach"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    smartSendOrEdit($message_id,"دسته بندی تیکت ها",$keys);
}
if($data=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser('addTicketCategory');
    smartSendOrEdit($message_id,"لطفا اسم دسته بندی را وارد کنید");
}
if ($userInfo['step']=="addTicketCategory" and ($from_id == $admin || $userInfo['isAdmin'] == true)){
	$stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES ('TICKETS_CATEGORY', ?)");	
	$stmt->bind_param("s", $text);
	$stmt->execute();
	$stmt->close();
    setUser();
    sendMessage($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"دسته بندی",'callback_data'=>"deltach"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"deltach"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"deltach"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    sendMessage("دسته بندی تیکت ها",$keys);
}
if(preg_match("/^delTicketCat_(\d+)/",$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("با موفقیت حذف شد");
        

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'TICKETS_CATEGORY'");
    $stmt->execute();
    $ticketCategory = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"دسته بندی",'callback_data'=>"deltach"]];
    
    if($ticketCategory->num_rows>0){
        while ($row = $ticketCategory->fetch_assoc()){
            
            $rowId = $row['id'];
            $ticketName = $row['value'];
            $keys[] = [['text'=>"❌",'callback_data'=>"delTicketCat_$rowId"],['text'=>$ticketName,'callback_data'=>"deltach"]];
        }
    }else{
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"deltach"]];
    }
    $keys[] = [['text'=>"افزودن دسته بندی",'callback_data'=>"addTicketCategory"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"ticketsList"]];
    
    $keys =  json_encode(['inline_keyboard'=>$keys]);
    smartSendOrEdit($message_id, "دسته بندی تیکت ها",$keys);
}
if(($data=="openTickets" or $data=="newTickets" or $data == "allTickets")  and  $from_id ==$admin){
    if($data=="openTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
        $type = 2;
    }elseif($data=="newTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
        $type = 0;
    }elseif($data=="allTickets"){
        $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
        $type = "all";
    }
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();
	$allList =$ticketList->num_rows;
	$cont = 5;
	$current = 0;
	$keys = array();
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
		    $current++;
		    
            $rowId = $row['id'];
            $admin = $row['user_id'];
            $title = $row['title'];
            $category = $row['category'];
	        $state = $row['state'];
	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";

            $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
            $stmt->bind_param("i",$rowId);
            $stmt->execute();
            $ticketInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $lastmsg = $ticketInfo['text'];
            $sentType = $ticketInfo['msg_type']=="USER"?"کاربر":"ادمین";
            
            if($state !=2){
                $keys = [
                        [['text'=>"بستن تیکت",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ",'callback_data'=>"reply_{$rowId}"]],
                        [['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]
                        ];
            }
            else{
                $keys = [[['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]];
                $rate = "\nرأی: ". $row['rate'];
            }
            
            sendMessage("آیدی کاربر: $admin\nنام کاربر: $username\nدسته بندی: $category $rate\n\nموضوع: $title\nآخرین پیام:\n[$sentType] $lastmsg",
                json_encode(['inline_keyboard'=>$keys]),"html");

			if($current>=$cont){
			    break;
			}
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("موارد بیشتر",$keys,"html");
		}
	}else{
        alert("تیکتی یافت نشد");
	}
}
if(preg_match('/^moreTicket_(.+)_(.+)/',$data, $match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,$mainValues['please_wait_message']);
    $type = $match[1];
    $offset = $match[2];
    if($type=="2") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` != 2 ORDER BY `state` ASC, `create_date` DESC");
    elseif($type=="0") $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `state` = 0 ORDER BY `create_date` DESC");
    elseif($type=="all") $stmt = $connection->prepare("SELECT * FROM `chats` ORDER BY `state` ASC, `create_date` DESC");
    
    $stmt->execute();
    $ticketList = $stmt->get_result();
    $stmt->close();

	$allList =$ticketList->num_rows;
	$cont = 5 + $offset;
	$current = 0;
	$keys = array();
	$rowCont = 0;
	if($allList>0){
        while ($row = $ticketList->fetch_assoc()){
            $rowCont++;
            if($rowCont>$offset){
    		    $current++;
    		    
                $rowId = $row['id'];
                $admin = $row['user_id'];
                $title = $row['title'];
                $category = $row['category'];
    	        $state = $row['state'];
    	        $username = bot('getChat',['chat_id'=>$admin])->result->first_name ?? " ";
    
                $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC");
                $stmt->bind_param("i",$rowId);
                $stmt->execute();
                $ticketInfo = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $lastmsg = $ticketInfo['text'];
                $sentType = $ticketInfo['msg_type']=="USER"?"کاربر":"ادمین";
                
                if($state !=2){
                    $keys = [
                            [['text'=>"بستن تیکت",'callback_data'=>"closeTicket_$rowId"],['text'=>"پاسخ",'callback_data'=>"reply_{$rowId}"]],
                            [['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]
                            ];
                }
                else{
                    $keys = [[['text'=>"آخرین پیام ها",'callback_data'=>"latestMsg_$rowId"]]];
                    $rate = "\nرأی: ". $row['rate'];
                }
                
                sendMessage("آیدی کاربر: $admin\nنام کاربر: $username\nدسته بندی: $category $rate\n\nموضوع: $title\nآخرین پیام:\n[$sentType] $lastmsg",
                    json_encode(['inline_keyboard'=>$keys]),"html");


    			if($current>=$cont){
    			    break;
    			}
            }
        }
        
		if($allList > $cont){
		    $keys = json_encode(['inline_keyboard'=>[
		        [['text'=>"دریافت",'callback_data'=>"moreTicket_{$type}_{$cont}"]]
		        ]]);
            sendMessage("موارد بیشتر",$keys);
		}
	}else{
        alert("تیکتی یافت نشد");
	}
}
if(preg_match('/^closeTicket_(\d+)/',$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $title = $ticketInfo['title'];
    $category = $ticketInfo['category'];
        

    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 2 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    $ticketClosed = "[$title] <i>$category</i> \n\n" . "این تیکت بسته شد\n به این تیکت رأی بدهید";;
    
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"بسیار بد 😠",'callback_data'=>"rate_{$chatRowId}_1"]],
        [['text'=>"بد 🙁",'callback_data'=>"rate_{$chatRowId}_2"]],
        [['text'=>"خوب 😐",'callback_data'=>"rate_{$chatRowId}_3"]],
        [['text'=>"بسیار خوب 😃",'callback_data'=>"rate_{$chatRowId}_4"]],
        [['text'=>"عالی 🤩",'callback_data'=>"rate_{$chatRowId}_5"]]
        ]]);
    sendMessage($ticketClosed,$keys,'html', $userId);
    editKeys(json_encode(['inline_keyboard'=>[
        [['text'=>"تیکت بسته شد",'callback_data'=>"deltach"]]
        ]]));

}
if(preg_match('/^latestMsg_(.*)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `chat_id` = ? ORDER BY `sent_date` DESC LIMIT 10");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $rowId = $row['id'];
        $type = $row['msg_type'] == "USER" ?"کاربر":"ادمین";
        $text = $row['text'];
        if(isset(json_decode($text,true)['file_id'])) $text = "تصویر /dlPic" . $rowId; 

        $output .= "<i>[$type]</i>\n$text\n\n";
    }
    sendMessage($output, null, "html");
}
if(preg_match('/^\/dlPic(\d+)/',$text,$match)){
     $stmt = $connection->prepare("SELECT * FROM `chats_info` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $chatList = $stmt->get_result();
    $stmt->close();
    $output = "";
    while($row = $chatList->fetch_assoc()){
        $text = json_decode($row['text'],true);
        $fileid = $text['file_id'];
        $caption = $text['caption'];
        $chatInfoId = $row['chat_id'];
        $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
        $stmt->bind_param("i", $chatInfoId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $userid = $info['user_id'];
        
        if($userid == $from_id || $from_id == $admin || $userInfo['isAdmin'] == true) sendPhoto($fileid, $caption);
    }
}
if($data == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("😡 | کی باز شلوغی کرده آیدی عددی شو بفرس تا برم ...... آرهههه:", $cancelKey);
    setUser($data);
}
if($data=="unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("آیدی عددیشو بفرست تا آزادش کنم", $cancelKey);
    setUser($data);
}
if($userInfo['step'] == "banUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();
        
        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] != "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'banned' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();
                
                sendMessage("❌ | خب خب برید کنار که مسدودش کردم 😎😂",$removeKeyboard);
            }else{
                sendMessage("☑️ | این کاربر که از قبل مسدود بود چیکارش داری بدبخت و 😂🤣",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if($data=="mainMenuButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}

if($data=="renameButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"✏️ تغییر اسم دکمه‌ها",getRenameButtonsKeys(0));
}
if(preg_match('/^renameButtonsPage(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"✏️ تغییر اسم دکمه‌ها",getRenameButtonsKeys($match[1]));
}
if(preg_match('/^renameBtnKey_(.+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("اسم جدید را ارسال کنید",$cancelKey);
    setUser("renameBtnKey_" . $match[1]);
}
if(preg_match('/^renameBtnKey_(.+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($update->message->text)){
        sendMessage("لطفا فقط متن ارسال کنید");
        exit();
    }
    $key = $match[1];
    if($key == "start_message"){
        $type = "MAINVALUE_start_message";
    }else{
        $type = "BUTTON_LABEL_" . $key;
    }
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = ?");
    $stmt->bind_param("s",$type);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows;
    $stmt->close();
    if($exists>0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value`=? WHERE `type`=?");
        $stmt->bind_param("ss",$text,$type);
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
        $stmt->bind_param("ss",$type,$text);
    }
    $stmt->execute();
    $stmt->close();
    setUser();
    sendMessage("✅ ذخیره شد", $removeKeyboard);
    sendMessage("مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if($data=="arrangeButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌ها",getArrangeButtonsMenuKeys());
}
if($data=="arrangeCustomButtons" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌های سفارشی",getArrangeMainButtonsKeys());
}


if($data=="arrangeMainOrderText" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    // Easy reorder: admin sends comma-separated numbers, e.g. 3,1,2
    setUser('awaiting_main_buttons_order','step');

    // Build current visible main menu buttons (built-in + custom)
    $kb = json_decode(getMainKeys(), true);
    $rows = $kb['inline_keyboard'] ?? [];
    $ordered = [];
    foreach($rows as $r){
        if(!is_array($r)) continue;
        foreach($r as $b){
            $cb = $b['callback_data'] ?? '';
            $tx = $b['text'] ?? '';
            if($cb === '' || $cb === 'deltach') continue;
            // keep managePanel fixed at the end (not sortable)
            if($cb === 'managePanel') continue;
            // avoid blanks
            if(trim((string)$tx) === '') continue;
            $ordered[] = ['cb'=>$cb,'title'=>$tx];
        }
    }

    if(count($ordered)==0){
        smartSendOrEdit($message_id, "❌ دکمه‌ای برای چینش پیدا نشد.", ['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'arrangeButtons']]]]);
        exit;
    }

    $lines = [];
    for($i=0;$i<count($ordered);$i++){
        $n=$i+1;
        $lines[] = "{$n}) " . $ordered[$i]['title'];
    }
    $msg = "✏️ ترتیب جدید *همه دکمه‌های منوی اصلی* را با ارسال شماره‌ها مشخص کنید.

".
           implode("
",$lines)."

".
           "مثال: 3,1,2
".
           "نکته: باید دقیقاً ".count($ordered)." شماره بفرستید.";
    smartSendOrEdit($message_id, $msg, ['inline_keyboard'=>[[['text'=>$buttonValues['back_button'],'callback_data'=>'arrangeButtons']]]]);
    exit;
}

if($data=="cycleMainCols" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $cols = (int)getSettingValue("MAIN_MENU_COLUMNS","2");
    $cols++;
    if($cols > 3) $cols = 1;
    upsertSettingValue("MAIN_MENU_COLUMNS",(string)$cols);
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌ها",getArrangeButtonsMenuKeys());
}
if($data=="toggleSwapBuy" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $cur = getSettingValue("MAIN_MENU_SWAP_BUY","0");
    $new = ($cur === "1") ? "0" : "1";
    upsertSettingValue("MAIN_MENU_SWAP_BUY",$new);
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌ها",getArrangeButtonsMenuKeys());
}
if($data=="toggleSwapServices" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $cur = getSettingValue("MAIN_MENU_SWAP_SERVICES","0");
    $new = ($cur === "1") ? "0" : "1";
    upsertSettingValue("MAIN_MENU_SWAP_SERVICES",$new);
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌ها",getArrangeButtonsMenuKeys());
}
if(preg_match('/^moveMainBtn_(up|down)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    moveMainButtonOrder($match[2], $match[1]);
    smartSendOrEdit($message_id,"↕️ چینش دکمه‌ها",getArrangeMainButtonsKeys());
}

if(preg_match('/^delMainButton(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("با موفقیت حذف شد");
    smartSendOrEdit($message_id,"مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if($data == "addNewMainButton" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا اسم دکمه را وارد کنید",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addNewMainButton" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage("لطفا فقط متن بفرستید");
        exit();
    }
    sendMessage("لطفا پاسخ دکمه را وارد کنید");
    setUser("setMainButtonAnswer" . $text);
}
if(preg_match('/^setMainButtonAnswer(.*)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(!isset($update->message->text)){
        sendMessage("لطفا فقط متن بفرستید");
        exit();
    }
    setUser();
    
    $stmt = $connection->prepare("INSERT INTO `setting` (`type`, `value`) VALUES (?, ?)");
    $btn = "MAIN_BUTTONS" . $match[1];
    $stmt->bind_param("ss", $btn, $text); 
    $stmt->execute();
    $stmt->close();
    
    sendMessage("مدیریت دکمه های صفحه اصلی",getMainMenuButtonsKeys());
}
if($userInfo['step'] == "unbanUser" && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $usersList = $stmt->get_result();
        $stmt->close();

        if($usersList->num_rows >0){
            $userState = $usersList->fetch_assoc();
            if($userState['step'] == "banned"){
                $stmt = $connection->prepare("UPDATE `users` SET `step` = 'none' WHERE `userid` = ?");
                $stmt->bind_param("i", $text);
                $stmt->execute();
                $stmt->close();

                sendMessage("✅ | آزاد شدم خوشحالم ننه ، ایشالا آزادی همه 😂",$removeKeyboard);
            }else{
                sendMessage("☑️ | این کاربری که فرستادی از قبل آزاد بود 🙁",$removeKeyboard);
            }
        }else sendMessage("کاربری با این آیدی یافت نشد");
        setUser();
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
    }else{
        sendMessage($mainValues['send_only_number']);
    }
}
if(preg_match("/^reply_(.*)/",$data,$match) and  ($from_id == $admin || $userInfo['isAdmin'] == true)){
    setUser("answer_" . $match[1]);
    sendMessage("لطفا پیام خود را ارسال کنید",$cancelKey);
}
if(preg_match('/^answer_(.*)/',$userInfo['step'],$match) and  $from_id ==$admin  and $text!=$buttonValues['cancel']){
    $chatRowId = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `chats` WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $ticketInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $ticketInfo['user_id'];
    $ticketTitle = $ticketInfo['title'];
    $ticketCat = $ticketInfo['category'];
    
    $time = time();

    
    if(isset($text)){
        $ticketTitle = str_replace(["/","'","#"],['\/',"\'","\#"],$ticketTitle);
        $text = str_replace(["/","'","#"],['\/',"\'","\#"],$text);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        sendMessage("\[$ticketTitle] _{$ticketCat}_\n\n" . $text,json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]),"Markdown", $userId);        
    }else{
        $text = json_encode(['file_id'=>$fileid, 'caption'=>$caption]);
        $stmt = $connection->prepare("INSERT INTO `chats_info` (`chat_id`,`sent_date`,`msg_type`,`text`) VALUES
                    (?,?,'ADMIN',?)");
        $stmt->bind_param("iis", $chatRowId, $time, $text);
        
        $keyboard = json_encode(['inline_keyboard'=>[
            [
                ['text'=>'پاسخ به تیکت 📝','callback_data'=>"replySupport_$chatRowId"],
                ['text'=>"بستن تیکت 🗳",'callback_data'=>"closeTicket_$chatRowId"]
                ]
            ]]);
            
        sendPhoto($fileid, "\[$ticketTitle] _{$ticketCat}_\n\n" . $caption,$keyboard, "Markdown", $userId);
    }
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("UPDATE `chats` SET `state` = 1 WHERE `id` = ?");
    $stmt->bind_param("i", $chatRowId);
    $stmt->execute();
    $stmt->close();
    
    setUser();
    sendMessage("پیام شما با موفقیت ارسال شد ✅",$removeKeyboard);
}
if(preg_match('/freeTrial(\d+)_(?<buyType>\w+)/',$data,$match)) {
    $id = $match[1];
 
    if($userInfo['freetrial'] == 'used' and !($from_id == $admin) && json_decode($userInfo['discount_percent'],true)['normal'] != "100"){
        alert('⚠️شما قبلا هدیه رایگان خود را دریافت کردید');
        exit;
    }
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $days = $file_detail['days'];
    $date = time();
    $expire_microdate = floor(microtime(true) * 1000) + (864000 * $days * 100);
    $expire_date = $date + (86400 * $days);
    $type = $file_detail['type'];
    $volume = $file_detail['volume'];
    $protocol = $file_detail['protocol'];
    $price = $file_detail['price'];
    $server_id = $file_detail['server_id'];
    $acount = $file_detail['acount'];
    $inbound_id = $file_detail['inbound_id'];
    $limitip = $file_detail['limitip'];
    $netType = $file_detail['type'];
    $rahgozar = $file_detail['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $agentBought = false;
    if($match['buyType'] == "one" || $match['buyType'] == "much"){
        $agentBought = true;
        
        
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$id]?? $discounts['normal'];
        else $discount = $discounts['servers'][$server_id]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
    }
    
    if($acount == 0 and $inbound_id != 0){
        alert($mainValues['out_of_connection_capacity']);
        exit;
    }
    if($inbound_id == 0) {
        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if($server_info['ucount'] <= 0){
            alert($mainValues['out_of_server_capacity']);
            exit;
        }
    }
    
    $uniqid = generateRandomString(42,$protocol); 

    $savedinfo = file_get_contents('settings/temp.txt');
    $savedinfo = explode('-',$savedinfo);
    $port = $savedinfo[0] + 1;
    $last_num = $savedinfo[1] + 1;

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
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

    if($from_id == $admin && !empty($userInfo['temp'])){
        $remark = $userInfo['temp'];
        setUser('','temp');
    }else{
        if($botState['remark'] == "digits"){
            $rnd = rand(10000,99999);
            $remark = "{$srv_remark}-{$rnd}";
        }else{
            $rnd = rand(1111,99999);
            $remark = "{$srv_remark}-{$from_id}-{$rnd}";
        }
    }
    
    if($portType == "auto"){
        file_put_contents('settings/temp.txt',$port.'-'.$last_num);
    }else{
        $port = rand(1111,65000);
    }
    if($inbound_id == 0){    
        if($serverType == "marzban"){
            $response = addMarzbanUser($server_id, $remark, $volume, $days, $id);
            if(!$response->success){
                if($response->msg == "User already exists"){
                    $remark .= rand(1111,99999);
                    $response = addMarzbanUser($server_id, $remark, $volume, $days, $id);
                }
            }
        }else{
            $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id); 
            if(!$response->success){
                if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();
                elseif(strstr($response->msg, "Port already exists")) $port = rand(1111,65000);

                $response = addUser($server_id, $uniqid, $protocol, $port, $expire_microdate, $remark, $volume, $netType, 'none', $rahgozar, $id);
            }
        }
    }else {
        $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id); 
        if(!$response->success){
            if(strstr($response->msg, "Duplicate email")) $remark .= RandomString();

            $response = addInboundAccount($server_id, $uniqid, $inbound_id, $expire_microdate, $remark, $volume, $limitip, null, $id);
        }
    }
    if(is_null($response)){
        alert('❌ | 🥺 گلم ، اتصال به سرور برقرار نیست لطفا مدیر رو در جریان بزار ...');
        exit;
    }
	if($response == "inbound not Found"){
        alert("❌ | 🥺 سطر (inbound) با آیدی $inbound_id تو این سرور وجود نداره ، مدیر رو در جریان بزار ...");
		exit;
	}
	if(!$response->success){
        alert('❌ | 😮 وای خطا داد لطفا سریع به مدیر بگو ...');
        sendToAdmins("خطای سرور {$serverInfo['title']}:\n\n" . ($response->msg), null, null);
        exit;
    }
    alert($mainValues['sending_config_to_user']);
	include 'phpqrcode/qrlib.php';
	
    if($serverType == "marzban"){
        $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
        $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
        $vraylink = [$subLink];
        $vray_link = json_encode($response->vray_links);
    }else{
        $token = RandomString(30);
        $subLink = $botState['subLinkState']=="on"?$botUrl . "settings/subLink.php?token=" . $token:"";
        $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id, $rahgozar, $customPath, $customPort, $customSni);
        $vray_link = json_encode($vraylink);
    }
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $link){
        $acc_text = "
😍 سفارش جدید شما
📡 پروتکل: $protocol
🔮 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت سرویس: $days روز
" . ($botState['configLinkState'] != "off" && $serverType != "marzban"?"
💝 config : <code>$link</code>":"");
if($botState['subLinkState'] == "on") $acc_text .= "

🔋 Volume web: <code> $botUrl"."search.php?id=".$uniqid."</code>

\n🌐 subscription : <code>$subLink</code>";
    
        $file = RandomString().".png";
        $ecc = 'L'; 
        $pixel_Size = 11;
        $frame_Size = 0;
        QRcode::png($link, $file, $ecc, $pixel_Size, $frame_size);
    	addBorderImage($file);
    	
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        sendPhoto($botUrl . $file, $acc_text,json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]]]),"HTML");
        unlink($file);
    }
	$stmt = $connection->prepare("INSERT INTO `orders_list` 
	    (`userid`, `token`, `transid`, `fileid`, `server_id`, `inbound_id`, `remark`, `uuid`, `protocol`, `expire_date`, `link`, `amount`, `status`, `date`, `notif`, `rahgozar`, `agent_bought`)
	    VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?,1, ?, 0, ?, ?)");
	$stmt->bind_param("isiiisssisiiii", $from_id, $token, $id, $server_id, $inbound_id, $remark, $uniqid, $protocol, $expire_date, $vray_link, $price, $date, $rahgozar, $agentBought);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    
    if($inbound_id == 0) {
        $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount` = `acount` - 1 WHERE `id`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }

    setUser('used','freetrial');    
}
if(preg_match('/^showMainButtonAns(\d+)/',$data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    smartSendOrEdit($message_id,$info['value'],json_encode(['inline_keyboard'=>[
        [['text'=>$buttonValues['back_button'],'callback_data'=>"mainMenu"]]
        ]]));
}
if(preg_match('/^marzbanHostSettings(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $match[1]);
    $stmt->execute();
    $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
    $stmt->close();
    
    $hosts = getMarzbanHosts($serverId)->inbounds;
    $networkType = array();
    foreach($hosts as $key => $inbound){
        $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"selectHost{$match[1]}*_*{$inbound->protocol}*_*{$inbound->tag}"]];
    }
    $networkType[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match[1]]];
    $networkType = json_encode(['inline_keyboard'=>$networkType]);
    smartSendOrEdit($message_id, "لطفا نوع شبکه های این پلن را انتخاب کنید",$networkType);
}
if(preg_match('/^selectHost(?<planId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $saveBtn = "ذخیره ✅";
    unset($markup[count($markup)-1]);
    if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);
    foreach($markup as $key => $keyboard){
        if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
    }
        
    if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
        $markup[] = [['text'=>$saveBtn,'callback_data'=>"saveServerHost" . $match['planId']]];
    }
    $markup[] = [['text'=>$buttonValues['cancel'], 'callback_data'=>"planDetails" . $match['planId']]];
    $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
    editKeys($markup);
}
if(preg_match('/^saveServerHost(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $inbounds = array();
    $proxies = array();
    unset($markup[count($markup)-1]);
    unset($markup[count($markup)-1]);
    
    foreach($markup as $key=>$value){
        $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
        if($state > 0){
            preg_match('/^selectHost(?<serverId>\d+)\*_\*(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
            $inbounds[$info['protocol']][] = $tag;
            $proxies[$info['protocol']] = array();

            if($info['protocol'] == "vless"){
                $proxies["vless"] = ["flow" => ""];
            }
            elseif($info['protocol'] == "shadowsocks"){
                $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
            }
        }
    }
    $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=? WHERE `id`=?");
    $stmt->bind_param("si", $info, $match[1]);
    $stmt->execute();
    $stmt->close();
    
    smartSendOrEdit($message_id, "با موفقیت ذخیره شد",getPlanDetailsKeys($match[1]));
    setUser();
}
if($data=="rejectedAgentList" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getRejectedAgentList();
    if($keys != null){
        smartSendOrEdit($message_id,"لیست کاربران رد شده از نمایندگی",$keys);
    }else alert("کاربری یافت نشد");
}
if(preg_match('/^releaseRejectedAgent(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = 0 WHERE `userid` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert($mainValues['saved_successfuly']);
    $keys = getRejectedAgentList();
    if($keys != null){
        smartSendOrEdit($message_id,"لیست کاربران رد شده از نمایندگی",$keys);
    }else smartSendOrEdit($message_id,"کاربری یافت نشد",json_encode(['inline_keyboard'=>[[['text'=>$buttonValues['back_to_main'],'callback_data'=>"managePanel"]]]]));
}
if($data=="showUUIDLeft" && ($botState['searchState']=="on" || $from_id== $admin)){
    delMessage();
    sendMessage($mainValues['send_config_uuid'],$cancelKey);
    setUser('showAccount');
}
if($userInfo['step'] == "showAccount" and $text != $buttonValues['cancel']){
    if(preg_match('/^vmess:\/\/(.*)/',$text,$match)){
        $jsonDecode = json_decode(base64_decode($match[1]),true);
        $text = $jsonDecode['id'];
        $marzbanText = $match[1];
    }elseif(preg_match('/^vless:\/\/(.*?)\@/',$text,$match)){
        $marzbanText = $text = $match[1];
    }elseif(preg_match('/^trojan:\/\/(.*?)\@/',$text,$match)){
        $marzbanText = $text = $match[1];
    }elseif(!preg_match('/[a-f0-9]{8}\-[a-f0-9]{4}\-4[a-f0-9]{3}\-(8|9|a|b)[a-f0-9]{3}\-[a-f0-9]{12}/', $text)){
        sendMessage($mainValues['not_correct_text']);
        exit();
    }
    $text = htmlspecialchars(stripslashes(trim($text)));
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    
    $stmt = $connection->prepare("SELECT * FROM `server_config`");
    $stmt->execute();
    $serversList = $stmt->get_result();
    $stmt->close();
    $found = false; 
    $isMarzban = false;
    while($row = $serversList->fetch_assoc()){
        $serverId = $row['id'];
        $serverType = $row['type'];
        
        if($serverType == "marzban"){
            $usersList = getMarzbanJson($serverId)->users;
            if(strstr(json_encode($usersList, JSON_UNESCAPED_UNICODE), $marzbanText) && !empty($marzbanText)){
                $found = true;
                $isMarzban = true;
                foreach($usersList as $key => $config){
                    if(strstr(json_encode($config->links, JSON_UNESCAPED_UNICODE), $marzbanText)){
                	    $remark = $config->username;
                        $total = $config->data_limit!=0?sumerize($config->data_limit):"نامحدود";
                        $totalUsed = sumerize($config->used_traffic);
                        $state = $config->status == "active"?$buttonValues['active']:$buttonValues['deactive'];
                        $expiryTime = $config->expire != 0?jdate("Y-m-d H:i:s",$config->expire):"نامحدود";
                        $leftMb = $config->data_limit!=0?$config->data_limit - $config->used_traffic:"نامحدود";
                        
                        if(is_numeric($leftMb)){
                            if($leftMb<0) $leftMb = 0;
                            else $leftMb = sumerize($leftMb);
                        }
                        
                        $expiryDay = $config->expire != 0?
                            floor(
                                ($config->expire - time())/(60 * 60 * 24)
                                ):
                                "نامحدود";    
                        if(is_numeric($expiryDay)){
                            if($expiryDay<0) $expiryDay = 0;
                        }
                	    $configLocation = ["remark" => $remark ,"uuid" =>$text, "marzban"=>true];
                        break;
                    }
                }
                break;
            }
        }else{
            $response = getJson($serverId);
            if($response->success){
                if(strstr(json_encode($response->obj), $text)){
                    $found = true;
                    $list = $response->obj;
                    if(!isset($list[0]->clientStats)){
                        foreach($list as $keys=>$packageInfo){
                        	if(strstr($packageInfo->settings, $text)){
                        	    $configLocation = ["remark"=> $packageInfo->remark, "uuid" =>$text];
                        	    $remark = $packageInfo->remark;
                                $upload = sumerize($packageInfo->up);
                                $download = sumerize($packageInfo->down);
                                $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                                $total = $packageInfo->total!=0?sumerize($packageInfo->total):"نامحدود";
                                $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"نامحدود";
                                $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"نامحدود";
                                $expiryDay = $packageInfo->expiryTime != 0?
                                    floor(
                                        (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24))
                                        :
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                break;
                        	}
                        }
                    }
                    else{
                        $keys = -1;
                        $settings = array_column($list,'settings');
                        foreach($settings as $key => $value){
                        	if(strstr($value, $text)){
                        		$keys = $key;
                        		break;
                        	}
                        }
                        if($keys == -1){
                            $found = false;
                            break;
                        }
                        $clientsSettings = json_decode($list[$keys]->settings,true)['clients'];
                        if(!is_array($clientsSettings)){
                            sendMessage("با عرض پوزش، متأسفانه مشکلی رخ داده است، لطفا مجدد اقدام کنید");
                            exit();
                        }
                        $settingsId = array_column($clientsSettings,'id');
                        $settingKey = array_search($text,$settingsId);
                        
                        if(!isset($clientsSettings[$settingKey]['email'])){
                            $packageInfo = $list[$keys];
                    	    $configLocation = ["remark" => $packageInfo->remark ,"uuid" =>$text];
                    	    $remark = $packageInfo->remark;
                            $upload = sumerize($packageInfo->up);
                            $download = sumerize($packageInfo->down);
                            $state = $packageInfo->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                            $totalUsed = sumerize($packageInfo->up + $packageInfo->down);
                            $total = $packageInfo->total!=0?sumerize($packageInfo->total):"نامحدود";
                            $expiryTime = $packageInfo->expiryTime != 0?jdate("Y-m-d H:i:s",substr($packageInfo->expiryTime,0,-3)):"نامحدود";
                            $leftMb = $packageInfo->total!=0?sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down):"نامحدود";
                            if(is_numeric($leftMb)){
                                if($leftMb<0){
                                    $leftMb = 0;
                                }else{
                                    $leftMb = sumerize($packageInfo->total - $packageInfo->up - $packageInfo->down);
                                }
                            }
    
                            
                            $expiryDay = $packageInfo->expiryTime != 0?
                                floor(
                                    (substr($packageInfo->expiryTime,0,-3)-time())/(60 * 60 * 24)
                                    ):
                                    "نامحدود";    
                            if(is_numeric($expiryDay)){
                                if($expiryDay<0) $expiryDay = 0;
                            }
                        }else{
                            $email = $clientsSettings[$settingKey]['email'];
                            $clientState = $list[$keys]->clientStats;
                            $emails = array_column($clientState,'email');
                            $emailKey = array_search($email,$emails);                    
                 
                            // if($clientState[$emailKey]->total != 0 || $clientState[$emailKey]->up != 0  ||  $clientState[$emailKey]->down != 0 || $clientState[$emailKey]->expiryTime != 0){
                            if(count($clientState) > 1){
                        	    $configLocation = ["id" => $list[$keys]->id, "remark"=>$email, "uuid"=>$text];
                                $upload = sumerize($clientState[$emailKey]->up);
                                $download = sumerize($clientState[$emailKey]->down);
                                $total = $clientState[$emailKey]->total==0 && $list[$keys]->total !=0?$list[$keys]->total:$clientState[$emailKey]->total;
                                $leftMb = $total!=0?($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down):"نامحدود";
                                if(is_numeric($leftMb)){
                                    if($leftMb<0){
                                        $leftMb = 0;
                                    }else{
                                        $leftMb = sumerize($total - $clientState[$emailKey]->up - $clientState[$emailKey]->down);
                                    }
                                }
                                $totalUsed = sumerize($clientState[$emailKey]->up + $clientState[$emailKey]->down);
                                $total = $total!=0?sumerize($total):"نامحدود";
                                $expTime = $clientState[$emailKey]->expiryTime == 0 && $list[$keys]->expiryTime?$list[$keys]->expiryTime:$clientState[$emailKey]->expiryTime;
                                $expiryTime = $expTime != 0?jdate("Y-m-d H:i:s",substr($expTime,0,-3)):"نامحدود";
                                $expiryDay = $expTime != 0?
                                    floor(
                                        ((substr($expTime,0,-3)-time())/(60 * 60 * 24))
                                        ):
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                $state = $clientState[$emailKey]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $remark = $email;
                            }
                            else{
                                $clientUpload = $clientState[$emailKey]->up;
                                $clientDownload = $clientState[$emailKey]->down;
                                $clientTotal = $clientState[$emailKey]->total;
                                $clientExpTime = $clientState[$emailKey]->expiryTime;
                                
                                $up = $list[$keys]->up;
                                $down = $list[$keys]->down;
                                $total = $list[$keys]->total;
                                $expiry = $list[$keys]->expiryTime;
                                
                                if(($clientTotal != 0 || $clientTotal != null) && ($clientExpTime != 0 || $clientExpTime != null)){
                                    $up = $clientUpload;
                                    $down = $clientDownload;
                                    $total = $clientTotal;
                                    $expiry = $clientExpTime;
                                }
    
                                $upload = sumerize($up);
                                $download = sumerize($down);
                                $configLocation = ["uuid" => $text, "remark"=>$list[$keys]->remark];
                                $leftMb = $total!=0?($total - $up - $down):"نامحدود";
                                if(is_numeric($leftMb)){
                                    if($leftMb<0){
                                        $leftMb = 0;
                                    }else{
                                        $leftMb = sumerize($total - $up - $down);
                                    }
                                }
                                $totalUsed = sumerize($up + $down);
                                $total = $total!=0?sumerize($total):"نامحدود";
                                
                                
                                $expiryTime = $expiry != 0?jdate("Y-m-d H:i:s",substr($expiry,0,-3)):"نامحدود";
                                $expiryDay = $expiry != 0?
                                    floor(
                                        ((substr($expiry,0,-3)-time())/(60 * 60 * 24))
                                        ):
                                        "نامحدود";
                                if(is_numeric($expiryDay)){
                                    if($expiryDay<0) $expiryDay = 0;
                                }
                                $state = $list[$keys]->enable == true?$buttonValues['active']:$buttonValues['deactive'];
                                $remark = $list[$keys]->remark;
                            }
                        }
                    }
                    break;
                }
            }
        }
    }
    if(!$found){
         sendMessage("ای وای ، اطلاعاتت اشتباهه 😔",$cancelKey);
    }else{
        setUser();
        $keys = json_encode(['inline_keyboard'=>array_merge([
        [
            ['text'=>$state??" ",'callback_data'=>"deltach"],
            ['text'=>"🔘 وضعیت اکانت 🔘",'callback_data'=>"deltach"],
            ],
        [
    		['text'=>$remark??" ",'callback_data'=>"deltach"],
            ['text'=>"« نام اکانت »",'callback_data'=>"deltach"],
            ]],(!$isMarzban?[
        [
            ['text'=>$upload?? " ",'callback_data'=>"deltach"],
            ['text'=>"√ آپلود √",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$download??" ",'callback_data'=>"deltach"],
            ['text'=>"√ دانلود √",'callback_data'=>"deltach"],
            ]]:[
        [
            ['text'=>$totalUsed?? " ",'callback_data'=>"deltach"],
            ['text'=>"√ آپلود + دانلود √",'callback_data'=>"deltach"],
            ]]),[
        [
            ['text'=>$total??" ",'callback_data'=>"deltach"],
            ['text'=>"† حجم کلی †",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$leftMb??" ",'callback_data'=>"deltach"],
            ['text'=>"~ حجم باقیمانده ~",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$expiryTime??" ",'callback_data'=>"deltach"],
            ['text'=>"تاریخ اتمام",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$expiryDay??" ",'callback_data'=>"deltach"],
            ['text'=>"تعداد روز باقیمانده",'callback_data'=>"deltach"],
            ],
        (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] == "on")?
            [
                ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId],
                ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId],
                ]:[]
                ),
        (($botState['renewAccountState'] != "on" && $botState['updateConfigLinkState'] == "on")?
            [
                ['text'=>$buttonValues['update_config_connection'],'callback_data'=>"sConfigUpdate" . $serverId]
                ]:[]
                ),
        (($botState['renewAccountState'] == "on" && $botState['updateConfigLinkState'] != "on")?
            [
                ['text'=>$buttonValues['renew_config'],'callback_data'=>"sConfigRenew" . $serverId]
                ]:[]
                ),
        [['text'=>"صفحه اصلی",'callback_data'=>"mainMenu"]]
        ])]);
        setUser(json_encode($configLocation,488), "temp");
        sendMessage("🔰مشخصات حسابت:",$keys,"Markdown");
    }
}

if(preg_match('/sConfigRenew(\d+)/', $data,$match)){
    if($botState['sellState']=="off" && $from_id !=$admin){ alert($mainValues['bot_is_updating']); exit(); }
    
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    if(isset($configInfo['marzban'])){
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `custom_sni` LIKE '%inbounds%' AND `active` = 1 AND `price` != 0");
        $stmt->bind_param("i", $server_id);
    }else{
        $response = getJson($server_id)->obj;
        if($response == null){delMessage(); exit();}
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` = 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $configReality = json_decode($row->streamSettings)->security == "reality"?"true":"false";
                    break;
                }
            }
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id` = ? AND `inbound_id` != 0 AND `protocol` = ? AND `active` = 1 AND `price` != 0 AND `rahgozar` = 0");
        }
        $stmt->bind_param("is", $server_id, $protocol);
    }
    
    $stmt->execute();
    $plans = $stmt->get_result();
    $stmt->close();
    if($plans->num_rows > 0){
        $keyboard = [];
        while($file = $plans->fetch_assoc()){ 
            $add = false;
            
            if(isset($configInfo['marzban'])) $add = true;
            else{
                $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
                $stmt->bind_param("i", $server_id);
                $stmt->execute();
                $isReality = $stmt->get_result()->fetch_assoc()['reality'];
                $stmt->close();
                
                if($isReality == $configReality) $add = true;
            }
            
            if($add){
                $id = $file['id'];
                $name = $file['title'];
                $price = applyUserPercentDiscount($from_id, (int)$file['price']);
                $price = ($price == 0) ? 'رایگان' : number_format($price).' تومان ';
                $keyboard[] = ['text' => "$name - $price", 'callback_data' => "sConfigRenewPlan{$id}_{$inboundId}"];
            }
        }
        $keyboard[] = ['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"];
        $keyboard = array_chunk($keyboard,1);
        smartSendOrEdit($message_id, "3️⃣ مرحله سه:

یکی از پلن هارو انتخاب کن و برو برای پرداختش 🤲 🕋", json_encode(['inline_keyboard'=>$keyboard]));
    }else sendMessage("💡پلنی در این دسته بندی وجود ندارد ");
}
if(preg_match('/sConfigRenewPlan(\d+)_(\d+)/',$data, $match) && ($botState['sellState']=="on" ||$from_id ==$admin) && $text != $buttonValues['cancel']){
    $id = $match[1];
	$inbound_id = $match[2];


    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];

    alert($mainValues['receving_information']);
    delMessage();
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? and `active`=1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $respd['catid']);
    $stmt->execute();
    $catname = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $name = $catname." ".$respd['title'];
    $desc = $respd['descr'];
	$sid = $respd['server_id'];
	$keyboard = array();
    $price =  $respd['price'];
    $token = base64_encode("{$from_id}.{$id}");
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_SCONFIG' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();

    setUser('', 'temp');
    $description = json_encode(["uuid"=>$uuid, "remark"=>$remark, 'marzban' => isset($configInfo['marzban'])],488);
    // apply user percent discount (from user info panel)
    $price = applyUserPercentDiscount($from_id, $price);

    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `description`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, 'RENEW_SCONFIG', ?, ?, '0', ?, ?, 'pending')");
    $stmt->bind_param("ssiiiii", $hash_id, $description, $from_id, $id, $inbound_id, $price, $time);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

	$keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    $basePrice = (int)($respd['price'] ?? $price);
    $discountAmount = $basePrice - (int)$price;
    if($discountAmount < 0) $discountAmount = 0;
    $discountPercent = ($basePrice > 0) ? floor(($discountAmount * 100) / $basePrice) : 0;
    $currentWallet = (int)($userInfo['wallet'] ?? 0);
    $walletAfter = $currentWallet - (int)$price;
    if($walletAfter < 0) $walletAfter = 0;
    $msg = str_replace(
        ['PLAN-NAME','BASE-PRICE','FINAL-PRICE','DESCRIPTION','CURRENT-WALLET','WALLET-AFTER','PLAN-VOLUME','PLAN-DAYS','DISCOUNT-AMOUNT','DISCOUNT-PERCENT'],
        [$name, number_format($basePrice).' تومان', number_format($price).' تومان', $desc, number_format($currentWallet), number_format($walletAfter), ($respd['volume']??0), ($respd['days']??0), number_format($discountAmount), $discountPercent],
        $mainValues['buy_subscription_detail']
    );
    sendMessage($msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
}
if(preg_match('/sConfigUpdate(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $server_id = $match[1];
    if(empty($userInfo['temp'])){delMessage(); exit();}
    
    $configInfo = json_decode($userInfo['temp'],true);
    $inboundId = $configInfo['id']??0;
    $uuid = $configInfo['uuid'];
    $remark = $configInfo['remark'];


    if(isset($configInfo['marzban'])){
        $info = getMarzbanUserInfo($server_id, $remark);
        $vraylink = $info->links;
    }else{
        $response = getJson($server_id)->obj;
        if($response == null){delMessage(); exit();}
        
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }
        
        if($uuid == null){delMessage(); exit();}
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId);
    }
    
    if($vraylink == null){delMessage(); exit();}
    include 'phpqrcode/qrlib.php';  
    define('IMAGE_WIDTH',540);
    define('IMAGE_HEIGHT',540);
    foreach($vraylink as $vray_link){
        $acc_text = $botState['configLinkState'] != "off"?"<code>$vray_link</code>":".";
    
        $ecc = 'L';
        $pixel_Size = 11;
        $frame_Size = 0;
        
        $file = RandomString() .".png";
        QRcode::png($vray_link, $file, $ecc, $pixel_Size, $frame_Size);
    	addBorderImage($file);
    	
        $backgroundImage = imagecreatefromjpeg("settings/QRCode.jpg");
        $qrImage = imagecreatefrompng($file);
        
        $qrSize = array('width' => imagesx($qrImage), 'height' => imagesy($qrImage));
        imagecopy($backgroundImage, $qrImage, 300, 300 , 0, 0, $qrSize['width'], $qrSize['height']);
        imagepng($backgroundImage, $file);
        imagedestroy($backgroundImage);
        imagedestroy($qrImage);

        sendPhoto($botUrl . $file, $acc_text,null,"HTML");
        unlink($file);
    }
}

if (($data == 'addNewPlan' || $data=="addNewRahgozarPlan" || $data == "addNewMarzbanPlan") and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();
    if($data=="addNewPlan" || $data == "addNewMarzbanPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`)
                                            VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?);";
    }elseif($data=="addNewRahgozarPlan"){
        $sql = "INSERT INTO `server_plans` (`fileid`, `catid`, `server_id`, `inbound_id`, `acount`, `limitip`, `title`, `protocol`, `days`, `volume`, `type`, `price`, `descr`, `pic`, `active`, `step`, `date`, `rahgozar`)
                    VALUES ('', 0,0,0,0, 1, '', '', 0, 0, '', 0, '', '',0,1, ?, 1);";
    }
    $stmt = $connection->prepare($sql);
    $stmt->bind_param("i", $time);
    $stmt->execute();
    $stmt->close();
    delMessage();
    $msg = '❗️یه عنوان برا پلن انتخاب کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/(addNewRahgozarPlan|addNewPlan|addNewMarzbanPlan)/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $catkey = [];
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `parent` =0 and `active`=1");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    while ($cat = $cats->fetch_assoc()){
        $id = $cat['id'];
        $name = $cat['title'];
        $catkey[] = ["$id - $name"];
    }
    $catkey[] = [$buttonValues['cancel']];

    $step = checkStep('server_plans');

    if($step==1 and $text!=$buttonValues['cancel']){
        $msg = '🔰 لطفا قیمت پلن رو به تومان وارد کنید!';
        if(strlen($text)>1){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=?,`step`=2 WHERE `active`=0 and `step`=1");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==2 and $text!=$buttonValues['cancel']){
        $msg = '🔰لطفا یه دسته از لیست زیر برا پلن انتخاب کن ';
        if(is_numeric($text)){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=?,`step`=3 WHERE `active`=0");
            $stmt->bind_param("s", $text);
            $stmt->execute();
            $stmt->close();
            sendMessage($msg,json_encode(['keyboard'=>$catkey,'resize_keyboard'=>true]));
        }else{
            $msg = '‼️ لطفا یک مقدار عددی وارد کنید';
            sendMessage($msg,$cancelKey);
        }
    } 
    if($step==3 and $text!=$buttonValues['cancel']){
        $srvkey = [];

        $stmt = $connection->prepare("SELECT `id` FROM `server_config` WHERE `type` = 'marzban'");
        $stmt->execute();
        $info = $stmt->get_result()->fetch_all();
        $stmt->close();
        
        
        
        $marzbanList = array_column($info, 0); 
        if(count($marzbanList) > 0) $condition  = " AND `id` " .($userInfo['step'] == "addNewMarzbanPlan"?"IN":"NOT IN") . " (" . implode(", ", $marzbanList) . ")";
        else $condition = "";


        $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 $condition");
        $stmt->execute();
        
        $srvs = $stmt->get_result();
        $stmt->close();
        sendMessage($mainValues['please_wait_message'],$cancelKey);
        while($srv = $srvs->fetch_assoc()){
            $id = $srv['id'];
            $title = $srv['title'];
            $srvkey[] = ['text' => "$title", 'callback_data' => "selectNewPlanServer$id"];
        }
        $srvkey = array_chunk($srvkey,2);
        sendMessage("لطفا یکی از سرورها رو انتخاب کن 👇 ", json_encode([
                'inline_keyboard' => $srvkey]), "HTML");
        $inarr = 0;
        foreach ($catkey as $op) {
            if (in_array($text, $op) and $text != $buttonValues['cancel']) {
                $inarr = 1;
            }
        }
        if( $inarr==1 ){
            $input = explode(' - ',$text);
            $catid = $input[0];
            $stmt = $connection->prepare("UPDATE `server_plans` SET `catid`=?,`step`=50 WHERE `active`=0");
            $stmt->bind_param("i", $catid);
            $stmt->execute();
            $stmt->close();

            sendMessage($msg,$cancelKey);
        }else{
            $msg = '‼️ لطفا فقط یکی از گزینه های پیشنهادی زیر را انتخاب کنید';
            sendMessage($msg,$catkey);
        }
    } 
    if($step==50 and $text!=$buttonValues['cancel'] and preg_match('/selectNewPlanServer(\d+)/', $data,$match)){
        $newStep = $userInfo['step'] == "addNewMarzbanPlan"?53:51;
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `server_id`=?,`step`=? WHERE `active`=0");
        $stmt->bind_param("ii", $match[1], $newStep);
        $stmt->execute();
        $stmt->close();

        $keys = json_encode(['inline_keyboard'=>[
            [['text'=>"🎖پورت اختصاصی",'callback_data'=>"withSpecificPort"]],
            [['text'=>"🎗پورت اشتراکی",'callback_data'=>"withSharedPort"]]
            ]]);
        if($userInfo['step'] != "addNewMarzbanPlan") smartSendOrEdit($message_id, "لطفا نوعیت پورت پنل رو انتخاب کنید", $keys);
        else smartSendOrEdit($message_id, "📅 | لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==51 and $text!=$buttonValues['cancel'] and preg_match('/^with(Specific|Shared)Port/',$data,$match)){
        if($userInfo['step'] == "addNewRahgozarPlan") $msg =  "📡 | لطفا پروتکل پلن مورد نظر را وارد کنید (vless | vmess)";
        else $msg =  "📡 | لطفا پروتکل پلن مورد نظر را وارد کنید (vless | vmess | trojan)";
        smartSendOrEdit($message_id,$msg);
        if($match[1] == "Shared"){
            $stmt = $connection->prepare("UPDATE `server_plans` SET `step`=60 WHERE `active`=0");
            $stmt->execute();
            $stmt->close();
        }
        elseif($match[1] == "Specific"){
            $stmt = $connection->prepare("UPDATE server_plans SET step=52 WHERE active=0");
            $stmt->execute();
            $stmt->close();
        }
    }
    if($step==60 and $text!=$buttonValues['cancel']){
        if($text != "vless" && $text != "vmess" && $text != "trojan" && $userInfo['step'] == "addNewPlan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        elseif($text != "vless" && $text != "vmess" && $userInfo['step'] == "addNewRahgozarPlan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=61 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("📅 | لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==61 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=62 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | لطفا مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==62 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `volume`=?,`step`=63 WHERE `active`=0");
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();
        sendMessage("🛡 | لطفا آیدی سطر کانکشن در پنل را وارد کنید:");
    }
    if($step==63 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        
        $response = getJson($res['server_id'])->obj;
        foreach($response as $row){
            if($row->id == $text) {
                $netType = json_decode($row->streamSettings)->network;
            }
        }        
        if(is_null($netType)){
            sendMessage("کانفیگی با این سطر آیدی یافت نشد");
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type` = ?, `inbound_id`=?,`step`=64 WHERE `active`=0");
        $stmt->bind_param("si", $netType, $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("لطفا ظرفیت تعداد اکانت رو پورت مورد نظر را وارد کنید");
    }
    if($step==64 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=?,`step`=65 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🧲 | لطفا تعداد چند کاربره این پلن را وارد کنید ( 0 نامحدود است )");
    }
    if($step==65 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `limitip`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    if($step==52 and $text!=$buttonValues['cancel']){
        if($userInfo['step'] == "addNewPlan" && $text != "vless" && $text != "vmess" && $text != "trojan"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }elseif($userInfo['step'] == "addNewRahgozarPlan" && $text != "vless" && $text != "vmess"){
            sendMessage("لطفا فقط پروتکل های vless و vmess را وارد کنید",$cancelKey);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `protocol`=?,`step`=53 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("📅 | لطفا تعداد روز های اعتبار این پلن را وارد کنید:");
    }
    if($step==53 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        $stmt = $connection->prepare("UPDATE `server_plans` SET `days`=?,`step`=54 WHERE `active`=0");
        $stmt->bind_param("i", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage("🔋 | لطفا مقدار حجم به GB این پلن را وارد کنید:");
    }
    if($step==54 and $text!=$buttonValues['cancel']){
        if(!is_numeric($text)){
            sendMessage($mainValues['send_only_number']);
            exit();
        }
        
        if($userInfo['step'] == "addNewPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?,`step`=55 WHERE `active`=0");
            $msg = "🔉 | لطفا نوع شبکه این پلن را در انتخاب کنید  (ws | tcp | grpc) :";
        }elseif($userInfo['step'] == "addNewRahgozarPlan" || $userInfo['step'] == "addNewMarzbanPlan"){
            $sql = ("UPDATE `server_plans` SET `volume`=?, `type`='ws', `step`=4 WHERE `active`=0");
            $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        }
        $stmt = $connection->prepare($sql);
        $stmt->bind_param("d", $text);
        $stmt->execute();
        $stmt->close();

        sendMessage($msg);
    }
    if($step==55 and $text!=$buttonValues['cancel']){
        if($text != "tcp" && $text != "ws" && $text != "grpc"){
            sendMessage("لطفا فقط نوع (ws | tcp | grpc) را وارد کنید");
            exit();
        }
        $stmt = $connection->prepare("UPDATE `server_plans` SET `type`=?,`step`=4 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = '🔻یه توضیح برای پلن مورد نظرت بنویس:';
        sendMessage($msg,$cancelKey); 
    }
    
    if($step==4 and $text!=$buttonValues['cancel']){
        
        if($userInfo['step'] == "addNewMarzbanPlan"){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0 AND `step` = 4");
            $stmt->execute();
            $serverId = $stmt->get_result()->fetch_assoc()['server_id'];
            $stmt->close();
        
            $hosts = getMarzbanHosts($serverId)->inbounds;
            $networkType = array();
            foreach($hosts as $key => $inbound){
                $networkType[] = [['text'=>$inbound->tag, 'callback_data'=>"planNetworkType{$inbound->protocol}*_*{$inbound->tag}"]];
            }
            $networkType = json_encode(['inline_keyboard'=>$networkType]);

            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `step` = 5 WHERE `step` = 4");
            sendMessage("لطفا نوع شبکه های این پلن را انتخاب کنید",$networkType);
        }
        else{
            $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=?, `active`=1,`step`=10 WHERE `step`=4");
            $imgtxt = '☑️ | پنل با موفقیت ثبت و ایجاد شد ( لذت ببرید ) ';
            
            sendMessage($imgtxt,$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getAdminKeys());
            setUser();
        }
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();

    } 
    elseif($step == 5 and $text != $buttonValues['cancel'] && preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$data,$match)){
        $saveBtn = "ذخیره ✅";
        if($markup[count($markup)-1][0]['text'] == $saveBtn) unset($markup[count($markup)-1]);

        foreach($markup as $key => $keyboard){
            if($keyboard[0]['callback_data'] == $data) $markup[$key][0]['text'] = $keyboard['0']['text'] == $match['tag'] . " ✅" ? $match['tag']:$match['tag'] . " ✅";
        }

        if(strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), "✅") && !strstr(json_encode($markup,JSON_UNESCAPED_UNICODE), $saveBtn)){
            $markup[] = [['text'=>$saveBtn,'callback_data'=>"savePlanNetworkType"]];
        }
        $markup = json_encode(['inline_keyboard'=>array_values($markup)]);
        
        editKeys($markup);
    }
    elseif($step == 5 && $text != $buttonValues['cancel'] && $data == "savePlanNetworkType"){
        delMessage();
        $inbounds = array();
        $proxies = array();
        unset($markup[count($markup)-1]);

        foreach($markup as $key=>$value){
            $tag = trim(str_replace("✅", "", $value[0]['text'], $state));
            if($state > 0){
                preg_match('/^planNetworkType(?<protocol>.+)\*_\*(?<tag>.*)/',$value[0]['callback_data'],$info);
                $inbounds[$info['protocol']][] = $tag;
                $proxies[$info['protocol']] = array();
    
                if($info['protocol'] == "vless"){
                    $proxies["vless"] = ["flow" => ""];
                }
                elseif($info['protocol'] == "shadowsocks"){
                    $proxies["shadowsocks"] = ['method' => "chacha20-ietf-poly1305"];
                }
            }
        }
        
        $info = json_encode(['inbounds'=>$inbounds, 'proxies'=>$proxies]);
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`=?, `active`=1,`step`=10 WHERE `step`=5");
        $stmt->bind_param("s", $info);
        $stmt->execute();
        $stmt->close();
        
        $imgtxt = '☑️ | پنل با موفقیت ثبت و ایجاد شد ( لذت ببرید ) ';
        sendMessage($imgtxt,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getAdminKeys());
        setUser();
    }
}
if($data == 'backplan' and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['title'];
        $keyboard[] = ['text' => "$title", 'callback_data' => "plansList$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"➖➖➖",'callback_data'=>"deltach"]];
    $keyboard[] = [['text'=>'➕ افزودن پلن اختصاصی و اشتراکی','callback_data'=>"addNewPlan"]];
    $keyboard[] = [
        ['text'=>'➕ افزودن پلن رهگذر','callback_data'=>"addNewRahgozarPlan"],
        ['text'=>"افزودن پلن مرزبان",'callback_data'=>"addNewMarzbanPlan"]
                    ];
    $keyboard[] = [['text'=>'➕ افزودن پلن حجمی','callback_data'=>"volumePlanSettings"],['text'=>'➕ افزودن پلن زمانی','callback_data'=>"dayPlanSettings"]];
    $keyboard[] = [['text' => "➕ افزودن پلن دلخواه", 'callback_data' => "editCustomPlan"]];
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];

    $msg = ' ☑️ مدیریت پلن ها:';
    
    if(isset($data) and $data=='backplan') {
        smartSendOrEdit($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendmessage($msg, json_encode(['inline_keyboard'=>$keyboard]));
    }
    
    
    exit;
}
if(($data=="editCustomPlan" || preg_match('/^editCustom(gbPrice|dayPrice)/',$userInfo['step'],$match)) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(!isset($data)){
        if(is_numeric($text)){
            setSettings($match[1], $text);
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard); 
        }else{
            sendMessage("فقط عدد ارسال کن");
            exit();
        }
    }
    $gbPrice=number_format($botState['gbPrice']??0) . " تومان";
    $dayPrice=number_format($botState['dayPrice']??0) . " تومان";
    
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>$gbPrice,'callback_data'=>"editCustomgbPrice"],
            ['text'=>"هزینه هر گیگ",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$dayPrice,'callback_data'=>"editCustomdayPrice"],
            ['text'=>"هزینه هر روز",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$buttonValues['back_button'],'callback_data'=>"backplan"]
            ]
            
        ]]);
    if(!isset($data)){
        sendMessage("تنظیمات پلن دلخواه",$keys);
        setUser();
    }else{
        smartSendOrEdit($message_id,"تنظیمات پلن دلخواه",$keys);
    }
}
if(preg_match('/^editCustom(gbPrice|dayPrice)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    $title = $match[1] == "dayPrice"?"هر روز":"هر گیگ";
    sendMessage("لطفا هزینه " . $title . " را به تومان وارد کنید",$cancelKey);
    setUser($data);
}
if(preg_match('/plansList(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `server_id`=? ORDER BY`id` ASC");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows==0){
        alert("متاسفانه، هیچ پلنی براش انتخاب نکردی 😑");
        exit;
    }else {
        $keyboard = [];
        while($cat = $res->fetch_assoc()){
            $id = $cat['id'];
            $title = $cat['title'];
            $keyboard[] = ['text' => "#$id $title", 'callback_data' => "planDetails$id"];
        }
        $keyboard = array_chunk($keyboard,2);
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "backplan"],];
        $msg = ' ▫️ یه پلن رو انتخاب کن بریم برای ادیت:';
        smartSendOrEdit($message_id, $msg, json_encode(['inline_keyboard'=>$keyboard]), "HTML");
    }
    exit();
}
if(preg_match('/planDetails(\d+)/', $data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else smartSendOrEdit($message_id, "ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^deltaplanacclist(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        alert('لیست خالی است');
        exit;
    }
    $txt = '';
    while($order = $res->fetch_assoc()){
		$suid = $order['userid'];
		$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
        $stmt->bind_param("i", $suid);
        $stmt->execute();
        $ures = $stmt->get_result()->fetch_assoc();
        $stmt->close();


        $date = $order['date'];
        $remark = $order['remark'];
        $date = jdate('Y-m-d H:i', $date);
        $uname = $ures['name'];
        $sold = " 🚀 ".$uname. " ($date)";
        $accid = $order['id'];
        $orderLink = json_decode($order['link'],true);
        $txt = "$sold \n  ☑️ $remark ";
        foreach($orderLink as $link){
            $txt .= $botState['configLinkState'] != "off"?"<code>".$link."</code> \n":"";
        }
        $txt .= "\n ❗ $channelLock \n";
        sendMessage($txt, null, "HTML");
    }
}
if(preg_match('/^deltaplandelete(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("پلن رو برات حذفش کردم ☹️☑️");
    
    smartSendOrEdit($message_id,"لطفا یکی از کلید های زیر را انتخاب کنید",getMainKeys());
}
if(preg_match('/^deltaplanname(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 یه اسم برا پلن جدید انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^deltaplanname(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys);
}
if(preg_match('/^deltaplanslimit(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 ظرفیت جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^deltaplanslimit(\d+)/',$userInfo['step'], $match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `acount`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^deltaplansinobundid(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🔅 سطر جدید برای پلن انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/^deltaplansinobundid(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `inbound_id`=? WHERE `id`=?");
    $stmt->bind_param("ii", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^deltaplaneditdes(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 توضیحاتت رو برام وارد کن:",$cancelKey);exit;
}
if(preg_match('/^deltaplaneditdes(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `descr`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editDestName(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 dest رو برام وارد کن:\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editDestName(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) &&  $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest` = NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `dest`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editSpiderX(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 spiderX رو برام وارد کن\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editSpiderX(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `spiderX`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editServerNames(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 serverNames رو به صورت زیر برام وارد کن:\n
`[
  \"yahoo.com\",
  \"www.yahoo.com\"
]`
    \n\nبرای حذف کردن متن /empty رو وارد کن",$cancelKey);exit;
}
if(preg_match('/^editServerNames(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=NULL WHERE `id`=?");
        $stmt->bind_param("s", $match[1]);
    }else{
        $stmt = $connection->prepare("UPDATE `server_plans` SET `serverNames`=? WHERE `id`=?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();


    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    if($keys == null){
        alert("موردی یافت نشد");
        exit;
    }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^editFlow(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    $keys = json_encode(['inline_keyboard'=>[
        [['text'=>"None", 'callback_data'=>"editPFlow" . $match[1] . "_None"]],
        [['text'=>"xtls-rprx-vision", 'callback_data'=>"editPFlow" . $match[1] . "_xtls-rprx-vision"]],
        ]]);
    sendMessage("🎯 لطفا یکی از موارد زیر رو انتخاب کن",$keys);exit;
}
if(preg_match('/^editPFlow(\d+)_(.*)/',$data, $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `flow`=? WHERE `id`=?");
    $stmt->bind_param("si", $match[2], $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    $keys = getPlanDetailsKeys($match[1]);
    smartSendOrEdit($message_id, "ویرایش تنظیمات پلن", $keys, "HTML");
}
if(preg_match('/^deltaplanrial(\d+)/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("🎯 شیطون قیمت و گرون کردی 😂 ، خب قیمت جدید و بزن ببینم :",$cancelKey);exit;
}
if(preg_match('/^deltaplanrial(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true)&& $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `price`=? WHERE `id`=?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();

        sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
        setUser();
        
        $keys = getPlanDetailsKeys($match[1]);
        if($keys == null){
            alert("موردی یافت نشد");
            exit;
        }else sendMessage("ویرایش تنظیمات پلن", $keys, "HTML");
    }else{
        sendMessage("بهت میگم قیمت وارد کن برداشتی یه چیز دیگه نوشتی 🫤 ( عدد وارد کن ) عجبا");
    }
}
if(($data == 'mySubscriptions' || $data == "agentConfigsList" or preg_match('/(changeAgentOrder|changeOrdersPage)(\d+)/',$data, $match) )&& ($botState['sellState']=="on" || $from_id ==$admin)){
    $results_per_page = 50;
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1");  
    else $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0");  
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $number_of_result= $stmt->get_result()->num_rows;
    $stmt->close();

    $number_of_page = ceil ($number_of_result / $results_per_page);
    $page = $match[2] ??1;
    $page_first_result = ($page-1) * $results_per_page;  
    
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 ORDER BY `id` DESC LIMIT ?, ?");
    else $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `status`=1 AND `agent_bought` = 0 ORDER BY `id` DESC LIMIT ?, ?");
    $stmt->bind_param("iii", $from_id, $page_first_result, $results_per_page);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();


    if($orders->num_rows==0){
        alert($mainValues['you_dont_have_config']);
        exit;
    }
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $remark = $cat['remark'];
        $keyboard[] = ['text' => "$remark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,1);
    
    $prev = $page - 1;
    $next = $page + 1;
    $lastpage = ceil($number_of_page/$results_per_page);
    $lpm1 = $lastpage - 1;
    
    $buttons = [];
    if ($prev > 0) $buttons[] = ['text' => "◀", 'callback_data' => (($data=="agentConfigsList" || $match[1] == "changeAgentOrder") ? "changeAgentOrder$prev":"changeOrdersPage$prev")];

    if ($next > 0 and $page != $number_of_page) $buttons[] = ['text' => "➡", 'callback_data' => (($data=="agentConfigsList" || $match[1] == "changeAgentOrder")?"changeAgentOrder$next":"changeOrdersPage$next")];   
    $keyboard[] = $buttons;
    if($data == "agentConfigsList" || $match[1] == "changeAgentOrder") $keyboard[] = [['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchAgentConfig"]];
    else $keyboard[] = [['text'=>$buttonValues['search_agent_config'],'callback_data'=>"searchMyConfig"]];
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    
    if(isset($data)) {
        smartSendOrEdit($message_id, $mainValues['select_one_to_show_detail'], json_encode(['inline_keyboard'=>$keyboard]));
    }else { sendAction('typing');
        sendMessage($mainValues['select_one_to_show_detail'], json_encode(['inline_keyboard'=>$keyboard]));
    }
    exit;
}
if($data=="searchAgentConfig" || $data == "searchMyConfig" || $data=="searchUsersConfig"){
    delMessage();
    sendMessage($mainValues['send_config_remark'],$cancelKey);
    setUser($data);
}
if(($userInfo['step'] == "searchAgentConfig" || $userInfo['step'] == "searchMyConfig") && $text != $buttonValues['cancel']){
    sendMessage($mainValues['please_wait_message'], $removeKeyboard);
    if($userInfo['step'] == "searchMyConfig") $condition = "AND `agent_bought` = 0";
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `remark` LIKE CONCAT('%', ?, '%') $condition");
    $stmt->bind_param("is", $from_id, $text);
    $stmt->execute();
    $orderId = $stmt->get_result()->fetch_assoc()['id'];
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $orderId);
    if($keys == null) sendMessage($mainValues['no_order_found']); 
    else {
        sendMessage($keys['msg'], $keys['keyboard'], "HTML");
        setUser();
    }
}
if(($userInfo['step'] == "searchUsersConfig" && $text != $buttonValues['cancel']) || preg_match('/^userOrderDetails(\d+)_(\d+)/',$data,$match)){
    if(isset($data)){
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else{
        sendMessage($mainValues['please_wait_message'], $removeKeyboard); 
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `remark` LIKE CONCAT('%', ?, '%')");
        $stmt->bind_param("s", $text);
    }
    $stmt->execute();
    $orderInfo = $stmt->get_result();
    $stmt->close();
    

    if($orderInfo->num_rows == 0) sendMessage($mainValues['no_order_found']); 
    else {
        $orderId = $orderInfo->fetch_assoc()['id'];
        $keys = getUserOrderDetailKeys($orderId, isset($data)?$match[2]:0);
        if($keys == null) sendMessage($mainValues['no_order_found']); 
        else{
            if(!isset($data)) sendMessage($keys['msg'], $keys['keyboard'], "HTML");
            else smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'], "HTML");
            setUser();
        }
    }
}
if(preg_match('/^orderDetails(\d+)(_|)(?<offset>\d+|)/', $data, $match) && ($botState['sellState']=="on" || ($from_id == $admin || $userInfo['isAdmin'] == true))){
    $keys = getOrderDetailKeys($from_id, $match[1], !empty($match['offset'])?$match['offset']:0);
    if($keys == null){
        alert($mainValues['no_order_found']);exit;
    }else smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="cantEditGrpc"){
    alert("نوعیت این کانفیگ رو تغییر داده نمیتونید!");
    exit();
}
if(preg_match('/^changeCustomPort(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا پورت مورد نظر خود را وارد کنید\nبرای حذف پورت دلخواه عدد 0 را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomPort(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_port`= ? WHERE `id` = ?");
        $stmt->bind_param("ii", $text, $match[1]);
        $stmt->execute();
        $stmt->close();  
        sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
         
        sendMessage("ویرایش تنظیمات پلن", getPlanDetailsKeys($match[1]));
        setUser();
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^changeCustomSni(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا sni مورد نظر خود را وارد کنید\nبرای حذف متن /empty را وارد کنید", $cancelKey);
    setUser($data);
}
if(preg_match('/^changeCustomSni(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if($text == "/empty"){
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= NULL WHERE `id` = ?");
        $stmt->bind_param("i", $match[1]);
    }
    else {
        $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_sni`= ? WHERE `id` = ?");
        $stmt->bind_param("si", $text, $match[1]);
    }
    $stmt->execute();
    $stmt->close();  
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
     
    sendMessage("ویرایش تنظیمات پلن", getPlanDetailsKeys($match[1]));
    setUser();
}
if(preg_match('/^changeCustomPath(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_plans` SET `custom_path` = IF(`custom_path` = 1, 0, 1) WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    editKeys(getPlanDetailsKeys($match[1]));
}
if(preg_match('/changeNetworkType(\d+)_(\d+)/', $data, $match)){
    $fid = $match[1];
    $oid = $match[2];
    
	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$oid";
		
	}else $name = "$oid";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $protocol = $order['protocol'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network; 
            $security = json_decode($row->streamSettings)->security;
            $netType = ($netType == 'tcp') ? 'ws' : 'tcp';
        break;
        }
    }

    if($protocol == 'trojan') $netType = 'tcp';

    $update_response = editInbound($server_id, $uuid, $uuid, $protocol, $netType);
    $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType);

    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=? WHERE `id`=?");
    $stmt->bind_param("ssi", $protocol, $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    
    $keys = getOrderDetailKeys($from_id, $oid);
    smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'], "HTML");
}
if($data=="changeProtocolIsDisable"){
    alert("تغییر پروتکل غیر فعال است");
}
if(preg_match('/updateConfigConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();

    $rahgozar = $order['rahgozar'];
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_config = $stmt->get_result()->fetch_assoc();
    $serverType = $server_config['type'];
    $netType = $file_detail['type'];
    $protocol = $file_detail['protocol'];
    $security = $server_config['security'];
    
    if($serverType == "marzban"){
        $info = getMarzbanUser($server_id, $remark);
        $vraylink = $info->links;
    }else{
        $response = getJson($server_id)->obj;
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $inboundRemark = $row->remark;
                    $iId = $row->id;
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $iId = $row->id;
                    $inboundRemark = $row->remark;
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
        }
    
        if($botState['updateConnectionState'] == "robot"){
            updateConfig($server_id, $iId, $protocol, $netType, $security, $rahgozar);
        }
        $vraylink = getConnectionLink($server_id, $uuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni);
        
    }
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=? WHERE `id`=?");
    $stmt->bind_param("si", $vray_link, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/changAccountConnectionLink(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $rahgozar = $order['rahgozar'];
    
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $serverType = $server_info['type'];

    if($serverType == "marzban"){
        $res = renewMarzbanUUID($server_id, $remark);
        $vraylink = $res->links;
        $newUuid = $newToken = str_replace("/sub/", "", $res->subscription_url);
    }else{
        $response = getJson($server_id)->obj;
        if($inboundId == 0){
            foreach($response as $row){
                $clients = json_decode($row->settings)->clients;
                if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                    $port = $row->port;
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
            
            $update_response = renewInboundUuid($server_id, $uuid);
        }else{
            foreach($response as $row){
                if($row->id == $inboundId) {
                    $port = $row->port; 
                    $protocol = $row->protocol;
                    $netType = json_decode($row->streamSettings)->network;
                    break;
                }
            }
            $update_response = renewClientUuid($server_id, $inboundId, $uuid);
        }
        $newUuid = $update_response->newUuid;
        $vraylink = getConnectionLink($server_id, $newUuid, $protocol, $remark, $port, $netType, $inboundId, $rahgozar, $customPath, $customPort, $customSni);
        $newToken = RandomString(30);
    }

    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `link`=?, `uuid` = ?, `token` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $vray_link, $newUuid, $newToken, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/changeUserConfigState(\d+)/', $data,$match)){
    alert($mainValues['please_wait_message']);
    $oid = $match[1];

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $userId = $order['userid'];
    $uuid = $order['uuid']??"0";
    $inboundId = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    
    if($inboundId == 0){
        if($serverType == "marzban") $update_response = changeMarzbanState($server_id, $remark);
        else $update_response = changeInboundState($server_id, $uuid);
    }else{
        $update_response = changeClientState($server_id, $inboundId, $uuid);
    }
    
    if($update_response->success){
        alert($mainValues['please_wait_message']);
    
        $keys = getUserOrderDetailKeys($oid);
        smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'], "HTML");
    }else sendMessage("عملیه مورد نظر با مشکل روبرو شد\n" . $update_response->msg);
}

if(preg_match('/changeAccProtocol(\d+)_(\d+)_(.*)/', $data,$match)){
    $fid = $match[1];
    $oid = $match[2];
    $protocol = $match[3];

	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();


	if($respd){
		$respd = $respd->fetch_assoc(); 
		$stmt= $connection->prepare("SELECT * FROM `server_categories` WHERE `id`=?");
        $stmt->bind_param("i", $respd['catid']);
        $stmt->execute();
        $cadquery = $stmt->get_result();
        $stmt->close();


		if($cadquery) {
			$catname = $cadquery->fetch_assoc()['title'];
			$name = $catname." ".$respd['title'];
		}else $name = "$id";
		
	}else $name = "$id";

    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();


    $date = jdate("Y-m-d H:i",$order['date']);
    $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $acc_link = $order['link'];
    $server_id = $order['server_id'];
    $price = $order['amount'];
    $rahgozar = $order['rahgozar'];
    $file_id = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $customPath = $file_detail['custom_path'];
    $customPort = $file_detail['custom_port'];
    $customSni = $file_detail['custom_sni'];
    
    $response = getJson($server_id)->obj;
    foreach($response as $row){
        $clients = json_decode($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $port = $row->port;
            $netType = json_decode($row->streamSettings)->network;
            $security = json_decode($row->streamSettings)->security;
            break;
        }
    }
    if($protocol == 'trojan') $netType = 'tcp';
    $uniqid = generateRandomString(42,$protocol); 
    $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB"; 
    $update_response = editInbound($server_id, $uniqid, $uuid, $protocol, $netType, $security, $rahgozar);
    $vraylink = getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, 0, $rahgozar, $customPath, $customPort, $customSni);
    
    $vray_link = json_encode($vraylink);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `protocol`=?,`link`=?, `uuid` = ? WHERE `id`=?");
    $stmt->bind_param("sssi", $protocol, $vray_link, $uniqid, $oid);
    $stmt->execute();
    $stmt->close();
    $keys = getOrderDetailKeys($from_id, $oid);
    smartSendOrEdit($message_id, $keys['msg'], $keys['keyboard'],"HTML");
}
if(preg_match('/^discountRenew(\d+)_(\d+)/',$userInfo['step'], $match) || preg_match('/renewAccount(\d+)/',$data,$match) && $text != $buttonValues['cancel']){
    if(preg_match('/^discountRenew/', $userInfo['step'])){
        $rowId = $match[2];
        
        $time = time();
        $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1) AND `hash_id` = ?");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $list = $stmt->get_result();
        $stmt->close();
        
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `id` = ?");
        $stmt->bind_param("i", $rowId);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $afterDiscount = $payInfo['price'];
        $stmt->close();
        
        if($list->num_rows>0){
            $discountInfo = $list->fetch_assoc();
            $amount = $discountInfo['amount'];
            $type = $discountInfo['type'];
            $count = $discountInfo['expire_count'];
            $usedBy = !is_null($discountInfo['used_by'])?json_decode($discountInfo['used_by'],true):array();            
            
            $canUse = $discountInfo['can_use'];
            $userUsedCount = array_count_values($usedBy)[$from_id];
            if($canUse > $userUsedCount){
                $usedBy[] = $from_id;
                $encodeUsedBy = json_encode($usedBy);
                
                if ($count != -1) $query = "UPDATE `discounts` SET `expire_count` = `expire_count` - 1, `used_by` = ? WHERE `id` = ?";
                else $query = "UPDATE `discounts` SET `used_by` = ? WHERE `id` = ?";
    
                $stmt = $connection->prepare($query);
                $stmt->bind_param("si", $encodeUsedBy, $discountInfo['id']);
                $stmt->execute();
                $stmt->close();
                
                if($type == "percent"){
                    $discount = $afterDiscount * $amount / 100;
                    $afterDiscount -= $discount;
                    $discount = number_format($discount) . " تومان";
                }else{
                    $afterDiscount -= $amount;
                    $discount = number_format($amount) . " تومان";
                }
                if($afterDiscount < 0) $afterDiscount = 0;
                
                $stmt = $connection->prepare("UPDATE `pays` SET `price` = ? WHERE `id` = ?");
                $stmt->bind_param("ii", $afterDiscount, $rowId);
                $stmt->execute();
                $stmt->close();
                sendMessage(str_replace("AMOUNT", $discount, $mainValues['valid_discount_code']));
                $keys = json_encode(['inline_keyboard'=>[
                    [
                        ['text'=>"❤️", "callback_data"=>"deltach"]
                        ],
                    ]]);
                sendToAdmins(
                    str_replace(['USERID', 'USERNAME', "NAME", "AMOUNT", "DISCOUNTCODE"], [$from_id, $username, $first_name, $discount, $text], $mainValues['used_discount_code'])
                    ,$keys,null);
            }else sendMessage($mainValues['not_valid_discount_code']);
        }else sendMessage($mainValues['not_valid_discount_code']);
        setUser();
    }else delMessage();

    $oid = $match[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    $order = $order->fetch_assoc();
    $serverId = $order['server_id'];
    $fid = $order['fileid'];
    $agentBought = $order['agent_bought'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
    $stmt->bind_param("i", $fid);
    $stmt->execute();
    $respd = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $respd['price'];
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$fid]?? $discounts['normal'];
        else $discount = $discounts['servers'][$serverId]?? $discounts['normal'];
        $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
    }
    if(!preg_match('/^discountRenew/', $userInfo['step'])){
        $hash_id = RandomString();
        $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` = 'RENEW_ACCOUNT' AND `state` = 'pending'");
        $stmt->bind_param("i", $from_id);
        $stmt->execute();
        $stmt->close();
        
        // apply user percent discount (from user info panel)
        $price = applyUserPercentDiscount($from_id, $price);

        $time = time();
        $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                    VALUES (?, ?, 'RENEW_ACCOUNT', ?, '0', '0', ?, ?, 'pending')");
        $stmt->bind_param("siiii", $hash_id, $from_id, $oid, $price, $time);
        $stmt->execute();
        $rowId = $stmt->insert_id;
        $stmt->close();
    }else $price = $afterDiscount;

    if($price == 0) $price = "رایگان";
    else $price .= " تومان";
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => "💳 کارت به کارت مبلغ $price",  'callback_data' => "payRenewWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "پرداخت با موجودی مبلغ $price",  'callback_data' => "payRenewWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    if(!preg_match('/^discountRenew/', $userInfo['step'])) $keyboard[] = [['text' => " 🎁 نکنه کد تخفیف داری؟ ",  'callback_data' => "haveDiscountRenew_" . $match[1] . "_" . $rowId]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];



    sendMessage("لطفا با یکی از روش های زیر اکانت خود را تمدید کنید :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/payRenewWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();

    $oid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    
    setUser($data);
    delMessage();

    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");
    exit;
}
if(preg_match('/payRenewWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result()->fetch_assoc();
        $hash_id = $payInfo['hash_id'];
        $stmt->close();
        
        $oid = $payInfo['plan_id'];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $oid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $fid = $order['fileid'];
        $remark = $order['remark'];
        $uid = $order['userid'];
        $userName = $userInfo['username'];
        $uname = $userInfo['name'];
        
        $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ? AND `active` = 1");
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payInfo['price'];
        $volume = $respd['volume'];
        $days = $respd['days'];
        
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
        // notify admin
        
        $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کارت به کارت', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveRenewAcc$hash_id"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decRenewAcc$hash_id"]
                ]
            ]
        ]);
    
        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        setUser();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    $stmt->close();
    
    if($payInfo['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    
    $uid = $payInfo['user_id'];
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


    unset($markup[count($markup)-1]);
    $markup[] = [['text'=>"✅",'callback_data'=>"deltach"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];


    editKeys($keys);

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'days'=>$days, 'volume' => $volume]);
    }else{
        if($inbound_id > 0)
            $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, "renew");
        else
            $response = editInboundTraffic($server_id, $uuid, $volume, $days, "renew");
    }
    
	if(is_null($response)){
		alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
		exit;
	}
	$stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
	$newExpire = $time + $days * 86400;
	$stmt->bind_param("ii", $newExpire, $oid);
	$stmt->execute();
	$stmt->close();
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();
    sendMessage(str_replace(["REMARK", "VOLUME", "DAYS"],[$remark, $volume, $days], $mainValues['renewed_config_to_user']), getMainKeys(),null,null);
    sendMessage("✅سرویس $remark با موفقیت تمدید شد",null,null,$uid);
    exit;
}
if(preg_match('/decRenewAcc(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result()->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    $stmt->close();
    
    $uid = $payInfo['user_id'];
    $oid = $payInfo['plan_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $fid = $order['fileid'];
    $remark = $order['remark'];
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
    $price = $respd['price'];


    unset($markup[count($markup)-1]);
    $markup[] = [['text' => '❌', 'callback_data' => "dontsendanymore"]];
    $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);

    editKeys($keys);
    sendMessage("😖|تمدید سرویس $remark لغو شد",null,null,$uid);
    exit;
}
if(preg_match('/payRenewWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();

    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payInfo = $payInfo->fetch_assoc();
    $hash_id = $payInfo['hash_id'];
    
    if($payInfo['state'] == "paid_with_wallet") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    $oid = $payInfo['plan_id'];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows == 0){
        delMessage();
        sendMessage($mainValues['config_not_found'], getMainKeys());
        exit();
    }
    $order = $order->fetch_assoc();
    
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

    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفا به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }


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
		alert('🔻مشکل فنی در اتصال به سرور. لطفا به مدیریت اطلاع بدید',true);
		exit;
	}
	$stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = ?, `notif` = 0 WHERE `id` = ?");
	$newExpire = $time + $days * 86400;
	$stmt->bind_param("ii", $newExpire, $oid);
	$stmt->execute();
	$stmt->close();
	$stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
	$stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
	$stmt->execute();
	$stmt->close();
	
	$stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
	$stmt->bind_param("ii", $price, $from_id);
	$stmt->execute();
	$stmt->close();
    smartSendOrEdit($message_id, "✅سرویس $remark با موفقیت تمدید شد",getMainKeys());
    $keys = json_encode(['inline_keyboard'=>[
        [
            ['text'=>"به به تمدید 😍",'callback_data'=>"deltach"]
            ],
        ]]);
    $msg = str_replace(['TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK", "VOLUME", "DAYS"],['کیف پول', $from_id, $username, $first_name, $price, $remark, $volume, $days], $mainValues['renew_account_request_message']);

    sendToAdmins($msg, $keys, "html");
    exit;
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است.لطفا ابتدا آن را تمدید کنید",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    smartSendOrEdit($message_id, ' 📍 لطفا برای تغییر لوکیشن سرویس فعلی, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if($data=="giftVolumeAndDay" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1");
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای هدیه دادن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "giftToServer{$sid}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    smartSendOrEdit($message_id, ' 📍 لطفا برای هدیه دادن, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/^giftToServer(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("لطفا مدت زمان هدیه را به روز وارد کنید\nبرای اضافه نشدن زمان 0 را وارد کنید", $cancelKey);
    setUser('giftServerDay' . $match[1]);
}
if(preg_match('/^giftServerDay(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            sendMessage("لطفا حجم هدیه را به مگابایت وارد کنید\nبرای اضافه نشدن حجم 0 را وارد کنید");
            setUser('giftServerVolume' . $match[1] . "_" . $text);
        }else sendMessage("عددی بزرگتر و یا مساوی به 0 واردکنید");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/^giftServerVolume(\d+)_(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    if(is_numeric($text)){
        if($text >= 0){
            $stmt = $connection->prepare("INSERT INTO `gift_list` (`server_id`, `volume`, `day`) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $match[1], $text, $match[2]);
            $stmt->execute();
            $stmt->close();
            
            sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
            sendMessage($mainValues['reached_main_menu'],getMainKeys());

            setUser();
        }else sendMessage("عددی بزرگتر و یا مساوی به 0 واردکنید");
    }else sendMessage($mainValues['send_only_number']);
}
if(preg_match('/switchLocation(.+)_(.+)_(.+)_(.+)/', $data,$match)){
    $order_id = $match[1];
    $server_id = $match[2];
    $leftgp = $match[3];
    $expire = $match[4]; 
    if($expire < time() or $leftgp <= 0) {
        alert("سرویس شما غیرفعال است.لطفا ابتدا آن را تمدید کنید",true);exit;
    }
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active` = 1 and `state` = 1 and ucount > 0 AND `id` != ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $respd = $stmt->get_result();
    $stmt->close();
    if($respd->num_rows == 0){
        alert('در حال حاضر هیچ سرور فعالی برای تغییر لوکیشن وجود ندارد',true);
        exit;
    }
    $keyboard = [];
    while($cat = $respd->fetch_assoc()){
        $sid = $cat['id'];
        $name = $cat['title'];
        $keyboard[] = ['text' => "$name", 'callback_data' => "switchServer{$sid}_{$order_id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "mainMenu"]];
    smartSendOrEdit($message_id, ' 📍 لطفا برای تغییر لوکیشن سرویس فعلی, یکی از سرورها را انتخاب کنید👇',json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/switchServer(.+)_(.+)/',$data,$match)){
    $sid = $match[1];
    $oid = $match[2];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fid = $order['fileid'];
    $protocol = $order['protocol'];
	$link = json_decode($order['link'])[0];
	
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $fid); 
    $stmt->execute();
    $file_detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $flow = $file_detail['flow'] == "None"?"":$file_detail['flow'];
	
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $server_info['reality'];
    $serverType = $server_info['type'];
    $panelUrl = $server_info['panel_url'];

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $srv_remark = $stmt->get_result()->fetch_assoc()['remark'];


    if($botState['remark'] == "digits"){
        $rnd = rand(10000,99999);
        $newRemark = "{$srv_remark}-{$rnd}";
    }else{
        $rnd = rand(1111,99999);
        $newRemark = "{$srv_remark}-{$from_id}-{$rnd}";
    }
	
    if(preg_match('/vmess/',$link)){
        $link_info = json_decode(base64_decode(str_replace('vmess://','',$link)));
        $uniqid = $link_info->id;
        $port = $link_info->port;
        $netType = $link_info->net;
    }else{
        $link_info = parse_url($link);
        $panel_ip = $link_info['host'];
        $uniqid = $link_info['user'];
        $protocol = $link_info['scheme'];
        $port = $link_info['port'];
        $netType = explode('type=',$link_info['query'])[1]; 
        $netType = explode('&',$netType)[0];
    }

    if($inbound_id > 0) {
        $remove_response = deleteClient($server_id, $inbound_id, $uuid);
		if(is_null($remove_response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($remove_response){
            $total = $remove_response['total'];
            $up = $remove_response['up'];
            $down = $remove_response['down'];
			$id_label = $protocol == 'trojan' ? 'password' : 'id';
			if($serverType == "sanaei" || $serverType == "alireza"){
			    if($reality == "true"){
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "flow" => $flow,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];			        
			    }else{
                    $newArr = [
                      "$id_label" => $uniqid,
                      "email" => $newRemark,
                      "enable" => true,
                      "limitIp" => $remove_response['limitIp'],
                      "totalGB" => $total - $up - $down,
                      "expiryTime" => $remove_response['expiryTime'],
                      "subId" => RandomString(16)
                    ];
			    }
			}else{
                $newArr = [
                  "$id_label" => $uniqid,
                  "flow" => $remove_response['flow'],
                  "email" => $newRremark,
                  "limitIp" => $remove_response['limitIp'],
                  "totalGB" => $total - $up - $down,
                  "expiryTime" => $remove_response['expiryTime']
                ];
			}
            
            $response = addInboundAccount($sid, '', $inbound_id, 1, $newRemark, 0, 1, $newArr); 
            if(is_null($response)){
                alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
                exit;
            }
			if($response == "inbound not Found"){
                alert("🔻سطر (inbound) با آیدی $inbound_id در این سرور یافت نشد. لطفا به مدیریت اطلاع بدید",true);
                exit;
            }
			if(!$response->success){
				alert('🔻خطا در ساخت کانفیگ. لطفا به مدیریت اطلاع بدید',true);
				exit;
			}
			$vray_link = getConnectionLink($sid, $uniqid, $protocol, $newRemark, $port, $netType, $inbound_id);
			deleteClient($server_id, $inbound_id, $uuid, 1);
        }
    }else{
        $response = deleteInbound($server_id, $uuid);
		if(is_null($response)){
			alert('🔻اتصال به سرور برقرار نیست. لطفا به مدیریت اطلاع بدید',true);
			exit;
		}
        if($response){
            if($serverType == "marzban"){
                $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                if(!$response->success){
                    if($response->msg == "User already exists"){
                        $newRemark .= rand(1111,99999);
                        $response = addMarzbanUser($server_id, $newRemark, $volume, $days, $fid);
                    }
                }
                $uniqid = $token = str_replace("/sub/", "", $response->sub_link);
                $subLink = $botState['subLinkState'] == "on"?$panelUrl . $response->sub_link:"";
                $vraylink = $response->vray_links;

                $stmt = $connection->prepare("UPDATE `orders_list` SET `token` = ?, `uuid` =? WHERE `id` = ?");
                $stmt->bind_param("ssi", $token, $uniqid, $oid);
                $stmt->execute();
                $stmt->close();

            }else{
                $res = addUser($sid, $response['uniqid'], $response['protocol'], $response['port'], $response['expiryTime'], $newRemark, $response['volume'] / 1073741824, $response['netType'], $response['security']);
                $vray_link = getConnectionLink($sid, $response['uniqid'], $response['protocol'], $newRemark, $response['port'], $response['netType'], $inbound_id);
            }
            deleteInbound($server_id, $uuid, 1);
        }
    }
    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` - 1 WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("UPDATE `orders_list` SET `server_id` = ?, `link`=?, `remark` = ? WHERE `id` = ?");
    $stmt->bind_param("issi", $sid, $vray_link, $newRemark, $oid);
    $stmt->execute();
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
    $stmt->bind_param("i", $sid);
    $stmt->execute();
    $server_title = $stmt->get_result()->fetch_assoc()['title'];
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `status` = 1 ORDER BY `id` DESC");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $orders = $stmt->get_result();
    $stmt->close();
    
    $keyboard = [];
    while($cat = $orders->fetch_assoc()){
        $id = $cat['id'];
        $cremark = $cat['remark'];
        $keyboard[] = ['text' => "$cremark", 'callback_data' => "orderDetails$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]];
    $msg = " 📍لوکیشن سرویس $remark به $server_title با ریمارک $newRemark تغییر یافت.\n لطفا برای مشاهده مشخصات, روی آن بزنید👇";
    
    smartSendOrEdit($message_id, $msg,json_encode([
            'inline_keyboard' => $keyboard
        ]));
    exit();
}
elseif(preg_match('/^deleteMyConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    smartSendOrEdit($message_id, "آیا از حذف کانفیگ $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بلی",'callback_data'=>"yesDeleteConfig" . $match[1]],['text'=>"نخیر",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif($data=="noDontDelete"){
    smartSendOrEdit($message_id, "عملیه مورد نظر لغو شد",json_encode([
        'inline_keyboard' => [
            [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteConfig(\d+)/',$data,$match)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    $fileid = $order['fileid'];
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param("i", $fileid);
    $stmt->execute();
    $planDetail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
	$volume = $planDetail['volume'];
	$days = $planDetail['days'];
	
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];

	
	if($serverType != "marzban"){
        if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
        else $res = deleteInbound($server_id, $uuid, 1);
        
        $leftMb = sumerize($res['total'] - $res['up'] - $res['down']);
        $expiryDay = $res['expiryTime'] != 0?
            floor(
                (substr($res['expiryTime'],0,-3)-time())/(60 * 60 * 24))
                :
                "نامحدود";
	}else{
	    $configInfo = getMarzbanUser($server_id, $remark);
	    deleteMarzban($server_id, $remark);
	    $leftMb = sumerize($configInfo->data_limit - $configInfo->used_traffic);
	    $expiryDay = $configInfo->expire != 0?
	        floor(($configInfo->expire - time())/ 86400):"نامحدود";
	}

    
    if(is_numeric($expiryDay)){
        if($expiryDay<0) $expiryDay = 0;
    }

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    smartSendOrEdit($message_id, "کانفیگ $remark با موفقیت حذف شد",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
sendToAdmins("
🔋|💰 حذف کانفیگ

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
🔋حجم سرویس: $volume گیگ
⏰ مدت زمان سرویس: $days روز
❌ حجم باقی مانده: $leftMb
📆 روز باقیمانده: $expiryDay روز
", null, "html");
    exit();
}
elseif(preg_match('/^delUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $remark = $order['remark'];

    smartSendOrEdit($message_id, "آیا از حذف کانفیگ $remark مطمئن هستید؟",json_encode([
        'inline_keyboard' => [
            [['text'=>"بلی",'callback_data'=>"yesDeleteUserConfig" . $match[1]],['text'=>"نخیر",'callback_data'=>"noDontDelete"]]
            ]
    ]));
}
elseif(preg_match('/^yesDeleteUserConfig(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $oid = $match[1];
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $userId = $order['userid'];
    $inbound_id = $order['inbound_id'];
    $server_id = $order['server_id'];
    $remark = $order['remark'];
    $uuid = $order['uuid']??"0";
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];
    
	
	if($serverType != "marzban"){
        if($inbound_id > 0) $res = deleteClient($server_id, $inbound_id, $uuid, 1);
        else $res = deleteInbound($server_id, $uuid, 1);
	}else{
	    $res = deleteMarzban($server_id, $remark);
	}
    

    $stmt = $connection->prepare("UPDATE `server_info` SET `ucount` = `ucount` + 1 WHERE `id` = ?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $stmt->close();

    $vray_link = json_encode($vray_link);
    $stmt = $connection->prepare("DELETE FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $oid);
    $stmt->execute();
    $stmt->close();

    smartSendOrEdit($message_id, "کانفیگ $remark با موفقیت حذف شد",json_encode([
            'inline_keyboard' => [
                [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]]
                ]
        ]));
        
    exit();
}
if(preg_match('/increaseADay(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_day`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];

    if($res->num_rows == 0){
        alert("در حال حاضر هیچ پلنی برای افزایش مدت زمان سرویس وجود ندارد");
        exit;
    }
    $keyboard = [];
    while ($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) . " تومان";
        $keyboard[] = ['text' => "$title روز $price", 'callback_data' => "selectPlanDayIncrease{$match[1]}_$id"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text' => $buttonValues['back_to_main'], 'callback_data' => "mainMenu"]];
    smartSendOrEdit($message_id, "لطفا یکی از پلن های افزایشی را انتخاب کنید :", json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/selectPlanDayIncrease(?<orderId>.+)_(?<dayId>.+)/',$data,$match)){
    $data = str_replace('selectPlanDayIncrease','',$data);
    $pid = $match['dayId'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $pid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];

        $planprice -= floor($planprice * $discount / 100);
    }
    
    
    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_DAY%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_DAY_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();

    
    $keyboard = array();
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'],  'callback_data' => "payIncreaseDayWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => $buttonValues['pay_with_wallet'],  'callback_data' => "payIncraseDayWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    smartSendOrEdit($message_id, "لطفا با یکی از روش های زیر پرداخت خود را تکمیل کنید :",json_encode(['inline_keyboard' => $keyboard]));
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$data,$match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    delMessage();
    setUser($data);
    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");

    exit;
}
if(preg_match('/payIncreaseDayWithCartToCart(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType,$increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];

        $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
    
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin   
        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'زمان', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);
    
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseDay{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseDay{$match[1]}"]
                ]
            ]
        ]);


        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        setUser();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }else{ 
        sendMessage($mainValues['please_send_only_image']);
    }

}
if(preg_match('/approveIncreaseDay(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];
    
    if($payParam['state'] == "approved") exit();
    
    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();
    


    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType,$increaseInfo);
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

    
    $uid = $payParam['user_id'];
    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];
    
    unset($markup[count($markup)-1]);

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_day'=>$volume]);
    }else{
        if($inbound_id > 0) $response = editClientTraffic($server_id, $inbound_id, $uuid, 0, $volume);
        else $response = editInboundTraffic($server_id, $uuid, 0, $volume);
    }
    
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `expire_date` = `expire_date` + ?, `notif` = 0 WHERE `uuid` = ?");
        $newVolume = $volume * 86400;
        $stmt->bind_param("is", $newVolume, $uuid);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("INSERT INTO `increase_order` VALUES (NULL, ?, ?, ?, ?, ?, ?);");
        $newVolume = $volume * 86400;
        $stmt->bind_param("iiisii", $uid, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
        $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    
        editKeys($keys);
        sendMessage("✅$volume روز به مدت زمان سرویس شما اضافه شد",null,null,$uid);
    }else {
        alert("مشکل فنی در ارتباط با سرور. لطفا سلامت سرور را بررسی کنید",true);
        exit;
    }
}
if(preg_match('/payIncraseDayWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();

    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
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

    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $serverConfig = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverConfig['type'];

    
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i", $planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفا به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }

    
    
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
        $stmt->bind_param("iiisii", $from_id, $server_id, $inbound_id, $remark, $price, $time);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        smartSendOrEdit($message_id, "✅$volume روز به مدت زمان سرویس شما اضافه شد",getMainKeys());
        
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی زمان زد 😁",'callback_data'=>"deltach"]
                ],
            ]]);
        sendToAdmins("
🔋|💰 افزایش زمان با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume روز
💰قیمت: $price تومان
⁮⁮ ⁮⁮
        ", $keys, "html");

        exit;
    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید", true);
        exit;
    }
}
if(preg_match('/^increaseAVolume(.*)/', $data, $match)){
    $stmt = $connection->prepare("SELECT * FROM `increase_plan`");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
    
    if($res->num_rows==0){
        alert("در حال حاضر هیچ پلن حجمی وجود ندارد");
        exit;
    }
    $keyboard = [];
    while($cat = $res->fetch_assoc()){
        $id = $cat['id'];
        $title = $cat['volume'];
        $price = $cat['price'];
        if($agentBought == true){
            $discounts = json_decode($userInfo['discount_percent'],true);
            if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
            else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
            $price -= floor($price * $discount / 100);
                $price = applyUserPercentDiscount($from_id, $price);
        }
        if($price == 0) $price = "رایگان";
        else $price = number_format($price) .  ' تومان';
        
        $keyboard[] = ['text' => "$title گیگ $price", 'callback_data' => "increaseVolumePlan{$match[1]}_{$id}"];
    }
    $keyboard = array_chunk($keyboard,2);
    $keyboard[] = [['text'=>"صفحه ی اصلی 🏘",'callback_data'=>"mainMenu"]];
    $res = smartSendOrEdit($message_id, "لطفا یکی از پلن های حجمی را انتخاب کنید :",json_encode([
            'inline_keyboard' => $keyboard
        ]));
}
if(preg_match('/increaseVolumePlan(?<orderId>.+)_(?<volumeId>.+)/',$data,$match)){
    $data = str_replace('increaseVolumePlan','',$data);
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i", $match['volumeId']);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $planprice = $res['price'];
    $plangb = $res['volume'];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $match['orderId']);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $agentBought = $orderInfo['agent_bought'];
 
    if($agentBought == true){
        $discounts = json_decode($userInfo['discount_percent'],true);
        if($botState['agencyPlanDiscount']=="on") $discount = $discounts['plans'][$orderInfo['fileid']]?? $discounts['normal'];
        else $discount = $discounts['servers'][$orderInfo['server_id']]?? $discounts['normal'];
        
        $planprice -= floor($planprice * $discount / 100);
    }

    $hash_id = RandomString();
    $stmt = $connection->prepare("DELETE FROM `pays` WHERE `user_id` = ? AND `type` LIKE '%INCREASE_VOLUME%' AND `state` = 'pending'");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $stmt->close();
    
    $time = time();
    $stmt = $connection->prepare("INSERT INTO `pays` (`hash_id`, `user_id`, `type`, `plan_id`, `volume`, `day`, `price`, `request_date`, `state`)
                                VALUES (?, ?, ?, '0', '0', '0', ?, ?, 'pending')");
    $type = "INCREASE_VOLUME_$data";
    $stmt->bind_param("sisii", $hash_id, $from_id,$type, $planprice, $time);
    $stmt->execute();
    $stmt->close();
    
    $keyboard = array();
    
    if($planprice == 0) $planprice = ' رایگان';
    else $planprice = " " . number_format($planprice) . " تومان";
    
    
    if($botState['cartToCartState'] == "on") $keyboard[] = [['text' => $buttonValues['cart_to_cart'] . $planprice,  'callback_data' => "payIncreaseWithCartToCart$hash_id"]];
    if($botState['nowPaymentOther'] == "on") $keyboard[] = [['text' => $buttonValues['now_payment_gateway'],  'url' => $botUrl . "pay/?nowpayment&hash_id=" . $hash_id]];
    if($botState['zarinpal'] == "on") $keyboard[] = [['text' => $buttonValues['zarinpal_gateway'],  'url' => $botUrl . "pay/?zarinpal&hash_id=" . $hash_id]];
    if($botState['nextpay'] == "on") $keyboard[] = [['text' => $buttonValues['nextpay_gateway'],  'url' => $botUrl . "pay/?nextpay&hash_id=" . $hash_id]];
    if($botState['weSwapState'] == "on") $keyboard[] = [['text' => $buttonValues['weswap_gateway'],  'callback_data' => "payWithWeSwap" . $hash_id]];
    if($botState['walletState'] == "on") $keyboard[] = [['text' => "💰پرداخت با موجودی  " . $planprice,  'callback_data' => "payIncraseWithWallet$hash_id"]];
    if($botState['tronWallet'] == "on") $keyboard[] = [['text' => $buttonValues['tron_gateway'],  'callback_data' => "payWithTronWallet" . $hash_id]];

    $keyboard[] = [['text'=>$buttonValues['cancel'], 'callback_data'=> "mainMenu"]];
    smartSendOrEdit($message_id, "لطفا با یکی از روش های زیر پرداخت خود را تکمیل کنید :",json_encode(['inline_keyboard' => $keyboard]));
} 
if(preg_match('/payIncreaseWithCartToCart(.*)/',$data, $match)) {
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    setUser($data);
    delMessage();
    
    sendMessage(str_replace(['ACCOUNT-NUMBER', 'HOLDER-NAME'],[$paymentKeys['bankAccount'], $paymentKeys['holderName']], $mainValues['renew_ccount_cart_to_cart']),$cancelKey,"html");
    exit;
}
if(preg_match('/payIncreaseWithCartToCart(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel']){
    if(isset($update->message->photo)){
        $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
        $stmt->bind_param("s", $match[1]);
        $stmt->execute();
        $payInfo = $stmt->get_result();
        $stmt->close();
        
        $payParam = $payInfo->fetch_assoc();
        $payType = $payParam['type'];
    
    
        preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
        $orderId = $increaseInfo[1];
        
        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $orderInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $server_id = $orderInfo['server_id'];
        $inbound_id = $orderInfo['inbound_id'];
        $remark = $orderInfo['remark'];
        
        $planid = $increaseInfo[2];
    
        $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
        $stmt->bind_param("i", $planid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $price = $payParam['price'];
        $volume = $res['volume'];
        $state = str_replace('payIncreaseWithCartToCart','',$userInfo['step']);
        sendMessage($mainValues['renew_order_sent'],$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getMainKeys());
    
        // notify admin

        $msg = str_replace(['INCREASE', 'TYPE', "USER-ID", "USERNAME", "NAME", "PRICE", "REMARK"],[$volume, 'حجم', $from_id, $username, $first_name, $price, $remark], $mainValues['increase_account_request_message']);

         $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => $buttonValues['approve'], 'callback_data' => "approveIncreaseVolume{$match[1]}"],
                    ['text' => $buttonValues['decline'], 'callback_data' => "decIncreaseVolume{$match[1]}"]
                ]
            ]
        ]);

        $res = sendPhotoToAdmins($fileid, $msg, $keyboard, "HTML");
        $msgId = $res->result->message_id;
        setUser();
        
        $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'have_sent', `message_id` = ?, `chat_id` = ? WHERE `hash_id` = ?");
        $stmt->bind_param("iis", $msgId, $admin, $match[1]);
        $stmt->execute();
        $stmt->close();
    }else{
        sendMessage($mainValues['please_send_only_image']);
    }
}
if(preg_match('/approveIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    if($payParam['state'] == "approved") exit();

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'approved' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
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

    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $server_info['type'];

    if($serverType == "marzban"){
        $response = editMarzbanConfig($server_id, ['remark'=>$remark, 'plus_volume'=>$volume]);
    }else{
        if($inbound_id > 0) $response = editClientTraffic($server_id, $inbound_id, $uuid, $volume, 0);
        else $response = editInboundTraffic($server_id, $uuid, $volume, 0);
    }
    
    if($response->success){
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();
        unset($markup[count($markup)-1]);
        $markup[] = [['text' => '✅', 'callback_data' => "dontsendanymore"]];
        $keys = json_encode(['inline_keyboard'=>array_values($markup)],488);
    
        editKeys($keys);
        sendMessage("✅$volume گیگ به حجم سرویس شما اضافه شد",null,null,$uid);
    }else {
        alert("مشکل فنی در ارتباط با سرور. لطفا سلامت سرور را بررسی کنید",true);
        exit;
    }
}
if(preg_match('/decIncreaseVolume(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_plan` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"لغو شد ❌",'callback_data'=>"deltach"]]
		    ]]));
    
    sendMessage("افزایش حجم $volume گیگ اشتراک $remark لغو شد",null,null,$uid);
}
if(preg_match('/decIncreaseDay(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];


    preg_match('/^INCREASE_DAY_(\d+)_(\d+)/',$payType, $increaseInfo);
    $orderId = $increaseInfo[1];
    
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id` = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $orderInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $server_id = $orderInfo['server_id'];
    $inbound_id = $orderInfo['inbound_id'];
    $remark = $orderInfo['remark'];
    
    $planid = $increaseInfo[2];


    $uid = $payParam['user_id'];
    $stmt = $connection->prepare("SELECT * FROM `increase_day` WHERE `id` = ?");
    $stmt->bind_param("i",$planid);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $price = $res['price'];
    $volume = $res['volume'];

    $acctxt = '';
    editKeys(json_encode(['inline_keyboard'=>[
		    [['text'=>"لغو شد ❌",'callback_data'=>"deltach"]]
		    ]]));
    
    sendMessage("افزایش زمان $volume روز اشتراک $remark لغو شد",null,null,$uid);
}
if(preg_match('/payIncraseWithWallet(.*)/', $data,$match)){
    $stmt = $connection->prepare("SELECT * FROM `pays` WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $payInfo = $stmt->get_result();
    $stmt->close();
    
    if($payInfo->num_rows == 0){
        $text = "/start";
        $data = "";
        delMessage();
        goto GOTOSTART;
    }

    $payParam = $payInfo->fetch_assoc();
    $payType = $payParam['type'];

    $stmt = $connection->prepare("UPDATE `pays` SET `state` = 'paid_with_wallet' WHERE `hash_id` = ?");
    $stmt->bind_param("s", $match[1]);
    $stmt->execute();
    $stmt->close();


    preg_match('/^INCREASE_VOLUME_(\d+)_(\d+)/',$payType, $increaseInfo);
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
    $price = $payParam['price'];
    $volume = $res['volume'];
    
    $userwallet = $userInfo['wallet'];

    if($userwallet < $price) {
        $needamount = $price - $userwallet;
        alert("💡موجودی کیف پول (".number_format($userwallet)." تومان) کافی نیست لطفا به مقدار ".number_format($needamount)." تومان شارژ کنید ",true);
        exit;
    }
    
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
        $stmt = $connection->prepare("UPDATE `users` SET `wallet` = `wallet` - ? WHERE `userid` = ?");
        $stmt->bind_param("ii", $price, $from_id);
        $stmt->execute();
        $stmt->close();
        $stmt = $connection->prepare("UPDATE `orders_list` SET `notif` = 0 WHERE `uuid` = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();
        $stmt->close();
        $keys = json_encode(['inline_keyboard'=>[
            [
                ['text'=>"اخیش یکی حجم زد 😁",'callback_data'=>"deltach"]
                ],
            ]]);
        sendToAdmins("
🔋|💰 افزایش حجم با ( کیف پول )

▫️آیدی کاربر: $from_id
👨‍💼اسم کاربر: $first_name
⚡️ نام کاربری: $username
🎈 نام سرویس: $remark
⏰ مدت افزایش: $volume گیگ
💰قیمت: $price تومان
⁮⁮ ⁮⁮
        ", $keys, "html");
        smartSendOrEdit($message_id, "✅$volume گیگ به حجم سرویس شما اضافه شد",getMainKeys());exit;
        

    }else {
        alert("به دلیل مشکل فنی امکان افزایش حجم نیست. لطفا به مدیریت اطلاع بدید یا 5دقیقه دیگر دوباره تست کنید",true);
        exit;
    }
}
if($data == 'cantEditTrojan'){
    alert("پروتکل تروجان فقط نوع شبکه TCP را دارد");
    exit;
}
if(($data=='categoriesSetting' || preg_match('/^nextCategoryPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getCategoriesKeys($match[1]);
    else $keys = getCategoriesKeys();
    
    smartSendOrEdit($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if($data=='addNewCategory' and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    setUser($data);
    delMessage();
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();


    $sql = "INSERT INTO `server_categories` VALUES (NULL, 0, '', 0,2,0);";
    $stmt = $connection->prepare($sql);
    $stmt->execute();
    $stmt->close();


    $msg = '▪️یه اسم برای دسته بندی وارد کن:';
    sendMessage($msg,$cancelKey);
    exit;
}
if(preg_match('/^addNewCategory/',$userInfo['step']) and $text!=$buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $step = checkStep('server_categories');
    if($step==2 and $text!=$buttonValues['cancel'] ){
        
        $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=?,`step`=4,`active`=1 WHERE `active`=0");
        $stmt->bind_param("s", $text);
        $stmt->execute();
        $stmt->close();


        $msg = 'یه دسته بندی جدید برات ثبت کردم 🙂☑️';
        sendMessage($msg,$removeKeyboard);
        sendMessage($mainValues['reached_main_menu'],getCategoriesKeys());
    }
}
if(preg_match('/^deltacategorydelete(\d+)_(\d+)/',$data, $match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `server_categories` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("دسته بندی رو برات حذفش کردم ☹️☑️");
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0");
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();

    $keys = getCategoriesKeys($match[2]);
    smartSendOrEdit($message_id,"☑️ مدیریت دسته ها:", $keys);
}
if(preg_match('/^deltacategoryedit/',$data) and ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    setUser($data);
    delMessage();
    sendMessage("〽️ یه اسم جدید برا دسته بندی انتخاب کن:",$cancelKey);exit;
}
if(preg_match('/deltacategoryedit(\d+)_(\d+)/',$userInfo['step'], $match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_categories` SET `title`=? WHERE `id`=?");
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();

    sendMessage("با موفقیت برات تغییر دادم ☺️☑️");
    setUser();
    
    sendMessage("☑️ مدیریت دسته ها:", getCategoriesKeys($match[2]));
}
if(($data=='serversSetting' || preg_match('/^nextServerPage(\d+)/',$data,$match)) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(isset($match[1])) $keys = getServerListKeys($match[1]);
    else $keys = getServerListKeys();
    
    smartSendOrEdit($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^toggleServerState(\d+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_info` SET `state` = IF(`state` = 0,1,0) WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    alert("وضعیت سرور با موفقیت تغییر کرد");
    
    $keys = getServerListKeys($match[2]);
    smartSendOrEdit($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^showServerSettings(\d+)_(\d+)/',$data,$match) and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $keys = getServerConfigKeys($match[1], $match[2]);
    smartSendOrEdit($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
}
if(preg_match('/^changesServerIp(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $serverIp= $stmt->get_result()->fetch_assoc()['ip']??"اطلاعاتی یافت نشد";
    $stmt->close();
    
    delMessage();
    sendMessage("لیست آیپی های فعلی: \n$serverIp\nلطفا آیپی های جدید را در خط های جدا بفرستید\n\nبرای خالی کردن متن /empty را وارد کنید",$cancelKey,null,null,null);
    setUser($data);
    exit();
}
if(preg_match('/^changesServerIp(\d+)/',$userInfo['step'],$match) && ($from_id == $admin || $userInfo['isAdmin'] == true) && $text != $buttonValues['cancel']){
    $stmt = $connection->prepare("UPDATE `server_config` SET `ip` = ? WHERE `id`=?");
    if($text == "/empty") $text = "";
    $stmt->bind_param("si", $text, $match[1]);
    $stmt->execute();
    $stmt->close();
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[1]);
    sendMessage("☑️ مدیریت سرور ها: $cname",$keys);
    exit();
}
if(preg_match('/^changePortType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `port_type` = IF(`port_type` = 'auto', 'random', 'auto') WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    alert("نوعیت پورت سرور مورد نظر با موفقیت تغییر کرد");
    
    $keys = getServerConfigKeys($match[1]);
    smartSendOrEdit($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeRealityState(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("UPDATE `server_config` SET `reality` = IF(`reality` = 'true', 'false', 'true') WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[1]);
    smartSendOrEdit($message_id,"☑️ مدیریت سرور ها: $cname",$keys);
    
    exit();
}
if(preg_match('/^changeServerType(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"
    
🔰 نکته مهم: ( پنل x-ui خود را به آخرین نسخه آپدیت کنید ) 

❤️ اگر از پنل سنایی استفاده میکنید لطفا نوع پنل را ( سنایی ) انتخاب کنید
🧡 اگر از پنل علیرضا استفاده میکنید لطفا نوع پنل را ( علیرضا ) انتخاب کنید
💚 اگر از پنل نیدوکا استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید 
💙 اگر از پنل چینی استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه !
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
",json_encode(['inline_keyboard'=>[
        [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $match[1]],['text'=>"سنایی",'callback_data'=>"chhangeServerTypesanaei_" . $match[1]]],
        [['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $match[1]]]
        ]]));
    exit();
}
if(preg_match('/^chhangeServerType(\w+)_(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    alert($mainValues['saved_successfuly']);
    $stmt = $connection->prepare("UPDATE `server_config` SET `type` = ? WHERE `id`=?");
    $stmt->bind_param("si",$match[1], $match[2]);
    $stmt->execute();
    $stmt->close();
    
    $keys = getServerConfigKeys($match[2]);
    smartSendOrEdit($message_id, "☑️ مدیریت سرور ها: $cname",$keys);
}
if(($data == "addNewMarzbanPanel" || $data=='addNewServer') and ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    setUser($data, 'temp');
    setUser('addserverName');
    sendMessage("مرحله اول: 
▪️یه اسم برا سرورت انتخاب کن:",$cancelKey);
    exit();
}
if($userInfo['step'] == 'addserverName' and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
	sendMessage('مرحله دوم: 
▪️ظرفیت تعداد ساخت کانفیگ رو برای سرورت مشخص کن ( عدد باشه )');
    $data = array();
    $data['title'] = $text;

    setUser('addServerUCount' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerUCount(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['ucount'] = $text;

    sendMessage("مرحله سوم: 
▪️یه اسم ( ریمارک ) برا کانفیگ انتخاب کن:
 ( به صورت انگیلیسی و بدون فاصله )
");
    setUser('addServerRemark' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerRemark(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1], true);
    $data['remark'] = $text;

    sendMessage("مرحله چهارم:
▪️لطفا یه ( ایموجی پرچم 🇮🇷 ) برا سرورت انتخاب کن:");
    setUser('addServerFlag' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerFlag(.*)/',$userInfo['step'], $match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['flag'] = $text;
    sendMessage("مرحله پنجم:

▪️لطفا آدرس پنل x-ui رو به صورت مثال زیر وارد کن:

❕https://yourdomain.com:54321
❕https://yourdomain.com:54321/path
❗️http://125.12.12.36:54321
❗️http://125.12.12.36:54321/path

اگر سرور مورد نظر با دامنه و ssl هست از مثال ( ❕) استفاده کنید
اگر سرور مورد نظر با ip و بدون ssl هست از مثال ( ❗️) استفاده کنید
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
");
    setUser('addServerPanelUrl' . json_encode($data,JSON_UNESCAPED_UNICODE));
    exit();
}
if(preg_match('/^addServerPanelUrl(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_url'] = $text;
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $data['panel_ip'] = "/empty";
        $data['sni'] = "/empty";
        $data['header_type'] = "/empty";
        $data['response_header'] = "/empty";
        $data['request_header'] = "/empty";
        $data['security'] = "/empty";
        $data['tls_setting'] = "/empty";
        
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "مرحله ششم: 
    ▪️لطفا یوزر پنل را وارد کنید:");
    
        exit();
    }else{
        setUser('addServerIp' . json_encode($data,JSON_UNESCAPED_UNICODE));
        sendMessage( "🔅 لطفا ip یا دامنه تانل شده پنل را وارد کنید:
    
    نمونه: 
    91.257.142.14
    sub.domain.com
    ❗️در صورتی که میخواید چند دامنه یا ip کانفیگ بگیرید باید زیر هم بنویسید و برای ربات بفرستین:
        \n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
        exit();
    }
}
if(preg_match('/^addServerIp(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_ip'] = $text;
    setUser('addServerSni' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا sni پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerSni(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['sni'] = $text;
    setUser('addServerHeaderType' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 اگر  از header type استفاده میکنید لطفا http را تایپ کنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerHeaderType(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['header_type'] = $text;
    setUser('addServerRequestHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅اگر از هدر استفاده میکنید لطفا آدرس رو به این صورت Host:test.com وارد کنید و به جای test.com آدرس دلخواه بزنید:\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerRequestHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['request_header'] = $text;
    setUser('addServerResponseHeader' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا response header پنل را وارد کنید\n\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
    exit();
}
if(preg_match('/^addServerResponseHeader(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['response_header'] = $text;
    setUser('addServerSecurity' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "🔅 لطفا security پنل را وارد کنید

⚠️ توجه: برای استفاده از tls یا xtls لطفا کلمه tls یا xtls رو تایپ کنید در غیر این صورت 👇
\n🔻برای خالی گذاشتن متن /empty را وارد کنید");
exit();
}
if(preg_match('/^addServerSecurity(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['security'] = $text;
    setUser('addServerTlsSetting' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage("
    🔅 لطفا tls|xtls setting پنل را وارد کنید🔻برای خالی گذاشتن متن /empty را وارد کنید 

⚠️ لطفا تنظیمات سرتیفیکیت رو با دقت انجام بدید مثال:
▫️serverName: yourdomain
▫️certificateFile: /root/cert.crt
▫️keyFile: /root/private.key
\n
"
        .'<b>tls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}]}</code>' . "\n"
        .'<b>xtls setting:</b> <code>{"serverName": "","certificates": [{"certificateFile": "","keyFile": ""}],"alpn": []}</code>', null, "HTML");

    exit();
}
if(preg_match('/^addServerTlsSetting(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['tls_setting'] = $text;
    setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله ششم: 
▪️لطفا یوزر پنل را وارد کنید:");

    exit();
}
if(preg_match('/^addServerPanelUser(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('addServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "مرحله هفتم: 
▪️لطفا پسورد پنل را وارد کنید:");
exit();
}
if(preg_match('/^addServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
    $data = json_decode($match[1],true);
    $title = $data['title'];
    $ucount = $data['ucount'];
    $remark = $data['remark'];
    $flag = $data['flag'];

    $panel_url = $data['panel_url'];
    $ip = $data['panel_ip']!="/empty"?$data['panel_ip']:"";
    $sni = $data['sni']!="/empty"?$data['sni']:"";
    $header_type = $data['header_type']!="/empty"?$data['header_type']:"none";
    $request_header = $data['request_header']!="/empty"?$data['request_header']:"";
    $response_header = $data['response_header']!="/empty"?$data['response_header']:"";
    $security = $data['security']!="/empty"?$data['security']:"none";
    $tlsSettings = $data['tls_setting']!="/empty"?$data['tls_setting']:"";
    $serverName = $data['panel_user'];
    $serverPass = $text;
    
    
    $loginResponse['success'] = false;
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
        );
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'accept: application/json'
            ));
        $response = json_decode(curl_exec($curl),true);
        
        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);
    
        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
        
    }
    if(!$loginResponse['success']){
        setUser('addServerPanelUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
        sendMessage( "
⚠️ با خطا مواجه شدی ! 

برای رفع این مشکل روی لینک زیر بزن و ویس رو با دقت گوش کن 👇

⛔️🔗 https://t.me/deltach/186

مجدد نام کاربری پنل را وارد کنید:
⁮⁮ ⁮⁮
        ");
        exit();
    }
    $stmt = $connection->prepare("INSERT INTO `server_info` (`title`, `ucount`, `remark`, `flag`, `active`)
                                                    VALUES (?,?,?,?,1)");
    $stmt->bind_param("siss", $title, $ucount, $remark, $flag);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    $stmt = $connection->prepare("INSERT INTO `server_config` (`id`, `panel_url`, `ip`, `sni`, `header_type`, `request_header`, `response_header`, `security`, `tlsSettings`, `username`, `password`)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssssssss", $rowId, $panel_url, $ip, $sni, $header_type, $request_header, $response_header, $security, $tlsSettings, $serverName, $serverPass);
    $stmt->execute();
    $rowId = $stmt->insert_id;
    $stmt->close();

    sendMessage(" تبریک ; سرورت رو ثبت کردی 🥹",$removeKeyboard);
    if($userInfo['temp'] == "addNewMarzbanPanel"){
        $stmt = $connection->prepare("UPDATE `server_config` SET `type` = 'marzban' WHERE `id`=?");
        $stmt->bind_param("i",$rowId);
        $stmt->execute();
        $stmt->close();
        
        $keys = getServerListKeys();
        sendMessage("☑️ مدیریت سرور ها",$keys);
    }else{
        sendMessage("
    
🔰 نکته مهم: ( پنل x-ui خود را به آخرین نسخه آپدیت کنید ) 

❤️ اگر از پنل سنایی استفاده میکنید لطفا نوع پنل را ( سنایی ) انتخاب کنید
🧡 اگر از پنل علیرضا استفاده میکنید لطفا نوع پنل را ( علیرضا ) انتخاب کنید
💚 اگر از پنل نیدوکا استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید 
💙 اگر از پنل چینی استفاده میکنید لطفا نوع پنل را ( ساده ) انتخاب کنید 
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
📣 حتما نوع پنل را انتخاب کنید وگرنه براتون مشکل ساز میشه !
⁮⁮ ⁮⁮ ⁮⁮ ⁮⁮
    ",json_encode(['inline_keyboard'=>[
            [['text'=>"ساده",'callback_data'=>"chhangeServerTypenormal_" . $rowId],['text'=>"سنایی",'callback_data'=>"chhangeServerTypesanaei_" . $rowId]],
            [['text'=>"علیرضا",'callback_data'=>"chhangeServerTypealireza_" . $rowId]]
            ]]));
    }
    setUser();
    exit();
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    delMessage();
    setUser($data);
    sendMessage( "▪️لطفا آدرس پنل را وارد کنید:",$cancelKey);
}
if(preg_match('/^changesServerLoginInfo(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = array();
    $data['rowId'] = $match[1];
    $data['panel_url'] = $text;
    setUser('editServerPaneUser' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️لطفا یوزر پنل را وارد کنید:",$cancelKey);
    exit();
}
if(preg_match('/^editServerPaneUser(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)) {
    $data = json_decode($match[1],true);
    $data['panel_user'] = $text;
    setUser('editServerPanePassword' . json_encode($data, JSON_UNESCAPED_UNICODE));
    sendMessage( "▪️لطفا پسورد پنل را وارد کنید:");
    exit();
}
if(preg_match('/^editServerPanePassword(.*)/',$userInfo['step'],$match) and $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("⏳ در حال ورود به اکانت ...");
    $data = json_decode($match[1],true);

    $rowId = $data['rowId'];
    $panel_url = $data['panel_url'];
    $serverName = $data['panel_user'];
    $serverPass = $text;
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $serverInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $serverType = $serverInfo['type'];
    $loginResponse['success'] = false;
    
    if($serverType == "marzban"){
        $loginUrl = $panel_url .'/api/admin/token';
        $postFields = array(
            'username' => $serverName,
            'password' => $serverPass
        );
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/x-www-form-urlencoded',
                'accept: application/json'
            ));
        $response = json_decode(curl_exec($curl),true);
        
        if(curl_error($curl)){
            $loginResponse = ['success' => false, 'error'=>curl_error($curl)];
        }
        curl_close($curl);
    
        if(isset($response['access_token'])){
            $loginResponse['success'] = true;
        }
    }else{
        $loginUrl = $panel_url . '/login';
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $loginUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); 
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        $loginResponse = json_decode(curl_exec($ch),true);
        curl_close($ch);
    }
    
    if(!$loginResponse['success']) sendMessage( "اطلاعاتی که وارد کردی اشتباهه 😂");
    else{
        $stmt = $connection->prepare("UPDATE `server_config` SET `panel_url` = ?, `username` = ?, `password` = ? WHERE `id` = ?");
        $stmt->bind_param("sssi", $panel_url, $serverName, $serverPass, $rowId);
        $stmt->execute();
        $stmt->close();
        
        sendMessage("اطلاعات ورود سرور با موفقیت عوض شد",$removeKeyboard);
    }
    $keys = getServerConfigKeys($rowId);
    sendMessage('☑️ مدیریت سرور ها:',$keys);
    setUser();
}
if(preg_match('/^deltadeleteserver(\d+)/',$data,$match) and ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    smartSendOrEdit($message_id,"از حذف سرور مطمئنی؟",json_encode(['inline_keyboard'=>[
        [['text'=>"بله",'callback_data'=>"yesDeleteServer" . $match[1]],['text'=>"نخير",'callback_data'=>"showServerSettings" . $match[1] . "_0"]]
        ]]));
}
if(preg_match('/^yesDeleteServer(\d+)/',$data,$match) && ($from_id == $admin || ($userInfo['isAdmin'] == true && $permissions['servers']))){
    $stmt = $connection->prepare("DELETE FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $connection->prepare("DELETE FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();

    alert("🙂 سرور رو چرا حذف کردی اخه ...");
    

    $keys = getServerListKeys();
    if($keys == null) smartSendOrEdit($message_id,"موردی یافت نشد");
    else smartSendOrEdit($message_id,"☑️ مدیریت سرور ها:",$keys);
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break; 
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم"; 
            break;
        default:
            $txt = str_replace("_", " ", $match[1]);
            $end = "برای خالی کردن متن /empty را وارد کنید";
            break;
    }
    delMessage();
    sendMessage("🔘|لطفا " . $txt . " جدید را وارد کنید" . $end,$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $sql = "UPDATE `server_info` SET `title`";
            break;
        case "Flag":
            $sql = "UPDATE `server_info` SET `flag`";
            break;
        case "Remark":
            $sql = "UPDATE `server_info` SET `remark`";
            break;
        case "Max":
            $sql = "UPDATE `server_info` SET `ucount`";
            break;
    }
    
    if($text == "/empty"){
        $stmt = $connection->prepare("$sql IS NULL WHERE `id`=?");
        $stmt->bind_param("i", $match[2]);
        $stmt->execute();
        $stmt->close();
    }else{
        $stmt = $connection->prepare("$sql=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
        $stmt->execute();
        $stmt->close();
    }
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $txt = str_replace("_", " ", $match[1]);
    delMessage();
    sendMessage("🔘|لطفا " . $txt . " جدید را وارد کنید\nبرای خالی کردن متن /empty را وارد کنید",$cancelKey);
    setUser($data);
    exit();
}
if(preg_match('/^editsServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if($text == "/empty"){
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni` = '' WHERE `id`=?");
        elseif($match[1] == "header_type") $stmt = $connection->prepare("UPDATE `server_config` SET `header_type` = 'none' WHERE `id`=?");
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header` = '' WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header` = '' WHERE `id`=?");
        elseif($match[1] == "security") $stmt = $connection->prepare("UPDATE `server_config` SET `security` = 'none' WHERE `id`=?");
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings` = '' WHERE `id`=?");

        $stmt->bind_param("i", $match[2]);
    }else{
        if($match[1] == "sni") $stmt = $connection->prepare("UPDATE `server_config` SET `sni`=? WHERE `id`=?");
        elseif($match[1] == "header_type"){
            if($text != "http" && $text != "none"){
                sendMessage("برای نوع header type فقط none و یا http مجاز است");
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `header_type`=? WHERE `id`=?");
        }
        elseif($match[1] == "request_header") $stmt = $connection->prepare("UPDATE `server_config` SET `request_header`=? WHERE `id`=?");
        elseif($match[1] == "response_header") $stmt = $connection->prepare("UPDATE `server_config` SET `response_header`=? WHERE `id`=?");
        elseif($match[1] == "security"){
            if($text != "tls" && $text != "none" && $text != "xtls"){
                sendMessage("برای نوع security فقط tls یا xtls و یا هم none مجاز است");
                exit();
            }else $stmt = $connection->prepare("UPDATE `server_config` SET `security`=? WHERE `id`=?");
        }
        elseif($match[1] == "tlsSettings") $stmt = $connection->prepare("UPDATE `server_config` SET `tlsSettings`=? WHERE `id`=?");
        $stmt->bind_param("si",$text, $match[2]);
    }
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
    exit();
}
if(preg_match('/^editServer(\D+)(\d+)/',$data,$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $txt ="اسم";
            break;
        case "Max":
            $txt = "ظرفیت";
            break;
        case "Remark":
            $txt ="ریمارک";
            break;
        case "Flag":
            $txt = "پرچم";
            break;
    }
    delMessage();
    sendMessage("🔘|لطفا " . $txt . " جدید را وارد کنید",$cancelKey);
    setUser($data);
}
if(preg_match('/^editServer(\D+)(\d+)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    switch($match[1]){
        case "Name":
            $stmt = $connection->prepare("UPDATE `server_info` SET `title`=? WHERE `id`=?");
            break;
        case "Max":
            $stmt = $connection->prepare("UPDATE `server_info` SET `ucount`=? WHERE `id`=?");
            break;
        case "Remark":
            $stmt = $connection->prepare("UPDATE `server_info` SET `remark`=? WHERE `id`=?");
            break;
        case "Flag":
            $stmt = $connection->prepare("UPDATE `server_info` SET `flag`=? WHERE `id`=?");
            break;
    }
    
    $stmt->bind_param("si",$text, $match[2]);
    $stmt->execute();
    $stmt->close();
    
    sendMessage($mainValues['saved_successfuly'],$removeKeyboard);
    setUser();
    
    $keys = getServerConfigKeys($match[2]);
    sendMessage("مدیریت سرور $cname",$keys);
}
if($data=="discount_codes" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id,"مدیریت کد های تخفیف",getDiscountCodeKeys());
}
if($data=="addDiscountCode" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    delMessage();
    sendMessage("🔘|لطفا مقدار تخفیف را وارد کنید\nبرای درصد علامت % را در کنار عدد وارد کنید در غیر آن مقدار تخفیف به تومان محاسبه میشود",$cancelKey);
    setUser($data);
}
if($userInfo['step'] == "addDiscountCode" && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $dInfo = array();
    $dInfo['type'] = 'amount';
    if(strstr($text, "%")) $dInfo['type'] = 'percent';
    $text = trim(str_replace("%", "", $text));
    if(is_numeric($text)){
        $dInfo['amount'] = $text;
        setUser("addDiscountDate" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|لطفا مدت زمان این تخفیف را به روز وارد کنید\nبرای نامحدود بودن 0 وارد کنید");
    }else sendMessage("🔘|لطفا فقط عدد و یا درصد بفرستید");
}
if(preg_match('/^addDiscountDate(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){
        $dInfo = json_decode($match[1],true);
        $dInfo['date'] = $text != 0?time() + ($text * 24 * 60 * 60):0;
        
        setUser("addDiscountCount" . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("🔘|لطفا تعداد استفاده این تخفیف را وارد کنید\nبرای نامحدود بودن 0 وارد کنید");
    }else sendMessage("🔘|لطفا فقط عدد بفرستید");
}
if(preg_match('/^addDiscountCount(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['count'] = $text>0?$text:-1;
        
        setUser('addDiscountCanUse' . json_encode($dInfo,JSON_UNESCAPED_UNICODE));
        sendMessage("لطفا تعداد استفاده هر یوزر را وارد کنید");
    }else sendMessage("🔘|لطفا فقط عدد بفرستید");
}
if(preg_match('/^addDiscountCanUse(.*)/',$userInfo['step'],$match) && $text != $buttonValues['cancel'] && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    if(is_numeric($text)){ 
        $dInfo = json_decode($match[1],true);
        $dInfo['can_use'] = $text>0?$text:-1;
         
        $hashId = RandomString();
        
        $stmt = $connection->prepare("INSERT INTO `discounts` (`hash_id`, `type`, `amount`, `expire_date`, `expire_count`, `can_use`)
                                        VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("ssiiii", $hashId, $dInfo['type'], $dInfo['amount'], $dInfo['date'], $dInfo['count'], $dInfo['can_use']);
        $stmt->execute();
        $stmt->close();
        sendMessage("کد تخفیف جدید (<code>$hashId</code>) با موفقیت ساخته شد",$removeKeyboard,"HTML");
        setUser();
        sendMessage("مدیریت کد های تخفیف",getDiscountCodeKeys());
    }else sendMessage("🔘|لطفا فقط عدد بفرستید");
}
if(preg_match('/^delDiscount(\d+)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $stmt = $connection->prepare("DELETE FROM `discounts` WHERE `id` = ?");
    $stmt->bind_param("i", $match[1]);
    $stmt->execute();
    $stmt->close();
    
    alert("کد تخفیف مورد نظر با موفقیت حذف شد");
    smartSendOrEdit($message_id,"مدیریت کد های تخفیف",getDiscountCodeKeys());
}
if(preg_match('/^copyHash(.*)/',$data,$match) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    sendMessage("<code>" . $match[1] . "</code>",null,"HTML");
}
if($data == "managePanel" and (($from_id == $admin || $userInfo['isAdmin'] == true))){
    
    setUser();
    $msg = "
👤 عزیزم به بخش مدیریت خوشومدی 
🤌 هرچی نیاز داشتی میتونی اینجا طبق نیازهات اضافه و تغییر بدی ، عزیزم $first_name جان اگه از فروش ربات درآمد داری از من حمایت کن تا پروژه همیشه آپدیت بمونه !

🆔 @deltach

🚪 /start
";
    smartSendOrEdit($message_id, $msg, getAdminKeys());
}

if($data == "managePanels" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id, "🧩 مدیریت پنل‌ها", getPanelManagementKeys());
}
if($data == "generalSettings" && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    smartSendOrEdit($message_id, "⚙️ تنظیمات عمومی", getGeneralSettingsKeys());
}
if($data == 'reciveApplications') {
    $stmt = $connection->prepare("SELECT * FROM `needed_sofwares` WHERE `status`=1");
    $stmt->execute();
    $respd= $stmt->get_result();
    $stmt->close();

    $keyboard = []; 
    while($file =  $respd->fetch_assoc()){ 
        $link = $file['link'];
        $title = $file['title'];
        $keyboard[] = ['text' => "$title", 'url' => $link];
    }
    $keyboard[] = ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"];
    $keyboard = array_chunk($keyboard,1); 
    smartSendOrEdit($message_id, "
🔸می توانید به راحتی همه فایل ها را (به صورت رایگان) دریافت کنید
📌 شما میتوانید برای راهنمای اتصال به سرویس کانال رسمی مارا دنبال کنید و همچنین از دکمه های زیر میتوانید برنامه های مورد نیاز هر سیستم عامل را دانلود کنید

✅ پیشنهاد ما برنامه V2rayng است زیرا کار با آن ساده است و برای تمام سیستم عامل ها قابل اجرا است، میتوانید به بخش سیستم عامل مورد نظر مراجعه کنید و لینک دانلود را دریافت کنید
", json_encode(['inline_keyboard'=>$keyboard]));
}
if ($text == $buttonValues['cancel']) {
    setUser();
    $stmt = $connection->prepare("DELETE FROM `server_plans` WHERE `active`=0");
    $stmt->execute();
    $stmt->close();

    sendMessage($mainValues['waiting_message'], $removeKeyboard);
    sendMessage($mainValues['reached_main_menu'],getMainKeys());
}
?>