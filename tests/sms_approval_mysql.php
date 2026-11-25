<?php
// Isolated MySQL integration test: no real customer, SMS, bank or Telegram.
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$connection=new mysqli(getenv('DELTA_CI_MYSQL_HOST'),getenv('DELTA_CI_MYSQL_USER'),getenv('DELTA_CI_MYSQL_PASSWORD'),getenv('DELTA_CI_MYSQL_DB'));
$isChildBot=false;
$connection->query("CREATE TEMPORARY TABLE setting (id INT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(255) UNIQUE,value TEXT) ENGINE=InnoDB");
$connection->query("CREATE TEMPORARY TABLE pays (id INT PRIMARY KEY,hash_id VARCHAR(128),user_id BIGINT,price BIGINT,state VARCHAR(32)) ENGINE=InnoDB");
function getSettingValue($type,$default=null){global $connection;$s=$connection->prepare('SELECT value FROM setting WHERE type=?');$s->bind_param('s',$type);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r['value']??$default;}
function upsertSettingValue($type,$value){global $connection;$s=$connection->prepare('INSERT INTO setting(type,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');$s->bind_param('ss',$type,$value);$s->execute();$s->close();return true;}
require __DIR__.'/../delta_order_features.php';
function ok($condition,$label){if(!$condition)throw new RuntimeException($label);}
function mode($main,$sms){upsertSettingValue('BOT_STATES',json_encode(['cartToCartAutoAcceptState'=>$main,'cartToCartSmsAcceptState'=>$sms]));}
// Recreate the previous schema in isolated CI to exercise the live upgrade.
$connection->query("CREATE TABLE delta_sms_invoice_bindings (
display_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, hash_id VARCHAR(128) NOT NULL, user_id BIGINT NOT NULL,
bank VARCHAR(16) NOT NULL, account_hint CHAR(5) NOT NULL, amount_irr BIGINT UNSIGNED NOT NULL,
nominal_toman BIGINT UNSIGNED NOT NULL, shown_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
event_id CHAR(64) DEFAULT NULL, claimed_at BIGINT UNSIGNED DEFAULT NULL,
UNIQUE KEY sms_amount (bank,account_hint,amount_irr), UNIQUE KEY sms_event (event_id), KEY sms_invoice(hash_id)
) ENGINE=InnoDB");
$connection->query("CREATE TEMPORARY TABLE payment_card_displays (id BIGINT PRIMARY KEY, hash_id VARCHAR(128),user_id BIGINT,card_number VARCHAR(16),shown_at BIGINT) ENGINE=InnoDB");
$now=time();$epoch=$now-120;upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)$epoch);
$oldEvent=str_repeat('f',64);
$connection->query("INSERT INTO delta_sms_invoice_bindings VALUES (10,'test-old',123,'sender','00000',800001,80000,".($now-7200).",".($now-3600).",'$oldEvent',".($now-7000).")");
ok(deltaSmsApprovalSchema(),'schema migration');
$row=$connection->query("SHOW INDEX FROM delta_sms_invoice_bindings WHERE Key_name='sms_amount'")->fetch_assoc();
ok((int)$row['Non_unique']===1,'old unique amount restriction removed');
ok($connection->query("SELECT event_id FROM delta_sms_invoice_bindings WHERE display_id=10")->fetch_assoc()['event_id']===$oldEvent,'old consumed events preserved');
$botState=['cartToCartAutoAcceptState'=>'off','cartToCartSmsAcceptState'=>'on'];mode('off','on');
$connection->query("INSERT INTO pays VALUES (1,'test-a',123,80000,'have_sent'),(2,'test-b',123,80000,'pending'),(3,'test-c',123,90000,'have_sent'),(4,'test-d',123,80000,'have_sent')");
$a=['id'=>1,'hash_id'=>'test-a','user_id'=>123,'price'=>80000,'request_date'=>$now-60];
$b=['id'=>2,'hash_id'=>'test-b','user_id'=>123,'price'=>80000,'request_date'=>$now-60];
$c=['id'=>3,'hash_id'=>'test-c','user_id'=>123,'price'=>90000,'request_date'=>$now-60];
$d=['id'=>4,'hash_id'=>'test-d','user_id'=>123,'price'=>80000,'request_date'=>$now-60];
foreach([[$a,1],[$b,2],[$c,3]] as [$pay,$id]){
    $reserved=deltaSmsReservePresentation($pay,['id'=>$id,'number'=>'0000000000000000']);
    ok(is_array($reserved)&&$reserved['amount_irr']===$pay['price']*10,'exact original price, no suffix');
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($pay['hash_id']),(string)$id);
}
$eventAt=time();
$connection->query("INSERT INTO payment_card_displays VALUES (1,'test-a',123,'0000000000000000',$eventAt),(2,'test-b',123,'0000000000000000',$eventAt),(3,'test-c',123,'0000000000000000',$eventAt)");
$event=str_repeat('a',64);
$trusted=[['event_id'=>$event,'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>800000,'received_at'=>$eventAt,'ingested_at'=>$eventAt]];
deltaMarkReceiptSubmitted('test-a',$eventAt);deltaMarkReceiptSubmitted('test-b',$eventAt);
$binding=['display_id'=>1,'matched_event'=>$event,'matched_event_at'=>$eventAt,'shown_at'=>$eventAt,'bank'=>'nominal','account_hint'=>'00000','amount_irr'=>800000];
mode('off','off');ok(!deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'manual rejects SMS');
mode('on','on');ok(!deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'both on rejects SMS');
mode('off','on');ok(!deltaSmsClaimInvoice($a,null,'sms'),'no evidence rejects claim');
ok(!deltaSmsClaimInvoice($c,null,'legacy'),'SMS-only rejects legacy');
ok(deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'pending same-price invoice is a competitor');
ok(!deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'ambiguous invoice stays manual');
$connection->query("UPDATE pays SET state='approved' WHERE id=2");
ok(deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'manual approved invoice still competes');
ok(!deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'manual approval does not remove ambiguity');
// Simulate a subsequent isolated payment window, not deletion in production.
$connection->query("UPDATE payment_card_displays SET shown_at=".($eventAt-7200)." WHERE id=2");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($eventAt-7200)." WHERE display_id=2");
ok(!deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'older presentation outside window');
ok(deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'unambiguous exact-price claim');
ok(!deltaSmsClaimInvoice($a,$binding,'sms',$trusted),'same invoice cannot be claimed twice');
$connection->query("UPDATE pays SET state='have_sent' WHERE id=2");
$binding['display_id']=2;
$connection->query("UPDATE payment_card_displays SET shown_at=".($eventAt-7200)." WHERE id=1");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($eventAt-7200)." WHERE display_id=1");
ok(!deltaSmsClaimInvoice($b,$binding,'sms',$trusted),'consumed event cannot be reused');
$binding['matched_event']=$oldEvent;
ok(!deltaSmsClaimInvoice($b,$binding,'sms',$trusted),'consumed event from old version cannot be reused');
$r=$connection->query("SELECT state FROM pays WHERE id=2")->fetch_assoc();ok($r['state']==='have_sent','failed claims leave invoice pending');
mode('on','on');ok(deltaSmsClaimInvoice($c,null,'legacy'),'legacy has priority');

