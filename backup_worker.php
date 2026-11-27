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

if($mode==='scheduled'){
    require_once __DIR__.'/baseInfo.php';
    $mode='backup';$token=(string)($botToken??'');$chatId=(string)($admin??'');
    $arg4='deltabotvps_scheduled_backup';$arg5=(string)($dbName??'');
}
if (!$mode || !$token || !$chatId) {
    fwrite(STDERR, "Invalid args\n");
    exit(1);
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . '/delta_backup_integrity.php';
$fullMotherDb = (string)($dbName ?? '');
$fullOwner = (string)($admin ?? '');
$fullMotherToken = (string)($botToken ?? '');
$fullOwnerRequest = $fullMotherDb === 'delta' &&
    ($arg5 === '' || $arg5 === $fullMotherDb) &&
    $fullOwner !== '' && hash_equals($fullOwner, (string)$chatId) &&
    $fullMotherToken !== '' && hash_equals($fullMotherToken, (string)$token);


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

// Keep the file below Telegram's cloud getFile limit when possible.
function compressBackupFile(string $path): ?string {
    $dest=$path.'.gz';$in=fopen($path,'rb');if(!$in)return null;
    $old=umask(0077);$out=gzopen($dest,'wb6');umask($old);
    if(!$out){fclose($in);return null;}
    $ok=true;
    try{while(!feof($in)){$chunk=fread($in,1048576);if($chunk===false||gzwrite($out,$chunk)!==strlen($chunk)){$ok=false;break;}}}
    finally{fclose($in);if(!gzclose($out))$ok=false;}
    if(!$ok){@unlink($dest);return null;}
    return $dest;
}
function expandBackupFile(string $path): ?string {
    if(file_get_contents($path,false,null,0,2)!=="\x1f\x8b")return $path;
    $dest=ensureBackupDir().'/restore_expand_'.bin2hex(random_bytes(12)).'.sql';
    $in=gzopen($path,'rb');if(!$in)return null;
    $old=umask(0077);$out=fopen($dest,'xb');umask($old);
    if(!$out){gzclose($in);return null;}
    $ok=true;$size=0;
    try{while(!gzeof($in)){
        $chunk=gzread($in,1048576);
        if($chunk===false||($size+=strlen($chunk))>536870912||fwrite($out,$chunk)!==strlen($chunk)){$ok=false;break;}
    }}finally{gzclose($in);fclose($out);}
    if(!$ok){@unlink($dest);return null;}
    return $dest;
}

// The root helper is installed outside the web root with a narrowly scoped sudo rule.
// The independent recovery key never enters PHP, Telegram or the repository.
function fullBackupHelper(string $action, string $path): bool {
    if (!in_array($action, ['create', 'verify'], true)) return false;
    if (!is_executable('/usr/local/sbin/delta-full-backup')) return false;
    if ($action === 'create') {
        $old = umask(0077);
        $handle = fopen($path, 'xb');
        umask($old);
        if (!$handle) return false;
        $io = [0=>['file','/dev/null','r'], 1=>$handle, 2=>['file','/dev/null','w']];
    } else {
        $handle = fopen($path,'rb');
        if (!$handle) return false;
        $io = [0=>$handle, 1=>['pipe','w'], 2=>['file','/dev/null','w']];
    }
    try {
        $proc = proc_open(['/usr/bin/sudo','-n','/usr/local/sbin/delta-full-backup',$action], $io, $pipes);
        if (!is_resource($proc)) return false;
        if ($action === 'verify') {
            $result = stream_get_contents($pipes[1], 4096);
            fclose($pipes[1]);
        }
        $ok = proc_close($proc) === 0;
        return $ok && ($action === 'create' || str_starts_with((string)$result,'VERIFIED_RESTORE_STAGED='));
    } finally { fclose($handle); }
}
function fullBackupMagic(string $path): bool {
    return is_file($path) && file_get_contents($path,false,null,0,14) === "DELTA-FULL-V1\n";
}

if ($mode === 'backup' && $fullOwnerRequest) {
    sendMsg($chatId, "⏳ در حال ساخت بکاپ رمزگذاری‌شده کل مجموعه دلتا...");
    $path = ensureBackupDir().'/DELTA-FULL-'.date('Ymd_His').'-'.bin2hex(random_bytes(4)).'.delta.gpg';
    try {
        // Preserve the portable app panel recovery envelope before taking the multi-DB snapshot.
        require_once __DIR__.'/delta_app_real_checkout.php';
        daSchema($connection);
        daRealSchema($connection);
        daPrepareRecoveryKey($connection);
        if (!fullBackupHelper('create',$path) || !fullBackupMagic($path)) {
            @unlink($path);
            sendMsg($chatId,"❌ بکاپ کامل ساخته نشد. سرویس بکاپ و دسترسی دیتابیس‌ها را بررسی کنید؛ بکاپ ناقص ارسال نشد.");
            exit(1);
        }
        if (filesize($path) > 19*1024*1024) {
            sendMsg($chatId,"⚠️ بکاپ رمزگذاری‌شده سالم روی سرور ذخیره شد ولی از سقف دانلود تلگرام بزرگ‌تر است. از SSH آن را به فضای امن خارج سرور منتقل کنید.\n".basename($path));
            exit(1);
        }
        $res = sendDoc($chatId,$path,"🔐 بکاپ رمزگذاری‌شده کل مجموعه دلتا\nربات مادر، نمایندگی‌ها، برنامه، SMS Bridge و تنظیمات سرور\n".date('Y-m-d H:i:s'));
        sendMsg($chatId,!empty($res['ok'])?"✅ بکاپ ارسال شد. فایل و رمز بازیابی مستقل را جداگانه خارج از سرور نگه دارید.":"❌ ارسال تلگرام ناموفق بود؛ فایل رمزگذاری‌شده روی سرور محفوظ است.");
        exit(empty($res['ok'])?1:0);
    } catch (Throwable $e) {
        @unlink($path);
        sendMsg($chatId,"❌ ساخت بکاپ کامل متوقف شد؛ هیچ بکاپ ناقصی ارسال نشد.");
        exit(1);
    }
}

if ($mode === 'restore' && fullBackupMagic($arg4)) {
    if (!$fullOwnerRequest) {
        sendMsg($chatId,"❌ بازیابی کل مجموعه فقط برای مدیر اصلی ربات مادر مجاز است.");
        exit(1);
    }
    if (!fullBackupHelper('verify',$arg4)) {
        sendMsg($chatId,"❌ بکاپ کامل معتبر نیست یا کلید بازیابی مطابقت ندارد. هیچ دیتابیسی تغییر نکرد.");
        exit(1);
    }
    sendMsg($chatId,"✅ فایل رمزگذاری‌شده بررسی و در فضای خصوصی root آماده شد. هیچ داده‌ای هنوز جایگزین نشده است. برای بازیابی کل سرور تأیید مستقل مالک از SSH لازم است.");
    exit(0);
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
    $unified=deltaUnifiedBackupIsMarked($path);
    $rawPath=$path;
    if($sz>18*1024*1024){
        $compressed=compressBackupFile($path);
        if($compressed)$path=$compressed;
    }
    if(@filesize($path)>19*1024*1024){
        sendMsg($chatId,"❌ فایل حتی پس از فشرده‌سازی از حد دانلود تلگرام بزرگ‌تر است. بکاپ روی سرور محفوظ است.\n".$rawPath);
        exit(1);
    }
    $cap=($unified?'🗄 بکاپ کامل ربات + برنامه دلتا':'🗄 بکاپ دیتابیس')."\n".date('Y-m-d H:i:s');
    $res = sendDoc($chatId, $path, $cap);
    if(isset($res['ok']) && $res['ok']){
        @unlink($path);
        if($rawPath!==$path)@unlink($rawPath);
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
    $originalPath=$sqlPath;
    $sqlPath=expandBackupFile($sqlPath);
    if(!$sqlPath){sendMsg($chatId,'❌ فایل فشرده معتبر نیست.');exit(1);}
    if($sqlPath!==$originalPath)register_shutdown_function(static function()use($sqlPath){@unlink($sqlPath);});
    global $connection;
    // Validate before touching any live tables. A dump containing the recovery
    // key must have a valid signature, including if its footer was truncated.
    $unified=deltaUnifiedBackupIsMarked($sqlPath)||deltaUnifiedBackupHasRecoveryTable($sqlPath);
    if($unified&&!deltaUnifiedBackupCheck($sqlPath,$connection)){
        sendMsg($chatId,'❌ فایل بکاپ کامل معتبر نیست یا توکن ربات مطابقت ندارد؛ هیچ تغییری اعمال نشد.');
        exit(1);
    }
    if(!$unified){
        if(!deltaLegacyBackupLooksComplete($sqlPath)){sendMsg($chatId,'❌ ساختار یا پایان فایل SQL معتبر نیست؛ هیچ تغییری اعمال نشد.');exit(1);}
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
    if($ok&&($unified||deltaUnifiedBackupHasTable($sqlPath,'delta_app_accounts'))){
        try{
            if($unified&&!daRecoveryTableExists($connection))throw new RuntimeException('missing recovery table');
            daVerifyRecoverySecrets($connection);
        }catch(Throwable $e){$ok=false;error_log('Unified restore verification failed: '.get_class($e));}
    }
    if($ok){
        sendMsg($chatId,$unified?'✅ بکاپ کامل ربات و برنامه دلتا بازگردانی شد؛ اتصال واقعی پنل را بررسی کنید.':'✅ بکاپ با موفقیت بازگردانی شد.');
        // Preserve the original backup for repeat recovery.
    }else{
        // Do not print mysql output, which can contain sensitive SQL values.
        sendMsg($chatId,"❌ بازگردانی کامل نشد. دیتابیس ممکن است جزئی تغییر کرده باشد؛ از بکاپ ایمنی قبل از بازگردانی استفاده کنید.\nمسیر بکاپ ایمنی: ".$safety);
    }
    exit(0);
}

sendMsg($chatId, "❌ دستور نامعتبر.");
exit(1);
