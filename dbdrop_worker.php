<?php
// DeltaBot DB Drop Worker (async)
// Usage:
//   php dbdrop_worker.php drop <BOT_TOKEN> <CHAT_ID> <MESSAGE_ID> <DB_NAME>
//
// Runs in background (nohup) so the main webhook won't hang.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only");
}

$mode = $argv[1] ?? '';
$token = $argv[2] ?? '';
$chatId = $argv[3] ?? '';
$messageId = $argv[4] ?? '';
$dbn = $argv[5] ?? '';

if (!$mode || !$token || !$chatId || !$messageId || !$dbn) {
    fwrite(STDERR, "Invalid args\n");
    exit(1);
}

require_once __DIR__ . '/config.php';

// override token for this worker run
$GLOBALS['botToken'] = $token;
$botToken = $token;

function tg($method, $data){
    global $botToken;
    $url = "https://api.telegram.org/bot{$botToken}/{$method}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function editMsg($chatId, $messageId, $text, $parseMode = 'Markdown', $replyMarkup = null){
    $payload = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true,
    ];
    if($replyMarkup !== null){
        $payload['reply_markup'] = is_string($replyMarkup) ? $replyMarkup : json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
    }
    return tg('editMessageText', $payload);
}

function sendMsg($chatId, $text, $parseMode = null){
    $payload = ['chat_id'=>$chatId,'text'=>$text,'disable_web_page_preview'=>true];
    if($parseMode) $payload['parse_mode'] = $parseMode;
    return tg('sendMessage', $payload);
}

if($mode !== 'drop'){
    sendMsg($chatId, "❌ دستور نامعتبر.");
    exit(1);
}

// Safety: only allow dropping reseller dbs of the form <base>_rb*
$baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');
if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0)) || $dbn === $baseDb){
    editMsg($chatId, $messageId, "❌ دیتابیس معتبر نیست یا حذف دیتابیس مادر مجاز نیست.", 'Markdown');
    exit(0);
}

// Try drop
$dbEsc = str_replace('`','',$dbn);
$ok = $connection->query("DROP DATABASE `{$dbEsc}`");

if($ok){
    // Clean reseller_bots references
    @ensureResellerTables();
    $connection->query("DELETE FROM reseller_bots WHERE db_name='".mysqli_real_escape_string($connection,$dbn)."'");
    $keys = ['inline_keyboard'=>[[['text'=>'🔙 برگشت به لیست','callback_data'=>'adminResDBList_0']]]];
    editMsg($chatId, $messageId, "✅ دیتابیس حذف شد: `{$dbn}`", 'Markdown', $keys);
}else{
    $err = $connection->error;
    $keys = ['inline_keyboard'=>[[['text'=>'🔙 برگشت به لیست','callback_data'=>'adminResDBList_0']]]];
    editMsg($chatId, $messageId, "❌ حذف انجام نشد.\n\nخطا: {$err}", 'Markdown', $keys);
}

exit(0);
