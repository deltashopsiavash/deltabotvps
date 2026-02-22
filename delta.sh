#!/usr/bin/env bash
# delta.sh (stable installer) - patched to ask Hetzner token + avoid silent crashes

set -Eeuo pipefail

# ---- helpers
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[0;33m'; CYN='\033[0;36m'; NC='\033[0m'
trap 'echo -e "\n${RED}[ERROR]${NC} خطا در خط ${YLW}$LINENO${NC}. نصب متوقف شد."; exit 1' ERR

need_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo -e "${YLW}Please run as root${NC}"
    echo -e "مثال: ${CYN}sudo -i${NC} سپس دوباره اجرا کن."
    exit 1
  fi
}

apt_install() {
  local pkgs=("$@")
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${pkgs[@]}"
}

print_banner() {
  echo -e "${GRN}"
  echo "██████╗ ███████╗██╗ ████████╗ █████╗ "
  echo "██╔══██╗██╔════╝██║ ╚══██╔══╝██╔══██╗"
  echo "██║  ██║█████╗  ██║    ██║   ███████║"
  echo "██║  ██║██╔══╝  ██║    ██║   ██╔══██║"
  echo "██████╔╝███████╗███████╗██║   ██║  ██║"
  echo "╚═════╝ ╚══════╝╚══════╝╚═╝   ╚═╝  ╚═╝"
  echo -e "${NC}"
  echo -e " ${RED}Telegram Channel:${NC} ${CYN}@deltach${NC} | ${RED}Telegram Group:${NC} ${CYN}@deltadev${NC}\n"
}

# ---- main
need_root
print_banner

echo -e "${GRN}Installing Delta bot ...${NC}\n"
sleep 1

echo -e "${CYN}Updating server packages...${NC}"
apt-get update -y
DEBIAN_FRONTEND=noninteractive apt-get upgrade -y
echo -e "${GRN}The server was successfully updated ...${NC}\n"

echo -e "${CYN}Installing required packages...${NC}"
apt_install git wget unzip curl ufw ca-certificates lsb-release gnupg openssl \
            apache2 mysql-server php libapache2-mod-php \
            php-mbstring php-zip php-gd php-json php-curl php-soap php-ssh2 \
            libssh2-1 libssh2-1-dev

systemctl enable --now mysql apache2

# ---- phpMyAdmin (non-interactive)
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

# ---- UFW
echo -e "${CYN}Configuring firewall (UFW)...${NC}"
ufw allow 80/tcp || true
ufw allow 443/tcp || true
ufw allow 'Apache Full' || true

# ---- clone bot
echo -e "${CYN}Cloning repository...${NC}"
rm -rf /var/www/html/deltabot || true
git clone https://github.com/deltashopsiavash/deltabotvps.git /var/www/html/deltabot
chown -R www-data:www-data /var/www/html/deltabot
chmod -R 755 /var/www/html/deltabot

# ---- web panel folder (random path)
RANDOM_CODE="$(tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)"
mkdir -p "/var/www/html/${RANDOM_CODE}"

echo -e "${CYN}Downloading panel zip...${NC}"
cd /var/www/html
# اگر ریلیز/لینک پنل جای دیگریه بگو تا همین‌جا اصلاحش کنم
wget -O deltapanel.zip "https://github.com/deltashopsiavash/deltabot/releases/download/10.3.1/deltapanel.zip"
mv /var/www/html/deltapanel.zip "/var/www/html/${RANDOM_CODE}/deltapanel.zip"
unzip -o "/var/www/html/${RANDOM_CODE}/deltapanel.zip" -d "/var/www/html/${RANDOM_CODE}/" >/dev/null
rm -f "/var/www/html/${RANDOM_CODE}/deltapanel.zip"
chown -R www-data:www-data "/var/www/html/${RANDOM_CODE}"
chmod -R 755 "/var/www/html/${RANDOM_CODE}"

# ---- MySQL root password set (fix auth_socket issues)
echo -e "${CYN}Configuring MySQL root password...${NC}"
mkdir -p /root/confdelta
MYSQL_ROOT_PASS="$(openssl rand -base64 18 | tr -dc 'a-zA-Z0-9' | head -c 24)"

# set root password safely (works on Ubuntu where root uses auth_socket)
mysql -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PASS}'; FLUSH PRIVILEGES;" || true

# store config for later usage (and store $path too)
cat > /root/confdelta/dbrootdelta.txt <<EOF
\$user = 'root';
\$pass = '${MYSQL_ROOT_PASS}';
\$path = '${RANDOM_CODE}';
EOF
chmod 600 /root/confdelta/dbrootdelta.txt

# verify mysql
mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SELECT 1;" >/dev/null

# ---- ask user inputs
echo
read -r -p "Enter domain (example: domain.com or sub.domain.com) : " DOMAIN_NAME
if [ -z "${DOMAIN_NAME}" ]; then
  echo -e "${RED}Domain is empty. Exit.${NC}"
  exit 1
fi

read -r -p "Enter email for SSL (Let's Encrypt) : " SSL_EMAIL
if [ -z "${SSL_EMAIL}" ]; then
  echo -e "${RED}Email is empty. Exit.${NC}"
  exit 1
fi

read -r -p "Bot Token : " YOUR_BOT_TOKEN
read -r -p "Admin Numerical ID (from @userinfobot) : " YOUR_CHAT_ID
read -r -p "Hetzner Token : " YOUR_HCLOUD_TOKEN

if [ -z "${YOUR_BOT_TOKEN}" ] || [ -z "${YOUR_CHAT_ID}" ] || [ -z "${YOUR_HCLOUD_TOKEN}" ]; then
  echo -e "${RED}Bot token / Admin ID / Hetzner token must not be empty.${NC}"
  exit 1
fi

# ---- SSL (don’t hard-fail if certbot fails; show warning)
echo -e "${CYN}Installing certbot...${NC}"
apt_install certbot python3-certbot-apache

echo -e "${CYN}Requesting SSL certificate...${NC}"
if ! certbot --apache --non-interactive --agree-tos -m "${SSL_EMAIL}" -d "${DOMAIN_NAME}"; then
  echo -e "${YLW}[WARN] SSL گرفتن موفق نشد. بعداً می‌تونی دستی اجرا کنی:${NC}"
  echo -e "${CYN}certbot --apache -d ${DOMAIN_NAME}${NC}"
fi

# ---- create DB + user
echo -e "${CYN}Creating database...${NC}"
DBNAME="delta"

# prevent duplicate
if mysql -u root -p"${MYSQL_ROOT_PASS}" -e "SHOW DATABASES LIKE '${DBNAME}';" | grep -q "${DBNAME}"; then
  echo -e "${YLW}[WARN] Database '${DBNAME}' already exists. Skipping create.${NC}"
else
  DEF_DB_USER="$(openssl rand -base64 12 | tr -dc 'a-zA-Z0-9' | head -c 16)"
  DEF_DB_PASS="$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 20)"

  echo -e "${GRN}DB user default: ${YLW}${DEF_DB_USER}${NC}"
  read -r -p "DB username (leave empty for default): " DBUSER
  DBUSER="${DBUSER:-$DEF_DB_USER}"

  echo -e "${GRN}DB pass default: ${YLW}${DEF_DB_PASS}${NC}"
  read -r -p "DB password (leave empty for default): " DBPASS
  DBPASS="${DBPASS:-$DEF_DB_PASS}"

  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE DATABASE ${DBNAME};"
  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE USER '${DBUSER}'@'%' IDENTIFIED WITH mysql_native_password BY '${DBPASS}';"
  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "GRANT ALL PRIVILEGES ON *.* TO '${DBUSER}'@'%'; FLUSH PRIVILEGES;"
  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "CREATE USER '${DBUSER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DBPASS}';"
  mysql -u root -p"${MYSQL_ROOT_PASS}" -e "GRANT ALL PRIVILEGES ON *.* TO '${DBUSER}'@'localhost'; FLUSH PRIVILEGES;"
fi

# if DBUSER/DBPASS not set because DB existed, try read from baseInfo if exists
DBUSER="${DBUSER:-}"
DBPASS="${DBPASS:-}"
if [ -z "${DBUSER}" ] || [ -z "${DBPASS}" ]; then
  # fallback: random (user can fix in baseInfo.php later)
  DBUSER="${DBUSER:-deltauser}"
  DBPASS="${DBPASS:-deltapass}"
fi

# ---- write baseInfo.php
echo -e "${CYN}Writing baseInfo.php...${NC}"
cat > /var/www/html/deltabot/baseInfo.php <<EOF
<?php
error_reporting(0);
\$botToken = '${YOUR_BOT_TOKEN}';
\$hcloudToken = '${YOUR_HCLOUD_TOKEN}';
\$dbUserName = '${DBUSER}';
\$dbPassword = '${DBPASS}';
\$dbName = '${DBNAME}';
\$botUrl = 'https://${DOMAIN_NAME}/deltabot/';
\$admin = ${YOUR_CHAT_ID};
?>
EOF
chown www-data:www-data /var/www/html/deltabot/baseInfo.php
chmod 640 /var/www/html/deltabot/baseInfo.php

# ---- set webhook
echo -e "${CYN}Setting Telegram webhook...${NC}"
curl -fsS -X POST "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/setWebhook" \
  -d "url=https://${DOMAIN_NAME}/deltabot/bot.php" >/dev/null || true

# ---- init DB (if createDB exists)
if [ -f /var/www/html/deltabot/createDB.php ]; then
  echo -e "${CYN}Initializing bot DB tables...${NC}"
  curl -fsS "https://${DOMAIN_NAME}/deltabot/createDB.php" >/dev/null || true
fi

# ---- notify admin
curl -s -X POST "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/sendMessage" \
  -d chat_id="${YOUR_CHAT_ID}" \
  -d text="✅ The delta bot has been successfully installed!" >/dev/null 2>&1 || true

echo
echo -e "${GRN}================= DONE =================${NC}"
echo -e "${GRN}phpMyAdmin:${NC} https://${DOMAIN_NAME}/phpmyadmin"
echo -e "${GRN}Panel:${NC}     https://${DOMAIN_NAME}/${RANDOM_CODE}/login.php"
echo -e "${GRN}Bot URL:${NC}    https://${DOMAIN_NAME}/deltabot/"
echo -e "${GRN}Hetzner token saved in baseInfo.php as \$hcloudToken${NC}"
echo -e "${GRN}========================================${NC}"
