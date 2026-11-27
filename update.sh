#!/bin/bash

# Written By: delta

if [ "$(id -u)" -ne 0 ]; then
    echo -e "\033[33mPlease run as root\033[0m"
    exit
fi

wait

echo " "

PS3=" Please Select Action: "
# Isolated Delta Android module update. Do not reinstall or modify payment/runtime files.
update_delta_app_only() (
    set -euo pipefail
    umask 077
    cd /var/www/html/deltabotvps
    local_dir="$PWD"
    [ -f baseInfo.php ] && [ -f admin_service_manager.php ] || exit 1
    command -v git >/dev/null
    command -v php >/dev/null
    command -v python3 >/dev/null
    backup=$(mktemp -d /root/delta-app-backup.XXXXXXXX)
    stage=$(mktemp -d /var/www/.delta-app-stage.XXXXXXXX)
    installed=()
    complete=0
    cleanup() {
        rc=$?
        if [ "$complete" -ne 1 ]; then
            for f in "${installed[@]}"; do
                if [ -e "$backup/$f" ]; then
                    cp -a "$backup/$f" "$stage/restore-$f" &&
                        mv -f "$stage/restore-$f" "$local_dir/$f" || echo "RESTORE REQUIRED: $backup/$f" >&2
                else
                    rm -f -- "$local_dir/$f"
                fi
            done
            echo "Delta app update did not complete. Backup: $backup" >&2
        fi
        rm -rf -- "$stage"
        return "$rc"
    }
    trap cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    files=(delta_app_core.php delta_app_admin.php delta_app_api.php admin_service_manager.php)
    for f in "${files[@]}"; do
        if [ -L "$f" ] || { [ -e "$f" ] && [ ! -f "$f" ]; }; then
            echo "Unexpected file type: $f" >&2
            exit 1
        fi
        if [ -f "$f" ]; then cp -a "$f" "$backup/$f"; fi
    done
    git -c safe.directory="$local_dir" fetch --no-tags https://github.com/deltashopsiavash/deltabotvps.git refs/heads/main
    rev=$(git -c safe.directory="$local_dir" rev-parse FETCH_HEAD)
    for f in delta_app_core.php delta_app_admin.php delta_app_api.php; do
        git -c safe.directory="$local_dir" show "$rev:$f" > "$stage/$f"
    done
    python3 - "$stage" <<'PY'
from pathlib import Path
import sys
p = Path("admin_service_manager.php")
s = p.read_bytes()
anchor = b"function deltaSvcHandleRequest(){"
hook = b"\n    require_once __DIR__ . '/delta_app_admin.php';\n    if (deltaAppAdminHandle()) return true;"
if s.count(anchor) != 1:
    raise SystemExit("Unexpected admin handler; no files changed")
if anchor + hook not in s:
    if b"deltaAppAdminHandle()" in s:
        raise SystemExit("Unexpected existing Delta app hook; no files changed")
    s = s.replace(anchor, anchor + hook, 1)
Path(sys.argv[1], p.name).write_bytes(s)
PY
    for f in "${files[@]}"; do
        php -l "$stage/$f"
        chown --reference=admin_service_manager.php "$stage/$f"
        if [ "$f" = admin_service_manager.php ]; then
            chmod --reference=admin_service_manager.php "$stage/$f"
        else
            chmod 644 "$stage/$f"
        fi
    done
    # Install the hook last. Existing account tables are never altered here.
    for f in "${files[@]}"; do
        installed+=("$f")
        mv -f "$stage/$f" "$f"
    done
    complete=1
    echo "DELTA APP UPDATE OK"
    echo "Source: $rev"
    echo "Backup: $backup"
    echo "Next: configure the mother webhook secret, then use /delta."
)

