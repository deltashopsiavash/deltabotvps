<?php
require_once __DIR__ . "/settings/values.php";
require_once __DIR__ . "/settings/jdf.php";
// Always load baseInfo.php from the project root (fixes include path issues when config.php
// is included from subfolders like /settings or /pay).
require_once __DIR__ . "/baseInfo.php";
$mainDbName = $dbName; // keep a stable reference to the mother DB
$connection = new mysqli('localhost',$dbUserName,$dbPassword,$dbName);
if($connection->connect_error){
    exit("error " . $connection->connect_error);  
}
$connection->set_charset("utf8mb4");

function addColumnIfMissing($table, $col, $ddl){
    global $connection;
    $tableEsc = str_replace('`','',$table);
    $colEsc = str_replace('`','',$col);
    $q = $connection->query("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$tableEsc}' AND COLUMN_NAME='{$colEsc}'");
    $exists = 0;
    if($q){
        $r = $q->fetch_assoc();
        $exists = (int)($r['c'] ?? 0);
    }
    if($exists === 0){
        $connection->query("ALTER TABLE `{$tableEsc}` ADD COLUMN {$ddl}");
    }
}
function ensureServerConfigSchema(){
    global $connection;
    static $checked = [];
    if(!$connection || $connection->connect_error) return;
    $db = '';
    $res = $connection->query("SELECT DATABASE() AS db");
    if($res){
        $db = (string)($res->fetch_assoc()['db'] ?? '');
    }
    if(isset($checked[$db])) return;
    addColumnIfMissing('server_config', 'sub_base_url', '`sub_base_url` varchar(255) DEFAULT NULL AFTER `panel_url`');
    $checked[$db] = true;
}
ensureServerConfigSchema();

function ensureUserApprovalSchema(){
    global $connection;
    static $done = false;
    if($done) return;
    $done = true;
    $q = $connection->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'");
    $exists = 0;
    if($q){
        $r = $q->fetch_assoc();
        $exists = (int)($r['c'] ?? 0);
    }
    if($exists === 0) return;
    addColumnIfMissing('users', 'approval_status', "`approval_status` varchar(20) NOT NULL DEFAULT 'none' AFTER `spam_info`");
    addColumnIfMissing('users', 'approval_inviter_input', '`approval_inviter_input` varchar(190) DEFAULT NULL AFTER `approval_status`');
    addColumnIfMissing('users', 'approval_inviter_userid', '`approval_inviter_userid` bigint(20) DEFAULT NULL AFTER `approval_inviter_input`');
    addColumnIfMissing('users', 'approval_inviter_username', '`approval_inviter_username` varchar(100) DEFAULT NULL AFTER `approval_inviter_userid`');
    addColumnIfMissing('users', 'approval_requested_at', '`approval_requested_at` int(11) NOT NULL DEFAULT 0 AFTER `approval_inviter_username`');
    addColumnIfMissing('users', 'approval_updated_at', '`approval_updated_at` int(11) NOT NULL DEFAULT 0 AFTER `approval_requested_at`');
    addColumnIfMissing('users', 'approval_by', '`approval_by` bigint(20) DEFAULT NULL AFTER `approval_updated_at`');
}
ensureUserApprovalSchema();

// ---------------- Multi-bot (Reseller Bots) support ----------------
$isChildBot = false;
$currentBotInstanceId = null;
$childBotRow = null;

// Helper: call Telegram API with a specific token (without changing globals)
function botWithToken($token, $method, $datas = []){
    $url = "https://api.telegram.org/bot" . $token . "/" . $method;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datas));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Ensure reseller tables exist (safe to call many times)
function ensureResellerTables(){
    global $connection;

    // Helper: add column if missing (MySQL/MariaDB compatible)
    $addColumnIfMissing = function($table, $col, $ddl) use ($connection){
        $tableEsc = str_replace('`','',$table);
        $colEsc = str_replace('`','',$col);
        $q = $connection->query("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$tableEsc}' AND COLUMN_NAME='{$colEsc}'");
        $exists = 0;
        if($q){
            $r = $q->fetch_assoc();
            $exists = (int)($r['c'] ?? 0);
        }
        if($exists === 0){
            $connection->query("ALTER TABLE `{$tableEsc}` ADD COLUMN {$ddl}");
        }
    };
    $connection->query("CREATE TABLE IF NOT EXISTS `reseller_plans` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `title` VARCHAR(120) NOT NULL,
        `days` INT NOT NULL DEFAULT 30,
        `price` INT NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` INT NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $connection->query("CREATE TABLE IF NOT EXISTS `reseller_bots` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `owner_userid` BIGINT NOT NULL,
        `bot_token` VARCHAR(255) NOT NULL,
        `bot_tg_id` BIGINT NULL,
        `bot_username` VARCHAR(120) NULL,
        `admin_userid` BIGINT NOT NULL DEFAULT 0,
        `created_at` INT NOT NULL DEFAULT 0,
        `expires_at` INT NOT NULL DEFAULT 0,
        `status` TINYINT(1) NOT NULL DEFAULT 1,
        `exp_notify_sent` TINYINT(1) NOT NULL DEFAULT 0,
        `backup_auto` TINYINT(1) NOT NULL DEFAULT 0,
        `last_backup_at` INT NOT NULL DEFAULT 0,
        `db_name` VARCHAR(190) NULL,
        PRIMARY KEY (`id`),
        KEY `owner_userid` (`owner_userid`),
        KEY `status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Backward-compatible alters for existing installs
    $addColumnIfMissing('reseller_bots','exp_notify_sent','`exp_notify_sent` TINYINT(1) NOT NULL DEFAULT 0');
    $addColumnIfMissing('reseller_bots','backup_auto','`backup_auto` TINYINT(1) NOT NULL DEFAULT 0');
    $addColumnIfMissing('reseller_bots','last_backup_at','`last_backup_at` INT NOT NULL DEFAULT 0');
    $addColumnIfMissing('reseller_bots','db_name','`db_name` VARCHAR(190) NULL');
    $addColumnIfMissing('reseller_bots','is_deleted','`is_deleted` TINYINT(1) NOT NULL DEFAULT 0');
    $addColumnIfMissing('reseller_bots','deleted_at','`deleted_at` INT NOT NULL DEFAULT 0');

}

// Create a dedicated database for a reseller bot (child bot).
// We clone schema from the mother DB and copy only configuration tables.
function ensureResellerBotDatabase($rid){
    global $connection, $dbUserName, $dbPassword, $mainDbName;
    ensureResellerTables();
    $rid = (int)$rid;
    if($rid <= 0) return false;

    $row = $connection->query("SELECT `id`,`db_name` FROM `reseller_bots` WHERE `id`={$rid} LIMIT 1");
    $rb = $row ? $row->fetch_assoc() : null;
    if(!$rb) return false;
    if(!empty($rb['db_name'])) return true;

    // Generate DB name
    $newDb = preg_replace('/[^a-zA-Z0-9_]/','_', $mainDbName . '_rb' . $rid);
    $newDb = substr($newDb, 0, 60);
    if($newDb === '') return false;

    // Create DB (requires CREATE privilege for the MySQL user)
    $ok = $connection->query("CREATE DATABASE IF NOT EXISTS `{$newDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    if(!$ok){
        return false;
    }

    // Prefer importing reseller template dump if present
    $templatePath = __DIR__ . "/settings/reseller_template.sql";
    $importOk = false;
    if(file_exists($templatePath) && filesize($templatePath) > 100){
        // Use mysql cli if available (handles real dumps reliably)
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        $shellOk = (!in_array('shell_exec',$disabled,true) && function_exists('shell_exec')) || (!in_array('exec',$disabled,true) && function_exists('exec'));
        if($shellOk){
            $which = @shell_exec('command -v mysql 2>/dev/null');
            $which = is_string($which) ? trim($which) : '';
            if($which !== '' && is_executable($which)){
                $env = 'MYSQL_PWD=' . escapeshellarg((string)$dbPassword);
                $cmd = $env .
                    ' ' . escapeshellcmd($which) .
                    ' --host=' . escapeshellarg('localhost') .
                    ' --user=' . escapeshellarg((string)$dbUserName) .
                    ' --default-character-set=utf8mb4 ' .
                    escapeshellarg((string)$newDb) .
                    ' < ' . escapeshellarg($templatePath) . ' 2>&1';
                $out = [];
                $code = 0;
                @exec($cmd, $out, $code);
                if($code === 0){
                    $importOk = true;
                }
            }
        }
    }

    // Fallback: clone schema only (if template import isn't available)
    if(!$importOk){
        $tablesRes = $connection->query("SHOW TABLES FROM `{$mainDbName}`");
        if(!$tablesRes) return false;
        $tables = [];
        while($tr = $tablesRes->fetch_array()){
            $tables[] = $tr[0];
        }
        foreach($tables as $t){
            if($t === 'reseller_plans' || $t === 'reseller_bots') continue;
            $createRes = $connection->query("SHOW CREATE TABLE `{$mainDbName}`.`{$t}`");
            if(!$createRes) continue;
            $cr = $createRes->fetch_assoc();
            $createSql = $cr['Create Table'] ?? '';
            if($createSql){
                $createSql = preg_replace('/CREATE TABLE `'.preg_quote($t,'/').'`/','CREATE TABLE `'.$newDb.'`.`'.$t.'`', $createSql, 1);
                @$connection->query($createSql);
            }
        }
    }

    // Save db_name for child bot so it never falls back to mother DB
    $stmt = $connection->prepare("UPDATE `reseller_bots` SET `db_name`=? WHERE `id`=?");
    $stmt->bind_param("si", $newDb, $rid);
    $stmt->execute();
    $stmt->close();

    return true;
}

// Run periodic maintenance for reseller bots:
// - 1 day before expiry, notify owner/admin once
// - after expiry, set status=0 and remove webhook (data is kept)
function resellerBotsMaintenance(){
    global $connection, $botUrl;
    ensureResellerTables();

    $now = time();
    $soon = $now + 86400; // 24h

    // Notify expiring soon (once)
    $stmt = $connection->prepare("SELECT `id`,`owner_userid`,`admin_userid`,`expires_at`,`bot_token`,`bot_username` FROM `reseller_bots` WHERE `status`=1 AND `expires_at`>0 AND `expires_at`<=? AND `expires_at`>? AND `exp_notify_sent`=0");
    if($stmt){
        $stmt->bind_param("ii", $soon, $now);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        while($row = $res->fetch_assoc()){
            $rid = (int)$row['id'];
            $expAt = (int)$row['expires_at'];
            $uname = !empty($row['bot_username']) ? '@'.$row['bot_username'] : ('#'.$rid);
            $exp = jdate('Y/m/d H:i', $expAt);
            $msg = "⏰ یادآوری: اعتبار ربات {$uname} فردا تمام می‌شود.\n\nتاریخ انقضا: {$exp}\n\nبرای جلوگیری از قطع شدن، لطفا قبل از پایان زمان تمدید کنید.";

            // notify owner and admin (if set)
            if(!empty($row['owner_userid'])){
                sendMessage($msg, null, 'HTML', (int)$row['owner_userid']);
            }
            if(!empty($row['admin_userid']) && (int)$row['admin_userid'] != (int)$row['owner_userid']){
                sendMessage($msg, null, 'HTML', (int)$row['admin_userid']);
            }

            $connection->query("UPDATE `reseller_bots` SET `exp_notify_sent`=1 WHERE `id`={$rid} LIMIT 1");
        }
    }

    // Expire bots: disable but keep data
    $stmt2 = $connection->prepare("SELECT `id`,`bot_token` FROM `reseller_bots` WHERE `status`=1 AND `expires_at`>0 AND `expires_at`<=?");
    if($stmt2){
        $stmt2->bind_param("i", $now);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $stmt2->close();
        while($row = $res2->fetch_assoc()){
            $rid = (int)$row['id'];
            $token = $row['bot_token'];
            // remove webhook so it stops working
            if(!empty($token)){
                botWithToken($token, 'setWebhook', ['url'=>'']);
            }
            $connection->query("UPDATE `reseller_bots` SET `status`=0 WHERE `id`={$rid} LIMIT 1");
        }
    }
}

// Resolve child bot instance (if webhook URL has ?bid=ID)
if(isset($_GET['bid'])){
    $bid = (int)$_GET['bid'];
    if($bid > 0){
        ensureResellerTables();
        $stmt = $connection->prepare("SELECT * FROM `reseller_bots` WHERE `id`=? AND `status`=1 LIMIT 1");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        if($res && $res->num_rows>0){
            $childBotRow = $res->fetch_assoc();
            $currentBotInstanceId = $bid;
            $isChildBot = true;
            // Override global token + admin for this request
            $GLOBALS['botToken'] = $childBotRow['bot_token'];
            $GLOBALS['admin'] = (int)$childBotRow['admin_userid'];

            // Keep legacy globals in sync (many functions use these variables)
            $botToken = $GLOBALS['botToken'];
            $admin = $GLOBALS['admin'];

            // Switch DB connection for this child bot if dedicated DB is set
            if(!empty($childBotRow['db_name'])){
                $childDb = $childBotRow['db_name'];
                if($childDb !== $mainDbName){
                    @mysqli_close($connection);
                    $connection = new mysqli('localhost', $dbUserName, $dbPassword, $childDb);
                    if(!$connection->connect_error){
                        $connection->set_charset('utf8mb4');
                        ensureServerConfigSchema();
                        $GLOBALS['dbName'] = $childDb;
                        $dbName = $childDb;
                    }
                }
            }
        }
    }
}

// Export key context as globals (used by workers)
$GLOBALS['currentBotInstanceId'] = $currentBotInstanceId ? (int)$currentBotInstanceId : 0;
$GLOBALS['mainDbName'] = $mainDbName;




function bot($method, $datas = []){
    global $botToken;
    $tokenToUse = $GLOBALS['botToken'] ?? $botToken;
    $url = "https://api.telegram.org/bot" . $tokenToUse . "/" . $method;
    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datas));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $res = curl_exec($ch);
    if (curl_error($ch)) {
        var_dump(curl_error($ch));
    } else {
        return json_decode($res);
    }
}
function sendMessage($txt, $key = null, $parse ="MarkDown", $ci= null, $msg = null){
    global $from_id;
    $ci = $ci??$from_id;

    $payload = [
        'chat_id' => $ci,
        'text' => $txt,
        'reply_to_message_id' => $msg,
        'reply_markup' => $key,
    ];

    // If parse mode is null/empty, send as plain text (prevents issues with _ in Markdown)
    if($parse !== null && $parse !== ''){
        $payload['parse_mode'] = $parse;
    }

    return bot('sendMessage', $payload);
}

