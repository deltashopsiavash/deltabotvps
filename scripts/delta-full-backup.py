#!/usr/bin/env python3
"""Root-only encrypted disaster snapshot; verify/stage never executes archive contents."""
import os, sys, re, json, tarfile, hashlib, subprocess, tempfile, shutil, pathlib, fcntl, sqlite3, posixpath, stat
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
    "etc/apache2", "etc/letsencrypt", "etc/mysql", "etc/php", "etc/phpmyadmin", "etc/cron.d",
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

def mysql_access():
    env = dict(os.environ)
    env.pop("MYSQL_PWD", None)
    args = ["mysql", "-uroot"]
    if subprocess.run(args + ["-e", "SELECT 1"], env=env, stdout=subprocess.DEVNULL,
                      stderr=subprocess.DEVNULL).returncode:
        match = re.search(r"\$pass\s*=\s*'([^']*)'", pathlib.Path("/root/confdelta/dbrootdelta.txt").read_text())
        require(match, "MYSQL_ROOT_UNAVAILABLE")
        env["MYSQL_PWD"] = match.group(1)
    return args, env

def database_snapshot(stage, safety=False):
    args, env = mysql_access()
    def query(sql):
        return run(args + ["-NB", "-e", sql], env=env, stdout=subprocess.PIPE).stdout.decode().splitlines()
    dbs = sorted(n for n in query("SHOW DATABASES") if re.fullmatch(r"delta(?:_rb[1-9][0-9]*|_sms_bridge)?", n))
    require("delta" in dbs and (safety or "delta_sms_bridge" in dbs), "REQUIRED_DATABASE_MISSING")
    for row in query("SELECT id,COALESCE(db_name,'') FROM delta.reseller_bots WHERE status=1 AND is_deleted=0"):
        rid, _, name = row.partition("\t")
        require(re.fullmatch(r"delta_rb[1-9][0-9]*", name) and name in dbs, "ACTIVE_RESELLER_DB_MISSING")
    dump = shutil.which("mysqldump") or shutil.which("mariadb-dump")
    require(dump, "MYSQLDUMP_REQUIRED")
    sql = stage / "databases.sql"
    with sql.open("wb") as f:
        run([dump, *args[1:], "--lock-all-tables", "--quick", "--no-tablespaces", "--hex-blob",
             "--routines", "--events", "--triggers", "--default-character-set=utf8mb4",
             "--add-drop-database", "--databases", *dbs], env=env, stdout=f)
    require(sql.stat().st_size > 1024, "EMPTY_SQL")
    with sql.open("rb") as f:
        f.seek(max(0, sql.stat().st_size - 8192))
        require(b"-- Dump completed on " in f.read(), "INCOMPLETE_SQL")
    return dbs

def sha(p):
    with p.open("rb") as f:
        return hashlib.file_digest(f, "sha256").hexdigest()

def sqlite_snapshot(stage, safety=False):
    found = []
    absent = []
    configured = set()
    for config in (pathlib.Path("/opt/vpsbot/.env"), pathlib.Path("/root/vpsbot/.env")):
        if config.is_file():
            m = re.search(r"^\s*(?:export\s+)?DB_PATH\s*=\s*(.*?)\s*$", config.read_text(), re.M)
            if m:
                path = pathlib.Path(m.group(1).strip("'\""))
                if not path.is_absolute():
                    path = config.parent / path
                require(under(str(path.resolve()).lstrip("/"), ("opt/vpsbot", "root/vpsbot")), "VPSBOT_DB_OUTSIDE_BACKUP_SCOPE")
                require(not path.is_symlink() and not any(parent.is_symlink() for parent in path.parents), "VPSBOT_DB_SYMLINK")
                # A configured but absent SQLite database is not equivalent to an empty backup.
                if safety and not path.exists():
                    # Rollback snapshots record actual absence on a fresh target;
                    # never waive this requirement for a normal complete backup.
                    require(not any(pathlib.Path(str(path) + suffix).exists() for suffix in ("-wal", "-shm", "-journal")), "VPSBOT_DB_SIDECARS_WITHOUT_DATABASE")
                    absent.append(str(path).lstrip("/"))
                    continue
                require(path.is_file(), "VPSBOT_CONFIGURED_DB_MISSING")
                with path.open("rb") as check:
                    require(check.read(16) == b"SQLite format 3\x00", "VPSBOT_DB_INVALID")
                configured.add(path)
    for base in (pathlib.Path("/opt/vpsbot"), pathlib.Path("/root/vpsbot")):
        if not base.is_dir():
            continue
        for p in base.rglob("*"):
            if any(s in SKIP for s in p.relative_to(base).parts) or p.is_symlink() or not p.is_file():
                continue
            if p not in configured and p.suffix.lower() not in (".sqlite", ".sqlite3", ".db"):
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
    require({str(x).lstrip("/") for x in configured} <= set(found), "CONFIGURED_SQLITE_NOT_CAPTURED")
    if safety:
        return sorted(found), sorted(absent)
    return sorted(found)

