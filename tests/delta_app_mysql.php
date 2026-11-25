<?php
declare(strict_types=1);
require_once __DIR__.'/../delta_app_core.php';
if(getenv('DELTA_CI_MYSQL_DB')!=='delta_ci')throw new RuntimeException('CI database only');
$db=new mysqli((string)getenv('DELTA_CI_MYSQL_HOST'),(string)getenv('DELTA_CI_MYSQL_USER'),(string)getenv('DELTA_CI_MYSQL_PASSWORD'),'delta_ci',(int)getenv('DELTA_CI_MYSQL_PORT'));
$db->set_charset('utf8mb4');
function ok($x){if(!$x)throw new RuntimeException('assertion');}
daSchema($db);daSchema($db);
ok(daValidUrl('https://pmainp1.deltamarket2.ir:8000/sub/test_123'));
ok(!daValidUrl('http://pmainp1.deltamarket2.ir:8000/sub/test_123'));
ok(!daValidUrl('https://other.invalid/sub/test'));
$tag=bin2hex(random_bytes(6));
daExec($db,'INSERT INTO delta_app_plans(name,gb,days,price) VALUES(?,?,?,?)','siii',$tag,10,30,100000);
$p=$db->insert_id;
$secret=daCreate($db,$p,'test_'.$tag);daCreate($db,$p,'other_'.$tag);
$a=daRow($db,'SELECT * FROM delta_app_accounts WHERE username=?','s','test_'.$tag);
ok($a&&password_verify($secret,$a['password_hash'])&&$a['stock_id']===null);
ok(daAllowed(daAccount($db,(int)$a['id'])));
$nodes=['vless://example@invalid:443#test','ss://example#test'];
ok(daExtract(base64_encode(implode("\n",$nodes)))===$nodes);
daSaveSource($db,'https://pmainp1.deltamarket2.ir:8000/sub/'.$tag,$nodes);
daSaveSource($db,'https://pmainp1.deltamarket2.ir:8000/sub/'.$tag.'_2',[$nodes[0]]);
ok(daCatalog($db)===$nodes);
daExec($db,'UPDATE delta_app_accounts SET active=0 WHERE id=?','i',(int)$a['id']);
ok(!daAllowed(daAccount($db,(int)$a['id'])));
ok(daLimit($db,$tag,1));ok(!daLimit($db,$tag,1));
echo "Delta shared catalog, extraction, account independence and hashes OK\n";
