\
import asyncio
import os
import shutil
import time
import json
import math
from dataclasses import dataclass
from typing import Dict, Any, List, Optional, Tuple
from datetime import datetime, timedelta, timezone

import pytz
import requests
import aiohttp
import re
from dotenv import load_dotenv
from aiogram import Bot, Dispatcher, Router, F
from aiogram.filters import CommandStart
from aiogram.types import Message, CallbackQuery, ReplyKeyboardMarkup, KeyboardButton, ReplyKeyboardRemove
from aiogram.types import InlineKeyboardMarkup, InlineKeyboardButton, FSInputFile
from aiogram.fsm.state import State, StatesGroup
from aiogram.fsm.context import FSMContext

from hcloud import Client
from hcloud.images import Image
from hcloud.server_types import ServerType
from hcloud.locations import Location

from db import DB


# -------------------------
# Money formatting helpers
# -------------------------

def fmt_irt(value: Any) -> str:
    """Format an amount in Iranian Toman (IRT) for UI.

    Accepts int/float/str/None and always returns a safe string.
    """
    try:
        if value is None:
            n = 0
        elif isinstance(value, bool):
            n = int(value)
        elif isinstance(value, int):
            n = int(value)
        elif isinstance(value, float):
            n = int(round(value))
        else:
            s = str(value).strip()
            s = s.replace(" تومان", "").replace("تومان", "")
            s = s.replace(",", "").replace("٬", "")
            n = int(float(s)) if s else 0
    except Exception:
        n = 0
    s2 = f"{n:,}".replace(",", "٬")
    return f"{s2} تومان"

def ensure_card_purchase_api(DB_cls):
    """Make bot resilient if db.py on server is older (missing card purchase methods)."""
    if hasattr(DB_cls, "create_card_purchase"):
        return

    import aiosqlite, time, json

    async def _ensure_table(self):
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                """CREATE TABLE IF NOT EXISTS card_purchases (
                    invoice_id INTEGER PRIMARY KEY,
                    user_id INTEGER NOT NULL,
                    payload_json TEXT NOT NULL,
                    receipt_file_id TEXT,
                    status TEXT NOT NULL,
                    created_at INTEGER NOT NULL
                )"""
            )
            await db.commit()

    async def create_card_purchase(self, invoice_id: int, user_id: int, payload_json: str) -> None:
        await _ensure_table(self)
        now = int(time.time())
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "INSERT OR REPLACE INTO card_purchases(invoice_id,user_id,payload_json,receipt_file_id,status,created_at) VALUES(?,?,?,?,?,?)",
                (invoice_id, user_id, payload_json, None, "waiting_receipt", now),
            )
            await db.commit()

    async def set_card_purchase_receipt(self, invoice_id: int, receipt_file_id: str) -> None:
        await _ensure_table(self)
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE card_purchases SET receipt_file_id=?, status='sent_to_admin' WHERE invoice_id=?",
                (receipt_file_id, invoice_id),
            )
            await db.commit()

    async def get_card_purchase(self, invoice_id: int):
        await _ensure_table(self)
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT invoice_id,user_id,payload_json,receipt_file_id,status,created_at FROM card_purchases WHERE invoice_id=?",
                (invoice_id,),
            )
            r = await cur.fetchone()
        if not r:
            return None
        return {
            "invoice_id": r[0],
            "user_id": r[1],
            "payload_json": r[2],
            "receipt_file_id": r[3],
            "status": r[4],
            "created_at": r[5],
        }

    async def set_card_purchase_status(self, invoice_id: int, status: str) -> None:
        await _ensure_table(self)
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE card_purchases SET status=? WHERE invoice_id=?", (status, invoice_id))
            await db.commit()

    DB_cls.create_card_purchase = create_card_purchase
    DB_cls.set_card_purchase_receipt = set_card_purchase_receipt
    DB_cls.get_card_purchase = get_card_purchase
    DB_cls.set_card_purchase_status = set_card_purchase_status


async def ensure_card_purchase_support(db: DB):
    """Runtime fallback: some deployments may have an older db.py without card_purchases helpers.
    This function makes the bot resilient by ensuring the table exists and monkey-patching missing methods.
    """
    if hasattr(DB, "create_card_purchase") and hasattr(DB, "get_card_purchase"):
        return

    import aiosqlite, time as _time
    # Ensure table exists
    async with aiosqlite.connect(db.path) as _c:
        await _c.executescript("""
        CREATE TABLE IF NOT EXISTS card_purchases (
          invoice_id INTEGER PRIMARY KEY,
          user_id INTEGER NOT NULL,
          payload_json TEXT NOT NULL,
          receipt_file_id TEXT,
          status TEXT NOT NULL,
          created_at INTEGER NOT NULL
        );
        """)
        await _c.commit()

    # Monkey patch missing methods onto DB class
    if not hasattr(DB, "create_card_purchase"):
        async def _create_card_purchase(self, invoice_id: int, user_id: int, payload_json: str) -> None:
            now = int(_time.time())
            async with aiosqlite.connect(self.path) as _db:
                await _db.execute(
                    "INSERT OR REPLACE INTO card_purchases(invoice_id,user_id,payload_json,receipt_file_id,status,created_at) VALUES(?,?,?,?,?,?)",
                    (invoice_id, user_id, payload_json, None, "waiting_receipt", now),
                )
                await _db.commit()
        DB.create_card_purchase = _create_card_purchase  # type: ignore[attr-defined]

    if not hasattr(DB, "set_card_purchase_receipt"):
        async def _set_card_purchase_receipt(self, invoice_id: int, receipt_file_id: str) -> None:
            async with aiosqlite.connect(self.path) as _db:
                await _db.execute(
                    "UPDATE card_purchases SET receipt_file_id=?, status='sent_to_admin' WHERE invoice_id=?",
                    (receipt_file_id, invoice_id),
                )
                await _db.commit()
        DB.set_card_purchase_receipt = _set_card_purchase_receipt  # type: ignore[attr-defined]

    if not hasattr(DB, "get_card_purchase"):
        async def _get_card_purchase(self, invoice_id: int):
            async with aiosqlite.connect(self.path) as _db:
                cur = await _db.execute(
                    "SELECT invoice_id,user_id,payload_json,receipt_file_id,status,created_at FROM card_purchases WHERE invoice_id=?",
                    (invoice_id,),
                )
                r = await cur.fetchone()
            if not r:
                return None
            return {
                "invoice_id": r[0],
                "user_id": r[1],
                "payload_json": r[2],
                "receipt_file_id": r[3],
                "status": r[4],
                "created_at": r[5],
            }
        DB.get_card_purchase = _get_card_purchase  # type: ignore[attr-defined]

    if not hasattr(DB, "set_card_purchase_status"):
        async def _set_card_purchase_status(self, invoice_id: int, status: str) -> None:
            async with aiosqlite.connect(self.path) as _db:
                await _db.execute("UPDATE card_purchases SET status=? WHERE invoice_id=?", (status, invoice_id))
                await _db.commit()
        DB.set_card_purchase_status = _set_card_purchase_status  # type: ignore[attr-defined]


# -------------------------
# Button label overrides (Admin -> Settings -> Rename buttons)
# -------------------------
# Stored in DB setting key "button_labels" as JSON mapping: {key: "New Text"}
# Keys are mostly callback_data values; for country buttons we use "country:CC"
BUTTON_LABELS: Dict[str, str] = {}

# Global UI preference: whether inline buttons are rendered in "glass" style.
# This is controlled from Admin -> مدیریت عمومی -> تغییر نمایش دکمه‌ها.
GLASS_BUTTONS_ENABLED: bool = True

# Stable short ids for admin label editor (avoid long callback_data)
LABEL_KEY_BY_ID: Dict[str, str] = {}
LABEL_ID_BY_KEY: Dict[str, str] = {}
LABEL_DEFAULTS: Dict[str, str] = {}


def _resolve_button_key(callback_data: str) -> str:
    """Map callback_data to a stable key for label overrides.

    We store label overrides keyed by callback_data. For country selectors we normalize
    to "country:CC" so one override applies anywhere that country button appears.
    """
    c = str(callback_data or "")
    m = re.search(r":country:([A-Z]{2})$", c)
    if m:
        return f"country:{m.group(1)}"
    return c

def _base36(n: int) -> str:
    chars = "0123456789abcdefghijklmnopqrstuvwxyz"
    if n == 0:
        return "0"
    s = ""
    while n > 0:
        n, r = divmod(n, 36)
        s = chars[r] + s
    return s











def _build_label_catalog() -> None:
    """Build a catalog of known buttons from hardcoded pairs + discovered pairs + countries."""
    global LABEL_KEY_BY_ID, LABEL_ID_BY_KEY, LABEL_DEFAULTS

    # Manually curated core keys + fallbacks (filled/extended later too)
    catalog: List[Tuple[str, str]] = []

    # NOTE: We also build extra items from COUNTRY_NAMES below.
    # A small set of very common keys (important so they're always present)
    core = [
        ("buy:start", "🛒 خرید"),
        ("buy:provider:manual", "🧾 فروش دستی"),
        ("manual_sale", "🧾 فروش دستی"),
        ("orders", "📦 سفارش‌ها"),
        ("profile", "👤 پروفایل"),
        ("ip:status", "🔁 بررسی دوباره"),
        ("admin:home", "🫧 پنل مدیریت"),
        ("admin:buttons", "🧩 تنظیم دکمه‌ها"),
        ("admin:labels", "🏷 تغییر اسم دکمه‌ها"),
    ]
    for k, t in core:
        catalog.append((k, t))

    # Discover additional (text, callback_data) pairs from this source file (best-effort)
    # (This helps automatically include nested/menu buttons without manually listing everything.)
    try:
        src = open(__file__, "r", encoding="utf-8").read()
        pair_re = re.compile(r"\(\s*[\"']([^\"']{1,80})[\"']\s*,\s*[\"']([^\"']{1,80})[\"']\s*\)")
        for t, c in pair_re.findall(src):
            if not c:
                continue
            # heuristics: likely callback_data
            if (":" in c) or c in {"home", "noop"} or c.startswith(("buy", "admin")):
                if " " in c or "/" in c or "." in c:
                    continue
                key = _resolve_button_key(c)
                if key and key not in LABEL_DEFAULTS:
                    LABEL_DEFAULTS[key] = t
    except Exception:
        pass

    # Countries
    try:
        for cc, nm in COUNTRY_NAMES.items():
            catalog.append((f"country:{cc}", f"🌍 {nm}"))
    except Exception:
        pass

    # Add any discovered defaults we already loaded into LABEL_DEFAULTS
    for k, t in list(LABEL_DEFAULTS.items()):
        catalog.append((k, t))

    # Uniq (preserve first title)
    uniq: Dict[str, str] = {}
    for k, t in catalog:
        if k and k not in uniq:
            uniq[k] = t

    # Assign ids
    LABEL_DEFAULTS = uniq
    LABEL_KEY_BY_ID = {}
    LABEL_ID_BY_KEY = {}
    i = 1
    for k in sorted(uniq.keys()):
        _id = _base36(i)
        i += 1
        LABEL_KEY_BY_ID[_id] = k
        LABEL_ID_BY_KEY[k] = _id
async def load_button_labels(db: "DB") -> None:
    """Load label overrides into memory."""
    global BUTTON_LABELS
    try:
        raw = await db.get_setting("button_labels", "{}") or "{}"
        obj = json.loads(raw)
        if isinstance(obj, dict):
            BUTTON_LABELS = {str(k): str(v) for k, v in obj.items() if str(v).strip()}
        else:
            BUTTON_LABELS = {}
    except Exception:
        BUTTON_LABELS = {}


async def load_glass_buttons_pref(db: "DB") -> None:
    """Load glass button preference into memory."""
    global GLASS_BUTTONS_ENABLED
    try:
        raw = await db.get_setting("glass_buttons_enabled", "1")
        GLASS_BUTTONS_ENABLED = str(raw or "1").strip() not in {"0", "false", "False", "no", "NO"}
    except Exception:
        GLASS_BUTTONS_ENABLED = True

# -------------------------
# Config
# -------------------------
load_dotenv()

BOT_TOKEN = os.getenv("BOT_TOKEN", "")
ADMIN_IDS = {int(x.strip()) for x in os.getenv("ADMIN_IDS", "").split(",") if x.strip().isdigit()}
HCLOUD_TOKEN = os.getenv("HCLOUD_TOKEN", "")

DB_PATH = os.getenv("DB_PATH", "/opt/vpsbot/vpsbot.sqlite3")

# DB backups
DB_BACKUP_DIR = os.getenv("DB_BACKUP_DIR", os.path.join(os.path.dirname(DB_PATH), "backups"))
DB_BACKUP_PREFIX = os.getenv("DB_BACKUP_PREFIX", "vpsbot_backup")
DB_BACKUP_KEEP_LAST = int(os.getenv("DB_BACKUP_KEEP_LAST", "30") or 30)
DB_BACKUP_HOUR = int(os.getenv("DB_BACKUP_HOUR", "3") or 3)   # local TZ hour
DB_BACKUP_MIN = int(os.getenv("DB_BACKUP_MIN", "0") or 0)
TZ_NAME = os.getenv("TIMEZONE", "Europe/Berlin")
TZ = pytz.timezone(TZ_NAME)

DEFAULT_CARD_TEXT = os.getenv("CARD_NUMBER_TEXT", "شماره کارت برای کارت‌به‌کارت: 0000-0000-0000-0000")

APP_TITLE = "vpsbot"

# NOTE: Telegram has no true "glass" UI. We'll use consistent separators/icons.
GLASS_LINE = "━━━━━━━━━━━━━━━"
GLASS_DOT = "✦"

# Requested OS list (we show but validate availability against Hetzner images)
REQUESTED_OS = [
    "AlmaLinux-10",
    "AlmaLinux-9",
    "AlmaLinux-8",
    "CentoOS-10",
    "CentoOS-9",
    "Debian-13",
    "Debian-12",
    "Debian-11",
    "fedroa-42",
    "fedtoa-41",
    "OpenSUSE-15",
    "RockyLinux-10",
    "RockyLinux-9",
    "RockyLinux-8",
    "Ubuntu-24.04",
    "Ubuntu-22.04",
]

# Country -> location(s) in Hetzner Cloud (expand later)
COUNTRY_LOCATIONS = {
    # Hetzner Cloud location codes
    # DE: nbg1 = Nuremberg, fsn1 = Falkenstein
    "DE": ["nbg1", "fsn1"],   # Germany
    "FI": ["hel1"],           # Finland (Helsinki)
    "US": ["ash", "hil"],     # United States (Ashburn, Hillsboro)
    "SG": ["sin"],            # Singapore
}

# reverse map for quick lookup (location -> country)
LOCATION_TO_COUNTRY = {loc: cc for cc, locs in COUNTRY_LOCATIONS.items() for loc in locs}


# Display names for countries (admin/user UI)
COUNTRY_NAMES = {
    "DE": "آلمان",
    "FI": "فنلاند",
    "US": "آمریکا",
    "SG": "سنگاپور",
    "IR": "ایران",
}

# Display names for Hetzner locations (user/admin UI)
LOCATION_NAMES = {
    "nbg1": "Nuremberg",
    "fsn1": "Falkenstein",
    "hel1": "Helsinki",
    "ash": "Ashburn",
    "hil": "Hillsboro",
    "sin": "Singapore",
    "manual": "Manual DC",
}

def location_label(loc_code: str) -> str:
    code = (loc_code or "").lower()
    name = LOCATION_NAMES.get(code, code.upper())
    return f"{name} ({code})"


# Settings key for per-location server-type group availability (what user sees)
# Stored as JSON:
# {
#   "DE": {"nbg1":{"cx":1,"cax":1,"cpx":1}, "fsn1":{"cx":1,"cax":1,"cpx":1}},
#   "US": {"ash":{"cx":1,"cax":1,"cpx":1}, "hil":{"cx":1,"cax":1,"cpx":1}},
#   ...
# }
COUNTRY_LOCATION_GROUPS_SETTINGS_KEY = "country_location_groups_availability"

# Settings key for enabling/disabling countries in user "buy" flow.
# Stored as JSON mapping: {"DE":1,"FI":0,...}
COUNTRIES_ENABLED_SETTINGS_KEY = "countries_enabled"

async def get_countries_enabled_cfg(db: DB) -> Dict[str, int]:
    """Return cfg[CC]=0/1 (country visibility in buy flow). Defaults to enabled for all in COUNTRY_LOCATIONS."""
    try:
        raw = await db.get_setting(COUNTRIES_ENABLED_SETTINGS_KEY, "") or ""
        obj = json.loads(raw) if raw else {}
        if not isinstance(obj, dict):
            obj = {}
    except Exception:
        obj = {}
    out: Dict[str, int] = {}
    for cc in COUNTRY_LOCATIONS.keys():
        cc_u = str(cc).upper()
        v = obj.get(cc_u, 1)
        try:
            out[cc_u] = 1 if int(v) else 0
        except Exception:
            out[cc_u] = 1
    return out

async def is_country_enabled(db: DB, cc: str) -> bool:
    cc = (cc or "").upper()
    cfg = await get_countries_enabled_cfg(db)
    return bool(cfg.get(cc, 1))

async def set_country_enabled_flag(db: DB, cc: str, enabled: bool) -> None:
    cc = (cc or "").upper()
    cfg = await get_countries_enabled_cfg(db)
    cfg[cc] = 1 if enabled else 0
    await db.set_setting(COUNTRIES_ENABLED_SETTINGS_KEY, json.dumps(cfg, ensure_ascii=False))


async def get_country_location_groups_cfg(db: DB) -> Dict[str, Dict[str, Dict[str, int]]]:
    """Return nested cfg[CC][LOC][GROUP]=0/1"""
    try:
        raw = await db.get_setting(COUNTRY_LOCATION_GROUPS_SETTINGS_KEY, "") or ""
        obj = json.loads(raw) if raw else {}
        if not isinstance(obj, dict):
            return {}
        out: Dict[str, Dict[str, Dict[str, int]]] = {}
        for cc, locmap in obj.items():
            if not isinstance(locmap, dict):
                continue
            cc_u = str(cc).upper()
            out[cc_u] = {}
            for loc, gmap in locmap.items():
                if not isinstance(gmap, dict):
                    continue
                loc_l = str(loc).lower()
                out[cc_u][loc_l] = {str(k).lower(): (1 if int(v) else 0) for k, v in gmap.items() if str(k)}
        return out
    except Exception:
        return {}

async def set_country_location_group_flag(db: DB, cc: str, loc: str, group_key: str, enabled: bool) -> None:
    cc = (cc or "").upper()
    loc = (loc or "").lower()
    group_key = (group_key or "").lower()
    cfg = await get_country_location_groups_cfg(db)

    if cc not in cfg:
        cfg[cc] = {}
    if loc not in cfg[cc]:
        cfg[cc][loc] = {}

    cfg[cc][loc][group_key] = 1 if enabled else 0
    await db.set_setting(COUNTRY_LOCATION_GROUPS_SETTINGS_KEY, json.dumps(cfg, ensure_ascii=False))

async def get_country_location_group_flag(db: DB, cc: str, loc: str, group_key: str) -> bool:
    cc = (cc or "").upper()
    loc = (loc or "").lower()
    group_key = (group_key or "").lower()

    cfg = await get_country_location_groups_cfg(db)
    if cc in cfg and loc in cfg[cc] and group_key in cfg[cc][loc]:
        return bool(int(cfg[cc][loc][group_key]))

    # default fallback: if no override saved yet
    default_keys = set(COUNTRY_SERVER_GROUPS.get(cc, ["cx", "cax", "cpx"]))
    return group_key in default_keys

async def allowed_group_keys_for_location_db(db: DB, cc: str, loc: str) -> List[str]:
    cc = (cc or "").upper()
    loc = (loc or "").lower()
    keys: List[str] = []
    for _label, key, _types in SERVER_TYPE_GROUPS:
        if await get_country_location_group_flag(db, cc, loc, key):
            keys.append(key)
    return keys
# -------------------------
# Server-type groups (for UI filtering)
# -------------------------
# We group Hetzner server types by architecture/family, then show them per-country.
# NOTE: We infer group by prefix:
#   cx*  -> x86 (intel/AMD)
#   cax* -> Arm64 (Ampere)
#   cpx* -> x86 AMD
SERVER_TYPE_GROUPS = [
    ("x86 (intel/AMD)", "cx", ["cx23", "cx33", "cx43", "cx53"]),
    ("Arm64 (Ampere)", "cax", ["cax11", "cax21", "cax31", "cax41"]),
    ("x86 AMD", "cpx", ["cpx11", "cpx21", "cpx31", "cpx41", "cpx51"]),
]

# Which groups should be shown for each country (examples: DE shows all; FI only AMD)
COUNTRY_SERVER_GROUPS = {
    "DE": ["cx", "cax", "cpx"],
    "FI": ["cx", "cax", "cpx"],
    "US": ["cx", "cax", "cpx"],
    "SG": ["cx", "cax", "cpx"],
}

def server_type_group_key(server_type: str) -> str:
    st = (server_type or "").strip().lower()
    if st.startswith("cax"):
        return "cax"
    if st.startswith("cpx"):
        return "cpx"
    if st.startswith("cx"):
        return "cx"
    return "other"

def allowed_group_keys_for_country(country_code: str) -> List[str]:
    return list(COUNTRY_SERVER_GROUPS.get(country_code, ["cx", "cax", "cpx"]))

def groups_for_country(country_code: str) -> List[Tuple[str, str]]:
    keys = set(allowed_group_keys_for_country(country_code))
    out = []
    for label, key, _types in SERVER_TYPE_GROUPS:
        if key in keys:
            out.append((label, key))
    return out

def server_types_for_group(key: str) -> List[str]:
    for _label, k, types_ in SERVER_TYPE_GROUPS:
        if k == key:
            return list(types_)
    return []

# -------------------------
# FSM
# -------------------------
class BuyFlow(StatesGroup):
    provider = State()
    country = State()
    location = State()
    os = State()
    server_type_group = State()
    plan = State()
    name = State()
    billing = State()
    pay_method = State()

class AdminAddPlan(StatesGroup):
    provider = State()
    country = State()
    location = State()
    server_type_group = State()
    server_type = State()
    title = State()
    price_monthly = State()
    traffic_limit = State()
    hourly_enabled = State()
    price_hourly = State()

class AdminPricingFlow(StatesGroup):
    set_rate = State()
    set_flat_pct = State()
    set_low_pct = State()
    set_high_pct = State()
    set_threshold = State()

class AdminPlanEditFlow(StatesGroup):
    pick_field = State()
    set_monthly_eur = State()
    set_hourly_eur = State()
    set_traffic_gb = State()


class AdminBroadcast(StatesGroup):
    text = State()

class AdminUserFlow(StatesGroup):
    search_id = State()
    msg_text = State()
    amount = State()

class AdminSetText(StatesGroup):
    text = State()

class AdminButtonsFlow(StatesGroup):
    labels_json = State()
    menu_layout_json = State()
    newbtn_title = State()
    newbtn_text = State()
    cbtn_pick = State()
    cbtn_edit_title = State()
    cbtn_edit_text = State()
    rename_value = State()


class AdminTrafficFlow(StatesGroup):
    country = State()
    title = State()
    volume_gb = State()
    price_irt = State()


class AdminManualPlanEditFlow(StatesGroup):
    pick_field = State()
    set_title = State()
    set_server_type = State()
    set_country_code = State()
    set_price_irt = State()
    set_traffic_gb = State()


class AdminManualDeliverFlow(StatesGroup):
    order_id = State()
    ip4 = State()
    login_user = State()
    login_pass = State()
    monitoring_url = State()
    monitoring_user = State()
    monitoring_pass = State()
    details = State()


class AdminBackupFlow(StatesGroup):
    auto_value = State()
    upload_db = State()

class RegistrationFlow(StatesGroup):
    phone = State()

class IpStatusFlow(StatesGroup):
    ip = State()

# Legacy (tickets/support removed)
class SupportFlow(StatesGroup):
    text = State()

class TicketFlow(StatesGroup):
    new_subject = State()
    new_text = State()
    reply_text = State()

class AwaitReceipt(StatesGroup):
    invoice_id = State()


class TopUpFlow(StatesGroup):
    amount = State()

# -------------------------
# Helpers
# -------------------------
def is_admin(user_id: int) -> bool:
    return user_id in ADMIN_IDS

def now_ts() -> int:
    return int(time.time())

_ipv4_re = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")

async def check_host_ping(ip: str, max_nodes: int = 3, wait_seconds: int = 7) -> Dict[str, Any]:
    """Call Check-Host.net ping API and return summary + raw results."""
    headers = {"Accept": "application/json", "User-Agent": f"{APP_TITLE}/1.0"}
    async with aiohttp.ClientSession(headers=headers, timeout=aiohttp.ClientTimeout(total=30)) as session:
        async with session.get("https://check-host.net/check-ping", params={"host": ip, "max_nodes": str(max_nodes)}) as r:
            data = await r.json(content_type=None)
        req_id = data.get("request_id")
        link = data.get("permanent_link")
        nodes = data.get("nodes") or {}
        # wait for results
        await asyncio.sleep(wait_seconds)
        async with session.get(f"https://check-host.net/check-result/{req_id}") as r2:
            res = await r2.json(content_type=None)

    # Compute basic stats across all nodes/pings
    ok_times: List[float] = []
    ok_count = 0
    fail_count = 0

    # res format: node -> [[ ["OK", 0.04, "ip"], ... ]]
    for node, node_val in (res or {}).items():
        if not node_val:
            continue
        # usually node_val is list with one element (pings list)
        for group in node_val:
            if not group:
                continue
            for ping_item in group:
                if not ping_item or ping_item[0] is None:
                    continue
                status = str(ping_item[0]).upper()
                if status == "OK":
                    ok_count += 1
                    try:
                        ok_times.append(float(ping_item[1]) * 1000.0)  # seconds -> ms
                    except Exception:
                        pass
                else:
                    fail_count += 1

    summary = {}
    if ok_times:
        summary["min_ms"] = round(min(ok_times), 1)
        summary["avg_ms"] = round(sum(ok_times) / len(ok_times), 1)
        summary["max_ms"] = round(max(ok_times), 1)
    summary["ok"] = ok_count
    summary["fail"] = fail_count
    summary["total"] = ok_count + fail_count

    return {"request_id": req_id, "permanent_link": link, "nodes": nodes, "results": res, "summary": summary}

async def _edit_progress(msg_obj, percent: int, line: str):
    try:
        await msg_obj.edit_text(
            f"{glass_header('در حال ساخت سرور')}\n"
            f"{GLASS_DOT} پیشرفت: <b>{percent}%</b>\n"
            f"{GLASS_DOT} {htmlesc(line)}",
            parse_mode="HTML",
        )
    except Exception:
        pass

async def hcloud_wait_running(server_id: int, timeout_sec: int = 180) -> Tuple[Optional[str], str]:
    """Wait until server is running and IPv4 is assigned."""
    client = hclient()
    start = time.time()
    last_status = ""
    while time.time() - start < timeout_sec:
        srv = client.servers.get_by_id(server_id)
        if srv:
            last_status = getattr(srv, "status", "") or last_status
            ip4 = ""
            try:
                ip4 = srv.public_net.ipv4.ip or ""
            except Exception:
                pass
            if last_status == "running" and ip4:
                return ip4, last_status
        await asyncio.sleep(5)
    return None, last_status or "unknown"

async def hcloud_wait_running_with_progress(msg_obj, server_id: int, timeout_sec: int = 240,
                                           start_percent: int = 75, end_percent: int = 99) -> Tuple[Optional[str], str]:
    """Wait until server is running and IPv4 is assigned; update a progress percent while waiting."""
    client = hclient()
    start = time.time()
    last_status = ""
    last_percent = -1

    while True:
        elapsed = time.time() - start
        if elapsed >= timeout_sec:
            break

        srv = client.servers.get_by_id(server_id)
        if srv:
            last_status = getattr(srv, "status", "") or last_status
            ip4 = ""
            try:
                ip4 = srv.public_net.ipv4.ip or ""
            except Exception:
                pass

            # compute percent based on elapsed time (best-effort)
            percent = start_percent + int((elapsed / float(timeout_sec)) * (end_percent - start_percent))
            percent = max(start_percent, min(end_percent, percent))
            if percent != last_percent:
                await _edit_progress(msg_obj, percent, f"وضعیت: {last_status}…")
                last_percent = percent

            if last_status == "running" and ip4:
                return ip4, last_status

        await asyncio.sleep(5)

    return None, last_status or "unknown"

def fmt_dt(ts: int) -> str:
    return datetime.fromtimestamp(ts, TZ).strftime("%Y-%m-%d %H:%M")


def days_left_text(order: dict) -> str:
    """For monthly orders: return remaining days like '3 روز'. Otherwise '-'"""
    try:
        if (order.get("billing_mode") or "").lower() != "monthly":
            return "-"
        exp = int(order.get("expires_at") or 0)
        if exp <= 0:
            return "-"
        now = int(time.time())
        if exp <= now:
            return "0 روز"
        days = (exp - now + 86399) // 86400
        return f"{days} روز"
    except Exception:
        return "-"
def kb(rows: List[List[Tuple[str, str]]]) -> InlineKeyboardMarkup:
    """Keyboard builder with global label overrides (stored in BUTTON_LABELS)."""
    out: List[List[InlineKeyboardButton]] = []
    for row in rows:
        out_row: List[InlineKeyboardButton] = []
        for t, c in row:
            key = _resolve_button_key(c)
            new_t = BUTTON_LABELS.get(key)
            if new_t:
                t = str(new_t)

            # Optional "glass" style for button text (purely visual).
            if GLASS_BUTTONS_ENABLED and t and not str(t).startswith("🫧"):
                t = f"🫧 {t}"

            t = str(t)[:64]
            # Support URL buttons via a simple prefix.
            # Example: ("🌐 Open", "url:https://example.com")
            if isinstance(c, str) and c.startswith("url:"):
                out_row.append(InlineKeyboardButton(text=t, url=c[4:]))
            else:
                out_row.append(InlineKeyboardButton(text=t, callback_data=c))
        out.append(out_row)
    return InlineKeyboardMarkup(inline_keyboard=out)


def glass_header(title: str) -> str:
    return f"🫧 {title}\n{GLASS_LINE}"


def htmlesc(s: str) -> str:
    import html as _html
    return _html.escape(str(s), quote=False)

def money(n: int) -> str:
    s = f"{n:,}".replace(",", "٬")
    return f"{s} تومان"


# -------------------------
# EUR pricing helpers
# -------------------------
def _pricing_defaults() -> Dict[str, Any]:
    return {
        "eur_rate_irt": 160_000,
        "eur_margin_mode": "tiered",   # 'flat' | 'tiered'
        "eur_margin_flat_pct": 15.0,
        "eur_margin_low_pct": 15.0,
        "eur_margin_high_pct": 8.0,
        "eur_margin_threshold_eur": 10.0,  # monthly EUR threshold for tiered mode
    }

async def get_pricing_cfg(db: 'DB') -> Dict[str, Any]:
    d = _pricing_defaults()
    try:
        d["eur_rate_irt"] = int(await db.get_setting("eur_rate_irt", str(d["eur_rate_irt"])) or d["eur_rate_irt"])
    except Exception:
        pass
    mode = (await db.get_setting("eur_margin_mode", d["eur_margin_mode"])) or d["eur_margin_mode"]
    d["eur_margin_mode"] = mode if mode in ("flat", "tiered") else d["eur_margin_mode"]
    async def _f(key: str, default: float) -> float:
        try:
            return float(await db.get_setting(key, str(default)) or default)
        except Exception:
            return default
    d["eur_margin_flat_pct"] = await _f("eur_margin_flat_pct", d["eur_margin_flat_pct"])
    d["eur_margin_low_pct"] = await _f("eur_margin_low_pct", d["eur_margin_low_pct"])
    d["eur_margin_high_pct"] = await _f("eur_margin_high_pct", d["eur_margin_high_pct"])
    d["eur_margin_threshold_eur"] = await _f("eur_margin_threshold_eur", d["eur_margin_threshold_eur"])
    return d

