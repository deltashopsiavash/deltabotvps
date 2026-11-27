#!/usr/bin/env bash
# Destructive ONLY to synthetic Delta fixture on a disposable GitHub-hosted VM.
set -Eeuo pipefail
umask 077
[[ $(id -u) == 0 && "${GITHUB_ACTIONS:-}" == true && "${RUNNER_ENVIRONMENT:-}" == github-hosted && "${DELTA_FULL_CI_VM:-}" == 1 ]] || exit 2
src=$(cd "$(dirname "$0")/.." && pwd)
for p in /var/www/html/deltabotvps /opt/vpsbot /opt/delta-sms-bridge /etc/delta-sms-bridge.php /var/lib/delta-full-backup; do
    [[ ! -e "$p" ]] || { echo 'Fixture target not empty'; exit 1; }
done
systemctl start mysql
export MYSQL_PWD=''
if ! mysql -uroot -e 'SELECT 1' >/dev/null 2>&1; then
    export MYSQL_PWD=root
    mysql -uroot -e 'SELECT 1' >/dev/null
    install -d -m 0700 /root/confdelta
    printf "%s\n" "\$pass = 'root';" > /root/confdelta/dbrootdelta.txt
fi
if mysql -uroot -NB -e 'SHOW DATABASES' | grep -Eq '^delta(_rb[0-9]+|_sms_bridge)?$'; then
    echo 'Fixture databases already exist'; exit 1
fi
install -d -m 0700 /etc/delta-full-backup /var/lib/delta-full-backup
printf '%s\n' 'disposable-ci-fixture-passphrase-123456789' > /etc/delta-full-backup/recovery.key
install -m 0755 "$src/scripts/delta-full-backup.py" /usr/local/sbin/delta-full-backup
mkdir -p /var/www/html/deltabotvps /opt/vpsbot /opt/delta-sms-bridge \
    /var/lib/delta-sms-bridge/events /etc/letsencrypt /var/www/delta-sms-bridge-public
cat > /var/www/html/deltabotvps/baseInfo.php <<'PHP'
<?php
$botToken = '123456:ciFixtureOnlyNeverSend';
$botUrl = 'https://fixture.invalid/deltabotvps/';
$admin = 12345;
$dbName = 'delta';
$dbUserName = 'delta_fixture_owner';
$dbPassword = 'fixture-local-db-password-123';
PHP
cp "$src/delta_app_core.php" /var/www/html/deltabotvps/delta_app_core.php
chmod 644 /var/www/html/deltabotvps/delta_app_core.php
chown root:www-data /var/www/html/deltabotvps/baseInfo.php
chmod 640 /var/www/html/deltabotvps/baseInfo.php
printf '%s\n' 'source fixture' > /var/www/html/deltabotvps/fixture.txt
printf '%s\n' 'DB_PATH=/opt/vpsbot/vpsbot.sqlite3' > /opt/vpsbot/.env
: > /opt/vpsbot/requirements.txt
printf '%s\n' '<?php return ["secret"=>"fixture-only"];' > /etc/delta-sms-bridge.php
printf '%s\n' 'bank-event-fixture' > /var/lib/delta-sms-bridge/events/fixture.json
python3 - <<'PY'
import sqlite3
with sqlite3.connect("/opt/vpsbot/vpsbot.sqlite3") as db:
    db.execute("CREATE TABLE orders(id INTEGER PRIMARY KEY)")
    db.execute("INSERT INTO orders VALUES(456)")
PY
mysql -uroot <<'SQL'
CREATE DATABASE delta;
CREATE DATABASE delta_rb72;
CREATE DATABASE delta_sms_bridge;
CREATE TABLE delta.reseller_bots(id INT PRIMARY KEY,db_name VARCHAR(80),status INT,is_deleted INT);
INSERT INTO delta.reseller_bots VALUES(71,'delta_rb71',0,1),(72,'delta_rb72',1,0);
CREATE TABLE delta.users(id INT PRIMARY KEY);
INSERT INTO delta.users VALUES(123);
CREATE TABLE delta_rb72.users(id INT PRIMARY KEY);
INSERT INTO delta_rb72.users VALUES(456);
CREATE TABLE delta_sms_bridge.events(id INT PRIMARY KEY);
INSERT INTO delta_sms_bridge.events VALUES(789);
CREATE USER 'delta_fixture_owner'@'localhost' IDENTIFIED BY 'fixture-local-db-password-123';
GRANT ALL PRIVILEGES ON delta.* TO 'delta_fixture_owner'@'localhost';
GRANT ALL PRIVILEGES ON delta_rb72.* TO 'delta_fixture_owner'@'localhost';
GRANT ALL PRIVILEGES ON delta_sms_bridge.* TO 'delta_fixture_owner'@'localhost';
SQL
runuser -u www-data -- php -r '
require "/var/www/html/deltabotvps/baseInfo.php";
require "/var/www/html/deltabotvps/delta_app_core.php";
$c=new mysqli("localhost",$dbUserName,$dbPassword,$dbName);
daSchema($c);
daPrepareRecoveryKey($c);
'
/usr/local/sbin/delta-full-backup create > /root/ci-delta-full.delta.gpg
[[ -s /root/ci-delta-full.delta.gpg ]]
digest=$(/usr/local/sbin/delta-full-backup verify-local < /root/ci-delta-full.delta.gpg | cut -d= -f2)
[[ "$digest" =~ ^[a-f0-9]{64}$ ]]
/usr/local/sbin/delta-full-backup rehearse "$digest"
# Drop ONLY exact fixture DB names and paths after validating the fixture marker.
grep -Fxq 'source fixture' /var/www/html/deltabotvps/fixture.txt
mysql -uroot <<'SQL'
DROP DATABASE delta;
DROP DATABASE delta_rb72;
DROP DATABASE delta_sms_bridge;
DROP USER 'delta_fixture_owner'@'localhost';
SQL
rm -rf /var/www/html/deltabotvps /opt/vpsbot /opt/delta-sms-bridge /var/lib/delta-sms-bridge
rm -f /etc/delta-sms-bridge.php
/usr/local/sbin/delta-full-backup fresh-apply "$digest"
[[ $(mysql -uroot -NB -e 'SELECT id FROM delta.users') == 123 ]]
[[ $(mysql -uroot -NB -e 'SELECT id FROM delta_rb72.users') == 456 ]]
[[ $(mysql -uroot -NB -e 'SELECT id FROM delta_sms_bridge.events') == 789 ]]
[[ $(mysql -uroot -NB -e "SELECT CONCAT(status,':',is_deleted) FROM delta.reseller_bots WHERE id=71") == 0:1 ]]
grep -Fxq 'source fixture' /var/www/html/deltabotvps/fixture.txt
grep -Fxq 'bank-event-fixture' /var/lib/delta-sms-bridge/events/fixture.json
python3 - <<'PY'
import sqlite3
with sqlite3.connect("file:/opt/vpsbot/vpsbot.sqlite3?mode=ro", uri=True) as db:
    assert db.execute("SELECT id FROM orders").fetchone() == (456,)
PY
! systemctl is-active --quiet apache2
! systemctl is-active --quiet cron
echo 'DELTA_FULL_FRESH_VM_TEST_PASSED'
