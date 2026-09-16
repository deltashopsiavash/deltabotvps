from pathlib import Path
import re

# Robust PasarGuard update endpoint: official + legacy fallback.
p=Path('config.php')
s=p.read_text(encoding='utf-8')
pat=re.compile(r"function editPasarguardUser\(\$server_id, \$remark, \$fields\)\{.*?\n\}\n\nfunction resetPasarguardTraffic",re.S)
m=pat.search(s)
if not m: raise SystemExit('editPasarguardUser block not found')
new=r'''function editPasarguardUser($server_id,$remark,$fields){
    global $connection;
    $stmt=$connection->prepare("SELECT * FROM `server_config` WHERE `id`=? LIMIT 1");
    $stmt->bind_param('i',$server_id);$stmt->execute();$server=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$server)return (object)['success'=>false,'msg'=>'PasarGuard server not found'];
    $token=getPasarguardToken($server_id);
    if(empty($token->success)||empty($token->access_token))return (object)['success'=>false,'msg'=>$token->msg??'PasarGuard token error'];

    // Current official API: PUT /api/user/{username}; keep legacy by-username route as fallback.
    $paths=[
        '/api/user/'.rawurlencode($remark),
        '/api/user/by-username/'.rawurlencode($remark),
        '/api/users/'.rawurlencode($remark),
        '/api/users/by-username/'.rawurlencode($remark)
    ];
    $last='';$lastHttp=0;
    foreach(pasarguardPanelApiBases($server['panel_url']) as $base){
        foreach($paths as $path){
            $ch=curl_init();
            curl_setopt_array($ch,[
                CURLOPT_URL=>$base.$path,
                CURLOPT_CUSTOMREQUEST=>'PUT',
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>30,
                CURLOPT_SSL_VERIFYHOST=>false,
                CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_MAXREDIRS=>3,
                CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','Authorization: Bearer '.$token->access_token],
                CURLOPT_POSTFIELDS=>json_encode($fields,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            ]);
            $raw=curl_exec($ch);$err=curl_error($ch);$http=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$lastHttp=$http;
            if($err){$last=$err;continue;}
            if($http>=200&&$http<300)return (object)['success'=>true,'obj'=>json_decode((string)$raw),'http'=>$http];
            $last=trim((string)$raw)!==''?(string)$raw:('HTTP '.$http);
            if($http===401||$http===403)return (object)['success'=>false,'msg'=>$last,'http'=>$http];
        }
    }
    return (object)['success'=>false,'msg'=>$last?:'PasarGuard update failed','http'=>$lastHttp];
}

function resetPasarguardTraffic'''
s=pat.sub(new,s,count=1)
p.write_text(s,encoding='utf-8')

p=Path('bot.php')
s=p.read_text(encoding='utf-8')
# Wallet renew must be pending before panel mutation to prevent double-click duplication.
needle="""    if(!$pay){ alert('فاکتور پیدا نشد'); exit; }
    if((int)$userInfo['wallet'] < (int)$pay['price']){ alert('موجودی کیف پول کافی نیست', true); exit; }
    $type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"""
repl="""    if(!$pay){ alert('فاکتور پیدا نشد'); exit; }
    if(($pay['state']??'')!=='pending'){alert('این فاکتور قبلاً پردازش شده است.',true);exit;}
    if((int)$userInfo['wallet'] < (int)$pay['price']){ alert('موجودی کیف پول کافی نیست', true); exit; }
    $type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"""
# Limit replacement to wallet block by position.
pos=s.index("if(preg_match('/^pgRenewPayWallet")
idx=s.find(needle,pos)
if idx<0: raise SystemExit('wallet safety target not found')
s=s[:idx]+repl+s[idx+len(needle):]

# Receipt approval must be idempotent.
pos=s.index("if(preg_match('/^approvePgRenew")
needle2="""    if(!$pay){ alert('فاکتور پیدا نشد'); exit; }
    $type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"""
idx=s.find(needle2,pos)
if idx<0: raise SystemExit('approve safety target not found')
repl2="""    if(!$pay){ alert('فاکتور پیدا نشد'); exit; }
    if(!in_array((string)($pay['state']??''),['pending','have_sent','send'],true)){alert('این رسید قبلاً پردازش شده است.',true);exit;}
    $type=$pay['type']; $days=0; $volume=0; $oid=0; $fullReset=false; $fullPlanId=0;"""
s=s[:idx]+repl2+s[idx+len(needle2):]

# Reject receipt should persist state, preventing later approval of the same receipt.
old="""if(preg_match('/^decPgRenew(.+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ رد شد','callback_data'=>'deltach']]]], JSON_UNESCAPED_UNICODE));
    alert('رد شد'); exit;
}"""
new2="""if(preg_match('/^decPgRenew(.+)$/', $data, $m) && ($from_id == $admin || $userInfo['isAdmin'] == true)){
    $hash=$m[1];$stmt=$connection->prepare(\"UPDATE `pays` SET `state`='rejected' WHERE `hash_id`=? AND `state` IN ('pending','have_sent','send')\");if($stmt){$stmt->bind_param('s',$hash);$stmt->execute();$stmt->close();}
    editKeys(json_encode(['inline_keyboard'=>[[['text'=>'❌ رد شد','callback_data'=>'deltach']]]], JSON_UNESCAPED_UNICODE));
    alert('رد شد'); exit;
}"""
if old not in s: raise SystemExit('decPgRenew target not found')
s=s.replace(old,new2,1)
p.write_text(s,encoding='utf-8')
print('safety patched')