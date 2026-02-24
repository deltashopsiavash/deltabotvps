import aiosqlite
import time
import os
import sqlite3
import asyncio
import glob
import shutil
from typing import Optional, Dict, Any, List, Tuple, Union

# ---------------------------------------------------------------------------
# SQLite schema
# ---------------------------------------------------------------------------
SCHEMA = """
PRAGMA journal_mode=WAL;

CREATE TABLE IF NOT EXISTS users (
  user_id INTEGER PRIMARY KEY,
  username TEXT,
  phone TEXT,
  registered_at INTEGER NOT NULL DEFAULT 0,
  balance_irt INTEGER NOT NULL DEFAULT 0,
  is_blocked INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS plans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider TEXT NOT NULL,        -- 'hetzner'
  country_code TEXT NOT NULL,    -- 'DE'
  location_name TEXT NOT NULL,   -- 'fsn1' etc
  server_type TEXT NOT NULL,     -- 'cx23'
  title TEXT NOT NULL,           -- display title
  vcpu INTEGER,
  ram_gb REAL,
  disk_gb INTEGER,
  price_monthly_eur REAL,
  price_hourly_eur REAL,
  price_monthly_irt INTEGER NOT NULL,
  hourly_enabled INTEGER NOT NULL DEFAULT 0,
  price_hourly_irt INTEGER NOT NULL DEFAULT 0,
  traffic_limit_gb INTEGER NOT NULL DEFAULT 0, -- 0 means unlimited
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  provider TEXT NOT NULL,
  country_code TEXT,
  hcloud_server_id INTEGER,      -- Hetzner Cloud server id
  ip4 TEXT,
  name TEXT,
  server_type TEXT,
  image_name TEXT,
  location_name TEXT,
  login_user TEXT,
  login_pass TEXT,
  manual_details TEXT,
  monitoring_url TEXT,
  monitoring_user TEXT,
  monitoring_pass TEXT,
  billing_mode TEXT NOT NULL,    -- 'monthly' | 'hourly'
  price_monthly_irt INTEGER NOT NULL,
  price_hourly_irt INTEGER NOT NULL,
  traffic_limit_gb INTEGER NOT NULL,
  traffic_used_gb REAL NOT NULL DEFAULT 0,
  traffic_last_ts INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL,          -- 'active'|'suspended'|'deleted'|'suspended_balance'
  purchased_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,   -- monthly expiry; for hourly used for display
  last_billed_hour INTEGER NOT NULL DEFAULT 0,

  -- hourly engine extra fields (migrated; kept here for new installs)
  last_hourly_charge_at INTEGER NOT NULL DEFAULT 0,
  last_warn_at INTEGER NOT NULL DEFAULT 0,
  suspended_at INTEGER NOT NULL DEFAULT 0,
  delete_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  amount_irt INTEGER NOT NULL,
  method TEXT NOT NULL,          -- 'wallet' | 'card'
  desc TEXT,
  status TEXT NOT NULL,          -- 'paid'|'pending'|'rejected'
  created_at INTEGER NOT NULL,
  order_id INTEGER               -- optional link to orders.id
);

CREATE TABLE IF NOT EXISTS card_purchases (
  invoice_id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL,
  payload_json TEXT NOT NULL,
  receipt_file_id TEXT,
  status TEXT NOT NULL DEFAULT 'waiting_receipt', -- waiting_receipt|sent_to_admin|approved|rejected
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  status TEXT NOT NULL,          -- 'open'|'closed'
  subject TEXT,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL,
  sender TEXT NOT NULL,         -- 'user'|'admin'
  sender_id INTEGER NOT NULL,
  text TEXT NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS traffic_packages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  country_code TEXT NOT NULL,
  title TEXT,
  volume_gb INTEGER NOT NULL,
  price_irt INTEGER NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS traffic_purchases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  order_id INTEGER NOT NULL,
  package_id INTEGER,
  volume_gb INTEGER NOT NULL,
  price_irt INTEGER NOT NULL,
  invoice_id INTEGER,
  status TEXT NOT NULL,
  created_at INTEGER NOT NULL
);

"""

def _now() -> int:
    return int(time.time())

def _as_int_or_none(x: Any) -> Optional[int]:
    if x is None:
        return None
    try:
        if isinstance(x, bool):
            return int(x)
        if isinstance(x, (int, float)):
            return int(x)
        s = str(x).strip()
        if s.isdigit() or (s.startswith("-") and s[1:].isdigit()):
            return int(s)
    except Exception:
        pass
    return None

