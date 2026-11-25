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
ok(!daValidUrl('https://pmainp1.deltamarket2.ir:8000/sub/test?x=1'));
ok(!daValidUrl('https://other.invalid/sub/test'));
$tag=bin2hex(random_bytes(6));
daExec($db,'INSERT INTO delta_app_plans(name,gb,days,price) VALUES(?,?,?,?)','siii',$tag,10,30,100000);
$p=$db->insert_id;
$url='https://pmainp1.deltamarket2.ir:8000/sub/'.$tag;
daExec($db,'INSERT INTO delta_app_stock(plan_id,url,url_hash) VALUES(?,?,?)','iss',$p,$url,hash('sha256',$url));
$secret=daCreate($db,$p,'test_'.$tag);
$a=daRow($db,'SELECT * FROM delta_app_accounts WHERE username=?','s','test_'.$tag);
ok($a&&password_verify($secret,$a['password_hash'])&&$secret!==$a['password_hash']);
ok(daAllowed(daAccount($db,(int)$a['id'])));
$failed=false;
try {daCreate($db,$p,'other_'.$tag);} catch(InvalidArgumentException $e){$failed=true;}
ok($failed);
daExec($db,'UPDATE delta_app_accounts SET active=0 WHERE id=?','i',(int)$a['id']);
ok(!daAllowed(daAccount($db,(int)$a['id'])));
ok(daLimit($db,$tag,1));ok(!daLimit($db,$tag,1));
echo "Delta account schema, validation, stock allocation and password hashing OK\n";
