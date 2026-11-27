#!/usr/bin/env python3
"""Exercise real GPG, archive hashes, staging and invalid input without production data."""
import importlib.util
import io
import json
import os
import subprocess
import pathlib
import tarfile
import tempfile
import unittest
from unittest.mock import patch

p = pathlib.Path(__file__).resolve().parents[1] / "scripts/delta-full-backup.py"
spec = importlib.util.spec_from_file_location("full", p)
full = importlib.util.module_from_spec(spec)
spec.loader.exec_module(full)

class Buffer:
    def __init__(self, data=b""):
        self.buffer = io.BytesIO(data)

class FullBackupTest(unittest.TestCase):
    def test_runtime_rejects_escaping_members_and_links(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            cases = [
                ("../../outside", None),
                ("/etc/passwd", None),
                ("var/www/html/escape", "../../../../../etc/shadow"),
                ("var/www/html/escape", "/etc/shadow"),
            ]
            for i, (name, target) in enumerate(cases):
                archive = root / f"bad-{i}.tar.gz"
                dest = root / f"dest-{i}"
                dest.mkdir()
                with tarfile.open(archive, "w:gz") as tar:
                    info = tarfile.TarInfo(name)
                    if target:
                        info.type = tarfile.SYMTYPE
                        info.linkname = target
                        tar.addfile(info)
                    else:
                        info.size = 4
                        tar.addfile(info, io.BytesIO(b"test"))
                with self.assertRaises(RuntimeError):
                    full.unpack_runtime(archive, dest, ("var/www/html",))
            self.assertFalse((root / "outside").exists())

    def test_runtime_preserves_regular_file_and_relative_link(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            archive = root / "safe.tar.gz"
            dest = root / "dest"
            dest.mkdir()
            with tarfile.open(archive, "w:gz") as tar:
                info = tarfile.TarInfo("etc/apache2/sites-available/bot.conf")
                info.size = 4
                info.mode = 0o640
                tar.addfile(info, io.BytesIO(b"test"))
                link = tarfile.TarInfo("etc/apache2/sites-enabled/bot.conf")
                link.type = tarfile.SYMTYPE
                link.linkname = "../sites-available/bot.conf"
                tar.addfile(link)
            full.unpack_runtime(archive, dest, ("etc/apache2",))
            self.assertEqual((dest / "etc/apache2/sites-enabled/bot.conf").read_bytes(), b"test")

    def test_wrong_approval_digest_stops_before_restore(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "verified-pending.tar.gz").write_bytes(b"archive")
            with patch.object(full, "ROOT", root), patch.object(full, "unpack_outer") as unpack:
                with self.assertRaisesRegex(RuntimeError, "APPROVAL_DIGEST_MISMATCH"):
                    full.apply_verified(root, "0" * 64)
                unpack.assert_not_called()

    def test_target_credentials_mismatch(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            source, target = root / "source.php", root / "target.php"
            fixture = """<?php
$botToken = 'fixture';
$botUrl = 'https://example.org/deltabotvps/';
$admin = 1234;
$dbName = 'delta';
$dbUserName = 'fixtureuser';
$dbPassword = 'fixturepass';
"""
            source.write_text(fixture)
            target.write_text(fixture)
            full.verify_target(source, target)
            target.write_text(fixture.replace("fixturepass", "different"))
            with self.assertRaisesRegex(RuntimeError, "TARGET_INSTALL_CREDENTIALS_MISMATCH"):
                full.verify_target(source, target)

    @unittest.skipUnless(os.environ.get("DELTA_FULL_BACKUP_CI") == "1", "isolated MySQL integration only")
    def test_actual_multi_database_dump_and_restore(self):
        # Never run this destructive fixture against a real server.
        env = dict(os.environ)
        env.update(MYSQL_PWD="ci_test_password", MYSQL_HOST="127.0.0.1", MYSQL_TCP_PORT="3306")
        args = ["mysql", "-h", "127.0.0.1", "-P", "3306", "-uroot", "-NB"]
        def sql(statement):
            return subprocess.run(args, input=statement.encode(), env=env, check=True, stdout=subprocess.PIPE).stdout
        # CI job runs against its own disposable MySQL container.
        sql("""
CREATE DATABASE delta;
CREATE DATABASE delta_rb72;
CREATE DATABASE delta_sms_bridge;
CREATE TABLE delta.reseller_bots(id INT PRIMARY KEY,db_name VARCHAR(80),status INT,is_deleted INT);
INSERT INTO delta.reseller_bots VALUES(71,'delta_rb71',0,1),(72,'delta_rb72',1,0);
CREATE TABLE delta.users(id INT PRIMARY KEY);
INSERT INTO delta.users VALUES(123);
CREATE TABLE delta_rb72.users(id INT PRIMARY KEY);
INSERT INTO delta_rb72.users VALUES(456);
CREATE TABLE delta_sms_bridge.events(id INT PRIMARY KEY);
INSERT INTO delta_sms_bridge.events VALUES(789);
""")
        with tempfile.TemporaryDirectory() as d, patch.object(full, "mysql_access", return_value=(args[:-1], env)):
            stage = pathlib.Path(d)
            sql("UPDATE delta.reseller_bots SET status=1,is_deleted=0 WHERE id=71")
            with self.assertRaisesRegex(RuntimeError, "ACTIVE_RESELLER_DB_MISSING"):
                full.database_snapshot(stage)
            sql("UPDATE delta.reseller_bots SET status=0,is_deleted=1 WHERE id=71")
            self.assertEqual(full.database_snapshot(stage), ["delta", "delta_rb72", "delta_sms_bridge"])
            sql("DELETE FROM delta.users; DELETE FROM delta_rb72.users; DELETE FROM delta_sms_bridge.events; CREATE TABLE delta.extra_after_backup(id INT)")
            with (stage / "databases.sql").open("rb") as dump:
                subprocess.run(args + ["--binary-mode"], stdin=dump, env=env, check=True)
            self.assertEqual(sql("SELECT id FROM delta.users"), b"123\n")
            self.assertEqual(sql("SELECT id FROM delta_rb72.users"), b"456\n")
            self.assertEqual(sql("SELECT id FROM delta_sms_bridge.events"), b"789\n")
            self.assertEqual(sql("SHOW TABLES FROM delta LIKE 'extra_after_backup'"), b"")
            self.assertEqual(sql("SELECT CONCAT(status,':',is_deleted) FROM delta.reseller_bots WHERE id=71"), b"0:1\n")
            # Fresh-server credentials are recreated without granting access
            # to system schemas or modifying the MySQL root account.
            password = "ci-fresh-'\\-password-234"
            sql(full.fresh_grants({"dbUserName": "delta_restore_ci", "dbPassword": password}))
            account_env = dict(env, MYSQL_PWD=password)
            container = os.environ["DELTA_FULL_CI_MYSQL_CONTAINER"]
            self.assertRegex(container, r"^[a-f0-9]{64}$")
            def as_app(statement):
                # The account is deliberately localhost-only. Exercise it inside MySQL's CI container, not from the external runner IP.
                return subprocess.run(["docker", "exec", "-i", "-e", "MYSQL_PWD", container, "mysql", "-udelta_restore_ci", "-NB"],
                                      input=statement.encode(), env=account_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            self.assertEqual(as_app("SELECT id FROM delta.users").stdout, b"123\n")
            self.assertEqual(as_app("SELECT id FROM delta_rb72.users").stdout, b"456\n")
            self.assertEqual(as_app("SELECT id FROM delta_sms_bridge.events").stdout, b"789\n")
            self.assertNotEqual(as_app("SELECT User FROM mysql.user").returncode, 0)
            self.assertEqual(as_app("CREATE DATABASE delta_rb9999").returncode, 0)
            sql("DROP DATABASE delta_rb9999; DROP USER 'delta_restore_ci'@'localhost';")

    def test_round_trip_and_tamper(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            key = root / "key"
            key.write_text("fixture-only-independent-key-12345678\n")
            (root / "gnupg").mkdir(mode=0o700)
            def fixture(stage):
                (stage / "databases.sql").write_text("fixture SQL, not executable")
                return ["delta", "delta_rb72", "delta_sms_bridge"]
            with patch.object(full, "ROOT", root), patch.object(full, "KEY", key), \
                 patch.object(full, "REQUIRED", ()), patch.object(full, "PATHS", ()), \
                 patch.object(full, "database_snapshot", side_effect=fixture), \
                 patch.object(full, "sqlite_snapshot", return_value=[]):
                stage = root / "create"
                stage.mkdir()
                output = Buffer()
                with patch.object(full.sys, "stdout", output):
                    full.create(stage)
                archive = output.buffer.getvalue()
                self.assertTrue(archive.startswith(full.MAGIC))
                self.assertNotIn(b"fixture SQL", archive)
                stage = root / "verify"
                stage.mkdir()
                with patch.object(full.sys, "stdin", Buffer(archive)):
                    full.verify(stage)
                verified = root / "verified-pending.tar.gz"
                with tarfile.open(verified, "r:gz") as tar:
                    m = json.load(tar.extractfile("manifest.json"))
                    self.assertEqual(m["databases"], ["delta", "delta_rb72", "delta_sms_bridge"])
                    self.assertEqual(tar.extractfile("databases.sql").read(), b"fixture SQL, not executable")
                old = verified.read_bytes()
                damaged = bytearray(archive)
                damaged[-20] ^= 1
                stage = root / "bad"
                stage.mkdir()
                with patch.object(full.sys, "stdin", Buffer(bytes(damaged))):
                    with self.assertRaises(RuntimeError):
                        full.verify(stage)
                self.assertEqual(verified.read_bytes(), old)
                stage = root / "wrong-key"
                stage.mkdir()
                key.write_text("another-fixture-only-key-987654321\n")
                with patch.object(full.sys, "stdin", Buffer(archive)):
                    with self.assertRaises(RuntimeError):
                        full.verify(stage)
                self.assertEqual(verified.read_bytes(), old)
                with patch.object(full.sys, "stdin", Buffer(b"not a Delta backup")):
                    with self.assertRaises(RuntimeError):
                        full.verify(stage)

if __name__ == "__main__":
    unittest.main()
