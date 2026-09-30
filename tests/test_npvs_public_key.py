#!/usr/bin/env python3
import base64
import hashlib
import importlib.util
import json
import struct
import tempfile
from pathlib import Path

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import ec, utils
from cryptography.hazmat.primitives.ciphers.aead import AESGCM, ChaCha20Poly1305
from cryptography.hazmat.primitives.kdf.hkdf import HKDF


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("npvs_public_key", ROOT / "tools" / "npvs_public_key.py")
GEN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(GEN)

SUB_URL = (
    "https://pmainp1.deltamarket2.ir:8000/sub/"
    "djMsMTA1NzAsMTc5MDc1MjE4Ng.FmHEqN20pL0PqQjc-gyHqPGQF326qtfjQgdpIHwXLwk"
)
NAME_23_BYTES = "01234567890123456789012"


def hkdf(key, salt, info):
    return HKDF(algorithm=hashes.SHA256(), length=32, salt=salt, info=info).derive(key)


def open_generated(data, recipient_private):
    assert data[:5] == b"NPVS\x06"
    header_len = struct.unpack(">I", data[5:9])[0]
    header = data[9 : 9 + header_len]
    assert header[0] == 1
    assert header[50] == 0
    assert struct.unpack(">H", header[51:53])[0] == 1

    config_id = header[1:17]
    creator_point = header[17:50]
    fingerprint = header[53:85]
    wrap = header[85:178]
    binding_salt = header[178:194]
    assert len(binding_salt) == 16

    ephemeral = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), wrap[:33])
    wrap_nonce = wrap[33:45]
    shared = recipient_private.exchange(ec.ECDH(), ephemeral)
    wrap_key = hkdf(shared, fingerprint, b"NPVS-v1-wrap" + config_id)
    masked_dek = AESGCM(wrap_key).decrypt(wrap_nonce, wrap[45:], fingerprint)

    app_kdk = hashlib.sha256(b"npvtunnel/appkey/v2 " + GEN._BINDING_A16 + config_id).digest()
    pad = hkdf(app_kdk, binding_salt, b"NPVS-v6/recipient-binding" + config_id)
    dek = bytes(a ^ b for a, b in zip(masked_dek, pad))

    metadata_len = struct.unpack(">I", header[194:198])[0]
    assert 198 + metadata_len == len(header)

    body_head = 9 + header_len
    body_nonce = data[body_head : body_head + 12]
    body_len = struct.unpack(">I", data[body_head + 12 : body_head + 16])[0]
    body = data[body_head + 16 : body_head + 16 + body_len]
    signature = data[body_head + 16 + body_len :]
    assert len(signature) == 64

    metadata_key = hkdf(dek, body_nonce, b"NPVS-v5/metadata")
    metadata = ChaCha20Poly1305(metadata_key).decrypt(
        body_nonce, header[198:], header[:194]
    )
    parsed_meta = json.loads(metadata)
    assert parsed_meta["policy"]["configVersion"] == 2

    assert body[:4] == b"NPF\x01"
    content_id = body[4:36]
    field_count = struct.unpack(">H", body[36:38])[0]
    assert field_count == 5
    offset = 38
    fields = {}
    for _ in range(field_count):
        seq, flags, enc_len = struct.unpack(">HHH", body[offset : offset + 6])
        assert flags == 0
        blob = body[offset + 6 : offset + 6 + enc_len]
        offset += 6 + enc_len
        plain_len = enc_len - 16
        field_key = hkdf(
            dek,
            content_id,
            b"NPV-fields-v1/field/" + struct.pack(">H", seq),
        )
        aad = (
            b"NPV-fields-v1/record/"
            + content_id
            + struct.pack(">H", seq)
            + struct.pack(">I", plain_len)
        )
        fields[seq] = ChaCha20Poly1305(field_key).decrypt(bytes(12), blob, aad)

    assert json.loads(fields[1]) == "v4-sub"
    assert json.loads(fields[2]) == NAME_23_BYTES
    assert json.loads(fields[3]) == SUB_URL
    assert json.loads(fields[4]) == 12
    assert json.loads(fields[65535]) == {
        "kind": 1,
        "name": 2,
        "url": 3,
        "autoUpdateHours": 4,
    }

    creator = ec.EllipticCurvePublicKey.from_encoded_point(ec.SECP256R1(), creator_point)
    r = int.from_bytes(signature[:32], "big")
    s = int.from_bytes(signature[32:], "big")
    creator.verify(
        utils.encode_dss_signature(r, s),
        data[:-64],
        ec.ECDSA(hashes.SHA256()),
    )
    return creator_point, binding_salt, fields


def main():
    recipient_private = ec.generate_private_key(ec.SECP256R1())
    recipient_point = recipient_private.public_key().public_bytes(
        serialization.Encoding.X962,
        serialization.PublicFormat.CompressedPoint,
    )
    public_text = base64.urlsafe_b64encode(recipient_point).rstrip(b"=").decode()

    with tempfile.TemporaryDirectory() as temp:
        creator_path = str(Path(temp) / "creator.pem")
        first = GEN.create_subscription(SUB_URL, public_text, NAME_23_BYTES, creator_path)
        second = GEN.create_subscription(SUB_URL, public_text, NAME_23_BYTES, creator_path)

        creator_1, salt_1, _ = open_generated(first, recipient_private)
        creator_2, salt_2, _ = open_generated(second, recipient_private)

        # These exact dimensions match the valid manual NPV Tunnel sample for
        # the same URL length and a 23-byte subscription name.
        assert len(first) == 863
        assert struct.unpack(">I", first[5:9])[0] == 399
        assert first[62:94] == hashlib.sha256(recipient_point).digest()

        # NPV Tunnel keeps one creator identity while v6 uses a fresh binding
        # salt for each export.
        assert creator_1 == creator_2
        assert salt_1 != salt_2

    print("NPVS v6 public-key regression test: PASS")


if __name__ == "__main__":
    main()
