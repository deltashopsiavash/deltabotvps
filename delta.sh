#!/bin/bash
# Written By: delta (patched to ask for Hetzner token)

set -e

if [ "$(id -u)" -ne 0 ]; then
  echo -e "\033[33mPlease run as root\033[0m"
  exit 1
fi

wait

echo -e "\e[32m"
echo "██████╗ ███████╗██╗ ████████╗ █████╗ "
echo "██╔══██╗██╔════╝██║ ╚══██╔══╝██╔══██╗"
echo "██║  ██║█████╗  ██║    ██║   ███████║"
echo "██║  ██║██╔══╝  ██║    ██║   ██╔══██║"
echo "██████╔╝███████╗███████╗██║   ██║  ██║"
echo "╚═════╝ ╚══════╝╚══════╝╚═╝   ╚═╝  ╚═╝"
echo -e "\033[0m"
echo -e " \e[31mTelegram Channel: \e[34m@deltach\033[0m | \e[31mTelegram Group: \e[34m@deltadev\033[0m\n"

echo -e "\e[32mInstalling Delta script ...\033[0m\n"
sleep 5

sudo apt update && apt upgrade -y
echo -e "\e[92mThe server was successfully updated ...\033[0m\n"

PKG=( lamp-server^ libapache2-mod-php mysql-server apache2 php-mbstring php-zip php-gd php-json php-curl )
for i in "${PKG[@]}"; do
  dpkg -s "$i" &>/dev/null
  if [ $? -eq 0 ]; then
    echo "$i is already installed"
  else
    apt install "$i" -y
    if [ $? -ne 0 ]; then
      echo "Error installing $i"
      exit 1
    fi
  fi
done

echo -e "\n\e[92mPackages Installed Continuing ...\033[0m\n"

randomdbpasstxt69=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-20)

echo 'phpmyadmin phpmyadmin/dbconfig-install boolean true' | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password $randomdbpasstxt69" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password $randomdbpasstxt69" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password $randomdbpasstxt69" | debconf-set-selections
echo 'phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2' | debconf-set-selections

sudo apt-get install phpmyadmin -y
sudo ln -s /etc/phpmyadmin/apache.conf /etc/apache2/conf-available/phpmyadmin.conf
sudo a2enconf phpmyadmin.conf
sudo systemctl restart apache2

sudo apt-get install -y php-soap
sudo apt-get install -y libapache2-mod-php

sudo systemctl enable mysql.service
sudo systemctl start mysql.service
sudo systemctl enable apache2
sudo systemctl start apache2

echo -e "\n\e[92m Setting Up UFW...\033[0m\n"
ufw allow 'Apache'
sudo systemctl restart apache2

echo -e "\n\e[92mInstalling ...\033[0m\n"
sleep 1

sudo apt-get install -y git wget unzip curl php-ssh2
sudo apt-get install -y libssh2-1-dev libssh2-1
sudo systemctl restart apache2.service

git clone https://github.com/deltashopsiavash/deltabot.git /var/www/html/deltabot
sudo chown -R www-data:www-data /var/www/html/deltabot/
sudo chmod -R 755 /var/www/html/deltabot/

echo -e "\n\033[33mDelta config and script have been installed successfully\033[0m"
wait

RANDOM_CODE=$(LC_CTYPE=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)
mkdir -p "/var/www/html/${RANDOM_CODE}"
echo "Directory created: ${RANDOM_CODE}"
echo "Folder created successfully!"

cd /var/www/html/
wget -O deltapanel.zip https://github.com/deltashopsiavash/deltabot/releases/download/10.3.1/deltapanel.zip

file_to_transfer="/var/www/html/deltapanel.zip"
destination_dir=$(find /var/www/html -type d -name "*${RANDOM_CODE}*" -print -quit)

if [ -z "$destination_dir" ]; then
  echo "Error: Could not find directory containing RANDOM_CODE in '/var/www/html'"
  exit 1
