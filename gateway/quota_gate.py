#!/usr/bin/env python3
"""Delta quota gate prototype. Runs on a dedicated Linux gateway, not the bot host.
Requires an authenticated WireGuard peer /32 per account, no direct client access
to upstream credentials, and separately configured forwarding. No auto-reapply.
"""
import argparse
import ipaddress
import json
import re
import subprocess
import time
from pathlib import Path

TABLE = "delta_quota"
GIB = 1024 ** 3

def normalize(config, now=None):
    now = int(time.time()) if now is None else now
    interface = config["interface"]
    if not isinstance(interface, str) or not re.fullmatch(r"[A-Za-z0-9_.-]{1,15}", interface):
        raise ValueError("invalid interface")
    ids, ips, accounts = set(), set(), []
    for raw in config["accounts"]:
        i, gb, used, expires = (raw[k] for k in ("id", "gb", "used_bytes", "expires"))
        if any(type(v) is not int for v in (i, gb, used, expires)):
            raise ValueError("integer account values required")
        addr = str(ipaddress.IPv4Address(raw["ip"]))
        if i < 1 or i > 4294967295 or not 1 <= gb <= 100000 or not 0 <= used <= gb * GIB:
            raise ValueError("invalid account values")
        if i in ids or addr in ips:
            raise ValueError("duplicate account id or peer IP")
        ids.add(i); ips.add(addr)
        if raw.get("active") is not True or expires <= now or used == gb * GIB:
            continue
        ttl = expires - now
        if ttl > 3650 * 86400:
            raise ValueError("expiry too far in future")
        accounts.append({"id":i,"ip":addr,"limit":gb*GIB,"used":used,"ttl":ttl})
    return interface, sorted(accounts, key=lambda a:a["id"])

def render(config, now=None):
    interface, accounts = normalize(config, now)
    # Shared named quota admits at most the remaining IP-packet bytes across both directions.
    # Named counters AFTER quota count only packets admitted by this gate, not rejected attempts.
    lines = [f"table inet {TABLE} {{",
             " set active4 {", "  type ipv4_addr", "  flags timeout"]
    if accounts:
        elems = ", ".join(f'{a["ip"]} timeout {a["ttl"]}s' for a in accounts)
        lines.append("  elements = { "+elems+" }")
    lines.append(" }")
    for a in accounts:
        i=a["id"]; left=a["limit"]-a["used"]
        lines.extend([f" quota q_{i} {{ until {left} bytes; }}",
                      f" counter up_{i} {{ }}", f" counter down_{i} {{ }}"])
    lines.extend([" chain ingress {", "  type filter hook prerouting priority -150; policy accept;"])
    for a in accounts:
        i=a["id"]
        lines.append(f'  iifname "{interface}" ip saddr {a["ip"]} ip saddr @active4 quota name "q_{i}" counter name "up_{i}" accept')
    lines.extend([f'  iifname "{interface}" drop'," }",
                  " chain egress {","  type filter hook postrouting priority 150; policy accept;"])
    for a in accounts:
        i=a["id"]
        lines.append(f'  oifname "{interface}" ip daddr {a["ip"]} ip daddr @active4 quota name "q_{i}" counter name "down_{i}" accept')
    lines.extend([f'  oifname "{interface}" drop'," }","}"])
    return "\n".join(lines)+"\n"

def snapshot(config, output, now=None):
    _, accounts = normalize(config, now)
    counters={}
    for entry in output["nftables"]:
        c=entry.get("counter")
        if c and c.get("table")==TABLE and c.get("family")=="inet":
            counters[c["name"]]=int(c["bytes"])
    # Include expired accounts that had counters when the gate was first loaded.
    rows=[]
    for raw in config["accounts"]:
        i=raw["id"]; limit=raw["gb"]*GIB
        up=counters.get(f"up_{i}"); down=counters.get(f"down_{i}")
        if up is None or down is None:
            rows.append({"id":i,"status":"no_live_counter","used_bytes":None,"remaining_bytes":None})
            continue
        used=raw["used_bytes"]+up+down
        if up<0 or down<0 or used>limit: raise RuntimeError("counter invariant violated")
        rows.append({"id":i,"status":"measured","upload_since_load":up,"download_since_load":down,
                     "used_bytes":used,"remaining_bytes":limit-used})
    return rows

def nft(*args, data=None):
    return subprocess.run(["nft",*args], input=data, text=True, capture_output=True, check=True)

def main():
    p=argparse.ArgumentParser()
    p.add_argument("action", choices=("render","check","install-once","snapshot"))
    p.add_argument("config",type=Path)
    args=p.parse_args()
    cfg=json.loads(args.config.read_text())
    rules=render(cfg)
    if args.action=="render":
        print(rules,end="")
    elif args.action=="check":
        nft("-c","-f","-",data=rules)
        print("syntax OK")
    elif args.action=="install-once":
        # NEVER reset counters or replace a live gate silently.
        # Exclusive add fails if table already exists; nft transaction is atomic.
        nft("-f","-",data=rules.replace(f"table inet {TABLE} {{",f"create table inet {TABLE}\ntable inet {TABLE} {{",1))
        print("gate installed; no forwarding or WireGuard settings were changed")
    else:
        print(json.dumps(snapshot(cfg,json.loads(nft("-j","list","table","inet",TABLE).stdout)),indent=2))

if __name__=="__main__":
    main()
