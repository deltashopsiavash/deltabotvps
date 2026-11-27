#!/usr/bin/env bash
# Dedicated blank Ubuntu 24.04 VPS only; no live Telegram webhook registration.
set -Eeuo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
src=$(cd "$(dirname "$0")" && pwd)
grep -Eq '^VERSION_ID="?24\.04"?$' /etc/os-release || { echo 'UBUNTU_24_04_REQUIRED'; exit 1; }
for p in /var/www/html/deltabotvps/baseInfo.php /opt/vpsbot /opt/delta-sms-bridge; do
    [[ ! -e "$p" ]] || { echo 'TARGET_NOT_FRESH'; exit 1; }
done
# If MySQL already exists, refuse a Delta database before touching packages.
if command -v mysql >/dev/null && systemctl is-active --quiet mysql; then
    dbs=$(mysql -uroot -NB -e 'SHOW DATABASES' 2>/dev/null) || { echo 'EXISTING_MYSQL_REQUIRES_REVIEW'; exit 1; }
    if printf '%s\n' "$dbs" | grep -Eq '^delta(_rb[0-9]+|_sms_bridge)?$'; then
        echo 'TARGET_DATABASES_ALREADY_EXIST'; exit 1
    fi
fi
[[ -t 0 ]] || { echo 'Interactive root terminal required'; exit 1; }
read -r -p 'Confirm dedicated blank VPS; type FRESH-DELTA: ' ack
[[ "$ack" == FRESH-DELTA ]] || exit 1
export DEBIAN_FRONTEND=noninteractive
apt-get update
printf '%s\n' 'phpmyadmin phpmyadmin/dbconfig-install boolean false' | debconf-set-selections
apt-get install -y apache2 mysql-server libapache2-mod-php php-cli php-mysql php-curl \
    php-mbstring php-zip php-gd php-xml php-soap php-ssh2 phpmyadmin \
    certbot python3-certbot-apache python3 python3-venv python3-pip \
    rsync curl git unzip gpg sudo cron ca-certificates
systemctl enable --now mysql
systemctl disable --now apache2 cron certbot.timer
bash "$src/install-full-backup.sh" --recovery-only
echo 'FRESH_TARGET_PREPARED; services remain offline.'
echo 'Transfer encrypted file, run verify-local, then fresh-apply with the verified digest.'
