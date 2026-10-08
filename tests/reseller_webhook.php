<?php
require_once dirname(__DIR__) . '/reseller_webhook.php';
function assertCandidate($got,$want,$label){
    if($got!==$want){fwrite(STDERR,"FAIL ".$label.": got ".var_export($got,true)." expected ".var_export($want,true)."\n");exit(1);}
}
$urls=deltaResellerWebhookCandidates(7,
    'https://working.example.org/apps/delta/bot.php',
    'https://old.example.net/wrong-path/',
    'live.example.org','/apps/delta/bot.php',true);
assertCandidate($urls[0],'https://working.example.org/apps/delta/bot.php?bid=7','inherit working mother handler');
assertCandidate($urls[1],'https://live.example.org/apps/delta/bot.php?bid=7','inherit actual HTTPS vhost');
assertCandidate($urls[2],'https://old.example.net/wrong-path/bot.php?bid=7','configured fallback only last');
$nonStandard=deltaResellerWebhookCandidates(9,'https://working.example.org/telegram/webhook.php','https://wrong.example.com/');
assertCandidate($nonStandard[0],'https://working.example.org/telegram/bot.php?bid=9','mother hook path may be differently named');
assertCandidate(deltaResellerWebhookCandidates(0,'https://working.example.org/bot.php','https://x.example.org/'),[],'invalid instance id');
assertCandidate(deltaResellerWebhookCandidates(2,'http://insecure.example.org/bot.php','http://old.example.org/'),[],'never register HTTP');
echo "Reseller webhook URL selection: PASS\n";
