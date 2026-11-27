<?php
declare(strict_types=1);
// Shared subscription catalog, independent account plan metadata.
function daSchema(mysqli $db): void {
    foreach ([
        "CREATE TABLE IF NOT EXISTS delta_app_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, gb INT UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL, price BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_test_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, buyer BIGINT NOT NULL, plan_id INT UNSIGNED NOT NULL, name VARCHAR(80) NOT NULL, gb INT UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL, price BIGINT UNSIGNED NOT NULL, device_limit INT UNSIGNED NOT NULL, state VARCHAR(20) NOT NULL DEFAULT 'pending', account_id INT UNSIGNED NULL UNIQUE, created BIGINT UNSIGNED NOT NULL, approved_at BIGINT UNSIGNED NULL, delivered_at BIGINT UNSIGNED NULL, UNIQUE KEY account_ref(account_id), INDEX(buyer,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_ui (id TINYINT PRIMARY KEY,message_id BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_categories (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_admin_draft (id TINYINT PRIMARY KEY,gb INT UNSIGNED NOT NULL DEFAULT 0,days INT UNSIGNED NOT NULL DEFAULT 0,price BIGINT UNSIGNED NOT NULL DEFAULT 0,devices INT UNSIGNED NOT NULL DEFAULT 1,plan_id INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_stock (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL, url TEXT NOT NULL, url_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1, INDEX(plan_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, stock_id INT UNSIGNED NULL UNIQUE, plan_id INT UNSIGNED NOT NULL, gb DECIMAL(13,3) UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, created BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_sessions (token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, account_id INT UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, INDEX(account_id), INDEX(expires)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_devices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, account_id INT UNSIGNED NOT NULL, device_hash CHAR(64) CHARACTER SET ascii NOT NULL, token_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, model VARCHAR(100) NOT NULL, created BIGINT UNSIGNED NOT NULL, last_seen BIGINT UNSIGNED NOT NULL, UNIQUE KEY account_device(account_id,device_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, url TEXT NOT NULL, url_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, payload MEDIUMTEXT NOT NULL, nodes INT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, updated BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_panels (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, panel_url VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, username VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL, secret TEXT NOT NULL, sub_base_url VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, group_id INT UNSIGNED NOT NULL, created BIGINT UNSIGNED NOT NULL, UNIQUE KEY panel_identity(panel_url,username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_panel_drafts (ticket CHAR(24) CHARACTER SET ascii PRIMARY KEY, panel_id INT UNSIGNED NULL, panel_url VARCHAR(255) NOT NULL, username VARCHAR(128) NOT NULL, secret TEXT NOT NULL, sub_base_url VARCHAR(255) NOT NULL, groups_json MEDIUMTEXT NOT NULL, expires BIGINT UNSIGNED NOT NULL, INDEX(expires)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_refresh (id TINYINT PRIMARY KEY, minutes INT UNSIGNED NOT NULL DEFAULT 5, revision BIGINT UNSIGNED NOT NULL DEFAULT 0, reconnect_revision BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_contacts (id TINYINT PRIMARY KEY, purchase VARCHAR(32) NOT NULL DEFAULT '', support VARCHAR(32) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_pg_settings (id TINYINT PRIMARY KEY, server_id INT UNSIGNED NOT NULL, group_id INT UNSIGNED NOT NULL, panel_base VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_pg_users (account_id INT UNSIGNED PRIMARY KEY, server_id INT UNSIGNED NOT NULL, group_id INT UNSIGNED NOT NULL, panel_base VARCHAR(255) NOT NULL, pg_name VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, marker VARCHAR(80) NOT NULL, state VARCHAR(16) NOT NULL DEFAULT 'pending', remote_id BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_limits (key_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, hits INT UNSIGNED NOT NULL, bucket BIGINT UNSIGNED NOT NULL, INDEX(bucket)) ENGINE=InnoDB"
    ] as $sql) { if (!$db->query($sql)) throw new RuntimeException('schema'); }
    daEnsureDecimalQuota($db,'delta_app_accounts');
    if(!daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_accounts' AND COLUMN_NAME='quota_baseline_bytes'"))$db->query("ALTER TABLE delta_app_accounts ADD COLUMN quota_baseline_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0");
    daNewsSchema($db);
    if(!$db->query("CREATE TABLE IF NOT EXISTS delta_app_update (id TINYINT PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 0, version VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT '') ENGINE=InnoDB"))throw new RuntimeException('update schema');
    $col=daRow($db,"SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_accounts' AND COLUMN_NAME='stock_id'");
    if($col && $col['IS_NULLABLE']==='NO' && !$db->query('ALTER TABLE delta_app_accounts MODIFY stock_id INT UNSIGNED NULL'))throw new RuntimeException('migration');
    if(!daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_plans' AND COLUMN_NAME='category_id'"))$db->query("ALTER TABLE delta_app_plans ADD COLUMN category_id INT UNSIGNED NULL");
    if(!daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_admin_draft' AND COLUMN_NAME='name'"))$db->query("ALTER TABLE delta_app_admin_draft ADD COLUMN name VARCHAR(80) CHARACTER SET utf8mb4 NOT NULL DEFAULT ''");
    foreach(['delta_app_plans','delta_app_accounts'] as $table){
        if(!daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='device_limit'",'s',$table))$db->query("ALTER TABLE `$table` ADD COLUMN device_limit INT UNSIGNED NOT NULL DEFAULT 1");
    }
    foreach(['delta_app_pg_settings','delta_app_pg_users'] as $table){
        $col=daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='panel_kind'",'s',$table);
        if(!$col)$db->query("ALTER TABLE `$table` ADD COLUMN panel_kind VARCHAR(8) NOT NULL DEFAULT 'legacy'");
    }
}
// Preserve every existing whole-GB value while accepting exact decimal GB.
function daEnsureDecimalQuota(mysqli $db,string $table): void {
    if(!in_array($table,['delta_app_accounts','delta_app_real_orders','delta_app_trial_settings'],true))throw new RuntimeException('quota table');
    $r=daRow($db,"SELECT DATA_TYPE,NUMERIC_PRECISION,NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='gb'",'s',$table);
    if(!$r)throw new RuntimeException('quota column unavailable');
    if($r['DATA_TYPE']==='decimal'&&(int)$r['NUMERIC_PRECISION']>=13&&(int)$r['NUMERIC_SCALE']===3)return;
    if(!in_array($r['DATA_TYPE'],['int','decimal'],true))throw new RuntimeException('unexpected quota column');
    $default=$table==='delta_app_trial_settings'?' DEFAULT 1':'';
    if(!$db->query("ALTER TABLE ".$table." MODIFY gb DECIMAL(13,3) UNSIGNED NOT NULL".$default))throw new RuntimeException('quota migration failed');
}
function daQuotaLabel(int|float|string $gb): string {
    $s=(string)$gb;
    return str_contains($s,'.')?rtrim(rtrim($s,'0'),'.'):$s;
}
function daQuotaBytes(int|float|string $gb): int {
    $s=(string)$gb;
    if(!preg_match('/^(\d{1,10})(?:\.(\d{1,3}))?$/D',$s,$m))throw new InvalidArgumentException('حجم معتبر نیست.');
    $whole=(int)$m[1];$frac=(int)str_pad($m[2]??'',3,'0');
    if($whole>4294967295||($whole===4294967295&&$frac>0))throw new InvalidArgumentException('حجم خارج از محدوده است.');
    $bytes=$whole*1073741824+intdiv($frac*1073741824,1000);
    if($bytes<1)throw new InvalidArgumentException('حجم باید بیشتر از صفر باشد.');
    return $bytes;
}
function daQuery(mysqli $db,string $sql,string $types='',...$args): mysqli_stmt {
    $s=$db->prepare($sql); if(!$s)throw new RuntimeException('query');
    if($types!==''&&!$s->bind_param($types,...$args))throw new RuntimeException('bind');
    if(!$s->execute())throw new RuntimeException('execute');
    return $s;
}
function daRow(mysqli $db,string $sql,string $types='',...$args): ?array {
    $s=daQuery($db,$sql,$types,...$args);$r=$s->get_result()->fetch_assoc();$s->close();return $r?:null;
}
function daRows(mysqli $db,string $sql,string $types='',...$args): array {
    $s=daQuery($db,$sql,$types,...$args);$r=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $r;
}
function daExec(mysqli $db,string $sql,string $types='',...$args): void {daQuery($db,$sql,$types,...$args)->close();}
function daValidUrl(string $url): bool {
    return strlen($url)<=512 && preg_match('~^https://pmainp1\.deltamarket2\.ir:8000/sub/[A-Za-z0-9._\~-]+$~D',$url)===1;
}
function daName(string $name): bool {return preg_match('/^[a-z0-9_]{4,48}$/D',$name)===1;}
// 128 bits of randomness; existing passwords remain valid until explicitly reset.
function daPassword(): string {return rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');}
function daAutoCreate(mysqli $db,int $plan): array {
    for($i=0;$i<40;$i++){
        $name='delta'.random_int(10000,99999);
        if(daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$name))continue;
        try{return ['username'=>$name,'password'=>daCreate($db,$plan,$name)];}
        catch(Throwable $e){if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$name))throw $e;}
    }
    throw new InvalidArgumentException('نام کاربری آزاد پیدا نشد؛ دوباره تلاش کنید.');
}
function daCreate(mysqli $db,int $plan,string $name): string {
    if(!daName($name))throw new InvalidArgumentException('نام کاربری ۴ تا ۴۸ حرف کوچک انگلیسی، عدد یا _ باشد.');
    $pass=daPassword();$hash=password_hash($pass,PASSWORD_DEFAULT);$db->begin_transaction();
    try{
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 FOR UPDATE','i',$plan);
        if(!$p)throw new InvalidArgumentException('پلن فعال پیدا نشد.');
        $now=time();$expires=$now+(int)$p['days']*86400;
        daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,plan_id,gb,expires,created) VALUES(?,?,?,?,?,?)','ssiiii',$name,$hash,$plan,(int)$p['gb'],$expires,$now);
        $db->commit();return $pass;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daAccount(mysqli $db,int $id): ?array {
    return daRow($db,'SELECT * FROM delta_app_accounts WHERE id=?','i',$id);
}
function daAllowed(?array $a): bool {return $a!==null && (int)$a['active']===1 && (int)$a['expires']>time();}
function daLimit(mysqli $db,string $key,int $max,int $seconds=300): bool {
    $h=hash('sha256',$key);$b=intdiv(time(),$seconds);
    daExec($db,'INSERT INTO delta_app_limits(key_hash,hits,bucket) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=IF(bucket=VALUES(bucket),hits+1,1),bucket=VALUES(bucket)','si',$h,$b);
    $r=daRow($db,'SELECT hits FROM delta_app_limits WHERE key_hash=?','s',$h);
    return (int)$r['hits']<=$max;
}

/** Only manually supplied links on the exact existing host. No redirects or nested URLs. */
function daExtract(string $body): array {
    $clean=trim(preg_replace('/^\xEF\xBB\xBF/','',$body));
    $b64=base64_decode(strtr(preg_replace('/\s+/','',$clean),'-_','+/'),true);
    foreach([$clean,$b64===false?'':trim(preg_replace('/^\xEF\xBB\xBF/','',$b64))] as $candidate){
        if($candidate==='')continue;
        // Xray full config object or array, preserved without discarding transport/routing.
        if($candidate[0]==='{'||$candidate[0]==='['){
            $json=json_decode($candidate,false,128);
            if(json_last_error()===JSON_ERROR_NONE){
                $items=is_array($json)?$json:[$json];$out=[];
                if(count($items)<=500){
                    foreach($items as $item){
                        if(!is_object($item)||!is_array($item->inbounds??null)||!is_array($item->outbounds??null)||!is_object($item->routing??null)){$out=[];break;}
                        $encoded=json_encode($item,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                        if(strlen($encoded)>262144)throw new InvalidArgumentException('اندازه یک کانفیگ JSON بیش از حد مجاز است.');
                        $out[$encoded]=true;
                    }
                    if($out)return array_keys($out);
                }
            }
        }
        if(str_starts_with($candidate,'[Interface]')&&str_contains($candidate,'[Peer]')&&strlen($candidate)<=262144)return [$candidate];
        $out=[];
        foreach(preg_split('/\r\n|\r|\n/',$candidate) as $line){
            $line=trim($line);
            // Some panels leave spaces in display names after '#', not in the URI.
            $parts=explode('#',$line,2);
            if(isset($parts[1])&&preg_match('/\s/u',$parts[1]))$line=$parts[0].'#'.rawurlencode(rawurldecode($parts[1]));
            if(strlen($line)<=16384 && preg_match('~^(vless|vmess|trojan|ss|socks|socks4|socks5|wireguard|hysteria2|hy2|v2rayn)://[^\s<>]+$~D',$line))$out[$line]=true;
        }
        if($out)return array_slice(array_keys($out),0,500);
    }
    $kind=str_starts_with($clean,'<')?'HTML':((str_starts_with($clean,'{')||str_starts_with($clean,'['))?'JSON ناشناخته':'متن یا Base64 ناشناخته');
    throw new InvalidArgumentException('فرمت ساب قابل استخراج نیست: '.$kind.' ('.strlen($body).' بایت).');
}
function daFetchSource(string $url): array {
    if(!daValidUrl($url))throw new InvalidArgumentException('ساختار لینک ساب مجاز نیست.');
    $body='';$c=curl_init($url);
    curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'v2rayNG/2.3.15',CURLOPT_WRITEFUNCTION=>function($c,$part)use(&$body){if(strlen($body)+strlen($part)>2000000)return 0;$body.=$part;return strlen($part);}]);
    try{$ok=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);}finally{curl_close($c);}
    if($ok===false||$code!==200)throw new InvalidArgumentException('دریافت ساب ناموفق بود؛ HTTP '.(int)$code.'.');
    return daExtract($body);
}
function daSaveSource(mysqli $db,string $url,array $nodes): void {
    if(!daValidUrl($url)||!$nodes)throw new InvalidArgumentException('ساب نامعتبر است.');
    $payload=json_encode($nodes,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $lock=daRow($db,"SELECT GET_LOCK(CONCAT('da_sources_',LEFT(SHA2(DATABASE(),256),40)),5) AS acquired");
    if((int)($lock['acquired']??0)!==1)throw new RuntimeException('lock');
    try{
        $h=hash('sha256',$url);
        $existing=daRow($db,'SELECT id FROM delta_app_sources WHERE url_hash=?','s',$h);
        $n=daRow($db,'SELECT COUNT(*) AS n FROM delta_app_sources');
        if(!$existing&&(int)$n['n']>=20)throw new InvalidArgumentException('حداکثر ۲۰ ساب؛ ابتدا یک مورد را حذف کنید.');
        daExec($db,'INSERT INTO delta_app_sources(url,url_hash,payload,nodes,updated) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),nodes=VALUES(nodes),updated=VALUES(updated),active=1','sssii',$url,$h,$payload,count($nodes),time());
    }finally{daRow($db,"SELECT RELEASE_LOCK(CONCAT('da_sources_',LEFT(SHA2(DATABASE(),256),40))) AS released");}
}
function daCatalog(mysqli $db): array {
    $out=[];$size=0;
    foreach(daRows($db,'SELECT payload FROM delta_app_sources WHERE active=1 ORDER BY id LIMIT 20') as $s){
        foreach(json_decode($s['payload'],true,512,JSON_THROW_ON_ERROR) as $line){
            if(isset($out[$line]))continue;
            $size+=strlen($line);
            if(count($out)>=1000||$size>1800000)throw new RuntimeException('catalog too large');
            $out[$line]=true;
        }
    }
    return array_keys($out);
}

function daDeleteAccount(mysqli $db,int $id): bool {
    $db->begin_transaction();
    try {
        $a=daRow($db,'SELECT id FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id);
        if(!$a){$db->rollback();return false;}
        daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);
        daExec($db,'DELETE FROM delta_app_accounts WHERE id=?','i',$id);
        $db->commit();return true;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daFindUsers(mysqli $db,string $prefix,int $offset=0): array {
    if(!preg_match('/^[a-z0-9_]{1,48}$/D',$prefix))throw new InvalidArgumentException('نام کاربری انگلیسی را وارد کنید.');
    $pattern=str_replace('_','!_',$prefix).'%';
    return daRows($db,"SELECT id,username,active,expires FROM delta_app_accounts WHERE username LIKE ? ESCAPE '!' ORDER BY username LIMIT 9 OFFSET ?",'si',$pattern,$offset);
}
function daRemainingSeconds(int $expires,?int $now=null): int {return max(0,$expires-($now??time()));}
function daIranDate(int $timestamp): string {
    require_once __DIR__.'/settings/jdf.php';
    $d=(new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$y,$m,$day]=gregorian_to_jalali((int)$d->format('Y'),(int)$d->format('n'),(int)$d->format('j'));
    return sprintf('%04d/%02d/%02d %s',$y,$m,$day,$d->format('H:i:s'));
}

/* App-only PasarGuard connector. Existing sales code is not changed. TLS is mandatory. */
function daPgBase(string $url): string {
    $base=preg_replace('~/dashboard/?$~i','',rtrim(trim($url),'/'));
    $p=parse_url($base);
    if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'],$p['pass'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!empty($p['path']))throw new InvalidArgumentException('آدرس پنل باید HTTPS معتبر و بدون مسیر اضافی باشد.');
    return $base;
}
// Preserve the existing panel key across database password changes.
// The recovery envelope is encrypted by the mother bot token, not by the DB password.
function daLegacyPanelKey(): string {
    global $dbPassword;
    if(!is_string($dbPassword??null)||$dbPassword==='')throw new RuntimeException('panel encryption unavailable');
    return hash_hkdf('sha256',$dbPassword,32,'delta-app-panel-v1');
}
function daRecoveryWrappingKey(string $token): string {
    if($token==='')throw new RuntimeException('recovery token unavailable');
    return hash_hkdf('sha256',$token,32,'delta-app-backup-wrap-v1');
}
function daRecoveryWrap(string $key,string $token): string {
    if(strlen($key)!==32)throw new RuntimeException('invalid panel key');
    $iv=random_bytes(12);$tag='';
    $encrypted=openssl_encrypt($key,'aes-256-gcm',daRecoveryWrappingKey($token),OPENSSL_RAW_DATA,$iv,$tag,'delta-app-backup-v1',16);
    if($encrypted===false)throw new RuntimeException('recovery encryption');
    return base64_encode($iv.$tag.$encrypted);
}
function daRecoveryUnwrap(string $value,string $token): string {
    $raw=base64_decode($value,true);
    if($raw===false||strlen($raw)!==60)throw new RuntimeException('invalid recovery envelope');
    $key=openssl_decrypt(substr($raw,28),'aes-256-gcm',daRecoveryWrappingKey($token),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),'delta-app-backup-v1');
    if($key===false||strlen($key)!==32)throw new RuntimeException('recovery token mismatch');
    return $key;
}
function daRecoveryTableExists(mysqli $db): bool {
    return (bool)daRow($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_backup_key'");
}
function daPanelKey(?mysqli $db=null): string {
    global $botToken;
    if($db!==null&&daRecoveryTableExists($db)){
        $row=daRow($db,'SELECT envelope FROM delta_app_backup_key WHERE id=1');
        if($row)return daRecoveryUnwrap($row['envelope'],(string)($botToken??''));
    }
    return daLegacyPanelKey();
}
// Called outside financial transactions, before a full SQL dump. Never replaces an existing envelope.
function daPrepareRecoveryKey(mysqli $db): void {
    global $botToken;
    $db->query("CREATE TABLE IF NOT EXISTS delta_app_backup_key (id TINYINT PRIMARY KEY,envelope VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB");
    $key=daPanelKey($db);
    daVerifyRecoverySecrets($db,$key);
    $envelope=daRecoveryWrap($key,(string)($botToken??''));
    daExec($db,'INSERT INTO delta_app_backup_key(id,envelope) VALUES(1,?) ON DUPLICATE KEY UPDATE id=id','s',$envelope);
    daVerifyRecoverySecrets($db,daPanelKey($db));
}
function daVerifyRecoverySecrets(mysqli $db,?string $key=null): void {
    $key=$key??daPanelKey($db);
    foreach(['delta_app_panels','delta_app_panel_drafts'] as $table){
        if(!daRow($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",'s',$table))continue;
        foreach(daRows($db,"SELECT secret,panel_url,username FROM ".$table) as $r){
            daPanelDecrypt($r['secret'],$r['panel_url'],$r['username'],$key);
        }
    }
}
function daPanelEncrypt(string $password,string $base,string $name,?string $key=null): string {
    $iv=random_bytes(12);$tag='';
    $encrypted=openssl_encrypt($password,'aes-256-gcm',$key??daLegacyPanelKey(),OPENSSL_RAW_DATA,$iv,$tag,$base."\0".$name,16);
    if($encrypted===false)throw new RuntimeException('panel encryption');
    return base64_encode($iv.$tag.$encrypted);
}
function daPanelDecrypt(string $value,string $base,string $name,?string $key=null): string {
    $raw=base64_decode($value,true);
    if($raw===false||strlen($raw)<=28)throw new RuntimeException('panel secret invalid');
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key??daLegacyPanelKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$base."\0".$name);
    if($plain===false)throw new RuntimeException('panel secret decrypt');
    return $plain;
}
function daPgServer(mysqli $db,int $id,?string $expected=null,string $kind='app'): array {
    if($kind==='legacy'){
        // Only already-bound old accounts may use the historical sales-panel reference.
        $s=daRow($db,"SELECT * FROM server_config WHERE id=? AND type='pasarguard'",'i',$id);
    }elseif($kind==='app'){
        $s=daRow($db,'SELECT * FROM delta_app_panels WHERE id=?','i',$id);
        if($s)$s['password']=daPanelDecrypt($s['secret'],$s['panel_url'],$s['username'],daPanelKey($db));
    }else throw new RuntimeException('panel kind');
    if(!$s)throw new InvalidArgumentException('پنل پسارگارد مربوط به حساب پیدا نشد.');
    $s['base']=daPgBase($s['panel_url']);
    if($expected!==null&&$s['base']!==$expected)throw new RuntimeException('panel changed');
    return $s;
}
// The same group-list response shapes supported by the sales bot, without using its credentials.
function daPgParseGroups(array $json): ?array {
    $items=$json;
    for($depth=0;$depth<3&&!array_is_list($items);$depth++){
        $next=null;foreach(['groups','items','results','data','user_groups','userGroups'] as $k)if(isset($items[$k])&&is_array($items[$k])){$next=$items[$k];break;}
        if($next===null)return null;$items=$next;
    }
    if(!array_is_list($items))return null;
    $groups=[];
    foreach($items as $g){
        if(!is_array($g))return null;
        $raw=$g['id']??$g['group_id']??null;
        $name=$g['name']??$g['title']??$g['remark']??null;
        if(!(is_int($raw)||(is_string($raw)&&ctype_digit($raw)))||!is_string($name)||trim($name)===''||(int)$raw<1||(int)$raw>4294967295)return null;
        $id=(int)$raw;
        if(in_array($g['is_disabled']??false,[true,1,'1'],true))continue;
        $groups[$id]=['id'=>$id,'name'=>mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/u',' ',$name)??''),0,80)];
    }
    return array_values($groups);
}
function daPgGroups(array $s,?string $token=null): array {
    $token=$token??daPgToken($s);
    $last=0;
    // Full list first (can identify disabled groups), then lightweight list for limited admins.
    foreach(['/api/groups','/api/groups/simple'] as $path){
        [$code,$raw]=daPgApi($s,$path.'?limit=1000&offset=0','GET',$token);$last=$code;
        if($code!==200)continue;
        $json=daPgJson($raw);$out=daPgParseGroups($json);
        if($out===null)continue;
        $total=$json['total']??null;
        // Fail rather than silently showing a truncated list.
        if(is_numeric($total)&&(int)$total>1000)throw new InvalidArgumentException('تعداد گروه‌ها بیش از ظرفیت این نسخه است.');
        if(is_numeric($total)&&(int)$total>0&&!$out&&$path==='/api/groups')continue;
        if($out)return $out;
        if($path==='/api/groups/simple')return [];
    }
    if($last===403||$last===401)throw new InvalidArgumentException('ورود انجام شد ولی مدیر اجازه مشاهده گروه‌ها را ندارد.');
    throw new InvalidArgumentException('فهرست گروه‌ها از پنل دریافت نشد؛ دسترسی یا نسخه API را بررسی کنید.');
}
function daPgCheckGroup(array $s,int $gid): array {
    foreach(daPgGroups($s) as $g)if($g['id']===$gid)return $g;
    throw new InvalidArgumentException('گروه در فهرست قابل دسترس مدیر پیدا نشد.');
}
function daPanelInput(string $url,string $name,string $password,string $sub=''): array {
    $base=daPgBase($url);$sub=$sub===''?$base:daPgBase($sub);
    if(strlen($base)>255||strlen($sub)>255||strlen($name)<1||strlen($name)>128||strlen($password)<1||strlen($password)>512||preg_match('/[\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('مقادیر پنل نامعتبر است.');
    return ['base'=>$base,'sub_base_url'=>$sub,'username'=>$name,'password'=>$password];
}
function daPanelDraft(mysqli $db,array $s,?int $panelId=null): array {
    if($panelId===null&&daRow($db,'SELECT id FROM delta_app_panels WHERE panel_url=? AND username=?','ss',$s['base'],$s['username']))throw new InvalidArgumentException('این پنل قبلاً ثبت شده؛ از انتخاب پنل و گروه استفاده کنید.');
    $groups=daPgGroups($s);
    if(!$groups)throw new InvalidArgumentException('ورود موفق بود ولی هیچ گروه قابل انتخابی پیدا نشد.');
    $ticket=bin2hex(random_bytes(12));$secret=daPanelEncrypt($s['password'],$s['base'],$s['username'],daPanelKey($db));
    daExec($db,'DELETE FROM delta_app_panel_drafts WHERE expires<=?','i',time());
    daExec($db,'INSERT INTO delta_app_panel_drafts(ticket,panel_id,panel_url,username,secret,sub_base_url,groups_json,expires) VALUES(?,?,?,?,?,?,?,?)','sisssssi',$ticket,$panelId,$s['base'],$s['username'],$secret,$s['sub_base_url'],json_encode($groups,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),time()+600);
    return ['ticket'=>$ticket,'groups'=>$groups];
}
function daPanelDraftGet(mysqli $db,string $ticket): array {
    if(!preg_match('/^[a-f0-9]{24}$/D',$ticket))throw new InvalidArgumentException('درخواست نامعتبر است.');
    $d=daRow($db,'SELECT * FROM delta_app_panel_drafts WHERE ticket=? AND expires>?','si',$ticket,time());
    if(!$d)throw new InvalidArgumentException('زمان انتخاب گروه تمام شد؛ دوباره شروع کنید.');
    return $d;
}
function daPanelDraftFinish(mysqli $db,string $ticket,int $gid): array {
    $d=daPanelDraftGet($db,$ticket);
    $groups=json_decode($d['groups_json'],true,512,JSON_THROW_ON_ERROR);
    if(!in_array($gid,array_column($groups,'id'),true))throw new InvalidArgumentException('گروه را از دکمه‌های همین فهرست انتخاب کنید.');
    $s=$d['panel_id']===null?['base'=>$d['panel_url'],'sub_base_url'=>$d['sub_base_url'],'username'=>$d['username'],'password'=>daPanelDecrypt($d['secret'],$d['panel_url'],$d['username'],daPanelKey($db))]:daPgServer($db,(int)$d['panel_id'],$d['panel_url']);
    $g=daPgCheckGroup($s,$gid);
    $db->begin_transaction();
    try{
        $locked=daRow($db,'SELECT ticket FROM delta_app_panel_drafts WHERE ticket=? AND expires>? FOR UPDATE','si',$ticket,time());
        if(!$locked)throw new InvalidArgumentException('این انتخاب قبلاً انجام شده یا منقضی شده است.');
        if($d['panel_id']===null){
            daExec($db,'INSERT INTO delta_app_panels(panel_url,username,secret,sub_base_url,group_id,created) VALUES(?,?,?,?,?,?)','ssssii',$d['panel_url'],$d['username'],$d['secret'],$d['sub_base_url'],$gid,time());$id=(int)$db->insert_id;
        }else{
            $id=(int)$d['panel_id'];daExec($db,'UPDATE delta_app_panels SET group_id=? WHERE id=?','ii',$gid,$id);
        }
        daExec($db,"INSERT INTO delta_app_pg_settings(id,server_id,group_id,panel_base,panel_kind) VALUES(1,?,?,?,'app') ON DUPLICATE KEY UPDATE server_id=VALUES(server_id),group_id=VALUES(group_id),panel_base=VALUES(panel_base),panel_kind='app'",'iis',$id,$gid,$s['base']);
        daExec($db,'DELETE FROM delta_app_panel_drafts WHERE ticket=?','s',$ticket);
        $db->commit();return ['id'=>$id,'group'=>$g['name']];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
// When panel_url is an IP and the owner provides a DNS subscription hostname,
 // dial the IP directly but retain the certificate hostname and SNI.
function daPgRoute(array $s): array {
    $base=daPgBase($s['base']);
    $p=parse_url($base);$ip=$p['host'];
    $sub=trim((string)($s['sub_base_url']??''));
    if($sub===''||$sub==='/empty')$sub=$base;
    $sub=daPgBase($sub);
    $q=parse_url($sub);$host=$q['host'];
    $apiPort=(int)($p['port']??443);$subPort=(int)($q['port']??443);
    $route=['api_base'=>$base,'api_resolve'=>[],'sub_resolve'=>[]];
    if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||filter_var($host,FILTER_VALIDATE_IP))return $route;
    if(!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||!str_contains($host,'.')||$apiPort<1||$apiPort>65535||$subPort<1||$subPort>65535)throw new InvalidArgumentException('دامنه یا پورت گواهی نامعتبر است.');
    $route['api_base']='https://'.$host.(isset($p['port'])?':'.$apiPort:'');
    $route['api_resolve']=[$host.':'.$apiPort.':'.$ip];
    // Only pin the same origin when the subscription port matches the panel port.
    if($subPort===$apiPort)$route['sub_resolve']=$route['api_resolve'];
    return $route;
}
function daPgApi(array $s,string $path,string $method='GET',?string $token=null,?array $body=null,bool $form=false): array {
    if(!str_starts_with($path,'/api/'))throw new RuntimeException('invalid API path');
    $r=daPgRoute($s);
    return daPgHttp($r['api_base'].$path,$method,$token,$body,$form,$r['api_resolve']);
}
function daPgHttp(string $url,string $method='GET',?string $token=null,?array $body=null,bool $form=false,array $resolve=[]): array {
    $data='';$ch=curl_init($url);
    $headers=['Accept: application/json'];
    if($token!==null)$headers[]='Authorization: Bearer '.$token;
    if($body!==null)$headers[]=$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_PROXY=>'',CURLOPT_USERAGENT=>'v2rayNG/2.3.15',CURLOPT_WRITEFUNCTION=>function($ch,$part)use(&$data){if(strlen($data)+strlen($part)>2000000)return 0;$data.=$part;return strlen($part);}]);
    if($resolve)curl_setopt($ch,CURLOPT_RESOLVE,$resolve);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$form?http_build_query($body):json_encode($body,JSON_THROW_ON_ERROR));
    try{$ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);}finally{curl_close($ch);}
    if($ok===false)throw new RuntimeException('panel network or TLS');
    return [$code,$data];
}
function daPgJson(string $raw): array {
    $j=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
    if(!is_array($j))throw new RuntimeException('panel response');
    return $j;
}
function daPgToken(array $s): string {
    [$code,$raw]=daPgApi($s,'/api/admin/token','POST',null,['username'=>$s['username'],'password'=>$s['password']],true);
    if($code!==200)throw new RuntimeException('panel auth');
    $t=daPgJson($raw)['access_token']??null;
    if(!is_string($t)||!preg_match('/^[A-Za-z0-9._~-]+$/D',$t))throw new RuntimeException('panel token');
    return $t;
}
function daPgPath(array $m): string {return '/api/user/'.rawurlencode($m['pg_name']);}
function daPgGet(array $s,string $token,array $m): ?array {
    [$code,$raw]=daPgApi($s,daPgPath($m),'GET',$token);
    if($code===404)return null;
    if($code!==200)throw new RuntimeException('panel read');
    $j=daPgJson($raw);
    if(($j['username']??null)!==$m['pg_name']||($j['note']??null)!==$m['marker']||!is_int($j['id']??null)||$j['id']<1||($m['remote_id']!==null&&(int)$m['remote_id']!==$j['id']))throw new RuntimeException('panel identity mismatch');
    return $j;
}
function daPgMetrics(array $a,array $j,bool $strict=true): array {
    $base=(int)($a['quota_baseline_bytes']??0);
    $limit=daQuotaBytes($a['gb']);
    $used=$j['used_traffic']??null;$actual=$j['data_limit']??null;$raw=$j['expire']??null;
    $expires=is_int($raw)?$raw:(is_string($raw)&&preg_match('/^\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)$/D',$raw)?strtotime($raw):false);
    if(!is_int($used)||$used<0||!is_int($actual)||$actual<1||$base<0||$used<$base||$actual<=$base||(($strict||$base>0)&&$actual!==$base+$limit)||$expires===false||$expires<=0||($strict&&$expires!==(int)$a['expires'])||($j['data_limit_reset_strategy']??null)!=='no_reset')throw new RuntimeException('panel quota or expiry mismatch');
    // A full renewal uses an absolute remote quota with a durable usage
    // baseline, rather than a non-idempotent remote traffic reset.
    $limit=$actual-$base;$used-=$base;
    $status=$j['status']??'unknown';
    return ['limit_bytes'=>$limit,'used_bytes'=>$used,'remaining_bytes'=>max(0,$limit-$used),'expires'=>$expires,'panel_status'=>$status,'can_connect'=>$status==='active'&&$used<$limit&&$expires>time()&&(int)$a['active']===1];
}
function daPgSub(array $s,array $j): string {
    $base=trim((string)($s['sub_base_url']??''));if($base===''||$base==='/empty')$base=$s['base'];
    $base=daPgBase($base);
    $url=$j['subscription_url']??null;if(!is_string($url))throw new RuntimeException('subscription missing');
    if(str_starts_with($url,'/sub/'))$url=$base.$url;
    if(strlen($url)>2048||!str_starts_with($url,$base.'/sub/')||!preg_match('~^/sub/[A-Za-z0-9._\~-]+$~D',substr($url,strlen($base))))throw new RuntimeException('subscription origin mismatch');
    return $url;
}
function daPgMap(mysqli $db,int $id): ?array {return daRow($db,'SELECT * FROM delta_app_pg_users WHERE account_id=?','i',$id);}
function daPgConfigure(mysqli $db,int $sid,int $gid): void {
    $s=daPgServer($db,$sid);
    daPgCheckGroup($s,$gid);
    $db->begin_transaction();
    try{
        daExec($db,'UPDATE delta_app_panels SET group_id=? WHERE id=?','ii',$gid,$sid);
        daExec($db,"INSERT INTO delta_app_pg_settings(id,server_id,group_id,panel_base,panel_kind) VALUES(1,?,?,?,'app') ON DUPLICATE KEY UPDATE server_id=VALUES(server_id),group_id=VALUES(group_id),panel_base=VALUES(panel_base),panel_kind='app'",'iis',$sid,$gid,$s['base']);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daPgLocked(mysqli $db,int $id,callable $fn): mixed {
    $key='delta_pg_'.substr(hash('sha256',(string)daRow($db,'SELECT DATABASE() AS d')['d'].':'.$id),0,48);
    $r=daRow($db,'SELECT GET_LOCK(?,3) AS acquired','s',$key);
    if((int)($r['acquired']??0)!==1)throw new RuntimeException('account busy');
    try{return $fn();}finally{daRow($db,'SELECT RELEASE_LOCK(?) AS released','s',$key);}
}
function daPgProvision(mysqli $db,int $id): void {daPgLocked($db,$id,fn()=>daPgProvisionLocked($db,$id));}
function daPgProvisionLocked(mysqli $db,int $id): void {
    $a=daAccount($db,$id);$m=daPgMap($db,$id);
    if(!$a||!$m||$m['state']!=='pending'||(int)$a['expires']<=time())throw new InvalidArgumentException('حساب آماده ساخت یا بازیابی نیست.');
    $s=daPgServer($db,(int)$m['server_id'],$m['panel_base'],$m['panel_kind']);$token=daPgToken($s);
    $j=daPgGet($s,$token,$m);
    if($j===null){
        [$code,$raw]=daPgApi($s,'/api/user','POST',$token,['username'=>$m['pg_name'],'status'=>'active','expire'=>gmdate('c',(int)$a['expires']),'data_limit'=>daQuotaBytes($a['gb']),'data_limit_reset_strategy'=>'no_reset','group_ids'=>[(int)$m['group_id']],'proxy_settings'=>new stdClass(),'note'=>$m['marker']]);
        if(!in_array($code,[200,201,204],true))throw new RuntimeException('panel create');
        $j=daPgGet($s,$token,$m);
    }
    if(!$j)throw new RuntimeException('panel pending');
    $metrics=daPgMetrics($a,$j);daPgSub($s,$j);
    if($metrics['panel_status']!=='active'||$metrics['used_bytes']!==0||!in_array((int)$m['group_id'],$j['group_ids']??[],true))throw new RuntimeException('created account mismatch');
    $db->begin_transaction();
    try{daExec($db,"UPDATE delta_app_pg_users SET state='ready',remote_id=? WHERE account_id=? AND state='pending'",'ii',$j['id'],$id);daExec($db,'UPDATE delta_app_accounts SET active=1 WHERE id=?','i',$id);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
}
function daPgNew(mysqli $db,int $plan): array {
    $setting=daRow($db,'SELECT * FROM delta_app_pg_settings WHERE id=1');
    if(!$setting||$setting['panel_kind']!=='app')throw new InvalidArgumentException('ابتدا پنل مستقل برنامه را ثبت کنید.');
    daPgServer($db,(int)$setting['server_id'],$setting['panel_base']);
    // Reserve a durable local record and unique random remote identity before the network call.
    $db->begin_transaction();
    try{
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 FOR UPDATE','i',$plan);
        if(!$p)throw new InvalidArgumentException('پلن فعال پیدا نشد.');
        $name=null;for($i=0;$i<40;$i++){$n='delta'.random_int(10000,99999);if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$n)&&!daRow($db,'SELECT account_id FROM delta_app_pg_users WHERE pg_name=?','s',$n)){$name=$n;break;}}
        if($name===null)throw new RuntimeException('name');
        $pass=daPassword();$now=time();$expires=$now+(int)$p['days']*86400;
        daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(?,?,?,?,?,0,?,?)','ssiiiii',$name,password_hash($pass,PASSWORD_DEFAULT),$plan,(int)$p['gb'],$expires,$now,(int)$p['device_limit']);
        $id=(int)$db->insert_id;$remote=$name;$marker='delta-app:'.bin2hex(random_bytes(16));
        daExec($db,'INSERT INTO delta_app_pg_users(account_id,server_id,group_id,panel_base,pg_name,marker,panel_kind) VALUES(?,?,?,?,?,?,\'app\')','iiisss',$id,(int)$setting['server_id'],(int)$setting['group_id'],$setting['panel_base'],$remote,$marker);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    try{daPgProvision($db,$id);return ['id'=>$id,'username'=>$name,'password'=>$pass,'ready'=>true];}
    catch(Throwable $e){return ['id'=>$id,'username'=>$name,'ready'=>false];}
}
function daPgLive(mysqli $db,array $a,bool $withConfigs=false): array {
    $m=daPgMap($db,(int)$a['id']);
    if(!$m||$m['state']!=='ready')throw new InvalidArgumentException('حساب هنوز ساب اختصاصی ندارد.');
    $s=daPgServer($db,(int)$m['server_id'],$m['panel_base'],$m['panel_kind']);$token=daPgToken($s);$j=daPgGet($s,$token,$m);
    if(!$j)throw new RuntimeException('panel user missing');
    // A confirmed renewal may have committed while the panel request was running.
    $fresh=daAccount($db,(int)$a['id']);if(!$fresh)throw new RuntimeException('account missing');
    $a=$fresh;
    if(daRow($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_renewals'")){
        $pending=daRow($db,"SELECT r.target_limit,r.target_expires FROM delta_app_renewals r JOIN delta_app_real_orders o ON o.id=r.order_id WHERE r.account_id=? AND o.state='provisioning' AND r.target_limit IS NOT NULL LIMIT 1",'i',(int)$a['id']);
        if($pending&&(int)$pending['target_limit']===($j['data_limit']??null))throw new RuntimeException('renewal completion pending');
    }
    $metrics=daPgMetrics($a,$j,false);$metrics['configs']=[];
    if($withConfigs&&$metrics['can_connect']){
        [$code,$raw]=daPgHttp(daPgSub($s,$j),'GET',null,null,false,daPgRoute($s)['sub_resolve']);
        if($code!==200)throw new RuntimeException('sub fetch');
        $metrics['configs']=daExtract($raw);
    }
    return $metrics;
}
function daPgChange(mysqli $db,int $id,string $action): void {
    if(!in_array($action,['delete','enable','disable'],true))throw new InvalidArgumentException('action');
    daPgLocked($db,$id,fn()=>daPgChangeLocked($db,$id,$action));
}
function daPgChangeLocked(mysqli $db,int $id,string $action): void {
    $delete=$action==='delete';
    if($delete&&daRow($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_renewals'")&&daRow($db,"SELECT r.order_id FROM delta_app_renewals r JOIN delta_app_real_orders o ON o.id=r.order_id WHERE r.account_id=? AND o.state IN ('pending','paid','provisioning') LIMIT 1",'i',$id))throw new InvalidArgumentException('ابتدا فاکتور تمدید باز را تعیین تکلیف کنید.');
    $a=daAccount($db,$id);$m=daPgMap($db,$id);if(!$a)throw new InvalidArgumentException('حساب یافت نشد.');
    if(!$m){if($delete){daDeleteAccount($db,$id);return;}throw new InvalidArgumentException('حساب قدیمی بدون ساب اختصاصی است.');}
    // First close local login, keep the mapping until panel deletion is confirmed.
    if($delete||$action==='disable'){daExec($db,'UPDATE delta_app_accounts SET active=0 WHERE id=?','i',$id);daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);}
    $s=daPgServer($db,(int)$m['server_id'],$m['panel_base'],$m['panel_kind']);$t=daPgToken($s);$j=daPgGet($s,$t,$m);
    if($delete){
        if($j!==null){[$code,$raw]=daPgApi($s,daPgPath($m),'DELETE',$t);if(!in_array($code,[200,204,404],true)||daPgGet($s,$t,$m)!==null)throw new RuntimeException('delete pending');}
        $db->begin_transaction();try{daExec($db,'DELETE FROM delta_app_pg_users WHERE account_id=?','i',$id);daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);daExec($db,'DELETE FROM delta_app_accounts WHERE id=?','i',$id);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}
        return;
    }
    if(!$j||$m['state']!=='ready')throw new RuntimeException('panel not ready');
    $target=$action==='disable'?'disabled':'active';
    if($target==='active'){ $v=daPgMetrics($a,$j);if($v['remaining_bytes']===0||$v['expires']<=time())throw new InvalidArgumentException('حجم یا اعتبار تمام شده است.');}
    [$code,$raw]=daPgApi($s,daPgPath($m),'PUT',$t,['status'=>$target]);
    $after=daPgGet($s,$t,$m);
    if(!in_array($code,[200,204],true)||($after['status']??null)!==$target)throw new RuntimeException('state not confirmed');
    if($target==='active')daExec($db,'UPDATE delta_app_accounts SET active=1 WHERE id=?','i',$id);
}

function daContactName(string $value): string {
    $value=trim($value);
    if($value==='-'||$value==='')return '';
    $value=ltrim($value,'@');
    if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D',$value))throw new InvalidArgumentException('فقط آیدی تلگرام انگلیسی معتبر یا - برای حذف.');
    return $value;
}
function daContacts(mysqli $db): array {
    // Compatible with older deployments until /delta creates the new table.
    $row=daRow($db,'SELECT purchase,support FROM delta_app_contacts WHERE id=1');
    return ['purchase'=>$row['purchase']??'','support'=>$row['support']??''];
}
function daContactSet(mysqli $db,string $kind,string $value): void {
    if(!in_array($kind,['purchase','support'],true))throw new InvalidArgumentException('نوع آیدی نامعتبر است.');
    $value=daContactName($value);
    daExec($db,"INSERT INTO delta_app_contacts(id,$kind) VALUES(1,?) ON DUPLICATE KEY UPDATE $kind=VALUES($kind)",'s',$value);
}

function daRefreshSettings(mysqli $db): array {
    $r=daRow($db,'SELECT minutes,revision,reconnect_revision FROM delta_app_refresh WHERE id=1');
    return ['refresh_minutes'=>(int)($r['minutes']??5),'refresh_revision'=>(int)($r['revision']??0),'reconnect_revision'=>(int)($r['reconnect_revision']??0)];
}
function daRefreshInterval(mysqli $db,int $minutes): void {
    if($minutes<1||$minutes>1440)throw new InvalidArgumentException('فاصله باید بین ۱ تا ۱۴۴۰ دقیقه باشد.');
    daExec($db,'INSERT INTO delta_app_refresh(id,minutes) VALUES(1,?) ON DUPLICATE KEY UPDATE minutes=VALUES(minutes)','i',$minutes);
}
function daRefreshBroadcast(mysqli $db,bool $reconnect): array {
    $db->begin_transaction();
    try{
        daExec($db,'INSERT IGNORE INTO delta_app_refresh(id) VALUES(1)');
        $r=daRow($db,'SELECT revision FROM delta_app_refresh WHERE id=1 FOR UPDATE');
        $n=(int)$r['revision']+1;
        if($reconnect)daExec($db,'UPDATE delta_app_refresh SET revision=?,reconnect_revision=? WHERE id=1','ii',$n,$n);
        else daExec($db,'UPDATE delta_app_refresh SET revision=? WHERE id=1','i',$n);
        $db->commit();return daRefreshSettings($db);
    }catch(Throwable $e){$db->rollback();throw $e;}
}

// Device IDs are app-scoped, not a hardware attestation. The account row
// serializes login, migration, and revocation so no extra slot is granted.
// Absolute lifetime for new sessions; legacy tokens are capped on next use.
function daSessionTtl(): int {return 90*86400;}
function daSessionLookup(mysqli $db,string $hash,int $now): ?array {
    return daRow($db,'SELECT s.account_id,d.id FROM delta_app_sessions s JOIN delta_app_devices d ON d.token_hash=s.token_hash AND d.account_id=s.account_id WHERE s.token_hash=? AND s.expires>?','si',$hash,$now);
}
function daSessionCap(mysqli $db,string $hash,int $now): void {
    daExec($db,'UPDATE delta_app_sessions SET expires=LEAST(expires,?) WHERE token_hash=? AND expires>?','isi',$now+daSessionTtl(),$hash,$now);
}
function daDeviceLogin(mysqli $db,string $name,string $password,string $device,string $model,string $legacy=''): array {
    $db->begin_transaction();
    try{
        $a=daRow($db,'SELECT * FROM delta_app_accounts WHERE username=? FOR UPDATE','s',$name);
        if(!$a||!password_verify($password,$a['password_hash'])||(int)$a['active']!==1)throw new DomainException('login',401);
        $id=(int)$a['id'];
        $mapping=daPgMap($db,$id);
        if(!$mapping||$mapping['state']!=='ready')throw new DomainException('dedicated_required',409);
        $dh=hash('sha256',$device);
        $old=daRow($db,'SELECT * FROM delta_app_devices WHERE account_id=? AND device_hash=?','is',$id,$dh);
        // Migrate only an exact previous installation ID supplied alongside
        // the verified password, never infer ownership from phone model.
        if(!$old&&$legacy!==''&&$legacy!==$device){
            $old=daRow($db,'SELECT * FROM delta_app_devices WHERE account_id=? AND device_hash=?','is',$id,hash('sha256',$legacy));
        }
        $count=daRow($db,'SELECT COUNT(*) AS n FROM delta_app_devices WHERE account_id=?','i',$id);
        if(!$old&&(int)$count['n']>=(int)$a['device_limit'])throw new DomainException('device_limit',409);
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=time();
        if($old){
            daExec($db,'DELETE FROM delta_app_sessions WHERE token_hash=?','s',$old['token_hash']);
            daExec($db,'UPDATE delta_app_devices SET device_hash=?,token_hash=?,model=?,last_seen=? WHERE account_id=? AND id=?','sssiii',$dh,$hash,$model,$now,$id,(int)$old['id']);
        }else{
            daExec($db,'INSERT INTO delta_app_devices(account_id,device_hash,token_hash,model,created,last_seen) VALUES(?,?,?,?,?,?)','isssii',$id,$dh,$hash,$model,$now,$now);
        }
        daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=? AND token_hash NOT IN (SELECT token_hash FROM delta_app_devices WHERE account_id=?)','ii',$id,$id);
        daExec($db,'INSERT INTO delta_app_sessions(token_hash,account_id,expires) VALUES(?,?,?)','sii',$hash,$id,$now+daSessionTtl());
        $db->commit();
        return ['account'=>$a,'token'=>$token];
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daDeviceMigrateSession(mysqli $db,int $account,string $token,string $stable): void {
    $db->begin_transaction();
    try{
        $a=daRow($db,'SELECT id,active FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$account);
        $h=hash('sha256',$token);
        $dev=daRow($db,'SELECT d.id,d.device_hash FROM delta_app_devices d JOIN delta_app_sessions s ON s.token_hash=d.token_hash AND s.account_id=d.account_id WHERE d.account_id=? AND d.token_hash=? AND s.expires>?','isi',$account,$h,time());
        if(!$a||(int)$a['active']!==1||!$dev)throw new DomainException('session',401);
        $dh=hash('sha256',$stable);
        if($dev['device_hash']!==$dh){
            $other=daRow($db,'SELECT id FROM delta_app_devices WHERE account_id=? AND device_hash=?','is',$account,$dh);
            // Never silently kick a different already registered slot.
            if(!$other)daExec($db,'UPDATE delta_app_devices SET device_hash=? WHERE account_id=? AND id=?','sii',$dh,$account,(int)$dev['id']);
        }
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daDeviceList(mysqli $db,int $id): array {
    return array_map(fn($r)=>['id'=>(int)$r['id'],'model'=>$r['model'],'created'=>(int)$r['created'],'last_seen'=>(int)$r['last_seen'],'used_bytes'=>null],daRows($db,'SELECT id,model,created,last_seen FROM delta_app_devices WHERE account_id=? ORDER BY created,id','i',$id));
}
function daDeviceRevoke(mysqli $db,int $id,int $device): void {
    $db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id);
        $r=daRow($db,'SELECT token_hash FROM delta_app_devices WHERE account_id=? AND id=?','ii',$id,$device);
        if($r){daExec($db,'DELETE FROM delta_app_sessions WHERE token_hash=?','s',$r['token_hash']);daExec($db,'DELETE FROM delta_app_devices WHERE account_id=? AND id=?','ii',$id,$device);}
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function daSetDeviceLimit(mysqli $db,int $id,int $limit): void {
    if($limit<1||$limit>20)throw new InvalidArgumentException('تعداد دستگاه باید ۱ تا ۲۰ باشد.');
    $db->begin_transaction();
    try{
        if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id))throw new InvalidArgumentException('حساب یافت نشد.');
        $n=daRow($db,'SELECT COUNT(*) n FROM delta_app_devices WHERE account_id=?','i',$id);
        if((int)$n['n']>$limit)throw new InvalidArgumentException('ابتدا دستگاه‌های اضافی را خارج کنید.');
        daExec($db,'UPDATE delta_app_accounts SET device_limit=? WHERE id=?','ii',$limit,$id);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function daEditPanel(mysqli $db,int $id,array $s): void {
    $old=daPgServer($db,$id);
    $token=daPgToken($s);
    $maps=daRows($db,"SELECT * FROM delta_app_pg_users WHERE panel_kind='app' AND server_id=?",'i',$id);
    // Never redirect existing accounts to an unverified panel.
    $groups=daPgGroups($s,$token);$gids=array_column($groups,'id');
    if(!in_array((int)$old['group_id'],$gids,true))throw new InvalidArgumentException('گروه قبلی در پنل جدید در دسترس نیست.');
    foreach($maps as $m){
        if($m['state']!=='ready'||!in_array((int)$m['group_id'],$gids,true)||!daPgGet($s,$token,$m))throw new InvalidArgumentException('هویت حساب‌های قبلی در پنل جدید تأیید نشد.');
    }
    $secret=daPanelEncrypt($s['password'],$s['base'],$s['username'],daPanelKey($db));
    $db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_panels WHERE id=? FOR UPDATE','i',$id);
        $fresh=daRows($db,"SELECT * FROM delta_app_pg_users WHERE panel_kind='app' AND server_id=?",'i',$id);
        if($fresh!==$maps)throw new InvalidArgumentException('حساب‌های پنل تغییر کرده‌اند؛ دوباره تلاش کنید.');
        daExec($db,'UPDATE delta_app_panels SET panel_url=?,username=?,secret=?,sub_base_url=? WHERE id=?','ssssi',$s['base'],$s['username'],$secret,$s['sub_base_url'],$id);
        daExec($db,"UPDATE delta_app_pg_users SET panel_base=? WHERE panel_kind='app' AND server_id=?",'si',$s['base'],$id);
        daExec($db,"UPDATE delta_app_pg_settings SET panel_base=? WHERE panel_kind='app' AND server_id=?",'si',$s['base'],$id);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daDeletePanel(mysqli $db,int $id): void {
    $db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_panels WHERE id=? FOR UPDATE','i',$id);
        if(daRow($db,"SELECT account_id FROM delta_app_pg_users WHERE panel_kind='app' AND server_id=? LIMIT 1",'i',$id))throw new InvalidArgumentException('پنل دارای حساب است؛ حذف مجاز نیست.');
        daExec($db,"DELETE FROM delta_app_pg_settings WHERE panel_kind='app' AND server_id=?",'i',$id);
        daExec($db,'DELETE FROM delta_app_panels WHERE id=?','i',$id);
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

/** Owner-only test invoices; intentionally never modify pays or wallet. */
function daTestInvoice(mysqli $db,int $buyer,int $plan): int {
    $db->begin_transaction();
    try {
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 AND category_id IS NOT NULL FOR UPDATE','i',$plan);
        if(!$p)throw new InvalidArgumentException('پلن فعال یافت نشد.');
        $old=daRow($db,"SELECT id FROM delta_app_test_orders WHERE buyer=? AND plan_id=? AND state='pending' AND created>? ORDER BY id DESC LIMIT 1",'iii',$buyer,$plan,time()-900);
        if($old){$db->commit();return (int)$old['id'];}
        daExec($db,'INSERT INTO delta_app_test_orders(buyer,plan_id,name,gb,days,price,device_limit,created) VALUES(?,?,?,?,?,?,?,?)','iisiiiii',$buyer,$plan,$p['name'],(int)$p['gb'],(int)$p['days'],(int)$p['price'],(int)$p['device_limit'],time());
        $id=(int)$db->insert_id;$db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daTestOrder(mysqli $db,int $buyer,int $id): array {
    $o=daRow($db,'SELECT * FROM delta_app_test_orders WHERE id=? AND buyer=?','ii',$id,$buyer);
    if(!$o)throw new InvalidArgumentException('فاکتور پیدا نشد.');
    return $o;
}
function daTestCancel(mysqli $db,int $buyer,int $id): void {
    daExec($db,"UPDATE delta_app_test_orders SET state='cancelled' WHERE id=? AND buyer=? AND state='pending'",'ii',$id,$buyer);
}
function daTestApprove(mysqli $db,int $buyer,int $id): array {
    $lock='delta-test-order-'.$id;$l=daRow($db,'SELECT GET_LOCK(?,0) AS acquired','s',$lock);
    if((int)($l['acquired']??0)!==1)throw new InvalidArgumentException('فاکتور در حال پردازش است.');
    try{
        $o=daTestOrder($db,$buyer,$id);
        if($o['state']==='delivered')return ['order'=>$o,'password'=>null];
        if(!in_array($o['state'],['pending','provisioning'],true))throw new InvalidArgumentException('فاکتور قابل تأیید نیست.');
        if($o['state']==='pending'&&(int)$o['created']<time()-900)throw new InvalidArgumentException('فاکتور منقضی شده؛ دوباره پلن را انتخاب کنید.');
        if($o['account_id']===null){
            $setting=daRow($db,'SELECT * FROM delta_app_pg_settings WHERE id=1');
            if(!$setting||$setting['panel_kind']!=='app')throw new InvalidArgumentException('ابتدا پنل برنامه را انتخاب کنید.');
            daPgServer($db,(int)$setting['server_id'],$setting['panel_base']);
            $db->begin_transaction();
            try{
                $o=daRow($db,'SELECT * FROM delta_app_test_orders WHERE id=? AND buyer=? FOR UPDATE','ii',$id,$buyer);
                if(!$o||$o['state']!=='pending'||$o['account_id']!==null)throw new InvalidArgumentException('فاکتور تغییر کرده است.');
                $name=null;
                for($i=0;$i<40;$i++){
                    $n='delta'.random_int(10000,99999);
                    if(!daRow($db,'SELECT id FROM delta_app_accounts WHERE username=?','s',$n)&&!daRow($db,'SELECT account_id FROM delta_app_pg_users WHERE pg_name=?','s',$n)){$name=$n;break;}
                }
                if($name===null)throw new RuntimeException('name');
                $pass=daPassword();$now=time();$expires=$now+(int)$o['days']*86400;
                daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(?,?,?,?,?,0,?,?)','ssiiiii',$name,password_hash($pass,PASSWORD_DEFAULT),(int)$o['plan_id'],(int)$o['gb'],$expires,$now,(int)$o['device_limit']);
                $aid=(int)$db->insert_id;
                daExec($db,"INSERT INTO delta_app_pg_users(account_id,server_id,group_id,panel_base,pg_name,marker,panel_kind) VALUES(?,?,?,?,?,?,'app')",'iiisss',$aid,(int)$setting['server_id'],(int)$setting['group_id'],$setting['panel_base'],$name,'delta-app:'.bin2hex(random_bytes(16)));
                daExec($db,"UPDATE delta_app_test_orders SET account_id=?,state='provisioning',approved_at=? WHERE id=?",'iii',$aid,$now,$id);
                $db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}
        }
        $o=daTestOrder($db,$buyer,$id);$aid=(int)$o['account_id'];$map=daPgMap($db,$aid);
        if(!$map||!daAccount($db,$aid))throw new RuntimeException('account missing');
        if($map['state']!=='ready')daPgProvision($db,$aid);
        // Retry safely reuses the reserved account; fresh PIN only before completion.
        $pass=daPassword();
        $db->begin_transaction();
        try{
            daExec($db,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($pass,PASSWORD_DEFAULT),$aid);
            daExec($db,"UPDATE delta_app_test_orders SET state='delivered',delivered_at=? WHERE id=? AND state='provisioning'",'ii',time(),$id);
            $db->commit();
        }catch(Throwable $e){$db->rollback();throw $e;}
        return ['order'=>daTestOrder($db,$buyer,$id),'password'=>$pass];
    }finally{daRow($db,'SELECT RELEASE_LOCK(?) AS released','s',$lock);}
}
function daTestResetPassword(mysqli $db,int $buyer,int $order): string {
    $o=daTestOrder($db,$buyer,$order);
    if($o['state']!=='delivered'||!daAccount($db,(int)$o['account_id']))throw new InvalidArgumentException('حساب آماده نیست.');
    $id=(int)$o['account_id'];$p=daPassword();$db->begin_transaction();
    try{
        daRow($db,'SELECT id FROM delta_app_accounts WHERE id=? FOR UPDATE','i',$id);
        daExec($db,'UPDATE delta_app_accounts SET password_hash=? WHERE id=?','si',password_hash($p,PASSWORD_DEFAULT),$id);
        daExec($db,'DELETE FROM delta_app_sessions WHERE account_id=?','i',$id);
        daExec($db,'DELETE FROM delta_app_devices WHERE account_id=?','i',$id);
        $db->commit();return $p;
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function daNewsSchema(mysqli $db): void {
    foreach([
        "CREATE TABLE IF NOT EXISTS delta_app_news(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(8) CHARACTER SET ascii NOT NULL,body TEXT NOT NULL,url VARCHAR(2048) NOT NULL DEFAULT '',button_label VARCHAR(80) NOT NULL DEFAULT '',active TINYINT NOT NULL DEFAULT 1,created BIGINT UNSIGNED NOT NULL,INDEX(kind,active,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_news_seen(account_id INT UNSIGNED NOT NULL,news_id BIGINT UNSIGNED NOT NULL,seen_at BIGINT UNSIGNED NOT NULL,PRIMARY KEY(account_id,news_id)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_news_drafts(admin_id BIGINT PRIMARY KEY,kind VARCHAR(8) NOT NULL,body TEXT NOT NULL,url VARCHAR(2048) NOT NULL DEFAULT '',button_label VARCHAR(80) NOT NULL DEFAULT '',ticket CHAR(24) CHARACTER SET ascii NOT NULL,created BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ] as $sql)if(!$db->query($sql))throw new RuntimeException('news schema');
}
function daNewsText(string $body): string {
    $body=trim($body);
    if($body===''||mb_strlen($body)>2500)throw new InvalidArgumentException('متن خبر باید بین ۱ تا ۲۵۰۰ حرف باشد.');
    return $body;
}
function daNewsUrl(string $url): string {
    $url=trim($url);
    if($url==='')return '';
    $p=parse_url($url);
    if(strlen($url)>2048||!filter_var($url,FILTER_VALIDATE_URL)||!is_array($p)||strtolower($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new InvalidArgumentException('لینک معتبر HTTPS وارد کنید.');
    return $url;
}
function daNewsDraft(mysqli $db,int $admin,string $kind,string $body): string {
    if(!in_array($kind,['inbox','float'],true))throw new InvalidArgumentException('نوع خبر');
    $body=daNewsText($body);$ticket=bin2hex(random_bytes(12));
    daExec($db,"INSERT INTO delta_app_news_drafts(admin_id,kind,body,ticket,created) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE kind=VALUES(kind),body=VALUES(body),url='',button_label='',ticket=VALUES(ticket),created=VALUES(created)",'isssi',$admin,$kind,$body,$ticket,time());
    return $ticket;
}
function daNewsDraftLink(mysqli $db,int $admin,string $ticket,string $url,string $label): void {
    $url=daNewsUrl($url);$label=trim($label);
    if($url!==''&&($label===''||mb_strlen($label)>60))throw new InvalidArgumentException('عنوان دکمه باید ۱ تا ۶۰ حرف باشد.');
    $s=daQuery($db,'UPDATE delta_app_news_drafts SET url=?,button_label=? WHERE admin_id=? AND ticket=? AND created>=?','ssisi',$url,$url===''?'':$label,$admin,$ticket,time()-3600);
    $s->close();
}
function daNewsPublish(mysqli $db,int $admin,string $ticket): int {
    $db->begin_transaction();
    try{
        $d=daRow($db,'SELECT * FROM delta_app_news_drafts WHERE admin_id=? AND ticket=? AND created>=? FOR UPDATE','isi',$admin,$ticket,time()-3600);
        if(!$d)throw new InvalidArgumentException('پیش‌نویس منقضی شده یا قبلاً منتشر شده است.');
        $body=daNewsText($d['body']);$url=daNewsUrl($d['url']);
        if(!in_array($d['kind'],['inbox','float'],true))throw new RuntimeException('news kind');
        if($d['kind']==='float')daExec($db,"UPDATE delta_app_news SET active=0 WHERE kind='float' AND active=1");
        daExec($db,'INSERT INTO delta_app_news(kind,body,url,button_label,created) VALUES(?,?,?,?,?)','ssssi',$d['kind'],$body,$url,$d['button_label'],time());
        $id=(int)$db->insert_id;
        daExec($db,'DELETE FROM delta_app_news_drafts WHERE admin_id=? AND ticket=?','is',$admin,$ticket);
        $db->commit();return $id;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daNewsArchive(mysqli $db,int $id,string $kind): void {
    daExec($db,'UPDATE delta_app_news SET active=0 WHERE id=? AND kind=?','is',$id,$kind);
}
function daNewsItem(array $r): array {
    return ['id'=>(int)$r['id'],'body'=>$r['body'],'url'=>$r['url'],'button_label'=>$r['button_label'],'created'=>(int)$r['created'],'read'=>!empty($r['seen_at'])];
}
function daNewsFeed(mysqli $db,int $account,int $before=0): array {
    $count=daRow($db,"SELECT COUNT(*) n FROM delta_app_news n LEFT JOIN delta_app_news_seen s ON s.news_id=n.id AND s.account_id=? WHERE n.kind='inbox' AND n.active=1 AND s.news_id IS NULL",'i',$account);
    $rows=daRows($db,"SELECT n.*,s.seen_at FROM delta_app_news n LEFT JOIN delta_app_news_seen s ON s.news_id=n.id AND s.account_id=? WHERE n.kind='inbox' AND n.active=1 AND (?=0 OR n.id<?) ORDER BY n.id DESC LIMIT 31",'iii',$account,$before,$before);
    $more=count($rows)>30;$rows=array_slice($rows,0,30);
    $floating=daRow($db,"SELECT n.*,s.seen_at FROM delta_app_news n LEFT JOIN delta_app_news_seen s ON s.news_id=n.id AND s.account_id=? WHERE n.kind='float' AND n.active=1 ORDER BY n.id DESC LIMIT 1",'i',$account);
    return ['unread'=>(int)$count['n'],'news'=>array_map('daNewsItem',$rows),'next_before'=>$more?(int)end($rows)['id']:0,'floating'=>$floating&&empty($floating['seen_at'])?daNewsItem($floating):null];
}
function daNewsSeen(mysqli $db,int $account,int $id,string $kind): void {
    if(!in_array($kind,['inbox','float'],true)||$id<1)throw new InvalidArgumentException('news id');
    daExec($db,'INSERT IGNORE INTO delta_app_news_seen(account_id,news_id,seen_at) SELECT ?,id,? FROM delta_app_news WHERE id=? AND kind=? AND active=1','iiis',$account,time(),$id,$kind);
}


// APK updates are opt-in and restricted to the official stable public release.
function daUpdateConfig(mysqli $db): array {
    $off=['enabled'=>false,'version'=>'','url'=>''];
    try{$r=daRow($db,'SELECT enabled,version FROM delta_app_update WHERE id=1');}catch(Throwable $e){return $off;}
    $v=(string)($r['version']??'');
    if(!preg_match('/^(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})$/D',$v))return $off;
    return ['enabled'=>(int)$r['enabled']===1,'version'=>$v,'url'=>daUpdateUrl($v)];
}
function daUpdateUrl(string $v): string {
    return 'https://github.com/deltacoms/deltavpn/releases/download/v'.$v.'/DeltaVPN-'.$v.'-arm64.apk';
}
function daUpdateLatest(): string {
    $body='';$c=curl_init('https://api.github.com/repos/deltacoms/deltavpn/releases/latest');
    curl_setopt_array($c,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,CURLOPT_USERAGENT=>'DeltaVPN-Release-Admin',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],CURLOPT_WRITEFUNCTION=>function($c,$part)use(&$body){if(strlen($body)+strlen($part)>524288)return 0;$body.=$part;return strlen($part);}]);
    try{$ok=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);}finally{curl_close($c);}
    if($ok===false||$code!==200)throw new InvalidArgumentException('دریافت نسخه رسمی از گیت‌هاب ناموفق بود؛ تنظیم قبلی تغییر نکرد.');
    $r=json_decode($body,true);
    if(!is_array($r)||($r['draft']??true)!==false||($r['prerelease']??true)!==false||!preg_match('/^v((?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3})\.(?:0|[1-9][0-9]{0,3}))$/D',(string)($r['tag_name']??''),$m))throw new InvalidArgumentException('نسخه پایدار معتبر یافت نشد.');
    $v=$m[1];
    foreach(($r['assets']??[]) as $asset){
        if(($asset['browser_download_url']??'')===daUpdateUrl($v)&&($asset['state']??'')==='uploaded'&&(int)($asset['size']??0)>0)return $v;
    }
    throw new InvalidArgumentException('فایل APK رسمی نسخه جدید یافت نشد.');
}
function daUpdateEnable(mysqli $db): void {
    $v=daUpdateLatest();
    daExec($db,'INSERT INTO delta_app_update(id,enabled,version) VALUES(1,1,?) ON DUPLICATE KEY UPDATE enabled=1,version=VALUES(version)','s',$v);
}
function daUpdateDisable(mysqli $db): void {
    daExec($db,"UPDATE delta_app_update SET enabled=0 WHERE id=1");
}
