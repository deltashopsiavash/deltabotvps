#!/usr/bin/env python3
"""Create a NPV Tunnel v6 subscription envelope for one P-256 recipient.

This implements the compact subscription format observed in the current app.
Only the subscription URL is encrypted; the remote subscription is never fetched.
Requires the ``cryptography`` Python package.
"""

import argparse
import base64
import binascii
import hashlib
import json
import os
import struct
import sys
from datetime import datetime, timezone
from urllib.parse import urlsplit

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, utils
from cryptography.hazmat.primitives.ciphers.aead import AESGCM, ChaCha20Poly1305
from cryptography.hazmat.primitives.kdf.hkdf import HKDF


# NPV v6 uses an app-key-derived pad to bind the recipient wrap to the
# configuration. The 16-byte binding salt is fresh for every envelope; only
# the app-side A16 value is format-specific.
_BINDING_A16 = bytes.fromhex("eceed23c974b1edf890ca722ebbece17")
_FIELD_KEY_INFO = b"NPV-fields-v1/field/"
_FIELD_AAD_INFO = b"NPV-fields-v1/record/"


def _hkdf(key: bytes, salt: bytes, info: bytes) -> bytes:
    return HKDF(algorithm=hashes.SHA256(), length=32, salt=salt, info=info).derive(key)


def _compressed(public_key) -> bytes:
    return public_key.public_bytes(serialization.Encoding.X962, serialization.PublicFormat.CompressedPoint)


def _recipient(value: str):
    try:
        encoded = value.strip()
        if len(encoded) == 66:
            raw = bytes.fromhex(encoded)
        else:
            raw = base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4))
        if len(raw) != 33 or raw[0] not in (2, 3):
            raise ValueError("expected a 33-byte compressed P-256 public key")
        return ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), raw), raw
    except (ValueError, TypeError, binascii.Error) as exc:
        raise ValueError("invalid NPV Public Key") from exc


def _load_or_create_creator(path: str):
    """Keep one creator identity across exports, matching NPV Tunnel behavior."""
    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        key = serialization.load_pem_private_key(target.read_bytes(), password=None)
        if not isinstance(key, ec.EllipticCurvePrivateKey) or not isinstance(key.curve, ec.SECP256R1):
            raise ValueError("creator key is not a P-256 private key")
        return key

    key = ec.generate_private_key(ec.SECP256R1())
    pem = key.private_bytes(
        serialization.Encoding.PEM,
        serialization.PrivateFormat.PKCS8,
        serialization.NoEncryption(),
    )
    fd = os.open(str(target), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        os.write(fd, pem)
    finally:
        os.close(fd)
    return key


def _field(seq: int, plaintext: bytes, dek: bytes, content_id: bytes) -> bytes:
    number = struct.pack(">H", seq)
    key = _hkdf(dek, content_id, _FIELD_KEY_INFO + number)
    aad = _FIELD_AAD_INFO + content_id + number + struct.pack(">I", len(plaintext))
    sealed = ChaCha20Poly1305(key).encrypt(bytes(12), plaintext, aad)
    return number + b"\0\0" + struct.pack(">H", len(sealed)) + sealed


def _json(value) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def create_subscription(url: str, public_key: str, name: str, creator_key_path: str) -> bytes:
    parsed = urlsplit(url)
    if parsed.scheme not in ("http", "https") or not parsed.hostname:
        raise ValueError("subscription must be an HTTP(S) URL")
    name = name.strip() or parsed.hostname
    if len(name) > 128:
        raise ValueError("subscription name is too long")
    recipient, recipient_point = _recipient(public_key)
    creator = _load_or_create_creator(creator_key_path)
    config_id, dek, content_id, body_nonce = (os.urandom(n) for n in (16, 32, 32, 12))
    fingerprint = hashlib.sha256(recipient_point).digest()

    # A fresh, one-time ECDH secret encrypts the DEK masked with the compact
    # envelope's recipient-binding pad. The P-256 recipient alone can unwrap it.
    binding_salt = os.urandom(16)
    app_kdk = hashlib.sha256(b"npvtunnel/appkey/v2 " + _BINDING_A16 + config_id).digest()
    pad = _hkdf(app_kdk, binding_salt, b"NPVS-v6/recipient-binding" + config_id)
    masked_dek = bytes(a ^ b for a, b in zip(dek, pad))
    ephemeral = ec.generate_private_key(ec.SECP256R1())
    shared = ephemeral.exchange(ec.ECDH(), recipient)
    wrap_key = _hkdf(shared, fingerprint, b"NPVS-v1-wrap" + config_id)
    wrap_nonce = os.urandom(12)
    wrap = _compressed(ephemeral.public_key()) + wrap_nonce + AESGCM(wrap_key).encrypt(
        wrap_nonce, masked_dek, fingerprint
    )
    assert len(wrap) == 93

    header_prefix = (
        b"\x01" + config_id + _compressed(creator.public_key())
        + b"\x00" + struct.pack(">H", 1) + fingerprint + wrap + binding_salt
    )
    metadata = {
        "issuedAt": datetime.now(timezone.utc).isoformat(timespec="microseconds").replace("+00:00", "Z"),
        "policy": {
            "configVersion": 2,
            "onlyMobileNetwork": False,
            "attestationLevel": "NONE",
            "expiresAt": None,
            "displayMessage": "",
            "customServerMessage": "",
        },
    }
    metadata_key = _hkdf(dek, body_nonce, b"NPVS-v5/metadata")
    sealed_metadata = ChaCha20Poly1305(metadata_key).encrypt(body_nonce, _json(metadata), header_prefix)
    header = header_prefix + struct.pack(">I", len(sealed_metadata)) + sealed_metadata

    fields = [
        (1, _json("v4-sub")),
        (2, _json(name)),
        (3, _json(url)),
        (4, _json(12)),
        (65535, b'{"kind":1,"name":2,"url":3,"autoUpdateHours":4}'),
    ]
    rows = b"".join(_field(seq, value, dek, content_id) for seq, value in fields)
    # Compact field bodies carry 32 bytes of trailing padding after the rows.
    body = b"NPF\x01" + content_id + struct.pack(">H", len(fields)) + rows + os.urandom(32)
    payload = (
        b"NPVS\x06" + struct.pack(">I", len(header)) + header
        + body_nonce + struct.pack(">I", len(body)) + body
    )
    der = creator.sign(payload, ec.ECDSA(hashes.SHA256()))
    r, s = utils.decode_dss_signature(der)
    return payload + r.to_bytes(32, "big") + s.to_bytes(32, "big")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sub", required=True, help="subscription URL, kept as a URL")
    parser.add_argument("--public-key", required=True, help="recipient Public Key from NPV Tunnel")
    parser.add_argument("--out", required=True, help="output .npvs file")
    parser.add_argument("--name", required=True, help="subscription name for the output file")
    parser.add_argument(
        "--creator-key",
        default=str(Path(__file__).resolve().parent.parent / "settings" / "npvs_creator_key.pem"),
        help="persistent P-256 creator private-key path (created with mode 0600 if missing)",
    )
    args = parser.parse_args()
    try:
        output = create_subscription(args.sub, args.public_key, args.name, args.creator_key)
        with open(args.out, "xb") as target:
            target.write(output)
    except (ValueError, OSError) as exc:
        print(f"NPVS generation failed: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
