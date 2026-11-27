#!/usr/bin/env python3
"""Exercise real GPG, archive hashes, staging and invalid input without production data."""
import importlib.util
import io
import json
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
