#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "[DeltaPay] Please run as root."
    exit 1
fi

TMP_UPDATE="/tmp/delta-core-update.sh"
PROJECT_ROOT="/var/www/html/deltabotvps"
BASE_INFO="$PROJECT_ROOT/baseInfo.php"

curl -fsSL "https://raw.githubusercontent.com/deltashopsiavash/deltabotvps/main/update.sh" -o "$TMP_UPDATE"
chmod +x "$TMP_UPDATE"

# Run the project's normal interactive updater first. This preserves all of the
# legacy update behaviour (DB migration, VPSBot sync, cleanup, notifications).
bash "$TMP_UPDATE"

# If Update bot was selected and DeltaPay is configured, re-apply its Apache
# setup plus the idempotent Telegram bot integration after the fresh clone.
if [ -f "$BASE_INFO" ] && [ -f "$PROJECT_ROOT/deltapay/install.sh" ]; then
    PAYMENT_DOMAIN="$(php -r 'include $argv[1]; echo isset($paymentDomain) ? trim((string)$paymentDomain) : "";' "$BASE_INFO" 2>/dev/null || true)"
    if [ -n "$PAYMENT_DOMAIN" ]; then
        echo "[DeltaPay] Refreshing personal gateway integration after update..."
        chmod +x "$PROJECT_ROOT/deltapay/install.sh"
        bash "$PROJECT_ROOT/deltapay/install.sh" --domain "$PAYMENT_DOMAIN" --yes
    fi
fi

rm -f "$TMP_UPDATE"
echo "[DeltaPay] Update wrapper finished."
