<?php
/**
 * DELTA finance reporting for the main bot owner.
 *
 * Sales are confirmed service invoices from pays; wallet top-ups are cash movement,
 * not sales. Dates are invoice-creation dates (pays.request_date), because historic
 * settlement timestamps/payment channels are not uniformly recorded in this bot.
 * Volume cost is an ESTIMATE, not audited net profit.
 */
function finPaidStatesSql(){
    return "'approved','paid','paid_with_wallet','paid_with_gateway','completed','success','delivered'";
}
function finSaleTypeSql($alias='p'){
    return "(".$alias.".type='BUY_SUB' OR ".$alias.".type IN ('RENEW_ACCOUNT','RENEW_SCONFIG')".
        " OR ".$alias.".type LIKE 'PG_RENEW_%' OR ".$alias.".type LIKE 'INCREASE_VOLUME_%'".
        " OR ".$alias.".type LIKE 'INCREASE_DAY_%')";
}
function finIsSaleType($type){
    return $type==='BUY_SUB' || $type==='RENEW_ACCOUNT' || $type==='RENEW_SCONFIG'
        || strpos($type,'PG_RENEW_')===0 || strpos($type,'INCREASE_VOLUME_')===0
        || strpos($type,'INCREASE_DAY_')===0;
}
function finTimezone(){return new DateTimeZone('Asia/Tehran');}
function finPeriod($key){
    $tz=finTimezone();
    $now=new DateTimeImmutable('now',$tz);
    $today=$now->setTime(0,0,0);
    $title='کل تاریخچه';
    $start=0;$end=0;
    switch($key){
        case 'today': $title='امروز';$start=$today->getTimestamp();$end=$today->modify('+1 day')->getTimestamp();break;
        case 'yesterday':$title='دیروز';$start=$today->modify('-1 day')->getTimestamp();$end=$today->getTimestamp();break;
        case 'week':$title='۷ روز اخیر';$start=$today->modify('-6 days')->getTimestamp();$end=$today->modify('+1 day')->getTimestamp();break;
        case 'month30':$title='۳۰ روز اخیر';$start=$today->modify('-29 days')->getTimestamp();$end=$today->modify('+1 day')->getTimestamp();break;
        case 'jmonth':
            $title='ماه شمسی جاری';
            if(function_exists('jdate') && function_exists('jalali_to_gregorian')){
                $parts=explode('-',jdate('Y-n-1',$now->getTimestamp()));
                $g=jalali_to_gregorian((int)$parts[0],(int)$parts[1],1);
                $start=(new DateTimeImmutable(sprintf('%04d-%02d-%02d', $g[0],$g[1],$g[2]),$tz))->getTimestamp();
            }else{$start=$today->modify('first day of this month')->getTimestamp();}
            $end=$today->modify('+1 day')->getTimestamp();
            break;
        default:
            if(preg_match('/^c([0-9]{10})-([0-9]{10})$/D',(string)$key,$m)){
                $start=(int)$m[1];$end=(int)$m[2];
                if($start<946684800 || $end<=$start || $end-$start>3660*86400)return finPeriod('all');
                $title='بازه دلخواه '.finDate($start).' تا '.finDate($end-1);
            }else{$key='all';}
    }
    return ['key'=>$key,'title'=>$title,'start'=>$start,'end'=>$end];
}
function finDate($ts){return (new DateTimeImmutable('@'.(int)$ts))->setTimezone(finTimezone())->format('Y-m-d');}
function finPeriodClause($p,$alias='p',$field='request_date'){
    $s=(int)$p['start'];$e=(int)$p['end'];
    if($s<=0)return '1=1';
    return $alias.'.'.$field.' >= '.$s.' AND '.$alias.'.'.$field.' < '.$e;
}
function finQueryRow($sql){
    global $connection;
    $r=$connection->query($sql);
    if(!$r){error_log('finQueryRow: '.$connection->error);return [];}
    $out=$r->fetch_assoc() ?: [];$r->free();return $out;
}
function finQueryRows($sql){
    global $connection;
    $r=$connection->query($sql);
    if(!$r){error_log('finQueryRows: '.$connection->error);return [];}
    $out=[];while($row=$r->fetch_assoc())$out[]=$row;
    $r->free();return $out;
}
function finEnsureSchema(){
    global $connection;
    static $done=false;
    if($done)return true;
    $ok=$connection->query("CREATE TABLE IF NOT EXISTS delta_finance_expenses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        amount BIGINT UNSIGNED NOT NULL,
        category VARCHAR(24) NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        spent_at INT UNSIGNED NOT NULL,
        created_at INT UNSIGNED NOT NULL,
        created_by BIGINT NOT NULL,
        INDEX idx_fin_expense_date (spent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if(!$ok){error_log('finEnsureSchema: '.$connection->error);return false;}
    $done=true;return true;
}
function finCostPerGb(){
    if(function_exists('bfConfig')){
        $c=bfConfig();
        return max(0,(int)($c['gb_cost']??1000));
    }
    return 1000;
}
function finCurrency($value){return number_format((float)$value,0).' تومان';}
function finEscape($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function finExpenseCategories(){
    return ['server'=>'سرور و هاست','commission'=>'کارمزد','support'=>'پشتیبانی','ads'=>'تبلیغات','other'=>'سایر هزینه‌ها'];
}
function finSummary($period){
    $when=finPeriodClause($period);
    $type=finSaleTypeSql();
    $paid=finPaidStatesSql();
    $sales=finQueryRow("SELECT COUNT(*) AS n,COUNT(DISTINCT p.user_id) AS buyers,
      COALESCE(SUM(p.price),0) AS revenue,
      COALESCE(AVG(p.price),0) AS avg_invoice,
      SUM(CASE WHEN p.state='paid_with_wallet' THEN 1 ELSE 0 END) AS wallet_count,
      COALESCE(SUM(CASE WHEN p.state='paid_with_wallet' THEN p.price ELSE 0 END),0) AS wallet_revenue,
      SUM(CASE WHEN p.type='BUY_SUB' THEN 1 ELSE 0 END) AS purchase_count,
      COALESCE(SUM(CASE WHEN p.type='BUY_SUB' THEN p.price ELSE 0 END),0) AS purchase_revenue,
      SUM(CASE WHEN p.type LIKE 'RENEW_%' OR p.type LIKE 'PG_RENEW_%' THEN 1 ELSE 0 END) AS renewal_count,
      COALESCE(SUM(CASE WHEN p.type LIKE 'RENEW_%' OR p.type LIKE 'PG_RENEW_%' THEN p.price ELSE 0 END),0) AS renewal_revenue,
      SUM(CASE WHEN p.type LIKE 'INCREASE_%' THEN 1 ELSE 0 END) AS add_on_count,
      COALESCE(SUM(CASE WHEN p.type LIKE 'INCREASE_%' THEN p.price ELSE 0 END),0) AS add_on_revenue,
      SUM(CASE WHEN p.agent_bought<>0 THEN 1 ELSE 0 END) AS agent_count,
      COALESCE(SUM(CASE WHEN p.agent_bought<>0 THEN p.price ELSE 0 END),0) AS agent_revenue
      FROM pays p WHERE $when AND $type AND p.state IN ($paid)");
    $pending=finQueryRow("SELECT
      SUM(CASE WHEN p.state IN ('pending','have_sent','need_admin','processing_receipt') THEN 1 ELSE 0 END) AS waiting,
      SUM(CASE WHEN p.state IN ('declined','expired','cancelled','canceled','failed') THEN 1 ELSE 0 END) AS unsuccessful
      FROM pays p WHERE $when AND $type");
    $topups=finQueryRow("SELECT COUNT(*) AS n,COALESCE(SUM(p.price),0) AS amount FROM pays p
      WHERE $when AND p.type='INCREASE_WALLET' AND p.state IN ($paid)");
    $discount=['n'=>0,'amount'=>0];
    // Missing campaign table on older installs is expected.
    if(function_exists('bfEnsureSchema') && bfEnsureSchema()){
        $discount=finQueryRow("SELECT COUNT(*) AS n,
          COALESCE(SUM(GREATEST(b.regular_price-b.discounted_price,0)),0) AS amount
          FROM black_friday_invoices b INNER JOIN pays p ON p.hash_id=b.hash_id
          WHERE $when AND p.state IN ($paid)");
    }
    $cost=finEstimateVolumeCost($period);
    $exp=finQueryRow("SELECT COUNT(*) AS n,COALESCE(SUM(amount),0) AS total FROM delta_finance_expenses e
      WHERE ".finPeriodClause($period,'e','spent_at'));
    $total=(float)($sales['revenue']??0);
    $volumeCost=(float)($cost['estimated_cost']??0);
    $manualCost=(float)($exp['total']??0);
    return ['sales'=>$sales,'pending'=>$pending,'topups'=>$topups,'discount'=>$discount,
      'volume'=>$cost,'expense'=>$exp,'margin'=>$total-$volumeCost-$manualCost,
      'external_cash_estimate'=>$total-(float)($sales['wallet_revenue']??0)+(float)($topups['amount']??0)];
}
function finEstimateVolumeCost($period){
    $when=finPeriodClause($period);
    $type=finSaleTypeSql();
    $paid=finPaidStatesSql();
    // A renewal's plan_id may be an order id. For full PasarGuard renewals the
    // last part of type contains the plan id. Add-ons may store volume directly.
    $sql="SELECT p.type,p.volume,p.day,p.plan_id,p.agent_count,
        sp.volume AS plan_volume,sp.days AS plan_days,
        rp.kind AS renewal_kind,rp.amount AS renewal_amount
      FROM pays p
      LEFT JOIN orders_list o ON p.type='RENEW_ACCOUNT' AND o.id=p.plan_id
      LEFT JOIN server_plans sp ON sp.id=CASE
        WHEN p.type='RENEW_ACCOUNT' THEN o.fileid
        WHEN p.type LIKE 'PG_RENEW_FULL_%' THEN CAST(SUBSTRING_INDEX(p.type,'_',-1) AS UNSIGNED)
        ELSE p.plan_id END
      LEFT JOIN pg_renew_plans rp ON p.type LIKE 'PG_RENEW_VOLUME_%'
          AND rp.id=CAST(SUBSTRING_INDEX(p.type,'_',-1) AS UNSIGNED)
      WHERE $when AND $type AND p.state IN ($paid)";
    $rows=finQueryRows($sql);$known=0;$unknown=0;$gb=0.0;
    foreach($rows as $row){
        $t=(string)$row['type'];$amount=null;
        if(strpos($t,'INCREASE_DAY_')===0 || strpos($t,'PG_RENEW_DAY_')===0){
            $amount=0.0; // Day-only extension: zero extra GB, not zero server costs.
        }elseif(strpos($t,'PG_RENEW_VOLUME_')===0){
            $amount=isset($row['renewal_amount'])?(float)$row['renewal_amount']:null;
        }elseif(strpos($t,'INCREASE_VOLUME_')===0){
            $amount=(float)($row['volume']??0)>0?(float)$row['volume']:null;
        }elseif((float)($row['volume']??0)>0){
            $amount=(float)$row['volume'];
        }elseif(isset($row['plan_volume']) && (float)$row['plan_volume']>0){
            $amount=(float)$row['plan_volume'];
        }
        if($amount===null){$unknown++;continue;}
        $known++;
        $mult=($t==='BUY_SUB' && (int)($row['agent_count']??0)>1)?(int)$row['agent_count']:1;
        $gb+=$amount*$mult;
    }
    $rate=finCostPerGb();
    return ['gb'=>$gb,'known'=>$known,'unknown'=>$unknown,
      'rate'=>$rate,'estimated_cost'=>(int)round($gb*$rate)];
}
function finGlobalCounts(){
    return finQueryRow("SELECT
      (SELECT COUNT(*) FROM users) AS users_count,
      (SELECT COUNT(*) FROM orders_list) AS services_all,
      (SELECT COUNT(*) FROM orders_list WHERE status=1) AS services_active,
      (SELECT COUNT(*) FROM orders_list WHERE status<>1) AS services_inactive");
}
function finReportKeyboard($period,$section='overview'){
    $k=$period['key'];
    $rows=[
      [['text'=>'📈 خلاصه مالی','callback_data'=>'finView_overview_'.$k],
       ['text'=>'🧾 نوع فروش','callback_data'=>'finView_types_'.$k]],
      [['text'=>'📦 پرفروش‌ترین پلن‌ها','callback_data'=>'finView_plans_'.$k],
       ['text'=>'👥 مشتری‌های برتر','callback_data'=>'finView_customers_'.$k]],
      [['text'=>'📅 روند روزانه','callback_data'=>'finView_daily_'.$k],
       ['text'=>'💸 هزینه‌ها و سود','callback_data'=>'finView_expenses_'.$k]],
      [['text'=>'➕ ثبت هزینه / تنظیم نرخ حجم','callback_data'=>'finExpenseMenu']],
      [['text'=>'📆 انتخاب بازه زمانی','callback_data'=>'finPeriods'],
       ['text'=>'🔄 تازه‌سازی','callback_data'=>'finView_'.$section.'_'.$k]],
      [['text'=>'🔙 مدیریت ربات','callback_data'=>'managePanel']]
    ];
    return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function finShowReport($section,$periodKey){
    global $message_id;
    if(!finEnsureSchema()){sendMessage('❌ ایجاد جدول حسابداری موفق نبود. لاگ دیتابیس بررسی شود.');return;}
    $p=finPeriod($periodKey);
    $head="📊 <b>حسابداری دلتا | ".finEscape($p['title'])."</b>\n";
    $head.="🕐 زمان گزارش: ".finEscape((new DateTimeImmutable('now',finTimezone()))->format('Y-m-d H:i'))." (ایران)\n\n";
    $s=finSummary($p);$data=$s['sales'];$v=$s['volume'];
    if($section==='overview'){
        $g=finGlobalCounts();
        $txt=$head.
            "💰 <b>جمع فروش قطعی:</b> ".finCurrency($data['revenue']??0)."\n".
            "🧾 تعداد فاکتور فروش قطعی: ".number_format((int)($data['n']??0))."\n".
            "👤 خریداران یکتا: ".number_format((int)($data['buyers']??0))."\n".
            "📈 میانگین هر فروش: ".finCurrency($data['avg_invoice']??0)."\n".
            "⏳ فاکتورهای در انتظار: ".number_format((int)($s['pending']['waiting']??0))."\n".
            "❌ فاکتورهای ناموفق: ".number_format((int)($s['pending']['unsuccessful']??0))."\n\n".
            "💳 خرید پرداخت‌شده با کیف پول: ".finCurrency($data['wallet_revenue']??0)."\n".
            "➕ شارژ موفق کیف پول (فروش نیست): ".finCurrency($s['topups']['amount']??0)."\n".
            "🏦 دریافتی بیرونیِ برآوردی: ".finCurrency($s['external_cash_estimate'])."\n".
            "🖤 تخفیف ثبت‌شده بلک فرایدی: ".finCurrency($s['discount']['amount']??0)."\n\n".
            "📦 حجم قابل‌برآورد فروش‌رفته: ".number_format((float)$v['gb'],2)." گیگ\n".
            "💸 هزینه تخمینی حجم: ".finCurrency($v['estimated_cost'])."\n".
            "🧮 سایر هزینه‌های ثبت‌شده: ".finCurrency($s['expense']['total']??0)."\n".
            "📊 <b>سود برآوردی پس از هزینه‌ها: ".finCurrency($s['margin'])."</b>\n".
            ($v['unknown']>0?"⚠️ برای {$v['unknown']} فروش حجم/هزینه نامشخص است.\n":"").
            "\n👥 کل کاربران ثبت‌شده: ".number_format((int)($g['users_count']??0))."\n".
            "🔌 سرویس‌ها: ".number_format((int)($g['services_all']??0))." کل / ".
            number_format((int)($g['services_active']??0))." فعال\n\n".
            "ℹ️ تاریخ‌ها بر مبنای صدور فاکتورند، نه لزوماً روز وصول. درآمدِ کیف پول از شارژ مجدد آن جدا شده؛ سود قطعی نیست.";
    }elseif($section==='types'){
        $txt=$head."🛒 خرید جدید: ".number_format((int)($data['purchase_count']??0)).
            " | ".finCurrency($data['purchase_revenue']??0)."\n".
            "🔁 تمدیدها: ".number_format((int)($data['renewal_count']??0)).
            " | ".finCurrency($data['renewal_revenue']??0)."\n".
            "➕ افزونه حجم/روز: ".number_format((int)($data['add_on_count']??0)).
            " | ".finCurrency($data['add_on_revenue']??0)."\n".
            "🤝 فروش نمایندگی (زیرمجموعه خریدها): ".number_format((int)($data['agent_count']??0)).
            " | ".finCurrency($data['agent_revenue']??0)."\n\n".
            "💳 فاکتورهای قطعی پرداخت با کیف پول: ".number_format((int)($data['wallet_count']??0))."\n".
            "🏦 سایر فاکتورهای قطعی: ".number_format(max(0,(int)($data['n']??0)-(int)($data['wallet_count']??0)))."\n".
            "⚠️ تفکیک دقیق کارت‌به‌کارت/درگاه/رمزارز در سوابق قدیمی در دسترس نیست.";
    }elseif($section==='plans'){
        $where=finPeriodClause($p);$paid=finPaidStatesSql();
        $rows=finQueryRows("SELECT p.plan_id,COALESCE(sp.title,'پلن نامشخص') AS title,
           COUNT(*) AS n,SUM(p.price) AS revenue
           FROM pays p LEFT JOIN server_plans sp ON sp.id=p.plan_id
           WHERE $where AND p.type='BUY_SUB' AND p.state IN ($paid)
           GROUP BY p.plan_id,sp.title ORDER BY revenue DESC LIMIT 12");
        $txt=$head."📦 <b>۱۲ پلن پرفروش از نظر مبلغ (خرید اولیه)</b>\n\n";
        foreach($rows as $i=>$r)$txt.=($i+1).". ".finEscape(mb_substr((string)$r['title'],0,42))." (#".(int)$r['plan_id'].")\n".
            "   ".number_format((int)$r['n'])." فروش | ".finCurrency($r['revenue'])."\n";
        if(!$rows)$txt.="در این بازه خرید ثبت نشده است.";
        $txt.="\nℹ️ تمدیدها و افزونه‌ها در گزارش نوع فروش محاسبه می‌شوند.";
    }elseif($section==='customers'){
        $where=finPeriodClause($p);$paid=finPaidStatesSql();$type=finSaleTypeSql();
        $rows=finQueryRows("SELECT p.user_id,COUNT(*) AS n,SUM(p.price) AS revenue
           FROM pays p WHERE $where AND $type AND p.state IN ($paid)
           GROUP BY p.user_id ORDER BY revenue DESC LIMIT 12");
        $txt=$head."👥 <b>۱۲ خریدار برتر از نظر مبلغ فروش</b>\n\n";
        foreach($rows as $i=>$r)$txt.=($i+1).". <code>".(int)$r['user_id']."</code> | ".
            number_format((int)$r['n'])." خرید | ".finCurrency($r['revenue'])."\n";
        if(!$rows)$txt.="در این بازه خرید ثبت نشده است.";
    }elseif($section==='daily'){
        $paid=finPaidStatesSql();$type=finSaleTypeSql();$today=(new DateTimeImmutable('now',finTimezone()))->setTime(0,0);
        $txt=$head."📅 <b>فروش روزانه در ۷ روز اخیر</b>\n\n";
        for($i=6;$i>=0;$i--){
            $day=$today->modify('-'.$i.' days');
            $from=$day->getTimestamp();$to=$day->modify('+1 day')->getTimestamp();
            $r=finQueryRow("SELECT COUNT(*) AS n,COALESCE(SUM(p.price),0) AS revenue FROM pays p
             WHERE p.request_date >= $from AND p.request_date < $to AND $type AND p.state IN ($paid)");
            $txt.=finDate($from)." | ".number_format((int)($r['n']??0))." فروش | ".finCurrency($r['revenue']??0)."\n";
        }
        $txt.="\nℹ️ این روند همیشه ۷ روز اخیر را نشان می‌دهد؛ سایر گزارش‌ها بر اساس بازه منتخب هستند.";
    }elseif($section==='expenses'){
        $cats=finExpenseCategories();$where=finPeriodClause($p,'e','spent_at');
        $groups=finQueryRows("SELECT category,COUNT(*) AS n,SUM(amount) AS total
           FROM delta_finance_expenses e WHERE $where GROUP BY category ORDER BY total DESC");
        $txt=$head."💸 <b>هزینه‌ها و سود</b>\n\n".
           "مبلغ فروش قطعی: ".finCurrency($data['revenue']??0)."\n".
           "حجم قابل‌برآورد: ".number_format((float)$v['gb'],2)." گیگ\n".
           "نرخ هزینه هر گیگ: ".finCurrency($v['rate'])."\n".
           "هزینه برآوردی حجم: ".finCurrency($v['estimated_cost'])."\n".
           "هزینه‌های دستی ثبت‌شده: ".finCurrency($s['expense']['total']??0)."\n".
           "➖ <b>مانده برآوردی: ".finCurrency($s['margin'])."</b>\n\n".
           "📋 <b>هزینه‌های ثبت‌شده بر حسب دسته:</b>\n";
        foreach($groups as $g)$txt.='• '.finEscape($cats[$g['category']]??$g['category']).': '.finCurrency($g['total'])."\n";
        if(!$groups)$txt.="هنوز هزینه‌ای برای این بازه ثبت نشده است.\n";
        $txt.="\n⚠️ تعرفه حجم با نرخ فعلی تخمین زده می‌شود؛ تاریخچه نرخ خرید، هزینه سرویس نامحدود، بازپرداخت‌ها و کارمزدهای ثبت‌نشده مشخص نیست. هزینه حجم را دوباره در مخارج ثبت نکنید.";
    }else{$section='overview';$txt=$head.'گزارش یافت نشد.';}
    smartSendOrEdit($message_id,$txt,finReportKeyboard($p,$section),'HTML');
}
function finShowPeriods(){
    global $message_id;
    $items=['today'=>'امروز','yesterday'=>'دیروز','week'=>'۷ روز اخیر',
      'month30'=>'۳۰ روز اخیر','jmonth'=>'ماه شمسی جاری','all'=>'کل تاریخچه'];
    $rows=[];
    foreach($items as $k=>$v)$rows[]=[['text'=>$v,'callback_data'=>'finView_overview_'.$k]];
    $rows[]=[['text'=>'🗓 بازه دلخواه','callback_data'=>'finCustom']];
    $rows[]=[['text'=>'🔙 گزارش جامع','callback_data'=>'finView_overview_all']];
    smartSendOrEdit($message_id,'📆 بازه آماری را انتخاب کنید:',json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE));
}
function finShowExpenseMenu(){
    global $message_id;
    $cats=finExpenseCategories();$rows=[];
    foreach($cats as $key=>$value)$rows[]=[['text'=>'➕ '.$value,'callback_data'=>'finAdd_'.$key]];
    $rows[]=[['text'=>'🗒 ۱۰ هزینه اخیر','callback_data'=>'finRecentExpenses']];
    $rows[]=[['text'=>'✏️ تنظیم نرخ هر گیگ','callback_data'=>'finEditRate']];
    $rows[]=[['text'=>'🔙 گزارش جامع','callback_data'=>'finView_expenses_all']];
    smartSendOrEdit($message_id,
      "🧮 <b>مدیریت هزینه‌ها</b>\n\nهزینه واقعی سرور، کارمزد، تبلیغات و پشتیبانی را ثبت کنید تا از مانده برآوردی کسر شوند.\n".
      "نرخ فعلی هر گیگ: <b>".finCurrency(finCostPerGb())."</b>\n".
      "⚠️ هزینه خرید حجم را دوباره به‌صورت دستی وارد نکنید.",json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE),'HTML');
}
function finHandleRequest(){
    global $data,$text,$userInfo,$from_id,$admin,$isChildBot,$message_id,$update,$connection;
    $cb=(string)($data??'');$step=(string)($userInfo['step']??'');
    if(strpos($cb,'fin')!==0 && strpos($step,'fin')!==0)return false;
    if((int)$from_id!==(int)$admin || !empty($isChildBot)){
        if(strpos($cb,'fin')===0 && isset($update->callback_query))answerQuery('فقط مالک اصلی به گزارش مالی دسترسی دارد.',true);
        return strpos($cb,'fin')===0;
    }
    if($step!=='' && strpos($step,'fin')===0 && ($cb==='managePanel'||$cb==='mainMenu'||trim((string)$text)==='/start')){
        setUser('none','step');return false;
    }
    if(!finEnsureSchema()){sendMessage('❌ دسترسی به دیتابیس حسابداری ممکن نیست.');return true;}
    if($cb==='finDashboard'){finShowReport('overview','all');return true;}
    if($cb==='finPeriods'){finShowPeriods();return true;}
    if($cb==='finExpenseMenu'){finShowExpenseMenu();return true;}
    if(preg_match('/^finView_(overview|types|plans|customers|daily|expenses)_(all|today|yesterday|week|month30|jmonth|c[0-9]{10}-[0-9]{10})$/D',$cb,$m)){
        finShowReport($m[1],$m[2]);return true;
    }
    if($cb==='finCustom'){
        setUser('finCustomInput','step');
        sendMessage("📆 تاریخ شروع و پایان را به میلادی و وقت ایران بفرستید:\n<code>2026-11-23 2026-11-27</code>\nهر دو روز در گزارش حساب می‌شوند.",null,'HTML');return true;
    }
    if($step==='finCustomInput' && isset($update->message->text)){
        if(!preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})\s+([0-9]{4}-[0-9]{2}-[0-9]{2})$/D',trim((string)$text),$m)){
            sendMessage('❌ دو تاریخ میلادی را با یک فاصله بفرستید. مثال: 2026-11-23 2026-11-27');return true;
        }
        $dates=[];
        foreach([1,2] as $n){
            $d=DateTimeImmutable::createFromFormat('!Y-m-d',$m[$n],finTimezone());
            if(!$d || $d->format('Y-m-d')!==$m[$n]){sendMessage('❌ تاریخ نامعتبر است.');return true;}
            $dates[]=$d->getTimestamp();
        }
        $start=$dates[0];$end=(new DateTimeImmutable('@'.$dates[1]))->setTimezone(finTimezone())->modify('+1 day')->getTimestamp();
        if($end<=$start || $end-$start>3660*86400){sendMessage('❌ بازه باید حداکثر ۱۰ سال و پایان بعد از شروع باشد.');return true;}
        setUser('none','step');finShowReport('overview','c'.$start.'-'.$end);return true;
    }
    if(preg_match('/^finAdd_(server|commission|support|ads|other)$/D',$cb,$m)){
        setUser('finExpenseInput_'.$m[1],'step');
        sendMessage("💸 مبلغ هزینه به تومان و توضیح را ارسال کنید:\n<code>250000 | تمدید سرور فرانکفورت</code>\nتاریخ هزینه زمان ثبت خواهد بود.",null,'HTML');
        return true;
    }
    if(preg_match('/^finExpenseInput_(server|commission|support|ads|other)$/D',$step,$m) && isset($update->message->text)){
        $parts=explode('|',trim((string)$text),2);
        $amount=trim($parts[0]??'');$note=trim($parts[1]??'');
        if(!preg_match('/^[1-9][0-9]{0,11}$/D',$amount) || $note==='' || mb_strlen($note)>250){
            sendMessage('❌ نمونه صحیح: 250000 | هزینه سرور (تومان). مبلغ مثبت و توضیح حداکثر ۲۵۰ حرف.');return true;
        }
        $category=$m[1];$now=time();$uid=(int)$from_id;$amount=(int)$amount;
        $st=$connection->prepare('INSERT INTO delta_finance_expenses(amount,category,note,spent_at,created_at,created_by) VALUES(?,?,?,?,?,?)');
        if(!$st){sendMessage('❌ ثبت هزینه ممکن نبود.');return true;}
        $st->bind_param('issiii',$amount,$category,$note,$now,$now,$uid);
        $ok=$st->execute();$st->close();
        if(!$ok){sendMessage('❌ ذخیره هزینه در دیتابیس ناموفق بود.');return true;}
        setUser('none','step');sendMessage('✅ هزینه '.finCurrency($amount).' ثبت شد.');finShowExpenseMenu();return true;
    }
    if($cb==='finEditRate'){
        setUser('finRateInput','step');
        sendMessage('💰 نرخ تمام‌شده هر گیگ به تومان را فقط به‌صورت عدد بفرستید (مثلاً 1000).');return true;
    }
    if($step==='finRateInput' && isset($update->message->text)){
        $s=trim((string)$text);
        if(!preg_match('/^[0-9]{1,7}$/D',$s)){sendMessage('❌ عدد صحیح بین ۰ تا ۹٬۹۹۹٬۹۹۹ وارد کنید.');return true;}
        $c=bfConfig();$c['gb_cost']=(int)$s;
        if(!bfSave($c)){sendMessage('❌ ذخیره نرخ ناموفق بود.');return true;}
        setUser('none','step');sendMessage('✅ نرخ هر گیگ به '.finCurrency($s).' تغییر کرد. این نرخ در برآورد دوره‌های گذشته هم اعمال می‌شود.');
        finShowExpenseMenu();return true;
    }
    if($cb==='finRecentExpenses'){
        $cats=finExpenseCategories();$rows=finQueryRows('SELECT id,amount,category,note,spent_at FROM delta_finance_expenses ORDER BY id DESC LIMIT 10');
        $txt="🗒 <b>۱۰ هزینه ثبت‌شده اخیر</b>\n\n";$buttons=[];
        foreach($rows as $r){
            $id=(int)$r['id'];$txt.="#$id | ".finDate($r['spent_at'])." | ".finEscape($cats[$r['category']]??$r['category'])."\n".
                finCurrency($r['amount']).' | '.finEscape(mb_substr((string)$r['note'],0,80))."\n\n";
            $buttons[]=[['text'=>'🗑 حذف هزینه #'.$id,'callback_data'=>'finDeleteAsk_'.$id]];
        }
        if(!$rows)$txt.="هنوز هزینه‌ای ثبت نشده است.";
        $buttons[]=[['text'=>'🔙 مدیریت هزینه‌ها','callback_data'=>'finExpenseMenu']];
        smartSendOrEdit($message_id,$txt,json_encode(['inline_keyboard'=>$buttons],JSON_UNESCAPED_UNICODE),'HTML');return true;
    }
    if(preg_match('/^finDeleteAsk_([0-9]+)$/D',$cb,$m)){
        $id=(int)$m[1];$row=finQueryRow('SELECT * FROM delta_finance_expenses WHERE id='.$id.' LIMIT 1');
        if(!$row){sendMessage('❌ این هزینه پیدا نشد.');return true;}
        $textOut='⚠️ حذف هزینه #'.$id.' به مبلغ '.finCurrency($row['amount']).'؟';
        $btn=['inline_keyboard'=>[[['text'=>'✅ تأیید حذف','callback_data'=>'finDelete_'.$id]],
            [['text'=>'❌ انصراف','callback_data'=>'finRecentExpenses']]]];
        smartSendOrEdit($message_id,$textOut,json_encode($btn,JSON_UNESCAPED_UNICODE));return true;
    }
    if(preg_match('/^finDelete_([0-9]+)$/D',$cb,$m)){
        $id=(int)$m[1];
        $st=$connection->prepare('DELETE FROM delta_finance_expenses WHERE id=? LIMIT 1');
        $st->bind_param('i',$id);$st->execute();$affected=$st->affected_rows;$st->close();
        sendMessage($affected?'✅ هزینه حذف شد.':'❌ هزینه پیدا نشد.');finShowExpenseMenu();return true;
    }
    if(strpos($cb,'fin')===0){sendMessage('گزینه مالی معتبر نیست.');return true;}
    return false;
}