fi

mv "$file_to_transfer" "$destination_dir/"
yes | unzip "$destination_dir/deltapanel.zip" -d "$destination_dir/"
rm "$destination_dir/deltapanel.zip"
sudo chmod -R 755 "$destination_dir/"
sudo chown -R www-data:www-data "$destination_dir/"

wait

# MySQL root helper file
if [ ! -d "/root/confdelta" ]; then
  sudo mkdir /root/confdelta
  sleep 1
  touch /root/confdelta/dbrootdelta.txt
  sudo chmod -R 777 /root/confdelta/dbrootdelta.txt
  sleep 1

  randomdbpasstxt=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-30)
  ASAS="$"
  echo "${ASAS}user = 'root';" >> /root/confdelta/dbrootdelta.txt
  echo "${ASAS}pass = '${randomdbpasstxt}';" >> /root/confdelta/dbrootdelta.txt

  sleep 1
  passs=$(cat /root/confdelta/dbrootdelta.txt | grep '$pass' | cut -d"'" -f2)
  userrr=$(cat /root/confdelta/dbrootdelta.txt | grep '$user' | cut -d"'" -f2)

  sudo mysql -u "$userrr" -p"$passs" -e "alter user '$userrr'@'localhost' identified with mysql_native_password by '$passs';FLUSH PRIVILEGES;"
  echo "SELECT 1" | mysql -u"$userrr" -p"$passs" 2>/dev/null || true
else
  echo "Folder already exists."
fi

clear
echo " "
echo -e "\e[32m"
echo "██████╗ ███████╗██╗ ████████╗ █████╗ "
echo "██╔══██╗██╔════╝██║ ╚══██╔══╝██╔══██╗"
echo "██║  ██║█████╗  ██║    ██║   ███████║"
echo "██║  ██║██╔══╝  ██║    ██║   ██╔══██║"
echo "██████╔╝███████╗███████╗██║   ██║  ██║"
echo "╚═════╝ ╚══════╝╚══════╝╚═╝   ╚═╝  ╚═╝"
echo -e "\033[0m"

read -p "Enter the domain: " domainname
if [ "$domainname" = "" ]; then
  echo -e "\n\033[91mPlease wait ...\033[0m\n"
  sleep 3
  echo -e "\e[36mNothing was registered for the domain.\033[0m\n"
  echo -e "\n\033[0m Good Luck Baby\n"
  exit 1
fi

DOMAIN_NAME="$domainname"

# cron jobs
PATHS=$(cat /root/confdelta/dbrootdelta.txt | grep '$path' | cut -d"'" -f2)
(crontab -l 2>/dev/null ; echo "* * * * * curl https://${DOMAIN_NAME}/deltabot/settings/messagedelta.php >/dev/null 2>&1") | sort - | uniq - | crontab -
(crontab -l 2>/dev/null ; echo "* * * * * curl https://${DOMAIN_NAME}/deltabot/settings/rewardReport.php >/dev/null 2>&1") | sort - | uniq - | crontab -
(crontab -l 2>/dev/null ; echo "* * * * * curl https://${DOMAIN_NAME}/deltabot/settings/warnusers.php >/dev/null 2>&1") | sort - | uniq - | crontab -
(crontab -l 2>/dev/null ; echo "* * * * * curl https://${DOMAIN_NAME}/deltabot/settings/gift2all.php >/dev/null 2>&1") | sort - | uniq - | crontab -
(crontab -l 2>/dev/null ; echo "*/3 * * * * curl https://${DOMAIN_NAME}/deltabot/settings/tronChecker.php >/dev/null 2>&1") | sort - | uniq - | crontab -
(crontab -l 2>/dev/null ; echo "* * * * * curl https://${DOMAIN_NAME}/${PATHS}/backupnutif.php >/dev/null 2>&1") | sort - | uniq - | crontab -

echo -e "\n\e[92m Setting Up Cron...\033[0m\n"

