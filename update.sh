#!/bin/bash

# Written By: delta

if [ "$(id -u)" -ne 0 ]; then
    echo -e "\033[33mPlease run as root\033[0m"
    exit
fi

wait

echo " "

PS3=" Please Select Action: "
options=("Update bot" "Update panel" "Backup" "Delete" "Donate" "Exit")
select opt in "${options[@]}"
do
	case $opt in
		"Update bot")
			echo " "
			read -p "Are you sure you want to update?[y/n]: " answer
			echo " "
			if [ "$answer" != "${answer#[Yy]}" ]; then

			# Preserve VPSBot runtime data (if present)
			VPSBOT_DIR="/opt/vpsbot"
			VPSBOT_BACKUP_DIR="/root/confdelta/vpsbot_update_backup"
			mkdir -p "$VPSBOT_BACKUP_DIR"
			if [ -d "$VPSBOT_DIR" ]; then
				[ -f "$VPSBOT_DIR/.env" ] && cp -f "$VPSBOT_DIR/.env" "$VPSBOT_BACKUP_DIR/.env"
				# Preserve DB if using default path
				[ -f "$VPSBOT_DIR/vpsbot.sqlite3" ] && cp -f "$VPSBOT_DIR/vpsbot.sqlite3" "$VPSBOT_BACKUP_DIR/vpsbot.sqlite3"
			fi
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
			sudo chown -R www-data:www-data /var/www/html/deltabotvps/
			sudo chmod -R 755 /var/www/html/deltabotvps/
			sleep 3
			mv /root/baseInfo.php /var/www/html/deltabotvps/

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
				[ -f "$VPSBOT_BACKUP_DIR/.env" ] && cp -f "$VPSBOT_BACKUP_DIR/.env" "$VPSBOT_DIR/.env"
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
			
                        MESSAGE="🤖 Delta robot has been successfully updated! "$'\n\n'"🔻token: <code>${bot_token}</code>"$'\n'"🔻admin: <code>${bot_value}</code> "$'\n'"🔻phpmyadmin: <code>https://domain.com/phpmyadmin</code>"$'\n'"🔹db name: <code>${db_namedelta}</code>"$'\n'"🔹db username: <code>${db_userdelta}</code>"$'\n'"🔹db password: <code>${db_passdelta}</code>"$'\n\n'"📢 @deltach "
			
   			curl -s -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE" -d parse_mode="html"
			
			curl -s -X POST "https://api.telegram.org/bot${bot_token2}/sendMessage" -d chat_id="${bot_value}" -d text="$MESSAGE" -d parse_mode="html"
			
			sleep 1
        
			url="${bot_url}install/install.php?updateBot"
			curl $url

   			url3="${bot_url}install/install.php?updateBot"
			curl $url3

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
