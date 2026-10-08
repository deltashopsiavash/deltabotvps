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
checkServiceUi(strpos($yellow,'🟨')!==false,'middle-stage plan must be yellow');
checkServiceUi(strpos($orange,'🟧')!==false,'low quota must include orange');
checkServiceUi(strpos($red,'🟥')!==false,'almost exhausted must include red');
checkServiceUi(substr_count($empty,'🟥')===15,'fully exhausted must be solid red');
checkServiceUi(strpos($empty,'0%')!==false || strpos($empty,'۰٪')!==false,'zero remaining percentage');
checkServiceUi(strpos(deltaSvcBar(12,0),'♾️')!==false,'unlimited plans');
checkServiceUi(!dsShouldShowRenew('marzban',false,true),'standard renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('marzban',true,false),'standard renewal visible when enabled');
checkServiceUi(!dsShouldShowRenew('pasarguard',true,false),'dedicated renewal hidden when disabled');
checkServiceUi(dsShouldShowRenew('pasarguard',false,true),'dedicated renewal visible when enabled');
echo "Service UI gradient & renewal switches: PASS\n";