class DB:
    def __init__(self, path: str):
        self.path = path

    async def init(self) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.executescript(SCHEMA)

            # ----------------- best-effort migrations -----------------
            # orders: hourly columns (older deployments)
            for stmt in [
                # users: phone registration
                "ALTER TABLE users ADD COLUMN phone TEXT",
                "ALTER TABLE users ADD COLUMN registered_at INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE orders ADD COLUMN last_hourly_charge_at INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE orders ADD COLUMN last_warn_at INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE orders ADD COLUMN suspended_at INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE orders ADD COLUMN delete_at INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE orders ADD COLUMN login_user TEXT",
                "ALTER TABLE orders ADD COLUMN login_pass TEXT",
                "ALTER TABLE orders ADD COLUMN manual_details TEXT",
                "ALTER TABLE orders ADD COLUMN monitoring_url TEXT",
                "ALTER TABLE orders ADD COLUMN monitoring_user TEXT",
                "ALTER TABLE orders ADD COLUMN monitoring_pass TEXT",
                "ALTER TABLE orders ADD COLUMN country_code TEXT",
                # invoices: link to order
                "ALTER TABLE invoices ADD COLUMN order_id INTEGER",
                # plans: EUR base prices
                "ALTER TABLE plans ADD COLUMN price_monthly_eur REAL",
                "ALTER TABLE plans ADD COLUMN price_hourly_eur REAL",
            ]:
                try:
                    await db.execute(stmt)
                except Exception:
                    pass

            # Ensure card_purchases table exists (some older db.py variants were missing it)
            try:
                await db.execute("SELECT 1 FROM card_purchases LIMIT 1")
            except Exception:
                await db.executescript("""
                CREATE TABLE IF NOT EXISTS card_purchases (
                  invoice_id INTEGER PRIMARY KEY,
                  user_id INTEGER NOT NULL,
                  payload_json TEXT NOT NULL,
                  receipt_file_id TEXT,
                  status TEXT NOT NULL DEFAULT 'waiting_receipt',
                  created_at INTEGER NOT NULL
                );
                """)
            await db.commit()

    # -------------------------
    # backups
    # -------------------------
    async def create_backup(
        self,
        backup_dir: str,
        *,
        prefix: str = "vpsbot_backup",
        keep_last: int = 30,
    ) -> str:
        """Create a consistent SQLite backup file and return its path.

        Uses VACUUM INTO when available (best for WAL mode). Falls back to the
        sqlite3 backup API if VACUUM INTO isn't supported.
        """

        os.makedirs(str(backup_dir), exist_ok=True)

        ts = int(time.time())
        # UTC timestamp in filename (stable for sorting)
        dt = time.strftime("%Y%m%d_%H%M%S", time.gmtime(ts))
        out_path = os.path.join(str(backup_dir), f"{prefix}_{dt}.sqlite3")

        def _do_backup() -> str:
            src = sqlite3.connect(self.path)
            try:
                # Prefer VACUUM INTO (creates a clean consistent copy)
                try:
                    src.execute("PRAGMA busy_timeout=5000")
                    safe_out = out_path.replace("'", "''")
                    src.execute(f"VACUUM INTO '{safe_out}'")
                    src.commit()
                except Exception:
                    # Fallback: sqlite backup API
                    dst = sqlite3.connect(out_path)
                    try:
                        src.backup(dst)
                        dst.commit()
                    finally:
                        dst.close()
            finally:
                src.close()
            return out_path

        # run in a thread to avoid blocking the event loop
        path = await asyncio.to_thread(_do_backup)

        # retention: keep newest N
        try:
            files = sorted(glob.glob(os.path.join(str(backup_dir), f"{prefix}_*.sqlite3")))
            if keep_last and keep_last > 0 and len(files) > keep_last:
                for f in files[: max(0, len(files) - keep_last)]:
                    try:
                        os.remove(f)
                    except Exception:
                        pass
        except Exception:
            pass

        return path

    def get_latest_backup(self, backup_dir: str, *, prefix: str = "vpsbot_backup") -> Optional[str]:
        """Return latest backup path (or None if not found)."""
        try:
            files = sorted(glob.glob(os.path.join(str(backup_dir), f"{prefix}_*.sqlite3")))
            return files[-1] if files else None
        except Exception:
            return None

    # -------------------------
    # settings
    # -------------------------
    async def get_setting(self, k: str, default: Optional[str] = None) -> Optional[str]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute("SELECT v FROM settings WHERE k=?", (k,))
            row = await cur.fetchone()
            return row[0] if row else default

    async def set_setting(self, k: str, v: str) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v",
                (k, v),
            )
            await db.commit()

    # -------------------------
    # users
    # -------------------------
    async def upsert_user(self, user_id: int, username: Optional[str]) -> None:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "INSERT INTO users(user_id, username, created_at) VALUES(?,?,?) "
                "ON CONFLICT(user_id) DO UPDATE SET username=excluded.username",
                (user_id, username, now),
            )
            await db.commit()

    async def get_user(self, user_id: int) -> Optional[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT user_id, username, phone, registered_at, balance_irt, is_blocked, created_at FROM users WHERE user_id=?",
                (user_id,),
            )
            row = await cur.fetchone()
        if not row:
            return None
        return {
            "user_id": row[0],
            "username": row[1],
            "phone": row[2],
            "registered_at": int(row[3] or 0),
            "balance_irt": int(row[4] or 0),
            "is_blocked": int(row[5] or 0),
            "created_at": int(row[6] or 0),
        }

    async def set_block(self, user_id: int, is_blocked: bool) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE users SET is_blocked=? WHERE user_id=?", (1 if is_blocked else 0, user_id))
            await db.commit()

    async def add_balance(self, user_id: int, delta_irt: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE users SET balance_irt = balance_irt + ? WHERE user_id=?", (delta_irt, user_id))
            await db.commit()

    async def list_all_users(self, limit: int = 50, offset: int = 0) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT user_id, username, phone, registered_at, balance_irt, is_blocked FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?",
                (limit, offset),
            )
            rows = await cur.fetchall()
        return [{"user_id": r[0], "username": r[1], "phone": r[2], "registered_at": int(r[3] or 0), "balance_irt": int(r[4] or 0), "is_blocked": int(r[5] or 0)} for r in rows]

    async def search_user(self, user_id: int) -> Optional[Dict[str, Any]]:
        return await self.get_user(user_id)

    
    async def set_user_phone(self, user_id: int, phone: str) -> None:
        phone = (phone or "").strip()
        if not phone:
            return
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE users SET phone=?, registered_at=? WHERE user_id=?",
                (phone, _now(), user_id),
            )
            await db.commit()

    async def get_user_phone(self, user_id: int) -> Optional[str]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute("SELECT phone FROM users WHERE user_id=?", (user_id,))
            row = await cur.fetchone()
        return row[0] if row and row[0] else None