// Owner review of real stored evidence, with two concurrent same-price invoices.
ok(deltaSmsReviewSchema(),'admin review schema');
mode('off','on');
$now=time();$eventAt=$now-10;$epoch=$now-120;upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)$epoch);
$connection->query("INSERT INTO pays VALUES (5,'review-a',321,81000,'have_sent'),(6,'review-b',322,81000,'have_sent'),(7,'review-reject',323,82000,'pending'),(8,'review-reset',324,83000,'have_sent')");
foreach([[5,'review-a',321,81000],[6,'review-b',322,81000],[7,'review-reject',323,82000],[8,'review-reset',324,83000]] as [$id,$hash,$uid,$price]){
    $pay=['id'=>$id,'hash_id'=>$hash,'user_id'=>$uid,'price'=>$price];
    $shown=$now-60;$expires=$now+3540;$amount=$price*10;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',$amount,$price,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);
    deltaMarkReceiptSubmitted($hash,$now-5);
}
$first=['event_id'=>str_repeat('b',64),'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>810000,'received_at'=>$eventAt,'ingested_at'=>$eventAt+1];
$second=array_replace($first,['event_id'=>str_repeat('c',64)]);
$third=array_replace($first,['event_id'=>str_repeat('d',64),'amount_irr'=>830000]);
$pa=deltaSmsReviewPay(5);$pb=deltaSmsReviewPay(6);
ok(deltaSmsHasCompetingInvoice('review-a',81000,$eventAt),'automatic ambiguity remains');
ok(!deltaSmsReviewQueueFromStoredEvents(5,substr($first['event_id'],0,16),123,[array_replace($first,['source'=>'notification'])]),'notification cannot be reviewed as bank SMS');
ok(!deltaSmsReviewQueueFromStoredEvents(5,substr($first['event_id'],0,16),123,[array_replace($first,['amount_irr'=>810001])]),'wrong amount cannot be selected');
ok(deltaSmsReviewQueueFromStoredEvents(5,substr($first['event_id'],0,16),123,[$first,$second]),'owner can select specific stored event');
ok(deltaSmsReviewEventUsed($first['event_id']),'event reserved immediately');
ok(!deltaSmsReviewQueueFromStoredEvents(6,substr($first['event_id'],0,16),123,[$first,$second]),'cannot assign same SMS twice');
ok(deltaSmsReviewReject(5,123)===null,'reserved deposit cannot be rejected');
$queued=deltaSmsReviewQueuedBinding($pa);
ok(is_array($queued)&&!empty($queued['admin_review']),'worker finds reviewed event');
ok(deltaSmsClaimInvoice($pa,$queued,'sms'),'owner-confirmed ambiguous payment uses delivery worker');
ok(!deltaSmsClaimInvoice($pa,$queued,'sms'),'reviewed invoice cannot be claimed twice');
deltaSmsReviewDelivered($queued);
ok(deltaSmsReviewQueueFromStoredEvents(6,substr($second['event_id'],0,16),123,[$first,$second]),'second invoice gets a distinct event');
$queued2=deltaSmsReviewQueuedBinding($pb);
ok(deltaSmsClaimInvoice($pb,$queued2,'sms'),'second same-price invoice can be independently claimed');
ok(deltaSmsReviewReject(7,123)!==null,'explicit unpaid rejection recorded');
ok(deltaSmsReviewReject(7,123)===null,'duplicate rejection prevented');
ok(deltaSmsReviewQueueFromStoredEvents(8,substr($third['event_id'],0,16),123,[$third]),'queue before reset');
ok(deltaSmsReviewReset(123),'reset only matching epoch');
ok(deltaSmsReviewQueuedBinding(deltaSmsReviewPay(8))===null,'reset blocks old queued auto delivery');
ok(deltaSmsReviewEventUsed($third['event_id']),'reset preserves event reservation');
ok(deltaSmsReviewPay(8)['state']==='have_sent','reset does not reject paid or pending invoice');
ok((int)$connection->query("SELECT COUNT(*) AS c FROM delta_sms_admin_audit WHERE action='reject'")->fetch_assoc()['c']===1,'rejection audit retained');
// Fresh open-list cutoff must not change payment states, SMS reservations or approval epoch.
$before=deltaSmsReviewOpenCount(0,false);$epochBefore=deltaSmsApprovalEpoch();
$archive=deltaSmsReviewClearList(123);
ok(is_array($archive)&&$archive['count']===$before,'archive records open count');
$cutoff=deltaSmsReviewListCutoff();
ok(deltaSmsReviewOpenCount($cutoff,false)===0,'new list starts empty');
ok(deltaSmsReviewOpenCount($cutoff,true)===$before,'old open invoices remain in archive');
ok(count(deltaSmsReviewOpenRows($cutoff,true,0))===$before,'archive rows still accessible');
ok(deltaSmsReviewPay(8)['state']==='have_sent','archive does not reject receipts');
ok(deltaSmsReviewEventUsed($third['event_id']),'archive preserves SMS reservation');
ok(deltaSmsApprovalEpoch()===$epochBefore,'list archive does not change auto approval epoch');
$connection->query("INSERT INTO pays VALUES (9,'fresh-list',325,83000,'pending')");
$connection->query("INSERT INTO payment_card_displays VALUES (9,'fresh-list',325,'0000000000000000',$now)");
ok(deltaSmsReviewOpenCount($cutoff,false)===1,'new invoice appears in fresh list');
ok(deltaSmsReviewOpenRows($cutoff,false,0)[0]['hash_id']==='fresh-list','only new invoice listed');

// Replay the observed pattern: later SMS after first receipt, then
// an independent same-price invoice. Neither invoice blocks issuance.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-1000));mode('off','on');
$connection->query("INSERT INTO pays VALUES (11,'time-a',501,88000,'have_sent'),(12,'time-b',502,88000,'have_sent'),(13,'time-c',503,88000,'pending')");
foreach([[11,'time-a',501,$now-660,$now-580],[12,'time-b',502,$now-150,$now-60],[13,'time-c',503,$now-30,0]] as [$id,$hash,$uid,$shown,$receipt]){
    $expires=$shown+3600;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',880000,88000,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);
    if($receipt)deltaMarkReceiptSubmitted($hash,$receipt);
}
$ea=['event_id'=>str_repeat('1',64),'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>880000,'received_at'=>$now-600,'ingested_at'=>$now-590];
$eb=array_replace($ea,['event_id'=>str_repeat('2',64),'received_at'=>$now-80,'ingested_at'=>$now-70]);
$events=[$ea,$eb];
$pa=deltaSmsReviewPay(11);$pb=deltaSmsReviewPay(12);
$ba=deltaSmsFindBinding($pa,$now-580,$events);
ok($ba&&$ba['matched_event']===$ea['event_id'],'later deposit is outside first receipt time window');
ok(deltaSmsFindBinding($pb,$now-60,$events)===null,'unsettled earlier invoice remains competing');
ok(deltaSmsClaimInvoice($pa,$ba,'sms',$events),'first distinct payment claims');
$bb=deltaSmsFindBinding($pb,$now-60,$events);
ok($bb&&$bb['matched_event']===$eb['event_id'],'earlier invoice with distinct verified claim no longer competes');
ok(deltaSmsFindBinding($pb,$now-60,[$eb])===null,'missing historical evidence remains ambiguous');
$connection->query("UPDATE pays SET state='approved' WHERE id=11");
ok(deltaSmsFindBinding($pb,$now-60,$events)!==null,'settled SMS claim still excludes earlier invoice');
$connection->query("UPDATE payment_card_displays SET shown_at=".($now-100)." WHERE id=13");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($now-100)." WHERE display_id=13");
ok(!deltaSmsClaimInvoice($pb,$bb,'sms',$events),'new genuine competitor blocks stale pre-lock match');
$connection->query("UPDATE payment_card_displays SET shown_at=".($now-30)." WHERE id=13");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($now-30)." WHERE display_id=13");
ok(deltaSmsClaimInvoice($pb,$bb,'sms',$events),'second sequential receipt claims separate SMS');
ok(!deltaSmsClaimInvoice($pb,$bb,'sms',$events),'replay blocked');
ok(deltaSmsReviewPay(13)['state']==='pending','other same-price invoice remains open');

