<?php
// HTTP trigger for broadcast worker.
// Purpose: allow starting broadcast on hosts where shell_exec/nohup is disabled.
@ini_set('display_errors', '0');
@ini_set('log_errors', '0');
@set_time_limit(0);

$bid = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;
// Ensure bid is available BEFORE config.php loads
if($bid > 0){
    $_GET['bid'] = $bid;
}

require_once __DIR__ . '/baseInfo.php';
require_once __DIR__ . '/config.php';

$token = $GLOBALS['botToken'] ?? ($botToken ?? '');
$adm   = $GLOBALS['admin'] ?? ($admin ?? 0);
$expected = hash('sha256', $token . '|' . $adm . '|broadcast');
$given = $_GET['key'] ?? '';

if(!is_string($given) || !hash_equals($expected, $given)){
    http_response_code(403);
    exit('forbidden');
}

// Run the worker (it uses lock files, so concurrent triggers are safe)
require_once __DIR__ . '/broadcast_worker.php';

echo 'ok';
