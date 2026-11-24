#!/usr/bin/env bash
# Mother-only bootstrapper for the PRIVATE delta-sms-bridge repository.
# This is called by the main Delta installer after mother baseInfo.php exists.
# Child/reseller webhooks use bot.php?bid=N and must NEVER invoke this hook.
set -Eeuo pipefail
umask 077

MOTHER_ROOT="/var/www/html/deltabotvps"
MOTHER_CONFIG="$MOTHER_ROOT/baseInfo.php"
BRIDGE_REPO="deltashopsiavash/delta-sms-bridge"
BRIDGE_DIR="/opt/delta-sms-bridge"
DOMAIN=""
NON_INTERACTIVE=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --domain) [ "$#" -gt 1 ] || { echo "Missing --domain value" >&2; exit 2; }; DOMAIN="$2"; shift 2 ;;
        --non-interactive) NON_INTERACTIVE=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done
say(){ printf '[Mother SMS Bridge] %s\n' "$*"; }
fail(){ printf '[Mother SMS Bridge] ERROR: %s\n' "$*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "Must run as root on the mother bot VPS."
[ -r "$MOTHER_CONFIG" ] || fail "Main bot config missing. Skipping bridge; no reseller changes."
command -v php >/dev/null || fail "PHP is required for mother-only verification."

# This check is intentionally strict. Child bot webhook/querystring MUST NOT pass.
CHECK="$(php -r '
    $config=$argv[1];
    include $config;
    $url=(string)($botUrl??"");
    $u=parse_url($url);
    if (!is_array($u) || strtolower((string)($u["scheme"]??""))!=="https"
        || (string)($u["path"]??"")!=="/deltabotvps/"
        || !empty($u["query"]) || !empty($u["fragment"])
        || !preg_match("/^[a-z0-9.-]+$/i",(string)($u["host"]??""))
        || !preg_match("/^[0-9]+:[a-zA-Z0-9_-]+$/",(string)($botToken??""))
        || !ctype_digit((string)($admin??""))
        || !preg_match("/^[a-zA-Z0-9_]+$/",(string)($dbName??""))
        || preg_match("/_rb[0-9]+$/i",(string)($dbName??""))) exit(2);
    echo strtolower((string)$u["host"]);
' "$MOTHER_CONFIG" 2>/dev/null)" || fail "Not a verified mother bot installation. Reseller bots are excluded."
[ -n "$CHECK" ] || fail "Missing mother domain."
if [ -n "$DOMAIN" ]; then
    DOMAIN="$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed -E 's#^https?://##;s#/.*$##')"
    [ "$DOMAIN" = "$CHECK" ] || fail "Requested domain is not the verified mother domain. Refusing cross-bot installation."
else
    DOMAIN="$CHECK"
fi
say "Verified MOTHER bot on domain $DOMAIN; reseller bots (bid>0) excluded."

# Private repository cannot be cloned anonymously. A new VPS needs a one-time
# GitHub device login. No token, phone secret or bot credential is printed.
if ! command -v gh >/dev/null 2>&1; then
    say "Installing GitHub CLI to access the owner's separate private repo..."
    DEBIAN_FRONTEND=noninteractive apt-get update -qq || fail "Could not update package index for GitHub CLI."
    DEBIAN_FRONTEND=noninteractive apt-get install -y gh >/dev/null || fail "Could not install GitHub CLI."
fi
if ! gh auth status -h github.com >/dev/null 2>&1; then
    if [ "$NON_INTERACTIVE" -eq 1 ]; then
        fail "Private GitHub access is not configured; preserving existing SMS Bridge. Re-run interactively to authorize once."
    fi
    [ -t 0 ] || fail "Private GitHub authorization requires interactive SSH. Re-run on a terminal."
    say "One-time PRIVATE GitHub device login is required for a new VPS."
    say "Open the URL shown by GitHub on your own phone and enter its device code there, not in chat."
    gh auth login -h github.com -p https -w || fail "GitHub authorization was cancelled."
fi
gh auth setup-git >/dev/null 2>&1 || true
if ! gh repo view "$BRIDGE_REPO" --json name >/dev/null 2>&1; then
    fail "Cannot read the owner's private Delta SMS Bridge repo. Check GitHub account permissions."
fi
if [ -e "$BRIDGE_DIR" ]; then
    [ -d "$BRIDGE_DIR/.git" ] || fail "$BRIDGE_DIR exists but is not a Git checkout. Refusing to overwrite it."
    REMOTE="$(git -C "$BRIDGE_DIR" remote get-url origin 2>/dev/null || true)"
    case "$REMOTE" in
        "https://github.com/$BRIDGE_REPO"|"https://github.com/$BRIDGE_REPO.git"|"git@github.com:$BRIDGE_REPO.git") ;;
        *) fail "Existing source checkout points to an unexpected repository; refusing to update." ;;
    esac
    git -C "$BRIDGE_DIR" pull --ff-only || fail "Private repo update failed; previous installed bridge remains intact."
else
    gh repo clone "$BRIDGE_REPO" "$BRIDGE_DIR" || fail "Cannot clone private bridge repository. Mother bot remains functional."
fi
chown -R root:root "$BRIDGE_DIR"
chmod 700 "$BRIDGE_DIR"
[ -f "$BRIDGE_DIR/scripts/install-ubuntu-apache.sh" ] || fail "Private bridge installer missing."

# This new flag enforces mother-only routing in the independent bridge installer.
if bash "$BRIDGE_DIR/scripts/install-ubuntu-apache.sh" --mother-only --domain "$DOMAIN"; then
    say "Private SMS Bridge is installed for the verified MOTHER bot only."
    if [ -f "$BRIDGE_DIR/scripts/check-bot-readonly.php" ]; then
        say "Running optional read-only mother invoice compatibility check..."
        php "$BRIDGE_DIR/scripts/check-bot-readonly.php" || say "Invoice check unavailable; SMS monitoring remains independent."
    fi
    say "Phone needs to be paired using the private URL/SECRET on this VPS."
    say "NEVER send pairing secrets or bank credentials to GitHub or chat."
else
    fail "Independent SMS Bridge setup failed. Main bot stays installed; fix this step separately."
fi
