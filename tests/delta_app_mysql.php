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
$generated=[];for($i=0;$i<100;$i++){$password=daPassword();ok(preg_match('/^[A-Za-z0-9_-]{22}$/D',$password)===1);ok(!isset($generated[$password]));$generated[$password]=true;}
$auto=daAutoCreate($db,$p);ok(preg_match('/^delta[0-9]{5}$/D',$auto['username'])===1);ok(preg_match('/^[A-Za-z0-9_-]{22}$/D',$auto['password'])===1);$autoRow=daRow($db,'SELECT password_hash FROM delta_app_accounts WHERE username=?','s',$auto['username']);ok(password_verify($auto['password'],$autoRow['password_hash']));
$a=daRow($db,'SELECT * FROM delta_app_accounts WHERE username=?','s','test_'.$tag);
ok($a&&password_verify($secret,$a['password_hash'])&&$a['stock_id']===null);
ok(daAllowed(daAccount($db,(int)$a['id'])));
$nodes=['vless://example@invalid:443#test','ss://example#test'];
ok(daExtract(base64_encode(implode("\n",$nodes)))===$nodes);
$xray='{"inbounds":[],"outbounds":[{"protocol":"vless","settings":{}}],"routing":{"rules":[]}}';
ok(daExtract($xray)===[$xray]);
ok(daExtract('['.$xray.','.$xray.']')===[$xray]);
ok(daExtract(base64_encode('['.$xray.']'))===[$xray]);
ok(daExtract("vless://example@invalid:443#test name")===['vless://example@invalid:443#test%20name']);
$wg="[Interface]\nPrivateKey = test\n[Peer]\nPublicKey = test";
ok(daExtract($wg)===[$wg]);
$bad=false;try{daExtract('<html>not a subscription</html>');}catch(InvalidArgumentException $e){$bad=true;}ok($bad);

daSaveSource($db,'https://pmainp1.deltamarket2.ir:8000/sub/'.$tag,$nodes);
daSaveSource($db,'https://pmainp1.deltamarket2.ir:8000/sub/'.$tag.'_2',[$nodes[0]]);
ok(daCatalog($db)===$nodes);
daExec($db,'UPDATE delta_app_accounts SET active=0 WHERE id=?','i',(int)$a['id']);
ok(!daAllowed(daAccount($db,(int)$a['id'])));
ok(daLimit($db,$tag,1));ok(!daLimit($db,$tag,1));
ok(daRemainingSeconds(100,99)===1);ok(daRemainingSeconds(100,101)===0);
$matches=daFindUsers($db,$auto['username']);ok(count($matches)===1);$aid=(int)$matches[0]['id'];
daExec($db,'INSERT INTO delta_app_sessions(token_hash,account_id,expires) VALUES(?,?,?)','sii',hash('sha256',$tag),$aid,time()+100);
ok(daDeleteAccount($db,$aid));ok(daAccount($db,$aid)===null);ok(!daRow($db,'SELECT account_id FROM delta_app_sessions WHERE account_id=?','i',$aid));ok(!daDeleteAccount($db,$aid));
ok(daIranDate(1710909000)==='1403/01/01 11:40:00' || str_starts_with(daIranDate(1710909000),'1403/01/01'));
echo "Delta shared catalog, extraction, account independence and hashes OK\n";

$fake=['gb'=>10,'expires'=>time()+3600,'active'=>1];
$j=['data_limit'=>10*1073741824,'used_traffic'=>12345,'expire'=>gmdate('c',$fake['expires']),'status'=>'active','data_limit_reset_strategy'=>'no_reset'];
$v=daPgMetrics($fake,$j);ok($v['can_connect']&&$v['remaining_bytes']===10*1073741824-12345);
$j['used_traffic']=$j['data_limit'];ok(!daPgMetrics($fake,$j)['can_connect']);
$j['data_limit']=0;$bad=false;try{daPgMetrics($fake,$j);}catch(RuntimeException $e){$bad=true;}ok($bad);
ok(daPgBase('https://panel.example:8000/dashboard/')==='https://panel.example:8000');
ok(daPgSub(['base'=>'https://panel.example:8000'],['subscription_url'=>'/sub/abc.def'])==='https://panel.example:8000/sub/abc.def');

