<?php
// Isolated eligibility rules; no DB, Telegram API, or panel login.
require_once dirname(__DIR__).'/admin_service_manager.php';
require_once dirname(__DIR__).'/phone_registration.php';
function phoneAssert($actual, $expected, $label) {
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: $label expected ".var_export($expected,true)." got ".var_export($actual,true)."\n");
        exit(1);
    }
}
$now=1800000000;
$gb=1073741824;
$order=['expire_date'=>$now+86400];
$live=['found'=>true,'enabled'=>true,'status'=>'active','expire'=>$now+86400,'total'=>40*$gb,'used'=>10*$gb];
phoneAssert(phoneRegAssessSnapshot($order,$live,$now),'active','active valid subscription');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['used'=>40*$gb]),$now),'inactive','quota exhausted');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['expire'=>$now]),$now),'inactive','expired');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['enabled'=>false]),$now),'inactive','disabled');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['status'=>'disabled']),$now),'inactive','invalid status');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['total'=>0,'used'=>0]),$now),'active','unlimited traffic');
phoneAssert(phoneRegAssessSnapshot($order,array_merge($live,['expire'=>0]),$now),'active','unlimited time');
phoneAssert(phoneRegAssessSnapshot($order,['found'=>false],$now),'unknown','panel unverified and remaining term');
phoneAssert(phoneRegAssessSnapshot(['expire_date'=>$now-86400],['found'=>false],$now),'inactive','already expired without panel');
echo "Phone eligibility rules: PASS\n";
