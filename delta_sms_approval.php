<?php
// Mother-only SMS checkout binding. No customer-supplied receipt is proof of payment.
// Sender rules live on the paired Android phone. Its authenticated SMS
// events are already filtered by the exact configured sender; no second
// card/account mapping or server-side copy of those rules is required.
function deltaSmsSenderReservation(array $binding): bool {
    return ($binding['bank']??'')==='nominal' && ($binding['account_hint']??'')==='00000';
}
// New account-scoped bindings use an explicit prefix. Old bank-specific
// reservations from the historical suffix scheme are not reactivated.
function deltaSmsAccountScope(array $b): ?array {
    $bank=(string)($b['bank']??'');$hint=(string)($b['account_hint']??'');
    if(!in_array($bank,['acct_melli','acct_pasargad','acct_khavar'],true)||!preg_match('/^[0-9]{5}$/D',$hint))return null;
    return ['bank'=>substr($bank,5),'account_hint'=>$hint];
}
function deltaSmsExactReservation(array $b): bool {
    return deltaSmsSenderReservation($b)||deltaSmsAccountScope($b)!==null;
}
function deltaSmsTrustedAccountMatches(array $b,array $e): bool {
    $scope=deltaSmsAccountScope($b);
    return ($e['source']??'')==='sms'&&is_string($e['sender']??null)&&trim($e['sender'])!==''&&
      in_array($e['bank']??'',['melli','pasargad','khavar'],true)&&
      ($scope===null||($e['bank']===$scope['bank']&&($e['account_hint']??'')===$scope['account_hint']));
}
function deltaSmsApprovalSchema(): bool {
    global $connection,$isChildBot;
    if(!empty($isChildBot)) return false;
    static $ready=false;
    if($ready) return true;
    $ready=(bool)$connection->query("CREATE TABLE IF NOT EXISTS delta_sms_invoice_bindings (
      display_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      hash_id VARCHAR(128) NOT NULL, user_id BIGINT NOT NULL,
      bank VARCHAR(16) NOT NULL, account_hint CHAR(5) NOT NULL,
      amount_irr BIGINT UNSIGNED NOT NULL, nominal_toman BIGINT UNSIGNED NOT NULL,
      shown_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
      event_id CHAR(64) DEFAULT NULL, claimed_at BIGINT UNSIGNED DEFAULT NULL,
      KEY sms_amount (amount_irr,shown_at,expires_at),
      UNIQUE KEY sms_event (event_id), KEY sms_invoice (hash_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!$ready)return false;
    // Existing installs have a unique amount index from the suffix version.
    // Keep every old reservation and the unique event index intact.
    $ready=false;
    $lockResult=$connection->query("SELECT GET_LOCK(CONCAT('delta_sms_schema_',LEFT(SHA1(DATABASE()),32)),5) AS acquired");
    if(!$lockResult || (int)($lockResult->fetch_assoc()['acquired']??0)!==1)return false;
    try{
        $index=$connection->query("SHOW INDEX FROM delta_sms_invoice_bindings WHERE Key_name='sms_amount'");
        $row=$index?$index->fetch_assoc():null;
        if(!$row)return false;
        if((int)$row['Non_unique']===0){
            if(!$connection->query("ALTER TABLE delta_sms_invoice_bindings DROP INDEX sms_amount, ADD INDEX sms_amount (amount_irr,shown_at,expires_at)"))return false;
        }
        $ready=true;
        return true;
    }finally{
        $connection->query("SELECT RELEASE_LOCK(CONCAT('delta_sms_schema_',LEFT(SHA1(DATABASE()),32)))");
    }
}
function deltaSmsApprovalEpoch(): int {
    return (int)getSettingValue('SMS_APPROVAL_FROM_TS','0');
}
function deltaSmsReservePresentation(array $pay,array $card): ?array {
    global $connection,$botState,$isChildBot;
    if(!empty($isChildBot)||deltaReceiptApprovalMode($botState)!=='sms'||deltaSmsApprovalEpoch()<=0) return null;
    $price=(int)($pay['price']??0);$hash=(string)($pay['hash_id']??'');
    if($price<=0||$price>99999999999||$hash===''||!deltaSmsApprovalSchema()) return null;
    $now=time();$expires=min($now+3600,(int)($pay['request_date']??0)+3600);
    if(function_exists('bfFindInvoice')){
        $bf=bfFindInvoice($hash);
        if($bf) $expires=min($expires,(int)$bf['reserved_until']);
    }
    if($expires<=$now || $now<deltaSmsApprovalEpoch()) return null;
    $id=(int)$card['id'];$uid=(int)$pay['user_id'];$bank='nominal';$hint='00000';
    $scope=deltaSmsAccountScope(['bank'=>'acct_'.($card['sms_bank']??''),'account_hint'=>$card['sms_hint']??'']);
    if($scope!==null){$bank='acct_'.$scope['bank'];$hint=$scope['account_hint'];}
    $rial=$price*10;
    $st=$connection->prepare('INSERT INTO delta_sms_invoice_bindings (display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->bind_param('isissiiii',$id,$hash,$uid,$bank,$hint,$rial,$price,$now,$expires);
    $st->execute();$inserted=$st->affected_rows===1;$st->close();
    return $inserted?['amount_irr'=>$rial,'expires_at'=>$expires]:null;
}
function deltaSmsPresentationText(?array $binding): string {
    if(!$binding) return "\n\n⚠️ این فاکتور برای بررسی دستی است؛ مبلغ اصلی فاکتور را واریز کنید.";
    return "\n\n📲 مبلغ دقیق قابل پرداخت: <code>".number_format($binding['amount_irr'],0,'.','')."</code> ریال\nفقط در زمان اعتبار فاکتور واریز کنید؛ پس از لغو یا ارسال رسید دوباره واریز نکنید. واریزی دیرهنگام را به پشتیبانی اطلاع دهید. موارد هم‌مبلغ مبهم نیاز به بررسی دارند.";
}
// Never exclude a possible owner just because it is pending, archived,
// manually approved, declined, or has an earlier customer receipt.
// A prior invoice stops competing only if it was actually linked to a
// different, earlier trusted SMS, and its payment state is settled.
function deltaSmsCompetitionRowsSql(): array {
    $fields="p.state,c.event_id,c.nominal_toman,c.amount_irr,c.shown_at,c.expires_at,c.bank,c.account_hint,COALESCE(n.value,0) AS no_payment_through";
    return [
      "SELECT DISTINCT BINARY d.hash_id AS hash_id,$fields,sb.bank AS scope_bank,sb.account_hint AS scope_hint FROM payment_card_displays d
        JOIN pays p ON BINARY p.hash_id=BINARY d.hash_id
        LEFT JOIN delta_sms_invoice_bindings sb ON sb.display_id=d.id AND BINARY sb.hash_id=BINARY d.hash_id
        LEFT JOIN delta_sms_invoice_bindings c ON BINARY c.hash_id=BINARY d.hash_id AND c.event_id IS NOT NULL
        LEFT JOIN setting n ON BINARY n.type=BINARY CONCAT('SMS_NO_PAYMENT_',SHA1(d.hash_id))
        WHERE BINARY d.hash_id<>BINARY ? AND p.price=? AND d.shown_at BETWEEN ? AND ?",
      "SELECT DISTINCT BINARY b.hash_id AS hash_id,$fields,b.bank AS scope_bank,b.account_hint AS scope_hint FROM delta_sms_invoice_bindings b
        LEFT JOIN pays p ON BINARY p.hash_id=BINARY b.hash_id
        LEFT JOIN delta_sms_invoice_bindings c ON BINARY c.hash_id=BINARY b.hash_id AND c.event_id IS NOT NULL
        LEFT JOIN setting n ON BINARY n.type=BINARY CONCAT('SMS_NO_PAYMENT_',SHA1(b.hash_id))
        WHERE BINARY b.hash_id<>BINARY ? AND b.nominal_toman=? AND b.shown_at BETWEEN ? AND ?"
    ];
}
// Each hash gets a snapshot of its receipt and latest card presentation.
// A previously verified declined receipt can be retired for NEW checkouts
// only after its entire receipt window was reviewed. Late payments remain
// ambiguous if they relate to an earlier or changed checkout.
function deltaSmsVerifiedClosedReceipt(array $row,array $history,int $candidateShown,int $eventAt): bool {
    $marker=(int)($row['no_payment_through']??0);
    if(!in_array($row['state']??'',['declined','cancelled_by_user'],true)||!empty($row['event_id'])||$marker<=0)return false;
    if($marker>=$eventAt)return true;
    $receipt=(int)($history['receipt_at']??0);$latest=(int)($history['latest_shown']??0);
    return $receipt>0 && $latest>0 && $latest<=$receipt
      && $receipt+deltaSmsReceiptGraceSeconds()<=$marker
      && $candidateShown>$marker && $eventAt>=$candidateShown;
}
function deltaSmsCompetingHashes(array $rows,array $events,int $eventAt,string $eventId,array $history=[],int $candidateShown=0,?array $candidateBinding=null): array {
    $byHash=[];$byEvent=[];$scope=deltaSmsAccountScope($candidateBinding??[]);
    foreach($events as $e){
        $id=(string)($e['event_id']??'');
        if(preg_match('/^[a-f0-9]{64}$/D',$id))$byEvent[$id]=$e;
    }
    foreach($rows as $row){
        $other=deltaSmsAccountScope(['bank'=>$row['scope_bank']??'','account_hint'=>$row['scope_hint']??'']);
        // Only proven different destination accounts can be excluded.
        // Unmapped history and orphan records remain possible competitors.
        if($scope!==null&&$other!==null&&$scope!==$other)continue;
        unset($row['scope_bank'],$row['scope_hint']);
        $byHash[(string)$row['hash_id']][serialize($row)]=$row;
    }
    $out=[];
    foreach($byHash as $hash=>$group){
        $settled=false;
        // Multiple claims or missing evidence are not automatically resolved.
        if(count($group)===1&&$eventId!==''){
            $r=reset($group);$id=(string)($r['event_id']??'');$e=$byEvent[$id]??null;
            $at=$e['received_at']??null;$ing=$e['ingested_at']??null;
            $settled=in_array($r['state']??'',['paid','approved'],true)
              && $id!=='' && $id!==$eventId && is_array($e)
              && deltaSmsExactReservation($r)&&deltaSmsTrustedAccountMatches($r,$e??[])
              && (int)$r['amount_irr']===(int)$r['nominal_toman']*10
              && ($e['source']??'')==='sms'
              && in_array($e['bank']??'',['melli','pasargad','khavar'],true)
              && is_string($e['sender']??null)&&trim($e['sender'])!==''
              && is_int($e['amount_irr']??null)&&$e['amount_irr']===(int)$r['amount_irr']
              && is_int($at)&&($at>=(int)$r['shown_at'] ||
                  (($history[$hash]['early_verified_event']??'')===$id && $at>=(int)$r['shown_at']-300))
              && $at<=(int)$r['expires_at']&&$at<$eventAt
              && is_int($ing)&&$ing>=(int)$r['shown_at']&&$ing>=$at-300;
        }
        if(count($group)===1){
            $r=reset($group);
            if(deltaSmsVerifiedClosedReceipt($r,$history[$hash]??[],$candidateShown,$eventAt))$settled=true;
        }
        if(!$settled)$out[]=$hash;
    }
    return $out;
}
function deltaSmsCompetitionHistoryQueries(): array {
    return [
        'SELECT COALESCE(MAX(shown_at),0) AS latest FROM payment_card_displays WHERE BINARY hash_id=BINARY ?',
        'SELECT COALESCE(MAX(shown_at),0) AS latest FROM delta_sms_invoice_bindings WHERE BINARY hash_id=BINARY ?'
    ];
}
function deltaSmsCompetitionHistory(array $rows): array {
    global $connection;
    $history=[];
    foreach($rows as $r){
        $h=(string)$r['hash_id'];
        if(isset($history[$h]))continue;
        if(in_array($r['state']??'',['paid','approved'],true)&&!empty($r['event_id'])){
            $history[$h]=['early_verified_event'=>(string)getSettingValue('SMS_EARLY_RECONCILE_'.sha1($h),'')];continue;
        }
        if(!in_array($r['state']??'',['declined','cancelled_by_user'],true)||(int)($r['no_payment_through']??0)<=0)continue;
        $latest=0;
        foreach(deltaSmsCompetitionHistoryQueries() as $sql){
            $s=$connection->prepare($sql);$s->bind_param('s',$h);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();$latest=max($latest,(int)($row['latest']??0));
        }
        $history[$h]=['receipt_at'=>deltaReceiptSubmittedAt($h,0),'latest_shown'=>$latest];
    }
    return $history;
}
function deltaSmsHasCompetingInvoice(string $hash,int $price,int $eventAt,string $eventId='',?array $events=null,int $candidateShown=0,?array $candidateBinding=null): bool {
    global $connection;
    $from=$eventAt-3600;
    $rows=[];
    foreach(deltaSmsCompetitionRowsSql() as $sql){
        $st=$connection->prepare($sql);
        $st->bind_param('siii',$hash,$price,$from,$eventAt);
        $st->execute();$rows=array_merge($rows,$st->get_result()->fetch_all(MYSQLI_ASSOC));$st->close();
    }
    return (bool)deltaSmsCompetingHashes($rows,$events??[],$eventAt,$eventId,deltaSmsCompetitionHistory($rows),$candidateShown,$candidateBinding);
}
function deltaSmsEventAlreadyUsed(string $id): bool {
    global $connection;
    $s=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE event_id=? LIMIT 1');
    $s->bind_param('s',$id);$s->execute();$used=(bool)$s->get_result()->fetch_row();$s->close();
    return $used;
}
// Small allowance for SMS delivery delay; later events cannot match a
// previously submitted receipt indefinitely. Receipt time alone is NOT proof.
function deltaSmsReceiptGraceSeconds(): int {return 120;}
function deltaSmsEventMatches(array $binding,array $event,int $receiptAt,int $epoch,int $now,bool $settledReconciliation=false): bool {
    $shown=(int)($binding['shown_at']??0);$expires=(int)($binding['expires_at']??0);
    $received=$event['received_at']??null;$ingested=$event['ingested_at']??null;
    return $epoch>0 && $shown >= $epoch && $expires>$shown
      && $receiptAt >= $shown && $receiptAt <= $expires
      && ($settledReconciliation || $now <= $expires+900)
      && ($event['source']??'')==='sms'
      && preg_match('/^[a-f0-9]{64}$/D',(string)($event['event_id']??''))
      && (deltaSmsExactReservation($binding)
          ? deltaSmsTrustedAccountMatches($binding,$event)
          : (($event['bank']??'')===$binding['bank'] && ($event['account_hint']??'')===$binding['account_hint']))
      && is_int($event['amount_irr']??null) && $event['amount_irr']===(int)$binding['amount_irr']
      && is_int($received) && $received >= $shown && $received <= min($expires,$receiptAt+deltaSmsReceiptGraceSeconds())
      && is_int($ingested) && $ingested >= $shown && $ingested <= $now && $ingested >= $received-300;
}
function deltaSmsReconciliationLookbackSeconds(): int {return 86400;}
function deltaSmsApprovalEvents(int $lookback=7200): array {
    static $cache=[];
    $lookback=max(1,min(deltaSmsReconciliationLookbackSeconds(),$lookback));
    if(isset($cache[$lookback]))return $cache[$lookback];
    $events=[];$conf='/etc/delta-sms-bridge.php';
    if(!is_readable($conf)) return $events;
    $cfg=require $conf;if(!is_array($cfg))return $events;
    $base=(string)($cfg['storage_dir']??'/var/lib/delta-sms-bridge/events');
    foreach(glob($base.'/[a-f0-9][a-f0-9]/*.json')?:[] as $path){
        if(is_link($path)||!is_file($path)||filesize($path)>4096||filemtime($path)<time()-$lookback)continue;
        $row=json_decode((string)file_get_contents($path),true);
        if(is_array($row)&&basename($path)===(string)($row['event_id']??'').'.json') $events[]=$row;
    }
    return $cache[$lookback]=$events;
}
// Use historical stored evidence for ownership/reconciliation only; the
// live event matcher still enforces the original invoice and receipt window.
function deltaSmsMatchingEvents(): array {return deltaSmsApprovalEvents(deltaSmsReconciliationLookbackSeconds());}
function deltaSmsFindBinding(array $pay,int $receiptAt,?array $trustedEvents=null,bool $settledReconciliation=false): ?array {
    if($settledReconciliation&&!in_array($pay['state']??'',['paid','approved'],true))return null;
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsApprovalSchema())return null;
    $hash=(string)$pay['hash_id'];$id=(int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0');$uid=(int)$pay['user_id'];
    if($id<=0)return null;
    $st=$connection->prepare('SELECT b.*,d.card_number FROM delta_sms_invoice_bindings b JOIN payment_card_displays d ON d.id=b.display_id AND BINARY d.hash_id=BINARY b.hash_id AND d.user_id=b.user_id WHERE b.display_id=? AND BINARY b.hash_id=BINARY ? AND b.user_id=? AND b.event_id IS NULL LIMIT 1');
    $st->bind_param('isi',$id,$hash,$uid);$st->execute();$b=$st->get_result()->fetch_assoc();$st->close();
    if(!$b||(int)$b['nominal_toman']!==(int)$pay['price'])return null;
    // Older account-bound reservations cannot silently acquire broader
    // sender-only eligibility after the upgrade.
    if(!deltaSmsExactReservation($b)||(int)$b['amount_irr']!==(int)$pay['price']*10)return null;
    $hits=[];$epoch=deltaSmsApprovalEpoch();$now=time();
    $events=$trustedEvents??deltaSmsMatchingEvents();
    foreach($events as $event) if(deltaSmsEventMatches($b,$event,$receiptAt,$epoch,$now,$settledReconciliation)&&!deltaSmsEventAlreadyUsed($event['event_id']))$hits[$event['event_id']]=$event;
    if(count($hits)!==1)return null;
    $b['matched_event']=array_key_first($hits);
    $b['matched_event_at']=(int)$hits[$b['matched_event']]['received_at'];
    if(deltaSmsHasCompetingInvoice($hash,(int)$pay['price'],$b['matched_event_at'],$b['matched_event'],$events,(int)$b['shown_at'],$b))return null;
    return $b;
}
// Atomic invoice+event claim. No bank event is released on a panel failure;
// failed delivery is a manual recovery, not a second financial approval.
function deltaSmsClaimInvoice(array $pay,?array $binding,string $mode,?array $trustedEvents=null): bool {
    global $connection,$isChildBot;
    $rowId=(int)$pay['id'];$hash=(string)$pay['hash_id'];$uid=(int)$pay['user_id'];$price=(int)$pay['price'];
    if(!empty($isChildBot))return false;
    // Schema checks can run DDL; keep them outside the payment transaction.
    if($mode==='sms'&&!deltaSmsApprovalSchema())return false;
    $connection->begin_transaction();
    try{
        $r=$connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $state=$r?json_decode((string)($r->fetch_assoc()['value']??''),true):null;
        if(!is_array($state)||deltaReceiptApprovalMode($state)!==$mode)throw new RuntimeException('mode changed');
        if($mode==='sms'){
            if(!$binding||deltaAutoApprovePolicy($uid)==='never')throw new RuntimeException('binding no longer eligible');
            if(!empty($binding['admin_review'])){
                if(!deltaSmsReviewClaim($pay,$binding))throw new RuntimeException('review claim unavailable');
            }else{
            // Revalidate the same selected binding and exact bank event inside
            // the shared approval lock. No stale pre-lock match may be claimed.
            $receipt=deltaReceiptSubmittedAt($hash,0);
            $fresh=deltaSmsFindBinding($pay,$receipt,$trustedEvents);
            if(!$fresh||(string)$fresh['matched_event']!==(string)($binding['matched_event']??'')
                ||(int)$fresh['display_id']!==(int)($binding['display_id']??0))throw new RuntimeException('match no longer unique');
            $display=(int)$fresh['display_id'];$event=(string)$fresh['matched_event'];$now=time();
            $bank=(string)$fresh['bank'];$hint=(string)$fresh['account_hint'];
            $st=$connection->prepare('UPDATE delta_sms_invoice_bindings SET event_id=?,claimed_at=? WHERE display_id=? AND hash_id=? AND user_id=? AND nominal_toman=? AND bank=? AND account_hint=? AND amount_irr=nominal_toman*10 AND event_id IS NULL');
            $st->bind_param('siisiiss',$event,$now,$display,$hash,$uid,$price,$bank,$hint);
            $st->execute();$ok=$st->affected_rows===1;$st->close();
            if(!$ok)throw new RuntimeException('event already used');
            }
        }
        $st=$connection->prepare("UPDATE pays SET state='paid' WHERE id=? AND hash_id=? AND user_id=? AND price=? AND state='have_sent'");
        $st->bind_param('isii',$rowId,$hash,$uid,$price);$st->execute();$ok=$st->affected_rows===1;$st->close();
        if(!$ok)throw new RuntimeException('invoice already handled');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