$key=random_bytes(32);$base='https://192.0.2.1:8000';$name='app-admin';
$secret=daPanelEncrypt('test-password',$base,$name,$key);
ok($secret!=='test-password'&&daPanelDecrypt($secret,$base,$name,$key)==='test-password');
$bad=false;try{daPanelDecrypt($secret,$base,'other-admin',$key);}catch(RuntimeException $e){$bad=true;}ok($bad);
ok(daPgBase($base.'/dashboard/')===$base);
daExec($db,'INSERT INTO delta_app_panels(panel_url,username,secret,sub_base_url,group_id,created) VALUES(?,?,?,?,?,?)','ssssii',$base,'app-'.$tag,$secret,$base,7,time());
$panel1=$db->insert_id;
daExec($db,'INSERT INTO delta_app_panels(panel_url,username,secret,sub_base_url,group_id,created) VALUES(?,?,?,?,?,?)','ssssii',$base,'other-'.$tag,$secret,$base,8,time());
ok($panel1!==$db->insert_id);
ok(daRow($db,"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='delta_app_pg_users' AND COLUMN_NAME='panel_kind'")!==null);

$route=daPgRoute(['base'=>'https://192.0.2.1:8000','sub_base_url'=>'https://panel.example:8000']);
ok($route['api_base']==='https://panel.example:8000');
ok($route['api_resolve']===['panel.example:8000:192.0.2.1']);
ok($route['sub_resolve']===$route['api_resolve']);
$route=daPgRoute(['base'=>'https://192.0.2.1:8000','sub_base_url'=>'https://panel.example']);
ok($route['api_base']==='https://panel.example:8000'&&$route['sub_resolve']===[]);
$route=daPgRoute(['base'=>'https://panel.example:8000','sub_base_url'=>'https://sub.example:8000']);
ok($route['api_base']==='https://panel.example:8000'&&$route['api_resolve']===[]);
$route=daPgRoute(['base'=>'https://192.0.2.1:8000']);
ok($route['api_base']==='https://192.0.2.1:8000'&&$route['api_resolve']===[]);

$g=daPgParseGroups(['groups'=>[['id'=>42,'name'=>'DeltaApp'],['id'=>7,'name'=>'Disabled','is_disabled'=>true]]]);
ok($g===[['id'=>42,'name'=>'DeltaApp']]);
ok(daPgParseGroups(['data'=>['items'=>[['group_id'=>'15','title'=>'Test']]]])===[['id'=>15,'name'=>'Test']]);
ok(daPgParseGroups(['error'=>'no access'])===null);
ok(daPgParseGroups(['groups'=>[]])===[]);
$ticket=bin2hex(random_bytes(12));
daExec($db,'INSERT INTO delta_app_panel_drafts(ticket,panel_id,panel_url,username,secret,sub_base_url,groups_json,expires) VALUES(?,?,?,?,?,?,?,?)','sisssssi',$ticket,null,'https://example.com','test',$secret,'https://example.com',json_encode($g),time()+600);
ok(daPanelDraftGet($db,$ticket)['panel_id']===null);
$bad=false;try{daPanelDraftFinish($db,$ticket,99);}catch(InvalidArgumentException $e){$bad=true;}ok($bad);
daExec($db,'DELETE FROM delta_app_panel_drafts WHERE ticket=?','s',$ticket);

ok(daContactName('@DeltaSupport')==='DeltaSupport');ok(daContactName('-')==='');
$bad=false;try{daContactName('https://evil.example');}catch(InvalidArgumentException $e){$bad=true;}ok($bad);
daContactSet($db,'purchase','@DeltaBuy');daContactSet($db,'support','@DeltaSupport');ok(daContacts($db)===['purchase'=>'DeltaBuy','support'=>'DeltaSupport']);
daContactSet($db,'purchase','-');ok(daContacts($db)['purchase']===''&&daContacts($db)['support']==='DeltaSupport');

ok(daRefreshSettings($db)['refresh_minutes']===5);
daRefreshInterval($db,9);ok(daRefreshSettings($db)['refresh_minutes']===9);
$r=daRefreshBroadcast($db,true);ok($r['refresh_revision']===1&&$r['reconnect_revision']===1);
$r=daRefreshBroadcast($db,false);ok($r['refresh_revision']===2&&$r['reconnect_revision']===1);
