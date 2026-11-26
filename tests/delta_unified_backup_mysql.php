<?php
declare(strict_types=1);
require_once __DIR__.'/../delta_backup_integrity.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if(getenv('DELTA_TEST_DB')!=='delta_checkout_fixture')throw new RuntimeException('fixture only');
$host=getenv('DELTA_TEST_HOST')?:'127.0.0.1';$user=getenv('DELTA_TEST_USER')?:'root';$pass=(string)getenv('DELTA_TEST_PASSWORD');$port=(int)(getenv('DELTA_TEST_PORT')?:3306);
$source='delta_checkout_fixture';$dest='delta_backup_restore_fixture';
$db=new mysqli($host,$user,$pass,$source,$port);$db->set_charset('utf8mb4');
$dbPassword='fixture-old-db-password';$botToken='fixture-same-mother-bot-token';
function backupOk(bool $ok,string $msg): void{if(!$ok)throw new RuntimeException($msg);echo "PASS $msg\n";}
function sqlTool(array $cmd,string $input,string $output): void {
    $p=proc_open($cmd,[0=>['file',$input,'r'],1=>['file',$output,'w'],2=>STDERR],$pipes);
    if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('SQL tool failed');
}
function snapshotAll(mysqli $db): array {
    $snapshot=[];
    foreach(daRows($db,"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME") as $t){
        $name=$t['TABLE_NAME'];$rows=daRows($db,'SELECT * FROM '.chr(96).$name.chr(96));
        $rows=array_map('serialize',$rows);sort($rows,SORT_STRING);$snapshot[$name]=hash('sha256',serialize($rows));
    }
    return $snapshot;
}
$base='https://fixture.invalid';$name='fixture-admin';$panelPassword='fixture-panel-secret';
$secret=daPanelEncrypt($panelPassword,$base,$name,daLegacyPanelKey());
daExec($db,'INSERT INTO delta_app_panels(id,panel_url,username,secret,sub_base_url,group_id,created) VALUES(1,?,?,?,?,1,?)','ssssi',$base,$name,$secret,$base,time());
$hash=password_hash('fixture-user-password',PASSWORD_DEFAULT);
daExec($db,'INSERT INTO delta_app_accounts(id,username,password_hash,plan_id,gb,expires,active,created,device_limit) VALUES(100,?,?,1,10,?,1,?,2)','ssii','fixture_user',$hash,time()+86400,time());
$tokenHash=hash('sha256','fixture-session');
daExec($db,'INSERT INTO delta_app_sessions(token_hash,account_id,expires) VALUES(?,100,?)','si',$tokenHash,time()+86400);
daExec($db,'INSERT INTO delta_app_devices(account_id,device_hash,token_hash,model,created,last_seen) VALUES(100,?,?,?,?,?)','sssii',hash('sha256','fixture-device'),$tokenHash,'Fixture phone',time(),time());
daPrepareRecoveryKey($db);
$key=daPanelKey($db);
backupOk(hash_equals($key,daLegacyPanelKey()),'existing panel key preserved');
$env=daRow($db,'SELECT envelope FROM delta_app_backup_key WHERE id=1')['envelope'];
backupOk($env!==base64_encode($key),'no plaintext panel key in SQL envelope');
daPrepareRecoveryKey($db);
backupOk(daRow($db,'SELECT envelope FROM delta_app_backup_key WHERE id=1')['envelope']===$env,'existing envelope is not overwritten');
$before=snapshotAll($db);
$file=tempnam(sys_get_temp_dir(),'delta-backup-test-');chmod($file,0600);
putenv('MYSQL_PWD='.$pass);
$args=['--host='.$host,'--port='.$port,'--user='.$user,'--default-character-set=utf8mb4'];
try{
    sqlTool(array_merge(['mysqldump'], $args,['--lock-all-tables','--quick','--no-tablespaces','--hex-blob',$source]),'/dev/null',$file);
    backupOk(deltaUnifiedBackupCheck($file,$db,true,true),'full SQL dump has every live table and valid recovery envelope');
    backupOk(deltaUnifiedBackupSign($file)&&deltaUnifiedBackupCheck($file,$db,true),'signed backup validates');
    $bad=$file.'.bad';copy($file,$bad);file_put_contents($bad,"-- corruption\n",FILE_APPEND);
    backupOk(!deltaUnifiedBackupCheck($bad,$db),'tampered SQL rejected');
    unlink($bad);
    $savedToken=$botToken;$botToken='different-token';
    backupOk(!deltaUnifiedBackupCheck($file,$db),'wrong bot token rejected before restore');
    $botToken=$savedToken;
    $db->query('CREATE DATABASE IF NOT EXISTS delta_backup_restore_fixture CHARACTER SET utf8mb4');
    sqlTool(array_merge(['mysql'],$args,[$dest]),$file,'/dev/null');
    $restored=new mysqli($host,$user,$pass,$dest,$port);$restored->set_charset('utf8mb4');
    $dbPassword='fixture-new-db-password';
    backupOk(snapshotAll($restored)===$before,'all database rows match after restore into separate database');
    backupOk(daPgServer($restored,1)['password']===$panelPassword,'panel password works after database password change');
    backupOk(password_verify('fixture-user-password',daRow($restored,'SELECT password_hash FROM delta_app_accounts WHERE id=100')['password_hash']),'existing app login hash preserved');
    backupOk(daRow($restored,'SELECT model FROM delta_app_devices WHERE account_id=100')['model']==='Fixture phone','registered device preserved');
    backupOk(daRow($restored,'SELECT token_hash FROM delta_app_sessions WHERE account_id=100')['token_hash']===$tokenHash,'existing session hash preserved');
    require_once __DIR__.'/../delta_app_real_checkout.php';
    $trial=daTrialCurrent($restored,1001);
    backupOk($trial!==null&&$trial['method']==='trial'&&(int)$trial['price']===0,'trial claim and one-time eligibility survive restore');
    backupOk(daTrialReserve($restored,1001,true)===(int)$trial['id'],'restored claim cannot create another trial');
    $renew=daRenewLatest($restored,(int)$trial['id']);
    backupOk($renew!==null&&daQuotaLabel(daAccount($restored,(int)$trial['account_id'])['gb'])==='10','renewed service and quota baseline survive restore');
    $feed=daNewsFeed($restored,(int)$trial['account_id']);
    backupOk($feed['unread']===1&&$feed['floating']!==null&&$feed['floating']['body']==='Second floating','announcement read and floating state survive restore');
    $restored->close();
}finally{
    @unlink($file);putenv('MYSQL_PWD');
    $db->query('DROP DATABASE IF EXISTS delta_backup_restore_fixture');
}
echo "All unified backup round-trip checks passed.\n";