function getAllAdminIds(){
    global $connection, $admin;
    $ids = [];
    // main admin
    if(!empty($admin)) $ids[] = (int)$admin;
    // other admins
    $stmt = $connection->prepare("SELECT `userid` FROM `users` WHERE `isAdmin` = 1");
    if($stmt){
        $stmt->execute();
        $res = $stmt->get_result();
        $stmt->close();
        while($row = $res->fetch_assoc()){
            $ids[] = (int)$row['userid'];
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    return $ids;
}
function sendToAdmins($txt, $key = null, $parse = "MarkDown", $msg = null){
    foreach(getAllAdminIds() as $aid){
        sendMessage($txt, $key, $parse, $aid, $msg);
    }
}


// ---------------- Settings helpers (added)
function getSettingValue($type, $default=null){
    global $connection;
    $stmt = $connection->prepare("SELECT `value` FROM `setting` WHERE `type`=? LIMIT 1");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res && $res->num_rows>0){
        return $res->fetch_assoc()['value'];
    }
    return $default;
}
function upsertSettingValue($type, $value){
    global $connection;
    $stmt = $connection->prepare("SELECT `id` FROM `setting` WHERE `type`=? LIMIT 1");
    $stmt->bind_param("s", $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res && $res->num_rows>0){
        $stmt = $connection->prepare("UPDATE `setting` SET `value`=? WHERE `type`=?");
        $stmt->bind_param("ss", $value, $type);
    }else{
        $stmt = $connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
        $stmt->bind_param("ss", $type, $value);
    }
    $stmt->execute();
    $stmt->close();
    return true;
}
// percent discount for a specific user (0..100). Returns integer price >=0
function applyUserPercentDiscount($userId, $price){
    $price = (int)$price;
    $d = (int)getSettingValue("USER_DISCOUNT_" . (int)$userId, "0");
    if($d<=0) return $price;
    if($d>100) $d=100;
    $new = (int)round($price * (100-$d) / 100);
    if($new<0) $new=0;
    return $new;
}

// Returns user percent discount (0..100)
function getUserPercentDiscount($userId){
    $d = (int)getSettingValue("USER_DISCOUNT_" . (int)$userId, "0");
    if($d < 0) $d = 0;
    if($d > 100) $d = 100;
    return $d;
}

// Appends a discount line to a text if user has a percent discount
function appendUserDiscountLine($userId, $text){
    $d = getUserPercentDiscount((int)$userId);
    if($d <= 0) return (string)$text;
    return rtrim((string)$text) . "\n\n🎯 تخفیف شما: {$d}%";
}

function userHasPreviousOrders($userId){
    global $connection;
    $stmt = $connection->prepare("SELECT `id` FROM `orders_list` WHERE `userid`=? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    return ($res && $res->num_rows > 0);
}

function getLastBoughtPlanId($userId){
    global $connection;
    $stmt = $connection->prepare("SELECT `fileid` FROM `orders_list` WHERE `userid`=? AND `fileid` IS NOT NULL AND `fileid` > 0 ORDER BY `id` DESC LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if($res && $res->num_rows > 0){
        return (int)$res->fetch_assoc()['fileid'];
    }
    return 0;
}

function getCopyPaymentButtons($amount, $bankAccount, $cancelCallback = 'mainMenu'){
    $rows = [];
    $copyRow = [];
    if((string)$amount !== '' && (int)$amount > 0){
        $copyRow[] = ['text' => '📋 کپی مبلغ', 'copy_text' => ['text' => (string)$amount]];
    }
    if(trim((string)$bankAccount) !== ''){
        $copyRow[] = ['text' => '💳 کپی شماره کارت', 'copy_text' => ['text' => trim((string)$bankAccount)]];
    }
    if(!empty($copyRow)) $rows[] = $copyRow;
    $rows[] = [['text' => '😪 منصرف شدم بیخیال', 'callback_data' => $cancelCallback]];
    return json_encode(['inline_keyboard' => $rows], 448);
}
function sendPhotoToAdmins($photo, $caption = null, $keyboard = null, $parse = "MarkDown"){
    $firstRes = null;
    foreach(getAllAdminIds() as $aid){
        $res = sendPhoto($photo, $caption, $keyboard, $parse, $aid);
        if($firstRes === null) $firstRes = $res;
    }
    return $firstRes;
}

function editKeys($keys = null, $msgId = null, $ci = null){
    global $from_id,$message_id;
    $ci = $ci??$from_id;
    $msgId = $msgId??$message_id;
   
    bot('editMessageReplyMarkup',[
		'chat_id' => $ci,
		'message_id' => $msgId,
		'reply_markup' => $keys
    ]);
}
function editText($msgId, $txt, $key = null, $parse = null, $ci = null){
    global $from_id;
    $ci = $ci??$from_id;

    return bot('editMessageText', [
        'chat_id' => $ci,
        'message_id' => $msgId,
        'text' => $txt,
        'parse_mode' => $parse,
        'reply_markup' =>  $key
        ]);
}
function delMessage($msg = null, $chat_id = null){
    global $from_id, $message_id;
    $msg = $msg??$message_id;
    $chat_id = $chat_id??$from_id;
    
    return bot('deleteMessage',[
        'chat_id'=>$chat_id,
        'message_id'=>$msg
        ]);
}
function sendAction($action, $ci= null){
    global $from_id;
    $ci = $ci??$from_id;

    return bot('sendChatAction',[
        'chat_id'=>$ci,
        'action'=>$action
    ]);
}
function forwardmessage($tochatId, $fromchatId, $message_id){
    return bot('forwardMessage',[
        'chat_id'=>$tochatId,
        'from_chat_id'=>$fromchatId,
        'message_id'=>$message_id
    ]);
}
function sendPhoto($photo, $caption = null, $keyboard = null, $parse = "MarkDown", $ci =null){
    global $from_id;
    $ci = $ci??$from_id;
    return bot('sendPhoto',[
        'chat_id'=>$ci,
        'caption'=>$caption,
        'reply_markup'=>$keyboard,
        'photo'=>$photo,
        'parse_mode'=>$parse
    ]);
}

function sendDocument($documentPath, $caption = null, $keyboard = null, $parse = "HTML", $ci = null){
    global $from_id;
    if($ci == null) $ci = $from_id;
    $data = [
        'chat_id' => $ci,
        'document' => new CURLFile($documentPath),
        'parse_mode' => $parse,
    ];
    if($caption !== null) $data['caption'] = $caption;
    if($keyboard !== null) $data['reply_markup'] = json_encode($keyboard);
    return bot('sendDocument', $data);
}


// Backup helpers (avoid sys_get_temp_dir/open_basedir issues)
function ensureBackupDir(){
    $dir = __DIR__ . '/settings/backups';
    if(!is_dir($dir)){
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function writeSqlBackupToFile($sql, $prefix = 'deltabotvps_backup'){
    $dir = ensureBackupDir();
    $path = $dir . '/' . $prefix . '_' . date('Ymd_His') . '.sql';
    file_put_contents($path, $sql);
    $real = realpath($path);
    return $real ? $real : $path;
}

// Check if shell execution is available (not disabled by host)
function isShellExecAvailable(){
    if(!function_exists('shell_exec')) return false;
    $disabled = ini_get('disable_functions');
    if(!$disabled) return true;
    $disabled = array_map('trim', explode(',', $disabled));
    return !in_array('shell_exec', $disabled, true);
}

// Create DB backup file and return its path. Uses mysqldump when available (fast),
// otherwise falls back to a PHP streaming dump (safe for shared hosts).
function dbCreateSqlBackupFile($prefix = 'deltabotvps_backup'){
    // Create SQL dump of the current database (structure + data) into a file.
    // Robust on VPS: uses mysqldump when available, but verifies exit-code and output.
    global $connection;

    // Support both legacy and current baseInfo.php variable names
    $dbHost = $GLOBALS['dbHost'] ?? ($GLOBALS['dbhost'] ?? 'localhost');
    $dbName = $GLOBALS['dbName'] ?? ($GLOBALS['dbname'] ?? null);
    $dbUser = $GLOBALS['dbUserName'] ?? ($GLOBALS['dbuser'] ?? null);
    $dbPass = $GLOBALS['dbPassword'] ?? ($GLOBALS['dbpass'] ?? '');

    if(!$dbName || !$dbUser || !$connection){
        return null;
    }

    @set_time_limit(600);
    if(function_exists('ignore_user_abort')) @ignore_user_abort(true);

    $dir = ensureBackupDir();
    $path = $dir . '/' . $prefix . '_' . date('Ymd_His') . '.sql';

    // 1) Try mysqldump (fast & reliable) if possible
    if(isShellExecAvailable()){
        $which = @shell_exec('command -v mysqldump 2>/dev/null');
        $which = is_string($which) ? trim($which) : '';
        if($which !== '' && @is_executable($which)){
            // Avoid exposing password on process list
            $env = 'MYSQL_PWD=' . escapeshellarg((string)$dbPass);
            $hostArg = $dbHost ? ('--host=' . escapeshellarg((string)$dbHost)) : '';
            $cmd = $env .
                ' ' . escapeshellcmd($which) .
                ' --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4 ' .
                $hostArg .
                ' --user=' . escapeshellarg((string)$dbUser) .
                ' ' . escapeshellarg((string)$dbName) .
                ' > ' . escapeshellarg($path) . ' 2>&1';

            $out = [];
            $code = 0;
            @exec($cmd, $out, $code);

            // Validate: exit code + non-trivial size + not an error-only file
            $ok = ($code === 0 && @file_exists($path) && @filesize($path) > 1024);
            if($ok){
                // quick sanity check: mysqldump outputs comments/CREATE TABLE
                $head = @file_get_contents($path, false, null, 0, 2048);
                if($head === false) $ok = false;
                else{
                    $bad = (stripos($head, 'mysqldump:') !== false) ||
                           (stripos($head, 'Got error') !== false) ||
                           (stripos($head, 'Access denied') !== false);
                    $ok = !$bad;
                }
            }

            if($ok){
                $real = realpath($path);
                return $real ? $real : $path;
            }else{
                // mysqldump failed; remove garbage file and fall back
                @unlink($path);
            }
        }
    }

    // 2) Fallback: stream dump via mysqli (memory-safe)
    $fh = @fopen($path, 'w');
    if(!$fh) return null;

    fwrite($fh, "-- DeltaBot DB Backup\n-- Created at: ".date('c')."\n\nSET FOREIGN_KEY_CHECKS=0;\n");

    $tables = [];
    // Prefer information_schema (more reliable)
    $q = $connection->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_TYPE='BASE TABLE'");
    if($q){
        while($r = $q->fetch_row()){ $tables[] = $r[0]; }
        $q->close();
    }else{
        $tablesRes = $connection->query("SHOW TABLES");
        if($tablesRes){
            while($trow = $tablesRes->fetch_row()){ $tables[] = $trow[0]; }
            $tablesRes->close();
        }
    }

    if(empty($tables)){
        fclose($fh);
        @unlink($path);
        return null;
    }

    foreach($tables as $table){
        $tableEsc = str_replace('`','``',$table);

        $createRes = $connection->query("SHOW CREATE TABLE `{$tableEsc}`");
        if($createRes){
            $c = $createRes->fetch_assoc();
            $createSql = $c['Create Table'] ?? null;
            if($createSql){
                fwrite($fh, "\nDROP TABLE IF EXISTS `{$tableEsc}`;\n");
                fwrite($fh, $createSql . ";\n");
            }
            $createRes->close();
        }

        $dataRes = $connection->query("SELECT * FROM `{$tableEsc}`", MYSQLI_USE_RESULT);
        if($dataRes){
            while($row = $dataRes->fetch_assoc()){
                $cols = array_keys($row);
                $vals = [];
                foreach($row as $v){
                    if($v === null) $vals[] = 'NULL';
                    else $vals[] = "'" . $connection->real_escape_string($v) . "'";
                }
                fwrite($fh, "INSERT INTO `{$tableEsc}` (`" . implode('`,`',$cols) . "`) VALUES (" . implode(',',$vals) . ");\n");
            }
            $dataRes->close();
        }
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    if(@file_exists($path) && @filesize($path) > 1024){
        $real = realpath($path);
        return $real ? $real : $path;
    }
    @unlink($path);
    return null;
}



// Create SQL dump of the current database (structure + data)
function dbCreateSqlBackup(){
    global $connection;
    $sql = "-- DeltaBot DB Backup\n-- Created at: " . date('c') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tablesRes = $connection->query("SHOW TABLES");
    if(!$tablesRes) return null;
    while($trow = $tablesRes->fetch_row()){
        $table = $trow[0];
        $createRes = $connection->query("SHOW CREATE TABLE `{$table}`");
        if($createRes){
            $c = $createRes->fetch_assoc();
            $createSql = $c['Create Table'] ?? null;
            if($createSql){
                $sql .= "\nDROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $createSql . ";\n";
            }
        }
        $dataRes = $connection->query("SELECT * FROM `{$table}`");
        if($dataRes){
            while($row = $dataRes->fetch_assoc()){
                $cols = array_keys($row);
                $vals = [];
                foreach($row as $v){
                    if($v === null){
                        $vals[] = 'NULL';
                    }else{
                        $vals[] = "'" . $connection->real_escape_string($v) . "'";
                    }
                }
                $sql .= "INSERT INTO `{$table}` (`" . implode('`,`',$cols) . "`) VALUES (" . implode(',',$vals) . ");\n";
            }
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

// Restore database from an SQL backup string
function dbRestoreFromSql($sql){
    global $connection;
    if(!is_string($sql) || strlen($sql) < 10) return false;
    // Split by ;\n but keep it simple; multi_query handles it.
    if(!$connection->multi_query($sql)){
        return false;
    }
    do {
        $connection->store_result();
    } while ($connection->more_results() && $connection->next_result());
    return true;
}
function getFileUrl($fileid){
    global $botToken;
    $filePath = bot('getFile',[
        'file_id'=>$fileid
    ])->result->file_path;
    return "https://api.telegram.org/file/bot" . $botToken . "/" . $filePath;
}
function alert($txt, $type = false, $callid = null){
    global $callbackId;
    $callid = $callid??$callbackId;
    return bot('answercallbackquery', [
        'callback_query_id' => $callid,
        'text' => $txt,
        'show_alert' => $type
    ]);
}

$range = [
        '149.154.160.0/22',
        '149.154.164.0/22',
        '91.108.4.0/22',
        '91.108.56.0/22',
        '91.108.8.0/22',
        '95.161.64.0/20',
    ];
// Get real client IP behind reverse proxies (Cloudflare / Nginx / etc.)
function get_client_ip(){
    $candidates = [];
    if(isset($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    if(isset($_SERVER['HTTP_X_REAL_IP'])) $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
    if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])){
        // may contain multiple IPs: client, proxy1, proxy2...
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach($parts as $p){
            $candidates[] = trim($p);
        }
    }
    if(isset($_SERVER['REMOTE_ADDR'])) $candidates[] = $_SERVER['REMOTE_ADDR'];
    foreach($candidates as $ip){
        if(filter_var($ip, FILTER_VALIDATE_IP)){
            return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}


function check($return = false){
    global $range;
    $ip = get_client_ip();
    // Optional: if you set Telegram webhook secret_token, verify it here
    if(isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) && isset($GLOBALS['botToken'])){
        $expected = hash('sha256', $GLOBALS['botToken']);
        if(hash_equals($expected, $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])){
            return true;
        }
    }
    foreach ($range as $rg) {
        if (ip_in_range($ip, $rg)) {
            return true;
        }
    }
    if ($return == true) {
        return false;
    }

    die('You do not have access');

}
function curl_get_file_contents($URL){
    $c = curl_init();
    curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($c, CURLOPT_URL, $URL);
    $contents = curl_exec($c);
    curl_close($c);

    if ($contents) return $contents;
    else return FALSE;
}

function ip_in_range($ip, $range){
    if (strpos($range, '/') == false) {
        $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list($range, $netmask) = explode('/', $range, 2);
    $range_decimal = ip2long($range);
    $ip_decimal = ip2long($ip);
    $wildcard_decimal = pow(2, (32 - $netmask)) - 1;
    $netmask_decimal = ~$wildcard_decimal;
    return (($ip_decimal & $netmask_decimal) == ($range_decimal & $netmask_decimal));
}

$time = time();
$rawUpdate = file_get_contents("php://input");
$update = json_decode($rawUpdate);
if(isset($update->message)){
    $from_id = $update->message->from->id;
    $text = $update->message->text;
    $first_name = htmlspecialchars($update->message->from->first_name);
    $caption = $update->message->caption;
    $chat_id = $update->message->chat->id;
    $last_name = htmlspecialchars($update->message->from->last_name);
    $username = $update->message->from->username?? " ندارد ";
    $message_id = $update->message->message_id;
    $forward_from_name = $update->message->reply_to_message->forward_sender_name;
    $forward_from_id = $update->message->reply_to_message->forward_from->id;
    $reply_text = $update->message->reply_to_message->text;
}
if(isset($update->callback_query)){
    $callbackId = $update->callback_query->id;
    $data = $update->callback_query->data;
    $text = $update->callback_query->message->text;
    $message_id = $update->callback_query->message->message_id;
    $chat_id = $update->callback_query->message->chat->id;
    $chat_type = $update->callback_query->message->chat->type;
    $username = htmlspecialchars($update->callback_query->from->username)?? " ندارد ";
    $from_id = $update->callback_query->from->id;
    $first_name = htmlspecialchars($update->callback_query->from->first_name);
    $markup = json_decode(json_encode($update->callback_query->message->reply_markup->inline_keyboard),true);
}
if($from_id < 0) exit();
$stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
$stmt->bind_param("i", $from_id);
$stmt->execute();
$uinfo = $stmt->get_result();
$userInfo = $uinfo->fetch_assoc();
$stmt->close();
 
$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
$stmt->execute();
$paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
else $paymentKeys = array();
$stmt->close();

$stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
$stmt->execute();
$botState = $stmt->get_result()->fetch_assoc()['value'];
if(!is_null($botState)) $botState = json_decode($botState,true);
else $botState = array();
$stmt->close();

$botState = array_merge([
    'groupBuyState' => 'off',
    'rebuyLastServiceState' => 'off',
    'adminApprovalState' => 'off',
], is_array($botState) ? $botState : []);

// Apply custom button labels / texts (admin editable)
$stmt = $connection->prepare("SELECT `type`,`value` FROM `setting` WHERE `type` LIKE 'BUTTON_LABEL_%' OR `type` LIKE 'MAINVALUE_%'");
$stmt->execute();
$customList = $stmt->get_result();
$stmt->close();
if($customList->num_rows > 0){
    while($row = $customList->fetch_assoc()){
        $t = $row['type'];
        $v = $row['value'];
        if(strpos($t,'BUTTON_LABEL_') === 0){
            $k = str_replace('BUTTON_LABEL_','',$t);
            if(isset($buttonValues[$k])) $buttonValues[$k] = $v;
        }elseif(strpos($t,'MAINVALUE_') === 0){
            $k = str_replace('MAINVALUE_','',$t);
            if(isset($mainValues[$k])) $mainValues[$k] = $v;
        }
    }
}

$channelLock = $botState['lockChannel'];
$joniedState= bot('getChatMember', ['chat_id' => $channelLock,'user_id' => $from_id])->result->status;

if ($update->message->document->file_id) {
    $filetype = 'document';
    $fileid = $update->message->document->file_id;
} elseif ($update->message->audio->file_id) {
    $filetype = 'music';
    $fileid = $update->message->audio->file_id;
} elseif ($update->message->photo[0]->file_id) {
    $filetype = 'photo';
    $fileid = $update->message->photo->file_id;
    if (isset($update->message->photo[2]->file_id)) {
        $fileid = $update->message->photo[2]->file_id;
    } elseif ($fileid = $update->message->photo[1]->file_id) {
        $fileid = $update->message->photo[1]->file_id;
    } else {
        $fileid = $update->message->photo[1]->file_id;
    }
} elseif ($update->message->voice->file_id) {
    $filetype = 'voice';
    $voiceid = $update->message->voice->file_id;
} elseif ($update->message->video->file_id) {
    $filetype = 'video';
    $fileid = $update->message->video->file_id;
}

$cancelKey=json_encode(['keyboard'=>[
    [['text'=>$buttonValues['cancel']]]
],'resize_keyboard'=>true]);
$removeKeyboard = json_encode(['remove_keyboard'=>true]);



function forwardUpdateToVpsbot($raw){
    // Forwards current Telegram update JSON to the local VPSBot bridge server.
    // Returns an associative array:
    //   ['ok'=>bool,'http_code'=>int,'resp'=>string,'error'=>string]
    $url = "http://127.0.0.1:9010/update";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // More tolerant timeouts (first VPSBot call may be slower)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if($resp === false){
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'ok' => false,
            'http_code' => 0,
            'resp' => '',
            'error' => 'curl_error: ' . $err,
        ];
    }

    curl_close($ch);

    // If bridge returned non-2xx, treat as failure.
    if($http < 200 || $http >= 300){
        return [
            'ok' => false,
            'http_code' => $http,
            'resp' => (string)$resp,
            'error' => 'http_error: ' . $http,
        ];
    }

    // Some bridge implementations may return empty body on success; that's OK.
    return [
        'ok' => true,
        'http_code' => $http,
        'resp' => (string)$resp,
        'error' => '',
    ];
}

function getMainKeys(){
    global $connection, $userInfo, $from_id, $admin, $botState, $buttonValues;

    // Layout settings (admin configurable)
    $cols = (int)getSettingValue("MAIN_MENU_COLUMNS","2");
    if($cols < 1) $cols = 1;
    if($cols > 3) $cols = 3;

    // If enabled, swap the order of some 2-button rows (RTL-friendly)
    $swapBuy = getSettingValue("MAIN_MENU_SWAP_BUY","0") === "1";
    $swapServices = getSettingValue("MAIN_MENU_SWAP_SERVICES","0") === "1";

    $mainKeys = array();
    $temp = array();

    if($botState['agencyState'] == "on" && $userInfo['is_agent'] == 1){
        $mainKeys = array_merge($mainKeys, [
            [['text'=>$buttonValues['agency_setting'],'callback_data'=>"agencySettings"]],
            [['text'=>$buttonValues['agent_one_buy'],'callback_data'=>"agentOneBuy"],['text'=>$buttonValues['agent_much_buy'],'callback_data'=>"agentMuchBuy"]],
            [['text'=>$buttonValues['my_subscriptions'],'callback_data'=>"agentConfigsList"]],
            ]);
    }else{
        $mainKeys = array_merge($mainKeys,[
            (($botState['agencyState'] == "on" && $userInfo['is_agent'] == 0)?[
                ['text'=>$buttonValues['request_agency'],'callback_data'=>"requestAgency"]
                ]:
                []),
            (($botState['sellState'] == "on" || $from_id == $admin || $userInfo['isAdmin'] == true)?
                [['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'mySubscriptions'],['text'=>$buttonValues['buy_subscriptions'],'callback_data'=>"buySubscription"]]
                :
                [['text'=>$buttonValues['my_subscriptions'],'callback_data'=>'mySubscriptions']]
                    )
            ]);
    }
    $mainKeys = array_merge($mainKeys,[
        (
            ($botState['testAccount'] == "on")?[['text'=>$buttonValues['test_account'],'callback_data'=>"getTestAccount"]]:
                []
            ),
        ((($botState['inviteButton']??'on') == 'on')?
        [['text'=>$buttonValues['invite_friends'],'callback_data'=>"inviteFriends"],['text'=>$buttonValues['my_info'],'callback_data'=>"myInfo"]]
        :
        [['text'=>$buttonValues['my_info'],'callback_data'=>"myInfo"]]
    ),
        (($botState['walletState'] ?? 'on') == 'on' ?
            [['text'=>$buttonValues['increase_wallet'],'callback_data'=>"increaseMyWallet"]]
            :[]
        ),
        (($botState['sharedExistence'] == "on" && $botState['individualExistence'] == "on")?
        [['text'=>$buttonValues['shared_existence'],'callback_data'=>"availableServers"],['text'=>$buttonValues['individual_existence'],'callback_data'=>"availableServers2"]]:[]),
        (($botState['sharedExistence'] == "on" && $botState['individualExistence'] != "on")?
            [['text'=>$buttonValues['shared_existence'],'callback_data'=>"availableServers"]]:[]),
        (($botState['sharedExistence'] != "on" && $botState['individualExistence'] == "on")?
            [['text'=>$buttonValues['individual_existence'],'callback_data'=>"availableServers2"]]:[]
        ),
        (($botState['searchState']=="on" || $from_id == $admin || $userInfo['isAdmin'] == true)?
            [['text'=>$buttonValues['search_config'],'callback_data'=>"showUUIDLeft"]]
            :[]),
    ]);

    // --- Custom MAIN_BUTTONS
// Reseller bot shop + my bots (only on main bot)
global $isChildBot;

// Reseller shop buttons
if(!$isChildBot && (($botState['resellerBotsButtonState'] ?? 'on') == 'on')){
    $mainKeys = array_merge($mainKeys,[
        [['text'=>$buttonValues['reseller_bot_shop'],'callback_data'=>"resellerShop"]],
    ]);
}
if(!$isChildBot && (($botState['myResellerBotsButtonState'] ?? 'on') == 'on')){
    $mainKeys = array_merge($mainKeys,[
        [['text'=>$buttonValues['my_reseller_bots'],'callback_data'=>"myResellerBots"]],
    ]);
}

// Cloud VPS buy button (ONLY on mother bot) + controlled by Bot Settings toggle
if(!$isChildBot && (($botState['cloudVpsPanelState'] ?? 'on') == 'on')){
    $mainKeys = array_merge($mainKeys,[
        [['text'=>$buttonValues['cloud_vps_buy'],'callback_data'=>"vpsbotStart"]],
    ]);
}

// (apply saved order + columns)
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();

    $items = [];
    if($buttons && $buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            if(strpos($row['type'],"MAIN_BUTTONS")===0){
                $items[] = ['id'=>(int)$row['id'], 'title'=>str_replace("MAIN_BUTTONS","",$row['type'])];
            }
        }
    }

    // load saved custom order
    $orderVal = getSettingValue("MAIN_BUTTONS_ORDER", null);
    $order = $orderVal ? json_decode($orderVal, true) : [];
    if(!is_array($order)) $order = [];

    // map + ordered merge
    $map = [];
    foreach($items as $it){ $map[(int)$it['id']] = $it; }
    $ordered = [];
    foreach($order as $oid){
        $oid = (int)$oid;
        if(isset($map[$oid])){
            $ordered[] = $map[$oid];
            unset($map[$oid]);
        }
    }
    foreach($map as $it){ $ordered[] = $it; }

    // push to keyboard with $cols columns
    $temp = [];
    foreach($ordered as $it){
        $temp[] = ['text'=>$it['title'], 'callback_data'=>"showMainButtonAns".$it['id']];
        if(count($temp) >= $cols){
            $mainKeys[] = $temp;
            $temp = [];
        }
    }
    if(count($temp) > 0) $mainKeys[] = $temp;



// --- Apply global MAIN_MENU_ORDER (built-in + custom buttons)
$menuOrderVal = getSettingValue("MAIN_MENU_ORDER", null);
$menuOrder = $menuOrderVal ? json_decode($menuOrderVal, true) : [];
if(!is_array($menuOrder)) $menuOrder = [];

if(count($menuOrder) > 0){
    // Flatten current menu (excluding placeholders)
    $flatBtns = [];
    foreach($mainKeys as $r){
        if(!is_array($r)) continue;
        foreach($r as $b){
            $cb = $b['callback_data'] ?? '';
            if($cb === '' || $cb === 'deltach') continue;
            // keep managePanel fixed at the end (not sortable here)
            if($cb === 'managePanel') continue;
            $flatBtns[] = $b;
        }
    }
    // Map by callback_data
    $cbMap = [];
    foreach($flatBtns as $b){
        $cb = $b['callback_data'];
        if(!isset($cbMap[$cb])) $cbMap[$cb] = $b;
    }
    $orderedFlat = [];
    foreach($menuOrder as $cb){
        $cb = (string)$cb;
        if(isset($cbMap[$cb])){
            $orderedFlat[] = $cbMap[$cb];
            unset($cbMap[$cb]);
        }
    }
    // Append remaining in original order
    foreach($flatBtns as $b){
        $cb = $b['callback_data'];
        if(isset($cbMap[$cb])){
            $orderedFlat[] = $cbMap[$cb];
            unset($cbMap[$cb]);
        }
    }

    // Rebuild rows based on selected columns
    $mainKeys = [];
    $tmpRow = [];
    foreach($orderedFlat as $b){
        $tmpRow[] = $b;
        if(count($tmpRow) >= $cols){
            $mainKeys[] = $tmpRow;
            $tmpRow = [];
        }
    }
    if(count($tmpRow) > 0) $mainKeys[] = $tmpRow;

    // If explicit order is set, ignore swap toggles (order is authoritative)
    $swapBuy = false;
    $swapServices = false;
}

    // swap specific rows (buy vs my, services)
    foreach($mainKeys as &$row){
        if(!is_array($row) || count($row) < 2) continue;
        $cbs = [];
        foreach($row as $b){ $cbs[] = $b['callback_data'] ?? ''; }

        if($swapBuy && in_array('mySubscriptions',$cbs,true) && in_array('buySubscription',$cbs,true)){
            $row = array_reverse($row);
        }
        if($swapServices && in_array('availableServers',$cbs,true) && in_array('availableServers2',$cbs,true)){
            $row = array_reverse($row);
        }
    }
    unset($row);

    // For 2/3 columns, re-flow the entire menu so admin-selected columns actually apply
    // to built-in rows too (not just custom buttons).
    if($cols > 1){
        $flat = [];
        foreach($mainKeys as $r){
            if(!is_array($r)) continue;
            foreach($r as $btn){
                $flat[] = $btn;
            }
        }
        $reflow = [];
        for($i=0; $i<count($flat); $i += $cols){
            $reflow[] = array_slice($flat, $i, $cols);
        }
        $mainKeys = $reflow;
    }

    // if 1 column selected, split any multi-button rows to single-button rows
    if($cols == 1){
        $oneCol = [];
        foreach($mainKeys as $row){
            if(!is_array($row) || count($row) == 0) continue;
            if(count($row) == 1){
                $oneCol[] = $row;
            }else{
                foreach($row as $btn){
                    $oneCol[] = [$btn];
                }
            }
        }
        $mainKeys = $oneCol;
    }

    if($from_id == $admin || $userInfo['isAdmin'] == true){
        $mainKeys[] = [['text'=>"مدیریت ربات ⚙️",'callback_data'=>"managePanel"]];
    }

    return json_encode(['inline_keyboard'=>$mainKeys]);
}
function getAgentKeys(){
    global $buttonValues, $mainValues, $from_id, $userInfo, $connection;
    $agencyDate = jdate("Y-m-d H:i:s",$userInfo['agent_date']);
    $joinedDate = jdate("Y-m-d H:i:s",$userInfo['date']);
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid` = ? AND `agent_bought` = 1");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $boughtAccounts = $stmt->get_result()->num_rows;
    $stmt->close();
    
    return json_encode(['inline_keyboard'=>[
        [['text'=>$boughtAccounts,'callback_data'=>"deltach"],['text'=>$buttonValues['agent_bought_accounts'],'callback_data'=>"deltach"]],
        [['text'=>$joinedDate,'callback_data'=>"deltach"],['text'=>$buttonValues['agent_joined_date'],'callback_data'=>"deltach"]],
        [['text'=>$agencyDate,'callback_data'=>"deltach"],['text'=>$buttonValues['agent_agency_date'],'callback_data'=>"deltach"]],
        [['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"]],
    ]]);
}
function getAdminKeys(){
    global $buttonValues, $from_id, $admin, $isChildBot;

    // Base buttons (2-column layout)
    $rows = [];

    // Reports
    $rows[] = [
        ['text'=>$buttonValues['bot_reports'],'callback_data'=>"botReports"],
        ['text'=>$buttonValues['user_reports'],'callback_data'=>"userReports"],
    ];

    // Users list + Discount users
    $rows[] = [
        ['text'=>'👥 لیست کاربران','callback_data'=>'adminUsersList0'],
        ['text'=>'٪ درصدی‌ها','callback_data'=>'adminDiscountUsers0'],
    ];

    // Banned users
    $rows[] = [
        ['text'=>'⛔️ لیست مسدود شده‌ها','callback_data'=>'adminBannedUsers0'],
    ];

    // Admins list (only owner) + Agents list
    if($from_id == $admin){
        $rows[] = [
            ['text'=>$buttonValues['admins_list'],'callback_data'=>"adminsList"],
            ['text'=>$buttonValues['agent_list'],'callback_data'=>"agentsList"],
        ];
    }else{
        $rows[] = [
            ['text'=>$buttonValues['agent_list'],'callback_data'=>"agentsList"],
            ['text'=>' ','callback_data'=>"deltach"],
        ];
    }

    // New grouped sections
    $rows[] = [
        ['text'=>'🧩 مدیریت پنل‌ها','callback_data'=>"managePanels"],
        ['text'=>'⚙️ تنظیمات عمومی','callback_data'=>"generalSettings"],
    ];

    // Reseller bots management (only on main bot)
    if(empty($isChildBot)){
        $rows[] = [
            ['text'=>'🤖 ربات‌ها','callback_data'=>'adminResellerBots'],
            ['text'=>'🗄 بکاپ','callback_data'=>'adminBackupMenu'],
        ];
    }else{
        $rows[] = [
            ['text'=>'🗄 بکاپ','callback_data'=>'adminBackupMenu'],
            ['text'=>' ','callback_data'=>'deltach'],
        ];
    }

    // QRCode image (one-column button) for BOTH mother + reseller bots
    $rows[] = [
        ['text'=>'🖼 تغییر تصویر QRCODE','callback_data'=>'adminChangeQrImage'],
    ];

// Main menu buttons management + Bot settings
    $rows[] = [
        ['text'=>$buttonValues['main_button_settings'],'callback_data'=>"mainMenuButtons"],
        ['text'=>$buttonValues['bot_settings'],'callback_data'=>'botSettings'],
    ];

    // Back
    $rows[] = [
        ['text'=>$buttonValues['back_to_main'],'callback_data'=>"mainMenu"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];

    // Remove placeholders from last row if desired (Telegram allows, but we keep 2 columns consistently)
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}


function normalizeKeyboard($rows){
    $out = [];
    foreach($rows as $row){
        if(!is_array($row)) continue;
        $newRow = [];
        foreach($row as $btn){
            if(!is_array($btn)) continue;
            $t = isset($btn['text']) ? trim((string)$btn['text']) : '';
            $cb = $btn['callback_data'] ?? '';
            // drop placeholder / empty buttons
            if($t === '' || $t === 'ㅤ' || $t === ' '){
                if($cb === 'deltach' || $cb === '' || $cb === null){
                    continue;
                }
            }
            $newRow[] = $btn;
        }
        if(count($newRow) > 0){
            $out[] = $newRow;
        }
    }
    return $out;
}

function getPanelManagementKeys(){
    global $buttonValues;
    $rows = [];
    $rows[] = [
        ['text'=>$buttonValues['server_settings'],'callback_data'=>"serversSetting"],
        ['text'=>$buttonValues['categories_settings'],'callback_data'=>"categoriesSetting"],
    ];
    
    // Cloud VPS management (ONLY on mother bot, and only when enabled in bot settings)
    global $isChildBot, $botState;
    if(!$isChildBot && (($botState['cloudVpsPanelState'] ?? 'on') == 'on')){
        $rows[] = [
            ['text'=>$buttonValues['cloud_vps_manage'],'callback_data'=>"vpsbotAdminEntry"],
        ];
    }
$rows[] = [
        ['text'=>$buttonValues['plan_settings'],'callback_data'=>"backplan"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];
    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"managePanel"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}

function getGeneralSettingsKeys(){
    global $buttonValues;
    $rows = [];

    $rows[] = [
        ['text'=>$buttonValues['gift_volume_day'],'callback_data'=>"giftVolumeAndDay"],
        ['text'=>$buttonValues['create_account'],'callback_data'=>"createMultipleAccounts"],
    ];

    $rows[] = [
        ['text'=>$buttonValues['discount_settings'],'callback_data'=>"discount_codes"],
        ['text'=>$buttonValues['gateways_settings'],'callback_data'=>"gateWays_Channels"],
    ];

    $rows[] = [
        ['text'=>$buttonValues['message_to_all'],'callback_data'=>"message2All"],
        ['text'=>$buttonValues['forward_to_all'],'callback_data'=>"forwardToAll"],
    ];

    $rows[] = [
        ['text'=>'درخواست های رد شده','callback_data'=>"rejectedAgentList"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];

    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"managePanel"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];

    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}


// ---------------- Admin: Users list + discount users
function getAdminUsersListKeys($offset=0){
    global $connection, $buttonValues;
    $offset = (int)$offset;
    if($offset < 0) $offset = 0;
    $limit = 20;

    $stmt = $connection->prepare("SELECT `userid`,`name` FROM `users` ORDER BY `id` DESC LIMIT ?,?");
    $stmt->bind_param("ii", $offset, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    $temp = [];
    while($u = $res->fetch_assoc()){
        $uid = (string)$u['userid'];
        $name = trim((string)$u['name']);
        if($name === '') $name = 'بدون نام';
        $temp[] = ['text'=>"👤 {$name} ({$uid})",'callback_data'=>"adminUser_{$uid}_{$offset}"];
        if(count($temp) >= 2){
            $rows[] = $temp;
            $temp = [];
        }
    }
    if(count($temp) > 0) $rows[] = $temp;

    // nav
    $nav = [];
    if($offset > 0){
        $prev = max(0, $offset-$limit);
        $nav[] = ['text'=>"⬅️ قبلی",'callback_data'=>"adminUsersList{$prev}"];
    }
    // detect next
    $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM `users`");
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total = (int)($cnt['c'] ?? 0);
    if(($offset + $limit) < $total){
        $next = $offset + $limit;
        $nav[] = ['text'=>"بعدی ➡️",'callback_data'=>"adminUsersList{$next}"];
    }
    if(count($nav)>0) $rows[] = $nav;

    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"managePanel"],
    ];
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}

function getAdminBannedUsersListKeys($offset=0){
    global $connection;
    $offset = (int)$offset;
    if($offset < 0) $offset = 0;
    $limit = 20;

    $stmt = $connection->prepare("SELECT `userid`,`name` FROM `users` WHERE `step`='banned' ORDER BY `id` DESC LIMIT ?,?");
    $stmt->bind_param("ii", $offset, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    while($u = $res->fetch_assoc()){
        $uid = (string)$u['userid'];
        $name = trim((string)$u['name']);
        if($name === '') $name = 'بدون نام';
        $rows[] = [
            ['text'=>"🚫 {$name} ({$uid})",'callback_data'=>"adminBannedUser_{$uid}_{$offset}"],
            ['text'=>'✅ آزادسازی','callback_data'=>"uUnban{$uid}"],
        ];
    }

    // nav
    $nav = [];
    if($offset > 0){
        $prev = max(0, $offset-$limit);
        $nav[] = ['text'=>"⬅️ قبلی",'callback_data'=>"adminBannedUsers{$prev}"];
    }
    $stmt = $connection->prepare("SELECT COUNT(*) AS c FROM `users` WHERE `step`='banned'");
    $stmt->execute();
    $cnt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total = (int)($cnt['c'] ?? 0);
    if(($offset + $limit) < $total){
        $next = $offset + $limit;
        $nav[] = ['text'=>"بعدی ➡️",'callback_data'=>"adminBannedUsers{$next}"];
    }
    if(count($nav)>0) $rows[] = $nav;

    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"managePanel"],
    ];

    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}

function getAdminDiscountUsersKeys($offset=0){
    global $connection;
    $offset = (int)$offset;
    if($offset < 0) $offset = 0;
    $limit = 20;

    // Read per-user discounts from `setting` table (keys like USER_DISCOUNT_<userid>)
    $stmt = $connection->prepare("SELECT `type`,`value` FROM `setting` WHERE `type` LIKE 'USER_DISCOUNT_%' AND `value` IS NOT NULL AND `value` != '' AND `value` != '0' ORDER BY `id` DESC");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $all = [];
    while($r = $res->fetch_assoc()){
        $type = (string)($r['type'] ?? '');
        if(strpos($type, 'USER_DISCOUNT_') !== 0) continue;
        $uid = (int)substr($type, strlen('USER_DISCOUNT_'));
        if($uid <= 0) continue;
        $p = (int)($r['value'] ?? 0);
        if($p <= 0) continue;
        if($p > 100) $p = 100;
        $all[] = ['uid'=>$uid, 'p'=>$p];
    }

    $total = count($all);
    $slice = array_slice($all, $offset, $limit);

    // prepared statement for user name lookup (keep it simple / safe)
    $nameStmt = $connection->prepare("SELECT `name` FROM `users` WHERE `userid`=? LIMIT 1");

    $rows = [];
    $temp = [];
    foreach($slice as $it){
        $uid = (int)$it['uid'];
        $p = (int)$it['p'];

        $name = 'بدون نام';
        $uidStr = (string)$uid;
        $nameStmt->bind_param("s", $uidStr);
        $nameStmt->execute();
        $r2 = $nameStmt->get_result();
        if($r2 && $r2->num_rows>0){
            $nm = trim((string)$r2->fetch_assoc()['name']);
            if($nm !== '') $name = $nm;
        }

        $temp[] = ['text'=>"👤 {$name} ({$uid}) — {$p}%",'callback_data'=>"adminUser_{$uid}_disc{$offset}"];
        if(count($temp) >= 1){
            $rows[] = $temp;
            $temp = [];
        }
    }
    if($nameStmt) $nameStmt->close();
    if(count($temp) > 0) $rows[] = $temp;

    // nav
    $nav = [];
    if($offset > 0){
        $prev = max(0, $offset-$limit);
        $nav[] = ['text'=>"⬅️ قبلی",'callback_data'=>"adminDiscountUsers{$prev}"];
    }
    if(($offset + $limit) < $total){
        $next = $offset + $limit;
        $nav[] = ['text'=>"بعدی ➡️",'callback_data'=>"adminDiscountUsers{$next}"];
    }
    if(count($nav)>0) $rows[] = $nav;

    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"managePanel"],
    ];
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}


function getAdminUserDetailsText($userId){
    global $connection;
    $uid = (string)$userId;

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? LIMIT 1");
    $stmt->bind_param("s", $uid);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$u) return "کاربر یافت نشد";

    $name = $u['name'] ?? '';
    $username = $u['username'] ?? '';
    $wallet = (int)($u['wallet'] ?? 0);
    $isAdmin = ((int)($u['isAdmin'] ?? 0)) === 1 ? "✅" : "❌";
    $isAgent = ((int)($u['is_agent'] ?? 0)) === 1 ? "✅" : "❌";
    $date = $u['date'] ?? '';
    $phone = $u['phone'] ?? '-';

    $normal = (int)getSettingValue("USER_DISCOUNT_" . (int)$uid, "0");
if($normal < 0) $normal = 0;
if($normal > 100) $normal = 100;
$lines = [];
    $lines[] = "👤 مشخصات کاربر";
    $lines[] = "";
    $lines[] = "🆔 آیدی عددی: <code>{$uid}</code>";
    $lines[] = "👤 نام: " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $lines[] = "🔗 یوزرنیم: @" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $lines[] = "💰 کیف پول: <b>" . number_format($wallet) . "</b> تومان";
    $lines[] = "🛡 ادمین: {$isAdmin}";
    $lines[] = "🤝 نماینده: {$isAgent}";
    $lines[] = "📅 تاریخ: " . htmlspecialchars($date, ENT_QUOTES, 'UTF-8');
    $lines[] = "📞 شماره: " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $lines[] = "٪ تخفیف (نرمال): <b>{$normal}%</b>";
    return implode("\n", $lines);
}

function getAdminUserDetailsKeys($backCb){
    $rows = [
        [
            ['text'=>"🔙 بازگشت",'callback_data'=>$backCb],
        ]
    ];
    return json_encode(['inline_keyboard'=>normalizeKeyboard($rows)], 488);
}


function getUserConfigsListKeys($userId, $offset=0){
    global $connection;
    $userId = (int)$userId;
    $offset = (int)$offset;
    $limit = 8;

    $stmt = $connection->prepare("SELECT o.`id`,o.`amount`,o.`remark`,o.`date`,s.`title` AS `server_title` FROM `orders_list` o LEFT JOIN `server_info` s ON o.`server_id`=s.`id` WHERE o.`userid`=? ORDER BY `id` DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("iii", $userId, $limit, $offset);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    $items = [];
    while($row = $res->fetch_assoc()){
        $oid = (int)$row['id'];
        $amount = isset($row['amount']) ? number_format((int)$row['amount']) . "ت" : "";
        $remark = trim((string)($row['remark'] ?? ''));
        if($remark === '') $remark = 'بدون عنوان';
        $srv = trim((string)($row["server_title"] ?? ""));
        if($srv === "") $srv = "بدون سرویس";
        $text = "#$oid | $srv | $amount";
        $items[] = ['text'=>$text,'callback_data'=>"userOrderDetails{$oid}_0"];
    }

    if(count($items) == 0){
        $rows[] = [
            ['text'=>"کانفیگی یافت نشد",'callback_data'=>"deltach"],
            ['text'=>' ','callback_data'=>"deltach"],
        ];
    }else{
        // two columns
        $rows = array_merge($rows, array_chunk($items, 2));
    }

    // Pagination
    $nav = [];
    if($offset > 0){
        $prev = max(0, $offset-$limit);
        $nav[] = ['text'=>"⬅️ قبلی",'callback_data'=>"uConfigs{$userId}_{$prev}"];
    }
    if(count($items) == $limit){
        $next = $offset + $limit;
        $nav[] = ['text'=>"بعدی ➡️",'callback_data'=>"uConfigs{$userId}_{$next}"];
    }
    if(count($nav) > 0){
        // keep 2 columns
        if(count($nav)==1) $nav[] = ['text'=>' ','callback_data'=>"deltach"];
        $rows[] = $nav;
    }

    $rows[] = [
        ['text'=>"🔙 بازگشت",'callback_data'=>"uRefresh{$userId}"],
        ['text'=>' ','callback_data'=>"deltach"],
    ];
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}


function getUserConfigsSearchResultKeys($userId, $query){
    global $connection;
    $userId = (int)$userId;
    $q = trim((string)$query);
    if($q === '') $q = ' ';
    $limit = 20;

    $stmt = $connection->prepare("SELECT o.`id`,o.`amount`,s.`title` AS `server_title` FROM `orders_list` o LEFT JOIN `server_info` s ON o.`server_id`=s.`id` WHERE o.`userid`=? AND o.`remark` LIKE CONCAT('%', ?, '%') ORDER BY o.`id` DESC LIMIT ?");
    $stmt->bind_param("isi", $userId, $q, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    $rows = [];
    $items = [];
    while($row = $res->fetch_assoc()){
        $oid = (int)$row['id'];
        $amount = isset($row['amount']) ? number_format((int)$row['amount']) . "ت" : "";
        $srv = trim((string)($row["server_title"] ?? ""));
        if($srv === "") $srv = "بدون سرویس";
        $items[] = ['text'=>"#$oid | $srv | $amount",'callback_data'=>"userOrderDetails{$oid}_0"];
    }
    if(count($items)==0){
        $rows[] = [[ 'text'=>"چیزی پیدا نشد",'callback_data'=>"deltach" ]];
    } else {
        $rows = array_merge($rows, array_chunk($items, 2));
    }
    $rows[] = [[ 'text'=>"🔙 بازگشت",'callback_data'=>"uConfigs{$userId}_0" ]];
    $rows = normalizeKeyboard($rows);
    return json_encode(['inline_keyboard'=>$rows], 488);
}

function setSettings($field, $value){
    global $connection, $botState;
    $botState[$field]= $value;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $isExists = $stmt->get_result();
    $stmt->close();
    if($isExists->num_rows>0) $query = "UPDATE `setting` SET `value` = ? WHERE `type` = 'BOT_STATES'";
    else $query = "INSERT INTO `setting` (`type`, `value`) VALUES ('BOT_STATES', ?)";
    $newData = json_encode($botState);
    
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $newData);
    $stmt->execute();
    $stmt->close();
}
function getRejectedAgentList(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 2");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    
    if($list->num_rows>0){
        $keys = array();
        $keys[] = [['text'=>"آزاد ساختن",'callback_data'=>"deltach"],['text'=>"اسم کاربر",'callback_data'=>'deltach'],['text'=>"آیدی عددی",'callback_data'=>"deltach"]];
        while($row = $list->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            
            $keys[] = [['text'=>"✅",'callback_data'=>"releaseRejectedAgent" . $userId],['text'=>$fullName,'callback_data'=>"deltach"],['text'=>$userId,'callback_data'=>"deltach"]];
        }
        $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
        return json_encode(['inline_keyboard'=>$keys]);
    }else return null;
}
function getAgentDetails($userId){
    global $connection, $mainVAlues, $buttonValues;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ? AND `is_agent` = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $agentDetail = $stmt->get_result();
    $stmt->close();


    $today = strtotime("today");
    $yesterday = strtotime("yesterday");
    $lastWeek = strtotime("last week");
    $lastMonth = strtotime("last month");

    $stmt = $connection->prepare("SELECT COUNT(`id`) AS `count`, SUM(`amount`) AS `total` FROM `orders_list` WHERE `date` >= ? AND `agent_bought` = 1 AND `userid` = ?");
    
    $stmt->bind_param("ii", $today, $userId);
    $stmt->execute();
    $todayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $yesterday, $userId);
    $stmt->execute();
    $yesterdayIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastWeek, $userId);
    $stmt->execute();
    $lastWeekIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->bind_param("ii", $lastMonth, $userId);
    $stmt->execute();
    $lastMonthIncome = $stmt->get_result()->fetch_assoc();
    
    $stmt->close();
    
    
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"(" . $todayIncome['count'] . ") " . number_format($todayIncome['total']),'callback_data'=>'deltach'],
            ['text'=>"درآمد امروز",'callback_data'=>'deltach']
            ],
        [
            ['text'=>"(" . $yesterdayIncome['count'] . ") " . number_format($yesterdayIncome['total']),'callback_data'=>"deltach"],
            ['text'=>"درآمد دیروز",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>"(" . $lastWeekIncome['count'] . ") " . number_format($lastWeekIncome['total']),'callback_data'=>"deltach"],
            ['text'=>"درآمد یک هفته",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>"(" . $lastMonthIncome['count'] . ") " . number_format($lastMonthIncome['total']),'callback_data'=>"deltach"],
            ['text'=>"درآمد یک ماه",'callback_data'=>"deltach"]
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]]
        ]]);
}
function checkSpam(){
    global $connection, $from_id, $userInfo, $admin;
    
    if($userInfo != null && $from_id != $admin){
        $spamInfo = json_decode($userInfo['spam_info'],true)??array();
        $spamDate = $spamInfo['date'];
        if(isset($spamInfo['banned'])){
            if(time() <= $spamInfo['banned']) return $spamInfo['banned'];
        }
        
        if(time() <= $spamDate) $spamInfo['count'] += 1;
        else{
            $spamInfo['count'] = 1;
            $spamInfo['date'] = strtotime("+1 minute");
        }
        if($spamInfo['count'] >= 50){
            $spamInfo['banned'] = strtotime("+1 day");
        }
        $spamInfo = json_encode($spamInfo);
        
        $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
        $stmt->bind_param("si", $spamInfo, $from_id);
        $stmt->execute();
        $stmt->close();
    }else return null;
}
function getAgentsList($offset = 0){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $agentList = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($agentList->num_rows == 0 && $offset == 0) return null;
    
    $keys[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"درصد تخفیف",'callback_data'=>"deltach"],['text'=>"تاریخ نمایندگی",'callback_data'=>"deltach"],['text'=>"اسم نماینده",'callback_data'=>"deltach"],['text'=>"آیدی عددی",'callback_data'=>"deltach"]];
    if($agentList->num_rows > 0){
        while($row = $agentList->fetch_assoc()){
            $userId = $row['userid'];
            
            $userDetail = bot('getChat',['chat_id'=>$userId])->result;
            $userUserName = $userDetail->username;
            $fullName = $userDetail->first_name . " " . $userDetail->last_name;
            $joinedDate = jdate("Y-m-d H:i",$row['agent_date']);

            $keys[] = [['text'=>"❌",'callback_data'=>"removeAgent" . $userId],['text'=>"⚙️",'callback_data'=>"agentPercentDetails" . $userId],['text'=>$joinedDate,'callback_data'=>"deltach"],['text'=>$fullName,'callback_data'=>"agentDetails" . $userId],['text'=>$userId,'callback_data'=>"agentDetails" . $userId]];
        }
    }
    if($offset == 0 && $limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)]
            ];
    elseif($limit <= $agentList->num_rows)
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextAgentList" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
    elseif($offset != 0)
        $keys[] = [
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextAgentList" . ($offset - $limit)]
            ];
            
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getAgentDiscounts($agentId){
    global $connection, $mainValues, $buttonValues, $botState;
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `is_agent` = 1 AND `userid` = ?");
    $stmt->bind_param("i", $agentId);
    $stmt->execute();
    $agentInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $keys = array();
    
    $discounts = json_decode($agentInfo['discount_percent'],true);

    $normal = $discounts['normal'];
    $keys[] = [['text'=>" ",'callback_data'=>"deltach"],
    ['text'=>$normal . "%",'callback_data'=>"editAgentDiscountNormal" . $agentId . "_0"],
    ['text'=>"عمومی",'callback_data'=>"deltach"]];            
    
    if($botState['agencyPlanDiscount']=="on"){
        foreach($discounts['plans'] as $planId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
            $stmt->bind_param('i', $planId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `id` = ?");
            $stmt->bind_param("i", $info['catid']);
            $stmt->execute();
            $catInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentPlan" . $agentId . "_" . $planId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountPlan" . $agentId . "_" . $planId],
            ['text'=>$info['title'] . " " . $catInfo['title'],'callback_data'=>"deltach"]];            
        }
    }else{
        foreach($discounts['servers'] as $serverId=>$discount){
            $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id` = ?");
            $stmt->bind_param('i', $serverId);
            $stmt->execute();
            $info = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $keys[] = [['text'=>"❌",'callback_data'=>"removePercentOfAgentServer" . $agentId . "_" . $serverId],
            ['text'=>$discount . "%",'callback_data'=>"editAgentDiscountServer" . $agentId . "_" . $serverId],
            ['text'=>$info['title'],'callback_data'=>"deltach"]];            
        }                
    }
    if($botState['agencyPlanDiscount']=="on")$keys[] = [['text' => "افزودن تخفیف پلن", 'callback_data' => "addDiscountPlanAgent" . $agentId]];
    else $keys[] = [['text' => "افزودن تخفیف سرور", 'callback_data' => "addDiscountServerAgent" . $agentId]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "agentsList"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function NOWPayments($method, $endpoint, $datas = []){
    global $paymentKeys;

    $base_url = 'https://api.nowpayments.io/v1/';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    switch ($method) {
        case 'GET':
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment']]);
            if(!empty($datas)) {
                if(is_array($datas)) {
                    $parameters = http_build_query($datas);
                    curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint . '?' . $parameters);
                } else {
                    if($endpoint == 'payment') curl_setopt($ch, CURLOPT_URL,$base_url . $endpoint . '/' . $datas);
                }
            } else {
                curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            }
            break;

        case 'POST':
            $datas = json_encode($datas);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-API-KEY: ' . $paymentKeys['nowpayment'], 'Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $datas);
            curl_setopt($ch, CURLOPT_URL, $base_url . $endpoint);
            break;

        default:
            break;
    }

    $res = curl_exec($ch);
    
    if(curl_error($ch)) var_dump(curl_error($ch));
    else return json_decode($res);
}
function getServerConfigKeys($serverId,$offset = 0){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
    $stmt->bind_param("i", $serverId);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();
    
    $cty = $cats->fetch_assoc();
    $id = $cty['id'];
    $cname = $cty['title'];
    $flagdelta = $cty['flag'];
    $remarkdelta = $cty['remark'];
    $ucount = $cty['ucount'];
    $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $serverConfig= $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $reality = $serverConfig['reality']=="true"?$buttonValues['active']:$buttonValues['deactive'];
    $panelUrl = $serverConfig['panel_url'];
    $subBaseUrl = !empty($serverConfig['sub_base_url']) ? xuiNormalizeSubBaseUrl($serverConfig['sub_base_url']) : 'از روی آدرس پنل';
    $sni = !empty($serverConfig['sni'])?$serverConfig['sni']:" ";
    $headerType = !empty($serverConfig['header_type'])?$serverConfig['header_type']:" ";
    $requestHeader = !empty($serverConfig['request_header'])?$serverConfig['request_header']:" ";
    $responseHeader = !empty($serverConfig['response_header'])?$serverConfig['response_header']:" ";
    $security = !empty($serverConfig['security'])?$serverConfig['security']:" ";
    $portType = $serverConfig['port_type']=="auto"?"خودکار":"تصادفی";
    $serverType = " ";
    switch ($serverConfig['type']){
        case "sanaei":
            $serverType = "سنایی";
            break;
        case "alireza":
            $serverType = "علیرضا";
            break;
        case "normal":
            $serverType = "ساده";
            break;
        case "marzban":
            $serverType = "مرزبان";
            break;
    }
    return json_encode(['inline_keyboard'=>array_merge([
        [
            ['text'=>$panelUrl,'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$cname,'callback_data'=>"editServerName$id"],
            ['text'=>"❕نام سرور",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$subBaseUrl,'callback_data'=>"editsServersub_base_url$id"],
            ['text'=>"🌐 دامنه ساب پنل",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$flagdelta,'callback_data'=>"editServerFlag$id"],
            ['text'=>"🚩 پرچم سرور",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$remarkdelta,'callback_data'=>"editServerRemark$id"],
            ['text'=>"📣 ریمارک سرور",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$serverType??" ",'callback_data'=>"changeServerType$id"],
            ['text'=>"نوعیت سرور",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$ucount,'callback_data'=>"editServerMax$id"],
            ['text'=>"ظرفیت سرور",'callback_data'=>"deltach"]
            ]
            ],
            ($serverConfig['type'] != "marzban"?[
        [
            ['text'=>$portType,'callback_data'=>"changePortType$id"],
            ['text'=>"نوعیت پورت",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$sni,'callback_data'=>"editsServersni$id"],
            ['text'=>"sni",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$headerType,'callback_data'=>"editsServerheader_type$id"],
            ['text'=>"header type",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$requestHeader,'callback_data'=>"editsServerrequest_header$id"],
            ['text'=>"request header",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$responseHeader,'callback_data'=>"editsServerresponse_header$id"],
            ['text'=>"response header",'callback_data'=>"deltach"],
            ],
        [
            ['text'=>$security,'callback_data'=>"editsServersecurity$id"],
            ['text'=>"security",'callback_data'=>"deltach"],
            ],
        (($serverConfig['type'] == "sanaei" || $serverConfig['type'] == "alireza")?
        [
            ['text'=>$reality,'callback_data'=>"changeRealityState$id"],
            ['text'=>"reality",'callback_data'=>"deltach"],
            ]:[]),
        [
            ['text'=>"♻️ تغییر آیپی های سرور",'callback_data'=>"changesServerIp$id"],
            ],
        [
            ['text'=>"♻️ تغییر security setting",'callback_data'=>"editsServertlsSettings$id"],
            ]
            ]:[]),[
        [
            ['text'=>"🔅تغییر اطلاعات ورود",'callback_data'=>"changesServerLoginInfo$id"],
            ],
        [
            ['text'=>"✂️ حذف سرور",'callback_data'=>"deltadeleteserver$id"],
            ],
        [['text' => $buttonValues['back_button'], 'callback_data' => "nextServerPage" . $offset]]
        ])]);
}
function getServerListKeys($offset = 0){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    
    $limit = 15;
    
    $stmt = $connection->prepare("SELECT * FROM `server_info` WHERE `active`=1 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats= $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"وضعیت",'callback_data'=>"deltach"],['text'=>"تنظیمات",'callback_data'=>"deltach"],['text'=>"نوعیت",'callback_data'=>"deltach"],['text'=>"سرور",'callback_data'=>"deltach"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"سروری یافت نشد",'callback_data'=>"deltach"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $flagdelta = $cty['flag'];
            $remarkdelta = $cty['remark'];
            $state = $cty['state'] == "1"?$buttonValues['active']:$buttonValues['deactive'];
            $ucount = $cty['ucount'];
            $stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id`=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $serverTypeInfo= $stmt->get_result()->fetch_assoc();
            $stmt->close(); 
            $portType = $serverTypeInfo['port_type']=="auto"?"خودکار":"تصادفی";
            $serverType = " ";
            switch ($serverTypeInfo['type']){
                case "sanaei":
                    $serverType = "سنایی";
                    break;
                case "alireza":
                    $serverType = "علیرضا";
                    break;
                case "normal":
                    $serverType = "ساده";
                    break;
                case "marzban":
                    $serverType = "مرزبان";
                    break;
            }
            $keys[] = [['text'=>$state,'callback_data'=>'toggleServerState' . $id . "_" . $offset],['text'=>"⚙️",'callback_data'=>"showServerSettings" . $id . "_" . $offset],['text'=>$serverType??" ",'callback_data'=>"deltach"],['text'=>$cname,'callback_data'=>"deltach"]];
        } 
    }
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextServerPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextServerPage" . ($offset - $limit)]];
    }
    $keys[] = [
        ['text'=>'➕ ثبت سرور xui','callback_data'=>"addNewServer"],
        ['text'=>"➕ ثبت سرور مرزبان",'callback_data'=>"addNewMarzbanPanel"]
        ];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getCategoriesKeys($offset = 0){
    $limit = 15;
    
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active`=1 AND `parent`=0 LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $limit, $offset);
    $stmt->execute();
    $cats = $stmt->get_result();
    $stmt->close();


    $keys = array();
    $keys[] = [['text'=>"حذف",'callback_data'=>"deltach"],['text'=>"اسم دسته",'callback_data'=>"deltach"]];
    if($cats->num_rows == 0){
        $keys[] = [['text'=>"دسته بندی یافت نشد",'callback_data'=>"deltach"]];
    }else {
        while($cty = $cats->fetch_assoc()){
            $id = $cty['id'];
            $cname = $cty['title'];
            $keys[] = [['text'=>"❌",'callback_data'=>"deltacategorydelete$id" . "_" . $offset],['text'=>$cname,'callback_data'=>"deltacategoryedit$id" . "_" . $offset]];
        }
    }
    
    if($offset == 0 && $cats->num_rows >= $limit){
        $keys[] = [['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)]];
    }
    elseif($cats->num_rows >= $limit){
        $keys[] = [
            ['text'=>" »» صفحه بعدی »»",'callback_data'=>"nextCategoryPage" . ($offset + $limit)],
            ['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]
            ];
    }
    elseif($offset != 0){
        $keys[] = [['text'=>" «« صفحه قبلی ««",'callback_data'=>"nextCategoryPage" . ($offset - $limit)]];
    }
    
    $keys[] = [['text'=>'➕ افزودن دسته جدید','callback_data'=>"addNewCategory"]];
    $keys[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getGateWaysKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();
    
    $cartToCartState = $botState['cartToCartState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $walletState = $botState['walletState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $weSwapState = $botState['weSwapState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentWallet = $botState['nowPaymentWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nowPaymentOther = $botState['nowPaymentOther']=="on"?$buttonValues['on']:$buttonValues['off'];
    $tronWallet = $botState['tronWallet']=="on"?$buttonValues['on']:$buttonValues['off'];
    $zarinpal = $botState['zarinpal']=="on"?$buttonValues['on']:$buttonValues['off'];
    $nextpay = $botState['nextpay']=="on"?$buttonValues['on']:$buttonValues['off'];
    $rewaredChannel = $botState['rewardChannel']??" ";
    $lockChannel = $botState['lockChannel']??" ";

    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>(!empty($paymentKeys['bankAccount'])?$paymentKeys['bankAccount']:" "),'callback_data'=>"changePaymentKeysbankAccount"],
            ['text'=>"شماره حساب",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>(!empty($paymentKeys['holderName'])?$paymentKeys['holderName']:" "),'callback_data'=>"changePaymentKeysholderName"],
            ['text'=>"دارنده حساب",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nowpayment'])?$paymentKeys['nowpayment']:" "),'callback_data'=>"changePaymentKeysnowpayment"],
            ['text'=>"کد درگاه nowPayment",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>(!empty($paymentKeys['zarinpal'])?$paymentKeys['zarinpal']:" "),'callback_data'=>"changePaymentKeyszarinpal"],
            ['text'=>"کد درگاه زرین پال",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>(!empty($paymentKeys['nextpay'])?$paymentKeys['nextpay']:" "),'callback_data'=>"changePaymentKeysnextpay"],
            ['text'=>"کد درگاه نکست پی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>(!empty($paymentKeys['tronwallet'])?$paymentKeys['tronwallet']:" "),'callback_data'=>"changePaymentKeystronwallet"],
            ['text'=>"آدرس والت ترون",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$weSwapState,'callback_data'=>"changeGateWaysweSwapState"],
            ['text'=>"درگاه وی سواپ",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$cartToCartState,'callback_data'=>"changeGateWayscartToCartState"],
            ['text'=>"کارت به کارت",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$nextpay,'callback_data'=>"changeGateWaysnextpay"],
            ['text'=>"درگاه نکست پی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$zarinpal,'callback_data'=>"changeGateWayszarinpal"],
            ['text'=>"درگاه زرین پال",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$nowPaymentWallet,'callback_data'=>"changeGateWaysnowPaymentWallet"],
            ['text'=>"درگاه NowPayment کیف پول",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$nowPaymentOther,'callback_data'=>"changeGateWaysnowPaymentOther"],
            ['text'=>"درگاه NowPayment سایر",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$tronWallet,'callback_data'=>"changeGateWaystronWallet"],
            ['text'=>"درگاه ترون",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$walletState,'callback_data'=>"changeGateWayswalletState"],
            ['text'=>"کیف پول",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$rewaredChannel,'callback_data'=>'editRewardChannel'],
            ['text'=>"کانال گزارش درآمد",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$lockChannel,'callback_data'=>'editLockChannel'],
            ['text'=>"کانال قفل",'callback_data'=>'deltach']
            ],
        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);

}
function getBotSettingKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'BOT_STATES'");
    $stmt->execute();
    $botState = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($botState)) $botState = json_decode($botState,true);
    else $botState = array();
    $stmt->close();

    $changeProtocole = $botState['changeProtocolState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $renewAccount = $botState['renewAccountState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $plandelkhahwiz = $botState['plandelkhahState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $switchLocation = $botState['switchLocationState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseTime = $botState['increaseTimeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $increaseVolume = $botState['increaseVolumeState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $subLink = ($botState['subLinkState']??'on')=="on"?$buttonValues['on']:$buttonValues['off'];
    $volumeWeb = ($botState['volumeWebState']??'on')=="on"?$buttonValues['on']:$buttonValues['off'];
    $configLink = $botState['configLinkState']=="off"?$buttonValues['off']:$buttonValues['on'];
    $renewConfigLink = $botState['renewConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $updateConfigLink = $botState['updateConfigLinkState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $individualExistence = $botState['individualExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sharedExistence = $botState['sharedExistence']=="on"?$buttonValues['on']:$buttonValues['off'];
    $testAccount = $botState['testAccount']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agency = $botState['agencyState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $agencyPlanDiscount = $botState['agencyPlanDiscount']=="on"?$buttonValues['plan_discount']:$buttonValues['server_discount'];
    $qrConfig = $botState['qrConfigState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $qrSub = $botState['qrSubState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $cloudVpsPanel = (($botState['cloudVpsPanelState'] ?? 'on')=='on')?$buttonValues['on']:$buttonValues['off'];
    $myResellerBotsButton = (($botState['myResellerBotsButtonState'] ?? 'on')=='on')?$buttonValues['on']:$buttonValues['off'];
    $resellerBotsButton = (($botState['resellerBotsButtonState'] ?? 'on')=='on')?$buttonValues['on']:$buttonValues['off'];
    $groupBuyState = (($botState['groupBuyState'] ?? 'off')=='on')?$buttonValues['on']:$buttonValues['off'];
    $rebuyLastServiceState = (($botState['rebuyLastServiceState'] ?? 'off')=='on')?$buttonValues['on']:$buttonValues['off'];
    $adminApprovalState = (($botState['adminApprovalState'] ?? 'off')=='on')?$buttonValues['on']:$buttonValues['off'];
    
    $requirePhone = $botState['requirePhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $requireIranPhone = $botState['requireIranPhone']=="on"?$buttonValues['on']:$buttonValues['off'];
    $sellState = $botState['sellState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $robotState = $botState['botState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $searchState = $botState['searchState']=="on"?$buttonValues['on']:$buttonValues['off'];
    $inviteBtn = (($botState['inviteButton']??'on')=='on')?$buttonValues['on']:$buttonValues['off'];
    $updateConnectionState = $botState['updateConnectionState']=="robot"?"از روی ربات":"از روی سایت";
    $rewaredTime = ($botState['rewaredTime']??0) . " ساعت";
    switch($botState['remark']){
        case "digits":
            $remarkType = "عدد رندم 5 حرفی";
            break;
        case "manual":
            $remarkType = "توسط کاربر";
            break;
        default:
            $remarkType = "آیدی و عدد رندوم";
            break;
    }
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` = 'PAYMENT_KEYS'");
    $stmt->execute();
    $paymentKeys = $stmt->get_result()->fetch_assoc()['value'];
    if(!is_null($paymentKeys)) $paymentKeys = json_decode($paymentKeys,true);
    else $paymentKeys = array();
    $stmt->close();
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>"🎗 بنر بازاریابی 🎗",'callback_data'=>"inviteSetting"]
            ],
        [
            ['text'=> $inviteBtn,'callback_data'=>"toggleInviteButton"],
            ['text'=>"زیرمجموعه گیری",'callback_data'=>"deltach"]
            ],
        [
            ['text'=> $updateConnectionState,'callback_data'=>"changeUpdateConfigLinkState"],
            ['text'=>"آپدیت کانفیگ",'callback_data'=>"deltach"]
            ],
        [
            ['text'=> $agency,'callback_data'=>"changeBotagencyState"],
            ['text'=>"نمایندگی",'callback_data'=>"deltach"]
            ],
        [
            ['text'=> $agencyPlanDiscount,'callback_data'=>"changeBotagencyPlanDiscount"],
            ['text'=>"نوع تخفیف نمایندگی",'callback_data'=>"deltach"]
            ],
        [
            ['text'=>$individualExistence,'callback_data'=>"changeBotindividualExistence"],
            ['text'=>"موجودی اختصاصی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$sharedExistence,'callback_data'=>"changeBotsharedExistence"],
            ['text'=>"موجودی اشتراکی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$testAccount,'callback_data'=>"changeBottestAccount"],
            ['text'=>"اکانت تست",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$changeProtocole,'callback_data'=>"changeBotchangeProtocolState"],
            ['text'=>"تغییر پروتکل",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$renewAccount,'callback_data'=>"changeBotrenewAccountState"],
            ['text'=>"تمدید سرویس",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$plandelkhahwiz,'callback_data'=>"changeBotplandelkhahState"],
            ['text'=>"پلن دلخواه",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$switchLocation,'callback_data'=>"changeBotswitchLocationState"],
            ['text'=>"تغییر لوکیشن",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$increaseTime,'callback_data'=>"changeBotincreaseTimeState"],
            ['text'=>"افزایش زمان",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$increaseVolume,'callback_data'=>"changeBotincreaseVolumeState"],
            ['text'=>"افزایش حجم",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$requirePhone,'callback_data'=>"changeBotrequirePhone"],
            ['text'=>"تأیید شماره",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$requireIranPhone,'callback_data'=>"changeBotrequireIranPhone"],
            ['text'=>"تأیید شماره ایرانی",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$sellState,'callback_data'=>"changeBotsellState"],
            ['text'=>"فروش",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$robotState,'callback_data'=>"changeBotbotState"],
            ['text'=>"وضعیت ربات",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$subLink,'callback_data'=>"changeBotsubLinkState"],
            ['text'=>"ساب پنل",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$volumeWeb,'callback_data'=>"changeBotvolumeWebState"],
            ['text'=>"Volume web",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$configLink,'callback_data'=>"changeBotconfigLinkState"],
            ['text'=>"لینک کانفیگ",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$searchState,'callback_data'=>"changeBotsearchState"],
            ['text'=>"مشخصات کانفیگ",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$renewConfigLink,'callback_data'=>"changeBotrenewConfigLinkState"],
            ['text'=>"دریافت لینک جدید",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$updateConfigLink,'callback_data'=>"changeBotupdateConfigLinkState"],
            ['text'=>"بروز رسانی لینک",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$qrConfig,'callback_data'=>"changeBotqrConfigState"],
            ['text'=>"کیو آر کد کانفیگ",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$qrSub,'callback_data'=>"changeBotqrSubState"],
            ['text'=>"کیو آر ساب پنل",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$remarkType,'callback_data'=>"changeConfigRemarkType"],
            ['text'=>"نوع ریمارک",'callback_data'=>"deltach"]
        ],
        [
            ['text'=>$rewaredTime,'callback_data'=>'editRewaredTime'],
            ['text'=>"ارسال گزارش درآمد", 'callback_data'=>'deltach']
            ],
        [
            ['text'=>$botState['cartToCartAutoAcceptState']=="on"?$buttonValues['on']:$buttonValues['off'],'callback_data'=>"changeBotcartToCartAutoAcceptState"],
            ['text'=>"تأیید خودکار کارت به کارت",'callback_data'=>"deltach"]
        ],
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptType'] == "0"?"نماینده":($botState['cartToCartAutoAcceptType'] == "1"?"کاربر":"همه")),'callback_data'=>"changeBotcartToCartAutoAcceptType"],
            ['text'=>"نوع تأیید",'callback_data'=>"deltach"]
        ]:[]),
        ($botState['cartToCartAutoAcceptState']=="on"?[
            ['text'=>($botState['cartToCartAutoAcceptTime']??"10") . " دقیقه",'callback_data'=>"editcartToCartAutoAcceptTime"],
            ['text'=>"زمان تأیید خودکار ",'callback_data'=>"deltach"]
        ]:[]),
        (empty($isChildBot)?[
            ['text'=>$myResellerBotsButton,'callback_data'=>'changeBotmyResellerBotsButtonState'],
            ['text'=>'ربات های من','callback_data'=>'deltach']
        ]:[]),
        (empty($isChildBot)?[
            ['text'=>$resellerBotsButton,'callback_data'=>'changeBotresellerBotsButtonState'],
            ['text'=>'ربات های نمایندگی','callback_data'=>'deltach']
        ]:[]),
        (empty($isChildBot)?[
            ['text'=>$cloudVpsPanel,'callback_data'=>'changeBotcloudVpsPanelState'],
            ['text'=>'سرور ابری','callback_data'=>'deltach']
        ]:[]),
        [
            ['text'=>$groupBuyState,'callback_data'=>'changeBotgroupBuyState'],
            ['text'=>'خرید گروهی','callback_data'=>'deltach']
        ],
        [
            ['text'=>$rebuyLastServiceState,'callback_data'=>'changeBotrebuyLastServiceState'],
            ['text'=>'خرید مجدد آخرین سرویس','callback_data'=>'deltach']
        ],
        (empty($isChildBot)?[
            ['text'=>$adminApprovalState,'callback_data'=>'changeBotadminApprovalState'],
            ['text'=>'تأیید مدیر برای کاربران','callback_data'=>'deltach']
        ]:[]),
        (empty($isChildBot)?[
            ['text'=>'✅ کاربرهای قبول شده','callback_data'=>'approvalAcceptedUsers'],
            ['text'=>'❌ کاربرهای رد شده','callback_data'=>'approvalRejectedUsers']
        ]:[]),
        [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]]
        ]]);

}
function getBotReportKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $stmt = $connection->prepare("SELECT * FROM `users`");
    $stmt->execute();
    $allUsers = $stmt->get_result()->num_rows;
    $stmt->close();

    $stmt = $connection->prepare("SELECT * FROM `orders_list`");
    $stmt->execute();
    $allOrders = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_config`");
    $stmt->execute();
    $allServers = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_categories`");
    $stmt->execute();
    $allCategories = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans`");
    $stmt->execute();
    $allPlans = $stmt->get_result()->num_rows;
    $stmt->close();
    
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `state` = 'paid' OR `state` = 'approved'");
    $stmt->execute();
    $totalRewards = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    
    $persian = explode("-",jdate("Y-n-1", time()));
    $gregorian = jalali_to_gregorian($persian[0], $persian[1], $persian[2]);
    $date =  $gregorian[0] . "-" . $gregorian[1] . "-" . $gregorian[2];
    $dayTime = strtotime($date);
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $monthReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    $dayTime = strtotime("-" . (date("w")+1) . " days");
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ?  AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $weekReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    $dayTime = strtotime("today");
    $stmt = $connection->prepare("SELECT SUM(price) as total FROM `pays` WHERE `request_date` > ? AND (`state` = 'paid' OR `state` = 'approved')");
    $stmt->bind_param("i", $dayTime);
    $stmt->execute();
    $dayReward = number_format($stmt->get_result()->fetch_assoc()['total']) . " تومان";
    $stmt->close();
    
    return json_encode(['inline_keyboard'=>[
        [
            ['text'=>$allUsers,'callback_data'=>'deltach'],
            ['text'=>"تعداد کل کاربران",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$allOrders,'callback_data'=>'deltach'],
            ['text'=>"کل محصولات خریداری شده",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$allServers,'callback_data'=>'deltach'],
            ['text'=>"تعداد سرورها",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$allCategories,'callback_data'=>'deltach'],
            ['text'=>"تعداد دسته ها",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$allPlans,'callback_data'=>'deltach'],
            ['text'=>"تعداد پلن ها",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$totalRewards,'callback_data'=>'deltach'],
            ['text'=>"درآمد کل",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$dayReward,'callback_data'=>'deltach'],
            ['text'=>"درآمد امروز",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$weekReward,'callback_data'=>'deltach'],
            ['text'=>"درآمد هفته",'callback_data'=>'deltach']
            ],
        [
            ['text'=>$monthReward,'callback_data'=>'deltach'],
            ['text'=>"درآمد ماه",'callback_data'=>'deltach']
            ],
        [
            ['text'=>"برگشت به مدیریت",'callback_data'=>'managePanel']
            ]
        ]]);
}
function getAdminsKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $keys = array();
    
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `isAdmin` = true");
    $stmt->execute();
    $usersList = $stmt->get_result();
    $stmt->close();
    if($usersList->num_rows > 0){
        while($user = $usersList->fetch_assoc()){
            $keys[] = [['text'=>"❌",'callback_data'=>"delAdmin" . $user['userid']],['text'=>$user['name'], "callback_data"=>"deltach"]];
        }
    }else{
        $keys[] = [['text'=>"لیست ادمین ها خالی است ❕",'callback_data'=>"deltach"]];
    }
    $keys[] = [['text'=>"➕ افزودن ادمین",'callback_data'=>"addNewAdmin"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getUserInfoKeys($userId, $backCallback = "managePanel"){
    global $connection, $mainValues, $buttonValues, $isChildBot; 
    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid` = ?");
    $stmt->bind_param("i",$userId);
    $stmt->execute();
    $userCount = $stmt->get_result();
    $stmt->close();
    if($userCount->num_rows > 0){
        $userInfos = $userCount->fetch_assoc();
        $userWallet = number_format($userInfos['wallet']) . " تومان";
        
        $stmt = $connection->prepare("SELECT COUNT(amount) as count, SUM(amount) as total FROM `orders_list` WHERE `userid` = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        
        $boughtService = $info['count'];
        $totalBoughtPrice = number_format($info['total']) . " تومان";
        
        $userDetail = bot('getChat',['chat_id'=>$userId])->result;
        $userUserName = $userDetail->username;
        $fullName = $userDetail->first_name . " " . $userDetail->last_name;
        
        $approvalStatus = $userInfos['approval_status'] ?? 'none';
        if($approvalStatus == 'approved') $approvalStatusText = '✅ تایید شده';
        elseif($approvalStatus == 'rejected') $approvalStatusText = '❌ رد شده';
        elseif($approvalStatus == 'pending') $approvalStatusText = '⏳ در انتظار';
        else $approvalStatusText = '—';
        $inviterText = trim((string)($userInfos['approval_inviter_input'] ?? ''));
        if($inviterText == '' && !empty($userInfos['approval_inviter_userid'])){
            $inviterUsername = trim((string)($userInfos['approval_inviter_username'] ?? ''));
            $inviterText = $inviterUsername != '' ? ('@' . $inviterUsername) : (string)((int)$userInfos['approval_inviter_userid']);
        }
        if($inviterText == '') $inviterText = 'ندارد';
        $userNameBtn = $userUserName ? ['text'=>$userUserName,'url'=>"t.me/$userUserName"] : ['text'=>'ندارد','callback_data'=>'deltach'];
        $approvalActionStatus = $approvalStatus == 'approved' ? 'rejected' : 'approved';
        $approvalActionText = $approvalStatus == 'approved' ? '🚫 عدم دسترسی' : '✅ تایید دسترسی';
        return json_encode(['inline_keyboard'=>[
            [
                $userNameBtn,
                ['text'=>"یوزرنیم",'callback_data'=>"deltach"]
                ],
            [
                ['text'=>$approvalStatusText,'callback_data'=>'deltach'],
                ['text'=>'وضعیت دسترسی','callback_data'=>'deltach']
                ],
            [
                ['text'=>$inviterText,'callback_data'=>'deltach'],
                ['text'=>'معرف','callback_data'=>'deltach']
                ],
            [
                ['text'=>$fullName??" ",'callback_data'=>"deltach"],
                ['text'=>"نام",'callback_data'=>"deltach"]
                ],
            [
                ['text'=>$boughtService??" ",'callback_data'=>"deltach"],
                ['text'=>"سرویس ها",'callback_data'=>"deltach"]
                ],
            [
                ['text'=>$totalBoughtPrice??" ",'callback_data'=>"deltach"],
                ['text'=>"مبلغ خرید",'callback_data'=>"deltach"]
                ],
            [
                ['text'=>$userWallet??" ",'callback_data'=>"deltach"],
                ['text'=>"موجودی کیف پول",'callback_data'=>"deltach"]
                ],
            [
                ['text'=>"➕ افزایش موجودی",'callback_data'=>"uIncWallet" . $userId],
                ['text'=>"➖ کاهش موجودی",'callback_data'=>"uDecWallet" . $userId]
                ],
            [
                ['text'=>"🔓 آزاد کردن",'callback_data'=>"uUnban" . $userId],
                ['text'=>"⛔️ مسدود کردن",'callback_data'=>"uBan" . $userId]
                ],
            [
                ['text'=>"✉️ پیام خصوصی",'callback_data'=>"uPm" . $userId],
                ['text'=>$approvalActionText,'callback_data'=>"uToggleApproval_" . $userId . "_" . $approvalActionStatus . "_" . $backCallback]
                ],
            [
                ['text'=>"🔎 کانفیگ‌ها",'callback_data'=>"uConfigs{$userId}_0"]
                ],
            [
                ['text'=>"🧾 سفارش‌ها",'callback_data'=>"uOrders" . $userId],
                ['text'=>'🔄 بروزرسانی','callback_data'=>"uRefresh" . $userId]
                ],
            [
                ['text'=>"🎯 تخفیف",'callback_data'=>"uDiscount" . $userId],
                ['text'=>"🧪 محدودیت تست",'callback_data'=>"uTestLimit" . $userId]
                ],
            [
                ['text'=>"✅/❌ تایید خودکار",'callback_data'=>"uAuto" . $userId],
                ['text'=>"♻️ صفر کردن موجودی",'callback_data'=>"uReset" . $userId]
                ],
            [
                ['text'=>$buttonValues['back_button'],'callback_data'=>$backCallback]
                ],
            ]]);
    }else return null;
}
function getDiscountCodeKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $time = time();
    $stmt = $connection->prepare("SELECT * FROM `discounts` WHERE (`expire_date` > $time OR `expire_date` = 0) AND (`expire_count` > 0 OR `expire_count` = -1)");
    $stmt->execute();
    $list = $stmt->get_result();
    $stmt->close();
    $keys = array();
    if($list->num_rows > 0){
        $keys[] = [['text'=>'حذف','callback_data'=>"deltach"],['text'=>"استفاده هر یوزر",'callback_data'=>"deltach"],['text'=>"تاریخ ختم",'callback_data'=>"deltach"],['text'=>"تعداد استفاده",'callback_data'=>"deltach"],['text'=>"مقدار تخفیف",'callback_data'=>"deltach"],['text'=>"کد تخفیف",'callback_data'=>"deltach"]];
        while($row = $list->fetch_assoc()){
            $date = $row['expire_date']!=0?jdate("Y/n/j H:i", $row['expire_date']):"نامحدود";
            $count = $row['expire_count']!=-1?$row['expire_count']:"نامحدود";
            $amount = $row['amount'];
            $amount = $row['type'] == 'percent'? $amount."%":$amount = number_format($amount) . " تومان";
            $hashId = $row['hash_id'];
            $rowId = $row['id'];
            $canUse = $row['can_use'];
            
            $keys[] = [['text'=>'❌','callback_data'=>"delDiscount" . $rowId],['text'=>$canUse, 'callback_data'=>"deltach"],['text'=>$date,'callback_data'=>"deltach"],['text'=>$count,'callback_data'=>"deltach"],['text'=>$amount,'callback_data'=>"deltach"],['text'=>$hashId,'callback_data'=>'copyHash' . $hashId]];
        }
    }else{
        $keys[] = [['text'=>"کد تخفیفی یافت نشد",'callback_data'=>"deltach"]];
    }
    
    $keys[] = [['text'=>"افزودن کد تخفیف",'callback_data'=>"addDiscountCode"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getMainMenuButtonsKeys(){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    
    $keys = array();
    if($buttons->num_rows > 0){
        while($row = $buttons->fetch_assoc()){
            $rowId = $row['id'];
            $title = str_replace("MAIN_BUTTONS","", $row['type']);
            $answer = $row['value'];
            $keys[] = [
                        ['text'=>"❌",'callback_data'=>"delMainButton" . $rowId],
                        ['text'=>$title??" " ,'callback_data'=>"deltach"]];
        }
    }else{
        $keys[] = [['text'=>"دکمه ای یافت نشد ❕",'callback_data'=>"deltach"]];
    }
    $keys[] = [['text'=>'✏️ تغییر اسم دکمه‌ها','callback_data'=>'renameButtons']];
    $keys[] = [['text'=>'↕️ چینش دکمه‌ها','callback_data'=>'arrangeButtons']];
    $keys[] = [['text'=>"افزودن دکمه جدید ➕",'callback_data'=>"addNewMainButton"]];
    $keys[] = [['text'=>$buttonValues['back_button'],'callback_data'=>"managePanel"]];
    return json_encode(['inline_keyboard'=>$keys]);
}
function getPlanDetailsKeys($planId){
    global $connection, $mainValues, $buttonValues, $isChildBot;
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
    $stmt->bind_param("i", $planId);
    $stmt->execute();
    $pdResult = $stmt->get_result();
    $pd = $pdResult->fetch_assoc();
    $stmt->close();


    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $pd['server_id']);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $reality = $server_info['reality'];


    if($pdResult->num_rows == 0) return null;
    else {
        $id=$pd['id'];
        $name=$pd['title'];
        $price=$pd['price'];
        $acount =$pd['acount'];
        $rahgozar = $pd['rahgozar'];
        $customPath = $pd['custom_path']==true?$buttonValues['on']:$buttonValues['off'];
        $dest = $pd['dest']??" ";
        $spiderX = $pd['spiderX']??" ";
        $serverName = $pd['serverNames']??" ";
        $flow = $pd['flow'];
        $customPort = $pd['custom_port'];
        $customSni = $pd['custom_sni']??" ";

        $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `status`=1 AND `fileid`=?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $deltaplanaccnumber = $stmt->get_result()->num_rows;
        $stmt->close();

        $srvid= $pd['server_id'];
        $keyboard = [
            ($rahgozar==true?[['text'=>"* نوع پلن: رهگذر *",'callback_data'=>'deltach']]:[]),
            ($rahgozar==true?[
                ['text'=>$customPath,'callback_data'=>'changeCustomPath' . $id],
                ['text'=>"Path Custom",'callback_data'=>'deltach'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customPort,'callback_data'=>'changeCustomPort' . $id],
                ['text'=>"پورت دلخواه",'callback_data'=>'deltach'],
                ]:[]),
            ($rahgozar==true?[
                ['text'=>$customSni,'callback_data'=>'changeCustomSni' . $id],
                ['text'=>"sni دلخواه",'callback_data'=>'deltach'],
                ]:[]),
            [['text'=>$name,'callback_data'=>"deltaplanname$id"],['text'=>"🔮 نام پلن",'callback_data'=>"deltach"]],
            ($reality == "true"?[['text'=>$dest,'callback_data'=>"editDestName$id"],['text'=>"dest",'callback_data'=>"deltach"]]:[]),
            ($reality == "true"?[['text'=>$serverName,'callback_data'=>"editServerNames$id"],['text'=>"serverNames",'callback_data'=>"deltach"]]:[]),
            ($reality == "true"?[['text'=>$spiderX,'callback_data'=>"editSpiderX$id"],['text'=>"spiderX",'callback_data'=>"deltach"]]:[]),
            ($reality == "true"?[['text'=>$flow,'callback_data'=>"editFlow$id"],['text'=>"flow",'callback_data'=>"deltach"]]:[]),
            [['text'=>$deltaplanaccnumber,'callback_data'=>"deltach"],['text'=>"🎗 تعداد اکانت های فروخته شده",'callback_data'=>"deltach"]],
            ($pd['inbound_id'] != 0?[['text'=>"$acount",'callback_data'=>"deltaplanslimit$id"],['text'=>"🚪 تغییر ظرفیت کانفیگ",'callback_data'=>"deltach"]]:[]),
            ($pd['inbound_id'] != 0?[['text'=>$pd['inbound_id'],'callback_data'=>"deltaplansinobundid$id"],['text'=>"🚪 سطر کانفیگ",'callback_data'=>"deltach"]]:[]),
            [['text'=>"✏️ ویرایش توضیحات",'callback_data'=>"deltaplaneditdes$id"]],
            [['text'=>number_format($price) . " تومان",'callback_data'=>"deltaplanrial$id"],['text'=>"💰 قیمت پلن",'callback_data'=>"deltach"]],
            [['text'=>"♻️ دریافت لیست اکانت ها",'callback_data'=>"deltaplanacclist$id"]],
            ($server_info['type'] == "marzban"?[['text'=>"انتخاب Host",'callback_data'=>"marzbanHostSettings" . $id]]:[]),
            [['text'=>"✂️ حذف",'callback_data'=>"deltaplandelete$id"]],
            [['text' => $buttonValues['back_button'], 'callback_data' =>"plansList$srvid"]]
            ];
        return json_encode(['inline_keyboard'=>$keyboard]);
    }
}
function getUserOrderDetailKeys($id, $offset = 0){
    global $connection, $botState, $mainValues, $buttonValues, $botUrl;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `id`=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();
    

    if($order->num_rows==0){
        return null;
    }else {
        $order = $order->fetch_assoc();
        $userId = $order['userid'];
        $firstName = bot('getChat',['chat_id'=>$userId])->result->first_name ?? " ";
        $fid = $order['fileid']; 
    	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result();
        $stmt->close();
	    $rahgozar = $order['rahgozar'];
        $agentBought = $order['agent_bought'];
        $isAgentBought = $agentBought == true?"بله":"نخیر";

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
    	    }else $name = "$id";
        	
    	}else $name = "$id";
    	
        $date = jdate("Y-m-d H:i",$order['date']);
        $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
        $remark = $order['remark'];
        $uuid = $order['uuid']??"0";
        $acc_link = json_decode($order['link']);
        $protocol = $order['protocol'];
        $token = $order['token'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $link_status = $order['expire_date'] > time()  ? $buttonValues['active'] : $buttonValues['deactive'];
        $price = $order['amount'];
        
    	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    	$stmt->bind_param('i', $server_id);
    	$stmt->execute();
    	$serverConfig = $stmt->get_result()->fetch_assoc();
    	$stmt->close();
    	$serverType = $serverConfig['type'];
    	$panelUrl = $serverConfig['panel_url'];

        if($serverType == "marzban"){
            $info = getMarzbanUser($server_id, $remark);
            $enable = $info->status =="active"?true:false;
            $total = $info->data_limit;
            $usedTraffic = $info->used_traffic;
            
            $leftgb = round( ($total - $usedTraffic) / 1073741824, 2) . " GB";
        }else{
            $response = getJson($server_id)->obj;
            if($inbound_id == 0) {
                foreach($response as $row){
                    $clients = xuiDecodeField($row->settings)->clients;
                    if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                        $total = $row->total;
                        $up = $row->up;
                        $enable = $row->enable;
                        $down = $row->down; 
                        $netType = xuiDecodeField($row->streamSettings)->network;
                        $security = xuiDecodeField($row->streamSettings)->security;
                        break;
                    }
                }
            }else {
                foreach($response as $row){
                    if($row->id == $inbound_id) {
                        $netType = xuiDecodeField($row->streamSettings)->network;
                        $security = xuiDecodeField($row->streamSettings)->security;
                        $clientsStates = $row->clientStats;
                        $clients = xuiDecodeField($row->settings)->clients;
                        foreach($clients as $key => $client){
                            if($client->id == $uuid || $client->password == $uuid){
                                $email = $client->email;
                                $emails = array_column($clientsStates,'email');
                                $emailKey = array_search($email,$emails);
                                
                                $total = $clientsStates[$emailKey]->total;
                                $up = $clientsStates[$emailKey]->up;
                                $enable = $clientsStates[$emailKey]->enable;
                                if(!$client->enable) $enable = false;
                                $down = $clientsStates[$emailKey]->down; 
                                break;
                            }
                        }
                    }
                }
            }
            $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB";
        }
        $configLinks = "";
    
        $limit = 5;
        $count = 0;
        foreach($acc_link as $accLink){
            $count++;
            if($count <= $offset) continue;
            $configLinks .= ($botState['configLinkState'] != "off"?"\n <code>$accLink</code>":"");
            
            if($count >= $offset + $limit) break;
        }

        $keyboard = array();
        
        $configKeys = [];
        
        if(count($acc_link) > $limit){
            if($offset == 0){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"userOrderDetails{$id}_" . ($offset + $limit)]
                    ];
            }
            elseif(count($acc_link) >= $offset + $limit){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"userOrderDetails{$id}_" . ($offset + $limit)],
                    ['text'=>"»",'callback_data'=>"userOrderDetails{$id}_" . ($offset - $limit)]
                    ];
                
            }
            elseif($offset != 0){
                $configKeys = [
                    ['text'=>"»",'callback_data'=>"userOrderDetails{$id}_" . ($offset - $limit)]
                    ];
            }
        }
    
        array_push($keyboard, $configKeys, [
    			    ['text' => $userId, 'callback_data' => "deltach"],
                    ['text' => "آیدی کاربر", 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => $firstName, 'callback_data' => "deltach"],
                    ['text' => "اسم کاربر", 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => $isAgentBought, 'callback_data' => "deltach"],
                    ['text' => "خرید نماینده", 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => "$name", 'callback_data' => "deltach"],
                    ['text' => $buttonValues['plan_name'], 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => "$date ", 'callback_data' => "deltach"],
                    ['text' => $buttonValues['buy_date'], 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => "$expire_date ", 'callback_data' => "deltach"],
                    ['text' => $buttonValues['expire_date'], 'callback_data' => "deltach"],
                ],
                [
    			    ['text' => " $leftgb", 'callback_data' => "deltach"],
                    ['text' => $buttonValues['volume_left'], 'callback_data' => "deltach"],
    			],
                [
                    ['text' => $buttonValues['selected_protocol'], 'callback_data' => "deltach"],
                ]);
                
        if($inbound_id == 0){
            if($protocol == 'trojan') {
                if($security == "xtls"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }else{
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                    
                }
            }else {
                if($netType == "grpc"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                }
                elseif($netType == "tcp" && $security == "xtls"){
                    array_push($keyboard, 
                        [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ],
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }
                else{
                    array_push($keyboard, 
                        ($rahgozar == true?
                        [
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ]:
                            [
                            ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => "deltach"],
                            ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => "deltach"],
                        ]),
                        [
                            ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                            ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                            ]
                    );
                    
                }
            }
        }else{
            array_push($keyboard, 
                [
                    ['text' => " $protocol ☑️", 'callback_data' => "deltach"],
                ],
                [
                    ['text'=>($enable == true?$buttonValues['disable_config']:$buttonValues['enable_config']),'callback_data'=>"changeUserConfigState" . $order['id']],
                    ['text'=>$buttonValues['delete_config'],'callback_data'=>"delUserConfig" . $order['id']],
                    ]
                ); 
            

        }


        $stmt= $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if($serverType == "marzban") $subLink = $botState['subLinkState'] == "on"?"<code>" . xuiBuildPanelSubLink($panelUrl, '/sub/' . $token, xuiGetServerSubBaseUrl($server_id, $panelUrl)) . "</code>":"";
        else $subLink = $botState['subLinkState']=="on"?"<code>" . xuiGetClientSubLink($server_id, $inbound_id, $uuid, $remark) . "</code>":"";

        
        $enable = $enable == true? $buttonValues['active']:$buttonValues['deactive'];
        $msg = str_replace(['STATE', 'NAME','CONNECT-LINK', 'SUB-LINK'], [$enable, $remark, $configLinks, $subLink], $mainValues['config_details_message']);
    
        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => "managePanel"]];
        return ["keyboard"=>json_encode([
                    'inline_keyboard' => $keyboard
                ]),
                "msg"=>$msg];
    }
}
function getOrderDetailKeys($from_id, $id, $offset = 0){
    global $connection, $botState, $mainValues, $buttonValues, $botUrl;
    $stmt = $connection->prepare("SELECT * FROM `orders_list` WHERE `userid`=? AND `id`=?");
    $stmt->bind_param("ii", $from_id, $id);
    $stmt->execute();
    $order = $stmt->get_result();
    $stmt->close();

    if($order->num_rows==0){
        return null;
    }else {
        $order = $order->fetch_assoc();
        $fid = $order['fileid']; 
    	$stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=? AND `active`=1"); 
        $stmt->bind_param("i", $fid);
        $stmt->execute();
        $respd = $stmt->get_result();
        $stmt->close();
	    $rahgozar = $order['rahgozar'];
        $agentBought = $order['agent_bought'];

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
    	    }else $name = "$id";
        	
    	}else $name = "$id";
    	
        $date = jdate("Y-m-d H:i",$order['date']);
        $expire_date = jdate("Y-m-d H:i",$order['expire_date']);
        $remark = $order['remark'];
        $uuid = $order['uuid']??"0";
        $acc_link = json_decode($order['link']);
        $protocol = $order['protocol'];
        $token = $order['token'];
        $server_id = $order['server_id'];
        $inbound_id = $order['inbound_id'];
        $link_status = $order['expire_date'] > time()  ? $buttonValues['active'] : $buttonValues['deactive'];
        $price = $order['amount'];
        
    	$stmt = $connection->prepare("SELECT * FROM `server_config` WHERE `id` = ?");
    	$stmt->bind_param('i', $server_id);
    	$stmt->execute();
    	$serverConfig = $stmt->get_result()->fetch_assoc();
    	$stmt->close();
    	$serverType = $serverConfig['type'];
        $panel_url = $serverConfig['panel_url'];
        
        $found = false;

        if($serverType == "marzban"){
            $info = getMarzbanUser($server_id, $remark);
            if(isset($info->username)){
                $found = true;
                $enable = $info->status =="active"?true:false;
                $total = $info->data_limit;
                $usedTraffic = $info->used_traffic;
                
                $leftgb = round( ($total - $usedTraffic) / 1073741824, 2) . " GB";
            } else $leftgb = "⚠️";
        }else{
            $response = getJson($server_id)->obj;
            if($response){
                if($inbound_id == 0) {
                    foreach($response as $row){
                        $clients = xuiDecodeField($row->settings)->clients;
                        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
                            $found = true;
                            $total = $row->total;
                            $up = $row->up;
                            $down = $row->down; 
                            $enable = $row->enable;
                            $expiryTime = $row->expiryTime;
                            
                            $netType = xuiDecodeField($row->streamSettings)->network;
                            $security = xuiDecodeField($row->streamSettings)->security;
                            
                            $clientsStates = $row->clientStats;
                            
                            $inboundEmail = $clients[0]->email;
                            $allEmails = array_column($clientsStates,'email');
                            $clienEmailKey = array_search($inboundEmail,$allEmails);
    
                            $clientTotal = $clientsStates[$clienEmailKey]->total;
                            $clientUp = $clientsStates[$clienEmailKey]->up;
                            $clientDown = $clientsStates[$clienEmailKey]->down;
                            $clientExpiryTime = $clientsStates[$clienEmailKey]->expiryTime;
                                
                            if($clientTotal != 0 && $clientTotal != null && $clientExpiryTime != 0 && $clientExpiryTime != null){
                                $up += $clientUp;
                                $down += $clientDown;
                                $total = $clientTotal;
                            }
    
                            break;
                        }
                    }
                }else {
                    foreach($response as $row){
                        if($row->id == $inbound_id) {
                            $netType = xuiDecodeField($row->streamSettings)->network;
                            $security = xuiDecodeField($row->streamSettings)->security;
                            
                            $clientsStates = $row->clientStats;
                            $clients = xuiDecodeField($row->settings)->clients;
                            foreach($clients as $key => $client){
                                if($client->id == $uuid || $client->password == $uuid){
                                    $found = true;
                                    $email = $client->email;
                                    $emails = array_column($clientsStates,'email');
                                    $emailKey = array_search($email,$emails);
                                    
                                    $total = $clientsStates[$emailKey]->total;
                                    $up = $clientsStates[$emailKey]->up;
                                    $enable = $clientsStates[$emailKey]->enable;
                                    if(!$client->enable) $enable = false;
                                    $down = $clientsStates[$emailKey]->down; 
                                    break;
                                }
                            }
                        }
                    }
                }
                $leftgb = round( ($total - $up - $down) / 1073741824, 2) . " GB";
            }else $leftgb = "⚠️";
        }
        $configLinks = "";
        
        $limit = 5;
        $count = 0;
        foreach($acc_link as $accLink){
            $count++;
            if($count <= $offset) continue;
            $configLinks .= ($botState['configLinkState'] != "off"?"\n <code>$accLink</code>":"");
            
            if($count >= $offset + $limit) break;
        }
        $keyboard = array();
        
        $configKeys = [];
        
        if(count($acc_link) > $limit){
            if($offset == 0){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"orderDetails{$id}_" . ($offset + $limit)]
                    ];
            }
            elseif(count($acc_link) >= $offset + $limit){
                $configKeys = [
                    ['text'=>"«",'callback_data'=>"orderDetails{$id}_" . ($offset + $limit)],
                    ['text'=>"»",'callback_data'=>"orderDetails{$id}_" . ($offset - $limit)]
                    ];
                
            }
            elseif($offset != 0){
                $configKeys = [
                    ['text'=>"»",'callback_data'=>"orderDetails{$id}_" . ($offset - $limit)]
                    ];
            }
        }
        
        array_push($keyboard,$configKeys, [
			    ['text' => $name, 'callback_data' => "deltach"],
                ['text' => $buttonValues['plan_name'], 'callback_data' => "deltach"],
            ],
            [
			    ['text' => $date, 'callback_data' => "deltach"],
                ['text' => $buttonValues['buy_date'], 'callback_data' => "deltach"],
            ],
            [
			    ['text' => $expire_date, 'callback_data' => "deltach"],
                ['text' => $buttonValues['expire_date'], 'callback_data' => "deltach"],
            ],
            [
			    ['text' => $leftgb, 'callback_data' => "deltach"],
                ['text' => $buttonValues['volume_left'], 'callback_data' => "deltach"],
			],
            ($serverType != "marzban"?
			[
                ['text' => $buttonValues['selected_protocol'], 'callback_data' => "deltach"],
            ]:[]));
        if($found){
            if($inbound_id == 0){
                if($protocol == 'trojan') {
                    if($security == "xtls"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        $temp = array();
                        if($price != 0 && $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date']];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }else{
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }
                }else {
                    if($netType == "grpc"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                    ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                    ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                                ]);
                        }
                        
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
                    }
                    elseif($netType == "tcp" && $security == "xtls"){
                        if($serverType != "marzban"){
                            array_push($keyboard, [
                                    ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                    ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")],
                            ]);
                        }
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on") $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
    
                    }
                    else{
                        if($serverType != "marzban"){
                            array_push($keyboard,
                                ($rahgozar == true?
                                    [
                                        ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")]
                                    ]:
                                    [
                                        ['text' => $protocol == 'trojan' ? '☑️ trojan' : 'trojan', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_trojan":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vmess' ? '☑️ vmess' : 'vmess', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vmess":"changeProtocolIsDisable")],
                                        ['text' => $protocol == 'vless' ? '☑️ vless' : 'vless', 'callback_data' => ($botState['changeProtocolState']=="on"?"changeAccProtocol{$fid}_{$id}_vless":"changeProtocolIsDisable")]
                                    ]
                                )
                            );
                        }
                        
                        $temp = array();
                        if($price != 0 || $agentBought == true){
                            if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                            if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                        }
                        if(count($temp)>0) array_push($keyboard, $temp);
    
                    }
                }
            }else{
                if($serverType != "marzban"){
                    array_push($keyboard, [
                            ['text' => " $protocol ☑️", 'callback_data' => "deltach"],
                        ]);
                }
                
                $temp = array();
                if($price != 0 || $agentBought == true){
                    if($botState['renewAccountState']=="on") $temp[] = ['text' => $buttonValues['renew_config'], 'callback_data' => "renewAccount$id" ];
                    if($botState['switchLocationState']=="on" && $rahgozar != true) $temp[] = ['text' => $buttonValues['change_config_location'], 'callback_data' => "switchLocation{$id}_{$server_id}_{$leftgb}_".$order['expire_date'] ];
                }
                if(count($temp)>0) array_push($keyboard, $temp);
    
            }
            $enable = $enable == true? $buttonValues['active']:$buttonValues['deactive'];
        }else $enable = $mainValues['config_doesnt_exist'];


        $stmt= $connection->prepare("SELECT * FROM `server_info` WHERE `id`=?");
        $stmt->bind_param("i", $server_id);
        $stmt->execute();
        $server_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if($serverType == "marzban") $subLink = $botState['subLinkState'] == "on"?"<code>" . xuiBuildPanelSubLink($panel_url, '/sub/' . $token, xuiGetServerSubBaseUrl($server_id, $panel_url)) . "</code>":"";
        else $subLink = $botState['subLinkState']=="on"?"<code>" . xuiGetClientSubLink($server_id, $inbound_id, $uuid, $remark) . "</code>":"";

        $msg = str_replace(['STATE', 'NAME','CONNECT-LINK', 'SUB-LINK'], [$enable, $remark, $configLinks, $subLink], $mainValues['config_details_message']);
        
        
        if($found){
            $extrakey = [];
            if($botState['increaseVolumeState']=="on" && ($price != 0 || $agentBought == true)) $extrakey[] = ['text' => $buttonValues['increase_config_volume'], 'callback_data' => "increaseAVolume{$id}"];
            if($botState['increaseTimeState']=="on" && ($price != 0 || $agentBought == true)) $extrakey[] = ['text' => $buttonValues['increase_config_days'], 'callback_data' => "increaseADay{$id}"];
            $keyboard[] = $extrakey;
            
             
            if($botState['renewConfigLinkState'] == "on" && $botState['updateConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['renew_connection_link'],'callback_data'=>'changAccountConnectionLink' . $id],['text'=>$buttonValues['update_config_connection'],'callback_data'=>'updateConfigConnectionLink' . $id]];
            elseif($botState['renewConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['renew_connection_link'],'callback_data'=>'changAccountConnectionLink' . $id]];
            elseif($botState['updateConfigLinkState'] == "on") $keyboard[] = [['text'=>$buttonValues['update_config_connection'],'callback_data'=>'updateConfigConnectionLink' . $id]];
            
            $temp = [];
            if($botState['qrConfigState'] == "on") $temp[] = ['text'=>$buttonValues['qr_config'],'callback_data'=>"showQrConfig" . $id];
            if($botState['qrSubState'] == "on") $temp[] = ['text'=>$buttonValues['qr_sub'],'callback_data'=>"showQrSub" . $id];
            array_push($keyboard, $temp);
            
        }
        $keyboard[] = [['text' => $buttonValues['delete_config'], 'callback_data' => "deleteMyConfig" . $id]];

        $keyboard[] = [['text' => $buttonValues['back_button'], 'callback_data' => ($agentBought == true?"agentConfigsList":"mySubscriptions")]];
        return ["keyboard"=>json_encode([
                    'inline_keyboard' => $keyboard
                ]),
                "msg"=>$msg];
    }
}

function RandomString($count = 9, $type = "all") {
    if($type == "all") $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456789';
    elseif($type == "small") $characters = 'abcdef123456789';
    elseif($type == "domain") $characters = 'abcdefghijklmnopqrstuvwxyz';
    
    $randstring = null;
    for ($i = 0; $i < $count; $i++) {
        $randstring .= $characters[
            rand(0, strlen($characters)-1)
        ];
    }
    return $randstring;
}
function generateUID(){
    $randomString = openssl_random_pseudo_bytes(16);
    $time_low = bin2hex(substr($randomString, 0, 4));
    $time_mid = bin2hex(substr($randomString, 4, 2));
    $time_hi_and_version = bin2hex(substr($randomString, 6, 2));
    $clock_seq_hi_and_reserved = bin2hex(substr($randomString, 8, 2));
    $node = bin2hex(substr($randomString, 10, 6));

    $time_hi_and_version = hexdec($time_hi_and_version);
    $time_hi_and_version = $time_hi_and_version >> 4;
    $time_hi_and_version = $time_hi_and_version | 0x4000;

    $clock_seq_hi_and_reserved = hexdec($clock_seq_hi_and_reserved);
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved >> 2;
    $clock_seq_hi_and_reserved = $clock_seq_hi_and_reserved | 0x8000;

    return sprintf('%08s-%04s-%04x-%04x-%012s', $time_low, $time_mid, $time_hi_and_version, $clock_seq_hi_and_reserved, $node);
}
function checkStep($table){
    global $connection;
    
    if($table == "server_plans") $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `active` = 0");
    if($table == "server_categories") $stmt = $connection->prepare("SELECT * FROM `server_categories` WHERE `active` = 0");
    
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['step']; 
}
function setUser($value = 'none', $field = 'step'){
    global $connection, $from_id, $username, $first_name, $userInfo, $admin;

    $stmt = $connection->prepare("SELECT * FROM `users` WHERE `userid`=?");
    $stmt->bind_param("i", $from_id);
    $stmt->execute();
    $uinfo = $stmt->get_result();
    $stmt->close();

    
    if($uinfo->num_rows == 0){
        $stmt = $connection->prepare("INSERT INTO `users` (`userid`, `name`, `username`, `refcode`, `wallet`, `date`)
                            VALUES (?,?,?, 0,0,?)");
        $time = time();
        $stmt->bind_param("issi", $from_id, $first_name, $username, $time);
        $stmt->execute();
        $stmt->close();
    }
    
    // Guard: do NOT let generic flow resets clear a manual ban.
    // Bans are stored in `step` = 'banned' and must only be removed by admin unban.
    if($field === 'step' && $value === 'none'){
        // Prefer in-memory cache if available
        $cur = null;
        if(is_array($userInfo) && (string)($userInfo['userid'] ?? '') === (string)$from_id){
            $cur = $userInfo['step'] ?? null;
        }
        if($cur === null){
            $stmt0 = $connection->prepare("SELECT `step` FROM `users` WHERE `userid`=? LIMIT 1");
            $stmt0->bind_param('i', $from_id);
            $stmt0->execute();
            $row0 = $stmt0->get_result()->fetch_assoc();
            $stmt0->close();
            $cur = $row0['step'] ?? null;
        }
        if($cur === 'banned'){
            return;
        }
    }

    if($field == "wallet") $stmt = $connection->prepare("UPDATE `users` SET `wallet` = ? WHERE `userid` = ?");
    elseif($field == "phone") $stmt = $connection->prepare("UPDATE `users` SET `phone` = ? WHERE `userid` = ?");
    elseif($field == "refered_by") $stmt = $connection->prepare("UPDATE `users` SET `refered_by` = ? WHERE `userid` = ?");
    elseif($field == "step") $stmt = $connection->prepare("UPDATE `users` SET `step` = ? WHERE `userid` = ?");
    elseif($field == "freetrial") $stmt = $connection->prepare("UPDATE `users` SET `freetrial` = ? WHERE `userid` = ?");
    elseif($field == "isAdmin") $stmt = $connection->prepare("UPDATE `users` SET `isAdmin` = ? WHERE `userid` = ?");
    elseif($field == "first_start") $stmt = $connection->prepare("UPDATE `users` SET `first_start` = ? WHERE `userid` = ?");
    elseif($field == "temp") $stmt = $connection->prepare("UPDATE `users` SET `temp` = ? WHERE `userid` = ?");
    elseif($field == "is_agent") $stmt = $connection->prepare("UPDATE `users` SET `is_agent` = ? WHERE `userid` = ?");
    elseif($field == "discount_percent") $stmt = $connection->prepare("UPDATE `users` SET `discount_percent` = ? WHERE `userid` = ?");
    elseif($field == "agent_date") $stmt = $connection->prepare("UPDATE `users` SET `agent_date` = ? WHERE `userid` = ?");
    elseif($field == "spam_info") $stmt = $connection->prepare("UPDATE `users` SET `spam_info` = ? WHERE `userid` = ?");
    
    $stmt->bind_param("si", $value, $from_id);
    $stmt->execute();
    $stmt->close();
}
function generateRandomString($length, $protocol) {
    return ($protocol == 'trojan') ? substr(md5(time()),5,15) : generateUID();
}
function xuiDecodeField($value){
    if(is_string($value)){
        $decoded = json_decode($value);
        if(json_last_error() === JSON_ERROR_NONE) return $decoded;
        $decoded = json_decode(html_entity_decode($value));
        if(json_last_error() === JSON_ERROR_NONE) return $decoded;
        return null;
    }
    if(is_array($value) || is_object($value)) return json_decode(json_encode($value));
    return null;
}
function xuiDecodeAssoc($value){
    if(is_array($value)) return $value;
    if(is_object($value)) return json_decode(json_encode($value), true) ?: [];
    if(is_string($value)) {
        $decoded = json_decode($value, true);
        if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
        $decoded = json_decode(html_entity_decode($value), true);
        if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }
    return [];
}
function xuiNormalizeInboundId($id){
    if(is_int($id)) return $id;
    if(is_float($id)) return intval($id);
    if(is_string($id)){
        $id = trim($id);
        if($id === '') return $id;
        if(preg_match('/^-?\\d+$/', $id)) return intval($id);
        if(preg_match('/(\\d+)/', $id, $match)) return intval($match[1]);
    }
    return $id;
}
function xuiNormalizeSubBaseUrl($url, $defaultScheme = 'https'){
    $url = trim((string)$url);
    if($url === '' || strtolower($url) === '/empty') return '';
    if(!preg_match('#^https?://#i', $url)) $url = $defaultScheme . '://' . $url;
    return rtrim($url, '/');
}
function xuiGetServerSubBaseUrl($server_id, $fallbackPanelUrl = ''){
    global $connection;
    ensureServerConfigSchema();
    $stmt = $connection->prepare("SELECT `panel_url`, `sub_base_url` FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $base = trim((string)($row['sub_base_url'] ?? ''));
    if($base === '') $base = $fallbackPanelUrl !== '' ? $fallbackPanelUrl : ($row['panel_url'] ?? '');
    return xuiNormalizeSubBaseUrl($base);
}
function xuiBuildPanelSubLink($panelUrl, $subId, $preferredBase = ''){
    $baseUrl = xuiNormalizeSubBaseUrl($preferredBase !== '' ? $preferredBase : $panelUrl);
    $subId = trim((string)$subId);
    if($baseUrl === '' || $subId === '') return '';

    if(preg_match('#^https?://#i', $subId)){
        $parts = parse_url($subId);
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $path === '' ? $baseUrl : $baseUrl . $path . $query . $fragment;
    }

    if($subId[0] !== '/'){
        if(strpos($subId, '/') !== false) $subId = '/' . ltrim($subId, '/');
        else $subId = '/sub/' . $subId;
    }

    return $baseUrl . $subId;
}
function xuiResolveClientSubLink($server_id, $panelUrl, $rawSubLink = '', $inbound_id = null, $client_id = '', $remark = ''){
    $rawSubLink = trim((string)$rawSubLink);
    if($rawSubLink !== '') return xuiBuildPanelSubLink($panelUrl, $rawSubLink, xuiGetServerSubBaseUrl($server_id, $panelUrl));
    return xuiGetClientSubLink($server_id, $inbound_id, $client_id, $remark);
}
function xuiFindClientRecord($server_id, $client_id = '', $inbound_id = null, $remark = ''){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$server_info) return null;

    $response = getJson($server_id);
    if(!$response || !isset($response->obj) || !is_array($response->obj)) return null;

    $normalizedInbound = $inbound_id === null ? null : xuiNormalizeInboundId($inbound_id);
    foreach($response->obj as $row){
        $rowInboundId = xuiNormalizeInboundId($row->id ?? null);
        if($normalizedInbound !== null && $normalizedInbound !== '' && (string)$rowInboundId !== (string)$normalizedInbound) continue;
        $settings = xuiDecodeField($row->settings ?? null);
        if(!$settings || !isset($settings->clients)) continue;
        foreach($settings->clients as $client){
            $candidateId = $client->id ?? ($client->password ?? ($client->email ?? ''));
            $candidateEmail = $client->email ?? '';
            if(($client_id !== '' && (string)$candidateId === (string)$client_id) || ($remark !== '' && (string)$candidateEmail === (string)$remark)){
                $subId = $client->subId ?? '';
                return (object) [
                    'inbound' => $row,
                    'client' => $client,
                    'subId' => $subId,
                    'sub_link' => xuiBuildPanelSubLink($server_info['panel_url'], $subId, $server_info['sub_base_url'] ?? ''),
                    'panel_url' => $server_info['panel_url']
                ];
            }
        }
    }
    return null;
}
function xuiGetClientSubLink($server_id, $inbound_id = null, $client_id = '', $remark = ''){
    $found = xuiFindClientRecord($server_id, $client_id, $inbound_id, $remark);
    return $found->sub_link ?? '';
}
function xuiBotStateIsOn($botState, $key, $default = 'off'){
    return (($botState[$key] ?? $default) === 'on');
}
function xuiResolvePayAccountCount($payInfo){
    $count = (int)($payInfo['agent_count'] ?? 0);
    if($count > 0) return $count;

    $desc = trim((string)($payInfo['description'] ?? ''));
    if($desc === '') return 1;

    if(preg_match('/__GROUP_COUNT__\s*=\s*(\d+)/i', $desc, $match)){
        $count = (int)$match[1];
        return $count > 0 ? $count : 1;
    }

    $decoded = json_decode($desc, true);
    if(json_last_error() === JSON_ERROR_NONE && is_array($decoded)){
        foreach(['account_count', 'group_count', 'agent_count', 'count'] as $key){
            if(isset($decoded[$key]) && (int)$decoded[$key] > 0) return (int)$decoded[$key];
        }
    }

    return 1;
}
function xuiBuildOrderLinksText($botState, $botUrl, $uniqid, $subLink = ''){
    $sections = [];
    if(xuiBotStateIsOn($botState, 'volumeWebState', 'on') && trim((string)$uniqid) !== ''){
        $sections[] = "🔋 Volume web: <code> " . $botUrl . "search.php?id=" . $uniqid . "</code>";
    }
    if(empty($sections)) return '';
    return "\n\n" . implode("\n\n", $sections);
}
function xuiChooseOrderQrPayload($botState, $configLink = '', $subLink = ''){
    $subLink = trim((string)$subLink);
    if(xuiBotStateIsOn($botState, 'qrSubState', 'off') && $subLink !== '') return $subLink;
    return trim((string)$configLink);
}
function xuiNormalizeConfigLinks($links){
    $out = [];
    if(!is_array($links)) $links = [$links];
    foreach($links as $link){
        if(is_array($link)){
            foreach(xuiNormalizeConfigLinks($link) as $nested){
                if($nested !== '') $out[] = $nested;
            }
            continue;
        }
        $link = trim((string)$link);
        if($link === '') continue;
        $out[] = $link;
    }
    return array_values(array_unique($out));
}
function xuiBuildConfigBlockHtml($botState, $serverType, $configLinks){
    if(($botState['configLinkState'] ?? 'on') == 'off' || $serverType == 'marzban') return '';
    $configLinks = xuiNormalizeConfigLinks($configLinks);
    if(empty($configLinks)) return '';
    $first = array_shift($configLinks);
    $text = "💝 config : <code>" . $first . "</code>";
    foreach($configLinks as $link){
        $text .= "
<code>" . $link . "</code>";
    }
    return $text;
}
function xuiBuildOrderCopyButtons($configLinks = [], $subLink = '', $backCallback = 'mainMenu', $lookupKey = ''){
    global $buttonValues, $botState;
    $rows = [];
    $configLinks = xuiNormalizeConfigLinks($configLinks);
    $subLink = trim((string)$subLink);
    $lookupKey = substr(trim((string)$lookupKey), 0, 48);

    $configButtonEnabled = (($botState['configLinkState'] ?? 'on') !== 'off');
    $subButtonEnabled = (($botState['subLinkState'] ?? 'on') !== 'off');

    if($configButtonEnabled && !empty($configLinks)){
        $rows[] = [[
            'text' => '📋 کپی کانفیگ',
            'copy_text' => ['text' => implode("
", $configLinks)]
        ]];
    }

    if($subButtonEnabled && $subLink !== ''){
        $subLen = function_exists('mb_strlen') ? mb_strlen($subLink, 'UTF-8') : strlen($subLink);
        if($lookupKey !== '' && $subLen > 256){
            $rows[] = [[
                'text' => '🌐 کپی سابسکریپشن',
                'callback_data' => 'xuiCopySub_' . $lookupKey
            ]];
        }
        else{
            $rows[] = [[
                'text' => '🌐 کپی سابسکریپشن',
                'copy_text' => ['text' => $subLink]
            ]];
        }
    }

    $rows[] = [[
        'text' => $buttonValues['back_to_main'] ?? 'بازگشت',
        'callback_data' => $backCallback
    ]];
    return json_encode(['inline_keyboard' => $rows], 448);
}
function xuiBuildOrderDeliveryText($protocol, $remark, $volume, $days, $botState, $serverType, $configLinks, $botUrl, $uniqid, $subLink = ''){
    $parts = [
        '😍 سفارش جدید شما',
        '📡 پروتکل: ' . $protocol,
        '🔮 نام سرویس: ' . $remark,
        '🔋حجم سرویس: ' . $volume . ' گیگ',
        '⏰ مدت سرویس: ' . $days . ' روز'
    ];
    $extra = trim(xuiBuildOrderLinksText($botState, $botUrl, $uniqid, $subLink));
    if($extra !== '') $parts[] = $extra;
    return implode("\n", $parts);
}
function xuiBuildQrBackgroundImage($file){
    $bid = (int)($GLOBALS['currentBotInstanceId'] ?? 0);
    $bgPath = 'settings/qrcodes/qr_main.jpg';
    if($bid > 0){
        $cand = 'settings/qrcodes/qr_rb' . $bid . '.jpg';
        if(file_exists($cand)) $bgPath = $cand;
    }
    if(!file_exists($bgPath)) $bgPath = 'settings/QRCode.jpg';
    $backgroundImage = @imagecreatefromjpeg($bgPath);
    $qrImage = @imagecreatefrompng($file);
    if(!$backgroundImage || !$qrImage) return false;
    $qrSize = ['width' => imagesx($qrImage), 'height' => imagesy($qrImage)];
    imagecopy($backgroundImage, $qrImage, 300, 300, 0, 0, $qrSize['width'], $qrSize['height']);
    imagepng($backgroundImage, $file);
    imagedestroy($backgroundImage);
    imagedestroy($qrImage);
    return true;
}
function xuiSendOrderDeliveryPhoto($chatId, $protocol, $remark, $volume, $days, $botState, $serverType, $configLinks, $botUrl, $uniqid, $subLink = '', $backCallback = 'mainMenu'){
    $configLinks = xuiNormalizeConfigLinks($configLinks);
    $primaryPayload = xuiChooseOrderQrPayload($botState, $configLinks[0] ?? '', $subLink);
    $caption = xuiBuildOrderDeliveryText($protocol, $remark, $volume, $days, $botState, $serverType, $configLinks, $botUrl, $uniqid, $subLink);
    $keyboard = xuiBuildOrderCopyButtons($configLinks, $subLink, $backCallback, $uniqid);
    if(trim((string)$primaryPayload) === '') return sendMessage($caption, $keyboard, 'HTML', $chatId);
    $file = RandomString() . '.png';
    QRcode::png($primaryPayload, $file, 'L', 11, 0);
    addBorderImage($file);
    xuiBuildQrBackgroundImage($file);
    $res = sendPhoto($botUrl . $file, $caption, $keyboard, 'HTML', $chatId);
    if(file_exists($file)) @unlink($file);
    return $res;
}
function xuiFinalizeCreateResponse($server_id, $client_id, $inbound_id, $remark, $rawResponse, $generatedSubId = ''){
    $rawResponse = is_string($rawResponse) ? trim($rawResponse) : '';
    $decoded = null;
    if($rawResponse !== '') $decoded = json_decode($rawResponse);

    if($decoded && isset($decoded->success) && $decoded->success){
        if(empty($decoded->sub_link)){
            if($generatedSubId !== ''){
                $found = xuiFindClientRecord($server_id, $client_id, $inbound_id, $remark);
                $decoded->sub_link = $found ? $found->sub_link : '';
                if(empty($decoded->sub_link)){
                    $stmt = $GLOBALS['connection']->prepare("SELECT panel_url, sub_base_url FROM server_config WHERE id=?");
                    $stmt->bind_param("i", $server_id);
                    $stmt->execute();
                    $panelRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $decoded->sub_link = xuiBuildPanelSubLink($panelRow['panel_url'] ?? '', $generatedSubId, $panelRow['sub_base_url'] ?? '');
                }
            } else {
                $decoded->sub_link = xuiGetClientSubLink($server_id, $inbound_id, $client_id, $remark);
            }
        }
        return $decoded;
    }

    $found = xuiFindClientRecord($server_id, $client_id, $inbound_id, $remark);
    if($found){
        return (object) [
            'success' => true,
            'msg' => isset($decoded->msg) ? $decoded->msg : ($rawResponse !== '' ? $rawResponse : 'created-with-fallback'),
            'sub_link' => $found->sub_link,
            'obj' => $found
        ];
    }

    if($decoded) return $decoded;
    return (object) [
        'success' => false,
        'msg' => $rawResponse !== '' ? $rawResponse : 'empty response from panel'
    ];
}
function addBorderImage($add){
    $border = 30;
    $im = ImageCreateFromPNG($add);
    $width = ImageSx($im);
    $height = ImageSy($im);
    $img_adj_width = $width + 2 * $border;
    $img_adj_height = $height + 2 * $border;
    $newimage = imagecreatetruecolor($img_adj_width, $img_adj_height);
    $border_color = imagecolorallocate($newimage, 255, 255, 255);
    imagefilledrectangle($newimage, 0, 0, $img_adj_width, $img_adj_height, $border_color);
    imageCopyResized($newimage, $im, $border, $border, 0, 0, $width, $height, $width, $height);
    ImagePNG($newimage, $add, 5);
}
function sumerize($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    if($gb > 1){
      return round($gb,2) . " گیگابایت"; 
    }
    else{
        $gb *= 1024;
        return round($gb,2) . " مگابایت";
    }

}

function sumerize2($amount){
    $gb = $amount / (1024 * 1024 * 1024);
    return round($gb,2);
}
function deleteClient($server_id, $inbound_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $old_data = []; $oldclientstat = [];
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = xuiDecodeField($row->settings);
            $clients = $settings->clients;

            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client->id == $uuid || $client->password == $uuid){
                    $old_data = $client;
                    unset($clients[$key]);
                    $email = $client->email;
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings->clients = $clients;
    $settings = json_encode($settings);
	
    if($delete == 1){
        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);
        
        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        
        $loginResponse = json_decode($body,true);
        
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei" || $serverType == "alireza"){
            if($serverType == "sanaei") $url = "$panel_url/panel/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);
            elseif($serverType == "alireza") $url = "$panel_url/xui/inbound/" . $inbound_id . "/delClient/" . rawurlencode($uuid);

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
                )
            ));
        }else{
            curl_setopt_array($curl, array(
                CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,  
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => $dataArr,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array(
                    'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                    'Accept:  application/json, text/plain, */*',
                    'Accept-Language:  en-US,en;q=0.5',
                    'Accept-Encoding:  gzip, deflate',
                    'X-Requested-With:  XMLHttpRequest',
                    'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
                )
            ));
        }
        
        $response = curl_exec($curl);
        curl_close($curl);
    }	
    return ['id' => $old_data->id,'expiryTime' => $old_data->expiryTime, 'limitIp' => $old_data->limitIp, 'flow' => $old_data->flow, 'total' => $total, 'up' => $up, 'down' => $down,];

}
function editInboundRemark($server_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = xuiDecodeField($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = xuiDecodeField($row->streamSettings)->network;
            break;
        }
    }


    $dataArr = array('up' => $up,'down' => $down,'total' => $total,'remark' => $newRemark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }
    
    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInboundTraffic($server_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = xuiDecodeField($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $netType = xuiDecodeField($row->streamSettings)->network;
            
            $email = $clients[0]->email;

            break;
        }
    }
    if($days != 0) {
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
    }

    if($volume != 0){
        $leftGB = $total - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        if($editType == "renew"){
            $total = $extend_volume;
            $up = 0;
            $down = 0;
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        else $total = ($leftGB > 0) ? $total + $extend_volume : $extend_volume;
    }

    $dataArr = array('up' => $up,'down' => $down,'total' => is_null($total) ? $row->total : $total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => is_null($expire_microdate) ? $row->expiryTime : $expire_microdate, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $row->settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);
}
function changeInboundState($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $settings = xuiDecodeAssoc($row->settings);
        $clients = $settings['clients'];
        if($clients[0]['id'] == $uuid || $clients[0]['password'] == $uuid) {
            $inbound_id = $row->id;
            $enable = $row->enable;
            break;
        }
    }
    
    if(!isset($settings['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['subId'] = RandomString(16);
    if(!isset($settings['clients'][0]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);

    $newEnable = $enable == true?false:true;
    
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => $newEnable,
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }


    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $response = json_decode($response);
    return $response;

}
function renewInboundUuid($server_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $settings = xuiDecodeAssoc($row->settings);
        $clients = $settings['clients'];
        if($clients[0]['id'] == $uuid || $clients[0]['password'] == $uuid) {
            $inbound_id = $row->id;
            $total = $row->total;
            $up = $row->up;
            $down = $row->down;
            $expiryTime = $row->expiryTime;
            $port = $row->port;
            $protocol = $row->protocol;
            $netType = xuiDecodeField($row->streamSettings)->network;
            break;
        }
    }
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][0]['password'] = $newUuid;
    else $settings['clients'][0]['id'] = $newUuid;
    if(!isset($settings['clients'][0]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['subId'] = RandomString(16);
    if(!isset($settings['clients'][0]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][0]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);


    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$inbound_id";
    else $url = "$panel_url/xui/inbound/update/$inbound_id";

    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,      // timeout on connect
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    $response = json_decode($response);
    $response->newUuid = $newUuid;
    return $response;

}
function changeClientState($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = xuiDecodeAssoc($row->settings);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $enable = $client['enable'];
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    $settings['clients'][$client_key]['enable'] = $enable == true?false:true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    curl_close($curl);
    return $response;

}
function renewClientUuid($server_id, $inbound_id, $uuid){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = -1;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = xuiDecodeAssoc($row->settings);
            $clients = $settings['clients'];
            
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $protocol = $row->protocol;
                    $client_key = $key;
                    break;
                }
            }
        }
    }
    if($client_key == -1) return null;
    
    $newUuid = generateRandomString(42,$protocol); 
    if($protocol == "trojan") $settings['clients'][$client_key]['password'] = $newUuid;
    else $settings['clients'][$client_key]['id'] = $newUuid;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings,488);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    $response = json_decode($response);
    $response->newUuid = $newUuid;

    curl_close($curl);
    return $response;

}
function editClientRemark($server_id, $inbound_id, $uuid, $newRemark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = xuiDecodeAssoc($row->settings);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    $settings['clients'][$client_key]['email'] = $newRemark;
    if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
    if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;

    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);

}
function editClientTraffic($server_id, $inbound_id, $uuid, $volume, $days, $editType = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    $client_key = 0;
    foreach($response as $row){
        if($row->id == $inbound_id) {
            $settings = xuiDecodeAssoc($row->settings);
            $clients = $settings['clients'];
            
            $clientsStates = $row->clientStats;
            foreach($clients as $key => $client){
                if($client['id'] == $uuid || $client['password'] == $uuid){
                    $client_key = $key;
                    $email = $client['email'];
                    $emails = array_column($clientsStates,'email');
                    $emailKey = array_search($email,$emails);
                    
                    $total = $clientsStates[$emailKey]->total;
                    $up = $clientsStates[$emailKey]->up;
                    $enable = $clientsStates[$emailKey]->enable;
                    $down = $clientsStates[$emailKey]->down; 
                    break;
                }
            }
        }
    }
    if($volume != 0){
        $client_total = $settings['clients'][$client_key]['totalGB'];// - $up - $down;
        $extend_volume = floor($volume * 1073741824);
        $volume = ($client_total > 0) ? $client_total + $extend_volume : $extend_volume;
        if($editType == "renew"){
            $volume = $extend_volume;
            if($serverType == "sanaei" || $serverType == "alireza") resetClientTraffic($server_id, $email, $inbound_id);
            else resetClientTraffic($server_id, $email);
        }
        $settings['clients'][$client_key]['totalGB'] = $volume;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    
    if($days != 0){
        $expiryTime = $settings['clients'][$client_key]['expiryTime'];
        $now_microdate = floor(microtime(true) * 1000);
        $extend_date = (864000 * $days * 100);
        if($editType == "renew") $expire_microdate = $now_microdate + $extend_date;
        else $expire_microdate = ($now_microdate > $expiryTime) ? $now_microdate + $extend_date : $expiryTime + $extend_date;
        $settings['clients'][$client_key]['expiryTime'] = $expire_microdate;
        if(!isset($settings['clients'][$client_key]['subId']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['subId'] = RandomString(16);
        if(!isset($settings['clients'][$client_key]['enable']) && ($serverType == "sanaei" || $serverType == "alireza")) $settings['clients'][$client_key]['enable'] = true;
    }
    $editedClient = $settings['clients'][$client_key];
    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);
    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
         
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);
    
    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse; 
    } 

    if($serverType == "sanaei" || $serverType == "alireza"){
        
        $newSetting = array();
        $newSetting['clients'][] = $editedClient;
        $newSetting = json_encode($newSetting);

        $dataArr = array(
            "id"=>$inbound_id,
            "settings" => $newSetting
            );
            
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/updateClient/" . rawurlencode($uuid);
        else $url = "$panel_url/xui/inbound/updateClient/" . rawurlencode($uuid);
        
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => "$panel_url/xui/inbound/update/$inbound_id",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
    }

    $response = curl_exec($curl);
    curl_close($curl);
    resetIpLog($server_id, $email);
    return $response = json_decode($response);

}
function deleteInbound($server_id, $uuid, $delete = 0){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = xuiDecodeField($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $inbound_id = $row->id;
            $protocol = $row->protocol;
            $uniqid = ($protocol == 'trojan') ? xuiDecodeField($row->settings)->clients[0]->password : xuiDecodeField($row->settings)->clients[0]->id;
            $netType = xuiDecodeField($row->streamSettings)->network;
            $oldData = [
                'total' => $row->total,
                'up' => $row->up,
                'down' => $row->down,
                'volume' => ((int)$row->total - (int)$row->up - (int)$row->down),
                'port' => $row->port,
                'protocol' => $protocol,
                'expiryTime' => $row->expiryTime,
                'uniqid' => $uniqid,
                'netType' => $netType,
                'security' => xuiDecodeField($row->streamSettings)->security,
            ];
            break;
        }
    }
    if($delete == 1){
        $serverName = $server_info['username'];
        $serverPass = $server_info['password'];
        
        $loginUrl = $panel_url . '/login';
        
        $postFields = array(
            "username" => $serverName,
            "password" => $serverPass
            );
            
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $loginUrl);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $response = curl_exec($curl);

        $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }

        $loginResponse = json_decode($body,true);
        if(!$loginResponse['success']){
            curl_close($curl);
            return $loginResponse;
        }
        
        if($serverType == "sanaei") $url = "$panel_url/panel/inbound/del/$inbound_id";
        else $url = "$panel_url/xui/inbound/del/$inbound_id";
       
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => array(
                'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
                'Accept:  application/json, text/plain, */*',
                'Accept-Language:  en-US,en;q=0.5',
                'Accept-Encoding:  gzip, deflate',
                'X-Requested-With:  XMLHttpRequest',
                'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
            )
        ));
        $response = curl_exec($curl);
        curl_close($curl);
    }
    return $oldData;
}
function resetIpLog($server_id, $remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = $panel_url. "/panel/inbound/clearClientIps/" . urlencode($remark);
    else $url = $panel_url. "/xui/inbound/clearClientIps/" . urlencode($remark);

    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function resetClientTraffic($server_id, $remark, $inboundId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $panel_url = $server_info['panel_url'];
    $serverType = $server_info['type'];


    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    elseif($inboundId == null) $url = "$panel_url/xui/inbound/resetClientTraffic/" . rawurlencode($remark);
    else $url = "$panel_url/xui/inbound/$inboundId/resetClientTraffic/" . rawurlencode($remark);
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addInboundAccount($server_id, $client_id, $inbound_id, $expiryTime, $remark, $volume, $limitip = 1, $newarr = '', $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = rtrim($server_info['panel_url'], '/');
    $serverType = $server_info['type'];
    $reality = $server_info['reality'];
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);

    $response = getJson($server_id);
    if(!$response || !isset($response->obj)) return null;
    $response = $response->obj;
    $iid = null;
    $row = null;
    foreach($response as $item){
        if((string)xuiNormalizeInboundId($item->id ?? null) === (string)xuiNormalizeInboundId($inbound_id)) {
            $iid = $item->id;
            $protocol = $item->protocol;
            $row = $item;
            break;
        }
    }
    if($iid === null) return "inbound not Found";

    $settings = xuiDecodeAssoc($row->settings);
    $id_label = $protocol == 'trojan' ? 'password' : 'id';
    $generatedSubId = '';
    if($newarr == ''){
        if($serverType == "sanaei" || $serverType == "alireza"){
            $generatedSubId = RandomString(16);
            if($reality == "true"){
                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                $stmt->bind_param("i", $planId);
                $stmt->execute();
                $file_detail = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "flow" => $flow,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => $generatedSubId
                ];
            }else{
                $newClient = [
                    "$id_label" => $client_id,
                    "enable" => true,
                    "email" => $remark,
                    "limitIp" => $limitip,
                    "totalGB" => $volume,
                    "expiryTime" => $expiryTime,
                    "subId" => $generatedSubId
                ];
            }
        }else{
            $newClient = [
                "$id_label" => $client_id,
                "flow" => "",
                "email" => $remark,
                "limitIp" => $limitip,
                "totalGB" => $volume,
                "expiryTime" => $expiryTime
            ];
        }
        $settings['clients'][] = $newClient;
    }elseif(is_array($newarr)){
        if(($serverType == "sanaei" || $serverType == "alireza") && empty($newarr['subId'])){
            $generatedSubId = RandomString(16);
            $newarr['subId'] = $generatedSubId;
        }
        $settings['clients'][] = $newarr;
    }

    $settings['clients'] = array_values($settings['clients']);
    $settings = json_encode($settings);

    $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $row->remark,'enable' => 'true',
        'expiryTime' => $row->expiryTime, 'listen' => '','port' => $row->port,'protocol' => $row->protocol,'settings' => $settings,
        'streamSettings' => $row->streamSettings, 'sniffing' => $row->sniffing);

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $loginUrl = $panel_url . '/login';
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
    );

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    $cookieHeader = 'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]];
    $headers = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'X-Requested-With: XMLHttpRequest',
        $cookieHeader
    );

    $rawResponse = '';
    if($serverType == "sanaei" || $serverType == "alireza"){
        $newSetting = array();
        if($newarr == '') $newSetting['clients'][] = $newClient;
        elseif(is_array($newarr)) $newSetting['clients'][] = $newarr;
        $newSetting = json_encode($newSetting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $postData = array(
            "id" => xuiNormalizeInboundId($inbound_id),
            "settings" => $newSetting
        );

        $urls = $serverType == "sanaei"
            ? array($panel_url . "/panel/api/inbounds/addClient", $panel_url . "/panel/inbound/addClient/")
            : array($panel_url . "/xui/inbound/addClient/");

        foreach($urls as $url){
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array_merge($headers, array('Content-Type: application/x-www-form-urlencoded'))
            ));
            $rawResponse = curl_exec($curl);
            $decoded = $rawResponse ? json_decode($rawResponse) : null;
            if($decoded || !empty(trim((string)$rawResponse))) break;
        }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => $panel_url . "/xui/inbound/update/" . $iid,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headers
        ));
        $rawResponse = curl_exec($curl);
    }

    curl_close($curl);
    return xuiFinalizeCreateResponse($server_id, $client_id, $inbound_id, $remark, $rawResponse, $generatedSubId);
}
function getNewHeaders($netType, $request_header, $response_header, $type){
    global $connection;
    $input = explode(':', $request_header);
    $key = $input[0];
    $value = $input[1];

    $input = explode(':', $response_header);
    $reskey = $input[0];
    $resvalue = $input[1];

    $headers = '';
    if( $netType == 'tcp'){
        if($type == 'none') {
            $headers = '{
              "type": "none"
            }';
        }else {
            $headers = '{
              "type": "http",
              "request": {
                "method": "GET",
                "path": [
                  "/"
                ],
                "headers": {
                   "'.$key.'": [
                     "'.$value.'"
                  ]
                }
              },
              "response": {
                "version": "1.1",
                "status": "200",
                "reason": "OK",
                "headers": {
                   "'.$reskey.'": [
                     "'.$resvalue.'"
                  ]
                }
              }
            }';
        }

    }elseif( $netType == 'ws'){
        if($type == 'none') {
            $headers = '{}';
        }else {
            $headers = '{
              "'.$key.'": "'.$value.'"
            }';
        }
    }
    return $headers;

}
function getConnectionLink($server_id, $uniqid, $protocol, $remark, $port, $netType, $inbound_id = 0, $rahgozar = false, $customPath = false, $customPort = 0, $customSni = null){
    global $connection;

    $normalizeHostValue = function($value) {
        if(is_array($value)) $value = reset($value);
        if(is_object($value)) return '';
        $value = trim((string)$value);
        if($value === '') return '';
        $parsed = @parse_url($value);
        if(is_array($parsed)){
            if(!empty($parsed['host'])) $value = $parsed['host'];
            elseif(!empty($parsed['path']) && strpos($value, '://') !== false) $value = $parsed['path'];
        }
        return trim($value, " 	

 /");
    };

    $normalizePathValue = function($value, $default = '/') {
        if(is_array($value)) $value = reset($value);
        if(is_object($value)) return $default;
        $value = trim((string)$value);
        if($value === '') return $default;
        return (strpos($value, '/') === 0) ? $value : '/' . $value;
    };

    $buildWsQuery = function($path, $host, $includeEncryption = false) use ($normalizePathValue, $normalizeHostValue) {
        $params = [];
        $params[] = 'path=' . rawurlencode($normalizePathValue($path));
        $host = $normalizeHostValue($host);
        if($host !== '') $params[] = 'host=' . rawurlencode($host);
        if($includeEncryption) $params[] = 'encryption=none';
        return '&' . implode('&', $params);
    };

    $extractWsHeaderHost = function($wsSettingsObj, $wsSettingsAssoc, $requestHeaderRaw = '', $streamSettingsRaw = '') use ($normalizeHostValue) {
        $findHost = function($value) use (&$findHost, $normalizeHostValue) {
            if(is_array($value)){
                foreach($value as $key => $item){
                    if(is_string($key) && strtolower($key) === 'host'){
                        $host = $normalizeHostValue($item);
                        if($host !== '') return $host;
                    }
                    $host = $findHost($item);
                    if($host !== '') return $host;
                }
            }elseif(is_object($value)){
                return $findHost(get_object_vars($value));
            }elseif(is_string($value)){
                $trimmed = trim($value);
                if($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')){
                    $decoded = json_decode($trimmed, true);
                    if(json_last_error() === JSON_ERROR_NONE){
                        return $findHost($decoded);
                    }
                }
            }
            return '';
        };

        foreach([$wsSettingsObj, $wsSettingsAssoc] as $candidateNode){
            $host = $findHost($candidateNode);
            if($host !== '') return $host;
        }

        foreach([
            is_object($wsSettingsObj) ? ($wsSettingsObj->host ?? null) : null,
            is_array($wsSettingsAssoc) ? ($wsSettingsAssoc['host'] ?? null) : null,
            is_object($wsSettingsObj) ? ($wsSettingsObj->Host ?? null) : null,
            is_array($wsSettingsAssoc) ? ($wsSettingsAssoc['Host'] ?? null) : null
        ] as $candidate){
            $host = $normalizeHostValue($candidate);
            if($host !== '') return $host;
        }

        if(preg_match('/^Host\s*:\s*(.+)$/im', (string)$requestHeaderRaw, $match)){
            $host = $normalizeHostValue($match[1]);
            if($host !== '') return $host;
        }

        if(preg_match("/[\"']host[\"']\\s*:\\s*[\"']([^\"']+)[\"']/i", (string)$streamSettingsRaw, $match)){
            $host = $normalizeHostValue($match[1]);
            if($host !== '') return $host;
        }

        return '';
    };

    $extractWsExternalTargets = function($node, $streamSettingsRaw = '') use ($normalizeHostValue) {
        $targets = [];

        $appendTarget = function($host, $port) use (&$targets, $normalizeHostValue) {
            $host = $normalizeHostValue($host);
            $port = (int)$port;
            if($host === '' || $port <= 0) return;
            $targets[strtolower($host) . ':' . $port] = ['host' => $host, 'port' => $port];
        };

        $parseExternalListItem = function($item) use (&$appendTarget, $normalizeHostValue) {
            if(is_object($item)) $item = get_object_vars($item);
            if(!is_array($item)) return;

            if(array_keys($item) === range(0, count($item) - 1)){
                $host = $normalizeHostValue($item[1] ?? ($item[0] ?? ''));
                $port = $item[2] ?? ($item[1] ?? 0);
                if($host !== '' && is_numeric($port)) $appendTarget($host, $port);
                return;
            }

            $lookup = [];
            foreach($item as $key => $value){
                $lookup[strtolower((string)$key)] = $value;
            }
            foreach([
                ['host','port'], ['domain','port'], ['server','port'], ['address','port'], ['addr','port'],
                ['dest','port'], ['destination','port'], ['targethost','targetport'], ['target','targetport'],
                ['remotehost','remoteport'], ['ip','port'], ['h','p'], ['server','serverport'],
                ['host','serverport'], ['host','targetport'], ['address','destport'], ['addr','destport']
            ] as $pair){
                if(isset($lookup[$pair[0]]) && isset($lookup[$pair[1]])){
                    $appendTarget($lookup[$pair[0]], $lookup[$pair[1]]);
                    return;
                }
            }
        };

        $walk = function($value, $parentKey = '') use (&$walk, &$parseExternalListItem, &$appendTarget) {
            if(is_string($value)){
                $trimmed = trim($value);
                if($trimmed === '') return;
                if(($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '['){
                    $decoded = json_decode($trimmed, true);
                    if(json_last_error() === JSON_ERROR_NONE){
                        $walk($decoded, $parentKey);
                        return;
                    }
                }
                if(preg_match('/^([a-z0-9._-]+)\s*:\s*(\d{2,5})$/i', $trimmed, $match)){
                    $appendTarget($match[1], $match[2]);
                }
                return;
            }

            if(is_object($value)) $value = get_object_vars($value);
            if(!is_array($value)) return;

            $isList = array_keys($value) === range(0, count($value) - 1);
            if($isList){
                $parentKey = strtolower((string)$parentKey);
                if(strpos($parentKey, 'external') !== false || strpos($parentKey, 'proxy') !== false){
                    foreach($value as $item) $parseExternalListItem($item);
                    return;
                }
                foreach($value as $child) $walk($child, $parentKey);
                return;
            }

            foreach($value as $key => $child){
                $lkey = strtolower((string)$key);
                if(strpos($lkey, 'external') !== false || strpos($lkey, 'proxy') !== false){
                    if(is_array($child) || is_object($child)){
                        $childArr = is_object($child) ? get_object_vars($child) : $child;
                        if(array_keys($childArr) === range(0, count($childArr) - 1)){
                            foreach($childArr as $item) $parseExternalListItem($item);
                            continue;
                        }
                        $parseExternalListItem($childArr);
                    }
                }
                $walk($child, $lkey);
            }
        };

        $walk($node, '');

        if(empty($targets) && $streamSettingsRaw !== ''){
            if(preg_match_all("/[\"']([a-z0-9._-]+)[\"']\\s*,\\s*(\\d{2,5})/i", $streamSettingsRaw, $matches, PREG_SET_ORDER)){
                foreach($matches as $m) $appendTarget($m[1], $m[2]);
            }
        }

        return array_values($targets);
    };

    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $server_ip = $server_info['ip'];
    $sni = $server_info['sni'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    preg_match("/^Host:(.*)/i",$request_header,$hostMatch);
    $wsExternalTargets = [];

    $panel_url = str_ireplace('http://','',$panel_url);
    $panel_url = str_ireplace('https://','',$panel_url);
    $panel_url = strtok($panel_url,":");
    if($server_ip == '') $server_ip = $panel_url;

    $response = getJson($server_id)->obj;
    foreach($response as $row){
        if($inbound_id == 0){
            $clients = xuiDecodeField($row->settings)->clients;
            if($clients[0]->id == $uniqid || $clients[0]->password == $uniqid) {
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = xuiDecodeAssoc($row->settings);
                    $email = $settings['clients'][0]['email'];
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $email;
                    $remark = $row->remark;
                }
                $tlsStatus = xuiDecodeField($row->streamSettings)->security;
                $tlsSetting = xuiDecodeField($row->streamSettings)->tlsSettings;
                $xtlsSetting = xuiDecodeField($row->streamSettings)->xtlsSettings;
                $netType = xuiDecodeField($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = xuiDecodeField($row->streamSettings)->tcpSettings->header->type;
                    $path = xuiDecodeField($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = xuiDecodeField($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = xuiDecodeField($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                }
                if($netType == 'ws') {
                    $streamSettingsObj = xuiDecodeField($row->streamSettings);
                    $streamSettingsAssoc = xuiDecodeAssoc($row->streamSettings);
                    $wsSettingsObj = $streamSettingsObj->wsSettings ?? null;
                    $wsSettingsAssoc = $streamSettingsAssoc['wsSettings'] ?? [];
                    $header_type = $wsSettingsObj->header->type ?? ($wsSettingsAssoc['header']['type'] ?? 'none');
                    $path = $wsSettingsObj->path ?? ($wsSettingsAssoc['path'] ?? ($wsSettingsAssoc['wsPath'] ?? '/'));
                    $host = $extractWsHeaderHost($wsSettingsObj, $wsSettingsAssoc, $request_header, $row->streamSettings ?? '');
                    $wsExternalTargets = $extractWsExternalTargets($wsSettingsAssoc, $row->streamSettings ?? '');
                }
                if($header_type == 'http' && empty($host)){
                    $request_header = explode(':', $request_header);
                    $host = $request_header[1];
                }
                if($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->certificates->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
						if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    } 
                    elseif($tlsStatus == "reality"){
                        $realitySettings = xuiDecodeField($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $flow = $settings['clients'][0]['flow'];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $serviceName = xuiDecodeField($row->streamSettings)->grpcSettings->serviceName;
                    $grpcSecurity = xuiDecodeField($row->streamSettings)->security;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }
                if($netType == 'kcp'){
                    $kcpSettings = xuiDecodeField($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                
                break;
            }
        }else{
            if($row->id == $inbound_id) {
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = xuiDecodeField($row->settings);
                    $clients = $settings->clients;
                    foreach($clients as $key => $client){
                        if($client->id == $uniqid || $client->password == $uniqid){
                            $flow = $client->flow;
                            break;
                        }
                    }
                    // $remark = (!empty($row->remark)?($row->remark . "-"):"") . $remark;
                    $remark = $remark;
                }
                
                $port = $row->port;
                $tlsStatus = xuiDecodeField($row->streamSettings)->security;
                $tlsSetting = xuiDecodeField($row->streamSettings)->tlsSettings;
                $xtlsSetting = xuiDecodeField($row->streamSettings)->xtlsSettings;
                $netType = xuiDecodeField($row->streamSettings)->network;
                if($netType == 'tcp') {
                    $header_type = xuiDecodeField($row->streamSettings)->tcpSettings->header->type;
                    $path = xuiDecodeField($row->streamSettings)->tcpSettings->header->request->path[0];
                    $host = xuiDecodeField($row->streamSettings)->tcpSettings->header->request->headers->Host[0];
                    
                    if($tlsStatus == "reality"){
                        $realitySettings = xuiDecodeField($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                }elseif($netType == 'ws') {
                    $streamSettingsObj = xuiDecodeField($row->streamSettings);
                    $streamSettingsAssoc = xuiDecodeAssoc($row->streamSettings);
                    $wsSettingsObj = $streamSettingsObj->wsSettings ?? null;
                    $wsSettingsAssoc = $streamSettingsAssoc['wsSettings'] ?? [];
                    $header_type = $wsSettingsObj->header->type ?? ($wsSettingsAssoc['header']['type'] ?? 'none');
                    $path = $wsSettingsObj->path ?? ($wsSettingsAssoc['path'] ?? ($wsSettingsAssoc['wsPath'] ?? '/'));
                    $host = $extractWsHeaderHost($wsSettingsObj, $wsSettingsAssoc, $request_header, $row->streamSettings ?? '');
                    $wsExternalTargets = $extractWsExternalTargets($wsSettingsAssoc, $row->streamSettings ?? '');
                }elseif($netType == 'grpc') {
                    if($tlsStatus == 'tls'){
                        $alpn = $tlsSetting->alpn;
						if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                        if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                    }
                    elseif($tlsStatus == "reality"){
                        $realitySettings = xuiDecodeField($row->streamSettings)->realitySettings;
                        $fp = $realitySettings->settings->fingerprint;
                        $spiderX = $realitySettings->settings->spiderX;
                        $pbk = $realitySettings->settings->publicKey;
                        $sni = $realitySettings->serverNames[0];
                        $sid = $realitySettings->shortIds[0];
                    }
                    $grpcSecurity = xuiDecodeField($row->streamSettings)->security;
                    $serviceName = xuiDecodeField($row->streamSettings)->grpcSettings->serviceName;
                }elseif($netType == 'kcp'){
                    $kcpSettings = xuiDecodeField($row->streamSettings)->kcpSettings;
                    $kcpType = $kcpSettings->header->type;
                    $kcpSeed = $kcpSettings->seed;
                }
                if($tlsStatus == 'tls'){
                    $serverName = $tlsSetting->serverName;
					if(isset($tlsSetting->serverName)) $sni = $tlsSetting->serverName;
                    if(isset($tlsSetting->settings->serverName)) $sni = $tlsSetting->settings->serverName;
                }
                if($tlsStatus == "xtls"){
                    $serverName = $xtlsSetting->serverName;
                    $alpn = $xtlsSetting->alpn;
					if(isset($xtlsSetting->serverName)) $sni = $xtlsSetting->serverName;
                    if(isset($xtlsSetting->settings->serverName)) $sni = $xtlsSetting->settings->serverName;
                }

                break;
            }
        }


    }
    $protocol = strtolower($protocol);
    $serverIp = explode("\n",$server_ip);
    $routeTargets = [];
    if($netType == 'ws' && $rahgozar != true && !empty($wsExternalTargets)){
        foreach($wsExternalTargets as $target){
            $routeTargets[] = [
                'host' => $target['host'] ?? '',
                'port' => (int)($target['port'] ?? $port)
            ];
        }
    }else{
        foreach($serverIp as $serverIpItem){
            $routeTargets[] = [
                'host' => str_replace("\r", "", (string)$serverIpItem),
                'port' => (int)$port
            ];
        }
    }
    $outputLink = array();
    foreach($routeTargets as $routeTarget){
        $server_ip = trim((string)($routeTarget['host'] ?? ''));
        if($server_ip === '') continue;
        $effectivePort = (int)($routeTarget['port'] ?? $port);
        if($effectivePort <= 0) $effectivePort = (int)$port;
        if($inbound_id == 0) {
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                    }
                }
                $psting = '';
                if($header_type == 'http' && $rahgozar != true && $netType != "grpc") $psting .= "&path=/&host=$host";
                if($netType == "ws" && $rahgozar != true) $psting .= $buildWsQuery($path ?? '/', $host ?? '');
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1 && $tlsStatus != "reality") $psting .= "&sni=$sni";
                if(strlen($serverName)>1 && $tlsStatus=="xtls") $server_ip = $serverName;
                if($tlsStatus == "xtls" && $netType == "tcp") $psting .= "&flow=xtls-rprx-direct";
                if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                if($rahgozar == true) $psting .= $buildWsQuery(($path ?? '/') . ($customPath == true?"?ed=2048":""), $host ?? '', true);
                $outputlink = "$protocol://$uniqid@$server_ip:" . ($rahgozar == true?($customPort!="0"?$customPort:"443"):$port) . "?type=$netType&security=" . ($rahgozar==true?"tls":$tlsStatus) . "{$psting}#$remark";
                if($netType == 'grpc' && $tlsStatus != "reality"){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }
    
            if($protocol == 'trojan'){
                $psting = '';
                if($header_type == 'http') $psting .= "&path=/&host=$host";
                if($netType == 'ws') $psting .= $buildWsQuery($path ?? '/', $host ?? '');
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$effectivePort,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> $kcpType ? $kcpType : "none",
                    "host"=> ($rahgozar == true && empty($host))? $server_ip:$normalizeHostValue($host),
                    "path"=> ($rahgozar == true)?(($path ?? '/') . ($customPath == true?"?ed=2048":"")):$normalizePathValue($path, '/'),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
    
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($header_type == 'http' && $rahgozar != true){
                    $vmessArr['path'] = "/";
                    $vmessArr['type'] = $header_type;
                    $vmessArr['host'] = $host;
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
    				$vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }else { 
            if($protocol == 'vless'){
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        $subDomain = RandomString(4,"domain");
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." . $explodeAdd[2];
                            else $sni = $uniqid . "." .$host;
                        }
                    }
                }
                
                if(strlen($sni) > 1 && $tlsStatus != "reality") $psting = "&sni=$sni"; else $psting = '';
                if($netType == 'tcp'){
                    if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                    if($tlsStatus=="xtls") $psting .= "&flow=xtls-rprx-direct";
                    if($tlsStatus=="reality") $psting .= "&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX";
                    if($header_type == "http") $psting .= "&path=/&host=$host";
                    $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus{$psting}#$remark";
                }elseif($netType == 'ws'){
                    if($rahgozar == true)$outputlink = "$protocol://$uniqid@$server_ip:" . ($customPort!=0?$customPort:"443") . "?type=$netType&security=tls" . $buildWsQuery(($path ?? '/') . ($customPath == true?"?ed=2048":""), $host ?? '', true) . "{$psting}#$remark";
                    else $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus" . $buildWsQuery($path ?? '/', $host ?? '') . "{$psting}#$remark";
                }
                elseif($netType == 'kcp')
                    $outputlink = "$protocol://$uniqid@$server_ip:$port?type=$netType&security=$tlsStatus&headerType=$kcpType&seed=$kcpSeed#$remark";
                elseif($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }
                    elseif($tlsStatus=="reality"){
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName&fp=$fp&pbk=$pbk&sni=$sni" . ($flow != ""?"&flow=$flow":"") . "&sid=$sid&spx=$spiderX#$remark";
                    }
                    else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
                }
            }elseif($protocol == 'trojan'){                
                $psting = '';
                if($header_type == 'http') $psting .= "&path=/&host=$host";
                if($netType == 'tcp' and $header_type == 'http') $psting .= '&headerType=http';
                if(strlen($sni) > 1) $psting .= "&sni=$sni";
                $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus{$psting}#$remark";
                
                if($netType == 'grpc'){
                    if($tlsStatus == 'tls'){
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName&sni=$sni#$remark";
                    }else{
                        $outputlink = "$protocol://$uniqid@$server_ip:$effectivePort?type=$netType&security=$tlsStatus&serviceName=$serviceName#$remark";
                    }
    
                }
            }elseif($protocol == 'vmess'){
                $vmessArr = [
                    "v"=> "2",
                    "ps"=> $remark,
                    "add"=> $server_ip,
                    "port"=> $rahgozar == true?($customPort!=0?$customPort:443):$effectivePort,
                    "id"=> $uniqid,
                    "aid"=> 0,
                    "net"=> $netType,
                    "type"=> ($header_type) ? $header_type : ($kcpType ? $kcpType : "none"),
                    "host"=> ($rahgozar == true && empty($host))?$server_ip:$normalizeHostValue($host),
                    "path"=> ($rahgozar == true)?(($path ?? '/') . ($customPath == true?"?ed=2048":"")) : $normalizePathValue($path, '/'),
                    "tls"=> $rahgozar == true?"tls":((is_null($tlsStatus)) ? 'none' : $tlsStatus)
                ];
                if($rahgozar == true){
                    if(empty($host) && isset($hostMatch[1])) $host = $hostMatch[1];
                    
                    if(!empty($host)){
                        $subDomain = RandomString(4, "domain");
                        $parseAdd = parse_url($host);
                        $parseAdd = $parseAdd['host']??$parseAdd['path'];
                        $explodeAdd = explode(".", $parseAdd);
                        if($customSni != null) $sni = $customSni;
                        else{
                            if(count($explodeAdd) >= 3) $sni = $uniqid . "." . $explodeAdd[1] . "." .$explodeAdd[2];
                            else $sni = $uniqid . "." . $host;
                        }
                        
                        $vmessArr['alpn'] = 'http/1.1';
                    }
                }
                if($netType == 'grpc'){
                    if(!is_null($alpn) and json_encode($alpn) != '[]' and $alpn != '') $vmessArr['alpn'] = $alpn;
                    if(strlen($serviceName) > 1) $vmessArr['path'] = $serviceName;
                    $vmessArr['type'] = $grpcSecurity;
                    $vmessArr['scy'] = 'auto';
                }
                if($netType == 'kcp'){
                    $vmessArr['path'] = $kcpSeed ? $kcpSeed : $vmessArr['path'];
    	        }
    
                if(strlen($sni) > 1) $vmessArr['sni'] = $sni;
                $urldata = base64_encode(json_encode($vmessArr,JSON_UNESCAPED_SLASHES,JSON_PRETTY_PRINT));
                $outputlink = "vmess://$urldata";
            }
        }
        $outputLink[] = $outputlink;
    }

    return $outputLink;
}
function updateConfig($server_id, $inboundId, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings,488|JSON_UNESCAPED_UNICODE);
    }
    
    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        if($row->id == $inboundId) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
        }
        
        
                $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }
			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                }
            }
            $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
        }

        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function editInbound($server_id, $uniqid, $uuid, $protocol, $netType = 'tcp', $security = 'none', $rahgozar = false){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $sni = $server_info['sni'];
    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }

    $response = getJson($server_id);
    if(!$response) return null;
    $response = $response->obj;
    foreach($response as $row){
        $clients = xuiDecodeField($row->settings)->clients;
        if($clients[0]->id == $uuid || $clients[0]->password == $uuid) {
            $iid = $row->id;
            $remark = $row->remark;
            $streamSettings = $row->streamSettings;
            $settings = $row->settings;
            break;
        }
    }
    if(!intval($iid)) return;

    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $headers = empty($headers)?"{}":$headers;

    if($protocol == 'trojan'){
        if($security == 'none'){
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

    	if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . RandomString(16) . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}else{
            $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$uniqid.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
    	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';

                $settings = '{
              "clients": [
                {
                  "id": "'.$uniqid.'",
    			  "flow": "xtls-rprx-direct".
    			  "email": "' . $remark. '"
                }
              ],
              "decryption": "none",
        	  "fallbacks": []
            }';
        }
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
              "enable": true,
			  "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . RandomString(16) . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$uniqid.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }
        
        
                $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }else{
        if($netType != "grpc"){
            if($rahgozar == true){
                $wsSettings = '{
                      "network": "ws",
                      "security": "none",
                      "wsSettings": {
                        "path": "/wss' . $row->port . '",
                        "headers": {}
                      }
                    }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$client_id.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
            else{
                if($security == 'tls') {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "tlsSettings": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
            	  "tcpSettings": {
                    "header": '.$headers.'
                  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle . '": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "enable": true,
                      "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }else{
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
        			  "flow": "",
        			  "email": "' . $remark. '"
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
                }
                else {
                    $tcpSettings = '{
            	  "network": "tcp",
            	  "security": "none",
            	  "tcpSettings": {
            		"header": '.$headers.'
            	  }
            	}';
                    $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/",
                    "headers": {}
                  }
                }';
                if($serverType == "sanaei" || $serverType == "alireza"){
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
                      "enable": true,
            		  "email": "' . $remark. '",
                      "limitIp": 0,
                      "totalGB": 0,
                      "expiryTime": 0,
                      "subId": "' . RandomString(16) . '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }else{
                    $settings = '{
            	  "clients": [
            		{
            		  "id": "'.$uniqid.'",
            		  "flow": "",
            		  "email": "' . $remark. '"
            		}
            	  ],
            	  "decryption": "none",
            	  "fallbacks": []
            	}';
                }
                }
            }
            $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
        }


        $dataArr = array('up' => $row->up,'down' => $row->down,'total' => $row->total,'remark' => $remark,'enable' => 'true',
            'expiryTime' => $row->expiryTime,'listen' => '','port' => $row->port,'protocol' => $protocol,'settings' => $settings,
            'streamSettings' => $streamSettings,
            'sniffing' => $row->sniffing);
    }



    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    if($serverType == "sanaei") $url = "$panel_url/panel/inbound/update/$iid";
    else $url = "$panel_url/xui/inbound/update/$iid";
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $dataArr,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function getMarzbanToken($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id = ?");
    $stmt->bind_param('i', $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $panel_url = $server_info['panel_url'];
    $username = $server_info['username'];
    $password = $server_info['password'];
    
    $loginUrl = $panel_url .'/api/admin/token';
    $postFields = array(
        'username' => $username,
        'password' => $password
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
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return (object) ['success'=>false, 'detail'=>curl_error($curl)];
    }
    curl_close($curl);

    return json_decode($response);
}
function getMarzbanJson($server_id, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/users';
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    return $response;
}
function getMarzbanUserInfo($server_id, $remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $configInfo = array();
    $curl = curl_init();
    for($i = 0; $i <= 10; $i++){
        $info = getMarzbanUser($server_id, $remark);
		$subLink = "/sub/" . (explode("/sub/", $info->subscription_url)[1]);
		$info->subscription_url = $subLink;
        curl_setopt($curl, CURLOPT_URL, $panel_url . $info->subscription_url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
        $response = curl_exec($curl);
        if($response && !curl_error($curl)){
            $configInfo = $info;
            break;
        }
		if($i == 10) $configInfo = $info;
    }
    curl_close($curl);

    return (object) $configInfo;
}
function getMarzbanUser($server_id, $remark, $token = null){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    
    $panel_url .= '/api/user/' . urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function getMarzbanHosts($server_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .= '/api/core/config';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' . $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    
    curl_close($curl);
    return $response;
}
function addMarzbanUser($server_id, $remark, $volume, $days, $plan_id){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    
    $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id` = ?");
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $planInfo = json_decode($stmt->get_result()->fetch_assoc()['custom_sni'],true);
    $stmt->close();

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $postFields = array(
        "inbounds" => $planInfo['inbounds'],
        "proxies" => $planInfo['proxies'],
        "expire" => time() + (86400 * $days),
        "data_limit" => $volume * 1073741824,
        "username" => urlencode($remark)
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url . "/api/user");
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail) || !isset($response->links)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $userInfo = getMarzbanUserInfo($server_id, $remark);

    return (object) [
        'success'=>true,
        'sub_link'=> $userInfo->subscription_url,
        'vray_links' => $response->links
        ];
}
function editMarzbanConfig($server_id,$info){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->error];}

    $remark = $info['remark'];
    $configInfo = getMarzbanUser($server_id, $remark, $token);
    
    
    $expireTime = $configInfo->expire;
    $volume = $configInfo->data_limit;
    $configState = $configInfo->status;
    
    if(isset($info['plus_day'])){
        if($expireTime < time()) $expireTime = time() + (86400 * $info['plus_day']);
        else $expireTime += (86400 * $info['plus_day']);
    }
    elseif(isset($info['days'])) $expireTime = time() + (86400 * $info['days']);
    
    if(isset($info['plus_volume'])) $volume += $info['plus_volume'] * 1073741824;
    elseif(isset($info['volume'])){
        $volume = $info['volume'] * 1073741824;
        $response = resetMarzbanTraffic($server_id, $remark, $token);
        
        if(!$response->success) return $response;
    }
    
    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $expireTime,
        "data_limit" => $volume,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => "active"
    );
    
    $panel_url .=  '/api/user/'. $remark;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
        ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function resetMarzbanTraffic($server_id, $remark, $token){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];

    if($token == null) $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}

    $panel_url .=  '/api/user/' . $remark .'/reset';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function renewMarzbanUUID($server_id,$remark){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .= '/api/user/' . $remark .'/revoke_sub';

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_POST , true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    $response = getMarzbanUserInfo($server_id, $remark);
    return $response;
}

function deleteMarzban($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $panel_url .=  '/api/user/'. urlencode($remark);

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($curl, CURLOPT_HTTPGET, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token
    ));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);
    
    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    
    return (object) ['success'=>true];
}
function changeMarzbanState($server_id,$remark){
    global $connection;
    
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];
    
    $token = getMarzbanToken($server_id);
    if(isset($token->detail)){return (object) ['success'=>false, 'msg'=>$token->detail];}
    $configInfo = getMarzbanUser($server_id, $remark, $token);

    $panel_url .=  '/api/user/'. $remark;

    $postFields = array(
        "inbounds" => $configInfo->inbounds,
        "proxies" => $configInfo->proxies,
        "expire" => $configInfo->expire,
        "data_limit" => $configInfo->data_limit,
        "username" => urlencode($remark),
        "note" => $configInfo->note,
        "data_limit_reset_strategy"=> $configInfo->data_limit_reset_strategy,
        "status" => $configInfo->status == "active"?"disabled":"active"
    );


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $panel_url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Accept: application/json',
        'Authorization: Bearer ' .  $token->access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($postFields));

    $response = json_decode(curl_exec($curl));
    curl_close($curl);

    if(isset($response->detail)){
		$detail = $response->detail;
        return (object) ['success'=>false, 'msg' => is_object($detail)?implode("-", (array) $detail):$detail];
    }
    return (object) ['success'=>true];
}
function getJson($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = rtrim($server_info['panel_url'], '/');
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    $serverType = $server_info['type'];

    $loginUrl = $panel_url . '/login';
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
    );

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }

    $cookieHeader = 'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]];
    $commonHeaders = array(
        'User-Agent: Mozilla/5.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'X-Requested-With: XMLHttpRequest',
        $cookieHeader
    );

    $attempts = array();
    if($serverType == "sanaei" || $serverType == "alireza"){
        $attempts[] = array('url' => $panel_url . "/panel/api/inbounds/list", 'method' => 'GET');
        $attempts[] = array('url' => $panel_url . "/panel/inbound/list", 'method' => 'POST');
    }else{
        $attempts[] = array('url' => $panel_url . "/xui/inbound/list", 'method' => 'POST');
    }

    $lastResponse = '';
    foreach($attempts as $attempt){
        curl_setopt_array($curl, array(
            CURLOPT_URL => $attempt['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $attempt['method'],
            CURLOPT_POSTFIELDS => $attempt['method'] === 'POST' ? '' : null,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $commonHeaders,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $lastResponse = curl_exec($curl);
        $decoded = is_string($lastResponse) ? json_decode($lastResponse) : null;
        if($decoded && isset($decoded->obj) && is_array($decoded->obj)){
            curl_close($curl);
            return $decoded;
        }
    }

    curl_close($curl);
    return json_decode($lastResponse);
}
function getNewCert($server_id){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];

    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);
    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => "$panel_url/server/getNewX25519Cert",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array(
            'User-Agent:  Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
            'Accept:  application/json, text/plain, */*',
            'Accept-Language:  en-US,en;q=0.5',
            'Accept-Encoding:  gzip, deflate',
            'X-Requested-With:  XMLHttpRequest',
            'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]]
        )
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    return $response = json_decode($response);
}
function addUser($server_id, $client_id, $protocol, $port, $expiryTime, $remark, $volume, $netType, $security = 'none', $rahgozar = false, $planId = null){
    global $connection;
    $stmt = $connection->prepare("SELECT * FROM server_config WHERE id=?");
    $stmt->bind_param("i", $server_id);
    $stmt->execute();
    $server_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $panel_url = $server_info['panel_url'];
    $security = $server_info['security'];
    $tlsSettings = $server_info['tlsSettings'];
    $header_type = $server_info['header_type'];
    $request_header = $server_info['request_header'];
    $response_header = $server_info['response_header'];
    $sni = $server_info['sni'];
    $serverType = $server_info['type'];
    $xtlsTitle = ($serverType == "sanaei" || $serverType == "alireza")?"XTLSSettings":"xtlsSettings";
    $reality = $server_info['reality'];

    if(!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza")){
        $tlsSettings = json_decode($tlsSettings,true);
        $tlsSettings['serverName'] = $sni;
        $tlsSettings = json_encode($tlsSettings);
    }
    
    $volume = ($volume == 0) ? 0 : floor($volume * 1073741824);
    $headers = getNewHeaders($netType, $request_header, $response_header, $header_type);
    $generatedSubId = RandomString(16);
//---------------------------------------Trojan------------------------------------//
    if($protocol == 'trojan'){
        // protocol trojan
        if($security == 'none'){
            
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
            $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'", 
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            
        	if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . $generatedSubId . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
        	}
        }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                    $tcpSettings = '{
                	  "network": "tcp",
                	  "security": "'.$security.'",
                	  "' . $xtlsTitle . '": '.$tlsSettings.',
                	  "tcpSettings": {
                        "header": '.$headers.'
                      }
                	}';

                    $wsSettings = '{
                  "network": "ws",
                  "security": "'.$security.'",
            	  "' . $xtlsTitle .'": '.$tlsSettings.',
                  "wsSettings": {
                    "path": "/",
                    "headers": '.$headers.'
                  }
                }';
                    $settings = '{
                  "clients": [
                    {
                      "id": "'.$uniqid.'",
                      "alterId": 0
                    }
                  ],
                  "decryption": "none",
            	  "fallbacks": []
                }';
                }
        
        else{
            $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
		if($serverType == "sanaei" || $serverType == "alireza"){
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
              "enable": true,
              "email": "' . $remark. '",
              "limitIp": 0,
              "totalGB": 0,
              "expiryTime": 0,
              "subId": "' . $generatedSubId . '"
			}
		  ],
		  "fallbacks": []
		}';
		}else{
            $settings = '{
		  "clients": [
			{
			  "password": "'.$client_id.'",
			  "flow": "",
			  "email": "' . $remark. '"
			}
		  ],
		  "fallbacks": []
		}';
		}
        }



        $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc'){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' .
    (!empty($sni) && ($serverType == "sanaei" || $serverType == "alireza") ?  $sni: parse_url($panel_url, PHP_URL_HOST))
     . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []'
    .'
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }




        // trojan
        $dataArr = array('up' => '0','down' => '0','total' => $volume,'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings,
            'sniffing' => '{
      "enabled": true,
      "destOverride": [
        "http",
        "tls"
      ]
    }');
    }else {
//-------------------------------------- vmess vless -------------------------------//
        if($rahgozar == true){
            $wsSettings = '{
                  "network": "ws",
                  "security": "none",
                  "wsSettings": {
                    "path": "/wss' . $port . '",
                    "headers": {}
                  }
                }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
                  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . $generatedSubId . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
        }else{
            if($security == 'tls') {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "tlsSettings": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "enable": true,
                  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . $generatedSubId . '"
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else{
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }
            }elseif($security == 'xtls' && $serverType != "sanaei" && $serverType != "alireza") {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
        	  "tcpSettings": {
                "header": '.$headers.'
              }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "'.$security.'",
        	  "' . $xtlsTitle . '": '.$tlsSettings.',
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
                $settings = '{
              "clients": [
                {
                  "id": "'.$client_id.'",
                  "alterId": 0
                }
              ],
              "disableInsecureEncryption": false
            }';
            }else {
                $tcpSettings = '{
        	  "network": "tcp",
        	  "security": "none",
        	  "tcpSettings": {
        		"header": '.$headers.'
        	  }
        	}';
                $wsSettings = '{
              "network": "ws",
              "security": "none",
              "wsSettings": {
                "path": "/",
                "headers": '.$headers.'
              }
            }';
            if($serverType == "sanaei" || $serverType == "alireza"){
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "enable": true,
        		  "email": "' . $remark. '",
                  "limitIp": 0,
                  "totalGB": 0,
                  "expiryTime": 0,
                  "subId": "' . $generatedSubId . '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }else{
                $settings = '{
        	  "clients": [
        		{
        		  "id": "'.$client_id.'",
        		  "flow": "",
        		  "email": "' . $remark. '"
        		}
        	  ],
        	  "decryption": "none",
        	  "fallbacks": []
        	}';
            }
            }
        }
        
        
		if($protocol == 'vless'){
		    if($serverType =="sanaei" || $serverType == "alireza"){
		        if($reality == "true"){
	                $stmt = $connection->prepare("SELECT * FROM `server_plans` WHERE `id`=?");
                    $stmt->bind_param("i", $planId);
                    $stmt->execute();
                    $file_detail = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                
                    $dest = !empty($file_detail['dest'])?$file_detail['dest']:"yahoo.com";
                    $serverNames = !empty($file_detail['serverNames'])?$file_detail['serverNames']:
                                '[
                                    "yahoo.com",
                                    "www.yahoo.com"
                                ]';
                    $spiderX = !empty($file_detail['spiderX'])?$file_detail['spiderX']:"";
                    $flow = isset($file_detail['flow']) && $file_detail['flow'] != "None" ? $file_detail['flow'] : "";
                    


		            $certInfo = getNewCert($server_id)->obj;
		            $publicKey = $certInfo->publicKey;
		            $privateKey = $certInfo->privateKey;
		            $shortId = RandomString(8, "small");
		            $serverName = json_decode($tlsSettings,true)['serverName'];
		            if($netType == "grpc"){
    		            $tcpSettings = '{
                          "network": "grpc",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "grpcSettings": {
                            "serviceName": "",
                    		"multiMode": false
                          }
                        }';
		            }else{
    		            $tcpSettings = '{
                          "network": "tcp",
                          "security": "reality",
                          "realitySettings": {
                            "show": false,
                            "xver": 0,
                            "dest": "' . $dest . '",
                            "serverNames":' . $serverNames . ',
                            "privateKey": "' . $privateKey . '",
                            "minClient": "",
                            "maxClient": "",
                            "maxTimediff": 0,
                            "shortIds": [
                              "' . $shortId .'"
                            ],
                            "settings": {
                              "publicKey": "' . $publicKey . '",
                              "fingerprint": "firefox",
                              "serverName": "' . $serverName . '",
                              "spiderX": "' . $spiderX . '"
                            }
                          },
                          "tcpSettings": {
                            "acceptProxyProtocol": false,
                    		"header": '.$headers.'
                          }
                        }';
		            }
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "flow": "' . $flow .'",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . $generatedSubId . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		            $netType = "tcp";
		        }else{
    			    $settings = '{
        			  "clients": [
        				{
        				  "id": "'.$client_id.'",
        				  "enable": true,
                          "email": "' . $remark. '",
                          "limitIp": 0,
                          "totalGB": 0,
                          "expiryTime": 0,
                          "subId": "' . $generatedSubId . '"
        				}
        			  ],
        			  "decryption": "none",
        			  "fallbacks": []
        			}';
		        }
		    }else{
			$settings = '{
			  "clients": [
				{
				  "id": "'.$client_id.'",
				  "flow": "",
				  "email": "' . $remark. '"
				}
			  ],
			  "decryption": "none",
			  "fallbacks": []
			}';
		    }
		}

        $streamSettings = ($netType == 'tcp') ? $tcpSettings : $wsSettings;
		if($netType == 'grpc' && $reality != "true"){
		    $keyFileInfo = json_decode($tlsSettings,true);
		    $certificateFile = "/root/cert.crt";
		    $keyFile = '/root/private.key';
		    
		    if(isset($keyFileInfo['certificates'])){
		        $certificateFile = $keyFileInfo['certificates'][0]['certificateFile'];
		        $keyFile = $keyFileInfo['certificates'][0]['keyFile'];
		    }

			if($security == 'tls') {
				$streamSettings = '{
  "network": "grpc",
  "security": "tls",
  "tlsSettings": {
    "serverName": "' . parse_url($panel_url, PHP_URL_HOST) . '",
    "certificates": [
      {
        "certificateFile": "' . $certificateFile . '",
        "keyFile": "' . $keyFile . '"
      }
    ],
    "alpn": []
  },
  "grpcSettings": {
    "serviceName": ""
  }
}';
		    }else{
			$streamSettings = '{
  "network": "grpc",
  "security": "none",
  "grpcSettings": {
    "serviceName": "' . parse_url($panel_url, PHP_URL_HOST) . '"
  }
}';
		}
	    }

        if(($serverType == "sanaei" || $serverType == "alireza") && $reality == "true"){
            $sniffing = '{
              "enabled": true,
              "destOverride": [
                "http",
                "tls",
                "quic"
              ]
            }';
        }else{
            $sniffing = '{
        	  "enabled": true,
        	  "destOverride": [
        		"http",
        		"tls"
        	  ]
        	}';
        }
        // vmess - vless
        $dataArr = array('up' => '0','down' => '0','total' => $volume, 'remark' => $remark,'enable' => 'true','expiryTime' => $expiryTime,'listen' => '','port' => $port,'protocol' => $protocol,'settings' => $settings,'streamSettings' => $streamSettings
        ,'sniffing' => $sniffing);
    }
    
    $phost = str_ireplace('https://','',str_ireplace('http://','',$panel_url));
    $serverName = $server_info['username'];
    $serverPass = $server_info['password'];
    
    $loginUrl = $panel_url . '/login';
    
    $postFields = array(
        "username" => $serverName,
        "password" => $serverPass
        );
        
        
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $loginUrl);
    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($curl, CURLOPT_HEADER, 1);
    $response = curl_exec($curl);

    $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header, $matches);
    $cookies = array();
    foreach($matches[1] as $item) {
        parse_str($item, $cookie);
        $cookies = array_merge($cookies, $cookie);
    }

    $loginResponse = json_decode($body,true);

    if(!$loginResponse['success']){
        curl_close($curl);
        return $loginResponse;
    }
    
    $cookieHeader = 'Cookie: ' . array_keys($cookies)[0] . "=" . $cookies[array_keys($cookies)[0]];
    $commonHeaders = array(
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:108.0) Gecko/20100101 Firefox/108.0',
        'Accept: application/json, text/plain, */*',
        'Accept-Language: en-US,en;q=0.5',
        'Accept-Encoding: gzip, deflate',
        'X-Requested-With: XMLHttpRequest',
        $cookieHeader
    );

    $rawResponse = '';
    if($serverType == "sanaei" || $serverType == "alireza"){
        $urls = array($panel_url . "/panel/api/inbounds/add", $panel_url . "/panel/inbound/add");
        foreach($urls as $url){
            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => http_build_query($dataArr),
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => array_merge($commonHeaders, array('Content-Type: application/x-www-form-urlencoded'))
            ));
            $rawResponse = curl_exec($curl);
            $decoded = $rawResponse ? json_decode($rawResponse) : null;
            if($decoded || !empty(trim((string)$rawResponse))) break;
        }
    }else{
        curl_setopt_array($curl, array(
            CURLOPT_URL => $panel_url . "/xui/inbound/add",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $dataArr,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $commonHeaders
        ));
        $rawResponse = curl_exec($curl);
    }
    curl_close($curl);
    return xuiFinalizeCreateResponse($server_id, $client_id, null, $remark, $rawResponse, $generatedSubId);
}