# Allow HTTP/HTTPS
echo -e "\n\033[1;7;31mAllowing HTTP and HTTPS traffic...\033[0m\n"
sudo ufw allow 80
sudo ufw allow 443

# Let's Encrypt
echo -e "\n\033[1;7;32mInstalling Let's Encrypt...\033[0m\n"
sudo apt install letsencrypt -y

echo -e "\n\033[1;7;33mEnabling automatic certificate renewal...\033[0m\n"
sudo systemctl enable certbot.timer || true

echo -e "\n\033[1;7;34mObtaining SSL certificate using standalone mode...\033[0m\n"
sudo certbot certonly --standalone --agree-tos --preferred-challenges http -d "$DOMAIN_NAME" || true

echo -e "\n\033[1;7;35mInstalling Certbot Apache plugin...\033[0m\n"
sudo apt install python3-certbot-apache -y

echo -e "\n\033[1;7;36mObtaining SSL certificate using Apache plugin...\033[0m\n"
sudo certbot --apache --agree-tos --preferred-challenges http -d "$DOMAIN_NAME" || true

echo -e "\e[32m======================================"
echo -e "SSL certificate obtained successfully!"
echo -e "======================================\033[0m"

wait
echo " "

ROOT_PASSWORD=$(cat /root/confdelta/dbrootdelta.txt | grep '$pass' | cut -d"'" -f2)
ROOT_USER="root"

echo "SELECT 1" | mysql -u"$ROOT_USER" -p"$ROOT_PASSWORD" 2>/dev/null
if [ $? -ne 0 ]; then
  echo -e "\n\e[36mThe password is not correct or empty.\033[0m\n"
  exit 1
fi

wait

randomdbpass=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-22)
randomdbdb=$(openssl rand -base64 10 | tr -dc 'a-zA-Z0-9' | cut -c1-22)

if [[ $(mysql -u root -p"$ROOT_PASSWORD" -e "SHOW DATABASES LIKE 'delta'" 2>/dev/null) ]]; then
  clear
  echo -e "\n\e[91mYou have already created the database\033[0m\n"
  exit 1
fi

dbname=delta

clear
echo -e "\n\e[32mPlease enter the database username!\033[0m"
printf "[+] Default user name is \e[91m${randomdbdb}\e[0m ( let it blank to use this user name ): "
read dbuser
if [ "$dbuser" = "" ]; then dbuser=$randomdbdb; fi

echo -e "\n\e[32mPlease enter the database password!\033[0m"
printf "[+] Default password is \e[91m${randomdbpass}\e[0m ( let it blank to use this password ): "
read dbpass
if [ "$dbpass" = "" ]; then dbpass=$randomdbpass; fi

mysql -u root -p"$ROOT_PASSWORD" \
  -e "CREATE DATABASE $dbname;" \
  -e "CREATE USER '$dbuser'@'%' IDENTIFIED WITH mysql_native_password BY '$dbpass';" \
  -e "GRANT ALL PRIVILEGES ON *.* TO '$dbuser'@'%'; FLUSH PRIVILEGES;" \
  -e "CREATE USER '$dbuser'@'localhost' IDENTIFIED WITH mysql_native_password BY '$dbpass';" \
  -e "GRANT ALL PRIVILEGES ON *.* TO '$dbuser'@'localhost'; FLUSH PRIVILEGES;"

echo -e "\n\e[95mDatabase Created.\033[0m"
wait

# =========================
# BOT CONFIG (PATCHED HERE)
# =========================
printf "\n\e[33m[+] \e[36mBot Token: \033[0m"
read YOUR_BOT_TOKEN

printf "\e[33m[+] \e[36mChat id: \033[0m"
read YOUR_CHAT_ID

printf "\e[33m[+] \e[36mDomain: \033[0m"
read YOUR_DOMAIN

printf "\e[33m[+] \e[36mHetzner Token: \033[0m"
read YOUR_HCLOUD_TOKEN

