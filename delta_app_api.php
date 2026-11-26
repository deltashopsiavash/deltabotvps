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
        if(!is_string($name)||!is_string($password)||!daName($name)||strlen($password)>128)daRespond(401,['error'=>'login']);
        if(!daLimit($db,'login:'.$name,5)||!daLimit($db,'login-day:'.$name,20,86400))daRespond(429,['error'=>'rate']);
        $db->begin_transaction();
        try {
            $a=daRow($db,'SELECT * FROM delta_app_accounts WHERE username=? FOR UPDATE','s',$name);
            if(!$a||!password_verify($password,$a['password_hash'])||!daAllowed($a)){$db->rollback();daRespond(401,['error'=>'login']);}
            $token=bin2hex(random_bytes(32));$id=(int)$a['id'];$exp=(int)$a['expires'];
            daExec($db,'DELETE FROM delta_app_sessions WHERE expires<=? OR account_id=?','ii',time(),$id);
            daExec($db,'INSERT INTO delta_app_sessions(token_hash,account_id,expires) VALUES(?,?,?)','sii',hash('sha256',$token),$id,$exp);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
    }elseif($action==='session'||$action==='status'||$action==='logout'){
        $token=$j['token']??'';
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))daRespond(401,['error'=>'session']);
        $h=hash('sha256',$token);$s=daRow($db,'SELECT account_id FROM delta_app_sessions WHERE token_hash=? AND expires>?','si',$h,time());
        if(!$s)daRespond(401,['error'=>'session']);
        if($action==='logout'){daExec($db,'DELETE FROM delta_app_sessions WHERE token_hash=?','s',$h);daRespond(200,['ok'=>true]);}
        $a=daAccount($db,(int)$s['account_id']);if(!daAllowed($a))daRespond(401,['error'=>'session']);
    }else daRespond(400,['error'=>'action']);
    $mapping=daPgMap($db,(int)$a['id']);
    if(!$mapping||$mapping['state']!=='ready')daRespond(409,['error'=>'dedicated_required']);
    $v=daPgLive($db,$a,$action!=='status');
    // Recheck after network I/O; revoked sessions never receive configs.
    $fresh=daAccount($db,(int)$a['id']);
    if(!daAllowed($fresh)||!daRow($db,'SELECT account_id FROM delta_app_sessions WHERE token_hash=? AND account_id=? AND expires>?','sii',hash('sha256',$token),(int)$a['id'],time()))daRespond(401,['error'=>'session']);
    daRespond(200,['token'=>$token,'username'=>$a['username'],'configs'=>$v['configs'],'quota_enforced'=>true,'gb'=>(int)$a['gb'],'expires'=>$v['expires'],'server_now'=>time(),'created'=>(int)$a['created'],'used_bytes'=>$v['used_bytes'],'remaining_bytes'=>$v['remaining_bytes'],'limit_bytes'=>$v['limit_bytes'],'can_connect'=>$v['can_connect'],'panel_status'=>$v['panel_status']]);
}catch(Throwable $e){daRespond(503,['error'=>'unavailable']);}