def runtime_filter(info):
    if any(x in SKIP for x in pathlib.PurePosixPath(info.name).parts) or info.name.endswith((".pyc", "-wal", "-shm", ".sock")):
        return None
    return None if info.isdev() or info.isfifo() else info

def gpg():
    return ["gpg", "--batch", "--yes", "--no-tty", "--pinentry-mode", "loopback",
            "--no-symkey-cache", "--passphrase-file", str(KEY), "--homedir", str(ROOT / "gnupg")]

def create(stage, safety=False):
    for p in REQUIRED:
        require(pathlib.Path("/" + p).exists(), "REQUIRED_COMPONENT_MISSING")
    dbs = database_snapshot(stage, safety=True) if safety else database_snapshot(stage)
    sqlite, absent = sqlite_snapshot(stage, safety=True) if safety else (sqlite_snapshot(stage), [])
    paths = [p for p in PATHS if pathlib.Path("/" + p).exists()]
    with tarfile.open(stage / "runtime.tar.gz", "w:gz", dereference=False) as tar:
        # Preserve symlinks without following them.
        for p in paths:
            tar.add("/" + p, arcname=p, filter=lambda info: None if info.name in sqlite else runtime_filter(info))
    # Verify the runtime can actually be extracted before sending an archive.
    rehearsal_dir = stage / "runtime-check"
    rehearsal_dir.mkdir()
    unpack_runtime(stage / "runtime.tar.gz", rehearsal_dir, paths)
    for rel in REQUIRED:
        require((rehearsal_dir / rel).exists(), "RESTORE_COMPONENT_MISSING")
    shutil.rmtree(rehearsal_dir)
    with (stage / "packages.txt").open("wb") as f:
        run(["dpkg-query", "-W"], stdout=f)
    names = ["databases.sql", "runtime.tar.gz", "packages.txt"]
    if sqlite:
        with tarfile.open(stage / "sqlite.tar.gz", "w:gz") as tar:
            tar.add(stage / "sqlite", arcname="sqlite")
        names.append("sqlite.tar.gz")
    manifest = {"format": 1, "created_utc": datetime.now(timezone.utc).isoformat(),
                "databases": dbs, "paths": paths, "sqlite": sqlite, "safety": safety,
                "sqlite_absent": absent,
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

def verify(stage, limit=LIMIT, stream=None):
    if stream is None:
        stream = sys.stdin.buffer
    require(stream.read(len(MAGIC)) == MAGIC, "INVALID_HEADER")
    enc = stage / "input.gpg"
    with enc.open("wb") as f:
        size = 0
        while chunk := stream.read(1048576):
            size += len(chunk)
            require(size <= limit, "ARCHIVE_TOO_LARGE")
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
            proc.stdout.close()
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
        require("delta" in manifest["databases"] and (manifest.get("safety") is True or "delta_sms_bridge" in manifest["databases"]), "MISSING_DATABASES")
        require(all(re.fullmatch(r"delta(?:_rb[1-9][0-9]*|_sms_bridge)?", n) for n in manifest["databases"]), "INVALID_DB_LIST")
    # Keep only one pending restore. No unbounded plaintext staging via the web worker.
    ready = ROOT / "verified-pending.tar.gz"
    os.replace(plain, ready)
    digest = sha(ready)
    print("VERIFIED_RESTORE_STAGED=" + digest)
    return digest

# Restore only explicit paths from the authenticated backup. Never extract an
# uploaded archive directly onto /. All links are installed after regular files.
# MySQL server configuration and root credentials remain those of the target.
PRESERVE_TARGET = {"etc/mysql", "root/confdelta", "etc/phpmyadmin"}
SERVICE_NAMES = ("apache2", "cron", "vpsbridge", "vpsbot")
def under(name, roots):
    return any(name == p or name.startswith(p + "/") for p in roots)

def safe_name(name):
    return bool(name) and not name.startswith("/") and all(
        part not in ("", ".", "..") for part in name.split("/"))

def unpack_runtime(archive, dest, roots):
    roots = tuple(roots)
    require(all(safe_name(x) and x in PATHS for x in roots), "INVALID_RUNTIME_ROOT")
    seen = set()
    links = []
    directories = []
    total = 0
    with tarfile.open(archive, "r:gz") as tar:
        for m in tar:
            name = m.name.rstrip("/")
            require(safe_name(name) and under(name, roots) and name not in seen, "UNSAFE_RUNTIME_MEMBER")
            require(not any(x in SKIP for x in name.split("/")), "EXCLUDED_RUNTIME_MEMBER")
            require(m.isdir() or m.isfile() or m.issym() or m.islnk(), "UNSUPPORTED_RUNTIME_MEMBER")
            seen.add(name)
            total += m.size
            require(total <= PLAIN_LIMIT and len(seen) <= 200000, "RUNTIME_LIMIT")
            out = dest / name
            require(not any(p.is_symlink() for p in out.parents if p != dest.parent), "LINK_PARENT")
            if m.issym():
                target = m.linkname
                normalized = posixpath.normpath(target.lstrip("/") if target.startswith("/") else posixpath.join(posixpath.dirname(name), target))
                package_links = ("usr/share/phpmyadmin", "usr/share/javascript", "etc/ssl/certs", "etc/alternatives")
                require(safe_name(normalized) and (under(normalized, roots) or (target.startswith("/") and under(normalized, package_links))), "UNSAFE_RUNTIME_LINK")
                links.append((out, m))
            elif m.islnk():
                target = m.linkname
                require(safe_name(target) and under(target, roots) and target in seen and target != name, "UNSAFE_HARDLINK")
                src = dest / target
                require(src.is_file() and not src.is_symlink() and not any(p.is_symlink() for p in src.parents), "INVALID_HARDLINK_TARGET")
                total += src.stat().st_size
                require(total <= PLAIN_LIMIT, "RUNTIME_LIMIT")
                out.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(src, out)
                if os.geteuid() == 0:
                    os.chown(out, m.uid, m.gid)
                os.chmod(out, m.mode & 0o777)
            elif m.isdir():
                out.mkdir(parents=True, exist_ok=True)
                directories.append((out, m))
            else:
                out.parent.mkdir(parents=True, exist_ok=True)
                with tar.extractfile(m) as src, out.open("xb") as dst:
                    shutil.copyfileobj(src, dst)
                if os.geteuid() == 0:
                    os.chown(out, m.uid, m.gid)
                os.chmod(out, m.mode & 0o777)
        for out, m in links:
            require(not out.exists() and not out.is_symlink(), "LINK_COLLISION")
            require(not any(p.is_symlink() for p in out.parents if p != dest.parent), "LINK_PARENT")
            out.parent.mkdir(parents=True, exist_ok=True)
            out.symlink_to(m.linkname)
            if os.geteuid() == 0:
                os.lchown(out, m.uid, m.gid)
        for out, m in reversed(directories):
            if os.geteuid() == 0:
                os.chown(out, m.uid, m.gid)
            os.chmod(out, m.mode & 0o7777)

def unpack_outer(verified, work):
    with tarfile.open(verified, "r:gz") as tar:
        manifest = json.load(tar.extractfile("manifest.json"))
        for name in manifest["files"]:
            with tar.extractfile(name) as src, (work / name).open("xb") as dst:
                shutil.copyfileobj(src, dst)
            require(sha(work / name) == manifest["files"][name]["sha256"], "STAGED_HASH_MISMATCH")
    return manifest

def base_values(path):
    # Parse the installer-generated literal values without executing PHP as root.
    content = path.read_text()
    values = {}
    for name in ("botToken", "botUrl", "admin", "dbName", "dbUserName", "dbPassword"):
        if name == "admin":
            m = re.search(r"\$admin\s*=\s*['\"]?([0-9]+)['\"]?\s*;", content)
        else:
            m = re.search(r"\$" + name + r"\s*=\s*(['\"])([^'\"\r\n]*)\1\s*;", content)
        require(m is not None, "UNSUPPORTED_BASEINFO")
        values[name] = m.group(1 if name == "admin" else 2)
    return values

def verify_target(source, target):
    a, b = base_values(source), base_values(target)
    require(a["dbName"] == "delta" and b["dbName"] == "delta", "MOTHER_DB_MISMATCH")
    require(all(a[k] == b[k] for k in ("botToken", "botUrl", "admin", "dbUserName", "dbPassword")),
            "TARGET_INSTALL_CREDENTIALS_MISMATCH")

def restore_sqlite(archive, dest, expected):
    with tarfile.open(archive, "r:gz") as tar:
        found = set()
        for m in tar:
            if m.isdir():
                continue
            rel = m.name.removeprefix("sqlite/")
            require(m.isfile() and m.name.startswith("sqlite/") and safe_name(rel) and
                    under(rel, ("opt/vpsbot", "root/vpsbot")) and rel in expected and rel not in found,
                    "INVALID_SQLITE_ARCHIVE")
            found.add(rel)
            target = dest / rel
            require(not any(p.is_symlink() for p in target.parents) and not target.is_symlink(), "SQLITE_LINK")
            target.parent.mkdir(parents=True, exist_ok=True)
            with tar.extractfile(m) as src, target.open("xb") as dst:
                shutil.copyfileobj(src, dst)
            with sqlite3.connect(target.as_uri() + "?mode=ro", uri=True) as db:
                require(db.execute("PRAGMA quick_check").fetchone()[0] == "ok", "SQLITE_INVALID")
            os.chmod(target, 0o600)
        require(found == set(expected), "SQLITE_SET_MISMATCH")

def active_services():
    return [s for s in SERVICE_NAMES if subprocess.run(
        ["systemctl", "is-active", "--quiet", s], stderr=subprocess.DEVNULL).returncode == 0]

def restore_path(src, target):
    require(not any(p.is_symlink() for p in target.parents) and not target.is_symlink(), "UNSAFE_TARGET_PATH")
    target.parent.mkdir(parents=True, exist_ok=True)
    if src.is_dir():
        require(not src.is_symlink(), "UNSAFE_SOURCE_PATH")
        target.mkdir(exist_ok=True)
        # Keep rebuilt Python environments and git metadata on the target.
        run(["rsync", "-a", "--delete", "--exclude=venv/", "--exclude=.venv/",
             "--exclude=.git/", "--exclude=backups/", str(src) + "/", str(target) + "/"])
    else:
        require(src.is_file() and not src.is_symlink(), "UNSAFE_SOURCE_PATH")
        fd, name = tempfile.mkstemp(prefix=".delta-restore-", dir=target.parent)
        os.close(fd)
        try:
            shutil.copy2(src, name)
            meta = src.stat()
            os.chown(name, meta.st_uid, meta.st_gid)
            os.replace(name, target)
        finally:
            pathlib.Path(name).unlink(missing_ok=True)

def apply_verified(work, expected_sha, preserve_ui=False):
    # This command is never in the www-data sudo rule. The digest binds the
    # owner's independent approval to exactly the staged archive.
    verified = ROOT / "verified-pending.tar.gz"
    require(verified.is_file() and not verified.is_symlink() and sha(verified) == expected_sha,
            "APPROVAL_DIGEST_MISMATCH")
    manifest = unpack_outer(verified, work)
    require(manifest.get("safety") is not True, "FULL_ARCHIVE_REQUIRED")
    runtime = work / "runtime"
    runtime.mkdir()
    unpack_runtime(work / "runtime.tar.gz", runtime, manifest["paths"])
    if manifest["sqlite"]:
        restore_sqlite(work / "sqlite.tar.gz", runtime, manifest["sqlite"])
    for rel in REQUIRED:
        require((runtime / rel).exists(), "RESTORE_COMPONENT_MISSING")
    target_base = pathlib.Path("/var/www/html/deltabotvps/baseInfo.php")
    verify_target(runtime / "var/www/html/deltabotvps/baseInfo.php", target_base)
    if preserve_ui:
        # Stage only the root-installed, CI-gated UI copies; never a web-supplied path.
        ui = KEY.parent / "ui"
        require(ui.is_dir() and not ui.is_symlink() and ui.stat().st_uid == 0 and not ui.stat().st_mode & 0o077, "TRUSTED_RESTORE_UI_REQUIRED")
        import grp
        for name in ("bot.php", "backup_worker.php"):
            source = ui / name
            dest = runtime / "var/www/html/deltabotvps" / name
            require(source.is_file() and not source.is_symlink() and source.stat().st_uid == 0 and not source.stat().st_mode & 0o077, "TRUSTED_RESTORE_UI_REQUIRED")
            require(not dest.is_symlink() and not any(parent.is_symlink() for parent in dest.parents), "UNSAFE_RESTORE_UI_DESTINATION")
            require(not dest.exists() or (dest.is_file() and dest.stat().st_nlink == 1), "UNSAFE_RESTORE_UI_DESTINATION")
            dest.unlink(missing_ok=True)
            shutil.copyfile(source, dest)
            os.chown(dest, 0, grp.getgrnam("www-data").gr_gid)
            os.chmod(dest, 0o644)
    require(pathlib.Path("/opt/vpsbot/venv/bin/python").exists(), "TARGET_VPSBOT_ENV_REQUIRED")
    for tool in ("rsync", "mysql", "apache2ctl", "systemctl"):
        require(shutil.which(tool), "TARGET_TOOL_MISSING")
    # Compare installed dependency requirements before replacing source code.
    oldreq, newreq = pathlib.Path("/opt/vpsbot/requirements.txt"), runtime / "opt/vpsbot/requirements.txt"
    require(oldreq.is_file() and newreq.is_file() and sha(oldreq) == sha(newreq), "TARGET_VPSBOT_DEPENDENCIES_MISMATCH")
    args, env = mysql_access()
    running = active_services()
    # Stop writers before creating rollback archive and replacing data.
    # Keep MySQL running. If anything fails, leave writers stopped, and retain
    # the safety backup for root recovery rather than claim a successful restore.
    print("RESTORE_STOPPING_WRITERS", flush=True)
    stopped = []
    safety = ROOT / ("before-restore-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S") + "-" + os.urandom(4).hex() + ".delta.gpg")
    changed = False
    try:
        for svc in running:
            run(["systemctl", "stop", svc])
            stopped.append(svc)
        with safety.open("xb") as out:
            run([sys.executable, __file__, "create-safety"], stdout=out)
        require(safety.stat().st_size > len(MAGIC), "SAFETY_ARCHIVE_FAILED")
        print("SAFETY_BACKUP=" + str(safety), flush=True)
        changed = True
        with (work / "databases.sql").open("rb") as sql:
            run(args + ["--binary-mode", "--default-character-set=utf8mb4"], env=env, stdin=sql, stdout=subprocess.DEVNULL)
        # Existing MySQL root config and root password must remain target-local.
        for rel in manifest["paths"]:
            if rel not in PRESERVE_TARGET:
                restore_path(runtime / rel, pathlib.Path("/") / rel)
        run(["systemctl", "daemon-reload"])
        run(["apache2ctl", "configtest"], stdout=subprocess.DEVNULL)
        # Verify app DB connectivity as the web user, not root; never expose secrets.
        check_restored_db()
        for svc in running:
            run(["systemctl", "start", svc])
        verified.unlink()
        print("DELTA_FULL_RESTORE_COMPLETE_CHECK_EXTERNAL_SERVICES", flush=True)
    except Exception:
        if not changed:
            safety.unlink(missing_ok=True)
            for svc in stopped:
                subprocess.run(["systemctl", "start", svc], stderr=subprocess.DEVNULL)
            print("RESTORE_STOPPED_BEFORE_REPLACEMENT", file=sys.stderr)
        else:
            for svc in running:
                subprocess.run(["systemctl", "stop", svc], stderr=subprocess.DEVNULL)
            print("RESTORE_FAILED_WRITERS_STOPPED; SAFETY_BACKUP=" + str(safety), file=sys.stderr)
        raise

