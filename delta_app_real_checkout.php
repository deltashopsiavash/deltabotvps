<?php
declare(strict_types=1);
require_once __DIR__.'/delta_app_core.php';

/** Real orders are isolated from old test rows. No test approval is accepted. */
function daRealSchema(mysqli $db): void {
    if(!$db->query("CREATE TABLE IF NOT EXISTS delta_app_real_orders (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      buyer BIGINT NOT NULL, plan_id INT UNSIGNED NOT NULL,
      name VARCHAR(80) NOT NULL, gb DECIMAL(13,3) UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL,
      price BIGINT UNSIGNED NOT NULL, device_limit INT UNSIGNED NOT NULL,
      pay_hash VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
      state VARCHAR(20) NOT NULL DEFAULT 'pending', method VARCHAR(16) NULL,
      account_id INT UNSIGNED NULL UNIQUE, created BIGINT UNSIGNED NOT NULL,
      approved_at BIGINT UNSIGNED NULL, delivered_at BIGINT UNSIGNED NULL,
      INDEX(buyer,id), INDEX(buyer,plan_id,state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"))throw new RuntimeException('real order schema');
    if(!$db->query("CREATE TABLE IF NOT EXISTS delta_app_delete_requests (
      order_id BIGINT UNSIGNED PRIMARY KEY,buyer BIGINT NOT NULL,account_id INT UNSIGNED NOT NULL,
      token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
      stage TINYINT UNSIGNED NOT NULL,expires BIGINT UNSIGNED NOT NULL
    ) ENGINE=InnoDB"))throw new RuntimeException('delete request schema');

    $db->query("CREATE TABLE IF NOT EXISTS delta_app_trial_settings (id TINYINT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0,gb DECIMAL(13,3) UNSIGNED NOT NULL DEFAULT 1,days INT UNSIGNED NOT NULL DEFAULT 1,devices INT UNSIGNED NOT NULL DEFAULT 1,daily_cap INT UNSIGNED NOT NULL DEFAULT 30,require_phone TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB");
    daEnsureDecimalQuota($db,'delta_app_real_orders');
    daEnsureDecimalQuota($db,'delta_app_trial_settings');
    $db->query("INSERT IGNORE INTO delta_app_trial_settings(id) VALUES(1)");
    $db->query("CREATE TABLE IF NOT EXISTS delta_app_trial_claims (order_id BIGINT UNSIGNED PRIMARY KEY,buyer BIGINT NOT NULL,claimed BIGINT UNSIGNED NOT NULL,released_at BIGINT UNSIGNED NULL,INDEX(buyer,released_at),INDEX(claimed)) ENGINE=InnoDB");
}
function daRealOrder(mysqli $db,int $buyer,int $id): array {
    $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=?','ii',$buyer,$id);
    if(!$o)throw new InvalidArgumentException('فاکتور پیدا نشد.');
    return $o;
}
function daRealInvoice(mysqli $db,int $buyer,int $plan): int {
    daRealSchema($db);
    daRealFinancePreflight($db);
    $db->begin_transaction();
    try{
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 AND category_id IS NOT NULL FOR UPDATE','i',$plan);
        if(!$p || (int)$p['price']<=0)throw new InvalidArgumentException('پلن پولی فعال یافت نشد.');
        $setting=daRow($db,"SELECT * FROM delta_app_pg_settings WHERE id=1 AND panel_kind='app'");
        if(!$setting)throw new InvalidArgumentException('ابتدا پنل برنامه را انتخاب کنید.');
        $old=daRow($db,"SELECT o.id FROM delta_app_real_orders o JOIN pays p ON BINARY p.hash_id=BINARY o.pay_hash AND p.user_id=o.buyer AND p.type='DELTA_APP_BUY' WHERE o.buyer=? AND o.plan_id=? AND o.state='pending' AND p.state='pending' AND o.created>=? ORDER BY o.id DESC LIMIT 1",'iii',$buyer,$plan,time()-3600);
        if($old){$db->commit();return (int)$old['id'];}
        $hash='';
        for($i=0;$i<12;$i++){
            $candidate='DA';$alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz123456789';
            for($j=0;$j<7;$j++)$candidate.=$alphabet[random_int(0,strlen($alphabet)-1)];
            if(!daRow($db,'SELECT id FROM pays WHERE hash_id=? LIMIT 1','s',$candidate)){$hash=$candidate;break;}
        }
        if($hash==='')throw new RuntimeException('invoice hash unavailable');
        $now=time();$price=(int)$p['price'];$gb=(int)$p['gb'];$days=(int)$p['days'];$devices=(int)$p['device_limit'];
        daExec($db,"INSERT INTO pays(hash_id,description,user_id,type,plan_id,volume,day,price,request_date,state) VALUES(?,?,?,'DELTA_APP_BUY',?,?,?,?,?,'pending')",'ssiiiiii',$hash,$p['name'],$buyer,$plan,$gb,$days,$price,$now);
        $pay=daRow($db,"SELECT type,price,user_id FROM pays WHERE hash_id=?",'s',$hash);
        if(!$pay || $pay['type']!=='DELTA_APP_BUY' || (int)$pay['price']!==$price || (int)$pay['user_id']!==$buyer)throw new RuntimeException('payment snapshot mismatch');
        daExec($db,'INSERT INTO delta_app_real_orders(buyer,plan_id,name,gb,days,price,device_limit,pay_hash,created) VALUES(?,?,?,?,?,?,?,?,?)','iisiiiisi',$buyer,$plan,$p['name'],$gb,$days,$price,$devices,$hash,$now);
        $id=(int)$db->insert_id;$db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealWalletAvailable(mysqli $db): bool {
    $r=daRow($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'");
    return strtolower((string)($r['ENGINE']??''))==='innodb';
}
function daRealWallet(mysqli $db,int $buyer,int $id): void {
    daRealFinancePreflight($db);
    if(!daRealWalletAvailable($db))throw new InvalidArgumentException('پرداخت از موجودی پس از ارتقای ایمن جدول کاربران فعال می‌شود.');
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=? FOR UPDATE','ii',$buyer,$id);
        if(!$o)throw new InvalidArgumentException('فاکتور یافت نشد.');
        $p=daRow($db,"SELECT * FROM pays WHERE BINARY hash_id=BINARY ? AND user_id=? AND type='DELTA_APP_BUY' FOR UPDATE",'si',$o['pay_hash'],$buyer);
        if(!$p||(int)$p['price']!==(int)$o['price'])throw new RuntimeException('payment mismatch');
        if($o['method']==='wallet'&&$p['state']==='paid_with_wallet'&&in_array($o['state'],['paid','provisioning','delivered'],true)){$db->commit();return;}
        if($o['state']!=='pending'||$p['state']!=='pending'||$o['method']!==null||daRealHasCard($db,$o['pay_hash'])||(int)$o['created']<time()-3600||(int)$o['price']<=0)throw new InvalidArgumentException('فاکتور قابل پرداخت از موجودی نیست.');
        $price=(int)$o['price'];
        $s=daQuery($db,'UPDATE users SET wallet=wallet-? WHERE userid=? AND wallet>=?','iii',$price,$buyer,$price);
        $ok=$s->affected_rows===1;$s->close();
        if(!$ok)throw new InvalidArgumentException('موجودی کیف پول کافی نیست.');
        $s=daQuery($db,"UPDATE pays SET state='paid_with_wallet' WHERE id=? AND state='pending'",'i',(int)$p['id']);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new RuntimeException('payment claim');
        daExec($db,"UPDATE delta_app_real_orders SET state='paid',method='wallet',approved_at=? WHERE id=?",'ii',time(),$id);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealPaymentSettled(array $o,array $p): bool {
    return ($o['method']==='card'&&in_array($p['state'],['paid','approved'],true))||($o['method']==='wallet'&&$p['state']==='paid_with_wallet');
}
function daRealCancel(mysqli $db,int $buyer,int $id): void {
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=? FOR UPDATE','ii',$buyer,$id);
        if(!$o||$o['state']!=='pending')throw new InvalidArgumentException('فاکتور قابل لغو نیست.');
        if($o['method']==='card'||daRealHasCard($db,(string)$o['pay_hash']))throw new InvalidArgumentException('شماره کارت صادر شده؛ ابتدا وضعیت واریز توسط مدیریت بررسی شود.');
        $s=daQuery($db,"UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' AND state='pending'",'si',$o['pay_hash'],$buyer);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new InvalidArgumentException('وضعیت پرداخت تغییر کرده است.');
        daExec($db,"UPDATE delta_app_real_orders SET state='cancelled' WHERE id=?",'i',$id);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function daTrialSettings(mysqli $db): array {
    $s=daRow($db,'SELECT * FROM delta_app_trial_settings WHERE id=1');
    if(!$s)throw new RuntimeException('trial settings unavailable');
    return $s;
}
function daTrialDayStart(): int {
    return (new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->getTimestamp();
}
function daTrialSet(mysqli $db,string $field,int|string $value): void {
    if($field==='gb'){
        $v=(string)$value;
        if(!preg_match('/^\d{1,3}(?:\.\d{1,3})?$/D',$v)||(float)$v<0.001||(float)$v>100)throw new InvalidArgumentException('حجم باید بین ۰٫۰۰۱ و ۱۰۰ گیگ باشد؛ مثل 0.1 یا 0.25.');
        daQuotaBytes($v);
        daExec($db,'UPDATE delta_app_trial_settings SET gb=? WHERE id=1','s',$v);
        return;
    }
    $ranges=['enabled'=>[0,1],'days'=>[1,30],'devices'=>[1,10],'daily_cap'=>[1,1000],'require_phone'=>[0,1]];
    if(!isset($ranges[$field])||!preg_match('/^\d{1,4}$/D',(string)$value)||(int)$value<$ranges[$field][0]||(int)$value>$ranges[$field][1])throw new InvalidArgumentException('مقدار نامعتبر است.');
    daExec($db,'UPDATE delta_app_trial_settings SET '.$field.'=? WHERE id=1','i',(int)$value);
}
function daRealAccountName(mysqli $db,bool $trial): string {
    $prefix=$trial?'testdelta':'delta';
    for($i=0;$i<40;$i++){
        $name=$prefix.random_int(10000,99999);
        if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$name)&&!daRow($db,'SELECT account_id FROM delta_app_pg_users WHERE pg_name=?','s',$name))return $name;
    }
    throw new RuntimeException('name');
}
function daTrialCurrent(mysqli $db,int $buyer): ?array {
    return daRow($db,"SELECT o.* FROM delta_app_trial_claims c JOIN delta_app_real_orders o ON o.id=c.order_id AND o.buyer=c.buyer WHERE c.buyer=? AND c.released_at IS NULL AND o.method='trial' ORDER BY c.order_id DESC LIMIT 1",'i',$buyer);
}
function daTrialReserve(mysqli $db,int $buyer,bool $phone): int {
    daRealSchema($db);
    foreach(['delta_app_trial_settings','delta_app_trial_claims','delta_app_real_orders'] as $table){
        $r=daRow($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",'s',$table);
        if(strtolower((string)($r['ENGINE']??''))!=='innodb')throw new RuntimeException('trial storage unavailable');
    }
    $db->begin_transaction();
    try{
        // Single settings-row lock serializes new reservations and admin release.
        $s=daRow($db,'SELECT * FROM delta_app_trial_settings WHERE id=1 FOR UPDATE');
        if(!$s)throw new RuntimeException('trial settings unavailable');
        $old=daTrialCurrent($db,$buyer);
        if($old){$db->commit();return (int)$old['id'];}
        if(!(int)$s['enabled'])throw new InvalidArgumentException('دریافت اشتراک تست غیرفعال است.');
        if((int)$s['require_phone']&&!$phone)throw new InvalidArgumentException('برای دریافت تست ابتدا شماره تلفن خود را در ربات ثبت کنید.');
        $count=daRow($db,'SELECT COUNT(*) AS n FROM delta_app_trial_claims WHERE claimed>=?','i',daTrialDayStart());
        if((int)$count['n']>=(int)$s['daily_cap'])throw new InvalidArgumentException('ظرفیت امروز تکمیل شده است.');
        $pg=daRow($db,"SELECT * FROM delta_app_pg_settings WHERE id=1 AND panel_kind='app'");
        if(!$pg)throw new InvalidArgumentException('پنل اختصاصی برنامه تنظیم نشده است.');
        $now=time();$hash='TR'.bin2hex(random_bytes(12));
        daExec($db,"INSERT INTO delta_app_real_orders(buyer,plan_id,name,gb,days,price,device_limit,pay_hash,state,method,created,approved_at) VALUES(?,0,'اشتراک تست رایگان',?,?,0,?,?,'paid','trial',?,?)",'isiisii',$buyer,(string)$s['gb'],(int)$s['days'],(int)$s['devices'],$hash,$now,$now);
        $id=(int)$db->insert_id;
        daExec($db,'INSERT INTO delta_app_trial_claims(order_id,buyer,claimed) VALUES(?,?,?)','iii',$id,$buyer,$now);
        $db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daTrialRelease(mysqli $db,int $buyer): void {
    $db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_trial_settings WHERE id=1 FOR UPDATE');
        $o=daTrialCurrent($db,$buyer);
        if(!$o)throw new InvalidArgumentException('سابقه تست فعال برای این کاربر پیدا نشد.');
        $a=$o['account_id']===null?null:daAccount($db,(int)$o['account_id']);
        // Explicit admin exception only after the previous service has ended.
        if(!($o['state']==='deleted'||($o['state']==='delivered'&&$a&&(int)$a['expires']<=time())))throw new InvalidArgumentException('ابتدا سرویس قبلی باید حذف یا منقضی شود.');
        daExec($db,'UPDATE delta_app_trial_claims SET released_at=? WHERE order_id=? AND released_at IS NULL','ii',time(),(int)$o['id']);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daTrialAuthorized(mysqli $db,array $o): bool {
    return $o['method']==='trial'&&(int)$o['price']===0&&(int)$o['plan_id']===0&&
        (bool)daRow($db,'SELECT order_id FROM delta_app_trial_claims WHERE order_id=? AND buyer=? AND released_at IS NULL','ii',(int)$o['id'],(int)$o['buyer']);
}
function daTrialDeliver(mysqli $db,int $buyer,int $id): array {
    $o=daRealOrder($db,$buyer,$id);
    if(!daTrialAuthorized($db,$o))throw new InvalidArgumentException('درخواست تست معتبر نیست.');
    $r=daRealDeliver($db,$buyer,$id);
    $a=daAccount($db,(int)$r['order']['account_id']);
    if(!$a||$r['order']['state']!=='delivered')throw new RuntimeException('trial delivery pending');
    if($r['password']!==null){
        $name=htmlspecialchars($a['username'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $pass=htmlspecialchars($r['password'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $o=$r['order'];
        sendMessage("🎁 اشتراک تست دلتا فعال شد\nنام کاربری: <code>".$name."</code>\nرمز: <code>".$pass."</code>\nحجم: ".daQuotaLabel($o['gb'])." گیگ\nاعتبار: ".$o['days']." روز\nدستگاه: ".$o['device_limit'],null,'HTML',$buyer);
        try{sendToAdmins("🎁 دریافت اشتراک تست برنامه دلتا\n".deltaUserShortInfo($buyer)."\n👤 نام کاربری برنامه: <code>".$name."</code>\nحجم: ".daQuotaLabel($o['gb'])." گیگ\nاعتبار: ".$o['days']." روز\nدستگاه: ".$o['device_limit']."\n📅 ".daIranDate(time())." (ایران)",null,'HTML');}catch(Throwable $e){error_log('Delta trial report failed');}
    }
    return $r;
}
function daRealDeliver(mysqli $db,int $buyer,int $id): array {
    $lock='delta-real-order-'.$id;$l=daRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',$lock);
    if((int)($l['acquired']??0)!==1)throw new InvalidArgumentException('فاکتور در حال پردازش است.');
    try{
        $o=daRealOrder($db,$buyer,$id);
        $pay=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY'",'si',$o['pay_hash'],$buyer);
        if($o['method']==='trial'){
            if(!daTrialAuthorized($db,$o))throw new InvalidArgumentException('درخواست تست معتبر نیست.');
        }elseif(!$pay || (int)$pay['price']!==(int)$o['price'] || !daRealPaymentSettled($o,$pay))throw new InvalidArgumentException('پرداخت واقعی تأیید نشده است.');
        if($o['state']==='delivered')return ['order'=>$o,'password'=>null];
        if(!in_array($o['state'],['paid','provisioning'],true))throw new InvalidArgumentException('فاکتور قابل تأیید نیست.');
        if($o['account_id']===null){
            $setting=daRow($db,'SELECT * FROM delta_app_pg_settings WHERE id=1');
            if(!$setting||$setting['panel_kind']!=='app')throw new InvalidArgumentException('ابتدا پنل برنامه را انتخاب کنید.');
            daPgServer($db,(int)$setting['server_id'],$setting['panel_base']);
            $db->begin_transaction();
            try{
                $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE id=? AND buyer=? FOR UPDATE','ii',$id,$buyer);
                if(!$o||$o['state']!=='paid'||$o['account_id']!==null)throw new InvalidArgumentException('فاکتور تغییر کرده است.');
                $name=daRealAccountName($db,$o['method']==='trial');
                $pass=daPassword();$now=time();$expires=$now+(int)$o['days']*86400;
                daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(?,?,?,?,?,0,?,?)','ssisiii',$name,password_hash($pass,PASSWORD_DEFAULT),(int)$o['plan_id'],(string)$o['gb'],$expires,$now,(int)$o['device_limit']);
                $aid=(int)$db->insert_id;
                daExec($db,"INSERT INTO delta_app_pg_users(account_id,server_id,group_id,panel_base,pg_name,marker,panel_kind) VALUES(?,?,?,?,?,?,'app')",'iiisss',$aid,(int)$setting['server_id'],(int)$setting['group_id'],$setting['panel_base'],$name,'delta-app:'.bin2hex(random_bytes(16)));
                daExec($db,"UPDATE delta_app_real_orders SET account_id=?,state='provisioning',approved_at=? WHERE id=?",'iii',$aid,$now,$id);
                $db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}
        }
        $o=daRealOrder($db,$buyer,$id);$aid=(int)$o['account_id'];$map=daPgMap($db,$aid);
        if(!$map||!daAccount($db,$aid))throw new RuntimeException('account missing');
        if($map['state']!=='ready')daPgProvision($db,$aid);
        // Retry safely reuses the reserved account; fresh PIN only before completion.
        $pass=daPassword();
        $db->begin_transaction();
        try{
            daExec($db,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($pass,PASSWORD_DEFAULT),$aid);
            daExec($db,"UPDATE delta_app_real_orders SET state='delivered',delivered_at=? WHERE id=? AND state='provisioning'",'ii',time(),$id);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        return ['order'=>daRealOrder($db,$buyer,$id),'password'=>$pass];
    }finally{daRow($db,'SELECT RELEASE_LOCK(?) AS released','s',$lock);}
}

function daRealResetPassword(mysqli $db,int $buyer,int $order): string {
    $o=daRealOrder($db,$buyer,$order);
    if($o['state']!=='delivered'||!daAccount($db,(int)$o['account_id']))throw new InvalidArgumentException('حساب آماده نیست.');
    $id=(int)$o['account_id'];
    return daPgLocked($db,$id,function()use($db,$buyer,$order,$id){
        $p=daPassword();$db->begin_transaction();
        try{
            $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE id=? AND buyer=? FOR UPDATE','ii',$order,$buyer);
            if(!$o||$o['state']!=='delivered'||(int)$o['account_id']!==$id)throw new InvalidArgumentException('حساب آماده نیست.');
            $a=daRow($db,'SELECT id,username FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id);
            if(!$a)throw new InvalidArgumentException('حساب یافت نشد.');
            daExec($db,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($p,PASSWORD_DEFAULT),$id);
            daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);
            daExec($db,'DELETE FROM delta_app_devices WHERE account_id=?','i',$id);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        try{
            $name=htmlspecialchars($a['username'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            sendToAdmins("🔐 گزارش تغییر رمز برنامه دلتا\n".deltaUserShortInfo($buyer)."\n👤 نام کاربری برنامه: <code>".$name."</code>\n📅 تاریخ و ساعت: ".daIranDate(time())." (ایران)\n".deltaTrackingLine($o['pay_hash']),null,'HTML');
        }catch(Throwable $e){error_log('Delta password change report failed');}
        return $p;
    });
}
function daRealWalletPreview(mysqli $db,int $buyer,int $price): array {
    $r=daRow($db,'SELECT wallet FROM users WHERE userid=? LIMIT 1','i',$buyer);
    if(!$r)throw new InvalidArgumentException('کاربر پیدا نشد.');
    $balance=(int)$r['wallet'];
    return ['balance'=>$balance,'after'=>$balance-$price];
}
function daRealDeleteChallenge(mysqli $db,int $buyer,int $order): string {
    $o=daRealOrder($db,$buyer,$order);
    if(!in_array($o['state'],['delivered','deleting'],true)||!(int)$o['account_id'])throw new InvalidArgumentException('سرویس قابل حذف نیست.');
    if($o['state']==='delivered'&&!daAccount($db,(int)$o['account_id']))throw new InvalidArgumentException('حساب یافت نشد.');
    $token=bin2hex(random_bytes(12));
    daExec($db,'INSERT INTO delta_app_delete_requests(order_id,buyer,account_id,token_hash,stage,expires) VALUES(?,?,?,?,1,?) ON DUPLICATE KEY UPDATE buyer=VALUES(buyer),account_id=VALUES(account_id),token_hash=VALUES(token_hash),stage=1,expires=VALUES(expires)','iiisi',$order,$buyer,(int)$o['account_id'],hash('sha256',$token),time()+300);
    return $token;
}
function daRealDeleteAdvance(mysqli $db,int $buyer,int $order,string $token): void {
    $o=daRealOrder($db,$buyer,$order);
    if(!in_array($o['state'],['delivered','deleting'],true))throw new InvalidArgumentException('سرویس قابل حذف نیست.');
    $s=daQuery($db,'UPDATE delta_app_delete_requests SET stage=2 WHERE order_id=? AND buyer=? AND account_id=? AND token_hash=? AND stage=1 AND expires>=?','iiisi',$order,$buyer,(int)$o['account_id'],hash('sha256',$token),time());
    $ok=$s->affected_rows===1;$s->close();
    if(!$ok)throw new InvalidArgumentException('تأیید منقضی یا نامعتبر است؛ از ابتدا اقدام کنید.');
}
function daRealDeleteCancel(mysqli $db,int $buyer,int $order,string $token): void {
    daRealOrder($db,$buyer,$order);
    daExec($db,'DELETE FROM delta_app_delete_requests WHERE order_id=? AND buyer=? AND token_hash=?','iis',$order,$buyer,hash('sha256',$token));
}
function daRealDeleteService(mysqli $db,int $buyer,int $order,string $token): void {
    $o=daRealOrder($db,$buyer,$order);$id=(int)$o['account_id'];
    if(!$id)throw new InvalidArgumentException('حساب یافت نشد.');
    daPgLocked($db,$id,function()use($db,$buyer,$order,$token,$id){
        $db->begin_transaction();
        try{
            $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE id=? AND buyer=? FOR UPDATE','ii',$order,$buyer);
            if(!$o||!in_array($o['state'],['delivered','deleting'],true)||(int)$o['account_id']!==$id)throw new InvalidArgumentException('سرویس قابل حذف نیست.');
            $s=daQuery($db,'DELETE FROM delta_app_delete_requests WHERE order_id=? AND buyer=? AND account_id=? AND token_hash=? AND stage=2 AND expires>=?','iiisi',$order,$buyer,$id,hash('sha256',$token),time());
            $ok=$s->affected_rows===1;$s->close();
            if(!$ok)throw new InvalidArgumentException('تأیید نهایی معتبر نیست؛ دوباره از ابتدا اقدام کنید.');
            $a=daAccount($db,$id);
            if(!$a&&$o['state']!=='deleting')throw new InvalidArgumentException('حساب یافت نشد.');
            $map=daPgMap($db,$id);
            if(($a&&(!$map||$map['panel_kind']!=='app'))||(!$a&&$map))throw new RuntimeException('account mapping requires admin review');

            daExec($db,"UPDATE delta_app_real_orders SET state='deleting' WHERE id=?",'i',$order);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        // On a network failure leave the order in deleting, never recreate or refund.
        if($a)daPgChangeLocked($db,$id,'delete');
        $db->begin_transaction();
        try{
            daExec($db,'DELETE FROM delta_app_devices WHERE account_id=?','i',$id);
            daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);
            daExec($db,"UPDATE delta_app_real_orders SET state='deleted' WHERE id=? AND buyer=? AND state='deleting'",'ii',$order,$buyer);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        try{
            sendToAdmins("🗑 حذف حساب برنامه دلتا توسط مشتری\n".deltaUserShortInfo($buyer)."\n👤 نام کاربری برنامه: ".htmlspecialchars($a['username']??'حساب حذف‌شده',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."\n📅 تاریخ و ساعت: ".daIranDate(time())." (ایران)\n".deltaTrackingLine($o['pay_hash']),null,'HTML');
        }catch(Throwable $e){error_log('Delta delete report failed');}
    });
}

function daRealHasCard(mysqli $db,string $hash): bool {
    if(!daRow($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_card_displays'"))return false;
    return (bool)daRow($db,'SELECT id FROM payment_card_displays WHERE hash_id=? LIMIT 1','s',$hash);
}
function daRealPayByHash(mysqli $db,string $hash): ?array {
    return daRow($db,"SELECT * FROM pays WHERE BINARY hash_id=BINARY ? AND type='DELTA_APP_BUY' LIMIT 1",'s',$hash);
}
/** Mark the independently claimed financial payment on the corresponding order. */
function daRealSyncPaid(mysqli $db,string $hash): array {
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE pay_hash=? FOR UPDATE','s',$hash);
        if(!$o)throw new RuntimeException('order missing');
        $p=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND type='DELTA_APP_BUY' FOR UPDATE",'s',$hash);
        if(!$p || (int)$p['user_id']!==(int)$o['buyer'] || (int)$p['price']!==(int)$o['price'] || !daRealPaymentSettled($o,$p))throw new RuntimeException('payment not settled');
        if($o['state']==='pending'){
            if(!daRealPaymentSettled($o,$p))throw new RuntimeException('payment method mismatch');
            daExec($db,"UPDATE delta_app_real_orders SET state='paid',method=?,approved_at=? WHERE id=? AND state='pending'",'sii',$o['method'],time(),(int)$o['id']);
        }elseif(!in_array($o['state'],['paid','provisioning','delivered'],true))throw new RuntimeException('order state mismatch');
        $db->commit();return daRealOrder($db,(int)$o['buyer'],(int)$o['id']);
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealRegisterReceipt(mysqli $db,string $hash,int $buyer,string $kind,int $at): bool {
    daRealFinancePreflight($db);
    if(!in_array($kind,['photo','text'],true)||$at<=0||$at>time())return false;
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE pay_hash=? AND buyer=? FOR UPDATE','si',$hash,$buyer);
        $p=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' FOR UPDATE",'si',$hash,$buyer);
        if(!$o||!$p||$o['method']!=='card'||$o['state']!=='pending'||$p['state']!=='pending'||(int)$p['price']!==(int)$o['price'])throw new RuntimeException('receipt state');
        if(!daRealHasCard($db,$hash))throw new RuntimeException('no card');
        if(!upsertSettingValue('RECEIPT_KIND_'.sha1($hash),$kind)||!deltaMarkReceiptSubmitted($hash,$at))throw new RuntimeException('receipt metadata');
        $s=daQuery($db,"UPDATE pays SET state='have_sent' WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' AND state='pending'",'si',$hash,$buyer);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new RuntimeException('receipt claim');
        $db->commit();return true;
    }catch(Throwable $e){$db->rollback();return false;}
}
/** Recoverable delivery: password may be reset in the owner menu if Telegram fails. */
function daRealDeliverPaidHash(mysqli $db,string $hash): array {
    $o=daRealSyncPaid($db,$hash);
    $r=daRealDeliver($db,(int)$o['buyer'],(int)$o['id']);
    $o=$r['order'];$a=daAccount($db,(int)$o['account_id']);
    if(!$a||$o['state']!=='delivered')throw new RuntimeException('delivery not ready');
    $esc=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $msg="✅ اشتراک دلتا تحویل شد\nپلن: ".$esc($o['name'])."\nکد فاکتور: <code>".$esc($hash)."</code>\nنام کاربری: <code>".$esc($a['username'])."</code>";
    if($r['password']!==null)$msg.="\nرمز: <code>".$esc($r['password'])."</code>";
    else $msg.="\nدر صورت نیاز، رمز را از سرویس‌های من تغییر دهید.";
    $msg.="\nمبلغ: ".number_format((int)$o['price'])." تومان";
    $sent=sendMessage($msg,null,'HTML',(int)$o['buyer']);
    if($r['password']!==null){
        sendToAdmins('✅ خرید موفق برنامه دلتا'."\n".deltaUserShortInfo((int)$o['buyer'])."\n👤 نام کاربری برنامه: <code>".$esc($a['username'])."</code>\n📦 پلن: ".$esc($o['name'])."\n💰 مبلغ: ".number_format((int)$o['price'])." تومان\n💳 روش پرداخت: ".($o['method']==='wallet'?'موجودی':'کارت به کارت')."\n".deltaTrackingLine($hash),null,'HTML');
    }
    return ['order'=>$o,'notified'=>is_object($sent)&&!empty($sent->ok)];
}

function daRealPrepareCard(mysqli $db,int $buyer,int $id): array {
    daRealFinancePreflight($db);
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=? FOR UPDATE','ii',$buyer,$id);
        if(!$o||$o['state']!=='pending'||!in_array($o['method'],[null,'card'],true)||(int)$o['created']<time()-3600)throw new InvalidArgumentException('فاکتور قابل پرداخت نیست.');
        $p=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' FOR UPDATE",'si',$o['pay_hash'],$buyer);
        if(!$p||$p['state']!=='pending'||(int)$p['price']!==(int)$o['price'])throw new InvalidArgumentException('وضعیت پرداخت تغییر کرده است.');
        daExec($db,"UPDATE delta_app_real_orders SET method='card' WHERE id=?",'i',$id);
        $db->commit();return $p;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealManualClaim(mysqli $db,string $hash): void {
    global $botState;
    daRealFinancePreflight($db);
    $p=daRealPayByHash($db,$hash);
    if(!$p)throw new RuntimeException('invoice missing');
    $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE pay_hash=? AND buyer=?','si',$hash,(int)$p['user_id']);
    if(!$o||$o['method']!=='card'||(int)$o['price']!==(int)$p['price'])throw new RuntimeException('order payment mismatch');
    if(in_array($p['state'],['paid','approved'],true))return;
    if(!in_array($p['state'],['have_sent','need_admin'],true)||!deltaReceiptHasSubmissionMarker($hash))throw new InvalidArgumentException('ابتدا رسید معتبر ثبت شود.');
    // The same single-click manual approval as BUY_SUB; the administrator
    // is responsible for verifying the actual bank transfer before clicking.
    // Prefer an atomic SMS event claim whenever one is uniquely available.
    if(deltaReceiptApprovalMode($botState)==='sms'){
        $binding=deltaSmsReviewQueuedBinding($p)??deltaSmsFindBinding($p,deltaReceiptSubmittedAt($hash,0));
        if($binding&&deltaSmsClaimInvoice($p,$binding,'sms'))return;
    }
    // Serialize with the shared SMS claim. A manually approved but unbound
    // invoice remains a competitor in the exact-price matching algorithm.
    $db->begin_transaction();
    try{
        daRow($db,"SELECT id FROM setting WHERE type='BOT_STATES' LIMIT 1 FOR UPDATE");
        $s=daQuery($db,"UPDATE pays SET state='approved' WHERE id=? AND BINARY hash_id=BINARY ? AND type='DELTA_APP_BUY' AND state IN ('have_sent','need_admin')",'is',(int)$p['id'],$hash);
        $ok=$s->affected_rows===1;$s->close();
        if(!$ok){
            $fresh=daRealPayByHash($db,$hash);
            if(!$fresh||!in_array($fresh['state'],['paid','approved'],true))throw new InvalidArgumentException('رسید قبلاً بررسی شده است.');
        }
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    if(function_exists('deltaSmsReconcileSettled')){
        try{deltaSmsReconcileSettled();}catch(Throwable $e){error_log('Delta manual SMS reconciliation pending');}
    }
}

/** Fail closed before any financial write on a nontransactional or incompatible schema. */
function daRealFinancePreflight(mysqli $db): void {
    foreach(['pays','setting','delta_app_plans','delta_app_real_orders','delta_app_accounts','delta_app_pg_users'] as $table){
        $r=daRow($db,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",'s',$table);
        if(!$r||strtolower((string)$r['ENGINE'])!=='innodb')throw new RuntimeException('transactional table required: '.$table);
    }
    $r=daRow($db,"SELECT DATA_TYPE,COLUMN_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pays' AND COLUMN_NAME='type'");
    if(!$r||(!in_array($r['DATA_TYPE'],['enum','set'],true)&&(int)$r['CHARACTER_MAXIMUM_LENGTH']<strlen('DELTA_APP_BUY'))||
        (in_array($r['DATA_TYPE'],['enum','set'],true)&&!str_contains($r['COLUMN_TYPE'],"'DELTA_APP_BUY'")))throw new RuntimeException('pays.type cannot store DELTA_APP_BUY');
    $r=daRow($db,"SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pays' AND COLUMN_NAME='hash_id'");
    if(!$r||(int)$r['CHARACTER_MAXIMUM_LENGTH']<9)throw new RuntimeException('pays.hash_id incompatible');
}

function daRealReject(mysqli $db,string $hash): array {
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE pay_hash=? FOR UPDATE','s',$hash);
        if(!$o||$o['state']!=='pending'||$o['method']!=='card')throw new InvalidArgumentException('فاکتور قبلاً بررسی شده است.');
        $s=daQuery($db,"UPDATE pays SET state='declined' WHERE BINARY hash_id=BINARY ? AND user_id=? AND type='DELTA_APP_BUY' AND state IN ('have_sent','need_admin')",'si',$hash,(int)$o['buyer']);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new InvalidArgumentException('رسید قبلاً بررسی شده است.');
        daExec($db,"UPDATE delta_app_real_orders SET state='cancelled' WHERE id=?",'i',(int)$o['id']);
        $db->commit();return $o;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
