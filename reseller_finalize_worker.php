<?php
// Background worker: finalize reseller bot (setWebhook) + send creation report to admins
// Usage: php reseller_finalize_worker.php <RID>

@set_time_limit(0);
@ini_set('max_execution_time', '0');

chdir(__DIR__);
require_once __DIR__ . "/config.php";

ensureResellerTables();

$rid = isset($argv[1]) ? (int)$argv[1] : 0;
if($rid <= 0){ 
// 3) Notify creator that finalization is complete (best-effort)
try{
    if($creatorId > 0){
        @bot("sendMessage", [
            "chat_id" => (int)$creatorId,
            "text" => "✅ نهایی‌سازی ربات نمایندگی انجام شد.\n\nیوزرنیم ربات: {$uname}\nRID: {$rid}",
        ]);
    }
}catch(Exception $e){
    // ignore
}

exit; }

$rowRes = $connection->query("SELECT `id`,`bot_token`,`bot_username`,`db_name`,`owner_userid`,`admin_userid`,`expires_at` FROM `reseller_bots` WHERE `id`={$rid} LIMIT 1");
$row = $rowRes ? $rowRes->fetch_assoc() : null;
if(!is_array($row) || empty($row['bot_token'])){ exit; }

$hookUrl = $botUrl . "bot.php?bid=" . $rid;

// 1) setWebhook for child bot (timeout-protected in botWithToken)
try{
    @botWithToken($row['bot_token'], "setWebhook", ['url'=>$hookUrl]);
}catch(Exception $e){
    // ignore
}

// 2) Send report to admins (using mother bot token)
$uname = !empty($row['bot_username']) ? '@'.$row['bot_username'] : '---';
$expAt = (int)($row['expires_at'] ?? 0);
$exp = $expAt > 0 ? jdate('Y/m/d H:i', $expAt) : '---';
$dbn = !empty($row['db_name']) ? $row['db_name'] : '---';
$creatorId = (int)($row['owner_userid'] ?? 0);
$adminUserId = (int)($row['admin_userid'] ?? 0);

$reportTxt = "📌 گزارش ساخت ربات نمایندگی\n\n"
    ."شناسه ربات (RID): {$rid}\n"
    ."یوزرنیم ربات: {$uname}\n"
    ."نام دیتابیس: {$dbn}\n"
    ."آیدی عددی سازنده: {$creatorId}\n"
    ."آیدی عددی ادمین ربات: {$adminUserId}\n"
    ."تاریخ انقضا: {$exp}";

$adminIds = getAllAdminIds();
foreach($adminIds as $aidReport){
    // به سازنده هم گزارش دوباره نده
    if((int)$aidReport === (int)$creatorId) continue;
    @bot("sendMessage", [
        "chat_id" => (int)$aidReport,
        "text" => $reportTxt,
        "parse_mode" => "Markdown"
    ]);
}

exit;