def prepare_install(work, expected_sha):
    # Before the original bot is installed, recover the exact private install
    # settings from the verified archive. No Telegram or MySQL changes here.
    verified = ROOT / "verified-pending.tar.gz"
    require(verified.is_file() and not verified.is_symlink() and sha(verified) == expected_sha,
            "APPROVAL_DIGEST_MISMATCH")
    manifest = unpack_outer(verified, work)
    runtime = work / "runtime"
    runtime.mkdir()
    unpack_runtime(work / "runtime.tar.gz", runtime, manifest["paths"])
    private = pathlib.Path(tempfile.mkdtemp(prefix="delta-install-credentials-", dir="/root"))
    for rel, name in (
        ("var/www/html/deltabotvps/baseInfo.php", "baseInfo.php"),
        ("opt/vpsbot/.env", "vpsbot.env"),
        ("etc/delta-sms-bridge.php", "sms-bridge.php"),
        ("root/delta-sms-bridge-pairing.txt", "sms-pairing.txt"),
    ):
        src = runtime / rel
        if not src.exists():
            continue
        require(src.is_file() and not src.is_symlink() and not any(p.is_symlink() for p in src.parents), "INVALID_CREDENTIAL_FILE")
        target = private / name
        shutil.copyfile(src, target)
        os.chmod(target, 0o600)
    require((private / "baseInfo.php").is_file() and (private / "vpsbot.env").is_file(), "INSTALL_SETTINGS_MISSING")
    base_values(private / "baseInfo.php")
    print("PRIVATE_INSTALL_CREDENTIALS=" + str(private))
    print("Use the original domain, bot token, admin ID and DB username/password. Never paste these files into chat.")

