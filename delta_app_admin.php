<?php
require_once __DIR__.'/delta_app_core.php';
function daEsc($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function daKeys(array $rows): string {return json_encode(['inline_keyboard'=>$rows],JSON_UNESCAPED_UNICODE);}
function daBtn(string $name,string $cb): array {return [['text'=>$name,'callback_data'=>$cb]];}
function daSend(string $txt,array $rows=[]): void {sendMessage($txt,$rows?daKeys($rows):null,'HTML');}
function daHome(): void {
    global $botUrl;
    $endpoint=rtrim((string)$botUrl,'/').'/delta_app_api.php';
    daSend("🔐 <b>مدیریت برنامه دلتا VPN</b>\nفروش عمومی غیرفعال است.\nAPI: <code>".daEsc($endpoint)."</code>\nساب‌های مشترک برای تمام حساب‌ها؛ حجم پلن فعلاً سقف اجرایی نیست.",[
        daBtn('📦 پلن‌ها','da_plans_0'),daBtn('➕ ساخت پلن','da_addplan'),
        daBtn('🔗 ساب‌های مشترک','da_stock_0'),daBtn('➕ افزودن اشتراک','da_addstock'),
        daBtn('👥 کاربران','da_users_0'),daBtn('➕ ساخت کاربر','da_adduser')]);
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
    $left=daRemainingSeconds((int)$a['expires']);
    $remaining=intdiv($left,86400).' روز و '.intdiv($left%86400,3600).' ساعت و '.intdiv($left%3600,60).' دقیقه و '.($left%60).' ثانیه';
    $status=!$a['active']?'غیرفعال':($left>0?'فعال':'منقضی');
    daSend("👤 <code>".daEsc($a['username'])."</code>\nشناسه: ".$a['id']."\nتاریخ ایجاد حساب: ".daIranDate((int)$a['created'])."\nتاریخ پایان: ".daIranDate((int)$a['expires'])."\nزمان باقی‌مانده: ".$remaining."\nحجم خریداری‌شده: ".$a['gb']." GB\nمصرف: قابل اندازه‌گیری مستقل نیست\nحجم باقی‌مانده: نامشخص\nوضعیت: ".$status."\nسرورها: فهرست مشترک\nرمز قبلی قابل مشاهده نیست.",[
        daBtn('✏️ تغییر نام کاربری','da_rename_'.$id),daBtn('🔑 ساخت رمز جدید','da_resetask_'.$id),
        daBtn($a['active']?'⛔ غیرفعال کردن ورود':'✅ فعال کردن ورود','da_ut_'.$id),
        daBtn('🗑 حذف کامل کاربر','da_delask_'.$id),daBtn('بازگشت','da_users_0')]);
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
        if($command||$cb==='da_home'){setUser();daHome();return true;}
        if($input==='/start'&&$cb===''){setUser();return false;}
        if($cb!==''&&!str_starts_with($cb,'da_')){setUser();return false;}
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
            $a=daAutoCreate($connection,(int)$m[1]);
            daSend("✅ حساب ساخته شد\nنام: <code>".daEsc($a['username'])."</code>\nرمز: <code>".daEsc($a['password'])."</code>\nحجم: ".$p['gb']." GB\nاعتبار: ".$p['days']." روز",[daBtn('➕ ساخت کاربر دیگر','da_adduser'),daBtn('کاربران','da_users_0')]);
            return true;
        }
        if(preg_match('/^da_(plans|stock|users)_(\d{1,5})$/D',$cb,$m)){setUser();daList($connection,$m[1],(int)$m[2]);return true;}
        if(preg_match('/^da_(pt|st|sf|sdask|sd|ut|u|rename|resetask|reset|delask|del)_(\d{1,10})$/D',$cb,$m)){
            $id=(int)$m[2];setUser();
            switch($m[1]){
                case 'pt': daExec($connection,'UPDATE delta_app_plans SET active=1-active WHERE id=?','i',$id);daList($connection,'plans',0);break;
                case 'st': daExec($connection,'UPDATE delta_app_sources SET active=1-active WHERE id=?','i',$id);daList($connection,'stock',0);break;
                case 'sf': $s=daRow($connection,'SELECT url FROM delta_app_sources WHERE id=?','i',$id);if(!$s)throw new InvalidArgumentException('ساب یافت نشد.');$nodes=daFetchSource($s['url']);daExec($connection,'UPDATE delta_app_sources SET payload=?,nodes=?,updated=? WHERE id=?','siii',json_encode($nodes,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),count($nodes),time(),$id);daList($connection,'stock',0);break;
                case 'sdask': daSend('این ساب از فهرست مشترک حذف شود؟',[daBtn('تأیید حذف','da_sd_'.$id),daBtn('انصراف','da_stock_0')]);break;
                case 'sd': daExec($connection,'DELETE FROM delta_app_sources WHERE id=?','i',$id);daList($connection,'stock',0);break;
                case 'delask':
                    $a=daAccount($connection,$id);if(!$a)throw new InvalidArgumentException('کاربر یافت نشد.');
                    daSend('حساب '.daEsc($a['username']).' و نشست‌های آن برای همیشه حذف شوند؟ ساب مشترک و اطلاعات فروش ربات تغییر نمی‌کنند.',[daBtn('تأیید حذف کامل','da_del_'.$id),daBtn('انصراف','da_u_'.$id)]);break;
                case 'del':
                    daSend(daDeleteAccount($connection,$id)?'✅ حساب و نشست‌های آن حذف شد.':'حساب قبلاً حذف شده است.');
                    daList($connection,'users',0);break;
                case 'ut': daExec($connection,'UPDATE delta_app_accounts SET active=1-active WHERE id=?','i',$id);daExec($connection,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);daUser($connection,$id);break;
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
        if($step==='da_search'||str_starts_with($step,'da_searchq_')){
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
    catch(Throwable $e){daSend('عملیات انجام نشد. احتمال تکراری بودن نام یا لینک، یا خطای دیتابیس وجود دارد.',[daBtn('بازگشت','da_home')]);}
    return true;
}
