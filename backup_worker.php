<?php
// DeltaBot Backup Worker (async)
// Usage:
//   php backup_worker.php backup <BOT_TOKEN> <CHAT_ID> [prefix] [db_name]
//   php backup_worker.php restore <BOT_TOKEN> <CHAT_ID> <SQL_FILE_PATH> [db_name]
//
// This script runs in background (nohup) so main bot won't hang.

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only"); }

$mode = $argv[1] ?? '';
$token = $argv[2] ?? '';
$chatId = $argv[3] ?? '';
$arg4 = $argv[4] ?? '';
$arg5 = $argv[5] ?? '';

if (!$mode || !$token || !$chatId) {
    fwrite(STDERR, "Invalid args\n");
    exit(1);
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . '/delta_backup_integrity.php';

// override token for this worker run
$GLOBALS['botToken'] = $token;
$botToken = $token;

// Optional: override DB for child bots
if($arg5){
    $childDb = trim($arg5);
    if($childDb !== '' && isset($GLOBALS['dbUserName']) && isset($GLOBALS['dbPassword'])){
        // reconnect global $connection
        if(isset($GLOBALS['connection']) && $GLOBALS['connection']){
            @mysqli_close($GLOBALS['connection']);
        }
        $conn2 = new mysqli('localhost', $GLOBALS['dbUserName'], $GLOBALS['dbPassword'], $childDb);
        if(!$conn2->connect_error){
            $conn2->set_charset('utf8mb4');
            $GLOBALS['connection'] = $conn2;
            $GLOBALS['dbName'] = $childDb;
        }
    }
}

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


function startsWith($hay, $needle){
    return substr($hay, 0, strlen($needle)) === $needle;
}

function sendMsg($chatId, $text){
    return tg('sendMessage', ['chat_id'=>$chatId,'text'=>$text]);
}

function sendDoc($chatId, $filePath, $caption=''){
    if(!file_exists($filePath)) return ['ok'=>false,'description'=>'file not found'];
    $post = [
        'chat_id'=>$chatId,
        'caption'=>$caption,
        'document'=> new CURLFile($filePath)
    ];
    return tg('sendDocument', $post);
}

function shellAvailable(){
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    foreach (['shell_exec','exec','system','passthru'] as $f){
        if (!in_array($f, $disabled, true) && function_exists($f)) return true;
    }
    return false;
}

function restoreWithMysqlCli($sqlPath, &$errOut = null){
    // Uses system mysql client if available (preferred; supports real-world dumps reliably)
    if(!shellAvailable()) return false;

    $which = @shell_exec('command -v mysql 2>/dev/null');
    $which = is_string($which) ? trim($which) : '';
    if($which === '' || !is_executable($which)) return false;

    $dbUser = $GLOBALS['dbUserName'] ?? ($GLOBALS['dbuser'] ?? null);
    $dbPass = $GLOBALS['dbPassword'] ?? ($GLOBALS['dbpass'] ?? null);
    $dbName = $GLOBALS['dbName'] ?? ($GLOBALS['dbname'] ?? null);
    $dbHost = $GLOBALS['dbHost'] ?? ($GLOBALS['dbhost'] ?? 'localhost');
    if(!$dbUser || $dbName === null) return false;
    if($dbHost === null || $dbHost === '') $dbHost = 'localhost';

    // Best-effort: avoid exposing password on process list
    $env = 'MYSQL_PWD=' . escapeshellarg((string)$dbPass);
    $cmd = $env.
        ' ' . escapeshellcmd($which) .
        ' --host=' . escapeshellarg($dbHost) .
        ' --user=' . escapeshellarg((string)$dbUser) .
        ' --default-character-set=utf8mb4 ' .
        escapeshellarg((string)$dbName) .
        ' < ' . escapeshellarg($sqlPath) . ' 2>&1';

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);
    if($code !== 0){
        $errOut = trim(implode("\n", array_slice($out, -10)));
        return false;
    }
    return true;
}

function restoreStreaming($sqlPath){
    global $connection;
    $fh = @fopen($sqlPath, 'r');
    if(!$fh) return false;
    @set_time_limit(600);
    if(function_exists('ignore_user_abort')) @ignore_user_abort(true);

    $buffer = '';
    $ok = true;
    while(!feof($fh)){
        $chunk = fread($fh, 1024*1024); // 1MB
        if($chunk === false) break;
        $buffer .= $chunk;

        // Process complete statements ending with ";\n"
        while(($pos = strpos($buffer, ";\n")) !== false){
            $stmt = substr($buffer, 0, $pos+2);
            $buffer = substr($buffer, $pos+2);

            $stmtTrim = trim($stmt);
            if($stmtTrim === '' || startsWith($stmtTrim, '--') || startsWith($stmtTrim, '/*')){
                continue;
            }
            if(!$connection->query($stmtTrim)){
                $ok = false;
                // keep going, but mark failed
            }
        }
    }
    // last statement
    $tail = trim($buffer);
    if($tail !== '' && !startsWith($tail, '--') && !startsWith($tail, '/*')){
        if(!$connection->query($tail)) $ok = false;
    }
    fclose($fh);
    return $ok;
}

