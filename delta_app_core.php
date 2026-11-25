<?php
declare(strict_types=1);
// Isolated app preview: each pre-provisioned upstream subscription is allocated once.
function daSchema(mysqli $db): void {
    foreach ([
        "CREATE TABLE IF NOT EXISTS delta_app_plans (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, gb INT UNSIGNED NOT NULL, days INT UNSIGNED NOT NULL, price BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_stock (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, plan_id INT UNSIGNED NOT NULL, url TEXT NOT NULL, url_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE, active TINYINT NOT NULL DEFAULT 1, INDEX(plan_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_accounts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, stock_id INT UNSIGNED NOT NULL UNIQUE, plan_id INT UNSIGNED NOT NULL, gb INT UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, created BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS delta_app_sessions (token_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, account_id INT UNSIGNED NOT NULL, expires BIGINT UNSIGNED NOT NULL, INDEX(account_id), INDEX(expires)) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS delta_app_limits (key_hash CHAR(64) CHARACTER SET ascii PRIMARY KEY, hits INT UNSIGNED NOT NULL, bucket BIGINT UNSIGNED NOT NULL, INDEX(bucket)) ENGINE=InnoDB"
    ] as $sql) { if (!$db->query($sql)) throw new RuntimeException('schema'); }
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
function daPassword(): string {return bin2hex(random_bytes(12));}
function daCreate(mysqli $db,int $plan,string $name): string {
    if(!daName($name))throw new InvalidArgumentException('نام کاربری ۴ تا ۴۸ حرف کوچک انگلیسی، عدد یا _ باشد.');
    $pass=daPassword();$hash=password_hash($pass,PASSWORD_DEFAULT);$db->begin_transaction();
    try{
        $p=daRow($db,'SELECT * FROM delta_app_plans WHERE id=? AND active=1 FOR UPDATE','i',$plan);
        if(!$p)throw new InvalidArgumentException('پلن فعال پیدا نشد.');
        $s=daRow($db,'SELECT s.id FROM delta_app_stock s LEFT JOIN delta_app_accounts a ON a.stock_id=s.id WHERE s.plan_id=? AND s.active=1 AND a.id IS NULL ORDER BY s.id LIMIT 1 FOR UPDATE','i',$plan);
        if(!$s)throw new InvalidArgumentException('موجودی ساب اختصاصی این پلن خالی است.');
        $now=time();$expires=$now+(int)$p['days']*86400;
        daExec($db,'INSERT INTO delta_app_accounts(username,password_hash,stock_id,plan_id,gb,expires,created) VALUES(?,?,?,?,?,?,?)','ssiiiii',$name,$hash,(int)$s['id'],$plan,(int)$p['gb'],$expires,$now);
        $db->commit();return $pass;
    }catch(Throwable $e){$db->rollback();throw $e;}
}
function daAccount(mysqli $db,int $id): ?array {
    return daRow($db,'SELECT a.*,s.url,s.active AS stock_active FROM delta_app_accounts a JOIN delta_app_stock s ON s.id=a.stock_id WHERE a.id=?','i',$id);
}
function daAllowed(?array $a): bool {return $a!==null && (int)$a['active']===1 && (int)$a['stock_active']===1 && (int)$a['expires']>time() && daValidUrl($a['url']);}
function daLimit(mysqli $db,string $key,int $max): bool {
    $h=hash('sha256',$key);$b=intdiv(time(),300);
    daExec($db,'INSERT INTO delta_app_limits(key_hash,hits,bucket) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=IF(bucket=VALUES(bucket),hits+1,1),bucket=VALUES(bucket)','si',$h,$b);
    $r=daRow($db,'SELECT hits FROM delta_app_limits WHERE key_hash=?','s',$h);
    return (int)$r['hits']<=$max;
}
