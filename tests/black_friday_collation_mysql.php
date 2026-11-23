<?php
/**
 * Integration test for MySQL 8.0's utf8mb4_0900_ai_ci vs utf8mb4_unicode_ci
 * comparison. Must run against a disposable CI MySQL service only.
 * No production database or customer data is touched.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host=getenv('DELTA_CI_MYSQL_HOST') ?: '127.0.0.1';
$port=(int)(getenv('DELTA_CI_MYSQL_PORT') ?: '3306');
$user=getenv('DELTA_CI_MYSQL_USER') ?: 'root';
$pass=getenv('DELTA_CI_MYSQL_PASSWORD') ?: '';
$db=getenv('DELTA_CI_MYSQL_DB') ?: 'delta_ci';
$conn=new mysqli($host,$user,$pass,$db,$port);
$conn->set_charset('utf8mb4');

$conn->query("CREATE TEMPORARY TABLE pays (
 id INT PRIMARY KEY,
 hash_id VARCHAR(128) COLLATE utf8mb4_0900_ai_ci NOT NULL,
 user_id BIGINT NOT NULL,
 state VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
$conn->query("CREATE TEMPORARY TABLE black_friday_invoices (
 hash_id VARCHAR(128) COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
 user_id BIGINT NOT NULL,
 campaign_key VARCHAR(40) NOT NULL,
 new_customer TINYINT NOT NULL,
 regular_price INT NOT NULL,
 discounted_price INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$conn->query("INSERT INTO pays VALUES
 (1,'BF-TEST-001',991,'approved'),
 (2,'BF-TEST-002',992,'pending'),
 (3,'BF-TEST-003',993,'approved')");
$conn->query("INSERT INTO black_friday_invoices VALUES
 ('BF-TEST-001',991,'event-1405',1,100000,75000),
 ('BF-TEST-002',992,'event-1405',1,100000,75000),
 ('BF-TEST-003',993,'event-1405',0,200000,160000)");

// Regression: all five invoice joins in bot and finance code must use explicit
// compatible collation rather than p.hash_id=b.hash_id (both IMPLICIT).
$predicate='p.hash_id COLLATE utf8mb4_unicode_ci=b.hash_id';
$repoRoot=dirname(__DIR__);
$bf=file_get_contents($repoRoot.'/black_friday.php');
$fin=file_get_contents($repoRoot.'/financial_reports.php');
if(substr_count($bf,$predicate)!==4 || substr_count($fin,$predicate)!==1){
    throw new RuntimeException('A Black Friday invoice/payments JOIN is missing explicit collation');
}
foreach([$bf,$fin] as $source){
    if(strpos($source,'p.hash_id=b.hash_id')!==false){
        throw new RuntimeException('An unsafe invoice hash JOIN remains');
    }
}
$join=" FROM black_friday_invoices b JOIN pays p ON {$predicate} ";

$testCases=[
    ["SELECT COUNT(*) AS n ".$join." WHERE b.campaign_key='event-1405'",3],
    ["SELECT COUNT(*) AS n ".$join." WHERE p.id=1",1],
    ["SELECT COUNT(*) AS n ".$join." WHERE b.new_customer=1 AND p.state='approved'",1],
    ["SELECT SUM(b.regular_price-b.discounted_price) AS n ".$join." WHERE p.state='approved'",65000],
    ["SELECT COUNT(*) AS n ".$join." WHERE b.campaign_key='not-this-event'",0],
];
foreach($testCases as $i=>$tc){
    $row=$conn->query($tc[0])->fetch_assoc();
    if((int)$row['n']!==$tc[1]){
        throw new RuntimeException("Incorrect collated join result #$i");
    }
}
echo "Black Friday mixed-collation MySQL integration test: PASS\n";
