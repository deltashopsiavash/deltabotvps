#!/bin/bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "[DeltaPay] Please run as root."
    exit 1
fi

DOMAIN=""
EMAIL=""
YES=0

while [ $# -gt 0 ]; do
    case "$1" in
        --domain)
            DOMAIN="${2:-}"
            shift 2
            ;;
        --email)
            EMAIL="${2:-}"
            shift 2
            ;;
        --yes|-y)
            YES=1
            shift
            ;;
        *)
            echo "Unknown argument: $1"
            echo "Usage: bash deltapay/install.sh --domain pay.example.com [--email you@example.com] [--yes]"
            exit 1
            ;;
    esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BASE_INFO="$PROJECT_ROOT/baseInfo.php"
DOCROOT="$PROJECT_ROOT/deltapay/public"

if [ -z "$DOMAIN" ] && [ -f "$BASE_INFO" ]; then
    DOMAIN="$(php -r 'include $argv[1]; echo isset($paymentDomain) ? trim((string)$paymentDomain) : "";' "$BASE_INFO" 2>/dev/null || true)"
fi

if [ -z "$DOMAIN" ]; then
    echo "[DeltaPay] Payment domain is required."
    echo "Example: bash $SCRIPT_DIR/install.sh --domain pay.example.com"
    exit 1
fi

DOMAIN="$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed 's#^https\?://##; s#/.*$##; s/[[:space:]]//g')"
if ! [[ "$DOMAIN" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; then
    echo "[DeltaPay] Invalid domain: $DOMAIN"
    exit 1
fi

if [ ! -f "$BASE_INFO" ]; then
    echo "[DeltaPay] $BASE_INFO was not found. Install/configure the bot first."
    exit 1
fi

if [ ! -d "$DOCROOT" ]; then
    echo "[DeltaPay] DocumentRoot not found: $DOCROOT"
    exit 1
fi

if ! command -v apache2ctl >/dev/null 2>&1; then
    echo "[DeltaPay] Apache is not installed."
    exit 1
fi

apt-get update -y >/dev/null
apt-get install -y certbot python3-certbot-apache >/dev/null

a2enmod ssl rewrite headers >/dev/null
ufw allow 80 >/dev/null 2>&1 || true
ufw allow 443 >/dev/null 2>&1 || true

# Persist the payment domain in baseInfo.php. update.sh already preserves this
# file, so the domain survives every bot update.
php "$SCRIPT_DIR/write-baseinfo.php" "$BASE_INFO" "$DOMAIN"

# Patch the legacy bot/config files so the admin gets a Personal Gateway toggle
# and users see the branded payment button on invoices. The patcher is
# idempotent and rolls back automatically if PHP syntax validation fails.
if [ -f "$SCRIPT_DIR/apply-bot-integration.php" ]; then
    echo "[DeltaPay] Applying Telegram bot personal-gateway integration..."
    php "$SCRIPT_DIR/apply-bot-integration.php"
fi

# The original bot still contains ZarinPal's old SOAP/WebGate implementation.
# Upgrade it to REST v4 on every fresh install/update. This patcher is also
# idempotent and restores both payment files if syntax validation fails.
if [ -f "$SCRIPT_DIR/apply-zarinpal-v4.php" ]; then
    echo "[DeltaPay] Applying ZarinPal REST v4 integration..."
    php "$SCRIPT_DIR/apply-zarinpal-v4.php"
fi

chown www-data:www-data "$BASE_INFO" || true
chmod 640 "$BASE_INFO" || true
chown -R www-data:www-data "$PROJECT_ROOT/deltapay"
find "$PROJECT_ROOT/deltapay" -type d -exec chmod 755 {} \;
find "$PROJECT_ROOT/deltapay" -type f -exec chmod 644 {} \;
chmod 755 "$SCRIPT_DIR/install.sh"

VHOST="/etc/apache2/sites-available/${DOMAIN}.conf"
LEGACY_SSL_SITE="${DOMAIN}-le-ssl.conf"
CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"

write_http_vhost() {
    cat > "$VHOST" <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${DOCROOT}

    <Directory ${DOCROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/deltapay-${DOMAIN}-error.log
    CustomLog \${APACHE_LOG_DIR}/deltapay-${DOMAIN}-access.log combined
</VirtualHost>
EOF
}

write_https_vhost() {
    cat > "$VHOST" <<EOF
<VirtualHost *:80>
    ServerName ${DOMAIN}
    Redirect permanent / https://${DOMAIN}/
</VirtualHost>

<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName ${DOMAIN}
    DocumentRoot ${DOCROOT}

    <Directory ${DOCROOT}>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>

    SSLEngine on
    SSLCertificateFile ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile ${CERT_DIR}/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"

    ErrorLog \${APACHE_LOG_DIR}/deltapay-${DOMAIN}-ssl-error.log
    CustomLog \${APACHE_LOG_DIR}/deltapay-${DOMAIN}-ssl-access.log combined
</VirtualHost>
</IfModule>
EOF
}

# Certbot may have created a separate -le-ssl vhost during an older/manual
# setup. Disable it to avoid two competing :443 virtual hosts for DeltaPay.
if [ -e "/etc/apache2/sites-enabled/${LEGACY_SSL_SITE}" ] || [ -e "/etc/apache2/sites-available/${LEGACY_SSL_SITE}" ]; then
    a2dissite "$LEGACY_SSL_SITE" >/dev/null 2>&1 || true
fi

if [ -f "${CERT_DIR}/fullchain.pem" ] && [ -f "${CERT_DIR}/privkey.pem" ]; then
    write_https_vhost
else
    write_http_vhost
fi

a2ensite "${DOMAIN}.conf" >/dev/null
apache2ctl configtest
systemctl reload apache2

if [ ! -f "${CERT_DIR}/fullchain.pem" ]; then
    echo "[DeltaPay] No certificate found. Requesting Let's Encrypt certificate for ${DOMAIN}..."
    CERTBOT_ARGS=(certonly --webroot -w "$DOCROOT" -d "$DOMAIN" --agree-tos --non-interactive)
    if [ -n "$EMAIL" ]; then
        CERTBOT_ARGS+=(--email "$EMAIL")
    else
        CERTBOT_ARGS+=(--register-unsafely-without-email)
    fi

    if certbot "${CERTBOT_ARGS[@]}"; then
        write_https_vhost
        apache2ctl configtest
        systemctl reload apache2
    else
        echo "[DeltaPay] WARNING: SSL could not be issued."
        echo "[DeltaPay] Make sure the A/AAAA record for ${DOMAIN} points to this server, then run this installer again."
        exit 2
    fi
fi

# Seed/update the runtime configuration after the database exists.
php "$SCRIPT_DIR/configure.php" --domain "$DOMAIN" >/dev/null 2>&1 || true

systemctl enable certbot.timer >/dev/null 2>&1 || true
systemctl start certbot.timer >/dev/null 2>&1 || true

if [ "$YES" -ne 1 ]; then
    echo
fi

echo "[DeltaPay] Installed successfully."
echo "[DeltaPay] Domain: https://${DOMAIN}"
echo "[DeltaPay] Order URL: https://${DOMAIN}/pay/start/?order_id=ORDER_ID"
echo "[DeltaPay] DocumentRoot: ${DOCROOT}"
