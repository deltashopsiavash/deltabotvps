<?php
require_once __DIR__.'/delta_app_core.php';
function daEsc($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function daKeys(array $rows): string {return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);}
function daBtn(string $name,string $cb): array {return [['text'=>$name,'callback_data'=>$cb]];}
function daSend(string $txt,array $rows=[]): void {sendMessage($txt,$rows?daKeys($rows):null,'HTML');}
function daHome(): void {
    global $botUrl;
    $endpoint=rtrim((string)$botUrl,'/').'/delta_app_api.php';
    daSend("🔐 <b>مدیریت برنامه دلتا VPN</b>\nفروش عمومی غیرفعال است.\nAPI: <code>".daEsc($endpoint)."</code>\nهر حساب جدید دارای ساب اختصاصی پسارگارد است.",[
        daBtn('📦 پلن‌ها','da_plans_0'),daBtn('➕ ساخت پلن','da_addplan'),
        daBtn('🖥 پنل‌های اختصاصی برنامه','da_pg'),daBtn('➕ افزودن پنل برنامه','da_pgadd'),daBtn('⚙️ انتخاب پنل و گروه','da_pgset'),
        daBtn('👥 کاربران','da_users_0'),daBtn('➕ ساخت کاربر','da_adduser'),daBtn('⚙️ آیدی خرید و پشتیبانی','da_contacts')]);
}
function daList(mysqli $db,string $kind,int $page): void {
    $off=$page*8;$rows=[];$txt='';
    if($kind==='plans'){
        $txt="📦 پلن‌ها\n";
        $items=daRows($db,'SELECT * FROM delta_app_plans ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
        foreach(array_slice($items,0,8) as $p){$id=(int)$p['id'];$txt.="\n#$id | ".daEsc($p['name'])." | ".$p['gb']." GB | ".$p['days']." روز | ".number_format((int)$p['price'])." تومان";$rows[]=daBtn(($p['active']?'غیرفعال':'فعال')." #$id",'da_pt_'.$id);}
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
    $rows[]=daBtn('بازگشت','da_home');daSend($txt,$rows);
}
function daChoosePlan(mysqli $db,int $page=0): void {
    $off=$page*8;$items=daRows($db,'SELECT id,name,gb,days FROM delta_app_plans WHERE active=1 ORDER BY id DESC LIMIT 9 OFFSET ?','i',$off);
    $rows=[];
    foreach(array_slice($items,0,8) as $p)$rows[]=daBtn($p['name'].' | '.$p['gb'].' GB | '.$p['days'].' روز','da_make_'.$p['id']);
    if($page>0)$rows[]=daBtn('قبلی','da_choose_'.($page-1));
    if(count($items)>8)$rows[]=daBtn('بعدی','da_choose_'.($page+1));
    $rows[]=daBtn('بازگشت','da_home');
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
    if($m&&$m['state']==='ready'){try{$v=daPgLive($db,$a);$traffic='مصرف: '.$fmt($v['used_bytes'])."\nباقی‌مانده: ".$fmt($v['remaining_bytes']);$status=(!$a['active']?'ورود غیرفعال؛ ': '').daEsc($v['panel_status']);}catch(Throwable $e){}}
    $rows=[daBtn('✏️ تغییر نام کاربری','da_rename_'.$id),daBtn('🔑 ساخت رمز جدید','da_resetask_'.$id)];
    if($m&&$m['state']==='pending')$rows[]=daBtn('🔄 بازیابی ساخت','da_pgretry_'.$id);
    if($m&&$m['state']==='ready'){$rows[]=daBtn('⛔ غیرفعال کردن / تلاش مجدد','da_pgdisable_'.$id);if(!$a['active'])$rows[]=daBtn('✅ فعال کردن','da_pgenable_'.$id);}
    $rows[]=daBtn('🗑 حذف کامل کاربر','da_delask_'.$id);$rows[]=daBtn('بازگشت','da_users_0');
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
    if(!$command&&!str_starts_with($cb,'da_')&&!str_starts_with($step,'da_'))return false;
    if(!empty($isChildBot)||(string)$from_id!==(string)$admin||(string)$chat_id!==(string)$admin)return false;
    // A hidden command alone is not authentication. Require the actual Telegram webhook secret.
    if(!is_string($botToken??null)||!hash_equals(hash('sha256',$botToken),(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??''))){
        return true;
    }
    if($cb!==''&&isset($update->callback_query->id))answerQuery();
    try{
        daSchema($connection);
        daExec($connection,'DELETE FROM delta_app_panel_drafts WHERE expires<=?','i',time());
        if(str_starts_with($step,'da_pgpick_')&&($command||in_array($cb,['da_home','da_pg','da_pgadd','da_pgset'],true)||($cb===''&&$input==='/start'))){daExec($connection,'DELETE FROM delta_app_panel_drafts WHERE ticket=?','s',substr($step,10));}
        if($command||$cb==='da_home'){setUser();daHome();return true;}
        if($input==='/start'&&$cb===''){setUser();return false;}
        if($cb!==''&&!str_starts_with($cb,'da_')){setUser();return false;}
        if($cb==='da_contacts'){
            setUser();$c=daContacts($connection);
            daSend("آیدی‌های برنامه:\nخرید: ".($c['purchase']===''?'تنظیم نشده':'@'.daEsc($c['purchase']))."\nپشتیبانی: ".($c['support']===''?'تنظیم نشده':'@'.daEsc($c['support'])),[daBtn('تنظیم آیدی خرید','da_contact_purchase'),daBtn('تنظیم آیدی پشتیبانی','da_contact_support'),daBtn('بازگشت','da_home')]);return true;
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
        if($cb==='da_pg'||$cb==='da_pgset'){
            $setting=daRow($connection,'SELECT * FROM delta_app_pg_settings WHERE id=1');
            $txt="🖥 پنل‌های مستقل برنامه:\n".($setting&&$setting['panel_kind']==='app'?'انتخاب فعلی #'.$setting['server_id'].' | گروه #'.$setting['group_id']:'پنل مستقل انتخاب نشده');
            $items=daRows($connection,'SELECT id,panel_url,username,group_id FROM delta_app_panels ORDER BY id LIMIT 20');
            $rows=[];
            foreach($items as $r){$txt.="\n#".$r['id']." | ".daEsc($r['username']);$rows[]=daBtn('#'.$r['id'].' | '.$r['username'],'da_pgpanel_'.$r['id']);}
            if(!$items)$txt.="\nهنوز پنلی ثبت نشده است.";
            else $txt.="\nبرای انتخاب گروه، روی نام پنل بزنید.";
            setUser();$rows[]=daBtn('➕ افزودن پنل برنامه','da_pgadd');$rows[]=daBtn('بازگشت','da_home');daSend($txt,$rows);return true;
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
                case 'rename':setUser('da_rename_'.$id);daSend('نام کاربری جدید انگلیسی را بفرستید.',[daBtn('انصراف','da_home')]);break;
                case 'resetask':daSend('رمز قبلی نامعتبر و نشست‌ها حذف شوند؟',[daBtn('تأیید','da_reset_'.$id),daBtn('انصراف','da_u_'.$id)]);break;
                case 'reset':
                    if(!daAccount($connection,$id))throw new InvalidArgumentException('کاربر یافت نشد.');
                    $p=daPassword();$connection->begin_transaction();try{daExec($connection,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($p,PASSWORD_DEFAULT),$id);daExec($connection,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);$connection->commit();}catch(Throwable $e){$connection->rollback();throw $e;}
                    daSend("رمز تازه (فقط این بار): <code>".$p."</code>",[daBtn('بازگشت','da_u_'.$id)]);break;
                default:daUser($connection,$id);
            }return true;
        }
        $hints=['da_addplan'=>'نام پلن | گیگ | روز | قیمت تومان'."\n".'مثال: 10GB | 10 | 30 | 100000','da_addstock'=>'فقط لینک ساب را ارسال کنید. چند لینک را در خط‌های جدا یا پیام‌های جدا بفرستید (حداکثر ۲۰ ساب).'];
        if(isset($hints[$cb])){setUser($cb);daSend($hints[$cb],[daBtn('انصراف','da_home')]);return true;}
        if($cb!==''||!isset($update->message->text))return true;
        if(in_array($step,['da_contact_purchase','da_contact_support'],true)){
            daContactSet($connection,substr($step,11),$input);setUser();daSend('✅ آیدی ذخیره شد.',[daBtn('آیدی‌های برنامه','da_contacts'),daBtn('بازگشت','da_home')]);
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
            $a=array_map('trim',explode('|',$input));if(count($a)!==4||$a[0]===''||strlen($a[0])>80||!ctype_digit($a[1])||!ctype_digit($a[2])||!ctype_digit($a[3])||(int)$a[1]<1||(int)$a[1]>100000||(int)$a[2]<1||(int)$a[2]>3650||(int)$a[3]>1000000000)throw new InvalidArgumentException('فرمت یا مقادیر پلن نامعتبر است.');
            daExec($connection,'INSERT INTO delta_app_plans(name,gb,days,price) VALUES(?,?,?,?)','siii',$a[0],(int)$a[1],(int)$a[2],(int)$a[3]);setUser();daList($connection,'plans',0);
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
            $id=(int)$m[1];$connection->begin_transaction();try{daExec($connection,'UPDATE delta_app_accounts SET username=? WHERE id=?','si',$input,$id);daExec($connection,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);$connection->commit();}catch(Throwable $e){$connection->rollback();throw $e;}setUser();daUser($connection,$id);
        }
    }catch(InvalidArgumentException $e){daSend(daEsc($e->getMessage()),[daBtn('انصراف','da_home')]);}
    catch(Throwable $e){if($step==='da_pgadd'||$step==='da_pgset'){daSend('اتصال یا ثبت پنل ناموفق بود؛ اگر آدرس پنل IP است، دامنه معتبر SSL را در خط پنجم بفرستید. اطلاعات مدیر و دسترسی گروه را هم بررسی کنید.',[daBtn('افزودن دوباره','da_pgadd'),daBtn('بازگشت','da_pg')]);return true;}daSend('عملیات انجام نشد. احتمال تکراری بودن نام یا لینک، یا خطای دیتابیس وجود دارد.',[daBtn('بازگشت','da_home')]);}
    return true;
}
