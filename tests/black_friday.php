<?php
// Database-free behavioral tests for the DELTA Black Friday campaign.
require_once dirname(__DIR__).'/black_friday.php';
require_once dirname(__DIR__).'/black_friday_ui.php';
function bfAssert($test,$label){
    if(!$test){fwrite(STDERR,"FAIL: $label\n");exit(1);}
}
$c=bfDefaults();
bfAssert((int)$c['enabled']===0,'deployment starts disabled to protect normal pricing');
bfAssert((int)$c['one_percent']===15,'one-month discount');
bfAssert((int)$c['two_percent']===20,'two-month discount');
bfAssert((int)$c['new_percent']===25,'newcomer discount');
bfAssert((int)$c['new_capacity']===30,'newcomer capacity');
bfAssert((int)$c['gb_cost']===1000,'default GB cost');
bfAssert((int)$c['ends_at']-(int)$c['starts_at']===86400,'exactly 24 hours');
bfAssert(bfClock($c['starts_at'])==='2026-11-27 00:00','Tehran start time');
bfAssert(bfClock($c['ends_at'])==='2026-11-28 00:00','Tehran end time');
bfAssert(bfWindow($c,$c['starts_at']+1)==='disabled','disabled overrides schedule');
$c['enabled']=1;
bfAssert(bfWindow($c,$c['starts_at']-1)==='upcoming','campaign not yet started');
bfAssert(bfWindow($c,$c['starts_at'])==='active','campaign begins exactly on time');
bfAssert(bfWindow($c,$c['ends_at']-1)==='active','last second included');
bfAssert(bfWindow($c,$c['ends_at'])==='ended','campaign ends exactly on time');
bfAssert(bfDurationGroup(30)==='one' && bfDurationGroup(60)==='two','eligible periods');
bfAssert(bfDurationGroup(90)===null && bfDurationGroup(14)===null,'other periods excluded');
bfAssert(bfPriceWithPercent(80000,15)===68000,'80k one month at 15%');
bfAssert(bfPriceWithPercent(390000,20)===312000,'390k two month at 20%');
bfAssert(bfPriceWithPercent(390000,25)===292500,'390k newcomer two month at 25%');
bfAssert(bfPriceWithPercent(0,15)===0,'zero price remains zero');
bfAssert(bfParseClock('2026-11-27 00:00')===$c['starts_at'],'parse Tehran time');
bfAssert(bfParseClock('2026-11-31 00:00')===null,'bad date rejected');
bfAssert(bfParseClock('2026-11-27')===null,'incomplete date rejected');
bfAssert(bfPlanIdInput('all')===[],'all plans');
bfAssert(bfPlanIdInput('همه')===[],'Persian all plans');
bfAssert(bfPlanIdInput('12, 15,12، 23')===[12,15,23],'unique plan ID selection');
bfAssert(bfPlanIdInput('12,abcd')===null,'reject invalid plan selection');
bfAssert(bfCouponStepPayId('discountSelectPlan123_6_789')===789,'protect buy coupon path');
bfAssert(bfCouponStepPayId('discountRenew100_900')===900,'protect renew coupon path');
bfAssert(bfCouponStepPayId('discountCustomPlanDay19')===19,'protect custom buy coupon path');
bfAssert(bfCouponStepPayId('unrelated')===0,'ignore noncoupon steps');
$kb=json_decode(bfAdminKeyboard($c),true);
bfAssert(!empty($kb['inline_keyboard']),'admin menu is valid JSON keyboard');
$callbacks=[];
foreach($kb['inline_keyboard'] as $row){
    foreach($row as $btn){
        $callbacks[]=$btn['callback_data']??'';
    }
}
bfAssert(in_array('bfToggle',$callbacks,true),'admin can toggle festival');
bfAssert(in_array('bfEdit_one_percent',$callbacks,true),'admin can edit one-month');
bfAssert(in_array('bfEdit_two_percent',$callbacks,true),'admin can edit two-month');
bfAssert(in_array('bfEdit_new_percent',$callbacks,true),'admin can edit newcomer offer');
bfAssert(in_array('bfEdit_new_capacity',$callbacks,true),'admin can edit capacity');
bfAssert(in_array('bfEdit_starts_at',$callbacks,true) && in_array('bfEdit_ends_at',$callbacks,true),'admin can edit dates');
bfAssert(in_array('bfEdit_eligible_plan_ids',$callbacks,true),'admin can limit eligible plans');
// Verify checkout and coupon interception wiring remains reachable.
$bot=file_get_contents(dirname(__DIR__).'/bot.php');
bfAssert(strpos($bot,"require_once __DIR__ . '/black_friday.php'")!==false,'campaign loaded by bot');
bfAssert(strpos($bot,'if(bfHandleRequest()) exit;')!==false,'campaign handlers reached');
bfAssert(strpos($bot,'bfGuardCouponStep(')!==false,'coupon stacking guarded');
bfAssert(strpos($bot,'bfGuardPayment(')!==false,'expired card payments guarded');
bfAssert(substr_count($bot,'bfApplyInvoice($hash_id)')>=3,'buy and renew checkout pricing');
echo "Black Friday campaign rules: PASS\n";
