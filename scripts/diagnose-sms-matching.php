<?php
declare(strict_types=1);
// Read-only diagnostic of an existing mother invoice. No actual worker run.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once '/opt/delta-sms-bridge/server/lib.php';
function output(string $key,$value):void{echo $key.'='.(is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE)).PHP_EOL;}
function query(PDO $p,string $sql,array $args=[]):array{
    $s=$p->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function setting(PDO $p,string $key):string{return (string)(query($p,'SELECT value FROM setting WHERE type=? LIMIT 1',[$key])[0]['value']??'');}
$hash=$argv[1]??'';
if($hash!==''&&!preg_match('/^[A-Za-z0-9_-]{5,128}$/D',$hash)){output('ERROR','INVALID_HASH');exit(2);}
try{
    $m=deltaSmsMotherDbInfo();
    if(!$m){output('ERROR','MOTHER_DB_UNAVAILABLE');exit(3);}
    $p=new PDO('mysql:host=localhost;dbname='.$m['db'].';charset=utf8mb4',$m['user'],$m['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>4]);
    $p->exec('SET SESSION TRANSACTION READ ONLY');
    $p->beginTransaction();
    $state=json_decode(setting($p,'BOT_STATES'),true);
    output('LEGACY_MODE',$state['cartToCartAutoAcceptState']??'off');
    output('SMS_MODE',$state['cartToCartSmsAcceptState']??'off');
    $epoch=(int)setting($p,'SMS_APPROVAL_FROM_TS');output('ACTIVATED_UTC',$epoch?gmdate('c',$epoch):'MISSING');
    $botFile='/var/www/html/deltabotvps/delta_sms_approval.php';
    if(!is_readable($botFile)){output('ERROR','BOT_SMS_CODE_MISSING');exit(3);}
    require_once $botFile;
    $rows=$hash!==''?query($p,'SELECT id,hash_id,price,state,request_date,user_id FROM pays WHERE hash_id=? LIMIT 1',[$hash]):
        query($p,"SELECT id,hash_id,price,state,request_date,user_id FROM pays WHERE state='have_sent' ORDER BY id DESC LIMIT 3");
    if(!$rows){output('INVOICES','NONE');exit;}
    $table=query($p,"SHOW TABLES LIKE 'delta_sms_invoice_bindings'");
    if(!$table){output('ERROR','SMS_BINDING_TABLE_MISSING');exit(3);}
    $events=deltaSmsApprovalEvents();
    output('RECENT_STORED_EVENTS',count($events));
    output('RECEIPT_GRACE_SECONDS',deltaSmsReceiptGraceSeconds());
    foreach($rows as $pay){
        $h=(string)$pay['hash_id'];$price=(int)$pay['price'];$receipt=(int)setting($p,'RECEIPT_AT_'.sha1($h));
        $display=(int)setting($p,'PAYMENT_CARD_SELECTED_'.sha1($h));
        output('INVOICE',$h);output('STATE',$pay['state']);output('PRICE_RIAL',$price*10);
        output('RECEIPT_UTC',$receipt?gmdate('c',$receipt):'MISSING');
        output('SELECTED_PRESENTATION',$display);
        output('DELIVERY_FAILURE_FLAG',setting($p,'AUTOAPPROVE_FAILED_'.(int)$pay['id'])==='1'?'YES':'NO');
        $policy=setting($p,'USER_AUTOAPPROVE_POLICY_'.(int)$pay['user_id']);
        if($policy==='')$policy=setting($p,'USER_NO_AUTOAPPROVE_'.(int)$pay['user_id'])==='1'?'never':'normal';
        output('USER_POLICY',$policy);
        $b=query($p,'SELECT * FROM delta_sms_invoice_bindings WHERE hash_id=? AND display_id=? LIMIT 1',[$h,$display])[0]??null;
        if(!$b){output('BLOCKER','NO_SELECTED_BINDING');continue;}
        output('BINDING_MODE',$b['bank']);output('EXPECTED_RIAL',$b['amount_irr']);
        output('DISPLAY_UTC',gmdate('c',(int)$b['shown_at']));output('EXPIRES_UTC',gmdate('c',(int)$b['expires_at']));
        if(!empty($b['event_id'])){output('BLOCKER','EVENT_ALREADY_CLAIMED');continue;}
        if(!deltaSmsSenderReservation($b)||(int)$b['amount_irr']!==$price*10){output('BLOCKER','OLD_OR_WRONG_AMOUNT_BINDING');continue;}
        $hits=[];$sameAmount=0;$usedCount=0;
        foreach($events as $e){
            if((int)($e['amount_irr']??0)!==(int)$b['amount_irr'])continue;
            $sameAmount++;
            $used=(bool)query($p,'SELECT display_id FROM delta_sms_invoice_bindings WHERE event_id=? LIMIT 1',[$e['event_id']]);
            if($used)$usedCount++;
            output('SAME_AMOUNT_EVENT',[
                'bank'=>$e['bank']??'','source'=>$e['source']??'',
                'received_utc'=>gmdate('c',(int)($e['received_at']??0)),
                'ingested_utc'=>gmdate('c',(int)($e['ingested_at']??0)),
                'already_reserved_or_used'=>$used,
                'passes_time_source_checks'=>deltaSmsEventMatches($b,$e,$receipt,$epoch,time())
            ]);
            if(!$used&&deltaSmsEventMatches($b,$e,$receipt,$epoch,time()))$hits[$e['event_id']]=$e;
        }
        output('SAME_AMOUNT_EVENTS',$sameAmount);output('USED_SAME_AMOUNT_EVENTS',$usedCount);output('ELIGIBLE_UNUSED_EVENTS',count($hits));
        if(count($hits)!==1){output('BLOCKER',count($hits)>1?'AMBIGUOUS_BANK_EVENTS':'NO_ELIGIBLE_BANK_EVENT');continue;}
        $event=reset($hits);$at=(int)$event['received_at'];
        $rows=[];foreach(deltaSmsCompetitionRowsSql() as $sql)$rows=array_merge($rows,query($p,$sql,[$h,$price,$at-3600,$at]));
        $competitors=deltaSmsCompetingHashes($rows,$events,$at,(string)$event['event_id']);
        output('COMPETING_INVOICES',count($competitors));
        output('BLOCKER',$competitors?'AMBIGUOUS_INVOICE':($policy==='never'?'USER_POLICY_NEVER':'NO_MATCHING_BLOCKER_FOUND_CHECK_WORKER'));
    }
    $p->rollBack();
    output('SAFE_MODE','READ_ONLY');
}catch(Throwable $e){output('ERROR','READ_ONLY_DIAGNOSTIC_FAILED');exit(3);}