// Previously approved and declined invoices require explicit reconciliation.
$now=time();
$connection->query("INSERT INTO pays VALUES (21,'closed-a',601,89000,'approved'),(22,'closed-b',602,89000,'declined'),(23,'closed-c',603,89000,'have_sent')");
foreach([[21,'closed-a',601,$now-500],[22,'closed-b',602,$now-400],[23,'closed-c',603,$now-200]] as [$id,$hash,$uid,$shown]){
    $expires=$shown+3600;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',890000,89000,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);
    deltaMarkReceiptSubmitted($hash,$shown+60);
}
$ec=['event_id'=>str_repeat('3',64),'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>890000,'received_at'=>$now-460,'ingested_at'=>$now-450];
$ed=array_replace($ec,['event_id'=>str_repeat('4',64),'received_at'=>$now-150,'ingested_at'=>$now-140]);
$evs=[$ec,$ed];
ok(deltaSmsHasCompetingInvoice('closed-c',89000,$ed['received_at'],$ed['event_id'],$evs),'unreconciled closed invoices compete');
ok(!deltaSmsReviewRecordClosed(22,substr($ec['event_id'],0,16),123,$evs),'declined cannot be linked as paid');
ok(deltaSmsReviewRecordClosed(21,substr($ec['event_id'],0,16),123,$evs),'record earlier independently verified paid SMS without delivery');
ok(deltaSmsReviewPay(21)['state']==='approved','reconcile preserves approved state');
ok(!deltaSmsReviewRecordClosed(21,substr($ec['event_id'],0,16),123,$evs),'duplicate paid reconciliation blocked');
ok(deltaSmsHasCompetingInvoice('closed-c',89000,$ed['received_at'],$ed['event_id'],$evs),'unverified declined still competes');
ok(!deltaSmsReviewNoPayment(23,123),'open receipt cannot be excluded');
ok(deltaSmsReviewNoPayment(22,123),'owner verifies declined unpaid');
ok(!deltaSmsHasCompetingInvoice('closed-c',89000,$ed['received_at'],$ed['event_id'],$evs),'verified old conflicts cleared');
ok(deltaSmsHasCompetingInvoice('closed-c',89000,time()+60,str_repeat('5',64),$evs),'later deposits remain protected');
ok(deltaSmsReviewPay(22)['state']==='declined','declined state preserved');

// Regression for the live recurrence: a new checkout AFTER the owner
// verified a declined receipt's whole time window must not require a repeat.
$now=time();
$verified=(int)getSettingValue('SMS_NO_PAYMENT_'.sha1('closed-b'),'0');
ok($verified>0,'existing no-payment marker remains usable');
ok(!deltaSmsHasCompetingInvoice('closed-c',89000,$verified+60,str_repeat('5',64),$evs,$verified+10),'fully reviewed declined receipt retired for new checkout');
ok(deltaSmsHasCompetingInvoice('closed-c',89000,$verified+60,str_repeat('5',64),$evs,$verified-10),'checkout before review remains ambiguous');
// A new receipt or later card presentation must invalidate retirement.
deltaMarkReceiptSubmitted('closed-b',$verified-30);
ok(deltaSmsHasCompetingInvoice('closed-c',89000,$verified+60,str_repeat('5',64),$evs,$verified+10),'review must cover complete receipt grace window');
deltaMarkReceiptSubmitted('closed-b',$verified-300);
$connection->query("INSERT INTO payment_card_displays VALUES (24,'closed-b',602,'0000000000000000',".($verified+20).")");
ok(deltaSmsHasCompetingInvoice('closed-c',89000,$verified+60,str_repeat('5',64),$evs,$verified+10),'new card presentation invalidates old review');
$connection->query("DELETE FROM payment_card_displays WHERE id=24");
// Synthetic next checkout after a historical verified cancellation.
// Only fixture timestamps are changed; no production records are altered.
$mark=$now-30;upsertSettingValue('SMS_NO_PAYMENT_'.sha1('closed-b'),(string)$mark);
deltaMarkReceiptSubmitted('closed-b',$now-340);
$connection->query("INSERT INTO pays VALUES (25,'closed-next',604,89000,'have_sent')");
$shown=$now-20;$expires=$shown+3600;
$connection->query("INSERT INTO payment_card_displays VALUES (25,'closed-next',604,'0000000000000000',$shown)");
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (25,'closed-next',604,'nominal','00000',890000,89000,$shown,$expires)");
upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1('closed-next'),'25');deltaMarkReceiptSubmitted('closed-next',$now-2);
// Settle the earlier distinct checkout with its own event first.
$prior=deltaSmsReviewPay(23);
ok(deltaSmsReviewQueueFromStoredEvents(23,substr($ed['event_id'],0,16),123,$evs),'old open payment reconciled');
ok(deltaSmsClaimInvoice($prior,deltaSmsReviewQueuedBinding($prior),'sms'),'old open payment settled');
$en=array_replace($ec,['event_id'=>str_repeat('6',64),'received_at'=>$now-10,'ingested_at'=>$now-9]);
$all=[$ec,$ed,$en];$next=deltaSmsReviewPay(25);
$nextBinding=deltaSmsFindBinding($next,$now-2,$all);
ok($nextBinding&&$nextBinding['matched_event']===$en['event_id'],'new purchase sees only its own event after verified closure');
ok(deltaSmsClaimInvoice($next,$nextBinding,'sms',$all),'next purchase approved without repeating declined review');
ok(deltaSmsReviewPay(22)['state']==='declined','old cancellation and financial records preserved');
ok(!deltaSmsClaimInvoice($next,$nextBinding,'sms',$all),'new SMS cannot be reused');

