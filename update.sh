#!/bin/bash
set -euo pipefail

# Written By: delta (patched for deltabotvps)

if [ "$(id -u)" -ne 0 ]; then
  echo -e "\033[33mPlease run as root\033[0m"
  exit 1
fi

REPO_URL="https://github.com/deltashopsiavash/deltabotvpsvps.git"
WEBROOT="/var/www/html"
BOT_DIR_NEW="${WEBROOT}/deltabotvps"
BOT_DIR_OLD="${WEBROOT}/deltabot"

# If old folder exists but new doesn't, migrate
if [ -d "$BOT_DIR_OLD" ] && [ ! -d "$BOT_DIR_NEW" ]; then
  mv "$BOT_DIR_OLD" "$BOT_DIR_NEW"
fi

BOT_DIR="$BOT_DIR_NEW"

if [ ! -d "$BOT_DIR" ]; then
  echo -e "\033[31mBot directory not found at ${BOT_DIR}. Install first.\033[0m"
  exit 1
fi

PS3=" Please Select Action: "
options=("Update bot" "Update panel" "Backup" "Delete" "Exit")
select opt in "${options[@]}"; do
  case $opt in
    "Update bot")
      echo " "
      read -p "Are you sure you want to update?[y/n]: " answer
      echo " "
      if [[ "$answer" =~ ^[Yy]$ ]]; then
        apt-get update -y
        apt-get install -y git wget unzip curl python3 python3-venv python3-pip

        echo -e "\n\e[92mUpdating deltabotvps ...\033[0m\n"

        # Backup important files
        mkdir -p /root/deltabotvps_backup
        if [ -f "${BOT_DIR}/baseInfo.php" ]; then
          cp -f "${BOT_DIR}/baseInfo.php" /root/deltabotvps_backup/baseInfo.php
        fi
        if [ -f "${BOT_DIR}/vpsbot_backend/.env" ]; then
          cp -f "${BOT_DIR}/vpsbot_backend/.env" /root/deltabotvps_backup/vpsbot.env
        fi
        if [ -f "${BOT_DIR}/vpsbot_backend/vpsbot.sqlite3" ]; then
          cp -f "${BOT_DIR}/vpsbot_backend/vpsbot.sqlite3" /root/deltabotvps_backup/vpsbot.sqlite3
        fi

        # Fresh clone
        rm -rf "${BOT_DIR}"
        git clone "$REPO_URL" "$BOT_DIR"

        # Restore backups
        if [ -f /root/deltabotvps_backup/baseInfo.php ]; then
          cp -f /root/deltabotvps_backup/baseInfo.php "${BOT_DIR}/baseInfo.php"
        fi

        # If user upgraded from old folder name, fix botUrl path inside baseInfo.php
        if [ -f "${BOT_DIR}/baseInfo.php" ]; then
          sed -i 's#/deltabot/#/deltabotvps/#g; s#/deltabot\b#/deltabotvps#g' "${BOT_DIR}/baseInfo.php" || true
        fi
        mkdir -p "${BOT_DIR}/vpsbot_backend"
        if [ -f /root/deltabotvps_backup/vpsbot.env ]; then
          cp -f /root/deltabotvps_backup/vpsbot.env "${BOT_DIR}/vpsbot_backend/.env"
        fi
        if [ -f /root/deltabotvps_backup/vpsbot.sqlite3 ]; then
          cp -f /root/deltabotvps_backup/vpsbot.sqlite3 "${BOT_DIR}/vpsbot_backend/vpsbot.sqlite3"
        fi

        chown -R www-data:www-data "$BOT_DIR"
        chmod -R 755 "$BOT_DIR"

        # Read values from baseInfo.php (best-effort)
        BASEINFO="${BOT_DIR}/baseInfo.php"
        bot_token=$(grep -E "\$botToken" "$BASEINFO" | head -n1 | cut -d"'" -f2 || true)
        bot_token2=$(grep -E "\$botToken" "$BASEINFO" | head -n1 | cut -d'"' -f2 || true)
        bot_url=$(grep -E "\$botUrl" "$BASEINFO" | head -n1 | sed -E "s/.*=\s*['\"]([^'\"]+)['\"].*/\1/" || true)
        admin_id=$(grep -E "\$admin\s*=" "$BASEINFO" | sed 's/.*= //' | sed 's/;//' | tr -d '"'"'"' ' || true)

        # Run DB update hooks if available
        if [ -n "${bot_url:-}" ]; then
          curl -s "${bot_url}install/install.php?updateBot" >/dev/null || true
        fi

        # Ensure bridge service exists & is running
        SERVICE_NAME="deltabotvps-vps-bridge"
        SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
        BRIDGE_DIR="${BOT_DIR}/vpsbot_backend"

        if [ -d "$BRIDGE_DIR" ]; then
          # (Re)create venv & deps (idempotent)
          python3 -m venv "${BRIDGE_DIR}/.venv" || true
          "${BRIDGE_DIR}/.venv/bin/pip" install -U pip >/dev/null
          if [ -f "${BRIDGE_DIR}/requirements.txt" ]; then
            "${BRIDGE_DIR}/.venv/bin/pip" install -r "${BRIDGE_DIR}/requirements.txt" >/dev/null
          fi

          # Create service if missing
          if [ ! -f "$SERVICE_FILE" ]; then
            cat > "$SERVICE_FILE" <<SVC
