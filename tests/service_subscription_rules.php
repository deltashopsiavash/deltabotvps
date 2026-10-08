<?php
require_once dirname(__DIR__) . '/admin_service_manager.php';
require_once dirname(__DIR__) . '/service_subscription_ui.php';
function testRule($expr,$label){
    if(!$expr){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
}
$g=1073741824;$now=1760000000;
testRule(dsRefundEligible($now-1,$now,0,20*$g),'new unused subscription eligible');
testRule(dsRefundEligible($now-86399,$now,(int)(0.9*$g),20*$g),'23h59m and 0.9GiB eligible');
testRule(!dsRefundEligible($now-86400,$now,0,20*$g),'exact 24h ineligible');
testRule(!dsRefundEligible($now-86401,$now,0,20*$g),'older than 24h ineligible');
testRule(!dsRefundEligible($now-3,$now,$g,20*$g),'exactly 1GiB ineligible');
testRule(!dsRefundEligible($now-3,$now,(int)(1.1*$g),20*$g),'over 1GiB ineligible');
testRule(!dsRefundEligible(0,$now,0,20*$g),'unknown purchase time no refund');
testRule(!dsRefundEligible($now+2,$now,0,20*$g),'future purchase date no refund');
testRule(!dsRefundEligible($now-1,$now,0,0),'unlimited no credit');
testRule(!dsRefundEligible($now-1,$now,(int)(0.2*$g),20*$g,3),'traffic reset prevents quota laundering');
// Deleting a 20 GiB plan with 5 GiB of manual charges:
// eligible 0.4GiB usage returns 24 GiB (floor remaining),
// leaves one GiB charge. Ineligible leaves all 25 GiB charged.
$base=20;$manual=5;$remaining=(int)floor(((25-0.4)*$g)/$g);
$refund=min($base+$manual,$remaining);
testRule($refund===24,'safe rounded refundable remaining quota');
testRule($manual + ($base-$refund)===1,'charged quota after eligible deletion');
testRule($manual+$base===25,'charged quota after ineligible deletion');
echo "Service deletion rules: PASS\n";
