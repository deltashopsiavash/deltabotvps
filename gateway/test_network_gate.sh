#!/usr/bin/env bash
set -euo pipefail
# Run only in CI's isolated network namespace. No host networking changes.
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
peer="delta-quota-peer-$$"
cleanup() { ip netns del "$peer" 2>/dev/null || true; rm -rf "$tmp"; }
trap cleanup EXIT
ip netns add "$peer"
ip link add wgdelta type veth peer name client0
ip link set client0 netns "$peer"
ip addr add 10.120.0.1/24 dev wgdelta
ip link set wgdelta up
ip netns exec "$peer" ip link set lo up
ip netns exec "$peer" ip link set client0 up
for addr in 2 3 4 5; do ip netns exec "$peer" ip addr add "10.120.0.$addr/24" dev client0; done
python3 - "$tmp/lab.json" <<'PY'
import json,sys,time
now=int(time.time())
gib=1024**3
accounts=[
    dict(id=1,ip="10.120.0.2",gb=1,used_bytes=gib-300,expires=now+120,active=True),
    dict(id=2,ip="10.120.0.3",gb=1,used_bytes=0,expires=now+120,active=True),
    dict(id=3,ip="10.120.0.4",gb=1,used_bytes=0,expires=now+12,active=True)]
json.dump(dict(interface="wgdelta",accounts=accounts),open(sys.argv[1],"w"))
PY
python3 "$here/quota_gate.py" check "$tmp/lab.json"
python3 "$here/quota_gate.py" install-once "$tmp/lab.json" --state-dir "$tmp/state"
ping_from() { ip netns exec "$peer" ping -4 -n -c 1 -W 1 -s 56 -I "10.120.0.$1" 10.120.0.1 >/dev/null; }
ping_from 1 && exit 1 || true # unknown / gateway address cannot be sourced by client
ping_from 2
if ping_from 2; then echo "quota not enforced"; exit 1; fi
if ping_from 2; then echo "quota reset unexpectedly"; exit 1; fi
ping_from 3
ping_from 4
if ping_from 5; then echo "unknown peer admitted"; exit 1; fi
sleep 12
if ping_from 4; then echo "expired peer admitted"; exit 1; fi
ping_from 3
python3 "$here/quota_gate.py" snapshot "$tmp/lab.json" --state-dir "$tmp/state" > "$tmp/snapshot.json"
python3 - "$tmp/snapshot.json" <<'PY'
import json,sys
a,b,c=json.load(open(sys.argv[1]))
assert a["used_bytes"] <= 1024**3 and 0 <= a["remaining_bytes"] < 168, a
assert a["upload_since_load"] > 0 and a["download_since_load"] > 0, a
assert b["upload_since_load"] >= 168 and b["download_since_load"] >= 168,b
assert c["upload_since_load"] >= 84 and c["download_since_load"] >= 84,c
print("Independent packet admission, expiry, unknown peer and shared-direction quota verified")
PY
if python3 "$here/quota_gate.py" install-once "$tmp/lab.json" --state-dir "$tmp/state"; then exit 1; fi
