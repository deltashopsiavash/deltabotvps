<?php
// No production database / bot credentials needed for pure finance rules tests.
require_once dirname(__DIR__).'/financial_reports.php';
function finAssert($v,$name){
    if(!$v){fwrite(STDERR,"FAIL: ".$name."\n");exit(1);}
}
finAssert(finIsSaleType('BUY_SUB'),'new purchase is a sale');
finAssert(finIsSaleType('RENEW_ACCOUNT'),'renewals are sales');
finAssert(finIsSaleType('RENEW_SCONFIG'),'sub config renewals are sales');
finAssert(finIsSaleType('PG_RENEW_FULL_42_8'),'PasarGuard full renewals are sales');
finAssert(finIsSaleType('PG_RENEW_VOLUME_42_8'),'volume renewals are sales');
finAssert(finIsSaleType('INCREASE_VOLUME_14'),'extra volume is a sale');
finAssert(!finIsSaleType('INCREASE_WALLET'),'wallet topup is not a service sale');
finAssert(!finIsSaleType('UNRELATED'),'unrelated types are not sales');
finAssert(strpos(finPaidStatesSql(),"'paid_with_wallet'")!==false,'wallet-paid invoice counted');
finAssert(strpos(finPaidStatesSql(),"'approved'")!==false,'approved invoice counted');
finAssert(strpos(finSaleTypeSql(),"INCREASE_WALLET")===false,'wallet topup not counted in sale query');
finAssert(finPeriod('all')['start']===0,'unbounded history');
finAssert(finPeriod('today')['start']>0,'today bounded');
finAssert(finPeriod('yesterday')['end']===finPeriod('today')['start'],'yesterday boundaries');
finAssert(finPeriod('week')['end']>finPeriod('week')['start'],'seven days period');
finAssert(finPeriod('jmonth')['start']>0,'Persian month date period');
finAssert(finPeriod('c1700000000-1700100000')['key']==='c1700000000-1700100000','custom date');
finAssert(finPeriod('c1700000000-1699999999')['key']==='all','reject reversed custom range');
finAssert(finCostPerGb()===1000,'default cost');
finAssert(count(finExpenseCategories())===5,'all expense categories defined');
finAssert(finCurrency(1000)==='1,000 تومان','formatted currency');
$src=file_get_contents(dirname(__DIR__).'/config.php');
$bot=file_get_contents(dirname(__DIR__).'/bot.php');
finAssert(strpos($src,"'callback_data'=>'finDashboard'")!==false,'owner finance panel is linked');
finAssert(strpos($bot,"require_once __DIR__ . '/financial_reports.php';")!==false,'finance module included');
finAssert(strpos($bot,'if(finHandleRequest()) exit;')!==false,'finance controller installed');
$keys=json_decode(finReportKeyboard(finPeriod('all'),'overview'),true);
$callbacks=[];
foreach(($keys['inline_keyboard']??[]) as $row){
    foreach($row as $button)$callbacks[]=$button['callback_data']??'';
}
finAssert(in_array('finExpenseMenu',$callbacks,true),'expense panel linked');
finAssert(in_array('finPeriods',$callbacks,true),'period controls linked');
finAssert(in_array('finView_overview_all',$callbacks,true),'refresh works');

// Clicking the dashboard must immediately show navigation, not run a full-history query.
$finCaptured=[];
function smartSendOrEdit($id,$message,$keys,$parseMode=null){
    global $finCaptured;
    $finCaptured=['id'=>$id,'text'=>$message,'keys'=>$keys,'parse'=>$parseMode];
    return true;
}
$GLOBALS['message_id']=123;
finShowHome();
finAssert(isset($finCaptured['keys']),'finance landing responds immediately');
$menu=json_decode($finCaptured['keys'],true);
$menuActions=[];
foreach(($menu['inline_keyboard']??[]) as $row){
    foreach($row as $button)$menuActions[]=$button['callback_data']??'';
}
finAssert(in_array('finView_overview_today',$menuActions,true),'fast today report available');
finAssert(in_array('finView_overview_week',$menuActions,true),'fast week report available');
finAssert(in_array('finView_overview_all',$menuActions,true),'all-history report still available');
finAssert(in_array('finExpenseMenu',$menuActions,true),'expense menu available');
// SQL failures are surfaced instead of silently returning invented zero totals.
class FinBrokenDb {
    public function query($sql){throw new RuntimeException('simulated database error');}
}
$GLOBALS['connection']=new FinBrokenDb();
$thrown=false;
try{finQueryRow('SELECT 1');}catch(RuntimeException $e){$thrown=($e->getMessage()==='FIN_REPORT_DB_QUERY');}
finAssert($thrown,'database exceptions propagate to handler for user-visible error');
unset($GLOBALS['connection']);
echo "Financial dashboard tests: PASS\n";
