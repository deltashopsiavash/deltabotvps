<?php
declare(strict_types=1);
// CLI-only migration: keep all bot tokens, webhook URLs and pending updates unchanged.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$mode=$argv[1]??'--check';
if(!in_array($mode,['--check','--apply'],true)){fwrite(STDERR,"Usage: php scripts/configure-webhook-auth.php --check|--apply\n");exit(2);}
require dirname(__DIR__).'/baseInfo.php';
function deltaHookApi(string $token,string $method,array $data=[]): array {
    if(!preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',$token))throw new RuntimeException('invalid bot credential');
    $c=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data?:new stdClass(),JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);
    try{$raw=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);}finally{curl_close($c);}
    if(!is_string($raw)||$code!==200)throw new RuntimeException('Telegram API request failed');
    $j=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
    if(!is_array($j)||($j['ok']??false)!==true)throw new RuntimeException('Telegram API rejected request');
    return $j;
}
function deltaHookPlan(array $info,string $token): array {
    $url=$info['url']??'';$p=is_string($url)?parse_url($url):false;
    if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment']))throw new RuntimeException('no valid existing HTTPS webhook');
    if(!empty($info['has_custom_certificate']))throw new RuntimeException('custom certificate requires manual migration');
    $args=['url'=>$url,'secret_token'=>hash('sha256',$token),'drop_pending_updates'=>false];
    if(isset($info['allowed_updates'])&&is_array($info['allowed_updates']))$args['allowed_updates']=$info['allowed_updates'];
    if(isset($info['max_connections'])&&is_int($info['max_connections']))$args['max_connections']=$info['max_connections'];
    return $args;
}
try{
    $db=new mysqli('localhost',$dbUserName,$dbPassword,$dbName);$db->set_charset('utf8mb4');
    $bots=['mother'=>(string)$botToken];
    $table=$db->query("SHOW TABLES LIKE 'reseller_bots'");
    if($table&&$table->num_rows){
        $rows=$db->query("SELECT id,bot_token FROM reseller_bots WHERE status=1 AND is_deleted=0");
        while($row=$rows->fetch_assoc())$bots['reseller-'.(int)$row['id']]=(string)$row['bot_token'];
    }
    $plans=[];
    foreach($bots as $label=>$token){
        try{$info=deltaHookApi($token,'getWebhookInfo')['result'];$plans[$label]=deltaHookPlan($info,$token);echo $label.": READY\n";}
        catch(Throwable $e){echo $label.": CHECK_FAILED\n";exit(1);}
    }
    if($mode==='--check'){echo "WEBHOOK_PREFLIGHT_OK\n";exit;}
    foreach($plans as $label=>$args){
        try{
            $token=$bots[$label];$r=deltaHookApi($token,'setWebhook',$args);
            if(($r['result']??false)!==true)throw new RuntimeException('registration failed');
            $after=deltaHookApi($token,'getWebhookInfo')['result'];
            if(($after['url']??'')!==$args['url'])throw new RuntimeException('URL mismatch');
            echo $label.": REGISTERED\n";
        }catch(Throwable $e){echo $label.": APPLY_FAILED\n";exit(1);}
    }
    echo "WEBHOOK_AUTH_REGISTERED\n";
}catch(Throwable $e){fwrite(STDERR,"WEBHOOK_MIGRATION_FAILED\n");exit(1);}
