<?php
// Mother-only SMS checkout binding. No customer-supplied receipt is proof of payment.
// Sender rules live on the paired Android phone. Its authenticated SMS
// events are already filtered by the exact configured sender; no second
// card/account mapping or server-side copy of those rules is required.
function deltaSmsSenderReservation(array $binding): bool {
    return ($binding['bank']??'')==='nominal' && ($binding['account_hint']??'')==='00000';
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
    $rial=$price*10;
    $st=$connection->prepare('INSERT INTO delta_sms_invoice_bindings (display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->bind_param('isissiiii',$id,$hash,$uid,$bank,$hint,$rial,$price,$now,$expires);
    $st->execute();$inserted=$st->affected_rows===1;$st->close();
    return $inserted?['amount_irr'=>$rial,'expires_at'=>$expires]:null;
}
function deltaSmsPresentationText(?array $binding): string {
    if(!$binding) return "\n\n⚠️ این فاکتور برای بررسی دستی است؛ مبلغ اصلی فاکتور را واریز کنید.";
    return "\n\n📲 مبلغ دقیق قابل پرداخت: <code>".number_format($binding['amount_irr'],0,'.','')."</code> ریال\nپس از واریز، رسید را ارسال کنید. در صورت وجود فاکتور یا واریزی هم‌مبلغ دیگر، مدیریت پرداخت را بررسی می‌کند.";
}
// Include every other card presentation, including pending, approved,
// declined and old-mode invoices. A manually paid invoice is still a possible
// owner of the bank deposit. Don't count repeated displays of the SAME invoice.
function deltaSmsHasCompetingInvoice(string $hash,int $price,int $eventAt): bool {
    global $connection;
    $from=$eventAt-3600;
    $st=$connection->prepare('SELECT d.id FROM payment_card_displays d JOIN pays p ON BINARY p.hash_id=BINARY d.hash_id WHERE BINARY d.hash_id<>BINARY ? AND p.price=? AND d.shown_at BETWEEN ? AND ? LIMIT 1');
    $st->bind_param('siii',$hash,$price,$from,$eventAt);$st->execute();
    $found=(bool)$st->get_result()->fetch_row();$st->close();
    if($found)return true;
    // Keep historical pricing evidence even if a pay row was repriced.
    $st=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE BINARY hash_id<>BINARY ? AND nominal_toman=? AND shown_at BETWEEN ? AND ? LIMIT 1');
    $st->bind_param('siii',$hash,$price,$from,$eventAt);$st->execute();
    $found=(bool)$st->get_result()->fetch_row();$st->close();
    return $found;
}
function deltaSmsEventMatches(array $binding,array $event,int $receiptAt,int $epoch,int $now): bool {
    $shown=(int)($binding['shown_at']??0);$expires=(int)($binding['expires_at']??0);
    $received=$event['received_at']??null;$ingested=$event['ingested_at']??null;
    return $epoch>0 && $shown >= $epoch && $expires>$shown
      && $receiptAt >= $shown && $receiptAt <= $expires
      && $now <= $expires+900
      && ($event['source']??'')==='sms'
      && preg_match('/^[a-f0-9]{64}$/D',(string)($event['event_id']??''))
      && (deltaSmsSenderReservation($binding)
          ? (in_array($event['bank']??'',['melli','pasargad','khavar'],true)
              && is_string($event['sender']??null) && trim($event['sender'])!=='')
          : (($event['bank']??'')===$binding['bank'] && ($event['account_hint']??'')===$binding['account_hint']))
      && is_int($event['amount_irr']??null) && $event['amount_irr']===(int)$binding['amount_irr']
      && is_int($received) && $received >= $shown && $received <= $expires
      && is_int($ingested) && $ingested >= $shown && $ingested <= $now && $ingested >= $received-300;
}
function deltaSmsApprovalEvents(): array {
    static $events=null;
    if($events!==null) return $events;
    $events=[];$conf='/etc/delta-sms-bridge.php';
    if(!is_readable($conf)) return $events;
    $cfg=require $conf;if(!is_array($cfg))return $events;
    $base=(string)($cfg['storage_dir']??'/var/lib/delta-sms-bridge/events');
    foreach(glob($base.'/[a-f0-9][a-f0-9]/*.json')?:[] as $path){
        if(is_link($path)||!is_file($path)||filesize($path)>4096||filemtime($path)<time()-7200)continue;
        $row=json_decode((string)file_get_contents($path),true);
        if(is_array($row)&&basename($path)===(string)($row['event_id']??'').'.json') $events[]=$row;
    }
    return $events;
}
function deltaSmsFindBinding(array $pay,int $receiptAt): ?array {
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsApprovalSchema())return null;
    $hash=(string)$pay['hash_id'];$id=(int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0');$uid=(int)$pay['user_id'];
    if($id<=0)return null;
    $st=$connection->prepare('SELECT b.*,d.card_number FROM delta_sms_invoice_bindings b JOIN payment_card_displays d ON d.id=b.display_id AND d.hash_id=b.hash_id AND d.user_id=b.user_id WHERE b.display_id=? AND b.hash_id=? AND b.user_id=? AND b.event_id IS NULL LIMIT 1');
    $st->bind_param('isi',$id,$hash,$uid);$st->execute();$b=$st->get_result()->fetch_assoc();$st->close();
    if(!$b||(int)$b['nominal_toman']!==(int)$pay['price'])return null;
    // Older account-bound reservations cannot silently acquire broader
    // sender-only eligibility after the upgrade.
    if(!deltaSmsSenderReservation($b)||(int)$b['amount_irr']!==(int)$pay['price']*10)return null;
    $hits=[];$epoch=deltaSmsApprovalEpoch();$now=time();
    foreach(deltaSmsApprovalEvents() as $event) if(deltaSmsEventMatches($b,$event,$receiptAt,$epoch,$now))$hits[$event['event_id']]=$event;
    if(count($hits)!==1)return null;
    $b['matched_event']=array_key_first($hits);
    $b['matched_event_at']=(int)$hits[$b['matched_event']]['received_at'];
    if(deltaSmsHasCompetingInvoice($hash,(int)$pay['price'],$b['matched_event_at']))return null;
    return $b;
}
// Atomic invoice+event claim. No bank event is released on a panel failure;
// failed delivery is a manual recovery, not a second financial approval.
function deltaSmsClaimInvoice(array $pay,?array $binding,string $mode): bool {
    global $connection,$isChildBot;
    $rowId=(int)$pay['id'];$hash=(string)$pay['hash_id'];$uid=(int)$pay['user_id'];$price=(int)$pay['price'];
    if(!empty($isChildBot))return false;
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
            if(deltaSmsApprovalEpoch()>(int)$binding['shown_at'])throw new RuntimeException('old activation epoch');
            $display=(int)$binding['display_id'];$event=(string)$binding['matched_event'];$now=time();
            if(!deltaSmsSenderReservation($binding)||(int)($binding['amount_irr']??0)!==$price*10)throw new RuntimeException('old pricing mode');
            $eventAt=(int)($binding['matched_event_at']??0);
            if($eventAt<=0||deltaSmsHasCompetingInvoice($hash,$price,$eventAt))throw new RuntimeException('ambiguous amount');
            if((int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0')!==$display)throw new RuntimeException('card changed');
            $st=$connection->prepare('UPDATE delta_sms_invoice_bindings SET event_id=?,claimed_at=? WHERE display_id=? AND hash_id=? AND user_id=? AND nominal_toman=? AND bank=\'nominal\' AND account_hint=\'00000\' AND amount_irr=nominal_toman*10 AND event_id IS NULL');
            $st->bind_param('siisii',$event,$now,$display,$hash,$uid,$price);
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
