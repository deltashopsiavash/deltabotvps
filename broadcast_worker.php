<?php
// Broadcast worker: processes send_list queue in background to avoid webhook timeouts.
// Usage: php broadcast_worker.php [bid]
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@set_time_limit(0);

if (php_sapi_name() === 'cli') {
    $bid = (int)($argv[1] ?? 0);
    if ($bid > 0) {
        // Let config.php switch token + DB for this child bot
        $_GET['bid'] = $bid;
    }
}

require_once __DIR__ . "/baseInfo.php";
require_once __DIR__ . "/config.php";

$bidLock = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;

$lockDir = __DIR__ . "/settings/locks";
if(!is_dir($lockDir)) @mkdir($lockDir, 0755, true);
$lockFile = $lockDir . "/broadcast_" . $bidLock . ".lock";
$fp = @fopen($lockFile, "c+");
if($fp === false){
    exit;
}
if(!@flock($fp, LOCK_EX | LOCK_NB)){
    fclose($fp);
    exit;
}

// Keep a heartbeat timestamp (useful for debugging)
@ftruncate($fp, 0);
@fwrite($fp, (string)time());
@fflush($fp);

$maxIterations = 300; // ~5 minutes max
for($i=0; $i<$maxIterations; $i++){
    $stmt = $connection->prepare("SELECT `id` FROM `send_list` WHERE `state` = 1 LIMIT 1");
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if($res->num_rows == 0){
        break;
    }

    // Process one batch (50 users) per iteration
    include __DIR__ . "/settings/messagedelta_worker.php";

    // Update heartbeat
    @ftruncate($fp, 0);
    @fwrite($fp, (string)time());
    @fflush($fp);

    usleep(600000); // 0.6s
}

@flock($fp, LOCK_UN);
@fclose($fp);
?>