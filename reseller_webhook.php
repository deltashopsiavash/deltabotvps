<?php
/**
 * Reseller Telegram webhook registration and readback.
 * No CLI/background worker dependency and no optimistic success messages.
 * This file is intentionally usable from pure PHP tests without loading config.php.
 */
function deltaResellerWebhookCandidates($rid,$motherWebhook,$configuredBase,$requestHost='',$requestScript='',$https=false){
    $rid=(int)$rid;
    if($rid<=0)return [];
    $candidates=[];
    $add=function($url)use(&$candidates){
        if(!filter_var($url,FILTER_VALIDATE_URL))return;
        $p=parse_url($url);
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host']))return;
        // Telegram webhook requires public HTTPS; do not accept credentials/fragments.
        if(isset($p['user'])||isset($p['pass'])||isset($p['fragment']))return;
        if(filter_var($p['host'],FILTER_VALIDATE_IP))return;
        if(!in_array($url,$candidates,true))$candidates[]=$url;
    };
    $make=function($basePath,$baseOrigin)use($rid,$add){
        $path=rtrim((string)$basePath,'/');
        if($path===''||$path==='.')$path='';
        if(!preg_match('~/bot\.php$~i',$path))$path.='/bot.php';
        $add(rtrim($baseOrigin,'/').$path.'?bid='.$rid);
    };
    // The parent bot is receiving updates through this Telegram-confirmed URL.
    // Use its origin AND path prefix even if it is not literally named bot.php.
    $parent=parse_url(trim((string)$motherWebhook));
    if(is_array($parent)&&strtolower((string)($parent['scheme']??''))==='https'
        && !empty($parent['host']) && !isset($parent['user']) && !isset($parent['pass'])){
        $host=(string)$parent['host'];
        $origin='https://'.$host.(isset($parent['port'])?':'.(int)$parent['port']:'');
        $path=(string)($parent['path']??'/bot.php');
        if(preg_match('~/bot\.php$~i',$path))$make($path,$origin);
        else {
            $dir=dirname($path);
            $dir=($dir==='/'||$dir==='.')?'':$dir;
            $make($dir,$origin);
        }
    }
    // The incoming request can also reveal the working public vhost, but
    // only on a real HTTPS request from the verified Telegram webhook handler.
    if($https && preg_match('/^[a-z0-9.-]+(?::443)?$/i',trim((string)$requestHost))){
        $script=(string)$requestScript;
        $dir=dirname($script);
        $dir=($dir==='/'||$dir==='.')?'':$dir;
        $make($dir,'https://'.trim((string)$requestHost));
    }
    $base=trim((string)$configuredBase);
    if($base!==''){
        $p=parse_url($base);
        if(is_array($p) && strtolower((string)($p['scheme']??''))==='https' && !empty($p['host'])){
            $root='https://'.$p['host'].(isset($p['port'])?':'.(int)$p['port']:'');
            $path=(string)($p['path']??'');
            $make($path,$root);
        }
    }
    return $candidates;
}

/**
 * Verify the child has its own database and Telegram accepts the new webhook.
 * Never return ok=true just because the outbound HTTP request was attempted.
 */
function deltaResellerWebhookSecret($token){
    // config.php::check() recognizes this bot-specific Telegram secret before
    // doing source-IP checks. This fixes silent /start behind CDN proxies.
    return hash('sha256',(string)$token);
}
function deltaResellerWebhookSetup($rid){
    global $connection,$botUrl,$botToken;
    $rid=(int)$rid;
    if($rid<=0)return ['ok'=>false,'error'=>'شناسهٔ نمایندگی معتبر نیست'];
    $st=$connection->prepare("SELECT bot_token,db_name,status,admin_userid FROM reseller_bots WHERE id=? AND is_deleted=0 LIMIT 1");
    if(!$st)return ['ok'=>false,'error'=>'اطلاعات نمایندگی از دیتابیس خوانده نشد'];
    $st->bind_param('i',$rid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    if(!$row||empty($row['bot_token']))return ['ok'=>false,'error'=>'توکن ربات نمایندگی ثبت نشده'];
    if((int)($row['admin_userid']??0)<=0)return ['ok'=>false,'error'=>'آیدی مدیر نمایندگی هنوز ثبت نشده'];
    if(empty($row['db_name'])||!deltaResellerDbHealthy($row['db_name']))
        return ['ok'=>false,'error'=>'ساختار دیتابیس نمایندگی تکمیل نشده؛ دسترسی دیتابیس سرور را بررسی کنید'];

    $parentInfo=botWithToken((string)$botToken,'getWebhookInfo',[]);
    $parentUrl=(is_array($parentInfo) && !empty($parentInfo['ok']))?
        (string)($parentInfo['result']['url']??''):'';
    $host=(string)($_SERVER['HTTP_HOST']??'');
    $script=(string)($_SERVER['SCRIPT_NAME']??'');
    $forwardedProto=strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??'')));
    $https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')
        ||(int)($_SERVER['SERVER_PORT']??0)===443
        // Reverse-proxy TLS termination may send the verified mother webhook
        // over HTTP to PHP while presenting a public HTTPS hostname.
        ||$forwardedProto==='https';
    // Optional explicit HTTPS override set by server owner, without changing botUrl.
    $custom=trim((string)(getenv('DELTA_RESELLER_WEBHOOK_BASE_URL')?:''));
    $bases=$custom!==''?[$custom,$botUrl]:[$botUrl];
    $candidates=[];
    foreach($bases as $base){
        foreach(deltaResellerWebhookCandidates($rid,$parentUrl,$base,$host,$script,$https) as $candidate){
            if(!in_array($candidate,$candidates,true))$candidates[]=$candidate;
        }
    }
    if(!$candidates)return ['ok'=>false,'error'=>'آدرس HTTPS قابل استفاده برای اتصال نمایندگی پیدا نشد'];

    $last='آدرس وب‌هوک قابل ثبت نیست';
    foreach($candidates as $url){
        $res=botWithToken((string)$row['bot_token'],'setWebhook',
            ['url'=>$url,
             'secret_token'=>deltaResellerWebhookSecret($row['bot_token']),
             'drop_pending_updates'=>'false',
             'allowed_updates'=>json_encode([])]);
        if(!is_array($res)||empty($res['ok'])){
            $last=(string)($res['description']??'درخواست تلگرام پاسخ موفق نداشت');
            continue;
        }
        $info=botWithToken((string)$row['bot_token'],'getWebhookInfo',[]);
        if(!is_array($info)||empty($info['ok'])){
            $last='ثبت انجام شد اما وضعیت اتصال از تلگرام دریافت نشد';
            continue;
        }
        $registered=(string)($info['result']['url']??'');
        if($registered!==$url){
            $last='نشانی ثبت‌شده در تلگرام با آدرس برنامه مطابقت ندارد';
            continue;
        }
        return ['ok'=>true,'url'=>$url,'pending'=>(int)($info['result']['pending_update_count']??0),
            'last_error'=>(string)($info['result']['last_error_message']??'')];
    }
    error_log("Reseller #".$rid." Telegram webhook failed; last error: ".$last);
    if(stripos($last,'Failed to resolve host')!==false ||
       stripos($last,'Name or service not known')!==false){
        $last='دامنهٔ فعلی ربات از سمت تلگرام قابل دسترس نیست. دامنهٔ فعال ربات مادر یا آدرس HTTPS صحیح سرور باید برای اتصال استفاده شود.';
    }
    return ['ok'=>false,'error'=>$last];
}