def rehearsal(work, expected_sha):
    verified = ROOT / "verified-pending.tar.gz"
    require(verified.is_file() and not verified.is_symlink() and sha(verified) == expected_sha,
            "APPROVAL_DIGEST_MISMATCH")
    manifest = unpack_outer(verified, work)
    runtime = work / "runtime"
    runtime.mkdir()
    unpack_runtime(work / "runtime.tar.gz", runtime, manifest["paths"])
    if manifest["sqlite"]:
        restore_sqlite(work / "sqlite.tar.gz", runtime, manifest["sqlite"])
    for rel in REQUIRED:
        require((runtime / rel).exists(), "RESTORE_COMPONENT_MISSING")
    values = base_values(runtime / "var/www/html/deltabotvps/baseInfo.php")
    require(values["dbName"] == "delta", "MOTHER_DB_MISMATCH")
    print("ARCHIVE_DATABASES=" + ",".join(manifest["databases"]))
    print("ARCHIVE_RUNTIME_PATHS=" + str(len(manifest["paths"])))
    print("ARCHIVE_SQLITE_COUNT=" + str(len(manifest["sqlite"])))
    print("DELTA_FULL_ARCHIVE_REHEARSAL_OK")
    print("No live SQL, service, webhook or configuration was changed.")

def fresh_grants(values):
    username = values["dbUserName"]
    require(re.fullmatch(r"[A-Za-z0-9_]{1,32}", username) and username.lower() not in ("root","mysql","mysql_session","mysql_innodb_cluster_metadata","debian-sys-maint","phpmyadmin"), "UNSAFE_DB_ACCOUNT")
    password = values["dbPassword"].replace("'", "''")
    require(bool(password) and "\x00" not in password, "EMPTY_DB_PASSWORD")
    account = "'" + username + "'@'localhost'"
    sql = "SET SESSION sql_mode='NO_BACKSLASH_ESCAPES';\nCREATE USER " + account + " IDENTIFIED BY '" + password + "';\n"
    sql += "GRANT ALL PRIVILEGES ON \x60delta\x60.* TO " + account + ";\n"
    sql += "GRANT ALL PRIVILEGES ON \x60delta\\_rb%\x60.* TO " + account + ";\n"
    sql += "GRANT ALL PRIVILEGES ON \x60delta\\_sms\\_bridge\x60.* TO " + account + ";\n"
    return sql

