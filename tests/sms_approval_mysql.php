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
$binding=['display_id'=>1,'matched_event'=>$event,'matched_event_at'=>$eventAt,'shown_at'=>$eventAt,'bank'=>'nominal','account_hint'=>'00000','amount_irr'=>800000];
mode('off','off');ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'manual rejects SMS');
mode('on','on');ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'both on rejects SMS');
mode('off','on');ok(!deltaSmsClaimInvoice($a,null,'sms'),'no evidence rejects claim');
ok(!deltaSmsClaimInvoice($c,null,'legacy'),'SMS-only rejects legacy');
ok(deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'pending same-price invoice is a competitor');
ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'ambiguous invoice stays manual');
$connection->query("UPDATE pays SET state='approved' WHERE id=2");
ok(deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'manual approved invoice still competes');
ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'manual approval does not remove ambiguity');
// Simulate a subsequent isolated payment window, not deletion in production.
$connection->query("UPDATE payment_card_displays SET shown_at=".($eventAt-7200)." WHERE id=2");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($eventAt-7200)." WHERE display_id=2");
ok(!deltaSmsHasCompetingInvoice('test-a',80000,$eventAt),'older presentation outside window');
ok(deltaSmsClaimInvoice($a,$binding,'sms'),'unambiguous exact-price claim');
ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'same invoice cannot be claimed twice');
$connection->query("UPDATE pays SET state='have_sent' WHERE id=2");
$binding['display_id']=2;
$connection->query("UPDATE payment_card_displays SET shown_at=".($eventAt-7200)." WHERE id=1");
$connection->query("UPDATE delta_sms_invoice_bindings SET shown_at=".($eventAt-7200)." WHERE display_id=1");
ok(!deltaSmsClaimInvoice($b,$binding,'sms'),'consumed event cannot be reused');
$binding['matched_event']=$oldEvent;
ok(!deltaSmsClaimInvoice($b,$binding,'sms'),'consumed event from old version cannot be reused');
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
$connection->query("DROP TABLE delta_sms_admin_audit");
$connection->query("DROP TABLE delta_sms_invoice_bindings");
echo "SMS claim integration PASS\\n";
