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
"CREATE TABLE pays(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,hash_id VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, UNIQUE KEY fixture_hash(hash_id(64)),description TEXT,user_id BIGINT NOT NULL,type VARCHAR(100) NOT NULL,plan_id INT NOT NULL,volume INT NOT NULL,day INT NOT NULL,price INT NOT NULL,request_date BIGINT NOT NULL,state VARCHAR(255) NOT NULL) ENGINE=InnoDB",
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
// A forged or obsolete wallet state must never provision a card-only order.
daExec($db,"UPDATE pays SET state='paid_with_wallet' WHERE hash_id=?",'s',$h);
rejected(fn()=>daRealSyncPaid($db,$h),'wallet financial state rejected');
daExec($db,"UPDATE pays SET state='pending' WHERE hash_id=?",'s',$h);
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
// Exercise the wallet path only after a disposable fixture migration.
$db->query('ALTER TABLE users ENGINE=InnoDB');
check(daRealWalletAvailable($db),'wallet enabled only on transactional users');
$id4=daRealInvoice($db,1001,1);$o4=daRealOrder($db,1001,$id4);
rejected(fn()=>daRealWallet($db,1002,$id4),'cross-customer debit blocked');
daRealWallet($db,1001,$id4);daRealWallet($db,1001,$id4);
check((int)daRow($db,'SELECT wallet FROM users WHERE userid=1001')['wallet']===70000,'wallet charged exactly once');
check(daRealPayByHash($db,$o4['pay_hash'])['state']==='paid_with_wallet','wallet payment recorded');
check(daRealSyncPaid($db,$o4['pay_hash'])['method']==='wallet','wallet paid order recoverable');
rejected(fn()=>daRealPrepareCard($db,1001,$id4),'paid wallet invoice cannot switch to card');
$id5=daRealInvoice($db,1002,1);$o5=daRealOrder($db,1002,$id5);
rejected(fn()=>daRealWallet($db,1002,$id5),'insufficient wallet rejected on InnoDB');
check((int)daRow($db,'SELECT wallet FROM users WHERE userid=1002')['wallet']===1,'insufficient wallet unchanged');
check(daRealPayByHash($db,$o5['pay_hash'])['state']==='pending','insufficient wallet invoice unchanged');
daRealPrepareCard($db,1002,$id5);
rejected(fn()=>daRealWallet($db,1002,$id5),'card invoice cannot use wallet');
daExec($db,'INSERT INTO payment_card_displays(hash_id) VALUES(?)','s',$o5['pay_hash']);
check(daRealRegisterReceipt($db,$o5['pay_hash'],1002,'text',time()),'second customer text receipt');
daRealReject($db,$o5['pay_hash']);
check(daRealPayByHash($db,$o5['pay_hash'])['state']==='declined','admin rejection persists');
check(daRealOrder($db,1002,$id5)['state']==='cancelled','rejection closes app order');
rejected(fn()=>daRealReject($db,$o5['pay_hash']),'duplicate rejection blocked');
rejected(fn()=>daRealDeliver($db,1002,$id5),'rejected order cannot provision');
// Isolated customer service operations. No network call to a production panel.
check(daRealWalletPreview($db,1001,30000)===['balance'=>70000,'after'=>40000],'live wallet preview');
check(daRealWalletPreview($db,1002,30000)['after']===-29999,'wallet preview exposes shortfall');
$reports=[];
function deltaUserShortInfo($uid){return 'buyer '.$uid;}
function deltaTrackingLine($hash){return 'tracking '.$hash;}
function sendToAdmins($txt,$key=null,$parse=null){$GLOBALS['reports'][]=$txt;}
$aid=90;$uname='fixture_delete_user';$old=password_hash('old-password',PASSWORD_DEFAULT);
daExec($db,'INSERT INTO delta_app_accounts(id,username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(?,?,?,1,10,?,1,?,1)','issii',$aid,$uname,$old,time()+86400,time());
daExec($db,"INSERT INTO delta_app_pg_users(account_id,server_id,group_id,panel_base,pg_name,marker,panel_kind) VALUES(?,999,1,'https://fixture.invalid',?,'fixture-marker','app')",'is',$aid,$uname);
daExec($db,"UPDATE delta_app_real_orders SET state='delivered',account_id=? WHERE id=?",'ii',$aid,$id4);
rejected(fn()=>daRealResetPassword($db,1002,$id4),'cross-owner password reset blocked');
$pin=daRealResetPassword($db,1001,$id4);
check(password_verify($pin,daAccount($db,$aid)['password_hash']),'password reset persisted');
check(count($reports)===1&&str_contains($reports[0],$uname)&&str_contains($reports[0],'1001')&&str_contains($reports[0],'تاریخ و ساعت'),'password reset report includes username buyer and timestamp');
rejected(fn()=>daRealDeleteChallenge($db,1002,$id4),'cross-owner deletion blocked');
$del=daRealDeleteChallenge($db,1001,$id4);
rejected(fn()=>daRealDeleteService($db,1001,$id4,$del),'cannot skip first confirmation');
daRealDeleteAdvance($db,1001,$id4,$del);
rejected(fn()=>daRealDeleteService($db,1002,$id4,$del),'cannot delete another buyer service with token');
daRealDeleteCancel($db,1001,$id4,$del);
rejected(fn()=>daRealDeleteService($db,1001,$id4,$del),'cancel invalidates final confirmation');
$del=daRealDeleteChallenge($db,1001,$id4);daRealDeleteAdvance($db,1001,$id4,$del);
daExec($db,'UPDATE delta_app_delete_requests SET expires=? WHERE order_id=?','ii',time()-1,$id4);
rejected(fn()=>daRealDeleteService($db,1001,$id4,$del),'expired final confirmation blocked');
$del=daRealDeleteChallenge($db,1001,$id4);daRealDeleteAdvance($db,1001,$id4,$del);
rejected(fn()=>daRealDeleteService($db,1001,$id4,$del),'unavailable panel not reported as deleted');
check(daRealOrder($db,1001,$id4)['state']==='deleting'&&daAccount($db,$aid)!==null,'incomplete deletion remains recoverable');
rejected(fn()=>daRealResetPassword($db,1001,$id4),'deleting account cannot reset password');
rejected(fn()=>daRealDeleteService($db,1001,$id4,$del),'used final token cannot replay');
// Simulate local result of a verified remote deletion in this disposable fixture only.
daExec($db,'DELETE FROM delta_app_pg_users WHERE account_id=?','i',$aid);
daExec($db,'DELETE FROM delta_app_accounts WHERE id=?','i',$aid);
$del=daRealDeleteChallenge($db,1001,$id4);daRealDeleteAdvance($db,1001,$id4,$del);
daRealDeleteService($db,1001,$id4,$del);
check(daRealOrder($db,1001,$id4)['state']==='deleted','completed deletion retains order');
check(daRealPayByHash($db,$o4['pay_hash'])['state']==='paid_with_wallet','deletion retains payment record');
check(daRealWalletPreview($db,1001,0)['balance']===70000,'deletion does not refund or debit wallet');
rejected(fn()=>daRealDeliver($db,1001,$id4),'deleted paid service cannot reprovision');
// Simulate previous production integer schemas, keeping existing paid orders.
$db->query('ALTER TABLE delta_app_accounts MODIFY gb INT UNSIGNED NOT NULL');
$db->query('ALTER TABLE delta_app_real_orders MODIFY gb INT UNSIGNED NOT NULL');
$oldPaid=daRealOrder($db,1001,$id4);
daSchema($db);daRealSchema($db);
check(daQuotaLabel(daRealOrder($db,1001,$id4)['gb'])===daQuotaLabel($oldPaid['gb']),'integer paid order quota preserved by decimal schema migration');
check(daQuotaBytes('0.1')===107374182&&daQuotaBytes('0.2')===214748364&&daQuotaBytes('0.5')===536870912,'fractional quotas converted to exact whole panel bytes');
check(daQuotaBytes('10.000')===10737418240,'whole paid quota byte conversion unchanged');
check(daQuotaLabel('0.100')==='0.1'&&daQuotaLabel('10.000')==='10','clean quota display');
// Free trial: separate reservation ledger, not a zero-value payment.
$s=daTrialSettings($db);
check((int)$s['enabled']===0&&(int)$s['gb']===1&&(int)$s['days']===1&&(int)$s['devices']===1&&(int)$s['daily_cap']===30,'trial defaults disabled, 1 GB, 1 day, 1 device, 30 daily');
rejected(fn()=>daTrialReserve($db,1001,true),'disabled trial cannot reserve');
daTrialSet($db,'enabled',1);
rejected(fn()=>daTrialSet($db,'gb','0'),'zero trial quota rejected');
rejected(fn()=>daTrialSet($db,'gb','0.0001'),'unsupported precision rejected');
rejected(fn()=>daTrialSet($db,'gb','-0.1'),'negative quota rejected');
daTrialSet($db,'gb','0.1');
check(daQuotaLabel(daTrialSettings($db)['gb'])==='0.1','fractional trial setting persisted');
daTrialSet($db,'require_phone',1);
rejected(fn()=>daTrialReserve($db,1001,false),'phone requirement enforced');
daTrialSet($db,'daily_cap',1);
$np=(int)daRow($db,'SELECT COUNT(*) AS n FROM pays')['n'];
$t=daTrialReserve($db,1001,true);
$tr=daRealOrder($db,1001,$t);
check($tr['method']==='trial'&&(int)$tr['price']===0&&daQuotaLabel($tr['gb'])==='0.1'&&daTrialAuthorized($db,$tr),'trial ledger authorizes free account only');
check(daTrialReserve($db,1001,true)===$t,'repeat request reuses same trial');
check((int)daRow($db,'SELECT COUNT(*) AS n FROM delta_app_trial_claims')['n']===1,'one claim row per ordinary buyer');
rejected(fn()=>daTrialReserve($db,1002,true),'daily cap enforced');
rejected(fn()=>daRealDeliver($db,1002,$t),'cross-owner trial delivery rejected');
rejected(fn()=>daRealWallet($db,1001,$t),'trial cannot use wallet');
rejected(fn()=>daRealPrepareCard($db,1001,$t),'trial cannot use card');
rejected(fn()=>daTrialRelease($db,1001),'pending claim not released by admin');
check((int)daRow($db,'SELECT COUNT(*) AS n FROM pays')['n']===$np,'trial creates no financial payment');
check(daRealWalletPreview($db,1001,0)['balance']===70000,'trial leaves wallet unchanged');
daExec($db,"UPDATE delta_app_real_orders SET state='deleted' WHERE id=?",'i',$t);
check(daTrialReserve($db,1001,true)===$t,'deleted trial cannot be claimed again');
rejected(fn()=>daTrialDeliver($db,1001,$t),'deleted trial cannot reprovision');
daTrialRelease($db,1001);
check(daTrialCurrent($db,1001)===null&&!daTrialAuthorized($db,$tr),'explicit release preserves old claim but removes authorization');
rejected(fn()=>daTrialReserve($db,1001,true),'released claims still count toward daily cap');
daTrialSet($db,'daily_cap',2);
daTrialSet($db,'gb','0.2');
$t2=daTrialReserve($db,1001,true);
check(daQuotaLabel(daRealOrder($db,1001,$t2)['gb'])==='0.2'&&daQuotaLabel(daRealOrder($db,1001,$t)['gb'])==='0.1','changed setting affects only future claims');
check($t2!==$t&&(int)daRow($db,'SELECT COUNT(*) AS n FROM delta_app_trial_claims')['n']===2,'admin exception keeps full trial history');
daTrialSet($db,'enabled',0);
check(daTrialReserve($db,1001,true)===$t2,'disabled new claims still allow recovery of existing reservation');
// Simulate a confirmed remote account in the disposable DB only. Exercise
// shared completion and notification without calling an actual VPN panel.
$pg=daRow($db,'SELECT * FROM delta_app_pg_settings WHERE id=1');
$trialAid=daRealReserveAccount($db,1001,$t2,$pg);
$ta=daAccount($db,$trialAid);$trialUser=$ta['username'];
check(preg_match('/^testdelta[1-9][0-9]{4}$/D',$trialUser)===1,'generated trial username is testdelta plus five digits');
check(preg_match('/^delta[1-9][0-9]{4}$/D',daRealAccountName($db,false))===1,'paid username prefix unchanged');
check(daQuotaLabel($ta['gb'])==='0.2'&&daPgMap($db,$trialAid)['pg_name']===$trialUser,'fractional quota and username persisted in actual account reservation');
$metrics=daPgMetrics(array_replace($ta,['active'=>1]),['data_limit'=>daQuotaBytes('0.2'),'used_traffic'=>0,'expire'=>gmdate('c',(int)$ta['expires']),'status'=>'active','data_limit_reset_strategy'=>'no_reset']);
check($metrics['can_connect']&&$metrics['limit_bytes']===214748364,'strict panel metric validation accepts fractional quota');
// Simulate confirmed remote provisioning only; no actual panel is contacted.
daExec($db,"UPDATE delta_app_pg_users SET state='ready' WHERE account_id=?",'i',$trialAid);
daExec($db,'UPDATE delta_app_accounts SET active=1 WHERE id=?','i',$trialAid);
$messages=[];
function sendMessage($txt,$key=null,$parse=null,$buyer=null){$GLOBALS['messages'][]=[$txt,$buyer];return (object)['ok'=>true];}
$nReports=count($reports);
$res=daTrialDeliver($db,1001,$t2);
check($res['order']['state']==='delivered'&&password_verify($res['password'],daAccount($db,$trialAid)['password_hash']),'trial completes existing reserved account with usable local password');
check(count($messages)===1&&$messages[0][1]===1001&&str_contains($messages[0][0],$trialUser)&&str_contains($messages[0][0],'0.2'),'trial credentials sent only to owner');
check(count($reports)===$nReports+1&&str_contains(end($reports),$trialUser),'trial delivery admin report includes username');
daTrialDeliver($db,1001,$t2);
check(count($messages)===1&&count($reports)===$nReports+1,'repeat completion does not resend credentials or reports');
check(daTrialReserve($db,1001,true)===$t2,'delivered trial remains one-time');
rejected(fn()=>daTrialRelease($db,1001),'active delivered trial cannot be released');

