<?php
// Local HTTP worker for dropping reseller databases without blocking Telegram webhook.
// Called by bot.php via fireAndForgetLocal(). Do NOT expose publicly without signature verification.

require_once __DIR__ . '/config.php';

@ignore_user_abort(true);
@set_time_limit(0);

$chat_id = isset($_GET['chat_id']) ? (int)$_GET['chat_id'] : 0;
$message_id = isset($_GET['message_id']) ? (int)$_GET['message_id'] : 0;
$dbn = isset($_GET['db']) ? (string)$_GET['db'] : '';
$sig = isset($_GET['sig']) ? (string)$_GET['sig'] : '';

if($chat_id <= 0 || $message_id <= 0 || $dbn === '' || $sig === ''){
    http_response_code(400);
    echo "bad_request";
    exit;
}

$tokenToUse = $GLOBALS['botToken'] ?? ($botToken ?? '');
$expected = hash_hmac('sha256', $chat_id . '|' . $message_id . '|' . $dbn, $tokenToUse);
if(!hash_equals($expected, $sig)){
    http_response_code(403);
    echo "forbidden";
    exit;
}

$baseDb = $GLOBALS['dbName'] ?? ($dbName ?? '');
if(!($dbn === $baseDb || (strpos($dbn, $baseDb . "_rb") === 0))){
    bot('editMessageText',[
        'chat_id'=>$chat_id,
        'message_id'=>$message_id,
        'text'=>"❌ دیتابیس معتبر نیست.",
        'parse_mode'=>"Markdown"
    ]);
    echo "invalid_db";
    exit;
}
if($dbn === $baseDb){
    bot('editMessageText',[
        'chat_id'=>$chat_id,
        'message_id'=>$message_id,
        'text'=>"❌ حذف دیتابیس مادر غیرفعال است.",
        'parse_mode'=>"Markdown"
    ]);
    echo "mother_blocked";
    exit;
}

// Try drop database
$dbEsc = str_replace('`','',$dbn);

// Optional: reduce waiting time if server supports it
@$connection->query("SET SESSION wait_timeout=30");
@$connection->query("SET SESSION innodb_lock_wait_timeout=15");

$ok = $connection->query("DROP DATABASE `{$dbEsc}`");
if($ok){
    $connection->query("DELETE FROM reseller_bots WHERE db_name='".mysqli_real_escape_string($connection,$dbn)."'");
    bot('editMessageText',[
        'chat_id'=>$chat_id,
        'message_id'=>$message_id,
        'text'=>"✅ دیتابیس حذف شد: `{$dbn}`",
        'parse_mode'=>"Markdown",
        'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>"🔙 برگشت به لیست",'callback_data'=>"adminResDBList_0"]]]])
    ]);
    echo "ok";
}else{
    $err = $connection->error;
    bot('editMessageText',[
        'chat_id'=>$chat_id,
        'message_id'=>$message_id,
        'text'=>"❌ حذف انجام نشد.\n\nخطا: {$err}",
        'parse_mode'=>"Markdown",
        'reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>"🔙 برگشت",'callback_data'=>"adminResDBInfo_".rawurlencode($dbn)]]])
    ]);
    echo "fail";
}
