<?php
require_once __DIR__.'/delta_app_real_checkout.php';
function daEsc($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function daKeys(array $rows): string {return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);}
function daBtn(string $name,string $cb): array {
    if(!preg_match('/^(?:da_(?:plan|make|cat|wcat)_|(?:dab|dar)_(?:plan|cat)_)\d+$/D',$cb)&&!preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}]/u',$name)){
        $icons=['بازگشت'=>'🔙','انصراف'=>'❌','قبلی'=>'◀️','بعدی'=>'▶️','خرید سرویس'=>'🛒','سرویس‌های من'=>'👤','ساخت دسته‌بندی'=>'➕','دسته‌بندی‌ها'=>'🗂','ساخت پلن'=>'➕','پلن‌ها'=>'📦','پنل‌های اختصاصی برنامه'=>'🖥','افزودن پنل برنامه'=>'➕','انتخاب پنل و گروه'=>'⚙️','کاربران'=>'👥','ساخت کاربر'=>'➕','آیدی خرید و پشتیبانی'=>'📞','به‌روزرسانی ساب کاربران'=>'🔄','جستجوی جدید'=>'🔎','همه کاربران'=>'👥','دستگاه‌ها'=>'📱','تغییر نام'=>'✏️','حذف دسته'=>'🗑','تأیید'=>'✅','ویرایش پلن'=>'✏️','حذف پلن'=>'🗑','تأیید حذف'=>'🗑','تأیید حذف کامل'=>'🗑','تنظیم فاصله خودکار'=>'⏱','به‌روزرسانی همین الان'=>'🔄','قطع و اتصال مجدد کاربران متصل'=>'🔌','به‌روزرسانی بدون قطعی'=>'🔄','تنظیم آیدی خرید'=>'🛒','تنظیم آیدی پشتیبانی'=>'💬','پنل‌های برنامه'=>'🖥','ویرایش اطلاعات پنل'=>'✏️','انتخاب گروه'=>'🗂','حذف پنل'=>'🗑','جزئیات حساب'=>'👤','جزئیات'=>'📋','آیدی‌های برنامه'=>'📞','فهرست ساب‌ها'=>'🔗','افزودن دوباره'=>'➕','فعال'=>'✅','غیرفعال'=>'⛔'];
        $name=($icons[$name]??(str_starts_with($name,'خروج ')?'🚪':(str_starts_with($cb,'da_pg')?'🖥':'📌'))).' '.$name;
    }
    return [['text'=>$name,'callback_data'=>$cb]];
}
function daBotValue($r,string $key){return is_array($r)?($r[$key]??null):(is_object($r)?($r->$key??null):null);}
function daSend(string $txt,array $rows=[]): void {
    global $connection,$chat_id;
    $keys=$rows?daKeys($rows):json_encode(['inline_keyboard'=>[]]);
    $payload=['chat_id'=>$chat_id,'text'=>$txt,'parse_mode'=>'HTML','reply_markup'=>$keys];
    if(str_contains($txt,'رمز: <code>')||str_contains($txt,'رمز تازه (فقط این بار):')){
        $r=bot('sendMessage',array_replace($payload,['reply_markup'=>json_encode(['inline_keyboard'=>[]])]));
        if(!daBotValue($r,'ok'))return;
        $payload['text']='✅ اطلاعات حساب در پیام جداگانه ارسال شد.';
    }
    $target=(int)($GLOBALS['da_ui_target']??0);
    if($target>0){
        $r=bot('editMessageText',$payload+['message_id'=>$target]);
        $desc=strtolower((string)daBotValue($r,'description'));
        if(daBotValue($r,'ok')||str_contains($desc,'message is not modified'))return;
        if(!str_contains($desc,'message to edit not found')&&!str_contains($desc,"message can't be edited")&&!str_contains($desc,'message_id_invalid')&&!str_contains($desc,'there is no text in the message to edit')&&!str_contains($desc,'message text is empty'))return;
    }
    $r=bot('sendMessage',$payload);
    $msg=daBotValue(daBotValue($r,'result'),'message_id');
    if(is_numeric($msg)&&(int)$msg>0){
        $GLOBALS['da_ui_target']=(int)$msg;
        if((int)$chat_id===(int)($GLOBALS['admin']??0)&&empty($GLOBALS['da_customer_ui']))daExec($connection,'INSERT INTO delta_app_ui(id,message_id) VALUES(1,?) ON DUPLICATE KEY UPDATE message_id=VALUES(message_id)','i',(int)$msg);
    }
}
function daHome(): void {
    global $botUrl;
    $endpoint=rtrim((string)$botUrl,'/').'/delta_app_api.php';
    daSend("🔐 <b>مدیریت برنامه دلتا VPN</b>",[daBtn('📦 پلن‌ها','da_menuplans'),daBtn('🖥 پنل‌ها','da_menupanels'),daBtn('⚙️ مدیریت','da_menumanage')]);
}
function daList(mysqli $db,string $kind,int $page): void {
    $off=$page*8;$rows=[];$txt='';
    if($kind==='plans'){
        $txt="📦 پلن‌ها\n";
        $items=daRows($db,'SELECT * FROM delta_app_plans ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
        foreach(array_slice($items,0,8) as $p){$id=(int)$p['id'];$txt.="\n".daEsc($p['name'])." — ".number_format((int)$p['price'])." تومان";$rows[]=daBtn($p['name'],'da_plan_'.$id);}
        $rows[]=daBtn('➕ ساخت پلن','da_addplan');
    }elseif($kind==='stock'){
        $txt="🔗 ساب‌های مشترک (حداکثر ۲۰ لینک)\n";
        $items=daRows($db,'SELECT id,nodes,active,updated FROM delta_app_sources ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
        foreach(array_slice($items,0,8) as $p){$id=(int)$p['id'];$txt.="\n#$id | ".$p['nodes']." سرور | ".($p['active']?'فعال':'غیرفعال');$rows[]=daBtn(($p['active']?'غیرفعال':'فعال')." #$id",'da_st_'.$id);$rows[]=daBtn("🔄 دریافت مجدد #$id",'da_sf_'.$id);$rows[]=daBtn("حذف #$id",'da_sdask_'.$id);}
        $rows[]=daBtn('➕ افزودن اشتراک','da_addstock');
    }else{
        $txt="👥 کاربران برنامه\n";
        $rows[]=daBtn('🔎 جستجوی کاربر','da_search');
        $items=daRows($db,'SELECT id,username,active,expires FROM delta_app_accounts ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
        foreach(array_slice($items,0,8) as $p)$rows[]=daBtn(($p['active']&&$p['expires']>time()?'🟢 ':'🔴 ').$p['username'],'da_u_'.$p['id']);
        $rows[]=daBtn('➕ ساخت کاربر','da_adduser');
    }
    if(!$items)$txt.="\nخالی است.";
    if($page>0)$rows[]=daBtn('قبلی',"da_".$kind.'_'.($page-1));
    if(count($items)>8)$rows[]=daBtn('بعدی',"da_".$kind.'_'.($page+1));
    $rows[]=daBtn('بازگشت',$kind==='plans'?'da_menuplans':($kind==='users'?'da_menumanage':'da_home'));daSend($txt,$rows);
}
function daChoosePlan(mysqli $db,int $page=0): void {
    $off=$page*8;$items=daRows($db,'SELECT id,name,gb,days FROM delta_app_plans WHERE active=1 ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
    $rows=[];
    foreach(array_slice($items,0,8) as $p)$rows[]=daBtn($p['name'],'da_make_'.$p['id']);
    if($page>0)$rows[]=daBtn('قبلی','da_choose_'.($page-1));
    if(count($items)>8)$rows[]=daBtn('بعدی','da_choose_'.($page+1));
    $rows[]=daBtn('بازگشت','da_menumanage');
    daSend($items?'پلن کاربر جدید را انتخاب کنید؛ نام و رمز خودکار ساخته می‌شوند.':'پلن فعال وجود ندارد؛ ابتدا پلن بسازید.',$rows);
}
function daSearchResults(mysqli $db,string $prefix,int $page=0): void {
    $items=daFindUsers($db,$prefix,$page*8);$rows=[];
    foreach(array_slice($items,0,8) as $p)$rows[]=daBtn(($p['active']&&$p['expires']>time()?'🟢 ':'🔴 ').$p['username'],'da_u_'.$p['id']);
    // Search term is held in the owner's step; callbacks stay within Telegram's 64-byte cap.
    if($page>0)$rows[]=daBtn('قبلی','da_searchpage_'.($page-1));
    if(count($items)>8)$rows[]=daBtn('بعدی','da_searchpage_'.($page+1));
    $rows[]=daBtn('جستجوی جدید','da_search');$rows[]=daBtn('همه کاربران','da_users_0');
    daSend('🔎 نتیجه جستجو: '.daEsc($prefix).($items?'':"\nکاربری یافت نشد."),$rows);
}
function daUser(mysqli $db,int $id): void {
    $a=daAccount($db,$id);if(!$a){daSend('کاربر یافت نشد.');return;}
    $m=daPgMap($db,$id);$left=daRemainingSeconds((int)$a['expires']);
    $remaining=intdiv($left,86400).' روز و '.intdiv($left%86400,3600).' ساعت و '.intdiv($left%3600,60).' دقیقه و '.($left%60).' ثانیه';
    $fmt=fn($b)=>number_format($b/1073741824,4).' GB';
    $traffic='مصرف: نامشخص (پنل تأیید نشده)';$status=$m?($m['state']==='pending'?'در انتظار ساخت':'نامشخص'):'قدیمی؛ نیازمند حساب اختصاصی';
    if($m&&$m['state']==='ready'){try{$v=daPgLive($db,$a);$a['expires']=$v['expires'];$a['gb']=$v['limit_bytes']/1073741824;$traffic='مصرف: '.$fmt($v['used_bytes'])."\nباقی‌مانده: ".$fmt($v['remaining_bytes']);$status=(!$a['active']?'ورود غیرفعال؛ ': '').daEsc($v['panel_status']);}catch(Throwable $e){}}
    $rows=[daBtn('✏️ تغییر نام کاربری','da_rename_'.$id),daBtn('🔑 ساخت رمز جدید','da_resetask_'.$id)];
    if($m&&$m['state']==='pending')$rows[]=daBtn('🔄 بازیابی ساخت','da_pgretry_'.$id);
    if($m&&$m['state']==='ready'){$rows[]=daBtn('⛔ غیرفعال کردن / تلاش مجدد','da_pgdisable_'.$id);if(!$a['active'])$rows[]=daBtn('✅ فعال کردن','da_pgenable_'.$id);}
    $rows[]=daBtn('✏️ تعداد دستگاه مجاز: '.$a['device_limit'],'da_limit_'.$id);$rows[]=daBtn('📱 دستگاه‌ها','da_devices_'.$id);$rows[]=daBtn('🗑 حذف کامل کاربر','da_delask_'.$id);$rows[]=daBtn('بازگشت','da_users_0');
    daSend("👤 <code>".daEsc($a['username'])."</code>\nشناسه: ".$id."\nتاریخ ایجاد: ".daIranDate((int)$a['created'])."\nپایان: ".daIranDate((int)$a['expires'])."\nباقی‌مانده: ".$remaining."\nحجم خریداری‌شده: ".$a['gb']." GB\n".$traffic."\nوضعیت: ".$status."\nرمز قبلی قابل مشاهده نیست.",$rows);
}
function daPgGroupButtons(string $ticket,array $groups,int $page=0): void {
    $rows=[];$off=$page*8;
    foreach(array_slice($groups,$off,8) as $g)$rows[]=daBtn($g['name'],'da_pgpick_'.$ticket.'_'.$g['id']);
    if($page>0)$rows[]=daBtn('قبلی','da_pgpage_'.$ticket.'_'.($page-1));
    if(count($groups)>$off+8)$rows[]=daBtn('بعدی','da_pgpage_'.$ticket.'_'.($page+1));
    $rows[]=daBtn('انصراف','da_pg');
    daSend("✅ ورود به پنل موفق بود.\nکدام گروه برای مشتریان برنامه استفاده شود؟",$rows);
}
function deltaAppAdminHandle(): bool {
    global $connection,$from_id,$admin,$chat_id,$isChildBot,$update,$data,$text,$userInfo,$botToken,$message_id;
    $cb=(string)($data??'');$input=trim((string)($text??''));$step=(string)($userInfo['step']??'');
    $command=$cb===''&&$input==='/delta';
    $buyPreview=($cb===''&&$input==='/buy')||str_starts_with($cb,'dab_')||str_starts_with($cb,'dar_');
    if(!$command&&!$buyPreview&&!str_starts_with($cb,'da_')&&!str_starts_with($step,'da_'))return false;
    if(!empty($isChildBot)||(string)$chat_id!==(string)$from_id)return false;
    if(!$buyPreview&&(string)$from_id!==(string)$admin)return false;
    if($buyPreview&&empty($userInfo['userid'])&&!daRow($connection,'SELECT userid FROM users WHERE userid=?','i',(int)$from_id))return false;
    // A hidden command alone is not authentication. Require the actual Telegram webhook secret.
    if(!is_string($botToken??null)||!hash_equals(hash('sha256',$botToken),(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))){
        return true;
    }
    if($cb!==''&&isset($update->callback_query->id))answerQuery();
    try{
        daSchema($connection);
        $GLOBALS['da_ui_target']=0;
        if(!$command&&$input!=='/buy'){
            $cbmsg=$update->callback_query->message->message_id??null;
            if($cb!==''&&is_numeric($cbmsg)){
                $GLOBALS['da_ui_target']=(int)$cbmsg;
                if(!$buyPreview)daExec($connection,'INSERT INTO delta_app_ui(id,message_id) VALUES(1,?) ON DUPLICATE KEY UPDATE message_id=VALUES(message_id)','i',(int)$cbmsg);
            }elseif(!$buyPreview&&str_starts_with($step,'da_')){
                $ui=daRow($connection,'SELECT message_id FROM delta_app_ui WHERE id=1');
                $GLOBALS['da_ui_target']=(int)($ui['message_id']??0);
            }
        }
        if($buyPreview){
            $GLOBALS['da_customer_ui']=true;
            setUser();
            daRealSchema($connection);
            if(str_starts_with($cb,'dab_')){daSend('این منوی قدیمی دیگر معتبر نیست.',[daBtn('خرید سرویس','dar_home')]);return true;}
            if($input==='/buy'||$cb==='dar_home'){daSend('اشتراک اختصاصی برنامه دلتا',[daBtn('خرید سرویس','dar_categories'),daBtn('سرویس‌های من','dar_mine')]);return true;}
            if($cb==='dar_categories'){$rows=[];foreach(daRows($connection,'SELECT DISTINCT c.id,c.name FROM delta_app_categories c JOIN delta_app_plans p ON p.category_id=c.id WHERE p.active=1 ORDER BY c.id') as $r)$rows[]=daBtn($r['name'],'dar_cat_'.$r['id']);$rows[]=daBtn('بازگشت','dar_home');daSend('دسته‌بندی را انتخاب کنید.',$rows);return true;}
            if(preg_match('/^dar_cat_(\d+)$/D',$cb,$m)){$rows=[];foreach(daRows($connection,'SELECT id,name,price FROM delta_app_plans WHERE active=1 AND category_id=? ORDER BY id','i',(int)$m[1]) as $r)$rows[]=daBtn($r['name'].' - '.number_format((int)$r['price']).' تومان','dar_plan_'.$r['id']);$rows[]=daBtn('بازگشت','dar_categories');daSend('پلن را انتخاب کنید.',$rows);return true;}
            if(preg_match('/^dar_plan_(\d+)$/D',$cb,$m)){
                $id=daRealInvoice($connection,(int)$from_id,(int)$m[1]);daRealInvoiceView($connection,(int)$from_id,$id);return true;
            }
            if(preg_match('/^dar_invoice_(\d+)$/D',$cb,$m)){daRealInvoiceView($connection,(int)$from_id,(int)$m[1]);return true;}
            if(preg_match('/^dar_cancel_(\d+)$/D',$cb,$m)){daRealCancel($connection,(int)$from_id,(int)$m[1]);daRealInvoiceView($connection,(int)$from_id,(int)$m[1]);return true;}
            if(preg_match('/^dar_card_(\d+)$/D',$cb,$m)){
                if(($GLOBALS['botState']['cartToCartState']??'off')!=='on')throw new InvalidArgumentException('کارت‌به‌کارت غیرفعال است.');
                if(!deltaCardPool())throw new InvalidArgumentException('شماره کارت فعالی ثبت نشده است.');
                $pay=daRealPrepareCard($connection,(int)$from_id,(int)$m[1]);
                if(deltaCardShowInvoice($pay))setUser('none');
                else daSend('نمایش شماره کارت ناموفق بود. دوباره از همین فاکتور تلاش کنید.',[daBtn('بازگشت','dar_invoice_'.$m[1])]);
                return true;
            }
            if(preg_match('/^dar_bank(?:ask|approve)_(\d+)$/D',$cb,$m)){
                answerQuery('رسید توسط مدیریت بررسی می‌شود.',true);return true;
            }
            if(preg_match('/^dar_change_(\d+)$/D',$cb,$m)){
                daRealCancel($connection,(int)$from_id,(int)$m[1]);
                $rows=[];foreach(daRows($connection,'SELECT DISTINCT c.id,c.name FROM delta_app_categories c JOIN delta_app_plans p ON p.category_id=c.id WHERE p.active=1 ORDER BY c.id') as $r)$rows[]=daBtn($r['name'],'dar_cat_'.$r['id']);
                $rows[]=daBtn('بازگشت','dar_home');daSend('دسته‌بندی را انتخاب کنید.',$rows);return true;
            }
            if(preg_match('/^dar_wallet(?:ask)?_(\d+)$/D',$cb,$m)){
                if(($GLOBALS['botState']['walletState']??'off')!=='on')throw new InvalidArgumentException('پرداخت از موجودی غیرفعال است.');
                $id=(int)$m[1];$o=daRealOrder($connection,(int)$from_id,$id);
                if(!daRealWalletAvailable($connection))throw new InvalidArgumentException('پرداخت از موجودی پس از ارتقای ایمن جدول کاربران فعال می‌شود.');
                if(str_starts_with($cb,'dar_walletask_')){
                    daSend('مبلغ '.number_format((int)$o['price']).' تومان از موجودی شما کسر می‌شود.',[daBtn('✅ پرداخت از موجودی','dar_wallet_'.$id),daBtn('بازگشت','dar_invoice_'.$id)]);return true;
                }
                daRealWallet($connection,(int)$from_id,$id);
                try{daRealDeliverPaidHash($connection,(string)$o['pay_hash']);}catch(Throwable $e){daSend('پرداخت ثبت شد؛ تحویل در انتظار تکمیل است.',[daBtn('وضعیت فاکتور','dar_invoice_'.$id)]);return true;}
                daRealInvoiceView($connection,(int)$from_id,$id);return true;
            }
            if(preg_match('/^dar_deliver_(\d+)$/D',$cb,$m)){
                $id=(int)$m[1];
                try{$order=daRealOrder($connection,(int)$from_id,$id);daRealSyncPaid($connection,(string)$order['pay_hash']);$result=daRealDeliver($connection,(int)$from_id,$id);}
                catch(Throwable $e){daSend('تحویل هنوز کامل نشده است؛ وضعیت فاکتور را بررسی کنید و دوباره پرداخت نکنید.',[daBtn('📋 وضعیت فاکتور','dar_invoice_'.$id)]);return true;}
                $o=$result['order'];$a=daAccount($connection,(int)$o['account_id']);
                if($result['password']!==null&&$a){
                    $msg="✅ <b>تحویل اشتراک دلتا</b>\nشماره سفارش: ".daEsc($o['pay_hash'])."\nپلن: ".daEsc($o['name'])."\nنام کاربری: <code>".daEsc($a['username'])."</code>\nرمز: <code>".daEsc($result['password'])."</code>\nحجم: ".$a['gb']." گیگ\nدستگاه: ".$a['device_limit']."\nپایان: ".daIranDate((int)$a['expires'])."\nمبلغ پرداخت: ".number_format((int)$o['price'])." تومان";
                    daSend($msg,[daBtn('👤 سرویس من','dar_service_'.$id),daBtn('بازگشت','dar_home')]);
                }else daRealServiceView($connection,(int)$from_id,$id);
                return true;
            }
            if($cb==='dar_orders'){daSend('اشتراک اختصاصی برنامه دلتا',[daBtn('خرید سرویس','dar_categories'),daBtn('سرویس‌های من','dar_mine')]);return true;}
            if($cb==='dar_mine'){
                $rows=[];$orders=daRows($connection,"SELECT o.id,o.state,a.username FROM delta_app_real_orders o LEFT JOIN delta_app_accounts a ON a.id=o.account_id WHERE o.buyer=? AND o.state IN ('delivered','provisioning','paid') ORDER BY o.id DESC LIMIT 30",'i',(int)$from_id);
                foreach($orders as $o)$rows[]=daBtn(($o['state']==='delivered'?'👤 ':'⏳ ').($o['username']??'DELTA-'.$o['id']),($o['state']==='delivered'?'dar_service_':'dar_invoice_').$o['id']);
                $rows[]=daBtn('بازگشت','dar_home');daSend($orders?'سرویس‌های من':'هنوز سرویس خریداری‌شده ندارید.',$rows);return true;
            }
            if(preg_match('/^dar_service_(\d+)$/D',$cb,$m)){daRealServiceView($connection,(int)$from_id,(int)$m[1]);return true;}
            if(preg_match('/^dar_devices_(\d+)$/D',$cb,$m)){
                daRealDevicesView($connection,(int)$from_id,(int)$m[1]);return true;
            }
            if(preg_match('/^dar_kickask_(\d+)_(\d+)$/D',$cb,$m)){daRealOrder($connection,(int)$from_id,(int)$m[1]);daSend('نشست این دستگاه لغو شود؟',[daBtn('✅ تأیید','dar_kick_'.$m[1].'_'.$m[2]),daBtn('بازگشت','dar_devices_'.$m[1])]);return true;}
            if(preg_match('/^dar_kick_(\d+)_(\d+)$/D',$cb,$m)){
                $o=daRealOrder($connection,(int)$from_id,(int)$m[1]);if($o['state']!=='delivered')throw new InvalidArgumentException('سرویس آماده نیست.');
                daDeviceRevoke($connection,(int)$o['account_id'],(int)$m[2]);daRealDevicesView($connection,(int)$from_id,(int)$m[1]);return true;
            }
            if(preg_match('/^dar_resetask_(\d+)$/D',$cb,$m)){daRealOrder($connection,(int)$from_id,(int)$m[1]);daSend('رمز جدید ساخته و همه نشست‌ها لغو شوند؟',[daBtn('✅ تأیید','dar_reset_'.$m[1]),daBtn('بازگشت','dar_service_'.$m[1])]);return true;}
            if(preg_match('/^dar_reset_(\d+)$/D',$cb,$m)){
                $p=daRealResetPassword($connection,(int)$from_id,(int)$m[1]);daSend('رمز تازه (فقط این بار): <code>'.daEsc($p).'</code>',[daBtn('بازگشت','dar_service_'.$m[1])]);return true;
            }
            return true;
        }
        daExec($connection,'DELETE FROM delta_app_panel_drafts WHERE expires<=?','i',time());
        if(str_starts_with($step,'da_pgpick_')&&($command||in_array($cb,['da_home','da_pg','da_pgadd','da_pgset'],true)||($cb===''&&$input==='/start'))){daExec($connection,'DELETE FROM delta_app_panel_drafts WHERE ticket=?','s',substr($step,10));}
        if($command||$cb==='da_home'){setUser();daHome();return true;}
        if($input==='/start'&&$cb===''){setUser();return false;}
        if($cb!==''&&!str_starts_with($cb,'da_')){setUser();return false;}
        if(preg_match('/^da_devices_(\d+)$/D',$cb,$m)){
            $id=(int)$m[1];$account=daAccount($connection,$id);if(!$account)throw new InvalidArgumentException('حساب یافت نشد.');
            $items=daDeviceList($connection,$id);$rows=[];$msg='دستگاه‌ها: '.count($items).' / '.$account['device_limit'];
            foreach($items as $item){$msg.="\n".daEsc($item['model']).' | '.daIranDate($item['last_seen']).' | مصرف مستقل: نامشخص';$rows[]=daBtn('خروج '. $item['model'],'da_dkick_'.$id.'_'.$item['id']);}
            $rows[]=daBtn('بازگشت','da_u_'.$id);daSend($msg,$rows);return true;
        }
        if(preg_match('/^da_dkick_(\d+)_(\d+)$/D',$cb,$m)){daDeviceRevoke($connection,(int)$m[1],(int)$m[2]);daSend('نشست دستگاه لغو شد.',[daBtn('دستگاه‌ها','da_devices_'.$m[1])]);return true;}
        if($cb==='da_menuplans'){setUser();daSend('📦 پلن‌ها',[daBtn('ساخت دسته‌بندی','da_catadd'),daBtn('دسته‌بندی‌ها','da_cats'),daBtn('ساخت پلن','da_addplan'),daBtn('پلن‌ها','da_plans_0'),daBtn('بازگشت','da_home')]);return true;}
        if($cb==='da_menupanels'){setUser();daSend('🖥 پنل‌ها',[daBtn('پنل‌های اختصاصی برنامه','da_pg'),daBtn('افزودن پنل برنامه','da_pgadd'),daBtn('انتخاب پنل و گروه','da_pgset'),daBtn('بازگشت','da_home')]);return true;}
        if($cb==='da_menumanage'){setUser();daSend('⚙️ مدیریت',[daBtn('کاربران','da_users_0'),daBtn('ساخت کاربر','da_adduser'),daBtn('آیدی خرید و پشتیبانی','da_contacts'),daBtn('به‌روزرسانی ساب کاربران','da_refresh'),daBtn('بازگشت','da_home')]);return true;}
        if($cb==='da_cats'){setUser();$rows=[];foreach(daRows($connection,'SELECT * FROM delta_app_categories ORDER BY id') as $r)$rows[]=daBtn($r['name'],'da_cat_'.$r['id']);$rows[]=daBtn('ساخت دسته‌بندی','da_catadd');$rows[]=daBtn('بازگشت','da_menuplans');daSend('دسته‌بندی‌ها',$rows);return true;}
        if($cb==='da_catadd'||preg_match('/^da_catedit_(\d+)$/D',$cb)){setUser($cb);daSend('نام دسته‌بندی را وارد کنید.',[daBtn('انصراف','da_cats')]);return true;}
        if(preg_match('/^da_cat_(\d+)$/D',$cb,$m)){setUser();$r=daRow($connection,'SELECT * FROM delta_app_categories WHERE id=?','i',(int)$m[1]);if(!$r)throw new InvalidArgumentException('دسته یافت نشد.');daSend(daEsc($r['name']),[daBtn('تغییر نام','da_catedit_'.$m[1]),daBtn('حذف دسته','da_catask_'.$m[1]),daBtn('بازگشت','da_cats')]);return true;}
        if(preg_match('/^da_catask_(\d+)$/D',$cb,$m)){setUser();daSend('دسته حذف شود؟ پلن‌های آن بدون دسته و غیرفعال می‌شوند؛ حساب‌ها حفظ می‌شوند.',[daBtn('تأیید','da_catdel_'.$m[1]),daBtn('انصراف','da_cats')]);return true;}
        if(preg_match('/^da_catdel_(\d+)$/D',$cb,$m)){$connection->begin_transaction();try{daExec($connection,'UPDATE delta_app_plans SET category_id=NULL,active=0 WHERE category_id=?','i',(int)$m[1]);daExec($connection,'DELETE FROM delta_app_categories WHERE id=?','i',(int)$m[1]);$connection->commit();}catch(Throwable $e){$connection->rollback();throw $e;}setUser();daSend('دسته حذف شد.',[daBtn('بازگشت','da_cats')]);return true;}
        if($cb==='da_addplan'||preg_match('/^da_planedit_(\d+)$/D',$cb,$m)){
            if(!daRow($connection,'SELECT id FROM delta_app_categories LIMIT 1'))throw new InvalidArgumentException('ابتدا دسته‌بندی بسازید.');
            $id=$cb==='da_addplan'?0:(int)$m[1];
            if($id&&!daRow($connection,'SELECT id FROM delta_app_plans WHERE id=?','i',$id))throw new InvalidArgumentException('پلن یافت نشد.');
            daExec($connection,'REPLACE INTO delta_app_admin_draft(id,plan_id) VALUES(1,?)','i',$id);setUser('da_wname');daSend('اسم پلن چی باشه؟ مثلاً 🔱10 گیگ دو کاربر🔱',[daBtn('انصراف','da_menuplans')]);return true;
        }
        if(preg_match('/^da_plan_(\d+)$/D',$cb,$m)){$p=daRow($connection,'SELECT * FROM delta_app_plans WHERE id=?','i',(int)$m[1]);if(!$p)throw new InvalidArgumentException('پلن یافت نشد.');setUser();daSend(daEsc($p['name'])."\n".$p['gb']." GB | ".$p['days']." روز | ".$p['price']." تومان | ".$p['device_limit']." دستگاه",[daBtn('ویرایش پلن','da_planedit_'.$m[1]),daBtn($p['active']?'غیرفعال':'فعال','da_pt_'.$m[1]),daBtn('حذف پلن','da_planask_'.$m[1]),daBtn('بازگشت','da_plans_0')]);return true;}
        if(preg_match('/^da_planask_(\d+)$/D',$cb,$m)){setUser();daSend('پلن حذف شود؟ حساب‌های قبلی تغییر نمی‌کنند.',[daBtn('تأیید حذف','da_plandel_'.$m[1]),daBtn('انصراف','da_plan_'.$m[1])]);return true;}
        if(preg_match('/^da_plandel_(\d+)$/D',$cb,$m)){daExec($connection,'DELETE FROM delta_app_plans WHERE id=?','i',(int)$m[1]);setUser();daList($connection,'plans',0);return true;}
        if(preg_match('/^da_wcat_(\d+)$/D',$cb,$m)&&$step==='da_wcat'){
            $d=daRow($connection,'SELECT * FROM delta_app_admin_draft WHERE id=1');$cat=daRow($connection,'SELECT id FROM delta_app_categories WHERE id=?','i',(int)$m[1]);
            if(!$d||!$cat||!$d['gb']||!$d['days'])throw new InvalidArgumentException('مراحل ساخت ناقص است.');
            $name=$d['name'];if($name==='')throw new InvalidArgumentException('نام پلن وارد نشده است.');
            $args=[$name,(int)$d['gb'],(int)$d['days'],(int)$d['price'],(int)$d['devices'],(int)$cat['id']];
            if($d['plan_id'])daExec($connection,'UPDATE delta_app_plans SET name=?,gb=?,days=?,price=?,device_limit=?,category_id=? WHERE id=?','siiiiii',...array_merge($args,[(int)$d['plan_id']]));
            else daExec($connection,'INSERT INTO delta_app_plans(name,gb,days,price,device_limit,category_id) VALUES(?,?,?,?,?,?)','siiiii',...$args);
            daExec($connection,'DELETE FROM delta_app_admin_draft WHERE id=1');setUser();daList($connection,'plans',0);return true;
        }
        if(preg_match('/^da_limit_(\d+)$/D',$cb,$m)){setUser($cb);daSend('تعداد دستگاه مجاز جدید (۱ تا ۲۰) را وارد کنید.',[daBtn('انصراف','da_u_'.$m[1])]);return true;}
        if($cb==='da_refresh'){
            setUser();$r=daRefreshSettings($connection);
            daSend("🔄 به‌روزرسانی اشتراک برنامه\nفاصله خودکار: ".$r['refresh_minutes']." دقیقه\nآخرین فرمان: #".$r['refresh_revision']."\nفرمان روی گوشی آنلاین هنگام بررسی بعدی دریافت می‌شود.",[daBtn('تنظیم فاصله خودکار','da_refinterval'),daBtn('به‌روزرسانی همین الان','da_refnow'),daBtn('بازگشت','da_menumanage')]);return true;
        }
        if($cb==='da_refinterval'){setUser('da_refinterval');daSend('فاصله به دقیقه (۱ تا ۱۴۴۰) را بفرستید.',[daBtn('انصراف','da_refresh')]);return true;}
        if($cb==='da_refnow'){
            setUser('da_refconfirm');daSend('نحوه به‌روزرسانی همه حساب‌های برنامه را انتخاب کنید. این فرمان مشتریان ربات فروش را تغییر نمی‌دهد.',[daBtn('قطع و اتصال مجدد کاربران متصل','da_refrestart'),daBtn('به‌روزرسانی بدون قطعی','da_refquiet'),daBtn('انصراف','da_refresh')]);return true;
        }
        if(in_array($cb,['da_refrestart','da_refquiet'],true)){
            if($step!=='da_refconfirm')throw new InvalidArgumentException('دوباره به‌روزرسانی همین الان را انتخاب کنید.');
            $r=daRefreshBroadcast($connection,$cb==='da_refrestart');setUser();
            daSend('✅ فرمان #'.$r['refresh_revision'].' ثبت شد. دریافت و اجرای فرمان به آنلاین بودن و فعالیت برنامه بستگی دارد؛ اجرای همه گوشی‌ها هنوز تأیید نشده.',[daBtn('بازگشت','da_refresh')]);return true;
        }
        if($cb==='da_contacts'){
            setUser();$c=daContacts($connection);
            daSend("آیدی‌های برنامه:\nخرید: ".($c['purchase']===''?'تنظیم نشده':'@'.daEsc($c['purchase']))."\nپشتیبانی: ".($c['support']===''?'تنظیم نشده':'@'.daEsc($c['support'])),[daBtn('تنظیم آیدی خرید','da_contact_purchase'),daBtn('تنظیم آیدی پشتیبانی','da_contact_support'),daBtn('بازگشت','da_menumanage')]);return true;
        }
        if(in_array($cb,['da_contact_purchase','da_contact_support'],true)){
            setUser($cb);daSend('آیدی تلگرام را با @ یا بدون آن بفرستید. برای پاک کردن - بفرستید.',[daBtn('انصراف','da_contacts')]);return true;
        }
        if($cb==='da_pgadd'){
            setUser('da_pgadd');
            daSend("اطلاعات ورود پنل مستقل برنامه را در چهار خط ارسال کنید:\nآدرس HTTPS پنل\nنام کاربری مدیر برنامه\nرمز مدیر برنامه\nدامنه HTTPS ساب (برای IP ضروری؛ در غیر این صورت اختیاری)\n\nپس از ورود، نام گروه‌ها به صورت دکمه نمایش داده می‌شود. پیام اطلاعات ورود برای حذف ارسال می‌شود.",[daBtn('انصراف','da_pg')]);return true;
        }
        if(preg_match('/^da_pgpage_([a-f0-9]{24})_(\d{1,3})$/D',$cb,$m)){
            if($step!=='da_pgpick_'.$m[1])throw new InvalidArgumentException('فهرست قدیمی است؛ دوباره شروع کنید.');
            $d=daPanelDraftGet($connection,$m[1]);daPgGroupButtons($m[1],json_decode($d['groups_json'],true,512,JSON_THROW_ON_ERROR),(int)$m[2]);return true;
        }
        if(preg_match('/^da_pgpick_([a-f0-9]{24})_(\d{1,10})$/D',$cb,$m)){
            if($step!=='da_pgpick_'.$m[1])throw new InvalidArgumentException('فهرست قدیمی است؛ دوباره شروع کنید.');
            $r=daPanelDraftFinish($connection,$m[1],(int)$m[2]);setUser();
            daSend('✅ پنل مستقل #'.$r['id']."\nگروه: ".daEsc($r['group'])."\nبرای حساب‌های جدید انتخاب شد.",[daBtn('پنل‌های برنامه','da_pg'),daBtn('بازگشت','da_home')]);return true;
        }
        if(preg_match('/^da_pgpanel_(\d{1,10})$/D',$cb,$m)){
            $id=(int)$m[1];$d=daPanelDraft($connection,daPgServer($connection,$id),$id);setUser('da_pgpick_'.$d['ticket']);daPgGroupButtons($d['ticket'],$d['groups']);return true;
        }
        if(preg_match('/^da_panelview_(\d+)$/D',$cb,$m)){
            $id=(int)$m[1];$p=daPgServer($connection,$id);setUser();
            daSend("پنل #".$id."\nآدرس: ".daEsc($p['base'])."\nدامنه: ".daEsc($p['sub_base_url'])."\nنام مدیر: ".daEsc($p['username'])."\nرمز نمایش داده نمی‌شود.",[daBtn('ویرایش اطلاعات پنل','da_paneledit_'.$id),daBtn('انتخاب گروه','da_pgpanel_'.$id),daBtn('حذف پنل','da_panelask_'.$id),daBtn('بازگشت','da_pg')]);return true;
        }
        if(preg_match('/^da_paneledit_(\d+)$/D',$cb,$m)){setUser($cb);daSend("چهار خط وارد کنید:\nآدرس HTTPS پنل\nنام کاربری مدیر\nرمز جدید (برای حفظ رمز قبلی -)\nدامنه HTTPS ساب",[daBtn('انصراف','da_panelview_'.$m[1])]);return true;}
        if(preg_match('/^da_panelask_(\d+)$/D',$cb,$m)){setUser();daSend('پنل حذف شود؟ فقط پنل بدون حساب قابل حذف است.',[daBtn('تأیید حذف','da_paneldel_'.$m[1]),daBtn('انصراف','da_panelview_'.$m[1])]);return true;}
        if(preg_match('/^da_paneldel_(\d+)$/D',$cb,$m)){daDeletePanel($connection,(int)$m[1]);setUser();daSend('پنل حذف شد.',[daBtn('بازگشت','da_pg')]);return true;}
        if($cb==='da_pg'||$cb==='da_pgset'){
            $setting=daRow($connection,'SELECT * FROM delta_app_pg_settings WHERE id=1');
            $txt="🖥 پنل‌های مستقل برنامه:\n".($setting&&$setting['panel_kind']==='app'?'انتخاب فعلی #'.$setting['server_id'].' | گروه #'.$setting['group_id']:'پنل مستقل انتخاب نشده');
            $items=daRows($connection,'SELECT id,panel_url,username,group_id FROM delta_app_panels ORDER BY id LIMIT 20');
            $rows=[];
            foreach($items as $r){$txt.="\n#".$r['id']." | ".daEsc($r['username']);$rows[]=daBtn('#'.$r['id'].' | '.$r['username'],($cb==='da_pgset'?'da_pgpanel_':'da_panelview_').$r['id']);}
            if(!$items)$txt.="\nهنوز پنلی ثبت نشده است.";
            else $txt.="\nروی نام پنل بزنید.";
            setUser();$rows[]=daBtn('➕ افزودن پنل برنامه','da_pgadd');$rows[]=daBtn('بازگشت','da_menupanels');daSend($txt,$rows);return true;
        }
        if($cb==='da_addstock'||str_starts_with($cb,'da_stock_')||preg_match('/^da_(st|sf|sdask|sd)_/',$cb)||$step==='da_addstock'){setUser();daHome();return true;}
        if($cb==='da_search'){setUser('da_search');daSend('نام کاربری یا ابتدای آن را بفرستید.',[daBtn('بازگشت','da_users_0')]);return true;}
        if(preg_match('/^da_searchpage_(\d{1,5})$/D',$cb,$m)&&str_starts_with($step,'da_searchq_')){
            daSearchResults($connection,substr($step,11),(int)$m[1]);return true;
        }
        if($cb==='da_adduser'||preg_match('/^da_choose_(\d{1,5})$/D',$cb,$m)){
            setUser('da_choose');daChoosePlan($connection,$cb==='da_adduser'?0:(int)$m[1]);return true;
        }
        if(preg_match('/^da_make_(\d{1,10})$/D',$cb,$m)){
            if($step!=='da_choose'){daSend('برای ایجاد حساب جدید دوباره ساخت کاربر را بزنید.');return true;}
            $p=daRow($connection,'SELECT * FROM delta_app_plans WHERE id=? AND active=1','i',(int)$m[1]);
            if(!$p)throw new InvalidArgumentException('پلن فعال پیدا نشد.');
            setUser();
            $a=daPgNew($connection,(int)$m[1]);
            if(!$a['ready']){daSend('ساخت در پنل هنوز تأیید نشده. حساب محلی حفظ شد؛ از جزئیات کاربر بازیابی ساخت را بزنید.',[daBtn('جزئیات حساب','da_u_'.$a['id'])]);return true;}
            daSend("✅ حساب ساخته شد\nنام: <code>".daEsc($a['username'])."</code>\nرمز: <code>".daEsc($a['password'])."</code>\nحجم: ".$p['gb']." GB\nاعتبار: ".$p['days']." روز",[daBtn('➕ ساخت کاربر دیگر','da_adduser'),daBtn('کاربران','da_users_0')]);
            return true;
        }
        if(preg_match('/^da_(plans|stock|users)_(\d{1,5})$/D',$cb,$m)){setUser();daList($connection,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^da_(pt|st|sf|sdask|sd|ut|u|rename|resetask|reset|delask|del|pgretry|pgdisable|pgenable)_(\d{1,10})$/D',$cb,$m)){
            $id=(int)$m[2];setUser();
            switch($m[1]){
                case 'pt': daExec($connection,'UPDATE delta_app_plans SET active=1-active WHERE id=?','i',$id);daList($connection,'plans',0);break;
                case 'st': daExec($connection,'UPDATE delta_app_sources SET active=1-active WHERE id=?','i',$id);daList($connection,'stock',0);break;
                case 'sf': $s=daRow($connection,'SELECT url FROM delta_app_sources WHERE id=?','i',$id);if(!$s)throw new InvalidArgumentException('ساب یافت نشد.');$nodes=daFetchSource($s['url']);daExec($connection,'UPDATE delta_app_sources SET payload=?,nodes=?,updated=? WHERE id=?','siii',json_encode($nodes,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),count($nodes),time(),$id);daList($connection,'stock',0);break;
                case 'sdask': daSend('این ساب از فهرست مشترک حذف شود؟',[daBtn('تأیید حذف','da_sd_'.$id),daBtn('انصراف','da_stock_0')]);break;
                case 'sd': daExec($connection,'DELETE FROM delta_app_sources WHERE id=?','i',$id);daList($connection,'stock',0);break;
                case 'delask':
                    $a=daAccount($connection,$id);if(!$a)throw new InvalidArgumentException('کاربر یافت نشد.');
                    daSend('حساب '.daEsc($a['username']).' و ساب اختصاصی آن برای همیشه حذف شوند؟',[daBtn('تأیید حذف کامل','da_del_'.$id),daBtn('انصراف','da_u_'.$id)]);break;
                case 'del':
                    daPgChange($connection,$id,'delete');daSend('✅ حذف حساب تأیید شد.');
                    daList($connection,'users',0);break;
                case 'ut': daUser($connection,$id);break;
                case 'pgdisable': daPgChange($connection,$id,'disable');daUser($connection,$id);break;
                case 'pgenable': daPgChange($connection,$id,'enable');daUser($connection,$id);break;
                case 'pgretry': daPgProvision($connection,$id);daSend('ساخت تأیید شد؛ برای دریافت رمز جدید، بازنشانی رمز را بزنید.',[daBtn('جزئیات','da_u_'.$id)]);break;
                case 'rename':if(daPgMap($connection,$id))throw new InvalidArgumentException('برای حفظ تطابق حساب با پسارگارد، تغییر نام حساب اختصاصی فعلاً غیرفعال است.');setUser('da_rename_'.$id);daSend('نام کاربری جدید انگلیسی را بفرستید.',[daBtn('انصراف','da_home')]);break;
                case 'resetask':daSend('رمز قبلی نامعتبر و نشست‌ها حذف شوند؟',[daBtn('تأیید','da_reset_'.$id),daBtn('انصراف','da_u_'.$id)]);break;
                case 'reset':
                    if(!daAccount($connection,$id))throw new InvalidArgumentException('کاربر یافت نشد.');
                    $p=daPassword();$connection->begin_transaction();try{daExec($connection,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($p,PASSWORD_DEFAULT),$id);daExec($connection,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);daExec($connection,'DELETE FROM delta_app_devices WHERE account_id=?','i',$id);$connection->commit();}catch(Throwable $e){$connection->rollback();throw $e;}
                    daSend("رمز تازه (فقط این بار): <code>".$p."</code>",[daBtn('بازگشت','da_u_'.$id)]);break;
                default:daUser($connection,$id);
            }return true;
        }
        $hints=['da_addplan'=>'نام پلن | گیگ | روز | قیمت تومان | تعداد دستگاه'."\n".'مثال: 10GB | 10 | 30 | 100000 | 1','da_addstock'=>'فقط لینک ساب را ارسال کنید. چند لینک را در خط‌های جدا یا پیام‌های جدا بفرستید (حداکثر ۲۰ ساب).'];
        if(isset($hints[$cb])){setUser($cb);daSend($hints[$cb],[daBtn('انصراف','da_home')]);return true;}
        if($cb!==''||!isset($update->message->text))return true;
        if($step==='da_catadd'||preg_match('/^da_catedit_(\d+)$/D',$step,$m)){
            if($input===''||strlen($input)>240||mb_strlen($input)>80)throw new InvalidArgumentException('نام دسته نامعتبر است.');
            if($step==='da_catadd')daExec($connection,'INSERT INTO delta_app_categories(name) VALUES(?)','s',$input);else daExec($connection,'UPDATE delta_app_categories SET name=? WHERE id=?','si',$input,(int)$m[1]);
            setUser();daSend('ذخیره شد.',[daBtn('دسته‌بندی‌ها','da_cats')]);
        }elseif(preg_match('/^da_limit_(\d+)$/D',$step,$m)){
            if(!ctype_digit($input))throw new InvalidArgumentException('عدد وارد کنید.');
            daSetDeviceLimit($connection,(int)$m[1],(int)$input);setUser();daUser($connection,(int)$m[1]);
        }elseif($step==='da_wname'){
            if($input===''||mb_strlen($input)>80||preg_match('/[\x00-\x1f\x7f]/u',$input))throw new InvalidArgumentException('نام پلن باید یک خط و حداکثر ۸۰ کاراکتر باشد.');
            daExec($connection,'UPDATE delta_app_admin_draft SET name=? WHERE id=1','s',$input);setUser('da_wgb');daSend('حجم چند گیگابایت باشد؟',[daBtn('انصراف','da_menuplans')]);
        }elseif(in_array($step,['da_wgb','da_wdays','da_wprice','da_wdevices'],true)){
            $fields=['da_wgb'=>['gb',1,100000,'da_wdays','چند روز؟'],'da_wdays'=>['days',1,3650,'da_wprice','قیمت به تومان؟'],'da_wprice'=>['price',1,1000000000,'da_wdevices','چند کاربر؟'],'da_wdevices'=>['devices',1,20,'da_wcat','دسته‌بندی را انتخاب کنید.']];
            [$field,$min,$max,$next,$hint]=$fields[$step];
            if(!ctype_digit($input)||(int)$input<$min||(int)$input>$max)throw new InvalidArgumentException('عدد معتبر وارد کنید.');
            daExec($connection,"UPDATE delta_app_admin_draft SET $field=? WHERE id=1",'i',(int)$input);setUser($next);$rows=[];
            if($next==='da_wcat')foreach(daRows($connection,'SELECT * FROM delta_app_categories ORDER BY id') as $r)$rows[]=daBtn($r['name'],'da_wcat_'.$r['id']);
            $rows[]=daBtn('انصراف','da_menuplans');daSend($hint,$rows);
        }elseif($step==='da_refinterval'){
            if(!preg_match('/^\d{1,4}$/D',$input))throw new InvalidArgumentException('عدد دقیقه را وارد کنید.');
            daRefreshInterval($connection,(int)$input);setUser();daSend('✅ فاصله به‌روزرسانی ذخیره شد.',[daBtn('بازگشت','da_refresh')]);
        }elseif(in_array($step,['da_contact_purchase','da_contact_support'],true)){
            daContactSet($connection,substr($step,11),$input);setUser();daSend('✅ آیدی ذخیره شد.',[daBtn('آیدی‌های برنامه','da_contacts'),daBtn('بازگشت','da_home')]);
        }elseif(preg_match('/^da_paneledit_(\d+)$/D',$step,$m)){
            bot('deleteMessage',['chat_id'=>$chat_id,'message_id'=>$message_id]);
            $id=(int)$m[1];$lines=preg_split('/\r\n|\r|\n/',(string)$text);
            if(count($lines)!==4)throw new InvalidArgumentException('چهار خط وارد کنید.');
            $old=daPgServer($connection,$id);$s=daPanelInput(trim($lines[0]),trim($lines[1]),$lines[2]==='-'?$old['password']:$lines[2],trim($lines[3]));
            daEditPanel($connection,$id,$s);setUser();daSend('✅ پنل و هویت حساب‌های قبلی بررسی و ذخیره شد.',[daBtn('بازگشت','da_panelview_'.$id)]);
        }elseif($step==='da_pgadd'){
            bot('deleteMessage',['chat_id'=>$chat_id,'message_id'=>$message_id]);
            $lines=preg_split('/\r\n|\r|\n/',(string)$text);
            if(count($lines)<3||count($lines)>4)throw new InvalidArgumentException('فرمت: آدرس پنل، نام مدیر، رمز، دامنه ساب؛ شماره گروه لازم نیست.');
            $s=daPanelInput(trim($lines[0]),trim($lines[1]),$lines[2],trim($lines[3]??''));
            $d=daPanelDraft($connection,$s);setUser('da_pgpick_'.$d['ticket']);daPgGroupButtons($d['ticket'],$d['groups']);
        }elseif($step==='da_search'||str_starts_with($step,'da_searchq_')){
            $prefix=strtolower($input);if(!preg_match('/^[a-z0-9_]{1,48}$/D',$prefix))throw new InvalidArgumentException('نام کاربری انگلیسی را وارد کنید.');
            setUser('da_searchq_'.$prefix);daSearchResults($connection,$prefix);
        }elseif($step==='da_addplan'){
            $a=array_map('trim',explode('|',$input));if(count($a)!==5||!ctype_digit($a[4])||(int)$a[4]<1||(int)$a[4]>20||$a[0]===''||strlen($a[0])>80||!ctype_digit($a[1])||!ctype_digit($a[2])||!ctype_digit($a[3])||(int)$a[1]<1||(int)$a[1]>100000||(int)$a[2]<1||(int)$a[2]>3650||(int)$a[3]>1000000000)throw new InvalidArgumentException('فرمت یا مقادیر پلن نامعتبر است.');
            daExec($connection,'INSERT INTO delta_app_plans(name,gb,days,price,device_limit) VALUES(?,?,?,?,?)','siiii',$a[0],(int)$a[1],(int)$a[2],(int)$a[3],(int)$a[4]);setUser();daList($connection,'plans',0);
        }elseif($step==='da_addstock'){
            $urls=array_values(array_unique(preg_split('/\s+/',trim($input),-1,PREG_SPLIT_NO_EMPTY)));
            if(!$urls||count($urls)>20)throw new InvalidArgumentException('۱ تا ۲۰ لینک ارسال کنید.');
            foreach($urls as $url)if(!daValidUrl($url))throw new InvalidArgumentException('فقط لینک ساب با ساختار مجاز ارسال کنید.');
            $ok=0;$report=[];
            // Bound webhook latency: process at most 3 links per message; accept further messages.
            if(count($urls)>3){daSend('برای جلوگیری از قطع وبهوک، حداکثر ۳ لینک در هر پیام بفرستید. مجموعاً ۲۰ ساب قابل ثبت است.');return true;}
            bot('deleteMessage',['chat_id'=>$chat_id,'message_id'=>$message_id]);
            foreach($urls as $ix=>$url){
                try{$nodes=daFetchSource($url);daSaveSource($connection,$url,$nodes);$ok++;$report[]='ساب '.($ix+1).': '.count($nodes).' سرور ثبت شد.';}
                catch(InvalidArgumentException $e){$report[]='ساب '.($ix+1).': '.$e->getMessage();}
                catch(Throwable $e){$report[]='ساب '.($ix+1).': خطای ذخیره یا پردازش (کد '.(int)$e->getCode().').';}
            }
            daSend(($ok>0?"✅ ":"❌ ").$ok." ساب ثبت شد.\n".daEsc(implode("\n",$report))."\nلینک بعدی را بفرستید یا فهرست را باز کنید.",[daBtn('فهرست ساب‌ها','da_stock_0'),daBtn('بازگشت','da_home')]);
        }elseif(preg_match('/^da_rename_(\d+)$/D',$step,$m)){
            if(!daName($input))throw new InvalidArgumentException('نام کاربری نامعتبر است.');
            $id=(int)$m[1];if(daPgMap($connection,$id))throw new InvalidArgumentException('تغییر نام حساب اختصاصی فعلاً غیرفعال است.');$connection->begin_transaction();try{daExec($connection,'UPDATE delta_app_accounts SET username=? WHERE id=?','si',$input,$id);daExec($connection,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);$connection->commit();}catch(Throwable $e){$connection->rollback();throw $e;}setUser();daUser($connection,$id);
        }
    }catch(InvalidArgumentException $e){daSend(daEsc($e->getMessage()),[daBtn('بازگشت',$buyPreview?'dar_home':'da_home')]);}
    catch(Throwable $e){if($step==='da_pgadd'||$step==='da_pgset'){daSend('اتصال یا ثبت پنل ناموفق بود؛ اگر آدرس پنل IP است، دامنه معتبر SSL را در خط پنجم بفرستید. اطلاعات مدیر و دسترسی گروه را هم بررسی کنید.',[daBtn('افزودن دوباره','da_pgadd'),daBtn('بازگشت','da_pg')]);return true;}if($buyPreview){error_log('Delta checkout: '.get_class($e).' code='.(int)$e->getCode());daSend('عملیات خرید کامل نشد؛ وضعیت فاکتور را بررسی کنید و در صورت پرداخت دوباره واریز نکنید.',[daBtn('سرویس‌های من','dar_mine'),daBtn('بازگشت','dar_home')]);return true;}daSend('عملیات انجام نشد. احتمال تکراری بودن نام یا لینک، یا خطای دیتابیس وجود دارد.',[daBtn('بازگشت','da_home')]);}
    return true;
}

function daRealInvoiceView(mysqli $db,int $buyer,int $id): void {
    $o=daRealOrder($db,$buyer,$id);$rows=[];
    $status=['pending'=>'در انتظار پرداخت','paid'=>'پرداخت شده','provisioning'=>'در حال تحویل','delivered'=>'تحویل شده','cancelled'=>'لغو شده'];
    $txt="🧾 فاکتور خرید دلتا\n〰〰〰〰〰〰〰\n".daEsc($o['name'])."\n🔹حجم: ".$o['gb']." گیگ\n🔹اعتبار: ".$o['days']." روز\n🔹دستگاه: ".$o['device_limit']."\n💵مبلغ: ".number_format((int)$o['price'])." تومان\n〰〰〰〰〰〰〰\n🔖کد پیگیری: <code>".daEsc(deltaTrackingCode($o['pay_hash']))."</code>\n⏳وضعیت: ".($status[$o['state']]??'نامشخص')."\n➖➖➖➖➖➖➖";
    $p=daRealPayByHash($db,(string)$o['pay_hash']);
    if(!$p)throw new RuntimeException('payment missing');
    if(in_array($p['state'],['declined','rejected','cancelled_by_user'],true)){
        $txt.="\nوضعیت پرداخت: ".daEsc(deltaOrderStateLabel($p['state']));
    }elseif($p['state']==='have_sent'){
        $txt.="\n📨 رسید ثبت شده؛ در انتظار بررسی بانک یا مدیریت. دوباره واریز نکنید.";

    }elseif($o['state']==='pending'&&$p['state']==='pending'&&(int)$o['created']>=time()-3600){
        if(($GLOBALS['botState']['cartToCartState']??'off')==='on')$rows[]=daBtn('💳 کارت‌به‌کارت','dar_card_'.$id);
        if($o['method']!=='card'){
            if(($GLOBALS['botState']['walletState']??'off')==='on')$rows[]=daBtn('💰 پرداخت با موجودی','dar_walletask_'.$id);
            $rows[]=daBtn('🔄 تغییر پلن','dar_change_'.$id);
            $rows[]=daBtn('❌ لغو فاکتور','dar_cancel_'.$id);
        }
    }
    if($p['state']==='pending'&&(int)$o['created']<time()-3600)$txt.="\nمهلت پرداخت تمام شده؛ اگر واریز کرده‌اید فاکتور را به مدیریت اطلاع دهید.";
    if(in_array($p['state'],['paid','approved','paid_with_wallet'],true)&&$o['state']==='pending'){
        $txt.="\n✅ پرداخت ثبت شده؛ تحویل در انتظار تکمیل است.";
        $rows[]=daBtn('🔄 ادامه تحویل','dar_deliver_'.$id);
    }
    if(in_array($o['state'],['paid','provisioning'],true))$rows[]=daBtn('🔄 ادامه تحویل','dar_deliver_'.$id);
    if($o['state']==='delivered')$rows[]=daBtn('👤 مشاهده سرویس','dar_service_'.$id);
    $rows[]=daBtn('بازگشت','dar_home');daSend($txt,$rows);
}
function daRealServiceView(mysqli $db,int $buyer,int $id): void {
    $o=daRealOrder($db,$buyer,$id);$a=daAccount($db,(int)$o['account_id']);
    if($o['state']!=='delivered'||!$a){daSend('حساب آماده یا موجود نیست.',[daBtn('بازگشت','dar_mine')]);return;}
    $status='نامشخص';$usage='نامشخص';$limit=(int)$a['gb']*1073741824;$expiry=(int)$a['expires'];
    try{$v=daPgLive($db,$a);$status=$v['can_connect']?'فعال':'غیرفعال';$usage=number_format($v['used_bytes']/1073741824,3).' GB';$limit=$v['limit_bytes'];$expiry=$v['expires'];}catch(Throwable $e){}
    $txt="👤 <code>".daEsc($a['username'])."</code>\nاشتراک: ".daEsc($o['name'])."\nشماره سفارش: ".daEsc($o['pay_hash'])."\nوضعیت: ".$status."\nحجم: ".number_format($limit/1073741824,3)." GB\nمصرف: ".$usage."\nتاریخ خرید: ".daIranDate((int)$o['approved_at'])."\nانقضا: ".daIranDate($expiry)."\nدستگاه: ".count(daDeviceList($db,(int)$a['id']))." / ".$a['device_limit'];
    daSend($txt,[daBtn('📱 دستگاه‌های متصل','dar_devices_'.$id),daBtn('🔑 تغییر رمز','dar_resetask_'.$id),daBtn('بازگشت','dar_mine')]);
}
function daRealDevicesView(mysqli $db,int $buyer,int $id): void {
    $o=daRealOrder($db,$buyer,$id);$a=daAccount($db,(int)$o['account_id']);
    if($o['state']!=='delivered'||!$a)throw new InvalidArgumentException('حساب یافت نشد.');
    $items=daDeviceList($db,(int)$a['id']);$rows=[];$msg='📱 دستگاه‌ها: '.count($items).' / '.$a['device_limit'];
    foreach($items as $item){$msg.="\n".daEsc($item['model'])." | ".daIranDate($item['last_seen'])." | مصرف مستقل: نامشخص";$rows[]=daBtn('🚪 خروج '.$item['model'],'dar_kickask_'.$id.'_'.$item['id']);}
    $rows[]=daBtn('بازگشت','dar_service_'.$id);daSend($msg,$rows);
}


/** Isolate app receipt callbacks from legacy server_plans provisioning. */
function daRealReceiptHandle(): bool {
    global $connection,$from_id,$admin,$chat_id,$isChildBot,$update,$data,$text,$userInfo,$botToken,$botState,$buttonValues;
    if(!empty($isChildBot))return false;
    $cb=(string)($data??'');$step=(string)($userInfo['step']??'');
    $hash='';$kind='';$display=0;$action='';
    if(preg_match('/^pay(Photo|Text)Receipt(.+)$/D',$cb,$m)){
        $ref=deltaCardParseReceiptRef($m[2]);$hash=(string)$ref['hash'];$display=(int)$ref['id'];$kind=strtolower($m[1]);$action='prompt';
    }elseif(preg_match('/^deltaCardSwitch(.+)~([0-9]+)$/D',$cb,$m)){
        $hash=$m[1];$action='switch';
    }elseif(preg_match('/^(?:accept|accCustom|approvePayment|payWithWallet|payCustomWithWallet|payWithCartToCart|payCustomWithCartToCart|payWithTronWallet|payWithWeSwap|payWithUsdt|havePaiedWeSwap|approveRenewAcc|approveIncreaseDay|approveIncreaseVolume|approvePgRenew)(DA[A-Za-z0-9]{7})$/D',$cb,$m)){
        $hash=$m[1];$action='legacy';
    }elseif(preg_match('/^dar_receipt_(photo|text)_(DA[A-Za-z0-9]{7})$/D',$step,$m)){
        if($cb!==''||trim((string)$text)==='/start'||trim((string)$text)==='/buy'||(string)$text===(string)($buttonValues['cancel']??'انصراف')){
            setUser();return false;
        }
        $kind=$m[1];$hash=$m[2];$action='submit';
    }else return false;
    $p=daRealPayByHash($connection,$hash);
    if(!$p)return false;
    if((int)$from_id!==(int)$admin||(int)$chat_id!==(int)$admin||(int)$p['user_id']!==(int)$admin||
        !is_string($botToken??null)||!hash_equals(hash('sha256',$botToken),(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))){
        return true;
    }
    try{
        daRealSchema($connection);
        $o=daRow($connection,'SELECT * FROM delta_app_real_orders WHERE pay_hash=? AND buyer=?','si',$hash,(int)$from_id);
        if(!$o)throw new RuntimeException('order missing');
        if($action==='legacy'){
            // Old approval buttons may appear in the general pending-receipt UI.
            // Route them to a real bank confirmation rather than normal VPN delivery.
            if($cb==='accept'.$hash||$cb==='accCustom'.$hash){
                sendMessage('ابتدا واریز بانکی را بررسی کنید.',daKeys([daBtn('بررسی فاکتور','dar_bankask_'.$o['id'])]),'HTML');
            }else answerQuery('از فاکتور اختصاصی دلتا استفاده کنید.',true);
            return true;
        }
        if($p['state']!=='pending'||$o['state']!=='pending'||$o['method']!=='card')throw new InvalidArgumentException('فاکتور دیگر قابل پرداخت نیست.');
        if($action==='switch'){
            if((int)$o['created']<time()-3600||($botState['cartToCartState']??'off')!=='on')throw new InvalidArgumentException('مهلت پرداخت تمام شده است.');
            deltaCardSwitchAction($cb);return true;
        }
        if($action==='prompt'){
            if($display<=0||!deltaCardBindReceipt($hash,$display,(int)$from_id))throw new InvalidArgumentException('شماره کارت معتبر نیست.');
            setUser('dar_receipt_'.$kind.'_'.$hash);
            answerQuery();
            sendMessage($kind==='photo'?deltaCardPhotoPrompt():deltaCardSmsPrompt(),deltaCardReceiptCancelKeys(),null);return true;
        }
        $photo='';
        if($kind==='photo'){
            $photos=$update->message->photo??null;
            if(!is_array($photos)||!$photos){sendMessage('لطفاً عکس واریزی را بفرستید.');return true;}
            $last=end($photos);$photo=(string)($last->file_id??'');
            if($photo==='')throw new InvalidArgumentException('عکس معتبر نیست.');
        }elseif(!isset($update->message->text)||trim((string)$text)===''){
            sendMessage('لطفاً متن پیامک واریزی را بفرستید.');return true;
        }
        $at=min(time(),(int)($_SERVER['REQUEST_TIME']??time()));
        if(!daRealRegisterReceipt($connection,$hash,(int)$from_id,$kind,$at))throw new RuntimeException('receipt already submitted');
        setUser();
        $msg="📨 رسید خرید برنامه دلتا\n".deltaUserShortInfo((int)$from_id)."\n📦 پلن: ".daEsc($o['name'])."\n💰 مبلغ: ".number_format((int)$o['price'])." تومان\n".deltaTrackingLine($hash);
        if($kind==='text')$msg.="\n📝 ".daEsc(mb_substr((string)$text,0,1500));
        $keys=daKeys([daBtn('🔎 بررسی و تأیید واریزی','dar_bankask_'.$o['id']),daBtn('🧾 وضعیت فاکتور','dar_invoice_'.$o['id'])]);
        $res=$kind==='photo'?sendPhotoToAdmins($photo,$msg,$keys,'HTML',$hash):sendToAdmins($msg,$keys,'HTML',null,$hash);
        if(is_object($res)&&!empty($res->ok)){
            $mid=(int)($res->result->message_id??0);$cid=(int)($res->result->chat->id??0);
            if($mid>0&&$cid!==0)daExec($connection,"UPDATE pays SET message_id=?,chat_id=? WHERE hash_id=? AND type='DELTA_APP_BUY'",'iis',$mid,$cid,$hash);
        }
        sendMessage('✅ رسید ثبت شد. منتظر بررسی واریز باشید؛ دوباره پرداخت نکنید.',daKeys([daBtn('🧾 وضعیت فاکتور','dar_invoice_'.$o['id'])]),'HTML');return true;
    }catch(Throwable $e){
        error_log('Delta app receipt: '.get_class($e));
        $msg=$e instanceof InvalidArgumentException?$e->getMessage():'عملیات کامل نشد؛ وضعیت فاکتور را بررسی کنید و دوباره واریز نکنید.';
        if($cb!=='')answerQuery($msg,true);else sendMessage($msg);return true;
    }
}
