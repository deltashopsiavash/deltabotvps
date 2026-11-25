<?php
declare(strict_types=1);
// Shared subscription catalog, independent account plan metadata.
function daSchema(mysqli $db): void {
    foreach ([
        "CREATE TABLE IF NOT EXISTS delta_app_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, gb INT UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL, price BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_stock (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL, url TEXT NOT NULL, url_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1, INDEX(plan_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, stock_id INT UNSIGNED NULL UNIQUE, plan_id INT UNSIGNED NOT NULL, gb INT UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, created BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_sessions (token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, account_id INT UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, INDEX(account_id), INDEX(expires)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_sources (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, url TEXT NOT NULL, url_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, payload MEDIUMTEXT NOT NULL, nodes INT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, updated BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_limits (key_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, hits INT UNSIGNED NOT NULL, bucket BIGINT UNSIGNED NOT NULL, INDEX(bucket)) ENGINE=InnoDB"
    ] as $sql) { if (!$db->query($sql)) throw new RuntimeException('schema'); }
    $col=daRow($db,"SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_accounts' AND COLUMN_NAME='stock_id'");
    if($col && $col['IS_NULLABLE']==='NO' && !$db->query('ALTER TABLE delta_app_accounts MODIFY stock_id INT UNSIGNED NULL'))throw new RuntimeException('migration');
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
function daPassword(): string {return (string)random_int(10000,99999);}
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