def _margin_pct(monthly_eur: Optional[float], cfg: Dict[str, Any]) -> float:
    mode = cfg.get("eur_margin_mode", "tiered")
    if mode == "flat":
        return float(cfg.get("eur_margin_flat_pct", 15.0))
    thr = float(cfg.get("eur_margin_threshold_eur", 10.0))
    low = float(cfg.get("eur_margin_low_pct", 15.0))
    high = float(cfg.get("eur_margin_high_pct", 8.0))
    m = float(monthly_eur or 0.0)
    return low if m <= thr else high

def _round_irt(n: float, step: int) -> int:
    if step <= 1:
        return int(round(n))
    return int(round(n / step) * step)

def eur_to_irt(eur: float, cfg: Dict[str, Any], monthly_eur_for_tier: Optional[float] = None, *, step: int = 1000) -> int:
    rate = float(cfg.get("eur_rate_irt", 0) or 0)
    pct = _margin_pct(monthly_eur_for_tier, cfg)
    val = float(eur or 0.0) * rate * (1.0 + pct / 100.0)
    return _round_irt(val, step)

async def plan_effective_prices(db: 'DB', plan: Dict[str, Any]) -> Dict[str, Any]:
    """Return effective prices (IRT) for a plan. If EUR base prices exist, convert using current cfg."""
    cfg = await get_pricing_cfg(db)
    me = plan.get("price_monthly_eur", None)
    he = plan.get("price_hourly_eur", None)
    if me is not None:
        try:
            me = float(me)
        except Exception:
            me = None
    if he is not None:
        try:
            he = float(he)
        except Exception:
            he = None
    if me is not None and me > 0:
        monthly_irt = eur_to_irt(me, cfg, monthly_eur_for_tier=me, step=1000)
    else:
        monthly_irt = int(plan.get("price_monthly_irt") or 0)
    # hourly
    hourly_enabled_flag = bool(plan.get("hourly_enabled"))
    if he is not None and he > 0 and hourly_enabled_flag:
        hourly_irt = eur_to_irt(he, cfg, monthly_eur_for_tier=me, step=100)
    else:
        hourly_irt = int(plan.get("price_hourly_irt") or 0)
    return {
        "monthly_irt": int(monthly_irt),
        "hourly_irt": int(hourly_irt),
        "monthly_eur": me,
        "hourly_eur": he,
        "cfg": cfg,
    }


def safe_hostname(name: str) -> Optional[str]:
    name = name.strip().lower()
    name = "".join(ch if (ch.isalnum() or ch == "-") else "-" for ch in name)
    name = name.strip("-")
    if not (1 <= len(name) <= 63):
        return None
    if not all(ch.isalnum() or ch == "-" for ch in name):
        return None
    return name

# -------------------------
# Hetzner utilities
# -------------------------
@dataclass
class HImage:
    id: int
    name: str
    description: str

# --- Hetzner server-type availability cache (best-effort) ---
_STOCK_CACHE = {}  # (location, server_type) -> (ts, available_bool)

def hcloud_server_type_available(location: str, server_type_name: str) -> bool:
    """
    Best-effort stock check. Hetzner Cloud API provides 'available' per server_type.
    This does NOT guarantee per-location stock, but usually correlates with "can create".
    We cache for 120 seconds.
    """
    key = (location, server_type_name.lower())
    now = time.time()
    if key in _STOCK_CACHE and now - _STOCK_CACHE[key][0] < 120:
        return bool(_STOCK_CACHE[key][1])
    token = os.getenv("HCLOUD_TOKEN", "").strip()
    if not token:
        _STOCK_CACHE[key] = (now, True)
        return True
    try:
        r = requests.get(
            "https://api.hetzner.cloud/v1/server_types",
            headers={"Authorization": f"Bearer {token}"},
            timeout=10,
        )
        r.raise_for_status()
        data = r.json().get("server_types", [])
        for st in data:
            if str(st.get("name","")).lower() == server_type_name.lower():
                av = bool(st.get("available", True))
                _STOCK_CACHE[key] = (now, av)
                return av
    except Exception:
        pass
    _STOCK_CACHE[key] = (now, True)
    return True

def hclient() -> Client:
    if not HCLOUD_TOKEN:
        raise RuntimeError("HCLOUD_TOKEN is not set in .env")
    return Client(token=HCLOUD_TOKEN)

def list_locations_for_country(country_code: str) -> List[str]:
    return COUNTRY_LOCATIONS.get(country_code, [])

def _normalize_os_key(s: str) -> str:
    return s.lower().replace("_", "-").replace(" ", "").replace(".", "")

def find_matching_image(client: Client, os_label: str) -> Optional[HImage]:
    key = _normalize_os_key(os_label)
    imgs = client.images.get_all(type="system")
    best: Optional[Tuple[int, Any]] = None
    for im in imgs:
        hay = _normalize_os_key((im.name or "") + " " + (im.description or "") + " " + (im.os_flavor or "") + " " + (im.os_version or ""))
        score = 0
        if "ubuntu" in key and ("ubuntu" in hay):
            score += 3
        if "debian" in key and ("debian" in hay):
            score += 3
        if "rocky" in key and ("rocky" in hay):
            score += 3
        if "alma" in key and ("alma" in hay):
            score += 3
        if "cento" in key and ("centos" in hay or "stream" in hay):
            score += 2
        if "fed" in key and ("fedora" in hay):
            score += 3
        if "opensuse" in key and ("suse" in hay or "opensuse" in hay):
            score += 3

        digits = "".join(ch for ch in key if ch.isdigit())
        if digits and digits in hay:
            score += 2

        if score > 0 and (best is None or score > best[0]):
            best = (score, im)
    if not best:
        return None
    im = best[1]
    return HImage(id=int(im.id), name=im.name, description=im.description or "")

def server_type_available_in_location(client: Client, server_type_name: str, location_name: str) -> bool:
    # Best-effort: if type exists, assume available; creation errors handled later
    try:
        st = client.server_types.get_by_name(server_type_name)
        return st is not None
    except Exception:
        return False

def get_server_type_specs(client: Client, server_type_name: str) -> Dict[str, Any]:
    st = client.server_types.get_by_name(server_type_name)
    if not st:
        return {}
    return {"vcpu": getattr(st, "cores", None), "ram_gb": getattr(st, "memory", None), "disk_gb": getattr(st, "disk", None)}

def hcloud_create_server(name: str, server_type: str, image_id: int, location_name: str) -> Tuple[int, str, str]:
    client = hclient()
    loc = Location(name=location_name)
    response = client.servers.create(
        name=name,
        server_type=ServerType(name=server_type),
        image=Image(id=image_id),
        location=loc,
    )
    srv = response.server
    root_pw = response.root_password or ""
    ip4 = ""
    try:
        ip4 = srv.public_net.ipv4.ip
    except Exception:
        pass
    return int(srv.id), ip4, root_pw

def hcloud_power_action(server_id: int, action: str) -> None:
    client = hclient()
    srv = client.servers.get_by_id(server_id)
    if not srv:
        raise RuntimeError("server not found")
    if action == "poweroff":
        srv.power_off()
    elif action == "poweron":

        srv.power_on()
    elif action == "rebuild":
        im = getattr(srv, "image", None)
        if not im or not getattr(im, "id", None):
            raise RuntimeError("cannot detect server image to rebuild")
        srv.rebuild(Image(id=int(im.id)))
    else:
        raise ValueError("unknown action")

def hcloud_reset_password(server_id: int) -> str:
    if not HCLOUD_TOKEN:
        raise RuntimeError("HCLOUD_TOKEN missing")
    url = f"https://api.hetzner.cloud/v1/servers/{server_id}/actions/reset_password"
    r = requests.post(url, headers={"Authorization": f"Bearer {HCLOUD_TOKEN}"}, timeout=30)
    r.raise_for_status()
    data = r.json()
    return data.get("root_password", "") or ""


def hcloud_delete_server(server_id: int) -> bool:
    """Delete a Hetzner Cloud server by id.

    Returns True if deleted (or not found). Uses two strategies:
    1) Direct REST DELETE (fast)
    2) hcloud SDK fallback (helps in some edge cases)
    """
    if not HCLOUD_TOKEN:
        raise RuntimeError("HCLOUD_TOKEN missing")
    sid = int(server_id)
    url = f"https://api.hetzner.cloud/v1/servers/{sid}"
    try:
        r = requests.delete(url, headers={"Authorization": f"Bearer {HCLOUD_TOKEN}"}, timeout=30)
        if r.status_code in (200, 204, 202, 404):
            return True
        # Some states may return other codes; fall back to SDK once before failing.
        last_err = f"{r.status_code} {r.text}"
    except Exception as e:
        last_err = str(e)

    # Fallback: hcloud SDK
    try:
        client = Client(token=HCLOUD_TOKEN)
        srv = client.servers.get_by_id(sid)
        if not srv:
            return True
        client.servers.delete(srv)
        return True
    except Exception as e:
        raise RuntimeError(f"Hetzner delete failed: {last_err} / fallback: {e}")

def hcloud_get_network_bytes(server_id: int, start: datetime, end: datetime) -> Optional[float]:
    if not HCLOUD_TOKEN:
        return None
    url = f"https://api.hetzner.cloud/v1/servers/{server_id}/metrics"
    params = {
        "type": "network",
        "start": start.replace(tzinfo=timezone.utc).isoformat().replace("+00:00", "Z"),
        "end": end.replace(tzinfo=timezone.utc).isoformat().replace("+00:00", "Z"),
        "step": 3600,
    }
    r = requests.get(url, headers={"Authorization": f"Bearer {HCLOUD_TOKEN}"}, params=params, timeout=30)
    if r.status_code != 200:
        return None
    data = r.json()
    metrics = data.get("metrics", {}).get("time_series", {})
    out_series = None
    for k in ("network.out", "network_out"):
        if k in metrics:
            out_series = metrics.get(k)
            break
    if not out_series and "network" in metrics and isinstance(metrics["network"], dict):
        out_series = metrics["network"].get("out")
    if not out_series:
        return None
    total = 0.0
    for _, v in (out_series.get("values") or []):
        if v is None:
            continue
        total += float(v)
    return total

# -------------------------
# UI
# -------------------------
async def get_card_text(db: DB) -> str:
    return await db.get_setting("card_number_text", DEFAULT_CARD_TEXT) or DEFAULT_CARD_TEXT


async def send_admin_purchase_report(
    bot: Bot,
    db: DB,
    *,
    user_id: int,
    order_id: int,
    ip4: str,
    pay_method: str,
    amount_irt: int,
    plan_name: str,
    billing: str,
):
    """Send a standardized purchase report to all admins when a server is successfully created."""
    try:
        u = await db.get_user(user_id)
        phone = (u.get("phone") if u else None) or "-"
        username = (u.get("username") if u else None) or "-"
        if username and username != "-" and not str(username).startswith("@"):  # normalize
            username = "@" + str(username)

        pm = "کارت به کارت" if pay_method == "card" else "پرداخت از موجودی"
        msg = (
            "🧾 گزارش خرید جدید\n"
            f"ای پی : {ip4}\n"
            f"کاربر: {user_id}\n"
            f"نام کاربری : {username}\n"
            f"شماره تلفن کاربر : {phone}\n"
            f"شماره سفارش: {order_id}\n"
            f"روش پرداخت: {pm}\n"
            f"پلن: {plan_name} ({billing})\n"
            f"مبلغ: {money(amount_irt)}"
        )
        for aid in ADMIN_IDS:
            try:
                await bot.send_message(aid, msg)
            except Exception:
                pass
    except Exception:
        return


async def send_admin_delete_report(
    bot: Bot,
    db: DB,
    *,
    user_id: int,
    order: Dict[str, Any],
    reason: str,
    actor_id: Optional[int] = None,
    actor_name: str = "-",
    extra_cost_irt: int = 0,
):
    """Send a standardized deletion report to all admins when a server/order is deleted.

    reason examples: user_delete, auto_hourly_no_balance, admin_delete, admin_bulk_delete
    """
    try:
        u = await db.get_user(int(user_id))
        phone = (u.get("phone") if u else None) or "-"
        username = (u.get("username") if u else None) or "-"
        if username and username != "-" and not str(username).startswith("@"):
            username = "@" + str(username)

        oid = int(order.get("id") or 0)
        ip4 = order.get("ip4") or "-"
        name = order.get("name") or "-"
        st = order.get("server_type") or "-"
        os_name = order.get("image_name") or "-"
        loc = order.get("location_name") or "-"
        billing = (order.get("billing_mode") or "-")
        hsid = order.get("hcloud_server_id") or "-"
        traffic_limit = order.get("traffic_limit_gb")
        traffic_used = order.get("traffic_used_gb")
        traffic_txt = "-"
        try:
            if traffic_limit is not None or traffic_used is not None:
                traffic_txt = f"{traffic_used if traffic_used is not None else 0}/{traffic_limit if traffic_limit is not None else 0} GB"
        except Exception:
            traffic_txt = "-"

        when_ts = int(time.time())
        actor_line = f"{actor_name}"
        if actor_id:
            actor_line += f" ({actor_id})"

        msg = (
            "🗑 گزارش حذف سرویس\n"
            f"دلیل: {reason}\n"
            f"زمان: {fmt_dt(when_ts)}\n"
            f"حذف توسط: {actor_line}\n"
            "━━━━━━━━━━━━━━\n"
            f"کاربر: {user_id}\n"
            f"نام کاربری: {username}\n"
            f"شماره تلفن: {phone}\n"
            "━━━━━━━━━━━━━━\n"
            f"Order: #{oid}\n"
            f"IP: {ip4}\n"
            f"نام سرویس: {name}\n"
            f"پلن: {st}\n"
            f"لوکیشن: {loc}\n"
            f"OS: {os_name}\n"
            f"Billing: {billing}\n"
            f"Hetzner ID: {hsid}\n"
            f"ترافیک: {traffic_txt}"
        )
        if extra_cost_irt and int(extra_cost_irt) > 0:
            msg += f"\nمبلغ تسویه/کسر شده: {money(int(extra_cost_irt))}"

        for aid in ADMIN_IDS:
            try:
                await bot.send_message(aid, msg)
            except Exception:
                pass
    except Exception:
        return


async def get_invoice_amount_irt(db: DB, invoice_id: int) -> int:
    """Read invoice amount from DB (fallback when no db.py helper exists)."""
    try:
        import aiosqlite
        async with aiosqlite.connect(db.path) as c:
            cur = await c.execute("SELECT amount_irt FROM invoices WHERE id=?", (invoice_id,))
            r = await cur.fetchone()
        return int(r[0]) if r and r[0] is not None else 0
    except Exception:
        return 0




async def get_invoice_amount(db: DB, invoice_id: int) -> int:
    """Backward-compatible alias used by older call sites."""
    return int(await get_invoice_amount_irt(db, invoice_id) or 0)
