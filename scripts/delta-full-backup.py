#!/usr/bin/env python3
"""Root-only encrypted disaster snapshot; verify/stage never executes archive contents."""
import os, sys, re, json, tarfile, hashlib, subprocess, tempfile, shutil, pathlib, fcntl, sqlite3
from datetime import datetime, timezone

MAGIC = b"DELTA-FULL-V1\n"
ROOT = pathlib.Path("/var/lib/delta-full-backup")
KEY = pathlib.Path("/etc/delta-full-backup/recovery.key")
LIMIT = 19 * 1024 * 1024
PLAIN_LIMIT = 2 * 1024 * 1024 * 1024
PATHS = (
    "var/www/html", "var/www/delta-sms-bridge-public",
    "opt/delta-sms-bridge", "opt/vpsbot", "root/vpsbot",
    "etc/delta-sms-bridge.php", "var/lib/delta-sms-bridge",
    "root/delta-sms-bridge-pairing.txt", "root/confdelta",
    "etc/apache2", "etc/letsencrypt", "etc/mysql", "etc/php", "etc/cron.d",
    "var/spool/cron/crontabs", "etc/systemd/system/vpsbridge.service",
    "etc/systemd/system/vpsbot.service",
)
REQUIRED = ("var/www/html/deltabotvps/baseInfo.php", "etc/delta-sms-bridge.php",
            "var/lib/delta-sms-bridge", "opt/vpsbot/.env", "etc/apache2", "etc/letsencrypt")
SKIP = {".git", "venv", ".venv", "__pycache__", "node_modules", "backups"}
def require(ok, code):
    if not ok:
        raise RuntimeError(code)

def run(cmd, **kw):
    return subprocess.run(cmd, check=True, stderr=subprocess.DEVNULL, **kw)

def database_snapshot(stage):
    env = dict(os.environ)
    env.pop("MYSQL_PWD", None)
    args = ["mysql", "-uroot", "-NB"]
    if subprocess.run(args + ["-e", "SELECT 1"], env=env, stdout=subprocess.DEVNULL,
                      stderr=subprocess.DEVNULL).returncode:
        match = re.search(r"\$pass\s*=\s*'([^']*)'", pathlib.Path("/root/confdelta/dbrootdelta.txt").read_text())
        require(match, "MYSQL_ROOT_UNAVAILABLE")
        env["MYSQL_PWD"] = match.group(1)
    def query(sql):
        return run(args + ["-e", sql], env=env, stdout=subprocess.PIPE).stdout.decode().splitlines()
    dbs = sorted(n for n in query("SHOW DATABASES") if re.fullmatch(r"delta(?:_rb[1-9][0-9]*|_sms_bridge)?", n))
    require({"delta", "delta_sms_bridge"} <= set(dbs), "REQUIRED_DATABASE_MISSING")
    for row in query("SELECT id,COALESCE(db_name,'') FROM delta.reseller_bots WHERE status=1 AND is_deleted=0"):
        rid, _, name = row.partition("\t")
        require(re.fullmatch(r"delta_rb[1-9][0-9]*", name) and name in dbs, "ACTIVE_RESELLER_DB_MISSING")
    dump = shutil.which("mysqldump") or shutil.which("mariadb-dump")
    require(dump, "MYSQLDUMP_REQUIRED")
    sql = stage / "databases.sql"
    with sql.open("wb") as f:
        run([dump, "-uroot", "--lock-all-tables", "--quick", "--no-tablespaces", "--hex-blob",
             "--routines", "--events", "--triggers", "--default-character-set=utf8mb4",
             "--databases", *dbs], env=env, stdout=f)
    require(sql.stat().st_size > 1024, "EMPTY_SQL")
    with sql.open("rb") as f:
        f.seek(max(0, sql.stat().st_size - 8192))
        require(b"-- Dump completed on " in f.read(), "INCOMPLETE_SQL")
    return dbs

def sha(p):
    with p.open("rb") as f:
        return hashlib.file_digest(f, "sha256").hexdigest()

def sqlite_snapshot(stage):
    found = []
    for base in (pathlib.Path("/opt/vpsbot"), pathlib.Path("/root/vpsbot")):
        if not base.is_dir():
            continue
        for p in base.rglob("*"):
            if any(s in SKIP for s in p.relative_to(base).parts) or p.is_symlink() or not p.is_file():
                continue
            if p.suffix.lower() not in (".sqlite", ".sqlite3", ".db"):
                continue
            with p.open("rb") as f:
                if f.read(16) != b"SQLite format 3\x00":
                    continue
            rel = str(p).lstrip("/")
            target = stage / "sqlite" / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            src = sqlite3.connect(p.as_uri() + "?mode=ro", uri=True, timeout=10)
            dst = sqlite3.connect(target)
            try:
                src.backup(dst)
                require(dst.execute("PRAGMA quick_check").fetchone()[0] == "ok", "SQLITE_INVALID")
            finally:
                src.close()
                dst.close()
            found.append(rel)
    return sorted(found)

def runtime_filter(info):
    if any(x in SKIP for x in pathlib.PurePosixPath(info.name).parts) or info.name.endswith((".pyc", "-wal", "-shm", ".sock")):
        return None
    return None if info.isdev() or info.isfifo() else info

def gpg():
    return ["gpg", "--batch", "--yes", "--no-tty", "--pinentry-mode", "loopback",
            "--no-symkey-cache", "--passphrase-file", str(KEY), "--homedir", str(ROOT / "gnupg")]

