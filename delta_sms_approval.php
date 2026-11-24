<?php
// Mother-only SMS checkout binding. No customer-supplied receipt is proof of payment.
function deltaSmsApprovalAccounts(): array {
    $path='/etc/delta-sms-approval.json';
    if(!is_file($path)||!is_readable($path)||fileowner($path)!==0||(fileperms($path)&0022)!==0) return [];
    $cfg=json_decode((string)file_get_contents($path),true);
    if(!is_array($cfg)||($cfg['dedicated_accounts_confirmed']??false)!==true) return [];
    $out=[];
    foreach(($cfg['cards']??[]) as $card=>$row){
        if(!preg_match('/^[0-9]{16}$/D',(string)$card)||!is_array($row)) continue;
        if(!in_array($row['bank']??'',['melli','pasargad','khavar'],true)||!preg_match('/^[0-9]{5}$/D',(string)($row['account_hint']??''))) continue;
        $out[(string)$card]=['bank'=>$row['bank'],'account_hint'=>$row['account_hint']];
    }
    return $out;
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
      UNIQUE KEY sms_amount (bank,account_hint,amount_irr),
      UNIQUE KEY sms_event (event_id), KEY sms_invoice (hash_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready;
}
function deltaSmsApprovalEpoch(): int {
    return (int)getSettingValue('SMS_APPROVAL_FROM_TS','0');
}
function deltaSmsReservePresentation(array $pay,array $card): ?array {
    global $connection,$botState,$isChildBot;
    if(!empty($isChildBot)||deltaReceiptApprovalMode($botState)!=='sms'||deltaSmsApprovalEpoch()<=0) return null;
    $accounts=deltaSmsApprovalAccounts();$target=$accounts[(string)$card['number']]??null;
    $price=(int)($pay['price']??0);$hash=(string)($pay['hash_id']??'');
    if(!$target||$price<=0||$price>99999999999||$hash===''||!deltaSmsApprovalSchema()) return null;
    $now=time();$expires=min($now+3600,(int)($pay['request_date']??0)+3600);
    if(function_exists('bfFindInvoice')){
        $bf=bfFindInvoice($hash);
        if($bf) $expires=min($expires,(int)$bf['reserved_until']);
    }
    if($expires<=$now || $now<deltaSmsApprovalEpoch()) return null;
    $id=(int)$card['id'];$uid=(int)$pay['user_id'];$bank=$target['bank'];$hint=$target['account_hint'];
    // Exact rial suffix is below one toman. Never reuse a reserved amount on the
    // same account, even after expiration, so late/duplicate deposits cannot
    // accidentally pay a later invoice. Exhausted prices go to manual review.
    $offsets=range(1,9);shuffle($offsets);
    foreach($offsets as $offset){
        $rial=$price*10+$offset;
        $st=$connection->prepare('INSERT IGNORE INTO delta_sms_invoice_bindings (display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->bind_param('isissiiii',$id,$hash,$uid,$bank,$hint,$rial,$price,$now,$expires);
        $st->execute();$inserted=$st->affected_rows===1;$st->close();
        if($inserted)return ['amount_irr'=>$rial,'expires_at'=>$expires];
    }
    return null;
}
function deltaSmsPresentationText(?array $binding): string {
    if(!$binding) return "\n\n⚠️ این فاکتور برای بررسی دستی است؛ مبلغ عادی را واریز کنید.";
    return "\n\n📲 مبلغ اختصاصی تأیید پیامکی: <code>".number_format($binding['amount_irr'],0,'.','')."</code> ریال\nلطفاً دقیقاً همین مبلغ را (با رقم آخر) کپی و واریز کنید. اختلاف کمتر از یک تومان است. اگر بانک مبلغ را گرد می‌کند، رسید برای بررسی دستی می‌ماند.";
}
function deltaSmsEventMatches(array $binding,array $event,int $receiptAt,int $epoch,int $now): bool {
    $shown=(int)($binding['shown_at']??0);$expires=(int)($binding['expires_at']??0);
    $received=$event['received_at']??null;$ingested=$event['ingested_at']??null;
    return $epoch>0 && $shown >= $epoch && $expires>$shown
      && $receiptAt >= $shown && $receiptAt <= $expires
      && $now <= $expires+900
      && ($event['source']??'')==='sms'
      && preg_match('/^[a-f0-9]{64}$/D',(string)($event['event_id']??''))
      && ($event['bank']??'')===$binding['bank'] && ($event['account_hint']??'')===$binding['account_hint']
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
    $account=deltaSmsApprovalAccounts()[$b['card_number']]??null;
    if(!$account||$account['bank']!==$b['bank']||$account['account_hint']!==$b['account_hint'])return null;
    $hits=[];$epoch=deltaSmsApprovalEpoch();$now=time();
    foreach(deltaSmsApprovalEvents() as $event) if(deltaSmsEventMatches($b,$event,$receiptAt,$epoch,$now))$hits[$event['event_id']]=$event;
    if(count($hits)!==1)return null;
    $b['matched_event']=array_key_first($hits);
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
            if(!$binding||deltaSmsApprovalEpoch()>(int)$binding['shown_at']||deltaAutoApprovePolicy($uid)==='never')throw new RuntimeException('binding no longer eligible');
            $display=(int)$binding['display_id'];$event=(string)$binding['matched_event'];$now=time();
            if((int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0')!==$display)throw new RuntimeException('card changed');
            $st=$connection->prepare('UPDATE delta_sms_invoice_bindings SET event_id=?,claimed_at=? WHERE display_id=? AND hash_id=? AND user_id=? AND nominal_toman=? AND event_id IS NULL');
            $st->bind_param('siisii',$event,$now,$display,$hash,$uid,$price);
            $st->execute();$ok=$st->affected_rows===1;$st->close();
            if(!$ok)throw new RuntimeException('event already used');
        }
        $st=$connection->prepare("UPDATE pays SET state='paid' WHERE id=? AND hash_id=? AND user_id=? AND price=? AND state='have_sent'");
        $st->bind_param('isii',$rowId,$hash,$uid,$price);$st->execute();$ok=$st->affected_rows===1;$st->close();
        if(!$ok)throw new RuntimeException('invoice already handled');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