async def main_menu(db: DB, user_id: int) -> Tuple[str, InlineKeyboardMarkup]:
    start_text = await db.get_setting("start_text", None)
    if not start_text:
        start_text = f"{glass_header('خوش اومدی')}\n{GLASS_DOT} برای خرید سرویس روی «خرید سرویس» بزن."


    try:
        labels = json.loads(await db.get_setting("button_labels", "{}") or "{}")
        if not isinstance(labels, dict):
            labels = {}
    except Exception:
        labels = {}

    def L(key: str, default: str) -> str:
        v = labels.get(key)
        return str(v)[:32] if v else default
    # Main menu order + columns (admins can override via admin:layout)
    # Note: "custom" refers to manual/custom buttons configured in admin panel.
    default_order = ["buy", "orders", "profile", "ip_status", "custom"]
    try:
        menu_order = json.loads(await db.get_setting("menu_order", "") or "null")
        if not isinstance(menu_order, list) or len(menu_order) != 5:
            menu_order = default_order
    except Exception:
        menu_order = default_order

    # Backward-compat: older configs used "renew" as slot #5.
    menu_order = ["custom" if k == "renew" else k for k in menu_order]

    try:
        cols = int(await db.get_setting("menu_cols", "2") or 2)
    except Exception:
        cols = 2
    cols = 1 if cols < 1 else (3 if cols > 3 else cols)

    keymap: Dict[str, Tuple[str, str]] = {
        "buy": (L("buy", "☁️خرید سرور ابری☁️"), "buy:start"),
        "orders": (L("orders", "📦سفارش های من📦"), "me:orders"),
        "profile": (L("profile", "👥مسخصات حساب و شارژ👥"), "me:profile"),
        "ip_status": (L("ip_status", "🛰وضعیت ای پی🛰"), "ip:status"),
        "admin": (L("admin", "🛠 پنل مدیریت"), "admin:home"),
    }

    # Build rows from order + cols
    rows: List[List[Tuple[str, str]]] = []

    # Load custom/manual buttons once
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons", "[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []

    # Expand menu_order to actual button tuples (custom expands to many)
    flat_buttons: List[Tuple[str, str]] = []
    for k in menu_order:
        if k == "custom":
            for i, b in enumerate(cbtns[:12]):
                title = str(b.get("title", ""))[:32]
                if title:
                    flat_buttons.append((title, f"custom:show:{i}"))
        elif k in keymap:
            flat_buttons.append(keymap[k])

    # Build rows by cols
    for i in range(0, len(flat_buttons), cols):
        row = flat_buttons[i:i+cols]
        if row:
            rows.append(row)

    # Admin entry (separate)
    if is_admin(user_id):
        rows.append([keymap["admin"]])

    # (custom buttons are injected via the "custom" slot in menu_order)

    # fallback (never return empty)
    if not rows:
        rows = [[keymap["buy"]], [keymap["orders"], keymap["profile"]], [keymap["ip_status"]]]
        if is_admin(user_id):
            rows.append([keymap["admin"]])

    rows.append([(L('back_to_main', 'برگشت'), 'returnToDelta')])
    return start_text, kb(rows)
def ensure_invoice_api(DB_cls):
    """Ensure invoice helpers exist even if db.py is older."""
    if hasattr(DB_cls, "set_invoice_status"):
        return
    import aiosqlite

    async def set_invoice_status(self, invoice_id: int, status: str) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE invoices SET status=? WHERE id=?", (status, invoice_id))
            await db.commit()

    DB_cls.set_invoice_status = set_invoice_status



router = Router()

# Entry points when called from DeltaBot (webhook-bridge)
@router.callback_query(F.data == "vpsbotStart")
async def deltabotvps_entry(cq: CallbackQuery, db: DB, state: FSMContext):
    # mimic /start behavior by showing main menu (registration flow still applies)
    await db.upsert_user(cq.from_user.id, cq.from_user.username)
    u = await db.get_user(cq.from_user.id)
    if u and u.get("is_blocked"):
        return await cq.answer("⛔️ دسترسی شما مسدود است.", show_alert=True)

    # If phone not registered, start registration flow
    if not u or not u.get("phone"):
        await state.set_state(RegistrationFlow.waiting_contact)
        return await cq.message.answer("برای ادامه، لطفاً شماره موبایل خود را ارسال کنید (Contact).")

    text, markup = await main_menu(db, cq.from_user.id)
    try:
        await cq.message.edit_text(text, reply_markup=markup, parse_mode="HTML")
    except Exception:
        await cq.message.answer(text, reply_markup=markup, parse_mode="HTML")
    await cq.answer()

@router.callback_query(F.data == "vpsbotAdminEntry")
async def deltabotvps_admin_entry(cq: CallbackQuery, db: DB):
    # jump directly to admin home
    return await admin_home(cq, db)



@router.message(CommandStart())
async def on_start(msg: Message, db: DB, state: FSMContext):
    await db.upsert_user(msg.from_user.id, msg.from_user.username)
    u = await db.get_user(msg.from_user.id)
    if u and u.get("is_blocked"):
        return await msg.answer("⛔️ دسترسی شما مسدود است.")

    # Phone registration (Telegram contact)
    if not u or not u.get("phone"):
        await state.set_state(RegistrationFlow.phone)
        kb_contact = ReplyKeyboardMarkup(
            keyboard=[[KeyboardButton(text="📞 ارسال شماره تلفن", request_contact=True)]],
            resize_keyboard=True,
            one_time_keyboard=True,
        )
        return await msg.answer(
            f"{glass_header('ثبت‌نام')}\n{GLASS_DOT} برای استفاده از ربات، شماره تلفنت را ارسال کن.",
            reply_markup=kb_contact,
        )

    text, keyboard = await main_menu(db, msg.from_user.id)
    await state.clear()
    await msg.answer(text, reply_markup=keyboard)


@router.message(RegistrationFlow.phone)
async def reg_phone(msg: Message, db: DB, state: FSMContext):
    # Only accept contact
    if not msg.contact or not msg.contact.phone_number:
        return await msg.answer("لطفاً فقط از دکمه «ارسال شماره تلفن» استفاده کن.")
    phone = msg.contact.phone_number.strip()
    await db.upsert_user(msg.from_user.id, msg.from_user.username)
    u_before = await db.get_user(msg.from_user.id)
    prev_phone = u_before.get("phone") if u_before else None

    await db.set_user_phone(msg.from_user.id, phone)
    await state.clear()

    # Notify admins only on first registration
    if not prev_phone:
        for aid in ADMIN_IDS:
            try:
                await msg.bot.send_message(
                    aid,
                    f"👤 ثبت‌نام جدید\n"
                    f"ID عددی: <code>{msg.from_user.id}</code>\n"
                    f"یوزرنیم: @{msg.from_user.username if msg.from_user.username else '-'}\n"
                    f"شماره: <code>{phone}</code>",
                    parse_mode="HTML",
                )
            except Exception:
                pass

    text, keyboard = await main_menu(db, msg.from_user.id)
    await msg.answer("✅ ثبت‌نام انجام شد.", reply_markup=ReplyKeyboardRemove())
    await msg.answer(text, reply_markup=keyboard)

@router.callback_query(F.data == "home")
async def cb_home(cq: CallbackQuery, db: DB, state: FSMContext):
    await state.clear()
    text, keyboard = await main_menu(db, cq.from_user.id)
    await cq.message.edit_text(text, reply_markup=keyboard)
    await cq.answer()

@router.callback_query(F.data == "ip:status")
async def ip_status_start(cq: CallbackQuery, state: FSMContext):
    await state.set_state(IpStatusFlow.ip)
    await cq.message.edit_text(
        f"{glass_header('وضعیت IP')}\n{GLASS_DOT} لطفاً IP را ارسال کن.\nمثال: <code>91.107.146.247</code>",
        parse_mode="HTML",
        reply_markup=kb([[("برگشت","home")]])
    )
    await cq.answer()

@router.message(IpStatusFlow.ip)
async def ip_status_get(msg: Message, state: FSMContext):
    ip = (msg.text or "").strip()
    if not _ipv4_re.match(ip):
        return await msg.answer("IP نامعتبر است. مثال: 91.107.146.247")
    try:
        parts = [int(x) for x in ip.split(".")]
        if any(p < 0 or p > 255 for p in parts):
            raise ValueError()
    except Exception:
        return await msg.answer("IP نامعتبر است.")
    await state.clear()

    wait_msg = await msg.answer(f"{glass_header('وضعیت IP')}\n{GLASS_DOT} در حال بررسی…", reply_markup=kb([[("🏠 منوی اصلی","home")]]))
    try:
        out = await check_host_ping(ip, max_nodes=3, wait_seconds=7)
        s = out.get("summary") or {}
        link = out.get("permanent_link") or f"https://check-host.net/check-ping?host={ip}"

        if s.get("total", 0) == 0:
            txt = (
                f"{glass_header('نتیجه پینگ')}\n"
                f"{GLASS_DOT} IP: <code>{ip}</code>\n"
                f"{GLASS_DOT} نتیجه قابل محاسبه نبود (ممکن است هنوز در حال انجام باشد).\n"
                f"{GLASS_DOT} لینک: {htmlesc(link)}"
            )
        else:
            loss = round(100.0 * (s.get('fail', 0) / max(1, s.get('total', 0))), 1)
            txt = (
                f"{glass_header('نتیجه پینگ')}\n"
                f"{GLASS_DOT} IP: <code>{ip}</code>\n"
                f"{GLASS_DOT} OK: <b>{s.get('ok',0)}</b> | FAIL: <b>{s.get('fail',0)}</b> | Loss: <b>{loss}%</b>\n"
                f"{GLASS_DOT} Min/Avg/Max: <b>{s.get('min_ms','-')}</b> / <b>{s.get('avg_ms','-')}</b> / <b>{s.get('max_ms','-')}</b> ms\n"
                f"{GLASS_DOT} لینک جزئیات: {htmlesc(link)}"
            )

        await wait_msg.edit_text(txt, parse_mode="HTML", reply_markup=kb([[("🔁 بررسی دوباره","ip:status")],[("🏠 منوی اصلی","home")]]))
    except Exception as e:
        await wait_msg.edit_text(
            f"{glass_header('خطا')}\n{GLASS_DOT} خطا در بررسی: {htmlesc(str(e))}",
            parse_mode="HTML",
            reply_markup=kb([[("🏠 منوی اصلی","home")]])
        )


@router.callback_query(F.data.startswith("custom:show:"))
async def custom_show(cq: CallbackQuery, db: DB):
    idx = int(cq.data.split(":")[-1])
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons", "[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    if idx < 0 or idx >= len(cbtns):
        return await cq.answer("یافت نشد.", show_alert=True)
    txt = str(cbtns[idx].get("text", "")) or "—"
    await cq.message.edit_text(f"{glass_header('اطلاعات')}\n{txt}", reply_markup=kb([[("برگشت","home")]]))
    await cq.answer()

# -------------------------
# Buy flow
# -------------------------
@router.callback_query(F.data == "buy:start")
async def buy_start(cq: CallbackQuery, db: DB, state: FSMContext):
    await state.clear()
    await state.set_state(BuyFlow.provider)
    text = f"{glass_header('خرید سرویس')}\n{GLASS_DOT} دیتاسنتر رو انتخاب کن:"

    # Button labels (admin editable)
    try:
        labels = json.loads(await db.get_setting("button_labels", "{}") or "{}")
        if not isinstance(labels, dict):
            labels = {}
    except Exception:
        labels = {}

    manual_sale_label = str(
        BUTTON_LABELS.get("buy:provider:manual")
        or labels.get("manual_sale")
        or "🧾 فروش دستی"
    )[:32]

    manual_sale = (await db.get_setting("manual_sale_enabled", "1")) == "1"
    rows = [[("🇩🇪 Hetzner Cloud", "buy:provider:hetzner")]]
    if manual_sale:
        rows.append([(manual_sale_label, "buy:provider:manual")])
    rows.append([("برگشت", "home")])
    await cq.message.edit_text(text, reply_markup=kb(rows))
    await cq.answer()


@router.callback_query(F.data.startswith("buy:provider:"))
async def buy_provider(cq: CallbackQuery, db: DB, state: FSMContext):
    provider = cq.data.split(":")[-1]
    await state.update_data(provider=provider)
    await state.set_state(BuyFlow.country)

    text = f"{glass_header('انتخاب کشور')}\n{GLASS_DOT} لطفاً کشور را انتخاب کنید:"

    # Manual sale: show only countries that have active manual plans
    if (provider or "").lower() == "manual":
        try:
            countries = await db.list_plan_countries("manual")
        except Exception:
            countries = ["IR"]
        if not countries:
            await cq.message.edit_text(
                f"{glass_header('انتخاب کشور')}\n{GLASS_DOT} فعلاً پلن دستی فعالی وجود ندارد.",
                reply_markup=kb([[("برگشت", "buy:start")]]),
            )
            return await cq.answer()
        btns = []
        for cc in countries:
            name = COUNTRY_NAMES.get(cc, cc)
            flag = {"DE": "🇩🇪", "FI": "🇫🇮", "US": "🇺🇸", "SG": "🇸🇬", "IR": "🇮🇷"}.get(cc, "🌍")
            btns.append((f"{flag} {name}", f"buy:country:{cc}"))
        rows: List[List[Tuple[str, str]]] = []
        for i in range(0, len(btns), 2):
            rows.append(btns[i:i+2])
        rows.append([("برگشت", "buy:start")])
        await cq.message.edit_text(text, reply_markup=kb(rows))
        return await cq.answer()

    # Hetzner: show only enabled countries (configurable from admin panel).
    cfg = await get_countries_enabled_cfg(db)
    enabled = [cc for cc, v in cfg.items() if int(v) == 1]

    # Keep stable order based on COUNTRY_LOCATIONS declaration
    enabled = [cc for cc in COUNTRY_LOCATIONS.keys() if cc in enabled]

    if not enabled:
        await cq.message.edit_text(
            f"{glass_header('انتخاب کشور')}\n{GLASS_DOT} فعلاً هیچ کشوری برای فروش فعال نیست.",
            reply_markup=kb([[("برگشت", "buy:start")]]),
        )
        return await cq.answer()

    # Build rows (2 per row)
    btns = []
    for cc in enabled:
        name = COUNTRY_NAMES.get(cc, cc)
        flag = {"DE": "🇩🇪", "FI": "🇫🇮", "US": "🇺🇸", "SG": "🇸🇬"}.get(cc, "🌍")
        btns.append((f"{flag} {name}", f"buy:country:{cc}"))

    rows = []
    for i in range(0, len(btns), 2):
        rows.append(btns[i:i+2])
    rows.append([("برگشت", "buy:start")])

    await cq.message.edit_text(text, reply_markup=kb(rows))
    await cq.answer()
@router.callback_query(F.data.startswith("buy:country:"))
async def buy_country(cq: CallbackQuery, db: DB, state: FSMContext):
    country = cq.data.split(":")[-1]
    data = await state.get_data()
    provider = (data.get('provider') or 'hetzner').strip().lower()

    # Manual provider: skip OS/group steps and show manual plans directly
    if provider == "manual":
        await state.update_data(country=country, location="manual", os="manual", server_type_group="manual")
        await state.set_state(BuyFlow.plan)

        plans = await db.list_plans("manual", country, "manual")
        if not plans:
            await cq.message.edit_text(
                f"{glass_header('پلنی وجود ندارد')}\n{GLASS_DOT} فعلاً هیچ پلن دستی‌ای تعریف نشده.",
                reply_markup=kb([[("برگشت", "buy:start")]]),
            )
            return await cq.answer()

        # Reuse the same rendering style as plan list (compact buttons + detailed text)
        lines = []
        btn_rows = []
        for idx, p in enumerate(plans, start=1):
            title = p.get("title") or f"Plan {p.get('id')}"
            st = p.get("server_type") or "-"
            price = fmt_irt(p.get("price_monthly_irt") or 0)

            tl = p.get("traffic_limit_gb") or 0
            tl_txt = "نامحدود" if int(tl) == 0 else f"{int(tl)}GB"

            lines.append(
                f"🔹 <b>PLAN{idx}</b> | {htmlesc(title)}\n"
                f"• کد: <code>{htmlesc(str(st))}</code>\n"
                f"• ترافیک: {htmlesc(tl_txt)}\n"
                f"• قیمت: <b>{price}</b>"
            )
            btn_rows.append([(f"🔵 PLAN{idx}", f"buy:plan:{p['id']}")])

        btn_rows.append([("برگشت", "buy:start")])

        await cq.message.edit_text(
            f"{glass_header('انتخاب پلن: ترافیک تمامی پلن ها 10 گیگ میباشد میتوانید بعد از خرید از قسمت سفارش های من ترافیک اضافه برای سرویس خود خریداری نمایید قیمت هر یک ترابایت ترافیک 700,000 هزار تومان میباشد اپلود رایگان و این قیمت فقط برای دانلود هست')}\n" + "\n\n".join(lines),
            reply_markup=kb(btn_rows),
            parse_mode="HTML",
        )
        return await cq.answer()

    # Hetzner provider flow
    locs = list_locations_for_country(country)
    await state.update_data(country=country)
    await state.set_state(BuyFlow.location)
    if not locs:
        await cq.message.edit_text("این کشور فعلاً لوکیشن ندارد.", reply_markup=kb([[("برگشت","buy:start")]]))
        return await cq.answer()
    rows = [[(f"📍 {location_label(loc)}", f"buy:loc:{loc}")] for loc in locs]
    rows.append([("برگشت", "buy:start")])
    await cq.message.edit_text(f"{glass_header('انتخاب لوکیشن')}\n{GLASS_DOT} لوکیشن موردنظر:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("buy:loc:"))
async def buy_location(cq: CallbackQuery, state: FSMContext):
    loc = cq.data.split(":")[-1]
    await state.update_data(location=loc)
    await state.set_state(BuyFlow.os)

    client = hclient()
    os_rows = []
    for os_name in REQUESTED_OS:
        im = find_matching_image(client, os_name)
        if im:
            os_rows.append([(f"🧊 {os_name} ✅", f"buy:os:{os_name}")])
        else:
            os_rows.append([(f"🧊 {os_name} ❌", "noop")])
    os_rows.append([("برگشت", "buy:start")])
    await cq.message.edit_text(
        f"{glass_header('انتخاب سیستم‌عامل')}\n{GLASS_DOT} فقط گزینه‌های ✅ قابل ساخت هستند.",
        reply_markup=kb(os_rows)
    )
    await cq.answer()

@router.callback_query(F.data == "noop")
async def noop(cq: CallbackQuery):
    await cq.answer("این سیستم‌عامل در Hetzner Cloud برای ساخت خودکار موجود نیست.", show_alert=True)


@router.callback_query(F.data.startswith("buy:os:"))
async def buy_os(cq: CallbackQuery, db: DB, state: FSMContext):
    os_name = cq.data.split(":")[-1]
    await state.update_data(os=os_name)

    data = await state.get_data()
    country = data.get("country", "")

    await state.set_state(BuyFlow.server_type_group)

    # Server-type groups (configurable from admin panel per location)
    location = data.get('location','')
    enabled_keys = await allowed_group_keys_for_location_db(db, country, location)
    groups = []
    for label, key, _types in SERVER_TYPE_GROUPS:
        if key in enabled_keys:
            groups.append((label, key))

    if not groups:
        await cq.message.edit_text(
            f"{glass_header('سرور تایپ')}\n{GLASS_DOT} برای این لوکیشن فعلاً هیچ سرور تایپی فعال نیست.",
            reply_markup=kb([[('برگشت','buy:start')]]),
        )
        return await cq.answer()


    rows = [[(f"🧩 {label}", f"buy:grp:{key}")] for (label, key) in groups]
    rows.append([("برگشت", "buy:start")])

    await cq.message.edit_text(
        f"{glass_header('سرور تایپ')}\n{GLASS_DOT} معماری/سری سرور را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()



@router.callback_query(F.data == "buy:back:grps")
async def buy_back_to_groups(cq: CallbackQuery, db: DB, state: FSMContext):
    """
    Go back one step from plan list to server-type group selection,
    using whatever is currently stored in state (country/location/os).
    """
    data = await state.get_data()
    country = data.get("country", "")
    location = data.get("location", "")
    if not country or not location:
        await cq.message.edit_text("❌ اطلاعات خرید ناقص است. دوباره از ابتدا شروع کن.", reply_markup=kb([[("🏠 منوی اصلی","home")]]))
        await state.clear()
        return await cq.answer()

    await state.set_state(BuyFlow.server_type_group)

    enabled_keys = await allowed_group_keys_for_location_db(db, country, location)
    groups = [(label, key) for (label, key, _types) in SERVER_TYPE_GROUPS if key in enabled_keys]

    if not groups:
        await cq.message.edit_text(
            f"{glass_header('سرور تایپ')}\n{GLASS_DOT} برای این لوکیشن فعلاً هیچ سرور تایپی فعال نیست.",
            reply_markup=kb([[('برگشت','buy:start')]]),
        )
        return await cq.answer()

    rows = [[(f"🧩 {label}", f"buy:grp:{key}")] for (label, key) in groups]
    rows.append([("برگشت", "buy:start")])
    await cq.message.edit_text(
        f"{glass_header('سرور تایپ')}\n{GLASS_DOT} معماری/سری سرور را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("buy:grp:"))
async def buy_server_type_group(cq: CallbackQuery, db: DB, state: FSMContext):
    grp = cq.data.split(":")[-1]
    await state.update_data(server_type_group=grp)
    await state.set_state(BuyFlow.plan)

    data = await state.get_data()
    await ensure_card_purchase_support(db)

    plans = await db.list_plans(data["provider"], data["country"], data["location"])

    hourly_global = (await db.get_setting("hourly_buy_enabled", "0")) == "1"
    if not plans:
        await cq.message.edit_text(
            f"{glass_header('پلنی وجود ندارد')}\n{GLASS_DOT} فعلاً برای این لوکیشن پلنی تعریف نشده.",
            reply_markup=kb([[("برگشت","buy:back:grps")]])
        )
        return await cq.answer()

    # Filter by selected group (prefix-based)
    plans = [p for p in plans if server_type_group_key(p.get("server_type","")) == grp]
    if not plans:
        await cq.message.edit_text(
            f"{glass_header('پلنی وجود ندارد')}\n{GLASS_DOT} برای این سرور تایپ فعلاً پلنی تعریف نشده.",
            reply_markup=kb([[("برگشت","buy:back:grps")]])
        )
        return await cq.answer()

    def _fmt_traffic(gb: int) -> str:
        """Format traffic in a user-friendly way.

        We use *decimal* TB (1000GB=1TB) because Hetzner traffic is marketed as 20TB, 1TB, ...
        and plans are usually stored as 20000GB, etc.
        """
        try:
            gb = int(gb)
        except Exception:
            gb = 0
        if gb <= 0:
            return "UNLIMITED"
        if gb >= 1000:
            tb = gb / 1000.0
            # show 20TB instead of 20.0TB
            if abs(tb - round(tb)) < 1e-9:
                return f"{int(round(tb))}TB"
            return f"{tb:.1f}TB"
        return f"{gb}GB"

    def _fmt_disk(gb: Any) -> str:
        try:
            gb_i = int(float(gb))
        except Exception:
            return f"{gb}GB"
        if gb_i >= 1024 and gb_i % 1024 == 0:
            return f"{gb_i//1024}TB"
        if gb_i >= 1024:
            return f"{gb_i/1024.0:.1f}TB"
        return f"{gb_i}GB"

    client = hclient()

    # Build a readable summary text (instead of long button titles)
    lines = []
    btn_rows = []

    for idx, p in enumerate(plans, start=1):
        stype = (p.get("server_type") or "").upper()
        specs = get_server_type_specs(client, p.get("server_type", "")) or {}
        vcpu = specs.get("vcpu") or p.get("vcpu") or "?"
        ram = specs.get("ram_gb") or p.get("ram_gb") or "?"
        disk = specs.get("disk_gb") or p.get("disk_gb") or "?"
        traffic = _fmt_traffic(int(p.get("traffic_limit_gb") or 0))

        # Best-effort "can create" status
        available = server_type_available_in_location(client, p.get("server_type", ""), data.get("location", ""))
        eff = await plan_effective_prices(db, p)
        pm = eff['monthly_irt']
        ph = eff['hourly_irt']

        # Text block (as requested)
        lines.append(
            f"🔵PLAN{idx}: {stype}:{vcpu}vCPU {ram}GBram | {_fmt_disk(disk)} SSD | TRAFFIC: {traffic}"
            f" | ماهانه: {money(int(pm))}"
            + (f" | ساعتی: {money(int(ph))}"
               if (hourly_global and p.get('hourly_enabled') and int(p.get('price_hourly_irt') or 0) > 0)
               else "")
        )

        # Buttons: just plan name + status
        if available:
            btn_rows.append([(f"🔵 PLAN{idx}", f"buy:plan:{p['id']}")])
        else:
            btn_rows.append([(f"🔵 PLAN{idx} ❌", "noop")])

    btn_rows.append([("برگشت", "buy:back:grps")])

    header = "🫧 انتخاب پلن\n━━━━━━━━━━━━━━━"
    text_block = "\n\n".join(lines)
    await cq.message.edit_text(
        f"{header}\n{text_block}",
        reply_markup=kb(btn_rows),
    )
    await cq.answer()

@router.callback_query(F.data.startswith("buy:plan:"))

async def buy_plan(cq: CallbackQuery, db: DB, state: FSMContext):
    plan_id = int(cq.data.split(":")[-1])
    plan = await db.get_plan(plan_id)
    if not plan or not plan["is_active"]:
        return await cq.answer("پلن نامعتبر است.", show_alert=True)
    await state.update_data(plan_id=plan_id)
    await state.set_state(BuyFlow.name)
    await cq.message.edit_text(
        f"{glass_header('نام سرور')}\n{GLASS_DOT} نام سرور را بفرست (مثلاً: DELTA)\n{GLASS_DOT} فقط حروف/عدد/خط تیره.",
        reply_markup=kb([[("برگشت","buy:start")]])
    )
    await cq.answer()

@router.message(BuyFlow.name)
async def buy_name(msg: Message, db: DB, state: FSMContext):
    name = safe_hostname(msg.text or "")
    if not name:
        return await msg.answer("❌ نام معتبر نیست. فقط حروف/عدد/خط تیره و طول 1 تا 63.")
    await state.update_data(server_name=name)
    await state.set_state(BuyFlow.billing)

    data = await state.get_data()
    plan = await db.get_plan(data["plan_id"])
    if not plan:
        return await msg.answer("پلن پیدا نشد.", reply_markup=kb([[("🏠 منوی اصلی","home")]]))

    eff = await plan_effective_prices(db, plan)
    rows = [[(f"🗓 ماهانه ({money(eff['monthly_irt'])})", "buy:billing:monthly")]]
    hourly_global = (await db.get_setting("hourly_buy_enabled", "0")) == "1"
    if hourly_global and plan.get('hourly_enabled') and eff['hourly_irt'] > 0:
        rows.append([(f"⏱ ساعتی ({money(plan['price_hourly_irt'])}/ساعت)", "buy:billing:hourly")])
    rows.append([("برگشت", "buy:start")])

    await msg.answer(f"{glass_header('نوع خرید')}\n{GLASS_DOT} ماهانه یا ساعتی؟", reply_markup=kb(rows))

HOURLY_MIN_WALLET_TO_BUY = 100_000
HOURLY_WARN_BALANCE = 20_000
HOURLY_CUTOFF_BALANCE = 5_000


@router.callback_query(F.data.startswith("buy:billing:"))
async def buy_billing(cq: CallbackQuery, db: DB, state: FSMContext):
    """Pick billing mode.

    Business rules (hourly):
      - User must have at least 100,000 Toman wallet balance to start an hourly server.
    """
    billing = cq.data.split(":")[-1]

    if billing == "hourly":
        u = await db.get_user(cq.from_user.id)
        bal = int(u["balance_irt"]) if u else 0
        if bal < HOURLY_MIN_WALLET_TO_BUY:
            return await cq.answer(
                "ابتدا موجودی خود را به بالای 100 هزار افزایش دهید سپس اقدام به خرید نمایید.",
                show_alert=True,
            )

    await state.update_data(billing=billing)
    await state.set_state(BuyFlow.pay_method)
    await cq.message.edit_text(
        f"{glass_header('پرداخت')}\n{GLASS_DOT} روش پرداخت را انتخاب کن:",
        reply_markup=kb([
            [("💳 پرداخت از موجودی", "buy:pay:wallet")],
            [("🏦 کارت به کارت", "buy:pay:card")],
            [("برگشت", "buy:start")],
        ])
    )
    await cq.answer()

async def _finalize_purchase(cq: CallbackQuery, db: DB, state: FSMContext, pay_method: str):
    data = await state.get_data()
    user_id = cq.from_user.id
    await ensure_card_purchase_support(db)

    # guard: state expired
    if "plan_id" not in data:
        await cq.answer("خرید منقضی شده. دوباره از ابتدا شروع کن.", show_alert=True)
        await state.clear()
        return

    plan = await db.get_plan(int(data["plan_id"]))
    if not plan:
        return await cq.answer("پلن پیدا نشد.", show_alert=True)

    u = await db.get_user(user_id)
    if not u or u["is_blocked"]:
        return await cq.answer("دسترسی ندارید.", show_alert=True)

    billing = data.get("billing", "monthly")  # monthly|hourly
    if billing == "monthly":
        eff = await plan_effective_prices(db, plan)
        amount = int(eff['monthly_irt'] or 0)
        duration_days = 30
    else:
        eff = await plan_effective_prices(db, plan)
        amount = int(eff['hourly_irt'] or 0)
        duration_days = 30

    if amount <= 0:
        return await cq.answer("قیمت پلن تنظیم نشده.", show_alert=True)

    provider = (data.get("provider") or plan.get("provider") or "hetzner").strip().lower()
    loc = data.get("location", "")
    if provider == "hetzner":
        if loc and not hcloud_server_type_available(loc, plan["server_type"]):
            return await cq.answer("⛔️ این پلن فعلاً قابل ساخت نیست (استوک/محدودیت).", show_alert=True)

    # ----- payment -----
    if pay_method == "wallet":
        if u["balance_irt"] < amount:
            await cq.message.edit_text(
                f"{glass_header('عدم موجودی')}\n"
                f"{GLASS_DOT} موجودی شما کافی نیست.\n"
                f"{GLASS_DOT} مبلغ: {money(amount)}\n"
                f"{GLASS_DOT} موجودی: {money(u['balance_irt'])}",
                reply_markup=kb([[("برگشت","buy:start")],[("➕ افزایش موجودی","me:topup")]])
            )
            await state.clear()
            return
        await db.add_balance(user_id, -amount)
        inv_id = await db.create_invoice(user_id, amount, "wallet", f"Purchase {plan['server_type']} ({billing})", "paid")
    else:
        inv_id = await db.create_invoice(user_id, amount, "card", f"Purchase {plan['server_type']} ({billing})", "pending")

        payload = {
            "type": "manual" if provider == "manual" else "vps",
            "provider": data.get("provider", ""),
            "country": data.get("country", ""),
            "location": data.get("location", ""),
            "os": data.get("os", ""),
            "server_name": data.get("server_name", ""),
            "plan_id": int(data["plan_id"]),
            "billing": billing,
        }
        await db.create_card_purchase(inv_id, user_id, json.dumps(payload, ensure_ascii=False))

        await state.set_state(AwaitReceipt.invoice_id)
        await state.update_data(invoice_id=inv_id)

        card_text = await get_card_text(db)
        summary = build_purchase_summary(plan, payload)

        await cq.message.edit_text(
            f"{glass_header('فاکتور کارت به کارت')}\n"
            f"{GLASS_DOT} شماره فاکتور: <code>#{inv_id}</code>\n"
            f"{GLASS_LINE}\n"
            f"{summary}\n"
            f"{GLASS_LINE}\n"
            f"{GLASS_DOT} مبلغ قابل پرداخت: {money(amount)}\n"
            f"{GLASS_DOT} {card_text}\n\n"
            f"{GLASS_DOT} رسید پرداخت را همینجا ارسال کن (عکس یا فایل).",
            reply_markup=kb([[("برگشت","home")]]),
            parse_mode="HTML"
        )

        for aid in ADMIN_IDS:
            try:
                await cq.bot.send_message(
                    aid,
                    f"📥 فاکتور کارت‌به‌کارت ({'فروش دستی' if provider=='manual' else 'خرید VPS'}) ایجاد شد\n"
                    f"کاربر: {user_id}\n"
                    f"مبلغ: {money(amount)}\n"
                    f"فاکتور: #{inv_id}\n"
                    f"IP: (بعد از تایید ساخته می‌شود)",
                    reply_markup=kb([
                        [("✅ تایید", f"admin:pay:approve:{inv_id}")],
                        [("❌ رد", f"admin:pay:reject:{inv_id}")]
                    ])
                )
            except Exception:
                pass

        return

    # ----- create server / manual order -----
    if provider == "manual":
        now = int(time.time())
        expires_at = int((datetime.fromtimestamp(now, TZ) + timedelta(days=duration_days)).timestamp())
        traffic_gb = int(plan.get("traffic_limit_gb") or 0)
        oid = await db.create_order(
            user_id=user_id,
            plan_id=int(plan.get("id") or 0),
            provider="manual",
            country_code=data.get("country",""),
            name=data.get("server_name") or "manual",
            server_type=plan.get("server_type"),
            image_name=data.get("os") or "-",
            location_name=data.get("location") or (plan.get("location_name") or "manual"),
            billing_mode="monthly",
            traffic_limit_gb=traffic_gb,
            expires_at=expires_at,
            status="pending_manual",
            price_monthly_irt=int(eff['monthly_irt'] or 0),
            price_hourly_irt=0,
        )
        await db.attach_invoice_to_order(inv_id, oid)

        # notify admins to deliver
        for aid in ADMIN_IDS:
            try:
                await cq.bot.send_message(
                    aid,
                    f"🧾 سفارش دستی جدید\nکاربر: {user_id}\nسرویس: #{oid}\nپلن: {plan.get('server_type')}\nلوکیشن: {data.get('location')}\nOS: {data.get('os')}\nپرداخت: {pay_method}",
                    reply_markup=kb([[('✅ تحویل و ارسال', f'admin:manual:deliver:{oid}')],[('⬅️ پنل مدیریت','admin:home')]]),
                )
            except Exception:
                pass

        await cq.message.edit_text(
            f"{glass_header('ثبت شد')}\n{GLASS_DOT} سفارش دستی شما ثبت شد و پس از ساخت توسط ربات برای شما ارسال می‌شود.\n{GLASS_DOT} شماره سرویس: <code>#{oid}</code>",
            parse_mode="HTML",
            reply_markup=kb([[('📦 سفارش‌های من','me:orders')],[('🏠 منوی اصلی','home')]]),
        )
        await state.clear()
        return

    # ----- create hetzner server -----
    client = hclient()
    img = find_matching_image(client, data["os"])
    if not img:
        if pay_method == "wallet":
            await db.add_balance(user_id, amount)
        await cq.message.edit_text("❌ این سیستم‌عامل برای هتزنر موجود نیست.", reply_markup=kb([[("برگشت","buy:start")]]))
        await state.clear()
        return

    await _edit_progress(cq.message, 10, 'در حال آماده‌سازی سفارش…')

    await _edit_progress(cq.message, 30, 'انتخاب ایمیج سیستم‌عامل…')

    try:
        await _edit_progress(cq.message, 70, 'در حال ساخت سرور روی Hetzner…')
        server_id, ip4, root_pw = hcloud_create_server(
            name=data["server_name"],
            server_type=plan["server_type"],
            image_id=img.id,
            location_name=data["location"],
        )
        # While Hetzner is provisioning, we show a progress percent that moves with real time/status.
        ip_ready, st = await hcloud_wait_running_with_progress(cq.message, server_id, timeout_sec=240, start_percent=75, end_percent=99)
        if ip_ready:
            ip4 = ip_ready
        await _edit_progress(cq.message, 100, 'سرور آماده شد ✅')
    except Exception as e:
        if pay_method == "wallet":
            await db.add_balance(user_id, amount)
        await cq.message.edit_text(
            f"{glass_header('خطا در ساخت')}\n{GLASS_DOT} ساخت سرور ناموفق بود.\n{GLASS_DOT} خطا: {e}",
            reply_markup=kb([[("🏠 منوی اصلی","home")]])
        )
        await state.clear()
        return

    now = int(time.time())
    expires_at = int((datetime.fromtimestamp(now, TZ) + timedelta(days=duration_days)).timestamp())

    traffic_gb = int(plan.get("traffic_limit_gb") or 0)
    eff = await plan_effective_prices(db, plan)

    oid = await db.create_order(
        user_id=user_id,
        plan_id=int(plan["id"]),
        provider=data.get("provider",""),
        country=data.get("country",""),
        location=data.get("location",""),
        os_name=data.get("os",""),
        server_type=plan["server_type"],
        hcloud_server_id=str(server_id),
        ip4=str(ip4),
        root_password=str(root_pw),
        billing_mode=billing,
        traffic_limit_gb=traffic_gb,
        expires_at=expires_at,
        status="active",
        price_monthly_irt=int(eff['monthly_irt'] or 0),
        price_hourly_irt=int(eff['hourly_irt'] or 0),
    )
    await db.attach_invoice_to_order(inv_id, oid)

    # Initialize hourly counters to prevent huge retroactive charges on old deployments.
    if (billing or "").lower() == "hourly" and int(plan.get("price_hourly_irt") or 0) > 0:
        try:
            current_hour = int(now // 3600)
            await db.set_last_billed_hour(oid, current_hour)
            # last_warn_at=0 (no warning yet)
            await db.update_order_hourly_tick(oid, now, 0)
        except Exception:
            pass

    await cq.message.edit_text(
        f"{glass_header('تحویل سرویس')}\n"
        f"{GLASS_DOT} IP: <code>{ip4}</code>\n"
        f"{GLASS_DOT} USER: <code>root</code>\n"
        f"{GLASS_DOT} PASS: <code>{root_pw}</code>\n"
        f"{GLASS_DOT} انقضا: {fmt_dt(expires_at)}\n",
        parse_mode="HTML",
        reply_markup=kb([[("📦 سفارش‌های من","me:orders")],[("🏠 منوی اصلی","home")]])
    )

    # Send purchase report to admins (for both wallet and card purchases)
    await send_admin_purchase_report(
        cq.bot,
        db,
        user_id=user_id,
        order_id=oid,
        ip4=str(ip4),
        pay_method=pay_method,
        amount_irt=amount,
        plan_name=str(plan['server_type']),
        billing=str(billing),
    )

    await state.clear()

@router.callback_query(F.data == "buy:pay:wallet")
async def buy_pay_wallet(cq: CallbackQuery, db: DB, state: FSMContext):
    await _finalize_purchase(cq, db, state, "wallet")
    await cq.answer()

@router.callback_query(F.data == "buy:pay:card")
async def buy_pay_card(cq: CallbackQuery, db: DB, state: FSMContext):
    await _finalize_purchase(cq, db, state, "card")
    await cq.answer()

# -------------------------
# Profile / wallet
# -------------------------
@router.callback_query(F.data == "me:profile")
async def me_profile(cq: CallbackQuery, db: DB):
    u = await db.get_user(cq.from_user.id)
    orders = await db.list_user_orders(cq.from_user.id)
    username = f"@{cq.from_user.username}" if cq.from_user.username else "-"
    balance = u["balance_irt"] if u else 0
    text = (
        f"{glass_header('مشخصات حساب')}\n"
        f"{GLASS_DOT} نام کاربری: {htmlesc(username)}\n"
        f"{GLASS_DOT} ایدی عددی: <code>{cq.from_user.id}</code>\n"
        f"{GLASS_DOT} موجودی: {htmlesc(money(balance))}\n"
        f"{GLASS_DOT} تعداد سرویس‌ها: {len(orders)}\n"
    )
    await cq.message.edit_text(
        text,
        reply_markup=kb([[("➕ افزایش موجودی","me:topup")],[("برگشت","home")]]),
        parse_mode="HTML"
    )
    await cq.answer()

@router.callback_query(F.data == "me:topup")
async def me_topup(cq: CallbackQuery, state: FSMContext):
    await state.set_state(TopUpFlow.amount)
    await cq.message.edit_text(
        f"{glass_header('افزایش موجودی')}\n"
        f"{GLASS_DOT} مبلغ شارژ را به تومان ارسال کن.\n"
        f"{GLASS_DOT} مثال: <code>200000</code>",
        parse_mode="HTML",
        reply_markup=kb([[("برگشت","home")]])
    )
    await cq.answer()

@router.callback_query(F.data == "me:renew")
async def me_renew(cq: CallbackQuery, db: DB):
    orders = await db.list_user_orders(cq.from_user.id)
    rows = []
    for o in orders:
        if o["billing_mode"] != "monthly" or o["status"] not in ("active","suspended"):
            continue
        label = o["ip4"] or f"Order#{o['id']}"
        rows.append([(f"♻️ {label} | {o['server_type']}", f"renew:pick:{o['id']}")])
    if not rows:
        await cq.message.edit_text(f"{glass_header('تمدید')}\n{GLASS_DOT} سرویس ماهانه قابل تمدید ندارید.", reply_markup=kb([[("برگشت","home")]]))
        return await cq.answer()
    rows.append([("برگشت","home")])
    await cq.message.edit_text(f"{glass_header('تمدید سرویس')}\n{GLASS_DOT} سرویس را انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("renew:pick:"))
async def renew_pick(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    amount = int(o["price_monthly_irt"])
    u = await db.get_user(cq.from_user.id)
    text = (
        f"{glass_header('تمدید ماهانه')}\n"
        f"{GLASS_DOT} IP: `{o['ip4']}`\n"
        f"{GLASS_DOT} مبلغ تمدید: {money(amount)}\n"
        f"{GLASS_DOT} موجودی: {money(u['balance_irt'] if u else 0)}\n"
    )
    await cq.message.edit_text(
        text,
        reply_markup=kb([
            [("✅ پرداخت و تمدید", f"renew:pay:{oid}")],
            [("برگشت", f"order:view:{oid}")],
        ]),
        parse_mode="Markdown",
    )
    await cq.answer()

@router.callback_query(F.data.startswith("renew:pay:"))
async def renew_pay(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    amount = int(o["price_monthly_irt"])
    u = await db.get_user(cq.from_user.id)
    if not u or u["balance_irt"] < amount:
        return await cq.answer("موجودی کافی نیست.", show_alert=True)
    await db.add_balance(cq.from_user.id, -amount)
    await db.create_invoice(cq.from_user.id, amount, "wallet", f"Renew order#{oid}", "paid")
    new_exp = int((datetime.fromtimestamp(o["expires_at"], TZ) + timedelta(days=30)).timestamp())
    await db.update_order_status_and_expiry(oid, "active", new_exp)
    try:
        if o["hcloud_server_id"]:
            hcloud_power_action(int(o["hcloud_server_id"]), "poweron")
    except Exception:
        pass
    await cq.message.edit_text(f"{glass_header('تمدید شد')}\n{GLASS_DOT} تا {fmt_dt(new_exp)} تمدید شد.", reply_markup=kb([[("🏠 منوی اصلی","home")],[("📦 سفارش‌های من","me:orders")]]))
    await cq.answer()


# -------------------------
# Extra traffic (user)
# -------------------------
@router.callback_query(F.data.startswith("traffic:order:"))
async def traffic_order(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    cc = (o.get("country_code") or "").upper().strip() or LOCATION_TO_COUNTRY.get((o.get("location_name") or "").strip(), "")
    if not cc:
        await cq.message.edit_text(f"{glass_header('حجم اضافه')}\n{GLASS_DOT} کشور سرویس مشخص نیست.", reply_markup=kb([[('برگشت', f'order:view:{oid}')]]))
        return await cq.answer()
    pkgs = await db.list_traffic_packages(cc, active_only=True)
    if not pkgs:
        await cq.message.edit_text(f"{glass_header('حجم اضافه')}\n{GLASS_DOT} برای این کشور پکیجی ثبت نشده.", reply_markup=kb([[('برگشت', f'order:view:{oid}')]]))
        return await cq.answer()
    rows = []
    for p in pkgs[:30]:
        title = p.get('title') or f"{p['volume_gb']}GB"
        rows.append([(f"➕ {title} | {p['volume_gb']}GB | {money(int(p['price_irt']))}", f"traffic:pkg:{oid}:{p['id']}")])
    rows.append([("برگشت", f"order:view:{oid}")])
    await cq.message.edit_text(
        f"{glass_header('حجم اضافه')}\n{GLASS_DOT} سرویس: <code>#{oid}</code>\n{GLASS_DOT} کشور: {cc}",
        reply_markup=kb(rows),
        parse_mode="HTML",
    )
    await cq.answer()


@router.callback_query(F.data.startswith("traffic:pkg:"))
async def traffic_pkg(cq: CallbackQuery, db: DB):
    _p = cq.data.split(":")
    oid = int(_p[2])
    pid = int(_p[3])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    pkg = await db.get_traffic_package(pid)
    if not pkg or not pkg.get('is_active'):
        return await cq.answer("پکیج نامعتبر است.", show_alert=True)
    title = pkg.get('title') or f"{pkg['volume_gb']}GB"
    amount = int(pkg['price_irt'] or 0)
    text = (
        f"{glass_header('تایید خرید حجم')}\n"
        f"{GLASS_DOT} سرویس: <code>#{oid}</code>\n"
        f"{GLASS_DOT} پکیج: {htmlesc(title)}\n"
        f"{GLASS_DOT} حجم: {pkg['volume_gb']}GB\n"
        f"{GLASS_DOT} مبلغ: {money(amount)}\n"
    )
    await cq.message.edit_text(
        text,
        parse_mode="HTML",
        reply_markup=kb([
            [("💳 پرداخت از موجودی", f"traffic:pay:wallet:{oid}:{pid}")],
            [("🏦 کارت به کارت", f"traffic:pay:card:{oid}:{pid}")],
            [("برگشت", f"traffic:order:{oid}")],
        ]),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("traffic:pay:wallet:"))
async def traffic_pay_wallet(cq: CallbackQuery, db: DB):
    _p = cq.data.split(":")
    oid = int(_p[3])
    pid = int(_p[4])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    pkg = await db.get_traffic_package(pid)
    if not pkg or not pkg.get('is_active'):
        return await cq.answer("پکیج نامعتبر است.", show_alert=True)
    amount = int(pkg['price_irt'] or 0)
    u = await db.get_user(cq.from_user.id)
    bal = int(u['balance_irt']) if u else 0
    if bal < amount:
        await cq.message.edit_text(
            f"{glass_header('عدم موجودی')}\n{GLASS_DOT} موجودی کافی نیست.\n{GLASS_DOT} مبلغ: {money(amount)}\n{GLASS_DOT} موجودی: {money(bal)}",
            reply_markup=kb([[('برگشت', f'traffic:pkg:{oid}:{pid}')],[('➕ افزایش موجودی','me:topup')]]),
        )
        return await cq.answer()
    await db.add_balance(cq.from_user.id, -amount)
    inv_id = await db.create_invoice(cq.from_user.id, amount, 'wallet', f"Extra traffic order#{oid}", 'paid')
    await db.attach_invoice_to_order(inv_id, oid)
    await db.add_order_traffic_limit(oid, int(pkg['volume_gb']))
    await db.create_traffic_purchase(user_id=cq.from_user.id, order_id=oid, package_id=pid, volume_gb=int(pkg['volume_gb']), price_irt=amount, invoice_id=inv_id, status='paid')
    await cq.message.edit_text(
        f"{glass_header('حجم اضافه')}\n✅ خرید انجام شد و {pkg['volume_gb']}GB به سقف ترافیک سرویس اضافه شد.",
        reply_markup=kb([[('برگشت', f'order:view:{oid}')]]),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("traffic:pay:card:"))
async def traffic_pay_card(cq: CallbackQuery, db: DB, state: FSMContext):
    _p = cq.data.split(":")
    oid = int(_p[3])
    pid = int(_p[4])
    await ensure_card_purchase_support(db)
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    pkg = await db.get_traffic_package(pid)
    if not pkg or not pkg.get('is_active'):
        return await cq.answer("پکیج نامعتبر است.", show_alert=True)
    amount = int(pkg['price_irt'] or 0)
    inv_id = await db.create_invoice(cq.from_user.id, amount, 'card', f"Extra traffic order#{oid}", 'pending')
    await db.attach_invoice_to_order(inv_id, oid)
    payload = {"type": "traffic", "order_id": oid, "package_id": pid, "volume_gb": int(pkg['volume_gb'])}
    await db.create_card_purchase(inv_id, cq.from_user.id, json.dumps(payload, ensure_ascii=False))

    await state.set_state(AwaitReceipt.invoice_id)
    await state.update_data(invoice_id=inv_id)

    card_text = await get_card_text(db)
    title = pkg.get('title') or f"{pkg['volume_gb']}GB"
    await cq.message.edit_text(
        f"{glass_header('فاکتور کارت به کارت')}\n"
        f"{GLASS_DOT} شماره فاکتور: <code>#{inv_id}</code>\n"
        f"{GLASS_DOT} سرویس: <code>#{oid}</code>\n"
        f"{GLASS_DOT} پکیج: {htmlesc(title)} ({pkg['volume_gb']}GB)\n"
        f"{GLASS_DOT} مبلغ: {money(amount)}\n"
        f"{GLASS_DOT} {card_text}\n\n"
        f"{GLASS_DOT} رسید پرداخت را همینجا ارسال کن.",
        parse_mode="HTML",
        reply_markup=kb([[('برگشت', f'order:view:{oid}')]]),
    )

    for aid in ADMIN_IDS:
        try:
            await cq.bot.send_message(
                aid,
                f"📥 فاکتور کارت‌به‌کارت (حجم اضافه)\n"
                f"کاربر: {cq.from_user.id}\n"
                f"سرویس: #{oid}\n"
                f"مبلغ: {money(amount)}\n"
                f"فاکتور: #{inv_id}",
                reply_markup=kb([[('✅ تایید', f'admin:pay:approve:{inv_id}')],[('❌ رد', f'admin:pay:reject:{inv_id}')]]),
            )
        except Exception:
            pass
    await cq.answer()

# -------------------------
# Orders
# -------------------------
@router.callback_query(F.data == "me:orders")
async def me_orders(cq: CallbackQuery, db: DB):
    orders = await db.list_user_orders(cq.from_user.id)
    if not orders:
        await cq.message.edit_text(f"{glass_header('سفارش‌های من')}\n{GLASS_DOT} سفارشی ندارید.", reply_markup=kb([[("برگشت","home")]]))
        return await cq.answer()
    rows = []
    for o in orders:
        label = o["ip4"] or f"Order#{o['id']}"
        rows.append([(f"🧊 {label} | {o['status']}", f"order:view:{o['id']}")])
    rows.append([("برگشت","home")])
    await cq.message.edit_text(f"{glass_header('سفارش‌های من')}\n{GLASS_DOT} روی آی‌پی بزن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("order:view:"))
async def order_view(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    traffic_txt = "نامحدود" if o["traffic_limit_gb"] <= 0 else f"{o['traffic_used_gb']:.1f}/{o['traffic_limit_gb']} GB"

    # derive country for extra-traffic list
    cc = (o.get("country_code") or "").upper().strip()
    if not cc:
        cc = LOCATION_TO_COUNTRY.get((o.get("location_name") or "").strip(), "")
    has_extra = False
    try:
        if cc:
            enabled = (await db.get_setting(f"extra_traffic_enabled_{cc}", "1")) == "1"
            if enabled:
                pkgs = await db.list_traffic_packages(cc, active_only=True)
                has_extra = bool(pkgs)
            else:
                has_extra = False
    except Exception:
        has_extra = False
    # Manual orders can optionally have a separate monitoring panel.
    mon_url = (o.get('monitoring_url') or '').strip()
    mon_user = (o.get('monitoring_user') or '').strip()
    mon_pass = (o.get('monitoring_pass') or '').strip()

    mon_info = ""
    if mon_url:
        mon_info += f"{GLASS_DOT} 🔐 مشخصات ورود مانیتورینگ:\n"
        mon_info += f"{GLASS_DOT} لینک مانیتورینگ: {mon_url}\n"
        if mon_user:
            mon_info += f"{GLASS_DOT} نام کاربری مانیتورینگ: `{mon_user}`\n"
        if mon_pass:
            mon_info += f"{GLASS_DOT} رمز عبور مانیتورینگ: `{mon_pass}`\n"

    text = (
        f"{glass_header('جزئیات سفارش')}\n"
        f"{GLASS_DOT} IP: `{o['ip4'] or '-'}`\n"
        f"{mon_info}"
        f"{GLASS_DOT} نام: {o['name']}\n"
        f"{GLASS_DOT} پلن: {o['server_type']}\n"
        f"{GLASS_DOT} لوکیشن: {o['location_name']}\n"
        f"{GLASS_DOT} OS: {o['image_name']}\n"
        f"{GLASS_DOT} وضعیت: {o['status']}\n"
        f"{GLASS_DOT} انقضا: {fmt_dt(o['expires_at'])}\n"
        f"{GLASS_DOT} تعداد روز باقی مانده : {days_left_text(o)}\n"
        f"{GLASS_DOT} ترافیک: {traffic_txt}\n"
    )

    rows = []

    # تمدید (از منوی اصلی حذف شده و داخل هر سرویس است)
    if (o.get("billing_mode") == "monthly") and (o.get("status") in ("active","suspended")):
        rows.append([("♻️ تمدید سرویس", f"renew:pick:{oid}")])

    if has_extra:
        rows.append([("➕ خرید حجم اضافه", f"traffic:order:{oid}")])

    if mon_url:
        rows.append([("🌐 ورود به صفحه مانیتورینگ", f"url:{mon_url}")])

    is_manual = (str(o.get('provider') or '').lower() == 'manual') or (not o.get('hcloud_server_id'))

    if is_manual:
        # For manual deliveries: hide provider actions.
        
        rows += [
            [("🗑 حذف سرور", f"order:del:{oid}")],
            [("برگشت", "me:orders")]
        ]
    else:
        rows += [
            [("🔁 ریبلد کردن سرور", f"order:rebuild:{oid}")],
            [("🔐 بازیابی پسوورد روت", f"order:resetpw:{oid}")],
            [("⏻ خاموش کردن", f"order:off:{oid}"), ("⏽ روشن کردن", f"order:on:{oid}")],
            [("📊 نمایش حجم", f"order:traffic:{oid}")],
            [("🗑 حذف سرور", f"order:del:{oid}")],
            [("برگشت", "me:orders")]
        ]
    await cq.message.edit_text(text, reply_markup=kb(rows), parse_mode="Markdown")
    await cq.answer()


@router.callback_query(F.data.startswith("order:del:confirm:"))
async def order_delete_confirm(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)

    sid = o.get("hcloud_server_id")
    billing = (o.get("billing_mode") or "").lower()

    # settle hourly (including minutes) before delete
    # NOTE: Use last_hourly_charge_at (not "start of current hour") so we don't overcharge when the
    #       server was created mid-hour.
    extra_cost = 0
    if billing == "hourly" and int(o.get("price_hourly_irt") or 0) > 0:
        rate = int(o.get("price_hourly_irt") or 0)
        now = int(time.time())
        last_charge_at = int(o.get("last_hourly_charge_at") or 0)
        if last_charge_at <= 0:
            last_charge_at = int(o.get("purchased_at") or now)

        elapsed = max(0, now - last_charge_at)
        full_hours = int(elapsed // 3600)
        rem_secs = int(elapsed % 3600)
        minutes = int((rem_secs + 59) // 60)  # ceil to minute
        cost_full = int(full_hours * rate)
        cost_minutes = int(math.ceil((minutes * rate) / 60.0)) if minutes > 0 else 0
        extra_cost = int(cost_full + cost_minutes)

        u = await db.get_user(int(o["user_id"]))
        bal = int(u["balance_irt"]) if u else 0
        if extra_cost > 0 and bal < extra_cost:
            return await cq.answer("موجودی کافی برای تسویه دقایق استفاده نیست. لطفاً ابتدا شارژ کنید.", show_alert=True)

        if extra_cost > 0:
            await db.add_balance(int(o["user_id"]), -extra_cost)
            await db.create_invoice(int(o["user_id"]), -extra_cost, "wallet", f"Hourly settle on delete order#{oid}", "paid")
        # mark billed up to now to avoid later double-charge
        try:
            await db.update_order_hourly_tick(oid, now, int(o.get("last_warn_at") or 0))
            await db.set_last_billed_hour(oid, int(now // 3600))
        except Exception:
            pass

    # delete server at provider
    if sid:
        try:
            hcloud_delete_server(int(sid))
        except Exception:
            # still continue to mark deleted in DB
            pass

    await db.set_order_status(oid, "deleted")

    # report to admins
    try:
        uname = (cq.from_user.username or "")
        actor_name = f"{cq.from_user.full_name}" + (f" (@{uname})" if uname else "")
        await send_admin_delete_report(
            cq.bot,
            db,
            user_id=int(o["user_id"]),
            order=o,
            reason="user_delete",
            actor_id=int(cq.from_user.id),
            actor_name=actor_name,
            extra_cost_irt=int(extra_cost or 0),
        )
    except Exception:
        pass

    msg = "✅ سرور حذف شد."
    if billing == "hourly" and extra_cost > 0:
        msg += f"\nمبلغ کسر شده بابت دقایق استفاده: {fmt_money(extra_cost)}"
    await cq.message.edit_text(msg, reply_markup=kb([[("برگشت", "me:orders")]]))
    await cq.answer("✅ حذف شد.")

@router.callback_query(F.data.startswith("order:del:"))
async def order_delete_prompt(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)

    billing = (o.get("billing_mode") or "").lower()
    if billing == "monthly":
        warn = "مطمنی میخواید سرور رو حذف کنید؟\nتمامی اطلاعات سرور میپره و قابل بازیابی نخواهد بود و مبلغ برگشت داده نخواهد شد"
    else:
        warn = "مطمینی میخواهید سرور رو حذف کنید؟\nتمامی اطلاعات سرور پاک خواهد شد و مبلغ استفاده دقایق سرور از حساب شما کسر خواهد شد"

    await cq.message.edit_text(
        f"{glass_header('حذف سرور')}\n{warn}",
        reply_markup=kb([
            [("✅ بله، حذف شود", f"order:del:confirm:{oid}")],
            [("❌ انصراف", f"order:view:{oid}")]
        ])
    )
    await cq.answer()

@router.callback_query(F.data.startswith("order:off:"))
async def order_off(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o or not o["hcloud_server_id"]:
        return await cq.answer("یافت نشد.", show_alert=True)
    try:
        hcloud_power_action(int(o["hcloud_server_id"]), "poweroff")
        await cq.answer("خاموش شد.")
    except Exception as e:
        await cq.answer(f"خطا: {e}", show_alert=True)

@router.callback_query(F.data.startswith("order:on:"))
async def order_on(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if o and o.get('status') == 'suspended_balance':
        # Allow turning on only after wallet top-up.
        u = await db.get_user(cq.from_user.id)
        bal = int(u["balance_irt"]) if u else 0
        if bal < HOURLY_WARN_BALANCE:
            return await cq.answer('⛔️ سرویس به علت اتمام موجودی ساعتی خاموش شده. ابتدا موجودی را افزایش بده، سپس روشن کن.', show_alert=True)
        try:
            await db.clear_order_suspension(int(o["id"]))
        except Exception:
            pass
    if not o or not o["hcloud_server_id"]:
        return await cq.answer("یافت نشد.", show_alert=True)
    try:
        hcloud_power_action(int(o["hcloud_server_id"]), "poweron")
        await cq.answer("روشن شد.")
    except Exception as e:
        await cq.answer(f"خطا: {e}", show_alert=True)

@router.callback_query(F.data.startswith("order:rebuild:"))
async def order_rebuild(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o or not o["hcloud_server_id"]:
        return await cq.answer("یافت نشد.", show_alert=True)
    try:
        hcloud_power_action(int(o["hcloud_server_id"]), "rebuild")
        await cq.answer("ریبلد شروع شد.")
        await cq.bot.send_message(cq.from_user.id, "🔁 ریبلد شروع شد. بعدش برای پسورد جدید از گزینه «بازیابی پسوورد» استفاده کن.")
    except Exception as e:
        await cq.answer(f"خطا: {e}", show_alert=True)

@router.callback_query(F.data.startswith("order:resetpw:"))
async def order_resetpw(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o or not o["hcloud_server_id"]:
        return await cq.answer("یافت نشد.", show_alert=True)
    try:
        newpw = hcloud_reset_password(int(o["hcloud_server_id"]))
        await cq.bot.send_message(cq.from_user.id, f"🔐 پسورد جدید روت:\n`{newpw}`", parse_mode="Markdown")
        await cq.answer("ارسال شد.")
    except Exception as e:
        await cq.answer(f"خطا: {e}", show_alert=True)

@router.callback_query(F.data.startswith("order:traffic:"))
async def order_traffic(cq: CallbackQuery, db: DB):
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid, user_id=cq.from_user.id)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    if o["traffic_limit_gb"] <= 0:
        return await cq.answer("برای این سرویس سقف ترافیک تعریف نشده.", show_alert=True)
    await cq.answer(f"{o['traffic_used_gb']:.1f}/{o['traffic_limit_gb']} GB")

@router.message(TopUpFlow.amount)
async def topup_amount(msg: Message, db: DB, state: FSMContext):
    raw = (msg.text or "").strip().replace(",", "").replace(" ", "")
    if not raw.isdigit():
        return await msg.answer("❌ لطفاً فقط عدد ارسال کن. مثال: 200000")
    amount = int(raw)
    if amount < 1000:
        return await msg.answer("❌ مبلغ باید حداقل 1000 تومان باشد.")
    user_id = msg.from_user.id

    inv_id = await db.create_invoice(user_id, amount, "card", f"Topup {amount}", "pending")
    payload = {"type": "topup", "amount": amount}
    await db.create_card_purchase(inv_id, user_id, json.dumps(payload, ensure_ascii=False))

    await state.clear()
    await state.set_state(AwaitReceipt.invoice_id)
    await state.update_data(invoice_id=inv_id)

    card_text = await get_card_text(db)
    await msg.answer(
        f"{glass_header('فاکتور کارت به کارت')}\n"
        f"{GLASS_DOT} شماره فاکتور: <code>#{inv_id}</code>\n"
        f"{GLASS_LINE}\n"
        f"{GLASS_DOT} نوع: شارژ کیف پول\n"
        f"{GLASS_DOT} مبلغ: {money(amount)}\n"
        f"{GLASS_LINE}\n"
        f"{GLASS_DOT} {card_text}\n\n"
        f"{GLASS_DOT} بعد از واریز، رسید را همینجا ارسال کن (عکس یا فایل).",
        parse_mode="HTML",
        reply_markup=kb([[("برگشت","home")]])
    )

    for aid in ADMIN_IDS:
        try:
            await msg.bot.send_message(
                aid,
                f"📥 فاکتور کارت‌به‌کارت (شارژ کیف پول) ایجاد شد\n"
                f"کاربر: {user_id}\n"
                f"مبلغ: {money(amount)}\n"
                f"فاکتور: #{inv_id}",
                reply_markup=kb([
                    [("✅ تایید شارژ", f"admin:pay:approve:{inv_id}")],
                    [("❌ رد", f"admin:pay:reject:{inv_id}")]
                ])
            )
        except Exception:
            pass

@router.message(AwaitReceipt.invoice_id)
async def receive_receipt(msg: Message, db: DB, state: FSMContext):
    data = await state.get_data()
    inv_id = int(data.get("invoice_id") or 0)
    if inv_id <= 0:
        await state.clear()
        return await msg.answer("فاکتور نامعتبر است.", reply_markup=kb([[("🏠 منوی اصلی","home")]]))

    file_id = None
    if msg.photo:
        file_id = msg.photo[-1].file_id
    elif msg.document:
        file_id = msg.document.file_id

    if not file_id:
        return await msg.answer("لطفاً رسید را به صورت عکس یا فایل ارسال کن.")

    await db.set_card_purchase_receipt(inv_id, file_id)

    cp = await db.get_card_purchase(inv_id)
    payload = {}
    try:
        payload = json.loads(cp["payload_json"]) if cp else {}
    except Exception:
        payload = {}

    kind = payload.get("type", "vps")
    if kind == "topup":
        title = "🧾 رسید پرداخت کارت‌به‌کارت (شارژ کیف پول)"
        approve_txt = "✅ تایید شارژ"
    else:
        title = "🧾 رسید پرداخت کارت‌به‌کارت (خرید VPS)"
        approve_txt = "✅ تایید و ساخت سرور"

    # build caption with contextual info (e.g., order IP for traffic purchases)
    extra_lines = []
    if kind == "traffic":
        oid = payload.get("order_id")
        ip4 = "-"
        try:
            if oid is not None:
                o = await db.get_order(int(oid))
                if o and o.get("ip4"):
                    ip4 = str(o.get("ip4"))
        except Exception:
            pass
        if oid is not None:
            extra_lines.append(f"سرویس: #{oid}")
        extra_lines.append(f"IP: {ip4}")

    extra_txt = ("\n" + "\n".join(extra_lines) + "\n") if extra_lines else ""

    caption = (
        f"{title}\n"
        f"کاربر: {msg.from_user.id}\n"
        f"@{msg.from_user.username}\n"
        f"فاکتور: #{inv_id}\n"
        f"{extra_txt}\n"
        f"برای تایید/رد از دکمه‌ها استفاده کن."
    )
    admin_kb = kb([
        [(approve_txt, f"admin:pay:approve:{inv_id}")],
        [("❌ رد رسید", f"admin:pay:reject:{inv_id}")],
    ])

    for aid in ADMIN_IDS:
        try:
            if msg.photo:
                await msg.bot.send_photo(aid, file_id, caption=caption, reply_markup=admin_kb)
            else:
                await msg.bot.send_document(aid, file_id, caption=caption, reply_markup=admin_kb)
        except Exception:
            pass

    await msg.answer("✅ رسید شما ارسال شد. منتظر تایید مدیر باشید.", reply_markup=kb([[("🏠 منوی اصلی","home")]]))

@router.callback_query(F.data == "ticket:new")
async def ticket_new(cq: CallbackQuery, state: FSMContext):
    await state.set_state(TicketFlow.new_subject)
    await cq.message.edit_text(f"{glass_header('تیکت جدید')}\n{GLASS_DOT} موضوع را بنویس:", reply_markup=kb([[("برگشت","support:start")]]))
    await cq.answer()

@router.message(TicketFlow.new_subject)
async def ticket_new_subject(msg: Message, state: FSMContext):
    subject = (msg.text or "").strip()
    if len(subject) < 2:
        return await msg.answer("موضوع کوتاه است. دوباره بفرست.")
    await state.update_data(ticket_subject=subject)
    await state.set_state(TicketFlow.new_text)
    await msg.answer(f"{glass_header('تیکت جدید')}\n{GLASS_DOT} متن پیام را بنویس:")

@router.message(TicketFlow.new_text)
async def ticket_new_text(msg: Message, db: DB, state: FSMContext):
    data = await state.get_data()
    subject = data.get("ticket_subject","-")
    text = (msg.text or "").strip()
    if len(text) < 2:
        return await msg.answer("متن کوتاه است. دوباره بفرست.")
    tid = await db.create_ticket(msg.from_user.id, subject, text)
    await state.clear()
    await msg.answer(f"✅ تیکت شما ثبت شد. شماره: #{tid}", reply_markup=kb([[("📄 تیکت‌های من","ticket:mine")],[("🏠 منوی اصلی","home")]]))

    admin_kb = kb([[("✉️ پاسخ", f"admin:ticket:reply:{tid}")],[("✅ بستن", f"admin:ticket:close:{tid}")]])
    for aid in ADMIN_IDS:
        try:
            await msg.bot.send_message(
                aid,
                f"🎫 تیکت جدید #{tid}\nکاربر: {msg.from_user.id}\nموضوع: {subject}\n\n{text}",
                reply_markup=admin_kb
            )
        except Exception:
            pass

@router.callback_query(F.data == "ticket:mine")
async def ticket_mine(cq: CallbackQuery, db: DB):
    tickets = await db.list_user_tickets(cq.from_user.id, limit=20)
    if not tickets:
        await cq.message.edit_text(f"{glass_header('تیکت‌های من')}\n{GLASS_DOT} موردی ندارید.", reply_markup=kb([[("برگشت","support:start")]]))
        return await cq.answer()
    rows=[]
    for t in tickets:
        st = "🟢 باز" if t["status"]=="open" else "⚪️ بسته"
        rows.append([(f"{st} #{t['id']} | {t['subject']}", f"ticket:view:{t['id']}")])
    rows.append([("برگشت","support:start")])
    await cq.message.edit_text(f"{glass_header('تیکت‌های من')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("ticket:view:"))
async def ticket_view(cq: CallbackQuery, db: DB):
    tid = int(cq.data.split(":")[-1])
    t = await db.get_ticket(tid)
    if not t or t["user_id"] != cq.from_user.id:
        return await cq.answer("یافت نشد.", show_alert=True)
    msgs = await db.list_ticket_messages(tid, limit=30)
    body = []
    for m in msgs:
        who = "🧑‍💻 شما" if m["sender"]=="user" else "🛠 مدیر"
        body.append(f"{who}: {m['text']}")
    text = f"{glass_header(f'تیکت #{tid}')}\\n{GLASS_DOT} موضوع: {t['subject']}\\n{GLASS_LINE}\\n" + "\\n".join(body[-20:])
    rows=[]
    if t["status"]=="open":
        rows.append([("✉️ ارسال پیام", f"ticket:reply:{tid}")])
    rows.append([("برگشت","ticket:mine")])
    await cq.message.edit_text(text, reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("ticket:reply:"))
async def ticket_reply_start(cq: CallbackQuery, state: FSMContext):
    tid = int(cq.data.split(":")[-1])
    await state.set_state(TicketFlow.reply_text)
    await state.update_data(reply_ticket_id=tid, reply_role="user")
    await cq.message.edit_text(f"{glass_header('پاسخ تیکت')}\n{GLASS_DOT} پیام را بنویس:", reply_markup=kb([[("برگشت", f"ticket:view:{tid}")]]))
    await cq.answer()

@router.message(TicketFlow.reply_text)
async def ticket_reply_send(msg: Message, db: DB, state: FSMContext):
    data = await state.get_data()
    tid = int(data.get("reply_ticket_id") or 0)
    role = data.get("reply_role")
    if tid <= 0 or role not in ("user","admin"):
        await state.clear()
        return
    t = await db.get_ticket(tid)
    if not t:
        await state.clear()
        return await msg.answer("تیکت یافت نشد.")
    txt = (msg.text or "").strip()
    if not txt:
        return await msg.answer("متن خالی است.")
    if role == "user" and t["user_id"] != msg.from_user.id:
        return await msg.answer("دسترسی ندارید.")
    if t["status"] != "open":
        await state.clear()
        return await msg.answer("این تیکت بسته شده است.")

    await db.add_ticket_message(tid, role, msg.from_user.id, txt)
    await state.clear()
    await msg.answer("✅ ارسال شد.", reply_markup=kb([[("📄 تیکت‌های من","ticket:mine")],[("🏠 منوی اصلی","home")]]))

    admin_kb = kb([[("✉️ پاسخ", f"admin:ticket:reply:{tid}")],[("✅ بستن", f"admin:ticket:close:{tid}")]])
    if role == "user":
        for aid in ADMIN_IDS:
            try:
                await msg.bot.send_message(aid, f"💬 پیام جدید در تیکت #{tid}\nکاربر: {t['user_id']}\n\n{txt}", reply_markup=admin_kb)
            except Exception:
                pass
    else:
        try:
            await msg.bot.send_message(t["user_id"], f"🛠 پاسخ مدیر (تیکت #{tid}):\n\n{txt}", reply_markup=kb([[("📄 تیکت‌های من","ticket:mine")]]))
        except Exception:
            pass

@router.callback_query(F.data.startswith("admin:ticket:reply:"))
async def admin_ticket_reply_start(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    tid = int(cq.data.split(":")[-1])
    t = await db.get_ticket(tid)
    if not t:
        return await cq.answer("یافت نشد.", show_alert=True)
    if t["status"] != "open":
        return await cq.answer("بسته است.", show_alert=True)
    await state.set_state(TicketFlow.reply_text)
    await state.update_data(reply_ticket_id=tid, reply_role="admin")
    await cq.message.reply(f"✉️ پاسخ به تیکت #{tid} (کاربر {t['user_id']})\nمتن پیام را بفرست:")
    await cq.answer()

@router.callback_query(F.data.startswith("admin:ticket:close:"))
async def admin_ticket_close(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    tid = int(cq.data.split(":")[-1])
    t = await db.get_ticket(tid)
    if not t:
        return await cq.answer("یافت نشد.", show_alert=True)
    await db.close_ticket(tid)
    try:
        await cq.bot.send_message(t["user_id"], f"✅ تیکت #{tid} بسته شد.", reply_markup=kb([[("🎫 پشتیبانی","support:start")],[("🏠 منوی اصلی","home")]]))
    except Exception:
        pass
    await cq.answer("بسته شد.")

@router.callback_query(F.data == "support:start")
async def support_removed(cq: CallbackQuery):
    await cq.answer("این بخش حذف شده است.", show_alert=True)

@router.message(SupportFlow.text)
async def support_text(msg: Message, state: FSMContext):
    txt = (msg.text or "").strip()
    if not txt:
        return await msg.answer("پیام خالی است.")
    for aid in ADMIN_IDS:
        try:
            await msg.bot.send_message(aid, f"🎫 پیام پشتیبانی\nاز: {msg.from_user.id}\n@{msg.from_user.username}\n\n{txt}")
        except Exception:
            pass
    await msg.answer("✅ ارسال شد. منتظر پاسخ مدیر بمان.", reply_markup=kb([[("🏠 منوی اصلی","home")]]))
    await state.clear()

@router.callback_query(F.data.startswith("admin:userlist:"))
async def admin_user_list(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    offset = int(cq.data.split(":")[-1])
    users = await db.list_all_users(limit=10, offset=offset)
    rows = []
    for u in users:
        name = f"@{u['username']}" if u['username'] else "-"
        blk = "⛔️" if u["is_blocked"] else "✅"
        rows.append([(f"{blk} {u['user_id']} {name} | {money(u['balance_irt'])}", f"admin:user:{u['user_id']}")])
    nav = []
    if offset >= 10:
        nav.append(("⬅️ قبلی", f"admin:userlist:{offset-10}"))
    nav.append(("بعدی ➡️", f"admin:userlist:{offset+10}"))
    rows.append(nav)
    rows.append([("برگشت","admin:users")])
    await cq.message.edit_text(f"{glass_header('لیست کاربران')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data == "admin:usersearch")
async def admin_user_search(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminUserFlow.search_id)
    await cq.message.edit_text(f"{glass_header('جستجو کاربر')}\n{GLASS_DOT} ایدی عددی را بفرست:", reply_markup=kb([[("برگشت","admin:users")]]))
    await cq.answer()

@router.message(AdminUserFlow.search_id)
async def admin_user_search_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        uid = int((msg.text or "").strip())
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await state.clear()
    u = await db.get_user(uid)
    if not u:
        return await msg.answer("یافت نشد.")
    await admin_user_view_common(msg.bot, msg.chat.id, db, uid)

async def admin_user_view_common(bot_: Bot, chat_id: int, db: DB, uid: int):
    u = await db.get_user(uid)
    if not u:
        return
    blk = "⛔️ مسدود" if u["is_blocked"] else "✅ فعال"
    username = u["username"] if u.get("username") else "-"
    text = (
        f"{glass_header('کاربر')}\n"
        f"{GLASS_DOT} ID: <code>{uid}</code>\n"
        f"{GLASS_DOT} Username: @{htmlesc(username)}\n"
        f"{GLASS_DOT} وضعیت: {htmlesc(blk)}\n"
        f"{GLASS_DOT} موجودی: {htmlesc(money(u['balance_irt']))}\n"
    )
    await bot_.send_message(chat_id, text, parse_mode="HTML", reply_markup=kb([
        [("✉️ پیام", f"admin:umsg:{uid}")],
        [("➕ افزایش", f"admin:ubal:add:{uid}"), ("➖ کاهش", f"admin:ubal:sub:{uid}")],
        [("📦 سفارش‌ها", f"admin:uorders:{uid}")],
        [("⛔️ بلاک/آن‌بلاک", f"admin:ublock:{uid}")],
        [("برگشت","admin:users")]
    ]))

@router.callback_query(F.data.startswith("admin:user:"))
async def admin_user_open(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    uid = int(cq.data.split(":")[-1])
    await admin_user_view_common(cq.bot, cq.message.chat.id, db, uid)
    await cq.answer()

@router.callback_query(F.data.startswith("admin:ublock:"))
async def admin_user_block_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    uid = int(cq.data.split(":")[-1])
    u = await db.get_user(uid)
    if not u:
        return await cq.answer("یافت نشد.", show_alert=True)
    await db.set_block(uid, not bool(u["is_blocked"]))
    await cq.answer("انجام شد.")
    await admin_user_view_common(cq.bot, cq.message.chat.id, db, uid)

@router.callback_query(F.data.startswith("admin:ubal:"))
async def admin_user_balance_begin(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return
    _,_,mode,uid = cq.data.split(":")
    uid = int(uid)
    await state.set_state(AdminUserFlow.amount)
    await state.update_data(ubal_uid=uid, ubal_mode=mode)
    await cq.message.edit_text(f"{glass_header('موجودی')}\n{GLASS_DOT} مبلغ تومان:", reply_markup=kb([[("برگشت","admin:users")]]))
    await cq.answer()

@router.message(AdminUserFlow.amount)
async def admin_user_balance_apply(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    uid = int(data.get("ubal_uid") or 0)
    mode = data.get("ubal_mode")
    try:
        amt = int((msg.text or "").strip())
        if amt <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    delta = amt if mode == "add" else -amt
    await db.add_balance(uid, delta)
    try:
        await try_resume_suspended_hourly(msg.bot, db, uid)
    except Exception:
        pass
    await state.clear()
    try:
        await msg.bot.send_message(uid, f"💰 تغییر موجودی: {money(delta)}")
    except Exception:
        pass
    await msg.answer("✅ انجام شد.", reply_markup=kb([[("برگشت","admin:users")]]))

@router.callback_query(F.data.startswith("admin:umsg:"))
async def admin_user_msg_begin(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return
    uid = int(cq.data.split(":")[-1])
    await state.set_state(AdminUserFlow.msg_text)
    await state.update_data(umsg_uid=uid)
    await cq.message.edit_text(f"{glass_header('پیام')}\n{GLASS_DOT} متن پیام:", reply_markup=kb([[("برگشت","admin:users")]]))
    await cq.answer()

@router.message(AdminUserFlow.msg_text)
async def admin_user_msg_send(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    uid = int(data.get("umsg_uid") or 0)
    txt = (msg.text or "").strip()
    if not txt:
        return await msg.answer("متن خالی است.")
    try:
        await msg.bot.send_message(uid, f"📩 پیام مدیر:\n\n{txt}")
        await msg.answer("✅ ارسال شد.", reply_markup=kb([[("برگشت","admin:users")]]))
    except Exception:
        await msg.answer("❌ ارسال نشد.")
    await state.clear()

@router.callback_query(F.data.startswith("admin:uorders:"))
async def admin_user_orders(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    uid = int(cq.data.split(":")[-1])
    orders = await db.list_user_orders(uid)
    if not orders:
        await cq.message.edit_text("سفارشی ندارد.", reply_markup=kb([[("برگشت","admin:users")]]))
        return await cq.answer()
    rows: List[List[Tuple[str, str]]] = []
    rows.append([("🗑 حذف همه سفارش‌ها", f"admin:uorders:clear:{uid}")])
    for o in orders[:50]:
        label = o.get("ip4") or f"Order#{o['id']}"
        rows.append([
            (f"{label} | {o.get('status')}", f"admin:ord:{o['id']}"),
            ("🗑", f"admin:orddel:{o['id']}:{uid}"),
        ])
    rows.append([("برگشت", "admin:users")])
    await cq.message.edit_text(
        f"{glass_header('سفارش‌های کاربر')}\n{GLASS_DOT} کاربر: {uid}\n{GLASS_DOT} روی 🗑 هر سفارش بزن تا حذف شود.",
        reply_markup=kb(rows),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:uorders:clear:"))
async def admin_user_orders_clear(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    uid = int(cq.data.split(":")[-1])

    # collect orders before bulk delete (so we can report each one)
    try:
        _orders = await db.list_user_orders(uid)
    except Exception:
        _orders = []

    await db.delete_user_orders(uid)

    # report to admins
    try:
        uname = (cq.from_user.username or "")
        actor_name = f"{cq.from_user.full_name}" + (f" (@{uname})" if uname else "")
        for _o in _orders:
            await send_admin_delete_report(
                cq.bot,
                db,
                user_id=uid,
                order=_o,
                reason="admin_bulk_delete",
                actor_id=int(cq.from_user.id),
                actor_name=actor_name,
                extra_cost_irt=0,
            )
    except Exception:
        pass

    await cq.answer("✅ سفارش‌های کاربر حذف شد")
    # refresh list
    await cq.message.edit_text(
        f"{glass_header('سفارش‌های کاربر')}\n{GLASS_DOT} سفارش‌های کاربر {uid} پاک شد.",
        reply_markup=kb([[('برگشت', 'admin:users')]]),
    )


@router.callback_query(F.data.startswith("admin:orddel:"))
async def admin_order_delete(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = cq.data.split(":")
    if len(parts) < 4:
        return await cq.answer()
    oid = int(parts[2])
    uid = int(parts[3])
    await db.delete_order(oid)

    # report to admins
    try:
        o = await db.get_order(oid)
        uname = (cq.from_user.username or "")
        actor_name = f"{cq.from_user.full_name}" + (f" (@{uname})" if uname else "")
        await send_admin_delete_report(
            cq.bot,
            db,
            user_id=uid,
            order=o or {"id": oid, "user_id": uid},
            reason="admin_delete",
            actor_id=int(cq.from_user.id),
            actor_name=actor_name,
            extra_cost_irt=0,
        )
    except Exception:
        pass

    await cq.answer("✅ حذف شد")
    # refresh orders list
    cq.data = f"admin:uorders:{uid}"
    await admin_user_orders(cq, db)

# -------------------------
# Admin
# -------------------------
@router.callback_query(F.data == "admin:home")
async def admin_home(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await cq.message.edit_text(
        f"{glass_header('پنل مدیریت')}\n{GLASS_DOT} انتخاب کن:",
        reply_markup=kb([
            [("➕ افزودن پلن", "admin:addplan"), ("📋 لیست پلن‌ها", "admin:plans")],
            [("🟢 سرورهای فعال", "admin:active"), ("🧾 پرداخت‌های کارت‌به‌کارت", "admin:payments")],
            [("➕ ترافیک اضافه", "admin:traffic"), ("🧾 فروش دستی", "admin:manual")],
            [("🧩 تنظیم دکمه‌ها", "admin:buttons"), ("🧰 مدیریت عمومی", "admin:general")],
            [("برگشت","home")],
        ])
    )
    await cq.answer()


# -------------------------
# Admin: Extra traffic packages
# -------------------------
@router.callback_query(F.data == "admin:traffic")
async def admin_traffic_menu(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    rows = []
    for cc in COUNTRY_LOCATIONS.keys():
        name = COUNTRY_NAMES.get(cc, cc)
        rows.append([(f"🌍 {name}", f"admin:traffic:cc:{cc}")])
    rows.append([("برگشت", "admin:home")])
    await cq.message.edit_text(
        f"{glass_header('ترافیک اضافه')}\n{GLASS_DOT} کشور را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:traffic:cc_toggle:"))
async def admin_traffic_cc_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    cc = cq.data.split(":")[-1].upper().strip()
    key = f"extra_traffic_enabled_{cc}"
    cur = await db.get_setting(key, "1")
    newv = "0" if cur == "1" else "1"
    await db.set_setting(key, newv)

    enabled = newv == "1"
    items = await db.list_traffic_packages(cc, active_only=False)
    rows = []
    rows.append([(f"📦 ترافیک اضافه این کشور: {'روشن ✅' if enabled else 'خاموش ❌'}", f"admin:traffic:cc_toggle:{cc}")])
    for p in items[:50]:
        title = p.get('title') or f"{p['volume_gb']}GB"
        st = "✅" if p.get('is_active') else "❌"
        rows.append([(f"{st} {title} | {p['volume_gb']}GB | {money(int(p['price_irt']))}", f"admin:traffic:pkg:{p['id']}:{cc}")])
    rows.append([("➕ افزودن پکیج", f"admin:traffic:add:{cc}")])
    rows.append([("برگشت", "admin:traffic")])
    await cq.message.edit_text(
        f"{glass_header('ترافیک اضافه')}\n{GLASS_DOT} کشور: {_country_label(cc)}\n{GLASS_DOT} پکیج‌ها:",
        reply_markup=kb(rows),
    )
    await cq.answer()



@router.callback_query(F.data.startswith("admin:traffic:cc:"))
async def admin_traffic_country(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    cc = cq.data.split(":")[-1].upper()
    enabled = (await db.get_setting(f"extra_traffic_enabled_{cc}", "1")) == "1"
    items = await db.list_traffic_packages(cc, active_only=False)
    rows = []
    rows.append([(f"📦 ترافیک اضافه این کشور: {'روشن ✅' if enabled else 'خاموش ❌'}", f"admin:traffic:cc_toggle:{cc}")])
    for p in items[:50]:
        title = p.get('title') or f"{p['volume_gb']}GB"
        st = "✅" if p.get('is_active') else "❌"
        rows.append([(f"{st} {title} | {p['volume_gb']}GB | {money(int(p['price_irt']))}", f"admin:traffic:pkg:{p['id']}:{cc}")])
    rows.append([( "➕ افزودن پکیج", f"admin:traffic:add:{cc}")])
    rows.append([( "برگشت", "admin:traffic")])
    await cq.message.edit_text(
        f"{glass_header('ترافیک اضافه')}\n{GLASS_DOT} کشور: {_country_label(cc)}\n{GLASS_DOT} پکیج‌ها:",
        reply_markup=kb(rows),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:traffic:pkg:"))
async def admin_traffic_pkg(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    # admin:traffic:pkg:ID:CC
    parts = cq.data.split(":")
    pid = int(parts[3])
    cc = parts[4].upper() if len(parts) > 4 else ""
    pkg = await db.get_traffic_package(pid)
    if not pkg:
        return await cq.answer("یافت نشد.", show_alert=True)
    title = pkg.get('title') or f"{pkg['volume_gb']}GB"
    st = "روشن ✅" if pkg.get('is_active') else "خاموش ❌"
    text = (
        f"{glass_header('مدیریت پکیج ترافیک')}\n"
        f"{GLASS_DOT} کشور: {pkg.get('country_code')}\n"
        f"{GLASS_DOT} عنوان: {title}\n"
        f"{GLASS_DOT} حجم: {pkg['volume_gb']}GB\n"
        f"{GLASS_DOT} قیمت: {money(int(pkg['price_irt']))}\n"
        f"{GLASS_DOT} وضعیت: {st}\n"
    )
    await cq.message.edit_text(
        text,
        reply_markup=kb([
            [("🔁 تغییر وضعیت", f"admin:traffic:toggle:{pid}:{cc}")],
            [("🗑 حذف", f"admin:traffic:del:{pid}:{cc}")],
            [("برگشت", f"admin:traffic:cc:{cc or pkg.get('country_code')}")],
        ]),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:traffic:toggle:"))
async def admin_traffic_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = cq.data.split(":")
    pid = int(parts[3])
    cc = parts[4].upper() if len(parts) > 4 else ""
    await db.toggle_traffic_package_active(pid)
    await cq.answer("ذخیره شد ✅")

    # re-render country list
    items = await db.list_traffic_packages(cc, active_only=False)
    rows = []
    for p in items[:50]:
        title = p.get('title') or f"{p['volume_gb']}GB"
        st = "✅" if p.get('is_active') else "❌"
        rows.append([(f"{st} {title} | {p['volume_gb']}GB | {money(int(p['price_irt']))}", f"admin:traffic:pkg:{p['id']}:{cc}")])
    rows.append([( "➕ افزودن پکیج", f"admin:traffic:add:{cc}")])
    rows.append([( "برگشت", "admin:traffic")])
    await cq.message.edit_text(
        f"{glass_header('ترافیک اضافه')}\n{GLASS_DOT} کشور: {_country_label(cc)}\n{GLASS_DOT} پکیج‌ها:",
        reply_markup=kb(rows),
    )


@router.callback_query(F.data.startswith("admin:traffic:del:"))
async def admin_traffic_delete(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = cq.data.split(":")
    pid = int(parts[3])
    cc = parts[4].upper() if len(parts) > 4 else ""
    await db.delete_traffic_package(pid)
    await cq.answer("حذف شد ✅")
    await cq.message.edit_text("✅ حذف شد.", reply_markup=kb([[('برگشت', f'admin:traffic:cc:{cc}')]]))


@router.callback_query(F.data.startswith("admin:traffic:add:"))
async def admin_traffic_add_start(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    cc = cq.data.split(":")[-1].upper()
    await state.clear()
    await state.update_data(country_code=cc)
    await state.set_state(AdminTrafficFlow.title)
    await cq.message.edit_text(
        f"{glass_header('پکیج جدید')}\n{GLASS_DOT} کشور: {_country_label(cc)}\n{GLASS_DOT} عنوان پکیج را بفرست (مثلاً 50GB):",
        reply_markup=kb([[('برگشت', f'admin:traffic:cc:{cc}')]]),
    )
    await cq.answer()


@router.message(AdminTrafficFlow.title)
async def admin_traffic_add_title(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    title = (msg.text or "").strip()
    if len(title) < 1:
        return await msg.answer("عنوان نامعتبر است.")
    await state.update_data(title=title)
    await state.set_state(AdminTrafficFlow.volume_gb)
    await msg.answer("حجم (GB) را فقط عدد بفرست:")


@router.message(AdminTrafficFlow.volume_gb)
async def admin_traffic_add_volume(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        gb = int((msg.text or "").strip())
        if gb <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست. مثال: 50")
    await state.update_data(volume_gb=gb)
    await state.set_state(AdminTrafficFlow.price_irt)
    await msg.answer("قیمت (تومان/ریال؟ مطابق سیستم شما) را فقط عدد بفرست:")


@router.message(AdminTrafficFlow.price_irt)
async def admin_traffic_add_price(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    cc = (data.get('country_code') or '').upper()
    title = data.get('title') or ''
    try:
        price = int((msg.text or "").strip())
        if price <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    gb = int(data.get('volume_gb') or 0)
    await db.create_traffic_package(country_code=cc, title=title, volume_gb=gb, price_irt=price, is_active=True)
    await state.clear()
    await msg.answer("✅ پکیج ثبت شد.", reply_markup=kb([[('برگشت', f'admin:traffic:cc:{cc}')]]))


# -------------------------
# Admin: Manual sales (deliver manual orders)
# -------------------------
@router.callback_query(F.data == "admin:manual")
async def admin_manual_menu(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    pending = await db.list_orders_by_status('pending_manual', limit=50)
    rows = []
    if pending:
        for o in pending:
            label = o.get('ip4') or o.get('name') or f"Order#{o['id']}"
            rows.append([(f"🟠 #{o['id']} | {o.get('user_id')} | {label}", f"admin:manual:deliver:{o['id']}")])
    else:
        rows.append([( "✅ سفارشی در انتظار نیست", "noop")])
    rows.append([("📋 لیست پلن‌های دستی", "admin:manual:plans")])
    rows.append([( "برگشت", "admin:home")])
    await cq.message.edit_text(
        f"{glass_header('فروش دستی')}\n{GLASS_DOT} سفارش‌های در انتظار تحویل:",
        reply_markup=kb(rows),
    )
    await cq.answer()


@router.callback_query(F.data == "admin:manual:plans")
async def admin_manual_plans(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()

    plans = await db.list_plans_by_provider("manual", only_active=None, limit=200)
    if not plans:
        await cq.message.edit_text(
            f"{glass_header('پلن‌های دستی')}\n{GLASS_DOT} هنوز پلن دستی‌ای ثبت نشده.",
            reply_markup=kb([[("برگشت", "admin:manual")]]),
        )
        return await cq.answer()

    lines = []
    rows = []
    for p in plans[:50]:
        st = p.get("server_type") or "-"
        title = p.get("title") or "-"
        cc = p.get("country_code") or "-"
        price = fmt_irt(p.get("price_monthly_irt") or 0)
        tl = p.get("traffic_limit_gb") or 0
        tl_txt = "نامحدود" if int(tl) == 0 else f"{int(tl)}GB"
        status = "✅" if int(p.get("is_active") or 0) == 1 else "⛔️"
        lines.append(
            f"{status} <b>#{p['id']}</b> | {htmlesc(title)}\n"
            f"• کشور: <code>{htmlesc(cc)}</code> | کد: <code>{htmlesc(str(st))}</code>\n"
            f"• ترافیک: {htmlesc(tl_txt)} | قیمت: <b>{price}</b>"
        )
        rows.append([(f"{status} #{p['id']} | {title[:18]}", f"admin:manualplan:{p['id']}")])

    rows.append([("برگشت", "admin:manual")])

    await cq.message.edit_text(
        f"{glass_header('پلن‌های دستی')}\n{GLASS_DOT} لیست پلن‌های دستی (۵۰ مورد اول):\n\n" + "\n\n".join(lines),
        reply_markup=kb(rows),
        parse_mode="HTML",
    )
    await cq.answer()


async def _render_manual_plan_panel(cq: CallbackQuery, db: DB, plan_id: int, note: str = "") -> None:
    p = await db.get_plan(plan_id)
    if not p or (p.get("provider") or "").lower() != "manual":
        await cq.message.edit_text(
            f"{glass_header('پلن دستی')}\n{GLASS_DOT} پلن یافت نشد.",
            reply_markup=kb([[('برگشت', 'admin:manual:plans')]]),
        )
        return

    title = p.get("title") or "-"
    cc = (p.get("country_code") or "-").upper()
    st = p.get("server_type") or "-"
    price = fmt_irt(p.get("price_monthly_irt") or 0)
    tl = int(p.get("traffic_limit_gb") or 0)
    tl_txt = "نامحدود" if tl == 0 else f"{tl}GB"
    active = int(p.get("is_active") or 0) == 1

    info = (
        f"{glass_header('مدیریت پلن دستی')}\n"
        f"{GLASS_DOT} شناسه: <b>#{p['id']}</b>\n"
        f"{GLASS_DOT} عنوان: <b>{htmlesc(title)}</b>\n"
        f"{GLASS_DOT} کشور: <code>{htmlesc(cc)}</code>\n"
        f"{GLASS_DOT} کد/سرور: <code>{htmlesc(str(st))}</code>\n"
        f"{GLASS_DOT} ترافیک: <b>{htmlesc(tl_txt)}</b>\n"
        f"{GLASS_DOT} قیمت ماهانه: <b>{price}</b>\n"
        f"{GLASS_DOT} وضعیت: {'✅ فعال' if active else '⛔️ غیرفعال'}"
    )
    if note:
        info = f"{note}\n\n" + info

    rows = [
        [("✏️ تغییر عنوان", f"admin:manualplan:edit:title:{p['id']}")],
        [("🏷 تغییر کد/سرور", f"admin:manualplan:edit:server:{p['id']}")],
        [("🌍 تغییر کشور", f"admin:manualplan:edit:country:{p['id']}")],
        [("💰 تغییر قیمت", f"admin:manualplan:edit:price:{p['id']}")],
        [("📶 تغییر ترافیک", f"admin:manualplan:edit:traffic:{p['id']}")],
        [("➕ ترافیک اضافه", f"admin:traffic:cc:{cc}")],

        [(f"🔁 {'غیرفعال کن' if active else 'فعال کن'}", f"admin:manualplan:toggle:{p['id']}")],
        [("🗑 حذف پلن", f"admin:manualplan:delete:{p['id']}")],
        [("برگشت", "admin:manual:plans")],
    ]

    await cq.message.edit_text(info, parse_mode="HTML", reply_markup=kb(rows))


@router.callback_query(F.data.startswith("admin:manualplan:"))
async def admin_manual_plan_router(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = cq.data.split(":")
    # admin:manualplan:<action>:...:id
    if len(parts) < 3:
        return await cq.answer()
    action = parts[2]
    try:
        plan_id = int(parts[-1])
    except Exception:
        return await cq.answer("شناسه نامعتبر.", show_alert=True)

    if action == "toggle":
        p = await db.get_plan(plan_id)
        if not p:
            return await cq.answer("یافت نشد.", show_alert=True)
        new_active = 0 if int(p.get("is_active") or 0) == 1 else 1
        await db.update_plan_fields(plan_id, is_active=new_active)
        await cq.answer("✅ انجام شد")
        await _render_manual_plan_panel(cq, db, plan_id)
        return

    if action == "delete":
        await db.delete_plan(plan_id)
        await cq.answer("✅ حذف شد")
        # refresh list
        await admin_manual_plans(cq, db, state)
        return

    if action == "edit" and len(parts) >= 5:
        field = parts[3]
        await state.clear()
        await state.update_data(manual_plan_id=plan_id)
        if field == "title":
            await state.set_state(AdminManualPlanEditFlow.set_title)
            await cq.message.edit_text(
                f"{glass_header('تغییر عنوان')}\n{GLASS_DOT} عنوان جدید را بفرست:",
                reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]),
            )
            await cq.answer()
            return
        if field == "server":
            await state.set_state(AdminManualPlanEditFlow.set_server_type)
            await cq.message.edit_text(
                f"{glass_header('تغییر کد/سرور')}\n{GLASS_DOT} کد جدید را بفرست (مثلاً CX22):",
                reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]),
            )
            await cq.answer()
            return
        if field == "country":
            await state.set_state(AdminManualPlanEditFlow.set_country_code)
            await cq.message.edit_text(
                f"{glass_header('تغییر کشور')}\n{GLASS_DOT} کد کشور را بفرست (مثلاً IR / DE / FI):",
                reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]),
            )
            await cq.answer()
            return
        if field == "price":
            await state.set_state(AdminManualPlanEditFlow.set_price_irt)
            await cq.message.edit_text(
                f"{glass_header('تغییر قیمت')}\n{GLASS_DOT} قیمت جدید (عدد) را بفرست:",
                reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]),
            )
            await cq.answer()
            return
        if field == "traffic":
            await state.set_state(AdminManualPlanEditFlow.set_traffic_gb)
            await cq.message.edit_text(
                f"{glass_header('تغییر ترافیک')}\n{GLASS_DOT} سقف ترافیک (GB) را عدد بفرست. برای نامحدود 0:",
                reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]),
            )
            await cq.answer()
            return

    # default: open panel
    await state.clear()
    await _render_manual_plan_panel(cq, db, plan_id)
    await cq.answer()


@router.message(AdminManualPlanEditFlow.set_title)
async def admin_manual_plan_set_title(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    plan_id = int((await state.get_data()).get("manual_plan_id") or 0)
    title = (msg.text or "").strip()
    if not title:
        return await msg.answer("عنوان نامعتبر است.")
    await db.update_plan_fields(plan_id, title=title)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]))


@router.message(AdminManualPlanEditFlow.set_server_type)
async def admin_manual_plan_set_server(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    plan_id = int((await state.get_data()).get("manual_plan_id") or 0)
    st = (msg.text or "").strip()
    if not st:
        return await msg.answer("کد نامعتبر است.")
    await db.update_plan_fields(plan_id, server_type=st)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]))


@router.message(AdminManualPlanEditFlow.set_country_code)
async def admin_manual_plan_set_country(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    plan_id = int((await state.get_data()).get("manual_plan_id") or 0)
    cc = (msg.text or "").strip().upper()
    if not re.fullmatch(r"[A-Z]{2}", cc):
        return await msg.answer("کد کشور نامعتبر است. مثال: IR")
    await db.update_plan_fields(plan_id, country_code=cc)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]))


@router.message(AdminManualPlanEditFlow.set_price_irt)
async def admin_manual_plan_set_price(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    plan_id = int((await state.get_data()).get("manual_plan_id") or 0)
    try:
        price = int(str(msg.text or "").strip())
        if price < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.update_plan_fields(plan_id, price_monthly_irt=price)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]))


@router.message(AdminManualPlanEditFlow.set_traffic_gb)
async def admin_manual_plan_set_traffic(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    plan_id = int((await state.get_data()).get("manual_plan_id") or 0)
    try:
        gb = int(str(msg.text or "").strip())
        if gb < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست. برای نامحدود 0")
    await db.update_plan_fields(plan_id, traffic_limit_gb=gb)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[('برگشت', f'admin:manualplan:{plan_id}')]]))

@router.callback_query(F.data.startswith('admin:manual:deliver:'))
async def admin_manual_deliver_start(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    oid = int(cq.data.split(':')[-1])
    o = await db.get_order(oid)
    if not o:
        return await cq.answer('یافت نشد.', show_alert=True)
    await state.clear()
    await state.update_data(order_id=oid)
    await state.set_state(AdminManualDeliverFlow.ip4)
    await cq.message.edit_text(
        f"{glass_header('تحویل سفارش دستی')}\n{GLASS_DOT} سرویس: #{oid}\n{GLASS_DOT} کاربر: {o.get('user_id')}\n\n{GLASS_DOT} IP را بفرست:",
        reply_markup=kb([[('برگشت','admin:manual')]]),
    )
    await cq.answer()


@router.message(AdminManualDeliverFlow.ip4)
async def admin_manual_deliver_ip(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    ip4 = (msg.text or '').strip()
    if len(ip4) < 3:
        return await msg.answer('IP نامعتبر است.')
    await state.update_data(ip4=ip4)
    await state.set_state(AdminManualDeliverFlow.login_user)
    await msg.answer('یوزرنیم/کاربر (مثلاً root) را بفرست:')


@router.message(AdminManualDeliverFlow.login_user)
async def admin_manual_deliver_user(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    u = (msg.text or '').strip()
    if len(u) < 1:
        return await msg.answer('نامعتبر است.')
    await state.update_data(login_user=u)
    await state.set_state(AdminManualDeliverFlow.login_pass)
    await msg.answer('پسورد را بفرست:')


@router.message(AdminManualDeliverFlow.login_pass)
async def admin_manual_deliver_pass(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    p = (msg.text or '').strip()
    if len(p) < 1:
        return await msg.answer('نامعتبر است.')
    await state.update_data(login_pass=p)
    await state.set_state(AdminManualDeliverFlow.monitoring_url)
    await msg.answer('لینک صفحه مانیتورینگ را بفرست (اختیاری) یا - بزن:')


@router.message(AdminManualDeliverFlow.monitoring_url)
async def admin_manual_deliver_monitoring_url(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    url = (msg.text or '').strip()
    if url == '-':
        await state.update_data(monitoring_url='', monitoring_user='', monitoring_pass='')
        await state.set_state(AdminManualDeliverFlow.details)
        return await msg.answer('توضیحات اضافی (اختیاری) را بفرست یا - بزن:')
    # very light validation
    if not (url.startswith('http://') or url.startswith('https://')):
        return await msg.answer('لینک نامعتبر است. با http:// یا https:// شروع شود یا - بزن.')
    await state.update_data(monitoring_url=url)
    await state.set_state(AdminManualDeliverFlow.monitoring_user)
    await msg.answer('نام کاربری مانیتورینگ را بفرست (اختیاری) یا - بزن:')


@router.message(AdminManualDeliverFlow.monitoring_user)
async def admin_manual_deliver_monitoring_user(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    u = (msg.text or '').strip()
    if u == '-':
        u = ''
    await state.update_data(monitoring_user=u)
    await state.set_state(AdminManualDeliverFlow.monitoring_pass)
    await msg.answer('رمز عبور مانیتورینگ را بفرست (اختیاری) یا - بزن:')


@router.message(AdminManualDeliverFlow.monitoring_pass)
async def admin_manual_deliver_monitoring_pass(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    p = (msg.text or '').strip()
    if p == '-':
        p = ''
    await state.update_data(monitoring_pass=p)
    await state.set_state(AdminManualDeliverFlow.details)
    await msg.answer('توضیحات اضافی (اختیاری) را بفرست یا - بزن:')


@router.message(AdminManualDeliverFlow.details)
async def admin_manual_deliver_done(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    oid = int(data.get('order_id') or 0)
    o = await db.get_order(oid)
    if not o:
        await state.clear()
        return await msg.answer('سرویس یافت نشد.')
    details = (msg.text or '').strip()
    if details == '-':
        details = ''
    await db.set_order_credentials(
        oid,
        ip4=data.get('ip4'),
        login_user=data.get('login_user'),
        login_pass=data.get('login_pass'),
        monitoring_url=data.get('monitoring_url') or None,
        monitoring_user=data.get('monitoring_user') or None,
        monitoring_pass=data.get('monitoring_pass') or None,
        manual_details=details,
        status='active',
    )
    await state.clear()

    # notify user
    try:
        txt = (
            f"✅ سفارش شما اماده شد مشخصات سفارش: 👇.\n"
            f"سرویس: #{oid}\n"
            f"IP: <code>{htmlesc(data.get('ip4') or '-') }</code>\n"
            f"USER: <code>{htmlesc(data.get('login_user') or '-') }</code>\n"
            f"PASS: <code>{htmlesc(data.get('login_pass') or '-') }</code>\n"
        )
        mon_url = (data.get('monitoring_url') or '').strip()
        mon_user = (data.get('monitoring_user') or '').strip()
        mon_pass = (data.get('monitoring_pass') or '').strip()
        if mon_url:
            txt += f"\n📡 صفحه مانیتورینگ: <a href=\"{htmlesc(mon_url)}\">ورود</a>\n"
            if mon_user:
                txt += f"نام کاربری مانیتورینگ: <code>{htmlesc(mon_user)}</code>\n"
            if mon_pass:
                txt += f"رمز عبور مانیتورینگ: <code>{htmlesc(mon_pass)}</code>\n"
        if details:
            txt += f"\n{GLASS_DOT} توضیحات:\n{htmlesc(details)}"
        await msg.bot.send_message(int(o['user_id']), txt, parse_mode='HTML', disable_web_page_preview=True)
    except Exception:
        pass

    await msg.answer('✅ تحویل ثبت شد و به کاربر ارسال شد.', reply_markup=kb([[('برگشت','admin:manual')]]))

# -------------------------
# Admin: EUR pricing config
# -------------------------
async def _safe_edit(msg: Message, text: str, reply_markup=None):
    try:
        await msg.edit_text(text, reply_markup=reply_markup)
    except TelegramBadRequest as e:
        if "message is not modified" in str(e).lower():
            return
        raise

@router.callback_query(F.data == "admin:pricing")
async def admin_pricing_menu(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    cfg = await get_pricing_cfg(db)
    mode = cfg["eur_margin_mode"]

    rate_txt = money(int(cfg["eur_rate_irt"]))
    mode_txt = "پلکانی" if mode == "tiered" else "ثابت"

    text = (
        "💶 قیمت‌گذاری یورو\n"
        "━━━━━━━━━━━━━━\n"
        f"💱 نرخ یورو: {rate_txt} (برای €1)\n\n"
        f"📊 حالت سود: {mode_txt}\n"
    )

    if mode == "flat":
        text += f"▫️ سود ثابت: {cfg['eur_margin_flat_pct']}%\n"
    else:
        text += (
            f"▫️ سود پلن ارزان: {cfg['eur_margin_low_pct']}%\n"
            f"▫️ سود پلن گران: {cfg['eur_margin_high_pct']}%\n"
            f"▫️ مرز قیمت ماهانه: €{cfg['eur_margin_threshold_eur']}\n"
        )

    rows = [
        [("✏️ تغییر نرخ یورو", "admin:pricing:set:rate")],
        [("🔁 تغییر حالت سود", "admin:pricing:toggle_mode")],
    ]
    if mode == "flat":
        rows.append([("✏️ تغییر درصد سود ثابت", "admin:pricing:set:flat")])
    else:
        rows += [
            [("✏️ درصد سود پلن ارزان", "admin:pricing:set:low")],
            [("✏️ درصد سود پلن گران", "admin:pricing:set:high")],
            [("✏️ مرز قیمت (€)", "admin:pricing:set:thr")],
        ]
    rows += [[("برگشت", "admin:general")]]
    await _safe_edit(cq.message, text, reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data == "admin:pricing:toggle_mode")
async def admin_pricing_toggle_mode(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    cur = (await db.get_setting("eur_margin_mode", "tiered")) or "tiered"
    new = "flat" if cur == "tiered" else "tiered"
    await db.set_setting("eur_margin_mode", new)
    await admin_pricing_menu(cq, db, state)

@router.callback_query(F.data == "admin:pricing:set:rate")
async def admin_pricing_set_rate(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPricingFlow.set_rate)
    await cq.message.edit_text(f"{glass_header('نرخ یورو')}\n{GLASS_DOT} عدد تومان برای هر 1€ را بفرست (مثلاً 160000):")
    await cq.answer()

@router.message(AdminPricingFlow.set_rate)
async def admin_pricing_set_rate_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        rate = int((msg.text or '').strip().replace(',', ''))
        if rate <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.set_setting("eur_rate_irt", str(rate))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("💶 قیمت‌گذاری (یورو)", "admin:pricing")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:pricing:set:flat")
async def admin_pricing_set_flat(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPricingFlow.set_flat_pct)
    await cq.message.edit_text(f"{glass_header('سود ثابت')}\n{GLASS_DOT} درصد سود (مثلاً 15):")
    await cq.answer()

@router.message(AdminPricingFlow.set_flat_pct)
async def admin_pricing_set_flat_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        pct = float((msg.text or '').strip().replace(',', '.'))
        if pct < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.set_setting("eur_margin_flat_pct", str(pct))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("💶 قیمت‌گذاری (یورو)", "admin:pricing")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:pricing:set:low")
async def admin_pricing_set_low(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPricingFlow.set_low_pct)
    await cq.message.edit_text(f"{glass_header('سود پلن ارزان')}\n{GLASS_DOT} درصد سود (مثلاً 15):")
    await cq.answer()

@router.message(AdminPricingFlow.set_low_pct)
async def admin_pricing_set_low_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        pct = float((msg.text or '').strip().replace(',', '.'))
        if pct < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.set_setting("eur_margin_low_pct", str(pct))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("💶 قیمت‌گذاری (یورو)", "admin:pricing")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:pricing:set:high")
async def admin_pricing_set_high(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPricingFlow.set_high_pct)
    await cq.message.edit_text(f"{glass_header('سود پلن گران')}\n{GLASS_DOT} درصد سود (مثلاً 8):")
    await cq.answer()

@router.message(AdminPricingFlow.set_high_pct)
async def admin_pricing_set_high_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        pct = float((msg.text or '').strip().replace(',', '.'))
        if pct < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.set_setting("eur_margin_high_pct", str(pct))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("💶 قیمت‌گذاری (یورو)", "admin:pricing")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:pricing:set:thr")
async def admin_pricing_set_thr(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPricingFlow.set_threshold)
    await cq.message.edit_text(f"{glass_header('مرز قیمت')}\n{GLASS_DOT} عدد یورو برای مرز پلکانی (مثلاً 10):")
    await cq.answer()

@router.message(AdminPricingFlow.set_threshold)
async def admin_pricing_set_thr_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        thr = float((msg.text or '').strip().replace(',', '.'))
        if thr <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await db.set_setting("eur_margin_threshold_eur", str(thr))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("💶 قیمت‌گذاری (یورو)", "admin:pricing")],[("🛠 پنل مدیریت","admin:home")]]))

# -------------------------
# Admin: Plans list/manage
# -------------------------
def _grp_label(g: str) -> str:
    g = (g or "all").lower()
    return {"all":"همه", "cx":"CX", "cpx":"CPX", "cax":"CAX"}.get(g, "همه")

@router.callback_query(F.data == "admin:plans")
async def admin_plans_countries(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    ccs = await db.list_all_plan_countries()
    if not ccs:
        return await _safe_edit(cq.message, f"{glass_header('لیست پلن‌ها')}\n{GLASS_DOT} هنوز پلنی ثبت نشده.", reply_markup=kb([[("برگشت","admin:home")]]))
    rows = []
    for cc in ccs:
        name = COUNTRY_NAMES.get(cc.upper(), cc.upper())
        rows.append([(f"{name} ({cc.upper()})", f"admin:plans:cc:{cc.upper()}:grp:all")])
    rows.append([("برگشت", "admin:home")])
    await _safe_edit(cq.message, f"{glass_header('کشورها')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

async def _render_admin_plans_list(msg: Message, db: DB, cc: str, grp: str):
    plans = await db.list_plans_admin(cc, grp)
    plan_ids = [int(p.get("id") or 0) for p in plans]
    counts = await db.get_plan_sales_counts(plan_ids) if plan_ids else {}

    header = "🧾 لیست پلن‌ها"
    line = "━━━━━━━━━━━━━━"
    text = (
        f"{header}\n{line}\n"
        f"🌍 کشور: {COUNTRY_NAMES.get(cc, cc)}\n"
        f"🧩 فیلتر: {_grp_label(grp)}"
    )

    if not plans:
        text += "\n\n▫️ هیچ پلنی ثبت نشده."
        return await _safe_edit(msg, text, reply_markup=kb([[("برگشت", "admin:plans")]]))

    rows = []
    for p in plans[:50]:
        pid = int(p.get("id") or 0)
        eff = await plan_effective_prices(db, p)
        sold = int(counts.get(pid, 0))
        status = "✅ فعال" if p.get("is_active") else "🚫 غیرفعال"

        name = (p.get("server_type") or p.get("title") or "-").upper()
        title = p.get("title") or "-"

        monthly_irt = money(int(eff["monthly_irt"]))
        hourly_irt = int(eff["hourly_irt"] or 0)
        hourly_txt = money(hourly_irt) if (hourly_irt > 0 and p.get("hourly_enabled")) else "—"

        traffic = int(p.get("traffic_limit_gb") or 0)
        traffic_txt = "نامحدود" if traffic == 0 else f"{traffic}GB"

        eur_bits = []
        if eff.get("monthly_eur") is not None:
            eur_bits.append(f"€{eff['monthly_eur']:g}/mo")
        if eff.get("hourly_eur") is not None and p.get("hourly_enabled"):
            eur_bits.append(f"€{eff['hourly_eur']:g}/h")
        eur_part = (" | " + " | ".join(eur_bits)) if eur_bits else ""

        text += (
            f"\n\n🔹 {name} — {title}\n"
            f"🆔 ID: {pid}\n"
            f"💰 ماهانه: {monthly_irt}{eur_part}\n"
            f"⏱ ساعتی: {hourly_txt}\n"
            f"🌐 ترافیک: {traffic_txt}\n"
            f"📊 فروش: {sold}\n"
            f"{status}"
        )

        toggle_label = "⛔️ غیرفعال کن" if p.get("is_active") else "✅ فعال کن"
        # IMPORTANT: callback_data must match handlers:
        #   admin:plan:toggle:{pid}:cc:{cc}:grp:{grp}
        #   admin:plan:edit:{pid}:cc:{cc}:grp:{grp}
        #   admin:plan:del:{pid}:cc:{cc}:grp:{grp}
        rows.append([
            (toggle_label, f"admin:plan:toggle:{pid}:cc:{cc}:grp:{grp}"),
            ("✏️ ویرایش", f"admin:plan:edit:{pid}:cc:{cc}:grp:{grp}"),
            ("🗑 حذف", f"admin:plan:del:{pid}:cc:{cc}:grp:{grp}"),
        ])

    # Filter row
    rows = [[
        ("همه", f"admin:plans:cc:{cc}:grp:all"),
        ("CX",  f"admin:plans:cc:{cc}:grp:cx"),
        ("CPX", f"admin:plans:cc:{cc}:grp:cpx"),
        ("CAX", f"admin:plans:cc:{cc}:grp:cax"),
    ]] + rows + [[("برگشت", "admin:plans")]]

    await _safe_edit(msg, text, reply_markup=kb(rows))

@router.callback_query(F.data.startswith("admin:plans:cc:"))
async def admin_plans_by_country(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    parts = (cq.data or "").split(":")
    cc = parts[3] if len(parts) > 3 else ""
    grp = parts[5] if len(parts) > 5 else "all"
    await _render_admin_plans_list(cq.message, db, cc, grp)
    await cq.answer()

@router.callback_query(F.data.startswith("admin:plan:toggle:"))
async def admin_plan_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = (cq.data or "").split(":")
    pid = int(parts[3])
    cc = parts[5] if len(parts) > 5 else ""
    grp = parts[7] if len(parts) > 7 else "all"
    await db.toggle_plan_active(pid)
    await _render_admin_plans_list(cq.message, db, cc, grp)
    await cq.answer("انجام شد.")

@router.callback_query(F.data.startswith("admin:plan:del:"))
async def admin_plan_delete(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = (cq.data or "").split(":")
    pid = int(parts[3])
    cc = parts[5] if len(parts) > 5 else ""
    grp = parts[7] if len(parts) > 7 else "all"
    await cq.message.edit_text(
        f"{glass_header('حذف پلن')}\n{GLASS_DOT} مطمئنی پلن ID:{pid} حذف شود؟",
        reply_markup=kb([[("✅ بله حذف کن", f"admin:plan:delok:{pid}:cc:{cc}:grp:{grp}")],[("⬅️ لغو", f"admin:plans:cc:{cc}:grp:{grp}")]])
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:plan:delok:"))
async def admin_plan_delete_ok(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = (cq.data or "").split(":")
    pid = int(parts[3])
    cc = parts[5] if len(parts) > 5 else ""
    grp = parts[7] if len(parts) > 7 else "all"
    await db.delete_plan(pid)
    await _render_admin_plans_list(cq.message, db, cc, grp)
    await cq.answer("حذف شد.")

@router.callback_query(F.data.regexp(r"^admin:plan:edit:\d+($|:)"))
async def admin_plan_edit(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    parts = (cq.data or "").split(":")
    pid = int(parts[3])
    cc = parts[5] if len(parts) > 5 else ""
    grp = parts[7] if len(parts) > 7 else "all"
    plan = await db.get_plan(pid)
    if not plan:
        return await cq.answer("پلن پیدا نشد.", show_alert=True)
    eff = await plan_effective_prices(db, plan)
    traffic_gb = int(plan.get("traffic_limit_gb") or 0)
    traffic_txt = "نامحدود" if traffic_gb <= 0 else f"{traffic_gb} GB"
    await state.clear()
    await state.update_data(edit_plan_id=pid, edit_cc=cc, edit_grp=grp)
    text = (
        f"{glass_header('ویرایش پلن')}\n"
        f"{GLASS_DOT} {plan.get('server_type')} — {plan.get('title')} (ID:{pid})\n"
        f"{GLASS_DOT} ماهانه: €{plan.get('price_monthly_eur')} → {money(eff['monthly_irt'])}\n"
        f"{GLASS_DOT} حجم: {traffic_txt}\n"
    )
    if plan.get("hourly_enabled"):
        text += f"{GLASS_DOT} ساعتی: €{plan.get('price_hourly_eur')} → {money(eff['hourly_irt'])}\n"
    rows = [
        [("✏️ تغییر ماهانه (€)", "admin:plan:edit:set_monthly")],
        [("✏️ تغییر ساعتی (€)", "admin:plan:edit:set_hourly")],
        [("✏️ تغییر حجم (GB)", "admin:plan:edit:set_traffic")],
        [("🔁 ساعتی روشن/خاموش", "admin:plan:edit:toggle_hourly")],
        [("برگشت", f"admin:plans:cc:{cc}:grp:{grp}")],
    ]
    await _safe_edit(cq.message, text, reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data == "admin:plan:edit:set_monthly")
async def admin_plan_edit_set_monthly(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPlanEditFlow.set_monthly_eur)
    await cq.message.edit_text(f"{glass_header('ویرایش ماهانه')}\n{GLASS_DOT} قیمت ماهانه به یورو را بفرست (مثلاً 2.99):")
    await cq.answer()

@router.message(AdminPlanEditFlow.set_monthly_eur)
async def admin_plan_edit_set_monthly_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    pid = int(data.get("edit_plan_id") or 0)
    if pid <= 0:
        await state.clear()
        return await msg.answer("خطا. دوباره از پنل مدیریت وارد شو.")
    try:
        val = float((msg.text or '').strip().replace(',', '.'))
        if val <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    plan = await db.get_plan(pid)
    cfg = await get_pricing_cfg(db)
    monthly_irt = eur_to_irt(val, cfg, monthly_eur_for_tier=val, step=1000)
    hourly_eur = float(plan.get("price_hourly_eur") or 0.0)
    hourly_irt = eur_to_irt(hourly_eur, cfg, monthly_eur_for_tier=val, step=100) if plan.get("hourly_enabled") else 0
    await db.update_plan_prices(pid, monthly_eur=float(val), hourly_eur=hourly_eur, monthly_irt=int(monthly_irt), hourly_irt=int(hourly_irt), hourly_enabled=bool(plan.get("hourly_enabled")))
    await state.clear()
    await msg.answer("✅ ذخیره شد. از لیست پلن‌ها ادامه بده.", reply_markup=kb([[("📋 لیست پلن‌ها","admin:plans")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:plan:edit:set_hourly")
async def admin_plan_edit_set_hourly(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPlanEditFlow.set_hourly_eur)
    await cq.message.edit_text(f"{glass_header('ویرایش ساعتی')}\n{GLASS_DOT} قیمت ساعتی به یورو را بفرست (مثلاً 0.005). 0 = خاموش:")
    await cq.answer()

@router.message(AdminPlanEditFlow.set_hourly_eur)
async def admin_plan_edit_set_hourly_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    pid = int(data.get("edit_plan_id") or 0)
    if pid <= 0:
        await state.clear()
        return await msg.answer("خطا. دوباره از پنل مدیریت وارد شو.")
    try:
        val = float((msg.text or '').strip().replace(',', '.'))
        if val < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    plan = await db.get_plan(pid)
    cfg = await get_pricing_cfg(db)
    monthly_eur = float(plan.get("price_monthly_eur") or 0.0)
    monthly_irt = eur_to_irt(monthly_eur, cfg, monthly_eur_for_tier=monthly_eur, step=1000) if monthly_eur>0 else int(plan.get("price_monthly_irt") or 0)
    hourly_enabled = bool(plan.get("hourly_enabled")) and val > 0
    hourly_irt = eur_to_irt(val, cfg, monthly_eur_for_tier=monthly_eur, step=100) if hourly_enabled else 0
    await db.update_plan_prices(pid, monthly_eur=plan.get("price_monthly_eur"), hourly_eur=float(val), monthly_irt=int(monthly_irt), hourly_irt=int(hourly_irt), hourly_enabled=hourly_enabled)
    await state.clear()
    await msg.answer("✅ ذخیره شد. از لیست پلن‌ها ادامه بده.", reply_markup=kb([[("📋 لیست پلن‌ها","admin:plans")],[("🛠 پنل مدیریت","admin:home")]]))

@router.callback_query(F.data == "admin:plan:edit:set_traffic")
async def admin_plan_edit_set_traffic(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminPlanEditFlow.set_traffic_gb)
    await cq.message.edit_text(
        f"{glass_header('ویرایش حجم')}\n{GLASS_DOT} سقف ترافیک را به GB بفرست (مثلاً 20000). 0 = نامحدود:",
        reply_markup=kb([[("برگشت", "admin:plan:edit:back")]]),
    )
    await cq.answer()

@router.callback_query(F.data == "admin:plan:edit:back")
async def admin_plan_edit_back(cq: CallbackQuery, db: DB, state: FSMContext):
    """Return to the plan edit menu for the currently edited plan."""
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    data = await state.get_data()
    pid = int(data.get("edit_plan_id") or 0)
    cc = data.get("edit_cc") or ""
    grp = data.get("edit_grp") or "all"
    if pid <= 0:
        await state.clear()
        return await cq.answer("خطا. دوباره از پنل مدیریت وارد شو.", show_alert=True)
    # Re-render edit menu
    plan = await db.get_plan(pid)
    if not plan:
        await state.clear()
        return await cq.answer("پلن پیدا نشد.", show_alert=True)
    eff = await plan_effective_prices(db, plan)
    traffic_gb = int(plan.get("traffic_limit_gb") or 0)
    traffic_txt = "نامحدود" if traffic_gb <= 0 else f"{traffic_gb} GB"
    text = (
        f"{glass_header('ویرایش پلن')}\n"
        f"{GLASS_DOT} {plan.get('server_type')} — {plan.get('title')} (ID:{pid})\n"
        f"{GLASS_DOT} ماهانه: €{plan.get('price_monthly_eur')} → {money(eff['monthly_irt'])}\n"
        f"{GLASS_DOT} حجم: {traffic_txt}\n"
    )
    if plan.get("hourly_enabled"):
        text += f"{GLASS_DOT} ساعتی: €{plan.get('price_hourly_eur')} → {money(eff['hourly_irt'])}\n"
    rows = [
        [("✏️ تغییر ماهانه (€)", "admin:plan:edit:set_monthly")],
        [("✏️ تغییر ساعتی (€)", "admin:plan:edit:set_hourly")],
        [("✏️ تغییر حجم (GB)", "admin:plan:edit:set_traffic")],
        [("🔁 ساعتی روشن/خاموش", "admin:plan:edit:toggle_hourly")],
        [("برگشت", f"admin:plans:cc:{cc}:grp:{grp}")],
    ]
    await _safe_edit(cq.message, text, reply_markup=kb(rows))
    await cq.answer()

@router.message(AdminPlanEditFlow.set_traffic_gb)
async def admin_plan_edit_set_traffic_msg(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    pid = int(data.get("edit_plan_id") or 0)
    cc = data.get("edit_cc") or ""
    grp = data.get("edit_grp") or "all"
    if pid <= 0:
        await state.clear()
        return await msg.answer("خطا. دوباره از پنل مدیریت وارد شو.")
    raw = (msg.text or "").strip().replace(",", "").replace(" ", "")
    if not raw.isdigit():
        return await msg.answer("عدد معتبر نیست. مثال: 20000 یا 0 برای نامحدود.")
    val = int(raw)
    if val < 0:
        return await msg.answer("عدد معتبر نیست.")
    await db.update_plan_traffic_limit(pid, traffic_limit_gb=val)
    await state.clear()
    await msg.answer(
        "✅ ذخیره شد. از لیست پلن‌ها ادامه بده.",
        reply_markup=kb([[("⬅️ لیست پلن‌ها", f"admin:plans:cc:{cc}:grp:{grp}")],[("🛠 پنل مدیریت","admin:home")]]),
    )

@router.callback_query(F.data == "admin:plan:edit:toggle_hourly")
async def admin_plan_edit_toggle_hourly(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    data = await state.get_data()
    pid = int(data.get("edit_plan_id") or 0)
    cc = data.get("edit_cc") or ""
    grp = data.get("edit_grp") or "all"
    plan = await db.get_plan(pid)
    if not plan:
        await state.clear()
        return await cq.answer("پلن پیدا نشد.", show_alert=True)
    new_enabled = not bool(plan.get("hourly_enabled"))
    cfg = await get_pricing_cfg(db)
    monthly_eur = float(plan.get("price_monthly_eur") or 0.0)
    monthly_irt = eur_to_irt(monthly_eur, cfg, monthly_eur_for_tier=monthly_eur, step=1000) if monthly_eur>0 else int(plan.get("price_monthly_irt") or 0)
    hourly_eur = float(plan.get("price_hourly_eur") or 0.0)
    hourly_irt = eur_to_irt(hourly_eur, cfg, monthly_eur_for_tier=monthly_eur, step=100) if (new_enabled and hourly_eur>0) else 0
    await db.update_plan_prices(pid, monthly_eur=plan.get("price_monthly_eur"), hourly_eur=hourly_eur, monthly_irt=int(monthly_irt), hourly_irt=int(hourly_irt), hourly_enabled=new_enabled)
    await _render_admin_plans_list(cq.message, db, cc, grp)
    await cq.answer("انجام شد.")

@router.callback_query(F.data == "admin:addplan")
async def admin_addplan_start(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    await state.set_state(AdminAddPlan.provider)
    await cq.message.edit_text(
        f"{glass_header('افزودن پلن')}\n{GLASS_DOT} دیتاسنتر:",
        reply_markup=kb([
            [("Hetzner Cloud", "admin:addplan:provider:hetzner")],
            [("Manual DC", "admin:addplan:provider:manual")],
            [("برگشت", "admin:home")]
        ])
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:addplan:provider:"))
async def admin_addplan_provider(cq: CallbackQuery, state: FSMContext):
    provider = cq.data.split(":")[-1]
    await state.update_data(provider=provider)
    await state.set_state(AdminAddPlan.country)

    # Manual sale: only Iran
    if (provider or "").lower() == "manual":
        await cq.message.edit_text(
            f"{glass_header('کشور')}\n{GLASS_DOT} انتخاب کشور:",
            reply_markup=kb([
                [("ایران (IR)", "admin:addplan:country:IR")],
                [("برگشت","admin:home")]
            ])
        )
        return await cq.answer()

    # Hetzner countries
    await cq.message.edit_text(
        f"{glass_header('کشور')}\n{GLASS_DOT} انتخاب کشور:",
        reply_markup=kb([
            [("آلمان (DE)", "admin:addplan:country:DE"), ("فنلاند (FI)", "admin:addplan:country:FI")],
            [("آمریکا (US)", "admin:addplan:country:US"), ("سنگاپور (SG)", "admin:addplan:country:SG")],
            [("برگشت","admin:home")]
        ])
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:addplan:country:"))
async def admin_addplan_country(cq: CallbackQuery, state: FSMContext):
    cc = cq.data.split(":")[-1]
    await state.update_data(country_code=cc)
    data = await state.get_data()
    provider = (data.get('provider') or 'hetzner').strip().lower()
    if provider == 'manual':
        await state.update_data(location_name='manual')
        await state.set_state(AdminAddPlan.server_type)
        await cq.message.edit_text(
            f"{glass_header('پلن دستی')}\n{GLASS_DOT} نام/کد پلن را بنویس (مثلاً M-...):",
            reply_markup=kb([[('برگشت','admin:home')]]),
        )
        return await cq.answer()

    await state.set_state(AdminAddPlan.location)
    locs = list_locations_for_country(cc)
    rows = [[(f"📍 {location_label(l)}", f"admin:addplan:loc:{l}")] for l in locs]
    rows.append([("برگشت","admin:home")])
    await cq.message.edit_text(f"{glass_header('لوکیشن')}\n{GLASS_DOT} انتخاب لوکیشن:", reply_markup=kb(rows))
    await cq.answer()


@router.callback_query(F.data.startswith("admin:addplan:loc:"))
async def admin_addplan_loc(cq: CallbackQuery, state: FSMContext):
    loc = cq.data.split(":")[-1]
    await state.update_data(location_name=loc)

    data = await state.get_data()
    cc = data.get("country_code", "")

    await state.set_state(AdminAddPlan.server_type_group)

    groups = groups_for_country(cc)
    if not groups:
        groups = [("x86 (intel/AMD)", "cx"), ("Arm64 (Ampere)", "cax"), ("x86 AMD", "cpx")]

    rows = [[(f"🧩 {label}", f"admin:addplan:grp:{key}")] for (label, key) in groups]
    rows.append([("برگشت","admin:home")])

    await cq.message.edit_text(
        f"{glass_header('سرور تایپ')}\n{GLASS_DOT} معماری/سری سرور را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:addplan:grp:"))
async def admin_addplan_group(cq: CallbackQuery, state: FSMContext):
    grp = cq.data.split(":")[-1]
    await state.update_data(server_type_group=grp)
    await state.set_state(AdminAddPlan.server_type)

    client = hclient()
    types_ = server_types_for_group(grp)

    rows = []
    for st in types_:
        specs = get_server_type_specs(client, st) or {}
        rows.append([(f"{st.upper()} | {specs.get('vcpu','?')}vCPU {specs.get('ram_gb','?')}GB {specs.get('disk_gb','?')}GB", f"admin:addplan:stype:{st}")])
    rows.append([("برگشت","admin:home")])
    await cq.message.edit_text(f"{glass_header('سرور تایپ')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()


@router.message(AdminAddPlan.server_type)
async def admin_addplan_stype_text(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    provider = (data.get('provider') or 'hetzner').strip().lower()
    if provider != 'manual':
        return
    st = (msg.text or '').strip()
    if len(st) < 1:
        return await msg.answer('نام/کد پلن نامعتبر است.')
    await state.update_data(server_type=st)
    await state.set_state(AdminAddPlan.title)
    await msg.answer(f"{glass_header('عنوان')}\n{GLASS_DOT} عنوان پلن را بفرست:", reply_markup=kb([[('برگشت','admin:home')]]))

@router.callback_query(F.data.startswith("admin:addplan:stype:"))
async def admin_addplan_stype(cq: CallbackQuery, state: FSMContext):
    await state.update_data(server_type=cq.data.split(":")[-1])
    await state.set_state(AdminAddPlan.title)
    await cq.message.edit_text(f"{glass_header('عنوان')}\n{GLASS_DOT} عنوان پلن را بفرست:", reply_markup=kb([[("برگشت","admin:home")]]))
    await cq.answer()

@router.message(AdminAddPlan.title)
async def admin_addplan_title(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    title = (msg.text or "").strip()
    if len(title) < 2:
        return await msg.answer("عنوان کوتاه است.")
    await state.update_data(title=title)
    await state.set_state(AdminAddPlan.price_monthly)
    await msg.answer(f"{glass_header('قیمت ماهانه')}\n{GLASS_DOT} عدد یورو را بفرست (مثلاً 2.99):")

@router.message(AdminAddPlan.price_monthly)
async def admin_addplan_price_monthly(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        p = float((msg.text or '').strip().replace(',', '.'))
        if p <= 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    
    cfg = await get_pricing_cfg(db)
    monthly_irt = eur_to_irt(float(p), cfg, monthly_eur_for_tier=float(p), step=1000)
    await state.update_data(price_monthly_eur=float(p), price_monthly_irt=int(monthly_irt))

    await state.set_state(AdminAddPlan.traffic_limit)
    await msg.answer(f"{glass_header('سقف ترافیک')}\n{GLASS_DOT} سقف به گیگابایت (مثلاً 2000). 0 = نامحدود")

@router.message(AdminAddPlan.traffic_limit)
async def admin_addplan_traffic(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        t = int((msg.text or "").strip())
        if t < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")
    await state.update_data(traffic_limit_gb=t)
    await state.set_state(AdminAddPlan.hourly_enabled)
    await msg.answer(
        f"{glass_header('حالت ساعتی')}\n{GLASS_DOT} برای این پلن ساعتی فعال باشد؟",
        reply_markup=kb([[("✅ بله","admin:addplan:hourly:1"),("❌ خیر","admin:addplan:hourly:0")],[("برگشت","admin:home")]])
    )

@router.callback_query(F.data.startswith("admin:addplan:hourly:"))
async def admin_addplan_hourly(cq: CallbackQuery, state: FSMContext):
    enabled = cq.data.split(":")[-1] == "1"
    await state.update_data(hourly_enabled=enabled)
    await state.set_state(AdminAddPlan.price_hourly)
    if enabled:
        await cq.message.edit_text(f"{glass_header('قیمت ساعتی')}\n{GLASS_DOT} عدد یورو برای هر ساعت را بفرست (مثلاً 0.005):")
    else:
        await cq.message.edit_text(f"{glass_header('قیمت ساعتی')}\n{GLASS_DOT} ساعتی غیرفعال شد. عدد 0 ارسال کن (یورو).")
    await cq.answer()

@router.message(AdminAddPlan.price_hourly)
async def admin_addplan_price_hourly(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    try:
        p = float((msg.text or '').strip().replace(',', '.'))
        if p < 0:
            raise ValueError()
    except Exception:
        return await msg.answer("عدد معتبر نیست.")

    cfg = await get_pricing_cfg(db)
    data0 = await state.get_data()
    me = float(data0.get('price_monthly_eur') or 0.0)
    hourly_irt = eur_to_irt(float(p), cfg, monthly_eur_for_tier=me, step=100)
    await state.update_data(price_hourly_eur=float(p), price_hourly_irt=int(hourly_irt))

    data = await state.get_data()
    client = hclient()
    specs = get_server_type_specs(client, data["server_type"]) or {}
    plan_id = await db.create_plan({
        "provider": data["provider"],
        "country_code": data["country_code"],
        "location_name": data["location_name"],
        "server_type": data["server_type"],
        "title": data["title"],
        "vcpu": specs.get("vcpu"),
        "ram_gb": specs.get("ram_gb"),
        "disk_gb": specs.get("disk_gb"),
        "price_monthly_eur": data.get("price_monthly_eur"),
        "price_hourly_eur": data.get("price_hourly_eur", float(p) if data.get('hourly_enabled') else 0.0),
        "price_monthly_irt": int(data.get("price_monthly_irt") or 0),
        "hourly_enabled": bool(data.get("hourly_enabled")),
        "price_hourly_irt": int(data.get("price_hourly_irt") or 0),
        "traffic_limit_gb": data["traffic_limit_gb"],
        "is_active": True,
    })
    await state.clear()
    await msg.answer(f"✅ پلن اضافه شد (ID: {plan_id})", reply_markup=kb([[("🛠 پنل مدیریت","admin:home")],[("🏠 منوی اصلی","home")]]))

@router.callback_query(F.data == "admin:buttons")
async def admin_buttons(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    await cq.message.edit_text(
        f"{glass_header('تنظیم دکمه‌ها')}\n{GLASS_DOT} انتخاب کن:",
        reply_markup=kb([
            [("✏️ تغییر متن استارت", "admin:set:start_text")],
            [("🏷 تغییر اسم دکمه‌ها", "admin:labels")],
            [("🧱 چینش دکمه‌ها", "admin:layout")],
            [("➕ اضافه کردن دکمه جدید", "admin:addbtn")],
            [("🧷 دکمه‌های اضافه شده", "admin:cbtns")],
            [("🏦 تنظیم متن شماره کارت", "admin:set:card_text")],
            [("برگشت","admin:home")]
        ])
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:set:"))
async def admin_set_begin(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    key = cq.data.split(":")[-1]
    await state.update_data(admin_set_key=key)
    await state.set_state(AdminSetText.text)
    label = "متن استارت" if key == "start_text" else "متن شماره کارت"
    await cq.message.edit_text(f"{glass_header(label)}\n{GLASS_DOT} متن جدید را ارسال کن:", reply_markup=kb([[("برگشت","admin:buttons")]]))
    await cq.answer()

@router.message(AdminSetText.text)
async def admin_set_text(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    key = data.get("admin_set_key")
    val = (msg.text or "").strip()
    if not key or not val:
        return await msg.answer("متن خالی/نامعتبر است.")
    if key == "card_text":
        await db.set_setting("card_number_text", val)
    elif key == "start_text":
        await db.set_setting("start_text", val)
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("🛠 پنل مدیریت","admin:home")],[("🏠 منوی اصلی","home")]]))

@router.callback_query(F.data == "admin:labels")
async def admin_labels(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()

    # Ensure catalogs + cache
    await load_button_labels(db)
    _build_label_catalog()

    await _admin_labels_show_page(cq, db, page=0)
    await cq.answer()


async def _admin_labels_show_page(cq: CallbackQuery, db: DB, page: int = 0):
    per_page = 20  # 2-column => 10 rows
    keys = sorted(LABEL_DEFAULTS.keys())
    total = max(1, math.ceil(len(keys) / per_page))
    page = max(0, min(page, total - 1))

    chunk = keys[page * per_page:(page + 1) * per_page]

    btns: List[Tuple[str, str]] = []
    for k in chunk:
        default = LABEL_DEFAULTS.get(k, k)
        cur = BUTTON_LABELS.get(k) or default
        _id = LABEL_ID_BY_KEY.get(k)
        if not _id:
            continue
        btns.append((str(cur)[:32], f"admin:lbl:{_id}"))

    rows: List[List[Tuple[str, str]]] = []
    for i in range(0, len(btns), 2):
        rows.append(btns[i:i+2])

    nav = []
    if page > 0:
        nav.append(("⬅️ قبلی", f"admin:labels:page:{page-1}"))
    nav.append((f"صفحه {page+1}/{total}", "noop"))
    if page < total - 1:
        nav.append(("بعدی ➡️", f"admin:labels:page:{page+1}"))
    rows.append(nav[:2] if len(nav) > 2 else nav)
    if len(nav) > 2:
        rows.append(nav[2:])

    rows.append([("برگشت", "admin:buttons")])

    await cq.message.edit_text(
        f"{glass_header('تغییر اسم دکمه‌ها')}\n{GLASS_DOT} روی هر دکمه بزن تا اسم جدید براش بفرستی.\n{GLASS_DOT} (همهٔ زیرمنوها و کشورها هم شامل می‌شود.)",
        reply_markup=kb(rows),
    )


@router.callback_query(F.data.startswith("admin:labels:page:"))
async def admin_labels_page(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    await load_button_labels(db)
    _build_label_catalog()
    try:
        page = int(cq.data.split(":")[-1])
    except Exception:
        page = 0
    await _admin_labels_show_page(cq, db, page=page)
    await cq.answer()


@router.callback_query(F.data.startswith("admin:lbl:"))
async def admin_label_edit(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)

    _id = cq.data.split(":")[-1]
    key = LABEL_KEY_BY_ID.get(_id)
    if not key:
        return await cq.answer("یافت نشد.", show_alert=True)

    await state.set_state(AdminButtonsFlow.rename_value)
    await state.update_data(label_key=key)

    default = LABEL_DEFAULTS.get(key, key)
    cur = BUTTON_LABELS.get(key) or default

    await cq.message.edit_text(
        f"{glass_header('تغییر اسم دکمه')}\n{GLASS_DOT} کلید: <code>{htmlesc(key)}</code>\n{GLASS_DOT} فعلی: {htmlesc(str(cur))}\n\n{GLASS_DOT} اسم جدید را ارسال کن:",
        reply_markup=kb([
            [("♻️ بازنشانی به پیش‌فرض", f"admin:lblreset:{_id}")],
            [("برگشت", "admin:labels")],
        ]),
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:lblreset:"))
async def admin_label_reset(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    _id = cq.data.split(":")[-1]
    key = LABEL_KEY_BY_ID.get(_id)
    if not key:
        return await cq.answer("یافت نشد.", show_alert=True)

    # remove override
    raw = await db.get_setting("button_labels", "{}") or "{}"
    try:
        obj = json.loads(raw)
        if not isinstance(obj, dict):
            obj = {}
    except Exception:
        obj = {}
    obj.pop(key, None)
    await db.set_setting("button_labels", json.dumps(obj, ensure_ascii=False))
    await load_button_labels(db)

    await cq.answer("✅ بازنشانی شد.")
    # go back to list
    await _admin_labels_show_page(cq, db, page=0)


@router.message(AdminButtonsFlow.rename_value)
async def admin_label_set_value(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    key = data.get("label_key")
    if not key:
        await state.clear()
        return await msg.answer("مشکل در وضعیت. دوباره وارد شوید.")

    new_text = (msg.text or "").strip()
    if not new_text:
        return await msg.answer("متن خالی مجاز نیست.")

    raw = await db.get_setting("button_labels", "{}") or "{}"
    try:
        obj = json.loads(raw)
        if not isinstance(obj, dict):
            obj = {}
    except Exception:
        obj = {}
    obj[str(key)] = new_text
    await db.set_setting("button_labels", json.dumps(obj, ensure_ascii=False))
    await load_button_labels(db)

    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("برگشت", "admin:labels")]]))

@router.callback_query(F.data == "admin:layout")
async def admin_layout(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)

    await state.set_state(AdminButtonsFlow.menu_layout_json)

    try:
        cols = int(await db.get_setting("menu_cols", "2") or 2)
    except Exception:
        cols = 2
    cols = 1 if cols < 1 else (3 if cols > 3 else cols)

    lines = [
        "✏️ ترتیب جدید *همه دکمه‌های منوی اصلی* را با ارسال شماره‌ها مشخص کنید.",
        "",
        "1) ☁️خرید سرور ابری☁️",
        "2)📦سفارش های من📦",
        "3) 👥مسخصات حساب و شارژ👥",
        "4) 🛰وضعیت ای پی🛰",
        "5) دکمه‌های دستی",
        "",
        "مثال: 3,1,2,4,5",
        "نکته: باید دقیقاً 5 شماره بفرستید.",
    ]

    await cq.message.edit_text(
        f"{glass_header('چینش دکمه‌ها')}\n" + "\n".join(lines),
        reply_markup=kb([
            [(f"🔳 تعداد ستون‌ها: {cols}", "admin:layout:cols")],
            [("برگشت", "admin:buttons")],
        ]),
        parse_mode="Markdown",
    )
    await cq.answer()

@router.callback_query(F.data == "admin:layout:cols")
async def admin_layout_toggle_cols(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)

    try:
        cols = int(await db.get_setting("menu_cols", "2") or 2)
    except Exception:
        cols = 2
    cols = 1 if cols < 1 else (3 if cols > 3 else cols)
    cols = 1 if cols >= 3 else cols + 1
    await db.set_setting("menu_cols", str(cols))

    # redraw page
    lines = [
        "✏️ ترتیب جدید *همه دکمه‌های منوی اصلی* را با ارسال شماره‌ها مشخص کنید.",
        "",
        "1) ☁️خرید سرور ابری☁️",
        "2)📦سفارش های من📦",
        "3) 👥مسخصات حساب و شارژ👥",
        "4) 🛰وضعیت ای پی🛰",
        "5) دکمه‌های دستی",
        "",
        "مثال: 3,1,2,4,5",
        "نکته: باید دقیقاً 5 شماره بفرستید.",
    ]
    await cq.message.edit_text(
        f"{glass_header('چینش دکمه‌ها')}\n" + "\n".join(lines),
        reply_markup=kb([
            [(f"🔳 تعداد ستون‌ها: {cols}", "admin:layout:cols")],
            [("برگشت", "admin:buttons")],
        ]),
        parse_mode="Markdown",
    )
    await state.set_state(AdminButtonsFlow.menu_layout_json)
    await cq.answer(f"✅ ستون‌ها شد {cols}")


@router.message(AdminButtonsFlow.menu_layout_json)
async def admin_layout_set(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return

    txt = (msg.text or "").strip()
    # accept "3,1,2,4,5" (comma or space separated)
    parts = re.split(r"[\s,]+", txt)
    parts = [p for p in parts if p]

    if len(parts) != 5 or any(not p.isdigit() for p in parts):
        return await msg.answer("❌ فرمت نامعتبر است. مثال درست: 3,1,2,4,5\nنکته: باید دقیقاً 5 شماره بفرستید.")

    nums = [int(p) for p in parts]
    if sorted(nums) != [1,2,3,4,5]:
        return await msg.answer("❌ باید دقیقاً شماره‌های 1 تا 5 را (بدون تکرار) ارسال کنید. مثال: 3,1,2,4,5")

    num_to_key = {1:"buy", 2:"orders", 3:"profile", 4:"ip_status", 5:"custom"}
    menu_order = [num_to_key[n] for n in nums]

    await db.set_setting("menu_order", json.dumps(menu_order, ensure_ascii=False))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("برگشت","admin:buttons")]]))

@router.callback_query(F.data == "admin:cbtns")
async def admin_cbtns(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons", "[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    rows = []
    for i, b in enumerate(cbtns):
        title = str(b.get("title", ""))[:30] or f"#{i+1}"
        rows.append([(f"✏️ {title}", f"admin:cbtn:view:{i}")])
    rows.append([("برگشت", "admin:buttons")])
    await cq.message.edit_text(
        f"{glass_header('دکمه‌های اضافه شده')}\n{GLASS_DOT} یکی را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:cbtn:view:"))
async def admin_cbtn_view(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    idx = int(cq.data.split(":")[-1])
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons", "[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    if idx < 0 or idx >= len(cbtns):
        return await cq.answer("یافت نشد.", show_alert=True)
    b = cbtns[idx]
    await state.update_data(cbtn_idx=idx)
    await cq.message.edit_text(
        f"{glass_header('ویرایش دکمه')}\n"
        f"{GLASS_DOT} عنوان: {htmlesc(str(b.get('title','')))}\n"
        f"{GLASS_DOT} متن: {htmlesc(str(b.get('text',''))[:120])}",
        parse_mode="HTML",
        reply_markup=kb([
            [("✏️ تغییر عنوان", "admin:cbtn:edit_title")],
            [("📝 تغییر متن", "admin:cbtn:edit_text")],
            [("🗑 حذف", "admin:cbtn:delete")],
            [("برگشت", "admin:cbtns")],
        ]),
    )
    await cq.answer()

@router.callback_query(F.data == "admin:cbtn:edit_title")
async def admin_cbtn_edit_title(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminButtonsFlow.cbtn_edit_title)
    await cq.message.edit_text(f"{glass_header('تغییر عنوان')}\n{GLASS_DOT} عنوان جدید را بفرست:", reply_markup=kb([[("برگشت","admin:cbtns")]]))
    await cq.answer()

@router.message(AdminButtonsFlow.cbtn_edit_title)
async def admin_cbtn_edit_title_set(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    title = (msg.text or "").strip()
    data = await state.get_data()
    idx = int(data.get("cbtn_idx",-1))
    if idx < 0:
        await state.clear()
        return await msg.answer("خطا.")
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons","[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    if idx >= len(cbtns):
        await state.clear()
        return await msg.answer("یافت نشد.")
    cbtns[idx]["title"] = title
    await db.set_setting("custom_buttons", json.dumps(cbtns, ensure_ascii=False))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("برگشت","admin:cbtns")]]))

@router.callback_query(F.data == "admin:cbtn:edit_text")
async def admin_cbtn_edit_text(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminButtonsFlow.cbtn_edit_text)
    await cq.message.edit_text(f"{glass_header('تغییر متن')}\n{GLASS_DOT} متن جدید را بفرست:", reply_markup=kb([[("برگشت","admin:cbtns")]]))
    await cq.answer()

@router.message(AdminButtonsFlow.cbtn_edit_text)
async def admin_cbtn_edit_text_set(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    txt = (msg.text or "").strip()
    data = await state.get_data()
    idx = int(data.get("cbtn_idx",-1))
    if idx < 0:
        await state.clear()
        return await msg.answer("خطا.")
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons","[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    if idx >= len(cbtns):
        await state.clear()
        return await msg.answer("یافت نشد.")
    cbtns[idx]["text"] = txt
    await db.set_setting("custom_buttons", json.dumps(cbtns, ensure_ascii=False))
    await state.clear()
    await msg.answer("✅ ذخیره شد.", reply_markup=kb([[("برگشت","admin:cbtns")]]))

@router.callback_query(F.data == "admin:cbtn:delete")
async def admin_cbtn_delete(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    data = await state.get_data()
    idx = int(data.get("cbtn_idx",-1))
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons","[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    if idx < 0 or idx >= len(cbtns):
        await state.clear()
        return await cq.answer("یافت نشد.", show_alert=True)
    cbtns.pop(idx)
    await db.set_setting("custom_buttons", json.dumps(cbtns, ensure_ascii=False))
    await state.clear()
    await cq.message.edit_text("✅ حذف شد.", reply_markup=kb([[("برگشت","admin:cbtns")]]))
    await cq.answer()
@router.callback_query(F.data == "admin:addbtn")
async def admin_addbtn_start(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminButtonsFlow.newbtn_title)
    await cq.message.edit_text(f"{glass_header('دکمه جدید')}\n{GLASS_DOT} اسم دکمه را بفرست:", reply_markup=kb([[("برگشت","admin:buttons")]]))
    await cq.answer()

@router.message(AdminButtonsFlow.newbtn_title)
async def admin_addbtn_title(msg: Message, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    title = (msg.text or "").strip()
    if len(title) < 1:
        return await msg.answer("کوتاه است.")
    await state.update_data(newbtn_title=title)
    await state.set_state(AdminButtonsFlow.newbtn_text)
    await msg.answer(f"{glass_header('متن دکمه')}\n{GLASS_DOT} متن را بفرست:")

@router.message(AdminButtonsFlow.newbtn_text)
async def admin_addbtn_text(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    data = await state.get_data()
    title = data.get("newbtn_title","")
    txt = (msg.text or "").strip()
    if not title or not txt:
        return await msg.answer("نام/متن نامعتبر است.")
    try:
        cbtns = json.loads(await db.get_setting("custom_buttons","[]") or "[]")
        if not isinstance(cbtns, list):
            cbtns = []
    except Exception:
        cbtns = []
    cbtns.append({"title": title, "text": txt})
    await db.set_setting("custom_buttons", json.dumps(cbtns, ensure_ascii=False))
    await state.clear()
    await msg.answer("✅ اضافه شد.", reply_markup=kb([[("برگشت","admin:buttons")]]))

@router.callback_query(F.data == "admin:general")
async def admin_general(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    bot_enabled = (await db.get_setting("bot_enabled", "1")) == "1"
    renew_enabled = (await db.get_setting("renew_enabled", "0")) == "1"
    hourly_buy = (await db.get_setting("hourly_buy_enabled", "0")) == "1"
    manual_sale = (await db.get_setting("manual_sale_enabled", "1")) == "1"
    glass_btns = (await db.get_setting("glass_buttons_enabled", "1")) == "1"
    await cq.message.edit_text(
        f"{glass_header('مدیریت عمومی')}\n{GLASS_DOT} وضعیت‌ها:",
        reply_markup=kb([
            [(f"🤖 وضعیت ربات: {'روشن ✅' if bot_enabled else 'خاموش ❌'}", "admin:toggle:bot")],
            [(f"♻️ دکمه تمدید: {'روشن ✅' if renew_enabled else 'خاموش ❌'}", "admin:toggle:renew")],
            [(f"⏱ خرید ساعتی: {'روشن ✅' if hourly_buy else 'خاموش ❌'}", "admin:toggle:hourlybuy")],
            [(f"🧾 فروش دستی: {'روشن ✅' if manual_sale else 'خاموش ❌'}", "admin:toggle:manualsale")],
            [(f"🫧 تغییر نمایش دکمه‌ها: {'شیشه‌ای ✅' if glass_btns else 'عادی'}", "admin:toggle:glassbuttons")],
            [("📈 آمار", "admin:stats")],
            [("👥 کاربران", "admin:users")],
            [("🌍 تنظیم کشور", "admin:countrycfg")],
            [("💶 قیمت‌گذاری (یورو)", "admin:pricing")],
            [("🗄 بکاپ", "admin:backup")],
            [("برگشت","admin:home")]
        ])
    )
    await cq.answer()


# -------------------------
# Admin: Backup submenu
# -------------------------
@router.callback_query(F.data == "admin:backup")
async def admin_backup_menu(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    auto_enabled = (await db.get_setting("backup_auto_send", "0")) == "1"
    st = "روشن ✅" if auto_enabled else "خاموش ❌"
    await cq.message.edit_text(
        f"{glass_header('بکاپ')}\n{GLASS_DOT} انتخاب کن:",
        reply_markup=kb([
            [(f"🕒 دریافت بکاپ خودکار: {st}", "admin:backup:auto")],
            [("🗄 دریافت بکاپ دستی (همین الان)", "admin:backup:manual")],
            [("📤 آپلود دیتابیس", "admin:backup:upload")],
            [("برگشت","admin:general")],
        ])
    )
    await cq.answer()

@router.callback_query(F.data == "admin:backup:auto")
async def admin_backup_auto_prompt(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminBackupFlow.auto_value)
    await cq.message.edit_text(
        f"{glass_header('بکاپ خودکار')}\n{GLASS_DOT} عدد بفرست:\n\n0 = خاموش\n1 یا هر عددی = همین الان بکاپ بفرست و خودکار را روشن کن",
        reply_markup=kb([[("برگشت","admin:backup")]])
    )
    await cq.answer()

@router.message(AdminBackupFlow.auto_value)
async def admin_backup_auto_set(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    val = (msg.text or "").strip()
    try:
        n = int(val)
    except Exception:
        n = 1
    if n <= 0:
        await db.set_setting("backup_auto_send", "0")
        await state.clear()
        return await msg.answer("✅ بکاپ خودکار خاموش شد.", reply_markup=kb([[("برگشت","admin:backup")]]))

    # Enable + send immediately
    await db.set_setting("backup_auto_send", "1")
    await db.set_setting("backup_auto_value", str(n))
    try:
        path = await db.create_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX, keep_last=DB_BACKUP_KEEP_LAST)
    except Exception:
        path = db.get_latest_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX)

    if not path or not os.path.exists(path):
        await state.clear()
        return await msg.answer("❌ بکاپی پیدا نشد.", reply_markup=kb([[("برگشت","admin:backup")]]))

    cap = f"🗄 بکاپ دیتابیس (خودکار)\n{GLASS_DOT} فایل: <code>{htmlesc(os.path.basename(path))}</code>"
    try:
        await msg.bot.send_document(msg.from_user.id, FSInputFile(path), caption=cap, parse_mode="HTML")
    except Exception as e:
        await state.clear()
        return await msg.answer(f"❌ خطا در ارسال فایل: {htmlesc(str(e))}", reply_markup=kb([[("برگشت","admin:backup")]]))

    await state.clear()
    await msg.answer("✅ فعال شد و بکاپ همین الان ارسال شد.", reply_markup=kb([[("برگشت","admin:backup")]]))

@router.callback_query(F.data == "admin:backup:manual")
async def admin_backup_manual(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await cq.answer("در حال آماده‌سازی…")
    try:
        path = await db.create_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX, keep_last=DB_BACKUP_KEEP_LAST)
    except Exception:
        path = db.get_latest_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX)
    if not path or not os.path.exists(path):
        return await cq.message.answer("❌ بکاپی پیدا نشد.")
    cap = f"🗄 بکاپ دیتابیس (دستی)\n{GLASS_DOT} فایل: <code>{htmlesc(os.path.basename(path))}</code>"
    try:
        await cq.bot.send_document(cq.from_user.id, FSInputFile(path), caption=cap, parse_mode="HTML")
    except Exception as e:
        return await cq.message.answer(f"❌ خطا در ارسال فایل: {htmlesc(str(e))}")
    await cq.message.answer("✅ بکاپ ارسال شد.", reply_markup=kb([[("برگشت","admin:backup")]]))

@router.callback_query(F.data == "admin:backup:upload")
async def admin_backup_upload_prompt(cq: CallbackQuery, db: DB, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminBackupFlow.upload_db)
    await cq.message.edit_text(
        f"{glass_header('آپلود دیتابیس')}\n{GLASS_DOT} فایل دیتابیس SQLite را ارسال کن (پسوند .sqlite3):",
        reply_markup=kb([[("برگشت","admin:backup")]])
    )
    await cq.answer()

@router.message(AdminBackupFlow.upload_db)
async def admin_backup_upload_apply(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    if not msg.document:
        return await msg.answer("❌ لطفاً فایل دیتابیس را به صورت Document ارسال کن.", reply_markup=kb([[("برگشت","admin:backup")]]))

    await msg.answer("⏳ در حال دانلود و اعمال…")
    try:
        f = await msg.bot.get_file(msg.document.file_id)
        tmp_dir = os.path.join(DB_BACKUP_DIR, "_uploads")
        os.makedirs(tmp_dir, exist_ok=True)
        tmp_path = os.path.join(tmp_dir, f"uploaded_{int(time.time())}.sqlite3")
        await msg.bot.download_file(f.file_path, destination=tmp_path)

        # Safety backup of current DB
        try:
            await db.create_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX, keep_last=DB_BACKUP_KEEP_LAST)
        except Exception:
            pass

        # Replace DB file
        shutil.copyfile(tmp_path, DB_PATH)

        # Ensure schema/migrations exist
        try:
            await db.init()
        except Exception:
            pass

    except Exception as e:
        await state.clear()
        return await msg.answer(f"❌ خطا: {htmlesc(str(e))}", reply_markup=kb([[("برگشت","admin:backup")]]))

    await state.clear()
    await msg.answer("✅ دیتابیس اعمال شد.", reply_markup=kb([[("برگشت","admin:backup")]]))

@router.callback_query(F.data.startswith("admin:toggle:"))
async def admin_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    key = cq.data.split(":")[-1]
    mapkey = {
        "bot": "bot_enabled",
        "renew": "renew_enabled",
        "hourlybuy": "hourly_buy_enabled",
        "manualsale": "manual_sale_enabled",
        "glassbuttons": "glass_buttons_enabled",
    }[key]
    cur = (await db.get_setting(mapkey, "0")) == "1"
    await db.set_setting(mapkey, "0" if cur else "1")
    if key == "glassbuttons":
        # keep in-memory flag in sync
        await load_glass_buttons_pref(db)
    await cq.answer("انجام شد.")
    await admin_general(cq, db)


# -------------------------

# -------------------------
# Admin: Country/Location server-type-group settings
# -------------------------
def _country_label(cc: str) -> str:
    cc = (cc or "").upper()
    name = COUNTRY_NAMES.get(cc, cc)
    flag = {"DE": "🇩🇪", "FI": "🇫🇮", "US": "🇺🇸", "SG": "🇸🇬"}.get(cc, "🌍")
    return f"{flag} {name} ({cc})"

def _loc_label(loc: str) -> str:
    return f"📍 {location_label(loc)}"

async def _render_location_groups_screen(msg, db: DB, cc: str, loc: str):
    cc = (cc or "").upper()
    loc = (loc or "").lower()

    rows = []
    for label, key, _types in SERVER_TYPE_GROUPS:
        enabled = await get_country_location_group_flag(db, cc, loc, key)
        st = "موجود ✅" if enabled else "ناموجود ❌"
        rows.append([(f"{label} — {st}", f"admin:countrycfg:toggle:{cc}:{loc}:{key}")])

    rows.append([("برگشت", f"admin:countrycfg:pick:{cc}")])
    await msg.edit_text(
        f"{glass_header('تنظیم کشور')}\n"
        f"{GLASS_DOT} کشور: {_country_label(cc)}\n"
        f"{GLASS_DOT} شهر/لوکیشن: {_loc_label(loc)}\n"
        f"{GLASS_DOT} وضعیت نمایش برای کاربر:",
        reply_markup=kb(rows),
    )

@router.callback_query(F.data == "admin:countrycfg")
async def admin_countrycfg(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)

    # Countries available in bot (based on COUNTRY_LOCATIONS)
    cfg = await get_countries_enabled_cfg(db)

    rows = []
    for cc in COUNTRY_LOCATIONS.keys():
        cc_u = (cc or "").upper()
        en = bool(int(cfg.get(cc_u, 1)))
        st = "روشن ✅" if en else "خاموش ❌"
        toggle_txt = "خاموش کردن" if en else "روشن کردن"
        rows.append([
            (f"{_country_label(cc_u)} — {st}", f"admin:countrycfg:pick:{cc_u}"),
            (f"🔁 {toggle_txt}", f"admin:countrycfg:ctoggle:{cc_u}")
        ])
    rows.append([("برگشت", "admin:general")])

    await cq.message.edit_text(
        f"{glass_header('تنظیم کشور')}\n{GLASS_DOT} کشور را انتخاب کن (یا وضعیت را تغییر بده):",
        reply_markup=kb(rows),
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:countrycfg:ctoggle:"))
async def admin_countrycfg_country_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    cc = cq.data.split(":")[-1].upper()
    cur = await is_country_enabled(db, cc)
    await set_country_enabled_flag(db, cc, not cur)
    await cq.answer("ذخیره شد ✅")
    await admin_countrycfg(cq, db)

@router.callback_query(F.data.startswith("admin:countrycfg:pick:"))
async def admin_countrycfg_pick(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    cc = cq.data.split(":")[-1].upper()
    locs = list_locations_for_country(cc)

    rows = []
    for loc in locs:
        rows.append([(_loc_label(loc), f"admin:countrycfg:loc:{cc}:{loc}")])
    rows.append([("برگشت", "admin:countrycfg")])

    await cq.message.edit_text(
        f"{glass_header('تنظیم کشور')}\n{GLASS_DOT} کشور: {_country_label(cc)}\n{GLASS_DOT} شهر/لوکیشن را انتخاب کن:",
        reply_markup=kb(rows),
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:countrycfg:loc:"))
async def admin_countrycfg_loc(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    _, _, _, cc, loc = cq.data.split(":", 4)
    cc = cc.upper()
    loc = loc.lower()
    await _render_location_groups_screen(cq.message, db, cc, loc)
    await cq.answer()

@router.callback_query(F.data.startswith("admin:countrycfg:toggle:"))
async def admin_countrycfg_toggle(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    # admin:countrycfg:toggle:CC:LOC:GROUP
    parts = cq.data.split(":")
    if len(parts) < 6:
        return await cq.answer("نامعتبر", show_alert=True)
    cc = parts[3].upper()
    loc = parts[4].lower()
    key = parts[5].lower()

    cur = await get_country_location_group_flag(db, cc, loc, key)
    await set_country_location_group_flag(db, cc, loc, key, not cur)

    await _render_location_groups_screen(cq.message, db, cc, loc)
    await cq.answer("ذخیره شد ✅")

@router.callback_query(F.data == "admin:stats")
async def admin_stats(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    st = await db.stats()
    await cq.message.edit_text(
        f"{glass_header('آمار')}\n{GLASS_DOT} کاربران: {st['users']}\n{GLASS_DOT} کل سفارش‌ها: {st['orders']}\n{GLASS_DOT} فعال: {st['active_orders']}",
        reply_markup=kb([[("برگشت","admin:general")]])
    )
    await cq.answer()

@router.callback_query(F.data == "admin:users")
async def admin_users(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.clear()
    await cq.message.edit_text(
        f"{glass_header('کاربران')}\n{GLASS_DOT} انتخاب کن:",
        reply_markup=kb([
            [("📣 پیام همگانی", "admin:broadcast")],
            [("📋 لیست کاربران", "admin:userlist:0")],
            [("🔎 جستجو کاربر", "admin:usersearch")],
            [("برگشت","admin:general")]
        ])
    )
    await cq.answer()

@router.callback_query(F.data == "admin:broadcast")
async def admin_broadcast_start(cq: CallbackQuery, state: FSMContext):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    await state.set_state(AdminBroadcast.text)
    await cq.message.edit_text(f"{glass_header('پیام همگانی')}\n{GLASS_DOT} متن پیام را ارسال کن:", reply_markup=kb([[("برگشت","admin:users")]]))
    await cq.answer()

@router.message(AdminBroadcast.text)
async def admin_broadcast_send(msg: Message, db: DB, state: FSMContext):
    if not is_admin(msg.from_user.id):
        return
    text = (msg.text or "").strip()
    if not text:
        return await msg.answer("متن خالی است.")
    users = await db.list_all_users(limit=5000, offset=0)
    sent = 0
    for u in users:
        if u["is_blocked"]:
            continue
        try:
            await msg.bot.send_message(u["user_id"], text)
            sent += 1
        except Exception:
            pass
    await state.clear()
    await msg.answer(f"✅ ارسال شد به {sent} کاربر.", reply_markup=kb([[("🛠 پنل مدیریت","admin:home")],[("🏠 منوی اصلی","home")]]))

@router.callback_query(F.data == "admin:active")
async def admin_active(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    orders = await db.list_active_orders()
    if not orders:
        await cq.message.edit_text("فعلاً سفارشی فعال نیست.", reply_markup=kb([[("برگشت","admin:home")]]))
        return await cq.answer()
    rows = []
    for o in orders[:50]:
        label = o["ip4"] or f"Order#{o['id']}"
        rows.append([(f"🧊 {label} | {o['status']}", f"admin:ord:{o['id']}"), ("🗑 حذف", f"admin:orddel:{o['id']}")])
    rows.append([("برگشت","admin:home")])
    await cq.message.edit_text(f"{glass_header('سرورهای فعال')}\n{GLASS_DOT} روی آی‌پی بزن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("admin:ord:"))
async def admin_order_view(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    traffic_txt = "نامحدود" if o["traffic_limit_gb"] <= 0 else f"{o['traffic_used_gb']:.1f}/{o['traffic_limit_gb']} GB"
    text = (
        f"{glass_header('مدیریت سرویس')}\n"
        f"{GLASS_DOT} IP: {o['ip4']}\n"
        f"{GLASS_DOT} خریدار: {o['user_id']}\n"
        f"{GLASS_DOT} نام: {o['name']}\n"
        f"{GLASS_DOT} پلن: {o['server_type']}\n"
        f"{GLASS_DOT} لوکیشن: {o['location_name']}\n"
        f"{GLASS_DOT} وضعیت: {o['status']}\n"
        f"{GLASS_DOT} انقضا: {fmt_dt(o['expires_at'])}\n"
        f"{GLASS_DOT} تعداد روز باقی مانده : {days_left_text(o)}\n"
        f"{GLASS_DOT} ترافیک: {traffic_txt}\n"
    )
    await cq.message.edit_text(
        text,
        reply_markup=kb([
            [("⏻ خاموش", f"admin:act:off:{oid}"), ("⏽ روشن", f"admin:act:on:{oid}")],
            [("🔁 ریبلد", f"admin:act:rebuild:{oid}")],
            [("🔐 بازیابی پسورد", f"admin:act:resetpw:{oid}")],
            [("📊 ترافیک باقی‌مانده", f"admin:act:traffic:{oid}")],
            [("🗑 حذف سرور", f"admin:orddel:{oid}")],
            [("برگشت","admin:active")]
        ])
    )
    await cq.answer()


@router.callback_query(F.data.startswith("admin:orddel:confirm:"))
async def admin_order_delete_confirm(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)

    sid = o.get("hcloud_server_id")
    billing = (o.get("billing_mode") or "").lower()

    # settle hourly (including minutes) before delete (same as user flow)
    extra_cost = 0
    if billing == "hourly" and int(o.get("price_hourly_irt") or 0) > 0:
        rate = int(o.get("price_hourly_irt") or 0)
        now = int(time.time())
        last_charge_at = int(o.get("last_hourly_charge_at") or 0)
        if last_charge_at <= 0:
            last_charge_at = int(o.get("purchased_at") or now)

        elapsed = max(0, now - last_charge_at)
        full_hours = int(elapsed // 3600)
        rem_secs = int(elapsed % 3600)
        minutes = int((rem_secs + 59) // 60)  # ceil to minute
        cost_full = int(full_hours * rate)
        cost_minutes = int(math.ceil((minutes / 60.0) * rate)) if minutes > 0 else 0
        extra_cost = int(cost_full + cost_minutes)

        u = await db.get_user(int(o["user_id"]))
        bal = int(u["balance_irt"]) if u else 0
        if extra_cost > 0 and bal < extra_cost:
            return await cq.answer("موجودی کاربر برای تسویه دقایق استفاده کافی نیست.", show_alert=True)

        if extra_cost > 0:
            await db.add_balance(int(o["user_id"]), -extra_cost)
            await db.create_invoice(int(o["user_id"]), -extra_cost, "wallet", f"Hourly settle on admin delete order#{oid}", "paid")
        try:
            await db.update_order_hourly_tick(oid, now, int(o.get("last_warn_at") or 0))
            await db.set_last_billed_hour(oid, int(now // 3600))
        except Exception:
            pass

    if sid:
        try:
            hcloud_delete_server(int(sid))
        except Exception as e:
            # Still mark as deleted in DB (server may already be removed at provider).
            try:
                for aid in ADMIN_IDS:
                    await cq.bot.send_message(aid, f"⚠️ خطا در حذف سرور در Hetzner برای سفارش #{oid}: {e}\nبا این حال در دیتابیس حذف شد.")
            except Exception:
                pass

    await db.set_order_status(oid, "deleted")

    # report to admins
    try:
        uname = (cq.from_user.username or "")
        actor_name = f"{cq.from_user.full_name}" + (f" (@{uname})" if uname else "")
        await send_admin_delete_report(
            cq.bot,
            db,
            user_id=int(o["user_id"]),
            order=o,
            reason="admin_delete",
            actor_id=int(cq.from_user.id),
            actor_name=actor_name,
            extra_cost_irt=int(extra_cost or 0),
        )
    except Exception:
        pass

    msg = "✅ سرور حذف شد."
    if billing == "hourly" and extra_cost > 0:
        msg += f"\nمبلغ کسر شده بابت دقایق استفاده: {fmt_money(extra_cost)}"
    await cq.message.edit_text(msg, reply_markup=kb([[("برگشت", "admin:active")]]))
    await cq.answer("✅ حذف شد.")


@router.callback_query(F.data.startswith("admin:orddel:"))
async def admin_order_delete_prompt(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    if cq.data.startswith("admin:orddel:confirm:"):
        return
    # admin:orddel:<oid>
    try:
        oid = int(cq.data.split(":")[-1])
    except Exception:
        return await cq.answer()

    o = await db.get_order(oid)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)

    billing = (o.get("billing_mode") or "").lower()
    if billing == "monthly":
        warn = "مطمنی میخواید سرور رو حذف کنید؟\nتمامی اطلاعات سرور میپره و قابل بازیابی نخواهد بود."
    else:
        warn = "مطمینی میخواهید سرور رو حذف کنید؟\nتمامی اطلاعات سرور پاک خواهد شد و مبلغ استفاده دقایق سرور از حساب کاربر کسر خواهد شد."

    await cq.message.edit_text(
        f"{glass_header('حذف سرور')}\n{warn}",
        reply_markup=kb([
            [("✅ بله، حذف شود", f"admin:orddel:confirm:{oid}")],
            [("❌ انصراف", f"admin:ord:{oid}")],
        ])
    )
    await cq.answer()

@router.callback_query(F.data.startswith("admin:act:"))
async def admin_order_action(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    _,_,act,oid = cq.data.split(":")
    oid = int(oid)
    o = await db.get_order(oid)
    if not o or not o["hcloud_server_id"]:
        return await cq.answer("یافت نشد.", show_alert=True)
    sid = int(o["hcloud_server_id"])
    try:
        if act == "off":
            hcloud_power_action(sid, "poweroff")
            await cq.answer("خاموش شد.")
        elif act == "on":
            hcloud_power_action(sid, "poweron")
            await cq.answer("روشن شد.")
        elif act == "rebuild":
            hcloud_power_action(sid, "rebuild")
            await cq.answer("ریبلد شروع شد.")
        elif act == "resetpw":
            pw = hcloud_reset_password(sid)
            await cq.bot.send_message(cq.from_user.id, f"🔐 پسورد جدید:\n`{pw}`", parse_mode="Markdown")
            await cq.answer("ارسال شد.")
        elif act == "traffic":
            if o["traffic_limit_gb"] <= 0:
                await cq.answer("نامحدود است.", show_alert=True)
            else:
                remain = max(0.0, float(o["traffic_limit_gb"]) - float(o["traffic_used_gb"]))
                await cq.answer(f"باقی‌مانده: {remain:.1f} GB")
        else:
            await cq.answer("نامعتبر.", show_alert=True)
    except Exception as e:
        await cq.answer(f"خطا: {e}", show_alert=True)

@router.callback_query(F.data.startswith("admin:pay:approve:"))
async def admin_pay_approve(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    inv_id = int(cq.data.split(":")[-1])
    cp = await db.get_card_purchase(inv_id)
    if not cp:
        return await cq.answer("فاکتور/خرید یافت نشد.", show_alert=True)
    if cp["status"] == "approved":
        return await cq.answer("قبلاً تایید شده.", show_alert=True)
    if cp["status"] == "rejected":
        return await cq.answer("قبلاً رد شده.", show_alert=True)

    payload = {}
    try:
        payload = json.loads(cp["payload_json"])
    except Exception:
        payload = {}

    user_id = int(cp["user_id"])

    # ---- Topup approve ----
    if payload.get("type") == "topup":
        amount = int(payload.get("amount") or 0)
        if amount <= 0:
            await db.set_card_purchase_status(inv_id, "rejected")
            await db.set_invoice_status(inv_id, "rejected")
            return await cq.answer("مبلغ نامعتبر.", show_alert=True)

        await db.add_balance(user_id, amount)
        await db.set_card_purchase_status(inv_id, "approved")
        await db.set_invoice_status(inv_id, "paid")
        # notify user + admins
        user_msg = (
            f"✅ رسید شما تایید شد و کیف پول شارژ شد.\n"
            f"شماره فاکتور: #{inv_id}\n"
            f"مبلغ: {money(amount)}"
        )
        sent_ok = True
        try:
            await cq.bot.send_message(user_id, user_msg)
        except Exception:
            sent_ok = False

        # report to admins (including the approver)
        try:
            uname = (cq.from_user.username or "")
            approver = f"{cq.from_user.full_name}" + (f" (@{uname})" if uname else "")
            rep = (
                "🧾 گزارش شارژ کیف پول\n"
                f"کاربر: {user_id}\n"
                f"مبلغ: {money(amount)}\n"
                f"فاکتور: #{inv_id}\n"
                f"تایید توسط: {approver}"
            )
            for aid in ADMIN_IDS:
                try:
                    await cq.bot.send_message(aid, rep)
                except Exception:
                    pass
        except Exception:
            pass

        if not sent_ok:
            try:
                await cq.bot.send_message(cq.message.chat.id, "⚠️ شارژ انجام شد ولی نتونستم به کاربر پیام بدم (ممکنه ربات رو بلاک کرده باشه).")
            except Exception:
                pass


        try:
            await cq.bot.send_message(user_id, f"✅ شارژ انجام شد.\nمبلغ: {money(amount)}")
        except Exception:
            pass
        try:
            await cq.message.edit_text("✅ تایید شد و موجودی شارژ شد.")
        except Exception:
            # message might be photo-only (no editable text)
            try:
                await cq.bot.send_message(cq.message.chat.id, "✅ تایید شد و موجودی شارژ شد.")
            except Exception:
                pass
        return await cq.answer("شارژ شد.", show_alert=True)

    # ---- Extra traffic approve ----
    if payload.get("type") == "traffic":
        oid = int(payload.get("order_id") or 0)
        pid = int(payload.get("package_id") or 0)
        pkg = await db.get_traffic_package(pid)
        if oid <= 0 or not pkg or not pkg.get('is_active'):
            await db.set_card_purchase_status(inv_id, "rejected")
            await db.set_invoice_status(inv_id, "rejected")
            return await cq.answer("پکیج/سرویس نامعتبر.", show_alert=True)

        amount = int(await get_invoice_amount(db, inv_id) or int(pkg.get('price_irt') or 0))

        await db.set_card_purchase_status(inv_id, "approved")
        await db.set_invoice_status(inv_id, "paid")

        await db.add_order_traffic_limit(oid, int(pkg['volume_gb']))
        await db.create_traffic_purchase(user_id=user_id, order_id=oid, package_id=pid, volume_gb=int(pkg['volume_gb']), price_irt=amount, invoice_id=inv_id, status='paid')

        try:
            await cq.bot.send_message(user_id, f"✅ رسید شما تایید شد و {pkg['volume_gb']}GB به سقف ترافیک سرویس #{oid} اضافه شد.")
        except Exception:
            pass

        try:
            await cq.message.edit_text("✅ تایید شد (حجم اضافه اعمال شد).")
        except Exception:
            pass
        return await cq.answer("تایید شد.", show_alert=True)

    # ---- Manual VPS approve (no provider API) ----
    if payload.get("type") == "manual":
        plan = await db.get_plan(int(payload.get("plan_id") or 0))
        if not plan or not plan.get("is_active"):
            await db.set_card_purchase_status(inv_id, "rejected")
            await db.set_invoice_status(inv_id, "rejected")
            try:
                await cq.bot.send_message(user_id, "❌ پلن نامعتبر شد. لطفاً دوباره خرید را انجام بده.")
            except Exception:
                pass
            return await cq.answer("پلن نامعتبر.", show_alert=True)

        now = int(time.time())
        exp = now + 30 * 24 * 3600
        oid = await db.create_order(
            user_id=user_id,
            provider=plan.get('provider') or 'manual',
            country_code=plan.get('country_code'),
            ip4=None,
            name=payload.get('server_name') or 'manual',
            server_type=plan.get('server_type'),
            image_name=payload.get('os') or '-',
            location_name=payload.get('location') or (plan.get('location_name') or 'manual'),
            billing_mode='monthly',
            price_monthly_irt=int(plan.get('price_monthly_irt') or 0),
            price_hourly_irt=0,
            traffic_limit_gb=int(plan.get('traffic_limit_gb') or 0),
            status='pending_manual',
            expires_at=exp,
        )
        await db.attach_invoice_to_order(inv_id, oid)
        await db.set_card_purchase_status(inv_id, "approved")
        await db.set_invoice_status(inv_id, "paid")

        try:
            await cq.bot.send_message(user_id, f"✅ پرداخت تایید شد. سفارش شما ثبت شد و در انتظار ساخت است. زمان تحویل 1 دقیقه الی 1 ساعت🕐\nشماره سرویس: #{oid}")
        except Exception:
            pass

        for aid in ADMIN_IDS:
            try:
                await cq.bot.send_message(
                    aid,
                    f"🧾 سفارش دستی جدید\nکاربر: {user_id}\nسرویس: #{oid}\nپلن: {plan.get('server_type')}\nلوکیشن: {payload.get('location')}\nOS: {payload.get('os')}\nفاکتور: #{inv_id}",
                    reply_markup=kb([[('✅ تحویل و ارسال', f'admin:manual:deliver:{oid}')],[('⬅️ پنل مدیریت','admin:home')]]),
                )
            except Exception:
                pass

        try:
            await cq.message.edit_text("✅ تایید شد (سفارش دستی ثبت شد).")
        except Exception:
            pass
        return await cq.answer("تایید شد.", show_alert=True)

    # ---- VPS approve (build server) ----
    plan = await db.get_plan(int(payload["plan_id"]))
    if not plan or not plan["is_active"]:
        await db.set_card_purchase_status(inv_id, "rejected")
        await db.set_invoice_status(inv_id, "rejected")
        try:
            await cq.bot.send_message(user_id, "❌ پلن نامعتبر شد. لطفاً دوباره خرید را انجام بده.")
        except Exception:
            pass
        return await cq.answer("پلن نامعتبر.", show_alert=True)

    # Inform user and show a progress percentage while the server is being built
    progress_msg = None
    try:
        progress_msg = await cq.bot.send_message(user_id, f"{glass_header('در حال ساخت سرور')}\n{GLASS_DOT} پیشرفت: <b>0%</b>\n{GLASS_DOT} شروع…", parse_mode="HTML")
    except Exception:
        progress_msg = None

    try:
        if progress_msg:
            await _edit_progress(progress_msg, 10, 'در حال آماده‌سازی سفارش…')
            await _edit_progress(progress_msg, 30, 'انتخاب ایمیج سیستم‌عامل…')
            await _edit_progress(progress_msg, 70, 'در حال ساخت سرور روی Hetzner…')

        client = hclient()
        img = find_matching_image(client, payload["os"])
        if not img:
            raise RuntimeError("Image not found")
        server_id, ip4, root_pw = hcloud_create_server(
            name=payload.get("server_name", "vps"),
            server_type=plan["server_type"],
            image_id=img.id,
            location_name=payload.get("location", ""),
        )

        # Wait until running + IPv4 is assigned (with visible progress when possible)
        if progress_msg:
            ip_ready, _st = await hcloud_wait_running_with_progress(progress_msg, int(server_id), timeout_sec=240, start_percent=75, end_percent=99)
            if ip_ready:
                ip4 = ip_ready
            await _edit_progress(progress_msg, 100, 'سرور آماده شد ✅')
    except Exception as e:
        await db.set_card_purchase_status(inv_id, "rejected")
        await db.set_invoice_status(inv_id, "rejected")
        try:
            await cq.bot.send_message(user_id, f"❌ خطا در ساخت سرور: {e}")
        except Exception:
            pass
        return await cq.answer("خطا در ساخت.", show_alert=True)

    now = int(time.time())
    expires_at = int((datetime.fromtimestamp(now, TZ) + timedelta(days=30)).timestamp())
    traffic_gb = int(plan.get("traffic_limit_gb") or 0)
    billing = payload.get("billing", "monthly")

    eff = await plan_effective_prices(db, plan)
    oid = await db.create_order(
        user_id=user_id,
        plan_id=int(plan["id"]),
        provider=payload.get("provider",""),
        country=payload.get("country",""),
        location=payload.get("location",""),
        os_name=payload.get("os",""),
        server_type=plan["server_type"],
        hcloud_server_id=str(server_id),
        ip4=str(ip4),
        root_password=str(root_pw),
        billing_mode=billing,
        traffic_limit_gb=traffic_gb,
        expires_at=expires_at,
        status="active",
        price_monthly_irt=int(eff['monthly_irt'] or 0),
        price_hourly_irt=int(eff['hourly_irt'] or 0),
    )
    await db.attach_invoice_to_order(inv_id, oid)

    # Initialize hourly counters to avoid retroactive charges
    if (billing or "").lower() == "hourly" and int(plan.get("price_hourly_irt") or 0) > 0:
        try:
            current_hour = int(now // 3600)
            await db.set_last_billed_hour(oid, current_hour)
            await db.update_order_hourly_tick(oid, now, 0)
        except Exception:
            pass

    await db.set_card_purchase_status(inv_id, "approved")
    await db.set_invoice_status(inv_id, "paid")

    # Report to admins after successful build
    amount_irt = await get_invoice_amount_irt(db, inv_id)
    await send_admin_purchase_report(
        cq.bot,
        db,
        user_id=user_id,
        order_id=oid,
        ip4=str(ip4),
        pay_method="card",
        amount_irt=amount_irt,
        plan_name=str(plan["server_type"]),
        billing=str(billing),
    )

    try:
        await cq.bot.send_message(
            user_id,
            f"{glass_header('تحویل سرویس')}\n"
            f"{GLASS_DOT} IP: <code>{ip4}</code>\n"
            f"{GLASS_DOT} USER: <code>root</code>\n"
            f"{GLASS_DOT} PASS: <code>{root_pw}</code>\n"
            f"{GLASS_DOT} انقضا: {fmt_dt(expires_at)}\n",
            parse_mode="HTML",
            reply_markup=kb([[("📦 سفارش‌های من","me:orders")],[("🏠 منوی اصلی","home")]])
        )
    except Exception:
        pass

    await cq.message.edit_text("✅ تایید شد و سرور ساخته شد.")
    await cq.answer("ساخته شد.", show_alert=True)

@router.callback_query(F.data.startswith("admin:pay:reject:"))
async def admin_pay_reject(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    inv_id = int(cq.data.split(":")[-1])
    cp = await db.get_card_purchase(inv_id)
    if not cp:
        return await cq.answer("یافت نشد.", show_alert=True)
    if cp["status"] == "approved":
        return await cq.answer("قبلاً تایید شده.", show_alert=True)

    await db.set_card_purchase_status(inv_id, "rejected")
    await db.set_invoice_status(inv_id, "rejected")

    try:
        await cq.bot.send_message(int(cp["user_id"]), f"❌ رسید فاکتور #{inv_id} رد شد. لطفاً دوباره رسید صحیح ارسال کن یا با پشتیبانی هماهنگ کن.")
    except Exception:
        pass

    await cq.answer("رد شد.")
    try:
        await cq.message.edit_caption((cq.message.caption or "") + "\n\n❌ رد شد.")
    except Exception:
        pass

@router.callback_query(F.data == "admin:payments")
async def admin_payments(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    items = await db.list_pending_card_purchases(limit=30)
    if not items:
        await cq.message.edit_text(f"{glass_header('پرداخت‌ها')}\n{GLASS_DOT} موردی نیست.", reply_markup=kb([[("برگشت","admin:home")]]))
        return await cq.answer()
    rows = []
    for it in items:
        st = "🟡 منتظر رسید" if it["status"] == "waiting_receipt" else "🟠 منتظر تایید"
        rows.append([(f"{st} #{it['invoice_id']} | {it['user_id']}", f"admin:payment:{it['invoice_id']}")])
    rows.append([("برگشت","admin:home")])
    await cq.message.edit_text(f"{glass_header('پرداخت‌های کارت‌به‌کارت')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("admin:payment:"))
async def admin_payment_view(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    inv_id = int(cq.data.split(":")[-1])
    cp = await db.get_card_purchase(inv_id)
    if not cp:
        return await cq.answer("یافت نشد.", show_alert=True)
    payload = json.loads(cp["payload_json"])
    text = (
        f"{glass_header('جزئیات پرداخت')}\n"
        f"{GLASS_DOT} فاکتور: #{inv_id}\n"
        f"{GLASS_DOT} کاربر: {cp['user_id']}\n"
        f"{GLASS_DOT} وضعیت: {cp['status']}\n"
        f"{GLASS_DOT} پلنID: {payload.get('plan_id')}\n"
        f"{GLASS_DOT} سرورنام: {payload.get('server_name')}\n"
        f"{GLASS_DOT} لوکیشن: {payload.get('location')}\n"
        f"{GLASS_DOT} OS: {payload.get('os')}\n"
    )
    rows = []
    if cp.get("receipt_file_id"):
        rows.append([("📎 نمایش رسید", f"admin:receipt:{inv_id}")])
    rows += [
        [("✅ تایید و ساخت", f"admin:pay:approve:{inv_id}")],
        [("❌ رد", f"admin:pay:reject:{inv_id}")],
        [("برگشت","admin:payments")]
    ]
    await cq.message.edit_text(text, reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("admin:receipt:"))
async def admin_receipt_send(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    inv_id = int(cq.data.split(":")[-1])
    cp = await db.get_card_purchase(inv_id)
    if not cp or not cp.get("receipt_file_id"):
        return await cq.answer("رسیدی ثبت نشده.", show_alert=True)
    try:
        await cq.bot.send_document(cq.from_user.id, cp["receipt_file_id"], caption=f"رسید فاکتور #{inv_id}")
    except Exception:
        try:
            await cq.bot.send_photo(cq.from_user.id, cp["receipt_file_id"], caption=f"رسید فاکتور #{inv_id}")
        except Exception:
            pass
    await cq.answer("ارسال شد.")

@router.callback_query(F.data.startswith("admin:act:extend:"))
async def admin_act_extend(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return await cq.answer("دسترسی ندارید.", show_alert=True)
    oid = int(cq.data.split(":")[-1])
    o = await db.get_order(oid)
    if not o:
        return await cq.answer("یافت نشد.", show_alert=True)
    new_exp = int((datetime.fromtimestamp(o["expires_at"], TZ) + timedelta(days=30)).timestamp())
    await db.update_order_status_and_expiry(oid, "active", new_exp)
    try:
        await cq.bot.send_message(o["user_id"], f"♻️ سرویس شما توسط مدیر تمدید شد تا {fmt_dt(new_exp)}")
    except Exception:
        pass
    await cq.answer("تمدید شد.")
    # refresh view
    await admin_order_view(cq, db)

@router.callback_query(F.data == "admin:tickets")
async def admin_tickets_removed(cq: CallbackQuery):
    await cq.answer("بخش تیکت/پشتیبانی حذف شده است.", show_alert=True)
    rows=[]
    for t in items:
        rows.append([(f"🎫 #{t['id']} | {t['user_id']} | {t['subject']}", f"admin:ticket:view:{t['id']}")])
    rows.append([("برگشت","admin:home")])
    await cq.message.edit_text(f"{glass_header('تیکت‌های باز')}\n{GLASS_DOT} انتخاب کن:", reply_markup=kb(rows))
    await cq.answer()

@router.callback_query(F.data.startswith("admin:ticket:view:"))
async def admin_ticket_view(cq: CallbackQuery, db: DB):
    if not is_admin(cq.from_user.id):
        return
    tid = int(cq.data.split(":")[-1])
    t = await db.get_ticket(tid)
    if not t:
        return await cq.answer("یافت نشد.", show_alert=True)
    msgs = await db.list_ticket_messages(tid, limit=30)
    body=[]
    for m in msgs:
        who = "کاربر" if m["sender"]=="user" else "مدیر"
        body.append(f"{who}: {m['text']}")
    text = f"{glass_header(f'تیکت #{tid}')}\\n{GLASS_DOT} کاربر: {t['user_id']}\\n{GLASS_DOT} موضوع: {t['subject']}\\n{GLASS_LINE}\\n" + "\\n".join(body[-20:])
    await cq.message.edit_text(text, reply_markup=kb([
        [("✉️ پاسخ", f"admin:ticket:reply:{tid}")],
        [("✅ بستن", f"admin:ticket:close:{tid}")],
        [("برگشت","admin:tickets")]
    ]))
    await cq.answer()

# -------------------------
# Background jobs
async def try_resume_suspended_hourly(bot_: Bot, db: DB, user_id: int):
    user = await db.get_user(user_id)
    bal = int(user["balance_irt"]) if user else 0
    orders = await db.list_hourly_orders(limit=500)
    for o in orders:
        if int(o["user_id"]) != int(user_id):
            continue
        if o["status"] != "suspended_balance":
            continue
        rate = int(o.get("price_hourly_irt") or 0)
        # Resume only after user has a safe buffer.
        if rate > 0 and bal >= HOURLY_WARN_BALANCE:
            await db.clear_order_suspension(int(o["id"]))
            try:
                if o.get("hcloud_server_id"):
                    hcloud_power_action(int(o["hcloud_server_id"]), "poweron")
            except Exception:
                pass
            try:
                await bot_.send_message(user_id, f"✅ موجودی شارژ شد و سرویس ساعتی شما فعال شد.\nIP: {o.get('ip4','-')}")
            except Exception:
                pass
            for aid in ADMIN_IDS:
                try:
                    await bot_.send_message(aid, f"✅ فعال‌سازی مجدد سرویس ساعتی بعد از شارژ\nکاربر: {user_id}\nIP: {o.get('ip4','-')}\nOrder: #{o['id']}")
                except Exception:
                    pass

async def hourly_billing_job(bot_: Bot, db: DB):
    now = int(time.time())
    orders = await db.list_hourly_orders(limit=500)
    for o in orders:
        uid = int(o["user_id"])
        rate = int(o.get("price_hourly_irt") or 0)
        if rate <= 0:
            continue
        user = await db.get_user(uid)
        bal = int(user["balance_irt"]) if user else 0

        delete_at = int(o.get("delete_at") or 0)
        if o["status"] == "suspended_balance" and delete_at and now >= delete_at:
            try:
                if o.get("hcloud_server_id"):
                    hcloud_delete_server(int(o["hcloud_server_id"]))
            except Exception:
                pass
            await db.update_order_status_and_expiry(int(o["id"]), "deleted", now)
            try:
                await bot_.send_message(uid, f"🗑 سرویس شما به دلیل عدم شارژ در 24 ساعت گذشته حذف شد.\nIP: {o.get('ip4','-')}")
            except Exception:
                pass
            # report to admins (full details)
            try:
                full_o = await db.get_order(int(o["id"]))
                await send_admin_delete_report(
                    bot_,
                    db,
                    user_id=uid,
                    order=full_o or o,
                    reason="auto_hourly_no_balance_24h",
                    actor_id=None,
                    actor_name="سیستم (auto)",
                    extra_cost_irt=0,
                )
            except Exception:
                pass
            continue

        warn_threshold = rate * 3
        last_warn = int(o.get("last_warn_at") or 0)
        if o["status"] == "active" and bal <= warn_threshold and now - last_warn >= 3600:
            await db.update_order_hourly_tick(int(o["id"]), int(o.get("last_hourly_charge_at") or 0), now)
            hours_left = max(0, bal // rate)
            try:
                await bot_.send_message(uid,
                    f"⚠️ هشدار کمبود موجودی ساعتی\nIP: {o.get('ip4','-')}\n"
                    f"نرخ ساعتی: {money(rate)}\nموجودی: {money(bal)}\n"
                    f"تقریباً {hours_left} ساعت باقی مانده.\n"
                    f"برای جلوگیری از قطع، موجودی را شارژ کن.",
                    reply_markup=kb([[('➕ افزایش موجودی','me:topup')]])
                )
            except Exception:
                pass
            for aid in ADMIN_IDS:
                try:
                    await bot_.send_message(aid, f"⚠️ هشدار کمبود موجودی\nکاربر: {uid}\nIP: {o.get('ip4','-')}\nموجودی: {money(bal)}\nنرخ: {money(rate)}")
                except Exception:
                    pass

        if o["status"] == "active":
            last_charge = int(o.get("last_hourly_charge_at") or 0)
            if last_charge == 0:
                last_charge = now
                await db.update_order_hourly_tick(int(o["id"]), last_charge, int(o.get("last_warn_at") or 0))
            if now - last_charge >= 3600:
                if bal >= rate:
                    await db.add_balance(uid, -rate)
                    await db.update_order_hourly_tick(int(o["id"]), now, int(o.get("last_warn_at") or 0))
                    for aid in ADMIN_IDS:
                        try:
                            await bot_.send_message(aid, f"💸 کسر ساعتی\nکاربر: {uid}\nIP: {o.get('ip4','-')}\nمبلغ: {money(rate)}")
                        except Exception:
                            pass
                else:
                    try:
                        if o.get("hcloud_server_id"):
                            hcloud_power_action(int(o["hcloud_server_id"]), "poweroff")
                    except Exception:
                        pass
                    del_at = now + 24*3600
                    await db.set_order_suspended_balance(int(o["id"]), now, del_at)
                    try:
                        await bot_.send_message(uid,
                            f"⛔️ سرویس ساعتی شما به علت اتمام موجودی خاموش شد.\n"
                            f"IP: {o.get('ip4','-')}\n"
                            f"تا 24 ساعت آینده شارژ نکنی حذف می‌شود.\n"
                            f"زمان حذف: {fmt_dt(del_at)}",
                            reply_markup=kb([[('➕ افزایش موجودی','me:topup')],[('📦 سفارش‌های من','me:orders')]])
                        )
                    except Exception:
                        pass
                    for aid in ADMIN_IDS:
                        try:
                            await bot_.send_message(aid,
                                f"⛔️ قطع به علت اتمام موجودی\n"
                                f"کاربر: {uid}\nOrder: #{o['id']}\nIP: {o.get('ip4','-')}\n"
                                f"نرخ ساعتی: {money(rate)}\nموجودی: {money(bal)}\n"
                                f"حذف در: {fmt_dt(del_at)}"
                            )
                        except Exception:
                            pass


# -------------------------
async def daily_db_backup_loop(db: DB, bot: Bot):
    """Create a DB backup once per day at configured local time.

    If 'backup_auto_send' setting is enabled, also sends the newest backup to admins.
    """
    while True:
        try:
            # compute next run time in configured TZ
            now_local = datetime.now(TZ)
            next_run = now_local.replace(hour=DB_BACKUP_HOUR, minute=DB_BACKUP_MIN, second=0, microsecond=0)
            if next_run <= now_local:
                next_run = next_run + timedelta(days=1)
            sleep_s = max(1.0, (next_run - now_local).total_seconds())
            await asyncio.sleep(sleep_s)

            path = None
            try:
                path = await db.create_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX, keep_last=DB_BACKUP_KEEP_LAST)
            except Exception:
                path = None

            try:
                auto_send = (await db.get_setting('backup_auto_send', '0')) == '1'
                if auto_send:
                    if not path:
                        path = db.get_latest_backup(DB_BACKUP_DIR, prefix=DB_BACKUP_PREFIX)
                    if path and os.path.exists(path):
                        cap = f"🗄 بکاپ دیتابیس (خودکار)\n{GLASS_DOT} فایل: <code>{htmlesc(os.path.basename(path))}</code>"
                        for aid in ADMIN_IDS:
                            try:
                                await bot.send_document(aid, FSInputFile(path), caption=cap, parse_mode='HTML')
                            except Exception:
                                pass
            except Exception:
                pass

        except Exception:
            # if loop fails, wait a bit and retry
            await asyncio.sleep(60)

async def job_loop(db: DB, bot: Bot):
    while True:
        try:
            bot_enabled = (await db.get_setting("bot_enabled", "1")) == "1"
            if not bot_enabled:
                await asyncio.sleep(5)
                continue

            orders = await db.list_active_orders()
            for o in orders:
                order = await db.get_order(o["id"])
                if not order or not order["hcloud_server_id"]:
                    continue
                sid = int(order["hcloud_server_id"])

                # monthly expiry
                if order["billing_mode"] == "monthly" and now_ts() >= order["expires_at"]:
                    try:
                        hcloud_power_action(sid, "poweroff")
                    except Exception:
                        pass
                    await db.set_order_status(order["id"], "suspended")
                    try:
                        await bot.send_message(order["user_id"], "⛔️ سرویس شما به دلیل اتمام زمان، متوقف شد. برای تمدید با پشتیبانی تماس بگیر.")
                    except Exception:
                        pass
                    continue

                # hourly billing (wallet thresholds + accurate elapsed-time billing)
                if order["billing_mode"] == "hourly" and int(order.get("price_hourly_irt") or 0) > 0:
                    rate = int(order.get("price_hourly_irt") or 0)
                    u = await db.get_user(order["user_id"])
                    bal = int(u["balance_irt"]) if u else 0

                    # 1) low-balance warning at 20k
                    last_warn_at = int(order.get("last_warn_at") or 0)
                    if bal <= HOURLY_WARN_BALANCE and (time.time() - last_warn_at) >= 3600:
                        try:
                            await db.update_order_hourly_tick(int(order["id"]), int(order.get("last_hourly_charge_at") or 0), int(time.time()))
                        except Exception:
                            pass
                        try:
                            await bot.send_message(
                                order["user_id"],
                                f"⚠️ موجودی شما به {money(bal)} رسید. لطفاً موجودی را افزایش دهید وگرنه سرور قطع می‌شود.\nIP: {order.get('ip4','-')}",
                                reply_markup=kb([[('➕ افزایش موجودی','me:topup')]]),
                            )
                        except Exception:
                            pass

                    # 2) hard cutoff at 5k (poweroff + lock)
                    if bal <= HOURLY_CUTOFF_BALANCE:
                        try:
                            hcloud_power_action(sid, "poweroff")
                        except Exception:
                            pass
                        try:
                            await db.set_order_suspended_balance(int(order["id"]), int(time.time()), 0)
                        except Exception:
                            await db.set_order_status(int(order["id"]), "suspended_balance")
                        try:
                            await bot.send_message(
                                order["user_id"],
                                f"⛔️ به دلیل رسیدن موجودی به {money(bal)}، سرویس ساعتی شما قطع شد و تا زمان شارژ دوباره روشن نمی‌شود.\nIP: {order.get('ip4','-')}",
                                reply_markup=kb([[('➕ افزایش موجودی','me:topup')],[('📦 سفارش‌های من','me:orders')]]),
                            )
                        except Exception:
                            pass
                        continue

                    # 3) charge passed full hours since last charge timestamp
                    now = int(time.time())
                    last_charge_at = int(order.get("last_hourly_charge_at") or 0)
                    if last_charge_at <= 0:
                        # fallback for older rows
                        last_charge_at = int(order.get("purchased_at") or now)
                        try:
                            await db.update_order_hourly_tick(int(order["id"]), last_charge_at, last_warn_at)
                        except Exception:
                            pass

                    elapsed = max(0, now - last_charge_at)
                    hours = int(elapsed // 3600)
                    if hours > 0:
                        cost = int(hours * rate)
                        if bal < cost:
                            try:
                                hcloud_power_action(sid, "poweroff")
                            except Exception:
                                pass
                            await db.set_order_suspended_balance(int(order["id"]), now, 0)
                            try:
                                await bot.send_message(order["user_id"], "⛔️ سرویس ساعتی به دلیل کمبود موجودی متوقف شد.")
                            except Exception:
                                pass
                        else:
                            await db.add_balance(order["user_id"], -cost)
                            await db.create_invoice(order["user_id"], cost, "wallet", f"Hourly charge order#{order['id']} ({hours}h)", "paid")
                            new_last_charge = int(last_charge_at + hours * 3600)
                            try:
                                await db.update_order_hourly_tick(int(order["id"]), new_last_charge, last_warn_at)
                                await db.set_last_billed_hour(int(order["id"]), int(new_last_charge // 3600))
                            except Exception:
                                pass

                # traffic check
                if order["traffic_limit_gb"] and order["traffic_limit_gb"] > 0:
                    end = datetime.now(timezone.utc)
                    start = datetime.fromtimestamp(order["purchased_at"], tz=timezone.utc)
                    bytes_out = hcloud_get_network_bytes(sid, start, end)
                    if bytes_out is not None:
                        used_gb = bytes_out / (1024**3)
                        await db.update_order_traffic(order["id"], float(used_gb), now_ts())
                        if used_gb >= float(order["traffic_limit_gb"]):
                            try:
                                hcloud_power_action(sid, "poweroff")
                            except Exception:
                                pass
                            await db.set_order_status(order["id"], "suspended")
                            try:
                                await bot.send_message(order["user_id"], f"⛔️ سرویس شما به دلیل رسیدن به سقف ترافیک ({order['traffic_limit_gb']}GB) متوقف شد.")
                            except Exception:
                                pass

        except Exception:
            pass

        await asyncio.sleep(300)

# -------------------------
# App
# -------------------------


# --- helpers ---
def build_purchase_summary(plan: dict, data: dict) -> str:
    parts = []
    parts.append(f"{GLASS_DOT} دیتاسنتر: {data.get('provider','-')}")
    parts.append(f"{GLASS_DOT} کشور: {data.get('country','-')} | لوکیشن: {data.get('location','-')}")
    parts.append(f"{GLASS_DOT} سیستم‌عامل: {data.get('os','-')}")
    parts.append(f"{GLASS_DOT} نام سرور: {data.get('server_name','-')}")
    parts.append(f"{GLASS_DOT} پلن: {plan.get('server_type','-')}")
    if plan.get("cpu"):
        parts.append(f"{GLASS_DOT} CPU: {plan.get('cpu')}")
    if plan.get("ram_gb"):
        parts.append(f"{GLASS_DOT} RAM: {plan.get('ram_gb')} GB")
    if plan.get("disk_gb"):
        parts.append(f"{GLASS_DOT} Disk: {plan.get('disk_gb')} GB")
    if plan.get("traffic_gb"):
        parts.append(f"{GLASS_DOT} ترافیک ماهانه: {plan.get('traffic_gb')} GB")
    return "\n".join(parts)


# Runtime singletons (used by bridge server)
BOT_OBJ: Optional[Bot] = None
DP_OBJ: Optional[Dispatcher] = None
DB_OBJ: Optional[DB] = None

async def init_runtime(start_polling: bool = True) -> Tuple[Bot, Dispatcher, DB]:
    if not BOT_TOKEN:
        raise RuntimeError("BOT_TOKEN is missing in .env")
    os.makedirs(os.path.dirname(DB_PATH), exist_ok=True)

    db = DB(DB_PATH)
    ensure_card_purchase_api(DB)
    ensure_invoice_api(DB)
    await db.init()

    # Load in-memory button label overrides + catalog (used by kb())
    await load_button_labels(db)
    await load_glass_buttons_pref(db)
    _build_label_catalog()

    # seed card text from env if present (do not override admin-configured value)
    if DEFAULT_CARD_TEXT:
        try:
            cur_card = await db.get_setting("card_number_text", "") or ""
            if not str(cur_card).strip():
                await db.set_setting("card_number_text", DEFAULT_CARD_TEXT)
        except Exception:
            pass

    bot = Bot(token=BOT_TOKEN)
    dp = Dispatcher()
    dp.include_router(router)

    # background jobs
    asyncio.create_task(job_loop(db, bot))
    asyncio.create_task(daily_db_backup_loop(db, bot))

    global BOT_OBJ, DP_OBJ, DB_OBJ
    BOT_OBJ, DP_OBJ, DB_OBJ = bot, dp, db

    if start_polling:
        await dp.start_polling(bot, db=db)

    return bot, dp, db

async def main():
    await init_runtime(start_polling=True)


if __name__ == "__main__":
    asyncio.run(main())