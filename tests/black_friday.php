<?php
// Database-free behavioral tests for the DELTA Black Friday campaign.
require_once dirname(__DIR__).'/black_friday.php';
require_once dirname(__DIR__).'/black_friday_ui.php';
require_once dirname(__DIR__).'/black_friday_storefront.php';
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
bfAssert($c['eligible_plan_ids']===[],'all eligible monthly plans included by default');
$key=bfCampaignKey($c);
$future=$c;$future['starts_at']+=604800;$future['ends_at']+=604800;
bfAssert(bfCampaignKey($future)!==$key,'future events have independent new-customer capacities and reports');
bfAssert((int)$c['ends_at']-(int)$c['starts_at']===432000,'five days inclusive of Black Friday');
bfAssert(bfClock($c['starts_at'])==='2026-11-23 00:00','Tehran start time');
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
bfAssert(bfPriceWithPercent(80000,25)===60000,'80k newcomer one month at 25%');
bfAssert(bfPriceWithPercent(0,15)===0,'zero price remains zero');
bfAssert(bfParseClock('2026-11-23 00:00')===$c['starts_at'],'parse Tehran time');
bfAssert(bfParseClock('2026-11-31 00:00')===null,'bad date rejected');
bfAssert(bfParseClock('2026-11-27')===null,'incomplete date rejected');
bfAssert(bfPlanIdInput('all')===[],'all plans');
bfAssert(bfPlanIdInput('همه')===[],'Persian all plans');
bfAssert(bfPlanIdInput('12, 15,12، 23')===[12,15,23],'unique plan ID selection');
bfAssert(bfPlanIdInput('12,abcd')===null,'reject invalid plan selection');
bfAssert(bfGroupPeriod('one')[0]===28 && bfGroupPeriod('two')[1]===62,'both supported periods');
bfAssert(bfGroupPeriod('renew')===null,'renewals have no storefront');
bfAssert(strpos(bfPlanLabel(['id'=>12,'title'=>'Delta','volume'=>20]),'20 گیگ')!==false,'plan labels expose volume');
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
bfAssert(in_array('bfPlans_one_0',$callbacks,true),'admin can tick monthly plans');
bfAssert(in_array('bfPlans_two_0',$callbacks,true),'admin can tick bimonthly plans');
bfAssert(!in_array('bfEdit_eligible_plan_ids',$callbacks,true),'old manual plan IDs removed');
// Regression: the existing pays table may use utf8mb4_0900_ai_ci while
// black_friday_invoices uses utf8mb4_unicode_ci. All comparisons must
// explicitly normalize the pays-side column before comparing invoice IDs.
$bfSource=file_get_contents(dirname(__DIR__).'/black_friday.php');
$collationJoin='JOIN pays p ON p.hash_id COLLATE utf8mb4_unicode_ci=b.hash_id';
bfAssert(substr_count($bfSource,$collationJoin)===4,'all four festival joins use compatible collation');
bfAssert(strpos($bfSource,'JOIN pays p ON p.hash_id=b.hash_id')===false,'no remaining incompatible festival joins');

// Verify checkout and coupon interception wiring remains reachable.
$bot=file_get_contents(dirname(__DIR__).'/bot.php');
bfAssert(strpos($bot,"require_once __DIR__ . '/black_friday.php'")!==false,'campaign loaded by bot');
bfAssert(strpos($bot,'if(bfHandleRequest()) exit;')!==false,'campaign handlers reached');
bfAssert(strpos($bot,'bfGuardCouponStep(')!==false,'coupon stacking guarded');
bfAssert(strpos($bot,'bfGuardPayment(')!==false,'expired card payments guarded');
bfAssert(strpos($bot,'bfApplyInvoice($hash_id,true)')!==false,'only dedicated new-service checkout uses BF pricing');
bfAssert(strpos($bot,'$bfDedicated?bfApplyInvoice($hash_id,true):null')!==false,'festival invoice requires explicit checkout');
bfAssert(strpos($bot,"require_once __DIR__ . '/black_friday_storefront.php';")!==false,'new BF direct storefront is loaded');
bfAssert(strpos($bot,'bfAdminSaleSummary($match[1])')!==false,'admin receives festival-specific purchase report');
bfAssert(strpos($bot,'$bfSale=$specialOffer?null:bfApplyInvoice($hash_id)')!==false,
    'limited special offers cannot receive festival stacking');
