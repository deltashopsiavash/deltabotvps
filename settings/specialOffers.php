<?php
require_once __DIR__.'/../baseInfo.php';

// The mother cron fans out to active reseller bots; child executions switch
// token and database through config.php's existing bid mechanism.
$bid=isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
if(PHP_SAPI==='cli') $bid=(int)($argv[1] ?? $bid);
if($bid>0) $_GET['bid']=$bid;

require_once __DIR__.'/../config.php';

if($bid<=0 && function_exists('ensureResellerTables')){
    ensureResellerTables();
    $res=$connection->query("SELECT `id` FROM `reseller_bots` WHERE `status`=1 AND `is_deleted`=0");
    if($res){
        while($row=$res->fetch_assoc()){
            $childId=(int)$row['id'];
            if($childId<=0) continue;
            $php=(defined('PHP_BINARY') && PHP_BINARY!=='') ? PHP_BINARY : 'php';
            $command='nohup '.escapeshellarg($php).' '.escapeshellarg(__FILE__).' '.escapeshellarg((string)$childId).' >/dev/null 2>&1 &';
            if(function_exists('exec')) @exec($command);
            elseif(function_exists('shell_exec')) @shell_exec($command);
        }
    }
}

specialOfferReleaseExpiredReservations();
specialOfferSyncCompletedSales();
$sent=specialOfferDispatchStartNotifications(500);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'sent'=>$sent,'bot_id'=>$bid],JSON_UNESCAPED_UNICODE);

