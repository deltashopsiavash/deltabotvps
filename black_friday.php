<?php
/**
 * DELTA limited-time campaign: invoices store an immutable discount snapshot.
 * The price of server_plans is never modified.
 */
function bfDefaults(){
    $tz=new DateTimeZone('Asia/Tehran');
    return [
        'name'=>'بلک فرایدی دلتا ۱۴۰۵', 'enabled'=>0,
        'starts_at'=>(new DateTimeImmutable('2026-11-23 00:00',$tz))->getTimestamp(),
        'ends_at'=>(new DateTimeImmutable('2026-11-28 00:00',$tz))->getTimestamp(),
        'one_percent'=>15,'two_percent'=>20,'new_percent'=>25,
        'new_capacity'=>30,'gb_cost'=>1000,'eligible_plan_ids'=>[]
    ];
}
function bfConfig(){
    $default=bfDefaults();
    $input=json_decode((string)getSettingValue('DELTA_BLACK_FRIDAY_V1',''),true);
    // Upgrade only the original 24-hour default window; keep custom dates intact.
    $tz=new DateTimeZone('Asia/Tehran');
    $oldStart=(new DateTimeImmutable('2026-11-27 00:00',$tz))->getTimestamp();
    $oldEnd=(new DateTimeImmutable('2026-11-28 00:00',$tz))->getTimestamp();
    if(is_array($input) && (int)($input['starts_at']??0)===$oldStart
       && (int)($input['ends_at']??0)===$oldEnd && time()<$oldStart){
        $input['starts_at']=$default['starts_at'];
        $input['ends_at']=$default['ends_at'];
        bfSave($input);
    }
    $config=array_merge($default,is_array($input)?$input:[]);
    foreach(['enabled','starts_at','ends_at','one_percent','two_percent','new_percent','new_capacity','gb_cost'] as $key){
        $config[$key]=(int)$config[$key];
    }
    $list=is_array($config['eligible_plan_ids'])?$config['eligible_plan_ids']:[];
    $config['eligible_plan_ids']=array_values(array_unique(array_filter(array_map('intval',$list),function($n){return $n>0;})));
    return $config;
}
function bfCampaignKey($config){
    // Reservations and reports are isolated by their scheduled start/end.
    return substr(sha1((string)$config['starts_at'].':'.(string)$config['ends_at']),0,40);
}
function bfWindow($config,$now=null){
    $now=$now===null?time():(int)$now;
    if(empty($config['enabled']))return 'disabled';
    if($now<(int)$config['starts_at'])return 'upcoming';
    if($now>=(int)$config['ends_at'])return 'ended';
    return 'active';
}
function bfDurationGroup($days){
    $days=(int)$days;
    if($days>=28 && $days<=31)return 'one';
    if($days>=58 && $days<=62)return 'two';
    return null;
}
function bfPriceWithPercent($amount,$percent){
    return max(0,(int)round(max(0,(int)$amount)*(100-(int)$percent)/100));
}
function bfClock($unix){
    return (new DateTimeImmutable('@'.(int)$unix))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d H:i');
}
function bfParseClock($text){
    $text=trim((string)$text);
    if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D',$text))return null;
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$text,new DateTimeZone('Asia/Tehran'));
    return $dt && $dt->format('Y-m-d H:i')===$text?$dt->getTimestamp():null;
}
function bfSave($config){
    return upsertSettingValue('DELTA_BLACK_FRIDAY_V1',json_encode($config,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function bfEnsureSchema(){
    global $connection;
    static $ready=[];
    if(!$connection)return false;
    $r=$connection->query('SELECT DATABASE() AS db');
    $db=$r?(string)($r->fetch_assoc()['db']??''):'';
    if(isset($ready[$db]))return true;
    $ok=$connection->query("CREATE TABLE IF NOT EXISTS black_friday_invoices (
        hash_id VARCHAR(128) NOT NULL PRIMARY KEY,
        campaign_key VARCHAR(40) NOT NULL,
        user_id BIGINT NOT NULL,
        period_days INT NOT NULL,
        volume_gb DECIMAL(12,3) NOT NULL DEFAULT 0,
        regular_price INT NOT NULL,
        discount_percent TINYINT UNSIGNED NOT NULL,
        discounted_price INT NOT NULL,
        new_customer TINYINT(1) NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        reserved_until BIGINT NOT NULL,
        KEY bf_new_slot (campaign_key,new_customer,reserved_until),
        KEY bf_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if($ok)$ready[$db]=true;
    return (bool)$ok;
}
function bfFinalStates(){
    return ['approved','paid','paid_with_wallet','paid_with_gateway','completed','success','delivered'];
}
function bfCountedStatesSql(){
    return "'approved','paid','paid_with_wallet','paid_with_gateway','completed','success','delivered','have_sent','processing_receipt','need_admin'";
}
function bfHasPriorPurchase($uid,$excludeHash=''){
    global $connection;
    $uid=(int)$uid;
    if($uid<=0)return true;
    $st=$connection->prepare('SELECT id FROM orders_list WHERE userid=? LIMIT 1');
    if(!$st)return true;
    $st->bind_param('i',$uid);$st->execute();
    $exists=$st->get_result()->num_rows>0;$st->close();
    if($exists)return true;
    $states="'approved','paid','paid_with_wallet','paid_with_gateway','completed','success','delivered'";
    $st=$connection->prepare("SELECT id FROM pays WHERE user_id=? AND hash_id<>? AND state IN ($states)
      AND (type='BUY_SUB' OR type='RENEW_ACCOUNT' OR type='RENEW_SCONFIG'
           OR type LIKE 'PG_RENEW_FULL_%') LIMIT 1");
    if(!$st)return true;
    $st->bind_param('is',$uid,$excludeHash);$st->execute();
    $exists=$st->get_result()->num_rows>0;$st->close();
    return $exists;
}
function bfFindInvoice($hash){
    global $connection;
    if(!bfEnsureSchema())return null;
    $st=$connection->prepare('SELECT * FROM black_friday_invoices WHERE hash_id=? LIMIT 1');
    if(!$st)return null;
    $st->bind_param('s',$hash);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return $row?:null;
}
function bfFindInvoiceId($id){
    global $connection;
    if((int)$id<=0 || !bfEnsureSchema())return null;
    $st=$connection->prepare('SELECT b.* FROM black_friday_invoices b JOIN pays p ON p.hash_id=b.hash_id WHERE p.id=? LIMIT 1');
    $st->bind_param('i',$id);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return $row?:null;
}
function bfVipReservedCount(){
    global $connection;
    if(!bfEnsureSchema())return 0;
    $states=bfCountedStatesSql();
    $now=time();$key=bfCampaignKey(bfConfig());
    $st=$connection->prepare("SELECT COUNT(DISTINCT b.user_id) AS n FROM black_friday_invoices b
      JOIN pays p ON p.hash_id=b.hash_id
      WHERE b.campaign_key=? AND b.new_customer=1 AND
        (p.state IN ($states) OR (p.state='pending' AND b.reserved_until>?))");
    $st->bind_param('si',$key,$now);$st->execute();
    $r=$st->get_result()->fetch_assoc();$st->close();
    return (int)($r['n']??0);
}
function bfVipClaimedByUser($uid){
    global $connection;
    if(!bfEnsureSchema())return true;
    $states=bfCountedStatesSql();
    $now=time();$uid=(int)$uid;$key=bfCampaignKey(bfConfig());
    $st=$connection->prepare("SELECT b.hash_id FROM black_friday_invoices b JOIN pays p ON p.hash_id=b.hash_id
      WHERE b.campaign_key=? AND b.new_customer=1 AND b.user_id=?
        AND (p.state IN ($states) OR (p.state='pending' AND b.reserved_until>?)) LIMIT 1");
    $st->bind_param('sii',$key,$uid,$now);$st->execute();
    $row=$st->get_result()->fetch_assoc();$st->close();
    return (bool)$row;
}
function bfPlanForInvoice($pay){
    global $connection;
    $type=(string)($pay['type']??'');$uid=(int)($pay['user_id']??0);
    $plan=0;$newAllowed=false;
    if($type==='BUY_SUB'){
        if((int)($pay['agent_bought']??0)!==0 || (int)($pay['agent_count']??1)>1)return null;
        if((int)($pay['volume']??0)!==0 || (int)($pay['day']??0)!==0)return null;
        $plan=(int)$pay['plan_id'];$newAllowed=true;
    }elseif($type==='RENEW_SCONFIG'){
        $plan=(int)$pay['plan_id'];
    }elseif($type==='RENEW_ACCOUNT'){
        $orderId=(int)$pay['plan_id'];
        $st=$connection->prepare('SELECT fileid,agent_bought FROM orders_list WHERE id=? AND userid=? LIMIT 1');
        if(!$st)return null;
        $st->bind_param('ii',$orderId,$uid);$st->execute();
        $order=$st->get_result()->fetch_assoc();$st->close();
        if(!$order || (int)($order['agent_bought']??0)!==0)return null;
        $plan=(int)$order['fileid'];
    }elseif(preg_match('/^PG_RENEW_FULL_(\d+)_(\d+)$/D',$type,$m)){
        $orderId=(int)$m[1];$plan=(int)$m[2];
        $st=$connection->prepare('SELECT id FROM orders_list WHERE id=? AND userid=? LIMIT 1');
        if(!$st)return null;
        $st->bind_param('ii',$orderId,$uid);$st->execute();
        $order=$st->get_result()->fetch_assoc();$st->close();
        if(!$order)return null;
    }else return null;
    if($plan<=0)return null;
    $st=$connection->prepare('SELECT price,days,volume FROM server_plans WHERE id=? LIMIT 1');
    if(!$st)return null;
    $st->bind_param('i',$plan);$st->execute();
    $p=$st->get_result()->fetch_assoc();$st->close();
    if(!$p || (int)$p['price']<=0)return null;
    $group=bfDurationGroup($p['days']);
    if(!$group)return null;
    return ['group'=>$group,'base'=>(int)$p['price'],'days'=>(int)$p['days'],
      'volume'=>(float)$p['volume'],'new_allowed'=>$newAllowed,'plan_id'=>$plan];
}
/** Single locked reservation ensures the 30-new-customer limit is not oversold. */
function bfApplyInvoice($hash){
    global $connection,$isChildBot;
    if(!empty($isChildBot))return null;
    $config=bfConfig();
    if(bfWindow($config)!=='active' || !bfEnsureSchema())return null;
    $hash=(string)$hash;
    if($hash==='' || strlen($hash)>128)return null;
    $res=$connection->query('SELECT DATABASE() AS db');
    $db=$res?(string)($res->fetch_assoc()['db']??''):'';
    $lock='delta_bf_'.substr(sha1($db),0,30);
    $safe=$connection->real_escape_string($lock);
    $locked=false;$tx=false;
    try{
        $result=$connection->query("SELECT GET_LOCK('$safe',5) AS ok");
        $locked=$result && (int)($result->fetch_assoc()['ok']??0)===1;
        if(!$locked)return null;
        $connection->begin_transaction();$tx=true;
        $st=$connection->prepare('SELECT * FROM pays WHERE hash_id=? LIMIT 1 FOR UPDATE');
        $st->bind_param('s',$hash);$st->execute();
        $pay=$st->get_result()->fetch_assoc();$st->close();
        if(!$pay || $pay['state']!=='pending' || (int)($pay['special_offer_id']??0)>0){
            $connection->rollback();$tx=false;return null;
        }
        $prior=bfFindInvoice($hash);
        if($prior){$connection->commit();$tx=false;return (int)$prior['discounted_price'];}
        $plan=bfPlanForInvoice($pay);
        if(!$plan || (!empty($config['eligible_plan_ids']) && !in_array((int)$plan['plan_id'],$config['eligible_plan_ids'],true))){
            $connection->rollback();$tx=false;return null;
        }
        $uid=(int)$pay['user_id'];
        $percent=(int)$config[$plan['group']==='one'?'one_percent':'two_percent'];
        $vip=0;
        if($plan['new_allowed'] && (int)$config['new_capacity']>0 &&
           !bfHasPriorPurchase($uid,$hash) && !bfVipClaimedByUser($uid) &&
           bfVipReservedCount()<(int)$config['new_capacity']){
            $vip=1;$percent=(int)$config['new_percent'];
        }
        $base=(int)$plan['base'];
        if($percent<=0 || $base<=0){$connection->rollback();$tx=false;return null;}
        $price=bfPriceWithPercent($base,$percent);
        $now=time();$reserved=$now+3600;$days=(int)$plan['days'];$gb=(float)$plan['volume'];
        $campaignKey=bfCampaignKey($config);
        $st=$connection->prepare('INSERT INTO black_friday_invoices
          (hash_id,campaign_key,user_id,period_days,volume_gb,regular_price,discount_percent,discounted_price,new_customer,created_at,reserved_until)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        if(!$st)throw new RuntimeException('Cannot prepare promotion record');
        $st->bind_param('ssiidiiiiii',$hash,$campaignKey,$uid,$days,$gb,$base,$percent,$price,$vip,$now,$reserved);
        if(!$st->execute())throw new RuntimeException('Cannot save promotion reservation');
        $st->close();
        $st=$connection->prepare("UPDATE pays SET price=? WHERE hash_id=? AND state='pending'");
        $st->bind_param('is',$price,$hash);
        if(!$st->execute())throw new RuntimeException('Cannot save promotional price');
        $count=$st->affected_rows;$st->close();
        if($count!==1)throw new RuntimeException('Price update did not affect one pending payment');
        $connection->commit();$tx=false;
        return $price;
    }catch(Throwable $error){
        if($tx)$connection->rollback();
        error_log('bfApplyInvoice: '.$error->getMessage());
        return null;
    }finally{
        if($locked)$connection->query("SELECT RELEASE_LOCK('$safe')");
    }
}
function bfInvoicePrice($hash,$fallback=0){
    $row=bfFindInvoice((string)$hash);
    return $row?(int)$row['discounted_price']:(int)$fallback;
}
function bfInvoiceNote($hash){
    $r=bfFindInvoice((string)$hash);
    if(!$r)return '';
    $title=(int)$r['new_customer']===1?'🎁 تخفیف خرید اول':'🖤 تخفیف بلک فرایدی';
    $pct=(int)$r['discount_percent'];
    return "\n\n<b>$title ({$pct}٪)</b>\n".
        "قیمت اصلی: <s>".number_format((int)$r['regular_price'])."</s> تومان\n".
        "قیمت جشنواره: <b>".number_format((int)$r['discounted_price'])."</b> تومان";
}
function bfGuardPayment($callback){
    global $connection,$from_id;
    if(!preg_match('/^(?:payWithCartToCart|payCustomWithCartToCart|payRenewWithCartToCart|pgRenewPayCart|payWithWallet|payCustomWithWallet|payRenewWithWallet|payWithTronWallet|payWithWeSwap|payWithUsdt|payPhotoReceipt|payTextReceipt)(.+)$/D',(string)$callback,$m))return false;
    $hash=explode('~',(string)$m[1],2)[0];
    $r=bfFindInvoice($hash);
    if(!$r || time()<=(int)$r['reserved_until'])return false;
    $st=$connection->prepare('SELECT user_id,state FROM pays WHERE hash_id=? LIMIT 1');
    if(!$st)return false;
    $st->bind_param('s',$hash);$st->execute();$pay=$st->get_result()->fetch_assoc();$st->close();
    if(!$pay || (int)$pay['user_id']!==(int)$from_id || $pay['state']!=='pending')return false;
    $st=$connection->prepare("UPDATE pays SET state='expired' WHERE hash_id=? AND state='pending'");
    $st->bind_param('s',$hash);$st->execute();$st->close();
    setUser('none','step');
    sendMessage('⌛ مهلت یک‌ساعته این فاکتور تمام شده است. لطفاً فاکتور جدید دریافت کنید.',getMainKeys());
    return true;
}

function bfCouponStepPayId($step){
    $step=(string)$step;
    if(preg_match('/^discountSelectPlan\d+_\d+_(\d+)$/D',$step,$m))return (int)$m[1];
    if(preg_match('/^discountRenew\d+_(\d+)$/D',$step,$m))return (int)$m[1];
    if(preg_match('/^discountCustomPlanDay(\d+)$/D',$step,$m))return (int)$m[1];
    return 0;
}
function bfGuardCouponStep($step,$text){
    $id=bfCouponStepPayId($step);
    if(!$id || trim((string)$text)==='' || trim((string)$text)==='/start')return false;
    if(!bfFindInvoiceId($id))return false;
    setUser('none','step');
    sendMessage('🖤 تخفیف بلک فرایدی از قبل روی فاکتور شما اعمال شده است و با کد تخفیف جمع نمی‌شود.',getMainKeys());
    return true;
}
function bfPlanIdInput($raw){
    $value=trim((string)$raw);
    if($value==='all' || $value==='همه' || $value==='*')return [];
    $parts=preg_split('/[\s,،]+/u',$value,-1,PREG_SPLIT_NO_EMPTY);
    if(!$parts || count($parts)>100)return null;
    $ids=[];
    foreach($parts as $part){
        if(!preg_match('/^\d+$/D',$part) || (int)$part<1)return null;
        $ids[]=(int)$part;
    }
    return array_values(array_unique($ids));
}

function bfSaleStats(){
    global $connection;
    $data=['total'=>0,'new'=>0,'revenue'=>0,'regular'=>0,'discount'=>0,'gb'=>0,'cost'=>0,'margin'=>0];
    if(!bfEnsureSchema())return $data;
    $key=bfCampaignKey(bfConfig());
    $st=$connection->prepare('SELECT b.*,p.state FROM black_friday_invoices b
      JOIN pays p ON p.hash_id=b.hash_id WHERE b.campaign_key=?');
    if(!$st)return $data;
    $st->bind_param('s',$key);$st->execute();
    $rows=$st->get_result();
    if(!$rows){$st->close();return $data;}
    foreach($rows as $row){
        if(!in_array((string)$row['state'],bfFinalStates(),true))continue;
        $data['total']++;
        if((int)$row['new_customer']===1)$data['new']++;
        $data['revenue']+=(int)$row['discounted_price'];
        $data['regular']+=(int)$row['regular_price'];
        $data['discount']+=(int)$row['regular_price']-(int)$row['discounted_price'];
        $data['gb']+=(float)$row['volume_gb'];
    }
    $st->close();
    $data['cost']=(int)round($data['gb']*(int)bfConfig()['gb_cost']);
    $data['margin']=$data['revenue']-$data['cost'];
    return $data;
}