echo " "

if [ "$YOUR_BOT_TOKEN" = "" ] || [ "$YOUR_DOMAIN" = "" ] || [ "$YOUR_CHAT_ID" = "" ] || [ "$YOUR_HCLOUD_TOKEN" = "" ]; then
  exit 1
fi

ASAS="$"
wait
sleep 1

file_path="/var/www/html/deltabot/baseInfo.php"
if [ -f "$file_path" ]; then
  rm "$file_path"
  echo -e "File deleted successfully."
else
  echo -e "File not found."
fi

sleep 2

# write baseInfo.php
echo -e "" > /var/www/html/deltabot/baseInfo.php
echo -e "error_reporting(0);" >> /var/www/html/deltabot/baseInfo.php
echo -e "${ASAS}botToken = '${YOUR_BOT_TOKEN}';" >> /var/www/html/deltabot/baseInfo.php

# ✅ NEW: Hetzner token
echo -e "${ASAS}hcloudToken = '${YOUR_HCLOUD_TOKEN}';" >> /var/www/html/deltabot/baseInfo.php

echo -e "${ASAS}dbUserName = '${dbuser}';" >> /var/www/html/deltabot/baseInfo.php
echo -e "${ASAS}dbPassword = '${dbpass}';" >> /var/www/html/deltabot/baseInfo.php
echo -e "${ASAS}dbName = '${dbname}';" >> /var/www/html/deltabot/baseInfo.php
echo -e "${ASAS}botUrl = 'https://${YOUR_DOMAIN}/deltabot/';" >> /var/www/html/deltabot/baseInfo.php
echo -e "${ASAS}admin = ${YOUR_CHAT_ID};" >> /var/www/html/deltabot/baseInfo.php
echo -e "?>" >> /var/www/html/deltabot/baseInfo.php

sleep 1

curl -F "url=https://${YOUR_DOMAIN}/deltabot/bot.php" "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/setWebhook" || true

MESSAGE="✅ The delta bot has been successfully installed! @deltach"
curl -s -X POST "https://api.telegram.org/bot${YOUR_BOT_TOKEN}/sendMessage" -d chat_id="${YOUR_CHAT_ID}" -d text="$MESSAGE" >/dev/null 2>&1 || true

sleep 1
url="https://${YOUR_DOMAIN}/deltabot/createDB.php"
curl "$url" || true

sleep 1

# cleanup
sudo rm -rf /var/www/html/deltabot/webpanel || true
sudo rm -rf /var/www/html/deltabot/install || true
sudo rm -f /var/www/html/deltabot/createDB.php || true
rm -f /var/www/html/deltabot/updateShareConfig.php || true
rm -f /var/www/html/deltabot/README.md || true
rm -f /var/www/html/deltabot/README-fa.md || true
rm -f /var/www/html/deltabot/LICENSE || true
rm -f /var/www/html/deltabot/update.sh || true
rm -f /var/www/html/deltabot/delta.sh || true
rm -f /var/www/html/deltabot/tempCookie.txt || true
rm -f /var/www/html/deltabot/settings/messagedelta.json || true

clear
echo " "
echo -e "\e[100mDatabase information:\033[0m"
echo -e "\e[33maddres: \e[36mhttps://${YOUR_DOMAIN}/phpmyadmin\033[0m"
echo -e "\e[33mDatabase name: \e[36m${dbname}\033[0m"
echo -e "\e[33mDatabase username: \e[36m${dbuser}\033[0m"
echo -e "\e[33mDatabase password: \e[36m${dbpass}\033[0m"

echo " "
echo -e "\e[100mdelta panel:\033[0m"
echo -e "\e[33maddres: \e[36mhttps://${YOUR_DOMAIN}/${RANDOM_CODE}/login.php\033[0m"
echo " "
echo -e "Good Luck Baby! \e[94mThis project is for free. If you like it, be sure to donate me :) , so let's go \033[0m\n"