<?php
require_once dirname(__DIR__) . '/admin_service_manager.php';
require_once dirname(__DIR__) . '/service_subscription_ui.php';
function checkServiceUi($yes,$label){
    if(!$yes){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
}
$blank=deltaSvcBar(0,100);
$green=deltaSvcBar(20,100);
$yellow=deltaSvcBar(40,100);
$orange=deltaSvcBar(60,100);
$red=deltaSvcBar(80,100);
$full=deltaSvcBar(100,100);
checkServiceUi(substr_count($blank,'⬜')===15,'100-percent remaining is all white');
checkServiceUi(strpos($blank,'🟩')===false && strpos($blank,'🟥')===false,'unused bar contains no color');
checkServiceUi(substr_count($green,'🟩')===3 && substr_count($green,'⬜')===12,'20-percent spent is green');
checkServiceUi(substr_count($yellow,'🟨')===6 && substr_count($yellow,'⬜')===9,'40-percent spent is yellow');
checkServiceUi(substr_count($orange,'🟧')===9 && substr_count($orange,'⬜')===6,'60-percent spent is orange');
checkServiceUi(substr_count($red,'🟥')===12 && substr_count($red,'⬜')===3,'80-percent spent is red');
checkServiceUi(substr_count($full,'🟥')===15,'100-percent spent is solid red');
checkServiceUi(strpos($full,'0%')!==false || strpos($full,'۰٪')!==false,'0-percent remaining');
checkServiceUi(strpos(deltaSvcBar(1,100),'🟩')!==false,'first bit of consumption begins green');
checkServiceUi(strpos(deltaSvcBar(21,100),'🟨')!==false,'after 20-percent is yellow');
checkServiceUi(strpos(deltaSvcBar(41,100),'🟧')!==false,'after 40-percent is orange');
checkServiceUi(strpos(deltaSvcBar(61,100),'🟥')!==false,'after 60-percent is red');
checkServiceUi(strpos($yellow,'🟩')===false && strpos($yellow,'🟧')===false,'no mixed colors');
checkServiceUi(strpos(deltaSvcBar(12,0),'♾️')!==false,'unlimited plans');
checkServiceUi(!dsShouldShowRenew('marzban',false,true),'standard renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('marzban',true,false),'standard renewal visible when enabled');
checkServiceUi(!dsShouldShowRenew('pasarguard',true,false),'dedicated renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('pasarguard',false,true),'dedicated renewal visible when enabled');
echo "Service UI gradient & renewal switches: PASS\n";