# -------------------------
    # plans
    # -------------------------
    async def create_plan(self, p: Dict[str, Any]) -> int:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """INSERT INTO plans(provider,country_code,location_name,server_type,title,vcpu,ram_gb,disk_gb,
                   price_monthly_eur,price_hourly_eur,price_monthly_irt,hourly_enabled,price_hourly_irt,traffic_limit_gb,is_active,created_at)
                   VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    p["provider"],
                    p["country_code"],
                    p["location_name"],
                    p["server_type"],
                    p["title"],
                    p.get("vcpu"),
                    p.get("ram_gb"),
                    p.get("disk_gb"),
                    float(p.get('price_monthly_eur')) if p.get('price_monthly_eur') is not None else None,
                    float(p.get('price_hourly_eur')) if p.get('price_hourly_eur') is not None else None,
                    int(p['price_monthly_irt']),
                    1 if p.get("hourly_enabled") else 0,
                    int(p.get("price_hourly_irt", 0) or 0),
                    int(p.get("traffic_limit_gb", 0) or 0),
                    1 if p.get("is_active", True) else 0,
                    now,
                ),
            )
            await db.commit()
            return int(cur.lastrowid)

    async def list_plans(self, provider: str, country_code: str, location_name: str) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, provider, country_code, location_name, server_type, title, vcpu, ram_gb, disk_gb,
                          price_monthly_eur, price_hourly_eur,
                          price_monthly_irt, hourly_enabled, price_hourly_irt, traffic_limit_gb, is_active
                   FROM plans
                   WHERE provider=? AND country_code=? AND location_name=? AND is_active=1
                   ORDER BY price_monthly_irt ASC""",
                (provider, country_code, location_name),
            )
            rows = await cur.fetchall()
        return [
            {
                "id": r[0],
                "provider": r[1],
                "country_code": r[2],
                "location_name": r[3],
                "server_type": r[4],
                "title": r[5],
                "vcpu": r[6],
                "ram_gb": r[7],
                "disk_gb": r[8],
                "price_monthly_eur": (float(r[9]) if r[9] is not None else None),
                "price_hourly_eur": (float(r[10]) if r[10] is not None else None),
                "price_monthly_irt": int(r[11] or 0),
                "hourly_enabled": bool(r[12]),
                "price_hourly_irt": int(r[13] or 0),
                "traffic_limit_gb": int(r[14] or 0),
                "is_active": bool(r[15]),
            }
            for r in rows
        ]

    
    async def list_plans_by_provider(self, provider: str, only_active: Optional[bool] = True, limit: int = 200) -> List[Dict[str, Any]]:
        """List plans for a provider (e.g. 'manual')."""
        where = "WHERE provider=?"
        params: List[Any] = [provider]
        if only_active is True:
            where += " AND is_active=1"
        elif only_active is False:
            where += " AND is_active=0"

        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                f"""SELECT id, provider, country_code, location_name, server_type, title, vcpu, ram_gb, disk_gb,
                          price_monthly_eur, price_hourly_eur,
                          price_monthly_irt, hourly_enabled, price_hourly_irt, traffic_limit_gb, is_active, created_at
                   FROM plans
                   {where}
                   ORDER BY created_at DESC
                   LIMIT ?""",
                tuple(params + [int(limit)]),
            )
            rows = await cur.fetchall()

        return [
            {
                "id": r[0],
                "provider": r[1],
                "country_code": r[2],
                "location_name": r[3],
                "server_type": r[4],
                "title": r[5],
                "vcpu": r[6],
                "ram_gb": r[7],
                "disk_gb": r[8],
                "price_monthly_eur": r[9],
                "price_hourly_eur": r[10],
                "price_monthly_irt": int(r[11] or 0),
                "hourly_enabled": int(r[12] or 0),
                "price_hourly_irt": int(r[13] or 0),
                "traffic_limit_gb": int(r[14] or 0),
                "is_active": int(r[15] or 0),
                "created_at": int(r[16] or 0),
            }
            for r in rows
        ]

    async def get_plan(self, plan_id: int) -> Optional[Dict[str, Any]]:
            async with aiosqlite.connect(self.path) as db:
                cur = await db.execute(
                    """SELECT id, provider, country_code, location_name, server_type, title, vcpu, ram_gb, disk_gb,
                              price_monthly_eur, price_hourly_eur,
                              price_monthly_irt, hourly_enabled, price_hourly_irt, traffic_limit_gb, is_active
                       FROM plans WHERE id=?""",
                    (plan_id,),
                )
                r = await cur.fetchone()
            if not r:
                return None
            return {
                "id": r[0],
                "provider": r[1],
                "country_code": r[2],
                "location_name": r[3],
                "server_type": r[4],
                "title": r[5],
                "vcpu": r[6],
                "ram_gb": r[7],
                "disk_gb": r[8],
                    "price_monthly_eur": (float(r[9]) if r[9] is not None else None),
                    "price_hourly_eur": (float(r[10]) if r[10] is not None else None),
                    "price_monthly_irt": int(r[11] or 0),
                    "hourly_enabled": bool(r[12]),
                    "price_hourly_irt": int(r[13] or 0),
                    "traffic_limit_gb": int(r[14] or 0),
                    "is_active": bool(r[15]),
            }

    async def list_plan_countries(self, provider: str) -> List[str]:
        """Return distinct country codes that have active plans for a provider."""
        provider = str(provider or "").strip().lower()
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT DISTINCT country_code FROM plans
                   WHERE lower(provider)=? AND is_active=1
                   ORDER BY country_code ASC""",
                (provider,),
            )
            rows = await cur.fetchall()
        return [str(r[0]).upper() for r in rows if r and r[0]]

    async def update_plan_fields(self, plan_id: int, **fields: Any) -> None:
        """Update a plan with a whitelisted set of fields.

        Used for manual plan management from the admin panel.
        """
        allowed = {
            "title",
            "server_type",
            "country_code",
            "price_monthly_irt",
            "traffic_limit_gb",
            "is_active",
        }
        set_parts = []
        params: List[Any] = []
        for k, v in fields.items():
            if k not in allowed:
                continue
            if k == "country_code" and v is not None:
                v = str(v).upper()
            set_parts.append(f"{k}=?")
            params.append(v)
        if not set_parts:
            return
        params.append(int(plan_id))
        async with aiosqlite.connect(self.path) as db:
            await db.execute(f"UPDATE plans SET {', '.join(set_parts)} WHERE id=?", tuple(params))
            await db.commit()

    # -------------------------
    # orders
    # -------------------------
    async def create_order(self, o: Union[Dict[str, Any], None] = None, **kwargs: Any) -> int:
        """
        Backward/forward compatible:
        - old signature: create_order(o: dict)
        - new call sites: create_order(user_id=..., provider=..., ...)
        Unknown keys are ignored.
        """
        if o is None:
            o = {}
        if not isinstance(o, dict):
            raise TypeError("create_order expects dict or keyword arguments")
        if kwargs:
            o = {**o, **kwargs}

        now = _now()
        user_id = int(o["user_id"])

        provider = str(o.get("provider") or "")
        country_code = str(o.get("country_code") or o.get("country") or "").upper().strip() or None
        hcloud_server_id = _as_int_or_none(o.get("hcloud_server_id"))
        ip4 = o.get("ip4")
        name = o.get("name") or o.get("server_name")
        server_type = o.get("server_type")
        image_name = o.get("image_name") or o.get("os_name") or o.get("os")
        location_name = o.get("location_name") or o.get("location")
        billing_mode = str(o.get("billing_mode") or "monthly")
        price_monthly_irt = int(o.get("price_monthly_irt") or 0)
        price_hourly_irt = int(o.get("price_hourly_irt") or 0)
        traffic_limit_gb = int(o.get("traffic_limit_gb") or 0)
        status = str(o.get("status") or "active")
        expires_at = int(o.get("expires_at") or now)
        last_billed_hour = int(o.get("last_billed_hour") or 0)

        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """INSERT INTO orders(
                    user_id,provider,country_code,hcloud_server_id,ip4,name,server_type,image_name,location_name,
                    billing_mode,price_monthly_irt,price_hourly_irt,traffic_limit_gb,status,purchased_at,expires_at,last_billed_hour
                ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
                (
                    user_id,
                    provider,
                    country_code,
                    hcloud_server_id,
                    ip4,
                    name,
                    server_type,
                    image_name,
                    location_name,
                    billing_mode,
                    price_monthly_irt,
                    price_hourly_irt,
                    traffic_limit_gb,
                    status,
                    now,
                    expires_at,
                    last_billed_hour,
                ),
            )
            await db.commit()
            return int(cur.lastrowid)

    async def set_order_status(self, order_id: int, status: str) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET status=? WHERE id=?", (status, order_id))
            await db.commit()


    async def set_order_credentials(
        self,
        order_id: int,
        *,
        ip4: Optional[str] = None,
        login_user: Optional[str] = None,
        login_pass: Optional[str] = None,
        manual_details: Optional[str] = None,
        monitoring_url: Optional[str] = None,
        monitoring_user: Optional[str] = None,
        monitoring_pass: Optional[str] = None,
        status: Optional[str] = None,
    ) -> None:
        """Set manual delivery credentials (best-effort).

        This is used for manual delivery flow to store IP/login details on an order.
        Columns are added via init() migrations on older databases.
        """
        fields = []
        values = []
        if ip4 is not None:
            fields.append("ip4=?")
            values.append(ip4)
        if login_user is not None:
            fields.append("login_user=?")
            values.append(login_user)
        if login_pass is not None:
            fields.append("login_pass=?")
            values.append(login_pass)
        if manual_details is not None:
            fields.append("manual_details=?")
            values.append(manual_details)
        if monitoring_url is not None:
            fields.append("monitoring_url=?")
            values.append(monitoring_url)
        if monitoring_user is not None:
            fields.append("monitoring_user=?")
            values.append(monitoring_user)
        if monitoring_pass is not None:
            fields.append("monitoring_pass=?")
            values.append(monitoring_pass)
        if status is not None:
            fields.append("status=?")
            values.append(status)
        if not fields:
            return
        values.append(order_id)
        q = f"UPDATE orders SET {', '.join(fields)} WHERE id=?"
        async with aiosqlite.connect(self.path) as db:
            await db.execute(q, tuple(values))
            await db.commit()

    async def update_order_status_and_expiry(self, order_id: int, status: str, expires_at: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET status=?, expires_at=? WHERE id=?", (status, int(expires_at), order_id))
            await db.commit()

    async def list_user_orders(self, user_id: int) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, ip4, name, server_type, image_name, location_name, billing_mode, status, purchased_at, expires_at,
                          traffic_limit_gb, traffic_used_gb, hcloud_server_id, price_monthly_irt, price_hourly_irt,
                          last_hourly_charge_at, last_warn_at, suspended_at, delete_at
                   FROM orders WHERE user_id=? AND status!='deleted' ORDER BY id DESC""",
                (user_id,),
            )
            rows = await cur.fetchall()

        out = []
        for r in rows:
            out.append(
                {
                    "id": r[0],
                    "ip4": r[1],
                    "name": r[2],
                    "server_type": r[3],
                    "image_name": r[4],
                    "location_name": r[5],
                    "billing_mode": r[6],
                    "status": r[7],
                    "purchased_at": r[8],
                    "expires_at": r[9],
                    "traffic_limit_gb": int(r[10] or 0),
                    "traffic_used_gb": float(r[11] or 0.0),
                    "hcloud_server_id": r[12],
                    "price_monthly_irt": int(r[13] or 0),
                    "price_hourly_irt": int(r[14] or 0),
                    "last_hourly_charge_at": int(r[15] or 0),
                    "last_warn_at": int(r[16] or 0),
                    "suspended_at": int(r[17] or 0),
                    "delete_at": int(r[18] or 0),
                }
            )
        return out

    async def delete_order(self, order_id: int) -> None:
        """Soft-delete an order (admin use)."""
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET status='deleted' WHERE id=?", (int(order_id),))
            await db.commit()

    async def delete_user_orders(self, user_id: int) -> None:
        """Soft-delete all orders of a user (admin use)."""
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET status='deleted' WHERE user_id=?", (int(user_id),))
            await db.commit()

    async def get_order(self, order_id: int, user_id: Optional[int] = None) -> Optional[Dict[str, Any]]:
        q = """SELECT id, user_id, provider, country_code, hcloud_server_id, ip4, name, server_type, image_name, location_name, login_user, login_pass, manual_details,
                      monitoring_url, monitoring_user, monitoring_pass,
                      billing_mode, price_monthly_irt, price_hourly_irt, traffic_limit_gb, traffic_used_gb, traffic_last_ts,
                      status, purchased_at, expires_at, last_billed_hour,
                      last_hourly_charge_at, last_warn_at, suspended_at, delete_at
               FROM orders WHERE id=?"""
        params: Tuple[Any, ...] = (order_id,)
        if user_id is not None:
            q += " AND user_id=?"
            params = (order_id, user_id)

        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(q, params)
            r = await cur.fetchone()
        if not r:
            return None

        return {
            "id": r[0],
            "user_id": r[1],
            "provider": r[2],
            "country_code": r[3],
            "hcloud_server_id": r[4],
            "ip4": r[5],
            "name": r[6],
            "server_type": r[7],
            "image_name": r[8],
            "location_name": r[9],
            "login_user": r[10],
            "login_pass": r[11],
            "manual_details": r[12],
            "monitoring_url": r[13],
            "monitoring_user": r[14],
            "monitoring_pass": r[15],
            "billing_mode": r[16],
            "price_monthly_irt": int(r[17] or 0),
            "price_hourly_irt": int(r[18] or 0),
            "traffic_limit_gb": int(r[19] or 0),
            "traffic_used_gb": float(r[20] or 0.0),
            "traffic_last_ts": int(r[21] or 0),
            "status": r[22],
            "purchased_at": int(r[23] or 0),
            "expires_at": int(r[24] or 0),
            "last_billed_hour": int(r[25] or 0),
            "last_hourly_charge_at": int(r[26] or 0),
            "last_warn_at": int(r[27] or 0),
            "suspended_at": int(r[28] or 0),
            "delete_at": int(r[29] or 0),
        }

    # -------------------------
    # Admin plan management
    # -------------------------
    async def list_all_plan_countries(self) -> List[str]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute("SELECT DISTINCT country_code FROM plans ORDER BY country_code")
            rows = await cur.fetchall()
        return [r[0] for r in rows if r and r[0]]

    async def list_plans_admin(self, country_code: str, group_key: str = "all") -> List[Dict[str, Any]]:
        # group_key: all|cx|cpx|cax
        where = "country_code=?"
        params: List[Any] = [country_code]
        g = (group_key or "all").lower()
        if g in ("cx","cpx","cax"):
            where += " AND lower(server_type) LIKE ?"
            params.append(f"{g}%")
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                f"""SELECT id, provider, country_code, location_name, server_type, title, vcpu, ram_gb, disk_gb,
                          price_monthly_eur, price_hourly_eur,
                          price_monthly_irt, hourly_enabled, price_hourly_irt, traffic_limit_gb, is_active
                   FROM plans WHERE {where} ORDER BY server_type""",
                tuple(params),
            )
            rows = await cur.fetchall()
        out: List[Dict[str, Any]] = []
        for r in rows:
            out.append({
                "id": r[0],
                "provider": r[1],
                "country_code": r[2],
                "location_name": r[3],
                "server_type": r[4],
                "title": r[5],
                "vcpu": r[6],
                "ram_gb": r[7],
                "disk_gb": r[8],
                "price_monthly_eur": (float(r[9]) if r[9] is not None else None),
                "price_hourly_eur": (float(r[10]) if r[10] is not None else None),
                "price_monthly_irt": int(r[11] or 0),
                "hourly_enabled": bool(r[12]),
                "price_hourly_irt": int(r[13] or 0),
                "traffic_limit_gb": int(r[14] or 0),
                "is_active": bool(r[15]),
            })
        return out



    async def list_orders_by_status(self, status: str, limit: int = 50) -> List[Dict[str, Any]]:
        """List orders filtered by a single status (new helper for admin manual/queues)."""
        status = str(status)
        limit = int(limit) if limit else 50
        if limit <= 0:
            limit = 50
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, user_id, provider, country_code, hcloud_server_id, ip4, name, server_type, image_name, location_name, login_user, login_pass, manual_details,
                          billing_mode, price_monthly_irt, price_hourly_irt, traffic_limit_gb, traffic_used_gb, traffic_last_ts,
                          status, purchased_at, expires_at, last_hourly_charge_at, last_warn_at, suspended_at, delete_at
                   FROM orders WHERE status=? ORDER BY id DESC LIMIT ?""",
                (status, limit),
            )
            rows = await cur.fetchall()

        out: List[Dict[str, Any]] = []
        for r in rows:
            out.append(
                {
                    "id": r[0],
                    "user_id": r[1],
                    "provider": r[2],
                    "hcloud_server_id": r[3],
                    "ip4": r[4],
                    "name": r[5],
                    "server_type": r[6],
                    "image_name": r[7],
                    "location_name": r[8],
                    "billing_mode": r[9],
                    "price_monthly_irt": r[10],
                    "price_hourly_irt": r[11],
                    "traffic_limit_gb": r[12],
                    "traffic_used_gb": r[13],
                    "traffic_last_ts": r[14],
                    "status": r[15],
                    "purchased_at": r[16],
                    "expires_at": r[17],
                    "last_hourly_charge_at": r[18],
                    "last_warn_at": r[19],
                    "suspended_at": r[20],
                    "delete_at": r[21],
                }
            )
        return out

    async def toggle_plan_active(self, plan_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE plans SET is_active=CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=?", (plan_id,))
            await db.commit()

    async def delete_plan(self, plan_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("DELETE FROM plans WHERE id=?", (plan_id,))
            await db.commit()

    async def update_plan_prices(self, plan_id: int, *, monthly_eur: Optional[float], hourly_eur: Optional[float],
                                monthly_irt: int, hourly_irt: int, hourly_enabled: bool) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE plans SET price_monthly_eur=?, price_hourly_eur=?, price_monthly_irt=?, price_hourly_irt=?, hourly_enabled=? WHERE id=?",
                (monthly_eur, hourly_eur, int(monthly_irt), int(hourly_irt), 1 if hourly_enabled else 0, int(plan_id)),
            )
            await db.commit()


    async def update_plan_traffic_limit(self, plan_id: int, *, traffic_limit_gb: int) -> None:
        """Update traffic limit (GB). 0 means unlimited."""
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE plans SET traffic_limit_gb=? WHERE id=?",
                (int(traffic_limit_gb), int(plan_id)),
            )
            await db.commit()

    async def get_plan_sales_counts(self, plan_ids: List[int]) -> Dict[int, int]:
        if not plan_ids:
            return {}
        placeholders = ",".join("?" for _ in plan_ids)
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                f"""SELECT plan_id, COUNT(*) FROM orders
                    WHERE plan_id IN ({placeholders}) AND status != 'deleted'
                    GROUP BY plan_id""",
                tuple(int(x) for x in plan_ids),
            )
            rows = await cur.fetchall()
        return {int(r[0]): int(r[1]) for r in rows if r and r[0] is not None}


    async def list_active_orders(self) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, user_id, ip4, name, server_type, location_name, status, hcloud_server_id,
                          billing_mode, expires_at, traffic_limit_gb, traffic_used_gb, price_monthly_irt, price_hourly_irt
                   FROM orders WHERE status IN ('active','suspended','suspended_balance') ORDER BY id DESC"""
            )
            rows = await cur.fetchall()
        return [
            {
                "id": r[0],
                "user_id": r[1],
                "ip4": r[2],
                "name": r[3],
                "server_type": r[4],
                "location_name": r[5],
                "status": r[6],
                "hcloud_server_id": r[7],
                "billing_mode": r[8],
                "expires_at": r[9],
                "traffic_limit_gb": int(r[10] or 0),
                "traffic_used_gb": float(r[11] or 0.0),
                "price_monthly_irt": int(r[12] or 0),
                "price_hourly_irt": int(r[13] or 0),
            }
            for r in rows
        ]

    async def list_hourly_orders(self, limit: int = 500) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, user_id, ip4, name, server_type, image_name, location_name, billing_mode, status,
                          purchased_at, expires_at, hcloud_server_id,
                          price_monthly_irt, price_hourly_irt, traffic_limit_gb, traffic_used_gb,
                          last_hourly_charge_at, last_warn_at, suspended_at, delete_at
                   FROM orders
                   WHERE billing_mode='hourly' AND status IN ('active','suspended_balance')
                   ORDER BY id DESC
                   LIMIT ?""",
                (limit,),
            )
            rows = await cur.fetchall()

        out = []
        for r in rows:
            out.append(
                {
                    "id": r[0],
                    "user_id": r[1],
                    "ip4": r[2],
                    "name": r[3],
                    "server_type": r[4],
                    "image_name": r[5],
                    "location_name": r[6],
                    "billing_mode": r[7],
                    "status": r[8],
                    "purchased_at": int(r[9] or 0),
                    "expires_at": int(r[10] or 0),
                    "hcloud_server_id": r[11],
                    "price_monthly_irt": int(r[12] or 0),
                    "price_hourly_irt": int(r[13] or 0),
                    "traffic_limit_gb": int(r[14] or 0),
                    "traffic_used_gb": float(r[15] or 0.0),
                    "last_hourly_charge_at": int(r[16] or 0),
                    "last_warn_at": int(r[17] or 0),
                    "suspended_at": int(r[18] or 0),
                    "delete_at": int(r[19] or 0),
                }
            )
        return out

    async def update_order_traffic(self, order_id: int, used_gb: float, ts: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET traffic_used_gb=?, traffic_last_ts=? WHERE id=?", (float(used_gb), int(ts), order_id))
            await db.commit()


    async def add_order_traffic_limit(self, order_id: int, add_gb: int) -> None:
        """Increase an order's traffic_limit_gb by add_gb (GB)."""
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE orders SET traffic_limit_gb = traffic_limit_gb + ? WHERE id=?",
                (int(add_gb), int(order_id)),
            )
            await db.commit()

    async def create_traffic_package(
        self,
        *,
        country_code: str,
        title: str,
        volume_gb: int,
        price_irt: int,
        is_active: bool = True,
    ) -> int:
        cc = (country_code or "").upper().strip()
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """INSERT INTO traffic_packages (country_code, title, volume_gb, price_irt, is_active, created_at)
                   VALUES (?,?,?,?,?,?)""",
                (cc, (title or "").strip(), int(volume_gb), int(price_irt), 1 if is_active else 0, _now()),
            )
            await db.commit()
            return int(cur.lastrowid)

    async def list_traffic_packages(self, country_code: str, *, active_only: bool = True, limit: int = 200) -> List[Dict[str, Any]]:
        cc = (country_code or "").upper().strip()
        where = "WHERE country_code=?"
        params: List[Any] = [cc]
        if active_only:
            where += " AND is_active=1"
        q = f"""SELECT id, country_code, title, volume_gb, price_irt, is_active, created_at
                 FROM traffic_packages {where}
                 ORDER BY price_irt ASC, volume_gb ASC, id ASC
                 LIMIT ?"""
        params.append(int(limit))
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(q, tuple(params))
            rows = await cur.fetchall()
        out: List[Dict[str, Any]] = []
        for r in rows or []:
            out.append(
                {
                    "id": int(r[0]),
                    "country_code": r[1] or "",
                    "title": r[2],
                    "volume_gb": int(r[3] or 0),
                    "price_irt": int(r[4] or 0),
                    "is_active": int(r[5] or 0),
                    "created_at": int(r[6] or 0),
                }
            )
        return out

    async def get_traffic_package(self, package_id: int) -> Optional[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id, country_code, title, volume_gb, price_irt, is_active, created_at
                   FROM traffic_packages WHERE id=?""",
                (int(package_id),),
            )
            r = await cur.fetchone()
        if not r:
            return None
        return {
            "id": int(r[0]),
            "country_code": r[1] or "",
            "title": r[2],
            "volume_gb": int(r[3] or 0),
            "price_irt": int(r[4] or 0),
            "is_active": int(r[5] or 0),
            "created_at": int(r[6] or 0),
        }

    async def toggle_traffic_package_active(self, package_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                """UPDATE traffic_packages
                   SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END
                   WHERE id=?""",
                (int(package_id),),
            )
            await db.commit()

    async def delete_traffic_package(self, package_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("DELETE FROM traffic_packages WHERE id=?", (int(package_id),))
            await db.commit()

    async def create_traffic_purchase(
        self,
        *,
        user_id: int,
        order_id: int,
        package_id: Optional[int],
        volume_gb: int,
        price_irt: int,
        invoice_id: Optional[int],
        status: str,
    ) -> int:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """INSERT INTO traffic_purchases (user_id, order_id, package_id, volume_gb, price_irt, invoice_id, status, created_at)
                   VALUES (?,?,?,?,?,?,?,?)""",
                (
                    int(user_id),
                    int(order_id),
                    _as_int_or_none(package_id),
                    int(volume_gb),
                    int(price_irt),
                    _as_int_or_none(invoice_id),
                    (status or "").strip(),
                    _now(),
                ),
            )
            await db.commit()
            return int(cur.lastrowid)
    async def set_last_billed_hour(self, order_id: int, hour_ts: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE orders SET last_billed_hour=? WHERE id=?", (int(hour_ts), order_id))
            await db.commit()

    async def update_order_hourly_tick(self, order_id: int, last_hourly_charge_at: int, last_warn_at: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE orders SET last_hourly_charge_at=?, last_warn_at=? WHERE id=?",
                (int(last_hourly_charge_at), int(last_warn_at), order_id),
            )
            await db.commit()

    async def set_order_suspended_balance(self, order_id: int, suspended_at: int, delete_at: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE orders SET status='suspended_balance', suspended_at=?, delete_at=? WHERE id=?",
                (int(suspended_at), int(delete_at), order_id),
            )
            await db.commit()

    async def clear_order_suspension(self, order_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE orders SET status='active', suspended_at=0, delete_at=0 WHERE id=?",
                (order_id,),
            )
            await db.commit()

    # -------------------------
    # invoices
    # -------------------------
    async def create_invoice(self, user_id: int, amount_irt: int, method: str, desc: str, status: str) -> int:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "INSERT INTO invoices(user_id,amount_irt,method,desc,status,created_at) VALUES(?,?,?,?,?,?)",
                (int(user_id), int(amount_irt), str(method), str(desc), str(status), now),
            )
            await db.commit()
            return int(cur.lastrowid)

    async def set_invoice_status(self, invoice_id: int, status: str) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE invoices SET status=? WHERE id=?", (str(status), int(invoice_id)))
            await db.commit()

    async def attach_invoice_to_order(self, invoice_id: int, order_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE invoices SET order_id=? WHERE id=?", (int(order_id), int(invoice_id)))
            await db.commit()

    # -------------------------
    # card purchases
    # -------------------------
    async def create_card_purchase(self, invoice_id: int, user_id: int, payload_json: str) -> None:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "INSERT OR REPLACE INTO card_purchases(invoice_id,user_id,payload_json,receipt_file_id,status,created_at) VALUES(?,?,?,?,?,?)",
                (int(invoice_id), int(user_id), str(payload_json), None, "waiting_receipt", now),
            )
            await db.commit()

    async def set_card_purchase_receipt(self, invoice_id: int, receipt_file_id: str) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "UPDATE card_purchases SET receipt_file_id=?, status='sent_to_admin' WHERE invoice_id=?",
                (str(receipt_file_id), int(invoice_id)),
            )
            await db.commit()

    async def get_card_purchase(self, invoice_id: int) -> Optional[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT invoice_id,user_id,payload_json,receipt_file_id,status,created_at FROM card_purchases WHERE invoice_id=?",
                (int(invoice_id),),
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
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE card_purchases SET status=? WHERE invoice_id=?", (str(status), int(invoice_id)))
            await db.commit()

    async def list_pending_card_purchases(self, limit: int = 30) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT invoice_id,user_id,payload_json,receipt_file_id,status,created_at
                   FROM card_purchases
                   WHERE status IN ('waiting_receipt','sent_to_admin')
                   ORDER BY created_at DESC
                   LIMIT ?""",
                (int(limit),),
            )
            rows = await cur.fetchall()
        return [
            {
                "invoice_id": r[0],
                "user_id": r[1],
                "payload_json": r[2],
                "receipt_file_id": r[3],
                "status": r[4],
                "created_at": r[5],
            }
            for r in rows
        ]

    # -------------------------
    # tickets
    # -------------------------
    async def create_ticket(self, user_id: int, subject: str, text: str) -> int:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "INSERT INTO tickets(user_id,status,subject,created_at) VALUES(?,?,?,?)",
                (int(user_id), "open", str(subject), now),
            )
            tid = int(cur.lastrowid)
            await db.execute(
                "INSERT INTO ticket_messages(ticket_id,sender,sender_id,text,created_at) VALUES(?,?,?,?,?)",
                (tid, "user", int(user_id), str(text), now),
            )
            await db.commit()
            return tid

    async def get_ticket(self, ticket_id: int) -> Optional[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute("SELECT id,user_id,status,subject,created_at FROM tickets WHERE id=?", (int(ticket_id),))
            r = await cur.fetchone()
        if not r:
            return None
        return {"id": r[0], "user_id": r[1], "status": r[2], "subject": r[3], "created_at": r[4]}

    async def close_ticket(self, ticket_id: int) -> None:
        async with aiosqlite.connect(self.path) as db:
            await db.execute("UPDATE tickets SET status='closed' WHERE id=?", (int(ticket_id),))
            await db.commit()

    async def list_user_tickets(self, user_id: int, limit: int = 20) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT id,user_id,status,subject,created_at FROM tickets WHERE user_id=? ORDER BY id DESC LIMIT ?",
                (int(user_id), int(limit)),
            )
            rows = await cur.fetchall()
        return [{"id": r[0], "user_id": r[1], "status": r[2], "subject": r[3], "created_at": r[4]} for r in rows]

    async def list_open_tickets(self, limit: int = 30) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                "SELECT id,user_id,status,subject,created_at FROM tickets WHERE status='open' ORDER BY id DESC LIMIT ?",
                (int(limit),),
            )
            rows = await cur.fetchall()
        return [{"id": r[0], "user_id": r[1], "status": r[2], "subject": r[3], "created_at": r[4]} for r in rows]

    async def add_ticket_message(self, ticket_id: int, sender: str, sender_id: int, text: str) -> None:
        now = _now()
        async with aiosqlite.connect(self.path) as db:
            await db.execute(
                "INSERT INTO ticket_messages(ticket_id,sender,sender_id,text,created_at) VALUES(?,?,?,?,?)",
                (int(ticket_id), str(sender), int(sender_id), str(text), now),
            )
            await db.commit()

    async def list_ticket_messages(self, ticket_id: int, limit: int = 30) -> List[Dict[str, Any]]:
        async with aiosqlite.connect(self.path) as db:
            cur = await db.execute(
                """SELECT id,ticket_id,sender,sender_id,text,created_at
                   FROM ticket_messages
                   WHERE ticket_id=?
                   ORDER BY id ASC
                   LIMIT ?""",
                (int(ticket_id), int(limit)),
            )
            rows = await cur.fetchall()
        return [{"id": r[0], "ticket_id": r[1], "sender": r[2], "sender_id": r[3], "text": r[4], "created_at": r[5]} for r in rows]

    # -------------------------
    # misc
    # -------------------------
    async def stats(self) -> Dict[str, int]:
        async with aiosqlite.connect(self.path) as db:
            c1 = await db.execute("SELECT COUNT(*) FROM users")
            users = int((await c1.fetchone())[0] or 0)
            c2 = await db.execute("SELECT COUNT(*) FROM orders")
            orders = int((await c2.fetchone())[0] or 0)
            c3 = await db.execute("SELECT COUNT(*) FROM orders WHERE status='active'")
            active = int((await c3.fetchone())[0] or 0)
        return {"users": users, "orders": orders, "active_orders": active}