<?php
declare(strict_types=1);
// Disposable fixture only. Never run this against a production database.
require_once __DIR__.'/../delta_app_real_checkout.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if(getenv('DELTA_TEST_DB')!=='delta_checkout_fixture')throw new RuntimeException('fixture database required');
$db=new mysqli(getenv('DELTA_TEST_HOST')?:'127.0.0.1',getenv('DELTA_TEST_USER')?:'root',(string)getenv('DELTA_TEST_PASSWORD'),'delta_checkout_fixture',(int)(getenv('DELTA_TEST_PORT')?:3306));
$db->set_charset('utf8mb4');
function check(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function rejected(callable $cb,string $msg): void {try{$cb();}catch(Throwable $e){echo "PASS $msg\n";return;}throw new RuntimeException('expected failure: '.$msg);}
function upsertSettingValue($key,$value): bool {global $db;daExec($db,'INSERT INTO setting(type,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)','ss',$key,$value);return true;}
function deltaMarkReceiptSubmitted($hash,$at=null): bool {return upsertSettingValue('RECEIPT_AT_'.sha1($hash),(string)($at??time()));}
function deltaReceiptHasSubmissionMarker($hash): bool {global $db;return (bool)daRow($db,'SELECT value FROM setting WHERE type=?','s','RECEIPT_AT_'.sha1($hash));}
foreach([
"CREATE TABLE pays(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,hash_id VARCHAR(9) NOT NULL UNIQUE,description TEXT,user_id BIGINT NOT NULL,type VARCHAR(64) NOT NULL,plan_id INT NOT NULL,volume INT NOT NULL,day INT NOT NULL,price BIGINT NOT NULL,request_date BIGINT NOT NULL,state VARCHAR(32) NOT NULL) ENGINE=InnoDB",
"CREATE TABLE users(userid BIGINT PRIMARY KEY,wallet BIGINT NOT NULL) ENGINE=MyISAM",
"CREATE TABLE setting(type VARCHAR(190) PRIMARY KEY,value TEXT NOT NULL) ENGINE=InnoDB",
"CREATE TABLE payment_card_displays(id BIGINT AUTO_INCREMENT PRIMARY KEY,hash_id VARCHAR(9) NOT NULL) ENGINE=InnoDB"
] as $sql)$db->query($sql);
daSchema($db);daRealSchema($db);
daExec($db,'INSERT INTO delta_app_categories(id,name) VALUES(1,?)','s','fixture');
daExec($db,'INSERT INTO delta_app_plans(id,name,gb,days,price,device_limit,category_id) VALUES(1,?,10,30,30000,1,1)','s','10 GB');
daExec($db,"INSERT INTO delta_app_pg_settings(id,server_id,group_id,panel_base,panel_kind) VALUES(1,1,1,'https://fixture.invalid','app')");
daExec($db,'INSERT INTO users(userid,wallet) VALUES(1001,100000),(1002,1)');
$id=daRealInvoice($db,1001,1);$o=daRealOrder($db,1001,$id);$h=$o['pay_hash'];
check(daRealInvoice($db,1001,1)===$id,'reuse pending invoice');
check((int)$o['price']===30000 && strlen($h)===9 && $o['state']==='pending','exact price and compatible hash');
check(daRealPayByHash($db,$h)['type']==='DELTA_APP_BUY','isolated payment type');
rejected(fn()=>daRealOrder($db,1002,$id),'cross-owner invoice blocked');
rejected(fn()=>daRealDeliver($db,1001,$id),'unpaid provisioning blocked');
check(!daRow($db,'SELECT id FROM delta_app_accounts LIMIT 1'),'unpaid order creates no account');
rejected(fn()=>daRealWallet($db,1001,$id),'wallet disabled before any debit on MyISAM');
check((int)daRow($db,'SELECT wallet FROM users WHERE userid=1001')['wallet']===100000,'existing wallet unchanged');
check(daRealPayByHash($db,$h)['state']==='pending','disabled wallet leaves invoice pending');
$id2=$id;$o2=$o;
$p=daRealPrepareCard($db,1001,$id2);
check((int)$p['price']===30000,'exact card amount');
rejected(fn()=>daRealWallet($db,1001,$id2),'wallet blocked after card selection');
rejected(fn()=>daRealCancel($db,1001,$id2),'card invoice cannot be silently cancelled');
daExec($db,'INSERT INTO payment_card_displays(hash_id) VALUES(?)','s',$o2['pay_hash']);
check(daRealRegisterReceipt($db,$o2['pay_hash'],1001,'photo',time()),'photo receipt registered');
check(!daRealRegisterReceipt($db,$o2['pay_hash'],1001,'text',time()),'duplicate receipt rejected');
check(daRealPayByHash($db,$o2['pay_hash'])['state']==='have_sent','receipt does not equal payment');
rejected(fn()=>daRealDeliver($db,1001,$id2),'unverified receipt cannot provision');
// Simulate the result of a trusted payment claim in the disposable fixture only.
daExec($db,"UPDATE pays SET state='paid' WHERE hash_id=? AND state='have_sent'",'s',$o2['pay_hash']);
check(daRealSyncPaid($db,$o2['pay_hash'])['state']==='paid','card payment synchronizes with order');
check(daRealSyncPaid($db,$o2['pay_hash'])['state']==='paid','repeat sync is idempotent');
check((int)daRow($db,'SELECT wallet FROM users WHERE userid=1001')['wallet']===100000,'card payment leaves wallet unchanged');
check(daRow($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")['ENGINE']==='MyISAM','existing users engine unchanged');
$id3=daRealInvoice($db,1002,1);
rejected(fn()=>daRealWallet($db,1002,$id3),'wallet blocked for all buyers');
check((int)daRow($db,'SELECT wallet FROM users WHERE userid=1002')['wallet']===1,'failed debit leaves balance untouched');
daRealCancel($db,1002,$id3);
check(daRealPayByHash($db,daRealOrder($db,1002,$id3)['pay_hash'])['state']==='cancelled_by_user','unpaid invoice cancellation');
echo "All isolated checkout fixture checks passed.\n";