// A prior manual approval, followed by a distinct same-price checkout.
// The reconciliation must not change its approved state or deliver again.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-1000));mode('off','on');
$connection->query("INSERT INTO pays VALUES (31,'manual-first',701,91000,'approved'),(32,'manual-next',702,91000,'have_sent')");
foreach([[31,'manual-first',701,$now-500,$now-440],[32,'manual-next',702,$now-100,$now-40]] as [$id,$hash,$uid,$shown,$receipt]){
    $expires=$shown+3600;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',910000,91000,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);deltaMarkReceiptSubmitted($hash,$receipt);
}
$em=['event_id'=>str_repeat('7',64),'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>910000,'received_at'=>$now-450,'ingested_at'=>$now-449];
$ef=array_replace($em,['event_id'=>str_repeat('8',64),'received_at'=>$now-35,'ingested_at'=>$now-34]);
$fixture=[$em,$ef];
ok(deltaSmsFindBinding(deltaSmsReviewPay(32),$now-40,$fixture)===null,'unlinked manual approval initially blocks');
$connection->query("INSERT INTO pays VALUES (33,'manual-competitor',703,91000,'pending')");
$connection->query("INSERT INTO payment_card_displays VALUES (33,'manual-competitor',703,'0000000000000000',".($now-480).")");
ok(deltaSmsReconcileSettled($fixture)===0,'ambiguous earlier deposit never silently assigned');
ok(!deltaSmsReviewEventUsed($em['event_id']),'ambiguous old SMS remains unused');
$connection->query("DELETE FROM payment_card_displays WHERE id=33");
ok(deltaSmsReconcileSettled($fixture)===1,'unique old manually settled payment linked');
ok(deltaSmsReviewPay(31)['state']==='approved','prior manual payment not reopened');
ok(deltaSmsReconcileSettled($fixture)===0,'reconciliation idempotent');
$next=deltaSmsReviewPay(32);$bind=deltaSmsFindBinding($next,$now-40,$fixture);
ok($bind&&$bind['matched_event']===$ef['event_id'],'new receipt matches SMS arriving after receipt');
ok(deltaSmsClaimInvoice($next,$bind,'sms',$fixture),'next independent payment approved');

// Production regression: the oldest approved receipt passed the live
// fulfillment deadline before its missing bank link was repaired.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-7200));mode('off','on');
$connection->query("INSERT INTO pays VALUES (41,'expired-settled',801,92000,'approved'),(42,'middle-settled',802,92000,'approved'),(43,'new-open',803,92000,'have_sent')");
foreach([[41,'expired-settled',801,$now-4700,$now-4500],[42,'middle-settled',802,$now-2300,$now-2200],[43,'new-open',803,$now-200,$now-140]] as [$id,$hash,$uid,$shown,$receipt]){
    $expires=$shown+3600;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',920000,92000,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);deltaMarkReceiptSubmitted($hash,$receipt);
}
$x=['event_id'=>str_repeat('9',64),'bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>920000,'received_at'=>$now-4600,'ingested_at'=>$now-4599];
$y=array_replace($x,['event_id'=>str_repeat('a',63).'1','received_at'=>$now-2250,'ingested_at'=>$now-2249]);
$z=array_replace($x,['event_id'=>str_repeat('b',63).'1','bank'=>'khavar','received_at'=>$now-150,'ingested_at'=>$now-149]);
$chain=[$x,$y,$z];
$old=deltaSmsReviewPay(41);
ok(deltaSmsFindBinding($old,$now-4500,$chain)===null,'normal expired payment cannot be fulfilled');
ok(deltaSmsFindBinding($old,$now-4500,$chain,true)!==null,'expired approved receipt can be reconciled historically');
ok(deltaSmsFindBinding(deltaSmsReviewPay(43),$now-140,$chain,true)===null,'historical mode cannot be used on an open invoice');
ok(deltaSmsReconcileSettled($chain)===2,'expired approved invoice and subsequent approved invoice reconciled in order');
ok(deltaSmsReviewPay(41)['state']==='approved'&&deltaSmsReviewPay(42)['state']==='approved','historical reconciliation cannot change financial state');
ok(deltaSmsReviewEventUsed($x['event_id'])&&deltaSmsReviewEventUsed($y['event_id']),'old evidence consumed once');
$open=deltaSmsReviewPay(43);$bind=deltaSmsFindBinding($open,$now-140,$chain);
ok($bind&&$bind['matched_event']===$z['event_id'],'new khavar payment unblocked');
ok(deltaSmsClaimInvoice($open,$bind,'sms',$chain),'current live invoice claimed once');

// Older manual reconciliation must not disappear merely because the
// ordinary two-hour recent SMS view rolled over. Keep live approval strict.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-20000));mode('off','on');
$connection->query("INSERT INTO pays VALUES (51,'historical-paid',901,93000,'approved'),(52,'historical-open',902,94000,'have_sent')");
foreach([[51,'historical-paid',901,93000],[52,'historical-open',902,94000]] as [$id,$hash,$uid,$price]){
    $shown=$now-10800;$expires=$shown+3600;$amount=$price*10;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',$amount,$price,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);deltaMarkReceiptSubmitted($hash,$shown+60);
}
$hx=['event_id'=>str_repeat('c',63).'1','bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>930000,'received_at'=>$now-10750,'ingested_at'=>$now-10749];
$hy=array_replace($hx,['event_id'=>str_repeat('d',63).'1','amount_irr'=>940000]);
ok(deltaSmsReconciliationLookbackSeconds()===86400,'bounded historical evidence window');
ok(deltaSmsReconcileSettled([$hx,$hy])===1,'three-hour-old settled receipt can be linked');
ok(deltaSmsReviewEventUsed($hx['event_id']),'old paid evidence is used once');
ok(deltaSmsReviewPay(51)['state']==='approved','historical state untouched');
ok(deltaSmsFindBinding(deltaSmsReviewPay(52),$now-10740,[$hx,$hy])===null,'old open invoice cannot be automatically fulfilled');
ok(!deltaSmsReviewEventUsed($hy['event_id']),'expired open payment stays available for owner review');

// Production regression: bank timestamp is 27 seconds before display.
// Only owner-confirmed historical reconciliation can consume this evidence.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-20000));mode('off','on');
$connection->query("INSERT INTO pays VALUES (61,'early-settled',1001,95000,'approved'),(62,'after-early',1002,95000,'have_sent')");
foreach([[61,'early-settled',1001,$now-1300,$now-1292],[62,'after-early',1002,$now-200,$now-140]] as [$id,$hash,$uid,$shown,$receipt]){
    $expires=$shown+3600;
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$shown)");
    $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($id,'$hash',$uid,'nominal','00000',950000,95000,$shown,$expires)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);deltaMarkReceiptSubmitted($hash,$receipt);
}
$early=['event_id'=>str_repeat('e',63).'1','bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>950000,'received_at'=>$now-1327,'ingested_at'=>$now-1198];
$later=array_replace($early,['event_id'=>str_repeat('f',63).'1','received_at'=>$now-150,'ingested_at'=>$now-149]);
$tooOld=array_replace($early,['event_id'=>str_repeat('1',63).'2','received_at'=>$now-1601]);
$fixtures=[$early,$later,$tooOld];
$prior=deltaSmsReviewPay(61);$b=deltaSmsReviewBinding($prior);
ok(deltaSmsFindBinding($prior,$now-1292,$fixtures,true)===null,'early event never auto reconciled');
$admin=123;
$beforeEpoch=deltaSmsApprovalEpoch();
$blocked=deltaSmsReviewRepairConflicts(123,$fixtures);
ok(is_array($blocked)&&$blocked['linked']===0,'repair never guesses early evidence');
ok(in_array(61,array_map('intval',array_column($blocked['rows'],'id')),true),'repair lists actual blocker');
ok(!deltaSmsReviewEventUsed($early['event_id'])&&deltaSmsReviewPay(62)['state']==='have_sent','repair cannot consume ambiguous money or fulfill live invoice');
ok(deltaSmsApprovalEpoch()===$beforeEpoch,'repair does not reset approval epoch');
ok(deltaSmsReviewRepairConflicts(124,$fixtures)===null,'non-owner repair denied');
$isChildBot=true;
ok(deltaSmsReviewRepairConflicts(123,$fixtures)===null,'child repair denied');
$isChildBot=false;

ok(deltaSmsReconcileSettled($fixtures)===0,'worker cannot attach early event');
ok(isset(deltaSmsReviewClosedCandidates($prior,$b,$fixtures)[$early['event_id']]),'owner can review early settled evidence');
ok(!isset(deltaSmsReviewClosedCandidates($prior,$b,$fixtures)[$tooOld['event_id']]),'early exception limited to five minutes');
ok(!deltaSmsReviewRecordClosed(62,substr($early['event_id'],0,16),123,$fixtures),'early exception cannot approve open invoice');
ok(!deltaSmsReviewRecordClosed(61,substr($early['event_id'],0,16),0,$fixtures),'early exception requires owner actor');
ok(deltaSmsReviewRecordClosed(61,substr($early['event_id'],0,16),123,$fixtures),'owner confirms earlier paid deposit without delivery');
ok(deltaSmsReviewPay(61)['state']==='approved','settled state preserved');
ok(deltaSmsReviewEventUsed($early['event_id']),'early event consumed exactly once');
ok(!deltaSmsReviewRecordClosed(61,substr($early['event_id'],0,16),123,$fixtures),'no duplicate reconciliation');
$next=deltaSmsReviewPay(62);$nb=deltaSmsFindBinding($next,$now-140,$fixtures);
ok($nb&&$nb['matched_event']===$later['event_id'],'verified early deposit no longer blocks new distinct event');
ok(deltaSmsClaimInvoice($next,$nb,'sms',$fixtures),'live approval still claims its own event');

// Text receipts register the original arrival time and the same state as
// photo receipts; a slow Telegram admin report cannot shift the timestamp.
$connection->query("ALTER TABLE pays ADD COLUMN message_id BIGINT NOT NULL DEFAULT 0, ADD COLUMN chat_id BIGINT NOT NULL DEFAULT 0");
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-1000));mode('off','on');
$connection->query("INSERT INTO pays(id,hash_id,user_id,price,state) VALUES (71,'text-live',1101,96000,'pending'),(72,'text-other',1102,97000,'pending')");
$shown=$now-200;$expires=$shown+3600;
$connection->query("INSERT INTO payment_card_displays VALUES (71,'text-live',1101,'0000000000000000',$shown)");
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (71,'text-live',1101,'nominal','00000',960000,96000,$shown,$expires)");
upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1('text-live'),'71');
ok(!deltaRegisterTextReceipt('text-live',1102,$now-80,10,123),'other user cannot register text receipt');
ok(!deltaReceiptHasSubmissionMarker('text-live'),'failed registration does not create marker');
ok(deltaRegisterTextReceipt('text-live',1101,$now-80,10,123),'text receipt registration succeeds');
$txt=deltaSmsReviewPay(71);
ok($txt['state']==='have_sent'&&(int)$txt['message_id']===10&&(int)$txt['chat_id']===123,'text receipt has worker state and admin target');
ok(getSettingValue('RECEIPT_KIND_'.sha1('text-live'))==='text','text receipt type stored');
ok(deltaReceiptSubmittedAt('text-live')===$now-80,'original receipt arrival retained');
ok(!deltaRegisterTextReceipt('text-live',1101,$now-5,11,123),'duplicate receipt cannot overwrite timestamp');
ok(deltaReceiptSubmittedAt('text-live')===$now-80,'duplicate leaves original receipt timestamp');
$te=['event_id'=>str_repeat('2',63).'3','bank'=>'pasargad','source'=>'sms','sender'=>'fixture','amount_irr'=>960000,'received_at'=>$now-70,'ingested_at'=>$now-69];
$tb=deltaSmsFindBinding($txt,deltaReceiptSubmittedAt('text-live'),[$te]);
ok($tb&&$tb['matched_event']===$te['event_id'],'text receipt uses the same bank SMS matching as photo');
ok(deltaSmsClaimInvoice($txt,$tb,'sms',[$te]),'text receipt claims payment once');
ok(!deltaSmsClaimInvoice($txt,$tb,'sms',[$te]),'text payment cannot be replayed');
ok(deltaSmsReviewPay(71)['state']==='paid','text receipt paid');
ok(deltaSmsReviewPay(72)['state']==='pending','unpaid text invoice not approved');

