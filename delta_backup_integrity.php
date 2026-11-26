<?php
require_once __DIR__.'/delta_app_core.php';
// Validate a combined SQL file without executing it or exposing credentials.
// Every app table currently in the database must be present in a new dump.
function deltaUnifiedBackupCheck(string $path, mysqli $db, bool $compareLive=false, bool $unsigned=false): bool {
    $fh=@fopen($path,'rb');if(!$fh)return false;
    $tables=[];$complete=false;$envelope=null;$signature=null;
    $token=(string)($GLOBALS['botToken']??'');if($token===''){fclose($fh);return false;}
    $ctx=hash_init('sha256',HASH_HMAC,hash_hkdf('sha256',$token,32,'delta-unified-sql-v1'));
    try{
        while(($line=fgets($fh))!==false){
            if($signature!==null)return false;
            if(preg_match('/^-- DELTA_UNIFIED_V1 ([a-f0-9]{64})\s*$/D',$line,$sig)){$signature=$sig[1];continue;}
            hash_update($ctx,$line);
            if(preg_match('/^CREATE TABLE `([A-Za-z0-9_]+)`/D',$line,$m))$tables[$m[1]]=true;
            if(preg_match('/^INSERT INTO `delta_app_backup_key` VALUES \(1,\x27([A-Za-z0-9+\/=]+)\x27\);/D',$line,$m))$envelope=$m[1];
            if(str_starts_with($line,'-- Dump completed on '))$complete=true;
        }
    }finally{fclose($fh);}
    $digest=hash_final($ctx);
    if(!$unsigned&&($signature===null||!hash_equals($digest,$signature)))return false;
    if(!$complete||!isset($tables['users'],$tables['pays'],$tables['setting'],$tables['delta_app_accounts'],$tables['delta_app_real_orders'],$tables['delta_app_pg_users'],$tables['delta_app_devices'],$tables['delta_app_sessions'],$tables['delta_app_panels'],$tables['delta_app_backup_key'])||$envelope===null)return false;
    if($compareLive){
        $q=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
        while($r=$q->fetch_assoc()){if(!isset($tables[$r['TABLE_NAME']])){$q->close();return false;}}
        $q->close();
    }
    try{
        require_once __DIR__.'/delta_app_core.php';
        daRecoveryUnwrap($envelope,$token);
        return true;
    }catch(Throwable $e){return false;}
}


function deltaUnifiedBackupSign(string $path): bool {
    $token=(string)($GLOBALS['botToken']??'');if($token==='')return false;
    $digest=hash_hmac_file('sha256',$path,hash_hkdf('sha256',$token,32,'delta-unified-sql-v1'));
    if($digest===false)return false;
    $footer="-- DELTA_UNIFIED_V1 ".$digest."\n";
    return file_put_contents($path,$footer,FILE_APPEND|LOCK_EX)===strlen($footer);
}
function deltaUnifiedBackupIsMarked(string $path): bool {
    $size=filesize($path);if($size===false)return false;
    $tail=file_get_contents($path,false,null,max(0,$size-512));
    return is_string($tail)&&str_contains($tail,'-- DELTA_UNIFIED_V1 ');
}

function deltaUnifiedBackupHasTable(string $path,string $table): bool {
    $fh=@fopen($path,'rb');if(!$fh)return false;
    $prefix='CREATE TABLE `'.$table.'`';
    try{while(($line=fgets($fh))!==false){if(str_starts_with($line,$prefix))return true;}}finally{fclose($fh);}
    return false;
}
function deltaUnifiedBackupHasRecoveryTable(string $path): bool {
    return deltaUnifiedBackupHasTable($path,'delta_app_backup_key');
}

function deltaLegacyBackupLooksComplete(string $path): bool {
    $size=filesize($path);if($size===false||$size<1024)return false;
    $tail=file_get_contents($path,false,null,max(0,$size-4096));
    if(!is_string($tail)||!preg_match('/(?:-- Dump completed on [^\r\n]+|SET FOREIGN_KEY_CHECKS=1;)\s*\z/D',$tail))return false;
    foreach(['users','pays','setting'] as $table)if(!deltaUnifiedBackupHasTable($path,$table))return false;
    return true;
}
