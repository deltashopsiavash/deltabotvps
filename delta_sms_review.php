<?php
// Mother owner-only reconciliation. The admin explicitly identifies the
// deposit owner; a customer-supplied tracking code is never bank evidence.
function deltaSmsReviewSchema(): bool {
    global $connection,$isChildBot;
    if(!empty($isChildBot))return false;
    static $ready=false;
    if($ready)return true;
    if(!deltaSmsApprovalSchema())return false;
    $ready=(bool)$connection->query("CREATE TABLE IF NOT EXISTS delta_sms_admin_audit (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      action VARCHAR(16) NOT NULL, actor_id BIGINT NOT NULL,
      invoice_id BIGINT UNSIGNED NOT NULL DEFAULT 0, hash_id VARCHAR(128) NOT NULL DEFAULT '',
      display_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      event_id CHAR(64) DEFAULT NULL, amount_irr BIGINT UNSIGNED NOT NULL DEFAULT 0,
      event_at BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at BIGINT UNSIGNED NOT NULL,
      status VARCHAR(16) NOT NULL, detail VARCHAR(255) NOT NULL DEFAULT '',
      UNIQUE KEY review_event (event_id), KEY review_invoice (hash_id,action,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $ready;
}
function deltaSmsReviewPay(int $id): ?array {
    global $connection;
    $s=$connection->prepare('SELECT * FROM pays WHERE id=? LIMIT 1');
    $s->bind_param('i',$id);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
    return $p?:null;
}
function deltaSmsReviewBinding(array $pay): ?array {
    global $connection;
    $hash=(string)$pay['hash_id'];$uid=(int)$pay['user_id'];
    $id=(int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0');
    if($id<=0)return null;
    $s=$connection->prepare('SELECT b.* FROM delta_sms_invoice_bindings b JOIN payment_card_displays d ON d.id=b.display_id AND BINARY d.hash_id=BINARY b.hash_id AND d.user_id=b.user_id WHERE b.display_id=? AND BINARY b.hash_id=BINARY ? AND b.user_id=? LIMIT 1');
    $s->bind_param('isi',$id,$hash,$uid);$s->execute();$b=$s->get_result()->fetch_assoc();$s->close();return $b?:null;
}
function deltaSmsReviewEventEligible(array $pay,array $b,array $e,int $receiptAt,int $now): bool {
    $at=$e['received_at']??null;$ingested=$e['ingested_at']??null;
    return ($pay['state']??'')==='have_sent' && $receiptAt>0
      && empty($b['event_id']) && (int)$b['nominal_toman']===(int)$pay['price']
      && ($e['source']??'')==='sms' && in_array($e['bank']??'',['melli','pasargad','khavar'],true)
      && trim((string)($e['sender']??''))!=='' && preg_match('/^[a-f0-9]{64}$/D',(string)($e['event_id']??''))
      && is_int($e['amount_irr']??null) && $e['amount_irr']===(int)$pay['price']*10
      && is_int($at) && $at>=(int)$b['shown_at'] && $at<=(int)$b['expires_at']
      && $receiptAt>=(int)$b['shown_at'] && $receiptAt<=(int)$b['expires_at']
      && is_int($ingested) && $ingested>=(int)$b['shown_at'] && $ingested<=$now && $ingested>=$at-300;
}
function deltaSmsReviewEventUsed(string $id): bool {
    global $connection;
    $s=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE event_id=? LIMIT 1');
    $s->bind_param('s',$id);$s->execute();$found=(bool)$s->get_result()->fetch_row();$s->close();return $found;
}
function deltaSmsReviewCandidates(array $pay,array $b,?array $trustedEvents=null): array {
    $receipt=deltaReceiptSubmittedAt($pay['hash_id'],0);$now=time();$out=[];
    foreach($trustedEvents??deltaSmsApprovalEvents() as $e){
        if(deltaSmsReviewEventEligible($pay,$b,$e,$receipt,$now)&&!deltaSmsReviewEventUsed($e['event_id']))$out[$e['event_id']]=$e;
    }
    uasort($out,static function($a,$b){return $b['received_at']<=>$a['received_at'];});
    return $out;
}
function deltaSmsReviewAudit(string $action,int $actor,array $pay,array $b,?array $event,string $status,string $detail): bool {
    global $connection;
    $invoice=(int)($pay['id']??0);$hash=(string)($pay['hash_id']??'');$display=(int)($b['display_id']??0);
    $eid=$event['event_id']??null;$amount=(int)($event['amount_irr']??((int)($pay['price']??0)*10));
    $at=(int)($event['received_at']??0);$now=time();
    $s=$connection->prepare('INSERT INTO delta_sms_admin_audit (action,actor_id,invoice_id,hash_id,display_id,event_id,amount_irr,event_at,created_at,status,detail) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $s->bind_param('siisisiiiss',$action,$actor,$invoice,$hash,$display,$eid,$amount,$at,$now,$status,$detail);
    $s->execute();$ok=$s->affected_rows===1;$s->close();return $ok;
}
// Reserve an actual stored SMS before delivery, in the same lock domain
// as automatic approval. The event remains reserved even if delivery fails.
function deltaSmsReviewQueue(int $id,string $prefix,int $actor): bool {
    return deltaSmsReviewQueueFromStoredEvents($id,$prefix,$actor,deltaSmsApprovalEvents());
}
// Internal entry for the trusted event reader and isolated tests; never takes client payloads.
function deltaSmsReviewQueueFromStoredEvents(int $id,string $prefix,int $actor,array $trustedEvents): bool {
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsReviewSchema()||!preg_match('/^[a-f0-9]{16}$/D',$prefix))return false;
    $connection->begin_transaction();
    try{
        $r=$connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $state=json_decode((string)($r->fetch_assoc()['value']??''),true);
        if(!is_array($state)||deltaReceiptApprovalMode($state)!=='sms')throw new RuntimeException('sms not active');
        $s=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$pay=$s->get_result()->fetch_assoc();$s->close();
        if(!$pay||$pay['state']!=='have_sent'||deltaAutoApprovePolicy($pay['user_id'])==='never'||getSettingValue('AUTOAPPROVE_FAILED_'.$id,'0')==='1'||(function_exists('deltaIsUsdtInvoice')&&deltaIsUsdtInvoice($pay['hash_id'])))throw new RuntimeException('not eligible');
        $b=deltaSmsReviewBinding($pay);if(!$b)throw new RuntimeException('missing binding');
        $matches=array_filter(deltaSmsReviewCandidates($pay,$b,$trustedEvents),static function($e)use($prefix){return substr($e['event_id'],0,16)===$prefix;});
        if(count($matches)!==1)throw new RuntimeException('missing or ambiguous event');
        $e=reset($matches);$eid=$e['event_id'];$display=(int)$b['display_id'];$now=time();$hash=(string)$pay['hash_id'];
        $s=$connection->prepare('UPDATE delta_sms_invoice_bindings SET event_id=?,claimed_at=? WHERE display_id=? AND BINARY hash_id=BINARY ? AND event_id IS NULL');
        $s->bind_param('siis',$eid,$now,$display,$hash);$s->execute();$ok=$s->affected_rows===1;$s->close();
        if(!$ok||!deltaSmsReviewAudit('link',$actor,$pay,$b,$e,'queued','owner verified receipt and deposit'))throw new RuntimeException('cannot reserve');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
function deltaSmsReviewQueuedBinding(array $pay): ?array {
    global $connection;
    if(!deltaSmsReviewSchema())return null;
    $hash=(string)$pay['hash_id'];$id=(int)$pay['id'];$epoch=deltaSmsApprovalEpoch();
    $s=$connection->prepare("SELECT a.id AS review_id,a.actor_id,a.event_id AS matched_event,a.event_at AS matched_event_at,a.amount_irr,a.created_at,b.display_id,b.shown_at,b.nominal_toman FROM delta_sms_admin_audit a JOIN delta_sms_invoice_bindings b ON b.display_id=a.display_id AND BINARY b.event_id=BINARY a.event_id AND BINARY b.hash_id=BINARY a.hash_id WHERE a.action='link' AND a.status='queued' AND a.invoice_id=? AND BINARY a.hash_id=BINARY ? AND a.created_at>=? LIMIT 1");
    $s->bind_param('isi',$id,$hash,$epoch);$s->execute();$b=$s->get_result()->fetch_assoc();$s->close();
    if(!$b||(int)$b['amount_irr']!==(int)$pay['price']*10)return null;
    $b['admin_review']=true;return $b;
}
function deltaSmsReviewClaim(array $pay,array $b): bool {
    global $connection;
    $review=(int)($b['review_id']??0);$display=(int)$b['display_id'];$hash=(string)$pay['hash_id'];$id=(int)$pay['id'];$eid=(string)$b['matched_event'];$price=(int)$pay['price']*10;$epoch=deltaSmsApprovalEpoch();
    if((int)getSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),'0')!==$display)return false;
    $s=$connection->prepare("UPDATE delta_sms_admin_audit a JOIN delta_sms_invoice_bindings b ON b.display_id=a.display_id AND BINARY b.event_id=BINARY a.event_id AND BINARY b.hash_id=BINARY a.hash_id SET a.status='claimed' WHERE a.id=? AND a.invoice_id=? AND BINARY a.hash_id=BINARY ? AND a.display_id=? AND a.event_id=? AND a.amount_irr=? AND a.created_at>=? AND a.action='link' AND a.status='queued'");
    $s->bind_param('iisisii', $review,$id,$hash,$display,$eid,$price,$epoch);
    $s->execute();$ok=$s->affected_rows===1;$s->close();return $ok;
}
function deltaSmsReviewDelivered(array $b): void {
    global $connection;
    if(empty($b['admin_review']))return;
    $id=(int)$b['review_id'];$s=$connection->prepare("UPDATE delta_sms_admin_audit SET status='delivered' WHERE id=? AND status='claimed'");
    $s->bind_param('i',$id);$s->execute();$s->close();
}
// Reset only the epoch. No financial records, receipts, or event IDs deleted.
function deltaSmsReviewReset(int $actor): bool {
    global $connection;
    if(!deltaSmsReviewSchema())return false;
    $connection->begin_transaction();
    try{
        $connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $now=time()+1;upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)$now);
        if(!deltaSmsReviewAudit('reset',$actor,[],[],null,'done','epoch only; existing invoices remain reviewable'))throw new RuntimeException('audit failed');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
function deltaSmsReviewReject(int $id,int $actor): ?array {
    global $connection;
    if(!deltaSmsReviewSchema())return null;
    $connection->begin_transaction();
    try{
        $s=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p||!in_array($p['state'],['pending','have_sent'],true)||getSettingValue('AUTOAPPROVE_FAILED_'.$id,'0')==='1')throw new RuntimeException('not rejectable');
        $hash=(string)$p['hash_id'];$s=$connection->prepare('SELECT id FROM payment_card_displays WHERE BINARY hash_id=BINARY ? LIMIT 1');$s->bind_param('s',$hash);$s->execute();$hasCard=(bool)$s->get_result()->fetch_row();$s->close();if(!$hasCard)throw new RuntimeException('not a card invoice');
        $b=deltaSmsReviewBinding($p);
        $s=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE BINARY hash_id=BINARY ? AND event_id IS NOT NULL LIMIT 1');$s->bind_param('s',$hash);$s->execute();$reserved=(bool)$s->get_result()->fetch_row();$s->close();
        if($reserved)throw new RuntimeException('reserved bank deposit');
        $s=$connection->prepare("UPDATE pays SET state='declined' WHERE id=? AND state IN ('pending','have_sent')");$s->bind_param('i',$id);$s->execute();$ok=$s->affected_rows===1;$s->close();
        if(!$ok||!deltaSmsReviewAudit('reject',$actor,$p,$b??[],null,'done','owner confirmed no payment / duplicate test invoice'))throw new RuntimeException('audit failed');
        $connection->commit();return $p;
    }catch(Throwable $e){$connection->rollback();return null;}
}


function deltaSmsReviewListCutoff(): int {
    return max(0,(int)getSettingValue('SMS_REVIEW_LIST_AFTER_ID','0'));
}
function deltaSmsReviewOpenCount(int $cutoff,bool $archive): int {
    global $connection;
    $op=$archive?'<=':'>';
    $s=$connection->prepare("SELECT COUNT(*) AS c FROM pays p WHERE p.id $op ? AND p.state IN ('pending','have_sent') AND EXISTS(SELECT 1 FROM payment_card_displays d WHERE BINARY d.hash_id=BINARY p.hash_id)");
    $s->bind_param('i',$cutoff);$s->execute();$n=(int)$s->get_result()->fetch_assoc()['c'];$s->close();return $n;
}
function deltaSmsReviewOpenRows(int $cutoff,bool $archive,int $offset): array {
    global $connection;
    $op=$archive?'<=':'>';
    $s=$connection->prepare("SELECT p.* FROM pays p WHERE p.id $op ? AND p.state IN ('pending','have_sent') AND EXISTS(SELECT 1 FROM payment_card_displays d WHERE BINARY d.hash_id=BINARY p.hash_id) ORDER BY (p.state='have_sent') DESC,p.id DESC LIMIT 9 OFFSET ?");
    $s->bind_param('ii',$cutoff,$offset);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
}
function deltaSmsReviewClearList(int $actor): ?array {
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsReviewSchema())return null;
    $connection->begin_transaction();
    try {
        $r=$connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        if(!$r||!$r->fetch_assoc())throw new RuntimeException('settings unavailable');
        $old=deltaSmsReviewListCutoff();
        $cutoff=max($old,(int)$connection->query('SELECT COALESCE(MAX(id),0) AS m FROM pays')->fetch_assoc()['m']);
        $count=deltaSmsReviewOpenCount($old,false);
        if(!upsertSettingValue('SMS_REVIEW_LIST_AFTER_ID',(string)$cutoff))throw new RuntimeException('cutoff save failed');
        if(!deltaSmsReviewAudit('archive',$actor,[],[],null,'done','cutoff='.$cutoff.'; open archived='.$count))throw new RuntimeException('audit failed');
        $connection->commit();return ['cutoff'=>$cutoff,'count'=>$count];
    }catch(Throwable $e){$connection->rollback();return null;}
}

 // Reconcile an already closed invoice without delivering a service again.
function deltaSmsReviewClosedCandidates(array $pay,array $b,?array $trustedEvents=null): array {
    if(!in_array($pay['state']??'',['paid','approved'],true))return [];
    $pay['state']='have_sent';
    return deltaSmsReviewCandidates($pay,$b,$trustedEvents);
}
function deltaSmsReviewRecordClosed(int $id,string $prefix,int $actor,?array $trustedEvents=null): bool {
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsReviewSchema()||!preg_match('/^[a-f0-9]{16}$/D',$prefix))return false;
    $connection->begin_transaction();
    try{
        $connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $s=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p||!in_array($p['state'],['paid','approved'],true))throw new RuntimeException('not settled');
        $b=deltaSmsReviewBinding($p);if(!$b||!deltaSmsSenderReservation($b))throw new RuntimeException('no nominal binding');
        $hash=(string)$p['hash_id'];
        $s=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE BINARY hash_id=BINARY ? AND event_id IS NOT NULL LIMIT 1');$s->bind_param('s',$hash);$s->execute();$used=(bool)$s->get_result()->fetch_row();$s->close();
        if($used)throw new RuntimeException('already linked');
        $hits=array_filter(deltaSmsReviewClosedCandidates($p,$b,$trustedEvents),static function($e)use($prefix){return substr($e['event_id'],0,16)===$prefix;});
        if(count($hits)!==1)throw new RuntimeException('ambiguous evidence');
        $e=reset($hits);$eid=$e['event_id'];$display=(int)$b['display_id'];$now=time();
        $s=$connection->prepare('UPDATE delta_sms_invoice_bindings SET event_id=?,claimed_at=? WHERE display_id=? AND BINARY hash_id=BINARY ? AND event_id IS NULL');$s->bind_param('siis',$eid,$now,$display,$hash);$s->execute();$ok=$s->affected_rows===1;$s->close();
        if(!$ok||!deltaSmsReviewAudit('reconcile',$actor,$p,$b,$e,'done','owner verified previously settled deposit; no delivery'))throw new RuntimeException('cannot reconcile');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
// Explicit owner attestation, never inferred from a declined state alone.
function deltaSmsReviewNoPayment(int $id,int $actor): bool {
    global $connection,$isChildBot;
    if(!empty($isChildBot)||!deltaSmsReviewSchema())return false;
    $connection->begin_transaction();
    try{
        $connection->query("SELECT value FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $s=$connection->prepare('SELECT * FROM pays WHERE id=? FOR UPDATE');$s->bind_param('i',$id);$s->execute();$p=$s->get_result()->fetch_assoc();$s->close();
        if(!$p||$p['state']!=='declined'||getSettingValue('AUTOAPPROVE_FAILED_'.$id,'0')==='1')throw new RuntimeException('not declined');
        $hash=(string)$p['hash_id'];
        $s=$connection->prepare('SELECT id FROM payment_card_displays WHERE BINARY hash_id=BINARY ? LIMIT 1');$s->bind_param('s',$hash);$s->execute();$card=(bool)$s->get_result()->fetch_row();$s->close();
        $s=$connection->prepare('SELECT display_id FROM delta_sms_invoice_bindings WHERE BINARY hash_id=BINARY ? AND event_id IS NOT NULL LIMIT 1');$s->bind_param('s',$hash);$s->execute();$used=(bool)$s->get_result()->fetch_row();$s->close();
        if(!$card||$used)throw new RuntimeException('not eligible');
        $now=time();if(!upsertSettingValue('SMS_NO_PAYMENT_'.sha1($hash),(string)$now)||!deltaSmsReviewAudit('no_payment',$actor,$p,[],null,'done','owner verified no deposit through '.$now))throw new RuntimeException('audit failed');
        $connection->commit();return true;
    }catch(Throwable $e){$connection->rollback();return false;}
}
// Same matcher as automatic approval; list actual competitors for the chosen receipt.
function deltaSmsReviewConflicts(array $p): array {
    $b=deltaSmsReviewBinding($p);if(!$b||!empty($b['event_id']))return [];
    $events=deltaSmsApprovalEvents();$receipt=deltaReceiptSubmittedAt($p['hash_id'],0);$now=time();$epoch=deltaSmsApprovalEpoch();$out=[];
    global $connection;
    foreach($events as $e){
        if(!deltaSmsEventMatches($b,$e,$receipt,$epoch,$now)||deltaSmsEventAlreadyUsed($e['event_id']))continue;
        $at=(int)$e['received_at'];$from=$at-3600;$hash=(string)$p['hash_id'];$price=(int)$p['price'];$rows=[];
        foreach(deltaSmsCompetitionRowsSql() as $sql){
            $s=$connection->prepare($sql);$s->bind_param('siii',$hash,$price,$from,$at);$s->execute();$rows=array_merge($rows,$s->get_result()->fetch_all(MYSQLI_ASSOC));$s->close();
        }
        foreach(deltaSmsCompetingHashes($rows,$events,$at,$e['event_id'],deltaSmsCompetitionHistory($rows),(int)$b['shown_at']) as $h){
            $s=$connection->prepare('SELECT * FROM pays WHERE BINARY hash_id=BINARY ? LIMIT 1');$s->bind_param('s',$h);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();if($r)$out[(int)$r['id']]=$r;
        }
    }
    ksort($out);return array_values($out);
}
