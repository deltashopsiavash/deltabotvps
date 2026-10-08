<?php
// Pure calculation tests; do not connect to a database or run the Telegram webhook.
require_once dirname(__DIR__) . '/admin_service_manager.php';
function assertEqual($actual,$want,$label){
    if($actual!==$want){
        fwrite(STDERR,"FAIL $label: got ".var_export($actual,true).", expected ".var_export($want,true)."\n");
        exit(1);
    }
}
$gb=1073741824;
assertEqual(deltaSvcCost('V',5),5,'minimum volume');
assertEqual(deltaSvcCost('V',10),10,'manual volume');
foreach([30=>2,35=>2,40=>2,41=>4,45=>4,50=>4,51=>5,59=>5,60=>5] as $d=>$cost)
    assertEqual(deltaSvcCost('D',$d),$cost,'day charge '.$d);
foreach([0=>0,15=>15,16=>16] as $v=>$cost)
    assertEqual(deltaSvcCost('R',0,$v*$gb),$cost,'reset integer '.$v);
assertEqual(deltaSvcCost('R',0,(int)(15.3*$gb)),15,'15.3 nearest');
assertEqual(deltaSvcCost('R',0,(int)(15.5*$gb)),16,'15.5 nearest');
assertEqual(deltaSvcCost('R',0,(int)(15.7*$gb)),16,'15.7 nearest');
assertEqual(deltaSvcTraffic(50*1048576),'50MB','MB display');
assertEqual(deltaSvcTraffic(20*$gb),'20GB','GB display');
assertEqual(deltaSvcTs(1760000000000),1760000000,'milliseconds to seconds');
assertEqual(deltaSvcTs(1760000000),1760000000,'seconds unchanged');
$half=deltaSvcBar(5,10);
assertEqual(strpos($half,'50% باقی‌مانده')!==false,true,'bar halfway reports remaining');
assertEqual(strpos($half,'🟨')!==false,true,'bar halfway uses warning color');
echo "Admin service quota calculations: PASS\n";