def create(stage):
    for p in REQUIRED:
        require(pathlib.Path("/" + p).exists(), "REQUIRED_COMPONENT_MISSING")
    dbs = database_snapshot(stage)
    sqlite = sqlite_snapshot(stage)
    paths = [p for p in PATHS if pathlib.Path("/" + p).exists()]
    with tarfile.open(stage / "runtime.tar.gz", "w:gz", dereference=False) as tar:
        for p in paths:
            tar.add("/" + p, arcname=p, filter=lambda info: None if info.name in sqlite else runtime_filter(info))
    with (stage / "packages.txt").open("wb") as f:
        run(["dpkg-query", "-W"], stdout=f)
    names = ["databases.sql", "runtime.tar.gz", "packages.txt"]
    if sqlite:
        with tarfile.open(stage / "sqlite.tar.gz", "w:gz") as tar:
            tar.add(stage / "sqlite", arcname="sqlite")
        names.append("sqlite.tar.gz")
    manifest = {"format": 1, "created_utc": datetime.now(timezone.utc).isoformat(),
                "databases": dbs, "paths": paths, "sqlite": sqlite,
                "files": {n: {"sha256": sha(stage / n), "size": (stage / n).stat().st_size} for n in names}}
    (stage / "manifest.json").write_text(json.dumps(manifest, indent=2))
    plain = stage / "archive.tar.gz"
    with tarfile.open(plain, "w:gz") as tar:
        for n in [*names, "manifest.json"]:
            tar.add(stage / n, arcname=n, recursive=False)
    enc = stage / "archive.gpg"
    run(gpg() + ["--symmetric", "--cipher-algo", "AES256", "--compress-algo", "none",
                 "--output", str(enc), str(plain)], stdout=subprocess.DEVNULL)
    sys.stdout.buffer.write(MAGIC)
    with enc.open("rb") as f:
        shutil.copyfileobj(f, sys.stdout.buffer)

def verify(stage):
    stream = sys.stdin.buffer
    require(stream.read(len(MAGIC)) == MAGIC, "INVALID_HEADER")
    enc = stage / "input.gpg"
    with enc.open("wb") as f:
        size = 0
        while chunk := stream.read(1048576):
            size += len(chunk)
            require(size <= LIMIT, "ARCHIVE_TOO_LARGE")
            f.write(chunk)
    plain = stage / "verified.tar.gz"
    with plain.open("wb") as out:
        proc = subprocess.Popen(gpg() + ["--decrypt", str(enc)], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        total = 0
        try:
            while chunk := proc.stdout.read(1048576):
                total += len(chunk)
                require(total <= PLAIN_LIMIT, "DECRYPTED_TOO_LARGE")
                out.write(chunk)
            require(proc.wait() == 0, "KEY_OR_INTEGRITY_INVALID")
        finally:
            if proc.poll() is None:
                proc.kill()
                proc.wait()
    allowed = {"manifest.json", "databases.sql", "runtime.tar.gz", "packages.txt", "sqlite.tar.gz"}
    with tarfile.open(plain, "r:gz") as tar:
        members = tar.getmembers()
        names = [m.name for m in members]
        require(len(names) == len(set(names)) and set(names) <= allowed and all(m.isfile() for m in members), "INVALID_MEMBERS")
        require(allowed - {"sqlite.tar.gz"} <= set(names), "MISSING_MEMBERS")
        require(sum(m.size for m in members) <= PLAIN_LIMIT and tar.getmember("manifest.json").size <= 65536, "EXPANDED_TOO_LARGE")
        manifest = json.load(tar.extractfile("manifest.json"))
        require(manifest.get("format") == 1 and set(manifest["files"]) == set(names) - {"manifest.json"}, "INVALID_MANIFEST")
        for name in names:
            if name == "manifest.json":
                continue
            h, size = hashlib.sha256(), 0
            with tar.extractfile(name) as f:
                while chunk := f.read(1048576):
                    h.update(chunk)
                    size += len(chunk)
            require(manifest["files"][name] == {"sha256": h.hexdigest(), "size": size}, "HASH_MISMATCH")
        require({"delta", "delta_sms_bridge"} <= set(manifest["databases"]), "MISSING_DATABASES")
        require(all(re.fullmatch(r"delta(?:_rb[1-9][0-9]*|_sms_bridge)?", n) for n in manifest["databases"]), "INVALID_DB_LIST")
    # Keep only one pending restore. No unbounded plaintext staging via the web worker.
    ready = ROOT / "verified-pending.tar.gz"
    os.replace(plain, ready)
    print("VERIFIED_RESTORE_STAGED=" + ready.name)

def main():
    os.umask(0o077)
    require(os.geteuid() == 0, "ROOT_REQUIRED")
    for p in (ROOT, KEY.parent):
        require(p.is_dir() and not p.is_symlink() and p.stat().st_uid == 0 and not p.stat().st_mode & 0o077, "PRIVATE_DIR_REQUIRED")
    require(KEY.is_file() and not KEY.is_symlink() and KEY.stat().st_uid == 0 and not KEY.stat().st_mode & 0o077, "RECOVERY_KEY_REQUIRED")
    require(len(KEY.read_bytes().strip()) >= 24, "RECOVERY_KEY_TOO_SHORT")
    require(len(sys.argv) == 2 and sys.argv[1] in ("create", "verify"), "INVALID_MODE")
    (ROOT / "gnupg").mkdir(mode=0o700, exist_ok=True)
    with (ROOT / "lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix="work-", dir=ROOT) as d:
            (create if sys.argv[1] == "create" else verify)(pathlib.Path(d))

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        # Only fixed internal reason identifiers are safe to log.
        reason = str(e) if isinstance(e, RuntimeError) and re.fullmatch(r"[A-Z_]+", str(e)) else type(e).__name__
        print("DELTA_FULL_BACKUP_FAILED:" + reason, file=sys.stderr)
        sys.exit(1)
