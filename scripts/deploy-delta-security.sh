#!/usr/bin/env bash
# Targeted security update. Never deletes the live tree or runs legacy migrations.
set -Eeuo pipefail
umask 077
mode=$1
expected=$2
[[ "$mode" == --check || "$mode" == --apply ]] || { echo 'Usage: bash scripts/deploy-delta-security.sh --check|--apply COMMIT'; exit 2; }
[[ "$expected" =~ ^[a-f0-9]{40}$ ]] || exit 2
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
src=$(cd "$(dirname "$0")/.." && pwd)
live=/var/www/html/deltabotvps
base=9e2dea9748b9d8dc153f72e30ac53c653b2eeae9
[[ "$src" != "$live" && -d "$live" && ! -L "$live" && -f "$live/baseInfo.php" && ! -L "$live/baseInfo.php" ]] || exit 1
[[ $(git -C "$src" rev-parse HEAD) == "$expected" ]] || { echo 'WRONG_CHECKOUT'; exit 1; }
git -C "$src" cat-file -e "$base^{commit}"
for cmd in php git flock crontab apache2ctl a2enconf systemctl; do command -v "$cmd" >/dev/null || { echo "MISSING_COMMAND=$cmd"; exit 1; }; done
systemctl is-active --quiet apache2 || { echo 'Apache installation required; no changes made'; exit 1; }
exec 9>/root/.delta-security-deploy.lock
flock -n 9 || { echo 'Another security deployment is active'; exit 1; }
stage=$(mktemp -d /root/delta-security-stage.XXXXXXXX)
backup=''
applied=()
cron_changed=0
apache_changed=0
finished=0
conf=/etc/apache2/conf-available/delta-private.conf
was_enabled=0
[[ -L /etc/apache2/conf-enabled/delta-private.conf ]] && was_enabled=1
cleanup(){
    rc=$?
    trap - EXIT
    if [[ $rc != 0 && "$finished" == 0 && -n "$backup" ]]; then
        for rel in "${applied[@]}"; do
            if [[ -f "$backup/files/$rel" ]]; then
                cp -a "$backup/files/$rel" "$live/$rel"
            else
                rm -f "$live/$rel"
            fi
        done
        if [[ "$cron_changed" == 1 ]]; then crontab "$backup/root.crontab" || true; fi
        if [[ "$apache_changed" == 1 ]]; then
            if [[ -f "$backup/apache.conf" ]]; then cp -a "$backup/apache.conf" "$conf"; else rm -f "$conf"; fi
            if [[ "$was_enabled" == 0 ]]; then a2disconf delta-private >/dev/null 2>&1 || true; fi
        fi
        apache2ctl configtest >/dev/null 2>&1 && systemctl reload apache2 || true
        echo "DEPLOYMENT_STOPPED; backup: $backup" >&2
    fi
    rm -rf -- "$stage"
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
files=(reseller_webhook.php config.php bot.php delta_app_core.php delta_app_api.php settings/rewardReport.php settings/warnusers.php install/update.php install/install.php delta.sh update.sh scripts/configure-webhook-auth.php)
for rel in "${files[@]}"; do
    [[ ! -L "$live/$rel" && ! -L "$(dirname "$live/$rel")" ]] || { echo "UNEXPECTED_LINK=$rel"; exit 1; }
    mkdir -p "$stage/$(dirname "$rel")"
    git -C "$src" show "$expected:$rel" > "$stage/$rel"
    if git -C "$src" cat-file -e "$base:$rel" 2>/dev/null; then
        git -C "$src" show "$base:$rel" > "$stage/baseline"
        [[ -f "$live/$rel" ]] && { cmp -s "$live/$rel" "$stage/baseline" || cmp -s "$live/$rel" "$stage/$rel"; } || { echo "LOCAL_VERSION_MISMATCH=$rel"; exit 1; }
    elif [[ -e "$live/$rel" ]] && ! cmp -s "$live/$rel" "$stage/$rel"; then
        echo "LOCAL_VERSION_MISMATCH=$rel"; exit 1
    fi
    case "$rel" in
        *.php) php -l "$stage/$rel" ;;
        *.sh) bash -n "$stage/$rel" ;;
    esac
