#!/usr/bin/env python3
"""Delta quota gate prototype. Runs on a dedicated Linux gateway, not the bot host.
Requires an authenticated WireGuard peer /32 per account, no direct client access
to upstream credentials, and separately configured forwarding. No auto-reapply.
"""
import argparse
import fcntl
import hashlib
import os
import tempfile
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

def config_digest(cfg):
    return hashlib.sha256(json.dumps(cfg,sort_keys=True,separators=(",",":")).encode()).hexdigest()

def boot_id():
    return Path("/proc/sys/kernel/random/boot_id").read_text().strip()

def durable_write(path, value):
    # Atomic checkpoint, root-only directory, and fsync before returning.
    fd,tmp=tempfile.mkstemp(prefix=".checkpoint-",dir=path.parent)
    try:
        with os.fdopen(fd,"w") as f:
            json.dump(value,f,sort_keys=True); f.flush(); os.fsync(f.fileno())
        os.replace(tmp,path)
        dfd=os.open(path.parent,os.O_DIRECTORY)
        try: os.fsync(dfd)
        finally: os.close(dfd)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def stateful(action,cfg,rules,state_dir):
    state_dir.mkdir(mode=0o700,parents=True,exist_ok=True)
    if state_dir.is_symlink() or state_dir.stat().st_mode & 0o077 or state_dir.stat().st_uid != os.geteuid():
        raise RuntimeError("state directory must be private and owned by operator")
    with (state_dir/"lock").open("a") as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        state_path=state_dir/"checkpoint.json"
        digest=config_digest(cfg)
        if action=="install-once":
            if state_path.exists():
                raise RuntimeError("existing durable epoch; refuse reset/reinstall, reconcile first")
            # Persist intent BEFORE installing. Failure leaves a blocked epoch requiring review.
            state={"boot":boot_id(),"config":digest,"phase":"installing","rows":[]}
            durable_write(state_path,state)
            nft("-f","-",data=rules.replace(f"table inet {TABLE} {{",f"create table inet {TABLE}\ntable inet {TABLE} {{",1))
            state["phase"]="installed"
            durable_write(state_path,state)
            print("gate installed; no forwarding or WireGuard settings were changed")
        else:
            state=json.loads(state_path.read_text())
            if state["phase"]!="installed" or state["config"]!=digest or state["boot"]!=boot_id():
                raise RuntimeError("epoch/config/boot mismatch; counters unknown; reconcile before enabling traffic")
            rows=snapshot(cfg,json.loads(nft("-j","list","table","inet",TABLE).stdout))
            previous={r["id"]:r for r in state["rows"]}
            for r in rows:
                old=previous.get(r["id"])
                if old and old["used_bytes"] is not None and (r["used_bytes"] is None or r["used_bytes"]<old["used_bytes"]):
                    raise RuntimeError("counter disappeared or reset; reconcile")
            state["rows"]=rows
            state["observed_at"]=int(time.time())
            durable_write(state_path,state)
            print(json.dumps(rows,indent=2))

def main():
    p=argparse.ArgumentParser()
    p.add_argument("action", choices=("render","check","install-once","snapshot"))
    p.add_argument("config",type=Path)
    p.add_argument("--state-dir",type=Path,default=Path("/var/lib/delta-quota-lab"))
    args=p.parse_args()
    cfg=json.loads(args.config.read_text())
    rules=render(cfg)
    if args.action=="render":
        print(rules,end="")
    elif args.action=="check":
        nft("-c","-f","-",data=rules)
        print("syntax OK")
    else:
        stateful(args.action,cfg,rules,args.state_dir)

if __name__=="__main__":
    main()