options=("Update bot" "Update panel" "Backup" "Delete" "Donate" "Exit" "Update Delta VPN app only")
select opt in "${options[@]}"
do
	case $opt in
		"Update Delta VPN app only")
			read -r -p "Update only the Delta Android module? [y/N]: " answer
			if [[ "$answer" == [Yy] ]]; then
				update_delta_app_only
				result=$?
				if [ "$result" -ne 0 ]; then
					echo "Delta app update failed. Check the output." >&2
					exit "$result"
				fi
			else
				echo "Cancelled."
			fi
			break ;;
		"Update bot")
			echo " "
			read -p "Are you sure you want to update?[y/n]: " answer
			echo " "
			if [ "$answer" != "${answer#[Yy]}" ]; then

			# Preserve VPSBot runtime data (if present)
			VPSBOT_DIR="/opt/vpsbot"
			VPSBOT_BACKUP_DIR="/root/confdelta/vpsbot_update_backup"
			install -d -m 0700 "$VPSBOT_BACKUP_DIR"
			if [ -d "$VPSBOT_DIR" ]; then
				[ -f "$VPSBOT_DIR/.env" ] && cp -f "$VPSBOT_DIR/.env" "$VPSBOT_BACKUP_DIR/.env"
				# Preserve DB if using default path
				[ -f "$VPSBOT_DIR/vpsbot.sqlite3" ] && cp -f "$VPSBOT_DIR/vpsbot.sqlite3" "$VPSBOT_BACKUP_DIR/vpsbot.sqlite3"
			fi

			# Preserve NPV runtime identity/data across the destructive repo refresh.
			NPV_BACKUP_DIR="/root/confdelta/npv_update_backup"
			rm -rf "$NPV_BACKUP_DIR"
			install -d -m 0700 "$NPV_BACKUP_DIR"
			NPV_SETTINGS="/var/www/html/deltabotvps/settings"
			if [ -f "$NPV_SETTINGS/npvs_creator_key.pem" ]; then
				cp -p "$NPV_SETTINGS/npvs_creator_key.pem" "$NPV_BACKUP_DIR/npvs_creator_key.pem"
			fi
			for npv_dir in npv_public_keys npv_devices; do
				if [ -d "$NPV_SETTINGS/$npv_dir" ]; then
					cp -a "$NPV_SETTINGS/$npv_dir" "$NPV_BACKUP_DIR/$npv_dir"
				fi
			done

			mv /var/www/html/deltabotvps/baseInfo.php /root/
			sudo apt-get install -y git
			sudo apt-get install -y wget
			sudo apt-get install -y unzip
			sudo apt install curl -y
			sudo apt-get install -y python3 python3-venv python3-pip
			echo -e "\n\e[92mUpdating ...\033[0m\n"
			sleep 4
			rm -r /var/www/html/deltabotvps/
			echo -e "\n\e[92mWait a few seconds ...\033[0m\n"
			sleep 3
			git clone https://github.com/deltashopsiavash/deltabotvps.git /var/www/html/deltabotvps

			# Restore NPV creator identity and per-service public keys before fixing ownership.
			mkdir -p /var/www/html/deltabotvps/settings
			if [ -f "$NPV_BACKUP_DIR/npvs_creator_key.pem" ]; then
				cp -p "$NPV_BACKUP_DIR/npvs_creator_key.pem" /var/www/html/deltabotvps/settings/npvs_creator_key.pem
				chmod 600 /var/www/html/deltabotvps/settings/npvs_creator_key.pem
			fi
			for npv_dir in npv_public_keys npv_devices; do
				if [ -d "$NPV_BACKUP_DIR/$npv_dir" ]; then
					rm -rf "/var/www/html/deltabotvps/settings/$npv_dir"
					cp -a "$NPV_BACKUP_DIR/$npv_dir" "/var/www/html/deltabotvps/settings/$npv_dir"
				fi
			done

			# Restore the one-minute income report and receipt approval worker on
			# existing installations; old HTTP entries could be missing or overlap.
			(crontab -l 2>/dev/null | grep -v 'deltabotvps/settings/rewardReport.php'; echo '* * * * * cd /var/www/html/deltabotvps/settings && /usr/bin/flock -n /tmp/deltabotvps-reward-report.lock /usr/bin/php /var/www/html/deltabotvps/settings/rewardReport.php >/dev/null 2>&1') | crontab -
			(crontab -l 2>/dev/null | grep -v 'deltabotvps/settings/warnusers.php'; echo '* * * * * cd /var/www/html/deltabotvps/settings && /usr/bin/flock -n /tmp/deltabotvps-warnusers.lock /usr/bin/php /var/www/html/deltabotvps/settings/warnusers.php >/dev/null 2>&1') | crontab -
			sudo chown -R www-data:www-data /var/www/html/deltabotvps/
			sudo chmod -R 755 /var/www/html/deltabotvps/
			sleep 3
			mv /root/baseInfo.php /var/www/html/deltabotvps/
			chown root:www-data /var/www/html/deltabotvps/baseInfo.php
			chmod 640 /var/www/html/deltabotvps/baseInfo.php

			# Sync embedded VPSBot to /opt/vpsbot so updates apply via update.sh
			if [ -d "/var/www/html/deltabotvps/vpsbot" ]; then
				mkdir -p "$VPSBOT_DIR"
				# Use rsync if available, otherwise fall back to cp
				if command -v rsync >/dev/null 2>&1; then
					rsync -a --delete \
						--exclude '.env' \
						--exclude 'venv' \
						--exclude '__pycache__' \
						--exclude '*.sqlite3' \
						/var/www/html/deltabotvps/vpsbot/ "$VPSBOT_DIR/"
				else
					rm -rf "$VPSBOT_DIR"/*
					cp -r /var/www/html/deltabotvps/vpsbot/* "$VPSBOT_DIR/"
				fi

				# Restore preserved runtime files
				[ -f "$VPSBOT_BACKUP_DIR/.env" ] && install -m 0600 "$VPSBOT_BACKUP_DIR/.env" "$VPSBOT_DIR/.env"
				[ -f "$VPSBOT_BACKUP_DIR/vpsbot.sqlite3" ] && cp -f "$VPSBOT_BACKUP_DIR/vpsbot.sqlite3" "$VPSBOT_DIR/vpsbot.sqlite3"

				# Ensure venv exists and deps are updated
				if [ ! -d "$VPSBOT_DIR/venv" ]; then
					python3 -m venv "$VPSBOT_DIR/venv"
				fi
				"$VPSBOT_DIR/venv/bin/pip" install --upgrade pip >/dev/null 2>&1
				if [ -f "$VPSBOT_DIR/requirements.txt" ]; then
					"$VPSBOT_DIR/venv/bin/pip" install -r "$VPSBOT_DIR/requirements.txt" >/dev/null 2>&1
				fi

				# Install/refresh service file if present
				if [ -f "$VPSBOT_DIR/vpsbridge.service" ]; then
					cp -f "$VPSBOT_DIR/vpsbridge.service" /etc/systemd/system/vpsbridge.service
					systemctl daemon-reload
					systemctl enable vpsbridge.service >/dev/null 2>&1
					systemctl restart vpsbridge.service >/dev/null 2>&1
				fi
			fi

			sleep 1

   		db_namedelta=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$dbName' | cut -d"'" -f2)
		  db_userdelta=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$dbUserName' | cut -d"'" -f2)
		  db_passdelta=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$dbPassword' | cut -d"'" -f2)
			bot_token=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$botToken' | cut -d"'" -f2)
			bot_token2=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$botToken' | cut -d'"' -f2)
			bot_url=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$botUrl' | cut -d'"' -d"'" -f2)
			
			filepath="/var/www/html/deltabotvps/baseInfo.php"
			
			bot_value=$(cat $filepath | grep '$admin =' | sed 's/.*= //' | sed 's/;//')
			
			MESSAGE="✅ Delta bot updated. Credentials are never included in notifications."
			
   			curl -s -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE" -d parse_mode="html"
			
			curl -s -X POST "https://api.telegram.org/bot${bot_token2}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE" -d parse_mode="html"
			
			sleep 1
			php /var/www/html/deltabotvps/install/install.php --update || exit 1


			# Existing mother install ONLY: refresh its private SMS Bridge if it
			# was previously installed. The bridge remains outside the bot tree.
			# NEVER install/enable it for a child or reseller (bid>0).
			MOTHER_SMS_SETUP="/var/www/html/deltabotvps/scripts/install-mother-sms-bridge.sh"
			if [ -f "/etc/delta-sms-bridge.php" ] && [ -f "$MOTHER_SMS_SETUP" ]; then
				echo "[Mother SMS Bridge] Checking previously installed bridge..."
				if ! bash "$MOTHER_SMS_SETUP" --non-interactive; then
					echo "[Mother SMS Bridge] Update skipped (private GitHub access or server check required)."
					echo "[Mother SMS Bridge] Existing bot/bridge runtime left unchanged."
				fi
			fi

   			echo -e "\n\e[92mUpdating ...\033[0m\n"
      
			sleep 2

   
			sudo rm -r /var/www/html/deltabotvps/webpanel
			sudo rm -r /var/www/html/deltabotvps/install
			rm /var/www/html/deltabotvps/createDB.php
			rm /var/www/html/deltabotvps/updateShareConfig.php
			rm /var/www/html/deltabotvps/README.md
			rm /var/www/html/deltabotvps/README-fa.md
			rm /var/www/html/deltabotvps/LICENSE
			rm /var/www/html/deltabotvps/update.sh
			rm /var/www/html/deltabotvps/delta.sh
  			rm /var/www/html/deltabotvps/tempCookie.txt
  			rm /var/www/html/deltabotvps/settings/messagedelta.json
			clear
			
			echo -e "\n\e[92mThe script was successfully updated! \033[0m\n"
			
			else
			  echo -e "\e[41mCancel the update.\033[0m\n"
			fi

			break ;;
		
		"Update panel")
			echo " "
			read -p "Are you sure you want to update?[y/n]: " answer
			echo " "
			if [ "$answer" != "${answer#[Yy]}" ]; then
   
			wait
   			cd /var/www/html/ && find . -mindepth 1 -maxdepth 1 ! -name deltabotvps -type d -exec rm -r {} \;

	 		touch /var/www/html/index.html
    			echo "<!DOCTYPE html><html><head><title>My Website</title></head><body><h1>Hello, world!</h1></body></html>" > /var/www/html/index.html
       
			
			    
			        
			RANDOM_CODE=$(LC_CTYPE=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)
			mkdir "/var/www/html/${RANDOM_CODE}"
			echo "Directory created: ${RANDOM_CODE}"
			echo "Folder created successfully!"
			
			 cd /var/www/html/
			 wget -O deltapanel.zip https://github.com/deltashopsiavash/deltabotvps/releases/download/10.3.1/deltapanel.zip
			
			 file_to_transfer="/var/www/html/deltapanel.zip"
			 destination_dir=$(find /var/www/html -type d -name "*${RANDOM_CODE}*" -print -quit)
			
			 if [ -z "$destination_dir" ]; then
			   echo "Error: Could not find directory containing 'wiz' in '/var/www/html'"
			   exit 1
			 fi
			
			 mv "$file_to_transfer" "$destination_dir/" && yes | unzip "$destination_dir/deltapanel.zip" -d "$destination_dir/" && rm "$destination_dir/deltapanel.zip" && sudo chmod -R 755 "$destination_dir/" && sudo chown -R www-data:www-data "$destination_dir/" 
			
			
			wait


			echo -e "\n\e[92mUpdating ...\033[0m\n"
			
			bot_token=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$botToken' | cut -d"'" -f2)
			bot_token2=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$botToken' | cut -d'"' -f2)
			
			filepath="/var/www/html/deltabotvps/baseInfo.php"
			
			bot_value=$(cat $filepath | grep '$admin =' | sed 's/.*= //' | sed 's/;//')
			
			MESSAGE="🕹 Delta panel has been successfully updated!"

			curl -s -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE"
			curl -s -X POST "https://api.telegram.org/bot${bot_token2}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE"
			
			sleep 1
			
			if [ $? -ne 0 ]; then
			echo -e "\n\e[41mError: The update failed!\033[0m\n"
			exit 1
			else

			clear

			echo -e ' '
			      echo -e "\e[100mdelta panel:\033[0m"
			      echo -e "\e[33maddres: \e[36mhttps://domain.com/${RANDOM_CODE}/login.php\033[0m"
			      echo " "
			      echo -e "\e[92mThe script was successfully updated!\033[0m\n"
			fi




			else
			  echo -e "\e[41mCancel the update.\033[0m\n"
			fi

			break ;;
		"Backup")
			echo " "
			wait

			(crontab -l ; echo "0 * * * * ./dbbackupdelta.sh") | sort - | uniq - | crontab -
			
			wget https://raw.githubusercontent.com/deltashopsiavash/deltabotvps/main/dbbackupdelta.sh | chmod +x dbbackupdelta.sh
			./dbbackupdelta.sh
   
			wget https://raw.githubusercontent.com/deltashopsiavash/deltabotvps/main/dbbackupdelta.sh | chmod +x dbbackupdelta.sh
			./dbbackupdelta.sh
			
			echo -e "\n\e[92m The backup settings have been successfully completed.\033[0m\n"

			break ;;
		"Delete")
			echo " "
			
			wait
			
			passs=$(cat /root/confdelta/dbrootdelta.txt | grep '$pass' | cut -d"'" -f2)
   			userrr=$(cat /root/confdelta/dbrootdelta.txt | grep '$user' | cut -d"'" -f2)
			pathsss=$(cat /root/confdelta/dbrootdelta.txt | grep '$path' | cut -d"'" -f2)
			pathsss=$(cat /root/confdelta/dbrootdelta.txt | grep '$path' | cut -d"'" -f2)
			passsword=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$dbPassword' | cut -d"'" -f2)
   			userrrname=$(cat /var/www/html/deltabotvps/baseInfo.php | grep '$dbUserName' | cut -d"'" -f2)
			
			mysql -u $userrr -p$passs -e "DROP DATABASE delta;" -e "DROP USER '$userrrname'@'localhost';" -e "DROP USER '$userrrname'@'%';"

			sudo rm -r /var/www/html/wizpanel${pathsss}
			sudo rm -r /var/www/html/deltabotvps
			
			clear
			
			sleep 1
			
			(crontab -l | grep -v "messagedelta.php") | crontab -
			(crontab -l | grep -v "rewardReport.php") | crontab -
			(crontab -l | grep -v "specialOffers.php") | crontab -
			(crontab -l | grep -v "warnusers.php") | crontab -
			(crontab -l | grep -v "backupnutif.php") | crontab -
			
			echo -e "\n\e[92m Removed successfully.\033[0m\n"
			break ;;
		"Donate")
			echo " "
			echo -e "\n\e[91mBank ( 1212 ): \e[36m1212\033[0m\n\e[91mTron(trx): \e[36mTY8j7of18gbMtneB8bbL7SZk5gcntQEemG\n\e[91mBitcoin: \e[36mbc1qcnkjnqvs7kyxvlfrns8t4ely7x85dhvz5gqge4\033[0m\n"
			exit 0
			break ;;
		"Exit")
			echo " "
			break
			;;
			*) echo "Invalid option!"
	esac
done