[Unit]
Description=DeltaBotVPS Bridge (VPSBot backend)
After=network.target

[Service]
Type=simple
WorkingDirectory=${BRIDGE_DIR}
EnvironmentFile=${BRIDGE_DIR}/.env
ExecStart=${BRIDGE_DIR}/.venv/bin/python ${BRIDGE_DIR}/bridge_server.py
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
SVC
          fi

          systemctl daemon-reload
          systemctl enable "$SERVICE_NAME" >/dev/null || true
          systemctl restart "$SERVICE_NAME" || true
        fi

        # Notify admin (best-effort)
        if [ -n "${bot_token:-}" ] && [ -n "${admin_id:-}" ]; then
          MESSAGE="🤖 DeltaBotVPS با موفقیت آپدیت شد.\n\n✅ بخش سرور ابری فعال شد.\n\n📌 مسیر نصب: ${BOT_DIR}"
          curl -s -X POST "https://api.telegram.org/bot${bot_token}/sendMessage" -d chat_id="${admin_id}" -d text="$MESSAGE" >/dev/null || true
        fi
        if [ -n "${bot_token2:-}" ] && [ -n "${admin_id:-}" ]; then
          MESSAGE="🤖 DeltaBotVPS با موفقیت آپدیت شد.\n\n✅ بخش سرور ابری فعال شد.\n\n📌 مسیر نصب: ${BOT_DIR}"
          curl -s -X POST "https://api.telegram.org/bot${bot_token2}/sendMessage" -d chat_id="${admin_id}" -d text="$MESSAGE" >/dev/null || true
        fi

        clear
        echo -e "\n\e[92mThe script was successfully updated!\033[0m\n"
        echo -e "\e[36mTip:\033[0m اگر منوی سرور ابری نیومد، کش تلگرام رو با /start تازه کن."

      else
        echo -e "\e[41mCancel the update.\033[0m\n"
      fi
      break
      ;;

    "Update panel")
      echo " "
      read -p "Are you sure you want to update panel?[y/n]: " answer
      echo " "
      if [[ "$answer" =~ ^[Yy]$ ]]; then
        apt-get update -y
        apt-get install -y wget unzip curl

        # keep bot folder, remove other folders
        cd "$WEBROOT" && find . -mindepth 1 -maxdepth 1 ! -name "$(basename "$BOT_DIR")" -type d -exec rm -r {} \;

        touch "$WEBROOT/index.html"
        echo "<!DOCTYPE html><html><head><title>My Website</title></head><body><h1>Hello, world!</h1></body></html>" > "$WEBROOT/index.html"

        RANDOM_CODE=$(LC_CTYPE=C tr -dc 'a-zA-Z0-9' < /dev/urandom | head -c 40)
        mkdir "$WEBROOT/${RANDOM_CODE}"

        cd "$WEBROOT"
        wget -O deltapanel.zip https://github.com/deltashopsiavash/deltabotvpsvps/releases/download/10.3.1/deltapanel.zip
        yes | unzip "$WEBROOT/deltapanel.zip" -d "$WEBROOT/${RANDOM_CODE}/" >/dev/null
        rm -f "$WEBROOT/deltapanel.zip"
        chmod -R 755 "$WEBROOT/${RANDOM_CODE}/"
        chown -R www-data:www-data "$WEBROOT/${RANDOM_CODE}/"

        clear
        echo -e "\n\e[92mDelta panel updated!\033[0m\n"
        echo -e "\e[33mPanel address: \e[36mhttps://YOUR_DOMAIN/${RANDOM_CODE}/login.php\033[0m"
      else
        echo -e "\e[41mCancel the update.\033[0m\n"
      fi
      break
      ;;

    "Backup")
      echo " "
      echo -e "\n\e[92mBackup: use your existing backup script (dbbackupdelta.sh)\033[0m\n"
      break
      ;;

    "Delete")
      echo -e "\n\e[31mDelete is disabled in this patched updater to avoid accidental removal.\033[0m\n"
      echo -e "اگر حذف می‌خوای بگو تا نسخه امنش رو آماده کنم."
      break
      ;;

    "Exit")
      echo " "
      break
      ;;

    *)
      echo "Invalid option!"
      ;;
  esac
done
