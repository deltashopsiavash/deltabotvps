"""VPSBot Bridge Server (for integration with DeltaBot)

How it works
------------
- DeltaBot (PHP) remains the ONLY webhook receiver.
- When user enters the "cloud server" section, DeltaBot forwards raw Telegram
  updates (JSON) to this HTTP service.
- This service feeds the update into VPSBot's aiogram Dispatcher.
- VPSBot sends responses to Telegram using the SAME BOT_TOKEN.

Run
---
1) Copy/prepare .env (same as vpsbot) and ensure BOT_TOKEN matches DeltaBot.
2) Install requirements (see requirements.txt).
3) Start server:
   python3 bridge_server.py

DeltaBot setting
---------------
Set VPSBOT_BRIDGE_URL to:
  http://127.0.0.1:8090/update

"""

import asyncio
import json
import os
from aiohttp import web

from aiogram import Bot, Dispatcher
from aiogram.types import Update

# Import VPSBot app pieces (router + init helpers + background jobs)
import bot as vpsapp


async def create_app() -> web.Application:
    if not vpsapp.BOT_TOKEN:
        raise RuntimeError("BOT_TOKEN is missing in .env")

    os.makedirs(os.path.dirname(vpsapp.DB_PATH), exist_ok=True)

    db = vpsapp.DB(vpsapp.DB_PATH)
    vpsapp.ensure_card_purchase_api(vpsapp.DB)
    vpsapp.ensure_invoice_api(vpsapp.DB)
    await db.init()

    # Load UI customizations and catalog used by kb()
    await vpsapp.load_button_labels(db)
    await vpsapp.load_glass_buttons_pref(db)
    vpsapp._build_label_catalog()

    # Seed card text from env if present (do not override admin-configured value)
    if vpsapp.DEFAULT_CARD_TEXT:
        try:
            cur_card = await db.get_setting("card_number_text", "") or ""
            if not str(cur_card).strip():
                await db.set_setting("card_number_text", vpsapp.DEFAULT_CARD_TEXT)
        except Exception:
            pass

    bot = Bot(token=vpsapp.BOT_TOKEN)
    dp = Dispatcher()
    dp.include_router(vpsapp.router)

    # Background jobs (optional but keeps behavior identical to polling mode)
    asyncio.create_task(vpsapp.job_loop(db, bot))
    asyncio.create_task(vpsapp.daily_db_backup_loop(db, bot))

    app = web.Application()
    app["bot"] = bot
    app["dp"] = dp
    app["db"] = db

    async def handle_update(request: web.Request) -> web.Response:
        try:
            payload = await request.json()
        except Exception:
            return web.json_response({"ok": False, "error": "invalid_json"}, status=400)

        update_json = payload.get("update") if isinstance(payload, dict) else None
        if update_json is None:
            update_json = payload

        try:
            upd = Update.model_validate(update_json)
        except Exception as e:
            return web.json_response({"ok": False, "error": f"bad_update: {e}"}, status=400)

        try:
            await dp.feed_update(bot, upd, db=db)
        except Exception as e:
            # Don't fail DeltaBot webhook; return 200 so Telegram doesn't retry.
            return web.json_response({"ok": False, "error": f"dispatch_error: {e}"})

        return web.json_response({"ok": True})

    app.router.add_post("/update", handle_update)
    return app


async def _main():
    app = await create_app()
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, host="127.0.0.1", port=8090)
    await site.start()
    print("VPSBot bridge listening on http://127.0.0.1:8090/update")
    # Run forever
    while True:
        await asyncio.sleep(3600)


if __name__ == "__main__":
    asyncio.run(_main())
