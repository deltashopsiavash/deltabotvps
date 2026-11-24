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
$connection->query("DROP TABLE delta_sms_invoice_bindings");
echo "SMS claim integration PASS\\n";