rejected(fn()=>daTrialReserve($db,1002,true),'disabled trial blocks new users');

// Full renewals use the same existing account, a separate real invoice,
// and a fixed absolute remote target rather than resetting traffic twice.
$original=daAccount($db,$trialAid);$originalHash=$original['password_hash'];
$normal=daRealInvoice($db,1001,1);
$renew=daRealInvoice($db,1001,1,$t2);
check($normal!==$renew&&daRenewLink($db,$renew)['root_order_id']==$t2,'renewal and new purchase invoices are isolated');
check(daRealInvoice($db,1001,1,$t2)===$renew,'same account reuses open renewal invoice');
rejected(fn()=>daRealInvoice($db,1002,1,$t2),'cross-owner renewal invoice rejected');
daRealCancel($db,1001,$normal);
$ro=daRealOrder($db,1001,$renew);$rr=daRenewLink($db,$renew);
$remote=['used'=>1234567,'limit'=>daQuotaBytes('0.2'),'expires'=>(int)$original['expires']];
rejected(fn()=>daRenewStage($db,$ro,$rr,$remote),'unpaid renewal cannot stage a panel target');
$del=daRealDeleteChallenge($db,1001,$t2);daRealDeleteAdvance($db,1001,$t2,$del);
rejected(fn()=>daRealDeleteService($db,1001,$t2,$del),'open renewal blocks service deletion');
check(daAccount($db,$trialAid)!==null,'blocked deletion leaves trial account intact');
daRealWallet($db,1001,$renew);daRealWallet($db,1001,$renew);
check(daRealWalletPreview($db,1001,0)['balance']===40000,'renewal debits wallet exactly once');
$ro=daRealSyncPaid($db,$ro['pay_hash']);
rejected(fn()=>daRealDeliver($db,1001,$renew),'renewal cannot provision a new user');
$rr=daRenewStage($db,$ro,$rr,$remote);
check((int)$rr['baseline']===1234567&&(int)$rr['target_limit']===1234567+daQuotaBytes('10'),'renewal target replaces allowance while preserving raw panel counter');
$retry=daRenewStage($db,$ro,$rr,array_replace($remote,['used'=>1239999]));
check($retry===$rr,'retry keeps original absolute target');
rejected(fn()=>daRenewFinish($db,$ro,$rr,$remote),'cannot complete until remote target verified');
$confirmed=['used'=>1234567+777,'limit'=>(int)$rr['target_limit'],'expires'=>(int)$rr['target_expires']];
check(daRenewFinish($db,$ro,$rr,$confirmed),'verified renewal completed');
check(!daRenewFinish($db,$ro,$rr,$confirmed),'repeat completion does not extend twice');
$now=daAccount($db,$trialAid);
check($now['username']===$original['username']&&$now['password_hash']===$originalHash&&daQuotaLabel($now['gb'])==='10'&&(int)$now['expires']===(int)$rr['target_expires'],'renewal preserves username and password, updates existing allowance');
check(daTrialReserve($db,1001,true)===$t2,'renewal does not release free trial eligibility');
check(daRenewLatest($db,$t2)['id']==$renew,'renewed plan visible via original service');
$v=daPgMetrics($now,['data_limit'=>$confirmed['limit'],'used_traffic'=>$confirmed['used'],'expire'=>gmdate('c',$confirmed['expires']),'status'=>'active','data_limit_reset_strategy'=>'no_reset']);
check($v['limit_bytes']===daQuotaBytes('10')&&$v['used_bytes']===777&&$v['remaining_bytes']===daQuotaBytes('10')-777,'renewal exposes new-plan usage rather than lifetime usage');
// News publishing and per-account state. No Telegram or browser network calls.
$author=999;
function fixtureNews(mysqli $db,int $author,string $kind,string $body,string $url=''): int {
    $t=daNewsDraft($db,$author,$kind,$body);
    if($url!=='')daNewsDraftLink($db,$author,$t,$url,'Download');
    $id=daNewsPublish($db,$author,$t);
    rejected(fn()=>daNewsPublish($db,$author,$t),'publish confirmation cannot be replayed');
    return $id;
}
$n1=fixtureNews($db,$author,'inbox','News one');
$n2=fixtureNews($db,$author,'inbox','News two','https://example.invalid/update.apk');
$n3=fixtureNews($db,$author,'inbox','News three');
check(daNewsFeed($db,$trialAid)['unread']===3,'three announcements produce unread count three');
daNewsSeen($db,$trialAid,$n2,'inbox');daNewsSeen($db,$trialAid,$n2,'inbox');
check(daNewsFeed($db,$trialAid)['unread']===2&&daNewsFeed($db,99999)['unread']===3,'read status is idempotent and isolated per account');
$f1=fixtureNews($db,$author,'float','First floating');
check(daNewsFeed($db,$trialAid)['floating']['id']===$f1,'floating announcement delivered');
daNewsSeen($db,$trialAid,$f1,'float');
check(daNewsFeed($db,$trialAid)['floating']===null&&daNewsFeed($db,99999)['floating']['id']===$f1,'per-account permanent dismissal');
$f2=fixtureNews($db,$author,'float','Second floating','https://example.invalid/update.apk');
check(daNewsFeed($db,$trialAid)['floating']['id']===$f2,'new floating announcement overrides old dismissal');
check(daNewsFeed($db,$trialAid)['unread']===2,'floating items do not inflate bell count');
daNewsSeen($db,$trialAid,$f2,'inbox');
check(daNewsFeed($db,$trialAid)['floating']['id']===$f2,'wrong-kind acknowledgement ignored');
daNewsArchive($db,$n1,'inbox');
check(daNewsFeed($db,$trialAid)['unread']===1,'archiving unread item updates count');
rejected(fn()=>daNewsUrl('javascript:alert(1)'),'unsafe link schemes rejected');
rejected(fn()=>daNewsUrl('http://example.invalid/test'),'non-HTTPS link rejected');
rejected(fn()=>daNewsUrl('https://user:pass@example.invalid/test'),'URL credentials rejected');
echo "All isolated checkout fixture checks passed.\n";