// -------- Admin: Rename built-in buttons / start text
function getRenameButtonsKeys($page=0){
    global $buttonValues;
    $keys = array();
    $btnKeys = array_keys($buttonValues);

    // add start_message pseudo key at first
    array_unshift($btnKeys, "start_message");

    // add bot updating message pseudo key
    array_splice($btnKeys, 1, 0, ["bot_is_updating"]);

    // bring cart_to_cart closer to top if exists (easier to find)
    $important = ["cart_to_cart"];
    foreach($important as $imp){
        $pos = array_search($imp, $btnKeys, true);
        if($pos !== false){
            unset($btnKeys[$pos]);
            array_splice($btnKeys, 1, 0, [$imp]); // after start_message
            $btnKeys = array_values($btnKeys);
        }
    }

    $perPage = 60; // 60 items => 20 rows of 3
    $page = (int)$page;
    $start = $page*$perPage;
    $slice = array_slice($btnKeys, $start, $perPage);

    // build 3-column rows
    $row = array();
    foreach($slice as $k){
        if($k=="start_message"){
            $title = "پیام شروع";
        }elseif($k=="bot_is_updating"){
            $title = "متن بروزرسانی ربات";
        }else{
            $title = $buttonValues[$k];
        }
        $row[] = ['text'=>$title,'callback_data'=>"renameBtnKey_" . $k];
        if(count($row) == 3){
            $keys[] = $row;
            $row = array();
        }
    }
    if(count($row)>0) $keys[] = $row;

    $nav = array();
    if($start>0) $nav[] = ['text'=>"⬅️ قبلی",'callback_data'=>"renameButtonsPage" . ($page-1)];
    if(($start+$perPage) < count($btnKeys)) $nav[] = ['text'=>"بعدی ➡️",'callback_data'=>"renameButtonsPage" . ($page+1)];
    if(count($nav)>0) $keys[] = $nav;

    $keys[] = [['text'=>"🔙 بازگشت",'callback_data'=>"mainMenuButtons"]];
    return json_encode(['inline_keyboard'=>$keys],488);
}

