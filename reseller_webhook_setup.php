<?php
/**
 * Telegram-only activation for reseller bots.  The surrounding purchase, bot
 * settings and database code is deliberately unchanged.
 *
 * The reference ZIP registers in a background worker and never checks setWebhook.
 * Here the same child bot.php?bid=ID endpoint is registered synchronously, with
 * the working mother bot webhook as the first URL candidate.
 */
function deltaResellerWebhookCandidates($motherUrl,$configuredBotUrl,$id){
    $id=(int)$id;
    if($id<1)return [];
    $urls=[];
    foreach([(string)$motherUrl,(string)$configuredBotUrl] as $i=>$source){
        $p=parse_url(trim($source));
        if(!is_array($p)||strtolower((string)($p['scheme']??''))!=='https'||empty($p['host']))
            continue;
        if(isset($p['user'])||isset($p['pass'])||isset($p['fragment']))
            continue;
        $host=(string)$p['host'];
        $origin='https://'.(strpos($host,':')!==false?'['.$host.']':$host).
            (isset($p['port'])?':'.(int)$p['port']:'');
        $path=(string)($p['path']??'/');
        // Mother webhook typically ends at bot.php; configured botUrl is a base.
        if($i===0){
            $directory=dirname($path);
            $directory=($directory==='/'||$directory==='.')?'':rtrim($directory,'/');
        }else{
            $directory=preg_match('~/bot\.php$~i',$path)?dirname($path):$path;
            $directory=($directory==='/'||$directory==='.')?'':rtrim($directory,'/');
        }
        $url=$origin.$directory.'/bot.php?bid='.$id;
        if(filter_var($url,FILTER_VALIDATE_URL)&&!in_array($url,$urls,true))$urls[]=$url;
    }
    return $urls;
}
function deltaResellerRegisterWebhook($rid){
    global $connection,$botUrl,$botToken;
    $rid=(int)$rid;
    if($rid<1)return ['ok'=>false,'message'=>'شناسه نمایندگی نامعتبر است'];
    $st=$connection->prepare("SELECT bot_token,db_name,admin_userid FROM reseller_bots WHERE id=? AND is_deleted=0 LIMIT 1");
    if(!$st)return ['ok'=>false,'message'=>'اطلاعات نمایندگی قابل خواندن نیست'];
    $st->bind_param('i',$rid);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
    if(!$row||empty($row['bot_token'])||(int)$row['admin_userid']<=0)
        return ['ok'=>false,'message'=>'توکن یا مدیر نمایندگی ثبت نشده است'];
    if(empty($row['db_name'])||!deltaResellerDbHealthy((string)$row['db_name']))
        return ['ok'=>false,'message'=>'دیتابیس اختصاصی نمایندگی آماده نیست'];
    // The mother bot is operational on its own registered webhook.  Prefer it
    // over a possibly outdated $botUrl in baseInfo.php.
    $mother=botWithToken((string)$botToken,'getWebhookInfo',[]);
    $motherUrl=is_array($mother)&&!empty($mother['ok'])?(string)($mother['result']['url']??''):'';
    $urls=deltaResellerWebhookCandidates($motherUrl,(string)$botUrl,$rid);
    if(!$urls)return ['ok'=>false,'message'=>'آدرس HTTPS معتبری برای وب‌هوک پیدا نشد'];
    $last='تلگرام اتصال را تأیید نکرد';
    foreach($urls as $url){
        // Telegram's secret header is verified by config.php:check().  Without
        // it a reverse proxy can make /start silently fail due to its source IP.
        $secret=hash('sha256',(string)$row['bot_token']);
        $result=botWithToken((string)$row['bot_token'],'setWebhook',[
            'url'=>$url,'secret_token'=>$secret,'drop_pending_updates'=>'false',
            'allowed_updates'=>json_encode(['message','callback_query','channel_post','edited_message'])
        ]);
        if(!is_array($result)||empty($result['ok'])){
            $last=(string)($result['description']??'ثبت Webhook توسط تلگرام رد شد');
            continue;
        }
        $info=botWithToken((string)$row['bot_token'],'getWebhookInfo',[]);
        if(!is_array($info)||empty($info['ok'])||
           (string)($info['result']['url']??'')!==$url){
            $last='آدرس Webhook از تلگرام قابل تأیید نیست';
            continue;
        }
        return ['ok'=>true,'url'=>$url];
    }
    error_log('Reseller #'.$rid.' setWebhook failed: '.$last);
    if(stripos($last,'Failed to resolve host')!==false||
       stripos($last,'Name or service not known')!==false)
        $last='آدرس عمومی وب‌هوک روی سرور یا دامنه قابل دسترس نیست؛ اتصال نمایندگی ثبت نشد';
    return ['ok'=>false,'message'=>$last];
}
