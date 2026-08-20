<?php
// CLI-only one-shot wakeup for special-offer start notifications.
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$startsAt=(int)($argv[1] ?? 0);
$offerId=(int)($argv[2] ?? 0);
$botInstanceId=(int)($argv[3] ?? 0);
if($startsAt<=0 || $offerId<=0) exit(1);

// One process per bot/offer is enough, even when many users subscribe.
$lockPath=sys_get_temp_dir().'/delta-special-offer-'.sha1($botInstanceId.'|'.$offerId).'.lock';
$lock=@fopen($lockPath,'c');
if(!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) exit(0);

while(($remaining=$startsAt-time())>0){
    sleep(min(30,$remaining));
}

// Load the bot directly instead of calling its public URL. This avoids DNS,
// SSL, firewall and loopback restrictions which caused missed notifications.
if($botInstanceId>0) $_GET['bid']=$botInstanceId;
require_once __DIR__.'/baseInfo.php';
require_once __DIR__.'/config.php';
specialOfferDispatchStartNotifications(500);

@flock($lock,LOCK_UN);
@fclose($lock);
