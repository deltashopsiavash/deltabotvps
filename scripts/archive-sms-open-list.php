<?php
// Root-only CLI: archive the current open-list view, never change payments.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
if(function_exists('posix_geteuid')&&posix_geteuid()!==0){fwrite(STDERR,"Run with sudo.\n");exit(1);}
if(($argv[1]??'')!=='--confirm'){fwrite(STDERR,"Usage: sudo php scripts/archive-sms-open-list.php --confirm\n");exit(2);}
$bridge='/opt/delta-sms-bridge/server/lib.php';
if(!is_readable($bridge)){fwrite(STDERR,"Mother database locator unavailable.\n");exit(3);}
require_once $bridge;
require_once dirname(__DIR__).'/delta_sms_approval.php';
require_once dirname(__DIR__).'/delta_sms_review.php';
function getSettingValue($key,$default=null){
    global $connection;
    $s=$connection->prepare('SELECT value FROM setting WHERE type=? LIMIT 1');$s->bind_param('s',$key);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r['value']??$default;
}
function upsertSettingValue($key,$value){
    global $connection;
    if(getSettingValue($key,null)===null){
        $s=$connection->prepare('INSERT INTO setting(type,value) VALUES(?,?)');$s->bind_param('ss',$key,$value);
    }else{
        $s=$connection->prepare('UPDATE setting SET value=? WHERE type=?');$s->bind_param('ss',$value,$key);
    }
    $ok=$s->execute();$s->close();return $ok;
}
try{
    $m=deltaSmsMotherDbInfo();
    if(!$m)throw new RuntimeException('mother unavailable');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $connection=new mysqli('localhost',$m['user'],$m['password'],$m['db']);
    $connection->set_charset('utf8mb4');$isChildBot=false;
    // Actor 0 is the authenticated local CLI operator, not a Telegram user.
    $r=deltaSmsReviewClearList(0);
    if($r===null)throw new RuntimeException('archive failed');
    echo 'ARCHIVED_OPEN_INVOICES='.$r['count'].PHP_EOL;
    echo 'NEW_LIST_AFTER_ID='.$r['cutoff'].PHP_EOL;
    echo 'PAYMENTS_DELETED=0'.PHP_EOL;
    echo 'PAYMENTS_REJECTED=0'.PHP_EOL;
    echo 'SMS_EVENTS_DELETED=0'.PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,"Archive not completed; check the mother bot and database.\n");exit(3);}