def fresh_restore(work, expected_sha):
    # Dedicated blank Ubuntu target only. No registration of real webhooks,
    # no outbound Telegram calls, no start of bot writers until owner activation.
    verified = ROOT / "verified-pending.tar.gz"
    require(verified.is_file() and not verified.is_symlink() and sha(verified) == expected_sha,
            "APPROVAL_DIGEST_MISMATCH")
    require(not pathlib.Path("/var/www/html/deltabotvps/baseInfo.php").exists(), "TARGET_NOT_FRESH")
    require(not pathlib.Path("/opt/vpsbot").exists() and not pathlib.Path("/opt/delta-sms-bridge").exists(), "TARGET_NOT_FRESH")
    release = pathlib.Path("/etc/os-release").read_text()
    require(any(line.strip() in ('VERSION_ID="24.04"', 'VERSION_ID=24.04') for line in release.splitlines()), "UBUNTU_24_04_REQUIRED")
    manifest = unpack_outer(verified, work)
    require(manifest.get("safety") is not True, "FULL_ARCHIVE_REQUIRED")
    runtime = work / "runtime"
    runtime.mkdir()
    unpack_runtime(work / "runtime.tar.gz", runtime, manifest["paths"])
    if manifest["sqlite"]:
        restore_sqlite(work / "sqlite.tar.gz", runtime, manifest["sqlite"])
    for rel in REQUIRED:
        require((runtime / rel).exists(), "RESTORE_COMPONENT_MISSING")
    values = base_values(runtime / "var/www/html/deltabotvps/baseInfo.php")
    require(values["dbName"] == "delta", "MOTHER_DB_MISMATCH")
    grants = fresh_grants(values)
    for cmd in ("mysql", "rsync", "apache2ctl", "systemctl", "python3", "runuser", "php"):
        require(shutil.which(cmd), "TARGET_TOOL_MISSING")
    args, env = mysql_access()
    def query(sql):
        return run(args + ["-NB"], env=env, input=sql.encode(), stdout=subprocess.PIPE).stdout.decode().splitlines()
    require(not any(re.fullmatch(r"delta(?:_rb[1-9][0-9]*|_sms_bridge)?", n) for n in query("SHOW DATABASES;")), "TARGET_DATABASES_ALREADY_EXIST")
    require(not query("SELECT User FROM mysql.user WHERE User='" + values["dbUserName"] + "';"), "TARGET_DB_ACCOUNT_EXISTS")
    for svc in (*SERVICE_NAMES, "certbot.timer"):
        subprocess.run(["systemctl", "disable", "--now", svc], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    require(not active_services(), "FRESH_WRITERS_STILL_ACTIVE")
    run(args + ["-e", "SET GLOBAL event_scheduler=OFF"], env=env, stdout=subprocess.DEVNULL)
    print("FRESH_RESTORE_WRITERS_STOPPED", flush=True)
    with (work / "databases.sql").open("rb") as sql:
        run(args + ["--binary-mode", "--default-character-set=utf8mb4"], env=env, stdin=sql, stdout=subprocess.DEVNULL)
    run(args, env=env, input=grants.encode(), stdout=subprocess.DEVNULL)
    for rel in manifest["paths"]:
        if rel not in PRESERVE_TARGET:
            restore_path(runtime / rel, pathlib.Path("/") / rel)
    backup_dir = pathlib.Path("/var/lib/deltabotvps/backups")
    require(not backup_dir.is_symlink() and not any(p.is_symlink() for p in backup_dir.parents), "UNSAFE_BACKUP_DIRECTORY")
    backup_dir.parent.mkdir(mode=0o750, exist_ok=True)
    shutil.chown(backup_dir.parent, user="root", group="www-data")
    os.chmod(backup_dir.parent, 0o750)
    backup_dir.mkdir(mode=0o700, exist_ok=True)
    shutil.chown(backup_dir, user="www-data", group="www-data")
    os.chmod(backup_dir, 0o700)
    vps = pathlib.Path("/opt/vpsbot")
    requirements = vps / "requirements.txt"
    require(requirements.is_file() and not requirements.is_symlink(), "VPSBOT_REQUIREMENTS_MISSING")
    run(["python3", "-m", "venv", str(vps / "venv")], stdout=subprocess.DEVNULL)
    run([str(vps / "venv/bin/python"), "-m", "pip", "install", "--disable-pip-version-check", "-r", str(requirements)], stdout=subprocess.DEVNULL)
    run(["systemctl", "daemon-reload"])
    run(["apache2ctl", "configtest"], stdout=subprocess.DEVNULL)
    check_restored_db()
    print("DELTA_FRESH_RESTORE_OFFLINE_OK", flush=True)
    print("Writers remain stopped. Verify domain/DNS, PHP, panel and SMS before activation.", flush=True)

def check_restored_db():
    php = "require '/var/www/html/deltabotvps/baseInfo.php'; mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT); $c=new mysqli('localhost',$dbUserName,$dbPassword,$dbName); require '/var/www/html/deltabotvps/delta_app_core.php'; daVerifyRecoverySecrets($c); $q=$c->query('SELECT db_name FROM reseller_bots WHERE status=1 AND is_deleted=0'); while($r=$q->fetch_assoc()){ $x=new mysqli('localhost',$dbUserName,$dbPassword,$r['db_name']); $x->close(); }"
    run(["runuser", "-u", "www-data", "--", "php", "-r", php], stdout=subprocess.DEVNULL)

# One-time restore: root explicitly approves the exact encrypted file and
# authorizes a short window. The web worker cannot select arbitrary commands,
# supply a plaintext digest, change the approval, or run apply directly.
def arm_restore(expected):
    import time
    require(not (ROOT / "queued-restore.gpg").exists(), "RESTORE_ALREADY_QUEUED")
    approval = ROOT / "restore-approval.json"
    require(not approval.exists() or json.loads(approval.read_text())["expires"] < time.time(),
            "RESTORE_ALREADY_ARMED")
    tmp = ROOT / "restore-approval.new"
    tmp.write_text(json.dumps({"sha256": expected, "expires": int(time.time()) + 1800}))
    os.replace(tmp, approval)
    print("DELTA_RESTORE_ARMED_FOR_30_MINUTES")

def approved_restore():
    import time
    approval = ROOT / "restore-approval.json"
    require(approval.is_file() and not approval.is_symlink(), "RESTORE_NOT_ARMED")
    data = json.loads(approval.read_text())
    require(data["expires"] >= time.time(), "RESTORE_APPROVAL_EXPIRED")
    return data

def queue_restore_password(stage, stream):
    import hmac
    approval = approved_restore()
    attempts = int(approval.get("password_attempts", 0))
    require(attempts < 5, "RESTORE_PASSWORD_ATTEMPTS_EXHAUSTED")
    raw = stream.readline(1025)
    require(raw.endswith(b"\n") and len(raw) <= 1024, "INVALID_RECOVERY_PASSWORD")
    secret = raw[:-1]
    if not hmac.compare_digest(secret, KEY.read_bytes().rstrip(b"\n")):
        approval["password_attempts"] = attempts + 1
        tmp = ROOT / "restore-approval.new"
        tmp.write_text(json.dumps(approval))
        os.replace(tmp, ROOT / "restore-approval.json")
        raise RuntimeError("INVALID_RECOVERY_PASSWORD")
    queue_restore(stage, stream)

def queue_restore(stage, stream):
    approval = approved_restore()
    queued = ROOT / "queued-restore.gpg"
    require(not queued.exists(), "RESTORE_ALREADY_QUEUED")
    candidate = stage / "candidate.gpg"
    with candidate.open("xb") as out:
        count = 0
        while chunk := stream.read(1048576):
            count += len(chunk)
            require(count <= LIMIT, "ARCHIVE_TOO_LARGE")
            out.write(chunk)
    require(sha(candidate) == approval["sha256"], "APPROVED_FILE_HASH_MISMATCH")
    with candidate.open("rb") as f:
        digest = verify(stage, LIMIT, f)
    # Exercise the actual archive and target identity before any replacement.
    check = stage / "rehearsal"
    check.mkdir()
    rehearsal(check, digest)
    verify_target(check / "runtime/var/www/html/deltabotvps/baseInfo.php",
                  pathlib.Path("/var/www/html/deltabotvps/baseInfo.php"))
    os.replace(candidate, queued)
    try:
        run(["systemd-run", "--unit=delta-full-restore-once", "--on-active=8s",
             "--property=Type=oneshot", "--property=TimeoutStartSec=1800",
             "/usr/local/sbin/delta-full-backup", "auto-apply"], stdout=subprocess.DEVNULL)
    except Exception:
        queued.unlink(missing_ok=True)
        raise
    print("DELTA_RESTORE_QUEUED")

def restore_notification(values, message):
    # Only the locally configured owner's private chat; never include secrets.
    import urllib.request, urllib.parse
    try:
        require(re.fullmatch(r"[0-9]{5,16}:[A-Za-z0-9_-]{35}", values["botToken"]), "INVALID_NOTIFICATION_TOKEN")
        url = "https://api.telegram.org/bot" + values["botToken"] + "/sendMessage"
        data = urllib.parse.urlencode({"chat_id": values["admin"], "text": message}).encode()
        with urllib.request.urlopen(url, data=data, timeout=15) as response:
            json.load(response)
    except Exception:
        print("RESTORE_NOTIFICATION_FAILED", file=sys.stderr)

def auto_apply(stage):
    values = base_values(pathlib.Path("/var/www/html/deltabotvps/baseInfo.php"))
    queued = ROOT / "queued-restore.gpg"
    approval = ROOT / "restore-approval.json"
    try:
        data = approved_restore()
        require(queued.is_file() and not queued.is_symlink() and sha(queued) == data["sha256"],
                "APPROVED_FILE_HASH_MISMATCH")
        # Consume authorization before attempting a destructive action. No retry
        # without a new independent root approval.
        approval.unlink()
        with queued.open("rb") as f:
            digest = verify(stage, LIMIT, f)
        work = stage / "apply"
        work.mkdir()
        apply_verified(work, digest, preserve_ui=True)
        restore_notification(values, "✅ اطلاعات بکاپ کامل بازگردانی شد. اکنون سلامت ربات، نمایندگی و سرویس‌های خارجی را بررسی کنید.")
    except Exception as e:
        reason = str(e) if isinstance(e, RuntimeError) and re.fullmatch(r"[A-Z_]+", str(e)) else type(e).__name__
        restore_notification(values, "❌ بازیابی کامل متوقف شد. کد بررسی: " + reason + "\nاگر جایگزینی آغاز شده باشد سرویس‌ها برای جلوگیری از خرابی بیشتر متوقف می‌مانند.")
        raise
    finally:
        queued.unlink(missing_ok=True)
        approval.unlink(missing_ok=True)

def main():
    os.umask(0o077)
    require(os.geteuid() == 0, "ROOT_REQUIRED")
    for p in (ROOT, KEY.parent):
        require(p.is_dir() and not p.is_symlink() and p.stat().st_uid == 0 and not p.stat().st_mode & 0o077, "PRIVATE_DIR_REQUIRED")
    require(KEY.is_file() and not KEY.is_symlink() and KEY.stat().st_uid == 0 and not KEY.stat().st_mode & 0o077, "RECOVERY_KEY_REQUIRED")
    require(len(KEY.read_bytes().strip()) >= 24, "RECOVERY_KEY_TOO_SHORT")
    mode = sys.argv[1] if len(sys.argv) > 1 else ""
    require((len(sys.argv) == 2 and mode in ("create", "verify", "verify-local", "create-safety", "queue-restore", "queue-restore-password", "auto-apply")) or
            (len(sys.argv) == 3 and mode in ("apply", "prepare-install", "rehearse", "fresh-apply", "arm-restore") and re.fullmatch(r"[a-f0-9]{64}",sys.argv[2])), "INVALID_MODE")
    (ROOT / "gnupg").mkdir(mode=0o700, exist_ok=True)
    # create-safety runs only as root while its parent holds the lock.
    if mode == "create-safety":
        with tempfile.TemporaryDirectory(prefix="safety-", dir=ROOT) as d:
            create(pathlib.Path(d), safety=True)
        return
    with (ROOT / "lock").open("a") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX if mode == "auto-apply" else fcntl.LOCK_EX | fcntl.LOCK_NB)
        with tempfile.TemporaryDirectory(prefix="work-", dir=ROOT) as d:
            stage = pathlib.Path(d)
            if mode == "arm-restore":
                arm_restore(sys.argv[2])
            elif mode == "queue-restore":
                queue_restore(stage, sys.stdin.buffer)
            elif mode == "queue-restore-password":
                queue_restore_password(stage, sys.stdin.buffer)
            elif mode == "auto-apply":
                auto_apply(stage)
            elif mode == "apply":
                apply_verified(stage, sys.argv[2])
            elif mode == "prepare-install":
                prepare_install(stage, sys.argv[2])
            elif mode == "rehearse":
                rehearsal(stage, sys.argv[2])
            elif mode == "fresh-apply":
                fresh_restore(stage, sys.argv[2])
            elif mode == "create":
                create(stage)
            else:
                verify(stage, PLAIN_LIMIT if mode == "verify-local" else LIMIT)

if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        # Only fixed internal reason identifiers are safe to log.
        reason = str(e) if isinstance(e, RuntimeError) and re.fullmatch(r"[A-Z_]+", str(e)) else type(e).__name__
        print("DELTA_FULL_BACKUP_FAILED:" + reason, file=sys.stderr)
        sys.exit(1)