// Replacing a mother's checkout must not erase the invoice behind a
// bank binding. Cancellation alone is never treated as proof of no payment.
$now=time();
$connection->query("INSERT INTO pays(id,hash_id,user_id,price,state) VALUES (81,'superseded-old',1201,97500,'pending'),(82,'superseded-new',1202,97500,'have_sent')");
$shown=$now-120;$expires=$shown+3600;
$connection->query("INSERT INTO payment_card_displays VALUES (81,'superseded-old',1201,'0000000000000000',$shown)");
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (81,'superseded-old',1201,'nominal','00000',975000,97500,$shown,$expires)");
$sql="DELETE FROM `pays` WHERE `user_id` = ? AND `state` = 'pending'";
ok(deltaSupersedePendingInvoiceSql($sql,true)===$sql,'child cleanup unchanged');
$s=$connection->prepare(deltaSupersedePendingInvoiceSql($sql));$uid=1201;$s->bind_param('i',$uid);$s->execute();$s->close();
ok(deltaSmsReviewPay(81)['state']==='cancelled_by_user','mother replacement keeps previous checkout history');
ok(deltaSmsReviewPay(82)['state']==='have_sent','other buyer unaffected');
foreach(['payWithWalletsuperseded-old','payCustomWithWalletsuperseded-old','payPhotoReceiptsuperseded-old~81','payTextReceipt|superseded-old|payWithCartToCartsuperseded-old','awaitingPayChoice|payRenewWithCartToCartsuperseded-old','payWithTronWalletsuperseded-old','payWithWeSwapsuperseded-old','haveDiscountSelectPlan_1_2_81','discountRenew1_81'] as $flow)
    ok(deltaIsCancelledCheckoutFlow($flow),'cancelled old payment entry blocked: '.$flow);
