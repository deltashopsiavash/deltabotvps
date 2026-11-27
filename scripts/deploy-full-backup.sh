#!/usr/bin/env bash
# Targeted upgrade: no DB import, no token/webhook change, no legacy update.
set -Eeuo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Run as root'; exit 1; }
src=$(cd "$(dirname "$0")/.." && pwd)
live=/var/www/html/deltabotvps
base=56e1becdcaaa65c3040d26dd0985772fd759c1a3
head=$(git -C "$src" rev-parse HEAD)
[[ "$src" != "$live" && -d "$live" && ! -L "$live" && -f "$live/baseInfo.php" ]] || exit 1
[[ "$#" == 1 && "$1" == "$head" && "$head" =~ ^[a-f0-9]{40}$ ]] || { echo 'Expected exact verified commit required'; exit 1; }
for c in git gh php rsync python3 flock; do
    command -v "$c" >/dev/null || { echo "MISSING=$c"; exit 1; }
done
passed=$(gh run list -R deltashopsiavash/deltabotvps --commit "$head" --limit 40 --json workflowName,status,conclusion --jq 'any(.[]; .workflowName=="PHP Lint" and .status=="completed" and .conclusion=="success") and any(.[]; .workflowName=="Full Delta restore on Ubuntu 24.04" and .status=="completed" and .conclusion=="success")')
[[ "$passed" == true ]] || { echo 'CI_OR_FRESH_RESTORE_TEST_NOT_PASSED'; exit 1; }
exec 9>/root/.delta-full-backup-deploy.lock
flock -n 9 || { echo 'DEPLOY_ALREADY_RUNNING'; exit 1; }
stage=$(mktemp -d /root/delta-full-backup-deploy.XXXXXXXX)
backup=$(mktemp -d /root/delta-full-backup-previous.XXXXXXXX)
done_ok=0
applied=()
cleanup(){
    rc=$?
    trap - EXIT
    if [[ "$done_ok" == 0 ]]; then
        for f in "${applied[@]}"; do cp -a "$backup/$f" "$live/$f"; done
        echo "DEPLOYMENT_STOPPED; preserved original files in $backup" >&2
    fi
    rm -rf -- "$stage"
    exit "$rc"
}
trap cleanup EXIT
for f in bot.php backup_worker.php; do
    [[ -f "$live/$f" && ! -L "$live/$f" ]] || exit 1
    git -C "$src" show "$base:$f" > "$stage/baseline"
    git -C "$src" show "$head:$f" > "$stage/$f"
    cmp -s "$live/$f" "$stage/baseline" || cmp -s "$live/$f" "$stage/$f" || { echo "LOCAL_VERSION_MISMATCH=$f"; exit 1; }
    php -l "$stage/$f"
    cp -a "$live/$f" "$backup/$f"
done
# Setup a separate offline recovery password; do not regenerate any bot tokens.
bash "$src/scripts/install-full-backup.sh"
for f in bot.php backup_worker.php; do
    tmp=$(mktemp "$live/.delta-full-backup.XXXXXXXX")
    install -o root -g www-data -m 0644 "$stage/$f" "$tmp"
    applied+=("$f")
    mv -fT "$tmp" "$live/$f"
done
done_ok=1
echo "DELTA_FULL_BACKUP_DEPLOYED; original files: $backup"
echo 'Create and verify a real backup before considering any destructive rebuild.'
