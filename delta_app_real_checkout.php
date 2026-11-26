<?php
declare(strict_types=1);
require_once __DIR__.'/delta_app_core.php';

/** Real orders are isolated from old test rows. No test approval is accepted. */
function daRealSchema(mysqli $db): void {
    if(!$db->query("CREATE TABLE IF NOT EXISTS delta_app_real_orders (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      buyer BIGINT NOT NULL, plan_id INT UNSIGNED NOT NULL,
      name VARCHAR(80) NOT NULL, gb INT UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL,
      price BIGINT UNSIGNED NOT NULL, device_limit INT UNSIGNED NOT NULL,
      pay_hash VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
      state VARCHAR(20) NOT NULL DEFAULT 'pending', method VARCHAR(16) NULL,
      account_id INT UNSIGNED NULL UNIQUE, created BIGINT UNSIGNED NOT NULL,
      approved_at BIGINT UNSIGNED NULL, delivered_at BIGINT UNSIGNED NULL,
      INDEX(buyer,id), INDEX(buyer,plan_id,state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"))throw new RuntimeException('real order schema');
}
function daRealOrder(mysqli $db,int $buyer,int $id): array {
    $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=?','ii',$buyer,$id);
    if(!$o)throw new InvalidArgumentException('فاکتور پیدا نشد.');
    return $o;
}
function daRealInvoice(mysqli $db,int $buyer,int $plan): int {
    daRealSchema($db);
    $db->begin_transaction();
    try{
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 AND category_id IS NOT NULL FOR UPDATE','i',$plan);
        if(!$p || (int)$p['price']<=0)throw new InvalidArgumentException('پلن پولی فعال یافت نشد.');
        $setting=daRow($db,"SELECT * FROM delta_app_pg_settings WHERE id=1 AND panel_kind='app'");
        if(!$setting)throw new InvalidArgumentException('ابتدا پنل برنامه را انتخاب کنید.');
        $old=daRow($db,"SELECT o.id FROM delta_app_real_orders o JOIN pays p ON p.hash_id=o.pay_hash AND p.user_id=o.buyer AND p.type='DELTA_APP_BUY' WHERE o.buyer=? AND o.plan_id=? AND o.state='pending' AND p.state='pending' AND o.created>=? ORDER BY o.id DESC LIMIT 1",'iii',$buyer,$plan,time()-3600);
        if($old){$db->commit();return (int)$old['id'];}
        $hash=bin2hex(random_bytes(9));$now=time();$price=(int)$p['price'];$gb=(int)$p['gb'];$days=(int)$p['days'];$devices=(int)$p['device_limit'];
        daExec($db,"INSERT INTO pays(hash_id,description,user_id,type,plan_id,volume,day,price,request_date,state) VALUES(?,?,?,'DELTA_APP_BUY',?,?,?,?,?,'pending')",'ssiiiiii',$hash,$p['name'],$buyer,$plan,$gb,$days,$price,$now);
        $pay=daRow($db,"SELECT type,price,user_id FROM pays WHERE hash_id=?",'s',$hash);
        if(!$pay || $pay['type']!=='DELTA_APP_BUY' || (int)$pay['price']!==$price || (int)$pay['user_id']!==$buyer)throw new RuntimeException('payment snapshot mismatch');
        daExec($db,'INSERT INTO delta_app_real_orders(buyer,plan_id,name,gb,days,price,device_limit,pay_hash,created) VALUES(?,?,?,?,?,?,?,?,?)','iisiiiisi',$buyer,$plan,$p['name'],$gb,$days,$price,$devices,$hash,$now);
        $id=(int)$db->insert_id;$db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealWallet(mysqli $db,int $buyer,int $id): void {
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=? FOR UPDATE','ii',$buyer,$id);
        if(!$o)throw new InvalidArgumentException('فاکتور یافت نشد.');
        $p=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' FOR UPDATE",'si',$o['pay_hash'],$buyer);
        if(!$p || (int)$p['price']!==(int)$o['price'])throw new RuntimeException('payment mismatch');
        if(in_array($o['state'],['paid','provisioning','delivered'],true)&&$p['state']==='paid_with_wallet'){$db->commit();return;}
        if($o['state']!=='pending'||$p['state']!=='pending'||(int)$o['created']<time()-3600||(int)$o['price']<=0)throw new InvalidArgumentException('فاکتور قابل پرداخت نیست.');
        $price=(int)$o['price'];
        $s=daQuery($db,'UPDATE users SET wallet=wallet-? WHERE userid=? AND wallet>=?','iii',$price,$buyer,$price);
        $ok=$s->affected_rows===1;$s->close();
        if(!$ok)throw new InvalidArgumentException('موجودی کیف پول کافی نیست.');
        $s=daQuery($db,"UPDATE pays SET state='paid_with_wallet' WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' AND state='pending'",'si',$o['pay_hash'],$buyer);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new RuntimeException('payment claim');
        daExec($db,"UPDATE delta_app_real_orders SET state='paid',method='wallet',approved_at=? WHERE id=?",'ii',time(),$id);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daRealCancel(mysqli $db,int $buyer,int $id): void {
    $db->begin_transaction();
    try{
        $o=daRow($db,'SELECT * FROM delta_app_real_orders WHERE buyer=? AND id=? FOR UPDATE','ii',$buyer,$id);
        if(!$o||$o['state']!=='pending')throw new InvalidArgumentException('فاکتور قابل لغو نیست.');
        $s=daQuery($db,"UPDATE pays SET state='cancelled_by_user' WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY' AND state='pending'",'si',$o['pay_hash'],$buyer);
        $ok=$s->affected_rows===1;$s->close();if(!$ok)throw new InvalidArgumentException('وضعیت پرداخت تغییر کرده است.');
        daExec($db,"UPDATE delta_app_real_orders SET state='cancelled' WHERE id=?",'i',$id);$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function daRealDeliver(mysqli $db,int $buyer,int $id): array {
    $lock='delta-real-order-'.$id;$l=daRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',$lock);
    if((int)($l['acquired']??0)!==1)throw new InvalidArgumentException('فاکتور در حال پردازش است.');
    try{
        $o=daRealOrder($db,$buyer,$id);
        $pay=daRow($db,"SELECT * FROM pays WHERE hash_id=? AND user_id=? AND type='DELTA_APP_BUY'",'si',$o['pay_hash'],$buyer);
        if(!$pay || (int)$pay['price']!==(int)$o['price'] || !in_array($pay['state'],['paid','approved','paid_with_wallet'],true))throw new InvalidArgumentException('پرداخت واقعی تأیید نشده است.');
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
                $name=null;
                for($i=0;$i<40;$i++){
                    $n='delta'.random_int(10000,99999);
                    if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$n)&&!daRow($db,'SELECT account_id FROM delta_app_pg_users WHERE pg_name=?','s',$n)){$name=$n;break;}
                }
                if($name===null)throw new RuntimeException('name');
                $pass=daPassword();$now=time();$expires=$now+(int)$o['days']*86400;
                daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(?,?,?,?,?,0,?,?)','ssiiiii',$name,password_hash($pass,PASSWORD_DEFAULT),(int)$o['plan_id'],(int)$o['gb'],$expires,$now,(int)$o['device_limit']);
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
    $id=(int)$o['account_id'];$p=daPassword();$db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id);
        daExec($db,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($p,PASSWORD_DEFAULT),$id);
        daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);
        daExec($db,'DELETE FROM delta_app_devices WHERE account_id=?','i',$id);
        $db->commit();return $p;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
