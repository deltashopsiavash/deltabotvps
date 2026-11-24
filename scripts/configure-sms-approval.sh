#!/bin/bash
set -euo pipefail
umask 077
[[ $(id -u) == 0 ]] || { echo 'Run as root.'; exit 1; }
[[ -r /etc/delta-sms-bridge.php && -d /var/lib/delta-sms-bridge/events && -f /var/www/html/deltabotvps/baseInfo.php ]] || { echo 'Install the private mother SMS Bridge first.'; exit 1; }
echo 'Only dedicated receiving accounts used exclusively for Delta card payments may be mapped.'
echo 'Do not use accounts with unrelated deposits or ambiguous account suffixes.'
read -r -p 'Confirm dedicated accounts and exact rial payments (type YES): ' confirm
[[ $confirm == YES ]] || exit 1
tmp=$(mktemp /etc/.delta-sms-approval.XXXXXX)
trap 'rm -f "$tmp"' EXIT
# Never put real full card numbers in argv, logs, or the repository.
python3 - "$tmp" <<'PY'
import json, re, sys
from getpass import getpass
cards={}
tty=open('/dev/tty','r')
def ask(prompt):
    print(prompt,end='',flush=True)
    return tty.readline().strip()
while True:
    card=getpass('Full receiving card number (empty to finish): ').strip()
    if not card: break
    if not re.fullmatch(r'[0-9]{16}',card): print('Invalid card number.'); continue
    bank=ask('Bank (melli/pasargad/khavar): ')
    hint=ask('Exact five-digit account hint from actual incoming SMS: ')
    if bank not in ('melli','pasargad','khavar') or not re.fullmatch(r'[0-9]{5}',hint):
        print('Invalid bank or account hint.'); continue
    cards[card]={'bank':bank,'account_hint':hint}
if not cards: raise SystemExit('No mappings supplied; existing settings preserved.')
with open(sys.argv[1],'w') as f: json.dump({'dedicated_accounts_confirmed':True,'cards':cards},f)
PY
chown root:www-data "$tmp"
chmod 0640 "$tmp"
mv -f "$tmp" /etc/delta-sms-approval.json
echo 'Private mapping saved. Turn on SMS approval only after updating both repos and the phone.'
