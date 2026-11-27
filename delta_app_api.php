<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function daRespond(int $code,array $body): never {http_response_code($code);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(!in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true))daRespond(403,['error'=>'https_required']);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST')daRespond(405,['error'=>'method']);
if((int)($_SERVER['CONTENT_LENGTH']??0)>4096)daRespond(413,['error'=>'size']);
$raw=file_get_contents('php://input',false,null,0,4097);
if(strlen($raw)>4096)daRespond(413,['error'=>'size']);
$j=json_decode($raw,true);
if(!is_array($j))daRespond(400,['error'=>'request']);
require_once __DIR__.'/baseInfo.php';
require_once __DIR__.'/delta_app_core.php';
try{
    $db=new mysqli('localhost',$dbUserName,$dbPassword,$dbName);$db->set_charset('utf8mb4');
    // Initialize via authenticated /delta, not through the public endpoint.
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    if(!daLimit($db,'ip:'.$ip,120))daRespond(429,['error'=>'rate']);
    $action=$j['action']??'';
    if($action==='public_config'){daRespond(200,daContacts($db));}
    if($action==='login'){
        $name=$j['username']??'';$password=$j['password']??'';
        $device=$j['device_id']??'';$model=$j['device_model']??'';
        if(!is_string($device)||!preg_match('/^[a-f0-9]{64}$/D',$device)||!is_string($model)||strlen($model)>100||$model==='')daRespond(426,['error'=>'update_required']);
        if(!is_string($name)||!is_string($password)||!daName($name)||strlen($password)>128)daRespond(401,['error'=>'login']);
        if(!daLimit($db,'login:'.$name,5)||!daLimit($db,'login-day:'.$name,20,86400))daRespond(429,['error'=>'rate']);
        $legacy=$j['legacy_device_id']??'';
        if(!is_string($legacy)||($legacy!==''&&!preg_match('/^[a-f0-9]{64}$/D',$legacy)))daRespond(400,['error'=>'device']);
        try{
            $login=daDeviceLogin($db,$name,$password,$device,$model,$legacy);
            $a=$login['account'];$token=$login['token'];
        }catch(DomainException $e){daRespond($e->getCode(),['error'=>$e->getMessage()]);}
    }elseif($action==='session'||$action==='status'||$action==='logout'||$action==='devices'||$action==='revoke_device'||$action==='news'||$action==='news_read'||$action==='news_dismiss'){
        $token=$j['token']??'';
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))daRespond(401,['error'=>'session']);
        $h=hash('sha256',$token);$s=daRow($db,'SELECT account_id FROM delta_app_sessions WHERE token_hash=? AND expires>? AND token_hash IN (SELECT token_hash FROM delta_app_devices)','si',$h,time());
        if(!$s)daRespond(401,['error'=>'session']);
        $dev=daRow($db,'SELECT id FROM delta_app_devices WHERE token_hash=?','s',$h);if(!$dev)daRespond(401,['error'=>'session']);
        if($action==='logout'){daDeviceRevoke($db,(int)$s['account_id'],(int)$dev['id']);daRespond(200,['ok'=>true]);}
        $a=daAccount($db,(int)$s['account_id']);if(!$a||(int)$a['active']!==1)daRespond(401,['error'=>'session']);
        if($action==='session'&&array_key_exists('stable_device_id',$j)){
            $stable=$j['stable_device_id'];
            if(!is_string($stable)||!preg_match('/^[a-f0-9]{64}$/D',$stable))daRespond(400,['error'=>'device']);
            try{daDeviceMigrateSession($db,(int)$a['id'],$token,$stable);}
            catch(DomainException $e){daRespond($e->getCode(),['error'=>$e->getMessage()]);}
        }
        daExec($db,'UPDATE delta_app_devices SET last_seen=? WHERE token_hash=?','is',time(),$h);
        if(in_array($action,['news','news_read','news_dismiss'],true)){
            if(!daLimit($db,'news:'.(int)$a['id'],120))daRespond(429,['error'=>'rate']);
            if($action!=='news'){
                $id=$j['id']??0;if(!is_int($id)||$id<1)daRespond(400,['error'=>'news']);
                daNewsSeen($db,(int)$a['id'],$id,$action==='news_read'?'inbox':'float');
            }
            $before=$j['before']??0;
            if(!is_int($before)||$before<0)daRespond(400,['error'=>'news']);
            daRespond(200,daNewsFeed($db,(int)$a['id'],$before));
        }
        if($action==='devices'||$action==='revoke_device'){
            if($action==='revoke_device'){if(!is_int($j['device']??null)||$j['device']<1||$j['device']==(int)$dev['id'])daRespond(400,['error'=>'device']);daDeviceRevoke($db,(int)$a['id'],$j['device']);}
            daRespond(200,['devices'=>daDeviceList($db,(int)$a['id']),'device_limit'=>(int)$a['device_limit'],'current_id'=>(int)$dev['id']]);
        }
    }else daRespond(400,['error'=>'action']);
    $mapping=daPgMap($db,(int)$a['id']);
    if(!$mapping||$mapping['state']!=='ready')daRespond(409,['error'=>'dedicated_required']);
    $v=daPgLive($db,$a,$action!=='status');
    // Recheck after network I/O; revoked sessions never receive configs.
    $fresh=daAccount($db,(int)$a['id']);
    if(!$fresh||(int)$fresh['active']!==1||!daRow($db,'SELECT account_id FROM delta_app_sessions WHERE token_hash=? AND account_id=? AND expires>?','sii',hash('sha256',$token),(int)$a['id'],time()))daRespond(401,['error'=>'session']);
    daRespond(200,['token'=>$token,'username'=>$a['username'],'configs'=>$v['configs'],'quota_enforced'=>true,'gb'=>$v['limit_bytes']/1073741824,'expires'=>$v['expires'],'server_now'=>time(),'created'=>(int)$a['created'],'used_bytes'=>$v['used_bytes'],'remaining_bytes'=>$v['remaining_bytes'],'limit_bytes'=>$v['limit_bytes'],'can_connect'=>$v['can_connect'],'panel_status'=>$v['panel_status']]+daRefreshSettings($db));
}catch(Throwable $e){daRespond(503,['error'=>'unavailable']);}
