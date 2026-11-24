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
ok(deltaSmsApprovalSchema(),'schema');
$connection->query("INSERT INTO pays VALUES (1,'test-a',123,80000,'have_sent'),(2,'test-b',123,80000,'have_sent'),(3,'test-c',123,80000,'have_sent')");
$now=time();$epoch=$now-120;upsertSettingValue('SMS_APPROVAL_FROM_TS',(string)$epoch);
$connection->query("INSERT INTO delta_sms_invoice_bindings(display_id,hash_id,user_id,bank,account_hint,amount_irr,nominal_toman,shown_at,expires_at) VALUES (1,'test-a',123,'melli','00001',800001,80000,$now,$now+3600),(2,'test-b',123,'melli','00001',800002,80000,$now,$now+3600)");
upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1('test-a'),'1');
upsertSettingValue('PAYMENT_CARD_SELECTED_'.sha1('test-b'),'2');
$a=['id'=>1,'hash_id'=>'test-a','user_id'=>123,'price'=>80000];
$b=['id'=>2,'hash_id'=>'test-b','user_id'=>123,'price'=>80000];
$c=['id'=>3,'hash_id'=>'test-c','user_id'=>123,'price'=>80000];
$event=str_repeat('a',64);$binding=['display_id'=>1,'matched_event'=>$event,'shown_at'=>$now];
mode('off','off');ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'manual rejects SMS');
mode('on','on');ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'both on rejects SMS');
mode('off','on');ok(!deltaSmsClaimInvoice($a,null,'sms'),'no payment evidence rejects claim');
ok(!deltaSmsClaimInvoice($c,null,'legacy'),'SMS-only rejects legacy claim');
ok(deltaSmsClaimInvoice($a,$binding,'sms'),'valid SMS claim');
ok(!deltaSmsClaimInvoice($a,$binding,'sms'),'same invoice cannot be claimed twice');
$binding['display_id']=2;ok(!deltaSmsClaimInvoice($b,$binding,'sms'),'same event cannot be applied to another invoice');
$r=$connection->query("SELECT state FROM pays WHERE id=2")->fetch_assoc();ok($r['state']==='have_sent','failed event claim does not mark invoice paid');
mode('on','on');ok(deltaSmsClaimInvoice($c,null,'legacy'),'legacy has priority');
$connection->query("DELETE FROM delta_sms_invoice_bindings WHERE hash_id IN ('test-a','test-b')");
echo "SMS claim integration PASS\n";
