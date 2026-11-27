#!/usr/bin/env bash
# Install only the fixed privileged helper. Do not change production bot code.
set -Eeuo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
src=$(cd "$(dirname "$0")" && pwd)
mode=${1:-normal}
[[ "$mode" == normal || "$mode" == --recovery-only ]] || exit 2
for c in python3 gpg; do
    command -v "$c" >/dev/null || { echo "MISSING=$c"; exit 1; }
done
if [[ "$mode" == normal ]]; then
    for c in sudo visudo mysql; do command -v "$c" >/dev/null || { echo "MISSING=$c"; exit 1; }; done
    [[ -f /var/www/html/deltabotvps/baseInfo.php ]] || exit 1
fi
install -d -o root -g root -m 0700 /etc/delta-full-backup /var/lib/delta-full-backup
key=/etc/delta-full-backup/recovery.key
if [[ ! -e "$key" ]]; then
    [[ -t 0 ]] || { echo 'Interactive recovery key setup required'; exit 1; }
    read -rs -p 'Independent backup recovery passphrase (at least 24 characters): ' pass
    printf '\n'
    read -rs -p 'Repeat passphrase: ' again
    printf '\n'
    [[ "$pass" == "$again" && "${#pass}" -ge 24 ]] || { echo 'Invalid passphrase'; exit 1; }
    printf '%s\n' "$pass" > "$key"
    unset pass again
fi
[[ -f "$key" && ! -L "$key" ]] || exit 1
chown root:root "$key"
chmod 600 "$key"
python3 -m py_compile "$src/delta-full-backup.py"
install -o root -g root -m 0755 "$src/delta-full-backup.py" /usr/local/sbin/delta-full-backup
if [[ "$mode" == --recovery-only ]]; then
    echo "DELTA_RECOVERY_TOOL_INSTALLED; use verify-local and prepare-install as root"
    exit 0
fi
# Save verified UI copies outside the restored web tree, so an old backup
# cannot remove the new restore interface on a successful automated restore.
install -d -o root -g root -m 0700 /etc/delta-full-backup/ui
for f in bot.php backup_worker.php; do
    php -l "$src/../$f"
    install -o root -g root -m 0600 "$src/../$f" "/etc/delta-full-backup/ui/$f"
done
tmp=$(mktemp /etc/sudoers.d/.delta-backup.XXXXXXXX)
trap 'rm -f "$tmp"' EXIT
printf '%s\n' 'www-data ALL=(root) NOPASSWD: /usr/local/sbin/delta-full-backup create, /usr/local/sbin/delta-full-backup verify, /usr/local/sbin/delta-full-backup queue-restore-password' > "$tmp"
chmod 0440 "$tmp"
visudo -cf "$tmp"
mv -fT "$tmp" /etc/sudoers.d/delta-full-backup
visudo -c >/dev/null
echo 'DELTA_FULL_BACKUP_HELPER_INSTALLED'
echo 'Keep the independent recovery passphrase offline. Never send it in Telegram.'
