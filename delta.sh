#!/usr/bin/env bash
# DeltaBot VPS installer (stable) - asks Hetzner token and avoids silent crashes

set -Eeuo pipefail

RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'; CYN='\033[0;36m'; NC='\033[0m'
trap 'echo -e "\n${RED}[ERROR]${NC} خطا در خط ${YLW}$LINENO${NC}. برای دیدن جزئیات، همین دستور را با -x اجرا کن."; exit 1' ERR

need_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo -e "${YLW}Please run as root${NC} (مثال: sudo -i)"
    exit 1
  fi
}

apt_install() {
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "$@"
}

banner() {
  echo -e "${GRN}"
  echo "██████╗ ███████╗██╗ ████████╗ █████╗ "
  echo "██╔══██╗██╔════╝██║ ╚══██╔══╝██╔══██╗"
  echo "██║  ██║█████╗  ██║    ██║   ███████║"
  echo "██║  ██║██╔══╝  ██║    ██║   ██╔══██║"
  echo "██████╔╝███████╗███████╗██║   ██║  ██║"
  echo "╚═════╝ ╚══════╝╚══════╝╚═╝   ╚═╝  ╚═╝"
  echo -e "${NC}"
}

need_root
banner

echo -e "${CYN}Updating server packages...${NC}"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get upgrade -y
echo -e "${GRN}The server was successfully updated ...${NC}\n"

echo -e "${CYN}Installing required packages...${NC}"
apt_install git wget unzip curl ufw ca-certificates openssl lsb-release gnupg cron
apt_install apache2 mysql-server
apt_install php libapache2-mod-php php-mbstring php-zip php-gd php-json php-curl php-soap php-xml php-mysql

systemctl enable --now mysql apache2

# phpMyAdmin (non-interactive)
echo -e "${CYN}Installing phpMyAdmin...${NC}"
PMAPASS="$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 22)"
echo 'phpmyadmin phpmyadmin/dbconfig-install boolean true' | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password ${PMAPASS}" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password ${PMAPASS}" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password ${PMAPASS}" | debconf-set-selections
echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2' | debconf-set-selections
apt_install phpmyadmin

a2enconf phpmyadmin || true
systemctl restart apache2

# Firewall
ufw allow 80/tcp || true
ufw allow 443/tcp || true
ufw allow 'Apache Full' || true

# Bot code
BOT_DIR="/var/www/html/deltabot"
rm -rf "${BOT_DIR}" || true

# IMPORTANT: this should be YOUR repo
REPO_URL="https://github.com/deltashopsiavash/deltabotvps.git"
echo -e "${CYN}Cloning repository: ${REPO_URL}${NC}"
git clone "${REPO_URL}" "${BOT_DIR}"
chown -R www-data:www-data "${BOT_DIR}"
chmod -R 755 "${BOT_DIR}"

# Panel path
RANDOM_CODE="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)"
PANEL_DIR="/var/www/html/${RANDOM_CODE}"
mkdir -p "${PANEL_DIR}"

# Try to install panel from /install/deltapanel.zip (if you put it in the repo)
PANEL_ZIP_LOCAL="${BOT_DIR}/install/deltapanel.zip"
if [ -f "${PANEL_ZIP_LOCAL}" ]; then
  echo -e "${CYN}Installing panel from repo: install/deltapanel.zip${NC}"
  unzip -o "${PANEL_ZIP_LOCAL}" -d "${PANEL_DIR}" >/dev/null
  chown -R www-data:www-data "${PANEL_DIR}"
  chmod -R 755 "${PANEL_DIR}"
else
  echo -e "${YLW}[WARN] panel zip پیدا نشد: ${PANEL_ZIP_LOCAL}${NC}"
  echo -e "${YLW}[WARN] پنل نصب نشد. اگر پنل داری، فایل deltapanel.zip را داخل پوشه install/ در ریپو بگذار.${NC}"
fi

# MySQL root password helper
mkdir -p /root/confdelta
MYSQL_ROOT_PASS="$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 24)"
mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PASS}'; FLUSH PRIVILEGES;" || true

cat > /root/confdelta/dbrootdelta.txt <<'CONF'
$user = 'root';
$pass = '__MYSQL_ROOT_PASS__';
$path = '__PANEL_PATH__';
CONF
sed -i "s/__MYSQL_ROOT_PASS__/${MYSQL_ROOT_PASS}/" /root/confdelta/dbrootdelta.txt
sed -i "s/__PANEL_PATH__/${RANDOM_CODE}/" /root/confdelta/dbrootdelta.txt
chmod 600 /root/confdelta/dbrootdelta.txt

# Ask inputs
echo
read -r -p "Enter domain (مثال: domain.com یا sub.domain.com بدون https) : " DOMAIN_NAME
if [ -z "${DOMAIN_NAME}" ]; then echo -e "${RED}Domain is empty.${NC}"; exit 1; fi

