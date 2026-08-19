<?php
// CLI-only one-shot wakeup for special-offer start notifications.
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    exit;
}

$endpoint=(string)($argv[1] ?? '');
$startsAt=(int)($argv[2] ?? 0);
$offerId=(int)($argv[3] ?? 0);
if(!filter_var($endpoint,FILTER_VALIDATE_URL) || $startsAt<=0 || $offerId<=0) exit(1);

// One process per bot/offer is enough, even when many users subscribe.
$lockPath=sys_get_temp_dir().'/delta-special-offer-'.sha1($endpoint.'|'.$offerId).'.lock';
$lock=@fopen($lockPath,'c');
if(!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) exit(0);

while(($remaining=$startsAt-time())>0){
    sleep(min(30,$remaining));
}

for($attempt=0;$attempt<3;$attempt++){
    $ok=false;
    if(function_exists('curl_init')){
        $ch=curl_init($endpoint);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_HTTPHEADER=>['X-Delta-Special-Offer-Wakeup: 1'],
        ]);
        curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $ok=(curl_errno($ch)===0 && $status>=200 && $status<300);
        curl_close($ch);
    }else{
        $context=stream_context_create(['http'=>['timeout'=>30,'ignore_errors'=>true]]);
        $result=@file_get_contents($endpoint,false,$context);
        $ok=($result!==false);
    }
    if($ok) break;
    sleep(5);
}

@flock($lock,LOCK_UN);
@fclose($lock);