ok(!deltaIsCancelledCheckoutFlow('payWithWalletsuperseded-new'),'current buyer checkout unaffected');
ok(!deltaIsCancelledCheckoutFlow('smsReviewInvoice81'),'management review unaffected');
$eid=str_repeat('3',63).'4';
ok(deltaSmsHasCompetingInvoice('superseded-new',97500,$now-10,$eid,[]),'cancelled checkout remains a real possible competitor');
ok(deltaSmsReviewNoPayment(81,123),'owner can review cancelled checkout');
ok(!deltaSmsHasCompetingInvoice('superseded-new',97500,$now-10,$eid,[]),'verified unpaid cancelled checkout no longer competes');
ok(deltaSmsReviewPay(81)['state']==='cancelled_by_user','review preserves cancellation state');
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (83,'old-orphan',1203,'nominal','00000',975000,97500,$shown,$expires)");
ok(deltaSmsHasCompetingInvoice('superseded-new',97500,$now-10,$eid,[]),'preexisting orphan not silently assumed unpaid');
// Recover a unique earlier display on the same already-settled invoice.
// Never use this fallback for a live invoice or an unrelated orphan.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-5000));mode('off','on');$admin=123;
$connection->query("INSERT INTO pays(id,hash_id,user_id,price,state) VALUES (91,'redisplay-settled',1301,98300,'approved'),(92,'redisplay-open',1302,98400,'have_sent'),(93,'redisplay-ambiguous',1303,98500,'approved')");
foreach([[91,'redisplay-settled',1301,98300],[92,'redisplay-open',1302,98400],[93,'redisplay-ambiguous',1303,98500]] as [$id,$hash,$uid,$price]){
    foreach([[$id,$now-600],[$id+10,$now-100]] as [$display,$shown]){
        $expires=$shown+3600;$amount=$price*10;
        $connection->query("INSERT INTO payment_card_displays VALUES ($display,'$hash',$uid,'0000000000000000',$shown)");
        $connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES ($display,'$hash',$uid,'nominal','00000',$amount,$price,$shown,$expires)");
    }
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)($id+10));deltaMarkReceiptSubmitted($hash,$now-50);
}
$re=['event_id'=>str_repeat('4',63).'5','bank'=>'melli','source'=>'sms','sender'=>'fixture','amount_irr'=>983000,'received_at'=>$now-300,'ingested_at'=>$now-299];
$ro=array_replace($re,['event_id'=>str_repeat('5',63).'6','amount_irr'=>984000]);
$ra=array_replace($re,['event_id'=>str_repeat('6',63).'7','amount_irr'=>985000]);
$rb=array_replace($ra,['event_id'=>str_repeat('7',63).'8','received_at'=>$now-250,'ingested_at'=>$now-249]);
$rep=[$re,$ro,$ra,$rb];
ok(deltaSmsFindBinding(deltaSmsReviewPay(91),$now-50,$rep,true)===null,'latest display alone cannot match earlier event');
ok(deltaSmsReviewHistoricalMatch(deltaSmsReviewPay(92),$rep)===null,'open invoice never recovered');
ok(deltaSmsReviewHistoricalMatch(deltaSmsReviewPay(93),$rep)===null,'multiple possible events remain blocked');
$beforeEpoch=deltaSmsApprovalEpoch();
$fixed=deltaSmsReviewRepairConflicts(123,$rep);
ok($fixed['historical_linked']===1,'one unique historical presentation recovered');
ok(deltaSmsReviewEventUsed($re['event_id'])&&!deltaSmsReviewEventUsed($ro['event_id'])&&!deltaSmsReviewEventUsed($ra['event_id']),'only unique settled evidence consumed');
ok(deltaSmsReviewPay(91)['state']==='approved'&&deltaSmsReviewPay(92)['state']==='have_sent','no financial state change');
ok(deltaSmsApprovalEpoch()===$beforeEpoch,'repair preserves epoch');
ok(deltaSmsReviewRepairConflicts(123,$rep)['linked']===0,'historical repair idempotent');
// Exact-price account separation: same amount, distinct verified accounts.
$now=time();upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)($now-600));mode('off','on');
$connection->query("INSERT INTO pays(id,hash_id,user_id,price,state) VALUES (111,'scope-a',1401,98700,'have_sent'),(112,'scope-b',1402,98700,'have_sent')");
foreach([[111,'scope-a',1401,'melli','11111'],[112,'scope-b',1402,'pasargad','22222']] as [$id,$hash,$uid,$bank,$hint]){
    $p=deltaSmsReviewPay($id);$p['request_date']=$now-10;
    $r=deltaSmsReservePresentation($p,['id'=>$id,'sms_bank'=>$bank,'sms_hint'=>$hint]);
    ok($r&&$r['amount_irr']===987000,'mapped account keeps exact nominal amount');
    $connection->query("INSERT INTO payment_card_displays VALUES ($id,'$hash',$uid,'0000000000000000',$now)");
    upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1($hash),(string)$id);deltaMarkReceiptSubmitted($hash,$now);
}
$ea=['event_id'=>str_repeat('8',63).'9','bank'=>'melli','source'=>'sms','sender'=>'fixture','account_hint'=>'11111','amount_irr'=>987000,'received_at'=>$now,'ingested_at'=>$now];
$eb=array_replace($ea,['event_id'=>str_repeat('9',63).'8','bank'=>'pasargad','account_hint'=>'22222']);
$pa=deltaSmsReviewPay(111);$pb=deltaSmsReviewPay(112);
ok(deltaSmsFindBinding($pa,$now,[$eb])===null,'wrong destination never matches');
ok(deltaSmsFindBinding($pa,$now,[array_replace($ea,['account_hint'=>''])])===null,'missing account never matches mapped invoice');
$ma=deltaSmsFindBinding($pa,$now,[$ea,$eb]);ok($ma&&$ma['matched_event']===$ea['event_id'],'different proven accounts do not compete');
ok(deltaSmsClaimInvoice($pa,$ma,'sms',[$ea,$eb]),'mapped invoice exact amount claim');
$mb=deltaSmsFindBinding($pb,$now,[$ea,$eb]);ok($mb&&$mb['matched_event']===$eb['event_id'],'second account matches independently');
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (113,'scope-orphan',1403,'nominal','00000',987000,98700,$now,".($now+3600).")");
ok(deltaSmsFindBinding($pb,$now,[$ea,$eb])===null,'unknown orphan remains competitor');
$connection->query("UPDATE delta_sms_invoice_bindings SET bank='acct_pasargad',account_hint='22222' WHERE display_id=113");
ok(deltaSmsFindBinding($pb,$now,[$ea,$eb])===null,'same-account orphan remains competitor');
$connection->query("UPDATE delta_sms_invoice_bindings SET bank='acct_melli',account_hint='11111' WHERE display_id=113");
ok(deltaSmsFindBinding($pb,$now,[$ea,$eb])!==null,'different immutable account scope can be separated');
$connection->query("DROP TABLE delta_sms_admin_audit");
$connection->query("DROP TABLE delta_sms_invoice_bindings");
echo "SMS claim integration PASS\\n";
