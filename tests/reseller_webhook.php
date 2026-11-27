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
$token='fixture-only-token';
$ranges=['149.154.160.0/20','91.108.4.0/22'];
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'203.0.113.10','HTTP_X_FORWARDED_FOR'=>'149.154.167.1','HTTP_CF_CONNECTING_IP'=>'149.154.167.1','HTTP_X_REAL_IP'=>'149.154.167.1'],$token,$ranges),false,'spoofed forwarding headers rejected');
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'203.0.113.10','HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>hash('sha256',$token)],$token,$ranges),true,'correct secret works behind proxy');
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'149.154.167.1','HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'=>'wrong'],$token,$ranges),false,'invalid supplied secret never falls back');
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'149.154.167.1'],$token,$ranges),true,'direct Telegram compatibility');
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'2001:db8::1'],$token,$ranges),false,'untrusted IPv6 denied');
assertCandidate(deltaWebhookAuthorized(['REMOTE_ADDR'=>'149.154.167.1'],'',$ranges),false,'empty token rejected');
echo "Reseller webhook URL and authentication: PASS\n";
