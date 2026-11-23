<?php
/**
 * Dedicated festival selector real MySQL 8 integration test.
 * Runs against CI-only DB; no live customer records or credentials.
 */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection=new mysqli(
    getenv('DELTA_CI_MYSQL_HOST')?:'127.0.0.1',
    getenv('DELTA_CI_MYSQL_USER')?:'root',
    getenv('DELTA_CI_MYSQL_PASSWORD')?:'',
    getenv('DELTA_CI_MYSQL_DB')?:'delta_ci',
    (int)(getenv('DELTA_CI_MYSQL_PORT')?:3306)
);
$connection->set_charset('utf8mb4');
$connection->query("CREATE TEMPORARY TABLE server_info (
  id INT PRIMARY KEY,title VARCHAR(150),active INT,state INT,ucount INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$connection->query("CREATE TEMPORARY TABLE server_plans (
  id INT PRIMARY KEY,title VARCHAR(150),volume DECIMAL(10,2),days INT,price INT,
  catid INT,server_id INT,active INT,inbound_id INT,acount INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$connection->query("INSERT INTO server_info VALUES
  (1,'Tehran',1,1,100),(2,'Germany',1,1,100),(3,'Disabled',0,0,0)");
$connection->query("INSERT INTO server_plans VALUES
  (101,'20GB One Month',20,30,100000,10,1,1,0,10),
  (102,'30GB One Month',30,30,140000,10,1,1,0,10),
  (103,'40GB One Month',40,30,180000,10,1,1,0,10),
  (201,'50GB Two Months',50,60,250000,20,2,1,0,10),
  (202,'100GB Two Months',100,60,480000,20,2,1,0,10),
  (203,'150GB Disabled',150,60,680000,20,3,1,0,10),
  (204,'200GB Inactive',200,60,800000,20,1,0,0,10)");
require_once dirname(__DIR__).'/black_friday_storefront.php';
function testAssert($x,$description){
 if(!$x)throw new RuntimeException('FAIL: '.$description);
}
$cfg=['eligible_plan_ids'=>[101,102,201,202,203]];
$monthly=bfListPlanRows('one',0,true,$cfg);
$twomonth=bfListPlanRows('two',0,true,$cfg);
testAssert($monthly['total']===2,'only selected 20GB and 30GB monthly plans appear');
testAssert(array_column($monthly['items'],'id')===[101,102],'monthly plan order');
testAssert($twomonth['total']===2,'only selected available 50GB and 100GB two-month plans appear');
testAssert(array_column($twomonth['items'],'id')===[201,202],'disabled servers never offered');
testAssert(bfListPlanRows('one',0,true,['eligible_plan_ids'=>[]])['total']===0,'no plan selected means no sale');
testAssert(bfListPlanRows('two',0,false)['total']===3,'admin sees all active two-month plans, even hidden server (can unselect)');
testAssert(bfGroupPeriod('one')[0]===28 && bfGroupPeriod('two')[1]===62,'duration filtering');
testAssert(bfLoadPlan(201)['server_title']==='Germany','plan loading and region correctly joined');
echo "Black Friday plan picker and selected-only storefront MySQL: PASS\n";
