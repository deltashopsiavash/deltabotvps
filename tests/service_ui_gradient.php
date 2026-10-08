<?php
require_once dirname(__DIR__) . '/admin_service_manager.php';
require_once dirname(__DIR__) . '/service_subscription_ui.php';
function checkServiceUi($yes,$label){
    if(!$yes){fwrite(STDERR,"FAIL: ".$label."\n");exit(1);}
}
$green=deltaSvcBar(0,100);
$yellow=deltaSvcBar(40,100);
$orange=deltaSvcBar(65,100);
$red=deltaSvcBar(90,100);
$empty=deltaSvcBar(100,100);
checkServiceUi(strpos($green,'🟩')!==false,'fresh plan must be green');
checkServiceUi(strpos($yellow,'🟨')!==false,'60-percent remaining must be yellow');
checkServiceUi(strpos($orange,'🟧')!==false,'35-percent remaining must be orange');
checkServiceUi(strpos($red,'🟥')!==false,'10-percent remaining must be red');
checkServiceUi(strpos($green,'🟨')===false && strpos($green,'🟧')===false && strpos($green,'🟥')===false,'green bar never mixes colors');
checkServiceUi(strpos($yellow,'🟩')===false && strpos($yellow,'🟧')===false && strpos($yellow,'🟥')===false,'yellow bar never mixes colors');
checkServiceUi(strpos($orange,'🟩')===false && strpos($orange,'🟨')===false && strpos($orange,'🟥')===false,'orange bar never mixes colors');
checkServiceUi(strpos($red,'🟩')===false && strpos($red,'🟨')===false && strpos($red,'🟧')===false,'red bar never mixes colors');
checkServiceUi(strpos(deltaSvcBar(20,100),'🟩')!==false,'80-percent remaining is green');
checkServiceUi(strpos(deltaSvcBar(80,100),'🟧')!==false,'20-percent remaining is orange');
checkServiceUi(strpos(deltaSvcBar(81,100),'🟥')!==false,'below 20-percent remaining is red');
checkServiceUi(substr_count($empty,'🟥')===15,'fully exhausted must be solid red');
checkServiceUi(strpos($empty,'0%')!==false || strpos($empty,'۰٪')!==false,'zero remaining percentage');
checkServiceUi(strpos(deltaSvcBar(12,0),'♾️')!==false,'unlimited plans');
checkServiceUi(!dsShouldShowRenew('marzban',false,true),'standard renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('marzban',true,false),'standard renewal visible when enabled');
checkServiceUi(!dsShouldShowRenew('pasarguard',true,false),'dedicated renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('pasarguard',false,true),'dedicated renewal visible when enabled');
echo "Service UI gradient & renewal switches: PASS\n";