done
git -C "$src" show "$expected:docs/apache-delta-private.conf" > "$stage/apache.conf"
ln -s "$live/baseInfo.php" "$stage/baseInfo.php"
php "$stage/scripts/configure-webhook-auth.php" --check
apache2ctl configtest
if [[ "$mode" == --check ]]; then echo 'DELTA_SECURITY_PREFLIGHT_OK'; exit 0; fi
command -v gh >/dev/null || { echo 'GitHub CLI needed to verify CI'; exit 1; }
passed=$(gh run list -R deltashopsiavash/deltabotvps --workflow php-lint.yml --commit "$expected" --limit 20 --json status,conclusion --jq 'any(.[]; .status=="completed" and .conclusion=="success")')
[[ "$passed" == true ]] || { echo 'CI_NOT_PASSED_FOR_THIS_COMMIT'; exit 1; }
backup=$(mktemp -d /root/delta-security-backup.XXXXXXXX)
mkdir -p "$backup/files"
for rel in "${files[@]}"; do
    if [[ -f "$live/$rel" ]]; then (cd "$live" && cp -a --parents "$rel" "$backup/files/"); fi
done
cp -a "$live/baseInfo.php" "$backup/baseInfo.php"
[[ ! -f "$conf" ]] || cp -a "$conf" "$backup/apache.conf"
crontab -l > "$backup/root.crontab" 2>/dev/null || true
# Full dump of the mother bot database, using credentials only inside a local child process.
dump=$(command -v mariadb-dump || command -v mysqldump)
php -r '
require $argv[1];
if(!preg_match("/^[a-zA-Z0-9_]+$/D",(string)$dbName))exit(1);
$env=getenv();$env["MYSQL_PWD"]=$dbPassword;
$cmd=[$argv[3],"--host=localhost","--user=".$dbUserName,"--single-transaction","--quick","--skip-lock-tables","--routines","--events","--triggers","--databases",$dbName];
$p=proc_open($cmd,[0=>["file","/dev/null","r"],1=>["file",$argv[2],"w"],2=>["file",$argv[2].".stderr","w"]],$pipes,null,$env);
exit(is_resource($p)?proc_close($p):1);
' "$live/baseInfo.php" "$backup/mother.sql" "$dump"
[[ -s "$backup/mother.sql" ]]
gzip "$backup/mother.sql"
gzip -t "$backup/mother.sql.gz"
# Register secrets before deploying mandatory checks. Existing tokens are unchanged.
php "$stage/scripts/configure-webhook-auth.php" --apply
# Protect repository metadata and runtime files from HTTP access.
apache_changed=1
install -o root -g root -m 0644 "$stage/apache.conf" "$conf"
a2enconf delta-private >/dev/null
apache2ctl configtest
# Install local cron replacements before restricting maintenance to CLI.
awk '!/deltabotvps\/settings\/(rewardReport|warnusers)\.php/' "$backup/root.crontab" > "$stage/cron"
printf '%s\n' '* * * * * cd /var/www/html/deltabotvps/settings && /usr/bin/flock -n /tmp/deltabotvps-reward-report.lock /usr/bin/php /var/www/html/deltabotvps/settings/rewardReport.php >/dev/null 2>&1' '* * * * * cd /var/www/html/deltabotvps/settings && /usr/bin/flock -n /tmp/deltabotvps-warnusers.lock /usr/bin/php /var/www/html/deltabotvps/settings/warnusers.php >/dev/null 2>&1' >> "$stage/cron"
cron_changed=1
crontab "$stage/cron"
for rel in "${files[@]}"; do
    tmp=$(mktemp "$(dirname "$live/$rel")/.delta-security.XXXXXXXX")
    install -o root -g www-data -m 0644 "$stage/$rel" "$tmp"
    if [[ -e "$live/$rel" ]]; then chmod --reference="$live/$rel" "$tmp"; fi
    applied+=("$rel")
    mv -fT "$tmp" "$live/$rel"
done
chown root:www-data "$live/baseInfo.php"
chmod 640 "$live/baseInfo.php"
if [[ -f /root/confdelta/dbrootdelta.txt && ! -L /root/confdelta/dbrootdelta.txt ]]; then chmod 600 /root/confdelta/dbrootdelta.txt; fi
if [[ -f /opt/vpsbot/.env && ! -L /opt/vpsbot/.env ]]; then chmod 600 /opt/vpsbot/.env; fi
systemctl reload apache2
finished=1
echo "DELTA_SECURITY_DEPLOYED; backup: $backup"
echo 'Next: verify actual Telegram delivery, purchases, renewal and API login with a test account.'