if($mode === 'backup'){
    $prefix = $arg4 ?: 'deltabotvps_backup';
    sendMsg($chatId, "⏳ در حال ساخت بکاپ... لطفا صبر کنید");
    $path = dbCreateSqlBackupFile($prefix);
    if(!$path){
        sendMsg($chatId, "❌ خطا در ساخت بکاپ (دسترسی/تنظیمات دیتابیس).");
        exit(0);
    }

    $sz = @filesize($path);
    if($sz === false || $sz < 1024){
        // probably mysqldump error-output or empty dump
        $hint = ''; // Never send snippets of SQL or credentials to chat.
        sendMsg($chatId, "❌ بکاپ ساخته شد اما معتبر نیست (خیلی کوچک/خطا در mysqldump).{$hint}\n\nمسیر فایل: {$path}");
        exit(0);
    }
    if(@filesize($path) > 49*1024*1024){
        sendMsg($chatId, "❌ حجم بکاپ خیلی زیاد است و تلگرام اجازه ارسال نمی‌دهد.\nمسیر فایل: {$path}");
        exit(0);
    }
    $unified=deltaUnifiedBackupIsMarked($path);
    $cap = ($unified?'🗄 بکاپ کامل ربات + برنامه دلتا (SQL)':'🗄 بکاپ دیتابیس')."\n".date('Y-m-d H:i:s');
    $res = sendDoc($chatId, $path, $cap);
    if(isset($res['ok']) && $res['ok']){
        @unlink($path);
        sendMsg($chatId, "✅ بکاپ ارسال شد.");
    }else{
        sendMsg($chatId, "❌ ارسال بکاپ ناموفق بود.\nمسیر فایل: {$path}");
    }
    exit(0);
}

if($mode === 'restore'){
    $sqlPath = $arg4;
    if(!$sqlPath || !file_exists($sqlPath)){
        sendMsg($chatId, "❌ فایل بکاپ پیدا نشد.");
        exit(0);
    }
    global $connection;
    // Validate before touching any live tables. A dump containing the recovery
    // key must have a valid signature, including if its footer was truncated.
    $unified=deltaUnifiedBackupIsMarked($sqlPath)||deltaUnifiedBackupHasRecoveryTable($sqlPath);
    if($unified&&!deltaUnifiedBackupCheck($sqlPath,$connection)){
        sendMsg($chatId,'❌ فایل بکاپ کامل معتبر نیست یا توکن ربات مطابقت ندارد؛ هیچ تغییری اعمال نشد.');
        exit(1);
    }
    if(!$unified){
        $hasApp=daRow($connection,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_accounts'");
        if($hasApp&&daRow($connection,'SELECT id FROM delta_app_accounts LIMIT 1')&&!deltaUnifiedBackupHasTable($sqlPath,'delta_app_accounts')){
            sendMsg($chatId,'❌ این بکاپ قدیمی اطلاعات برنامه را ندارد. برای جلوگیری از بازیابی ناقص، بکاپ کامل را انتخاب کنید.');
            exit(1);
        }
        sendMsg($chatId,'⚠️ بکاپ قدیمی است؛ بازیابی کامل برنامه با رمز دیتابیس متفاوت تضمین نمی‌شود.');
    }
    // Preserve the current database before importing. Do not retry a failed
    // partial mysql import with a different SQL parser.
    $safety=dbCreateSqlBackupFile('before_restore');
    if(!$safety){sendMsg($chatId,'❌ بکاپ ایمنی قبل از بازگردانی ساخته نشد؛ بازیابی متوقف شد.');exit(1);}
    sendMsg($chatId,"⏳ در حال بازگردانی بکاپ...");
    $err=null;
    $ok=restoreWithMysqlCli($sqlPath,$err);
    if($ok&&$unified){
        try{
            if(!daRecoveryTableExists($connection))throw new RuntimeException('missing recovery table');
            daVerifyRecoverySecrets($connection);
        }catch(Throwable $e){$ok=false;error_log('Unified restore verification failed: '.get_class($e));}
    }
    if($ok){
        sendMsg($chatId,$unified?'✅ بکاپ کامل ربات و برنامه دلتا بازگردانی شد؛ اتصال واقعی پنل را بررسی کنید.':'✅ بکاپ با موفقیت بازگردانی شد.');
        @unlink($sqlPath);
    }else{
        // Do not print mysql output, which can contain sensitive SQL values.
        sendMsg($chatId,"❌ بازگردانی کامل نشد. دیتابیس ممکن است جزئی تغییر کرده باشد؛ از بکاپ ایمنی قبل از بازگردانی استفاده کنید.\nمسیر بکاپ ایمنی: ".$safety);
    }
    exit(0);
}

sendMsg($chatId, "❌ دستور نامعتبر.");
exit(1);