$gateway=file_get_contents(dirname(__DIR__).'/pay/index.php');
bfAssert(strpos($gateway,'bfFindInvoice($hash_id)')!==false,
    'gateway checkout checks festival invoice deadline');


// Regression: opening Black Friday management must not touch the database schema
// or count VIP invoice reservations. It should paint the menu immediately.
$bfCaptured=null;
$bfFakeConfigError=false;
$bfNotifyMessage='';
function getSettingValue($type,$default=null){
    global $bfFakeConfigError;
    if($bfFakeConfigError)throw new RuntimeException('simulated settings database failure');
    return $default;
}
function smartSendOrEdit($id,$text,$keys=null,$mode=null){
    global $bfCaptured;
    $bfCaptured=['id'=>$id,'text'=>$text,'keys'=>$keys,'mode'=>$mode];
    return true;
}
function sendMessage($txt,$keys=null,$mode=null,$to=null){
    global $bfNotifyMessage;
    $bfNotifyMessage=(string)$txt;
    return true;
}
$GLOBALS['message_id']=321;
$GLOBALS['data']='bfAdmin';
$GLOBALS['userInfo']=['step'=>'none','isAdmin'=>0];
$GLOBALS['from_id']=9876;
$GLOBALS['admin']=9876;
$GLOBALS['update']=(object)[];
bfAssert(bfHandleRequest()===true,'festival admin callback handled');
bfAssert(is_array($bfCaptured),'festival menu rendered without requiring MySQL');
bfAssert(strpos($bfCaptured['text'],'مدیریت جشنواره دلتا')!==false,'festival management text');
bfAssert(strpos($bfCaptured['text'],'۲۵')===false || strpos($bfCaptured['text'],'25')!==false || strpos($bfCaptured['text'],'٪')!==false,'festival discount text');
bfAssert(strpos($bfCaptured['text'],'ظرفیت کل خرید اول ویژه')!==false,'quota presented without a live DB count');
$bfFakeConfigError=true;
$bfNotifyMessage='';
bfAssert(bfHandleRequest()===true,'festival DB failure handled');
bfAssert(strpos($bfNotifyMessage,'BF-')!==false,'festival DB failure returns diagnostic code');
$bfFakeConfigError=false;


// Regressions: separate festival catalog, selected-only pricing and admin report.
$bfCore=file_get_contents(dirname(__DIR__).'/black_friday.php');
$store=file_get_contents(dirname(__DIR__).'/black_friday_storefront.php');
bfAssert(strpos($bfCore,"if((string)($pay['type']??'')!=='BUY_SUB')return null;")!==false,'renewals never get festival discount');
bfAssert(strpos($bfCore,"if(!$dedicatedCheckout || !empty($isChildBot))return null;")!==false,'ordinary purchases never get festival discount');
bfAssert(strpos($bfCore,"!in_array((int)$plan['plan_id'],$config['eligible_plan_ids'],true)")!==false,'only explicitly selected plan IDs discounted');
bfAssert(strpos($store,"'callback_data'=>'bfBuy_'.$id")!==false,'festival catalog buttons lead to direct checkout');
bfAssert(strpos($store,"$data='selectPlan'.$planId.'_'.(int)$plan['catid'].'_bf';")!==false,'separate buyType marks festival purchase');
bfAssert(strpos($store,'bfPriceWithPercent((int)$p[\'price\'],$percentage)')!==false,'festival plan list shows discounted price');
bfAssert(strpos($bot,'if($bfDedicated && !bfIsCheckoutPlanAllowed(')!==false,'validate festival selection before paying');
bfAssert(strpos($bot,'bfAdminSaleSummary($payProvisionHash)')!==false,'admin report attached to batch delivery');
$mainConfig=file_get_contents(dirname(__DIR__).'/config.php');
$mainStart=strpos($mainConfig,'function getMainKeys(){');
$mainEnd=strpos($mainConfig,'function getAgentKeys(){');
$mainSection=substr($mainConfig,$mainStart,$mainEnd-$mainStart);
bfAssert(strpos($mainSection,'array_unshift($mainKeys,$bfRow)')>strrpos($mainSection,'charityBuildMainMenuRows()'),'festival appears above charity and other buttons');

echo "Black Friday campaign rules: PASS\n";