// -------- Admin: Main menu layout / arrangement
function getArrangeButtonsMenuKeys(){
    global $buttonValues;
    $cols = (int)getSettingValue("MAIN_MENU_COLUMNS","2");
    if($cols < 1) $cols = 1;
    if($cols > 3) $cols = 3;
    $swapBuy = getSettingValue("MAIN_MENU_SWAP_BUY","0") === "1";
    $swapServices = getSettingValue("MAIN_MENU_SWAP_SERVICES","0") === "1";

    $colsLabel = ($cols==1) ? "۱ ستون" : (($cols==2) ? "۲ ستون" : "۳ ستون");
    $buySide = $swapBuy ? "چپ" : "راست";
    $srvSide = $swapServices ? "چپ" : "راست";

    $keys = [
        [['text'=>"🧱 تعداد ستون‌ها: ".$colsLabel, 'callback_data'=>"cycleMainCols"]],
        [['text'=>"🛒 خرید کانفیگ سمت: ".$buySide, 'callback_data'=>"toggleSwapBuy"]],
        [['text'=>"🧾 سرویس‌ها سمت: ".$srvSide, 'callback_data'=>"toggleSwapServices"]],
        [['text'=>"↕️ چینش همه دکمه‌ها", 'callback_data'=>"arrangeMainOrderText"]],
        [['text'=>"🔙 بازگشت", 'callback_data'=>"mainMenuButtons"]],
    ];
    return json_encode(['inline_keyboard'=>$keys],488);
}

