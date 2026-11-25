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
$textOnly=$hash==='--text';if($textOnly)$hash='';
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
    $rows=$hash!==''?query($p,'SELECT id,hash_id,price,state,request_date,user_id,type FROM pays WHERE hash_id=? LIMIT 1',[$hash]):
        ($textOnly?query($p,"SELECT p.id,p.hash_id,p.price,p.state,p.request_date,p.user_id,p.type FROM pays p JOIN setting s ON BINARY s.type=BINARY CONCAT('RECEIPT_KIND_',SHA1(p.hash_id)) WHERE s.value='text' AND p.state='have_sent' ORDER BY p.id DESC LIMIT 5"):
        query($p,"SELECT id,hash_id,price,state,request_date,user_id,type FROM pays WHERE state='have_sent' ORDER BY id DESC LIMIT 3"));
    if(!$rows){output('INVOICES','NONE');exit;}
    $table=query($p,"SHOW TABLES LIKE 'delta_sms_invoice_bindings'");
    if(!$table){output('ERROR','SMS_BINDING_TABLE_MISSING');exit(3);}
    $events=deltaSmsMatchingEvents();
    output('HISTORY_LOOKBACK_SECONDS',deltaSmsReconciliationLookbackSeconds());
    output('STORED_EVENTS_IN_LOOKBACK',count($events));
    output('RECEIPT_GRACE_SECONDS',deltaSmsReceiptGraceSeconds());
    foreach($rows as $pay){
        $h=(string)$pay['hash_id'];$price=(int)$pay['price'];$receipt=(int)setting($p,'RECEIPT_AT_'.sha1($h));
        $display=(int)setting($p,'PAYMENT_CARD_SELECTED_'.sha1($h));
        output('INVOICE',$h);output('STATE',$pay['state']);output('PRICE_RIAL',$price*10);
        output('RECEIPT_KIND',setting($p,'RECEIPT_KIND_'.sha1($h))?:'photo_or_unknown');
        output('PAY_TYPE',$pay['type']);
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
        $settled=in_array($pay['state'],['paid','approved'],true);
        output('MATCH_MODE',$settled?'SETTLED_RECONCILIATION':'NEW_PAYMENT');
        output('ACTIVE_APPROVAL_DEADLINE_UTC',gmdate('c',(int)$b['expires_at']+900));
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
                'passes_time_source_checks'=>deltaSmsEventMatches($b,$e,$receipt,$epoch,time(),$settled)
            ]);
            if(!$used&&deltaSmsEventMatches($b,$e,$receipt,$epoch,time(),$settled))$hits[$e['event_id']]=$e;
        }
        output('SAME_AMOUNT_EVENTS',$sameAmount);output('USED_SAME_AMOUNT_EVENTS',$usedCount);output('ELIGIBLE_UNUSED_EVENTS',count($hits));
        if(count($hits)!==1){output('BLOCKER',count($hits)>1?'AMBIGUOUS_BANK_EVENTS':'NO_ELIGIBLE_BANK_EVENT');continue;}
        $event=reset($hits);$at=(int)$event['received_at'];
        $rows=[];foreach(deltaSmsCompetitionRowsSql() as $sql)$rows=array_merge($rows,query($p,$sql,[$h,$price,$at-3600,$at]));
        $history=[];
        foreach($rows as $r){
            $ch=(string)$r['hash_id'];
            if(isset($history[$ch]))continue;
            if(in_array($r['state']??'',['paid','approved'],true)&&!empty($r['event_id'])){
                $history[$ch]=['early_verified_event'=>(string)setting($p,'SMS_EARLY_RECONCILE_'.sha1($ch))];continue;
            }
            if(!in_array($r['state']??'',['declined','cancelled_by_user'],true)||(int)($r['no_payment_through']??0)<=0)continue;
            $latest=0;
            foreach(deltaSmsCompetitionHistoryQueries() as $sql)$latest=max($latest,(int)(query($p,$sql,[$ch])[0]['latest']??0));
            $history[$ch]=['receipt_at'=>(int)setting($p,'RECEIPT_AT_'.sha1($ch)),'latest_shown'=>$latest];
        }
        $competitors=deltaSmsCompetingHashes($rows,$events,$at,(string)$event['event_id'],$history,(int)$b['shown_at']);
        output('COMPETING_INVOICES',count($competitors));
        foreach($competitors as $ch){
            $cp=query($p,'SELECT id,hash_id,price,state,request_date FROM pays WHERE BINARY hash_id=BINARY ? LIMIT 1',[$ch])[0]??[];
            if(!$cp){
                $old=query($p,'SELECT nominal_toman,shown_at,expires_at FROM delta_sms_invoice_bindings WHERE BINARY hash_id=BINARY ? ORDER BY shown_at DESC LIMIT 1',[$ch])[0]??[];
                output('ORPHAN_BINDING',['hash'=>$ch,'price_toman'=>(int)($old['nominal_toman']??0),'shown_utc'=>isset($old['shown_at'])?gmdate('c',(int)$old['shown_at']):'unknown','expires_utc'=>isset($old['expires_at'])?gmdate('c',(int)$old['expires_at']):'unknown']);
            }
            $id=(int)($cp['id']??0);
            output('CONFLICT',['tracking_code'=>$id>0&&$id<90000000?10000000+(($id*32452843+27182818)%90000000):'unknown',
                'hash'=>$ch,'state'=>$cp['state']??'missing','price_toman'=>$cp['price']??0,'created_iran'=>isset($cp['request_date'])?gmdate('Y-m-d H:i:s',(int)$cp['request_date']+12600):'unknown']);
        }
        output('BLOCKER',$competitors?'AMBIGUOUS_INVOICE':($policy==='never'?'USER_POLICY_NEVER':'NO_MATCHING_BLOCKER_FOUND_CHECK_WORKER'));
    }
    $p->rollBack();
    output('SAFE_MODE','READ_ONLY');
}catch(Throwable $e){output('ERROR','READ_ONLY_DIAGNOSTIC_FAILED');exit(3);}
