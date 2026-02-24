<?php
require_once __DIR__ . "/../config.php";

// Tron wallet must be configured
if(empty($paymentKeys['tronwallet'])) exit();
$wallet = $paymentKeys['tronwallet'];

// 1) Process mother bot (main DB) payments
$GLOBALS['currentBotInstanceId'] = 0;
include __DIR__ . "/tronChecker_worker.php";

// 2) Process reseller (child) bots payments too (each with its own DB + token)
if(function_exists('ensureResellerTables')){
    ensureResellerTables();
}
if(isset($connection) && $connection){
    $res = $connection->query("SELECT `id`,`db_name`,`bot_token`,`admin_userid` FROM `reseller_bots` WHERE `status`=1 AND `is_deleted`=0 AND `db_name` IS NOT NULL AND `db_name` <> ''");
    if($res){
        while($rb = $res->fetch_assoc()){
            $bid = (int)($rb['id'] ?? 0);
            $dbName = $rb['db_name'] ?? '';
            $token = $rb['bot_token'] ?? '';
            $adminId = (int)($rb['admin_userid'] ?? 0);
            if($bid <= 0 || $dbName === '' || $token === '') continue;

            // switch bot context (token/admin) + switch db connection
            $GLOBALS['currentBotInstanceId'] = $bid;
            $GLOBALS['botToken'] = $token;
            if($adminId > 0) $GLOBALS['admin'] = $adminId;

            // open child db connection
            $child = @new mysqli('localhost', $dbUserName, $dbPassword, $dbName);
            if($child && !$child->connect_error){
                $child->set_charset('utf8mb4');
                $connection = $child; // worker uses global $connection
                include __DIR__ . "/tronChecker_worker.php";
                @mysqli_close($child);
            }
        }
    }
}