read -r -p "Enter email for SSL (Let's Encrypt) : " SSL_EMAIL
if [ -z "${SSL_EMAIL}" ]; then echo -e "${RED}Email is empty.${NC}"; exit 1; fi

read -r -p "Bot Token : " BOT_TOKEN
read -r -p "Admin Numerical ID (از @userinfobot) : " ADMIN_ID
read -r -p "Hetzner Token : " HCLOUD_TOKEN

if [ -z "${BOT_TOKEN}" ] || [ -z "${ADMIN_ID}" ] || [ -z "${HCLOUD_TOKEN}" ]; then
  echo -e "${RED}Bot token / Admin ID / Hetzner token نباید خالی باشد.${NC}"
  exit 1
fi

# SSL
apt_install certbot python3-certbot-apache
if ! certbot --apache --non-interactive --agree-tos -m "${SSL_EMAIL}" -d "${DOMAIN_NAME}"; then
  echo -e "${YLW}[WARN] SSL گرفتن موفق نشد. بعداً دستی اجرا کن:${NC}"
  echo -e "${CYN}certbot --apache -d ${DOMAIN_NAME}${NC}"
fi

# Create DB
DBNAME="delta"
DBUSER="$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)"
DBPASS="$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)"

echo -e "${GRN}DB user default: ${YLW}${DBUSER}${NC}"
read -r -p "DB username (خالی = پیشفرض): " IN_DBUSER
DBUSER="${IN_DBUSER:-$DBUSER}"

echo -e "${GRN}DB pass default: ${YLW}${DBPASS}${NC}"
read -r -p "DB password (خالی = پیشفرض): " IN_DBPASS
DBPASS="${IN_DBPASS:-$DBPASS}"

mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE DATABASE IF NOT EXISTS ${DBNAME};"
mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE USER IF NOT EXISTS '${DBUSER}'@'%' IDENTIFIED WITH mysql_native_password BY '${DBPASS}';"
mysql -u root -p"${MYSQL_ROOT_PASS}" -e "GRANT ALL PRIVILEGES ON *.* TO '${DBUSER}'@'%'; FLUSH PRIVILEGES;"
mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE USER IF NOT EXISTS '${DBUSER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DBPASS}';"
mysql -u root -p"${MYSQL_ROOT_PASS}" -e "GRANT ALL PRIVILEGES ON *.* TO '${DBUSER}'@'localhost'; FLUSH PRIVILEGES;"

# Write baseInfo.php
cat > "${BOT_DIR}/baseInfo.php" <<'PHP'
<?php
error_reporting(0);
$botToken = '__BOT_TOKEN__';
$hcloudToken = '__HCLOUD_TOKEN__';
$dbUserName = '__DBUSER__';
$dbPassword = '__DBPASS__';
$dbName = '__DBNAME__';
$botUrl = '__BOTURL__';
$admin = __ADMIN_ID__;
?>
PHP
sed -i "s|__BOT_TOKEN__|${BOT_TOKEN}|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__HCLOUD_TOKEN__|${HCLOUD_TOKEN}|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__DBUSER__|${DBUSER}|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__DBPASS__|${DBPASS}|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__DBNAME__|${DBNAME}|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__BOTURL__|https://${DOMAIN_NAME}/deltabot/|g" "${BOT_DIR}/baseInfo.php"
sed -i "s|__ADMIN_ID__|${ADMIN_ID}|g" "${BOT_DIR}/baseInfo.php"

chown www-data:www-data "${BOT_DIR}/baseInfo.php"
chmod 640 "${BOT_DIR}/baseInfo.php"

# Webhook
curl -fsS -X POST "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook" \
  -d "url=https://${DOMAIN_NAME}/deltabot/bot.php" >/dev/null || true

# Init DB
if [ -f "${BOT_DIR}/createDB.php" ]; then
  curl -fsS "https://${DOMAIN_NAME}/deltabot/createDB.php" >/dev/null || true
fi

# Cron job (مثال)
(crontab -l 2>/dev/null; echo "* * * * * curl -fsS https://${DOMAIN_NAME}/deltabot/settings/messagedelta.php >/dev/null 2>&1") | sort -u | crontab -

# Notify
curl -s -X POST "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
  -d chat_id="${ADMIN_ID}" \
  -d text="✅ The delta bot has been successfully installed!" >/dev/null 2>&1 || true

echo
echo -e "${GRN}================= DONE =================${NC}"
echo -e "${GRN}phpMyAdmin:${NC} https://${DOMAIN_NAME}/phpmyadmin"
echo -e "${GRN}Bot URL:${NC}    https://${DOMAIN_NAME}/deltabot/"
echo -e "${GRN}Panel:${NC}      https://${DOMAIN_NAME}/${RANDOM_CODE}/login.php  (اگر پنل نصب شده باشد)"
echo -e "${GRN}========================================${NC}"