// -------- Admin: Arrange MAIN_BUTTONS (custom) order
function getArrangeMainButtonsKeys(){
    global $connection, $buttonValues;
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    $items = array();
    while($row=$buttons->fetch_assoc()){
        if(strpos($row['type'],"MAIN_BUTTONS")===0){
            $items[] = ['id'=>$row['id'],'title'=>str_replace("MAIN_BUTTONS","",$row['type'])];
        }
    }
    // load order
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type`='MAIN_BUTTONS_ORDER'");
    $stmt->execute();
    $orderVal = $stmt->get_result()->fetch_assoc()['value']??null;
    $stmt->close();
    $order = $orderVal?json_decode($orderVal,true):array();
    if(!is_array($order)) $order=array();
    // build ordered list
    $map = array();
    foreach($items as $it){ $map[$it['id']] = $it; }
    $ordered=array();
    foreach($order as $oid){
        if(isset($map[$oid])){ $ordered[] = $map[$oid]; unset($map[$oid]); }
    }
    foreach($map as $it){ $ordered[]=$it; }
    $keys=array();
    if(count($ordered)==0){
        $keys[]=[['text'=>"دکمه ای یافت نشد ❕",'callback_data'=>"deltach"]];
    }else{
        for($i=0;$i<count($ordered);$i++){
            $id=$ordered[$i]['id'];
            $title=$ordered[$i]['title'];
            $row=array();
            $row[]=['text'=>"⬆️",'callback_data'=>"moveMainBtn_up_" . $id];
            $row[]=['text'=>$title,'callback_data'=>"deltach"];
            $row[]=['text'=>"⬇️",'callback_data'=>"moveMainBtn_down_" . $id];
            $keys[]=$row;
        }
    }
    $keys[]=[['text'=>"✏️ تنظیم ترتیب با ارسال شماره‌ها",'callback_data'=>"arrangeMainOrderText"]];
$keys[]=[['text'=>"🔙 بازگشت",'callback_data'=>"mainMenuButtons"]];
    return json_encode(['inline_keyboard'=>$keys],488);
}
function moveMainButtonOrder($rowId, $dir){
    global $connection;
    $rowId = (int)$rowId;
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type` LIKE '%MAIN_BUTTONS%'");
    $stmt->execute();
    $buttons = $stmt->get_result();
    $stmt->close();
    $ids=array();
    while($row=$buttons->fetch_assoc()){
        if(strpos($row['type'],"MAIN_BUTTONS")===0) $ids[]=(int)$row['id'];
    }
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type`='MAIN_BUTTONS_ORDER'");
    $stmt->execute();
    $orderVal = $stmt->get_result()->fetch_assoc()['value']??null;
    $stmt->close();
    $order = $orderVal?json_decode($orderVal,true):array();
    if(!is_array($order)) $order=array();
    // merge with current ids
    $current=array();
    foreach($order as $oid){
        if(in_array((int)$oid,$ids,true)) $current[]=(int)$oid;
    }
    foreach($ids as $id){
        if(!in_array($id,$current,true)) $current[]=$id;
    }
    $idx=array_search($rowId,$current,true);
    if($idx===false) return;
    if($dir=="up" && $idx>0){
        $tmp=$current[$idx-1]; $current[$idx-1]=$current[$idx]; $current[$idx]=$tmp;
    }
    if($dir=="down" && $idx < count($current)-1){
        $tmp=$current[$idx+1]; $current[$idx+1]=$current[$idx]; $current[$idx]=$tmp;
    }
    $newVal=json_encode($current);
    $stmt = $connection->prepare("SELECT * FROM `setting` WHERE `type`='MAIN_BUTTONS_ORDER'");
    $stmt->execute();
    $exists=$stmt->get_result()->num_rows;
    $stmt->close();
    if($exists>0){
        $stmt=$connection->prepare("UPDATE `setting` SET `value`=? WHERE `type`='MAIN_BUTTONS_ORDER'");
        $stmt->bind_param("s",$newVal);
    }else{
        $type="MAIN_BUTTONS_ORDER";
        $stmt=$connection->prepare("INSERT INTO `setting` (`type`,`value`) VALUES (?,?)");
        $stmt->bind_param("ss",$type,$newVal);
    }
    $stmt->execute();
    $stmt->close();
}
